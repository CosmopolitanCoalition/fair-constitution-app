<?php

namespace Tests\Constitutional;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the coverage-gate must not OOM on a mega scope and must
 * not overflow on a nodata-less raster (2026-07-21, the run-6 mega-area crash
 * class).
 *
 * PopulationRaster::coverageStats decides whether the WorldPop raster
 * meaningfully covers a scope (hasRasterCoverage / basis). It formerly called
 * population_within_multi, whose ST_Union(rast,'MAX') builds one mosaic over
 * the whole scope — a 95,902 km² Siberian district then allocated a planet-
 * scale raster and the postgres backend was OOM-killed (signal 9), looping the
 * whole run. The fix clips each intersecting tile independently and sums, with
 * the WorldPop -99999 nodata passed explicitly so a band lacking its own nodata
 * cannot leave a ±3.4e38 float fill in the crop's outside pixels (which
 * ROUND(...)::bigint would reject as "bigint out of range").
 *
 * This pins the ARITHMETIC directly on the two raster shapes the change turns
 * on: a full-coverage clip sums correctly, and a nodata-less band whose scope
 * only grazes the tile returns 0 (not an overflow). The mega OOM avoidance is
 * structural (no ST_Union anywhere in coverageStats) and is exercised
 * end-to-end by AutoscalePinTest's NULL-nodata fixture.
 *
 * If an edit breaks these, the edit is the constitutional violation — fix the
 * edit, not the test.
 */
class CoverageGateRobustnessTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_coverage_gate';

    /** The exact per-tile coverage sum coverageStats runs, over an ad-hoc raster. */
    private function coverageSum(string $rasterExpr, string $geomWkt): ?int
    {
        $row = DB::selectOne(
            "WITH r AS (SELECT {$rasterExpr} AS rast),
                  g AS (SELECT ST_GeomFromText(?, 4326) AS geom)
             SELECT COALESCE(
                 ROUND(SUM((ST_SummaryStats(
                     ST_Clip(r.rast, 1, g.geom, -99999::double precision, TRUE)
                 )).sum))::bigint, 0) AS pop
               FROM r, g WHERE ST_Intersects(r.rast, g.geom)",
            [$geomWkt]
        );

        return $row === null ? null : (int) $row->pop;
    }

    public function test_no_nodata_band_grazed_by_scope_returns_zero_not_overflow(): void
    {
        $this->onLivePg(function (): void {
            // A NULL-nodata 32BF band (the AutoscalePin fixture shape): a scope
            // that only grazes the tile bbox covers ~no pixel centers. The crop
            // then fills its outside pixels with the float sentinel; without the
            // explicit -99999 nodata this summed to ±3.4e38 and ROUND::bigint
            // threw. It must resolve to 0.
            $raster = "ST_AddBand(ST_MakeEmptyRaster(80,20,10.0,50.1,0.005,-0.005,0,0,4326),'32BF'::text, 7.5, NULL)";
            $grazing = 'POLYGON((10.3999 49.9999, 10.4001 49.9999, 10.4001 50.0001, 10.3999 50.0001, 10.3999 49.9999))';

            $this->assertSame(0, $this->coverageSum($raster, $grazing));
        });
    }

    public function test_full_and_partial_coverage_sum_correctly_excluding_fill(): void
    {
        $this->onLivePg(function (): void {
            // Same band; a scope covering a known sub-rectangle sums only its
            // real pixels — fill never leaks in. 40×18 pixels × 7.5 = 5400.
            $raster = "ST_AddBand(ST_MakeEmptyRaster(80,20,10.0,50.1,0.005,-0.005,0,0,4326),'32BF'::text, 7.5, NULL)";
            $covering = 'POLYGON((10.05 50.0, 10.25 50.0, 10.25 50.09, 10.05 50.09, 10.05 50.0))';

            $this->assertSame(5400, $this->coverageSum($raster, $covering));
        });
    }

    private function onLivePg(callable $body): void
    {
        $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        try {
            $body();
        } finally {
            DB::setDefaultConnection($original);
        }
    }
}
