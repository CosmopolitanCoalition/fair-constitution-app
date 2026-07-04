<?php

namespace Tests\Constitutional;

use App\Services\Districting\SubdivisionCellSeedService;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase 5, the community-cells autoseed's pure diagram
 * math (no DB). The balanced power diagram must be exactly reproducible on
 * every mesh node (the plan_hash receipt depends on it), so these pins nail:
 *  1. Sutherland–Hodgman half-plane clipping (the exact cell polygons —
 *     every pairwise radical axis is a straight line);
 *  2. the Aurenhammer-style weight balancer: hits its targets on a synthetic
 *     grid, deterministically, and fails PLAINLY when it cannot converge;
 *  3. density-peak seed picking: greedy by (val desc, lon asc, lat asc) with
 *     a deterministic min-separation relaxation.
 *
 * If an edit breaks these, the edit is the constitutional violation — fix the
 * edit, not the test.
 */
class SubdivisionCellSeedTest extends TestCase
{
    // ── 1. Sutherland–Hodgman ───────────────────────────────────────────────

    public function test_half_plane_clipping_pins(): void
    {
        $square = [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]];

        // x ≤ 0.5 keeps the left half, cutting two edges exactly at x = 0.5.
        $left = SubdivisionCellSeedService::clipHalfPlane($square, 1.0, 0.0, 0.5);
        $this->assertCount(4, $left);
        $this->assertEqualsWithDelta(0.5, self::ringArea($left), 1e-12);
        foreach ($left as $pt) {
            $this->assertLessThanOrEqual(0.5 + 1e-12, $pt[0]);
        }

        // A diagonal half-plane x + y ≤ 1 keeps the lower-left triangle.
        $tri = SubdivisionCellSeedService::clipHalfPlane($square, 1.0, 1.0, 1.0);
        $this->assertEqualsWithDelta(0.5, self::ringArea($tri), 1e-12);

        // Fully inside → unchanged; fully outside → empty.
        $this->assertSame($square, SubdivisionCellSeedService::clipHalfPlane($square, 1.0, 0.0, 2.0));
        $this->assertSame([], SubdivisionCellSeedService::clipHalfPlane($square, 1.0, 0.0, -1.0));
    }

    // ── 2. the weight balancer ──────────────────────────────────────────────

    public function test_the_weight_balancer_hits_its_targets_deterministically(): void
    {
        // A 10×10 unit-population grid with deliberately OFF-CENTER seeds:
        // the unweighted diagram splits 40:60, so the balancer must actually
        // move weight to reach the 50:50 target.
        $px = [];
        for ($x = 0; $x < 10; $x++) {
            for ($y = 0; $y < 10; $y++) {
                $px[] = [(float) $x, (float) $y, 1.0];
            }
        }
        $seeds = [[1.5, 4.5], [5.5, 4.5]];
        $targets = [50.0, 50.0];

        [$w1, $pops1] = SubdivisionCellSeedService::balanceWeights($px, $seeds, $targets);
        $this->assertEqualsWithDelta(50.0, $pops1[0], 50.0 * SubdivisionCellSeedService::TOLERANCE);
        $this->assertEqualsWithDelta(50.0, $pops1[1], 50.0 * SubdivisionCellSeedService::TOLERANCE);
        $this->assertEqualsWithDelta(100.0, $pops1[0] + $pops1[1], 1e-9, 'every pixel lands in exactly one cell');

        // Identical inputs → the identical weights and pops (mesh determinism).
        [$w2, $pops2] = SubdivisionCellSeedService::balanceWeights($px, $seeds, $targets);
        $this->assertSame($w1, $w2);
        $this->assertSame($pops1, $pops2);
    }

    public function test_the_weight_balancer_balances_an_uneven_two_cluster_grid(): void
    {
        // Two clusters: a dense 5×5 (val 3 → 75 people) and a sparse 5×5
        // (val 1 → 25 people) 10 units apart. A 60:40 target forces the
        // sparse seed's cell to reach across and capture a dense column
        // (25 + one 15-person column = 40 exactly).
        $px = [];
        for ($x = 0; $x < 5; $x++) {
            for ($y = 0; $y < 5; $y++) {
                $px[] = [(float) $x, (float) $y, 3.0];
                $px[] = [(float) $x + 10.0, (float) $y, 1.0];
            }
        }
        $seeds = [[2.0, 2.0], [12.0, 2.0]];

        [, $pops] = SubdivisionCellSeedService::balanceWeights($px, $seeds, [60.0, 40.0]);
        $this->assertEqualsWithDelta(60.0, $pops[0], 60.0 * SubdivisionCellSeedService::TOLERANCE);
        $this->assertEqualsWithDelta(40.0, $pops[1], 40.0 * SubdivisionCellSeedService::TOLERANCE);
    }

    public function test_the_weight_balancer_fails_plainly_when_it_cannot_converge(): void
    {
        // Two pixels of 10 people each cannot split 15:5 (population moves in
        // whole-pixel lumps) — the balancer must throw, never spin or fake it.
        $px = [[0.0, 0.0, 10.0], [10.0, 0.0, 10.0]];
        $seeds = [[0.0, 0.0], [10.0, 0.0]];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('did not converge');
        SubdivisionCellSeedService::balanceWeights($px, $seeds, [15.0, 5.0]);
    }

    // ── 3. seed picking ─────────────────────────────────────────────────────

    public function test_seed_picking_takes_density_peaks_min_separated(): void
    {
        // A flat 5×5 field of 1s plus two towers of 9 — the towers win, and
        // the flat tie-break is (lon asc, lat asc).
        $px = [];
        for ($x = 0; $x < 5; $x++) {
            for ($y = 0; $y < 5; $y++) {
                $px[] = [(float) $x, (float) $y, 1.0];
            }
        }
        $px[] = [1.0, 1.0, 9.0];   // index 25
        $px[] = [3.0, 3.0, 9.0];   // index 26

        $seeds = SubdivisionCellSeedService::pickSeeds($px, 2, 1.0);
        $this->assertSame([25, 26], $seeds, 'the two density towers are the seeds');

        // Third seed: the first flat pixel ≥ minSep from both towers — (0,0)
        // wins the (val desc, lon asc, lat asc) order at minSep 1.
        $three = SubdivisionCellSeedService::pickSeeds($px, 3, 1.0);
        $this->assertSame([25, 26, 0], $three);
    }

    public function test_seed_picking_relaxes_min_separation_deterministically(): void
    {
        // Four pixels 1 apart with an impossible minSep of 100 — the halving
        // relaxation must still find all four, in the total order.
        $px = [[0.0, 0.0, 4.0], [1.0, 0.0, 3.0], [0.0, 1.0, 2.0], [1.0, 1.0, 1.0]];

        $seeds = SubdivisionCellSeedService::pickSeeds($px, 4, 100.0);
        $this->assertSame([0, 1, 2, 3], $seeds);
    }

    // -------------------------------------------------------------------------

    /** Shoelace area of an open ring. */
    private static function ringArea(array $ring): float
    {
        $n = count($ring);
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            [$x1, $y1] = $ring[$i];
            [$x2, $y2] = $ring[($i + 1) % $n];
            $sum += $x1 * $y2 - $x2 * $y1;
        }

        return abs($sum) / 2;
    }
}
