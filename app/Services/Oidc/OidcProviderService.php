<?php

namespace App\Services\Oidc;

use App\Models\OidcAuthorizationCode;
use App\Models\User;
use App\Services\Matrix\MatrixIdentityProvisioner;

/**
 * Phase 5 / K-3 (K3-C.2/.3) — the GAME OIDC provider's authorization-code + PKCE core. Serves exactly ONE
 * relying party: MAS (configured upstream→game). The flow is the constrained, modern OIDC profile:
 * authorization_code ONLY, PKCE S256 MANDATORY, RS256 id_tokens, pseudonym-only claims.
 *
 * Security posture (fail-closed throughout):
 *  - The client_id + the EXACT redirect_uri are validated against the single registered client BEFORE any
 *    redirect; an unknown client or unregistered redirect_uri is an un-redirectable error (never bounce a
 *    code/error to an attacker-chosen URI).
 *  - Codes are single-use (a conditional consume), seconds-lived, stored hashed, and bound to
 *    {client, redirect_uri, PKCE challenge, nonce, user}; redemption re-checks every binding.
 *  - PKCE S256 is verified at the token endpoint; the client secret is constant-time compared.
 *  - The pseudonym (u-<handle>) is the ONLY identity claim emitted — never a name/email.
 */
class OidcProviderService
{
    public function __construct(
        private readonly OidcKeyService $keys,
        private readonly MatrixIdentityProvisioner $provisioner,
    ) {}

    // ── Authorize (K3-C.2) ───────────────────────────────────────────────────────────────────────────────

    /**
     * Validate an /oauth/authorize request. Discriminated result:
     *  ok           → ['ok'=>true, client_id, redirect_uri, state, nonce, code_challenge, scope]
     *  un-redirect  → ['ok'=>false, 'redirectable'=>false, 'error'=>...]   (bad client / bad redirect_uri)
     *  redirectable → ['ok'=>false, 'redirectable'=>true, 'redirect_uri', 'state', 'error'=>...]
     *
     * @param  array<string,mixed>  $q
     * @return array<string,mixed>
     */
    public function validateAuthorize(array $q): array
    {
        $clientId = (string) ($q['client_id'] ?? '');
        $redirectUri = (string) ($q['redirect_uri'] ?? '');
        $client = (array) config('matrix.oidc.client');

        // (1) The client_id must be THE one registered relying party (MAS).
        if ($clientId === '' || ! hash_equals((string) $client['id'], $clientId)) {
            return ['ok' => false, 'redirectable' => false, 'error' => 'unauthorized_client'];
        }
        // (2) The redirect_uri must EXACTLY match a registered one — before any redirect can target it.
        if ($redirectUri === '' || ! in_array($redirectUri, array_map('strval', (array) $client['redirect_uris']), true)) {
            return ['ok' => false, 'redirectable' => false, 'error' => 'invalid_redirect_uri'];
        }

        // From here, a param error can safely redirect back to the VALIDATED redirect_uri.
        $state = isset($q['state']) ? (string) $q['state'] : null;
        $fail = fn (string $error) => ['ok' => false, 'redirectable' => true, 'redirect_uri' => $redirectUri, 'state' => $state, 'error' => $error];

        if (($q['response_type'] ?? null) !== 'code') {
            return $fail('unsupported_response_type');
        }
        $scopes = preg_split('/\s+/', trim((string) ($q['scope'] ?? ''))) ?: [];
        if (! in_array('openid', $scopes, true)) {
            return $fail('invalid_scope');
        }
        // PKCE is MANDATORY and S256-only (no 'plain', no downgrade).
        if (($q['code_challenge_method'] ?? null) !== 'S256') {
            return $fail('invalid_request');
        }
        $challenge = (string) ($q['code_challenge'] ?? '');
        if (! preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $challenge)) {
            return $fail('invalid_request');
        }

