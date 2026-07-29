<?php

namespace Tests\Feature;

use App\Models\Candidacy;
use App\Models\Election;
use App\Models\SocialFollow;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Wave 2, lane 15 — THE PUBLIC PERSON PROFILE (the ?who= page; v3.2 ruling
 * 0a "one person = one profile") and its rails:
 *
 *  - Art. I pseudonymity end-to-end: the subject's LEGAL name (users.name)
 *    never leaves the server on this page — no display choice, no legal
 *    fallback, unlike the governance surfaces.
 *  - The candidate-profile absorption: /candidates/{candidacy} (every
 *    count-page profile_href) 302s onto the Candidacy tab; the mockup-side
 *    ?candidate= contract forwards the same way.
 *  - "Your choice to show": bio/handle/achievements render for others ONLY
 *    when the subject's social profile says visibility=public. Visibility
 *    is a preference, never a rights gate.
 *  - Follows are LOCAL-ONLY private rows — unique per pair, restore on
 *    re-follow, and never a row anyone else can read off this page.
 *
 * JourneysTest live-pg posture: real Postgres, one rolled-back
 * transaction, SKIP when pg is unreachable (run inside the app container).
 */
class PersonProfileTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_person_profile';

    public function test_a_guest_can_read_a_public_profile_by_uuid(): void
    {
        $this->onLivePg(function () {
            $subject = $this->aUser('Person Profile Subject');

            $this->get('/people?who='.$subject->id)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Social/PersonProfile')
                    ->where('tab', 'overview')
                    ->where('isSelf', false)
                    ->where('person.id', (string) $subject->id)
                    ->where('follow.canFollow', false)
                    // No social profile, no display choice → the stable
                    // non-PII pseudonym.
                    ->where('person.display', 'Resident-'.substr(hash('sha256', (string) $subject->id), 0, 8)));
        });
    }

    public function test_the_legal_name_never_leaves_the_server(): void
    {
        $this->onLivePg(function () {
            $subject = $this->aUser('Zebulon Nightingale-Quixote');

            $response = $this->get('/people?who='.$subject->id)->assertOk();

            // Art. I: no fragment of the legal name anywhere in the page —
            // props, HTML, anything. The governance-plane display fallback
            // (display_name ?: name) does not exist on this surface.
            $this->assertStringNotContainsString('Zebulon', $response->getContent());
            $this->assertStringNotContainsString('Nightingale-Quixote', $response->getContent());
        });
    }

    public function test_handle_resolution_and_the_visibility_choice(): void
    {
        $this->onLivePg(function () {
            $subject = $this->aUser('Visible Person');
            SocialProfile::create([
                'user_id' => (string) $subject->id,
                'handle' => 'visible-'.substr((string) $subject->id, 0, 8),
                'display_name' => 'The Visible One',
                'bio' => 'A public bio.',
                'visibility' => SocialProfile::VISIBILITY_PUBLIC,
            ]);

            // ?who= accepts the handle (case-insensitive, with or without @).
            $this->get('/people?who=@VISIBLE-'.substr((string) $subject->id, 0, 8))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('person.id', (string) $subject->id)
                    ->where('person.display', 'The Visible One')
                    ->where('person.bio', 'A public bio.')
                    ->where('achievements', []));

            // Flip the choice to private: bio and achievements leave the
            // public view; the page itself stays reachable (never a gate).
            SocialProfile::query()->where('user_id', (string) $subject->id)
                ->update(['visibility' => SocialProfile::VISIBILITY_PRIVATE]);

            $this->get('/people?who='.$subject->id)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('person.bio', null)
                    ->where('person.handle', null)
                    ->where('person.followCounts', null)
                    ->where('achievements', null));
        });
    }

    public function test_the_old_candidate_route_lands_on_the_candidacy_tab(): void
    {
        $this->onLivePg(function () {
            [$subject, $candidacy] = $this->aCandidacy();

            // The app-side absorption: every count/ballot page profile_href.
            $this->get('/candidates/'.$candidacy->id)
                ->assertRedirect('/people?who='.$subject->id.'&tab=candidacy&candidacy='.$candidacy->id);

            // The mockup-contract forward: ?candidate= → ?who=&tab=candidacy.
            $this->get('/people?candidate='.$candidacy->id)
                ->assertRedirect('/people?who='.$subject->id.'&tab=candidacy&candidacy='.$candidacy->id);

            // And the landing renders the absorbed panel.
            $this->get('/people?who='.$subject->id.'&tab=candidacy&candidacy='.$candidacy->id)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Social/PersonProfile')
                    ->where('tab', 'candidacy')
                    ->where('candidacyPanel.candidacy.id', (string) $candidacy->id)
                    ->where('candidacyPanel.isOwner', false)
                    ->where('candidacyPanel.currentState', Candidacy::STATUS_REGISTERED));
        });
    }

    public function test_follow_round_trip_stays_one_local_row(): void
    {
        $this->onLivePg(function () {
            $viewer = $this->aUser('Follower');
            $subject = $this->aUser('Followed');

            $follow = fn () => $this->actingAs($viewer)->post('/people/'.$subject->id.'/follow');

            $follow()->assertRedirect();
            $follow()->assertRedirect(); // idempotent — restore, not duplicate

            $rows = fn () => SocialFollow::withTrashed()
                ->where('follower_user_id', (string) $viewer->id)
                ->where('target_type', SocialFollow::TARGET_USER)
                ->where('target_id', (string) $subject->id);

            $this->assertSame(1, $rows()->count(), 'one row per pair, ever');
            $this->assertNull($rows()->first()->deleted_at);

            $this->actingAs($viewer)->delete('/people/'.$subject->id.'/follow')->assertRedirect();
            $this->assertNotNull($rows()->withTrashed()->first()->deleted_at, 'unfollow soft-deletes');

            $follow()->assertRedirect();
            $this->assertSame(1, $rows()->count(), 're-follow restores the same row');

            // LOCAL-ONLY: the act never touched the audit chain.
            $this->assertSame(
                0,
                DB::table('audit_log')->where('actor_user_id', (string) $viewer->id)->count(),
                'a follow is a private note — never audited'
            );

            // The viewer's follow state reaches the page; following yourself refuses.
            $this->actingAs($viewer)->get('/people?who='.$subject->id)
                ->assertInertia(fn (Assert $page) => $page->where('follow.isFollowing', true));
            $this->actingAs($viewer)->post('/people/'.$viewer->id.'/follow')->assertStatus(422);
        });
    }

    public function test_no_subject_routes_home(): void
    {
        $this->onLivePg(function () {
            // Guest first — auth state persists within a test once actingAs runs.
            $this->get('/people')->assertRedirect(route('login'));

            $viewer = $this->aUser('No Subject');

            $this->actingAs($viewer)->get('/people')
                ->assertRedirect('/people?who='.$viewer->id);
        });
    }

    // ── helpers (the JourneysTest live-pg posture) ───────────────────────────

    /** @return array{0: User, 1: Candidacy} */
    private function aCandidacy(): array
    {
        $subject = $this->aUser('Standing Person');

        $jurisdictionId = (string) DB::table('jurisdictions')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->value('id');

        $this->assertNotSame('', $jurisdictionId, 'the live DB has at least one jurisdiction');

        $election = Election::create([
            'jurisdiction_id' => $jurisdictionId,
            'kind' => Election::KIND_GENERAL,
            'status' => Election::STATUS_APPROVAL_OPEN,
            'trigger' => 'manual',
            'voting_method' => 'stv_droop',
        ]);

        $candidacy = Candidacy::create([
            'election_id' => $election->id,
            'user_id' => $subject->id,
            'status' => Candidacy::STATUS_REGISTERED,
            'residency_attested_at' => now(),
        ]);

        return [$subject, $candidacy];
    }

    private function aUser(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => 'person-profile-'.Str::uuid().'@test.invalid',
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
