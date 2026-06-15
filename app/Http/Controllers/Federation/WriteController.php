<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\FederationPeer;
use App\Services\Federation\ForwardedWriteRefused;
use App\Services\Federation\WriteRouterService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Forwarded writes (Phase G, G4). `POST /api/federation/write` — an established,
 * PINNED peer forwards a write for a jurisdiction WE are authoritative for. The
 * `federation.signed` (pinned) middleware has already verified the peer's
 * Ed25519 signature over the raw body and bound the peer into the request, so
 * the forwarder is authenticated; the WriteRouterService then runs the write
 * through the NORMAL ConstitutionalEngine and records the outcome idempotently.
 *
 * Outcomes:
 *   200 executed   — the engine filed it (audit_seq + result_hash returned);
 *   422 rejected   — a valid constitutional denial (citation returned), OR a
 *                    malformed envelope;
 *   403            — an unverifiable citizen-actor claim (pre-G-ID);
 *   421            — misdirected (we are not the authoritative leader);
 *   409            — a concurrent duplicate forward (idempotency key in flight).
 */
class WriteController extends Controller
{
    public function __construct(private readonly WriteRouterService $router) {}

    public function write(Request $request): JsonResponse
    {
        /** @var FederationPeer $peer */
        $peer = $request->attributes->get('peer');

        // Parse the RAW signed bytes — the peer signature is over getContent(),
        // not the middleware-mutated parsed input (the SyncController discipline).
        $envelope = json_decode((string) $request->getContent(), true) ?? [];

        try {
            $outcome = $this->router->executeForwarded($envelope, $peer);
        } catch (ForwardedWriteRefused $e) {
            return response()->json(['error' => $e->reason], $e->status);
        } catch (QueryException) {
            // The (origin_server_id, idempotency_key) unique index — a duplicate
            // forward already claimed the key and is executing.
            return response()->json(['error' => 'forward_in_flight'], 409);
        }

        $status = ($outcome['status'] ?? '') === 'rejected' ? 422 : 200;

        return response()->json($outcome, $status);
    }
}
