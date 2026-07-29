<?php

namespace Tests\Constitutional;

use App\Http\Middleware\DevTimeControlsEnabled;
use App\Models\FederationPeer;
use App\Services\AuditService;
use App\Services\ClockService;
use App\Services\Dev\DemoMeshTimeCoordinator;
use App\Services\Dev\DevClockService;
use App\Support\GameMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — DEMO-MESH TIME COORDINATION (Wave 3, lane 2 — the build of
 * DEMO_MESH_TIME_COORDINATION.md, ruling §10 item 4's multibox half).
 *
 * A mesh made only of declared demo instances MAY time-travel, but only through
 * ONE coordinating node, or the shared deadlines skew. This pins the mechanism:
 *
 *   · the coordinator ORIGINATES an advance and publishes it as a signed record
 *     on the ordinary sync stream (a `demo.time_advance` audit entry);
 *   · a follower REPLAYS that record exactly ONCE — idempotent on the advance_id
 *     ledger, so a re-delivery (or a restart) never double-advances;
 *   · the whole mechanism RE-GATES on receipt: one non-demo peer in the mesh and
 *     every path refuses, because a node real nodes trust must never time-travel;
 *   · a follower's LOCAL advance is refused with the coordinator named (§3),
 *     unless skew tolerance is asserted (§4);
 *   · the coordinator role is a persisted column, so it SURVIVES a restart.
 *
 * Built and tested against the written-but-unlanded migration: the schema is
 * created inside the same rolled-back transaction the body runs in (Postgres
 * transactional DDL), so this is green whether or not
 * 2026_07_29_200000_demo_mesh_time_coordination has been applied. The DDL here
 * mirrors that migration exactly — if they drift, THIS is the copy to fix.
 *
 * The clock engine is a double: this pins the COORDINATION logic (ledger, gate,
 * replay, refusal), not the deadline-shifting DevClockService already pinned
 * elsewhere. The advance is a recorded call, not a live world-wide shift.
 */
class DemoMeshTimeCoordinatorTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_demo_mesh_time';

    /** The double's advance() call log — asserted to prove "exactly once". An
     *  ArrayObject so the double and the test share one handle (a plain array
     *  cannot be held by reference through constructor promotion). */
    private \ArrayObject $advanceLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        config(['cga.impersonation' => true, 'cga.dev_time' => true]);
        GameMode::flush();
    }

    protected function tearDown(): void
    {
        GameMode::flush();
        parent::tearDown();
    }

    // ── The coordinator originates + publishes ──────────────────────────────

    public function test_the_coordinator_originates_an_advance_and_publishes_the_mesh_record(): void
    {
        $this->onLivePg(function (DemoMeshTimeCoordinator $mesh, string $selfId, string $coordPeerId) {
            // A declared-demo peer keeps the base gate open and makes this a
            // coordinator (not solo).
            $this->insertPeer($coordPeerId, ['instance_class' => 'scale_demo']);

            $this->assertTrue($mesh->isCoordinator(), 'time_coordinator NULL ⇒ this node coordinates');
            $this->assertSame('coordinator', $mesh->role());

            $result = $mesh->originateAdvance(30);

            $this->assertNotNull($result['advance_id'] ?? null, 'an originated advance is minted an id');
            $this->assertCount(1, $this->advanceLog, 'the engine advanced exactly once');
            $this->assertSame(30, $this->advanceLog[0]);

            // The ledger row — origin local.
            $row = DB::table('demo_time_advances')->where('advance_id', $result['advance_id'])->first();
            $this->assertNotNull($row);
            $this->assertSame('local', $row->origin);
            $this->assertSame(30, (int) $row->days);
            $this->assertSame($selfId, $row->issued_by);

            // The mesh record rides the audit stream — this is what a follower replays.
            $entry = DB::table('audit_log')
                ->where('event', DemoMeshTimeCoordinator::ADVANCE_EVENT)
                ->orderByDesc('seq')->first();
            $this->assertNotNull($entry, 'the coordinator appended a demo.time_advance audit entry');
            $payload = json_decode($entry->payload, true);
            $this->assertSame($result['advance_id'], $payload['advance_id']);
            $this->assertSame(30, $payload['days']);
            $this->assertSame($selfId, $payload['issued_by']);
        });
    }

    // ── A follower replays it, once, idempotently (the two-node path) ────────

    public function test_a_follower_replays_the_coordinators_advance_once_and_only_once(): void
    {
        $this->onLivePg(function (DemoMeshTimeCoordinator $mesh, string $selfId, string $coordId) {
            // Step 1 — originate AS the coordinator, capturing the exact record a
            // follower would receive over the sync stream.
            $this->insertPeer($coordId, ['instance_class' => 'scale_demo']);
            $origin = $mesh->originateAdvance(45);
            $payload = json_decode(
                DB::table('audit_log')->where('event', DemoMeshTimeCoordinator::ADVANCE_EVENT)
                    ->orderByDesc('seq')->value('payload'),
                true,
            );

            // Step 2 — become a DIFFERENT node: a follower of the coordinator, with
            // no memory of the advance. (Same DB, so we clear the local ledger and
            // re-point the coordinator; the captured payload stands in for the
            // record that would have arrived on B's sync.)
            DB::table('demo_time_advances')->delete();
            $this->advanceLog->exchangeArray([]);
            $coordServerId = $payload['issued_by']; // the coordinator's own server_id
            DB::table('instance_settings')->update(['time_coordinator_server_id' => $coordServerId]);
            // The coordinator is a declared-demo peer of ours; the gate stays open.
            $this->insertPeer($coordServerId, ['instance_class' => 'scale_demo']);

            $this->assertTrue($mesh->isFollower());
            $this->assertNull(DevTimeControlsEnabled::refusalReason(), 'a declared-demo mesh may time-travel');

            $peer = $this->peerModel($coordServerId);

            // First delivery — applied.
            $applied = $mesh->replayFromSync($payload, $peer);
            $this->assertSame($origin['advance_id'], $applied, 'the follower applied the coordinator advance');
            $this->assertCount(1, $this->advanceLog, 'the engine advanced exactly once on replay');
            $this->assertSame(45, $this->advanceLog[0]);
            $ledger = DB::table('demo_time_advances')->where('advance_id', $payload['advance_id'])->first();
            $this->assertSame('sync', $ledger->origin);
            $this->assertSame($coordServerId, $ledger->source_peer_id);

            // Second delivery of the SAME record — a no-op. THE IDEMPOTENCY PIN.
            $again = $mesh->replayFromSync($payload, $peer);
            $this->assertNull($again, 'a re-delivered advance is not applied again');
            $this->assertCount(1, $this->advanceLog, 'the engine did NOT advance a second time');
            $this->assertSame(1, DB::table('demo_time_advances')->count(), 'one ledger row, not two');
        });
    }

    // ── The refusal matrix: one non-demo peer refuses everything ────────────

    public function test_a_non_demo_peer_in_the_mesh_refuses_the_whole_mechanism(): void
    {
        $this->onLivePg(function (DemoMeshTimeCoordinator $mesh, string $selfId, string $coordId) {
            DB::table('instance_settings')->update(['time_coordinator_server_id' => $coordId]);
            $this->insertPeer($coordId, ['instance_class' => 'scale_demo']); // the demo coordinator
            $this->insertPeer((string) Str::uuid(), []);                     // one UNDECLARED (real) peer

            // The base gate refuses — demo + production is a real mesh.
            $reason = DevTimeControlsEnabled::refusalReason();
            $this->assertNotNull($reason, 'a mesh with any non-demo node refuses time controls');
            $this->assertStringContainsString('has not declared itself a demo', $reason);

            // And so the replay applies nothing — the gate travels the mesh.
            $payload = [
                'advance_id' => (string) Str::uuid(),
                'days' => 30,
                'issued_by' => $coordId,
                'issued_at' => (string) now(),
            ];
            $applied = $mesh->replayFromSync($payload, $this->peerModel($coordId));

            $this->assertNull($applied, 'a re-gated node applies no coordinator advance');
            $this->assertCount(0, $this->advanceLog, 'the engine never advanced');
            $this->assertSame(0, DB::table('demo_time_advances')->count());
        });
    }

    // ── §3 — a follower refuses a LOCAL advance, naming the coordinator ──────

    public function test_a_follower_refuses_a_local_advance_and_names_the_coordinator(): void
    {
        $this->onLivePg(function (DemoMeshTimeCoordinator $mesh, string $selfId, string $coordId) {
            $this->insertPeer($coordId, ['instance_class' => 'scale_demo', 'name' => 'coordinator']);
            DB::table('federation_peers')->where('server_id', $coordId)->update(['name' => 'Earth-Prime']);
            DB::table('instance_settings')->update(['time_coordinator_server_id' => $coordId]);

            $this->assertTrue($mesh->isFollower());
            $this->assertFalse($mesh->isCoordinator());

            $refusal = $mesh->localAdvanceRefusal();
            $this->assertNotNull($refusal, 'a follower may not originate a local advance');
            $this->assertStringContainsString('not the demo-mesh time coordinator', $refusal);
            $this->assertStringContainsString('Earth-Prime', $refusal, 'the coordinator is named');
        });
    }

    // ── §4 — asserted skew tolerance stands the follower clause down ─────────

    public function test_skew_tolerance_lets_a_follower_advance_independently(): void
    {
        $this->onLivePg(function (DemoMeshTimeCoordinator $mesh, string $selfId, string $coordId) {
            $this->insertPeer($coordId, ['instance_class' => 'scale_demo']);
            DB::table('instance_settings')->update(['time_coordinator_server_id' => $coordId]);

            $this->assertNotNull($mesh->localAdvanceRefusal(), 'strict by default — follower refused');

            $mesh->setSkewTolerance(true);
            $this->assertTrue($mesh->skewTolerated());
            $this->assertNull($mesh->localAdvanceRefusal(), '§4 asserted — the clause stands down');

            // Audit-logged on the flip.
            $this->assertSame(
                1,
                DB::table('audit_log')->where('event', 'demo.time_skew_tolerance_set')->count(),
                'the skew-tolerance flip is recorded on the audit chain',
            );
        });
    }

    // ── The role is durable — it survives a restart ─────────────────────────

    public function test_the_coordinator_designation_survives_a_restart(): void
    {
        $this->onLivePg(function (DemoMeshTimeCoordinator $mesh, string $selfId, string $coordId) {
            $mesh->setCoordinator($coordId);

            // A restart is a fresh process reading the same durable row — model it
            // with a brand-new service instance and a direct column read.
            $fresh = new DemoMeshTimeCoordinator($this->fakeClock(), app(AuditService::class));
            $this->assertSame($coordId, $fresh->coordinatorServerId(), 'the designation is read back after "restart"');
            $this->assertSame(
                $coordId,
                DB::table('instance_settings')->value('time_coordinator_server_id'),
                'and it is a persisted column, not in-memory state',
            );

            // Re-designating self clears it.
            $mesh->setCoordinator(null);
            $this->assertTrue($mesh->isCoordinator());
            $this->assertNull(DB::table('instance_settings')->value('time_coordinator_server_id'));
        });
    }

    // ── Defense in depth: only OUR coordinator's advances replay ─────────────

    public function test_a_replay_from_a_node_that_is_not_our_coordinator_is_ignored(): void
    {
        $this->onLivePg(function (DemoMeshTimeCoordinator $mesh, string $selfId, string $coordId) {
            $this->insertPeer($coordId, ['instance_class' => 'scale_demo']);
            DB::table('instance_settings')->update(['time_coordinator_server_id' => $coordId]);

            // A record issued by some OTHER node, delivered by a demo peer.
            $strangerId = (string) Str::uuid();
            $this->insertPeer($strangerId, ['instance_class' => 'scale_demo']);
            $payload = [
                'advance_id' => (string) Str::uuid(),
                'days' => 30,
                'issued_by' => $strangerId, // not our coordinator
                'issued_at' => (string) now(),
            ];

            $this->assertNull($mesh->replayFromSync($payload, $this->peerModel($strangerId)));
            $this->assertCount(0, $this->advanceLog, 'we replay only our coordinator');
        });
    }

    public function test_a_coordinator_never_replays_a_foreign_advance(): void
    {
        $this->onLivePg(function (DemoMeshTimeCoordinator $mesh, string $selfId, string $coordId) {
            // time_coordinator NULL ⇒ we coordinate; we originate, never replay.
            $this->insertPeer($coordId, ['instance_class' => 'scale_demo']);
            $this->assertTrue($mesh->isCoordinator());

            $payload = [
                'advance_id' => (string) Str::uuid(),
                'days' => 30,
                'issued_by' => $coordId,
                'issued_at' => (string) now(),
            ];
            $this->assertNull($mesh->replayFromSync($payload, $this->peerModel($coordId)));
            $this->assertCount(0, $this->advanceLog);
        });
    }

    // ── Harness ─────────────────────────────────────────────────────────────

    /**
     * Runs $body inside a rolled-back transaction on the live sandbox DB, with:
     *   · the written-but-unlanded schema created transactionally (DDL undone on
     *     rollback — the real schema is never touched);
     *   · the base gate open (sandbox mode, no peers) so the coordination paths
     *     can be exercised; the body adds the peers it measures;
     *   · a known self server_id and a coordinator NULL (this node coordinates).
     *
     * @param  callable(DemoMeshTimeCoordinator,string,string):void  $body
     */
    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $this->ensureSchema();

            $selfId = (string) Str::uuid();
            DB::table('federation_peers')->delete();
            DB::table('instance_settings')->update([
                'server_id' => $selfId,
                'time_coordinator_server_id' => null,
                'demo_time_skew_tolerated' => false,
                'mirror_of_server_id' => null,
            ]);

            GameMode::override(GameMode::SANDBOX);
            $this->advanceLog = new \ArrayObject();

            $mesh = new DemoMeshTimeCoordinator($this->fakeClock(), app(AuditService::class));

            $body($mesh, $selfId, (string) Str::uuid());
        } finally {
            GameMode::flush();
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }

    /**
     * The written-but-unlanded schema, created inside the current transaction so
     * it is undone on rollback. Mirrors 2026_07_29_200000 exactly; IF NOT EXISTS
     * makes it a no-op once that migration lands.
     */
    private function ensureSchema(): void
    {
        DB::statement('ALTER TABLE instance_settings ADD COLUMN IF NOT EXISTS time_coordinator_server_id uuid');
        DB::statement('ALTER TABLE instance_settings ADD COLUMN IF NOT EXISTS demo_time_skew_tolerated boolean NOT NULL DEFAULT false');
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS demo_time_advances (
                advance_id     uuid PRIMARY KEY,
                days           integer NOT NULL,
                issued_by      uuid,
                issued_at      timestamptz NOT NULL,
                plan_hash      varchar(64),
                origin         varchar(8) NOT NULL,
                source_peer_id uuid,
                applied_at     timestamptz NOT NULL,
                created_at     timestamptz,
                updated_at     timestamptz
            )
        SQL);
    }

    /** A DevClockService double: advance() logs its call, dryRun() is cheap. */
    private function fakeClock(): DevClockService
    {
        return new class(app(AuditService::class), app(ClockService::class), $this->advanceLog) extends DevClockService
        {
            public function __construct(AuditService $a, ClockService $c, private \ArrayObject $log)
            {
                parent::__construct($a, $c);
            }

            public function dryRun(int $days): array
            {
                return ['days' => $days, 'timers' => [], 'columns' => [], 'total_timers' => 0];
            }

            public function advance(int $days, ?callable $onProgress = null): array
            {
                $this->log->append($days);

                return ['days' => $days, 'shifted' => [], 'fired' => 0, 'failed' => 0];
            }
        };
    }

    private function insertPeer(string $serverId, array $metadata): void
    {
        DB::table('federation_peers')->insert([
            'server_id' => $serverId,
            'url' => 'http://'.substr($serverId, 0, 8).'.test',
            'status' => 'trust_established',
            'metadata' => json_encode($metadata),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function peerModel(string $serverId): FederationPeer
    {
        $peer = new FederationPeer;
        $peer->server_id = $serverId;

        return $peer;
    }
}
