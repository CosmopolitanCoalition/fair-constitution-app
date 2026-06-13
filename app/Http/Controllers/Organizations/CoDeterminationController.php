<?php

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\Department;
use App\Models\Election;
use App\Models\Organization;
use App\Models\SettingChange;
use App\Services\Organizations\CoDeterminationService;
use App\Services\SettingsResolver;
use App\Support\SurfaceMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-D7 — Co-determination scaling (PHASE_D_DESIGN_frontend.md §B.10;
 * surface organizations/co-determination) ← the CLK-13 exit surface.
 *
 *   GET /organizations/co-determination          — the explorer + the
 *        applies-equally table (every LIVE boards row, all three kinds).
 *   GET /organizations/co-determination?org={id} — binds the CoDetScale
 *        meter to one live org/department's published numbers.
 *
 * CONSTITUTIONAL POSTURE: every threshold/seat-count on this page is an
 * ENGINE SNAPSHOT read from a row — boards.worker_seats / .owner_seats /
 * .composition_valid are written ONLY by CoDeterminationService; the
 * minimum/parity thresholds resolve through SettingsResolver at request
 * time (CLK-13/14 are AMENDABLE — never the hardcoded 100/2000). The
 * controller computes nothing the engine owns: nextStepAt is the pure,
 * published projection (CoDeterminationService::nextStep), nothing more.
 *
 * Public read (the co-determination register, like every board, is a
 * public record — Art. III §6).
 */
class CoDeterminationController extends Controller
{
    public function __construct(private readonly SettingsResolver $settings) {}

