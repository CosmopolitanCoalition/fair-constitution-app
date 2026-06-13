<?php

namespace App\Http\Controllers\Judiciary;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Models\CourtCase;
use App\Models\Judiciary;
use App\Services\RoleService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-E3 — Judiciary/Docket (PHASE_E_DESIGN_frontend.md §B.2; surface
 * judiciary/case-docket).
 *
 *   GET  /judiciaries/{judiciary}/docket — the public case docket for ONE
 *        court: open-case stats by kind, the filterable case list (each case
 *        links to case-detail, or constitutional-challenge for an Art. IV §5
 *        case), the F-IND-017 filing composer (claimed scale + claimed
 *        severity), and the reference cards for the other entry points
 *        (F-IND-016, F-ADV-001) and what the court does next (F-JDG-001).
 *   POST /judiciaries/{judiciary}/cases — F-IND-017 (engine): a civil /
 *        criminal / administrative filing. The court classifies justiciability
 *        and severity at acceptance (F-JDG-001) — the claimed values are
 *        inputs, never the panel size.
 *
 * PUBLIC READ (Art. II §2 — Judicial proceedings are public record). The
 * docket renders for any authenticated resident; filing gates by derived
 * role (R-03 / R-21) via `can.fileCase` + the engine 422 — never a page 403
 * (the CandidacyRegistration posture). Every panel summary / severity here is
 * a row snapshot; the court owns the classification arithmetic, this
 * controller reads rows and opens the filing door.
 */
class DocketController extends Controller
{
    /** kind value → the display label + the surface a case of that kind links to. */
    private const KIND_DISPLAY = [
        CourtCase::KIND_CONSTITUTIONAL => 'Constitutional challenge',
        CourtCase::KIND_CIVIL => 'Civil',
        CourtCase::KIND_CRIMINAL => 'Criminal',
        CourtCase::KIND_ADMINISTRATIVE => 'Administrative',
    ];

    /** court_severity (or claimed_severity) value → display label. */
    private const SEVERITY_DISPLAY = [
        CourtCase::SEVERITY_MINOR => 'Minor',
        CourtCase::SEVERITY_MODERATE => 'Moderate',
        CourtCase::SEVERITY_SERIOUS => 'Serious',
        CourtCase::SEVERITY_CONSTITUTIONAL_MAJOR => 'Major constitutional question',
    ];

