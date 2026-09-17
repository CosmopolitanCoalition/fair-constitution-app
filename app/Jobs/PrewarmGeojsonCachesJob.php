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
 * Horizon-queued boundary + revealed GeoJSON pre-warm: THE PLANNER.
 *
 * Dispatched on container start (after migrations) and after a fresh ETL /
 * restore. It no longer builds anything itself (WoS beta, 2026-09-17: the
 * single worker that built every payload was a 1.2 GB process, OOM-killed at
 * every boot, silently). It plans the units (legislature x scope x zoom),
 * records the ledger, and dispatches one PrewarmGeojsonUnitJob per unit on
 * the prewarm lane, root scope first. Each unit reports to the ledger, so
 * `geojson:prewarm --status` can name a lost unit.
 *
 * Runs on the `prewarm` supervisor. No retries: the plan is idempotent (the
 * caches are rememberForever), so a restart just re-plans.
 */
class PrewarmGeojsonCachesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(
        /** Comma-separated zoom levels, forwarded to the plan. Null = 3,4,5,6. */
        public readonly ?string $zooms = null,
        /** Legislature ids to plan for; null = the root legislatures (the mapper's first screen). */
        public readonly ?array $legislatureIds = null,
    ) {
        // Low-priority prewarm lane — see PrewarmRasterTilesJob: boot-time
        // warms must never occupy the single interactive long-running slot.
        $this->onQueue('prewarm');
    }

    public function handle(GeojsonPrewarmPlanner $planner): void
    {
        // YIELD TO A LIVE STEP 4 RUN (operator order 2026-09-06): the boot-time
        // cache prewarm is memory-heavy and competes with the provisioning
        // lanes for the box. While a Step 4 run is live it defers; a later
        // dispatch warms the caches once the run is done.
        if (\App\Models\ProvisionRun::query()->whereIn('status', ['queued', 'running', 'halted'])->exists()) {
            Log::info('Prewarm deferred: a Step 4 provision run is live.');

            return;
        }

        $zooms = self::parseZooms($this->zooms);
        $units = $planner->plan($zooms, $this->legislatureIds);
        $planner->recordPlan($units);

        foreach ($units as $u) {
            PrewarmGeojsonUnitJob::dispatch($u['leg'], $u['scope'], $u['zoom']);
        }

        Log::info('PrewarmGeojsonCachesJob planned', ['units' => count($units), 'zooms' => $zooms]);
    }

    /** @return list<int> */
    public static function parseZooms(?string $zooms): array
    {
        $list = array_values(array_filter(
            array_map(static fn ($z) => (int) trim($z), explode(',', (string) ($zooms ?? ''))),
            static fn ($z) => $z >= 0 && $z <= 18
        ));

        return $list === [] ? [3, 4, 5, 6] : $list;
    }
}
