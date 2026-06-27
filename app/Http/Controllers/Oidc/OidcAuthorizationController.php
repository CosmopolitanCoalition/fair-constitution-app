<?php

namespace App\Http\Controllers\Oidc;

use App\Http\Controllers\Controller;
use App\Services\Oidc\OidcProviderService;
use Illuminate\Http\Request;

/**
 * Phase 5 / K-3 (K3-C.2) — the OIDC authorization endpoint. Behind `auth`, so an unauthenticated citizen is
 * sent to the GAME login (intended-URL preserved) and bounces back here once logged in — the player's ONE
 * login. On success it issues a single-use code bound to the request's PKCE challenge + nonce + this user,
 * and redirects to the (already-validated) redirect_uri. An unknown client / unregistered redirect_uri is a
 * plain error page — a code/error is NEVER bounced to an attacker-chosen URI.
 */
class OidcAuthorizationController extends Controller
{
    public function authorize(Request $request, OidcProviderService $oidc)
    {
        $v = $oidc->validateAuthorize($request->query());

        if (! ($v['ok'] ?? false)) {
            if (! ($v['redirectable'] ?? false)) {
                // Un-redirectable (bad client or unregistered redirect_uri) — never redirect to it.
                return response('OIDC authorize error: '.($v['error'] ?? 'invalid_request'), 400);
            }

            return redirect()->away($this->appendQuery((string) $v['redirect_uri'], [
                'error' => $v['error'] ?? 'invalid_request',
                'state' => $v['state'] ?? null,
            ]));
        }

        $code = $oidc->issueCode((string) $request->user()->getKey(), $v);

        return redirect()->away($this->appendQuery((string) $v['redirect_uri'], [
            'code' => $code,
            'state' => $v['state'] ?? null,
        ]));
    }

    /** @param array<string,?string> $params */
    private function appendQuery(string $uri, array $params): string
    {
        $params = array_filter($params, fn ($x) => $x !== null);

        return $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($params);
    }
}
