FROM python:3.12-slim

LABEL description="Fair Constitution ETL — geoBoundaries + WorldPop loader (multi-arch: amd64 + arm64 / Raspberry Pi)"

# rasterio's manylinux wheel bundles GDAL/GEOS/PROJ via auditwheel, but expat is on the
# manylinux *baseline* whitelist, so GDAL still dynamically links the system libexpat at
# runtime rather than carrying its own. python:3.12-slim is minimal and does NOT ship
# libexpat1, so on the trimmed image `import rasterio` fails with
# "libexpat.so.1: cannot open shared object file" — the image BUILDS (every wheel resolves)
# but the WorldPop population phase dies on first import. The old image masked this: the
# gdal/geos/proj `-dev` + build-essential apt chain pulled libexpat1 in transitively. That
# chain is gone (it was the arm64 build wall), so we re-add the ONE runtime lib it provided.
# This is the build-verified-≠-runtime-verified gap a cross-build can't catch — confirmed on
# a real Raspberry Pi: libexpat1 alone is sufficient (the other rasterio.libs/*.so are
# auditwheel-bundled and resolve via RPATH); it affects amd64 too, not just arm64.
# libexpat1 is a pre-built runtime shared library, not a build dependency — no compilers, no
# source build, so the image still builds cleanly and fast on a Pi.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libexpat1 \
    && rm -rf /var/lib/apt/lists/*

# The ACTIVE pipeline (seed_database → import_geoboundaries + import_worldpop, plus the
# raster-attribution fallback) imports ONLY these seven, every one of which ships a
# self-contained binary wheel for BOTH amd64 and arm64: rasterio bundles GDAL, shapely
# bundles GEOS, psycopg2-binary bundles libpq, and numpy/scipy/pandas/ijson are pure
# wheels. So there is no build chain and no source compile (only the one runtime lib above),
# and the image builds cleanly on a Raspberry Pi.
#
# `--only-binary=:all:` makes that guarantee enforceable: if a wheel were ever missing for
# the target architecture, the build FAILS FAST with a clear message instead of silently
# dropping to a (long, failing) source build — the exact arm64 trap the old image hit.
#
# Removed (verified unused by the active ETL — only referenced in historical comments):
# spopt + libpysal (the old SKATER districting clusterer — districting is pure PHP now),
# geopandas + fiona (Phase L replaced them with the ijson streaming reader), rasterstats /
# pyproj / sqlalchemy / geoalchemy2 / tqdm / requests, AND the gdal/geos/proj `-dev` +
# build-essential apt stack they pulled in. That tail was the only thing that failed on arm64.
RUN pip install --no-cache-dir --only-binary=:all: \
    ijson==3.3.0 \
    rasterio==1.4.4 \
    shapely==2.0.4 \
    numpy==1.26.4 \
    scipy==1.13.0 \
    pandas==2.2.2 \
    psycopg2-binary==2.9.9

WORKDIR /etl

# Scripts come via bind mount (./scripts/etl → /etl)
