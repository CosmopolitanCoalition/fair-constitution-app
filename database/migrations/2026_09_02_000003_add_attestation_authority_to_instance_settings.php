<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G-ID) — operator gate for acting as an attestation authority. Ships
 * DARK (default false): the attestation tables + service exist but no behavior
 * changes until an instance opts in. The role-resolution path stays the live
 * RoleService derivation for local session users at every step (Art. I — roles
 * are never stored; the attestation is a portable SNAPSHOT for forwarded writes,
 * not a replacement for local derivation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->boolean('attestation_authority_enabled')->default(false)
                ->comment('Phase G G-ID: opt-in to issue standing attestations (ships dark)');
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('attestation_authority_enabled');
        });
    }
};
