"""
run_raster_attribution_drillrun.py — Phase T.7 standalone proof-of-concept.

Iterates every (iso, level) pair with ≥2 polygons, runs
raster_attribution.attribute() against the on-disk WorldPop GeoTIFFs,
and reports per-pair timing + per-polygon population totals. DRY RUN —
does NOT update jurisdictions.population. Results go to a JSON report
file + a per-pair summary log.

Why dry-run for the proof-of-concept:
  - Lets us compare T.7 output against the existing population_baseline
    + per-iso L=1 totals without corrupting the current data.
  - Operator can review the report, decide whether to apply.
  - Same code path can be flipped to apply-mode by setting
    APPLY_TO_DB=1 in the env.

Usage (inside the etl container):
  python3 /etl/run_raster_attribution_drillrun.py
  # Or to apply:
  APPLY_TO_DB=1 python3 /etl/run_raster_attribution_drillrun.py
"""

from __future__ import annotations

import json
import logging
import os
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, "/etl")

from db import get_connection, get_cursor
from raster_attribution import attribute
from import_worldpop import find_worldpop_tif

REPORT_PATH = Path("/etl/control/t7_dryrun_report.json")
LOG_PATH    = Path("/etl/control/t7_dryrun.log")
APPLY_TO_DB = os.environ.get("APPLY_TO_DB", "0") == "1"

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
    handlers=[
        logging.StreamHandler(sys.stdout),
        logging.FileHandler(LOG_PATH, mode="w"),
    ],
)
log = logging.getLogger("t7_dryrun")


def enumerate_iso_levels(conn) -> list[tuple[str, int, int]]:
    """Return [(iso_code, adm_level, n_polys), ...] for every pair with ≥2."""
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT iso_code, adm_level, COUNT(*) AS n
            FROM   jurisdictions
            WHERE  iso_code IS NOT NULL
              AND  adm_level >= 1
              AND  deleted_at IS NULL
            GROUP  BY iso_code, adm_level
            HAVING COUNT(*) >= 2
            ORDER  BY iso_code, adm_level
        """)
        return [(r["iso_code"], r["adm_level"], r["n"]) for r in cur.fetchall()]


def fetch_l1_geom(conn, iso: str) -> bytes | None:
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT ST_AsBinary(geom) AS wkb
            FROM   jurisdictions
            WHERE  iso_code = %s AND adm_level = 1 AND deleted_at IS NULL
            LIMIT  1
        """, (iso,))
        row = cur.fetchone()
        if not row:
            return None
        wkb = row["wkb"]
        return bytes(wkb) if wkb is not None else None


def fetch_level_polygons(conn, iso: str, level: int) -> list[tuple[str, bytes]]:
    """Legacy: full WKB load. Used only by callers that haven't migrated
    to the lightweight (id, centroid, bbox) metadata interface."""
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT id::text AS id, ST_AsBinary(geom) AS wkb
            FROM   jurisdictions
            WHERE  iso_code = %s AND adm_level = %s AND deleted_at IS NULL
        """, (iso, level))
        return [(r["id"], bytes(r["wkb"])) for r in cur.fetchall()
                if r["wkb"] is not None]


def fetch_level_polygon_meta(conn, iso: str, level: int):
    """
    Lightweight polygon metadata for attribute() — id + centroid +
    bounding-box per polygon. Avoids loading the WKB blobs (which for
    IND L=6's 649 k polygons would be GBs). The geoms themselves are
    fetched per-window via fetch_geoms_by_jur_ids() during attribution.

    Returns:
        (polygon_meta, idx_to_jur_id):
          - polygon_meta: list of tuples
              (jurisdiction_id, centroid_x, centroid_y, minx, miny, maxx, maxy)
              in deterministic order. The list index is the polygon's
              0-based ARRAY index (raster label = idx + 1).
          - idx_to_jur_id: parallel dict {0: 'uuid-string', 1: '...', ...}
              so the per-window geom-fetch callback can map back to
              jurisdiction UUIDs.
    """
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT id::text AS id,
                   ST_X(ST_Centroid(geom)) AS cx,
                   ST_Y(ST_Centroid(geom)) AS cy,
                   ST_XMin(geom)::float AS minx,
                   ST_YMin(geom)::float AS miny,
                   ST_XMax(geom)::float AS maxx,
                   ST_YMax(geom)::float AS maxy
            FROM   jurisdictions
            WHERE  iso_code = %s AND adm_level = %s AND deleted_at IS NULL
            ORDER  BY id
        """, (iso, level))
        meta = []
        idx_to_jur = {}
        for i, r in enumerate(cur.fetchall()):
            meta.append((
                r["id"], float(r["cx"]), float(r["cy"]),
                float(r["minx"]), float(r["miny"]),
                float(r["maxx"]), float(r["maxy"]),
            ))
            idx_to_jur[i] = r["id"]
    return meta, idx_to_jur


