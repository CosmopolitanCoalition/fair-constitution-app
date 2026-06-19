<?php

namespace App\Http\Controllers\Matrix;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Phase K-3 — Matrix .well-known delegation, served dynamically by Laravel.
 *
 * Matrix uses .well-known to delegate the homeserver's server-name → the host:port the
 * federation (S2S) and client APIs are actually reachable at — here, the CGA nginx, which
 * proxies /_matrix/ to the fc_matrix container. nginx cannot env-substitute an inline JSON
 * body, so this is served by a controller reading config/matrix.php (MATRIX_DOMAIN / APP_URL
 * resolve per instance). The delegation target must serve a cert valid for the ORIGINAL
 * server_name — the design pins keys, never trusts DNS (K3 design §1, §6.4).
 */
class WellKnownController extends Controller
{
    /** GET /.well-known/matrix/server — S2S delegation: where the federation API lives. */
    public function server(): JsonResponse
    {
        return response()->json([
            'm.server' => $this->delegateAuthority(),
        ]);
    }

    /** GET /.well-known/matrix/client — client discovery: the homeserver base URL + the OIDC IdP. */
    public function client(): JsonResponse
    {
        $base = rtrim((string) config('matrix.well_known.client_base_url'), '/');

        return response()->json([
            'm.homeserver'      => ['base_url' => $base],
            'org.matrix.msc2965.authentication' => [
                // The OIDC provider (MAS) Synapse delegates to — the public issuer clients reach.
                'issuer'  => config('matrix.mas.issuer'),
                'account' => rtrim((string) config('matrix.mas.issuer'), '/').'/account',
            ],
        ])->header('Access-Control-Allow-Origin', '*');
    }

    /**
     * "<server_name>:<port>" the federation API is reachable at. Explicit override
     * (MATRIX_DELEGATE_SERVER) wins; otherwise compute from the configured server_name +
     * the APP_URL port (the CGA nginx proxies /_matrix/ on that port).
     */
    private function delegateAuthority(): string
    {
        $explicit = config('matrix.well_known.delegate_server');
        if (! empty($explicit)) {
            return (string) $explicit;
        }

        $serverName = (string) config('matrix.server_name');
        $appUrl = (string) config('matrix.well_known.client_base_url');
        $port = parse_url($appUrl, PHP_URL_PORT);

        return $port ? "{$serverName}:{$port}" : $serverName;
    }
}
