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
 * THE COMBINED CAP (B3, operator, Wave 2 review): the invariant above is stated
 * against Type A because every case here lives in the population-AMPLE regime
 * (population ≥ 2·type_a), where Type A is the tighter bound. B3 adds a SECOND,
 * always-true bound: type_a + type_b may never exceed population — never more
 * representatives than people. The effective Type B bound is the tighter of
 * Type A and (population − type_a); it only bites where equal representation
 * would otherwise out-seat the residents (small / highly-fragmented parents).
 * Pinned below in test_b3_*: seats ≤ max(0, population − type_a), ALWAYS.
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

    // ── B3 — THE COMBINED POPULATION CAP ─────────────────────────────────────
    // type_a + type_b may never exceed population. Never more reps than people.

    /**
     * A place small enough that equal representation would out-seat its
     * residents has type_b floored to what population leaves after type_a —
     * WITHOUT needing districting when the capped seats still fit at a lawful
     * rep floor. pop 9, two constituents (5, 4), type_a 5: population leaves
     * 9 − 5 = 4 for type_b; the ladder descends to 2-per (2 + 2 = 4) and fits.
     */
    public function test_the_cap_floors_type_b_to_the_seats_population_leaves(): void
    {
        $r = TypeBSeatLadder::apportion(5, [5, 4], 5, 9);

        $this->assertSame(4, $r['seats'], 'population (9) − type_a (5) = 4 seats for type_b');
        $this->assertSame(2, $r['rep_floor']);
        $this->assertFalse($r['needs_districting'], 'the capped seats fit at floor 2 — no grouping owed');
        $this->assertSame(9, 5 + $r['seats'], 'type_a + type_b == population, exactly');
    }

    /**
     * When even the floor-2 placeholder would out-seat the residents, the
     * placeholder ITSELF is floored by the cap, and the chamber is still
     * flagged for grouping (the survivors must be distributed equally). pop 8,
     * three constituents (3, 3, 2), type_a 5: floor-2 demand is 6, but only
     * 8 − 5 = 3 seats remain — capped to 3, flagged.
     */
    public function test_the_cap_floors_even_the_flagged_placeholder(): void
    {
        $r = TypeBSeatLadder::apportion(5, [3, 3, 2], 5, 8);

        $this->assertSame(3, $r['seats'], 'floor-2 demand 6 capped to population − type_a = 3');
        $this->assertSame(2, $r['rep_floor']);
        $this->assertTrue($r['needs_districting'], 'still owes grouping to seat the 3 survivors equally');
        $this->assertLessThanOrEqual(8, 5 + $r['seats'], 'type_a + type_b never exceeds population');
    }

    /**
     * THE UNIVERSAL B3 INVARIANT: across every shape, type_b never exceeds the
     * seats population leaves after type_a. In the ample regime this is slack
     * (Type A binds first); in the tight regime it is exact. Never violated.
     */
    public function test_type_b_never_exceeds_what_population_leaves(): void
    {
        $cases = [
            // [type_a, children, population]
            [11, [0, 0, 0, 0, 0, 0, 0, 50, 89, 100, 117, 173, 224, 436], 1189], // Niue — ample
            [32, [1002, 1200, 1500, 2000, 2500, 3000, 3500, 4000, 4500], 23202], // San Marino — ample
            [5,  [5, 4], 9],                                                       // tight, fits
            [5,  [3, 3, 2], 8],                                                    // tight, flagged
            [5,  [2, 2], 4],                                                       // hopeless: pop == type_a
            [6,  array_fill(0, 100, 2), 200],                                      // many tiny: floor-2 (200) > pop − a (194)
        ];

        foreach ($cases as [$typeA, $children, $pop]) {
            $r   = TypeBSeatLadder::apportion($typeA, $children, 5, $pop);
            $cap = max(0, $pop - $typeA);

            $this->assertLessThanOrEqual(
                $cap,
                $r['seats'],
                sprintf(
                    'type_a=%d pop=%d → type_b=%d must not exceed population − type_a = %d',
                    $typeA, $pop, $r['seats'], $cap,
                ),
            );
        }
    }
}
