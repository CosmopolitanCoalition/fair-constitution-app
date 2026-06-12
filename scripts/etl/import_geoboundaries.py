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

import ijson
import pandas as pd
import psycopg2

from db import (
    get_connection,
    get_cursor,
    bulk_insert_jurisdictions,
    bulk_insert_constitutional_settings,
)
from languages import get_languages
import heartbeat

# ─── Paths ───────────────────────────────────────────────────────────────────
# DATA_ROOT env var selects source: /archive (local archive) or /docs (fresh download).
# Defaults to /docs for backward compatibility with the legacy main-branch flow.

DATA_ROOT          = Path(os.environ.get("DATA_ROOT", "/docs"))
GEOBOUNDARIES_ROOT = DATA_ROOT / "geoBoundaries_repo" / "releaseData"
GBOPEN_ROOT        = GEOBOUNDARIES_ROOT / "gbOpen"
META_CSV           = GEOBOUNDARIES_ROOT / "geoBoundariesOpen-meta.csv"

# ─── Constants ────────────────────────────────────────────────────────────────

# Phase L: byte-aware batching replaces fixed row-count batching. Each INSERT
# is flushed when adding the next row would push the total GeoJSON payload over
# BATCH_BYTE_LIMIT, OR when the batch reaches BATCH_ROW_LIMIT rows. PostgreSQL
# receive-side memory matters too — every feature in the batch is parsed
# server-side via ST_GeomFromGeoJSON and held in work_mem during ST_MakeValid;
# smaller batches keep PG's per-INSERT peak memory lower. The streaming
# pipeline (no per-file accumulation) + adaptive batches make this OOM-safe
# even on Pi-class hardware.
#
# Phase N: profile-driven sizing. Selected once at import based on the
# container's cgroup memory limit (or host RAM in non-containerised setups);
# override with ETL_MEMORY_BUDGET_BYTES env var. See memory_budget.py for the
# tier table. The 'desktop' tier matches the previous hardcoded values
# exactly, so 8–16 GB hosts see no behavior change after Phase N.
from memory_budget import chunk_profile, detect_memory_budget_bytes

_MEMORY_BUDGET                      = detect_memory_budget_bytes()
_PROFILE_NAME, _PROFILE             = chunk_profile(_MEMORY_BUDGET)
BATCH_BYTE_LIMIT                    = _PROFILE["BATCH_BYTE_LIMIT"]
BATCH_ROW_LIMIT                     = _PROFILE["BATCH_ROW_LIMIT"]

ADM_LEVEL_MAP = {0: 1, 1: 2, 2: 3, 3: 4, 4: 5, 5: 6}  # geoBoundaries → app

# Natural-language labels for heartbeat sub_phase strings — keep in sync with the
# canonical PHP map at SetupController::jurisdictionsCounts(). These are used in
# the wizard's CurrentJurisdictionCard subphase badge ("Country resolving parents
# 1,234 of 56,789") so the user never sees "ADM" jargon. Index by app adm_level.
NATURAL_LABEL = {
    0: "Planet",
    1: "Country",
    2: "State / Province",
    3: "County",
    4: "Municipality",
    5: "Township",
    6: "Neighborhood",
}

# Plural form of each level's natural label. Drives bar titles
# ("Boundaries — Counties") and the inline progress text ("Counties
# processed N of M"). Singular NATURAL_LABEL is still used for
# outer-loop "Country N of M" wording where N/M is the country-file
# iteration count, not the level's unit count.
NATURAL_LABEL_PLURAL = {
    0: "Planets",
    1: "Countries",
    2: "States / Provinces",
    3: "Counties",
    4: "Municipalities",
    5: "Townships",
    6: "Neighborhoods",
}

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


def _demojibake(name: str) -> str:
    """
    Repair double-encoded (UTF-8-bytes-read-as-Latin-1) names that ship corrupted
    in the geoBoundaries source GeoJSON, e.g. 'RegiÃ£o AutÃ³noma da Madeira' →
    'Região Autónoma da Madeira', 'AÃ§ores' → 'Açores'.

    Safe by construction:
      • Fast-path returns unchanged unless the name has a Latin-1-supplement char
        (U+0080–U+00FF) — the only bytes a UTF-8 sequence can masquerade as.
        Real Persian/Arabic/CJK names (codepoints > U+00FF) are never candidates.
      • A candidate is repaired only when it round-trips latin-1 → utf-8 WITHOUT
        error AND the result differs. Correctly-encoded accented names
        ('Île-de-France', 'Região') fail the utf-8 decode (a lone high byte isn't
        valid UTF-8) and are returned untouched.

    The lossy '?' (0x3f) corruption (e.g. Iran ADM4 names) is unrecoverable from
    the source and is left as-is.
    """
    if not any(0x80 <= ord(ch) <= 0xFF for ch in name):
        return name
    try:
        repaired = name.encode("latin-1").decode("utf-8")
    except (UnicodeEncodeError, UnicodeDecodeError):
        return name
    return repaired if repaired and repaired != name else name


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

def load_meta_index(meta_csv_path: Path, conn=None) -> dict:
    """
    Read geoBoundariesOpen-meta.csv, persist every row to the
    `geoboundary_metadata` DB table, and return a nested dict keyed by
    (ISO3, adm_n) for fast in-process lookups during the rest of the run.

    Phase R (2026-05-10): the CSV is now the source-of-record for a small
    DB-resident reference table. The DB table is the canonical lookup for:

      - synthesize_missing_country_rows: fetches `name` for synthesised
        country rows (replaces the TERRITORY_DISPLAY_NAMES dict in
        scripts/etl/sovereign_territories.py — now deleted).
      - Future Setup wizard / DataReviewService: region / income-group
        filtering and display.

    The dict is still returned because process_geojson_file pulls
    boundaryID and UNSDG-region per file at ETL load time and an
    in-memory dict beats a SELECT round-trip for that hot path. The DB
    table holds the same data; both are populated from the same CSV.

    Returns the dict (empty if the CSV is missing — the run continues
    without supplementary metadata).
    """
    if not meta_csv_path.exists():
        logger.warning(
            "Meta CSV not found at %s — proceeding without supplementary metadata",
            meta_csv_path,
        )
        return {}

    df = pd.read_csv(meta_csv_path, dtype=str).fillna("")

    # Build the (iso, adm_n) → row dict in one pass. The CSV occasionally
    # contains the same (iso, adm_n) twice — e.g. IND ADM1 has two builds
    # in the 2026-04 release. We deduplicate by keeping the LAST row seen
    # (typically the most recent build, since the file is in build order).
    # Without this, the bulk INSERT below trips Postgres's
    #   "ON CONFLICT DO UPDATE command cannot affect row a second time"
    # error and the whole DB-write step silently no-ops.
    index: dict = {}
    db_rows_by_key: dict[tuple[str, int], tuple] = {}
    for _, row in df.iterrows():
        iso3   = str(row.get("boundaryISO", "")).strip().upper()
        btype  = str(row.get("boundaryType", "")).strip()   # "ADM0", "ADM1", ...
        if not iso3 or not btype.startswith("ADM"):
            continue
        try:
            adm_n = int(btype[3:])
        except ValueError:
            continue
        rd = row.to_dict()
        index[(iso3, adm_n)] = rd

        # Build the DB row tuple. Numeric columns from the CSV are strings
        # ("7342.0", "1", etc.) — convert defensively, NULL on failure.
        def _opt_int(v):
            v = str(v).strip()
            if not v:
                return None
            try:
                return int(float(v))
            except (TypeError, ValueError):
                return None

        def _opt_float(v):
            v = str(v).strip()
            if not v:
                return None
            try:
                return float(v)
            except (TypeError, ValueError):
                return None

        def _opt_str(v):
            v = str(v).strip()
            return v if v else None

        db_rows_by_key[(iso3, adm_n)] = (
            iso3,                                           # iso_code
            adm_n,                                          # adm_level
            _opt_str(rd.get("boundaryID")),                 # boundary_id
            _opt_str(rd.get("boundaryName")),               # name
            _opt_int(rd.get("boundaryYearRepresented")),    # year_represented
            _opt_str(rd.get("boundaryType")),               # boundary_type
            _opt_str(rd.get("boundaryCanonical")),          # boundary_canonical
            _opt_str(rd.get("boundarySource")),             # boundary_source
            _opt_str(rd.get("boundaryLicense")),            # boundary_license
            _opt_str(rd.get("licenseDetail")),              # license_detail
            _opt_str(rd.get("licenseSource")),              # license_source
            _opt_str(rd.get("boundarySourceURL")),          # boundary_source_url
            _opt_str(rd.get("sourceDataUpdateDate")),       # source_data_update_date
            _opt_str(rd.get("buildDate")),                  # build_date
            _opt_str(rd.get("Continent")),                  # continent
            _opt_str(rd.get("UNSDG-region")),               # unsdg_region
            _opt_str(rd.get("UNSDG-subregion")),            # unsdg_subregion
            _opt_str(rd.get("worldBankIncomeGroup")),       # world_bank_income_group
            _opt_int(rd.get("admUnitCount")),               # adm_unit_count
            _opt_float(rd.get("meanVertices")),             # mean_vertices
            _opt_int(rd.get("minVertices")),                # min_vertices
            _opt_int(rd.get("maxVertices")),                # max_vertices
            _opt_float(rd.get("meanPerimeterLengthKM")),    # mean_perimeter_length_km
            _opt_float(rd.get("minPerimeterLengthKM")),     # min_perimeter_length_km
            _opt_float(rd.get("maxPerimeterLengthKM")),     # max_perimeter_length_km
            _opt_float(rd.get("meanAreaSqKM")),             # mean_area_sq_km
            _opt_float(rd.get("minAreaSqKM")),              # min_area_sq_km
            _opt_float(rd.get("maxAreaSqKM")),              # max_area_sq_km
            _opt_str(rd.get("staticDownloadLink")),         # static_download_link
        )

    # Flatten the dedup dict into the list bulk-insert expects.
    db_rows: list[tuple] = list(db_rows_by_key.values())

    # Persist to DB. The --fresh purge clears geoboundary_metadata first
    # (see purge_geoboundaries_data); ON CONFLICT here covers warm-run
    # replays where a previous run wrote rows already. extras.execute_values
    # is the bulk-insert helper that scales to the ~700 rows in the CSV.
    if conn is not None and db_rows:
        try:
            with get_cursor(conn) as cur:
                psycopg2.extras.execute_values(
                    cur,
                    """
                    INSERT INTO geoboundary_metadata (
                      iso_code, adm_level, boundary_id, name, year_represented,
                      boundary_type, boundary_canonical, boundary_source,
                      boundary_license, license_detail, license_source,
                      boundary_source_url, source_data_update_date, build_date,
                      continent, unsdg_region, unsdg_subregion,
                      world_bank_income_group, adm_unit_count, mean_vertices,
                      min_vertices, max_vertices, mean_perimeter_length_km,
                      min_perimeter_length_km, max_perimeter_length_km,
                      mean_area_sq_km, min_area_sq_km, max_area_sq_km,
                      static_download_link, created_at, updated_at
                    ) VALUES %s
                    ON CONFLICT (iso_code, adm_level) DO UPDATE SET
                      boundary_id              = EXCLUDED.boundary_id,
                      name                     = EXCLUDED.name,
                      year_represented         = EXCLUDED.year_represented,
                      boundary_type            = EXCLUDED.boundary_type,
                      boundary_canonical       = EXCLUDED.boundary_canonical,
                      boundary_source          = EXCLUDED.boundary_source,
                      boundary_license         = EXCLUDED.boundary_license,
                      license_detail           = EXCLUDED.license_detail,
                      license_source           = EXCLUDED.license_source,
                      boundary_source_url      = EXCLUDED.boundary_source_url,
                      source_data_update_date  = EXCLUDED.source_data_update_date,
                      build_date               = EXCLUDED.build_date,
                      continent                = EXCLUDED.continent,
                      unsdg_region             = EXCLUDED.unsdg_region,
                      unsdg_subregion          = EXCLUDED.unsdg_subregion,
                      world_bank_income_group  = EXCLUDED.world_bank_income_group,
                      adm_unit_count           = EXCLUDED.adm_unit_count,
                      mean_vertices            = EXCLUDED.mean_vertices,
                      min_vertices             = EXCLUDED.min_vertices,
                      max_vertices             = EXCLUDED.max_vertices,
                      mean_perimeter_length_km = EXCLUDED.mean_perimeter_length_km,
                      min_perimeter_length_km  = EXCLUDED.min_perimeter_length_km,
                      max_perimeter_length_km  = EXCLUDED.max_perimeter_length_km,
                      mean_area_sq_km          = EXCLUDED.mean_area_sq_km,
                      min_area_sq_km           = EXCLUDED.min_area_sq_km,
                      max_area_sq_km           = EXCLUDED.max_area_sq_km,
                      static_download_link     = EXCLUDED.static_download_link,
                      updated_at               = NOW()
                    """,
                    [r + (datetime.now(timezone.utc), datetime.now(timezone.utc)) for r in db_rows],
                )
            conn.commit()
            logger.info("geoboundary_metadata: persisted %d rows from CSV", len(db_rows))
        except Exception as exc:
            logger.warning("geoboundary_metadata DB load failed (continuing with in-memory only): %s", exc)
            conn.rollback()

    logger.info("Meta index loaded: %d entries (supplementary, in-memory + DB)", len(index))
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


