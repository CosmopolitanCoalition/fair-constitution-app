<?php

namespace App\Jobs;

use App\Http\Controllers\LegislatureController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Long-running mass-reseed sweep that creates districts across many scopes
 * (e.g., recursive Earth autoseed). Runs on the Horizon queue so it doesn't
 * tie up a php-fpm worker and isn't bounded by HTTP request timeouts.
 *
 * Per-scope commits inside LegislatureController::executeMassReseedSweep()
 * mean partial progress survives any error or worker death — restarting
 * with the same operation_scope (e.g. `legislature_unassigned`) effectively
 * resumes from where it left off, since the sweep skips scopes that already
 * have districts.
 *
 * Halt support: a separate /mass-halt endpoint sets a cache flag. The sweep
 * polls it between scopes and exits cleanly with whatever's already committed.
 */
class MassReseedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 2-hour timeout covers a whole-Earth recursive sweep on reasonable
     * hardware. Per-scope commits mean a kill-at-timeout still preserves
     * everything that's already committed.
     */
    public int $timeout    = 7200;
    public int $tries      = 1;     // No automatic retry — partial sweeps are resumable manually
    public int $retryAfter = 7260;  // Slightly longer than timeout

    public function __construct(
        private readonly string $legislatureId,
        private readonly string $operationScope,
        private readonly string $scopeId,
        private readonly string $mapId,
    ) {}

    public function handle(): void
    {
        try {
            /** @var LegislatureController $ctrl */
            $ctrl = app(LegislatureController::class);

            $result = $ctrl->executeMassReseedSweep(
                $this->legislatureId,
                $this->operationScope,
                $this->scopeId,
                $this->mapId,
            );

            Log::info('MassReseedJob complete', [
                'legislature_id'    => $this->legislatureId,
                'scopes_processed'  => $result['scopes_processed'],
                'districts_created' => $result['districts_created'],
                'halted'            => $result['halted'],
                'errors'            => $result['errors'],
            ]);

            // Post-sweep tasks: recolor indices, invalidate cached GeoJSON,
            // dispatch the adjacency-based recolor refresh. Run even on
            // halt so the partial districts have correct colors.
            foreach ($result['scope_ids'] ?? [] as $sid) {
                try {
                    $ctrl->recomputeColorIndices(
                        $this->legislatureId,
                        $sid,
                        $this->scopeId,   // root scope for the recolor walk
                        $this->mapId,
                    );
                } catch (\Throwable $e) {
                    Log::warning('Color-index recompute failed (non-fatal): '.$e->getMessage());
                }
            }
            try {
                $ctrl->flushRevealedCache($this->legislatureId, $this->mapId, $this->scopeId);
            } catch (\Throwable $e) {
                Log::warning('flushRevealedCache failed (non-fatal): '.$e->getMessage());
            }

            RecolorDistrictsJob::dispatch($this->legislatureId, $this->mapId);
        } finally {
            // Always clear the running + halt flags so the UI can re-enable
            // controls and a fresh run can be dispatched.
            Cache::forget("legislature.{$this->legislatureId}.mass_running");
            Cache::forget("legislature.{$this->legislatureId}.mass_halt");
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('MassReseedJob failed', [
            'legislature_id' => $this->legislatureId,
            'operation'      => $this->operationScope,
            'scope_id'       => $this->scopeId,
            'map_id'         => $this->mapId,
            'message'        => $exception->getMessage(),
        ]);

        Cache::put("legislature.{$this->legislatureId}.mass_progress", [
            'phase'       => 'failed',
            'phase_label' => 'Mass reseed failed: ' . $exception->getMessage(),
            'last_update_at' => time(),
        ], 7200);
        Cache::forget("legislature.{$this->legislatureId}.mass_running");
        Cache::forget("legislature.{$this->legislatureId}.mass_halt");
    }
}
