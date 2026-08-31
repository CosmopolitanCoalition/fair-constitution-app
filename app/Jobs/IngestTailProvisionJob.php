<?php

namespace App\Jobs;

use App\Services\Autoscale\AdjacencyPrecompute;
use App\Support\HostCapacity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * THE INGEST TAIL (operator ruling 2026-08-29, rubric ingest-tail-
 * apportionment = A): a completed geodata ingestion finishes with the
 * chamber sizes and the sibling-border graph already computed, BEFORE map
 * acceptance — acceptance is not a precondition, because unaccepted data
 * makes this work moot, not wrong. A fresh box then moves from Accept
 * straight into district drawing, ~70 minutes earlier.
 *
 * Everything here is idempotent and resumable: the seed upserts and skips,
 * the leaf INSERT is guarded per level by NOT EXISTS, the precompute is
 * paid once per geometry with its own ledger, and the lanes claim one
 * parent per fresh-slate job. Tested on the next fresh run, per the pin.
 */
class IngestTailProvisionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;
    public int $tries = 1;

    public function __construct(public string $geodataRunId)
    {
        $this->onQueue('autoscale');
    }

    public function handle(): void
    {
        // A live full-scale run owns these tables; never run beside it.
        if (DB::table('autoscale_runs')->whereNotIn('status', ['done', 'failed'])->exists()) {
            Log::info('IngestTail: an autoscale run is active — skipping (it owns sizing/precompute)');

            return;
        }

        Log::info('IngestTail: sizing parents (cube-root law)', ['geodata_run' => $this->geodataRunId]);
        Artisan::call('apportionment:seed', ['--parents-only' => true]);

        // Leaves, set-based per level — THE ONE LEAF STATEMENT
        // (AutoscaleEnumeration::seedLeafLegislatures), the same owner the
        // run's sizing pass calls, so the two paths cannot drift.
        $floor = \App\Services\ConstitutionalDefaults::floor();
        for ($lvl = 0; $lvl <= 6; $lvl++) {
            \App\Support\AutoscaleEnumeration::seedLeafLegislatures($lvl, $floor);
        }

        // Border precompute worklist + lanes (run-independent ledger; paid
        // once per geometry, already-done parents are kept).
        $pending = app(AdjacencyPrecompute::class)->seedWorklist();
        $lanes = max(2, min(HostCapacity::autoscaleWorkers(), max(1, $pending)));
        for ($i = 0; $i < $lanes; $i++) {
            PrecomputeLaneJob::dispatch();
        }

        // THE APPORTIONMENT LEDGER (operator order 2026-08-31): the seat
        // arithmetic is a property of the POPULATION DATA — computed here,
        // at the ingest tail, once per dataset. Heads, stamped scope trees,
        // walk order, and pre-draw gate verdicts for the whole world land
        // in apportionment_ledger before any run exists; runs copy.
        $seeded = \App\Support\AutoscaleEnumeration::seedApportionmentWorklist();
        $aLanes = max(2, min(HostCapacity::autoscaleWorkers(), max(1, $seeded)));
        for ($i = 0; $i < $aLanes; $i++) {
            \App\Jobs\ApportionmentLaneJob::dispatch();
        }

        Log::info('IngestTail: dispatched', [
            'pending_parents' => $pending, 'lanes' => $lanes,
            'apportionment_pending' => $seeded, 'apportionment_lanes' => $aLanes,
        ]);
    }
}
