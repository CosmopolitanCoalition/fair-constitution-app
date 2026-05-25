"""
seed_database.py — Master ETL orchestrator for the Fair Constitution App.

Runs the full geospatial data pipeline in order:
  Phase 1: import_geoboundaries  — boundary polygons + hierarchy
  Phase 2: import_worldpop       — load raster tiles into DB, then derive
                                    jurisdictions.population via PostGIS

Usage:
    # Full global run (boundaries + raster tiles + populations)
    python seed_database.py

    # Smoke test (NZL only, no population)
    python seed_database.py --countries NZL --adm-levels 0 1 --skip-population --fresh

    # Resume after a crash
    python seed_database.py --resume

    # Specific countries
    python seed_database.py --countries USA GBR DEU FRA

    # Boundaries only (no raster / no population)
    python seed_database.py --skip-population

Options:
    --countries ISO3 [ISO3 ...]   Only process these ISO3 codes (default: all)
    --adm-levels N [N ...]        Only process these ADM levels 0-5 (default: all)
    --skip-population             Skip Phase 2 entirely (no raster load, no pops)
    --fresh                       Ignore progress.json, reprocess everything
    --resume                      Explicitly resume from progress.json (default)
    --log-file PATH               Log file path (default: /etl/etl.log)

Raster tiles are loaded unconditionally in Phase 2. They drive both the
population_within() SQL path (used here to fill jurisdictions.population) and
the PostGIS-backed district mapper used after setup completes. The on-disk
WorldPop .tif files are not read again once tiles are in the DB.
"""

import argparse
import json
import logging
import os
import signal
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

import heartbeat

# ─── Paths ───────────────────────────────────────────────────────────────────

PROGRESS_FILE = Path("/etl/progress.json")
DEFAULT_LOG   = Path("/etl/etl.log")

# ─── Progress persistence ─────────────────────────────────────────────────────

def load_progress(progress_file: Path, fresh: bool) -> dict:
    """
    Load the progress tracking dict from JSON.

    If fresh=True or the file doesn't exist, returns a new empty dict.
    Uses a .tmp file pattern — a crashed mid-write leaves the .tmp, not
    a corrupted main file.
    """
    if fresh:
        return {"started_at": datetime.now(timezone.utc).isoformat()}

    if progress_file.exists():
        try:
            with open(progress_file) as f:
                data = json.load(f)
            return data
        except (json.JSONDecodeError, OSError) as exc:
            logging.warning("Could not load progress file (%s) — starting fresh", exc)

    return {"started_at": datetime.now(timezone.utc).isoformat()}


def save_progress(progress: dict, progress_file: Path):
    """
    Atomically write progress dict to JSON.
    Writes to .tmp first, then renames — crash-safe.
    """
    tmp = progress_file.with_suffix(".json.tmp")
    try:
        with open(tmp, "w") as f:
            json.dump(progress, f, indent=2, default=str)
        os.replace(tmp, progress_file)
    except OSError as exc:
        logging.error("Failed to save progress: %s", exc)


# ─── Logging setup ────────────────────────────────────────────────────────────

def setup_logging(log_file: Path) -> logging.Logger:
    """
    Configure root logger to write to both stdout and a log file.

    Format: 2026-02-22 14:23:01 [INFO ] seed_database: Starting ETL pipeline
    """
    log_format = "%(asctime)s [%(levelname)-5s] %(name)s: %(message)s"
    date_format = "%Y-%m-%d %H:%M:%S"

    root_logger = logging.getLogger()
    root_logger.setLevel(logging.DEBUG)

    # Console handler (INFO and above)
    console_handler = logging.StreamHandler(sys.stdout)
    console_handler.setLevel(logging.INFO)
    console_handler.setFormatter(logging.Formatter(log_format, date_format))
    root_logger.addHandler(console_handler)

    # File handler (DEBUG and above — full detail for troubleshooting)
    try:
        log_file.parent.mkdir(parents=True, exist_ok=True)
        file_handler = logging.FileHandler(log_file)
        file_handler.setLevel(logging.DEBUG)
        file_handler.setFormatter(logging.Formatter(log_format, date_format))
        root_logger.addHandler(file_handler)
    except OSError as exc:
        logging.warning("Could not open log file %s: %s", log_file, exc)

    return logging.getLogger("seed_database")


