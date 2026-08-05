# Type B Mapper — Integration Plan and Cheap-Equivalence Review

**Scope:** the Type B system as built — `TypeBSeatLadder` (the 5→4→3→2 ladder with the B3 combined cap), `TypeBDistrictMapper` (panel grouping under rulings B1–B7), and the paths that invoke them. Reviewed at `670ed3d`. **Note for the implementer:** `GraphPartitionPlanner` is *not* Type B despite the name — it is a Type A last-resort primitive (spanning-tree cuts on the pixel graph, called by the cell seeder after the weight balancer refuses). Type B lives in `app/Services/Legislature/`.

**The headline, up front:** Type B's *compute* is already cheap, pure, and on the right side of every lesson this codebase has paid for — no geometry ever crosses the PHP↔PG boundary, no coastline is ever cut, `computePanels()` is a deterministic pure function over `(populations, adjacency, centroids)`. Its expensive input — sibling adjacency with shared-border lengths, once 30–180 s of live `ST_Intersection` per high-vertex pair — is **already solved** by `AdjacencyPrecompute` riding the autoscale pull engine as a claimable worklist. What Type B lacks is not speed. It is a *seat on the train*: the operator's diagnosis is confirmed exactly — **nothing in the acceptance flow runs it.**

---

## 1. The gap, verified

The autoscale run (dispatched from acceptance via `AutoscalePumpCommand` → `AutoscaleSizingJob`) computes and persists `type_b_seats` and sets `type_b_needs_districting` where even 2-per-constituent overflows the bound — and then stops. The only paths that ever *group* a flagged chamber:

- `POST /api/setup/wizard/step3/type-b-district` — the Step-3 dashboard button: a **serial `foreach` inside the HTTP request**, bounded at ≤500 per click, with failures swallowed into a bare counter (`catch (\Throwable) { $failures++; }` — no reason recorded anywhere).
- The `type-b:district` CLI, same service, same shape.

Meanwhile `ElectionLifecycleService` **blocks every Type B race** on the flag (correctly — the chamber has no lawful composition yet). So the end state of an accepted planet is: sized chambers, gated races, and a counter on a dashboard waiting for an operator to click through it 500 at a time. That is the integration debt, and it is orchestration debt, not algorithm debt.

## 2. Integration — Stage T-B in the autoscale run

Type B becomes a first-class stage of the same pull engine that already runs sizing, the sweep, and the adjacency precompute:

**2.1 New claimable kind `type_b_group`** in `autoscale_items` — one item per flagged legislature, enumerated after sizing (the flag rows *are* the worklist: `WHERE type_b_needs_districting AND deleted_at IS NULL`). Idempotent via the run-scoped `NOT EXISTS`, like every other kind.

**2.2 The one dependency rule — adjacency before grouping, expressed as a claim predicate, never as a runtime fallback.** `computePanels` needs the parent's rows from `jurisdiction_adjacency`; the read path's "exact fallback to live SQL" is correct for a single interactive call and *catastrophic* for a mass stage — a stampede of 30–180 s live derivations is the hidden hour-class cost of doing this integration wrong. So: a `type_b_group` item is **claimable only when its parent's `jurisdiction_adjacency_parents.status = 'done'`** (worklist status, not derived rows — the non-circular readiness idiom from the geodata work). Adjacency items and grouping items share the pile; an adjacency completion is what unblocks its groupings, and the two kinds pipeline naturally. If an item is claimed and the read path *still* has to fall back live (a coverage hole), that is a `review` with a named reason, not a silent 3-minute stall.

**2.3 Lanes, exactly as the operator specified:** `est_cost = child_count`, two-ended draining — half the lanes big→small, half small→big — the ordering machinery already present in the engine (the simplest-first key precedent). Grouping items are milliseconds-to-seconds each; the two-ended split matters less for throughput here than for the *tail shape*: the handful of thousand-child parents (the archipelago class) start immediately instead of last.

**2.4 Item execution = `apply()` as it exists**, one legislature per item, with two hardenings: (a) the failure reason lands in the item (`reason`), killing the swallowed-`Throwable` opacity; (b) `apply()`'s idempotency is already guaranteed by B7 versioning (draft → activate → archive prior), so the resume grain is one legislature and a `kill -9` costs one item. No new transaction semantics needed.

**2.5 Visibility:** the standard per-item bars, plus a stage chip reporting `grouped / flagged`, running `panels × rep_floor` seat tally, and the `undercount` count — the same numbers the dashboard already computes, now live during the run. Acceptance criterion for the stage: **zero flagged chambers remain**, every previously-gated Type B race schedules on the next lifecycle tick, and every undercount is enumerated (undercounts are *lawful* — the B3 cap is a hard ceiling — but they must be visible, never buried).

**2.6 The dashboard button survives** as the spot-repair tool — but it dispatches items into the engine instead of `foreach`-ing in the request. UI↔engine parity through one code path, the same rule the `type-b:district` CLI comment already states for UI↔CLI.

## 3. Cheap-equivalence review — where the costs actually are

