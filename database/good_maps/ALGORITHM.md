# The Good Maps Districting Algorithm

**Status: ACCEPTED.** Operator verdict on the iteration-19 rewalk (2026-08-25):
*"With very little exception I would say this is the best map set you have ever
created."* The accepted maps are **"Good Maps — Auto Iteration 19"** (Earth
`6dc4117a-c843-4e58-aff0-3f9fa9a25f3b`, USA `41682156-149d-4e37-af60-82d3c6b165a8`),
drawn by engine commit `42495b5` and reproducible from it byte-for-byte (the
recreation guarantee — the engine is deterministic; load-bearing ORDER BY
clauses and cursor-based BFS keep it so).

This document records the algorithm, the laws it obeys, and the logical steps
that produced it, at rebuild grade: a human could implement it from this, and
an AI could recreate the campaign. The implementation is
`app/Services/DistrictingService.php` (PROTECTED); the pins are
`tests/Feature/DistrictingDoctrineTest.php` (38 tests). Statistics for the
standards and all 19 iterations live beside this file.

---

## 1. The objective — what a "good map" is

A drawing partitions a **scope** (a jurisdiction) into **districts**, each a
set of whole child jurisdictions (geometry is never cut; the one exception is
the splitline tool inside childless leaf giants, out of scope here). Quality
is judged on four counts in **strict priority order** (the operator's revealed
order from his manual maps):

1. **Legality** — absolute, excluded not scored:
   - The chamber total is the cube-root law (`round(pop^(1/3))`); every
     scope's districts sum exactly to its cascade budget. **Drift is always
     wrong** — a drifted drawing can never beat any exact one.
   - Every district holds 5–9 seats (Art. II §2). Lawful exception: a
     promoted-giant remainder below the floor carries `floor_override`.
2. **Contiguity, spread first** — a district should be connected land.
   Forced breaks (islands, giant-promoted separations) are lawful, but **THE
   SPREAD LAW** (operator, on walking iteration 12): *"even in
   non-contiguity forced situations we can make them as compact as possible
   to minimize the spread."* Detached pieces sit near their hosts; minimizing
   the COUNT of flagged districts never justifies trans-oceanic grab-bags.
   Within a spread class, fewer breaks win.
3. **Compactness** — cut length (real shared-border length between
   districts) is the in-loop currency; convex hull ratio
   (`ST_Area(union)/ST_Area(ST_ConvexHull(union))`) is the reported metric;
   neck count (articulation points splitting ≥25% of members) and
   population-weighted radius of gyration are the finer keys.
4. **Deviation** — population balance is an **acceptability threshold**
   (avg ≤4%, max ≤10%): beyond it, banded penalties outrank everything below
   legality; within it, deviations tie and shape decides; raw deviation
   returns only as the late tiebreak. The operator's maps pay deviation for
   contiguity and shape, never the reverse.

## 2. The comparator (scoreRank) — the whole doctrine as 12 lexicographic keys

Candidates compete on a vector compared lexicographically (lower wins):

| # | Key | Notes |
|---|---|---|
| 1 | `seat_drift` | \|Σ nearest-rounded seats − budget\|. Exactness is law. |
| 2 | avg-deviation excess | 0 if ≤4%, else banded in 2pp steps |
| 3 | max-deviation excess | 0 if ≤10%, else banded in 5pp steps |
| 4 | fragment-gap band | Σ over bins of each detached piece's closest approach to its bin's main fragment, in **doubling bands** (`floor(log2(1+gap))`). Ranks BEFORE the break count — the spread law. |
| 5 | non-contiguous count | breaks, within a spread class |
| 6 | seat-spread **excess over the canonical partition at that k** | `budget%k≠0` makes spread 1 arithmetic, not a defect; raw spread punished fat plans and forced 10×5 over 9+9+8+8+8+8 in Texas. Canonical mixes have excess 0 by construction, so all within-k mix doctrine survives. |
| 7 | cut length | real border km between districts (stringiness) |
| 8 | neck count | pinch points |
| 9 | avg Rg² | centroid compactness proxy |
| 10 | avg Droop threshold | `mean(1/(s+1))` — proportionality, sacrificed first |
| 11 | raw avg deviation | the late tiebreak |
| 12 | raw fragment gap | the very last word |