    /** adm_level → the natural level label (mirrors Jurisdiction::adm_label). */
    private const ADM_LABELS = [
        0 => 'World',
        1 => 'Country / Territory',
        2 => 'State / Province / Region',
        3 => 'County / District',
        4 => 'Municipality / City',
        5 => 'Borough / Township',
        6 => 'Neighbourhood / Ward',
    ];

    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly RoleService $roles,
    ) {}

    // =========================================================================
    // GET /judiciaries/{judiciary}/docket
    // =========================================================================

    public function index(Request $request, Judiciary $judiciary): Response
    {
        $judiciary->loadMissing('jurisdiction');

        $cases = CourtCase::query()
            ->where('judiciary_id', $judiciary->id)
            ->with(['panel', 'jurisdiction:id,name'])
            ->orderByDesc('created_at')
            ->get();

        $associations = $request->user() !== null ? $this->roles->associationsFor($request->user()) : [];
        $isAssociated = $associations !== [];

        return Inertia::render('Judiciary/CaseDocket', [
            'surface' => SurfaceMeta::for('judiciary/case-docket'),
            'judiciary' => $this->judiciaryHeader($judiciary),
            'stats' => $this->stats($cases),
            'cases' => $this->caseRows($cases),
            'machine' => config('cga.state_machines.case', []),
            'filters' => [
                'kinds' => array_values(self::KIND_DISPLAY),
            ],
            'filingForm' => [
                'kinds' => [
                    ['value' => CourtCase::KIND_CIVIL, 'label' => 'Civil'],
                    ['value' => CourtCase::KIND_CRIMINAL, 'label' => 'Criminal complaint'],
                    ['value' => CourtCase::KIND_ADMINISTRATIVE, 'label' => 'Administrative'],
                ],
                'scales' => $this->scaleOptions($associations, $judiciary),
                'severities' => [
                    ['value' => CourtCase::SEVERITY_MINOR, 'label' => 'Minor'],
                    ['value' => CourtCase::SEVERITY_MODERATE, 'label' => 'Moderate'],
                    ['value' => CourtCase::SEVERITY_SERIOUS, 'label' => 'Serious'],
                ],
            ],
            'isAssociated' => $isAssociated,
            'can' => [
                // Filing is association-only (Art. I). The engine is the
                // boundary — this only decides whether the form renders enabled
                // or whether the residency CTA shows instead.
                'fileCase' => $request->user() !== null && $isAssociated,
            ],
        ]);
    }

    // =========================================================================
    // POST /judiciaries/{judiciary}/cases — F-IND-017
    // =========================================================================

    /**
     * F-IND-017 — open a civil/criminal/administrative case. The court
     * classifies justiciability + severity at acceptance (F-JDG-001); the
     * claimed values ride only as the filer's claim. A ConstitutionalViolation
     * (e.g. the double-jeopardy bar, or a court that is not yet hearing cases)
     * surfaces as errors.constitution through the global handler.
     */
    public function store(Request $request, Judiciary $judiciary): RedirectResponse
    {
        $scaleId = (string) $request->input('jurisdiction_id', (string) $judiciary->jurisdiction_id);

        // The same docket endpoint serves the resident's own filing (F-IND-017)
        // and an advocate opening a case ON BEHALF OF a client (F-ADV-001, R-21).
        if ((string) $request->input('form_id') === 'F-ADV-001') {
            $client = trim((string) $request->input('client', ''));
            $clientId = \App\Models\User::query()
                ->where(fn ($q) => $q->whereRaw('lower(name) = ?', [mb_strtolower($client)])->orWhere('email', $client))
                ->value('id');

            if ($clientId === null) {
                return back()->withErrors([
                    'constitution' => "F-ADV-001 names the client by registered name or email — \"{$client}\" did not resolve to a resident.",
                ]);
            }

            $this->engine->file('F-ADV-001', $request->user(), [
                'judiciary_id' => (string) $judiciary->id,
                'jurisdiction_id' => $scaleId,
                'filed_on_behalf_of_user_id' => (string) $clientId,
                'kind' => (string) $request->input('kind', 'civil'),
                'title' => (string) $request->input('title', ''),
                'statement_of_claim' => (string) $request->input('statement_of_claim', ''),
                'claimed_severity' => (string) $request->input('claimed_severity', ''),
            ]);

            return back()->with(
                'status',
                'Case filed on behalf of your client — a docket number is assigned (F-ADV-001 · Art. IV §4).'
            );
        }

        $this->engine->file('F-IND-017', $request->user(), [
            'judiciary_id' => (string) $judiciary->id,
            'jurisdiction_id' => $scaleId,
            'kind' => (string) $request->input('kind', ''),
            'title' => (string) $request->input('title', ''),
            'statement_of_claim' => (string) $request->input('statement_of_claim', ''),
            'claimed_severity' => (string) $request->input('claimed_severity', ''),
        ]);

        return back()->with(
            'status',
            'Filing accepted for review — a docket number is assigned. The court will classify '
            .'justiciability and severity, then assign a panel with conflict screening '
            .'(F-IND-017 → F-JDG-001 · Art. IV §4).'
        );
    }

    // =========================================================================
    // Props assembly
    // =========================================================================

    /** @return array<string, mixed> */
    private function judiciaryHeader(Judiciary $judiciary): array
    {
        $jurisdiction = $judiciary->jurisdiction;

        return [
            'id' => (string) $judiciary->id,
            'name' => $judiciary->court_name
                ?? ($jurisdiction !== null ? "{$jurisdiction->name} judiciary" : 'Judiciary'),
            'jurisdiction' => $jurisdiction !== null ? [
                'id' => (string) $jurisdiction->id,
                'name' => $jurisdiction->name,
                'href' => '/jurisdictions/'.($jurisdiction->slug ?? $jurisdiction->id),
            ] : null,
            'home_href' => "/judiciaries/{$judiciary->id}",
            'challenges_href' => "/judiciaries/{$judiciary->id}/challenges",
        ];
    }

    /**
     * Open-case counts overall and by kind (row counts, never recomputed
     * downstream). An "open" case is any non-terminal case on this docket.
     *
     * @param  \Illuminate\Support\Collection<int, CourtCase>  $cases
     * @return array{open: int, by_kind: array<string, int>}
     */
    private function stats($cases): array
    {
        $open = $cases->whereNotIn('status', CourtCase::TERMINAL_STATUSES);

        return [
            'open' => $open->count(),
            'by_kind' => [
                'constitutional' => $open->where('kind', CourtCase::KIND_CONSTITUTIONAL)->count(),
                'civil' => $open->where('kind', CourtCase::KIND_CIVIL)->count(),
                'criminal' => $open->where('kind', CourtCase::KIND_CRIMINAL)->count(),
                'administrative' => $open->where('kind', CourtCase::KIND_ADMINISTRATIVE)->count(),
            ],
        ];
    }

    /**
     * The docket rows. Each case links to its detail surface (case-detail, or
     * constitutional-challenge for an Art. IV §5 case). The panel summary is
     * read off the seated `panels` row — engine snapshot, never derived from
     * severity here.
     *
     * @param  \Illuminate\Support\Collection<int, CourtCase>  $cases
     * @return list<array<string, mixed>>
     */
    private function caseRows($cases): array
    {
        $courtName = fn (CourtCase $case) => $case->jurisdiction?->name !== null
            ? "{$case->jurisdiction->name} court"
            : 'court';

        return $cases->map(function (CourtCase $case) use ($courtName) {
            $isConstitutional = $case->kind === CourtCase::KIND_CONSTITUTIONAL;

            return [
                'id' => (string) $case->id,
                'docket_no' => $case->docket_no,
                'title' => $case->title,
                'kind' => self::KIND_DISPLAY[$case->kind] ?? ucfirst((string) $case->kind),
                'court' => ['name' => $courtName($case)],
                'panel' => ['summary' => $this->panelSummary($case)],
                'severity' => $this->severityLabel($case),
                'state' => $case->status,
                'filed_via' => $case->filed_via_form,
                'double_jeopardy_note' => $case->kind === CourtCase::KIND_CRIMINAL
                    ? ($case->double_jeopardy_locked
                        ? 'double-jeopardy flag locked · Art. II §8'
                        : 'criminal — will carry the double-jeopardy flag · Art. II §8')
                    : null,
                // Art. IV §5 cases route to the challenge tracker; everything
                // else to the case-detail lifecycle.
                'href' => $isConstitutional
                    ? "/judiciaries/{$case->judiciary_id}/challenges"
                    : "/cases/{$case->id}",
            ];
        })->values()->all();
    }

    /**
     * The panel column — the seated bench snapshot, or "Pending acceptance"
     * before the court accepts + panels the case. Never computed from severity.
     */
    private function panelSummary(CourtCase $case): string
    {
        $panel = $case->panel;

        if ($panel === null) {
            return 'Pending acceptance (F-JDG-001)';
        }

        if ($panel->is_en_banc) {
            return 'Full court';
        }

        $base = sprintf('%d judges', (int) $panel->size);

        return $case->jury_entitled ? "{$base} + jury" : $base;
    }

    /** Severity label — the court's classification once accepted, else the claim. */
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
     * The claimed-scale options: the viewer's jurisdiction chain with natural
     * level labels (the jurisdiction whose law the case arises under, so the
     * right court level hears it). Falls back to this court's own jurisdiction
     * for an unassociated/anonymous reader.
     *
     * @param  list<array{id: string, name: string, adm_level: int}>  $associations
     * @return list<array{value: string, label: string}>
     */
    private function scaleOptions(array $associations, Judiciary $judiciary): array
    {
        if ($associations === [] && $judiciary->jurisdiction !== null) {
            $j = $judiciary->jurisdiction;

            return [[
                'value' => (string) $j->id,
                'label' => $j->name.' ('.($this->admLabel((int) $j->adm_level)).')',
            ]];
        }

        return array_map(fn (array $a) => [
            'value' => (string) $a['id'],
            'label' => $a['name'].' ('.$this->admLabel((int) $a['adm_level']).')',
        ], $associations);
    }

    private function admLabel(int $level): string
    {
        return self::ADM_LABELS[$level] ?? "Jurisdiction (Level {$level})";
    }
}
