<?php

namespace App\Console\Commands;

use App\Jobs\PrewarmGeojsonCachesJob;
use Illuminate\Console\Command;
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
        {--legislature=* : Legislature id(s) to warm; default = the root legislatures (Earth on a planet box)}
        {--queue         : Dispatch as a Horizon-queued PrewarmGeojsonCachesJob and return; do not warm inline}
        {--unless-busy   : Do nothing while any engine run is active (serving-profile boot; operator ruling 2026-09-17)}
        {--status        : Print the prewarm ledger: done, failed and LOST units}';

    protected $description = 'Pre-build boundary + revealed GeoJSON caches for Earth and every giant scope so the mapper / viewer first-load instantly.';

    public function handle(): int
    {
        $planner = app(\App\Services\Maps\GeojsonPrewarmPlanner::class);

        if ($this->option('status')) {
            return $this->printStatus($planner);
        }

        // Serving-profile boot (operator ruling 2026-09-17): skipped while any
        // run is active, since the prewarm shares the run's Horizon cap.
        if ($this->option('unless-busy') && ($busy = \App\Support\RunsInFlight::any()) !== null) {
            $this->info("Prewarm skipped: a {$busy} run is active (--unless-busy).");
            return self::SUCCESS;
        }

        $legIds = array_values(array_filter((array) $this->option('legislature'))) ?: null;

        if ($this->option('queue')) {
            PrewarmGeojsonCachesJob::dispatch((string) $this->option('zooms'), $legIds);
            $this->info('Dispatched PrewarmGeojsonCachesJob to Horizon (queue=prewarm): it plans one unit per scope and zoom.');
            return self::SUCCESS;
        }

        $zooms = PrewarmGeojsonCachesJob::parseZooms((string) $this->option('zooms'));

        if (! DB::table('legislatures')->whereNull('deleted_at')->exists()) {
            $this->warn('No legislatures present — nothing to warm.');
            return self::SUCCESS;
        }

        // Inline: the same bounded units, one after the other.
        $units = $planner->plan($zooms, $legIds);
        $planner->recordPlan($units);
        $this->info(sprintf('%d units (scope x zoom).', count($units)));

        $built = $failed = 0;
        foreach ($units as $i => $u) {
            $key = \App\Services\Maps\GeojsonPrewarmPlanner::unitKey($u['leg'], $u['scope'], $u['zoom']);
            $planner->mark($key, 'started');
            $r = $planner->build($u['leg'], $u['scope'], $u['zoom']);
            foreach ($r['messages'] as $m) {
                $this->warn('  '.$m);
            }
            if ($r['failed'] > 0) {
                $planner->mark($key, 'failed', ['messages' => array_slice($r['messages'], 0, 5)]);
                $failed += $r['failed'];
            } else {
                $planner->mark($key, 'done', ['built' => $r['built']]);
            }
            $built += $r['built'];
            $this->line(sprintf('  [%d/%d] %s: %d payloads', $i + 1, count($units), $key, $r['built']));
        }

        $this->info("GeoJSON prewarm complete: {$built} payloads, {$failed} failures.");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** --status: the ledger, with LOST units named (a killed worker cannot report). */
    private function printStatus(\App\Services\Maps\GeojsonPrewarmPlanner $planner): int
    {
        $st = $planner->status();
        if ($st['planned_at'] === null) {
            $this->line('No GeoJSON prewarm plan recorded on this box.');
            return self::SUCCESS;
        }
        $this->line('Planned: '.$st['planned_at']);
        $c = $st['counts'];
        $this->line(sprintf('Units:   %d planned, %d done, %d running, %d pending, %d failed, %d lost',
            $c['planned'], $c['done'], $c['running'], $c['pending'], $c['failed'], $c['lost']));
        foreach ($st['failed'] as $k) {
            $this->line("  failed   {$k}");
        }
        foreach ($st['lost'] as $k) {
            $this->line("  LOST     {$k}  (started, never finished: the worker was killed)");
        }

        return ($c['lost'] > 0 || $c['failed'] > 0) ? self::FAILURE : self::SUCCESS;
    }
}
