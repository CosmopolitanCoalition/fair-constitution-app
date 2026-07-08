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
 * Phase T.2 — Horizon-queued raster-tile pre-warm.
 *
 * Wraps the `rasters:prewarm` artisan command in a long-running Horizon job
 * so the multi-day full-planet warm-up doesn't tie up an interactive PHP
 * process. Two dispatch paths:
 *
 *   1. End of a fresh ETL run: seed_database.py invokes the command with
 *      `--queue` after Phase 2 completes. The command dispatches us, the
 *      ETL exits cleanly, and Horizon picks up the work on its long-running
 *      supervisor (see config/horizon.php).
 *
 *   2. After a map edit (Phase T.4): TileCacheInvalidator::dispatchPartialRewarm
 *      writes the affected `["z/x/y", ...]` keys to a JSON file under
 *      storage/app/tile-keys/ and dispatches us with $tileKeysFile pointing
 *      at it. Only those tiles re-generate; everything else stays warm.
 *
 * The job is deliberately a thin shim. All the warm-up logic — landmask
 * compute, cache short-circuit, controller invocation, progress reporting —
 * lives in `rasters:prewarm` and stays usable interactively (developers
 * running it from the artisan CLI, ad-hoc verification, etc.). This class
 * exists only to give the same code path a Horizon home for the days-long
 * cases.
 *
 * Failure handling: Horizon's `long-running` supervisor doesn't retry
 * (`tries = 1`). On crash, the operator's restart-the-job recipe is just
 * `php artisan rasters:prewarm --queue` — every already-cached tile is
 * skipped via the file-exists fast-path, so it picks up where it left off
 * with zero duplicate work.
 */
class PrewarmRasterTilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * No retries. A failed job restart begins by skipping every tile
     * already on disk, so a fresh run is functionally a resume.
     */
    public int $tries = 1;

    /**
     * No per-job timeout — full-planet z=0-12 takes 6-10 days. The
     * long-running Horizon supervisor's `timeout: 0` is what actually
     * enforces this; the property here is a hint to Laravel's queue
     * worker for environments without Horizon.
     */
    public int $timeout = 0;

    public function __construct(
        public readonly int $minZoom = 0,
        public readonly int $maxZoom = 12,
        public readonly bool $landOnly = true,
        /** Path to a JSON file containing ["z/x/y", ...] for partial-mode
         *  rewarms after a map edit. Null = full zoom-range scan. */
        public readonly ?string $tileKeysFile = null,
    ) {
        // Dedicated LOW-PRIORITY lane (own supervisor, no per-job timeout —
        // the default supervisor's 60 s timeout would SIGTERM us in seconds).
        // NOT 'long-running': that supervisor has a single slot reserved for
        // the INTERACTIVE heavy jobs (autoseed / mass-reseed / geodata scan),
        // and this warm re-dispatches on every horizon boot — parked there it
        // jammed an operator's autoseed behind hours of planetary prewarm.
        $this->onQueue('prewarm');
    }

    public function handle(): void
    {
        // The landmask compute fetches ~250 k bbox rows and builds a key
        // map of ~2.7 M entries at z=0-12. With PHP's default 128 MB
        // memory_limit that's a tight squeeze even after the in-place
        // mask optimization in landMask(). The Horizon container's
        // 99-local.ini override hasn't loaded cleanly (UTF-16 encoding
        // bug separate from this phase), so be defensive: raise the
        // limit explicitly inside the job. Effect is scoped to this
        // worker; other Horizon jobs unaffected.
        @ini_set('memory_limit', '768M');

        $args = [
            '--min-zoom'  => $this->minZoom,
            '--max-zoom'  => $this->maxZoom,
        ];
        if ($this->landOnly) {
            $args['--land-only'] = true;
        }
        if ($this->tileKeysFile !== null) {
            $args['--tile-keys'] = $this->tileKeysFile;
        }

        Log::info('PrewarmRasterTilesJob starting', [
            'min_zoom'        => $this->minZoom,
            'max_zoom'        => $this->maxZoom,
            'land_only'       => $this->landOnly,
            'tile_keys_file'  => $this->tileKeysFile,
        ]);

        $exit = Artisan::call('rasters:prewarm', $args);

        Log::info('PrewarmRasterTilesJob finished', [
            'exit_code' => $exit,
        ]);

        if ($exit !== 0) {
            // Surface to Horizon's failed-job list so the operator notices.
            throw new \RuntimeException(
                "rasters:prewarm exited non-zero (code {$exit})"
            );
        }
    }
}
