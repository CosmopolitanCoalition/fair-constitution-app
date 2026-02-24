"""
import_geoboundaries.py — Import administrative boundaries from geoBoundaries into
the jurisdictions table.

Data source: /docs/geoBoundaries_repo/releaseData/gbOpen/
Manifest:    /docs/geoBoundaries_repo/releaseData/geoBoundariesOpen-meta.csv

Processing order (MUST be strict — children need parent UUIDs):
  ADM0 → ADM1 → ADM2 → ADM3 → ADM4 → ADM5

ADM level mapping (geoBoundaries → app adm_level column):
  synthetic Earth  → 0
  ADM0 (national)  → 1
  ADM1 (state)     → 2
  ADM2 (county)    → 3
  ADM3 (local)     → 4
  ADM4 (sub-local) → 5
  ADM5             → 6
"""

import json
import logging
import os
import re
import unicodedata
from datetime import datetime, timezone
from pathlib import Path

import geopandas as gpd
import pandas as pd
import psycopg2

from db import (
    get_connection,
    get_cursor,
    bulk_insert_jurisdictions,
    bulk_insert_constitutional_settings,
)
from languages import get_languages

# ─── Paths ───────────────────────────────────────────────────────────────────

GEOBOUNDARIES_ROOT = Path("/docs/geoBoundaries_repo/releaseData")
GBOPEN_ROOT        = GEOBOUNDARIES_ROOT / "gbOpen"
META_CSV           = GEOBOUNDARIES_ROOT / "geoBoundariesOpen-meta.csv"

# ─── Constants ────────────────────────────────────────────────────────────────

BATCH_SIZE    = 50   # rows per INSERT batch (WKB is compact enough for complex geometries)
ADM_LEVEL_MAP = {0: 1, 1: 2, 2: 3, 3: 4, 4: 5, 5: 6}  # geoBoundaries → app

# GeoJSON property keys tried in order when reading feature name
NAME_KEYS = ["shapeName", "name", "NAME", "ADM5_EN", "ADM4_EN", "ADM3_EN",
             "ADM2_EN", "ADM1_EN", "ADM0_EN", "Local", "VARNAME_1"]

logger = logging.getLogger(__name__)


# ─── Slug generation ─────────────────────────────────────────────────────────

def _sanitize_name(name: str) -> str:
    """
    Normalize unicode, convert to ASCII, lowercase, replace non-alphanumeric
    with hyphens, collapse consecutive hyphens, strip leading/trailing hyphens.

    Example: "Île-de-France" → "ile-de-france"
    """
    normalized = unicodedata.normalize("NFKD", name)
    ascii_str   = normalized.encode("ascii", "ignore").decode("ascii")
    lower       = ascii_str.lower()
    cleaned     = re.sub(r"[^a-z0-9]+", "-", lower)
    return cleaned.strip("-") or "unknown"


def make_slug(
    iso3: str,
    adm_level_app: int,
    name: str,
    existing_slugs: set,
) -> str:
    """
    Generate a unique, URL-safe slug.

    Pattern: {iso3_lower}-{adm_level_app}-{sanitized_name}
    Example: ("USA", 1, "United States") → "usa-1-united-states"

    If the base slug already exists, appends -2, -3, … until unique.
    Adds the final slug to existing_slugs in-place.
    """
    base   = f"{iso3.lower()}-{adm_level_app}-{_sanitize_name(name)}"
    slug   = base
    count  = 2
    while slug in existing_slugs:
        slug = f"{base}-{count}"
        count += 1
    existing_slugs.add(slug)
    return slug


# ─── Filesystem discovery ─────────────────────────────────────────────────────

