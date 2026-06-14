<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G1) — mirror membership. Makes the MIRROR relationship a first-class
 * object, distinct from a sovereign peer (Phase F) and from authority.
 *
 * `role` is OUR role in the membership:
 *   - `mirror` — WE read-only-replicate the peer (the peer is our host). A mirror
 *     is authoritative for NOTHING; the backfill_* columns track our cold pull of
 *     the host's public corpus. An instance may be a `mirror` of AT MOST ONE host
 *     at a time (the one-active-mirror partial-unique below).
 *   - `host`   — WE host the peer as our mirror (the peer reads from us). Many
 *     allowed; we stay authoritative, the mirror copies us.
 *
 * (The peer's reciprocal role is recorded on `federation_peers.relation`.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cluster_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('peer_id');
            $table->foreign('peer_id')->references('id')->on('federation_peers')->cascadeOnDelete();

            // OUR role in this membership (see docblock).
            $table->string('role', 8); // mirror | host

            $table->string('state', 12)->default('requested');
            // requested | admitted | syncing | live | suspended | departed | rejected

            $table->string('admission_method', 12)->nullable(); // join_key | request

            // The subtree this mirror covers (NULL = the whole public corpus). A
            // bare id, not an FK — a fresh mirror may not yet hold the row locally.
            $table->uuid('scope_jurisdiction_id')->nullable();

            // Cold-pull progress (the `mirror`-role side only).
            $table->bigInteger('backfill_cursor_seq')->nullable();
            $table->bigInteger('backfill_target_seq')->nullable();
            $table->timestampTz('backfilled_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('peer_id');
            $table->index('state');
        });

        DB::statement('ALTER TABLE cluster_memberships ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        DB::statement("ALTER TABLE cluster_memberships ADD CONSTRAINT cluster_memberships_role_check CHECK (role IN ('mirror','host'))");
        DB::statement("ALTER TABLE cluster_memberships ADD CONSTRAINT cluster_memberships_state_check CHECK (state IN ('requested','admitted','syncing','live','suspended','departed','rejected'))");
        DB::statement("ALTER TABLE cluster_memberships ADD CONSTRAINT cluster_memberships_admission_check CHECK (admission_method IN ('join_key','request'))");

        // One membership row per (peer, role).
        DB::statement(
            'CREATE UNIQUE INDEX cluster_memberships_one_per_peer_role '
          .'ON cluster_memberships (peer_id, role) WHERE deleted_at IS NULL'
        );

        // One ACTIVE mirror: an instance read-only-mirrors at most one host at a
        // time. All qualifying rows share role='mirror', so a unique index on
        // (role) filtered to the active mirror rows admits exactly one.
        DB::statement(
            'CREATE UNIQUE INDEX cluster_memberships_one_active_mirror '
          .'ON cluster_memberships (role) '
          ."WHERE deleted_at IS NULL AND role = 'mirror' AND state NOT IN ('departed','rejected')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cluster_memberships');
    }
};
