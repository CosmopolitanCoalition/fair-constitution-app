<?php

namespace App\Jobs;

use App\Jobs\Clocks\EvaluateCriticalPopulationJob;
use App\Jobs\Clocks\EvaluatePetitionThresholdJob;
use App\Jobs\Clocks\EvaluateResidencyThresholdsJob;
use App\Models\ClockTimer;
use App\Services\ClockService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * WI-6 — the every-minute scheduler sweep (routes/console.php, run by the
 * `scheduler` container's `php artisan schedule:work`).
 *
 * Two passes:
 *  1. Deadline timers: armed clock_timers whose fires_at has passed →
 *     ClockService::fire (audit entry + mapped handler job each).
 *  2. Threshold clocks (CLK-05 residency, CLK-06 critical population):
 *     evaluated DIRECTLY every sweep — they watch quantities, not
 *     deadlines, so they need no armed timer to be live. Their inline
 *     event-driven path (e.g. recordPing flipping threshold_met) remains
 *     the cheap path; this sweep is the catch-up/safety net.
 *
 * Short job on the default Horizon queue (60 s timeout is ample: due
 * timers are bounded per sweep and the threshold jobs run separately).
 */
class EvaluateClocksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Per-sweep ceiling on deadline fires (backlog drains across sweeps). */
    private const MAX_FIRES_PER_SWEEP = 500;

    public function handle(ClockService $clocks): void
    {
        $due = ClockTimer::query()
            ->due()
            ->orderBy('fires_at')
            ->limit(self::MAX_FIRES_PER_SWEEP)
            ->get();

        foreach ($due as $timer) {
            $clocks->fire($timer);
        }

        EvaluateResidencyThresholdsJob::dispatch();
        EvaluateCriticalPopulationJob::dispatch();
        // CLK-17 (Phase C batch 2) — petition thresholds watch a quantity,
        // not a deadline; the sweep is the safety net behind the
        // event-driven signature-insert check.
        EvaluatePetitionThresholdJob::dispatch();
    }
}
