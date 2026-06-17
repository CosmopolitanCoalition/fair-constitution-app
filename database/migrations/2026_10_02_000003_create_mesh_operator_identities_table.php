<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G-OP) — the MESH-WIDE operator identity (the synced object). Carries
 * only a stable UUID + a non-secret display handle + provenance; NO secret, NO
 * credential. Every instance that has a linked local operator holds a replica,
 * gossiped like a G9 directory entry. A mesh operator is *defined* by the device
 * keys bound to this id in mesh_operator_keys — this row is just the anchor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mesh_operator_identities', function (Blueprint $table) {
            $table->uuid('id')->primary();               // the mesh_operator_id, stable across the mesh

            $table->string('display_handle');            // non-secret human label (NOT a login)
            $table->uuid('genesis_server_id');           // which instance first minted this identity

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('genesis_server_id');
        });

        DB::statement('ALTER TABLE mesh_operator_identities ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('mesh_operator_identities');
    }
};
