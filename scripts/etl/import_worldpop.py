"""
import_worldpop.py — Load WorldPop 2023 100m population rasters into the
worldpop_rasters PostGIS table, then derive jurisdictions.population directly
from those tiles via the SQL function population_within().

Pipeline per country (one TIF open per country):
  1. Open the TIF's header for metadata (CRS, extent).
  2. load_raster_to_db() — stream 256×256 tiles into worldpop_rasters.
     This is the ONLY place the TIF is read. After this point every ADM level
     below the country is computed in the database.
  3. For each adm_level present (national + sub-national):
         UPDATE jurisdictions
         SET    population = population_within(iso_code, geom, 2023)
         WHERE  id = ANY(<chunk_ids>)
     — one SQL round-trip per DB_FETCH_CHUNK_SIZE chunk.
  4. Planet-level rollup: sum country populations into the synthetic
     adm_level=0 "Earth" row (there is no planet raster).

Why SQL instead of in-process rasterio zonal stats:
  - The raster lives in the database once per country (already tiled with a
    GIST index), so ST_Clip + ST_SummaryStats runs directly against those
    tiles — no TIF reopening, no large numpy masks, no per-polygon tile-mode
    fallback for Alaska-scale regions.
  - The district mapper (PHP LegislatureController::runAutoCompositeForScope)
    already depends on the DB-resident raster. Reusing that path here deletes
    hundreds of lines of rasterio/rasterstats bookkeeping.

An ISO with no own TIF (ATA, VAT, XKX, …) skips the raster-load step but
still runs the per-ADM population pass. Phase Q's _topological_raster_fallback
then discovers any overlapping neighbour rasters via ST_Intersects and uses
the highest-yielding one. Outcome by case:

  - ATA (Antarctica): no overlapping neighbour rasters → all rows stay 0
  - VAT (Vatican): ITA's raster overlaps → 0 (Vatican < 1 WorldPop pixel,
    upstream data limit, not a code limit)
  - XKX (Kosovo): SRB's raster excludes Kosovo but ALB / MKD / MNE rasters
    have border pixels → small non-zero number (better undercount than 0)
  - Any future iso lacking an own TIF: handled identically with no curated
    list maintenance.

Phase R (2026-05-10) deleted the curated NO_WORLDPOP set and RASTER_FALLBACKS
dict — the load step now derives behaviour from "does this iso have an own
TIF on disk?" alone, and the population path is fully topological.
"""

import logging
import os
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
import psycopg2
import psycopg2.extras
import rasterio
from rasterio.io import MemoryFile

from db import get_connection, get_cursor
import heartbeat

# ─── Paths ───────────────────────────────────────────────────────────────────
# DATA_ROOT env var selects source: /archive (local archive) or /docs (fresh download).
# Defaults to /docs for backward compatibility with the legacy main-branch flow.

DATA_ROOT     = Path(os.environ.get("DATA_ROOT", "/docs"))
WORLDPOP_ROOT = DATA_ROOT / "worldpop_100m_latest"

# ─── Adaptive chunk sizing (Phase N) ──────────────────────────────────────────
# Profile-driven sizing. Selected once at import based on the container's
# cgroup memory limit (or host RAM in non-containerised setups); override with
# ETL_MEMORY_BUDGET_BYTES env var. See memory_budget.py for the tier table.
#
# DB_FETCH_CHUNK_SIZE: how many jurisdiction rows per population_within() UPDATE
# round-trip. Small chunks keep the heartbeat sub_phase counter lively; big
# chunks amortise psycopg2 overhead. The 'desktop' tier (8–16 GB) keeps the
# legacy 2000 value so dev rigs see no behavior change.
#
# RASTER_BATCH_SIZE: how many tiles to INSERT per worldpop_rasters round-trip.
# Each tile is ~300–500 KB as an in-memory GeoTIFF; the batch peak memory is
# RASTER_BATCH_SIZE × ~400 KB on the etl side plus libpq buffer. The 'desktop'
# tier keeps the legacy 50 value (~20 MB per batch).
from memory_budget import chunk_profile, detect_memory_budget_bytes

_MEMORY_BUDGET           = detect_memory_budget_bytes()
_PROFILE_NAME, _PROFILE  = chunk_profile(_MEMORY_BUDGET)
DB_FETCH_CHUNK_SIZE      = _PROFILE["DB_FETCH_CHUNK_SIZE"]
RASTER_BATCH_SIZE        = _PROFILE["RASTER_BATCH_SIZE"]

# ─── PostGIS raster loading parameters (NOT memory-bound) ─────────────────────
# Tile size for worldpop_rasters table.  256×256 splits the TIF's internal
# 512×512 blocks into 4 subtiles per block, giving the PostGIS GIST index
# fine enough granularity to quickly find tiles that overlap a query polygon
# without loading the entire country raster. Stays hardcoded — affects index
# selectivity, not memory pressure.
RASTER_TILE_SIZE = 256

# Heartbeat cadence during raster load. Emit a heartbeat every N tiles so the
# frontend gets fresh rate samples for the interpolated progress bar. We trigger
# on (tile_idx % 10 == 0) OR after every batch — whichever comes first.
RASTER_HEARTBEAT_EVERY = 10

# Natural-language labels for heartbeat sub_phase strings — keep in sync with
# SetupController::jurisdictionsCounts() (PHP) and import_geoboundaries.py.
# Index by app adm_level (NOT geoBoundaries' adm_n).
NATURAL_LABEL = {
    0: "Planet",
    1: "Country",
    2: "State / Province",
    3: "County",
    4: "Municipality",
    5: "Township",
    6: "Neighborhood",
}

# Phase P.1.2: plural form for bar labels. Matches import_geoboundaries.
NATURAL_LABEL_PLURAL = {
    0: "Planets",
    1: "Countries",
    2: "States / Provinces",
    3: "Counties",
    4: "Municipalities",
    5: "Townships",
    6: "Neighborhoods",
}

# ─── TIF filename pattern ─────────────────────────────────────────────────────

