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
 *   - CPU busy-fraction > 0.92 (cores actually saturated)     → width − 1
 *   - CPU busy-fraction < 0.70 and sweeps are waiting         → width + 1
 *
 * The signal is REAL CPU busy time from /proc/stat deltas — never load
 * average, which on this stack counts Postgres' IO-wait sleepers as
 * pressure (measured live: load 9.4 with 2.3 cores busy) and would
 * chronically under-drive an IO-latency-hiding workload. IO-wait reads as
 * headroom here, which is correct: more concurrent sweeps are exactly how
 * that latency is hidden.
 *
 * Bounded by [2, HostCapacity::workerCeiling()] — the ceiling is cores, i.e.
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

        $cpuBusy = self::cpuBusyFraction();
        // Published for the dashboard (a read-only copy — the web endpoint
        // must never advance the delta window this tick steers by).
        Cache::put('autoscale.cpu_last_reading', [
            'busy' => $cpuBusy !== null ? round($cpuBusy, 2) : null,
            'at'   => now()->toIso8601String(),
        ], 3600);

        $decided = self::decide(
            current: $current,
            ceiling: HostCapacity::workerCeiling(),
            cpuBusy: $cpuBusy,
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
                'from' => $current, 'to' => $decided, 'cpu_busy' => $cpuBusy,
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
        ?float $cpuBusy,
        bool $pendingExists,
        int $recentFailures,
        bool $pgRestarted,
    ): int {
        $width = $current;

        if ($pgRestarted) {
            $width = intdiv($width, 2);                       // crash → halve
        } elseif ($recentFailures > 0) {
            $width -= 2;                                      // failures → firm step down
        } elseif ($cpuBusy !== null && $cpuBusy > 0.92) {
            $width -= 1;                                      // cores saturated → ease off
        } elseif (($cpuBusy === null || $cpuBusy < 0.70) && $pendingExists) {
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

    /**
     * System-wide CPU busy fraction since the LAST call (a /proc/stat
     * delta persisted in cache). IO-wait counts as idle — deliberately:
     * an IO-waiting core can absorb another worker. Null on the first
     * sample or where /proc/stat is unavailable.
     */
    public static function cpuBusyFraction(): ?float
    {
        if (! is_readable('/proc/stat')) {
            return null;
        }
        $line = strtok((string) file_get_contents('/proc/stat'), "\n");
        if (! is_string($line) || ! str_starts_with($line, 'cpu ')) {
            return null;
        }
        $f = array_map('floatval', preg_split('/\s+/', trim(substr($line, 4))));
        // user nice system idle iowait irq softirq steal
        $idle  = ($f[3] ?? 0) + ($f[4] ?? 0);
        $total = array_sum(array_slice($f, 0, 8));

        $prev = Cache::get('autoscale.cpu_sample');
        Cache::put('autoscale.cpu_sample', ['idle' => $idle, 'total' => $total], 3600);

        if (! is_array($prev) || $total <= ($prev['total'] ?? 0)) {
            return null;
        }
        $dTotal = $total - $prev['total'];
        $dIdle  = $idle - $prev['idle'];

        return $dTotal > 0 ? max(0.0, min(1.0, 1.0 - $dIdle / $dTotal)) : null;
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
