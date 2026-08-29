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

        // Leaves, set-based per level — the cycle-2 leaf law (floor clamp
        // only), the same statement the autoscale sizing runs.
        $floor = \App\Services\ConstitutionalDefaults::floor();
        for ($lvl = 0; $lvl <= 6; $lvl++) {
            DB::statement("
                INSERT INTO legislatures
                    (id, jurisdiction_id, term_number, status,
                     total_seats, type_a_seats, type_b_seats, quorum_required,
                     created_at, updated_at)
                SELECT gen_random_uuid(), j.id, 1, 'forming',
                       s.seats, s.seats, 0,
                       GREATEST(3, CEIL(s.seats / 2.0))::int,
                       now(), now()
                  FROM jurisdictions j
                 CROSS JOIN LATERAL (
                       SELECT GREATEST(?, ROUND(POWER(GREATEST(COALESCE(j.population, 0), 1)::numeric, 1.0/3.0)))::int AS seats
                 ) s
                 WHERE j.deleted_at IS NULL
                   AND j.adm_level = ?
                   AND NOT EXISTS (SELECT 1 FROM jurisdictions c
                                    WHERE c.parent_id = j.id AND c.deleted_at IS NULL)
                   AND NOT EXISTS (SELECT 1 FROM legislatures l
                                    WHERE l.jurisdiction_id = j.id AND l.deleted_at IS NULL)
            ", [$floor, $lvl]);
        }

        // Border precompute worklist + lanes (run-independent ledger; paid
        // once per geometry, already-done parents are kept).
        $pending = app(AdjacencyPrecompute::class)->seedWorklist();
        $lanes = max(2, min(HostCapacity::autoscaleWorkers(), max(1, $pending)));
        for ($i = 0; $i < $lanes; $i++) {
            PrecomputeLaneJob::dispatch();
        }

        Log::info('IngestTail: dispatched', ['pending_parents' => $pending, 'lanes' => $lanes]);
    }
}
