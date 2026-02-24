# Data Acquisition Guide

This guide explains how to acquire the geospatial source data required to populate the
Cosmopolitan Governance App database from scratch.

> **Why isn't this data in the repository?**
> - **geoBoundaries** gbOpen release: ~300–600 MB (file-level sparse checkout; directory-level ~1–2 GB; full repo ~3–5 GB)
> - **WorldPop** 100m rasters: ~9.5 GB of GeoTIFF files (229 countries)
>
> GitHub enforces a 100 MB per-file limit and a ~2 GB total repository soft limit.
> Both datasets are freely available under **CC BY 4.0** licenses and can be downloaded
> using the scripts provided in this directory.

---

## Overview

The ETL pipeline requires two datasets, both mounted read-only into the Docker ETL container:

| Dataset | Path in repo | Container path |
|---|---|---|
| geoBoundaries gbOpen | `docs/geoBoundaries_repo/` | `/docs/geoBoundaries_repo/` |
| WorldPop 2023 rasters | `docs/worldpop_100m_latest/` | `/docs/worldpop_100m_latest/` |

---

## Step 1 — Clone geoBoundaries (filtered)

geoBoundaries provides administrative boundary polygons (ADM0–ADM5) for 232 countries under
CC BY 4.0. Download scripts are provided for both Windows and Linux/Mac. They use **Git sparse
checkout** to download only the files the importer actually reads, skipping the humanitarian
and authoritative release types that are never used.

### Linux / macOS

```bash
bash docs/fetch_geoboundaries.sh
```

### Windows (PowerShell)

```powershell
powershell -ExecutionPolicy Bypass -File docs\fetch_geoboundaries.ps1
```

Both scripts use `git clone --depth 1 --filter=blob:none --sparse` and apply file-level
sparse checkout patterns to include only the files `import_geoboundaries.py` actually reads:

| Included | Excluded (~68% of each ADM directory) |
|---|---|
| `geoBoundaries-*-ADM[0-9].geojson` — primary boundary (~1.4 MB each) | `*-simplified.geojson/shp/topojson/dbf/shx/prj` |
| `geoBoundaries-*-ADM[0-9].shp/.dbf/.shx/.prj` — shapefile fallback | `*.topojson` + `*-simplified.topojson` |
| `geoBoundariesOpen-meta.csv` — supplementary metadata | `*-all.zip` (redundant archive of the same files) |
| | `*-metaData.json/.txt`, `*-PREVIEW.png`, citation files |
| | `releaseData/gbHumanitarian/`, `gbAuthoritative/` |

**Requirements:** git ≥ 2.26 (ships with macOS Ventura+, Ubuntu 22.04+, Windows Git 2.26+)
**Expected size:** ~300–600 MB (file-level filtered, vs ~1–2 GB directory-level, vs ~3–5 GB full clone)
**Expected time:** 5–15 minutes depending on connection speed
**Version used:** 6.0.0 (September 2023)

If you already have a full or directory-level sparse clone and want to retroactively slim it:

```bash
cd docs/geoBoundaries_repo
git sparse-checkout init --no-cone
git sparse-checkout set \
  "releaseData/gbOpen/**/geoBoundaries-*-ADM[0-9].geojson" \
  "releaseData/gbOpen/**/geoBoundaries-*-ADM[0-9].shp" \
  "releaseData/gbOpen/**/geoBoundaries-*-ADM[0-9].dbf" \
  "releaseData/gbOpen/**/geoBoundaries-*-ADM[0-9].shx" \
  "releaseData/gbOpen/**/geoBoundaries-*-ADM[0-9].prj" \
  "releaseData/geoBoundariesOpen-meta.csv"
```

After cloning, the pipeline reads from:
```
docs/geoBoundaries_repo/releaseData/gbOpen/{ISO3}/ADM{N}/geoBoundaries-{ISO3}-ADM{N}.geojson
docs/geoBoundaries_repo/releaseData/geoBoundariesOpen-meta.csv
```

---

## Step 2 — Download WorldPop Rasters

WorldPop provides 100m resolution constrained population count rasters for each country
(2023 data, R2025A release) under CC BY 4.0. Download scripts are provided for both Windows
and Linux/Mac.

### Windows (PowerShell)

```powershell
powershell -ExecutionPolicy Bypass -File docs\fetch_worldpop.ps1
```

### Linux / macOS (Bash)

Requires `curl` and `jq`:
```bash
# Install dependencies if needed:
# Ubuntu/Debian: sudo apt-get install curl jq
# macOS:         brew install curl jq

bash docs/fetch_worldpop.sh
```

