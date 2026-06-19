<?php

namespace Tests\Constitutional;

use App\Models\MatrixRoom;
use App\Services\Matrix\MatrixClientService;
use App\Services\Matrix\SocialTopologyReconcilerService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase K-3 (K3-F). The Matrix topology reconciler is idempotent (one live room
 * per (entity, space_type) — a re-run is a no-op), every room it makes is v12 (the K3-E power-clamp
 * holds across the tree), and #halls is gated on a SEATED government (no seated body ⇒ #square but NO
 * #halls — the FLIP-ON-SEATEDNESS gate, halls half). The Matrix client is mocked (hermetic);
 * matrix_rooms is real (live-pg).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class TopologyReconcilerTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_k3_topology';

    public function test_a_seated_jurisdiction_gets_space_square_halls_idempotently(): void
    {
        $this->onLivePg(function () {
            $jurId = $this->aJurisdiction();
            $this->mockClient();

            $topo = app(SocialTopologyReconcilerService::class);
            $topo->reconcileJurisdiction($jurId, isSeated: true);
            $topo->reconcileJurisdiction($jurId, isSeated: true);   // a re-run must NOT duplicate

            $rooms = MatrixRoom::query()->where('entity_type', 'jurisdiction')->where('entity_id', $jurId)->get();

            $this->assertCount(3, $rooms, 'exactly Space + #square + #halls, no duplicates on re-run');
            $this->assertSame(1, $rooms->whereNull('space_type')->where('room_type', MatrixRoom::ROOM_SPACE)->count(), 'one Space');
            $this->assertSame(1, $rooms->where('space_type', MatrixRoom::SPACE_PUBLIC_SQUARE)->count(), 'one #square');
            $this->assertSame(1, $rooms->where('space_type', MatrixRoom::SPACE_HALLS)->count(), 'one #halls');
            $this->assertTrue($rooms->every(fn ($r) => $r->room_version === '12'), 'every room is v12 (the K3-E clamp)');
        });
    }

    public function test_an_unseated_jurisdiction_gets_a_square_but_no_halls(): void
    {
        $this->onLivePg(function () {
            $jurId = $this->aJurisdiction();
            $this->mockClient();

            app(SocialTopologyReconcilerService::class)->reconcileJurisdiction($jurId, isSeated: false);

            $rooms = MatrixRoom::query()->where('entity_id', $jurId)->get();
            $this->assertSame(1, $rooms->where('space_type', MatrixRoom::SPACE_PUBLIC_SQUARE)->count(), '#square always exists');
            $this->assertSame(0, $rooms->where('space_type', MatrixRoom::SPACE_HALLS)->count(),
                'NO #halls without a seated government (FLIP-ON-SEATEDNESS, halls half)');
        });
    }

    private function mockClient(): void
    {
        $n = 0;
        $this->mock(MatrixClientService::class, function ($m) use (&$n) {
            $m->shouldReceive('roomVersions')->andReturn(['default' => '10', 'available' => ['10', '11', '12']]);
            $m->shouldReceive('createRoom')->andReturnUsing(function ($body) use (&$n) {
                $n++;

                return ['room_id' => '!room'.$n.':localhost'];
            });
            $m->shouldReceive('sendStateEvent')->andReturn(['event_id' => '$e']);
        });
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
