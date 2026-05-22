<?php

namespace App\Console\Commands;

use App\Http\Controllers\RasterTileController;
use App\Jobs\PrewarmRasterTilesJob;
use App\Support\TileCoordMath;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pre-warm the WorldPop raster tile cache.
 *
 * The rasters and the geographic boundaries don't change unless the operator
 * runs a fresh ETL — so once we've generated every visible tile once, every
 * subsequent viewer load hits a warm disk cache and renders in <50 ms. This
 * command iterates the (z, x, y) coordinate space and asks
 * RasterTileController::tile() to generate each one, skipping tiles where:
 *
 *   - The cache file already exists (idempotent — safe to re-run).
 *   - No worldpop_rasters tile intersects the bbox (oceans, polar deserts).
 *     These return a tiny transparent PNG that gets cached too, so the
 *     check is fast on subsequent passes.
 *
 * Tile counts scale exponentially with zoom (4^z tiles total):
 *   z=0:        1, z=1:        4, z=2:       16, z=3:       64
 *   z=4:      256, z=5:    1,024, z=6:    4,096, z=7:   16,384
 *   z=8:   65,536, z=9:  262,144, z=10:  ~1 M,   z=11:  ~4 M
 *   z=12:  ~16 M
 *
 * Generation time per tile drops as zoom rises (smaller bbox → fewer source
 * rasters to union → faster SQL). Observed cold-cache:
 *   z=0:  ~6.6 min (whole world ST_Union)
 *   z=2:  ~56 s
 *   z=4:  ~12 s
 *   z=8+: <2 s typical
 *
 * Default `--max-zoom=5` warms 1,365 tiles (z=0-5) — covers Earth → country
 * zoom transitions where first-load latency is most noticeable. Higher
 * zooms remain lazy; they generate fast enough on demand.
 *
 * Usage:
 *   php artisan rasters:prewarm                   # default z=0-5
 *   php artisan rasters:prewarm --max-zoom=6      # also warm z=6 (~4 k tiles)
 *   php artisan rasters:prewarm --min-zoom=4 --max-zoom=7   # specific range
 *   php artisan rasters:prewarm --land-only       # only over rasterised land
 *   php artisan rasters:prewarm --queue           # dispatch to Horizon (long-running),
 *                                                 # return immediately. The ETL uses
 *                                                 # this at the end of a fresh run.
 */
class RasterTilePrewarmCommand extends Command
{
    protected $signature = 'rasters:prewarm
        {--min-zoom=0    : First zoom level to warm (inclusive)}
        {--max-zoom=5    : Last zoom level to warm (inclusive)}
        {--land-only     : Only generate tiles whose bbox overlaps a worldpop_rasters row}
        {--queue         : Dispatch as a Horizon-queued PrewarmRasterTilesJob and return; do not warm inline}
        {--tile-keys=    : Path to a JSON file of ["z/x/y", ...]. When set, ignore zoom range/land-only and warm only those tiles. Used by the future map-editor invalidator for partial post-edit re-warms.}';

    protected $description = 'Pre-generate WorldPop raster tiles to disk cache so viewer first-loads are instant.';

