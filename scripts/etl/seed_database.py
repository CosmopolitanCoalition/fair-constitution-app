"""
seed_database.py — Master ETL orchestrator for the Fair Constitution App.

Runs the full geospatial data pipeline in order:
  Phase 1: import_geoboundaries  — boundary polygons + hierarchy
  Phase 2: import_worldpop       — population raster → zonal stats

Usage:
    # Full global run (boundaries + population aggregates)
    python seed_database.py

    # Full run including raster tiles for PostGIS district drawing
    python seed_database.py --load-rasters

    # Smoke test (NZL only, no population)
    python seed_database.py --countries NZL --adm-levels 0 1 --skip-population --fresh

    # Resume after a crash
    python seed_database.py --resume

    # Specific countries with all ADM levels + population
    python seed_database.py --countries USA GBR DEU FRA

    # Specific countries with population AND raster tiles
    python seed_database.py --countries USA --load-rasters

    # Boundaries only (no WorldPop)
    python seed_database.py --skip-population

Options:
    --countries ISO3 [ISO3 ...]   Only process these ISO3 codes (default: all)
    --adm-levels N [N ...]        Only process these ADM levels 0-5 (default: all)
    --skip-population             Skip Phase 2 WorldPop import
    --load-rasters                Also load each country's TIF into worldpop_rasters
                                  table after population aggregation. One-time cost;
                                  after this the TIF files are not needed at runtime.
    --fresh                       Ignore progress.json, reprocess everything
    --resume                      Explicitly resume from progress.json (default)
    --log-file PATH               Log file path (default: /etl/etl.log)
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
            f"║  Phase 2 — WorldPop rasters (--load-rasters) ║",
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
    DELETE all geoboundaries-sourced jurisdictions and their constitutional_settings.

    Earth (source='computed_skater', adm_level=0) is preserved.
    Called automatically when --fresh is passed, so re-runs start clean.
    """
    import psycopg2
    from db import get_connection, get_cursor

    conn = get_connection()
    with get_cursor(conn) as cur:
        cur.execute("""
            DELETE FROM constitutional_settings
            WHERE jurisdiction_id IN (
                SELECT id FROM jurisdictions WHERE source = 'geoboundaries'
            )
        """)
        settings_deleted = cur.rowcount
        cur.execute("DELETE FROM jurisdictions WHERE source = 'geoboundaries'")
        jur_deleted = cur.rowcount
    conn.close()
    log.info(
        "--fresh: purged %d jurisdictions and %d constitutional_settings rows from DB",
        jur_deleted, settings_deleted
    )


# ─── Signal handler (graceful Ctrl-C) ────────────────────────────────────────

_progress_ref: dict = {}
_progress_file_ref: Path = PROGRESS_FILE

def _handle_sigint(signum, frame):
    logging.getLogger("seed_database").warning(
        "Interrupted — saving progress before exit…"
    )
    save_progress(_progress_ref, _progress_file_ref)
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
        help="Skip Phase 2 WorldPop population import"
    )
    parser.add_argument(
        "--load-rasters", action="store_true",
        help=(
            "Load each country's WorldPop TIF into the worldpop_rasters table "
            "after population aggregation. One-time cost; after this the TIF files "
            "are not needed at runtime. Implies Phase 2 runs (ignores --skip-population)."
        )
    )
    parser.add_argument(
        "--fresh", action="store_true",
        help="Ignore progress.json and reprocess everything"
    )
    parser.add_argument(
        "--resume", action="store_true", default=True,
        help="Resume from progress.json (default behaviour)"
    )
    parser.add_argument(
        "--log-file", default=str(DEFAULT_LOG),
        help=f"Log file path (default: {DEFAULT_LOG})"
    )

    args = parser.parse_args()
    log  = setup_logging(Path(args.log_file))

    log.info("╔══════════════════════════════════════════════╗")
    log.info("║     Fair Constitution — ETL Pipeline          ║")
    log.info("╚══════════════════════════════════════════════╝")
    log.info("Started at %s", datetime.now(timezone.utc).isoformat())
    if args.countries:
        log.info("Filtering to countries: %s", ", ".join(args.countries))
    if args.adm_levels:
        log.info("Filtering to ADM levels: %s", args.adm_levels)
    if args.skip_population:
        log.info("--skip-population: WorldPop phase will be skipped")
    if args.load_rasters:
        log.info("--load-rasters: TIF tiles will be loaded into worldpop_rasters table")
    if args.fresh:
        log.info("--fresh: ignoring any existing progress.json")

    # ── Verify DB ──
    if not verify_database_connection(log):
        log.error("Cannot connect to database after 5 retries. Is the postgres container up?")
        log.error("Try: docker compose up -d postgres && docker compose exec etl python seed_database.py")
        sys.exit(1)

    # ── Load progress ──
    progress = load_progress(PROGRESS_FILE, fresh=args.fresh)

    # ── Purge DB if --fresh ──
    if args.fresh:
        purge_geoboundaries_data(log)

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
    try:
        from import_geoboundaries import import_geoboundaries
        import_geoboundaries(
            countries  = args.countries,
            adm_levels = args.adm_levels,
            progress   = progress,
            log        = log.getChild("geoboundaries"),
        )
    except Exception as exc:
        log.error("Phase 1 failed with unhandled exception: %s", exc, exc_info=True)
        save_progress(progress, PROGRESS_FILE)
        sys.exit(1)

    save_progress(progress, PROGRESS_FILE)
    log.info("Phase 1 complete. Progress saved.")

    # ── Phase 2: Population ──
    # IMPORTANT: import_worldpop is run in a SUBPROCESS, not imported directly.
    # Reason: import_geoboundaries loads geopandas/fiona which initialises one
    # GDAL/GEOS shared-library instance. import_worldpop loads rasterio which
    # initialises a second GDAL instance. Two GDAL instances in the same process
    # cause a segfault (exit 139 / SIGKILL) when rasterstats opens the raster.
    # Running worldpop in a fresh subprocess avoids this entirely.
    #
    # --load-rasters implies Phase 2 must run (raster loading happens inside
    # import_worldpop after population aggregation, per-country).
    run_phase2 = not args.skip_population or args.load_rasters
    if run_phase2:
        log.info("")
        log.info("═══ Phase 2: import_worldpop ════════════════════")

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
    load_rasters={args.load_rasters!r},
)

_save_progress(progress)
log.info("Phase 2 progress saved.")
"""]

        import subprocess
        try:
            result = subprocess.run(
                wp_cmd,
                cwd="/etl",
                check=True,
            )
        except subprocess.CalledProcessError as exc:
            log.error("Phase 2 subprocess failed with exit code %d", exc.returncode)
            save_progress(progress, PROGRESS_FILE)
            sys.exit(1)

        # Reload progress that the subprocess wrote
        progress = load_progress(PROGRESS_FILE, fresh=False)
        log.info("Phase 2 complete. Progress saved.")
    else:
        log.info("Phase 2 skipped (--skip-population).")

    # ── Summary ──
    print_summary(progress, time.time() - start_time, log)


if __name__ == "__main__":
    main()
