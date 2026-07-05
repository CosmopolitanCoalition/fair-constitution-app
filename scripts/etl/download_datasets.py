"""
download_datasets.py — Fetch the official OPEN geodata (geoBoundaries + WorldPop)
straight from the upstream hosts into the ETL data volume, laid out EXACTLY the
way seed_database.py's importers expect to read it.

WHY THIS EXISTS
---------------
The normal setup path stages a full-world archive on disk (the map-files
archive, mounted read-only at /archive) and the seeder runs against
DATA_ROOT=/archive.
That archive is ~14 GB (geoBoundaries gbOpen + every country's WorldPop 100m
raster) and is impractical for a novice to assemble by hand. This script is the
"just download the country I care about" alternative: it pulls a country-scoped
slice from the same official sources into the writable /data volume, and the
seeder then runs with --data-root pointing there.

NETWORK DEPENDENCE + SIZE — read this before running
----------------------------------------------------
  * This talks to the live public internet. Every byte is fetched from
    github.com / media.githubusercontent.com (geoBoundaries, Git-LFS backed)
    and data.worldpop.org (WorldPop rasters). If the box is offline, nothing
    here works — use the /archive path instead.
  * geoBoundaries GeoJSON per country: usually a few MB, but high-detail
    coastlines can be 50-100+ MB for a single ADM level (NZL ADM0 ≈ 106 MB).
  * WorldPop 100m constrained rasters are the heavy part: tens of MB for a
    small country, 400-700 MB for the USA, 1-1.5 GB for Russia. A single
    populous country can be a >1 GB download.
  * A FULL-WORLD download would be 14 GB+ and take hours. That is NEVER the
    default here: this script REFUSES to run without an explicit country list
    (see the empty-countries guard in download_datasets()). Full-world belongs
    on the /archive path, not on a live download.

LAYOUT PRODUCED (matches import_geoboundaries.py / import_worldpop.py exactly)
-----------------------------------------------------------------------------
  <data_root>/geoBoundaries_repo/releaseData/geoBoundariesOpen-meta.csv
  <data_root>/geoBoundaries_repo/releaseData/gbOpen/<ISO3>/ADM<n>/geoBoundaries-<ISO3>-ADM<n>.geojson
  <data_root>/worldpop_100m_latest/<iso3_lower>/<iso3_lower>_pop_2023_CN_100m_R2025A_v1.tif

  (import_geoboundaries.discover_geoboundaries_files scans gbOpen/<ISO3>/ADM<n>/;
   import_worldpop.find_worldpop_tif resolves worldpop_100m_latest/<iso3>/ and
   prefers the canonical *_pop_2023_CN_100m_R2025A_v1.tif name — which the
   WorldPop STAC 2023 asset filename already is.)

RESUMABILITY
------------
Every file is downloaded to a "<name>.part" temp then atomically renamed on
success, so a crash never leaves a truncated file that looks complete. On
re-run, any fully-present target file is skipped. Transient HTTP failures
(timeouts, 5xx, connection resets) are retried with backoff.

DEPENDENCIES
------------
Standard library ONLY (urllib, json, csv is not even needed). The ETL image
deliberately does NOT ship `requests` or `jq` (docker/etl/Dockerfile removed
them to keep the arm64/Pi wheel set clean), so this file must not import them.

USAGE
-----
    python3 download_datasets.py --countries NZL --datasets geoboundaries worldpop
    python3 download_datasets.py --countries USA CAN --datasets geoboundaries
    python3 download_datasets.py --countries DEU --data-root /data

The supervisor invokes this before seed_database.py when a wizard request has
source == 'download'; see supervisor.build_download_argv / run_job.
"""

import argparse
import json
import logging
import os
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

import heartbeat

logger = logging.getLogger("download_datasets")

# ─── Upstream endpoints ───────────────────────────────────────────────────────

