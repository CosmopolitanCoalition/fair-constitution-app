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

## 5b. Road-test feedback wave (operator, 2026-07-02, from the field)

Five items from the operator's first hands-on session, built as slices 5e-5i:

1. **Shell width (5e)** — every v2 page rendered ~1555px left-pinned on a 1920 screen.
   Root cause: `.dev-bar { grid-area: devbar }` with no `devbar` area in the v2 shell
   grid → CSS Grid resolves the unknown name to an IMPLICIT auto-sized COLUMN that
   steals the dev bar's max-content width from the real column. Fix: the v2 grid gets
   its devbar row back (mirrors v1) + the dev bar lifts above the fixed command bar.
   Companion: AppShellV2 read `page.props.impersonation` but the middleware shares
   `auth.impersonating` — the impersonation dev-bar trigger never fired; fixed.
2. **Template picker (5f)** — "Polygon drawing is clunky; common algorithm templates
   to choose from would be nice." The §3b second seeder ships as part of a four-way
   picker on the leaf panel: Shortest lines · Vertical strips · Horizontal strips
   (both = the splitline recursion with a constrained angle set — parallel balanced
   cuts commute) · Community cells (the density-anchored balanced power diagram:
   deterministic density-peak seeds, Aurenhammer weight balancing over the pixel
   grid, exact convex cells by radical-axis half-plane clipping, then the proven
   PostGIS clip+shave). `template` joins the plan_hash, so commit fails closed on a
   template mismatch.
3. **Null-actor hole CLOSED (5g)** — found while diagnosing the operator's R-08
   refusal: the mutating draw routes were public and a null actor bypasses BOTH the
   engine role gate (ConstitutionalEngine::authorize) and the handler's board
   provenance — an anonymous guest could file F-ELB-008 while a signed-in
   non-board-member correctly could not. The three mutating routes now require auth;
   probes/preview stay public (read-only). New `can_draw` prop gates the UI honestly.
4. **Dev board-seat path (5h)** — R-08 derives only from a SEATED row on an ACTIVE
   election board, and the handler additionally demands the actor's OWN seat on the
   jurisdiction's board (operator posture is not enough). Dev-plane fix (same
   double-lock as all /dev routes): POST /dev/board/seat seats the current user on
   the active (bootstrap) board — one honest row; /dev/board/unseat reverses it. A
   dev-only strip on the mapper surfaces it when can_draw is false.
5. **Stepper → lines (5i)** — the wizard's Auto-seed silently no-opped on leaf-giant
   stops (completeness was vacuously true with zero children; composite reseed is a
   logged no-op on childless scopes). The completeness probe is now leaf-aware (drawn
   seats vs budget), Auto-seed forks to the lines autoseed on leaf stops
   (preview→commit with the persisted template), Skip Complete stops false-skipping
   undrawn leaves, and the redundant composite ⚡ Autoseed button hides on leaves
   (Clear stays — it deletes committed districts, which session undo cannot).
   ⚑ Residual (flagged, not built): the backend `incomplete_scopes` flag is equally
   leaf-blind — a "clean sweep" claim from OUTSIDE a leaf stop can still miss undrawn
   leaves; wants a backend follow-up.
5c. **Second field round (operator, 2026-07-04 evening — slices 5k-5o):**
   (5k) GHOST-LABEL 500: the F-ELB-008 handler numbers drawn-district labels by
   counting LIVE rows while the (map_id, label) unique index also covers
   SOFT-DELETED rows — after a clear/undo, the next commit collides with a ghost
   ("drawn district 1" already exists) and 500s. Fix: partial unique (WHERE
   deleted_at IS NULL) + collision-proof numbering. (5l) REPLACE FLOW: accepting
   an autoseed plan over an already-drawn scope only offered the Art. II §8
   overlap refusal; preview now reports existing_districts and commit accepts
   replace=true (retires the old rows in-transaction, same semantics as the
   delete endpoint). (5m) DRAWN-DISTRICT VISIBILITY: drawn districts were
   invisible to the sidebar list, the counters, and the PARENT scope's reveal
   layer (leaf-scope reveal worked); all three now treat subdivision districts
   as first-class, children rows show drawn-progress, and flags gains
   undrawn_leaf_giants (retiring the leaf-blind clean-sweep residual). (5n)
   POLYGON SNAP TOOLS: vertices snap (~12px) to the giant outline + existing
   district edges (Alt disables). (5o) FILL REMAINDER: one click stages
   giant-minus-drawn as the pending polygon through the normal probe/commit
   path — the last district never needs hand-tracing.

5d. **Third field round (operator, 2026-07-04 night — slices 5p-5s, parity with
   the composite system):** (5p) the drawn-district sidebar didn't refresh on
   commit until a full reload (a once-seeded ref not re-synced on Inertia partial
   reloads); (5q) drawn districts appear in ANCESTOR sidebar lists like composite
   children's districts do (same descendant reach as the reveal branches);
   (5r) drawn districts join the composite ADJACENCY-COLORING graph — operator's
   screenshot showed both Serravalle drawn districts wearing the neighboring
   composite district's orange (adjacency = geometry touching: drawn↔drawn share
   the cut, drawn↔composite share the giant's edge); (5s) polygon AUTO-CLIP —
   probes and filings trim the drawn polygon to the giant (proven clip+shave), so
   ✗outside becomes impossible by construction and the pending shape redraws
   trimmed; plus a diagnosis pass on why vertex snapping didn't engage on the
   operator's rig (leaflet-draw private-API wrap).

6. **Scope subtree clamp (5j)** — operator stepped the San Marino legislature's
   mapper up to scope=earth-0-earth: the scope resolver checks existence + the giant
   guard, but never subtree membership, and Earth passes the giant guard trivially —
   the page then runs Webster-share arithmetic on the whole planet ("7,677,127 seats
   to assign"). Fix: districts() clamps any scope outside the legislature's root
   subtree with a redirect to the root scope (map param preserved); the breadcrumb
   stops linking ancestors above the legislature root and the ↑ control stops at
   root.

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
