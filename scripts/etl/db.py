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

# geom_ewkb is a hex-encoded EWKB string produced by shapely's .wkb_hex.
# Binary transfer is ~3× smaller than WKT text, which matters for huge
# geometries like Canada/Russia province-level boundaries (50–200 MB as WKT).
# ST_Multi()     → promotes POLYGON → MULTIPOLYGON
# ST_MakeValid() → repairs broken ring closures / self-intersections
# ST_Centroid()  → computed server-side from the repaired geometry
_JURISDICTION_TEMPLATE = """(
    %(name)s,
    %(slug)s,
    %(iso_code)s,
    %(adm_level)s,
    %(parent_id)s,
    %(source)s,
    %(geoboundaries_id)s,
    %(official_languages)s::jsonb,
    %(timezone)s,
    ST_Multi(ST_MakeValid(ST_SetSRID(decode(%(geom_ewkb)s, 'hex')::geometry, 4326))),
    ST_Centroid(ST_Multi(ST_MakeValid(ST_SetSRID(decode(%(geom_ewkb)s, 'hex')::geometry, 4326)))),
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
        source          str  (e.g. 'geoboundaries')
        geoboundaries_id str | None
        official_languages str  (JSON array string, e.g. '["en"]')
        timezone        str  (default 'UTC')
        geom_ewkb       str  (hex-encoded WKB from shapely .wkb_hex; binary is
                              ~3× smaller than WKT — critical for large geometries)

    Returns:
        List of UUID strings for rows that were actually inserted
        (ON CONFLICT DO NOTHING means conflicts are silently skipped and
        their UUIDs are NOT returned — this is intentional for idempotency).
    """
    if not rows:
        return []

    with get_cursor(conn) as cur:
        result = psycopg2.extras.execute_values(
            cur,
            _JURISDICTION_INSERT_SQL,
            rows,
            template=_JURISDICTION_TEMPLATE,
            fetch=True,
            page_size=50,
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
