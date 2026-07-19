<?php

namespace Tests\Constitutional;

use App\Services\Legislature\TypeBSeatLadder;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — THE TYPE B LADDER (operator ruling 2026-07-19).
 *
 * Type A and Type B are INDEPENDENTLY sized chambers — no combined ceiling.
 * Type B (equal representation of the direct constituents) is bound by the
 * same sizing rule: its total may not exceed the lawful legislature size,
 * which is Type A. Each constituent contributes rep_floor seats, EXCEPT
 * pop ≤ 5 contributes min(pop, rep_floor) — a zero-population space seats
 * nobody. The ladder starts at the setting (default 5) and descends
 * 4 → 3 → 2; still over at 2 → keep the floor-2 value and FLAG for the
 * deferred Type B districting (never total-forced, never silently clamped).
 *
 * Pure arithmetic — no database. If an edit breaks these, the edit is the
 * constitutional violation.
 */
class TypeBLadderTest extends TestCase
{
    public function test_equal_representation_holds_at_five_when_the_bound_allows(): void
    {
        // Pinland's shape: 5 constituents all above tiny-pop, Type A 34.
        $r = TypeBSeatLadder::apportion(34, [8000, 7000, 7000, 7000, 12000]);
        $this->assertSame(['seats' => 25, 'rep_floor' => 5, 'needs_districting' => false], $r);
    }

    public function test_exactly_at_the_bound_is_lawful(): void
    {
        // Quarter 1's shape: 4 × 5 = 20 = Type A 20 — the bound is ≤, not <.
        $r = TypeBSeatLadder::apportion(20, [2000, 2000, 2000, 2000]);
        $this->assertSame(['seats' => 20, 'rep_floor' => 5, 'needs_districting' => false], $r);
    }

    public function test_tiny_constituents_contribute_their_population(): void
    {
        // pops [0, 3, 5, 100]: 0 + 3 + 5 + 5 = 13 at rep 5 — a
        // zero-population space seats NOBODY.
        $r = TypeBSeatLadder::apportion(50, [0, 3, 5, 100]);
        $this->assertSame(13, $r['seats']);
        $this->assertSame(5, $r['rep_floor']);
        $this->assertFalse($r['needs_districting']);
    }

    public function test_the_ladder_descends_to_the_largest_lawful_rep(): void
    {
        // 10 big constituents, Type A 42: 5×10=50 > 42, 4×10=40 ≤ 42 → 4.
        $pops = array_fill(0, 10, 1000);
        $r = TypeBSeatLadder::apportion(42, $pops);
        $this->assertSame(['seats' => 40, 'rep_floor' => 4, 'needs_districting' => false], $r);

        // Type A 31: 4×10=40 > 31, 3×10=30 ≤ 31 → 3.
        $r = TypeBSeatLadder::apportion(31, $pops);
        $this->assertSame(['seats' => 30, 'rep_floor' => 3, 'needs_districting' => false], $r);

        // Type A 21: descends all the way to 2 (20 ≤ 21) — lawful, unflagged.
        $r = TypeBSeatLadder::apportion(21, $pops);
        $this->assertSame(['seats' => 20, 'rep_floor' => 2, 'needs_districting' => false], $r);
    }

    public function test_still_over_at_two_keeps_the_value_and_flags(): void
    {
        // The Minas-Gerais shape: 862 municipalities, Type A 278.
        // 2 × 862 = 1,724 > 278 → floor-2 value kept + FLAGGED for the
        // deferred Type B districting. Never clamped to Type A (that would
        // be total-forcing), never silently dropped.
        $pops = array_fill(0, 862, 10000);
        $r = TypeBSeatLadder::apportion(278, $pops);
        $this->assertSame(1724, $r['seats']);
        $this->assertSame(2, $r['rep_floor']);
        $this->assertTrue($r['needs_districting']);
    }

    public function test_tiny_cap_applies_at_every_rung(): void
    {
        // pops [2, 2, big×6], Type A 20: @5 → 2+2+30=34 >20; @4 → 2+2+24=28
        // >20; @3 → 2+2+18=22 >20; @2 → 2+2+12=16 ≤20 → rep 2, no flag.
        $r = TypeBSeatLadder::apportion(20, [2, 2, 100, 100, 100, 100, 100, 100]);
        $this->assertSame(['seats' => 16, 'rep_floor' => 2, 'needs_districting' => false], $r);
    }

    public function test_starting_rep_setting_is_respected(): void
    {
        // A jurisdiction whose constitution starts at 3 never tries 5 or 4.
        $r = TypeBSeatLadder::apportion(100, [1000, 1000], 3);
        $this->assertSame(['seats' => 6, 'rep_floor' => 3, 'needs_districting' => false], $r);
    }

    public function test_no_constituents_means_no_type_b(): void
    {
        $r = TypeBSeatLadder::apportion(23, []);
        $this->assertSame(0, $r['seats']);
        $this->assertFalse($r['needs_districting']);
    }
}
