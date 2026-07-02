<?php

namespace Tests\Feature;

use App\Models\Invite;
use App\Models\SocialMembership;
use App\Models\SocialSpace;
use App\Models\User;
use App\Services\Invites\InviteService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * The invite landing EXPERIENCE (mockups-v3-wiring Phase 3a — the civic/join.html arrival
 * contract): a guest opening /i/{token} gets an HONEST preview of where the link leads —
 * a `space` invite reflects the real room (title, member count, privacy); the open kinds
 * surface the server-built label; a dead link still renders the front door, preview-free.
 *
 * The InviteFlowTest posture: guarded live-pg connection, everything rolled back —
 * never RefreshDatabase (the live dev DB is not disposable).
 */
class InviteLandingPreviewTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_invite_landing';

    public function test_a_space_invite_previews_the_real_room_for_a_guest(): void
    {
        $this->onLivePg(function () {
            $inviter = $this->aUser('Room Owner');
            $member = $this->aUser('Early Member');
            $space = $this->aPrivateRoom($inviter, 'Harbor Cleanup Crew');

            SocialMembership::create([
                'space_id' => (string) $space->id,
                'user_id'  => (string) $member->id,
                'role'     => SocialMembership::ROLE_MEMBER,
            ]);

            [$plain] = app(InviteService::class)->mint($inviter, [
                'kind' => Invite::KIND_SPACE, 'space_id' => (string) $space->id,
            ]);

            $this->get('/i/'.$plain)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Invite/Landing')
                    ->where('invite.kind', 'space')
                    ->where('invite.inviter', 'Room Owner')
                    ->where('preview.title', 'Harbor Cleanup Crew')
                    ->where('preview.memberCount', 2)
                    ->where('preview.isPrivate', true));
        });
    }

    public function test_a_dead_invite_still_renders_the_door_without_a_preview(): void
    {
        $this->onLivePg(function () {
            $inviter = $this->aUser('Inviter');
            [$plain, $invite] = app(InviteService::class)->mint($inviter, [
                'kind' => Invite::KIND_PROCEEDING, 'path' => '/system/public-records', 'label' => 'The public record',
            ]);

            app(InviteService::class)->revoke($invite->handle);

            $this->get('/i/'.$plain)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Invite/Landing')
                    ->where('invite', null)
                    ->missing('preview'));
        });
    }

    public function test_a_commons_invite_previews_its_label_as_the_title(): void
    {
        $this->onLivePg(function () {
            $inviter = $this->aUser('Inviter');
            [$plain, $invite] = app(InviteService::class)->mint($inviter, [
                'kind' => Invite::KIND_COMMONS, 'jurisdiction_id' => $this->aJurisdiction(), 'space' => 'square',
            ]);

            $this->assertNotSame('', trim((string) $invite->label), 'commons mint builds a label');

            $this->get('/i/'.$plain)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Invite/Landing')
                    ->where('invite.kind', 'commons')
                    ->where('preview.title', $invite->label)
                    ->where('preview.memberCount', null)
                    ->where('preview.isPrivate', false));
        });
    }

    // ── helpers (the InviteFlowTest live-pg posture) ──────────────────────────────────────

    private function aUser(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => 'invite-landing-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }

    /** A private room + the owner's membership — built directly (no Matrix provisioning in tests). */
    private function aPrivateRoom(User $owner, string $title): SocialSpace
    {
        $space = SocialSpace::create([
            'jurisdiction_id' => $this->aJurisdiction(),
            'space_type'      => SocialSpace::TYPE_GROUP,
            'title'           => $title,
            'status'          => SocialSpace::STATUS_OPEN,
            'is_private'      => true,
            'owner_user_id'   => (string) $owner->id,
        ]);

        SocialMembership::create([
            'space_id' => (string) $space->id,
            'user_id'  => (string) $owner->id,
            'role'     => SocialMembership::ROLE_OWNER,
        ]);

        return $space;
    }

    private function aJurisdiction(): string
    {
        $id = DB::table('jurisdictions')->whereNull('deleted_at')->value('id');
        if ($id === null) {
            $this->markTestSkipped('Live DB has no jurisdiction.');
        }

        return (string) $id;
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
