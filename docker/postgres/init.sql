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

-- Verify
SELECT PostGIS_Full_Version();
