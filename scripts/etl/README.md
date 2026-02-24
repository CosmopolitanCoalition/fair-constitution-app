# ETL Scripts — Geospatial Data Pipeline

This directory contains the Python ETL pipeline that populates the `jurisdictions` and
`constitutional_settings` tables from two open-licensed geospatial datasets.

**Before running:** Acquire the source data by following `docs/DATA_ACQUISITION.md`.

---

## Script Inventory

| Script | Purpose |
|---|---|
| `seed_database.py` | Master orchestrator — runs both phases in sequence with progress tracking |
| `import_geoboundaries.py` | Phase 1 — imports ~951K jurisdiction rows from geoBoundaries GeoJSON files |
| `import_worldpop.py` | Phase 2 — overlays WorldPop 2023 100m rasters onto jurisdiction polygons |
| `db.py` | Shared database connection, bulk insert/update helpers, TCP keepalive config |
| `languages.py` | Static ISO3 → ISO 639-1 language mapping (249 countries) |
| `fix_orphans.py` | Utility — re-chains jurisdictions with missing `parent_id` via spatial intersection |
| `rebuild_worldpop_progress.py` | Utility — rebuilds `progress.json` worldpop section from current DB state |
| `run_skater.py` | Phase 3 stub — SKATER district drawing (Phase 2 of app roadmap, not yet implemented) |

---

## Prerequisites

1. **Docker Compose** running (`docker compose up -d postgres`)
2. **Source data** present at `docs/geoBoundaries_repo/` and `docs/worldpop_100m_latest/`
   — see `docs/DATA_ACQUISITION.md` for download instructions

The ETL container (`fc_etl`) mounts:
- `./scripts/etl` → `/etl` (scripts)
- `./docs` → `/docs` (read-only source data)

---

## Running the Pipeline

> **Important:** Use `docker compose run --rm etl` (not `exec`) — the ETL container exits
> after each run and must be started fresh each time.

### Full pipeline (recommended first run)

```bash
docker compose run --rm etl python seed_database.py
```

Runs Phase 1 (boundaries) then Phase 2 (population). Total runtime: 6–12 hours globally.

### Smoke test — New Zealand only (~5 seconds)

```bash
docker compose run --rm etl python seed_database.py \
    --countries NZL --skip-population --fresh
```

### Boundaries only (skip population raster step)

```bash
docker compose run --rm etl python seed_database.py --skip-population
```

### Resume after crash or interruption

```bash
docker compose run --rm etl python seed_database.py --resume
```

Progress is saved after every ~90 seconds of work. Resuming skips all completed chunks.

### Specific countries

```bash
# Single country (boundaries + population)
docker compose run --rm etl python seed_database.py --countries NZL

# Multiple countries
docker compose run --rm etl python seed_database.py --countries USA GBR DEU FRA

# Re-run a country from scratch (purges its DB rows first)
docker compose run --rm etl python seed_database.py --countries NZL --fresh
```

---

## `seed_database.py` CLI Reference

| Flag | Type | Default | Description |
|---|---|---|---|
| `--countries ISO3 [...]` | list | all | Only process these ISO3 country codes |
| `--adm-levels N [...]` | list | all | Only process these ADM levels (0–5) |
| `--skip-population` | flag | off | Skip Phase 2 WorldPop raster overlay |
| `--fresh` | flag | off | Delete existing DB rows and ignore `progress.json` before starting |
| `--resume` | flag | on | Resume from `progress.json` (default behaviour) |
| `--log-file PATH` | string | `/etl/etl.log` | Log file path inside the container |

---

## Progress Tracking

Progress is stored in `scripts/etl/progress.json` (gitignored — machine-specific state).
It is written atomically (`.tmp` → `os.replace`) after every completed chunk so a crash
never corrupts the file.

**Structure:**
```json
{
  "earth_inserted": true,
  "geoboundaries": {
    "USA-ADM0": {"status": "done", "inserted": 1, "timestamp": "..."},
    "USA-ADM1": {"status": "done", "inserted": 51, "timestamp": "..."}
  },
  "worldpop": {
    "USA": {"status": "done", "updated": 3143, "timestamp": "..."},
    "USA:adm1": {"status": "done", "updated": 1, "timestamp": "..."},
    "USA:adm1:chunk0": {"status": "done", "updated": 1, "timestamp": "..."}
  }
}
```

**If `progress.json` is lost but data is already in the DB:**

```bash
docker compose run --rm etl python rebuild_worldpop_progress.py
```

This rebuilds the worldpop section from the database, allowing the pipeline to resume
without re-processing countries that already have population data.

---

## Architecture Notes

### Why Phase 2 runs in a subprocess

`seed_database.py` launches `import_worldpop.py` as a subprocess (not a direct import).
This is intentional: Phase 1 uses `geopandas` (GEOS/GDAL) and Phase 2 uses `rasterio`
(also GDAL). Running both in the same process causes shared-library conflicts that result
in segfaults. Subprocess isolation is the safe solution.

### Memory management for large countries

India (IND) has 649,710 ADM6 polygons. The pipeline handles this with three layers:
1. **DB chunking** (`DB_FETCH_CHUNK_SIZE=2000`) — geometries fetched in 2,000-row batches
2. **zonal_stats sub-batching** (`ZONAL_STATS_BATCH_SIZE=50`) — limits numpy mask memory per call
3. **Tiled raster fallback** — polygons whose bounding box exceeds 400 megapixels (e.g. large
   Australian outback LGAs, Siberian oblasts) are processed via windowed `rasterio.mask` reads
   in 5000×5000 pixel tiles, preventing OOM kills

### Idempotency

All inserts use `ON CONFLICT DO NOTHING`. All population updates use `UPDATE ... SET`.
Re-running any phase or country is always safe and produces the same result.

---

## Known Data Gaps

| Country | ISO3 | Issue |
|---|---|---|
| Antarctica | ATA | No WorldPop raster; skipped |
| Vatican City | VAT | ITA raster fallback used; 0 population returned (Vatican's ~44px footprint has no modelled pixels) |
| Kosovo | XKX | SRB raster fallback used; 0 population returned (WorldPop excludes Kosovo from SRB raster) |
| Greenland | GRL | National = 369, adm2 = 23,614; WorldPop constrained model limitation for sparse Arctic settlements |

These are source data limitations, not pipeline bugs. See `docs/DATA_ACQUISITION.md` for details.

---

## Database Schema (key columns)

```sql
jurisdictions (
    id              UUID PRIMARY KEY,
    name            TEXT,
    slug            TEXT UNIQUE,          -- e.g. "usa-1-united-states"
    iso_code        CHAR(3),              -- ISO 3166-1 alpha-3
    adm_level       SMALLINT,             -- 0=Earth, 1=National, 2=State, ..., 6=Sub-sub-local
    parent_id       UUID REFERENCES jurisdictions(id),
    source          TEXT,                 -- 'geoboundaries' | 'synthetic'
    geom            GEOMETRY(MultiPolygon, 4326),
    centroid        GEOMETRY(Point, 4326),
    population      BIGINT,
    population_year SMALLINT,
    official_languages JSONB,             -- e.g. ["en", "fr"]
    ...
)
```

**ADM level mapping (geoBoundaries → app):**

| geoBoundaries | `adm_level` | Meaning |
|---|---|---|
| (synthetic) | 0 | Earth — single root parent |
| ADM0 | 1 | National |
| ADM1 | 2 | State / Province |
| ADM2 | 3 | County / Region |
| ADM3 | 4 | Local |
| ADM4 | 5 | Sub-local |
| ADM5 | 6 | Sub-sub-local |
