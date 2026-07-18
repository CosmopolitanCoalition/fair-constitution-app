<?php

namespace Tests\Constitutional;

use App\Http\Controllers\LegislatureController;
use App\Services\ConstitutionalDefaults;
use App\Services\Districting\LeafGiantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\Concerns\SeatsBoardUser;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the MIXED AUTOSEED (2026-07-17): one mass-reseed sweep
 * produces the COMPLETE map — composite districts for child-bearing scopes AND
 * line-split F-ELB-008 districts for childless leaf giants — automating
 * apportionment-law step 5 ("an above-ceiling jurisdiction with no children
 * awaits manual split") without touching any seat math.
 *
 * Runs on a fully SYNTHETIC fixture built in-transaction (a rectangular
 * "Pinland" with 4 composite children + 1 childless giant strip + a uniform
 * synthetic WorldPop tile), so the pins hold on ANY box — no demo geodata
 * required. Pins:
 *  1. mass-reseed is auth-gated — the sweep files F-ELB-008 as the initiating
 *     operator, and a guest POST must never reach the null-actor system path;
 *  2. ONE detector: LeafGiantResolver::context() flags exactly the childless
 *     giant (budget = max(floor, round(frac)) — the handler's own math) and
 *     refuses child-bearing and sub-threshold scopes;
 *  3. THE MIXED SWEEP: map_plus_children_all from the root creates composite
 *     districts at the root scope AND line-split districts at the giant, in
 *     the same run, seats exact (24 composite + 10 drawn = 34), every drawn
 *     piece an audited F-ELB-008 by the REAL actor (never null);
 *  4. _unassigned resume skips a giant whose drawn set already exists —
 *     the operator's work is never silently replaced;
 *  5. a sweep WITHOUT an initiating operator refuses the leaf giant per-scope
 *     (recorded error, nothing filed) instead of riding the system path;
 *  6. the districting_autoseed_template Setup Option resolves through
 *     ConstitutionalDefaults (default 'shortest', amendable, invalid values
 *     fall back to 'shortest' — never a stringy strip by accident);
 *  7. wizard steps label each scope by method (is_leaf) so the stepper shows
 *     ✂ line-split vs composite.
 *
 * If an edit breaks these, the edit is the constitutional violation — fix the
 * edit, not the test.
 */
class MixedAutoseedSweepTest extends TestCase
{
    use LivePgConnection;
    use SeatsBoardUser;

    private const LIVE_CONNECTION = 'pgsql_mixed_sweep';

    /** Synthetic fixture geometry (WGS84): Pinland = [10.0,10.4]×[50.0,50.2]. */
    private const ISO = 'ZZP';

    // ── pure pins (no DB) ───────────────────────────────────────────────────

    public function test_mass_reseed_is_auth_gated(): void
    {
        $route = Route::getRoutes()->getByName('legislatures.mass-reseed');
        $this->assertNotNull($route, 'mass-reseed route must exist');
        $this->assertContains(
            'auth',
            $route->gatherMiddleware(),
            'mass-reseed must require a session — the sweep files F-ELB-008 as the '
            .'initiating operator, and a guest POST must never ride the null-actor system path.'
        );
    }

    // ── live pins (synthetic fixture; skip only when pg is unreachable) ─────

    public function test_detector_flags_exactly_the_childless_giant(): void
    {
        $this->onLivePg(function (array $ctx) {
            $resolver = app(LeafGiantResolver::class);

            $giant = $resolver->context($ctx['legislature_id'], $ctx['giant_id']);
            $this->assertNotNull($giant, 'the childless giant must be detected');
            $this->assertSame(10, $giant['budget'], 'budget = max(floor, round(10.2)) = 10');
            $this->assertEqualsWithDelta(1200.0, $giant['quota'], 0.001);

            // A child-bearing scope (the root) is never a leaf giant.
            $this->assertNull($resolver->context($ctx['legislature_id'], $ctx['root_id']));
            // A sub-threshold child (frac 5.95 < 9.5) is never a leaf giant.
            $this->assertNull($resolver->context($ctx['legislature_id'], $ctx['composite_ids'][0]));
        });
    }

    public function test_one_sweep_creates_composite_and_line_split_districts_together(): void
    {
        $this->onLivePg(function (array $ctx) {
            $user = $this->seatedBoardUser($ctx['root_id']);

            $result = app(LegislatureController::class)->executeMassReseedSweep(
                $ctx['legislature_id'],
                'map_plus_children_all',
                $ctx['root_id'],
                $ctx['map_id'],
                (string) $user->id,
                null,   // template → the constitutional default ('shortest')
            );

            $this->assertSame([], $result['errors'], 'the mixed sweep must complete clean: '.json_encode($result['errors']));
            $this->assertSame(2, $result['scopes_processed'], 'root (composite) + giant (line-split)');

            // Composite districts at the root scope: the 4 sub-threshold
            // children, one district each (pairs would round past the ceiling),
            // 24 seats — the giant is excluded at this scope, never stubbed.
            $composite = DB::table('legislature_districts')
                ->where('legislature_id', $ctx['legislature_id'])
                ->where('jurisdiction_id', $ctx['root_id'])
                ->where('map_id', $ctx['map_id'])
                ->whereNull('deleted_at')
                ->get(['seats']);
            $this->assertCount(4, $composite);
            $this->assertSame(24, (int) $composite->sum('seats'));
            foreach ($composite as $d) {
                $this->assertGreaterThanOrEqual(5, (int) $d->seats);
                $this->assertLessThanOrEqual(9, (int) $d->seats);
            }

            // Line-split districts at the giant: budget 10 → [5,5], each an
            // F-ELB-008-filed synthetic piece (district_subdivisions row).
            $subdivisions = DB::table('district_subdivisions')
                ->where('parent_jurisdiction_id', $ctx['giant_id'])
                ->where('map_id', $ctx['map_id'])
                ->whereNull('deleted_at')
                ->get(['seats', 'method']);
            $this->assertCount(2, $subdivisions, 'the giant must be line-split in the SAME sweep');
            foreach ($subdivisions as $s) {
                $this->assertSame('manual', $s->method, 'autoseed leaves persist through the F-ELB-008 handler');
            }
            $this->assertSame(10, (int) $subdivisions->sum('seats'), 'drawn seats must equal the giant budget');

            $drawn = DB::table('legislature_districts')
                ->where('legislature_id', $ctx['legislature_id'])
                ->where('jurisdiction_id', $ctx['giant_id'])
                ->where('map_id', $ctx['map_id'])
                ->whereNull('deleted_at')
                ->get(['seats', 'num_geom_parts', 'is_contiguous']);
            $this->assertCount(2, $drawn);
            $this->assertSame(10, (int) $drawn->sum('seats'));

            // ISLAND RIDING (the LA-County shape): the giant is mainland +
            // one detached island — exactly ONE of the two districts carries
            // it whole (num_geom_parts 2, honestly non-contiguous); the other
            // is a single cut piece. The blade never strands or fragments it.
            $islandCarrier = $drawn->where('is_contiguous', false);
            $this->assertCount(1, $islandCarrier,
                'exactly one line-split district must carry the detached island whole');
            $this->assertSame(2, (int) $islandCarrier->first()->num_geom_parts);
            $this->assertCount(1, $drawn->where('is_contiguous', true),
                'the other district is a single contiguous cut piece');

            // The whole map seats the full legislature: 24 + 10 = 34 = type_a.
            $total = (int) DB::table('legislature_districts')
                ->where('legislature_id', $ctx['legislature_id'])
                ->where('map_id', $ctx['map_id'])
                ->whereNull('deleted_at')
                ->sum('seats');
            $this->assertSame(34, $total, 'composite + line-split must seat the complete legislature');

            // Authorship: the filings carry the REAL initiating actor — never
            // the null system actor.
            $authored = (int) DB::table('audit_log')
                ->where('actor_user_id', (string) $user->id)
                ->count();
            $this->assertGreaterThanOrEqual(2, $authored, 'each line-split district files as the initiating operator');

            // Pin 4 — resume: an _unassigned re-run leaves the drawn giant
            // alone (skip, not replace, not error).
            $again = app(LegislatureController::class)->executeMassReseedSweep(
                $ctx['legislature_id'],
                'map_plus_children_unassigned',
                $ctx['root_id'],
                $ctx['map_id'],
                (string) $user->id,
                null,
            );
            // The fully-assigned root reports its composite no-op reason
            // through the errors channel (pre-existing semantics); what the
            // pin demands is that the GIANT is neither re-filed nor errored.
            foreach ($again['errors'] as $err) {
                $this->assertStringNotContainsString('Pin Giant Strip', $err,
                    'a drawn giant must SKIP cleanly on an _unassigned resume');
            }
            $stillTwo = (int) DB::table('district_subdivisions')
                ->where('parent_jurisdiction_id', $ctx['giant_id'])
                ->where('map_id', $ctx['map_id'])
                ->whereNull('deleted_at')
                ->count();
            $this->assertSame(2, $stillTwo, 'resume must not re-file or duplicate the drawn set');
        });
    }

    public function test_a_sweep_without_an_operator_refuses_the_leaf_giant(): void
    {
        $this->onLivePg(function (array $ctx) {
            $result = app(LegislatureController::class)->executeMassReseedSweep(
                $ctx['legislature_id'],
                'map_view_all',
                $ctx['giant_id'],
                $ctx['map_id'],
                null,   // no initiating operator
                null,
            );

            $this->assertCount(1, $result['errors'], 'the leaf giant must fail per-scope');
            $this->assertStringContainsString('operator', $result['errors'][0]);

            $none = (int) DB::table('district_subdivisions')
                ->where('parent_jurisdiction_id', $ctx['giant_id'])
                ->where('map_id', $ctx['map_id'])
                ->whereNull('deleted_at')
                ->count();
            $this->assertSame(0, $none, 'nothing may file through the null-actor system path');
        });
    }

    public function test_districting_template_setting_resolves_with_shortest_fallback(): void
    {
        $this->onLivePg(function (array $ctx) {
            try {
                ConstitutionalDefaults::flush();
                $this->assertSame('shortest', ConstitutionalDefaults::districtingTemplate($ctx['root_id']),
                    'the default of defaults is the compactness-preserving shortest-splitline');

                // Amend the Setup Option (in-transaction, rolled back).
                DB::table('constitutional_settings')->insert([
                    'jurisdiction_id'               => $ctx['root_id'],
                    'districting_autoseed_template' => 'community_cells',
                ]);
                ConstitutionalDefaults::flush();
                $this->assertSame('community_cells', ConstitutionalDefaults::districtingTemplate($ctx['root_id']));

                // An invalid stored value NEVER leaks through — fall back to
                // shortest, not a stringy strip by accident.
                DB::table('constitutional_settings')
                    ->where('jurisdiction_id', $ctx['root_id'])
                    ->update(['districting_autoseed_template' => 'bogus_template']);
                ConstitutionalDefaults::flush();
                $this->assertSame('shortest', ConstitutionalDefaults::districtingTemplate($ctx['root_id']));
            } finally {
                ConstitutionalDefaults::flush();
            }
        });
    }

    public function test_wizard_steps_label_each_scope_by_method(): void
    {
        $this->onLivePg(function (array $ctx) {
            $response = app(LegislatureController::class)->wizardSteps(
                Request::create('/w', 'GET', ['scope_id' => $ctx['root_id']]),
                $ctx['legislature_id']
            );
            $this->assertSame(200, $response->getStatusCode());
            $steps = json_decode($response->getContent(), true)['steps'];

            $byId = collect($steps)->keyBy('scope_id');
            $this->assertTrue($byId->has($ctx['giant_id']), 'the giant is a wizard step');
            $this->assertTrue((bool) $byId[$ctx['giant_id']]['is_leaf'], 'a childless giant is a ✂ line-split step');

            $last = end($steps);
            $this->assertSame($ctx['root_id'], $last['scope_id'], 'root is always the final step');
            $this->assertFalse((bool) $last['is_leaf'], 'the root is never a line-split step');
        });
    }

    // ── fixture ─────────────────────────────────────────────────────────────

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body($this->buildSyntheticContext());
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
            ConstitutionalDefaults::flush();
        }
    }

    /**
     * Pinland (pop 40 000, type_a 34 = round(40000^⅓), quota ≈ 1176):
     *   - 4 composite children (pop 7 000 each → frac 5.95, in band)
     *     as adjacent 0.1°×0.1° squares along the top row;
     *   - 1 CHILDLESS GIANT strip (pop 12 000 → frac 10.2 ≥ 9.5) across the
     *     bottom: [10.0,10.4]×[50.0,50.1];
     *   - a synthetic uniform WorldPop tile over the giant (80×20 pixels of
     *     7.5 people = 12 000 exactly), so the splitline autoseeder's raster
     *     arithmetic runs for real;
     *   - a forming legislature, a draft map, and an ACTIVE bootstrap board
     *     (SeatsBoardUser seats the filing actor on it).
     * Everything inside the caller's transaction — rolled back afterwards.
     */
    private function buildSyntheticContext(): array
    {
        $now = now();
        // Each jurisdiction is one or more envelope rects — a MULTI-rect
        // geometry is a NON-CONTIGUOUS territory (the LA-County islands
        // shape: mainland + detached components).
        $mkJur = function (string $name, ?string $parentId, int $admLevel, int $pop, array $rects) use ($now): string {
            $id = (string) Str::uuid();
            $envelopes = implode(', ', array_map(
                fn (array $r) => sprintf('ST_MakeEnvelope(%F, %F, %F, %F, 4326)', ...$r),
                $rects
            ));
            DB::insert(
                "INSERT INTO jurisdictions
                    (id, name, slug, iso_code, adm_level, parent_id, population, is_active, is_civic_active,
                     source, official_languages, timezone, geom, centroid, created_at, updated_at)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?, true, true, 'pin-fixture', '[]', 'UTC',
                     ST_Multi(ST_Union(ARRAY[{$envelopes}])),
                     ST_Centroid(ST_Union(ARRAY[{$envelopes}])), ?, ?)",
                [
                    $id, $name, 'zzp-'.$admLevel.'-'.Str::slug($name).'-'.substr($id, 0, 8), self::ISO,
                    $admLevel, $parentId, $pop,
                    $now, $now,
                ]
            );

            return $id;
        };

        // Root STORED population is deliberately NOISY (43 000) while the
        // children sum to 40 000 — the USA-style geodata drift (342.35M stored
        // vs 346.04M children-sum) that manufactured the phantom-giant
        // Kentucky. Every share in the system must divide by the CHILDREN-SUM
        // (seating law step 2); on the old stored base the giant's share reads
        // 12000×34/43000 = 9.49 < 9.5 and every giant test goes blind.
        $rootId = $mkJur('Pinland', null, 1, 43000, [[10.0, 50.0, 10.4, 50.2], [10.05, 49.90, 10.15, 49.95]]);
        $compositeIds = [];
        foreach (range(0, 3) as $i) {
            $x = 10.0 + $i * 0.1;
            $compositeIds[] = $mkJur('Pin Quarter '.($i + 1), $rootId, 2, 7000, [[$x, 50.1, $x + 0.1, 50.2]]);
        }
        // The giant is NON-CONTIGUOUS: the mainland strip PLUS a detached
        // island to the southwest (the Santa-Catalina shape) — the island must
        // RIDE WHOLE with the blade side its position dictates, never strand.
        $giantId = $mkJur('Pin Giant Strip', $rootId, 2, 12000, [[10.0, 50.0, 10.4, 50.1], [10.05, 49.90, 10.15, 49.95]]);

        $legislatureId = (string) Str::uuid();
        DB::table('legislatures')->insert([
            'id'              => $legislatureId,
            'jurisdiction_id' => $rootId,
            'term_number'     => 1,
            'status'          => 'forming',
            'total_seats'     => 34,
            'type_a_seats'    => 34,
            'type_b_seats'    => 0,
            'quorum_required' => 18,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $mapId = (string) Str::uuid();
        DB::table('legislature_district_maps')->insert([
            'id'             => $mapId,
            'legislature_id' => $legislatureId,
            'name'           => 'mixed autoseed pin',
            'status'         => 'draft',
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        DB::table('election_boards')->insert([
            'id'              => (string) Str::uuid(),
            'jurisdiction_id' => $rootId,
            'legislature_id'  => $legislatureId,
            'is_bootstrap'    => true,
            'status'          => 'active',
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        // Synthetic WorldPop tiles: the mainland strip (80×20 cells of 0.005°,
        // upper-left (10.0, 50.1), 7.3125 each → 11 700) plus the island
        // (20×10 cells, upper-left (10.05, 49.95), 1.5 each → 300) — exactly
        // 12 000 people in the giant, 300 of them riding the island.
        DB::insert(
            "INSERT INTO worldpop_rasters (id, iso_code, year, resolution_m, rast, created_at)
             VALUES (?, ?, 2023, 100,
                     ST_AddBand(ST_MakeEmptyRaster(80, 20, 10.0, 50.1, 0.005, -0.005, 0, 0, 4326),
                                '32BF'::text, 7.3125, NULL),
                     ?)",
            [(string) Str::uuid(), self::ISO, $now]
        );
        DB::insert(
            "INSERT INTO worldpop_rasters (id, iso_code, year, resolution_m, rast, created_at)
             VALUES (?, ?, 2023, 100,
                     ST_AddBand(ST_MakeEmptyRaster(20, 10, 10.05, 49.95, 0.005, -0.005, 0, 0, 4326),
                                '32BF'::text, 1.5, NULL),
                     ?)",
            [(string) Str::uuid(), self::ISO, $now]
        );

        return [
            'root_id'        => $rootId,
            'composite_ids'  => $compositeIds,
            'giant_id'       => $giantId,
            'legislature_id' => $legislatureId,
            'map_id'         => $mapId,
        ];
    }
}