# ─── DB connection check ──────────────────────────────────────────────────────

def verify_database_connection(log: logging.Logger, retries: int = 5, delay: int = 5) -> bool:
    """
    Attempt to connect to the database, retrying with exponential-ish backoff.
    Returns True on success, False after all retries are exhausted.
    """
    import psycopg2
    from db import DB_CONFIG

    for attempt in range(1, retries + 1):
        try:
            conn = psycopg2.connect(**DB_CONFIG)
            conn.close()
            log.info("Database connection verified (attempt %d/%d)", attempt, retries)
            return True
        except psycopg2.OperationalError as exc:
            log.warning(
                "DB connection attempt %d/%d failed: %s",
                attempt, retries, exc
            )
            if attempt < retries:
                wait = delay * attempt
                log.info("Retrying in %ds…", wait)
                time.sleep(wait)

    return False


# ─── Summary printer ─────────────────────────────────────────────────────────

def print_summary(progress: dict, elapsed: float, log: logging.Logger):
    """Print a summary table of what was processed."""
    gb_entries  = progress.get("geoboundaries", {})
    wp_entries  = progress.get("worldpop", {})
    wr_entries  = progress.get("worldpop_rasters", {})

    gb_done     = sum(1 for v in gb_entries.values() if v.get("status") == "done")
    gb_skipped  = sum(1 for v in gb_entries.values() if v.get("status") == "skipped")
    gb_errors   = sum(1 for v in gb_entries.values() if v.get("status") == "error")
    gb_inserted = sum(v.get("inserted", 0) for v in gb_entries.values())

    wp_done     = sum(1 for v in wp_entries.values() if v.get("status") == "done")
    wp_skipped  = sum(1 for v in wp_entries.values() if v.get("status") == "skipped")
    wp_updated  = sum(v.get("updated", 0) for v in wp_entries.values())

    wr_done     = sum(1 for v in wr_entries.values() if v.get("status") == "done")
    wr_tiles    = sum(v.get("tiles", 0) for v in wr_entries.values())

    elapsed_str = f"{int(elapsed // 3600)}h {int((elapsed % 3600) // 60)}m {int(elapsed % 60)}s"

    lines = [
        "",
        "╔══════════════════════════════════════════════╗",
        "║         ETL Pipeline — Final Summary          ║",
        "╠══════════════════════════════════════════════╣",
        f"║  Phase 1 — geoBoundaries                     ║",
        f"║    Files processed (done):   {gb_done:<6}          ║",
        f"║    Files skipped:            {gb_skipped:<6}          ║",
        f"║    Files errored:            {gb_errors:<6}          ║",
        f"║    Jurisdictions inserted:   {gb_inserted:<7}         ║",
        "╠══════════════════════════════════════════════╣",
        f"║  Phase 2 — WorldPop population               ║",
        f"║    Countries processed:      {wp_done:<6}          ║",
        f"║    Countries skipped:        {wp_skipped:<6}          ║",
        f"║    Population rows updated:  {wp_updated:<7}         ║",
    ]

    if wr_entries:
        lines += [
            "╠══════════════════════════════════════════════╣",
            f"║  Phase 2 — WorldPop raster tiles in DB       ║",
            f"║    Countries loaded:         {wr_done:<6}          ║",
            f"║    Total raster tiles:       {wr_tiles:<7}         ║",
        ]

    lines += [
        "╠══════════════════════════════════════════════╣",
        f"║  Total elapsed: {elapsed_str:<29}║",
        "╚══════════════════════════════════════════════╝",
        "",
    ]
    for line in lines:
        log.info(line)


