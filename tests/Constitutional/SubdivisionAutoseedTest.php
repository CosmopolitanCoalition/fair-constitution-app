<?php

namespace Tests\Constitutional;

use App\Http\Controllers\Legislature\SubdivisionDrawController;
use App\Models\User;
use App\Services\ConstitutionalDefaults;
use App\Services\Districting\SubdivisionAutoseedService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\Concerns\SeatsBoardUser;
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
 *  4. commit files one F-ELB-008 per leaf district (audited, atomic, by a
 *     SEATED board member — the actor posture the endpoints now require), and
 *     a stale plan_hash is refused — preview-first, never auto-commit;
 *  5. split-balance keeps the hand-placed angle and lands both sides in band;
 *  6. TEMPLATES (Phase 5 wave): the strip templates cut at their one fixed
 *     angle and stay in band; the template is part of the hashed plan
 *     identity (a template swap between preview and commit fails closed);
 *     community_cells emits a deterministic in-band plan on the same contract;
 *  7. a GUEST cannot reach any mutating draw endpoint (auth-gated) — the
 *     null-actor system path is never rideable from outside.
 *
 * If an edit breaks these, the edit is the constitutional violation — fix the
 * edit, not the test.
 */
class SubdivisionAutoseedTest extends TestCase
{
    use LivePgConnection;
    use SeatsBoardUser;

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

            // Commits file as a SEATED board member (the endpoints are
            // auth-gated now; the guest/null-actor path is pinned closed below).
            $user = $this->seatedBoardUser($ctx['leg_jurisdiction_id']);

            // A stale plan_hash is refused before anything persists.
            $stale = $controller->autoseedCommit($this->authedRequest('/c', [
                'scope_id'  => $ctx['giant_id'],
                'map_id'    => $ctx['map_id'],
                'plan_hash' => str_repeat('0', 64),
            ], $user), $ctx['legislature_id']);
            $this->assertSame(422, $stale->getStatusCode());
            $this->assertStringContainsString('Plan changed', json_decode($stale->getContent(), true)['error']);
            $this->assertSame(0, $subdivisions());

            $auditBefore = (int) DB::table('audit_log')
                ->where('ref', 'F-ELB-008')->where('rejected', false)->count();

            $ok = $controller->autoseedCommit($this->authedRequest('/c', [
                'scope_id'  => $ctx['giant_id'],
                'map_id'    => $ctx['map_id'],
                'plan_hash' => $plan['plan_hash'],
            ], $user), $ctx['legislature_id']);
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

    public function test_commit_after_clearing_the_drawn_set_never_ghost_collides(): void
    {
        $this->onLivePg(function (array $ctx) {
            $controller = app(SubdivisionDrawController::class);
            $user = $this->seatedBoardUser($ctx['leg_jurisdiction_id']);
            $commit = function () use ($controller, $ctx, $user) {
                $plan = json_decode($controller->autoseedPreview(
                    Request::create('/a', 'POST', ['scope_id' => $ctx['giant_id'], 'map_id' => $ctx['map_id']]),
                    $ctx['legislature_id']
                )->getContent(), true);

                return $controller->autoseedCommit($this->authedRequest('/c', [
                    'scope_id'  => $ctx['giant_id'],
                    'map_id'    => $ctx['map_id'],
                    'plan_hash' => $plan['plan_hash'],
                ], $user), $ctx['legislature_id']);
            };

            $first = $commit();
            $this->assertSame(200, $first->getStatusCode(), $first->getContent());

            // Clear the set through the SAME delete endpoint the sidebar uses —
            // subdivisions retire with their districts (soft-delete, labels kept).
            $leg = app(\App\Http\Controllers\LegislatureController::class);
            foreach (json_decode($first->getContent(), true)['district_ids'] as $id) {
                $this->assertSame(200, $leg->deleteDistrict($ctx['legislature_id'], $id)->getStatusCode());
            }
            $live = fn () => (int) DB::table('district_subdivisions')
                ->where('parent_jurisdiction_id', $ctx['giant_id'])
                ->where('map_id', $ctx['map_id'])->whereNull('deleted_at')->count();
            $this->assertSame(0, $live(), 'the delete endpoint retires the subdivisions too');

            // The operator's 500: recommitting the (deterministic, identical)
            // plan collided with the soft-deleted ghosts' labels — 23505.
            // Now: 200, fresh rows, labels never reused.
            $second = $commit();
            $this->assertSame(200, $second->getStatusCode(), $second->getContent());
            $this->assertSame(2, json_decode($second->getContent(), true)['districts_created']);
            $this->assertSame(2, $live());

            $labels = DB::table('district_subdivisions')
                ->where('parent_jurisdiction_id', $ctx['giant_id'])
                ->where('map_id', $ctx['map_id'])
                ->pluck('label');
            $this->assertSame($labels->count(), $labels->unique()->count(),
                'every label ever issued on the plan stays distinct — live AND ghost');
        });
    }

