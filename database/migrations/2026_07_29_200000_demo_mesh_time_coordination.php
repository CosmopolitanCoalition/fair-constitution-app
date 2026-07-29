<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo-mesh time coordination (Wave 3, lane 2 — the build half of
 * docs/plans/launch/DEMO_MESH_TIME_COORDINATION.md). Additive schema applied on
 * top of the flattened baseline (instance_settings lives in
 * database/schema/pgsql-schema.sql). Real-dated LAST in the wave-3 slot (the
 * desk's order 1 → 15 → me) — after lane 1's Type B district grouping
 * (2026_07_29_150000) and lane 15's education tables (2026_07_29_190000). The
 * service/CLI/UI layer shipped ahead of it (fa4e628, degrading to solo without
 * the columns); this migration lands on the desk's slot signal. down() proven
 * live before this landed (batch-scoped rollback, DemoMeshTimeCoordinationMigrationTest).
 *
 * WHY. Full Faith & Credit syncs *records*, not *time*. When a mesh made only of
 * declared demo instances time-travels (the ruled capability, §10 item 4), each
 * sovereign advancing independently skews every deadline whose records cross the
 * mesh — node A believes an election window is open, node B still waits on it.
 * The fix is ONE coordinating node whose advance rides the sync stream as an
 * idempotent signed record that every other demo node replays through its own
 * gate. This migration lays the two objects that design named:
 *
 *   instance_settings.time_coordinator_server_id   NULL = this node coordinates
 *       (the authoritative_server_id idiom: NULL means "us"). Set = the server_id
 *       of the node whose advances this one replays; a local advance here is
 *       refused with that coordinator named.
 *
 *   instance_settings.demo_time_skew_tolerated      the escape hatch (§4). Off by
 *       default. When an operator asserts it (audit-logged on flip), the
 *       coordinator clause stands down and this node may advance independently —
 *       an explicit per-mesh choice, never an implicit fallback.
 *
 *   demo_time_advances                              the idempotency ledger. One
 *       row per LOGICAL advance the coordinator issued, keyed by the coordinator-
 *       minted advance_id (stable across nodes — the audit seq/hash are per-node
 *       and cannot dedup a replay). insertOrIgnore on the PK makes replay a
 *       no-op and makes the whole mechanism survive a restart: an advance already
 *       in the ledger is never re-applied, whatever order records arrive in.
 *
 * Append-only ledger: no deleted_at. The values (server ids, days) are app-layer
 * validated, not DB enums — consistent with how peer metadata stores class/mode.
 * Honest down() proven while both objects are cheap (0 rows on a fresh box).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            // NULL = this node coordinates (the authoritative_server_id idiom).
            $table->uuid('time_coordinator_server_id')->nullable();
            // The §4 escape hatch — off by default, the fail-closed (strict) direction.
            $table->boolean('demo_time_skew_tolerated')->default(false);
        });

        Schema::create('demo_time_advances', function (Blueprint $table) {
            // The coordinator-minted dedup key — stable across every node in the
            // mesh, unlike the per-node audit seq/hash. PK so insertOrIgnore is
            // the idempotency guard.
            $table->uuid('advance_id')->primary();
            $table->integer('days');
            // The coordinator that originated this advance (its server_id). Nullable:
            // a solo node with no federation identity yet can still record its own
            // advances (issued_by NULL = "us, unminted"); no follower replays those.
            $table->uuid('issued_by')->nullable();
            // When the coordinator originated it (preserved on every replaying node).
            $table->timestampTz('issued_at');
            // sha256 of the coordinator's dry-run plan — informational/audit only.
            // The receiver does NOT re-verify against its own plan: each node's
            // fires_at landscape legitimately differs; the LOGICAL advance (N days)
            // is what replays, not the concrete plan.
            $table->string('plan_hash', 64)->nullable();
            // 'local' = originated on this node; 'sync' = replayed from the coordinator.
            $table->string('origin', 8);
            // The peer this replay arrived from (NULL when origin = local).
            $table->uuid('source_peer_id')->nullable();
            // When THIS node applied it (may lag issued_at by the sync interval).
            $table->timestampTz('applied_at');
            $table->timestampsTz();

            $table->index('issued_by');
            $table->index('issued_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_time_advances');

        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn(['time_coordinator_server_id', 'demo_time_skew_tolerated']);
        });
    }
};
