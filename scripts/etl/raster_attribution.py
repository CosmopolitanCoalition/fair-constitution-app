"""
raster_attribution.py — Phase T.7: in-memory per-pixel attribution.

Replaces the PostGIS-based injection + correction pipeline with a NumPy
raster-direct pass. Same per-ISO invariant guarantees as the SQL approach,
~10× faster on most ISOs.

Architecture: lazy-geometry windowed pass.

  - Up front, the caller passes lightweight polygon metadata: just
    (id, centroid_x, centroid_y, minx, miny, maxx, maxy) for every
    L-level polygon. For IND L=6's 649 k polygons that's ~30 MB total,
    not the multi-GB of WKB blobs.
  - A KDTree is built once from the centroids — used by every window
    for cross-window gap-pixel nearest-polygon lookup.
  - For each raster window, the polygons whose bboxes overlap the
    window are identified via a simple NumPy bbox-intersection test.
    Their geometries are **fetched from the DB lazily** through a
    caller-provided callback (`get_geoms(ids) -> {id: wkb}`).
    rasterio.features.rasterize then runs only on this per-window
    subset.
  - Per-window memory is bounded by `len(polygons_in_window) × avg_wkb_size`,
    typically a few hundred MB even for IND L=6's biggest window.

This keeps the algorithm system-independent — the same Python runs on
a Pi 4 (2 GB) and a workstation (32 GB). Window size adapts via
`window_px` for tighter memory budgets.

Per (iso, level) algorithm:

  1. Caller passes polygon metadata + a fetch callback.
  2. Build KDTree from polygon centroids (global, lightweight).
  3. For each relevant raster:
     a. Open with rasterio; clip to claim bbox.
     b. Iterate the claim bbox in `window_px`-edge sub-windows.
     c. Per window:
          - Read pop values.
          - Numpy bbox-intersection: find which polygon-indices'
            bboxes overlap the window.
          - Call `get_geoms(those_indices)` to fetch geoms from DB.
          - Rasterize claim + count + label using only those geoms.
          - count == 1: full pop to label[pixel].
          - count >= 2: per-polygon expansion (rare).
          - count == 0: KDTree.query → nearest centroid globally.
          - np.bincount accumulate into `totals`.
  4. Return {id: population_int}.

Cross-ISO ownership: pixels are constrained to (L=1 ∪ all L-polygons)
via the claim_mask rasterise. Dual-footprint preserved naturally
(USA's "Puerto Rico county" reads PRI pixels through its own L=2
polygon's geometry; PRI's standalone tree separately reads its own
pixels).

Pure function — no DB connection inside; caller owns DB access via
the geom-fetch callback. Caller persists results.
"""

from __future__ import annotations

import ctypes
import ctypes.util
import gc
import logging
import os
from pathlib import Path
from typing import Callable, Iterable

# glibc trim — return free pages to the OS, not just to Python's heap.
# Without this, RSS creeps up across many windows even though Python
# has released the references: glibc's malloc keeps the freed pages
# in its arenas for reuse rather than returning them. malloc_trim(0)
# walks the arenas and returns whatever is contiguously free. On
# CAN-class isos this is the difference between OOM at window ~1500
# and clean completion. Wrapped in try/except for non-Linux hosts.
try:
    _libc = ctypes.CDLL(ctypes.util.find_library("c") or "libc.so.6")
    _libc.malloc_trim.argtypes = [ctypes.c_size_t]
    _libc.malloc_trim.restype = ctypes.c_int
    def _malloc_trim() -> None:
        try:
            _libc.malloc_trim(0)
        except Exception:
            pass
except Exception:
    def _malloc_trim() -> None:  # type: ignore[misc]
        pass

# Cap GDAL's internal block cache BEFORE rasterio loads. GDAL caches
# read blocks from raster files in a process-wide pool whose default is
# 5 % of host RAM — on a workstation host that translates to GB-scale
# inside the container, and the cache grew unbounded across 2300 CAN L=2
# windows until OOM-kill. 64 MB is plenty for our sequential per-window
# read pattern (we read each region once and move on).
#
# Must be set before `import rasterio` (env var read by GDAL on init).
os.environ.setdefault("GDAL_CACHEMAX", "64")  # MB

import numpy as np
import rasterio
import shapely.wkb
from rasterio import features, transform as rio_transform, windows
from scipy.spatial import cKDTree
from shapely.geometry import box as shapely_box
from shapely.prepared import prep as shapely_prep

try:
    from memory_budget import chunk_profile, detect_memory_budget_bytes
    _BUDGET, _PROFILE = chunk_profile(detect_memory_budget_bytes())
    DEFAULT_WINDOW_PX = _PROFILE.get("RASTER_WINDOW_PX", 4096)
