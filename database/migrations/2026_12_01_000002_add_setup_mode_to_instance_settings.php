<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles-onboarding campaign, Phase 1 — fold the SOLO/JOIN decision into setup. After the operator
 * account is created (createFounder), the FIRST question is whether to start a new world (solo) or
 * join an existing mesh (join). This flag drives the wizard routing: SOLO walks the build steps
 * (cosmic → constitution → geodata → districts → seat institutions); JOIN mints a federation
 * identity and connects to a host (the mirror onboarding), SKIPPING all institution-building (it
 * syncs in via the geodata seed + the audit replay). Null = the operator has not yet chosen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('setup_mode')->nullable(); // 'solo' | 'join' | null (undecided)
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('setup_mode');
        });
    }
};
