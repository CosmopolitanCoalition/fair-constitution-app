<?php

namespace Tests\Constitutional;

use App\Services\Districting\SubdivisionAutoseedService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the COMPONENTS template (run-6 watch fix 2026-07-19).
 *
 * A multipart scope whose every straight cut strands a fragment (the two-part
 * village class: a detached hamlet holding a decisive population share) is
 * districted WITHOUT cutting — its detached parts become the districts, seats
 * nearest-rounded per the drawn-district law (sub-floor rides the autoseed
 * floor posture). The template rides LAST in the registry: the ladder reaches
 * it only when every cutting template has refused, so nothing about the
 * cutting doctrine changes for contiguous scopes.
 *
 * If an edit breaks these, the edit is the constitutional violation — fix the
 * edit, not the test.
 */
class ComponentsTemplateTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_components_pin';

    private const ISO = 'ZZC';

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            $body($this->buildTwinIsles());
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }

    /**
     * Twin Isles: a 2-part MultiPolygon village — West Isle 20×20 raster
     * cells, East Isle 12×20 (detached, 0.2° of open water between) under a
     * uniform synthetic tile, so the population splits exactly 62.5 / 37.5.
     * Stored population 900 → the real run-6 class arithmetic: type_a 10,
     * ceil(10/9) = 2 districts, quota 90 → nearest-rounded seats 6 and 4
     * (the 4 is sub-floor — the autoseed floor posture files it).
     * Solid Isle: the same footprint as West Isle but single-part — the
     * inapplicability pin.
     */
    private function buildTwinIsles(): array
    {
        $now = now();

        $twinId = (string) Str::uuid();
        DB::insert(
            "INSERT INTO jurisdictions
                (id, name, slug, iso_code, adm_level, parent_id, population, is_active, is_civic_active,
                 source, official_languages, timezone, geom, centroid, created_at, updated_at)
             VALUES
                (?, 'Twin Isles', ?, ?, 2, NULL, 900, true, true, 'pin-fixture', '[]', 'UTC',
                 ST_Collect(ARRAY[
                     ST_MakeEnvelope(10.0, 50.0, 10.1, 50.1, 4326),
                     ST_MakeEnvelope(10.3, 50.0, 10.36, 50.1, 4326)
                 ]),
                 ST_Centroid(ST_MakeEnvelope(10.0, 50.0, 10.36, 50.1, 4326)), ?, ?)",
            [$twinId, 'zz-2-twin-isles-'.substr($twinId, 0, 8), self::ISO, $now, $now]
        );

        $solidId = (string) Str::uuid();
        DB::insert(
            "INSERT INTO jurisdictions
                (id, name, slug, iso_code, adm_level, parent_id, population, is_active, is_civic_active,
                 source, official_languages, timezone, geom, centroid, created_at, updated_at)
             VALUES
                (?, 'Solid Isle', ?, ?, 2, NULL, 900, true, true, 'pin-fixture', '[]', 'UTC',
                 ST_Multi(ST_MakeEnvelope(10.0, 50.0, 10.1, 50.1, 4326)),
                 ST_Centroid(ST_MakeEnvelope(10.0, 50.0, 10.1, 50.1, 4326)), ?, ?)",
            [$solidId, 'zz-2-solid-isle-'.substr($solidId, 0, 8), self::ISO, $now, $now]
        );

        // One uniform tile over both isles and the gap: 80×20 cells of
        // 0.005°, value 900/640 — the 640 in-scope cells sum to exactly 900.
        DB::insert(
            "INSERT INTO worldpop_rasters (id, iso_code, year, resolution_m, rast, created_at)
             VALUES (?, ?, 2023, 100,
                     ST_AddBand(ST_MakeEmptyRaster(80, 20, 10.0, 50.1, 0.005, -0.005, 0, 0, 4326),
                                '32BF'::text, ?::double precision, NULL),
                     ?)",
            [(string) Str::uuid(), self::ISO, 900 / 640, $now]
        );

        // Lopsided Isles — the Chiboo Gaon class (mainland-by-population
        // pin): the SMALLER-AREA east isle holds 96% of the people (its own
        // hot tile), the big west isle is nearly empty. The blade mainland
        // must be chosen by population — an area-chosen mainland has no
        // balanced cut and the whole ladder used to refuse.
        $lopsidedId = (string) Str::uuid();
        DB::insert(
            "INSERT INTO jurisdictions
                (id, name, slug, iso_code, adm_level, parent_id, population, is_active, is_civic_active,
                 source, official_languages, timezone, geom, centroid, created_at, updated_at)
             VALUES
                (?, 'Lopsided Isles', ?, ?, 2, NULL, 1000, true, true, 'pin-fixture', '[]', 'UTC',
                 ST_Collect(ARRAY[
                     ST_MakeEnvelope(20.0, 50.0, 20.1, 50.1, 4326),
                     ST_MakeEnvelope(20.3, 50.0, 20.36, 50.1, 4326)
                 ]),
                 ST_Centroid(ST_MakeEnvelope(20.0, 50.0, 20.36, 50.1, 4326)), ?, ?)",
            [$lopsidedId, 'zz-2-lopsided-isles-'.substr($lopsidedId, 0, 8), self::ISO, $now, $now]
        );
        // West tile: 20×20 cells × 0.1 = 40 people. East tile: 12×20 × 4.0
        // = 960. Stored 1000 → coverage exact.
        DB::insert(
            "INSERT INTO worldpop_rasters (id, iso_code, year, resolution_m, rast, created_at)
             VALUES (?, ?, 2023, 100,
                     ST_AddBand(ST_MakeEmptyRaster(20, 20, 20.0, 50.1, 0.005, -0.005, 0, 0, 4326),
                                '32BF'::text, ?::double precision, NULL),
                     ?)",
            [(string) Str::uuid(), self::ISO, 0.1, $now]
        );
        DB::insert(
            "INSERT INTO worldpop_rasters (id, iso_code, year, resolution_m, rast, created_at)
             VALUES (?, ?, 2023, 100,
                     ST_AddBand(ST_MakeEmptyRaster(12, 20, 20.3, 50.1, 0.005, -0.005, 0, 0, 4326),
                                '32BF'::text, ?::double precision, NULL),
                     ?)",
            [(string) Str::uuid(), self::ISO, 4.0, $now]
        );

        return ['twin_id' => $twinId, 'solid_id' => $solidId, 'lopsided_id' => $lopsidedId];
    }

    private function ctx(int $budget): array
    {
        return ['floor' => 5, 'ceiling' => 9, 'budget' => $budget, 'quota' => 900 / max($budget, 1)];
    }

    public function test_detached_parts_become_the_districts_with_nearest_rounded_seats(): void
    {
        $this->onLivePg(function (array $ctx) {
            $plan = app(SubdivisionAutoseedService::class)
                ->plan($ctx['twin_id'], $this->ctx(10), 2023, SubdivisionAutoseedService::TEMPLATE_COMPONENTS);

            $this->assertSame(SubdivisionAutoseedService::TEMPLATE_COMPONENTS, $plan['template']);
            $this->assertSame([], $plan['cuts'], 'the components template never cuts');
            $this->assertCount(2, $plan['districts'], 'two detached parts, ceil(10/9) = 2 districts — one each');

            // Nearest-rounded seats under the drawn-district law: 62.5% of
            // 10 → 6, 37.5% → 4 (sub-floor, the floor posture's case). No
            // total-forcing — 6 + 4 = 10 happens to be exact here.
            $this->assertSame([6, 4], $plan['sizes']);
            $this->assertSame(10, array_sum(array_column($plan['districts'], 'seats')));
            $this->assertEqualsWithDelta(562.5, $plan['districts'][0]['pop'], 1.0, 'the west isle holds 400/640 of the mass');
            $this->assertEqualsWithDelta(337.5, $plan['districts'][1]['pop'], 1.0, 'the east isle holds 240/640');

            // Each district is ONE whole part — no fragment of the other isle
            // rides along (the west district lies strictly west of the gap).
            foreach ([0, 1] as $i) {
                $row = DB::selectOne(
                    'SELECT ST_XMin(g) AS xmin, ST_XMax(g) AS xmax
                       FROM ST_GeomFromGeoJSON(?) AS g',
                    [$plan['districts'][$i]['geometry_json']]
                );
                if ($i === 0) {
                    $this->assertLessThan(10.2, (float) $row->xmax, 'district c0 = the west isle only');
                } else {
                    $this->assertGreaterThan(10.2, (float) $row->xmin, 'district c1 = the east isle only');
                }
            }

            // Determinism receipt: the same scope + year reproduces the hash.
            $again = app(SubdivisionAutoseedService::class)
                ->plan($ctx['twin_id'], $this->ctx(10), 2023, SubdivisionAutoseedService::TEMPLATE_COMPONENTS);
            $this->assertSame($plan['plan_hash'], $again['plan_hash']);
        });
    }

    public function test_blade_mainland_is_chosen_by_population_not_area(): void
    {
        $this->onLivePg(function (array $ctx) {
            // The Chiboo Gaon class: 96% of the people on the SMALLER-area
            // part. The splitline must cut the populous isle and let the big
            // empty isle ride as an island — an area-chosen mainland has no
            // balanced cut and this plan() used to refuse outright.
            $plan = app(SubdivisionAutoseedService::class)
                ->plan(
                    $ctx['lopsided_id'],
                    ['floor' => 5, 'ceiling' => 9, 'budget' => 10, 'quota' => 100.0],
                    2023,
                    SubdivisionAutoseedService::TEMPLATE_SHORTEST
                );

            $this->assertSame(SubdivisionAutoseedService::TEMPLATE_SHORTEST, $plan['template']);
            $this->assertCount(2, $plan['districts'], 'one straight cut through the populous isle → two districts');
            $this->assertSame([5, 5], array_values(array_map(
                fn (array $d) => $d['seats'],
                $plan['districts']
            )), 'budget 10 bisects 5:5');
            foreach ($plan['districts'] as $d) {
                $this->assertEqualsWithDelta(500, $d['pop'], 30,
                    'both sides balance within the per-seat deviation guard — the empty isle rides, never blocks');
            }
        });
    }

    public function test_single_landmass_and_too_few_parts_refuse_plainly(): void
    {
        $this->onLivePg(function (array $ctx) {
            try {
                app(SubdivisionAutoseedService::class)
                    ->plan($ctx['solid_id'], $this->ctx(10), 2023, SubdivisionAutoseedService::TEMPLATE_COMPONENTS);
                $this->fail('a single-part scope must refuse the components template');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('single landmass', $e->getMessage());
            }

            // Budget 19 needs ceil(19/9) = 3 districts — two parts cannot
            // fill them without a cut; the cutting templates keep the case.
            try {
                app(SubdivisionAutoseedService::class)
                    ->plan($ctx['twin_id'], $this->ctx(19), 2023, SubdivisionAutoseedService::TEMPLATE_COMPONENTS);
                $this->fail('fewer parts than districts must refuse — a cut is required');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('cannot fill', $e->getMessage());
            }
        });
    }
}
