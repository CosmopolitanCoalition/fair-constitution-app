<?php

namespace Tests\Constitutional;

use App\Domain\Forms\Handlers\ManualDistrictDraw;
use App\Services\Districting\PopulationRaster;
use Mockery;
use Tests\TestCase;

/**
 * PIN — F-ELB-008 piece counting under the leaf law (fix set 2026-09-02).
 *
 * THE ORIGINAL COUNT IS THE RECORD (operator ruling 2026-09-02): a MACHINE
 * piece (planned_seats + plan_pop + plan_total_pop) records the plan's own
 * lossless pixel partition and runs NO raster measurement: neither the
 * half-plane chain nor the full-grid ray-cast. A HAND-DRAWN piece still
 * measures (the chain when cut_path rides, else the ray-cast share of the
 * planning grid). A machine payload without plan counts still measures
 * (fail-closed for any older payload shape).
 *
 * The raster is a strict mock: any measurement call on the machine path
 * fails the test. No database is touched.
 */
class LeafLadderMachinePieceTest extends TestCase
{
    private const GEOJSON = '{"type":"Polygon","coordinates":[[[0,0],[1,0],[1,1],[0,1],[0,0]]]}';

    public function test_a_machine_piece_records_the_plan_count_and_runs_no_raster_measurement(): void
    {
        $raster = Mockery::mock(PopulationRaster::class);
        $raster->shouldReceive('measureByCutPath')->never();
        $raster->shouldReceive('measureWithFallback')->never();
        $raster->shouldReceive('impliedSeats')->never();
        $raster->shouldReceive('basis')->once()->with('scope-1', 2023)->andReturn('raster');

        $count = (new ManualDistrictDraw($raster))->pieceCount('scope-1', 2023, 18, 1000.0, self::GEOJSON, [
            'planned_seats'  => 9,
            'plan_pop'       => 9_100,
            'plan_total_pop' => 18_000,
            // A chain rides along and is still not measured.
            'cut_path'       => [[0.0, 1.0, 0.5, 0.0, 0.0, 1.0, 0]],
            'island_pop'     => 12.0,
        ]);

        $this->assertSame(9_100, $count['pop']);
        $this->assertEqualsWithDelta(9.1, $count['fractional'], 1e-9, 'fractional seats = S x plan_pop / plan_total_pop');
        $this->assertSame(9, $count['seats']);
        $this->assertSame('worldpop_raster', $count['source']);
    }

    public function test_a_machine_piece_on_the_area_basis_names_the_area_source(): void
    {
        $raster = Mockery::mock(PopulationRaster::class);
        $raster->shouldReceive('measureByCutPath')->never();
        $raster->shouldReceive('measureWithFallback')->never();
        $raster->shouldReceive('basis')->once()->with('scope-1', 2023)->andReturn('area');

        $count = (new ManualDistrictDraw($raster))->pieceCount('scope-1', 2023, 10, 500.0, self::GEOJSON, [
            'planned_seats'  => 5,
            'plan_pop'       => 2_600,
            'plan_total_pop' => 5_000,
        ]);

        $this->assertSame('area_proportional', $count['source']);
        $this->assertEqualsWithDelta(5.2, $count['fractional'], 1e-9);
        $this->assertSame(5, $count['seats']);
    }

    public function test_a_hand_drawn_piece_measures_by_the_ray_cast(): void
    {
        $raster = Mockery::mock(PopulationRaster::class);
        $raster->shouldReceive('measureByCutPath')->never();
        $raster->shouldReceive('basis')->never();
        $raster->shouldReceive('measureWithFallback')->once()->with('scope-1', self::GEOJSON, 2023)
            ->andReturn(['pop' => 7_000, 'source' => 'worldpop_raster']);
        $raster->shouldReceive('impliedSeats')->once()->with(7_000, 1000.0)->andReturn(7.0);

        $count = (new ManualDistrictDraw($raster))->pieceCount('scope-1', 2023, 18, 1000.0, self::GEOJSON, []);

        $this->assertSame(7_000, $count['pop']);
        $this->assertSame(7, $count['seats']);
        $this->assertSame('worldpop_raster', $count['source']);
    }

    public function test_a_hand_drawn_piece_with_a_cut_chain_measures_by_the_chain(): void
    {
        $chain = [[0.0, 1.0, 0.5, 0.0, 0.0, 1.0, 1]];
        $raster = Mockery::mock(PopulationRaster::class);
        $raster->shouldReceive('measureWithFallback')->never();
        $raster->shouldReceive('basis')->never();
        $raster->shouldReceive('measureByCutPath')->once()->with('scope-1', $chain, 2023, 40.0)
            ->andReturn(['pop' => 8_900, 'source' => 'worldpop_raster']);
        $raster->shouldReceive('impliedSeats')->once()->with(8_900, 1000.0)->andReturn(8.9);

        $count = (new ManualDistrictDraw($raster))->pieceCount('scope-1', 2023, 18, 1000.0, self::GEOJSON, [
            'cut_path'   => $chain,
            'island_pop' => 40.0,
        ]);

        $this->assertSame(8_900, $count['pop']);
        $this->assertSame(9, $count['seats']);
    }

    public function test_a_machine_piece_without_plan_counts_still_measures(): void
    {
        $raster = Mockery::mock(PopulationRaster::class);
        $raster->shouldReceive('basis')->never();
        $raster->shouldReceive('measureWithFallback')->once()->with('scope-1', self::GEOJSON, 2023)
            ->andReturn(['pop' => 9_000, 'source' => 'worldpop_raster']);
        $raster->shouldReceive('impliedSeats')->once()->with(9_000, 1000.0)->andReturn(9.0);

        $count = (new ManualDistrictDraw($raster))->pieceCount('scope-1', 2023, 18, 1000.0, self::GEOJSON, [
            'planned_seats' => 9,
        ]);

        $this->assertSame(9_000, $count['pop']);
        $this->assertSame(9, $count['seats']);
    }
}
