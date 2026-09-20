# D020: tiny electorates and optional challengers

Live diagnosis of remaining repair reviews found one-resident Type B panels
whose 62% synthetic turnout rounded to zero, and exhausted candidate pools
requiring one additional challenger beyond the actual seats. Both prevent a
lawful one-person contest from reaching the existing counting/certification path.

- Fresh cohort generation uses whole-person minimum rounding for positive
  population and positive turnout, matching the district simulation. Zero
  population and explicit zero turnout remain zero. Legacy zero electorates
  are normalized only when the original floor calculation also yields zero;
  stored cohorts and completed tabulations are not rewritten.
- Candidate fielding fills every required seat before optional challengers
  when a known population cannot supply the preferred extra candidates.
  Distinctness, serving-member exclusion, real-population ceilings and actual
  seat targets are preserved. The voting/counting engine is unchanged.
- A halted, drained existing repair can use
  `sim:repair --retry-small-electorates=RUN --scope=UUID` (at most 100 exact
  scopes). It requires scoped tiny-panel evidence and a known rolled-back
  election exception. It preserves prior receipts and skips successful or
  unrelated repairs. It does not repeat inventory, Apply or payments.

**58 tests / 651 assertions passed** against guarded disposable PostgreSQL
fixtures and mapper regressions. Covered one-person real recovery and seating,
historical count preservation, repeat delivery, explicit zero turnout and zero
population, optional challengers across multiple races, serving exclusion,
rollback, retry guards and the worker path. A previous floor test now accepts
two genuine candidates for two seats while still asserting exactly two residents.

PHP-only: exclusive deployment lock; halt/drain the same run
`01a0bed7-d6c6-7399-b288-44053ffe00e1`, refresh Horizon, targeted retries, resume.
No migration, asset build, PostgreSQL/Redis/scheduler restart or concurrency
change. No speedup claim. Court/governance postcondition failures are separate
and remain visible. Remote acceptance results will be appended after deployment.
