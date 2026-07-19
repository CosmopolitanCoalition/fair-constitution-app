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
 */
final class AutoscaleContext
{
    public static ?string $runId = null;

    public static ?string $itemId = null;

    public static ?string $scopeId = null;

    public static function enter(string $runId, string $itemId, ?string $scopeId): void
    {
        static::$runId   = $runId;
        static::$itemId  = $itemId;
        static::$scopeId = $scopeId;
    }

    public static function clear(): void
    {
        static::$runId = static::$itemId = static::$scopeId = null;
    }

    public static function active(): bool
    {
        return static::$runId !== null;
    }
}
