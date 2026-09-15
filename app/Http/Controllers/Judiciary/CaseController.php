<?php

namespace App\Http\Controllers\Judiciary;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Forms\Support\JudicialActor;
use App\Http\Controllers\Controller;
use App\Models\CaseFiling;
use App\Models\CaseParty;
use App\Models\CourtCase;
use App\Models\JudicialSeat;
use App\Models\Opinion;
use App\Models\PanelJudge;
use App\Models\User;
use App\Services\Judiciary\CaseService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-E3 — Judiciary/CaseDetail (PHASE_E_DESIGN_frontend.md §B.3; surface
 * judiciary/case-detail).
 *
 *   GET  /cases/{case} — the public case record: the Case-ESM StateStrip + the
 *        10-stage lifecycle (CaseLifecycle), the conflict-screened PanelTable,
 *        the motions/evidence dockets, the jury draw, and the verdict /
 *        sentencing / warrant / opinion stages.
 *   POST /cases/{case}/acceptance  — F-JDG-001 (accept + conflict-screened panel)
 *   POST /cases/{case}/jury-orders — F-JDG-002 (random jury draw, seed published)
 *   POST /cases/{case}/opinions    — F-JDG-003 (opinion; closes the case)
 *   POST /cases/{case}/sentencing  — F-JDG-009 (guilty-verdict sentencing order)
 *   POST /cases/{case}/warrants    — F-JDG-010 (Art. II §8 warrant)
 *
 * PUBLIC READ (Art. II §2 — proceedings are public record). Every per-stage
 * action gates by derived role (R-19/R-20 court orders) via `can.*` + the
 * engine 422 (JudicialActor::seat) — never a page 403. The court ADVANCES the
 * append-only record through the engine; no client toggle ever mutates it.
 *
 * The panel size + en-banc flag are ENGINE SNAPSHOTS read off the `panels`
 * row (PanelService / PanelSizing, CLK-16) — never recomputed from severity
 * here. The double-jeopardy flag is the persisted Art. II §8 fact off the
 * `cases` row.
 */
class CaseController extends Controller
{
    /** Case status → the 1-based lifecycle stage the live record rests at. */
    private const STATUS_STAGE = [
        CourtCase::STATUS_FILED => 1,
        CourtCase::STATUS_ACCEPTED => 2,
        CourtCase::STATUS_PANELED => 3,
        CourtCase::STATUS_JURY_EMPANELED => 6,
        CourtCase::STATUS_HEARD => 7,
        CourtCase::STATUS_DELIBERATION => 8,
        CourtCase::STATUS_DECIDED => 9,
        CourtCase::STATUS_SENTENCED => 9,
        CourtCase::STATUS_CLOSED => 10,
        CourtCase::STATUS_DISMISSED => 2,
        CourtCase::STATUS_APPEALED => 10,
    ];

    /** Case status → the Case-ESM state the StateStrip highlights (1-based stage). */
    private const STAGE_STATE = [
        CourtCase::STATUS_FILED,
        CourtCase::STATUS_ACCEPTED,
        CourtCase::STATUS_PANELED,
        CourtCase::STATUS_PANELED,
        CourtCase::STATUS_PANELED,
        CourtCase::STATUS_JURY_EMPANELED,
        CourtCase::STATUS_HEARD,
        CourtCase::STATUS_DELIBERATION,
        CourtCase::STATUS_DECIDED,
        CourtCase::STATUS_CLOSED,
    ];

    /** The 10 ordinal lifecycle stage titles (the case-detail track). */
    private function stages(): array
    {
        return [
            ['index' => 1, 'title' => __('Filing')],
            ['index' => 2, 'title' => __('Classification')],
            ['index' => 3, 'title' => __('Panel assignment')],
            ['index' => 4, 'title' => __('Initial hearing')],
            ['index' => 5, 'title' => __('Evidence docket')],
            ['index' => 6, 'title' => __('Jury selection')],
            ['index' => 7, 'title' => __('Arguments')],
            ['index' => 8, 'title' => __('Deliberation')],
            ['index' => 9, 'title' => __('Judgement')],
            ['index' => 10, 'title' => __('Opinion')],
        ];
    }

