<?php

namespace App\Services\Dev;

use App\Http\Middleware\DevTimeControlsEnabled;
use App\Models\DemoTimeAdvance;
use App\Models\FederationPeer;
use App\Models\InstanceSettings;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * DEMO-MESH TIME COORDINATION — the build of DEMO_MESH_TIME_COORDINATION.md.
 *
 * Full Faith & Credit syncs *records*, not *time*. A mesh made only of declared
 * demo instances MAY time-travel (ruling §10 item 4), but if each sovereign
 * advances independently the shared deadlines skew: node A believes an election
 * window is open, node B still waits on it, and the records that cross between
 * them contradict each other. The fix is exactly one coordinating node.
 *
 * THE THREE MOVES THIS SERVICE MAKES:
 *   1. WHO coordinates — the world-starting node, by default. Designation lives
 *      in instance_settings.time_coordinator_server_id (NULL = "this node
 *      coordinates", the authoritative_server_id idiom). Persisted, so the role
 *      survives a restart with no election protocol.
 *   2. An advance is a mesh RECORD, not an RPC. The coordinator's apply mints a
 *      stable advance_id, records it locally, and appends a `demo.time_advance`
 *      audit entry — which rides the ORDINARY sync tail (buildAuditTail ships
 *      every audit entry in its window). No new transport.
 *   3. Every other demo node REPLAYS that record idempotently, through the SAME
 *      engine path and re-through its OWN gate. A node that has meanwhile peered
 *      with a real instance refuses the replay exactly as it refuses a local
 *      advance — the gate travels the mesh.
 *
 * DEGRADES TO SOLO when the schema is not yet applied: reads of the settings
 * columns are null-safe under Eloquent (an absent column reads as NULL = "this
 * node coordinates"), and every ledger touch is guarded by hasTable. So the
 * service is safe on a box that has the code but not yet the migration — it
 * simply behaves as a single-box demo, which is correct.
 */
class DemoMeshTimeCoordinator
{
    /** The audit event that carries a coordinated advance across the sync stream. */
    public const ADVANCE_EVENT = 'demo.time_advance';

    public const ADVANCE_MODULE = 'system';

    public const ADVANCE_REF = 'DEV-TIME-MESH';

    public function __construct(
        private readonly DevClockService $clock,
        private readonly AuditService $audit,
    ) {}

    // ──────────────────────────────────────────────────────────────────────
    // Role
    // ──────────────────────────────────────────────────────────────────────

    /** The designated coordinator's server_id, or NULL meaning "this node". */
    public function coordinatorServerId(): ?string
    {
        // Null-safe on an un-migrated box: the column is absent → attribute NULL.
        $id = InstanceSettings::current()->time_coordinator_server_id;

        return $id !== null && $id !== '' ? (string) $id : null;
    }

    /** This node coordinates when no other node is named (the NULL idiom). */
    public function isCoordinator(): bool
    {
        return $this->coordinatorServerId() === null;
    }

    public function isFollower(): bool
    {
        return $this->coordinatorServerId() !== null;
    }

    /** The §4 escape hatch — off by default (the strict, fail-closed direction). */
    public function skewTolerated(): bool
    {
        return (bool) InstanceSettings::current()->demo_time_skew_tolerated;
    }

    /**
     * How many declared-demo peers this node would propagate an advance to. The
     * same declaration the dev-time rail reads (instance_class = scale_demo OR
     * game_mode = sandbox), live rows only.
     */
    public function demoPeerCount(): int
    {
        if (! Schema::hasTable('federation_peers')) {
            return 0;
        }

        return DB::table('federation_peers')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereRaw("metadata->>'instance_class' = ?", ['scale_demo'])
                    ->orWhereRaw("metadata->>'game_mode' = ?", ['sandbox']);
            })
            ->count();
    }

    public function role(): string
    {
        if ($this->isFollower()) {
            return 'follower';
        }

        return $this->demoPeerCount() > 0 ? 'coordinator' : 'solo';
    }

    // ──────────────────────────────────────────────────────────────────────
    // The §3 clause — a follower does not originate a local advance
    // ──────────────────────────────────────────────────────────────────────

    /**
     * The refusal a LOCAL advance earns on a follower node, or NULL when a local
     * advance may originate here. This is checked IN ADDITION to the base gate
     * (DevTimeControlsEnabled::refusalReason) — the base gate says "may this node
     * time-travel at all", this says "may THIS node be the one to START the
     * advance". A replay from the coordinator is not a local origination and does
     * not consult this.
     *
     * Stands down when skew tolerance is asserted (§4) — the operator has
     * explicitly chosen independent timelines.
     */
    public function localAdvanceRefusal(): ?string
    {
        if (! $this->isFollower() || $this->skewTolerated()) {
            return null;
        }

        return sprintf(
            'This node is not the demo-mesh time coordinator. Advance time on the coordinator (%s); '
            .'its advance replays here automatically. Assert skew tolerance to advance this node independently.',
            $this->coordinatorLabel(),
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Originate — the coordinator's apply path
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Advance the world here AND publish it to the mesh. Mints a stable
     * advance_id, runs the ordinary engine advance, records the ledger row, and
     * appends the `demo.time_advance` audit entry that rides the sync tail.
     *
     * The caller is responsible for the gate (base + localAdvanceRefusal); this
     * assumes an advance is permitted. On an un-migrated box it degrades to a
     * plain engine advance with no mesh record (solo).
     *
     * @param  callable|null  $onProgress  fn(string, int, int): void
     * @return array{days:int, shifted:array<string,int>, fired:int, failed:int, advance_id:?string}
     */
    public function originateAdvance(int $days, ?callable $onProgress = null): array
    {
        // Solo / un-migrated box: no ledger to key on — just advance.
        if (! Schema::hasTable('demo_time_advances')) {
            return $this->clock->advance($days, $onProgress) + ['advance_id' => null];
        }

        $advanceId = (string) Str::uuid();
        $issuedBy = (string) (InstanceSettings::current()->server_id ?? '');
        $issuedAt = now();

        // The plan hash is the coordinator's record of WHAT it advanced — audit
        // only. Followers never re-verify it (their fires_at landscape differs by
        // design); the LOGICAL advance (N days) is what replays.
        $planHash = hash('sha256', AuditService::canonicalJson($this->clock->dryRun($days)));

        $result = $this->clock->advance($days, $onProgress);

        DB::table('demo_time_advances')->insertOrIgnore([
            'advance_id' => $advanceId,
            'days' => $days,
            'issued_by' => $issuedBy !== '' ? $issuedBy : null,
            'issued_at' => $issuedAt,
            'plan_hash' => $planHash,
            'origin' => DemoTimeAdvance::ORIGIN_LOCAL,
            'source_peer_id' => null,
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The mesh record. Rides the ordinary sync tail; a follower replays it.
        $this->audit->append(
            module: self::ADVANCE_MODULE,
            event: self::ADVANCE_EVENT,
            payload: [
                'advance_id' => $advanceId,
                'days' => $days,
                'issued_by' => $issuedBy !== '' ? $issuedBy : null,
                'issued_at' => (string) $issuedAt,
                'plan_hash' => $planHash,
                'dev_control' => true,
                'note' => 'DEMO-MESH TIME COORDINATION — the coordinator advanced the world; '
                    .'declared-demo followers replay this record idempotently through their own gate.',
            ],
            ref: self::ADVANCE_REF,
        );

        return $result + ['advance_id' => $advanceId];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Replay — a follower applies the coordinator's record on sync receive
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Apply a coordinated advance carried on an inbound sync entry, once. Returns
     * the advance_id when this call applied it, or NULL when it was skipped —
     * already applied (idempotent), refused by the gate (a real node joined the
     * mesh), not from this node's coordinator, or malformed.
     *
     * CLAIM-THEN-ADVANCE: the ledger insertOrIgnore is the lock. Only the caller
     * that wins the PK insert runs the engine advance, so two concurrent
     * deliveries of the same record advance the world exactly once.
     *
     * @param  array<string,mixed>  $payload  the `demo.time_advance` audit payload
     */
    public function replayFromSync(array $payload, FederationPeer $peer): ?string
    {
        // No ledger → cannot dedup → must not replay (a solo box that received a
        // record it has no way to remember applying). Fail safe.
        if (! Schema::hasTable('demo_time_advances')) {
            return null;
        }

        $advanceId = isset($payload['advance_id']) ? (string) $payload['advance_id'] : '';
        $days = (int) ($payload['days'] ?? 0);
        $issuedBy = isset($payload['issued_by']) && $payload['issued_by'] !== null
            ? (string) $payload['issued_by']
            : (string) $peer->server_id;

        if ($advanceId === '' || $days < 1) {
            return null; // not a replayable coordinated record
        }

        // RE-GATE ON RECEIPT — the whole mechanism refuses if this node is now
        // connected to any non-demo node. The gate travels the mesh.
        if (DevTimeControlsEnabled::refusalReason() !== null) {
            return null;
        }

        // Only replay OUR coordinator's advances.
        $myCoordinator = $this->coordinatorServerId();
        if ($myCoordinator === null) {
            return null; // this node coordinates — it originates, never replays
        }
        if ($issuedBy !== $myCoordinator) {
            return null; // not from our coordinator — ignore (defense in depth)
        }

        // Claim the advance_id. Only the winner of the PK insert applies it.
        $claimed = DB::table('demo_time_advances')->insertOrIgnore([
            'advance_id' => $advanceId,
            'days' => $days,
            'issued_by' => $issuedBy,
            'issued_at' => $payload['issued_at'] ?? now(),
            'plan_hash' => isset($payload['plan_hash']) ? (string) $payload['plan_hash'] : null,
            'origin' => DemoTimeAdvance::ORIGIN_SYNC,
            'source_peer_id' => (string) $peer->server_id,
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($claimed === 0) {
            return null; // already applied — idempotent
        }

        // Apply the SAME logical advance through the SAME engine the local
        // console uses. Its own audit entry (dev.clock_advanced) records the
        // concrete effect on THIS node's timeline.
        $this->clock->advance($days);

        $this->audit->append(
            module: self::ADVANCE_MODULE,
            event: 'demo.time_advance_replayed',
            payload: [
                'advance_id' => $advanceId,
                'days' => $days,
                'issued_by' => $issuedBy,
                'source_peer_id' => (string) $peer->server_id,
                'dev_control' => true,
                'note' => 'DEMO-MESH TIME COORDINATION — replayed the coordinator\'s advance on receipt.',
            ],
            ref: self::ADVANCE_REF,
        );

        return $advanceId;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Designation — operator controls (CLI + UI parity)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Designate the coordinator: a server_id names another node (this becomes a
     * follower), NULL makes this node the coordinator. Audit-logged. No-ops (with
     * a thrown guard) on an un-migrated box, where the column does not exist.
     */
    public function setCoordinator(?string $serverId): void
    {
        $this->assertMigrated();

        $settings = InstanceSettings::current();
        $previous = $settings->time_coordinator_server_id;
        $settings->time_coordinator_server_id = $serverId !== null && $serverId !== '' ? $serverId : null;
        $settings->save();

        $this->audit->append(
            module: self::ADVANCE_MODULE,
            event: 'demo.time_coordinator_set',
            payload: [
                'coordinator_server_id' => $settings->time_coordinator_server_id,
                'previous' => $previous,
                'is_self' => $settings->time_coordinator_server_id === null,
                'dev_control' => true,
            ],
            ref: self::ADVANCE_REF,
        );
    }

    /** Flip the §4 skew-tolerance assertion. Audit-logged on every flip. */
    public function setSkewTolerance(bool $on): void
    {
        $this->assertMigrated();

        $settings = InstanceSettings::current();
        $settings->demo_time_skew_tolerated = $on;
        $settings->save();

        $this->audit->append(
            module: self::ADVANCE_MODULE,
            event: 'demo.time_skew_tolerance_set',
            payload: [
                'skew_tolerated' => $on,
                'dev_control' => true,
                'note' => $on
                    ? 'DEMO-MESH TIME COORDINATION — skew tolerance ASSERTED; this node may advance independently.'
                    : 'DEMO-MESH TIME COORDINATION — skew tolerance withdrawn; the coordinator clause re-applies.',
            ],
            ref: self::ADVANCE_REF,
        );
    }

    // ──────────────────────────────────────────────────────────────────────
    // Status — one read for the CLI and the Demo flyout (parity)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $coordinatorId = $this->coordinatorServerId();

        return [
            'role' => $this->role(),
            'coordinator' => [
                'is_self' => $coordinatorId === null,
                'server_id' => $coordinatorId,
                'label' => $coordinatorId === null ? 'this node' : $this->coordinatorLabel(),
            ],
            'demo_peers' => $this->demoPeerCount(),
            'skew_tolerated' => $this->skewTolerated(),
            'local_advance_refusal' => $this->localAdvanceRefusal(),
            'recent' => $this->recentAdvances(),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function recentAdvances(): array
    {
        if (! Schema::hasTable('demo_time_advances')) {
            return [];
        }

        return DemoTimeAdvance::query()
            ->orderByDesc('applied_at')
            ->limit(5)
            ->get(['advance_id', 'days', 'issued_by', 'origin', 'applied_at'])
            ->map(static fn (DemoTimeAdvance $a): array => [
                'advance_id' => $a->advance_id,
                'days' => $a->days,
                'issued_by' => $a->issued_by,
                'origin' => $a->origin,
                'applied_at' => (string) $a->applied_at,
            ])
            ->all();
    }

    /** A human label for the designated coordinator — its peer name if we know it. */
    private function coordinatorLabel(): string
    {
        $id = $this->coordinatorServerId();
        if ($id === null) {
            return 'this node';
        }

        if (Schema::hasTable('federation_peers')) {
            $name = DB::table('federation_peers')->where('server_id', $id)->value('name');
            if (is_string($name) && $name !== '') {
                return $name.' ('.Str::limit($id, 8, '').')';
            }
        }

        return Str::limit($id, 8, '');
    }

    private function assertMigrated(): void
    {
        if (! Schema::hasColumn('instance_settings', 'time_coordinator_server_id')) {
            throw new \RuntimeException(
                'Demo-mesh time coordination is not available on this instance — the '
                .'2026_07_29_180000_demo_mesh_time_coordination migration has not been applied.'
            );
        }
    }
}
