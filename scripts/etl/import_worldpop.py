"""
import_worldpop.py — Overlay WorldPop 2023 100m population rasters onto
jurisdiction polygons and update the jurisdictions.population column.

Data source:  /docs/worldpop_100m_latest/{iso3_lower}/{iso3_lower}_pop_2023_CN_100m_R2025A_v1.tif
Target table: jurisdictions (columns: population, population_year)

Processing strategy:
  - One country at a time (memory-safe; rasterio streams the raster in tiles)
  - Always per-ADM-level for every country (avoids loading all ADM levels at
    once; IND ADM6 alone has 649K polygons)
  - Within each ADM level, geometries are fetched and processed in DB chunks
    of DB_FETCH_CHUNK_SIZE rows — so 649K IND ADM6 polygons never live in
    memory all at once
  - Within each chunk, zonal_stats is called in sub-batches of
    ZONAL_STATS_BATCH_SIZE polygons to limit numpy mask memory
  - Per-polygon bbox pixel-area guard: polygons whose bounding box spans more
    than MAX_BBOX_PIXELS raster pixels are processed via a TILED rasterio.mask
    fallback instead of zonal_stats — this avoids OOM while still computing
    accurate population values for large polygons like Western Australia,
    Alaska, Nunavut, and large Siberian oblasts
  - rasterstats.zonal_stats() sums pixel values within each polygon
  - Bulk UPDATE via a VALUES() join — one round-trip per chunk per ADM level
  - Idempotent: re-running overwrites population values (update, not insert)
  - Progress tracked per (iso3, adm_level) AND per chunk for crash-safe resume;
    save_progress_fn is called after every chunk commit so a mid-level crash
    resumes from the last completed chunk, not the start of the level

Why population counts in the DB vs. raw raster data:
  - The `population` column stores the AGGREGATE COUNT for display, legislature
    sizing, and quick queries — it does not replace the raster for district drawing.
  - District drawing (SKATER) needs 100m pixel resolution within a jurisdiction.
    That is served directly from the on-disk TIF at draw time via rasterio windowed
    reads — the TIF is never fully loaded, only the tiles overlapping the target
    polygons are streamed. This is fast even for large countries because the TIFs
    are internally tiled at 512×512 pixel blocks (confirmed: all WorldPop 2023 files
    use this layout).
  - So the workflow at district-draw time is:
      1. Fetch child jurisdiction geometries from DB (fast, already simplified)
      2. Open country TIF with rasterio (no full load — windowed)
      3. Run zonal_stats on just those children (10s–minutes, not hours)
      4. Pass population array + adjacency graph to SKATER

Countries without WorldPop data (ATA, VAT, XKX) are skipped with a log message.
"""

import logging
import math
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
import psycopg2
import psycopg2.extras
import rasterio
from rasterio.io import MemoryFile
from rasterio.mask import mask as rio_mask
from affine import Affine
from rasterstats import zonal_stats
from shapely import wkb as shapely_wkb
from shapely.geometry import box, mapping

from db import get_connection, get_cursor, bulk_update_populations

# ─── Paths ───────────────────────────────────────────────────────────────────

WORLDPOP_ROOT = Path("/docs/worldpop_100m_latest")

# ─── Countries without WorldPop coverage ─────────────────────────────────────

NO_WORLDPOP = {"ATA"}   # Antarctica — no WorldPop raster, no fallback available

# ─── Raster fallbacks for countries without their own WorldPop file ───────────
# VAT (Vatican City) is enclosed within Italy → use ITA raster.
# XKX (Kosovo) has no own raster → try SRB raster (WorldPop may or may not
# include Kosovo territory; outcome determined at run time).
RASTER_FALLBACKS: dict[str, str] = {
    "VAT": "ITA",
    "XKX": "SRB",
}