    private function severityDisplay(): array
    {
        return [
            CourtCase::SEVERITY_MINOR => __('Minor'),
            CourtCase::SEVERITY_MODERATE => __('Moderate'),
            CourtCase::SEVERITY_SERIOUS => __('Serious'),
            CourtCase::SEVERITY_CONSTITUTIONAL_MAJOR => __('Major constitutional question'),
        ];
    }

    private function kindDisplay(): array
    {
        return [
            CourtCase::KIND_CONSTITUTIONAL => __('Constitutional challenge'),
            CourtCase::KIND_CIVIL => __('Civil'),
            CourtCase::KIND_CRIMINAL => __('Criminal'),
            CourtCase::KIND_ADMINISTRATIVE => __('Administrative'),
        ];
    }

    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly CaseService $cases,
    ) {}

    // =========================================================================
    // GET /cases/{case}
    // =========================================================================

    public function show(Request $request, CourtCase $case): Response
    {
        $case->loadMissing([
            'judiciary.jurisdiction',
            'jurisdiction:id,name',
            'panel.judges.user:id,name,display_name',
            'panel.judges.seat:id,seat_number',
            'jury.eligibleJurisdiction:id,name',
            'verdict',
            'appealOf:id,docket_no,judiciary_id',
        ]);

        $isJudge = $this->isSeatedJudge($request->user(), $case);

        return Inertia::render('Judiciary/CaseDetail', [
            'surface' => SurfaceMeta::for('judiciary/case-detail'),
            'case' => $this->caseProps($case),
            'machine' => config('cga.state_machines.case', []),
            'stages' => $this->stages(),
            'stageStateMap' => self::STAGE_STATE,
            'panel' => $this->panelProps($case),
            'motions' => $this->filingRows($case, CaseFiling::KIND_MOTION),
            'evidence' => $this->filingRows($case, CaseFiling::KIND_EVIDENCE),
            'jury' => $this->juryProps($case),
            'can' => [
                // R-19/R-20: a seated judge of THIS court may advance the record.
                // The engine (JudicialActor::seat) is the boundary — this drives
                // the form's enabled state, never a page 403.
                'orderCourt' => $isJudge,
                // IO-2 — a party to a decided/sentenced case may appeal (the
                // engine re-asserts the party check + the ESM edge on POST).
                'appeal' => in_array($case->status, [CourtCase::STATUS_DECIDED, CourtCase::STATUS_SENTENCED], true)
                    && $this->isPartyTo($request->user(), $case),
                // A seated judge of an appeal case's court may record the
                // appellate outcome once the appeal case is decided/sentenced
                // (the F-JDG-003 opinion close gate).
                'record_appeal_outcome' => $isJudge && $case->isAppeal()
                    && in_array($case->status, [CourtCase::STATUS_DECIDED, CourtCase::STATUS_SENTENCED], true),
            ],
        ]);
    }

    // =========================================================================
    // POSTs (court actions — all R-19/R-20, all through the engine)
    // =========================================================================

    /** F-JDG-001 — accept + classify severity + seat the conflict-screened panel. */
    public function acceptance(Request $request, CourtCase $case): RedirectResponse
    {
        $this->engine->file('F-JDG-001', $request->user(), [
            'case_id' => (string) $case->id,
            'judiciary_id' => (string) $case->judiciary_id,
            'action' => (string) $request->input('action', 'accept'),
            'court_severity' => (string) $request->input('court_severity', ''),
            'jury_waived' => (bool) $request->input('jury_waived', false),
            'reason' => (string) $request->input('reason', ''),
        ]);

        return back()->with(
            'status',
            __('Case accepted — severity classified and the panel seated with conflict screening (F-JDG-001 · Art. IV §4). Recused judges are excluded and the draw re-runs.')
        );
    }

    /** F-JDG-002 — order the random jury draw; the seed publishes to the audit chain. */
    public function juryOrder(Request $request, CourtCase $case): RedirectResponse
    {
        $this->engine->file('F-JDG-002', $request->user(), [
            'case_id' => (string) $case->id,
            'judiciary_id' => (string) $case->judiciary_id,
            'seats' => $request->filled('seats') ? (int) $request->input('seats') : null,
            'alternates' => $request->filled('alternates') ? (int) $request->input('alternates') : null,
        ]);

        return back()->with(
            'status',
            __('Jury selection ordered — jurors drawn at random from the eligible pool; the selection seed is published to the audit chain (F-JDG-002 · Art. IV §4 · WF-JUD-04).')
        );
    }

    /**
     * F-JDG-003 — publish the opinion (commentary on the law); closes the case.
     * On an APPEAL case the opinion also carries the appellate outcome (IO-2):
     * civil affirm/reverse/remand, criminal affirm/vacate — never a re-trial
     * (Art. II §8). The engine refuses an appeal_outcome on a first-instance case.
     */
    public function opinion(Request $request, CourtCase $case): RedirectResponse
    {
        $this->engine->file('F-JDG-003', $request->user(), [
            'case_id' => (string) $case->id,
            'judiciary_id' => (string) $case->judiciary_id,
            'kind' => (string) $request->input('kind', 'majority'),
            'title' => (string) $request->input('title', ''),
            'body' => (string) $request->input('body', ''),
            'appeal_outcome' => (string) $request->input('appeal_outcome', ''),
        ]);

        return back()->with(
            'status',
            __('Opinion published to the public record — commentary on the law as written or edited; only the Art. IV §5 process can change a law\'s text (F-JDG-003 · Art. IV §4–§5).')
        );
    }

    /**
     * F-IND-027 — appeal a decided/sentenced judgement (IO-2). Files through
     * the engine: the actor must be a PARTY to the original (the handler
     * re-asserts it) and the original must be decided or sentenced. The appeal
     * is a NEW linked case at the parent judiciary (or the same court en banc);
     * the original moves to `appealed` untouched. A criminal appeal may only
     * affirm or vacate — double jeopardy is never disturbed (Art. II §8).
     */
    public function appeal(Request $request, CourtCase $case): RedirectResponse
    {
        $this->engine->file('F-IND-027', $request->user(), [
            'case_id' => (string) $case->id,
            'grounds' => (string) $request->input('grounds', ''),
            'statement' => (string) $request->input('statement', ''),
        ]);

        return back()->with(
            'status',
            __('Appeal filed — a new case opens at the appellate court and the original judgement rests as appealed; its verdict and opinion are preserved (F-IND-027 · Art. II §8).')
        );
    }

    /** F-JDG-009 — issue the sentencing order (requires a guilty criminal verdict). */
    public function sentencing(Request $request, CourtCase $case): RedirectResponse
    {
        $this->engine->file('F-JDG-009', $request->user(), [
            'case_id' => (string) $case->id,
            'judiciary_id' => (string) $case->judiciary_id,
            'terms' => (string) $request->input('terms', ''),
        ]);

        return back()->with(
            'status',
            __('Sentencing order issued on the guilty verdict — the outcome record carries the double-jeopardy flag (F-JDG-009 · Art. II §8).')
        );
    }

    /** F-JDG-010 — issue an arrest/search/seizure warrant (Art. II §8 facts). */
    public function warrant(Request $request, CourtCase $case): RedirectResponse
    {
        $this->engine->file('F-JDG-010', $request->user(), [
            'case_id' => (string) $case->id,
            'judiciary_id' => (string) $case->judiciary_id,
            'kind' => (string) $request->input('kind', ''),
            'stated_reason' => (string) $request->input('stated_reason', ''),
            'max_hold_duration_hours' => $request->filled('max_hold_duration_hours')
                ? (int) $request->input('max_hold_duration_hours')
                : null,
        ]);

        return back()->with(
            'status',
            __('Warrant issued with a stated reason and (for an arrest) a maximum hold duration — the two constitutional facts are mandatory (F-JDG-010 · Art. II §8).')
        );
    }

    /**
     * F-ADV-002/003/004 — an advocate (R-21) appends a motion / evidence /
     * brief to an existing case. The attach-window is enforced SERVER-side by
     * the handler (a brief after deliberation 422s); the form id rides in the
     * payload from the advocate composer.
     */
    public function filing(Request $request, CourtCase $case): RedirectResponse
    {
        $formId = (string) $request->input('form_id', '');

        if (! in_array($formId, ['F-ADV-002', 'F-ADV-003', 'F-ADV-004'], true)) {
            return back()->withErrors(['constitution' => __('A case filing is a motion (F-ADV-002), evidence (F-ADV-003), or a brief (F-ADV-004).')]);
        }

        $this->engine->file($formId, $request->user(), [
            'case_id' => (string) $case->id,
            'judiciary_id' => (string) $case->judiciary_id,
            'title' => (string) $request->input('title', ''),
            'body' => (string) $request->input('body', ''),
        ]);

        return back()->with('status', __('Filing added to the case docket under the attach-window (Art. IV §4).'));
    }

    /** F-JDG-011 — open arguments (paneled/jury_empaneled → heard). */
    public function hearing(Request $request, CourtCase $case): RedirectResponse
    {
        $this->engine->file('F-JDG-011', $request->user(), [
            'case_id' => (string) $case->id,
            'judiciary_id' => (string) $case->judiciary_id,
        ]);

        return back()->with('status', __('Hearing ordered — arguments are open on the record (F-JDG-011 · Art. IV §4).'));
    }

    /** F-JDG-012 — submit the case (heard → deliberation). */
    public function deliberation(Request $request, CourtCase $case): RedirectResponse
    {
        $this->engine->file('F-JDG-012', $request->user(), [
            'case_id' => (string) $case->id,
            'judiciary_id' => (string) $case->judiciary_id,
        ]);

        return back()->with('status', __('Case submitted to deliberation — the only unrecorded space; the verdict is recorded (F-JDG-012 · Art. IV §4).'));
    }

    /** F-JDG-013 — dismiss a case not justiciable or withdrawn (filed/accepted → dismissed). */
    public function dismissal(Request $request, CourtCase $case): RedirectResponse
    {
        $this->engine->file('F-JDG-013', $request->user(), [
            'case_id' => (string) $case->id,
            'judiciary_id' => (string) $case->judiciary_id,
            'reason' => (string) $request->input('reason', ''),
        ]);

        return back()->with('status', __('Case dismissed — the public record names the reason (F-JDG-013 · Art. IV §4).'));
    }

    /** F-JDG-014 — rule on a motion or evidence filing (an appended follow-up). */
    public function ruling(Request $request, CourtCase $case): RedirectResponse
    {
        $this->engine->file('F-JDG-014', $request->user(), [
            'case_id' => (string) $case->id,
            'judiciary_id' => (string) $case->judiciary_id,
            'filing_kind' => (string) $request->input('filing_kind', CaseFiling::KIND_MOTION),
            'ruling' => (string) $request->input('ruling', ''),
            'ruling_reason' => (string) $request->input('ruling_reason', ''),
            'title' => (string) $request->input('title', ''),
            'references_filing_id' => $request->input('references_filing_id'),
        ]);

        return back()->with('status', __('Ruling appended to the docket with its written reason (F-JDG-014 · Art. IV §4).'));
    }

    /**
     * The VERDICT — deliberation → decided. NOT a form (two design notes:
     * CaseService::recordVerdict and FormRegistry). A judge-only route: the
     * actor must hold a SEATED judicial seat on THIS court AND sit on THIS
     * case's panel. The panel/majority/unanimity rules live in
     * CaseService::assertVerdictRecordable (inside recordVerdict, unbypassable);
     * the case row is locked for the write. Double jeopardy is set exactly as
     * CaseService already does it (Art. II §8).
     */
    public function verdict(Request $request, CourtCase $case): RedirectResponse
    {
        // R-19/R-20 seated judge of THIS court (engine 422, never a page 403).
        $seat = JudicialActor::seat($request->user(), (string) $case->judiciary_id, 'court.verdict');
        // ... and seated on THIS case's panel (a court seat is not enough).
        $this->cases->assertActorOnPanel($case, $seat);

        $attrs = [
            'decided_by' => (string) $request->input('decided_by', ''),
            'outcome' => (string) $request->input('outcome', ''),
            'summary' => $request->filled('summary') ? (string) $request->input('summary') : null,
            'panel_vote_for' => $request->filled('panel_vote_for') ? (int) $request->input('panel_vote_for') : null,
            'panel_vote_against' => $request->filled('panel_vote_against') ? (int) $request->input('panel_vote_against') : null,
            'jury_unanimous' => $request->filled('jury_unanimous') ? $request->boolean('jury_unanimous') : null,
        ];

        DB::transaction(function () use ($case, $attrs): void {
            // Lock the case row for the write (DB-agnostic; sqlite ignores the
            // clause but the transaction still frames recordVerdict).
            CourtCase::query()->whereKey($case->getKey())->lockForUpdate()->first();
            $this->cases->recordVerdict($case->refresh(), $attrs);
        });

        return back()->with(
            'status',
            __('Verdict recorded — the outcome is on the public record (a criminal verdict locks double jeopardy · Art. II §8; Art. IV §4).')
        );
    }

    // =========================================================================
    // Props assembly
    // =========================================================================

    /** @return array<string, mixed> */
    private function caseProps(CourtCase $case): array
    {
        $courtName = $case->judiciary?->court_name
            ?? ($case->judiciary?->jurisdiction?->name !== null
                ? __(':name court', ['name' => $case->judiciary->jurisdiction->name])
                : __('court'));

        return [
            'id' => (string) $case->id,
            'judiciary_id' => (string) $case->judiciary_id,
            'docket_no' => $case->docket_no,
            'title' => $case->title,
            'kind' => $this->kindDisplay()[$case->kind] ?? ucfirst((string) $case->kind),
            'kind_raw' => $case->kind,
            'severity' => $this->severityLabel($case),
            'court' => ['name' => $courtName],
            'double_jeopardy' => (bool) $case->double_jeopardy_locked,
            'jury_entitled' => (bool) $case->jury_entitled,
            'current_stage' => self::STATUS_STAGE[$case->status] ?? 1,
            'current_state' => $case->status,
            'accusation' => $case->statement_of_claim,
            'filed_at' => $case->created_at?->toDateString(),
            'filed_by_label' => $case->filed_via_form !== null
                ? __('filed via :form', ['form' => $case->filed_via_form])
                : null,
            // IO-2 — appeal links (both directions) + the en-banc fact.
            'is_appeal' => $case->isAppeal(),
            // Heard by the same court en banc when the appeal court IS the
            // original's court (no parent judiciary existed).
            'en_banc' => $case->isAppeal()
                && $case->appealOf !== null
                && (string) $case->judiciary_id === (string) $case->appealOf->judiciary_id,
            'appeal_of' => $case->isAppeal() && $case->appealOf !== null ? [
                'id' => (string) $case->appealOf->id,
                'docket_number' => $case->appealOf->docket_no,
                'href' => "/cases/{$case->appealOf->id}",
            ] : null,
            'appeals' => $this->appealRows($case),
            // The lawful appellate outcomes for THIS case's kind (the appeal
            // opinion's select). Empty for a first-instance case.
            'appeal_outcomes' => $case->isAppeal()
                ? array_values(Opinion::APPEAL_OUTCOMES[$case->kind] ?? Opinion::APPEAL_OUTCOMES['civil'])
                : [],
        ];
    }

    /**
     * The appeal cases filed against THIS case (IO-2). Bounded to the latest 20
     * by id desc; each row carries the appeal case's status and its recorded
     * appellate outcome (the opinion's appeal_outcome, once ruled).
     *
     * @return list<array<string, mixed>>
     */
    private function appealRows(CourtCase $case): array
    {
        $appeals = $case->appeals()
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'docket_no', 'status', 'appeal_of_case_id']);

        if ($appeals->isEmpty()) {
            return [];
        }

        // The recorded appellate outcome lives on the appeal case's opinion.
        $outcomes = Opinion::query()
            ->whereIn('case_id', $appeals->pluck('id')->map('strval')->all())
            ->whereNotNull('appeal_outcome')
            ->orderByDesc('published_at')
            ->get(['case_id', 'appeal_outcome'])
            ->groupBy('case_id')
            ->map(fn ($rows) => (string) $rows->first()->appeal_outcome);

        return $appeals->map(fn (CourtCase $appeal) => [
            'id' => (string) $appeal->id,
            'docket_number' => $appeal->docket_no,
            'status' => $appeal->status,
            'outcome' => $outcomes[(string) $appeal->id] ?? null,
            'href' => "/cases/{$appeal->id}",
        ])->values()->all();
    }

    /**
     * PanelTable props — the conflict-screened bench. `panelSize`/`isFullCourt`
     * are ENGINE SNAPSHOTS off the `panels` row (size, is_en_banc), never
     * recomputed from severity. Null until the court accepts + panels the case.
     *
     * @return array<string, mixed>|null
     */
    private function panelProps(CourtCase $case): ?array
    {
        $panel = $case->panel;

        if ($panel === null) {
            return null;
        }

        $seats = $panel->judges
            ->sortByDesc(fn (PanelJudge $judge) => $judge->is_presiding)
            ->map(fn (PanelJudge $judge) => $this->panelSeatRow($judge))
            ->values()
            ->all();

        return [
            'seats' => $seats,
            'severity' => (string) $panel->severity_basis,
            // ENGINE snapshots — the CLK-16 hard constraint, read off the row.
            'panelSize' => (int) $panel->size,
            'isFullCourt' => (bool) $panel->is_en_banc,
            'rule' => $panel->is_en_banc
                ? __('Full court — all judges hear major constitutional questions · CLK-16 · Art. IV §4')
                : __('≥3, odd, severity-scaled · CLK-16 · Art. IV §4'),
        ];
    }

    /** One PanelTable seat row from a panel_judges row. */
    private function panelSeatRow(PanelJudge $judge): array
    {
        $recused = $judge->status === PanelJudge::STATUS_RECUSED
            || $judge->screening_result === PanelJudge::SCREENING_RECUSED;

        return [
            'judge' => [
                'name' => $judge->user?->display_name
                    ?: ($judge->user?->name ?? __('Judge')),
            ],
            'is_presiding' => (bool) $judge->is_presiding,
            'screening' => $recused ? 'recused' : 'no_conflicts',
            'screening_reason' => $judge->recusal_reason,
            'result' => $recused ? 'recused' : 'seated',
        ];
    }

    /**
     * Motions / evidence — the append-only docket rows of one kind, with the
     * judge's granted/denied/admitted/excluded ruling and the written reason.
     *
     * @return list<array<string, mixed>>
     */
    private function filingRows(CourtCase $case, string $kind): array
    {
        return CaseFiling::query()
            ->where('case_id', (string) $case->id)
            ->where('filing_kind', $kind)
            ->orderBy('seq')
            ->get()
            ->map(fn (CaseFiling $filing) => [
                'id' => (string) $filing->id,
                'title' => $filing->title ?? ucfirst($kind),
                'filed_by' => $filing->filed_by_role ?? '—',
                'ruling' => $filing->ruling,
                'ruling_reason' => $filing->ruling_reason,
            ])
            ->values()
            ->all();
    }

    /**
     * The jury draw — pool size + seats/alternates and the published seed's
     * audit-chain link (the draw is reproducible; anyone can verify it).
     *
     * @return array<string, mixed>|null
     */
    private function juryProps(CourtCase $case): ?array
    {
        $jury = $case->jury;

        if ($jury === null) {
            return null;
        }

        return [
            'drawn' => true,
            'jurors' => (int) $jury->seats,
            'alternates' => (int) $jury->alternates,
            'pool_size' => (int) $jury->pool_size,
            'pool_label' => __(':count eligible jurisdictionally associated residents of :jurisdiction', [
                'count' => number_format((int) $jury->pool_size),
                'jurisdiction' => $jury->eligibleJurisdiction?->name ?? __('the jurisdiction'),
            ]),
            'seed_audit_href' => '/audit-chain',
        ];
    }

    private function severityLabel(CourtCase $case): string
    {
        if ($case->court_severity !== null) {
            return $this->severityDisplay()[$case->court_severity] ?? ucfirst((string) $case->court_severity);
        }

        if ($case->claimed_severity !== null) {
            $label = $this->severityDisplay()[$case->claimed_severity] ?? ucfirst((string) $case->claimed_severity);

            return __(':label (claimed)', ['label' => $label]);
        }

        return __('Pending classification');
    }

    /**
     * Whether the viewer is a SEATED judge of this case's court (R-19/R-20).
     * Mirrors JudicialActor::seat — the engine re-asserts it on every POST.
     */
    private function isSeatedJudge(?User $user, CourtCase $case): bool
    {
        if ($user === null) {
            return false;
        }

        return JudicialSeat::query()
            ->where('judiciary_id', (string) $case->judiciary_id)
            ->where('user_id', (string) $user->getKey())
            ->where('status', JudicialSeat::STATUS_SEATED)
            ->exists();
    }

    /**
     * Whether the viewer is an active PARTY to the case (IO-2 appeal standing).
     * Mirrors the AppealFiling handler's check — the engine re-asserts it on
     * POST; this only drives the control's enabled state, never a page 403.
     */
    private function isPartyTo(?User $user, CourtCase $case): bool
    {
        if ($user === null) {
            return false;
        }

        return CaseParty::query()
            ->where('case_id', (string) $case->id)
            ->where('party_user_id', (string) $user->getKey())
            ->where('status', CaseParty::STATUS_ACTIVE)
            ->exists();
    }
}
