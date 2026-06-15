<?php

namespace Tests\Constitutional;

use App\Services\Districting\Splitline;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase H (H0). The DB-free seat math every districting
 * method shares. The pins:
 *  1. seatSplit ALWAYS terminates with every leaf in the resolved [floor,ceiling]
 *     band and the leaves summing EXACTLY to the whole budget S — proven across
 *     THREE amendable bands (5/9, 7/15, 3/7), so nothing is hard-coded to 5/9
 *     (Art. II §2 / Art. V §3 — the band is amendable per scope);
 *  2. Serravalle's whole budget of 10 splits into [5,5] (the live fixture);
 *  3. a sub-floor budget is refused (a forced sub-floor is a merge-up question,
 *     Art. V §3 — never silently seated below the floor);
 *  4. largest-remainder reconciliation makes slice populations sum EXACTLY to the
 *     parent total, deterministically (audit reproducibility).
 *
 * If an edit breaks these, the edit is the constitutional violation — fix the
 * edit, not the test.
 */
class SubdivisionSeatMathTest extends TestCase
{
    /** Every amendable band must decompose cleanly — no 5/9 literal in the math. */
    public function test_seat_split_yields_in_band_leaves_summing_to_budget_across_amendable_bands(): void
    {
        foreach ([[5, 9], [7, 15], [3, 7]] as [$floor, $ceiling]) {
            for ($S = $floor; $S <= 60; $S++) {
                $leaves = Splitline::seatSplit($S, $floor, $ceiling);

                $this->assertNotEmpty($leaves, "band [{$floor},{$ceiling}] S={$S} produced no leaves");
                $this->assertSame(
                    $S,
                    array_sum($leaves),
                    "band [{$floor},{$ceiling}] S={$S} leaves ".json_encode($leaves)." do not sum to S"
                );
                foreach ($leaves as $seats) {
                    $this->assertGreaterThanOrEqual(
                        $floor,
                        $seats,
                        "band [{$floor},{$ceiling}] S={$S} leaf {$seats} below floor"
                    );
                    $this->assertLessThanOrEqual(
                        $ceiling,
                        $seats,
                        "band [{$floor},{$ceiling}] S={$S} leaf {$seats} above ceiling"
                    );
                }
            }
        }
    }

    /** The live fixture: Serravalle (round(10.398) = 10 whole seats) → [5, 5]. */
    public function test_serravalle_ten_seats_splits_into_two_fives(): void
    {
        $this->assertSame([5, 5], Splitline::seatSplit(10, 5, 9));
    }

    /** A budget below the floor is never seated below the floor by splitline. */
    public function test_seat_split_refuses_a_sub_floor_budget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Splitline::seatSplit(3, 5, 9);
    }

    /** A band too tight to split a budget is flagged infeasible, not faked. */
    public function test_a_band_too_tight_to_subdivide_is_infeasible(): void
    {
        // floor 5, ceiling 6: a budget of 7 cannot be one district (>6) nor two
        // (2*5 = 10 > 7). It must be reported infeasible.
        $this->assertFalse(Splitline::isFeasible(7, 5, 6));
        // ...while a comfortable band handles the same budget.
        $this->assertTrue(Splitline::isFeasible(7, 3, 7));
    }

    /** Reconciled slice populations sum EXACTLY to the parent total. */
    public function test_largest_remainder_reconciliation_sums_exactly_to_parent(): void
    {
        foreach (
            [
                [10825, [4100, 3600, 3100]],   // Serravalle-scale, lossy thirds
                [1000,  [333.4, 333.3, 333.3]],
                [7,     [1, 1, 1, 1, 1]],       // remainder exceeds zero
                [100,   [0, 0, 0]],             // degenerate all-zero weights
            ] as [$parent, $weights]
        ) {
            $reconciled = Splitline::reconcileLargestRemainder($parent, $weights);

            $this->assertCount(count($weights), $reconciled);
            $this->assertSame(
                $parent,
                array_sum($reconciled),
                'reconciled '.json_encode($reconciled)." must sum to parent {$parent}"
            );
        }
    }

    /** Identical inputs → identical reconciliation (federation/audit determinism). */
    public function test_reconciliation_is_deterministic(): void
    {
        $a = Splitline::reconcileLargestRemainder(9999, [501.5, 250.25, 248.25]);
        $b = Splitline::reconcileLargestRemainder(9999, [501.5, 250.25, 248.25]);

        $this->assertSame($a, $b);
    }
}
