<?php

namespace App\Http\Controllers\Elections;

use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\Candidacy;
use App\Models\ClockTimer;
use App\Models\Election;
use App\Models\ElectionCertification;
use App\Models\ElectionRace;
use App\Models\LegislatureDistrict;
use App\Models\User;
use App\Services\ElectionLifecycleService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-B2 — ElectionDetail (PHASE_B_DESIGN_frontend.md §B.1).
 *
 *   GET /elections                — jurisdiction-scoped resolver: redirect
 *                                   to the viewer's election, or render the
 *                                   CLK-01 empty state on the same page
 *   GET /elections/{election}     — the full §B.1 props contract
 *   GET /elections/{open-ballot|candidacy|ranked-ballot|results}
 *                                 — nav entry points (resolve → forward)
 *
 * Phase derivation is SERVER-SIDE and lives here (shared by the other
 * election controllers): the frozen scenario vocabulary
 * approval | ranked | certifying maps 1:1 onto ESM-03 statuses, so pages
 * never branch on raw status strings.
 *
 * Read-only: every state change on this surface posts to engine-backed
 * endpoints owned by other controllers (certify/recount → BoardConsole).
 */
class ElectionController extends Controller
{
    public function __construct(
        private readonly ElectionLifecycleService $lifecycle,
        private readonly SettingsResolver $settings,
        private readonly RoleService $roles,
    ) {
    }

    // =========================================================================
    // Shared phase vocabulary (used by Candidacy/Approval controllers too)
    // =========================================================================

    /**
     * ESM-03 status → the frozen page vocabulary (§B conventions):
     *   {scheduled, approval_open, finalist_cutoff} → 'approval'
     *   ranked_open                                 → 'ranked'
     *   everything else                             → 'certifying'
     * (scheduled maps to 'approval' so blocked/pre-open elections render the
     * approval-side chrome with every schedule row 'upcoming'.)
     */
    public static function phase(string $status): string
    {
        return match ($status) {
            Election::STATUS_SCHEDULED,
            Election::STATUS_APPROVAL_OPEN,
            Election::STATUS_FINALIST_CUTOFF => 'approval',
            Election::STATUS_RANKED_OPEN     => 'ranked',
            default                          => 'certifying',
        };
    }

    /** Sub-step inside the 'certifying' phase ('tabulating'|'certified'|'recount'|null). */
    public static function certSubStep(string $status): ?string
    {
        return match ($status) {
            Election::STATUS_VOTING_CLOSED,
            Election::STATUS_TABULATING  => 'tabulating',
            Election::STATUS_CERTIFIED,
            Election::STATUS_FINAL       => 'certified',
            Election::STATUS_AUDIT_RERUN => 'recount',
            default                      => null,
        };
    }

    /** ESM-03 happy-path machine for the StateStrip (PHP-owned — §B conventions). */
    public static function machine(): array
    {
        return [
            Election::STATUS_SCHEDULED,
            Election::STATUS_APPROVAL_OPEN,
            Election::STATUS_FINALIST_CUTOFF,
            Election::STATUS_RANKED_OPEN,
            Election::STATUS_VOTING_CLOSED,
            Election::STATUS_TABULATING,
            Election::STATUS_CERTIFIED,
            Election::STATUS_FINAL,
        ];
    }

    /** Current machine node for display (off-path statuses map to their anchor). */
    public static function machineNode(string $status): ?string
    {
        return match ($status) {
            Election::STATUS_AUDIT_RERUN => Election::STATUS_CERTIFIED,
            Election::STATUS_CANCELLED   => null,
            default                      => $status,
        };
    }

    /**
     * Human race label, shared with the candidacy/open-ballot pages:
     * district race → "District {n} · {member names}", at-large →
     * "At-large · {jurisdiction}" (+ seat-kind tag for bicameral type_b).
     */
    public static function raceLabel(ElectionRace $race): string
    {
        if ($race->district_id === null) {
            $name = $race->jurisdiction?->name ?? 'jurisdiction';
            $kind = $race->seat_kind === ElectionRace::SEAT_KIND_TYPE_B ? ' (type B)' : '';

            return "At-large{$kind} · {$name}";
        }

        $district = $race->relationLoaded('district')
            ? $race->district
            : LegislatureDistrict::query()->find($race->district_id);

        $number = $district?->district_number;

        $members = DB::table('legislature_district_jurisdictions as ldj')
            ->join('jurisdictions as j', 'j.id', '=', 'ldj.jurisdiction_id')
            ->where('ldj.district_id', $race->district_id)
            ->orderBy('j.name')
            ->limit(5)
            ->pluck('j.name');

        $suffix = $members->count() === 5 ? '…' : '';
        $label  = $number !== null ? "District {$number}" : 'District';

        return $members->isEmpty()
            ? $label
            : "{$label} · " . $members->implode(', ') . $suffix;
    }

