<?php

namespace Tests\Feature;

use App\Models\Jurisdiction;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\ResidencyConfirmation;
use App\Models\User;
use App\Services\RepresentativesResolver;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * /civic/record — the ONE person page (mockups-v3-wiring Phase 2, the
 * profile-v2.js contract): the unified tabbed profile with server-validated
 * ?tab= plus the representatives/candidacies props.
 *
 * The SupportReportTest posture: DB-backed paths run on the guarded live-pg
 * connection (never RefreshDatabase — the live dev DB is not disposable);
 * SKIPS when pg is unreachable — run inside the app container.
 */
class MyProfileTabsTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_my_profile_tabs';

    public function test_the_profile_page_renders_with_the_phase_2_props(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('Profile Owner');

            $this->actingAs($user)
                ->get('/civic/record')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Civic/MyRecord')
                    ->where('tab', 'overview')
                    ->has('representatives')
                    ->has('candidacies')
                    ->has('entries')
                    ->has('associations')
                    ->has('stats')
                    ->has('profile'));
        });
    }

    public function test_a_valid_tab_param_is_reflected(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('Tab Switcher');

            $this->actingAs($user)
                ->get('/civic/record?tab=representatives')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Civic/MyRecord')
                    ->where('tab', 'representatives'));
        });
    }

    public function test_an_invalid_tab_falls_back_to_overview(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('Tab Fuzzer');

            $this->actingAs($user)
                ->get('/civic/record?tab=office-of-the-president')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Civic/MyRecord')
                    ->where('tab', 'overview'));
        });
    }

    public function test_a_fresh_user_has_no_representatives_or_candidacies(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('Fresh Player');

            $this->actingAs($user)
                ->get('/civic/record')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Civic/MyRecord')
                    ->where('representatives', [])
                    ->where('candidacies', []));
        });
    }

    public function test_representatives_resolve_through_the_residency_chain(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('Represented Resident');

            // Jurisdictions with NO existing legislature (the live DB carries
            // demo chambers) — one local (deeper adm_level) and one enclosing.
            $used  = Legislature::query()->pluck('jurisdiction_id')->all();
            $local = Jurisdiction::query()->whereNotIn('id', $used)->where('adm_level', 2)->orderBy('id')->first();
            $upper = Jurisdiction::query()->whereNotIn('id', $used)->where('adm_level', 1)->orderBy('id')->first();
            $other = Jurisdiction::query()->whereNotIn('id', $used)->where('adm_level', 1)->orderBy('id')->skip(1)->first();

            $this->assertNotNull($local, 'live DB has an adm2 jurisdiction without a legislature');
            $this->assertNotNull($upper, 'live DB has an adm1 jurisdiction without a legislature');
            $this->assertNotNull($other, 'live DB has a second adm1 jurisdiction without a legislature');

            foreach ([$local, $upper, $other] as $depth => $jurisdiction) {
                ResidencyConfirmation::create([
                    'id'              => (string) Str::uuid(),
                    'user_id'         => (string) $user->id,
                    'jurisdiction_id' => (string) $jurisdiction->id,
                    'depth'           => $depth,
                    'days_confirmed'  => 30,
                    'confirmed_at'    => now(),
                    'is_active'       => true,
                ]);
            }

            $localLeg   = Legislature::create(['jurisdiction_id' => (string) $local->id, 'status' => Legislature::STATUS_ACTIVE]);
            $upperLeg   = Legislature::create(['jurisdiction_id' => (string) $upper->id, 'status' => Legislature::STATUS_ACTIVE]);
            $formingLeg = Legislature::create(['jurisdiction_id' => (string) $other->id, 'status' => Legislature::STATUS_FORMING]);

            $speaker = $this->aUser('Local Speaker');
            $atLarge = $this->aUser('Upper At-Large');
            $ghost   = $this->aUser('Forming Ghost');

            LegislatureMember::create([
                'legislature_id' => (string) $localLeg->id,
                'user_id'        => (string) $speaker->id,
                'seat_type'      => 'a',
                'seat_no'        => 1,
                'status'         => LegislatureMember::STATUS_SEATED,
                'is_speaker'     => true,
                'term_ends_on'   => now()->addYears(5)->toDateString(),
            ]);
            LegislatureMember::create([
                'legislature_id' => (string) $upperLeg->id,
                'user_id'        => (string) $atLarge->id,
                'seat_type'      => 'b',
                'seat_no'        => 3,
                'status'         => LegislatureMember::STATUS_ELECTED,
            ]);
            // A forming chamber never yields representatives.
            LegislatureMember::create([
                'legislature_id' => (string) $formingLeg->id,
                'user_id'        => (string) $ghost->id,
                'seat_type'      => 'a',
                'seat_no'        => 1,
                'status'         => LegislatureMember::STATUS_SEATED,
            ]);

            $rows = app(RepresentativesResolver::class)->forUser($user);

            $this->assertCount(2, $rows, 'active chambers only — the forming one is excluded');

            // Most-local first: adm_level DESCENDING.
            $this->assertSame('Local Speaker', $rows[0]['name']);
            $this->assertSame(2, $rows[0]['jurisdiction']['adm_level']);
            $this->assertTrue($rows[0]['is_speaker']);
            $this->assertSame(1, $rows[0]['seat_no']);
            $this->assertSame('a', $rows[0]['seat_type']);
            $this->assertSame((string) $localLeg->id, $rows[0]['legislature_id']);
            $this->assertSame($local->name, $rows[0]['jurisdiction']['name']);
            $this->assertNotNull($rows[0]['term_ends_on']);

            $this->assertSame('Upper At-Large', $rows[1]['name']);
            $this->assertSame(1, $rows[1]['jurisdiction']['adm_level']);
            $this->assertFalse($rows[1]['is_speaker']);
            $this->assertSame('b', $rows[1]['seat_type']);

            // And the endpoint carries the same rows onto the profile.
            $this->actingAs($user)
                ->get('/civic/record?tab=representatives')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Civic/MyRecord')
                    ->where('tab', 'representatives')
                    ->has('representatives', 2)
                    ->where('representatives.0.name', 'Local Speaker')
                    ->where('representatives.1.name', 'Upper At-Large'));
        });
    }

    // ── helpers (the SupportReportTest live-pg posture) ──────────────────────

    private function aUser(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => 'profile-tabs-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }

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
