<?php

namespace Tests\Constitutional;

use App\Services\Districting\SubdivisionAutoseedService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the full-crossing blade (2026-07-22, the arctic
 * scrap-cut class).
 *
 * The blade's fixed 2° over-extension assumed sub-degree scopes. An arctic
 * mega spans 10°+ (Avannaata, Cochrane Unorganized, Northern Rockies): the
 * blade SEGMENT ended inside the region, ST_Split clipped off whatever
 * corner it happened to cross, and the plan filed a scrap piece against the
 * rest of the scope — "balanced" by the infinite half-plane pixel split,
 * all-or-nothing by geometry, band-refused at filing (18.00-quota pieces,
 * 13 items). The extension now out-spans the populated bbox diagonal, so
 * every candidate blade fully crosses; the 2° floor keeps every sub-degree
 * scope's plan byte-identical.
 *
 * Fixture: a 12°-wide rectangle with population in two equal lumps near its
 * east and west ends. findBlade must return two sides that EACH cover their
 * lump — under the old fixed extension every vertical candidate cut only a
 * scrap and the run of candidates degenerated exactly like the arctic class.
 *
 * If an edit breaks these, the edit is the constitutional violation — fix
 * the edit, not the test.
 */
class FullCrossingBladeTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_full_crossing';

    public function test_a_twelve_degree_scope_is_cut_by_a_fully_crossing_blade(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        try {
            // 12° x 2° rectangle at mid-latitude; two 500-person lumps.
            $regionGj = '{"type":"Polygon","coordinates":[[[10,40],[22,40],[22,42],[10,42],[10,40]]]}';
            $pixels = [];
            foreach ([11.0, 21.0] as $lumpX) {
                foreach (range(0, 4) as $i) {
                    $pixels[] = [$lumpX + $i * 0.01, 41.0, 100.0];
                }
            }

            $svc = app(SubdivisionAutoseedService::class);
            $m = new \ReflectionMethod($svc, 'findBlade');
            $m->setAccessible(true);
            $cut = $m->invoke($svc, $regionGj, $pixels, [], 1, 1, 500.0, 'shortest');

            $this->assertEqualsWithDelta(500.0, $cut['pop_a'], 25.0, 'side a balances one lump');
            $this->assertEqualsWithDelta(500.0, $cut['pop_b'], 25.0, 'side b balances the other lump');

            // THE pin: each lump is geometrically held by ONE side and the
            // sides differ — a scrap cut leaves one side with no people at
            // all (the arctic signature). Side labels (a = t < c) depend on
            // blade orientation, so the pin is side-agnostic.
            $row = DB::selectOne(
                'SELECT ST_Covers(ST_MakeValid(ST_GeomFromGeoJSON(:ga)), ST_SetSRID(ST_MakePoint(11.02, 41.0), 4326)) AS a_west,
                        ST_Covers(ST_MakeValid(ST_GeomFromGeoJSON(:ga2)), ST_SetSRID(ST_MakePoint(21.02, 41.0), 4326)) AS a_east,
                        ST_Covers(ST_MakeValid(ST_GeomFromGeoJSON(:gb)), ST_SetSRID(ST_MakePoint(11.02, 41.0), 4326)) AS b_west,
                        ST_Covers(ST_MakeValid(ST_GeomFromGeoJSON(:gb2)), ST_SetSRID(ST_MakePoint(21.02, 41.0), 4326)) AS b_east,
                        ST_Area(ST_GeomFromGeoJSON(:ga3)) AS area_a,
                        ST_Area(ST_GeomFromGeoJSON(:gb3)) AS area_b',
                ['ga' => $cut['gj_a'], 'ga2' => $cut['gj_a'], 'gb' => $cut['gj_b'], 'gb2' => $cut['gj_b'],
                 'ga3' => $cut['gj_a'], 'gb3' => $cut['gj_b']]
            );

            $aWest = (bool) $row->a_west; $aEast = (bool) $row->a_east;
            $bWest = (bool) $row->b_west; $bEast = (bool) $row->b_east;
            $this->assertTrue(
                ($aWest && $bEast && ! $aEast && ! $bWest)
                || ($aEast && $bWest && ! $aWest && ! $bEast),
                "each side must hold exactly one lump (a: west={$row->a_west} east={$row->a_east}, b: west={$row->b_west} east={$row->b_east})"
            );

            // Neither side is a scrap: the cut partitions 24 deg² roughly in half.
            $this->assertGreaterThan(4.0, (float) $row->area_a, 'side a is a real half, not a scrap');
            $this->assertGreaterThan(4.0, (float) $row->area_b, 'side b is a real half, not a scrap');
        } finally {
            DB::setDefaultConnection($original);
        }
    }
}
