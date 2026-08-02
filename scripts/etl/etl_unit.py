"""
etl_unit.py — execute ONE geodata item in a fresh process
(GEODATA_PULL_ENGINE_PLAN.md §4).

Usage:  python3 etl_unit.py --run RUN_ID --item ITEM_ID

Prints exactly ONE JSON line to stdout:
  {"ok": true,  "status": "done",   "metrics": {...}}
  {"ok": false, "status": "review", "reason": "...", "metrics": {...}}

A fresh subprocess per item is the T.7 OOM lesson made law: rasterio caches,
heap fragmentation, and NumPy temporaries all die with the child. The parent
worker (worker.py) reads this JSON and owns the DB status write; a missing
JSON line or a non-zero exit is treated by the worker as status='review'.

Each kind dispatches to the EXISTING importer functions — the only new seam
is import_geoboundaries's no_global_passes / global_passes_only split.
"""

from __future__ import annotations

import argparse
import json
import logging
import os
import subprocess
import sys
import traceback

sys.path.insert(0, "/etl")

from db import get_connection, get_cursor  # noqa: E402

PAIR_SCRIPT = "/etl/run_t7_pair.py"


def _logger() -> logging.Logger:
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s [%(levelname)-5s] etl_unit: %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
    )
    return logging.getLogger("etl_unit")


def load_item(conn, item_id: str) -> dict | None:
    with get_cursor(conn) as cur:
        cur.execute(
            """
            SELECT id::text AS id, run_id::text AS run_id, kind,
                   iso_code, adm_level, dry_run
              FROM geodata_items WHERE id = %s
            """,
            (item_id,),
        )
        row = cur.fetchone()
    return dict(row) if row else None


def load_run_options(conn, run_id: str) -> dict:
    with get_cursor(conn) as cur:
        cur.execute("SELECT options FROM geodata_runs WHERE id = %s", (run_id,))
        row = cur.fetchone()
    return (row["options"] if row and row["options"] else {}) or {}


def _insert_items(conn, run_id: str, rows: list[tuple]) -> None:
    """rows: (kind, iso_code, adm_level, est_cost). position = -est_cost
    (largest-first). Chunked per THE ETL RULE (~1k rows here, but honor it)."""
    if not rows:
        return
    import psycopg2.extras

    payload = [
        (run_id, kind, iso, lvl, "pending", -int(cost), int(cost))
        for (kind, iso, lvl, cost) in rows
    ]
    with get_cursor(conn) as cur:
        psycopg2.extras.execute_values(
            cur,
            """
            INSERT INTO geodata_items
                (run_id, kind, iso_code, adm_level, status, position, est_cost,
                 created_at, updated_at)
            VALUES %s
            """,
            payload,
            template="(%s,%s,%s,%s,%s,%s,%s,now(),now())",
            page_size=25000,
        )


# ── Per-kind handlers ───────────────────────────────────────────────────────

def do_manifest(conn, run_id: str, options: dict, log: logging.Logger) -> dict:
    """Discover the archive and INSERT every downstream item (except the
    attribution pairs, which the resolve_global barrier enumerates from the
    populated DB). boundary_iso + raster_iso are file-derived; the barriers are
    singletons."""
    from import_geoboundaries import discover_geoboundaries_files
    from import_worldpop import find_worldpop_tif

    discovered = discover_geoboundaries_files()  # [(iso, adm_n, path)]
    countries = {c.upper() for c in (options.get("countries") or [])}

    iso_bytes: dict[str, int] = {}
    for iso, _adm_n, path in discovered:
        if countries and iso not in countries:
            continue
        try:
            sz = os.path.getsize(path)
        except OSError:
            sz = 0
        iso_bytes[iso] = iso_bytes.get(iso, 0) + sz

    rows: list[tuple] = []
    for iso, cost in iso_bytes.items():
        rows.append(("boundary_iso", iso, None, cost))

    for iso in sorted(iso_bytes):
        tif = find_worldpop_tif(iso)
        if tif is not None:
            try:
                rc = os.path.getsize(tif)
            except OSError:
                rc = 0
            rows.append(("raster_iso", iso, None, rc))

    rows.append(("resolve_global", None, None, 0))
    rows.append(("finalize_global", None, None, 0))
    rows.append(("acceptance_scan", None, None, 0))

    _insert_items(conn, run_id, rows)
    log.info("manifest: %d isos → %d items enumerated", len(iso_bytes), len(rows))
    return {"isos": len(iso_bytes), "items_inserted": len(rows)}


