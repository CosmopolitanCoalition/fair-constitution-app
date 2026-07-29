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

## ⚑ One coordination point for lane 3 (flag, do not pre-empt)

`ElectionStageTest::test_a_large_type_b_is_one_lawful_at_large_race` asserts that
a `type_a 14 / type_b 1141` chamber elects its Type B half as one at-large race
**today** — correct under the 2026-07-26 ruling (an at-large Type B is one STV
race at any size). But that fixture is over-bound (`type_b > type_a`), so if
lane 3's R-A guard classifies it `type_b_needs_districting` and blocks it, that
assertion inverts. **This is lane 3's call on `racePlan()` semantics**, not a
sim-engine change: whether an over-bound-but-undivided Type B is "lawful at any
size" (2026-07-26) or "blocked pending districting" (R-A) is exactly the line the
guard draws. When lane 3 lands the guard, that one pin moves with it. Flagged so
it is a coordinated update, never a surprise red in the suite.

Related: the console already renders these chambers honestly — see
`SimConsoleController::overBoundChambers()` (Niue 11/14, seated before the flag
reached the engine). The honest-absence rail and this deferral are the same
doctrine on two surfaces.
