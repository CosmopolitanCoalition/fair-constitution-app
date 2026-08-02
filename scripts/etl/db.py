"""
db.py — Shared database connection and bulk insert helpers.

All ETL scripts import from here. Connection parameters are read from
environment variables injected by docker-compose.yml.
"""

import os
import logging
from contextlib import contextmanager

import psycopg2
import psycopg2.extras

logger = logging.getLogger(__name__)

# ─── Connection config (from docker-compose environment) ─────────────────────

DB_CONFIG = {
    "host":     os.environ.get("DB_HOST",     "postgres"),
    "port":     int(os.environ.get("DB_PORT", 5432)),
    "dbname":   os.environ.get("DB_NAME",     "fair_constitution"),
    "user":     os.environ.get("DB_USER",     "fc_user"),
    "password": os.environ.get("DB_PASSWORD", "fc_password"),
    # Force UTF-8 on the wire (defensive). The default already resolves to UTF8
    # in our containers, but pinning it removes any dependence on the container
    # locale so non-Latin names always round-trip cleanly into the UTF-8 columns.
    # NOTE: the observed Iran '?' / Madeira mojibake names are NOT introduced
    # here — they are corrupted in the geoBoundaries *source* GeoJSON itself
    # (verified). The reversible mojibake is un-mangled in import_geoboundaries.py
    # (_demojibake); the lossy '?' Persian names are unrecoverable from source.
    "client_encoding":     "UTF8",
    # TCP keepalives — prevent PostgreSQL from dropping idle connections
    # during long per-country processing (CAN, RUS, USA can take minutes).
    "keepalives":          1,
    "keepalives_idle":     30,   # send keepalive probe after 30s idle
    "keepalives_interval": 10,   # retry probe every 10s
    "keepalives_count":    5,    # give up after 5 unanswered probes
}


def get_connection() -> psycopg2.extensions.connection:
    """
    Return an open psycopg2 connection.
    Caller is responsible for calling conn.close().
    """
    return psycopg2.connect(**DB_CONFIG)


@contextmanager
def get_cursor(conn: psycopg2.extensions.connection):
    """
    Context manager yielding a DictCursor.
    Commits the transaction on clean exit; rolls back on any exception.
    """
    cur = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)
    try:
        yield cur
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        cur.close()


# ─── Jurisdiction bulk insert ─────────────────────────────────────────────────

# Columns must match the template below exactly.
#
# SINGLE-PARSE, NOW REALLY (2026-08-02, the AUS-ADM0 kill-loop): the first
# "single-parse" fix put the pipeline in a LATERAL and referenced g.geom
# twice (geom + ST_Centroid(g.geom)). PROVEN INSUFFICIENT by EXPLAIN VERBOSE:
# the planner INLINES an un-fenced LATERAL subquery, substituting the full
# parse+MakeValid pipeline at BOTH references — with execute_values shipping
# literals, constant-folding evaluated the monster pipeline TWICE at plan
# time (AUS ADM0: 64 MB feature → 3.88 GB backend, kernel-killed; ~2× the
# single cost, exactly the legacy double-parse it was meant to remove). The
# OFFSET 0 is the optimizer fence: the plan becomes a Subquery Scan whose
# inner Result computes the pipeline ONCE; ST_Centroid then references the
# computed var. Verified in the plan output both ways. Do not remove it.
#
# WKB-FIRST (same dig): rows arrive with geom_wkb_hex whenever the importer
# could encode the geometry client-side (all (Multi)Polygons — in practice
# everything). decode(hex)+ST_GeomFromWKB is a near-1x transient; the
# ST_GeomFromGeoJSON branch (json-c tree, ~10-30x the text for coordinate-
# dense JSON — the other half of the 3.88 GB) remains only as the fallback
# for non-polygonal/unconvertible geometries. CASE evaluates one branch.
_JURISDICTION_INSERT_SQL = """
    INSERT INTO jurisdictions (
        name,
        slug,
        iso_code,
        adm_level,
        parent_id,
        parent_assigned_via,
        source,
        geoboundaries_id,
        official_languages,
        timezone,
        geom,
        centroid,
        created_at,
        updated_at
    )
    SELECT
        v.name,
        v.slug,
        v.iso_code,
        v.adm_level::int,
        v.parent_id::uuid,
        v.parent_assigned_via,
        v.source,
        v.geoboundaries_id,
        v.official_languages::jsonb,
        v.timezone,
        g.geom,
        ST_Centroid(g.geom),
        NOW(),
        NOW()
    FROM (VALUES %s) AS v(
        name, slug, iso_code, adm_level, parent_id, parent_assigned_via,
        source, geoboundaries_id, official_languages, timezone,
        geom_geojson, geom_wkb_hex
    )
    CROSS JOIN LATERAL (
        SELECT ST_Multi(ST_MakeValid(ST_SetSRID(
                   CASE WHEN v.geom_wkb_hex IS NOT NULL
                        THEN ST_GeomFromWKB(decode(v.geom_wkb_hex, 'hex'))
                        ELSE ST_GeomFromGeoJSON(v.geom_geojson)
                   END, 4326))) AS geom
        OFFSET 0
    ) AS g
    ON CONFLICT (slug) DO NOTHING
    RETURNING id, slug
"""

