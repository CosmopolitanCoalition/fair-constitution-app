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
import psycopg2   # QueryCanceled, for the collapse pass's per-batch timeout
import subprocess
import sys
import time
import traceback

sys.path.insert(0, "/etl")

import heartbeat  # noqa: E402
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

    # ACCEPTANCE SCAN OFF BY DEFAULT (2026-08-04, operator: "turn off these
    # acceptance scans, obviously it's fucking busted"). The six detectors
    # open with planet-wide MATERIALIZED CTEs that repeatedly took postgres
    # backends down (signal 9) and, before the liveness markers landed,
    # thrashed a run for 4h17m without finishing. The scan is a data-QUALITY
    # report, not part of producing the world: ingest, resolve, attribution
    # and finalize are complete and correct without it, so a run should reach
    # `done` rather than park behind it.
    #
    # The scan remains fully available on demand — it was an operator-run
    # console tool for three weeks before it became a phase (2026-08-01) —
    # and CGA_ETL_ACCEPTANCE_SCAN=1 restores it to the pipeline. Nothing
    # about the detectors themselves is removed.
    if os.environ.get("CGA_ETL_ACCEPTANCE_SCAN", "1") != "0":
        rows.append(("acceptance_scan", None, None, 0))
    else:
        log.info("manifest: acceptance scan DISABLED "
                 "(CGA_ETL_ACCEPTANCE_SCAN=0 was set)")

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

    # ONLY THE LEVELS THIS COUNTRY ACTUALLY HAS (operator ruling 2026-08-04).
    # This walked 0..5 unconditionally, so a country like MYT — which has ADM0
    # and ADM4 and nothing between — paid four full import calls that could
    # only ever insert zero rows, and each of those calls re-stat'd the entire
    # 715-entry gbOpen tree (11 s a call on the bind mount). 678 absent levels
    # planet-wide, ~2 h of aggregate lane time producing nothing.
    #
    # Gated on the FILESYSTEM, never on geoboundary_metadata: the meta CSV is
    # documented-incomplete (IND-ADM0, PRI-ADM0 have files but no CSV row), so
    # trusting it here would skip real levels of real countries. `expected`
    # stays what it was — a size hint for the split decision, not a existence
    # oracle. Falls back to all six if discovery comes back empty, so a
    # mis-pointed GBOPEN_ROOT still behaves exactly as before rather than
    # silently importing nothing.
    from import_geoboundaries import available_adm_levels

    present = available_adm_levels(iso)
    if not present:
        log.warning("%s: no files discovered under GBOPEN_ROOT — walking all "
                    "six levels as before", iso)
        present = set(range(6))

    levels = [l for l in range(6)
              if l in present
              and (adm_filter is None or l in adm_filter)]

    skipped = [l for l in range(6) if l not in present]
    if skipped:
        log.info("%s: levels %s absent on disk — skipped (was %d wasted "
                 "import calls)", iso, skipped, len(skipped))

    def _db_count(app_lvl: int) -> int:
        with get_cursor(conn) as cur:
            cur.execute(
                "SELECT COUNT(*) AS n FROM jurisdictions "
                "WHERE iso_code = %s AND adm_level = %s AND deleted_at IS NULL",
                (iso, app_lvl),
            )
            return int(cur.fetchone()["n"])

    # ── Pass 1: PRE-SPLIT every split-worthy level NOW (operator rulings
    # 2026-08-02: "always split in advance where it makes sense … without
    # any stop start logic", and — parenting deferred to the resolve
    # barrier — "thread everything where splitting is possible"). With the
    # inline ladder gone, ADM levels are INDEPENDENT: every monster level
    # of this country enters the shared two-ended pile at claim time, one
    # range per big-first lane, est_cost = feature count. The big half of
    # the pool converges on them immediately while this coordinator
    # streams the small levels inline. The prefix invariant holds per
    # level: `already` is whatever strict prefix earlier passes left in
    # the DB (0 on a fresh run); range windows partition [already, exp). ──
    split_levels: list[int] = []
    for file_lvl in levels:
        exp = expected.get(file_lvl, 0)
        if exp < split_min or pool <= 1:
            continue
        # Idempotent re-entry: ranges that already exist (any status) mean
        # this level pre-split before — never enumerate a second set.
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
            log.info("%s ADM%d: ranges already enumerated — will resume barrier",
                     iso, file_lvl)
            split_levels.append(file_lvl)
            continue

        already   = _db_count(ADM_LEVEL_MAP[file_lvl])
        remaining = exp - already
        if remaining < split_min:
            continue   # tail smaller than a split's worth — inline below
        n_ranges   = max(2, range_count_override or (pool // 2))
        range_size = -(-remaining // n_ranges)
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
        split_levels.append(file_lvl)

    # ── Pass 2: inline walk of the non-split levels. 0→5 order is kept for
    # the ADM0→ADM1 parent_map; with parenting deferred these are pure
    # parse+insert — no per-feature ladder round-trips. ──
    for file_lvl in levels:
        if file_lvl in split_levels:
            continue
        total_inserted += _run_level_import(iso, file_lvl, log)

    # ── Pass 3: participate in this country's own ranges (all split levels),
    # then ONE barrier over every range of this iso. ──
    for file_lvl in split_levels:
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

    if split_levels:
        while True:
            with get_cursor(conn) as cur:
                cur.execute(
                    """
                    SELECT COUNT(*) FILTER (WHERE status IN ('pending','running')) AS open,
                           COUNT(*) FILTER (WHERE status = 'pending')             AS pend,
                           COUNT(*) FILTER (WHERE status IN ('review','failed'))  AS bad
                      FROM geodata_items
                     WHERE run_id = %s AND kind = 'boundary_range'
                       AND iso_code = %s
                    """,
                    (run_id, iso),
                )
                r = cur.fetchone()
            if int(r["open"]) == 0:
                if int(r["bad"]) > 0:
                    raise RuntimeError(
                        f"{int(r['bad'])} range(s) failed across ADM levels "
                        f"{split_levels} — country in review; requeue the ranges "
                        f"(geodata:requeue --kind=boundary_range --iso={iso}) "
                        "and then this item."
                    )
                break
            if int(r["pend"]) > 0:
                # A range bounced back to pending (worker death + pump
                # reclaim) while we were already waiting — re-participate
                # instead of watching it starve.
                for file_lvl in split_levels:
                    rng = claims.claim_range(conn, run_id, iso, file_lvl, token)
                    if rng is None:
                        continue
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
                            claims.record_outcome(conn, rng["id"], token, "pending",
                                                  reason="yielded: giant holds the floor")
                            raise
                        claims.record_outcome(conn, rng["id"], token, "review",
                                              reason=f"{type(exc).__name__}: {exc}",
                                              metrics={"start": start, "count": count})
                continue
            time.sleep(5)
        log.info("%s: all ranges settled across ADM levels %s", iso, split_levels)

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


def _resolve_iso_fanout(conn, run_id: str, log: logging.Logger):
    """RESOLVE FAN-OUT (2026-08-03) — the per-iso orphan ladder, spread
    across every LANE instead of an in-process thread pool.

    Resolve was ONE claimable item: internally threaded, but bounded by
    _resolve_worker_count() (<= 6) inside a single lane, so the other nine
    had nothing — measured ~30-54 minutes of near-idle field per run. It was
    also the opacity the operator called out: the bar ticked only when a
    whole country finished, and the giants' set-based strategy UPDATEs are
    invisible until commit, so the panel moved three times in half an hour.

    Same coordinator/range shape as boundary_range and attribution_range:
    enumerate per-country resolve_range children (idempotent), participate
    alongside free lanes, barrier, aggregate. Each child is its own worker
    strip with elapsed time, and the countries bar ticks per settled child
    from the DB — visibility falls out of the mechanism."""
    import claims

    def _runner(isos: list[str]) -> dict:
        token = str(__import__("uuid").uuid4())

        # est_cost = the country's orphan count, so the two-ended queue puts
        # the big chains on the big lanes immediately.
        with get_cursor(conn) as cur:
            cur.execute(
                """
                SELECT iso_code, COUNT(*) AS n FROM jurisdictions
                 WHERE parent_id IS NULL AND adm_level BETWEEN 2 AND 6
                   AND deleted_at IS NULL AND iso_code = ANY(%s)
                 GROUP BY iso_code
                """,
                (list(isos),),
            )
            cost = {r["iso_code"]: int(r["n"]) for r in cur.fetchall()}

        with get_cursor(conn) as cur:
            cur.execute(
                "SELECT COUNT(*) AS n FROM geodata_items "
                " WHERE run_id=%s AND kind='resolve_range'", (run_id,))
            already = int(cur.fetchone()["n"]) > 0
        if already:
            log.info("resolve: range items already enumerated — resuming")
        else:
            _insert_items(conn, run_id,
                          [("resolve_range", iso, None, cost.get(iso, 1))
                           for iso in isos])
            log.info("resolve: enumerated %d per-iso range items", len(isos))

        total = len(isos)
        # Seed the coordinator's own lane with a real denominator so
        # resolve_global reads "X / N countries" instead of an indeterminate
        # pulse (bar_start in quiet mode primes the item bar's total; the
        # bar_update calls below then tick it as children settle).
        heartbeat.bar_start("resolve:isos", "resolving parent chains",
                            total=total, unit="countries")

        def _settled() -> int:
            """Children finished by ANYONE — the coordinator and every free
            lane. Counting only our own completions would under-report the
            bar by exactly the parallelism this fan-out exists to add."""
            with get_cursor(conn) as cur:
                cur.execute(
                    "SELECT COUNT(*) AS n FROM geodata_items "
                    " WHERE run_id=%s AND kind='resolve_range' "
                    "   AND status NOT IN ('pending','running')", (run_id,))
                return int(cur.fetchone()["n"])

        def _run_one(rng) -> None:
            from import_geoboundaries import _resolve_orphans_for_iso
            iso = rng["iso_code"]
            try:
                # bar_item_id = the child's own id (not resolve_global's) so the
                # bar lands on the right lane even when the coordinator runs it.
                c = _resolve_orphans_for_iso(iso, log, bar_item_id=rng["id"])
                claims.record_outcome(conn, rng["id"], token, "done", metrics=c)
            except Exception as exc:
                log.error("resolve %s failed: %s", iso, exc)
                claims.record_outcome(conn, rng["id"], token, "review",
                                      reason=f"{type(exc).__name__}: {exc}")
            heartbeat.bar_update("resolve:isos", _settled())

        # Participate, then barrier with re-participation (a child that
        # bounces back to pending is picked up here instead of starving).
        while True:
            rng = claims.claim_range(conn, run_id, None, None, token,
                                     kind="resolve_range")
            if rng is None:
                break
            _run_one(rng)

        while True:
            with get_cursor(conn) as cur:
                cur.execute(
                    """
                    SELECT COUNT(*) FILTER (WHERE status IN ('pending','running')) AS open,
                           COUNT(*) FILTER (WHERE status = 'pending')             AS pend
                      FROM geodata_items
                     WHERE run_id = %s AND kind = 'resolve_range'
                    """,
                    (run_id,),
                )
                r = cur.fetchone()
            if int(r["open"]) == 0:
                break
            if int(r["pend"]) > 0:
                rng = claims.claim_range(conn, run_id, None, None, token,
                                         kind="resolve_range")
                if rng is not None:
                    _run_one(rng)
                    continue
            # Waiting on OTHER lanes' children — keep the bar honest while
            # we block, or the panel looks frozen for the whole tail.
            heartbeat.bar_update("resolve:isos", _settled())
            time.sleep(3)

        # Aggregate the children's per-strategy counts.
        agg: dict = {}
        with get_cursor(conn) as cur:
            cur.execute(
                "SELECT metrics FROM geodata_items "
                " WHERE run_id=%s AND kind='resolve_range' AND status='done'",
                (run_id,))
            for row in cur.fetchall():
                m = row["metrics"] or {}
                if isinstance(m, str):
                    m = json.loads(m)
                for k, v in m.items():
                    if isinstance(v, int):
                        agg[k] = agg.get(k, 0) + v
        heartbeat.bar_update("resolve:isos", total)
        return agg

    return _runner


def do_resolve_range(conn, item: dict, log: logging.Logger) -> dict:
    """One country's orphan ladder — claimed by any free lane while the
    resolve coordinator holds the barrier."""
    from import_geoboundaries import _resolve_orphans_for_iso
    # Pass our own item id so the ladder writes a live orphan-drain bar onto
    # this lane — a giant like IND now shows a filling bar, not a dead slice.
    return _resolve_orphans_for_iso(item["iso_code"], log, bar_item_id=item["id"])


def _enumerate_attribution_pairs(conn, run_id: str, options: dict,
                                 log: logging.Logger) -> int:
    """Enumerate the attribution pairs from the DB. Idempotent — a resume
    (or the finalize-side safety call) never duplicates the pile."""
    from run_t7_orchestrator import enumerate_iso_levels

    with get_cursor(conn) as cur:
        cur.execute(
            "SELECT COUNT(*) AS n FROM geodata_items "
            " WHERE run_id=%s AND kind='attribution_pair'", (run_id,))
        if int(cur.fetchone()["n"]) > 0:
            return 0

    pairs = enumerate_iso_levels(conn)  # [(iso, level, npolys)] — DB-derived
    countries = {c.upper() for c in (options.get("countries") or [])}
    if countries:
        pairs = [p for p in pairs if p[0] in countries]

    # A COUNTRY WITH NO RASTER IS NOT AN ATTRIBUTION PAIR (2026-08-04,
    # operator: "then there is nothing to review then for ATA eh?").
    # Antarctica has no WorldPop tile — there is no resident population to
    # model — so its pair could only ever fail with "no rasters", land in
    # review, and sit there through every retry of every future run. That is
    # a permanent false positive in a list whose entire job is "things a
    # human should look at."
    #
    # Population is left NULL rather than set to 0: the honest statement is
    # "no source data", not "measured zero". The acceptance scan's
    # raster_coverage detector already reports isos without tiles, which is
    # the right surface for it.
    with get_cursor(conn) as cur:
        cur.execute("SELECT DISTINCT iso_code FROM worldpop_rasters")
        have_raster = {r["iso_code"] for r in cur.fetchall()}
    skipped = sorted({p[0] for p in pairs if p[0] not in have_raster})
    if skipped:
        pairs = [p for p in pairs if p[0] in have_raster]
        log.info("attribution: skipping %d iso(s) with no raster tiles — %s "
                 "(no source data; raster_coverage flags them)",
                 len(skipped), ", ".join(skipped))

    # est_cost = LEVEL VERTEX WEIGHT, not polygon count (2026-08-02, the
    # attribution starvation: weight and npolys ANTI-correlate — AUS L2 is
    # 1.8M vertices across ~1.4k polygons, so the small-first lanes kept
    # "cheapest-first" claiming unstartable heavies while true lights
    # starved mid-pile). Weight is what the giant-pair floor gates on, so
    # cost and admission now speak the same unit.
    with get_cursor(conn) as cur:
        cur.execute("SELECT iso_code, adm_level, "
                    "GREATEST(1,(COALESCE(mean_vertices,0)*COALESCE(adm_unit_count,0))::bigint) AS w "
                    "FROM geoboundary_metadata")
        weight = {(r["iso_code"], int(r["adm_level"])): int(r["w"]) for r in cur.fetchall()}
    rows = [("attribution_pair", iso, level,
             weight.get((iso, level - 1), int(npolys)))
            for iso, level, npolys in pairs]
    _insert_items(conn, run_id, rows)
    return len(rows)


def do_resolve(conn, run_id: str, options: dict, log: logging.Logger) -> dict:
    """The barrier after all boundaries: run the global passes (Earth +
    synthesize + orphan resolution + cross-ISO) with the per-iso ladder
    fanned out to resolve_range items on every lane.

    EARLY ATTRIBUTION (2026-08-03, operator: attribution plays nice with
    resolve so the lanes are taken). Attribution's math never reads
    parent_id — it needs geometry (boundaries, complete at this barrier),
    rasters (complete), and the L1 baseline (stamped below). So the pairs
    are enumerated FIRST and the resolving phase's claim fallthrough lets
    idle lanes attribute while the resolve ladders grind — the field stays
    full instead of eight lanes watching IND for twenty minutes.

    The one thing resolve can do that invalidates an early pair: the
    cross-ISO/phase-S passes may INSERT synthesized intermediary rows into a
    level that already attributed. Those pairs are requeued at the end —
    idempotent by design, and the redo set is measured from created_at, not
    guessed."""
    import datetime as _dt
    from import_geoboundaries import import_geoboundaries

    # PRE-ENUMERATE the per-country resolve ranges before anything else
    # (2026-08-03, operator: "it took a couple minutes for the individual
    # lanes to come on line"). The ranges used to be enumerated inside the
    # global passes, AFTER the baseline stamp ground its raster stats — so
    # the field sat idle for exactly that long at phase entry. The orphan
    # census is one cheap GROUP BY; lanes start claiming countries within
    # seconds, and the fan-out runner sees them and resumes.
    with get_cursor(conn) as cur:
        cur.execute(
            "SELECT COUNT(*) AS n FROM geodata_items "
            " WHERE run_id=%s AND kind='resolve_range'", (run_id,))
        if int(cur.fetchone()["n"]) == 0:
            cur.execute(
                """
                SELECT iso_code, COUNT(*) AS n FROM jurisdictions
                 WHERE parent_id IS NULL AND adm_level BETWEEN 2 AND 6
                   AND deleted_at IS NULL AND iso_code IS NOT NULL
                 GROUP BY iso_code
                """)
            census = [(r["iso_code"], int(r["n"])) for r in cur.fetchall()]
            if census:
                _insert_items(conn, run_id,
                              [("resolve_range", iso, None, n) for iso, n in census])
                log.info("resolve_global: pre-enumerated %d per-country ranges "
                         "— lanes start immediately", len(census))

    # Baselines BEFORE anything verdicts (the 59-of-232 lesson): every L1
    # row exists at this barrier and every tile is loaded.
    _stamp_missing_baselines(conn, log)

    n_pairs = _enumerate_attribution_pairs(conn, run_id, options, log)
    with get_cursor(conn) as cur:
        cur.execute("SELECT now() AS t")
        t0 = cur.fetchone()["t"]
    log.info("resolve_global: %d attribution pairs enumerated EARLY — idle "
             "lanes attribute while the ladders run", n_pairs)

    import_geoboundaries(global_passes_only=True, log=log,
                         resolve_iso_runner=_resolve_iso_fanout(conn, run_id, log))

    # Requeue any pair whose (iso, level) gained rows during the global
    # passes (synthesized intermediaries) — attributed before those rows
    # existed, its numbers are stale for exactly that level.
    with get_cursor(conn) as cur:
        cur.execute(
            """
            UPDATE geodata_items gi
               SET status='pending', claim_token=NULL, reason=NULL,
                   started_at=NULL, finished_at=NULL, updated_at=now()
              FROM (SELECT DISTINCT iso_code, adm_level FROM jurisdictions
                     WHERE created_at > %s AND deleted_at IS NULL
                       AND adm_level BETWEEN 2 AND 6) fresh
             WHERE gi.run_id=%s AND gi.kind='attribution_pair'
               AND gi.status IN ('done','review','failed')
               AND gi.iso_code=fresh.iso_code AND gi.adm_level=fresh.adm_level
            """,
            (t0, run_id))
        redo = cur.rowcount
    if redo:
        log.info("resolve_global: requeued %d pair(s) whose level gained "
                 "synthesized rows during the global passes", redo)
    return {"attribution_pairs": n_pairs, "pairs_requeued": redo}


def _stamp_national_baseline(conn, iso: str, log: logging.Logger) -> None:
    """Write the iso's L1 population_baseline = its raster tile sum — THE
    fresh-run verdict anchor (2026-08-02: all 481 pairs of the first fresh
    run verdicted no_l1 because baseline was a dev-era artifact comparing
    T.7 against the LEGACY world's populations; a virgin world has none).
    Independent of every level's partition, which is exactly what the
    exact/near/partial/far check needs. Set-based, one iso, idempotent."""
    try:
        with get_cursor(conn) as cur:
            cur.execute(
                """
                UPDATE jurisdictions j
                   SET population_baseline = s.pop, updated_at = now()
                  FROM (SELECT SUM((ST_SummaryStats(rast)).sum)::bigint AS pop
                          FROM worldpop_rasters WHERE iso_code = %s) s
                 WHERE j.iso_code = %s AND j.adm_level = 1
                   AND j.deleted_at IS NULL AND s.pop IS NOT NULL
                """,
                (iso, iso),
            )
    except Exception as exc:
        log.warning("%s: national baseline stamp failed (%s) — verdicts for "
                    "this iso will read no_l1", iso, exc)


def _stamp_missing_baselines(conn, log: logging.Logger) -> int:
    """Set-based catch-all for the per-iso stamp above (2026-08-03, the
    59-of-232 baseline gap): the raster-phase stamp UPDATEs the iso's L1 row
    — and under the boundary/raster OVERLAP most rasters finish BEFORE their
    country's L1 row exists, so the UPDATE matches zero rows and vanishes
    silently. The previous run dodged this only because the PRI stall kept
    boundary lanes busy (almost no rasters ran early); the speedups exposed
    it: 173 countries with loaded tiles and no baseline, 345 pairs
    verdicting no_l1, Earth rolling up 2.7B from the 59 that had one.

    One statement over every iso that has tiles but no baseline. Runs at the
    resolve barrier (before pairs, so verdicts anchor) and again at finalize
    (so the L1 copy + planet rollup can never see a gap). Idempotent."""
    with get_cursor(conn) as cur:
        cur.execute(
            """
            UPDATE jurisdictions j
               SET population_baseline = s.pop, updated_at = now()
              FROM (SELECT iso_code, SUM((ST_SummaryStats(rast)).sum)::bigint AS pop
                      FROM worldpop_rasters GROUP BY iso_code) s
             WHERE j.iso_code = s.iso_code AND j.adm_level = 1
               AND j.deleted_at IS NULL AND s.pop IS NOT NULL
               AND COALESCE(j.population_baseline, 0) = 0
            """
        )
        n = cur.rowcount
    conn.commit()
    if n:
        log.info("baseline catch-all: stamped %d countries the raster-phase "
                 "stamp missed (L1 row did not exist yet under the overlap)", n)
    return n


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
        _stamp_national_baseline(conn, iso, log)
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
    _stamp_national_baseline(conn, iso, log)
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


def do_attribution(conn, run_id: str, iso: str, level: int, apply_to_db: bool,
                   log: logging.Logger, item_metrics: dict | None = None) -> dict:
    """Run the T.7 pair in its own subprocess (run_t7_pair.py — already the
    memory-isolated pair worker) and relay its JSON verdict. A 'far' verdict is
    NOT a failure (the scan flags data quality); only a hard error is review.

    THREE SHAPES, NOT ONE (2026-08-03, operator ruling after run 019fc460
    killed CAN L2 and IND L6 at exit -9). The single dial
    `mean_vertices × adm_unit_count` CONFLATES two OPPOSITE problems, and
    sending both to the same remedy regressed one of them:

      * VERTEX MONSTER — few features, enormous rings (CAN L2 = 13 features
        × 1,269,933 mean vertices; PHL L2 = 17 × 767,415). Grid
        decomposition IS the remedy here: O(V log W) is precisely what
        ended CAN L2's DNF/OOM history and landed it exact in 163 s. It
        must NEVER be window-split — a slice runs the LEGACY per-window
        attribute() (only that function takes window_slice), which is the
        O(windows × vertices) cost class grid decomposition exists to
        delete. What a monster needs is FUNDING, not slicing: an
        irreducible single-feature parse peak (Nunavut ≈ 800 MB) cannot be
        batched smaller, only given room. So: grid, holding the
        cga_giant_parse floor EXCLUSIVELY — the container to itself.

      * FEATURE SWARM — hundreds of thousands of tiny features (IND L6 =
        649,771 features × 34 mean vertices). Bisecting a 34-vertex ring
        buys nothing; the cost is the sheer COUNT, and the remedy is LANES.
        This is the shape that always worked well: the coverage-guarded
        window-split + attribution_partials coordinator, many lanes at once.

      * EVERYTHING ELSE (~480 pairs) — grid decomposition in a lane,
        width-capped by claims.kind_cap. Unchanged, proven across this run.

    The two dials are separate because the axes are independent (file level
    = app − 1). Defaults come from this run's field data, where the classes
    separate by four orders of magnitude — mean_vertices 1.27M/767k
    (monsters) vs 34/206/413 (swarms); adm_unit_count 13/17 (monsters) vs
    649,771/35,010 (swarms) — so neither default sits near a real value."""
    from import_geoboundaries import GiantFloorYield

    with get_cursor(conn) as cur:
        cur.execute(
            "SELECT COALESCE(mean_vertices,0) AS mv, COALESCE(adm_unit_count,0) AS n "
            "FROM geoboundary_metadata WHERE iso_code=%s AND adm_level=%s",
            (iso, level - 1),
        )
        row = cur.fetchone()
        mean_v = int(row["mv"]) if row else 0
        unit_n = int(row["n"]) if row else 0
    monster_mean = int(os.environ.get("CGA_ETL_MONSTER_MEAN_VERTICES", "0") or 0) or 500_000
    swarm_count = int(os.environ.get("CGA_ETL_SWARM_FEATURES", "0") or 0) or 100_000
    is_monster = mean_v >= monster_mean

    # REVERTED TO THE METHOD THAT WORKED (2026-08-03, operator order: "go
    # back to the way that worked").
    #
    # Splitting is decided by FEATURE COUNT alone, exactly as it was on the
    # run whose attribution the operator called good. My weight-based split
    # (1141d3f) and its min-units patch both made things worse: they pulled
    # 76 then 54 pairs into slicing that had always run whole, which is where
    # every OOM and every extra review item came from. Weight is not the
    # signal — a band divides work only when there are MANY FEATURES to
    # divide among bands, and unit_n is that measure directly.
    #
    # Result: only genuine swarms split (IND L6 at 649,771 units). Everything
    # else — including every heavy coastal level — runs whole, funded, as it
    # did on the good run.
    is_swarm = (not is_monster) and unit_n >= swarm_count

    # ── FEATURE SWARM → window-split, many lanes ──
    if is_swarm:
        # Resume identity: existing ranges PINNED a window_px, so enumerate
        # at that same px — otherwise the coverage guard compares counts
        # taken from two different grids and refuses a legitimate merge
        # (9,436 windows at 1024 px vs 2,418 at 2048 px — caught before it
        # could reject a completed pair).
        with get_cursor(conn) as cur:
            cur.execute(
                "SELECT MIN((metrics->>'window_px')::int) AS px FROM geodata_items "
                " WHERE run_id=%s AND kind='attribution_range' "
                "   AND iso_code=%s AND adm_level=%s",
                (run_id, iso, level),
            )
            r = cur.fetchone()
            pinned_px = int(r["px"]) if r and r["px"] else None
        enum_cmd = ["python3", PAIR_SCRIPT, iso, str(level), "--enumerate"]
        if pinned_px:
            enum_cmd += ["--window-px", str(pinned_px)]
        enum_proc = subprocess.run(enum_cmd, capture_output=True, text=True, check=False)
        enum_payload = None
        for line in reversed((enum_proc.stdout or "").strip().splitlines()):
            try:
                enum_payload = json.loads(line)
                break
            except ValueError:
                continue
        if enum_payload is None or not enum_payload.get("ok"):
            raise RuntimeError(
                "swarm pair --enumerate produced no JSON (exit "
                f"{enum_proc.returncode}): {(enum_proc.stderr or '')[-400:]}"
            )
        log.info("%s L%d attribution: FEATURE SWARM (%s features x %s mean "
                 "vertices) — window-split across lanes, %d windows @ %dpx",
                 iso, level, f"{unit_n:,}", f"{mean_v:,}",
                 int(enum_payload["n_windows"]), int(enum_payload["window_px"]))
        pool = int(os.environ.get("CGA_ETL_POOL_SIZE", "0") or 0) or 10
        result = _attribution_window_split(
            conn, run_id, iso, level, apply_to_db,
            n_windows=int(enum_payload["n_windows"]),
            window_px=int(enum_payload["window_px"]),
            pool=pool, log=log,
            raster_isos=enum_payload.get("rasters"),
            mean_v=mean_v, unit_n=unit_n,
        )
        return {k: result.get(k) for k in
                ("verdict", "n_polys", "post_sum", "l1_pop", "post_dev", "applied_rows")}

    # ── VERTEX MONSTER → grid decomposition, floor held EXCLUSIVELY ──
    # The floor lives on a DEDICATED connection whose close IS the release
    # (gate v2: deterministic even on kill -9, immune to shared-conn unlock
    # paths). A light pair never blocks on it — only monsters take it, and a
    # monster that finds it held yields free, whereupon the worker's
    # post-yield skip-list moves that lane onto other work.
    legacy_mode = os.environ.get("CGA_T7_FAST", "1") == "0"
    floor_conn = get_connection() if (legacy_mode or is_monster) else None
    try:
        if is_monster:
            with get_cursor(floor_conn) as cur:
                cur.execute("SELECT pg_try_advisory_lock(hashtext('cga_giant_parse')) AS got")
                if not bool(cur.fetchone()["got"]):
                    raise GiantFloorYield(
                        f"{iso} L{level} attribution: the floor is held")
            log.info("%s L%d attribution: VERTEX MONSTER (%s mean vertices x %s "
                     "features) — grid decomposition, EXCLUSIVE floor",
                     iso, level, f"{mean_v:,}", f"{unit_n:,}")
        elif legacy_mode:
            with get_cursor(floor_conn) as cur:
                cur.execute("SELECT pg_try_advisory_lock_shared(hashtext('cga_giant_parse')) AS got")
                if not bool(cur.fetchone()["got"]):
                    raise GiantFloorYield(
                        f"{iso} L{level} attribution: a giant holds the floor")

        cmd = ["python3", PAIR_SCRIPT, iso, str(level)]
        if apply_to_db:
            cmd.append("--apply")
        proc = subprocess.run(cmd, capture_output=True, text=True, check=False)
    finally:
        if floor_conn is not None:
            try:
                floor_conn.close()   # session locks die with the conn
            except Exception:
                pass

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


def _attribution_window_split(conn, run_id: str, iso: str, level: int,
                              apply_to_db: bool, n_windows: int,
                              window_px: int, pool: int,
                              log: logging.Logger,
                              raster_isos: list | None = None,
                              mean_v: int = 0, unit_n: int = 0) -> dict:
    """Coordinator for a window-split pair: enumerate attribution_range
    slices (idempotent), participate alongside every free lane, barrier,
    then merge partials set-based, verdict, apply once, clean up.

    mean_v/unit_n are the caller's already-fetched geometry census — the
    slice sizer needs them and this function has no other access to them
    (a splice that assumed otherwise cost IND L6 an entire run)."""
    import claims
    _, range_count_override = _range_dials()
    token = str(__import__("uuid").uuid4())
    raster_isos = raster_isos or [iso]

    with get_cursor(conn) as cur:
        cur.execute(
            """
            SELECT COUNT(*) AS n,
                   MIN((metrics->>'window_px')::int) AS px
              FROM geodata_items
             WHERE run_id = %s AND kind = 'attribution_range'
               AND iso_code = %s AND adm_level = %s
            """,
            (run_id, iso, level),
        )
        r = cur.fetchone()
        ranges_exist, pinned_px = int(r["n"]) > 0, r["px"]
    if ranges_exist:
        window_px = int(pinned_px or window_px)   # sequence identity wins
        log.info("%s L%d attribution: window ranges already enumerated — resuming",
                 iso, level)
    else:
        # SLICE SIZE IS A MEMORY QUESTION, NOT A LANE-COUNT QUESTION
        # (2026-08-03, operator: "number of cores is irrelevant to the number
        # of slices. It's about how big the raster is that needs to be split
        # to avoid crashing. How many windows can fit per core is the real
        # test. That number is the size of your slice.").
        #
        # Every previous rule keyed off the pool -- pool//2, pool*4, pool*2 --
        # and every one was wrong in the same way: the pool says how many
        # slices can run AT ONCE, which has nothing to do with how big a slice
        # may safely BE. Slices are a claimable pile, so the count never
        # forces concurrency; only the per-slice footprint can crash a worker.
        #
        # A slice's residency is its BAND'S GEOMETRY plus a fixed floor (the
        # interpreter, numpy/rasterio/shapely, and one window buffer --
        # measured ~90 MB at rest, ~11 MB for a 1024px window). So:
        #
        #     slices = ceil(whole-pair geometry / geometry a worker can hold)
        #
        # and the windows per slice fall out of that. A pair whose geometry
        # already fits gets ONE slice (i.e. it may as well run whole); a pair
        # ten times too big gets ten. The budget is the CHILD'S slice of the
        # container, which worker.py already sized against live concurrency,
        # so this scales from a Pi to a workstation with no dial.
        try:
            from memory_budget import etl_budget_bytes
            budget = etl_budget_bytes()
        except Exception:
            budget = 512 * 1024 * 1024
        floor_b   = int(os.environ.get("CGA_ETL_SLICE_FLOOR_BYTES", "0") or 0) or 100 * 1024 * 1024
        per_vert  = int(os.environ.get("CGA_ETL_BYTES_PER_VERTEX", "0") or 0) or 32
        usable    = max(32 * 1024 * 1024, budget - floor_b)
        geom_b    = max(1, mean_v * unit_n * per_vert)
        k = range_count_override or -(-geom_b // usable)
        k = max(1, min(int(k), n_windows))
        log.info("%s L%d slice sizing: geometry ~%.0f MB / usable ~%.0f MB "
                 "-> %d slice(s)", iso, level, geom_b / 1048576, usable / 1048576, k)
        size = -(-n_windows // k)
        log.info("%s L%d attribution: WINDOW-SPLIT — %d windows → %d slices of %d "
                 "(window_px=%d pinned)", iso, level, n_windows, k, size, window_px)
        rows = []
        for i in range(k):
            start = i * size
            count = min(size, n_windows - start)
            if count <= 0:
                break
            rows.append((run_id, "attribution_range", iso, level, "pending",
                         start, count,
                         json.dumps({"win_start": start, "win_count": count,
                                     "window_px": window_px,
                                     "rasters": raster_isos})))
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
                rows,
                template="(%s,%s,%s,%s,%s,%s,%s,%s::jsonb,now(),now())",
            )

    def _run_slice(rng) -> None:
        meta = rng.get("metrics") or {}
        if isinstance(meta, str):
            meta = json.loads(meta)
        cmd = ["python3", PAIR_SCRIPT, iso, str(level),
               "--win-start", str(int(meta["win_start"])),
               "--win-count", str(int(meta["win_count"])),
               "--window-px", str(int(meta.get("window_px") or window_px)),
               "--run-id", run_id]
        if meta.get("rasters"):
            cmd += ["--rasters", ",".join(meta["rasters"])]
        # The slice's live bar must land on ITS OWN range row — without this
        # a coordinator-run slice inherits the coordinator's env and writes
        # its bar onto the PAIR row while the range row sits at zero
        # (observed live: CAN slices "dead" at bar 0 while burning CPU).
        slice_env = {**os.environ, "CGA_ETL_ITEM_ID": rng["id"]}
        proc = subprocess.run(cmd, capture_output=True, text=True, check=False,
                              env=slice_env)
        payload = None
        for line in reversed((proc.stdout or "").strip().splitlines()):
            try:
                payload = json.loads(line)
                break
            except ValueError:
                continue
        if payload is None or not payload.get("ok"):
            claims.record_outcome(conn, rng["id"], token, "review",
                                  reason=(payload or {}).get(
                                      "error", f"slice produced no JSON (exit {proc.returncode})"))
        else:
            claims.record_outcome(conn, rng["id"], token, "done",
                                  metrics={"n_partial_rows": payload.get("n_partial_rows"),
                                           "elapsed_s": payload.get("elapsed_s")})

    # Participate, then barrier with re-participation (a range that bounces
    # back to pending is picked up here instead of starving).
    while True:
        rng = claims.claim_range(conn, run_id, iso, level, token,
                                 kind="attribution_range")
        if rng is None:
            break
        _run_slice(rng)
    while True:
        with get_cursor(conn) as cur:
            cur.execute(
                """
                SELECT COUNT(*) FILTER (WHERE status IN ('pending','running')) AS open,
                       COUNT(*) FILTER (WHERE status = 'pending')             AS pend,
                       COUNT(*) FILTER (WHERE status IN ('review','failed'))  AS bad
                  FROM geodata_items
                 WHERE run_id = %s AND kind = 'attribution_range'
                   AND iso_code = %s AND adm_level = %s
                """,
                (run_id, iso, level),
            )
            r = cur.fetchone()
        if int(r["open"]) == 0:
            if int(r["bad"]) > 0:
                raise RuntimeError(
                    f"{int(r['bad'])} window slice(s) failed — requeue "
                    f"(geodata:requeue --kind=attribution_range --iso={iso}) "
                    "and then this pair.")
            break
        if int(r["pend"]) > 0:
            rng = claims.claim_range(conn, run_id, iso, level, token,
                                     kind="attribution_range")
            if rng is not None:
                _run_slice(rng)
                continue
        time.sleep(5)

    # COVERAGE GUARD before merge (2026-08-02, learned the hard way: a
    # coordinator resumed against a half-deleted range set, saw open=0,
    # and merged 2 stale slices as all of Canada — 13,504 people of 39M).
    # The merge is legal ONLY when the done slices' windows sum to the
    # pair's full window count. Anything else is review, never silence.
    with get_cursor(conn) as cur:
        cur.execute(
            """
            SELECT COALESCE(SUM((metrics->>'win_count')::bigint), 0) AS covered
              FROM geodata_items
             WHERE run_id = %s AND kind = 'attribution_range'
               AND iso_code = %s AND adm_level = %s AND status = 'done'
            """,
            (run_id, iso, level),
        )
        covered = int(cur.fetchone()["covered"])
    if covered != n_windows:
        raise RuntimeError(
            f"window coverage {covered}/{n_windows} — refusing to merge an "
            f"incomplete pair; requeue the missing ranges "
            f"(geodata:requeue --kind=attribution_range --iso={iso})")

    # Merge (one GROUP BY), verdict, set-based apply, cleanup.
    with get_cursor(conn) as cur:
        cur.execute(
            "SELECT COALESCE(SUM(pop),0)::bigint AS s, COUNT(DISTINCT jurisdiction_id) AS n "
            "FROM attribution_partials WHERE run_id=%s AND iso_code=%s AND adm_level=%s",
            (run_id, iso, level),
        )
        r = cur.fetchone()
        post_sum, n_polys = int(r["s"]), int(r["n"])
        cur.execute(
            "SELECT COALESCE(population_baseline,0) AS p FROM jurisdictions "
            "WHERE iso_code=%s AND adm_level=1 AND deleted_at IS NULL LIMIT 1",
            (iso,),
        )
        row = cur.fetchone()
        l1_pop = int(row["p"]) if row else 0
    post_dev = post_sum - l1_pop
    if l1_pop > 0:
        pct = abs(post_dev) / l1_pop * 100
        verdict = ("exact" if pct < 0.01 else "near" if pct < 1.0
                   else "partial" if pct < 5.0 else "far")
    else:
        verdict = "no_l1"

    applied = 0
    with get_cursor(conn) as cur:
        if apply_to_db:
            cur.execute(
                """
                UPDATE jurisdictions j
                   SET population = s.pop, updated_at = NOW()
                  FROM (SELECT jurisdiction_id, SUM(pop)::bigint AS pop
                          FROM attribution_partials
                         WHERE run_id=%s AND iso_code=%s AND adm_level=%s
                         GROUP BY 1) s
                 WHERE j.id = s.jurisdiction_id
                """,
                (run_id, iso, level),
            )
            applied = cur.rowcount
        cur.execute(
            "DELETE FROM attribution_partials WHERE run_id=%s AND iso_code=%s AND adm_level=%s",
            (run_id, iso, level),
        )
    log.info("%s L%d attribution: window-split merged — %d polys, verdict=%s, applied=%d",
             iso, level, n_polys, verdict, applied)
    return {"verdict": verdict, "n_polys": n_polys, "post_sum": post_sum,
            "l1_pop": l1_pop, "post_dev": post_dev, "applied_rows": applied,
            "window_split": True, "n_windows": n_windows}


def do_attribution_range(conn, run_id: str, item: dict, log: logging.Logger) -> dict:
    """One window slice of one pair — claimed by any free lane."""
    meta = item.get("metrics") or {}
    if isinstance(meta, str):
        meta = json.loads(meta)
    cmd = ["python3", PAIR_SCRIPT, item["iso_code"], str(int(item["adm_level"])),
           "--win-start", str(int(meta["win_start"])),
           "--win-count", str(int(meta["win_count"])),
           "--window-px", str(int(meta["window_px"])),
           "--run-id", run_id]
    if meta.get("rasters"):
        cmd += ["--rasters", ",".join(meta["rasters"])]
    proc = subprocess.run(cmd, capture_output=True, text=True, check=False)
    payload = None
    for line in reversed((proc.stdout or "").strip().splitlines()):
        try:
            payload = json.loads(line)
            break
        except ValueError:
            continue
    if payload is None:
        raise RuntimeError(f"slice produced no JSON (exit {proc.returncode}): "
                           f"{(proc.stderr or '')[-300:]}")
    if not payload.get("ok"):
        raise RuntimeError(payload.get("error", "attribution slice failed"))
    return {k: payload.get(k) for k in
            ("win_start", "win_count", "n_partial_rows", "elapsed_s")}


def _collapse_same_space_chains(conn, log: logging.Logger) -> int:
    """Collapse single-child same-space chains at ingest (operator ruling
    2026-08-04, answer B: "Collapse them at INGEST — a single-child same-space
    pair merges on import, so the queue never fills with them").

    The last full-planet scan flagged 11,919 of these, concentrated in CZE L4
    (3,897), SVK L4 (2,497), IND L4 (3,381), JAM L3 (791) and AUT L4 (725) —
    national releases that record one village at two ADM levels. Every repair
    endpoint is one-chain-per-POST and the repair window shuts on acceptance,
    so hand-working them was never possible.

    WHY THIS RUNS IN FINALIZE, NOT IN THE BOUNDARY PARSE. The operator's first
    constraint is a POPULATION check — geometry alone is not enough, because
    two rows can overlap ~99% and the missing sliver may hold people, and
    "any populated lack of overlap" must not get merged away. Population does
    not exist during the boundary phase; it is attributed later, from the
    raster. Finalize is the earliest point where the check is possible, and it
    still precedes the acceptance scan, so the queue never fills.

    The three constraints, each enforced below:
      1. POPULATION — merge only when parent and child hold EXACTLY the same
         population. If the non-overlapping sliver is populated the totals
         differ and the pair is left alone. Exact equality is deliberate: it
         errs toward not merging, and for genuinely identical geometry the
         same raster pixels are summed so equality is guaranteed.
      2. NEVER ORPHAN — the child's own children are re-anchored onto the
         survivor BEFORE the child is soft-deleted, in the same transaction.
         Nothing beneath a collapsed chain loses its lineage.
      3. TOPMOST SURVIVES — matching GeodataRemediationService::mergeChain, so
         a chain collapsed here is indistinguishable from one merged by hand.

    Chains longer than two collapse by iteration: each pass removes one link,
    and the loop repeats until a pass finds nothing. Per THE ETL RULE the work
    is bounded, committed chunks — never one planet-wide statement.

    Set CGA_ETL_COLLAPSE_CHAINS=0 to skip (the flags then simply surface in the
    scan as before)."""
    if (os.environ.get("CGA_ETL_COLLAPSE_CHAINS", "1") or "1").strip() == "0":
        log.info("finalize: same-space chain collapse disabled by env")
        return 0

    chunk = int(os.environ.get("CGA_ETL_COLLAPSE_CHUNK", "0") or 0) or 500
    total = 0

    for sweep in range(1, 13):          # chains deeper than 12 do not occur
        # ── STAGE A — the CHEAP classification, planet-wide.
        #
        # Ordered by cost, cheapest first (law 5: examine the GOAL). The goal is
        # "is the child the same PLACE as the parent", and three of the four
        # tests answer it for free:
        #   population equality  integer compare      — rejects 5,126 outright
        #   md5 of the WKB       hash                 — accepts 2,867 outright
        #   area ratio           ST_Area, indexed-ish — rejects the rest
        #   ST_SymDifference     THE EXPENSIVE ONE    — only the remainder
        # Measured on the live planet: this whole query returns in seconds over
        # 16,464 pairs. Putting ST_SymDifference in the same predicate made it
        # unbounded and OOM-killed postgres, because the planner evaluated it
        # before the cheap filters could reject anything.
        #
        # `certain` needs no geometry work at all; only `maybe` goes to stage B.
        with get_cursor(conn) as cur:
            cur.execute("""
                WITH oc AS (
                    SELECT c.parent_id AS pid, (array_agg(c.id))[1] AS cid
                      FROM jurisdictions c
                     WHERE c.deleted_at IS NULL AND c.merged_into_id IS NULL
                       AND c.parent_id IS NOT NULL
                     GROUP BY c.parent_id
                    HAVING COUNT(*) = 1
                )
                SELECT p.id AS pid, c.id AS cid,
                       (md5(ST_AsBinary(p.geom)) = md5(ST_AsBinary(c.geom))) AS certain
                  FROM oc
                  JOIN jurisdictions p ON p.id = oc.pid
                  JOIN jurisdictions c ON c.id = oc.cid
                 WHERE p.deleted_at IS NULL AND p.merged_into_id IS NULL
                   AND p.geom IS NOT NULL AND c.geom IS NOT NULL
                   -- constraint 1, applied FIRST because it is free: same
                   -- space AND same population confirms same PLACE. A ~99%
                   -- overlap whose missing sliver holds people fails here and
                   -- is never merged.
                   AND COALESCE(p.population, 0) = COALESCE(c.population, 0)
                   AND (
                        md5(ST_AsBinary(p.geom)) = md5(ST_AsBinary(c.geom))
                        OR (ST_Area(c.geom) / NULLIF(ST_Area(p.geom), 0))
                               BETWEEN 0.98 AND 1.02
                   )
            """)
            rows = cur.fetchall()
        conn.commit()

        pairs  = [(r["pid"], r["cid"]) for r in rows if r["certain"]]
        maybe  = [(r["pid"], r["cid"]) for r in rows if not r["certain"]]
        log.info("finalize: chain scan sweep %d — %d identical (no geometry "
                 "work), %d need symmetric-difference", sweep, len(pairs), len(maybe))

        # ── STAGE B — the expensive test, on the remainder only, in ADAPTIVE
        # batches. Batch size self-tunes toward a target duration because cost
        # depends entirely on WHICH geometries land in a batch: a fixed 500 ran
        # 3 s on one batch and was still going at 54 s on the next, with
        # postgres at 91% of its cgroup. A fixed size is a magic number waiting
        # for a different batch. ──
        target_s = float(os.environ.get("CGA_ETL_COLLAPSE_TARGET_S", "0") or 0) or 10.0
        size, i, t0 = max(8, min(chunk, 64)), 0, time.time()
        while i < len(maybe):
            batch = maybe[i:i + size]
            b0 = time.time()
            with get_cursor(conn) as cur:
                cur.execute("SET LOCAL statement_timeout = '120s'")
                try:
                    cur.execute("""
                        SELECT p.id AS pid, c.id AS cid
                          FROM jurisdictions p
                          JOIN jurisdictions c ON c.parent_id = p.id
                         WHERE p.id = ANY(%s::uuid[]) AND c.id = ANY(%s::uuid[])
                           AND ST_Area(ST_SymDifference(p.geom, c.geom))
                               <= 0.01 * NULLIF(GREATEST(ST_Area(p.geom),
                                                         ST_Area(c.geom)), 0)
                    """, ([str(p) for p, _c in batch], [str(c) for _p, c in batch]))
                    pairs.extend((r["pid"], r["cid"]) for r in cur.fetchall())
                except psycopg2.errors.QueryCanceled:
                    conn.rollback()
                    log.warning("finalize: symdiff batch of %d exceeded 120s — "
                                "skipped; those pairs stay flagged for the scan",
                                len(batch))
            conn.commit()

            i += len(batch)
            el = time.time() - b0
            if el < target_s / 2:
                size = min(chunk, size * 2)
            elif el > target_s and size > 8:
                size = max(8, size // 2)
            log.info("finalize: symdiff %d/%d · %d mergeable · batch=%d took "
                     "%.1fs · %.0fs elapsed", i, len(maybe), len(pairs),
                     len(batch), el, time.time() - t0)

        if not pairs:
            break

        merged = stale = 0
        for pid, cid in pairs:
            with get_cursor(conn) as cur:
                # ── STALENESS GUARD (caught live 2026-08-04: 89 detached rows).
                # `pairs` is a SNAPSHOT. A chain P->Q->R yields BOTH (P,Q) and
                # (Q,R) in the same sweep, because each link is a single-child
                # parent. Merging Q into P makes the (Q,R) pair stale — and
                # acting on it re-anchored R's children onto a Q that no longer
                # exists, detaching them from the tree. `deleted_at IS NULL` on
                # the merge itself was not enough: the RE-ANCHOR ran first and
                # pointed real children at a dead row.
                #
                # Both ends must still be live AND still be parent-and-child.
                # Anything stale is skipped; the next sweep re-derives it from
                # live rows and collapses it correctly. This is why the sweep
                # loop exists. ──
                cur.execute("""
                    SELECT 1
                      FROM jurisdictions p
                      JOIN jurisdictions c ON c.id = %s AND c.parent_id = p.id
                     WHERE p.id = %s
                       AND p.deleted_at IS NULL AND p.merged_into_id IS NULL
                       AND c.deleted_at IS NULL AND c.merged_into_id IS NULL
                """, (cid, pid))
                fresh = cur.fetchone() is not None
            if not fresh:
                # Close the read's transaction before moving on — `continue`
                # would otherwise skip the commit below and leave one
                # idle-in-transaction per stale pair.
                conn.commit()
                stale += 1
                continue

            with get_cursor(conn) as cur:
                # constraint 2: lineage first — re-anchor the child's children
                # onto the survivor before the child stops being live.
                cur.execute("""
                    UPDATE jurisdictions
                       SET parent_id = %s, parent_assigned_via = 'merged_chain',
                           updated_at = now()
                     WHERE parent_id = %s AND deleted_at IS NULL
                """, (pid, cid))
                moved = cur.rowcount

                # constraint 3: topmost survives; the child becomes a
                # pass-through pointer, exactly as mergeChain leaves it.
                cur.execute("""
                    UPDATE jurisdictions
                       SET merged_into_id = %s, deleted_at = now(), updated_at = now()
                     WHERE id = %s AND deleted_at IS NULL
                """, (pid, cid))
                if cur.rowcount:
                    merged += 1
                    total  += 1
            conn.commit()          # bounded, committed chunk of work

        log.info("finalize: chain collapse sweep %d — %d pair(s) merged, %d "
                 "stale (deferred to the next sweep) · %d total",
                 sweep, merged, stale, total)
        if merged == 0:
            break

    if total:
        log.info("finalize: collapsed %d same-space chain link(s); survivors "
                 "keep every descendant", total)
    return total


def do_finalize(conn, options: dict, log: logging.Logger) -> dict:
    """Planet rollup + per-country national validation (the barrier). Findings
    land as geodata_flags rows (class national_delta_gt5) — the repair plane's
    censusable stream — not just log warnings (GEODATA_PULL_ENGINE_PLAN.md §6)."""
    from import_worldpop import rollup_planet_population, validate_national_population

    # Belt-and-suspenders at the barrier: any baseline the raster-phase stamp
    # missed (overlap ordering — see _stamp_missing_baselines) gets stamped
    # here, so the L1 copy below and the planet rollup can never see a gap.
    _stamp_missing_baselines(conn, log)

    # National populations: the engine attributes levels 2+, and legacy's
    # L1 raster pass isn't part of the pull flow — the national number IS
    # the raster-sum baseline stamped at the raster phase. Set-based,
    # fills only what attribution didn't (idempotent).
    with get_cursor(conn) as cur:
        cur.execute("""
            UPDATE jurisdictions
               SET population = population_baseline,
                   population_year = 2023, updated_at = now()
             WHERE adm_level = 1 AND deleted_at IS NULL
               AND COALESCE(population, 0) = 0
               AND COALESCE(population_baseline, 0) > 0
        """)
        log.info("finalize: %d national populations set from baselines", cur.rowcount)

    # Collapse same-space chains BEFORE the rollup: merged rows go
    # deleted_at + merged_into_id, and every live() predicate in the app
    # excludes both, so the rollup must not count a row that is about to stop
    # being live.
    collapsed = _collapse_same_space_chains(conn, log)

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
                   'National vs children population delta > 5%% — ' || d.iso_code,
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
            elif kind == "resolve_range":
                metrics = do_resolve_range(conn, item, log)
            elif kind == "raster_iso":
                metrics = do_raster(conn, args.run, item["iso_code"], log)
            elif kind == "raster_range":
                metrics = do_raster_range(conn, item, log)
            elif kind == "attribution_pair":
                _im = item.get("metrics") or {}
                if isinstance(_im, str):
                    _im = json.loads(_im)
                metrics = do_attribution(conn, args.run, item["iso_code"],
                                         int(item["adm_level"]),
                                         not item["dry_run"], log,
                                         item_metrics=_im)
            elif kind == "attribution_range":
                metrics = do_attribution_range(conn, args.run, item, log)
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
