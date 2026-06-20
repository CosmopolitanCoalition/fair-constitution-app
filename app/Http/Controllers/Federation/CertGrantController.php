<?php

namespace App\Http\Controllers\Federation;

use App\Http\Controllers\Controller;
use App\Models\FederationPeer;
use App\Services\AuditService;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mesh Roles & Channels of Trust (★11) — cert-grant delivery. An authority.grant-holding box pushes a
 * minted cert_grant to the grantee here (the cross-instance half of grant-on-promotion). We accept it ONLY
 * if its signature verifies against the AUTHORITY's OWN pinned key and the grant's claimed authority_pubkey
 * equals that pinned key — no trust by relay (the ingestAnnounce discipline). The grantee then uses the
 * grant via `mesh:request-cert`. Reached only by a pinned peer (federation.signed).
 */
class CertGrantController extends Controller
{
    public function __construct(private readonly InstanceIdentityService $identity) {}

    public function receiveGrant(Request $request): JsonResponse
    {
        /** @var FederationPeer $from */
        $from = $request->attributes->get('peer');

        // RAW bytes — the canonical grant must not be mutated before we re-canonicalize it.
        $data = json_decode((string) $request->getContent(), true);
        $grant = is_array($data) ? ($data['grant'] ?? null) : null;
        $grantSig = is_array($data) ? (string) ($data['grant_signature'] ?? '') : '';

        if (! is_array($grant) || $grantSig === ''
            || ($grant['type'] ?? null) !== 'cert_grant' || ($grant['v'] ?? null) !== 1) {
            return response()->json(['error' => 'malformed cert_grant'], 422);
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

        // Verified — the grantee may now use it to request a cert (storage is the operator's; we ack).
        return response()->json([
            'ok' => true,
            'domain' => (string) ($grant['domain'] ?? ''),
            'subdomain' => (string) ($grant['subdomain'] ?? ''),
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
