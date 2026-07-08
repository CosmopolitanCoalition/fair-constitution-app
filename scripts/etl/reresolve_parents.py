#!/usr/bin/env python3
"""
reresolve_parents.py — Clear + re-derive the ENTIRE jurisdiction parent hierarchy.

Run: docker compose exec etl python reresolve_parents.py [--dry-run]

WHAT IT DOES
------------
Thin driver over the strategy ladder that already lives in
import_geoboundaries.post_pass_orphan_resolution(). Lineage in this schema is
just parent_id + parent_assigned_via (there is no ancestry cache), so a full
re-derive is cheap:

  1. Clear:      parent_id = NULL, parent_assigned_via = NULL on every row with
                 adm_level > 0 (soft-deleted rows untouched).
  2. Re-anchor:  ALL adm_level = 1 rows — including source='synthetic' ones like
                 the PRI country row — back to Earth (the single adm_level=0
                 row) with parent_assigned_via='direct'.
  3. Re-derive:  import_geoboundaries.post_pass_orphan_resolution() walks
                 levels 2..6 through the ladder (direct_intersect → exact →
                 buffered → topological) and then Phase S converts cross-iso
                 parents to same-iso ones, synthesising intermediaries where
                 needed.
  4. Report:     before/after orphan counts + parent_assigned_via histogram.

WHY STEP 2 (adm_level=1 → Earth) IS MANDATORY — THE TRAP
--------------------------------------------------------
The strategy ladder only processes levels 2..6, and every strategy matches by
GEOMETRY. Earth has geom = NULL, so nothing can ever spatially re-derive a
country's parent: if step 1 nulls adm_level=1 rows and step 2 is skipped,
EVERY country on the planet stays orphaned forever (and the wizard's counts,
ancestor sweeps and roll-ups all break). Re-anchoring level 1 to Earth is not
an optimisation — it is the only way those rows get a parent back. Synthetic
country rows are re-anchored too (never deleted): deeper levels re-chain to
them in step 3 exactly as they did at import time.

SAFETY / IDEMPOTENCY
--------------------
* Touches ONLY parent_id / parent_assigned_via / updated_at. Population, geom,
  centroid and every other column are never written; no row is ever DELETEd.
* Idempotent: the derivation is a pure function of the stored geometries, so
  re-running produces the same linkage. Steps 1+2 run on one plain cursor with
  no intermediate commit; the final commit is explicit and any exception rolls
  the connection back. Caveat: post_pass_orphan_resolution() commits internally
  between strategy passes (its own design), so a crash INSIDE step 3 can leave
  a partially re-derived hierarchy — simply re-run this script; step 1 clears
  everything and the re-derive starts clean.
* --dry-run prints the planned actions plus the current orphan and
  parent_assigned_via histograms and writes nothing.
"""

import argparse
import logging
import sys
from datetime import datetime, timezone

import psycopg2.extras

from db import get_connection
from import_geoboundaries import post_pass_orphan_resolution

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
    handlers=[logging.StreamHandler(sys.stdout)],
)
log = logging.getLogger("reresolve_parents")


# ─────────────────────────────────────────────────────────────────────────────
# Read-only state snapshots
# ─────────────────────────────────────────────────────────────────────────────
# NB: these take a cursor, not the connection — db.get_cursor commits on clean
# exit, which would break the single-transaction guarantee of the write phase,
# so this script drives one plain DictCursor itself.

def get_earth_id(cur) -> str:
    """Return the UUID of the single Earth (adm_level=0) row, or raise."""
    cur.execute(
        "SELECT id FROM jurisdictions "
        "WHERE adm_level = 0 AND deleted_at IS NULL"
    )
    rows = cur.fetchall()
    if len(rows) != 1:
        raise RuntimeError(
            f"expected exactly 1 Earth (adm_level=0) row, found {len(rows)} — "
            "run the seeder first / inspect the jurisdictions table"
        )
    return str(rows[0]["id"])


def orphan_counts_by_level(cur) -> dict[int, int]:
    """{adm_level: orphan_count} for rows with parent_id IS NULL above Earth."""
    cur.execute(
        "SELECT adm_level, COUNT(*) AS cnt FROM jurisdictions "
        "WHERE parent_id IS NULL AND adm_level > 0 AND deleted_at IS NULL "
        "GROUP BY adm_level ORDER BY adm_level"
    )
    return {r["adm_level"]: r["cnt"] for r in cur.fetchall()}