# ─── DB purge (for --fresh) ───────────────────────────────────────────────────

def purge_geoboundaries_data(log: logging.Logger):
    """
    Clean-slate purge for --fresh runs:
      1. DELETE all geoboundaries-sourced jurisdictions + their constitutional_settings.
      2. TRUNCATE geoboundary_metadata so the meta CSV gets re-loaded fresh
         (Phase R — the CSV is now persisted into a DB table at run start).
      3. TRUNCATE worldpop_rasters so a previous run's tiles don't leak
         into the new run's preview/UI (Phase P.1.1 — was surfacing stale
         rasters in the boundaries-phase preview because per-country DELETE
         in load_raster_to_db only fires when that country's WorldPop pass
         actually runs).
      4. Reset population/population_year on PRESERVED rows (Earth, etc.) so a
         prior run's rollup value (~342M for USA-only, ~7.99B for world-wide)
         doesn't leak into the new run's Planet card.

    Earth (source='computed_skater', adm_level=0) survives the DELETE because
    it isn't sourced from geoBoundaries — but we still reset its population
    so the cards correctly show 0% w/pop until Phase 2 re-rolls it up.
    """
    import psycopg2
    from db import get_connection, get_cursor

    # Sources that get fully purged on --fresh. 'geoboundaries' is the bulk
    # of imported data; 'synthetic' is rows synthesised by Phase J1.5 (PRI
    # etc.); 'synthetic_country' is the LEGACY value used before that source
    # name changed — included here so a leftover row from an old iteration
    # doesn't survive a --fresh and block synthesis (which uses ON CONFLICT
    # (slug) DO NOTHING and would silently skip if a stale slug is present).
    purgeable_sources = ('geoboundaries', 'synthetic', 'synthetic_country',
                          'synthetic_intermediary')

    conn = get_connection()
    with get_cursor(conn) as cur:
        cur.execute("""
            DELETE FROM constitutional_settings
            WHERE jurisdiction_id IN (
                SELECT id FROM jurisdictions WHERE source = ANY(%s)
            )
        """, (list(purgeable_sources),))
        settings_deleted = cur.rowcount
        cur.execute(
            "DELETE FROM jurisdictions WHERE source = ANY(%s)",
            (list(purgeable_sources),),
        )
        jur_deleted = cur.rowcount

        # Phase R: truncate geoboundary_metadata so the next run re-imports
        # the CSV cleanly. The table is small (~700 rows) so TRUNCATE is
        # cheap and avoids leaving stale entries from a previous archive
        # version. Wrapped in EXCEPTION-handler so older DBs without the
        # table (pre-Phase-R) don't fail the whole purge.
        try:
            cur.execute("TRUNCATE TABLE geoboundary_metadata")
            meta_purged = True
        except psycopg2.errors.UndefinedTable:
            conn.rollback()
            # Re-acquire cursor on the same connection after rollback.
            meta_purged = False

        # Phase P.1.1: truncate worldpop_rasters so the boundaries-phase
        # preview doesn't surface tiles from a previous run. (Per-country
        # DELETE inside load_raster_to_db keeps the table clean during
        # Phase 2, but only fires when the country's WorldPop pass runs;
        # we want a clean slate at --fresh-time so the wizard's preview
        # honestly reflects what THIS run has produced.) Worldpop_rasters
        # is ~7 GB at world scale; TRUNCATE is a metadata operation and
        # completes in milliseconds regardless.
        try:
            cur.execute("TRUNCATE TABLE worldpop_rasters")
            rasters_purged = True
        except psycopg2.errors.UndefinedTable:
            conn.rollback()
            rasters_purged = False

        # Reset population on the preserved Earth row (and any other rows
        # not in the purgeable sources list). The next run's
        # rollup_planet_population will repopulate Earth once Phase 2 finishes.
        cur.execute("""
            UPDATE jurisdictions
            SET    population = NULL,
                   population_year = NULL,
                   updated_at = NOW()
            WHERE  source <> ALL(%s)
              AND  population IS NOT NULL
        """, (list(purgeable_sources),))
        pop_reset = cur.rowcount
    conn.commit()
    conn.close()
    log.info(
        "--fresh: purged %d jurisdictions, %d constitutional_settings rows, "
        "%s, %s, and reset population on %d preserved row(s)",
        jur_deleted, settings_deleted,
        "truncated geoboundary_metadata" if meta_purged else "geoboundary_metadata not present (pre-Phase-R DB)",
        "truncated worldpop_rasters"     if rasters_purged else "worldpop_rasters not present",
        pop_reset
    )


