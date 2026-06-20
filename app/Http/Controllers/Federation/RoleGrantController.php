<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\FederationPeer;
use App\Models\InstanceCapability;
use App\Services\AuditService;
use App\Services\Federation\CapabilityService;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mesh Roles & Channels of Trust (★17) — cross-instance capability-grant delivery (the JOIN hop). An
 * authority box pushes a minted, signed capability_grant to the grantee here; we accept it ONLY if its
 * signature verifies against the AUTHORITY's OWN pinned key, the claimed authority_pubkey equals that
 * pinned key, AND the grant names THIS box (server_id + pubkey) as the grantee — no trust by relay, no
 * grant applied to a box it was not addressed to. On all checks passing we grantSelf the channel (the
 * grant receipt is the cryptographic proof of the dual-meter approval). Reached only by a pinned peer.
 */
class RoleGrantController extends Controller
{
    public function __construct(
        private readonly InstanceIdentityService $identity,
        private readonly CapabilityService $capabilities,
    ) {}

    public function receiveGrant(Request $request): JsonResponse
    {
        /** @var FederationPeer $from */
        $from = $request->attributes->get('peer');

        // RAW bytes — the canonical grant must not be mutated before we re-canonicalize it.
        $data = json_decode((string) $request->getContent(), true);
        $grant = is_array($data) ? ($data['grant'] ?? null) : null;
        $grantSig = is_array($data) ? (string) ($data['grant_signature'] ?? '') : '';

        if (! is_array($grant) || $grantSig === ''
            || ($grant['type'] ?? null) !== 'capability_grant' || ($grant['v'] ?? null) !== 1) {
            return response()->json(['error' => 'malformed capability_grant'], 422);
        }

        $authorityServerId = (string) ($grant['authority_server_id'] ?? '');
        $authorityPub = (string) ($grant['authority_pubkey'] ?? '');
        $pinned = $this->pinnedKey($authorityServerId, $from);

        if ($pinned === null || ! hash_equals($pinned, $authorityPub)) {
            return response()->json(['error' => 'authority not pinned, or pubkey mismatch'], 403);
        }
        if (! InstanceIdentityService::verify($pinned, AuditService::canonicalJson($grant), $grantSig)) {
            return response()->json(['error' => 'grant signature invalid'], 403);
        }

        // The grant must be addressed to THIS box — never apply a grant minted for another grantee.
        if ((string) ($grant['peer_server_id'] ?? '') !== $this->identity->serverId()
            || (string) ($grant['peer_pubkey'] ?? '') !== $this->identity->publicKey()) {
            return response()->json(['error' => 'grant is not addressed to this box'], 403);
        }

        $capability = (string) ($grant['capability'] ?? '');
        if (! InstanceCapability::isGoverned($capability)) {
            return response()->json(['error' => 'not a governed capability'], 422);
        }

        // JOIN — apply the grant locally, flipping the channel enabled with its receipt on the row.
        $this->capabilities->grantSelf($capability, $authorityServerId, $grantSig, (int) ($grant['expires_at'] ?? 0));

        return response()->json([
            'ok' => true,
            'capability' => $capability,
            'expires_at' => (int) ($grant['expires_at'] ?? 0),
        ]);
    }

    /** The authority's pinned key — ours if it's us, the sender's if it sent, else the pinned peer's. */
    private function pinnedKey(string $serverId, FederationPeer $from): ?string
    {
        if ($serverId === $this->identity->serverId()) {
            return $this->identity->publicKey();
        }
        if ($serverId === (string) $from->server_id && $from->public_key !== null) {
            return (string) $from->public_key;
        }
        $peer = FederationPeer::query()->where('server_id', $serverId)->whereNull('deleted_at')->first();

        return $peer?->public_key !== null ? (string) $peer->public_key : null;
    }
}
