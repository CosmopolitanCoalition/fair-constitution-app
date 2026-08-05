# Type A Mapper — Cheap Equivalence Plan

**Scope:** the population-based Type A generator only — `SubdivisionAutoseedService` (shortest-splitline + template ladder), its primitives, and the mass sweep that drives it. Type B (`GraphPartitionPlanner` — present, parked per operator) and court maps are out of scope. **Reviewed at `670ed3d`.**

**The governing doctrine, named:** *candidates on the blob, geometry once for the winner.* This is the operator's manual-draw ruling — cut through the filled support, reconcile against the mask — promoted to the engine's own law. The engine already half-follows it (islands ride as super-pixels; the absorb sweep; the components template are all blob-family moves). This plan finishes the migration: every *ranking* decision moves to the pixel support; the full-resolution coastline is consulted exactly once per accepted cut.

---

## 1. Confirmations — what the operator asked about already exists

- **The ≤ceiling short-circuit is built.** `AutoscaleSizingJob` Phase B enumerates every legislature into `autoscale_items` with `kind = CASE WHEN child_count > 0 OR type_a_seats > ceiling THEN 'sweep' ELSE 'single' END` — an in-band childless leaf (S ≤ 9 by default) becomes a **set-based at-large district with zero mapper work**. No change needed; stated here so nobody rebuilds it.
- **Seat math is already cheap and correct**: `seatGroups(S, floor, ceiling)` and the composition ladder (k_min + up to 3 rungs) are integer arithmetic; k−1 cuts are known before the first blade — which matters for §5's visibility.
- **Determinism is load-bearing and stays**: fixed angle sets, exact prefix-sum offsets, total tie-breaks, `plan_hash` recompute-on-commit. Every change below is classified **Class A (byte-identical plans)** or **Class B (plan-version-bumped, A/B-verified)** against that law.

## 2. Where the hour actually goes — four sinks, with line evidence

**Sink 1 — the region is re-shipped, re-parsed, and re-validated per candidate.** `plan()` pulls the giant as `ST_AsGeoJSON(ST_MakeValid(geom), 15)` — precision 15 ≈ 35 bytes/coordinate; a coastal giant's region string runs to megabytes — and then `findBlade` executes, **for every surviving candidate**, `ST_Length(ST_Intersection(line, ST_MakeValid(ST_GeomFromGeoJSON(:gj))))` with that full string as the bind. Twenty to forty candidates per node × MB-scale text parse × `ST_MakeValid` on a 100k–500k-vertex coastline × (k−1) nodes × ladder retries. `splitRegion` / `splitRegionAbsorb` repeat the same parse+MakeValid per ranked attempt, and the recursion hands *children* down as GeoJSON strings, so every level re-serializes and re-parses its inheritance. This is the ETL parse-per-window crime, PHP↔PostGIS edition, and it is the dominant term.

**Sink 2 — `usort` per candidate.** `bladeOffsetSearch` rebuilds a projection array and full-sorts it — O(P log P) — for every angle: up to (24+48) × 2 absorb sweeps = 144 sorts of ~50k weighted pixels per node, in PHP.

**Sink 3 — doomed split attempts.** "Each side one polygon" is discovered by running the full `ST_Split` pipeline per ranked candidate; concave scopes burn `MAX_BISECTIONS_PER_NODE` × full-geometry splits learning what a flood fill would have said in a millisecond.

**Sink 4 — the sweep is one lane.** `MassReseedJob` is a single 2-hour Horizon job running `executeMassReseedSweep` serially over scopes; per-scope commits make it *resumable*, but a whole-Earth sweep is as slow as the sum of its giants.

## 3. Class A levers — byte-identical plans, no version bump

**A1 — Validate once; reference by handle (kills Sink 1).** At `plan()` entry, materialize `ST_MakeValid(geom)` once into a scratch row (`districting_scratch(plan_token, node_id, geom)` — UNLOGGED, dropped at plan end). Every subsequent statement references scratch **ids**: the length query becomes `ST_Length(ST_Intersection(blade, s.geom))` with a token join; `splitRegion` writes its two children back as scratch rows (`INSERT … RETURNING id`) and the recursion carries **ids, not strings** — geometry never crosses the PHP↔PG boundary again until the final plan payload (which drops to precision 6–7, ~11 cm, for the UI). Same validated geometry, same operations, same results ⇒ byte-identical `plan_hash`. This is the largest single lever and it is a refactor, not an algorithm change.

**A2 — Weighted median without the sort (kills Sink 2).** The offset `c` is the midpoint between the two projections straddling the target — findable in O(P): one pass for a coarse histogram (2,048 bins over the projection range), prefix-sum to the boundary bin, then an exact scan of only that bin's members for the true straddling pair. Reproduces the identical `c` and the identical strict-`t < c` recount ⇒ byte-identical. Expect 10–30× on the search loop; also hoist the per-angle projection array allocation.

