<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Services\ConstitutionalDefaults;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\Concerns\SeatsBoardUser;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase H (H1), F-ELB-008 Manual District Draw. The human
 * tool that lifts the childless-leaf-giant clamp, run THROUGH THE ENGINE against
 * the live Serravalle fixture (childless castello of San Marino, ISO SMR). Pins:
 *  1. a valid in-band hand-drawn piece persists a district_subdivisions row +
 *     a district + a polymorphic (subdivision_id) membership, and supersedes the
 *     giant's clamp district — WITHOUT creating any `jurisdictions` row (C5:
 *     electoral ≠ administrative);
 *  2. a piece whose population rounds OUTSIDE the resolved band is refused
 *     (Art. II §2) — never silently seated out of band;
 *  3. a non-contiguous piece is refused (Art. II §8);
 *  4. a piece outside the giant's boundary is refused (Art. II §8);
 *  5. drawing into a NON-giant scope is refused (manual draw is only for the
 *     case composite cannot handle).
 *
 * If an edit breaks these, the edit is the constitutional violation — fix the
 * edit, not the test.
 */
class ManualDistrictDrawTest extends TestCase
{
    use LivePgConnection;
    use SeatsBoardUser;

    private const LIVE_CONNECTION = 'pgsql_manual_draw';

    public function test_the_draw_surface_and_form_are_registered(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('legislatures.population-probe'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('legislatures.subdivisions.draw'));
        $this->assertSame(
            \App\Domain\Forms\Handlers\ManualDistrictDraw::class,
            \App\Domain\Forms\FormRegistry::handlerFor('F-ELB-008'),
        );
    }

    public function test_a_valid_draw_persists_a_subdivision_district_and_lifts_the_clamp(): void
    {
        $this->onLivePg(function (array $ctx) {
            $before = (int) DB::table('jurisdictions')->count();

            $result = $this->engine()->file('F-ELB-008', null, [
                'legislature_id' => $ctx['legislature_id'],
                'scope_id'       => $ctx['giant_id'],
                'jurisdiction_id'=> $ctx['leg_jurisdiction_id'],
                'map_id'         => $ctx['map_id'],
                'geojson'        => $ctx['inband_geojson'],
            ]);

            $rec = $result->recorded;
            $floor   = ConstitutionalDefaults::floor($ctx['leg_jurisdiction_id']);
            $ceiling = ConstitutionalDefaults::ceiling($ctx['leg_jurisdiction_id']);

            $this->assertGreaterThanOrEqual($floor, $rec['seats'], 'drawn seats below floor');
            $this->assertLessThanOrEqual($ceiling, $rec['seats'], 'drawn seats above ceiling');

            // The subdivision + district + polymorphic membership all exist.
            $this->assertDatabaseHasOn(self::LIVE_CONNECTION, 'district_subdivisions', [
                'id'     => $rec['subdivision_id'],
                'method' => 'manual',
            ]);
            $this->assertDatabaseHasOn(self::LIVE_CONNECTION, 'legislature_districts', [
                'id' => $rec['district_id'],
            ]);
            $member = DB::table('legislature_district_jurisdictions')
                ->where('district_id', $rec['district_id'])->first();
            $this->assertNotNull($member);
            $this->assertSame($rec['subdivision_id'], $member->subdivision_id, 'member is the subdivision');
            $this->assertNull($member->jurisdiction_id, 'a drawn member carries no jurisdiction_id');

            // C5 — the subdivision is NOT an administrative jurisdiction.
            $this->assertSame($before, (int) DB::table('jurisdictions')->count(),
                'a drawn subdivision must never create a jurisdictions row');

            $this->assertTrue($rec['clamp_superseded'], 'the giant clamp district is superseded');

            // Operator expectation: the drawn district PAINTS on the map — it must
            // come back from revealedGeoJson at the giant scope as a sub_district
            // feature carrying the drawn geometry.
            $response = app(\App\Http\Controllers\LegislatureController::class)->revealedGeoJson(
                \Illuminate\Http\Request::create('/r', 'GET', [
                    'scope' => $ctx['giant_id'],
                    'map'   => $ctx['map_id'],
                    'zoom'  => 12,
                ]),
                $ctx['legislature_id']
            );
            $fc = json_decode($response->getContent(), true);
            $drawn = collect($fc['features'] ?? [])
                ->first(fn ($f) => ($f['properties']['district_id'] ?? null) === $rec['district_id']);

            $this->assertNotNull($drawn, 'the drawn district must render via revealedGeoJson');
            $this->assertSame('sub_district', $drawn['properties']['type']);
            $this->assertNotNull($drawn['geometry'] ?? null, 'the rendered feature carries the drawn geometry');
        });
    }