def discover_geoboundaries_files() -> list[tuple[str, int, Path]]:
    """
    Traverse GBOPEN_ROOT and discover every available (ISO3, adm_n, geojson_path)
    triple by inspecting the folder tree directly.

    Directory structure:
        gbOpen/{ISO3}/ADM{n}/geoBoundaries-{ISO3}-ADM{n}.geojson

    This is the authoritative source of what exists — the meta CSV is incomplete
    (e.g. IND-ADM0 and PRI-ADM0 are absent from the CSV despite the files being
    present on disk). The meta CSV is still loaded separately as a supplementary
    lookup for boundaryID and UNSDG-region metadata.

    Returns list sorted by (adm_n, iso3) so that all ADM0 rows are processed
    before ADM1 rows, guaranteeing parents exist before children are inserted.
    """
    results: list[tuple[str, int, Path]] = []

    if not GBOPEN_ROOT.is_dir():
        logger.error("GBOPEN_ROOT not found: %s", GBOPEN_ROOT)
        return results

    for iso3_dir in sorted(GBOPEN_ROOT.iterdir()):
        if not iso3_dir.is_dir():
            continue
        iso3 = iso3_dir.name.upper()
        if len(iso3) != 3:
            continue   # skip README, desktop.ini, etc.

        for adm_dir in sorted(iso3_dir.iterdir()):
            if not adm_dir.is_dir():
                continue
            m = re.match(r'^ADM(\d)$', adm_dir.name, re.IGNORECASE)
            if not m:
                continue
            adm_n        = int(m.group(1))
            geojson_path = adm_dir / f"geoBoundaries-{iso3}-ADM{adm_n}.geojson"
            # GeoJSON is primary; process_geojson_file falls back to .shp
            # automatically if the GeoJSON read fails or isn't present.
            results.append((iso3, adm_n, geojson_path))

    # Strict parent-before-child ordering: sort by adm_n first, then iso3
    results.sort(key=lambda x: (x[1], x[0]))
    logger.info(
        "Discovered %d (ISO3, ADM level) entries across %d countries",
        len(results),
        len({iso3 for iso3, _, _ in results}),
    )
    return results


# ─── Meta CSV loading (supplementary) ────────────────────────────────────────

def load_meta_index(meta_csv_path: Path) -> dict:
    """
    Read geoBoundariesOpen-meta.csv into a nested dict keyed by (ISO3, adm_n).

    Used as a SUPPLEMENTARY lookup only — not as the driver of what files to
    process. discover_geoboundaries_files() is the authoritative source.

    Provides: boundaryID (used as geoboundaries_id fallback) and UNSDG-region
    (used for official_languages lookup). Both default to "" if absent.

    Returns:
        { ("USA", 0): {"boundaryID": "...", "UNSDG-region": "...", ...}, ... }
    """
    if not meta_csv_path.exists():
        logger.warning(
            "Meta CSV not found at %s — proceeding without supplementary metadata",
            meta_csv_path,
        )
        return {}
    df = pd.read_csv(meta_csv_path, dtype=str).fillna("")
    index: dict = {}
    for _, row in df.iterrows():
        iso3   = str(row.get("boundaryISO", "")).strip().upper()
        btype  = str(row.get("boundaryType", "")).strip()   # "ADM0", "ADM1", ...
        if not iso3 or not btype.startswith("ADM"):
            continue
        try:
            adm_n = int(btype[3:])
        except ValueError:
            continue
        index[(iso3, adm_n)] = row.to_dict()
    logger.info("Meta index loaded: %d entries (supplementary only)", len(index))
    return index


# ─── Earth jurisdiction ───────────────────────────────────────────────────────

def insert_earth_jurisdiction(conn: psycopg2.extensions.connection) -> str:
    """
    INSERT (or retrieve) the synthetic Earth jurisdiction (adm_level=0).

    Uses ON CONFLICT (slug) DO UPDATE SET updated_at = NOW() so the row
    is always returned, whether it's a new insert or already exists.

    Returns the UUID of the Earth jurisdiction.
    """
    sql = """
        INSERT INTO jurisdictions (
            name, slug, iso_code, adm_level, parent_id, source,
            geoboundaries_id, official_languages, timezone,
            geom, centroid, created_at, updated_at
        )
        VALUES (
            'Earth',
            'earth-0-earth',
            NULL,
            0,
            NULL,
            'computed_skater',
            NULL,
            '["en"]'::jsonb,
            'UTC',
            NULL,
            NULL,
            NOW(),
            NOW()
        )
        ON CONFLICT (slug) DO UPDATE SET updated_at = NOW()
        RETURNING id
    """
    with get_cursor(conn) as cur:
        cur.execute(sql)
        row = cur.fetchone()
    earth_id = str(row["id"])

    # Ensure constitutional_settings row exists for Earth
    bulk_insert_constitutional_settings(conn, [earth_id])

    logger.info("Earth jurisdiction ready (id=%s)", earth_id)
    return earth_id


# ─── ADM0 parent map ─────────────────────────────────────────────────────────