def _tif_path(iso3: str) -> Path:
    """
    Build the expected WorldPop .tif path for a given ISO3 country code.

    Pattern: /docs/worldpop_100m_latest/{iso3_lower}/{iso3_lower}_pop_2023_CN_100m_R2025A_v1.tif
    """
    iso_lower = iso3.lower()
    return WORLDPOP_ROOT / iso_lower / f"{iso_lower}_pop_2023_CN_100m_R2025A_v1.tif"


def find_worldpop_tif(iso3: str) -> Path | None:
    """
    Return the iso's own .tif path if it exists, otherwise None.

    Phase R: no surrogate-raster lookup. ISOs without their own TIF return
    None and the caller skips the raster-load step but still runs the
    per-ADM population pass. The Phase Q topological raster fallback
    (_topological_raster_fallback in this file) then picks up neighbour
    rasters via ST_Intersects automatically — VAT gets ITA's tiles,
    XKX gets ALB / MKD / MNE / SRB tiles, etc. — without any curated
    fallback dict.
    """
    primary = _tif_path(iso3)
    if primary.exists():
        return primary

    iso_lower = iso3.lower()
    parent = WORLDPOP_ROOT / iso_lower
    if parent.is_dir():
        tifs = list(parent.glob("*.tif"))
        if tifs:
            return tifs[0]

    return None


# ─── Jurisdiction fetch helpers ──────────────────────────────────────────────

def count_jurisdiction_rows_for_level(
    conn: psycopg2.extensions.connection,
    iso3: str,
    adm_level: int,
) -> int:
    """Return the number of geometry rows for a specific (iso3, adm_level)."""
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT COUNT(*) AS n
            FROM   jurisdictions
            WHERE  iso_code   = %s
              AND  adm_level  = %s
              AND  source     IN ('geoboundaries', 'synthetic')
              AND  deleted_at IS NULL
              AND  geom       IS NOT NULL
        """, (iso3, adm_level))
        row = cur.fetchone()
    return int(row["n"]) if row else 0


def fetch_jurisdiction_ids_chunk(
    conn: psycopg2.extensions.connection,
    iso3: str,
    adm_level: int,
    offset: int,
    limit: int,
) -> list[str]:
    """
    Fetch a chunk of jurisdiction UUIDs for one ADM level.

    We only need ids — population_within() reads the geometry directly from
    jurisdictions.geom inside the SQL UPDATE, so there is no point pulling
    WKB back to Python.
    """
    sql = """
        SELECT id::text AS id
        FROM   jurisdictions
        WHERE  iso_code   = %s
          AND  adm_level  = %s
          AND  source     IN ('geoboundaries', 'synthetic')
          AND  deleted_at IS NULL
          AND  geom       IS NOT NULL
        ORDER BY id
        LIMIT  %s OFFSET %s
    """
    with get_cursor(conn) as cur:
        cur.execute(sql, (iso3, adm_level, limit, offset))
        rows = cur.fetchall()
    return [str(r["id"]) for r in rows]


def get_adm_levels_for_country(conn: psycopg2.extensions.connection, iso3: str) -> list[int]:
    """Return sorted list of distinct adm_levels >= 1 present for a country.

    Sources accepted:
      - 'geoboundaries' — normal imported features
      - 'synthetic'     — country-row synthesis (Phase J1.5 + legacy fix_orphans),
                          covers PRI today and any future iso missing its
                          country-level row in geoBoundaries
    """
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT DISTINCT adm_level
            FROM   jurisdictions
            WHERE  iso_code   = %s
              AND  adm_level  >= 1
              AND  source     IN ('geoboundaries', 'synthetic')
              AND  deleted_at IS NULL
              AND  geom       IS NOT NULL
            ORDER BY adm_level
        """, (iso3,))
        rows = cur.fetchall()
    return [r["adm_level"] for r in rows]


# ─── Phase Q: topological raster fallback ──────────────────────────────────

def _topological_raster_fallback_global(
    conn: psycopg2.extensions.connection,
    log,
) -> int:
    """
    End-of-Phase-2 cleanup pass that re-applies the topological raster
    fallback over EVERY zero/null population row, now that all isos'
    rasters have been loaded.

    Why this exists (the bug it fixes). The per-chunk fallback inside
    process_adm_level() runs DURING each iso's pass, joining
    worldpop_rasters for tiles loaded so far. Phase 2 iterates isos
    alphabetically, so an iso whose population depends on a neighbour
    iso's raster (e.g. FRA-overseas commune rows in French Guiana /
    Guadeloupe / Mayotte / Réunion territory) processes BEFORE that
    neighbour loads its raster. GUF/GLP/MYT/REU all alphabetically
    follow FRA, so when FRA's per-iso fallback ran, those rasters
    didn't exist in the DB yet → fallback returned 0 → row stayed
    pop=0 via=NULL. This pass catches them after every iso has
    loaded its tiles.

    Naturally generalised — any future iso added with a similar
    dual-footprint pattern gets picked up here without enumeration.
    Same SQL as the per-iso fallback, just no chunk-id filter.

    Returns number of rows rescued. Tags rescued rows with
    population_assigned_via='topological_raster_fallback' (same tag
    as the per-iso fallback for review consistency).
    """
    sql = """
        UPDATE jurisdictions j
        SET    population              = sub.max_pop,
               population_year         = 2023,
               population_assigned_via = 'topological_raster_fallback',
               updated_at              = NOW()
        FROM   (
            SELECT j2.id,
                   MAX(population_within(r.iso_code::varchar, j2.geom,
                                         2023::smallint)) AS max_pop
            FROM   jurisdictions j2
            JOIN   worldpop_rasters r ON ST_Intersects(r.rast, j2.geom)
            WHERE  j2.deleted_at IS NULL
              AND  COALESCE(j2.population, 0) = 0
              AND  r.iso_code != j2.iso_code
            GROUP BY j2.id
            HAVING MAX(population_within(r.iso_code::varchar, j2.geom,
                                         2023::smallint)) > 0
        ) sub
        WHERE j.id = sub.id
    """
    try:
        with get_cursor(conn) as cur:
            cur.execute(sql)
            rescued = cur.rowcount
        conn.commit()
        return rescued
    except Exception as exc:
        log.warning("Global topological raster fallback failed: %s", exc)
        conn.rollback()
        return 0


