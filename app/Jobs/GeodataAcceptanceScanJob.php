<?php

namespace App\Jobs;

use App\Models\GeodataRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The run's closing item (GEODATA_PULL_ENGINE_PLAN.md §4/§5): the ONE work
 * unit that runs Laravel-side, not in a Python worker (no docker-in-docker).
 * The pump dispatches this when the run enters the `scanning` phase; it runs
 * the six-detector acceptance scan over the freshly-ingested tree (→
 * geodata_flags) and closes the acceptance_scan item, so the pump's next tick
 * advances the run to `done`.
 *
 * A failure never sinks the run — the item still closes (status=review with
 * the error as reason); the scan findings are advisory flags, not a gate.
 */
class GeodataAcceptanceScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 1;

    public function __construct(private readonly string $runId)
    {
        // long-running: consumed by supervisor-long-running (timeout=0) in
        // every environment. A dedicated 'geodata' queue had NO consumer —
        // the job would sit forever and the run would wedge at `scanning`.
        // Duplicate pump re-dispatches are harmless: the pending→running
        // claim below lets exactly one instance run the scan.
        $this->onQueue('long-running');
    }

    public function handle(): void
    {
        $run = GeodataRun::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        // Claim the acceptance_scan item (idempotent: another dispatch that
        // raced us finds it already running/done and no-ops).
        $claimed = DB::table('geodata_items')
            ->where('run_id', $run->id)
            ->where('kind', 'acceptance_scan')
            ->where('status', 'pending')
            ->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
        if ($claimed === 0) {
            return;
        }

        $status = 'done';
        $reason = null;
        $started = microtime(true);
        try {
            // All six detectors, whole ingested tree → geodata_flags.
            Artisan::call('geodata:scan');
        } catch (\Throwable $e) {
            $status = 'review';
            $reason = 'acceptance scan errored: '.$e->getMessage();
            Log::error('Geodata acceptance scan errored', [
                'run_id' => $run->id, 'message' => $e->getMessage(),
            ]);
        }

        DB::table('geodata_items')
            ->where('run_id', $run->id)
            ->where('kind', 'acceptance_scan')
            ->where('status', 'running')
            ->update([
                'status'      => $status,
                'reason'      => $reason,
                'metrics'     => json_encode(['elapsed' => round(microtime(true) - $started, 1)]),
                'finished_at' => now(),
                'updated_at'  => now(),
            ]);
    }
}
