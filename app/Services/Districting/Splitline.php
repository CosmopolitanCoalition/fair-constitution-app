<?php

namespace App\Services\Districting;

use InvalidArgumentException;

/**
 * Phase H — shortest-splitline math. THIS FILE (H0) ships only the pure,
 * DB-free statics that the manual drawer, the splitline generator, and the
 * constitutional pins all share; the geometry generator (ST_Split recursion
 * over a giant's polygon) lands in H3 behind the DistrictingMethod interface.
 *
 * The band ([floor, ceiling], default 5/9) is ALWAYS passed in — resolved per
 * scope from ConstitutionalDefaults, never a literal (design §1.2). A giant
 * entitled to a WHOLE S seats (S = round(fractional_seats), the LegislatureController
 * clamp) is decomposed into in-band leaves whose seats sum exactly to S.
 */
class Splitline
{
    /**
     * Decompose a whole seat budget S into in-band district seat counts that sum
     * to S, by balanced bisection (the §4.2.1 a≈b rule). Every returned value is
     * in [floor, ceiling]; the list sums to S.
     *
     * Throws if S is below the floor (a forced sub-floor — the caller resolves it
     * by merge-up, §4.1.4; a leaf giant with S > ceiling never reaches this), or
     * if the band cannot subdivide S at all (pathological band where
     * ceiling < 2*floor − 1).
     *
     * @return int[] each in [floor, ceiling], summing to $S
     */
    public static function seatSplit(int $S, int $floor, int $ceiling): array
    {
        if ($floor < 1 || $ceiling < $floor) {
            throw new InvalidArgumentException("Invalid seat band [{$floor}, {$ceiling}].");
        }
        if ($S < $floor) {
            throw new InvalidArgumentException(
                "Seat budget {$S} is below the floor {$floor} — a forced sub-floor "
                .'is resolved by merge-up (Art. V §3), not by splitline.'
            );
        }
        if ($S <= $ceiling) {
            return [$S];                       // a single in-band leaf district
        }

        // S > ceiling: bisect into a + b (b carries the odd remainder).
        $a = intdiv($S, 2);
        $b = $S - $a;

        if ($a < $floor) {
            // Only possible when ceiling < 2*floor − 1 (a band too tight to split S).
            throw new InvalidArgumentException(
                "Seat band [{$floor}, {$ceiling}] cannot subdivide a budget of {$S}."
            );
        }

        return array_merge(
            self::seatSplit($a, $floor, $ceiling),
            self::seatSplit($b, $floor, $ceiling),
        );
    }

    /**
     * Whether a whole seat budget S is decomposable into in-band leaves.
     */
    public static function isFeasible(int $S, int $floor, int $ceiling): bool
    {
        try {
            self::seatSplit($S, $floor, $ceiling);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Distribute $parentTotal across integer slices proportional to $weights, by
     * the largest-remainder method, so the slices sum EXACTLY to $parentTotal.
     *
     * Summing many ST_Clip slice populations accumulates partial-pixel error and
     * will not equal the parent total computed once. This reconciles the residual
     * onto the slices with the largest fractional parts (design §4.2.3) so seat
     * apportionment over the slices is exact and deterministic.
     *
     * @param  float[]|int[]  $weights  per-slice raw populations
     * @return int[] reconciled per-slice populations, summing to $parentTotal
     */
    public static function reconcileLargestRemainder(int $parentTotal, array $weights): array
    {
        $n = count($weights);
        if ($n === 0) {
            return [];
        }

        $sumW = array_sum($weights);
        // Degenerate (all-zero weights): split as evenly as possible.
        if ($sumW <= 0) {
            $weights = array_fill(0, $n, 1);
            $sumW = $n;
        }

        $floors = [];
        $fracs  = [];
        $assigned = 0;
        foreach ($weights as $i => $w) {
            $raw       = $parentTotal * ($w / $sumW);
            $f         = (int) floor($raw);
            $floors[$i] = $f;
            $fracs[$i]  = $raw - $f;
            $assigned  += $f;
        }

        $remainder = $parentTotal - $assigned;

        if ($remainder > 0) {
            // Hand out the leftover units to the largest fractional parts; ties
            // break on index for determinism (identical inputs → identical output).
            $order = array_keys($fracs);
            usort($order, function ($a, $b) use ($fracs) {
                return $fracs[$b] <=> $fracs[$a] ?: $a <=> $b;
            });
            for ($k = 0; $k < $remainder; $k++) {
                $floors[$order[$k]]++;
            }
        }

        ksort($floors);

        return array_values($floors);
    }
}
