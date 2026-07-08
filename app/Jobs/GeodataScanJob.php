<?php

namespace App\Jobs;

use App\Services\Geodata\GeodataFlagService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background geodata flag scan (the POST /api/geodata/scan payload).
 *
 * Runs GeodataFlagService::scan() — six spatial detectors over the full
 * jurisdictions tree. On the world import that means ST_Covers across every
 * live parent/child pair plus per-iso raster summaries, so it runs on
 * Horizon's `long-running` supervisor (timeout=0, memory=512) rather than a
 * php-fpm worker; the default supervisor-1's 60s wall would SIGKILL it
 * mid-detector.
 *
 * Progress/liveness is the service's own status cache
 * (`geodata.scan.status`) — the service marks running at start, updates
 * per-category counts as detectors finish, and writes the final summary
 * (or the error) at the end, so the UI poller needs nothing from the job
 * itself.
 */
class GeodataScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Two hours: a full six-detector world scan's hot spot is the
     * same_space_chain ST_SymDifference pass over ~10k coastline-heavy pairs
     * (the ~3k md5 twins short-circuit past it) — MassReseedJob budgets the
     * same ceiling for its ST_Union sweeps. No automatic retry — open flags
     * are derived artifacts, so an operator simply re-dispatches after a
     * failure.
     */
    public int $timeout    = 7200;
    public int $tries      = 1;
    public int $retryAfter = 7260;  // Slightly longer than timeout

    /**
     * @param  list<string>|null  $categories  subset of GeodataFlag::CATEGORIES (null = all)
     * @param  list<string>|null  $isoCodes    restrict detectors to these iso_codes (null = all)
     */
    public function __construct(
        private readonly ?array $categories = null,
        private readonly ?array $isoCodes = null,
    ) {
        $this->onQueue('long-running');
    }

    public function handle(GeodataFlagService $service): void
    {
        $counts = $service->scan($this->categories, $this->isoCodes);

        Log::info('GeodataScanJob complete', [
            'categories' => $this->categories,
            'iso_codes'  => $this->isoCodes,
            'counts'     => $counts,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        // The service's catch block already stamped the status cache
        // (running=false + error summary); just leave the operator a log.
        Log::error('GeodataScanJob failed', [
            'categories' => $this->categories,
            'iso_codes'  => $this->isoCodes,
            'message'    => $exception->getMessage(),
        ]);
    }
}
