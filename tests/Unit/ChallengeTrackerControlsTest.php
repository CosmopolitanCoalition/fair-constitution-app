<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Domain\Engine\EngineResult;
use App\Http\Controllers\Judiciary\ChallengeController;
use App\Http\Controllers\Legislature\BillController;
use App\Models\AuditEntry;
use App\Models\ConstitutionalChallenge;
use App\Models\ConstitutionalFinding;
use App\Models\InstanceSettings;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\Jurisdiction;
use App\Models\Law;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\PublicRecord;
use App\Models\RemedyRecommendation;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ConstitutionalValidator;
use App\Services\EnactmentService;
use App\Services\Education\TrainingGateService;
use App\Services\Judiciary\ConstitutionalChallengeService;
use App\Services\Judiciary\JudicialRemedyService;
use App\Http\Presenters\ChamberVotePresenter;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * IO-3 constitutional-challenge outcome controls (operator ruling 2026-09-13).
 * The four existing handlers (finding F-JDG-004, remedy recommendation
 * F-JDG-005, override F-LEG-035, judicial remedy F-JDG-006) are now reachable
 * from the tracker. This pins:
 *   - each new route dispatches the exact form id + payload (a recording engine);
 *   - the page gate flags flip with status / windows / role (the private
 *     outcomeGates hint), and nothing is enabled on a closed challenge;
 *   - the bill prefill forwards targets_challenge_id (Path 1 wiring);
 *   - premature and repeated F-JDG-006 are refused by the REAL
 *     JudicialRemedyService guards when exercised through the route.
 * A private in-memory SQLite fixture — never the live world.
 */
final class ChallengeTrackerControlsTest extends TestCase
{
    private string $original;

