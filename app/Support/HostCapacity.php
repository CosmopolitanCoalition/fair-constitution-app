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
        // be a derivation"): each lane holds TWO postgres sessions (its
        // work connection and the 'pgsql_beat' heartbeat connection, since
        // 2026-09-02), so the honest cap is the connection budget:
        // max_connections minus a reserve for the web app, the pump,
        // horizon's other queues and superuser slots, divided by three
        // (two sessions plus a margin). On the 8 GB reference box
        // (max_connections 200) this yields ~56, far above the core-bound
        // 13; on big iron it frees the formula to use the cores.
        $connCap = (int) floor((self::pgMaxConnections() - 30) / 3);

        // THE APPETITE DERIVES FROM THE MEMORY SHARE (WoS 2026-09-02): a cap
        // limits what Horizon may hold, it does not shrink what the lanes
        // ask for, so on a small host the pool blew its cap and looped
        // through kills. The lane count also fits the container's memory
        // share (MEM_HORIZON). No cap known = no memory bound.
        //
        // THE LANES FIT THE WHOLE TREE (operator order 2026-09-19, the size-family
        // sweep). The old bound (three quarters of the cap at 400 MB a lane)
        // dated from four pools of two idle lanes. Every pool now holds the full
        // lane width at idle, a fifth pool (provision) was added, and each
        // supervisor is its own PHP process, so the quarter left for "the rest"
        // under-funded it: a 1776 MB cap was granted three lanes whose tree needs
        // 2176 MB. lanesThatFit() charges the master, every supervisor, every
        // pool's idle lanes and the active pool's growth to the recycle floor, and
        // returns the widest lane count the cap funds. get-started's Horizon need
        // is this same model at two lanes, so a cap at its floor always fits.
        $memCap = self::horizonMemoryCapMb();
        $memLanes = $memCap > 0 ? self::lanesThatFit($memCap, self::horizonMasterMemoryMb()) : PHP_INT_MAX;

        return max(2, min(max(4, $connCap), max(2, $memLanes), (int) floor((self::cpuCores() - $reserve) / $busyFactor)));
    }

    /** Supervisors defined in config/horizon.php (HostCapacityTest pins the count against the config). */
    public const SUPERVISORS = 6;

    /** A lane's resident size at the recycle floor, MB: workerRecycleHeavyMb() never goes below it. */
    public const HEAVY_FLOOR_MB = 256;

    /**
     * Worker processes Horizon holds at idle for a lane width: the pool widths
     * of config/horizon.php (default, long-running, autoscale, provision, sim,
     * prewarm). Every 'simple'-balance pool runs its full width at idle. Pure.
     */
    public static function fleetFor(int $aw, ?int $provision = null): int
    {
        $aw          = max(2, $aw);
        $default     = max(2, (int) ceil($aw / 3));
        $longRunning = max(2, min(count(\App\Models\GeodataFlag::CATEGORIES), $aw));
        $prewarm     = max(1, min(4, (int) ceil($aw / 4)));

        return $default + $longRunning + $aw + ($provision ?? $aw) + $aw + $prewarm;
    }

    /**
     * What the Horizon container holds with ONE pool active at $aw lanes, MB:
     * the master at its limit, every supervisor and every idle lane at the
     * measured idle size, and the active pool's lanes grown to $laneMb. Pure.
     * At the default $laneMb (the recycle floor) and $aw = 2 this is the
     * smallest tree Horizon runs: get-started.sh (need_horizon) and
     * get-started.ps1 mirror that value as the Horizon need.
     */
    public static function horizonNeedMb(int $aw, int $masterMb, ?int $laneMb = null): int
    {
        $aw = max(2, $aw);
        $laneMb ??= self::HEAVY_FLOOR_MB;

        return $masterMb
            + (self::SUPERVISORS + self::fleetFor($aw)) * self::PER_WORKER_IDLE_MB
            + $aw * ($laneMb - self::PER_WORKER_IDLE_MB);
    }

    /** A lane's working resident size, MB: 5/6 of its recycle bound (it recycles at 480, so it sits below). */
    public static function laneWorkMb(): int
    {
        return (int) round(\App\Jobs\AutoscaleWorkerJob::MEMORY_RECYCLE_BYTES / 1048576 * 5 / 6);
    }

    /**
     * The widest lane count whose whole tree a Horizon cap funds. Two lanes
     * are the floor (two-ended draining needs both). A third lane and every
     * lane after it is charged at its WORKING size, so a wide pool keeps a
     * recycle bound near the lane's real appetite and is never squeezed to the
     * recycle floor to admit more lanes. Pure.
     */
    public static function lanesThatFit(int $capMb, int $masterMb): int
    {
        $aw = 2;
        while ($aw < 512 && self::horizonNeedMb($aw + 1, $masterMb, self::laneWorkMb()) <= $capMb) {
            $aw++;
        }

        return $aw;
    }

    /**
     * THE PROVISION POOL (operator order 2026-09-06: run ALL lanes at all
     * times). Provisioning uses the full wait-aware compute count, like
     * districting — every lane the host can offer. The earlier write-contention
     * that forced a half pool is addressed at its cause instead: the shell
     * batch is un-bulked (ProvisionClaims::SHELL_BATCH), the near-empty shell
     * tables are analyzed before the dependent pass, and the lane/part timings
     * (ProvisionTimer) surface any remaining contention to target directly.
     * CGA_PROVISION_WORKERS overrides.
     */
    public static function provisionWorkers(): int
    {
        $override = env('CGA_PROVISION_WORKERS');
        if ($override !== null && (int) $override > 0) {
            return (int) $override;
        }

        return max(2, self::autoscaleWorkers());
    }

    /**
     * The Horizon container's memory cap in MB from the budget ledger
     * (MEM_HORIZON, written by get-started as "NNNm" or "NNg"); 0 when unset.
     */
    public static function horizonMemoryCapMb(): int
    {
        $raw = strtolower(trim((string) env('MEM_HORIZON', '')));
        if ($raw === '' || ! preg_match('/^(\d+)\s*([kmg]?)b?$/', $raw, $m)) {
            return 0;
        }
        $n = (int) $m[1];

        return match ($m[2]) { 'g' => $n * 1024, 'k' => intdiv($n, 1024), default => $n };
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
    /** Measured idle resident RSS of one queue worker, MB (WoS 2026-09-02).
     *  Only this per-worker UNIT is fixed; the fleet COUNT derives below. */
    private const PER_WORKER_IDLE_MB = 64;

    /**
     * The idle tree a closed Horizon cap must fund before any job runs,
     * DERIVED from the actual supervisor widths (operator ruling 2026-09-08).
     * Every 'simple'-balance supervisor runs its maxProcesses at idle, so the
     * fleet scales with autoscaleWorkers(); each supervisor is one more PHP
     * process. fleetFor() owns the widths and MIRRORS config/horizon.php
     * (supervisor-1, long-running, autoscale, provision, sim, prewarm); keep
     * them in lockstep. Acyclic: autoscaleWorkers() reads horizonNeedMb(),
     * never this.
     */
    public static function idleFleetMb(): int
    {
        // Every pool AND every supervisor process (size-family sweep 2026-09-19:
        // the provision pool and the six supervisors were not counted, so the
        // recycle bound below over-granted by about 900 MB on a 16 GB mapping box).
        $fleet = self::fleetFor(self::autoscaleWorkers(), self::provisionWorkers()) + self::SUPERVISORS;

        return $fleet * self::PER_WORKER_IDLE_MB;
    }

    public static function workerRecycleHeavyMb(): int
    {
        $heavy = (int) max(512, min(2048, self::hostMemoryGb() * 64));
        // THE CAP BOUNDS THE RECYCLE (WoS 2026-09-02, 41 Horizon restarts on a
        // 4 GB host; DERIVED 2026-09-08). A heavy worker is part of the idle
        // fleet (counted at PER_WORKER_IDLE_MB) and GROWS to this bound when it
        // runs, so the growth of the whole active pool must fit the room the cap
        // leaves after the master and the idle fleet:
        //   active_lanes * (heavy - idle) <= room  =>  heavy <= idle + room/active_lanes
        // The active step runs ONE heavy pool at autoscaleWorkers() width, so
        // that is the divisor (was a fixed 2 — an OOM bet the moment more than
        // two lanes hit the bound). Floor 256 keeps a tiny host working; no cap
        // known (open profile writes the host size) leaves the host formula alone.
        $capMb = self::horizonMemoryCapMb();
        if ($capMb > 0) {
            $room  = max(0, $capMb - self::horizonMasterMemoryMb() - self::idleFleetMb());
            $lanes = max(2, self::autoscaleWorkers());
            $heavy = min($heavy, max(self::HEAVY_FLOOR_MB, self::PER_WORKER_IDLE_MB + intdiv($room, $lanes)));
        }

        return $heavy;
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

    /**
     * ROWS PER ENUMERATION CHUNK, DERIVED FROM THE HOST (the derive-from-host
     * law generalized to every sibling, operator ruling 2026-09-13). One
     * bounded, individually committed chunk of a keyset enumeration walk (the
     * sim cohort worklist, the Step 4 provision ledger, the autoscale
     * apportionment worklist). Bigger hosts commit fewer, larger chunks; a Pi
     * commits many small ones and stays resumable in tiny bites.
     *
     * Derivation: rows scale with host memory, since a chunk buffers the
     * keyset page plus the INSERT...SELECT working set. The 8 GB reference box
     * ran 25000 comfortably, so ~3125 rows per host GB. Floor 1000 keeps a Pi
     * resumable without huge per-chunk statements; cap 100000 keeps one
     * chunk's statement bounded on big iron. hostMemoryGb() falls back to 8.0
     * when unreadable, so an unknown host resolves to exactly 25000 — the
     * behaviour is identical to the retired fixed constants apart from the
     * size. CGA_ENUM_CHUNK overrides everything (operator dial).
     */
    public static function enumerationChunk(): int
    {
        $override = (int) env('CGA_ENUM_CHUNK', 0);
        if ($override > 0) {
            return $override;
        }

        return (int) max(1000, min(100000, (int) round(self::hostMemoryGb() * 3125)));
    }

    /**
     * Keyset chunk size for the clock and standings sweeps (W-0187). Each
     * chunk is one committed unit of a resumable pass, so a kill costs one
     * chunk. Derived from host memory, floored so a Pi still runs (200) and
     * capped so a big host never holds too large a working set at once.
     * CGA_SWEEP_CHUNK overrides (operator dial).
     */
    public static function sweepChunk(): int
    {
        $override = (int) env('CGA_SWEEP_CHUNK', 0);
        if ($override > 0) {
            return $override;
        }

        return (int) max(200, min(5000, (int) round(self::hostMemoryGb() * 250)));
    }

    /**
     * Per-sweep ceiling on clock-timer fires (W-0187, replaces the fixed
     * 500). The chamber-size backlog drains oldest-first across sweeps with
     * no starvation; the ceiling only bounds one sweep. Scales with the lane
     * pool so a big host fires more per minute, floored at 500 so no box
     * regresses and a Pi stays honest. CGA_CLOCK_SWEEP_BUDGET overrides.
     */
    public static function clockSweepBudget(): int
    {
        $override = (int) env('CGA_CLOCK_SWEEP_BUDGET', 0);
        if ($override > 0) {
            return $override;
        }

        return (int) max(500, min(50000, self::autoscaleWorkers() * 1000));
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
