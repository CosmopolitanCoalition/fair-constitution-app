-- Run automatically on first container boot
-- Enables PostGIS spatial extensions in the fair_constitution database

CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS postgis_raster;         -- raster storage for WorldPop tiles
CREATE EXTENSION IF NOT EXISTS postgis_topology;
CREATE EXTENSION IF NOT EXISTS fuzzystrmatch;          -- needed for address standardizer
CREATE EXTENSION IF NOT EXISTS postgis_tiger_geocoder; -- optional but useful

-- Enable GDAL GTiff driver so ST_FromGDALRaster can load WorldPop raster tiles.
-- GDAL drivers are disabled by default in PostGIS for security; GTiff is the only
-- driver needed by this application.
ALTER DATABASE fair_constitution SET postgis.gdal_enabled_drivers TO 'GTiff';

-- Phase K-3: the Matrix homeserver + the Matrix Authentication Service (MAS) each need
-- their OWN logical database on this shared server. Synapse REQUIRES C collation, which the
-- server-wide --locale=C (POSTGRES_INITDB_ARGS) already gives template0. This block runs ONLY
-- on a FRESH postgres_data volume; warm dev/worktree stacks create these via the deploy guard
-- (deploy.sh) or `php artisan matrix:setup` (K3-D). gen_random_uuid etc. are not needed here.
CREATE DATABASE matrix      WITH OWNER fc_user ENCODING 'UTF8' LC_COLLATE 'C' LC_CTYPE 'C' TEMPLATE template0;
CREATE DATABASE matrix_auth WITH OWNER fc_user ENCODING 'UTF8' LC_COLLATE 'C' LC_CTYPE 'C' TEMPLATE template0;

-- Verify
SELECT PostGIS_Full_Version();
