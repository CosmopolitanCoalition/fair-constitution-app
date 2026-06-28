<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\MatrixRoom;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\Matrix\LiveKitTokenService;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-3 (K3-J) / Phase 5, the LiveKit call-token minter. Voice/video in the
 * public commons is participation, gated EXACTLY like speech: the commons is OPEN (Art. I — any
 * authenticated player, resident OR visitor; never karma/age/reputation). The token's identity is the
 * player's PSEUDONYM (@u-<handle>), never the legal name; it is ROOM-SCOPED (a VideoGrant for ONE room),
 * SHORT-LIVED (a bounded exp), grants no admin/recording rights, and is signed by the APPSERVICE alone
 * (a forged/foreign token does not verify).
 *
 * (Corrected 2026-06-27: the prior pin refused a non-resident; the operator's constitutional correction
 * is that the commons is open and only governance POWERS are residency-gated, enforced by the game.)
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class CallTokenResidencyTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_k3_call';

    private const ROOM = '!halls:localhost';

    public function test_a_resident_gets_a_room_scoped_pseudonymous_bounded_token(): void
    {
        $this->onLivePg(function () {
            $jur = $this->aJurisdiction();
            $this->commonsCallRoom($jur);
            $caller = $this->resident($jur, 'Caller LEGALNAME', 'caller-handle');
            $svc = app(LiveKitTokenService::class);
            $expectedIdentity = app(MatrixPostingGateService::class)->matrixUserId($caller);

            $minted = $svc->mintFor($caller, $jur, self::ROOM);

            $this->assertSame($expectedIdentity, $minted['identity']);
            $this->assertStringNotContainsString('LEGALNAME', $minted['identity'], 'Art. I — the identity is a pseudonym');
            $this->assertStringStartsWith('@u-', $minted['identity']);
            $this->assertSame(self::ROOM, $minted['room']);

            // The token verifies under the api_secret and carries a room-scoped, bounded grant.
            $claims = $svc->verify($minted['token']);
            $this->assertNotNull($claims, 'the minted token verifies under the api_secret');
            $this->assertSame($expectedIdentity, $claims['sub'], 'sub is the pseudonym, never the legal name');
            $this->assertStringNotContainsString('LEGALNAME', json_encode($claims), 'no legal name anywhere in the token');
            $this->assertSame(self::ROOM, $claims['video']['room'], 'room-scoped grant');
            $this->assertTrue($claims['video']['roomJoin']);
            $this->assertArrayNotHasKey('roomAdmin', $claims['video'], 'no admin grant');

            $lifetime = (int) $claims['exp'] - (int) $claims['nbf'];
            $this->assertGreaterThan(0, $lifetime);
            $this->assertLessThanOrEqual(LiveKitTokenService::MAX_TTL_SECONDS, $lifetime, 'a bounded join grant, not a session');
        });
    }

    public function test_a_visitor_non_resident_also_gets_a_token_open_commons(): void
    {
        $this->onLivePg(function () {
            $jur = $this->aJurisdiction();
            $this->commonsCallRoom($jur);
            // A VISITOR with NO residency association with this jurisdiction.
            $visitor = User::create([
                'name' => 'Visitor',
                'email' => 'k3call-visitor-'.Str::uuid().'@test.invalid',
                'password' => Str::random(32),
                'terms_accepted_at' => now(),
            ]);
            SocialProfile::query()->create(['user_id' => (string) $visitor->id, 'handle' => 'visitor-handle']);
            app(RoleService::class)->flush();

            // The public commons is open (Art. I) — the visitor gets a room-scoped, pseudonymous token.
            $minted = app(LiveKitTokenService::class)->mintFor($visitor, $jur, self::ROOM);

            $this->assertStringStartsWith('@u-', $minted['identity'], 'the visitor joins pseudonymously');
            $this->assertStringNotContainsString('Visitor', $minted['identity'], 'Art. I — identity is a pseudonym');
            $this->assertSame(self::ROOM, $minted['room']);

            $claims = app(LiveKitTokenService::class)->verify($minted['token']);
            $this->assertNotNull($claims, 'a real, appservice-signed token even for a visitor');
            $this->assertSame(self::ROOM, $claims['video']['room'], 'still room-scoped');
            $this->assertTrue($claims['video']['roomJoin']);
            $this->assertArrayNotHasKey('roomAdmin', $claims['video'], 'no admin grant for anyone');
        });
    }

    public function test_a_forged_or_foreign_token_does_not_verify(): void
    {
        $this->onLivePg(function () {
            $jur = $this->aJurisdiction();
            $this->commonsCallRoom($jur);
            $caller = $this->resident($jur, 'Caller Two', 'caller2-handle');
            $svc = app(LiveKitTokenService::class);

            $token = $svc->mintFor($caller, $jur, self::ROOM)['token'];

            // Tamper the payload (escalate the grant) — the signature no longer matches.
            [$h, $p, $s] = explode('.', $token);
            $forged = $h.'.'.rtrim(strtr(base64_encode('{"video":{"roomAdmin":true}}'), '+/', '-_'), '=').'.'.$s;
            $this->assertNull($svc->verify($forged), 'a tampered token fails closed — the appservice is the sole signer');
            $this->assertNull($svc->verify('not.a.jwt'), 'garbage fails closed');
        });
    }

    public function test_no_token_mints_for_a_non_commons_room(): void
    {
        $this->onLivePg(function () {
            $jur = $this->aJurisdiction();
            // An institution's governance room — its OWN controls, not reachable through the open commons.
            MatrixRoom::query()->create([
                'matrix_room_id' => '!institution-court:localhost',
                'room_type' => MatrixRoom::ROOM_INSTITUTION,
                'room_version' => '12',
                'entity_type' => MatrixRoom::ENTITY_JUDICIARY,
                'entity_id' => (string) Str::uuid(),
                'space_type' => null,
                'is_public' => true,
            ]);
            $caller = $this->resident($jur, 'Caller Three', 'caller3-handle');

            // Even a resident gets NO voice grant for a non-commons room — the gate fails closed (Art. I).
            $threw = false;
            try {
                app(LiveKitTokenService::class)->mintFor($caller, $jur, '!institution-court:localhost');
            } catch (ConstitutionalViolation $e) {
                $threw = true;
                $this->assertSame('Art. I', $e->citation);
            }
            $this->assertTrue($threw, 'voice tokens mint only for the public square / halls — institution rooms have their own controls');
        });
    }

    /** The jurisdiction's public-commons call room — the open voice gate resolves + scopes against this. */
    private function commonsCallRoom(string $jur): void
    {
        MatrixRoom::query()->create([
            'matrix_room_id' => self::ROOM,
            'room_type' => MatrixRoom::ROOM_COMMONS,
            'room_version' => '12',
            'entity_type' => MatrixRoom::ENTITY_JURISDICTION,
            'entity_id' => $jur,
            'space_type' => MatrixRoom::SPACE_HALLS,
            'is_public' => true,
        ]);
    }

    private function resident(string $jurisdictionId, string $legalName, string $handle): User
    {
        $user = User::create([
            'name' => $legalName,
            'email' => 'k3call-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);

        SocialProfile::query()->create(['user_id' => (string) $user->id, 'handle' => $handle]);

        DB::table('residency_confirmations')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => (string) $user->id,
            'jurisdiction_id' => $jurisdictionId,
            'days_confirmed' => 30,
            'confirmed_at' => now(),
            'is_active' => true,
            'depth' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(RoleService::class)->flush();

        return $user;
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