    public function handle(): int
    {
        $minZ = (int) $this->option('min-zoom');
        $maxZ = (int) $this->option('max-zoom');
        $landOnly = (bool) $this->option('land-only');
        $tileKeysFile = $this->option('tile-keys');

        if ($minZ < 0 || $maxZ < $minZ || $maxZ > 12) {
            $this->error("Bad zoom range: min={$minZ}, max={$maxZ}. Allowed 0-12.");
            return self::FAILURE;
        }

        // --queue mode: hand the work to Horizon's long-running supervisor
        // and return. The ETL hooks this at the end of fresh runs so it can
        // exit cleanly while pre-warm runs for days in the background.
        // --tile-keys propagates through the job for partial-rewarm dispatches.
        if ($this->option('queue')) {
            PrewarmRasterTilesJob::dispatch(
                minZoom:      $minZ,
                maxZoom:      $maxZ,
                landOnly:     $landOnly,
                tileKeysFile: $tileKeysFile ?: null,
            );
            $this->info(sprintf(
                'Dispatched PrewarmRasterTilesJob to Horizon (queue=long-running): z=%d-%d, land_only=%s%s.',
                $minZ, $maxZ, $landOnly ? 'true' : 'false',
                $tileKeysFile ? ", tile_keys={$tileKeysFile}" : ''
            ));
            return self::SUCCESS;
        }

        // --tile-keys mode: warm exactly the keys listed in the JSON file
        // and return. Ignores zoom range + land-only flags (the keys ARE
        // the target). Used by TileCacheInvalidator (Phase T.4) when a
        // future map edit affects a bounded set of tiles.
        if ($tileKeysFile) {
            return $this->warmByTileKeys($tileKeysFile);
        }

        // Compute the landmask once for --land-only — the set of (z, x, y)
        // coordinates that overlap any worldpop_rasters row. Below we filter
        // each candidate tile against this set in O(1).
        $landMask = null;
        if ($landOnly) {
            $this->info('Computing land-coverage mask…');
            $landMask = $this->landMask($minZ, $maxZ);
            $this->info(sprintf('Land mask: %d candidate tiles across z=%d-%d.', count($landMask), $minZ, $maxZ));
        }

        $controller = app(RasterTileController::class);
        $request    = new Request();

        $start  = microtime(true);
        $totalGenerated = 0;
        $totalSkipped   = 0;
        $totalEmpty     = 0;
        $totalErrors    = 0;

        for ($z = $minZ; $z <= $maxZ; $z++) {
            $tilesAtZoom = 1 << $z;            // 2^z per axis
            $total = $tilesAtZoom * $tilesAtZoom;

            $this->info(sprintf(
                "[z=%d] %s tiles (%dx%d grid)…",
                $z, number_format($total), $tilesAtZoom, $tilesAtZoom
            ));

            $bar = $this->output->createProgressBar($total);
            $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%  %message%');
            $bar->setMessage('starting…');
            $bar->start();

            $genZoom = 0;
            $skipZoom = 0;
            $emptyZoom = 0;
            $errZoom   = 0;
            $zoomStart = microtime(true);

            for ($x = 0; $x < $tilesAtZoom; $x++) {
                for ($y = 0; $y < $tilesAtZoom; $y++) {
                    if ($landMask !== null && ! isset($landMask["{$z}/{$x}/{$y}"])) {
                        $emptyZoom++;
                        $bar->advance();
                        continue;
                    }

                    $cachePath = storage_path("app/tile-cache/{$z}/{$x}/{$y}.png");
                    if (is_file($cachePath)) {
                        $skipZoom++;
                        $bar->advance();
                        continue;
                    }

                    // Advance BEFORE the long controller call so the progress
                    // bar's elapsed/estimated timers reflect real work, then
                    // tick at the end. The displayed N/M shows tiles
                    // *completed*, not tiles *started*.
                    $bar->setMessage("generating {$z}/{$x}/{$y}");

                    try {
                        $controller->tile($request, $z, $x, $y);
                        $genZoom++;
                    } catch (\Throwable $e) {
                        $errZoom++;
                        $this->newLine();
                        $this->error("  tile {$z}/{$x}/{$y} failed: " . $e->getMessage());
                    }

                    $bar->advance();
                }
            }

            $bar->setMessage(sprintf(
                'generated=%d skipped=%d empty=%d errors=%d',
                $genZoom, $skipZoom, $emptyZoom, $errZoom
            ));
            $bar->finish();
            $this->newLine();
            $this->line(sprintf(
                '  z=%d done in %s — generated=%d skipped=%d empty=%d errors=%d',
                $z,
                $this->elapsed(microtime(true) - $zoomStart),
                $genZoom, $skipZoom, $emptyZoom, $errZoom
            ));

            $totalGenerated += $genZoom;
            $totalSkipped   += $skipZoom;
            $totalEmpty     += $emptyZoom;
            $totalErrors    += $errZoom;
        }

        $elapsed = microtime(true) - $start;
        $this->newLine();
        $this->info(sprintf(
            'Pre-warm complete in %s. Generated=%d, skipped (already cached)=%d, empty (no overlap)=%d, errors=%d.',
            $this->elapsed($elapsed),
            $totalGenerated, $totalSkipped, $totalEmpty, $totalErrors
        ));

        return self::SUCCESS;
    }

