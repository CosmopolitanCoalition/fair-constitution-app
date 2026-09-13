<?php

namespace Tests\Unit;

use App\Domain\Counting\BallotSet;
use App\Domain\Counting\CountInput;
use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Domain\Forms\Contracts\ElectionSchedulingDelegate;
use App\Jobs\Clocks\ScheduleGeneralElectionJob;
use App\Models\AuditEntry;
use App\Models\Candidacy;
use App\Models\ChamberVote;
use App\Models\Clock;
use App\Models\ClockTimer;
use App\Models\Committee;
use App\Models\CommitteeSeat;
use App\Models\ConstitutionalSettings;
use App\Models\Election;
use App\Models\ElectionAudit;
use App\Models\ElectionBoard;
use App\Models\ElectionCertification;
use App\Models\ElectionRace;
use App\Models\Executive;
use App\Models\ExecutiveMember;
use App\Models\InstanceSettings;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\Jurisdiction;
use App\Models\Legislature;
use App\Models\LegislatureDistrictMap;
use App\Models\LegislatureMember;
use App\Models\RaceResult;
use App\Models\Tabulation;
use App\Models\Term;
use App\Models\Vacancy;
use App\Services\ApprovalService;
use App\Services\AuditService;
use App\Services\CertificationService;
use App\Services\ClockService;
use App\Services\ConstitutionalDefaults;
use App\Services\ConstitutionalValidator;
use App\Services\ConstitutionalVersionService;
use App\Services\Education\TrainingGateService;
use App\Services\ElectionLifecycleService;
use App\Services\ReferendumService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use App\Services\TabulationRecorder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Actual lifecycle, CLK-01 form job, office seating and immutable clocks on private SQLite.
 * Sealed upstream results and anonymous ranked count inputs are fixture data.
 * Audit/settings/role lookup, referendum effects and ballot retrieval are doubles.
 */
final class RecurringElectedOfficeCycleTest extends TestCase
{
    private string $original;

    private ElectionLifecycleService $lifecycle;

    private CertificationService $certification;

