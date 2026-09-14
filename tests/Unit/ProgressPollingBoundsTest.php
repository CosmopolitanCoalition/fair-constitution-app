<?php

namespace Tests\Unit;

use App\Http\Controllers\SetupController;
use App\Jobs\Organizations\EvaluateCoDeterminationJob;
use App\Jobs\Setup\WarmSetupRollupJob;
use App\Services\Demo\SimSnapshot;
use App\Services\Setup\SetupProgressRollup;
use App\Support\AutoscaleEnumeration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * G3 — bound the setup progress polls, the SimSnapshot world figures, the
 * co-determination backstop clock, and the ordering-key geometry derive.
 *
 * Runs on the phpunit sqlite connection with a minimal schema built in setUp:
 * only the tables the readers touch, no PostGIS, no live box. The live world on
 * box E is never read or written.
 *
 * Pins:
 *   (a) a warmed rollup serves each poll handler from CACHE — zero statements
 *       against jurisdictions / legislatures / provision_ledger / users;
 *   (b) the refresher computes the rollup and the handler then serves its
 *       values plus the snapshot_at / snapshot_stale / snapshot_state stamp;
 *   (c) a cold miss returns 'computing' with NO scan and queues one warm;
 *   (d) SimSnapshot world figures come from the run-row counters — no LIKE scan;
 *   (e) the co-determination backstop threshold follows the demo clock;
 *   (f) the ordering-key derive resumes from its phase marker.
 */
class ProgressPollingBoundsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->buildSchema();
    }

    // ─── (a)+(b): the Step 4 poll serves from a warmed rollup, no scan ───────

    public function test_step4_poll_serves_from_the_warmed_rollup_and_never_scans(): void
    {
        $this->seedLedger();
        DB::table('legislatures')->insert([
            ['id' => (string) Str::uuid(), 'deleted_at' => null],
            ['id' => (string) Str::uuid(), 'deleted_at' => null],
        ]);

        // The refresher (scheduler / warm job) computes; this is where the scan
        // lives now. The poll below must not repeat it.
        app(SetupProgressRollup::class)->refresh('step4');

        $log = $this->pollQueryLog(fn () => $this->invokePrivate('step4ProgressPayload'));

        $this->assertNoForbiddenScan($log);
        $payload = $log['result'];
        $this->assertSame(3, $payload['ledger']['total']);
        $this->assertSame(2, $payload['total_legislatures']);
        $this->assertNotEmpty($payload['layers']);
        $this->assertSame('ready', $payload['snapshot_state']);
        $this->assertFalse($payload['snapshot_stale']);
        $this->assertNotNull($payload['snapshot_at']);
    }

    public function test_step4_summary_poll_serves_from_the_warmed_rollup_and_never_scans(): void
    {
        DB::table('legislatures')->insert([['id' => (string) Str::uuid(), 'deleted_at' => null]]);
        DB::table('legislature_districts')->insert([['id' => (string) Str::uuid(), 'deleted_at' => null]]);

        app(SetupProgressRollup::class)->refresh('summary');

        $log = $this->pollQueryLog(fn () => $this->invokePrivate('buildStep4Summary'));

        $this->assertNoForbiddenScan($log);
        $this->assertSame(1, $log['result']['legislatures']);
        $this->assertSame(1, $log['result']['districts']);
        $this->assertSame('ready', $log['result']['snapshot_state']);
        $this->assertArrayHasKey('snapshot_at', $log['result']);
    }

    public function test_jurisdictions_poll_serves_from_the_warmed_rollup_and_never_scans(): void
    {
        DB::table('jurisdictions')->insert([
            ['id' => (string) Str::uuid(), 'adm_level' => 0, 'population' => 100, 'deleted_at' => null,
             'name' => 'Earth', 'slug' => 'earth'],
            ['id' => (string) Str::uuid(), 'adm_level' => 1, 'population' => 50, 'deleted_at' => null,
             'name' => 'A', 'slug' => 'a'],
        ]);

        app(SetupProgressRollup::class)->refresh('jurisdictions');

        $log = $this->pollQueryLog(fn () => $this->invokePrivate('jurisdictionsCounts'));

        $this->assertNoForbiddenScan($log);
        $this->assertSame(1, $log['result']['adm0']);
        $this->assertSame(1, $log['result']['adm1']);
        $this->assertSame(2, $log['result']['total']);
        $this->assertSame('ready', $log['result']['snapshot_state']);
    }

    // ─── (c): a cold miss returns 'computing' with no scan and queues a warm ─

    public function test_cold_miss_returns_computing_without_scanning_and_queues_one_warm(): void
    {
        Queue::fake();

        $out = app(SetupProgressRollup::class)->readOrWarm('jurisdictions');

        $this->assertSame('computing', $out['state']);
        $this->assertNull($out['values']);
        $this->assertTrue($out['stale']);
        Queue::assertPushed(WarmSetupRollupJob::class, 1);

        // A burst of pollers still queues exactly one warm.
        app(SetupProgressRollup::class)->readOrWarm('jurisdictions');
        Queue::assertPushed(WarmSetupRollupJob::class, 1);
    }

    public function test_refresher_only_warms_kinds_with_a_live_viewer(): void
    {
        DB::table('legislatures')->insert([['id' => (string) Str::uuid(), 'deleted_at' => null]]);
        $rollups = app(SetupProgressRollup::class);

        // No viewer: refreshWatched writes nothing.
        $rollups->refreshWatched();
        $this->assertSame('computing', $rollups->read('summary')['state']);

        // Mark a viewer, then the refresher warms it.
        $rollups->markViewer('summary');
        $rollups->refreshWatched();
        $this->assertSame('ready', $rollups->read('summary')['state']);
        $this->assertSame(1, $rollups->read('summary')['values']['legislatures']);
    }

    // ─── (d): SimSnapshot world — counters + the warmed scope, no scan ───────

    public function test_sim_world_serves_counters_and_the_warmed_scope_without_scanning(): void
    {
        $runId = (string) Str::uuid();
        DB::table('sim_runs')->insert([
            'id' => $runId, 'status' => 'running', 'phase' => 'seating',
            'people_founded' => 4242, 'residencies_founded' => 9000,
            'cohorts' => 17, 'chambers_governed' => 5,
            'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        ]);

        // Seed the world and warm the 'world' scope snapshot OFF the poll path —
        // this is the ONLY place the four whole-table reads run now (ruling A).
        for ($i = 0; $i < 20; $i++) {
            DB::table('legislatures')->insert(['id' => (string) Str::uuid(), 'deleted_at' => null]);
        }
        for ($i = 0; $i < 30; $i++) {
            DB::table('jurisdictions')->insert([
                'id' => (string) Str::uuid(), 'adm_level' => 2, 'deleted_at' => null,
                'name' => "j{$i}", 'slug' => "j{$i}",
            ]);
        }
        DB::table('jurisdiction_cohorts')->insert([
            'id' => (string) Str::uuid(), 'population' => 1000, 'electorate' => 700,
        ]);
        app(SetupProgressRollup::class)->refresh('world');

        $log = $this->pollQueryLog(fn () => app(SimSnapshot::class)->world(true));

        // Ruling A: the poll runs NO whole-table scan (legislatures /
        // jurisdictions / users / provision_ledger) and NO cohort sum, and no
        // LIKE. Only the run-row read is allowed.
        $this->assertNoForbiddenScan($log);
        foreach ($log['queries'] as $q) {
            $sql = strtolower($q['query']);
            $this->assertStringNotContainsString('like', $sql,
                'world() must not run any LIKE scan: '.$q['query']);
            $this->assertStringNotContainsString('"jurisdiction_cohorts"', $sql,
                'world() must not scan jurisdiction_cohorts on the poll: '.$q['query']);
        }

        $world = $log['result'];
        $this->assertSame(4242, $world['people']);
        $this->assertSame(9000, $world['residencies']);
        $this->assertSame(17, $world['cohorts']);
        $this->assertSame(5, $world['chambers_governed']);
        $this->assertSame(20, $world['chambers']);
        $this->assertSame(15, $world['chambers_awaiting_election']);
        $this->assertSame(30, $world['jurisdictions']);
        $this->assertSame(1000, $world['population_modelled']);
        $this->assertSame(700, $world['electorate_modelled']);
        $this->assertSame('ready', $world['scope_snapshot_state']);
        $this->assertFalse($world['scope_snapshot_stale']);
        $this->assertNotNull($world['scope_snapshot_at']);
    }

    public function test_sim_world_cold_scope_miss_serves_computing_without_scanning_and_queues_one_warm(): void
    {
        Queue::fake();
        DB::table('sim_runs')->insert([
            'id' => (string) Str::uuid(), 'status' => 'running', 'phase' => 'seating',
            'people_founded' => 7, 'residencies_founded' => 0,
            'cohorts' => 0, 'chambers_governed' => 0,
            'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        ]);

        // No warmed 'world' snapshot. The poll must still not scan; it serves
        // computing zeros for the scope, keeps the run-row counters, and queues
        // exactly one off-thread warm.
        $log = $this->pollQueryLog(fn () => app(SimSnapshot::class)->world(true));

        $this->assertNoForbiddenScan($log);
        $world = $log['result'];
        $this->assertSame('computing', $world['scope_snapshot_state']);
        $this->assertSame(0, $world['chambers']);
        $this->assertSame(0, $world['jurisdictions']);
        $this->assertSame(0, $world['population_modelled']);
        $this->assertSame(7, $world['people']);
        Queue::assertPushed(WarmSetupRollupJob::class, 1);
    }

    // ─── (e): the backstop threshold follows the demo clock ──────────────────

    public function test_backstop_threshold_is_48h_without_compression(): void
    {
        config(['cga.election_demo_compression' => false]);
        $threshold = EvaluateCoDeterminationJob::backstopThreshold();
        $this->assertEqualsWithDelta(now()->subHours(48)->timestamp, $threshold->timestamp, 5);
    }

    public function test_backstop_threshold_compresses_with_the_demo_clock(): void
    {
        config(['cga.election_demo_compression' => 5]);
        $threshold = EvaluateCoDeterminationJob::backstopThreshold();
        $this->assertEqualsWithDelta(now()->subMinutes(5)->timestamp, $threshold->timestamp, 5);
        // The compressed grace is far nearer than 48h, so a stalled board seats.
        $this->assertGreaterThan(now()->subHours(48)->timestamp, $threshold->timestamp);
    }

    // ─── (f): the ordering-key derive resumes from its phase marker ──────────

    public function test_derive_marker_defaults_to_a_fresh_run(): void
    {
        $m = AutoscaleEnumeration::readDeriveMarker();
        $this->assertSame('stamp', $m['phase']);
        $this->assertSame('00000000-0000-0000-0000-000000000000', $m['geom_cursor']);
        $this->assertNull($m['started_at']);
    }

    public function test_fresh_marker_runs_every_phase(): void
    {
        foreach (AutoscaleEnumeration::DERIVE_PHASES as $phase) {
            if ($phase === 'done') {
                continue;
            }
            $this->assertTrue(AutoscaleEnumeration::shouldRunDerivePhase('stamp', $phase),
                "a fresh run must run the {$phase} phase");
        }
    }

    public function test_marker_in_geometry_skips_completed_phases_and_resumes_geometry(): void
    {
        AutoscaleEnumeration::writeDeriveMarker([
            'phase' => 'geometry', 'geom_cursor' => 'aaaaaaaa-0000-0000-0000-000000000000',
            'geom_total' => 900000, 'geom_done' => 400000, 'started_at' => now()->toIso8601String(),
        ]);

        $m = AutoscaleEnumeration::readDeriveMarker();
        $this->assertSame('geometry', $m['phase']);
        $this->assertSame('aaaaaaaa-0000-0000-0000-000000000000', $m['geom_cursor']);
        $this->assertSame(400000, $m['geom_done']);

        // stamp and cascade are done — skipped; geometry and later phases run.
        $this->assertFalse(AutoscaleEnumeration::shouldRunDerivePhase('geometry', 'stamp'));
        $this->assertFalse(AutoscaleEnumeration::shouldRunDerivePhase('geometry', 'cascade'));
        $this->assertTrue(AutoscaleEnumeration::shouldRunDerivePhase('geometry', 'geometry'));
        $this->assertTrue(AutoscaleEnumeration::shouldRunDerivePhase('geometry', 'position'));
        $this->assertTrue(AutoscaleEnumeration::shouldRunDerivePhase('geometry', 'block'));
    }

    public function test_marker_in_position_skips_the_geometry_scan(): void
    {
        $this->assertFalse(AutoscaleEnumeration::shouldRunDerivePhase('position', 'geometry'));
        $this->assertTrue(AutoscaleEnumeration::shouldRunDerivePhase('position', 'position'));
        $this->assertTrue(AutoscaleEnumeration::shouldRunDerivePhase('position', 'block'));
    }

    public function test_derive_marker_round_trips_and_clears(): void
    {
        AutoscaleEnumeration::writeDeriveMarker([
            'phase' => 'geometry', 'geom_cursor' => 'bbbbbbbb-0000-0000-0000-000000000000',
            'geom_total' => 10, 'geom_done' => 3, 'started_at' => null,
        ]);
        $this->assertSame('geometry', AutoscaleEnumeration::readDeriveMarker()['phase']);

        AutoscaleEnumeration::clearDeriveMarker();
        $this->assertSame('stamp', AutoscaleEnumeration::readDeriveMarker()['phase']);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    /**
     * Run one poll under a query log, returning {result, queries}.
     *
     * @return array{result:mixed,queries:array<int,array<string,mixed>>}
     */
    private function pollQueryLog(callable $poll): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result  = $poll();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return ['result' => $result, 'queries' => $queries];
    }

    /** @param array{result:mixed,queries:array<int,array<string,mixed>>} $log */
    private function assertNoForbiddenScan(array $log): void
    {
        foreach ($log['queries'] as $q) {
            $sql = strtolower($q['query']);
            foreach (['"provision_ledger"', '"legislatures"', '"jurisdictions"', '"users"'] as $needle) {
                $this->assertStringNotContainsString($needle, $sql,
                    "the poll must not scan {$needle}: {$q['query']}");
            }
        }
    }

    private function invokePrivate(string $method): mixed
    {
        $controller = app(SetupController::class);
        $ref = new \ReflectionMethod($controller, $method);
        $ref->setAccessible(true);

        return $ref->invoke($controller);
    }

    private function seedLedger(): void
    {
        $rows = [
            ['status' => 'done',    'stage' => 1, 'adm_level' => 1],
            ['status' => 'done',    'stage' => 1, 'adm_level' => 2],
            ['status' => 'review',  'stage' => 1, 'adm_level' => 2],
        ];
        foreach ($rows as $r) {
            DB::table('provision_ledger')->insert([
                'legislature_id'  => (string) Str::uuid(),
                'jurisdiction_id' => (string) Str::uuid(),
                'status'          => $r['status'],
                'stage'           => $r['stage'],
                'adm_level'       => $r['adm_level'],
                'est_cost'        => 10,
                'finished_at'     => $r['status'] === 'done' ? now()->toDateTimeString() : null,
            ]);
        }
    }

    private function buildSchema(): void
    {
        Schema::create('provision_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('provision_ledger', function (Blueprint $t) {
            $t->uuid('legislature_id');
            $t->uuid('jurisdiction_id')->nullable();
            $t->string('status')->nullable();
            $t->integer('stage')->default(0);
            $t->integer('adm_level')->nullable();
            $t->integer('est_cost')->default(0);
            $t->string('reason')->nullable();
            $t->timestamp('finished_at')->nullable();
        });
        Schema::create('legislatures', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('legislature_districts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('executives', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('judiciaries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('jurisdictions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->integer('adm_level')->nullable();
            $t->bigInteger('population')->nullable();
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('autoscale_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('status')->nullable();
        });
        Schema::create('jurisdiction_cohorts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->bigInteger('population')->default(0);
            $t->bigInteger('electorate')->default(0);
        });
        Schema::create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('email')->nullable();
        });
        Schema::create('residency_confirmations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->boolean('is_active')->default(true);
        });
        Schema::create('sim_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('status')->nullable();
            $t->string('phase')->nullable();
            $t->unsignedBigInteger('people_founded')->default(0);
            $t->unsignedBigInteger('residencies_founded')->default(0);
            $t->unsignedBigInteger('cohorts')->default(0);
            $t->unsignedBigInteger('chambers_governed')->default(0);
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
        foreach ([
            'provision_runs', 'provision_ledger', 'legislatures', 'legislature_districts',
            'executives', 'judiciaries', 'jurisdictions', 'autoscale_runs',
            'jurisdiction_cohorts', 'users', 'residency_confirmations', 'sim_runs',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }
}
