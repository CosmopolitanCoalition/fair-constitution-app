"""
window_lattice.py — THE GLOBAL WINDOW LATTICE (2026-08-03).

Every WorldPop tif in the archive sits on ONE shared pixel grid: same pixel
size, origins an exact integer number of pixels apart. Measured across the
archive — worst fractional offset 2.9e-5 px, i.e. alignment is real, not
approximate. This module makes that fact load-bearing.

WHY IT EXISTS — THE CANADA GAP
==============================
pilot_grid_decomp.py did CAN L2 exact in 163 s using Canada's own tif alone.
Production attribute_grid took 3,005 s on the same pair. The entire gap is
one shape mistake: the production traversal iterated THE FULL GRID OF EVERY
PINNED TIF and re-decomposed every geometry against each tif's own lattice.
CAN pins three tifs, one being the USA — 430,706 x 62,971 px = 26,102
windows — so Canada's 16.5M vertices were bisected against the USA's lattice
for nothing. IND L6 pins TEN tifs with 649,710 features, and the geometry
fetcher caps its cache at 50,000 rows, so ~600k geometries were re-read from
postgres and re-parsed on every one of ten passes.

Keying windows to the shared mastergrid collapses all of it: one window =
one piece of geography = read once, and each geometry is decomposed ONCE.

WHY NOT "FIRST PINNED TIF OWNS THE WINDOW"
==========================================
Because it silently loses people. Where geoBoundaries' L1 reaches past
WorldPop's country mask, the owning tif reads nodata there while a neighbour
holds the population — and the old per-tif loop, for all its waste, DID pick
that up. Aksai Chin is a live instance: in that bbox IND>0 = 0 while
CHN>0 = 395. India's L1 claims the territory; the population exists only in
China's tif.

So a window is read from EVERY covering tif and combined with `fmax`. The
country masks are mutually exclusive (verified by sampling ON the shared
border: IND/BGD, IND/NPL, IND/PAK, FRA/DEU, and the disputed masks Aksai
Chin / Arunachal / Kashmir — BOTH>0 = 0 everywhere), so fmax is exactly
total-preserving; and it cannot double-count if a future dataset overlaps.

PIXEL SIZE IS DERIVED, NEVER HARD-CODED
=======================================
The archive's pixel size is 0.00083333333 — a truncated decimal, NOT
1/1200 (0.0008333333333333334). Assuming the mathematically ideal value
would drift the grid against the actual data. `lattice_pixel_size` reads it
off the headers and asserts every tif agrees.

PROOF OF EQUIVALENCE (2026-08-03, run live on this box before adoption):
per-jurisdiction A/B against the old per-tif traversal on NPL/LKA/NLD/CHE L2
across 1-6 tifs — ZERO jurisdictions differ, worst relative difference
0.00e+00. Window counts shrink (NPL 150 -> 66, BGD 105 -> 48) because shared
geography stops being enumerated once per covering tif.
"""

from __future__ import annotations

import math
from dataclasses import dataclass
from pathlib import Path

import rasterio

# The mastergrid origin every WorldPop tile is aligned to. Only the ORIGIN is
# fixed; the pixel size is read from the data (see module docstring).
MASTER_ORIGIN_X = -180.0
MASTER_ORIGIN_Y = 90.0

# A tif is "on the lattice" when its origin is an integer number of pixels
# from the mastergrid origin. Tolerance is in PIXELS and is deliberately far
# below one pixel: real misalignment shows up at 0.1 px and above, while the
# archive's own float noise measures 2.9e-5 px.
LATTICE_EPS_PX = 1e-3


class LatticeError(RuntimeError):
    """A tif is not on the shared mastergrid — window identity is unsafe."""


@dataclass(frozen=True)
class TifHeader:
    """One tif's header facts. Read ONCE and kept — never re-open a tif per
    window: windowed reads over the bind mount measured ~98% IO WAIT, so an
    open/close per window per covering tif would be a new IO term on the
    exact axis that already hurt (see run_t7_pair._stage_tifs)."""
    iso: str
    path: Path
    col_off: int      # global pixel column of this tif's origin
    row_off: int      # global pixel row of this tif's origin
    width: int
    height: int
    nodata: float | None
    px: float         # pixel size, degrees (positive)


@dataclass(frozen=True)
class LatticeWindow:
    """One window of the global lattice, and who can see it."""
    key: tuple[int, int]          # (I, J) global window index — THE identity
    bounds: tuple[float, float, float, float]   # minx, miny, maxx, maxy
    covering: tuple[int, ...]     # indices into the headers list


def lattice_pixel_size(headers_or_transforms) -> float:
    """The archive's shared pixel size, asserted uniform across the inputs."""
    sizes = set()
    for h in headers_or_transforms:
        px = h.px if isinstance(h, TifHeader) else abs(h.a)
        sizes.add(round(px, 15))
    if not sizes:
        raise LatticeError("no tifs supplied — cannot derive the lattice")
    if len(sizes) > 1:
        raise LatticeError(
            f"tifs disagree on pixel size {sorted(sizes)} — the global window "
            "lattice is undefined; re-stage the archive at one resolution")
    return next(iter(sizes))


