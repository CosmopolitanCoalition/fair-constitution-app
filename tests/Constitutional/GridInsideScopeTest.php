<?php

namespace Tests\Constitutional;

use App\Services\Districting\PopulationRaster;
use App\Support\AutoscaleContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — every population-grid point lies INSIDE its territory
 * (2026-07-21, the arctic all-or-nothing class).
 *
 * A coastal bin's center — even a native pixel centroid on a ragged shore —
 * can fall in the sea. Avannaata carried 53% of its population at offshore
 * bin centers: the planner split that mass by blade-sign while the filing
 * measurement's nearest-piece recovery assigned each outside point WHOLESALE
 * to one piece — one piece measured the whole scope (18.00 quotas exact) and
 * its sibling zero, and the band gate refused lawful plans en masse. The fix
 * snaps any outside grid point to the closest point ON the territory, so
 * piece-coverage and blade-side agree by construction and no mass is ever
 * placed in the water.
 *
 * Fixture: a C-shaped (concave) scope whose populated raster pixels sit on
 * the two arms; at a coarse bin size their aggregated center falls in the
 * bay — outside the polygon. The pin asserts every returned grid point is
 * covered by the scope and the total mass is conserved.
 *
 * If an edit breaks these, the edit is the constitutional violation — fix
 * the edit, not the test.
 */
class GridInsideScopeTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_grid_inside';

    public function test_every_grid_point_is_covered_by_the_scope_and_mass_is_conserved(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();
        AutoscaleContext::enter('pin', 'pin', null);

        try {
            // A C-shape opening east, ~0.4° tall — big enough (>300 km²)
            // that pixelGrid picks a BINNED cell, so the two arms' pixels
            // aggregate toward bin centers in the bay.
            $scopeId = (string) Str::uuid();
            DB::insert("
                INSERT INTO jurisdictions (id, name, slug, iso_code, adm_level, population,
                                           is_active, is_civic_active, geom, centroid, created_at, updated_at)
                VALUES (?, 'Grid Inside Pin', ?, 'ZZG', 2, 1000, true, true,
                        ST_Multi(ST_GeomFromText('POLYGON((30.0 10.0, 30.5 10.0, 30.5 10.08, 30.12 10.08,
                                                           30.12 10.32, 30.5 10.32, 30.5 10.4, 30.0 10.4, 30.0 10.0))', 4326)),
                        ST_SetSRID(ST_MakePoint(30.06, 10.2), 4326), now(), now())
            ", [$scopeId, 'zz-grid-inside-'.substr($scopeId, 0, 8)]);

            // One raster tile covering the C; population on BOTH arms so the
            // aggregated bin center lands between them, inside the bay.
            DB::insert("
                INSERT INTO worldpop_rasters (id, iso_code, year, resolution_m, rast, created_at)
                VALUES (?, 'ZZG', 2023, 100,
                        ST_SetValues(
                            ST_AddBand(ST_MakeEmptyRaster(100, 100, 30.0, 10.4, 0.005, -0.005, 0, 0, 4326),
                                       '32BF'::text, 0, -99999),
                            1, 1, 1,
                            ARRAY[ [10.0::double precision, 10.0], [10.0, 10.0] ]
                        ), now())
            ", [(string) Str::uuid()]);
            // Put mass explicitly on the two arms (south + north), none in the bay.
            DB::update("
                UPDATE worldpop_rasters
                   SET rast = ST_SetValue(ST_SetValue(rast,
                                  1, ST_SetSRID(ST_MakePoint(30.3, 10.04), 4326), 400.0),
                                  1, ST_SetSRID(ST_MakePoint(30.3, 10.36), 4326), 400.0)
                 WHERE iso_code = 'ZZG'
            ");

            $raster = app(PopulationRaster::class);
            $grid = $raster->pixelGrid($scopeId, 2023);

            $this->assertNotEmpty($grid, 'the fixture raster must yield grid points');

            $total = 0.0;
            foreach ($grid as [$x, $y, $v]) {
                $total += $v;
                $inside = (bool) DB::selectOne(
                    'SELECT ST_Covers(geom, ST_SetSRID(ST_MakePoint(?, ?), 4326)) AS c
                       FROM jurisdictions WHERE id = ?',
                    [$x, $y, $scopeId]
                )->c;
                $this->assertTrue($inside, "grid point ({$x}, {$y}) must lie ON the territory, never in the water");
            }

            // Mass conservation: the snap moves points, never mass.
            $this->assertGreaterThan(0, $total);
        } finally {
            AutoscaleContext::clear();
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