Both scripts:
- Read ISO3 country codes from your cloned `geoBoundaries_repo/` directory
- Query the [WorldPop STAC API](https://api.stac.worldpop.org) for each country
- Filter for: `project=Population`, `resolution=100m`, constrained (`_CN_100m_`), year ≤ 2023
- Download the most recent matching file per country
- Skip files that already exist (safe to re-run; set `OVERWRITE=true` on Linux to force refresh)

**Expected output:** `docs/worldpop_100m_latest/{ISO3}/{ISO3}_pop_2023_CN_100m_R2025A_v1.tif`
**Expected size:** ~9.5 GB across 229 country files
**Expected time:** 2–4 hours depending on connection speed

---

## Step 3 — Run the ETL Pipeline

Once both datasets are in place, start Docker and run the full pipeline:

```bash
# Bring up the stack (postgres must be running)
docker compose up -d postgres

# Run the full pipeline (boundaries + population)
docker compose run --rm etl python seed_database.py
```

This runs in two phases:
1. **Phase 1 — geoBoundaries**: Imports ~951,000 jurisdiction rows (ADM0–ADM5 for 232 countries)
2. **Phase 2 — WorldPop**: Overlays population rasters onto all jurisdictions (~2.1M rows updated)

**Total runtime:** 6–12 hours for a full global run (Phase 2 dominates; large countries like IND,
USA, BRA, RUS, CHN take the most time).

See `scripts/etl/README.md` for the full CLI reference and resume instructions.

---

## Resuming a Partial Run

The pipeline saves progress after every chunk (~90 seconds of work) to `scripts/etl/progress.json`.
If the run is interrupted, simply restart it — it will resume from the last completed checkpoint:

```bash
docker compose run --rm etl python seed_database.py --resume
```

To rebuild `progress.json` from the current database state (e.g. after a fresh clone where
the database already has data from a previous run):

```bash
docker compose run --rm etl python rebuild_worldpop_progress.py
```

---

## Processing Individual Countries

To run or re-run specific countries:

```bash
# Single country
docker compose run --rm etl python seed_database.py --countries NZL

# Multiple countries
docker compose run --rm etl python seed_database.py --countries USA GBR DEU

# Boundaries only (skip population raster step)
docker compose run --rm etl python seed_database.py --countries NZL --skip-population
```

---

## Known Coverage Gaps

| Country | ISO3 | Issue | Population in DB |
|---|---|---|---|
| Antarctica | ATA | No WorldPop raster file; no fallback | 0 |
| Vatican City | VAT | ITA raster has no pixels mapped to Vatican's ~44-pixel footprint at 100m resolution | 0 |
| Kosovo | XKX | WorldPop excludes Kosovo territory from the SRB raster; no standalone XKX file exists | 0 |
| Greenland | GRL | WorldPop constrained model has poor coverage of sparse Arctic coastal settlements; national raster value (369) is a known WorldPop source limitation | 369 national / 23,614 via adm2 |

For all other countries, the pipeline produces accurate population totals consistent with
WorldPop 2023 estimates. The global Earth total is **7,991,808,820**.

---

## Data Licenses

| Dataset | License | Attribution |
|---|---|---|
| geoBoundaries | [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/) | Runfola D. et al. (2020) geoBoundaries: A global database of political administrative boundaries. *PLOS ONE* 15(4): e0231866. |
| WorldPop 2023 | [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/) | WorldPop (www.worldpop.org) School of Geography and Environmental Science, University of Southampton. |

---

## Supplemental Data Sources

The ETL pipeline also uses:

| Dataset | License | Use |
|---|---|---|
| OpenStreetMap | ODbL | Supplemental local boundaries (future phases) |

---

## Verification Queries

After the pipeline completes, run these queries to confirm data integrity:

```sql
-- Row counts by ADM level (should match: 1 Earth, 232 national, etc.)
SELECT adm_level, COUNT(*) AS count
FROM jurisdictions
GROUP BY adm_level
ORDER BY adm_level;

-- Global population (should be ~7,991,808,820)
SELECT population FROM jurisdictions WHERE adm_level = 0;

-- Top 10 countries by population
SELECT name, population
FROM jurisdictions
WHERE adm_level = 1
ORDER BY population DESC
LIMIT 10;

-- Countries with zero population (expected: ATA, VAT, XKX only at national level)
SELECT iso_code, name, population
FROM jurisdictions
WHERE adm_level = 1 AND (population IS NULL OR population = 0)
ORDER BY iso_code;

-- Orphan check (only Earth should have no parent)
SELECT name, adm_level FROM jurisdictions WHERE parent_id IS NULL;

-- Invalid geometry check (should return 0)
SELECT COUNT(*) FROM jurisdictions
WHERE geom IS NOT NULL AND NOT ST_IsValid(geom);
```