    public function test_deleting_a_drawn_district_retires_its_subdivision_and_frees_the_area(): void
    {
        $this->onLivePg(function (array $ctx) {
            $rec = $this->engine()->file('F-ELB-008', null, [
                'legislature_id' => $ctx['legislature_id'],
                'scope_id'       => $ctx['giant_id'],
                'map_id'         => $ctx['map_id'],
                'geojson'        => $ctx['inband_geojson'],
            ])->recorded;
            $firstLabel = DB::table('district_subdivisions')->where('id', $rec['subdivision_id'])->value('label');

            // The delete endpoint retires the SUBDIVISION with its district.
            // Leaving it live was the "ghost": its label collided with the next
            // auto-numbered filing (the operator's 23505 500) and its geometry
            // tripped the sibling-overlap gate on any redraw of the same area.
            $resp = app(\App\Http\Controllers\LegislatureController::class)
                ->deleteDistrict($ctx['legislature_id'], $rec['district_id']);
            $this->assertSame(200, $resp->getStatusCode(), $resp->getContent());
            $sub = DB::table('district_subdivisions')->where('id', $rec['subdivision_id'])->first();
            $this->assertNotNull($sub->deleted_at, 'the subdivision must be retired with its district');

            // The SAME shape draws again cleanly — never a 23505, never a
            // phantom overlap from the retired geometry.
            $again = $this->engine()->file('F-ELB-008', null, [
                'legislature_id' => $ctx['legislature_id'],
                'scope_id'       => $ctx['giant_id'],
                'map_id'         => $ctx['map_id'],
                'geojson'        => $ctx['inband_geojson'],
            ])->recorded;
            $this->assertNotSame($rec['subdivision_id'], $again['subdivision_id']);

            // The auto label never reuses the ghost's name (numbering counts
            // soft-deleted labels too), so live-uniqueness holds by construction.
            $newLabel = DB::table('district_subdivisions')->where('id', $again['subdivision_id'])->value('label');
            $this->assertNotSame($firstLabel, $newLabel, 'auto labels must never reuse a ghost label');
        });
    }

    public function test_a_duplicate_operator_label_is_refused_with_a_citation_not_a_500(): void
    {
        $this->onLivePg(function (array $ctx) {
            $this->engine()->file('F-ELB-008', null, [
                'legislature_id' => $ctx['legislature_id'],
                'scope_id'       => $ctx['giant_id'],
                'map_id'         => $ctx['map_id'],
                'geojson'        => $ctx['inband_geojson'],
                'label'          => 'West Serravalle',
            ]);

            // A second filing under the same live label must be refused by the
            // handler (422 + citation through the endpoint), never surface as
            // a raw unique-index 23505.
            try {
                $this->engine()->file('F-ELB-008', null, [
                    'legislature_id' => $ctx['legislature_id'],
                    'scope_id'       => $ctx['giant_id'],
                    'map_id'         => $ctx['map_id'],
                    'geojson'        => $ctx['tiny_geojson'],   // any shape; the label gate fires first
                    'label'          => 'West Serravalle',
                ]);
                $this->fail('a duplicate live label must be refused');
            } catch (ConstitutionalViolation $e) {
                $this->assertStringContainsString('West Serravalle', $e->getMessage());
            }
        });
    }

