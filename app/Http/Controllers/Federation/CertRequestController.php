<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Services\Federation\InMeshBrokerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MeshCertBroker\BrokerError;
use Throwable;

/**
 * Mesh Roles & Channels of Trust (★9) — the in-mesh broker endpoint. A needy peer POSTs its signed cert
 * request here (reached over the multiplex ladder by capability+domain discovery); we hand the RAW bytes
 * to the same Broker::issue() Box C runs, sourcing authority_keys from the gossiped broker_authorizations.
 * Reached only by a pinned peer (federation.signed) — never a browser.
 */
class CertRequestController extends Controller
{
    public function __construct(private readonly InMeshBrokerService $broker) {}

    public function certRequest(Request $request): JsonResponse
    {
        // RAW bytes — the signed canonical request must not be mutated by TrimStrings.
        $body = json_decode((string) $request->getContent(), true);
        if (! is_array($body)) {
            return response()->json(['error' => 'malformed cert request'], 422);
        }

        try {
            return response()->json($this->broker->issue($body));
        } catch (BrokerError $e) {
            // Client-safe message + the broker's HTTP status; never a token/key/path.
            return response()->json(['error' => $e->getMessage()], $e->status);
        } catch (Throwable $e) {
            return response()->json(['error' => 'cert issuance failed'], 500);
        }
    }
}
