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

## 6. Open questions for the operator (resolve before building)

1. **One race or many?** Confirm the output stays ONE at-large STV race with
   `seats = num_groups × rep_floor` (panels are the constituent-unit), not one
   race per panel.
2. **Uneven counts / indivisible remainders** (Niue: 7 villages, pairs → 3+1).
   The rule when the constituent count doesn't divide the group size: one odd
   group, force last group ±1, or step to the next group size until an even
   partition exists? Must be deterministic.
3. **Do zero-population constituents participate?** Today `sumAt` gives a pop≤5
   constituent `min(pop, rep_floor)` seats, so empty villages seat 0. Do they
   join panels and count toward the even grouping, or stay unseated?
4. **Islands / disconnected adjacency** (the likely crux for most of the 9,708).
   Adjacency only has an edge on a shared border, so archipelagos are
   disconnected. Fall back to centroid distance, or group components
   deterministically-but-arbitrarily?
5. **Determinism / dual-path identity.** Both mass paths + the SQL mirror must
   mint identical groupings — confirm the tie-break (lowest jurisdiction id) and
   whether the SQL-mirror path reproduces grouping in SQL or defers to PHP.
6. **Cross-parent grouping** — confirm forbidden (grouping stays within one
   parent's children; `jurisdiction_adjacency.parent_id` scopes it naturally).
7. **Re-grouping stability.** Niue's already-seated over-bound chamber "stays
   seated and labeled; its re-seat rides the mapper" (R-A). Define the recompute
   trigger (constituent add/remove, pop-boundary shift) and the seat-continuity
   rule for sitting panel members.
