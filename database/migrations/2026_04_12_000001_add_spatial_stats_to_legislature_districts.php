<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add pre-computed spatial stat columns to legislature_districts.
     *
     * polsby_popper  — Polsby-Popper compactness score (4π·Area / Perimeter²).
     *                  Range 0.0–1.0; 1.0 = perfect circle.
     *
     * num_geom_parts — ST_NumGeometries of the unioned member geometry.
     *                  Values > 1 on multi-member districts indicate potential
     *                  non-contiguity (island jurisdictions may cause false positives).
     *
     * Both columns are NULL until computed. ETL-generated districts (geom IS NOT NULL)
     * are backfilled inline below. PHP-created manual districts (geom IS NULL) are
     * backfilled by the districts:backfill-stats Artisan command.
     *
     * These columns eliminate the on-load ST_Union that timed out at Earth scope.
     */
    public function up(): void
    {
        Schema::table('legislature_districts', function (Blueprint $table) {
            // Precision 8,6 → 6 decimal places, e.g. 0.123456
            $table->decimal('polsby_popper', 8, 6)->nullable()->after('color_index');
            $table->unsignedSmallInteger('num_geom_parts')->nullable()->after('polsby_popper');
        });

        // Backfill: ETL-generated districts already have ld.geom stored as
        // ST_Multi(ST_Union(member_jurisdiction_geoms)).  Read it directly —
        // no JOIN, no extra ST_Union, one pass over the table.
        // Manual PHP-created districts (geom IS NULL) are left NULL here.
        DB::statement("
            UPDATE legislature_districts
            SET
                polsby_popper = CASE
                    WHEN ST_Perimeter(ST_MakeValid(geom)::geography) > 0
                    THEN (4 * pi() * ST_Area(ST_MakeValid(geom)::geography))
                         / POWER(ST_Perimeter(ST_MakeValid(geom)::geography), 2)
                    ELSE NULL
                END,
                num_geom_parts = ST_NumGeometries(ST_MakeValid(geom)),
                updated_at     = NOW()
            WHERE geom IS NOT NULL
              AND deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('legislature_districts', function (Blueprint $table) {
            $table->dropColumn(['polsby_popper', 'num_geom_parts']);
        });
    }
};
