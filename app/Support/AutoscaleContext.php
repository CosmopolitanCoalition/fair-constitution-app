<?php

namespace App\Support;

/**
 * Per-process autoscale claim context (pull engine, 2026-07-19).
 *
 * A worker sets this while it processes a claimed sweep scope so that deep
 * plumbing (DistrictingService::publishMassProgress) can heartbeat the
 * worker's OWN claim rows instead of every running row on the legislature.
 * Without the scoping, two scope workers sharing one legislature (Earth root
 * + China concurrently) would keep a DEAD sibling's lease fresh forever —
 * the stale-claim reclaim could never fire.
 *
 * Static per PHP worker process; each queue job clears it in `finally`.
 *
 * The WORKER TOKEN rides here too (2026-08-09, the re-run loop): the lease row
 * is the pump's only evidence that a worker is alive, and it was stamped solely
 * at claim boundaries — so a worker inside one long scope went silent, its
 * lease was pruned at 10 minutes, a replacement was dispatched alongside it,
 * and the scope itself was reclaimed at 30. Carrying the token lets the same
 * heartbeat that touches the claim rows also refresh the lease, so BUSY is no
 * longer mistaken for DEAD.
 */
final class AutoscaleContext
{
    public static ?string $runId = null;

    public static ?string $itemId = null;

    public static ?string $scopeId = null;

    /** The claiming worker's lease id, when a worker (not a test) is driving. */
    public static ?string $workerToken = null;

    public static function enter(string $runId, string $itemId, ?string $scopeId, ?string $workerToken = null): void
    {
        static::$runId       = $runId;
        static::$itemId      = $itemId;
        static::$scopeId     = $scopeId;
        static::$workerToken = $workerToken;
    }

    public static function clear(): void
    {
        static::$runId = static::$itemId = static::$scopeId = static::$workerToken = null;
    }

    public static function active(): bool
    {
        return static::$runId !== null;
    }

    /**
     * Does this lane still hold the scope it is drawing? A lane whose scope
     * was reclaimed or killed must not retire or file anything further
     * (operator order 2026-09-02, the Tumaco three-lane pattern). No worker
     * token (a test, a human draw, the CLI) always owns its scope.
     */
    public static function ownsScope(): bool
    {
        if (static::$workerToken === null || static::$scopeId === null) {
            return true;
        }

        return \Illuminate\Support\Facades\DB::table('apportionment_ledger_scopes')
            ->where('id', static::$scopeId)
            ->where('status', 'running')
            ->where('claim_token', static::$workerToken)
            ->exists();
    }
}
