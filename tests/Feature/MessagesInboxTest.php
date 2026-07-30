<?php

namespace Tests\Feature;

use App\Models\SocialMembership;
use App\Models\SocialSpace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Messages inbox (mockups-v3-wiring Phase 3d — the groups/groups-home.html contract): /civic/rooms
 * is the direct & group messages inbox, a THIN UI over the existing private-room primitive
 * (SocialSpace group/is_private + SocialMembership). Creating a conversation lands back on the inbox
 * with the "Bring people in" share step (?created=<id>); the invite link is the only way in.
 *
 * The TodayFeedTest posture: DB-backed paths run on the guarded live-pg connection (never
 * RefreshDatabase — the live dev DB is not disposable), everything inside a rolled-back transaction;
 * SKIPS when pg is unreachable.
 */
class MessagesInboxTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_messages_inbox';

    public function test_the_inbox_renders_the_players_conversations_with_member_counts(): void
    {
        $this->onLivePg(function () {
            $owner = $this->aUser('Inbox Owner');
            $space = $this->aPrivateSpace($owner, 'Saturday crew');

            $this->actingAs($owner)
                ->get('/civic/rooms')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Civic/PrivateRooms')
                    ->where('surface.title', 'Messages')
                    ->has('rooms', 1)
                    ->where('rooms.0.id', (string) $space->id)
                    ->where('rooms.0.title', 'Saturday crew')
                    ->where('rooms.0.is_owner', true)
                    ->where('rooms.0.memberCount', 1)
                    ->has('rooms.0.openedAt')
                    ->where('created', null));
        });
    }

    public function test_creating_a_group_creates_the_space_and_owner_membership(): void
    {
        $this->onLivePg(function () {
            $owner = $this->aUser('Group Founder');
            $title = 'Inbox-test group '.Str::random(6);

            $response = $this->withSession(['_token' => 'pin'])
                ->actingAs($owner)
                ->post('/civic/rooms', ['name' => $title, '_token' => 'pin']);

            $space = SocialSpace::query()
                ->where('owner_user_id', (string) $owner->id)
                ->where('title', $title)
                ->first();

            $this->assertNotNull($space, 'creating a group creates the private space');
            $this->assertTrue((bool) $space->is_private, 'a conversation is a PRIVATE room');
            $this->assertSame(SocialSpace::TYPE_GROUP, $space->space_type);

            $this->assertSame(SocialMembership::ROLE_OWNER, SocialMembership::query()
                ->where('space_id', (string) $space->id)
                ->where('user_id', (string) $owner->id)
                ->value('role'), 'the creator holds the owner membership');

            // Lands back on the inbox with the share step open ("Bring people in").
            $response->assertRedirect('/civic/rooms?created='.$space->id);

            // And the inbox surfaces the just-created room to its owner.
            $this->actingAs($owner)
                ->get('/civic/rooms?created='.$space->id)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Civic/PrivateRooms')
                    ->where('created.id', (string) $space->id)
                    ->where('created.title', $title));
        });
    }

    public function test_the_created_rooms_page_renders_for_its_owner(): void
    {
        $this->onLivePg(function () {
            $owner = $this->aUser('Room Owner');
            $space = $this->aPrivateSpace($owner, 'Render check');

            $this->actingAs($owner)
                ->get('/civic/rooms/'.$space->id)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Civic/PrivateRoom')
                    ->where('locked', false)
                    ->where('room.id', (string) $space->id)
                    ->where('room.title', 'Render check')
                    ->where('room.is_owner', true)
                    ->has('members', 1));
        });
    }

    public function test_the_new_message_page_renders(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('New Message Author');

            $this->actingAs($user)
                ->get('/civic/rooms/new')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Civic/PrivateRoomCreate')
                    ->where('surface.title', 'New message'));
        });
    }

    public function test_inbox_rows_carry_a_real_preview_and_never_fabricate_unread_or_live(): void
    {
        $this->onLivePg(function () {
            $owner = $this->aUser('Preview Owner');
            $this->aPrivateSpace($owner, 'Quiet room'); // no Matrix room provisioned → no live channel

            $this->actingAs($owner)
                ->get('/civic/rooms')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Civic/PrivateRooms')
                    ->has('rooms', 1)
                    // The preview is a REAL server read: null here (no live channel), never a faked string.
                    ->where('rooms.0.preview', null)
                    ->where('rooms.0.lastAt', null)
                    // Honest-empty — no source table exists for these, so the row NEVER carries them.
                    ->missing('rooms.0.unread')
                    ->missing('rooms.0.live')
                    ->missing('rooms.0.kind'));
        });
    }

    // ── helpers (the PrivateRoomTest live-pg posture) ─────────────────────────────────────

    private function aUser(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => 'inbox-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }

    private function aJurisdiction(): string
    {
        $id = DB::table('jurisdictions')
            ->whereNull('deleted_at')
            // Scope to a jurisdiction this test can OWN. Taking the first row
            // takes an arbitrary REAL one, and once it holds live Matrix rooms
            // a fixture that creates its own collides on
            // matrix_rooms_entity_unique. Which files that hits is decided by
            // table ordering, i.e. chance — see c70af6e, where Niue acquiring
            // rooms at 16:53 on 2026-07-26 took 11 tests down at once.
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('matrix_rooms')
                ->whereColumn('matrix_rooms.entity_id', 'jurisdictions.id')
                ->whereNull('matrix_rooms.deleted_at'))
            ->value('id');
        if ($id === null) {
            $this->markTestSkipped('Live DB has no jurisdiction.');
        }

        return (string) $id;
    }

    /** A private group space with the owner already a member — bypasses Matrix provisioning (kept fast/offline). */
    private function aPrivateSpace(User $owner, string $title): SocialSpace
    {
        $space = SocialSpace::query()->create([
            'jurisdiction_id' => $this->aJurisdiction(),
            'space_type'      => SocialSpace::TYPE_GROUP,
            'title'           => $title,
            'status'          => SocialSpace::STATUS_OPEN,
            'is_private'      => true,
            'owner_user_id'   => (string) $owner->id,
        ]);

        SocialMembership::query()->create([
            'space_id' => (string) $space->id,
            'user_id'  => (string) $owner->id,
            'role'     => SocialMembership::ROLE_OWNER,
        ]);

        return $space;
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
