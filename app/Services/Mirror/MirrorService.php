<?php

namespace App\Services\Mirror;

use App\Models\ClusterMembership;
use App\Models\FederationPeer;
use App\Models\InstanceSettings;
use App\Services\AuditService;
use App\Services\Federation\PeerService;

/**
 * Mirror lifecycle (Phase G, Track A — the permissionless read-only commons).
 *
 * The shared local-state path behind BOTH the CLI (`federation:cold-sync` /
 * adoption command) and the browser "Join a cluster" wizard. G1 lands the
 * structural primitives — pin the host, open the `mirror` membership, and flip
 * THIS instance into read-only-mirror mode. G2 layers the network adoption
 * (`POST /api/federation/adopt`, join key, chunked backfill) and the
 * ConstitutionalEngine write-guard on top of these primitives.
 *
 * Cardinal invariant: a mirror is authoritative for NOTHING. Nothing here ever
 * writes `authoritative_server_id` — `mirror_of_server_id` points AT the host;
 * it never claims authority.
 */
class MirrorService
{
    public function __construct(
        private readonly PeerService $peers,
        private readonly AuditService $audit,
    ) {}

    /** Is THIS instance a read-only mirror of some host? */
    public function isMirror(): bool
    {
        return InstanceSettings::current()->isMirror();
    }

    /**
     * Pin the host we intend to mirror as a trusted `host` edge (TOFU), reusing
     * the shared peer-upsert so the mirror path and the sovereign handshake share
     * one pin block. From our side the peer's relation is `host`.
     *
     * @param  array<string,mixed>  $attrs  name/url/schema_version
     */
    public function pinHost(string $serverId, string $publicKey, array $attrs = []): FederationPeer
    {
        return $this->peers->upsertTrustedPeer(
            $serverId, $publicKey, $attrs, FederationPeer::RELATION_HOST, 'mirror_host'
        );
    }

    /**
     * Open (idempotently) our `mirror` membership against a host peer. Creating a
     * second active `mirror` membership — against any other host — is rejected by
     * the one-active-mirror index: an instance mirrors at most one host.
     */
    public function openMirrorMembership(
        FederationPeer $host,
        string $admissionMethod,
        ?string $scopeJurisdictionId = null,
    ): ClusterMembership {
        return ClusterMembership::query()->firstOrCreate(
            ['peer_id' => $host->id, 'role' => ClusterMembership::ROLE_MIRROR],
            [
                'state' => ClusterMembership::STATE_REQUESTED,
                'admission_method' => $admissionMethod,
                'scope_jurisdiction_id' => $scopeJurisdictionId,
            ],
        );
    }

    /**
     * Commit the adoption: mark the membership live and flip THIS instance into
     * read-only-mirror mode. After this the engine write-guard (G2) refuses every
     * constitutional write. Idempotent — re-running on an already-live mirror of
     * the same host is a no-op beyond refreshing the membership state.
     */
    public function markMirrorLive(ClusterMembership $membership, string $hostServerId): void
    {
        $membership->update(['state' => ClusterMembership::STATE_LIVE]);

        $settings = InstanceSettings::current();
        $settings->mirror_of_server_id = $hostServerId;
        $settings->mirror_adopted_at ??= now();
        $settings->save();

        $this->audit->append('mirror', 'mirror.adopted',
            ['host_server_id' => $hostServerId, 'membership_id' => $membership->id], 'WF-JUR-06');
    }
}
