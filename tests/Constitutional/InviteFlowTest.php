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
 * redemption, records ONLY attribution — it grants no residency, role, or governance power. A link is a
 * short code (8 URL-safe characters, or a custom name): atomic single-use, revocation, expiry, a miss
 * lockout per address, and a SERVER-BUILT same-origin destination (no open redirect).
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

            // A short URL-safe code: 8 characters, the stored handle itself.
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9][A-Za-z0-9_-]{7}$/', $plaintext);
            $this->assertSame($plaintext, $invite->handle);
            $this->assertFalse((bool) $invite->is_custom);
            $this->assertStringStartsWith('/civic/commons/square', $invite->path());

            // Resolve is content-correct; a wrong secret / garbage fails closed.
            $this->assertNotNull(app(InviteService::class)->resolve($plaintext));
            $this->assertNull(app(InviteService::class)->resolve('nope.nope'), 'a long old-style token is not a code');
            $this->assertNull(app(InviteService::class)->resolve('zzzzzzzz'), 'an unknown code fails closed');
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


    public function test_codes_are_short_distinct_and_never_enter_the_audit_chain(): void
    {
        $this->onLivePg(function () {
            $inviter = $this->aUser('Inviter');
            $svc = app(InviteService::class);
            $codes = [];
            for ($i = 0; $i < 25; $i++) {
                [$code] = $svc->mint($inviter, ['kind' => Invite::KIND_PROCEEDING, 'path' => '/bills/'.Str::uuid()]);
                $this->assertMatchesRegularExpression('/^[A-Za-z0-9][A-Za-z0-9_-]{7}$/', $code);
                $codes[] = $code;
            }
            $this->assertCount(25, array_unique($codes));

            $payloads = DB::table('audit_log')->where('module', 'invite')->orderByDesc('seq')->limit(60)->pluck('payload')->implode(' ');
            foreach ($codes as $code) {
                $this->assertStringNotContainsString($code, (string) $payloads, 'the code is the credential, so it is not logged');
            }
        });
    }

    public function test_a_custom_name_is_validated_unique_and_needs_a_limit(): void
    {
        $this->onLivePg(function () {
            $inviter = $this->aUser('Inviter');
            $svc = app(InviteService::class);
            $spec = ['kind' => Invite::KIND_PROCEEDING, 'path' => '/system/public-records'];

            [$code, $invite] = $svc->mint($inviter, $spec + ['slug' => 'Team-Night']);
            $this->assertSame('team-night', $code, 'a name is stored in lowercase');
            $this->assertTrue((bool) $invite->is_custom);
            $this->assertNotNull($svc->resolve('team-night'));
            $this->assertNotNull($svc->resolve('TEAM-NIGHT'), 'a custom name matches in any case');

            foreach (['team-night' => 'taken', 'ab' => 'short', '-bad' => 'edge', 'bad-' => 'edge', 'a--b' => 'double', 'has space' => 'space', 'admin' => 'reserved'] as $bad => $why) {
                try {
                    $svc->mint($inviter, $spec + ['slug' => $bad]);
                    $this->fail("a {$why} name was accepted: [{$bad}]");
                } catch (InvalidArgumentException) {
                    $this->assertTrue(true);
                }
            }

            try {
                $svc->mint($inviter, $spec + ['slug' => 'no-limits', 'ttl_days' => null]);
                $this->fail('a custom name with no expiry and no use limit was accepted');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
            [, $limited] = $svc->mint($inviter, $spec + ['slug' => 'with-a-limit', 'ttl_days' => null, 'max_uses' => 5]);
            $this->assertNull($limited->expires_at);
        });
    }

    public function test_old_style_links_reach_the_front_door_and_repeated_misses_lock_an_address(): void
    {
        $this->onLivePg(function () {
            $inviter = $this->aUser('Inviter');
            [$code] = app(InviteService::class)->mint($inviter, ['kind' => Invite::KIND_PROCEEDING, 'path' => '/system/public-records']);
            \Illuminate\Support\Facades\RateLimiter::clear('invite-miss:127.0.0.1');

            $this->get('/i/oldhandle12.'.str_repeat('a', 64))->assertOk(); // old long link: front door, not a 404
            for ($i = 0; $i < 20; $i++) {
                $this->get('/i/zz'.$i.'wrong')->assertOk();
            }
            // Locked: even the right code reads as invalid until the window passes.
            $this->get('/i/'.$code)->assertOk()
                ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->component('Invite/Landing')->where('invite', null));
            \Illuminate\Support\Facades\RateLimiter::clear('invite-miss:127.0.0.1');
            $this->get('/i/'.$code)->assertOk();
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
