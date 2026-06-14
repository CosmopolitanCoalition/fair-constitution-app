<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\FederationPeer;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\PeerService;
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
    ) {}

    /** GET /api/federation/identity (public) — our advertised identity. */
    public function identity(): JsonResponse
    {
        return response()->json(
            $this->identity->handshakePayload() + ['url' => config('cga.federation_self_url')]
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
