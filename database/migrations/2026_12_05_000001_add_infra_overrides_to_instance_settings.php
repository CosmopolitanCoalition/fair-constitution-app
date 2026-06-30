<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator Operations console (Phase 2) — a JSONB bag of operator-set overrides for
 * the INSTANT-tier infrastructure knobs (federation heartbeat / timeout / page size /
 * geodata origin). The InfraOverridesServiceProvider overlays these onto config() at
 * boot, so every existing config('cga.…') read picks them up with no code change and
 * an edit applies on the next request — no container restart. Empty/absent = the
 * env/config default, unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->jsonb('infra_overrides')->nullable()->after('app_release');
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('infra_overrides');
        });
    }
};
