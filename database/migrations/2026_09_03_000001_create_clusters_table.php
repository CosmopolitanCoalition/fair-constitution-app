<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G·co-member) — a co-member cluster: co-equal read/write members that
 * ALL present `authoritative_server_id = NULL` for the subtree they jointly own.
 *
 * THE CARDINAL INVARIANT — two orthogonal axes:
 *   AUTHORITY   = jurisdictions.authoritative_server_id (NULL = our cluster owns
 *                 it). Phase-F meaning, UNCHANGED. This table NEVER touches it.
 *   LEADERSHIP  = leader_server_id (which node currently accepts writes) — a NEW
 *                 data-tier axis OBSERVED from Patroni, fenced by a monotonic
 *                 leader_epoch. Decided by Patroni, never by PHP consensus.
 *
 * A follower still presents authoritative_server_id = NULL → authorityDisposition()
 * and AuthorityFlipService need ZERO change, and no authority-path file may read
 * leadership/cluster state (the ClusterAuthoritySeparation grep pin enforces it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clusters', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name')->nullable();
            $table->string('kind', 12)->default('authority'); // authority | mirror

            // The subtree this cluster is authoritative for (soft ref); and the
            // Phase-F authority_claims row the cluster is the membership AROUND.
            $table->uuid('jurisdiction_id')->nullable();
            $table->uuid('authority_claim_id')->nullable();

            $table->boolean('is_self')->default(false); // this instance's own cluster

            // ── LEADERSHIP axis (data-tier; written by ONE method) ────────────
            $table->uuid('leader_server_id')->nullable();   // observed write-leader
            $table->bigInteger('leader_epoch')->default(0); // monotonic fence
            $table->string('topology', 16)->nullable();     // single_node | patroni
            $table->string('dcs_backend', 16)->nullable();  // etcd

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('jurisdiction_id');
        });

        DB::statement('ALTER TABLE clusters ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE clusters ADD CONSTRAINT clusters_kind_check CHECK (kind IN ('authority','mirror'))");

        // At most ONE authority cluster per jurisdiction (anti-split-brain, the
        // sibling of authority_claims' one-authority-per-jurisdiction). Mirror
        // clusters are exempt.
        DB::statement(
            "CREATE UNIQUE INDEX clusters_one_authority_cluster_per_jurisdiction "
          ."ON clusters (jurisdiction_id) WHERE deleted_at IS NULL AND kind = 'authority'"
        );

        // Exactly one cluster is ours.
        DB::statement(
            'CREATE UNIQUE INDEX clusters_one_self '
          .'ON clusters ((is_self)) WHERE deleted_at IS NULL AND is_self = true'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('clusters');
    }
};