def make_geom_fetcher(conn, iso: str, level: int, idx_to_jur_id: dict):
    """
    Return a callable suitable for attribute()'s `get_geoms` parameter.
    The callable takes a list of polygon array-indices and returns a
    {index: wkb_bytes} dict. Each call runs one SQL query bounded to
    the requested ID set.

    Caches recently-fetched WKBs to avoid re-querying when adjacent
    raster windows share polygons. Cache is bounded by the largest
    window's polygon count (typically < 10 k entries for desktop-sized
    windows on dense isos like IND L=6).
    """
    cache: dict[int, bytes] = {}
    MAX_CACHE = 50000  # bound the cache to keep memory steady

    def fetch(indices: list[int]) -> dict[int, bytes]:
        # Split into cached + missing.
        result = {}
        missing = []
        for idx in indices:
            if idx in cache:
                result[idx] = cache[idx]
            else:
                missing.append(idx)
        if not missing:
            return result

        # Map missing array-indices to jurisdiction UUIDs for the SQL query.
        missing_jur_ids = [idx_to_jur_id[i] for i in missing]
        with get_cursor(conn) as cur:
            cur.execute("""
                SELECT id::text AS id, ST_AsBinary(geom) AS wkb
                FROM   jurisdictions
                WHERE  iso_code = %s AND adm_level = %s
                  AND  deleted_at IS NULL
                  AND  id = ANY(%s::uuid[])
            """, (iso, level, missing_jur_ids))
            jur_to_wkb = {r["id"]: bytes(r["wkb"]) for r in cur.fetchall()
                          if r["wkb"] is not None}

        # Map back to array-index keys.
        for idx in missing:
            jur_id = idx_to_jur_id[idx]
            wkb = jur_to_wkb.get(jur_id)
            if wkb is not None:
                result[idx] = wkb
                if len(cache) < MAX_CACHE:
                    cache[idx] = wkb
        return result

    return fetch


def fetch_relevant_rasters(conn, iso: str, level: int) -> list[Path]:
    """
    Enumerate raster iso_codes whose worldpop_rasters tiles intersect any
    L=1 or L=level polygon of this iso, then map each to its on-disk TIF
    path. Own iso first.
    """
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT DISTINCT r.iso_code
            FROM   worldpop_rasters r
            JOIN   jurisdictions j
              ON   j.iso_code = %s
             AND   j.adm_level IN (1, %s)
             AND   j.deleted_at IS NULL
            WHERE  ST_Intersects(r.rast, j.geom)
        """, (iso, level))
        relevant_isos = [r["iso_code"] for r in cur.fetchall()]

    # Own iso first so the largest expected raster is read first;
    # then sorted others.
    ordered = ([iso] if iso in relevant_isos else []) + \
              sorted(set(relevant_isos) - {iso})

    paths: list[Path] = []
    for ric in ordered:
        p = find_worldpop_tif(ric)
        if p is not None:
            paths.append(p)
        else:
            log.debug("  no TIF on disk for %s", ric)
    return paths


def fetch_baselines(conn, iso: str, level: int) -> dict[str, int]:
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT id::text AS id, population_baseline
            FROM   jurisdictions
            WHERE  iso_code = %s AND adm_level = %s AND deleted_at IS NULL
        """, (iso, level))
        return {r["id"]: int(r["population_baseline"] or 0) for r in cur.fetchall()}


