<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drop columns whose writers / readers have been removed or superseded.
 *
 * legislature_districts.geom + its GiST index
 *     — the mapper renders member jurisdiction polygons directly via
 *     LegislatureController::revealedGeoJson; no unioned polygon is stored
 *     on the district row. See recomputeDistrict():
 *     "No geometry stored on the district record itself."
 *
 * legislature_districts.polsby_popper
 *     — deliberately superseded by convex_hull_ratio; every writer now
 *     sets polsby_popper to null (see recomputeDistrict and
 *     BackfillDistrictSpatialStatsCommand). CHR is preferred because it
 *     does not penalise natural coastlines or water bodies.
 *
 * legislature_districts.centroid_spread
 *     — scaffolded in 2026_04_12_000004 but never written or read. Only
 *     reference is the migration that created it.
 *
 * jurisdictions.osm_relation_id
 *     — scaffolded for OpenStreetMap ingestion that was never wired up.
 *     geoBoundaries is the sole active source (all 951k+ rows carry a
 *     geoboundaries_id; zero carry an osm_relation_id).
 *
 * jurisdictions.is_bootstrapping
 *     — flag defined but never flipped anywhere in the codebase.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS legislature_districts_geom_idx');
        DB::statement('ALTER TABLE legislature_districts DROP COLUMN IF EXISTS geom');
        DB::statement('ALTER TABLE legislature_districts DROP COLUMN IF EXISTS polsby_popper');
        DB::statement('ALTER TABLE legislature_districts DROP COLUMN IF EXISTS centroid_spread');

        DB::statement('ALTER TABLE jurisdictions DROP COLUMN IF EXISTS osm_relation_id');
        DB::statement('ALTER TABLE jurisdictions DROP COLUMN IF EXISTS is_bootstrapping');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE jurisdictions ADD COLUMN IF NOT EXISTS is_bootstrapping boolean NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE jurisdictions ADD COLUMN IF NOT EXISTS osm_relation_id varchar(255)');

        DB::statement('ALTER TABLE legislature_districts ADD COLUMN IF NOT EXISTS centroid_spread numeric(8,6)');
        DB::statement('ALTER TABLE legislature_districts ADD COLUMN IF NOT EXISTS polsby_popper numeric(8,6)');
        DB::statement('ALTER TABLE legislature_districts ADD COLUMN IF NOT EXISTS geom geometry(MultiPolygon,4326)');
        DB::statement('CREATE INDEX IF NOT EXISTS legislature_districts_geom_idx ON legislature_districts USING gist (geom)');
    }
};