# ─── Zonal stats batch size ───────────────────────────────────────────────────
# rasterstats.zonal_stats() rasterizes each polygon into an in-memory mask.
# For countries with complex coastlines (NZL, GBR, IDN, PHL) the mask arrays
# are large enough to OOM-kill the container if all polygons are submitted at
# once. Process at most this many polygons per zonal_stats call.
ZONAL_STATS_BATCH_SIZE = 50

# ─── DB geometry fetch chunk size ─────────────────────────────────────────────
# How many jurisdiction rows to fetch from the DB at a time within a single
# ADM level. IND ADM6 has 649,710 rows — fetching all at once would allocate
# ~3–5 GB of shapely geometry objects. Instead we stream in chunks and
# bulk-UPDATE after each chunk, then GC the geometry list.
DB_FETCH_CHUNK_SIZE = 2000   # rows per DB fetch + zonal_stats + UPDATE cycle

# ─── Per-polygon bounding-box pixel-area limit ────────────────────────────────
# If a single polygon's axis-aligned bounding box covers more than this many
# raster pixels, the standard zonal_stats path would try to allocate a numpy
# mask that size — causing an OOM kill for continent-scale polygons.
# Polygons that exceed this limit are processed via the TILED fallback path
# (_compute_population_tiled) which reads the raster in manageable windows.
# 400 megapixels ≈ 20,000×20,000 pixels — safe to allocate (~400 MB mask).
# AUS outback LGAs can produce windows of 2.5 billion pixels → instant kill.
MAX_BBOX_PIXELS = 400_000_000    # 400 megapixels

# ─── Tiled raster reading parameters ─────────────────────────────────────────
# For polygons exceeding MAX_BBOX_PIXELS, we subdivide the polygon's bbox into
# tiles of TILE_PIXELS × TILE_PIXELS pixels and sum rasterio.mask reads.
# At 5000×5000 = 25 megapixels × 4 bytes (float32) = ~100 MB peak per tile.
# This is safe even in a 512 MB Docker container.
TILE_PIXELS = 5000

# ─── PostGIS raster loading parameters ───────────────────────────────────────
# Tile size for worldpop_rasters table.  256×256 splits the TIF's internal
# 512×512 blocks into 4 subtiles per block, giving the PostGIS GIST index
# fine enough granularity to quickly find tiles that overlap a query polygon
# without loading the entire country raster.
RASTER_TILE_SIZE = 256

# How many tiles to INSERT per database round-trip.  Each tile is ~300–500 KB
# as an in-memory GeoTIFF; 50 tiles ≈ 15–25 MB per batch — safe in 512 MB container.
RASTER_BATCH_SIZE = 50

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
    Return the .tif path if it exists, otherwise None.
    Handles case-insensitive ISO3 matching by trying uppercase folder as well.
    If no own raster is found, checks RASTER_FALLBACKS for a surrogate country.
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

    # No own raster — try fallback country
    fallback_iso = RASTER_FALLBACKS.get(iso3.upper())
    if fallback_iso:
        fallback_path = _tif_path(fallback_iso)
        if fallback_path.exists():
            return fallback_path
        # Also glob fallback folder in case filename pattern varies
        fb_lower = fallback_iso.lower()
        fb_parent = WORLDPOP_ROOT / fb_lower
        if fb_parent.is_dir():
            tifs = list(fb_parent.glob("*.tif"))
            if tifs:
                return tifs[0]

    return None


# ─── Raster metadata ─────────────────────────────────────────────────────────

def get_tif_metadata(tif_path: Path) -> dict:
    """
    Open a GeoTIFF and extract CRS, nodata value, dimensions, transform, and file size.

    Returns a dict with keys: crs_epsg, nodata, width, height, transform, size_mb
    """
    with rasterio.open(tif_path) as src:
        crs_epsg  = src.crs.to_epsg() if src.crs else None
        nodata    = src.nodata
        width     = src.width
        height    = src.height
        transform = src.transform   # Affine transform: maps pixel coords → geographic coords

    size_mb = tif_path.stat().st_size / (1024 * 1024)

    return {
        "crs_epsg":  crs_epsg,
        "nodata":    nodata,
        "width":     width,
        "height":    height,
        "transform": transform,
        "size_mb":   round(size_mb, 1),
    }