# ─── Signal handler (graceful Ctrl-C) ────────────────────────────────────────

_progress_ref: dict = {}
_progress_file_ref: Path = PROGRESS_FILE
# When the Phase 2 worldpop SUBPROCESS is running, IT owns progress.json —
# it writes per-raster entries via its own _save_progress callback. The
# parent's _progress_ref is a stale snapshot from "after Phase 1" and does
# NOT contain those subprocess writes. If the SIGTERM handler called
# save_progress(_progress_ref, ...) during Phase 2, it would atomically
# clobber every raster-done entry the subprocess just wrote — turning
# halt-and-resume into halt-and-redo-everything-from-AFG. So we flip this
# flag around the subprocess.run call to suppress that overwrite.
_phase2_subprocess_running: bool = False
_phase2_subprocess: object = None   # the Popen, when running

def _handle_sigint(signum, frame):
    log = logging.getLogger("seed_database")
    if _phase2_subprocess_running:
        # During Phase 2 the worldpop subprocess owns progress.json. Forward
        # SIGTERM to it so it can finish its current atomic write and exit
        # cleanly, then let its naturally-saved state survive on disk.
        log.warning(
            "Interrupted during Phase 2 — forwarding SIGTERM to worldpop "
            "subprocess and preserving its progress writes."
        )
        if _phase2_subprocess is not None and _phase2_subprocess.poll() is None:
            try:
                _phase2_subprocess.terminate()
                # Give it up to 30 s to flush — atomic write is microseconds,
                # but a mid-batch INSERT may take a second or two to commit.
                _phase2_subprocess.wait(timeout=30)
            except Exception as exc:
                log.warning("subprocess teardown raised %s — proceeding", exc)
    else:
        # Phase 1 (or pre/post-phase) — parent owns progress.json. Save before
        # exit so per-country boundary work isn't lost.
        log.warning("Interrupted — saving progress before exit…")
        save_progress(_progress_ref, _progress_file_ref)
    # Phase T.8: freeze running bar timers in place rather than letting
    # them keep ticking or marking them complete-at-100% on halt. The
    # next run's bar_start calls overwrite started_at fresh.
    try:
        from supervisor import freeze_bar_timers, now_iso
        freeze_bar_timers(now_iso())
    except Exception as exc:
        log.warning("could not freeze bar timers on halt: %s", exc)
    heartbeat.clear_current()
    sys.exit(130)


