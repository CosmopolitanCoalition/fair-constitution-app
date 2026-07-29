<?php

namespace Tests\Constitutional;

use App\Models\MatrixRoom;
use App\Services\Matrix\MatrixClientService;
use App\Services\Matrix\SocialTopologyReconcilerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Slice 6, per-institution live rooms. A government
 * proceeding's room (a legislature floor, a committee hearing, a court case) is
 * a PUBLIC commons room (world_readable — Art. II §2; public trials — Art. IV),
 * room-typed `institution`, and bound UNDER its jurisdiction Space. A private
 * organization's board room is PRIVATE (§10-1: "private organizations decide
 * their own visibility"), never bound to the public Space. All provisioning is
 * idempotent (the matrix_rooms partial-unique makes a re-run a no-op — safe to
 * call lazily on first view). The Matrix client is mocked (hermetic); matrix_rooms
 * is real (live-pg).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class InstitutionRoomProvisioningTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_inst_rooms';

    public function test_a_public_institution_room_is_v12_institution_typed_and_bound_under_the_space(): void
    {
        $this->onLivePg(function () {
            $jurId = $this->aJurisdiction();
            $legId = (string) Str::uuid();
            [$childBindings] = $this->mockClient();

            $topo = app(SocialTopologyReconcilerService::class);
            $room  = $topo->reconcileInstitutionRoom(MatrixRoom::ENTITY_LEGISLATURE, $legId, $jurId, 'Floor session', true);
            $again = $topo->reconcileInstitutionRoom(MatrixRoom::ENTITY_LEGISLATURE, $legId, $jurId, 'Floor session', true); // re-run

            $this->assertNotNull($room);
            $this->assertSame((string) $room->id, (string) $again->id, 'idempotent — the same room on a re-run');

            $rows = MatrixRoom::query()
                ->where('entity_type', MatrixRoom::ENTITY_LEGISLATURE)->where('entity_id', $legId)->get();
            $this->assertCount(1, $rows, 'exactly one institution room, no duplicate on re-run');
            $this->assertSame(MatrixRoom::ROOM_INSTITUTION, $rows->first()->room_type);
            $this->assertTrue((bool) $rows->first()->is_public, 'a government proceeding is public (Art. II §2)');
            $this->assertSame('12', $rows->first()->room_version, 'v12 sole-creator clamp holds (K3-E)');

            // A Space exists for the jurisdiction and the institution room binds under it.
            $space = MatrixRoom::query()
                ->where('entity_type', 'jurisdiction')->where('entity_id', $jurId)
                ->where('room_type', MatrixRoom::ROOM_SPACE)->first();
            $this->assertNotNull($space, 'the jurisdiction Space is ensured for the parent binding');
            $this->assertContains($rows->first()->matrix_room_id, $childBindings(),
                'the institution room is bound under the Space via m.space.child');
        });
    }

    public function test_a_board_room_is_private_and_not_bound_to_the_public_space(): void
    {
        $this->onLivePg(function () {
            $jurId = $this->aJurisdiction();
            $boardId = (string) Str::uuid();
            $this->mockClient();

            $room = app(SocialTopologyReconcilerService::class)
                ->reconcileInstitutionRoom(MatrixRoom::ENTITY_BOARD, $boardId, $jurId, 'Board meeting', false);

            $this->assertNotNull($room);
            $this->assertFalse((bool) $room->is_public, 'a private org decides its own visibility — private-default (§10-1)');
            $this->assertSame(MatrixRoom::ROOM_ORG_PRIVATE, $room->room_type);

            // A private room is off the public plane: no Space was even ensured for it.
            $this->assertSame(0, MatrixRoom::query()
                ->where('entity_type', 'jurisdiction')->where('entity_id', $jurId)
                ->where('room_type', MatrixRoom::ROOM_SPACE)->count(),
                'a private board never provisions or binds under the public Space');
        });
    }

    public function test_a_public_room_without_a_resolvable_jurisdiction_makes_no_room(): void
    {
        $this->onLivePg(function () {
            $caseId = (string) Str::uuid();
            $this->mockClient();

            $room = app(SocialTopologyReconcilerService::class)
                ->reconcileInstitutionRoom(MatrixRoom::ENTITY_CASE, $caseId, null, 'Hearing', true);

            $this->assertNull($room, 'no jurisdiction Space to parent it ⇒ no public civic room');
            $this->assertSame(0, MatrixRoom::query()->where('entity_id', $caseId)->count());
        });
    }

    /** @return array{0: callable():array<string>} the captured m.space.child child-room ids. */
    private function mockClient(): array
    {
        $n = 0;
        $childBindings = [];
        $this->mock(MatrixClientService::class, function ($m) use (&$n, &$childBindings) {
            $m->shouldReceive('roomVersions')->andReturn(['default' => '10', 'available' => ['10', '11', '12']]);
            $m->shouldReceive('createRoom')->andReturnUsing(function ($body) use (&$n) {
                $n++;

                return ['room_id' => '!room'.$n.':localhost'];
            });
            $m->shouldReceive('sendStateEvent')->andReturnUsing(function ($room, $type, $key, $content) use (&$childBindings) {
                if ($type === 'm.space.child') {
                    $childBindings[] = $key; // the child room id is the state_key
                }

                return ['event_id' => '$e'];
            });
        });

        // NB: a real closure with by-reference capture — an arrow fn would snapshot
        // the (empty) array by value before the mock ever appends to it.
        return [function () use (&$childBindings) { return $childBindings; }];
    }

    private function aJurisdiction(): string
    {
        $id = DB::table('jurisdictions')
            ->whereNull('deleted_at')
            // Own an unclaimed jurisdiction — one already holding Matrix rooms
            // would collide on matrix_rooms_entity_unique when we ensure its
            // Space (see TopologyReconcilerTest's note on the Niue collision).
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('matrix_rooms')
                ->whereColumn('matrix_rooms.entity_id', 'jurisdictions.id')
                ->whereNull('matrix_rooms.deleted_at'))
            ->value('id');
        if ($id === null) {
            $this->markTestSkipped('Live DB has no unclaimed jurisdiction.');
        }

        return (string) $id;
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