def via_histogram(cur) -> dict[str, int]:
    """{parent_assigned_via: count} over all live rows above Earth."""
    cur.execute(
        "SELECT COALESCE(parent_assigned_via, '(null)') AS via, COUNT(*) AS cnt "
        "FROM jurisdictions "
        "WHERE adm_level > 0 AND deleted_at IS NULL "
        "GROUP BY 1 ORDER BY cnt DESC"
    )
    return {r["via"]: r["cnt"] for r in cur.fetchall()}


def log_state(cur, label: str) -> int:
    """Log the orphan-by-level + strategy histograms. Returns total orphans."""
    orphans = orphan_counts_by_level(cur)
    total = sum(orphans.values())
    log.info("%s: %d orphan(s)%s", label, total,
             " — " + ", ".join(f"level {lvl}: {cnt}"
                               for lvl, cnt in orphans.items()) if orphans else "")
    log.info("%s parent_assigned_via histogram:", label)
    for via, cnt in via_histogram(cur).items():
        log.info("  %-24s %8d", via, cnt)
    return total


# ─────────────────────────────────────────────────────────────────────────────
# Main
# ─────────────────────────────────────────────────────────────────────────────

def main() -> int:
    parser = argparse.ArgumentParser(
        description="Clear + re-derive every jurisdiction's parent linkage via "
                    "the import-time strategy ladder.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__,
    )
    parser.add_argument(
        "--dry-run", action="store_true",
        help="Print planned actions + current orphan/strategy histograms "
             "without writing anything.",
    )
    args = parser.parse_args()

    log.info("=== reresolve_parents.py starting (%s) ===",
             "DRY RUN" if args.dry_run else "live")
    start = datetime.now(timezone.utc)

    conn = get_connection()
    try:
        # One plain DictCursor for the whole write phase — everything before the
        # explicit commit below rides a single transaction (see docstring for
        # the step-3 internal-commit caveat).
        cur = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)

        earth_id = get_earth_id(cur)
        log.info("Earth (adm_level=0) id: %s", earth_id)
        log_state(cur, "BEFORE")

        if args.dry_run:
            log.info("")
            log.info("DRY RUN — planned actions (nothing written):")
            log.info("  1. UPDATE jurisdictions SET parent_id=NULL, "
                     "parent_assigned_via=NULL WHERE adm_level > 0 "
                     "AND deleted_at IS NULL")
            log.info("  2. Re-anchor ALL adm_level=1 rows (incl. synthetic) to "
                     "Earth %s with parent_assigned_via='direct'", earth_id)
            log.info("  3. import_geoboundaries.post_pass_orphan_resolution() — "
                     "strategy ladder over levels 2..6 + Phase S")
            log.info("  4. Report after-state orphan counts + histogram")
            conn.rollback()   # nothing pending, but be explicit
            return 0

        # ── Step 1: clear every derived parent above Earth ──────────────────
        cur.execute("""
            UPDATE jurisdictions
            SET    parent_id           = NULL,
                   parent_assigned_via = NULL,
                   updated_at          = NOW()
            WHERE  adm_level > 0
              AND  deleted_at IS NULL
        """)
        log.info("Step 1: cleared parent linkage on %d row(s)", cur.rowcount)

        # ── Step 2: THE TRAP — re-anchor all countries (incl. synthetic) ────
        # The ladder in step 3 only covers levels 2..6 and matches by geometry;
        # Earth has geom=NULL, so skipping this leaves EVERY country orphaned.
        cur.execute("""
            UPDATE jurisdictions
            SET    parent_id           = %s,
                   parent_assigned_via = 'direct',
                   updated_at          = NOW()
            WHERE  adm_level = 1
              AND  deleted_at IS NULL
        """, (earth_id,))
        log.info("Step 2: re-anchored %d adm_level=1 row(s) to Earth", cur.rowcount)

        # ── Step 3: re-derive levels 2..6 via the import-time ladder ────────
        counts = post_pass_orphan_resolution(conn, earth_id, log)
        log.info("Step 3: ladder counts: %s", counts)

        # ── Step 4: after-state report + explicit final commit ──────────────
        residual = log_state(cur, "AFTER")
        cur.close()
        conn.commit()

        elapsed = (datetime.now(timezone.utc) - start).total_seconds()
        if residual > 0:
            log.warning("%d jurisdiction(s) still have parent_id IS NULL after "
                        "re-derive — manual review needed", residual)
        log.info("=== reresolve_parents.py done in %.1fs ===", elapsed)
        return 0
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


if __name__ == "__main__":
    sys.exit(main())
