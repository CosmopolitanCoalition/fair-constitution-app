<?php

namespace Tests\Feature;

use App\Jobs\SimWorkerJob;
use App\Models\SimRun;
use App\Support\SimClaims;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/** Opt-in real PostgreSQL claims; every table lives in a disposable nonce schema. */
class SimClaimPerformanceTest extends TestCase
{
    use LivePgConnection;

    private ?string $schema = null;
    private string $original;
    private string $runId;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_SIM_INDEX_PG_TESTS') !== '1') {
            $this->markTestSkipped('Opt in to isolated PostgreSQL claim fixtures.');
        }
        Cache::flush();
        $this->original = DB::getDefaultConnection();
        $a = $this->livePg('claim_test_a');
        config(['database.connections.claim_test_b' => $a->getConfig()]);
        $this->schema = 'claim_test_'.bin2hex(random_bytes(8));
        $a->statement('CREATE SCHEMA '.$this->schema);
        foreach ([$a, DB::connection('claim_test_b')] as $connection) {
            $connection->statement('SET search_path TO '.$this->schema.', pg_catalog');
            $connection->statement("SET lock_timeout = '2s'");
            $connection->statement("SET statement_timeout = '30s'");
            $this->assertSame($this->schema, $connection->selectOne('SELECT current_schema() AS name')->name);
        }
        DB::setDefaultConnection('claim_test_a');
        DB::statement('CREATE TABLE sim_items (id uuid PRIMARY KEY, run_id uuid NOT NULL, kind text NOT NULL, status text NOT NULL, position integer NOT NULL, claim_token uuid, started_at timestamptz, updated_at timestamptz, finished_at timestamptz, jurisdiction_id uuid, legislature_id uuid, race_id uuid, adm_level smallint, reason text, metrics jsonb)');
        $this->runId = (string) Str::uuid();
        DB::statement("INSERT INTO sim_items (id,run_id,kind,status,position,adm_level) SELECT md5(g::text)::uuid, ?::uuid, 'election_scope','pending',93,6 FROM generate_series(1,20000) g", [$this->runId]);
        DB::statement('CREATE INDEX sim_items_claim_idx ON sim_items(run_id,kind,status,position)');
        DB::statement('ANALYZE sim_items'); // isolated 20,000-row fixture only
    }

    protected function tearDown(): void
    {
        if ($this->schema !== null) {
            foreach (['claim_test_a', 'claim_test_b'] as $name) {
                $connection = DB::connection($name);
                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            }
            if (! preg_match('/^claim_test_[a-f0-9]{16}$/D', $this->schema)) {
                throw new \LogicException('Unexpected fixture schema.');
            }
            DB::connection('claim_test_a')->statement('DROP SCHEMA '.$this->schema.' CASCADE');
            DB::setDefaultConnection($this->original);
            DB::purge('claim_test_a'); DB::purge('claim_test_b');
        }
        parent::tearDown();
    }

    private function runModel(): SimRun
    {
        return (new SimRun)->forceFill(['id' => $this->runId, 'status' => 'running', 'phase' => 'elections']);
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_09_19_200000_index_sim_claim_order.php');
    }

    private function plan(): string
    {
        // Capture the real full UPDATE, not just a SELECT resembling its subquery.
        $queries = DB::pretend(fn () => SimClaims::next($this->runModel(), (string) Str::uuid()));
        $this->assertCount(1, $queries);
        $query = $queries[0];
        $bindings = str_contains($query['query'], '?') ? $query['bindings'] : [];

        return json_encode(DB::select('EXPLAIN (FORMAT JSON) '.$query['query'], $bindings));
    }

    public function test_the_full_claim_plan_loses_its_sort_and_the_migration_is_rerunnable(): void
    {
        $this->assertStringContainsString('Sort', $this->plan());
        $migration = $this->migration();
        $this->assertFalse($migration->withinTransaction);
        $migration->up();
        $before = DB::selectOne("SELECT indexrelid, indisvalid FROM pg_index WHERE indexrelid = 'sim_items_claim_order_idx'::regclass");
        $this->assertTrue($before->indisvalid);
        $migration->up();
        $this->assertSame($before->indexrelid, DB::selectOne("SELECT 'sim_items_claim_order_idx'::regclass::oid AS id")->id);
        $plan = $this->plan();
        $this->assertStringContainsString('sim_items_claim_order_idx', $plan);
        $this->assertStringNotContainsString('Sort', $plan);
        $migration->down();
        $this->assertNull(DB::selectOne("SELECT to_regclass('sim_items_claim_order_idx') AS id")->id);
        $this->assertNotNull(DB::selectOne("SELECT to_regclass('sim_items_claim_idx') AS id")->id);
    }

    public function test_an_interrupted_build_is_replaced_with_a_valid_index(): void
    {
        try {
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY sim_items_claim_order_idx ON sim_items (position)');
            $this->fail('Duplicate fixture positions must reject this unique index.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertFalse(DB::selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid = 'sim_items_claim_order_idx'::regclass")->indisvalid);
        }
        $this->migration()->up();
        $this->assertTrue(DB::selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid = 'sim_items_claim_order_idx'::regclass")->indisvalid);
    }

    public function test_overlapping_transactions_skip_locked_claims_and_keep_order_and_token_ownership(): void
    {
        $this->migration()->up();
        $expected = DB::table('sim_items')->orderBy('position')->orderBy('id')->limit(20)->pluck('id')->all();
        $a = DB::connection('claim_test_a'); $b = DB::connection('claim_test_b');
        $a->beginTransaction(); $b->beginTransaction();
        $claimed = [];
        for ($i = 0; $i < 20; $i++) {
            DB::setDefaultConnection($i % 2 === 0 ? 'claim_test_a' : 'claim_test_b');
            $token = (string) Str::uuid();
            $item = SimClaims::next($this->runModel(), $token);
            $this->assertNotNull($item);
            $this->assertNotContains($item->id, $claimed);
            $claimed[] = $item->id;
            SimClaims::release($item->id, (string) Str::uuid());
            $this->assertSame($token, DB::table('sim_items')->where('id', $item->id)->value('claim_token'));
        }
        $this->assertSame($expected, $claimed);
        $a->rollBack(); $b->rollBack();
        DB::setDefaultConnection('claim_test_a');
        $run = $this->runModel();
        $run->halt_requested_at = now();
        $this->assertNull(SimClaims::next($run, (string) Str::uuid()));
        $run->halt_requested_at = null; $run->paused_until = now()->addMinute();
        $this->assertNull(SimClaims::next($run, (string) Str::uuid()));
    }

    public function test_worker_reports_acquisition_execution_and_no_work_before_leaving(): void
    {
        $this->exerciseWorker(true);
    }

    public function test_worker_still_runs_before_the_activity_migration_is_applied(): void
    {
        $this->exerciseWorker(false);
    }

    private function exerciseWorker(bool $migrated): void
    {
        DB::table('sim_items')->where('run_id', $this->runId)->delete();
        DB::statement("CREATE TABLE sim_runs (id uuid PRIMARY KEY, status text, phase text, options jsonb DEFAULT '{}', halt_requested_at timestamptz, paused_until timestamptz, created_at timestamptz, updated_at timestamptz)");
        DB::table('sim_runs')->insert(['id' => $this->runId, 'status' => 'running', 'phase' => 'cohorts']);
        DB::statement('CREATE TABLE sim_worker_leases (id uuid PRIMARY KEY, run_id uuid, started_at timestamptz, last_seen_at timestamptz, claim_type varchar(16), claim_label text, claim_started_at timestamptz, lane text)');
        DB::statement('CREATE TABLE sim_timings (run_id uuid, part text, count bigint, total_us bigint, max_us bigint, updated_at timestamptz, PRIMARY KEY(run_id,part))');
        DB::statement('CREATE TABLE jurisdictions (id uuid, name text, adm_level smallint, population bigint, official_languages jsonb, timezone text, deleted_at timestamptz)');
        if ($migrated) {
            (require base_path('database/migrations/2026_09_19_201000_sim_worker_activity.php'))->up();
        }
        // Missing synthetic jurisdictions deliberately exercise the real error/
        // settlement path without invoking any external service or world data.
        for ($i = 0; $i < 2; $i++) {
            DB::table('sim_items')->insert(['id' => (string) Str::uuid(), 'run_id' => $this->runId,
                'kind' => 'cohort_scope', 'status' => 'pending', 'position' => $i,
                'jurisdiction_id' => (string) Str::uuid()]);
        }
        Artisan::shouldReceive('call')->once()->with('sim:pump')->andReturn(0);
        $states = []; $claimStates = []; $observing = true;
        DB::listen(function ($query) use (&$states, &$claimStates, &$observing) {
            if (! $observing || $query->connectionName !== 'claim_test_a') return;
            if (preg_match('/^(insert|update).*sim_worker_leases/is', $query->sql)) {
                $lease = DB::table('sim_worker_leases')->first();
                if ($lease) $states[] = $lease->activity ?? 'legacy';
            }
            if (str_starts_with($query->sql, 'UPDATE sim_items s')) {
                $claimStates[] = DB::table('sim_worker_leases')->first()->activity ?? 'legacy';
            }
        });
        $signal = function_exists('pcntl_signal_get_handler') ? pcntl_signal_get_handler(SIGTERM) : null;
        try {
            (new SimWorkerJob($this->runId))->handle();
        } finally {
            $observing = false;
            if ($signal !== null) pcntl_signal(SIGTERM, $signal);
        }
        $this->assertSame(2, DB::table('sim_items')->where('status', 'review')->count());
        $this->assertSame(0, DB::table('sim_worker_leases')->count(), 'a no-work worker exits; it does not idle');
        $this->assertCount(3, $claimStates);
        $this->assertSame(array_fill(0, 3, $migrated ? 'acquiring' : 'legacy'), $claimStates);
        if ($migrated) {
            $this->assertSame(['acquiring', 'acquiring', 'executing', 'acquiring', 'executing', 'acquiring', 'waiting'], $states);
        }
    }
}
