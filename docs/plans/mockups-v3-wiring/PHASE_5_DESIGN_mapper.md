# Phase 5 design note — the manual district mapper, operational

**Status: DRAFT for operator review — written against the operator's 2026-07-02 work
order (verbatim): "I want to get this manual district drawing working and operational.
I need different auto seeds for it where lines are made that make sense. one bug I
notice immediately is my zoom in gets pulled back forcibly when the rasters are
showing... line drawing is hard anyway and the controls need a good overhaul to be
desktop and mobile friendly." The two bugs shipped at Phase 4 close-out; §3–§4 below
are the build proposal.**

## 1. What shipped immediately (Phase 4 close-out commit)

| Item | Root cause | Fix |
|---|---|---|
| Zoom forcibly pulled back when rasters showing | The raster tile layer declared `maxZoom: 12`; a Leaflet map with no own `maxZoom` derives its limit from its layers — toggling the raster on ran `setZoom(12)` and clamped all zoom-in | `maxNativeZoom: 12` (layer CSS-upscales z12 tiles past z12 — WorldPop is ~100 m native, so upscaled pixels are the honest rendering). Districts.vue + Jurisdictions/Show.vue |
| "Probe failed." on split-line | NOT a data/PostGIS failure — a CSRF 419. The tab sat idle past SESSION_LIFETIME (120 min); the expired session turned the probe POST into an HTML "Page Expired" the fetch helper could only report generically. (Serravalle's raster data probes perfectly: 1,487 pixels / pop 10,824.65 / 285 ms) | Session heartbeat (10-min ping + visibilitychange re-arm via `/api/session/heartbeat`, which also returns a fresh token so a slept tab self-heals without reload) + honest 419 message in all four draw-path fetch sites |

## 2. Ground truth (what exists)

- **Leaf-giant detection + seat budget**: `SubdivisionDrawController::giantContext()` —
  constitutional floor/ceiling (5–9, Art. II §2), `giant_threshold`, Webster-share seat
  budget (Serravalle: 10 of San Marino's 32).
- **Split line**: press-drag-release one straight segment → blade extended ±2° →
  `PopulationRaster::pixelGrid` (cached WorldPop centroids per scope) +
  `splitByBlade` (pure-PHP side sums) → both-sides readout → `splitCommit`
  (`ST_Split`, files F-ELB-008, audited).
- **Polygon**: Leaflet-draw polygon → `population_within_multi` probe (in-band /
  contiguous / within-giant) → draw commit (F-ELB-008).
- **Auto-seed stepper**: post-order DFS of giant scopes (`wizard-steps`), the mapper
  walks every drillable stop ("1/2" in the sidebar). The whole-child-unit autoseed
  (`DistrictingService::runAutoCompositeForScope`, PROTECTED) handles scopes WITH
  children; leaf giants are exactly the gap the manual tool fills — today entirely
  by hand, one cut at a time.

## 3. "Auto seeds where lines are made that make sense" — proposal

**Primary: a shortest-splitline autoseed for leaf giants.** One click on a leaf-giant
stop proposes a COMPLETE in-band plan; the operator accepts, or hand-adjusts any cut.

Algorithm (recursive population bisection — deterministic, hence auditable):

1. Take the scope's seat budget S (from `giantContext`). Choose the split ratio
   a:b = floor(S/2):ceil(S/2) — repeat until every piece holds 5–9 seats.
2. For each candidate blade angle (e.g. 24 angles over 180°), binary-search the
   perpendicular offset until the two sides hit the a:b population ratio (the exact
   search `splitByBlade` already makes cheap — pure PHP over the cached pixel grid).
3. Among balanced candidates, pick the one whose in-polygon blade segment is
   SHORTEST (the classic splitline criterion — short cuts hug natural compactness,
   never snake).
4. Recurse on both sides (each side's pixel subset is already in hand from the
   winning `splitByBlade` call).
5. Present the whole tree as DRAFT sub-districts on the map, each committed via the
   existing `splitCommit`/draw path — every piece an audited F-ELB-008 filing, the
   same as a hand cut. Nothing new touches the PROTECTED `DistrictingService`.

Cost: Serravalle's grid is 1,487 pixels — interactive. A metropolis-scale leaf giant
(hundreds of thousands of pixels) runs as a queued job with the existing mass-status
progress pattern. Determinism: fixed angle set + fixed tie-breaks ⇒ same map +
same rasters = same plan, on any node.

### 3b. Seeding-options review (operator's AI survey, 2026-07-02)

Six candidate initializers judged against the LIVE Map Quality metrics
(`DistrictingService`: per-seat deviation avg/max, constitutional contiguity with
exemptions, convex-hull-ratio compactness, uniform-diversity seat grouping) plus the
two platform-specific constraints the survey couldn't know: **determinism** (same map +
same rasters must reproduce identically on every mesh node — the audit chain and FF&C
demand it) and **no partisan data** (the platform holds none, by design).

| Option | Verdict | Why |
|---|---|---|
| 5. Shortest splitline | **PRIMARY (build — §3)** | Deterministic → auditable; exact balance by binary search; reuses shipped primitives (pixelGrid/splitByBlade/ST_Split); its cuts are the same species the manual tool commits (F-ELB-008) |
| 1+4. Balanced power diagram seeded by high-density anchors | **SECONDARY (build — the "different auto seed")** | Convex cells ≈ top CHR; capacity weights → near-exact balance; density-peak seeds keep towns intact (the community-integrity value that survives inside a childless leaf); deterministic with pinned seeding + tie-breaks |
| 3. Population-tiered slicing + region growing | Fold the goal, skip the algorithm | Its ≤5% target is already exact under both builds; grown regions have ragged borders that would DRAG the CHR stat below the composite baseline |
| 2. Farthest-point sampling → simulated annealing | **Reject** (SA) | Nondeterministic/unbounded runtime — unauditable on the constitutional plane. (FPS itself survives as a deterministic seeding fallback if anchors cluster.) |
| 6. MCMC / ensemble baseline | **Reject as seeder** | It mutates maps, it doesn't draw them; ensemble gerrymander detection presupposes partisan vote data the platform deliberately lacks. If ever wanted, it is an offline audit instrument, not a seeder. |

Both builds land as PREVIEW plans scored by the same Map Quality panel, side by side —
the operator accepts whichever reads better per scope. Note: inside leaf giants,
free-form drawing should typically RAISE the map-wide stats versus the composite
baseline (Earth today: mean CHR 0.639, avg deviation 2.3%) — composite inherits ugly
admin-unit shapes and whole-unit quantization; pixel-resolution cuts and convex cells
do not. Splitline caveat for 5a: `ST_Split` of a NON-convex leaf can yield >2 pieces /
a disconnected side — validate contiguity per candidate cut and discard violators
(the shortest-line criterion rarely picks them anyway).

**Secondary: "snap to balance" for a hand-placed line.** The operator places a rough
line; one button slides it perpendicular (the inner loop of step 2, one angle) to the
nearest in-band balance. This is the assist for "line drawing is hard" — the human
picks the direction that makes sense (a river, a highway), the machine finds the
exact offset. Cheap: it is one binary search over the cached grid.

## 4. Controls overhaul — desktop AND mobile

Interaction model (replaces press-drag-release, which fights map panning and is
near-impossible on touch):

1. **Two-tap placement**: tap start, tap end — no drag, no pan conflict, works
   identically with mouse and finger. (Desktop keeps drag as a shortcut.)
2. **Persistent endpoint handles** (44 px touch targets) after placement: drag a
   handle to refine; the probe re-fires on release. Arrow keys nudge on desktop.
3. **Mid-line handle** translates the whole line; **Snap to balance** (§3) does the
   final precision so the human never has to.
4. **Mobile posture**: the tool rail becomes a bottom sheet (the `.map-split`
   portrait stack from the Phase 0 mockup contract); the probe readout is a floating
   chip above it; buttons at thumb scale.
5. **Undo per cut** within a draft (each commit already lands one at a time; the
   existing delete-district endpoint gives the revert — the overhaul surfaces it as
   Undo).

## 5. Build slices (each gated on green suite + DOM smoke)

1. **5a — splitline autoseed service + endpoint** (`SubdivisionAutoseedService`,
   probe-only "preview plan" endpoint; unit pins on determinism + in-band + ratio).
2. **5b — accept/adjust UI** on the mapper (draft overlay, per-cut accept, snap to
   balance button).
3. **5c — controls overhaul** (two-tap + handles + bottom sheet + undo).
4. **5d — the stepper integration** (Autoseed-lines button per leaf-giant stop; the
   San Marino ×2 walk end-to-end is the acceptance test).

## 6. Not doing / boundaries

- No touch to the PROTECTED `DistrictingService` autoseed (whole-child-unit
  composition stays THE autoseed for scopes with children).
- No raster edits, no new PostGIS functions — `pixelGrid` + `splitByBlade` +
  `ST_Split` are sufficient primitives.
- Judicial-district reuse is noted (same splitter, different seat source) but waits
  for the operator's districting-lane direction.

## 7. ⚑ Open questions for the operator (low stakes, defaults stated)

1. Should the splitline autoseed **auto-commit** the whole proposed plan in one click
   (fastest), or land as a **preview the operator accepts cut-by-cut** (default —
   matches the "operator's hand on every filing" posture)?
2. Blade angles: straight lines only (default, classic splitline), or also allow the
   proposal to reuse child-unit boundaries where any exist mid-giant? (Serravalle has
   none — leaf giants rarely do.)
