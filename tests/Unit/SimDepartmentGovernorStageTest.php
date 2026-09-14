<?php

namespace Tests\Unit;

use App\Domain\Forms\Contracts\CommitteeRoster;
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
use App\Services\Demo\Stages\GovernanceStage;
use App\Services\Executive\BoardGovernorService;
use App\Services\Legislature\ChamberActService;
use App\Services\Legislature\CommitteeService;
use App\Services\Legislature\ElectionBoardTransitionService;
use App\Services\Organizations\OrgBoardService;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use App\Services\VoteCountingService;
use App\Services\EnactmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PIN — the sim governance stage seats a DEPARTMENT board of governors through
 * the REAL consent pipeline (W-0196).
 *
 * GovernanceStage::seatDepartmentGovernors files F-EXE-001 through
 * BoardGovernorService::nominate (the same service the F-EXE-001 handler
 * calls), the F-LEG-020 consent vote opens, the seated chamber carries it, and
 * the adoption dispatch seats the governor with a 10-year civil term and arms
 * CLK-09. It writes no row directly — every seat comes through the consent
 * adoption path, so a demo world's department boards are governed, not empty.
 *
 * DB-free posture (the CgcGovernorWorkflowTest fixture): a named sqlite
 * connection, real ChamberVoteService / BoardGovernorService / ChamberActService
 * / CivilAppointmentService / ClockService, and audit / settings / roles
 * doubles. No live PostgreSQL. The CGC governor path is untouched and its own
 * pins stay green.
 */
final class SimDepartmentGovernorStageTest extends TestCase
{
    use \Tests\Concerns\AchievementSchema;

    private string $original;

    private ChamberVoteService $votes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config([
            'database.connections.sim_dept_governor_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cga.demo_session_capture' => false,
        ]);
        DB::setDefaultConnection('sim_dept_governor_fixture');
        $this->createAchievementTables();
        self::assertSame('sqlite', DB::connection()->getDriverName());

