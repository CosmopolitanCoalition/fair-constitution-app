<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * instance_settings.instance_class — the Phase O two-instance axis.
 *
 *   production  — a real instance. Consent-bearing. NEVER carries synthetic data.
 *   scale_demo  — the Attained: a full-scale illustration, federation off,
 *                 ephemeral, populated by the sim engine. Not a government
 *                 (Preamble: no consent → an illustration, never a government).
 *
 * NOT NULL DEFAULT 'production' — every existing instance and the baseline dump
 * become production automatically, which is the fail-closed direction: the
 * synthetic-data generators refuse everywhere until an operator deliberately
 * founds a scale_demo world.
 *
 * This is a DIFFERENT AXIS from game_mode (production|sandbox), which asks
 * whether the dev toolbox is legitimate in this world. They are orthogonal and
 * must never be merged: a sandbox world still bears real consent semantics; a
 * scale_demo world may have dev tooling off. See
 * docs/plans/simworld/SIM_SCALING_PLAN.md §1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('instance_class', 16)
                ->default('production')
                ->after('game_mode');
        });

        DB::statement(
            'ALTER TABLE instance_settings ADD CONSTRAINT instance_settings_instance_class_check '
            ."CHECK (instance_class IN ('production', 'scale_demo'))"
        );

        // Backfill is implicit via the column default; make the NOT NULL explicit
        // only after the default has populated every existing row.
        DB::statement("UPDATE instance_settings SET instance_class = 'production' WHERE instance_class IS NULL");
        DB::statement('ALTER TABLE instance_settings ALTER COLUMN instance_class SET NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE instance_settings DROP CONSTRAINT IF EXISTS instance_settings_instance_class_check');

        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn('instance_class');
        });
    }
};
