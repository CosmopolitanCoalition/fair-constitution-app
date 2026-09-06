<?php

namespace App\Console\Commands;

use App\Jobs\ProvisionWorkerJob;
use App\Models\ProvisionRun;
use App\Services\AuditService;
use App\Services\Provision\ProvisionRunControl;
use App\Support\HostCapacity;
use App\Support\ProvisionClaims;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The Step 4 pump (Wave 6) — the run's ONLY liveness root, the autoscale
 * pump's posture applied to institutions. Every minute: the halt/resume
 * state machine, the ledger materialization (chunked, resumable), the money
 * plane founding, dead-lane reclaims on backend absence, lane seeding to the
 * derived pool width, counters, the done flip. Each duty is idempotent and
 * seconds-long; if everything else crashes, the next minute heals it.
 */
class ProvisionPumpCommand extends Command
{
    protected $signature = 'provision:pump';

    protected $description = 'Advance the live Step 4 run: halt/resume, ledger seeding, reclaims, lane seeding, counters, done flip';

    /** Chunks of the ledger materialized per tick (25k rows each). */
    private const SEED_CHUNKS_PER_TICK = 8;

    public function handle(ProvisionRunControl $control): int
    {
        $run = $control->liveRun();
        if ($run === null) {
            return self::SUCCESS;
        }

        // ── Halt / resume (the DB column is the source of truth) ───────────
        if ($run->haltRequested() && $run->status !== ProvisionRun::STATUS_HALTED) {
            Cache::put('provision.halt_requested', true, 86400);
            $run->forceFill(['status' => ProvisionRun::STATUS_HALTED, 'updated_at' => now()])->save();
            Log::info('Step 4 run halted by operator', ['run_id' => (string) $run->id]);
        }
        if ($run->status === ProvisionRun::STATUS_HALTED) {
            if ($run->haltRequested()) {
                self::reapHaltedLanes($run);
                $control->refreshCounters($run);

                return self::SUCCESS;
            }
            $run->forceFill(['status' => ProvisionRun::STATUS_RUNNING, 'updated_at' => now()])->save();
        }
        if ($run->status === ProvisionRun::STATUS_QUEUED) {
            $run->forceFill(['status' => ProvisionRun::STATUS_RUNNING, 'started_at' => $run->started_at ?? now(), 'updated_at' => now()])->save();
        }
        Cache::forget('provision.halt_requested');

        // ── The ledger (chunked, latched once nothing is missing) ──────────
        if ($run->ledger_seeded_at === null) {
            $inserted = $control->materializeLedger($run, self::SEED_CHUNKS_PER_TICK);
            $missing = DB::table('legislatures as l')
                ->whereNull('l.deleted_at')
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')->from('provision_ledger as pl')->whereColumn('pl.legislature_id', 'l.id');
                })
                ->exists();
            if (! $missing) {
                $control->foundMoneyPlane();
                // The claim plans read the ledger's statistics (the ETL rule:
                // analyze before the dependent pass).
                DB::statement('ANALYZE provision_ledger');
                $run->forceFill(['ledger_seeded_at' => now(), 'updated_at' => now()])->save();
                Log::info('Step 4 ledger materialized', ['run_id' => (string) $run->id, 'inserted_this_tick' => $inserted]);
            } else {
                $control->refreshCounters($run);

                return self::SUCCESS; // next tick continues the seeding
            }
        }

        // ── Statistics while shells land (dry run 2026-09-06) ──────────────
        // The shell tables grow from near empty; without fresh statistics the
        // NOT EXISTS probes plan as sequential scans and every batch grows
        // slower than the last. One ANALYZE per table per minute, seconds.
        if (DB::table('provision_ledger')->where('stage', 0)->whereIn('status', ['pending', 'running'])->exists()) {
            \App\Services\Provision\ShellBatchProcessor::analyzeAll();
        }

        // ── Reclaims: rows held by lanes that are certainly gone ───────────
        $reclaimed = self::reclaimDeadRows();
        if ($reclaimed > 0) {
            Log::warning('Step 4 pump reclaimed dead-lane rows', ['run_id' => (string) $run->id, 'count' => $reclaimed]);
        }
        // The reaper: a lease whose heartbeat is stale AND whose backend is
        // gone is a certain corpse; its rows were returned above.
        DB::delete('
            DELETE FROM provision_worker_leases l
             WHERE l.last_seen_at < ?
               AND (l.pg_backend_pid IS NULL
                    OR NOT EXISTS (SELECT 1 FROM pg_stat_activity a WHERE a.pid = l.pg_backend_pid))
        ', [now()->subMinutes(2)]);

        // ── Lane seeding: keep the derived pool topped up (two-ended) ──────
        if (! $run->isPaused() && ProvisionClaims::claimableWork()) {
            $target       = HostCapacity::autoscaleWorkers();
            $targetBottom = intdiv($target, 2);
            $targetTop    = $target - $targetBottom;
            $fresh = DB::table('provision_worker_leases')
                ->where('run_id', (string) $run->id)
                ->where('last_seen_at', '>', now()->subMinutes(2))
                ->selectRaw("COUNT(*) FILTER (WHERE lane = 'bottomup') AS bottom,
                             COUNT(*) FILTER (WHERE lane <> 'bottomup') AS top")
                ->first();
            for ($i = 0; $i < ($targetTop - (int) ($fresh->top ?? 0)); $i++) {
                ProvisionWorkerJob::dispatch((string) $run->id, ProvisionClaims::LANE_TOPDOWN);
            }
            for ($i = 0; $i < ($targetBottom - (int) ($fresh->bottom ?? 0)); $i++) {
                ProvisionWorkerJob::dispatch((string) $run->id, ProvisionClaims::LANE_BOTTOMUP);
            }
        }

        // ── Counters + completion ──────────────────────────────────────────
        $counts = $control->refreshCounters($run);
        if (! ProvisionClaims::openWork()) {
            $elapsed = $run->started_at !== null ? (int) now()->diffInSeconds($run->started_at, true) : null;
            $run->forceFill([
                'status'      => ProvisionRun::STATUS_DONE,
                'finished_at' => now(),
                'baseline'    => ['elapsed_seconds' => $elapsed, 'units_done' => (int) $counts->units_done,
                                  'review' => (int) $counts->review_count, 'skipped' => (int) $counts->skipped,
                                  'lanes' => HostCapacity::autoscaleWorkers()],
                'updated_at'  => now(),
            ])->save();

            app(AuditService::class)->append(
                module: 'jurisdictions',
                event: 'provision.completed',
                payload: [
                    'run_id'       => (string) $run->id,
                    'units_done'   => (int) $counts->units_done,
                    'review_count' => (int) $counts->review_count,
                    'skipped'      => (int) $counts->skipped,
                    'elapsed_s'    => $elapsed,
                    'generator'    => 'ProvisionPumpCommand (Wave 6)',
                ],
                ref: 'WF-JUR-01',
            );
            Log::info('Step 4 run complete', ['run_id' => (string) $run->id, 'elapsed_s' => $elapsed]);
        }

        return self::SUCCESS;
    }

    /**
     * A running row returns to the pile only when its lane is certainly gone:
     * no token, no lease, or a lease whose recorded backend left
     * pg_stat_activity. A live lane deep in one long statement is never
     * touched. Three reclaims park the row in review.
     */
    public static function reclaimDeadRows(): int
    {
        return DB::update("
            WITH dead AS (
                SELECT pl.legislature_id
                  FROM provision_ledger pl
                  LEFT JOIN provision_worker_leases wl ON wl.id = pl.claim_token
                 WHERE pl.status = 'running'
                   AND (pl.claim_token IS NULL
                        OR wl.id IS NULL
                        OR (wl.pg_backend_pid IS NOT NULL
                            AND NOT EXISTS (SELECT 1 FROM pg_stat_activity a WHERE a.pid = wl.pg_backend_pid))
                        OR (wl.pg_backend_pid IS NULL AND wl.last_seen_at < now() - interval '10 minutes'))
                 FOR UPDATE OF pl SKIP LOCKED
            )
            UPDATE provision_ledger pl
               SET status = CASE WHEN pl.retry_count + 1 >= 3 THEN 'review' ELSE 'pending' END,
                   retry_count = pl.retry_count + 1,
                   claim_token = NULL,
                   reason = CASE WHEN pl.retry_count + 1 >= 3
                                 THEN 'reclaimed 3 times: lane died mid-claim' ELSE 'reclaimed: lane died mid-claim' END,
                   updated_at = now()
              FROM dead d
             WHERE pl.legislature_id = d.legislature_id
        ");
    }

    /** A parked run owns no lanes (THE ESCAPE-HATCH LAW). */
    public static function reapHaltedLanes(ProvisionRun $run): void
    {
        if ($run->halt_requested_at !== null && $run->halt_requested_at->lt(now()->subMinutes(2))) {
            DB::select("
                SELECT pg_terminate_backend(a.pid)
                  FROM provision_worker_leases l
                  JOIN pg_stat_activity a ON a.pid = l.pg_backend_pid
                 WHERE l.run_id = ?::uuid AND a.usename = current_user AND a.pid <> pg_backend_pid()
            ", [(string) $run->id]);
        }
        DB::delete("
            DELETE FROM provision_worker_leases l
             WHERE l.run_id = ?::uuid
               AND CASE WHEN l.pg_backend_pid IS NOT NULL
                        THEN NOT EXISTS (SELECT 1 FROM pg_stat_activity a WHERE a.pid = l.pg_backend_pid)
                        ELSE l.last_seen_at < now() - interval '2 minutes' END
        ", [(string) $run->id]);
        DB::update("
            UPDATE provision_ledger pl
               SET status = 'pending', claim_token = NULL, updated_at = now()
             WHERE pl.status = 'running'
               AND NOT EXISTS (SELECT 1 FROM provision_worker_leases l WHERE l.id = pl.claim_token)
        ");
    }
}