**3.1 The algorithm is not the cost.** `computePanels` is a compact greedy walk (border-length-weighted nearest neighbour, B5; centroid jump for islands, B4; lowest-population start so the remainder lands low, B2) sliced into as-equal-as-possible runs. Complexity ≈ O(n·deg) with an O(n) scan per island jump — a thousand-child archipelago parent is ~10⁶–10⁷ PHP ops, well under a second. **Instrument before optimizing**: log per-item `child_count`, walk time, and jump count in the item metrics; only if the archipelago class measurably drags does the grid-bucketed nearest-centroid lookup earn its complexity. Do not build it speculatively.

**3.2 The input pipeline is the cost, and it is already paid** — provided §2.2's ordering holds. The remaining micro-lever: `apply()` currently issues separate queries for populations, adjacency, and centroids per legislature; fold them into one round trip per item. Milliseconds each, but ×48k parents it is the difference between a stage that is DB-chatty and one that is not.

**3.3 What NOT to do:** no geometry work belongs anywhere in this stage. Type B's entire design premise — "a balanced grouping over the constituent adjacency graph, never a cut through geometry" — is the blob doctrine in its purest form, arrived at independently. The moment someone proposes computing panel *shapes* or convex hulls during grouping, the answer is: the panels are ID lists; rendering is the map layer's problem, downstream, cached.

## 4. Class B (optional, version-gated, explicitly deferred)

The walk-then-slice is a *proxy* for B5's stated objective (max total internal shared-border): slicing a path can produce stringy panels a local swap would improve. A bounded, deterministic improvement pass (fixed-order boundary swaps between adjacent panels, accepted only when internal border length strictly increases, fixed iteration cap) would sharpen compactness — and it **changes groupings**, so it is exactly the `plan_version`-class change the Type A plan defines: gated behind a grouping-version field, A/B-replayed against every existing active grouping with a diff report. Recommend also adding a `group_hash` (inputs + version → hash) mirroring `plan_hash`, so recompute-on-activate carries the same receipt discipline Type A commits have. **File it; do not build it in this pass** — the operator's priority is integration and occupancy, and the v1 walk is pinned, shipped, and lawful.

## 5. Paradigm scorecard for the stage

Flexible/multithreaded: rides the existing engine's lanes, two-ended per §2.3 — a Pi runs it narrow, the game box runs it at width, same code. Chunkable: the legislature is the natural atom and it is already small; nothing needs sub-chunking. Resumable: one item per legislature, B7-versioned idempotency, kill costs one item. Visible: per-item bars + the stage chip with known-total progress (`flagged` is the denominator, known at enumerate). Fast: the compute was never slow; the stage's speed is the adjacency dependency being *precomputed and predicate-ordered* rather than fallback-derived — which is §2.2, the one place this plan can genuinely fail if built casually.

## 6. Build order

1. §2.1 + §2.2 + §2.4 — the kind, the claim predicate, the reason recording. This is the integration itself.
2. §2.5 + §2.6 — visibility and the button-to-dispatcher conversion.
3. §3.2 — the single-round-trip item load.
4. Run the acceptance flow end-to-end on the existing planet: sizing → adjacency → grouping, and verify the acceptance criteria in §2.5 — zero flagged, races scheduling, undercounts enumerated.
5. §4 stays filed until the operator asks for compactness quality; when he does, the harness comes with it, not after it.

## 7. Amendment (operator direction 2026-08-04) — generalization and the constitutional options

**7.1 The panel engine goes institution-generic.** `computePanels` gains no new logic — it gains new *callers*. Court benches under constituent nomination hit the identical overflow (`equal_per_constituent × constituent_count` versus a lawful bound), descend the identical ladder, and clump into **court panels** through this same pure function under rulings B2–B6 verbatim, with B7 versioning. Concretely: `TypeBSeatLadder` takes its bound as a parameter (legislature: Type A + the B3 cap; judiciary: the bench bound from the creation act + the same never-more-officials-than-people cap); the grouping trio gains an institution scope (`scope_type`/`scope_id`) instead of a parallel table; and the court-panel stage rides Stage T-B's machinery as a sibling claimable kind, **behind the same adjacency-done claim predicate** — §2.2 is non-negotiable for any consumer. Base courts ship **Type B only-where-needed** (operator ruling C3); details and build order in `INSTITUTION_SCALING_COURTS_ADDENDUM.md` §3.

**7.2 Default-on, and floor 0 by amendment only (rulings C4–C5).** Type B seating's current defaults (starting rep 5) are confirmed as deliberate posture. A jurisdiction may reach **floor 0 — no Type B seats at all, seating disintermediation — solely through constitutional amendment procedure**: wire `type_b_seats_per_child` into `SETTING_BOUNDS` (an entry is currently *missing* — verified) with the standing band excluding 0 and the 0 value reachable only through the dual-door `setting_amendment` path (`judiciary_is_elected`'s shape). At 0: `apportion()` returns zero seats, no flag is ever set, the race validator's 5–9 chamber band gains a "no Type B races exist" arm rather than tripping, and existing chambers stay byte-identical until their jurisdiction amends. Amending back up re-runs the ladder; B7 handles any re-grouping while sitting members serve out.

**7.3 The disintermediation consequence needs no mechanism.** When an intermediary dissolves under F-LEG-030, its chambers cease with it and each constituent's Type B question **recomputes against its new parent on the next sizing pass** — de-federalization of the dissolved layer's Type B seating is automatic, not an extra step. The one build item: parent-change triggers a sizing pass for the re-parented constituents. The broader sealed-records clone-merge is the addendum's §5 sketch, gated on operator review.
