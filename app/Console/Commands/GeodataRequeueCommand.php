<?php

namespace App\Console\Commands;

use App\Models\GeodataRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * geodata:requeue — the pull engine's batch-fix cycle as a command
 * (GEODATA_PULL_ENGINE_PLAN.md §5, the run-6 recipe wrapped in a CLI).
 *
 * Resets matching items to pending at the HEAD of the queue (position 0) so
 * workers pick them up next. Requeue may target ANY settled state — done
 * included — because reprocessing a healthy ISO after a code tweak is the
 * POINT of the pull engine: tweak → requeue affected items → inspect → no
 * planetary rerun.
 *
 * Requeuing a boundary_iso auto-requeues the downstream barrier items
 * (resolve_global + finalize_global + acceptance_scan): the global passes must
 * re-run over the re-imported hierarchy. All barrier passes are idempotent.
 *
 *   php artisan geodata:requeue --status=review               # the review census
 *   php artisan geodata:requeue --iso=IND --kind=attribution_pair
 *   php artisan geodata:requeue --iso=NZL --dry-run-items     # requeue pairs as dry-run
 */
class GeodataRequeueCommand extends Command
{
    protected $signature = 'geodata:requeue
        {--run= : Run id (default: the newest run)}
        {--kind= : Restrict to one item kind}
        {--iso=* : Restrict to ISO3 code(s)}
        {--status=* : Statuses to requeue (default: review,failed)}
        {--dry-run-items : Set dry_run=true on the requeued items (T.7 review-then-APPLY)}';

    protected $description = 'Reset settled geodata items to pending (head of queue) for incremental reprocessing';

    public function handle(): int
    {
        $run = $this->option('run')
            ? GeodataRun::query()->find($this->option('run'))
            : GeodataRun::query()->orderByDesc('created_at')->first();
        if ($run === null) {
            $this->error('No geodata run found.');

            return self::FAILURE;
        }

        $statuses = $this->option('status') ?: ['review', 'failed'];
        $q = DB::table('geodata_items')
            ->where('run_id', $run->id)
            ->whereIn('status', $statuses);
        if ($this->option('kind')) {
            $q->where('kind', $this->option('kind'));
        }
        if ($this->option('iso')) {
            $q->whereIn('iso_code', array_map('strtoupper', $this->option('iso')));
        }

        $update = [
            'status' => 'pending', 'claim_token' => null, 'reason' => null,
            'started_at' => null, 'finished_at' => null, 'position' => 0,
            'updated_at' => now(),
        ];
        if ($this->option('dry-run-items')) {
            $update['dry_run'] = true;
        }
        $n = $q->update($update);

        // Downstream barriers re-run when upstream fan-out work is requeued.
        $kinds = DB::table('geodata_items')
            ->where('run_id', $run->id)->where('status', 'pending')
            ->distinct()->pluck('kind')->all();
        $downstream = 0;
        if (array_intersect($kinds, ['boundary_iso', 'raster_iso', 'attribution_pair'])) {
            $barriers = ['finalize_global', 'acceptance_scan'];
            if (in_array('boundary_iso', $kinds, true)) {
                $barriers[] = 'resolve_global';
            }
            // 'running' belongs in this list too (2026-08-02, operator-caught
            // race): phaseDrained() treats a freshly-'review'd item as settled,
            // so the pump can claim a downstream barrier in the WINDOW between
            // a kill and this command's requeue — resolve_global started 41s
            // before this command even reset PRI's item, and kept running
            // while PRI was mid-reprocess. Resetting a 'running' row to
            // pending is safe: record_outcome() is claim_token-fenced and
            // requires status='running', so the stray process's eventual
            // write becomes a guaranteed no-op once claim_token is nulled
            // here — it just finishes uselessly instead of clobbering the
            // rewound run.
            $downstream = DB::table('geodata_items')
                ->where('run_id', $run->id)
                ->whereIn('kind', $barriers)
                ->whereIn('status', ['done', 'review', 'failed', 'running'])
                ->update([
                    'status' => 'pending', 'claim_token' => null, 'reason' => null,
                    'started_at' => null, 'finished_at' => null, 'updated_at' => now(),
                ]);
        }

        // Rewind the run's phase pointer to the earliest phase with pending
        // work, and reopen a finished run — the pump advances from there.
        $earliest = null;
        foreach (GeodataRun::PHASES as $phase) {
            $kind = GeodataRun::PHASE_KIND[$phase] ?? null;
            if ($kind !== null && in_array($kind, $kinds, true)) {
                $earliest = $phase;
                break;
            }
        }
        if ($earliest !== null) {
            $run->forceFill([
                'phase'       => $earliest,
                'status'      => 'running',
                'finished_at' => null,
                'updated_at'  => now(),
            ])->save();
        }

        $this->info("Requeued {$n} item(s) + {$downstream} downstream barrier(s) on run {$run->id}"
            .($earliest !== null ? " — phase rewound to {$earliest}." : '.'));
        $this->line('The pump advances it within a minute (or run `php artisan geodata:pump` now); '
            .'start the worker pool from Setup Step 2 if the supervisor is idle.');

        return self::SUCCESS;
    }
}
