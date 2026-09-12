<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalViolation;
use App\Http\Controllers\Civic\MatrixCommonsController;
use App\Models\MatrixRoom;
use App\Models\User;
use App\Services\Matrix\MatrixClientService;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\Matrix\SocialTopologyReconcilerService;
use App\Services\Matrix\TestimonyBridgeService;
use App\Services\RoleService;
use App\Services\Rooms\PublicRoomNames;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionProperty;
use Tests\TestCase;

/** Explicit SQLite memory fixtures and mocked Matrix transport/topology only. */
final class MatrixCommonsScopeTest extends TestCase
{
    private const CONNECTION = 'matrix_commons_scope_fixture';
    private string $originalConnection;
    private $roles;
    private $client;
    private $posting;
    private $testimony;
    private $names;
    private $topology;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.'.self::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection(self::CONNECTION);
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('jurisdictions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name'); $t->string('slug'); $t->integer('adm_level');
            $t->uuid('parent_id')->nullable(); $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('legislatures', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('jurisdiction_id'); $t->string('status'); $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('matrix_rooms', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('matrix_room_id')->nullable(); $t->string('entity_type');
            $t->uuid('entity_id'); $t->string('space_type'); $t->string('room_type');
            $t->boolean('is_public')->default(true); $t->boolean('is_encrypted')->default(false);
            $t->timestamp('tombstoned_at')->nullable(); $t->timestamp('deleted_at')->nullable();
        });
        DB::table('jurisdictions')->insert([
            ['id' => $this->id(1), 'name' => 'World', 'slug' => 'world', 'adm_level' => 0, 'parent_id' => null],
            ['id' => $this->id(2), 'name' => 'Poland', 'slug' => 'poland', 'adm_level' => 1, 'parent_id' => $this->id(1)],
            ['id' => $this->id(3), 'name' => 'Elsewhere', 'slug' => 'elsewhere', 'adm_level' => 1, 'parent_id' => $this->id(1)],
        ]);
        $this->roles = Mockery::mock(RoleService::class);
        $this->client = Mockery::mock(MatrixClientService::class);
        $this->posting = Mockery::mock(MatrixPostingGateService::class);
        $this->testimony = Mockery::mock(TestimonyBridgeService::class);
        $this->names = Mockery::mock(PublicRoomNames::class);
        $this->names->shouldReceive('forHandles')->with([])->andReturn([])->byDefault();
        $this->topology = Mockery::mock(SocialTopologyReconcilerService::class);
    }

    protected function tearDown(): void
    {
        DB::disconnect(self::CONNECTION); DB::purge(self::CONNECTION); DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_guest_can_read_an_explicit_slug_or_uuid_without_residency_lookup_or_provisioning(): void
    {
        $this->room(10);
        $this->client->shouldReceive('getMessages')->twice()->with('!room10:local', 'b', null, 40)->andReturn(['chunk' => []]);
        foreach (['poland', $this->id(2)] as $key) {
            $props = $this->page('/civic/commons/square?jurisdiction='.$key);
            self::assertSame('!room10:local', $props['roomId']);
            self::assertSame($this->id(2), $props['jurisdictionId']);
            self::assertSame('Poland', $props['selectedPlace']['name']);
            self::assertSame(['world', 'poland'], array_column($props['jurisdictionContext']['chain'], 'slug'));
            self::assertNull($props['myMxid']); self::assertFalse($props['isAssociated']);
            self::assertSame([], $props['jurisdictions']);
        }
    }

    public function test_unselected_guest_does_not_choose_an_unrelated_room_or_query_the_world(): void
    {
        $this->room(10);
        DB::enableQueryLog(); DB::flushQueryLog();
        $props = $this->page('/civic/commons/square');
        self::assertSame('choose_place', $props['roomState']);
        self::assertNull($props['roomId']); self::assertNull($props['selectedPlace']);
        self::assertSame([], DB::getQueryLog());
    }

    public function test_missing_square_is_created_only_for_the_selected_place_on_initial_visit(): void
    {
        $this->room(11, ['entity_id' => $this->id(3)]);
        $this->topology->shouldReceive('reconcileJurisdiction')->once()->with($this->id(2), false)->andReturnUsing(function () {
            $this->room(10);
        });
        $this->client->shouldReceive('getMessages')->once()->with('!room10:local', 'b', null, 40)->andReturn(['chunk' => []]);
        $props = $this->page('/civic/commons/square?jurisdiction=poland');
        self::assertSame('ready', $props['roomState']);
        self::assertSame('!room10:local', $props['roomId']);
    }

    public function test_halls_do_not_provision_for_an_inactive_or_unrelated_government(): void
    {
        DB::table('legislatures')->insert([
            ['id' => $this->id(20), 'jurisdiction_id' => $this->id(2), 'status' => 'forming', 'deleted_at' => null],
            ['id' => $this->id(21), 'jurisdiction_id' => $this->id(3), 'status' => 'active', 'deleted_at' => null],
            ['id' => $this->id(22), 'jurisdiction_id' => $this->id(2), 'status' => 'active', 'deleted_at' => '2026-01-01'],
        ]);
        $props = $this->page('/civic/commons/halls?jurisdiction=poland', true);
        self::assertNull($props['roomId']);
        self::assertSame('waiting_for_government', $props['roomState']);
    }

    public function test_halls_provision_when_the_selected_place_has_an_active_legislature(): void
    {
        DB::table('legislatures')->insert(['id' => $this->id(20), 'jurisdiction_id' => $this->id(2), 'status' => 'active']);
        $this->topology->shouldReceive('reconcileJurisdiction')->once()->with($this->id(2), true)->andReturnUsing(function () {
            $this->room(10, ['space_type' => MatrixRoom::SPACE_HALLS]);
        });
        $this->client->shouldReceive('getMessages')->once()->andReturn(['chunk' => []]);
        $props = $this->page('/civic/commons/halls?jurisdiction=poland', true);
        self::assertSame('ready', $props['roomState']);
        self::assertSame('!room10:local', $props['roomId']);
    }

    public function test_poll_and_prefetch_requests_never_provision_missing_rooms(): void
    {
        foreach ([['X-Inertia-Partial-Data' => 'messages,reachable'], ['X-Inertia-Partial-Component' => 'Civic/MatrixCommons'], ['Purpose' => 'prefetch'], ['Sec-Purpose' => 'prefetch']] as $headers) {
            $props = $this->page('/civic/commons/square?jurisdiction=poland', headers: $headers);
            self::assertNull($props['roomId']);
            self::assertSame('not_ready', $props['roomState']);
        }
    }

    public function test_private_encrypted_tombstoned_and_unrelated_rooms_are_never_read_as_public_commons(): void
    {
        foreach ([['is_public' => false], ['is_encrypted' => true], ['tombstoned_at' => '2026-01-01'], ['deleted_at' => '2026-01-01'], ['entity_id' => $this->id(3)], ['room_type' => MatrixRoom::ROOM_USER_PRIVATE]] as $i => $extra) {
            $this->room(10 + $i, $extra);
        }
        $props = $this->page('/civic/commons/square?jurisdiction=poland', headers: ['X-Inertia-Partial-Data' => 'messages']);
        self::assertNull($props['roomId']); self::assertSame([], $props['messages']);
        $initial = $this->page('/civic/commons/square?jurisdiction=poland');
        self::assertNull($initial['roomId']); self::assertSame([], $initial['messages']);
    }

    public function test_matrix_timeline_and_provisioning_failures_leave_navigation_available(): void
    {
        $this->room(10);
        $this->client->shouldReceive('getMessages')->once()->andThrow(new ConnectionException('offline'));
        $props = $this->page('/civic/commons/square?jurisdiction=poland');
        self::assertFalse($props['reachable']); self::assertSame([], $props['messages']);
        self::assertSame('Poland', $props['selectedPlace']['name']);
        $this->topology->shouldReceive('reconcileJurisdiction')->once()->with($this->id(3), false)->andThrow(new ConnectionException('offline'));
        $props = $this->page('/civic/commons/square?jurisdiction=elsewhere');
        self::assertFalse($props['reachable']); self::assertSame('unavailable', $props['roomState']);
        self::assertSame('Elsewhere', $props['selectedPlace']['name']);
    }

    public function test_observed_place_is_separate_from_the_signed_in_users_residency(): void
    {
        $user = new User; $user->id = $this->id(30);
        $this->roles->shouldReceive('associationsFor')->once()->with($user)->andReturn([
            ['id' => $this->id(3), 'name' => 'Elsewhere', 'adm_level' => 1],
        ]);
        $this->posting->shouldReceive('matrixUserId')->once()->with($user)->andReturn('@u-visitor:local');
        $this->names->shouldReceive('forUsers')->once()->with([$this->id(30)])->andReturn([$this->id(30) => 'Visitor']);
        $this->room(10);
        $this->client->shouldReceive('getMessages')->once()->andReturn(['chunk' => []]);
        $props = $this->page('/civic/commons/square?jurisdiction=poland', user: $user);
        self::assertSame($this->id(2), $props['jurisdictionId']);
        self::assertFalse($props['isAssociated']);
        self::assertSame($this->id(3), $props['jurisdictions'][0]['id']);
        self::assertSame('Visitor', $props['displayNames']['@u-visitor:local']);
    }

    public function test_transport_failures_return_form_errors_and_constitutional_denials_remain_denials(): void
    {
        $user = new User; $user->id = $this->id(30);
        $request = Request::create('/civic/commons/post', 'POST', ['jurisdiction_id' => $this->id(2), 'room_id' => '!room10:local', 'body' => 'A draft']);
        $request->setUserResolver(fn () => $user);
        $session = app('session')->driver('array');
        app('redirect')->setSession($session);
        $this->posting->shouldReceive('post')->once()->with($user, $this->id(2), '!room10:local', 'A draft')->andThrow(new ConnectionException('offline'));
        $response = $this->controller()->post($request);
        self::assertSame(302, $response->getStatusCode());
        self::assertTrue($session->get('errors')->has('body'));
        $this->posting->shouldReceive('post')->once()->andThrow(new ConstitutionalViolation('Denied by the room gate.', 'Art. I'));
        $this->expectException(ConstitutionalViolation::class);
        $this->controller()->post($request);
    }

    private function controller(): MatrixCommonsController
    {
        return new MatrixCommonsController($this->roles, $this->client, $this->posting, $this->testimony, $this->names, $this->topology);
    }

    private function page(string $url, bool $halls = false, array $headers = [], ?User $user = null): array
    {
        $request = Request::create($url); $request->headers->add($headers); $request->setUserResolver(fn () => $user);
        $response = $halls ? $this->controller()->halls($request) : $this->controller()->square($request);

        return (new ReflectionProperty($response, 'props'))->getValue($response);
    }

    private function room(int $number, array $extra = []): void
    {
        DB::table('matrix_rooms')->insert(array_replace([
            'id' => $this->id($number), 'matrix_room_id' => '!room'.$number.':local',
            'entity_type' => MatrixRoom::ENTITY_JURISDICTION, 'entity_id' => $this->id(2),
            'space_type' => MatrixRoom::SPACE_PUBLIC_SQUARE, 'room_type' => MatrixRoom::ROOM_COMMONS,
        ], $extra));
    }

    private function id(int $number): string { return sprintf('10000000-0000-4000-8000-%012d', $number); }
}
