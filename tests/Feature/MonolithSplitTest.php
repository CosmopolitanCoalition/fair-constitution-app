<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * mockups-v3-wiring Phase 3e — the two monolith retirements.
 *
 * Legislature/Show (the 5.2k-line monolith) split in two: the overview stays
 * at /legislatures/{slug} (Legislature/Show, v2 shell, NO mapper props) and
 * the ENTIRE district mapper moved verbatim to /legislatures/{slug}/districts
 * (Legislature/Districts, exactly the props the mapper consumed). Pre-split
 * mapper deep links (?scope/?map/?setup/?compare) forward to the districts
 * surface with the query preserved. The jurisdiction viewer keeps rendering
 * by slug on its reshaped (wide v2) shell.
 *
 * The JourneysTest posture: DB-backed paths run on the guarded live-pg
 * connection (never RefreshDatabase — the live dev DB is not disposable);
 * everything inside one rolled-back transaction; SKIPS when pg is
 * unreachable — run inside the app container. The legislature under test is
 * resolved at runtime (never a hardcoded UUID); when the DB has none, a
 * minimal row pair is created inside the transaction.
 */
class MonolithSplitTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_monolith_split';

    public function test_the_legislature_overview_renders_without_the_mapper_props(): void
    {
        $this->onLivePg(function () {
            $leg = $this->resolveLegislature();

            $this->get("/legislatures/{$leg->slug}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Legislature/Show')
                    ->where('surface.id', 'legislature/overview')
                    ->where('legislature.slug', $leg->slug)
                    ->where('districtsHref', "/legislatures/{$leg->slug}/districts")
                    ->has('legislature.type_a_seats')
                    ->has('members')
                    ->has('maps.total')
                    // The mapper's prop surface is GONE from the overview —
                    // the split's designed shape change.
                    ->missing('children')
                    ->missing('districts')
                    ->missing('scope')
                    ->missing('quota')
                    ->missing('flags')
                    ->missing('stats')
                    ->missing('active_map'));
        });
    }

    public function test_the_districts_surface_carries_exactly_the_mapper_props(): void
    {
        $this->onLivePg(function () {
            $leg = $this->resolveLegislature();

            $this->get("/legislatures/{$leg->slug}/districts")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Legislature/Districts')
                    ->where('surface.id', 'legislature/districts')
                    ->where('legislature.slug', $leg->slug)
                    ->where('scope.id', $leg->jurisdiction_id)
                    ->has('scope.bbox')
                    ->has('ancestors')
                    ->has('children')
                    ->has('districts')
                    ->has('quota')
                    ->has('flags')
                    ->has('maps')
                    ->has('constitutional.floor')
                    ->has('constitutional.ceiling')
                    ->has('constitutional.giant_threshold')
                    ->where('setup_mode', false));
        });
    }

    public function test_pre_split_mapper_deep_links_forward_to_the_districts_surface(): void
    {
        $this->onLivePg(function () {
            $leg = $this->resolveLegislature();

            // The setup wizard's ?setup=1 handoff (Setup/Step3_Districts.vue).
            $this->get("/legislatures/{$leg->slug}?setup=1")
                ->assertRedirect("/legislatures/{$leg->slug}/districts?setup=1");

            // A bookmarked drill-down (?scope=) forwards too.
            $this->get("/legislatures/{$leg->slug}?scope={$leg->slug}")
                ->assertRedirect("/legislatures/{$leg->slug}/districts?scope={$leg->slug}");
        });
    }

    public function test_uuid_arrivals_canonicalize_to_the_slug_on_both_surfaces(): void
    {
        $this->onLivePg(function () {
            $leg = $this->resolveLegislature();

            $this->get("/legislatures/{$leg->id}")
                ->assertRedirect("/legislatures/{$leg->slug}");

            $this->get("/legislatures/{$leg->id}/districts")
                ->assertRedirect("/legislatures/{$leg->slug}/districts");
        });
    }

    public function test_the_jurisdiction_viewer_still_renders_by_slug(): void
    {
        $this->onLivePg(function () {
            $leg = $this->resolveLegislature();

            $this->get("/jurisdictions/{$leg->slug}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Jurisdictions/Show')
                    ->where('surface.id', 'jurisdictions/viewer')
                    ->where('jurisdiction.slug', $leg->slug)
                    ->has('ancestors')
                    ->has('childCount')
                    ->has('activation')
                    ->has('map_acceptance.is_planet_scope'));
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * A live legislature + its root jurisdiction, resolved at RUNTIME (DB
     * resets change every UUID). Prefers the shallowest root (the planet
     * legislature when seeded); falls back to minimal in-transaction rows on
     * an empty database.
     *
     * @return object{id: string, jurisdiction_id: string, slug: string}
     */
    private function resolveLegislature(): object
    {
        $row = DB::table('legislatures as l')
            ->join('jurisdictions as j', 'j.id', '=', 'l.jurisdiction_id')
            ->whereNull('l.deleted_at')
            ->whereNull('j.deleted_at')
            ->whereNotNull('j.slug')
            ->orderBy('j.adm_level')
            ->orderBy('l.created_at')
            ->first(['l.id', 'l.jurisdiction_id', 'j.slug']);

        if ($row !== null) {
            return $row;
        }

        $jurisdictionId = (string) Str::uuid();
        $slug = 'monolith-split-land-' . substr((string) Str::uuid(), 0, 8);
        DB::table('jurisdictions')->insert([
            'id'         => $jurisdictionId,
            'name'       => 'Monolith Split Land',
            'slug'       => $slug,
            'adm_level'  => 0,
            'population' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legislatureId = (string) Str::uuid();
        DB::table('legislatures')->insert([
            'id'              => $legislatureId,
            'jurisdiction_id' => $jurisdictionId,
            'status'          => 'forming',
            'type_a_seats'    => 5,
            'type_b_seats'    => 0,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return (object) [
            'id'              => $legislatureId,
            'jurisdiction_id' => $jurisdictionId,
            'slug'            => $slug,
        ];
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
