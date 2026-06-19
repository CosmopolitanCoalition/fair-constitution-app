<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase K-3 — authenticate inbound appservice transactions from Synapse. The homeserver presents the
 * hs_token (modern: Authorization: Bearer; legacy: ?access_token=); we compare it (constant-time) to
 * the configured hs_token. This is the AS-API analogue of VerifyPeerSignature — NOT user/session auth.
 * Anything else is M_FORBIDDEN, so a forged push can never inject events as the appservice.
 */
class VerifyMatrixAppService
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('matrix.appservice.hs_token');
        $presented = $request->bearerToken() ?? (string) $request->query('access_token', '');

        if ($expected === '' || ! hash_equals($expected, (string) $presented)) {
            return response()->json(['errcode' => 'M_FORBIDDEN'], 403);
        }

        return $next($request);
    }
}
