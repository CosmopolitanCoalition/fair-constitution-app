<?php

namespace Tests\Constitutional;

use App\Http\Controllers\Oidc\OidcDiscoveryController;
use App\Models\MatrixIdentity;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\Matrix\MatrixIdentityProvisioner;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\Oidc\OidcKeyService;
use App\Services\Oidc\OidcProviderService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the GAME-as-OIDC-provider, K3-C.1 (identity bridge flows GAME → Matrix, ratified
 * 2026-06-27). THE INVARIANTS: the signing key is asymmetric (RS256) so MAS verifies without a shared
 * secret; the JWKS endpoint exposes ONLY public key material (never d/p/q/PEM); id_tokens round-trip
 * (sign→verify) and a tampered token or an alg-downgrade ('none') is rejected fail-closed; the discovery
 * document advertises ONLY what is implemented (code flow, RS256, mandatory PKCE S256) and a PSEUDONYM-ONLY
 * claim set (sub + preferred_username — never name/email; the de-anon is a judicial carve-out, not a claim).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class OidcProviderTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_oidc';

    public function test_signing_key_mints_publishes_public_jwks_only_and_round_trips(): void
    {
        $this->onLivePg(function () {
            $svc = app(OidcKeyService::class);

            $key = $svc->ensureKey();
            $this->assertSame($key->id, $svc->ensureKey()->id, 'idempotent — one active signing key');

            // JWKS publishes the public key, public members ONLY.
            $jwks = $svc->jwks();
            $jwk = collect($jwks['keys'])->firstWhere('kid', $key->kid);
            $this->assertIsArray($jwk, 'the active key is published');
            $this->assertSame('RSA', $jwk['kty']);
            $this->assertSame('RS256', $jwk['alg']);
            $this->assertSame('sig', $jwk['use']);
            $this->assertArrayHasKey('n', $jwk);
            $this->assertArrayHasKey('e', $jwk);
            foreach (['d', 'p', 'q', 'dp', 'dq', 'qi', 'private_pem_encrypted'] as $secret) {
                $this->assertArrayNotHasKey($secret, $jwk, "JWKS must never expose [{$secret}]");
            }
            $this->assertStringNotContainsString('PRIVATE KEY', (string) json_encode($jwks),
                'no PEM private material ever rides JWKS');

            // An id_token round-trips against the published key, and tampering / alg-downgrade fail closed.
            $jwt = $svc->sign(['iss' => 'x', 'sub' => 'u-abc', 'aud' => 'mas-upstream']);
            $this->assertSame('u-abc', $svc->verify($jwt)['sub'] ?? null, 'a valid id_token verifies');
            $this->assertNull($svc->verify($jwt.'tampered'), 'a tampered token is rejected');

            [, $payload] = explode('.', $jwt);
            $noneHeader = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=');
            $this->assertNull($svc->verify($noneHeader.'.'.$payload.'.'), 'alg=none is rejected (no algorithm confusion)');
        });
    }

    public function test_discovery_advertises_only_what_is_implemented_and_pseudonym_claims(): void
    {
        $doc = app(OidcDiscoveryController::class)->configuration()->getData(true);
        $issuer = (string) config('matrix.oidc.issuer');

        $this->assertSame($issuer, $doc['issuer']);
        $this->assertSame($issuer.'/oauth/jwks', $doc['jwks_uri']);
        $this->assertSame($issuer.'/oauth/authorize', $doc['authorization_endpoint']);
        $this->assertSame($issuer.'/oauth/token', $doc['token_endpoint']);
        $this->assertSame(['code'], $doc['response_types_supported']);
        $this->assertSame(['authorization_code'], $doc['grant_types_supported']);
        $this->assertSame(['RS256'], $doc['id_token_signing_alg_values_supported']);
        $this->assertSame(['S256'], $doc['code_challenge_methods_supported'], 'PKCE mandatory; no plain');

        // PSEUDONYM ONLY — the de-anon stays a judicial carve-out, never an OIDC claim.
        $this->assertSame(['sub', 'preferred_username'], $doc['claims_supported']);
        $this->assertNotContains('email', $doc['claims_supported']);
        $this->assertNotContains('name', $doc['claims_supported']);
    }

    public function test_full_authorization_code_pkce_flow_provisions_pseudonym_and_mints_id_token(): void
    {
        $this->onLivePg(function () {
            $user = User::factory()->create();
            SocialProfile::create(['user_id' => (string) $user->id, 'handle' => 'alice', 'visibility' => 'public']);

            $client = (array) config('matrix.oidc.client');
            $redirect = $client['redirect_uris'][0];
            $verifier = $this->b64url(random_bytes(40));
            $challenge = $this->b64url(hash('sha256', $verifier, true));
            $svc = app(OidcProviderService::class);

            $v = $svc->validateAuthorize([
                'response_type' => 'code', 'client_id' => $client['id'], 'redirect_uri' => $redirect,
                'scope' => 'openid', 'state' => 'st-1', 'nonce' => 'n-123',
                'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
            ]);
            $this->assertTrue($v['ok']);

            $code = $svc->issueCode((string) $user->id, $v);
            $res = $svc->exchange([
                'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier,
                'redirect_uri' => $redirect, 'client_id' => $client['id'], 'client_secret' => $client['secret'],
            ], null);

            $this->assertSame(200, $res['status']);
            $claims = app(OidcKeyService::class)->verify($res['body']['id_token']);
            $this->assertSame((string) $user->id, $claims['sub']);
            $this->assertSame($client['id'], $claims['aud']);
            $this->assertSame('u-alice', $claims['preferred_username'], 'the pseudonym MAS maps localpart from');
            $this->assertSame('n-123', $claims['nonce'], 'the nonce rides through (replay binding)');
            $this->assertArrayNotHasKey('email', $claims, 'NO PII in the id_token');
            $this->assertArrayNotHasKey('name', $claims);

            // Provisioning: exactly one pseudonymous identity row.
            $rows = MatrixIdentity::query()->where('user_id', (string) $user->id)->get();
            $this->assertCount(1, $rows);
            $this->assertSame('u-alice', $rows[0]->matrix_localpart);

            // UserInfo with the minted access token.
            $info = $svc->userinfo($res['body']['access_token']);
            $this->assertSame(200, $info['status']);
            $this->assertSame('u-alice', $info['body']['preferred_username']);
            $this->assertSame((string) $user->id, $info['body']['sub']);
            $this->assertNull($svc->userinfo('garbage.token.here')['body']['sub'] ?? null, 'a bad bearer is 401, no claims');
        });
    }

    public function test_token_endpoint_is_fail_closed_on_pkce_replay_and_client_secret(): void
    {
        $this->onLivePg(function () {
            $user = User::factory()->create();
            $client = (array) config('matrix.oidc.client');
            $redirect = $client['redirect_uris'][0];
            $verifier = $this->b64url(random_bytes(40));
            $challenge = $this->b64url(hash('sha256', $verifier, true));
            $svc = app(OidcProviderService::class);
            $v = $svc->validateAuthorize([
                'response_type' => 'code', 'client_id' => $client['id'], 'redirect_uri' => $redirect,
                'scope' => 'openid', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
            ]);
            $ok = fn (string $code, ?string $secret = null, ?string $vfy = null) => $svc->exchange([
                'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $vfy ?? $verifier,
                'redirect_uri' => $redirect, 'client_id' => $client['id'], 'client_secret' => $secret ?? $client['secret'],
            ], null);

            // (a) Wrong PKCE verifier → invalid_grant.
            $bad = $ok($svc->issueCode((string) $user->id, $v), null, $this->b64url(random_bytes(40)));
            $this->assertSame(400, $bad['status']);
            $this->assertSame('invalid_grant', $bad['body']['error']);

            // (b) Wrong client secret → invalid_client (401).
            $badClient = $ok($svc->issueCode((string) $user->id, $v), 'WRONG-SECRET');
            $this->assertSame(401, $badClient['status']);
            $this->assertSame('invalid_client', $badClient['body']['error']);

            // (c) Single-use: a code redeems once; a replay is invalid_grant.
            $code = $svc->issueCode((string) $user->id, $v);
            $this->assertSame(200, $ok($code)['status']);
            $replay = $ok($code);
            $this->assertSame(400, $replay['status']);
            $this->assertSame('invalid_grant', $replay['body']['error']);
        });
    }

    public function test_authorize_validation_protects_the_redirect_uri_and_forbids_pkce_downgrade(): void
    {
        $svc = app(OidcProviderService::class);
        $client = (array) config('matrix.oidc.client');
        $redirect = $client['redirect_uris'][0];
        $goodChallenge = str_repeat('a', 43);

        // Unknown client → UN-redirectable (never bounce to an attacker URI).
        $r = $svc->validateAuthorize(['response_type' => 'code', 'client_id' => 'attacker', 'redirect_uri' => $redirect, 'scope' => 'openid', 'code_challenge' => $goodChallenge, 'code_challenge_method' => 'S256']);
        $this->assertFalse($r['ok']);
        $this->assertFalse($r['redirectable']);

        // Unregistered redirect_uri → UN-redirectable.
        $r = $svc->validateAuthorize(['response_type' => 'code', 'client_id' => $client['id'], 'redirect_uri' => 'https://evil.example/cb', 'scope' => 'openid', 'code_challenge' => $goodChallenge, 'code_challenge_method' => 'S256']);
        $this->assertFalse($r['ok']);
        $this->assertFalse($r['redirectable']);

        // Missing PKCE → redirectable invalid_request.
        $r = $svc->validateAuthorize(['response_type' => 'code', 'client_id' => $client['id'], 'redirect_uri' => $redirect, 'scope' => 'openid']);
        $this->assertTrue($r['redirectable']);
        $this->assertSame('invalid_request', $r['error']);

        // PKCE 'plain' downgrade → rejected.
        $r = $svc->validateAuthorize(['response_type' => 'code', 'client_id' => $client['id'], 'redirect_uri' => $redirect, 'scope' => 'openid', 'code_challenge' => $goodChallenge, 'code_challenge_method' => 'plain']);
        $this->assertSame('invalid_request', $r['error']);

        // Non-openid scope → redirectable invalid_scope.
        $r = $svc->validateAuthorize(['response_type' => 'code', 'client_id' => $client['id'], 'redirect_uri' => $redirect, 'scope' => 'profile', 'code_challenge' => $goodChallenge, 'code_challenge_method' => 'S256']);
        $this->assertSame('invalid_scope', $r['error']);
    }

    public function test_provisioning_survives_a_localpart_collision_without_500_or_merge(): void
    {
        $this->onLivePg(function () {
            $userA = User::factory()->create();
            $userB = User::factory()->create();

            // Force B's DERIVED localpart to be already taken by ANOTHER user (the collision case).
            $bLocalpart = app(MatrixPostingGateService::class)->deriveLocalpart($userB);
            MatrixIdentity::create([
                'user_id' => (string) $userA->id,
                'matrix_localpart' => $bLocalpart,
                'matrix_user_id' => '@'.$bLocalpart.':x',
            ]);

            // Provisioning B must NOT throw, must NOT merge into A, and must yield a DISTINCT localpart.
            $idB = app(MatrixIdentityProvisioner::class)->ensureFor($userB);
            $this->assertSame((string) $userB->id, $idB->user_id);
            $this->assertNotSame($bLocalpart, $idB->matrix_localpart, 'a collision is discriminated, never merged');

            // Idempotent, and message-sending now reads the SAME discriminated localpart (no identity split).
            $this->assertSame($idB->id, app(MatrixIdentityProvisioner::class)->ensureFor($userB)->id);
            $this->assertSame($idB->matrix_localpart, app(MatrixPostingGateService::class)->localpartFor($userB));
        });
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
