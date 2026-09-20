<?php

namespace Tests\Feature;

use App\Jobs\SimWorkerJob;
use App\Models\{Election, SimRun};
use App\Services\AuditService;
use App\Services\Demo\{SimRepairControl, SimRunControl};
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\{Artisan, DB, Queue};
use Illuminate\Support\Str;
use Tests\Concerns\DisposableRepairWorld;
use Tests\TestCase;

/** Actual worker + pump against the complete installed PostgreSQL schema. */
class SimRepairWorkerTest extends TestCase
{
    use DisposableRepairWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openRepairWorld();
        config(['cache.default' => 'array', 'queue.default' => 'sync']);
        Queue::fake([SimWorkerJob::class]); // Run each real worker explicitly, no background fixture processes.
        $this->app->instance(SimRunControl::class, new class(app(AuditService::class)) extends SimRunControl {
            public function refusalReason(): ?string { return null; }
        });
    }

    protected function tearDown(): void
    {
        DB::purge('sim_repair_heartbeat');
        $this->closeRepairWorld();
        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_09_20_123000_widen_sim_worker_claim_type.php');
    }

    public function test_upgrade_preserves_nulls_and_old_claims_and_covers_every_declared_kind(): void
    {
        DB::statement('ALTER TABLE sim_worker_leases ALTER COLUMN claim_type TYPE varchar(16)');
        $run = (string) Str::uuid();
        foreach ([null, 'training_scope'] as $kind) {
            DB::table('sim_worker_leases')->insert(['id' => (string) Str::uuid(), 'run_id' => $run,
                'started_at' => now(), 'last_seen_at' => now(), 'claim_type' => $kind]);
        }
        $before = DB::table('sim_worker_leases')->where('run_id', $run)->orderBy('id')->get()->toArray();
        $this->migration()->up();
        $this->migration()->up(); // Safe after a committed migration whose acknowledgement was lost.
        self::assertEquals($before, DB::table('sim_worker_leases')->where('run_id', $run)->orderBy('id')->get()->toArray());
        $columns = DB::select("SELECT table_name, column_name, character_maximum_length AS width, is_nullable
            FROM information_schema.columns WHERE table_schema = 'public' AND
            ((table_name='sim_items' AND column_name='kind') OR
             (table_name='sim_worker_leases' AND column_name IN ('claim_type','claim_label')) OR
             (table_name='sim_runs' AND column_name='phase') OR
             (table_name='sim_timings' AND column_name='part') OR
             (table_name='sim_repair_receipts' AND column_name='kind'))");
        $width = collect($columns)->keyBy(fn ($c) => $c->table_name.'.'.$c->column_name);
        self::assertSame(24, (int) $width['sim_worker_leases.claim_type']->width);
        self::assertSame('YES', $width['sim_worker_leases.claim_type']->is_nullable);
        foreach (SimRun::PHASE_KINDS as $phase => $kinds) {
            self::assertLessThanOrEqual((int) $width['sim_runs.phase']->width, strlen($phase), $phase);
            foreach ($kinds as $kind) {
                foreach (['sim_items.kind','sim_worker_leases.claim_type'] as $column) {
                    self::assertLessThanOrEqual((int) $width[$column]->width, strlen($kind), $column.': '.$kind);
                }
                self::assertLessThanOrEqual((int) $width['sim_timings.part']->width, strlen('stage.'.$kind));
                DB::table('sim_worker_leases')->where('run_id', $run)->update(['claim_type' => $kind]);
            }
        }
        foreach (['election','training','governance','judiciary','civics','chair'] as $action) {
            self::assertLessThanOrEqual((int) $width['sim_repair_receipts.kind']->width, strlen($action));
            self::assertLessThanOrEqual((int) $width['sim_timings.part']->width, strlen('repair.'.$action));
        }
        $this->migration()->down();
        DB::table('sim_worker_leases')->where('run_id', $run)->update(['claim_type' => 'repair_plan_scope']);
        self::assertSame('repair_plan_scope', DB::table('sim_worker_leases')->where('run_id', $run)->value('claim_type'));
    }

    public function test_real_worker_reproduces_old_failure_then_resumes_same_pilot_through_inspection_and_apply_gate(): void
    {
        $scope = (string) Str::uuid();
        DB::table('jurisdictions')->insert(['id' => $scope, 'name' => 'Worker repair fixture', 'slug' => $scope,
            'adm_level' => 6, 'population' => 1000, 'created_at' => now(), 'updated_at' => now()]);
        $source = SimRun::create(['status' => 'done', 'phase' => 'done', 'options' => ['scope_aspects' => ['elections']], 'phase_timings' => []]);
        $election = Election::create(['jurisdiction_id' => $scope, 'kind' => 'general', 'status' => 'scheduled', 'cycle_number' => 1]);
        foreach (['verify_scope','election_scope','stipend_scope'] as $kind) {
            DB::table('sim_items')->insert(['id' => (string) Str::uuid(), 'run_id' => $source->id, 'kind' => $kind,
                'unit_key' => $scope, 'jurisdiction_id' => $scope, 'status' => $kind === 'verify_scope' ? 'review' : 'done',
                'metrics' => json_encode($kind === 'election_scope' ? ['election_id' => $election->id] : []), 'created_at' => now(), 'updated_at' => now()]);
        }
        $original = DB::table('sim_items')->where('run_id', $source->id)->orderBy('id')->get()->toArray();
        $control = app(SimRepairControl::class);
        $pilot = $control->start($source->id, [$scope]); $control->enumerate($pilot);
        $item = DB::table('sim_items')->where('run_id', $pilot->id)->sole();
        DB::statement('ALTER TABLE sim_worker_leases ALTER COLUMN claim_type TYPE varchar(16)');
        DB::commit(); // Independent heartbeat connection must see the committed lease/run.
        try {
            (new SimWorkerJob($pilot->id))->handle();
            self::fail('The historical schema must reproduce D013.');
        } catch (QueryException $error) {
            self::assertSame('22001', $error->errorInfo[0]);
            self::assertStringContainsString('claim_type', $error->getSql());
        }
        self::assertSame('running', DB::table('sim_items')->where('id', $item->id)->value('status'));
        self::assertSame(0, DB::table('sim_worker_leases')->where('run_id', $pilot->id)->count());
        self::assertSame(0, DB::table('sim_repair_receipts')->where('source_run_id', $source->id)->count());
        $runs = app(SimRunControl::class);
        self::assertTrue($runs->halt('fixture')['ok']); Artisan::call('sim:pump');
        self::assertSame('halted', $pilot->refresh()->status);
        $this->migration()->up();

        $reported = []; $settlement = [];
        DB::listen(function (QueryExecuted $query) use (&$reported, &$settlement, $pilot): void {
            if (str_contains($query->sql, 'update "sim_worker_leases"') && in_array('repair_plan_scope', $query->bindings, true)) {
                $reported[] = DB::table('sim_worker_leases')->where('run_id', $pilot->id)->value('claim_type');
            }
            if (str_starts_with($query->sql, 'update "sim_items"') && str_contains($query->sql, '"finished_at"')) {
                $settlement[] = $query->sql;
            }
        });
        self::assertTrue($runs->resume('fixture')['ok']);
        self::assertSame(0, Artisan::call('sim:pump')); // Real reclaim, not manual status repair.
        self::assertSame('pending', DB::table('sim_items')->where('id', $item->id)->value('status'));
        (new SimWorkerJob($pilot->id))->handle(); // Real lease report, inspector, heartbeat, settlement, and pump kick.
        self::assertSame(['repair_plan_scope'], $reported);
        $finished = DB::table('sim_items')->where('id', $item->id)->sole();
        self::assertSame('done', $finished->status, $finished->reason ?? '');
        self::assertNull($finished->claim_token); self::assertNotNull($finished->finished_at);
        self::assertNotEmpty($settlement);
        self::assertStringContainsString('and "claim_token" = ? and "status" = ?', $settlement[0]);
        $plan = json_decode($finished->metrics, true);
        self::assertSame($election->id, $plan['plan']['election_id']);
        self::assertContains(['kind' => 'election', 'target' => $election->id], $plan['plan']['actions']);
        self::assertSame('planned', DB::table('sim_repair_receipts')->where('source_run_id', $source->id)->value('status'));
        self::assertSame('halted', $pilot->refresh()->status);
        self::assertSame('repair_planning', $pilot->phase);
        self::assertTrue($pilot->options['repair_plan_complete']);
        self::assertFalse($pilot->options['repair_apply_authorized']);
        self::assertSame(1, $control->report($pilot)['summary']['scopes']);
        self::assertSame(0, DB::table('sim_items')->where('run_id', $pilot->id)->where('kind', 'repair_scope')->count());
        self::assertSame(0, DB::table('sim_worker_leases')->where('run_id', $pilot->id)->count());
        self::assertEquals($original, DB::table('sim_items')->where('run_id', $source->id)->orderBy('id')->get()->toArray());
        self::assertSame('scheduled', $election->refresh()->status);
        self::assertSame(0, DB::table('candidacies')->where('election_id', $election->id)->count());
        self::assertSame(1, DB::table('sim_timings')->where('run_id', $pilot->id)->where('part', 'stage.repair_plan_scope')->value('count'));
        // Even ordinary resume cannot bypass the explicit apply gate.
        $runs->resume('fixture'); Artisan::call('sim:pump');
        self::assertSame('halted', $pilot->refresh()->status);
        self::assertFalse($pilot->options['repair_apply_authorized']);
        $pilot->forceFill(['status' => 'done'])->save(); // Only private-fixture cleanup.
    }
}
