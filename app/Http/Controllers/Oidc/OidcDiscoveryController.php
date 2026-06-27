<?php

namespace App\Http\Controllers\Oidc;

use App\Http\Controllers\Controller;
use App\Services\Oidc\OidcKeyService;
use Illuminate\Http\JsonResponse;

/**
 * Phase 5 / K-3 (K3-C) — the GAME OIDC provider's public discovery surface. MAS (configured upstream→game)
 * fetches /.well-known/openid-configuration to learn the endpoints + capabilities, and /oauth/jwks to get
 * the public key it verifies id_tokens with. Both are PUBLIC, unauthenticated, side-effect-free GETs (a key
 * is minted lazily on first JWKS read). The game advertises ONLY what it actually implements: the
 * authorization-code flow with mandatory PKCE (S256), RS256 id_tokens, and the pseudonymous claim set.
 */
class OidcDiscoveryController extends Controller
{
    public function __construct(private readonly OidcKeyService $keys) {}

    public function configuration(): JsonResponse
    {
        $issuer = (string) config('matrix.oidc.issuer');

        return response()->json([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'jwks_uri' => $issuer.'/oauth/jwks',
            'userinfo_endpoint' => $issuer.'/oauth/userinfo',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => ['openid', 'profile'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
            'code_challenge_methods_supported' => ['S256'], // PKCE mandatory; 'plain' deliberately unsupported
            'claims_supported' => ['sub', 'preferred_username'], // pseudonym only — never name/email
        ]);
    }

    public function jwks(): JsonResponse
    {
        return response()->json($this->keys->jwks());
    }
}
