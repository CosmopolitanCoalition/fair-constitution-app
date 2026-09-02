<?php

namespace Tests\Feature;

use App\Services\DistrictingService;
use App\Support\AutoscaleContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * THE BEAT CONNECTION visibility pins (2026-09-02, the composite
 * transaction split), live Postgres.
 *
 *  (a) A heartbeat published while the lane's default connection holds an
 *      open transaction is visible from a third session BEFORE the default
 *      connection commits: the scope row's updated_at and the lease's
 *      last_seen_at / claim_label move at once, and the lease carries the
 *      WORK backend pid, not the beat session's.
 *  (b) With a claim token other than the one on the scope row, the scope
 *      row is not updated.
 *
 * Fixtures are COMMITTED rows (fresh uuids: one run, one lease, one ledger
 * scope) in the public tables, deleted in finally. Cross-session visibility
 * cannot be shown on temp tables or inside a rolled-back transaction.
 * THIS TEST WRITES to the database LIVE_PG_DATABASE names (default
 * fair_constitution). Point LIVE_PG_DATABASE at a test database before
 * running it.
 */
class CompositeTransactionBeatVisibilityTest extends TestCase
{
    use LivePgConnection;

    private const WORK  = 'pgsql_ct_work';
    private const PROBE = 'pgsql_ct_probe';
    private const OLD   = '2000-01-01 00:00:00+00';

    public function test_heartbeat_is_visible_before_the_work_connection_commits(): void
    {
        $this->withFixtures(function (array $fx, Connection $work, Connection $beat, Connection $probe): void {
            $work->beginTransaction();
            try {
                // The lane's work transaction is open and idle, the way the
                // Step-8 search leaves it.
                $work->select('SELECT 1');

                AutoscaleContext::enter($fx['run'], $fx['leg'], $fx['scope'], $fx['token']);
                app(DistrictingService::class)->publishMassProgress($fx['leg'], [
                    'phase' => 'binning', 'phase_label' => 'probe beat',
                ]);

                $this->assertSame(1, $work->transactionLevel(), 'the default connection has not committed');
                $this->assertSame(0, $beat->transactionLevel(), 'the beat connection holds no transaction');

                $scope = $probe->table('apportionment_ledger_scopes')->where('id', $fx['scope'])->first();
                $this->assertGreaterThan(strtotime(self::OLD), strtotime($scope->updated_at),
                    'the scope beat is visible from a third session before any commit on the default connection');

                $lease = $probe->table('autoscale_worker_leases')->where('id', $fx['token'])->first();
                $this->assertGreaterThan(strtotime(self::OLD), strtotime($lease->last_seen_at),
                    'the lease beat is visible from a third session before any commit on the default connection');
                $this->assertSame('base ⋯ probe beat', $lease->claim_label);
                $this->assertSame($fx['work_pid'], (int) $lease->pg_backend_pid,
                    'the lease carries the WORK backend pid, never the beat session\'s');
            } finally {
                AutoscaleContext::clear();
                $work->rollBack();
            }
        });
    }

    public function test_wrong_claim_token_leaves_the_scope_row_untouched(): void
    {
        $this->withFixtures(function (array $fx, Connection $work, Connection $beat, Connection $probe): void {
            try {
                AutoscaleContext::enter($fx['run'], $fx['leg'], $fx['scope'], (string) Str::uuid());
                app(DistrictingService::class)->publishMassProgress($fx['leg'], [
                    'phase' => 'binning', 'phase_label' => 'stranger',
                ]);

                $scope = $probe->table('apportionment_ledger_scopes')->where('id', $fx['scope'])->first();
                $this->assertSame(strtotime(self::OLD), strtotime($scope->updated_at),
                    'a beat with a token other than the row\'s claim_token does not refresh the reclaim clock');
                $lease = $probe->table('autoscale_worker_leases')->where('id', $fx['token'])->first();
                $this->assertSame(strtotime(self::OLD), strtotime($lease->last_seen_at),
                    'the stranger token names no lease of ours');
            } finally {
                AutoscaleContext::clear();
            }
        });
    }

    /**
     * Three live sessions: WORK (set as the default, the lane's connection),
     * the service's own BEAT_CONNECTION pointed at the live database the way
     * the trait points every live pin, and PROBE (a third session that
     * inserts the committed fixtures and reads the results).
     */
    private function withFixtures(callable $body): void
    {
        $work  = $this->livePg(self::WORK);
        $probe = $this->livePg(self::PROBE);
        $beat  = $this->livePg(DistrictingService::BEAT_CONNECTION);

        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::WORK);

        $fx = [
            'run'      => (string) Str::uuid(),
            'leg'      => (string) Str::uuid(),
            'scope'    => (string) Str::uuid(),
            'token'    => (string) Str::uuid(),
            'work_pid' => (int) $work->selectOne('SELECT pg_backend_pid() AS pid')->pid,
        ];

        try {
            $probe->table('autoscale_runs')->insert([
                'id' => $fx['run'], 'status' => 'mapping', 'adm_max' => 6,
                'sized_parents' => 0, 'sized_leaves' => 0,
                'singles_total' => 0, 'singles_done' => 0,
                'sweeps_total' => 0, 'sweeps_done' => 0, 'review_count' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $probe->table('autoscale_worker_leases')->insert([
                'id' => $fx['token'], 'run_id' => $fx['run'], 'lane' => 'auto',
                'claim_type' => 'scope', 'claim_label' => 'base',
                'started_at' => self::OLD, 'last_seen_at' => self::OLD,
                'pg_backend_pid' => null,
            ]);
            $probe->table('apportionment_ledger_scopes')->insert([
                'id' => $fx['scope'], 'legislature_id' => $fx['leg'],
                'scope_jurisdiction_id' => (string) Str::uuid(),
                'depth' => 0, 'walk_position' => 1, 'seat_budget' => 5,
                'status' => 'running', 'claim_token' => $fx['token'],
                'created_at' => self::OLD, 'updated_at' => self::OLD,
            ]);

            $body($fx, $work, $beat, $probe);
        } finally {
            while ($work->transactionLevel() > 0) {
                $work->rollBack();
            }
            $probe->table('apportionment_ledger_scopes')->where('id', $fx['scope'])->delete();
            $probe->table('autoscale_worker_leases')->where('id', $fx['token'])->delete();
            $probe->table('autoscale_runs')->where('id', $fx['run'])->delete();
            DB::setDefaultConnection($original);
        }
    }
}
