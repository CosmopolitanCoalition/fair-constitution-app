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

## Live deployment and compatibility correction

`8968410a` deployed at 19:41 UTC, Horizon only. All 23 court retries and the
one governance retry completed successfully. Bounded checks cover 24 appointed
courts/174 distinct-within-court judges with matching active terms, residency and
armed clocks. All 130 previously seated judges and their terms are unchanged.
The original failed delegation, tallies and casts remain unchanged; a separate
new act adopted. All 903,500 original stipend items remain DONE.

The changed supermajority method also changed the full constitutional fingerprint,
which caused remaining older elections to refuse certification. This was missed
in the initial test fixtures, which created elections under the current code.
The same run was halted/drained; failed election actions rolled back, preserving
their old counts. These new reviews are not lost world data or successful repairs.

The follow-up preserves both version identities and the normal mismatch guard.
Only the exact reviewed transition from `cv1.ac7230fe88c24e2fcd8f323f5e160b78`
to `cv1.35f8a64e89b09eda7046ff11a3d94f6c` permits certification of unchanged
general/special STV elections. A test reconstructs the entire old fingerprint by
removing only the approved two-member threshold branch and its comment, proving
all other hardened counting/apportionment/finalist code identical. Any different
source/destination, method or contest kind fails closed. Federation and legislative
vote compatibility are NOT relaxed. Original election pins remain unchanged;
certification audit payloads record both versions and the explicit compatibility.

`sim:repair --retry-compatible-elections=RUN --scope=UUID` recovers only the
precise rolled-back fingerprint refusal, under the existing halted/drained/source
and explicit recovery guards. Previous failed receipts remain in history;
unrelated failures and already-completed work are retained. Use at most 100
exact scopes per call. No inventory, Apply, payment replay or election repinning.

Combined validation: **66 tests / 2,242 assertions**. Includes real certification
refusal/rollback/retry, unchanged original version, repeat idempotence, unknown
version rejection, actual CLI, sealed-count and certified-officeholder recovery
with pre-D021 version pins, plus court/worker/threshold regressions. Fixtures are
guarded disposable PostgreSQL; no write tests ran on the live demo.

Deploy the follow-up under the same exclusive lock while halted, refresh Horizon
only, retry the exact compatible failures, resume the same repair. No migration,
frontend build, scheduler/PG/Redis restart or concurrency change.

## Follow-up deployed and checked

`faaae724` deployed at **20:00:32 UTC**. Exactly **2,319** fingerprint-refusal
items were requeued in batches of at most 100; four unrelated allocation reviews
were retained. Same run/version, Horizon only; configuration hashes and all other
service identities/start times/caps unchanged. The run resumed successfully.

At **20:03:28 UTC**: **920,989 DONE**, 2,029 pending, 73 running, four review;
73 fresh leases. Two sampled older elections now certify with their original
version retained. All 149 old count payloads across four sampled elections are
unchanged (the other two were still pending at inspection). Both bounded chain
tails passed 129 hashes/128 links; original stipend completion stays 903,500.
Public Step 5 returns HTTP 200. No throughput comparison is claimed.

Remote evidence: `evidence/D021` and `evidence/D021-COMPAT` beneath
`/home/cosmo/wos-step5-operations`. STATE.json contains the latest checkpoint.
The existing authorized heartbeat continues; world readiness is not yet claimed.
The remaining four original allocation failures are not repaired by compatibility.
