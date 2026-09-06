<?php

namespace App\Jobs;

use App\Models\ProvisionRun;
use App\Services\Provision\LegislatureUnitProcessor;
use App\Services\Provision\ShellBatchProcessor;
use App\Support\ProvisionClaims;
use App\Support\ProvisionTimer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A Step 4 lane (Wave 6): registers a lease, claims from the ledger until the
 * pile is dry, the halt flag is set, the claim budget is spent or memory
 * grows past the recycle bound, then exits. The pump seeds replacements every
 * minute against claimable work, so a lane never needs to outlive its budget.
 * Rides the autoscale queue (redis-long): its retry_after is hours, so a
 * long batch is never ghost-redelivered.
 */
class ProvisionWorkerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Same posture as the districting lane: the supervisor's timeout derives from the autoscale job. */
    public const CLAIM_BUDGET_SECONDS = AutoscaleWorkerJob::CLAIM_BUDGET_SECONDS;

    public int $timeout = 2 * self::CLAIM_BUDGET_SECONDS;

    public int $tries = 1;

    /**
     * Recycle the lane (exit; the pump dispatches a fresh process) at 256 MB,
     * well below the 480 MB districting bound and the PHP limit, so a single
     * big chamber's unit has room to allocate its hundreds of models without
     * hitting the fatal. A fresh process reclaims everything.
     */
    private const LANE_RECYCLE_BYTES = 256 * 1048576;

    public function __construct(private readonly string $runId, private readonly string $lane = ProvisionClaims::LANE_TOPDOWN)
    {
        $this->onQueue('autoscale');
    }

    public function handle(ShellBatchProcessor $shells, LegislatureUnitProcessor $units): void
    {
        $run = ProvisionRun::query()->find($this->runId);
        if ($run === null || $run->status !== ProvisionRun::STATUS_RUNNING) {
            return;
        }

        $token   = (string) Str::uuid();
        $started = microtime(true);

        DB::statement('
            INSERT INTO provision_worker_leases (id, run_id, lane, pg_backend_pid, started_at, last_seen_at)
            VALUES (?::uuid, ?::uuid, ?, pg_backend_pid(), now(), now())
        ', [$token, $this->runId, $this->lane]);

        // THE BULK-LOAD COMMIT LEVER (dry-run fix 2026-09-06): a unit files its
        // election, committees and departments as separate audited
        // transactions, each fsync-committed. On a slow disk that fsync, times
        // the audit chain's one global appender, capped the unit phase at ~2/s.
        // Provisioning is idempotent and resumable, so a crash that loses the
        // last few un-flushed commits costs only a re-run of those units on the
        // pump's reclaim — never corruption. synchronous_commit off drops the
        // per-commit fsync; the WAL still flushes within a second. Reset in the
        // finally so a later districting job on this worker keeps full durability.
        DB::statement('SET synchronous_commit = off');

        $sinceFlush = 0;
        $lastFlush = microtime(true);
        $gapStart = microtime(true);

        try {
            while (true) {
                if (Cache::get('provision.halt_requested') || $this->haltRequested()) {
                    break;
                }
                if (microtime(true) - $started > self::CLAIM_BUDGET_SECONDS) {
                    break;
                }
                // A PROVISION LANE RECYCLES EARLY (dry-run fix 2026-09-06): a
                // big chamber's unit files hundreds of races, committees and
                // departments in one iteration and can allocate a few hundred
                // MB on top of the baseline. The between-unit check must leave
                // that much headroom under the PHP limit, so a lane exits (a
                // fresh process reclaims all of it) well before a big unit can
                // push it over. gc_collect_cycles below keeps the baseline low.
                if (memory_get_usage(true) > self::LANE_RECYCLE_BYTES) {
                    break;
                }

                ProvisionTimer::open('lane.claim_next');
                $claim = ProvisionClaims::next($run, $token, $this->lane);
                ProvisionTimer::close('lane.claim_next');
                if ($claim === null) {
                    break;
                }
                // The whole gap since the previous claim ended: acquisition plus
                // the touch and gc overhead — how long the lane sat idle.
                ProvisionTimer::record('lane.between_claims', (int) round((microtime(true) - $gapStart) * 1_000_000));

                $label = $claim['type'] === 'shell_batch'
                    ? "shell batch × {$claim['count']}"
                    : 'unit '.$claim['legislature_id'];
                $this->touch($token, [
                    'claim_type'             => $claim['type'],
                    'claim_label'            => $label,
                    'claim_started_at'       => now(),
                    'current_legislature_id' => $claim['type'] === 'unit' ? $claim['legislature_id'] : null,
                ]);

                ProvisionTimer::open($claim['type'] === 'shell_batch' ? 'claim.shell_batch' : 'claim.unit');
                try {
                    if ($claim['type'] === 'shell_batch') {
                        $shells->process($token, fn () => $this->touch($token, []));
                    } else {
                        $this->runUnit($units, $token, $claim['legislature_id']);
                    }
                } catch (\Throwable $e) {
                    $this->fail_claim($token, $claim, $e);
                }
                ProvisionTimer::close($claim['type'] === 'shell_batch' ? 'claim.shell_batch' : 'claim.unit');

                $this->touch($token, [
                    'claim_type' => null, 'claim_label' => null, 'claim_started_at' => null,
                    'current_legislature_id' => null,
                ], bumpClaims: true);

                // Reclaim the cyclic references a unit's Eloquent models leave,
                // so the baseline stays low between claims and one big unit
                // never starts from a high-water mark.
                gc_collect_cycles();

                $gapStart = microtime(true);
                // Flush the timings often so the page reveals them within a
                // poll or two, even when the rate is slow: every 10 claims or
                // every 15 seconds, whichever comes first.
                if (++$sinceFlush >= 10 || microtime(true) - $lastFlush > 15) {
                    ProvisionTimer::flush($this->runId);
                    $sinceFlush = 0;
                    $lastFlush = microtime(true);
                }
            }
        } finally {
            ProvisionTimer::flush($this->runId);
            // Restore full durability for whatever this worker runs next.
            try {
                DB::statement('SET synchronous_commit = on');
            } catch (\Throwable) {
                // A dead connection resets to the default on its own.
            }
            DB::table('provision_worker_leases')->where('id', $token)->delete();
        }

        // SELF-RESPAWN (the pull-engine pattern): a lane that exited on its
        // budget or the memory recycle dispatches its own replacement so the
        // pool stays full without waiting for the next pump minute. Not after a
        // halt, and only while claimable work remains — the pump owns the tail.
        if (! Cache::get('provision.halt_requested') && ! $this->haltRequested() && ProvisionClaims::claimableWork()) {
            self::dispatch($this->runId, $this->lane);
        }
    }

    private function runUnit(LegislatureUnitProcessor $units, string $token, string $legislatureId): void
    {
        $result = $units->process($legislatureId, fn () => $this->touch($token, []));

        DB::table('provision_ledger')
            ->where('legislature_id', $legislatureId)
            ->where('claim_token', $token)
            ->update([
                'stage'       => 2,
                'status'      => $result['status'],
                'claim_token' => null,
                'finished_at' => now(),
                'reason'      => $result['reason'],
                'manifest'    => json_encode($result['manifest']),
                'updated_at'  => now(),
            ]);
    }

    /** A thrown claim files as review (engine-shaped failures never auto-retry). */
    private function fail_claim(string $token, array $claim, \Throwable $e): void
    {
        $reason = mb_substr(get_class($e).': '.$e->getMessage(), 0, 900);
        Log::warning('Step 4 lane claim failed', ['claim' => $claim, 'error' => $reason]);

        if ($claim['type'] === 'shell_batch') {
            // The batch returns to the pile; a repeated failure parks it.
            DB::update("
                UPDATE provision_ledger
                   SET status = CASE WHEN retry_count + 1 >= 3 THEN 'review' ELSE 'pending' END,
                       retry_count = retry_count + 1, claim_token = NULL, reason = ?, updated_at = now()
                 WHERE claim_token = ?::uuid AND status = 'running'
            ", [$reason, $token]);

            return;
        }

        DB::table('provision_ledger')
            ->where('legislature_id', $claim['legislature_id'])
            ->where('claim_token', $token)
            ->update([
                'status'      => 'review',
                'claim_token' => null,
                'finished_at' => now(),
                'reason'      => $reason,
                'updated_at'  => now(),
            ]);
    }

    private function haltRequested(): bool
    {
        return DB::table('provision_runs')->where('id', $this->runId)->whereNotNull('halt_requested_at')->exists();
    }

    private function touch(string $token, array $patch, bool $bumpClaims = false): void
    {
        $patch['last_seen_at'] = now();
        if ($bumpClaims) {
            $patch['claims_done'] = DB::raw('claims_done + 1');
        }
        DB::table('provision_worker_leases')->where('id', $token)->update($patch);
    }
}
