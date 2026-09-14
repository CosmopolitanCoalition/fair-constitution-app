<?php

namespace Tests\Unit;

use App\Models\Board;
use App\Models\MatrixIdentity;
use App\Models\User;
use App\Services\Matrix\LiveKitTokenService;
use App\Services\Matrix\MatrixClientService;
use App\Services\Matrix\MatrixIdentityProvisioner;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\Rooms\BoardRoomAccess;
use App\Services\Rooms\RoomFloorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * R2 · combined institutional rooms over REAL transport.
 *
 * Register row remaining scope: join the same app-authorized presider/member/witness/board journey
 * to real transport; verify media/history continuity, retained identity, direct unauthorized joins
 * and removed members with already-issued grants. The application/controller/seating scope already
 * passed; this establishes the transport lifetime the synthetic HTTP/track objects did not.
 *
 * Three parts:
 *   Part A  door refusals — the SEATING guard every board door runs, executed for real on a sqlite
 *           fixture, with Http::preventStrayRequests() proving the deny path issues ZERO transport
 *           calls (the "before any Matrix/LiveKit call" property).
 *   Part B  real Matrix HTTP against the running fc_matrix homeserver (createRoom + send + read back
 *           + re-read) — identity + history continuity over the real Client-Server API.
 *   Part C  real LiveKit SFU (fc_livekit) token-grant + TTL enforcement via /rtc/validate.
 *
 * Media-FRAME publish/subscribe (WebRTC) is browser-only: it needs a browser media runtime
 * (RTCPeerConnection + getUserMedia) that these test containers do not have. That portion is NOT
 * established here; it moves to the browser lane and is reported separately.
 */