def build_adm0_parent_map(conn: psycopg2.extensions.connection) -> dict:
    """
    Query the DB for all national-level (adm_level=1) jurisdictions and
    return a dict mapping ISO3 code → UUID.

    Used by ADM1 import to resolve parent_id without spatial queries.
    """
    sql = """
        SELECT iso_code, id
        FROM   jurisdictions
        WHERE  adm_level = 1
          AND  deleted_at IS NULL
    """
    with get_cursor(conn) as cur:
        cur.execute(sql)
        rows = cur.fetchall()
    adm0_map = {str(row["iso_code"]).upper(): str(row["id"]) for row in rows if row["iso_code"]}
    logger.debug("ADM0 parent map: %d entries", len(adm0_map))
    return adm0_map


# ─── ADM1+ parent map (by iso_code + adm_level) ──────────────────────────────

def build_parent_map_for_level(
    conn: psycopg2.extensions.connection,
    iso3: str,
    parent_adm_level_app: int,
) -> list[dict]:
    """
    Fetch all jurisdictions at the given adm_level that belong to iso3.
    Returns a list of {id, geom_wkt} for spatial parent lookup.
    Only used when ISO-code-based lookup is insufficient (ADM2+).
    """
    sql = """
        SELECT id, ST_AsText(geom) AS geom_wkt
        FROM   jurisdictions
        WHERE  iso_code   = %s
          AND  adm_level  = %s
          AND  deleted_at IS NULL
          AND  geom       IS NOT NULL
    """
    with get_cursor(conn) as cur:
        cur.execute(sql, (iso3, parent_adm_level_app))
        rows = cur.fetchall()
    return [{"id": str(r["id"]), "geom_wkt": r["geom_wkt"]} for r in rows]


# ─── Spatial parent lookup ────────────────────────────────────────────────────

def find_parent_by_spatial(
    conn: psycopg2.extensions.connection,
    geom_wkt: str,
    parent_adm_level_app: int,
    iso3: str,
) -> str | None:
    """
    Find the parent jurisdiction UUID using a spatial intersection query,
    pre-filtered by iso_code and adm_level for index efficiency.

    Returns the UUID of the candidate with the largest intersection area
    (handles features that straddle administrative borders).
    Returns None if no parent is found.
    """
    sql = """
        SELECT id
        FROM   jurisdictions
        WHERE  iso_code  = %s
          AND  adm_level = %s
          AND  deleted_at IS NULL
          AND  ST_Intersects(geom, ST_GeomFromText(%s, 4326))
        ORDER BY
          ST_Area(ST_Intersection(geom, ST_GeomFromText(%s, 4326))) DESC
        LIMIT 1
    """
    try:
        with get_cursor(conn) as cur:
            cur.execute(sql, (iso3, parent_adm_level_app, geom_wkt, geom_wkt))
            row = cur.fetchone()
        return str(row["id"]) if row else None
    except Exception as exc:
        logger.warning("Spatial parent lookup failed for iso3=%s adm=%d: %s",
                       iso3, parent_adm_level_app, exc)
        conn.rollback()
        return None


# ─── Existing slugs cache ─────────────────────────────────────────────────────

def load_existing_slugs(conn: psycopg2.extensions.connection) -> set:
    """Load all existing slugs from the jurisdictions table into a set."""
    with get_cursor(conn) as cur:
        cur.execute("SELECT slug FROM jurisdictions WHERE deleted_at IS NULL")
        rows = cur.fetchall()
    return {str(row["slug"]) for row in rows}


# ─── GeoJSON feature processing ──────────────────────────────────────────────

def _extract_name(props: dict, adm_n: int) -> str:
    """
    Extract the human-readable name from GeoJSON feature properties.
    Tries a prioritized list of known geoBoundaries property keys.
    """
    # Try level-specific key first
    level_key = f"ADM{adm_n}_EN"
    if level_key in props and props[level_key]:
        return str(props[level_key]).strip()

    for key in NAME_KEYS:
        val = props.get(key)
        if val and str(val).strip() not in ("", "None", "null"):
            return str(val).strip()
    return "Unknown"