except Exception:
    # Fallback when memory_budget.py is unavailable (e.g. running
    # outside the ETL container). Conservative default chosen so a
    # bare invocation runs without OOM on a 2 GB host.
    DEFAULT_WINDOW_PX = 1024


def attribute(
    iso: str,
    adm_level: int,
    l1_geom_wkb: bytes,
    polygon_meta: list[tuple[str, float, float, float, float, float, float]],
    get_geoms: Callable[[list[int]], dict[int, bytes]],
    raster_paths: list[Path],
    log: logging.Logger | None = None,
    window_px: int = DEFAULT_WINDOW_PX,
) -> dict[str, int]:
    """
    Compute per-polygon population for one (iso, adm_level) pair using
    lazy per-window geometry fetching.

    Args:
        iso, adm_level: informational, for logging.
        l1_geom_wkb:    WKB bytes for the iso's L=1 polygon.
        polygon_meta:   list of tuples
                        `(jurisdiction_id, centroid_x, centroid_y, minx, miny, maxx, maxy)`
                        for every L-level polygon. The polygon's array
                        index in this list IS its label value (0-based;
                        rasterized label is `idx + 1`).
        get_geoms:      callable(list_of_indices) -> {index: wkb_bytes}.
                        Fetches WKB for the requested polygons; called
                        once per raster window with the polygon indices
                        whose bbox overlaps the window.
        raster_paths:   list of WorldPop .tif paths to attribute from.
        log:            optional logger.
        window_px:      pixel edge for sub-window iteration.

    Returns:
        dict {jurisdiction_id_str: population_int}. Polygons with zero
        attribution are omitted.
    """
    log = log or logging.getLogger("raster_attribution")
    if not polygon_meta or not raster_paths:
        return {}

    l1_geom = shapely.wkb.loads(l1_geom_wkb)
    # Prepared geometry: pre-builds a spatial index over L=1's
    # vertices so repeated intersects() calls (one per window) run
    # in O(log V) instead of O(V) — critical for big continental
    # L=1 polygons with millions of vertices.
    l1_prepared = shapely_prep(l1_geom)
    n = len(polygon_meta)

    # Pre-extract metadata into NumPy arrays for fast vectorised
    # bbox-intersection per window.
    ids = [m[0] for m in polygon_meta]
    centroids = np.array([(m[1], m[2]) for m in polygon_meta], dtype=np.float64)
    bboxes = np.array(
        [(m[3], m[4], m[5], m[6]) for m in polygon_meta],
        dtype=np.float64,
    )  # shape (n, 4): minx, miny, maxx, maxy

    centroid_tree = cKDTree(centroids)

    # Claim region bbox = union of L=1 bbox + every L-polygon bbox.
    l1_minx, l1_miny, l1_maxx, l1_maxy = l1_geom.bounds
    claim_minx = min(l1_minx, float(bboxes[:, 0].min()))
    claim_miny = min(l1_miny, float(bboxes[:, 1].min()))
    claim_maxx = max(l1_maxx, float(bboxes[:, 2].max()))
    claim_maxy = max(l1_maxy, float(bboxes[:, 3].max()))

    totals = np.zeros(n, dtype=np.float64)

    for tif_path in raster_paths:
        try:
            _process_raster(
                tif_path,
                claim_minx, claim_miny, claim_maxx, claim_maxy,
                l1_geom, l1_prepared, bboxes, get_geoms, centroid_tree,
                totals, window_px, log,
            )
        except Exception as exc:
            log.warning("  raster_attribution[%s L%d]: %s raised %s",
                        iso, adm_level, tif_path, exc)

    return {
        ids[i]: int(round(float(totals[i])))
        for i in range(n)
        if totals[i] > 0
    }


