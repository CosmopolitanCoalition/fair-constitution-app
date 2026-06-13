<?php

namespace App\Services\Organizations;

use App\Models\Board;
use App\Models\BoardSeat;
use App\Models\Organization;
use App\Models\OrgWorker;
use App\Services\AuditService;
use App\Services\ClockService;
use App\Services\SettingsResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ╔═══════════════════════════════════════════════════════════════════════╗
 * ║ PROTECTED FILE — CONSTITUTIONAL REVIEW REQUIRED BEFORE MODIFICATION   ║
 * ║                                                                       ║
 * ║ Art. III §6 — worker co-determination. This service is the hardened  ║
 * ║ Art. III §6 math, exactly as VoteCountingService is the Art. II      ║
 * ║ math: the FIRST worker-elected seat at the resolved minimum          ║
 * ║ (default 100 workers, CLK-13), LINEAR scaling to parity at the       ║
 * ║ resolved parity threshold (default 2,000, CLK-14), parity as the     ║
 * ║ CEILING. Applies IDENTICALLY to departments, CGCs, and private       ║
 * ║ enterprises — the formula takes (workers, ownerSeats) and nothing    ║
 * ║ else. Pinned exhaustively by tests/Constitutional/                   ║
 * ║ WorkerRepresentationTest.                                            ║
 * ╚═══════════════════════════════════════════════════════════════════════╝
 *
 * workerSeats()/nextStep() are pure, static, DB-free — the verbatim
 * contract of mockups/organizations/co-determination.html (worked case:
 * 1,450 workers, 7 governors → round(1350/1900×7) = round(4.97) = 5).
 *
 * recompute() is the single ORCHESTRATOR behind every headcount event
 * (RecomputeWorkerHeadcountJob — queued, never synchronous) and the
 * nightly EvaluateCoDeterminationJob sweep: counter caches, the
 * boards.worker_seats / worker_headcount snapshot (THIS SERVICE IS THE
 * ONLY WRITER of those columns — binding cross-designer contract), seat
 * reconciliation via OrgBoardService, and the CLK-13/CLK-14 registry
 * fires. Thresholds resolve per the employer's jurisdiction through
 * SettingsResolver at EVALUATION time — never frozen (clock-registry
 * rule).
 */
