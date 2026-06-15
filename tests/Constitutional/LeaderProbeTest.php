<?php

namespace Tests\Constitutional;

use App\Services\Cluster\ClusterMembershipService;
use App\Services\Cluster\LeaderProbe;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (Patroni HA). Leadership is a DATA-TIER axis
 * OBSERVED from Postgres, never decided by PHP, and ORTHOGONAL to authority.
 * The pins:
 *  1. the primary is the write-leader; the timeline_id is a monotonic epoch;
 *  2. reconcileFromDataTier makes the primary the leader at epoch = timeline;
 *  3. it respects the monotonic fence — a stale timeline never unseats a
 *     higher-epoch leader;
 *  4. the scheduler dispatches on EXACTLY ONE server (Redis lock) and the sweep
 *     fires only on the write-leader;
 *  5. LeaderProbe never touches authority (leadership ≠ authority).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class LeaderProbeTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_leader_probe';

    public function test_the_primary_is_the_write_leader_with_a_monotonic_timeline_epoch(): void
    {
        $this->onLivePg(function () {
            $probe = app(LeaderProbe::class);

            $this->assertTrue($probe->isPrimary(), 'the dev/test postgres is a primary (not in recovery)');
            $this->assertGreaterThanOrEqual(1, $probe->timeline(), 'the timeline is a positive monotonic leadership epoch');
        });
    }

    public function test_reconcile_from_data_tier_makes_the_primary_the_leader_at_the_timeline_epoch(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $probe = app(LeaderProbe::class);
            $svc = app(ClusterMembershipService::class);

            $cluster = $svc->form((string) Str::uuid());
            $reconciled = $probe->reconcileFromDataTier($cluster);

            $this->assertSame($identity->serverId(), $reconciled->leader_server_id, 'the primary leads itself');
            $this->assertSame($probe->timeline(), (int) $reconciled->leader_epoch, 'epoch == the Postgres timeline');
            $this->assertSame('patroni', $reconciled->topology);
            $this->assertTrue($svc->isWriteLeader($reconciled));
        });
    }

    public function test_reconcile_from_data_tier_respects_the_epoch_fence(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $probe = app(LeaderProbe::class);
            $svc = app(ClusterMembershipService::class);

            $cluster = $svc->form((string) Str::uuid());

            // A later-timeline leader is already recorded at a high epoch.
            $laterLeader = (string) Str::uuid();
            $svc->reconcileLeadership($cluster, $laterLeader, 999);
            $this->assertSame($laterLeader, $cluster->refresh()->leader_server_id);

            // The data-tier observation (timeline = 1) is STALE relative to epoch
            // 999 → fenced; the higher-epoch leader stands. A flapping/demoted node
            // can never claw leadership back with an old timeline.
            $probe->reconcileFromDataTier($cluster);
            $this->assertSame($laterLeader, $cluster->refresh()->leader_server_id, 'a stale timeline is fenced');
            $this->assertSame(999, (int) $cluster->leader_epoch);
        });
    }

    public function test_the_scheduler_dispatches_on_exactly_one_server_and_fires_on_the_write_leader(): void
    {
        $console = (string) file_get_contents(base_path('routes/console.php'));
        $this->assertStringContainsString(
            'EvaluateClocksJob)->everyMinute()->withoutOverlapping()->onOneServer()',
            $console,
            'the every-minute clock sweep must dispatch on exactly one server (Redis lock)'
        );

        $job = (string) file_get_contents(base_path('app/Jobs/EvaluateClocksJob.php'));
        $this->assertStringContainsString(
            'LeaderProbe::class)->isPrimary()',
            $job,
            'the clock sweep must fire only on the write-leader'
        );
    }

    public function test_leader_probe_never_touches_authority(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Cluster/LeaderProbe.php'));

        foreach (['authoritative_server_id', 'authorityDisposition', 'AuthorityFlip'] as $needle) {
            $this->assertStringNotContainsString($needle, $src,
                "LeaderProbe must not touch authority ({$needle}) — leadership ≠ authority");
        }
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
