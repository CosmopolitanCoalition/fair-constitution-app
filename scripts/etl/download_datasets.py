"""
download_datasets.py — Fetch the official OPEN geodata (geoBoundaries + WorldPop
+ optional Protomaps basemap) straight from the upstream hosts into the ETL data
volume, laid out EXACTLY the way seed_database.py's importers expect to read it.

WHY THIS EXISTS
---------------
The normal setup path stages a full-world archive on disk (the map-files
archive, mounted read-only at /archive) and the seeder runs against
DATA_ROOT=/archive.
That archive is ~14 GB (geoBoundaries gbOpen + every country's WorldPop 100m
raster) and is impractical for a novice to assemble by hand. This script is the
"just download what I care about" alternative: it pulls a country-scoped (or,
if the operator asks, a FULL-WORLD) slice from the same official sources into
the writable /data volume, and the seeder then runs with --data-root pointing
there.

NETWORK DEPENDENCE + SIZE — read this before running
----------------------------------------------------
  * This talks to the live public internet. Every byte is fetched from
    github.com / media.githubusercontent.com (geoBoundaries, Git-LFS backed),
    data.worldpop.org + api.stac.worldpop.org (WorldPop rasters) and
    build.protomaps.com (the Protomaps planet basemap). If the box is offline,
    nothing here works — use the /archive path instead.
  * geoBoundaries GeoJSON per country: usually a few MB, but high-detail
    coastlines can be 50-100+ MB for a single ADM level (NZL ADM0 ≈ 106 MB).
  * WorldPop 100m constrained rasters are the heavy part: tens of MB for a
    small country, 400-700 MB for the USA, 1-1.5 GB for Russia. A single
    populous country can be a >1 GB download.
  * A FULL-WORLD geodata download (empty --countries) is 14 GB+ and takes HOURS.
    It is allowed but loudly warned about. Full-world normally belongs on the
    /archive path, not on a live download.
  * The Protomaps PLANET basemap (build.protomaps.com) is ~100 GB. Downloading
    it is a serious commitment of disk and bandwidth. It is only fetched when
    'protomaps' is explicitly in --datasets, and the size is warned about hard.

LAYOUT PRODUCED (matches import_geoboundaries.py / import_worldpop.py exactly)
-----------------------------------------------------------------------------
  <data_root>/geoBoundaries_repo/releaseData/<release>-meta.csv
  <data_root>/geoBoundaries_repo/releaseData/gbOpen/<ISO3>/ADM<n>/geoBoundaries-<ISO3>-ADM<n>.geojson
  <data_root>/worldpop_100m_latest/<iso3_lower>/<file>.tif
  <data_root>/protomaps/<YYYYMMDD>.pmtiles        (Protomaps planet basemap)

  IMPORTANT — the seeder ALWAYS reads geoBoundaries GeoJSON from the `gbOpen/`
  subdirectory (import_geoboundaries.GBOPEN_ROOT is hardcoded to
  releaseData/gbOpen). So even when the operator picks gbHumanitarian or
  gbAuthoritative, the fetched files are written UNDER gbOpen/<ISO3>/ADM<n>/
  with the canonical geoBoundaries-<ISO3>-ADM<n>.geojson name — that is where
  the importer looks, regardless of which release the bytes actually came from.
  The release only changes WHICH upstream URL we fetch; the on-disk path is
  fixed so the seeder finds it.

  Likewise import_worldpop.find_worldpop_tif reads
  worldpop_100m_latest/<iso>/ and prefers the canonical
  *_pop_2023_CN_100m_R2025A_v1.tif name but falls back to the FIRST *.tif in
  sorted order. So a non-2023 / unconstrained / 1km / UN-adjusted raster with a
  different WorldPop filename still gets picked up as long as it lands in that
  per-iso directory — we keep the upstream filename verbatim.

PROTOMAPS — WHERE IT LANDS AND WHAT THE OPERATOR MUST DO
--------------------------------------------------------
The etl container does NOT have the Protomaps app mount. In docker-compose.yml,
${PROTOMAPS_DIR:-./data/protomaps} is bound (read-only) into the app / nginx /
vite containers at /var/www/html/public/maps/protomaps, but NOT into the etl
service. The etl service only has the writable `etl_geodata` named volume at
/data. So the Protomaps file is written to /data/protomaps/<YYYYMMDD>.pmtiles
inside that volume, and this script logs, LOUDLY, the exact path the operator
must then point PROTOMAPS_DIR at (the host location of the etl_geodata volume's
protomaps/ subdir) before the app can serve it. There is no way for this script
to write directly into the app's protomaps mount from the etl container.

WORLDPOP VARIANTS — WHAT THE STAC ACTUALLY OFFERS (be honest)
-------------------------------------------------------------
The WorldPop STAC API (api.stac.worldpop.org) currently serves ONLY the R2025A
"Global_2015_2030" release: CONSTRAINED (CN) products at 100m and 1km, for
years 2015–2030. As of this writing the STAC does NOT expose the 2023 vintage
(the canonical filename the seeder prefers encodes _2023_, but the STAC's
lowest year is 2015 and per-country `items` pages surface the newest years
first) and does NOT expose UNCONSTRAINED (UC) or UN-ADJUSTED (UNadj) products
at all — those live under separate roots on data.worldpop.org and are NOT in
the STAC catalog.

Consequences honored here:
  * --wp-variant constrained (default): resolve via the STAC. Pick the newest
    CN asset at the requested resolution with year <= --wp-year; if NONE is
    <= that year (e.g. the STAC's floor is above it), fall back to the newest
    available CN asset overall so a raster is still fetched.
  * --wp-variant unconstrained  and/or  --wp-un-adjusted: the STAC has no such
    asset, so we CONSTRUCT the canonical data.worldpop.org URL for the
    Global_2000_2020 unconstrained / UN-adjusted product family and fetch it
    directly, probing a small set of known filename shapes. This is best-effort
    and network-dependent; if none resolve, the country is logged as "no raster"
    and the topological fallback in import_worldpop covers it at population time.

RESUMABILITY
------------
Every file is downloaded to a "<name>.part" temp then atomically renamed on
success, so a crash never leaves a truncated file that looks complete. On
re-run, any fully-present target file is skipped. Transient HTTP failures
(timeouts, 5xx, connection resets) are retried with backoff. The ~100 GB
Protomaps download is likewise resumable at file granularity (skip if the dated
.pmtiles is already fully present), though a partial .part is re-fetched from
scratch — HTTP range-resume of a partial is a future refinement.

DEPENDENCIES
------------
Standard library ONLY (urllib, json). The ETL image deliberately does NOT ship
`requests` or `jq` (docker/etl/Dockerfile removed them to keep the arm64/Pi
wheel set clean), so this file must not import them.

USAGE
-----
    # Country-scoped, all defaults (gbOpen, WorldPop CN 100m newest<=2023):
    python3 download_datasets.py --countries NZL --datasets geoboundaries worldpop

    # Full world (EMPTY country list = every ISO3 the release ships):
    python3 download_datasets.py --countries --datasets geoboundaries worldpop

    # Variant knobs:
    python3 download_datasets.py --countries DEU \
        --datasets geoboundaries worldpop \
        --gb-release gbHumanitarian \
        --wp-year 2020 --wp-variant unconstrained --wp-resolution 1km \
        --wp-un-adjusted

    # Protomaps planet basemap (~100 GB!):
    python3 download_datasets.py --countries NZL --datasets protomaps

The supervisor invokes this before seed_database.py when a wizard request has
source == 'download'; see supervisor.build_download_argv / run_download_step.
"""

import argparse
import csv
import io
import json
import logging
import os
import sys
import threading
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
from pathlib import Path

