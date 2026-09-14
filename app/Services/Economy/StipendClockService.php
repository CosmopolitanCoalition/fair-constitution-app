<?php

namespace App\Services\Economy;

use App\Models\ClockTimer;
use App\Models\Jurisdiction;
use App\Services\ClockService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * W-0201 — the clock that drives the STANDALONE civic stipend.
 *
 * The stipend already runs inside the simulation (StipendStage). This arms a
 * real CLK-22 timer for the root so the stipend also runs on its own period
 * outside a sim, fired by the clock engine (EvaluateClocksJob →
 * ClockService::fire → RunCivicStipendJob) and re-armed each period. The
 * treasury page reads the next-run date from this timer, not a guess.
 *
 * The period resolves from stipend_period_days through ClockService (registry
 * default 30). CLK-22 carries a derive payload with unit 'days', so a
 * stipend_period_days change re-derives the armed timer through the existing
 * ClockRederivationService path — no second owner of the deadline.
 *
 * Root-scoped: ONE armed CLK-22 per world. Arming is idempotent (a world that
 * already carries an armed CLK-22 is left alone); reArm() cancels and arms a
 * fresh period, the path the fired handler uses.
 */
class StipendClockService
{
    public function __construct(private readonly ClockService $clocks)
    {
    }

    /** The root jurisdiction id, or null when the world has none yet. */
    public function rootId(): ?string
    {
        $id = Jurisdiction::query()
            ->whereNull('parent_id')
            ->whereNull('deleted_at')
            ->orderBy('adm_level')
            ->value('id');

        return $id !== null ? (string) $id : null;
    }

    /** The armed CLK-22 timer for the root, or null. */
    public function armedTimer(?string $rootId = null): ?ClockTimer
    {
        $rootId ??= $this->rootId();
        if ($rootId === null) {
            return null;
        }

        return ClockTimer::query()
            ->armed()
            ->where('clock_id', 'CLK-22')
            ->where('subject_type', 'jurisdiction')
            ->where('subject_id', $rootId)
            ->orderByDesc('armed_at')
            ->first();
    }

    /** The next stipend run instant from the real timer, or null. */
    public function nextRunAt(?string $rootId = null): ?CarbonImmutable
    {
        $timer = $this->armedTimer($rootId);

        return $timer?->fires_at !== null ? CarbonImmutable::parse($timer->fires_at) : null;
    }

    /**
     * Arm CLK-22 for the root if it is not already armed. Called on treasury
     * mint. A world with no root, or no period set (null and no registry
     * default), arms nothing.
     */
    public function armForRoot(?CarbonInterface $from = null): ?ClockTimer
    {
        $rootId = $this->rootId();
        if ($rootId === null) {
            return null;
        }

        if ($this->armedTimer($rootId) !== null) {
            return null; // already armed — idempotent
        }

        return $this->arm($rootId, $from);
    }

    /**
     * Cancel any armed CLK-22 for the root and arm a fresh period. The path
     * the fired handler uses to re-arm the next period.
     */
    public function reArm(?CarbonInterface $from = null): ?ClockTimer
    {
        $rootId = $this->rootId();
        if ($rootId === null) {
            return null;
        }

        foreach (ClockTimer::query()
            ->armed()
            ->where('clock_id', 'CLK-22')
            ->where('subject_type', 'jurisdiction')
            ->where('subject_id', $rootId)
            ->get() as $stale) {
            $this->clocks->cancel($stale, 'stipend period rolled — CLK-22 re-armed');
        }

        return $this->arm($rootId, $from);
    }

    /** The one arm site: fires_at = anchor + period days, derive-tagged. */
    private function arm(string $rootId, ?CarbonInterface $from): ?ClockTimer
    {
        // The registry row must exist first. A box that has not yet applied
        // the CLK-22 seed migration arms nothing rather than throwing —
        // self-healing once the migration lands.
        if (! \App\Models\Clock::query()->whereKey('CLK-22')->exists()) {
            return null;
        }

        $days = $this->clocks->resolvedInt('CLK-22', $rootId, 30);
        if ($days < 1) {
            return null; // no period, no clock
        }

        $anchor  = ($from !== null ? CarbonImmutable::parse($from) : CarbonImmutable::now())->startOfDay();
        $firesAt = $anchor->addDays($days);

        return $this->clocks->arm(
            'CLK-22',
            $rootId,
            'jurisdiction',
            $rootId,
            $firesAt,
            [
                'derive' => [
                    'anchor_at' => $anchor->toIso8601String(),
                    'unit'      => 'days',
                ],
            ],
        );
    }
}
