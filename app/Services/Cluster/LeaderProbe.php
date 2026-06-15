<?php

namespace App\Services\Cluster;

use App\Models\Cluster;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Support\Facades\DB;

/**
 * Observes the DATA-TIER leadership Patroni decides (Phase G, Patroni HA) — it
 * never decides it. The write-leader is whichever node Patroni has promoted to
 * Postgres PRIMARY; this probe reads that truth in pure SQL, with no etcd/DCS
 * round-trip:
 *   - pg_is_in_recovery() = false  → this connection IS the primary (write-leader);
 *   - pg_control_checkpoint().timeline_id → Postgres bumps the timeline on every
 *     promotion, so it is a MONOTONIC leadership epoch that fences a stale leader.
 *
 * The cardinal invariant holds: leadership (this axis) is ORTHOGONAL to authority
 * (the authoritative-server axis). This probe touches neither authority nor any
 * authority-path file — it only feeds ClusterMembershipService::reconcileLeadership,
 * the single epoch-fenced writer of leadership. "Patroni decides, PHP observes."
 */
class LeaderProbe
{
    public function __construct(
        private readonly InstanceIdentityService $identity,
        private readonly ClusterMembershipService $clusters,
    ) {}

    /** Is this node's database connection the Postgres primary (the write-leader)? */
    public function isPrimary(): bool
    {
        $row = DB::selectOne('SELECT pg_is_in_recovery() AS in_recovery');

        return ! (bool) $row->in_recovery;
    }

    /**
     * The current Postgres timeline — a monotonic leadership epoch Patroni bumps
     * on every promotion/failover (timeline 1 → 2 → 3 …). Fences a stale leader
     * without consulting the DCS.
     */
    public function timeline(): int
    {
        $row = DB::selectOne('SELECT timeline_id FROM pg_control_checkpoint()');

        return (int) $row->timeline_id;
    }

    /**
     * Observe the data tier and reconcile the cluster's leadership. When THIS
     * node is the primary we are the write-leader at epoch = timeline; a follower
     * observes nothing authoritative about who leads (and cannot write the
     * clusters row anyway), so it leaves leadership unchanged. A demoted ex-primary
     * therefore never keeps claiming leadership. Delegates to the sole epoch-fenced
     * writer (a stale observation is ignored there).
     */
    public function reconcileFromDataTier(Cluster $cluster): Cluster
    {
        if (! $this->isPrimary()) {
            return $cluster; // only the primary self-reports leadership
        }

        return $this->clusters->reconcileLeadership($cluster, $this->identity->serverId(), $this->timeline());
    }
}
