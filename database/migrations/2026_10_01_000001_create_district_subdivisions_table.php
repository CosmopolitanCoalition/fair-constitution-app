<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase H (H0 substrate) — the geometry home for drawn / split electoral
 * sub-units of a CHILDLESS LEAF GIANT.
 *
 * The districting autoseed composes a scope's WHOLE child jurisdictions into
 * districts. A childless leaf giant (entitlement > resolved ceiling, no
 * children) has nothing to compose from; today
 * InitialDistrictMapService::clampUnassignedLeafGiants clamps it to one ceiling
 * district and audits `clamped_pending_subdivision_capability`. This table is
 * where the manual-draw (F-ELB-008) and shortest-splitline (F-ELB-007) tools
 * store the sub-shapes that lift that clamp.
 *
 * A row is an ELECTORAL sub-unit — deliberately OUTSIDE `jurisdictions` so the
 * authoritative administrative tree, residency point-in-polygon, and
 * civic-population queries are untouched (design §3.1; the privacy/authority
 * boundary, §6 C5 / R3). The autoseed-by-Webster pipeline reads these as the
 * virtual leaf-children of the giant; only the leaves (`seats` within the
 * resolved band) become districts. NOTHING here is administrative.
 *
 * Population is the AGGREGATE worldpop raster sum (population_within_multi) or a
 * civic count — never raw locations or individual records (§5 P1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('district_subdivisions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // The draft/active/archived district plan this sub-unit belongs to.
            $table->uuid('map_id');
            $table->foreign('map_id')
                  ->references('id')->on('legislature_district_maps')
                  ->onDelete('cascade');

            // The giant being subdivided (the scope). Administrative parent.
            $table->uuid('parent_jurisdiction_id');
            $table->foreign('parent_jurisdiction_id')
                  ->references('id')->on('jurisdictions')
                  ->onDelete('cascade');

            // Splitline recursion tree: a non-leaf cut points at its parent cut.
            // NULL for manual draws and for top-level splitline regions. The
            // self-referential FK is added after create() (the PK must exist first).
            $table->uuid('parent_subdivision_id')->nullable();

            // splitline | manual | composite_synthetic (CHECK added below).
            $table->string('method', 20);
            $table->string('label', 120);

            // population_source: worldpop_raster | civic | manual_override (CHECK below).
            $table->bigInteger('population')->nullable();
            $table->string('population_source', 16)->default('worldpop_raster');
            $table->smallInteger('population_year')->nullable();

            // pop / local-quota; and the Webster integer leaf seats (resolved band).
            $table->decimal('fractional_seats', 10, 6)->nullable();
            $table->smallInteger('seats')->nullable();

            // draft | active | archived (CHECK below).
            $table->string('status', 16)->default('draft');

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['map_id', 'parent_jurisdiction_id']);
            $table->index('parent_subdivision_id');
        });

        DB::statement('ALTER TABLE district_subdivisions ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        // Self-referential FK — added after create() so the PK it targets exists.
        Schema::table('district_subdivisions', function (Blueprint $table) {
            $table->foreign('parent_subdivision_id')
                  ->references('id')->on('district_subdivisions')
                  ->onDelete('cascade');
        });

        // PostGIS geometry — the drawn/split shape (authoritative for a drawn
        // district, unlike a composite which derives geometry from its members).
        DB::statement('ALTER TABLE district_subdivisions ADD COLUMN geom geometry(MultiPolygon, 4326)');
        DB::statement('ALTER TABLE district_subdivisions ADD COLUMN centroid geometry(Point, 4326)');
        DB::statement('CREATE INDEX district_subdivisions_geom_gist ON district_subdivisions USING GIST (geom)');
        DB::statement('CREATE INDEX district_subdivisions_centroid_gist ON district_subdivisions USING GIST (centroid)');

        DB::statement(
            "ALTER TABLE district_subdivisions ADD CONSTRAINT district_subdivisions_method_check "
          ."CHECK (method IN ('splitline','manual','composite_synthetic'))"
        );
        DB::statement(
            "ALTER TABLE district_subdivisions ADD CONSTRAINT district_subdivisions_population_source_check "
          ."CHECK (population_source IN ('worldpop_raster','civic','manual_override'))"
        );
        DB::statement(
            "ALTER TABLE district_subdivisions ADD CONSTRAINT district_subdivisions_status_check "
          ."CHECK (status IN ('draft','active','archived'))"
        );

        // One label per plan (live rows only).
        DB::statement(
            'CREATE UNIQUE INDEX district_subdivisions_map_label_unique '
          .'ON district_subdivisions (map_id, label) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('district_subdivisions');
    }
};