# geom_geojson is a JSON text string — the raw GeoJSON `geometry` member from
# the source feature, passed through to PostgreSQL untouched. ST_GeomFromGeoJSON
# decodes it server-side; ST_MakeValid repairs topology; ST_Multi promotes
# POLYGON → MULTIPOLYGON for schema consistency. ST_SetSRID(..., 4326) is the
# explicit SRID since GeoJSON doesn't carry one (geoBoundaries publishes WGS84).
#
# Phase L (Native ingest): we no longer simplify or convert in Python. Every
# vertex from the source file is preserved. ST_MakeValid handles any topology
# defects server-side, replacing the role shapely played in the previous
# hex-WKB path. The byte-aware batching in import_geoboundaries.py keeps the
# total INSERT statement size below libpq's protocol max for the rare batches
# that contain multiple very large features (Canadian Arctic, Russia regions).
#
# Phase J: parent_assigned_via tracks which strategy resolved each row's
# parent. NULL for orphans + pre-J rows; 'direct'/'skip_ancestor'/'buffered'/
# 'synthetic_country' for resolved rows. See migration 2026_04_28_000003.
_JURISDICTION_TEMPLATE = """(
    %(name)s,
    %(slug)s,
    %(iso_code)s,
    %(adm_level)s,
    %(parent_id)s,
    %(parent_assigned_via)s,
    %(source)s,
    %(geoboundaries_id)s,
    %(official_languages)s,
    %(timezone)s,
    %(geom_geojson)s,
    %(geom_wkb_hex)s
)"""


_GIANT_LOCK_CACHE: list = []

# PG-side transient multipliers, field-calibrated 2026-08-02 (AUS ADM0:
# 64 MB GeoJSON → 3.88 GB backend under the doubled pipeline ⇒ ~30x per
# single evaluation; WKB hex decodes near-1x — statement text + bytea +
# geometry + MakeValid copy ≈ 4x the hex). These size the ESTIMATED
# backend transient an INSERT will cost, which is what exclusivity must
# key on — the old raw-payload trigger derived from the client flush
# ceiling (~4 MB on a sliced budget) and exclusive-convoyed the whole
# pool behind every mid-size feature.
_PG_COST_GEOJSON = 30
_PG_COST_WKB_HEX = 4