# geoBoundaries per-boundary metadata API. GET returns a JSON object with a
# `gjDownloadURL` pointing at the raw (LFS-media) GeoJSON. Probing per ADM
# level lets us discover which levels a country actually ships (ADM0..ADM5),
# skipping the ones that 404, WITHOUT a full LFS clone of the 1.5 GB repo.
GEOBOUNDARIES_API = "https://www.geoboundaries.org/api/current/gbOpen/{iso3}/ADM{n}/"

# The supplementary meta CSV import_geoboundaries.load_meta_index() reads. Small
# (~700 rows). /raw/main/ 302-redirects to raw.githubusercontent.com; urllib
# follows it. It's a single shared file, downloaded once per run regardless of
# how many countries are requested.
GEOBOUNDARIES_META_CSV = (
    "https://raw.githubusercontent.com/wmgeolab/geoBoundaries/main/"
    "releaseData/geoBoundariesOpen-meta.csv"
)

# WorldPop STAC API — one collection per ISO3, paginated `items`. We want the
# "Population / 100m / constrained (CN)" asset for the newest year <= 2023
# (WorldPop now ships projected years up to 2030; the app's population model is
# pinned to 2023, and the canonical on-disk filename encodes _2023_).
WORLDPOP_STAC_ITEMS = "https://api.stac.worldpop.org/collections/{iso3}/items"
WORLDPOP_MAX_YEAR = 2023
WORLDPOP_STAC_MAX_PAGES = 200   # generous cap; a collection is ~8 pages today

# geoBoundaries app-side ADM levels to probe. geoBoundaries publishes ADM0..ADM5;
# import_geoboundaries maps geoBoundaries ADM0→app-level-1, etc. We fetch every
# level the country ships; the seeder decides the hierarchy.
GEOBOUNDARIES_ADM_LEVELS = (0, 1, 2, 3, 4, 5)

USER_AGENT = "fair-constitution-etl/1.0 (+https://github.com/CosmopolitanCoalition/fair-constitution-app)"

# HTTP retry policy for transient failures (timeouts, 5xx, resets). 404 is
# terminal (the level/collection simply doesn't exist) and is NOT retried.
MAX_RETRIES = 4
RETRY_BACKOFF_SEC = (2, 5, 15, 30)   # per-attempt sleep; len == MAX_RETRIES
HTTP_TIMEOUT_METADATA = 60           # JSON API calls
HTTP_TIMEOUT_DOWNLOAD = 3600         # a big raster can legitimately take a while
STREAM_CHUNK = 1024 * 256            # 256 KiB streaming reads


# ─── Low-level HTTP helpers (stdlib urllib only) ──────────────────────────────

def _open(url: str, timeout: int):
    """Open a URL with our UA header. Raises urllib.error.* on failure."""
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    return urllib.request.urlopen(req, timeout=timeout)


def _is_retryable(exc: Exception) -> bool:
    """True for transient conditions worth retrying; False for terminal ones
    (notably 404, which means the resource genuinely isn't there)."""
    if isinstance(exc, urllib.error.HTTPError):
        # 404 / 410 → gone for good. 429 + 5xx → transient (server busy).
        return exc.code in (408, 425, 429, 500, 502, 503, 504)
    # URLError (DNS, connection reset, timeout), socket timeouts, etc.
    return True


def fetch_json(url: str) -> dict | None:
    """GET a JSON document with retry. Returns the parsed dict, or None on a
    terminal 404 (caller treats that as "this level/collection doesn't exist").
    Re-raises after exhausting retries on transient errors."""
    last_exc: Exception | None = None
    for attempt in range(MAX_RETRIES):
        try:
            with _open(url, HTTP_TIMEOUT_METADATA) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except urllib.error.HTTPError as exc:
            if exc.code in (404, 410):
                return None
            last_exc = exc
        except Exception as exc:  # noqa: BLE001 — urllib raises a small zoo
            last_exc = exc
        if not _is_retryable(last_exc):
            break
        if attempt < MAX_RETRIES - 1:
            time.sleep(RETRY_BACKOFF_SEC[attempt])
    raise last_exc if last_exc else RuntimeError(f"failed to fetch {url}")