    // =========================================================================
    // GET /elections — jurisdiction-scoped resolver / empty state
    // =========================================================================

    public function index(Request $request): Response|RedirectResponse
    {
        $election = $this->resolveViewerElection($request->user());

        if ($election !== null) {
            return redirect()->route('elections.show', $election->id);
        }

        return $this->renderEmptyState($request);
    }

    /** Nav entry points: resolve the viewer's election, forward to the target page. */
    public function entry(Request $request, string $target): Response|RedirectResponse
    {
        $election = $this->resolveViewerElection($request->user());

        if ($election === null) {
            return $this->renderEmptyState($request);
        }

        return match ($target) {
            'open-ballot'   => redirect()->route('elections.open-ballot', $election->id),
            'candidacy'     => redirect()->route('elections.candidacy.create', $election->id),
            'ranked-ballot' => redirect()->route('elections.ranked-ballot', $election->id),
            'results'       => redirect()->route('elections.results', $election->id),
            default         => redirect()->route('elections.show', $election->id),
        };
    }

    // =========================================================================
    // GET /elections/{election}
    // =========================================================================

    public function show(Request $request, string $election): Response
    {
        $model = Election::query()
            ->with(['jurisdiction', 'legislature', 'races.jurisdiction', 'races.district'])
            ->findOrFail($election);

        $user  = $request->user();
        $phase = self::phase($model->status);

        $races = $model->races
            ->sortBy(fn (ElectionRace $r) => [$r->seat_kind, $r->district?->district_number ?? PHP_INT_MAX])
            ->values();

        $candidateCounts = Candidacy::query()
            ->where('election_id', $model->id)
            ->whereNotNull('race_id')
            ->standing()
            ->selectRaw('race_id, COUNT(*) AS n')
            ->groupBy('race_id')
            ->pluck('n', 'race_id');

        $validatedCandidates = Candidacy::query()
            ->where('election_id', $model->id)
            ->whereIn('status', [
                Candidacy::STATUS_VALIDATED,
                Candidacy::STATUS_IN_POOL,
                Candidacy::STATUS_FINALIST,
                Candidacy::STATUS_NON_FINALIST,
                Candidacy::STATUS_ELECTED,
                Candidacy::STATUS_DEFEATED,
            ])
            ->count();

        $jid = (string) $model->jurisdiction_id;

        // F-ELB-001 scheduling-order provenance straight off the chain.
        $order = AuditEntry::query()
            ->where('module', 'elections')
            ->where('event', 'election.scheduled')
            ->where('payload->election_id', $model->id)
            ->orderBy('seq')
            ->first();

        $certRow = ElectionCertification::query()
            ->with('certifiedBy')
            ->where('election_id', $model->id)
            ->where('status', ElectionCertification::STATUS_CERTIFIED)
            ->orderByDesc('certified_at')
            ->first();

        $boardMember = $this->isBoardMember($user, $model);

        return Inertia::render('Elections/ElectionDetail', [
            'surface'  => SurfaceMeta::for('elections/detail'),
            'election' => [
                'id'             => (string) $model->id,
                'kind'           => $model->kind,
                'status'         => $model->status,
                'phase'          => $phase,
                'certSubStep'    => self::certSubStep($model->status),
                'legislature_id' => $model->legislature_id !== null ? (string) $model->legislature_id : null,
                'jurisdiction'   => [
                    'id'        => $jid,
                    'name'      => $model->jurisdiction?->name,
                    'adm_level' => (int) ($model->jurisdiction?->adm_level ?? 0),
                ],
                'schedule'           => $this->scheduleRows($model),
                'interval'           => [
                    'value'      => $this->settings->resolveInt($jid, 'election_interval_months', 60),
                    'unit'       => 'months',
                    'settingKey' => 'election_interval_months',
                    'citation'   => 'Art. II §2 · five-year default · CLK-01',
                ],
                'finalistMultiplier' => [
                    'value'      => max(1, $this->settings->resolveInt($jid, 'finalist_multiplier', 3)),
                    'settingKey' => 'finalist_multiplier',
                    'clock'      => 'CLK-21',
                ],
                'schedulingOrder'    => $order === null ? null : [
                    'issued_at'  => $order->occurred_at?->toIso8601String(),
                    'board_name' => $this->boardName($model),
                ],
            ],
            'machine'      => self::machine(),
            'currentState' => self::machineNode($model->status),
            'stats'        => [
                'seats'               => (int) $races->sum('seats'),
                'finalistPlaces'      => (int) $races->sum('finalist_count'),
                'validatedCandidates' => $validatedCandidates,
                'stage'               => $phase,
            ],
            'races' => $races->map(fn (ElectionRace $r) => [
                'id'              => (string) $r->id,
                'label'           => self::raceLabel($r),
                'seats'           => (int) $r->seats,
                'finalist_count'  => (int) $r->finalist_count,
                'candidate_count' => (int) ($candidateCounts[$r->id] ?? 0),
                'district_id'     => $r->district_id !== null ? (string) $r->district_id : null,
                'at_large'        => $r->district_id === null,
            ])->all(),
            'blockers'      => $this->blockers($model),
            'others'        => $this->otherElections($model),
            'can'           => [
                'certify' => $boardMember,
                'recount' => $boardMember,
            ],
            'certification' => $certRow === null ? null : [
                'certified_at' => $certRow->certified_at?->toIso8601String(),
                'by'           => $certRow->certifiedBy?->user?->display_name
                    ?? $certRow->certifiedBy?->user?->name
                    ?? 'bootstrap election board (system)',
            ],
        ]);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * The viewer's election: most recent open-cycle election whose
     * jurisdiction is in the viewer's active association chain. Falls back
     * to ANY open election (records are public — a pre-residency viewer
     * still gets the browse experience) before the empty state.
     */
    private function resolveViewerElection(?User $user): ?Election
    {
        $open = Election::query()
            ->whereNotIn('status', [Election::STATUS_FINAL, Election::STATUS_CANCELLED])
            ->orderByDesc('created_at');

        if ($user !== null) {
            $associated = (clone $open)
                ->whereIn('jurisdiction_id', function ($q) use ($user) {
                    $q->select('jurisdiction_id')
                        ->from('residency_confirmations')
                        ->where('user_id', (string) $user->getKey())
                        ->where('is_active', true);
                })
                // Deepest footprint first: a Serravalle resident's "my
                // election" is San Marino's, not Earth's.
                ->get()
                ->sortByDesc(fn (Election $e) => (int) ($e->jurisdiction?->adm_level ?? 0))
                ->first();

            if ($associated !== null) {
                return $associated;
            }
        }

        return $open->first();
    }

    private function renderEmptyState(Request $request): Response
    {
        // The armed CLK-01 cycle timer, when one exists (next general fire).
        $timer = ClockTimer::query()
            ->where('clock_id', 'CLK-01')
            ->where('state', 'armed')
            ->where('payload->step', 'schedule_general')
            ->orderBy('fires_at')
            ->first();

        $jid = null;
        if ($request->user() !== null) {
            $jid = DB::table('residency_confirmations')
                ->where('user_id', (string) $request->user()->getKey())
                ->where('is_active', true)
                ->orderByRaw('depth ASC NULLS LAST')
                ->value('jurisdiction_id');
        }

        return Inertia::render('Elections/ElectionDetail', [
            'surface'      => SurfaceMeta::for('elections/detail'),
            'election'     => null,
            'machine'      => self::machine(),
            'currentState' => null,
            'stats'        => null,
            'races'        => [],
            'blockers'     => [],
            'others'       => $this->otherElections(null),
            'can'          => ['certify' => false, 'recount' => false],
            'certification' => null,
            'empty'        => [
                'interval'   => $jid !== null
                    ? $this->settings->resolveInt((string) $jid, 'election_interval_months', 60)
                    : 60,
                'clk01DueAt' => $timer?->fires_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * The 5 server-computed schedule rows (§B.1). Blocked/scheduled
     * elections render every row 'upcoming'.
     *
     * @return list<array{stage: string, at: string|null, key: string, status: string}>
     */
    private function scheduleRows(Election $election): array
    {
        $ordinal = match ($election->status) {
            Election::STATUS_SCHEDULED       => 0,
            Election::STATUS_APPROVAL_OPEN   => 1,
            Election::STATUS_FINALIST_CUTOFF => 2,
            Election::STATUS_RANKED_OPEN     => 3,
            Election::STATUS_VOTING_CLOSED,
            Election::STATUS_TABULATING      => 5,
            default                          => 6, // certified / audit_rerun / final
        };

        $rows = [
            ['stage' => 'Approval phase opens — registration + open ballot', 'at' => $election->approval_opens_at,  'key' => 'CLK-18', 'ordinal' => 1],
            ['stage' => 'Finalist cutoff — top X locked, ballot frozen',     'at' => $election->finalist_cutoff_at, 'key' => 'CLK-21', 'ordinal' => 2],
            ['stage' => 'Ranked window opens — F-IND-007 ballots commit',    'at' => $election->ranked_opens_at,    'key' => 'CLK-01', 'ordinal' => 3],
            ['stage' => 'Ranked window closes',                              'at' => $election->ranked_closes_at,   'key' => 'CLK-01', 'ordinal' => 4],
            ['stage' => 'Tabulation & certification — winners seated',       'at' => $election->certified_at,       'key' => 'F-ELB-004', 'ordinal' => 5],
        ];

        return array_map(fn (array $row) => [
            'stage'  => $row['stage'],
            'at'     => $row['at']?->toIso8601String(),
            'key'    => $row['key'],
            'status' => $row['ordinal'] < $ordinal ? 'done' : ($row['ordinal'] === $ordinal ? 'current' : 'upcoming'),
        ], $rows);
    }

    /**
     * §B.4 blocked posture for the Art. II §8 banner: a scheduled election
     * with no races = subdivision (or an operator ruling) is pending.
     *
     * @return list<array{kind: string, detail: string}>
     */
    private function blockers(Election $election): array
    {
        if ($election->status !== Election::STATUS_SCHEDULED || $election->races->isNotEmpty()) {
            return [];
        }

        $legislature = $election->legislature;

        if ($legislature === null) {
            return [];
        }

        $plan = $this->lifecycle->racePlan($legislature);

        if (! $plan['blocked']) {
            return [];
        }

        return collect($plan['kinds'])
            ->filter(fn (array $spec) => $spec['mode'] === 'blocked')
            ->map(fn (array $spec, string $kind) => [
                'kind'   => 'subdivision_required',
                'detail' => "{$kind}: {$spec['reason']} · {$spec['citation']}",
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function otherElections(?Election $current): array
    {
        return Election::query()
            ->with(['jurisdiction', 'races'])
            ->whereNotIn('status', [Election::STATUS_CANCELLED])
            ->when($current !== null, fn ($q) => $q->whereKeyNot($current->id))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (Election $e) => [
                'election_id'       => (string) $e->id,
                'jurisdiction_name' => $e->jurisdiction?->name,
                'kind'              => $e->kind,
                'seats'             => (int) $e->races->sum('seats'),
                'finalist_count'    => (int) $e->races->sum('finalist_count'),
                'phase'             => self::phase($e->status),
            ])
            ->all();
    }

    /**
     * Board-membership policy for can.certify / can.recount: a seated
     * member of THIS election's board, or the operator while the board is
     * the bootstrap one (RoleService's system-as-board posture).
     */
    private function isBoardMember(?User $user, Election $election): bool
    {
        if ($user === null || ! in_array('R-08', $this->roles->rolesFor($user), true)) {
            return false;
        }

        $board = $election->election_board_id !== null
            ? $election->board
            : null;

        if ($board === null) {
            $board = \App\Models\ElectionBoard::query()
                ->where('jurisdiction_id', $election->jurisdiction_id)
                ->active()
                ->first();
        }

        if ($board === null) {
            return false;
        }

        $seated = $board->members()
            ->where('status', 'seated')
            ->where('user_id', (string) $user->getKey())
            ->exists();

        return $seated || ((bool) $user->is_operator && (bool) $board->is_bootstrap);
    }

    private function boardName(Election $election): string
    {
        $board = $election->board;
        $name  = $election->jurisdiction?->name ?? 'jurisdiction';

        return $board !== null && $board->is_bootstrap
            ? "{$name} bootstrap election board (system)"
            : "{$name} election board";
    }
}
