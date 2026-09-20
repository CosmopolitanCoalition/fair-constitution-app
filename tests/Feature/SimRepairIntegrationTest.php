<?php

namespace Tests\Feature;

use App\Models\{Election, Legislature, LegislatureMember, SimRun};
use App\Services\Demo\{SimCandidateField, SimRepairControl, SimRepairInspector, SimRepairService, SimRunControl};
use App\Services\Demo\Stages\{GovernanceStage, JudiciaryStage};
use App\Support\WorldReadiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\DisposableRepairWorld;
use Tests\TestCase;

class SimRepairIntegrationTest extends TestCase
{
    use DisposableRepairWorld;

    protected function setUp(): void
    {
        parent::setUp(); $this->openRepairWorld();
        config(['cache.default' => 'array', 'queue.default' => 'sync']);
        $guard = $this->createMock(SimRunControl::class);
        $guard->method('refusalReason')->willReturn(null);
        $guard->method('activeRun')->willReturnCallback(fn () => SimRun::whereIn('status', ['queued','running','halted'])->first());
        $this->app->instance(SimRunControl::class, $guard);
    }

    protected function tearDown(): void { $this->closeRepairWorld(); parent::tearDown(); }

    private function id(): string { return (string) Str::uuid(); }
    private function place(int $level = 6): string
    {
        $id = $this->id(); DB::table('jurisdictions')->insert(['id' => $id, 'name' => 'Private repair test', 'slug' => $id,
            'adm_level' => $level, 'population' => 1000, 'created_at' => now(), 'updated_at' => now()]); return $id;
    }
    private function person(): string
    {
        $id = $this->id(); DB::table('users')->insert(['id' => $id, 'name' => 'Fixture', 'email' => $id.'@example.test',
            'password' => 'unused', 'terms_accepted_at' => now(), 'created_at' => now(), 'updated_at' => now()]); return $id;
    }
    private function election(string $scope): string
    {
        return Election::create(['jurisdiction_id' => $scope, 'kind' => 'general', 'status' => 'scheduled', 'cycle_number' => 1])->id;
    }
    private function race(string $election, string $scope, int $number): string
    {
        $id = $this->id(); DB::table('election_races')->insert(['id' => $id, 'election_id' => $election,
            'jurisdiction_id' => $scope, 'seat_kind' => $number === 1 ? 'type_a' : 'type_b', 'seats' => 2, 'finalist_count' => 6,
            'created_at' => now(), 'updated_at' => now()]); return $id;
    }
    private function resident(string $scope, string $user): void
    {
        DB::table('residency_confirmations')->insert(['id' => $this->id(), 'jurisdiction_id' => $scope,
            'user_id' => $user, 'days_confirmed' => 1, 'is_active' => true, 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
    private function source(array $scopes): SimRun
    {
        $run = SimRun::create(['status' => 'done', 'phase' => 'done', 'options' => ['scope_aspects' => ['elections'], 'no_floor' => true], 'phase_timings' => []]);
        foreach ($scopes as $scope) DB::table('sim_items')->insert(['id' => $this->id(), 'run_id' => $run->id, 'kind' => 'verify_scope',
            'unit_key' => $scope, 'jurisdiction_id' => $scope, 'status' => 'review', 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return $run;
    }

    public function test_cross_scope_candidate_collisions_are_filled_without_duplicate_or_changed_assignments(): void
    {
        $parent = $this->place(0); $child = $this->place(6); $e = $this->election($parent);
        $a = $this->race($e, $parent, 1); $b = $this->race($e, $child, 2);
        for ($i = 0; $i < 6; $i++) {
            $user = $this->person(); $this->resident($parent, $user); if ($i < 3) $this->resident($child, $user);
        }
        $service = app(SimCandidateField::class);
        self::assertSame(6, $service->fill($e, null, 1, noFloor: true)['candidacies']);
        self::assertSame(3, DB::table('candidacies')->where('race_id', $a)->count());
        self::assertSame(3, DB::table('candidacies')->where('race_id', $b)->count());
        $saved = DB::table('candidacies')->where('election_id', $e)->orderBy('id')->get()->toArray();
        $service->fill($e, null, 1, noFloor: true);
        self::assertEquals($saved, DB::table('candidacies')->where('election_id', $e)->orderBy('id')->get()->toArray());
        self::assertSame(6, DB::table('candidacies')->where('election_id', $e)->distinct()->count('user_id'));
    }

    public function test_candidate_repair_preserves_partial_fields_and_refuses_certified_elections(): void
    {
        $scope = $this->place(); $e = $this->election($scope); $race = $this->race($e, $scope, 1);
        for ($i = 0; $i < 3; $i++) $this->resident($scope, $this->person());
        app(SimCandidateField::class)->fill($e, null, 1, noFloor: true);
        $kept = DB::table('candidacies')->where('election_id', $e)->first();
        DB::table('candidacies')->where('election_id', $e)->where('id', '!=', $kept->id)->delete();
        app(SimCandidateField::class)->fill($e, null, 1, noFloor: true);
        self::assertEquals($kept, DB::table('candidacies')->where('id', $kept->id)->first());
        DB::table('elections')->where('id', $e)->update(['status' => 'certified']);
        try { app(SimCandidateField::class)->fill($e, null, 1, noFloor: true); self::fail('Must preserve certification'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('preserved', $error->getMessage()); }
        self::assertSame(3, DB::table('candidacies')->where('election_id', $e)->count());
    }

    public function test_manifest_restarts_are_deduplicated_and_a_pilot_is_never_whole_world_ready(): void
    {
        $scopes = [$this->place(), $this->place()]; $source = $this->source($scopes);
        $control = app(SimRepairControl::class); $repair = $control->start($source->id, [$scopes[0]]);
        self::assertSame(1, $control->enumerate($repair)); self::assertSame(0, $control->enumerate($repair));
        self::assertSame(2, DB::table('sim_items')->where('run_id', $source->id)->where('status', 'review')->count());
        try { $control->apply($repair); self::fail('Planning has not completed'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('inventory', $error->getMessage()); }
        $repair->refresh(); $options = $repair->options; $options['repair_plan_complete'] = true;
        $repair->forceFill(['options' => $options, 'status' => 'halted'])->save(); $control->apply($repair);
        $repair->refresh()->forceFill(['phase' => 'repairing'])->save(); $control->enumerate($repair);
        DB::table('sim_items')->where('run_id', $repair->id)->update(['status' => 'done']);
        $repair->forceFill(['phase' => 'done', 'status' => 'done'])->save();
        $report = app(WorldReadiness::class)->report($repair);
        self::assertFalse($report['complete']); self::assertTrue($report['pending']); self::assertSame(2, $report['verify_expected']);
        self::assertSame('done', $source->refresh()->status);
    }

    public function test_atomic_receipt_rolls_back_failed_work_and_duplicate_delivery_reuses_it(): void
    {
        $scope = $this->place(); $source = $this->source([$scope]); $run = app(SimRepairControl::class)->start($source->id);
        $service = app(SimRepairService::class); $action = new \ReflectionMethod($service, 'action');
        $original = DB::table('jurisdictions')->where('id', $scope)->value('name');
        $result = $action->invoke($service, $run, $scope, 'test_rollback', $scope, function () use ($scope) {
            DB::table('jurisdictions')->where('id', $scope)->update(['name' => 'must roll back']); throw new \RuntimeException('injected interruption');
        });
        self::assertSame('blocked', $result['status']); self::assertSame($original, DB::table('jurisdictions')->where('id', $scope)->value('name'));
        $count = 0;
        $perform = function () use (&$count) { $count++; return ['status' => 'done']; };
        $action->invoke($service, $run, $scope, 'test_success', $scope, $perform);
        $again = $action->invoke($service, $run, $scope, 'test_success', $scope, $perform);
        self::assertSame(1, $count); self::assertTrue($again['reused']);
    }

    private function chamber(int $a, int $b): array
    {
        $scope = $this->place();
        $leg = Legislature::create(['jurisdiction_id' => $scope, 'term_number' => 1, 'status' => 'active',
            'total_seats' => $a + $b, 'type_a_seats' => $a, 'type_b_seats' => $b, 'quorum_required' => intdiv($a + $b, 2) + 1]);
        foreach (['a' => $a, 'b' => $b] as $kind => $n) for ($i = 0; $i < $n; $i++) {
            $user = $this->person(); $this->resident($scope, $user);
            LegislatureMember::create(['legislature_id' => $leg->id, 'user_id' => $user, 'seat_type' => $kind, 'seat_no' => $i + 1, 'status' => 'elected', 'seated_on' => now()]);
        }
        app(\App\Services\Education\EducationCatalogService::class)->publish();
        app(\App\Services\Education\SeatedMemberTrainingService::class)->armForJurisdiction($scope);
        return [$scope, $leg];
    }

    public function test_exactly_five_delegates_through_the_real_vote_and_retry_preserves_it(): void
    {
        [$scope, $leg] = $this->chamber(5, 0);
        $exec = $this->id(); DB::table('executives')->insert(['id' => $exec, 'jurisdiction_id' => $scope, 'type' => 'committee', 'term_number' => 1, 'status' => 'forming', 'created_at' => now(), 'updated_at' => now()]);
        $first = GovernanceStage::run($scope, null, 1);
        self::assertTrue($first['departments']['delegated'], json_encode($first));
        self::assertSame('delegated', DB::table('executives')->where('id', $exec)->value('status'));
        $votes = DB::table('chamber_votes')->where('legislature_id', $leg->id)->count();
        GovernanceStage::run($scope, null, 1);
        self::assertSame($votes, DB::table('chamber_votes')->where('legislature_id', $leg->id)->count());
    }

    public function test_tiny_chamber_ruling_uses_new_delegation_vote_and_preserves_the_historical_failed_act(): void
    {
        [$scope, $leg] = $this->chamber(5, 2); $source = $this->source([$scope]);
        $source->forceFill(['options' => ['scope_aspects' => ['governance'], 'no_floor' => true]])->save();
        $this->bindElection($source, $scope, $leg, 7, 7);
        $exec = $this->id(); DB::table('executives')->insert(['id' => $exec, 'jurisdiction_id' => $scope, 'type' => 'committee', 'term_number' => 1, 'status' => 'forming', 'created_at' => now(), 'updated_at' => now()]);
        $members = $leg->members()->get(); $actor = \App\Models\User::findOrFail($members->first()->user_id);
        $act = app(\App\Domain\Engine\ConstitutionalEngine::class)->file('F-LEG-014', $actor, [
            'legislature_id' => $leg->id, 'jurisdiction_id' => $scope, 'delegated_scope' => 'Historical fixture act', 'member_count' => 5, 'interest' => [],
        ]);
        $vote = \App\Models\ChamberVote::findOrFail($act->recorded['vote_id']);
        // Reproduce the PRE-ruling snapshot before any casts; the real close
        // path must fail, and that historical result must survive recovery.
        DB::table('chamber_vote_tallies')->where('vote_id', $vote->id)->where('lane', 'type_b')->update(['required_yes' => 3]);
        app(\App\Services\ChamberVoteService::class)->castManyYes($vote, $members);
        self::assertSame('failed', $vote->refresh()->outcome);
        $oldVote = DB::table('chamber_votes')->where('id', $vote->id)->first();
        $oldTallies = DB::table('chamber_vote_tallies')->where('vote_id', $vote->id)->orderBy('id')->get();
        $oldCasts = DB::table('vote_casts')->where('vote_id', $vote->id)->orderBy('id')->get();
        $run = app(SimRepairControl::class)->start($source->id); $options = $run->options; $options['repair_apply_authorized'] = true;
        $run->forceFill(['options' => $options, 'phase' => 'repairing', 'status' => 'running'])->save();
        $itemId = $this->id();
        DB::table('sim_items')->insert(['id' => $itemId, 'run_id' => $run->id, 'kind' => 'repair_scope', 'unit_key' => $scope,
            'jurisdiction_id' => $scope, 'status' => 'review', 'metrics' => '{"old":true}']);
        $key = ['source_run_id' => $source->id, 'repair_version' => 1, 'jurisdiction_id' => $scope, 'kind' => 'governance', 'target_id' => $leg->id];
        DB::table('sim_repair_receipts')->insert($key + ['status' => 'blocked', 'result' => json_encode([
            'departments' => ['delegated' => false, 'skipped' => 'delegation vote did not adopt'], 'reason' => 'Repair action did not meet its required postcondition.',
        ])]);
        $recovery = app(\App\Services\Demo\SimRepairReceiptRecovery::class);
        try { $recovery->retryTinyGovernance($run, [$scope]); self::fail('Must halt first'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('drained, halted', $error->getMessage()); }
        $run->forceFill(['status' => 'halted', 'halt_requested_at' => now()])->save();
        self::assertSame([], $recovery->retryTinyGovernance($run, [$this->id()])['retried']);
        DB::table('chamber_vote_tallies')->where('vote_id', $vote->id)->where('lane', 'type_b')->update(['yes' => 1, 'no' => 1]);
        self::assertSame([], $recovery->retryTinyGovernance($run, [$scope])['retried'], 'A genuinely opposed act cannot use this correction.');
        DB::table('chamber_vote_tallies')->where('vote_id', $vote->id)->where('lane', 'type_b')->update(['yes' => 2, 'no' => 0]);
        self::assertSame([$scope], $recovery->retryTinyGovernance($run, [$scope])['retried']);
        self::assertSame([], $recovery->retryTinyGovernance($run, [$scope])['retried']);
        self::assertSame('pending', DB::table('sim_items')->where('id', $itemId)->value('status'));
        $run->forceFill(['status' => 'running', 'halt_requested_at' => null])->save();
        $service = app(SimRepairService::class); $item = (object) ['jurisdiction_id' => $scope];
        $result = $service->run($run, $item);
        self::assertSame('done', $result['_verdict'], json_encode($result));
        self::assertSame('delegated', DB::table('executives')->where('id', $exec)->value('status'));
        self::assertSame(1, DB::table('chamber_votes')->where('body_id', $leg->id)->where('vote_type', 'exec_delegate')->where('outcome', 'adopted')->count());
        self::assertEquals($oldVote, DB::table('chamber_votes')->where('id', $vote->id)->first());
        self::assertEquals($oldTallies, DB::table('chamber_vote_tallies')->where('vote_id', $vote->id)->orderBy('id')->get());
        self::assertEquals($oldCasts, DB::table('vote_casts')->where('vote_id', $vote->id)->orderBy('id')->get());
        $counts = [DB::table('chamber_votes')->count(), DB::table('terms')->count(), DB::table('ledger_entries')->count()];
        self::assertSame('done', $service->run($run, $item)['_verdict']);
        self::assertSame($counts, [DB::table('chamber_votes')->count(), DB::table('terms')->count(), DB::table('ledger_entries')->count()]);
        self::assertStringContainsString('delegation vote did not adopt', DB::table('sim_repair_receipts')->where($key)->value('result'));
    }

    public function test_lowercase_type_b_allows_real_committee_growth_and_court_creation(): void
    {
        [$scope, $leg] = $this->chamber(5, 5);
        $result = GovernanceStage::run($scope, null, 1);
        self::assertSame(2, $result['committees']['created'], json_encode($result));
        self::assertSame(2, DB::table('committees')->where('legislature_id', $leg->id)->whereNotNull('created_by_vote_id')->count());
        $court = $this->id(); DB::table('judiciaries')->insert(['id' => $court, 'jurisdiction_id' => $scope, 'type' => 'appointed', 'status' => 'forming', 'min_judges' => 5, 'court_name' => 'Fixture court', 'created_at' => now(), 'updated_at' => now()]);
        $result = JudiciaryStage::run($scope, null, 1);
        self::assertTrue($result['filed'], json_encode($result));
        self::assertSame('appointed', DB::table('judiciaries')->where('id', $court)->value('status'), json_encode($result));
        self::assertSame(5, DB::table('judicial_seats')->where('judiciary_id', $court)->where('status', 'seated')->whereNotNull('term_id')->count());
    }

    public function test_court_roster_recovery_uses_real_court_residents_preserving_local_nominees_and_seated_terms(): void
    {
        [$scope, $leg] = $this->chamber(5, 5);
        $people = $leg->members()->orderBy('user_id')->pluck('user_id')->all();
        $children = [];
        foreach ([1, 1000] as $population) {
            $child = $this->place(7); $children[] = $child;
            DB::table('jurisdictions')->where('id', $child)->update(['parent_id' => $scope, 'population' => $population]);
            Legislature::create(['jurisdiction_id' => $child, 'term_number' => 1, 'status' => 'forming', 'total_seats' => 5, 'type_a_seats' => 5, 'type_b_seats' => 0]);
        }
        $this->resident($children[0], $people[0]);
        foreach (array_slice($people, 1, 3) as $person) { $this->resident($children[1], $person); }
        DB::table('residency_confirmations')->where('jurisdiction_id', $scope)->whereNotIn('user_id', array_slice($people, 0, 4))->update(['is_active' => false]);
        $court = $this->id(); DB::table('judiciaries')->insert(['id' => $court, 'jurisdiction_id' => $scope, 'type' => 'appointed', 'status' => 'forming', 'min_judges' => 5, 'court_name' => 'Eligible court resident fixture', 'created_at' => now(), 'updated_at' => now()]);
        $first = JudiciaryStage::run($scope, null, 1);
        self::assertSame('creating', $first['status']); self::assertSame(4, $first['seats_seated']);
        self::assertSame('2 seat(s) deferred', $first['skipped']);
        $oldSeats = DB::table('judicial_seats')->where('judiciary_id', $court)->where('status', 'seated')->orderBy('id')->get();
        $oldTerms = DB::table('terms')->whereIn('id', $oldSeats->pluck('term_id'))->orderBy('id')->get();
        $source = $this->source([$scope]); $run = app(SimRepairControl::class)->start($source->id); $options = $run->options; $options['repair_apply_authorized'] = true;
        $run->forceFill(['options' => $options, 'phase' => 'repairing', 'status' => 'running'])->save();
        $item = $this->id(); DB::table('sim_items')->insert(['id' => $item, 'run_id' => $run->id, 'kind' => 'repair_scope', 'unit_key' => $scope, 'jurisdiction_id' => $scope, 'status' => 'review', 'metrics' => '{"old":true}']);
        $key = ['source_run_id' => $source->id, 'repair_version' => 1, 'jurisdiction_id' => $scope, 'kind' => 'judiciary', 'target_id' => $court];
        DB::table('sim_repair_receipts')->insert($key + ['status' => 'blocked', 'result' => json_encode($first + ['reason' => 'Repair action did not meet its required postcondition.'])]);
        $retry = app(\App\Services\Demo\SimRepairReceiptRecovery::class);
        try { $retry->retryCourtRosters($run, [$scope]); self::fail('Must halt first'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('drained, halted', $error->getMessage()); }
        $run->forceFill(['status' => 'halted', 'halt_requested_at' => now()])->save();
        self::assertSame([], $retry->retryCourtRosters($run, [$scope])['retried'], 'Do not retry when there are no unused eligible nominees.');
        DB::table('residency_confirmations')->where('jurisdiction_id', $scope)->update(['is_active' => true]);
        self::assertSame([$scope], $retry->retryCourtRosters($run, [$scope])['retried']);
        self::assertSame([], $retry->retryCourtRosters($run, [$scope])['retried']);
        $service = app(SimRepairService::class); $action = new \ReflectionMethod($service, 'action');
        $result = $action->invoke($service, $run, $scope, 'judiciary', $court,
            fn () => JudiciaryStage::run($scope, $source->id, 1),
            fn () => DB::table('judiciaries')->where('id', $court)->value('status') === 'appointed');
        self::assertSame('applied', $result['status'], json_encode($result));
        $seats = DB::table('judicial_seats')->where('judiciary_id', $court)->where('status', 'seated')->get();
        self::assertCount(6, $seats); self::assertCount(6, $seats->pluck('user_id')->unique());
        self::assertSame(5, (int) DB::table('judiciaries')->where('id', $court)->value('min_judges'));
        self::assertCount(3, $seats->where('nominating_jurisdiction_id', $children[0]));
        self::assertCount(3, $seats->where('nominating_jurisdiction_id', $children[1]));
        self::assertEquals($oldSeats, DB::table('judicial_seats')->whereIn('id', $oldSeats->pluck('id'))->orderBy('id')->get());
        self::assertEquals($oldTerms, DB::table('terms')->whereIn('id', $oldTerms->pluck('id'))->orderBy('id')->get());
        self::assertSame(2, $seats->where('nominating_jurisdiction_id', $children[0])->where('user_id', '!=', $people[0])->count());
        foreach ($seats as $seat) {
            self::assertTrue(DB::table('residency_confirmations')->where('jurisdiction_id', $scope)->where('user_id', $seat->user_id)->where('is_active', true)->exists());
            self::assertTrue(DB::table('clock_timers')->where('clock_id', 'CLK-09')->where('subject_id', $seat->term_id)->where('state', 'armed')->exists());
        }
        $terms = DB::table('terms')->count(); $votes = DB::table('chamber_votes')->count();
        self::assertSame('already operating', JudiciaryStage::run($scope, $source->id, 1)['skipped']);
        self::assertSame($terms, DB::table('terms')->count()); self::assertSame($votes, DB::table('chamber_votes')->count());
        self::assertStringContainsString('2 seat(s) deferred', DB::table('sim_repair_receipts')->where($key)->value('result'));
    }

    private function bindElection(SimRun $source, string $scope, Legislature $leg, int $candidates, int $seats, string $status = 'certified'): string
    {
        $e = $this->election($scope); $race = $this->race($e, $scope, 1);
        DB::table('elections')->where('id', $e)->update(['legislature_id' => $leg->id, 'status' => $status]);
        DB::table('election_races')->where('id', $race)->update(['seats' => $seats, 'seat_kind' => $seats > 9 ? 'type_b' : 'type_a']);
        foreach (DB::table('legislature_members')->where('legislature_id', $leg->id)->limit($candidates)->pluck('user_id') as $user) {
            DB::table('candidacies')->insert(['id' => $this->id(), 'election_id' => $e, 'race_id' => $race, 'user_id' => $user,
                'status' => 'elected', 'position_tags' => '[]', 'residency_attested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('sim_items')->insert(['id' => $this->id(), 'run_id' => $source->id, 'kind' => 'election_scope', 'unit_key' => $scope,
            'jurisdiction_id' => $scope, 'status' => 'done', 'position' => 1, 'metrics' => json_encode(['election_id' => $e]), 'created_at' => now(), 'updated_at' => now()]);
        return $e;
    }

    public function test_repair_executes_government_dependencies_and_rechecks_without_replaying_money(): void
    {
        [$scope, $leg] = $this->chamber(5, 0); $source = $this->source([$scope]);
        $source->forceFill(['options' => ['scope_aspects' => ['governance'], 'no_floor' => true]])->save();
        $this->bindElection($source, $scope, $leg, 5, 5);
        $exec = $this->id(); DB::table('executives')->insert(['id' => $exec, 'jurisdiction_id' => $scope, 'type' => 'committee', 'term_number' => 1, 'status' => 'forming', 'created_at' => now(), 'updated_at' => now()]);
        $run = app(SimRepairControl::class)->start($source->id);
        $item = (object) ['jurisdiction_id' => $scope]; $service = app(SimRepairService::class);
        $plan = $service->run($run, $item, planning: true);
        self::assertSame('governance', $plan['category']);
        self::assertSame('forming', DB::table('executives')->where('id', $exec)->value('status'));
        $options = $run->options; $options['repair_apply_authorized'] = true;
        $run->forceFill(['options' => $options, 'phase' => 'repairing', 'status' => 'running'])->save();
        $ledger = DB::table('ledger_entries')->count();
        $result = $service->run($run, $item);
        self::assertSame('done', $result['_verdict'], json_encode($result));
        self::assertFalse($result['stipend_replayed']);
        self::assertSame($ledger, DB::table('ledger_entries')->count());
        $votes = DB::table('chamber_votes')->count(); $terms = DB::table('terms')->count();
        self::assertSame('done', $service->run($run, $item)['_verdict']);
        self::assertSame($votes, DB::table('chamber_votes')->count()); self::assertSame($terms, DB::table('terms')->count());
    }

    public function test_certified_underfilled_election_stays_review_and_its_history_is_immutable(): void
    {
        [$scope, $leg] = $this->chamber(5, 2);
        $leg->forceFill(['total_seats' => 10, 'type_b_seats' => 5])->save();
        $source = $this->source([$scope]); $e = $this->bindElection($source, $scope, $leg, 7, 10);
        $run = app(SimRepairControl::class)->start($source->id);
        $options = $run->options; $options['repair_apply_authorized'] = true;
        $run->forceFill(['options' => $options, 'phase' => 'repairing', 'status' => 'running'])->save();
        $before = DB::table('elections')->where('id', $e)->first();
        $result = app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope]);
        self::assertSame('review', $result['_verdict']);
        self::assertStringContainsString('Certified', $result['_reason']);
        self::assertSame(7, DB::table('legislature_members')->where('legislature_id', $leg->id)->count());
        self::assertEquals($before, DB::table('elections')->where('id', $e)->first());
        self::assertSame(0, DB::table('sim_repair_receipts')->where('kind', 'election')->count());
    }

    public function test_four_is_refused_and_six_is_valid_for_executive_delegation(): void
    {
        foreach ([4, 6] as $number) {
            [$scope] = $this->chamber($number, 0); $exec = $this->id();
            DB::table('executives')->insert(['id' => $exec, 'jurisdiction_id' => $scope, 'type' => 'committee', 'term_number' => 1, 'status' => 'forming', 'created_at' => now(), 'updated_at' => now()]);
            $result = GovernanceStage::run($scope, null, 1);
            self::assertSame($number === 6, $result['departments']['delegated'], json_encode($result));
            self::assertSame($number === 6 ? 'delegated' : 'forming', DB::table('executives')->where('id', $exec)->value('status'));
        }
    }

    public function test_halt_preserves_planned_receipts_for_resume_and_keeps_work_untouched(): void
    {
        $scope = $this->place(); $source = $this->source([$scope]); $run = app(SimRepairControl::class)->start($source->id);
        $options = $run->options; $options['repair_apply_authorized'] = true;
        $run->forceFill(['options' => $options, 'phase' => 'repairing', 'status' => 'halted', 'halt_requested_at' => now()])->save();
        $inspector = $this->createMock(SimRepairInspector::class);
        $inspector->method('inspect')->willReturn(['verification' => [], 'actions' => [['kind' => 'chair', 'target' => $this->id()]], 'blockers' => [], 'coverage' => [], 'acceptance_gaps' => []]);
        $this->expectException(\App\Services\Demo\SimRepairPaused::class);
        (new SimRepairService($inspector))->run($run, (object) ['jurisdiction_id' => $scope]);
    }

    public function test_empty_election_repairs_through_real_counting_certification_and_seating_once(): void
    {
        $scope = $this->place();
        $leg = Legislature::create(['jurisdiction_id' => $scope, 'term_number' => 1, 'status' => 'forming',
            'total_seats' => 5, 'type_a_seats' => 5, 'type_b_seats' => 0, 'quorum_required' => 3]);
        $board = \App\Models\ElectionBoard::create(['jurisdiction_id' => $scope, 'is_bootstrap' => true, 'status' => 'active']);
        \App\Models\ElectionBoardMember::create(['election_board_id' => $board->id, 'user_id' => null, 'status' => 'seated']);
        for ($i = 0; $i < 6; $i++) $this->resident($scope, $this->person());
        \App\Services\Demo\Stages\CohortStage::run($scope, null, 1, 62);
        $source = $this->source([$scope]); $e = $this->bindElection($source, $scope, $leg, 0, 5, 'scheduled');
        DB::table('elections')->where('id', $e)->update(['election_board_id' => $board->id]);
        $run = app(SimRepairControl::class)->start($source->id); $options = $run->options; $options['repair_apply_authorized'] = true;
        $run->forceFill(['options' => $options, 'phase' => 'repairing', 'status' => 'running'])->save();
        $service = app(SimRepairService::class); $item = (object) ['jurisdiction_id' => $scope];
        $result = $service->run($run, $item);
        self::assertSame('done', $result['_verdict'], json_encode($result));
        self::assertSame('certified', DB::table('elections')->where('id', $e)->value('status'));
        self::assertSame(5, DB::table('legislature_members')->where('election_id', $e)->count());
        $terms = DB::table('terms')->count(); $counts = DB::table('tabulations')->count();
        self::assertSame('done', $service->run($run, $item)['_verdict']);
        self::assertSame($terms, DB::table('terms')->count()); self::assertSame($counts, DB::table('tabulations')->count());
    }

    public function test_compatible_version_retry_preserves_the_original_election_pin_and_rejects_other_failures(): void
    {
        $scope = $this->place();
        $leg = Legislature::create(['jurisdiction_id' => $scope, 'term_number' => 1, 'status' => 'forming',
            'total_seats' => 5, 'type_a_seats' => 5, 'type_b_seats' => 0, 'quorum_required' => 3]);
        $board = \App\Models\ElectionBoard::create(['jurisdiction_id' => $scope, 'is_bootstrap' => true, 'status' => 'active']);
        \App\Models\ElectionBoardMember::create(['election_board_id' => $board->id, 'user_id' => null, 'status' => 'seated']);
        for ($i = 0; $i < 6; $i++) { $this->resident($scope, $this->person()); }
        \App\Services\Demo\Stages\CohortStage::run($scope, null, 1, 62);
        $source = $this->source([$scope]); $e = $this->bindElection($source, $scope, $leg, 0, 5, 'scheduled');
        $old = 'cv1.ac7230fe88c24e2fcd8f323f5e160b78';
        DB::table('elections')->where('id', $e)->update(['election_board_id' => $board->id, 'constitutional_version' => $old]);
        $run = app(SimRepairControl::class)->start($source->id); $options = $run->options;
        $options['repair_apply_authorized'] = true; $options['repair_election_recovery'] = true;
        $run->forceFill(['options' => $options, 'phase' => 'repairing', 'status' => 'running'])->save();
        $service = app(SimRepairService::class); $item = (object) ['jurisdiction_id' => $scope];
        // An unreviewed version still refuses at REAL certification and rolls
        // the whole election action back, as the deployed D021 incident did.
        $future = new class extends \App\Services\ConstitutionalVersionService { public function derive(): string { return 'cv1.unreviewed'; } };
        $this->app->instance(\App\Services\ConstitutionalVersionService::class, $future);
        $counts = DB::table('tabulations')->count(); $members = DB::table('legislature_members')->count();
        $result = $service->run($run, $item);
        self::assertSame('review', $result['_verdict']);
        self::assertStringContainsString('constitutional_version', json_encode($result));
        self::assertSame($counts, DB::table('tabulations')->count());
        self::assertSame($members, DB::table('legislature_members')->count());
        self::assertSame('scheduled', DB::table('elections')->where('id', $e)->value('status'));
        $itemId = $this->id(); DB::table('sim_items')->insert(['id' => $itemId, 'run_id' => $run->id, 'kind' => 'repair_scope',
            'unit_key' => $scope, 'jurisdiction_id' => $scope, 'status' => 'review', 'metrics' => json_encode($result)]);
        $run->forceFill(['status' => 'halted', 'halt_requested_at' => now()])->save();
        $retry = app(\App\Services\Demo\SimRepairReceiptRecovery::class);
        self::assertSame([], $retry->retryCompatibleElections($run, [$scope])['retried'], 'Future math cannot bypass version protection.');
        $this->app->instance(\App\Services\ConstitutionalVersionService::class, new \App\Services\ConstitutionalVersionService());
        $receipt = DB::table('sim_repair_receipts')->where('target_id', $e)->first();
        DB::table('sim_repair_receipts')->where('target_id', $e)->update(['result' => '{"reason":"unrelated","exception":"ConstitutionalViolation"}']);
        self::assertSame([], $retry->retryCompatibleElections($run, [$scope])['retried']);
        DB::table('sim_repair_receipts')->where('target_id', $e)->update(['result' => $receipt->result]);
        self::assertSame(0, \Illuminate\Support\Facades\Artisan::call('sim:repair', ['--retry-compatible-elections' => $run->id, '--scope' => [$scope]]));
        self::assertSame([$scope], json_decode(\Illuminate\Support\Facades\Artisan::output(), true)['retried']);
        self::assertSame([], $retry->retryCompatibleElections($run, [$scope])['retried']);
        $run->forceFill(['status' => 'running', 'halt_requested_at' => null])->save();
        $result = $service->run($run, $item);
        self::assertSame('done', $result['_verdict'], json_encode($result));
        self::assertSame($old, DB::table('elections')->where('id', $e)->value('constitutional_version'));
        self::assertSame('certified', DB::table('elections')->where('id', $e)->value('status'));
        self::assertSame(5, DB::table('legislature_members')->where('election_id', $e)->count());
        $history = json_decode(DB::table('sim_repair_receipts')->where('target_id', $e)->value('result'), true);
        self::assertStringContainsString('constitutional_version', json_encode($history['_prior_receipts']));
        $counts = DB::table('tabulations')->count(); $terms = DB::table('terms')->count();
        self::assertSame('done', $service->run($run, $item)['_verdict']);
        self::assertSame($counts, DB::table('tabulations')->count()); self::assertSame($terms, DB::table('terms')->count());
    }

    public function test_fresh_board_seating_elects_a_chair_and_existing_board_repair_is_atomic(): void
    {
        $scope = $this->place(); $people = [$this->person(), $this->person(), $this->person()];
        $org = \App\Models\Organization::create(['name' => 'Fixture board', 'slug' => $this->id(), 'jurisdiction_id' => $scope,
            'type' => 'business', 'ownership_type' => 'private', 'status' => 'active']);
        $boards = app(\App\Services\Demo\SimBoardService::class);
        self::assertSame(3, $boards->seatOrganizationBoard($org, 3, $people, $scope));
        $board = \App\Models\Board::findOrFail($org->refresh()->board_id);
        self::assertNotNull($board->chair_seat_id);
        self::assertSame('adopted', DB::table('chamber_votes')->where('body_id', $board->id)->value('outcome'));
        $votes = DB::table('chamber_votes')->count();
        self::assertSame(0, $boards->seatOrganizationBoard($org, 3, $people, $scope));
        self::assertSame($votes, DB::table('chamber_votes')->count());
    }

    public function test_repair_resumes_an_existing_committee_vote_without_a_second_proposal(): void
    {
        [$scope, $leg] = $this->chamber(5, 0);
        $member = $leg->members()->first();
        $proposal = app(\App\Services\Legislature\CommitteeService::class)->proposeCreation($leg, $member, 'Existing committee', 'Fixture', 5);
        $before = DB::table('chamber_vote_proposals')->count();
        $service = app(SimRepairService::class);
        $resumed = (new \ReflectionMethod($service, 'resumePendingActs'))->invoke($service, $scope, 'governance', null);
        self::assertSame([$proposal['vote_id']], $resumed);
        self::assertSame('adopted', DB::table('chamber_votes')->where('id', $proposal['vote_id'])->value('outcome'));
        self::assertSame($before, DB::table('chamber_vote_proposals')->count());
        self::assertSame([], (new \ReflectionMethod($service, 'resumePendingActs'))->invoke($service, $scope, 'governance', null));
    }

    public function test_interruption_inside_an_act_leaves_a_retryable_receipt_and_no_partial_effects(): void
    {
        $scope = $this->place(); $source = $this->source([$scope]); $run = app(SimRepairControl::class)->start($source->id);
        $service = app(SimRepairService::class); $method = new \ReflectionMethod($service, 'action');
        $name = DB::table('jurisdictions')->where('id', $scope)->value('name');
        try {
            $method->invoke($service, $run, $scope, 'interruption_test', $scope, function () use ($scope) {
                DB::table('jurisdictions')->where('id', $scope)->update(['name' => 'partial']);
                throw new \App\Services\Demo\SimRepairPaused('ownership lost');
            }); self::fail('Expected interruption');
        } catch (\App\Services\Demo\SimRepairPaused) {}
        self::assertSame($name, DB::table('jurisdictions')->where('id', $scope)->value('name'));
        self::assertSame('planned', DB::table('sim_repair_receipts')->where('kind', 'interruption_test')->value('status'));
        self::assertSame('applied', $method->invoke($service, $run, $scope, 'interruption_test', $scope, fn () => ['status' => 'done'])['status']);
    }

    public function test_full_manifest_summary_and_completion_preserve_inactive_scopes(): void
    {
        $scope = $this->place(); DB::table('jurisdictions')->where('id', $scope)->update(['population' => 0]);
        \App\Services\Demo\Stages\CohortStage::run($scope, null, 1, 62);
        $source = $this->source([$scope]); $control = app(SimRepairControl::class); $run = $control->start($source->id); $control->enumerate($run);
        $plan = app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope], planning: true);
        self::assertSame('lawfully_inactive', $plan['category']); self::assertSame([], $plan['plan']['actions']);
        DB::table('sim_items')->where('run_id', $run->id)->update(['status' => 'done', 'metrics' => json_encode($plan)]);
        self::assertTrue($control->summarize($run)); self::assertTrue($control->summarize($run));
        self::assertSame(1, $control->report($run->refresh())['summary']['categories']['lawfully_inactive']);
        $options = $run->options; $options['repair_apply_authorized'] = true;
        $run->forceFill(['options' => $options, 'status' => 'running', 'phase' => 'repairing'])->save(); $control->enumerate($run);
        $result = app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope]);
        self::assertSame('done', $result['_verdict']);
        DB::table('sim_items')->where('run_id', $run->id)->update(['status' => 'done']);
        $run->forceFill(['status' => 'done', 'phase' => 'done'])->save();
        self::assertTrue(app(WorldReadiness::class)->report($run)['complete']);
    }

    public function test_district_candidates_stay_inside_the_existing_race_footprint(): void
    {
        $parent = $this->place(0); $child = $this->place(6);
        $leg = Legislature::create(['jurisdiction_id' => $parent, 'term_number' => 1, 'status' => 'forming', 'total_seats' => 5, 'type_a_seats' => 5, 'type_b_seats' => 0, 'quorum_required' => 3]);
        $district = $this->id();
        DB::table('legislature_districts')->insert(['id' => $district, 'legislature_id' => $leg->id, 'jurisdiction_id' => $parent,
            'district_number' => 1, 'seats' => 5, 'target_population' => 1000, 'actual_population' => 1000]);
        DB::table('legislature_district_jurisdictions')->insert(['id' => $this->id(), 'district_id' => $district, 'jurisdiction_id' => $child]);
        $e = $this->election($parent); $race = $this->race($e, $parent, 1);
        DB::table('election_races')->where('id', $race)->update(['district_id' => $district]);
        for ($i = 0; $i < 6; $i++) {
            $user = $this->person(); $this->resident($parent, $user); if ($i < 3) $this->resident($child, $user);
        }
        app(SimCandidateField::class)->fill($e, null, 1, noFloor: true);
        $raceModel = \App\Models\ElectionRace::findOrFail($race);
        foreach (DB::table('candidacies')->where('election_id', $e)->pluck('user_id') as $user) {
            self::assertTrue(\App\Domain\Forms\Support\RaceFootprint::userInFootprint($user, $raceModel));
        }
    }

    public function test_candidate_floor_never_mints_above_real_population(): void
    {
        $scope = $this->place(); DB::table('jurisdictions')->where('id', $scope)->update(['population' => 2]);
        \App\Services\Demo\Stages\CohortStage::run($scope, null, 1, 62);
        $e = $this->election($scope); $this->race($e, $scope, 1);
        $result = app(SimCandidateField::class)->fill($e, null, 1);
        self::assertSame(2, $result['candidacies']); self::assertSame([], $result['too_few']);
        self::assertSame(2, DB::table('residency_confirmations')->where('jurisdiction_id', $scope)->where('is_active', true)->count());
    }

    public function test_candidate_shortage_allocates_required_seats_before_optional_challengers(): void
    {
        $scope = $this->place(); DB::table('jurisdictions')->where('id', $scope)->update(['population' => 4]);
        \App\Services\Demo\Stages\CohortStage::run($scope, null, 1, 62);
        $e = $this->election($scope); $a = $this->race($e, $scope, 1); $b = $this->race($e, $scope, 2);
        for ($i = 0; $i < 4; $i++) { $this->resident($scope, $this->person()); }
        $result = app(SimCandidateField::class)->fill($e, null, 1, noFloor: true);
        self::assertSame(4, $result['candidacies']); self::assertSame([], $result['too_few']);
        self::assertSame(2, DB::table('candidacies')->where('race_id', $a)->count());
        self::assertSame(2, DB::table('candidacies')->where('race_id', $b)->count());
        self::assertSame(4, DB::table('candidacies')->where('election_id', $e)->distinct()->count('user_id'));
        self::assertSame(4, app(SimCandidateField::class)->fill($e, null, 1, noFloor: true)['candidacies']);
    }

    private function mixedCountWorld(): array
    {
        $scope = $this->place(); $source = $this->source([$scope]);
        $source->forceFill(['options' => ['scope_aspects' => ['civic_life'], 'no_floor' => true]])->save();
        $leg = Legislature::create(['jurisdiction_id' => $scope, 'term_number' => 1, 'status' => 'forming', 'total_seats' => 6, 'type_a_seats' => 6, 'type_b_seats' => 0]);
        $e = $this->bindElection($source, $scope, $leg, 0, 2, 'scheduled');
        $empty = DB::table('election_races')->where('election_id', $e)->value('id');
        $district = $this->id();
        DB::table('legislature_districts')->insert(['id' => $district, 'legislature_id' => $leg->id, 'jurisdiction_id' => $scope,
            'district_number' => 1, 'seats' => 2, 'target_population' => 1000, 'actual_population' => 1000]);
        DB::table('election_races')->where('id', $empty)->update(['district_id' => $district]);
        $deficient = $this->race($e, $scope, 1); $full = $this->race($e, $scope, 2);
        foreach ([$deficient => 1, $full => 2] as $race => $n) {
            for ($i = 0; $i < $n; $i++) {
                $user = $this->person(); $this->resident($scope, $user);
                DB::table('candidacies')->insert(['id' => $this->id(), 'election_id' => $e, 'race_id' => $race,
                    'user_id' => $user, 'status' => 'validated', 'position_tags' => '[]', 'residency_attested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('tabulations')->insert(['id' => $this->id(), 'race_id' => $race, 'engine_version' => 'fixture',
                'seats' => 2, 'status' => 'complete', 'record_hash' => hash('sha256', $race), 'completed_at' => now()]);
        }
        $run = app(SimRepairControl::class)->start($source->id);
        return [$scope, $source, $run, $e, $empty, $deficient, $full];
    }

    public function test_mixed_deficient_counts_block_planning_and_dependencies_but_valid_board_chairs_still_finish(): void
    {
        [$scope, $source, $run, $e, $empty, $deficient, $full] = $this->mixedCountWorld();
        $org = \App\Models\Organization::create(['name' => 'Independent board', 'slug' => $this->id(), 'jurisdiction_id' => $scope, 'type' => 'business', 'status' => 'active']);
        $board = \App\Models\Board::create(['boardable_type' => 'organizations', 'boardable_id' => $org->id, 'status' => 'active', 'owner_seats' => 3, 'worker_seats' => 0]);
        for ($i = 1; $i <= 3; $i++) \App\Models\BoardSeat::create(['board_id' => $board->id, 'seat_class' => 'owner_elected', 'seat_no' => $i, 'holder_user_id' => $this->person(), 'status' => 'seated']);
        $candidates = DB::table('candidacies')->where('election_id', $e)->orderBy('id')->get()->toArray();
        $counts = DB::table('tabulations')->whereIn('race_id', [$empty,$deficient,$full])->orderBy('id')->get()->toArray();
        $service = app(SimRepairService::class); $item = (object) ['jurisdiction_id' => $scope];
        $plan = $service->run($run, $item, planning: true);
        self::assertSame('blocked_recovery', $plan['category']);
        self::assertSame(['uncounted_deficient' => 1, 'uncounted_sufficient' => 0, 'counted_deficient' => 1, 'counted_sufficient' => 1], $plan['plan']['race_states']);
        self::assertSame(['chair'], DB::table('sim_repair_receipts')->where('source_run_id', $source->id)->pluck('kind')->all());
        $options = $run->options; $options['repair_apply_authorized'] = true;
        $run->forceFill(['options' => $options, 'phase' => 'repairing', 'status' => 'running'])->save();
        $ledger = DB::table('ledger_entries')->count();
        $out = $service->run($run, $item);
        self::assertSame('review', $out['_verdict']); self::assertNotNull($board->refresh()->chair_seat_id);
        self::assertSame('adopted', DB::table('chamber_votes')->where('body_id', $board->id)->value('outcome'));
        self::assertSame(['chair'], DB::table('sim_repair_receipts')->where('source_run_id', $source->id)->pluck('kind')->all());
        $service->run($run, $item);
        self::assertEquals($candidates, DB::table('candidacies')->where('election_id', $e)->orderBy('id')->get()->toArray());
        self::assertEquals($counts, DB::table('tabulations')->whereIn('race_id', [$empty,$deficient,$full])->orderBy('id')->get()->toArray());
        self::assertSame($ledger, DB::table('ledger_entries')->count());
        // Retained uncontested counts that fill their seats are not deficient.
        DB::table('tabulations')->where('race_id', $deficient)->update(['status' => 'superseded']);
        $plan = $service->run($run, $item, planning: true);
        self::assertSame([], $plan['plan']['blockers']); self::assertSame('election', $plan['category']);
    }

    public function test_a_blocked_election_receipt_stops_dependent_actions_even_when_inspection_allows_fielding(): void
    {
        [$scope, $source, $run, $e, $empty, $deficient] = $this->mixedCountWorld();
        DB::table('tabulations')->where('race_id', $deficient)->update(['status' => 'superseded']);
        DB::table('sim_repair_receipts')->insert(['source_run_id' => $source->id, 'repair_version' => 1, 'kind' => 'election', 'target_id' => $e,
            'jurisdiction_id' => $scope, 'status' => 'blocked', 'result' => json_encode(['reason' => 'Existing recovery refusal']), 'created_at' => now(), 'updated_at' => now()]);
        $options = $run->options; $options['repair_apply_authorized'] = true;
        $run->forceFill(['options' => $options, 'phase' => 'repairing', 'status' => 'running'])->save();
        $out = app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope]);
        self::assertSame('review', $out['_verdict']);
        self::assertSame(['election'], DB::table('sim_repair_receipts')->where('source_run_id', $source->id)->pluck('kind')->all());
    }

    public function test_noop_receipt_recovery_retains_history_and_successes_and_can_continue_after_prerequisites_are_restored(): void
    {
        // Fixture represents the state supplied by a separately authorized election recovery.
        // This test does not introduce or authorize a production recount policy.
        [$scope, $leg] = $this->chamber(5, 0); $source = $this->source([$scope]);
        $source->forceFill(['options' => ['scope_aspects' => ['civic_life'], 'no_floor' => true]])->save();
        $this->bindElection($source, $scope, $leg, 5, 5);
        DB::table('executives')->insert(['id' => $this->id(), 'jurisdiction_id' => $scope, 'type' => 'committee', 'term_number' => 1, 'status' => 'forming']);
        $court = $this->id(); DB::table('judiciaries')->insert(['id' => $court, 'jurisdiction_id' => $scope, 'type' => 'appointed', 'status' => 'forming', 'min_judges' => 5, 'court_name' => 'Fixture court']);
        $control = app(SimRepairControl::class); $run = $control->start($source->id); $control->enumerate($run);
        DB::table('sim_items')->where('run_id', $run->id)->update(['status' => 'done']);
        $run->forceFill(['status' => 'halted', 'halt_requested_at' => now()])->save();
        $noop = [
            'training' => ['holders' => 0, 'trained' => 0, 'already' => 0, 'unarmed' => 0, 'failed' => 0],
            'governance' => (new \ReflectionMethod(GovernanceStage::class, 'bothSkip'))->invoke(null, 'chamber not seated'),
            'judiciary' => (new \ReflectionMethod(JudiciaryStage::class, 'skip'))->invoke(null, 'chamber not seated'),
            'civics' => (new \ReflectionMethod(\App\Services\Demo\Stages\CivicsStage::class, 'result'))->invoke(null),
        ];
        $targets = ['training' => $scope, 'governance' => $leg->id, 'judiciary' => $court, 'civics' => $scope];
        foreach ($noop as $kind => $result) DB::table('sim_repair_receipts')->insert(['source_run_id' => $source->id, 'repair_version' => 1, 'kind' => $kind,
            'target_id' => $targets[$kind], 'jurisdiction_id' => $scope, 'status' => 'applied', 'result' => json_encode($result), 'created_at' => now(), 'updated_at' => now()]);
        $successful = ['source_run_id' => $source->id, 'repair_version' => 1, 'kind' => 'chair', 'target_id' => $this->id(), 'jurisdiction_id' => $scope,
            'status' => 'applied', 'result' => json_encode(['status' => 'done']), 'created_at' => now(), 'updated_at' => now()];
        DB::table('sim_repair_receipts')->insert($successful);
        $recovery = app(\App\Services\Demo\SimRepairReceiptRecovery::class);
        $ledger = DB::table('ledger_entries')->count();
        self::assertCount(4, $recovery->recover($run, [$scope])['corrected']);
        self::assertSame([], $recovery->recover($run, [$scope])['corrected']);
        self::assertSame(4, DB::table('audit_log')->where('event', 'sim.repair_receipt_corrected')->count());
        self::assertSame('applied', DB::table('sim_repair_receipts')->where('target_id', $successful['target_id'])->value('status'));
        foreach ($noop as $kind => $previous) {
            $saved = DB::table('sim_repair_receipts')->where('source_run_id', $source->id)->where('kind', $kind)->sole();
            self::assertSame('deferred', $saved->status); self::assertEquals($previous, json_decode($saved->result, true)['_prior_receipts'][0]['result']);
        }
        self::assertSame($ledger, DB::table('ledger_entries')->count());
        $options = $run->options; $options['repair_apply_authorized'] = true;
        $run->forceFill(['options' => $options, 'phase' => 'repairing', 'status' => 'running', 'halt_requested_at' => null])->save();
        $out = app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope]);
        self::assertSame('done', $out['_verdict'], json_encode($out));
        foreach ($noop as $kind => $previous) {
            $saved = DB::table('sim_repair_receipts')->where('source_run_id', $source->id)->where('kind', $kind)->sole();
            self::assertSame('applied', $saved->status); self::assertEquals($previous, json_decode($saved->result, true)['_prior_receipts'][0]['result']);
        }
        $votes = DB::table('chamber_votes')->count(); $terms = DB::table('terms')->count(); $ledger = DB::table('ledger_entries')->count();
        self::assertSame('done', app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope])['_verdict']);
        self::assertSame($votes, DB::table('chamber_votes')->count()); self::assertSame($terms, DB::table('terms')->count()); self::assertSame($ledger, DB::table('ledger_entries')->count());
    }

    public function test_action_postcondition_failure_is_not_success_and_zero_holder_training_is_deferred(): void
    {
        $scope = $this->place(); $source = $this->source([$scope]); $run = app(SimRepairControl::class)->start($source->id);
        $method = new \ReflectionMethod(SimRepairService::class, 'action'); $service = app(SimRepairService::class);
        $out = $method->invoke($service, $run, $scope, 'election', $this->id(), fn () => ['seating' => ['certified' => false]], fn () => false);
        self::assertSame('blocked', $out['status']);
        $out = $method->invoke($service, $run, $scope, 'training', $scope,
            fn () => ['holders' => 0, 'trained' => 0, 'already' => 0, 'unarmed' => 0, 'failed' => 0], fn () => false);
        self::assertSame('deferred', $out['status']);
        self::assertFalse(\App\Services\Demo\SimRepairReceiptRecovery::isPrerequisiteNoop('training', ['holders' => 1, 'trained' => 1, 'already' => 0, 'unarmed' => 0, 'failed' => 0]));
        $gov = (new \ReflectionMethod(GovernanceStage::class, 'bothSkip'))->invoke(null, 'chamber not seated');
        $gov['resumed_votes'] = [$this->id()];
        self::assertFalse(\App\Services\Demo\SimRepairReceiptRecovery::isPrerequisiteNoop('governance', $gov));
    }

    public function test_older_inventory_reclassification_preserves_run_items_and_gate_and_rebuilds_summary_once(): void
    {
        [$scope, $source, $run] = $this->mixedCountWorld();
        $control = app(SimRepairControl::class); $control->enumerate($run);
        $plan = app(SimRepairService::class)->run($run, (object) ['jurisdiction_id' => $scope], planning: true);
        $plan['category'] = 'election'; $plan['plan']['blockers'] = []; unset($plan['plan']['race_states']);
        $oldId = DB::table('sim_items')->where('run_id', $run->id)->value('id');
        DB::table('sim_items')->where('id', $oldId)->update(['status' => 'done', 'metrics' => json_encode($plan)]);
        $run->refresh(); $options = $run->options; $options['repair_inspector_revision'] = 1; $options['repair_plan_complete'] = true;
        $run->forceFill(['options' => $options, 'status' => 'halted', 'halt_requested_at' => now()])->save();
        self::assertTrue($control->report($run)['classification_stale']);
        try { $control->apply($run); self::fail('Old classifications must not apply'); } catch (\RuntimeException $e) { self::assertStringContainsString('refresh-plan', $e->getMessage()); }
        $stats = $control->refreshInventory($run); self::assertSame(1, $stats['refreshed']);
        self::assertSame(['already_current' => true], $control->refreshInventory($run));
        $report = $control->report($run->refresh());
        self::assertFalse($report['classification_stale']); self::assertTrue($report['plan_complete']); self::assertFalse($report['authorized']);
        self::assertSame(1, $report['summary']['categories']['blocked_recovery']);
        self::assertSame($oldId, DB::table('sim_items')->where('run_id', $run->id)->value('id'));
        $saved = json_decode(DB::table('sim_items')->where('id', $oldId)->value('metrics'), true);
        self::assertSame('election', $saved['_previous_inventory']['category']);
        self::assertSame('blocked_recovery', $saved['category']);
        self::assertSame('done', $source->refresh()->status);
    }

    public function test_conflicting_receipts_serialize_on_postgresql_and_reuse_the_committed_result(): void
    {
        $scope = $this->place(); $source = $this->source([$scope]); $run = app(SimRepairControl::class)->start($source->id);
        DB::commit(); // Fixture-only: a second connection must see the source and planned receipt.
        $cfg = DB::connection()->getConfig();
        $other = new \PDO('pgsql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['database'], $cfg['username'], $cfg['password']);
        $other->exec("SET lock_timeout='100ms'");
        $service = app(SimRepairService::class); $method = new \ReflectionMethod($service, 'action');
        $result = $method->invoke($service, $run, $scope, 'concurrency_test', $scope, function () use ($other, $source, $scope) {
            try {
                $query = $other->prepare("SELECT status FROM sim_repair_receipts WHERE source_run_id=? AND kind='concurrency_test' AND target_id=? FOR UPDATE");
                $query->execute([$source->id, $scope]); self::fail('Concurrent target ownership must wait');
            } catch (\PDOException $error) { self::assertSame('55P03', $error->getCode()); }
            return ['status' => 'done', 'once' => true];
        });
        self::assertSame('applied', $result['status']);
        self::assertTrue($method->invoke($service, $run, $scope, 'concurrency_test', $scope, fn () => throw new \LogicException('must not execute twice'))['reused']);
        $run->forceFill(['status' => 'done', 'phase' => 'done'])->save();
        unset($other); DB::beginTransaction();
    }
}
