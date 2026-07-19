<?php

namespace App\Services\Legislature;

/**
 * THE TYPE B LADDER (operator ruling 2026-07-19).
 *
 * Type A and Type B are INDEPENDENTLY sized chambers — there is no combined
 * ceiling. Type B implements equal representation of the encompassed
 * (direct constituent) jurisdictions, but is bound by the same sizing rule
 * as Type A: its total may not exceed the lawful legislature size, which is
 * Type A's own number.
 *
 * Per legislature: each direct constituent contributes rep_floor seats,
 * EXCEPT a constituent with population ≤ 5 contributes min(population,
 * rep_floor) — a zero-population space seats nobody. The ladder starts at
 * the constitutional setting (type_b_seats_per_child, default 5) and
 * descends 4 → 3 → 2 until Σ ≤ Type A. A legislature still over at 2 keeps
 * the floor-2 value and is FLAGGED for the deferred "Type B districting"
 * (compact equal groupings sharing representative panels — not built yet;
 * the flag is the worklist).
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
     * @return array{seats: int, rep_floor: int, needs_districting: bool}
     */
    public static function apportion(int $typeA, array $childPopulations, int $startingRep = 5): array
    {
        if ($childPopulations === []) {
            return ['seats' => 0, 'rep_floor' => max($startingRep, self::MIN_REP), 'needs_districting' => false];
        }

        $start = max($startingRep, self::MIN_REP);
        for ($f = $start; $f >= self::MIN_REP; $f--) {
            $sum = self::sumAt($childPopulations, $f);
            if ($sum <= $typeA) {
                return ['seats' => $sum, 'rep_floor' => $f, 'needs_districting' => false];
            }
        }

        // Still over the bound at the minimum rep: keep the floor-2 value,
        // flag for the deferred Type B districting. Never total-forced.
        return [
            'seats'             => self::sumAt($childPopulations, self::MIN_REP),
            'rep_floor'         => self::MIN_REP,
            'needs_districting' => true,
        ];
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
