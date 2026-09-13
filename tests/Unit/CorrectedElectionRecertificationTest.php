<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Handlers\ElectionResultsCertification;
use App\Jobs\Elections\TabulateRaceJob;
use App\Models\AuditEntry;
use App\Models\Candidacy;
use App\Models\ChamberVote;
use App\Models\ClockTimer;
use App\Models\Committee;
use App\Models\CommitteeSeat;
use App\Models\Election;
use App\Models\ElectionAudit;
use App\Models\ElectionCertification;
use App\Models\ElectionRace;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\Tabulation;
use App\Models\Term;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\AuditService;
use App\Services\CertificationService;
use App\Services\ClockService;
use App\Services\ConstitutionalDefaults;
use App\Services\ConstitutionalVersionService;
use App\Services\ElectionLifecycleService;
use App\Services\ReferendumService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use App\Services\VacancyService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Only private in-memory tables; actual handler, seating, clock and successor
 * services, actual audit reconciliation and vacancy/countback paths. Sealed
 * counts are supplied. Audit transport, resolved settings and referendum side
 * effects are doubles. No job, network or live DB execution.
 */
final class CorrectedElectionRecertificationTest extends TestCase
{
    private string $original;

    private ElectionResultsCertification $handler;

    private ElectionLifecycleService $lifecycle;

    private AuditService $audit;

    private array $initial;

    private array $memberIds;

    private array $termIds;

    private string $cycleTimerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config([
            'database.connections.recertification_review' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.default' => 'array',
            'queue.default' => 'sync',
        ]);
        DB::setDefaultConnection('recertification_review');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        Bus::fake();
        Http::preventStrayRequests();
        ConstitutionalDefaults::flush();
        $this->travelTo(now()->setDate(2026, 9, 1)->startOfDay());

        $tables = [
            'legislatures' => 'jurisdiction_id status speaker_id term_starts_on term_ends_on term_number total_seats type_a_seats type_b_seats',
            'legislature_members' => 'legislature_id user_id status is_speaker seat_type seat_no vote_share_norm term_id district_id elected_in_race_id election_id seated_on seated_at term_ends_on home_jurisdiction_id vacated_at vacancy_reason',
            'terms' => 'legislature_id jurisdiction_id status term_class starts_on ends_on office_kind office_type office_id holder_user_id source_election_id',
            'clock_timers' => 'clock_id jurisdiction_id subject_type subject_id state payload armed_at fires_at',
            'clocks' => '',
            'committees' => 'legislature_id name purpose status chair_member_id alternate_member_id seats type_a_seats type_b_seats',
            'committee_seats' => 'committee_id member_id status seat_kind assigned_via seated_at vacated_at vacated_reason',
            'chamber_votes' => 'body_type body_id legislature_id votable_type votable_id vote_type status outcome decided_at rcv_record',
            'elections' => 'legislature_id jurisdiction_id kind vacancy_id election_board_id status certified_at prior_election_id constitutional_version trigger voting_method approval_opens_at district_map_id',
            'election_races' => 'election_id seat_kind district_id status jurisdiction_id seats finalist_count electorate_type',
            'election_boards' => 'jurisdiction_id status',
            'election_board_members' => 'election_board_id user_id status',
            'election_certifications' => 'election_id election_board_id certified_by_member_id certified_at count_record_hash status',
            'election_audits' => 'election_id race_id cause ordered_by ordered_at tabulation_id outcome resolved_at',
            'tabulations' => 'race_id kind status record_hash completed_at',
            'race_results' => 'tabulation_id candidacy_id seat_no vote_share_norm',
            'candidacies' => 'race_id user_id status',
            'residency_confirmations' => 'user_id jurisdiction_id is_active depth',
            'executives' => 'jurisdiction_id source_legislature_id status type',
            'judiciaries' => 'jurisdiction_id source_legislature_id status type',
            'executive_members' => 'executive_id user_id role rank joined_at left_at legislature_member_id elected_in_race_id term_id selection status',
            'vacancies' => 'seat_type seat_id legislature_id jurisdiction_id declared_by declared_via_form status detected_at declared_at countback_tabulation_id special_election_id filled_by_user_id filled_at',
            'legislature_district_maps' => 'legislature_id status',
            'constitutional_settings' => 'jurisdiction_id legislature_min_seats legislature_max_seats',
        ];
        foreach ($tables as $name => $columns) {
            DB::connection()->getSchemaBuilder()->create($name, function (Blueprint $t) use ($columns): void {
                $t->string('id')->primary();
                foreach (array_filter(explode(' ', $columns)) as $column) {
                    $t->text($column)->nullable();
                }
                $t->timestamps();
                $t->softDeletes();
            });
        }
        DB::statement("CREATE UNIQUE INDEX fixture_current_certification ON election_certifications (election_id) WHERE status = 'certified'");
        DB::statement("CREATE UNIQUE INDEX fixture_current_member ON legislature_members (legislature_id, user_id) WHERE status IN ('seated', 'elected') AND deleted_at IS NULL");
        DB::statement('CREATE UNIQUE INDEX fixture_live_committee_seat ON committee_seats (committee_id, member_id) WHERE vacated_at IS NULL');
        DB::table('clocks')->insert([['id' => 'CLK-01'], ['id' => 'CLK-10']]);
        DB::table('constitutional_settings')->insert(['id' => 'settings', 'jurisdiction_id' => 'place', 'legislature_min_seats' => 5, 'legislature_max_seats' => 9]);

