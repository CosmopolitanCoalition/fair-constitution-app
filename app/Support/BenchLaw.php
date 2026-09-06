<?php

namespace App\Support;

/**
 * THE BENCH LAW (operator ruling 2026-09-05, bench-scaling-law B).
 *
 *   bench = max(floor, next odd integer >= type_a_seats / 10)
 *
 * The floor is the instance's own judiciary_min_judges_per_race setting (the
 * setup default is 5; a new instance may move it). A court whose jurisdiction
 * holds n constituents takes the bench as a MINIMUM MULTIPLE: judges per
 * constituent = ceil(bench / n), bench = that x n (Art. IV §2, an equal number
 * by each constituent). Replaces the 5/7/9 tier bands everywhere a bench is
 * written. Pure statics, mirrored in SQL for the set-based provisioning step;
 * BenchLawTest pins the two together.
 */
final class BenchLaw
{
    private function __construct() {}

    /** The bench for a chamber of $typeASeats under floor $floor. */
    public static function bench(int $typeASeats, int $floor, int $constituents = 0): int
    {
        $floor  = max(1, $floor);
        $scaled = self::nextOdd($typeASeats / 10);
        $bench  = max($floor, $scaled);

        if ($constituents > 1) {
            $perConstituent = (int) ceil($bench / $constituents);
            $bench          = $perConstituent * $constituents;
        }

        return $bench;
    }

    /** Judges nominated per constituent for a court with $constituents parts. */
    public static function perConstituent(int $typeASeats, int $floor, int $constituents): int
    {
        if ($constituents < 1) {
            return 0;
        }

        return (int) ceil(max(max(1, $floor), self::nextOdd($typeASeats / 10)) / $constituents);
    }

    /** The smallest odd integer >= $x; 1 for x <= 1. */
    public static function nextOdd(float $x): int
    {
        $n = (int) ceil($x);
        if ($n < 1) {
            return 1;
        }

        return $n % 2 === 1 ? $n : $n + 1;
    }

    /**
     * The SQL mirror: $seats, $floor and $constituents are SQL expressions
     * yielding integers. Same arithmetic as bench().
     */
    public static function sql(string $seats, string $floor, string $constituents): string
    {
        $tenth     = "CEIL(({$seats})::numeric / 10.0)::int";
        $floorExpr = "GREATEST(1, ({$floor}))";
        $scaled    = "(CASE WHEN {$tenth} < 1 THEN 1 WHEN MOD({$tenth}, 2) = 1 THEN {$tenth} ELSE {$tenth} + 1 END)";
        $bench     = "GREATEST({$floorExpr}, {$scaled})";

        return "(CASE WHEN ({$constituents}) > 1
                      THEN CEIL(({$bench})::numeric / ({$constituents}))::int * ({$constituents})
                      ELSE {$bench} END)::int";
    }
}
