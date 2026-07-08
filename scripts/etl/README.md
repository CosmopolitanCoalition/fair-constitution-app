# ETL Scripts — Fair Constitution App

Geospatial data pipeline for importing boundary polygons, population data,
and WorldPop raster tiles into PostgreSQL/PostGIS.

## Scripts

| Script | Purpose |
|---|---|
| `seed_database.py` | Master orchestrator — runs the full pipeline in order |
| `import_geoboundaries.py` | Phase 1: boundary polygons + hierarchy from geoBoundaries |
| `import_worldpop.py` | Phase 2: WorldPop 100m raster → `jurisdictions.population` + optional tile loading |
| `reresolve_parents.py` | Maintenance: clear + re-derive the entire parent hierarchy via the import-time strategy ladder |
| `db.py` | Shared DB connection and bulk insert/update helpers |

## Quickstart

Always run via `docker compose run --rm etl` (not `exec`) so the container exits when done
and doesn't stay attached.

```bash
# Full pipeline: boundaries + population (no rasters)
docker compose run --rm etl python seed_database.py

# Full pipeline including WorldPop raster tiles (one-time; eliminates TIF file dependency)
docker compose run --rm etl python seed_database.py --load-rasters

# Smoke test — NZL only, boundaries only
docker compose run --rm etl python seed_database.py --countries NZL --skip-population --fresh

# Specific countries with population and rasters
docker compose run --rm etl python seed_database.py --countries USA GBR DEU --load-rasters

# Resume after a crash
docker compose run --rm etl python seed_database.py --resume

# Boundaries only (skip WorldPop)
docker compose run --rm etl python seed_database.py --skip-population
```

## CLI Reference — `seed_database.py`

| Flag | Default | Description |
|---|---|---|
| `--countries ISO3 [...]` | all | Limit to these ISO3 codes |
| `--adm-levels N [...]` | all | Limit to these ADM levels (0–5) |
| `--skip-population` | off | Skip Phase 2 WorldPop import |
| `--load-rasters` | off | Load TIF tiles into `worldpop_rasters` table after population aggregation. One-time; after this the TIF files are not needed at runtime |
| `--fresh` | off | Ignore `progress.json`, reprocess from scratch |
| `--resume` | on | Resume from `progress.json` (default behaviour) |
| `--log-file PATH` | `/etl/etl.log` | Log file path |

## WorldPop Raster Loading (`--load-rasters`)

After the population aggregation step, each country's GeoTIFF is loaded into
the `worldpop_rasters` PostGIS table as 256×256 pixel tiles. This is a
**one-time operation** — once complete, the `docs/worldpop_100m_latest/`
directory is no longer needed at runtime.

```bash
# Load rasters for all countries (hours; run once)
docker compose run --rm etl python seed_database.py --load-rasters

# Load rasters for specific countries
docker compose run --rm etl python seed_database.py --countries USA CAN GBR --load-rasters

# Verify tiles were loaded
docker compose exec postgres psql -U fc_user -d fair_constitution \
  -c "SELECT iso_code, COUNT(*) AS tiles FROM worldpop_rasters GROUP BY iso_code ORDER BY tiles DESC LIMIT 10;"

# Test district population query (validate NC 6/13 seat split)
docker compose exec postgres psql -U fc_user -d fair_constitution \
  -c "SELECT population_within('USA', ST_Buffer(ST_Point(-79.0, 35.5), 2.0)) AS nc_sample_pop;"
```

Storage estimates per country in `worldpop_rasters`:
- Small (NZL): ~50–80 MB
- Medium (DEU): ~80–150 MB
- Large (USA): ~400–700 MB
- Very large (RUS): ~1–1.5 GB
- All 232 countries: ~20–50 GB

Once all countries are loaded, `pg_dump` will include raster data. For
logical backups, exclude the raster table (it can be re-loaded from source):
```bash
pg_dump --exclude-table=worldpop_rasters fair_constitution > backup.sql
```

## Re-deriving the parent hierarchy (`reresolve_parents.py`)

Lineage is just `parent_id` + `parent_assigned_via` (no ancestry cache), so the
whole hierarchy can be cheaply cleared and re-derived from the stored
geometries. `reresolve_parents.py` does exactly that:

1. Clears `parent_id`/`parent_assigned_via` on every row above Earth.
2. Re-anchors ALL `adm_level=1` rows (including `source='synthetic'` ones like
   PRI) back to Earth — mandatory, because the strategy ladder only covers
   levels 2–6 and Earth has no geometry to match against.
3. Runs `import_geoboundaries.post_pass_orphan_resolution()` (direct →
   exact → buffered → topological ladder + Phase S same-iso synthesis).
