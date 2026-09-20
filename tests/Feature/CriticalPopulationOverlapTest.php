<?php

namespace Tests\Feature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Jobs\Clocks\EvaluateCriticalPopulationJob;
use App\Models\{AuditEntry, Jurisdiction, JurisdictionActivation};
use App\Services\{ActivationService, AuditService, ElectionLifecycleService, InitialDistrictMapService, InstitutionStubService, SettingsResolver};
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Execution guard and unchanged CLK-06 evaluation in disposable PostgreSQL. */
class CriticalPopulationOverlapTest extends TestCase
{
    private ?string $fixture = null;
    private string $original;
    private array $children = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_SIM_INDEX_PG_TESTS') !== '1') {
            $this->markTestSkipped('Opt in to disposable population-sweep tests.');
        }
        $this->original = DB::getDefaultConnection();
        config(['database.connections.population_admin' => array_replace(config('database.connections.pgsql'),
            ['database' => 'postgres', 'name' => 'population_admin', 'url' => null])]);
        $admin = DB::connection('population_admin');
        $this->assertSame('postgres', $admin->selectOne('SELECT current_database() AS name')->name);
        $this->fixture = 'population_test_'.bin2hex(random_bytes(8));
        $admin->statement('CREATE DATABASE '.$this->fixture.' TEMPLATE template0');
        config(['database.connections.population_test' => array_replace($admin->getConfig(),
            ['database' => $this->fixture, 'name' => 'population_test', 'url' => null])]);
        DB::setDefaultConnection('population_test');
        $this->assertSame($this->fixture, DB::selectOne('SELECT current_database() AS name')->name);
        foreach ([new Jurisdiction, new JurisdictionActivation] as $model) {
            $this->assertSame('population_test', $model->getConnection()->getName());
            $this->assertSame($this->fixture, $model->getConnection()->selectOne('SELECT current_database() AS name')->name);
        }
        DB::statement("SET statement_timeout = '15s'");
        DB::statement('CREATE TABLE jurisdictions (id uuid PRIMARY KEY, parent_id uuid, name text, slug text, population bigint, deleted_at timestamptz)');
        DB::statement('CREATE TABLE residency_confirmations (jurisdiction_id uuid, user_id uuid, is_active boolean)');
        DB::statement('CREATE INDEX residency_active_jurisdiction_user_idx ON residency_confirmations(jurisdiction_id, user_id) WHERE is_active');
        DB::statement('CREATE TABLE jurisdiction_activations (id uuid PRIMARY KEY, jurisdiction_id uuid, state text, critical_population_at timestamptz, notes jsonb, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz)');
        DB::statement('CREATE TABLE legislatures (id uuid PRIMARY KEY, jurisdiction_id uuid, deleted_at timestamptz)');
        DB::statement('CREATE TABLE legislature_members (legislature_id uuid, status text, deleted_at timestamptz)');
        DB::statement('CREATE TABLE constitutional_settings (jurisdiction_id uuid PRIMARY KEY, critical_population_threshold integer)');
        DB::statement('CREATE TABLE fixture_boots (jurisdiction_id uuid, schedule boolean)');
    }

    protected function tearDown(): void
    {
        foreach ($this->children as [$process, $pipes]) {
            if (is_resource($process)) { proc_terminate($process, 9); }
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            if (is_resource($process)) { proc_close($process); }
        }
        if ($this->fixture !== null) {
            while (DB::transactionLevel() > 0) { DB::rollBack(); }
            DB::setDefaultConnection($this->original);
            DB::purge('population_test');
            if (! preg_match('/^population_test_[a-f0-9]{16}$/D', $this->fixture)) { throw new \LogicException('Unexpected fixture.'); }
            DB::connection('population_admin')->statement('DROP DATABASE '.$this->fixture.' WITH (FORCE)');
            DB::purge('population_admin');
        }
        parent::tearDown();
    }

    private function id(int $n): string { return sprintf('06000000-0000-4000-8000-%012d', $n); }

    private function place(int $n, int $residents = 2, bool $active = true, bool $deleted = false): void
    {
        DB::table('jurisdictions')->insert(['id' => $this->id($n), 'name' => 'Fixture', 'slug' => 'fixture-'.$n,
            'population' => 100, 'deleted_at' => $deleted ? now() : null]);
        DB::table('constitutional_settings')->insert(['jurisdiction_id' => $this->id($n), 'critical_population_threshold' => 2]);
        for ($i = 0; $i < $residents; $i++) {
            DB::table('residency_confirmations')->insert(['jurisdiction_id' => $this->id($n), 'user_id' => $this->id(1000 + $i), 'is_active' => $active]);
        }
    }

    private function activation(): ActivationService
    {
        $audit = $this->createMock(AuditService::class);
        $audit->method('append')->willReturn(new AuditEntry);
        return new class($audit, $this->createMock(InstitutionStubService::class), $this->createMock(InitialDistrictMapService::class),
            $this->createMock(ElectionLifecycleService::class), $this->createMock(ConstitutionalEngine::class)) extends ActivationService {
            public function activate(Jurisdiction $jurisdiction, bool $scheduleElection = true): JurisdictionActivation
            {
                DB::table('fixture_boots')->insert(['jurisdiction_id' => $jurisdiction->id, 'schedule' => $scheduleElection]);
                return JurisdictionActivation::where('jurisdiction_id', $jurisdiction->id)->firstOrFail();
            }
        };
    }

    private function runSweep(): void { (new EvaluateCriticalPopulationJob)->handle($this->activation(), new SettingsResolver); }

    public function test_filters_crossings_and_future_periodic_evaluation_remain_unchanged(): void
    {
        foreach ([1, 3, 4, 5, 6, 7, 10] as $n) { $this->place($n); }
        $this->place(2, deleted: true); $this->place(8, active: false); $this->place(9, 1);
        foreach ([3, 6, 7] as $n) {
            DB::table('legislatures')->insert(['id' => $this->id(100 + $n), 'jurisdiction_id' => $this->id($n), 'deleted_at' => $n === 7 ? now() : null]);
            DB::table('legislature_members')->insert(['legislature_id' => $this->id(100 + $n), 'status' => 'vacated', 'deleted_at' => $n === 6 ? now() : null]);
        }
        foreach ([4, 5, 10] as $n) {
            DB::table('jurisdiction_activations')->insert(['id' => $this->id(200 + $n), 'jurisdiction_id' => $this->id($n),
                'state' => $n === 5 ? 'boundary_loaded' : 'critical_population', 'deleted_at' => $n === 10 ? now() : null]);
        }
        $this->runSweep();
        $this->assertSame(array_map($this->id(...), [1, 5, 6, 7, 10]), DB::table('fixture_boots')->orderBy('jurisdiction_id')->pluck('jurisdiction_id')->all());
        $this->assertSame(0, DB::table('fixture_boots')->where('schedule', false)->count());
        $this->runSweep();
        $this->assertSame(5, DB::table('fixture_boots')->count());
        DB::table('residency_confirmations')->insert(['jurisdiction_id' => $this->id(9), 'user_id' => $this->id(999), 'is_active' => true]);
        $this->runSweep();
        $this->assertSame(6, DB::table('fixture_boots')->count());
        $this->assertSame(1, DB::table('fixture_boots')->where('jurisdiction_id', $this->id(9))->count());
    }

    private function startWorker(bool $hold, bool $legacy = false): array
    {
        $process = proc_open([PHP_BINARY, base_path('tests/Support/critical_population_worker.php')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $key = count($this->children); $this->children[$key] = [$process, $pipes];
        fwrite($pipes[0], json_encode(['connection' => DB::connection()->getConfig(), 'hold' => $hold, 'legacy' => $legacy], JSON_THROW_ON_ERROR)."\n");
        stream_set_timeout($pipes[1], 10);
        $ready = json_decode(fgets($pipes[1]) ?: '{}', true);
        $this->assertTrue($ready['ready'] ?? false);
        fwrite($pipes[0], "GO\n");
        return [$key, $ready['pid'], $pipes];
    }

    private function finishWorker(int $key, array $pipes): array
    {
        $result = json_decode(fgets($pipes[1]) ?: '{}', true);
        $this->assertTrue($result['done'] ?? false);
        fclose($pipes[0]); fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $this->assertSame(0, proc_close($this->children[$key][0]), $errors);
        unset($this->children[$key]);
        return $result;
    }

    public function test_independent_workers_drop_queued_duplicates_without_scan_or_retry(): void
    {
        $this->place(1);
        [$owner, , $ownerPipes] = $this->startWorker(true);
        $this->assertSame("HOLDING\n", fgets($ownerPipes[1]));
        for ($i = 0; $i < 3; $i++) {
            [$duplicate, , $pipes] = $this->startWorker(false, legacy: true);
            $out = $this->finishWorker($duplicate, $pipes);
            $this->assertSame(0, $out['scans']); $this->assertSame(0, $out['crossings']);
        }
        fwrite($ownerPipes[0], "CONTINUE\n");
        $out = $this->finishWorker($owner, $ownerPipes);
        $this->assertSame(1, $out['scans']); $this->assertSame(1, $out['crossings']);
        [$later, , $pipes] = $this->startWorker(false);
        $out = $this->finishWorker($later, $pipes);
        $this->assertSame(1, $out['scans']); $this->assertSame(1, $out['crossings']);
    }

    public function test_process_death_releases_guard_without_a_cache_expiry(): void
    {
        $this->place(1);
        [$owner, $pid, $pipes] = $this->startWorker(true);
        $this->assertSame("HOLDING\n", fgets($pipes[1]));
        proc_terminate($this->children[$owner][0], 9);
        foreach ($pipes as $pipe) { fclose($pipe); }
        proc_close($this->children[$owner][0]); unset($this->children[$owner]);
        $until = microtime(true) + 5;
        do {
            DB::selectOne('SELECT pg_stat_clear_snapshot()');
            $alive = DB::table('pg_stat_activity')->where('pid', $pid)->where('datname', $this->fixture)->exists();
            if (! $alive) { break; }
            usleep(10000);
        } while (microtime(true) < $until);
        $this->assertFalse($alive);
        $this->runSweep();
        $this->assertSame(1, DB::table('fixture_boots')->count());
    }

    public function test_exception_releases_guard_and_preserves_original_error(): void
    {
        $this->place(1);
        $activation = $this->createMock(ActivationService::class);
        $activation->method('thresholdFor')->willThrowException(new \RuntimeException('fixture failure'));
        try { (new EvaluateCriticalPopulationJob)->handle($activation, new SettingsResolver); $this->fail('Expected failure'); }
        catch (\RuntimeException $error) { $this->assertSame('fixture failure', $error->getMessage()); }
        $this->runSweep();
        $this->assertSame(1, DB::table('fixture_boots')->count());
    }

    public function test_lost_connection_cannot_resume_without_guard_and_next_job_recovers(): void
    {
        $this->place(1);
        $activation = $this->createMock(ActivationService::class);
        $activation->method('thresholdFor')->willReturnCallback(function () {
            // Simulate loss before the next operation; reconnect must be fenced.
            DB::disconnect('population_test');
            DB::selectOne('SELECT 1');
            return 1;
        });
        $activation->expects($this->never())->method('onCriticalPopulation');
        try { (new EvaluateCriticalPopulationJob)->handle($activation, new SettingsResolver); $this->fail('Expected lost-session failure'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('lost its guarded database session', $error->getMessage()); }
        $this->runSweep();
        $this->assertSame(1, DB::table('fixture_boots')->count());
    }

    public function test_new_jobs_use_existing_long_lane_without_a_short_timeout(): void
    {
        $job = unserialize(serialize(new EvaluateCriticalPopulationJob));
        $this->assertSame('long-running', $job->queue);
        $this->assertSame(0, $job->timeout);
        $this->assertSame(1, $job->tries);
        $this->assertSame('redis-long', config('horizon.defaults.supervisor-long-running.connection'));
    }

    public function test_direct_invocation_does_not_discard_a_callers_transaction(): void
    {
        DB::beginTransaction();
        $this->place(1);
        try { $this->runSweep(); $this->fail('A queued sweep cannot own a caller transaction.'); }
        catch (\LogicException $error) { $this->assertStringContainsString('outside a transaction', $error->getMessage()); }
        $this->assertSame(1, DB::transactionLevel());
        $this->assertSame(1, DB::table('jurisdictions')->count());
        DB::rollBack();
        $this->assertSame(0, DB::table('jurisdictions')->count());
        $this->place(1);
        $this->runSweep();
        $this->assertSame(1, DB::table('fixture_boots')->count());
    }
}