    public function test_an_out_of_band_piece_is_refused(): void
    {
        $this->onLivePg(function (array $ctx) {
            $this->expectException(ConstitutionalViolation::class);
            // A 30 m box holds ~0 people → 0 seats, below the floor.
            $this->engine()->file('F-ELB-008', null, [
                'legislature_id' => $ctx['legislature_id'],
                'scope_id'       => $ctx['giant_id'],
                'map_id'         => $ctx['map_id'],
                'geojson'        => $ctx['tiny_geojson'],
            ]);
        });
    }

    public function test_a_non_contiguous_piece_is_refused(): void
    {
        $this->onLivePg(function (array $ctx) {
            $this->expectException(ConstitutionalViolation::class);
            $this->engine()->file('F-ELB-008', null, [
                'legislature_id' => $ctx['legislature_id'],
                'scope_id'       => $ctx['giant_id'],
                'map_id'         => $ctx['map_id'],
                'geojson'        => $ctx['multipart_geojson'],
            ]);
        });
    }

    public function test_a_piece_outside_the_giant_is_refused(): void
    {
        $this->onLivePg(function (array $ctx) {
            $this->expectException(ConstitutionalViolation::class);
            $this->engine()->file('F-ELB-008', null, [
                'legislature_id' => $ctx['legislature_id'],
                'scope_id'       => $ctx['giant_id'],
                'map_id'         => $ctx['map_id'],
                'geojson'        => $ctx['offshore_geojson'],
            ]);
        });
    }

    public function test_drawing_into_a_non_giant_scope_is_refused(): void
    {
        $this->onLivePg(function (array $ctx) {
            $this->expectException(ConstitutionalViolation::class);
            $this->engine()->file('F-ELB-008', null, [
                'legislature_id' => $ctx['legislature_id'],
                'scope_id'       => $ctx['leg_jurisdiction_id'],  // San Marino itself — not a leaf giant
                'map_id'         => $ctx['map_id'],
                'geojson'        => $ctx['inband_geojson'],
            ]);
        });
    }

    public function test_split_probe_partitions_the_giant_population_into_two_sides(): void
    {
        $this->onLivePg(function (array $ctx) {
            $controller = app(\App\Http\Controllers\Legislature\SubdivisionDrawController::class);
            $req = \Illuminate\Http\Request::create('/sp', 'POST', [
                'scope_id' => $ctx['giant_id'],
                'line'     => $ctx['bisect_line'],
            ]);
            $resp = $controller->splitProbe($req, $ctx['legislature_id']);
            $this->assertSame(200, $resp->getStatusCode(), $resp->getContent());
            $data = json_decode($resp->getContent(), true);

            $this->assertCount(2, $data['sides'], 'a bisecting line yields exactly two sides');
            $this->assertGreaterThan(0, $data['sides'][0]['population']);
            $this->assertGreaterThan(0, $data['sides'][1]['population']);
            // The two sides partition the giant — their populations sum to ~its total
            // (small clip error tolerated), proving the split + raster sum are sound.
            $sum = $data['sides'][0]['population'] + $data['sides'][1]['population'];
            $this->assertEqualsWithDelta($ctx['giant_population'], $sum, $ctx['giant_population'] * 0.05,
                'the two sides sum to the giant population');
        });
    }

