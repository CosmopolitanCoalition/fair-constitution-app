<?php

namespace Tests\Feature;

use App\Jobs\MaintainSimulationStatisticsJob;
use App\Services\Demo\SimulationStatisticsMaintenance;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\Support\Step5BoardDatabase;
use Tests\TestCase;

class SimulationStatisticsMaintenanceTest extends TestCase
{
    use Step5BoardDatabase;

    private array $children = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBoardFixture();
        (require database_path('migrations/2026_09_20_073000_create_sim_statistics_maintenance.php'))->up();
        DB::table('sim_runs')->insert(['id' => $this->id(1), 'status' => 'running', 'phase' => 'civics']);
        DB::table('sim_timings')->insert(['run_id' => $this->id(1), 'part' => 'stage.civics_scope', 'count' => 1000]);
    }

    protected function tearDown(): void
    {
        foreach ($this->children as [$process, $pipes]) {
            if (is_resource($process)) {
                proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_close($process);
            }
        }
        $this->closeBoardFixture();
        parent::tearDown();
    }

    private function eligible(): void
    {
        $this->seedStaleBoards();
        $this->travel(6)->minutes(); // last analyze preceded the new population
    }

    private function state(): object
    {
        return DB::table('sim_statistics_maintenance')->where('target', SimulationStatisticsMaintenance::TARGET)->first();
    }

    private function distribution(): array
    {
        return json_decode(DB::selectOne("SELECT array_to_json(most_common_vals)::text AS values
            FROM pg_stats WHERE schemaname='public' AND tablename='boards' AND attname='boardable_type'")->values, true);
    }

    public function test_new_population_refreshes_real_statistics_and_preserves_all_application_rows(): void
    {
        $this->eligible();
        $before = $this->applicationDigest();
        $this->assertSame(['departments'], $this->distribution());
        $oldPlan = $this->oldLookupPlan();
        $this->assertSame('boards', $oldPlan['Plans'][0]['Relation Name'] ?? null);
        $result = (new SimulationStatisticsMaintenance)->check();
        $this->assertSame('succeeded', $result['status'], json_encode($result));
        $this->assertSame('new_organization_category', $result['reason']);
        $this->assertContains('organizations', $this->distribution());
        $newPlan = $this->oldLookupPlan();
        $this->assertSame('organizations', $newPlan['Plans'][0]['Relation Name'] ?? null);
        $this->assertStringContainsString('boardable_id', json_encode($newPlan));
        $this->assertSame($before, $this->applicationDigest());
        $attempt = DB::table('sim_statistics_attempts')->first();
        $this->assertSame('succeeded', $attempt->status);
        $this->assertSame('civics', $attempt->phase);
        $this->assertSame($this->id(1), $attempt->run_id);
        $this->assertNotNull($attempt->started_at);
        $this->assertNotNull($attempt->finished_at);
        $this->assertNotNull($this->state()->last_success_at);
        $evidence = json_decode($attempt->evidence, true);
        $this->assertArrayHasKey('reloptions', $evidence);
        $this->assertArrayHasKey('default_analyze_scale_factor', $evidence);
        $this->assertGreaterThanOrEqual(1000, $evidence['progress']);

        // New object/new session models an ordinary worker restart. Cooldowns
        // live in the database, not an in-process static or expiring cache owner.
        $this->assertSame('check_cooldown', (new SimulationStatisticsMaintenance)->check()['reason']);
        $this->travel(61)->seconds();
        $this->assertSame('attempt_cooldown', (new SimulationStatisticsMaintenance)->check()['reason']);
        $this->travel(16)->minutes();
        $this->assertSame('insufficient_change', (new SimulationStatisticsMaintenance)->check()['reason']);
        $this->assertSame(1, DB::table('sim_statistics_attempts')->count());
    }

    private function oldLookupPlan(): array
    {
        $scope = DB::table('organizations')->orderBy('id')->value('jurisdiction_id');
        $plan = DB::selectOne("EXPLAIN (FORMAT JSON) SELECT b.* FROM boards b JOIN organizations o
            ON o.id=b.boardable_id AND b.boardable_type='organizations'
            WHERE o.jurisdiction_id=? AND o.type='common_good_corp' AND o.deleted_at IS NULL
              AND b.deleted_at IS NULL AND b.status <> 'dissolved'", [$scope]);

        return json_decode($plan->{'QUERY PLAN'}, true)[0]['Plan'];
    }

    private function applicationDigest(): array
    {
        // Whole-table assertions are limited to these deliberately small disposable fixtures.
        $out = [];
        foreach (['boards', 'organizations', 'board_seats', 'terms', 'sim_runs', 'sim_items'] as $table) {
            $out[$table] = hash('sha256', json_encode(DB::table($table)->orderBy('id')->get()));
        }

        return $out;
    }

    public function test_fresh_external_statistics_skip_without_claiming_a_successful_maintenance_run(): void
    {
        $this->seedStaleBoards();
        DB::statement('ANALYZE boards (boardable_type)');
        $this->flushStatistics();
        $this->assertSame('recently_analyzed', (new SimulationStatisticsMaintenance)->check()['reason']);
        $this->assertNull($this->state()->last_success_at);
        $this->assertSame(0, DB::table('sim_statistics_attempts')->count());
        $this->travel(16)->minutes();
        $this->assertSame('insufficient_change', (new SimulationStatisticsMaintenance)->check()['reason']);
        $this->assertNull($this->state()->last_success_at);
    }

    public function test_waits_for_population_and_uses_bounded_progress_fallback_when_timers_are_absent(): void
    {
        $this->eligible();
        DB::table('sim_timings')->delete();
        $this->assertSame('awaiting_population', (new SimulationStatisticsMaintenance)->check()['reason']);
        DB::statement("INSERT INTO sim_items(id, run_id, kind, status)
            SELECT md5('item-' || n)::uuid, ?::uuid, 'civics_scope', 'done' FROM generate_series(1, 1100) n", [$this->id(1)]);
        DB::table('sim_timings')->insert(['run_id' => $this->id(1), 'part' => 'stage.civics_scope', 'count' => 0]);
        config(['cga.sim.timings' => false]);
        $this->travel(61)->seconds();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->assertSame('succeeded', (new SimulationStatisticsMaintenance)->check()['status']);
        $itemReads = array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'from "sim_items"')));
        $this->assertCount(1, $itemReads);
        $this->assertStringContainsString('limit ', $itemReads[0]);
        $this->assertStringNotContainsString('count(', strtolower($itemReads[0]));
    }

    public function test_later_material_changes_refresh_but_do_not_refresh_every_check(): void
    {
        $this->eligible();
        $this->assertSame('succeeded', (new SimulationStatisticsMaintenance)->check()['status']);
        DB::statement("INSERT INTO boards(id, boardable_type, boardable_id, status)
            SELECT md5('later-board-' || n)::uuid, 'organizations', md5('later-org-' || n)::uuid, 'forming'
            FROM generate_series(1, 6000) n");
        $this->flushStatistics();
        $this->travel(16)->minutes();
        $result = (new SimulationStatisticsMaintenance)->check();
        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('material_board_changes', $result['reason']);
        $this->assertSame(2, DB::table('sim_statistics_attempts')->count());
    }

    public function test_a_real_statement_timeout_is_recorded_then_retried_after_persistent_cooldown(): void
    {
        $this->eligible();
        $service = new class extends SimulationStatisticsMaintenance
        {
            protected function budgets(float $hostGb, int $sharedBuffersBytes): array
            {
                return ['statement_ms' => 10, 'buffer_mb' => 1, 'lock_ms' => 250];
            }

            protected function analyze(Connection $db, array $budgets): void
            {
                $db->selectOne('SELECT pg_sleep(1)');
            }
        };
        $this->assertSame('timeout', $service->check()['status']);
        $this->assertSame('timeout', DB::table('sim_statistics_attempts')->value('status'));
        $this->assertNull($this->state()->last_success_at);
        $this->assertSame(['departments'], $this->distribution());
        $this->assertSame('check_cooldown', (new SimulationStatisticsMaintenance)->check()['reason']);
        $this->travel(6)->minutes();
        $this->assertSame('succeeded', (new SimulationStatisticsMaintenance)->check()['status']);
        $this->assertSame(2, DB::table('sim_statistics_attempts')->count());
    }

    public function test_competing_table_maintenance_defers_without_waiting_or_changing_success_marker(): void
    {
        $this->eligible();
        config(['database.connections.board_blocker' => array_replace(DB::connection()->getConfig(),
            ['name' => 'board_blocker', 'url' => null])]);
        $other = DB::connection('board_blocker');
        $this->assertSame($this->fixture, $other->selectOne('SELECT current_database() AS name')->name);
        try {
            $other->beginTransaction();
            $other->statement('LOCK TABLE boards IN SHARE UPDATE EXCLUSIVE MODE');
            $this->assertSame('deferred', (new SimulationStatisticsMaintenance)->check()['status']);
            $this->assertNull($this->state()->last_success_at);
            $this->assertSame('deferred', DB::table('sim_statistics_attempts')->value('status'));
            $this->assertSame(['departments'], $this->distribution());
        } finally {
            $other->rollBack();
            DB::purge('board_blocker');
        }
        $this->travel(6)->minutes();
        $this->assertSame('succeeded', (new SimulationStatisticsMaintenance)->check()['status']);
    }

    public function test_independent_queued_duplicates_do_not_bypass_ownership_or_restart_cooldowns(): void
    {
        $this->eligible();
        [$owner, $pipes] = $this->startWorker(true);
        $this->assertSame("HOLDING\n", fgets($pipes[1]));
        $this->assertNull($this->state()->last_success_at);
        for ($i = 0; $i < 2; $i++) {
            [$duplicate, $duplicatePipes] = $this->startWorker(false);
            $result = $this->finishWorker($duplicate, $duplicatePipes);
            $this->assertSame('another_owner', $result['result']['reason']);
            $this->assertSame(0, $result['analyzes']);
        }
        fwrite($pipes[0], "CONTINUE\n");
        $out = $this->finishWorker($owner, $pipes);
        $this->assertSame('succeeded', $out['result']['status']);
        $this->assertSame(1, $out['analyzes']);
        [$later, $laterPipes] = $this->startWorker(false);
        $laterOut = $this->finishWorker($later, $laterPipes);
        $this->assertSame('check_cooldown', $laterOut['result']['reason']);
        $this->assertSame(0, $laterOut['analyzes']);
        $this->assertSame(1, DB::table('sim_statistics_attempts')->count());
    }

    public function test_process_death_leaves_an_honest_attempt_and_allows_bounded_recovery(): void
    {
        $this->eligible();
        [$owner, $pipes, $pid] = $this->startWorker(true);
        $this->assertSame("HOLDING\n", fgets($pipes[1]));
        proc_terminate($this->children[$owner][0], 9);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($this->children[$owner][0]);
        unset($this->children[$owner]);
        $until = microtime(true) + 5;
        do {
            DB::selectOne('SELECT pg_stat_clear_snapshot()');
            $alive = DB::table('pg_stat_activity')->where('pid', $pid)->where('datname', $this->fixture)->exists();
            if (! $alive) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $until);
        $this->assertFalse($alive);
        $this->assertSame('running', DB::table('sim_statistics_attempts')->value('status'));
        $this->assertNull($this->state()->last_success_at);
        $this->travel(6)->minutes();
        $this->assertSame('succeeded', (new SimulationStatisticsMaintenance)->check()['status']);
        $this->assertSame(['abandoned', 'succeeded'], DB::table('sim_statistics_attempts')->orderBy('started_at')->pluck('status')->all());
    }

    public function test_lost_session_is_fenced_and_durable_attempt_can_recover(): void
    {
        $this->eligible();
        $service = new class extends SimulationStatisticsMaintenance
        {
            protected function analyze(Connection $db, array $budgets): void
            {
                $db->disconnect();
                $db->selectOne('SELECT 1');
            }
        };
        $this->assertSame('failed', $service->check()['status']);
        $this->assertNull($this->state()->last_success_at);
        $this->travel(6)->minutes();
        $this->assertSame('succeeded', (new SimulationStatisticsMaintenance)->check()['status']);
    }

    public function test_rejects_unreviewed_targets_and_leaves_caller_transaction_intact(): void
    {
        try {
            (new SimulationStatisticsMaintenance)->check('audit_log');
            $this->fail('Expected target rejection');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('Unreviewed', $error->getMessage());
        }
        DB::beginTransaction();
        try {
            (new SimulationStatisticsMaintenance)->check();
            $this->fail('Expected transaction rejection');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('own its transaction', $error->getMessage());
        }
        $this->assertSame(1, DB::transactionLevel());
        DB::rollBack();
        $this->assertSame(0, DB::table('sim_statistics_attempts')->count());
        $job = unserialize(serialize(new MaintainSimulationStatisticsJob));
        $this->assertSame('long-running', $job->queue);
        $this->assertSame(180, $job->timeout);
    }

    public function test_halted_completed_and_unpopulated_phases_cannot_trigger_statistics_work(): void
    {
        $this->eligible();
        DB::table('sim_runs')->update(['halt_requested_at' => now()]);
        $this->assertSame('no_active_civics_run', (new SimulationStatisticsMaintenance)->check()['reason']);
        $this->travel(61)->seconds();
        DB::table('sim_runs')->update(['halt_requested_at' => null, 'phase' => 'stipends']);
        $this->assertSame('no_active_civics_run', (new SimulationStatisticsMaintenance)->check()['reason']);
        $this->assertSame(0, DB::table('sim_statistics_attempts')->count());
    }

    public function test_budgets_scale_without_copying_demo_host_runtime(): void
    {
        $method = new \ReflectionMethod(SimulationStatisticsMaintenance::class, 'budgets');
        $small = $method->invoke(new SimulationStatisticsMaintenance, 2, 128 * 1048576);
        $large = $method->invoke(new SimulationStatisticsMaintenance, 128, 512 * 1048576);
        $this->assertSame(120000, $small['statement_ms']);
        $this->assertSame(30000, $large['statement_ms']);
        $this->assertSame(1, $small['buffer_mb']);
        $this->assertSame(8, $large['buffer_mb']);
        $this->assertSame(250, $small['lock_ms']);
    }

    private function startWorker(bool $hold): array
    {
        $process = proc_open([PHP_BINARY, base_path('tests/Support/simulation_statistics_worker.php')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $key = count($this->children);
        $this->children[$key] = [$process, $pipes];
        fwrite($pipes[0], json_encode(['connection' => DB::connection()->getConfig(),
            'hold' => $hold, 'now' => now()->toIso8601String()], JSON_THROW_ON_ERROR)."\n");
        stream_set_timeout($pipes[1], 15);
        $ready = json_decode(fgets($pipes[1]) ?: '{}', true);
        $this->assertTrue($ready['ready'] ?? false);
        fwrite($pipes[0], "GO\n");

        return [$key, $pipes, $ready['pid']];
    }

    private function finishWorker(int $key, array $pipes): array
    {
        $result = json_decode(fgets($pipes[1]) ?: '{}', true);
        $this->assertTrue($result['done'] ?? false);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($this->children[$key][0]), $errors);
        unset($this->children[$key]);

        return $result;
    }
}