class CoDeterminationService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly SettingsResolver $settings,
        private readonly ClockService $clocks,
        private readonly OrgBoardService $boards,
    ) {
    }

    // =========================================================================
    // PURE Art. III §6 math (pinned by WorkerRepresentationTest)
    // =========================================================================

    /**
     * Required worker-elected seats for an active headcount.
     *
     *   min = resolved worker_rep_min_employees   (CLK-13, default 100)
     *   par = resolved worker_rep_parity_employees (CLK-14, default 2000)
     *
     *   f(w < min)  = 0
     *   f(min)      = 1                       (first seat — CLK-13)
     *   f(w)        = max(1, min(ownerSeats, round((w−min)/(par−min) × ownerSeats)))
     *   f(w ≥ par)  = ownerSeats              (parity — CLK-14, the CEILING)
     *
     * Rounding = half-up (PHP round() default), matching the mockup's
     * Math.round — pinned at the .5 boundaries so the two can never
     * diverge.
     */
    public static function workerSeats(int $workers, int $ownerSeats, int $min = 100, int $par = 2000): int
    {
        if ($ownerSeats < 1 || $workers < $min) {
            return 0;
        }

        if ($par <= $min) {
            // Degenerate thresholds (validator forbids min ≥ par; defensive).
            return $ownerSeats;
        }

        return max(1, min($ownerSeats, (int) round(($workers - $min) / ($par - $min) * $ownerSeats)));
    }

    /**
     * Smallest headcount at which the entitlement first exceeds $seats
     * (UI projection + the CLK next-step display). Null at parity.
     */
    public static function nextStep(int $seats, int $ownerSeats, int $min = 100, int $par = 2000): ?int
    {
        if ($ownerSeats < 1 || $seats >= $ownerSeats) {
            return null;
        }

        if ($seats < 1) {
            return $min;
        }

        return min($par, (int) ceil(($seats + 0.5) / $ownerSeats * ($par - $min) + $min));
    }

    // =========================================================================
    // Orchestration (the only writer of worker_seats / worker_headcount)
    // =========================================================================

    /**
     * Recompute one employer's co-determination state. Idempotent; safe
     * to re-run from the CLK-13/14 fire handler and the nightly sweep.
     *
     * @return array<string, mixed> audit snapshot
     */
    public function recompute(string $employerType, string $employerId): array
    {
        return DB::transaction(function () use ($employerType, $employerId) {
            $count = OrgWorker::query()
                ->forEmployer($employerType, $employerId)
                ->active()
                ->count();

            $jurisdictionId = $this->employerJurisdiction($employerType, $employerId);

            // Counter caches (organizations.worker_count / departments.worker_count).
            $this->writeCounterCache($employerType, $employerId, $count);

            $min = $jurisdictionId !== null
                ? $this->settings->resolveInt($jurisdictionId, 'worker_rep_min_employees', 100)
                : 100;
            $par = $jurisdictionId !== null
                ? $this->settings->resolveInt($jurisdictionId, 'worker_rep_parity_employees', 2000)
                : 2000;

            $board = Board::query()
                ->where('boardable_type', $employerType)
                ->where('boardable_id', $employerId)
                ->whereNull('deleted_at')
                ->where('status', '!=', Board::STATUS_DISSOLVED)
                ->lockForUpdate()
                ->first();

            $required           = 0;
            $provisionedBefore  = null;

            if ($board !== null) {
                $required          = self::workerSeats($count, (int) $board->owner_seats, $min, $par);
                $provisionedBefore = $board->seats()
                    ->where('seat_class', BoardSeat::CLASS_WORKER_ELECTED)
                    ->whereIn('status', [BoardSeat::STATUS_VACANT, BoardSeat::STATUS_NOMINATED, BoardSeat::STATUS_SEATED])
                    ->count();
            }

            // CLK-13/14 registry fires + watcher upkeep (BEFORE reconcile,
            // so "first seat" fires against the pre-reconcile posture).
            $this->evaluateClocks($employerType, $employerId, $jurisdictionId, $count, $min, $par, $provisionedBefore);

            $reconciliation = null;

            if ($board !== null) {
                // THE single write of the co-determination snapshot.
                $board->forceFill([
                    'worker_seats'     => $required,
                    'worker_headcount' => $count,
                ])->save();

                $reconciliation = $this->boards->reconcile($board, $required);
            }

            $snapshot = [
                'employer_type'      => $employerType,
                'employer_id'        => $employerId,
                'active_workers'     => $count,
                'resolved_min'       => $min,
                'resolved_parity'    => $par,
                'board_id'           => $board?->id !== null ? (string) $board->id : null,
                'owner_seats'        => $board !== null ? (int) $board->owner_seats : null,
                'required_worker_seats' => $board !== null ? $required : null,
                'reconciliation'     => $reconciliation,
            ];

            $this->audit->append(
                module: 'organizations',
                event: 'co_determination.recomputed',
                payload: $snapshot,
                ref: 'WF-ORG-04',
                jurisdictionId: $jurisdictionId,
            );

            return $snapshot;
        });
    }

    // =========================================================================
    // CLK-13 / CLK-14 (Art. III §6 thresholds — registry-visible fires)
    // =========================================================================

    /**
     * Arm the per-employer threshold watchers lazily (first org_workers
     * write — design §B.4). Idempotent.
     */
    public function armWatchers(string $employerType, string $employerId, ?string $jurisdictionId): void
    {
        foreach (['CLK-13', 'CLK-14'] as $clockId) {
            $armed = DB::table('clock_timers')
                ->where('clock_id', $clockId)
                ->where('subject_type', $employerType)
                ->where('subject_id', $employerId)
                ->where('state', 'armed')
                ->exists();

            if (! $armed) {
                $this->clocks->arm(
                    $clockId,
                    $jurisdictionId,
                    $employerType,
                    $employerId,
                    null, // threshold watch — no deadline
                    ['step' => 'co_determination'],
                );
            }
        }
    }

    private function evaluateClocks(
        string $employerType,
        string $employerId,
        ?string $jurisdictionId,
        int $count,
        int $min,
        int $par,
        ?int $provisionedWorkerSeats,
    ): void {
        // CLK-13 — fires ONCE when active headcount first crosses the
        // resolved minimum with zero worker seats provisioned (the
        // first-seat event, WF-ORG-04). Re-arms only after headcount
        // falls back below the minimum.
        $clk13 = $this->armedTimer('CLK-13', $employerType, $employerId);

        if ($clk13 !== null && $count >= $min && ($provisionedWorkerSeats ?? 0) === 0 && $provisionedWorkerSeats !== null) {
            $this->clocks->fire($clk13, [
                'active_workers' => $count,
                'resolved_min'   => $min,
            ]);
        } elseif ($clk13 === null && $count < $min) {
            $this->clocks->arm('CLK-13', $jurisdictionId, $employerType, $employerId, null, ['step' => 'co_determination']);
        }

        // CLK-14 — fires at the parity crossing; re-arms when headcount
        // falls below parity again.
        $clk14 = $this->armedTimer('CLK-14', $employerType, $employerId);

        if ($clk14 !== null && $count >= $par) {
            $this->clocks->fire($clk14, [
                'active_workers'  => $count,
                'resolved_parity' => $par,
            ]);
        } elseif ($clk14 === null && $count < $par) {
            $this->clocks->arm('CLK-14', $jurisdictionId, $employerType, $employerId, null, ['step' => 'co_determination']);
        }
    }

    private function armedTimer(string $clockId, string $subjectType, string $subjectId): ?\App\Models\ClockTimer
    {
        return \App\Models\ClockTimer::query()
            ->where('clock_id', $clockId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('state', \App\Models\ClockTimer::STATE_ARMED)
            ->first();
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function employerJurisdiction(string $employerType, string $employerId): ?string
    {
        if (! Schema::hasTable($employerType)) {
            return null;
        }

        $row = DB::table($employerType)->where('id', $employerId)->first(['jurisdiction_id']);

        return $row?->jurisdiction_id !== null ? (string) $row->jurisdiction_id : null;
    }

    private function writeCounterCache(string $employerType, string $employerId, int $count): void
    {
        if ($employerType === OrgWorker::EMPLOYER_ORGANIZATIONS) {
            Organization::query()->whereKey($employerId)->update(['worker_count' => $count]);

            return;
        }

        // departments.worker_count — exec designer's table; maintained by
        // the same job per the binding contract. Guarded until it lands.
        if (Schema::hasTable('departments') && Schema::hasColumn('departments', 'worker_count')) {
            DB::table('departments')->where('id', $employerId)->update(['worker_count' => $count]);
        }
    }
}
