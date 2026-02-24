# ETL Scripts — Fair Constitution App

Geospatial data pipeline for importing boundary polygons, population data,
and WorldPop raster tiles into PostgreSQL/PostGIS.

## Scripts

| Script | Purpose |
|---|---|
| `seed_database.py` | Master orchestrator — runs the full pipeline in order |
| `import_geoboundaries.py` | Phase 1: boundary polygons + hierarchy from geoBoundaries |
| `import_worldpop.py` | Phase 2: WorldPop 100m raster → `jurisdictions.population` + optional tile loading |
| `run_skater.py` | Phase 3 stub: SKATER district drawing (Phase 2 app roadmap) |
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

## Architecture Notes

### Why `docker compose run --rm` instead of `exec`

`exec` attaches to a running container. The ETL container has no persistent
process — it starts on demand, runs the script, and exits. `run --rm` creates
a fresh container and removes it on exit.

### Why Phase 2 runs in a subprocess

`import_geoboundaries.py` uses geopandas/fiona (one GDAL instance). `import_worldpop.py`
uses rasterio (another GDAL instance). Two GDAL instances in the same process cause
a segfault. `seed_database.py` runs Phase 2 in a subprocess via `sys.executable -c "..."`
to isolate the GDAL instances.

### Memory management

- IND ADM6: 649,710 polygons — fetched in chunks of 2,000 to avoid OOM
- Large polygons (AUS outback): tiled raster reads (5,000×5,000 px windows)
- Raster tile loading: 50 tiles per INSERT batch (~15–25 MB peak)

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