def download_to_file(
    url: str,
    dest: Path,
    label: str,
    bar_key: str | None = None,
) -> bool:
    """
    Stream `url` to `dest` (atomic via a .part temp + rename), with retry on
    transient failures. Skips the work if `dest` already exists (resumability).

    Progress: when the server reports Content-Length, drive the given heartbeat
    bar (bytes) so the wizard's live progress panel shows the download advancing.

    Returns True if the file is present at the end (downloaded now OR already
    there), False if the download ultimately failed.
    """
    if dest.exists() and dest.stat().st_size > 0:
        logger.info("  skip (already present): %s", dest.name)
        return True

    dest.parent.mkdir(parents=True, exist_ok=True)
    part = dest.with_suffix(dest.suffix + ".part")

    last_exc: Exception | None = None
    for attempt in range(MAX_RETRIES):
        try:
            with _open(url, HTTP_TIMEOUT_DOWNLOAD) as resp:
                total = resp.headers.get("Content-Length")
                total = int(total) if total and total.isdigit() else 0
                if bar_key is not None:
                    heartbeat.bar_start(
                        key=bar_key, label=label, total=total, unit="bytes",
                    )
                written = 0
                # Fresh .part each attempt so a partial read from a failed try
                # never bleeds into the next.
                with open(part, "wb") as fh:
                    while True:
                        chunk = resp.read(STREAM_CHUNK)
                        if not chunk:
                            break
                        fh.write(chunk)
                        written += len(chunk)
                        if bar_key is not None:
                            # bar_update throttles disk writes internally, so
                            # calling it per-chunk is cheap.
                            heartbeat.bar_update(
                                bar_key, written,
                                total=total if total else None,
                            )
            # Success — atomically publish.
            os.replace(part, dest)
            if bar_key is not None:
                heartbeat.bar_complete(bar_key, current=written, total=written)
            logger.info("  downloaded %s (%s bytes)", dest.name, f"{written:,}")
            return True
        except Exception as exc:  # noqa: BLE001
            last_exc = exc
            # Clean up the partial so a stale .part doesn't linger.
            try:
                part.unlink(missing_ok=True)
            except OSError:
                pass
            if not _is_retryable(exc):
                logger.error("  FAILED (non-retryable) %s: %s", dest.name, exc)
                break
            if attempt < MAX_RETRIES - 1:
                wait = RETRY_BACKOFF_SEC[attempt]
                logger.warning(
                    "  transient error on %s (attempt %d/%d): %s — retrying in %ds",
                    dest.name, attempt + 1, MAX_RETRIES, exc, wait,
                )
                time.sleep(wait)

    if bar_key is not None:
        heartbeat.bar_complete(bar_key, current=0)
    logger.error("  giving up on %s after %d attempts: %s",
                 dest.name, MAX_RETRIES, last_exc)
    return False


# ─── geoBoundaries ────────────────────────────────────────────────────────────

def download_geoboundaries_meta(gb_release_root: Path) -> bool:
    """Download the shared geoBoundariesOpen-meta.csv (once per run). Non-fatal
    if it fails — import_geoboundaries.load_meta_index() tolerates a missing CSV
    and just runs without supplementary metadata — but we try hard for it."""
    dest = gb_release_root / "geoBoundariesOpen-meta.csv"
    logger.info("geoBoundaries meta CSV → %s", dest)
    heartbeat.write_current(
        name="geoBoundaries metadata", phase="download",
        sub_phase="downloading boundary metadata catalog",
    )
    ok = download_to_file(
        GEOBOUNDARIES_META_CSV, dest,
        label="geoBoundaries — metadata CSV",
        bar_key="download:gb:meta",
    )
    if not ok:
        logger.warning("meta CSV download failed — the seeder will run without "
                       "supplementary metadata (names may fall back to ISO codes "
                       "for a few synthesised rows). Continuing.")
    return ok


