<?php

namespace App\Jobs;

use App\Models\AutoscaleRun;
use App\Services\Autoscale\AdjacencyPrecompute;
use App\Services\Autoscale\SinglesBatchProcessor;
use App\Services\Autoscale\SweepScopeProcessor;
use App\Support\AutoscaleClaims;
use App\Support\HostCapacity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A pull worker (re-engineering 2026-07-19): registers a lease, then claims
 * work from the ladder (AutoscaleClaims) in a loop — one unit at a time,
 * each claim atomic via FOR UPDATE SKIP LOCKED — until there is nothing to
 * claim, the run halts/pauses, or the claim budget elapses. Then it simply
 * exits; the pump re-seeds workers every minute.
 *
 * NO self-rescheduling, NO payload state: a worker that dies (OOM, SIGKILL,
 * horizon crash, box reboot) just drops its lease; its single in-flight
 * claim goes stale and the pump reclaims it minutes later. Crash-safety is
 * the contract — graceful mid-scope exit is not attempted (the per-scope
 * `_all` redraw makes any redo clean).
 *
 * Concurrency = the Horizon supervisor's process count. The width governor
 * and per-job release() gate are GONE (operator ruling 2026-07-19): one
 * limiter, no make-work.
 */
class AutoscaleWorkerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;
    public int $tries   = 1;

    /** Exit the claim loop after this long — the pump re-seeds fresh workers. */
    private const CLAIM_BUDGET_SECONDS = 3000;

    /** Consecutive claim failures before the worker exits (≈1-min backoff via the pump). */
    private const MAX_CONSECUTIVE_FAILURES = 3;

    private bool $stopping = false;

    public function __construct(private readonly string $runId)
    {
        $this->onQueue('autoscale');
    }

    public function handle(): void
    {
        $run = AutoscaleRun::query()->find($this->runId);
        if ($run === null || $run->status !== 'mapping') {
            return;
        }

        $token = (string) Str::uuid();
        DB::table('autoscale_worker_leases')->insert([
            'id'           => $token,
            'run_id'       => $run->id,
            'started_at'   => now(),
            'last_seen_at' => now(),
        ]);

        // Over-dispatch self-correction: the pump counts live leases before
        // seeding, but a redelivered payload (redis-long retry_after on a
        // >4 h worker) can still spawn a surplus copy — it sees the pool is
        // full and exits immediately.
        $alive = (int) DB::table('autoscale_worker_leases')
            ->where('run_id', $run->id)
            ->where('last_seen_at', '>', now()->subMinutes(2))
            ->count();
        if ($alive > HostCapacity::autoscaleWorkers()) {
            DB::table('autoscale_worker_leases')->where('id', $token)->delete();
            return;
        }

        // Deploy/restart: horizon:terminate SIGTERMs workers — exit at the
        // next claim boundary. (pcntl is CLI/Linux only; inline test runs
        // skip signal wiring.)
        if (\function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () {
                $this->stopping = true;
            });
        }

        $startedAt = time();
        $failures  = 0;

        try {
            while (true) {
                if (\function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
                if ($this->stopping) {
                    break;
                }
                if ((time() - $startedAt) > self::CLAIM_BUDGET_SECONDS) {
                    break;
                }

                // Fresh run state every iteration — halt/pause/terminal all
                // stop the loop at a claim boundary.
                $run->refresh();
                if ($run->status !== 'mapping' || $run->haltRequested() || $run->isPaused()) {
                    break;
                }

                $claim = AutoscaleClaims::next($run, $token);
                if ($claim === null) {
                    break;
                }

                try {
                    match ($claim['type']) {
                        'singles'    => app(SinglesBatchProcessor::class)->process($run, $token),
                        'finalize'   => app(SweepScopeProcessor::class)->finalize($run, $claim['item_id']),
                        'precompute' => app(AdjacencyPrecompute::class)->processParent($claim['parent_id']),
                        'scope'      => app(SweepScopeProcessor::class)->process($run, $claim),
                    };
                    $failures = 0;
                } catch (\Throwable $e) {
                    // The processors never rethrow work errors — reaching here
                    // means infrastructure trouble (connection loss, OOM-adjacent).
                    // Release the claim and back off; after a few in a row this
                    // worker exits and the pump's next minute seeds a fresh one.
                    $failures++;
                    Log::error('Autoscale worker claim error', [
                        'run_id' => $run->id, 'claim' => $claim['type'], 'message' => $e->getMessage(),
                    ]);
                    $this->releaseClaim($run, $token, $claim);
                    if ($failures >= self::MAX_CONSECUTIVE_FAILURES) {
                        break;
                    }
                }

                DB::table('autoscale_worker_leases')
                    ->where('id', $token)
                    ->update(['last_seen_at' => now()]);
            }
        } finally {
            try {
                DB::table('autoscale_worker_leases')->where('id', $token)->delete();
            } catch (\Throwable) {
                // A dropped connection loses the lease row's delete — the
                // pump prunes stale leases anyway.
            }
        }
    }

    /** Best-effort release after an infrastructure error mid-claim. */
    private function releaseClaim(AutoscaleRun $run, string $token, array $claim): void
    {
        try {
            match ($claim['type']) {
                'singles' => DB::table('autoscale_items')
                    ->where('run_id', $run->id)
                    ->where('kind', 'single')
                    ->where('status', 'running')
                    ->where('claim_token', $token)
                    ->update(['status' => 'pending', 'claim_token' => null, 'updated_at' => now()]),
                'finalize' => DB::table('autoscale_items')
                    ->where('id', $claim['item_id'])
                    ->where('status', 'assessing')
                    ->update(['status' => 'running', 'claim_token' => null, 'updated_at' => now()]),
                'precompute' => DB::table('jurisdiction_adjacency_parents')
                    ->where('parent_id', $claim['parent_id'])
                    ->where('status', 'running')
                    ->update(['status' => 'pending', 'claim_token' => null, 'updated_at' => now()]),
                'scope' => DB::table('autoscale_scopes')
                    ->where('id', $claim['scope_id'])
                    ->where('status', 'running')
                    ->update(['status' => 'pending', 'claim_token' => null, 'updated_at' => now()]),
            };
        } catch (\Throwable) {
            // The pump's stale-claim reclaim is the durable fallback.
        }
    }
}
