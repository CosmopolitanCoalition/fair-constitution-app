# D017: Type B vacancy detection during the existing repair

2026-09-20. This developer owns local fixes and direct SSH deployment under the
operator's explicit unattended Step 5 authorization. Same repair run:
`01a0bed7-d6c6-7399-b288-44053ffe00e1`; no new inventory or Apply.

## Observed defect and correction

At 17:00:34 UTC the heartbeat found 29 reviews. At 17:12:21, 735,347 repairs
were done and 66 were in review; the site and all 73 workers remained healthy.
Most sampled failures concern panels with assigned seats but zero resident
population/electorate. An operator decision on recording these seats as unfilled
is pending; this release does not alter their treatment, create residents, or
relax readiness and voting requirements.

A separate certified legislature has 15 Type A and 13 Type B members against
stored targets of 14 and 14. The aggregate reaches 28, so the inspector omitted
election recovery despite its own Type B shortfall. The missing seat is real:
one two-seat race elected only one candidate; its panel's two constituents have
populations of 216 and 120. Existing authorized special elections can fill it.

The inspector now schedules recovery when Type B is short even if the aggregate
serving count meets the legislature target. Original candidates, certifications,
terms and the existing special-election path are unchanged. Completed supplemental
elections do not keep re-triggering recovery from historical deficient counts.

The existing `--enable-election-recovery` control now respects repeated `--scope`
arguments, allowing only named reviews to be requeued while the run is drained
and halted. Other reviews, successful actions and receipts stay intact. Omitting
scopes preserves the existing command behavior.

## Validation and deployment

**34 tests / 453 assertions passed** in guarded disposable PostgreSQL databases:
SimElectionRecoveryTest, SimRepairIntegrationTest and SimRepairWorkerTest.
Coverage includes aggregate masking of Type B shortages, real special elections,
preserved certified members/terms, repeated recovery, isolated review requeueing,
and unchanged unrelated review rows.

Halt/drain the current run, pull the release, refresh Horizon only, requeue the
exact inspected scopes with the scoped control, and resume the same run. No
migration, frontend build, scheduler refresh, PostgreSQL or Redis restart.
Validate the individual retry outcomes before claiming success. This is a
correctness correction, not a claimed performance improvement.

Initial inspected retry scopes:

- `c81b06a6-ba56-4674-9cfb-45d3b4f31445`
- `511f28e6-c335-4caf-abec-0d6b6a9e6584`

Evidence before deployment is under the remote
`/home/cosmo/wos-step5-operations/evidence/D016-AWARD/`: the `heartbeat-1659`
and `incident-1717` snapshots (use timestamps inside the files), review samples,
and the exact panel/cohort/member/race probes. The original zero-population
rules and existing quorum remain binding while the operator decision is pending.
