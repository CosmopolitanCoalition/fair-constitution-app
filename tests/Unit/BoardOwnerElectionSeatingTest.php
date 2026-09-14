<?php

namespace Tests\Unit;

use App\Domain\Counting\BallotSet;
use App\Domain\Counting\CountInput;
use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Models\{AuditEntry, Board, BoardSeat, Candidacy, Election, ElectionRace, InstanceSettings, Organization, PublicRecord, RaceResult, Tabulation, Term, User};
use App\Services\{AchievementService, AuditService, ConstitutionalValidator, PublicRecordService, RoleService, VoteCountingService};
use App\Services\Education\TrainingGateService;
use App\Services\Organizations\{CoDeterminationService, OrgBoardElectionService, OrgBoardSeatingService, OrgBoardService};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * S1 · organizations/economy review — the board-election gap.
 *
 * BoardElectionSurfaceTest mocks the engine (:128), so the owner-seat STV
 * count and the certify-and-seat path were never exercised together. This
 * journey closes that gap on disposable SQLite: the REAL ConstitutionalEngine
 * files F-ORG-003, the PROTECTED VoteCountingService::countStv produces the
 * winners, and the REAL OrgBoardSeatingService seats them into board_seats
 * with org-cycle terms. Co-determination recompute and the chair re-election
 * (their own pins: WorkerRepresentationTest, BoardChairWorkflowTest) are the
 * doubled collaborators; audit transport, roles and settings are doubles, as
 * in BoardChairWorkflowTest. PostgreSQL row locking is not claimed here.
 */
final class BoardOwnerElectionSeatingTest extends TestCase
{
    private string $original;
    private ConstitutionalEngine $engine;
    private VoteCountingService $counter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.board_seating_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cga.demo_session_capture' => false]);
        DB::setDefaultConnection('board_seating_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        // Derive-fillable disposable tables — the world is never touched.
        foreach ([Board::class, BoardSeat::class, Organization::class, User::class, Election::class,
            ElectionRace::class, Candidacy::class, Tabulation::class, RaceResult::class, Term::class, InstanceSettings::class] as $class) {
            $model = new $class;
            DB::connection()->getSchemaBuilder()->create($model->getTable(), function (Blueprint $t) use ($model) {
                foreach (array_unique(array_merge($model->getFillable(), ['id', 'created_at', 'updated_at', 'deleted_at'])) as $column) {
                    if ($column === 'id') $t->string('id')->unique();
                    else $t->text($column)->nullable();
                }
            });
        }

        InstanceSettings::create(['instance_name' => 'Board seating fixture', 'instance_class' => 'production']);

        // Doubled collaborators. The SEATING service itself is real.
        $audit = $this->createMock(AuditService::class);
        $audit->method('append')->willReturn((new AuditEntry)->forceFill(['seq' => 1]));
        $records = $this->createMock(PublicRecordService::class);
        $records->method('publish')->willReturn((new PublicRecord)->forceFill(['id' => $this->id(900)]));
        $roles = $this->createMock(RoleService::class);
        $coDetermination = $this->createMock(CoDeterminationService::class);
        $coDetermination->method('recompute')->willReturn(['reconciliation' => null]);
        $boardsForSeating = $this->createMock(OrgBoardService::class);
        $boardsForSeating->method('onCompositionChange')->willReturn(null);
        // The REAL ESM-03 authority flips voting_closed -> tabulating ->
        // certified -> final; its collaborators are doubles (the transition
        // itself only updates status and appends one audit row).
        $lifecycle = new \App\Services\ElectionLifecycleService(
            $audit,
            $this->createMock(\App\Services\ClockService::class),
            $this->createMock(\App\Services\SettingsResolver::class),
            $this->createMock(\App\Services\ApprovalService::class),
        );
        $this->app->instance(\App\Services\ElectionLifecycleService::class, $lifecycle);

        $seating = new OrgBoardSeatingService($audit, $records, $roles, $boardsForSeating, $coDetermination);
        // The handler autowires these from the container; only the seating
        // service runs on the certify path.
        $this->app->instance(OrgBoardSeatingService::class, $seating);
        $this->app->instance(OrgBoardService::class, $boardsForSeating);
        $this->app->instance(OrgBoardElectionService::class, $this->createMock(OrgBoardElectionService::class));
        $achievements = $this->createMock(AchievementService::class);
        $achievements->method('awardSelf')->willReturn(true);
        $this->app->instance(AchievementService::class, $achievements);

        $roleGate = $this->createMock(ResolvesRoles::class);
        $roleGate->method('rolesFor')->willReturn(['R-23']);
        $this->engine = new ConstitutionalEngine($audit, new ConstitutionalValidator, $roleGate, $this->createMock(TrainingGateService::class));
        $this->counter = new VoteCountingService;
    }

