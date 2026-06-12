<?php

namespace App\Jobs\Clocks;

use App\Services\ClockRederivationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * C-B2 (PHASE_C_DESIGN_votes_laws §C.5) — after a setting bill enacts,
 * re-derive the armed clock timers whose clocks resolve from the changed
 * setting key (CLK-01 ← election_interval_months, CLK-02 ←
 * max_days_between_meetings, …). Dispatched after-commit by
 * EnactmentService so it reads committed state. Re-derivation is
 * CANCEL + RE-ARM through ClockService's only write paths — armed timers
 * are never moved (the ElectionClockTest no-skip pin).
 *
 * CLK-03 timers are deliberately NEVER re-derived: an active emergency
 * power keeps its DECLARED duration — a lowered max binds only new
 * declarations/renewals (Art. II §7 reading: the declaration fixed the
 * duration at vote time). Enforced inside rederiveForSetting().
 */
class RederiveClockTimersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $settingKey,
        public readonly string $jurisdictionId,
    ) {
    }

    public function handle(ClockRederivationService $rederivation): void
    {
        $rederivation->rederiveForSetting($this->settingKey, $this->jurisdictionId);
    }
}