# ─── Country-row synthesis for missing-top-level isos (Phase J1.5) ─────────

def synthesize_missing_country_rows(
    conn: psycopg2.extensions.connection,
    earth_uuid: str,
    log: logging.Logger,
) -> int:
    """
    For each iso whose MIN(adm_level) > 1 in the DB, build a synthetic
    adm_level=1 row from ST_Union of its deepest features.

    Today's data fires this exactly once (PRI: levels 3, 4 — no country
    or state row). Future ETL data may surface more. Idempotent — skipped
    if a row already exists at the deterministic slug.

    The row matches the pattern fix_orphans.py uses on main:
      - source              = 'synthetic'  (NOT 'synthetic_country' — using the
                              same value lets the existing source filters in
                              count_jurisdiction_rows_for_level / fetch_chunk
                              pick it up so its population gets computed)
      - name                = boundaryName from `geoboundary_metadata` (e.g.
                              'Puerto Rico' for iso=PRI). Falls back to the
                              iso code if the iso has no row in the meta
                              table. Phase R replaced the previous
                              hand-curated TERRITORY_DISPLAY_NAMES dict with
                              this DB-driven lookup — generalises to any iso
                              geoBoundaries covers without code changes.
      - slug                = lower(iso) + '-1-' + slugified(name)
      - parent_id           = Earth (so the row chains cleanly to the planet)
      - parent_assigned_via = 'direct' (parent IS Earth — no spatial lookup,
                              no skip — same as a regular ADM0)

    The dual-footprint structure is preserved — USA's separate "Puerto Rico"
    ADM2 row (and its 78 USA-iso children) remain in the DB unchanged.

    Returns the number of synthetic rows inserted.
    """
    # Fetch isos that need synthesis
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT iso_code, MIN(adm_level) AS deepest
            FROM jurisdictions
            WHERE deleted_at IS NULL AND iso_code IS NOT NULL
            GROUP BY iso_code
            HAVING MIN(adm_level) > 1
        """)
        targets = [(str(r["iso_code"]).upper(), int(r["deepest"])) for r in cur.fetchall()]

    if not targets:
        return 0

    # Phase R: pull display names from the geoboundary_metadata DB table
    # in one round-trip (replaces the hand-curated TERRITORY_DISPLAY_NAMES
    # dict). Pre-loaded by load_meta_index() at the start of the run.
    # Phase S fix: query the iso's LOWEST adm_level row instead of insisting
    # on adm_level=0. For ISOs like PRI that geoBoundaries doesn't ship at
    # ADM0 (they ship ADM2/3 directly), the same name "Puerto Rico" lives
    # at the deeper-level rows. Without this fallback, the synthetic row
    # ended up named just "PRI".
    name_lookup: dict[str, str] = {}
    if targets:
        target_isos = [t[0] for t in targets]
        try:
            with get_cursor(conn) as cur:
                cur.execute("""
                    SELECT DISTINCT ON (iso_code) iso_code, name
                    FROM   geoboundary_metadata
                    WHERE  iso_code = ANY(%s)
                      AND  name IS NOT NULL
                    ORDER BY iso_code, adm_level ASC
                """, (target_isos,))
                for r in cur.fetchall():
                    name_lookup[str(r["iso_code"]).upper()] = str(r["name"]).strip()
        except Exception as exc:
            log.warning("Could not query geoboundary_metadata for synth names "
                        "(falling back to iso codes): %s", exc)
            conn.rollback()

    inserted_rows: list[dict] = []
    for iso, deepest in targets:
        # Use boundaryName from the meta table; fall back to iso code if
        # the iso has no row (e.g. brand-new iso the CSV doesn't cover).
        display_name = name_lookup.get(iso) or iso
        slug         = f"{iso.lower()}-1-{_sanitize_name(display_name)}"

        sql = """
            WITH deepest_union AS (
                SELECT
                    ST_Multi(ST_MakeValid(ST_Union(geom))) AS geom,
                    ST_Centroid(ST_Union(geom))            AS centroid
                FROM jurisdictions
                WHERE iso_code   = %s
                  AND adm_level  = %s
                  AND deleted_at IS NULL
            )
            INSERT INTO jurisdictions (
                id, name, slug, iso_code, adm_level, parent_id,
                source, parent_assigned_via,
                official_languages, timezone,
                geom, centroid,
                created_at, updated_at
            )
            SELECT gen_random_uuid(),
                   %s, %s, %s, 1, %s,
                   'synthetic', 'direct',
                   '[]'::jsonb, 'UTC',
                   du.geom, du.centroid,
                   NOW(), NOW()
            FROM deepest_union du
            WHERE du.geom IS NOT NULL
            ON CONFLICT (slug) DO NOTHING
            RETURNING id, iso_code, name
        """
        with get_cursor(conn) as cur:
            cur.execute(sql, (
                iso, deepest,
                display_name, slug, iso, earth_uuid,
            ))
            row = cur.fetchone()
            if row:
                inserted_rows.append(dict(row))

    # Mirror the constitutional_settings row for each synthetic country so
    # the regular setup flow treats it as a normal jurisdiction.
    if inserted_rows:
        ids = [str(r["id"]) for r in inserted_rows]
        bulk_insert_constitutional_settings(conn, ids)
        for r in inserted_rows:
            log.info(
                "Synthesized country row for %s ('%s', source=synthetic) — id=%s",
                r["iso_code"], r["name"], r["id"],
            )
    return len(inserted_rows)


# ─── End-of-import post-pass: re-resolve remaining orphans ─────────────────
#
# After all ADM levels finish loading, run a single post-pass that:
#   1. Synthesizes country-level rows for isos that have only deeper levels
#      (today: PRI; future: any new such iso surfaces automatically)
#   2. Walks levels shallow-to-deep, re-running the strategy ladder on each
#      orphan. Shallow-first matters because synthesizing PRI level-1 lets
#      PRI level-3 chain to it; resolving PRI level-3 then lets PRI level-4
#      chain to those (no more synthesizing chain).

def _resolve_orphans_at_level_via_strategy(
    conn: psycopg2.extensions.connection,
    orphan_level: int,
    strategy: str,           # 'exact' | 'buffered' | 'topological'
) -> tuple[int, int]:
    """
    Single bulk UPDATE that re-resolves all orphans at the given adm_level.

    Returns:
      ('exact')        → (n_direct, n_skip_ancestor)
      ('buffered')     → (n_buffered, 0)
      ('topological')  → (n_topological, n_cross_iso_topological)

    Pure SQL (no Python iteration) — handles thousands of orphans in one
    round-trip via DISTINCT ON. Match condition + ORDER BY mirrors the
    per-row strategy ladder in find_parent_by_strategy_ladder.

    Strategy semantics match the inline ladder:
      - exact: same-iso, centroid OR PointOnSurface containment, deepest
        match. Classified 'direct' or 'skip_ancestor' based on the
        chosen parent's adm_level relative to orphan_level - 1.
      - buffered: same-iso, ~3.0° planar proximity (ST_DWithin on
        geometry — uses the GIST index), tie-broken by closest-edge
        distance to the orphan's centroid. No source simplification —
        the prior 0.01° simplify was masking 22 boundary-touch matches.
        Planar instead of geography because the geography cast
        invalidates the spatial index and is 120× slower (measured
        2026-05-09).
      - topological (Phase O): no iso filter — pure containment fallback
        for features whose iso label and geographic ancestry diverge.
        Classified 'topological' if chosen iso matches orphan iso,
        'cross_iso_topological' if it differs (the meaningful audit case).
    """
    if strategy == "direct_intersect":
        # Direct-level intersection (mirrors find_parent_by_strategy_ladder's
        # Strategy 0): same-iso parent at the EXACT level above the orphan,
        # matched by ST_Intersects rather than strict point-containment, so a
        # coastal sub-unit re-chains to its true state/province even when the
        # ADM boundaries are clipped differently at the shoreline. Run BEFORE
        # 'exact' so the strict-containment classifier below only ever sees
        # genuinely level-skipping orphans. The exact-level constraint rides on
        # same_iso_join; the template's `p.adm_level < orphan_level` stays
        # trivially satisfied (orphan_level-1 < orphan_level).
        match_expr    = "ST_Intersects(p.geom, o.geom)"
        same_iso_join = f"p.iso_code = o.iso_code AND p.adm_level = {orphan_level - 1}"
        strategy_expr = "'direct'::varchar(32)"
    elif strategy == "exact":
        # Same-iso, centroid OR PointOnSurface containment
        match_expr = (
            "ST_Contains(p.geom, ST_Centroid(o.geom)) "
            "OR ST_Contains(p.geom, ST_PointOnSurface(o.geom))"
        )
        same_iso_join = "p.iso_code = o.iso_code"
        strategy_expr = (
            f"CASE WHEN bp.parent_adm_level = {orphan_level - 1} "
            "THEN 'direct'::varchar(32) "
            "ELSE 'skip_ancestor'::varchar(32) END"
        )
    elif strategy == "buffered":
        # Same-iso, ~3.0° planar proximity (uses GIST index). No
        # simplification — removing ST_Simplify(o.geom, 0.01) un-masks
        # 22 boundary-touch orphans (FJI Rotuma sub-features, ARG
        # Comunas). Distance bumped from the previous 110 m planar
        # buffer to 3.0° because the residual-orphan analysis showed
        # the worst case needing 235 km reach (SLB Malaita Outer Island,
        # 9°S where 3.0° ≈ 333 km).
        #
        # Planar (geometry) distance, NOT geography. ST_DWithin on
        # geography casts geom→geography on the fly, which invalidates
        # the GIST index and forces a parallel bitmap-heap-scan +
        # exact-distance filter on every candidate row — measured 168 s
        # per query against FRA cantons during the 2026-05-09 hot-fix
        # run. The planar version uses the GIST `&&` bbox shortcut and
        # comes back in ~1.4 s (120× faster). Effective reach varies
        # with latitude (3.0° E-W = 333 km at equator, 166 km at 60°N)
        # but covers everything in the residual-orphan distance profile
        # with margin.
        #
        # Safe bound: same-iso 3.0° (max ~333 km) can't reach the
        # FRA→Marie-Galante (~2,000 km) false-positive class that bit
        # main; Strategy 3 has already resolved the cross-iso cases by
        # the time this runs.
        match_expr = "ST_DWithin(p.geom, o.geom, 3.0)"
        same_iso_join = "p.iso_code = o.iso_code"
        strategy_expr = "'buffered'::varchar(32)"
    elif strategy == "topological":
        # Phase O: drop the iso filter entirely. Same matching predicate
        # as 'exact' (centroid OR PointOnSurface), but we tag the result
        # based on whether the chosen parent's iso matches the orphan's.
        match_expr = (
            "ST_Contains(p.geom, ST_Centroid(o.geom)) "
            "OR ST_Contains(p.geom, ST_PointOnSurface(o.geom))"
        )
        same_iso_join = "TRUE"   # no iso constraint
        strategy_expr = (
            "CASE WHEN bp.parent_iso_code = bp.orphan_iso_code "
            "THEN 'topological'::varchar(32) "
            "ELSE 'cross_iso_topological'::varchar(32) END"
        )
    else:
        raise ValueError(f"Unknown strategy: {strategy}")

    sql = f"""
        WITH orphans AS (
            SELECT id, geom, iso_code FROM jurisdictions
            WHERE adm_level = %s
              AND parent_id IS NULL
              AND adm_level > 0
              AND deleted_at IS NULL
        ),
        best_parent AS (
            SELECT DISTINCT ON (o.id)
                o.id           AS orphan_id,
                p.id           AS parent_id,
                p.adm_level    AS parent_adm_level,
                p.iso_code     AS parent_iso_code,
                o.iso_code     AS orphan_iso_code
            FROM orphans o
            JOIN jurisdictions p ON (
                {same_iso_join}
                AND p.adm_level < %s
                AND p.deleted_at IS NULL
                AND ({match_expr})
            )
            ORDER BY o.id,
                     p.adm_level DESC,
                     -- Tie-breaker: closest geometry to orphan centroid
                     -- (planar KNN — uses the GIST index). Decisive for
                     -- the buffered strategy (multiple same-iso candidates
                     -- within ~3°); a no-op for exact / topological because
                     -- containment is already a single match per (level,
                     -- iso) pair.
                     ST_Centroid(o.geom) <-> p.geom
        )
        UPDATE jurisdictions j
        SET parent_id           = bp.parent_id,
            parent_assigned_via = {strategy_expr},
            updated_at          = NOW()
        FROM best_parent bp
        WHERE j.id = bp.orphan_id
        RETURNING j.parent_assigned_via
    """
    with get_cursor(conn) as cur:
        cur.execute(sql, (orphan_level, orphan_level))
        rows = cur.fetchall()

    n_direct       = sum(1 for r in rows if r["parent_assigned_via"] == "direct")
    n_skip         = sum(1 for r in rows if r["parent_assigned_via"] == "skip_ancestor")
    n_buffered     = sum(1 for r in rows if r["parent_assigned_via"] == "buffered")
    n_topo         = sum(1 for r in rows if r["parent_assigned_via"] == "topological")
    n_cross        = sum(1 for r in rows if r["parent_assigned_via"] == "cross_iso_topological")

    if strategy in ("direct_intersect", "exact"):
        return (n_direct, n_skip)
    if strategy == "buffered":
        return (n_buffered, 0)
    if strategy == "topological":
        return (n_topo, n_cross)
    return (0, 0)


def post_pass_orphan_resolution(
    conn: psycopg2.extensions.connection,
    earth_uuid: str,
    log: logging.Logger,
) -> dict:
    """
    End-of-import post-pass that synthesizes any missing country-level rows
    and then re-resolves remaining orphans via the strategy ladder.

    Run AFTER all ADM levels finish loading. Walks levels shallow-to-deep so
    each level's resolved rows become valid parents for deeper levels in
    subsequent passes (e.g. synthesizing PRI level-1 → PRI level-3 chains
    to it → PRI level-4 chains to PRI level-3).

    Returns dict of counts: {synthesized, direct, skip_ancestor, buffered,
    topological, cross_iso_topological, residual}
    """
    counts = {
        "synthesized":             0,
        "direct":                  0,
        "skip_ancestor":           0,
        "buffered":                0,
        "topological":             0,
        "cross_iso_topological":   0,
        "residual":                0,
    }

    log.info("=== Phase J post-pass: orphan resolution ===")

    # Step 1: synthesize country rows for isos with only deep levels.
    # Phase O typically does this mid-loop (after each ADM level), so by
    # the time we reach the post-pass synthesised should usually be 0.
    # Kept here as belt-and-suspenders for any iso that surfaces only
    # after the full Phase 1 finishes.
    counts["synthesized"] = synthesize_missing_country_rows(conn, earth_uuid, log)
    conn.commit()

    # Step 2: re-resolve orphans level-by-level. Three strategies in
    # increasing permissiveness:
    #   exact       — same-iso, centroid OR PointOnSurface containment
    #   buffered    — same-iso, simplified+buffered intersect
    #   topological — drop iso filter, deepest containment wins. Tags
    #                 cross_iso_topological when chosen iso ≠ orphan iso.
    for level in (2, 3, 4, 5, 6):
        with get_cursor(conn) as cur:
            cur.execute(
                "SELECT COUNT(*) AS cnt FROM jurisdictions "
                "WHERE adm_level = %s AND parent_id IS NULL AND deleted_at IS NULL",
                (level,)
            )
            initial_orphans = cur.fetchone()["cnt"]

        if initial_orphans == 0:
            continue

        # Direct-level ST_Intersects first — re-chains coastal/precision sub-units
        # to their true parent before the strict-containment classifier can
        # mislabel them 'skip_ancestor'.
        n_di, _ = _resolve_orphans_at_level_via_strategy(conn, level, "direct_intersect")
        conn.commit()
        counts["direct"] += n_di

        n_direct, n_skip = _resolve_orphans_at_level_via_strategy(conn, level, "exact")
        conn.commit()
        counts["direct"]        += n_direct
        counts["skip_ancestor"] += n_skip

        n_buf, _ = _resolve_orphans_at_level_via_strategy(conn, level, "buffered")
        conn.commit()
        counts["buffered"] += n_buf

        # Phase O: topological fallback runs on whatever's still orphan.
        # Tagged cross_iso_topological when the chosen parent is in a
        # different iso (the audit-worthy case — features whose iso
        # label and geographic ancestry diverge, e.g. FRA Cayenne →
        # GUF parent). 'topological' on its own is rare (means same
        # iso but Strategies 1+2 missed; usually a precision glitch).
        n_topo, n_cross = _resolve_orphans_at_level_via_strategy(conn, level, "topological")
        conn.commit()
        counts["topological"]           += n_topo
        counts["cross_iso_topological"] += n_cross

        log.info(
            "Post-pass level %d: %d orphans → %d direct + %d skip + %d buffered "
            "+ %d topological + %d cross_iso_topological = %d residual",
            level, initial_orphans,
            n_direct, n_skip, n_buf, n_topo, n_cross,
            initial_orphans - n_direct - n_skip - n_buf - n_topo - n_cross
        )

    # Final residual count
    with get_cursor(conn) as cur:
        cur.execute(
            "SELECT COUNT(*) AS cnt FROM jurisdictions "
            "WHERE parent_id IS NULL AND adm_level > 0 AND deleted_at IS NULL"
        )
        counts["residual"] = cur.fetchone()["cnt"]

    log.info(
        "Post-pass summary: synthesized=%d, direct=%d, skip_ancestor=%d, "
        "buffered=%d, topological=%d, cross_iso_topological=%d, residual orphans=%d",
        counts["synthesized"], counts["direct"], counts["skip_ancestor"],
        counts["buffered"], counts["topological"], counts["cross_iso_topological"],
        counts["residual"]
    )

    # Phase S: convert cross_iso_topological parents to same-iso parents.
    # Three-pass: re-resolve to existing same-iso ancestor, synthesise
    # meaningful intermediaries for ≥2-feature clusters, singleton
    # fallback to same-iso top-level.
    s_counts = phase_s_resolve_cross_iso(conn, earth_uuid, log)
    counts.update({f"phase_s_{k}": v for k, v in s_counts.items()})
    # Cross-iso category should now be 0; recompute for the summary
    with get_cursor(conn) as cur:
        cur.execute("SELECT COUNT(*) AS cnt FROM jurisdictions "
                    "WHERE parent_assigned_via='cross_iso_topological' "
                    "AND deleted_at IS NULL")
        counts["cross_iso_topological_remaining"] = cur.fetchone()["cnt"]

    return counts


# ─── Phase S: same-iso intermediary synthesis ────────────────────────────────

def phase_s_resolve_cross_iso(
    conn: psycopg2.extensions.connection,
    earth_uuid: str,
    log: logging.Logger,
) -> dict:
    """
    Convert every row tagged parent_assigned_via='cross_iso_topological'
    into a same-iso parent assignment. Three passes:

      Pass 1 — Re-resolve via existing same-iso ancestor: if any same-iso
          polygon at any level now contains the row's centroid/PointOnSurface,
          re-parent there (e.g. PRI municipios → synthesised PRI top-level
          that wasn't around when they first loaded).

      Pass 2 — Synthesise meaningful intermediary: cross-iso clusters of
          size ≥ 2 grouped by (orphan_iso, bridge_parent_iso). For each,
          synthesise an iso=orphan_iso row at adm_level=2 with
          ST_Union(cluster) as polygon, parent=orphan_iso top-level.
          Re-parent the cluster members to it (skip_ancestor).

      Pass 3 — Singleton fallback: cluster size = 1 → skip_ancestor to
          same-iso top-level. Avoids creating a 1-child senseless
          intermediary identical to the row itself.

    Synthetic intermediaries get population via Phase Q's topological
    raster fallback when Phase 2 runs (own-iso raster doesn't cover the
    polygon → finds an overlapping neighbour iso's raster → uses it).

    Returns counts dict: {pass1_reresolved, pass2_synthesised,
    pass2_repaired, pass3_singletons, remaining}.
    """
    log.info("=== Phase S: cross-iso → same-iso intermediary synthesis ===")
    out = {"pass1_reresolved": 0, "pass2_synthesised": 0,
           "pass2_repaired": 0, "pass3_singletons": 0, "remaining": 0}

    # Pass 1: re-resolve to existing same-iso ancestor (PRI case).
    # DISTINCT ON picks the deepest same-iso ancestor whose polygon
    # contains the orphan's centroid or PointOnSurface.
    try:
        with get_cursor(conn) as cur:
            cur.execute("""
                WITH cross_iso_rows AS (
                    SELECT id, geom, iso_code, adm_level
                    FROM   jurisdictions
                    WHERE  parent_assigned_via = 'cross_iso_topological'
                      AND  deleted_at IS NULL
                ),
                best_match AS (
                    SELECT DISTINCT ON (c.id)
                        c.id           AS orphan_id,
                        p.id           AS parent_id,
                        p.adm_level    AS parent_adm_level,
                        c.adm_level    AS orphan_adm_level
                    FROM cross_iso_rows c
                    JOIN jurisdictions  p
                      ON p.iso_code   = c.iso_code
                     AND p.adm_level  < c.adm_level
                     AND p.deleted_at IS NULL
                     AND (
                          ST_Contains(p.geom, ST_Centroid(c.geom))
                       OR ST_Contains(p.geom, ST_PointOnSurface(c.geom))
                     )
                    ORDER BY c.id, p.adm_level DESC
                )
                UPDATE jurisdictions j
                SET    parent_id           = bm.parent_id,
                       parent_assigned_via = CASE
                           WHEN bm.parent_adm_level = bm.orphan_adm_level - 1
                                THEN 'direct'::varchar(32)
                           ELSE 'skip_ancestor'::varchar(32)
                       END,
                       updated_at          = NOW()
                FROM   best_match bm
                WHERE  j.id = bm.orphan_id
                RETURNING j.iso_code, j.parent_assigned_via
            """)
            rows = cur.fetchall()
            out["pass1_reresolved"] = len(rows)
        conn.commit()
        if out["pass1_reresolved"] > 0:
            log.info("Phase S pass 1 (re-resolve to existing same-iso ancestor): %d rows",
                     out["pass1_reresolved"])
    except Exception as exc:
        log.warning("Phase S pass 1 failed: %s", exc)
        conn.rollback()

    # Pass 2: synthesise same-iso intermediaries for ≥2-feature clusters.
    # Group remaining cross-iso rows by (orphan_iso, bridge_parent_iso) —
    # one synthetic row per cluster.
    try:
        with get_cursor(conn) as cur:
            cur.execute("""
                SELECT j.iso_code                              AS orphan_iso,
                       p.iso_code                              AS bridge_iso,
                       array_agg(j.id::text)                   AS orphan_ids,
                       ST_Multi(ST_Union(j.geom))              AS cluster_geom,
                       COUNT(*)                                AS cluster_size
                FROM   jurisdictions j
                JOIN   jurisdictions p ON p.id = j.parent_id
                WHERE  j.parent_assigned_via = 'cross_iso_topological'
                  AND  j.deleted_at IS NULL
                GROUP BY j.iso_code, p.iso_code
                HAVING COUNT(*) >= 2
            """)
            clusters = cur.fetchall()

        for cluster in clusters:
            orphan_iso  = cluster["orphan_iso"]
            bridge_iso  = cluster["bridge_iso"]
            orphan_ids  = cluster["orphan_ids"]
            cluster_geom = cluster["cluster_geom"]
            cluster_size = cluster["cluster_size"]

            # Name for the synthetic intermediary: the bridge iso's
            # display name (e.g. "French Guiana" for GUF). Falls back to
            # the bridge iso code if no metadata row exists.
            with get_cursor(conn) as cur:
                cur.execute("""
                    SELECT name FROM geoboundary_metadata
                    WHERE iso_code = %s AND name IS NOT NULL
                    ORDER BY adm_level ASC LIMIT 1
                """, (bridge_iso,))
                row = cur.fetchone()
            intermediate_name = (row["name"].strip() if row and row["name"] else bridge_iso)
            intermediate_slug = f"{orphan_iso.lower()}-2-{_sanitize_name(intermediate_name)}"

            # Locate the orphan_iso's top-level row (adm_level=1) to use
            # as the new parent. If missing (extreme edge case), bail.
            with get_cursor(conn) as cur:
                cur.execute("""
                    SELECT id::text AS id FROM jurisdictions
                    WHERE iso_code=%s AND adm_level=1 AND deleted_at IS NULL
                    LIMIT 1
                """, (orphan_iso,))
                row = cur.fetchone()
            if not row:
                log.warning("Phase S pass 2: no iso=%s top-level row — skipping cluster",
                            orphan_iso)
                continue
            top_id = row["id"]

            # Insert the synthetic intermediary. ON CONFLICT (slug) DO NOTHING
            # makes the operation idempotent across re-runs / partial recoveries.
            try:
                with get_cursor(conn) as cur:
                    cur.execute("""
                        INSERT INTO jurisdictions (
                            id, name, slug, iso_code, adm_level,
                            parent_id, source, parent_assigned_via,
                            geom, centroid,
                            created_at, updated_at
                        ) VALUES (
                            gen_random_uuid(), %s, %s, %s, 2,
                            %s, 'synthetic_intermediary', 'direct',
                            %s, ST_Centroid(%s),
                            NOW(), NOW()
                        )
                        ON CONFLICT (slug) DO UPDATE SET updated_at = NOW()
                        RETURNING id::text AS id
                    """, (
                        intermediate_name, intermediate_slug, orphan_iso,
                        top_id, cluster_geom, cluster_geom,
                    ))
                    inter_id = cur.fetchone()["id"]

                # Re-parent the cluster's orphans to the new intermediary.
                # parent_assigned_via='skip_ancestor' because we're jumping
                # several adm_level steps (intermediate is level 2, orphans
                # at deeper levels — typically 6 for FRA communes).
                with get_cursor(conn) as cur:
                    cur.execute("""
                        UPDATE jurisdictions
                        SET    parent_id           = %s::uuid,
                               parent_assigned_via = 'skip_ancestor'::varchar(32),
                               updated_at          = NOW()
                        WHERE  id = ANY(%s::uuid[])
                    """, (inter_id, orphan_ids))
                    out["pass2_repaired"] += cur.rowcount
                conn.commit()
                out["pass2_synthesised"] += 1
                log.info("Phase S pass 2: synthesised '%s' (iso=%s, lvl 2) "
                         "for %d %s features bridged from %s",
                         intermediate_name, orphan_iso, cluster_size,
                         orphan_iso, bridge_iso)
            except Exception as exc:
                log.warning("Phase S pass 2 failed for cluster (%s→%s): %s",
                            orphan_iso, bridge_iso, exc)
                conn.rollback()
    except Exception as exc:
        log.warning("Phase S pass 2 (cluster query) failed: %s", exc)
        conn.rollback()

    # Pass 3: singleton fallback — re-parent any remaining cross_iso row
    # to same-iso top-level via skip_ancestor.
    try:
        with get_cursor(conn) as cur:
            cur.execute("""
                UPDATE jurisdictions j
                SET    parent_id           = top.id,
                       parent_assigned_via = 'skip_ancestor'::varchar(32),
                       updated_at          = NOW()
                FROM (
                    SELECT id, iso_code FROM jurisdictions
                    WHERE adm_level = 1 AND deleted_at IS NULL
                ) top
                WHERE top.iso_code = j.iso_code
                  AND j.parent_assigned_via = 'cross_iso_topological'
                  AND j.deleted_at IS NULL
            """)
            out["pass3_singletons"] = cur.rowcount
        conn.commit()
        if out["pass3_singletons"] > 0:
            log.info("Phase S pass 3 (singletons → same-iso top): %d rows",
                     out["pass3_singletons"])
    except Exception as exc:
        log.warning("Phase S pass 3 failed: %s", exc)
        conn.rollback()

    # Sanity check — any leftovers?
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT COUNT(*) AS cnt FROM jurisdictions
            WHERE parent_assigned_via='cross_iso_topological'
              AND deleted_at IS NULL
        """)
        out["remaining"] = cur.fetchone()["cnt"]

    log.info(
        "Phase S summary: pass1_reresolved=%d, pass2_synthesised=%d intermediaries "
        "(repaired %d features), pass3_singletons=%d, remaining_cross_iso=%d",
        out["pass1_reresolved"], out["pass2_synthesised"], out["pass2_repaired"],
        out["pass3_singletons"], out["remaining"]
    )
    return out


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

def find_parent_by_strategy_ladder(
    conn: psycopg2.extensions.connection,
    geom_geojson: str,
    orphan_adm_level: int,
    iso3: str,
) -> tuple[str | None, str | None]:
    """
    Find a parent for a feature at `orphan_adm_level` belonging to `iso3` via
    a three-strategy cascade.

    Strategies search at any shallower level (`adm_level < orphan_level`)
    and pick the deepest available match. Strategy classification is
    post-hoc based on the chosen parent's iso/level:

      'direct'                — same iso, parent.adm_level == orphan_level - 1
      'skip_ancestor'         — same iso, parent.adm_level <  orphan_level - 1
      'buffered'              — Strategy 2 same-iso 250 km proximity match
      'topological'           — Strategy 3 same-iso match through pure topology
                                (rare; Strategies 1+2 had a glitch but 3 caught)
      'cross_iso_topological' — Strategy 3 jumped to a different iso (the
                                meaningful audit category — features whose
                                geographic ancestor lives under a different
                                iso label, e.g. iso='FRA' Cayenne whose true
                                ancestor is iso='GUF')
      None                    — genuine orphan; surfaced via Phase I review

    Phase O (generalised orphan reduction): Strategy 1 now matches via
    centroid OR PointOnSurface (covers crescent/island features where
    centroid falls in ocean). Strategy 3 added — pure topology, no iso
    filter, catches features whose iso label and geographic ancestry
    diverge (overseas territories, dependent territories, dual-footprint
    pairs) without curated lookup tables.

    Phase O.2 (final orphan-reduction pass): Strategy 2 dropped the
    0.01° ST_Simplify on the orphan and replaced the ~110 m planar
    buffer with a 3.0° planar ST_DWithin + closest-edge tie-breaker.
    Distance analysis of the residual orphans showed every one of them
    has a same-iso ancestor within 236 km; 3.0° (≈ 333 km at equator,
    166 km at 60°N) clears that with margin while staying well below
    the ~2,000 km false-positive distance that bit main
    (Cayenne→Marie-Galante).

    Phase O.3 (perf hot-fix, 2026-05-09): the initial Phase O.2 ship
    used ST_DWithin on geography (250 km geodesic). That casts geom→
    geography on the fly, which invalidates the GIST spatial index and
    forces a parallel bitmap-heap-scan with exact geography distance on
    every candidate — measured 168 s per query against FRA cantons,
    versus 1.4 s for the planar equivalent (120× speedup). Switched to
    planar geometry; semantics are unchanged (same orphans get caught,
    same tie-breaker picks the same parent).

    Phase L (memory-tuned): the ORDER BY ST_Area(ST_Intersection(...)) tiebreak
    on native (5-million-vertex) geometries was OOM-killing PostgreSQL backends
    during world imports. Centroid/PointOnSurface containment is cheap
    regardless of parent vertex count. Strategy 2 (Phase O.3) uses
    ST_DWithin on geometry with the GIST `&&` bbox shortcut, keeping
    per-call work bounded even on large parent polygons.

    Returns (parent_uuid, strategy_used). strategy_used is None when the
    feature stays orphan.
    """
    # Strategy 0 (direct-level intersection): match the orphan to a same-iso
    # parent at the EXACT level above it (adm_level == orphan_level - 1) via
    # ST_Intersects, not strict point-containment. A sub-unit always overlaps
    # its proper parent even when the two boundaries are clipped differently at
    # a coastline (e.g. Aransas vs Texas, Yuhuanxian vs Zhejiang). Those cases
    # fail Strategy 1's strict ST_Contains(point) and used to skip straight to
    # the country as 'skip_ancestor' — the regression the operator hit. Running
    # this FIRST restores the pre-regression direct-level match and leaves the
    # strict-containment ladder below to handle only genuine no-intermediate-
    # level territories (Puerto Rico, Guam, Réunion).
    #
    # Tie-break (a border feature can intersect two parents): prefer the parent
    # that actually contains the orphan's surface point / centroid; else the one
    # with the largest bbox overlap. Both are cheap — point-in-polygon (cheap
    # regardless of parent vertex count) + envelope area — never a full
    # ST_Intersection (which OOMs on 5M-vertex geoms; see Strategy 2 notes). The
    # GIST '&&' index bounds the ST_Intersects candidate set; at the direct
    # level the parents are states/provinces, not the giant country polygon.
    sql0 = """
        WITH o AS (SELECT ST_SetSRID(ST_GeomFromGeoJSON(%s), 4326) AS geom)
        SELECT j.id
        FROM   jurisdictions j, o
        WHERE  j.iso_code   = %s
          AND  j.adm_level  = %s
          AND  j.deleted_at IS NULL
          AND  ST_Intersects(j.geom, o.geom)
        ORDER BY
          (ST_Contains(j.geom, ST_PointOnSurface(o.geom))
            OR ST_Contains(j.geom, ST_Centroid(o.geom))) DESC,
          ST_Area(ST_Intersection(ST_Envelope(j.geom), ST_Envelope(o.geom))) DESC
        LIMIT 1
    """
    if orphan_adm_level - 1 >= 0:
        try:
            with get_cursor(conn) as cur:
                cur.execute(sql0, (geom_geojson, iso3, orphan_adm_level - 1))
                row = cur.fetchone()
            if row:
                return str(row["id"]), "direct"
        except Exception as exc:
            logger.warning("Strategy 0 lookup failed for iso3=%s lvl=%d: %s",
                           iso3, orphan_adm_level, exc)
            conn.rollback()

    # Strategy 1: same-iso, centroid OR PointOnSurface containment.
    # ST_Centroid is the fast common case; ST_PointOnSurface is the
    # crescent/island fallback (centroid in ocean, but PointOnSurface is
    # guaranteed to lie on the orphan's geometry → if a parent contains
    # the orphan, it contains this point). Cost is small — both functions
    # operate on the orphan's own geometry, which is reasonable size.
    sql1 = """
        SELECT id, adm_level
        FROM   jurisdictions
        WHERE  iso_code   = %s
          AND  adm_level  < %s
          AND  deleted_at IS NULL
          AND  (
                ST_Contains(geom, ST_Centroid(
                    ST_SetSRID(ST_GeomFromGeoJSON(%s), 4326)))
             OR ST_Contains(geom, ST_PointOnSurface(
                    ST_SetSRID(ST_GeomFromGeoJSON(%s), 4326)))
          )
        ORDER BY adm_level DESC
        LIMIT 1
    """
    try:
        with get_cursor(conn) as cur:
            cur.execute(sql1, (iso3, orphan_adm_level, geom_geojson, geom_geojson))
            row = cur.fetchone()
        if row:
            parent_lvl = int(row["adm_level"])
            strategy   = "direct" if parent_lvl == orphan_adm_level - 1 else "skip_ancestor"
            return str(row["id"]), strategy
    except Exception as exc:
        logger.warning("Strategy 1 lookup failed for iso3=%s lvl=%d: %s",
                       iso3, orphan_adm_level, exc)
        conn.rollback()

    # Strategy 2: same-iso, ~3.0° planar proximity, tie-broken by closest
    # geometry to the orphan's centroid.
    #
    # Why 3.0° (planar, not geodesic): distance analysis of the residual
    # 80 orphans showed the nearest same-iso ancestor sits at most
    # 235.5 km away (SLB Malaita Outer Island → Malaita, at 9°S where
    # 3.0° ≈ 333 km). 3.0° clears that with margin and stays safely
    # below the FRA→Marie-Galante (~2,000 km) false-positive ceiling
    # that bit main's nearest-centroid fallback. Cross-iso cases (FRA
    # Cayenne → GUF, MNP Northern Islands → USA, etc.) are already
    # resolved by Strategy 3, so this same-iso reach is bounded by what
    # an orphan's true geographic neighbours look like — not by main's
    # "nearest FRA polygon at any distance" failure mode.
    #
    # Why planar instead of geography: ST_DWithin(geog, geog, m) casts
    # geom→geography on the fly, which invalidates the GIST spatial
    # index. PostgreSQL falls back to parallel bitmap-heap-scan + exact
    # geography distance on every candidate row — measured 168 s per
    # query against FRA cantons in the 2026-05-09 hot-fix run. Planar
    # ST_DWithin uses the GIST `&&` bbox shortcut: same query in ~1.4 s
    # (120× faster). Effective reach varies with latitude (3.0° E-W =
    # 333 km at equator, 166 km at 60°N) but covers every case in the
    # residual-orphan distance profile with margin.
    #
    # Why no ST_Simplify: the prior 0.01° simplify on the orphan was
    # introducing buffer-edge ambiguity that missed 22 boundary-touch
    # cases (FJI Rotuma sub-features, ARG Comunas, Piteå stadsdistrikt).
    #
    # Tie-breaker: ORDER BY adm_level DESC (deepest), then closest-edge
    # distance to the orphan's centroid (planar KNN, also index-
    # accelerated). Disambiguates when 3.0° pulls in multiple same-iso
    # candidates at the same level (e.g. Wuqiu sees both Kinmen and
    # Republic Of China; deepest-then-closest picks Kinmen).
    sql2 = """
        SELECT j.id, j.adm_level
        FROM   jurisdictions j
        WHERE  j.iso_code   = %s
          AND  j.adm_level  < %s
          AND  j.deleted_at IS NULL
          AND  ST_DWithin(
                   j.geom,
                   ST_SetSRID(ST_GeomFromGeoJSON(%s), 4326),
                   3.0
               )
        ORDER BY j.adm_level DESC,
                 ST_Centroid(ST_SetSRID(ST_GeomFromGeoJSON(%s), 4326))
                   <-> j.geom
        LIMIT 1
    """
    try:
        with get_cursor(conn) as cur:
            cur.execute(sql2, (iso3, orphan_adm_level, geom_geojson, geom_geojson))
            row = cur.fetchone()
        if row:
            return str(row["id"]), "buffered"
    except Exception as exc:
        logger.warning("Strategy 2 (buffered) lookup failed for iso3=%s lvl=%d: %s",
                       iso3, orphan_adm_level, exc)
        conn.rollback()

    # Strategy 3: pure topological fallback — drop the iso filter.
    # When same-iso 1 and 2 both fail, the orphan's iso label and its
    # geographic ancestry have diverged. This happens for overseas
    # territories (FRA's Cayenne is geographically inside GUF's hierarchy)
    # and any dual-footprint arrangement. Pure topology — no curated
    # tables. Centroid OR PointOnSurface, deepest containment wins.
    #
    # Tag distinctly: 'cross_iso_topological' when the chosen parent's iso
    # differs from the orphan's iso (the meaningful audit case);
    # 'topological' when same-iso (rare; usually means Strategies 1+2 had
    # a glitch). The distinct labels let the Phase I review surface
    # surface only the genuine cross-iso bridges for operator review.
    sql3 = """
        SELECT id, adm_level, iso_code
        FROM   jurisdictions
        WHERE  adm_level  < %s
          AND  deleted_at IS NULL
          AND  (
                ST_Contains(geom, ST_Centroid(
                    ST_SetSRID(ST_GeomFromGeoJSON(%s), 4326)))
             OR ST_Contains(geom, ST_PointOnSurface(
                    ST_SetSRID(ST_GeomFromGeoJSON(%s), 4326)))
          )
        ORDER BY adm_level DESC
        LIMIT 1
    """
    try:
        with get_cursor(conn) as cur:
            cur.execute(sql3, (orphan_adm_level, geom_geojson, geom_geojson))
            row = cur.fetchone()
        if row:
            chosen_iso = row["iso_code"]
            strategy = "cross_iso_topological" if chosen_iso != iso3 else "topological"
            return str(row["id"]), strategy
    except Exception as exc:
        logger.warning("Strategy 3 (topological) lookup failed for iso3=%s lvl=%d: %s",
                       iso3, orphan_adm_level, exc)
        conn.rollback()

    return None, None  # genuine orphan — Step 2 review surfaces it


# ─── Backwards-compat wrapper kept for import_worldpop and other callers ─────
# (no internal callers in this file after Phase J — kept for safety)
def find_parent_by_spatial(
    conn: psycopg2.extensions.connection,
    geom_geojson: str,
    parent_adm_level_app: int,
    iso3: str,
) -> str | None:
    """Legacy single-strategy spatial parent lookup. Phase J's
    find_parent_by_strategy_ladder is preferred. Kept callable to avoid
    breaking any external callers; returns just the parent uuid.

    Phase L: parameter is now GeoJSON text rather than WKT (matches the
    in-process pipeline; no Python-side geometry conversion needed)."""
    parent, _strategy = find_parent_by_strategy_ladder(conn, geom_geojson, parent_adm_level_app, iso3)
    return parent


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
        return _demojibake(str(props[level_key]).strip())

    for key in NAME_KEYS:
        val = props.get(key)
        if val and str(val).strip() not in ("", "None", "null"):
            return _demojibake(str(val).strip())
    return "Unknown"


# Phase L: no Python-side geometry conversion. Source GeoJSON `geometry` is
# passed straight to PostgreSQL via ST_GeomFromGeoJSON (see _JURISDICTION_TEMPLATE
# in db.py). PostGIS handles topology repair (ST_MakeValid) and singleton →
# multi promotion (ST_Multi) server-side. The previous _geometry_to_ewkb /
# _geometry_to_wkt helpers — which used shapely.simplify(0.001°) on every
# feature — have been removed; we no longer simplify at ingest.


def _iter_features_streaming(geojson_path: Path):
    """
    Yield feature dicts from a GeoJSON FeatureCollection one at a time.

    Uses ijson event-based parsing so the whole file is never loaded into
    memory. For CAN-ADM1 (619 MB on disk, multi-GB as a Python tree) this
    is the difference between a successful import and an OOM kill.

    `use_float=True` tells ijson to emit native Python floats for JSON
    numbers (default is Decimal, which json.dumps can't serialize and
    which we don't need — PostGIS stores geometry coordinates as
    double-precision anyway).

    Caller is responsible for handling I/O / parse errors — propagated as-is.
    """
    with open(geojson_path, "rb") as f:
        # "features.item" tells ijson: walk the top-level `features` array
        # and emit each element as a parsed dict. ijson's C backend
        # (ijson.backends.yajl2_c) handles SAX-style parsing efficiently.
        yield from ijson.items(f, "features.item", use_float=True)


def _check_crs_in_head(geojson_path: Path, log: logging.Logger) -> bool:
    """
    Defensive CRS sanity check that doesn't load the whole file.

    Reads the first 64 KB of the GeoJSON looking for a `crs` member with a
    non-default name (anything other than 4326 / CRS84). Returns False when
    a non-default CRS is detected; True otherwise (including "no crs member"
    which is the modern RFC 7946 default).

    geoBoundaries always publishes WGS84, so this is purely defensive against
    a future data source that doesn't follow that convention. The `crs`
    member always appears at the top of a GeoJSON file (alongside `type`,
    `name`) and well before the `features` array, so 64 KB is plenty.
    """
    try:
        with open(geojson_path, encoding="utf-8") as f:
            head = f.read(65536)
    except Exception:
        return True   # unreadable head — let the main streaming flow fail loudly

    m = re.search(r'"crs"\s*:\s*\{[^{}]*"name"\s*:\s*"([^"]+)"', head)
    if m:
        crs_name = m.group(1)
        if "4326" not in crs_name and "CRS84" not in crs_name:
            log.error(
                "%s declares CRS %r but the import path assumes WGS84 (4326). "
                "Reproject the source file before retrying.",
                geojson_path, crs_name,
            )
            return False
    return True


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
    heartbeat_queue_preview: list[str] | None = None,
    heartbeat_country_jur_id: str | None = None,   # P.2 — iso-locked minimap
    bar_key: str | None = None,                    # P.1.1 — inner bar progress
    bar_baseline: int = 0,                          # P.1.1 — features already counted before this country
    bar_cap: int | None = None,                     # P.1.1 — clamp to bar's expected total
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

    # ── Phase 1: CRS sanity check (head-of-file regex, no full load) ────────
    # Phase L: ijson streams the features rather than loading them all into
    # memory at once. geoBoundaries always publishes WGS84 4326 GeoJSON (RFC
    # 7946 default), so we don't reproject — but we still scan the file head
    # for an explicit `crs` member to fail loudly on a future non-WGS84
    # source.
    if not _check_crs_in_head(geojson_path, log):
        progress.setdefault("geoboundaries", {})[progress_key] = {
            "status": "error",
            "error": "non-WGS84 CRS detected",
            "timestamp": datetime.now(timezone.utc).isoformat(),
        }
        return []

    # ── Phase 2+3: Single streaming pipeline ─────────────────────────────────
    # Phase L (memory-tuned): the previous Phase-2-then-Phase-3 split required
    # accumulating ALL features for a file in `pending_spatial` + `ready_rows`
    # before any DB work. For ADM3 files with thousands of features (each
    # holding the GeoJSON text of its geometry) plus the batch buffer plus
    # libpq receive buffer, peak Python memory was hitting multi-GB and the
    # cgroup OOM-killed seed_database.py mid-run.
    #
    # New flow: stream a feature → build the row → resolve parent_id (parent
    # map for ADM0/1, strategy ladder for ADM2+ via the open conn) → append
    # to batch → flush when the batch is full. At any moment the only Python
    # state is one in-flight feature dict (briefly), the current batch
    # (BATCH_BYTE_LIMIT bound), and a few small bookkeeping ints. Peak memory
    # is bounded by the largest single feature plus the batch ceiling.
    all_inserted_ids: list[str] = []

    HEARTBEAT_EVERY     = 25
    queue_preview       = heartbeat_queue_preview or []
    adm_level_app_local = adm_level_app
    level_label         = NATURAL_LABEL.get(adm_level_app_local, f"Level {adm_level_app_local}")
    # P.1.2: plural form for inline progress text. Falls back to singular
    # if no plural is defined for this level.
    level_label_plural  = NATURAL_LABEL_PLURAL.get(adm_level_app_local, level_label)
    # Country's expected feature count from the meta CSV (e.g. for FRA
    # ADM4 this is the number of cantons France ships in that file).
    # Used as the denominator in "{plural} processed X of Y".
    try:
        country_feature_total = int(meta_row.get("admUnitCount") or 0)
    except (TypeError, ValueError):
        country_feature_total = 0

    def emit_heartbeat(processed: int, label: str) -> None:
        # P.1.2: pass the country's expected feature count (from meta CSV's
        # admUnitCount) as progress_total so the frontend can render
        # "currently HTI · Counties processed 200 of 1,234 (16%)" from
        # structured data instead of parsing the pre-formatted label.
        # Falls back to None when meta CSV has no row for this iso+level.
        try:
            heartbeat.write_current(
                id               = heartbeat_country_jur_id,   # iso-locked minimap (P.2)
                name             = iso3,
                iso_code         = iso3,
                adm_level        = adm_level_app_local,
                phase            = "geoboundaries",
                sub_phase        = label,
                queue_preview    = queue_preview,
                progress_current = processed,
                progress_total   = country_feature_total if country_feature_total > 0 else None,
            )
            # P.1.1: also advance the level's ADM bar based on intra-file
            # progress so the operator sees movement during big-country
            # files (e.g. CAN at ADM1 with 13 huge multi-million-vertex
            # provinces, or CHN at ADM3 with thousands of municipalities).
            # Without this, the bar's `current` only ticks once per
            # country, leaving large countries sitting at one value for
            # minutes while ETA inflates.
            if bar_key is not None:
                ticked = bar_baseline + processed
                if bar_cap is not None:
                    ticked = min(ticked, bar_cap)
                heartbeat.bar_update(bar_key, ticked)
        except Exception:
            pass   # heartbeat is best-effort, never block the import

    try:
        feature_stream = _iter_features_streaming(geojson_path)
    except Exception as exc:
        log.error("GeoJSON open failed for %s: %s", geojson_path, exc)
        progress.setdefault("geoboundaries", {})[progress_key] = {
            "status": "error",
            "error": str(exc),
            "timestamp": datetime.now(timezone.utc).isoformat(),
        }
        return []

    # Open the DB connection upfront. With the streaming pipeline it stays
    # open for the duration of the file, doing per-feature parent lookups
    # and periodic batch INSERTs. This is fine — psycopg2 keeps the
    # connection healthy via the keepalive settings in db.py.
    conn = get_connection()
    try:
        # P.1.2 resume-safe seek: count rows already in the DB for this
        # (iso, app_adm_level) pair. On `--resume`, a country file that
        # was mid-processing during the halt re-enters this function
        # because progress.json only marks the file done when the WHOLE
        # file finishes. Without skipping, the feature stream re-iterates
        # from index 0; the slug-bump in compute_slug() then treats every
        # pre-existing row as a name collision and inserts a duplicate
        # row with `-2`/`-3` suffix. Skip-first-N relies on geoBoundaries'
        # GeoJSON files being in stable feature order (verified for the
        # 2026-04 release).
        try:
            with get_cursor(conn) as _cur:
                _cur.execute(
                    "SELECT COUNT(*) AS n FROM jurisdictions "
                    "WHERE iso_code = %s AND adm_level = %s AND deleted_at IS NULL",
                    (iso3, adm_level_app_local),
                )
                _row = _cur.fetchone()
                skip_n_resume = int(_row["n"]) if _row else 0
        except Exception:
            skip_n_resume = 0
        if skip_n_resume > 0:
            log.info(
                "%s ADM%d: resume detected — skipping the first %d features "
                "(already in DB from prior run)",
                iso3, adm_n, skip_n_resume,
            )

        batch: list[dict]    = []
        batch_bytes          = 0
        n_processed          = 0
        n_orphans            = 0
        emitted_inner        = False
        feature_idx          = 0   # 1-based position in the feature stream

        def _flush() -> int:
            nonlocal batch, batch_bytes
            if not batch:
                return 0
            ids = bulk_insert_jurisdictions(conn, batch)
            bulk_insert_constitutional_settings(conn, ids)
            all_inserted_ids.extend(ids)
            count = len(batch)
            batch = []
            batch_bytes = 0
            return count

        try:
            for feature in feature_stream:
                feature_idx += 1
                # P.1.2: skip features already processed in a prior run.
                # Cheap (just an int compare), positioned BEFORE the
                # geometry/name/shapeID extraction so the skip cost is
                # negligible relative to the work it avoids.
                if feature_idx <= skip_n_resume:
                    continue

                geom_dict = feature.get("geometry")
                if not geom_dict or not geom_dict.get("coordinates"):
                    # Match the previous behaviour: skip empty/null geometries
                    # silently — known in some geoBoundaries files.
                    continue

                props      = feature.get("properties") or {}
                name       = _extract_name(props, adm_n)
                feat_iso3  = str(props.get("shapeGroup", iso3)).strip().upper() or iso3
                shape_id   = str(props.get("shapeID", "")).strip()

                # ADM0 name override. geoBoundaries' source GeoJSON for some
                # countries has a buggy `shapeName` at ADM0 — observed
                # in-the-wild: ITA-ADM0 says "Nord-Ovest" (one of its
                # macro-regions); IND-ADM0 says "Puducherry" (a union
                # territory). The curated `geoboundary_metadata` CSV
                # carries the correct country name, so we prefer it for
                # country-level features. Falls through silently when
                # meta_row doesn't have a name (e.g. iso has no L0 row in
                # the CSV) — in that case the existing _extract_name
                # output is kept.
                if adm_n == 0:
                    meta_name = (meta_row.get("name") or "").strip()
                    if meta_name and meta_name.lower() not in ("unknown", "none", "null"):
                        name = meta_name

                # Native GeoJSON passthrough: serialize the geometry dict back
                # to JSON text for ST_GeomFromGeoJSON.
                geom_geojson = json.dumps(geom_dict, separators=(",", ":"))

                slug             = make_slug(feat_iso3, adm_level_app, name, existing_slugs)
                geoboundaries_id = shape_id if shape_id else boundary_id
                official_langs   = get_languages(feat_iso3, unsdg_region)

                row = {
                    "name":               name,
                    "slug":               slug,
                    "iso_code":           feat_iso3,
                    "adm_level":          adm_level_app,
                    "parent_id":          None,
                    "source":             "geoboundaries",
                    "geoboundaries_id":   geoboundaries_id,
                    "official_languages": official_langs,
                    "timezone":           "UTC",
                    "geom_geojson":       geom_geojson,
                }

                # Drop the parsed dict so the GC can reclaim ~300 MB for a
                # Nunavut-class feature before the next ijson iteration.
                del feature, geom_dict, props

                # ── Resolve parent_id ──
                if adm_n == 0:
                    row["parent_id"]           = earth_uuid
                    row["parent_assigned_via"] = "direct"

                elif adm_n == 1:
                    row["parent_id"] = parent_map.get(feat_iso3)
                    if row["parent_id"]:
                        row["parent_assigned_via"] = "direct"
                    else:
                        log.warning("No ADM0 parent for iso3=%s feature '%s' — inserting as orphan",
                                    feat_iso3, name)
                        row["parent_assigned_via"] = None
                        n_orphans += 1
                        # P.3: surface the orphan-insert as a UI event. The
                        # post-pass orphan resolution may still rescue this
                        # row before the run ends; the event records "we
                        # noticed this at insert time" so the operator has
                        # auditable visibility regardless of outcome.
                        heartbeat.emit_event(
                            level="warn", type="orphan_inline",
                            phase="geoboundaries",
                            iso=feat_iso3, name=name,
                            adm_level=adm_level_app,
                            msg="no ADM0 parent at insert",
                        )

                else:
                    # ADM2+ — strategy ladder lookup against the open conn.
                    parent_id, strategy = find_parent_by_strategy_ladder(
                        conn, geom_geojson, adm_level_app, feat_iso3
                    )
                    row["parent_id"]           = parent_id
                    row["parent_assigned_via"] = strategy
                    if not parent_id:
                        log.warning("No spatial parent for %s '%s' (level %d) — inserting as orphan",
                                    feat_iso3, name, adm_level_app_local)
                        n_orphans += 1
                        heartbeat.emit_event(
                            level="warn", type="orphan_inline",
                            phase="geoboundaries",
                            iso=feat_iso3, name=name,
                            adm_level=adm_level_app_local,
                            msg="no spatial parent at insert",
                        )

                # ── Add to batch; flush when full ──
                rsz = len(geom_geojson)
                if batch and (
                    batch_bytes + rsz > BATCH_BYTE_LIMIT
                    or len(batch)     >= BATCH_ROW_LIMIT
                ):
                    _flush()
                    emit_heartbeat(
                        processed = n_processed,
                        label     = (f"{level_label_plural} processed {n_processed:,} of {country_feature_total:,}"
                                      if country_feature_total > 0
                                      else f"{level_label_plural} processed {n_processed:,}"),
                    )
                    emitted_inner = True

                batch.append(row)
                batch_bytes += rsz
                n_processed += 1

                # P.1.1: per-feature bar tick. heartbeat.bar_update is
                # throttled to ~4 Hz per bar internally, so calling it
                # every iteration is cheap. This is what makes
                # small-count / huge-vertex files (CAN ADM1's 13
                # provinces, USA ADM1's 51 states) advance the bar one
                # province at a time instead of jumping in a single step
                # at the end. Throttle drops the in-between writes for
                # rapid-fire files (IND ADM5's ~200 k features); final
                # value lands via the outer-loop force=True call.
                if bar_key is not None:
                    ticked = bar_baseline + n_processed
                    if bar_cap is not None:
                        ticked = min(ticked, bar_cap)
                    heartbeat.bar_update(bar_key, ticked)

                # Periodic write_current "processed N" text update for
                # long files, even between flushes. Kept at 25-feature
                # cadence so the operator's eye doesn't flicker on the
                # "currently X · processed N" line.
                if n_processed % HEARTBEAT_EVERY == 0:
                    emit_heartbeat(
                        processed = n_processed,
                        label     = (f"{level_label_plural} processed {n_processed:,} of {country_feature_total:,}"
                                      if country_feature_total > 0
                                      else f"{level_label_plural} processed {n_processed:,}"),
                    )
                    emitted_inner = True
        except Exception as exc:
            log.error("GeoJSON streaming failed for %s: %s", geojson_path, exc)
            progress.setdefault("geoboundaries", {})[progress_key] = {
                "status": "error",
                "error": str(exc),
                "timestamp": datetime.now(timezone.utc).isoformat(),
            }
            return []

        # Final flush
        _flush()
        if emitted_inner and n_processed > 0:
            emit_heartbeat(
                processed = n_processed,
                label     = (f"{level_label_plural} processed {n_processed:,} of {country_feature_total:,}"
                              if country_feature_total > 0
                              else f"{level_label_plural} processed {n_processed:,}"),
            )

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
    pause_on_exception: bool = False,
    save_progress_fn=None,
    # Legacy alias kept for backwards compatibility — promoted to the new flag.
    stop_on_exception: bool = False,
) -> int:
    """
    Import geoBoundaries data into the jurisdictions table.

    Args:
        countries:           Optional list of ISO3 codes to process (None = all)
        adm_levels:          Optional list of ADM levels to process, e.g. [0,1,2]
        progress:            Shared progress dict (mutated in-place)
        log:                 Logger instance
        pause_on_exception:  If True, on per-country error pause and ask the
                             operator (skip / retry / abort) via control files.
        save_progress_fn:    Optional callable(progress) — invoked after each
                             country's geojson finishes so the on-disk
                             progress.json stays current and the wizard's
                             "countries done" tile reflects real-time progress
                             instead of stale prior-run data.
        stop_on_exception:   Legacy flag — silently treated the same as
                             pause_on_exception=True. Kept so existing callers
                             continue working through the wizard rename.

    Returns:
        Total number of jurisdictions inserted.
    """
    pause_on_exception = pause_on_exception or stop_on_exception
    if log is None:
        log = logging.getLogger(__name__)
    if progress is None:
        progress = {}

    levels_to_process = set(adm_levels) if adm_levels is not None else set(range(6))

    # ── Discover all available files from the filesystem ──
    # This is the authoritative source — the meta CSV is incomplete.
    all_discovered = discover_geoboundaries_files()

    # ── Load meta CSV: in-memory dict for hot ETL lookups + persist to
    # geoboundary_metadata DB table for downstream consumers (synthesize_
    # missing_country_rows, Setup wizard, DataReviewService).
    _conn = get_connection()
    try:
        meta_index = load_meta_index(META_CSV, conn=_conn)
    finally:
        _conn.close()

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

    # ── Phase P.1.1: pre-register all ADM-level bars in 'pending' state so
    # the operator sees the FULL pipeline at run start, not bars revealing
    # one-by-one as each level kicks off. Bars transition pending →
    # running → done as the ETL works through them.
    #
    # Totals come from geoboundary_metadata.adm_unit_count (sum across the
    # countries we'll process at each level), the same source bar_start
    # uses later. We pre-compute all levels in a single query so the bars
    # all show their final feature target from the get-go.
    #
    # `all_discovered` is a list[tuple[iso, adm_n, path]] — group by adm_n
    # to find which isos have files at each level.
    isos_per_level: dict[int, list[str]] = {}
    for iso, adm_n, _path in all_discovered:
        if 0 <= adm_n <= 5 and adm_n in levels_to_process:
            isos_per_level.setdefault(adm_n, []).append(iso)

    pending_totals: dict[int, int] = {n: 0 for n in isos_per_level}
    if pending_totals:
        try:
            _conn = get_connection()
            try:
                with get_cursor(_conn) as cur:
                    for adm_n, isos in isos_per_level.items():
                        # Per-iso COALESCE so isos that have a file on disk
                        # but no row in the meta CSV (e.g. IND at ADM0 in
                        # the 2026-04 release) still contribute at least
                        # 1 to the bar total. For ADM0 this gives the
                        # exact answer (every ADM0 file = 1 country).
                        # For ADM1+ it's a conservative lower bound — the
                        # missing iso's actual sub-unit count would be
                        # whatever's in its boundary file, but 1 keeps
                        # the bar total ≥ the file iteration count so
                        # the "X of Y" math never reads backwards.
                        cur.execute(
                            """
                            SELECT COALESCE(SUM(COALESCE(m.adm_unit_count, 1)), 0)::bigint AS total
                            FROM   unnest(%s::text[]) AS iso_list(iso_code)
                            LEFT JOIN geoboundary_metadata m
                              ON m.iso_code  = iso_list.iso_code
                             AND m.adm_level = %s
                            """,
                            (isos, adm_n),
                        )
                        row = cur.fetchone()
                        pending_totals[adm_n] = (
                            int(row["total"]) if row and row["total"] else len(isos)
                        )
            finally:
                _conn.close()
        except Exception as exc:
            log.warning("Could not pre-compute feature totals for pending bars "
                        "(falling back to country counts): %s", exc)
            for adm_n in pending_totals:
                pending_totals[adm_n] = len(isos_per_level.get(adm_n, []))

    # Register every level's bar up front. The actual bar_start inside the
    # main loop will transition each to "running" (preserving the total).
    # P.1.2: use plural label ("Counties" not "County"), drop the
    # (ADMn) suffix, and pass a `unit` field so the frontend says
    # "49,363 counties" instead of "49,363 features".
    for adm_n in sorted(pending_totals.keys()):
        app_lvl     = ADM_LEVEL_MAP[adm_n]
        plural_label = NATURAL_LABEL_PLURAL.get(app_lvl, f"Level {app_lvl}")
        heartbeat.bar_register(
            key   = f"gb:adm{adm_n}",
            label = f"Boundaries — {plural_label}",
            total = pending_totals[adm_n],
            unit  = plural_label.lower(),
        )
    log.info("Pre-registered %d ADM-level bars (pending): %s",
             len(pending_totals),
             ", ".join(f"adm{n}={pending_totals[n]:,}" for n in sorted(pending_totals)))

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

        # Phase P.1.1: stacked-progress-bar marker for this ADM level. The bar
        # tracks FEATURES inserted, not country files — the expected total comes
        # from geoboundary_metadata.adm_unit_count (the meta CSV's pre-recorded
        # feature count per iso × adm level), summed across the country files
        # we're about to process. Bar advances by `len(inserted_ids)` after each
        # country, so the operator sees real units of work moving through.
        #
        # Falling back to len(level_files) keeps the bar functional even when
        # the meta CSV doesn't cover every iso (e.g. synthetic-only isos).
        adm_level_label = NATURAL_LABEL.get(ADM_LEVEL_MAP[adm_n], f"Level {ADM_LEVEL_MAP[adm_n]}")
        gb_bar_key = f"gb:adm{adm_n}"

        # Pre-compute the expected feature total for this level (sum
        # adm_unit_count across the country files we're about to process).
        # Per-iso COALESCE(...,1) so isos with a file on disk but no meta
        # CSV row (e.g. IND at ADM0 in the 2026-04 geoBoundaries release)
        # still contribute. Matches the pre-register query so both
        # surfaces show the same total.
        expected_total = 0
        try:
            _conn_total = get_connection()
            try:
                with get_cursor(_conn_total) as cur:
                    cur.execute(
                        """
                        SELECT COALESCE(SUM(COALESCE(m.adm_unit_count, 1)), 0)::bigint AS total
                        FROM   unnest(%s::text[]) AS iso_list(iso_code)
                        LEFT JOIN geoboundary_metadata m
                          ON m.iso_code  = iso_list.iso_code
                         AND m.adm_level = %s
                        """,
                        ([iso3 for iso3, _ in level_files], adm_n),
                    )
                    row = cur.fetchone()
                    expected_total = int(row["total"]) if row and row["total"] else 0
            finally:
                _conn_total.close()
        except Exception as exc:
            log.warning("Could not pre-compute ADM%d feature total (falling back "
                        "to country-file count): %s", adm_n, exc)

        # Belt-and-suspenders: ensure the bar has *some* nonzero total so the
        # progress fraction is well-defined even if the meta CSV is missing.
        if expected_total <= 0:
            expected_total = len(level_files)

        plural_label = NATURAL_LABEL_PLURAL.get(ADM_LEVEL_MAP[adm_n], adm_level_label)
        heartbeat.bar_start(
            key   = gb_bar_key,
            label = f"Boundaries — {plural_label}",
            total = expected_total,
            unit  = plural_label.lower(),
        )
        # P.1.2 resume-aware bar counter: seed with the count of rows
        # already in DB at this app adm_level for the country files
        # we're about to process. On `--resume`, this preloads the bar
        # with the work that the previous run completed. New inserts in
        # this run are then added on top by process_geojson_file's
        # per-feature `bar_update` and the outer per-country tally.
        # On `--fresh` this is always 0 (purge_geoboundaries_data emptied
        # the table just before this loop ran).
        app_level_seed = ADM_LEVEL_MAP[adm_n]
        bar_features_inserted = 0
        try:
            _conn_seed = get_connection()
            try:
                with get_cursor(_conn_seed) as cur:
                    cur.execute(
                        "SELECT COUNT(*) AS n FROM jurisdictions "
                        "WHERE adm_level = %s "
                        "AND iso_code = ANY(%s) AND deleted_at IS NULL",
                        (app_level_seed, [iso for iso, _ in level_files]),
                    )
                    row = cur.fetchone()
                    bar_features_inserted = int(row["n"]) if row else 0
            finally:
                _conn_seed.close()
        except Exception as exc:
            log.warning("Could not seed bar from existing DB rows (treating as 0): %s", exc)
            bar_features_inserted = 0
        # Snap the bar to the seeded baseline so the operator sees the
        # in-DB count immediately on resume instead of watching it tick
        # up from zero through 26 k features that already exist.
        if bar_features_inserted > 0:
            heartbeat.bar_update(
                gb_bar_key,
                min(bar_features_inserted, expected_total),
                force=True,
            )
            log.info("ADM%d bar seeded from DB at %d / %d (resume)",
                     adm_n, bar_features_inserted, expected_total)

        for idx, (iso3, geojson_path) in enumerate(level_files):
            # Supplementary metadata from CSV — empty dict if not present
            meta_row = meta_index.get((iso3, adm_n), {})

            # Heartbeat: let the UI know what's being loaded right now.
            queue_preview = [nxt for nxt, _ in level_files[idx + 1 : idx + 3]]
            adm_level_app = ADM_LEVEL_MAP[adm_n]
            level_label   = NATURAL_LABEL.get(adm_level_app, f"Level {adm_level_app}")
            n_total       = len(level_files)

            # Phase P.2: resolve the country's adm_level=1 row id so the
            # MiniMap stays iso-locked (same country shown for all of its
            # ADM-N files, doesn't flicker per sub-jurisdiction). For
            # adm_n=0, the country row hasn't been inserted yet, so id
            # stays None and the preview shows "Preparing…" until ADM1
            # starts. For adm_n >= 1, the country row exists from the
            # earlier ADM0 pass.
            country_jur_id = None
            if adm_n >= 1:
                try:
                    _conn_hb = get_connection()
                    try:
                        with get_cursor(_conn_hb) as cur:
                            cur.execute(
                                """
                                SELECT id::text AS id FROM jurisdictions
                                WHERE iso_code = %s AND adm_level = 1
                                  AND deleted_at IS NULL
                                LIMIT 1
                                """,
                                (iso3,),
                            )
                            row = cur.fetchone()
                            if row:
                                country_jur_id = row["id"]
                    finally:
                        _conn_hb.close()
                except Exception:
                    country_jur_id = None

            # Per-country (outer) heartbeat carries country-of-N progress so
            # the bar renders even during fast ADM0/ADM1 passes where each
            # file has 1–N rows and the inner per-batch heartbeat never fires.
            # Inner per-batch heartbeats from process_geojson_file will
            # override this with row-level progress for big files (e.g. India
            # ADM5 has hundreds of thousands of rows in one file). When that
            # happens, sub_phase changes from "<Level>" to "<Level> inserting
            # X of Y", which trips the frontend's rate-buffer reset.
            heartbeat.write_current(
                id               = country_jur_id,
                name             = iso3,
                iso_code         = iso3,
                adm_level        = adm_level_app,
                phase            = "geoboundaries",
                # P.1.2: this is the *country-file* iteration index across
                # the current ADM level. Use "Country" (singular noun for
                # the unit being counted — country files), not the level's
                # natural label ("County 78 of 180" was misleading: the 78
                # is countries-with-counties processed so far, not a count
                # of counties themselves).
                sub_phase        = f"Country {idx + 1:,} of {n_total:,}",
                queue_preview    = queue_preview,
                progress_current = idx + 1,
                progress_total   = n_total,
            )

            # process_geojson_file manages its own connection internally —
            # the connection is opened AFTER file parsing so it is never idle
            # during slow GeoJSON/Shapefile reads (CAN, RUS, USA can take mins).
            #
            # The retry loop wraps the call so a "Retry" decision from the
            # operator re-runs the same country without recursion.
            while True:
                try:
                    inserted_ids = process_geojson_file(
                        geojson_path             = geojson_path,
                        iso3                     = iso3,
                        adm_n                    = adm_n,
                        meta_row                 = meta_row,
                        parent_map               = parent_map,
                        earth_uuid               = earth_uuid,
                        existing_slugs           = existing_slugs,
                        progress                 = progress,
                        log                      = log,
                        heartbeat_queue_preview  = queue_preview,
                        heartbeat_country_jur_id = country_jur_id,   # P.2 — keeps minimap iso-locked
                        # P.1.1: pass bar context so inner-batch heartbeat
                        # advances the ADM bar mid-country (huge multi-vertex
                        # countries like CAN/RUS/USA/CHN at ADM1+ otherwise
                        # leave the bar stuck for minutes).
                        bar_key                  = gb_bar_key,
                        bar_baseline             = bar_features_inserted,
                        bar_cap                  = expected_total,
                    )
                    total_inserted        += len(inserted_ids)
                    bar_features_inserted += len(inserted_ids)
                    # Flush progress.json after each country so the wizard's
                    # countries-done tally tracks real-time, not just at the
                    # end of Phase 1.
                    if save_progress_fn:
                        save_progress_fn(progress)
                    # Phase P.1.1: force-write the per-country final tally so
                    # the bar's persisted value catches up after any throttled
                    # per-feature ticks. Cap at expected_total so a slight
                    # under/over-estimate from the meta CSV doesn't surface
                    # as >100% mid-run.
                    heartbeat.bar_update(
                        gb_bar_key,
                        min(bar_features_inserted, expected_total),
                        force=True,
                    )
                    break  # success — move to next country
                except Exception as exc:
                    progress_key = f"{iso3}-ADM{adm_n}"
                    log.error("Unhandled error processing %s: %s", progress_key, exc, exc_info=True)
                    progress.setdefault("geoboundaries", {})[progress_key] = {
                        "status":    "error",
                        "error":     str(exc),
                        "timestamp": datetime.now(timezone.utc).isoformat(),
                    }

                    if pause_on_exception:
                        from error_pause import wait_for_error_decision
                        decision = wait_for_error_decision(
                            country   = iso3,
                            adm_level = ADM_LEVEL_MAP.get(adm_n, adm_n + 1),
                            phase     = "geoboundaries",
                            exception = exc,
                            log       = log,
                        )
                        if decision == "abort":
                            log.warning("Operator aborted the run from error pause.")
                            raise SystemExit(2)
                        elif decision == "retry":
                            log.info("Retrying %s on operator request…", progress_key)
                            continue   # loop back, re-run process_geojson_file
                        else:  # skip
                            progress["geoboundaries"][progress_key]["status"] = "skipped"
                            log.info("Skipping %s on operator request.", progress_key)
                            break
                    else:
                        # Legacy behaviour — log & continue to the next country.
                        break

        # All countries for this ADM level have been processed. The cleanup
        # (mid-loop synthesis + orphan-resolve) runs next and is folded INTO
        # this bar's elapsed time — bar_complete fires once at the bottom of
        # the cleanup block, not here. Phase P.1.1 removed the separate
        # cleanup bar since synthesis is typically < 1s and resolve < 30s,
        # not worth a row of its own.

        # Phase O: mid-loop synthesis + re-resolve.
        #
        # After all per-iso files for this ADM level finish, check whether any
        # iso whose deepest level is now this level needs a synthetic country
        # row. If so, insert it AND re-resolve orphans at this level so the
        # newly-synthesised parent unblocks the rows we just inserted.
        #
        # Why mid-loop instead of only end-of-Phase-1: PRI imports its first
        # features at gb-ADM2 (app adm_level=3). Inline parent lookup for those
        # features fails because PRI has no shallower rows yet → 78 inline
        # orphan warnings. After the gb-ADM2 level completes, we can synthesise
        # PRI's adm_level=1 row from the just-imported ADM2 features, then
        # re-resolve the orphans → they find synthetic PRI-1 as parent
        # (skip_ancestor) before the next ADM level even starts.
        #
        # Generalised — uses MIN(adm_level)>1 query, no curated iso list.
        # Operates on whatever ISOs the data shows that need it. Idempotent
        # via ON CONFLICT (slug) DO NOTHING in synthesize_missing_country_rows.
        #
        # Phase P.1.1: this work runs inside the ADM bar's lifecycle (no
        # separate cleanup bar). bar_complete fires AFTER the cleanup, so the
        # ADM bar's elapsed time covers both inserts and post-cleanup. The
        # cleanup is fast enough (typically seconds) that the bar's percentage
        # display stays at 100% throughout.
        try:
            _conn = get_connection()
            try:
                synthesised = synthesize_missing_country_rows(_conn, earth_uuid, log)
                _conn.commit()
                if synthesised > 0:
                    log.info("Mid-Phase-1 synthesis after gb-ADM%d: %d new country rows",
                             adm_n, synthesised)
                    # Re-resolve orphans at this level only — newly-synthesised
                    # parents may unblock them. Three-strategy ladder so we
                    # get the same coverage as the end-of-pass post-pass.
                    app_level = ADM_LEVEL_MAP[adm_n]
                    n_d, n_s = _resolve_orphans_at_level_via_strategy(
                        _conn, app_level, "exact")
                    _conn.commit()
                    n_b, _   = _resolve_orphans_at_level_via_strategy(
                        _conn, app_level, "buffered")
                    _conn.commit()
                    n_t, n_x = _resolve_orphans_at_level_via_strategy(
                        _conn, app_level, "topological")
                    _conn.commit()
                    if (n_d + n_s + n_b + n_t + n_x) > 0:
                        log.info(
                            "Mid-Phase-1 re-resolve at level %d: "
                            "%d direct + %d skip + %d buffered + %d topological "
                            "+ %d cross_iso_topological",
                            app_level, n_d, n_s, n_b, n_t, n_x
                        )
            finally:
                _conn.close()
        except Exception as mid_exc:
            log.warning("Mid-loop synthesis/re-resolve after gb-ADM%d failed: %s",
                        adm_n, mid_exc)

        # bar_complete pins the final value to the expected total — clean
        # 100% display regardless of how the running counter ended (over or
        # under estimate from the meta CSV).
        heartbeat.bar_complete(gb_bar_key, current=expected_total)

    log.info("import_geoboundaries: %d total jurisdictions inserted (pre-post-pass)", total_inserted)

    # ── Phase J post-pass: synthesize missing country rows + re-resolve orphans
    # via the strategy ladder. Runs once at the end of the full import (not
    # per-iso) so it sees the complete DB state. Idempotent — safe to re-run.
    # With Phase O's mid-loop synthesis, this should normally be mostly a
    # no-op for synthesised + same-iso cases; the heavy lifter here is the
    # 'topological' strategy which catches cross-iso ancestry mismatches that
    # only become resolvable once ALL countries' hierarchies are in the DB
    # (e.g. FRA features looking for GUF parents).
    #
    # Phase P.1.1: no separate cleanup bar — the post-pass runs silently
    # between the final ADM bar completing and Phase 2 (WorldPop) starting.
    # Counts surface via log / event stream; typical world-scale wall time
    # is well under a minute.
    _conn = get_connection()
    try:
        post_counts = post_pass_orphan_resolution(_conn, earth_uuid, log)
        progress["geoboundaries_post_pass"] = {
            "synthesized":           post_counts["synthesized"],
            "direct":                post_counts["direct"],
            "skip_ancestor":         post_counts["skip_ancestor"],
            "buffered":              post_counts["buffered"],
            "topological":           post_counts["topological"],
            "cross_iso_topological": post_counts["cross_iso_topological"],
            "residual":              post_counts["residual"],
            "timestamp":             datetime.now(timezone.utc).isoformat(),
        }
    finally:
        _conn.close()

    log.info("import_geoboundaries complete: %d total jurisdictions, %d residual orphans after post-pass",
             total_inserted, post_counts["residual"])

    # P.3: surface the post-pass summary as a UI event so the operator can see
    # the cleanup outcome without scrolling the log.
    heartbeat.emit_event(
        level="info" if post_counts["residual"] == 0 else "warn",
        type="post_pass_summary",
        phase="cleanup",
        synthesized           = post_counts["synthesized"],
        direct                = post_counts["direct"],
        skip_ancestor         = post_counts["skip_ancestor"],
        buffered              = post_counts["buffered"],
        topological           = post_counts["topological"],
        cross_iso_topological = post_counts["cross_iso_topological"],
        residual              = post_counts["residual"],
        msg = (
            f"Post-pass: {post_counts['residual']} residual orphans "
            f"after {post_counts['cross_iso_topological']} cross-iso "
            f"+ {post_counts['buffered']} buffered + "
            f"{post_counts['topological']} topological rescues"
        ),
    )
    return total_inserted
