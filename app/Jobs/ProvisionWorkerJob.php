<?php

namespace App\Jobs;

use App\Models\ProvisionRun;
use App\Services\Provision\LegislatureUnitProcessor;
use App\Services\Provision\ShellBatchProcessor;
use App\Support\ProvisionClaims;
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

        try {
            while (true) {
                if (Cache::get('provision.halt_requested') || $this->haltRequested()) {
                    break;
                }
                if (microtime(true) - $started > self::CLAIM_BUDGET_SECONDS) {
                    break;
                }
                if (memory_get_usage(true) > AutoscaleWorkerJob::MEMORY_RECYCLE_BYTES) {
                    break;
                }

                $claim = ProvisionClaims::next($run, $token, $this->lane);
                if ($claim === null) {
                    break;
                }

                $label = $claim['type'] === 'shell_batch'
                    ? "shell batch × {$claim['count']}"
                    : 'unit '.$claim['legislature_id'];
                $this->touch($token, [
                    'claim_type'             => $claim['type'],
                    'claim_label'            => $label,
                    'claim_started_at'       => now(),
                    'current_legislature_id' => $claim['type'] === 'unit' ? $claim['legislature_id'] : null,
                ]);

                try {
                    if ($claim['type'] === 'shell_batch') {
                        $shells->process($token, fn () => $this->touch($token, []));
                    } else {
                        $this->runUnit($units, $token, $claim['legislature_id']);
                    }
                } catch (\Throwable $e) {
                    $this->fail_claim($token, $claim, $e);
                }

                $this->touch($token, [
                    'claim_type' => null, 'claim_label' => null, 'claim_started_at' => null,
                    'current_legislature_id' => null,
                ], bumpClaims: true);
            }
        } finally {
            DB::table('provision_worker_leases')->where('id', $token)->delete();
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
