<?php

namespace App\Domain\Forms\Contracts;

use App\Models\Election;

/**
 * Seam between the F-ELB-001 ElectionSchedulingOrder handler and the
 * WI-B3 ElectionLifecycleService (race generation per design §B.4 +
 * clock wiring per design §B.1).
 *
 * The handler owns everything constitutional about a scheduling order:
 * window ordering, approval_min_days / ranked_window_days bounds, the
 * lockstep no-delay rule, the CLK-04 special-election window, the
 * `elections.race_structure` rule, and freezing X (= finalist_multiplier
 * × seats, CLK-21) into `election_races.finalist_count` for races passed
 * explicitly in the payload.
 *
 * What it delegates (WI-B3 owns the machinery):
 *
 *   generateRaces()   — derive races from the legislature's active
 *                       district map (one per district; at-large single
 *                       race when seats ≤ max with no map; auto-generate
 *                       the initial map for >9-seat chambers with
 *                       constituents — San Marino path). Returns the
 *                       race summaries for the audit payload, each
 *                       {race_id, district_id, seats, finalist_count}.
 *   armPhaseTimers()  — arm the CLK-18 / CLK-01 phase timers
 *                       (finalist_cutoff, ranked_open, ranked_close) via
 *                       ClockService so the scheduler drives ESM-03.
 *
 * WI-B4 binds NoopElectionSchedulingDelegate; the orchestrator rebinds
 * ElectionLifecycleService after WI-B3 merges (no handler change). Both
 * methods run inside the engine's DB transaction.
 */
interface ElectionSchedulingDelegate
{
    /**
     * Generate races for an election that has none and whose scheduling
     * order did not carry an explicit race list.
     *
     * @return list<array{race_id: string, district_id: string|null, seats: int, finalist_count: int}>
     */
    public function generateRaces(Election $election, array $payload): array;

    /**
     * Arm the ESM-03 phase timers for the (re)confirmed schedule.
     */
    public function armPhaseTimers(Election $election): void;
}