    protected function tearDown(): void
    {
        DB::purge('board_seating_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function id(int $n): string { return sprintf('00000000-0000-4000-8000-%012d', $n); }
    private function user(int $n): User { return (new User)->forceFill(['id' => $this->id($n)]); }

    /** Seed org + board with two vacant owner-elected seats. */
    private function seedBoardAndOrg(): void
    {
        Organization::create(['id' => $this->id(1), 'name' => 'Stock organization', 'type' => 'business', 'structure' => 'stock',
            'status' => 'active', 'is_cgc' => false, 'agent_user_id' => $this->id(10), 'board_id' => $this->id(3), 'jurisdiction_id' => $this->id(2)]);
        Board::create(['id' => $this->id(3), 'boardable_type' => Board::BOARDABLE_ORGANIZATIONS, 'boardable_id' => $this->id(1),
            'status' => Board::STATUS_ACTIVE, 'owner_seats' => 2, 'worker_seats' => 0, 'cycle_months' => 24, 'composition_valid' => true]);
        foreach ([1, 2] as $n) {
            BoardSeat::create(['id' => $this->id(30 + $n), 'board_id' => $this->id(3), 'seat_class' => BoardSeat::CLASS_OWNER_ELECTED,
                'seat_no' => $n, 'status' => BoardSeat::STATUS_VACANT, 'is_chair' => false]);
        }
    }

    /**
     * Seed a voting_closed owner election + one 2-seat race + four finalist
     * candidacies, run the REAL STV count over ranked ballots, and persist the
     * sealed tabulation + per-candidacy results exactly as the counted pipeline
     * records them. Returns [electionId, raceId, orderedWinnerUserIds].
     */
    private function seedCountedOwnerElection(int $electionId, int $raceId, array $candidateUsers): array
    {
        Election::create(['id' => $this->id($electionId), 'jurisdiction_id' => $this->id(2), 'board_id' => $this->id(3),
            'kind' => Election::KIND_ORG_BOARD_OWNER, 'status' => Election::STATUS_VOTING_CLOSED, 'trigger' => 'scheduled', 'voting_method' => 'stv_droop']);
        ElectionRace::create(['id' => $this->id($raceId), 'election_id' => $this->id($electionId), 'jurisdiction_id' => $this->id(2),
            'seat_kind' => ElectionRace::SEAT_KIND_TYPE_A, 'seats' => 2, 'finalist_count' => 6,
            'electorate_type' => ElectionRace::ELECTORATE_OWNERS, 'status' => Election::STATUS_VOTING_CLOSED]);
        $candidacyByUser = [];
        foreach ($candidateUsers as $u) {
            $cid = $this->id(400 + $u);
            $candidacyByUser[$u] = $cid;
            Candidacy::create(['id' => $cid, 'election_id' => $this->id($electionId), 'race_id' => $this->id($raceId),
                'user_id' => $this->id($u), 'status' => Candidacy::STATUS_FINALIST]);
        }
        // Ranked ballots: A and B dominate, C and D trail.
        [$a, $b, $c, $d] = array_map(fn ($u) => $candidacyByUser[$u], $candidateUsers);
        $ballots = BallotSet::fromGrouped([
            [[$a, $b], 5], [[$b, $a], 4], [[$c, $a], 2], [[$d, $b], 1],
        ]);
        $input = new CountInput(array_values($candidacyByUser), 2, $ballots);
        $result = $this->counter->countStv($input);

        $tabulationId = $this->id(700 + $electionId);
        Tabulation::create(['id' => $tabulationId, 'race_id' => $this->id($raceId), 'kind' => Tabulation::KIND_INITIAL,
            'status' => Tabulation::STATUS_COMPLETE, 'record_hash' => $result->recordHash(), 'completed_at' => now()]);
        $winnerUsers = [];
        foreach ($result->elected as $elected) {
            RaceResult::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'tabulation_id' => $tabulationId,
                'candidacy_id' => $elected['candidacy_id'], 'round_elected' => $elected['round'], 'seat_no' => $elected['seat_no']]);
            $winnerUsers[] = array_search($elected['candidacy_id'], $candidacyByUser, true);
        }

        return [$this->id($electionId), $this->id($raceId), $winnerUsers, $result];
    }