    public function test_replace_retires_the_live_set_and_a_plain_commit_refuses_early(): void
    {
        $this->onLivePg(function (array $ctx) {
            $controller = app(SubdivisionDrawController::class);
            $user = $this->seatedBoardUser($ctx['leg_jurisdiction_id']);
            $plan = json_decode($controller->autoseedPreview(
                Request::create('/a', 'POST', ['scope_id' => $ctx['giant_id'], 'map_id' => $ctx['map_id']]),
                $ctx['legislature_id']
            )->getContent(), true);
            $this->assertSame(0, $plan['existing_districts'], 'a fresh plan reports nothing to displace');

            $body = [
                'scope_id'  => $ctx['giant_id'],
                'map_id'    => $ctx['map_id'],
                'plan_hash' => $plan['plan_hash'],
            ];
            $first = $controller->autoseedCommit($this->authedRequest('/c', $body, $user), $ctx['legislature_id']);
            $this->assertSame(200, $first->getStatusCode(), $first->getContent());
            $firstIds = json_decode($first->getContent(), true)['district_ids'];

            // The preview now reports the displacement the accept button offers.
            $again = json_decode($controller->autoseedPreview(
                Request::create('/a', 'POST', ['scope_id' => $ctx['giant_id'], 'map_id' => $ctx['map_id']]),
                $ctx['legislature_id']
            )->getContent(), true);
            $this->assertSame(2, $again['existing_districts']);

            // Without replace: the EARLY whole-plan refusal — the operator must
            // never reach the per-piece Art. II §8 overlap citation from here.
            $plain = $controller->autoseedCommit($this->authedRequest('/c', $body, $user), $ctx['legislature_id']);
            $this->assertSame(422, $plain->getStatusCode());
            $this->assertSame(
                'This scope already holds 2 drawn districts — accept with replace, or clear them first.',
                json_decode($plain->getContent(), true)['error']
            );

            // With replace: old rows retired, new rows filed, counts right.
            $replace = $controller->autoseedCommit($this->authedRequest('/c', $body + ['replace' => true], $user), $ctx['legislature_id']);
            $this->assertSame(200, $replace->getStatusCode(), $replace->getContent());
            $data = json_decode($replace->getContent(), true);
            $this->assertSame(2, $data['districts_created']);
            $this->assertSame(2, $data['districts_replaced']);

            foreach ($firstIds as $id) {
                $this->assertNotNull(DB::table('legislature_districts')->where('id', $id)->value('deleted_at'),
                    'a replaced district is soft-deleted, not destroyed');
            }
            $this->assertSame(2, (int) DB::table('district_subdivisions')
                ->where('parent_jurisdiction_id', $ctx['giant_id'])
                ->where('map_id', $ctx['map_id'])->whereNull('deleted_at')->count());
            $this->assertSame(2, (int) DB::table('district_subdivisions')
                ->where('parent_jurisdiction_id', $ctx['giant_id'])
                ->where('map_id', $ctx['map_id'])->whereNotNull('deleted_at')->count());
        });
    }

