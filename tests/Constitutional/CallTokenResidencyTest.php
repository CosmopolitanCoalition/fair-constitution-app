<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
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
 * CONSTITUTIONAL PIN — Phase K-3 (K3-J), the LiveKit call-token minter. Voice/video is participation:
 * RESIDENCY is the ONLY gate (Art. I — never karma/age/reputation), the SAME assertion as posting. The
 * token's identity is the resident's PSEUDONYM (@u-<handle>), never the legal name; it is ROOM-SCOPED
 * (a VideoGrant for ONE room), SHORT-LIVED (a bounded exp), grants no admin/recording rights, and is
 * signed by the APPSERVICE alone (a forged/foreign token does not verify).
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

    public function test_a_non_resident_is_refused_under_art_i(): void
    {
        $this->onLivePg(function () {
            $jur = $this->aJurisdiction();
            // A user with NO residency association with this jurisdiction.
            $stranger = User::create([
                'name'              => 'Stranger',
                'email'             => 'k3call-stranger-'.Str::uuid().'@test.invalid',
                'password'          => Str::random(32),
                'terms_accepted_at' => now(),
            ]);
            app(RoleService::class)->flush();

            $threw = false;
            try {
                app(LiveKitTokenService::class)->mintFor($stranger, $jur, self::ROOM);
            } catch (ConstitutionalViolation $e) {
                $threw = true;
                $this->assertSame('Art. I', $e->citation);
            }
            $this->assertTrue($threw, 'a non-resident cannot get a call token — residency is the only gate');
        });
    }

    public function test_a_forged_or_foreign_token_does_not_verify(): void
    {
        $this->onLivePg(function () {
            $jur = $this->aJurisdiction();
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

    private function resident(string $jurisdictionId, string $legalName, string $handle): User
    {
        $user = User::create([
            'name'              => $legalName,
            'email'             => 'k3call-'.Str::uuid().'@test.invalid',
            'password'          => Str::random(32),
            'terms_accepted_at' => now(),
        ]);

        SocialProfile::query()->create(['user_id' => (string) $user->id, 'handle' => $handle]);

        DB::table('residency_confirmations')->insert([
            'id'              => (string) Str::uuid(),
            'user_id'         => (string) $user->id,
            'jurisdiction_id' => $jurisdictionId,
            'days_confirmed'  => 30,
            'confirmed_at'    => now(),
            'is_active'       => true,
            'depth'           => 0,
            'created_at'      => now(),
            'updated_at'      => now(),
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
