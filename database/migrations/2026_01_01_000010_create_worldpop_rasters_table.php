<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Create the worldpop_rasters table for storing WorldPop 100m population
     * rasters as PostGIS raster tiles.
     *
     * Each country's TIF is loaded by the ETL (import_worldpop.py --load-rasters)
     * as a set of 256×256 pixel tiles. Once loaded, the TIF files are no longer
     * required at runtime — all population-within-polygon queries are served
     * directly from this table via the population_within() SQL function.
     *
     * Usage (from Laravel or raw SQL):
     *   SELECT population_within('USA', ST_GeomFromGeoJSON($proposed_district_geojson));
     *
     * Load rasters (from ETL container, once per country):
     *   docker compose run --rm etl python seed_database.py --countries USA --load-rasters
     */
    public function up(): void
    {
        // Ensure postgis_raster is enabled — safe to run even if already present.
        // Required for existing postgres_data volumes that pre-date this migration.
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis_raster');

        // Enable GDAL GTiff driver so ST_FromGDALRaster can load WorldPop tiles.
        // GDAL drivers are disabled by default in PostGIS for security; GTiff is the
        // only driver this application needs. Safe to run on existing databases.
        DB::statement("ALTER DATABASE fair_constitution SET postgis.gdal_enabled_drivers TO 'GTiff'");

        // Raster tile table — one row per 256×256 pixel tile per country
        DB::statement('
            CREATE TABLE worldpop_rasters (
                id           UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                iso_code     VARCHAR(3)  NOT NULL,
                year         SMALLINT    NOT NULL DEFAULT 2023,
                resolution_m SMALLINT    NOT NULL DEFAULT 100,
                rast         raster      NOT NULL,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ');

        // GIST index on raster tile convex hulls — enables fast ST_Intersects lookups.
        // PostGIS uses this to identify which tiles overlap a query polygon without
        // loading all tiles for the country.
        DB::statement('
            CREATE INDEX worldpop_rasters_gist
            ON worldpop_rasters
            USING GIST (ST_ConvexHull(rast))
        ');

        // Composite index for the WHERE iso_code = ? AND year = ? filter that
        // every population_within() call uses.
        DB::statement('
            CREATE INDEX worldpop_rasters_iso_year
            ON worldpop_rasters (iso_code, year)
        ');

        // population_within() — compute population inside any polygon.
        //
        // Intended for the district drawing tool (Phase 2): given a proposed
        // district polygon, returns the number of people living within it.
        //
        // Parameters:
        //   p_iso_code  ISO3 country code (e.g. 'USA')
        //   p_geom      The polygon to query (any SRID — caller uses 4326)
        //   p_year      WorldPop year (default 2023)
        //
        // Returns BIGINT population count, or 0 if no raster tiles are loaded.
        //
        // Example — validate NC district split (6 seats / 7 seats from 13 total):
        //   SELECT
        //     population_within('USA', district_a_geom) AS pop_a,
        //     population_within('USA', district_b_geom) AS pop_b,
        //     population_within('USA', nc_geom)         AS total,
        //     ROUND(population_within('USA', district_a_geom)::numeric
        //           / NULLIF(population_within('USA', nc_geom), 0) * 100, 2) AS pct_a
        //   -- pct_a should be ~46.15% (= 6/13)
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION population_within(
                p_iso_code   VARCHAR(3),
                p_geom       GEOMETRY,
                p_year       SMALLINT DEFAULT 2023
            ) RETURNS BIGINT AS $$
                SELECT COALESCE(
                    ROUND(
                        SUM((ST_SummaryStats(ST_Clip(rast, p_geom, TRUE))).sum)
                    )::BIGINT,
                    0
                )
                FROM  worldpop_rasters
                WHERE iso_code = p_iso_code
                  AND year     = p_year
                  AND ST_Intersects(rast, p_geom);
            $$ LANGUAGE SQL STABLE;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS population_within(VARCHAR, GEOMETRY, SMALLINT)');
        DB::statement('DROP TABLE IF EXISTS worldpop_rasters');
    }
};
