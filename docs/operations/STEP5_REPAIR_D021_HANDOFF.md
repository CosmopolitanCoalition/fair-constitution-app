# D021: lawful court nominee scope and tiny-chamber unanimity

Operator September 20: retain court bench requirements and investigate multiple
roles; explicitly approve unanimity in one/two-member chambers. Existing failed
votes, certifications, terms and once-only payments remain historical records.

## Court finding corrects D020's diagnosis

D020 incorrectly treated local-resident shortages as necessarily requiring an
exception to equal-constituent quotas or the minimum bench. Reading both real
nomination paths disproved that inference: `JudicialNominationService::nominee`
and `JudicialSeatService::assertNomineeAssociation` require association with the
**court's jurisdiction**, not residence in the nominating constituent. Other
offices are not excluded. The simulator had applied a narrower selection rule.

The patch preserves every available preferred local nominee, then fills only
shortages from a bounded, ordered court-jurisdiction roster. Existing judges and
already-selected nominees are excluded. Each seat still has a distinct judge;
nomination quotas, bench minima, consent votes, terms and clocks are unchanged.
No population is invented and no multiple-seat exception is needed. Empty or
exhausted court-wide rosters still defer honestly. This also fixes fresh runs.

## Approved voting rule

For one/two serving members, the shared supermajority threshold is unanimity.
Three or more retain the exact configured fraction and majority-plus-one floor.
Zero serving members cannot adopt. Closed vote snapshots are never changed.
The failed delegation is retried as a **new** act through the normal vote engine.
This specific rule was explicitly approved by the operator; CLAUDE.md records it.

## Internal validation and continuation

**46 tests / 2,026 assertions passed**, including guarded disposable PostgreSQL
fixtures, actual court creation/nomination/consent/seating and partial recovery,
unchanged old judges/terms, unique judges and armed clocks, exhausted-roster
deferral, repeated delivery, bounded indexed roster probes, and actual new
delegation after an old failed vote. The old vote/tallies/casts remain identical.
Threshold tests cover four configured fractions across 3–300 members unchanged.

New scoped retry commands require the same drained, halted, authorized repair:

```
php artisan sim:repair --retry-court-rosters=RUN --scope=UUID
php artisan sim:repair --retry-tiny-governance=RUN --scope=UUID
```

Both preserve prior receipts, skip successful/unrelated work and verify concrete
eligibility. Court retries require enough distinct unused court residents;
governance retries require the old impossible tiny threshold and unanimous casts,
with unchanged serving counts. They do not replay inventory, Apply or payments.

Same repair `01a0bed7-d6c6-7399-b288-44053ffe00e1` halted/drained at 19:20 UTC
before its remaining work finished. Deploy under `developer-deployment.lock`,
refresh Horizon only, targeted retries, then resume. No migration, asset build,
PostgreSQL/Redis/scheduler refresh, concurrency change or new repair run.
Independent Claude storage/monitoring/shutdown work is untouched.

The four remaining election candidate-allocation failures are separate and are
not claimed fixed. No throughput benefit is claimed. Live acceptance pending.
