<?php

namespace Tests\Constitutional;

use App\Services\Legislature\TypeBSeatLadder;
use PHPUnit\Framework\TestCase;

/**
 * THE TYPE B BOUND: type_b may not exceed type_a — and when it does anyway,
 * the chamber MUST be flagged for grouping.
 *
 * CLAUDE.md, Bicameral Support (settled 2026-07-26): *"The only bound on
 * Type B: it may not exceed the Type A total."* Reduce in two stages, in
 * order: step seats-per-constituent 5 → 4 → 3 → 2, and only if two-per-
 * constituent still overflows, group whole constituents into shared panels.
 *
 * **Stage two is not built.** So there is a real, expected state in which a
 * chamber exceeds the bound: the ladder has done everything it can and is
 * waiting for grouping that does not yet exist. That state is lawful ONLY
 * because it is recorded — `type_b_needs_districting` is the worklist marker,
 * and a chamber over the bound without it would be a silent violation.
 *
 * So the invariant these pin is not "type_b <= type_a" (which is false today
 * for 9,708 real chambers) but the stronger, checkable one:
 *
 *     type_b > type_a  IF AND ONLY IF  needs_districting
 *
 * Measured on the seeded planet: 9,708 over the bound, 9,708 flagged, **0 over
 * and unflagged, 0 flagged and within**. Perfect correspondence — the data is
 * honest, and this file is what keeps it that way.
 *
 * If an edit breaks these, the edit is the constitutional violation —
 * fix the edit, not the test.
 */
class TypeBBoundTest extends TestCase
{
    /**
     * NIUE, the real case that surfaced this. 14 constituent villages, seven
     * of them recorded with zero population.
     *
     * The mechanism is NOT "the ladder descended to one seat per constituent"
     * — it never goes below two. It is that seven INHABITED villages take two
     * seats each (14) while seven empty ones take none, against a type_a of
     * 11. The ladder is out of rungs and says so.
     */
    public function test_niue_overflows_at_the_floor_and_is_flagged_not_silently_shipped(): void
    {
        $villages = [0, 0, 0, 0, 0, 0, 0, 50, 89, 100, 117, 173, 224, 436];

        $result = TypeBSeatLadder::apportion(11, $villages, 5);

        $this->assertSame(14, $result['seats'], '7 inhabited villages x 2 seats');
        $this->assertSame(2, $result['rep_floor'], 'the ladder floors at two, it does not reach one');
        $this->assertTrue(
            $result['needs_districting'],
            'a chamber over the bound MUST be flagged — an unflagged overflow is a silent violation',
        );
    }

    /**
     * THE INVARIANT ITSELF, over a spread of shapes: exceeding the bound and
     * carrying the flag are the same event. Neither may occur without the
     * other.
     */
    public function test_over_the_bound_if_and_only_if_flagged(): void
    {
        $cases = [
            [11, [0, 0, 0, 0, 0, 0, 0, 50, 89, 100, 117, 173, 224, 436]],  // Niue
            [32, [1002, 1200, 1500, 2000, 2500, 3000, 3500, 4000, 4500]],  // San Marino, 9 castelli
            [5,  [100, 200, 300]],                                          // small, 3 constituents
            [50, [10, 20, 30, 40]],                                         // roomy
            [4,  [10, 20, 30, 40, 50, 60]],                                 // hopeless
            [9,  []],                                                       // a leaf
            [7,  [0, 0, 0]],                                                // all empty
        ];

        foreach ($cases as [$typeA, $children]) {
            $r = TypeBSeatLadder::apportion($typeA, $children, 5);
            $over = $r['seats'] > $typeA;

            $this->assertSame(
                $over,
                $r['needs_districting'],
                sprintf(
                    'type_a=%d children=%d produced seats=%d flagged=%s — over-the-bound and flagged must agree',
                    $typeA,
                    count($children),
                    $r['seats'],
                    $r['needs_districting'] ? 'true' : 'false',
                ),
            );
        }
    }

    /** The ladder never descends below two per constituent. */
    public function test_the_ladder_floors_at_two_per_constituent(): void
    {
        // Hopeless: six inhabited constituents cannot fit in four seats at any
        // lawful rep floor.
        $r = TypeBSeatLadder::apportion(4, [10, 20, 30, 40, 50, 60], 5);

        $this->assertSame(2, $r['rep_floor']);
        $this->assertSame(12, $r['seats'], 'six constituents x the floor of two');
        $this->assertTrue($r['needs_districting']);
    }

    /**
     * A place with nobody in it seats nobody. Zero-population constituents
     * contribute zero, not a floor — which is why Niue's fourteen villages
     * produce fourteen seats rather than twenty-eight.
     */
    public function test_an_empty_constituent_seats_nobody(): void
    {
        // Three empty constituents seat nobody, and nothing overflows, so the
        // ladder never descends: it keeps the full starting rep floor.
        $all_empty = TypeBSeatLadder::apportion(9, [0, 0, 0], 5);
        $this->assertSame(0, $all_empty['seats']);
        $this->assertSame(5, $all_empty['rep_floor']);

        // Mixed: two empty, two inhabited, against a type_a of 9.
        //   f=5 → 0 + 0 + 5 + 5 = 10, over the bound, descend
        //   f=4 → 0 + 0 + 4 + 4 =  8, fits → stop
        // So the answer is EIGHT at a rep floor of four, not four at a floor
        // of two. My first version of this pin asserted 4, having assumed the
        // floor without working the descent — the ladder stops at the most
        // GENEROUS floor that fits, and only the empty constituents are
        // reduced to nothing.
        $mixed = TypeBSeatLadder::apportion(9, [0, 0, 100, 200], 5);
        $this->assertSame(8, $mixed['seats']);
        $this->assertSame(4, $mixed['rep_floor']);
        $this->assertFalse($mixed['needs_districting']);
    }

    /** A leaf has no constituents to represent, so it has no Type B at all. */
    public function test_a_leaf_has_no_type_b(): void
    {
        $r = TypeBSeatLadder::apportion(9, [], 5);

        $this->assertSame(0, $r['seats']);
        $this->assertFalse($r['needs_districting'], 'nothing to group — a leaf is not a deferred case');
    }

    /** Within the bound, the ladder stops at the highest rep floor that fits. */
    public function test_the_ladder_takes_the_most_generous_floor_that_fits(): void
    {
        // 9 constituents; at 5 apiece = 45 > 32; at 4 = 36 > 32; at 3 = 27 <= 32.
        $r = TypeBSeatLadder::apportion(32, array_fill(0, 9, 1000), 5);

        $this->assertSame(3, $r['rep_floor'], 'it should descend only as far as it must');
        $this->assertSame(27, $r['seats']);
        $this->assertFalse($r['needs_districting']);
    }
}