    public function test_remainder_probe_returns_the_undrawn_side_and_it_commits_through_the_draw_path(): void
    {
        $this->onLivePg(function (array $ctx) {
            $controller = app(SubdivisionDrawController::class);
            $plan = json_decode($controller->autoseedPreview(
                Request::create('/a', 'POST', ['scope_id' => $ctx['giant_id']]),
                $ctx['legislature_id']
            )->getContent(), true);

            // Draw ONE side of the Serravalle bisect (the plan's first leaf).
            $engine = app(\App\Domain\Engine\ConstitutionalEngine::class);
            $side = $engine->file('F-ELB-008', null, [
                'legislature_id' => $ctx['legislature_id'],
                'scope_id'       => $ctx['giant_id'],
                'map_id'         => $ctx['map_id'],
                'geojson'        => json_encode($plan['districts'][0]['geometry']),
            ])->recorded;

            $probe = fn () => $controller->remainder(Request::create('/rm', 'POST', [
                'scope_id' => $ctx['giant_id'],
                'map_id'   => $ctx['map_id'],
            ]), $ctx['legislature_id']);

            $resp = $probe();
            $this->assertSame(200, $resp->getStatusCode(), $resp->getContent());
            $data = json_decode($resp->getContent(), true);

            // Single polygon, the leftover seats, in band ([5,5] plan → 5 left).
            $parts = DB::selectOne(
                'SELECT ST_NumGeometries(ST_Multi(ST_MakeValid(ST_GeomFromGeoJSON(?)))) AS parts',
                [json_encode($data['geometry'])]
            );
            $this->assertSame(1, (int) $parts->parts, 'the remainder is a single polygon');
            $this->assertSame(10 - $side['seats'], $data['remaining_seats']);
            $this->assertSame(5, $data['implied_seats']);
            $this->assertTrue($data['in_band'], json_encode($data));

            // Fill-remainder is NOT a new filing path: the returned polygon
            // commits through the normal F-ELB-008 draw pipeline.
            $filled = $engine->file('F-ELB-008', null, [
                'legislature_id' => $ctx['legislature_id'],
                'scope_id'       => $ctx['giant_id'],
                'map_id'         => $ctx['map_id'],
                'geojson'        => json_encode($data['geometry']),
            ])->recorded;
            $this->assertSame(5, $filled['seats']);

            // Fully drawn: the probe now refuses plainly (empty or sliver-split
            // residue — either way a 422, never a fake district).
            $this->assertSame(422, $probe()->getStatusCode());
        });
    }

    public function test_committed_subdivisions_reveal_at_the_root_scope(): void
    {
        $this->onLivePg(function (array $ctx) {
            $controller = app(SubdivisionDrawController::class);
            $user = $this->seatedBoardUser($ctx['leg_jurisdiction_id']);
            $plan = json_decode($controller->autoseedPreview(
                Request::create('/a', 'POST', ['scope_id' => $ctx['giant_id']]),
                $ctx['legislature_id']
            )->getContent(), true);
            $ok = $controller->autoseedCommit($this->authedRequest('/c', [
                'scope_id'  => $ctx['giant_id'],
                'map_id'    => $ctx['map_id'],
                'plan_hash' => $plan['plan_hash'],
            ], $user), $ctx['legislature_id']);
            $this->assertSame(200, $ok->getStatusCode(), $ok->getContent());
            $ids = json_decode($ok->getContent(), true)['district_ids'];

            // The ANCESTOR view: at the legislature's ROOT scope (the giant's
            // parent) the drawn districts must paint exactly like composite
            // sub-districts — same feature type, same tooltip props, the drawn
            // geometry attached. (The leaf-scope path was already pinned.)
            $resp = app(\App\Http\Controllers\LegislatureController::class)->revealedGeoJson(
                \Illuminate\Http\Request::create('/r', 'GET', [
                    'scope' => $ctx['leg_jurisdiction_id'],
                    'map'   => $ctx['map_id'],
                    'zoom'  => 10,
                ]),
                $ctx['legislature_id']
            );
            $fc = json_decode($resp->getContent(), true);
            $drawn = collect($fc['features'] ?? [])
                ->filter(fn ($f) => in_array($f['properties']['district_id'] ?? null, $ids, true))
                ->values();

            $this->assertCount(2, $drawn, 'both drawn districts render at the root scope');
            foreach ($drawn as $f) {
                $this->assertSame('sub_district', $f['properties']['type']);
                $this->assertNotNull($f['geometry'] ?? null);
                $this->assertSame('Serravalle', $f['properties']['parent_name']);
                $this->assertNotEmpty($f['properties']['member_name'], 'the tooltip label is the drawn label');
                $this->assertArrayHasKey('seats', $f['properties']);
                $this->assertArrayHasKey('district_population', $f['properties']);
            }

            // The giant's own outline rides along, like a composite giant's.
            $outline = collect($fc['features'] ?? [])->first(fn ($f) => ($f['properties']['type'] ?? null) === 'parent_outline'
                && ($f['properties']['jurisdiction_id'] ?? null) === $ctx['giant_id']);
            $this->assertNotNull($outline, 'the drawn giant gets its parent outline at the root scope');
        });
    }

