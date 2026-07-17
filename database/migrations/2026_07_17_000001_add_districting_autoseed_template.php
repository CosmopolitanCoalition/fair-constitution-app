<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mixed-autoseed unification — additive on top of the flattened baseline
 * (REAL-dated, 2026-07-17).
 *
 * constitutional_settings.districting_autoseed_template — the DEFAULT line-split
 * method the autoseeder uses when a childless leaf giant must be cut into
 * synthetic district geometries (a Setup Option, operator ruling 2026-07-17;
 * amendable like its siblings legislature_sizing_law / voting_method).
 *
 * Enum (app-layer validated, per naming conventions — no PostgreSQL ENUM):
 *   shortest | vertical_strips | horizontal_strips | community_cells
 * matching SubdivisionAutoseedService::TEMPLATES. The mapper's per-run picker
 * can still override; this is the default the mass sweep and the panel start
 * from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('constitutional_settings', function (Blueprint $table) {
            $table->string('districting_autoseed_template', 32)->default('shortest');
        });
    }

    public function down(): void
    {
        Schema::table('constitutional_settings', function (Blueprint $table) {
            $table->dropColumn('districting_autoseed_template');
        });
    }
};
