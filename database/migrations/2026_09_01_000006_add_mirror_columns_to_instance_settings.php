<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G1) — mark THIS instance as a read-only mirror.
 *
 * `mirror_of_server_id` set  ⇒  this instance is a mirror of the host with that
 * federation server_id; it is authoritative for NOTHING and the engine
 * write-guard (G2) refuses every constitutional write. NULL (the default) keeps
 * the instance a normal sovereign instance — Phase F semantics unchanged, and
 * `authoritative_server_id` on jurisdictions is NEVER touched by mirror state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->uuid('mirror_of_server_id')->nullable()
                ->comment('Phase G: set => this instance is a READ-ONLY mirror of the host with this server_id');
            $table->timestampTz('mirror_adopted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn(['mirror_of_server_id', 'mirror_adopted_at']);
        });
    }
};