import heartbeat

logger = logging.getLogger("download_datasets")

# ─── Upstream endpoints ───────────────────────────────────────────────────────

# geoBoundaries per-boundary metadata API. GET returns a JSON object with a
# `gjDownloadURL` pointing at the raw (LFS-media) GeoJSON. Probing per ADM
# level lets us discover which levels a country actually ships (ADM0..ADM5),
# skipping the ones that 404, WITHOUT a full LFS clone of the 1.5 GB repo.
# {release} is one of gbOpen | gbHumanitarian | gbAuthoritative.
GEOBOUNDARIES_API = "https://www.geoboundaries.org/api/current/{release}/{iso3}/ADM{n}/"

# The special ISO code "ALL" against the API returns a JSON ARRAY with one
# object per country boundary at that ADM level (each carrying boundaryISO +
# gjDownloadURL). We hit ADM0/ALL once to enumerate every ISO3 the release
# ships when the operator requests a full-world download (empty --countries).
GEOBOUNDARIES_API_ALL = "https://www.geoboundaries.org/api/current/{release}/ALL/ADM0/"

# The supplementary meta CSV import_geoboundaries.load_meta_index() reads. Small
# (~700 rows). Each release publishes its own meta CSV; the file the importer
# reads is releaseData/<Release>-meta.csv. The importer's META_CSV constant is
# hardcoded to geoBoundariesOpen-meta.csv, so we ALWAYS write the fetched CSV to
# that filename (whatever release it came from) — see download_geoboundaries_meta.
GEOBOUNDARIES_META_CSV = {
    "gbOpen": (
        "https://raw.githubusercontent.com/wmgeolab/geoBoundaries/main/"
        "releaseData/geoBoundariesOpen-meta.csv"
    ),
    "gbHumanitarian": (
        "https://raw.githubusercontent.com/wmgeolab/geoBoundaries/main/"
        "releaseData/geoBoundariesHumanitarian-meta.csv"
    ),
    "gbAuthoritative": (
        "https://raw.githubusercontent.com/wmgeolab/geoBoundaries/main/"
        "releaseData/geoBoundariesAuthoritative-meta.csv"
    ),
}

# ─── THE EQUIVALENCE PIN (operator ruling 2026-08-28) ─────────────────────────
# The reference archive (the operator's local mirror, the substrate under every
# accepted map standard) is a geoBoundaries clone at ONE exact commit. gbOpen
# on the moving main branch receives daily bot merges, so an unpinned download
# cannot reproduce the reference archive or its map mathematics.
#
# When GB_PIN_REF is non-empty (default = the reference commit), boundary
# GeoJSONs are fetched AT THAT REF via GitHub's LFS media host, the meta CSV
# at the same ref via the raw host, and the full-world ISO list is parsed from
# that pinned meta CSV — byte-identical to the reference archive. Set
# GB_PIN_REF= (empty) in the etl environment to fall back to the live
# current-release API instead.
GEOBOUNDARIES_PIN_REF = os.environ.get(
    "GB_PIN_REF", "78a697d230c11919c50c0fea5565c9fd021e0800"
).strip()
# GeoJSONs are Git-LFS objects; only the media host serves their CONTENT at a
# pinned ref (raw.githubusercontent.com would return the tiny LFS pointer file).
GEOBOUNDARIES_PINNED_FILE = (
    "https://media.githubusercontent.com/media/wmgeolab/geoBoundaries/"
    "{ref}/releaseData/{release}/{iso3}/ADM{n}/geoBoundaries-{iso3}-ADM{n}.geojson"
)
# The meta CSV is a regular git blob (not LFS) — the raw host serves it at a ref.
GEOBOUNDARIES_PINNED_META = (
    "https://raw.githubusercontent.com/wmgeolab/geoBoundaries/"
    "{ref}/releaseData/geoBoundaries{name}-meta.csv"
)
GEOBOUNDARIES_RELEASE_NAME = {
    "gbOpen": "Open",
    "gbHumanitarian": "Humanitarian",
    "gbAuthoritative": "Authoritative",
}

# WorldPop frozen-release direct URL (preferred over the STAC for the default
# constrained product): R2025A v1 is a FROZEN release, so these files are the
# exact bytes of the reference archive's *_pop_{year}_CN_{res}_R2025A_v1.tif
# rasters. The STAC is a moving catalog (it may drop old vintages or serve a
# newer revision); the frozen URL cannot drift. Verified live for the 2023
# 100m constrained product. Falls back to the STAC on a 404.
WORLDPOP_R2025A_FILE = (
    "https://data.worldpop.org/GIS/Population/Global_2015_2030/R2025A/"
    "{year}/{iso_u}/v1/{res}/constrained/{iso_l}_pop_{year}_CN_{res}_R2025A_v1.tif"
)

# WorldPop STAC API — one collection per ISO3, paginated `items`. The R2025A
# "Global_2015_2030" release this serves is CONSTRAINED (CN) only, at 100m and
# 1km, years 2015–2030. We pick the newest qualifying CN asset for the chosen
# resolution with year <= --wp-year (falling back to the newest available if
# none is <= that year — the STAC no longer carries the old 2023 vintage the
# canonical on-disk filename encodes).
WORLDPOP_STAC_ITEMS = "https://api.stac.worldpop.org/collections/{iso3}/items"
WORLDPOP_STAC_MAX_PAGES = 200   # generous cap; a collection is ~8 pages today

# Default target year when --wp-year is unset. Kept at the historical 2023 so
# the "newest CN asset with year <= 2023" selection matches the legacy behavior
# when the STAC still carried pre-2024 years; when it doesn't, resolve_worldpop
# widens to the newest available so a raster is still fetched.
WORLDPOP_DEFAULT_YEAR = 2023

# Direct data.worldpop.org roots for the products the STAC does NOT expose
# (unconstrained + UN-adjusted). These are constructed URLs, not catalog
# lookups — WorldPop's directory layout is stable but this is best-effort and
# purely network-dependent. The filename shapes below are the documented
# conventions for the Global_2000_2020 unconstrained family and the
# constrained-2020 UN-adjusted family.
#
#   Unconstrained individual-country (ppp) — Global_2000_2020:
#     .../GIS/Population/Global_2000_2020/<year>/<ISO3>/<iso3>_ppp_<year>.tif
#     UN-adjusted variant of the same:
#     .../GIS/Population/Global_2000_2020/<year>/<ISO3>/<iso3>_ppp_<year>_UNadj.tif
#   Unconstrained 1km mosaics live under Global_2000_2020_1km / _1km_UNadj.
#
# WorldPop's unconstrained series tops out at 2020; a requested wp_year above
# that is clamped to 2020 for these products (logged).
WORLDPOP_UC_BASE = "https://data.worldpop.org/GIS/Population"
WORLDPOP_UC_MAX_YEAR = 2020

# WorldPop legacy ISO3 aliases — some countries appear in WorldPop's catalogs
# under a DIFFERENT code than the geoBoundaries ISO3 everything else keys on.
# Verified: Kosovo is XKX in geoBoundaries, but the R2025A constrained STAC has
# NO Kosovo collection under ANY code, and in the legacy Global_2000_2020
# direct-URL series .../2020/XKX/ 404s while .../2020/KOS/kos_ppp_2020.tif is
# present. When the primary iso resolves nothing (on either the STAC path or
# the constructed direct-URL path), each alias is tried before giving up; a
# hit is logged loudly. The raster still lands under the PRIMARY iso's
# directory so the seeder finds it.
WORLDPOP_ISO_ALIASES: dict[str, list[str]] = {"XKX": ["KOS"]}