def do_boundary(iso: str, options: dict, log: logging.Logger) -> dict:
    from import_geoboundaries import import_geoboundaries

    adm = options.get("adm_levels") or None
    n = import_geoboundaries(countries=[iso], adm_levels=adm,
                             no_global_passes=True, log=log)
    return {"inserted": int(n)}


def do_resolve(conn, run_id: str, options: dict, log: logging.Logger) -> dict:
    """The single-writer barrier after all boundaries: run the global passes
    (Earth + synthesize + orphan resolution + cross-ISO), THEN enumerate the
    attribution pairs from the now-populated DB (the authoritative source)."""
    from import_geoboundaries import import_geoboundaries
    from run_t7_orchestrator import enumerate_iso_levels

    import_geoboundaries(global_passes_only=True, log=log)

    pairs = enumerate_iso_levels(conn)  # [(iso, level, npolys)] — DB-derived
    countries = {c.upper() for c in (options.get("countries") or [])}
    if countries:
        pairs = [p for p in pairs if p[0] in countries]
    rows = [("attribution_pair", iso, level, int(npolys)) for iso, level, npolys in pairs]
    _insert_items(conn, run_id, rows)
    log.info("resolve_global: post-pass done, %d attribution pairs enumerated", len(rows))
    return {"attribution_pairs": len(rows)}


def do_raster(iso: str, log: logging.Logger) -> dict:
    from import_worldpop import find_worldpop_tif, load_raster_to_db

    tif = find_worldpop_tif(iso)
    if tif is None:
        # Honest-empty: a fallback/absent iso has no own raster; the acceptance
        # scan flags coverage gaps (raster_absent_alias). Not a failure.
        return {"tiles": 0, "note": "no raster tif for iso"}
    conn = get_connection()
    try:
        tiles = load_raster_to_db(conn, iso, tif, log)
    finally:
        conn.close()
    return {"tiles": int(tiles)}


def do_attribution(iso: str, level: int, apply_to_db: bool, log: logging.Logger) -> dict:
    """Run the T.7 pair in its own subprocess (run_t7_pair.py — already the
    memory-isolated pair worker) and relay its JSON verdict. A 'far' verdict is
    NOT a failure (the scan flags data quality); only a hard error is review."""
    cmd = ["python3", PAIR_SCRIPT, iso, str(level)]
    if apply_to_db:
        cmd.append("--apply")
    proc = subprocess.run(cmd, capture_output=True, text=True, check=False)

    payload = None
    for line in reversed(proc.stdout.strip().splitlines()):
        try:
            payload = json.loads(line)
            break
        except ValueError:
            continue
    if payload is None:
        raise RuntimeError(
            f"pair produced no JSON (exit {proc.returncode}): {proc.stderr[-400:]}"
        )
    if not payload.get("ok"):
        raise RuntimeError(payload.get("error", "attribution pair failed"))

    return {k: payload.get(k) for k in
            ("verdict", "n_polys", "post_sum", "l1_pop", "post_dev", "applied_rows", "elapsed_s")}


