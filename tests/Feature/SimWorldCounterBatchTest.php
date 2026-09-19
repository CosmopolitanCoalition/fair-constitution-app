<?php

namespace Tests\Feature;

use App\Jobs\SimWorkerJob;
use App\Models\SimRun;
use App\Support\SimWorldCounters;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** All records and concurrent transactions live in a dedicated disposable DB. */
class SimWorldCounterBatchTest extends TestCase
{
    private ?string $fixture = null;
    private string $original;
    private SimRun $run;
    private bool $failMerge = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_SIM_INDEX_PG_TESTS') !== '1') { $this->markTestSkipped('Opt in to isolated counter fixtures.'); }
        $this->original = DB::getDefaultConnection();
        config(['database.connections.counter_admin' => array_replace(config('database.connections.pgsql'),
            ['database' => 'postgres', 'name' => 'counter_admin', 'url' => null])]);
        $admin = DB::connection('counter_admin');
        $this->assertSame('postgres', $admin->selectOne('SELECT current_database() AS name')->name);
        $this->fixture = 'counter_test_'.bin2hex(random_bytes(8));
        $admin->statement('CREATE DATABASE '.$this->fixture.' TEMPLATE template0');
        foreach (['counter_a', 'counter_b'] as $name) {
            config(['database.connections.'.$name => array_replace($admin->getConfig(), ['database' => $this->fixture, 'name' => $name])]);
            $this->assertSame($this->fixture, DB::connection($name)->selectOne('SELECT current_database() AS name')->name);
            DB::connection($name)->statement("SET lock_timeout = '1s'");
            DB::connection($name)->statement("SET statement_timeout = '10s'");
        }
        DB::setDefaultConnection('counter_a');
        $this->assertSame('counter_a', (new SimRun)->getConnection()->getName());
        $this->assertSame($this->fixture, (new SimRun)->getConnection()->selectOne('SELECT current_database() AS name')->name);
        DB::statement('CREATE TABLE sim_runs (id uuid PRIMARY KEY, created_at timestamptz, updated_at timestamptz)');
        foreach (SimWorldCounters::COLUMNS as $column) { DB::statement("ALTER TABLE sim_runs ADD COLUMN {$column} bigint NOT NULL DEFAULT 0"); }
        DB::statement('CREATE TABLE sim_items (id uuid PRIMARY KEY, status text, claim_token uuid, metrics jsonb, reason text, finished_at timestamptz, updated_at timestamptz, race_id uuid)');
        $this->migration()->up();
        $this->run = SimRun::create(['id' => (string) Str::uuid(), 'chambers_governed' => 100]);
    }

    protected function tearDown(): void
    {
        $this->failMerge = false;
        if ($this->fixture !== null) {
            foreach (['counter_a', 'counter_b'] as $name) {
                while (DB::connection($name)->transactionLevel() > 0) { DB::connection($name)->rollBack(); }
                DB::purge($name);
            }
            DB::setDefaultConnection($this->original);
            if (! preg_match('/^counter_test_[a-f0-9]{16}$/D', $this->fixture)) { throw new \LogicException('Unexpected test database.'); }
            DB::connection('counter_admin')->statement('DROP DATABASE '.$this->fixture.' WITH (FORCE)');
            DB::purge('counter_admin');
        }
        parent::tearDown();
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_09_20_003000_create_sim_world_counter_deltas.php');
    }

    private function settle(string $token, string $kind = 'seat_scope', array $metrics = ['certified' => true], bool $batches = true, string $verdict = 'done'): string
    {
        $job = new SimWorkerJob((string) $this->run->id);
        (new \ReflectionProperty($job, 'batchesCounters'))->setValue($job, $batches);
        $item = (object) ['id' => (string) Str::uuid(), 'kind' => $kind];
        DB::table('sim_items')->insert(['id' => $item->id, 'status' => 'running', 'claim_token' => $token]);
        (new \ReflectionMethod($job, 'settleWithCounters'))->invoke($job, $this->run, $item, $verdict, $metrics, null, $token);
        return $item->id;
    }

    public function test_fifty_settlements_merge_with_two_shared_row_updates_and_preserve_the_baseline(): void
    {
        $a = (string) Str::uuid(); $b = (string) Str::uuid();
        DB::enableQueryLog(); DB::flushQueryLog();
        for ($i = 0; $i < 25; $i++) { $this->settle($a); $this->settle($b); }
        $runWrites = fn () => count(array_filter(DB::getQueryLog(), fn ($q) => str_starts_with($q['query'], 'update "sim_runs"')));
        $this->assertSame(0, $runWrites());
        $this->assertSame(100, (int) $this->run->fresh()->chambers_governed);
        $this->assertSame(50, DB::table('sim_items')->where('status', 'done')->count());
        $this->assertSame(1, SimWorldCounters::flush($this->run->id, $a));
        $this->assertSame(1, SimWorldCounters::flush($this->run->id, $b));
        $this->assertSame(0, SimWorldCounters::flush($this->run->id, $a));
        $this->assertSame(150, (int) $this->run->fresh()->chambers_governed);
        $this->assertSame(2, $runWrites());
    }

    public function test_all_counter_types_and_review_exclusions_are_preserved(): void
    {
        $token = (string) Str::uuid();
        $this->settle($token, 'identity_batch', ['users' => 4, 'confirmations' => 20]);
        $this->settle($token, 'identity_batch', ['inactive' => \App\Services\Demo\Stages\IdentityStage::INACTIVE_ZERO_POPULATION]);
        $this->settle($token, 'election_scope', ['inactive' => \App\Services\Demo\Stages\IdentityStage::INACTIVE_TOO_FEW_RESIDENTS]);
        $this->settle($token, 'cohort_scope', []);
        $this->settle($token, 'seat_scope', ['certified' => false]);
        $this->settle($token, 'seat_scope', ['certified' => true], verdict: 'review');
        $this->assertSame(1, SimWorldCounters::flush($this->run->id)); // no lease exists: orphan recovery
        $row = $this->run->fresh();
        foreach (['people_founded' => 4, 'residencies_founded' => 20, 'cohorts' => 1, 'chambers_governed' => 100,
            'places_zero_population' => 1, 'places_too_few_residents' => 1] as $column => $value) {
            $this->assertSame($value, (int) $row->$column);
        }
    }

    public function test_failure_between_done_and_delta_rolls_back_both(): void
    {
        DB::statement("ALTER TABLE sim_world_counter_deltas ADD CONSTRAINT reject_fixture_delta CHECK (chambers_governed = 0)");
        try { $this->settle((string) Str::uuid()); $this->fail('Delta constraint must reject settlement.'); }
        catch (\Illuminate\Database\QueryException $e) { $this->assertStringContainsString('reject_fixture_delta', $e->getMessage()); }
        $this->assertSame('running', DB::table('sim_items')->value('status'));
        $this->assertSame(0, DB::table('sim_world_counter_deltas')->count());
    }

    public function test_failed_merge_rolls_back_increment_and_retry_counts_once(): void
    {
        $token = (string) Str::uuid(); $this->settle($token);
        $this->failMerge = true;
        DB::listen(function ($query) {
            if ($this->failMerge && str_starts_with($query->sql, 'delete from "sim_world_counter_deltas"')) {
                $this->failMerge = false;
                throw new \RuntimeException('fixture merge interrupted');
            }
        });
        try { SimWorldCounters::flush($this->run->id); $this->fail('Fixture must interrupt merge.'); }
        catch (\RuntimeException $e) { $this->assertSame('fixture merge interrupted', $e->getMessage()); }
        $this->assertSame(100, (int) $this->run->fresh()->chambers_governed);
        $this->assertSame(1, DB::table('sim_world_counter_deltas')->count());
        SimWorldCounters::flush($this->run->id);
        $this->assertSame(101, (int) $this->run->fresh()->chambers_governed);
    }

    public function test_settlement_does_not_wait_on_the_shared_counter_and_mergers_skip_locked_rows(): void
    {
        $a = DB::connection('counter_a'); $b = DB::connection('counter_b');
        $token = (string) Str::uuid();
        $a->beginTransaction();
        $a->table('sim_runs')->where('id', $this->run->id)->increment('cohorts');
        DB::setDefaultConnection('counter_b');
        $this->settle($token); // would time out if it still updated the run row
        $this->assertSame(1, $b->table('sim_world_counter_deltas')->count());
        $a->rollBack();
        $a->beginTransaction();
        $a->table('sim_world_counter_deltas')->where('worker_token', $token)->lockForUpdate()->first();
        $this->assertSame(0, SimWorldCounters::flush($this->run->id));
        $a->rollBack();
        $this->assertSame(1, SimWorldCounters::flush($this->run->id));
        $this->assertSame(101, (int) $this->run->fresh()->chambers_governed);
    }

    public function test_legacy_fallback_and_migration_rollback_preserve_counts(): void
    {
        $this->settle((string) Str::uuid(), batches: false);
        $this->assertSame(101, (int) $this->run->fresh()->chambers_governed);
        $this->settle((string) Str::uuid());
        try { $this->migration()->down(); $this->fail('Pending deltas must block rollback.'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('Merge pending', $e->getMessage()); }
        SimWorldCounters::flush($this->run->id);
        $this->migration()->down();
        $this->assertFalse(SimWorldCounters::available());
        $this->assertSame(102, (int) $this->run->fresh()->chambers_governed);
    }
}
