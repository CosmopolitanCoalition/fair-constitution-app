<?php

namespace App\Jobs;

use App\Models\AutoscaleRun;
use App\Services\Autoscale\AdjacencyPrecompute;
use App\Services\Autoscale\AutoscaleRunControl;
use App\Services\Autoscale\SinglesBatchProcessor;
use App\Services\Autoscale\SweepScopeProcessor;
use App\Support\AutoscaleClaims;
use App\Support\HostCapacity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Database\LostConnectionException;
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
 * horizon crash, box reboot) just drops its lease; its postgres backend
 * leaves pg_stat_activity and the pump reclaims its in-flight scope on that
 * evidence (backend absence, never a timer). Crash-safety is the contract —
 * graceful mid-scope exit is not attempted (the per-scope `_all` redraw
 * makes any redo clean).
 *
 * THE CLAIM-TOKEN GUARD (operator order 2026-09-02): the lease id is the
 * lane's token; every scope state write repeats it and checks the row count,
 * so a lane whose scope was reclaimed or killed writes nothing further for it.
 *
 * THE LEASE IS THE LANE'S LIFE (2026-09-02, the lease-less zombie): every
 * lease touch (at claim start, before each batch scope, after each claim)
 * checks its row count. Zero rows means a kill or a reap removed the lease;
 * the lane releases what it still holds under its token and ENDS. A lane
 * never claims without a lease, so the pump's lease-missing reclaim can
 * never fire on a scope a live lane is working, and the lease count the
 * pump seeds against is always the count of lanes actually working. The
 * lease also names the scope in hand (current_scope_id) and restarts
 * claim_started_at before each batch scope, so a kill parks exactly that
 * scope and a deadline measures that scope.
 *
 * THE CONNECTION GUARD (2026-09-02, the kill that restarted its work): the
 * framework re-runs a statement on a fresh session when the connection is
 * lost outside a transaction (Connection::tryAgainIfCausedByLostConnection).
 * On a lane that retry resumes work on a scope that just left it: a kill
 * parked it, or a postgres restart let the pump reclaim it. The guard
 * replaces the connection's reconnector for the lane's lifetime: a lost
 * session throws instead of retrying, the session stays dead for the rest
 * of the claim whatever catch blocks lie between, and the worker's catch
 * ends the lane. The finally block restores the reconnector, reconnects,
 * deletes the lease and respawns while work remains.
 *
 * Concurrency = the Horizon supervisor's process count. The width governor
 * and per-job release() gate are GONE (operator ruling 2026-07-19): one
 * limiter, no make-work.
 */
class AutoscaleWorkerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Exit the claim loop after this long — the pump re-seeds fresh workers.
     * Public: config/horizon.php derives the supervisor timeout from it.
     */
    public const CLAIM_BUDGET_SECONDS = 3000;

    /**
     * Job timeout = twice the claim budget (operator order 2026-09-02). The
     * budget is tested between claims; a lane still inside ONE claim at
     * twice the budget is killed by the queue worker, its backend leaves
     * pg_stat_activity, and the pump reclaims the scope on that evidence.
     * Derived, never a second number.
     */
    public int $timeout = 2 * self::CLAIM_BUDGET_SECONDS;

    public int $tries = 1;

    /**
     * Recycle the worker once the PROCESS holds this much memory (2026-07-22,
     * the monster-claim fatals): one worker loops through dozens of scopes and
     * memory creeps across claims — the 768M fatals hit workers ALREADY at the
     * limit trying to allocate kilobytes (a fresh process runs the same scope
     * at ~80M peak). Exiting at a claim boundary is free: the lease clears and
     * the pump seeds a fresh worker within a minute. 480M leaves ~3x headroom
     * over the largest observed fresh-process peak (~140M, monster class).
     * Tested after every claim AND between the scopes of a batch claim
     * (operator order 2026-09-02) — never mid-scope.
     */
    public const MEMORY_RECYCLE_BYTES = 480 * 1048576;

    /** Consecutive claim failures before the worker exits (≈1-min backoff via the pump). */
    private const MAX_CONSECUTIVE_FAILURES = 3;

    /**
     * idle_in_transaction_session_timeout for the lane's session (operator
     * order 2026-09-02): a lane that holds a transaction open and idle this
     * long is disconnected by postgres; the connection guard turns that into
     * the end of the lane and the pump reclaims its scope.
     */
    private const IDLE_IN_TRANSACTION_SECONDS = 60;

    private bool $stopping = false;

    /** A lease touch affected no row: a kill or a reap removed the lease. The lane ends. */
    private bool $leaseLost = false;

    /** The lane's database session died. The lane ends; the cleanup reconnects first. */
    private bool $connectionLost = false;

    public function __construct(private readonly string $runId, private readonly string $lane = 'auto')
    {
        $this->onQueue('autoscale');
    }

    public function handle(): void
    {
        $run = AutoscaleRun::query()->find($this->runId);
        if ($run === null || $run->status !== 'mapping') {
            return;
        }

        // The lane's own connection, guarded for the lane's lifetime (THE
        // CONNECTION GUARD above). The guard goes on first, so nothing the
        // lane does can run on a silently replaced session.
        $conn = DB::connection();
        self::installConnectionGuard($conn);

        $token     = (string) Str::uuid();
        $respawn   = true;
        $startedAt = time();
        $failures  = 0;

        try {
            // Per-lane session settings on the lane's own connection: work_mem
            // and the two timeouts. Applied before the lease so the lease's
            // backend pid is the configured session's pid.
            $this->applyLaneSessionSettings();

            // THE REAPER'S EYES (operator order 2026-08-30): the lane's own
            // postgres backend pid rides on the lease, so the pump can tell a
            // dead worker (backend gone) from a quiet grinder (backend present,
            // deep in one long query) with certainty instead of timers.
            $this->registerLease($token, $run);

            // Over-dispatch self-correction: the pump counts live leases before
            // seeding, but a redelivered payload (redis-long retry_after on a
            // >4 h worker) can still spawn a surplus copy — it sees the pool is
            // full and exits immediately, and does not respawn.
            if ($this->freshLeases($run, null) > HostCapacity::autoscaleWorkers()) {
                DB::table('autoscale_worker_leases')->where('id', $token)->delete();
                $respawn = false;

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
                // After every claim (this is the top of the next iteration).
                if ($this->memoryExceeded()) {
                    Log::info('Autoscale worker recycling on memory', [
                        'run_id' => $run->id,
                        'bytes'  => memory_get_usage(true),
                    ]);
                    break;
                }

                // Fresh run state every iteration — halt/pause/terminal all
                // stop the loop at a claim boundary.
                $run->refresh();
                if ($run->status !== 'mapping' || $run->haltRequested() || $run->isPaused()) {
                    break;
                }

                // CONNECTION HYGIENE AT THE CLAIM BOUNDARY (2026-08-31, the
                // Bosnia four-canton 25P02): every claim starts at
                // transaction level ZERO — an aborted residue from the
                // previous claim poisons every statement after it while the
                // culprit's own error stays swallowed.
                if (DB::transactionLevel() > 0) {
                    Log::warning('Autoscale lane holds an open transaction at the claim boundary — rolling back', [
                        'run_id' => $run->id, 'lane' => $this->lane,
                        'tx_level' => DB::transactionLevel(),
                    ]);
                    while (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }
                }

                $claim = AutoscaleClaims::next($run, $token, $this->lane);
                if ($claim === null) {
                    break;
                }

                // Claim visibility (operator ask 2026-07-19): the dashboard
                // renders one line per worker from the lease row — fast
                // sweeps blink through the scope list, but THIS shows what
                // every worker holds at any instant. THE LEASE TOUCH IS A
                // LIVENESS TEST: zero rows means the lease is gone (killed or
                // reaped) while this claim was taken. The claim is released
                // under this token and the lane ends; nothing is worked.
                $touched = $this->touchLease($token, [
                    'claim_type'       => $claim['type'],
                    // 160 = the column's width; a longer breadcrumb must
                    // truncate, never error (the Bosnia 25P02, 2026-08-31).
                    'claim_label'      => mb_substr($this->claimLabel($claim), 0, 160),
                    'claim_started_at' => now(),
                    'last_seen_at'     => now(),
                    'pg_backend_pid'   => DB::raw('pg_backend_pid()'),
                ], $claim['type'] === 'scope' ? (string) $claim['scope_id'] : null);
                if (! $touched) {
                    Log::warning('Autoscale lane found its lease gone at claim start: claim released, lane ends', [
                        'run_id' => $run->id, 'lane' => $this->lane, 'claim' => $claim['type'], 'token' => $token,
                    ]);
                    $this->releaseClaim($run, $token, $claim);
                    break;
                }

                $recycle = false;
                try {
                    $recycle = $this->runClaim($run, $claim, $token);
                    $failures = 0;
                } catch (\Throwable $e) {
                    if (SweepScopeProcessor::isLostConnection($e) || ! $this->connectionAlive()) {
                        // THE LANE ENDS WITH ITS SESSION (operator order
                        // 2026-09-02): a kill or an idle-in-transaction
                        // timeout terminated this session. The claim is
                        // abandoned with NO write: the kill parked its scope,
                        // or the pump reclaims it on backend absence (and
                        // carries the retry count). The finally block
                        // reconnects for the cleanup and respawns the lane.
                        $this->connectionLost = true;
                        Log::warning('Autoscale lane lost its database session mid-claim: claim abandoned, lane ends', [
                            'run_id' => $run->id, 'lane' => $this->lane, 'claim' => $claim['type'],
                            'token' => $token, 'message' => mb_substr($e->getMessage(), 0, 300),
                        ]);
                        break;
                    }
                    $failures++;
                    // The processors never rethrow work errors — reaching here
                    // means infrastructure trouble (OOM-adjacent, a redis
                    // fault). Release the claim and back off; after a few in
                    // a row this worker exits and the pump's next minute
                    // seeds a fresh one.
                    Log::error('Autoscale worker claim error', [
                        'run_id' => $run->id, 'claim' => $claim['type'], 'message' => $e->getMessage(),
                    ]);
                    $this->releaseClaim($run, $token, $claim);
                    if ($failures >= self::MAX_CONSECUTIVE_FAILURES) {
                        break;
                    }
                }

                if ($this->leaseLost) {
                    Log::warning('Autoscale lane found its lease gone mid-batch: remainder released, lane ends', [
                        'run_id' => $run->id, 'lane' => $this->lane, 'token' => $token,
                    ]);
                    break;
                }

                if (! $this->touchLease($token, [
                    'last_seen_at'     => now(),
                    'claim_type'       => null,
                    'claim_label'      => null,
                    'claim_started_at' => null,
                    'pg_backend_pid'   => DB::raw('pg_backend_pid()'),
                ], null)) {
                    Log::warning('Autoscale lane found its lease gone after a claim: lane ends', [
                        'run_id' => $run->id, 'lane' => $this->lane, 'token' => $token,
                    ]);
                    break;
                }

                if ($recycle) {
                    Log::info('Autoscale worker recycling on memory after a batch claim', [
                        'run_id' => $run->id,
                        'bytes'  => memory_get_usage(true),
                    ]);
                    break;
                }
            }
        } catch (\Throwable $e) {
            // Only a claim-boundary statement can throw to here (the loop
            // catches its own claims). A lost session ends the lane through
            // the cleanup below; anything else is a real fault and surfaces.
            if (! SweepScopeProcessor::isLostConnection($e) && $this->connectionAlive()) {
                throw $e;
            }
            $this->connectionLost = true;
            Log::warning('Autoscale lane lost its database session at a claim boundary: lane ends', [
                'run_id' => $run->id, 'lane' => $this->lane, 'token' => $token,
                'message' => mb_substr($e->getMessage(), 0, 300),
            ]);
        } finally {
            // THE CLEANUP RUNS ON A LIVE SESSION: the guard comes off first,
            // then a dead session is replaced (DatabaseManager::reconnect
            // builds a fresh PDO; it does not use the reconnector).
            self::restoreConnectionReconnector($conn);
            if ($this->connectionLost) {
                try {
                    DB::reconnect();
                } catch (\Throwable) {
                    // Postgres is still down: the pump's backend-absence
                    // reclaim and lease prune cover this lane.
                }
            }

            try {
                DB::table('autoscale_worker_leases')->where('id', $token)->delete();
            } catch (\Throwable) {
                // A session that cannot be rebuilt loses the lease row's
                // delete — the pump prunes stale leases anyway.
            }

            // THE LANE SIGNALS ITS SUCCESSOR (operator order 2026-08-30,
            // benchmark 3): a lane exiting for a benign reason — claim-time
            // budget, memory recycle, a momentarily empty pile, a lost lease
            // or session — dispatches its own replacement while work
            // remains, so the pool sustains itself with no scheduler and the
            // fan-out after a serial phase gets eaten the second it appears.
            // Guards: the run must still be mapping and unhalted, work must
            // exist, and the startup over-dispatch check culls any surplus.
            // A stopping (SIGTERM) exit never respawns — deploys stay clean;
            // a surplus copy that exited at startup never respawns either.
            try {
                if ($respawn && ! $this->stopping) {
                    $run?->refresh();
                    if ($run !== null && $run->status === 'mapping'
                        && ! $run->haltRequested() && ! $run->isPaused()
                        && AutoscaleClaims::claimableWork($run)) {
                        self::dispatch($this->runId, $this->lane);
                    }
                }
            } catch (\Throwable) {
                // Respawn is best-effort; the pump remains the backstop.
            }
        }
    }

    /**
     * Run one claim. Returns true when the lane must recycle after this
     * claim (the memory test fired between the scopes of a batch).
     */
    private function runClaim(AutoscaleRun $run, array $claim, string $token): bool
    {
        switch ($claim['type']) {
            case 'singles':
                app(SinglesBatchProcessor::class)->process($run, $token);

                return false;
            case 'finalize':
                app(SweepScopeProcessor::class)->finalize($run, $claim['legislature_id'], $token);

                return false;
            case 'precompute':
                app(AdjacencyPrecompute::class)->processParent($claim['parent_id']);

                return false;
            case 'scope':
                app(SweepScopeProcessor::class)->process($run, $claim, $token);

                return false;
            case 'scope_batch':
                return $this->runScopeBatch($run, $claim, $token);
        }

        throw new \UnexpectedValueException('Unknown autoscale claim type: '.$claim['type']);
    }

    /**
     * Two-cutter batch (2026-08-29): one claim, up to 100 small leaf splits,
     * drawn one by one through the untouched per-scope engine. BETWEEN
     * SCOPES, NEVER MID-SCOPE (operator order 2026-09-02): the memory
     * recycle test runs before every scope after the first; the lease touch
     * runs before EVERY scope and names the scope in hand (current_scope_id)
     * with a fresh claim_started_at, so a kill parks that one scope and a
     * deadline measures that one scope; the halt test runs every fifth. On
     * any stop the untouched remainder returns to the pile under this lane's
     * token. Returns true when the stop was the memory test (the lane
     * recycles). A lease touch affecting no row sets leaseLost: the lane
     * ends after the remainder is released.
     */
    private function runScopeBatch(AutoscaleRun $run, array $claim, string $token): bool
    {
        $scopes = array_values($claim['scopes']);
        $total  = count($scopes);
        foreach ($scopes as $i => $sc) {
            if ($i > 0 && $this->memoryExceeded()) {
                $this->releaseBatchRemainder(array_slice($scopes, $i), $token);
                Log::info('Autoscale lane stopped a batch on memory between scopes', [
                    'run_id' => $run->id, 'done' => $i, 'total' => $total, 'bytes' => memory_get_usage(true),
                ]);

                return true;
            }
            $touched = $this->touchLease($token, [
                'claim_label'      => '2-cut batch '.($i + 1).'/'.$total,
                'claim_started_at' => now(),
                'last_seen_at'     => now(),
                'pg_backend_pid'   => DB::raw('pg_backend_pid()'),
            ], (string) $sc['scope_id']);
            if (! $touched) {
                $this->leaseLost = true;
                $this->releaseBatchRemainder(array_slice($scopes, $i), $token);

                return false;
            }
            if ($i % 5 === 4 && $run->refresh()->haltRequested()) {
                $this->releaseBatchRemainder(array_slice($scopes, $i), $token);

                return false;
            }
            app(SweepScopeProcessor::class)->process($run, $sc, $token);
        }

        return false;
    }

    /** Untouched batch scopes go back to pending — only those still running under this token. */
    private function releaseBatchRemainder(array $remaining, string $token): void
    {
        $ids = array_map(static fn (array $s) => (string) $s['scope_id'], $remaining);
        if ($ids === []) {
            return;
        }
        DB::table('apportionment_ledger_scopes')
            ->whereIn('id', $ids)
            ->where('status', 'running')
            ->where('claim_token', $token)
            ->update(['status' => 'pending', 'claim_token' => null, 'started_at' => null, 'updated_at' => now()]);
    }

    /**
     * Update this lane's lease row and report whether it still exists. THE
     * LEASE IS THE LANE'S LIFE: zero rows means a kill or a reap removed it,
     * and the caller ends the lane. $currentScopeId names the scope in hand
     * (null between claims and for non-scope claims); it is written once the
     * lane-control migration has added the column.
     */
    private function touchLease(string $token, array $patch, ?string $currentScopeId): bool
    {
        if (AutoscaleRunControl::laneControlColumnsPresent()) {
            $patch['current_scope_id'] = $currentScopeId;
        }

        return DB::table('autoscale_worker_leases')->where('id', $token)->update($patch) > 0;
    }

    private function memoryExceeded(): bool
    {
        return memory_get_usage(true) > self::MEMORY_RECYCLE_BYTES;
    }

    /**
     * Session settings on the lane's own connection, applied once at lane
     * start (a replaced session ends the lane, so they never go stale):
     *  - work_mem (lane 2G's audit row 4, operator order 2026-08-30): the
     *    global setting stays web-tier conservative; a districting lane's
     *    session sorts and hashes bigger. Derived, session-scoped.
     *  - idle_in_transaction_session_timeout 60 s and statement_timeout at
     *    the second lane warning mark (operator order 2026-09-02).
     * SET is a utility statement — no bind parameters; every value is an
     * int from our own derivation. Never fatal: the global defaults stand.
     */
    private function applyLaneSessionSettings(): void
    {
        try {
            DB::statement(sprintf("SET work_mem = '%dMB'", HostCapacity::laneWorkMemMb()));
        } catch (\Throwable) {
            // The global default stands.
        }
        try {
            DB::statement(sprintf("SET idle_in_transaction_session_timeout = '%ds'", self::IDLE_IN_TRANSACTION_SECONDS));
            DB::statement(sprintf("SET statement_timeout = '%ds'", self::laneStatementTimeoutSeconds()));
        } catch (\Throwable) {
            // The global defaults stand.
        }
    }

    /** The second lane warning mark (cga.lane_warn_seconds[1]) is the lane's statement_timeout. */
    public static function laneStatementTimeoutSeconds(): int
    {
        $marks  = array_values((array) config('cga.lane_warn_seconds', [300, 900]));
        $second = (int) ($marks[1] ?? 900);

        return $second > 0 ? $second : 900;
    }

    /**
     * Register this lane's lease with the CURRENT backend pid. Idempotent on
     * the lease id.
     */
    private function registerLease(string $token, AutoscaleRun $run): void
    {
        DB::statement('
            INSERT INTO autoscale_worker_leases (id, run_id, lane, pg_backend_pid, started_at, last_seen_at)
            VALUES (?::uuid, ?::uuid, ?, pg_backend_pid(), now(), now())
            ON CONFLICT (id) DO UPDATE
               SET pg_backend_pid = pg_backend_pid(), last_seen_at = now(),
                   claim_type = NULL, claim_label = NULL, claim_started_at = NULL
        ', [$token, (string) $run->id, $this->lane]);
    }

    /** Fresh leases of this run (heartbeat within two minutes), optionally excluding one lease. */
    private function freshLeases(AutoscaleRun $run, ?string $exceptToken): int
    {
        return (int) DB::table('autoscale_worker_leases')
            ->where('run_id', $run->id)
            ->where('last_seen_at', '>', now()->subMinutes(2))
            ->when($exceptToken !== null, fn ($q) => $q->where('id', '!=', $exceptToken))
            ->count();
    }

    /**
     * One round trip on the lane's session, after rolling back any aborted
     * transaction residue (an aborted transaction answers every statement
     * with 25P02 and is not a dead session). False under the guard when the
     * session is dead.
     */
    private function connectionAlive(): bool
    {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $pdo = DB::getPdo();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            DB::selectOne('SELECT 1 AS ok');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * THE CONNECTION GUARD (class docblock). Replaces the connection's
     * reconnector: a lost session throws LostConnectionException instead of
     * being replaced and the failed statement re-run. Public static so the
     * pin can prove it on a probe session.
     */
    public static function installConnectionGuard(Connection $connection): void
    {
        $connection->setReconnector(static function (Connection $c): void {
            throw new LostConnectionException(
                'autoscale lane: database session lost on connection ['.$c->getName().']: '
                .'the transparent retry is refused and the claim is abandoned'
            );
        });
    }

    /** The framework's own reconnector (DatabaseManager::__construct), restored after the lane. */
    public static function restoreConnectionReconnector(Connection $connection): void
    {
        $connection->setReconnector(static function (Connection $c): void {
            app('db')->reconnect($c->getNameWithReadWriteType());
        });
    }

    /** A human-readable line for the dashboard's per-worker strip. */
    private function claimLabel(array $claim): string
    {
        $name = function (?string $jurisdictionId): string {
            if ($jurisdictionId === null) {
                return '?';
            }
            return (string) (DB::table('jurisdictions')->where('id', $jurisdictionId)->value('name') ?? '?');
        };

        return match ($claim['type']) {
            'singles'     => 'leaf-council batch (' . number_format($claim['count']) . ')',
            'scope_batch' => '2-cut batch (' . count($claim['scopes']) . ')',
            'precompute' => 'borders: ' . $name($claim['parent_id']),
            // THE FULL BREADCRUMB (operator catch 2026-08-30): the label
            // walks the real jurisdiction chain from the map's root down to
            // the scope — "Earth › India › Uttar Pradesh" — never just the
            // endpoints. Bounded: parent hops of one scope, ≤ tree depth.
            'scope'      => (function () use ($claim, $name) {
                $mapOwner = (string) (DB::table('apportionment_ledger')
                    ->where('legislature_id', $claim['legislature_id'])->value('jurisdiction_id') ?? '');
                $chain = [];
                $cursor = $claim['scope_jurisdiction_id'];
                for ($hops = 0; $cursor !== null && $hops < 10; $hops++) {
                    array_unshift($chain, $name((string) $cursor));
                    if ((string) $cursor === $mapOwner) {
                        break;
                    }
                    $cursor = DB::table('jurisdictions')->where('id', $cursor)->value('parent_id');
                }

                return implode(' › ', $chain)
                    . ($claim['depth'] > 0 ? ' (depth ' . $claim['depth'] . ')' : '');
            })(),
            'finalize'   => 'assessing: ' . $name(
                DB::table('apportionment_ledger')->where('legislature_id', $claim['legislature_id'])->value('jurisdiction_id')
            ),
            default      => $claim['type'],
        };
    }

    /**
     * Best-effort release after an infrastructure error mid-claim, or of a
     * claim taken by a lane whose lease is already gone. Every arm repeats
     * this lane's token (THE CLAIM-TOKEN GUARD, 2026-09-02): a claim that
     * already left this lane is not touched.
     */
    private function releaseClaim(AutoscaleRun $run, string $token, array $claim): void
    {
        try {
            switch ($claim['type']) {
                case 'singles':
                    DB::table('apportionment_ledger')
                        ->where('kind', 'single')
                        ->where('map_status', 'running')
                        ->where('claim_token', $token)
                        ->update(['map_status' => 'pending', 'claim_token' => null, 'updated_at' => now()]);
                    break;
                case 'finalize':
                    DB::table('apportionment_ledger')
                        ->where('legislature_id', $claim['legislature_id'])
                        ->where('map_status', 'assessing')
                        ->where('claim_token', $token)
                        ->update(['map_status' => 'running', 'claim_token' => null, 'updated_at' => now()]);
                    break;
                case 'precompute':
                    DB::table('jurisdiction_adjacency_parents')
                        ->where('parent_id', $claim['parent_id'])
                        ->where('status', 'running')
                        ->where('claim_token', $token)
                        ->update(['status' => 'pending', 'claim_token' => null, 'updated_at' => now()]);
                    break;
                case 'scope':
                    DB::table('apportionment_ledger_scopes')
                        ->where('id', $claim['scope_id'])
                        ->where('status', 'running')
                        ->where('claim_token', $token)
                        ->update(['status' => 'pending', 'claim_token' => null, 'started_at' => null, 'updated_at' => now()]);
                    break;
                case 'scope_batch':
                    $this->releaseBatchRemainder($claim['scopes'], $token);
                    break;
            }
        } catch (\Throwable) {
            // The pump's backend-absence reclaim is the durable fallback.
        }
    }
}