def _process_raster(
    tif_path: Path,
    minx: float, miny: float, maxx: float, maxy: float,
    l1_geom,
    l1_prepared,
    bboxes: np.ndarray,
    get_geoms: Callable[[list[int]], dict[int, bytes]],
    centroid_tree: cKDTree,
    totals: np.ndarray,
    window_px: int,
    log: logging.Logger,
) -> None:
    """Iterate one raster TIF in windows; per window, classify and
    accumulate per-polygon totals."""
    if not tif_path.exists():
        log.debug("  %s does not exist — skipping", tif_path)
        return

    with rasterio.open(tif_path) as src:
        try:
            full_window = windows.from_bounds(
                minx, miny, maxx, maxy, transform=src.transform,
            )
        except Exception as exc:
            log.debug("  %s window-from-bounds failed: %s", tif_path, exc)
            return

        full_window = full_window.intersection(
            windows.Window(0, 0, src.width, src.height)
        )
        if full_window.width <= 0 or full_window.height <= 0:
            return

        windows_processed = 0
        windows_skipped = 0
        for win in _tile_window(full_window, window_px):
            # Lever 1 — skip windows that the L=1 polygon doesn't
            # touch. For continental-bbox isos like CAN (89° × 43°
            # bbox, ~2300 windows at 1024 px) this drops the work
            # by 30-50 % — Arctic Ocean, Pacific Ocean, and Atlantic
            # Ocean windows are inside CAN's bbox but outside CAN's
            # L=1 polygon and contribute zero. Using the prepared
            # L=1 geometry's intersects() makes this ~10x faster than
            # raw shapely intersects on a complex polygon.
            win_transform = src.window_transform(win)
            win_minx_cs = win_transform.c
            win_maxy_cs = win_transform.f
            win_maxx_cs = win_minx_cs + win_transform.a * int(win.width)
            win_miny_cs = win_maxy_cs + win_transform.e * int(win.height)
            win_box = shapely_box(win_minx_cs, win_miny_cs,
                                  win_maxx_cs, win_maxy_cs)
            if not l1_prepared.intersects(win_box):
                windows_skipped += 1
                continue

            _process_window(
                src, win, l1_geom, bboxes, get_geoms,
                centroid_tree, totals, log,
            )
            windows_processed += 1
            # GC every window + glibc trim. gc.collect frees Python
            # references; malloc_trim forces glibc to actually
            # return free pages to the OS. The combination keeps RSS
            # bounded across thousands of windows.
            gc.collect()
            _malloc_trim()

        log.debug("  %s: processed %d windows, skipped %d (no L=1 overlap)",
                  tif_path.name, windows_processed, windows_skipped)


def _tile_window(parent: windows.Window, size: int) -> Iterable[windows.Window]:
    """Yield non-overlapping sub-windows covering `parent`."""
    r0 = int(parent.row_off)
    c0 = int(parent.col_off)
    h = int(parent.height)
    w = int(parent.width)
    for r in range(r0, r0 + h, size):
        for c in range(c0, c0 + w, size):
            yield windows.Window(
                col_off=c, row_off=r,
                width=min(size, c0 + w - c),
                height=min(size, r0 + h - r),
            )


def _process_window(
    src,
    win: windows.Window,
    l1_geom,
    bboxes: np.ndarray,
    get_geoms: Callable[[list[int]], dict[int, bytes]],
    centroid_tree: cKDTree,
    totals: np.ndarray,
    log: logging.Logger,
) -> None:
    """Attribute pixels in `win` to per-polygon totals.

    Lazy geometry loading: fetch only the polygons whose bbox overlaps
    `win` from the DB, rasterize them, attribute. Memory bounded by
    the per-window polygon count + the window's pixel buffers.
    """
    try:
        pop = src.read(1, window=win).astype(np.float32, copy=False)
    except Exception as exc:
        log.debug("  read window=%s failed: %s", win, exc)
        return

    if src.nodata is not None:
        pop[pop == src.nodata] = 0
    np.maximum(pop, 0, out=pop)

    if not (pop > 0).any():
        return

    transform = src.window_transform(win)
    out_shape = pop.shape

    # Compute window bbox in CRS coords. Used to filter polygons by
    # bbox-intersection vectorised over the metadata array.
    win_minx = transform.c
    win_maxy = transform.f
    win_maxx = win_minx + transform.a * int(win.width)
    win_miny = win_maxy + transform.e * int(win.height)

    # Vectorised bbox-intersection: polygons whose bbox overlaps window.
    # Bbox A overlaps bbox B iff
    #   A.maxx >= B.minx AND A.minx <= B.maxx AND
    #   A.maxy >= B.miny AND A.miny <= B.maxy
    intersects = (
        (bboxes[:, 2] >= win_minx) &
        (bboxes[:, 0] <= win_maxx) &
        (bboxes[:, 3] >= win_miny) &
        (bboxes[:, 1] <= win_maxy)
    )
    relevant_idxs = np.where(intersects)[0]

    if len(relevant_idxs) == 0:
        # No L-polygons overlap this window. Pixels in L=1 with no
        # containing polygon are still gaps — KDTree resolves them
        # to the globally-nearest polygon.
        _attribute_gaps_only(
            pop, l1_geom, transform, out_shape, centroid_tree,
            totals, log,
        )
        return

    # Lazy fetch: load WKB only for the polygons we actually need.
    geom_wkbs = get_geoms(relevant_idxs.tolist())

    # Parse WKBs to shapely geoms. Build the rasterize shape lists with
    # ORIGINAL 1-based polygon indices as labels — keeps `totals` in
    # sync across windows that see different polygon subsets.
    relevant_polys: list = []
    relevant_idx_list: list[int] = []
    for idx in relevant_idxs:
        wkb = geom_wkbs.get(int(idx))
        if wkb is None:
            continue
        try:
            g = shapely.wkb.loads(wkb)
            if not g.is_empty:
                relevant_polys.append(g)
                relevant_idx_list.append(int(idx))
        except Exception as exc:
            log.debug("  bad WKB for idx %d: %s", idx, exc)

    if not relevant_polys:
        _attribute_gaps_only(
            pop, l1_geom, transform, out_shape, centroid_tree,
            totals, log,
        )
        return

    claim_shapes = [(l1_geom, 1)] + [(g, 1) for g in relevant_polys]
    count_shapes = [(g, 1) for g in relevant_polys]
    label_shapes = [(g, relevant_idx_list[k] + 1)
                    for k, g in enumerate(relevant_polys)]

    try:
        claim_mask = features.rasterize(
            claim_shapes, out_shape=out_shape, transform=transform,
            merge_alg=features.MergeAlg.replace, dtype=np.uint8,
            fill=0, all_touched=False,
        )
        count = features.rasterize(
            count_shapes, out_shape=out_shape, transform=transform,
            merge_alg=features.MergeAlg.add, dtype=np.int16,
            fill=0, all_touched=False,
        )
        label = features.rasterize(
            label_shapes, out_shape=out_shape, transform=transform,
            merge_alg=features.MergeAlg.replace, dtype=np.int32,
            fill=0, all_touched=False,
        )
    except Exception as exc:
        log.warning("  rasterize failed for window=%s: %s", win, exc)
        return

    in_claim_pop = (claim_mask == 1) & (pop > 0)

    # count == 1 — direct attribution. label holds original 1-based
    # polygon index, so `label - 1` is the correct slot in `totals`.
    ones_mask = in_claim_pop & (count == 1)
    if ones_mask.any():
        np.add.at(totals, label[ones_mask].ravel() - 1,
                  pop[ones_mask].ravel())

    # count >= 2 — overlap pixels. Rare.
    over_mask = in_claim_pop & (count >= 2)
    if over_mask.any():
        _attribute_overlaps_window(
            over_mask, pop, count, label_shapes,
            transform, out_shape, totals, log,
        )

    # count == 0 — gaps inside claim region. KDTree spans all polygons
    # so cross-window gap lookup is exact.
    gap_mask = in_claim_pop & (count == 0)
    if gap_mask.any():
        rows, cols = np.where(gap_mask)
        xs, ys = rio_transform.xy(transform, rows.tolist(), cols.tolist())
        coords = np.column_stack([np.asarray(xs), np.asarray(ys)])
        _, nearest = centroid_tree.query(coords, k=1)
        gap_pop = pop[rows, cols]
        np.add.at(totals, nearest, gap_pop)


