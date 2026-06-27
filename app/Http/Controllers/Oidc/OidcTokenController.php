<?php

namespace App\Http\Controllers\Oidc;

use App\Http\Controllers\Controller;
use App\Services\Oidc\OidcProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 5 / K-3 (K3-C.3) — the OIDC token + userinfo endpoints. Machine-to-machine (MAS), mounted OUTSIDE
 * the web group (no session, no CSRF; authenticated by the client secret + PKCE in the service). All the
 * security lives in OidcProviderService; this controller maps {status, body} and sets the no-store cache
 * headers OAuth requires for token responses.
 */
class OidcTokenController extends Controller
{
    public function token(Request $request, OidcProviderService $oidc): JsonResponse
    {
        $result = $oidc->exchange($request->post(), $request->header('Authorization'));

        return response()->json($result['body'], $result['status'])
            ->withHeaders(['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    public function userinfo(Request $request, OidcProviderService $oidc): JsonResponse
    {
        $result = $oidc->userinfo($request->bearerToken());

        return response()->json($result['body'], $result['status'])
            ->header('Cache-Control', 'no-store');
    }
}
