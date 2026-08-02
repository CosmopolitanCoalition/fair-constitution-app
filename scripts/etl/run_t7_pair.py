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
import os
import sys
import time
import traceback

sys.path.insert(0, "/etl")

from db import get_connection, get_cursor
from raster_attribution import attribute, DEFAULT_WINDOW_PX
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


def _stage_tifs(paths, log) -> list:
    """Copy tifs to the container-local volume once, shared across slices
    (atomic replace; racers write identical bytes). MEASURED 2026-08-02:
    windowed reads over the Windows bind mount were ~98% IO WAIT — the CAN
    arctic slice spent 11 CPU-seconds in 10 wall-minutes. One sequential
    mount read (which the mount does fast) turns thousands of window reads
    local. Correctness never depends on staging — any failure falls back
    to the mount path."""
    import shutil
    import tempfile
    from pathlib import Path as _P
    cache = _P("/data/tifcache")
    try:
        cache.mkdir(parents=True, exist_ok=True)
    except Exception:
        return paths
    out = []
    for p in paths:
        try:
            dst = cache / p.name
            if not dst.exists() or dst.stat().st_size != p.stat().st_size:
                fd, tmp = tempfile.mkstemp(dir=str(cache))
                os.close(fd)
                shutil.copyfile(p, tmp)
                os.replace(tmp, dst)
            out.append(dst)
        except Exception:
            out.append(p)
    return out


def main(iso: str, level: int, apply_to_db: bool,
         enumerate_only: bool = False,
         win_start: int | None = None, win_count: int | None = None,
         window_px_pin: int | None = None, run_id: str | None = None) -> int:
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

            # ── --enumerate: deterministic window census for the pair's
            # coordinator (header math only). window_px is PINNED here and
            # carried in every range's metrics — the sequence identity. ──
            if enumerate_only:
                from raster_attribution import (claim_bounds_for,
                                                count_raw_windows,
                                                DEFAULT_WINDOW_PX)
                px = window_px_pin or DEFAULT_WINDOW_PX
                n = count_raw_windows(rasters, claim_bounds_for(l1_wkb, meta), px)
                print(json.dumps({"ok": True, "n_windows": int(n),
                                  "window_px": int(px),
                                  "n_rasters": len(rasters)}))
                return 0

            l1_pop = fetch_l1_pop(conn, iso)
            pre_sum = fetch_baselines_sum(conn, iso, level)
            fetcher = make_geom_fetcher(conn, iso, level, idx_to_jur)
            rasters = _stage_tifs(rasters, log)

            # ── WINDOW-SLICE mode (operator design: windows chunked among
            # lanes): compute ONLY [win_start, win_start+win_count) of the
            # pinned deterministic sequence, write per-jurisdiction partials
            # in bounded chunks, and exit. No verdict, no apply — the
            # coordinator merges all slices with one GROUP BY and applies
            # set-based. Idempotent: this slice's prior rows are deleted
            # first. ──
            if win_start is not None:
                _cb = None
                try:
                    import heartbeat as _hb
                    _bar_key = f"t7:{iso}:L{level}:w{win_start}"
                    _hb.bar_start(_bar_key, label=f"{iso} L{level} windows {win_start}+",
                                  total=int(win_count or 0), unit="windows")
                    def _cb(cur, tot, _k=_bar_key, _h=_hb):
                        _h.bar_update(_k, cur, total=tot)
                except Exception:
                    _cb = None
                attr_start = time.monotonic()
                partial = attribute(
                    iso=iso, adm_level=level, l1_geom_wkb=l1_wkb,
                    polygon_meta=meta, get_geoms=fetcher,
                    raster_paths=rasters, log=log,
                    window_px=window_px_pin or DEFAULT_WINDOW_PX,
                    progress_cb=_cb,
                    window_slice=(int(win_start), int(win_count)),
                )
                with get_cursor(conn) as cur:
                    cur.execute(
                        "DELETE FROM attribution_partials WHERE run_id=%s "
                        "AND iso_code=%s AND adm_level=%s AND win_start=%s",
                        (run_id, iso, level, int(win_start)),
                    )
                rows = [(run_id, iso, level, int(win_start), jid, int(pop))
                        for jid, pop in partial.items()]
                import psycopg2.extras as _px
                for i in range(0, len(rows), 5000):
                    with get_cursor(conn) as cur:
                        _px.execute_values(
                            cur,
                            "INSERT INTO attribution_partials "
                            "(id, run_id, iso_code, adm_level, win_start, "
                            " jurisdiction_id, pop) VALUES %s",
                            rows[i:i + 5000],
                            template="(gen_random_uuid(),%s,%s,%s,%s,%s,%s)",
                        )
                result.update({
                    "ok": True, "partial": True,
                    "win_start": int(win_start), "win_count": int(win_count),
                    "n_partial_rows": len(rows),
                    "elapsed_s": round(time.monotonic() - attr_start, 2),
                })
                print(json.dumps(result))
                return 0

            # Live progress → the engine's item bar (operator ask 2026-08-02:
            # attribution must show incremental detail, not "errors or
            # nothing"). CGA_ETL_ITEM_ID is inherited from the etl_unit
            # child, so heartbeat's item writer lands window counts on this
            # pair's row — the pull panel renders the mini bar + ETA. Wrapped
            # defensively: a bar failure must never sink the attribution.
            _cb = None
            try:
                import heartbeat as _hb
                _bar_key = f"t7:{iso}:L{level}"
                _hb.bar_start(_bar_key, label=f"{iso} L{level} attribution",
                              total=0, unit="windows")
                def _cb(cur, tot, _k=_bar_key, _h=_hb):
                    _h.bar_update(_k, cur, total=tot)
            except Exception:
                _cb = None

            attr_start = time.monotonic()
            attr_results = attribute(
                iso=iso, adm_level=level,
                l1_geom_wkb=l1_wkb,
                polygon_meta=meta, get_geoms=fetcher,
                raster_paths=rasters, log=log,
                progress_cb=_cb,
            )
            attr_elapsed = time.monotonic() - attr_start
            post_sum = sum(attr_results.values())

            # Bench/verification hook: dump per-jurisdiction results so an
            # A/B run can prove per-ID exactness, not just total equality.
            _dump = os.environ.get("CGA_T7_DUMP_RESULTS", "")
            if _dump:
                try:
                    with open(_dump, "w") as fh:
                        json.dump({k: int(v) for k, v in sorted(attr_results.items())}, fh)
                except Exception:
                    pass
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
        print(json.dumps({"ok": False, "error":
              "usage: run_t7_pair.py ISO LEVEL [--apply] [--enumerate] "
              "[--win-start N --win-count N --window-px P --run-id UUID]"}))
        sys.exit(2)
    iso = sys.argv[1]
    level = int(sys.argv[2])
    rest = sys.argv[3:]

    def _opt(name):
        if name in rest:
            i = rest.index(name)
            if i + 1 < len(rest):
                return rest[i + 1]
        return None

    sys.exit(main(
        iso, level, "--apply" in rest,
        enumerate_only="--enumerate" in rest,
        win_start=int(_opt("--win-start")) if _opt("--win-start") is not None else None,
        win_count=int(_opt("--win-count")) if _opt("--win-count") is not None else None,
        window_px_pin=int(_opt("--window-px")) if _opt("--window-px") is not None else None,
        run_id=_opt("--run-id"),
    ))
