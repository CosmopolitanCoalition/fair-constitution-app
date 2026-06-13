<?php

namespace App\Http\Controllers\Judiciary;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Models\Advocate;
use App\Models\CaseFiling;
use App\Models\CourtCase;
use App\Models\Judiciary;
use App\Models\User;
use App\Support\SurfaceMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-E4 — Advocate console (PHASE_E_DESIGN_frontend.md §B.5; surface
 * judiciary/advocate-console).
 *
 *   GET /judiciary/advocate — the per-viewer advocate dashboard: the
 *       F-IND-015 registration card (R-21 bar entry), the viewer's own cases
 *       filed via F-ADV-001, the F-ADV-001..004 composer (case filing /
 *       motion / evidence / brief), and the append-only recent-filings log.
 *
 * Per-viewer surface — NOT court-scoped (the resolver binds /judiciary/advocate
 * to the R-21 viewer; like a personal dashboard, mirroring how
 * ExecutiveResolverController routes /executive/reporting to the holder).
 *
 * PUBLIC-READ posture (Art. II §2): the four-instrument explainer + the
 * registration form render for ANY associated resident — registration is open
 * to any R-03 (Art. I; F-IND-015). The viewer's own case list + filings are
 * the viewer's own R-21 record. Actions gate by role via `can.*` + engine 422,
 * never a page 403 — every POST runs through ConstitutionalEngine::file (the
 * global handler renders ConstitutionalViolation as errors.constitution).
 */
class AdvocateController extends Controller
{
    /** F-ADV form → the human label + the per-type composer hint (mockup verbatim). */
    private const FILING_TYPES = [
        'F-ADV-001' => [
            'label' => 'Case filing — on behalf of client (F-ADV-001)',
            'hint' => 'Your client retains you; the retainer is recorded with the filing.',
        ],
        'F-ADV-002' => [
            'label' => 'Motion filing (F-ADV-002)',
            'hint' => 'Motions are ruled on with written reasons, on the public record.',
        ],
        'F-ADV-003' => [
            'label' => 'Evidence submission (F-ADV-003)',
            'hint' => 'Evidence attaches to the case’s open docket.',
        ],
        'F-ADV-004' => [
            'label' => 'Brief / argument filing (F-ADV-004)',
            'hint' => 'Briefs are accepted until deliberation begins.',
        ],
    ];

    /** case.status → the human display state + the NEXT-ACTION line (mockup verbatim). */
    private const STATE_LABELS = [
        CourtCase::STATUS_FILED => ['Filed', 'neutral', 'Awaiting acceptance and panel assignment (F-JDG-001)'],
        CourtCase::STATUS_ACCEPTED => ['Accepted', 'info', 'Accepted — panel assignment with conflict screening is next (F-JDG-001)'],
        CourtCase::STATUS_PANELED => ['Paneled', 'info', 'Panel seated — motions and evidence accepted (F-ADV-002 / F-ADV-003)'],
        CourtCase::STATUS_JURY_EMPANELED => ['Jury selection', 'info', 'Voir dire under way — challenge motions only (F-ADV-002)'],
        CourtCase::STATUS_HEARD => ['Evidence docket', 'info', 'Evidence docket open — submissions accepted (F-ADV-003)'],
        CourtCase::STATUS_DELIBERATION => ['Deliberation', 'neutral', 'In deliberation — no filings accepted; await judgement'],
        CourtCase::STATUS_DECIDED => ['Decided', 'success', 'Judgement entered — opinions and sentencing follow (court action)'],
        CourtCase::STATUS_SENTENCED => ['Sentenced', 'success', 'Sentenced — the order is on the public record'],
        CourtCase::STATUS_CLOSED => ['Closed', 'neutral', 'Closed — no further filings'],
        CourtCase::STATUS_DISMISSED => ['Dismissed', 'neutral', 'Dismissed — no further filings'],
        CourtCase::STATUS_APPEALED => ['Appealed', 'warning', 'Appealed — re-enters the lifecycle at a wider panel'],
    ];

