<?php

namespace App\Support;

/**
 * Host capacity derivations (pull engine, 2026-07-19; wait-aware rewrite
 * 2026-08-29; audit derivations 2026-08-30).
 *
 * ONE concurrency limiter: the Horizon supervisor-autoscale process count =
 * autoscaleWorkers(). A pull worker claims one unit at a time, so process
 * count IS concurrency — no second dial.
 *
 *   workers = min( (cores − reserve) / busy_factor,  (max_connections − 30) / 3 )
 *
 * The wait-aware formula (operator order 2026-08-29): a lane's second
 * splits between PHP compute and waiting on postgres, so lanes lawfully
 * exceed cores; busy_factor (measured 0.86 on the 12-core reference:
 * php 0.55 + pg 0.31 per lane) prices a lane's true core cost. The
 * ceiling derives from the postgres connection budget (each lane holds a
 * connection, ~3× safety, 30 reserved for the web tier), floored at 4 so
 * a tiny max_connections cannot strangle a capable host. Floor 2 keeps a
 * Pi honest. Re-measure busy_factor with scripts/measure-busy-factor.sh
 * (operator order 2026-08-30) and pin via CGA_AUTOSCALE_BUSY_FACTOR.
 *
 * CGA_AUTOSCALE_WORKERS overrides everything (operator dial). Values
 * resolve at config load, so `config:cache` freezes them per host —
 * exactly right: capacity is a host property.
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

        // The ceiling DERIVES too (operator, 2026-08-29: "it should always
        // be a derivation"): each lane holds one postgres connection, so
        // the honest cap is the connection budget — max_connections minus
        // a reserve for the web app, the pump, horizon's other queues and
        // superuser slots, divided by a small safety factor for each
        // lane's occasional second connection. On the 8 GB reference box
        // (max_connections 200) this yields ~56, far above the core-bound
        // 13; on big iron it frees the formula to use the cores.
        $connCap = (int) floor((self::pgMaxConnections() - 30) / 3);

        return max(2, min(max(4, $connCap), (int) floor((self::cpuCores() - $reserve) / $busyFactor)));
    }

    /**
     * Horizon master memory limit in MB (audit row, 2026-08-30):
     * clamp(16 × host GB, 64, 256). 128 on the 8 GB reference box.
     */
    public static function horizonMasterMemoryMb(): int
    {
        return (int) max(64, min(256, self::hostMemoryGb() * 16));
    }

    /** Default-queue supervisor width: a third of the lane pool (audit row). */
    public static function defaultQueueWorkers(): int
    {
        return max(2, (int) ceil(self::autoscaleWorkers() / 3));
    }

    /**
     * Per-worker memory-recycle thresholds in MB (audit row): heavy lanes
     * (autoscale/sim/long-running) and light lanes (default queue) scale
     * with the host, floored at today's proven values so no box regresses.
     */
    public static function workerRecycleHeavyMb(): int
    {
        return (int) max(512, min(2048, self::hostMemoryGb() * 64));
    }

    public static function workerRecycleLightMb(): int
    {
        return (int) max(128, min(512, self::hostMemoryGb() * 16));
    }

    /** postgres max_connections, cached per process; 100 when unreachable. */
    public static function pgMaxConnections(): int
    {
        static $n = null;
        if ($n !== null) {
            return $n;
        }
        try {
            $n = (int) (\Illuminate\Support\Facades\DB::selectOne('SELECT current_setting(?) AS v', ['max_connections'])->v ?? 100);
        } catch (\Throwable) {
            $n = 100;
        }

        return $n > 0 ? $n : 100;
    }

    /**
     * Per-lane session work_mem in MB (lane 2G's audit row 4, operator
     * order 2026-08-30). Postgres' global work_mem stays conservative for
     * the web tier; a districting lane's session may sort/hash bigger.
     * Derivation: the REAL postgres container cap (POSTGRES_MEM_LIMIT,
     * the closed-budget share the installers write — never a re-guess of
     * their formula: the 2026-09-01 hardcoded 60%-of-host assumption
     * sized lanes against a cap 3.5x the real one and a work_mem spike
     * signal-9'd a backend 43 seconds into the resumed benchmark), minus
     * shared_buffers, minus a transient reserve for giant-geometry
     * operators (a quarter of the cap, at most 2 GB — a fixed 2 GB went
     * negative under small closed-budget caps), split across the lanes,
     * halved for the occasional second sort node. Clamped [16, 256].
     */
    public static function laneWorkMemMb(): int
    {
        $override = (int) env('CGA_LANE_WORK_MEM_MB', 0);
        if ($override > 0) {
            return $override;
        }
        $pgCapMb = self::hostMemoryGb() * 1024.0 * 0.6;
        $envCap = (string) env('POSTGRES_MEM_LIMIT', '');
        if (preg_match('/^(\d+)\s*([mg]?)/i', trim($envCap), $m)) {
            $pgCapMb = strtolower($m[2]) === 'g' ? ((float) $m[1]) * 1024.0 : (float) $m[1];
        }
        $reserveMb = min(2048.0, $pgCapMb / 4.0);
        $sharedMb = 512.0;
        try {
            $raw = (string) (\Illuminate\Support\Facades\DB::selectOne(
                'SELECT current_setting(?) AS v', ['shared_buffers'])->v ?? '512MB');
            $sharedMb = str_ends_with($raw, 'GB')
                ? ((float) $raw) * 1024.0
                : (float) $raw;
        } catch (\Throwable) {
            // fallback stands
        }

        return (int) max(16, min(256, ($pgCapMb - $sharedMb - $reserveMb) / max(self::autoscaleWorkers(), 1) / 2.0));
    }

    /** Host (VM) memory in GiB, from /proc/meminfo; 8 when unreadable. */
    public static function hostMemoryGb(): float
    {
        static $gb = null;
        if ($gb !== null) {
            return $gb;
        }
        $kb = 0;
        if (is_readable('/proc/meminfo')
            && preg_match('/^MemTotal:\s+(\d+)\s+kB/m', (string) file_get_contents('/proc/meminfo'), $m)) {
            $kb = (int) $m[1];
        }
        $gb = $kb > 0 ? $kb / 1048576 : 8.0;

        return $gb;
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