        return [
            'ok' => true,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'nonce' => isset($q['nonce']) ? (string) $q['nonce'] : null,
            'code_challenge' => $challenge,
            'scope' => implode(' ', array_values(array_unique($scopes))),
        ];
    }

    /**
     * Issue a single-use authorization code for the authenticated user (called only after validateAuthorize
     * returned ok AND the citizen holds a web session). Returns the RAW code (only its hash is stored).
     *
     * @param  array<string,mixed>  $validated
     */
    public function issueCode(string $userId, array $validated): string
    {
        $raw = $this->b64url(random_bytes(32));

        OidcAuthorizationCode::create([
            'code_hash' => hash('sha256', $raw),
            'client_id' => (string) $validated['client_id'],
            'user_id' => $userId,
            'redirect_uri' => (string) $validated['redirect_uri'],
            'scope' => (string) $validated['scope'],
            'code_challenge' => (string) $validated['code_challenge'],
            'nonce' => $validated['nonce'] !== null ? (string) $validated['nonce'] : null,
            'expires_at' => now()->addSeconds((int) config('matrix.oidc.code_ttl', 60)),
        ]);

        return $raw;
    }

    // ── Token (K3-C.3) ───────────────────────────────────────────────────────────────────────────────────

    /**
     * Redeem a code at /oauth/token. Fail-closed; an OAuth error is {status, body:{error}} with no token.
     *
     * @param  array<string,mixed>  $post
     * @return array{status:int, body:array<string,mixed>}
     */
    public function exchange(array $post, ?string $authorizationHeader): array
    {
        $client = (array) config('matrix.oidc.client');
        [$cid, $csecret] = $this->clientCredentials($post, $authorizationHeader);

        // Constant-time client authentication.
        if ($cid === '' || ! hash_equals((string) $client['id'], $cid) || ! hash_equals((string) $client['secret'], $csecret)) {
            return ['status' => 401, 'body' => ['error' => 'invalid_client']];
        }
        if (($post['grant_type'] ?? null) !== 'authorization_code') {
            return ['status' => 400, 'body' => ['error' => 'unsupported_grant_type']];
        }

        $code = (string) ($post['code'] ?? '');
        $verifier = (string) ($post['code_verifier'] ?? '');
        $redirectUri = (string) ($post['redirect_uri'] ?? '');
        if ($code === '' || $verifier === '' || $redirectUri === '') {
            return ['status' => 400, 'body' => ['error' => 'invalid_request']];
        }

        $row = OidcAuthorizationCode::query()->where('code_hash', hash('sha256', $code))->first();
        if ($row === null
            || $row->consumed_at !== null
            || $row->expires_at === null
            || $row->expires_at->isPast()
            || ! hash_equals((string) $row->client_id, $cid)
            || ! hash_equals((string) $row->redirect_uri, $redirectUri)) {
            return ['status' => 400, 'body' => ['error' => 'invalid_grant']];
        }

        // PKCE S256: base64url(sha256(verifier)) must equal the stored challenge.
        $expected = $this->b64url(hash('sha256', $verifier, true));
        if (! hash_equals((string) $row->code_challenge, $expected)) {
            return ['status' => 400, 'body' => ['error' => 'invalid_grant']];
        }

        // Single-use: consume atomically. A concurrent/replayed redemption loses the conditional UPDATE.
        $consumed = OidcAuthorizationCode::query()
            ->where('id', $row->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
        if ($consumed !== 1) {
            return ['status' => 400, 'body' => ['error' => 'invalid_grant']];
        }

        $user = User::query()->find((string) $row->user_id);
        if ($user === null) {
            return ['status' => 400, 'body' => ['error' => 'invalid_grant']];
        }

        // Provision (idempotent) the pseudonymous identity + mint the tokens.
        $localpart = (string) $this->provisioner->ensureFor($user)->matrix_localpart;
        $now = now()->timestamp;
        $issuer = (string) config('matrix.oidc.issuer');
        $ttl = (int) config('matrix.oidc.id_token_ttl', 300);

        $idClaims = [
            'iss' => $issuer,
            'sub' => (string) $user->getKey(),
            'aud' => $cid,
            'iat' => $now,
            'exp' => $now + $ttl,
            'auth_time' => $now,
            'preferred_username' => $localpart, // the pseudonym — MAS maps localpart from this
        ];
        if ($row->nonce !== null) {
            $idClaims['nonce'] = (string) $row->nonce;
        }

        $accessToken = $this->keys->sign([
            'iss' => $issuer,
            'sub' => (string) $user->getKey(),
            'aud' => $issuer.'/oauth/userinfo',
            'iat' => $now,
            'exp' => $now + $ttl,
            'token_use' => 'access',
            'scope' => (string) $row->scope,
            'preferred_username' => $localpart,
        ]);

        return ['status' => 200, 'body' => [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
            'id_token' => $this->keys->sign($idClaims),
            'scope' => (string) $row->scope,
        ]];
    }

    /**
     * The userinfo endpoint — a bearer access_token (our signed JWT) → pseudonym claims. Fail-closed.
     *
     * @return array{status:int, body:array<string,mixed>}
     */
    public function userinfo(?string $bearer): array
    {
        $claims = $bearer !== null && $bearer !== '' ? $this->keys->verify($bearer) : null;
        if ($claims === null
            || ($claims['token_use'] ?? null) !== 'access'
            || (int) ($claims['exp'] ?? 0) < now()->timestamp) {
            return ['status' => 401, 'body' => ['error' => 'invalid_token']];
        }

        return ['status' => 200, 'body' => [
            'sub' => (string) ($claims['sub'] ?? ''),
            'preferred_username' => (string) ($claims['preferred_username'] ?? ''),
        ]];
    }

    /** client_secret_basic (RFC6749 §2.3.1, form-urlencoded) OR client_secret_post. @return array{0:string,1:string} */
    private function clientCredentials(array $post, ?string $authorizationHeader): array
    {
        if (is_string($authorizationHeader) && str_starts_with($authorizationHeader, 'Basic ')) {
            $decoded = base64_decode(substr($authorizationHeader, 6), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$u, $p] = explode(':', $decoded, 2);

                return [urldecode($u), urldecode($p)];
            }
        }

        return [(string) ($post['client_id'] ?? ''), (string) ($post['client_secret'] ?? '')];
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
