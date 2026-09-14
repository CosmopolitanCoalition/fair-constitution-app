<?php

namespace Tests\Unit;

use App\Http\Controllers\SetupController;
use App\Models\SimRun;
use App\Services\Demo\Stages\VerifyStage;
use App\Support\WorldReadiness;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * G1 — world-readiness verification + the Step 5 completion guard.
 *
 * DB-FREE. Builds a tiny synthetic tree in the default sqlite connection (never
 * the live world). Proves: the verify stage passes a complete scope and files
 * review-with-gaps for each incomplete one; WorldReadiness rolls the outcomes
 * up; and completeStep5 refuses on review items, refuses "verification pending"
 * on a done run with zero verify items, passes with force (recording the
 * outstanding list) and passes clean; completeStep6 refuses without the Step 5
 * pass.
 */
class WorldReadinessGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();
    }

    private function buildSchema(): void
    {
        foreach ([
            'instance_settings', 'sim_runs', 'sim_items', 'jurisdictions',
            'legislatures', 'legislature_members', 'executives', 'judiciaries',
            'organizations', 'boards',
        ] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('instance_settings', function ($t) {
            $t->string('id')->primary();
            $t->string('instance_name')->nullable();
            $t->string('map_mode')->nullable();
            $t->string('time_mode')->nullable();
            $t->integer('time_scale_seconds_per_year')->nullable();
            $t->integer('setup_step_completed')->default(0);
            $t->timestamp('setup_completed_at')->nullable();
            $t->text('setup_completion_notes')->nullable();
            $t->timestamp('setup_districts_confirmed_at')->nullable();
            $t->timestamp('map_accepted_at')->nullable();
            $t->timestamp('apportionment_completed_at')->nullable();
            $t->string('setup_mode')->nullable();
            $t->string('game_mode')->nullable();
            $t->string('institution_scale_mode')->nullable();
            $t->boolean('simulate_at_scale')->default(false);
            $t->string('cosmic_address_id')->nullable();
            $t->string('server_id')->nullable();
            $t->string('mirror_of_server_id')->nullable();
            $t->string('constitutional_version')->nullable();
            $t->timestamp('version_pinned_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });

        Schema::create('sim_runs', function ($t) {
            $t->string('id')->primary();
            $t->string('status')->default('queued');
            $t->string('phase')->default('enumerating');
            $t->text('options')->nullable();
            $t->integer('items_total')->default(0);
            $t->integer('items_done')->default(0);
            $t->integer('items_review')->default(0);
            $t->integer('open_items')->default(0);
            $t->timestamps();
        });

        Schema::create('sim_items', function ($t) {
            $t->string('id')->primary();
            $t->string('run_id');
            $t->string('kind');
            $t->string('status')->default('pending');
            $t->string('jurisdiction_id')->nullable();
            $t->integer('adm_level')->nullable();
            $t->string('unit_key');
            $t->integer('position')->default(0);
            $t->string('claim_token')->nullable();
            $t->text('reason')->nullable();
            $t->text('metrics')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });

        Schema::create('jurisdictions', function ($t) {
            $t->string('id')->primary();
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->integer('adm_level')->nullable();
            $t->string('parent_id')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('legislatures', function ($t) {
            $t->string('id')->primary();
            $t->string('jurisdiction_id');
            $t->integer('total_seats')->default(0);
            $t->string('status')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('legislature_members', function ($t) {
            $t->string('id')->primary();
            $t->string('legislature_id');
            $t->string('status')->nullable();
        });

        Schema::create('executives', function ($t) {
            $t->string('id')->primary();
            $t->string('jurisdiction_id');
            $t->string('status')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('judiciaries', function ($t) {
            $t->string('id')->primary();
            $t->string('jurisdiction_id');
            $t->string('status')->nullable();
            $t->integer('judge_count')->default(0);
            $t->integer('min_judges')->default(5);
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('organizations', function ($t) {
            $t->string('id')->primary();
            $t->string('jurisdiction_id')->nullable();
            $t->string('ownership_type')->nullable();
            $t->string('board_id')->nullable();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('boards', function ($t) {
            $t->string('id')->primary();
            $t->string('chair_seat_id')->nullable();
            $t->string('status')->nullable();
            $t->integer('owner_seats')->default(0);
        });
    }

    // ── fixture builders ─────────────────────────────────────────────────────

    private function jurisdiction(string $name, int $adm = 4): string
    {
        $id = (string) Str::uuid();
        DB::table('jurisdictions')->insert([
            'id' => $id, 'name' => $name, 'slug' => Str::slug($name), 'adm_level' => $adm,
        ]);

        return $id;
    }

    private function legislature(string $jur, int $seats, int $seated): void
    {
        $legId = (string) Str::uuid();
        DB::table('legislatures')->insert([
            'id' => $legId, 'jurisdiction_id' => $jur, 'total_seats' => $seats, 'status' => 'active',
        ]);
        for ($i = 0; $i < $seated; $i++) {
            DB::table('legislature_members')->insert([
                'id' => (string) Str::uuid(), 'legislature_id' => $legId, 'status' => 'seated',
            ]);
        }
    }

    private function executive(string $jur, string $status): void
    {
        DB::table('executives')->insert([
            'id' => (string) Str::uuid(), 'jurisdiction_id' => $jur, 'status' => $status,
        ]);
    }

    private function judiciary(string $jur, string $status, int $judges, int $min = 5): void
    {
        DB::table('judiciaries')->insert([
            'id' => (string) Str::uuid(), 'jurisdiction_id' => $jur,
            'status' => $status, 'judge_count' => $judges, 'min_judges' => $min,
        ]);
    }

    private function orgWithBoard(string $jur, ?string $ownership, ?string $chairSeat, int $ownerSeats = 3): void
    {
        $boardId = (string) Str::uuid();
        DB::table('boards')->insert([
            'id' => $boardId, 'chair_seat_id' => $chairSeat, 'status' => 'active', 'owner_seats' => $ownerSeats,
        ]);
        DB::table('organizations')->insert([
            'id' => (string) Str::uuid(), 'jurisdiction_id' => $jur,
            'ownership_type' => $ownership, 'board_id' => $boardId,
        ]);
    }

    private function makeRun(string $status, array $aspects = []): SimRun
    {
        $run = new SimRun();
        $run->id = (string) Str::uuid();
        $run->status = $status;
        $run->phase = $status === 'done' ? 'done' : 'verifying';
        $run->options = $aspects === [] ? [] : ['scope_aspects' => $aspects];
        $run->save();

        return $run;
    }

    private function verifyItem(string $runId, string $jur, string $status, ?string $reason = null): void
    {
        DB::table('sim_items')->insert([
            'id' => (string) Str::uuid(), 'run_id' => $runId, 'kind' => 'verify_scope',
            'status' => $status, 'jurisdiction_id' => $jur, 'unit_key' => $jur,
            'reason' => $reason, 'metrics' => '{}', 'position' => 0,
        ]);
    }

    private function settings(array $attrs = []): void
    {
        DB::table('instance_settings')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'instance_name' => 'Test',
            'map_mode' => 'physical_earth',
            'time_mode' => 'real',
            'setup_step_completed' => 5,
            'institution_scale_mode' => 'eager',
            'simulate_at_scale' => true,
            'game_mode' => 'sandbox',
        ], $attrs));
    }

    private function completeStep5(bool $force = false): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/api/setup/wizard/step5/complete', 'POST', $force ? ['force' => true] : []);
        $request->setUserResolver(fn () => (object) ['is_operator' => true, 'username' => 'operator']);

        return (new SetupController())->completeStep5($request);
    }

    private function completeStep6(): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/api/setup/wizard/step6/complete', 'POST');
        $request->setUserResolver(fn () => (object) ['is_operator' => true, 'username' => 'operator']);

        return (new SetupController())->completeStep6($request);
    }

    // ── verify stage ──────────────────────────────────────────────────────────

    public function test_verify_stage_passes_a_complete_scope(): void
    {
        $run = $this->makeRun('running');
        $jur = $this->jurisdiction('Complete');
        $this->legislature($jur, 9, 5);              // 5 >= majority(9)=5
        $this->executive($jur, 'delegated');
        $this->judiciary($jur, 'appointed', 5, 5);
        $this->orgWithBoard($jur, 'stock', (string) Str::uuid());

        $m = VerifyStage::run($jur, (string) $run->id, 1);

        $this->assertSame('done', $m['_verdict']);
        $this->assertArrayNotHasKey('gaps', $m);
    }

    public function test_verify_stage_flags_a_zero_seat_chamber(): void
    {
        $run = $this->makeRun('running', ['elections']);
        $jur = $this->jurisdiction('ZeroSeat');
        $this->legislature($jur, 0, 0);

        $m = VerifyStage::run($jur, (string) $run->id, 1);

        $this->assertSame('review', $m['_verdict']);
        $this->assertStringContainsString('zero seats', $m['_reason']);
    }

    public function test_verify_stage_flags_an_unseated_majority(): void
    {
        $run = $this->makeRun('running', ['elections']);
        $jur = $this->jurisdiction('Unseated');
        $this->legislature($jur, 9, 2);              // 2 < majority 5

        $m = VerifyStage::run($jur, (string) $run->id, 1);

        $this->assertSame('review', $m['_verdict']);
        $this->assertStringContainsString('below the majority', $m['_reason']);
    }

    public function test_verify_stage_flags_a_forming_executive(): void
    {
        $run = $this->makeRun('running', ['governance']);   // pulls in elections + training
        $jur = $this->jurisdiction('Forming');
        $this->legislature($jur, 9, 5);
        $this->executive($jur, 'forming');

        $m = VerifyStage::run($jur, (string) $run->id, 1);

        $this->assertSame('review', $m['_verdict']);
        $this->assertStringContainsString('executive status', $m['_reason']);
    }

    public function test_verify_stage_flags_a_judiciary_below_min(): void
    {
        $run = $this->makeRun('running', ['governance']);
        $jur = $this->jurisdiction('ThinBench');
        $this->legislature($jur, 9, 5);
        $this->executive($jur, 'delegated');
        $this->judiciary($jur, 'appointed', 3, 5);

        $m = VerifyStage::run($jur, (string) $run->id, 1);

        $this->assertSame('review', $m['_verdict']);
        $this->assertStringContainsString('below the minimum', $m['_reason']);
    }

    public function test_verify_stage_flags_an_unfilled_chair(): void
    {
        $run = $this->makeRun('running', ['civic_life']);
        $jur = $this->jurisdiction('NoChair');
        $this->legislature($jur, 9, 5);
        $this->executive($jur, 'delegated');
        $this->judiciary($jur, 'appointed', 5, 5);
        $this->orgWithBoard($jur, 'stock', null);       // chair_seat_id null

        $m = VerifyStage::run($jur, (string) $run->id, 1);

        $this->assertSame('review', $m['_verdict']);
        $this->assertStringContainsString('unfilled chair', $m['_reason']);
    }

    public function test_verify_stage_flags_missing_ownership(): void
    {
        $run = $this->makeRun('running', ['civic_life']);
        $jur = $this->jurisdiction('NoOwnership');
        $this->legislature($jur, 9, 5);
        $this->executive($jur, 'delegated');
        $this->judiciary($jur, 'appointed', 5, 5);
        $this->orgWithBoard($jur, null, (string) Str::uuid());   // ownership_type null

        $m = VerifyStage::run($jur, (string) $run->id, 1);

        $this->assertSame('review', $m['_verdict']);
        $this->assertStringContainsString('no ownership structure', $m['_reason']);
    }

    public function test_verify_stage_respects_run_aspects(): void
    {
        // Elections-only run: a forming executive is NOT a gap because governance
        // was never in scope.
        $run = $this->makeRun('running', ['elections']);
        $jur = $this->jurisdiction('ElectionsOnly');
        $this->legislature($jur, 9, 5);
        $this->executive($jur, 'forming');

        $m = VerifyStage::run($jur, (string) $run->id, 1);

        $this->assertSame('done', $m['_verdict']);
    }

    // ── rollup ─────────────────────────────────────────────────────────────────

    public function test_rollup_counts_verify_outcomes(): void
    {
        $run = $this->makeRun('done');
        $a = $this->jurisdiction('A');
        $b = $this->jurisdiction('B');
        $c = $this->jurisdiction('C');
        $this->verifyItem((string) $run->id, $a, 'done');
        $this->verifyItem((string) $run->id, $b, 'done');
        $this->verifyItem((string) $run->id, $c, 'review', 'legislature seated 2/9, below the majority of 5');

        $report = app(WorldReadiness::class)->report($run);

        $this->assertSame(3, $report['verify_total']);
        $this->assertSame(2, $report['verify_done']);
        $this->assertSame(1, $report['verify_review']);
        $this->assertFalse($report['pending']);
        $this->assertFalse($report['complete']);
        $this->assertCount(1, $report['unresolved']);
        $this->assertSame('C', $report['unresolved'][0]['name']);
        $this->assertStringContainsString('below the majority', $report['unresolved'][0]['gaps']);
    }

    public function test_rollup_reports_pending_for_a_done_run_with_no_verify_items(): void
    {
        $run = $this->makeRun('done');
        $report = app(WorldReadiness::class)->report($run);

        $this->assertSame(0, $report['verify_total']);
        $this->assertTrue($report['pending']);
        $this->assertFalse($report['complete']);
    }

    public function test_rollup_reports_complete_when_all_verify_items_done(): void
    {
        $run = $this->makeRun('done');
        $this->verifyItem((string) $run->id, $this->jurisdiction('A'), 'done');

        $report = app(WorldReadiness::class)->report($run);

        $this->assertTrue($report['complete']);
        $this->assertFalse($report['pending']);
    }

    // ── the guard: completeStep5 ────────────────────────────────────────────────

    public function test_complete_step5_refuses_when_run_not_done(): void
    {
        $this->settings();
        $this->makeRun('running');

        $res = $this->completeStep5();

        $this->assertSame(422, $res->getStatusCode());
        $this->assertFalse($res->getData(true)['ok']);
    }

    public function test_complete_step5_refuses_verification_pending(): void
    {
        $this->settings();
        $this->makeRun('done');   // done, zero verify items

        $res = $this->completeStep5();
        $data = $res->getData(true);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Verification pending', $data['error']);
    }

    public function test_complete_step5_refuses_pending_even_with_force(): void
    {
        $this->settings();
        $this->makeRun('done');   // done, zero verify items

        $res = $this->completeStep5(force: true);

        $this->assertSame(422, $res->getStatusCode());
    }

    public function test_complete_step5_refuses_on_review_items(): void
    {
        $this->settings();
        $run = $this->makeRun('done');
        $this->verifyItem((string) $run->id, $this->jurisdiction('A'), 'done');
        $this->verifyItem((string) $run->id, $this->jurisdiction('B'), 'review', 'zero seats');

        $res = $this->completeStep5();

        $this->assertSame(422, $res->getStatusCode());
    }

    public function test_complete_step5_passes_with_force_and_records_the_outstanding_list(): void
    {
        $this->settings();
        $run = $this->makeRun('done');
        $this->verifyItem((string) $run->id, $this->jurisdiction('A'), 'done');
        $b = $this->jurisdiction('B');
        $this->verifyItem((string) $run->id, $b, 'review', 'legislature has zero seats (chamber not sized)');

        $res = $this->completeStep5(force: true);
        $this->assertSame(200, $res->getStatusCode());

        $notes = json_decode(DB::table('instance_settings')->value('setup_completion_notes'), true);
        $this->assertArrayHasKey('step5_verification', $notes);
        $this->assertTrue($notes['step5_verification']['forced']);
        $this->assertCount(1, $notes['step5_verification']['outstanding']);
        $this->assertSame('B', $notes['step5_verification']['outstanding'][0]['name']);
    }

    public function test_complete_step5_passes_with_zero_review(): void
    {
        $this->settings();
        $run = $this->makeRun('done');
        $this->verifyItem((string) $run->id, $this->jurisdiction('A'), 'done');

        $res = $this->completeStep5();
        $this->assertSame(200, $res->getStatusCode());

        $notes = json_decode(DB::table('instance_settings')->value('setup_completion_notes'), true);
        $this->assertArrayHasKey('step5_verification', $notes);
        $this->assertFalse($notes['step5_verification']['forced']);
    }

    // ── the guard: completeStep6 ────────────────────────────────────────────────

    public function test_complete_step6_refuses_without_the_step5_pass(): void
    {
        // Step 5 applies (eager + simulate + sandbox) but no step5_verification stamp.
        $this->settings(['setup_step_completed' => 6]);

        $res = $this->completeStep6();

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Step 5 verification', $res->getData(true)['error']);
    }

    // The completeStep6 PASS path folds DataReviewService::summary() into the
    // notes, which uses PostgreSQL-only raw SQL (`::text` casts) and the full
    // jurisdictions schema — not runnable on the sqlite fixture. The pass path
    // is exercised on box E (read-only) instead; the refuse path above is the
    // guard's DB-free contract.
}
