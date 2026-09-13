<?php

namespace Tests\Unit;

use App\Http\Controllers\Matrix\VoiceReachController;
use App\Http\Controllers\Rooms\InstitutionRoomController;
use App\Http\Controllers\Rooms\RoomFloorController;
use App\Http\Controllers\Rooms\RoomParticipantController;
use App\Models\Board;
use App\Models\CourtCase;
use App\Models\Legislature;
use App\Models\User;
use App\Services\Federation\MultiplexClient;
use App\Services\Federation\ServiceReachService;
use App\Services\Identity\AttestationService;
use App\Services\Matrix\LiveKitTokenService;
use App\Services\Matrix\MatrixClientService;
use App\Services\Matrix\MatrixIdentityProvisioner;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\Matrix\PublicVoiceRoomAccess;
use App\Services\Matrix\SocialTopologyReconcilerService;
use App\Services\Matrix\VoiceReachService;
use App\Services\RoleService;
use App\Services\Rooms\BoardRoomAccess;
use App\Services\Rooms\LiveFloorService;
use App\Services\Rooms\PublicRoomNames;
use App\Services\Rooms\RoomFloorService;
use App\Services\Rooms\RoomParticipantRoster;
use Illuminate\Cache\ArrayStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as OutboundRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Combined real application room boundaries; transport is an explicit in-memory protocol fixture. */
final class RoomWorkflowIntegrationTest extends TestCase
{
    private string $original;
    private string $originalCache;
    private InstitutionRoomController $rooms;
    private RoomFloorController $floor;
    private RoomParticipantController $participants;
    private VoiceReachService $voice;
    private LiveKitTokenService $tokens;
    private MatrixPostingGateService $posting;
    private MatrixIdentityProvisioner $identities;
    private array $registered = [];
    private array $invited = [];
    private array $joined = [];
    private array $messages = [];
    private array $transport = [];
    private array $snapshots = [];