# geoBoundaries app-side ADM levels to probe. geoBoundaries publishes ADM0..ADM5;
# import_geoboundaries maps geoBoundaries ADM0→app-level-1, etc. We fetch every
# level the country ships; the seeder decides the hierarchy.
GEOBOUNDARIES_ADM_LEVELS = (0, 1, 2, 3, 4, 5)

# Protomaps daily planet builds. The build server publishes a dated planet
# .pmtiles at https://build.protomaps.com/<YYYYMMDD>.pmtiles. We resolve the
# newest available date by probing backwards from today (a build isn't posted
# every single day). ~100 GB per file — warned about hard at the call site.
PROTOMAPS_BUILD_URL = "https://build.protomaps.com/{date}.pmtiles"
PROTOMAPS_PROBE_DAYS = 14   # look back up to two weeks for the latest build

USER_AGENT = "fair-constitution-etl/1.0 (+https://github.com/CosmopolitanCoalition/fair-constitution-app)"

# HTTP retry policy for transient failures (timeouts, 5xx, resets). 404 is
# terminal (the level/collection simply doesn't exist) and is NOT retried.
MAX_RETRIES = 4
RETRY_BACKOFF_SEC = (2, 5, 15, 30)   # per-attempt sleep; len == MAX_RETRIES
HTTP_TIMEOUT_METADATA = 60           # JSON API calls
HTTP_TIMEOUT_DOWNLOAD = 3600         # a big raster can legitimately take a while
STREAM_CHUNK = 1024 * 256            # 256 KiB streaming reads


# ─── Low-level HTTP helpers (stdlib urllib only) ──────────────────────────────

def _open(url: str, timeout: int, method: str = "GET"):
    """Open a URL with our UA header. Raises urllib.error.* on failure."""
    req = urllib.request.Request(
        url, headers={"User-Agent": USER_AGENT}, method=method,
    )
    return urllib.request.urlopen(req, timeout=timeout)


def _open_ranged(url: str, timeout: int, resume_from: int):
    """Open a URL requesting bytes from `resume_from`. Returns (resp,
    resumed): resumed is True when the server honored the Range (206) and the
    stream continues mid-file; False when it ignored it (200, full body)."""
    headers = {"User-Agent": USER_AGENT}
    if resume_from > 0:
        headers["Range"] = f"bytes={resume_from}-"
    req = urllib.request.Request(url, headers=headers, method="GET")
    resp = urllib.request.urlopen(req, timeout=timeout)
    return resp, (resume_from > 0 and getattr(resp, "status", 200) == 206)


def _is_retryable(exc: Exception) -> bool:
    """True for transient conditions worth retrying; False for terminal ones
    (notably 404, which means the resource genuinely isn't there)."""
    if isinstance(exc, urllib.error.HTTPError):
        # 404 / 410 → gone for good. 429 + 5xx → transient (server busy).
        return exc.code in (408, 425, 429, 500, 502, 503, 504)
    # URLError (DNS, connection reset, timeout), socket timeouts, etc.
    return True


def fetch_json(url: str):
    """GET a JSON document with retry. Returns the parsed value (dict OR list —
    the geoBoundaries ALL endpoint returns an array), or None on a terminal 404
    (caller treats that as "this level/collection doesn't exist"). Re-raises
    after exhausting retries on transient errors."""
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


def url_exists(url: str) -> bool:
    """Best-effort HEAD probe: True if the URL responds 200-ish, False on 404.
    Used to resolve which constructed data.worldpop.org / build.protomaps.com
    URL actually exists before committing to a (potentially huge) download.
    A transient/network error is treated as 'unknown' → False (caller moves on
    to the next candidate rather than blocking)."""
    try:
        with _open(url, HTTP_TIMEOUT_METADATA, method="HEAD") as resp:
            code = getattr(resp, "status", 200)
            return 200 <= int(code) < 400
    except urllib.error.HTTPError as exc:
        # Some hosts reject HEAD (405) but serve GET fine — treat 405 as "try it".
        if exc.code == 405:
            return True
        return False
    except Exception:  # noqa: BLE001
        return False


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
    attempts_without_progress = 0
    while attempts_without_progress < MAX_RETRIES:
        written_this_attempt = 0
        try:
            # HTTP RANGE RESUME (2026-08-29): a surviving .part continues from
            # its last byte instead of restarting from zero — a killed 100 GB
            # basemap haul at 8% used to discard 10+ GB. When the server
            # ignores the Range (200), the .part is truncated and the fetch
            # starts clean, exactly as before.
            resume_from = 0
            try:
                if part.exists():
                    resume_from = part.stat().st_size
            except OSError:
                resume_from = 0
            resp, resumed = _open_ranged(url, HTTP_TIMEOUT_DOWNLOAD, resume_from)
            with resp:
                length = resp.headers.get("Content-Length")
                length = int(length) if length and length.isdigit() else 0
                if resumed:
                    total = resume_from + length
                    written = resume_from
                    mode = "ab"
                    logger.info("  resuming %s at byte %s", dest.name,
                                f"{resume_from:,}")
                else:
                    total = length
                    written = 0
                    mode = "wb"
                if bar_key is not None:
                    heartbeat.bar_start(
                        key=bar_key, label=label, total=total, unit="bytes",
                    )
                    if written:
                        heartbeat.bar_update(bar_key, written, force=True,
                                             total=total if total else None)
                with open(part, mode) as fh:
                    while True:
                        chunk = resp.read(STREAM_CHUNK)
                        if not chunk:
                            break
                        fh.write(chunk)
                        written += len(chunk)
                        written_this_attempt += len(chunk)
                        if bar_key is not None:
                            # bar_update throttles disk writes internally, so
                            # calling it per-chunk is cheap.
                            heartbeat.bar_update(
                                bar_key, written,
                                total=total if total else None,
                            )
            # COMPLETION VALIDATION (2026-08-29): end-of-stream is NOT
            # success. The protomaps host closed the stream at 16.8 GB of a
            # 128 GB build and the old code stamped the stump as done. When
            # the expected size is known, a short read keeps the .part and
            # goes back around the resume loop.
            if total and written != total:
                raise IOError(
                    f"short read: {written:,} of {total:,} bytes — stream "
                    f"closed early; resuming"
                )
            # Success — atomically publish.
            os.replace(part, dest)
            if bar_key is not None:
                heartbeat.bar_complete(bar_key, current=written, total=written)
            logger.info("  downloaded %s (%s bytes)", dest.name, f"{written:,}")
            return True
        except Exception as exc:  # noqa: BLE001
            last_exc = exc
            # KEEP the partial — the next attempt resumes from its last byte
            # via the Range header above.
            if not _is_retryable(exc):
                logger.error("  FAILED (non-retryable) %s: %s", dest.name, exc)
                break
            # An attempt that moved bytes forward does not burn the retry
            # budget — a 128 GB haul over a connection that drops hourly
            # must grind forward, not give up after MAX_RETRIES drops.
            if written_this_attempt > 0:
                logger.warning(
                    "  transient error on %s after +%s bytes: %s — resuming",
                    dest.name, f"{written_this_attempt:,}", exc,
                )
                continue
            attempts_without_progress += 1
            if attempts_without_progress < MAX_RETRIES:
                wait = RETRY_BACKOFF_SEC[
                    min(attempts_without_progress - 1, len(RETRY_BACKOFF_SEC) - 1)
                ]
                logger.warning(
                    "  transient error on %s (no-progress attempt %d/%d): %s — "
                    "retrying in %ds",
                    dest.name, attempts_without_progress, MAX_RETRIES, exc, wait,
                )
                time.sleep(wait)

    if bar_key is not None:
        heartbeat.bar_complete(bar_key, current=0)
    logger.error("  giving up on %s after %d attempts: %s",
                 dest.name, MAX_RETRIES, last_exc)
    return False