def download_geoboundaries_for_country(gbopen_root: Path, iso3: str,
                                       queue_preview: list[str]) -> int:
    """
    Fetch every ADM level geoBoundaries ships for one country into
    gbOpen/<ISO3>/ADM<n>/geoBoundaries-<ISO3>-ADM<n>.geojson.

    Discovers available levels by probing the per-boundary API ADM0..ADM5 and
    skipping the ones that 404. Returns the number of GeoJSON files present for
    this country afterwards (downloaded or already there).
    """
    iso3 = iso3.upper()
    present = 0
    logger.info("=== geoBoundaries: %s ===", iso3)

    for adm_n in GEOBOUNDARIES_ADM_LEVELS:
        api_url = GEOBOUNDARIES_API.format(iso3=iso3, n=adm_n)
        heartbeat.write_current(
            name=iso3, iso_code=iso3, adm_level=adm_n, phase="download",
            sub_phase=f"resolving boundary ADM{adm_n} download URL",
            queue_preview=queue_preview,
        )
        try:
            meta = fetch_json(api_url)
        except Exception as exc:  # noqa: BLE001
            logger.warning("  %s ADM%d: metadata lookup failed after retries: %s "
                           "— skipping this level", iso3, adm_n, exc)
            continue
        if meta is None:
            # 404 — this level doesn't exist for this country. Higher levels
            # won't exist either, but probe them anyway (cheap, and coverage
            # is not always contiguous for every ISO).
            logger.info("  %s ADM%d: not published — skipping", iso3, adm_n)
            continue

        gj_url = meta.get("gjDownloadURL")
        if not gj_url:
            logger.warning("  %s ADM%d: API returned no gjDownloadURL — skipping",
                           iso3, adm_n)
            continue

        dest = (gbopen_root / iso3 / f"ADM{adm_n}"
                / f"geoBoundaries-{iso3}-ADM{adm_n}.geojson")
        heartbeat.write_current(
            name=iso3, iso_code=iso3, adm_level=adm_n, phase="download",
            sub_phase=f"downloading boundary ADM{adm_n}",
            queue_preview=queue_preview,
        )
        if download_to_file(
            gj_url, dest,
            label=f"{iso3} — boundary ADM{adm_n}",
            bar_key=f"download:gb:{iso3}:adm{adm_n}",
        ):
            present += 1

    if present == 0:
        logger.warning("  %s: no geoBoundaries GeoJSON obtained — the seeder "
                       "will have nothing to import for this ISO.", iso3)
    else:
        logger.info("  %s: %d boundary file(s) ready", iso3, present)
    return present


# ─── WorldPop ─────────────────────────────────────────────────────────────────

def resolve_worldpop_asset(iso3: str) -> tuple[int, str, str] | None:
    """
    Page through the WorldPop STAC collection for this ISO3 and return the
    (year, download_href, item_id) for the newest "Population / 100m /
    constrained" raster with year <= WORLDPOP_MAX_YEAR.

    Mirrors the selection logic in docs/fetch_worldpop.sh (project == Population,
    resolution == 100m, id matches _CN_100m_, highest year, id tiebreak).

    Returns None if the collection doesn't exist or has no qualifying asset.
    """
    iso3 = iso3.upper()
    url: str | None = WORLDPOP_STAC_ITEMS.format(iso3=iso3)
    best: tuple[int, str, str] | None = None
    pages = 0

    while url and pages < WORLDPOP_STAC_MAX_PAGES:
        pages += 1
        try:
            doc = fetch_json(url)
        except Exception as exc:  # noqa: BLE001
            logger.warning("  %s: STAC page %d failed after retries: %s",
                           iso3, pages, exc)
            break
        if doc is None:
            # 404 on page 1 → no such collection.
            if pages == 1:
                logger.info("  %s: no WorldPop collection", iso3)
            break

        for feat in doc.get("features", []) or []:
            props = feat.get("properties", {}) or {}
            fid = feat.get("id") or ""
            if (props.get("project") != "Population"
                    or props.get("resolution") != "100m"
                    or "_CN_100m_" not in fid):
                continue
            try:
                year = int(props.get("year"))
            except (TypeError, ValueError):
                continue
            if year > WORLDPOP_MAX_YEAR:
                continue
            href = ((feat.get("assets", {}) or {}).get("data", {}) or {}).get("href")
            if not href:
                continue
            if (best is None or year > best[0]
                    or (year == best[0] and fid > best[2])):
                best = (year, href, fid)

        # Follow STAC pagination (rel == next).
        nxt = None
        for link in doc.get("links", []) or []:
            if link.get("rel") == "next":
                nxt = link.get("href")
                break
        url = nxt

    return best


