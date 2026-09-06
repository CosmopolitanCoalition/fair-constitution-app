<?php

namespace App\Jobs;

use App\Models\SimItem;
use App\Models\SimRun;
use App\Services\Demo\Stages\CohortStage;
use App\Services\Demo\Stages\CountingStage;
use App\Services\Demo\Stages\ElectionStage;
use App\Services\Demo\Stages\GovernanceStage;
use App\Services\Demo\Stages\SeatingStage;
use App\Services\Demo\Stages\TrainingStage;
use App\Services\Demo\Stages\IdentityStage;
use App\Support\SimClaims;
use App\Support\SimTimer;
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
        $prevEnd = null;          // hrtime mark at the end of the last claim
        $claimsSinceFlush = 0;    // flush the timer every ~25 claims

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

                // The gap between one claim's end and the next acquisition — a
                // lane sitting idle is a lane not working (Step 4's lesson).
                if ($prevEnd !== null) {
                    SimTimer::record('lane.between_claims', (int) round((hrtime(true) - $prevEnd) / 1000));
                }

                SimTimer::open('lane.claim_next');
                $item = SimClaims::next($run, $token);
                SimTimer::close('lane.claim_next');

                if ($item === null) {
                    break;
                }

                DB::table('sim_worker_leases')->where('id', $token)->update([
                    'claim_type' => $item->kind,
                    'claim_label' => $this->label($item),
                    'claim_started_at' => now(),
                    'last_seen_at' => now(),
                ]);

                // Per-stage timing: `stage.<kind>` is the whole execute for that
                // stage, so the page shows which stage owns the run's time.
                $part = 'stage.'.$item->kind;
                SimTimer::open($part);
                try {
                    $metrics = $this->execute($run, $item, $token);
                    SimTimer::close($part);
                    $this->settle($item->id, SimItem::STATUS_DONE, $metrics);
                    $failures = 0;
                } catch (\Throwable $e) {
                    SimTimer::close($part); // no-op if already closed
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

                $prevEnd = hrtime(true);
                if (++$claimsSinceFlush >= 25) {
                    SimTimer::flush((string) $run->id);
                    $claimsSinceFlush = 0;
                }
            }
        } finally {
            SimTimer::flush($this->runId); // survive the lane's exit
            // Best effort: if the connection died, the pump culls stale leases.
            try {
                DB::table('sim_worker_leases')->where('id', $token)->delete();
            } catch (\Throwable) {
            }
        }
    }

    /** Dispatch one claimed unit to its stage. */
    private function execute(SimRun $run, object $item, string $token): array
    {
        $options = $run->options ?? [];
        $version = (int) ($options['version'] ?? 1);

        // HEARTBEAT (W7 item 1): a closure the stage calls at each chunk
        // boundary so a long item keeps its lease fresh. Without it a worker
        // busy longer than the pump's 2-minute live horizon looks dead and is
        // double-dispatched. Mirrors ProvisionWorkerJob's $beat.
        $beat = fn () => $this->touch($token);

        return match ($item->kind) {
            'cohort_scope' => CohortStage::run(
                (string) $item->jurisdiction_id,
                (string) $run->id,
                $version,
                (int) ($options['turnout_pct'] ?? CohortStage::DEFAULT_TURNOUT_PCT),
                $beat,
            ),
            'identity_batch' => IdentityStage::run(
                (string) $item->jurisdiction_id,
                (string) $run->id,
                $version,
                $beat,
            ),
            'election_scope' => ElectionStage::run(
                (string) $item->jurisdiction_id,
                (string) $run->id,
                $version,
                $beat,
            ),
            // Counting and seating carry an ELECTION, not a jurisdiction — the
            // election is the unit both the count batches over and
            // certification acts on. The id rides in `race_id`, which is the
            // item's spare reference column.
            'count_election' => CountingStage::run(
                (string) $item->race_id,
                (string) $run->id,
                $version,
                $beat,
            ),
            'seat_scope' => SeatingStage::run(
                (string) $item->race_id,
                (string) $run->id,
                $version,
                $beat,
            ),
            // The growth dial matures the PLACE (committees → K, departments →
            // D), so it is jurisdiction-scoped like cohort/seating, not
            // election-scoped like counting. It files the real F-LEG-009/014/016
            // acts and defers-with-reason where a chamber cannot yet grow.
            'governance_scope' => GovernanceStage::run(
                (string) $item->jurisdiction_id,
                (string) $run->id,
                $version,
                $beat,
            ),
            // The bench (2026-08-08): F-LEG-017 creation + per-seat F-LEG-021
            // constituent nominations through the real forms — the sim's
            // courtrooms stop being empty shells. Jurisdiction-scoped like
            // governance; defers-with-reason where formation cannot pass yet.
            'judiciary_scope' => \App\Services\Demo\Stages\JudiciaryStage::run(
                (string) $item->jurisdiction_id,
                (string) $run->id,
                $version,
                $beat,
            ),
            // Census-flavored orgs + bills (2026-08-08, rubric B): real
            // per-capita rates, sampled rows, true counts in metrics.
            'civics_scope' => \App\Services\Demo\Stages\CivicsStage::run(
                (string) $item->jurisdiction_id,
                (string) $run->id,
                $version,
                $beat,
            ),
            // Pre-train the jurisdiction's seated holders so the walk shows a
            // trained fleet (W7 item 7). The catalog was armed at the phase
            // transition; this files F-EDU-001 per holder, idempotent.
            'training_scope' => TrainingStage::run(
                (string) $item->jurisdiction_id,
                (string) $run->id,
                $version,
                $beat,
            ),
            // The money plane (W7 item 8): the civic stipend for this
            // jurisdiction's residents, through the real F-TRE-004.
            'stipend_scope' => \App\Services\Demo\Stages\StipendStage::run(
                (string) $item->jurisdiction_id,
                (string) $run->id,
                $version,
                $beat,
            ),
            // Stages land here as they are built; an unknown kind is a REVIEW
            // row naming itself rather than a crash.
            default => throw new \RuntimeException("No stage is wired for item kind '{$item->kind}'."),
        };
    }

    /** HEARTBEAT (W7 item 1): keep the lease fresh mid-item so a long claim is
     * not mistaken for a dead worker. Mirrors ProvisionWorkerJob::touch. */
    private function touch(string $token): void
    {
        DB::table('sim_worker_leases')->where('id', $token)->update(['last_seen_at' => now()]);
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