def _geometry_to_ewkb(geom) -> str | None:
    """
    Convert a Shapely geometry to hex-encoded WKB, with simplification.

    Some geoBoundaries ADM1 geometries (e.g. Ontario, Nunavut, BC) are
    100–200 MB as WKT/WKB — far too large to send as a query parameter.
    A simplify tolerance of 0.001 degrees (≈110 metres) reduces these to
    ~7% of their original size with no visible loss of detail at map scale.

    Promotes POLYGON → MULTIPOLYGON for schema consistency.
    Returns None if geometry is missing or empty.
    """
    if geom is None or geom.is_empty:
        return None
    from shapely.geometry import MultiPolygon, Polygon

    # Simplify before encoding — 0.001° ≈ 110 m; invisible on any map
    geom = geom.simplify(0.001, preserve_topology=True)

    if isinstance(geom, Polygon):
        geom = MultiPolygon([geom])
    elif geom.is_empty:
        return None

    return geom.wkb_hex


def _geometry_to_wkt(geom) -> str | None:
    """
    Convert a Shapely geometry to WKT (used only for spatial parent lookups).
    Applies the same 0.001° simplification as _geometry_to_ewkb so the
    lookup geometry is small enough to pass as a query parameter.
    Returns None if geometry is missing or empty.
    """
    if geom is None or geom.is_empty:
        return None
    from shapely.geometry import MultiPolygon, Polygon
    geom = geom.simplify(0.001, preserve_topology=True)
    if isinstance(geom, Polygon):
        geom = MultiPolygon([geom])
    if geom.is_empty:
        return None
    return geom.wkt


# ─── Core file processor ─────────────────────────────────────────────────────

