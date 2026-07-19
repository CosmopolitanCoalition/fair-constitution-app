<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Autoscale pull engine (re-engineering 2026-07-19) — additive on top of the
 * flattened baseline.
 *
 * Replaces the self-rescheduling orchestrator tick chain with a
 * scheduler-driven pump + pull workers. Everything liveness-critical moves
 * into Postgres:
 *
 *  - autoscale_runs gains the DB halt flag (halt_requested_at — the Redis
 *    HALT_CACHE_KEY is retired), the pg-crash breaker state (pg_fingerprint,
 *    paused_until), and pump bookkeeping stamps.
 *  - autoscale_items gains map_id (founding maps are minted set-based at
 *    enumeration — kills the two-workers-two-maps race), child_count (the
 *    bottom-up ordering key), and claim_token (singles batch claims).
 *  - autoscale_scopes: the NEW sweep work unit — one row per composite/leaf
 *    scope of a legislature's giant cascade, materialized INCREMENTALLY as
 *    parent scopes complete (the one-frame law forbids freezing the giant
 *    tree at enumeration). Earth/China/India parallelize across workers.
 *  - autoscale_worker_leases: pump-visible worker liveness (top-up control).
 *
 * Global precompute tables (NO run_id — they are geometry-derived, survive
 * revert/re-run iterations, and are only invalidated by geometry changes,
 * which the acceptance gate locks out):
 *
 *  - jurisdiction_adjacency: sibling adjacency + shared-border lengths,
 *    computed once per geometry instead of once per sweep (Step 7's
 *    30–180 s/pair ST_Intersection was the #1 per-sweep cost).
 *  - jurisdiction_adjacency_parents: the claimable precompute worklist.
 *  - jurisdiction_centroids: ST_Centroid(geom) — the exact Step-1 expression
 *    (NEVER the mixed-provenance jurisdictions.centroid column).
 *  - jurisdiction_simplified: the exact two-tier ST_MakeValid(ST_Simplify())
 *    geoms for high-vertex rows, so heavy pairs/unions pay simplify once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('autoscale_runs', function (Blueprint $table) {
            // Operator halt — DB-backed (no cache-critical state). Non-null
            // means "halt requested"; the pump flips status and fans out the
            // per-legislature mass_halt cache flags (best-effort in-flight
            // force only).
            $table->timestampTz('halt_requested_at')->nullable();
            // pg-crash breaker: claims pause until this passes. Fingerprint =
            // postmaster start time || stats_reset (backend-OOM crash
            // recovery moves stats_reset without a postmaster restart).
            $table->timestampTz('paused_until')->nullable();
            $table->text('pg_fingerprint')->nullable();
            // Sizing dispatch throttle (correctness lives on the per-run pg
            // advisory lock inside AutoscaleSizingJob, not on this stamp).
            $table->timestampTz('sizing_lease_at')->nullable();
            // UI phase chip only — run status stays queued|sizing|mapping|….
            $table->timestampTz('precompute_started_at')->nullable();
        });

        Schema::table('autoscale_items', function (Blueprint $table) {
            // Founding map, minted set-based at enumeration (sweep items).
            $table->uuid('map_id')->nullable();
            // Bottom-up ordering key: live direct children of the item's
            // jurisdiction at enumeration time (0 for singles).
            $table->unsignedInteger('child_count')->default(0);
            // Singles batch claim owner (workers partition by token).
            $table->uuid('claim_token')->nullable();

            // Claim scans + the per-layer dashboard GROUP BY.
            $table->index(['run_id', 'kind', 'status', 'position'], 'autoscale_items_claim_idx');
            $table->index(['run_id', 'kind', 'adm_level', 'status'], 'autoscale_items_layers_idx');
        });

        Schema::create('autoscale_scopes', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->foreignUuid('run_id')
                ->constrained('autoscale_runs')->cascadeOnDelete();
            $table->foreignUuid('item_id')
                ->constrained('autoscale_items')->cascadeOnDelete();
            $table->uuid('legislature_id');
            $table->uuid('scope_jurisdiction_id');
            $table->uuid('parent_scope_id')->nullable();
            $table->unsignedSmallInteger('depth')->default(0);
            // pending | running | done | failed
            $table->string('status', 16)->default('pending');
            $table->uuid('claim_token')->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            // Incremental materialization is idempotent through this key —
            // a crash between "scope done" and "children minted" re-mints
            // into ON CONFLICT DO NOTHING territory.
            $table->unique(['run_id', 'legislature_id', 'scope_jurisdiction_id'], 'autoscale_scopes_scope_uq');
            $table->index(['run_id', 'status']);
            $table->index(['item_id', 'status']);
            $table->index(['legislature_id', 'status']);
        });

        Schema::create('autoscale_worker_leases', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            $table->uuid('run_id');
            $table->timestampTz('started_at');
            $table->timestampTz('last_seen_at');

            $table->index(['run_id', 'last_seen_at']);
        });

        // ── Global precompute tables ─────────────────────────────────────
        // Raw SQL: the schema builder has no PostGIS geometry type, and the
        // adjacency PK is composite.

        DB::statement('
            CREATE TABLE jurisdiction_adjacency (
                parent_id   uuid NOT NULL,
                j1          uuid NOT NULL,
                j2          uuid NOT NULL,
                dim         smallint NOT NULL,
                border_len  double precision,
                computed_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (parent_id, j1, j2)
            )
        ');
        // Step 7 reads WHERE parent_id = ? AND dim >= 1 — the PK covers it.

        DB::statement("
            CREATE TABLE jurisdiction_adjacency_parents (
                parent_id   uuid PRIMARY KEY,
                adm_level   smallint NOT NULL DEFAULT 0,
                child_count integer  NOT NULL DEFAULT 0,
                status      varchar(16) NOT NULL DEFAULT 'pending',
                claim_token uuid,
                duration_ms integer,
                error       text,
                updated_at  timestamptz NOT NULL DEFAULT now()
            )
        ");
        DB::statement('
            CREATE INDEX jurisdiction_adjacency_parents_claim_idx
                ON jurisdiction_adjacency_parents (status, child_count DESC)
        ');

        DB::statement('
            CREATE TABLE jurisdiction_centroids (
                jurisdiction_id uuid PRIMARY KEY,
                x double precision NOT NULL,
                y double precision NOT NULL
            )
        ');

        DB::statement('
            CREATE TABLE jurisdiction_simplified (
                jurisdiction_id uuid PRIMARY KEY,
                geom geometry(Geometry, 4326) NOT NULL
            )
        ');

        // ── Back-compat: carry a live cache halt flag into the DB column ──
        // (deploy lands mid-halted-run; the cache flag is retired with this
        // migration). Cache::get at migration time is best-effort only.
        try {
            if (\Illuminate\Support\Facades\Cache::get('autoscale.halt')) {
                DB::table('autoscale_runs')
                    ->whereIn('status', ['queued', 'sizing', 'mapping', 'halted'])
                    ->update(['halt_requested_at' => now()]);
                \Illuminate\Support\Facades\Cache::forget('autoscale.halt');
            }
        } catch (\Throwable) {
            // No cache available during migration — nothing to carry.
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS jurisdiction_simplified');
        DB::statement('DROP TABLE IF EXISTS jurisdiction_centroids');
        DB::statement('DROP TABLE IF EXISTS jurisdiction_adjacency_parents');
        DB::statement('DROP TABLE IF EXISTS jurisdiction_adjacency');
        Schema::dropIfExists('autoscale_worker_leases');
        Schema::dropIfExists('autoscale_scopes');
        Schema::table('autoscale_items', function (Blueprint $table) {
            $table->dropIndex('autoscale_items_claim_idx');
            $table->dropIndex('autoscale_items_layers_idx');
            $table->dropColumn(['map_id', 'child_count', 'claim_token']);
        });
        Schema::table('autoscale_runs', function (Blueprint $table) {
            $table->dropColumn([
                'halt_requested_at', 'paused_until', 'pg_fingerprint',
                'sizing_lease_at', 'precompute_started_at',
            ]);
        });
    }
};