def _attribute_gaps_only(
    pop: np.ndarray,
    l1_geom,
    transform,
    out_shape: tuple[int, int],
    centroid_tree: cKDTree,
    totals: np.ndarray,
    log: logging.Logger,
) -> None:
    """Handle windows where no L-polygon's bbox overlaps. Any pop pixel
    inside L=1 is a gap — attribute to nearest centroid globally."""
    try:
        claim_mask = features.rasterize(
            [(l1_geom, 1)], out_shape=out_shape, transform=transform,
            merge_alg=features.MergeAlg.replace, dtype=np.uint8,
            fill=0, all_touched=False,
        )
    except Exception as exc:
        log.warning("  rasterize L=1-only failed: %s", exc)
        return
    gap_only = (claim_mask == 1) & (pop > 0)
    if not gap_only.any():
        return
    rows, cols = np.where(gap_only)
    xs, ys = rio_transform.xy(transform, rows.tolist(), cols.tolist())
    coords = np.column_stack([np.asarray(xs), np.asarray(ys)])
    _, nearest = centroid_tree.query(coords, k=1)
    gap_pop = pop[rows, cols]
    np.add.at(totals, nearest, gap_pop)


def _attribute_overlaps_window(
    over_mask: np.ndarray,
    pop: np.ndarray,
    count: np.ndarray,
    label_shapes: list,
    transform,
    out_shape: tuple[int, int],
    totals: np.ndarray,
    log: logging.Logger,
) -> None:
    """Per-polygon expansion for overlap pixels (count >= 2). Rare in
    practice; 0 instances across 84 ISOs in the live run."""
    n_overlap = int(over_mask.sum())
    log.debug("  overlap pixels in window: %d", n_overlap)
    if n_overlap == 0:
        return
    for poly_geom, poly_idx_1based in label_shapes:
        poly_mask = features.rasterize(
            [(poly_geom, 1)], out_shape=out_shape, transform=transform,
            merge_alg=features.MergeAlg.replace, dtype=np.uint8,
            fill=0, all_touched=False,
        )
        contributes = over_mask & (poly_mask == 1)
        if contributes.any():
            share = pop[contributes] / count[contributes].astype(np.float64)
            totals[poly_idx_1based - 1] += float(share.sum())
