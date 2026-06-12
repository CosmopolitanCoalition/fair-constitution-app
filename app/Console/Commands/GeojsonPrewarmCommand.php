<?php

namespace App\Console\Commands;

use App\Http\Controllers\JurisdictionController;
use App\Http\Controllers\LegislatureController;
use App\Jobs\PrewarmGeojsonCachesJob;
use App\Models\Jurisdiction;
use App\Services\ConstitutionalDefaults;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pre-warm the boundary + revealed GeoJSON caches.
 *
 * Companion to `rasters:prewarm`. Where that command warms the WorldPop raster
 * TILE cache (disk), this one warms the Redis GeoJSON caches that both the
 * Jurisdiction Viewer and the District Mapper read:
 *
 *   - geojson.children / self / siblings.{id}.z{zoom}   (JurisdictionController)
 *   - geojson.revealed.{leg}.{scope}.{map}.z{zoom}      (LegislatureController)
 *
 * Those caches are now `rememberForever` (persist-until-invalidated), so a
 * single warm pass keeps them hot until an operator action invalidates the
 * relevant tag (district redraw → revealed flush; ETL/restore → boundary
 * flush). The expensive cold build — Earth-scope revealed is ~90 s because of
 * ST_Simplify over ~3.5 k giant sub-district members — is then paid once, here,
 * in the background, instead of by the first operator to open Earth view.
 *
 * Scope selection: the legislature root (Earth) plus every drillable GIANT
 * (fractional_seats ≥ giant_threshold AND has children). Those are exactly the
 * scopes the mapper can navigate to; warming all 951 k jurisdictions would be
 * pointless (the long tail is cheap on demand and rarely visited).
 *
 * Operator-view preemption: this runs on Horizon's `long-running` queue at the
 * supervisor's concurrency, while live tile/GeoJSON requests are served
 * synchronously by PHP-FPM and generate-on-miss immediately — so whatever the
 * operator is looking at is never blocked waiting on the background warm.
 *
 * Usage:
 *   php artisan geojson:prewarm                 # warm z=3-6 inline
 *   php artisan geojson:prewarm --zooms=3,4,5   # specific zooms
 *   php artisan geojson:prewarm --queue         # dispatch to Horizon, return
 */
class GeojsonPrewarmCommand extends Command
{
    protected $signature = 'geojson:prewarm
        {--zooms=3,4,5,6 : Comma-separated Leaflet zoom levels to warm}
        {--queue         : Dispatch as a Horizon-queued PrewarmGeojsonCachesJob and return; do not warm inline}';

    protected $description = 'Pre-build boundary + revealed GeoJSON caches for Earth and every giant scope so the mapper / viewer first-load instantly.';

    public function handle(): int
    {
        if ($this->option('queue')) {
            PrewarmGeojsonCachesJob::dispatch((string) $this->option('zooms'));
            $this->info('Dispatched PrewarmGeojsonCachesJob to Horizon (queue=long-running).');
            return self::SUCCESS;
        }

        $zooms = array_values(array_filter(
            array_map(fn ($z) => (int) trim($z), explode(',', (string) $this->option('zooms'))),
            fn ($z) => $z >= 0 && $z <= 18
        ));
        if (!$zooms) $zooms = [3, 4, 5, 6];

        $legislatures = DB::table('legislatures')->whereNull('deleted_at')->get();
        if ($legislatures->isEmpty()) {
            $this->warn('No legislatures present — nothing to warm.');
            return self::SUCCESS;
        }

        $jurisdictionCtl = app(JurisdictionController::class);
        $legislatureCtl  = app(LegislatureController::class);

        $boundary = 0;
        $revealed = 0;
        $failed   = 0;

        foreach ($legislatures as $leg) {
            $rootId  = $leg->jurisdiction_id;
            $rootPop = max((int) DB::table('jurisdictions')->where('id', $rootId)->value('population'), 1);
            $seats   = (int) $leg->type_a_seats;
            $thr     = ConstitutionalDefaults::giantThreshold($rootId);

            // Root + every drillable giant. EXISTS(children) keeps the list to
            // scopes the mapper actually opens.
            $giantRows = DB::select(
                "SELECT j.id
                 FROM jurisdictions j
                 WHERE j.deleted_at IS NULL
                   AND (CAST(j.population AS numeric) * :seats / :rootpop) >= :thr
                   AND EXISTS (
                       SELECT 1 FROM jurisdictions c
                       WHERE c.parent_id = j.id AND c.deleted_at IS NULL
                   )",
                ['seats' => $seats, 'rootpop' => $rootPop, 'thr' => $thr]
            );

            $scopeIds = array_values(array_unique(array_merge(
                [$rootId],
                array_map(fn ($r) => $r->id, $giantRows)
            )));

            $this->info(sprintf(
                'Legislature %s: %d scopes × %d zooms (%d boundary + revealed payloads each)…',
                $leg->id, count($scopeIds), count($zooms), 4
            ));

            foreach ($scopeIds as $sid) {
                $jur = Jurisdiction::find($sid);
                if (!$jur) continue;

                foreach ($zooms as $z) {
                    // Boundary geometry — viewer + mapper children layer. Pure
                    // geometry; the rememberForever cache is populated as a side
                    // effect of the controller call (we discard the response).
                    foreach (['childrenGeoJson', 'selfGeoJson', 'siblingsGeoJson'] as $method) {
                        try {
                            $jurisdictionCtl->{$method}(Request::create("/warm?zoom={$z}", 'GET'), $jur);
                            $boundary++;
                        } catch (\Throwable $e) {
                            $failed++;
                            $this->warn("  {$method} {$sid} z{$z}: {$e->getMessage()}");
                        }
                    }

                    // Revealed sub-district fills — mapper only. Heaviest at root.
                    try {
                        $legislatureCtl->revealedGeoJson(
                            Request::create("/warm?scope={$sid}&zoom={$z}", 'GET'),
                            $leg->id
                        );
                        $revealed++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->warn("  revealedGeoJson {$sid} z{$z}: {$e->getMessage()}");
                    }
                }
            }
        }

        $this->info("GeoJSON prewarm complete: {$boundary} boundary payloads, {$revealed} revealed payloads, {$failed} failures.");
        return self::SUCCESS;
    }
}