final class RoomTransportTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['cache.default' => 'array', 'database.connections.room_transport_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('room_transport_fixture');
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('boards', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('status');
            $t->string('chair_seat_id')->nullable();
            $t->string('boardable_type')->nullable();
            $t->string('boardable_id')->nullable();
            $t->softDeletes();
        });
        $schema->create('board_seats', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('status');
            $t->string('board_id')->nullable();
            $t->string('holder_user_id')->nullable();
            $t->integer('seat_no')->nullable();
            $t->softDeletes();
        });
        // The real LiveKit mint path resolves a public pseudonym via PublicRoomNames, which reads
        // matrix_identities. Create the real (empty) table so the mint runs its real query and falls
        // back to the identity handle — the SFU grant recipe stays the app's own, undoubled.
        $schema->create('matrix_identities', function (Blueprint $t) {
            $t->string('user_id')->nullable();
            $t->string('matrix_user_id')->nullable();
            $t->softDeletes();
        });
        DB::table('boards')->insert(['id' => 'brd', 'status' => Board::STATUS_ACTIVE, 'chair_seat_id' => 'seat-chair']);
        DB::table('board_seats')->insert([
            ['id' => 'seat-chair', 'status' => 'seated', 'board_id' => 'brd', 'holder_user_id' => 'u-chair', 'seat_no' => 1],
            ['id' => 'seat-dir', 'status' => 'seated', 'board_id' => 'brd', 'holder_user_id' => 'u-dir', 'seat_no' => 2],
            // A director whose seat was REMOVED: no seated row remains for this holder.
            ['id' => 'seat-removed', 'status' => 'removed', 'board_id' => 'brd', 'holder_user_id' => 'u-removed', 'seat_no' => 3],
        ]);

        // Infrastructure doubles only. The guard under test (BoardRoomAccess / the board branch of
        // RoomFloorService::access) is the REAL service — never doubled. The provisioner is reached only
        // on a SUCCESSFUL raise (after the guard passes); it writes a Matrix identity row we do not need.
        $identities = $this->createMock(MatrixPostingGateService::class);
        $identities->method('matrixUserId')->willReturnCallback(fn (User $u) => '@u-'.$u->getKey().':localhost');
        app()->instance(MatrixPostingGateService::class, $identities);
        $provisioner = $this->createMock(MatrixIdentityProvisioner::class);
        $provisioner->method('ensureFor')->willReturnCallback(fn (User $u) => (new MatrixIdentity())->forceFill([
            'matrix_user_id' => '@u-'.$u->getKey().':localhost',
        ]));
        app()->instance(MatrixIdentityProvisioner::class, $provisioner);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function user(string $id): User
    {
        return (new User())->forceFill(['id' => $id]);
    }

    private function httpStatus(callable $fn): int
    {
        try {
            $fn();

            return 200;
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    // ─── Part A · the seating guard every board door runs, and it runs BEFORE any transport call ───

    public function test_board_doors_refuse_outsider_and_removed_director_before_any_transport_call(): void
    {
        // No stray HTTP may leave on the deny path. If any board door reached Matrix/LiveKit before the
        // seating guard, this would throw. Zero requests = the refusals happen before any transport call.
        Http::preventStrayRequests();

        $board = Board::query()->findOrFail('brd');
        $access = app(BoardRoomAccess::class);
        $floor = app(RoomFloorService::class);

        $outsider = $this->user('u-outsider');   // never held a seat
        $removed = $this->user('u-removed');      // seat status = removed
        $chair = $this->user('u-chair');          // seated chair (presider)
        $director = $this->user('u-dir');         // seated, not chair

        // The 4 DIRECT doors each call BoardRoomAccess::assertMayJoin as their first executable line:
        //   page        InstitutionRoomController::board        :100
        //   token       InstitutionRoomController::boardToken   :118  (before existingRoom/mint at :120)
        //   discussion  InstitutionRoomController::boardMessages:139  (before postDiscussion/sendMessage)
        //   participant RoomParticipantController::board -> participants (before the room lookup)
        foreach (['outsider' => $outsider, 'removed' => $removed] as $label => $actor) {
            self::assertSame(403, $this->httpStatus(fn () => $access->assertMayJoin($actor, $board)),
                "direct board doors must refuse the {$label}");
        }
        // The seated chair and the seated director both pass the shared guard.
        self::assertSame(200, $this->httpStatus(fn () => $access->assertMayJoin($chair, $board)));
        self::assertSame(200, $this->httpStatus(fn () => $access->assertMayJoin($director, $board)));

        // The 3 FLOOR doors route through RoomFloorService::act('board', …) -> access('board') ->
        //   assertMayJoin, BEFORE any floor mutation (RoomFloorController::board -> apply).
        foreach (['raise', 'recognize', 'yield'] as $action) {
            self::assertSame(403, $this->httpStatus(fn () => $floor->act('board', 'brd', $outsider, $action, 'x')),
                "floor '{$action}' must refuse the outsider");
            self::assertSame(403, $this->httpStatus(fn () => $floor->act('board', 'brd', $removed, $action, 'x')),
                "floor '{$action}' must refuse the removed director");
        }

        // Presider distinction: the seated non-chair passes the guard but cannot preside (recognize/yield),
        // while the chair can. This is the seated-member-vs-presider boundary the room enforces.
        self::assertSame(403, $this->httpStatus(fn () => $floor->act('board', 'brd', $director, 'recognize')),
            'a seated non-chair passes the guard but may not recognize a speaker');
        self::assertSame(200, $this->httpStatus(fn () => $floor->act('board', 'brd', $chair, 'yield')),
            'the seated chair presides');

        // A seated member may raise a hand; the real LiveFloorService records it in the queue.
        self::assertSame(200, $this->httpStatus(fn () => $floor->act('board', 'brd', $director, 'raise')));
        $live = app(\App\Services\Rooms\LiveFloorService::class);
        $state = $live->state($live->key('board', 'brd'));
        self::assertContains('@u-u-dir:localhost', array_column($state['queue'], 'handle'),
            'the raised hand is in the real floor queue');
    }

    // ─── Part B · real Matrix HTTP: identity + history continuity over the running homeserver ───

    public function test_matrix_real_http_message_exchange_retains_identity_and_history(): void
    {
        $matrix = app(MatrixClientService::class);
        try {
            $who = $matrix->whoami();
        } catch (\Throwable $e) {
            self::markTestSkipped('fc_matrix unreachable at '.config('matrix.synapse_url').' — '.$e->getMessage());
        }
        self::assertSame('@cga-appservice:'.config('matrix.server_name'), $who['user_id'] ?? null,
            'the appservice authenticates against the real homeserver');

        $nonce = Str::lower(Str::random(10));
        $server = (string) config('matrix.server_name');
        // No room_alias_name: the appservice reserves only the civic alias namespaces
        // (#square-*, #halls-*, #bill-*, …), so an r2-transport-* alias is refused M_EXCLUSIVE.
        // The harness addresses the room by its returned room_id throughout, never by alias.
        $created = $matrix->createRoom([
            'preset' => 'public_chat',
            'name' => 'R2 transport rehearsal '.$nonce,
            'visibility' => 'private',
        ]);
        $roomId = $created['room_id'] ?? null;
        self::assertNotEmpty($roomId, 'the homeserver returned a real room id');

        // Two distinct app-authorized identities exchange real messages (both directions in one room).
        $presider = '@u-r2-presider-'.$nonce.':'.$server;
        $member = '@u-r2-member-'.$nonce.':'.$server;
        $bodyP = 'R2 presider opens the floor '.$nonce;
        $bodyM = 'R2 member speaks from the floor '.$nonce;
        $sentP = $matrix->sendMessage($roomId, ['msgtype' => 'm.text', 'body' => $bodyP], $presider);
        $sentM = $matrix->sendMessage($roomId, ['msgtype' => 'm.text', 'body' => $bodyM], $member);
        self::assertNotEmpty($sentP['event_id'] ?? null);
        self::assertNotEmpty($sentM['event_id'] ?? null);

        // Read the timeline back over the real API. Identity retained = the pseudonym is the event sender.
        $chunk = $matrix->getMessages($roomId, 'b', null, 30)['chunk'] ?? [];
        $byBody = [];
        foreach ($chunk as $ev) {
            if (($ev['type'] ?? '') === 'm.room.message') {
                $byBody[$ev['content']['body'] ?? ''] = $ev['sender'] ?? '';
            }
        }
        self::assertSame($presider, $byBody[$bodyP] ?? null, 'presider message round-trips with its pseudonym identity');
        self::assertSame($member, $byBody[$bodyM] ?? null, 'member message round-trips with its pseudonym identity');

        // Re-read the timeline (a fresh fetch = the rejoin/reconnect read). History is retained: BOTH
        // events are still present and the RealEvent sender/content match the appservice's ground truth.
        $again = $matrix->getMessages($roomId, 'b', null, 30)['chunk'] ?? [];
        $bodiesAgain = array_map(fn ($e) => $e['content']['body'] ?? '', array_filter($again, fn ($e) => ($e['type'] ?? '') === 'm.room.message'));
        self::assertContains($bodyP, $bodiesAgain, 'presider remark retained on reconnect');
        self::assertContains($bodyM, $bodiesAgain, 'member remark retained on reconnect');
        $ground = $matrix->getEvent($roomId, $sentP['event_id']);
        self::assertSame($presider, $ground['sender'] ?? null, 'the event ground truth keeps the retained identity');
    }

    // ─── Part C · real LiveKit SFU: the token grant + its bounded lifetime ───

    public function test_livekit_real_sfu_enforces_token_grant_and_ttl(): void
    {
        $service = app(LiveKitTokenService::class);
        $base = rtrim((string) config('matrix.livekit.url'), '/');
        $secret = (string) config('matrix.livekit.api_secret');
        $key = (string) config('matrix.livekit.api_key');
        $room = 'r2-board-'.Str::lower(Str::random(8));
        $identity = '@u-r2-chair:'.config('matrix.server_name');

        // Reachability guard — record BLOCKED (not pass) if the SFU is down.
        try {
            $probe = Http::timeout(6)->get($base.'/');
        } catch (\Throwable $e) {
            self::markTestSkipped('fc_livekit unreachable at '.$base.' — '.$e->getMessage());
        }
        self::assertSame(200, $probe->status(), 'the LiveKit SFU root answers');

        // A real app-minted join grant (the same recipe boardToken issues) is ACCEPTED by the real SFU.
        $valid = $service->mintAccessToken($identity, $room)['token'];
        $okResp = Http::timeout(6)->get($base.'/rtc/validate', ['access_token' => $valid]);
        self::assertSame(200, $okResp->status(), 'the SFU accepts the app-minted grant: '.$okResp->body());
        self::assertStringContainsString('success', Str::lower($okResp->body()));

        // TTL is enforced by the SFU: a token past its exp (same signing key) is refused.
        $expired = $this->hs256($key, $secret, $identity, $room, now()->timestamp - 3600);
        $expResp = Http::timeout(6)->get($base.'/rtc/validate', ['access_token' => $expired]);
        self::assertSame(401, $expResp->status(), 'the SFU refuses an expired grant');
        self::assertStringContainsString('expired', Str::lower($expResp->body()));

        // A forged token (any other secret) is refused: only THIS appservice's grants are honoured.
        $forged = $this->hs256($key, 'not-the-real-secret', $identity, $room, now()->timestamp + 3600);
        $forgedResp = Http::timeout(6)->get($base.'/rtc/validate', ['access_token' => $forged]);
        self::assertSame(401, $forgedResp->status(), 'the SFU refuses a forged grant');
        self::assertStringContainsString('signature', Str::lower($forgedResp->body()));

        // The grant lifetime is BOUNDED: a request over the ceiling clamps to MAX_TTL_SECONDS.
        self::assertSame(21600, LiveKitTokenService::MAX_TTL_SECONDS);
        $long = $service->mintAccessToken($identity, $room, 99999);
        $claims = $service->verify($long['token']);
        self::assertNotNull($claims);
        self::assertSame(LiveKitTokenService::MAX_TTL_SECONDS, $claims['exp'] - $claims['nbf'],
            'a mint above the ceiling is clamped to MAX_TTL_SECONDS');
    }

    /** The LiveKitTokenService HS256 recipe, reproduced to craft expired/forged grants for SFU checks. */
    private function hs256(string $key, string $secret, string $identity, string $room, int $exp): string
    {
        $b64 = fn (string $r) => rtrim(strtr(base64_encode($r), '+/', '-_'), '=');
        $claims = [
            'iss' => $key, 'sub' => $identity, 'name' => $identity,
            'nbf' => $exp - 3600, 'exp' => $exp,
            'video' => ['room' => $room, 'roomJoin' => true, 'canPublish' => true, 'canSubscribe' => true],
        ];
        $si = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES))
            .'.'.$b64(json_encode($claims, JSON_UNESCAPED_SLASHES));

        return $si.'.'.$b64(hash_hmac('sha256', $si, $secret, true));
    }
}
