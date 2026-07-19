<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Autoscale cycle 2 (operator rulings 2026-07-19) — additive.
 *
 *  R1 — leaf sizing law: leaves follow the SAME law as parents (floor-clamp
 *       only); over-ceiling leaves line-split their own districts. No schema
 *       change needed for the law itself.
 *  R2 — work ordering: est_districts (ceil(type_a/ceiling)) + cascade_height
 *       (subtree height, leaves 0) stored on items — the new position key is
 *       est ASC, height ASC, adm DESC, population ASC (simplest work first).
 *  R3 — Type B ladder: type_b_rep_floor records the per-constituent
 *       representation the ladder settled on (5→4→3→2);
 *       type_b_needs_districting flags legislatures still over Type A at 2
 *       — the DEFERRED "Type B districting" worklist (compact equal
 *       groupings sharing rep panels), 9,708 on the live box.
 *  R4 — area-proportional fallback: district_subdivisions.population_source
 *       gains 'area_proportional' (zero-raster-coverage giants measure by
 *       population × area-share).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legislatures', function (Blueprint $table) {
            $table->unsignedSmallInteger('type_b_rep_floor')->nullable();
            $table->boolean('type_b_needs_districting')->default(false);
        });
        DB::statement('
            CREATE INDEX legislatures_type_b_districting_idx
                ON legislatures (jurisdiction_id)
             WHERE type_b_needs_districting
        ');

        Schema::table('autoscale_items', function (Blueprint $table) {
            $table->unsignedSmallInteger('est_districts')->nullable();
            $table->unsignedSmallInteger('cascade_height')->nullable();
        });

        // population_source: widen for 'area_proportional' (17 chars) and
        // allow it in the CHECK.
        DB::statement('
            ALTER TABLE district_subdivisions
            ALTER COLUMN population_source TYPE character varying(32)
        ');
        DB::statement('
            ALTER TABLE district_subdivisions
             DROP CONSTRAINT IF EXISTS district_subdivisions_population_source_check
        ');
        DB::statement("
            ALTER TABLE district_subdivisions
              ADD CONSTRAINT district_subdivisions_population_source_check
            CHECK (population_source::text = ANY (ARRAY['worldpop_raster'::character varying, 'civic'::character varying, 'manual_override'::character varying, 'area_proportional'::character varying]::text[]))
        ");
    }

    public function down(): void
    {
        DB::statement('
            ALTER TABLE district_subdivisions
             DROP CONSTRAINT IF EXISTS district_subdivisions_population_source_check
        ');
        DB::statement("
            ALTER TABLE district_subdivisions
              ADD CONSTRAINT district_subdivisions_population_source_check
            CHECK (population_source::text = ANY (ARRAY['worldpop_raster'::character varying, 'civic'::character varying, 'manual_override'::character varying]::text[]))
        ");
        DB::statement('
            ALTER TABLE district_subdivisions
            ALTER COLUMN population_source TYPE character varying(16)
        ');
        Schema::table('autoscale_items', function (Blueprint $table) {
            $table->dropColumn(['est_districts', 'cascade_height']);
        });
        DB::statement('DROP INDEX IF EXISTS legislatures_type_b_districting_idx');
        Schema::table('legislatures', function (Blueprint $table) {
            $table->dropColumn(['type_b_rep_floor', 'type_b_needs_districting']);
        });
    }
};
