#!/usr/bin/env python3
"""
fix_orphans.py — Re-chain jurisdictions with parent_id IS NULL that should have a parent.

Run: docker compose exec etl python fix_orphans.py

Three categories handled:
  1. Standard orphans  — direct parent level exists in DB; ST_Intersects just
                         failed at import time (island enclaves, boundary gaps).
                         Fix: spatial lookup then nearest-centroid fallback.

  2. Level-gap orphans — intermediate ADM levels absent from geoBoundaries source
                         (French overseas territories, US territories, CAF ADM5 gap).
                         Fix: chain to best available ancestor level (nearest below).

  3. PRI special case  — Puerto Rico has NO adm_level=1 row in geoBoundaries at all.
                         Fix: create synthetic country row from ST_Union of ADM2 children,
                         then re-chain ADM2+ADM3 to it.

Results per group are logged. No --fresh risk: all UPDATEs are idempotent.
"""

import logging
import sys
from datetime import datetime, timezone
from db import get_connection, get_cursor

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
    handlers=[logging.StreamHandler(sys.stdout)],
)
log = logging.getLogger("fix_orphans")


# ─────────────────────────────────────────────────────────────────────────────
# Helpers
# ─────────────────────────────────────────────────────────────────────────────

def get_orphan_groups(conn):
    """Return list of (iso_code, adm_level, orphan_count) for all orphaned rows."""
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT iso_code, adm_level, COUNT(*) AS cnt
            FROM jurisdictions
            WHERE parent_id IS NULL
              AND adm_level > 1
              AND source IN ('geoboundaries', 'synthetic')
            GROUP BY iso_code, adm_level
            ORDER BY iso_code, adm_level
        """)
        return cur.fetchall()


def get_available_levels(conn, iso_code):
    """Return sorted list of all adm_levels present in DB for iso_code."""
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT DISTINCT adm_level
            FROM jurisdictions
            WHERE iso_code = %s
            ORDER BY adm_level
        """, (iso_code,))
        return [r[0] for r in cur.fetchall()]


def find_best_parent_level(orphan_level, available_levels):
    """
    Return the highest level in available_levels that is < orphan_level.
    Returns None if no such level exists.
    """
    candidates = [lvl for lvl in available_levels if lvl < orphan_level]
    return max(candidates) if candidates else None


# ─────────────────────────────────────────────────────────────────────────────
# PRI special case: create synthetic country row
# ─────────────────────────────────────────────────────────────────────────────

def create_synthetic_pri_country(conn):
    """
    Create a synthetic adm_level=1 row for Puerto Rico by taking the union
    of its adm_level=3 municipality geometries.  No-op if the row already exists.
    """
    with get_cursor(conn) as cur:
        cur.execute(
            "SELECT id FROM jurisdictions WHERE iso_code = 'PRI' AND adm_level = 1"
        )
        if cur.fetchone():
            log.info("PRI adm_level=1 already exists — skipping creation")
            return

        cur.execute("SELECT id FROM jurisdictions WHERE adm_level = 0 LIMIT 1")
        row = cur.fetchone()
        if not row:
            log.warning("Earth (adm_level=0) not found — cannot create PRI country row")
            return
        earth_id = row[0]

        cur.execute("""
            INSERT INTO jurisdictions (
                name, slug, iso_code, adm_level, parent_id, source,
                official_languages, geom, centroid, created_at, updated_at
            )
            SELECT
                'Puerto Rico',
                'pri-1-puerto-rico',
                'PRI',
                1,
                %s,
                'synthetic',
                '["es","en"]',
                ST_Multi(ST_MakeValid(ST_Union(geom))),
                ST_Centroid(ST_Union(geom)),
                NOW(),
                NOW()
            FROM jurisdictions
            WHERE iso_code = 'PRI' AND adm_level = 3
            ON CONFLICT (slug) DO NOTHING
            RETURNING id
        """, (earth_id,))
        result = cur.fetchone()
        if result:
            log.info("Created synthetic PRI adm_level=1: %s", result[0])
            cur.execute("""
                INSERT INTO constitutional_settings (jurisdiction_id, created_at, updated_at)
                VALUES (%s, NOW(), NOW())
                ON CONFLICT (jurisdiction_id) DO NOTHING
            """, (result[0],))
        else:
            log.warning("PRI synthetic row INSERT returned nothing (conflict on slug?)")


# ─────────────────────────────────────────────────────────────────────────────
# Re-chain one (iso_code, adm_level) batch
# ─────────────────────────────────────────────────────────────────────────────

