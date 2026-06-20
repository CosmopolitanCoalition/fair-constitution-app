<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\FederationPeer;
use App\Services\Federation\BrokerAuthorizationService;
use App\Services\Federation\CapabilityService;
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
        private readonly CapabilityService $capabilities,
        private readonly BrokerAuthorizationService $brokerAuth,
    ) {}

    /** GET /api/federation/identity (public) — our advertised identity + transports + capabilities. */
    public function identity(): JsonResponse
    {
        return response()->json(
            $this->identity->handshakePayload() + [
                'url' => config('cga.federation_self_url'),
                // G8b — every channel we are reachable over, so a discoverer populates
                // its failover ladder up front (not just our single legacy url).
                'transports' => $this->transports->selfEndpoints(),
                // Mesh Roles ★4 — our capability manifest (the role set we offer), signed with this payload.
                'capabilities' => $this->capabilities->selfCapabilities(),
                // Mesh Roles ★8/A1 — our broker-routing attestations, so a peer learns which boxes the mesh
                // trusts to broker under each domain (each fact verified against its authority's pinned key).
                'broker_authorizations' => $this->brokerAuth->wire(),
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
            // Mesh Roles ★4 — the peer's capability manifest (the role set it advertises).
            'capabilities' => ['nullable', 'array'],
            'capabilities.*.capability' => ['nullable', 'string'],
            'capabilities.*.priority' => ['nullable', 'integer'],
            'capabilities.*.granted_by_server_id' => ['nullable', 'string'],
            'capabilities.*.grant_signature' => ['nullable', 'string'],
            'capabilities.*.grant_expires_at' => ['nullable', 'integer'],
            // Mesh Roles ★8/A1 — the peer's broker-routing attestations (verified against each authority's key).
            'broker_authorizations' => ['nullable', 'array'],
            'broker_authorizations.*.domain' => ['nullable', 'string'],
            'broker_authorizations.*.broker_server_id' => ['nullable', 'string'],
            'broker_authorizations.*.authority_server_id' => ['nullable', 'string'],
            'broker_authorizations.*.authority_pubkey' => ['nullable', 'string'],
            'broker_authorizations.*.signature' => ['nullable', 'string'],
            'broker_authorizations.*.issued_at' => ['nullable', 'integer'],
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
