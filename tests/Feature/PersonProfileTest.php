<?php

namespace Tests\Feature;

use App\Models\Candidacy;
use App\Models\Election;
use App\Models\MatrixRoom;
use App\Models\SocialFollow;
use App\Models\SocialMembership;
use App\Models\SocialProfile;
use App\Models\SocialSpace;
use App\Models\User;
use App\Services\Matrix\MatrixRoomCreationService;
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
            $handle = 'visible-'.substr((string) $subject->id, 0, 8);
            SocialProfile::create([
                'user_id' => (string) $subject->id,
                'handle' => $handle,
                'display_name' => 'The Visible One',
                'bio' => 'A public bio.',
                'visibility' => SocialProfile::VISIBILITY_PUBLIC,
            ]);

            // ?who= accepts the handle (case-insensitive, with or without @).
            $this->get('/people?who=@'.strtoupper($handle))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('person.id', (string) $subject->id)
                    ->where('person.display', 'The Visible One')
                    ->where('person.bio', 'A public bio.')
                    ->where('achievements', []));

            // Flip the choice to private: bio, achievements, AND the named
            // home chain leave the public view; the page itself stays
            // reachable by uuid (never a gate) —
            SocialProfile::query()->where('user_id', (string) $subject->id)
                ->update(['visibility' => SocialProfile::VISIBILITY_PRIVATE]);

            $this->get('/people?who='.$subject->id)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('person.bio', null)
                    ->where('person.handle', null)
                    ->where('person.followCounts', null)
                    ->where('person.home', null)
                    ->where('record.associations', [])
                    ->where('achievements', null));

            // — but the HIDDEN handle no longer resolves: confirming the
            // handle→person mapping is itself the leak the choice hides.
            $this->get('/people?who=@'.$handle)->assertNotFound();
        });
    }

    public function test_array_query_params_never_crash_the_public_route(): void
    {
        $this->onLivePg(function () {
            // Bracketed params arrive as arrays; each cast site must treat
            // them as absent, not 500 (guest-triggerable on a public route).
            $this->get('/people?who[]=x')->assertRedirect(route('login'));
            $this->get('/people?candidate[]=x')->assertRedirect(route('login'));

            [$subject, $candidacy] = $this->aCandidacy();
            $this->get('/people?who='.$subject->id.'&tab=candidacy&candidacy[]=x')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('candidacyPanel.candidacy.id', (string) $candidacy->id));
        });
    }

    public function test_the_candidacy_registration_page_still_loads(): void
    {
        $this->onLivePg(function () {
            // Regression pin for the import sweep: CandidacyController kept
            // officesFor(User ...) after the show() port, and the User
            // import must survive any future cleanup — this GET had zero
            // coverage when the 3f81290 cleanup broke it.
            [$subject, $candidacy] = $this->aCandidacy();

            $this->actingAs($subject)
                ->get('/elections/'.$candidacy->election_id.'/candidacy')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Elections/CandidacyRegistration'));
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

    public function test_the_self_edit_door_files_f_ind_002_and_withholds_social_values(): void
    {
        $this->onLivePg(function () {
            // Guest: the self-edit door is auth-gated.
            $this->post('/people/profile', ['display_name' => 'x'])->assertRedirect(route('login'));

            $user = $this->aUser('Editor Person');
            $secretBio = 'A distinctive private bio zzq-'.substr((string) $user->id, 0, 6);
            $handle = 'editor-'.substr((string) $user->id, 0, 8);

            $this->actingAs($user)->post('/people/profile', [
                'display_name' => 'Edited Display',
                'handle' => $handle,
                'bio' => $secretBio,
                'visibility' => 'private',
            ])->assertRedirect();

            // The user row AND the social profile both applied.
            $this->assertSame('Edited Display', $user->fresh()->display_name);
            $profile = SocialProfile::query()->where('user_id', (string) $user->id)->first();
            $this->assertNotNull($profile);
            $this->assertSame($handle, $profile->handle);
            $this->assertSame($secretBio, $profile->bio);
            $this->assertSame(SocialProfile::VISIBILITY_PRIVATE, $profile->visibility);

            // The chain records the ACT — but NOT the social values. A chained
            // handle history is a de-anon vector; a private bio is private.
            $payload = (string) DB::table('audit_log')
                ->where('ref', 'F-IND-002')->where('rejected', false)
                ->where('actor_user_id', (string) $user->id)
                ->orderByDesc('seq')->value('payload');

            $this->assertNotSame('', $payload, 'the edit filed a F-IND-002 chain entry');
            $this->assertStringNotContainsString($secretBio, $payload, 'the bio value must never reach the public chain');
            $this->assertStringNotContainsString($handle, $payload, 'the handle value must never reach the public chain');

            $decoded = json_decode($payload, true);
            $this->assertContains('handle', $decoded['changed_fields'] ?? []);
            $this->assertContains('bio', $decoded['changed_fields'] ?? []);
            // display_name is the chosen public pseudonym — its value may chain, as today.
            $this->assertSame('Edited Display', $decoded['changes']['display_name'] ?? null);
        });
    }

    public function test_a_duplicate_handle_is_refused_case_insensitively(): void
    {
        $this->onLivePg(function () {
            $a = $this->aUser('Handle A');
            $b = $this->aUser('Handle B');
            $handle = 'shared-'.substr((string) $a->id, 0, 8);

            SocialProfile::create([
                'user_id' => (string) $a->id, 'handle' => $handle,
                'visibility' => SocialProfile::VISIBILITY_PUBLIC,
            ]);

            // Upper-cased attempt still collides (we store + compare lower).
            $this->actingAs($b)->post('/people/profile', ['handle' => strtoupper($handle)])
                ->assertSessionHasErrors('handle');

            $this->assertNull(SocialProfile::query()->where('user_id', (string) $b->id)->value('handle'));
        });
    }

    public function test_message_opens_a_two_person_dm_idempotently(): void
    {
        $this->onLivePg(function () {
            // Keep the Matrix side effect out of the rolled-back transaction —
            // the DM's local substrate is what this pins.
            $this->mock(MatrixRoomCreationService::class)
                ->shouldReceive('createPrivateRoom')->andReturn(new MatrixRoom());

            $me = $this->aUser('DM Initiator');
            $them = $this->aUser('DM Recipient');

            $resp = $this->actingAs($me)->post('/people/'.$them->id.'/message')->assertRedirect();
            $spaceId = basename((string) parse_url($resp->headers->get('Location'), PHP_URL_PATH));

            $space = SocialSpace::query()->find($spaceId);
            $this->assertNotNull($space, 'the DM opened a room');
            $this->assertSame(SocialSpace::TYPE_GROUP, $space->space_type);
            $this->assertTrue((bool) $space->is_private);
            $this->assertSame((string) $me->id, (string) $space->owner_user_id);

            $members = SocialMembership::query()->where('space_id', $spaceId)
                ->whereNull('deleted_at')->pluck('user_id')->map(fn ($x) => (string) $x)->sort()->values()->all();
            $expected = collect([(string) $me->id, (string) $them->id])->sort()->values()->all();
            $this->assertSame($expected, $members, 'a DM is exactly the two of them');

            // Idempotent: a second message reuses the same room (one DM per pair).
            $resp2 = $this->actingAs($me)->post('/people/'.$them->id.'/message')->assertRedirect();
            $this->assertSame($spaceId, basename((string) parse_url($resp2->headers->get('Location'), PHP_URL_PATH)));
            $this->assertSame(1, SocialSpace::query()
                ->where('owner_user_id', (string) $me->id)->whereNull('deleted_at')->count());

            // You cannot DM yourself.
            $this->actingAs($me)->post('/people/'.$me->id.'/message')->assertStatus(422);
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