        foreach ([Appointment::class, Board::class, BoardSeat::class, ChamberVote::class, ChamberVoteTally::class,
            Clock::class, ClockTimer::class, Department::class, Executive::class, ExecutiveMember::class, InstanceSettings::class,
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

        InstanceSettings::create(['instance_name' => 'Sim governance', 'instance_class' => 'production']);
        Clock::create(['id' => 'CLK-09', 'name' => 'Civil expiry', 'type' => 'countdown']);

        // ONE legislature in the jurisdiction (legislatureOf resolves the
        // consent chamber by jurisdiction), five seated members.
        Legislature::create(['id' => $this->id(3), 'jurisdiction_id' => $this->id(2), 'status' => 'active',
            'total_seats' => 5, 'type_a_seats' => 5, 'type_b_seats' => 0, 'quorum_required' => 3]);
        foreach ([101, 102, 103, 104, 105] as $i => $n) {
            LegislatureMember::create(['id' => $this->id(200 + $n), 'legislature_id' => $this->id(3),
                'user_id' => $this->id($n), 'status' => 'seated', 'seat_type' => 'a']);
        }

        // A delegated executive with a seated principal — the nominator.
        Executive::create(['id' => $this->id(5), 'jurisdiction_id' => $this->id(2), 'source_legislature_id' => $this->id(3),
            'status' => 'delegated', 'type' => 'committee']);
        ExecutiveMember::create(['id' => $this->id(9), 'executive_id' => $this->id(5), 'user_id' => $this->id(100),
            'status' => 'seated', 'role' => 'principal']);

        // A chartered department with a board carrying one vacant governor seat.
        Department::create(['id' => $this->id(50), 'name' => 'Treasury', 'jurisdiction_id' => $this->id(2),
            'executive_id' => $this->id(5), 'board_id' => $this->id(51), 'status' => 'oversight_assigned']);
        Board::create(['id' => $this->id(51), 'boardable_type' => 'departments', 'boardable_id' => $this->id(50),
            'status' => 'forming', 'composition_valid' => true, 'owner_seats' => 1]);
        BoardSeat::create(['id' => $this->id(52), 'board_id' => $this->id(51), 'seat_class' => 'governor', 'seat_no' => 1, 'status' => 'vacant']);

        // Users: the principal (100), the five members (101-105), and an active
        // resident nominee (110).
        foreach ([100, 101, 102, 103, 104, 105, 110] as $n) {
            (new User)->forceFill(['id' => $this->id($n), 'name' => 'Internal '.$n, 'display_name' => 'Public '.$n])->save();
        }
        DB::table('residency_confirmations')->insert(['user_id' => $this->id(110), 'jurisdiction_id' => $this->id(2), 'is_active' => true]);

        $audit = $this->createMock(AuditService::class);
        $audit->method('append')->willReturnCallback(fn (...$a) => (new AuditEntry)->forceFill(['seq' => 1]));
        $settings = $this->createMock(SettingsResolver::class);
        $settings->method('resolveInt')->willReturnCallback(fn ($j, $k, $f) => $k === 'civil_appointment_years' ? 10 : $f);
        $roles = $this->createMock(RoleService::class);
        $records = new PublicRecordService($audit);
        $this->votes = new ChamberVoteService($audit, $settings, $records, $this->createMock(CommitteeRoster::class), new VoteCountingService);
        $clocks = new ClockService($audit, $settings);
        $governors = new BoardGovernorService($this->votes, new CivilAppointmentService($clocks), $records, $audit, $settings, $clocks, $roles);
        $acts = new ChamberActService($this->votes, $this->createMock(EnactmentService::class), $records, $this->createMock(CommitteeService::class),
            $this->createMock(ElectionBoardTransitionService::class), $settings, $clocks, $roles);
        foreach ([ChamberVoteService::class => $this->votes, BoardGovernorService::class => $governors, ChamberActService::class => $acts,
            OrgBoardService::class => new OrgBoardService($audit, $records, $roles), AuditService::class => $audit,
            PublicRecordService::class => $records, RoleService::class => $roles, SettingsResolver::class => $settings, ClockService::class => $clocks] as $class => $instance) {
            $this->app->instance($class, $instance);
        }
        $this->travelTo(now()->setDate(2026, 9, 14)->setTime(12, 0));
        Bus::fake();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        DB::purge('sim_dept_governor_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    public function test_the_stage_seats_the_department_board_of_governors_and_arms_clk09(): void
    {
        $legislature = Legislature::findOrFail($this->id(3));
        $serving = LegislatureMember::where('legislature_id', $this->id(3))->get();

        $out = GovernanceStage::seatDepartmentGovernors($legislature, $serving);

        self::assertSame(1, $out['departments'], 'one department had a vacant governor seat to fill');
        self::assertSame(1, $out['nominated'], 'one F-EXE-001 nomination was filed');
        self::assertSame(1, $out['seated'], 'the consent adopted and the governor seated');
        self::assertNull($out['skipped']);

        // The board seat is SEATED, held by the nominee — written by the
        // consent adoption path, not by the stage.
        $seat = BoardSeat::findOrFail($this->id(52));
        self::assertSame('seated', $seat->status);
        self::assertSame($this->id(110), (string) $seat->holder_user_id);

        // A 10-year civil-appointment term exists for the governor seat.
        $term = Term::sole();
        self::assertSame('board_governor', $term->office_kind);
        self::assertSame('civil_appointment', $term->term_class);
        self::assertSame($this->id(52), (string) $term->office_id);
        self::assertSame($this->id(3), (string) $term->legislature_id);
        self::assertSame('active', $term->status);
        self::assertSame('2036-09-14', $term->ends_on->toDateString());

        // CLK-09 is armed per seat at the term's expiry.
        $timer = ClockTimer::sole();
        self::assertSame('CLK-09', $timer->clock_id);
        self::assertSame('armed', $timer->state);
        self::assertSame((string) $term->id, (string) $timer->subject_id);

        // The department advanced to operating once its only governor seated.
        self::assertSame('operating', Department::findOrFail($this->id(50))->status);

        // The consent vote adopted (ordinary majority of all serving).
        $vote = ChamberVote::where('vote_type', BoardGovernorService::CONSENT_VOTE_TYPE)->sole();
        self::assertSame('adopted', $vote->outcome);
    }

    public function test_the_stage_is_idempotent(): void
    {
        $legislature = Legislature::findOrFail($this->id(3));
        $serving = LegislatureMember::where('legislature_id', $this->id(3))->get();

        GovernanceStage::seatDepartmentGovernors($legislature, $serving);
        self::assertSame(1, Term::count());

        // A second pass finds no vacant governor seat and mints nothing.
        $again = GovernanceStage::seatDepartmentGovernors($legislature, $serving);
        self::assertSame(0, $again['departments'], 'no vacant seat remains');
        self::assertSame(0, $again['nominated']);
        self::assertSame(0, $again['seated']);
        self::assertSame(1, Term::count(), 'no second term is opened');
        self::assertSame(1, ClockTimer::count(), 'no second clock is armed');
    }

    public function test_it_defers_when_no_seated_executive_principal_can_nominate(): void
    {
        // The executive has not delegated: its only member is an advisor, not a
        // seated principal. The stage defers — it never forces a nomination.
        ExecutiveMember::whereKey($this->id(9))->update(['role' => 'advisor']);

        $legislature = Legislature::findOrFail($this->id(3));
        $serving = LegislatureMember::where('legislature_id', $this->id(3))->get();

        $out = GovernanceStage::seatDepartmentGovernors($legislature, $serving);

        self::assertSame(0, $out['departments'], 'no principal, so the department is deferred');
        self::assertSame(0, $out['seated']);
        self::assertSame('vacant', BoardSeat::findOrFail($this->id(52))->status);
        self::assertSame(0, Term::count());
        self::assertSame(0, Appointment::count());
    }

    private function id(int $n): string
    {
        return sprintf('50000000-0000-4000-8000-%012d', $n);
    }
}
