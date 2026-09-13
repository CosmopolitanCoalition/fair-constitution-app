<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Domain\Forms\Contracts\CommitteeRoster;
use App\Jobs\Clocks\CivilTermExpiryJob;
use App\Models\Appointment;
use App\Models\AuditEntry;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\ChamberVote;
use App\Models\ChamberVoteTally;
use App\Models\Clock;
use App\Models\ClockTimer;
use App\Models\Department;
use App\Models\Executive;
use App\Models\ExecutiveMember;
use App\Models\GovernorRemovalRequest;
use App\Models\InstanceSettings;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\Organization;
use App\Models\PublicRecord;
use App\Models\Term;
use App\Models\User;
use App\Models\VoteCast;
use App\Services\AuditService;
use App\Services\ChamberVoteService;
use App\Services\CivilAppointmentService;
use App\Services\ClockService;
use App\Services\ConstitutionalValidator;
use App\Services\Education\TrainingGateService;
use App\Services\EnactmentService;
use App\Services\Executive\BoardGovernorService;
use App\Services\Judiciary\JudicialSeatService;
use App\Services\Legislature\ChamberActService;
use App\Services\Legislature\CommitteeService;
use App\Services\Legislature\ElectionBoardTransitionService;
use App\Services\Organizations\OrgBoardService;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use App\Services\VoteCountingService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Real nomination, consent votes, publication, civil term, clock and expiry on private SQLite.
 * Audit transport, global role lookup and settings are doubles. No live PostgreSQL or provisioning.
 */
final class CgcGovernorWorkflowTest extends TestCase
{
    private string $original;

    private ConstitutionalEngine $engine;

    private BoardGovernorService $governors;

    private ChamberActService $acts;

    private ClockService $clocks;

