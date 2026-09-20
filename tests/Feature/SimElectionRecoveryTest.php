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
            // Existing-world counts were created before the tiny-chamber rule.
            // Every recovery regression must preserve this original version.
            'kind' => 'general', 'status' => 'scheduled', 'constitutional_version' => 'cv1.ac7230fe88c24e2fcd8f323f5e160b78']);
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

    private function addPopulationPanel(Legislature $leg, Election $election, ?int $population = 0): array
    {
        $child = $this->id(); $group = $this->id(); $panel = $this->id();
        DB::table('jurisdictions')->insert(['id' => $child, 'name' => 'Population ceiling fixture', 'slug' => $child,
            'parent_id' => $leg->jurisdiction_id, 'population' => $population, 'adm_level' => 7, 'created_at' => now(), 'updated_at' => now()]);
        // The ordinary fixture has an existing five-seat Type B race. Add a
        // legacy two-seat empty panel without disturbing either sealed count.
        DB::table('legislature_type_b_groupings')->insert(['id' => $group, 'legislature_id' => $leg->id, 'status' => 'active',
            'rep_floor' => 2, 'group_size' => 1, 'panel_count' => 1, 'seats_total' => 7, 'type_a_bound' => 7, 'signature' => 'unchanged-membership']);
        DB::table('legislature_type_b_panels')->insert(['id' => $panel, 'grouping_id' => $group, 'legislature_id' => $leg->id,
            'panel_number' => 1, 'seats' => 2, 'member_count' => 1, 'bonus_seats' => 0]);
        DB::table('legislature_type_b_panel_jurisdictions')->insert(['id' => $this->id(), 'grouping_id' => $group,
            'panel_id' => $panel, 'jurisdiction_id' => $child]);
        $race = ElectionRace::create(['election_id' => $election->id, 'jurisdiction_id' => $leg->jurisdiction_id,
            'seat_kind' => 'type_b', 'type_b_panel_id' => $panel, 'seats' => 2, 'finalist_count' => 8]);
        $leg->forceFill(['type_b_seats' => 7, 'total_seats' => 12])->save();
        return [$child, $group, $panel, $race];
    }

    public function test_zero_population_panel_recovers_through_real_count_and_certification_without_invented_people(): void
    {
        [$scope, $leg, $e, $a, $b, $source, $run] = $this->world(false);
        [$child, $group, $panel, $empty] = $this->addPopulationPanel($leg, $e);
        $legacyCandidate = $this->id();
        $unused = DB::table('users')->whereNotIn('id', DB::table('candidacies')->where('election_id', $e->id)->select('user_id'))->value('id');
        DB::table('candidacies')->insert(['id' => $legacyCandidate, 'election_id' => $e->id, 'race_id' => $empty->id,
            'user_id' => $unused, 'status' => 'validated', 'position_tags' => '[]', 'residency_attested_at' => now(), 'validated_at' => now()]);
        $full = DB::table('tabulations')->where('race_id', $b->id)->first();
        $oldMembers = DB::table('legislature_type_b_panel_jurisdictions')->where('grouping_id', $group)->get();
        $users = DB::table('users')->count(); $this->enable($run);
        $out = app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope]);
        self::assertSame('done', $out['_verdict'], json_encode($out));
        self::assertSame(0, (int) DB::table('legislature_type_b_panels')->where('id', $panel)->value('seats'));
        self::assertSame(5, (int) DB::table('legislature_type_b_groupings')->where('id', $group)->value('seats_total'));
        self::assertSame('unchanged-membership', DB::table('legislature_type_b_groupings')->where('id', $group)->value('signature'));
        self::assertEquals($oldMembers, DB::table('legislature_type_b_panel_jurisdictions')->where('grouping_id', $group)->get());
        self::assertTrue(ElectionRace::withTrashed()->findOrFail($empty->id)->trashed());
        self::assertSame(2, (int) ElectionRace::withTrashed()->findOrFail($empty->id)->seats, 'Keep the historical advertised seats.');
        self::assertSame(10, (int) $leg->refresh()->total_seats);
        self::assertSame(10, DB::table('legislature_members')->where('election_id', $e->id)->count());
        self::assertSame(0, DB::table('residency_confirmations')->where('jurisdiction_id', $child)->count());
        self::assertSame($users, DB::table('users')->count());
        self::assertEquals($full, DB::table('tabulations')->where('id', $full->id)->first());
        self::assertSame(0, DB::table('tabulations')->where('race_id', $empty->id)->count());
        self::assertSame($empty->id, DB::table('candidacies')->where('id', $legacyCandidate)->value('race_id'));
        self::assertSame('done', app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope])['_verdict']);
    }

    public function test_one_person_panel_recovers_legacy_zero_turnout_and_optional_challenger_without_invented_people(): void
    {
        [$scope, $leg, $e, $a, $b, $source, $run] = $this->world(false);
        [$child, $group, $panel, $race] = $this->addPopulationPanel($leg, $e, 1);
        $user = DB::table('users')->whereNotIn('id', DB::table('candidacies')->where('election_id', $e->id)->select('user_id'))->value('id');
        DB::table('residency_confirmations')->insert(['id' => $this->id(), 'jurisdiction_id' => $child, 'user_id' => $user,
            'days_confirmed' => 1, 'is_active' => true, 'confirmed_at' => now()]);
        CohortStage::run($child, null, 1, 62);
        self::assertSame(1, (int) DB::table('jurisdiction_cohorts')->where('jurisdiction_id', $child)->value('electorate'), 'Fresh worlds round positive fractional turnout to a real whole person.');
        DB::table('jurisdiction_cohorts')->where('jurisdiction_id', $child)->update(['electorate' => 0]); // legacy cohort
        $full = DB::table('tabulations')->where('race_id', $b->id)->first();
        $users = DB::table('users')->count(); $this->enable($run);
        self::assertTrue(\App\Services\Demo\SimElectorate::hasTinyPanel($e));
        $out = app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope]);
        self::assertSame('done', $out['_verdict'], json_encode($out));
        self::assertSame(1, (int) $race->refresh()->seats);
        self::assertSame(1, DB::table('candidacies')->where('race_id', $race->id)->count());
        self::assertSame($user, DB::table('candidacies')->where('race_id', $race->id)->value('user_id'));
        self::assertSame(1, DB::table('legislature_members')->where('election_id', $e->id)->where('user_id', $user)->where('seat_type', 'b')->count());
        self::assertSame(11, DB::table('legislature_members')->where('election_id', $e->id)->count());
        self::assertSame($users, DB::table('users')->count());
        self::assertSame(0, (int) DB::table('jurisdiction_cohorts')->where('jurisdiction_id', $child)->value('electorate'), 'Do not rewrite historical aggregates.');
        self::assertEquals($full, DB::table('tabulations')->where('id', $full->id)->first());
        $count = DB::table('tabulations')->where('race_id', $race->id)->first();
        self::assertSame('complete', $count->status);
        self::assertSame('done', app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope])['_verdict']);
        self::assertEquals($count, DB::table('tabulations')->where('id', $count->id)->first());
    }

    public function test_integer_turnout_keeps_zero_population_explicit_zero_turnout_and_existing_positive_counts(): void
    {
        $class = \App\Services\Demo\SimElectorate::class;
        self::assertSame(0, $class::size(0, 62));
        self::assertSame(0, $class::size(1, 0));
        self::assertSame(1, $class::size(1, 62));
        self::assertSame(1, $class::size(2, 62));
        self::assertSame(62, $class::size(100, 62));
        self::assertSame(2, $class::size(2, 200));
        self::assertSame(0, $class::fromCohort((object) ['population' => 100, 'turnout_pct' => 62, 'electorate' => 0]), 'Only round-to-zero legacy cohorts are corrected.');
        self::assertSame(3, $class::fromCohort((object) ['population' => 100, 'turnout_pct' => 62, 'electorate' => 3]));
        self::assertSame(0, $class::fromCohort((object) ['population' => 1, 'turnout_pct' => 0, 'electorate' => 0]));
    }

    public function test_tiny_electorate_retry_preserves_history_and_does_not_retry_unrelated_or_completed_items(): void
    {
        [$scope, $leg, $e, $a, $b, $source, $run] = $this->world(false);
        [$child] = $this->addPopulationPanel($leg, $e, 1);
        CohortStage::run($child, null, 1, 62);
        DB::table('jurisdiction_cohorts')->where('jurisdiction_id', $child)->update(['electorate' => 0]);
        $this->enable($run);
        $reason = 'Recovery count did not elect the full advertised field; no partial certification applied.';
        $item = $this->id();
        DB::table('sim_items')->insert(['id' => $item, 'run_id' => $run->id, 'kind' => 'repair_scope', 'unit_key' => $scope,
            'jurisdiction_id' => $scope, 'status' => 'review', 'metrics' => '{"old":true}', 'reason' => $reason]);
        $key = ['source_run_id' => $source->id, 'repair_version' => 1, 'jurisdiction_id' => $scope, 'kind' => 'election_recovery', 'target_id' => $e->id];
        DB::table('sim_repair_receipts')->insert($key + ['status' => 'blocked', 'result' => json_encode(['reason' => $reason, 'exception' => 'RuntimeException'])]);
        $service = app(\App\Services\Demo\SimRepairReceiptRecovery::class);
        try { $service->retryPopulationCeiling($run, [$scope], true); self::fail('Must halt first'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('drained, halted', $error->getMessage()); }
        $run->forceFill(['status' => 'halted', 'halt_requested_at' => now()])->save();
        self::assertSame([], $service->retryPopulationCeiling($run, [$this->id()], true)['retried']);
        self::assertSame([$scope], $service->retryPopulationCeiling($run, [$scope], true)['retried']);
        self::assertSame([], $service->retryPopulationCeiling($run, [$scope], true)['retried']);
        self::assertSame('deferred', DB::table('sim_repair_receipts')->where($key)->value('status'));
        self::assertStringContainsString($reason, DB::table('sim_repair_receipts')->where($key)->value('result'));
        self::assertSame('pending', DB::table('sim_items')->where('id', $item)->value('status'));
    }

    public function test_small_panel_uses_population_and_frozen_finalist_multiplier_and_unknown_is_not_zero(): void
    {
        [$scope, $leg, $e, $a, $b, $source, $run] = $this->world(false);
        [$child, $group, $panel, $race] = $this->addPopulationPanel($leg, $e, null);
        $service = app(\App\Services\Demo\SimPopulationCeiling::class);
        self::assertSame([], $service->reconcile($e, $source->id));
        self::assertSame(2, (int) $race->refresh()->seats);
        DB::table('jurisdictions')->where('id', $child)->update(['population' => 1]);
        self::assertCount(1, $service->reconcile($e, $source->id));
        self::assertSame(1, (int) $race->refresh()->seats);
        self::assertSame(4, (int) $race->finalist_count, 'Use the frozen four-per-seat rule, not a hardcoded three.');
        self::assertSame(11, (int) $leg->refresh()->total_seats);
        self::assertSame([], $service->reconcile($e, $source->id));
        self::assertSame(0, DB::table('residency_confirmations')->where('jurisdiction_id', $child)->count());
    }

    public function test_later_recovery_failure_rolls_back_population_correction_and_preserves_counts(): void
    {
        [$scope, $leg, $e, $a, $b, $source, $run] = $this->world(false);
        [$child, $group, $panel, $race] = $this->addPopulationPanel($leg, $e); $this->enable($run);
        $audit = DB::table('audit_log')->count(); $counts = DB::table('tabulations')->orderBy('id')->get();
        try {
            app(SimElectionRecovery::class)->recover($run, $e->id, function () use ($race) {
                if (ElectionRace::withTrashed()->find($race->id)->trashed()) { throw new \RuntimeException('fixture after ceiling'); }
            }); self::fail('Expected interruption');
        } catch (\RuntimeException $error) { self::assertSame('fixture after ceiling', $error->getMessage()); }
        self::assertFalse($race->refresh()->trashed());
        self::assertSame(12, (int) $leg->refresh()->total_seats);
        self::assertSame(2, (int) DB::table('legislature_type_b_panels')->where('id', $panel)->value('seats'));
        self::assertSame($audit, DB::table('audit_log')->count());
        self::assertEquals($counts, DB::table('tabulations')->orderBy('id')->get());
    }

    public function test_population_retry_is_scoped_halted_idempotent_and_retains_failure_history(): void
    {
        [$scope, $leg, $e, $a, $b, $source, $run] = $this->world(false);
        $this->addPopulationPanel($leg, $e); $this->enable($run);
        $item = $this->id(); $reason = 'Recovery count did not elect the full advertised field; no partial certification applied.';
        DB::table('sim_items')->insert(['id' => $item, 'run_id' => $run->id, 'kind' => 'repair_scope', 'unit_key' => $scope,
            'jurisdiction_id' => $scope, 'status' => 'review', 'metrics' => '{"old":true}', 'reason' => $reason]);
        $key = ['source_run_id' => $source->id, 'repair_version' => 1, 'jurisdiction_id' => $scope, 'kind' => 'election_recovery', 'target_id' => $e->id];
        DB::table('sim_repair_receipts')->insert($key + ['status' => 'blocked', 'result' => json_encode(['reason' => $reason, 'exception' => 'RuntimeException'])]);
        $service = app(\App\Services\Demo\SimRepairReceiptRecovery::class);
        try { $service->retryPopulationCeiling($run, [$scope]); self::fail('Running retry forbidden'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('drained, halted', $error->getMessage()); }
        $run->forceFill(['status' => 'halted', 'halt_requested_at' => now()])->save();
        self::assertSame([], $service->retryPopulationCeiling($run, [$this->id()])['retried']);
        self::assertSame([$scope], $service->retryPopulationCeiling($run, [$scope])['retried']);
        self::assertSame([], $service->retryPopulationCeiling($run, [$scope])['retried']);
        self::assertSame('pending', DB::table('sim_items')->where('id', $item)->value('status'));
        self::assertSame('deferred', DB::table('sim_repair_receipts')->where($key)->value('status'));
        $run->forceFill(['status' => 'running', 'halt_requested_at' => null])->save();
        self::assertSame('done', app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope])['_verdict']);
        $receipt = DB::table('sim_repair_receipts')->where($key)->first();
        self::assertSame('applied', $receipt->status);
        self::assertStringContainsString($reason, $receipt->result);
    }

    public function test_ceiling_correction_refuses_existing_counts_and_certified_terms(): void
    {
        [$scope, $leg, $e, $a, $b, $source, $run] = $this->world(false);
        [$child, $group, $panel, $race] = $this->addPopulationPanel($leg, $e);
        DB::table('tabulations')->where('race_id', $a->id)->update(['race_id' => $race->id]);
        $service = app(\App\Services\Demo\SimPopulationCeiling::class);
        try { $service->reconcile($e, $source->id); self::fail('A count must be preserved'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('cannot rewrite an existing count', $error->getMessage()); }
        self::assertFalse($race->refresh()->trashed());
        self::assertSame(12, (int) $leg->refresh()->total_seats);
        $e->forceFill(['status' => 'certified'])->save();
        self::assertSame([], $service->reconcile($e, $source->id));
        self::assertSame(2, (int) DB::table('legislature_type_b_panels')->where('id', $panel)->value('seats'));
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

    public function test_committed_single_resident_retires_only_the_empty_uncounted_panel_and_resumes_same_terminal_run(): void
    {
        [$scope, $leg, $e, $a, $b, $source, $run] = $this->world(false);
        [$child, $group, $panel, $race] = $this->addPopulationPanel($leg, $e, 1);
        $used = DB::table('candidacies')->where('race_id', $b->id)->value('user_id');
        DB::table('residency_confirmations')->insert(['id' => $this->id(), 'jurisdiction_id' => $child,
            'user_id' => $used, 'is_active' => true, 'days_confirmed' => 1, 'confirmed_at' => now()]);
        CohortStage::run($child, null, 1, 62);
        $this->enable($run);
        $run->forceFill(['status' => 'done', 'phase' => 'done', 'finished_at' => now(),
            'phase_timings' => ['repairing' => ['worklist' => ['complete' => true]]], 'items_review' => 1, 'open_items' => 0])->save();
        $reason = 'Distinct eligible candidate pool exhausted in '.$child.': 0 available, 1 missing; existing candidacies preserved.';
        DB::table('sim_items')->insert(['id' => $this->id(), 'run_id' => $run->id, 'kind' => 'repair_scope',
            'unit_key' => $scope, 'jurisdiction_id' => $scope, 'status' => 'review', 'reason' => $reason, 'position' => 0]);
        DB::table('sim_repair_receipts')->insert(['source_run_id' => $source->id, 'repair_version' => 1, 'jurisdiction_id' => $scope,
            'kind' => 'election_recovery', 'target_id' => $e->id, 'status' => 'blocked', 'result' => json_encode(['exception' => 'RuntimeException', 'reason' => $reason])]);
        $old = DB::table('tabulations')->where('race_id', $b->id)->first();
        $candidates = DB::table('candidacies')->where('election_id', $e->id)->orderBy('id')->get();
        $people = DB::table('users')->count();
        self::assertSame(0, \Illuminate\Support\Facades\Artisan::call('sim:repair', ['--retry-distinct-capacity' => $run->id, '--scope' => [$scope]]));
        self::assertSame([$scope], json_decode(\Illuminate\Support\Facades\Artisan::output(), true)['retried']);
        self::assertSame('halted', $run->refresh()->status); self::assertSame('repairing', $run->phase);
        self::assertSame([], app(\App\Services\Demo\SimRepairReceiptRecovery::class)->retryPopulationCeiling($run, [$scope], distinctCapacity: true)['retried']);
        $run->forceFill(['status' => 'running', 'halt_requested_at' => null])->save();
        $out = app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope]);
        self::assertSame('done', $out['_verdict'], json_encode($out));
        self::assertSame(10, (int) $leg->refresh()->total_seats);
        self::assertSame(0, (int) DB::table('legislature_type_b_panels')->where('id', $panel)->value('seats'));
        self::assertTrue($race->refresh()->trashed());
        self::assertEquals($old, DB::table('tabulations')->where('id', $old->id)->first());
        self::assertSame($people, DB::table('users')->count());
        foreach ($candidates as $c) { self::assertSame($c->race_id, DB::table('candidacies')->where('id', $c->id)->value('race_id')); }
        self::assertSame(10, DB::table('legislature_members')->where('legislature_id', $leg->id)->count());
    }

    public function test_certified_capacity_retires_only_unfillable_slots_and_keeps_every_officeholder_term_and_count(): void
    {
        [$scope, $leg, $e, $a, $b, $source, $run] = $this->world(false);
        [$child, $group, $panel, $race] = $this->addPopulationPanel($leg, $e, 2);
        foreach (DB::table('candidacies')->where('race_id', $b->id)->limit(2)->pluck('user_id') as $used) {
            DB::table('residency_confirmations')->insert(['id' => $this->id(), 'jurisdiction_id' => $child,
                'user_id' => $used, 'is_active' => true, 'days_confirmed' => 1, 'confirmed_at' => now()]);
        }
        CohortStage::run($child, null, 1, 62);
        $candidate = DB::table('users')->whereNotIn('id', DB::table('candidacies')->where('election_id', $e->id)->select('user_id'))->value('id');
        DB::table('candidacies')->insert(['id' => $this->id(), 'election_id' => $e->id, 'race_id' => $race->id,
            'user_id' => $candidate, 'status' => 'validated', 'position_tags' => '[]', 'residency_attested_at' => now(), 'validated_at' => now()]);
        CountingStage::run($e->id, $source->id, 1); self::assertTrue(SeatingStage::run($e->id, $source->id, 1)['certified']);
        $this->enable($run);
        $members = DB::table('legislature_members')->where('legislature_id', $leg->id)->orderBy('id')->get();
        $terms = DB::table('terms')->where('legislature_id', $leg->id)->orderBy('id')->get();
        $counts = DB::table('tabulations')->whereIn('race_id', [$a->id, $b->id, $race->id])->orderBy('id')->get();
        $original = DB::table('election_races')->where('id', $race->id)->first();
        $certification = DB::table('election_certifications')->where('election_id', $e->id)->get();
        $beforePeople = DB::table('users')->count();
        $beforeAudit = DB::table('audit_log')->count(); $interrupted = false;
        try {
            app(SimElectionRecovery::class)->recover($run, $e->id, function () use ($panel, &$interrupted) {
                if ((int) DB::table('legislature_type_b_panels')->where('id', $panel)->value('seats') === 1) {
                    $interrupted = true; throw new \RuntimeException('after capacity correction');
                }
            });
            self::fail('Expected interruption after capacity correction.');
        } catch (\RuntimeException $error) { self::assertSame('after capacity correction', $error->getMessage()); }
        self::assertTrue($interrupted);
        self::assertSame(2, (int) DB::table('legislature_type_b_panels')->where('id', $panel)->value('seats'));
        self::assertSame(12, (int) $leg->refresh()->total_seats);
        self::assertSame($beforeAudit, DB::table('audit_log')->count());
        self::assertSame(0, DB::table('vacancies')->where('legislature_id', $leg->id)->count());
        $out = app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope]);
        self::assertSame('done', $out['_verdict'], json_encode($out));
        self::assertSame(11, (int) $leg->refresh()->total_seats);
        self::assertSame(1, (int) DB::table('legislature_type_b_panels')->where('id', $panel)->value('seats'));
        self::assertEquals($original, DB::table('election_races')->where('id', $race->id)->first());
        self::assertEquals($counts, DB::table('tabulations')->whereIn('id', $counts->pluck('id'))->orderBy('id')->get());
        self::assertEquals($members, DB::table('legislature_members')->whereIn('id', $members->pluck('id'))->orderBy('id')->get());
        self::assertEquals($terms, DB::table('terms')->whereIn('id', $terms->pluck('id'))->orderBy('id')->get());
        self::assertEquals($certification, DB::table('election_certifications')->where('election_id', $e->id)->get());
        self::assertSame(11, DB::table('legislature_members')->where('legislature_id', $leg->id)->distinct()->count('user_id'));
        self::assertSame($beforePeople, DB::table('users')->count());
        $audit = DB::table('audit_log')->count();
        self::assertSame('done', app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope])['_verdict']);
        self::assertSame($audit, DB::table('audit_log')->count());
    }

    public function test_supplements_reserve_the_last_small_pool_resident_before_filling_a_broad_contest(): void
    {
        [$scope, $leg, $e, $a, $b, $source, $run] = $this->world(false);
        [$child, $group, $panel, $race] = $this->addPopulationPanel($leg, $e, 2);
        $needed = '00000000-0000-4000-8000-000000000001';
        DB::table('users')->insert(['id' => $needed, 'name' => 'Scarce resident', 'email' => 'scarce@example.test', 'password' => 'unused', 'terms_accepted_at' => now()]);
        foreach ([$scope, $child] as $place) DB::table('residency_confirmations')->insert(['id' => $this->id(),
            'jurisdiction_id' => $place, 'user_id' => $needed, 'is_active' => true, 'days_confirmed' => 1, 'confirmed_at' => now()]);
        CohortStage::run($child, null, 1, 62);
        $candidate = DB::table('users')->where('id', '!=', $needed)->whereNotIn('id', DB::table('candidacies')->where('election_id', $e->id)->select('user_id'))->value('id');
        DB::table('candidacies')->insert(['id' => $this->id(), 'election_id' => $e->id, 'race_id' => $race->id,
            'user_id' => $candidate, 'status' => 'validated', 'position_tags' => '[]', 'residency_attested_at' => now(), 'validated_at' => now()]);
        CountingStage::run($e->id, $source->id, 1); self::assertTrue(SeatingStage::run($e->id, $source->id, 1)['certified']);
        $this->enable($run);
        self::assertSame('done', app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope])['_verdict']);
        $vacancy = DB::table('vacancies')->where('seat_id', $race->id)->where('seat_type', 'election_races')->first();
        self::assertNotNull($vacancy); self::assertSame('filled', $vacancy->status);
        $special = Election::where('vacancy_id', $vacancy->id)->firstOrFail();
        self::assertSame($needed, DB::table('legislature_members')->where('election_id', $special->id)->value('user_id'));
        self::assertSame(12, (int) $leg->refresh()->total_seats, 'Do not shrink a fillable chamber.');
        self::assertSame(12, DB::table('legislature_members')->where('legislature_id', $leg->id)->distinct()->count('user_id'));
    }
}
