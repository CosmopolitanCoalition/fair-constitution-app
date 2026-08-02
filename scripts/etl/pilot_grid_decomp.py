#!/usr/bin/env python3
"""
pilot_grid_decomp.py — the GRID DECOMPOSITION acceptance pilot, on CAN L2.

Implements stages 1+2 of GEODATA_GRID_DECOMPOSITION_PLAN.md as a standalone,
timed proof against the one unfinished pair of run 019fc1bf:

  1. window_pop  — one streaming pass over the (staged) CAN tif: per raw-grid
                   window, the population sum. has_pop=false windows vanish
                   from the workload before any geometry is touched.
  2. decompose   — recursive bisection of L1 + every L2 geometry against the
                   SAME pixel-anchored window grid the pair enumerated
                   (window_px pinned): FULL windows vs boundary PIECEs,
                   O(V log W) instead of O(V × W).
  3. attribute   — per populated window: L1 FULL ⇒ constant claim; single
                   FULL owner ⇒ O(1) sum add; else burn the tiny pieces with
                   the EXACT mask semantics of raster_attribution
                   (all_touched=False, MergeAlg add/replace, count==1 direct,
                   count>=2 split, gaps → nearest centroid KDTree).
  4. verdict     — vs jurisdictions.population_baseline (raster national sum).

Prints a JSON verdict with a timing breakdown. --apply writes populations and
marks nothing else — run/pair bookkeeping stays the operator's call.

Memory law: geometries are parsed ONE AT A TIME (decompose then discard the
parse; keep only pieces/FULL sets), so peak ≈ one monster parse transient +
the manifest — the co-residency lesson of kills #32-36 baked in.
"""

from __future__ import annotations

import json
import sys
import time

sys.path.insert(0, "/etl")

import numpy as np
import rasterio
import shapely
import shapely.wkb
from rasterio import features, transform as rio_transform, windows
from scipy.spatial import cKDTree
from shapely.ops import clip_by_rect

from db import get_connection, get_cursor

WINDOW_PX = 1024
ISO = "CAN"
LEVEL = 2


def log(msg: str) -> None:
    print(f"[{time.strftime('%H:%M:%S')}] {msg}", file=sys.stderr, flush=True)


# ─── Grid (pinned to the tif's pixel lattice, same as the pair's windows) ───