def _topological_raster_fallback(
    conn: psycopg2.extensions.connection,
    ids: list[str],
    log,
) -> int:
    """
    For rows whose primary population_within($iso, ...) returned 0, find ANY
    iso whose raster tiles spatially overlap the row's geometry and use the
    highest population_within() result among them. Pure topology — no
    curated tables.

    Replaces the previous Phase K _territory_raster_fallback, which threaded
    a curated `SOVEREIGN_TERRITORIES` dict (USA → [PRI, GUM, …]) and a
    `RASTER_FALLBACKS` dict (VAT → ITA, XKX → SRB) to decide which
    surrogate isos to retry under. Both dicts were deleted in Phase R
    (2026-05-10) — same shape as Phase O's strategy ladder for parent
    assignment: ask the spatial index instead of a hand-curated list.

    What it covers automatically (no code changes needed for new cases):
      - PRI/GUM/ASM/MNP/VIR under USA: USA's raster covers them at primary
        pass already; this fallback doesn't fire (no-op for those rows).
      - "Taiwan Province" under CHN, where CHN's raster excludes Taiwan
        but TWN ships its own raster: ST_Intersects finds TWN's tiles,
        population_within('TWN', taiwan_geom) ≈ 22.7 M.
      - VAT (Vatican): ST_Intersects finds ITA's tiles; ITA raster's
        pixel at the Vatican location is empty (Vatican < 1 WorldPop
        pixel), so result stays 0 — upstream data limit, not lookup.
      - XKX (Kosovo): ST_Intersects finds SRB tiles (excluded Kosovo, so
        ~0) AND ALB / MKD / MNE tiles (border pixels with non-zero
        population). GREATEST picks the largest → small but non-zero.
      - Future ISOs with similar dual-footprint patterns: handled
        automatically.

    Tags rescued rows with population_assigned_via='topological_raster_fallback'
    so the Step 2 review surface can surface them for inspection.

    Returns the number of rows rescued.
    """
    # GREATEST against every overlapping iso's population_within result.
    # ST_Intersects(rast, geom) uses the GIST index on worldpop_rasters, so
    # the candidate set is bbox-bounded — typical row hits 0–4 candidate
    # isos (own + neighbours), not all 229 loaded isos.
    sql = """
        UPDATE jurisdictions j
        SET    population              = sub.max_pop,
               population_year         = 2023,
               population_assigned_via = 'topological_raster_fallback',
               updated_at              = NOW()
        FROM   (
            SELECT j2.id,
                   MAX(population_within(r.iso_code::varchar, j2.geom,
                                         2023::smallint)) AS max_pop
            FROM   jurisdictions j2
            JOIN   worldpop_rasters r ON ST_Intersects(r.rast, j2.geom)
            WHERE  j2.id = ANY(%s::uuid[])
              AND  COALESCE(j2.population, 0) = 0
              AND  r.iso_code != j2.iso_code   -- already tried in primary pass
            GROUP BY j2.id
            HAVING MAX(population_within(r.iso_code::varchar, j2.geom,
                                         2023::smallint)) > 0
        ) sub
        WHERE j.id = sub.id
    """
    try:
        with get_cursor(conn) as cur:
            cur.execute(sql, (ids,))
            rescued = cur.rowcount
        conn.commit()
        return rescued
    except Exception as exc:
        log.warning("Topological raster fallback failed: %s", exc)
        conn.rollback()
        return 0


# ─── ADM level processor (SQL-only) ──────────────────────────────────────────

