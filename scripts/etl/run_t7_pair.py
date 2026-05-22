"""
run_t7_pair.py — Phase T.7 single-pair worker.

Runs raster_attribution.attribute() for one (iso, adm_level) pair and
prints a single JSON result line to stdout. Designed to be invoked as a
subprocess by the orchestrator so that all memory is freed between
pairs (Python heap fragmentation + rasterio internal caches accumulate
across many calls in the same process; subprocess isolation prevents
the slow OOM-creep we hit at pair 69/483 in the previous full sweep).

Usage:
  python3 /etl/run_t7_pair.py ISO LEVEL [--apply]

Output (JSON to stdout, one line):
  {
    "iso": "FRA", "level": 5,
    "ok": true,
    "elapsed_s": 32.5,
    "n_polys": 2054,
    "n_rasters": 12,
    "l1_pop": 65201789,
    "pre_sum": 64273536,
    "post_sum": 67949469,
    "post_dev": 2747680,
    "verdict": "far",
    "applied_rows": 0,
    "results": {jurisdiction_id: pop_int, ...}  # only if --apply
  }

On error: {"iso": "...", "level": ..., "ok": false, "error": "..."}.

Exit code: 0 on success, 1 on failure. Crashes (OOM signal-9, kernel
kill) leave no JSON on stdout — the orchestrator treats missing-JSON
as a hard failure and marks the pair as failed in the progress file.
"""

from __future__ import annotations

import json
import logging
import sys
import time
import traceback

sys.path.insert(0, "/etl")

from db import get_connection, get_cursor
from raster_attribution import attribute
from import_worldpop import find_worldpop_tif


def fetch_l1_geom(conn, iso: str) -> bytes | None:
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT ST_AsBinary(geom) AS wkb FROM jurisdictions
            WHERE iso_code = %s AND adm_level = 1 AND deleted_at IS NULL LIMIT 1
        """, (iso,))
        row = cur.fetchone()
        if not row or row["wkb"] is None:
            return None
        return bytes(row["wkb"])


def fetch_level_polygon_meta(conn, iso: str, level: int):
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


def fetch_relevant_rasters(conn, iso: str, level: int):
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
        relevant = [r["iso_code"] for r in cur.fetchall()]

    ordered = ([iso] if iso in relevant else []) + sorted(set(relevant) - {iso})
    paths = []
    for ric in ordered:
        p = find_worldpop_tif(ric)
        if p is not None:
            paths.append(p)
    return paths


def fetch_baselines_sum(conn, iso: str, level: int) -> int:
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT COALESCE(SUM(population_baseline), 0) AS s FROM jurisdictions
            WHERE iso_code = %s AND adm_level = %s AND deleted_at IS NULL
        """, (iso, level))
        return int(cur.fetchone()["s"] or 0)