def download_worldpop_for_country(worldpop_root: Path, iso3: str,
                                  queue_preview: list[str]) -> bool:
    """
    Resolve + download the country's WorldPop 100m constrained raster into
    worldpop_100m_latest/<iso3_lower>/<filename>.

    The STAC 2023 asset filename is already the canonical
    <iso>_pop_2023_CN_100m_R2025A_v1.tif that import_worldpop.find_worldpop_tif
    prefers, so no rename is needed. Returns True if a raster is present after,
    False if none was found/downloaded.

    A missing raster is NOT fatal to the pipeline: import_worldpop treats a
    country with no own TIF as "topological fallback only" (population comes
    from overlapping neighbour rasters where they exist). We log and move on.
    """
    iso3 = iso3.upper()
    logger.info("=== WorldPop: %s ===", iso3)
    heartbeat.write_current(
        name=iso3, iso_code=iso3, adm_level=1, phase="download",
        sub_phase="resolving population raster (WorldPop STAC)",
        queue_preview=queue_preview,
    )

    asset = resolve_worldpop_asset(iso3)
    if asset is None:
        logger.info("  %s: no 100m constrained population raster available "
                    "(topological fallback will apply at population time)", iso3)
        return False

    year, href, item_id = asset
    filename = href.rsplit("/", 1)[-1]
    dest = worldpop_root / iso3.lower() / filename
    logger.info("  %s: WorldPop year %d → %s", iso3, year, filename)
    heartbeat.write_current(
        name=iso3, iso_code=iso3, adm_level=1, phase="download",
        sub_phase=f"downloading population raster ({year})",
        queue_preview=queue_preview,
    )
    return download_to_file(
        href, dest,
        label=f"{iso3} — population raster {year}",
        bar_key=f"download:wp:{iso3}",
    )


# ─── Orchestration ────────────────────────────────────────────────────────────

