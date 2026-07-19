<?php

namespace App\Support;

/**
 * Host worker sizing (pull engine, 2026-07-19).
 *
 * ONE concurrency limiter: the Horizon supervisor-autoscale process count =
 * autoscaleWorkers(). The AIMD width governor and the per-job release()
 * gate are gone (operator ruling: the stacked self-regulation "clearly does
 * not know what it's doing"). A pull worker claims one unit at a time, so
 * process count IS concurrency — no second dial.
 *
 *   workers = clamp( cores − 2, 2, 12 )
 *
 * Two cores stay reserved for the platform (web, redis, scheduler, the
 * operator's own browsing); 12 is the contention cap (audit-chain lock +
 * Postgres). 12-core box → 10.
 *
 * CGA_AUTOSCALE_WORKERS overrides everything (operator dial). The value is
 * resolved at config load, so `config:cache` freezes it per host — exactly
 * right: capacity is a host property.
 */
class HostCapacity
{
    public static function autoscaleWorkers(): int
    {
        $override = env('CGA_AUTOSCALE_WORKERS');
        if ($override !== null && (int) $override > 0) {
            return (int) $override;
        }

        return max(2, min(12, self::cpuCores() - 2));
    }

    public static function cpuCores(): int
    {
        $n = (int) trim((string) @shell_exec('nproc 2>/dev/null'));
        if ($n > 0) {
            return $n;
        }

        if (is_readable('/proc/cpuinfo')) {
            $n = (int) preg_match_all('/^processor\s*:/m', (string) file_get_contents('/proc/cpuinfo'));
            if ($n > 0) {
                return $n;
            }
        }

        return 4; // conservative fallback → 2 workers, never zero
    }
}