def fetch_l1_pop(conn, iso: str) -> int:
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT population_baseline FROM jurisdictions
            WHERE iso_code = %s AND adm_level = 1 AND deleted_at IS NULL LIMIT 1
        """, (iso,))
        row = cur.fetchone()
        if not row or row["population_baseline"] is None:
            return 0
        return int(row["population_baseline"])


def make_geom_fetcher(conn, iso: str, level: int, idx_to_jur_id: dict):
    cache: dict[int, bytes] = {}
    MAX_CACHE = 50000

    def fetch(indices):
        result = {}
        missing = []
        for idx in indices:
            if idx in cache:
                result[idx] = cache[idx]
            else:
                missing.append(idx)
        if not missing:
            return result
        missing_jur_ids = [idx_to_jur_id[i] for i in missing]
        with get_cursor(conn) as cur:
            cur.execute("""
                SELECT id::text AS id, ST_AsBinary(geom) AS wkb FROM jurisdictions
                WHERE iso_code = %s AND adm_level = %s
                  AND deleted_at IS NULL AND id = ANY(%s::uuid[])
            """, (iso, level, missing_jur_ids))
            jur_to_wkb = {r["id"]: bytes(r["wkb"]) for r in cur.fetchall() if r["wkb"]}
        for idx in missing:
            jur_id = idx_to_jur_id[idx]
            wkb = jur_to_wkb.get(jur_id)
            if wkb is not None:
                result[idx] = wkb
                if len(cache) < MAX_CACHE:
                    cache[idx] = wkb
        return result

    return fetch


def main(iso: str, level: int, apply_to_db: bool) -> int:
    # Quiet logger — orchestrator collects from stdout JSON.
    logging.basicConfig(level=logging.ERROR)
    log = logging.getLogger("t7_pair")

    result = {
        "iso": iso, "level": level,
        "ok": False, "elapsed_s": 0.0,
    }
    start = time.monotonic()

    try:
        conn = get_connection()
        try:
            l1_wkb = fetch_l1_geom(conn, iso)
            if l1_wkb is None:
                result["error"] = "no L=1 geom"
                print(json.dumps(result))
                return 1
            meta, idx_to_jur = fetch_level_polygon_meta(conn, iso, level)
            if not meta:
                result["error"] = "no polygons"
                print(json.dumps(result))
                return 1
            rasters = fetch_relevant_rasters(conn, iso, level)
            if not rasters:
                result["error"] = "no rasters"
                print(json.dumps(result))
                return 1

            l1_pop = fetch_l1_pop(conn, iso)
            pre_sum = fetch_baselines_sum(conn, iso, level)
            fetcher = make_geom_fetcher(conn, iso, level, idx_to_jur)

            attr_start = time.monotonic()
            attr_results = attribute(
                iso=iso, adm_level=level,
                l1_geom_wkb=l1_wkb,
                polygon_meta=meta, get_geoms=fetcher,
                raster_paths=rasters, log=log,
            )
            attr_elapsed = time.monotonic() - attr_start
            post_sum = sum(attr_results.values())
            post_dev = post_sum - l1_pop

            if l1_pop > 0:
                pct = abs(post_dev) / l1_pop * 100
                if pct < 0.01:        verdict = "exact"
                elif pct < 1.0:       verdict = "near"
                elif pct < 5.0:       verdict = "partial"
                else:                 verdict = "far"
            else:
                verdict = "no_l1"

            applied_rows = 0
            if apply_to_db and attr_results:
                values = ",".join(
                    f"('{uid}'::uuid, {pop}::bigint)"
                    for uid, pop in attr_results.items()
                )
                sql = f"""
                    UPDATE jurisdictions j
                    SET    population = t.pop, updated_at = NOW()
                    FROM   (VALUES {values}) AS t(id, pop)
                    WHERE  j.id = t.id
                """
                with get_cursor(conn) as cur:
                    cur.execute(sql)
                    applied_rows = cur.rowcount
                conn.commit()

            result.update({
                "ok": True,
                "elapsed_s": round(attr_elapsed, 2),
                "n_polys": len(meta),
                "n_rasters": len(rasters),
                "l1_pop": l1_pop,
                "pre_sum": pre_sum,
                "post_sum": post_sum,
                "post_dev": post_dev,
                "verdict": verdict,
                "applied_rows": applied_rows,
            })
            print(json.dumps(result))
            return 0
        finally:
            conn.close()
    except Exception as exc:
        result["error"] = f"{type(exc).__name__}: {exc}"
        result["traceback"] = traceback.format_exc()
        result["elapsed_s"] = round(time.monotonic() - start, 2)
        print(json.dumps(result))
        return 1


if __name__ == "__main__":
    if len(sys.argv) < 3:
        print(json.dumps({"ok": False, "error": "usage: run_t7_pair.py ISO LEVEL [--apply]"}))
        sys.exit(2)
    iso = sys.argv[1]
    level = int(sys.argv[2])
    apply_db = "--apply" in sys.argv[3:]
    sys.exit(main(iso, level, apply_db))
