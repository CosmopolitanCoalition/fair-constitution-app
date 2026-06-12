<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Horizon-queued boundary + revealed GeoJSON pre-warm.
 *
 * Thin shim around the `geojson:prewarm` artisan command — the GeoJSON
 * counterpart to PrewarmRasterTilesJob. Dispatched on container start (after
 * migrations) and after a fresh ETL / restore, so Earth + every giant scope's
 * boundary and revealed payloads are hot in Redis before the first operator
 * opens the mapper. Earth-scope revealed alone is ~90 s cold, so paying it here
 * (background) instead of on a live request is the whole point.
 *
 * Runs on the `long-running` supervisor (no per-job timeout). No retries:
 * because the underlying caches are rememberForever and idempotent, a restart
 * just re-confirms what's already warm. Mirrors PrewarmRasterTilesJob's
 * contract exactly so the two warm jobs behave identically operationally.
 */
class PrewarmGeojsonCachesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 0;

    public function __construct(
        /** Comma-separated zoom levels, forwarded to --zooms. Null = command default. */
        public readonly ?string $zooms = null,
    ) {
        $this->onQueue('long-running');
    }

    public function handle(): void
    {
        // Earth-scope revealed payload is ~20 MB; building it transiently can
        // brush PHP's default 128 MB limit. Match PrewarmRasterTilesJob and
        // raise it for this worker only.
        @ini_set('memory_limit', '768M');

        $args = [];
        if ($this->zooms !== null && $this->zooms !== '') {
            $args['--zooms'] = $this->zooms;
        }

        Log::info('PrewarmGeojsonCachesJob starting', ['zooms' => $this->zooms]);

        $exit = Artisan::call('geojson:prewarm', $args);

        Log::info('PrewarmGeojsonCachesJob finished', ['exit_code' => $exit]);

        if ($exit !== 0) {
            throw new \RuntimeException("geojson:prewarm exited non-zero (code {$exit})");
        }
    }
}
