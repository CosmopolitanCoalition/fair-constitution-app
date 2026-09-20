# D018: enforce the existing population ceiling in Type B panels

The operator confirmed on September 20 that zero population means zero government.
This corrects a grouped-panel allocation defect; it does not change voting rules.
Same repair run: `01a0bed7-d6c6-7399-b288-44053ffe00e1`.

## Change

- Fresh Type B mapping caps each panel's seats at its combined population after
  grouping. Territory, grouping priority and membership remain unchanged; unused
  seats are not redistributed.
- Step 5 candidate fielding also reconciles legacy open, uncounted panel races.
  Zero-seat races are soft-retired with their old advertised seats and candidacies
  retained. Panel/group/chamber targets and the existing quorum formula are updated
  atomically and audited. Positive tiny panels retain the frozen finalist multiplier.
- Certified elections, existing counts, serving members and unknown populations
  are protected. No votes or residents are invented, and no payment phase is replayed.
- Seating ignores soft-retired races, matching the real certification service.
- `sim:repair --retry-population-ceiling=RUN --scope=UUID` accepts 1–100 exact
  scopes on the drained, halted, authorized repair run. It requeues only blocked,
  rolled-back election failures with demonstrated cap defects. Prior failure
  receipts/metrics are retained; successful repairs and unrelated reviews stay intact.

The patch does not claim to resolve every review: population-one/zero-turnout
contests, already-certified shortages and separate court postconditions need their
own evidence. Unknown population is never treated as zero. No new policy approval
is pending for the proven zero-population defect.

## Validation and deployment

Targeted guarded disposable PostgreSQL and pure mapper regressions cover real
candidate fielding/counting/certification, zero/one/unknown populations, exact
historical candidacy/count preservation, rollback after adjustment, repeated calls,
refusal to rewrite certified/count history, scoped retry guards and worker paths.
**54 tests / 607 assertions passed** (SimElectionRecoveryTest,
SimRepairIntegrationTest, SimRepairWorkerTest, TypeBDistrictMapperTest).
Live deployment results will be recorded after validation.

PHP-only release: obtain the exclusive deployment lock, halt/drain the same run,
refresh Horizon, pull, retry inspected scopes, resume. No migration, frontend build,
scheduler/PostgreSQL/Redis restart, world restart, repeated inventory or Apply.
Keep the independent Claude loop unchanged. Validate actual target outcomes and
remaining reviews; no new throughput gain is claimed.
