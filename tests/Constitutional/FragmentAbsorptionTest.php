<?php

namespace Tests\Constitutional;

use App\Services\Districting\PopulationRaster;
use App\Services\Districting\SubdivisionAutoseedService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — fragment absorption + the concentration basis
 * (operator sanction 2026-07-21, the concave-residue endgame).
 *
 * When every strict blade candidate strands a fragment (a concave scope where
 * "each side one polygon" can never hold), splitRegionAbsorb accepts a
 * >2-piece cut and regroups the stranded pieces onto the two sides,
 * contiguity-checked piece by piece. This pins THE MECHANISM deterministically
 * on synthetic geometry: the seeding (largest piece per geometric side), the
 * opposite-side-first attach order, the single-polygon invariant on both
 * returned sides, and the refusal when a fragment attaches to neither side.
 * The population recount + per-seat guard live in findBlade and are exercised
 * end-to-end by the live cohort; the geometric contract is what a refactor
 * could silently break, so it is what this file locks.
 *
 * Also pins PopulationRaster::concentrated() — the atomic-pixel trigger that
 * moves a one-town scope (Bilma, McKinlay) onto the sub-pixel basis.
 *
 * If an edit breaks these, the edit is the constitutional violation — fix the
 * edit, not the test.
 */
class FragmentAbsorptionTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_frag_absorb';

    /** The geometry pins need PostGIS — read-only ST_ calls on the live pg. */
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

    /** Invoke the private splitRegionAbsorb via reflection. */
    private function absorb(string $regionGj, array $cand): ?array
    {
        $svc = app(SubdivisionAutoseedService::class);
        $m = new \ReflectionMethod($svc, 'splitRegionAbsorb');
        $m->setAccessible(true);

        // lon0 = 0, lat0 = 0, cosLat = 1 — the synthetic frame is already local.
        return $m->invoke($svc, $regionGj, $cand, 0.0, 0.0, 1.0);
    }

    /** A U-shape opening upward: arms x∈[0,1] and x∈[2,3] rising to y=3, base y∈[0,1]. */
    private const U_SHAPE = '{"type":"Polygon","coordinates":[[[0,0],[3,0],[3,3],[2,3],[2,1],[1,1],[1,3],[0,3],[0,0]]]}';

    /** A horizontal blade at y = 2: side a is y < 2 (nx=0, ny=1, c=2). */
    private function horizontalBladeAt2(): array
    {
        return [
            'nx'    => 0.0,
            'ny'    => 1.0,
            'c'     => 2.0,
            'blade' => [-5.0, 2.0, 5.0, 2.0],
        ];
    }

    public function test_three_piece_split_absorbs_deterministically(): void
    {
        // The y=2 blade cuts the U into THREE pieces: the connected lower U
        // (side a, area 5) and the two arm tops (side b, 1 each) — the exact
        // shape the strict pass refuses (side b = two parts). Absorption seeds
        // side b with the LARGER-ordered arm top (area tie → smaller POS x:
        // the left arm) and fix-point-attaches the right arm top — opposite
        // side 'a' tried FIRST, and the lower U touches it along y=2, so it
        // lands there. Deterministic end state: a = lower U + right arm top
        // (area 6, ONE polygon), b = left arm top (area 1).
        $this->onLivePg(function (): void {
            $sides = $this->absorb(self::U_SHAPE, $this->horizontalBladeAt2());

            $this->assertNotNull($sides, 'the three-piece U cut must absorb, not refuse');

            $row = DB::selectOne(
                'SELECT ST_Area(ST_GeomFromGeoJSON(?)) AS area_a,
                        ST_Area(ST_GeomFromGeoJSON(?)) AS area_b,
                        ST_NumGeometries(ST_Multi(ST_GeomFromGeoJSON(?))) AS parts_a,
                        ST_NumGeometries(ST_Multi(ST_GeomFromGeoJSON(?))) AS parts_b,
                        ST_Area(ST_Union(ST_GeomFromGeoJSON(?), ST_GeomFromGeoJSON(?))) AS area_union',
                [$sides['a'], $sides['b'], $sides['a'], $sides['b'], $sides['a'], $sides['b']]
            );

            $this->assertSame(1, (int) $row->parts_a, 'side a must be ONE polygon (Art. II §8)');
            $this->assertSame(1, (int) $row->parts_b, 'side b must be ONE polygon (Art. II §8)');
            $this->assertEqualsWithDelta(6.0, (float) $row->area_a, 1e-9, 'the stranded right arm top joins side a across the blade');
            $this->assertEqualsWithDelta(1.0, (float) $row->area_b, 1e-9, 'side b keeps only its seed arm top');
            $this->assertEqualsWithDelta(7.0, (float) $row->area_union, 1e-9, 'the two sides tile the region exactly');
        });
    }

    public function test_two_piece_split_is_not_absorptions_business(): void
    {
        // A plain rectangle cut once yields TWO pieces — the strict case.
        // Absorption refuses so the strict pass stays the only author of
        // 2-piece plans (byte-identical historical hashes).
        $this->onLivePg(function (): void {
            $rect = '{"type":"Polygon","coordinates":[[[0,0],[3,0],[3,3],[0,3],[0,0]]]}';
            $this->assertNull($this->absorb($rect, $this->horizontalBladeAt2()));
        });
    }

    public function test_an_unattachable_fragment_refuses_the_candidate(): void
    {
        // The U plus a DETACHED square far to the east: the square never
        // touches either side, the fix-point sweep stalls, the candidate
        // refuses (null) — absorption may regroup, never teleport territory.
        $this->onLivePg(function (): void {
            $withDetached = '{"type":"MultiPolygon","coordinates":['
                .'[[[0,0],[3,0],[3,3],[2,3],[2,1],[1,1],[1,3],[0,3],[0,0]]],'
                .'[[[10,0],[11,0],[11,1],[10,1],[10,0]]]]}';
            $this->assertNull($this->absorb($withDetached, $this->horizontalBladeAt2()));
        });
    }

    public function test_concentration_trigger_pins(): void
    {
        // Bilma class: ample pixels, one cell dominating → sub-pixel basis.
        $dominated = [[0.0, 0.0, 970.0]];
        for ($i = 1; $i < 500; $i++) {
            $dominated[] = [$i * 0.001, 0.0, 0.06];        // ~30 people scattered
        }
        $this->assertTrue(PopulationRaster::concentrated($dominated));

        // A flat grid of the same size never triggers.
        $flat = [];
        for ($i = 0; $i < 500; $i++) {
            $flat[] = [$i * 0.001, 0.0, 2.0];
        }
        $this->assertFalse(PopulationRaster::concentrated($flat));

        // Above the expansion bound the trigger stands down (16× would leave
        // interactive scale) — the raster basis keeps such scopes.
        $big = [];
        for ($i = 0; $i < 4000; $i++) {
            $big[] = [$i * 0.001, 0.0, $i === 0 ? 1000.0 : 0.1];
        }
        $this->assertFalse(PopulationRaster::concentrated($big));

        // Empty and zero-mass grids are never "concentrated".
        $this->assertFalse(PopulationRaster::concentrated([]));
        $this->assertFalse(PopulationRaster::concentrated([[0.0, 0.0, 0.0]]));
    }
}
