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
    VALUES %s
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
    %(official_languages)s::jsonb,
    %(timezone)s,
    ST_Multi(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(%(geom_geojson)s), 4326))),
    ST_Centroid(ST_Multi(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(%(geom_geojson)s), 4326)))),
    NOW(),
    NOW()
)"""


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
    # surface flag the un-tracked rows.
    for row in rows:
        row.setdefault("parent_assigned_via", None)

    with get_cursor(conn) as cur:
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