    public function test_split_commit_creates_two_districts_when_both_sides_are_in_band(): void
    {
        $this->onLivePg(function (array $ctx) {
            $controller = app(\App\Http\Controllers\Legislature\SubdivisionDrawController::class);
            $probe = json_decode($controller->splitProbe(
                \Illuminate\Http\Request::create('/sp', 'POST', ['scope_id' => $ctx['giant_id'], 'line' => $ctx['bisect_line']]),
                $ctx['legislature_id']
            )->getContent(), true);

            // The commit files as a SEATED board member — split-commit is
            // auth-gated now, so the guest/null-actor posture no longer exists.
            $user = $this->seatedBoardUser($ctx['leg_jurisdiction_id']);
            $commitReq = \Illuminate\Http\Request::create('/sc', 'POST', [
                'scope_id' => $ctx['giant_id'],
                'map_id'   => $ctx['map_id'],
                'line'     => $ctx['bisect_line'],
            ]);
            $commitReq->setUserResolver(fn () => $user);
            $resp = $controller->splitCommit($commitReq, $ctx['legislature_id']);

            if ($probe['both_in_band']) {
                $this->assertSame(200, $resp->getStatusCode(), $resp->getContent());
                $data = json_decode($resp->getContent(), true);
                $this->assertCount(2, $data['districts']);
                $this->assertSame(2, (int) DB::table('district_subdivisions')
                    ->where('parent_jurisdiction_id', $ctx['giant_id'])
                    ->where('map_id', $ctx['map_id'])->whereNull('deleted_at')->count());
            } else {
                // Out-of-band cut must be refused atomically — neither side persisted.
                $this->assertSame(422, $resp->getStatusCode());
                $this->assertSame(0, (int) DB::table('district_subdivisions')
                    ->where('parent_jurisdiction_id', $ctx['giant_id'])
                    ->where('map_id', $ctx['map_id'])->whereNull('deleted_at')->count());
            }
        });
    }

    public function test_a_snap_balanced_diagonal_cut_commits_without_boundary_epsilon_refusal(): void
    {
        $this->onLivePg(function (array $ctx) {
            $controller = app(\App\Http\Controllers\Legislature\SubdivisionDrawController::class);

            // The operator's exact field gesture: a slanted hand cut, slid to
            // balance by the assist, then committed. Both sides are inside the
            // giant BY CONSTRUCTION (pieces of an ST_Split of the giant), so a
            // refusal citing Art. II §8 "extends outside the boundary" can only
            // be the decimal-GeoJSON round-trip epsilon — the bug this pins.
            $balanced = json_decode($controller->splitBalance(
                \Illuminate\Http\Request::create('/sb', 'POST', [
                    'scope_id' => $ctx['giant_id'],
                    'line'     => $ctx['diagonal_line'],
                ]),
                $ctx['legislature_id']
            )->getContent(), true);
            $this->assertTrue($balanced['both_in_band'] ?? false, 'the assist lands the diagonal in band');

            $user = $this->seatedBoardUser($ctx['leg_jurisdiction_id']);
            $commitReq = \Illuminate\Http\Request::create('/sc', 'POST', [
                'scope_id' => $ctx['giant_id'],
                'map_id'   => $ctx['map_id'],
                'line'     => json_encode($balanced['line']),
            ]);
            $commitReq->setUserResolver(fn () => $user);
            $resp = $controller->splitCommit($commitReq, $ctx['legislature_id']);

            $this->assertSame(200, $resp->getStatusCode(), $resp->getContent());
            $data = json_decode($resp->getContent(), true);
            $this->assertCount(2, $data['districts']);
        });
    }

    public function test_drawn_districts_list_at_the_ancestor_scope_without_double_counting(): void
    {
        $this->onLivePg(function (array $ctx) {
            $drawn = $this->commitBalancedDiagonalSplit($ctx);

            // The ANCESTOR scope (the San Marino root) lists the drawn rows —
            // the reveal layer already painted them there (Branches 4/5); the
            // sidebar list must carry the same districts.
            $props = $this->districtsPageProps($ctx);

            $rows = array_values(array_filter(
                $props['districts'],
                fn ($d) => ($d['method'] ?? null) === 'drawn'
            ));
            $this->assertCount(2, $rows, 'both drawn districts list at the root scope');
            foreach ($rows as $d) {
                $this->assertSame('Serravalle', $d['scope_name']);
                $this->assertNotEmpty($d['label']);
                $this->assertNotEmpty($d['subdivision_id']);
                $this->assertArrayHasKey('color_index', $d);
                $this->assertArrayHasKey('deviation_pct', $d);
                $this->assertArrayHasKey('fractional_seats', $d, 'every composite row key is present');
            }

            // Counters stay honest: the giant's child row ALREADY carries the
            // drawn seats (child_committed subdivision branch) — the new list
            // rows must never add them a second time anywhere.
            $drawnSeats = array_sum(array_column($rows, 'seats'));
            $this->assertSame(count($drawn['district_ids']), count($rows));
            $giantRow = collect($props['children'])->firstWhere('id', $ctx['giant_id']);
            $this->assertNotNull($giantRow, 'Serravalle appears as a child at the root scope');
            $this->assertSame($drawnSeats, $giantRow['drawn_seats']);
            $this->assertSame($drawnSeats, $giantRow['child_assigned_seats']);
            if (($props['flags']['cap'] ?? null) !== null) {
                $this->assertSame($drawnSeats, $props['flags']['cap']['total'],
                    'the cap flag counts each drawn seat exactly once');
            }
        });
    }