def _giant_lock_bytes() -> int:
    """ESTIMATED-TRANSIENT budget above which an insert runs EXCLUSIVE.
    Default 1 GiB — a monster's estimate (Nunavut-class WKB ≈ 200 MB hex
    × 4) crosses it; the mid-size field (< ~250 MB hex / ~34 MB geojson)
    stays shared at full pool width. CGA_ETL_GIANT_LOCK_BYTES overrides
    (same env var, now interpreted as the transient budget)."""
    if not _GIANT_LOCK_CACHE:
        env = os.environ.get("CGA_ETL_GIANT_LOCK_BYTES", "")
        if env.isdigit() and int(env) > 0:
            _GIANT_LOCK_CACHE.append(int(env))
        else:
            _GIANT_LOCK_CACHE.append(1024 * 1024 * 1024)
    return _GIANT_LOCK_CACHE[0]


def bulk_insert_jurisdictions(
    conn: psycopg2.extensions.connection,
    rows: list[dict],
) -> list[str]:
    """
    Bulk-insert a list of jurisdiction dicts and return inserted UUIDs.

    Each dict must contain:
        name            str
        slug            str  (unique URL-safe identifier)
        iso_code        str | None
        adm_level       int  (0=Earth, 1=National, 2=State…)
        parent_id       str | None  (UUID of parent jurisdiction)
        parent_assigned_via str | None (Phase J — strategy that resolved parent;
                              one of 'direct', 'skip_ancestor', 'buffered',
                              'synthetic_country', or None for orphans)
        source          str  (e.g. 'geoboundaries')
        geoboundaries_id str | None
        official_languages str  (JSON array string, e.g. '["en"]')
        timezone        str  (default 'UTC')
        geom_geojson    str  (raw GeoJSON `geometry` member as JSON text — passed
                              straight to PostgreSQL via ST_GeomFromGeoJSON; no
                              Python-side simplification or conversion. Phase L.)

    Returns:
        List of UUID strings for rows that were actually inserted
        (ON CONFLICT DO NOTHING means conflicts are silently skipped and
        their UUIDs are NOT returned — this is intentional for idempotency).

    Note: page_size is set very high so each call to this function produces
    one SQL statement, with the byte-aware batching in the caller setting the
    actual statement-size boundary. See import_geoboundaries.py.
    """
    if not rows:
        return []

    # Defensive: allow callers that haven't been Phase-J-updated to omit
    # parent_assigned_via — default to None and let the operator's review
    # surface flag the un-tracked rows. geom_wkb_hex defaults for callers
    # that still build geojson-only rows (global passes, synthetic Earth).
    for row in rows:
        row.setdefault("parent_assigned_via", None)
        row.setdefault("geom_wkb_hex", None)
        row.setdefault("geom_geojson", None)

    # THE GIANT GATE (2026-08-02, the two pg-backend OOM kills): chunking (the
    # Phase-L byte-aware batching) bounds PG's per-INSERT peak for NORMAL
    # batches, but "peak memory is bounded by the largest single feature" — a
    # Nunavut-class MultiPolygon is ONE row whose server-side
    # ST_GeomFromGeoJSON + ST_MakeValid transient runs 2–3 GB and cannot be
    # chunked smaller, only funded. The legacy single-threaded pipeline was
    # safe because it fed PG exactly ONE such transient at a time; with a
    # worker pool, two concurrent giants OOM-killed backends (2.4 GB + 2.0 GB
    # anon-rss, kernel log). This gate restores the legacy guarantee at the
    # exact grain: an OVERFLOW batch (payload above the caller's byte ceiling
    # — by the flush law, a single giant feature) takes a global advisory
    # xact lock, serializing ONLY the giants; every normal batch runs at
    # full pool width. The lock releases at commit.
    gj_bytes  = sum(len(r.get("geom_geojson") or "") for r in rows)
    wkb_bytes = sum(len(r.get("geom_wkb_hex") or "") for r in rows)
    est_transient = _PG_COST_GEOJSON * gj_bytes + _PG_COST_WKB_HEX * wkb_bytes

    with get_cursor(conn) as cur:
        # SHARED/EXCLUSIVE (operator, 2026-08-02 — "the single-threaded version
        # handled NZL, CAN, FRA, IND", on this same box): legacy's real
        # guarantee wasn't just one-giant-at-a-time, it was that a giant ran
        # ALONE — no other backend spending work_mem beside the 2–3 GB
        # transient. Kill #3 (3.0 GB backend, post-gate) proved serializing
        # giants against each other isn't enough. So: every NORMAL batch
        # holds the lock SHARED (full pool width, no contention among
        # themselves); a GIANT takes it EXCLUSIVE — it waits for in-flight
        # normal inserts to drain, runs as the ONLY insert in Postgres
        # (legacy conditions exactly), releases at commit, and the pool
        # resumes. The engine breathes: wide on the field, single-file past
        # the monsters.
        if est_transient > _giant_lock_bytes():
            logger.info(
                "giant-batch gate: %.1f MB payload (%.0f MB est. PG transient, "
                "%d row(s), %s) — EXCLUSIVE (insert runs alone)",
                (gj_bytes + wkb_bytes) / 1048576, est_transient / 1048576,
                len(rows), "wkb" if wkb_bytes else "geojson",
            )
            cur.execute("SELECT pg_advisory_xact_lock(hashtext('cga_giant_geom_insert'))")
        else:
            cur.execute("SELECT pg_advisory_xact_lock_shared(hashtext('cga_giant_geom_insert'))")
        result = psycopg2.extras.execute_values(
            cur,
            _JURISDICTION_INSERT_SQL,
            rows,
            template=_JURISDICTION_TEMPLATE,
            fetch=True,
            page_size=len(rows),  # one statement per call; caller controls byte-size
        )

    inserted_ids = [row["id"] for row in result]
    logger.debug("bulk_insert_jurisdictions: inserted %d / %d rows", len(inserted_ids), len(rows))
    return inserted_ids