def fix_batch(conn, iso_code, orphan_level, parent_level):
    """
    Fix orphans in (iso_code, orphan_level) by assigning parent from parent_level.

    Strategy 1 — ST_Intersects, pick largest overlap area.
    Strategy 2 — nearest centroid (handles island territories that don't overlap
                 any parent polygon).

    Returns (spatial_fixed, centroid_fixed, still_failed).
    """

    # ── Strategy 1: Spatial intersection ──────────────────────────────────
    with get_cursor(conn) as cur:
        cur.execute("""
            WITH orphans AS (
                SELECT id, geom
                FROM jurisdictions
                WHERE iso_code    = %(iso)s
                  AND adm_level   = %(olevel)s
                  AND parent_id IS NULL
            ),
            best_parent AS (
                SELECT DISTINCT ON (o.id)
                    o.id                                  AS orphan_id,
                    p.id                                  AS parent_id,
                    ST_Area(ST_Intersection(p.geom, o.geom)) AS overlap_area
                FROM orphans o
                JOIN jurisdictions p ON (
                    p.iso_code  = %(iso)s
                    AND p.adm_level = %(plevel)s
                    AND ST_Intersects(p.geom, o.geom)
                )
                ORDER BY o.id,
                         ST_Area(ST_Intersection(p.geom, o.geom)) DESC
            )
            UPDATE jurisdictions j
            SET    parent_id  = bp.parent_id,
                   updated_at = NOW()
            FROM   best_parent bp
            WHERE  j.id = bp.orphan_id
        """, {"iso": iso_code, "olevel": orphan_level, "plevel": parent_level})
        spatial_fixed = cur.rowcount

    # ── Strategy 2: Nearest centroid fallback ─────────────────────────────
    with get_cursor(conn) as cur:
        cur.execute("""
            WITH orphans AS (
                SELECT id, centroid
                FROM jurisdictions
                WHERE iso_code    = %(iso)s
                  AND adm_level   = %(olevel)s
                  AND parent_id IS NULL
                  AND centroid IS NOT NULL
            ),
            nearest AS (
                SELECT DISTINCT ON (o.id)
                    o.id  AS orphan_id,
                    p.id  AS parent_id
                FROM orphans o
                JOIN jurisdictions p ON (
                    p.iso_code  = %(iso)s
                    AND p.adm_level = %(plevel)s
                    AND p.centroid IS NOT NULL
                )
                ORDER BY o.id,
                         ST_Distance(o.centroid, p.centroid) ASC
            )
            UPDATE jurisdictions j
            SET    parent_id  = n.parent_id,
                   updated_at = NOW()
            FROM   nearest n
            WHERE  j.id = n.orphan_id
        """, {"iso": iso_code, "olevel": orphan_level, "plevel": parent_level})
        centroid_fixed = cur.rowcount

    # ── Count remaining failures ───────────────────────────────────────────
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT COUNT(*) FROM jurisdictions
            WHERE iso_code  = %s
              AND adm_level = %s
              AND parent_id IS NULL
        """, (iso_code, orphan_level))
        still_failed = cur.fetchone()[0]

    return spatial_fixed, centroid_fixed, still_failed


# ─────────────────────────────────────────────────────────────────────────────
# Main
# ─────────────────────────────────────────────────────────────────────────────

def main():
    log.info("=== fix_orphans.py starting ===")
    start = datetime.now(timezone.utc)

    # ── 0. PRI synthetic country row ──────────────────────────────────────
    log.info("Step 0: PRI special case")
    conn = get_connection()
    create_synthetic_pri_country(conn)
    conn.close()

    # ── 1. Collect all orphan groups ─────────────────────────────────────
    conn = get_connection()
    groups = get_orphan_groups(conn)
    conn.close()

    n_countries  = len(set(g[0] for g in groups))
    total_orphans = sum(g[2] for g in groups)
    log.info(
        "Found %d orphan groups across %d countries — %d total orphaned rows",
        len(groups), n_countries, total_orphans,
    )

    # ── 2. Re-chain each group ────────────────────────────────────────────
    grand_spatial  = 0
    grand_centroid = 0
    grand_failed   = 0
    rows = []  # for summary table

    for iso_code, orphan_level, orphan_count in groups:
        conn = get_connection()
        avail = get_available_levels(conn, iso_code)
        conn.close()

        parent_level = find_best_parent_level(orphan_level, avail)

        if parent_level is None:
            log.warning(
                "%s adm_level=%d: no ancestor level available, "
                "leaving %d orphans unlinked",
                iso_code, orphan_level, orphan_count,
            )
            grand_failed += orphan_count
            rows.append((iso_code, orphan_level, orphan_count, 0, 0, orphan_count, "NO ANCESTOR"))
            continue

        gap = orphan_level - parent_level
        strategy_note = (
            "direct parent"
            if gap == 1
            else f"level gap → chaining to level {parent_level} (gap={gap})"
        )

        conn = get_connection()
        spatial, centroid, failed = fix_batch(conn, iso_code, orphan_level, parent_level)
        conn.close()

        total_fixed = spatial + centroid
        grand_spatial  += spatial
        grand_centroid += centroid
        grand_failed   += failed
        rows.append((iso_code, orphan_level, orphan_count, spatial, centroid, failed, strategy_note))

        log.info(
            "%-4s adm=%d (%3d orphans): spatial=%d  centroid=%d  failed=%d  [%s]",
            iso_code, orphan_level, orphan_count, spatial, centroid, failed, strategy_note,
        )

    # ── 3. Summary table ──────────────────────────────────────────────────
    elapsed = (datetime.now(timezone.utc) - start).total_seconds()
    log.info("")
    log.info("=" * 70)
    log.info("SUMMARY  (%d groups, %.1fs)", len(groups), elapsed)
    log.info("%-4s  %5s  %7s  %7s  %8s  %6s  %s",
             "ISO", "Level", "Orphans", "Spatial", "Centroid", "Failed", "Strategy")
    log.info("-" * 70)
    for iso, lvl, total, sp, ct, fa, note in rows:
        log.info("%-4s  %5d  %7d  %7d  %8d  %6d  %s", iso, lvl, total, sp, ct, fa, note)
    log.info("-" * 70)
    log.info("TOTAL         %7d  %7d  %8d  %6d",
             total_orphans, grand_spatial, grand_centroid, grand_failed)
    log.info("=" * 70)

    if grand_failed > 0:
        log.warning("%d jurisdictions still have parent_id IS NULL — manual review needed", grand_failed)
    else:
        log.info("All orphans resolved successfully.")

    return 0 if grand_failed == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