    /**
     * Compute the (z, x, y) coordinates whose bbox intersects any
     * worldpop_rasters row. Returns an associative array keyed by
     * "z/x/y" for O(1) lookup.
     *
     * Strategy: fetch each source raster's bbox once (~230 rasters
     * worldwide), then for every zoom level convert each bbox into the
     * range of (x, y) tile coordinates it covers via standard Web
     * Mercator math. Union the ranges across all rasters. No per-tile
     * SQL — completes in seconds even for z=0..12 (~22 M tiles
     * theoretically scanned, ~1 M land tiles emitted).
     *
     * This replaces the earlier recursive-CTE approach which generated
     * the full Cartesian product (X × Y) at the database layer and
     * filtered with EXISTS — O(N²) per zoom, blew up past z=4.
     */
    private function landMask(int $minZ, int $maxZ): array
    {
        // Fetch each individual raster tile's bbox. Unioning per-iso first
        // blew up PostGIS memory ("invalid memory alloc request size") on
        // big-iso countries with 10k+ tiles. The set-of-bboxes approach
        // produces ~250 k rows but each is just 4 doubles, totals ~10 MB
        // in PHP — fine. Tile-coord ranges naturally dedupe via the
        // assoc-array key.
        $bboxes = DB::select("
            SELECT
                ST_UpperLeftX(rast)::double precision           AS xmin,
                (ST_UpperLeftY(rast) + ST_Height(rast) * ST_ScaleY(rast))::double precision AS ymin,
                (ST_UpperLeftX(rast) + ST_Width(rast)  * ST_ScaleX(rast))::double precision AS xmax,
                ST_UpperLeftY(rast)::double precision           AS ymax
            FROM worldpop_rasters
        ");

        // Union per-raster tile-key sets via TileCoordMath::bboxToTileKeys.
        // The helper deduplicates naturally via the assoc-array key, and
        // shares the slippy-map math with the tile controller and the
        // future cache-invalidator so all three never drift.
        $mask = [];
        // Compute tile-key ranges inline rather than calling
        // TileCoordMath::bboxToTileKeys per bbox. Building an
        // intermediate per-bbox key array and merging blew past PHP's
        // 128 MB queue-worker memory limit at z=0..12 × 250 k bboxes
        // (each bbox can produce hundreds of keys at high zoom; the
        // intermediate arrays accumulate to ~150 MB before merge).
        // Writing directly into $mask keeps peak RAM small while still
        // sharing the slippy-map math with TileCoordMath as the single
        // source of truth.
        foreach ($bboxes as $b) {
            $xmin = (float) $b->xmin;
            $ymin = (float) $b->ymin;   // south (smaller lat)
            $xmax = (float) $b->xmax;
            $ymax = (float) $b->ymax;   // north (larger lat)
            for ($z = $minZ; $z <= $maxZ; $z++) {
                $xLo = TileCoordMath::lngToTileX($xmin, $z);
                $xHi = TileCoordMath::lngToTileX($xmax, $z);
                $yLo = TileCoordMath::latToTileY($ymax, $z); // north → smaller y
                $yHi = TileCoordMath::latToTileY($ymin, $z); // south → larger y
                for ($x = $xLo; $x <= $xHi; $x++) {
                    for ($y = $yLo; $y <= $yHi; $y++) {
                        $mask["{$z}/{$x}/{$y}"] = true;
                    }
                }
            }
        }
        return $mask;
    }

    /**
     * Phase T.4 — partial-rewarm mode.
     *
     * Read a JSON array of "z/x/y" strings from disk and regenerate exactly
     * those tiles. Cache fast-path still applies — if the caller deleted the
     * file (e.g. via TileCacheInvalidator::invalidateBbox), the tile will be
     * absent and we'll regenerate; if the file is still on disk, we skip.
     *
     * The map editor (a future phase) will call this via the queue: it
     * passes the bbox of (old ∪ new) geometry to TileCacheInvalidator, which
     * computes the tile-key set, deletes those files, writes the key list
     * to /storage/app/tile-keys/<uuid>.json, and dispatches the prewarm job
     * with --tile-keys=<path>. Horizon's long-running worker picks it up,
     * re-runs this command (without --queue) in this code path, and the
     * affected tiles regenerate before the editor's next viewer load.
     */
    private function warmByTileKeys(string $filePath): int
    {
        if (! is_file($filePath)) {
            $this->error("Tile-keys file not found: {$filePath}");
            return self::FAILURE;
        }

        $raw = @file_get_contents($filePath);
        if ($raw === false) {
            $this->error("Could not read tile-keys file: {$filePath}");
            return self::FAILURE;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $this->error("Tile-keys file must contain a JSON array of \"z/x/y\" strings.");
            return self::FAILURE;
        }

        // Normalise + filter to well-formed entries. Anything malformed gets
        // logged and skipped rather than aborting the whole batch — partial
        // success is preferable when the editor's bbox→keys produced a
        // mostly-valid list with one stray.
        $keys = [];
        foreach ($decoded as $entry) {
            if (! is_string($entry) || ! preg_match('#^(\d{1,2})/(\d+)/(\d+)$#', $entry, $m)) {
                $this->warn("Skipping malformed tile key: " . var_export($entry, true));
                continue;
            }
            [$z, $x, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($z < 0 || $z > 12) {
                $this->warn("Skipping out-of-range zoom: {$entry}");
                continue;
            }
            $maxIdx = (1 << $z) - 1;
            if ($x < 0 || $x > $maxIdx || $y < 0 || $y > $maxIdx) {
                $this->warn("Skipping out-of-grid tile: {$entry}");
                continue;
            }
            $keys[] = [$z, $x, $y];
        }

        $totalKeys = count($keys);
        if ($totalKeys === 0) {
            $this->info("No valid tile keys after filtering — nothing to warm.");
            return self::SUCCESS;
        }

        $this->info(sprintf('Warming %s tile keys from %s…',
            number_format($totalKeys), $filePath));

        $controller = app(RasterTileController::class);
        $request    = new Request();

        $bar = $this->output->createProgressBar($totalKeys);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%  %message%');
        $bar->setMessage('starting…');
        $bar->start();

        $generated = 0;
        $skipped   = 0;
        $errors    = 0;
        $start     = microtime(true);

        foreach ($keys as [$z, $x, $y]) {
            $cachePath = storage_path("app/tile-cache/{$z}/{$x}/{$y}.png");
            if (is_file($cachePath)) {
                $skipped++;
                $bar->advance();
                continue;
            }

            $bar->setMessage("generating {$z}/{$x}/{$y}");

            try {
                $controller->tile($request, $z, $x, $y);
                $generated++;
            } catch (\Throwable $e) {
                $errors++;
                $this->newLine();
                $this->error("  tile {$z}/{$x}/{$y} failed: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->setMessage(sprintf(
            'generated=%d skipped=%d errors=%d',
            $generated, $skipped, $errors
        ));
        $bar->finish();
        $this->newLine();
        $this->info(sprintf(
            'Tile-keys warm complete in %s. Generated=%d, skipped (already cached)=%d, errors=%d.',
            $this->elapsed(microtime(true) - $start),
            $generated, $skipped, $errors
        ));

        return self::SUCCESS;
    }

    private function elapsed(float $seconds): string
    {
        if ($seconds < 60)   return sprintf('%.1fs', $seconds);
        if ($seconds < 3600) return sprintf('%dm %ds', floor($seconds / 60), floor($seconds) % 60);
        return sprintf('%dh %dm', floor($seconds / 3600), floor(($seconds % 3600) / 60));
    }
}