# ─── geoBoundaries ────────────────────────────────────────────────────────────

def enumerate_all_geoboundaries_isos(release: str) -> list[str]:
    """
    Full-world helper: hit the geoBoundaries ALL/ADM0 endpoint for the chosen
    release and return every ISO3 it ships. Used when the operator requests an
    unscoped (empty --countries) download.

    Returns a sorted, de-duplicated list of ISO3 codes. Empty on failure (the
    caller then aborts with a clear message rather than silently downloading
    nothing).
    """
    # Pinned ref: enumerate from the pinned meta CSV — the live ALL endpoint
    # describes the CURRENT release, which is not the pinned tree.
    if GEOBOUNDARIES_PIN_REF:
        name = GEOBOUNDARIES_RELEASE_NAME.get(release, "Open")
        url = GEOBOUNDARIES_PINNED_META.format(ref=GEOBOUNDARIES_PIN_REF, name=name)
        logger.info("Enumerating ISO3 codes from the PINNED meta CSV "
                    "(ref %s, %s)", GEOBOUNDARIES_PIN_REF[:9], url)
        try:
            with _open(url, timeout=120) as resp:
                text = resp.read().decode("utf-8", errors="replace")
            rows = csv.DictReader(io.StringIO(text))
            isos = sorted({
                str(r.get("boundaryISO", "")).strip().upper()
                for r in rows
                if str(r.get("boundaryISO", "")).strip()
            })
        except Exception as exc:  # noqa: BLE001
            logger.error("Pinned enumeration failed: %s — refusing to mix a "
                         "pinned download with live enumeration", exc)
            return []
        logger.info("Pinned enumeration: %d ISO3 codes at ref %s",
                    len(isos), GEOBOUNDARIES_PIN_REF[:9])
        return isos

    url = GEOBOUNDARIES_API_ALL.format(release=release)
    logger.info("Enumerating ALL ISO3 codes for release %s (%s)", release, url)
    try:
        doc = fetch_json(url)
    except Exception as exc:  # noqa: BLE001
        logger.error("Full-world enumeration failed: %s", exc)
        return []
    if not isinstance(doc, list):
        logger.error("ALL endpoint returned unexpected shape (%s) — cannot "
                     "enumerate isos for a full-world run", type(doc).__name__)
        return []
    isos = sorted({
        str(row.get("boundaryISO", "")).strip().upper()
        for row in doc
        if str(row.get("boundaryISO", "")).strip()
    })
    logger.info("Full-world enumeration: %d ISO3 codes from release %s",
                len(isos), release)
    return isos


def download_geoboundaries_meta(gb_release_root: Path, release: str) -> bool:
    """Download the shared meta CSV (once per run). Non-fatal if it fails —
    import_geoboundaries.load_meta_index() tolerates a missing CSV and just runs
    without supplementary metadata — but we try hard for it.

    NB: the importer's META_CSV is hardcoded to <root>/geoBoundariesOpen-meta.csv
    regardless of release, so we always write to THAT filename even when the
    bytes came from the Humanitarian/Authoritative release CSV."""
    dest = gb_release_root / "geoBoundariesOpen-meta.csv"
    if GEOBOUNDARIES_PIN_REF:
        src_url = GEOBOUNDARIES_PINNED_META.format(
            ref=GEOBOUNDARIES_PIN_REF,
            name=GEOBOUNDARIES_RELEASE_NAME.get(release, "Open"),
        )
    else:
        src_url = GEOBOUNDARIES_META_CSV.get(release, GEOBOUNDARIES_META_CSV["gbOpen"])
    logger.info("geoBoundaries meta CSV (%s) → %s", release, dest)
    heartbeat.write_current(
        name="geoBoundaries metadata", phase="download",
        sub_phase="downloading boundary metadata catalog",
    )
    ok = download_to_file(
        src_url, dest,
        label="geoBoundaries — metadata CSV",
        bar_key="download:gb:meta",
    )
    if not ok:
        logger.warning("meta CSV download failed — the seeder will run without "
                       "supplementary metadata (names may fall back to ISO codes "
                       "for a few synthesised rows). Continuing.")
    return ok


def download_geoboundaries_for_country(gbopen_root: Path, iso3: str,
                                       release: str,
                                       queue_preview: list[str]) -> int:
    """
    Fetch every ADM level geoBoundaries ships for one country into
    gbOpen/<ISO3>/ADM<n>/geoBoundaries-<ISO3>-ADM<n>.geojson.

    Discovers available levels by probing the per-boundary API ADM0..ADM5 and
    skipping the ones that 404. `release` selects gbOpen/gbHumanitarian/
    gbAuthoritative for the UPSTREAM URL only — the on-disk path stays under
    gbOpen/ because that's the single directory the importer reads.

    Returns the number of GeoJSON files present for this country afterwards
    (downloaded or already there).
    """
    iso3 = iso3.upper()
    present = 0
    logger.info("=== geoBoundaries (%s): %s ===", release, iso3)

    for adm_n in GEOBOUNDARIES_ADM_LEVELS:
        heartbeat.write_current(
            name=iso3, iso_code=iso3, adm_level=adm_n, phase="download",
            sub_phase=f"resolving boundary ADM{adm_n} download URL",
            queue_preview=queue_preview,
        )
        if GEOBOUNDARIES_PIN_REF:
            # THE EQUIVALENCE PIN: fetch the file AT THE PINNED REF via the
            # LFS media host. A HEAD probe stands in for the API's level
            # discovery — 404 means the pinned tree has no such level.
            gj_url = GEOBOUNDARIES_PINNED_FILE.format(
                ref=GEOBOUNDARIES_PIN_REF, release=release, iso3=iso3, n=adm_n,
            )
            if not url_exists(gj_url):
                logger.info("  %s ADM%d: not in the pinned tree — skipping",
                            iso3, adm_n)
                continue
        else:
            api_url = GEOBOUNDARIES_API.format(release=release, iso3=iso3, n=adm_n)
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

            # The per-boundary API returns a single object; the ALL endpoint an
            # array. Here we always query a single (iso, adm) pair, so a dict is
            # expected — but guard defensively.
            if isinstance(meta, list):
                meta = meta[0] if meta else None
            gj_url = (meta or {}).get("gjDownloadURL")
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
            bar_key=f"gb:{iso3}:adm{adm_n}",
        ):
            present += 1

    if present == 0:
        logger.warning("  %s: no geoBoundaries GeoJSON obtained — the seeder "
                       "will have nothing to import for this ISO.", iso3)
    else:
        logger.info("  %s: %d boundary file(s) ready", iso3, present)
    return present


# ─── WorldPop ─────────────────────────────────────────────────────────────────