def read_headers(paths, isos=None) -> list[TifHeader]:
    """Open each tif ONCE, assert it is on the lattice, and keep its header.

    This assertion point fails loudly rather than silently shifting a window
    grid — a half-pixel shift would move population between jurisdictions
    with no error anywhere."""
    raw = []
    for p in paths:
        p = Path(p)
        with rasterio.open(p) as s:
            raw.append((p, s.transform, s.width, s.height, s.nodata))
    px = lattice_pixel_size([t for (_, t, _, _, _) in raw])

    headers: list[TifHeader] = []
    for i, (p, t, w, h, nodata) in enumerate(raw):
        fx = (t.c - MASTER_ORIGIN_X) / px
        fy = (MASTER_ORIGIN_Y - t.f) / px
        cx, cy = round(fx), round(fy)
        if abs(fx - cx) > LATTICE_EPS_PX or abs(fy - cy) > LATTICE_EPS_PX:
            raise LatticeError(
                f"{p.name} is off the mastergrid: origin sits at "
                f"({fx:.6f}, {fy:.6f}) px from ({MASTER_ORIGIN_X}, "
                f"{MASTER_ORIGIN_Y}), fractional part "
                f"({abs(fx - cx):.2e}, {abs(fy - cy):.2e}) px > {LATTICE_EPS_PX}. "
                "Global window identity would be wrong.")
        iso = (isos[i] if isos is not None and i < len(isos)
               else p.stem.split("_")[0].upper())
        headers.append(TifHeader(iso=iso, path=p, col_off=cx, row_off=cy,
                                 width=int(w), height=int(h),
                                 nodata=nodata, px=px))
    return headers


def window_bounds(key: tuple[int, int], window_px: int, px: float
                  ) -> tuple[float, float, float, float]:
    """Geographic bbox of a global window key — pure arithmetic."""
    i, j = key
    minx = MASTER_ORIGIN_X + (i * window_px) * px
    maxy = MASTER_ORIGIN_Y - (j * window_px) * px
    return (minx, maxy - window_px * px, minx + window_px * px, maxy)


def enumerate_windows(claim_bounds: tuple[float, float, float, float],
                      window_px: int,
                      headers: list[TifHeader]) -> list[LatticeWindow]:
    """The pair's window sequence: every global window that both intersects
    the claim bbox and is covered by at least one pinned tif, ordered
    row-major by (J, I).

    DETERMINISTIC AND TIF-ORDER-INDEPENDENT. The old sequence was a function
    of `raster_paths` ORDER; here the key IS the geography, so the sequence
    means the same thing on any box, any resume, any tif ordering. Derivable
    from headers alone — no pixel reads."""
    if not headers:
        return []
    px = lattice_pixel_size(headers)
    cminx, cminy, cmaxx, cmaxy = claim_bounds

    # Claim bbox → global window index range. Nudge by a tiny epsilon so a
    # claim edge landing exactly on a window boundary doesn't pull in a
    # neighbouring window it only touches with zero width.
    eps = px * 1e-6
    i0 = math.floor((cminx - MASTER_ORIGIN_X) / px / window_px + eps)
    i1 = math.ceil((cmaxx - MASTER_ORIGIN_X) / px / window_px - eps)
    j0 = math.floor((MASTER_ORIGIN_Y - cmaxy) / px / window_px + eps)
    j1 = math.ceil((MASTER_ORIGIN_Y - cminy) / px / window_px - eps)

    # Each tif's own window-index footprint, so covering is arithmetic too.
    spans = []
    for h in headers:
        spans.append((
            h.col_off / window_px, (h.col_off + h.width) / window_px,
            h.row_off / window_px, (h.row_off + h.height) / window_px,
        ))

    out: list[LatticeWindow] = []
    for j in range(j0, max(j0, j1)):
        for i in range(i0, max(i0, i1)):
            covering = tuple(
                k for k, (a0, a1, b0, b1) in enumerate(spans)
                if a0 < i + 1 and a1 > i and b0 < j + 1 and b1 > j
            )
            if not covering:
                continue
            out.append(LatticeWindow(key=(i, j),
                                     bounds=window_bounds((i, j), window_px, px),
                                     covering=covering))
    return out


def window_index_range(bounds, window_px: int, px: float):
    """Global window index box [i0,i1) x [j0,j1) covering a geographic bbox."""
    bx0, by0, bx1, by1 = bounds
    i0 = math.floor((bx0 - MASTER_ORIGIN_X) / px / window_px)
    i1 = math.floor((bx1 - MASTER_ORIGIN_X) / px / window_px) + 1
    j0 = math.floor((MASTER_ORIGIN_Y - by1) / px / window_px)
    j1 = math.floor((MASTER_ORIGIN_Y - by0) / px / window_px) + 1
    return i0, i1, j0, j1