def process_adm_level(
    conn: psycopg2.extensions.connection,
    iso3: str,
    adm_level: int,
    log: logging.Logger,
    progress: dict,
    save_progress_fn=None,
    country_jur_id: str | None = None,
    heartbeat_queue_preview: list[str] | None = None,
) -> int:
    """
    Update jurisdictions.population for all rows of (iso3, adm_level) using
    the database-resident raster via the population_within() SQL function.

    Progress is tracked per-chunk (key: "{iso3}:adm{level}:chunk{N}") AND
    flushed to disk after every chunk via save_progress_fn, so a crash
    mid-level resumes from the last completed chunk.

    Since the UPDATE is idempotent, re-running a completed chunk is safe.

    Returns total rows updated for this ADM level.
    """
    total_rows = count_jurisdiction_rows_for_level(conn, iso3, adm_level)
    if total_rows == 0:
        log.debug("%s adm%d: no geometry rows — skipping", iso3, adm_level)
        return 0

    n_chunks = (total_rows + DB_FETCH_CHUNK_SIZE - 1) // DB_FETCH_CHUNK_SIZE

    log.info(
        "%s adm%d: %d polygons → %d DB chunk(s) via population_within()",
        iso3, adm_level, total_rows, n_chunks,
    )

    # Phase P.1: stacked-progress-bar marker for this ADM level. The bar
    # ticks forward by chunk size after each chunk commits.
    # P.1.2: plural label + unit field, drop "(ADM{n})" suffix to match
    # the geoBoundaries-side cleanup.
    plural_label = NATURAL_LABEL_PLURAL.get(adm_level, f"Level {adm_level}")
    wp_bar_key = f"wp:{iso3}:adm{adm_level}"
    heartbeat.bar_start(
        key   = wp_bar_key,
        label = f"{iso3} — {plural_label}",
        total = total_rows,
        unit  = plural_label.lower(),
    )

    wp_progress   = progress.setdefault("worldpop", {})
    total_updated = 0

    for chunk_idx in range(n_chunks):
        offset      = chunk_idx * DB_FETCH_CHUNK_SIZE
        chunk_label = f"chunk {chunk_idx + 1}/{n_chunks}"
        chunk_key   = f"{iso3}:adm{adm_level}:chunk{chunk_idx}"

        level_label = NATURAL_LABEL.get(adm_level, f"Level {adm_level}")
        heartbeat.write_current(
            id               = country_jur_id,
            name             = iso3,
            iso_code         = iso3,
            adm_level        = adm_level,
            phase            = "worldpop",
            sub_phase        = f"{level_label} {offset + 1:,} of {total_rows:,}",
            queue_preview    = heartbeat_queue_preview or [],
            progress_current = min(offset + DB_FETCH_CHUNK_SIZE, total_rows),
            progress_total   = total_rows,
        )

        # Resume: skip chunks already committed in a previous (interrupted) run
        if wp_progress.get(chunk_key, {}).get("status") == "done":
            prev_updated   = wp_progress[chunk_key].get("updated", 0)
            total_updated += prev_updated
            log.debug("%s adm%d [%s]: already done (%d rows) — skipping",
                      iso3, adm_level, chunk_label, prev_updated)
            continue

        ids = fetch_jurisdiction_ids_chunk(
            conn, iso3, adm_level, offset, DB_FETCH_CHUNK_SIZE,
        )
        if not ids:
            break

        with get_cursor(conn) as cur:
            # population_within() is declared as (VARCHAR(3), GEOMETRY, SMALLINT)
            # — PostgreSQL won't implicitly cast integer→smallint during function
            # resolution, so the year literal must be explicitly cast.
            #
            # Phase JK: tag rows that get non-zero population from this primary
            # pass with population_assigned_via='primary'. Rows that come back
            # at 0 here will be retried by the territory-raster fallback below.
            #
            # Phase L: CTE evaluates population_within() exactly once per row;
            # the UPDATE then reads the cached column twice (once for the value,
            # once for the CASE) which is a column read, not a function call.
            # This roughly halves the SQL function evaluation cost on this hot
            # path (~470× chunked UPDATE per world run).
            cur.execute(
                """
                WITH pop_calc AS (
                    SELECT id,
                           population_within(%s::varchar, geom, 2023::smallint) AS pop
                    FROM   jurisdictions
                    WHERE  id = ANY(%s::uuid[])
                )
                UPDATE jurisdictions AS j
                SET    population              = pc.pop,
                       population_year         = 2023,
                       population_assigned_via = CASE
                           WHEN pc.pop > 0
                                THEN 'primary'::varchar(32)
                           ELSE NULL
                       END,
                       updated_at              = NOW()
                FROM   pop_calc pc
                WHERE  j.id = pc.id
                """,
                (iso3, ids),
            )
            updated = cur.rowcount
        conn.commit()

        # Phase Q: topological raster fallback. For any rows still at 0 after
        # the primary pass, find any iso whose raster tiles spatially overlap
        # the row's geometry and use the highest population_within() result.
        # Pure topology — same shape as Phase O's strategy ladder for parent
        # assignment. The SQL's HAVING clause makes this a cheap no-op when no
        # overlapping non-own-iso tiles yield anything; cost per call is
        # bounded by the GIST bbox prefilter on worldpop_rasters.rast.
        rescued = _topological_raster_fallback(conn, ids, log)
        if rescued > 0:
            log.info("%s adm%d [%s]: rescued %d zero-pop rows via topological raster fallback",
                     iso3, adm_level, chunk_label, rescued)

        total_updated += updated
        wp_progress[chunk_key] = {
            "status":    "done",
            "updated":   updated,
            "rescued_via_territory_fallback": rescued,
            "timestamp": datetime.now(timezone.utc).isoformat(),
        }
        if save_progress_fn:
            save_progress_fn(progress)

        if n_chunks > 1:
            log.debug(
                "%s adm%d [%s]: updated %d rows",
                iso3, adm_level, chunk_label, updated,
            )

        # Phase P.1: tick the bar forward by the size of this chunk.
        heartbeat.bar_update(wp_bar_key, min(offset + DB_FETCH_CHUNK_SIZE, total_rows))

    heartbeat.bar_complete(wp_bar_key, current=total_rows)
    log.info("%s adm%d: updated %d rows total", iso3, adm_level, total_updated)
    return total_updated


# ─── Planet-level population rollup ──────────────────────────────────────────

def rollup_planet_population(
    conn: psycopg2.extensions.connection,
    log: logging.Logger,
) -> int:
    """
    Set the synthetic planet row's (adm_level = 0, "Earth") population by
    summing all national populations (adm_level = 1).

    There is no planet-scale WorldPop raster, so rollup is the only option at
    this level. Every level below planet (countries and their descendants) is
    computed directly from the DB raster via population_within() and must
    NOT be rolled up — rollup here is exclusively for adm_level = 0.

    Called once at the end of the WorldPop phase after every country in this
    run has either succeeded, failed, or been skipped. Countries with NULL
    population (never loaded, or failed mid-run) are excluded from the sum.

    Returns:
        Number of planet rows updated (0 if no countries have population yet,
        otherwise 1).
    """
    with get_cursor(conn) as cur:
        # Only update if at least one country has a population; otherwise
        # leave Earth's value alone rather than overwriting with 0.
        cur.execute("""
            UPDATE jurisdictions AS planet
            SET
                population      = child_sum.total,
                population_year = 2023,
                updated_at      = NOW()
            FROM (
                SELECT SUM(population) AS total
                FROM jurisdictions
                WHERE adm_level  = 1
                  AND deleted_at IS NULL
                  AND population IS NOT NULL
            ) child_sum
            WHERE planet.adm_level  = 0
              AND planet.deleted_at IS NULL
              AND child_sum.total   IS NOT NULL
        """)
        updated = cur.rowcount
    if updated:
        log.info("planet rollup: Earth population updated from sum of countries")
    else:
        log.debug("planet rollup: no countries have population yet — skipped")
    return updated


# ─── National vs. sub-national population validation ─────────────────────────

