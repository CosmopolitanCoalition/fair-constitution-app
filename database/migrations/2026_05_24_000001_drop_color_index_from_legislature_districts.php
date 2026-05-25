<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop legislature_districts.color_index.
 *
 * Colors are now computed at read time by
 * LegislatureController::colorIndicesForDistricts() — a scope-local greedy
 * 7-coloring over the adjacency graph of the visible districts, called from
 * revealedGeoJson(), show()'s dmRows, and districtsAt(). No stored column,
 * no recompute job (RecolorDistrictsJob deleted), no lazy-stale flag.
 *
 * Rollback recreates the column with the same shape (unsignedTinyInteger default 0)
 * and populates it using the same formula, so any code rolled back along with
 * this migration sees the same values it would have computed inline.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('legislature_districts', function (Blueprint $table) {
            $table->dropColumn('color_index');
        });
    }

    public function down(): void
    {
        Schema::table('legislature_districts', function (Blueprint $table) {
            // Original definition from 2026_03_04_000001_add_color_index_to_legislature_districts.
            $table->unsignedTinyInteger('color_index')->default(0)->after('floor_override');
        });

        // Backfill via the same formula the application uses. Without this,
        // every row would default to 0 and the visual map would collapse to
        // a single color until the next district mutation in each scope.
        DB::statement(<<<SQL
            UPDATE legislature_districts
               SET color_index = (
                   ((district_number - 1)
                    + (abs(hashtext(coalesce(jurisdiction_id::text, legislature_id::text))) % 7))
                   % 7
               )
             WHERE deleted_at IS NULL
        SQL);
    }
};