        $this->audit = Mockery::mock(AuditService::class);
        $this->audit->shouldReceive('append')->andReturn(new AuditEntry);
        $settings = Mockery::mock(SettingsResolver::class);
        $settings->shouldReceive('resolveInt')->with('place', 'election_interval_months', 60)->andReturn(48);
        $settings->shouldReceive('resolveInt')->with('place', 'approval_min_days', 30)->andReturn(30);
        $settings->shouldReceive('resolveInt')->with('place', 'ranked_window_days', 14)->andReturn(14);
        $settings->shouldReceive('resolveInt')->with('place', 'finalist_multiplier', 3)->andReturn(3);
        $settings->shouldReceive('resolve')->with('place', 'voting_method')->andReturn('stv_droop');
        $referendums = Mockery::mock(ReferendumService::class);
        $referendums->shouldReceive('certifyForElection')->andReturn([]);
        $referendums->shouldReceive('releaseShields')->andReturn(0);
        $referendums->shouldReceive('attachQueued')->andReturn(0);
        $approvals = Mockery::mock(ApprovalService::class); // No approval tally method is invoked.
        $this->app->instance(AuditService::class, $this->audit);
        $this->app->instance(SettingsResolver::class, $settings);
        $this->app->instance(ReferendumService::class, $referendums);
        $clocks = new ClockService($this->audit, $settings);
        $this->lifecycle = new ElectionLifecycleService($this->audit, $clocks, $settings, $approvals);
        $pipeline = new CertificationService($this->audit, $clocks, $settings, $this->lifecycle, app(RoleService::class));
        $this->app->instance(CertificationService::class, $pipeline);
        $this->app->instance(ClockService::class, $clocks);
        $this->app->instance(ElectionLifecycleService::class, $this->lifecycle);
        $this->handler = new ElectionResultsCertification($pipeline);