def validate_national_population(
    conn: psycopg2.extensions.connection,
    iso3: str,
    log: logging.Logger,
) -> None:
    """
    Compare the direct-raster national population (adm_level=1) against the
    sum of its immediate children.  Logs a one-line report per country.

    Delta > 5 % is flagged as WARNING; otherwise INFO.  Countries with no
    children population data yet are skipped silently.

    Both sides of the comparison now come from population_within() reads
    against the same DB tiles, so deltas should be dominated by ST_Clip's
    partial-pixel handling (≤0.05 % in practice).
    """
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT
                p.population                        AS national_pop,
                SUM(c.population)                   AS children_sum,
                COUNT(c.id)                         AS child_count,
                COUNT(c.id) FILTER (
                    WHERE c.population IS NOT NULL
                )                                   AS child_with_pop
            FROM  jurisdictions p
            JOIN  jurisdictions c ON c.parent_id = p.id
            WHERE p.iso_code   = %s
              AND p.adm_level  = 1
              AND p.deleted_at IS NULL
              AND c.deleted_at IS NULL
            GROUP BY p.population
        """, (iso3,))
        row = cur.fetchone()

    if not row:
        return  # No children at all — nothing to compare

    national   = row["national_pop"]
    child_sum  = row["children_sum"]
    child_cnt  = row["child_count"]
    child_pop  = row["child_with_pop"]

    if national is None or child_sum is None or child_pop == 0:
        return  # Children not yet populated — skip

    delta     = national - child_sum
    delta_pct = (delta / national * 100) if national else 0.0

    msg = (
        "%s population check: national=%s  children_sum=%s  "
        "delta=%+d (%.2f%%)  [%d/%d children have pop]"
    )
    args = (
        iso3,
        f"{national:,}", f"{child_sum:,}",
        delta, delta_pct,
        child_pop, child_cnt,
    )

    if abs(delta_pct) > 5.0:
        log.warning(msg, *args)
    else:
        log.info(msg, *args)


# ─── PostGIS raster loading ──────────────────────────────────────────────────

def _insert_raster_batch(
    conn: psycopg2.extensions.connection,
    batch: list[tuple],
) -> None:
    """
    Bulk-insert a list of (iso_code, year, resolution_m, tile_bytes) tuples
    into worldpop_rasters using ST_FromGDALRaster.

    Each tile_bytes value is a raw GeoTIFF produced by rasterio MemoryFile —
    PostGIS decodes it via its GDAL GeoTIFF driver and stores it as a raster.
    """
    with get_cursor(conn) as cur:
        psycopg2.extras.execute_values(
            cur,
            """
            INSERT INTO worldpop_rasters (iso_code, year, resolution_m, rast)
            VALUES %s
            """,
            batch,
            template="(%s, %s, %s, ST_SetSRID(ST_FromGDALRaster(%s), 4326))",
            page_size=RASTER_BATCH_SIZE,
        )


def load_raster_to_db(
    conn: psycopg2.extensions.connection,
    iso3: str,
    tif_path: Path,
    log: logging.Logger,
    year: int = 2023,
    resolution_m: int = 100,
    country_jur_id: str | None = None,
    heartbeat_queue_preview: list[str] | None = None,
) -> int:
    """
    Load a WorldPop GeoTIFF into the worldpop_rasters table as 256×256 pixel tiles.

    Each tile is written to an in-memory GeoTIFF via rasterio.MemoryFile, then
    inserted using PostGIS's ST_FromGDALRaster — no external tools required.

    Tiles that are entirely nodata or zero (ocean, uninhabited land) are skipped
    to keep storage compact.  For a country like the USA this typically eliminates
    ~40–60% of potential tiles.

    Idempotent: existing tiles for (iso3, year) are deleted before inserting.

    Args:
        conn:             Open psycopg2 connection (fresh per country).
        iso3:             ISO3 country code to tag tiles with.
        tif_path:         Path to the WorldPop GeoTIFF for this country.
        log:              Logger instance.
        year:             WorldPop year (default 2023).
        resolution_m:     Pixel resolution in metres (default 100).
        country_jur_id:   UUID of this country's adm_level=1 jurisdiction, for
                          heartbeat continuity during the (potentially long)
                          tile-insert loop.
        heartbeat_queue_preview: ISO3 codes of the next countries in the queue.

    Returns:
        Number of tiles inserted.

    Notes:
        Fallback countries (VAT→ITA, XKX→SRB) should NOT be passed here — their
        fallback TIFs cover a much larger territory.  Skip them at the call site.
    """
    log.info("%s: loading raster into DB from %s …", iso3, tif_path.name)

    # Delete existing tiles for this country/year (idempotent re-runs)
    with get_cursor(conn) as cur:
        cur.execute(
            "DELETE FROM worldpop_rasters WHERE iso_code = %s AND year = %s",
            (iso3, year),
        )
        deleted = cur.rowcount
    if deleted:
        log.debug("%s: removed %d stale raster tiles", iso3, deleted)

    tiles_inserted = 0
    batch: list[tuple] = []

    with rasterio.open(tif_path) as _src:
        # Wrap in WarpedVRT if CRS is not EPSG:4326 (defensive; WorldPop is always 4326)
        if _src.crs and _src.crs.to_epsg() != 4326:
            from rasterio.vrt import WarpedVRT
            src_ctx = WarpedVRT(_src, crs="EPSG:4326")
        else:
            src_ctx = _src

        nodata = _src.nodata
        width  = src_ctx.width
        height = src_ctx.height

        col_steps = list(range(0, width,  RASTER_TILE_SIZE))
        row_steps = list(range(0, height, RASTER_TILE_SIZE))
        total_potential = len(col_steps) * len(row_steps)
        log.debug(
            "%s: raster %d×%d → up to %d tiles at %d×%d px",
            iso3, width, height, total_potential, RASTER_TILE_SIZE, RASTER_TILE_SIZE,
        )

        tile_idx = 0
        for row_off in row_steps:
            for col_off in col_steps:
                tile_idx += 1
                tile_h = min(RASTER_TILE_SIZE, height - row_off)
                tile_w = min(RASTER_TILE_SIZE, width  - col_off)

                window = rasterio.windows.Window(col_off, row_off, tile_w, tile_h)
                data   = src_ctx.read(1, window=window)

                # Skip tiles that are entirely nodata / zero (ocean, uninhabited)
                finite = data[~np.isnan(data)]
                if nodata is not None:
                    finite = finite[finite != nodata]
                if finite.size == 0 or float(finite.sum()) == 0.0:
                    continue

                tile_transform = src_ctx.window_transform(window)

                # Encode tile as an in-memory GeoTIFF for ST_FromGDALRaster
                with MemoryFile() as mf:
                    with mf.open(
                        driver    = "GTiff",
                        height    = tile_h,
                        width     = tile_w,
                        count     = 1,
                        dtype     = "float32",
                        crs       = src_ctx.crs,
                        transform = tile_transform,
                        nodata    = nodata,
                    ) as dst:
                        dst.write(data.astype("float32"), 1)
                    tile_bytes = mf.read()

                batch.append((iso3, year, resolution_m, psycopg2.Binary(tile_bytes)))

                # Heartbeat fires either when a batch flushes OR every
                # RASTER_HEARTBEAT_EVERY tiles, whichever comes first. This
                # keeps client-side rate samples fresh enough that the
                # interpolated progress bar advances smoothly between events.
                if (len(batch) >= RASTER_BATCH_SIZE
                        or tile_idx % RASTER_HEARTBEAT_EVERY == 0):
                    if len(batch) >= RASTER_BATCH_SIZE:
                        _insert_raster_batch(conn, batch)
                        tiles_inserted += len(batch)
                        batch = []
                    heartbeat.write_current(
                        id               = country_jur_id,
                        name             = iso3,
                        iso_code         = iso3,
                        adm_level        = 1,
                        phase            = "worldpop",
                        sub_phase        = f"loading population data ({tile_idx:,}/{total_potential:,})",
                        queue_preview    = heartbeat_queue_preview or [],
                        progress_current = tile_idx,
                        progress_total   = total_potential,
                    )
                    # P.1.2: also drive the stacked-bars panel — bar_start
                    # registered this with total=0 (unknown), so pass
                    # total_potential on every update to fix that and let
                    # the bar render a real percentage.
                    heartbeat.bar_update(
                        f"wp:{iso3}:load",
                        tile_idx,
                        total=total_potential,
                    )

    # Flush remaining tiles
    if batch:
        _insert_raster_batch(conn, batch)
        tiles_inserted += len(batch)

    conn.commit()

    log.info("%s: loaded %d raster tiles (skipped %d empty)",
             iso3, tiles_inserted, total_potential - tiles_inserted)
    return tiles_inserted


# ─── Main entry point ─────────────────────────────────────────────────────────

def import_worldpop(
    countries: list[str] | None = None,
    progress: dict = None,
    log: logging.Logger = None,
    save_progress_fn=None,
    level_filter: list[int] | None = None,
    pause_on_exception: bool = False,
    # Legacy alias kept for backwards compatibility — promoted to the new flag.
    stop_on_exception: bool = False,
) -> int:
    """
    Load WorldPop rasters into worldpop_rasters, then populate
    jurisdictions.population via population_within() SQL.

    Args:
        countries:           Optional list of ISO3 codes (None = all in DB).
        progress:            Shared progress dict (mutated in-place).
        log:                 Logger instance.
        save_progress_fn:    callable(progress) — called after each chunk, ADM
                             level, and country to flush progress atomically.
        level_filter:        If provided, only process these adm_levels AFTER the
                             raster load completes. e.g. [1] runs only the
                             national polygon update.
        pause_on_exception:  If True, on per-country error pause and ask the
                             operator (skip / retry / abort) via control files.
        stop_on_exception:   Legacy alias — silently treated the same as
                             pause_on_exception=True.

    Per country the flow is:
        load_raster_to_db() → for each adm_level: SQL UPDATE via population_within()

    The TIF is opened exactly once per country. All ADM levels are computed
    against the already-loaded DB tiles.

    Returns:
        Total number of jurisdiction rows updated by the population step.
    """
    if log is None:
        log = logging.getLogger(__name__)
    if progress is None:
        progress = {}

    pause_on_exception = pause_on_exception or stop_on_exception

    # ── Determine which ISO3 codes to process ──
    if countries:
        iso3_list = [c.upper() for c in countries]
    else:
        _conn = get_connection()
        try:
            with get_cursor(_conn) as cur:
                cur.execute("""
                    SELECT DISTINCT iso_code
                    FROM   jurisdictions
                    WHERE  source     = 'geoboundaries'
                      AND  deleted_at IS NULL
                      AND  iso_code   IS NOT NULL
                    ORDER BY iso_code
                """)
                rows = cur.fetchall()
            iso3_list = [str(r["iso_code"]).upper() for r in rows]
        finally:
            _conn.close()


    total_updated   = 0
    skipped_no_data = 0
    skipped_no_tif  = 0

    log.info("WorldPop: processing %d countries", len(iso3_list))

    # Phase P.1.1: pre-register the "Countries X / Y" summary bar BEFORE
    # the first country starts so the operator sees the Population section
    # show up at the same moment Phase 1 finishes — no flash of empty
    # state between phases. started_at is stamped now and preserved
    # across subsequent worldpop_advance_country calls so the overall
    # "Population total: Xh Ym" timer measures the full phase.
    heartbeat.worldpop_register_summary(total=len(iso3_list))

    for idx, iso3 in enumerate(iso3_list):
        queue_preview = iso3_list[idx + 1 : idx + 3]

        # Phase P.1: advance the per-country worldpop summary AND reset the
        # current-country bars list so the UI shows fresh bars for this iso.
        heartbeat.worldpop_advance_country(iso3, idx + 1, len(iso3_list))

        # Phase P.1.1: pre-register all the bars THIS country will produce
        # (raster load, then population at each ADM level that has rows).
        # Operator sees the full per-country pipeline as pending bars the
        # moment the country activates, with each transitioning pending →
        # running → done in turn. Mirrors the geoboundaries pre-register.
        try:
            _conn_pre = get_connection()
            try:
                country_adm_levels = get_adm_levels_for_country(_conn_pre, iso3)
            finally:
                _conn_pre.close()
        except Exception:
            country_adm_levels = []

        tif_path_lookahead = find_worldpop_tif(iso3)
        if tif_path_lookahead is not None:
            heartbeat.bar_register(
                key   = f"wp:{iso3}:load",
                label = f"{iso3} — Load raster tiles",
                total = 0,    # unknown until load_raster_to_db returns
                unit  = "tiles",
            )
        for adm_level in country_adm_levels:
            # P.1.2: plural label, drop "(ADM{n})" suffix.
            plural_label = NATURAL_LABEL_PLURAL.get(adm_level, f"Level {adm_level}")
            heartbeat.bar_register(
                key   = f"wp:{iso3}:adm{adm_level}",
                label = f"{iso3} — {plural_label}",
                total = 0,    # populated by bar_start when process_adm_level fires
                unit  = plural_label.lower(),
            )

        # Find the iso's own .tif file. May be None — that's fine, the
        # per-ADM population pass still runs and the Phase Q topological
        # fallback picks up overlapping neighbour rasters via
        # ST_Intersects(rast, geom). VAT/XKX/ATA/etc. flow through this
        # path without any curated lookup.
        tif_path = find_worldpop_tif(iso3)
        if tif_path is None:
            log.info("%s: no own .tif — skipping raster load (topological "
                     "fallback will use overlapping neighbour rasters if any)",
                     iso3)
            skipped_no_tif += 1
            progress.setdefault("worldpop", {})[iso3] = {
                "status":    "no_own_tif",
                "timestamp": datetime.now(timezone.utc).isoformat(),
            }

        # ── Per-country connection (fresh per country to avoid timeout) ──
        # Wrapped in `while True` so a "Retry" decision from the operator
        # re-runs this country's whole pipeline (raster load + ADM updates).
        # Most countries will exit on the first iteration via `break` at the
        # end of the success path.
        while True:
            conn = get_connection()
            try:
                # Resolve the country's adm_level=1 jurisdiction id so heartbeats
                # can drive the UI minimap during both the raster load and the
                # subsequent SQL population passes.
                country_jur_id = None
                try:
                    with get_cursor(conn) as cur:
                        cur.execute(
                            """
                            SELECT id::text AS id FROM jurisdictions
                            WHERE iso_code = %s AND adm_level = 1 AND deleted_at IS NULL
                            LIMIT 1
                            """,
                            (iso3,),
                        )
                        row = cur.fetchone()
                        if row:
                            country_jur_id = row["id"]
                except Exception:
                    country_jur_id = None

                # Heartbeat: country-level entry so the card shows iso_code immediately.
                heartbeat.write_current(
                    id            = country_jur_id,
                    name          = iso3,
                    iso_code      = iso3,
                    adm_level     = 1,
                    phase         = "worldpop",
                    sub_phase     = "loading population data",
                    queue_preview = queue_preview,
                )

                # ── Step 1: raster load (only when this iso has its own TIF) ──
                # No-own-TIF cases (ATA/VAT/XKX/...) already had tif_path set
                # to None earlier; they skip this block entirely and proceed
                # directly to the population pass, where the Phase Q
                # topological fallback handles them via overlapping
                # neighbour rasters.
                raster_key = f"rasters:{iso3}"
                load_raster = (
                    tif_path is not None
                    and progress.get("worldpop_rasters", {}).get(raster_key, {}).get("status") != "done"
                )
                if load_raster:
                    # Phase P.1: stacked-progress-bar marker for the raster
                    # load step. Total is unknown until load_raster_to_db
                    # returns the tile count; the bar shows as "running"
                    # with elapsed time, then completes with the final count.
                    load_bar_key = f"wp:{iso3}:load"
                    heartbeat.bar_start(
                        key   = load_bar_key,
                        label = f"{iso3} — Load raster tiles",
                        total = 0,   # unknown — frontend shows indeterminate
                    )
                    try:
                        tiles = load_raster_to_db(
                            conn, iso3, tif_path, log, year=2023,
                            country_jur_id=country_jur_id,
                            heartbeat_queue_preview=queue_preview,
                        )
                        # P.1.2: pass total=tiles so the bar reads
                        # "364 / 364 (100%)" at done — not "364 / 638 (57%)"
                        # which would suggest a half-loaded raster. The 638
                        # denominator was the iteration counter (grid slots,
                        # including empty/ocean tiles that aren't worth
                        # storing); the loaded headline is the only number
                        # that matters at done time.
                        heartbeat.bar_complete(load_bar_key, current=tiles, total=tiles)
                        progress.setdefault("worldpop_rasters", {})[raster_key] = {
                            "status":    "done",
                            "tiles":     tiles,
                            "timestamp": datetime.now(timezone.utc).isoformat(),
                        }
                        if save_progress_fn:
                            save_progress_fn(progress)
                    except Exception as exc:
                        heartbeat.bar_complete(load_bar_key, current=0)
                        log.error("%s: raster load failed — %s", iso3, exc, exc_info=True)
                        # P.3: surface as a UI error event
                        heartbeat.emit_event(
                            level="error", type="raster_load_failed",
                            phase="worldpop", iso=iso3,
                            msg=f"raster load failed: {exc}",
                        )
                        progress.setdefault("worldpop_rasters", {})[raster_key] = {
                            "status":    "error",
                            "error":     str(exc),
                            "timestamp": datetime.now(timezone.utc).isoformat(),
                        }
                        if save_progress_fn:
                            save_progress_fn(progress)
                        # In pause mode, propagate to outer handler for the
                        # operator decision. In legacy mode, skip the country.
                        if pause_on_exception:
                            raise
                        # Without raster tiles, population_within() returns 0. Skip
                        # the population pass for this country so we don't stamp
                        # zeros over any previously computed values.
                        break

                # ── Step 2: run population_within() per ADM level ──
                adm_levels = get_adm_levels_for_country(conn, iso3)

                if level_filter:
                    adm_levels = [l for l in adm_levels if l in level_filter]

                if not adm_levels:
                    log.warning(
                        "%s: no jurisdiction rows in DB — run import_geoboundaries first", iso3
                    )
                    break

                country_updated = 0
                for adm_level in adm_levels:
                    progress_key = f"{iso3}:adm{adm_level}"

                    if progress.get("worldpop", {}).get(progress_key, {}).get("status") == "done":
                        log.debug("%s adm%d: already done — skipping", iso3, adm_level)
                        continue

                    heartbeat.write_current(
                        id            = country_jur_id,
                        name          = iso3,
                        iso_code      = iso3,
                        adm_level     = adm_level,
                        phase         = "worldpop",
                        sub_phase     = f"{NATURAL_LABEL.get(adm_level, f'Level {adm_level}')} starting",
                        queue_preview = queue_preview,
                    )

                    updated = process_adm_level(
                        conn, iso3, adm_level, log, progress,
                        save_progress_fn=save_progress_fn,
                        country_jur_id=country_jur_id,
                        heartbeat_queue_preview=queue_preview,
                    )
                    total_updated   += updated
                    country_updated += updated

                    progress.setdefault("worldpop", {})[progress_key] = {
                        "status":    "done",
                        "updated":   updated,
                        "timestamp": datetime.now(timezone.utc).isoformat(),
                    }
                    if save_progress_fn:
                        save_progress_fn(progress)

                # Validate: national total vs. sum of children
                validate_national_population(conn, iso3, log)

                progress.setdefault("worldpop", {})[iso3] = {
                    "status":    "done",
                    "updated":   country_updated,
                    "timestamp": datetime.now(timezone.utc).isoformat(),
                }
                log.info("%s: complete", iso3)

                if save_progress_fn:
                    save_progress_fn(progress)

                break  # success path — exit the retry loop

            except Exception as exc:
                log.error("%s: unhandled error — %s", iso3, exc, exc_info=True)
                progress.setdefault("worldpop", {})[iso3] = {
                    "status":    "error",
                    "error":     str(exc),
                    "timestamp": datetime.now(timezone.utc).isoformat(),
                }

                if pause_on_exception:
                    from error_pause import wait_for_error_decision
                    decision = wait_for_error_decision(
                        country   = iso3,
                        adm_level = 1,
                        phase     = "worldpop",
                        exception = exc,
                        log       = log,
                    )
                    if decision == "abort":
                        log.warning("Operator aborted the run from error pause.")
                        raise SystemExit(2)
                    elif decision == "retry":
                        log.info("Retrying %s on operator request…", iso3)
                        # `finally` below closes the connection; the next
                        # iteration of `while True` will open a fresh one.
                        continue
                    else:  # skip
                        progress["worldpop"][iso3]["status"] = "skipped"
                        log.info("Skipping %s on operator request.", iso3)
                        break
                else:
                    # Legacy behaviour: log + mark error + move to next country.
                    break

            finally:
                conn.close()

    # ── Cleanup phase — only the topological-raster-fallback rescue
    # remains. The Phase T.8 pixel-attribution-correction work
    # (within-iso gap/overlap + cross-iso orphan attribution) was
    # ripped 2026-05-22 per the operator decision documented in
    # migration 2026_05_22_000001_rip_pixel_attribution_correction.php.
    # The within-iso nearest-sibling clamp produced row-level garbage
    # on sparse-coverage L-levels (tiny hamlets credited with millions
    # of people); the correction approach was abandoned. Phase 2's
    # per-polygon population_within + the topological raster fallback
    # below + the planet rollup is the canonical population pipeline
    # going forward.
    heartbeat.set_phase("cleanup")
    heartbeat.bar_register(
        key   = "cleanup:topo_fallback",
        label = "Topological raster fallback rescue",
        total = 1,
        unit  = "passes",
    )

    # Global topological-raster-fallback cleanup pass. Catches rows whose
    # per-iso fallback ran with incomplete raster coverage (because the
    # row's neighbour iso loaded later in the alphabetical loop). Most
    # impactful for synthetic-intermediary trees and their commune
    # descendants in foreign-iso territory (e.g. FRA-iso rows under
    # synthetic "French Guiana" L2 → GUF raster). Runs once at the end,
    # naturally generalised — no hardcoded iso lists.
    try:
        cleanup_conn = get_connection()
        try:
            log.info("Phase Q global cleanup: re-applying topological raster fallback over all zero/null-pop rows...")
            heartbeat.bar_start(
                key   = "cleanup:topo_fallback",
                label = "Topological raster fallback rescue",
                total = 1,
                unit  = "passes",
            )
            heartbeat.write_current(
                phase     = "cleanup",
                sub_phase = "topological raster fallback (single pass over all zero/null-pop rows)",
            )
            rescued_global = _topological_raster_fallback_global(cleanup_conn, log)
            log.info("Phase Q global cleanup: rescued %d rows via topological raster fallback", rescued_global)
            # Final tally: number of rows rescued. total=max(rescued, 1) so
            # the bar renders 100 % even when zero rows needed rescue (i.e.
            # the per-iso passes already nailed everything).
            heartbeat.bar_complete(
                key     = "cleanup:topo_fallback",
                current = max(rescued_global, 1),
                total   = max(rescued_global, 1),
            )
        finally:
            cleanup_conn.close()
    except Exception as exc:
        log.warning("global topological fallback cleanup failed (non-fatal): %s", exc)
        # Mark the bar done so the UI doesn't render a stuck "running" state
        # for an aborted pass. Operator inspects log_tail / events for context.
        heartbeat.bar_complete(key="cleanup:topo_fallback", current=0, total=1)

    # All cleanup work done — clear the active iso heartbeat so the UI
    # doesn't stick on the last processed country after the rollup runs.
    heartbeat.clear_current()

    # Planet-level rollup: sum country populations into Earth (adm_level=0).
    # There is no planet raster, so rollup is the only way to populate this row.
    # Runs AFTER the global cleanup so any newly-rescued populations roll up.
    try:
        rollup_conn = get_connection()
        try:
            rollup_planet_population(rollup_conn, log)
            rollup_conn.commit()
        finally:
            rollup_conn.close()
    except Exception as exc:
        log.warning("planet rollup failed (non-fatal): %s", exc)

    log.info(
        "import_worldpop complete: %d rows updated | "
        "%d skipped (no coverage) | %d skipped (no tif)",
        total_updated, skipped_no_data, skipped_no_tif
    )
    return total_updated