def _bisect(geom, window_px, px, i0, i1, j0, j1, out_full, out_piece):
    """Recursive bisection against the GLOBAL lattice — the same O(V log W)
    procedure as raster_attribution._grid_decompose, re-anchored from a tif's
    transform to the mastergrid so the result is tif-independent.

    Semantics preserved exactly: a rectangle the geometry fills completely
    becomes FULL windows (no vertex work ever again); a single window that is
    only partly covered keeps its clipped PIECE; colliding pieces stay LISTS
    (rasterize burns lists natively — unioning them is GEOS waste, the
    12-CPU-minute lesson) and stored geoms are NEVER make_valid'd."""
    if geom.is_empty:
        return
    x0 = MASTER_ORIGIN_X + (i0 * window_px) * px
    x1 = MASTER_ORIGIN_X + (i1 * window_px) * px
    y1 = MASTER_ORIGIN_Y - (j0 * window_px) * px
    y0 = MASTER_ORIGIN_Y - (j1 * window_px) * px
    rect_area = abs((x1 - x0) * (y1 - y0))
    if rect_area <= 0:
        return
    if abs(geom.area - rect_area) <= 1e-9 * rect_area:
        for ii in range(i0, i1):
            for jj in range(j0, j1):
                out_full.add((ii, jj))
        return
    if i1 - i0 == 1 and j1 - j0 == 1:
        out_piece.setdefault((i0, j0), []).append(geom)
        return
    from shapely.ops import clip_by_rect
    if (i1 - i0) >= (j1 - j0):
        im = (i0 + i1) // 2
        xm = MASTER_ORIGIN_X + (im * window_px) * px
        _bisect(clip_by_rect(geom, x0, y0, xm, y1), window_px, px,
                i0, im, j0, j1, out_full, out_piece)
        _bisect(clip_by_rect(geom, xm, y0, x1, y1), window_px, px,
                im, i1, j0, j1, out_full, out_piece)
    else:
        jm = (j0 + j1) // 2
        ym = MASTER_ORIGIN_Y - (jm * window_px) * px
        _bisect(clip_by_rect(geom, x0, ym, x1, y1), window_px, px,
                i0, i1, j0, jm, out_full, out_piece)
        _bisect(clip_by_rect(geom, x0, y0, x1, ym), window_px, px,
                i0, i1, jm, j1, out_full, out_piece)


def decompose_global(wkb_or_geom, window_px: int, px: float):
    """One geometry vs the GLOBAL lattice → (FULL set, {key: [pieces]}).

    Parses at most once, per top-level part so islands short-circuit
    (Nunavut is thousands of islands; most see one window)."""
    import shapely.wkb
    g = (shapely.wkb.loads(wkb_or_geom)
         if isinstance(wkb_or_geom, (bytes, bytearray, memoryview))
         else wkb_or_geom)
    if g.is_empty:
        return set(), {}
    parts = list(g.geoms) if hasattr(g, "geoms") else [g]
    full: set = set()
    piece: dict = {}
    for p in parts:
        if p.is_empty:
            continue
        i0, i1, j0, j1 = window_index_range(p.bounds, window_px, px)
        if i0 >= i1 or j0 >= j1:
            continue
        _bisect(p, window_px, px, i0, i1, j0, j1, full, piece)
    # A window that is FULL needs no piece — drop the redundant boundary work.
    for k in list(piece.keys()):
        if k in full:
            del piece[k]
    return full, piece


def read_window_fmax(key: tuple[int, int], window_px: int,
                     headers: list[TifHeader], covering, open_datasets):
    """Population array for one global window, combined across covering tifs
    with `fmax` (see module docstring: never first-tif-owns).

    `open_datasets` is a list parallel to `headers` of ALREADY-OPEN rasterio
    datasets — the caller opens each tif once and keeps the handle."""
    import numpy as np
    from rasterio import windows as rio_windows

    i, j = key
    out = None
    for k in covering:
        h = headers[k]
        src = open_datasets[k]
        # Global window → this tif's local pixel offsets. Integer arithmetic
        # throughout: both are anchored to the same asserted lattice.
        c0 = i * window_px - h.col_off
        r0 = j * window_px - h.row_off
        c_lo, r_lo = max(0, c0), max(0, r0)
        c_hi = min(h.width, c0 + window_px)
        r_hi = min(h.height, r0 + window_px)
        if c_hi <= c_lo or r_hi <= r_lo:
            continue
        arr = src.read(1, window=rio_windows.Window(
            c_lo, r_lo, c_hi - c_lo, r_hi - r_lo))
        if h.nodata is not None:
            arr[arr == h.nodata] = 0
        np.maximum(arr, 0, out=arr)
        if out is None:
            out = np.zeros((window_px, window_px), dtype=np.float32)
        # Place into the window-local frame (a tif may cover only part of it).
        out[r_lo - r0:r_hi - r0, c_lo - c0:c_hi - c0] = np.fmax(
            out[r_lo - r0:r_hi - r0, c_lo - c0:c_hi - c0], arr)
    return out