# ─── Main ─────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(
        description="Fair Constitution App — Geospatial ETL Pipeline",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__,
    )
    parser.add_argument(
        "--countries", nargs="+", metavar="ISO3",
        help="ISO3 country codes to process (default: all)"
    )
    parser.add_argument(
        "--adm-levels", nargs="+", type=int, metavar="N",
        help="ADM levels to process 0-5 (default: all)"
    )
    parser.add_argument(
        "--skip-population", action="store_true",
        help="Skip Phase 2 entirely (no raster tile load, no population fill)"
    )
    parser.add_argument(
        "--fresh", action="store_true",
        help="Ignore progress.json and reprocess everything (purge "
             "geoboundary-sourced jurisdictions + reimport boundaries "
             "+ redo population)."
    )
    parser.add_argument(
        "--resume", action="store_true", default=True,
        help="Resume from progress.json (default behaviour)"
    )
    parser.add_argument(
        "--log-file", default=str(DEFAULT_LOG),
        help=f"Log file path (default: {DEFAULT_LOG})"
    )
    parser.add_argument(
        "--pause-on-exception", action="store_true",
        help=(
            "On the first unhandled per-country error, pause and wait for the "
            "operator to choose skip / retry / abort via the wizard UI (control "
            "files /etl/control/paused_on_error.json + error_resolution.json). "
            "Replaces the legacy hard-halt behavior."
        )
    )
    # Legacy flag — silently promoted to --pause-on-exception so old
    # invocations from the wizard or scripts keep working.
    parser.add_argument(
        "--stop-on-exception", action="store_true",
        help=argparse.SUPPRESS,
    )
    # Phase P.8 — operator-supplied data root. Overrides the DATA_ROOT env
    # default (/archive in container, /docs in legacy bare-metal). Wizard
    # plumbs this from a Step 2 source-folder input through supervisor.py.
    # MUST be set BEFORE importing import_geoboundaries / import_worldpop
    # because those modules read DATA_ROOT at module-load time.
    parser.add_argument(
        "--data-root", default=None,
        help=(
            "Override DATA_ROOT (the directory containing geoBoundaries_repo/ "
            "and worldpop_100m_latest/). Useful when the wizard points at a "
            "non-default folder; defaults to env DATA_ROOT or /docs."
        ),
    )

    args = parser.parse_args()

    # Apply --data-root before any subsequent module imports pick up DATA_ROOT.
    if args.data_root:
        os.environ["DATA_ROOT"] = args.data_root

    log  = setup_logging(Path(args.log_file))

    log.info("╔══════════════════════════════════════════════╗")
    log.info("║     Fair Constitution — ETL Pipeline          ║")
    log.info("╚══════════════════════════════════════════════╝")
    log.info("Started at %s", datetime.now(timezone.utc).isoformat())

    # Phase N: log the detected memory budget + chosen chunk profile so the
    # operator (and the wizard's log-tail panel) sees what sizing decisions
    # were made before any heavy work starts. Override with
    # ETL_MEMORY_BUDGET_BYTES env var.
    try:
        from memory_budget import chunk_profile, detect_memory_budget_bytes
        _budget = detect_memory_budget_bytes()
        _profile_name, _profile = chunk_profile(_budget)
        log.info(
            "Memory budget: %.1f GB → profile '%s' "
            "(BATCH_BYTE_LIMIT=%d MB, BATCH_ROW_LIMIT=%d, "
            "DB_FETCH_CHUNK_SIZE=%d, RASTER_BATCH_SIZE=%d)",
            _budget / (1024 ** 3),
            _profile_name,
            _profile["BATCH_BYTE_LIMIT"] // (1024 * 1024),
            _profile["BATCH_ROW_LIMIT"],
            _profile["DB_FETCH_CHUNK_SIZE"],
            _profile["RASTER_BATCH_SIZE"],
        )
    except Exception as _exc:   # pragma: no cover — best-effort logging
        log.warning("Could not log memory budget: %s", _exc)

    if args.countries:
        log.info("Filtering to countries: %s", ", ".join(args.countries))

    # NOTE: dependent territories (USA's PR/GU/VIR/ASM/MNP, FRA's overseas
    # départements, etc.) loaded with iso_code = sovereign get population
    # from the sovereign's raster (e.g. PR's pixels are inside USA's TIF
    # already). Phase Q's _topological_raster_fallback handles the dual-
    # footprint cases where a polygon has iso_code = sovereign but the
    # sovereign's raster doesn't cover it (e.g. CHN's "Taiwan Province"
    # picks up TWN's raster via ST_Intersects). No curated lookup table
    # — Phase R deleted SOVEREIGN_TERRITORIES, RASTER_FALLBACKS, and
    # NO_WORLDPOP. The Setup wizard's Phase 4 review surface still
    # surfaces any rows the topology can't help with, but the ETL no
    # longer needs a hand-curated mapping to bridge them.
    if args.adm_levels:
        log.info("Filtering to ADM levels: %s", args.adm_levels)
    if args.skip_population:
        log.info("--skip-population: WorldPop phase will be skipped")

    if args.fresh:
        log.info("--fresh: will purge geoboundary data + reprocess everything")

    # Promote legacy --stop-on-exception → --pause-on-exception
    args.pause_on_exception = args.pause_on_exception or args.stop_on_exception
    if args.pause_on_exception:
        log.info("--pause-on-exception: pipeline will pause on first per-country error and await operator decision")

    # ── Verify DB ──
    if not verify_database_connection(log):
        log.error("Cannot connect to database after 5 retries. Is the postgres container up?")
        log.error("Try: docker compose up -d postgres && docker compose exec etl python seed_database.py")
        sys.exit(1)

    # ── Load progress + optional fresh purge ──
    progress = load_progress(PROGRESS_FILE, fresh=args.fresh)
    if args.fresh:
        purge_geoboundaries_data(log)
        save_progress(progress, PROGRESS_FILE)
        log.info("--fresh: wrote empty progress.json to clear stale per-country state")
        heartbeat.clear_all()

    # Register signal handler so Ctrl-C saves progress
    global _progress_ref, _progress_file_ref
    _progress_ref      = progress
    _progress_file_ref = PROGRESS_FILE
    signal.signal(signal.SIGINT, _handle_sigint)
    signal.signal(signal.SIGTERM, _handle_sigint)

    start_time = time.time()

    # ── Phase 1: Boundaries ──
    log.info("")
    log.info("═══ Phase 1: import_geoboundaries ═══════════════")
    heartbeat.set_phase("geoboundaries")
    try:
        from import_geoboundaries import import_geoboundaries

        # Save callback fires after each country's geojson finishes. Mirrors
        # the Phase 2 pattern; lets the wizard's "countries done" tile track
        # progress in near-real-time instead of waiting for Phase 1 to fully
        # complete before the on-disk progress.json is updated.
        def _save_progress_phase1(p):
            save_progress(p, PROGRESS_FILE)

        import_geoboundaries(
            countries          = args.countries,
            adm_levels         = args.adm_levels,
            progress           = progress,
            log                = log.getChild("geoboundaries"),
            pause_on_exception = args.pause_on_exception,
            save_progress_fn   = _save_progress_phase1,
        )
    except SystemExit:
        # Operator chose "Abort" from an error-pause card → propagate cleanly.
        save_progress(progress, PROGRESS_FILE)
        heartbeat.clear_current()
        raise
    except Exception as exc:
        log.error("Phase 1 failed with unhandled exception: %s", exc, exc_info=True)
        save_progress(progress, PROGRESS_FILE)
        heartbeat.clear_current()
        sys.exit(2 if args.pause_on_exception else 1)

    save_progress(progress, PROGRESS_FILE)
    log.info("Phase 1 complete. Progress saved.")

    # ── Phase 2: Raster load + population fill ──
    # IMPORTANT: import_worldpop is run in a SUBPROCESS, not imported directly.
    # Historical reason: import_geoboundaries loaded geopandas/fiona (one GDAL
    # instance) and import_worldpop loaded rasterio (second GDAL instance);
    # two GDAL instances in the same process caused a segfault (exit 139).
    #
    # Phase L removed geopandas/fiona from import_geoboundaries, so the segfault
    # risk is gone. We keep the subprocess split because it provides crash
    # isolation between phases and lets Phase 1 + Phase 2 fail independently.
    run_phase2 = not args.skip_population
    if run_phase2:
        log.info("")
        log.info("═══ Phase 2: import_worldpop ════════════════════")
        heartbeat.set_phase("worldpop")

        # Heartbeat transition: keep the UI's "currently processing" card from
        # pointing at the last Phase 1 jurisdiction while we spawn the subprocess.
        heartbeat.write_current(
            id=None,
            name="transition",
            iso_code=None,
            adm_level=None,
            phase="transition",
            sub_phase="spawning worldpop subprocess",
            queue_preview=[],
        )

        # Build the subprocess argv
        wp_cmd = [sys.executable, "-c", f"""
import logging, sys, json, pathlib

# Re-attach to the same log file so output is unified
log_file = pathlib.Path({str(args.log_file)!r})
root = logging.getLogger()
root.setLevel(logging.DEBUG)
fh = logging.FileHandler(log_file)
fh.setLevel(logging.DEBUG)
fh.setFormatter(logging.Formatter("%(asctime)s [%(levelname)-5s] %(name)s: %(message)s", "%Y-%m-%d %H:%M:%S"))
root.addHandler(fh)
ch = logging.StreamHandler(sys.stdout)
ch.setLevel(logging.INFO)
ch.setFormatter(logging.Formatter("%(asctime)s [%(levelname)-5s] %(name)s: %(message)s", "%Y-%m-%d %H:%M:%S"))
root.addHandler(ch)
log = logging.getLogger("seed_database.worldpop")

import os

progress_file = pathlib.Path({str(PROGRESS_FILE)!r})
progress = json.loads(progress_file.read_text())

def _save_progress(p):
    tmp = progress_file.with_suffix('.json.tmp')
    tmp.write_text(json.dumps(p, indent=2, default=str))
    os.replace(str(tmp), str(progress_file))

from import_worldpop import import_worldpop
import_worldpop(
    countries={args.countries!r},
    progress=progress,
    log=log,
    save_progress_fn=_save_progress,
    pause_on_exception={args.pause_on_exception!r},
)

_save_progress(progress)
log.info("Phase 2 progress saved.")
"""]

        import subprocess
        global _phase2_subprocess_running, _phase2_subprocess
        # Popen instead of subprocess.run so the SIGTERM handler can forward
        # the signal to the child (instead of letting subprocess.run's
        # SystemExit teardown SIGTERM-kill it before it can save).
        proc = subprocess.Popen(wp_cmd, cwd="/etl")
        _phase2_subprocess = proc
        _phase2_subprocess_running = True
        try:
            exit_code = proc.wait()
        finally:
            _phase2_subprocess_running = False
            _phase2_subprocess = None

        if exit_code != 0:
            log.error("Phase 2 subprocess failed with exit code %d", exit_code)
            # IMPORTANT: do NOT call save_progress(progress, ...) here. The
            # parent's `progress` dict is the stale Phase-1 snapshot — writing
            # it would clobber the subprocess's per-raster saves. The
            # subprocess's own _save_progress has already persisted the
            # latest state atomically. Just propagate the failure.
            heartbeat.clear_current()
            sys.exit(2 if args.pause_on_exception else 1)

        # Reload progress that the subprocess wrote
        progress = load_progress(PROGRESS_FILE, fresh=False)
        log.info("Phase 2 complete. Progress saved.")
    else:
        log.info("Phase 2 skipped (--skip-population).")

    # ── Summary ──
    print_summary(progress, time.time() - start_time, log)

    # Clean up the heartbeat so the UI knows no jurisdiction is being processed
    # anymore. (supervisor.py also clears this on job end as a backstop.)
    # Mark worldpop summary 100 % done if it ran, then flip phase to "complete".
    if run_phase2:
        heartbeat.worldpop_mark_complete()
    heartbeat.set_phase("complete")
    heartbeat.clear_current()


if __name__ == "__main__":
    main()
