<?php

namespace App\Services\Oidc;

use App\Models\OidcSigningKey;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Phase 5 / K-3 (K3-C) — the GAME OIDC provider's signing-key authority. The game signs id_tokens with an
 * RSA (RS256) key; MAS (the single relying party, configured upstream→game) verifies them against the
 * PUBLIC half published at the JWKS endpoint — no shared secret. The private half is encrypted at rest with
 * the app key (the same discipline as instance_settings.private_key_encrypted), never leaves the box, never
 * federates, never appears in JWKS.
 *
 * RS256 (not ES256/EdDSA) on purpose: it is the universally-supported OIDC id_token algorithm and its
 * openssl_sign path yields the JOSE signature bytes directly (no DER→raw conversion), so the signing code
 * stays small and auditable. Rotation is supported by design — multiple non-revoked keys publish together
 * (sign with the newest active, verify against any), so a key roll never invalidates in-flight tokens.
 */
class OidcKeyService
{
    private const RSA_BITS = 2048;

    /**
     * The active signing key, minting one on first use. Idempotent: returns the existing active key if any.
     */
    public function activeSigningKey(): OidcSigningKey
    {
        $existing = OidcSigningKey::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->first();

        return $existing ?? $this->generate();
    }

    /** Ensure at least one active key exists (setup hook). Returns the active key. */
    public function ensureKey(): OidcSigningKey
    {
        return $this->activeSigningKey();
    }

    /**
     * The JWKS document — every non-revoked key's PUBLIC JWK (kty/use/alg/kid/n/e). This is the ONLY place
     * key material is exposed, and it is public-by-design. NEVER includes the private members (d, p, q, …).
     *
     * @return array{keys: list<array<string,mixed>>}
     */
    public function jwks(): array
    {
        $keys = OidcSigningKey::query()
            ->whereNull('deleted_at')
            ->orderByDesc('is_active')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (OidcSigningKey $k) => $this->publicOnly((array) $k->public_jwk))
            ->all();

        if ($keys === []) {
            $keys = [$this->publicOnly((array) $this->activeSigningKey()->public_jwk)];
        }

        return ['keys' => array_values($keys)];
    }

    /**
     * Sign a compact JWS (the id_token / any provider JWT) with the active key. The header carries the kid
     * so MAS selects the right JWKS entry.
     *
     * @param  array<string,mixed>  $claims
     */
    public function sign(array $claims): string
    {
        $key = $this->activeSigningKey();
        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => (string) $key->kid];

        $signingInput = $this->b64url($this->json($header)).'.'.$this->b64url($this->json($claims));

        $private = openssl_pkey_get_private(Crypt::decryptString((string) $key->private_pem_encrypted));
        if ($private === false) {
            throw new RuntimeException('OIDC signing key could not be loaded.');
        }
        $signature = '';
        if (! openssl_sign($signingInput, $signature, $private, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('OIDC id_token signing failed.');
        }

        return $signingInput.'.'.$this->b64url($signature);
    }

    /**
     * Verify a compact JWS against a published key (selected by kid) and return its claims, or null on any
     * failure. Mirrors what MAS does with the JWKS; used by the constitutional pins. Fail-closed.
     *
     * @return array<string,mixed>|null
     */
    public function verify(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;

        $header = json_decode($this->b64urlDecode($h), true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256') {
            return null; // alg pinned — no 'none', no alg confusion
        }

        $key = OidcSigningKey::query()
            ->where('kid', (string) ($header['kid'] ?? ''))
            ->whereNull('deleted_at')
            ->first();
        if ($key === null) {
            return null;
        }

        // Derive the public key from the stored private key (the same key whose public half is in JWKS).
        $private = openssl_pkey_get_private(Crypt::decryptString((string) $key->private_pem_encrypted));
        if ($private === false) {
            return null;
        }
        $publicPem = (string) (openssl_pkey_get_details($private)['key'] ?? '');

        $ok = openssl_verify($h.'.'.$p, $this->b64urlDecode($s), $publicPem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            return null;
        }

        $claims = json_decode($this->b64urlDecode($p), true);

        return is_array($claims) ? $claims : null;
    }

    /** Generate + store a fresh RS256 keypair. */
    private function generate(): OidcSigningKey
    {
        $res = openssl_pkey_new([
            'private_key_bits' => self::RSA_BITS,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($res === false) {
            throw new RuntimeException('Could not generate an OIDC RSA signing key (ext-openssl required).');
        }

        openssl_pkey_export($res, $privatePem);
        $details = openssl_pkey_get_details($res);
        if (! is_array($details) || ! isset($details['rsa']['n'], $details['rsa']['e'])) {
            throw new RuntimeException('Could not read the generated RSA key details.');
        }

        $n = $this->b64url((string) $details['rsa']['n']);
        $e = $this->b64url((string) $details['rsa']['e']);
        $kid = $this->thumbprint($n, $e);

        $publicJwk = [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n' => $n,
            'e' => $e,
        ];

        return OidcSigningKey::create([
            'kid' => $kid,
            'algorithm' => 'RS256',
            'public_jwk' => $publicJwk,
            'private_pem_encrypted' => Crypt::encryptString((string) $privatePem),
            'is_active' => true,
        ]);
    }

    /** RFC 7638 JWK thumbprint over the REQUIRED RSA members in lexicographic order ({e,kty,n}), no spaces. */
    private function thumbprint(string $n, string $e): string
    {
        $canonical = '{"e":"'.$e.'","kty":"RSA","n":"'.$n.'"}';

        return $this->b64url(hash('sha256', $canonical, true));
    }

    /** Strip any non-public members defensively — JWKS is public-only by construction, but enforce it. */
    private function publicOnly(array $jwk): array
    {
        return array_intersect_key($jwk, array_flip(['kty', 'use', 'alg', 'kid', 'n', 'e']));
    }

    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'));
    }
}
