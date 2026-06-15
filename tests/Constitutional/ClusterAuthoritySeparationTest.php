<?php

namespace Tests\Constitutional;

use App\Models\Cluster;
use App\Services\Cluster\ClusterMembershipService;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G·co-member) the CARDINAL invariant: AUTHORITY
 * (jurisdictions.authoritative_server_id) and LEADERSHIP (clusters.leader_server_id,
 * a Patroni data-tier axis) are ORTHOGONAL. The pins:
 *  1. GREP — the authority-path files reference NO leadership/cluster state;
 *  2. reconcileLeadership NEVER changes a jurisdiction's authority;
 *  3. leader_epoch is monotonic — a stale leader is fenced;
 *  4. at most one authority cluster per jurisdiction (anti-split-brain);
 *  5. forming a cluster makes us our own single-node leader.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class ClusterAuthoritySeparationTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_cluster_sep';

    public function test_authority_path_files_never_reference_leadership_or_cluster_state(): void
    {
        $forbidden = ['leader_server_id', 'leader_epoch', 'cluster_members', 'reconcileLeadership', 'home_cluster_id', 'ClusterMembershipService'];

        foreach (['app/Services/Federation/FederationSyncService.php', 'app/Services/Federation/AuthorityFlipService.php'] as $rel) {
            $src = (string) file_get_contents(base_path($rel));
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $src,
                    "{$rel} must not reference leadership/cluster state ({$needle}) — authority ≠ leadership");
            }
        }
    }

    public function test_forming_a_cluster_makes_us_our_own_single_node_leader(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $svc = app(ClusterMembershipService::class);

            $cluster = $svc->form((string) Str::uuid());

            $this->assertTrue($cluster->is_self);
            $this->assertSame($identity->serverId(), $cluster->leader_server_id, 'single-node: we lead ourselves');
            $this->assertSame('single_node', $cluster->topology);
            $this->assertSame(1, $cluster->members()->where('is_self', true)->count());
            $this->assertTrue($svc->isWriteLeader($cluster));
        });
    }

    public function test_reconcile_leadership_is_monotonic_and_never_touches_authority(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $svc = app(ClusterMembershipService::class);

            // A single jurisdiction's authority — must be unchanged by leadership ops.
            $jur = DB::table('jurisdictions')->select('id', 'authoritative_server_id')->first();

            $peerA = (string) Str::uuid();
            $stalePeer = (string) Str::uuid();

            $cluster = $svc->form((string) Str::uuid());
            $svc->reconcileLeadership($cluster, $peerA, 5);
            $this->assertSame($peerA, $cluster->refresh()->leader_server_id);
            $this->assertSame(5, (int) $cluster->leader_epoch);

            // A stale observation (epoch < current) is fenced.
            $svc->reconcileLeadership($cluster, $stalePeer, 3);
            $this->assertSame($peerA, $cluster->refresh()->leader_server_id, 'a stale leader is fenced');
            $this->assertSame(5, (int) $cluster->leader_epoch);

            if ($jur !== null) {
                $after = DB::table('jurisdictions')->where('id', $jur->id)->value('authoritative_server_id');
                $this->assertSame($jur->authoritative_server_id, $after, 'leadership ops never move authority');
            }
        });
    }

    public function test_one_authority_cluster_per_jurisdiction(): void
    {
        $this->onLivePg(function () {
            $jid = (string) Str::uuid();
            Cluster::create(['kind' => Cluster::KIND_AUTHORITY, 'jurisdiction_id' => $jid, 'is_self' => false]);

            $threw = false;
            try {
                DB::transaction(fn () => Cluster::create(['kind' => Cluster::KIND_AUTHORITY, 'jurisdiction_id' => $jid, 'is_self' => false]));
            } catch (QueryException $e) {
                $threw = true;
                $this->assertStringContainsStringIgnoringCase('one_authority_cluster_per_jurisdiction', $e->getMessage());
            }

            $this->assertTrue($threw, 'a second authority cluster for one jurisdiction is rejected');
        });
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
