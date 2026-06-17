<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G-OP) — the local<->mesh sync ledger: records that local operator
 * account A on this instance is the same operator as mesh identity M. This is
 * the home_server_id analogue made many-to-many — one mesh operator may hold a
 * local account on several instances (a traveling operator recognized
 * everywhere). `linked_via_peer_id` records which authenticated peer the link
 * rode in on (the Flow-B link-proof), for audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mesh_operator_local_links', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('operator_account_id');         // the LOCAL account on this instance
            $table->uuid('mesh_operator_id');            // the mesh-wide identity it links to
            $table->uuid('linked_via_peer_id')->nullable(); // which trusted peer the link rode in on
            $table->timestampTz('linked_at');
            $table->timestampTz('unlinked_at')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('mesh_operator_id');
            $table->index('operator_account_id');
        });

        DB::statement('ALTER TABLE mesh_operator_local_links ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        // An account links to at most one live mesh identity at a time.
        DB::statement(
            'CREATE UNIQUE INDEX mesh_operator_local_links_account_unique '
          .'ON mesh_operator_local_links (operator_account_id) WHERE deleted_at IS NULL AND unlinked_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('mesh_operator_local_links');
    }
};
