<?php

namespace Tests\Constitutional;

use App\Http\Controllers\Legislature\SubdivisionDrawController;
use App\Services\ConstitutionalDefaults;
use App\Services\Districting\SubdivisionAutoseedService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase 5 (5a), the shortest-splitline AUTOSEED for a
 * childless leaf giant. Pure pins (no DB) nail the deterministic seat
 * arithmetic (grouping, multiset bisection, the exact prefix-sum blade
 * placement); the live pins run the preview/commit/balance endpoints against
 * the Serravalle fixture (childless castello of San Marino, 10-seat budget)
 * and SKIP when it is absent. Pins:
 *  1. seat grouping: 10→[5,5], 13→[6,7], 21→[7,7,7], 32→[8,8,8,8]; every
 *     group in the 5–9 band (Art. II §2), summing exactly to S;
 *  2. determinism — two preview runs produce the identical plan_hash (the
 *     audit chain and FF&C demand reproducibility on every mesh node);
 *  3. every final district holds 5–9 seats at ≤5% per-seat deviation, and is
 *     a single contiguous polygon (Art. II §8);
 *  4. commit files one F-ELB-008 per leaf district (audited, atomic), and a
 *     stale plan_hash is refused — preview-first, never auto-commit;
 *  5. split-balance keeps the hand-placed angle and lands both sides in band.
 *
 * If an edit breaks these, the edit is the constitutional violation — fix the
 * edit, not the test.
 */
class SubdivisionAutoseedTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_autoseed';

    // ── pure pins (no DB) ───────────────────────────────────────────────────

    public function test_seat_grouping_pins(): void
    {
        $this->assertSame([5, 5], SubdivisionAutoseedService::seatGroups(10));
        $this->assertSame([6, 7], SubdivisionAutoseedService::seatGroups(13));
        $this->assertSame([7, 7, 7], SubdivisionAutoseedService::seatGroups(21));
        $this->assertSame([8, 8, 8, 8], SubdivisionAutoseedService::seatGroups(32));
        $this->assertSame([9], SubdivisionAutoseedService::seatGroups(9));
        $this->assertSame([5, 6], SubdivisionAutoseedService::seatGroups(11));

        // Every group in band and summing exactly to S across the whole range.
        for ($s = 5; $s <= 120; $s++) {
            $sizes = SubdivisionAutoseedService::seatGroups($s);
            $this->assertSame($s, array_sum($sizes), "grouping of {$s} must sum to {$s}");
            $this->assertGreaterThanOrEqual(5, min($sizes), "grouping of {$s} dips below the floor");
            $this->assertLessThanOrEqual(9, max($sizes), "grouping of {$s} exceeds the ceiling");
        }
    }

    public function test_seat_grouping_rejects_an_unsplittable_band(): void
    {
        $this->expectException(\RuntimeException::class);
        SubdivisionAutoseedService::seatGroups(10, 6, 9);   // 2 groups of 5 — below a floor of 6
    }

    public function test_multiset_bisection_pins(): void
    {
        $this->assertSame([[5], [5]], SubdivisionAutoseedService::bisectSizes([5, 5]));
        // Equal |diff| both ways → lexicographically greatest sorted-desc A wins.
        $this->assertSame([[7], [6]], SubdivisionAutoseedService::bisectSizes([6, 7]));
        // Equal |diff| → fewer elements in A wins.
        $this->assertSame([[7], [7, 7]], SubdivisionAutoseedService::bisectSizes([7, 7, 7]));
        $this->assertSame([[8, 8], [8, 8]], SubdivisionAutoseedService::bisectSizes([8, 8, 8, 8]));
        $this->assertSame([[6], [5, 5]], SubdivisionAutoseedService::bisectSizes([5, 5, 6]));
    }

    public function test_blade_offset_search_pins_on_a_synthetic_grid(): void
    {
        // A 10×10 unit-population grid at the equator (cosLat = 1). Horizontal
        // blade (θ=0 → normal (0,1)): the projection is simply lat − 4.5.
        $grid = [];
        for ($x = 0; $x < 10; $x++) {
            for ($y = 0; $y < 10; $y++) {
                $grid[] = [(float) $x, (float) $y, 1.0];
            }
        }

        // Target 30 of 100 → the blade sits midway between rows y=2 and y=3.
        $res = SubdivisionAutoseedService::bladeOffsetSearch($grid, 0.0, 1.0, 4.5, 4.5, 1.0, 30.0);
        $this->assertNotNull($res);
        [$c, $popA, $popB] = $res;
        $this->assertEqualsWithDelta(-2.0, $c, 1e-9);
        $this->assertEqualsWithDelta(30.0, $popA, 1e-9);
        $this->assertEqualsWithDelta(70.0, $popB, 1e-9);

        // Exact midline for a 50:50 target.
        [$c2, $popA2] = SubdivisionAutoseedService::bladeOffsetSearch($grid, 0.0, 1.0, 4.5, 4.5, 1.0, 50.0);
        $this->assertEqualsWithDelta(0.0, $c2, 1e-9);
        $this->assertEqualsWithDelta(50.0, $popA2, 1e-9);

        // An unreachable target (all pixels on one side) is refused, not faked.
        $this->assertNull(SubdivisionAutoseedService::bladeOffsetSearch($grid, 0.0, 1.0, 4.5, 4.5, 1.0, 100.0));
    }

    public function test_the_autoseed_routes_are_registered(): void
    {
        $this->assertTrue(Route::has('legislatures.autoseed-lines.preview'));
        $this->assertTrue(Route::has('legislatures.autoseed-lines.commit'));
        $this->assertTrue(Route::has('legislatures.split-balance'));
    }

    // ── live pins (Serravalle fixture; skip when absent) ────────────────────

    public function test_preview_is_deterministic_and_every_district_is_in_band(): void
    {
        $this->onLivePg(function (array $ctx) {
            $controller = app(SubdivisionDrawController::class);
            $preview = fn () => $controller->autoseedPreview(
                Request::create('/a', 'POST', ['scope_id' => $ctx['giant_id']]),
                $ctx['legislature_id']
            );

            $r1 = $preview();
            $this->assertSame(200, $r1->getStatusCode(), $r1->getContent());
            $r2 = $preview();
            $p1 = json_decode($r1->getContent(), true);
            $p2 = json_decode($r2->getContent(), true);
            $this->assertSame($p1['plan_hash'], $p2['plan_hash'], 'two preview runs must produce the identical plan');

            // Serravalle: a 10-seat budget → [5,5], one cut, two districts.
            $this->assertSame([5, 5], $p1['sizes']);
            $this->assertCount(1, $p1['cuts']);
            $this->assertSame('root', $p1['cuts'][0]['parent_path']);
            $this->assertCount(2, $p1['districts']);
            $this->assertCount(2, $p1['cuts'][0]['line']['coordinates'], 'a cut line is a 2-point segment');

            $floor   = ConstitutionalDefaults::floor($ctx['leg_jurisdiction_id']);
            $ceiling = ConstitutionalDefaults::ceiling($ctx['leg_jurisdiction_id']);
            foreach ($p1['districts'] as $d) {
                $this->assertGreaterThanOrEqual($floor, $d['seats']);
                $this->assertLessThanOrEqual($ceiling, $d['seats']);
                $this->assertLessThanOrEqual(5.0, $d['per_seat_deviation_pct'],
                    "district {$d['path']} deviates past the good band");
                $parts = DB::selectOne(
                    'SELECT ST_NumGeometries(ST_Multi(ST_MakeValid(ST_GeomFromGeoJSON(?)))) AS parts',
                    [json_encode($d['geometry'])]
                );
                $this->assertSame(1, (int) $parts->parts, "district {$d['path']} is not a single polygon");
            }

            // A non-giant scope is refused with the giantContext message.
            $bad = $controller->autoseedPreview(
                Request::create('/a', 'POST', ['scope_id' => $ctx['leg_jurisdiction_id']]),
                $ctx['legislature_id']
            );
            $this->assertSame(422, $bad->getStatusCode());
        });
    }

    public function test_commit_files_one_form_per_district_and_a_stale_hash_is_refused(): void
    {
        $this->onLivePg(function (array $ctx) {
            $controller = app(SubdivisionDrawController::class);
            $plan = json_decode($controller->autoseedPreview(
                Request::create('/a', 'POST', ['scope_id' => $ctx['giant_id']]),
                $ctx['legislature_id']
            )->getContent(), true);

            $subdivisions = fn () => (int) DB::table('district_subdivisions')
                ->where('parent_jurisdiction_id', $ctx['giant_id'])
                ->where('map_id', $ctx['map_id'])
                ->where('method', 'manual')
                ->whereNull('deleted_at')->count();

            // A stale plan_hash is refused before anything persists.
            $stale = $controller->autoseedCommit(Request::create('/c', 'POST', [
                'scope_id'  => $ctx['giant_id'],
                'map_id'    => $ctx['map_id'],
                'plan_hash' => str_repeat('0', 64),
            ]), $ctx['legislature_id']);
            $this->assertSame(422, $stale->getStatusCode());
            $this->assertStringContainsString('Plan changed', json_decode($stale->getContent(), true)['error']);
            $this->assertSame(0, $subdivisions());

            $auditBefore = (int) DB::table('audit_log')
                ->where('ref', 'F-ELB-008')->where('rejected', false)->count();

            $ok = $controller->autoseedCommit(Request::create('/c', 'POST', [
                'scope_id'  => $ctx['giant_id'],
                'map_id'    => $ctx['map_id'],
                'plan_hash' => $plan['plan_hash'],
            ]), $ctx['legislature_id']);
            $this->assertSame(200, $ok->getStatusCode(), $ok->getContent());
            $data = json_decode($ok->getContent(), true);
            $this->assertSame(2, $data['districts_created']);
            $this->assertCount(2, $data['district_ids']);

            $this->assertSame(2, $subdivisions(), 'every leaf of the plan persists as a drawn subdivision');
            $auditAfter = (int) DB::table('audit_log')
                ->where('ref', 'F-ELB-008')->where('rejected', false)->count();
            $this->assertSame(2, $auditAfter - $auditBefore, 'one F-ELB-008 audit edge per district');
        });
    }

    public function test_split_balance_preserves_the_angle_and_lands_both_sides_in_band(): void
    {
        $this->onLivePg(function (array $ctx) {
            $controller = app(SubdivisionDrawController::class);

            // A rough vertical line at 42% across — deliberately unbalanced.
            $x = $ctx['xmin'] + 0.42 * ($ctx['xmax'] - $ctx['xmin']);
            $line = [
                'type'        => 'LineString',
                'coordinates' => [[$x, $ctx['ymin'] - 0.001], [$x, $ctx['ymax'] + 0.001]],
            ];

            $resp = $controller->splitBalance(Request::create('/b', 'POST', [
                'scope_id' => $ctx['giant_id'],
                'line'     => $line,
            ]), $ctx['legislature_id']);
            $this->assertSame(200, $resp->getStatusCode(), $resp->getContent());
            $data = json_decode($resp->getContent(), true);

            $this->assertSame([5, 5], $data['seat_split']);
            $this->assertTrue($data['both_in_band'], json_encode($data['sides']));
            $this->assertSame(5, $data['sides'][0]['implied_seats']);
            $this->assertSame(5, $data['sides'][1]['implied_seats']);

            // Angle preserved: the balanced line is still vertical.
            [$p1, $p2] = $data['line']['coordinates'];
            $this->assertEqualsWithDelta(0.0, $p2[0] - $p1[0], 1e-6,
                'the balanced line must keep the hand-placed angle');
        });
    }

    // -------------------------------------------------------------------------

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $ctx = $this->buildContext();
            if ($ctx === null) {
                $this->markTestSkipped('Serravalle / San Marino legislature fixture not present.');
            }
            $body($ctx);
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }

    /** Resolve the live fixture (never hard-code UUIDs) + a draft plan to commit into. */
    private function buildContext(): ?array
    {
        $giant = DB::selectOne(
            "SELECT id, parent_id,
                    ST_XMin(geom) AS xmin, ST_XMax(geom) AS xmax,
                    ST_YMin(geom) AS ymin, ST_YMax(geom) AS ymax
               FROM jurisdictions
              WHERE name = 'Serravalle' AND iso_code = 'SMR' AND deleted_at IS NULL
              LIMIT 1"
        );
        if ($giant === null) {
            return null;
        }

        $leg = DB::selectOne(
            'SELECT id, jurisdiction_id FROM legislatures WHERE jurisdiction_id = ? AND deleted_at IS NULL LIMIT 1',
            [$giant->parent_id]
        );
        if ($leg === null) {
            return null;
        }

        $mapId = (string) Str::uuid();
        DB::table('legislature_district_maps')->insert([
            'id'             => $mapId,
            'legislature_id' => $leg->id,
            'name'           => '5a autoseed pin',
            'status'         => 'draft',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return [
            'legislature_id'      => $leg->id,
            'leg_jurisdiction_id' => $leg->jurisdiction_id,
            'giant_id'            => $giant->id,
            'map_id'              => $mapId,
            'xmin'                => (float) $giant->xmin,
            'xmax'                => (float) $giant->xmax,
            'ymin'                => (float) $giant->ymin,
            'ymax'                => (float) $giant->ymax,
        ];
    }
}