Two keys were retuned during this campaign against the standard maps, with
the operator's walk as sanction: gap-band moved above break count (spread
law), and the former 1pp deviation sub-band key was **removed** (within
acceptability, shape decides — the standard pays +1.08pp in South Carolina
for −21% cut). Every retune preserved all prior pinned outcomes because
0-break sides carry gap 0.

## 3. Seat budgets — the cascade (settled law, never re-derive)

- Chamber = cube root of population, nearest-rounded. No Webster,
  Sainte-Laguë, or largest-remainder anywhere.
- A scope's budget splits to children by population share with the
  **children-sum** as denominator. A child whose share reaches
  `ceiling + 0.5` (9.5) fractional seats is a **giant**: it locks at its
  nearest-rounded seats and becomes its own scope; the remainder
  redistributes among the rest, **recursively** (nth-order promotion — a
  child pushed past 9.5 by an earlier giant's redistribution promotes too;
  Ukraine is the canonical case).
- Non-giant children form the scope's **composite pool** with budget =
  scope budget − Σ giant locks. Drawn districts round to NEAREST
  independently; there is no total-forcing loop. Exactness is enforced by
  candidate exclusion and by `landSeatVector` repairs (single-child moves
  between bins), never by rebudgeting.

## 4. Generation — one scope (`runAutoCompositeForScope`)

1. **Load children + centroids.** Centroids come from a precompute table
   (`jurisdiction_centroids`) or `ST_Centroid`. **Every centroid distance in
   the engine is antimeridian-aware**: `dx = |x1−x2|; if dx > 180 then
   dx = 360 − dx`. (Raw deltas made Florida "nearer" to Guam than Hawaii and
   built trans-oceanic grab-bags — the single worst geometric bug found.)
2. **Classify giants** with the one-frame lock: the cascade's own
   classification (`giantChildrenForScope`) is authoritative when it agrees
   about the budget.
3. **Adjacency graph**: sibling pairs whose two-tier-simplified geometries
   intersect with `ST_Dimension ≥ 1` (a shared LINE, not a point), with the
   shared border length as edge weight (the cut-length currency). Backed by a
   precompute table; the live query's ORDER BY is load-bearing for
   determinism.
4. **Components + satellites (round-9 accounting)**: connected components of
   the graph. Components under `floor − 0.5` fracs are **satellites**: they
   COUNT toward their nearest host component's budget and quota, candidates
   are built on the real landmass, and satellites physically attach to their
   closest-approach bin after scoring.