# ─── Jurisdiction geometry fetch (streaming / chunked) ───────────────────────

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


def fetch_jurisdiction_geometry_chunk(
    conn: psycopg2.extensions.connection,
    iso3: str,
    adm_level: int,
    offset: int,
    limit: int,
) -> list[dict]:
    """
    Fetch a chunk of jurisdiction UUIDs and WKB geometries for one ADM level.

    Uses OFFSET/LIMIT with ORDER BY id for stable pagination across chunks.
    adm_level=1 (national) rows are now included — large country polygons are
    handled by the tiled raster fallback in _run_zonal_stats_chunk().

    Uses ST_AsBinary (not ST_AsEWKB): shapely_wkb.loads() expects standard
    WKB without the 4-byte SRID prefix that EWKB includes. Using ST_AsEWKB
    causes shapely to silently misparse the SRID bytes as geometry coordinates,
    producing degenerate shapes with zonal_stats sum=0.

    Returns:
        [{"id": "uuid-...", "geom_wkb": "<hex-wkb>"}]
    """
    sql = """
        SELECT id, encode(ST_AsBinary(geom), 'hex') AS geom_wkb
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
    return [
        {"id": str(r["id"]), "geom_wkb": str(r["geom_wkb"])}
        for r in rows
    ]


def get_adm_levels_for_country(conn: psycopg2.extensions.connection, iso3: str) -> list[int]:
    """Return sorted list of distinct adm_levels >= 1 present for a country.

    adm_level=1 (national) rows are now processed directly via tiled raster
    reads rather than rollup, so they are included here.  Both 'geoboundaries'
    and 'synthetic' sources are included (the latter covers PRI's country row).
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


# ─── Per-polygon bbox pixel-area filter ──────────────────────────────────────

def _bbox_pixel_area(geom, transform: Affine) -> int:
    """
    Estimate the raster pixel count inside a geometry's bounding box.

    This is an upper bound on the numpy mask that rasterstats would allocate.
    Uses the raster's affine transform to convert geographic bbox → pixel size.

    For a geographic raster (EPSG:4326) the pixel size in degrees is:
        pixel_width  = abs(transform.a)   (e.g. 0.000833° ≈ 100m at equator)
        pixel_height = abs(transform.e)

    Args:
        geom:      Shapely geometry
        transform: Rasterio Affine transform from get_tif_metadata()

    Returns:
        Estimated pixel count (width_px × height_px of the bbox window).
    """
    minx, miny, maxx, maxy = geom.bounds
    pixel_width  = abs(transform.a)
    pixel_height = abs(transform.e)
    if pixel_width == 0 or pixel_height == 0:
        return 0
    bbox_px_w = math.ceil((maxx - minx) / pixel_width)
    bbox_px_h = math.ceil((maxy - miny) / pixel_height)
    return bbox_px_w * bbox_px_h


# ─── Tiled raster reading for large polygons ─────────────────────────────────

def _compute_population_tiled(
    geom,
    tif_path: Path,
    nodata: float,
    transform: Affine,
    log: logging.Logger,
    jur_id: str = "",
) -> int:
    """
    Sum population for a large polygon using tiled rasterio.mask reads.

    Instead of rasterizing the entire bbox into one numpy array (which would
    OOM for polygons like Western Australia at ~508 megapixels), this function:

      1. Subdivides the polygon's bbox into tiles of TILE_PIXELS × TILE_PIXELS
         pixels (~100 MB each at float32)
      2. For each tile, clips the polygon to the tile bbox
      3. If the clipped geometry is empty (ocean / outside polygon), skips
      4. Otherwise, uses rasterio.mask.mask(crop=True) which does a windowed
         read — only the relevant raster blocks are loaded from disk
      5. Sums valid pixels (excluding nodata and NaN)

    The WorldPop GeoTIFFs are internally tiled at 512×512 blocks, so
    rasterio.mask with crop=True translates each tile request into a small
    number of block reads — no full-raster load ever happens.

    Args:
        geom:      Shapely geometry (typically MultiPolygon)
        tif_path:  Path to the country's WorldPop .tif
        nodata:    Nodata value from rasterio profile (varies: -99999, -9999, NaN)
        transform: Affine transform from get_tif_metadata()
        log:       Logger instance
        jur_id:    Jurisdiction UUID for log messages

    Returns:
        Population count (integer, ≥ 0). Returns 0 if all tiles fail.
    """
    minx, miny, maxx, maxy = geom.bounds

    # Convert tile size from pixels to CRS units (degrees for EPSG:4326)
    pixel_w = abs(transform.a)
    pixel_h = abs(transform.e)
    tile_w  = TILE_PIXELS * pixel_w
    tile_h  = TILE_PIXELS * pixel_h

    # Build tile grid
    tile_cols = math.ceil((maxx - minx) / tile_w)
    tile_rows = math.ceil((maxy - miny) / tile_h)
    total_tiles = tile_cols * tile_rows

    total_pop     = 0.0
    tiles_read    = 0
    tiles_skipped = 0
    tiles_failed  = 0

    log.info(
        "  tiled read for %s: bbox %.1f×%.1f° → %d×%d = %d tiles @ %dx%d px each",
        jur_id[:12] if jur_id else "?",
        maxx - minx, maxy - miny,
        tile_cols, tile_rows, total_tiles,
        TILE_PIXELS, TILE_PIXELS,
    )

    with rasterio.open(tif_path) as src:
        # If the raster CRS doesn't match EPSG:4326, wrap in a WarpedVRT
        # so coordinates align. This is rare for WorldPop but handled defensively.
        if src.crs and src.crs.to_epsg() != 4326:
            from rasterio.vrt import WarpedVRT
            src = WarpedVRT(src, crs="EPSG:4326")

        for col in range(tile_cols):
            for row in range(tile_rows):
                tile_minx = minx + col * tile_w
                tile_miny = miny + row * tile_h
                tile_maxx = min(tile_minx + tile_w, maxx)
                tile_maxy = min(tile_miny + tile_h, maxy)

                # Clip the polygon to this tile's bbox
                tile_box  = box(tile_minx, tile_miny, tile_maxx, tile_maxy)
                clipped   = geom.intersection(tile_box)

                if clipped.is_empty:
                    tiles_skipped += 1
                    continue

                try:
                    out_image, _ = rio_mask(
                        src,
                        [mapping(clipped)],
                        crop=True,
                        nodata=nodata,
                        filled=True,       # fill outside polygon with nodata
                        all_touched=False,
                    )
                    data = out_image[0]    # single band

                    # Build validity mask: exclude nodata and NaN
                    valid_mask = ~np.isnan(data)
                    if nodata is not None and not np.isnan(float(nodata)):
                        valid_mask &= (data != nodata)

                    tile_sum   = float(np.sum(data[valid_mask]))
                    total_pop += tile_sum
                    tiles_read += 1

                except Exception as exc:
                    tiles_failed += 1
                    if tiles_failed <= 3:
                        log.warning(
                            "  tiled read: tile (%d,%d) failed for %s: %s",
                            col, row, jur_id[:12] if jur_id else "?", exc,
                        )
                    continue

    result = max(0, int(round(total_pop)))

    log.info(
        "  tiled read complete for %s: pop=%d | tiles: %d read, %d empty, %d failed / %d total",
        jur_id[:12] if jur_id else "?",
        result, tiles_read, tiles_skipped, tiles_failed, total_tiles,
    )

    return result


# ─── Zonal stats for a chunk of geometries ───────────────────────────────────

def _run_zonal_stats_chunk(
    iso3: str,
    adm_level: int,
    chunk_label: str,
    tif_path: Path,
    nodata: float,
    transform: Affine,
    jurisdictions: list[dict],
    log: logging.Logger,
) -> dict[str, int]:
    """
    Run zonal_stats on a chunk of jurisdiction geometries, returning {uuid: pop}.

    Steps:
      1. Decode WKB → shapely geometry for each row
      2. For geometries whose bbox pixel area > MAX_BBOX_PIXELS, use the tiled
         rasterio.mask fallback (_compute_population_tiled) — accurate but slower
      3. Run zonal_stats on remaining (normal-sized) geometries in sub-batches
         of ZONAL_STATS_BATCH_SIZE
      4. Map results back to UUIDs; None → 0
    """
    # ── Decode WKB → shapely ─────────────────────────────────────────────────
    geometries: list = []
    uuid_order: list[str] = []

    population_map: dict[str, int] = {}

    for jur in jurisdictions:
        try:
            geom = shapely_wkb.loads(jur["geom_wkb"], hex=True)
        except Exception as exc:
            log.warning("%s adm%d: invalid WKB for %s: %s", iso3, adm_level, jur["id"], exc)
            continue

        # ── Bbox pixel-area guard ─────────────────────────────────────────
        bbox_px = _bbox_pixel_area(geom, transform)
        if bbox_px > MAX_BBOX_PIXELS:
            # TILED FALLBACK — read raster in manageable windows instead of
            # skipping. This produces accurate population for large polygons
            # like Western Australia (~508MP), Alaska, Nunavut, etc.
            log.info(
                "%s adm%d: %s bbox ~%dMP > %dMP limit — using tiled raster read",
                iso3, adm_level, jur["id"],
                bbox_px // 1_000_000, MAX_BBOX_PIXELS // 1_000_000,
            )
            tiled_pop = _compute_population_tiled(
                geom, tif_path, nodata, transform, log, jur_id=jur["id"],
            )
            population_map[jur["id"]] = tiled_pop
            continue

        geometries.append(geom)
        uuid_order.append(jur["id"])

    if not geometries:
        return population_map

    # ── Chunked zonal_stats (normal-sized polygons) ─────────────────────────
    all_stats: list[dict] = []
    n_batches = (len(geometries) + ZONAL_STATS_BATCH_SIZE - 1) // ZONAL_STATS_BATCH_SIZE

    for batch_idx in range(n_batches):
        start       = batch_idx * ZONAL_STATS_BATCH_SIZE
        end         = start + ZONAL_STATS_BATCH_SIZE
        batch_geoms = geometries[start:end]

        try:
            batch_stats = zonal_stats(
                vectors     = batch_geoms,
                raster      = str(tif_path),
                stats       = ["sum"],
                nodata      = nodata,
                all_touched = False,   # strict containment → more accurate totals
            )
            all_stats.extend(batch_stats)
        except Exception as exc:
            log.error(
                "%s adm%d [%s]: zonal_stats failed on sub-batch %d/%d: %s",
                iso3, adm_level, chunk_label, batch_idx + 1, n_batches, exc,
            )
            # Return what we have so far rather than dropping the whole chunk
            for uid in uuid_order[start:]:
                population_map[uid] = 0
            uuid_order = uuid_order[:start]
            break

    for uid, stat in zip(uuid_order, all_stats):
        raw_sum = stat.get("sum") if stat else None
        population_map[uid] = max(0, round(raw_sum)) if raw_sum is not None else 0

    return population_map


# ─── ADM level processor ─────────────────────────────────────────────────────

def process_adm_level(
    conn: psycopg2.extensions.connection,
    iso3: str,
    adm_level: int,
    tif_path: Path,
    tif_meta: dict,
    log: logging.Logger,
    progress: dict,
    save_progress_fn=None,
) -> int:
    """
    Process one (iso3, adm_level) pair: fetch geometries in DB chunks,
    run zonal_stats (or tiled fallback) per chunk, bulk-UPDATE DB after each chunk.

    Progress is tracked per-chunk (key: "{iso3}:adm{level}:chunk{N}") AND flushed
    to disk after every chunk via save_progress_fn. This means a crash mid-ADM-level
    resumes from the last completed chunk (~90 seconds rework) rather than
    reprocessing the entire level (up to ~8 hours for IND ADM6 / 325 chunks).

    Since bulk_update_populations is idempotent (UPDATE, not INSERT), re-running
    a completed chunk is always safe.

    Args:
        save_progress_fn: Optional callable(progress) — called after each chunk
                          commit to flush progress to disk.

    Returns total rows updated for this ADM level.
    """
    total_rows = count_jurisdiction_rows_for_level(conn, iso3, adm_level)
    if total_rows == 0:
        log.debug("%s adm%d: no geometry rows — skipping", iso3, adm_level)
        return 0

    nodata    = tif_meta["nodata"]
    transform = tif_meta["transform"]
    n_chunks  = math.ceil(total_rows / DB_FETCH_CHUNK_SIZE)

    log.info(
        "%s adm%d: %d polygons × %.0f MB raster — %d DB chunk(s)",
        iso3, adm_level, total_rows, tif_meta["size_mb"], n_chunks,
    )

    wp_progress   = progress.setdefault("worldpop", {})
    total_updated = 0

    for chunk_idx in range(n_chunks):
        offset      = chunk_idx * DB_FETCH_CHUNK_SIZE
        chunk_label = f"chunk {chunk_idx + 1}/{n_chunks}"
        chunk_key   = f"{iso3}:adm{adm_level}:chunk{chunk_idx}"

        # Resume: skip chunks already committed in a previous (interrupted) run
        if wp_progress.get(chunk_key, {}).get("status") == "done":
            prev_updated   = wp_progress[chunk_key].get("updated", 0)
            total_updated += prev_updated
            log.debug("%s adm%d [%s]: already done (%d rows) — skipping",
                      iso3, adm_level, chunk_label, prev_updated)
            continue

        jurisdictions = fetch_jurisdiction_geometry_chunk(
            conn, iso3, adm_level, offset, DB_FETCH_CHUNK_SIZE
        )
        if not jurisdictions:
            break

        if n_chunks > 1:
            log.debug(
                "%s adm%d [%s]: fetched %d rows",
                iso3, adm_level, chunk_label, len(jurisdictions),
            )

        population_map = _run_zonal_stats_chunk(
            iso3, adm_level, chunk_label,
            tif_path, nodata, transform, jurisdictions, log,
        )

        updated = 0
        if population_map:
            updated        = bulk_update_populations(conn, population_map)
            total_updated += updated

        # ── Per-chunk progress: update in-memory dict then flush to disk ──
        wp_progress[chunk_key] = {
            "status":    "done",
            "updated":   updated,
            "timestamp": datetime.now(timezone.utc).isoformat(),
        }
        # Flush after every chunk — a crash resumes from here, not the level start
        if save_progress_fn:
            save_progress_fn(progress)

        # Free geometry memory before next chunk
        del jurisdictions
        del population_map

    log.info("%s adm%d: updated %d rows total", iso3, adm_level, total_updated)
    return total_updated


# ─── ADM0 population rollup ──────────────────────────────────────────────────

def rollup_adm0_population(
    conn: psycopg2.extensions.connection,
    iso3: str,
    log: logging.Logger,
) -> int:
    """
    Set the ADM0 (national) population by summing its direct ADM1 children.

    ADM0 boundaries span entire countries — their raster bounding boxes are
    billions of pixels, causing OOM in zonal_stats. Instead we sum the ADM1
    children (which have already been computed via zonal_stats or tiled reads)
    and write the total to the ADM0 row.

    Returns:
        Number of rows updated (0 or 1).
    """
    with get_cursor(conn) as cur:
        cur.execute("""
            UPDATE jurisdictions AS parent
            SET
                population      = child_sum.total,
                population_year = 2023,
                updated_at      = NOW()
            FROM (
                SELECT
                    p.id              AS parent_id,
                    SUM(c.population) AS total
                FROM jurisdictions p
                JOIN jurisdictions c ON c.parent_id = p.id
                WHERE p.iso_code  = %s
                  AND p.adm_level = 1
                  AND p.source    IN ('geoboundaries', 'synthetic')
                  AND p.deleted_at IS NULL
                  AND c.deleted_at IS NULL
                  AND c.population IS NOT NULL
                GROUP BY p.id
            ) child_sum
            WHERE parent.id = child_sum.parent_id
        """, (iso3,))
        updated = cur.rowcount
    log.debug("%s ADM0 rollup: updated %d rows", iso3, updated)
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
        conn:         Open psycopg2 connection (fresh per country).
        iso3:         ISO3 country code to tag tiles with.
        tif_path:     Path to the WorldPop GeoTIFF for this country.
        log:          Logger instance.
        year:         WorldPop year (default 2023).
        resolution_m: Pixel resolution in metres (default 100).

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

        col_steps = range(0, width,  RASTER_TILE_SIZE)
        row_steps = range(0, height, RASTER_TILE_SIZE)
        total_potential = len(col_steps) * len(row_steps)
        log.debug(
            "%s: raster %d×%d → up to %d tiles at %d×%d px",
            iso3, width, height, total_potential, RASTER_TILE_SIZE, RASTER_TILE_SIZE,
        )

        for row_off in row_steps:
            for col_off in col_steps:
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

                if len(batch) >= RASTER_BATCH_SIZE:
                    _insert_raster_batch(conn, batch)
                    tiles_inserted += len(batch)
                    batch = []

    # Flush remaining tiles
    if batch:
        _insert_raster_batch(conn, batch)
        tiles_inserted += len(batch)

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
    load_rasters: bool = False,
) -> int:
    """
    Update jurisdictions.population for all countries using WorldPop rasters.

    Args:
        level_filter: If provided, only process these adm_levels.
                      e.g. level_filter=[1] runs only the national polygon.
                      Useful for targeted patches without re-running all levels.

    Every country is processed one ADM level at a time. Within each ADM level
    geometries are fetched in chunks of DB_FETCH_CHUNK_SIZE and processed
    chunk-by-chunk so that even IND ADM6 (649K polygons) never exceeds a few
    hundred MB of geometry memory at once.

    Progress is tracked per (iso3, adm_level) key AND per chunk within each
    ADM level. save_progress_fn is called after every chunk commit, so a crash
    anywhere in the run resumes from the last completed chunk with at most
    ~90 seconds of rework (one DB_FETCH_CHUNK_SIZE batch).

    Args:
        countries:        Optional list of ISO3 codes to process (None = all in DB)
        progress:         Shared progress dict (mutated in-place)
        log:              Logger instance
        save_progress_fn: callable(progress) — called after each chunk, ADM level,
                          and country to flush progress to disk atomically.
        load_rasters:     If True, also load the country's GeoTIFF into the
                          worldpop_rasters table after population aggregation.
                          Skipped for fallback countries (VAT→ITA, XKX→SRB).
                          Once loaded, TIF files are not needed at runtime.

    Returns:
        Total number of jurisdiction rows updated.
    """
    if log is None:
        log = logging.getLogger(__name__)
    if progress is None:
        progress = {}

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

    for iso3 in iso3_list:

        # Skip countries with no WorldPop coverage
        if iso3 in NO_WORLDPOP:
            log.info("%s: no WorldPop coverage — skipping", iso3)
            skipped_no_data += 1
            continue

        # Find the .tif file first (cheap check before opening a DB connection)
        tif_path = find_worldpop_tif(iso3)
        if tif_path is not None and iso3.upper() in RASTER_FALLBACKS:
            log.info("%s: no own raster — using %s raster as fallback (%s)",
                     iso3, RASTER_FALLBACKS[iso3.upper()], tif_path)
        if tif_path is None:
            log.warning("%s: WorldPop .tif not found — skipping", iso3)
            skipped_no_tif += 1
            progress.setdefault("worldpop", {})[iso3] = {
                "status":    "skipped",
                "reason":    "tif_not_found",
                "timestamp": datetime.now(timezone.utc).isoformat(),
            }
            continue

        # Load raster metadata once per country (cheap — just opens header)
        tif_meta = get_tif_metadata(tif_path)

        if tif_meta["crs_epsg"] and tif_meta["crs_epsg"] != 4326:
            log.warning(
                "%s: raster CRS is EPSG:%s — tiled reads will use WarpedVRT",
                iso3, tif_meta["crs_epsg"]
            )

        # ── Per-country connection (fresh per country to avoid timeout) ──
        conn = get_connection()
        try:
            adm_levels = get_adm_levels_for_country(conn, iso3)

            if level_filter:
                adm_levels = [l for l in adm_levels if l in level_filter]

            if not adm_levels:
                log.warning(
                    "%s: no jurisdiction rows in DB — run import_geoboundaries first", iso3
                )
                continue

            country_updated = 0

            for adm_level in adm_levels:
                progress_key = f"{iso3}:adm{adm_level}"

                if progress.get("worldpop", {}).get(progress_key, {}).get("status") == "done":
                    log.debug("%s adm%d: already done — skipping", iso3, adm_level)
                    continue

                updated = process_adm_level(
                    conn, iso3, adm_level, tif_path, tif_meta, log, progress,
                    save_progress_fn=save_progress_fn,
                )
                total_updated   += updated
                country_updated += updated

                progress.setdefault("worldpop", {})[progress_key] = {
                    "status":    "done",
                    "updated":   updated,
                    "timestamp": datetime.now(timezone.utc).isoformat(),
                }
                # Flush after each ADM level — crash-safe resume mid-country
                if save_progress_fn:
                    save_progress_fn(progress)

            # Validate: direct raster national total vs. sum of children
            validate_national_population(conn, iso3, log)

            # ── Optional: load raster tiles into worldpop_rasters table ──
            # Skip fallback countries — their TIF covers a larger territory,
            # so storing it under the fallback iso_code would be misleading.
            # (VAT/XKX are tiny and will never need district drawing.)
            if load_rasters and iso3 not in RASTER_FALLBACKS:
                raster_key = f"rasters:{iso3}"
                if progress.get("worldpop_rasters", {}).get(raster_key, {}).get("status") != "done":
                    try:
                        tiles = load_raster_to_db(conn, iso3, tif_path, log, year=2023)
                        progress.setdefault("worldpop_rasters", {})[raster_key] = {
                            "status":    "done",
                            "tiles":     tiles,
                            "timestamp": datetime.now(timezone.utc).isoformat(),
                        }
                        if save_progress_fn:
                            save_progress_fn(progress)
                    except Exception as exc:
                        log.error("%s: raster load failed — %s", iso3, exc, exc_info=True)
                        progress.setdefault("worldpop_rasters", {})[raster_key] = {
                            "status":    "error",
                            "error":     str(exc),
                            "timestamp": datetime.now(timezone.utc).isoformat(),
                        }
                else:
                    log.debug("%s: raster already loaded — skipping", iso3)

            progress.setdefault("worldpop", {})[iso3] = {
                "status":    "done",
                "updated":   country_updated,
                "timestamp": datetime.now(timezone.utc).isoformat(),
            }
            log.info("%s: complete", iso3)

            # Flush after each country
            if save_progress_fn:
                save_progress_fn(progress)

        except Exception as exc:
            log.error("%s: unhandled error — %s", iso3, exc, exc_info=True)
            progress.setdefault("worldpop", {})[iso3] = {
                "status":    "error",
                "error":     str(exc),
                "timestamp": datetime.now(timezone.utc).isoformat(),
            }
            # Continue to next country rather than aborting the entire run

        finally:
            conn.close()

    log.info(
        "import_worldpop complete: %d rows updated | "
        "%d skipped (no coverage) | %d skipped (no tif)",
        total_updated, skipped_no_data, skipped_no_tif
    )
    return total_updated