def resolve_worldpop_asset(
    iso3: str,
    wp_year: int,
    wp_resolution: str,
) -> tuple[int, str, str] | None:
    """
    Page through the WorldPop STAC collection for this ISO3 and return the
    (year, download_href, item_id) for the newest CONSTRAINED (CN) raster at
    the requested resolution with year <= wp_year.

    If NO CN asset satisfies year <= wp_year (the STAC's year floor is now 2015
    and the old 2023 vintage the canonical filename encodes may be gone), fall
    back to the newest available CN asset at that resolution overall so a raster
    is still fetched. This is logged.

    Mirrors the selection logic of the retired docs/fetch_worldpop.sh (project ==
    Population, matching resolution, id matches _CN_<res>_, highest qualifying
    year, id tiebreak). The STAC does NOT carry UC / UNadj — those are handled
    by resolve_worldpop_direct().

    Returns None if the collection doesn't exist or has no qualifying asset.
    """
    iso3 = iso3.upper()
    res_token = f"_CN_{wp_resolution}_"   # e.g. "_CN_100m_" / "_CN_1km_"
    url: str | None = WORLDPOP_STAC_ITEMS.format(iso3=iso3)

    # Track the best qualifying (<= wp_year) asset AND, separately, the newest
    # asset overall so we can fall back if nothing qualifies.
    best_le: tuple[int, str, str] | None = None   # newest with year <= wp_year
    best_any: tuple[int, str, str] | None = None  # newest of any year
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

        for feat in (doc.get("features", []) or []):
            props = feat.get("properties", {}) or {}
            fid = feat.get("id") or ""
            if (props.get("project") != "Population"
                    or props.get("resolution") != wp_resolution
                    or res_token not in fid):
                continue
            try:
                year = int(props.get("year"))
            except (TypeError, ValueError):
                continue
            href = ((feat.get("assets", {}) or {}).get("data", {}) or {}).get("href")
            if not href:
                continue
            cand = (year, href, fid)
            if best_any is None or year > best_any[0] or (
                    year == best_any[0] and fid > best_any[2]):
                best_any = cand
            if year <= wp_year and (
                    best_le is None or year > best_le[0]
                    or (year == best_le[0] and fid > best_le[2])):
                best_le = cand

        # Follow STAC pagination (rel == next).
        nxt = None
        for link in (doc.get("links", []) or []):
            if link.get("rel") == "next":
                nxt = link.get("href")
                break
        url = nxt

    if best_le is not None:
        return best_le
    if best_any is not None:
        logger.info("  %s: no CN %s asset with year <= %d — falling back to "
                    "newest available (year %d)",
                    iso3, wp_resolution, wp_year, best_any[0])
        return best_any
    return None


def resolve_worldpop_direct(
    iso3: str,
    wp_year: int,
    wp_resolution: str,
    unconstrained: bool,
    un_adjusted: bool,
) -> tuple[int, str] | None:
    """
    Resolve a WorldPop product the STAC does NOT expose (unconstrained and/or
    UN-adjusted) by CONSTRUCTING the canonical data.worldpop.org URL and probing
    it with a HEAD request. Returns (year, href) for the first candidate URL
    that exists, or None.

    This is best-effort URL construction against WorldPop's documented
    Global_2000_2020 directory layout — not a catalog lookup. WorldPop's
    unconstrained series stops at 2020, so wp_year is clamped to
    WORLDPOP_UC_MAX_YEAR for these products.

      Unconstrained 100m per-country (ppp):
        <base>/Global_2000_2020/<year>/<ISO3>/<iso3>_ppp_<year>.tif
        UN-adjusted:
        <base>/Global_2000_2020/<year>/<ISO3>/<iso3>_ppp_<year>_UNadj.tif
      Unconstrained 1km per-country:
        <base>/Global_2000_2020_1km/<year>/<ISO3>/<iso3>_ppp_<year>_1km_Aggregated.tif
        UN-adjusted 1km:
        <base>/Global_2000_2020_1km_UNadj/<year>/<ISO3>/<iso3>_ppp_<year>_1km_Aggregated_UNadj.tif

    Only reached when --wp-variant unconstrained OR --wp-un-adjusted is set. If
    every candidate 404s, returns None and the caller logs it; the topological
    fallback in import_worldpop then covers the country at population time.
    """
    iso_u = iso3.upper()
    iso_l = iso3.lower()
    year = min(wp_year, WORLDPOP_UC_MAX_YEAR)
    if year != wp_year:
        logger.info("  %s: WorldPop unconstrained/UNadj series tops out at %d — "
                    "clamping requested year %d to %d",
                    iso_u, WORLDPOP_UC_MAX_YEAR, wp_year, year)

    suffix = "_UNadj" if un_adjusted else ""
    if wp_resolution == "1km":
        root = "Global_2000_2020_1km_UNadj" if un_adjusted else "Global_2000_2020_1km"
        fname = f"{iso_l}_ppp_{year}_1km_Aggregated{suffix}.tif"
    else:
        root = "Global_2000_2020"
        fname = f"{iso_l}_ppp_{year}{suffix}.tif"

    candidates = [f"{WORLDPOP_UC_BASE}/{root}/{year}/{iso_u}/{fname}"]

    for url in candidates:
        if url_exists(url):
            logger.info("  %s: resolved unconstrained%s %s product → %s",
                        iso_u, " UN-adjusted" if un_adjusted else "",
                        wp_resolution, fname)
            return (year, url)
        logger.debug("  %s: candidate not found: %s", iso_u, url)

    logger.info("  %s: no unconstrained%s %s product resolved on "
                "data.worldpop.org (topological fallback will apply)",
                iso_u, " UN-adjusted" if un_adjusted else "", wp_resolution)
    return None


def download_worldpop_for_country(worldpop_root: Path, iso3: str,
                                  wp_year: int, wp_variant: str,
                                  wp_resolution: str, wp_un_adjusted: bool,
                                  queue_preview: list[str]) -> bool:
    """
    Resolve + download the country's WorldPop raster into
    worldpop_100m_latest/<iso3_lower>/<filename>, honoring the year / variant /
    resolution / UN-adjusted knobs.

    Selection:
      * constrained (default): STAC lookup via resolve_worldpop_asset().
      * unconstrained and/or UN-adjusted: direct data.worldpop.org URL
        construction via resolve_worldpop_direct().
      * Either way, if the primary iso yields nothing, WORLDPOP_ISO_ALIASES
        legacy codes are tried before giving up (e.g. XKX falls back to KOS;
        a hit is logged).

    We ALWAYS write into worldpop_100m_latest/<iso>/ (the directory the seeder
    scans) and keep the upstream filename verbatim. find_worldpop_tif prefers
    the canonical *_pop_2023_CN_100m_R2025A_v1.tif but falls back to the first
    *.tif in the directory, so a differently-named unconstrained / 1km / non-2023
    raster is still picked up.

    A missing raster is NOT fatal: import_worldpop treats a country with no own
    TIF as "topological fallback only" (population comes from overlapping
    neighbour rasters where they exist). We log and move on. Returns True if a
    raster is present after, False otherwise.
    """
    iso3 = iso3.upper()
    logger.info("=== WorldPop: %s (variant=%s res=%s year<=%d un_adjusted=%s) ===",
                iso3, wp_variant, wp_resolution, wp_year, wp_un_adjusted)
    heartbeat.write_current(
        name=iso3, iso_code=iso3, adm_level=1, phase="download",
        sub_phase="resolving population raster",
        queue_preview=queue_preview,
    )

    href: str | None = None
    year: int | None = None
    want_direct = (wp_variant == "unconstrained") or wp_un_adjusted

    # Primary iso first, then any WorldPop legacy aliases (WORLDPOP_ISO_ALIASES,
    # e.g. XKX → KOS). The alias only changes which upstream STAC collection /
    # constructed direct URL we ask for — the downloaded raster still lands
    # under the PRIMARY iso's directory below, where the seeder looks.
    for candidate in [iso3] + [a.upper() for a in WORLDPOP_ISO_ALIASES.get(iso3, [])]:
        if want_direct:
            resolved = resolve_worldpop_direct(
                candidate, wp_year, wp_resolution,
                unconstrained=(wp_variant == "unconstrained"),
                un_adjusted=wp_un_adjusted,
            )
            if resolved is not None:
                year, href = resolved
        else:
            # THE EQUIVALENCE PIN (2026-08-28): prefer the FROZEN R2025A v1
            # direct URL — the exact bytes of the reference archive's
            # canonical rasters. The STAC is a moving catalog (it may drop
            # the requested vintage or serve a newer revision); the frozen
            # release cannot drift. STAC remains the fallback on a 404.
            frozen = WORLDPOP_R2025A_FILE.format(
                year=wp_year, iso_u=candidate.upper(),
                iso_l=candidate.lower(), res=wp_resolution,
            )
            if url_exists(frozen):
                year, href = wp_year, frozen
                logger.info("  %s: frozen R2025A v1 product resolved directly "
                            "(equivalence pin)", candidate.upper())
            else:
                logger.info("  %s: no frozen R2025A v1 file for year=%d res=%s "
                            "— falling back to the STAC catalog",
                            candidate.upper(), wp_year, wp_resolution)
                asset = resolve_worldpop_asset(candidate, wp_year, wp_resolution)
                if asset is not None:
                    year, href, _item_id = asset
        if href is not None:
            if candidate != iso3:
                logger.info("  %s: found via WorldPop legacy code %s",
                            iso3, candidate)
            break

    if href is None:
        logger.info("  %s: no population raster available for the requested "
                    "product (topological fallback will apply at population time)",
                    iso3)
        return False

    filename = href.rsplit("/", 1)[-1]
    dest = worldpop_root / iso3.lower() / filename
    logger.info("  %s: WorldPop year %s → %s", iso3, year, filename)
    heartbeat.write_current(
        name=iso3, iso_code=iso3, adm_level=1, phase="download",
        sub_phase=f"downloading population raster ({year})",
        queue_preview=queue_preview,
    )
    return download_to_file(
        href, dest,
        label=f"{iso3} — population raster {year}",
        bar_key=f"wp:{iso3}",
    )


