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

        // THE WAIT-AWARE FORMULA (operator order 2026-08-29, the durable
        // fix). The old cores−2 counted workers as if always busy, but a
        // sweep worker's second splits between PHP compute and waiting on
        // postgres — during the wait its core is free, so lanes can
        // lawfully exceed cores. Measured on the planet run (10 lanes,
        // 12 cores): PHP busy ≈ 0.55 cores/worker and postgres serving
        // them ≈ 0.31 cores/worker, so each lane's true cost ≈ 0.86 of a
        // core. workers = (cores − reserve) / busy_factor, reserve 0.5
        // for the OS + web app. On this box: (12 − 0.5) / 0.86 ≈ 13.
        // Both constants are env-tunable (re-measure with `docker stats`:
        // busy_factor = (horizon_cpu + postgres_cpu) / lanes / 100).
        // Floor 2 keeps a Pi honest; cap 16 keeps a big host from
        // outrunning the postgres connection budget.
        $busyFactor = (float) (env('CGA_AUTOSCALE_BUSY_FACTOR') ?: 0.86);
        $reserve    = (float) (env('CGA_AUTOSCALE_CORE_RESERVE') ?: 0.5);
        $busyFactor = $busyFactor > 0.1 ? $busyFactor : 0.86;

        return max(2, min(16, (int) floor((self::cpuCores() - $reserve) / $busyFactor)));
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