    public function show(Request $request): Response
    {
        // The thresholds backing the explorer's default render resolve
        // against the root jurisdiction (the instance's amended floor /
        // parity); per-board rows resolve their OWN jurisdiction below.
        $rootJurisdictionId = $this->rootJurisdictionId();
        $explorerThresholds = $this->thresholds($rootJurisdictionId);

        $boards = $this->liveBoards();

        $focus = $this->focus($request->query('org'), $boards, $explorerThresholds);

        return Inertia::render('Organizations/CoDetermination', [
            'surface' => SurfaceMeta::for('organizations/co-determination'),
            'focus' => $focus,
            'appliesTable' => $boards->map(fn (Board $board) => $this->appliesRow($board))->values()->all(),
            'clk13' => $this->amendableCard(
                $rootJurisdictionId,
                'worker_rep_min_employees',
                $explorerThresholds['min'],
                100,
                'CLK-13 · Art. III §6',
                'must stay below the parity threshold',
            ),
            'clk14' => $this->amendableCard(
                $rootJurisdictionId,
                'worker_rep_parity_employees',
                $explorerThresholds['parity'],
                2000,
                'CLK-14 · Art. III §6',
                'must stay above the minimum threshold',
            ),
            'jointChairForm' => SurfaceMeta::for('organizations/co-determination')['forms'][0] ?? null,
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * Every LIVE board — one row per `boards` row that is not dissolved,
     * across all three boardable kinds (private orgs, CGCs, departments).
     * Eager-loads seats so the owner-side / worker-side counts and the
     * owner-side label come from real board_seats rows, not a recompute.
     */
    private function liveBoards(): \Illuminate\Support\Collection
    {
        return Board::query()
            ->whereNull('deleted_at')
            ->where('status', '!=', Board::STATUS_DISSOLVED)
            ->with(['seats' => fn ($q) => $q->orderBy('seat_no')])
            ->orderBy('boardable_type')
            ->get();
    }

    /**
     * The CoDetScale-bound focus: the org/department named by ?org, else
     * null (the page renders the generic explorer at the resolved
     * thresholds). The org may also be a board's boardable_id — we resolve
     * org first, then any department by the same id, so the meter binds to
     * whichever entity actually carries a board.
     */
    private function focus(?string $orgId, \Illuminate\Support\Collection $boards, array $explorerThresholds): ?array
    {
        if ($orgId === null || $orgId === '') {
            return null;
        }

        $org = Organization::query()->whereKey($orgId)->first();
        if ($org !== null) {
            return $this->focusFor(
                Board::BOARDABLE_ORGANIZATIONS,
                (string) $org->id,
                $org->name,
                '/organizations/'.$org->id,
                $org->is_cgc ? 'Common Good Corporation' : ($org->type ?? 'organization'),
                $boards,
            );
        }

        $department = Department::query()->whereKey($orgId)->first();
        if ($department !== null) {
            return $this->focusFor(
                Board::BOARDABLE_DEPARTMENTS,
                (string) $department->id,
                $department->name,
                '/departments/'.$department->id,
                'Executive department',
                $boards,
            );
        }

        return null;
    }

    private function focusFor(
        string $boardableType,
        string $boardableId,
        string $name,
        string $href,
        string $kind,
        \Illuminate\Support\Collection $boards,
    ): ?array {
        $board = $boards->first(
            fn (Board $b) => $b->boardable_type === $boardableType && (string) $b->boardable_id === $boardableId,
        );

        if ($board === null) {
            // The entity exists but has no board yet — no published
            // co-determination numbers to bind. The explorer renders the
            // generic default; the focus stays null.
            return null;
        }

        return [
            'entity' => ['name' => $name, 'href' => $href, 'kind' => $kind],
            'scale' => $this->scaleProps($board),
        ];
    }

    /**
     * CoDetScale props (§A.2) — ENGINE SNAPSHOTS from the board row plus
     * the per-board-jurisdiction thresholds. workerSeats is the engine's
     * stored number (boards.worker_seats); nextStepAt is the published
     * projection only.
     */
    private function scaleProps(Board $board): array
    {
        $thresholds = $this->thresholds($board->jurisdictionId());

        return [
            'workers' => (int) $board->worker_headcount,
            'ownerSeats' => (int) $board->owner_seats,
            'workerSeats' => (int) $board->worker_seats,
            'thresholds' => $thresholds,
            'nextStepAt' => CoDeterminationService::nextStep(
                (int) $board->worker_seats,
                (int) $board->owner_seats,
                $thresholds['min'],
                $thresholds['parity'],
            ),
        ];
    }

    /**
     * One applies-equally row — every owner-side / worker-side number is a
     * board snapshot; `state` derives from the SNAPSHOTTED worker_seats and
     * the resolved thresholds (display classification only, not the seat
     * math); composition_valid is the engine flag verbatim.
     */
    private function appliesRow(Board $board): array
    {
        $thresholds = $this->thresholds($board->jurisdictionId());

        [$name, $href, $kind] = $this->boardableMeta($board);

        $ownerSide = $board->seats->whereIn('seat_class', [BoardSeat::CLASS_GOVERNOR, BoardSeat::CLASS_OWNER_ELECTED]);
        $isAppointed = $ownerSide->isNotEmpty()
            ? $ownerSide->every(fn (BoardSeat $s) => $s->seat_class === BoardSeat::CLASS_GOVERNOR)
            : ($board->boardable_type === Board::BOARDABLE_DEPARTMENTS); // departments + CGCs run appointed governors

        $workers = (int) $board->worker_headcount;
        $workerSeats = (int) $board->worker_seats;

        return [
            'entity' => ['name' => $name, 'href' => $href],
            'kind' => $kind,
            'workers' => $workers,
            'owner_side' => [
                'seats' => (int) $board->owner_seats,
                'label' => $isAppointed ? 'appointed governors' : 'shareholder-elected',
            ],
            'worker_seats' => $workerSeats,
            'state' => $this->stateFor($workers, $workerSeats, $thresholds),
            'composition_valid' => (bool) $board->composition_valid,
            'election' => $this->workerTrackElection($board),
        ];
    }

    /**
     * State classification for the applies-table badge. Reads the
     * SNAPSHOTTED worker_seats vs the resolved thresholds — it never
     * recomputes the entitlement (that is the engine's; we render its
     * result). `below` / `scaling` / `parity` per the mockup logic.
     */
    private function stateFor(int $workers, int $workerSeats, array $thresholds): string
    {
        if ($workers < $thresholds['min']) {
            return 'below';
        }

        return $workers >= $thresholds['parity'] ? 'parity' : 'scaling';
    }

    /**
     * The worker-track election for this board, when one exists — the link
     * the CLK-13 flip surfaces ("worker-track election open"). Reads the
     * elections row by governed board + worker kind; null when none.
     */
    private function workerTrackElection(Board $board): ?array
    {
        $election = Election::query()
            ->where('board_id', $board->id)
            ->where('kind', Election::KIND_ORG_BOARD_WORKER)
            ->whereNull('deleted_at')
            ->whereNot('status', Election::STATUS_CANCELLED)
            ->orderByDesc('created_at')
            ->first();

        if ($election === null) {
            return null;
        }

        return [
            'status' => $election->status,
            'href' => '/elections/'.$election->id,
        ];
    }

    /** [name, href, kind-label] for a board's boardable. */
    private function boardableMeta(Board $board): array
    {
        if ($board->boardable_type === Board::BOARDABLE_DEPARTMENTS) {
            $department = Department::query()->whereKey($board->boardable_id)->first();

            return [
                $department?->name ?? 'Department',
                $department !== null ? '/departments/'.$department->id : null,
                'Executive department',
            ];
        }

        $org = Organization::query()->whereKey($board->boardable_id)->first();

        if ($org === null) {
            return ['Organization', null, 'Organization'];
        }

        $kind = $org->is_cgc
            ? 'Common Good Corporation'
            : ($org->structure === Organization::STRUCTURE_STOCK
                ? 'Private enterprise (stock)'
                : 'Private enterprise');

        return [$org->name, '/organizations/'.$org->id, $kind];
    }

    // -------------------------------------------------------------------------

    /**
     * Resolved { min, parity } for a jurisdiction (CLK-13 / CLK-14). Falls
     * back to the constitutional defaults when no row in the chain carries
     * a value, or when there is no jurisdiction (e.g. the explorer before a
     * planet row exists). NEVER hardcoded into the Vue.
     */
    private function thresholds(?string $jurisdictionId): array
    {
        if ($jurisdictionId === null) {
            return ['min' => 100, 'parity' => 2000];
        }

        return [
            'min' => $this->settings->resolveInt($jurisdictionId, 'worker_rep_min_employees', 100),
            'parity' => $this->settings->resolveInt($jurisdictionId, 'worker_rep_parity_employees', 2000),
        ];
    }

    /**
     * The CLK-13 / CLK-14 AmendableSetting card payload: live resolved
     * value + enacting-act provenance (the setting_changes ledger row that
     * last set it, anywhere in the chain) or null = "Template default ·
     * founding value". Mirrors SettingsController::register provenance.
     */
    private function amendableCard(
        ?string $jurisdictionId,
        string $settingKey,
        int $value,
        int $default,
        string $basis,
        string $boundsGloss,
    ): array {
        return [
            'value' => $value,
            'default' => $default,
            'basis' => $basis,
            'bounds_gloss' => $boundsGloss,
            'enacted_by' => $jurisdictionId !== null ? $this->enactingAct($jurisdictionId, $settingKey) : null,
        ];
    }

    /** The most-recent enacting act for a setting key in the chain, or null. */
    private function enactingAct(string $jurisdictionId, string $settingKey): ?array
    {
        $chainIds = $this->jurisdictionChainIds($jurisdictionId);

        if ($chainIds === []) {
            return null;
        }

        $change = SettingChange::query()
            ->whereIn('jurisdiction_id', $chainIds)
            ->where('setting_key', $settingKey)
            ->with('law:id,act_number,enacting_bill_id')
            ->orderByDesc('applied_at')
            ->first();

        if ($change === null || $change->law === null) {
            return null;
        }

        return [
            'act' => $change->law->act_number,
            'href' => $change->law->enacting_bill_id !== null
                ? '/bills/'.$change->law->enacting_bill_id
                : '/system/public-records',
        ];
    }

    /** @return list<string> self-first jurisdiction chain ids (planet-ward). */
    private function jurisdictionChainIds(string $jurisdictionId): array
    {
        $rows = DB::select(
            'WITH RECURSIVE chain AS (
                SELECT j.id, j.parent_id, 0 AS depth
                FROM jurisdictions j
                WHERE j.id = ? AND j.deleted_at IS NULL

                UNION ALL

                SELECT p.id, p.parent_id, c.depth + 1
                FROM chain c
                JOIN jurisdictions p ON p.id = c.parent_id AND p.deleted_at IS NULL
                WHERE c.depth < 32
            )
            SELECT c.id FROM chain c ORDER BY c.depth',
            [$jurisdictionId]
        );

        return array_map(static fn ($row) => (string) $row->id, $rows);
    }

    /** The planet root (adm_level 0), or null when no jurisdiction exists. */
    private function rootJurisdictionId(): ?string
    {
        $row = DB::table('jurisdictions')
            ->whereNull('deleted_at')
            ->where('adm_level', 0)
            ->orderBy('created_at')
            ->first(['id']);

        return $row?->id !== null ? (string) $row->id : null;
    }
}
