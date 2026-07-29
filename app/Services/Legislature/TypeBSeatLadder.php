<?php

namespace App\Services\Legislature;

/**
 * THE TYPE B LADDER (operator ruling 2026-07-19; combined cap 2026-07-29).
 *
 * Type A and Type B are sized by DIFFERENT rules — but they share ONE ceiling:
 * a jurisdiction may never seat more representatives than it has people. Type B
 * implements equal representation of the encompassed (direct constituent)
 * jurisdictions, bound in two ways:
 *   1. it may not exceed the lawful legislature size, Type A's own number; and
 *   2. THE COMBINED CAP (B3, operator, Wave 2 review): type_a + type_b may
 *      never exceed the jurisdiction's population — never more reps than people.
 * Type A is the primary chamber (its own cube-root law, with the constitutional
 * min-5 floor); the combined cap therefore bounds TYPE B by what population is
 * left after Type A. The effective bound on Type B is the TIGHTER of Type A and
 * (population − type_a). For all but small / highly-fragmented parents that is
 * just Type A, so historical chambers are byte-for-byte unchanged; the cap bites
 * only where equal representation would otherwise seat more members than there
 * are residents.
 *
 * Per legislature: each direct constituent contributes rep_floor seats,
 * EXCEPT a constituent with population ≤ 5 contributes min(population,
 * rep_floor) — a zero-population space seats nobody. The ladder starts at
 * the constitutional setting (type_b_seats_per_child, default 5) and
 * descends 4 → 3 → 2 until Σ ≤ the effective bound. A legislature still over at
 * 2 keeps the floor-2 value (itself floored by the population cap) and is
 * FLAGGED for the deferred "Type B districting" (compact equal groupings
 * sharing representative panels; the flag is the worklist).
 *
 * Pure arithmetic — both mass paths (ApportionmentSeedCommand and
 * ActivationService) call this one formula so their chambers are identical.
 */
final class TypeBSeatLadder
{
    /** Constituents at or below this population contribute at most their population. */
    public const TINY_POP = 5;

    /** The ladder never descends below 2 per constituent. */
    public const MIN_REP = 2;

    /**
     * @param int       $typeA            the lawful legislature size (the bound)
     * @param list<int> $childPopulations direct live constituents' populations
     * @param int       $startingRep      constitutional starting rep per constituent
     * @param ?int      $population        the jurisdiction's population for the
     *                                     combined cap (B3). Null → the sum of
     *                                     constituent populations (the same
     *                                     level-local denominator apportionment
     *                                     uses); never the noisy stored parent.
     * @return array{seats: int, rep_floor: int, needs_districting: bool}
     */
    public static function apportion(int $typeA, array $childPopulations, int $startingRep = 5, ?int $population = null): array
    {
        if ($childPopulations === []) {
            return ['seats' => 0, 'rep_floor' => max($startingRep, self::MIN_REP), 'needs_districting' => false];
        }

        // B3 combined cap: type_a + type_b ≤ population. The seats population
        // leaves for Type B after Type A; the effective Type B bound is the
        // tighter of Type A and this.
        $pop         = $population ?? self::sumPopulation($childPopulations);
        $popCapBound = max(0, $pop - $typeA);
        $bound       = min($typeA, $popCapBound);

        $start = max($startingRep, self::MIN_REP);
        for ($f = $start; $f >= self::MIN_REP; $f--) {
            $sum = self::sumAt($childPopulations, $f);
            if ($sum <= $bound) {
                return ['seats' => $sum, 'rep_floor' => $f, 'needs_districting' => false];
            }
        }

        // Still over the bound at the minimum rep: keep the floor-2 value as
        // the pre-grouping placeholder, but never seat more representatives
        // than there are people — the placeholder itself is floored by the
        // population cap. Flag for the deferred Type B districting; never
        // total-forced.
        return [
            'seats'             => min(self::sumAt($childPopulations, self::MIN_REP), $popCapBound),
            'rep_floor'         => self::MIN_REP,
            'needs_districting' => true,
        ];
    }

    /** @param list<int> $childPopulations */
    private static function sumPopulation(array $childPopulations): int
    {
        $sum = 0;
        foreach ($childPopulations as $pop) {
            $sum += max((int) $pop, 0);
        }

        return $sum;
    }

    /** @param list<int> $childPopulations */
    private static function sumAt(array $childPopulations, int $repFloor): int
    {
        $sum = 0;
        foreach ($childPopulations as $pop) {
            $pop = max((int) $pop, 0);
            $sum += $pop <= self::TINY_POP ? min($pop, $repFloor) : $repFloor;
        }

        return $sum;
    }
}