    public function test_drawn_siblings_and_their_touching_composite_neighbor_color_pairwise_distinctly(): void
    {
        $this->onLivePg(function (array $ctx) {
            $drawn = $this->commitBalancedDiagonalSplit($ctx);

            // A composite neighbor TOUCHING BOTH drawn pieces, resolved from
            // live geometry (San Marino's castelli are small — the giant's
            // southern neighbors usually span both sides of a diagonal cut).
            $neighbor = DB::selectOne('
                SELECT j.id, j.population
                  FROM jurisdictions j
                 WHERE j.parent_id = ?
                   AND j.id <> ?
                   AND j.deleted_at IS NULL
                   AND j.geom IS NOT NULL
                   AND NOT EXISTS (
                       SELECT 1 FROM district_subdivisions ds
                        WHERE ds.map_id = ?
                          AND ds.parent_jurisdiction_id = ?
                          AND ds.deleted_at IS NULL
                          AND NOT ST_DWithin(ds.geom, j.geom, 0.000001)
                   )
                 LIMIT 1
            ', [$ctx['leg_jurisdiction_id'], $ctx['giant_id'], $ctx['map_id'], $ctx['giant_id']]);
            if ($neighbor === null) {
                $this->markTestSkipped('No castello touches both drawn pieces under this cut — geometry-dependent pin.');
            }

            $compositeId = (string) Str::uuid();
            DB::table('legislature_districts')->insert([
                'id'                => $compositeId,
                'legislature_id'    => $ctx['legislature_id'],
                'map_id'            => $ctx['map_id'],
                'jurisdiction_id'   => $ctx['leg_jurisdiction_id'],
                'district_number'   => 99,
                'seats'             => 5,
                'target_population' => (int) $neighbor->population,
                'actual_population' => (int) $neighbor->population,
                'fractional_seats'  => 5.0,
                'floor_override'    => false,
                'status'            => 'active',
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
            DB::table('legislature_district_jurisdictions')->insert([
                'id'              => (string) Str::uuid(),
                'district_id'     => $compositeId,
                'jurisdiction_id' => $neighbor->id,
            ]);

            // Colors recompute on the next read (the split-commit flushed the
            // revealed tag and nothing has re-primed it since).
            $props = $this->districtsPageProps($ctx);
            $byId  = collect($props['districts'])->keyBy('id');

            $colors = [];
            foreach ([...$drawn['district_ids'], $compositeId] as $did) {
                $this->assertTrue($byId->has($did), "district {$did} lists at the root scope");
                $colors[$did] = $byId[$did]['color_index'];
            }
            $this->assertCount(3, array_unique($colors),
                'two drawn siblings + their touching composite neighbor wear pairwise-distinct colors');

            // One source of truth: revealedGeoJson paints the SAME color the
            // sidebar row carries.
            $fc = json_decode(app(\App\Http\Controllers\LegislatureController::class)->revealedGeoJson(
                \Illuminate\Http\Request::create('/r', 'GET', [
                    'scope' => $ctx['leg_jurisdiction_id'],
                    'map'   => $ctx['map_id'],
                    'zoom'  => 12,
                ]),
                $ctx['legislature_id']
            )->getContent(), true);
            foreach ($fc['features'] as $f) {
                $did = $f['properties']['district_id'] ?? null;
                if ($did !== null && isset($colors[$did])) {
                    $this->assertSame($colors[$did], $f['properties']['color_index'],
                        'map fill and sidebar dot read the same color map');
                }
            }
        });
    }

    public function test_probe_and_draw_auto_clip_an_over_the_boundary_rectangle(): void
    {
        $this->onLivePg(function (array $ctx) {
            $controller = app(\App\Http\Controllers\Legislature\SubdivisionDrawController::class);
            $probe = function (string $geojson) use ($controller, $ctx) {
                return $controller->probe(
                    \Illuminate\Http\Request::create('/pp', 'POST', [
                        'scope_id' => $ctx['giant_id'],
                        'geojson'  => $geojson,
                    ]),
                    $ctx['legislature_id']
                );
            };

            // Reference: the pre-clipped western 60% — never trimmed.
            $refResp = $probe($ctx['inband_geojson']);
            $this->assertSame(200, $refResp->getStatusCode(), $refResp->getContent());
            $reference = json_decode($refResp->getContent(), true);
            $this->assertFalse($reference['clipped'], 'a fully-inside shape passes through untrimmed');
            $this->assertNotNull($reference['geometry'], 'the measured geometry is always echoed');

            // The raw over-the-boundary rectangle probes to the SAME region:
            // clipped, echoed, measured — never refused with ✗outside.
            $resp = $probe($ctx['overdraw_geojson']);
            $this->assertSame(200, $resp->getStatusCode(), $resp->getContent());
            $data = json_decode($resp->getContent(), true);
            $this->assertTrue($data['clipped'], 'the crossing rectangle is trimmed, not refused');
            $this->assertTrue($data['within_giant'], 'the compatibility key holds on every 200');
            $this->assertNotNull($data['geometry'], 'the clipped geometry is echoed for the pending layer');
            $this->assertEqualsWithDelta($reference['population'], $data['population'],
                max(1, $reference['population'] * 0.02),
                'the readout prices the clipped shape, not the raw rectangle');

            // The SAME raw rectangle COMMITS through draw() — clipped before
            // filing, so the handler's exact CoveredBy proof passes by
            // construction. The operator label passes through unchanged.
            $user = $this->seatedBoardUser($ctx['leg_jurisdiction_id']);
            $drawReq = \Illuminate\Http\Request::create('/dd', 'POST', [
                'scope_id' => $ctx['giant_id'],
                'map_id'   => $ctx['map_id'],
                'geojson'  => $ctx['overdraw_geojson'],
                'label'    => 'Clipped West',
            ]);
            $drawReq->setUserResolver(fn () => $user);
            $drawResp = $controller->draw($drawReq, $ctx['legislature_id']);
            $this->assertSame(200, $drawResp->getStatusCode(), $drawResp->getContent());
            $this->assertTrue(
                DB::table('district_subdivisions')
                    ->where('map_id', $ctx['map_id'])
                    ->where('label', 'Clipped West')
                    ->whereNull('deleted_at')
                    ->exists(),
                'the clipped rectangle filed under its manual label'
            );

            // A polygon entirely outside the giant still refuses — plainly.
            $off = $probe($ctx['offshore_geojson']);
            $this->assertSame(422, $off->getStatusCode());
            $this->assertStringContainsString(
                'entirely outside',
                json_decode($off->getContent(), true)['error'] ?? ''
            );
        });
    }

    // -------------------------------------------------------------------------

    /**
     * The proven field gesture shared by the new pins: a hand-drawn diagonal,
     * slid to seat balance by the assist, committed into TWO drawn siblings.
     *
     * @return array{district_ids: string[]}
     */
    private function commitBalancedDiagonalSplit(array $ctx): array
    {
        $controller = app(\App\Http\Controllers\Legislature\SubdivisionDrawController::class);

        $balanced = json_decode($controller->splitBalance(
            \Illuminate\Http\Request::create('/sb', 'POST', [
                'scope_id' => $ctx['giant_id'],
                'line'     => $ctx['diagonal_line'],
            ]),
            $ctx['legislature_id']
        )->getContent(), true);
        $this->assertTrue($balanced['both_in_band'] ?? false, 'the assist lands the diagonal in band');

        $user = $this->seatedBoardUser($ctx['leg_jurisdiction_id']);
        $commitReq = \Illuminate\Http\Request::create('/sc', 'POST', [
            'scope_id' => $ctx['giant_id'],
            'map_id'   => $ctx['map_id'],
            'line'     => json_encode($balanced['line']),
        ]);
        $commitReq->setUserResolver(fn () => $user);
        $resp = $controller->splitCommit($commitReq, $ctx['legislature_id']);
        $this->assertSame(200, $resp->getStatusCode(), $resp->getContent());
        $districts = json_decode($resp->getContent(), true)['districts'];

        return ['district_ids' => array_column($districts, 'district_id')];
    }

    /**
     * GET the mapper page at the legislature's ROOT scope (by slug, on the
     * test's draft map) and return the raw Inertia props.
     */
    private function districtsPageProps(array $ctx): array
    {
        $slug = DB::table('jurisdictions')->where('id', $ctx['leg_jurisdiction_id'])->value('slug');
        $this->assertNotNull($slug, 'the root jurisdiction carries a slug');

        $props = null;
        $this->get("/legislatures/{$slug}/districts?map={$ctx['map_id']}")
            ->assertOk()
            ->assertInertia(function (\Inertia\Testing\AssertableInertia $page) use (&$props) {
                $props = $page->toArray()['props'];
                return $page->component('Legislature/Districts');
            });
        $this->assertIsArray($props);

        return $props;
    }

    private function engine(): ConstitutionalEngine
    {
        return app(ConstitutionalEngine::class);
    }

    private function assertDatabaseHasOn(string $connection, string $table, array $where): void
    {
        $this->assertTrue(
            DB::connection($connection)->table($table)->where($where)->exists(),
            "expected a row in {$table} matching ".json_encode($where)
        );
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

    /**
     * Resolve the live fixture (never hard-code UUIDs) and synthesise the test
     * polygons from the giant's real geometry + bbox.
     */
    private function buildContext(): ?array
    {
        $giant = DB::selectOne(
            "SELECT id, parent_id, population, ST_AsText(ST_Envelope(geom)) AS env,
                    ST_XMin(geom) AS xmin, ST_XMax(geom) AS xmax, ST_YMin(geom) AS ymin, ST_YMax(geom) AS ymax
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

        // A draft plan to draw into.
        $mapId = (string) Str::uuid();
        DB::table('legislature_district_maps')->insert([
            'id'             => $mapId,
            'legislature_id' => $leg->id,
            'name'           => 'H1 manual-draw pin',
            'status'         => 'draft',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Seed a clamp district (member = the giant itself) so the happy path can
        // prove it is superseded — mirrors clampUnassignedLeafGiants output.
        $clampId = (string) Str::uuid();
        DB::table('legislature_districts')->insert([
            'id'              => $clampId,
            'legislature_id'  => $leg->id,
            'map_id'          => $mapId,
            'jurisdiction_id' => $giant->id,
            'district_number' => 1,
            'seats'           => ConstitutionalDefaults::ceiling($leg->jurisdiction_id),
            'target_population'=> (int) $giant->population,
            'actual_population'=> (int) $giant->population,
            'fractional_seats'=> 10.398343,
            'floor_override'  => false,
            'status'          => 'active',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        DB::table('legislature_district_jurisdictions')->insert([
            'id'              => (string) Str::uuid(),
            'district_id'     => $clampId,
            'jurisdiction_id' => $giant->id,
        ]);

        // In-band piece: the western 60% of the giant by longitude, clipped to it.
        $xCut = $giant->xmin + 0.60 * ($giant->xmax - $giant->xmin);
        $inband = DB::selectOne(
            'SELECT ST_AsGeoJSON(ST_Multi(ST_CollectionExtract(
                 ST_Intersection(ST_MakeValid(geom), ST_MakeEnvelope(?, ?, ?, ?, 4326)), 3))) AS gj
               FROM jurisdictions WHERE id = ?',
            [$giant->xmin - 0.001, $giant->ymin - 0.001, $xCut, $giant->ymax + 0.001, $giant->id]
        );

        // The SAME western 60% cut as $inband, but drawn the way the operator
        // really draws it: a raw rectangle with fat margins hanging outside the
        // giant on three sides. The auto-clip must trim this to the giant and
        // measure/file the identical region $inband covers.
        $overdraw = json_encode([
            'type' => 'Polygon',
            'coordinates' => [[
                [$giant->xmin - 0.05, $giant->ymin - 0.05],
                [$xCut,               $giant->ymin - 0.05],
                [$xCut,               $giant->ymax + 0.05],
                [$giant->xmin - 0.05, $giant->ymax + 0.05],
                [$giant->xmin - 0.05, $giant->ymin - 0.05],
            ]],
        ]);

        // Tiny box ~ centre of the giant (≈ 0 people → below floor).
        $cx = ($giant->xmin + $giant->xmax) / 2;
        $cy = ($giant->ymin + $giant->ymax) / 2;
        $tiny = json_encode([
            'type' => 'Polygon',
            'coordinates' => [[
                [$cx, $cy], [$cx + 0.0003, $cy], [$cx + 0.0003, $cy + 0.0003], [$cx, $cy + 0.0003], [$cx, $cy],
            ]],
        ]);

        // Two disjoint boxes → a non-contiguous (multi-part) piece.
        $multipart = json_encode([
            'type' => 'MultiPolygon',
            'coordinates' => [
                [[[$giant->xmin, $giant->ymin], [$giant->xmin + 0.002, $giant->ymin], [$giant->xmin + 0.002, $giant->ymin + 0.002], [$giant->xmin, $giant->ymin + 0.002], [$giant->xmin, $giant->ymin]]],
                [[[$giant->xmax - 0.002, $giant->ymax - 0.002], [$giant->xmax, $giant->ymax - 0.002], [$giant->xmax, $giant->ymax], [$giant->xmax - 0.002, $giant->ymax], [$giant->xmax - 0.002, $giant->ymax - 0.002]]],
            ],
        ]);

        // A box well to the south (outside the giant entirely).
        $offshore = json_encode([
            'type' => 'Polygon',
            'coordinates' => [[
                [$cx, $giant->ymin - 0.5], [$cx + 0.01, $giant->ymin - 0.5],
                [$cx + 0.01, $giant->ymin - 0.49], [$cx, $giant->ymin - 0.49], [$cx, $giant->ymin - 0.5],
            ]],
        ]);

        // A bisecting line: vertical through the giant centroid, top to bottom.
        $bisectLine = json_encode([
            'type' => 'LineString',
            'coordinates' => [[$cx, $giant->ymin - 0.001], [$cx, $giant->ymax + 0.001]],
        ]);

        // A SLANTED cut (the operator's real gesture): its ST_Split pieces
        // carry non-terminating decimal vertices, so the GeoJSON round-trip
        // epsilon actually bites — an axis-aligned line can pass by luck.
        $diagonalLine = json_encode([
            'type' => 'LineString',
            'coordinates' => [
                [$cx - 0.3 * ($giant->xmax - $giant->xmin), $giant->ymin - 0.001],
                [$cx + 0.3 * ($giant->xmax - $giant->xmin), $giant->ymax + 0.001],
            ],
        ]);

        return [
            'legislature_id'      => $leg->id,
            'leg_jurisdiction_id' => $leg->jurisdiction_id,
            'giant_id'            => $giant->id,
            'giant_population'    => (int) $giant->population,
            'map_id'              => $mapId,
            'inband_geojson'      => $inband->gj,
            'overdraw_geojson'    => $overdraw,
            'tiny_geojson'        => $tiny,
            'multipart_geojson'   => $multipart,
            'offshore_geojson'    => $offshore,
            'bisect_line'         => $bisectLine,
            'diagonal_line'       => $diagonalLine,
        ];
    }
}
