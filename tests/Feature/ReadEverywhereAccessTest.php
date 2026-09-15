<?php

namespace Tests\Feature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Handlers\OrganizationStaffDelegation;
use App\Http\Controllers\Organizations\OrganizationController;
use App\Http\Controllers\Organizations\OrgDelegationController;
use App\Http\Controllers\Rooms\InstitutionRoomController;
use App\Models\Board;
use App\Models\Organization;
use App\Models\User;
use App\Services\Matrix\MatrixClientService;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\Matrix\PublicVoiceRoomAccess;
use App\Services\Matrix\SocialTopologyReconcilerService;
use App\Services\Rooms\BoardRoomAccess;
use App\Services\Rooms\LiveFloorService;
use App\Services\Rooms\PublicRoomNames;
use App\Services\Rooms\RoomFloorService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * gap-lane fix-access — the read-everywhere access rulings (CLAUDE.md access
 * rulings; commit 253847f0, "a page never 403s on a role"). Two page reads and
 * one gate that stays.
 *
 * DB-free: an in-memory sqlite fixture is set as the default connection (the
 * InstitutionRoomTest posture) and Matrix transport is mocked, so no live box
 * is touched.
 *
 * Finding 1 — /organizations/{organization}/delegations. The index no longer
 * aborts for a non-agent; it delegates to OrganizationController@show for any
 * signed-in viewer. The grant/revoke write stays agent-only, enforced by the
 * F-ORG-011 handler.
 *
 * Finding 2 — /rooms/board/{board}. A board room with no live call renders the
 * institution page in a 'no live call' state (callAvailable = false) instead of
 * a blank room. The private-member gate (BoardRoomAccess) and the join/message
 * endpoints keep refusing (pinned in InstitutionRoomTest).
 */
final class ReadEverywhereAccessTest extends TestCase
{
    private string $original;

    /** @var \PHPUnit\Framework\MockObject\MockObject&SocialTopologyReconcilerService */
    private $topology;

    /** @var \PHPUnit\Framework\MockObject\MockObject&MatrixPostingGateService */
    private $posting;

    /** @var \PHPUnit\Framework\MockObject\MockObject&MatrixClientService */
    private $matrix;

    /** @var \PHPUnit\Framework\MockObject\MockObject&PublicRoomNames */
    private $names;

    private InstitutionRoomController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.fix_access_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('fix_access_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());

        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('organizations', function (Blueprint $t): void {
            $t->string('id')->primary();
            $t->string('agent_user_id')->nullable();
            $t->string('jurisdiction_id')->nullable();
            $t->softDeletes();
        });
        $schema->create('board_seats', function (Blueprint $t): void {
            foreach (['id', 'board_id', 'holder_user_id', 'status'] as $column) {
                $t->string($column);
            }
            $t->integer('seat_no')->nullable();
            $t->softDeletes();
        });
        $schema->create('matrix_rooms', function (Blueprint $t): void {
            $t->string('id')->primary();
            foreach (['matrix_room_id', 'entity_type', 'entity_id', 'room_type', 'space_type'] as $column) {
                $t->string($column)->nullable();
            }
            $t->boolean('is_public')->default(false);
            $t->boolean('is_encrypted')->default(false);
            $t->timestamp('tombstoned_at')->nullable();
            $t->softDeletes();
        });

        $this->topology = $this->createMock(SocialTopologyReconcilerService::class);
        $this->posting = $this->createMock(MatrixPostingGateService::class);
        $this->matrix = $this->createMock(MatrixClientService::class);
        $this->names = $this->createMock(PublicRoomNames::class);
        $this->posting->method('matrixUserIdsFor')->willReturn([]);
        $this->posting->method('matrixUserId')->willReturn('@viewer:fixture');
        $this->names->method('forUsers')->willReturn([]);
        $this->names->method('forHandles')->willReturn([]);

        $floorView = $this->createMock(RoomFloorService::class);
        $floorView->method('view')->willReturn(['queue' => [], 'floorHolder' => null, 'activeWitness' => null,
            'canRequest' => false, 'canPreside' => false, 'myHandRaised' => false, 'displayNames' => []]);
        app()->instance(RoomFloorService::class, $floorView);

        $liveFloor = $this->createMock(LiveFloorService::class);
        $this->controller = new InstitutionRoomController($this->topology, $this->posting, $this->matrix,
            $this->names, $liveFloor, new BoardRoomAccess(), new PublicVoiceRoomAccess($this->posting));
    }

