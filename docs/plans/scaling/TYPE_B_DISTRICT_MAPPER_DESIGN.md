# Type B District Mapper — Wave 3 design brief (lane 1)

*Design reading for the paused districting campaign's resume point. Ruled R-A
(`docs/plans/ui/V3_SYNTHESIS_PLAN.md:228`): the Type B district mapper is lane
1's Wave 3 headline. Stage-two grouping for the ~9,708 flagged chambers;
unblocks Type B election playtesting (blocked fleet-wide until it ships). NO
code written here — this is the build-plan seed.*

## 1. What Type B districting must do (CLAUDE.md §Bicameral Support, lines 282–293)

Type B = **equal representation of the constituent jurisdictions; population is
irrelevant**. Total = `seats_per_constituent × number_of_constituents`
(`type_b_seats_per_child`, default 5), elected as **ONE at-large STV race**.
The only bound: **Type B may not exceed the Type A total**. When it does, reduce
in two stages, in this order:

1. **The ladder** — step `seats_per_constituent` 5 → 4 → 3 → 2 until the total
   fits. (San Marino: 9 castelli × 5 = 45 > 32; step to 3 → 27, fits.)
2. **Type B districting — ONLY if 2-per-constituent still overflows.** Clump
   whole constituent jurisdictions into shared panels: pairs, then triples, …
   until it fits. **Evenly** (every group the same count → representation stays
   equal) and **compactly** (nearest neighbours clump; adjacency matters).
   **No geometry is ever cut** — a balanced grouping over an adjacency graph,
   not a drawing operation. Populations are consulted at NO step.

## 2. Current state — stage one built, stage two absent

- **The ladder is fully built and pure-arithmetic**: `app/Services/Legislature/TypeBSeatLadder.php`
  `apportion()` (line 40). It descends 5→2 (`MIN_REP=2`) and, when even 2-per
  overflows, returns `needs_districting => true` (**line 59**) with the floored
  count — that is the exact point stage two must engage.
- **The flag is persisted on three paths that must stay byte-identical**:
  `ApportionmentSeedCommand.php:263/281/332/349`, `ActivationService.php:199/206`,
  and the **SQL mirror** `AutoscaleResizeRepairCommand.php:105`.
- **Storage**: `legislatures.type_b_needs_districting` (bool) +
  `type_b_rep_floor`, migration `2026_07_19_000002_autoscale_cycle2.php:31-38`,
  with partial index `legislatures_type_b_districting_idx` = the ready-made
  worklist. Count (~9,708) is **measured**, not stored: `TypeBBoundTest.php:28`
  pins `type_b > type_a IFF needs_districting`.
- **The R-A guard (shipped Wave 2, lane 3)**: `ElectionLifecycleService.php:616`
  blocks the Type B race kind while the flag is set. Clearing the flag makes the
  guard stop firing and line 676 emits the one at-large race — no downstream code.

## 3. The gap

A **balanced, compact grouping of whole sibling constituents over an adjacency
graph** that reduces the seat total under Type A and clears the flag. Nothing
today (a) reads the worklist and computes a grouping, (b) persists group→
constituent membership (**no schema exists** — the migration added only the two
scalar columns), or (c) recomputes `type_b_seats` from the grouping.

## 4. Reusable machinery

- **`jurisdiction_adjacency`** (migration `2026_07_19_000001:118-127`) keyed on
  `parent_id` — exactly the constituent set. `WHERE parent_id=<j> AND dim>=1`
  yields the sibling adjacency graph; `border_len` is a natural compactness
  weight. `AdjacencyPrecompute::isDone()` / live-fallback `writeBack()` cover
  materialization; `jurisdiction_centroids` gives a distance fallback for
  island/disconnected sets.