    private array $audit = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.cgc_governor_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'cga.demo_session_capture' => false]);
        DB::setDefaultConnection('cgc_governor_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        foreach ([Appointment::class, Board::class, BoardSeat::class, ChamberVote::class, ChamberVoteTally::class,
            Clock::class, ClockTimer::class, Department::class, Executive::class, ExecutiveMember::class, GovernorRemovalRequest::class, InstanceSettings::class,
            Legislature::class, LegislatureMember::class, Organization::class, PublicRecord::class, Term::class, User::class, VoteCast::class] as $class) {
            $model = new $class;
            DB::connection()->getSchemaBuilder()->create($model->getTable(), function (Blueprint $t) use ($model) {
                if ($model instanceof PublicRecord) {
                    $t->bigIncrements('seq');
                }
                foreach (array_unique([...$model->getFillable(), 'id', 'created_at', 'updated_at', 'deleted_at']) as $column) {
                    if ($column === 'id') {
                        $t->string('id')->unique();
                    } elseif ($column === 'is_tiebreak') {
                        $t->boolean($column)->default(false);
                    } elseif ($model instanceof ChamberVoteTally && in_array($column, ['yes', 'no', 'abstain', 'present'], true)) {
                        $t->integer($column)->default(0);
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
        });
        InstanceSettings::create(['instance_name' => 'Private workflow', 'instance_class' => 'production']);
        Clock::create(['id' => 'CLK-09', 'name' => 'Civil expiry', 'type' => 'countdown']);
        Legislature::create(['id' => $this->id(3), 'jurisdiction_id' => $this->id(2), 'status' => 'active', 'total_seats' => 3, 'type_a_seats' => 3, 'type_b_seats' => 0]);
        // Insert another chamber first in this jurisdiction to prove the creator ID wins.
        Legislature::create(['id' => $this->id(4), 'jurisdiction_id' => $this->id(2), 'status' => 'active', 'total_seats' => 3, 'type_a_seats' => 3, 'type_b_seats' => 0]);
        Executive::create(['id' => $this->id(5), 'jurisdiction_id' => $this->id(2), 'source_legislature_id' => $this->id(4), 'status' => 'delegated', 'type' => 'committee']);
        Organization::create(['id' => $this->id(1), 'name' => 'Public transport', 'jurisdiction_id' => $this->id(2),
            'is_cgc' => true, 'type' => 'common_good_corp', 'status' => 'active', 'is_active' => true, 'ip_is_public_domain' => true,
            'board_id' => $this->id(6), 'created_by_legislature_id' => $this->id(4), 'overseen_by_executive_id' => $this->id(5)]);
        Board::create(['id' => $this->id(6), 'boardable_type' => 'organizations', 'boardable_id' => $this->id(1),
            'status' => 'forming', 'owner_seats' => 1, 'worker_seats' => 1, 'composition_valid' => true]);
        BoardSeat::create(['id' => $this->id(7), 'board_id' => $this->id(6), 'seat_class' => 'governor', 'seat_no' => 1, 'status' => 'vacant']);
        BoardSeat::create(['id' => $this->id(8), 'board_id' => $this->id(6), 'seat_class' => 'worker_elected', 'seat_no' => 2, 'status' => 'seated', 'holder_user_id' => $this->id(108), 'is_chair' => true]);
        Board::whereKey($this->id(6))->update(['chair_seat_id' => $this->id(8)]);
        foreach ([100, 101, 102, 103, 104, 108] as $n) {
            (new User)->forceFill(['id' => $this->id($n), 'name' => 'Internal '.$n, 'display_name' => 'Public '.$n])->save();
        }
        ExecutiveMember::create(['id' => $this->id(9), 'executive_id' => $this->id(5), 'user_id' => $this->id(100), 'status' => 'seated', 'role' => 'principal']);
        foreach ([101, 102, 103] as $n) {
            LegislatureMember::create(['id' => $this->id(200 + $n), 'legislature_id' => $this->id(4), 'user_id' => $this->id($n), 'status' => 'seated', 'seat_type' => 'a']);
        }
        DB::table('residency_confirmations')->insert(['user_id' => $this->id(104), 'jurisdiction_id' => $this->id(2), 'is_active' => true]);
        $audit = $this->createMock(AuditService::class);
        $audit->method('append')->willReturnCallback(function (...$args) {
            $this->audit[] = $args;

            return (new AuditEntry)->forceFill(['seq' => count($this->audit)]);
        });
        $settings = $this->createMock(SettingsResolver::class);
        $settings->method('resolveInt')->willReturnCallback(fn ($jurisdiction, $key, $fallback) => $key === 'civil_appointment_years' ? 7 : $fallback);
        $roles = $this->createMock(RoleService::class);
        $roleGate = $this->createMock(ResolvesRoles::class);
        $roleGate->method('rolesFor')->willReturn(['R-01', 'R-09', 'R-10', 'R-14']);
        $records = new PublicRecordService($audit);
        $votes = new ChamberVoteService($audit, $settings, $records, $this->createMock(CommitteeRoster::class), new VoteCountingService);
        $this->clocks = new ClockService($audit, $settings);
        $this->governors = new BoardGovernorService($votes, new CivilAppointmentService($this->clocks), $records, $audit, $settings, $this->clocks, $roles);
        $this->acts = new ChamberActService($votes, $this->createMock(EnactmentService::class), $records, $this->createMock(CommitteeService::class),
            $this->createMock(ElectionBoardTransitionService::class), $settings, $this->clocks, $roles);
        foreach ([ChamberVoteService::class => $votes, BoardGovernorService::class => $this->governors, ChamberActService::class => $this->acts,
            OrgBoardService::class => new OrgBoardService($audit, $records, $roles), AuditService::class => $audit,
            PublicRecordService::class => $records, RoleService::class => $roles, SettingsResolver::class => $settings, ClockService::class => $this->clocks] as $class => $instance) {
            $this->app->instance($class, $instance);
        }
        $this->engine = new ConstitutionalEngine($audit, new ConstitutionalValidator, $roleGate, $this->createMock(TrainingGateService::class));
        $this->travelTo(now()->setDate(2026, 9, 13)->setTime(12, 0));
        Bus::fake();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        DB::purge('cgc_governor_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    public function test_real_nomination_votes_term_clock_expiry_and_replacement_form_a_repeat_safe_loop(): void
    {
        $nomination = $this->nominate();
        $vote = ChamberVote::findOrFail($nomination['consent_vote_id']);
        self::assertSame($this->id(4), $vote->body_id);
        self::assertSame('bog_consent', $vote->vote_type);
        self::assertSame(3, $vote->serving_snapshot);
        self::assertSame(2, $vote->tallies->sole()->required_yes);
        self::assertSame(0, Term::count());
        self::assertSame('Public dossier', PublicRecord::where('via_form', 'F-EXE-001')->sole()->body);
        $this->vote($vote, ['yes', 'yes', 'no']);
        self::assertSame('adopted', $vote->refresh()->outcome);
        $appointment = Appointment::findOrFail($nomination['appointment_id']);
        $term = Term::findOrFail($appointment->term_id);
        self::assertSame('seated', $appointment->status);
        self::assertSame('board_governor', $term->office_kind);
        self::assertSame('civil_appointment', $term->term_class);
        self::assertSame('2033-09-13', $term->ends_on->toDateString());
        self::assertSame($this->id(4), $term->legislature_id);
        self::assertSame($nomination['seat_id'], $term->office_id);
        $timer = ClockTimer::where('subject_id', $term->id)->sole();
        self::assertSame('CLK-09', $timer->clock_id);
        self::assertSame('armed', $timer->state);
        self::assertSame('2033-09-13 00:00:00', $timer->fires_at->format('Y-m-d H:i:s'));
        $chair = ChamberVote::where('vote_type', 'board_chair_elect')->sole();
        self::assertSame(2, $chair->serving_snapshot);
        self::assertNull(Board::findOrFail($this->id(6))->chair_seat_id);
        $this->acts->resolveConsentVote($vote, 'adopted');
        self::assertSame(1, Term::count());
        self::assertSame(1, ClockTimer::count());
        $this->governors->expireGovernorTerm($term);
        self::assertSame('active', $term->refresh()->status, 'A direct early expiry cannot shorten the configured term.');
        $this->travelTo($term->ends_on->addHour());
        self::assertTrue($this->clocks->fire($timer));
        Bus::assertDispatched(CivilTermExpiryJob::class);
        $this->expire($timer);
        self::assertSame('completed', $term->refresh()->status);
        self::assertSame('2033-09-13', $term->ends_on->toDateString());
        self::assertSame('ended', $appointment->refresh()->status);
        self::assertSame('term_ended', BoardSeat::findOrFail($nomination['seat_id'])->status);
        self::assertSame('void', $chair->refresh()->status);
        self::assertSame(1, BoardSeat::where('board_id', $this->id(6))->where('status', 'vacant')->count());
        $recordCount = PublicRecord::count();
        $this->expire($timer);
        $this->governors->expireGovernorTerm($term);
        self::assertSame($recordCount, PublicRecord::count());
        self::assertSame(3, BoardSeat::count());
        $replacement = $this->nominate();
        self::assertNotSame($nomination['seat_id'], $replacement['seat_id']);
        $this->vote(ChamberVote::findOrFail($replacement['consent_vote_id']), ['yes', 'yes', 'yes']);
        self::assertSame(2, Term::count());
        self::assertSame(2, ClockTimer::count());
        self::assertSame('2040-09-13', Appointment::findOrFail($replacement['appointment_id'])->term->ends_on->toDateString());
        self::assertSame(0, PublicRecord::whereNull('audit_seq')->count());
    }

    public function test_rejected_nomination_reopens_only_its_own_seat_and_next_nomination_can_succeed(): void
    {
        $first = $this->nominate();
        $vote = ChamberVote::findOrFail($first['consent_vote_id']);
        $this->vote($vote, ['no', 'no', 'yes']);
        self::assertSame('rejected', Appointment::findOrFail($first['appointment_id'])->status);
        self::assertSame('vacant', BoardSeat::findOrFail($first['seat_id'])->status);
        self::assertSame(0, Term::count());
        $next = $this->nominate();
        self::assertSame($first['seat_id'], $next['seat_id']);
        $this->governors->handleRejectedNomination(Appointment::findOrFail($first['appointment_id']));
        self::assertSame($next['appointment_id'], BoardSeat::findOrFail($next['seat_id'])->appointment_id);
        $this->vote(ChamberVote::findOrFail($next['consent_vote_id']), ['yes', 'yes', 'yes']);
        self::assertSame(1, Term::count());
    }

    public static function invalidOwners(): array
    {
        return [
            'private corporation' => ['organizations', 1, ['is_cgc' => false, 'type' => 'business']],
            'inactive corporation' => ['organizations', 1, ['is_active' => false]],
            'dissolved corporation' => ['organizations', 1, ['status' => 'dissolved']],
            'deleted corporation' => ['organizations', 1, ['deleted_at' => '2026-09-13']],
            'board pointer changed' => ['organizations', 1, ['board_id' => null]],
            'wrong board owner' => ['boards', 6, ['boardable_id' => 'bad-owner']],
            'wrong board type' => ['boards', 6, ['boardable_type' => 'departments']],
            'dissolved board' => ['boards', 6, ['status' => 'dissolved']],
            'wrong overseer' => ['organizations', 1, ['overseen_by_executive_id' => null]],
            'foreign overseer jurisdiction' => ['executives', 5, ['jurisdiction_id' => 'foreign']],
            'dissolved overseer' => ['executives', 5, ['status' => 'dissolved']],
            'non-serving nominator' => ['executive_members', 9, ['status' => 'term_ended']],
            'advisor cannot nominate' => ['executive_members', 9, ['role' => 'advisor']],
            'creator missing' => ['organizations', 1, ['created_by_legislature_id' => null]],
            'foreign creator jurisdiction' => ['legislatures', 4, ['jurisdiction_id' => 'foreign']],
            'dissolved creator' => ['legislatures', 4, ['status' => 'dissolved']],
            'non-governor seat' => ['board_seats', 7, ['seat_class' => 'owner_elected']],
            'already seated' => ['board_seats', 7, ['status' => 'seated', 'holder_user_id' => 'holder']],
            'deleted nominee' => ['users', 104, ['deleted_at' => '2026-09-13']],
        ];
    }

    #[DataProvider('invalidOwners')]
    public function test_invalid_scope_cannot_publish_or_nominate(string $table, int $id, array $changes): void
    {
        DB::table($table)->where('id', $this->id($id))->update($changes);
        $this->refused(fn () => $this->nominate());
        self::assertSame(0, Appointment::count());
        self::assertSame(0, ChamberVote::count());
        self::assertSame(0, PublicRecord::count());
    }

    public function test_handler_rejects_ambiguous_or_forged_context_and_nominee_without_association(): void
    {
        foreach ([['department_id' => $this->id(50)], ['jurisdiction_id' => $this->id(99)], ['nominee_user_id' => $this->id(103)],
            ['organization_id' => 'malformed'], ['dossier' => ['bad']], ['dossier' => str_repeat('x', 20001)]] as $changes) {
            $this->refused(fn () => $this->nominate($changes));
        }
        self::assertSame(0, Appointment::count());
        self::assertSame(0, PublicRecord::count());
    }

    public static function staleConsent(): array
    {
        return [
            'wrong recorded vote' => ['appointments', 'appointment', ['consent_vote_id' => 'wrong']],
            'appointment already has a term' => ['appointments', 'appointment', ['term_id' => 'old-term']],
            'wrong nomination form' => ['appointments', 'appointment', ['nominated_via_form' => 'F-LEG-021']],
            'replaced seat nomination' => ['board_seats', 'seat', ['appointment_id' => 'new-appointment']],
            'wrong chamber' => ['chamber_votes', 'vote', ['body_id' => 'foreign']],
            'wrong legislature metadata' => ['chamber_votes', 'vote', ['legislature_id' => 'foreign']],
            'wrong vote type' => ['chamber_votes', 'vote', ['vote_type' => 'procedural_motion']],
            'wrong vote stage' => ['chamber_votes', 'vote', ['stage' => 'committee']],
            'wrong vote jurisdiction' => ['chamber_votes', 'vote', ['jurisdiction_id' => 'foreign']],
            'owner no longer current' => ['organizations', 'owner', ['board_id' => null]],
            'creator changed after nomination' => ['organizations', 'owner', ['created_by_legislature_id' => '50000000-0000-4000-8000-000000000003']],
        ];
    }

    #[DataProvider('staleConsent')]
    public function test_stale_consent_cannot_seat_or_clear_a_replacement(string $table, string $target, array $changes): void
    {
        $nomination = $this->nominate();
        $ids = ['appointment' => $nomination['appointment_id'], 'seat' => $nomination['seat_id'], 'vote' => $nomination['consent_vote_id'], 'owner' => $this->id(1)];
        DB::table($table)->where('id', $ids[$target])->update($changes);
        $vote = ChamberVote::findOrFail($nomination['consent_vote_id']);
        foreach (['adopted', 'failed'] as $outcome) {
            $vote->forceFill(['status' => 'closed', 'outcome' => $outcome])->save();
            $this->refused(fn () => DB::transaction(fn () => $this->acts->resolveConsentVote($vote, $outcome)));
        }
        self::assertSame(0, Term::count());
        self::assertSame(0, ClockTimer::count());
        self::assertSame('nominated', Appointment::findOrFail($nomination['appointment_id'])->status);
        self::assertSame(1, PublicRecord::count());
    }

    public function test_department_nomination_and_consent_still_use_the_existing_pipeline(): void
    {
        Department::create(['id' => $this->id(50), 'name' => 'Transport department', 'jurisdiction_id' => $this->id(2),
            'executive_id' => $this->id(5), 'board_id' => $this->id(51), 'status' => 'oversight_assigned']);
        Board::create(['id' => $this->id(51), 'boardable_type' => 'departments', 'boardable_id' => $this->id(50), 'status' => 'forming', 'composition_valid' => true, 'owner_seats' => 1]);
        BoardSeat::create(['id' => $this->id(52), 'board_id' => $this->id(51), 'seat_class' => 'governor', 'seat_no' => 1, 'status' => 'vacant']);
        // The established department path finds its jurisdiction's chamber (id 3).
        LegislatureMember::query()->update(['legislature_id' => $this->id(3)]);
        $result = $this->engine->file('F-EXE-001', User::findOrFail($this->id(100)), ['department_id' => $this->id(50), 'jurisdiction_id' => $this->id(2), 'nominee_user_id' => $this->id(104)])->recorded;
        $this->vote(ChamberVote::findOrFail($result['consent_vote_id']), ['yes', 'yes', 'yes']);
        self::assertSame('operating', Department::findOrFail($this->id(50))->status);
        $term = Term::sole();
        self::assertSame($this->id(52), $term->office_id);
        self::assertSame('2033-09-13', $term->ends_on->toDateString());
        $removal = DB::transaction(fn () => $this->governors->requestRemoval(BoardSeat::findOrFail($this->id(52)), ExecutiveMember::findOrFail($this->id(9)), 'Public competence grounds'));
        $this->travelTo($term->ends_on->addHour());
        $timer = ClockTimer::sole();
        $this->clocks->fire($timer);
        $this->expire($timer);
        $this->vote(ChamberVote::findOrFail($removal['vote_id']), ['no', 'no', 'yes']);
        self::assertSame('term_ended', BoardSeat::findOrFail($this->id(52))->status);
        self::assertSame(1, BoardSeat::where('board_id', $this->id(51))->where('status', 'vacant')->count());
    }

    public function test_invalid_current_owner_rolls_back_the_final_consent_cast_then_a_corrected_retry_seats_once(): void
    {
        $nomination = $this->nominate();
        $vote = ChamberVote::findOrFail($nomination['consent_vote_id']);
        $this->vote($vote, ['yes', 'yes']);
        Organization::whereKey($this->id(1))->update(['board_id' => null]);
        $cast = fn () => $this->engine->file('F-LEG-004', User::findOrFail($this->id(103)), ['vote_id' => $vote->id, 'jurisdiction_id' => $this->id(2), 'value' => 'yes']);
        $this->refused($cast);
        self::assertSame('open', $vote->refresh()->status);
        self::assertSame(2, VoteCast::count());
        self::assertSame(3, PublicRecord::count());
        self::assertSame(0, Term::count());
        self::assertSame(0, ClockTimer::count());
        Organization::whereKey($this->id(1))->update(['board_id' => $this->id(6)]);
        $cast();
        self::assertSame('adopted', $vote->refresh()->outcome);
        self::assertSame(1, Term::count());
    }

    public function test_failed_expiry_side_effect_rolls_back_before_a_successful_retry(): void
    {
        $nomination = $this->nominate();
        $this->vote(ChamberVote::findOrFail($nomination['consent_vote_id']), ['yes', 'yes', 'yes']);
        $term = Term::sole();
        $timer = ClockTimer::sole();
        $this->travelTo($term->ends_on->addHour());
        $this->clocks->fire($timer);
        $boards = app(OrgBoardService::class);
        $failing = $this->createMock(OrgBoardService::class);
        $failing->method('onCompositionChange')->willThrowException(new \RuntimeException('Temporary fixture failure'));
        $this->app->instance(OrgBoardService::class, $failing);
        $count = PublicRecord::count();
        try {
            $this->expire($timer);
            self::fail('Expected the isolated side-effect failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('Temporary fixture failure', $error->getMessage());
        }
        self::assertSame('active', $term->refresh()->status);
        self::assertSame('seated', Appointment::findOrFail($nomination['appointment_id'])->status);
        self::assertSame('seated', BoardSeat::findOrFail($nomination['seat_id'])->status);
        self::assertSame(2, BoardSeat::count());
        self::assertSame($count, PublicRecord::count());
        $this->app->instance(OrgBoardService::class, $boards);
        $this->expire($timer);
        self::assertSame('completed', $term->refresh()->status);
        self::assertSame(3, BoardSeat::count());
    }

    public function test_legacy_sim_cgc_term_expires_but_private_worker_or_wrong_term_targets_do_not(): void
    {
        $nomination = $this->nominate();
        $this->vote(ChamberVote::findOrFail($nomination['consent_vote_id']), ['yes', 'yes', 'yes']);
        $term = Term::sole();
        $timer = ClockTimer::sole();
        $term->forceFill(['office_kind' => 'board_seat', 'source_appointment_id' => null])->save();
        BoardSeat::whereKey($nomination['seat_id'])->update(['appointment_id' => null]);
        $this->travelTo($term->ends_on->addHour());
        $this->clocks->fire($timer);
        foreach ([['seat_class' => 'worker_elected'], ['term_id' => 'replacement-term'], ['holder_user_id' => $this->id(103)]] as $changes) {
            $seat = BoardSeat::findOrFail($nomination['seat_id']);
            $original = $seat->only(array_keys($changes));
            $seat->forceFill($changes)->save();
            $this->expire($timer);
            self::assertSame('active', $term->refresh()->status);
            self::assertSame(2, BoardSeat::count());
            $seat->forceFill($original)->save();
        }
        Organization::whereKey($this->id(1))->update(['is_cgc' => false, 'type' => 'business']);
        $this->expire($timer);
        self::assertSame('active', $term->refresh()->status);
        Organization::whereKey($this->id(1))->update(['is_cgc' => true, 'type' => 'common_good_corp']);
        $this->expire($timer);
        self::assertSame('completed', $term->refresh()->status);
        self::assertSame(1, BoardSeat::where('status', 'vacant')->count());
    }

    public function test_wrong_cancelled_or_early_timers_do_not_dispatch_and_judicial_terms_keep_their_own_handler(): void
    {
        $nomination = $this->nominate();
        $this->vote(ChamberVote::findOrFail($nomination['consent_vote_id']), ['yes', 'yes', 'yes']);
        $term = Term::sole();
        $timer = ClockTimer::sole();
        $this->expire($timer);
        self::assertSame('active', $term->refresh()->status);
        $timer->forceFill(['state' => 'fired'])->save();
        $this->expire($timer);
        self::assertSame('active', $term->refresh()->status, 'Even a fired timer cannot expire a future term.');
        $this->travelTo($term->ends_on->addHour());
        foreach ([['state' => 'cancelled'], ['state' => 'fired', 'clock_id' => 'CLK-10'], ['state' => 'fired', 'jurisdiction_id' => $this->id(99)]] as $changes) {
            $timer->forceFill(['clock_id' => 'CLK-09', 'jurisdiction_id' => $this->id(2), ...$changes])->save();
            $this->expire($timer);
            self::assertSame('active', $term->refresh()->status);
        }
        $timer->forceFill(['clock_id' => 'CLK-09', 'jurisdiction_id' => $this->id(2), 'state' => 'fired'])->save();
        $term->forceFill(['office_kind' => 'judicial_seat'])->save();
        $judges = $this->createMock(JudicialSeatService::class);
        $judges->expects(self::once())->method('expireJudicialTerm')->with(self::callback(fn ($given) => $given->id === $term->id));
        (new CivilTermExpiryJob($timer->id))->handle($this->governors, $judges);
        self::assertSame('active', $term->refresh()->status);
    }

    public function test_existing_judicial_consent_dispatch_is_not_subject_to_governor_context(): void
    {
        $appointment = Appointment::create(['appointable_type' => 'judicial_seats', 'appointable_id' => $this->id(71),
            'nominee_user_id' => $this->id(104), 'nominated_via_form' => 'F-LEG-021', 'status' => 'nominated']);
        $vote = DB::transaction(fn () => app(ChamberVoteService::class)->open('legislature', $this->id(4), 'bog_consent', $appointment, 'floor'));
        $appointment->forceFill(['consent_vote_id' => $vote->id])->save();
        $judges = $this->createMock(JudicialSeatService::class);
        $judges->expects(self::once())->method('seat')->with(self::callback(fn ($given) => $given->id === $appointment->id))->willReturn([]);
        $this->app->instance(JudicialSeatService::class, $judges);
        $this->vote($vote, ['yes', 'yes', 'yes']);
        self::assertSame('adopted', $vote->refresh()->outcome);
        self::assertSame(3, VoteCast::count());
        self::assertSame(0, Term::count(), 'The judicial service double receives seating; no governor term is opened.');
    }

    public function test_real_nomination_is_browsable_and_castable_only_by_an_eligible_creator_member(): void
    {
        $this->createReaderTables();
        $nomination = $this->nominate();
        $read = fn (int $user) => $this->readAppointment($user);
        $row = $read(101);
        self::assertSame($nomination['appointment_id'], $row['id']);
        self::assertSame($nomination['consent_vote_id'], $row['consent']['tally']['vote_id']);
        self::assertSame('/votes/'.$nomination['consent_vote_id'].'/cast', $row['consent']['cast_url']);
        self::assertTrue($row['consent']['can_cast']);
        self::assertSame('Public dossier', $row['dossier']->body);
        self::assertSame('Public 104', $row['nominee']['name']);
        $this->vote(ChamberVote::findOrFail($nomination['consent_vote_id']), ['yes']);
        self::assertFalse($read(101)['consent']['can_cast']);
        self::assertSame('yes', $read(101)['consent']['my_cast']);
        Legislature::whereKey($this->id(4))->update(['speaker_id' => $this->id(302)]);
        self::assertFalse($read(102)['consent']['can_cast']);
        LegislatureMember::whereKey($this->id(303))->update(['legislature_id' => $this->id(3)]);
        self::assertFalse($read(103)['consent']['can_cast']);
    }

    public static function speakerChoices(): array
    {
        return ['approve' => ['yes', 'adopted', 'seated'], 'reject' => ['no', 'failed', 'rejected']];
    }

    #[DataProvider('speakerChoices')]
    public function test_actual_tied_consent_waits_for_its_speaker_then_resolves_once(string $choice, string $outcome, string $status): void
    {
        $this->createReaderTables();
        Legislature::whereKey($this->id(4))->update(['speaker_id' => $this->id(303)]);
        $nomination = $this->nominate();
        $vote = ChamberVote::findOrFail($nomination['consent_vote_id']);
        $this->vote($vote, ['yes', 'no']);
        self::assertSame('closed', $vote->refresh()->status);
        self::assertSame('tied', $vote->outcome);
        self::assertSame('nominated', Appointment::findOrFail($nomination['appointment_id'])->status);
        self::assertSame(0, Term::count());
        self::assertTrue($this->readAppointment(103)['consent']['can_tiebreak']);
        self::assertSame('/votes/'.$vote->id.'/tiebreak', $this->readAppointment(103)['consent']['tiebreak_url']);
        self::assertFalse($this->readAppointment(103)['consent']['can_cast']);
        self::assertFalse($this->readAppointment(101)['consent']['can_tiebreak']);
        $breakTie = fn (int $actor) => $this->engine->file('F-SPK-004', User::findOrFail($this->id($actor)), [
            'vote_id' => $vote->id, 'jurisdiction_id' => $this->id(2), 'value' => $choice, 'explanation' => 'Public deciding reasons',
        ]);
        $this->refused(fn () => $breakTie(101));
        self::assertSame(2, VoteCast::where('vote_id', $vote->id)->count());
        $breakTie(103);
        self::assertSame($outcome, $vote->refresh()->outcome);
        self::assertTrue($vote->speaker_tiebreak);
        self::assertSame(2, $vote->tallies()->sole()->required_yes, 'The Speaker does not change the threshold.');
        $appointment = Appointment::findOrFail($nomination['appointment_id']);
        self::assertSame($status, $appointment->status);
        self::assertSame($choice === 'yes' ? 'seated' : 'vacant', BoardSeat::findOrFail($nomination['seat_id'])->status);
        self::assertSame($choice === 'yes' ? 1 : 0, Term::count());
        if ($choice === 'yes') {
            self::assertSame('2033-09-13', $appointment->term->ends_on->toDateString());
        }
        self::assertSame(1, VoteCast::where('vote_id', $vote->id)->where('is_tiebreak', true)->count());
        self::assertSame(1, PublicRecord::where('via_form', 'F-SPK-004')->count());
        self::assertFalse($this->readAppointment(103)['consent']['can_tiebreak']);
        $this->refused(fn () => $breakTie(103));
        self::assertSame(3, VoteCast::where('vote_id', $vote->id)->count());
        self::assertSame(1, PublicRecord::where('via_form', 'F-SPK-004')->count());
    }

    private function createReaderTables(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('jurisdictions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('name');
            $t->softDeletes();
        });
        $schema->create('social_profiles', function (Blueprint $t) {
            $t->string('user_id');
            $t->string('display_name')->nullable();
            $t->string('handle')->nullable();
            $t->string('visibility');
            $t->softDeletes();
        });
        DB::table('jurisdictions')->insert(['id' => $this->id(2), 'name' => 'Fixture jurisdiction']);
    }

    private function readAppointment(int $user): array
    {
        $org = Organization::findOrFail($this->id(1));
        $board = Board::with('seats')->findOrFail($org->board_id);

        return (new \App\Support\CgcGovernorWorkspace($org, $board, User::findOrFail($this->id($user)), new \App\Http\Presenters\ChamberVotePresenter))
            ->appointments(\Illuminate\Http\Request::create('/organizations/'.$org->id.'/board-elections'))['rows'][0];
    }

    public function test_principal_authority_elsewhere_does_not_authorize_target_advisor_nominations(): void
    {
        Executive::create(['id' => $this->id(80), 'jurisdiction_id' => $this->id(81), 'status' => 'delegated', 'type' => 'committee']);
        ExecutiveMember::create(['id' => $this->id(82), 'executive_id' => $this->id(80), 'user_id' => $this->id(100), 'status' => 'seated', 'role' => 'principal', 'selection' => 'delegated_proportional']);
        ExecutiveMember::whereKey($this->id(9))->update(['role' => 'advisor']);
        // The real engine role gate receives R-14 from the existing fixture double;
        // target-specific authority must still be refused by the service.
        $this->refused(fn () => $this->nominate());
        Department::create(['id' => $this->id(50), 'name' => 'Other target', 'jurisdiction_id' => $this->id(2),
            'executive_id' => $this->id(5), 'board_id' => $this->id(51), 'status' => 'oversight_assigned']);
        Board::create(['id' => $this->id(51), 'boardable_type' => 'departments', 'boardable_id' => $this->id(50), 'status' => 'forming', 'composition_valid' => true, 'owner_seats' => 1]);
        BoardSeat::create(['id' => $this->id(52), 'board_id' => $this->id(51), 'seat_class' => 'governor', 'seat_no' => 1, 'status' => 'vacant']);
        $this->refused(fn () => $this->engine->file('F-EXE-001', User::findOrFail($this->id(100)), ['department_id' => $this->id(50),
            'jurisdiction_id' => $this->id(2), 'nominee_user_id' => $this->id(104)]));
        BoardSeat::whereKey($this->id(52))->update(['status' => 'seated', 'holder_user_id' => $this->id(104)]);
        $this->refused(fn () => DB::transaction(fn () => $this->governors->requestRemoval(
            BoardSeat::findOrFail($this->id(52)), ExecutiveMember::findOrFail($this->id(9)), 'Public grounds'
        )));
        self::assertSame(0, Appointment::count());
        self::assertSame(0, PublicRecord::count());
        self::assertSame(0, ChamberVote::count());
    }

    private function nominate(array $changes = []): array
    {
        return $this->engine->file('F-EXE-001', User::findOrFail($this->id(100)), [
            'organization_id' => $this->id(1), 'jurisdiction_id' => $this->id(2), 'nominee_user_id' => $this->id(104), 'dossier' => 'Public dossier', ...$changes])->recorded;
    }

    private function vote(ChamberVote $vote, array $values): void
    {
        foreach ($values as $i => $value) {
            $this->engine->file('F-LEG-004', User::findOrFail($this->id(101 + $i)), ['vote_id' => $vote->id, 'jurisdiction_id' => $this->id(2), 'value' => $value]);
        }
    }

    private function expire(ClockTimer $timer): void
    {
        $judges = $this->createMock(JudicialSeatService::class);
        $judges->expects(self::never())->method('expireJudicialTerm');
        (new CivilTermExpiryJob($timer->id))->handle($this->governors, $judges);
    }

    private function refused(callable $action): void
    {
        try {
            $action();
            self::fail('Invalid governor operation was accepted.');
        } catch (ConstitutionalViolation $error) {
            self::assertNotSame('', $error->getMessage());
        }
    }

    private function id(int $n): string
    {
        return sprintf('50000000-0000-4000-8000-%012d', $n);
    }
}
