<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Http\Controllers\Judiciary\CaseController;
use App\Models\AuditEntry;
use App\Models\CaseFiling;
use App\Models\CaseParty;
use App\Models\CourtCase;
use App\Models\InstanceSettings;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\Jurisdiction;
use App\Models\Opinion;
use App\Models\OpinionLawLink;
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
use App\Services\Judiciary\PanelService;
use App\Services\PublicRecordService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * IO-2 appeals workflow (operator ruling 2026-09-13, appeals-workflow-rules =
 * B). The real AppealFiling / OpinionRulingFiling handlers, CaseController,
 * engine and CaseService drive an appeal end to end on a private in-memory
 * SQLite fixture. No live world.
 *
 *   filing → the original moves decided|sentenced → appealed (verdict UNTOUCHED),
 *   a new linked case opens at the parent court (or the same court en banc);
 *   the appeal panel records the outcome through F-JDG-003; a criminal appeal
 *   may only affirm or vacate, and double jeopardy is never lifted (Art. II §8).
 */
final class AppealWorkflowTest extends TestCase
{
    private string $original;

    private ConstitutionalEngine $engine;

    private CaseService $cases;

    private int $nextId = 5000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config([
            'database.connections.appeal_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cga.demo_session_capture' => false,
        ]);
        DB::setDefaultConnection('appeal_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        foreach ([CourtCase::class, Judiciary::class, Jurisdiction::class, JudicialSeat::class,
            Panel::class, PanelJudge::class, Verdict::class, Opinion::class, OpinionLawLink::class,
            CaseParty::class, PublicRecord::class, InstanceSettings::class, User::class] as $class) {
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

        InstanceSettings::create(['instance_name' => 'Appeal fixture', 'instance_class' => 'production']);
        DB::table('jurisdictions')->insert(['id' => $this->id(1), 'name' => 'Court place']);
        // A child court WITH a parent (the appellate court), and a solo court with none.
        Judiciary::create(['id' => $this->id(2), 'jurisdiction_id' => $this->id(1), 'court_name' => 'Appellate court', 'type' => 'appointed', 'status' => 'appointed']);
        Judiciary::create(['id' => $this->id(3), 'jurisdiction_id' => $this->id(1), 'court_name' => 'Trial court', 'type' => 'appointed', 'status' => 'appointed', 'parent_judiciary_id' => $this->id(2)]);
        Judiciary::create(['id' => $this->id(4), 'jurisdiction_id' => $this->id(1), 'court_name' => 'Solo court', 'type' => 'appointed', 'status' => 'appointed']);

        $audit = $this->createMock(AuditService::class);
        $audit->method('append')->willReturn((new AuditEntry)->forceFill(['seq' => 1]));

        $this->cases = new CaseService(new PublicRecordService($audit), $audit);
        $filings = new CaseFilingService(new PublicRecordService($audit), $audit);

        foreach ([CaseService::class => $this->cases, CaseFilingService::class => $filings,
            AuditService::class => $audit] as $class => $instance) {
            $this->app->instance($class, $instance);
        }

        $roleGate = $this->createMock(ResolvesRoles::class);
        // Every fixture actor holds the filing + judicial roles; the PARTY check
        // and the SEAT check are the real gates under test.
        $roleGate->method('rolesFor')->willReturn(['R-03', 'R-21', 'R-19', 'R-20']);
        $this->engine = new ConstitutionalEngine($audit, new ConstitutionalValidator, $roleGate, $this->createMock(TrainingGateService::class));
    }

    protected function tearDown(): void
    {
        DB::purge('appeal_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function id(int $n): string
    {
        return sprintf('7c000000-0000-4000-8000-%012d', $n);
    }

    private function controller(?ConstitutionalEngine $engine = null): CaseController
    {
        return new CaseController($engine ?? $this->engine, $this->cases);
    }

    private function user(int $n): User
    {
        $u = (new User)->forceFill(['id' => $this->id($n), 'name' => 'Person '.$n, 'display_name' => 'Person '.$n]);
        $u->save();

        return $u;
    }

    private function judge(int $n, string $judiciaryId): User
    {
        $u = $this->user($n);
        JudicialSeat::create(['id' => $this->id(9000 + $n), 'judiciary_id' => $judiciaryId, 'user_id' => (string) $u->getKey(), 'seat_number' => $n, 'status' => 'seated']);

        return $u;
    }

    /**
     * A decided/sentenced original with a preserved verdict + the given parties.
     *
     * @param  list<array<string,mixed>>  $parties
     */
    private function original(string $status, string $kind, string $judiciaryId, array $parties, bool $dj = false): CourtCase
    {
        $case = CourtCase::create([
            'id' => $this->id($this->nextId++),
            'docket_no' => 'case-2026-'.$this->nextId,
            'judiciary_id' => $judiciaryId,
            'jurisdiction_id' => $this->id(1),
            'kind' => $kind,
            'title' => 'State v. Fixture',
            'filed_via_form' => 'F-IND-017',
            'court_severity' => 'serious',
            'status' => $status,
            'double_jeopardy_locked' => $dj,
        ]);
        Verdict::create([
            'id' => $this->id($this->nextId++),
            'case_id' => (string) $case->id,
            'decided_by' => 'panel',
            'outcome' => $kind === CourtCase::KIND_CRIMINAL ? 'guilty' : 'liable',
            'double_jeopardy_flag' => $kind === CourtCase::KIND_CRIMINAL,
        ]);
        foreach ($parties as $p) {
            CaseParty::create(array_merge(
                ['id' => $this->id($this->nextId++), 'case_id' => (string) $case->id, 'status' => CaseParty::STATUS_ACTIVE],
                $p,
            ));
        }

        return $case->refresh();
    }

    private function plaintiff(User $u): array
    {
        return ['party_role' => CaseParty::ROLE_PLAINTIFF, 'party_type' => CaseParty::TYPE_INDIVIDUAL, 'party_user_id' => (string) $u->getKey()];
    }

    private function accused(User $u): array
    {
        return ['party_role' => CaseParty::ROLE_ACCUSED, 'party_type' => CaseParty::TYPE_INDIVIDUAL, 'party_user_id' => (string) $u->getKey()];
    }

    private function req(?User $actor, array $data): Request
    {
        $r = Request::create('/', 'POST', $data);
        $r->setUserResolver(fn () => $actor);

        return $r;
    }

    private function appealOf(CourtCase $original): ?CourtCase
    {
        return CourtCase::query()->where('appeal_of_case_id', (string) $original->id)->first();
    }

    /** Bring an appeal case to `decided` with a panel + seated judge, then rule. */
    private function recordOutcome(User $judge, CourtCase $appeal, string $outcome): void
    {
        $appeal->forceFill(['status' => CourtCase::STATUS_DECIDED])->save();
        Panel::create(['id' => $this->id($this->nextId++), 'case_id' => (string) $appeal->id,
            'judiciary_id' => (string) $appeal->judiciary_id, 'size' => 3, 'is_en_banc' => false, 'status' => 'seated']);

        $this->controller()->opinion($this->req($judge, [
            'kind' => 'majority', 'title' => 'Appellate opinion', 'body' => 'Reasoning on the appeal.',
            'appeal_outcome' => $outcome,
        ]), $appeal->fresh());
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

    public function test_a_party_appeals_a_decided_civil_case_to_the_parent_court(): void
    {
        $appellant = $this->user(11);
        $original = $this->original(CourtCase::STATUS_DECIDED, CourtCase::KIND_CIVIL, $this->id(3), [$this->plaintiff($appellant)]);

        $this->controller()->appeal($this->req($appellant, ['grounds' => 'The ruling misread the statute.']), $original->fresh());

        $original->refresh();
        self::assertSame(CourtCase::STATUS_APPEALED, $original->status, 'The original rests as appealed.');
        // The verdict row is untouched.
        $verdict = Verdict::query()->where('case_id', (string) $original->id)->firstOrFail();
        self::assertSame('liable', $verdict->outcome);

        $appeal = $this->appealOf($original);
        self::assertNotNull($appeal, 'A linked appeal case exists.');
        self::assertSame($this->id(2), (string) $appeal->judiciary_id, 'The appeal is heard by the parent court.');
        self::assertStringStartsWith('Appeal of ', (string) $appeal->title);
        self::assertSame(CourtCase::KIND_CIVIL, $appeal->kind);
        self::assertSame(CourtCase::STATUS_FILED, $appeal->status, 'The appeal starts its own lifecycle at filed.');
    }

    public function test_appeal_with_no_parent_goes_en_banc_to_the_same_court(): void
    {
        $appellant = $this->user(21);
        $original = $this->original(CourtCase::STATUS_SENTENCED, CourtCase::KIND_CIVIL, $this->id(4), [$this->plaintiff($appellant)]);

        $this->controller()->appeal($this->req($appellant, ['grounds' => 'Clear error.']), $original->fresh());

        $appeal = $this->appealOf($original->fresh());
        self::assertNotNull($appeal);
        self::assertSame($this->id(4), (string) $appeal->judiciary_id, 'No parent court — the same court hears it en banc.');
        self::assertSame((string) $original->judiciary_id, (string) $appeal->judiciary_id);
    }

    public function test_a_no_parent_appeal_panel_seats_the_full_court_en_banc(): void
    {
        // Five seated judges on the solo court (no parent) and five on the parent.
        foreach ([61, 62, 63, 64, 65] as $n) {
            $this->judge($n, $this->id(4));
        }
        foreach ([71, 72, 73, 74, 75] as $n) {
            $this->judge($n, $this->id(2));
        }

        $audit = $this->createMock(AuditService::class);
        $audit->method('append')->willReturn((new AuditEntry)->forceFill(['seq' => 1]));
        $panels = new PanelService($this->cases, $audit);

        // Same-court appeal: opened at the solo court (id 4). Even classified
        // `moderate` (which alone sizes a 3-judge panel), the appeal panel is
        // the FULL court en banc — the no-parent ruling, not severity, governs.
        $appellant = $this->user(66);
        $original = $this->original(CourtCase::STATUS_SENTENCED, CourtCase::KIND_CIVIL, $this->id(4), [$this->plaintiff($appellant)]);
        $this->controller()->appeal($this->req($appellant, ['grounds' => 'Clear error.']), $original->fresh());
        $appeal = $this->appealOf($original->fresh());
        self::assertNotNull($appeal);
        $this->cases->accept($appeal, CourtCase::SEVERITY_MODERATE);
        $panel = $panels->assignPanel($appeal->fresh());
        self::assertTrue((bool) $panel->is_en_banc, 'A no-parent appeal is seated en banc.');
        self::assertSame(5, (int) $panel->size, 'The full seated court (5) hears the appeal, not a 3-judge sub-panel.');

        // Parent-court appeal: opened at the parent (id 2). Classified `moderate`
        // it is a normal 3-judge sub-panel — the en-banc override is scoped to
        // the same-court case only.
        $appellant2 = $this->user(76);
        $original2 = $this->original(CourtCase::STATUS_DECIDED, CourtCase::KIND_CIVIL, $this->id(3), [$this->plaintiff($appellant2)]);
        $this->controller()->appeal($this->req($appellant2, ['grounds' => 'Misread statute.']), $original2->fresh());
        $appeal2 = $this->appealOf($original2->fresh());
        self::assertNotNull($appeal2);
        self::assertSame($this->id(2), (string) $appeal2->judiciary_id, 'The parent court hears it.');
        $this->cases->accept($appeal2, CourtCase::SEVERITY_MODERATE);
        $panel2 = $panels->assignPanel($appeal2->fresh());
        self::assertFalse((bool) $panel2->is_en_banc, 'A parent-court appeal is a normal severity-scaled panel.');
        self::assertSame(3, (int) $panel2->size, 'Moderate severity seats a 3-judge sub-panel at the parent.');
    }

    public function test_a_non_party_is_refused_with_no_write(): void
    {
        $party = $this->user(31);
        $stranger = $this->user(32);
        $original = $this->original(CourtCase::STATUS_DECIDED, CourtCase::KIND_CIVIL, $this->id(3), [$this->plaintiff($party)]);

        $this->refused(fn () => $this->controller()->appeal($this->req($stranger, ['grounds' => 'I disagree.']), $original->fresh()));

        self::assertSame(CourtCase::STATUS_DECIDED, $original->fresh()->status, 'A refused appeal writes nothing.');
        self::assertNull($this->appealOf($original->fresh()), 'No appeal case is created.');
    }

    public function test_dismissed_closed_and_appealed_cases_refuse(): void
    {
        $appellant = $this->user(41);
        foreach ([CourtCase::STATUS_DISMISSED, CourtCase::STATUS_CLOSED, CourtCase::STATUS_APPEALED] as $status) {
            $original = $this->original($status, CourtCase::KIND_CIVIL, $this->id(3), [$this->plaintiff($appellant)]);
            $this->refused(fn () => $this->controller()->appeal($this->req($appellant, ['grounds' => 'Too late.']), $original->fresh()));
            self::assertNull($this->appealOf($original->fresh()), "A {$status} case yields no appeal.");
        }
    }

    public function test_grounds_are_required(): void
    {
        $appellant = $this->user(45);
        $original = $this->original(CourtCase::STATUS_DECIDED, CourtCase::KIND_CIVIL, $this->id(3), [$this->plaintiff($appellant)]);

        $this->refused(fn () => $this->controller()->appeal($this->req($appellant, ['grounds' => '']), $original->fresh()));
        self::assertSame(CourtCase::STATUS_DECIDED, $original->fresh()->status);
    }

    public function test_civil_appeal_records_reverse_and_preserves_the_original(): void
    {
        $appellant = $this->user(51);
        $judge = $this->judge(52, $this->id(2)); // seated on the appellate court
        $original = $this->original(CourtCase::STATUS_DECIDED, CourtCase::KIND_CIVIL, $this->id(3), [$this->plaintiff($appellant)]);

        $this->controller()->appeal($this->req($appellant, ['grounds' => 'The ruling misread the statute.']), $original->fresh());
        $appeal = $this->appealOf($original->fresh());

        $this->recordOutcome($judge, $appeal, 'reverse');

        self::assertSame(CourtCase::STATUS_CLOSED, $appeal->fresh()->status, 'Recording the outcome closes the appeal.');
        $opinion = Opinion::query()->where('case_id', (string) $appeal->id)->firstOrFail();
        self::assertSame('reverse', $opinion->appeal_outcome);
        // The ORIGINAL is untouched — still appealed, verdict intact.
        self::assertSame(CourtCase::STATUS_APPEALED, $original->fresh()->status);
        self::assertSame('liable', Verdict::query()->where('case_id', (string) $original->id)->firstOrFail()->outcome);
        // The effect is a public record on the original, not a verdict edit.
        self::assertTrue(
            PublicRecord::query()->where('subject_id', (string) $original->id)->where('title', 'like', 'Verdict reversed%')->exists(),
            'The reversal is recorded on the original as a public record.'
        );
    }

    public function test_criminal_appeal_vacate_keeps_double_jeopardy_and_bars_reprosecution(): void
    {
        $accused = $this->user(61);
        $judge = $this->judge(62, $this->id(2));
        $original = $this->original(CourtCase::STATUS_SENTENCED, CourtCase::KIND_CRIMINAL, $this->id(3), [$this->accused($accused)], dj: true);

        // The accused (a party) appeals.
        $this->controller()->appeal($this->req($accused, ['grounds' => 'The conviction rests on excluded evidence.']), $original->fresh());
        $appeal = $this->appealOf($original->fresh());
        self::assertSame(CourtCase::KIND_CRIMINAL, $appeal->kind);

        // Vacate is a lawful criminal appellate outcome.
        $this->recordOutcome($judge, $appeal, 'vacate');
        self::assertSame(CourtCase::STATUS_CLOSED, $appeal->fresh()->status);
        self::assertSame('vacate', Opinion::query()->where('case_id', (string) $appeal->id)->firstOrFail()->appeal_outcome);

        // The original stays double-jeopardy-locked; the acquittal is recorded.
        $original->refresh();
        self::assertTrue((bool) $original->double_jeopardy_locked, 'A vacate never lifts double jeopardy (Art. II §8).');
        self::assertTrue(
            PublicRecord::query()->where('subject_id', (string) $original->id)->where('title', 'like', 'Conviction vacated%')->exists()
        );

        // The re-prosecution bar still refuses a fresh criminal filing for the same accused.
        $this->refused(fn () => (new ConstitutionalValidator)->check('F-IND-017', [
            'kind' => 'criminal',
            'accused_user_id' => (string) $accused->getKey(),
            'judiciary_id' => $this->id(3),
        ]));
    }

    public function test_criminal_appeal_remand_or_reverse_is_refused(): void
    {
        $accused = $this->user(71);
        $judge = $this->judge(72, $this->id(2));

        foreach (['remand', 'reverse'] as $bad) {
            $original = $this->original(CourtCase::STATUS_SENTENCED, CourtCase::KIND_CRIMINAL, $this->id(3), [$this->accused($accused)], dj: true);
            $this->controller()->appeal($this->req($accused, ['grounds' => 'Error.']), $original->fresh());
            $appeal = $this->appealOf($original->fresh());

            $this->refused(fn () => $this->recordOutcome($judge, $appeal, $bad));
            self::assertNull(Opinion::query()->where('case_id', (string) $appeal->id)->first(), "A criminal {$bad} writes no opinion.");
        }
    }

    public function test_appeal_outcome_on_a_non_appeal_case_is_refused(): void
    {
        $judge = $this->judge(81, $this->id(3));
        // A first-instance decided case with a panel — an appeal_outcome must not attach.
        $case = $this->original(CourtCase::STATUS_DECIDED, CourtCase::KIND_CIVIL, $this->id(3), []);
        Panel::create(['id' => $this->id($this->nextId++), 'case_id' => (string) $case->id,
            'judiciary_id' => $this->id(3), 'size' => 3, 'is_en_banc' => false, 'status' => 'seated']);

        $this->refused(fn () => $this->controller()->opinion($this->req($judge, [
            'kind' => 'majority', 'title' => 'Opinion', 'body' => 'Body.', 'appeal_outcome' => 'affirm',
        ]), $case->fresh()));
        self::assertNull(Opinion::query()->where('case_id', (string) $case->id)->first());
    }

    public function test_appeals_list_is_bounded_to_twenty_and_ordered_by_id_desc(): void
    {
        $appellant = $this->user(91);
        $original = $this->original(CourtCase::STATUS_DECIDED, CourtCase::KIND_CIVIL, $this->id(3), [$this->plaintiff($appellant)]);

        // 22 linked appeal rows (directly, to exceed the bound).
        $ids = [];
        for ($i = 0; $i < 22; $i++) {
            $id = $this->id(20000 + $i);
            $ids[] = $id;
            CourtCase::create([
                'id' => $id, 'docket_no' => 'appeal-'.$i, 'judiciary_id' => $this->id(2),
                'jurisdiction_id' => $this->id(1), 'kind' => 'civil', 'title' => 'Appeal', 'filed_via_form' => 'F-IND-027',
                'status' => 'filed', 'appeal_of_case_id' => (string) $original->id,
            ]);
        }

        $method = new \ReflectionMethod(CaseController::class, 'appealRows');
        $method->setAccessible(true);
        $rows = $method->invoke($this->controller(), $original->fresh());

        self::assertCount(20, $rows, 'The appeals list is bounded to 20.');
        $returned = array_map(fn ($r) => $r['id'], $rows);
        $sorted = $returned;
        rsort($sorted);
        self::assertSame($sorted, $returned, 'The list is ordered by id descending.');
        self::assertSame(max($ids), $returned[0], 'The newest appeal id leads.');
    }

    public function test_controller_files_exactly_f_ind_027_through_the_engine(): void
    {
        $appellant = $this->user(95);
        $original = $this->original(CourtCase::STATUS_DECIDED, CourtCase::KIND_CIVIL, $this->id(3), [$this->plaintiff($appellant)]);

        $engine = $this->createMock(ConstitutionalEngine::class);
        $engine->expects($this->once())
            ->method('file')
            ->with(
                'F-IND-027',
                self::anything(),
                self::callback(fn ($p) => ($p['case_id'] ?? null) === (string) $original->id
                    && ($p['grounds'] ?? null) === 'Reversible error.'),
            )
            ->willReturn(new \App\Domain\Engine\EngineResult('F-IND-027', (new AuditEntry)->forceFill(['seq' => 1]), []));

        $this->controller($engine)->appeal($this->req($appellant, ['grounds' => 'Reversible error.', 'statement' => '']), $original->fresh());
    }
}