def download_datasets(
    countries: list[str],
    datasets: list[str],
    data_root: Path,
    log: logging.Logger | None = None,
) -> int:
    """
    Download the requested OPEN datasets for the given countries into `data_root`
    in the exact layout seed_database.py reads.

    Args:
        countries: ISO3 codes to fetch. MUST be non-empty — a full-world download
                   is 14 GB+ and is intentionally refused here (use /archive).
        datasets:  which of {"geoboundaries", "worldpop"} to fetch.
        data_root: target root (e.g. /data). Subtrees geoBoundaries_repo/ and
                   worldpop_100m_latest/ are created under it.

    Returns 0 on success, non-zero on a fatal condition (empty country list,
    or every requested country yielding no boundary data).
    """
    global logger
    if log is not None:
        logger = log

    # ── Guard: never allow an unscoped full-world download. ──
    countries = [c.strip().upper() for c in (countries or []) if c and c.strip()]
    if not countries:
        logger.error(
            "download_datasets refuses to run without a country filter. A "
            "full-world download from the official hosts is 14 GB+ and would "
            "take hours — that is what the /archive path is for. Re-run scoped "
            "to specific ISO3 codes, e.g. --countries NZL, or point the wizard's "
            "source at the staged archive instead."
        )
        return 2

    datasets = [d.strip().lower() for d in (datasets or []) if d and d.strip()]
    if not datasets:
        # Nothing to do is treated as an explicit no-op rather than an error —
        # the supervisor only calls us when at least one dataset was requested,
        # but be defensive.
        logger.warning("download_datasets called with no datasets — nothing to do.")
        return 0

    want_gb = "geoboundaries" in datasets
    want_wp = "worldpop" in datasets

    gb_release_root = data_root / "geoBoundaries_repo" / "releaseData"
    gbopen_root = gb_release_root / "gbOpen"
    worldpop_root = data_root / "worldpop_100m_latest"

    logger.info("╔══════════════════════════════════════════════╗")
    logger.info("║   Download from official sources (scoped)     ║")
    logger.info("╚══════════════════════════════════════════════╝")
    logger.info("Started at %s", datetime.now(timezone.utc).isoformat())
    logger.info("Countries: %s", ", ".join(countries))
    logger.info("Datasets:  %s", ", ".join(datasets))
    logger.info("Data root: %s", data_root)
    logger.warning(
        "NETWORK: this fetches live data from github.com + data.worldpop.org. "
        "WorldPop rasters can be hundreds of MB to >1 GB per country."
    )

    heartbeat.set_phase("download")

    # geoBoundaries meta CSV once up front (shared across all countries).
    if want_gb:
        download_geoboundaries_meta(gb_release_root)

    gb_countries_with_data = 0
    for idx, iso3 in enumerate(countries):
        queue_preview = countries[idx + 1: idx + 3]
        logger.info("")
        logger.info("──── country %d/%d: %s ────", idx + 1, len(countries), iso3)

        if want_gb:
            n_files = download_geoboundaries_for_country(
                gbopen_root, iso3, queue_preview,
            )
            if n_files > 0:
                gb_countries_with_data += 1

        if want_wp:
            download_worldpop_for_country(worldpop_root, iso3, queue_preview)

    heartbeat.clear_current()
    logger.info("")
    logger.info("Download stage complete.")

    # Fatal only if we were asked for boundaries and got NONE for any country —
    # that means the seeder would have nothing to import. WorldPop-only failures
    # are non-fatal (topological fallback covers gaps).
    if want_gb and gb_countries_with_data == 0:
        logger.error(
            "No geoBoundaries data obtained for ANY requested country — the "
            "seeder would import nothing. Check the ISO3 codes and network."
        )
        return 1
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Download official OPEN geodata (geoBoundaries + WorldPop) "
                    "into the ETL data volume, country-scoped.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__,
    )
    parser.add_argument(
        "--countries", nargs="+", metavar="ISO3", required=True,
        help="ISO3 country codes to download (REQUIRED — full-world is refused).",
    )
    parser.add_argument(
        "--datasets", nargs="+", metavar="NAME",
        choices=["geoboundaries", "worldpop"],
        default=["geoboundaries", "worldpop"],
        help="Which datasets to fetch (default: both).",
    )
    parser.add_argument(
        "--data-root", default=os.environ.get("DATA_ROOT", "/data"),
        help="Target root directory (default: env DATA_ROOT or /data). The "
             "geoBoundaries_repo/ and worldpop_100m_latest/ subtrees are "
             "created under it, and this is the path the seeder should read "
             "via --data-root.",
    )
    parser.add_argument(
        "--log-file", default="/etl/etl.log",
        help="Log file to append to (default: /etl/etl.log — same file the "
             "supervisor tails for the wizard UI).",
    )
    args = parser.parse_args()

    # Log to stdout AND the shared etl.log so the wizard's log-tail panel sees
    # the download alongside the seed run. Mirrors seed_database.setup_logging.
    fmt = "%(asctime)s [%(levelname)-5s] %(name)s: %(message)s"
    datefmt = "%Y-%m-%d %H:%M:%S"
    root = logging.getLogger()
    root.setLevel(logging.DEBUG)
    ch = logging.StreamHandler(sys.stdout)
    ch.setLevel(logging.INFO)
    ch.setFormatter(logging.Formatter(fmt, datefmt))
    root.addHandler(ch)
    try:
        fh = logging.FileHandler(args.log_file)
        fh.setLevel(logging.DEBUG)
        fh.setFormatter(logging.Formatter(fmt, datefmt))
        root.addHandler(fh)
    except OSError as exc:
        logging.warning("could not open log file %s: %s", args.log_file, exc)

    return download_datasets(
        countries=args.countries,
        datasets=args.datasets,
        data_root=Path(args.data_root),
        log=logging.getLogger("download_datasets"),
    )


if __name__ == "__main__":
    sys.exit(main())
