<?php

namespace Tests\Feature;

use App\Domain\Achievements\AchievementCatalog;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Wave 2, lane 15 — the achievements page: the full catalog over the
 * sealed append-only ledger, and the rails that constrain it:
 *
 *  - PI-6, structurally: the page props carry LISTS and per-entry earned
 *    marks — never a total, percentage, rank or "N of M". The absence of
 *    a number is the rail.
 *  - The catalog serialization is derived from AchievementCatalog (never
 *    a hardcoded count — the catalog is content and grows).
 *  - Tour arcs are labeled walkthroughs (TIER_TOUR), so they can never
 *    borrow the verified tier's credibility.
 *  - Jurisdiction/system entries carry no user and no earned overlay.
 *  - A guest browses the same catalog; earned marks are the signed-in
 *    viewer's own ledger rows only.
 *
 * JourneysTest live-pg posture: real Postgres, one rolled-back
 * transaction, SKIP when pg is unreachable (run inside the app container).
 */
class AchievementsPageTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_achievements_page';

    /** A live journey arc (config/cga/journeys.php) for the earned-overlay case. */
    private const LIVE_JOURNEY = 'form-a-group';

    public function test_a_guest_browses_the_full_catalog_with_no_earned_overlay(): void
    {
        $this->onLivePg(function () {
            $this->get('/achievements')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Social/Achievements')
                    ->where('signedIn', false)
                    // Derived from the catalog, never hardcoded.
                    ->has('catalog.personal', count(AchievementCatalog::inScope(AchievementCatalog::SCOPE_PERSONAL)))
                    ->has('catalog.jurisdiction', count(AchievementCatalog::inScope(AchievementCatalog::SCOPE_JURISDICTION)))
                    ->has('catalog.system', count(AchievementCatalog::inScope(AchievementCatalog::SCOPE_SYSTEM)))
                    ->where('earned', []));
        });
    }

    public function test_no_composite_score_ever_ships(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('Score-Free Reader');

            $this->actingAs($user)
                ->get('/achievements')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    // PI-6: no tally-shaped prop exists, under any name.
                    ->missing('totals')
                    ->missing('progress')
                    ->missing('score')
                    ->missing('counts')
                    ->missing('completion'));
        });
    }

    public function test_completing_a_journey_marks_exactly_that_entry(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('Decorated Browser');
            $steps = count(config('cga.journeys.'.self::LIVE_JOURNEY.'.steps'));

            foreach (range(0, $steps - 1) as $step) {
                $this->actingAs($user)
                    ->post('/journeys/'.self::LIVE_JOURNEY.'/steps', ['step' => $step])
                    ->assertRedirect();
            }

            $this->actingAs($user)
                ->get('/achievements')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('signedIn', true)
                    ->has('earned.'.self::LIVE_JOURNEY.'.earned_at')
                    // One completion → one mark. The earned map holds exactly
                    // the ledger rows, nothing derived.
                    ->has('earned', 1));
        });
    }

    public function test_tour_arcs_are_labeled_walkthroughs_and_verified_entries_carry_their_proof(): void
    {
        $this->onLivePg(function () {
            $this->get('/achievements')
                ->assertOk()
                ->assertInertia(function (Assert $page) {
                    $personal = $page->toArray()['props']['catalog']['personal'];

                    $arcs = array_values(array_filter($personal, fn ($e) => $e['is_arc']));
                    $verified = array_values(array_filter($personal, fn ($e) => ! $e['is_arc']));

                    // Every arc in the journey registry appears, tier 'tour'.
                    $this->assertCount(count(config('cga.journeys')), $arcs);
                    foreach ($arcs as $arc) {
                        $this->assertSame(AchievementCatalog::TIER_TOUR, $arc['tier']);
                    }

                    // Every verified entry names its proving source.
                    foreach ($verified as $entry) {
                        $this->assertSame(AchievementCatalog::TIER_VERIFIED, $entry['tier']);
                        $this->assertNotSame('', trim($entry['trigger']));
                    }

                    $page->component('Social/Achievements');
                });
        });
    }

    // ── helpers (the JourneysTest live-pg posture) ───────────────────────────

    private function aUser(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => 'achievements-page-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }

    private function onLivePg(callable $body): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

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
