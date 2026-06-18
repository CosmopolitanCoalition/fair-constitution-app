<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\FederationPeer;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\PeerService;
use App\Services\Federation\TransportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Server-to-server federation endpoints (Phase F, WF-JUR-06). Reached only by
 * other instances under the VerifyPeerSignature middleware — never a browser.
 */
class PeerController extends Controller
{
    public function __construct(
        private readonly InstanceIdentityService $identity,
        private readonly PeerService $peers,
        private readonly TransportService $transports,
    ) {}

    /** GET /api/federation/identity (public) — our advertised identity + transports. */
    public function identity(): JsonResponse
    {
        return response()->json(
            $this->identity->handshakePayload() + [
                'url' => config('cga.federation_self_url'),
                // G8b — every channel we are reachable over, so a discoverer populates
                // its failover ladder up front (not just our single legacy url).
                'transports' => $this->transports->selfEndpoints(),
            ]
        );
    }

    /** POST /api/federation/handshake (TOFU) — a peer introduces itself. */
    public function handshake(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'server_id' => ['required', 'uuid'],
            'public_key' => ['required', 'string'],
            'name' => ['nullable', 'string'],
            'url' => ['nullable', 'string'],
            'schema_version' => ['nullable', 'string'],
            'constitutional_version' => ['nullable', 'string'],
            'app_release' => ['nullable', 'string'],
            // G8b — the peer's reachable channels (learned into the ladder).
            'transports' => ['nullable', 'array'],
            'transports.*.transport' => ['nullable', 'string'],
            'transports.*.url' => ['nullable', 'string'],
            'transports.*.priority' => ['nullable', 'integer'],
        ]);

        return response()->json($this->peers->receiveHandshake($payload));
    }

    /** POST /api/federation/heartbeat (pinned) — liveness ping. */
    public function heartbeat(Request $request): JsonResponse
    {
        /** @var FederationPeer $peer */
        $peer = $request->attributes->get('peer');

        $this->peers->recordHeartbeat($peer);

        return response()->json(['ok' => true, 'at' => now()->toIso8601String()]);
    }
}
