<?php

namespace Tests\Feature;

use App\Models\{Election, ElectionBoard, ElectionBoardMember, ElectionRace, Legislature, SimRun};
use App\Services\Demo\{SimElectionRecovery, SimRepairControl, SimRepairInspector, SimRepairService, SimRunControl};
use App\Services\Demo\Stages\{CohortStage, CountingStage, SeatingStage};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\DisposableRepairWorld;
use Tests\TestCase;

class SimElectionRecoveryTest extends TestCase
{
    use DisposableRepairWorld;

    protected function setUp(): void
    {
        parent::setUp(); $this->openRepairWorld();
        config(['cache.default' => 'array', 'queue.default' => 'sync']);
        $guard = $this->createMock(SimRunControl::class); $guard->method('refusalReason')->willReturn(null);
        $this->app->instance(SimRunControl::class, $guard);
    }
    protected function tearDown(): void { $this->closeRepairWorld(); parent::tearDown(); }
    private function id(): string { return (string) Str::uuid(); }

    private function world(bool $certified): array
    {
        $scope = $this->id();
        DB::table('jurisdictions')->insert(['id' => $scope, 'name' => 'Private recovery fixture', 'slug' => $scope,
            'population' => 1000, 'adm_level' => 6, 'created_at' => now(), 'updated_at' => now()]);
        $leg = Legislature::create(['jurisdiction_id' => $scope, 'status' => 'forming', 'term_number' => 1,
            'total_seats' => 10, 'type_a_seats' => 5, 'type_b_seats' => 5, 'quorum_required' => 6]);
        $board = ElectionBoard::create(['jurisdiction_id' => $scope, 'is_bootstrap' => true, 'status' => 'active']);
        ElectionBoardMember::create(['election_board_id' => $board->id, 'user_id' => null, 'status' => 'seated']);
        $e = Election::create(['jurisdiction_id' => $scope, 'legislature_id' => $leg->id, 'election_board_id' => $board->id,
            'kind' => 'general', 'status' => 'scheduled']);
        $a = ElectionRace::create(['election_id' => $e->id, 'jurisdiction_id' => $scope, 'seat_kind' => 'type_a', 'seats' => 5, 'finalist_count' => 15]);
        $b = ElectionRace::create(['election_id' => $e->id, 'jurisdiction_id' => $scope, 'seat_kind' => 'type_b', 'seats' => 5, 'finalist_count' => 15]);
        for ($i = 0; $i < 20; $i++) {
            $user = $this->id();
            DB::table('users')->insert(['id' => $user, 'name' => 'Resident', 'email' => $user.'@example.test', 'password' => 'unused', 'terms_accepted_at' => now()]);
            DB::table('residency_confirmations')->insert(['id' => $this->id(), 'jurisdiction_id' => $scope, 'user_id' => $user,
                'days_confirmed' => 1, 'is_active' => true, 'confirmed_at' => now()]);
            $field = $certified ? 6 : 8;
            if ($i < $field) DB::table('candidacies')->insert(['id' => $this->id(), 'election_id' => $e->id,
                'race_id' => $i < 3 ? $a->id : $b->id, 'user_id' => $user, 'status' => 'validated',
                'position_tags' => '[]', 'residency_attested_at' => now(), 'validated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        CohortStage::run($scope, null, 1, 62);
        $source = SimRun::create(['status' => 'done', 'phase' => 'done', 'options' => ['scope_aspects' => ['elections'], 'no_floor' => true], 'phase_timings' => []]);
        foreach (['verify_scope', 'election_scope'] as $kind) DB::table('sim_items')->insert(['id' => $this->id(), 'run_id' => $source->id,
            'kind' => $kind, 'unit_key' => $scope, 'jurisdiction_id' => $scope, 'status' => 'done', 'position' => 1,
            'metrics' => json_encode(['election_id' => $e->id]), 'created_at' => now(), 'updated_at' => now()]);
        CountingStage::run($e->id, $source->id, 1);
        if ($certified) { self::assertTrue(SeatingStage::run($e->id, $source->id, 1)['certified']); }
        $run = app(SimRepairControl::class)->start($source->id);
        return [$scope, $leg, $e, $a, $b, $source, $run];
    }

    private function enable(SimRun $run): void
    {
        $options = $run->options; $options['repair_election_recovery'] = true; $options['repair_apply_authorized'] = true;
        $run->forceFill(['options' => $options, 'phase' => 'repairing', 'status' => 'running'])->save();
    }

    public function test_certified_missing_seats_get_real_special_elections_without_changing_prior_officeholders_or_terms(): void
    {
        [$scope, $leg, $e, $a, $b, $source, $run] = $this->world(true);
        $oldMembers = DB::table('legislature_members')->where('legislature_id', $leg->id)->orderBy('id')->get();
        $oldTerms = DB::table('terms')->where('legislature_id', $leg->id)->orderBy('id')->get();
        $original = DB::table('elections')->where('id', $e->id)->first();
        $oldCounts = DB::table('tabulations')->whereIn('race_id', [$a->id, $b->id])->orderBy('id')->get();
        self::assertCount(6, $oldMembers);
        $this->enable($run);
        $out = app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope]);
        self::assertSame('done', $out['_verdict'], json_encode($out));
        self::assertEquals($oldMembers, DB::table('legislature_members')->whereIn('id', $oldMembers->pluck('id'))->orderBy('id')->get());
        self::assertEquals($oldTerms, DB::table('terms')->whereIn('id', $oldTerms->pluck('id'))->orderBy('id')->get());
        self::assertEquals($original, DB::table('elections')->where('id', $e->id)->first());
        self::assertEquals($oldCounts, DB::table('tabulations')->whereIn('race_id', [$a->id, $b->id])->orderBy('id')->get());
        $members = DB::table('legislature_members')->where('legislature_id', $leg->id)->get();
        self::assertCount(10, $members); self::assertCount(10, $members->unique('user_id'));
        foreach (['a', 'b'] as $kind) self::assertSame([1,2,3,4,5], $members->where('seat_type', $kind)->pluck('seat_no')->sort()->values()->all());
        self::assertCount(1, $members->pluck('term_ends_on')->unique());
        self::assertSame(4, DB::table('vacancies')->where('legislature_id', $leg->id)->where('status', 'filled')->count());
        self::assertSame(4, Election::where('legislature_id', $leg->id)->where('kind', 'special')->where('status', 'certified')->count());
        $counts = DB::table('tabulations')->count(); $terms = DB::table('terms')->count();
        self::assertSame('done', app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope])['_verdict']);
        self::assertSame([], app(SimElectionRecovery::class)->recover($run, $e->id)['specials']);
        self::assertSame($counts, DB::table('tabulations')->count()); self::assertSame($terms, DB::table('terms')->count());
    }

    public function test_unfinished_deficient_counts_are_preserved_and_replaced_while_full_count_is_retained(): void
    {
        [$scope, $leg, $e, $a, $b, $source, $run] = $this->world(false);
        // Earth's shape: an uncounted race alongside deficient and adequate
        // complete counts. Only the first two require new count records.
        $district = $this->id();
        DB::table('legislature_districts')->insert(['id' => $district, 'legislature_id' => $leg->id, 'jurisdiction_id' => $scope,
            'district_number' => 1, 'seats' => 5, 'target_population' => 1000, 'actual_population' => 1000]);
        $empty = ElectionRace::create(['election_id' => $e->id, 'jurisdiction_id' => $scope, 'district_id' => $district,
            'seat_kind' => 'type_a', 'seats' => 5, 'finalist_count' => 15]);
        $leg->forceFill(['total_seats' => 15, 'type_a_seats' => 10])->save();
        $full = DB::table('tabulations')->where('race_id', $b->id)->first();
        $deficient = DB::table('tabulations')->where('race_id', $a->id)->first();
        $rounds = DB::table('tabulation_rounds')->where('tabulation_id', $deficient->id)->orderBy('id')->get();
        $results = DB::table('race_results')->where('tabulation_id', $deficient->id)->orderBy('id')->get();
        $candidates = DB::table('candidacies')->where('election_id', $e->id)->pluck('id')->all();
        $this->enable($run);
        $out = app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope]);
        self::assertSame('done', $out['_verdict'], json_encode($out));
        self::assertEquals($full, DB::table('tabulations')->where('id', $full->id)->first());
        $now = DB::table('tabulations')->where('id', $deficient->id)->first();
        self::assertSame('superseded', $now->status); self::assertSame($deficient->record_hash, $now->record_hash);
        self::assertEquals($rounds, DB::table('tabulation_rounds')->where('tabulation_id', $deficient->id)->orderBy('id')->get());
        self::assertEquals($results, DB::table('race_results')->where('tabulation_id', $deficient->id)->orderBy('id')->get());
        self::assertSame(count($candidates), DB::table('candidacies')->whereIn('id', $candidates)->count());
        self::assertSame(15, DB::table('legislature_members')->where('election_id', $e->id)->count());
        self::assertSame(4, DB::table('tabulations')->whereIn('race_id', [$a->id, $b->id, $empty->id])->count());
        self::assertSame('done', app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope])['_verdict']);
    }

    public function test_interruption_after_superseding_rolls_back_history_fields_and_all_new_candidates(): void
    {
        [$scope, $leg, $e, $a, $b, $source, $run] = $this->world(false); $this->enable($run);
        $counts = DB::table('tabulations')->whereIn('race_id', [$a->id, $b->id])->orderBy('id')->get();
        $candidates = DB::table('candidacies')->where('election_id', $e->id)->orderBy('id')->get();
        $before = DB::table('audit_log')->count(); $hit = false;
        try {
            app(SimElectionRecovery::class)->recover($run, $e->id, function () use (&$hit, $a) {
                if (DB::table('tabulations')->where('race_id', $a->id)->where('status', 'superseded')->exists()) {
                    $hit = true; throw new \RuntimeException('fixture interruption');
                }
            }); self::fail('Expected interruption');
        } catch (\RuntimeException $error) { self::assertSame('fixture interruption', $error->getMessage()); }
        self::assertTrue($hit);
        self::assertEquals($counts, DB::table('tabulations')->whereIn('race_id', [$a->id, $b->id])->orderBy('id')->get());
        self::assertEquals($candidates, DB::table('candidacies')->where('election_id', $e->id)->orderBy('id')->get());
        self::assertSame($before, DB::table('audit_log')->count());
    }

    public function test_authorization_reclassifies_the_same_halted_inventory_and_preserves_blocked_receipts(): void
    {
        [$scope, $leg, $e, $a, $b, $source, $run] = $this->world(true);
        $control = app(SimRepairControl::class); $control->enumerate($run);
        $plan = app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope], planning: true);
        self::assertSame('blocked_recovery', $plan['category']);
        $id = DB::table('sim_items')->where('run_id', $run->id)->value('id');
        DB::table('sim_items')->where('id', $id)->update(['status' => 'done', 'metrics' => json_encode($plan)]);
        $run->refresh()->forceFill(['status' => 'halted', 'halt_requested_at' => now()])->save();
        $control->enableElectionRecovery($run); $control->enableElectionRecovery($run);
        self::assertFalse($control->report($run->refresh())['plan_complete']);
        $result = $control->refreshInventory($run); self::assertSame(1, $result['refreshed']);
        self::assertSame(1, $control->report($run->refresh())['summary']['categories']['election_recovery']);
        self::assertSame($id, DB::table('sim_items')->where('run_id', $run->id)->value('id'));
        self::assertSame(['already_current' => true], $control->refreshInventory($run));
        $control->apply($run);
        $out = app(SimRepairService::class)->run($run->refresh(), (object) ['jurisdiction_id' => $scope]);
        self::assertSame('done', $out['_verdict'], json_encode($out));
    }

    public function test_recovery_requires_explicit_run_authorization(): void
    {
        [$scope, $leg, $e, $a, $b, $source, $run] = $this->world(false);
        $this->expectExceptionMessage('has not been enabled');
        app(SimElectionRecovery::class)->recover($run, $e->id);
    }

    public function test_surplus_type_a_members_cannot_hide_missing_type_b_seats_from_recovery(): void
    {
        [$scope, $leg, $e, $a, $b, $source, $run] = $this->world(true);
        // Reproduce the live shape: aggregate serving count meets the stored
        // target, but one chamber remains short. Existing certified winners
        // still belong to their original race and cannot cover the other kind.
        $leg->forceFill(['total_seats' => 6, 'type_a_seats' => 1, 'type_b_seats' => 5])->save();
        $this->enable($run);
        $plan = app(SimRepairInspector::class)->inspect($run, $scope);
        self::assertSame(6, $plan['verification']['seated']);
        self::assertFalse($plan['institution_ready']);
        self::assertContains(['kind' => 'election_recovery', 'target' => $e->id], $plan['actions']);
        $original = DB::table('legislature_members')->where('legislature_id', $leg->id)->orderBy('id')->get();
        $out = app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope]);
        self::assertSame('done', $out['_verdict'], json_encode($out));
        self::assertEquals($original, DB::table('legislature_members')->whereIn('id', $original->pluck('id'))->orderBy('id')->get());
        self::assertSame(5, DB::table('legislature_members')->where('legislature_id', $leg->id)->where('seat_type', 'b')->count());
    }

    public function test_already_applied_run_requeues_only_election_reviews_and_retains_the_old_blocked_receipt(): void
    {
        [$scope, $leg, $e, $a, $b, $source, $run] = $this->world(false);
        $options = $run->options; $options['repair_apply_authorized'] = true;
        $run->forceFill(['options' => $options, 'phase' => 'repairing', 'status' => 'halted', 'halt_requested_at' => now()])->save();
        $review = $this->id(); $done = $this->id(); $pending = $this->id();
        foreach ([$review => 'review', $done => 'done', $pending => 'pending'] as $id => $status) {
            DB::table('sim_items')->insert(['id' => $id, 'run_id' => $run->id, 'kind' => 'repair_scope', 'unit_key' => $id,
                'jurisdiction_id' => $scope, 'status' => $status, 'position' => 1, 'metrics' => '{"old":true}',
                'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('sim_repair_receipts')->insert(['source_run_id' => $source->id, 'repair_version' => 1, 'jurisdiction_id' => $scope,
            'kind' => 'election', 'target_id' => $e->id, 'status' => 'blocked', 'result' => '{"reason":"old protected count"}',
            'created_at' => now(), 'updated_at' => now()]);
        $oldReceipt = DB::table('sim_repair_receipts')->where('target_id', $e->id)->first();
        $oldDone = DB::table('sim_items')->where('id', $done)->first(); $oldPending = DB::table('sim_items')->where('id', $pending)->first();
        $otherScope = $this->id(); $otherReview = $this->id();
        DB::table('sim_items')->insert(['id' => $otherReview, 'run_id' => $run->id, 'kind' => 'repair_scope',
            'unit_key' => $otherScope, 'jurisdiction_id' => $otherScope, 'status' => 'review', 'position' => 1,
            'metrics' => '{"unrelated":true}', 'reason' => 'Keep unrelated review intact', 'created_at' => now(), 'updated_at' => now()]);
        $oldOtherReview = DB::table('sim_items')->where('id', $otherReview)->first();
        $control = app(SimRepairControl::class);
        self::assertSame(1, $control->enableElectionRecovery($run, [$scope])['reviews_requeued']);
        self::assertSame(0, $control->enableElectionRecovery($run, [$scope])['reviews_requeued']);
        self::assertEquals($oldOtherReview, DB::table('sim_items')->where('id', $otherReview)->first());
        self::assertEquals($oldDone, DB::table('sim_items')->where('id', $done)->first());
        self::assertEquals($oldPending, DB::table('sim_items')->where('id', $pending)->first());
        $run->refresh()->forceFill(['status' => 'running', 'halt_requested_at' => null])->save();
        $out = app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope]);
        self::assertSame('done', $out['_verdict'], json_encode($out));
        self::assertEquals($oldReceipt, DB::table('sim_repair_receipts')->where('target_id', $e->id)->where('kind', 'election')->first());
        self::assertSame('applied', DB::table('sim_repair_receipts')->where('target_id', $e->id)->where('kind', 'election_recovery')->value('status'));
    }

    public function test_failed_second_supplement_rolls_back_the_first_and_leaves_original_certification_intact(): void
    {
        [$scope, $leg, $e, $a, $b, $source, $run] = $this->world(true); $this->enable($run);
        $before = DB::table('legislature_members')->where('legislature_id', $leg->id)->orderBy('id')->get();
        $terms = DB::table('terms')->count(); $audit = DB::table('audit_log')->count(); $hit = false;
        try {
            app(SimElectionRecovery::class)->recover($run, $e->id, function () use (&$hit, $leg) {
                if (DB::table('vacancies')->where('legislature_id', $leg->id)->where('status', 'filled')->exists()) {
                    $hit = true; throw new \RuntimeException('fixture after first supplement');
                }
            }); self::fail('Expected interruption');
        } catch (\RuntimeException $error) { self::assertSame('fixture after first supplement', $error->getMessage()); }
        self::assertTrue($hit);
        self::assertEquals($before, DB::table('legislature_members')->where('legislature_id', $leg->id)->orderBy('id')->get());
        self::assertSame($terms, DB::table('terms')->count()); self::assertSame($audit, DB::table('audit_log')->count());
        self::assertSame(0, DB::table('vacancies')->where('legislature_id', $leg->id)->count());
        self::assertSame(0, Election::where('legislature_id', $leg->id)->where('kind', 'special')->count());
        self::assertSame('certified', $e->refresh()->status);
    }

    public function test_exhausted_resident_pool_cannot_manufacture_a_second_seat_for_a_serving_member(): void
    {
        [$scope, $leg, $e, $a, $b, $source, $run] = $this->world(true); $this->enable($run);
        $serving = DB::table('legislature_members')->where('legislature_id', $leg->id)->pluck('user_id');
        DB::table('residency_confirmations')->where('jurisdiction_id', $scope)->whereNotIn('user_id', $serving)->delete();
        $before = DB::table('legislature_members')->where('legislature_id', $leg->id)->orderBy('id')->get();
        try { app(SimElectionRecovery::class)->recover($run, $e->id); self::fail('No eligible pool remains'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('pool exhausted', $error->getMessage()); }
        self::assertEquals($before, DB::table('legislature_members')->where('legislature_id', $leg->id)->orderBy('id')->get());
        self::assertSame(0, DB::table('vacancies')->where('legislature_id', $leg->id)->count());
    }
}
