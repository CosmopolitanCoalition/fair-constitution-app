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