        $this->seedCount();
        $this->initial = $this->certify();
        $this->memberIds = array_column($this->initial['winners'], 'member_id');
        $this->termIds = array_column($this->initial['terms'], 'term_id');
        $this->cycleTimerId = ClockTimer::armed()->where('clock_id', 'CLK-01')->sole()->id;
        self::assertSame(['starts_on' => '2026-09-01', 'ends_on' => '2030-09-01', 'inherited' => false], $this->initial['term_window']);
        self::assertSame(1, Legislature::findOrFail('chamber')->term_number);
        self::assertSame('approval_open', Election::findOrFail($this->initial['next_election_id'])->status);
        $this->organizeExistingTerm();
        $this->travelTo(now()->setDate(2026, 9, 13)->startOfDay());
    }

    protected function tearDown(): void
    {
        ConstitutionalDefaults::flush();
        $this->travelBack();
        DB::purge('recertification_review');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    public function test_corrected_same_election_preserves_term_offices_and_existing_successor(): void
    {
        $this->prepareAudit(changedHash: true);
        self::assertSame('corrected', ElectionAudit::findOrFail('audit')->outcome);
        self::assertSame('audit_rerun', Election::findOrFail('election')->status);
        self::assertSame('superseded', Tabulation::findOrFail('initial-count')->status);
        $result = $this->certify();

        self::assertSame($this->initial['certification_id'], $result['superseded_certification']);
        self::assertSame('superseded_by_audit', ElectionCertification::findOrFail($this->initial['certification_id'])->status);
        self::assertSame(1, ElectionCertification::where('status', 'certified')->count());
        self::assertNotSame($this->initial['count_record_hash'], $result['count_record_hash']);
        self::assertSame(array_column($this->initial['winners'], 'user_id'), array_column($result['winners'], 'user_id'));
        self::assertSame(1, Legislature::findOrFail('chamber')->term_number);
        self::assertSame(['starts_on' => '2026-09-01', 'ends_on' => '2030-09-01', 'inherited' => true], $result['term_window']);
        self::assertSame('2030-09-01', Legislature::findOrFail('chamber')->term_ends_on->toDateString());
        self::assertSame('2026-09-01', Election::findOrFail('election')->certified_at->toDateString());
        self::assertSame('2026-09-13', ElectionCertification::findOrFail($result['certification_id'])->certified_at->toDateString());
        self::assertSame(5, LegislatureMember::whereIn('id', $this->memberIds)->where('status', 'seated')->count());
        self::assertSame(5, Term::whereIn('id', $this->termIds)->where('status', 'active')->count());
        self::assertSame(5, Term::whereIn('id', $this->termIds)->whereDate('ends_on', '2030-09-01')->count());
        self::assertSame($this->memberIds, array_column($result['winners'], 'member_id'));
        self::assertSame($this->termIds, array_column($result['terms'], 'term_id'));
        self::assertSame(5, LegislatureMember::count());
        self::assertSame('1.2500', LegislatureMember::findOrFail($this->memberIds[0])->vote_share_norm);
        self::assertSame($this->memberIds[0], Legislature::findOrFail('chamber')->speaker_id);
        self::assertSame(1, LegislatureMember::where('is_speaker', true)->count());
        self::assertSame('seated', Committee::findOrFail('committee')->status);
        self::assertSame($this->memberIds[0], Committee::findOrFail('committee')->chair_member_id);
        self::assertSame($this->memberIds[1], Committee::findOrFail('committee')->alternate_member_id);
        self::assertSame('seated', CommitteeSeat::findOrFail('placement')->status);
        self::assertNull(CommitteeSeat::findOrFail('placement')->vacated_at);
        self::assertSame('open', ChamberVote::findOrFail('open-chair')->status);
        self::assertSame('closed', ChamberVote::findOrFail('closed-chair')->status);
        self::assertSame('armed', ClockTimer::findOrFail($this->cycleTimerId)->state);
        self::assertSame(5, ClockTimer::whereIn('subject_id', $this->termIds)->where('state', 'armed')->count());
        self::assertSame(5, ClockTimer::armed()->where('clock_id', 'CLK-10')->count());
        $nextTimer = ClockTimer::armed()->where('clock_id', 'CLK-01')->sole();
        self::assertSame($this->cycleTimerId, $nextTimer->id);
        self::assertSame(1, Election::where('prior_election_id', 'election')->where('status', 'approval_open')->count());
        self::assertSame($this->initial['next_election_id'], $result['next_election_id']);
        self::assertSame(1, ElectionRace::where('election_id', $result['next_election_id'])->where('status', 'approval_open')->count());
        self::assertSame('approval_open', Election::findOrFail($this->initial['next_election_id'])->status);
        Bus::assertDispatchedTimes(\App\Jobs\EvaluateSocialStructureJob::class, 1);
        Bus::assertNotDispatched(\App\Jobs\Elections\RunCountbackJob::class);
        Http::assertNothingSent();
    }

    public function test_reaffirmed_audit_preserves_existing_term_and_rejects_a_second_certification(): void
    {
        $this->prepareAudit(changedHash: false);
        self::assertSame('reaffirmed', ElectionAudit::findOrFail('audit')->outcome);
        self::assertSame('certified', Election::findOrFail('election')->status);
        try {
            $this->certify();
            self::fail('A reaffirmed result was recertified.');
        } catch (ConstitutionalViolation $e) {
            self::assertStringContainsString('not certifiable', $e->getMessage());
        }
        // Even an incorrect caller putting the election back into audit_rerun
        // cannot bypass the corrected-outcome gate.
        DB::table('elections')->where('id', 'election')->update(['status' => 'audit_rerun']);
        try {
            $this->certify();
            self::fail('A second certification without a corrected audit was accepted.');
        } catch (ConstitutionalViolation $e) {
            self::assertStringContainsString("outcome 'corrected'", $e->getMessage());
        }
        $this->assertExistingTermUntouched();
    }

    public function test_changed_winner_retires_only_displaced_authority_and_inherits_original_expiry(): void
    {
        $this->prepareAudit(changedHash: true);
        $this->replaceFirstAuditWinner();
        foreach ([0, 1] as $index) {
            DB::table('executive_members')->insert(['id' => "delegate-$index", 'executive_id' => 'delegated-office', 'user_id' => 'winner-'.($index + 1), 'legislature_member_id' => $this->memberIds[$index], 'selection' => 'delegated_proportional', 'status' => 'seated']);
        }
        DB::table('committees')->insert(['id' => 'unaffected', 'legislature_id' => 'chamber', 'status' => 'seated', 'chair_member_id' => $this->memberIds[1], 'alternate_member_id' => $this->memberIds[2], 'seats' => 2]);
        DB::table('committee_seats')->insert(['id' => 'unaffected-placement', 'committee_id' => 'unaffected', 'member_id' => $this->memberIds[1], 'status' => 'seated']);
        $result = $this->certify();
        self::assertSame([$this->memberIds[0]], $result['displaced_member_ids']);
        self::assertCount(1, $result['new_member_ids']);
        $incoming = LegislatureMember::findOrFail($result['new_member_ids'][0]);
        self::assertSame('replacement-user', $incoming->user_id);
        self::assertSame('elected', $incoming->status);
        self::assertSame(1, $incoming->seat_no);
        self::assertSame('2026-09-13', $incoming->seated_on->toDateString());
        self::assertSame('2030-09-01', $incoming->term->ends_on->toDateString());
        self::assertSame('active', $incoming->term->status);
        self::assertSame('vacated', LegislatureMember::findOrFail($this->memberIds[0])->status);
        self::assertSame('audit_correction', LegislatureMember::findOrFail($this->memberIds[0])->vacancy_reason);
        self::assertSame('vacated', Term::findOrFail($this->termIds[0])->status);
        self::assertSame('2030-09-01', Term::findOrFail($this->termIds[0])->ends_on->toDateString());
        self::assertSame('vacated', CommitteeSeat::findOrFail('placement')->status);
        self::assertSame('chamber_vacancy', CommitteeSeat::findOrFail('placement')->vacated_reason);
        self::assertNull(Committee::findOrFail('committee')->chair_member_id);
        self::assertNull(Legislature::findOrFail('chamber')->speaker_id);
        self::assertSame($this->memberIds[1], Committee::findOrFail('committee')->alternate_member_id);
        self::assertSame('seated', Committee::findOrFail('committee')->status);
        self::assertSame('seated', CommitteeSeat::findOrFail('unaffected-placement')->status);
        self::assertSame($this->memberIds[1], Committee::findOrFail('unaffected')->chair_member_id);
        self::assertSame(4, LegislatureMember::whereIn('id', array_slice($this->memberIds, 1))->where('status', 'seated')->count());
        self::assertSame('left', DB::table('executive_members')->where('id', 'delegate-0')->value('status'));
        self::assertSame('seated', DB::table('executive_members')->where('id', 'delegate-1')->value('status'));
        self::assertSame('filled', DB::table('vacancies')->sole()->status);
        self::assertSame('replacement-user', DB::table('vacancies')->sole()->filled_by_user_id);
        self::assertSame('open', ChamberVote::findOrFail('open-chair')->status);
        self::assertSame('closed', ChamberVote::findOrFail('closed-chair')->status);
        self::assertSame('armed', ClockTimer::findOrFail($this->cycleTimerId)->state);
        self::assertSame(5, ClockTimer::armed()->where('clock_id', 'CLK-10')->count());
        self::assertSame(1, Legislature::findOrFail('chamber')->term_number);
        self::assertSame($this->initial['next_election_id'], $result['next_election_id']);
        self::assertSame('2026-09-01', Election::findOrFail('election')->certified_at->toDateString());
        Bus::assertDispatchedTimes(\App\Jobs\EvaluateSocialStructureJob::class, 1);
        Bus::assertNotDispatched(\App\Jobs\Elections\RunCountbackJob::class);
    }

    public function test_metadata_correction_preserves_an_independent_resignation_and_countback_replacement(): void
    {
        $replacement = $this->independentCountback();
        $before = $replacement->refresh()->getAttributes();
        $this->prepareAudit(changedHash: true);
        $result = $this->certify();
        self::assertSame([], $result['displaced_member_ids']);
        self::assertSame([], $result['new_member_ids']);
        self::assertSame($before, $replacement->refresh()->getAttributes());
        self::assertSame('vacated', LegislatureMember::findOrFail($this->memberIds[0])->status);
        self::assertSame('resigned', LegislatureMember::findOrFail($this->memberIds[0])->vacancy_reason);
        self::assertSame('1.2000', LegislatureMember::findOrFail($this->memberIds[0])->vote_share_norm);
        self::assertSame(5, LegislatureMember::current()->count());
        self::assertSame(1, DB::table('vacancies')->count());
        self::assertSame('filled', DB::table('vacancies')->sole()->status);
        self::assertSame(1, Election::where('prior_election_id', 'election')->count());
        Bus::assertNotDispatched(\App\Jobs\Elections\RunCountbackJob::class);
    }

    public function test_changed_winner_with_independent_vacancy_history_refuses_atomically(): void
    {
        $this->independentCountback();
        $this->prepareAudit(changedHash: true);
        $this->replaceFirstAuditWinner();
        $this->assertRefusedWithoutMutation('independent vacancy or replacement history');
    }

    public function test_historical_audit_cannot_change_the_later_current_term(): void
    {
        DB::table('legislatures')->where('id', 'chamber')->update(['term_number' => 2, 'term_starts_on' => '2030-09-01', 'term_ends_on' => '2034-09-01']);
        $this->prepareAudit(changedHash: true);
        $this->assertRefusedWithoutMutation('different or unresolvable chamber term');
    }

    public function test_retry_does_not_repeat_reconciliation_even_if_a_stale_caller_restores_audit_status(): void
    {
        $this->prepareAudit(changedHash: true);
        $this->certify();
        $this->assertRefusedWithoutMutation('not certifiable');
        DB::table('elections')->where('id', 'election')->update(['status' => 'audit_rerun']);
        $this->assertRefusedWithoutMutation('already certified');
    }

    public function test_unresolvable_prior_snapshot_rolls_back_certification_before_touching_offices(): void
    {
        $this->prepareAudit(changedHash: true);
        DB::table('tabulations')->where('id', 'initial-count')->update(['record_hash' => hash('sha256', 'wrong prior snapshot')]);
        $this->assertRefusedWithoutMutation('do not match the certification');
    }

    public function test_failed_replacement_insert_rolls_back_vacancy_offices_and_certification(): void
    {
        $this->prepareAudit(changedHash: true);
        $this->replaceFirstAuditWinner();
        DB::statement("CREATE TRIGGER reject_synthetic_replacement BEFORE INSERT ON terms WHEN NEW.holder_user_id = 'replacement-user' BEGIN SELECT RAISE(ABORT, 'synthetic replacement failure'); END");
        $before = $this->stateSnapshot();
        try {
            $this->certify();
            self::fail('Fixture insert failure did not reach the transaction boundary.');
        } catch (\Illuminate\Database\QueryException $e) {
            self::assertStringContainsString('synthetic replacement failure', $e->getMessage());
        }
        self::assertSame($before, $this->stateSnapshot());
        $this->assertExistingTermUntouched();
        Bus::assertNotDispatched(\App\Jobs\Elections\RunCountbackJob::class);
    }

    private function assertRefusedWithoutMutation(string $message): void
    {
        $before = $this->stateSnapshot();
        try {
            $this->certify();
            self::fail('Unsafe correction was accepted.');
        } catch (ConstitutionalViolation $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertSame($before, $this->stateSnapshot());
        Bus::assertNotDispatched(\App\Jobs\Elections\RunCountbackJob::class);
    }

    private function stateSnapshot(): array
    {
        $tables = ['elections', 'election_certifications', 'legislatures', 'legislature_members', 'terms', 'clock_timers', 'committees', 'committee_seats', 'chamber_votes', 'vacancies', 'executive_members'];

        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), $tables);
    }

    private function replaceFirstAuditWinner(): void
    {
        DB::table('candidacies')->insertOrIgnore(['id' => 'replacement-candidate', 'race_id' => 'race', 'user_id' => 'replacement-user', 'status' => 'defeated']);
        DB::table('race_results')->where('id', 'audit-result-1')->update(['candidacy_id' => 'replacement-candidate']);
    }

    private function independentCountback(): LegislatureMember
    {
        $this->travelTo(now()->setDate(2026, 9, 5)->startOfDay());
        $vacancy = app(VacancyService::class)->declare(LegislatureMember::findOrFail($this->memberIds[0]), 'resigned', queueCountback: false);
        DB::table('candidacies')->insert(['id' => 'countback-candidate', 'race_id' => 'race', 'user_id' => 'countback-user', 'status' => 'defeated']);
        DB::table('tabulations')->insert(['id' => 'countback-count', 'race_id' => 'race', 'kind' => 'countback', 'status' => 'complete', 'record_hash' => hash('sha256', 'synthetic countback'), 'completed_at' => now()]);
        DB::table('race_results')->insert(['id' => 'countback-result', 'tabulation_id' => 'countback-count', 'candidacy_id' => 'countback-candidate', 'seat_no' => 1, 'vote_share_norm' => '1.1']);
        $member = app(CertificationService::class)->certifyCountback($vacancy, Tabulation::findOrFail('countback-count'), Candidacy::findOrFail('countback-candidate'));
        $this->travelTo(now()->setDate(2026, 9, 13)->startOfDay());

        return $member;
    }

    public function test_corrected_audit_still_requires_the_responsible_board_member(): void
    {
        $this->prepareAudit(changedHash: true);
        try {
            $this->certify('unseated-outsider');
            self::fail('An outsider certified the corrected count.');
        } catch (ConstitutionalViolation $e) {
            self::assertStringContainsString('seated member of the responsible board', $e->getMessage());
        }
        $this->assertExistingTermUntouched();
    }

    private function assertExistingTermUntouched(): void
    {
        self::assertSame(1, Legislature::findOrFail('chamber')->term_number);
        self::assertSame('2030-09-01', Legislature::findOrFail('chamber')->term_ends_on->toDateString());
        self::assertSame($this->memberIds[0], Legislature::findOrFail('chamber')->speaker_id);
        self::assertSame(5, LegislatureMember::whereIn('id', $this->memberIds)->where('status', 'seated')->count());
        self::assertSame(5, Term::whereIn('id', $this->termIds)->where('status', 'active')->count());
        self::assertSame('seated', Committee::findOrFail('committee')->status);
        self::assertNull(CommitteeSeat::findOrFail('placement')->vacated_at);
        self::assertSame('open', ChamberVote::findOrFail('open-chair')->status);
        self::assertSame('armed', ClockTimer::findOrFail($this->cycleTimerId)->state);
        self::assertSame(1, Election::where('prior_election_id', 'election')->count());
        self::assertSame(1, ElectionCertification::count());
        self::assertSame('certified', ElectionCertification::findOrFail($this->initial['certification_id'])->status);
        Http::assertNothingSent();
    }

    private function certify(string $actorId = 'board-user'): array
    {
        $actor = (new User)->forceFill(['id' => $actorId]);

        // Reproduce the handler's required surrounding engine transaction.
        // This does not claim the outer engine/validator/audit chain was run.
        return DB::transaction(fn () => $this->handler->handle($actor, ['election_id' => 'election']));
    }

    private function seedCount(): void
    {
        DB::table('legislatures')->insert(['id' => 'chamber', 'jurisdiction_id' => 'place', 'status' => 'forming', 'term_number' => 1, 'total_seats' => 5, 'type_a_seats' => 5, 'type_b_seats' => 0]);
        DB::table('election_boards')->insert(['id' => 'board', 'jurisdiction_id' => 'place', 'status' => 'active']);
        DB::table('election_board_members')->insert(['id' => 'board-seat', 'election_board_id' => 'board', 'user_id' => 'board-user', 'status' => 'seated']);
        DB::table('elections')->insert(['id' => 'election', 'legislature_id' => 'chamber', 'jurisdiction_id' => 'place', 'kind' => 'general', 'election_board_id' => 'board', 'status' => 'tabulating', 'constitutional_version' => app(ConstitutionalVersionService::class)->derive()]);
        DB::table('election_races')->insert(['id' => 'race', 'election_id' => 'election', 'seat_kind' => 'type_a', 'status' => 'tabulating', 'seats' => 5]);
        DB::table('tabulations')->insert(['id' => 'initial-count', 'race_id' => 'race', 'kind' => 'initial', 'status' => 'complete', 'record_hash' => hash('sha256', 'synthetic initial sealed count'), 'completed_at' => now()]);
        for ($n = 1; $n <= 5; $n++) {
            DB::table('candidacies')->insert(['id' => "candidate-$n", 'race_id' => 'race', 'user_id' => "winner-$n", 'status' => 'finalist']);
            DB::table('race_results')->insert(['id' => "initial-result-$n", 'tabulation_id' => 'initial-count', 'candidacy_id' => "candidate-$n", 'seat_no' => $n, 'vote_share_norm' => '1.2']);
        }
    }

    private function organizeExistingTerm(): void
    {
        DB::table('legislature_members')->whereIn('id', $this->memberIds)->update(['status' => 'seated']);
        DB::table('legislature_members')->where('id', $this->memberIds[0])->update(['is_speaker' => true]);
        DB::table('legislatures')->where('id', 'chamber')->update(['speaker_id' => $this->memberIds[0]]);
        DB::table('committees')->insert(['id' => 'committee', 'legislature_id' => 'chamber', 'name' => 'Synthetic committee', 'status' => 'seated', 'chair_member_id' => $this->memberIds[0], 'alternate_member_id' => $this->memberIds[1], 'seats' => 3]);
        DB::table('committee_seats')->insert(['id' => 'placement', 'committee_id' => 'committee', 'member_id' => $this->memberIds[0], 'status' => 'seated', 'seated_at' => now()]);
        foreach (['open-chair' => 'open', 'closed-chair' => 'closed'] as $id => $status) {
            DB::table('chamber_votes')->insert(['id' => $id, 'body_type' => 'legislature', 'body_id' => 'chamber', 'vote_type' => 'committee_chair', 'votable_type' => 'committee', 'votable_id' => 'committee', 'status' => $status]);
        }
    }

    private function prepareAudit(bool $changedHash): void
    {
        DB::table('elections')->where('id', 'election')->update(['status' => 'audit_rerun']);
        DB::table('election_audits')->insert(['id' => 'audit', 'election_id' => 'election', 'race_id' => 'race', 'cause' => 'synthetic review', 'ordered_by' => 'board-user', 'ordered_at' => now()->subDay()]);
        DB::table('tabulations')->insert(['id' => 'audit-count', 'race_id' => 'race', 'kind' => 'audit_rerun', 'status' => 'complete', 'record_hash' => hash('sha256', $changedHash ? 'synthetic corrected sealed count' : 'synthetic initial sealed count'), 'completed_at' => now()]);
        for ($n = 1; $n <= 5; $n++) {
            DB::table('race_results')->insert(['id' => "audit-result-$n", 'tabulation_id' => 'audit-count', 'candidacy_id' => "candidate-$n", 'seat_no' => $n, 'vote_share_norm' => $changedHash ? '1.25' : '1.2']);
        }
        // Invoke only the actual post-count audit-resolution stage. The private
        // method seam avoids running ballot decryption/counting or a real job;
        // it proves that changed sealed hashes, with unchanged winners, really
        // become 'corrected' and remain eligible for board recertification.
        $job = new TabulateRaceJob('race', Tabulation::KIND_AUDIT_RERUN, 'audit');
        (new ReflectionMethod($job, 'resolveAudit'))->invoke($job, ElectionRace::findOrFail('race'), $this->lifecycle, $this->audit);
    }
}