    protected function tearDown(): void
    {
        DB::purge('fix_access_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    // ── Finding 1: delegations index reads for any signed-in viewer ──────────

    public function test_delegations_index_delegates_to_show_for_a_non_agent_without_aborting(): void
    {
        // A sentinel OrganizationController — index() must resolve it from the
        // container and return its show() result verbatim. If index still
        // aborted for a non-agent, the sentinel would never be reached.
        $marker = new \stdClass();
        app()->instance(OrganizationController::class, new class($marker)
        {
            public function __construct(private object $marker) {}

            public function show(Request $request, Organization $organization): object
            {
                return $this->marker;
            }
        });

        $org = (new Organization())->forceFill(['id' => 'org-1', 'agent_user_id' => 'the-agent']);
        $nonAgent = (new User())->forceFill(['id' => 'a-different-resident']);
        $request = Request::create('/organizations/org-1/delegations', 'GET');
        $request->setUserResolver(fn () => $nonAgent);

        $controller = new OrgDelegationController($this->createMock(ConstitutionalEngine::class));

        self::assertSame($marker, $controller->index($request, $org),
            'a non-agent resident reads the org detail surface (no 403)');
    }

    // ── Finding 1: the grant/revoke write stays agent-only ───────────────────

    public function test_staff_delegation_grant_is_refused_for_a_non_agent_and_for_a_system_filing(): void
    {
        DB::table('organizations')->insert(['id' => 'org-1', 'agent_user_id' => 'the-agent']);
        $handler = new OrganizationStaffDelegation();
        $payload = ['organization_id' => 'org-1', 'action' => 'grant_task',
            'grantee_user_id' => 'someone', 'bucket' => 'profile'];

        $nonAgent = (new User())->forceFill(['id' => 'a-different-resident']);
        try {
            $handler->handle($nonAgent, $payload);
            self::fail('Only the organization agent may file F-ORG-011.');
        } catch (ConstitutionalViolation $error) {
            self::assertStringContainsStringIgnoringCase('agent', $error->getMessage());
        }

        try {
            $handler->handle(null, $payload);
            self::fail('A system filing has no agent to record.');
        } catch (ConstitutionalViolation $error) {
            self::assertStringContainsStringIgnoringCase('person', $error->getMessage());
        }
    }

    // ── Finding 2: board room renders a no-live-call state, not a 403 ────────

    public function test_board_room_renders_no_live_call_state_for_a_seated_member_when_no_room_is_live(): void
    {
        $this->seatMember('member');
        // No matrix_rooms row exists; the provision attempt yields nothing.
        $this->topology->method('reconcileBoard')->willReturn(null);
        $this->matrix->expects($this->never())->method('getMessages');

        $props = $this->boardProps('member');

        self::assertFalse($props['callAvailable'], 'no live room → callAvailable is false');
        self::assertNull($props['voice']['roomId']);
        self::assertTrue($props['private'], 'a board room is the private variant');
        self::assertSame('board', $props['variant']);
    }

    public function test_board_room_reports_call_available_when_a_live_room_exists(): void
    {
        $this->seatMember('member');
        DB::table('matrix_rooms')->insert(['id' => 'room-1', 'matrix_room_id' => '!board:fixture',
            'entity_type' => 'board', 'entity_id' => 'board-1', 'room_type' => 'org_private', 'is_public' => false]);
        $this->matrix->method('getMessages')->willReturn(['chunk' => []]);

        $props = $this->boardProps('member');

        self::assertTrue($props['callAvailable'], 'a valid live room → callAvailable is true');
        self::assertSame('!board:fixture', $props['voice']['roomId']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function seatMember(string $userId): void
    {
        DB::table('board_seats')->insert(['id' => 'seat-1', 'board_id' => 'board-1',
            'holder_user_id' => $userId, 'status' => 'seated', 'seat_no' => 1]);
    }

    /** @return array<string, mixed> */
    private function boardProps(string $userId): array
    {
        $board = (new Board())->forceFill(['id' => 'board-1', 'status' => Board::STATUS_ACTIVE,
            'chair_seat_id' => 'seat-1', 'boardable_type' => Board::BOARDABLE_ORGANIZATIONS, 'boardable_id' => 'org-1']);
        $request = Request::create('/rooms/board/board-1', 'GET');
        $request->setUserResolver(fn () => (new User())->forceFill(['id' => $userId]));
        $response = $this->controller->board($request, $board);

        return (new \ReflectionProperty($response, 'props'))->getValue($response);
    }
}
