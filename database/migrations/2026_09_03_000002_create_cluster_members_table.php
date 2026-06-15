<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G·co-member) — a co-equal read/write member of a cluster. Every member
 * presents `authoritative_server_id = NULL` for the cluster's subtree; which one
 * currently WRITES is the separate leadership axis on `clusters`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cluster_members', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('cluster_id');
            $table->foreign('cluster_id')->references('id')->on('clusters')->cascadeOnDelete();

            $table->uuid('server_id'); // the member instance's federation server_id
            $table->boolean('is_self')->default(false);

            $table->string('state', 12)->default('forming'); // forming|admitted|live|suspended|departed
            $table->string('role', 16)->default('co_member');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('cluster_id');
        });

        DB::statement('ALTER TABLE cluster_members ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE cluster_members ADD CONSTRAINT cluster_members_state_check CHECK (state IN ('forming','admitted','live','suspended','departed'))");

        // One membership row per (cluster, server).
        DB::statement(
            'CREATE UNIQUE INDEX cluster_members_one_per_cluster_server '
          .'ON cluster_members (cluster_id, server_id) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cluster_members');
    }
};