# ─── constitutional_settings bulk insert ─────────────────────────────────────

_SETTINGS_INSERT_SQL = """
    INSERT INTO constitutional_settings (jurisdiction_id, created_at, updated_at)
    VALUES (%s, NOW(), NOW())
    ON CONFLICT (jurisdiction_id) DO NOTHING
"""


def bulk_insert_constitutional_settings(
    conn: psycopg2.extensions.connection,
    jurisdiction_ids: list[str],
) -> int:
    """
    Insert a default constitutional_settings row for each jurisdiction UUID.

    All column values use the database-side defaults defined in the migration.
    ON CONFLICT DO NOTHING makes this fully idempotent.

    Returns:
        Number of rows actually inserted.
    """
    if not jurisdiction_ids:
        return 0

    inserted = 0
    with get_cursor(conn) as cur:
        for jid in jurisdiction_ids:
            cur.execute(_SETTINGS_INSERT_SQL, (jid,))
            inserted += cur.rowcount

    logger.debug("bulk_insert_constitutional_settings: inserted %d rows", inserted)
    return inserted


# ─── Population bulk update ───────────────────────────────────────────────────

_POPULATION_UPDATE_SQL = """
    UPDATE jurisdictions AS j
    SET
        population      = v.population::bigint,
        population_year = 2023,
        updated_at      = NOW()
    FROM (VALUES %s) AS v(id, population)
    WHERE j.id = v.id::uuid
    RETURNING j.id
"""


def bulk_update_populations(
    conn: psycopg2.extensions.connection,
    population_map: dict[str, int],
) -> int:
    """
    Bulk-update the population column for a dict of {jurisdiction_uuid: count}.

    Uses a single VALUES-based UPDATE rather than N individual statements.
    population_year is hard-coded to 2023 (WorldPop 2023 dataset).

    Returns:
        Number of rows updated.
    """
    if not population_map:
        return 0

    rows = list(population_map.items())  # [(uuid, count), ...]

    with get_cursor(conn) as cur:
        result = psycopg2.extras.execute_values(
            cur,
            _POPULATION_UPDATE_SQL,
            rows,
            template="(%s, %s)",
            page_size=1000,
            fetch=True,        # accumulates RETURNING rows across all pages
        )
        updated = len(result)  # true count regardless of page_size splitting

    logger.debug("bulk_update_populations: updated %d rows", updated)
    return updated