    protected function setUp(): void
    {
        parent::setUp(); Bus::fake();
        $this->original = DB::getDefaultConnection(); $this->originalCache = Cache::getDefaultDriver();
        config([
            'database.connections.room_workflow_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'cache.stores.room_workflow_fixture' => ['driver' => 'array', 'serialize' => false],
            'matrix.server_name' => 'room-workflow.invalid', 'matrix.synapse_url' => 'https://room-workflow.invalid',
            'matrix.appservice.as_token' => 'synthetic-matrix-test-token',
            'matrix.livekit.api_key' => 'synthetic-livekit-key', 'matrix.livekit.api_secret' => 'synthetic-livekit-secret-not-a-deployment-secret',
            'matrix.livekit.public_url' => 'wss://sfu.room-workflow.invalid',
        ]);
        DB::setDefaultConnection('room_workflow_fixture'); Cache::setDefaultDriver('room_workflow_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName()); self::assertSame(':memory:', DB::connection()->getDatabaseName());
        self::assertInstanceOf(ArrayStore::class, Cache::store()->getStore());
        $this->schema(); $this->seedFixture(); $this->fakeTransport();
        $client = new MatrixClientService();
        // Identity helpers do not use role derivation. Unexpected role-engine work must fail.
        $roles = $this->createMock(RoleService::class); $roles->expects(self::never())->method('rolesFor');
        $this->posting = new MatrixPostingGateService($roles, $client);
        $this->identities = new MatrixIdentityProvisioner($this->posting); app()->instance(MatrixIdentityProvisioner::class, $this->identities);
        $names = new PublicRoomNames(); $boards = new BoardRoomAccess(); $public = new PublicVoiceRoomAccess($this->posting);
        $floor = new LiveFloorService();
        app()->instance(RoomFloorService::class, new RoomFloorService($floor, $this->posting, $names, $boards));
        // Rooms already exist only in this fixture. No topology provisioning may run.
        $topology = $this->createMock(SocialTopologyReconcilerService::class);
        foreach (['reconcileLegislature', 'reconcileCase', 'reconcileBoard'] as $method) $topology->expects(self::never())->method($method);
        $this->rooms = new InstitutionRoomController($topology, $this->posting, $client, $names, $floor, $boards, $public);
        $this->floor = new RoomFloorController(); $this->participants = new RoomParticipantController(new RoomParticipantRoster($names), $boards, $public);
        $this->tokens = new LiveKitTokenService($this->posting, $names);
        // Deliberately stop at a local synthetic SFU pointer; no peer/identity keys are read.
        $reach = $this->createMock(ServiceReachService::class);
        $reach->method('reachLiveService')->willReturnCallback(function (string $capability, string $place): array {
            self::assertSame('voice.sfu', $capability); self::assertSame($this->id('place'), $place); return ['local' => true];
        });
        $attestations = $this->createMock(AttestationService::class); $attestations->expects(self::never())->method('issue');
        $mux = $this->createMock(MultiplexClient::class); $mux->expects(self::never())->method('reach');
        $this->voice = new VoiceReachService($reach, $attestations, $this->tokens, $this->posting, $mux, $public);
    }

    protected function tearDown(): void
    {
        DB::purge('room_workflow_fixture'); DB::setDefaultConnection($this->original);
        Cache::forgetDriver('room_workflow_fixture'); Cache::setDefaultDriver($this->originalCache);
        parent::tearDown();
    }

    private function id(string $key): string { return sprintf('70000000-0000-4000-8000-%012d', crc32($key)); }
    private function mx(string $actor): string { return '@u-'.$actor.':room-workflow.invalid'; }
    private function room(string $kind): string { return '!'.$kind.':room-workflow.invalid'; }
    private function user(string $key): User { return User::query()->findOrFail($this->id($key)); }
    private function institution(string $kind): Legislature|CourtCase|Board
    {
        return (match ($kind) { 'chamber' => Legislature::query(), 'court' => CourtCase::query(), 'board' => Board::query() })->findOrFail($this->id($kind));
    }
    private function request(string $actor, array $data = [], string $method = 'POST'): Request
    {
        $r = Request::create('/rooms/fixture', $method, $data); $r->setUserResolver(fn () => $this->user($actor)); return $r;
    }
    private function act(string $kind, string $actor, string $action, ?string $target = null): void
    {
        $response = $this->floor->$kind($this->request($actor, ['action' => $action, 'handle' => $target]), $this->institution($kind));
        self::assertSame(302, $response->getStatusCode());
    }
    private function page(string $kind, string $actor): array
    {
        $response = $this->rooms->$kind($this->request($actor, method: 'GET'), $this->institution($kind));
        return (new \ReflectionProperty($response, 'props'))->getValue($response);
    }
    private function roster(string $kind, string $actor, array $names): array
    {
        return $this->participants->$kind($this->request($actor, ['handles' => array_map($this->mx(...), $names), 'role' => 'chair', 'room_id' => $this->room('other')]), $this->institution($kind))->getData(true);
    }
    private function send(string $kind, string $actor, string $body): void
    {
        $method = $kind.'Messages';
        $response = $this->rooms->$method($this->request($actor, ['body' => $body, 'room' => $this->room('other')]), $this->institution($kind));
        self::assertSame(302, $response->getStatusCode()); self::assertFalse(session()->has('errors'));
    }
    private function token(string $kind, string $actor): array
    {
        if ($kind === 'board') {
            $response = $this->rooms->boardToken($this->request($actor, ['room' => $this->room('other')]), $this->institution('board'), $this->tokens);
        } else {
            $response = (new VoiceReachController())($this->request($actor, [
                'jurisdiction_id' => $this->id('place'), 'room' => $this->room($kind),
                'device_public_key' => 'synthetic-device', 'action_signature' => 'synthetic-local-proof-unused', 'timestamp' => time(),
            ]), $this->voice);
        }
        self::assertSame(200, $response->getStatusCode()); $data = $response->getData(true); $claims = $this->tokens->verify($data['token']);
        self::assertNotNull($claims); self::assertSame($this->mx($actor), $claims['sub']); self::assertSame('Public '.$actor, $claims['name']);
        self::assertSame($this->room($kind), $claims['video']['room']); self::assertTrue($claims['video']['roomJoin']);
        self::assertArrayNotHasKey('roomAdmin', $claims['video']); self::assertArrayNotHasKey('roomRecord', $claims['video']);
        self::assertLessThanOrEqual(LiveKitTokenService::MAX_TTL_SECONDS, $claims['exp'] - $claims['nbf']);
        self::assertStringNotContainsString('SECRET', json_encode($data));
        return ['identity' => $data['identity'], 'name' => $claims['name'], 'room' => $data['room']]; // Never export token/signature.
    }
    private function snapshot(string $name, string $kind, string $actor, array $connected): array
    {
        $page = $this->page($kind, $actor); $roster = $this->roster($kind, $actor, $connected);
        self::assertSame($this->room($kind), $roster['roomId']); self::assertSame($this->room($kind), $page['voice']['roomId']);
        self::assertStringNotContainsString('SECRET', json_encode($page)); self::assertStringNotContainsString('SECRET', json_encode($roster));
        return $this->snapshots[$name] = ['variant' => $page['variant'], 'roster' => $page['roster'], 'connectedRoster' => $roster['roster'], 'floorHolder' => $page['floorHolder'],
            'activeWitness' => $page['activeWitness'], 'displayNames' => $page['displayNames'], 'connected' => array_map($this->mx(...), $connected)];
    }

    public function test_presider_member_witness_and_private_board_share_the_same_identity_floor_and_read_contracts(): void
    {
        // No identity rows exist before first action; each real provisioner inserts into the private DB.
        self::assertSame(0, DB::table('matrix_identities')->count());
        $this->token('chamber', 'speaker'); $member = $this->token('chamber', 'member');
        $this->send('chamber', 'speaker', 'Welcome to the synthetic chamber.'); $this->send('chamber', 'member', 'Ready to speak.');
        $this->act('chamber', 'member', 'raise', $this->mx('speaker'));
        $queued = $this->page('chamber', 'member')['floorControls'];
        self::assertTrue($queued['myHandRaised']); self::assertFalse($queued['canPreside']);
        self::assertSame([['handle' => $member['identity'], 'display_name' => 'Public member']], $queued['queue']);
        $this->denied(fn () => $this->act('chamber', 'member', 'recognize'));
        $this->act('chamber', 'speaker', 'recognize', $member['identity']);
        $speaking = $this->snapshot('chamber_speaking', 'chamber', 'speaker', ['speaker', 'member']);
        self::assertSame(['speaker', 'legislator'], array_column($speaking['roster'], 'role'));
        self::assertSame($member['identity'], $speaking['floorHolder']);
        $this->act('chamber', 'speaker', 'yield');

        $judge = $this->token('court', 'judge'); $witness = $this->token('court', 'witness');
        $this->send('court', 'judge', 'Synthetic hearing discussion.'); $this->send('court', 'witness', 'Ready for the witness position.');
        $this->act('court', 'witness', 'raise'); $this->act('court', 'judge', 'witness', $witness['identity']);
        $stand = $this->snapshot('court_witness', 'court', 'judge', ['judge', 'witness']);
        self::assertSame(['presiding_judge', 'claimant'], array_column($stand['roster'], 'role'));
        self::assertSame($witness['identity'], $stand['activeWitness']);
        $this->act('court', 'judge', 'raise'); $this->act('court', 'judge', 'recognize', $judge['identity']);
        $questioning = $this->snapshot('court_questioning', 'court', 'judge', ['judge', 'witness']);
        self::assertSame($judge['identity'], $questioning['floorHolder']); self::assertSame($witness['identity'], $questioning['activeWitness']);

        // Separate requests represent leaving/reopening the application. This is NOT SFU transport.
        $this->snapshot('court_disconnected', 'court', 'judge', ['judge']);
        $identityBefore = DB::table('matrix_identities')->where('user_id', $this->id('witness'))->first();
        unset($this->joined[$this->room('court')][$witness['identity']]);
        self::assertSame($witness, $this->token('court', 'witness'));
        $this->send('court', 'witness', 'Returned to the synthetic hearing.');
        $rejoined = $this->snapshot('court_rejoined', 'court', 'witness', ['judge', 'witness']);
        self::assertSame($questioning['floorHolder'], $rejoined['floorHolder']); self::assertSame($questioning['activeWitness'], $rejoined['activeWitness']);
        self::assertEquals($identityBefore, DB::table('matrix_identities')->where('user_id', $this->id('witness'))->first());
        self::assertSame(['Synthetic hearing discussion.', 'Ready for the witness position.', 'Returned to the synthetic hearing.'], array_column($this->page('court', 'witness')['messages'], 'body'));
        $this->act('court', 'judge', 'yield'); $yielded = $this->snapshot('court_yielded', 'court', 'judge', ['judge', 'witness']);
        self::assertNull($yielded['activeWitness']); self::assertNull($yielded['floorHolder']);
        self::assertSame('paneled', DB::table('cases')->where('id', $this->id('court'))->value('status'), 'Choreography must not file testimony or advance the case.');

        $this->token('board', 'chair'); $director = $this->token('board', 'director');
        $this->send('board', 'chair', 'Private board discussion.'); $this->send('board', 'director', 'Member contribution.');
        self::assertSame([$this->mx('chair'), $this->mx('director')], array_keys($this->invited[$this->room('board')]));
        $this->act('board', 'director', 'raise'); $this->denied(fn () => $this->act('board', 'director', 'recognize'));
        $this->act('board', 'chair', 'recognize'); $board = $this->snapshot('board_speaking', 'board', 'chair', ['chair', 'director']);
        self::assertSame(['chair', 'board_member'], array_column($board['roster'], 'role')); self::assertSame($director['identity'], $board['floorHolder']);
        self::assertSame($director, $this->token('board', 'director'));
        self::assertSame(['Private board discussion.', 'Member contribution.'], array_column($this->page('board', 'director')['messages'], 'body'));
        $this->act('board', 'chair', 'yield');
        self::assertSame(6, DB::table('matrix_identities')->count()); Bus::assertNothingDispatched();
        $this->exportSnapshots();
    }

    public function test_unauthorized_and_removed_board_members_are_denied_at_every_application_door_before_transport(): void
    {
        $this->token('board', 'director'); $this->send('board', 'director', 'Before removal.');
        DB::table('board_seats')->where('id', $this->id('director-seat'))->update(['status' => 'removed']);
        $before = count($this->transport);
        $guest = Request::create('/rooms/fixture'); $guest->setUserResolver(fn () => null);
        $this->denied(fn () => $this->rooms->board($guest, $this->institution('board')));
        $this->denied(fn () => $this->rooms->boardToken($guest, $this->institution('board'), $this->tokens));
        foreach (['outsider', 'director'] as $actor) {
            $this->denied(fn () => $this->page('board', $actor));
            $this->denied(fn () => $this->token('board', $actor));
            $this->denied(fn () => $this->send('board', $actor, 'Must not send.'));
            $this->denied(fn () => $this->roster('board', $actor, ['chair']));
            foreach (['raise', 'recognize', 'yield'] as $action) $this->denied(fn () => $this->act('board', $actor, $action));
        }
        self::assertSame($before, count($this->transport));
        self::assertSame(0, DB::table('matrix_identities')->where('user_id', $this->id('outsider'))->count());
        $public = (new VoiceReachController())($this->request('outsider', ['jurisdiction_id' => $this->id('place'), 'room' => $this->room('board'),
            'device_public_key' => 'fixture', 'action_signature' => 'fixture', 'timestamp' => time()]), $this->voice);
        self::assertSame(403, $public->getStatusCode()); self::assertArrayNotHasKey('token', $public->getData(true));
        self::assertSame(1, count($this->messages[$this->room('board')]));
    }

    public function test_stale_presiders_and_wrong_room_targets_cannot_control_other_participants(): void
    {
        $this->token('chamber', 'speaker');
        self::assertSame('speaker', $this->roster('chamber', 'member', ['speaker'])['roster'][0]['role']);
        $this->act('chamber', 'member', 'raise');
        try { $this->act('court', 'judge', 'witness', $this->mx('member')); self::fail('A chamber hand cannot become a court witness.'); }
        catch (ValidationException $e) { self::assertArrayHasKey('floor', $e->errors()); }
        DB::table('legislature_members')->where('id', $this->id('speaker-seat'))->update(['status' => 'term_ended']);
        $this->denied(fn () => $this->act('chamber', 'speaker', 'recognize'));
        self::assertFalse($this->page('chamber', 'speaker')['floorControls']['canPreside']);
        self::assertSame('guest', $this->roster('chamber', 'member', ['speaker'])['roster'][0]['role']);
        DB::table('panel_judges')->where('id', $this->id('judge-seat'))->update(['screening_result' => 'recused']);
        $this->denied(fn () => $this->act('court', 'judge', 'yield'));
        DB::table('cases')->where('id', $this->id('court'))->update(['status' => 'closed']);
        $this->denied(fn () => $this->act('court', 'witness', 'raise'));
        self::assertSame([], $this->page('court', 'judge')['floorControls']['queue']);
        self::assertNull($this->page('court', 'judge')['activeWitness']);
    }

    private function denied(callable $action): void
    {
        try { $action(); self::fail('Expected current exact-office refusal.'); }
        catch (HttpException $e) { self::assertSame(403, $e->getStatusCode()); }
    }

    private function exportSnapshots(): void
    {
        $nonce = getenv('ROOM_WORKFLOW_SNAPSHOT_ID'); if ($nonce === false || $nonce === '') return;
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $nonce);
        $directory = storage_path('framework/testing'); if (! is_dir($directory)) mkdir($directory, 0700, true);
        $path = $directory.'/r2-room-workflow-'.$nonce.'.json'; self::assertFileDoesNotExist($path);
        $data = ['fixture' => 'room-workflow-private-v1', 'snapshots' => $this->snapshots];
        self::assertStringNotContainsString('SECRET', json_encode($data));
        self::assertNotFalse(file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)));
    }

    private function schema(): void
    {
        $tables = [
            'users' => ['name', 'email', 'display_name'], 'social_profiles' => ['user_id', 'handle', 'display_name', 'visibility'],
            'matrix_identities' => ['user_id', 'matrix_user_id', 'matrix_localpart'],
            'legislatures' => ['jurisdiction_id', 'speaker_id', 'status'], 'legislature_members' => ['legislature_id', 'user_id', 'status', 'seat_no'],
            'cases' => ['jurisdiction_id', 'advocate_id', 'title', 'status'], 'panels' => ['case_id', 'status'],
            'panel_judges' => ['panel_id', 'user_id', 'status', 'screening_result', 'is_presiding'],
            'case_parties' => ['case_id', 'party_user_id', 'party_role', 'status', 'represented_by_advocate_id'],
            'advocates' => ['user_id', 'status'], 'juries' => ['case_id', 'status'], 'jury_members' => ['jury_id', 'user_id', 'screening_status', 'seat_no'],
            'boards' => ['boardable_type', 'boardable_id', 'chair_seat_id', 'status'], 'board_seats' => ['board_id', 'holder_user_id', 'status', 'seat_no'],
            'organizations' => ['jurisdiction_id'], 'jurisdictions' => ['name', 'slug', 'adm_level', 'parent_id'],
            'matrix_rooms' => ['entity_type', 'entity_id', 'matrix_room_id', 'room_type', 'space_type', 'is_public', 'is_encrypted', 'tombstoned_at'],
        ];
        foreach ($tables as $table => $columns) DB::connection()->getSchemaBuilder()->create($table, function (Blueprint $t) use ($table, $columns): void {
            $t->string('id')->primary(); foreach ($columns as $column) $t->string($column)->nullable(); $t->timestamps(); $t->softDeletes();
            if ($table === 'matrix_identities') { $t->unique('user_id'); $t->unique('matrix_localpart'); $t->unique('matrix_user_id'); }
        });
    }

    private function seedFixture(): void
    {
        foreach (['speaker', 'member', 'judge', 'witness', 'chair', 'director', 'outsider'] as $actor) {
            $this->row('users', $actor, ['display_name' => 'Public '.$actor, 'name' => 'SECRET legal '.$actor, 'email' => 'SECRET@fixture.invalid']);
            $this->row('social_profiles', $actor, ['user_id' => $this->id($actor), 'handle' => $actor, 'display_name' => 'Other public '.$actor, 'visibility' => 'public']);
        }
        $this->row('jurisdictions', 'place', ['name' => 'Synthetic place', 'slug' => 'synthetic-place', 'adm_level' => '1']);
        $this->row('legislatures', 'chamber', ['jurisdiction_id' => $this->id('place'), 'speaker_id' => $this->id('speaker-seat'), 'status' => 'active']);
        foreach (['speaker', 'member'] as $n => $actor) $this->row('legislature_members', $actor.'-seat', ['legislature_id' => $this->id('chamber'), 'user_id' => $this->id($actor), 'status' => 'seated', 'seat_no' => (string) ($n + 1)]);
        $this->row('cases', 'court', ['jurisdiction_id' => $this->id('place'), 'title' => 'Synthetic case', 'status' => 'paneled']);
        $this->row('panels', 'panel', ['case_id' => $this->id('court'), 'status' => 'seated']);
        $this->row('panel_judges', 'judge-seat', ['panel_id' => $this->id('panel'), 'user_id' => $this->id('judge'), 'status' => 'seated', 'screening_result' => 'cleared', 'is_presiding' => '1']);
        $this->row('case_parties', 'party', ['case_id' => $this->id('court'), 'party_user_id' => $this->id('witness'), 'party_role' => 'plaintiff', 'status' => 'active']);
        $this->row('organizations', 'organization', ['jurisdiction_id' => $this->id('place')]);
        $this->row('boards', 'board', ['boardable_type' => 'organizations', 'boardable_id' => $this->id('organization'), 'chair_seat_id' => $this->id('chair-seat'), 'status' => 'active']);
        foreach (['chair', 'director'] as $n => $actor) $this->row('board_seats', $actor.'-seat', ['board_id' => $this->id('board'), 'holder_user_id' => $this->id($actor), 'status' => 'seated', 'seat_no' => (string) ($n + 1)]);
        $this->row('board_seats', 'other-seat', ['board_id' => $this->id('other-board'), 'holder_user_id' => $this->id('outsider'), 'status' => 'seated', 'seat_no' => '1']);
        foreach (['chamber' => 'legislature', 'court' => 'case', 'board' => 'board'] as $kind => $type) {
            $this->row('matrix_rooms', $kind.'-room', ['entity_type' => $type, 'entity_id' => $this->id($kind), 'matrix_room_id' => $this->room($kind),
                'room_type' => $kind === 'board' ? 'org_private' : 'institution', 'is_public' => $kind === 'board' ? '0' : '1', 'is_encrypted' => '0']);
            $this->messages[$this->room($kind)] = [];
        }
    }
    private function row(string $table, string $key, array $data): void { DB::table($table)->insert(['id' => $this->id($key), ...$data]); }

    private function fakeTransport(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (OutboundRequest $request) {
            self::assertSame('room-workflow.invalid', parse_url($request->url(), PHP_URL_HOST));
            self::assertSame('Bearer synthetic-matrix-test-token', $request->header('Authorization')[0] ?? null);
            $path = rawurldecode(parse_url($request->url(), PHP_URL_PATH)); parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $sender = $query['user_id'] ?? null; $this->transport[] = [$request->method(), $path, $sender];
            if ($path === '/_matrix/client/v3/register') {
                self::assertTrue($request['inhibit_login']); self::assertSame('m.login.application_service', $request['type']);
                $mxid = '@'.$request['username'].':room-workflow.invalid';
                if (isset($this->registered[$mxid])) return Http::response(['errcode' => 'M_USER_IN_USE'], 400);
                $this->registered[$mxid] = true; return Http::response(['user_id' => $mxid]);
            }
            if (! preg_match('~^/_matrix/client/v3/rooms/(![^/]+)/(.+)$~', $path, $match)) throw new \LogicException('Unexpected fixture endpoint');
            [$all, $room, $operation] = $match; self::assertArrayHasKey($room, $this->messages);
            if ($operation === 'messages') {
                self::assertSame('GET', $request->method()); self::assertSame('30', (string) $query['limit']);
                return Http::response(['chunk' => array_reverse($this->messages[$room])]);
            }
            if ($operation === 'state/m.room.join_rules') return Http::response(['join_rule' => $room === $this->room('board') ? 'invite' : 'public']);
            if ($operation === 'invite') {
                self::assertNull($sender); self::assertSame($this->room('board'), $room);
                self::assertContains($request['user_id'], [$this->mx('chair'), $this->mx('director')]);
                $this->invited[$room][$request['user_id']] = true; return Http::response([]);
            }
            if ($operation === 'join') {
                self::assertArrayHasKey($sender, $this->registered);
                if ($room === $this->room('board') && ! isset($this->invited[$room][$sender])) return Http::response(['errcode' => 'M_FORBIDDEN'], 403);
                $this->joined[$room][$sender] = true; return Http::response(['room_id' => $room]);
            }
            if (str_starts_with($operation, 'send/m.room.message/')) {
                self::assertTrue($this->joined[$room][$sender] ?? false);
                $event = '$synthetic-'.(count($this->messages[$room]) + 1);
                $this->messages[$room][] = ['event_id' => $event, 'type' => 'm.room.message', 'sender' => $sender, 'content' => $request->data(), 'origin_server_ts' => 1];
                return Http::response(['event_id' => $event]);
            }
            throw new \LogicException('Unexpected fixture Matrix operation');
        });
    }
}