- **Graph primitives (adapt, don't extend in place)**: `GraphPartitionPlanner::components()`
  (line 201, deterministic connected-components) and `splitOnce()` (spanning-tree
  edge cut → two contiguous halves) are the right *shape* but are
  population/geometry-weighted — antithetical to Type B. Build a new
  count-balanced grouping; borrow the tree-cut idea only.
- **Downstream is done**: once the flag clears, `ElectionLifecycleService`
  `racePlan():676` + `createRaces():802-813` create the single at-large STV race.

## 5. Hook point

`TypeBSeatLadder` is deliberately pure (no DB/geometry), so the grouping cannot
live inside it. Attach at the two mass paths that call the ladder AND have DB
access, at the flag-raise point: `ApportionmentSeedCommand.php:263-281` and
`ActivationService.php:199-206`; keep the SQL mirror
(`AutoscaleResizeRepairCommand.php:105`) in lockstep or route it through the new
service. Recommended shape: a new `App\Services\Legislature\TypeBDistrictMapper`
both paths call — mirroring how both already share `TypeBSeatLadder`. **A new
persistence schema for group↔constituent membership is required.**

## 6. BINDING ANSWERS — operator, Wave 2 review (origin `6be3121`/`698419c`; via lane 7 Wave 3 order)

All seven gate questions are ruled. These are law for the build; do not re-derive.

- **B1 — ONE at-large race.** Panels are the seat-allocation UNIT inside the
  single race (confirmed; `seats = num_panels × rep_floor`).
- **B2 — remainders: STEP THE GROUP SIZE UP** to maintain equality (pairs →
  triples → …). If NO even partition exists at any size, the least remainder is
  borne by the **LOWEST-POPULATION jurisdiction(s)** — the one place population
  is consulted, and only for the residual, never for compactness.
- **B3 — zero-pop constituents are INERT** (zero seats, no government; not
  grouped). **NEW COMBINED CAP:** a jurisdiction's `type_a + type_b` TOTAL
  **floors down to its population** — never more representatives than people.
  ⚑ This binds `TypeBSeatLadder` itself — implement the combined cap there and
  **pin it** (a build item, not just the mapper).
- **B4 — ISLANDS (the operator's own method, "check me on it"):** take the
  disconnected jurisdiction's **WorldPop mask** → draw a smoothed imaginary
  border (**hull**) around all boundary pieces → run the simple line-drawing
  over that pseudo-contiguous shape (ocean/foreign pixels carry no population —
  they're not in the mask) → **CUT AWAY components outside the real boundary
  mask.** Also derive grouping adjacency from **hull-contact**. **VALIDATE
  technically before building** (headline risk): the enclave case (populated
  foreign territory inside the hull — should be excluded by the mask, confirm),
  degenerate hulls on far-flung archipelagos, and hull-contact adjacency.
- **B5 — tie-break must be MEANINGFUL, key TBD.** FIRST design step = the
  tie-break probe (§7) → an OPEN QUESTION to the desk/operator; the tie-break is
  late-binding, build the rest while it's in front of him.
- **B6 — NEVER cross-parent at ANY adm level.** Each peer branch is independent
  with respect to its own children (general doctrine — **pin it**).
- **B7 — new geographies take effect NEXT TERM.** Geodata changes REQUIRE
  redrawn maps (districts AND groupings); sitting members serve out. The
  recompute trigger fires on a geodata change but seats only at the next term.

## 7. First design step — the B5 tie-break probe (OPEN QUESTION, in flight to the operator)

When several EVEN groupings tie on the primary compactness metric, the
tie-break must be meaningful — not lowest-id. Population is OFF-LIMITS for the
grouping (CLAUDE.md: "populations are not consulted at any step"; B2 consults it
only for the residual). Candidate meaningful keys, each with a final
lowest-sorted-member-id fallback for determinism:
- **(a) max total internal shared-border length** — reuses `border_len` already
  in `jurisdiction_adjacency`; a direct extension of compactness; cheapest.
- **(b) minimax intra-panel diameter** — the most-stretched panel is as tight as
  possible; needs centroid/diameter compute.
- **(c) min cross-panel edges cut** — cleaner partition; needs edge-cut counting.

**Recommendation: (a).** Cheapest, reuses materialized data, deterministic,
"meaningful" (tightest-clumped panels). **SQL-mirror question:** grouping is a
graph algorithm, not SQL-expressible without reimplementing partitioning — the
`AutoscaleResizeRepairCommand` mirror should **defer to the PHP mapper service**
(the ladder stays SQL; the grouping calls out). Sent to the desk as the B5
OPEN QUESTION; the tie-break binds late.

## 8. Build order (post-compaction)

1. **Schema** — one additive REAL-dated migration for group↔constituent
   membership (the slot is EMPTY; signal the hash on landing).
2. **Combined cap (B3)** in `TypeBSeatLadder` + pin.
3. **Grouping engine** — hook at `TypeBSeatLadder:59`; `jurisdiction_adjacency`
   for contiguous sets, hull-derived adjacency (B4) for islands; B2 remainder
   rule; B6 within-parent only.
4. **Hull island method (B4)** — validate first (§6 B4 risks), then build.
5. **Un-flag** chambers as groupings land (lane 3's R-A guard un-blocks
   elections the instant `type_b_needs_districting` clears — the designed
   lifecycle).
6. **Niue** is the proving chamber (7 villages, seated over-bound; re-seat rides
   B7's next-term rule).
7. **Mass dry-run** over a sample of the ~9,708 — ETL rule (bounded committed
   chunks, per-chunk progress); DRIFT law throughout.
8. **Pins** for every ruling B1–B7.
9. Mapper UI rides the existing Step-3 / mapper surfaces.
