<?php

namespace App\Jobs\Setup;

use App\Http\Controllers\SetupController;
use App\Services\Setup\SetupProgressRollup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Warm one setup progress snapshot off the request thread (G3).
 *
 * Dispatched on a COLD MISS by a poll handler so the whole-world scan happens
 * on the queue, never inside the poll request. The scheduled refresher keeps
 * the copies warm while a viewer is present; this only covers the first poll
 * after a restart or cache flush, so the page shows real numbers within a few
 * seconds instead of blocking on a planet scan.
 *
 * 'step4' | 'summary' | 'jurisdictions' recompute through SetupProgressRollup.
 * 'autoscale' recomputes the Step 3 dashboard snapshot through the existing
 * viewer-gated refresher (SetupController::refreshProgressSnapshot).
 */
class WarmSetupRollupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $kind)
    {
    }

    public function handle(SetupProgressRollup $rollups): void
    {
        if ($this->kind === 'autoscale') {
            app(SetupController::class)->refreshProgressSnapshot();

            return;
        }

        if (in_array($this->kind, SetupProgressRollup::KINDS, true)) {
            $rollups->refresh($this->kind);
        }
    }
}