    private int $sequence = 100;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.recurring_offices_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'cga.demo_session_capture' => false]);
        DB::setDefaultConnection('recurring_offices_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->travelTo(now()->setDate(2026, 9, 13)->startOfDay());
        Bus::fake();
        ConstitutionalDefaults::flush();
        foreach ([Candidacy::class, ChamberVote::class, Clock::class, ClockTimer::class, Committee::class, CommitteeSeat::class,
            ConstitutionalSettings::class, Election::class, ElectionAudit::class, ElectionBoard::class, ElectionCertification::class, ElectionRace::class,
            Executive::class, ExecutiveMember::class, InstanceSettings::class, Judiciary::class, JudicialSeat::class, Jurisdiction::class,
            Legislature::class, LegislatureDistrictMap::class, LegislatureMember::class, RaceResult::class, Term::class, Tabulation::class, Vacancy::class] as $class) {
            $model = new $class;
            DB::connection()->getSchemaBuilder()->create($model->getTable(), function (Blueprint $t) use ($model) {
                foreach (array_unique([...$model->getFillable(), 'id', 'created_at', 'updated_at', 'deleted_at']) as $column) {
                    if ($column === 'id') {
                        $t->string($column)->primary();
                    } else {
                        $t->text($column)->nullable();
                    }
                }
            });
        }
        DB::connection()->getSchemaBuilder()->create('residency_confirmations', function (Blueprint $t) {
            $t->string('user_id');
            $t->string('jurisdiction_id');
            $t->boolean('is_active');
            $t->integer('depth')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX fixture_exec_cycle ON elections (general_cycle_election_id, executive_id)');
        DB::statement('CREATE UNIQUE INDEX fixture_judicial_cycle ON elections (general_cycle_election_id, judiciary_id)');
        InstanceSettings::create(['id' => $this->id(99), 'instance_name' => 'Private election cycle', 'instance_class' => 'production']);
        DB::table('jurisdictions')->insert(['id' => $this->id(1), 'name' => 'Fixture place', 'adm_level' => 0]);
        DB::table('constitutional_settings')->insert(['id' => $this->id(2), 'jurisdiction_id' => $this->id(1), 'legislature_min_seats' => 5, 'legislature_max_seats' => 9]);
        foreach (['CLK-01', 'CLK-09', 'CLK-10', 'CLK-18'] as $id) {
            Clock::create(['id' => $id, 'name' => $id, 'type' => 'countdown']);
        }
        Legislature::create(['id' => $this->id(3), 'jurisdiction_id' => $this->id(1), 'status' => 'active', 'total_seats' => 5,
            'type_a_seats' => 5, 'type_b_seats' => 0, 'term_number' => 1, 'term_starts_on' => '2022-09-13', 'term_ends_on' => '2026-09-13']);
        $audit = $this->createMock(AuditService::class);
        $audit->method('append')->willReturn(new AuditEntry);
        $settings = $this->createMock(SettingsResolver::class);
        $settings->method('resolveInt')->willReturnCallback(fn ($jid, $key, $fallback) => match ($key) {
            'election_interval_months' => 48, 'finalist_multiplier' => 4, default => $fallback,
        });
        $settings->method('resolve')->willReturn('stv_droop');
        $version = $this->createMock(ConstitutionalVersionService::class);
        $version->method('derive')->willReturn('private-fixture');
        $roles = $this->createMock(RoleService::class);
        $clock = new ClockService($audit, $settings);
        $this->lifecycle = new ElectionLifecycleService($audit, $clock, $settings, $this->createMock(ApprovalService::class));
        $this->certification = new CertificationService($audit, $clock, $settings, $this->lifecycle, $roles);
        $referendums = $this->createMock(ReferendumService::class);
        $referendums->method('certifyForElection')->willReturn([]);
        $referendums->method('releaseShields')->willReturn(0);
        $engine = new ConstitutionalEngine($audit, new ConstitutionalValidator, $this->createMock(ResolvesRoles::class), $this->createMock(TrainingGateService::class));
        foreach ([AuditService::class => $audit, SettingsResolver::class => $settings, ConstitutionalVersionService::class => $version,
            RoleService::class => $roles, ReferendumService::class => $referendums, ElectionLifecycleService::class => $this->lifecycle,
            ElectionSchedulingDelegate::class => $this->lifecycle, ConstitutionalEngine::class => $engine,
            ClockService::class => $clock, CertificationService::class => $this->certification] as $class => $instance) {
            $this->app->instance($class, $instance);
        }
        $recorder = $this->createMock(TabulationRecorder::class);
        $recorder->method('countInput')->willReturnCallback(function (ElectionRace $race) {
            $ids = Candidacy::where('race_id', $race->id)->orderBy('id')->pluck('id')->all();

            return new CountInput($ids, 1, BallotSet::fromGrouped([[$ids, 12]]), tieSeedBase: 'fixture');
        });
        $this->app->instance(TabulationRecorder::class, $recorder);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        ConstitutionalDefaults::flush();
        DB::purge('recurring_offices_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    public function test_two_general_cycles_retire_and_replace_elected_executives_and_judges(): void
    {
        $this->seedElectedOffices();
        $first = $this->general();
        $this->seal($first, 5);
        $result = $this->certify($first);
        $next = Election::findOrFail($result['next_election_id']);
        self::assertSame('approval_open', $next->status);
        $offices = $this->companions($next);
        self::assertCount(2, $offices);
        foreach ($offices as $office) {
            self::assertSame($next->approval_opens_at->toIso8601String(), $office->approval_opens_at->toIso8601String());
            self::assertNull($office->ranked_closes_at);
            self::assertSame(4 * $office->races()->sole()->seats, $office->races()->sole()->finalist_count);
        }
        self::assertSame($next->id, $this->lifecycle->openSuccessor($first)->id);
        self::assertCount(2, $this->companions($next));
        $timer = ClockTimer::armed()->where('subject_type', 'legislature')->sole();
        $this->travelTo($timer->fires_at);
        DB::table('clock_timers')->where('id', $timer->id)->update(['state' => 'fired']);
        (new ScheduleGeneralElectionJob($timer->id))->handle($this->lifecycle);
        self::assertSame($timer->id, $next->refresh()->triggered_by_timer_id);
        $activeTimerIds = ClockTimer::armed()->pluck('id')->sort()->values()->all();
        (new ScheduleGeneralElectionJob($timer->id))->handle($this->lifecycle);
        self::assertSame($activeTimerIds, ClockTimer::armed()->pluck('id')->sort()->values()->all());
        foreach ($offices as $office) {
            self::assertSame($next->ranked_closes_at->toIso8601String(), $office->refresh()->ranked_closes_at->toIso8601String());
            self::assertSame(3, ClockTimer::armed()->where('subject_id', $office->id)->count());
            $this->seal($office, $office->kind === 'executive' ? 2 : 3);
            $this->refused(fn () => $this->certify($office));
        }
        self::assertSame(0, ExecutiveMember::count());
        $this->travelTo($next->ranked_closes_at);
        $this->seal($next, 5);
        $result2 = $this->certify($next);
        foreach ($offices as $office) {
            $out = $this->certify($office);
            self::assertSame($result2['term_window'], $out['term_window']);
        }
        self::assertSame(2, ExecutiveMember::where('status', 'seated')->count());
        self::assertSame(3, JudicialSeat::where('status', 'seated')->count());
        self::assertSame(7, Judiciary::findOrFail($this->id(5))->judge_count, 'Unfilled seats do not shrink the authorized bench.');
        $oldOfficeTerms = Term::whereIn('office_kind', ['executive_seat', 'judicial_seat'])->get()->keyBy('id');
        $third = Election::findOrFail($result2['next_election_id']);
        $this->travelTo($next->refresh()->certified_at->addMonths(48));
        $this->seal($third, 5);
        $result3 = $this->certify($third);
        foreach ($oldOfficeTerms as $term) {
            self::assertSame('completed', $term->refresh()->status);
            self::assertSame($result2['term_window']['ends_on'], $term->ends_on->toDateString());
        }
        self::assertSame(0, ExecutiveMember::where('status', 'seated')->count());
        self::assertSame(0, JudicialSeat::where('status', 'seated')->count());
        foreach ($this->companions($third) as $office) {
            $this->seal($office, 2);
            self::assertSame($result3['term_window'], $this->certify($office)['term_window']);
        }
        $newIds = ExecutiveMember::where('status', 'seated')->pluck('id')->all();
        foreach ($offices as $oldElection) {
            $this->refused(fn () => $this->certify($oldElection));
        }
        self::assertSame($newIds, ExecutiveMember::where('status', 'seated')->pluck('id')->all());
        $electionCount = Election::count();
        (new ScheduleGeneralElectionJob($timer->id))->handle($this->lifecycle);
        self::assertSame($electionCount, Election::count(), 'A late old timer cannot schedule a new cycle.');
    }

    public function test_individual_conversion_keeps_remainder_and_next_cycle_replaces_advisors(): void
    {
        Legislature::whereKey($this->id(3))->update(['term_ends_on' => '2030-09-13']);
        Executive::create(['id' => $this->id(4), 'jurisdiction_id' => $this->id(1), 'source_legislature_id' => $this->id(3), 'status' => 'conversion_voted', 'type' => 'committee']);
        ExecutiveMember::create(['id' => $this->id(9), 'executive_id' => $this->id(4), 'user_id' => $this->id(90), 'role' => 'principal', 'status' => 'seated', 'selection' => 'delegated_proportional']);
        $conversion = $this->lifecycle->scheduleExecutive(Executive::findOrFail($this->id(4)), Legislature::findOrFail($this->id(3)), 'individual', 1);
        self::assertNull($conversion->general_cycle_election_id);
        $this->seal($conversion, 1, 5);
        $converted = $this->certify($conversion);
        self::assertTrue($converted['term_window']['inherited']);
        self::assertSame('2030-09-13', $converted['term_window']['ends_on']);
        self::assertSame('left', ExecutiveMember::findOrFail($this->id(9))->status);
        self::assertSame(1, ExecutiveMember::where('status', 'seated')->where('role', 'principal')->count());
        self::assertSame(4, ExecutiveMember::where('status', 'seated')->where('role', 'advisor')->count());
        $old = ExecutiveMember::where('status', 'seated')->pluck('id')->all();
        $general = $this->general();
        $this->lifecycle->syncGeneralCompanions($general);
        $companion = $this->companions($general)[0];
        self::assertSame(1, $companion->races()->sole()->seats);
        $this->travelTo(now()->setDate(2030, 9, 13));
        $this->seal($general, 5);
        $generalResult = $this->certify($general);
        self::assertSame(5, ExecutiveMember::whereIn('id', $old)->where('status', 'term_ended')->count());
        $this->seal($companion, 1, 5);
        $result = $this->certify($companion);
        self::assertSame($generalResult['term_window'], $result['term_window']);
        self::assertSame(1, ExecutiveMember::where('status', 'seated')->where('role', 'principal')->count());
        self::assertSame(4, ExecutiveMember::where('status', 'seated')->where('role', 'advisor')->count());
        self::assertSame('2026-09-13', Executive::findOrFail($this->id(4))->converted_at->toDateString());
    }

    public function test_foreign_anchor_or_current_office_cannot_certify_and_matching_dates_are_required(): void
    {
        $this->seedElectedOffices();
        $general = $this->general();
        $this->seal($general, 5);
        $this->certify($general);
        $next = Election::where('prior_election_id', $general->id)->sole();
        $office = $this->companions($next)[0];
        $this->seal($office, 2);
        foreach ([['general_cycle_election_id' => $this->id(800)], ['legislature_id' => $this->id(900)], ['jurisdiction_id' => $this->id(999)]] as $changes) {
            $before = $office->getAttributes();
            $office->forceFill($changes)->save();
            $this->refused(fn () => $this->certify($office));
            $office->setRawAttributes($before)->save();
        }
        self::assertSame(0, ExecutiveMember::count());
        self::assertSame(0, JudicialSeat::count());
    }

    public function test_changed_winner_general_correction_keeps_companions_on_the_original_window(): void
    {
        $this->seedElectedOffices();
        $general = $this->general();
        $this->lifecycle->syncGeneralCompanions($general);
        $this->seal($general, 5);
        $first = $this->certify($general);
        $race = $general->races()->sole();
        $oldCount = $race->tabulations()->sole();
        $oldCount->forceFill(['kind' => 'initial', 'status' => 'superseded'])->save();
        $previous = ElectionCertification::create(['election_id' => $general->id, 'certified_at' => now(), 'count_record_hash' => hash('sha256', $race->id.':'.$oldCount->record_hash), 'status' => 'superseded_by_audit']);
        $oldTermIds = Term::where('source_election_id', $general->id)->pluck('id')->all();
        $this->travelTo(now()->addDays(12));
        $corrected = Tabulation::create(['race_id' => $race->id, 'kind' => 'audit_rerun', 'status' => 'complete', 'record_hash' => 'changed-winner', 'completed_at' => now()]);
        $newCandidate = Candidacy::create(['race_id' => $race->id, 'user_id' => $this->id(900), 'status' => 'finalist']);
        foreach (RaceResult::where('tabulation_id', $oldCount->id)->orderBy('seat_no')->get() as $row) {
            RaceResult::create(['tabulation_id' => $corrected->id, 'candidacy_id' => $row->seat_no === 1 ? $newCandidate->id : $row->candidacy_id, 'seat_no' => $row->seat_no, 'vote_share_norm' => 1]);
        }
        ElectionAudit::create(['election_id' => $general->id, 'tabulation_id' => $corrected->id, 'outcome' => 'corrected', 'ordered_at' => now()->subDay(), 'resolved_at' => now()]);
        $cert = ElectionCertification::create(['election_id' => $general->id, 'certified_at' => now(), 'count_record_hash' => hash('sha256', $race->id.':changed-winner'), 'status' => 'certified']);
        $correction = DB::transaction(fn () => app(\App\Services\ElectionCertificationReconciliationService::class)
            ->reconcile($general->refresh(), $cert, $previous, [$race->id => 'changed-winner'], null));
        self::assertCount(1, $correction['new_member_ids']);
        self::assertSame('2026-09-25', LegislatureMember::findOrFail($correction['new_member_ids'][0])->term->starts_on->toDateString());
        self::assertSame('2030-09-13', LegislatureMember::findOrFail($correction['new_member_ids'][0])->term->ends_on->toDateString());
        foreach ($this->companions($general) as $companion) {
            $this->seal($companion, 2);
            self::assertSame($first['term_window'], $this->certify($companion)['term_window']);
        }
        self::assertSame(5, Term::whereIn('id', $oldTermIds)->whereDate('ends_on', '2030-09-13')->count());
    }

    public function test_date_confirmation_is_bounded_to_its_anchor_and_published_race_counts_stay_frozen(): void
    {
        $this->seedElectedOffices();
        $general = $this->general();
        $this->lifecycle->syncGeneralCompanions($general);
        $companion = $this->companions($general)[0];
        self::assertSame(6, $companion->races()->sole()->seats);
        self::assertSame(24, $companion->races()->sole()->finalist_count);
        $confirmed = $this->lifecycle->scheduleGeneral(Legislature::findOrFail($this->id(3)));
        self::assertSame($general->id, $confirmed->id);
        $firstDate = $companion->refresh()->ranked_closes_at;
        $newDates = $this->lifecycle->defaultDates($general->jurisdiction_id, now()->addDay());
        $this->lifecycle->scheduleGeneral(Legislature::findOrFail($this->id(3)), dates: $newDates);
        self::assertTrue($companion->refresh()->ranked_closes_at->gt($firstDate));
        self::assertSame(3, ClockTimer::armed()->where('subject_id', $companion->id)->count());
        self::assertSame(24, $companion->races()->sole()->finalist_count);
        self::assertSame(2, Election::where('general_cycle_election_id', $general->id)->count());
        $migration = require base_path('database/migrations/2026_09_13_110000_link_recurring_office_elections_to_general_cycles.php');
        $migration->up();
        $migration->up();
        self::assertTrue(DB::connection()->getSchemaBuilder()->hasColumn('elections', 'general_cycle_election_id'));
        try {
            Election::create(['general_cycle_election_id' => $general->id, 'executive_id' => $this->id(4), 'kind' => 'executive']);
            self::fail('Duplicate companion accepted.');
        } catch (\Illuminate\Database\QueryException) {
            self::assertSame(2, count($this->companions($general)));
        }
    }

    public function test_judicial_conversion_preserves_appointed_history_and_configured_vacancies(): void
    {
        Legislature::whereKey($this->id(3))->update(['term_ends_on' => '2030-09-13']);
        Judiciary::create(['id' => $this->id(5), 'jurisdiction_id' => $this->id(1), 'source_legislature_id' => $this->id(3), 'status' => 'conversion_voted', 'type' => 'appointed', 'judge_count' => 7]);
        $seat = JudicialSeat::create(['judiciary_id' => $this->id(5), 'user_id' => $this->id(80), 'seat_class' => 'committee_nominated', 'status' => 'seated']);
        $term = Term::create(['office_kind' => 'judicial_seat', 'office_type' => 'judicial_seats', 'office_id' => $seat->id, 'holder_user_id' => $seat->user_id, 'term_class' => 'civil_appointment', 'status' => 'active', 'starts_on' => '2025-01-01', 'ends_on' => '2035-01-01']);
        $seat->forceFill(['term_id' => $term->id])->save();
        $clock = app(ClockService::class)->arm('CLK-09', $this->id(1), 'term', $term->id, $term->ends_on);
        $conversion = $this->lifecycle->scheduleJudicial(Judiciary::findOrFail($this->id(5)), Legislature::findOrFail($this->id(3)), 7);
        self::assertNull($conversion->general_cycle_election_id);
        $this->seal($conversion, 3);
        $result = $this->certify($conversion);
        self::assertTrue($result['term_window']['inherited']);
        self::assertSame('2030-09-13', $result['term_window']['ends_on']);
        self::assertSame('term_ended', $seat->refresh()->status);
        self::assertSame('completed', $term->refresh()->status);
        self::assertSame('2035-01-01', $term->ends_on->toDateString());
        self::assertSame('cancelled', $clock->refresh()->state);
        self::assertSame(7, Judiciary::findOrFail($this->id(5))->judge_count);
        self::assertSame(3, JudicialSeat::where('status', 'seated')->count());
    }

    public function test_a_frozen_companion_prevents_partial_date_changes_to_its_general_cycle(): void
    {
        $this->seedElectedOffices();
        $general = $this->lifecycle->scheduleGeneral(Legislature::findOrFail($this->id(3)));
        $companion = $this->companions($general)[0];
        $before = $general->ranked_closes_at->toIso8601String();
        $timers = ClockTimer::armed()->pluck('id')->sort()->values()->all();
        $companion->forceFill(['status' => 'finalist_cutoff'])->save();
        $this->refused(fn () => $this->lifecycle->scheduleGeneral(Legislature::findOrFail($this->id(3)),
            dates: $this->lifecycle->defaultDates($general->jurisdiction_id, now()->addDays(2))));
        self::assertSame($before, $general->refresh()->ranked_closes_at->toIso8601String());
        self::assertSame($before, $companion->refresh()->ranked_closes_at->toIso8601String());
        self::assertSame($timers, ClockTimer::armed()->pluck('id')->sort()->values()->all());
    }

    public function test_outgoing_elected_rows_follow_their_term_identity_and_leave_neighboring_offices_untouched(): void
    {
        $this->seedElectedOffices();
        Legislature::create(['id' => $this->id(70), 'jurisdiction_id' => $this->id(71), 'status' => 'active']);
        Executive::create(['id' => $this->id(72), 'jurisdiction_id' => $this->id(71), 'source_legislature_id' => $this->id(70), 'status' => 'elected', 'type' => 'individual']);
        Judiciary::create(['id' => $this->id(73), 'jurisdiction_id' => $this->id(71), 'source_legislature_id' => $this->id(70), 'status' => 'appointed', 'type' => 'appointed']);
        $own = ExecutiveMember::create(['id' => $this->id(74), 'executive_id' => $this->id(4), 'user_id' => $this->id(84), 'status' => 'seated', 'selection' => 'succession', 'role' => 'principal']);
        $foreign = ExecutiveMember::create(['id' => $this->id(75), 'executive_id' => $this->id(72), 'user_id' => $this->id(85), 'status' => 'seated', 'selection' => 'elected_rcv', 'role' => 'principal']);
        $civil = JudicialSeat::create(['judiciary_id' => $this->id(73), 'user_id' => $this->id(86), 'status' => 'seated', 'seat_class' => 'committee_nominated']);
        foreach ([$own, $foreign, $civil] as $i => $row) {
            $term = Term::create(['office_kind' => $i === 2 ? 'judicial_seat' : 'executive_seat', 'office_type' => $row->getTable(), 'office_id' => $row->id,
                'holder_user_id' => $row->user_id, 'jurisdiction_id' => $i === 0 ? $this->id(1) : $this->id(71), 'legislature_id' => $i === 0 ? $this->id(3) : $this->id(70),
                'term_class' => $i === 2 ? 'civil_appointment' : 'lockstep', 'status' => 'active', 'starts_on' => '2022-09-13', 'ends_on' => '2030-09-13']);
            $row->forceFill(['term_id' => $term->id])->save();
        }
        $general = $this->general();
        $this->seal($general, 5);
        $this->certify($general);
        self::assertSame('term_ended', $own->refresh()->status);
        self::assertSame('completed', Term::findOrFail($own->term_id)->status);
        self::assertSame('seated', $foreign->refresh()->status);
        self::assertSame('active', Term::findOrFail($foreign->term_id)->status);
        self::assertSame('seated', $civil->refresh()->status);
        self::assertSame('active', Term::findOrFail($civil->term_id)->status);
        self::assertSame('2030-09-13', Term::findOrFail($own->term_id)->ends_on->toDateString());
    }

    private function seedElectedOffices(): void
    {
        Executive::create(['id' => $this->id(4), 'jurisdiction_id' => $this->id(1), 'source_legislature_id' => $this->id(3), 'status' => 'elected', 'type' => 'committee', 'delegated_member_count' => 2, 'converted_at' => '2022-09-13']);
        Judiciary::create(['id' => $this->id(5), 'jurisdiction_id' => $this->id(1), 'source_legislature_id' => $this->id(3), 'status' => 'elected', 'type' => 'elected', 'judge_count' => 7, 'converted_at' => '2022-09-13']);
        $old = Election::create(['jurisdiction_id' => $this->id(1), 'legislature_id' => $this->id(3), 'executive_id' => $this->id(4), 'kind' => 'executive', 'status' => 'certified', 'certified_at' => '2022-09-13']);
        ElectionRace::create(['election_id' => $old->id, 'jurisdiction_id' => $this->id(1), 'seat_kind' => 'exec_committee', 'seats' => 6, 'finalist_count' => 24]);
    }

    private function general(): Election
    {
        $general = Election::create(['jurisdiction_id' => $this->id(1), 'legislature_id' => $this->id(3), 'kind' => 'general', 'status' => 'approval_open', 'approval_opens_at' => now(), 'voting_method' => 'stv_droop']);
        ElectionRace::create(['election_id' => $general->id, 'jurisdiction_id' => $this->id(1), 'seat_kind' => 'type_a', 'seats' => 5, 'finalist_count' => 20]);

        return $general;
    }

    private function companions(Election $general): array
    {
        return Election::where('general_cycle_election_id', $general->id)->orderBy('kind')->get()->all();
    }

    private function seal(Election $election, int $winners, ?int $candidates = null): void
    {
        $race = $election->races()->sole();
        $tabulation = Tabulation::create(['race_id' => $race->id, 'status' => 'complete', 'record_hash' => 'sealed-fixture', 'completed_at' => now()]);
        for ($n = 1; $n <= ($candidates ?? $winners); $n++) {
            $candidate = Candidacy::create(['id' => $this->id(++$this->sequence), 'race_id' => $race->id, 'user_id' => $this->id(++$this->sequence), 'status' => 'finalist']);
            if ($n <= $winners) {
                RaceResult::create(['tabulation_id' => $tabulation->id, 'candidacy_id' => $candidate->id, 'seat_no' => $n, 'vote_share_norm' => 1]);
            }
        }
    }

    private function certify(Election $election): array
    {
        return DB::transaction(function () use ($election) {
            $fresh = Election::findOrFail($election->id);
            $fresh->forceFill(['status' => 'certified', 'certified_at' => now()])->save();

            return $this->certification->certify($fresh, (new ElectionCertification)->forceFill(['certified_at' => now()]));
        });
    }

    private function refused(callable $action): void
    {
        try {
            $action();
            self::fail('Invalid recurring office election accepted.');
        } catch (ConstitutionalViolation $error) {
            self::assertNotSame('', $error->getMessage());
        }
    }

    private function id(int $id): string
    {
        return sprintf('60000000-0000-4000-8000-%012d', $id);
    }
}