5. **The k-loop**: for each district count k in
   `[ceil(budget/9) … min(floor(budget/5), kMin+7)]`, generate candidates:
   - BFS seed expansion — every member as first seed with far-point spread
     for the rest, plus a top-k-by-population anchor set; a cheap BFS-only
     proxy gates the top 20 into the full pipeline.
   - The sequential builder — the operator's hand method mechanized: seed at
     the most-constrained unassigned child (corners/pockets), grow toward
     whole-seat population targets with dynamic retargeting and remainder
     awareness; metro seeding lets a metropolitan cluster form its own bin.
   - Bisection line-first — 12-direction sweeps cutting the member list at
     canonical population boundaries (the operator's border-first method).
6. **The polish pipeline** (every candidate): balance minimax → compact
   exchanges (Rg², 2.5% caps) → border smoothing (cut-reducing moves) →
   post-compact minimax → **tiered cut descent**: singles + 1:1 exchanges to
   a fixed point, then ONE big move (2:0 chains, 2:1 exchanges), repeat —
   tiering keeps added vocabulary strictly additive → **pair re-bisection**:
   re-draw each touching district pair from scratch with the 12-direction
   sweep over the pair's union at its canonical 2-part budget, adopt on
   strict internal-cut decrease (the union's outer boundary is fixed, so
   total cut moves by exactly the internal delta).
7. **Variants**: `landSeatVector` (exactness landing — attempted whenever the
   total misses; an exact landing is adopted unconditionally) and
   `breakRebalance` (the last-resort deliberate break, fragments kept close).
8. The comparator picks the winner across all k and all variants.

## 5. The repair layer (Step 8c) — this campaign's construction

Runs on the FINAL bins of each scope, after satellites are attached and the
landing is done, in this order (contiguity outranks compactness, compactness
outranks deviation):

### 5a. breakRepairPass — contiguity and spread
- **Pass A — nearest-host redistribution.** Every detached fragment
  (island-exempt included) belongs with the bin whose main landmass it sits
  nearest. Try the top-3 nearest hosts (Step-11 rounding walls often block
  the first). Also in the vocabulary:
  - **Compensated moves** — when a plain move fails only because the donor's
    remainder breaks the deviation band, pair it with one compensator moving
    IN (a fragment or ≤2.5-frac member of another bin within 15° of the
    donor's main, its own donor staying whole). This is the operator's own
    chain shape: his Caucasus keeps its floor by gaining Turkmenistan when
    Cyprus leaves for the Levant.
  - **Combination dissolution** — scatter a whole remainder-pool bin, every
    fragment to its own nearest host, enumerated over placement combinations
    (top-3 hosts per fragment, base-3 odometer, ≤243 combos), k drops by one.
- **Pass A2 — pool 2-center split** (the move that cracked the USA root).
  For each pure pool of detached smalls: gather its pieces plus nearby
  helpers (far fragments of other bins within 50° — the Alaska class; small
  ≤2.5-frac members within 12° whose donors stay whole — the Vermont class),
  2-center the set at its two mutually farthest pieces, repair fracs toward
  the light side, then search single-piece variants (boundary swaps + helper
  drop-backs) until one passes every guard. k RISES by one — the standard's
  own structure (its Pacific pool is Alaska + Hawaii + Guam + CNMI +
  American Samoa at 4.98 fr → 5 seats, no override).
- **Pass B — satellite-aware pair re-split.** For any multi-fragment bin,
  re-bisect it with each touching neighbor over the pair's union: strip the
  union's detached components, bisect the mainland, re-attach each detached
  component to the closest half, judge connectivity on the mainland only
  (the island exemption). Adopt when the pair's avoidable-flag count drops,
  or at equal flags when the pair's fragment spread drops.

**Acceptance in every pass is THE LAW, not proxy caps** (proxy caps caused a
silent zero-adoption iteration): simulate Step 11's own arithmetic on the
trial — `seats = max(minSeat, min(9, round(frac)))` with
`minSeat = (budget ≥ bins×5) ? 5 : 1` — and require the exact budget landing;
max deviation ≤10% per district; no bin NEWLY below the override zone
(`floor − 0.5` — donors MAY enter the lawful floor_override zone; forbidding
that kept far pieces as ballast, the anti-pattern the Zhoushan pin forbids);
no bin at/above the giant threshold; total spread strictly improves. Log
every rejection with its reason.

### 5b. hullRepairPass — the reported metric defends the shape
Cut length and hull ratio ANTICORRELATE on concave/coastal scopes (a shorter
cut dropped Ukraine's real CHR .738 → .662). So on the final bins, per
touching pair: the incumbent split defends against the cut-best and top-3
Rg²-best legal candidates from the pair's satellite-aware 12-direction
bisection sweep PLUS 1:1 border-swap variants (straight lines never generate
a single-member trade — the Ukraine/Poltava class), measured with
**recomputeDistrict's EXACT hull formula** (two-tier simplify + cache →
ST_Union → area/hull-area) so the pass optimizes the reported number itself.
Adopt on strictly better pair-mean hull (or equal hull, shorter cut). 2–3
PostGIS union calls per pair keeps planet cost bounded.

### 5c. comparatorPolishPass — fit recovery only
Greedy border singles + 1:1 exchanges adopted only when **avg deviation
strictly improves AND the full rank vector does not worsen**. (Unconstrained
ascent on the comparator lawfully kept converting deviation into compactness
past the standard's trade point — measured and reverted.) Bounded to
≤150-member scopes.

## 6. The reported contiguity law (what `is_contiguous` means)

BFS over plain `ST_Intersects` on two-tier-simplified geometries, starting
from the most-connected member. A district is flagged non-contiguous ONLY
when the break was **avoidable**: some orphaned piece shares a border with an
AVAILABLE (non-giant) sibling. True islands and giant-locked remainders are
exempt. Consequence for repair design: concentrating orphans lowers the flag
count while raising spread — which is why the spread law, not the flag
count, drives the repair objective.

## 7. Results (iteration 19 vs the standards)

| Count | USA (auto vs standard) | Earth (auto vs standard) |
|---|---|---|
| Legality | 702 exact, 0 band — parity | 2003 exact, 0 band — parity |
| Contiguity | **4 clusters vs 7 — better** | **17 vs 21 — better** |
| Compactness | **CHR .7723 vs .7677 — better** | .6279 vs .6320 (41 scopes better / 18 worse) |
| Deviation | 12.48 vs 10.96 | 39.86 vs 37.21 |

Resolved during the Class-1 round: Vietnam (interleave → 3 contiguous
districts, coastal islets ≤1.2°), Russia (→ Sakhalin 8.0° + the unavoidable
Kaliningrad exclave 8.2°), Ukraine (Poltava stringiness → fully contiguous),
USA #16 (Hawaii+Rhode Island grab-bag → the standard's own structure: the
Alaska-anchored Pacific pool and a tight RI+DE+DC Atlantic pool, root k
16→17).

**Known residuals** (accepted by the operator "with very little exception"):
Cyprus rides the Caucasus at 12.5° (standard: Levant ~3°), Ecuador 29.8°
(standard cluster ~12°), Mongolia 29.0° (standard: DPRK pairing ~12°), New
Mexico 25.8° (standard: NE+NM+SD ~8°). Each is provably unreachable by any
single lawful move or any of 36 compensated pairings — they need multi-bin
chains balanced globally, the way the operator's hand works.

## 8. The method that built this (for the next rebuilder)

1. **The standard is the answer key.** Save the target maps' full row data
   and statistics FIRST (restorable CSVs + fingerprints); every design
   question ("what should the structure be?") is answered by reading what
   the operator's map actually does, not by theory.
2. **Trace, never theorize.** Every breakthrough came from a *reflection
   lab*: load the live scope's children/adjacency/bins in tinker, invoke the
   private pass via Reflection, and print per-candidate outcomes — or from
   rejection logs at every gate ("a silent zero-adoption run can never
   happen again"). Every hand-simulation of guard arithmetic was wrong at
   least once; the labs never were.
3. **One lever per iteration**, full-planet regeneration (~20 min for Earth
   81 scopes + USA 30 via `MassReseedJob` `map_plus_children_all` from
   root), scored by the committed scorer against the committed standards.
   Deploy = commit → push → pull → **`docker restart fc_horizon`** (workers
   hold old code in memory; the classic miss).
4. **The comparator is retuned only against the standard's revealed
   behavior**, and every retune is pinned in DistrictingDoctrineTest so all
   prior doctrine survives by construction (0-break sides carry gap 0, so
   gap-before-count flips only the comparisons the operator's walk flipped).
5. **Guards mirror the real law or they lie.** Proxy caps (a flat 4%
   per-bin cap; a no-sub-floor window) silently froze whole repair passes;
   the fix each time was simulating Step 11's actual arithmetic and the
   actual band, with the actual override zone.

## 9. Reproduction runbook

```bash
# Draw a fresh map pair (game box):
#   create draft map rows, then dispatch per legislature —
#   MassReseedJob(leg_id, 'map_plus_children_all', root_jurisdiction_id, map_id, operator_user_id)
# Export stats for a map:
bash database/good_maps/tools/export_map_stats.sh <map_id> > out.csv
# Score against a standard:
python3 database/good_maps/tools/score.py \
  database/good_maps/stats/districts_earth_manual_draft1_2ed73bff.csv out.csv 2003
# Regenerate the standards' full record:
bash database/good_maps/tools/export_good_maps.sh
```

Spread verification (the Class-1 probe) reconstructs per-district fragment
structure from `jurisdiction_adjacency` + wrap-aware centroid distances; see
the campaign transcript pattern or rebuild from §6.

## 10. Iteration chronology (all on 2026-08-23/24, commits on main)

| Iter | Commit | Change |
|---|---|---|
| 1 | — | Baseline: current engine = byte-identical to stored auto drafts (determinism proven) |
| 2 | `d031d62` | Comparator: spread-excess over canonical k; 1pp sub-band key removed |
| 3 | `714574a` | Cut-length descent pass + metro-seeded builder (Ohio beats standard) |
| 4 | `ad313d6` | Rg² guard + chains + pool telemetry (guard proved a wrong conviction) |
| 5 | `089ae9a` | Guard reverted; tiered descent; **pair re-bisection** (91% of USA CHR gap closed) |
| 6 | `e148e2a` | **Hull repair** — the reported CHR defends pair splits |
| 7 | `ede3bc7` | Satellite-aware hull repair (island class unlocked; Earth CHR parity) |
| 8 | `2bc737a` | Break repair v1 (proxy caps — zero adoptions, the negative result that taught the law) |
| 9 | `3861796` | **Acceptance = the law** (Step-11 simulation); Earth crosses on contiguity+compactness |
| 10 | `93c65c8` | Comparator polish v1 (traded deviation up the vector — measured, wrong) |
| 11 | `3e92da9` | Polish = fit-recovery only (Earth fit parity) |
| 12 | `0c60da4` | + avoidable-flag guard → **the combined map; operator walk: "best so far" + the spread law + Class-1 list** |
| 13 | `b1789a1` | **THE SPREAD LAW**: gap-band before count; nearest-host Pass A; Pass B spread objective; hull swap candidates |
| 14 | `5fbbcca` | **Antimeridian fix** (+ top-3 host fallback); Vietnam/Russia/Ukraine resolved |
| 15 | `275dc04` | Dissolution v1 (zero adoptions — instrumented) |
| 16 | `4ae92f4` | Combination-placement dissolution + rejection logs |
| 17 | `c9feb3e` | **Pool 2-center split** → the standard's own USA root structure, k 16→17 |
| 18 | `5c79ba4` | Override-zone donors; helper radius 12° |
| 19 | `42495b5` | Compensated moves (the Turkmenistan chain shape) → **ACCEPTED SET** |

Record commits: standards `9697d07`, verdicts/statistics through `680c5ee`.

## 11. THE REGRESSION GATE and the next phase (operator orders, 2026-08-25)

**Standing rule — the Earth+USA pair is the permanent benchmark:** *"Any
tweaks made to the algorithm would necessitate an iteration of Earth and USA
map to make sure the logic isn't being compromised."* Concretely: after ANY
change to `DistrictingService`, (1) run the doctrine suite, (2) regenerate a
fresh Earth + USA draft pair via `map_plus_children_all` from each root,
(3) score both against the standards with `tools/score.py` and probe the
Class-1 cases, (4) require: budgets exact, 0 unsanctioned band violations,
contiguity clusters ≤ the accepted set's (USA 4 / Earth 17) with no new
far-spread pieces, CHR and fit within noise of the accepted set (USA
.7723/12.48, Earth .6279/39.86) or better in the operator's priority order.
A change that degrades any count is not shipped without his word.

**Next phase:** fully map ALL jurisdictions planet-wide (the re-hook /
multithreaded Type A sweep), every legislature staying legal and optimized
to these standards — the same engine, the same laws, the same statistics
verified at every scale.