def do_finalize(conn, options: dict, log: logging.Logger) -> dict:
    """Planet rollup + per-country national validation (the barrier). Findings
    land as geodata_flags rows (class national_delta_gt5) — the repair plane's
    censusable stream — not just log warnings (GEODATA_PULL_ENGINE_PLAN.md §6)."""
    from import_worldpop import rollup_planet_population, validate_national_population

    rollup = rollup_planet_population(conn, log)

    countries = {c.upper() for c in (options.get("countries") or [])}
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT DISTINCT iso_code FROM jurisdictions
             WHERE adm_level = 1 AND iso_code IS NOT NULL AND deleted_at IS NULL
        """)
        isos = [r["iso_code"] for r in cur.fetchall()]
    if countries:
        isos = [i for i in isos if i in countries]
    for iso in isos:
        try:
            validate_national_population(conn, iso, log)  # the per-iso log line
        except Exception as exc:  # a single country's validation never sinks it
            log.warning("validate_national_population(%s) failed: %s", iso, exc)

    # One set-based pass: every national row whose children-sum deviates >5%
    # becomes an OPEN geodata_flags row (idempotent via fingerprint — an open
    # flag for the same iso is not duplicated; a resolved one may re-open as a
    # fresh detection after a re-run).
    flagged = 0
    with get_cursor(conn) as cur:
        cur.execute("""
            WITH deltas AS (
                SELECT p.id, p.iso_code, p.population AS national,
                       SUM(c.population) AS children_sum
                  FROM jurisdictions p
                  JOIN jurisdictions c ON c.parent_id = p.id AND c.deleted_at IS NULL
                 WHERE p.adm_level = 1 AND p.deleted_at IS NULL
                   AND p.population IS NOT NULL AND p.iso_code = ANY(%s)
                 GROUP BY p.id, p.iso_code, p.population
                HAVING SUM(c.population) IS NOT NULL AND p.population > 0
                   AND ABS(SUM(c.population) - p.population)::float / p.population > 0.05
            )
            INSERT INTO geodata_flags
                (category, severity, jurisdiction_id, title, payload,
                 fingerprint, status, detected_at, created_at, updated_at)
            SELECT 'national_delta_gt5', 'warning', d.id,
                   'National vs children population delta > 5% — ' || d.iso_code,
                   jsonb_build_object('iso', d.iso_code, 'national', d.national,
                                      'children_sum', d.children_sum,
                                      'delta_pct', ROUND(ABS(d.children_sum - d.national)::numeric
                                                         / d.national * 100, 2)),
                   'national_delta_gt5:' || d.iso_code, 'open', now(), now(), now()
              FROM deltas d
             WHERE NOT EXISTS (
                   SELECT 1 FROM geodata_flags f
                    WHERE f.fingerprint = 'national_delta_gt5:' || d.iso_code
                      AND f.status = 'open' AND f.deleted_at IS NULL
             )
        """, (isos,))
        flagged = cur.rowcount
    if flagged:
        log.warning("finalize: %d national_delta_gt5 flag(s) written", flagged)
    return {"planet_rows": int(rollup), "validated_isos": len(isos), "flags_written": flagged}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--run", required=True)
    ap.add_argument("--item", required=True)
    args = ap.parse_args()
    log = _logger()

    conn = get_connection()
    try:
        item = load_item(conn, args.item)
        if item is None:
            print(json.dumps({"ok": False, "status": "review", "reason": "item gone"}))
            return 1
        options = load_run_options(conn, args.run)
        kind = item["kind"]

        try:
            if kind == "manifest":
                metrics = do_manifest(conn, args.run, options, log)
            elif kind == "boundary_iso":
                metrics = do_boundary(item["iso_code"], options, log)
            elif kind == "resolve_global":
                metrics = do_resolve(conn, args.run, options, log)
            elif kind == "raster_iso":
                metrics = do_raster(item["iso_code"], log)
            elif kind == "attribution_pair":
                metrics = do_attribution(item["iso_code"], int(item["adm_level"]),
                                         not item["dry_run"], log)
            elif kind == "finalize_global":
                metrics = do_finalize(conn, options, log)
            elif kind == "acceptance_scan":
                # Laravel-side (the pump dispatches GeodataAcceptanceScanJob);
                # a Python worker should never claim this. No-op defensively.
                metrics = {"note": "acceptance scan runs Laravel-side"}
            else:
                raise ValueError(f"unknown item kind: {kind}")

            print(json.dumps({"ok": True, "status": "done", "metrics": metrics}))
            return 0
        except Exception as exc:
            print(json.dumps({
                "ok": False, "status": "review",
                "reason": f"{type(exc).__name__}: {exc}",
                "metrics": {"traceback": traceback.format_exc()[-1500:]},
            }))
            return 1
    finally:
        conn.close()


if __name__ == "__main__":
    sys.exit(main())