**A3 — Sweep fan-out (kills Sink 4).** The pull-work-list pattern already exists in `autoscale_items`; the mixed-autoseed sweep should ride it: per-scope claimable items across the long-running supervisor's width instead of one serial job, simplest-first ordering already in place, per-scope commits already the resume grain. Plans are independent across scopes — this is embarrassingly parallel and touches orchestration only. (Watch PG headroom: each lane's plan does geometry work server-side; the long-running width of 2–4 is the right starting cap.)

## 4. Class B levers — version-bumped, A/B-gated

**B1 — Blob-length ranking.** After A1, the per-candidate cost is one `ST_Length(ST_Intersection(...))` against a big resident geometry — cheap-ish but still 20–40 real intersections per node. The doctrine's full form replaces the ranking measurement with the **chord length across the pixel support** (project the occupied cells onto the blade-normal; the in-support span is O(P) in PHP, zero PG calls) or against a once-simplified scratch geometry (`ST_SimplifyPreserveTopology` at half-cell tolerance). Near-ties can reorder ⇒ different winners ⇒ different plans: **Class B.** Arguably *truer* to the splitline spirit — short cuts should hug population compactness, not fjord perimeter.

**B2 — Grid contiguity before geometry (fixes Sink 3).** After the half-plane assignment, a 4-connected flood fill over ≤50k occupied cells (~ms, PHP) predicts "each side one polygon" almost perfectly; attempt grid-connected candidates first and fall back to the full sweep before any refusal. Because attempt *order* can change which candidate validates first, this is **Class B** — but it is precisely the concave-residue class (the absorb sweep's 67/71 cohort) where it pays: the flood fill knows in a millisecond what three full-geometry `ST_Split`s currently discover in minutes.

**The A/B harness both need:** replay every committed scope plus the run-6 review cohort under the candidate build. Class A ships only on 100% identical `plan_hash`. Class B ships behind a `plan_version` field with a diff report per divergent scope — cut count, per-seat deviation, hull ratios, blade lengths — reviewed like the drift census. Committed maps never silently change: `plan_version` is part of the hashed identity, so recompute-on-commit stays honest across the transition.

## 5. Paradigm fit for the sweep

Chunkable: scope = the chunk; within a scope, cuts are sequential by nature (each consumes the last's output) but there are k−1 of them and **k−1 is known before the first blade** — so *visible* means a per-scope bar of cuts-done/(k−1) with per-cut elapsed, not a spinner. Resumable: already per-scope commits; a deterministic replan after A1+A2 costs seconds, so mid-plan resume is unnecessary complexity — don't build it. Flexible: PHP single-proc per plan is fine once nodes are milliseconds; width is the Horizon dial. Fast: the levers above; the re-measure of filed pieces (full-resolution `populationWithin` per leaf) should route through per-tile sums — interior tiles as O(1) adds, boundary tiles only clipped — the same interior/boundary doctrine the ETL now runs on, and the mapper's copy of that machinery is where the geodata and districting systems finally share an artifact.

## 6. Rollout and the expected envelope

**Phase 1: A1 + A2** (byte-identical; the harness proves it). Expected: the hour-class giant drops to **low minutes** — Sink 1 was the hour. **Phase 2: A3**, sweep goes wide; whole-Earth reseed wall-time approaches its largest scope instead of its sum. **Phase 3: B1 + B2** behind `plan_version` 2 with the diff review; expected a further 2–5× concentrated exactly on the concave/absorb class that currently burns the blade budget. Instrument from day one: per-node candidate count, PG time share, blade-budget consumption — the numbers that tell you whether Phase 3 is worth its review cost, the same way `phase_timestamps` told us whether early attribution was.

One inheritance note for whoever builds this: the failure pattern to guard against is the one the ETL just spent a week paying for — *coordination metadata lagging a mechanism*. Here that means: the scratch table's lifetime, the `plan_version` field, and the harness land **with** A1, not after it.

## 7. Amendment (operator direction 2026-08-04) — maps become institution-agnostic artifacts

Type A maps stop being a legislature possession. The map registry gains a **consumer scope** — a map is keyed by (jurisdiction, consumer institution, purpose): the legislative chamber map is one row, and a subject-matter court's map is another, all consuming the same geodata artifacts (pixel grids, tile sums, adjacency). Per the operator's court rulings (see `INSTITUTION_SCALING_COURTS_ADDENDUM.md` §1): Type A is a **registered option axis for courts** — the elected/appointed-style choice — but **v1 builds no Type A court map**; base courts ship on Type B clumping only-where-needed. What this plan owes that future: the schema scope lands now (cheap, additive), and when a court charter first selects a Type A map, *everything in this document applies to it unchanged* — the scratch handle, the `plan_version` discipline, the A/B harness, the determinism law. A new consumer is a new row and a new purpose string, never a fork of the engine.