    public function test_real_stv_count_seats_the_owner_winners_through_the_real_engine(): void
    {
        $this->seedBoardAndOrg();
        [$electionId, , $winnerUsers, $result] = $this->seedCountedOwnerElection(100, 200, [11, 12, 13, 14]);

        // The PROTECTED count itself elected exactly two, users 11 and 12.
        self::assertSame(2, count($result->elected));
        self::assertEqualsCanonicalizing([11, 12], $winnerUsers);

        // The REAL engine files F-ORG-003 certify as the org agent.
        $out = $this->engine->file('F-ORG-003', $this->user(10),
            ['organization_id' => $this->id(1), 'action' => 'certify', 'election_id' => $electionId]);
        self::assertSame(BoardSeat::CLASS_OWNER_ELECTED, $out->recorded['seat_class']);
        self::assertCount(2, $out->recorded['seated']);

        // Both vacant owner seats hold an STV winner; nobody else was seated.
        $seated = BoardSeat::where('board_id', $this->id(3))->where('status', BoardSeat::STATUS_SEATED)->get();
        self::assertCount(2, $seated);
        $seatedUsers = $seated->pluck('holder_user_id')->map(fn ($id) => (int) substr($id, -3))->sort()->values()->all();
        self::assertSame([11, 12], $seatedUsers);
        foreach ($seated as $seat) self::assertNotNull($seat->term_id);

        // Each seated winner has an active org-cycle term; the count losers are defeated.
        self::assertSame(2, Term::where('term_class', 'org_cycle')->where('status', Term::STATUS_ACTIVE)->count());
        self::assertSame(2, Candidacy::where('status', Candidacy::STATUS_ELECTED)->count());
        self::assertSame(2, Candidacy::where('status', Candidacy::STATUS_DEFEATED)->count());
        self::assertSame(Election::STATUS_FINAL, Election::findOrFail($electionId)->status);
    }

    public function test_only_the_current_agent_can_certify_and_a_foreign_election_is_refused(): void
    {
        $this->seedBoardAndOrg();
        [$electionId] = $this->seedCountedOwnerElection(100, 200, [11, 12, 13, 14]);

        // Wrong actor: a non-agent cannot certify this organization's board.
        $this->violation(fn () => $this->engine->file('F-ORG-003', $this->user(99),
            ['organization_id' => $this->id(1), 'action' => 'certify', 'election_id' => $electionId]));
        // Wrong institution: an election id that is not on this org's board.
        $this->violation(fn () => $this->engine->file('F-ORG-003', $this->user(10),
            ['organization_id' => $this->id(1), 'action' => 'certify', 'election_id' => $this->id(555)]));
        // No seat moved on either refusal.
        self::assertSame(0, BoardSeat::where('status', BoardSeat::STATUS_SEATED)->count());
        self::assertSame(0, Term::count());
    }

    public function test_recertification_and_uncounted_race_are_refused_and_roll_back(): void
    {
        $this->seedBoardAndOrg();
        [$electionId] = $this->seedCountedOwnerElection(100, 200, [11, 12, 13, 14]);

        // First certify succeeds and drives the election to final.
        $this->engine->file('F-ORG-003', $this->user(10),
            ['organization_id' => $this->id(1), 'action' => 'certify', 'election_id' => $electionId]);
        self::assertSame(Election::STATUS_FINAL, Election::findOrFail($electionId)->status);

        // Duplicate/stale: certifying the finalized election again is refused.
        $this->violation(fn () => $this->engine->file('F-ORG-003', $this->user(10),
            ['organization_id' => $this->id(1), 'action' => 'certify', 'election_id' => $electionId]));

        // Uncounted race: a second election whose race has no complete
        // tabulation is refused, and the refusal rolls back — no seats, no terms.
        $seatedBefore = BoardSeat::where('status', BoardSeat::STATUS_SEATED)->count();
        $termsBefore = Term::count();
        Election::create(['id' => $this->id(101), 'jurisdiction_id' => $this->id(2), 'board_id' => $this->id(3),
            'kind' => Election::KIND_ORG_BOARD_OWNER, 'status' => Election::STATUS_VOTING_CLOSED, 'trigger' => 'scheduled', 'voting_method' => 'stv_droop']);
        ElectionRace::create(['id' => $this->id(201), 'election_id' => $this->id(101), 'jurisdiction_id' => $this->id(2),
            'seat_kind' => ElectionRace::SEAT_KIND_TYPE_A, 'seats' => 2, 'finalist_count' => 6,
            'electorate_type' => ElectionRace::ELECTORATE_OWNERS, 'status' => Election::STATUS_VOTING_CLOSED]);
        $this->violation(fn () => $this->engine->file('F-ORG-003', $this->user(10),
            ['organization_id' => $this->id(1), 'action' => 'certify', 'election_id' => $this->id(101)]));
        self::assertSame($seatedBefore, BoardSeat::where('status', BoardSeat::STATUS_SEATED)->count());
        self::assertSame($termsBefore, Term::count());
    }

    private function violation(callable $call): void
    {
        try { $call(); self::fail('Expected a constitutional refusal.'); }
        catch (ConstitutionalViolation $e) { self::assertNotSame('', $e->getMessage()); }
    }
}