    public function test_undrawn_leaf_giants_flag_and_the_leaf_scope_sidebar_props(): void
    {
        $this->onLivePg(function (array $ctx) {
            // Build the "all-done candidate" state incomplete_scopes can see:
            // ONE composite district over the 8 non-giant castelli (22 of 32
            // seats) + the Serravalle ceiling clamp. incomplete_scopes reads
            // empty — it is leaf-blind — while the giant still lacks its drawn
            // set. Exactly the state the lazy undrawn_leaf_giants gate serves.
            $castelli = DB::table('jurisdictions')
                ->where('parent_id', $ctx['leg_jurisdiction_id'])
                ->whereNull('deleted_at')
                ->where('id', '!=', $ctx['giant_id'])
                ->get(['id', 'population']);
            $this->assertGreaterThan(0, $castelli->count());

            $compositeId = (string) Str::uuid();
            DB::table('legislature_districts')->insert([
                'id'               => $compositeId,
                'legislature_id'   => $ctx['legislature_id'],
                'map_id'           => $ctx['map_id'],
                'jurisdiction_id'  => $ctx['leg_jurisdiction_id'],
                'district_number'  => 1,
                'seats'            => 22,
                'target_population' => (int) $castelli->sum('population'),
                'actual_population' => (int) $castelli->sum('population'),
                'fractional_seats' => 21.6,
                'floor_override'   => false,
                'status'           => 'active',
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
            foreach ($castelli as $c) {
                DB::table('legislature_district_jurisdictions')->insert([
                    'id' => (string) Str::uuid(), 'district_id' => $compositeId, 'jurisdiction_id' => $c->id,
                ]);
            }
            $clampId = (string) Str::uuid();
            DB::table('legislature_districts')->insert([
                'id'               => $clampId,
                'legislature_id'   => $ctx['legislature_id'],
                'map_id'           => $ctx['map_id'],
                'jurisdiction_id'  => $ctx['giant_id'],
                'district_number'  => 1,
                'seats'            => 9,
                'target_population' => 10825,
                'actual_population' => 10825,
                'fractional_seats' => 10.398343,
                'floor_override'   => false,
                'status'           => 'active',
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
            DB::table('legislature_district_jurisdictions')->insert([
                'id' => (string) Str::uuid(), 'district_id' => $clampId, 'jurisdiction_id' => $ctx['giant_id'],
            ]);

            $leg      = app(\App\Http\Controllers\LegislatureController::class);
            $rootSlug = (string) DB::table('jurisdictions')->where('id', $ctx['leg_jurisdiction_id'])->value('slug');
            $leafSlug = (string) DB::table('jurisdictions')->where('id', $ctx['giant_id'])->value('slug');
            $render   = fn (array $q) => $this->inertiaProps(
                $leg->districts(Request::create('/d', 'GET', $q + ['map' => $ctx['map_id']]), $rootSlug)
            );

            $props = $render([]);
            $this->assertSame(1, $props['flags']['undrawn_leaf_giants'],
                'the undrawn giant is counted the moment incomplete_scopes goes quiet');
            $giantRow = collect($props['children'])->firstWhere('id', $ctx['giant_id']);
            $this->assertSame(0, $giantRow['drawn_seats'], 'no drawn progress before drawing');

            // Draw the leaf (preview → commit).
            $controller = app(SubdivisionDrawController::class);
            $user = $this->seatedBoardUser($ctx['leg_jurisdiction_id']);
            $plan = json_decode($controller->autoseedPreview(
                Request::create('/a', 'POST', ['scope_id' => $ctx['giant_id'], 'map_id' => $ctx['map_id']]),
                $ctx['legislature_id']
            )->getContent(), true);
            $ok = $controller->autoseedCommit($this->authedRequest('/c', [
                'scope_id'  => $ctx['giant_id'],
                'map_id'    => $ctx['map_id'],
                'plan_hash' => $plan['plan_hash'],
            ], $user), $ctx['legislature_id']);
            $this->assertSame(200, $ok->getStatusCode(), $ok->getContent());

            $props = $render([]);
            $this->assertSame(0, $props['flags']['undrawn_leaf_giants'], 'drawn to budget → no longer counted');
            $giantRow = collect($props['children'])->firstWhere('id', $ctx['giant_id']);
            $this->assertSame(10, $giantRow['drawn_seats'], 'the child row shows the full drawn budget');
            // The root counter counts the drawn seats: 22 composite + 10 drawn = 32.
            $this->assertNull($props['flags']['cap'], 'drawn seats close the seat-cap gap at the root');

            // LEAF scope: the sidebar data — drawn districts ARE the districts
            // prop, with the fields the list needs (ADDITIVE shape).
            $props = $render(['scope' => $leafSlug]);
            $this->assertCount(2, $props['districts']);
            $seatSum = 0;
            foreach ($props['districts'] as $d) {
                $seatSum += $d['seats'];
                $this->assertSame('drawn', $d['method']);
                $this->assertNotEmpty($d['label']);
                $this->assertSame($d['label'], $d['name']);
                $this->assertNotEmpty($d['subdivision_id'], 'paired with the district id for deletion');
                $this->assertNotEmpty($d['id']);
                $this->assertGreaterThan(0, $d['population']);
                $this->assertArrayHasKey('deviation_pct', $d);
                $this->assertArrayHasKey('convex_hull_ratio', $d);
                $this->assertArrayHasKey('is_contiguous', $d);
                $this->assertArrayHasKey('has_integrity', $d);
            }
            $this->assertSame(10, $seatSum, 'assigned-seats counter sums the drawn districts');
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

    // ── template pins (Phase 5 template wave) ───────────────────────────────

    public function test_strip_templates_cut_at_their_fixed_angle_and_land_in_band(): void
    {
        $this->onLivePg(function (array $ctx) {
            $controller = app(SubdivisionDrawController::class);
            $floor   = ConstitutionalDefaults::floor($ctx['leg_jurisdiction_id']);
            $ceiling = ConstitutionalDefaults::ceiling($ctx['leg_jurisdiction_id']);

            foreach (['vertical_strips' => 90.0, 'horizontal_strips' => 0.0] as $template => $angle) {
                $resp = $controller->autoseedPreview(Request::create('/a', 'POST', [
                    'scope_id' => $ctx['giant_id'],
                    'template' => $template,
                ]), $ctx['legislature_id']);
                $this->assertSame(200, $resp->getStatusCode(), $resp->getContent());
                $p = json_decode($resp->getContent(), true);

                $this->assertSame($template, $p['template'], 'the preview echoes its template');
                $this->assertCount(1, $p['cuts'], "{$template}: Serravalle is one cut");
                $this->assertEqualsWithDelta($angle, $p['cuts'][0]['angle_deg'], 1e-9,
                    "{$template} must cut at its one fixed blade angle");
                $this->assertCount(2, $p['districts']);
                foreach ($p['districts'] as $d) {
                    $this->assertGreaterThanOrEqual($floor, $d['seats']);
                    $this->assertLessThanOrEqual($ceiling, $d['seats']);
                    $this->assertLessThanOrEqual(5.0, $d['per_seat_deviation_pct']);
                }
            }
        });
    }

    public function test_the_template_joins_the_plan_hash_and_a_mismatched_commit_fails_closed(): void
    {
        $this->onLivePg(function (array $ctx) {
            $controller = app(SubdivisionDrawController::class);
            $preview = fn (array $params) => json_decode($controller->autoseedPreview(
                Request::create('/a', 'POST', ['scope_id' => $ctx['giant_id']] + $params),
                $ctx['legislature_id']
            )->getContent(), true);

            $short = $preview([]);
            $vert  = $preview(['template' => 'vertical_strips']);
            $this->assertSame('shortest', $short['template']);
            $this->assertNotSame($short['plan_hash'], $vert['plan_hash'],
                'the template is part of the hashed plan identity');

            // Committing the SHORTEST hash under the strips template must be
            // refused as a changed plan — nothing persists.
            $user = $this->seatedBoardUser($ctx['leg_jurisdiction_id']);
            $resp = $controller->autoseedCommit($this->authedRequest('/c', [
                'scope_id'  => $ctx['giant_id'],
                'map_id'    => $ctx['map_id'],
                'plan_hash' => $short['plan_hash'],
                'template'  => 'vertical_strips',
            ], $user), $ctx['legislature_id']);
            $this->assertSame(422, $resp->getStatusCode());
            $this->assertStringContainsString('Plan changed', json_decode($resp->getContent(), true)['error']);
            $this->assertSame(0, (int) DB::table('district_subdivisions')
                ->where('map_id', $ctx['map_id'])->whereNull('deleted_at')->count());
        });
    }

    public function test_community_cells_produces_a_deterministic_in_band_plan_and_commits(): void
    {
        $this->onLivePg(function (array $ctx) {
            $controller = app(SubdivisionDrawController::class);
            $preview = fn () => $controller->autoseedPreview(Request::create('/a', 'POST', [
                'scope_id' => $ctx['giant_id'],
                'template' => 'community_cells',
            ]), $ctx['legislature_id']);

            $r1 = $preview();
            $this->assertSame(200, $r1->getStatusCode(), $r1->getContent());
            $p1 = json_decode($r1->getContent(), true);
            $p2 = json_decode($preview()->getContent(), true);
            $this->assertSame($p1['plan_hash'], $p2['plan_hash'],
                'two cells previews must produce the identical plan');

            // Serravalle: 10 seats → [5,5] → 2 cells, no cuts, 2 seeds.
            $this->assertSame('community_cells', $p1['template']);
            $this->assertSame([5, 5], $p1['sizes']);
            $this->assertSame([], $p1['cuts']);
            $this->assertCount(2, $p1['seeds']);
            $this->assertCount(2, $p1['districts']);
            foreach ($p1['seeds'] as $seed) {
                $this->assertArrayHasKey('lng', $seed);
                $this->assertArrayHasKey('lat', $seed);
            }

            $floor   = ConstitutionalDefaults::floor($ctx['leg_jurisdiction_id']);
            $ceiling = ConstitutionalDefaults::ceiling($ctx['leg_jurisdiction_id']);
            foreach ($p1['districts'] as $d) {
                $this->assertSame(5, $d['seats']);
                $this->assertGreaterThanOrEqual($floor, $d['seats']);
                $this->assertLessThanOrEqual($ceiling, $d['seats']);
                $this->assertLessThanOrEqual(5.0, $d['per_seat_deviation_pct'],
                    "cell {$d['path']} deviates past the guard");
                $parts = DB::selectOne(
                    'SELECT ST_NumGeometries(ST_Multi(ST_MakeValid(ST_GeomFromGeoJSON(?)))) AS parts',
                    [json_encode($d['geometry'])]
                );
                $this->assertSame(1, (int) $parts->parts, "cell {$d['path']} is not a single polygon");
            }

            // The commit path is the identical recompute→hash→file pipeline.
            $user = $this->seatedBoardUser($ctx['leg_jurisdiction_id']);
            $ok = $controller->autoseedCommit($this->authedRequest('/c', [
                'scope_id'  => $ctx['giant_id'],
                'map_id'    => $ctx['map_id'],
                'plan_hash' => $p1['plan_hash'],
                'template'  => 'community_cells',
            ], $user), $ctx['legislature_id']);
            $this->assertSame(200, $ok->getStatusCode(), $ok->getContent());
            $this->assertSame(2, json_decode($ok->getContent(), true)['districts_created']);
            $this->assertSame(2, (int) DB::table('district_subdivisions')
                ->where('parent_jurisdiction_id', $ctx['giant_id'])
                ->where('map_id', $ctx['map_id'])
                ->whereNull('deleted_at')->count());
        });
    }

    // ── access pins ─────────────────────────────────────────────────────────

    public function test_guest_posts_to_the_mutating_draw_endpoints_are_refused(): void
    {
        $this->onLivePg(function (array $ctx) {
            // Exercise the AUTH gate specifically (CSRF would 419 first and
            // mask it) — a guest must be turned away before the controller.
            $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

            $rows = fn () => [
                (int) DB::table('district_subdivisions')->whereNull('deleted_at')->count(),
                (int) DB::table('legislature_districts')->where('map_id', $ctx['map_id'])->whereNull('deleted_at')->count(),
                (int) DB::table('audit_log')->where('ref', 'F-ELB-008')->count(),
            ];
            $before = $rows();

            $endpoints = [
                route('legislatures.subdivisions.draw', ['legislature_id' => $ctx['legislature_id']]),
                route('legislatures.split-commit', ['legislature_id' => $ctx['legislature_id']]),
                route('legislatures.autoseed-lines.commit', ['legislature_id' => $ctx['legislature_id']]),
            ];
            foreach ($endpoints as $url) {
                $resp = $this->postJson($url, [
                    'scope_id'  => $ctx['giant_id'],
                    'map_id'    => $ctx['map_id'],
                    'plan_hash' => str_repeat('0', 64),
                ]);
                $this->assertSame(401, $resp->getStatusCode(),
                    "guest POST {$url} must be refused, got {$resp->getStatusCode()}");
            }

            $this->assertSame($before, $rows(), 'a guest POST must persist nothing');
        });
    }

    public function test_dev_board_seat_routes_ride_the_wi4_double_lock(): void
    {
        // Boot-registered only when APP_ENV=local (this container exports a
        // real local env, so the routes exist here — the DevImpersonationTest
        // posture); non-registration elsewhere is the routes/web.php
        // condition. What CAN be pinned in-process is the double lock:
        // DevToolsEnabled 404s when the toggle is off, and 'auth' bounces
        // guests when it is on — never a guest-reachable seat grant.
        $noCsrf = fn () => $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        config(['cga.impersonation' => false]);
        $noCsrf()->post('/dev/board/seat', ['legislature_id' => (string) Str::uuid()])->assertNotFound();
        $noCsrf()->post('/dev/board/unseat', ['legislature_id' => (string) Str::uuid()])->assertNotFound();

        config(['cga.impersonation' => true]);
        $noCsrf()->post('/dev/board/seat', ['legislature_id' => (string) Str::uuid()])->assertRedirect('/login');
        $noCsrf()->post('/dev/board/unseat', ['legislature_id' => (string) Str::uuid()])->assertRedirect('/login');
    }

    public function test_can_draw_reflects_board_provenance(): void
    {
        $this->onLivePg(function (array $ctx) {
            $controller = app(\App\Http\Controllers\LegislatureController::class);
            // Enter by SLUG — the canonical form, so districts() renders
            // instead of 302ing to the pretty URL.
            $slug = (string) DB::table('jurisdictions')
                ->where('id', $ctx['leg_jurisdiction_id'])->value('slug');

            $guest = $controller->districts(Request::create('/d', 'GET'), $slug);
            $this->assertFalse($this->inertiaProps($guest)['can_draw'], 'a guest can never draw');

            $user = $this->seatedBoardUser($ctx['leg_jurisdiction_id']);
            $req = Request::create('/d', 'GET');
            $req->setUserResolver(fn () => $user);
            $seated = $controller->districts($req, $slug);
            $this->assertTrue($this->inertiaProps($seated)['can_draw'],
                'a seated board member holds the draw pair (R-08 + provenance)');
        });
    }

    // -------------------------------------------------------------------------

    /** A POST request carrying $user, as the auth-gated endpoints receive it. */
    private function authedRequest(string $uri, array $params, User $user): Request
    {
        $req = Request::create($uri, 'POST', $params);
        $req->setUserResolver(fn () => $user);

        return $req;
    }

    /** The props array of an Inertia render (no view/asset pipeline needed). */
    private function inertiaProps(mixed $response): array
    {
        $this->assertInstanceOf(\Inertia\Response::class, $response);
        $prop = new \ReflectionProperty(\Inertia\Response::class, 'props');

        return $prop->getValue($response);
    }

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
