<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Step-3 dashboard poll target, GET /api/setup/wizard/step3/autoscale-progress
 * (fix 7 + the UI half of fix 3, 2026-09-02).
 *
 * Runs on the phpunit sqlite :memory: connection with a minimal schema
 * built in setUp: only the tables autoscaleProgress reads, no PostGIS, no
 * live box. The live database is never touched.
 *
 * Pins:
 *   (a) the payload carries run.lane_warn_seconds (from config, default
 *       [300, 900]) and run.auto_kill_minutes (null before the Workstream A
 *       column exists, the stored value once it does);
 *   (b) each workers_detail entry carries lease_id, claim_secs and a boolean
 *       kill_requested (false before the column exists, true when the
 *       column holds a timestamp);
 *   (c) THE POLL LOAD: two polls inside 5 s run each planet-wide
 *       aggregate (drift row, rate row, drifted list, layer bars, scopes
 *       left, Type B count, the precompute worklist aggregate, and during
 *       sizing the legislatures and maps counts) exactly once. The second
 *       poll is served from cache. Counted through DB::listen.
 */
class SetupAutoscaleProgressTest extends TestCase
{
    private const RUN_ID = '0f6e6d1a-1111-4a2b-9c3d-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->buildSchema();
    }

    // ─── (a) + (b): payload keys, null-safe before the Workstream A columns ──

    public function test_payload_carries_lane_warn_seconds_and_null_safe_auto_kill_and_kill_requested(): void
    {
        config(['cga.lane_warn_seconds' => [300, 900]]);
        $this->seedRun('mapping');
        $this->seedLease('claiming a scope', 120);

        $json = $this->getJson('/api/setup/wizard/step3/autoscale-progress')
            ->assertOk()
            ->json();

        $this->assertSame(self::RUN_ID, $json['run']['id']);
        $this->assertSame([300, 900], $json['run']['lane_warn_seconds']);
        $this->assertArrayHasKey('auto_kill_minutes', $json['run']);
        $this->assertNull($json['run']['auto_kill_minutes'], 'no column yet: null, never an exception');

        $this->assertCount(1, $json['workers_detail']);
        $lane = $json['workers_detail'][0];
        $this->assertSame(8, strlen($lane['id']));
        $this->assertTrue(Str::isUuid($lane['lease_id']), 'the kill endpoint takes the full lease id');
        $this->assertSame('claiming a scope', $lane['claim_label']);
        $this->assertIsInt($lane['claim_secs']);
        $this->assertGreaterThanOrEqual(119, $lane['claim_secs']);
        $this->assertFalse($lane['kill_requested'], 'no column yet: false, never an exception');
    }

    public function test_lane_warn_seconds_default_when_config_is_absent(): void
    {
        config(['cga.lane_warn_seconds' => null]);
        $this->seedRun('mapping');

        $json = $this->getJson('/api/setup/wizard/step3/autoscale-progress')->assertOk()->json();

        $this->assertSame([300, 900], $json['run']['lane_warn_seconds']);
    }

    public function test_auto_kill_minutes_and_kill_requested_read_through_once_the_columns_exist(): void
    {
        Schema::table('autoscale_runs', fn (Blueprint $t) => $t->smallInteger('auto_kill_minutes')->nullable());
        Schema::table('autoscale_worker_leases', fn (Blueprint $t) => $t->timestamp('kill_requested_at')->nullable());

        $this->seedRun('mapping', ['auto_kill_minutes' => 45]);
        $this->seedLease('claiming a scope', 1000, ['kill_requested_at' => now()->toDateTimeString()]);

        $json = $this->getJson('/api/setup/wizard/step3/autoscale-progress')->assertOk()->json();

        $this->assertSame(45, $json['run']['auto_kill_minutes']);
        $this->assertTrue($json['workers_detail'][0]['kill_requested']);
        $this->assertGreaterThanOrEqual(999, $json['workers_detail'][0]['claim_secs']);
    }

    // ─── (c) THE POLL LOAD: planet-wide aggregates run once per 5 s ─────────

    public function test_two_polls_inside_five_seconds_run_the_planet_wide_aggregates_once(): void
    {
        // precompute_started_at set: the worklist aggregate is on the poll
        // path, as it is on the live box for the rest of every run.
        $this->seedRun('mapping', ['precompute_started_at' => now()->subMinutes(40)->toDateTimeString()]);
        $this->seedLease('claiming a scope', 10);
        $this->seedLedger();
        $this->seedPrecomputeWorklist();

        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = $q->sql;
        });

        $first = $this->getJson('/api/setup/wizard/step3/autoscale-progress')->assertOk()->json();
        $second = $this->getJson('/api/setup/wizard/step3/autoscale-progress')->assertOk()->json();

        // The aggregates and their SQL fingerprints (SetupController::autoscaleProgress).
        $fingerprints = [
            'drift row'           => 'AS drifted',
            'rate row'            => 'sweeps_30m',
            'scopes left'         => 'where "status" in (?, ?)',
            'drifted list'        => 'abs(h.drift)',
            'layer bars'          => 'leaf_total',
            'precompute worklist' => 'jurisdiction_adjacency_parents',
        ];
        foreach ($fingerprints as $name => $needle) {
            $hits = count(array_filter($sql, fn ($s) => str_contains($s, $needle)));
            $this->assertSame(1, $hits, "{$name}: ran {$hits} times across two polls; the cache must serve the second");
        }

        // The numbers the copy carries are the live ones.
        $this->assertSame(2, $first['run']['drifted_done']);
        $this->assertSame(3, $first['run']['net_drift']);
        $this->assertSame(1, $first['run']['attention_count']);
        // JSON carries 12.0 as 12; compare by value.
        $this->assertEqualsWithDelta(12.0, $first['run']['sweeps_per_hour'], 0.001, 'two scopes done in the window, times six');
        $this->assertEqualsWithDelta(6.0, $first['run']['singles_per_hour'], 0.001, 'one single done in the window, times six');
        $this->assertCount(2, $first['drifted_items']);
        $this->assertSame($first['run']['drifted_done'], $second['run']['drifted_done']);
        $this->assertSame($first['drifted_items'], $second['drifted_items']);
        $this->assertSame(['total' => 4, 'done' => 2, 'running' => 1, 'failed' => 1], $first['precompute']);
        $this->assertSame($first['precompute'], $second['precompute']);

        // The lease strip is NOT cached: it is one indexed read per lane
        // and its clock must tick every poll.
        $laneReads = count(array_filter($sql, fn ($s) => str_contains($s, 'autoscale_worker_leases')));
        $this->assertSame(2, $laneReads);
    }

    public function test_sizing_phase_counters_run_once_across_two_polls(): void
    {
        $this->seedRun('sizing');
        $this->seedLedger();
        DB::table('legislature_district_maps')->insert([
            'id' => (string) Str::uuid(), 'legislature_id' => (string) Str::uuid(), 'status' => 'active',
        ]);

        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = $q->sql;
        });

        $first = $this->getJson('/api/setup/wizard/step3/autoscale-progress')->assertOk()->json();
        $second = $this->getJson('/api/setup/wizard/step3/autoscale-progress')->assertOk()->json();

        // sized_live counts legislatures with deleted_at null (the Type B
        // count filters type_b_needs_districting first, so this needle is
        // unique to sized_live); maps_minted counts the maps table (the
        // drifted list joins it as "m", so the bare FROM is unique).
        $fingerprints = [
            'sized live'  => 'from "legislatures" where "deleted_at" is null',
            'maps minted' => 'from "legislature_district_maps"',
        ];
        foreach ($fingerprints as $name => $needle) {
            $hits = count(array_filter($sql, fn ($s) => str_contains($s, $needle)));
            $this->assertSame(1, $hits, "{$name}: ran {$hits} times across two polls; the cache must serve the second");
        }

        $this->assertSame(5, $first['run']['sized_live']);
        $this->assertSame(1, $first['run']['maps_minted']);
        $this->assertSame(5, $first['run']['maps_total']);
        $this->assertSame($first['run']['sized_live'], $second['run']['sized_live']);
        $this->assertSame($first['run']['maps_minted'], $second['run']['maps_minted']);
    }

    // ─── fixtures ────────────────────────────────────────────────────────────

    private function seedRun(string $status, array $extra = []): void
    {
        DB::table('autoscale_runs')->insert(array_merge([
            'id'            => self::RUN_ID,
            'status'        => $status,
            'adm_max'       => 6,
            'sized_parents' => 1,
            'sized_leaves'  => 2,
            'singles_total' => 2,
            'singles_done'  => 1,
            'sweeps_total'  => 3,
            'sweeps_done'   => 1,
            'review_count'  => 1,
            'created_at'    => now()->subHour()->toDateTimeString(),
            'updated_at'    => now()->toDateTimeString(),
            'mapping_started_at' => now()->subMinutes(30)->toDateTimeString(),
        ], $extra));
    }

    private function seedLease(string $label, int $claimSecs, array $extra = []): void
    {
        DB::table('autoscale_worker_leases')->insert(array_merge([
            'id'               => (string) Str::uuid(),
            'run_id'           => self::RUN_ID,
            'started_at'       => now()->subMinutes(20)->toDateTimeString(),
            'last_seen_at'     => now()->toDateTimeString(),
            'claim_type'       => 'scope',
            'claim_label'      => $label,
            'claim_started_at' => now()->subSeconds($claimSecs)->toDateTimeString(),
        ], $extra));
    }

    /** Five headers: two drifted done (+2, +1), one clean done, one review, one pending single done now. */
    private function seedLedger(): void
    {
        $j = [];
        foreach (['alpha', 'beta', 'gamma', 'delta', 'epsilon'] as $slug) {
            $id = (string) Str::uuid();
            $j[$slug] = $id;
            DB::table('jurisdictions')->insert(['id' => $id, 'name' => ucfirst($slug), 'slug' => $slug]);
        }
        $rows = [
            ['j' => 'alpha',   'kind' => 'sweep',  'lvl' => 1, 'status' => 'done',    'drift' => 2,    'finished' => now()->subMinutes(2)],
            ['j' => 'beta',    'kind' => 'sweep',  'lvl' => 1, 'status' => 'done',    'drift' => 1,    'finished' => now()->subMinutes(3)],
            ['j' => 'gamma',   'kind' => 'sweep',  'lvl' => 2, 'status' => 'done',    'drift' => 0,    'finished' => now()->subHours(2)],
            ['j' => 'delta',   'kind' => 'sweep',  'lvl' => 2, 'status' => 'review',  'drift' => null, 'finished' => null],
            ['j' => 'epsilon', 'kind' => 'single', 'lvl' => 3, 'status' => 'done',    'drift' => 0,    'finished' => now()->subMinutes(1)],
        ];
        $pos = 0;
        foreach ($rows as $r) {
            $legId = (string) Str::uuid();
            DB::table('legislatures')->insert(['id' => $legId, 'jurisdiction_id' => $j[$r['j']], 'type_b_needs_districting' => false]);
            DB::table('apportionment_ledger')->insert([
                'legislature_id'  => $legId,
                'jurisdiction_id' => $j[$r['j']],
                'population'      => 1000,
                'head_seats'      => 9,
                'adm_level'       => $r['lvl'],
                'kind'            => $r['kind'],
                'child_count'     => $r['kind'] === 'single' ? 0 : 3,
                'position'        => ++$pos,
                'map_status'      => $r['status'],
                'seats_seated'    => $r['drift'] !== null ? 9 + $r['drift'] : null,
                'drift'           => $r['drift'],
                'finished_at'     => $r['finished']?->toDateTimeString(),
            ]);
            // One scope per header; the two drifted sweeps closed in the window.
            DB::table('apportionment_ledger_scopes')->insert([
                'legislature_id'        => $legId,
                'scope_jurisdiction_id' => $j[$r['j']],
                'depth'                 => 0,
                'status'                => $r['status'] === 'done' ? 'done' : 'pending',
                'finished_at'           => in_array($r['j'], ['alpha', 'beta'], true) ? now()->subMinutes(2)->toDateTimeString() : null,
                'area_tier'             => 1,
                'is_leaf'               => $r['kind'] === 'single',
            ]);
        }
    }

    /** Four worklist rows: two done, one running, one failed. */
    private function seedPrecomputeWorklist(): void
    {
        foreach (['done', 'done', 'running', 'failed'] as $status) {
            DB::table('jurisdiction_adjacency_parents')->insert([
                'parent_id'   => (string) Str::uuid(),
                'adm_level'   => 1,
                'child_count' => 3,
                'status'      => $status,
                'updated_at'  => now()->toDateTimeString(),
            ]);
        }
    }

    /** The tables autoscaleProgress reads, at the width it reads them. */
    private function buildSchema(): void
    {
        Schema::create('jurisdiction_adjacency_parents', function (Blueprint $t) {
            $t->uuid('parent_id')->primary();
            $t->smallInteger('adm_level')->default(0);
            $t->integer('child_count')->default(0);
            $t->string('status', 16)->default('pending');
            $t->uuid('claim_token')->nullable();
            $t->integer('duration_ms')->nullable();
            $t->text('error')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('autoscale_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('status');
            $t->smallInteger('adm_max')->default(6);
            $t->integer('sized_parents')->default(0);
            $t->integer('sized_leaves')->default(0);
            $t->integer('singles_total')->default(0);
            $t->integer('singles_done')->default(0);
            $t->integer('sweeps_total')->default(0);
            $t->integer('sweeps_done')->default(0);
            $t->integer('review_count')->default(0);
            $t->text('last_error')->nullable();
            $t->timestamp('sizing_started_at')->nullable();
            $t->timestamp('mapping_started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamp('halt_requested_at')->nullable();
            $t->timestamp('paused_until')->nullable();
            $t->timestamp('sizing_lease_at')->nullable();
            $t->timestamp('precompute_started_at')->nullable();
            $t->text('pg_fingerprint')->nullable();
            $t->timestamps();
        });
        Schema::create('autoscale_worker_leases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('run_id');
            $t->timestamp('started_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->string('claim_type')->nullable();
            $t->string('claim_label')->nullable();
            $t->timestamp('claim_started_at')->nullable();
            $t->string('lane')->nullable();
            $t->integer('pg_backend_pid')->nullable();
        });
        Schema::create('jurisdictions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('slug');
            $t->uuid('parent_id')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('legislatures', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('jurisdiction_id');
            $t->boolean('type_b_needs_districting')->default(false);
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('legislature_district_maps', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('legislature_id');
            $t->string('status');
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('apportionment_ledger', function (Blueprint $t) {
            $t->uuid('legislature_id')->primary();
            $t->uuid('jurisdiction_id');
            $t->bigInteger('population')->nullable();
            $t->integer('head_seats')->nullable();
            $t->smallInteger('adm_level');
            $t->string('kind');
            $t->integer('child_count')->default(0);
            $t->integer('position')->nullable();
            $t->string('map_status')->default('pending');
            $t->integer('seats_seated')->nullable();
            $t->integer('drift')->nullable();
            $t->text('reason')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
        });
        Schema::create('apportionment_ledger_scopes', function (Blueprint $t) {
            $t->uuid('legislature_id');
            $t->uuid('scope_jurisdiction_id');
            $t->smallInteger('depth')->default(0);
            $t->string('status')->default('pending');
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->smallInteger('area_tier')->nullable();
            $t->boolean('is_leaf')->nullable();
            $t->string('scope_kind', 8)->default('type_a');
        });
        Schema::create('world_builds', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('status');
            $t->text('steps')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
        });
    }
}
