<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Invite;
use App\Models\MatrixRoom;
use App\Models\SocialMembership;
use App\Models\SocialSpace;
use App\Models\User;
use App\Services\Invites\InviteService;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\Social\PrivateRoomService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — user-owned PRIVATE ROOMS are the "Art. I private half": private association,
 * OFF the public-commons plane. A room is reachable ONLY by its members (never residency), writes NO
 * public record / testimony, and confers NO governance. A `space` invite admits the redeemer as a
 * member. The commons gate (assertMayAccessCommons) is a SEPARATE code path and stays untouched.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class PrivateRoomTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_private_room';

    public function test_create_makes_a_private_room_owned_and_off_the_civic_plane(): void
    {
        $this->onLivePg(function () {
            $owner = $this->aUser('Owner');
            $recordsBefore = DB::table('public_records')->count();

            $space = app(PrivateRoomService::class)->create($owner, 'Friday call');

            $this->assertTrue((bool) $space->is_private);
            $this->assertSame(SocialSpace::TYPE_GROUP, $space->space_type);
            $this->assertSame((string) $owner->id, (string) $space->owner_user_id);

            $this->assertSame(SocialMembership::ROLE_OWNER, SocialMembership::query()
                ->where('space_id', $space->id)->where('user_id', $owner->id)->value('role'));

            // OFF the civic plane: creating a private room NEVER touches the public record.
            $this->assertSame($recordsBefore, DB::table('public_records')->count(),
                'a private room writes no public record');
        });
    }

    public function test_a_member_passes_the_gate_and_a_non_member_is_refused(): void
    {
        $this->onLivePg(function () {
            $owner = $this->aUser('Owner');
            $space = $this->aPrivateSpace($owner);
            $room = $this->aPrivateRoom($space);
            $gate = app(MatrixPostingGateService::class);

            // The owner (a member) reaches the room.
            $gate->assertMayAccessPrivateRoom($owner, (string) $room->matrix_room_id);
            $this->assertTrue(true);

            // A stranger is refused — membership is the gate.
            $stranger = $this->aUser('Stranger');
            $threw = false;
            try {
                $gate->assertMayAccessPrivateRoom($stranger, (string) $room->matrix_room_id);
            } catch (ConstitutionalViolation) {
                $threw = true;
            }
            $this->assertTrue($threw, 'a non-member cannot reach a private room');

            // Once admitted, the stranger passes.
            app(PrivateRoomService::class)->admit($space, $stranger);
            $gate->assertMayAccessPrivateRoom($stranger, (string) $room->matrix_room_id);
            $this->assertTrue(true);
        });
    }

    public function test_a_space_invite_admits_the_redeemer_as_a_member(): void
    {
        $this->onLivePg(function () {
            $owner = $this->aUser('Owner');
            $space = $this->aPrivateSpace($owner);
            $invites = app(InviteService::class);

            [$plain, $invite] = $invites->mint($owner, ['kind' => Invite::KIND_SPACE, 'space_id' => (string) $space->id]);
            $this->assertSame('/civic/rooms/'.$space->id, $invite->path());

            $friend = $this->aUser('Friend');
            $this->assertFalse(app(PrivateRoomService::class)->isMember($space, $friend));

            $this->assertNotNull($invites->resolve($plain));
            $invites->consume($invite, $friend);
            $invites->grantAccess($invite, $friend);

            $this->assertTrue(app(PrivateRoomService::class)->isMember($space, $friend),
                'redeeming a space invite admits the friend to the room');
        });
    }

    public function test_the_private_call_token_endpoint_is_member_gated(): void
    {
        $this->onLivePg(function () {
            $owner = $this->aUser('Owner');
            $space = $this->aPrivateSpace($owner);
            $room = $this->aPrivateRoom($space);

            // A member gets a room-scoped, pseudonymous token.
            $this->withSession(['_token' => 'pin'])
                ->actingAs($owner)
                ->postJson('/civic/matrix/private-call-token', ['room_id' => $room->matrix_room_id], ['X-CSRF-TOKEN' => 'pin'])
                ->assertOk()
                ->assertJsonStructure(['token', 'sfu_url', 'room', 'identity']);

            // A non-member is refused (403) — the membership gate, not residency.
            $stranger = $this->aUser('Stranger');
            $this->withSession(['_token' => 'pin'])
                ->actingAs($stranger)
                ->postJson('/civic/matrix/private-call-token', ['room_id' => $room->matrix_room_id], ['X-CSRF-TOKEN' => 'pin'])
                ->assertStatus(403);
        });
    }

    // ── helpers (the CallTokenResidencyTest live-pg posture) ──────────────────────────────

    private function aUser(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => 'priv-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }

    private function aJurisdiction(): string
    {
        $id = DB::table('jurisdictions')->whereNull('deleted_at')->value('id');
        if ($id === null) {
            $this->markTestSkipped('Live DB has no jurisdiction.');
        }

        return (string) $id;
    }

    /** A private group space with the owner already a member — bypasses Matrix provisioning (kept fast/offline). */
    private function aPrivateSpace(User $owner): SocialSpace
    {
        $space = SocialSpace::query()->create([
            'jurisdiction_id' => $this->aJurisdiction(),
            'space_type'      => SocialSpace::TYPE_GROUP,
            'title'           => 'Test room',
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

    /** A manually-provisioned private Matrix room bound to the space (no live homeserver needed). */
    private function aPrivateRoom(SocialSpace $space): MatrixRoom
    {
        return MatrixRoom::query()->create([
            'matrix_room_id' => '!priv-'.Str::lower(Str::random(10)).':localhost',
            'room_type'      => MatrixRoom::ROOM_USER_PRIVATE,
            'room_version'   => '12',
            'entity_type'    => MatrixRoom::ENTITY_SOCIAL_SPACE,
            'entity_id'      => (string) $space->id,
            'space_type'     => null,
            'is_public'      => false,
        ]);
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
