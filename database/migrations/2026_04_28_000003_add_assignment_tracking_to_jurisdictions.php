<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase JK — Hierarchy + Population Auto-Resolution audit columns.
 *
 * Two new columns on jurisdictions track HOW each row's parent_id and
 * population were resolved during the ETL. The operator's review surface
 * uses these to distinguish "this came directly from geoBoundaries" from
 * "we had to fall back to a heuristic" — useful for spot-checking the
 * automated decisions.
 *
 *   parent_assigned_via:
 *       'direct'              — Earth/ADM0/ADM1 (no spatial lookup needed)
 *       'skip_ancestor'       — deepest available ancestor via ST_Intersects
 *       'buffered'            — same as skip_ancestor with 0.001° tolerance
 *       'synthetic_country'   — synthesized country-level row (B pattern)
 *       NULL                   — orphan (parent_id IS NULL too) OR pre-JK row
 *
 *   population_assigned_via:
 *       'primary'             — own-iso raster matched
 *       'territory_fallback'  — sovereign+territory GREATEST() rescue
 *       NULL                   — pre-JK row OR genuinely 0/NULL pop
 *
 * INTENTIONAL NO-BACKFILL: existing rows keep both columns NULL. The next
 * fresh ETL run populates them on insert. Mixing pre-JK NULL with post-JK
 * values cleanly tells the operator which rows have been re-imported under
 * Phase JK's strategies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jurisdictions', function (Blueprint $table) {
            $table->string('parent_assigned_via', 32)->nullable()->after('parent_id');
            $table->string('population_assigned_via', 32)->nullable()->after('population_year');
        });
    }

    public function down(): void
    {
        Schema::table('jurisdictions', function (Blueprint $table) {
            $table->dropColumn(['parent_assigned_via', 'population_assigned_via']);
        });
    }
};
