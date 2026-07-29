# B4 hull-island method — technical validation (lane 1, Wave 3)

*Operator invited the check ("check me on it"); desk ordered it FIRST, before any
mass pass. This is the analytical validation + empirical test plan. B4 is the
operator's ruling — the recommendation below is a FLAG for his word, not a
substitution.*

## The method under test (B4, verbatim intent)

A disconnected jurisdiction's **WorldPop mask** → a smoothed imaginary border
(**hull**) around all boundary pieces → the simple **line-drawing** over that
pseudo-contiguous shape (ocean/foreign pixels carry no population — not in the
mask) → **cut away components outside the real boundary mask.** Grouping
adjacency also derived from **hull-contact.**

## Data reality on the dev box — the empirical test cannot run here

| probe | result |
|---|---|
| jurisdictions (live) | 26, all San Marino |
| multi-part geoms (islands) | **0** — every geom single-part, contiguous |
| adjacency edges | 40, **all dim=1** (shared land borders) |
| WorldPop raster rows | 2 |
| centroids | 9 |

San Marino is a landlocked microstate: **no island, no enclave, no antimeridian
case exists on this box.** Empirical validation of the hull (enclave exclusion,
degenerate/antimeridian hulls) needs a box with real archipelago jurisdictions
and full WorldPop coverage — the game/live box. PostGIS 3.5.2 is present and all
hull primitives (`ST_ConvexHull`, `ST_Buffer`, `ST_Dump`, `ST_NumGeometries`)
work.

## Two problems the hull is asked to serve — they must be separated

1. **Type A island DISTRICTING** — line-splitting an archipelago constituent
   that is itself over-ceiling into 5–9-seat districts. Needs a contiguous shape
   to cut. **This is the hull's true home.**
2. **Type B grouping ADJACENCY** (lane 1's Wave 3 deliverable) — deciding which
   island siblings clump into a shared panel. Needs an adjacency relation among
   islands.

## Finding 1 — the engine ALREADY seats islands without a hull

`DistrictingService` Step 7 (PROTECTED) builds BFS connected components, then
**attaches** tiny / island components to their nearest host via
`closestApproachSq()` — the minimum centroid distance between the two sets
(`DistrictingService.php:4603`). This is the shipped, tested **"islands-ride-whole"
doctrine (Art. II §8)**. For Type A, the hull is therefore **not a prerequisite**:
disconnected territory is already handled by nearest-host attachment.

## Finding 2 — the three flagged risks, assessed

- **Enclave (populated foreign land inside the hull).** SOUND **iff two clips
  hold**: population sampled from (WorldPop ∩ **real** boundary), never (∩ hull);
  and drawn pieces cut to the real boundary. With both, foreign pixels never
  enter the mask and foreign land never survives the cut — exactly as the
  operator reasoned. The risk is not the concept; it is **forgetting either
  clip.** Confirmed as the required invariant. Not exercisable on-box.
- **Degenerate / antimeridian hulls.** REAL — and already live in this codebase:
  `2026_07_21_000001_antimeridian_guard_population_within_multi.php` exists
  because Pacific-spanning multipart geoms break naive planar ops. A convex hull
  of antimeridian-crossing islands wraps the wrong way (spans ~359° of ocean); a
  two-island hull collapses to a line. Any hull step **must** run in a shifted
  CRS / geography and inherit that guard. Guardable, but a genuine gotcha.
- **Hull-contact adjacency.** THE weak link for grouping. Convex hulls of
  ocean-separated islands **do not touch** — literal contact yields an *empty*
  graph. To make them contact you must **buffer** each island outward by a
  distance *d*; then *d* controls everything (too small → disconnected; too
  large → complete graph). "Hull-contact" for grouping therefore **reduces to a
  distance-threshold proximity graph** — a nearest-neighbour graph with extra,
  fragile steps and a free parameter to tune.

## Recommendation — a FLAG for the operator's word (B4 is his ruling)

- **Keep the WorldPop-mask hull for the Type A DRAWING case** if/when we must
  line-split a single archipelago constituent that nearest-host attachment can't
  seat — with the two enclave clips and the antimeridian guard inherited.
- **For Type B grouping ADJACENCY (this deliverable), realize "hull-contact /
  nearest islands clump" as centroid (or exact geom) nearest-approach**, reusing
  the PROTECTED `closestApproachSq` primitive already shipped for the identical
  island-nearest job. Faithful to the operator's intent (nearest islands clump),
  deterministic, **parameter-free** (k-nearest, ties broken by `border_len` then
  id per B5), and it dodges all three hull risks. It also **unifies** the two
  cases: use `jurisdiction_adjacency.border_len` when a land-border edge exists,
  fall back to centroid nearest-approach when it does not — **one graph, no
  separate hull pipeline for grouping.**

This keeps the operator's method where it is genuinely needed (drawing) and
substitutes the simpler, proven, equivalent primitive where the hull is fragile
(grouping adjacency). It does **not** block the build: the contiguous grouping
path (the common case — most of the ~9,708 flagged parents are contiguous, like
San Marino's castelli) is built now; only the island adjacency path waits on his
word, behind the safe centroid-nearest default.

## Empirical test plan (needs island data — game/live box)

Pick a real archipelago parent (a Pacific nation): (1) confirm its
`jurisdiction_adjacency` graph is empty/sparse; (2) confirm centroid
nearest-approach yields the intuitively-correct clumps; (3) confirm enclave
exclusion via the two clips on a hull draw; (4) confirm the antimeridian hull is
guarded. Runnable against the game box on the desk's go, or handed to whoever
owns island geodata.
