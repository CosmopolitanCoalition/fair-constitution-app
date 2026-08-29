<?php

namespace App\Jobs;

use App\Services\Autoscale\AdjacencyPrecompute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ONE LANE of the ingest-tail border precompute (operator ruling
 * 2026-08-29). Claims ONE parent from the run-independent ledger
 * (jurisdiction_adjacency_parents), computes its sibling borders, and
 * dispatches its replacement while pending parents remain — the same
 * fresh-slate-per-unit shape as every other lane in this codebase. The
 * autoscale run uses its own claim for the same ledger; this lane exists
 * so a fresh box pays the borders during ingestion, before any run.
 */
class PrecomputeLaneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;
    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('autoscale');
    }

    public function handle(): void
    {
        // Defer to a live full-scale run — its own workers claim this ledger.
        if (DB::table('autoscale_runs')->whereNotIn('status', ['done', 'failed'])->exists()) {
            return;
        }

        $token = (string) Str::uuid();
        $row = DB::selectOne("
            UPDATE jurisdiction_adjacency_parents
               SET status = 'running', claim_token = ?, updated_at = now()
             WHERE parent_id = (
                   SELECT parent_id FROM jurisdiction_adjacency_parents
                    WHERE status = 'pending'
                       OR (status = 'running' AND updated_at < now() - interval '30 minutes')
                    ORDER BY child_count DESC, adm_level ASC, parent_id
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED
             )
         RETURNING parent_id
        ", [$token]);

        if ($row === null) {
            $open = DB::table('jurisdiction_adjacency_parents')
                ->whereIn('status', ['pending', 'running'])->count();
            if ($open === 0) {
                Log::info('IngestTail precompute COMPLETE (borders paid)');
            }

            return;
        }

        try {
            app(AdjacencyPrecompute::class)->processParent((string) $row->parent_id);
        } catch (\Throwable $e) {
            DB::table('jurisdiction_adjacency_parents')
                ->where('parent_id', $row->parent_id)
                ->update(['status' => 'failed', 'updated_at' => now()]);
            Log::warning('IngestTail precompute parent failed', [
                'parent' => (string) $row->parent_id,
                'error'  => mb_substr($e->getMessage(), 0, 200),
            ]);
        }

        $more = DB::table('jurisdiction_adjacency_parents')->where('status', 'pending')->exists();
        if ($more) {
            self::dispatch();
        }
    }
}