    public function __construct(private readonly ConstitutionalEngine $engine) {}

    /**
     * F-IND-015 — register with a court as an advocate. The bar is open to any
     * associated resident (R-03; Art. I); the engine re-checks association and
     * names the court (judiciary_id).
     */
    public function register(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->engine->file('F-IND-015', $request->user(), [
            'judiciary_id' => (string) $request->input('judiciary_id', ''),
            'qualifications_note' => (string) $request->input('qualifications_note', ''),
        ]);

        return back()->with(
            'status',
            'Registered as an advocate — your R-21 bar record is on file (F-IND-015 · Art. IV §4).'
        );
    }

    public function show(Request $request): Response
    {
        $user = $request->user();

        $advocate = $user !== null ? $this->viewerAdvocate($user) : null;

        return Inertia::render('Judiciary/AdvocateConsole', [
            'surface' => SurfaceMeta::for('judiciary/advocate-console'),
            'advocate' => $this->advocateProps($advocate),
            'myCases' => $advocate !== null ? $this->myCaseRows($advocate) : [],
            'filings' => $advocate !== null ? $this->filingRows($advocate) : [],
            'composer' => $this->composerProps($advocate),
            'registerTargetId' => $advocate === null && $user !== null
                ? $this->publicRegistrationJudiciaryId($user)
                : null,
            'can' => [
                'register' => $user !== null && $advocate === null,
                'file' => $advocate !== null,
                'isRegistered' => $advocate !== null,
            ],
        ]);
    }

    // -------------------------------------------------------------------------

    /** The viewer's single registered advocate row (the deepest/first one), or null. */
    private function viewerAdvocate(User $user): ?Advocate
    {
        return Advocate::query()
            ->with('judiciary:id,court_name,jurisdiction_id')
            ->where('user_id', (string) $user->getKey())
            ->where('status', Advocate::STATUS_REGISTERED)
            ->whereNull('deleted_at')
            ->orderByDesc('registered_at')
            ->first();
    }

    /** @return array<string, mixed>|null */
    private function advocateProps(?Advocate $advocate): ?array
    {
        if ($advocate === null) {
            return null;
        }

        $courtName = $advocate->judiciary?->court_name;

        return [
            'is_registered' => true,
            'persona' => ['name' => $this->displayName($advocate->user)],
            'granted_at' => $advocate->registered_at?->toIso8601String(),
            'judiciary' => [
                'id' => (string) $advocate->judiciary_id,
                'name' => $courtName ?? 'this court',
                'href' => "/judiciaries/{$advocate->judiciary_id}",
            ],
            'practice_scope' => $courtName !== null
                ? "every court of {$courtName} and its constituent counties"
                : 'every court of this judiciary and its constituent counties',
        ];
    }

