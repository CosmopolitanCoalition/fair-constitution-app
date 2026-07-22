<?php

namespace Tests\Constitutional;

use App\Services\Districting\PopulationRaster;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — half-plane cut-path measurement (operator ruling
 * 2026-07-22, "a simpler strategy to cut the line").
 *
 * A machine-cut piece is the intersection of half-planes down its cut path;
 * its measured population is the grid mass satisfying every level's side
 * test — the planner's own total per-point rule re-applied, pure arithmetic,
 * no geometry SQL. This pins the arithmetic contract: side-0 is the t < c
 * side exactly as findBlade assigns it, cascades intersect, totality holds
 * (every point lands on exactly one leaf of a full cut tree), and the sums
 * agree with splitByBlade's half-plane split.
 *
 * If an edit breaks these, the edit is the constitutional violation — fix
 * the edit, not the test.
 */
class CutPathMeasurementTest extends TestCase
{
    /** A 4-point grid: two west (100 + 200), two east (300 + 400). */
    private function grid(): array
    {
        return [
            [10.0, 40.0, 100.0],
            [10.2, 40.5, 200.0],
            [11.0, 40.0, 300.0],
            [11.2, 40.5, 400.0],
        ];
    }

    /** A vertical blade at x = 10.5 in a unit frame: nx=-1 (theta 90°), t = -(x-lon0). */
    private function verticalFrame(float $cutX, float $lon0 = 10.0, float $lat0 = 40.0): array
    {
        // t = (x - lon0)·cosLat·nx + (y - lat0)·ny with nx=-1, ny=0, cosLat=1
        // → t = -(x - lon0); t < c ⇔ x > lon0 - c. With c = -(cutX - lon0):
        // side 0 (t < c) = points EAST of cutX.
        return [-1.0, 0.0, -($cutX - $lon0), $lon0, $lat0, 1.0];
    }

    public function test_single_level_sides_partition_the_grid(): void
    {
        $grid = $this->grid();
        $east = array_merge($this->verticalFrame(10.5), [0]);   // side 0 = t < c = east
        $west = array_merge($this->verticalFrame(10.5), [1]);   // side 1 = west

        [$sumEast, $total] = PopulationRaster::sumByCutPath($grid, [$east]);
        [$sumWest] = PopulationRaster::sumByCutPath($grid, [$west]);

        $this->assertEqualsWithDelta(700.0, $sumEast, 1e-9, 'side 0 (t < c) holds the east lumps');
        $this->assertEqualsWithDelta(300.0, $sumWest, 1e-9, 'side 1 holds the west lumps');
        $this->assertEqualsWithDelta(1000.0, $total, 1e-9);
        $this->assertEqualsWithDelta($total, $sumEast + $sumWest, 1e-9, 'totality: the two sides partition the grid exactly');
    }

    public function test_cascaded_levels_intersect(): void
    {
        $grid = $this->grid();
        // Level 1: east of 10.5 (side 0). Level 2: horizontal blade at
        // y = 40.25 — theta 0°: nx=0, ny=1, t = y - lat0; side 0 = t < c = south.
        $level1 = array_merge($this->verticalFrame(10.5), [0]);
        $level2 = [0.0, 1.0, 0.25, 10.0, 40.0, 1.0, 0];

        [$sumSE] = PopulationRaster::sumByCutPath($grid, [$level1, $level2]);
        [$sumNE] = PopulationRaster::sumByCutPath($grid, [$level1, [0.0, 1.0, 0.25, 10.0, 40.0, 1.0, 1]]);

        $this->assertEqualsWithDelta(300.0, $sumSE, 1e-9, 'east ∩ south = the 300 lump');
        $this->assertEqualsWithDelta(400.0, $sumNE, 1e-9, 'east ∩ north = the 400 lump');
    }

    public function test_empty_path_measures_the_whole_scope(): void
    {
        [$sum, $total] = PopulationRaster::sumByCutPath($this->grid(), []);
        $this->assertEqualsWithDelta($total, $sum, 1e-9, 'an empty chain is the whole scope (a single-district filing)');
    }

    public function test_agrees_with_split_by_blade(): void
    {
        $grid = $this->grid();
        // splitByBlade with a vertical line at x=10.5 going north: left of
        // the direction = west. Compare mass partitions.
        [$left, $right] = PopulationRaster::splitByBlade($grid, 10.5, 39.0, 10.5, 42.0);
        [$sumEast] = PopulationRaster::sumByCutPath($grid, [array_merge($this->verticalFrame(10.5), [0])]);
        [$sumWest] = PopulationRaster::sumByCutPath($grid, [array_merge($this->verticalFrame(10.5), [1])]);

        $this->assertEqualsWithDelta($left + $right, $sumEast + $sumWest, 1e-9);
        $this->assertTrue(
            (abs($left - $sumWest) < 1e-9 && abs($right - $sumEast) < 1e-9)
            || (abs($left - $sumEast) < 1e-9 && abs($right - $sumWest) < 1e-9),
            'the half-plane chain partitions mass identically to the blade split'
        );
    }
}
