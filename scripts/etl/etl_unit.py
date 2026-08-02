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
import time
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
                   iso_code, adm_level, dry_run, metrics
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


def _run_level_import(iso: str, file_lvl: int | None, log: logging.Logger,
                      feature_start: int | None = None,
                      feature_count: int | None = None) -> int:
    """One import call with DONE-MUST-BE-EVIDENCED enforcement (operator,
    2026-08-02, after IND false-done'd at 331k/649k): the legacy importer
    records per-level failures into the progress dict and RETURNS NORMALLY
    (progress.json was the legacy UI's error surface). Any errored entry
    raises → the item lands in REVIEW with the real reasons."""
    from import_geoboundaries import import_geoboundaries

    progress: dict = {}
    n = import_geoboundaries(
        countries=[iso],
        adm_levels=[file_lvl] if file_lvl is not None else None,
        no_global_passes=True, log=log, progress=progress,
        feature_start=feature_start, feature_count=feature_count,
    )
    errored = {
        key: entry for key, entry in (progress.get("geoboundaries") or {}).items()
        if isinstance(entry, dict) and entry.get("status") == "error"
    }
    if errored:
        details = "; ".join(
            f"{key}: {str(entry.get('error', '?'))[:140]}"
            for key, entry in list(errored.items())[:4]
        )
        raise RuntimeError(
            f"{len(errored)} level(s) errored (inserted {int(n)} rows before failing) — {details}"
        )
    return int(n)


def _range_dials() -> tuple[int, int]:
    """(split_min, range_count_override) — a level with >= split_min expected
    features PRE-SPLITS into max(2, pool//2) ranges: one per big-first lane,
    so the big half of the pool converges on a monster level together
    (operator ruling 2026-08-02: "pre split for 5 lanes where a split is
    needed"). Fewer, larger ranges also bound the skip cost — every range
    child streams the file up to its window, so ranges-per-level ≈ passes
    over the file. CGA_ETL_RANGE_COUNT forces the count; CGA_ETL_RANGE_MIN
    keeps the split threshold."""
    def _env_int(key: str, default: int) -> int:
        raw = os.environ.get(key, "")
        return int(raw) if raw.isdigit() and int(raw) > 0 else default
    return _env_int("CGA_ETL_RANGE_MIN", 30000), _env_int("CGA_ETL_RANGE_COUNT", 0)


