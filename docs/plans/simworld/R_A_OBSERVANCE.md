# R-A observance in the simulated-world engine

*Lane 4, 2026-07-29. Verification, not a build — R-A's guard is lane 3's to write
in `racePlan()`. This records that the sim engine already DEFERS to that guard,
so it inherits the block for free the moment lane 3 lands it, and flags the one
coordination point.*

## The ruling (plan §10)

**R-A** — no Type B election playtesting on chambers flagged
`type_b_needs_districting` until lane 1's Type B district mapper ships. The
scheduling guard lives in **`racePlan()`** and is **lane 3's** to build. (Lane 1
builds the mapper itself; lane 3 the guard; this lane neither.)

## The finding: the sim engine has no independent Type B path

The populate pipeline's election → count → seat chain operates ONLY on the real
`election_races` rows that the real engine creates. It never constructs a race,
so it cannot construct a Type B race the guard would forbid:

- **`ElectionStage::run()`** ([app/Services/Demo/Stages/ElectionStage.php:58](../../../app/Services/Demo/Stages/ElectionStage.php)) —
  reads `ElectionLifecycleService::racePlan($legislature)`, records the kinds
  whose `mode === 'blocked'`, returns done-with-nothing when `fully_blocked`, and
  otherwise calls `scheduleGeneral()` — "the same method CLK-01 calls on a live
  instance." It writes candidacies only; it inserts **no** `election_races` row.
- **`CountingStage::run()`** ([app/Services/Demo/Stages/CountingStage.php:63](../../../app/Services/Demo/Stages/CountingStage.php)) —
  iterates `ElectionRace::where('election_id', …)`. No races for a blocked kind
  ⇒ nothing to count.
- **`SeatingStage::run()`** ([app/Services/Demo/Stages/SeatingStage.php:42](../../../app/Services/Demo/Stages/SeatingStage.php)) —
  files ONE real `F-ELB-004` through `ConstitutionalEngine`; `CertificationService`
  seats whoever the real races produced. Nothing to seat for a race that was
  never scheduled.
- **`SimPumpCommand::mintWorklist('counting')`** gates counting on
  `EXISTS (SELECT 1 FROM election_races …)`, so a chamber with a blocked half
  mints only the count items its lawful races justify.

**Conclusion.** When lane 3 makes `racePlan()` refuse the Type B half of a
flagged chamber, the sim engine schedules the lawful half, records the refused
half in `blocked_kinds`, and seats neither — with **no edit here**. The stage
defers; it does not fight the guard.

## Pinned

`ElectionStageTest::test_a_partially_blocked_chamber_elects_only_its_lawful_half`
proves the mechanism today with the block that CAN be triggered before lane 3's
work: an over-ceiling `type_a` (152) with no active map is a blocked district
half, a lawful at-large `type_b` (5) elects, and the stage schedules exactly the
one `type_b` race while recording the district half. When lane 3's guard swaps
which half is blocked, the identical deferral carries. This sits alongside the
existing `fully_blocked` and honest-absence pins — it is their sibling.

**THE UN-FLAG DIRECTION IS NOW PINNED (2026-07-29).** Lane 1's Type B mapper
cleared its first real chamber (Niue) via `TypeBDistrictMapper::apply()`, so the
symmetric pin is no longer synthetic:
`ElectionStageTest::test_the_sim_schedules_the_type_b_race_the_instant_the_mapper_unflags_it`
seeds a flagged Niue-shaped chamber, runs the **real** `apply()` (groups 8
children → 2 panels × 2 = 4 seats, recomputes `type_b_seats`, clears the flag),
then runs `ElectionStage::run()` and asserts the sim schedules the at-large
`type_b` race at its grouped seat count — with no edit to the stage. The
`before`-state assertion (`racePlan` returns `blocked` while flagged) makes it
non-vacuous: the race appears *because* the flag cleared. Companion to lane 1's
`TypeBDistrictMapperApplyTest`, which pins the mapper→`racePlan` flip at the plan
level; this pins the demo populate pipeline's side. The two deferral directions —
blocked and un-flagged — are now both nailed.

## ✓ One coordination point — RESOLVED 2026-07-29

`ElectionStageTest::test_a_large_type_b_is_one_lawful_at_large_race` asserts that
a `type_a 14 / type_b 1141` chamber elects its Type B half as one at-large race
**today** — correct under the 2026-07-26 ruling (an at-large Type B is one STV
race at any size). The Wave-2 flag was that if lane 3's R-A guard reclassified it
`type_b_needs_districting`, that assertion would invert.

**It did not, and the pin stays green.** The guard keys STRICTLY on the persisted
`type_b_needs_districting` column (racePlan L616 — `if ((bool) $legislature->type_b_needs_districting)`),
which the **ladder/mapper** sets, not `racePlan` itself. That fixture never sets
the flag, so the guard never fires on it and it remains a lawful at-large race.
Confirmed green in the same run that landed the un-flag pin (both live: lane 1's
mapper + guard shipped). No coordinated edit was needed — the column-keyed design
kept the two rulings (2026-07-26 "lawful at any size" vs R-A "blocked pending
districting") cleanly separated by whether the ladder flagged the chamber.

Related: the console already renders these chambers honestly — see
`SimConsoleController::overBoundChambers()` (Niue 11/14, seated before the flag
reached the engine). The honest-absence rail and this deferral are the same
doctrine on two surfaces.