class Grid:
    def __init__(self, tif_path: str):
        with rasterio.open(tif_path) as src:
            self.t = src.transform
            self.width = src.width
            self.height = src.height
            self.nodata = src.nodata
        self.nx = -(-self.width // WINDOW_PX)
        self.ny = -(-self.height // WINDOW_PX)

    def win_rect(self, wi: int, wj: int):
        """CRS rect of window (wi=col-block, wj=row-block) — pixel-anchored."""
        c0, r0 = wi * WINDOW_PX, wj * WINDOW_PX
        w = min(WINDOW_PX, self.width - c0)
        h = min(WINDOW_PX, self.height - r0)
        x0 = self.t.c + self.t.a * c0
        y1 = self.t.f + self.t.e * r0
        x1 = x0 + self.t.a * w
        y0 = y1 + self.t.e * h
        return (x0, y0, x1, y1, c0, r0, w, h)


# ─── Recursive bisection (the plan's reference, productionized) ──────────────

def decompose(geom, grid: Grid, i0, i1, j0, j1, out_full: set, out_piece: dict):
    if geom.is_empty:
        return
    x0 = grid.t.c + grid.t.a * (i0 * WINDOW_PX)
    x1 = grid.t.c + grid.t.a * min(i1 * WINDOW_PX, grid.width)
    y1 = grid.t.f + grid.t.e * (j0 * WINDOW_PX)
    y0 = grid.t.f + grid.t.e * min(j1 * WINDOW_PX, grid.height)
    rect_area = abs((x1 - x0) * (y1 - y0))
    if rect_area <= 0:
        return
    if abs(geom.area - rect_area) <= 1e-9 * rect_area:
        for ii in range(i0, i1):
            for jj in range(j0, j1):
                out_full.add((ii, jj))
        return
    if i1 - i0 == 1 and j1 - j0 == 1:
        out_piece[(i0, j0)] = geom
        return
    if (i1 - i0) >= (j1 - j0):
        im = (i0 + i1) // 2
        xm = grid.t.c + grid.t.a * (im * WINDOW_PX)
        decompose(clip_by_rect(geom, x0, y0, xm, y1), grid, i0, im, j0, j1, out_full, out_piece)
        decompose(clip_by_rect(geom, xm, y0, x1, y1), grid, im, i1, j0, j1, out_full, out_piece)
    else:
        jm = (j0 + j1) // 2
        ym = grid.t.f + grid.t.e * (jm * WINDOW_PX)
        # note: e < 0 (north-up) — ym is BELOW y1, above y0
        decompose(clip_by_rect(geom, x0, ym, x1, y1), grid, i0, i1, j0, jm, out_full, out_piece)
        decompose(clip_by_rect(geom, x0, y0, x1, ym), grid, i0, i1, jm, j1, out_full, out_piece)


def decompose_geometry(wkb: bytes, grid: Grid) -> tuple[set, dict, int]:
    """Parse → make_valid → per top-level part → (FULL set, PIECE dict, nverts)."""
    g = shapely.wkb.loads(wkb)
    nverts = int(shapely.count_coordinates(g))
    try:
        g = shapely.make_valid(g)
    except Exception:
        pass
    parts = list(g.geoms) if hasattr(g, "geoms") else [g]
    full: set = set()
    piece: dict = {}
    inv_a, inv_e = 1.0 / grid.t.a, 1.0 / grid.t.e
    for p in parts:
        if p.is_empty:
            continue
        bx0, by0, bx1, by1 = p.bounds
        i0 = max(0, int((bx0 - grid.t.c) * inv_a) // WINDOW_PX)
        i1 = min(grid.nx, int((bx1 - grid.t.c) * inv_a) // WINDOW_PX + 1)
        j0 = max(0, int((by1 - grid.t.f) * inv_e) // WINDOW_PX)
        j1 = min(grid.ny, int((by0 - grid.t.f) * inv_e) // WINDOW_PX + 1)
        if i0 >= i1 or j0 >= j1:
            continue
        sub_piece: dict = {}
        decompose(p, grid, i0, i1, j0, j1, full, sub_piece)
        for k, v in sub_piece.items():
            if k in piece:
                piece[k] = shapely.union_all([piece[k], v])
            else:
                piece[k] = v
    # windows both FULL (from one part) and PIECE (another part) stay FULL
    for k in list(piece.keys()):
        if k in full:
            del piece[k]
    return full, piece, nverts


# ─── Main pilot ──────────────────────────────────────────────────────────────

def main() -> int:
    apply_db = "--apply" in sys.argv[1:]
    t_all = time.monotonic()
    conn = get_connection()
    res: dict = {"iso": ISO, "level": LEVEL, "window_px": WINDOW_PX}

    # tif: staged local copy if present, else the mount
    import pathlib
    staged = pathlib.Path("/data/tifcache/can_pop_2023_CN_100m_R2025A_v1.tif")
    tif = str(staged) if staged.exists() else \
        "/archive/worldpop_100m_latest/can/can_pop_2023_CN_100m_R2025A_v1.tif"
    grid = Grid(tif)
    log(f"grid {grid.nx}×{grid.ny} = {grid.nx*grid.ny} windows over {tif}")

    # ── geometries ──
    with get_cursor(conn) as cur:
        cur.execute("""
            SELECT id::text AS id, name, ST_AsBinary(geom) AS wkb,
                   ST_X(centroid) AS cx, ST_Y(centroid) AS cy
              FROM jurisdictions
             WHERE iso_code=%s AND adm_level=%s AND deleted_at IS NULL
             ORDER BY id
        """, (ISO, LEVEL))
        polys = [(r["id"], r["name"], bytes(r["wkb"]), r["cx"], r["cy"])
                 for r in cur.fetchall()]
        cur.execute("""
            SELECT ST_AsBinary(geom) AS wkb FROM jurisdictions
             WHERE iso_code=%s AND adm_level=1 AND deleted_at IS NULL LIMIT 1
        """, (ISO,))
        l1_wkb = bytes(cur.fetchone()["wkb"])
        cur.execute("""
            SELECT COALESCE(population_baseline,0) AS b FROM jurisdictions
             WHERE iso_code=%s AND adm_level=1 AND deleted_at IS NULL LIMIT 1
        """, (ISO,))
        l1_pop = int(cur.fetchone()["b"])
    log(f"{len(polys)} level polygons; baseline {l1_pop:,}")

    # ── stage 2: decompose, ONE GEOMETRY AT A TIME (co-residency law) ──
    t0 = time.monotonic()
    l1_full, l1_piece, l1_v = decompose_geometry(l1_wkb, grid)
    manifests = []
    total_pieces = 0
    for idx, (jid, name, wkb, cx, cy) in enumerate(polys):
        full, piece, nv = decompose_geometry(wkb, grid)
        manifests.append((full, piece))
        total_pieces += len(piece)
        log(f"  decomposed {name}: {nv:,} verts → {len(full)} FULL + {len(piece)} PIECE windows")
    t_decomp = time.monotonic() - t0
    res["decompose_s"] = round(t_decomp, 1)
    log(f"DECOMPOSE done in {t_decomp:.1f}s (L1: {len(l1_full)} FULL + {len(l1_piece)} PIECE; "
        f"{total_pieces} level pieces)")

    # window → candidate polygon indices
    win_owners: dict = {}
    for i, (full, piece) in enumerate(manifests):
        for k in full:
            win_owners.setdefault(k, []).append((i, "F"))
        for k in piece:
            win_owners.setdefault(k, []).append((i, "P"))

    centroid_tree = cKDTree(np.array([(cx, cy) for _, _, _, cx, cy in polys]))
    totals = np.zeros(len(polys), dtype=np.float64)

    # ── stages 1+3 fused: stream the tif once; window_pop implicit ──
    t0 = time.monotonic()
    n_skip = n_fast = n_burn = 0
    with rasterio.open(tif) as src:
        for wj in range(grid.ny):
            for wi in range(grid.nx):
                key = (wi, wj)
                owners = win_owners.get(key)
                in_l1 = key in l1_full or key in l1_piece
                if owners is None and not in_l1:
                    continue                       # outside everything
                x0, y0, x1, y1, c0, r0, w, h = grid.win_rect(wi, wj)
                pop = src.read(1, window=windows.Window(c0, r0, w, h))
                if src.nodata is not None:
                    pop[pop == src.nodata] = 0
                np.maximum(pop, 0, out=pop)
                if not (pop > 0).any():
                    n_skip += 1
                    continue                       # window_pop: has_pop=false
                # single FULL owner ⇒ O(1)
                if owners and len(owners) == 1 and owners[0][1] == "F":
                    totals[owners[0][0]] += float(pop.sum())
                    n_fast += 1
                    continue
                # burn masks from pieces (semantics identical to the pair)
                n_burn += 1
                tfm = src.window_transform(windows.Window(c0, r0, w, h))
                shape = (h, w)
                claim_shapes = []
                if key in l1_full:
                    claim = np.ones(shape, dtype=np.uint8)
                else:
                    if key in l1_piece:
                        claim_shapes.append((l1_piece[key], 1))
                    claim = None
                count_shapes, label_shapes = [], []
                for (i, cls) in (owners or []):
                    if cls == "F":
                        count_shapes.append(("FULL", i))
                    else:
                        g = manifests[i][1][key]
                        claim_shapes.append((g, 1))
                        count_shapes.append((g, i))
                        label_shapes.append((g, i + 1))
                if claim is None:
                    claim = features.rasterize(
                        claim_shapes, out_shape=shape, transform=tfm,
                        merge_alg=features.MergeAlg.replace, dtype=np.uint8,
                        fill=0, all_touched=False) if claim_shapes else \
                        np.zeros(shape, dtype=np.uint8)
                count = np.zeros(shape, dtype=np.int16)
                label = np.zeros(shape, dtype=np.int32)
                for entry in count_shapes:
                    if entry[0] == "FULL":
                        count += 1
                        label[:] = entry[1] + 1
                        claim[:] = 1
                    else:
                        g, i = entry
                        m = features.rasterize([(g, 1)], out_shape=shape,
                                               transform=tfm,
                                               merge_alg=features.MergeAlg.replace,
                                               dtype=np.uint8, fill=0,
                                               all_touched=False)
                        count += m.astype(np.int16)
                        label[m == 1] = i + 1
                in_claim = (claim == 1) & (pop > 0)
                ones = in_claim & (count == 1)
                if ones.any():
                    np.add.at(totals, label[ones].ravel() - 1, pop[ones].ravel())
                over = in_claim & (count >= 2)
                if over.any():
                    for entry in count_shapes:
                        if entry[0] == "FULL":
                            contributes = over
                        else:
                            g, i2 = entry
                            m = features.rasterize([(g, 1)], out_shape=shape,
                                                   transform=tfm,
                                                   merge_alg=features.MergeAlg.replace,
                                                   dtype=np.uint8, fill=0,
                                                   all_touched=False)
                            contributes = over & (m == 1)
                            i = i2
                        if entry[0] == "FULL":
                            i = entry[1]
                        if contributes.any():
                            share = pop[contributes] / count[contributes].astype(np.float64)
                            totals[i] += float(share.sum())
                gaps = in_claim & (count == 0)
                if gaps.any():
                    rows, cols = np.where(gaps)
                    xs, ys = rio_transform.xy(tfm, rows.tolist(), cols.tolist())
                    coords = np.column_stack([np.asarray(xs), np.asarray(ys)])
                    _, nearest = centroid_tree.query(coords, k=1)
                    np.add.at(totals, nearest, pop[rows, cols])
    t_stream = time.monotonic() - t0
    res.update({"stream_s": round(t_stream, 1), "skipped": n_skip,
                "fast_windows": n_fast, "burned_windows": n_burn})

    post = {polys[i][0]: int(round(totals[i])) for i in range(len(polys)) if totals[i] > 0}
    post_sum = sum(post.values())
    dev = post_sum - l1_pop
    pct = abs(dev) / l1_pop * 100 if l1_pop else 0
    verdict = ("exact" if pct < 0.01 else "near" if pct < 1 else
               "partial" if pct < 5 else "far")
    res.update({"post_sum": post_sum, "l1_pop": l1_pop,
                "dev_pct": round(pct, 3), "verdict": verdict,
                "provinces": {polys[i][1]: int(round(totals[i]))
                              for i in range(len(polys))},
                "total_s": round(time.monotonic() - t_all, 1)})

    if apply_db and verdict in ("exact", "near") and post:
        with get_cursor(conn) as cur:
            for jid, p in post.items():
                cur.execute("UPDATE jurisdictions SET population=%s, "
                            "population_year=2023, updated_at=now() WHERE id=%s",
                            (p, jid))
        res["applied_rows"] = len(post)

    conn.close()
    print(json.dumps(res))
    return 0


if __name__ == "__main__":
    sys.setrecursionlimit(100000)
    sys.exit(main())