def process_geojson_file(
    geojson_path: Path,
    iso3: str,
    adm_n: int,
    meta_row: dict,
    parent_map: dict,           # {iso3: parent_uuid} for ADM0→1; {} for ADM2+
    earth_uuid: str,
    existing_slugs: set,
    progress: dict,
    log: logging.Logger,
) -> list[str]:
    """
    Process one (ISO3, ADM_level) GeoJSON file and insert features into DB.

    Opens its own DB connection AFTER the file read completes so that the
    connection is never idle during slow GeoJSON/Shapefile parsing
    (CAN, RUS, USA ADM1 files can take several minutes to parse).

    Returns list of all inserted jurisdiction UUIDs for this file.
    """
    progress_key = f"{iso3}-ADM{adm_n}"

    # Resume check
    if progress.get("geoboundaries", {}).get(progress_key, {}).get("status") == "done":
        log.debug("Skipping %s (already done)", progress_key)
        return []

    if not geojson_path.exists():
        log.warning("GeoJSON not found: %s — marking skipped", geojson_path)
        progress.setdefault("geoboundaries", {})[progress_key] = {
            "status": "skipped",
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "reason": "file_not_found",
        }
        return []

    adm_level_app = ADM_LEVEL_MAP.get(adm_n, adm_n + 1)
    parent_adm_level_app = adm_level_app - 1  # parent is one level up
    boundary_id = meta_row.get("boundaryID", "")
    unsdg_region = meta_row.get("UNSDG-region", "")

    # Set env var to remove fiona's GeoJSON object size limit (some country
    # boundaries like NZL, RUS, CAN have very complex coastlines > 64MB default).
    os.environ["OGR_GEOJSON_MAX_OBJ_SIZE"] = "0"

    # Primary: GeoJSON. Fallback: Shapefile (no size limit, always present).
    shp_path = geojson_path.with_suffix(".shp")

    # ── Phase 1: Read file (CPU-bound, no DB connection open) ────────────────
    try:
        gdf = gpd.read_file(geojson_path)
    except Exception as geojson_exc:
        log.warning(
            "GeoJSON read failed for %s (%s) — falling back to Shapefile",
            geojson_path, geojson_exc
        )
        if shp_path.exists():
            try:
                gdf = gpd.read_file(shp_path)
                log.info("Using Shapefile fallback: %s", shp_path)
            except Exception as shp_exc:
                log.error("Shapefile fallback also failed for %s: %s", shp_path, shp_exc)
                progress.setdefault("geoboundaries", {})[progress_key] = {
                    "status": "error",
                    "error": f"GeoJSON: {geojson_exc} | SHP: {shp_exc}",
                    "timestamp": datetime.now(timezone.utc).isoformat(),
                }
                return []
        else:
            log.error("No Shapefile fallback found at %s", shp_path)
            progress.setdefault("geoboundaries", {})[progress_key] = {
                "status": "error",
                "error": str(geojson_exc),
                "timestamp": datetime.now(timezone.utc).isoformat(),
            }
            return []

    # Reproject to EPSG:4326 if needed
    if gdf.crs and gdf.crs.to_epsg() != 4326:
        log.warning("%s has CRS %s — reprojecting to 4326", geojson_path, gdf.crs)
        gdf = gdf.to_crs(epsg=4326)

    # ── Phase 2: Build row dicts in memory (no DB) ────────────────────────────
    # For ADM0 and ADM1 we can determine parent_id without a DB query.
    # For ADM2+ we need a spatial lookup, so we defer those to Phase 3.
    pending_spatial: list[dict] = []   # rows needing spatial parent lookup (ADM2+)
    ready_rows: list[dict] = []        # rows with parent_id already resolved

    for idx, feature in gdf.iterrows():
        props = feature.to_dict()
        geom  = props.pop("geometry", None)

        name       = _extract_name(props, adm_n)
        geom_ewkb  = _geometry_to_ewkb(geom)
        feat_iso3  = str(props.get("shapeGroup", iso3)).strip().upper() or iso3
        shape_id   = str(props.get("shapeID", "")).strip()

        if not geom_ewkb:
            log.warning("Empty geometry for %s feature '%s' — skipping", progress_key, name)
            continue

        slug             = make_slug(feat_iso3, adm_level_app, name, existing_slugs)
        geoboundaries_id = shape_id if shape_id else boundary_id
        official_langs   = get_languages(feat_iso3, unsdg_region)

        row = {
            "name":               name,
            "slug":               slug,
            "iso_code":           feat_iso3,
            "adm_level":          adm_level_app,
            "parent_id":          None,    # filled below
            "source":             "geoboundaries",
            "geoboundaries_id":   geoboundaries_id,
            "official_languages": official_langs,
            "timezone":           "UTC",
            "geom_ewkb":          geom_ewkb,   # hex WKB — binary, ~3× smaller than WKT
        }

        # ── Determine parent_id ──
        if adm_n == 0:
            row["parent_id"] = earth_uuid
            ready_rows.append(row)

        elif adm_n == 1:
            row["parent_id"] = parent_map.get(feat_iso3)
            if not row["parent_id"]:
                log.warning("No ADM0 parent for iso3=%s feature '%s' — inserting as orphan",
                            feat_iso3, name)
            ready_rows.append(row)

        else:
            # parent_id will be resolved via spatial query in Phase 3.
            # WKT is needed for find_parent_by_spatial so derive it here.
            row["_geom_wkt_for_lookup"] = _geometry_to_wkt(geom)
            row["_parent_adm_level"]    = parent_adm_level_app
            pending_spatial.append(row)

    # ── Phase 3: DB operations (connection opened fresh here) ─────────────────
    # Connection is opened AFTER file parsing so it is never idle during slow IO.
    all_inserted_ids: list[str] = []

    conn = get_connection()
    try:
        # Resolve spatial parents for ADM2+ features
        for row in pending_spatial:
            parent_adm  = row.pop("_parent_adm_level")
            geom_wkt_lk = row.pop("_geom_wkt_for_lookup", None)
            row["parent_id"] = find_parent_by_spatial(
                conn, geom_wkt_lk, parent_adm, row["iso_code"]
            ) if geom_wkt_lk else None
            if not row["parent_id"]:
                log.warning("No spatial parent for %s '%s' (adm%d) — inserting as orphan",
                            row["iso_code"], row["name"], adm_n)
            ready_rows.append(row)

        # Bulk insert in batches
        batch: list[dict] = []
        for row in ready_rows:
            batch.append(row)
            if len(batch) >= BATCH_SIZE:
                ids = bulk_insert_jurisdictions(conn, batch)
                bulk_insert_constitutional_settings(conn, ids)
                all_inserted_ids.extend(ids)
                batch = []

        if batch:
            ids = bulk_insert_jurisdictions(conn, batch)
            bulk_insert_constitutional_settings(conn, ids)
            all_inserted_ids.extend(ids)

    finally:
        conn.close()

    # Mark progress
    progress.setdefault("geoboundaries", {})[progress_key] = {
        "status":    "done",
        "inserted":  len(all_inserted_ids),
        "timestamp": datetime.now(timezone.utc).isoformat(),
    }

    log.info("%-12s inserted %4d jurisdictions", progress_key, len(all_inserted_ids))
    return all_inserted_ids


# ─── Main entry point ─────────────────────────────────────────────────────────

