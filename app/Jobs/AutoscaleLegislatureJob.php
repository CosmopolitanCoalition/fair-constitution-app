<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RETIRED (pull engine, 2026-07-19) — kept one release as a stub.
 *
 * Per-item sweep jobs are gone: AutoscaleWorkerJob claims per-SCOPE work
 * from autoscale_scopes (SweepScopeProcessor executes; the completeness
 * assessment moved there verbatim). Redis still holds serialized payloads
 * of this class from a live deploy; the stub hands their items back to
 * pending so the pull ladder claims them, then discards the payload.
 * Delete after the next flattening.
 */
class AutoscaleLegislatureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 1;

    public function __construct(private readonly string $itemId)
    {
        $this->onQueue('autoscale');
    }

    public function handle(): void
    {
        // A legacy payload's item sits 'queued' (the retired dispatch state)
        // — normalize it for the claim ladder. The pump's compat sweep does
        // the same; this just gets there first when the payload delivers.
        DB::table('autoscale_items')
            ->where('id', $this->itemId)
            ->whereIn('status', ['queued'])
            ->update([
                'status'     => 'pending',
                'reason'     => 'reclaimed: legacy dispatch state',
                'updated_at' => now(),
            ]);
        Log::info('AutoscaleLegislatureJob stub: retired payload discarded (pull workers own sweeps)', [
            'item_id' => $this->itemId,
        ]);
    }
}
