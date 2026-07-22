<?php

namespace Tests\Constitutional;

use App\Services\Districting\PopulationRaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — measurement mass conservation (operator ruling
 * 2026-07-22: "there should be no drift in seat counts").
 *
 * Filed pieces are shaved 1e-8° inward while coastal grid points sit
 * SNAPPED ON the scope boundary — strictly outside every shaved piece. The
 * pure ray-cast dropped that mass from every sibling, every piece rounded
 * DOWN, and filed maps silently seated fewer seats than their budget
 * (Falkland: 14 of 15; planet-wide: 31k done items at net −33k seats). The
 * edge-recovery arm counts a leftover point for the piece whose edge lies
 * within 1e-6° — so sibling measurements CONSERVE the scope's mass and
 * filing seats equal plan seats.
 *
 * Fixture: a right triangle (the hypotenuse cuts raster bins → their
 * centers snap onto it) split by a vertical line and per-part shaved,
 * exactly the filing shape. Pins: (1) the two siblings' measured pops sum
 * to the stored population (nothing vanishes); (2) each sibling holds a
 * sensible share (no piece measures the whole scope).
 *
 * If an edit breaks these, the edit is the constitutional violation — fix
 * the edit, not the test.
 */
class ConservationMeasurementTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_conservation';

    private const ISO = 'ZZV';

    public function test_shaved_sibling_pieces_conserve_the_scope_mass(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $now = now();
            $scopeId = (string) Str::uuid();
            // Right triangle: legs on x=10.0 and y=50.0, hypotenuse from
            // (10.4, 50.0) to (10.0, 50.2) — bins straddling it have
            // outside centers that the grid build snaps onto the boundary.
            DB::insert(
                "INSERT INTO jurisdictions
                    (id, name, slug, iso_code, adm_level, parent_id, population, is_active, is_civic_active,
                     source, official_languages, timezone, geom, centroid, created_at, updated_at)
                 VALUES
                    (?, 'Pin Triangle', ?, ?, 2, NULL, 9000, true, true, 'pin-fixture', '[]', 'UTC',
                     ST_Multi(ST_GeomFromText('POLYGON((10.0 50.0, 10.4 50.0, 10.0 50.2, 10.0 50.0))', 4326)),
                     ST_Centroid(ST_GeomFromText('POLYGON((10.0 50.0, 10.4 50.0, 10.0 50.2, 10.0 50.0))', 4326)), ?, ?)",
                [$scopeId, 'zz-2-pin-triangle-'.substr($scopeId, 0, 8), self::ISO, $now, $now]
            );
            DB::insert(
                "INSERT INTO worldpop_rasters (id, iso_code, year, resolution_m, rast, created_at)
                 VALUES (?, ?, 2023, 100,
                         ST_AddBand(ST_MakeEmptyRaster(80, 40, 10.0, 50.2, 0.005, -0.005, 0, 0, 4326),
                                    '32BF'::text, 10.0, NULL),
                         ?)",
                [(string) Str::uuid(), self::ISO, $now]
            );

            // Split at x=10.15 and shave each dumped part 1e-8° inward —
            // byte-for-byte the leaf filing shape.
            $pieces = DB::select(
                "WITH t AS (SELECT ST_MakeValid(geom) AS g FROM jurisdictions WHERE id = ?),
                      s AS (SELECT (ST_Dump(ST_Split((SELECT g FROM t),
                                ST_SetSRID(ST_MakeLine(ST_MakePoint(10.15, 49.9), ST_MakePoint(10.15, 50.3)), 4326)))).geom AS g),
                      shaved AS (
                          SELECT ST_CollectionExtract(ST_MakeValid(ST_Buffer(g, -0.00000001)), 3) AS g FROM s
                      )
                 SELECT ST_AsGeoJSON(g, 15) AS gj FROM shaved WHERE NOT ST_IsEmpty(g)
                  ORDER BY ST_XMin(g)",
                [$scopeId]
            );
            $this->assertCount(2, $pieces, 'the vertical line must split the triangle into two shaved pieces');

            $raster = app(PopulationRaster::class);
            $a = $raster->measureWithFallback($scopeId, (string) $pieces[0]->gj, 2023);
            $b = $raster->measureWithFallback($scopeId, (string) $pieces[1]->gj, 2023);

            $sum = $a['pop'] + $b['pop'];
            $this->assertEqualsWithDelta(9000, $sum, 2,
                'sibling pieces must conserve the stored mass — boundary-snapped points may not vanish '
                ."(got {$a['pop']} + {$b['pop']} = {$sum} of 9000)");

            // Sanity: the west piece (x < 10.15, the fat end) holds the
            // clear majority but never the whole scope.
            $this->assertGreaterThan(4500, $a['pop']);
            $this->assertLessThan(9000, $a['pop']);
            $this->assertGreaterThan(0, $b['pop']);
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