def import_geoboundaries(
    countries: list[str] | None = None,
    adm_levels: list[int] | None = None,
    progress: dict = None,
    log: logging.Logger = None,
) -> int:
    """
    Import geoBoundaries data into the jurisdictions table.

    Args:
        countries:  Optional list of ISO3 codes to process (None = all)
        adm_levels: Optional list of ADM levels to process, e.g. [0,1,2]
        progress:   Shared progress dict (mutated in-place)
        log:        Logger instance

    Returns:
        Total number of jurisdictions inserted.
    """
    if log is None:
        log = logging.getLogger(__name__)
    if progress is None:
        progress = {}

    levels_to_process = set(adm_levels) if adm_levels is not None else set(range(6))

    # ── Discover all available files from the filesystem ──
    # This is the authoritative source — the meta CSV is incomplete.
    all_discovered = discover_geoboundaries_files()

    # ── Load meta CSV as supplementary lookup (boundaryID, UNSDG-region) ──
    meta_index = load_meta_index(META_CSV)

    # ── Earth jurisdiction (short-lived connection) ──
    _conn = get_connection()
    try:
        if not progress.get("earth_inserted"):
            earth_uuid = insert_earth_jurisdiction(_conn)
            progress["earth_inserted"] = True
            progress["earth_uuid"]     = earth_uuid
        else:
            earth_uuid = progress["earth_uuid"]
            log.info("Earth jurisdiction already exists (id=%s)", earth_uuid)
    finally:
        _conn.close()

    # ── Load existing slugs (short-lived connection) ──
    _conn = get_connection()
    try:
        existing_slugs = load_existing_slugs(_conn)
    finally:
        _conn.close()
    log.info("Loaded %d existing slugs from DB", len(existing_slugs))

    total_inserted = 0

    # ── Process each ADM level in strict order (ADM0 before ADM1, etc.) ──
    for adm_n in sorted(levels_to_process):
        if adm_n not in range(6):
            log.warning("ADM level %d out of range (0–5) — skipping", adm_n)
            continue

        log.info("=== Processing ADM%d (app adm_level=%d) ===", adm_n, ADM_LEVEL_MAP[adm_n])

        # Build parent map appropriate for this ADM level (short-lived connection)
        if adm_n == 0:
            parent_map = {}  # ADM0 always parents to Earth (earth_uuid constant)
        elif adm_n == 1:
            _conn = get_connection()
            try:
                parent_map = build_adm0_parent_map(_conn)
            finally:
                _conn.close()
            log.info("ADM0 parent map: %d countries", len(parent_map))
        else:
            parent_map = {}  # spatial lookup per-feature for ADM2+

        # Files for this ADM level, optionally filtered by --countries
        level_files = [
            (iso3, geojson_path)
            for iso3, n, geojson_path in all_discovered
            if n == adm_n
        ]
        if countries:
            countries_upper = {c.upper() for c in countries}
            level_files = [(iso3, p) for iso3, p in level_files if iso3 in countries_upper]

        log.info("ADM%d: %d countries to process", adm_n, len(level_files))

        for iso3, geojson_path in level_files:
            # Supplementary metadata from CSV — empty dict if not present
            meta_row = meta_index.get((iso3, adm_n), {})

            # process_geojson_file manages its own connection internally —
            # the connection is opened AFTER file parsing so it is never idle
            # during slow GeoJSON/Shapefile reads (CAN, RUS, USA can take mins).
            try:
                inserted_ids = process_geojson_file(
                    geojson_path   = geojson_path,
                    iso3           = iso3,
                    adm_n          = adm_n,
                    meta_row       = meta_row,
                    parent_map     = parent_map,
                    earth_uuid     = earth_uuid,
                    existing_slugs = existing_slugs,
                    progress       = progress,
                    log            = log,
                )
                total_inserted += len(inserted_ids)
            except Exception as exc:
                progress_key = f"{iso3}-ADM{adm_n}"
                log.error("Unhandled error processing %s: %s", progress_key, exc, exc_info=True)
                progress.setdefault("geoboundaries", {})[progress_key] = {
                    "status":    "error",
                    "error":     str(exc),
                    "timestamp": datetime.now(timezone.utc).isoformat(),
                }
                # Continue to next country rather than aborting the entire run

    log.info("import_geoboundaries complete: %d total jurisdictions inserted", total_inserted)
    return total_inserted
