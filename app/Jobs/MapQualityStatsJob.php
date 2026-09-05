<?php

namespace App\Jobs;

use App\Models\AutoscaleRun;
use App\Services\Autoscale\MapQualityStats;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Compute and cache a finished run's planet-wide map quality statistics
 * (operator order 2026-09-05). Queued by the pump's done flip so the flip
 * itself stays instant; the Type B contiguity walk takes minutes at planet
 * scale. Idempotent: a re-run overwrites the cache.
 */
class MapQualityStatsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public readonly string $runId)
    {
    }

    public function handle(MapQualityStats $stats): void
    {
        $run = AutoscaleRun::query()->find($this->runId);
        if ($run === null) {
            return;
        }
        $stats->refresh($run);
    }
}
