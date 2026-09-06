<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * THE STEP 5 LANE TIMER — the sim mirror of ProvisionTimer.
 *
 * A sim lane times each part of its work (its stage's execute per kind, the
 * claim acquisition, the gap between claims) and accumulates them in process.
 * flush() folds the accumulator into sim_timings as an INCREMENT (count, total,
 * max) per (run, part), so the timings survive the lane's exit and the Step 5
 * page can show where the time goes and prove a change made a stage faster or
 * slower. hrtime is nanosecond-cheap; flush is a handful of upserts, so the
 * instrument costs nothing measurable.
 *
 * Static, per process: a sim lane runs one claim at a time and one job at a
 * time, and flush() clears the accumulator, so no run's timings leak into
 * another.
 */
final class SimTimer
{
    /** @var array<string,int> microseconds accumulated per part */
    private static array $us = [];

    /** @var array<string,int> sample count per part */
    private static array $n = [];

    /** @var array<string,int> worst single sample per part */
    private static array $max = [];

    /** @var array<string,int> open hrtime marks by part */
    private static array $open = [];

    private static ?bool $enabled = null;

    private static function on(): bool
    {
        return self::$enabled ??= (bool) config('cga.sim.timings', true);
    }

    public static function open(string $part): void
    {
        if (self::on()) {
            self::$open[$part] = hrtime(true);
        }
    }

    public static function close(string $part): void
    {
        if (! isset(self::$open[$part])) {
            return;
        }
        $us = (int) round((hrtime(true) - self::$open[$part]) / 1000);
        unset(self::$open[$part]);
        self::record($part, $us);
    }

    public static function record(string $part, int $us): void
    {
        if (! self::on()) {
            return;
        }
        $us = max(0, $us);
        self::$us[$part]  = (self::$us[$part] ?? 0) + $us;
        self::$n[$part]   = (self::$n[$part] ?? 0) + 1;
        self::$max[$part] = max(self::$max[$part] ?? 0, $us);
    }

    /** Fold the accumulator into sim_timings as an increment, then clear. */
    public static function flush(string $runId): void
    {
        if (self::$us === []) {
            return;
        }
        $parts = self::$us;
        $n = self::$n;
        $mx = self::$max;
        self::$us = [];
        self::$n = [];
        self::$max = [];

        foreach ($parts as $part => $tot) {
            DB::statement('
                INSERT INTO sim_timings (run_id, part, count, total_us, max_us, updated_at)
                VALUES (?::uuid, ?, ?, ?, ?, now())
                ON CONFLICT (run_id, part) DO UPDATE
                   SET count    = sim_timings.count + EXCLUDED.count,
                       total_us = sim_timings.total_us + EXCLUDED.total_us,
                       max_us   = GREATEST(sim_timings.max_us, EXCLUDED.max_us),
                       updated_at = now()
            ', [$runId, mb_substr($part, 0, 48), $n[$part], $tot, $mx[$part]]);
        }
    }
}
