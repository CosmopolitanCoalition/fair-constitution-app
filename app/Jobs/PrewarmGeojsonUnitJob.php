<?php

namespace App\Jobs;

use App\Services\Maps\GeojsonPrewarmPlanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * PrewarmGeojsonUnitJob — ONE scope at ONE zoom for ONE legislature (WoS beta,
 * 2026-09-17). The boot prewarm was one worker that died at every boot; a
 * unit is the bounded piece. Every state change lands in the ledger, so
 * `geojson:prewarm --status` reads the truth even when a worker is killed
 * mid-unit (that unit shows as LOST after 15 minutes). The build is the
 * controllers' own map queries, untouched.
 *
 * No retries: the caches are rememberForever and idempotent; the next boot
 * plans again and skips what is warm through the caches themselves.
 */
class PrewarmGeojsonUnitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(
        public readonly string $legislatureId,
        public readonly string $scopeId,
        public readonly int $zoom,
    ) {
        $this->onQueue('prewarm');
    }

    public function handle(GeojsonPrewarmPlanner $planner): void
    {
        $key = GeojsonPrewarmPlanner::unitKey($this->legislatureId, $this->scopeId, $this->zoom);

        // The same worker limit the one-piece job carried (Earth-scope revealed
        // builds transiently brush PHP's default 128 MB).
        @ini_set('memory_limit', '768M');

        $planner->mark($key, 'started');
        $result = $planner->build($this->legislatureId, $this->scopeId, $this->zoom);

        if ($result['failed'] > 0) {
            $planner->mark($key, 'failed', ['messages' => array_slice($result['messages'], 0, 5)]);
            Log::warning('GeoJSON prewarm unit failed', ['unit' => $key, 'messages' => $result['messages']]);

            return;
        }

        $planner->mark($key, 'done', ['built' => $result['built']]);
    }
}
