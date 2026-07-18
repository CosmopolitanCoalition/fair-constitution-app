<?php

namespace App\Support;

/**
 * Host-adaptive worker sizing (operator ruling 2026-07-18: the autoscale
 * "should go as fast as the host allows … neither over- nor under-utilize").
 *
 * A sweep worker burns ~1 PHP core (the k-loop scorer) and drives ~1
 * Postgres core (PostGIS) concurrently, so each worker costs ~2 logical
 * cores at peak. Two cores stay reserved for the platform (web, redis,
 * scheduler, the operator's own browsing):
 *
 *   workers = clamp( floor((cores − 2) / 2), 2, 12 )
 *
 * 8-core box → 3 (the old hardcoded width, now derived);
 * 12-core box → 5; 32-core box → 12 (cap — beyond it the audit-chain lock
 * and Postgres contention eat the gains).
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

        return max(2, min(12, intdiv(self::cpuCores() - 2, 2)));
    }

    /**
     * The PHYSICS ceiling for supervisor-autoscale maxProcesses (operator
     * ruling 2026-07-18 #2: "set to unlimited and let the system itself
     * decide"): more worker processes than cores cannot compute CPU-bound
     * sweeps faster — that is arithmetic, not policy. Horizon provisions up
     * to this many processes; the AutoscaleGovernor decides each tick how
     * many may be BUSY (the width), hunting the real knee by feedback. Idle
     * processes above the width cost ~80 MB RSS and no CPU.
     */
    public static function workerCeiling(): int
    {
        $override = env('CGA_AUTOSCALE_WORKERS');
        if ($override !== null && (int) $override > 0) {
            return (int) $override;
        }

        return max(2, min(16, self::cpuCores()));
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
