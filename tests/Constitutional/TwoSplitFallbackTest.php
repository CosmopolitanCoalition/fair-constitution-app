<?php

namespace Tests\Constitutional;

use App\Services\Districting\SubdivisionAutoseedService as S;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the Tier-1 lawful two-split fallback (operator sanction
 * 2026-07-21, the concave-residue fix). When a 2-district scope's balanced
 * grouping strands a fragment at every angle, the autoseeder retries at every
 * OTHER lawful in-band sizing (each side 5–9 seats) rather than surrendering
 * to the review list. This pins the split ORDER the fallback tries:
 * most-balanced-first, the balanced pair already tried excluded, every
 * candidate genuinely in-band. The geometric rescue itself is proven
 * end-to-end against the real review geometries; this locks the arithmetic
 * contract so a refactor can't silently reorder or admit an out-of-band split.
 *
 * If an edit breaks these, the edit is the constitutional violation — fix the
 * edit, not the test.
 */
class TwoSplitFallbackTest extends TestCase
{
    public function test_composition_ladder_starts_at_the_historical_grouping_and_buys_slack(): void
    {
        // RUNG 0 IS THE HISTORY (2026-07-25): the ladder's first composition
        // must equal seatGroups() for every budget, or every drawable scope
        // would re-plan. Then each rung must stay lawful and in band.
        foreach ([10, 13, 21, 32, 65, 69, 73, 79, 124, 152] as $S) {
            $kMin = intdiv($S + 9 - 1, 9);
            $this->assertSame(S::seatGroups($S, 5, 9), S::seatGroupsForK($S, $kMin, 5, 9),
                "rung 0 must reproduce seatGroups for S={$S}");

            for ($k = $kMin; $k <= min(intdiv($S, 5), $kMin + 3); $k++) {
                $sizes = S::seatGroupsForK($S, $k, 5, 9);
                if ($sizes === null) {
                    continue;
                }
                $this->assertSame($S, array_sum($sizes), "composition must seat exactly S={$S} at k={$k}");
                $this->assertCount($k, $sizes);
                foreach ($sizes as $s) {
                    $this->assertGreaterThanOrEqual(5, $s);
                    $this->assertLessThanOrEqual(9, $s);
                }
            }
        }

        // THE ABU DHABI SHAPE: at k_min a 152-seat budget crowds the ceiling
        // (sixteen 9s), so its 18-seat nodes admit exactly ONE sizing (9:9) —
        // zero slack, undrawable when no blade fits. A later rung breaks the
        // deadlock: nineteen 8s, whose 16-seat nodes admit 7:9, 8:8 and 9:7.
        $rigid = S::seatGroupsForK(152, 17, 5, 9);
        $this->assertSame(16, count(array_filter($rigid, fn (int $s) => $s === 9)));
        $this->assertSame([], S::lawfulTwoSplitFallback(18, 5, 9, 9),
            'an 18-seat node has no alternative sizing — the rigidity that forced the ladder');

        $slack = S::seatGroupsForK(152, 19, 5, 9);
        $this->assertSame(array_fill(0, 19, 8), $slack);
        $this->assertSame([7, 9], S::lawfulTwoSplitFallback(16, 5, 9, 8),
            'a 16-seat node offers real alternatives — the slack the ladder buys');

        // Unsatisfiable rungs are refused, never fudged.
        $this->assertNull(S::seatGroupsForK(152, 16, 5, 9), 'k too small: would exceed the ceiling');
        $this->assertNull(S::seatGroupsForK(152, 31, 5, 9), 'k too large: would breach the floor');
    }

    public function test_bisection_alternatives_lead_with_the_historical_choice(): void
    {
        // THE DETERMINISM GUARANTEE for backtracking (2026-07-25): the search
        // walks bisectionAlternatives() in order, so element 0 MUST be
        // bisectSizes()'s answer — otherwise every currently-drawable scope
        // would silently re-plan. Checked across shapes that exercise the
        // comparator's tie-breaks (equal halves, odd sums, repeated sizes).
        foreach ([[9, 9, 9, 9], [9, 9, 8], [9, 8, 7, 6, 5], [5, 5], [9, 9, 9, 9, 9, 8]] as $sizes) {
            $alts = S::bisectionAlternatives($sizes);
            $this->assertNotSame([], $alts);
            $this->assertSame(S::bisectSizes($sizes), $alts[0],
                'element 0 must be the historical balanced bisection: '.implode(',', $sizes));

            // Every alternative is a true partition of the multiset — no seat
            // invented, none dropped (the seating law's budget is exact).
            $expected = $sizes;
            sort($expected);
            foreach ($alts as [$a, $b]) {
                $merged = array_merge($a, $b);
                sort($merged);
                $this->assertSame($expected, $merged, 'a bisection must partition the sizes exactly');
                $this->assertNotSame([], $a);
                $this->assertNotSame([], $b);
            }

            // Mirror pairs are collapsed — A|B and B|A are one split.
            $keys = array_map(fn (array $p) => implode(',', $p[0]).'|'.implode(',', $p[1]), $alts);
            $this->assertSame(count($keys), count(array_unique($keys)));
        }
    }

    public function test_fallback_excludes_the_balanced_split_and_orders_by_balance(): void
    {
        // S=12, balanced 6:6 already tried → alts are the other lawful low
        // sides {5,7}, both one step from balance, tie broken by a asc.
        $this->assertSame([5, 7], S::lawfulTwoSplitFallback(12, 5, 9, 6));

        // S=11, balanced low side 6 already tried → only 5 remains lawful
        // (a ∈ [max(5,2), min(9,6)] = [5,6]); this is the exact Napara case.
        $this->assertSame([5], S::lawfulTwoSplitFallback(11, 5, 9, 6));

        // S=14, balanced 7:7 tried → {5,6,8,9}, ordered by |a-7|: 6 and 8
        // (1 away) before 5 and 9 (2 away), a asc within a tie.
        $this->assertSame([6, 8, 5, 9], S::lawfulTwoSplitFallback(14, 5, 9, 7));
    }

    public function test_every_returned_split_is_in_band_on_both_sides(): void
    {
        foreach (range(10, 18) as $S) {           // the full 2-district range
            foreach (S::lawfulTwoSplitFallback($S, 5, 9, (int) floor($S / 2)) as $a) {
                $b = $S - $a;
                $this->assertGreaterThanOrEqual(5, $a, "low side {$a} of S={$S} must be >= floor");
                $this->assertLessThanOrEqual(9, $a, "low side {$a} of S={$S} must be <= ceiling");
                $this->assertGreaterThanOrEqual(5, $b, "high side {$b} of S={$S} must be >= floor");
                $this->assertLessThanOrEqual(9, $b, "high side {$b} of S={$S} must be <= ceiling");
            }
        }
    }

    public function test_a_symmetric_split_offers_no_alternatives(): void
    {
        // S=10 with balanced 5:5: the only lawful split IS 5:5 (a ∈ [5,5]),
        // already tried → no fallback exists → the scope is honest review.
        $this->assertSame([], S::lawfulTwoSplitFallback(10, 5, 9, 5));
        // S=18 (9:9) likewise — the band [9,9] has one member.
        $this->assertSame([], S::lawfulTwoSplitFallback(18, 5, 9, 9));
    }
}
