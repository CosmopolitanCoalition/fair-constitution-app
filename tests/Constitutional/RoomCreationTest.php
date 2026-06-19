<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\MatrixRoom;
use App\Models\MatrixServerAcl;
use App\Services\Matrix\MatrixClientService;
use App\Services\Matrix\MatrixFederationGateService;
use App\Services\Matrix\MatrixRoomCreationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-3 (K3-E), the NO-HUMAN-POWER invariant (Art. I §5.1). Every public
 * commons room is created in room v12 with the appservice as the sole immutable creator and a power
 * map NO human can reach (users:{}, ban/kick/redact = 100); a homeserver that cannot offer v12 is
 * refused; a server ACL is M-1/M-4 only and can NEVER write allow:[] (the self-brick footgun). The
 * Matrix client is mocked (hermetic — no live homeserver needed); matrix_rooms is real (live-pg).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class RoomCreationTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_k3_rooms';

    public function test_a_public_commons_room_is_v12_sole_creator_with_no_human_power(): void
    {
        $this->onLivePg(function () {
            $captured = null;
            $this->mock(MatrixClientService::class, function ($m) use (&$captured) {
                $m->shouldReceive('roomVersions')->andReturn(['default' => '10', 'available' => ['10', '11', '12']]);
                $m->shouldReceive('createRoom')->andReturnUsing(function ($body) use (&$captured) {
                    $captured = $body;

                    return ['room_id' => '!probe:localhost'];
                });
            });

            $room = app(MatrixRoomCreationService::class)->createPublicCommonsRoom(
                'jurisdiction', (string) Str::uuid(), 'public_square', 'commons', 'Square: Test', null
            );

            // The room is v12 + public.
            $this->assertSame('12', $captured['room_version']);
            $this->assertSame('public', $captured['visibility']);

            // NO HUMAN holds a power level — only the v12 creator's implicit, unencodable power.
            $pl = $captured['power_level_content_override'];
            $this->assertSame([], (array) $pl['users'], 'users:{} — no human holds a power level');
            foreach (['ban', 'kick', 'redact', 'state_default', 'invite'] as $k) {
                $this->assertSame(100, $pl[$k], "{$k} is unreachable (100)");
            }
            $this->assertSame(0, $pl['events_default'], 'members may post (residency-gated by the appservice)');
            $this->assertSame(0, $pl['users_default']);
            $this->assertSame(100, $pl['events']['m.room.encryption'], 'public rooms cannot be encrypted');

            // world_readable.
            $history = collect($captured['initial_state'])->firstWhere('type', 'm.room.history_visibility');
            $this->assertSame('world_readable', $history['content']['history_visibility']);

            // Recorded as a v12 public room.
            $this->assertTrue(
                MatrixRoom::query()->whereKey($room->id)->where('room_version', '12')->where('is_public', true)->exists()
            );
        });
    }

    public function test_refuses_a_homeserver_that_cannot_offer_v12(): void
    {
        $this->onLivePg(function () {
            $this->mock(MatrixClientService::class, function ($m) {
                $m->shouldReceive('roomVersions')->andReturn(['default' => '10', 'available' => ['10', '11']]);
                $m->shouldNotReceive('createRoom');
            });

            $threw = false;
            try {
                app(MatrixRoomCreationService::class)->createPublicCommonsRoom(
                    'jurisdiction', (string) Str::uuid(), 'public_square', 'commons', 'No v12', null
                );
            } catch (ConstitutionalViolation $e) {
                $threw = true;
                $this->assertSame('Art. I', $e->citation);
            }

            $this->assertTrue($threw, 'refuses to stand a public commons on a homeserver without v12');
        });
    }

    public function test_a_server_acl_never_self_bricks_and_is_carveout_only(): void
    {
        $this->onLivePg(function () {
            $sent = null;
            $this->mock(MatrixClientService::class, function ($m) use (&$sent) {
                $m->shouldReceive('sendStateEvent')->andReturnUsing(function ($room, $type, $key, $content) use (&$sent) {
                    $sent = $content;

                    return ['event_id' => '$x'];
                });
            });
            $gate = app(MatrixFederationGateService::class);

            // A valid M-4 abusive-server ACL ALWAYS retains the local server (never allow:[]).
            $gate->setRoomServerACL('!r:localhost', ['evil.example'], MatrixServerAcl::CARVE_M4_ANTISPAM);
            $this->assertNotEmpty($sent['allow'], 'allow is never [] (the self-brick guard)');
            $this->assertContains(config('matrix.server_name'), $sent['allow'], 'the local server is always retained');
            $this->assertContains('evil.example', $sent['deny']);

            // A viewpoint / non-M1-M4 carve-out is refused.
            $threw = false;
            try {
                $gate->setRoomServerACL('!r:localhost', ['x'], 'm2_rights');
            } catch (ConstitutionalViolation $e) {
                $threw = true;
                $this->assertSame('Art. I', $e->citation);
            }
            $this->assertTrue($threw, 'a server ACL is M-1/M-4 only, never viewpoint');
        });
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
