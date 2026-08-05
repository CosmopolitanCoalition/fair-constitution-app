# Institution Scaling — Courts, the Map Question, and the Type B Constitutional Options

**Addendum to `docs/plans/scaling/INSTITUTION_SCALING_PLAN.md` (lane 3).** Reviewed base `670ed3d`. This document does not restate the parent plan; it extends it with the operator's rulings of 2026-08-04 and the court-scaling design they imply. It travels with the amended Type A and Type B mapper plans — the three are one package.

**House rule applies:** every "exists/verified" claim below was read from the worktree; every "ruling" is operator direction awaiting wiring; anything marked "sketch" is explicitly not fully specified and must survive the adversarial-verification pass this repo's plans customarily receive before build.

---

## 1. Operator rulings (2026-08-04), captured

- **C1 — Equal representation binds the JUDGE POOL, not the map.** Art. IV §2's equal-per-constituent nomination sizes the bench. The composition of any court *district* map is a separate, free question.
- **C2 — Subject-matter courts may carry their own maps.** Different courts (by subject matter or otherwise) may each commission a map, all consuming the same geodata artifacts. Maps become institution-agnostic artifacts, not legislature possessions.
- **C3 — Base-court initial scaling: Map Type B, only where needed.** Just as elected/appointed is an option axis for executive and judiciary, Type A vs Type B mapping is an option axis for courts. v1 ships **Type B clumping only where needed** (the bench-side analogue of `type_b_needs_districting`); Type A for courts is a registered option, filed, not built.
- **C4 — Type B legislative seating is ON by default.** The current defaults (`type_b_seats_per_child` starting at 5) already express this; the ruling makes it explicit posture, not accident.
- **C5 — A Type B floor of 0 is attainable ONLY via constitutional amendment procedure.** Floor-0 means no Type B seats at all — a jurisdiction may choose pure Type A representation. This is a form of **disintermediation of seating**.
- **C6 — De-federalizing Type B seating is the seating precursor of full disintermediation.** When an intermediary dissolves (F-LEG-030), the records of all branches are sealed and clone-merged to the now-independent constituents. Constitutionally this is the mirror of `union` (making one polygon from several for their Union); the mechanism direction is §5. Operator flag preserved verbatim: *"I'm not sure if this mechanism is fully fleshed out"* — §5 states exactly how far the code already goes and what remains sketch.

## 2. What exists (verified against the worktree)

- **Disintermediation is substantially BUILT for laws.** `DisintermediationService` (F-LEG-030, Art. V §8): a `disintermediation` MJV at `BASIS_UNANIMITY` across constituents plus encompassing consent; on passage the intermediary's Acts are **incorporated into each former constituent as its OWN copy carrying full version history** (operator ruling 2026-07-28), constituents re-parent to the encompassing jurisdiction; process states `open → passed → merged / failed / expired`; `LawMergeResolution` handles collisions. The operator's "clone-merge" is, for Acts, running code.
- **`union` and `setting_amendment` are registered MJV kinds** — the unification mirror and the dual-door setting-amendment leg both exist as vote machinery.
- **The judiciary stub + Phase E structure design** (one `forming` judiciary per legislature-bearing jurisdiction via `InstitutionStubService`; ESM-18 lifecycle, two nomination modes, `judge_count = equal_per_constituent × constituent_count` for constituent-nominated benches) — see `PHASE_E_DESIGN_judiciary.md`.
- **The Type B machinery** (`TypeBSeatLadder` with hard `MIN_REP = 2`; `TypeBDistrictMapper::computePanels` — pure, deterministic, geometry-free; rulings B1–B7; versioned groupings) and **`AdjacencyPrecompute`** on the autoscale pull engine.
- **The provisioning engine**: `InstitutionProvisionService::STEPS`, set-based/chunked/idempotent, `ProvisionInstitutionsJob` off-request.
- **Gap noted for the implementer:** `type_b_seats_per_child` is a recognized, integer-cast constitutional setting, but no `SETTING_BOUNDS` entry for it was found in review — bounds (and therefore its amendment door) appear unwired. §4 depends on closing this.

## 3. Court scaling design (implements C1–C3)

