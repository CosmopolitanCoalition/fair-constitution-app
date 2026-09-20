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
and remain visible.

## Deployment and live acceptance

Deployed `1874b1ca13405a6af5736e29ee9d7c3b3c080a31` at 19:01:59 UTC.
The exclusive lock covered halt/drain, Horizon refresh, fast-forward, lint,
targeted retry and resume. Configuration hashes and every other inspected
service identity/start time/cap were unchanged. A pre-deployment read-only
check initially referenced `record_hash` instead of the ledger's `hash`;
it stopped before code changes and was corrected while the run stayed drained.

Of **97 scoped retries**, **76 now pass all repair acceptance**, **17 completed
their election recovery but exposed court shortages**, and **four remain blocked
by distinct-candidate shortages**. Seven unrelated reviews were retained.
All four originally diagnosed one-person contests now have one valid ballot,
quota one, one winner, a sealed complete count and a certified election.
Bounded audit and money tails each passed **129 hashes / 128 links**. All
**903,500 original stipend items remain DONE**; public Step 5 HTTP 200.

At **19:10:12 UTC / 21:10 Warsaw** the same run has **908,766 / 923,095 DONE**,
14,229 pending, 72 running, **28 review** and 73 fresh workers. This is not
whole-world acceptance. The independent Claude loop is unchanged.
Evidence: remote `evidence/D020/`; exact continuation in `STATE.json`.

## Concrete remaining cases

- **23 courts:** 38 unfilled seats all belong to constituents with fewer real
  people than their allocated quota (zero, one or two residents). Existing
  seated judges remain intact. The current equal-constituent and minimum-bench
  constraints prevent automatic completion. The operator was asked whether
  the population ceiling overrides those two requirements; no answer yet.
- **One executive:** its Type B chamber has two serving members and two yes
  votes, but the existing supermajority function requires three (`majority + 1`).
  The original failed vote is intact. The operator was asked whether unanimity
  should suffice for one/two-member chambers; larger thresholds unchanged.
  The unattended no-policy-change instruction requires a ruling first.
- **Four elections:** all available residents of a required footprint are
  already candidates or serving members elsewhere in that election/chamber.
  Two original elections are certified; two are open with existing sealed
  counts. Old candidate assignments, count hashes and terms are preserved.
  Do not retry unchanged failures or award a second seat to the same person.

These are source-supported remaining constraints, not permission to lower
thresholds, rewrite certifications or force verification success. Continue
normal pending repairs and bounded acceptance while decisions are outstanding.
