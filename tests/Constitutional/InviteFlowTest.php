<?php

namespace Tests\Constitutional;

use App\Models\Invite;
use App\Models\User;
use App\Services\Invites\InviteService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — person-to-person invites are a GROWTH pointer, never a privilege. An invite
 * lands a friend somewhere already open (Art. I commons / Art. II §2 public proceedings) and, on
 * redemption, records ONLY attribution — it grants no residency, role, or governance power. The token
 * posture mirrors the federation join-key: Argon2id at rest, constant-time resolve, atomic single-use,
 * revocation, and a SERVER-BUILT same-origin destination (no open redirect).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class InviteFlowTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_invite';

    public function test_mint_resolve_and_consume_records_only_attribution(): void
    {
        $this->onLivePg(function () {
            $inviter = $this->aUser('Inviter');
            $jur = $this->aJurisdiction();

            [$plaintext, $invite] = app(InviteService::class)->mint($inviter, [
                'kind' => Invite::KIND_CALL, 'jurisdiction_id' => $jur, 'space' => 'square',
            ]);

            // handle.secret form; only the hash is stored, never the plaintext.
            $this->assertStringContainsString('.', $plaintext);
            $this->assertNotSame($plaintext, $invite->token_hash);
            $this->assertStringStartsWith('/civic/commons/square', $invite->path());

            // Resolve is content-correct; a wrong secret / garbage fails closed.
            $this->assertNotNull(app(InviteService::class)->resolve($plaintext));
            $this->assertNull(app(InviteService::class)->resolve('nope.nope'));
            $this->assertNull(app(InviteService::class)->resolve($invite->handle.'.wrongsecret'));

            // A brand-new friend redeems it.
            $friend = $this->aUser('Friend');
            $this->assertNull($friend->invited_by_user_id);
            $this->assertTrue(app(InviteService::class)->consume($invite, $friend));

            $friend->refresh();
            $this->assertSame((string) $inviter->id, (string) $friend->invited_by_user_id, 'attribution recorded');

            // NO power conferred: no residency row, no derived role beyond the base individual.
            $this->assertSame(0, DB::table('residency_confirmations')->where('user_id', (string) $friend->id)->count());
            $roles = app(RoleService::class)->rolesFor($friend);
            foreach (['R-02', 'R-03', 'R-04'] as $power) {
                $this->assertNotContains($power, $roles, "an invite grants no {$power}");
            }
        });
    }

    public function test_a_single_use_invite_consumes_once_then_is_dead(): void
    {
        $this->onLivePg(function () {
            $inviter = $this->aUser('Inviter');
            [$plain, $invite] = app(InviteService::class)->mint($inviter, [
                'kind' => Invite::KIND_PROCEEDING, 'path' => '/system/public-records', 'max_uses' => 1,
            ]);

            $a = $this->aUser('First');
            $b = $this->aUser('Second');

            $this->assertTrue(app(InviteService::class)->consume($invite, $a));
            $this->assertFalse(app(InviteService::class)->consume($invite->fresh(), $b), 'a one-use invite admits exactly one');
            $this->assertNull(app(InviteService::class)->resolve($plain), 'exhausted → resolves to null');
        });
    }

    public function test_a_revoked_invite_stops_resolving(): void
    {
        $this->onLivePg(function () {
            $inviter = $this->aUser('Inviter');
            [$plain, $invite] = app(InviteService::class)->mint($inviter, [
                'kind' => Invite::KIND_PROCEEDING, 'path' => '/system/public-records',
            ]);

            $this->assertNotNull(app(InviteService::class)->resolve($plain));
            $this->assertTrue(app(InviteService::class)->revoke($invite->handle));
            $this->assertNull(app(InviteService::class)->resolve($plain), 'revoked → null');
        });
    }

    public function test_mint_refuses_an_off_site_or_non_proceeding_destination(): void
    {
        $this->onLivePg(function () {
            $inviter = $this->aUser('Inviter');
            $svc = app(InviteService::class);

            foreach (['https://evil.example', '//evil.example', '/dev/secrets', '/bills/../dev', "/bills/x\\..\\dev"] as $bad) {
                try {
                    $svc->mint($inviter, ['kind' => Invite::KIND_PROCEEDING, 'path' => $bad]);
                    $this->fail("the open-redirect guard accepted [{$bad}]");
                } catch (InvalidArgumentException) {
                    $this->assertTrue(true);
                }
            }

            // A legitimate same-origin proceeding path is accepted.
            [, $invite] = $svc->mint($inviter, ['kind' => Invite::KIND_PROCEEDING, 'path' => '/bills/'.Str::uuid()]);
            $this->assertStringStartsWith('/bills/', $invite->path());
        });
    }

    public function test_the_landing_route_previews_for_a_guest_and_continues_an_authed_user(): void
    {
        $this->onLivePg(function () {
            $inviter = $this->aUser('Inviter');
            [$plain] = app(InviteService::class)->mint($inviter, [
                'kind' => Invite::KIND_PROCEEDING, 'path' => '/system/public-records', 'label' => 'The public record',
            ]);

            // Guest: a preview page (not a redirect) — the front door for a would-be member.
            $this->get('/i/'.$plain)->assertOk();

            // Authed: redeemed + sent straight to the destination.
            $friend = $this->aUser('Friend');
            $this->actingAs($friend)->get('/i/'.$plain)->assertRedirect('/system/public-records');

            $friend->refresh();
            $this->assertSame((string) $inviter->id, (string) $friend->invited_by_user_id);
        });
    }

    // ── helpers (the CallTokenResidencyTest live-pg posture) ──────────────────────────────

    private function aUser(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => 'invite-'.Str::uuid().'@test.invalid',
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
