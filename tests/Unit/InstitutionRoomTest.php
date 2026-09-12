<?php

namespace Tests\Unit;

use App\Http\Controllers\Rooms\InstitutionRoomController;
use App\Models\Board;
use App\Models\Legislature;
use App\Models\MatrixIdentity;
use App\Models\User;
use App\Services\Matrix\LiveKitTokenService;
use App\Services\Matrix\MatrixClientService;
use App\Services\Matrix\MatrixIdentityProvisioner;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\Matrix\PublicVoiceRoomAccess;
use App\Services\Matrix\SocialTopologyReconcilerService;
use App\Services\Rooms\BoardRoomAccess;
use App\Services\Rooms\LiveFloorService;
use App\Services\Rooms\PublicRoomNames;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Explicit memory-only fixtures and mocked Matrix transport; never provisions a live room. */
final class InstitutionRoomTest extends TestCase
{
    private string $original;
    private $matrix;
    private $posting;
    private $topology;
    private $names;
    private $provisioner;
    private InstitutionRoomController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.institution_room_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('institution_room_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('board_seats', function (Blueprint $t): void {
            foreach (['id', 'board_id', 'holder_user_id', 'status'] as $column) $t->string($column);
            $t->integer('seat_no')->nullable();
            $t->softDeletes();
        });
        $schema->create('matrix_rooms', function (Blueprint $t): void {
            $t->string('id')->primary();
            foreach (['matrix_room_id', 'entity_type', 'entity_id', 'room_type', 'space_type'] as $column) $t->string($column)->nullable();
            $t->boolean('is_public')->default(false);
            $t->boolean('is_encrypted')->default(false);
            $t->timestamp('tombstoned_at')->nullable();
            $t->softDeletes();
        });
        $schema->create('legislature_members', function (Blueprint $t): void {
            foreach (['id', 'legislature_id', 'user_id', 'status'] as $column) $t->string($column);
            $t->integer('seat_no')->nullable();
            $t->softDeletes();
        });
        $schema->create('legislatures', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->string('jurisdiction_id')->nullable();
            $t->softDeletes();
        });
        $this->matrix = $this->createMock(MatrixClientService::class);
        $this->posting = $this->createMock(MatrixPostingGateService::class);
        $this->topology = $this->createMock(SocialTopologyReconcilerService::class);
        $this->names = $this->createMock(PublicRoomNames::class);
        $this->provisioner = $this->createMock(MatrixIdentityProvisioner::class);
        app()->instance(MatrixIdentityProvisioner::class, $this->provisioner);
        $floor = $this->createMock(LiveFloorService::class);
        $floor->method('key')->willReturn('fixture-floor');
        $floor->method('state')->willReturn(['floorHolder' => null, 'queue' => [], 'speaking' => null]);
        $this->controller = new InstitutionRoomController($this->topology, $this->posting, $this->matrix,
            $this->names, $floor, new BoardRoomAccess(), new PublicVoiceRoomAccess($this->posting));
    }

    protected function tearDown(): void
    {
        DB::purge('institution_room_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    public function test_private_board_page_rejects_guests_former_members_and_members_of_other_boards_before_matrix(): void
    {
        $this->topology->expects($this->never())->method('reconcileBoard');
        $this->matrix->expects($this->never())->method('getMessages');
        $this->provisioner->expects($this->never())->method('ensureFor');
        $this->seat('former', 'board', 'former', 'term_ended');
        $this->seat('other', 'different-board', 'other');
        foreach ([null, 'outsider', 'former', 'other'] as $id) {
            try {
                $this->controller->board($this->request($id), $this->board());
                self::fail('Only a current seat on this exact board may view its room.');
            } catch (HttpException $error) { self::assertSame(403, $error->getStatusCode()); }
        }
    }

    public function test_board_token_uses_derived_room_and_current_member_identity(): void
    {
        $this->seat('chair', 'board', 'member');
        $this->room('board', 'board', false);
        $this->provisioner->expects($this->once())->method('ensureFor')->willReturn((new MatrixIdentity())->forceFill(['matrix_user_id' => '@member:fixture']));
        $tokens = $this->createMock(LiveKitTokenService::class);
        $tokens->expects($this->once())->method('mintAccessToken')->with('@member:fixture', '!board:fixture')
            ->willReturn(['token' => 'fixture-token', 'url' => 'wss://fixture.invalid', 'identity' => '@member:fixture', 'room' => '!board:fixture']);
        $request = $this->request('member', ['room_id' => '!other-private:fixture']);
        $result = $this->controller->boardToken($request, $this->board(), $tokens)->getData(true);
        self::assertSame('!board:fixture', $result['room']);
        self::assertSame('wss://fixture.invalid', $result['sfu_url']);
    }

    public function test_board_token_refuses_public_encrypted_tombstoned_or_dissolved_targets(): void
    {
        $this->seat('chair', 'board', 'member');
        $this->room('board', 'board', false);
        $tokens = $this->createMock(LiveKitTokenService::class);
        $tokens->expects($this->never())->method('mintAccessToken');
        $this->provisioner->expects($this->never())->method('ensureFor');
        foreach ([['is_public' => true], ['is_encrypted' => true], ['tombstoned_at' => now()], ['room_type' => 'user_private']] as $change) {
            DB::table('matrix_rooms')->update(array_merge(['is_public' => false, 'is_encrypted' => false, 'tombstoned_at' => null, 'room_type' => 'org_private'], $change));
            try {
                $this->controller->boardToken($this->request('member'), $this->board(), $tokens);
                self::fail('A mismatched private room must be refused.');
            } catch (HttpException $error) { self::assertSame(403, $error->getStatusCode()); }
        }
        $board = $this->board();
        $board->status = Board::STATUS_DISSOLVED;
        self::assertFalse((new BoardRoomAccess())->allows($this->request('member')->user(), $board));
    }

    public function test_guest_chamber_preview_reuses_existing_room_and_shows_only_chosen_names_and_actual_roles(): void
    {
        $this->room('legislature', 'chamber', true);
        DB::table('legislature_members')->insert([
            ['id' => 'speaker-seat', 'legislature_id' => 'chamber', 'user_id' => 'speaker-person', 'status' => 'seated', 'seat_no' => 1],
            ['id' => 'old-seat', 'legislature_id' => 'chamber', 'user_id' => 'old-person', 'status' => 'term_ended', 'seat_no' => 2],
        ]);
        $this->topology->expects($this->never())->method('reconcileLegislature');
        $this->posting->expects($this->once())->method('matrixUserIdsFor')->with(['speaker-person'])->willReturn(['speaker-person' => '@speaker:fixture']);
        $this->posting->expects($this->never())->method('matrixUserId');
        $this->names->method('forUsers')->willReturn(['speaker-person' => 'Chosen public name']);
        $this->names->method('forHandles')->willReturn([]);
        $this->matrix->expects($this->once())->method('getMessages')->with('!chamber:fixture', 'b', null, 30)->willReturn(['chunk' => []]);
        $legislature = (new Legislature())->forceFill(['id' => 'chamber', 'speaker_id' => 'speaker-seat', 'jurisdiction_id' => null]);
        $response = $this->controller->chamber($this->request(null), $legislature);
        $props = (new \ReflectionProperty($response, 'props'))->getValue($response);
        self::assertSame([['handle' => '@speaker:fixture', 'display_name' => 'Chosen public name', 'role' => 'speaker', 'seat' => 1]], $props['roster']);
        self::assertNull($props['voice']['myUserId']);
        self::assertTrue($props['timelineAvailable']);
        self::assertStringNotContainsString('speaker-person', json_encode($props));
        self::assertSame('/legislatures/chamber/session', $props['recordHref']);
    }

    public function test_missing_room_is_not_provisioned_by_partial_refresh_prefetch_or_head(): void
    {
        $this->topology->expects($this->never())->method('reconcileLegislature');
        $this->matrix->expects($this->never())->method('getMessages');
        $legislature = (new Legislature())->forceFill(['id' => 'chamber', 'jurisdiction_id' => null]);
        foreach ([
            ['GET', ['X-Inertia-Partial-Data' => 'voice']],
            ['GET', ['X-Inertia-Partial-Component' => 'Rooms/Institution']],
            ['GET', ['Purpose' => 'prefetch']],
            ['GET', ['Sec-Purpose' => 'prefetch;prerender']],
            ['HEAD', []],
        ] as [$method, $headers]) {
            $request = $this->request(null);
            $request->setMethod($method);
            $request->headers->add($headers);
            $response = $this->controller->chamber($request, $legislature);
            $props = (new \ReflectionProperty($response, 'props'))->getValue($response);
            self::assertNull($props['voice']['roomId']);
            self::assertSame('/rooms/chamber/chamber', $props['roomHref']);
        }
    }

    public function test_explicit_full_visit_retries_only_the_selected_missing_room(): void
    {
        $legislature = (new Legislature())->forceFill(['id' => 'chamber', 'jurisdiction_id' => null]);
        $this->topology->expects($this->once())->method('reconcileLegislature')->with($legislature)->willReturn(null);
        $request = $this->request(null);
        $request->setMethod('GET');
        $response = $this->controller->chamber($request, $legislature);
        $props = (new \ReflectionProperty($response, 'props'))->getValue($response);
        self::assertNull($props['voice']['roomId']);
        self::assertFalse($props['timelineAvailable']);
    }

    public function test_board_discussion_rechecks_membership_and_never_accepts_a_supplied_room_id(): void
    {
        $this->seat('member-seat', 'board', 'member');
        $this->room('board', 'board', false);
        $this->provisioner->expects($this->once())->method('ensureFor')->willReturn((new MatrixIdentity())->forceFill(['matrix_user_id' => '@member:fixture']));
        $this->matrix->expects($this->once())->method('sendMessage')
            ->with('!board:fixture', ['msgtype' => 'm.text', 'body' => 'A discussion message'], '@member:fixture')->willReturn(['event_id' => '$sent']);
        $this->controller->boardMessages($this->request('member', ['body' => 'A discussion message', 'room' => '!foreign:fixture']), $this->board());
        DB::table('board_seats')->where('id', 'member-seat')->update(['status' => 'removed']);
        try {
            $this->controller->boardMessages($this->request('member', ['body' => 'No longer a member']), $this->board());
            self::fail('Membership must be checked on each post.');
        } catch (HttpException $error) { self::assertSame(403, $error->getStatusCode()); }
    }

    public function test_public_discussion_cannot_reach_a_private_institution_room(): void
    {
        $this->room('legislature', 'chamber', false);
        $this->matrix->expects($this->never())->method('sendMessage');
        $this->provisioner->expects($this->never())->method('ensureFor');
        $legislature = (new Legislature())->forceFill(['id' => 'chamber', 'jurisdiction_id' => 'place']);
        try {
            $this->controller->chamberMessages($this->request('visitor', ['body' => 'Hello']), $legislature);
            self::fail('Private rooms never use the public discussion path.');
        } catch (HttpException $error) { self::assertSame(403, $error->getStatusCode()); }
    }

    public function test_batched_roster_identities_match_canonical_user_identity_without_per_seat_queries(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('matrix_identities', function (Blueprint $t): void {
            $t->string('user_id');
            $t->string('matrix_localpart')->nullable();
            $t->softDeletes();
        });
        $schema->create('social_profiles', function (Blueprint $t): void {
            $t->string('user_id');
            $t->string('handle')->nullable();
            $t->softDeletes();
        });
        config(['matrix.server_name' => 'fixture']);
        DB::table('matrix_identities')->insert([
            ['user_id' => 'stored', 'matrix_localpart' => 'u-chosen-disambiguated'],
            ['user_id' => 'empty', 'matrix_localpart' => ''],
        ]);
        DB::table('social_profiles')->insert([
            ['user_id' => 'stored', 'handle' => 'Other handle'],
            ['user_id' => 'fresh', 'handle' => 'A.Public-Handle'],
            ['user_id' => 'empty', 'handle' => 'Profile.Name'],
        ]);
        $service = new MatrixPostingGateService($this->createMock(\App\Services\RoleService::class), $this->matrix);
        DB::connection()->enableQueryLog();
        $batch = $service->matrixUserIdsFor(['stored', 'fresh', 'anonymous', 'empty', 'stored']);
        self::assertCount(2, DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
        foreach ($batch as $id => $handle) {
            self::assertSame($service->matrixUserId((new User())->forceFill(['id' => $id])), $handle);
        }
        self::assertSame('@u-chosen-disambiguated:fixture', $batch['stored']);
        self::assertSame('@u-a.public-handle:fixture', $batch['fresh']);
        self::assertSame('@u-profile.name:fixture', $batch['empty']);
        self::assertSame('@u-'.substr(hash('sha256', 'anonymous'), 0, 32).':fixture', $batch['anonymous']);
    }

    public function test_first_authorized_board_join_mints_chosen_name_and_reuses_its_pseudonym(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('matrix_identities', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->string('user_id')->unique();
            $t->string('matrix_localpart')->unique();
            $t->string('matrix_user_id');
            $t->timestamps();
            $t->softDeletes();
        });
        $schema->create('social_profiles', function (Blueprint $t): void {
            $t->string('user_id');
            $t->string('handle');
            $t->string('display_name')->nullable();
            $t->softDeletes();
        });
        $schema->create('users', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->string('display_name');
            $t->softDeletes();
        });
        // No legal-name/email columns exist in the fixture: resolving either must fail.
        DB::table('users')->insert(['id' => 'member', 'display_name' => 'Chosen public name']);
        DB::table('social_profiles')->insert(['user_id' => 'member', 'handle' => 'first-call']);
        $this->seat('chair', 'board', 'member');
        $this->room('board', 'board', false);
        config(['matrix.server_name' => 'fixture', 'matrix.livekit.api_key' => 'fixture', 'matrix.livekit.api_secret' => 'test-secret']);
        $posting = new MatrixPostingGateService($this->createMock(\App\Services\RoleService::class), $this->matrix);
        app()->instance(MatrixIdentityProvisioner::class, new MatrixIdentityProvisioner($posting));
        $tokens = new LiveKitTokenService($posting, new PublicRoomNames());
        foreach ([1, 2] as $attempt) {
            $response = $this->controller->boardToken($this->request('member'), $this->board(), $tokens)->getData(true);
            $claims = $tokens->verify($response['token']);
            self::assertSame('Chosen public name', $claims['name']);
            self::assertSame('@u-first-call:fixture', $claims['sub']);
            self::assertSame('!board:fixture', $claims['video']['room']);
            self::assertArrayNotHasKey('roomAdmin', $claims['video']);
        }
        self::assertSame(1, DB::table('matrix_identities')->count());
    }

    private function request(?string $user, array $data = []): Request
    {
        $request = Request::create('/rooms/fixture', 'POST', $data);
        $request->setUserResolver(fn () => $user === null ? null : (new User())->forceFill(['id' => $user]));
        return $request;
    }

    private function board(): Board
    {
        return (new Board())->forceFill(['id' => 'board', 'status' => 'active', 'chair_seat_id' => 'chair']);
    }

    private function seat(string $id, string $board, string $user, string $status = 'seated'): void
    {
        DB::table('board_seats')->insert(['id' => $id, 'board_id' => $board, 'holder_user_id' => $user, 'status' => $status, 'seat_no' => 1]);
    }

    private function room(string $type, string $id, bool $public): void
    {
        DB::table('matrix_rooms')->insert(['id' => 'matrix-'.$id, 'matrix_room_id' => '!'.$id.':fixture',
            'entity_type' => $type, 'entity_id' => $id, 'room_type' => $public ? 'institution' : 'org_private', 'is_public' => $public]);
    }
}
