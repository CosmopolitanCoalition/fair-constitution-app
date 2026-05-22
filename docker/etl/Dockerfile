FROM python:3.12-slim

LABEL description="Fair Constitution ETL — geoBoundaries, WorldPop, SKATER"

RUN apt-get update && apt-get install -y --no-install-recommends \
    gdal-bin \
    libgdal-dev \
    libgeos-dev \
    libproj-dev \
    libspatialindex-dev \
    build-essential \
    curl \
    && rm -rf /var/lib/apt/lists/*

# GDAL version pinning for geopandas compatibility
ENV GDAL_VERSION=3.6.2

# Python geospatial stack
# Phase L: ijson is the primary GeoJSON reader for import_geoboundaries (streaming
# parser — handles 600 MB+ files like CAN-ADM1 without loading the whole tree
# into memory). The previous geopandas/fiona path is kept installed for any
# archived districting scripts but no longer used by the active ETL.
RUN pip install --no-cache-dir \
    ijson==3.3.0 \
    geopandas==0.14.4 \
    rasterio==1.3.10 \
    rasterstats==0.19.0 \
    shapely==2.0.4 \
    pyproj==3.6.1 \
    fiona==1.9.6 \
    scipy==1.13.0 \
    numpy==1.26.4 \
    pandas==2.2.2 \
    psycopg2-binary==2.9.9 \
    sqlalchemy==2.0.30 \
    geoalchemy2==0.14.6 \
    spopt==0.6.0 \
    libpysal==4.9.2 \
    requests==2.32.2 \
    tqdm==4.66.4

WORKDIR /etl

# Scripts come via bind mount
