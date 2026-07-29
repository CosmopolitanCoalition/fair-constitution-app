<?php

namespace Tests\Feature;

use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Wave 4, lane 4 — the Atlas: the whole world on one read-only public screen
 * (ATLAS_DESIGN.md). The wire, not the rails: the pins for "a gauge, never a
 * lever" live in tests/Constitutional/AtlasGaugeNeverLeverTest.
 *
 * What this proves:
 *  - A GUEST reads it. Watching the world is a citizen's business, so the
 *    surface is public and never operator-walled.
 *  - THE SUPPRESSION RAIL, at the prop level: a figure the rollup does not
 *    carry arrives as NULL, never as 0. This is why the Atlas is honest before
 *    its nightly rollup has ever run — a world we have not measured is not a
 *    world of zeros.
 *  - A suppressed legitimacy snapshot yields NO reach number. Suppression is
 *    the whole point of the snapshot; a surface that recovered the figure by
 *    the back door would defeat it.
 *  - The map plots from the PostGIS centroid, and never plots the planet as a
 *    dot on its own map.
 *
 * JourneysTest live-pg posture: real Postgres, one rolled-back transaction,
 * SKIP when pg is unreachable (run inside the app container).
 */
class AtlasPageTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_atlas_page';

    public function test_a_guest_reads_the_whole_world_on_one_public_screen(): void
    {
        $this->onLivePg(function () {
            $this->get('/atlas')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('System/Atlas')
                    ->has('surface')
                    ->has('hero')
                    ->has('world')
                    ->has('reach')
                    ->has('representation')
                    ->has('executive')
                    ->has('judiciary')
                    ->has('organizations')
                    ->has('economy')
                    ->has('people')
                    ->has('mesh')
                    ->has('map')
                    ->has('trends')
                    ->has('privacy'));
        });
    }

    /**
     * The economy card is RULED `planned` (operator 2026-07-29, Atlas §8 Q4
     * option (a)) until lane 13's services land, so the rollup carries no
     * economy domain. It must therefore arrive EMPTY — not as a set of zeros
     * that would read as "the economy minted nothing".
     */
    public function test_a_domain_the_rollup_does_not_carry_is_empty_never_zero(): void
    {
        $this->onLivePg(function () {
            $this->get('/atlas')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('economy', [])
                    ->where('people', []));
        });
    }

    /**
     * A withheld night is a GAP. `ratio_micro` is NULL on a suppressed snapshot
     * (a database CHECK constraint enforces it), and that null must survive all
     * the way to the prop — the page renders it as an em-dash.
     */
    public function test_a_suppressed_snapshot_yields_no_reach_number(): void
    {
        $this->onLivePg(function () {
            $place = DB::table('jurisdictions')
                ->whereNull('deleted_at')
                ->orderBy('adm_level')
                ->first(['id', 'name']);

            $this->assertNotNull($place, 'the live box carries no jurisdictions to gauge');

            // A suppressed night for this place, newer than anything real, so it
            // is the row the controller reads. verified/ratio NULL is the shape
            // legitimacy_snapshots_suppressed_has_no_count_check requires.
            DB::table('legitimacy_snapshots')->insert([
                'jurisdiction_id' => $place->id,
                'as_of_date' => '2099-01-01',
                'verified_residents' => null,
                'population_estimate' => 1000,
                'ratio_micro' => null,
                'state' => 'activating',
                'suppressed' => true,
                'population_provenance' => 'test',
                'population_year' => 2024,
                'source_server_id' => null,
            ]);

            $this->get('/atlas?place='.$place->id)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('reach.home.state', 'activating')
                    // The rail: no number, and NOT a zero.
                    ->where('reach.home.reachPct', null));
        });
    }

    /**
     * The map reads the PostGIS `centroid` (SRID 4326 — ST_X is longitude,
     * ST_Y is latitude), and the planet is never a dot on its own map.
     */
    public function test_the_map_plots_places_from_the_centroid_and_never_the_planet(): void
    {
        $this->onLivePg(function () {
            $response = $this->get('/atlas')->assertOk();

            $places = $response->viewData('page')['props']['map']['places'];

            $this->assertNotEmpty($places, 'the live box carries no place centroids to plot');

            foreach ($places as $p) {
                $this->assertGreaterThanOrEqual(1, $p['tier'], 'adm_level 0 is the planet — it is not a dot on its own map');
                $this->assertGreaterThanOrEqual(-90, $p['lat']);
                $this->assertLessThanOrEqual(90, $p['lat']);
                $this->assertGreaterThanOrEqual(-180, $p['lng']);
                $this->assertLessThanOrEqual(180, $p['lng']);
            }
        });
    }

    /** The AchievementsPageTest posture: live pg, set as default, always rolled back. */
    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        app(RoleService::class)->flush();
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
            app(RoleService::class)->flush();
        }
    }
}