# ─── Protomaps planet basemap ─────────────────────────────────────────────────

def resolve_latest_protomaps_build() -> str | None:
    """
    Find the newest available Protomaps daily planet build date by probing
    build.protomaps.com backwards from today. A build isn't published every
    single day, so we look back up to PROTOMAPS_PROBE_DAYS days and return the
    first YYYYMMDD whose .pmtiles HEAD-probes as present. Returns None if none
    is found in the window (network down, or an unusually long publishing gap).
    """
    from datetime import timedelta
    today = datetime.now(timezone.utc).date()
    for back in range(PROTOMAPS_PROBE_DAYS):
        d = today - timedelta(days=back)
        stamp = d.strftime("%Y%m%d")
        url = PROTOMAPS_BUILD_URL.format(date=stamp)
        logger.info("Protomaps: probing build %s", stamp)
        if url_exists(url):
            logger.info("Protomaps: latest available build is %s", stamp)
            return stamp
    logger.error("Protomaps: no build found in the last %d days — the build "
                 "server may be down or between publishing runs.",
                 PROTOMAPS_PROBE_DAYS)
    return None


def download_protomaps(protomaps_root: Path) -> bool:
    """
    Download the latest Protomaps planet basemap into
    <data_root>/protomaps/<YYYYMMDD>.pmtiles.

    SIZE WARNING: the planet build is ~100 GB. This is a serious disk + network
    commitment. Resumable at file granularity (skip if the dated file is already
    fully present); a partial .part is re-fetched from scratch.

    The etl container has NO protomaps app mount (only the writable etl_geodata
    volume at /data), so we can only land the file in /data/protomaps/ and tell
    the operator, LOUDLY, the path they must point PROTOMAPS_DIR at afterwards.

    Returns True if a .pmtiles is present after, False otherwise.
    """
    logger.warning("")
    logger.warning("╔══════════════════════════════════════════════════════════╗")
    logger.warning("║  PROTOMAPS PLANET BASEMAP DOWNLOAD — ~100 GB, HOURS       ║")
    logger.warning("║  This is the FULL planet vector basemap. Ensure the      ║")
    logger.warning("║  etl_geodata volume has ~100 GB free before proceeding.  ║")
    logger.warning("╚══════════════════════════════════════════════════════════╝")

    stamp = resolve_latest_protomaps_build()
    if stamp is None:
        return False

    url = PROTOMAPS_BUILD_URL.format(date=stamp)
    dest = protomaps_root / f"{stamp}.pmtiles"
    heartbeat.write_current(
        name="Protomaps planet basemap", phase="download",
        sub_phase=f"downloading planet basemap {stamp} (~100 GB)",
    )
    ok = download_to_file(
        url, dest,
        label=f"Protomaps — planet basemap {stamp}",
        bar_key="download:protomaps",
    )

    if ok:
        # The etl container can't write into the app's protomaps mount. Tell the
        # operator exactly where the file is (inside the etl_geodata volume) and
        # what PROTOMAPS_DIR must point at. The container path is /data/protomaps;
        # the host path is wherever docker put the etl_geodata volume (or, if the
        # operator later rebinds it, wherever they mount it).
        logger.warning("")
        logger.warning("Protomaps basemap saved to (container path): %s", dest)
        logger.warning("The etl service writes into the `etl_geodata` docker "
                       "volume (mounted at /data). The app serves protomaps from "
                       "${PROTOMAPS_DIR}, which is NOT this volume by default.")
        logger.warning("ACTION REQUIRED: point PROTOMAPS_DIR in your .env at the "
                       "directory that contains %s.pmtiles, then RECREATE the "
                       "containers (docker compose up -d). If you can't rebind "
                       "the volume, copy the file from the etl_geodata volume's "
                       "protomaps/ subdir into your PROTOMAPS_DIR host folder.",
                       stamp)
    return ok


# ─── Orchestration ────────────────────────────────────────────────────────────

