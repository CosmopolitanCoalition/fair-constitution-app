<?php

namespace App\Jobs;

use App\Models\SimItem;
use App\Models\SimRun;
use App\Services\Demo\Stages\CohortStage;
use App\Services\Demo\Stages\CountingStage;
use App\Services\Demo\Stages\ElectionStage;
use App\Services\Demo\Stages\GovernanceStage;
use App\Services\Demo\Stages\SeatingStage;
use App\Services\Demo\Stages\IdentityStage;
use App\Support\SimClaims;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A simulated-world PULL WORKER.
 *
 * Claims ONE unit at a time and executes it, in a loop, until the run stops
 * handing out work or its budget expires. Process count IS concurrency — there
 * is no second width dial, exactly as `HostCapacity`'s docblock says of the
 * autoscale pool.
 *
 * Everything about the shape is inherited from the autoscale worker, because
 * each piece of it was learned from a failure:
 *   · a LEASE row so the live UI can show who is doing what, culled by the pump
 *   · OVER-DISPATCH SELF-CORRECTION — a worker that finds the pool over target
 *     deletes its own lease and leaves, so a double-dispatch cannot compound
 *   · HALT AND BREAKER checked EVERY loop iteration, not once at boot
 *   · a MEMORY CEILING with exit at a claim boundary, because a long-lived PHP
 *     process at planet scale fragments
 *   · SIGTERM stops at the next claim boundary, never mid-item
 *   · `tries = 1`: a failed item becomes a REVIEW row, not a silent retry that
 *     duplicates work the pump would have reclaimed anyway
 *
 * An item that throws is settled as `review` WITH ITS REASON rather than
 * killing the run. Failures never sink a run — the pump's barrier opens on
 * settled, and the acceptance scan reports what refused.
 */
class SimWorkerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 0;

    /** Stop claiming after this long so a worker recycles rather than ages. */
    private const CLAIM_BUDGET_SECONDS = 3000;

    private const MEMORY_RECYCLE_BYTES = 480 * 1048576;

    private const MAX_CONSECUTIVE_FAILURES = 3;

    private bool $stopping = false;

    public function __construct(public readonly string $runId)
    {
        $this->onQueue('sim');
    }

    public function handle(): void
    {
        $run = SimRun::find($this->runId);

        if ($run === null || ! $run->isClaimable()) {
            return;
        }

        $token = (string) Str::uuid();
        $startedAt = microtime(true);
        $failures = 0;

        DB::table('sim_worker_leases')->insert([
            'id' => $token,
            'run_id' => $run->id,
            'started_at' => now(),
            'last_seen_at' => now(),
        ]);

        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () {
                $this->stopping = true;
            });
        }

        try {
            while (true) {
                if ($this->stopping) {
                    break;
                }

                if (microtime(true) - $startedAt > self::CLAIM_BUDGET_SECONDS) {
                    break;
                }

                if (memory_get_usage(true) > self::MEMORY_RECYCLE_BYTES) {
                    break;
                }

                // Re-read the run EVERY iteration: halt, pause and phase are all
                // live state, and a worker that caches them keeps working after
                // an operator has told the world to stop.
                $run->refresh();

                if (! $run->isClaimable()) {
                    break;
                }

                $item = SimClaims::next($run, $token);

                if ($item === null) {
                    break;
                }

                DB::table('sim_worker_leases')->where('id', $token)->update([
                    'claim_type' => $item->kind,
                    'claim_label' => $this->label($item),
                    'claim_started_at' => now(),
                    'last_seen_at' => now(),
                ]);

                try {
                    $metrics = $this->execute($run, $item);
                    $this->settle($item->id, SimItem::STATUS_DONE, $metrics);
                    $failures = 0;
                } catch (\Throwable $e) {
                    $failures++;

                    $this->settle(
                        $item->id,
                        SimItem::STATUS_REVIEW,
                        ['error' => class_basename($e)],
                        Str::limit($e->getMessage(), 500)
                    );

                    Log::warning('sim worker item refused', [
                        'run' => $run->id, 'item' => $item->id, 'kind' => $item->kind,
                        'error' => $e->getMessage(),
                    ]);

                    if ($failures >= self::MAX_CONSECUTIVE_FAILURES) {
                        break; // infrastructure is unwell; the pump reseeds shortly
                    }
                }

                DB::table('sim_worker_leases')->where('id', $token)->update([
                    'claim_type' => null,
                    'claim_label' => null,
                    'claim_started_at' => null,
                    'last_seen_at' => now(),
                ]);
            }
        } finally {
            // Best effort: if the connection died, the pump culls stale leases.
            try {
                DB::table('sim_worker_leases')->where('id', $token)->delete();
            } catch (\Throwable) {
            }
        }
    }

    /** Dispatch one claimed unit to its stage. */
    private function execute(SimRun $run, object $item): array
    {
        $options = $run->options ?? [];
        $version = (int) ($options['version'] ?? 1);

        return match ($item->kind) {
            'cohort_scope' => CohortStage::run(
                (string) $item->jurisdiction_id,
                (string) $run->id,
                $version,
                (int) ($options['turnout_pct'] ?? CohortStage::DEFAULT_TURNOUT_PCT),
            ),
            'identity_batch' => IdentityStage::run(
                (string) $item->jurisdiction_id,
                (string) $run->id,
                $version,
            ),
            'election_scope' => ElectionStage::run(
                (string) $item->jurisdiction_id,
                (string) $run->id,
                $version,
            ),
            // Counting and seating carry an ELECTION, not a jurisdiction — the
            // election is the unit both the count batches over and
            // certification acts on. The id rides in `race_id`, which is the
            // item's spare reference column.
            'count_election' => CountingStage::run(
                (string) $item->race_id,
                (string) $run->id,
                $version,
            ),
            'seat_scope' => SeatingStage::run(
                (string) $item->race_id,
                (string) $run->id,
                $version,
            ),
            // The growth dial matures the PLACE (committees → K, departments →
            // D), so it is jurisdiction-scoped like cohort/seating, not
            // election-scoped like counting. It files the real F-LEG-009/014/016
            // acts and defers-with-reason where a chamber cannot yet grow.
            'governance_scope' => GovernanceStage::run(
                (string) $item->jurisdiction_id,
                (string) $run->id,
                $version,
            ),
            // Stages land here as they are built; an unknown kind is a REVIEW
            // row naming itself rather than a crash.
            default => throw new \RuntimeException("No stage is wired for item kind '{$item->kind}'."),
        };
    }

    private function settle(string $itemId, string $status, array $metrics = [], ?string $reason = null): void
    {
        DB::table('sim_items')->where('id', $itemId)->update([
            'status' => $status,
            'claim_token' => null,
            'metrics' => json_encode($metrics),
            'reason' => $reason,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function label(object $item): string
    {
        return Str::limit($item->kind.' · '.($item->jurisdiction_id ?? $item->race_id ?? '—'), 155, '');
    }
}
