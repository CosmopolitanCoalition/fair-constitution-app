<?php

namespace App\Services\Cluster;

use App\Models\Cluster;
use App\Models\ClusterMember;
use App\Services\AuditService;
use App\Services\Federation\InstanceIdentityService;

/**
 * Co-member cluster lifecycle (Phase G, G·co-member).
 *
 * Cardinal discipline: `reconcileLeadership` is the ONLY method anywhere that
 * writes `leader_server_id` / `leader_epoch`. Leadership is OBSERVED from Patroni
 * (which node is primary), fenced by a monotonic epoch, and is ORTHOGONAL to
 * authority — nothing here ever touches `jurisdictions.authoritative_server_id`,
 * and no authority-path file may read cluster/leader state (the
 * ClusterAuthoritySeparation grep pin enforces it).
 */
class ClusterMembershipService
{
    public function __construct(
        private readonly InstanceIdentityService $identity,
        private readonly AuditService $audit,
    ) {}

    /**
     * Form a new authority cluster we own — a single-node "we are our own leader"
     * cluster until Patroni supplies a real observed primary.
     */
    public function form(?string $jurisdictionId = null, ?string $authorityClaimId = null): Cluster
    {
        $cluster = Cluster::create([
            'kind' => Cluster::KIND_AUTHORITY,
            'jurisdiction_id' => $jurisdictionId,
            'authority_claim_id' => $authorityClaimId,
            'is_self' => true,
            'topology' => 'single_node',
            'leader_server_id' => $this->identity->serverId(), // single-node: we lead ourselves
            'leader_epoch' => 1,
        ]);

        ClusterMember::create([
            'cluster_id' => $cluster->id,
            'server_id' => $this->identity->serverId(),
            'is_self' => true,
            'state' => ClusterMember::STATE_LIVE,
            'role' => 'co_member',
        ]);

        $this->audit->append('cluster', 'cluster.formed',
            ['cluster_id' => $cluster->id, 'jurisdiction_id' => $jurisdictionId], 'WF-JUR-06');

        return $cluster;
    }

    /** Record a governed-admitted co-member (the admission act happens upstream). */
    public function admit(Cluster $cluster, string $serverId): ClusterMember
    {
        $member = ClusterMember::query()->firstOrCreate(
            ['cluster_id' => $cluster->id, 'server_id' => $serverId],
            ['state' => ClusterMember::STATE_LIVE, 'role' => 'co_member'],
        );

        $this->audit->append('cluster', 'cluster.member_admitted',
            ['cluster_id' => $cluster->id, 'server_id' => $serverId], 'WF-JUR-06');

        return $member;
    }

    /**
     * THE ONLY writer of leadership. Records the data-tier primary Patroni
     * observed, fencing on a monotonic epoch — a stale leader (epoch < current)
     * is ignored. NEVER touches authoritative_server_id.
     */
    public function reconcileLeadership(Cluster $cluster, string $observedLeaderServerId, int $observedEpoch): Cluster
    {
        if ($observedEpoch < (int) $cluster->leader_epoch) {
            return $cluster; // a stale observation is fenced
        }

        $cluster->leader_server_id = $observedLeaderServerId;
        $cluster->leader_epoch = $observedEpoch;
        $cluster->topology = 'patroni';
        $cluster->save();

        $this->audit->append('cluster', 'cluster.leadership_reconciled',
            ['cluster_id' => $cluster->id, 'leader_server_id' => $observedLeaderServerId, 'epoch' => $observedEpoch], 'WF-JUR-06');

        return $cluster;
    }

    /** Are we the current write-leader of this cluster? */
    public function isWriteLeader(Cluster $cluster): bool
    {
        return (string) $cluster->leader_server_id === $this->identity->serverId();
    }
}