4. Prints before/after orphan counts and the `parent_assigned_via` histogram.

Never touches population or geometry columns; never deletes rows. Idempotent —
safe to re-run.

```bash
# Preview: planned actions + current orphan/strategy histograms, no writes
docker compose exec etl python reresolve_parents.py --dry-run

# Live run
docker compose exec etl python reresolve_parents.py
```

(It supersedes the retired `fix_orphans.py`, whose unbounded nearest-centroid
fallback could mis-parent across continents and which never wrote
`parent_assigned_via`.)

## WorldPop ISO aliases (`download_datasets.py`)

Some countries appear in WorldPop's catalogs under a different code than the
geoBoundaries ISO3 the pipeline keys on. `download_datasets.py` keeps a
`WORLDPOP_ISO_ALIASES` map (currently `XKX → KOS`): when the primary iso
resolves no raster on either the STAC path or the constructed
data.worldpop.org direct-URL path, each alias is tried before giving up, and a
hit is logged (`XKX: found via WorldPop legacy code KOS`). The downloaded
raster still lands under the primary iso's directory
(`worldpop_100m_latest/xkx/`) so the seeder finds it. Verified: the R2025A
constrained STAC has no Kosovo under any code; the legacy `Global_2000_2020`
series serves it as `KOS` only.

## Architecture Notes

### Why `docker compose run --rm` instead of `exec`

`exec` attaches to a running container. The ETL container has no persistent
process — it starts on demand, runs the script, and exits. `run --rm` creates
a fresh container and removes it on exit.

### Why Phase 2 runs in a subprocess

Historical: `import_geoboundaries.py` used geopandas/fiona (one GDAL instance) and
`import_worldpop.py` uses rasterio (another GDAL instance). Two GDAL instances in
the same process caused a segfault. Phase L removed geopandas/fiona — `import_geoboundaries.py`
is now pure Python `json.load` and passes raw GeoJSON straight to PostgreSQL via
`ST_GeomFromGeoJSON`, so the segfault risk is gone. The subprocess split is kept
for crash isolation between phases.

### Memory management

Chunk sizes are **selected automatically** at ETL startup based on the etl
container's cgroup memory limit (or host RAM in non-containerised setups). See
[`memory_budget.py`](memory_budget.py) for the detection logic and tier table.

| Profile | Cgroup tier | `BATCH_BYTE_LIMIT` | `BATCH_ROW_LIMIT` | `DB_FETCH_CHUNK_SIZE` | `RASTER_BATCH_SIZE` |
|---|---|---|---|---|---|
| `extreme` | < 1 GB | 4 MB | 500 | 250 | 10 |
| `pi-1gb` | 1–2 GB | 8 MB | 1 000 | 500 | 20 |
| `pi-2gb` | 2–4 GB | 16 MB | 2 500 | 1 000 | 30 |
| `pi-4gb` | 4–8 GB | 32 MB | 5 000 | 1 500 | 40 |
| `desktop` | 8–16 GB | 64 MB | 5 000 | 2 000 | 50 |
| `workstation` | 16+ GB | 128 MB | 10 000 | 4 000 | 100 |

The `desktop` tier matches the pre–Phase-N hardcoded values exactly, so dev
rigs with 8–16 GB available to the etl container see identical behavior.

`seed_database.py` logs the chosen profile at startup so the wizard's log-tail
panel surfaces it:

```
Memory budget: 2.0 GB → profile 'pi-2gb' (BATCH_BYTE_LIMIT=16 MB, ...)
```

Override with the `ETL_MEMORY_BUDGET_BYTES` env var when you need to force a
specific tier (testing, dev parity, or when auto-detection picks the wrong
value):

```bash
ETL_MEMORY_BUDGET_BYTES=$((4 * 1024**3)) python seed_database.py …
```

Other notes:
- IND ADM6 (~650 k polygons) fetched in `DB_FETCH_CHUNK_SIZE` chunks to avoid OOM
- Large polygons (AUS outback): tiled raster reads (5 000×5 000 px windows)

### Idempotency

All scripts are safe to re-run. Geometries use `ON CONFLICT DO NOTHING` on
`slug`. Population uses `UPDATE`. Raster tiles DELETE + re-INSERT on re-run.
Progress tracked per `(iso3, adm_level)` in `progress.json` — `--resume`
skips already-completed steps; `--fresh` starts over.

## Data Sources

| Dataset | License | Location |
|---|---|---|
| geoBoundaries v6.0 | CC BY 4.0 | `docs/geoBoundaries_repo/` (see `docs/fetch_geoboundaries.sh`) |
| WorldPop 2023 100m | CC BY 4.0 | `docs/worldpop_100m_latest/` (see `docs/fetch_worldpop.sh`) |
