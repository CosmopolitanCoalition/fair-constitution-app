<?php

namespace App\Http\Controllers\Judiciary;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Models\CaseFiling;
use App\Models\CourtCase;
use App\Models\JudicialSeat;
use App\Models\PanelJudge;
use App\Models\User;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    private const STAGES = [
        ['index' => 1, 'title' => 'Filing'],
        ['index' => 2, 'title' => 'Classification'],
        ['index' => 3, 'title' => 'Panel assignment'],
        ['index' => 4, 'title' => 'Initial hearing'],
        ['index' => 5, 'title' => 'Evidence docket'],
        ['index' => 6, 'title' => 'Jury selection'],
        ['index' => 7, 'title' => 'Arguments'],
        ['index' => 8, 'title' => 'Deliberation'],
        ['index' => 9, 'title' => 'Judgement'],
        ['index' => 10, 'title' => 'Opinion'],
    ];

    private const SEVERITY_DISPLAY = [
        CourtCase::SEVERITY_MINOR => 'Minor',
        CourtCase::SEVERITY_MODERATE => 'Moderate',
        CourtCase::SEVERITY_SERIOUS => 'Serious',
        CourtCase::SEVERITY_CONSTITUTIONAL_MAJOR => 'Major constitutional question',
    ];

    private const KIND_DISPLAY = [
        CourtCase::KIND_CONSTITUTIONAL => 'Constitutional challenge',
        CourtCase::KIND_CIVIL => 'Civil',
        CourtCase::KIND_CRIMINAL => 'Criminal',
        CourtCase::KIND_ADMINISTRATIVE => 'Administrative',
    ];

    public function __construct(private readonly ConstitutionalEngine $engine) {}

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
        ]);

        $isJudge = $this->isSeatedJudge($request->user(), $case);

        return Inertia::render('Judiciary/CaseDetail', [
            'surface' => SurfaceMeta::for('judiciary/case-detail'),
            'case' => $this->caseProps($case),
            'machine' => config('cga.state_machines.case', []),
            'stages' => self::STAGES,
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
            'Case accepted — severity classified and the panel seated with conflict screening '
            .'(F-JDG-001 · Art. IV §4). Recused judges are excluded and the draw re-runs.'
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
            'Jury selection ordered — jurors drawn at random from the eligible pool; the selection '
            .'seed is published to the audit chain (F-JDG-002 · Art. IV §4 · WF-JUD-04).'
        );
    }

    /** F-JDG-003 — publish the opinion (commentary on the law); closes the case. */
    public function opinion(Request $request, CourtCase $case): RedirectResponse
    {
        $this->engine->file('F-JDG-003', $request->user(), [
            'case_id' => (string) $case->id,
            'judiciary_id' => (string) $case->judiciary_id,
            'kind' => (string) $request->input('kind', 'majority'),
            'title' => (string) $request->input('title', ''),
            'body' => (string) $request->input('body', ''),
        ]);

        return back()->with(
            'status',
            'Opinion published to the public record — commentary on the law as written or edited; '
            .'only the Art. IV §5 process can change a law\'s text (F-JDG-003 · Art. IV §4–§5).'
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
            'Sentencing order issued on the guilty verdict — the outcome record carries the '
            .'double-jeopardy flag (F-JDG-009 · Art. II §8).'
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
            'Warrant issued with a stated reason and (for an arrest) a maximum hold duration — '
            .'the two constitutional facts are mandatory (F-JDG-010 · Art. II §8).'
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
            return back()->withErrors(['constitution' => 'A case filing is a motion (F-ADV-002), evidence (F-ADV-003), or a brief (F-ADV-004).']);
        }

        $this->engine->file($formId, $request->user(), [
            'case_id' => (string) $case->id,
            'judiciary_id' => (string) $case->judiciary_id,
            'title' => (string) $request->input('title', ''),
            'body' => (string) $request->input('body', ''),
        ]);

        return back()->with('status', 'Filing added to the case docket under the attach-window (Art. IV §4).');
    }

    // =========================================================================
    // Props assembly
    // =========================================================================

    /** @return array<string, mixed> */
    private function caseProps(CourtCase $case): array
    {
        $courtName = $case->judiciary?->court_name
            ?? ($case->judiciary?->jurisdiction?->name !== null
                ? "{$case->judiciary->jurisdiction->name} court"
                : 'court');

        return [
            'id' => (string) $case->id,
            'judiciary_id' => (string) $case->judiciary_id,
            'docket_no' => $case->docket_no,
            'title' => $case->title,
            'kind' => self::KIND_DISPLAY[$case->kind] ?? ucfirst((string) $case->kind),
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
                ? "filed via {$case->filed_via_form}"
                : null,
        ];
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
                ? 'Full court — all judges hear major constitutional questions · CLK-16 · Art. IV §4'
                : '≥3, odd, severity-scaled · CLK-16 · Art. IV §4',
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
                    ?: ($judge->user?->name ?? 'Judge'),
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
            'pool_label' => number_format((int) $jury->pool_size).' eligible jurisdictionally '
                .'associated residents of '.($jury->eligibleJurisdiction?->name ?? 'the jurisdiction'),
            'seed_audit_href' => '/audit-chain',
        ];
    }

    private function severityLabel(CourtCase $case): string
    {
        if ($case->court_severity !== null) {
            return self::SEVERITY_DISPLAY[$case->court_severity] ?? ucfirst((string) $case->court_severity);
        }

        if ($case->claimed_severity !== null) {
            $label = self::SEVERITY_DISPLAY[$case->claimed_severity] ?? ucfirst((string) $case->claimed_severity);

            return "{$label} (claimed)";
        }

        return 'Pending classification';
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
}