def fetch_l1_pop(conn, iso: str) -> int:
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT population_baseline FROM jurisdictions
            WHERE  iso_code = %s AND adm_level = 1 AND deleted_at IS NULL
            LIMIT  1
        """, (iso,))
        row = cur.fetchone()
        if not row or row["population_baseline"] is None:
            return 0
        return int(row["population_baseline"])


def apply_results(conn, iso: str, level: int, results: dict[str, int]) -> int:
    """Apply T.7 results to jurisdictions.population. Returns rows updated."""
    if not results:
        return 0
    # Build VALUES list. psycopg2 doesn't have a great way for batched
    # multi-row UPDATEs; use a single UPDATE FROM (VALUES (...)) AS t.
    values = ",".join(
        f"('{uid}'::uuid, {pop}::bigint)"
        for uid, pop in results.items()
    )
    sql = f"""
        UPDATE jurisdictions j
        SET    population = t.pop, updated_at = NOW()
        FROM   (VALUES {values}) AS t(id, pop)
        WHERE  j.id = t.id
    """
    with get_cursor(conn) as cur:
        cur.execute(sql)
        return cur.rowcount


def main() -> int:
    log.info("[T.7 dry-run start] APPLY_TO_DB=%s", APPLY_TO_DB)

    conn = get_connection()
    try:
        pairs = enumerate_iso_levels(conn)
        log.info("Enumerated %d (iso, level) pairs", len(pairs))

        run_start = time.monotonic()
        report = {
            "started_at": datetime.now(timezone.utc).isoformat(),
            "apply_to_db": APPLY_TO_DB,
            "pairs": [],
            "totals_by_verdict": {
                "exact": 0, "near": 0, "partial": 0, "far": 0,
                "no_l1": 0, "no_polys": 0, "no_rasters": 0,
            },
        }

        for idx, (iso, level, n_polys) in enumerate(pairs, start=1):
            pair_start = time.monotonic()

            l1_geom_wkb = fetch_l1_geom(conn, iso)
            if l1_geom_wkb is None:
                log.warning("  [%s L%d] no L=1 geom — skipping", iso, level)
                report["pairs"].append({
                    "iso": iso, "level": level, "n_polys": n_polys,
                    "verdict": "no_l1", "elapsed_s": 0,
                })
                report["totals_by_verdict"]["no_l1"] += 1
                continue

            # Lightweight polygon metadata (id + centroid + bbox).
            polygon_meta, idx_to_jur_id = fetch_level_polygon_meta(conn, iso, level)
            if not polygon_meta:
                report["pairs"].append({
                    "iso": iso, "level": level, "n_polys": n_polys,
                    "verdict": "no_polys", "elapsed_s": 0,
                })
                report["totals_by_verdict"]["no_polys"] += 1
                continue

            raster_paths = fetch_relevant_rasters(conn, iso, level)
            if not raster_paths:
                report["pairs"].append({
                    "iso": iso, "level": level, "n_polys": n_polys,
                    "verdict": "no_rasters", "elapsed_s": 0,
                })
                report["totals_by_verdict"]["no_rasters"] += 1
                continue

            l1_pop = fetch_l1_pop(conn, iso)
            baselines = fetch_baselines(conn, iso, level)
            pre_sum = sum(baselines.values())

            # Build a DB-backed lazy geom fetcher for this (iso, level).
            geom_fetcher = make_geom_fetcher(conn, iso, level, idx_to_jur_id)

            # Run the attribution.
            results = attribute(
                iso=iso,
                adm_level=level,
                l1_geom_wkb=l1_geom_wkb,
                polygon_meta=polygon_meta,
                get_geoms=geom_fetcher,
                raster_paths=raster_paths,
                log=log,
            )
            post_sum = sum(results.values())

            elapsed = time.monotonic() - pair_start
            pre_dev  = pre_sum  - l1_pop
            post_dev = post_sum - l1_pop

            # Verdict: how close did we get to L=1 (for non-cross-iso cases)?
            if l1_pop > 0:
                pct = abs(post_dev) / l1_pop * 100
            else:
                pct = None
            if pct is None:
                verdict = "exact" if post_dev == 0 else "no_l1"
            elif pct < 0.01:
                verdict = "exact"
            elif pct < 1.0:
                verdict = "near"
            elif pct < 5.0:
                verdict = "partial"
            else:
                verdict = "far"
            report["totals_by_verdict"][verdict] = report["totals_by_verdict"].get(verdict, 0) + 1

            log.info(
                "  [%4d/%4d] %s L%d (n=%d, rasters=%d): "
                "pre=%d (dev=%d), post=%d (dev=%d), "
                "verdict=%s, elapsed=%.1fs",
                idx, len(pairs), iso, level, n_polys, len(raster_paths),
                pre_sum, pre_dev, post_sum, post_dev, verdict, elapsed,
            )

            applied_rows = 0
            if APPLY_TO_DB and results:
                applied_rows = apply_results(conn, iso, level, results)
                conn.commit()

            report["pairs"].append({
                "iso": iso, "level": level, "n_polys": n_polys,
                "n_rasters": len(raster_paths),
                "l1_pop": l1_pop,
                "pre_sum": pre_sum,
                "post_sum": post_sum,
                "pre_dev": pre_dev,
                "post_dev": post_dev,
                "verdict": verdict,
                "elapsed_s": round(elapsed, 2),
                "applied_rows": applied_rows,
                "timestamp": datetime.now(timezone.utc).isoformat(),
            })

            # Save report incrementally so a halt mid-run preserves data.
            with open(REPORT_PATH, "w") as f:
                json.dump(report, f, indent=2, default=str)

        total_elapsed = time.monotonic() - run_start
        report["finished_at"] = datetime.now(timezone.utc).isoformat()
        report["total_elapsed_s"] = round(total_elapsed, 2)
        report["total_elapsed_h"] = round(total_elapsed / 3600, 3)

        with open(REPORT_PATH, "w") as f:
            json.dump(report, f, indent=2, default=str)

        log.info(
            "[T.7 dry-run done] total=%.1fs (%.2fh) | verdicts=%s",
            total_elapsed, total_elapsed / 3600,
            report["totals_by_verdict"],
        )
        return 0
    finally:
        conn.close()


if __name__ == "__main__":
    sys.exit(main())
