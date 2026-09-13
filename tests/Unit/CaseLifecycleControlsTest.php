<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Http\Controllers\Judiciary\CaseController;
use App\Models\AuditEntry;
use App\Models\CaseFiling;
use App\Models\CourtCase;
use App\Models\InstanceSettings;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\Jurisdiction;
use App\Models\Panel;
use App\Models\PanelJudge;
use App\Models\PublicRecord;
use App\Models\User;
use App\Models\Verdict;
use App\Services\AuditService;
use App\Services\ConstitutionalValidator;
use App\Services\Education\TrainingGateService;
use App\Services\Judiciary\CaseFilingService;
use App\Services\Judiciary\CaseService;
use App\Services\PublicRecordService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * IO-1 case-lifecycle controls (operator ruling 2026-09-13). The real
 * handlers, controller, engine and CaseService drive paneled → heard →
 * deliberation → decided on a private in-memory SQLite fixture. No live world.
 */
final class CaseLifecycleControlsTest extends TestCase
{
    private string $original;

    private ConstitutionalEngine $engine;

    private CaseService $cases;

    /** Captures DB::transactionLevel() at each public-record publish. */
    private PublicRecordService $records;

    private array $publishLevels = [];

    private int $nextId = 5000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config([
            'database.connections.case_controls_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cga.demo_session_capture' => false,
        ]);
        DB::setDefaultConnection('case_controls_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        // Generic tables: fillable + id/timestamps/deleted_at, all text/nullable.
        foreach ([CourtCase::class, Judiciary::class, Jurisdiction::class, JudicialSeat::class,
            Panel::class, PanelJudge::class, Verdict::class, PublicRecord::class,
            InstanceSettings::class, User::class] as $class) {
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
        // The append-only docket: seq is the autoincrement PK, id is a uuid.
        DB::connection()->getSchemaBuilder()->create('case_filings', function (Blueprint $t) {
            $t->bigIncrements('seq');
            $t->string('id')->nullable();
            foreach (['case_id', 'filing_form', 'filing_kind', 'filed_by_user_id', 'filed_by_role',
                'advocate_id', 'title', 'body', 'ruling', 'ruling_reason', 'accepted_at_state',
                'record_id', 'audit_seq'] as $c) {
                $t->text($c)->nullable();
            }
            $t->timestamp('created_at')->nullable();
        });

        InstanceSettings::create(['instance_name' => 'Case controls fixture', 'instance_class' => 'production']);
        DB::table('jurisdictions')->insert(['id' => $this->id(1), 'name' => 'Court place']);
        Judiciary::create(['id' => $this->id(2), 'jurisdiction_id' => $this->id(1), 'court_name' => 'Civic court', 'type' => 'appointed', 'status' => 'appointed']);
        Judiciary::create(['id' => $this->id(3), 'jurisdiction_id' => $this->id(1), 'court_name' => 'Other court', 'type' => 'appointed', 'status' => 'appointed']);

        $audit = $this->createMock(AuditService::class);
        $audit->method('append')->willReturn((new AuditEntry)->forceFill(['seq' => 1]));

        $levels = &$this->publishLevels;
        $this->records = new class($audit, $levels) extends PublicRecordService {
            public function __construct(AuditService $audit, private array &$levels)
            {
                parent::__construct($audit);
            }

            public function publish(string $kind, string $title, ?string $body = null, array $attrs = []): PublicRecord
            {
                $this->levels[] = DB::transactionLevel();

                return parent::publish($kind, $title, $body, $attrs);
            }
        };

        $this->cases = new CaseService($this->records, $audit);
        $filings = new CaseFilingService($this->records, $audit);

        foreach ([CaseService::class => $this->cases, CaseFilingService::class => $filings,
            PublicRecordService::class => $this->records, AuditService::class => $audit] as $class => $instance) {
            $this->app->instance($class, $instance);
        }

        $roleGate = $this->createMock(ResolvesRoles::class);
        $roleGate->method('rolesFor')->willReturn(['R-19', 'R-20']);
        $this->engine = new ConstitutionalEngine($audit, new ConstitutionalValidator, $roleGate, $this->createMock(TrainingGateService::class));
    }

    protected function tearDown(): void
    {
        DB::purge('case_controls_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function id(int $n): string
    {
        return sprintf('7a000000-0000-4000-8000-%012d', $n);
    }

    private function controller(): CaseController
    {
        return new CaseController($this->engine, $this->cases);
    }

    /** A seated judge of the given court. */
    private function judge(int $n, string $judiciaryId): User
    {
        $user = (new User)->forceFill(['id' => $this->id($n), 'name' => 'Judge '.$n, 'display_name' => 'Judge '.$n]);
        $user->save();
        JudicialSeat::create(['id' => $this->id(1000 + $n), 'judiciary_id' => $judiciaryId, 'user_id' => (string) $user->getKey(), 'seat_number' => $n, 'status' => 'seated']);

        return $user;
    }

    /**
     * A case with a panel and the given users seated on it.
     *
     * @param  list<User>  $panelists
     */
    private function makeCase(string $status, string $kind, int $panelSize, array $panelists): CourtCase
    {
        $case = CourtCase::create([
            'id' => $this->id($this->nextId++),
            'docket_no' => 'case-2026-'.($this->nextId),
            'judiciary_id' => $this->id(2),
            'jurisdiction_id' => $this->id(1),
            'kind' => $kind,
            'title' => 'State v. Fixture',
            'filed_via_form' => 'F-IND-017',
            'court_severity' => 'serious',
            'jury_entitled' => $kind === CourtCase::KIND_CRIMINAL,
            'status' => $status,
        ]);

        if ($panelists !== []) {
            $panel = Panel::create(['id' => $this->id($this->nextId++), 'case_id' => (string) $case->id,
                'judiciary_id' => $this->id(2), 'size' => $panelSize, 'is_en_banc' => false, 'status' => 'seated']);
            $case->forceFill(['panel_id' => (string) $panel->id])->save();
            foreach ($panelists as $user) {
                PanelJudge::create(['id' => $this->id($this->nextId++), 'panel_id' => (string) $panel->id,
                    'judicial_seat_id' => $this->id(1000 + (int) substr((string) $user->getKey(), -3)),
                    'user_id' => (string) $user->getKey(), 'status' => PanelJudge::STATUS_SEATED, 'screening_result' => PanelJudge::SCREENING_CLEARED]);
            }
        }

        return $case->refresh();
    }

    private function req(User $actor, array $data): Request
    {
        $r = Request::create('/', 'POST', $data);
        $r->setUserResolver(fn () => $actor);

        return $r;
    }

    private function refused(callable $action): void
    {
        try {
            $action();
            self::fail('Expected a constitutional refusal.');
        } catch (ConstitutionalViolation $e) {
            self::assertNotSame('', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------

    public function test_seated_panel_judge_drives_hearing_deliberation_and_panel_verdict(): void
    {
        $judge = $this->judge(11, $this->id(2));
        $case = $this->makeCase(CourtCase::STATUS_PANELED, CourtCase::KIND_CRIMINAL, 3, [$judge]);
        $controller = $this->controller();

        $controller->hearing($this->req($judge, []), $case->fresh());
        self::assertSame(CourtCase::STATUS_HEARD, $case->fresh()->status);

        $controller->deliberation($this->req($judge, []), $case->fresh());
        self::assertSame(CourtCase::STATUS_DELIBERATION, $case->fresh()->status);

        $this->publishLevels = [];
        $controller->verdict($this->req($judge, [
            'decided_by' => 'panel', 'outcome' => 'guilty', 'panel_vote_for' => 3, 'panel_vote_against' => 0,
            'summary' => 'Guilty on all counts.',
        ]), $case->fresh());

        $case->refresh();
        self::assertSame(CourtCase::STATUS_DECIDED, $case->status);
        self::assertTrue((bool) $case->double_jeopardy_locked, 'A criminal verdict locks double jeopardy (Art. II §8).');
        $verdict = Verdict::query()->where('case_id', (string) $case->id)->firstOrFail();
        self::assertSame('guilty', $verdict->outcome);
        self::assertTrue((bool) $verdict->double_jeopardy_flag);
        // The verdict write ran inside a transaction (the case-row lock frame).
        self::assertGreaterThanOrEqual(1, (int) end($this->publishLevels));
    }

    public function test_non_judge_and_judge_of_another_court_are_refused_with_no_write(): void
    {
        $outsider = (new User)->forceFill(['id' => $this->id(20), 'name' => 'Citizen', 'display_name' => 'Citizen']);
        $outsider->save();
        $otherCourtJudge = $this->judge(21, $this->id(3)); // seated on a DIFFERENT court
        $panelJudge = $this->judge(22, $this->id(2));
        $case = $this->makeCase(CourtCase::STATUS_PANELED, CourtCase::KIND_CRIMINAL, 3, [$panelJudge]);
        $controller = $this->controller();

        $this->refused(fn () => $controller->hearing($this->req($outsider, []), $case->fresh()));
        $this->refused(fn () => $controller->hearing($this->req($otherCourtJudge, []), $case->fresh()));
        self::assertSame(CourtCase::STATUS_PANELED, $case->fresh()->status, 'A refused advance writes nothing.');
        self::assertSame(0, CaseFiling::query()->where('case_id', (string) $case->id)->count());
    }

    public function test_a_seated_judge_not_on_the_panel_cannot_record_the_verdict(): void
    {
        $panelJudge = $this->judge(31, $this->id(2));
        $offPanel = $this->judge(32, $this->id(2)); // seated on the court, NOT on this panel
        $case = $this->makeCase(CourtCase::STATUS_DELIBERATION, CourtCase::KIND_CRIMINAL, 3, [$panelJudge]);
        $controller = $this->controller();

        $this->refused(fn () => $controller->verdict($this->req($offPanel, [
            'decided_by' => 'panel', 'outcome' => 'guilty', 'panel_vote_for' => 3, 'panel_vote_against' => 0,
        ]), $case->fresh()));
        self::assertSame(CourtCase::STATUS_DELIBERATION, $case->fresh()->status);
        self::assertSame(0, Verdict::query()->where('case_id', (string) $case->id)->count());
    }

    public function test_second_hearing_order_and_second_verdict_are_refused_by_the_state_machine(): void
    {
        $judge = $this->judge(41, $this->id(2));
        $case = $this->makeCase(CourtCase::STATUS_HEARD, CourtCase::KIND_CRIMINAL, 3, [$judge]);
        $controller = $this->controller();

        // heard → heard is not a legal edge.
        $this->refused(fn () => $controller->hearing($this->req($judge, []), $case->fresh()));

        $controller->deliberation($this->req($judge, []), $case->fresh());
        $controller->verdict($this->req($judge, [
            'decided_by' => 'panel', 'outcome' => 'not_guilty', 'panel_vote_for' => 1, 'panel_vote_against' => 2,
        ]), $case->fresh());
        self::assertSame(CourtCase::STATUS_DECIDED, $case->fresh()->status);

        // decided → decided is not a legal edge.
        $this->refused(fn () => $controller->verdict($this->req($judge, [
            'decided_by' => 'panel', 'outcome' => 'not_guilty', 'panel_vote_for' => 1, 'panel_vote_against' => 2,
        ]), $case->fresh()));
        self::assertSame(1, Verdict::query()->where('case_id', (string) $case->id)->count());
    }

    public function test_panel_vote_must_sum_to_the_panel_size(): void
    {
        $judge = $this->judge(51, $this->id(2));
        $case = $this->makeCase(CourtCase::STATUS_DELIBERATION, CourtCase::KIND_CRIMINAL, 3, [$judge]);

        $this->refused(fn () => $this->controller()->verdict($this->req($judge, [
            'decided_by' => 'panel', 'outcome' => 'guilty', 'panel_vote_for' => 2, 'panel_vote_against' => 0,
        ]), $case->fresh()));
        self::assertSame(CourtCase::STATUS_DELIBERATION, $case->fresh()->status);
    }

    public function test_outcome_not_carried_by_the_majority_is_refused(): void
    {
        $judge = $this->judge(61, $this->id(2));
        $case = $this->makeCase(CourtCase::STATUS_DELIBERATION, CourtCase::KIND_CRIMINAL, 3, [$judge]);

        // 1 for, 2 against → the majority is negative; a guilty outcome contradicts it.
        $this->refused(fn () => $this->controller()->verdict($this->req($judge, [
            'decided_by' => 'panel', 'outcome' => 'guilty', 'panel_vote_for' => 1, 'panel_vote_against' => 2,
        ]), $case->fresh()));
        self::assertSame(0, Verdict::query()->where('case_id', (string) $case->id)->count());
    }

    public function test_jury_verdict_without_unanimity_is_refused(): void
    {
        $judge = $this->judge(71, $this->id(2));
        $case = $this->makeCase(CourtCase::STATUS_DELIBERATION, CourtCase::KIND_CRIMINAL, 3, [$judge]);
        // The jury sat (markJuryEmpaneled is the live path; the fixture sets the
        // fact directly), so decided_by=jury is lawful for this case.
        $case->forceFill(['jury_id' => $this->id(7900)])->save();

        $this->refused(fn () => $this->controller()->verdict($this->req($judge, [
            'decided_by' => 'jury', 'outcome' => 'guilty', 'jury_unanimous' => false,
        ]), $case->fresh()));

        // A unanimous jury verdict is recorded.
        $this->controller()->verdict($this->req($judge, [
            'decided_by' => 'jury', 'outcome' => 'not_guilty', 'jury_unanimous' => true,
        ]), $case->fresh());
        self::assertSame(CourtCase::STATUS_DECIDED, $case->fresh()->status);
        self::assertSame('not_guilty', Verdict::query()->where('case_id', (string) $case->id)->firstOrFail()->outcome);
    }

    public function test_jury_verdict_on_a_case_with_no_jury_is_refused(): void
    {
        // A criminal case can reach deliberation with the jury waived, and a
        // civil case never seats a jury; a jury verdict must not be recordable
        // on either — a null jury_id proves no jury sat. Without this guard the
        // panel majority math is bypassable and a jury unanimity is fabricated.
        $judge = $this->judge(72, $this->id(2));
        $case = $this->makeCase(CourtCase::STATUS_DELIBERATION, CourtCase::KIND_CRIMINAL, 3, [$judge]);
        self::assertNull($case->jury_id, 'This case never empaneled a jury.');

        $this->refused(fn () => $this->controller()->verdict($this->req($judge, [
            'decided_by' => 'jury', 'outcome' => 'guilty', 'jury_unanimous' => true,
        ]), $case->fresh()));
        self::assertSame(CourtCase::STATUS_DELIBERATION, $case->fresh()->status);
        self::assertSame(0, Verdict::query()->where('case_id', (string) $case->id)->count());
    }

    public function test_dismissal_works_from_filed_and_from_accepted_and_is_refused_later(): void
    {
        $judge = $this->judge(81, $this->id(2));
        $controller = $this->controller();

        $filed = $this->makeCase(CourtCase::STATUS_FILED, CourtCase::KIND_CIVIL, 3, []);
        $controller->dismissal($this->req($judge, ['reason' => 'Not justiciable.']), $filed->fresh());
        self::assertSame(CourtCase::STATUS_DISMISSED, $filed->fresh()->status);

        $accepted = $this->makeCase(CourtCase::STATUS_ACCEPTED, CourtCase::KIND_CIVIL, 3, []);
        $controller->dismissal($this->req($judge, ['reason' => 'Withdrawn by the parties.']), $accepted->fresh());
        self::assertSame(CourtCase::STATUS_DISMISSED, $accepted->fresh()->status);

        // A dismissal without a reason is refused.
        $another = $this->makeCase(CourtCase::STATUS_FILED, CourtCase::KIND_CIVIL, 3, []);
        $this->refused(fn () => $controller->dismissal($this->req($judge, ['reason' => '']), $another->fresh()));

        // A paneled case is past the dismissal window.
        $paneled = $this->makeCase(CourtCase::STATUS_PANELED, CourtCase::KIND_CIVIL, 3, [$judge]);
        $this->refused(fn () => $controller->dismissal($this->req($judge, ['reason' => 'Too late.']), $paneled->fresh()));
    }

    public function test_ruling_appends_a_follow_up_filing_with_ruling_and_reason(): void
    {
        $judge = $this->judge(91, $this->id(2));
        $case = $this->makeCase(CourtCase::STATUS_HEARD, CourtCase::KIND_CRIMINAL, 3, [$judge]);

        $this->controller()->ruling($this->req($judge, [
            'filing_kind' => 'motion', 'ruling' => 'granted', 'ruling_reason' => 'The motion is well founded.',
        ]), $case->fresh());

        $filing = CaseFiling::query()->where('case_id', (string) $case->id)->where('filing_kind', 'motion')->firstOrFail();
        self::assertSame('granted', $filing->ruling);
        self::assertSame('The motion is well founded.', $filing->ruling_reason);
        self::assertSame('F-JDG-014', $filing->filing_form);

        // An unknown ruling value is refused.
        $this->refused(fn () => $this->controller()->ruling($this->req($judge, [
            'filing_kind' => 'motion', 'ruling' => 'maybe', 'ruling_reason' => 'x',
        ]), $case->fresh()));
    }
}
