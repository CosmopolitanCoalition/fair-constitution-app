<?php

namespace Tests\Constitutional;

use App\Support\BenchLaw;
use App\Support\QuorumLaw;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — THE BENCH LAW and ONE QUORUM FORMULA (operator rulings
 * 2026-09-05, bench-scaling-law B and bench-and-quorum-law A).
 *
 *   bench  = max(floor, next odd >= type_a_seats / 10), a minimum multiple
 *            where n constituents nominate (judges per constituent =
 *            ceil(bench / n));
 *   quorum = 0 for no seats, else min(seats, max(3, ceil(seats / 2))).
 *
 * The floor is the instance's own setting (5 by default, movable). DB-free.
 * If an edit breaks these, the edit is the violation — fix the edit.
 */
class BenchLawTest extends TestCase
{
    public function test_the_bench_follows_the_chamber_from_the_floor(): void
    {
        $this->assertSame(5,  BenchLaw::bench(5, 5),    'a 5-seat chamber sits the floor');
        $this->assertSame(5,  BenchLaw::bench(50, 5),   '50 seats → 5.0 → next odd 5');
        $this->assertSame(7,  BenchLaw::bench(51, 5),   '51 seats → 5.1 → 6 → next odd 7');
        $this->assertSame(7,  BenchLaw::bench(60, 5),   '60 seats → 6 → next odd 7');
        $this->assertSame(45, BenchLaw::bench(439, 5),  'Germany 439 → 43.9 → 44 → 45');
        $this->assertSame(201, BenchLaw::bench(1999, 5), 'Earth 1,999 → 199.9 → 200 → 201');
        $this->assertSame(7,  BenchLaw::bench(5, 7),    'the floor is the setting, not a literal 5');
        $this->assertSame(1,  BenchLaw::bench(0, 1),    'a 0-seat chamber holds the floor');
    }

    public function test_next_odd_is_the_smallest_odd_integer_at_or_above_x(): void
    {
        $this->assertSame(1, BenchLaw::nextOdd(0.0));
        $this->assertSame(1, BenchLaw::nextOdd(0.4));
        $this->assertSame(1, BenchLaw::nextOdd(1.0));
        $this->assertSame(3, BenchLaw::nextOdd(1.1));
        $this->assertSame(3, BenchLaw::nextOdd(2.0));
        $this->assertSame(3, BenchLaw::nextOdd(3.0));
        $this->assertSame(5, BenchLaw::nextOdd(3.01));
    }

    public function test_constituent_courts_take_the_bench_as_a_minimum_multiple(): void
    {
        // 45 over 16 constituents: ceil(45/16) = 3 per constituent → 48.
        $this->assertSame(3,  BenchLaw::perConstituent(439, 5, 16));
        $this->assertSame(48, BenchLaw::bench(439, 5, 16));
        // 5 over 9 constituents: 1 each → 9 (San Marino's castelli).
        $this->assertSame(1, BenchLaw::perConstituent(32, 5, 9));
        $this->assertSame(9, BenchLaw::bench(32, 5, 9));
        // One constituent is no multiple.
        $this->assertSame(5, BenchLaw::bench(32, 5, 1));
        $this->assertSame(0, BenchLaw::perConstituent(32, 5, 0));
    }

    public function test_the_sql_mirror_carries_the_same_arithmetic_in_its_text(): void
    {
        $sql = BenchLaw::sql('s', 'f', 'c');
        $this->assertStringContainsString('/ 10.0', $sql, 'the tenth');
        $this->assertStringContainsString('MOD(', $sql, 'the next odd');
        $this->assertStringContainsString('GREATEST(GREATEST(1, (f))', $sql, 'the floor');
        $this->assertStringContainsString('(c) > 1', $sql, 'the constituent multiple');
    }

    public function test_one_quorum_formula_never_exceeds_the_chamber(): void
    {
        $this->assertSame(0, QuorumLaw::required(0), 'no seats, no quorum');
        $this->assertSame(1, QuorumLaw::required(1), 'a 1-seat chamber convenes with its member');
        $this->assertSame(2, QuorumLaw::required(2));
        $this->assertSame(3, QuorumLaw::required(3));
        $this->assertSame(3, QuorumLaw::required(5));
        $this->assertSame(5, QuorumLaw::required(9));
        $this->assertSame(220, QuorumLaw::required(439));
        $this->assertSame(1000, QuorumLaw::required(1999));
        $this->assertStringContainsString('LEAST((x), GREATEST(3', QuorumLaw::sql('x'));
    }
}
