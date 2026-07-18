<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The width governor (operator ruling 2026-07-18: "set to unlimited and let
 * the system itself decide what it can handle"). No static formula caps the
 * run — the system hunts the host's real knee by feedback, AIMD-style:
 *
 *   - Postgres restarted since last tick (the crash signal)  → HALVE
 *   - sweep jobs failed in the last window                    → width − 2
 *   - 1-min load per core > 1.15 (host saturated)             → width − 1
 *   - load per core < 0.80 and sweeps are waiting             → width + 1
 *
 * bounded by [2, HostCapacity::workerCeiling()] — the ceiling is cores, i.e.
 * physics, not policy. CGA_AUTOSCALE_WORKERS pins the width and disables the
 * governor entirely (the operator's manual dial).
 *
 * The decided width gates BUSY sweep jobs (AutoscaleLegislatureJob releases
 * itself back to the queue when the width is full); Horizon provisions the
 * ceiling's worth of processes and the surplus idles cheaply. The
 * orchestrator tick calls tick() every ~90 s, so the width walks to the knee
 * within minutes and keeps tracking it as the workload changes.
 */
class AutoscaleGovernor
{
    public const WIDTH_CACHE_KEY = 'autoscale.width';

    private const PG_START_CACHE_KEY = 'autoscale.pg_started_at';

    /** Measure the host, decide, store, and return the current width. */
    public static function tick(string $runId): int
    {
        $pinned = env('CGA_AUTOSCALE_WORKERS');
        if ($pinned !== null && (int) $pinned > 0) {
            Cache::put(self::WIDTH_CACHE_KEY, (int) $pinned, 86400);

            return (int) $pinned;
        }

        $current = (int) Cache::get(self::WIDTH_CACHE_KEY, 0);
        if ($current < 2) {
            $current = HostCapacity::autoscaleWorkers(); // the seed guess
        }

        $decided = self::decide(
            current: $current,
            ceiling: HostCapacity::workerCeiling(),
            loadPerCore: self::loadPerCore(),
            pendingExists: DB::table('autoscale_items')
                ->where('run_id', $runId)->where('kind', 'sweep')
                ->whereIn('status', ['pending', 'queued'])->exists(),
            recentFailures: (int) DB::table('autoscale_items')
                ->where('run_id', $runId)->where('status', 'failed')
                ->where('finished_at', '>', now()->subMinutes(5))->count(),
            pgRestarted: self::pgRestartedSinceLastTick(),
        );

        if ($decided !== $current) {
            Log::info('Autoscale governor width change', [
                'from' => $current, 'to' => $decided, 'load_per_core' => self::loadPerCore(),
            ]);
        }
        Cache::put(self::WIDTH_CACHE_KEY, $decided, 86400);

        return $decided;
    }

    /**
     * The pure decision — AIMD toward the knee. Public + argument-driven so
     * the pin suite can walk every transition without a host.
     */
    public static function decide(
        int $current,
        int $ceiling,
        ?float $loadPerCore,
        bool $pendingExists,
        int $recentFailures,
        bool $pgRestarted,
    ): int {
        $width = $current;

        if ($pgRestarted) {
            $width = intdiv($width, 2);                       // crash → halve
        } elseif ($recentFailures > 0) {
            $width -= 2;                                      // failures → firm step down
        } elseif ($loadPerCore !== null && $loadPerCore > 1.15) {
            $width -= 1;                                      // saturated → ease off
        } elseif (($loadPerCore === null || $loadPerCore < 0.80) && $pendingExists) {
            $width += 1;                                      // headroom + work waiting → probe up
        }

        return max(2, min($ceiling, $width));
    }

    /** Effective width for job-side gating (seed when no tick ran yet). */
    public static function width(): int
    {
        $pinned = env('CGA_AUTOSCALE_WORKERS');
        if ($pinned !== null && (int) $pinned > 0) {
            return (int) $pinned;
        }

        $w = (int) Cache::get(self::WIDTH_CACHE_KEY, 0);

        return $w >= 2 ? $w : HostCapacity::autoscaleWorkers();
    }

    /** 1-minute load average per core (containers share the host kernel). */
    public static function loadPerCore(): ?float
    {
        if (! is_readable('/proc/loadavg')) {
            return null;
        }
        $load = (float) strtok((string) file_get_contents('/proc/loadavg'), ' ');

        return $load / max(1, HostCapacity::cpuCores());
    }

    /** Crash signal: pg_postmaster_start_time moved since the last tick. */
    private static function pgRestartedSinceLastTick(): bool
    {
        try {
            $started = (string) DB::scalar('SELECT pg_postmaster_start_time()');
        } catch (\Throwable) {
            return false; // unreachable DB is its own storm — don't compound it
        }

        $previous = Cache::get(self::PG_START_CACHE_KEY);
        Cache::put(self::PG_START_CACHE_KEY, $started, 86400);

        return $previous !== null && $previous !== $started;
    }
}