def download_datasets(
    countries: list[str],
    datasets: list[str],
    data_root: Path,
    gb_release: str = "gbOpen",
    wp_year: int = WORLDPOP_DEFAULT_YEAR,
    wp_variant: str = "constrained",
    wp_resolution: str = "100m",
    wp_un_adjusted: bool = False,
    log: logging.Logger | None = None,
) -> int:
    """
    Download the requested OPEN datasets for the given countries into `data_root`
    in the exact layout seed_database.py reads.

    Args:
        countries:  ISO3 codes to fetch. EMPTY = ALL countries (full-world) —
                    enumerated from the geoBoundaries release for boundaries and
                    from each country's STAC collection for WorldPop. A
                    full-world download is 14 GB+ and hours long; we WARN loudly
                    but proceed.
        datasets:   which of {"geoboundaries", "worldpop", "protomaps"} to fetch.
        data_root:  target root (e.g. /data). Subtrees geoBoundaries_repo/,
                    worldpop_100m_latest/ and protomaps/ are created under it.
        gb_release: gbOpen | gbHumanitarian | gbAuthoritative (upstream URL only;
                    on-disk path stays under gbOpen/ for the importer).
        wp_year:    newest WorldPop year to accept (<= this year; falls back to
                    newest available if nothing qualifies).
        wp_variant: constrained (STAC) | unconstrained (direct data.worldpop.org).
        wp_resolution: 100m | 1km.
        wp_un_adjusted: prefer the UN-adjusted product (direct URL).

    Returns 0 on success, non-zero on a fatal condition (e.g. full-world
    boundary enumeration failed, or every requested country yielded no boundary
    data).
    """
    global logger
    if log is not None:
        logger = log

    countries = [c.strip().upper() for c in (countries or []) if c and c.strip()]
    datasets = [d.strip().lower() for d in (datasets or []) if d and d.strip()]
    if not datasets:
        # Nothing to do is treated as an explicit no-op rather than an error —
        # the supervisor only calls us when at least one dataset was requested,
        # but be defensive.
        logger.warning("download_datasets called with no datasets — nothing to do.")
        return 0

    want_gb = "geoboundaries" in datasets
    want_wp = "worldpop" in datasets
    want_pm = "protomaps" in datasets

    # Normalize release + variant knobs defensively (the supervisor validates
    # upstream, but this file also runs standalone from the CLI).
    if gb_release not in GEOBOUNDARIES_META_CSV:
        logger.warning("Unknown gb_release %r — defaulting to gbOpen", gb_release)
        gb_release = "gbOpen"
    if wp_variant not in ("constrained", "unconstrained"):
        wp_variant = "constrained"
    if wp_resolution not in ("100m", "1km"):
        wp_resolution = "100m"

    gb_release_root = data_root / "geoBoundaries_repo" / "releaseData"
    gbopen_root     = gb_release_root / "gbOpen"   # importer reads gbOpen/ always
    worldpop_root   = data_root / "worldpop_100m_latest"
    protomaps_root  = data_root / "protomaps"

    full_world = (want_gb or want_wp) and not countries

    logger.info("╔══════════════════════════════════════════════╗")
    logger.info("║   Download from official sources              ║")
    logger.info("╚══════════════════════════════════════════════╝")
    logger.info("Started at %s", datetime.now(timezone.utc).isoformat())
    logger.info("Countries: %s", ", ".join(countries) if countries else "(ALL — full world)")
    logger.info("Datasets:  %s", ", ".join(datasets))
    logger.info("Data root: %s", data_root)
    if want_gb:
        logger.info("geoBoundaries release: %s (written under gbOpen/ for the seeder)",
                    gb_release)
    if want_wp:
        logger.info("WorldPop: year<=%d, variant=%s, resolution=%s, un_adjusted=%s",
                    wp_year, wp_variant, wp_resolution, wp_un_adjusted)
    logger.warning(
        "NETWORK: this fetches live data from github.com + data.worldpop.org "
        "(+ build.protomaps.com for the basemap). WorldPop rasters can be "
        "hundreds of MB to >1 GB per country."
    )
    if full_world:
        logger.warning("")
        logger.warning("╔══════════════════════════════════════════════════════════╗")
        logger.warning("║  FULL-WORLD DOWNLOAD REQUESTED (empty country list)      ║")
        logger.warning("║  This fetches EVERY country from the official hosts:     ║")
        logger.warning("║    • ~14 GB+ of geoBoundaries + WorldPop rasters         ║")
        logger.warning("║    • HOURS of transfer time on a typical connection      ║")
        logger.warning("║  The /archive path is the usual home for a full world.   ║")
        logger.warning("║  Proceeding anyway per the empty --countries request.    ║")
        logger.warning("╚══════════════════════════════════════════════════════════╝")

    heartbeat.set_phase("download")

    # Protomaps used to run FIRST — a ~100 GB display-only basemap starving
    # the math data (boundaries + population) for hours. It now runs LAST
    # (operator, 2026-08-29): the ingestible datasets land first, and the
    # basemap haul follows once everything the seeder needs is on disk.

    # ── Resolve the working country list ──
    gb_iso_list: list[str] = countries
    wp_iso_list: list[str] = countries

    if want_gb:
        # geoBoundaries meta CSV once up front (shared across all countries).
        download_geoboundaries_meta(gb_release_root, gb_release)
        if full_world:
            gb_iso_list = enumerate_all_geoboundaries_isos(gb_release)
            if not gb_iso_list:
                logger.error(
                    "Full-world geoBoundaries enumeration returned no ISO codes "
                    "— cannot proceed with a boundary download. Check the network "
                    "and the ALL/ADM0 endpoint for release %s.", gb_release,
                )
                return 1
        # For WorldPop full-world we reuse the geoBoundaries iso list as the
        # country set (every country with boundaries is a country whose
        # population we want); the STAC lookup 404s harmlessly for any iso
        # without a collection.
        if full_world and want_wp:
            wp_iso_list = gb_iso_list
    elif full_world and want_wp:
        # WorldPop-only full world with no boundaries: enumerate via the
        # geoBoundaries ALL endpoint anyway — it's the cleanest global ISO3
        # source we have, and any iso lacking a STAC collection simply skips.
        wp_iso_list = enumerate_all_geoboundaries_isos(gb_release)
        if not wp_iso_list:
            logger.error("Full-world WorldPop run could not enumerate ISO codes.")
            return 1

    gb_countries_with_data = 0

    # ── THE MULTI-LANE DOWNLOAD ENGINE (the lane law, 2026-08-29) ──────────
    # One pile per KIND (boundaries / rasters), each ordered by est_cost and
    # drained from BOTH ENDS at once: the submission order interleaves
    # largest, smallest, 2nd-largest, 2nd-smallest… so the monsters start
    # immediately while the smalls never stop. The two sibling kinds then
    # interleave 1:1 into ONE shared pool, so when either pile drains its
    # lanes flow to the survivor automatically. Lane width DERIVES from the
    # host — min(8, 2×cores), env DL_LANES overrides — capped for politeness
    # to the two upstream hosts. Every file is atomic + skip-existing, so a
    # killed run costs at most one in-flight file per lane.
    # Courtesy cap NAMED per source (audit row, 2026-08-30): the bound is
    # politeness to geoBoundaries + WorldPop, not hardware — it scales
    # gently with cores instead of freezing at 8, and stays modest so the
    # two upstream hosts never see a hammering.
    courtesy_lanes_per_source = 6
    lanes = int(os.environ.get("DL_LANES", "0") or 0) \
        or max(2, min(2 * courtesy_lanes_per_source, 2 * (os.cpu_count() or 2)))

    # est_cost, boundaries: published-boundary row count per ISO from the
    # (pinned) meta CSV already on disk — free, and proportional to the
    # number of files a country will fetch.
    gb_est: dict[str, int] = {}
    if want_gb:
        try:
            meta_path = gb_release_root / "geoBoundariesOpen-meta.csv"
            with open(meta_path, encoding="utf-8", errors="replace") as fh:
                for row in csv.DictReader(fh):
                    iso = str(row.get("boundaryISO", "")).strip().upper()
                    if iso:
                        gb_est[iso] = gb_est.get(iso, 0) + 1
        except OSError as exc:
            logger.warning("boundary sizing pre-pass skipped (%s) — pile order "
                           "falls back to alphabetical", exc)

    # est_cost, rasters: Content-Length of the frozen R2025A URL, gathered by
    # a pooled HEAD pre-pass (seconds). Only meaningful for the default
    # constrained product; other variants order alphabetically.
    wp_est: dict[str, int] = {}
    if want_wp and wp_variant == "constrained" and not wp_un_adjusted:
        logger.info("Sizing pre-pass: HEAD-probing %d frozen raster URLs on "
                    "%d lanes…", len(wp_iso_list), lanes)

        def _head_size(iso: str) -> tuple[str, int]:
            url = WORLDPOP_R2025A_FILE.format(
                year=wp_year, iso_u=iso.upper(), iso_l=iso.lower(),
                res=wp_resolution,
            )
            try:
                with _open(url, timeout=30, method="HEAD") as resp:
                    return iso, int(resp.headers.get("Content-Length") or 0)
            except Exception:  # noqa: BLE001
                return iso, 0

        with ThreadPoolExecutor(max_workers=lanes) as sizing_pool:
            for iso, size in sizing_pool.map(_head_size, wp_iso_list):
                wp_est[iso] = size

    def _two_ended(isos: list[str], est: dict[str, int]) -> list[str]:
        ordered = sorted(isos, key=lambda i: (-est.get(i, 0), i))
        out, lo, hi, big = [], 0, len(ordered) - 1, True
        while lo <= hi:
            out.append(ordered[lo] if big else ordered[hi])
            if big:
                lo += 1
            else:
                hi -= 1
            big = not big
        return out

    gb_order = _two_ended(list(gb_iso_list), gb_est) if want_gb else []
    wp_order = _two_ended(list(wp_iso_list), wp_est) if want_wp else []

    counters_lock = threading.Lock()
    done_counts = {"gb": 0, "wp": 0}
    if gb_order:
        heartbeat.bar_start("gb:ALL", "Boundaries — countries complete",
                            total=len(gb_order), unit="countries")
    if wp_order:
        heartbeat.bar_start("wp:ALL", "Population — countries complete",
                            total=len(wp_order), unit="countries")

    def _run_gb(iso3: str) -> None:
        nonlocal gb_countries_with_data
        n_files = download_geoboundaries_for_country(
            gbopen_root, iso3, gb_release, [],
        )
        with counters_lock:
            if n_files > 0:
                gb_countries_with_data += 1
            done_counts["gb"] += 1
            heartbeat.bar_update("gb:ALL", done_counts["gb"],
                                 force=True, total=len(gb_order))

    def _run_wp(iso3: str) -> None:
        download_worldpop_for_country(
            worldpop_root, iso3, wp_year, wp_variant,
            wp_resolution, wp_un_adjusted, [],
        )
        with counters_lock:
            done_counts["wp"] += 1
            heartbeat.bar_update("wp:ALL", done_counts["wp"],
                                 force=True, total=len(wp_order))

    tasks: list[tuple] = []
    for k in range(max(len(gb_order), len(wp_order))):
        if k < len(gb_order):
            tasks.append((_run_gb, gb_order[k]))
        if k < len(wp_order):
            tasks.append((_run_wp, wp_order[k]))

    if tasks:
        logger.info("")
        logger.info("Multi-lane download: %d lanes over %d boundary + %d "
                    "raster tasks (two-ended piles, kinds interleaved 1:1)",
                    lanes, len(gb_order), len(wp_order))
        with ThreadPoolExecutor(max_workers=lanes,
                                thread_name_prefix="dl") as pool:
            futures = [pool.submit(fn, iso) for fn, iso in tasks]
            for fut in as_completed(futures):
                # Per-country failures are logged inside the task; an
                # unexpected exception must surface, not vanish in a lane.
                exc = fut.exception()
                if exc is not None:
                    logger.error("lane task raised: %s", exc)
    if gb_order:
        heartbeat.bar_complete("gb:ALL", current=done_counts["gb"],
                               total=len(gb_order))
    if wp_order:
        heartbeat.bar_complete("wp:ALL", current=done_counts["wp"],
                               total=len(wp_order))

    # ── Protomaps: A LANE UNTO ITSELF (operator ruling 2026-08-29) ──────────
    # The planet basemap is an OPTIONAL, display-only dependency. It must
    # never gate ingestion or the rest of bootstrapping: instead of running
    # inline (which held the seed phase behind a ~100 GB haul), it spawns as
    # a DETACHED protomaps-only invocation of this same script and this
    # step returns immediately. The child survives this process, resumes
    # any .part via the Range support above, and keeps writing its own
    # progress bar. DL_PROTOMAPS_INLINE=1 restores the old blocking
    # behavior (used by the detached child itself).
    if want_pm:
        if os.environ.get("DL_PROTOMAPS_INLINE", "").strip() in ("1", "true"):
            download_protomaps(protomaps_root)
        else:
            child_env = dict(os.environ, DL_PROTOMAPS_INLINE="1")
            log_path = data_root / "protomaps" / "download.log"
            log_path.parent.mkdir(parents=True, exist_ok=True)
            with open(log_path, "ab") as lf:
                import subprocess
                subprocess.Popen(
                    [sys.executable, "-u", os.path.abspath(__file__),
                     "--countries",
                     "--datasets", "protomaps",
                     "--data-root", str(data_root)],
                    stdout=lf, stderr=subprocess.STDOUT,
                    env=child_env, start_new_session=True,
                )
            logger.info("")
            logger.info("Protomaps basemap: detached as its own lane (resumes "
                        "any partial; log: %s). Ingestion proceeds WITHOUT "
                        "waiting for it.", log_path)

    heartbeat.clear_current()
    logger.info("")
    logger.info("Download stage complete.")

    # Fatal only if we were asked for boundaries and got NONE for any country —
    # that means the seeder would have nothing to import. WorldPop-only /
    # protomaps-only failures are non-fatal (topological fallback covers
    # population gaps; a failed protomaps download is logged but the seed can
    # still run).
    if want_gb and gb_countries_with_data == 0:
        logger.error(
            "No geoBoundaries data obtained for ANY requested country — the "
            "seeder would import nothing. Check the ISO3 codes and network."
        )
        return 1
    return 0