    /**
     * The viewer's cases — those they filed on behalf of a client (F-ADV-001),
     * newest first. Panel size + en-banc are ENGINE snapshots read off the
     * panel row, never recomputed.
     *
     * @return list<array<string, mixed>>
     */
    private function myCaseRows(Advocate $advocate): array
    {
        return CourtCase::query()
            ->with(['judiciary:id,court_name', 'panel:id,case_id,size,is_en_banc,status'])
            ->where('advocate_id', (string) $advocate->id)
            ->where('filed_via_form', 'F-ADV-001')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (CourtCase $case) {
                [$stateLabel, $stateTone, $nextAction] = self::STATE_LABELS[$case->status]
                    ?? [ucfirst((string) $case->status), 'neutral', 'Awaiting court action'];

                return [
                    'id' => (string) $case->id,
                    'docket_no' => $case->docket_no,
                    'title' => $case->title,
                    'kind' => ucfirst((string) $case->kind),
                    'court' => $case->judiciary?->court_name ?? '—',
                    'panel' => $this->panelSummary($case),
                    'state' => $stateLabel,
                    'state_tone' => $stateTone,
                    'next_action' => $nextAction,
                    'href' => "/cases/{$case->id}",
                ];
            })
            ->values()
            ->all();
    }

    /** A human panel summary read off the ENGINE-owned panel row (never computed here). */
    private function panelSummary(CourtCase $case): string
    {
        $panel = $case->panel;

        if ($panel === null) {
            return 'Pending acceptance';
        }

        if ($panel->is_en_banc) {
            return "Full court — all {$panel->size} judges";
        }

        $base = "{$panel->size} judges";

        return $case->jury_entitled ? "{$base} + jury" : $base;
    }

    /**
     * The viewer's own docketed filings (append-only), newest first — the
     * recent-filings LogRow list. case_filings is append-only; we render the
     * record verbatim.
     *
     * @return list<array<string, mixed>>
     */
    private function filingRows(Advocate $advocate): array
    {
        return CaseFiling::query()
            ->with('case:id,title,docket_no')
            ->where('advocate_id', (string) $advocate->id)
            ->orderByDesc('seq')
            ->limit(50)
            ->get()
            ->map(fn (CaseFiling $filing) => [
                'seq' => (int) $filing->seq,
                'form' => $filing->filing_form,
                'kind' => $filing->filing_kind,
                'case' => $filing->case !== null
                    ? ['id' => (string) $filing->case->id, 'title' => $filing->case->title, 'href' => "/cases/{$filing->case->id}"]
                    : null,
                'text' => $filing->title ?? $filing->body ?? $filing->filing_kind,
                'when' => $filing->created_at?->toIso8601String(),
                'status' => 'docketed',
            ])
            ->values()
            ->all();
    }

    /**
     * Composer options: the four filing types (with per-type hints) and the
     * viewer's own cases (the F-ADV-002/003/004 target set).
     *
     * @return array<string, mixed>
     */
    private function composerProps(?Advocate $advocate): array
    {
        $types = [];
        foreach (self::FILING_TYPES as $id => $meta) {
            $types[] = ['id' => $id, 'label' => $meta['label'], 'hint' => $meta['hint']];
        }

        $cases = $advocate === null ? [] : CourtCase::query()
            ->where('advocate_id', (string) $advocate->id)
            ->where('filed_via_form', 'F-ADV-001')
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'docket_no'])
            ->map(fn (CourtCase $case) => [
                'id' => (string) $case->id,
                'title' => $case->title,
                'label' => "{$case->title} ({$case->docket_no})",
            ])
            ->values()
            ->all();

        return [
            'types' => $types,
            'casesForClient' => $cases,
        ];
    }

    /**
     * For an unregistered viewer: the judiciary they would register with — the
     * deepest associated jurisdiction's judiciary (public read; the same
     * orderByDesc(adm_level) pattern the resolver uses). The F-IND-015 form
     * needs a judiciary_id; the engine re-checks association (Art. I).
     */
    private function publicRegistrationJudiciaryId(User $user): ?string
    {
        $id = DB::table('residency_confirmations as rc')
            ->join('judiciaries as j', 'j.jurisdiction_id', '=', 'rc.jurisdiction_id')
            ->join('jurisdictions as ju', 'ju.id', '=', 'rc.jurisdiction_id')
            ->where('rc.user_id', (string) $user->getKey())
            ->where('rc.is_active', true)
            ->whereNull('j.deleted_at')
            ->whereNot('j.status', Judiciary::STATUS_DISSOLVED)
            ->whereNull('ju.deleted_at')
            ->orderByDesc('ju.adm_level')
            ->value('j.id');

        return $id !== null ? (string) $id : null;
    }

    private function displayName(?User $user): string
    {
        if ($user === null) {
            return 'you';
        }

        return $user->display_name ?? $user->name ?? 'you';
    }
}