def do_boundary(conn, run_id: str, iso: str, options: dict,
                log: logging.Logger) -> dict:
    """One ISO's boundary chain — now a mapper-style COORDINATOR (operator
    ruling 2026-08-02: a giant country's monster level PRE-SPLITS into
    feature ranges — one per big-first lane — that enter the shared
    two-ended pile immediately, so the big half of the pool converges on
    it while the small half keeps eating countries; no drain triggers).

    Levels run in strict ADM order (parenting reads the level above). For a
    VIRGIN monster level (expected features >= split_min AND zero rows so far
    — never mixes counter-suffix slugs with range slugs), the coordinator
    enumerates boundary_range items and then PARTICIPATES: it claims ranges
    of its own level alongside every idle worker, and barriers until the
    level's ranges all settle. Any failed range fails the country into
    review (ranges stay individually requeue-able). Small levels run inline
    exactly as before."""
    import claims
    from import_geoboundaries import ADM_LEVEL_MAP

    split_min, range_count_override = _range_dials()
    pool = int(os.environ.get("CGA_ETL_POOL_SIZE", "0") or 0)
    adm_filter = {int(a) for a in (options.get("adm_levels") or [])} or None

    # Expected feature counts per file-level, from the persisted meta CSV.
    # FRESH-BOX RACE (operator catch 2026-08-02: "IND never subdivided, FRA
    # did"): the metadata table is populated by the FIRST import call — a
    # country claimed in the opening wave sees it EMPTY, decides "expected=0",
    # and silently falls back to a sequential monster pass whose hours-long
    # shared parse-lock hold then CONVOYS the whole engine behind any queued
    # exclusive. Load the meta CSV directly when the table has no row for us.
    def _load_expected() -> dict:
        with get_cursor(conn) as cur:
            cur.execute(
                "SELECT adm_level, COALESCE(adm_unit_count, 0) AS n "
                "FROM geoboundary_metadata WHERE iso_code = %s",
                (iso,),
            )
            return {int(r["adm_level"]): int(r["n"]) for r in cur.fetchall()}

    expected = _load_expected()
    if not expected:
        try:
            from import_geoboundaries import META_CSV, load_meta_index
            load_meta_index(META_CSV, conn=conn)
            expected = _load_expected()
            log.info("%s: metadata was empty — loaded the meta CSV first "
                     "(%d levels now known)", iso, len(expected))
        except Exception as exc:
            log.warning("%s: could not preload metadata (%s) — split decisions "
                        "fall back to inline for this country", iso, exc)

    total_inserted = 0
    import uuid as _uuid
    token = str(_uuid.uuid4())   # claim_token column is uuid-typed

    for file_lvl in range(6):
        if adm_filter is not None and file_lvl not in adm_filter:
            continue
        app_lvl = ADM_LEVEL_MAP[file_lvl]
        exp = expected.get(file_lvl, 0)

        with get_cursor(conn) as cur:
            cur.execute(
                "SELECT COUNT(*) AS n FROM jurisdictions "
                "WHERE iso_code = %s AND adm_level = %s AND deleted_at IS NULL",
                (iso, app_lvl),
            )
            already = int(cur.fetchone()["n"])

        remaining = exp - already
        if remaining >= split_min and pool > 1:
            # ── PRE-SPLIT (operator ruling 2026-08-02, superseding the
            # drain-triggered windows: "always split in advance where it
            # makes sense … pre split for 5 lanes where a split is needed
            # … this should chunk it without any stop start logic needed").
            #
            # A split-worthy level enumerates its ranges IMMEDIATELY — one
            # per big-first lane (half the pool) — and they join the ONE
            # two-ended pile with est_cost = feature count. The big-first
            # half of the pool converges on them at once (a monster range
            # out-costs whole mid-size countries); the small-first half
            # keeps eating countries smallest → largest. No windows, no
            # country-pile checks, no cut points: the prefix invariant
            # still holds because `already` is whatever strict prefix
            # earlier passes left in the DB (0 on a fresh run), and range
            # windows partition [already, exp) exactly. ──
            n_ranges = max(2, range_count_override or (pool // 2))
            range_size = -(-remaining // n_ranges)
            # Idempotent re-entry: a requeued coordinator whose ranges already
            # exist (any status) must NOT enumerate a second set — it goes
            # straight to participate + barrier over the existing ones.
            with get_cursor(conn) as cur:
                cur.execute(
                    """
                    SELECT COUNT(*) AS n FROM geodata_items
                     WHERE run_id = %s AND kind = 'boundary_range'
                       AND iso_code = %s AND adm_level = %s
                    """,
                    (run_id, iso, file_lvl),
                )
                ranges_exist = int(cur.fetchone()["n"]) > 0
            if ranges_exist:
                log.info("%s ADM%d: ranges already enumerated — resuming barrier",
                         iso, file_lvl)
            else:
                log.info("%s ADM%d: PRE-SPLIT — %d of %d features remain → %d ranges of %d "
                         "(prefix %d already in DB)",
                         iso, file_lvl, remaining, exp, n_ranges, range_size, already)
                rows = [
                    ("boundary_range", iso, file_lvl,
                     min(range_size, remaining - i * range_size),
                     json.dumps({"start": already + i * range_size,
                                 "count": min(range_size, remaining - i * range_size)}))
                    for i in range(n_ranges)
                ]
                import psycopg2.extras
                with get_cursor(conn) as cur:
                    psycopg2.extras.execute_values(
                        cur,
                        """
                        INSERT INTO geodata_items
                            (run_id, kind, iso_code, adm_level, status, position,
                             est_cost, metrics, created_at, updated_at)
                        VALUES %s
                        """,
                        [(run_id, k, i2, l2, "pending",
                          already + idx * range_size,   # position = ABSOLUTE start → reconstructible
                          c2, m2)
                         for idx, (k, i2, l2, c2, m2) in enumerate(rows)],
                        template="(%s,%s,%s,%s,%s,%s,%s,%s::jsonb,now(),now())",
                    )

            # Participate: claim own ranges alongside every free lane.
            while True:
                rng = claims.claim_range(conn, run_id, iso, file_lvl, token)
                if rng is None:
                    break
                meta = rng.get("metrics") or {}
                if isinstance(meta, str):
                    meta = json.loads(meta)
                start, count = int(meta["start"]), int(meta["count"])
                try:
                    n = _run_level_import(iso, file_lvl, log,
                                          feature_start=start, feature_count=count)
                    claims.record_outcome(conn, rng["id"], token, "done",
                                          metrics={"inserted": n, "start": start,
                                                   "count": count})
                    total_inserted += n
                except Exception as exc:
                    if type(exc).__name__ == "GiantFloorYield":
                        # Free the range, then yield the whole country cleanly.
                        claims.record_outcome(conn, rng["id"], token, "pending",
                                              reason="yielded: giant holds the floor")
                        raise
                    claims.record_outcome(conn, rng["id"], token, "review",
                                          reason=f"{type(exc).__name__}: {exc}",
                                          metrics={"start": start, "count": count})

            # Barrier: wait for the free lanes to finish their ranges.
            while True:
                with get_cursor(conn) as cur:
                    cur.execute(
                        """
                        SELECT COUNT(*) FILTER (WHERE status IN ('pending','running')) AS open,
                               COUNT(*) FILTER (WHERE status IN ('review','failed'))  AS bad
                          FROM geodata_items
                         WHERE run_id = %s AND kind = 'boundary_range'
                           AND iso_code = %s AND adm_level = %s
                        """,
                        (run_id, iso, file_lvl),
                    )
                    r = cur.fetchone()
                if int(r["open"]) == 0:
                    if int(r["bad"]) > 0:
                        raise RuntimeError(
                            f"ADM{file_lvl}: {int(r['bad'])} range(s) failed — "
                            "country in review; requeue the ranges (geodata:requeue "
                            f"--kind=boundary_range --iso={iso}) and then this item."
                        )
                    break
                time.sleep(5)
            log.info("%s ADM%d: all %d ranges settled", iso, file_lvl, n_ranges)
        else:
            # ── Inline (small level, partial level, or 1-worker box). ──
            total_inserted += _run_level_import(iso, file_lvl, log)

    return {"inserted": total_inserted}


def do_boundary_range(conn, item: dict, log: logging.Logger) -> dict:
    """One feature-range of one country's monster level — claimed by any free
    lane once the country pile is empty."""
    meta = item.get("metrics") or {}
    if isinstance(meta, str):
        meta = json.loads(meta)
    start, count = int(meta["start"]), int(meta["count"])
    n = _run_level_import(item["iso_code"], int(item["adm_level"]), log,
                          feature_start=start, feature_count=count)
    return {"inserted": n, "start": start, "count": count}


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


def do_raster(conn, run_id: str, iso: str, log: logging.Logger) -> dict:
    """One ISO's raster load — PRE-SPLIT coordinator (operator ruling
    2026-08-02: the raster phase gets the boundary treatment — half/half
    two-ended lanes come from the shared pile; a monster tif pre-splits into
    ROW-BAND ranges, one per big lane, that any lane can claim. Tile windows
    are random-access, so a band range pays zero skip cost)."""
    import claims
    import rasterio
    from import_worldpop import (find_worldpop_tif, load_raster_to_db,
                                 RASTER_TILE_SIZE)

    tif = find_worldpop_tif(iso)
    if tif is None:
        # Honest-empty: a fallback/absent iso has no own raster; the acceptance
        # scan flags coverage gaps (raster_absent_alias). Not a failure.
        return {"tiles": 0, "note": "no raster tif for iso"}

    split_min_tiles = int(os.environ.get("CGA_ETL_RASTER_RANGE_MIN", "") or 20000)
    _, range_count_override = _range_dials()
    pool = int(os.environ.get("CGA_ETL_POOL_SIZE", "0") or 0)

    with rasterio.open(tif) as src:                      # header-only: cheap
        n_rows = -(-src.height // RASTER_TILE_SIZE)
        n_cols = -(-src.width  // RASTER_TILE_SIZE)
    tiles_potential = n_rows * n_cols

    if tiles_potential < split_min_tiles or pool <= 1:
        tiles = load_raster_to_db(conn, iso, tif, log)   # inline, whole tif
        return {"tiles": int(tiles)}

    # ── PRE-SPLIT into row-band ranges, one per big-first lane. ──
    n_ranges  = max(2, range_count_override or (pool // 2))
    n_ranges  = min(n_ranges, n_rows)                    # can't split finer than rows
    band_size = -(-n_rows // n_ranges)
    try:
        tif_bytes = os.path.getsize(tif)
    except OSError:
        tif_bytes = 0

    with get_cursor(conn) as cur:
        cur.execute(
            """
            SELECT COUNT(*) AS n FROM geodata_items
             WHERE run_id = %s AND kind = 'raster_range' AND iso_code = %s
            """,
            (run_id, iso),
        )
        ranges_exist = int(cur.fetchone()["n"]) > 0
    if ranges_exist:
        log.info("%s raster: ranges already enumerated — resuming barrier", iso)
    else:
        log.info("%s raster: PRE-SPLIT — %d×%d tiles → %d band ranges of %d rows",
                 iso, n_rows, n_cols, n_ranges, band_size)
        # The whole-country stale-tile DELETE happens ONCE here (bands only
        # self-clean their own rows, which covers requeued ranges).
        with get_cursor(conn) as cur:
            cur.execute("DELETE FROM worldpop_rasters WHERE iso_code = %s AND year = 2023",
                        (iso,))
        items = []
        for i in range(n_ranges):
            start = i * band_size
            count = min(band_size, n_rows - start)
            if count <= 0:
                break
            items.append(
                ("raster_range", iso, None, "pending", start,
                 int(tif_bytes * count / n_rows),
                 json.dumps({"band_start": start, "band_count": count})))
        import psycopg2.extras
        with get_cursor(conn) as cur:
            psycopg2.extras.execute_values(
                cur,
                """
                INSERT INTO geodata_items
                    (run_id, kind, iso_code, adm_level, status, position,
                     est_cost, metrics, created_at, updated_at)
                VALUES %s
                """,
                [(run_id, k, i2, l2, s2, p2, c2, m2)
                 for (k, i2, l2, s2, p2, c2, m2) in items],
                template="(%s,%s,%s,%s,%s,%s,%s,%s::jsonb,now(),now())",
            )

    import uuid as _uuid
    token = str(_uuid.uuid4())
    total_tiles = 0
    # Participate: claim own band ranges alongside every free lane.
    while True:
        rng = claims.claim_range(conn, run_id, iso, None, token, kind="raster_range")
        if rng is None:
            break
        meta = rng.get("metrics") or {}
        if isinstance(meta, str):
            meta = json.loads(meta)
        bs, bc = int(meta["band_start"]), int(meta["band_count"])
        try:
            n = load_raster_to_db(conn, iso, tif, log, band_start=bs, band_count=bc)
            claims.record_outcome(conn, rng["id"], token, "done",
                                  metrics={"tiles": int(n)})
            total_tiles += int(n)
        except Exception as exc:
            claims.record_outcome(conn, rng["id"], token, "review",
                                  reason=f"{type(exc).__name__}: {exc}")

    # Barrier: wait for the other lanes' bands to settle.
    while True:
        with get_cursor(conn) as cur:
            cur.execute(
                """
                SELECT COUNT(*) FILTER (WHERE status IN ('pending','running')) AS open,
                       COUNT(*) FILTER (WHERE status IN ('review','failed'))  AS bad,
                       COALESCE(SUM((metrics->>'tiles')::bigint)
                                FILTER (WHERE status = 'done'), 0)            AS tiles
                  FROM geodata_items
                 WHERE run_id = %s AND kind = 'raster_range' AND iso_code = %s
                """,
                (run_id, iso),
            )
            r = cur.fetchone()
        if int(r["open"]) == 0:
            if int(r["bad"]) > 0:
                raise RuntimeError(
                    f"raster: {int(r['bad'])} band range(s) failed — requeue "
                    f"(geodata:requeue --kind=raster_range --iso={iso}) and then this item."
                )
            total_tiles = int(r["tiles"])
            break
        time.sleep(5)
    log.info("%s raster: all band ranges settled (%d tiles)", iso, total_tiles)
    return {"tiles": total_tiles}


def do_raster_range(conn, item: dict, log: logging.Logger) -> dict:
    """One row-band of one country's raster — claimed by any free lane."""
    from import_worldpop import find_worldpop_tif, load_raster_to_db
    meta = item.get("metrics") or {}
    if isinstance(meta, str):
        meta = json.loads(meta)
    bs, bc = int(meta["band_start"]), int(meta["band_count"])
    tif = find_worldpop_tif(item["iso_code"])
    if tif is None:
        return {"tiles": 0, "note": "no raster tif for iso"}
    n = load_raster_to_db(conn, item["iso_code"], tif, log,
                          band_start=bs, band_count=bc)
    return {"tiles": int(n)}


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
                metrics = do_boundary(conn, args.run, item["iso_code"], options, log)
            elif kind == "boundary_range":
                metrics = do_boundary_range(conn, item, log)
            elif kind == "resolve_global":
                metrics = do_resolve(conn, args.run, options, log)
            elif kind == "raster_iso":
                metrics = do_raster(conn, args.run, item["iso_code"], log)
            elif kind == "raster_range":
                metrics = do_raster_range(conn, item, log)
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
            # A yield is not a failure: the giant holds the parse floor, so
            # this child exits FREE and its item requeues for after the turn.
            if type(exc).__name__ == "GiantFloorYield":
                print(json.dumps({
                    "ok": True, "status": "pending",
                    "reason": f"yielded: {exc}",
                }))
                return 0
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