**3.1 The bench ladder (pool side).** Constituent-nominated benches inherit the exact arithmetic that forced the legislative ladder: `equal_per_constituent × constituent_count` overflows any lawful bench bound at high fan-out. Reuse `TypeBSeatLadder` **parameterized by institution** — the bound becomes an argument (for legislatures: Type A and the B3 population cap; for judiciaries: the court's lawful bench bound from its creation act / `judge_count` ceiling and the same never-more-officials-than-people cap). Descend the per-constituent rep the same way; when even the ladder floor overflows, set the judiciary's `needs_districting` analogue — **the flag is the worklist**, exactly as on the legislative side.

**3.2 Court districts = Type B panels wearing robes (map side).** A flagged bench groups its constituents into **court panels** via `TypeBDistrictMapper::computePanels` — same inputs (populations, precomputed adjacency, centroids), same rulings B2–B6 verbatim (equal panels, remainder to the lowest-population end, inert zero-pop, island centroid fallback, max-internal-border tie-break, never cross-parent), same B7 versioning. Each panel's constituents share their panel's bench seats; nomination stays equal *within* the panel (C1 — the pool rule survives clumping intact). Storage: generalize the grouping trio with an institution scope (`scope_type` ∈ {legislature, judiciary, …} + `scope_id`) rather than a parallel table — one grouping engine, many consumers. Orchestration: a claimable kind on the autoscale engine beside `type_b_group`, **behind the same adjacency-done claim predicate** (the Type B plan §2.2 rule — precomputed, never fallback-derived, non-negotiable at mass scale).

**3.3 Maps as institution-agnostic artifacts (C2).** The map registry gains a consumer scope: a map is keyed by (jurisdiction, consumer institution, purpose) — the legislative Type A map is one row of a general table, a future subject-matter court's map is another, all reading the same geodata (pixel grids, tile sums, adjacency). **v1 builds no Type A court map** — the option axis is registered in settings/structure so a court's charter *can* one day select it, and when it does, the Type A plan's scratch-handle/`plan_version`/harness discipline applies unchanged to the new consumer.

**3.4 Provisioning integration.** Extend `InstitutionProvisionService::STEPS` (or the autoscale run ladder, whichever the parent plan's build log has made canonical by then) with: bench-ladder sizing at judiciary provisioning → flag where overflowed → court-panel grouping stage ordered after adjacency. Acceptance criteria mirror the legislative stage: zero flagged benches remain, undercounts enumerated (the hard cap makes undershoot lawful and reportable, never silent), per-item reasons on failure, two-ended est_cost = constituent count.

## 4. The Type B constitutional options (implements C4–C5)

**4.1 Default-on** is confirmed posture: the starting rep of 5 and the ladder as-shipped stand; no code change, one sentence of doctrine.

**4.2 Floor 0 by amendment only.** Today `MIN_REP = 2` is a class constant — floor-0 must arrive as *law*, not as a constant edit:

- Add the missing `SETTING_BOUNDS` entry for `type_b_seats_per_child` with the **standing legal band excluding 0** and 0 reachable **solely through the amendment door** — wire it as a dual-door key (`setting_amendment` MJV: chamber supermajority + constituent-consent leg), the same shape `judiciary_is_elected` already uses. Bounds changes touch PROTECTED files; say so in the commit.
- Ladder semantics at 0: `apportion()` returns `seats: 0`, no Type B chamber composition, `needs_districting` never set. Race side: the validator's hardened 5–9 chamber-race band (ConstitutionalValidator ~:640) must gain the arm "no Type B races exist when the setting is 0" rather than trip on a zero-seat race. Election lifecycle simply finds nothing to schedule. Existing chambers are untouched until a jurisdiction amends — byte-for-byte historical stability, the house law.
- Reversal: amending back up re-runs the ladder and, where needed, re-flags for grouping — the versioned-grouping machinery (B7) already handles "a fresh grouping while sitting members serve out."

## 5. Disintermediation linkage (implements C6) — part built, part sketch

**Built:** the constitutional crossing (unanimity + encompassing consent), Act incorporation as per-constituent independent copies with history, re-parenting, collision resolution. **The Type B connection needs no new mechanism at all:** on `merged`, the intermediary's chambers cease with it, and each constituent's own Type B question simply *recomputes against its new parent* on the next sizing pass — de-federalization of the intermediary's Type B seating is an automatic consequence of F-LEG-030, not an extra step. C5's floor-0 is the *voluntary, partial* form of the same movement — seating disintermediation without dissolving the jurisdiction — which is why the two belong in one doctrine.

**Sketch (flagged; not build-ready):** extending incorporation from *Acts* to **the sealed records of all branches**. The operator's intent: seal the intermediary's records (immutable snapshot at dissolution) and clone-merge the results to constituents, symmetric with `union`. Design questions the wiring must answer before this leaves sketch status — enumerate the record families and give each a disposition (**copy-per-constituent** like Acts, **seal-only** on the dissolved row, or **transfer**): chamber votes and proceedings; executive records and offices; judicial records — with the hard cases being **open court cases** (venue transfer to which constituent's court? the panel structure of §3.2 gives a natural answer where panels exist) and **sitting judges** (the B7 serve-out doctrine suggests seats close at term rather than at dissolution, but Art. IV needs to say so); audit-log scoping (jurisdiction-scoped entries are already immutable — sealing may be a no-op plus an index). And one symmetry check worth writing down: whatever disposition table this produces should read correctly **in reverse for `union`** — merging polygons should be the same table run backward, or the asymmetry should be justified in the doc. This section is the one place this addendum requests operator review before Claude Code builds.

## 6. Build order

1. §4.2 — the bounds entry + dual-door wiring + ladder/validator arms (small, self-contained, PROTECTED-file review).
2. §3.1–3.2 — institution-parameterized ladder + grouping scope + the court-panel claimable kind (rides the Type B plan's Stage T-B; build them together).
3. §3.3 — the map-registry consumer scope (schema now, Type A court consumers later).
4. §3.4 — provisioning wiring + acceptance criteria.
5. §5's sealed-records disposition table — **after** operator review of the sketch questions; the Type B recompute-on-reparent consequence needs no build at all beyond a sizing-pass trigger on parent change.