    private int $nextId = 6000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config([
            'database.connections.challenge_controls_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cga.demo_session_capture' => false,
        ]);
        DB::setDefaultConnection('challenge_controls_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        foreach ([ConstitutionalChallenge::class, ConstitutionalFinding::class, RemedyRecommendation::class,
            Law::class, JudicialSeat::class, Judiciary::class, Jurisdiction::class, Legislature::class,
            LegislatureMember::class, PublicRecord::class, InstanceSettings::class, User::class] as $class) {
            $model = new $class;
            DB::connection()->getSchemaBuilder()->create($model->getTable(), function (Blueprint $t) use ($model) {
                if ($model instanceof PublicRecord) {
                    $t->bigIncrements('seq');
                }
                foreach (array_unique([...$model->getFillable(), 'id', 'created_at', 'updated_at', 'deleted_at']) as $column) {
                    if ($column === 'id') {
                        $t->string('id')->unique();
                    } else {
                        $t->text($column)->nullable();
                    }
                }
            });
        }

        InstanceSettings::create(['instance_name' => 'Challenge controls fixture', 'instance_class' => 'production']);
    }

    protected function tearDown(): void
    {
        DB::purge('challenge_controls_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function id(int $n): string
    {
        return sprintf('7c000000-0000-4000-8000-%012d', $n);
    }

    private function user(int $n): User
    {
        $user = (new User)->forceFill(['id' => $this->id($n), 'name' => 'Person '.$n, 'display_name' => 'Person '.$n]);
        $user->save();

        return $user;
    }

    /** A seated judge of the given court. */
    private function judge(int $n, string $judiciaryId): User
    {
        $user = $this->user($n);
        JudicialSeat::create(['id' => $this->id(1000 + $n), 'judiciary_id' => $judiciaryId,
            'user_id' => (string) $user->getKey(), 'seat_number' => $n, 'status' => JudicialSeat::STATUS_SEATED]);

        return $user;
    }

    /** A seated member of the given legislature. */
    private function member(int $n, string $legislatureId): User
    {
        $user = $this->user($n);
        LegislatureMember::create(['id' => $this->id(2000 + $n), 'legislature_id' => $legislatureId,
            'user_id' => (string) $user->getKey(), 'seat_no' => $n, 'status' => LegislatureMember::STATUS_SEATED]);

        return $user;
    }

    /** The standard graph: jurisdiction → judiciary + legislature + an in-force law. */
    private function scene(): array
    {
        $jur = $this->id(1);
        DB::table('jurisdictions')->insert(['id' => $jur, 'name' => 'Fixture place']);
        $court = Judiciary::create(['id' => $this->id(2), 'jurisdiction_id' => $jur, 'court_name' => 'Fixture court', 'type' => 'appointed', 'status' => 'appointed']);
        $leg = Legislature::create(['id' => $this->id(3), 'jurisdiction_id' => $jur, 'status' => Legislature::STATUS_ACTIVE, 'term_number' => 1]);
        $law = Law::create(['id' => $this->id(4), 'jurisdiction_id' => $jur, 'legislature_id' => (string) $leg->id,
            'act_number' => '2026-1', 'title' => 'Fixture Act', 'status' => Law::STATUS_IN_FORCE, 'current_version_no' => 1]);

        return ['jurisdiction' => $jur, 'court' => $court, 'legislature' => $leg, 'law' => $law];
    }

    /**
     * A saved challenge in the given status, optionally with a finding + remedy
     * whose windows are offset from now by the given day deltas.
     */
    private function makeChallenge(string $status, array $scene, ?int $vetoDeltaDays = null, ?int $remedyDeltaDays = null): ConstitutionalChallenge
    {
        $challenge = ConstitutionalChallenge::create([
            'id' => $this->id($this->nextId++),
            'jurisdiction_id' => $scene['jurisdiction'],
            'judiciary_id' => (string) $scene['court']->id,
            'challenged_law_id' => (string) $scene['law']->id,
            'challenged_version_no' => 1,
            'claim_text' => 'The law impedes a right.',
            'claimed_basis' => ConstitutionalChallenge::BASIS_CONSTITUTION,
            'status' => $status,
            'filed_at' => now(),
        ]);

        if ($vetoDeltaDays !== null) {
            $finding = ConstitutionalFinding::create([
                'id' => $this->id($this->nextId++),
                'challenge_id' => (string) $challenge->id,
                'judiciary_id' => (string) $scene['court']->id,
                'finds_contradiction' => true,
                'contradiction_against' => ConstitutionalChallenge::BASIS_CONSTITUTION,
                'offending_law_id' => (string) $scene['law']->id,
                'offending_version_no' => 1,
                'opinion_text' => 'A contradiction exists.',
                'issued_at' => now(),
            ]);
            $remedy = RemedyRecommendation::create([
                'id' => $this->id($this->nextId++),
                'finding_id' => (string) $finding->id,
                'challenge_id' => (string) $challenge->id,
                'judiciary_id' => (string) $scene['court']->id,
                'remedy_kind' => RemedyRecommendation::KIND_MODIFY,
                'recommended_text' => 'Corrected text.',
                'rationale_text' => 'Because.',
                'remedy_timeframe_days' => 30,
                'veto_window_days' => 30,
                'remedy_due_at' => now()->addDays($remedyDeltaDays ?? $vetoDeltaDays),
                'veto_closes_at' => now()->addDays($vetoDeltaDays),
                'issued_at' => now(),
            ]);
            $challenge->forceFill(['finding_id' => (string) $finding->id, 'remedy_id' => (string) $remedy->id])->save();
            $challenge->refresh();
        }

        return $challenge;
    }

    private function req(?User $actor, array $data): Request
    {
        $r = Request::create('/', 'POST', $data);
        $r->setUserResolver(fn () => $actor);

        return $r;
    }

    /** A ChallengeController with a recording engine (routing-only). */
    private function recordingController(array &$calls): ChallengeController
    {
        $engine = new class($calls) extends ConstitutionalEngine {
            public function __construct(private array &$calls) {}

            public function file(string $formId, ?User $actor, array $payload): EngineResult
            {
                $this->calls[] = ['form' => $formId, 'payload' => $payload];

                return new EngineResult($formId, (new AuditEntry)->forceFill(['seq' => 1]), $payload);
            }
        };

        return new ChallengeController($engine, $this->createMock(RoleService::class));
    }

    /** @return array<string, bool> the private outcomeGates hint for a viewer. */
    private function gates(ChallengeController $controller, ?ConstitutionalChallenge $challenge, ?User $user): array
    {
        $m = new ReflectionMethod($controller, 'outcomeGates');
        $m->setAccessible(true);

        return $m->invoke($controller, $challenge, $user);
    }

    // -------------------------------------------------------------------------

    public function test_each_route_dispatches_its_form_id_and_payload(): void
    {
        $scene = $this->scene();
        $challenge = $this->makeChallenge(ConstitutionalChallenge::STATUS_UNDER_REVIEW, $scene);
        $judge = $this->judge(11, (string) $scene['court']->id);

        $calls = [];
        $controller = $this->recordingController($calls);

        $controller->finding($this->req($judge, ['finds_contradiction' => '1', 'opinion_text' => 'op', 'full_court' => '1']), $challenge);
        $controller->recommend($this->req($judge, ['remedy_kind' => 'modify', 'recommended_text' => 'x', 'rationale_text' => 'y', 'remedy_timeframe_days' => 30, 'veto_window_days' => 30]), $challenge);
        $controller->override($this->req($judge, ['dissent_text' => 'no']), $challenge);
        $controller->remedy($this->req($judge, []), $challenge);

        self::assertSame(['F-JDG-004', 'F-JDG-005', 'F-LEG-035', 'F-JDG-006'], array_column($calls, 'form'));

        // Finding payload: bool cast + defaults from the challenge row.
        self::assertSame((string) $challenge->id, $calls[0]['payload']['challenge_id']);
        self::assertTrue($calls[0]['payload']['finds_contradiction']);
        self::assertTrue($calls[0]['payload']['full_court']);
        self::assertSame((string) $challenge->challenged_law_id, $calls[0]['payload']['offending_law_id']);
        self::assertSame($challenge->claimed_basis, $calls[0]['payload']['contradiction_against']);
        self::assertNull($calls[0]['payload']['offending_version_no']);

        // Recommendation payload.
        self::assertSame('modify', $calls[1]['payload']['remedy_kind']);
        self::assertSame(30, $calls[1]['payload']['remedy_timeframe_days']);
        self::assertSame(30, $calls[1]['payload']['veto_window_days']);

        // Override + remedy carry the challenge id and jurisdiction scope.
        self::assertSame('no', $calls[2]['payload']['dissent_text']);
        self::assertSame((string) $challenge->id, $calls[3]['payload']['challenge_id']);
        self::assertSame((string) $challenge->jurisdiction_id, $calls[3]['payload']['jurisdiction_id']);
    }

    public function test_finding_and_recommend_gates_are_seated_judge_and_status(): void
    {
        $scene = $this->scene();
        $judge = $this->judge(21, (string) $scene['court']->id);
        $calls = [];
        $controller = $this->recordingController($calls);

        $underReview = $this->makeChallenge(ConstitutionalChallenge::STATUS_UNDER_REVIEW, $scene);
        $g = $this->gates($controller, $underReview, $judge);
        self::assertTrue($g['isSeatedJudge']);
        self::assertTrue($g['finding'], 'finding is enabled for a seated judge while under review');
        self::assertFalse($g['recommend']);

        $findingIssued = $this->makeChallenge(ConstitutionalChallenge::STATUS_FINDING_ISSUED, $scene);
        $g = $this->gates($controller, $findingIssued, $judge);
        self::assertFalse($g['finding'], 'finding is closed once the finding is issued');
        self::assertTrue($g['recommend'], 'recommend opens after a contradiction is found');
    }

    public function test_override_gate_is_offending_legislature_member_within_veto_window(): void
    {
        $scene = $this->scene();
        $member = $this->member(31, (string) $scene['legislature']->id);
        $outsider = $this->user(32);
        $calls = [];
        $controller = $this->recordingController($calls);

        // Window open, veto window still open (closes in 10 days).
        $open = $this->makeChallenge(ConstitutionalChallenge::STATUS_LEGISLATIVE_WINDOW_OPEN, $scene, 10, 5);
        $g = $this->gates($controller, $open, $member);
        self::assertTrue($g['isLegislatureMember']);
        self::assertTrue($g['override'], 'override is enabled for a member inside the veto window');
        self::assertTrue($g['proposeAmendment']);
        self::assertFalse($g['remedy'], 'the remedy is not yet applicable — the windows are open');

        // A non-member sees no override control (absent, not disabled).
        $g = $this->gates($controller, $open, $outsider);
        self::assertFalse($g['isLegislatureMember']);
        self::assertFalse($g['override']);
        self::assertFalse($g['proposeAmendment']);

        // The veto window has closed (7 days ago) → override barred, remedy opens.
        $closedVeto = $this->makeChallenge(ConstitutionalChallenge::STATUS_LEGISLATIVE_WINDOW_OPEN, $scene, -7, -7);
        $g = $this->gates($controller, $closedVeto, $member);
        self::assertFalse($g['override'], 'override is barred once the veto window closes');
    }

    public function test_remedy_gate_needs_both_windows_closed_and_a_seated_judge(): void
    {
        $scene = $this->scene();
        $judge = $this->judge(41, (string) $scene['court']->id);
        $calls = [];
        $controller = $this->recordingController($calls);

        // Both windows in the future → not yet.
        $future = $this->makeChallenge(ConstitutionalChallenge::STATUS_LEGISLATIVE_WINDOW_OPEN, $scene, 10, 5);
        self::assertFalse($this->gates($controller, $future, $judge)['remedy']);

        // Both windows in the past → the seated judge may apply the remedy.
        $past = $this->makeChallenge(ConstitutionalChallenge::STATUS_LEGISLATIVE_WINDOW_OPEN, $scene, -3, -3);
        self::assertTrue($this->gates($controller, $past, $judge)['remedy']);
    }

    public function test_a_closed_challenge_enables_nothing_but_stays_visible_to_its_actors(): void
    {
        $scene = $this->scene();
        $judge = $this->judge(51, (string) $scene['court']->id);
        $member = $this->member(52, (string) $scene['legislature']->id);
        $calls = [];
        $controller = $this->recordingController($calls);

        $closed = $this->makeChallenge(ConstitutionalChallenge::STATUS_CLOSED, $scene, -3, -3);

        $gJudge = $this->gates($controller, $closed, $judge);
        self::assertTrue($gJudge['isSeatedJudge'], 'the judge still sees the controls (disabled, not hidden)');
        self::assertFalse($gJudge['finding']);
        self::assertFalse($gJudge['recommend']);
        self::assertFalse($gJudge['remedy']);

        $gMember = $this->gates($controller, $closed, $member);
        self::assertTrue($gMember['isLegislatureMember']);
        self::assertFalse($gMember['override']);
        self::assertFalse($gMember['proposeAmendment']);
    }

    /**
     * IO-3, the register's "repeated application after legislative remedy or
     * override" clause, at the tracker gate: once a challenge has been resolved
     * by a Path-1 legislative amendment (amended_by_legislature) or a Path-2
     * override (overridden), the outcome gates enable NOTHING — no repeated
     * override, no repeated remedy, no further amendment proposal — even with
     * both windows in the past. The controls stay visible to their actors
     * (disabled, not hidden). This pins the two Path-1/Path-2 terminal statuses
     * distinctly from the generic `closed` covered above; the ENGINE-level
     * repeated-application refusals are exercised on a disposable PostgreSQL
     * database by tests/concurrency/challenge_direct_remedy.php.
     */
    public function test_resolved_challenge_enables_no_repeated_application(): void
    {
        $scene = $this->scene();
        $judge = $this->judge(91, (string) $scene['court']->id);
        $member = $this->member(92, (string) $scene['legislature']->id);
        $calls = [];
        $controller = $this->recordingController($calls);

        foreach ([
            ConstitutionalChallenge::STATUS_AMENDED_BY_LEGISLATURE,
            ConstitutionalChallenge::STATUS_OVERRIDDEN,
        ] as $status) {
            // Both windows in the PAST — only the resolved status bars the gate.
            $resolved = $this->makeChallenge($status, $scene, -5, -5);

            $gMember = $this->gates($controller, $resolved, $member);
            self::assertTrue($gMember['isLegislatureMember'], "member still sees the controls ({$status})");
            self::assertFalse($gMember['override'], "no repeated override after resolution ({$status})");
            self::assertFalse($gMember['proposeAmendment'], "no further amendment after resolution ({$status})");

            $gJudge = $this->gates($controller, $resolved, $judge);
            self::assertTrue($gJudge['isSeatedJudge'], "judge still sees the controls ({$status})");
            self::assertFalse($gJudge['remedy'], "no repeated judicial remedy after resolution ({$status})");
            self::assertFalse($gJudge['finding']);
            self::assertFalse($gJudge['recommend']);
        }
    }

    public function test_bill_prefill_forwards_targets_challenge_id(): void
    {
        $scene = $this->scene();
        $challenge = $this->makeChallenge(ConstitutionalChallenge::STATUS_LEGISLATIVE_WINDOW_OPEN, $scene, 10, 5);

        $calls = [];
        $engine = new class($calls) extends ConstitutionalEngine {
            public function __construct(private array &$calls) {}

            public function file(string $formId, ?User $actor, array $payload): EngineResult
            {
                $this->calls[] = ['form' => $formId, 'payload' => $payload];

                return new EngineResult($formId, (new AuditEntry)->forceFill(['seq' => 1]), $payload);
            }
        };
        $controller = new BillController($engine, new ConstitutionalValidator,
            $this->createMock(ChamberVotePresenter::class), $this->createMock(SettingsResolver::class));

        $request = $this->req($this->user(61), [
            'title' => 'Curing Act', 'law_text' => 'text', 'act_type' => 'ordinary',
            'targets_challenge_id' => (string) $challenge->id,
        ]);
        $controller->store($request, $scene['legislature']);

        self::assertSame('F-LEG-003', $calls[0]['form']);
        self::assertSame((string) $challenge->id, $calls[0]['payload']['targets_challenge_id']);
    }

    // ---- The real JudicialRemedyService guards, exercised through the route ---

    private function realEngineController(): ChallengeController
    {
        $audit = $this->createMock(AuditService::class);
        $audit->method('append')->willReturn((new AuditEntry)->forceFill(['seq' => 1]));

        $roleGate = $this->createMock(ResolvesRoles::class);
        $roleGate->method('rolesFor')->willReturn(['R-19', 'R-20']);

        // The REAL guard owner. Its deps are mocks: both guards (premature /
        // repeated) fire BEFORE any enactment or timer write, so they are never
        // reached.
        $remedies = new JudicialRemedyService(
            $this->createMock(EnactmentService::class),
            $this->createMock(PublicRecordService::class),
            $audit,
            $this->createMock(ConstitutionalChallengeService::class),
        );
        $this->app->instance(JudicialRemedyService::class, $remedies);

        $engine = new ConstitutionalEngine($audit, new ConstitutionalValidator, $roleGate, $this->createMock(TrainingGateService::class));

        return new ChallengeController($engine, $this->createMock(RoleService::class));
    }

    public function test_premature_remedy_is_refused_through_the_route(): void
    {
        $scene = $this->scene();
        $judge = $this->judge(71, (string) $scene['court']->id);
        // Both windows in the FUTURE — the premature guard must throw.
        $challenge = $this->makeChallenge(ConstitutionalChallenge::STATUS_LEGISLATIVE_WINDOW_OPEN, $scene, 10, 5);
        $controller = $this->realEngineController();

        try {
            $controller->remedy($this->req($judge, []), $challenge);
            self::fail('Expected a premature-remedy refusal.');
        } catch (ConstitutionalViolation $e) {
            self::assertStringContainsString('Art. IV §5', $e->citation);
        }

        self::assertSame(ConstitutionalChallenge::STATUS_LEGISLATIVE_WINDOW_OPEN, $challenge->fresh()->status,
            'a refused remedy writes nothing');
    }

    public function test_repeated_remedy_is_refused_through_the_route(): void
    {
        $scene = $this->scene();
        $judge = $this->judge(81, (string) $scene['court']->id);
        // Already resolved (closed) — the repeated-application guard returns null
        // and the handler re-throws.
        $challenge = $this->makeChallenge(ConstitutionalChallenge::STATUS_CLOSED, $scene, -3, -3);
        $controller = $this->realEngineController();

        $this->expectException(ConstitutionalViolation::class);
        $controller->remedy($this->req($judge, []), $challenge);
    }
}