def _str2bool(v: str) -> bool:
    return str(v).strip().lower() in ("1", "true", "yes", "y", "on")


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Download official OPEN geodata (geoBoundaries + WorldPop + "
                    "optional Protomaps basemap) into the ETL data volume.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__,
    )
    parser.add_argument(
        # nargs='*' + no `required` so an EMPTY list is a valid full-world
        # request. `--countries` with nothing after it → [] → all countries.
        "--countries", nargs="*", metavar="ISO3", default=[],
        help="ISO3 country codes to download. EMPTY = ALL countries "
             "(full-world; 14 GB+, hours — warned about but allowed).",
    )
    parser.add_argument(
        "--datasets", nargs="+", metavar="NAME",
        choices=["geoboundaries", "worldpop", "protomaps"],
        default=["geoboundaries", "worldpop"],
        help="Which datasets to fetch (default: geoboundaries + worldpop). "
             "'protomaps' pulls the ~100 GB planet basemap.",
    )
    parser.add_argument(
        "--gb-release", choices=["gbOpen", "gbHumanitarian", "gbAuthoritative"],
        default="gbOpen",
        help="geoBoundaries release to fetch from (default gbOpen, CC-BY 4.0). "
             "Files still land under gbOpen/ on disk for the seeder.",
    )
    parser.add_argument(
        "--wp-year", type=int, default=WORLDPOP_DEFAULT_YEAR,
        help="Newest WorldPop year to accept (<= this year). Falls back to the "
             "newest available if nothing qualifies. Default %(default)s.",
    )
    parser.add_argument(
        "--wp-variant", choices=["constrained", "unconstrained"],
        default="constrained",
        help="constrained (STAC, default) or unconstrained (direct "
             "data.worldpop.org URL construction).",
    )
    parser.add_argument(
        "--wp-resolution", choices=["100m", "1km"], default="100m",
        help="WorldPop raster resolution (default 100m).",
    )
    parser.add_argument(
        "--wp-un-adjusted", nargs="?", const=True, default=False,
        type=_str2bool,
        help="Prefer the UN-adjusted WorldPop product (direct URL). Bare flag "
             "or a truthy value both enable it.",
    )
    parser.add_argument(
        "--data-root", default=os.environ.get("DATA_ROOT", "/data"),
        help="Target root directory (default: env DATA_ROOT or /data). The "
             "geoBoundaries_repo/, worldpop_100m_latest/ and protomaps/ subtrees "
             "are created under it, and this is the path the seeder should read "
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
        gb_release=args.gb_release,
        wp_year=args.wp_year,
        wp_variant=args.wp_variant,
        wp_resolution=args.wp_resolution,
        wp_un_adjusted=bool(args.wp_un_adjusted),
        log=logging.getLogger("download_datasets"),
    )


if __name__ == "__main__":
    sys.exit(main())
