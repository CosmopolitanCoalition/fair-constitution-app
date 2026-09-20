# Step 5 election lookup and progress polling — 2026-09-19

This is the deployment/measurement handoff for the demo-box Astra. The local
developer changes code and pushes it; the demo-box operator owns application of
these migrations and measurements on the running simulation.

## Operator communication protocol — 2026-09-20

This protocol continues across automatic context compaction and new turns.
Read it before any Step 5 handoff work. The operator relays complete handoffs
manually; do not send messages directly to the other task.

- **Develop and Commit** works in the Windows E: checkout: inspect the received
  recommendation, implement a justified Step 5 fix, complete internal tests,
  commit and push. It does not operate the remote demo server.
- **Deploy and Benchmark** works on the demo server: receive the completed
  handoff, deploy, finish the measurements, then send one consolidated result
  and next recommendation.
- Alternate strictly: recommendation → completed developer handoff → completed
  deployment/benchmark → next recommendation. Send no intermediate cross-task
  status, partial patch, speculative recommendation or duplicate prompt.
- Developer handoffs include the pushed revision, finished test results,
  deployment requirements and measurement guidance. A rejected recommendation
  must not be turned into fabricated work: establish the reason before returning
  one final disposition. An actual blocker is reported to the operator.
- Keep work within Step 5. Leave the separate Claude loop's disk expansion,
  resource monitoring and final server shutdown alone.
- After compaction, recover the current exchange from the latest messages and
  this file. Identify whose turn it is before acting. A drafted handoff is not
  proof of delivery; never resend or launch a second exchange just because
  context was compacted.

Current exchange: **D015 received; full repair already running on 96f840aa**.
The operator explicitly authorized supplemental elections for never-filled seats
and Earth's deficient-count recovery. The dominant chair audit bottleneck and
both recovery paths are addressed in [the completed D015 response](STEP5_REPAIR_D015_HANDOFF.md).
Continue `01a0bed7-d6c6-7399-b288-44053ffe00e1`, repair version 1. Halt/drain,
deploy, record the settled recovery choice and Resume. Do not repeat the already
completed D014 receipt correction/refresh/Apply or create another run.
The final developer response supplies the pushed revision and completed tests.
No direct cross-task messages or remote actions.
Older D010 performance results below are historical evidence, not repair results.

## D010 response: bounded atomic stipend batches — 2026-09-20

### Received evidence and baseline

The complete [D010 report](step5-benchmarks/D010.md) is committed. The duplicate
index retirement remains justified: six surviving indexes are healthy, sampled
payments reconcile, and throughput reached **397,433 scopes/hour**. Compared with
the final pre-migration minute, the observed improvement was **2.08–2.13×**. The
full baseline overlapped autovacuum; attributing its entire 2.45× ratio to the
migration would overstate the evidence.

The operator subsequently relayed a newer, unchanged-code benchmark ending
**09:43 UTC**: **504,728/hour over ten minutes**, **503,782/hour over five**, and
**520,042/hour over one**; full-item time approximately **521 ms**, 73 healthy
workers, zero review. At that endpoint 462,026/903,500 scopes were complete.
This increase predates batching and must not be credited to this patch. Capture
a fresh baseline immediately before deployment; neither earlier rate is a fixed
counterfactual for a changing live run.

### What changes

`SimWorkerJob` now claims **one to four Phase 10 scopes** in queue order using
`FOR UPDATE SKIP LOCKED`. The maximum falls with the worker's existing memory
headroom (8 MiB reserve per prepared scope, capped at four); the existing
25-wallet sample is unchanged, so a batch prepares at most 100 wallet entries.
There is no worker-count change or new parallel ledger writer.

`SimStipendBatch` prepares each jurisdiction's recipients, roles, settings and
amounts before acquiring the global ledger append lock. One owned transaction
then fences every item by run, token and running status, checks the worker lease,
acquires the existing append lock once, pays each scope, and writes all DONE
records and metrics before committing. LedgerService remains the sole ledger
writer. Its per-post lock calls reenter the same transaction lock; distinct
issuance/payment groups, receipts and canonical chain order remain intact.

- Ordinary `StipendService::run()` and direct single-scope calls retain their
  fresh-disbursement contract. Only the Step 5 worker uses atomic batches.
- Issuance authority still runs through the real service. Each scope retains its
  settings, role bumps, amount and funding source. Treasury-draw ratios are
  calculated under append ownership against the balance left by earlier scopes;
  zero-credit/short-paid results retain the original arithmetic.
- Payment and DONE commit together. A worker killed before commit leaves neither;
  a worker killed after commit leaves both. Reclaim, retry and lost-acknowledgement
  cleanup cannot reset a committed DONE row or pay it again. This closes the
  previous worker's payment-commit/DONE gap for newly processed items.
- A scope-specific failure rolls back the complete batch, marks only that owned
  failing scope for review, and releases the others for retry. Ownership loss,
  lock/transaction timeout or shutdown releases still-owned work without turning
  it into a review item. The worker exits after a transient batch failure instead
  of immediately spinning on it. Unreachable-database recovery leaves claims for
  the existing lease reclaimer.
- Live halt/pause checks reject new claims and stop prepared work before payment.
  An already executing batch finishes or rolls back as one bounded unit.
- The payment transaction has a **15-second maximum** (honoring a stricter
  existing timeout), plus statement and between-scope deadline checks. PostgreSQL
  17's transaction timeout is set before BEGIN and restored afterward; connection
  loss purges the broken session. No global PostgreSQL setting changes. See
  [PostgreSQL transaction timeout](https://www.postgresql.org/docs/17/runtime-config-client.html#GUC-TRANSACTION-TIMEOUT).

This does not combine jurisdictions into one anonymous payment, rewrite chain
history, change eligibility, or merge separate ledger posting meanings. The
existing queue retains one item and outcome per jurisdiction.

### Internal validation and actual reduction

**46 PHP tests / 2,162 assertions and 10 JavaScript tests passed.** The PHP total
is 42 real batching/payment/training tests (2,133 assertions), plus four
DB-free ledger writer/direction guards (29 assertions). PostgreSQL writes used
guarded disposable nonce databases. No local world or remote state was changed.

Coverage includes:

- Four, partial and final batches; real worker execution, queue ordering, live
  halt, empty eligible sample, memory cap, token takeover and concurrent batches
  from independently booted PHP processes.
- Minted and treasury-draw funding, distinct jurisdiction amounts/roles, depleted
  balances, exact fixed-ID ledger hashes/receipts/balances versus the original
  path, existing eligibility/authority and all training-ledger regressions.
- Failure after mint, wallet credit and receipt insertion; transaction timeout;
  failure immediately before commit and a lost commit acknowledgement; actual
  SIGKILL before/after commit followed by the real pump reclaimer. No duplicate
  payment after successful commit. Timer scopes close on failure.
- Timing display excludes old per-item and nested timings from batch averages
  and explicitly labels batches, including partial ones.

The four-scope fixture measured **four payment commits → one**, with **eight
nested savepoints → eight**. It does not add a per-scope stipend savepoint.
SQL calls increased **61 → 65** including ownership and timeout checks; this is
not a query-count reduction claim. The comparator includes ordinary payments
and separate item updates, excludes claims/lease traffic, and uses warm settings
on the same local fixture. Posting counts are unchanged. **No production batching
speedup is measured or promised**; commit amortization, fewer contended ownership
acquisitions and grouped DONE writes must justify their added checks on the box.

```text
docker exec -w /var/www/html -e RUN_SIM_INDEX_PG_TESTS=1 fc_app php vendor/bin/phpunit tests/Feature/StipendBatchTest.php tests/Feature/TrainingLedgerPerformanceTest.php
docker exec -w /var/www/html fc_app php vendor/bin/phpunit tests/Constitutional/LedgerIntegrityTest.php --filter 'test_ledger_service_is_the_only_writer|test_the_write_scan_flags_every_write_shape|test_the_write_scan_passes_every_lawful_shape|test_direction_constants_are_pinned'
docker exec -w /var/www/html fc_vite node --experimental-vm-modules --test tests/js/simWorkerActivity.test.mjs
```

### Completed deployment and measurement handoff

1. If Phase 10 is still active, capture fresh direct completion counts and payment
   workload at unchanged concurrency. Keep the newer ~505,000/hour observation
   separate from this immediate baseline.
2. Build the small timing-label frontend change in the established **isolated
   asset build** environment. Do not build on the loaded Windows development box.
   Request a bounded halt, drain leases/running items, pull the pushed revision,
   refresh **Horizon** and deploy the assets, then resume the same run. No migration,
   scheduler refresh, PostgreSQL restart, Redis recreation or sizing change.
   Preserve local configuration. Do not mix old and new stipend workers.
3. Existing DONE rows and all previous payments remain untouched. This patch does
   not identify or repay any historic paid-but-unsettled item. Draining old workers
   before refresh avoids introducing that old gap during deployment.
4. Exclude startup/drain intervals. Compare several settled windows using direct
   done scopes/hour, actual paid scopes/hour, wallets and ledger rows per scope,
   batch sizes, review/released claims and lease health. Reconcile a pre-captured
   bounded set of changed wallets, their receipts, disbursements, issuance groups
   and ledger hashes/links. Never replay paid scopes to obtain a benchmark.
5. New timer units below replace the old Phase 10 per-item counters; historical
   counters remain. Durations overlap and must not be summed. No straight
   comparison of an old per-scope mean against a new per-batch mean.
6. If Phase 10 finishes first, retain regression evidence and report the absence
   of a live batching comparison. Continue the already-established Phase 11
   assessment; do not reset the world. Leave the independent Claude loop alone.
   A code rollback likewise requires draining before refreshing workers; preserve
   all DONE and payment records.

| New counter/timer | Unit and interpretation |
|---|---|
| `lane.stipend_batch_total` | One worker batch iteration: refresh/reporting, claim, prepare, transaction through commit, timing flush when due. |
| `stipend_batch.claim`, `.between_claims` | One claim attempt / housekeeping interval. An empty final claim is possible. |
| `stage.stipend_batch` | One batch attempt, including preparation, transaction and recovery. |
| `stipend_batch.prepare`, `.transaction`, `.append_wait`, `.settle`, `.commit` | One batch; transaction includes append waiting, payments, DONE settlement and durable commit. Commit is the owned outer commit, never a savepoint release. |
| `stipend_batch.payment` | One scope execution inside the batch; excludes outer commit and preceding append wait. |
| `stipend_batch.ledger_lock_wait`, `.ledger_locked_post` | One ledger posting; lock calls reenter existing batch ownership. Main queue wait is `.append_wait`. |
| `stipend_batch.recipients`, `.office_holders`, `.settings`, `.wallet_balances`, `.receipts` | Existing nested operations, now with a distinct batch-path prefix. |
| `stipend_batch.scopes` | Zero-duration count of successfully committed DONE scopes, including eligible-empty skips. |
| `stipend_batch.paid_scopes`, `.wallets` | Zero-duration counts of scopes with positive wallet credits / credited wallet entries; excludes zero-credit draws. |

For per-scope or per-wallet cost, divide timer delta totals by the corresponding
committed-scope/wallet counts, not by unrelated nested invocation counts. Crash
windows can lose in-memory counters even when DONE/payment committed; use direct
completion counts for throughput and report batched counter-flush uncertainty.

## D009 response: retire redundant account-index writes — 2026-09-20

### Received result

The complete [D009 report](step5-benchmarks/D009.md) is committed for manual relay.
It measures `07fb6b0b` at **133,613 → 153,382 scopes/hour (+14.80%)**, with almost
identical ledger rows per scope. Primary-key buffer reads per new row fell
**99.80%**. Checks reconciled 412 wallets that actually changed, 30 disbursements,
412 receipts, distinct mint/payment groups, and sampled chain hashes/links.
Retain that patch. These are sequential live measurements with the limitations
stated in D009, not a promised speedup on every host.

D009 identifies two maintained account indexes sharing their leading keys. The
narrow index still incurred 0.709 buffer reads per new row after the UUID change.
This response removes that maintenance cost; **its throughput effect is not yet
measured**. The earlier +14.80% belongs to D008, not this migration.

### Reader audit and disposition

The audit covered tracked application, command, model, route, script, schema and
migration references to `ledger_entries`, `LedgerEntry` and both index names.

| Reader or contract | Finding |
|---|---|
| `PublicFinanceDirectory::ledger()` | Account history specifies type, account and currency, then pages by `seq`. The retained `(account_type, account_id, currency_id, seq)` index supports the filter and order. Currency-wide history keeps its separate currency/sequence index. |
| `LedgerService` head/chain verification | Uses sequence order; the sequence index is retained. Currency imbalance reporting has no account-index requirement. |
| `TreasuryDemoCommand` row count and `LedgerEntry` model | No dependency on the narrower account index or its name. The primary key and all constraints remain. |
| Administrative/reporting/import paths | No additional account-only ledger reader or index-name hint found in tracked runtime code. Private external SQL cannot be established by repository inspection. |
| Migrations/dependencies | The original non-unique two-key index is created by the ledger-plane migration. The four-key replacement was added on September 13. The new migration checks actual catalogs and refuses constraint, replica-identity, clustering or dependency surprises. Historical migrations remain unchanged. |

The 60,000-row PostgreSQL fixture includes 20,000 entries for a busy treasury,
20 for a sparse economic account, many unrelated accounts and two currencies.
Actual public-history results are identical. Account-only existence, ordered
pages, totals and cross-currency histories also retain exact results. Relevant
scoped plans remain indexed without disabling sequential scans or changing
planner settings. Ordered account/currency pages need no sort.

There is a real **read-space tradeoff**, not universal read-cost equivalence:

| Fixture query | Buffer accesses before → after |
|---|---:|
| Busy treasury, account/currency history page | 9 → 9 |
| Sparse account, account/currency history page | 6 → 6 |
| Account-only page ordered by currency/sequence, either account | 7 → 7 |
| Account existence, either account | 3 → 4 |
| Entire treasury's account-only totals by currency | 759 → 1,036 |
| Entire sparse account's totals | 7 → 7 |

The hypothetical whole-account treasury aggregation reads about 36.5% more
buffers because the wider index stores more keys. Its warm-fixture elapsed time
was 10.51 → 10.27 ms, which does **not** establish cold-cache latency equivalence.
No such aggregation exists in the audited application reader. Cross-currency
sequence history without a currency constraint is not covered in order by
either account index; its existing scan/sort costs remain. The sparse variant
used four versus seven buffers. No material regression was found in the actual
bounded public reader. Proceed for the application's measured write workload,
but benchmark any external account-only reporting before retaining the change
on a box that depends on it. Concurrent restoration is supplied for that case.

### Migration and recovery

`2026_09_20_090000_retire_duplicate_ledger_account_index.php` removes only
`public.ledger_entries_account_type_account_id_index`, using
`DROP INDEX CONCURRENTLY ... RESTRICT` outside a transaction. It keeps
`ledger_public_account_seek_idx` and the other five ledger indexes.

- Verifies the ordinary persistent table, exact key order, B-tree method,
  default sort/operator/collation behavior, no predicate/expression/INCLUDE,
  non-unique status and absence of protected uses/dependencies. A same-name
  unexpected definition fails closed. The replacement must be valid, ready and
  live even when retrying an already-completed retirement.
- One database-scoped advisory owner coordinates this migration's up/down
  operations, on one PDO session without transparent reconnect. It does not
  take the money-ledger append lock. Active ledger index builds cause refusal.
- Lock waits are capped at five seconds, honoring a stricter existing value.
  A finite caller `statement_timeout` is honored; otherwise execution defaults
  to 60 seconds for retirement and 15 minutes for restoration. These are
  interruption budgets, not memory or concurrency sizing. A larger host/table
  can supply a longer **finite** session budget for restoration. Original
  session limits are restored on success/failure; ownership is released.
- There is no automatic retry loop. After timeout, failure or process loss,
  inspect the catalog and retry the same operation once its blocker is gone.
  An interrupted concurrent drop/build may leave the expected index invalid;
  retry accepts only that exact definition and rechecks all guards. The valid
  wider index continues serving reads. Do not force a nonconcurrent drop.
- `down()` concurrently recreates the original non-unique index, including
  recovery from an interrupted build. It can restore it if the replacement
  has subsequently disappeared. A healthy existing old index is a no-op.
  Restoration scans the table and needs disk/CPU/I/O; it is not cost-free.

PostgreSQL documents the concurrent DDL transaction/locking restrictions in
[DROP INDEX](https://www.postgresql.org/docs/17/sql-dropindex.html) and
[CREATE INDEX](https://www.postgresql.org/docs/17/sql-createindex.html). Leading
key coverage is described in [Multicolumn Indexes](https://www.postgresql.org/docs/17/indexes-multicolumn.html).

### Internal validation

**44 tests / 2,365 assertions passed.** The final seven migration tests were
also rerun after refining their populated sparse-account probes and recovery
budget. PostgreSQL work used guarded nonce databases; the public-directory suite
used private in-memory SQLite. No application test wrote to the local world.

- Exact results/plans before and after retirement, six remaining indexes, and
  unchanged definitions for every surviving index.
- Missing/invalid replacement, wrong key order, partial/descending/INCLUDE/
  custom-opclass/collation definitions, unexpected unique index and real SQL
  function dependency all refuse retirement.
- Concurrent ownership, open transaction refusal, lock and execution timeouts,
  session-setting restoration, interrupted drop/build retries, idempotent
  up/down and restoration without the replacement.
- Existing real ledger/payment fixtures now apply the resulting **six-index**
  production set. UUID compatibility, independent concurrent writers, exact
  hashes/balances, append-only protections, payment/receipt totals and atomic
  failure/outer rollback checks pass. Payment runtime code is unchanged.
- Public page contracts/pagination pass. The fixture was brought up to date
  with the already-existing stipend-clock read; no product clock change.

```text
docker exec -w /var/www/html -e RUN_SIM_INDEX_PG_TESTS=1 fc_app php vendor/bin/phpunit tests/Feature/LedgerAccountIndexRetirementTest.php tests/Feature/TrainingLedgerPerformanceTest.php tests/Feature/Phase10PerformanceTest.php tests/Unit/PublicFinanceDirectoryTest.php
```

### Completed developer handoff

1. Capture fresh Phase 10 throughput, paid scopes/receipts, new ledger rows,
   payment timers and relevant buffer-read deltas at unchanged concurrency.
2. Pull the pushed revision and apply **only** the migration above using
   `php artisan migrate --force --path=database/migrations/2026_09_20_090000_retire_duplicate_ledger_account_index.php`
   in the app container. Use one deployment owner. The concurrent migration can
   run while the simulation continues. No halt, Horizon/scheduler refresh,
   frontend build, PostgreSQL restart, Redis or sizing change is required.
3. Verify only the narrow index disappeared and the wider index remains valid,
   ready and live. Exclude the DDL/wait interval from comparisons. Capture fresh
   public-history plans plus representative account-only external readers, if
   any, before/after. Do not claim an unseen reader cannot regress.
4. Compare several settled windows, normalize read counters by inserted ledger
   rows, and repeat bounded changed-wallet/receipt/hash/link checks. Timers
   overlap; do not add lock waiting to its enclosing payment time.
5. If rollback is needed and this isolated migration is the latest batch, use
   `php artisan migrate:rollback --force --path=database/migrations/2026_09_20_090000_retire_duplicate_ledger_account_index.php`.
   Check migration history first; do not roll back unrelated later changes.
   A failed recreation can be retried. Budget space and a finite execution
   window for its concurrent rebuild. If Phase 10 finishes, report that a live
   phase comparison is unavailable instead of replaying paid scopes.

The operator additionally asked whether transactions can be grouped across
jurisdictions, analogous to earlier court batching. The current minted payment
already groups its recipients and holds one outer transaction for mint plus
disbursement; the second lock acquisition is reentrant. A single hash chain
still needs ordered appends, but a bounded multi-scope append/commit may amortize
costs. **No cross-scope batching is included here.** Use the next completed
benchmark to assess the remaining posting, wallet and commit costs, plus
per-scope authority, receipt and retry/rollback requirements, before recommending
that change. Do not infer a large gain from removing a roughly 2 ms commit alone.

Return one completed deployment/benchmark and next bounded recommendation for
manual relay. Older D003/D004/D006 work and the independent Claude loop stay
separate. No remote actions or direct cross-task messages were performed here.

## D008 response: new ledger ID locality and Phase 10 payment timings — 2026-09-20

### Received evidence and interpretation

The complete [D008 report](step5-benchmarks/D008.md) is committed for manual relay.
It confirms all 914,453 Phase 9 items finished naturally before `c2ca186f` was
deployed. The new scoped lookup returned the same board IDs for 20 live scopes.
The scheduled statistics job correctly recorded `no_active_civics_run`, with no
ANALYZE attempt/success. **No live D007 Phase 9 code-speedup comparison exists.**

Phase 10's preferred absolute baseline was **124,332 scopes/hour**, 10,361 direct
completions over five minutes at 73 workers, zero review. It was still warming
up. About 99% of accumulated stage time was inside payment; 70–72 sessions
waited on the ledger append lock in the supplied snapshots. Most sampled owners
were waiting on page reads in ledger appends/treasury or wallet updates, rather
than COMMIT. Detailed training timers did not measure Phase 10.

The UUID primary-key index showed 51,043 buffer reads over 45.818 seconds in the
reported window. These are database-wide counters, **not physical-I/O bytes or
latency**, and other indexes also generated substantial reads. The specific
hypothesis is fewer primary-key leaf pages touched by newly appended rows. The
local developer claims no speedup factor or whole-phase completion estimate.

The operator subsequently supplied recorded Phases 1–9 totaling **16h47m**, with
Phase 10 projected at approximately **6h45m** before this patch. The older
53-minute Phase 10 estimate divided a 13-lane 4.9-hour figure by 73/13; it is not
a valid prediction for the serialized payment path. Comparing the 4.9-hour run
itself also requires matching paid-wallet/posting counts, sampling and storage/
cache conditions. More RAM/lanes alone do not establish that the locked section
became faster. Use D008's observed contention and fresh measured windows rather
than the simple lane-ratio projection to judge this change.

### Implemented compatibility and locality change

- Only the **new ledger row `id`** in `LedgerService::post()` changes from
  `Str::uuid()` to `Str::uuid7()`. Generation remains before the append lock.
  Existing ledger IDs and hashes are not rewritten. No migration or dependency
  change is required: the installed Laravel implementation delegates to pinned
  `ramsey/uuid` **4.9.2**, already present in the project.
- `entry_group` remains generated by `Str::uuid()` and stays in the canonical
  hash payload. Row `id` stays outside that payload. The head read still happens
  **after** acquiring the global transaction lock; `seq` still defines chain
  order. UUID order is not used to order appends, resolve authority or decide a
  payment's time. The two minted-stipend posting groups and outer durable
  transaction, wallet/receipt writes and all amounts/policy remain unchanged.
- PostgreSQL's native UUID column accepts mixed versions. The schema, known
  readers/references, validation and serialization paths were checked; no ledger
  UUIDv4-only contract was found. Public ledger history already paginates by
  `seq`, not UUID. A compatibility test uncovered an existing Eloquent defect:
  the read-only `LedgerEntry` model inherited an integer key cast. It now
  explicitly declares a nonincrementing string key, preserving complete IDs for
  **both** historical UUIDv4 and new UUIDv7 rows in model serialization.
- The installed generator combines time and randomness and increments within a
  process when the clock does not advance. Tests cover same-millisecond IDs,
  a simulated backward clock observation, independently booted concurrent
  appenders and PostgreSQL ordering/uniqueness. Cross-process clocks and lock
  acquisition can differ: **UUID order is not promised to equal global commit
  order**. Sequence order and chain hashes remain the correctness mechanism.
  See the library's [UUIDv7 documentation](https://uuid.ramsey.dev/en/stable/rfc4122/version7.html).

The primary-key writes should cluster in the UUIDv7 range among existing random
keys; this is not necessarily the far right of a mixed UUIDv4/v7 index. Random
entry-group IDs and account-index writes remain. Existing shared-buffer size,
indexes, concurrency, Redis and durability settings are untouched.

### New Phase 10 measurements

Only an open `stage.stipend_scope` enables the new `stipend.*` details. Existing
training diagnostics keep their original names; ordinary payments create none
of these Step 5 timing records. No diagnostic SQL is added to the payment path.

| Timer | Meaning and nesting |
|---|---|
| `stipend.ledger_lock_wait` | Each posting's advisory-lock acquisition. A normal minted payment has two invocations; the second is reentrant in its existing outer transaction. |
| `stipend.ledger_locked_post` | Head read, hashing, append and treasury update after acquiring the lock. Excludes later wallet/receipt writes and outer commit; it is not the complete lock lifetime. |
| `stipend.wallet_balances` | The existing grouped wallet-balance update. |
| `stipend.receipts` | Existing receipt-insert loop, including failure cleanup; no receipt-generation or payment behavior changes. |
| `stipend.commit` | Only a transaction owned by `StipendService`: spans closure exit, Laravel's committing/committed events and the database COMMIT. An outer caller's savepoint is not labelled a durable commit. Failures before commit do not create a commit sample. |
| Existing `stipend.payment` | Includes these nested costs and the owned commit. Do not sum it with its child timers. |

The commit timer measures the attempt, including framework event overhead. A
commit error can also contribute a timing sample; health/review evidence must
be reported alongside timing deltas. Batched timing flushes may represent a
different cohort from a short direct-completion window. Report counts and total
microseconds per completed scope as well as averages per invocation.

### Internal validation

**28 tests / 1,839 assertions passed** in guarded disposable PostgreSQL databases.
The fixtures use the real ledger migration and all seven production ledger index
definitions, including the two later public-history indexes. Coverage includes:

- 10,000 pre-existing random-UUID ledger rows followed by 500 UUIDv7 rows;
  unchanged historical samples/head, native UUID ordering, uniqueness,
  serialization and standard UUID validation, exact balances and full fixture
  chain verification. This is compatibility evidence, not a production-size
  I/O benchmark.
- Identical canonical hashes and links for fixed monetary payloads/entry groups
  with UUIDv4 versus UUIDv7 row IDs; independent concurrent new appenders waiting
  behind a UUIDv4 head; training groups/retakes and normal payment regressions.
- Append-only UPDATE/DELETE/TRUNCATE protections, conserved posting amounts,
  treasury/wallet/receipt totals, failure after mint/before credit, credit and
  receipt failure rollback, outer-caller rollback and unchanged repeated-scope
  disbursement behavior.
- Two ledger timer invocations per minted scope; wallet/receipt timers; owned
  commit observed at both transaction events; no false commit for an outer
  caller; timers close on failure and stay absent for ordinary payments.

```text
docker exec -w /var/www/html -e RUN_SIM_INDEX_PG_TESTS=1 fc_app php vendor/bin/phpunit tests/Feature/TrainingLedgerPerformanceTest.php tests/Feature/Phase10PerformanceTest.php
```

**Four database-free ledger source/direction tests / 29 assertions also passed**,
for **32 tests / 1,868 assertions total**. Do not run
the full `LedgerIntegrityTest` class on the demo: its other methods use a live
database helper. No local live-world writes, remote access or service-control
actions were performed by the developer.

### Deployment and benchmark handoff

1. Capture fresh Phase 10 windows immediately before deployment, keeping 73
   workers and direct completion counts. The early D008 warmup is not a steady
   pre-deployment baseline. Preserve the existing bounded correctness checks.
2. Use the established **bounded halt/drain, pull, Horizon refresh and resume**
   procedure on the same run. No migration, frontend build, scheduler refresh,
   PostgreSQL restart, Redis change or worker resizing is required.
3. After startup settles, compare several windows of direct completion rate,
   the new nested payment timings, and index buffer-read deltas. Separate all
   startup/drain windows and acknowledge that old code has no Phase 10 detailed
   timers. Do not attribute changing cache warmth or workload entirely to UUIDs.
4. Inspect a bounded tail for UUIDv7 row IDs, retained posting-group behavior,
   canonical hashes and adjacent links. Reconcile **wallets that actually changed**
   with captured balances and new account-ledger deltas, plus matching issuance,
   disbursement and receipts. D008's 272 unchanged wallets were not evidence of
   newly paid wallet deltas; do not carry that limitation forward as a pass.
5. If Phase 10 has finished, report that a live comparison is unavailable. No
   phase restart, paid-scope replay, index rebuild or historical-row rewrite is
   authorized to manufacture a benchmark. Retain the local regression evidence.

Return one completed measurement/disposition and next bounded recommendation.
D003/D004 candidates and D006's surviving population-scan rewrite stay separate;
the independent Claude storage/monitoring/shutdown loop remains untouched.

## D007 response: scoped CGC boards and unattended statistics — 2026-09-20

### Evidence and scope

[D007's operator-relayed evidence](step5-benchmarks/D007.md) records a manual
`ANALYZE boards (boardable_type)` on unchanged `5a78d6a1`: 199,635 → 701,215
scopes/hour, or 3.51×. This is a measured operational statistics correction,
**not this patch's measured speedup**. Fresh statistics relieved the immediate
problem before development. Audit serialization remains a separate bottleneck.

[D006](step5-benchmarks/D006.md), received during development, confirms D005's
guard reduced 25 population-sweep clients to one and preserved later scheduled
evaluations. Its initial matched windows changed 454,320 → 464,306 courts/hour
(+2.20%); a later ten-minute window was 485,832/hour. These sequential changes
do not establish a large attributable speedup. The surviving scan's source-work
pagination recommendation is **not implemented in D007**.

### Scoped query and timings

`SimBoardService::seatCgcGovernors()` first reads non-deleted CGC organization IDs
within the jurisdiction. It then probes boards using explicit IDs **and**
`boardable_type`, retaining non-deleted/not-dissolved board predicates. This
prevents a reorderable global boards/organizations join from choosing the
organization-board prefix before applying jurisdiction scope. No index is added.

The organization roster is keyset-paged using `HostCapacity::sweepChunk()` and
every eligible page is consumed. This is a batch size, not a roster cap.
`organizations.board_id` is never substituted for the boardable relationship:
NULL and stale pointers are covered, and inactive but non-deleted CGCs remain
eligible. Existing executive-first holder selection, deduplication, resident
sampling, vacancy-only seating, settings-resolved terms and CLK-09 effects stay
intact. One holder cursor spans all pages.

The former cross-board query had **no promised order**. The new traversal uses
organization ID, then board ID; seat order remains `seat_no`. On jurisdictions
with several CGCs this makes assignment stable across page boundaries, but it
does not promise to reproduce an incidental prior planner/heap order. Already
seated holders are retained. The multi-board regression asserts the complete
holder rotation, terms/clocks and no additional writes on a repeat run.

New nested timings are `civics.cgc_charter`, `civics.cgc_governors` and
`civics.cgc_board_lookup`. Lookup measures each read batch, not time paused while
seats are written. Charter and governors sit within `civics.cgcs`; lookup sits
within governors. Do not add these overlapping timers together, and account for
batch invocation counts when comparing per-scope costs.

### Automatic maintenance behavior

One scheduled job per minute uses the **existing `long-running` queue**; no new
supervisor or simulation lanes. It performs a cheap eligibility check. The only
reviewed target is **`boards (boardable_type)` during an active, unhalted `civics`
phase**. A delayed job rechecks the current phase and all eligibility conditions.
An empty/not-yet-populated phase cannot trigger an initial refresh.

Normal autovacuum/autoanalyze is retained without changing any global or table
settings. PostgreSQL's table controls are based on changed-row volume, whereas
D007 demonstrated a new category becoming significant before that trigger. The
check records the target's `reloptions`, effective global defaults and catalog
estimates for diagnosis. Changing the permanent table threshold alone would not
target this particular category transition. See the PostgreSQL 17 documentation
on [automatic analyze controls](https://www.postgresql.org/docs/17/runtime-config-autovacuum.html)
and [column-specific ANALYZE](https://www.postgresql.org/docs/17/sql-analyze.html).

The earlier Phase 6 evidence was inspected: D001's separate four-column
`ANALYZE audit_log (ref, event, actor_user_id, rejected)` restored the actor-index
lookup. That remains recorded in its existing section below. This patch does
**not** add audit-log maintenance or any speculative remaining-phase targets;
each additional target needs its own reviewed population/eligibility evidence.

| Decision | Bound and reason |
|---|---|
| Check cadence | At most once per minute after acquiring ownership; queued duplicates read the persistent cooldown. |
| Initial population | `max(50, min(1000, HostCapacity::sweepChunk()))` recorded civic-stage completions, plus that many catalog-estimated modifications and an actual organization-board existence probe. This scales the initial sample with the existing host batch rule rather than waiting for a fraction of a multi-million-row table. |
| Progress source | Existing `(run_id, part)` timer row. If timings are absent/disabled, at most the same bounded number of done-item IDs from the existing claim index; no full worklist/table count. |
| First refresh | Initial population exists but the board-type histogram still lacks `organizations`. |
| Later material change | At least the larger of five initial-population batches or 2% of estimated table rows changed, with a 15-minute interval from the latest table analysis. This gives normal autoanalyze room to act while addressing the observed 10% threshold gap. |
| Refresh/retry cooldown | Five minutes after an attempt; a fresh external analysis also suppresses immediate work. Checks that skip do not execute ANALYZE. |
| SQL budgets | Metadata statements: 3 seconds. Target table lock: NOWAIT; any later lock waits: 250 ms. ANALYZE execution: `clamp(120 / sqrt(max(1, hostGiB / 4)), 30, 120)` seconds, rounded up. Small hosts get more time, not wider concurrency. |
| Buffer bound | ANALYZE buffer ring is 1–8 MiB, limited by host memory/8 and PostgreSQL shared buffers/64. It does not change global work memory or planner choices. |
| Ownership | Nonblocking PostgreSQL session advisory lock `0x53494D53544154` per database; no expiring ownership TTL. Reconnect is fenced. |
| Other maintenance | Acquire `ShareUpdateExclusive` on this table with NOWAIT in the analyze transaction. This conflicts with concurrent ANALYZE/VACUUM/index maintenance while allowing ordinary DML. Contention records `deferred`, without blocking simulation progress. |

Column-only ANALYZE need not reset `n_mod_since_analyze`. Therefore the durable
state also records a baseline of total inserts/updates/deletes, relation OID and
database statistics-reset timestamp. Subsequent checks use changes since that
baseline (bounded by the ordinary modification estimate). Fresh observed
external analysis may establish a baseline **without** claiming a successful
application maintenance run. Counter resets or table replacement invalidate the
old baseline. This prevents repeatedly refreshing unchanged data after cooldown.

Two small, additive metadata tables keep operational evidence separate from
simulation outcomes:

- `sim_statistics_maintenance`: latest check/reason/evidence, cooldowns, baseline,
  last attempt and last **successfully recorded** refresh.
- `sim_statistics_attempts`: target, run/phase, reason, start/end, status, error
  and the evidence/budgets of each attempted refresh.

Exceptions/timeouts never create simulation review items. Only a committed,
successful refresh advances the success marker. A killed/lost worker leaves a
durable running attempt; the next owner marks it abandoned, honors cooldown and
may retry. If ANALYZE committed but the success record was interrupted, the
record remains conservative rather than claiming an unconfirmed success.

### Internal validation

**26 tests / 409 assertions passed.** The focused D007 suite uses guarded nonce
PostgreSQL databases, plus the existing private SQLite board-term and civic-stage
fixtures. It never writes to the local world or accesses the remote demo. Tests cover:

- Department-only statistics, then thousands of organization boards inserted
  without re-analysis. Actual scoped single-ID and multi-ID query plans probe
  both composite index keys and return only the requested boards.
- Exact old/new eligible board sets, no/multiple CGCs, deleted/unrelated/dissolved
  rows, inactive organizations, stale/NULL pointers, already-filled seats,
  continuous holder rotation across batches, repeat runs and real term writes.
- Actual maintenance recovering the stale join plan and leaving all fixture
  application rows unchanged; initial, unchanged and externally refreshed cases;
  material later changes and persistent cooldown after a fresh worker/session.
- Independent serialized-job duplicates, one owner, process kill and subsequent
  recovery, real SQL timeout, competing table lock, lost-session fencing, target
  restrictions, caller-transaction preservation and host budget derivation.

```text
docker exec -w /var/www/html -e RUN_SIM_INDEX_PG_TESTS=1 fc_app php vendor/bin/phpunit tests/Feature/CgcBoardLookupTest.php tests/Feature/SimulationStatisticsMaintenanceTest.php tests/Unit/SimBoardTermTest.php tests/Feature/Phase9PerformanceTest.php
```

### Deployment and honest comparison

1. If Phase 9 is still active, capture a fresh baseline using direct completion
   windows, unchanged worker concurrency and the existing bounded correctness
   samples. Do not reuse the stale-statistics window as a code baseline.
2. Use the established bounded halt/drain procedure. Pull the exact pushed
   revision; retain local configuration changes. Apply the single additive
   `2026_09_20_073000_create_sim_statistics_maintenance` migration. It creates only
   the two small metadata tables, with no scan or index build on application data.
3. Refresh **Horizon and scheduler**, preserving the established handling of old
   running jobs. Resume the same run. No frontend build, PostgreSQL restart,
   Redis recreation, worker-count change, statistics override or world reset.
4. Verify that the scheduled maintenance job is consumed by the existing
   long-running workers. Record its **actual outcome**, separately from the code
   deployment. Already-fresh statistics or a completed Phase 9 should cause a
   skip; do not deliberately stale production statistics to manufacture a gain.
5. Compare settled windows after worker startup; exclude any maintenance interval.
   Capture the new nested CGC timings, actual lookup plan and bounded board,
   holder, term/clock and audit checks. No additional live throughput gain is
   claimed by the local developer. If Phase 9 finished, retain local regression
   evidence and explicitly report that live Phase 9 comparison is unavailable.

Bounded operational evidence reads:

```sql
SELECT target, checked_at, reason, next_check_at, next_attempt_at,
       last_success_at, last_attempt_id, change_baseline, evidence
FROM sim_statistics_maintenance WHERE target = 'boards.boardable_type';

SELECT target, run_id, phase, reason, status, started_at, finished_at, error, evidence
FROM sim_statistics_attempts WHERE target = 'boards.boardable_type'
ORDER BY started_at DESC LIMIT 5;
```

The independent Claude storage/monitoring/shutdown loop is outside this work.

## D005 response: serialize background population sweeps — 2026-09-20

### Recorded handoffs

The operator-provided reports are committed under `step5-benchmarks/` so the
demo task can retrieve the complete evidence without a large clipboard relay.

| Report | Recorded outcome and disposition |
|---|---|
| [D003](step5-benchmarks/D003.md) | `1c254f92` deployed; bounded ledger and 104-wallet checks passed. Scopes/hour fell 3.97% while newly trained holders/hour rose 17.65% as the workload changed. Neither establishes an attributable code regression or definitive speedup. Retain the atomic training patch. Its preparation-before-lock recommendation remains historical, unimplemented in this pass. |
| [D004](step5-benchmarks/D004.md) | Phase 7 on `e8e0910c`: 485,446 jurisdictions/hour, 73 workers, zero review. Governor/term/clock and audit samples passed. There was no old-code Phase 7 window. The proposed per-nomination transaction remains historical, unimplemented in this pass. |
| [D005](step5-benchmarks/D005.md) | Phase 8: 488,088 courts/hour over six minutes, then 464,246/hour over four minutes, 73 workers, zero review. Court and audit samples passed. Twenty-five application population sweeps plus 32 PostgreSQL parallel workers repeated the same scan. This pass addresses that confirmed interference first. |

These source reports are historical observations, not proof of current remote
state. The local developer did not connect to the demo to reproduce them.

### Implemented and tested

Only `EvaluateCriticalPopulationJob` changes runtime behavior:

- Before scanning, try a PostgreSQL session advisory lock (`0x434c4b3036`,
  CLK06). One invocation owns it per database. A competing queued invocation
  returns immediately, without scanning, waiting for the lock or requeueing.
  This also covers previously serialized jobs once new workers execute them.
- The lock spans the existing sweep and its ordinary per-operation commits.
  It has no cache TTL that can expire underneath a slow query. `finally`
  releases it on the owning PDO; session termination releases it after a crash.
  There is no permanent cache uniqueness key or new dispatch throttle.
- A temporary reconnect fence makes session loss fail this invocation instead
  of silently continuing without its lock. The connection is disposed after
  the sweep, restoring ordinary connection behavior for subsequent jobs.
  Direct invocation inside a caller-owned transaction is rejected before
  acquiring the guard or changing that transaction.
- New jobs use the existing `long-running` queue, its `redis-long` retry
  configuration and a zero per-job timeout. The old `default` supervisor has a
  60-second timeout, inappropriate for the observed multi-minute sweep. No
  supervisor width or simulation-worker count changes.

The scheduler still requests periodic evaluations. The existing query,
candidate ordering, threshold resolution, liveness/deletion checks,
activation-state checks and memberless-jurisdiction guard are unchanged.
There is no global scan rewrite or new migration. One remaining scan can still
be expensive because its LIMIT follows eligibility filtering and aggregation;
measure that surviving workload after eliminating overlap before changing it.

**10 tests / 108 assertions passed**, using guarded disposable PostgreSQL
databases and the existing private SQLite autoboot fixtures. Independent child
processes verify one owner, three constructor-less serialized duplicates with
zero scans, lock survival across a commit, success/failure cleanup, actual
process-death recovery, a subsequent legitimate run, reconnect rejection and
recovery, and preservation of a caller's transaction. Candidate fixtures cover
deleted jurisdictions, inactive residents, any undeleted membership (including
vacated members), deleted members/legislatures, activation states and later
threshold crossings. The real threshold and crossing service is exercised;
the downstream boot entry point is spied rather than constructing a world.

```text
docker exec -w /var/www/html -e RUN_SIM_INDEX_PG_TESTS=1 fc_app php vendor/bin/phpunit tests/Feature/CriticalPopulationOverlapTest.php tests/Constitutional/CriticalPopulationAutobootTest.php
```

### Deployment: existing queued and running work

1. Capture fresh completed Phase 8 windows if still running. Use the established
   bounded simulation halt/drain and Horizon refresh procedure. Do not reuse
   D003's superseded shutdown sequence. Pull the exact pushed developer commit.
2. **Already-running old invocations do not gain this guard after a pull.**
   Retire the old Horizon processes through that deployment procedure and
   verify their matching CLK-06 scans have exited before evaluating the fix.
   A disconnected backend may still be completing its old scan. Any required
   cleanup belongs to the demo operator and must identify the specific old
   application sessions, not indiscriminately cancel other jobs or queries.
   Do not leave an old unguarded sweep running alongside a new guarded one.
3. Queued old payloads need no queue purge: the current handler guards them at
   execution. They retain their old queue/envelope settings until consumed.
   Later scheduled jobs use the existing long-running lane. Its normal retry
   interval is unchanged. New workers must consume both existing queues.
   There is no permanent pause of the default queue and no clock disabling.
4. No migration, asset build, scheduler configuration change, PostgreSQL
   restart, Redis recreation or sizing re-derive is needed. Keep the 73
   simulation workers, Redis settings and four local configuration edits.
   Leave the independent Claude storage/resource/shutdown loop untouched.
5. Resume the same run. Confirm at most one **client** executes CLK-06; that
   client's PostgreSQL parallel workers are not duplicate application sweeps.
   Confirm a later scheduled evaluation runs after the owner completes. Capture
   several steady direct-completion windows, full-item and claim time, audit
   waiting, oldest scan/snapshot age and review/lease health at unchanged
   concurrency. Retain bounded court/audit correctness checks. Report a completed
   comparison, or a phase-change limitation, through the operator once.

Local tests prove exclusion and recovery, not a production speedup. D004's
governor transaction recommendation and D005's secondary judicial nomination
transaction observation are not bundled into this interference fix.

## Remaining-phase preparation: phases 7 through 11 — 2026-09-20

These changes target repeated reads and unnecessary result transfer within
Step 5. Existing institutional writes, authority checks, voting rules, sample
sizes and monetary transaction boundaries remain intact. The later phases
already use saved, bounded queue-generation cursors; this pass does not replace
that work or alter worker counts. Local tests establish query reductions and
behavioral equivalence, not production throughput.

### Implemented changes

| Phase | Change | Local evidence |
|---|---|---|
| 7 Growing chambers | Combine the initial board-vacancy and fresh principal lookup; avoid checking that same vacancy twice; reuse one committee inventory for counts and names. | Two fewer reads per governable department, one fewer committee inventory read during growth. Principal departure between departments, refusal, idempotence and the real consent/adoption/term-clock path pass. |
| 8 Seating courts | Batch ordered resident-pool probes in host-derived chunks; use a court-scoped exclusion subquery; combine final seat counts. | A 120-pool fixture reduces 121 roster queries to one. PostgreSQL reads the exclusion once; indexed probes read at most three entries for two nominees. Exact nominee order, exclusions and deferrals pass. |
| 9 Modelling civic life | Count organization types in one scoped aggregate; fetch only the ordered candidate IDs consumed by the endorsement sample. | Up to four censuses become one. Candidate retrieval is capped at four for the current sample. Empty, small and 1,000-candidate fields retain the same endorsement sequence; top-ups and reruns pass. |
| 10 Paying the civic stipend | Select the existing 25-wallet sample first, restrict the holder lookup to those users, and prefetch the seven stipend settings on the service's actual resolver. | Enabled settings resolution falls from seven ancestor queries to one. Exact recipients/order, inherited overrides/defaults, role bumps, amounts, short-pay receipts and rollback pass with real payment services. Empty samples skip holder/settings/payment reads. |
| 11 Verifying the world | Group member counts for the selected legislatures; return organization/board verdict counts as one scalar row, using board-primary-key probes. | Exact verdicts/gaps, NULL/deleted/shared-board behavior, aspect skips and inactive-scope rules pass. A 200-chamber/3,000-organization fixture uses eight reads instead of 208. The plan uses scoped organization and board indexes with 10,000 unrelated rows present. |

Phase 8 uses the existing `residency_active_jurisdiction_user_idx` deployed
earlier. No additional index is justified by these fixtures. Phase 9's sampled
local plan already uses an election-leading candidacy index. Fixture query
counts are workload-specific, not a forecast of whole-phase speedup.

Phase 10 retains its existing recipient query and single outer payment
transaction. A bounded local planner check already used a jurisdiction-leading
resident index and indexed wallet bindings; no replacement index is included.
Ordinary stipend callers retain lazy settings reads. The Step 5 prefetch can
read unused settings for a disabled stipend, so the six-query saving applies
to the tested enabled path, not every outcome. The unchanged cold currency
provisioning path was stubbed in these fixtures; the real stipend, issuance,
wallet and ledger paths were exercised.

The Phase 11 fixture exposed an unsafe plan for an ordinary LEFT JOIN: the
planner hashed the whole boards table. The shipped query uses a correlated
scalar board-primary-key lookup within the scoped aggregate instead. Tests
verify that access path without adding an index or relying on session planner
settings. Member predicates and existing validation rules are preserved,
including how deleted/vacated member rows are counted; this performance pass
does not change verification policy.

### Completed internal validation

**40 tests / 881 assertions passed** in the combined phase 7–11 suite and the
existing governor-consent and board-term regression classes. PostgreSQL tests
create guarded nonce databases, verify both raw and Eloquent routing before
fixture writes, and remove only those databases. Other fixtures use private
SQLite memory databases. No tests wrote to the live development world or demo.

```text
docker exec -w /var/www/html -e RUN_SIM_INDEX_PG_TESTS=1 fc_app php vendor/bin/phpunit tests/Feature/Phase7PerformanceTest.php tests/Feature/Phase8PerformanceTest.php tests/Feature/Phase9PerformanceTest.php tests/Feature/Phase10PerformanceTest.php tests/Feature/Phase11PerformanceTest.php tests/Unit/SimDepartmentGovernorStageTest.php tests/Unit/SimBoardTermTest.php
```

These are focused regression and query-plan fixtures. They do not replay a
planet-scale run or establish a live phase speedup. Phase 7 also exercises the
real governor consent/adoption/term clock; Phase 8 mocks unchanged nomination
writes while checking selection and deferral; Phase 9 mocks unchanged board
orchestration and retains the existing real board-term tests. No full live
test suite was used as a gate.

### Measurement boundaries

- Phase 7 adds `gov.governor_nominate` and `gov.governor_consent`, nested in
  `gov.governors`.
- Phase 8 adds `judiciary.resident_pools`, `judiciary.stage_nominations`,
  `judiciary.slate_consent` and `judiciary.slate_seating`, nested in
  `judiciary.seat`.
- Phase 9 adds `civics.org_counts`; use it alongside existing board, bill,
  endorsement and whole-item timings.
- Phase 10 adds `stipend.currency`, `stipend.recipients`,
  `stipend.office_holders`, `stipend.settings` and `stipend.payment`, only while
  `stage.stipend_scope` is open. Payment includes its existing owned durable
  commit. These parts are nested in `stipend.disburse`, which now records
  failed attempts as well as successful ones. Separate failures when comparing
  windows. The new parts do not measure ledger lock wait independently.
- Phase 11 adds `verify.elections_read` and `verify.civics_read`, nested in
  `verify.scan`.

Keep these timers separate from whole-item throughput. Nested intervals cannot
be summed. The unchanged write paths can still dominate a phase after reads
improve; the new timers help identify that without weakening their guarantees.

### Deployment and comparison

This pass is worker PHP only. Under demo operator control, capture a steady
baseline if the relevant phase is running, halt and drain, pull the completed
developer revision, refresh drained Horizon workers, then resume the same run.
No new migration, asset build, scheduler refresh, PostgreSQL restart, Redis
recreation, sizing re-derive or simulation reset is required. Keep existing
worker concurrency, persistence settings and local configuration files intact.

Compare several settled windows of direct completed-item counts. Record the
phase, selected aspects, item counts, worker count, run population settings,
full-item time and the new stage parts. Run summaries and timing flushes are
batched; exclude startup and drain windows. A phase beginning after deployment
has no live before-window on this run: report its absolute measurements and
bounded correctness checks, rather than calling the earlier projection a
controlled baseline. Do not restart completed phases to recreate a benchmark.

Use a bounded sample of completed scopes to check the affected outputs:
governors/committee acts and terms (7), nominated/seated benches and deferrals
(8), organization top-ups and endorsement associations (9), selected wallet
credits/receipts and ledger links (10), and stored verdicts/gaps (11). Keep
review counts and worker liveness alongside throughput. If the remaining cost
is a shared write lock, provide its measured timing/owner evidence in the next
completed handoff; read-query reductions alone do not establish that cost fell.
The independent Claude storage and shutdown loop remains outside this work.

## D002 response: one atomic training-stipend group — 2026-09-20

### Completed demo evidence, supplied by the operator

D002 confirms deployment of `a77cf0b61e7ad920a4b7aa3b84947a414320ffff` on the
same Phase 6 run, with 73 workers. Weighted throughput was 442,212 scopes/hour
before versus 443,832 after (+0.37%): **no material throughput improvement**.
Both windows used the actor index for training-completion checks. The reported
513-hash/512-link tails and 91 pre-captured wallet reconciliations passed; no
review or sampled holder failures appeared. Retain the patch for its verified
query reduction and measurement, without claiming a production speedup.

The new timers attributed about 506 of 598 ms per scope to the two ledger lock
acquisitions. Four of six sampled lock owners were executing COMMIT (three
WALWrite, one WalSync); this confirms commit retains the lock, but the six
samples are not a measured time share. PostgreSQL, Redis, scheduler, worker
count and the four existing local configuration files were preserved.

### Implemented and internally tested

Only `TrainingStipendService::commitBatch` changes runtime behavior. Each existing
treasury/currency group now wraps its ordinary mint and bulk wallet disbursement
in one outer transaction. It still writes the distinct issuance and stipend
entry groups, the issuance event, and the same amounts and wallet credits.
Issuance authority checks and the once-only achievement gate are unchanged.
An exception after mint or during wallet payment now rolls back **both** postings
and the issuance record for that group, including its treasury/wallet changes.
Atomicity is per monetary group; the earlier per-person training filings remain
separate transactions, and this does not add a new payout-recovery mechanism.

The ledger service is unchanged: it calls the advisory lock twice and reads a
fresh head after each call. The second call is reentrant within the same outer
transaction, so it does not release and rejoin the contended queue. The group
performs one owned durable COMMIT rather than two. Other payment entry points,
durability settings, worker counts and Redis are untouched.

**Savepoint check and tradeoff:** the installed Laravel implementation creates
two service savepoints inside the group. Tests observe transaction levels
`[1, 2, 2]` and exactly one `TransactionCommitting` event (the framework emits
this immediately before its sole PDO commit). We retain those savepoints and
the services' exception semantics. They add two SQL statements; disbursement
preparation also now runs while the first posting's lock remains held. These
costs can offset some benefit from removing one durable commit. A live speedup
is not established by these internal tests.

Validation: **15 tests / 1,427 assertions passed** in guarded disposable local
PostgreSQL databases. The focused suite includes two concurrent training groups
waiting on a parent-held lock before the parent changes the head; noninterleaved
mint/disbursement sequences and exact chain hashes/balances; failure after mint
but before credit; wallet update failure; outer-caller rollback; mint/burn
accounting; the real training handler's retakes and repeated batch commits; and
the earlier chunking, ledger-writer concurrency and append-only regressions.
Commit-boundary event checks prove the new timers remain open both before and
after the actual owned commit. No live development-world or demo write tests.

### Deployment and completed-comparison guidance

1. Capture a fresh steady Phase 6 baseline if the phase is still running. Under
   demo operator control, halt and drain at item boundaries, pull the developer's
   exact pushed commit, refresh drained Horizon workers, and resume the same run.
   **No migration, frontend build, scheduler refresh, PostgreSQL restart, Redis
   recreation or sizing rederive is required.**
2. Keep 73 workers and Redis's temporary 2 GiB runtime cap / 870 MiB data limit,
   persistence and eviction unchanged. Compare multiple steady direct-completion
   windows against the fresh baseline, alongside full-item and training timings.
3. New timers (only while `stage.training_scope` is open):

   | Timer | Meaning |
   |---|---|
   | `training.stipend_group` | Whole group transaction, including its owned durable commit |
   | `training.stipend_commit` | Final owned commit/return interval after the group callback completes |

   `stipend_mint` and `stipend_credit` now measure **nested service calls**; their
   completion no longer includes an owned durable commit. Both remain nested in
   `stipend_group`, as do the existing ledger/wallet timers. Do not sum overlapping
   timings. There are still two lock timer samples per group, but the second
   acquisition is reentrant: compare total wait per completed scope, not just
   the per-invocation average. `ledger_locked_post` still excludes other work
   while the outer transaction holds the lock. The new commit timer is absent
   when a caller already owns a transaction; the normal Step 5 path owns its
   group transaction. Failed attempts can also contribute timing samples.
4. Retain bounded chain-tail and pre-captured wallet reconciliation checks,
   holder/lease health and review counts. Do not restart a finished phase to
   recreate a comparison. Report a completed comparison (or that limitation)
   and one next bounded Step 5 recommendation in the next operator-relayed
   handoff. Leave the independent Claude monitoring/storage/shutdown loop alone.

## D001 response: Phase 6 money-ledger contention — 2026-09-20

### Completed demo evidence, supplied by the operator

D001 reports the same run `01a0ba29-3dbb-7181-8654-2d41ce1dea86`, training,
with 73 workers and zero review items. Deployment of `5604e477` retained its
completed 914,453-item worklist. Training produces no world-counter deltas, so
that patch has **no demonstrated Phase 6 execution speedup**; generation was
already complete and its cursor change was not benchmarked in this phase.

Weighted throughput was 303,654 jurisdictions/hour before deployment, 172,287
after deployment with stale statistics, then 308,365 on the same code after a
separate targeted `ANALYZE audit_log (ref, event, actor_user_id, rejected)`.
The existing actor index then replaced the reference index for the actual
training-completion lookup. This is a statistics correction, not evidence of
counter-patch improvement or regression. Use the recovered baseline, and capture
a fresh pre-deployment window, for the next comparison.

**Empty-to-populated lesson:** statistics collected before a phase may badly
underestimate a new event/ref as that phase fills the table. A static fixture's
correct plan does not establish the live plan. Inspect bounded planner examples
and statistics if the lookup regresses; separate any targeted statistics
maintenance from code benchmark windows. No new audit index or automatic
whole-table maintenance is included in this patch.

The measured remaining wait is on money-ledger key `0x4c45444752` (64 waiting
sessions in one snapshot), not the audit-chain key. Post-statistics audit wait
was approximately 0.14 ms, while training took approximately 808 ms per scope.

### Implemented and internally tested

- `LedgerService::post` prepares canonical payloads, row UUIDs and net treasury
  deltas before acquiring its append lock. Amount normalization and chain-hash
  inputs are unchanged. The head is still read in a separate statement **after**
  acquiring the transaction-scoped lock, so waiting writers see the committed
  head. Only head-dependent chaining remains inside that section.
- For a posting touching one treasury (both training mint and disbursement),
  the final bounded insert chunk and its treasury update share one PostgreSQL
  data-modifying CTE. This removes one database round trip per posting. Multiple
  treasuries retain the existing update path. Inserts remain at most 500 rows per
  chunk. No lock, durability, policy or transaction boundary is relaxed.
- Narrow timers distinguish append-lock waiting, the remainder of `post`, wallet
  updates, and complete mint/disbursement calls. They run only while a simulation
  `stage.training_scope` timer is open, not on ordinary user payments.

Validation: **19 tests / 1,329 assertions passed**: 10 disposable-PostgreSQL tests
(1,288 assertions) and 9 existing database-free ledger integrity tests (41).
These cover exact canonical hashes, posting order, amounts and balances; a
502-row posting across chunks; mint/burn supply; outer rollback and failures in
treasury/wallet updates; two independent concurrent writers actually waiting on
the lock; append-only database guards; single-writer/validation rules; and the
real training handler plus achievement and stipend services paying only once
across retakes and repeated batch commits. The ordinary batch credit now issues
four SQL statements instead of five. No development-world or remote data was
used as a write fixture. This proves the query reduction and checked invariants,
**not a production throughput gain**.

### Deploy and benchmark this completed patch

1. Capture a fresh Phase 6 baseline if still available. Under demo operator
   control, halt and drain at item boundaries, pull the exact commit from the
   developer's final response, refresh drained Horizon workers, and resume the
   same run. This changes worker PHP only: **no migration, frontend build,
   scheduler refresh, PostgreSQL restart or Redis recreation is required**.
2. Keep 73 workers and the current Redis settings unchanged: temporary 2 GiB
   runtime cap, 870 MiB data limit, same persistence/eviction. The installer
   sizing correction remains separate and is not part of this benchmark.
3. Compare multiple steady direct-completion windows and `lane.item_total`,
   `stage.training_scope` / `training.arm`, plus these new timer deltas:

   | Timer | Meaning |
   |---|---|
   | `training.ledger_lock_wait` | Advisory-lock acquisition per ledger posting |
   | `training.ledger_locked_post` | After acquisition through head read, chained inserts and treasury update |
   | `training.wallet_balances` | Bulk wallet updates after the disbursement posting |
   | `training.stipend_mint` | Full mint call, including issuer check, ledger posting, issuance event and owned transaction commit |
   | `training.stipend_credit` | Full bulk disbursement, including posting, wallets and owned transaction commit |

   `ledger_locked_post` is **not the full lifetime of the transaction lock**:
   wallet/issuance work and transaction commit can follow it. These scopes are
   nested; do not sum them. Counts are per call/posting, not per jurisdiction;
   compare total-microsecond deltas per completed scope when attributing costs.
   Workers flush timing accumulators in batches, so single tiny windows mislead.
4. Use bounded samples of newly completed scopes and newly appended ledger
   links, plus sampled issuance/wallet deltas where a before snapshot exists.
   Confirm healthy leases and zero new review/failed-holder results. Do not run
   a planet-wide ledger verification for this benchmark. If Phase 6 has ended,
   do not reset the world to recreate it; report that limitation.
5. Finish the comparison and return **one consolidated result and next bounded
   Step 5 recommendation**, relayed by the operator. Leave the separate Claude
   monitoring/storage/shutdown loop alone.

## Counter contention and persistent Redis sizing — 2026-09-20

The operator reports `e9fc8ade` deployed, while `0dfba8f6` remains undeployed.
The 219 Redis-related review items recovered successfully. Heartbeat-only writes
fell about 66%, but no throughput gain was established. Comparable slowdowns
preceded the heartbeat deployment (662–679k/hour at 21:58–21:59 versus deployment
at 22:09). The 897k/hour versus 604–641k/hour comparison does not isolate a patch
regression. Observed waits on the shared `sim_runs` counter row explain time
outside the seating timer; audit contention remains another serialized cost.

### Application change and benchmark deployment

`SimWorldCounters` stores each worker's deltas in a separate durable PostgreSQL
row. A DONE settlement and its delta commit together. Every 25 items, on worker
exit and before a phase-ending pump kick, the worker merges its row into the
shared run counters. Merge plus deletion is one transaction. A crash or failed
merge leaves committed deltas for a later pump; phase advancement waits until
they are merged. The pump reads only host-bounded batches. Existing run counters
are the retained baseline; no historical recount or reset is required.

New timings are `lane.item_total` (acquisition through settlement and periodic
flush work), `lane.settle` and `lane.counter_flush`. Merge averages are per merge,
not per item. These timings contain nested work and must not be summed. The
existing `stage.*` timer still excludes settlement. A worker also recycles at the
smaller of its existing 480 MiB bound and the host-derived Horizon heavy-worker
limit, rather than exceeding the memory budget on small hosts.

For a controlled comparison, **keep the demo's current Redis settings unchanged**:

1. Halt and drain under operator control; pull the reviewed revision. This also
   includes the still-undeployed `0dfba8f6` queue-generation change.
2. Apply only the additive counter table migration:

   ```bash
   docker compose exec -T app php artisan migrate --force --isolated=1 --path=database/migrations/2026_09_20_003000_create_sim_world_counter_deltas.php
   ```

3. Refresh drained Horizon and scheduler processes, then resume the same run.
   No frontend build, PostgreSQL restart, Redis recreation or sizing re-derive
   is needed for this application benchmark. Without the migration, workers
   retain the old immediate-counter fallback.
4. Compare several direct settled-item throughput windows at unchanged worker
   count and Redis settings. Include the new full-item timer, counter-flush
   frequency, audit waiting and worker recycling. Verify pending deltas drain
   on halt and compare bounded completed-item samples. A migration rollback
   refuses to discard pending deltas; merge them before reverting the code.

Validation: 52 focused PHP tests / 1,015 assertions passed. Fifty settlements
produce two shared-counter updates in the fixture. Tests cover all counter
types, reviewed items, retained baselines, atomic settlement rollback, interrupted
merge/retry, orphan recovery without leases, overlapping mergers, legacy fallback,
memory-derived recycling, and existing worker/pump/heartbeat regressions. No
production throughput gain is claimed before remote measurement.

### Durable sizing change (apply separately)

Both installers now allocate persistent queue Redis with a 480 MiB minimum need
and 2 GiB ceiling inside the existing closed host budget. Its data limit is 40%
of its allocated cap: another 40% budgets dirty copy-on-write pages, leaving 20%
for process/buffer overhead. Thus a funded minimum holds 192 MiB of data; a
2 GiB allocation derives 819 MiB. This is a budgeting policy, not a guarantee
against every possible workload. Redis documents the fork/copy-on-write behavior
for [snapshots and AOF rewrites](https://redis.io/docs/latest/operate/oss_and_stack/management/persistence/).
Persistence and eviction settings are unchanged. The open profile retains its
existing queue data limit; explicit operator pins remain respected.

PowerShell now re-detects automatic geodata/mapping profiles on explicit
re-derive, as Bash does. Bash's printed re-derive instructions now cover every
changed service cap. Re-derive deliberately writes configuration only; it does
not itself apply caps to running containers. During separate maintenance, use
the existing re-derive workflow, inspect the resulting ledger and recreate all
affected services. Check current Redis memory before reducing its data limit.
Do not replace the demonstrated live 2 GiB cap with the old 1 GiB configuration.

The Linux boot reconciliation implementation already exists in `get-started.sh`.
A missing installed unit is a deployment gap, not missing source code. The
operator can use `./get-started.sh --install-boot-unit` on that Linux box; no unit
was installed or remote setting changed by the developer.

Both sizing suites passed 36 host/profile cases each, plus PowerShell automatic
profile, open-profile and hand-pin checks. The Linux sweep verifies aggregate
budget, minimum needs, positive/monotonic caps, lane funding and Redis headroom.
Hosts below the service set's resident minimum continue to report that condition.

## Look-ahead: bounded queue generation for phases 6–11

The later generators still searched past prior output on every batch. Training
and chamber growth also deduplicated the full seated-election roster before the
outer LIMIT. `SimPumpCommand` now shares the existing counting cursor loop with
all six remaining phases. It reads a host-sized source batch through the existing
`(run_id, kind, unit_key)` unique index, then materializes only that batch for
eligibility checks and joins. Inserts and cursor advancement commit together;
zero eligible rows or duplicate-only batches still advance the source cursor.

| Phase | Source roster | Eligibility retained |
| --- | --- | --- |
| 6 Training | Seating items | Done items with an election; one task per jurisdiction |
| 7 Growing chambers | Seating items | Same one-per-jurisdiction rule |
| 8 Seating courts | Chamber-growth items | Done upstream items |
| 9 Civic life | Court-seating items | Done upstream items |
| 10 Civic stipend | Identity items | Done items in leaves; live children exclude a parent |
| 11 Verification | Enrolled cohort items | A non-deleted legislature exists, regardless of cohort verdict |

Training and growth now cast the bounded source key to UUID instead of casting
the election primary key to text. This permits indexed election joins. Duplicate
jurisdictions across batches use the existing unique conflict guard. Existing
pending, running or settled targets are never updated by generation.

**Deployment:** halt and drain under operator control; pull `main`; refresh both
Horizon and the scheduler so worker-triggered and scheduled pumps use the new
code; resume the same run. No migration, frontend build or PostgreSQL restart is
needed. Preserve existing completed worklist markers. An incomplete older queue
with no cursor is reconciled from its sources, preserving every existing item.
Do not clear a completed queue merely to benchmark this change.

Measure queue-generation duration and `phase_timings[phase].worklist` progress
(`cursor`, `scanned`, `minted`, `complete`) at the next natural phase transition.
Confirm phase advancement waits for completion and work drain. Processing rates
and per-stage timings remain separate measurements: this patch changes the queue
generator, not the constitutional actions performed by each stage.

Validation: 21 tests / 250 assertions pass in disposable PostgreSQL databases.
All six new cursor paths cover empty first batches, halt/resume, duplicate
jurisdictions within/across batches, pre-existing settled targets, reconciliation
without a marker, and atomic insert/cursor rollback. Tests retain leaf-only
stipends and verification of enrolled chambers even with reviewed cohort items.
A two-source batch over 20,000 source items and elections uses both primary-key
indexes with no sequential scan. Existing counting and pump-lock checks pass.

Training-completion inspection found the existing actor index already supplies
the equality lookup in `TrainingGateService::hasCompleted`. The actual query uses
that index on a 20,000-row fixture and correctly distinguishes completed tracks.
No extra audit index is added. Production cost for this lookup, other per-holder
work and later-stage actions remains for the demo-box operator to measure.

## Follow-up: remove identical heartbeat-only writes

`SimWorkerJob` now remembers the last successfully committed lease timestamp.
Acquiring, executing and waiting activity updates still write immediately. A
stage's heartbeat callback skips its UPDATE only when it would send the exact
same timestamp for the same lease. Comparison uses Laravel's actual date binding
precision (seconds), so this introduces no longer heartbeat interval. Long-item
callbacks still write whenever their bound timestamp changes. Failed writes,
missing leases and writes inside a transaction cannot prime this cache.

The purpose is to remove redundant database round trips and updates from fast
items. This does not change audit locking, durability, claims, concurrency or
stale-worker thresholds. The production throughput effect remains unmeasured.

**Deployment:** under operator control, halt and drain the same run, pull `main`,
refresh Horizon workers, then resume. This worker-only change needs no migration,
frontend build, scheduler refresh or PostgreSQL restart.

Compare direct settled-item throughput and timer differences over multiple
windows at unchanged concurrency. Track `stage.seat_scope`, `lane.claim_next`,
`audit.commit` and `audit.lock_wait`; the last two are nested, not additive.
Where statement statistics are already available, compare heartbeat-only UPDATE
calls per completed item separately from activity updates. No new statistics
extension is required. Verify fresh leases and a bounded sample of completed
election outcomes as before.

Validation: 15 tests / 91 assertions pass. In-memory SQLite checks cover repeated
callbacks within one stored second, second boundaries, immediate activity
changes, long items, backward clock changes, rollback, missing/different leases,
and retry after a failed write. Two existing PostgreSQL worker-loop checks pass
in disposable nonce schemas, including installations without activity columns.
No live-world records or remote-demo state were changed by this pass.

## Phase 5: active member count index

The remote operator confirmed `c1e36516` deployed with valid sampled audit links.
Phase 4 completed all 914,453 items with zero review items. Its final before/after
windows were both about 1.22 million elections/hour: the audit patch did not
produce a measured throughput gain. Phase 5 then exposed a separate growing
cost: `seat.seated` repeatedly scans `legislature_members` by election.

Migration `2026_09_19_213000_index_active_members_by_election.php` adds
`legislature_members_active_election_idx` on `(election_id)` with predicate
`deleted_at IS NULL AND status IN ('elected', 'seated')`. It matches the actual
count in `SeatingStage` without changing that query or certification behavior.

One coordinator checks database-volume space and existing index builds,
preserves local configuration edits, pulls `main`, and applies:

```bash
docker compose exec -T app php artisan migrate --force --isolated=1 --path=database/migrations/2026_09_19_213000_index_active_members_by_election.php
```

This index-only patch needs **no Horizon, scheduler, PostgreSQL restart or
frontend build**. The migration disables its wrapping transaction and builds
concurrently. Normal reads/writes remain permitted, but the build consumes
resources and can wait for old transactions or snapshots. Do not wrap it in an
external transaction or start a second competing build. A valid existing index
is retained; an interrupted invalid build is recovered on retry unless still
actively building. Do not alter simulation run controls solely for this patch.

Verify with bounded catalog and per-election planner queries:

```sql
SELECT indisvalid, indisready, pg_get_indexdef(indexrelid)
FROM pg_index
WHERE indexrelid = to_regclass('legislature_members_active_election_idx');

EXPLAIN (FORMAT JSON)
SELECT count(*) FROM legislature_members
WHERE election_id = '<election-uuid>' AND status IN ('elected', 'seated')
  AND deleted_at IS NULL;

SELECT part, count, total_us, max_us FROM sim_timings
WHERE run_id = '<run-uuid>' AND part IN
  ('seat.seated', 'stage.seat_scope', 'audit.commit', 'audit.lock_wait');
```

Remote operator result for `9d4e7bec`, at unchanged 73-worker concurrency:

| Measurement | Before | After |
| --- | ---: | ---: |
| Time-weighted throughput | 359,753/hour | 830,703/hour |
| Member count (`seat.seated`) | 345.72–390.01 ms | 0.407–0.409 ms |
| Full seating item | 587.47–632.09 ms | 286.21–292.23 ms |
| Claim acquisition | 49.85–67.18 ms | 6.83–7.17 ms |

The two before and two after windows show 2.31x throughput. They process different
elections sequentially, not an identical replay. Counters flush in batches of
25. The index built live in 3m 1s without halt or restart, became valid/ready,
and changed the plan to an Index Only Scan. A bounded 100-election sample found
no certification or seated-count discrepancies. At 21:40:37 UTC, 238,283 of
914,453 items were complete with zero review items. Audit lock wait remained
177.1–181.5 ms (about 62% of the stage). Its increase accompanied the higher
throughput; it does not indicate a slower indexed count.

Validation: 3 tests / 29 assertions pass in disposable `seat_index_test_*`
databases. The bound-parameter count over a 20,010-row fixture changes from a
sequential scan to the new index and returns identical results. Tests also cover
valid-index reuse, failed concurrent-build recovery, rollback retaining the
primary key, and updates to active status and soft deletion. No local-world or
remote-demo schema changes were applied by the developer.

## Audit append follow-up

The demo-box operator confirmed `6d8a8670` at unchanged concurrency (73 workers):
441,763 elections/hour before versus 1,309,246–1,324,174/hour afterward.
Acquisition fell from 478.8 ms to 2.6–2.8 ms. All 923,095 generation sources were
examined with zero duplicate inserts. The remaining measured cost was audit-lock
acquisition: 170.6–173.3 ms inside 173.3–176.0 ms final audit commits.

The next patch shortens the existing audit critical section:

- Canonical JSON preparation happens before acquiring the chain lock, for both
  collapsed and individually retained batch entries.
- A single append reads the latest head and inserts its successor in one SQL
  statement after acquiring the lock. The append uses two SQL statements
  instead of three (transaction begin/commit are unchanged).
- PostgreSQL hashes the exact canonical UTF-8 string supplied by the existing
  PHP canonicalizer. It does not hash PostgreSQL's reformatted JSON. The PHP
  chain verifier, payload shape, per-item entry count, and global lock are unchanged.
- For transactions owned by append, model hydration happens after commit.
  Caller-owned transactions still contain their mutation and audit entry.

Lock acquisition deliberately remains a separate statement. PostgreSQL's
[Read Committed snapshots](https://www.postgresql.org/docs/17/transaction-iso.html#XACT-READ-COMMITTED)
let the subsequent head read see the previous writer's commit. Hashing uses
[built-in SHA-256 and UTF-8 conversion](https://www.postgresql.org/docs/17/functions-binarystring.html);
no extension or schema change is needed.

**Deployment:** halt and drain under operator control, pull `main`, refresh
Horizon before resuming the same run. This patch changes only the audit service;
there is no new migration, frontend build, database restart, concurrency change,
or audit rewrite. Earlier migrations remain required when upgrading from older
commits. Apply the existing PHP/opcache reload policy for web processes too.

Remeasure the same `lane.claim_next`, `stage.count_election`, `audit.commit`, and
`audit.lock_wait` counters over comparable windows. The latest remote baseline
above is the comparison point. No production speedup is claimed for this patch.

Validation: 13 focused tests / 249 assertions passed. They cover exact hashes for
Unicode, nested structures and larger payloads; returned metadata; missing
genesis; rollback; both batch formats; original database immutability triggers;
and two concurrent PHP writers forced to wait while a third changes the head.
The latter completes a 42-entry chain with no forks. Each SQL/Eloquent connection
is checked against a disposable `audit_test_*` database before writes; its admin
connection targets only the `postgres` maintenance database. This pass performed
no local-world or remote-demo database writes.

```bash
docker compose exec -T -e RUN_SIM_INDEX_PG_TESTS=1 app php vendor/bin/phpunit tests/Feature/AuditAppendPerformanceTest.php tests/Feature/SimPhaseQueueTest.php tests/Feature/AuditChainSmokeTest.php --filter 'AuditAppendPerformanceTest|SimPhaseQueueTest|canonical_json|chain_hash|simulated_chain'
```

## Phase 4 follow-up: generation barrier, cursor, and audit measurements

The full-queue remote sample reported 503 ms acquisition versus 104 ms processing
per item, with the claim index still absent. Apply the already-published claim
index first. This follow-up does not add workers or alter vote counting.

- The pump now holds a PostgreSQL session advisory lock in addition to its
  existing cache lock. It stays exclusive if generation exceeds the cache TTL,
  while individual chunks still commit. Normal exit/disconnect releases it.
- Each phase records `phase_timings[phase].worklist.complete`. An empty queue
  cannot advance until generation has finished. An interrupted generator is
  resumed on the next pump. Missing markers on an upgraded run are reconciled
  using the existing items; they are not assumed complete.
- Counting walks the existing `(run_id, kind, unit_key)` unique index with a
  durable cursor. Each host-sized source batch is bounded before election/race
  joins. Insertion uses the unique conflict guard instead of repeatedly
  comparing against the growing target queue. Cursor and inserts commit in one
  transaction. A batch with zero inserts still advances the source cursor.
  Other phases retain their existing generators behind the completion barrier.
- `audit.commit` measures the simulated item's final batch commit.
  `audit.lock_wait` measures its advisory-lock acquisition SQL, including the
  round trip. The latter is nested inside the former, which is inside the stage
  timer. Do not add these overlapping durations. The hash chain, transaction
  boundaries, append ordering, and durability are unchanged.

Validation passed: 34 unique PHP tests / 311 assertions across the focused
queue, claims, timing, counting-scope, and governance checks.

No new migration or frontend build is required for **this** follow-up. The two
migrations described below still apply if upgrading from `22105523`.

Deployment order: the operator halts and drains the run, waits for any old
`sim:pump` process to exit, preserves local configuration edits, and pulls
`main`. Apply the claim-index and worker-activity migrations below. Refresh
Horizon and the scheduler so old pump/worker code cannot coexist with the new
code, then let the operator resume the same run. PostgreSQL needs no restart.
Do not rewind the simulation. On the first updated pump, counting may make one
cursor pass over existing source items to establish a trustworthy completion
marker; existing target items are retained, including their settled states.

Read generation progress from CLI chunk output or this single-row query:

```sql
SELECT phase, phase_timings -> phase -> 'worklist' AS generation
FROM sim_runs WHERE id = '<run-uuid>';

SELECT part, count, total_us, max_us FROM sim_timings
WHERE run_id = '<run-uuid>' AND part IN
  ('lane.claim_next', 'stage.count_election', 'count.persist',
   'audit.commit', 'audit.lock_wait');
```

Compare differences over several windows within the same phase, after the
index has completed. Audit timers only describe updated workers. No production
speedup is claimed for the generation change or audit instrumentation.

Validation incident on the local development box: the first version of the
new test fixture copied a database connection name incorrectly. It wrote five
test run records to the local development database. All five exact IDs were
removed after verifying they had no work items or workers. The local scheduler
had advanced one empty test run and republished the existing education catalog
from configuration at 20:23:06 UTC; its prior row values were not captured and
were not reconstructed. No remote demo connection was used. The corrected
fixture uses a dedicated maintenance connection and verifies both raw SQL and
Eloquent resolve to its disposable database before creating any run.

## Follow-up: claim ordering and worker activity

The claim index was published separately as `5b132712` so it can be applied
without waiting for the dashboard update. Phase 3 finished before this patch
was delivered; there is no production Phase 3 before/after measurement for it.
`SimClaims` also serves later phases. Measure acquisition latency within the
same later phase, rather than comparing its overall rate to election throughput.

After preserving local configuration changes and pulling `main`, one coordinator
applies the index:

```bash
docker compose exec -T app php artisan migrate --force --isolated=1 --path=database/migrations/2026_09_19_200000_index_sim_claim_order.php
```

This adds `sim_items_claim_order_idx` on
`sim_items (run_id, kind, status, position, id)` concurrently. The final `id`
matches the existing claim ordering when many items share a position. The old
index, claim SQL, `SKIP LOCKED`, and worker count remain unchanged. This migration
has the same invalid-build recovery as the earlier indexes. It runs outside a
transaction; check disk/build activity first. The index alone needs no restart.

The subsequent dashboard update distinguishes acquiring work, executing an
item, and finding no available claim. An older worker with no claim reports
activity unknown, rather than idle. To enable explicit activity reporting:

```bash
docker compose exec -T app php artisan migrate --force --isolated=1 --path=database/migrations/2026_09_19_201000_sim_worker_activity.php
```

**This follow-up changes worker code.** After the operator gracefully halts and
drains the workers, refresh Horizon (`docker restart fc_horizon` on the supplied
stack) before the operator resumes the same run. Do not restart PostgreSQL.
The additive lease columns are nullable; workers tolerate the pre-migration
schema, and the dashboards tolerate pre-update workers. Publish the frontend
through the existing asset pipeline; do not run a full Vite build on the loaded
box. No local live-world migration or run-control action was performed here.

Both dashboards now show recent acquisition, execution, and housekeeping
averages, separately from all-run totals. The existing counters are sampled
once per ten seconds across viewers, using up to sixty seconds of history.
Allow two samples before reading a window. Timers flush in batches, so compare
several windows. Zero new samples display no measured average, not zero cost.
Housekeeping excludes acquisition; nested stage timers are excluded from the
execution summary to avoid double counting. Polling can miss brief execution
states even when workers are busy.

Verify `sim_items_claim_order_idx` is valid/ready using the bounded catalog
queries below with its name added. Use planner-only `EXPLAIN` for the full
`SimClaims` UPDATE with the actual run, phase kind, and status, confirming the
claim subquery loses its Sort and uses the new index. **Do not use EXPLAIN
ANALYZE on the claim UPDATE: it would claim a real item.** Sample these existing
counters before and after the build, at unchanged concurrency:

```sql
SELECT part, count, total_us, max_us FROM sim_timings
WHERE run_id = '<run-uuid>'
  AND (part IN ('lane.claim_next', 'lane.between_claims') OR part LIKE 'stage.%');
```

Compute each window average as delta `total_us` / delta `count` / 1000. Report
the actual phase and timings; no production speedup is claimed for this patch.
Local validation passed: 23 PHP tests / 210 assertions and 19 JavaScript tests.
Tests cover the actual claim UPDATE plan on 20,000 synthetic same-position
items, overlapping transaction claims without duplicates, valid-index retries,
failed concurrent-build recovery, worker activity with/without the new columns,
shared timing deltas, and rendered states on both pages. PostgreSQL tests use
nonce schemas with no public schema in their search path.

```bash
docker compose exec -T -e RUN_SIM_INDEX_PG_TESTS=1 app php vendor/bin/phpunit tests/Unit/SimWorkerReportingTest.php tests/Feature/SimClaimPerformanceTest.php tests/Unit/SimProgressSnapshotTest.php tests/Unit/SimConsoleRailsTest.php
node --experimental-vm-modules --test tests/js/simWorkerActivity.test.mjs tests/js/step5Readiness.test.mjs tests/js/simProgressSnapshot.test.mjs tests/js/i18nNamedArgs.test.mjs
```

## Original patch: resident lookup and progress polling (22105523)

The demo-box operator reported successful deployment: both indexes valid and
used, zero review items in the sampled state, candidate fielding down from about
2,450 ms to 15–21 ms, and short election windows of 649,000–815,000/hour versus
about 85,000/hour previously. The instructions below describe that original
patch; its no-worker-restart statement does not apply to the follow-up above.

### Changes

- `2026_09_19_190000_index_active_residents_by_jurisdiction_user.php` adds
  `residency_active_jurisdiction_user_idx` on
  `residency_confirmations (jurisdiction_id, user_id) WHERE is_active`.
  It matches `ElectionStage::rosterFor` without changing that query, candidate
  eligibility, ordering, roster size, or election behavior.
- `2026_09_19_191000_index_sim_recent_completions.php` adds
  `sim_items_run_finished_done_idx` on `(run_id, finished_at) WHERE status='done'`
  for the existing ten-minute completion-rate query.
- `SimSnapshot::progress` shares one sample for ten seconds, keyed by run,
  phase and status. One grouped query supplies ledger totals, stage bars and
  current-phase layers; a separate indexed query supplies the rate. Concurrent
  viewers reuse the sample. During a refresh, other viewers get the dated
  previous sample, or `computing` on a cold miss, rather than another scan.
  Worker leases, run controls and permissions remain fresh and outside this cache.
- Both dashboards expose `progress_snapshot.snapshot_at`, `snapshot_stale` and
  `snapshot_state` and display the measurement time. Shared cache storage
  (`CACHE_STORE=redis` in the supplied deployment configuration) is required
  to share the sample between HTTP processes.

## Apply on the demo box

One coordinator runs the builds. Do not separately create the same indexes from
another session. Concurrent builds allow normal reads/writes but take locks,
can wait for transactions, and consume CPU, I/O and disk. Check available space
on the database volume and existing index-build activity first. Neither build
duration nor production speedup is established by the local tests.

Record the baseline below before deployment, then:

```bash
git status --short
git pull --ff-only origin main
docker compose exec -T app php artisan migrate --force --isolated=1 --path=database/migrations/2026_09_19_190000_index_active_residents_by_jurisdiction_user.php
```

Preserve the demo's existing Matrix/LiveKit configuration edits. Stop if Git
reports a conflict; do not reset them. Using explicit migration paths applies
only this performance work, not unrelated pending migrations.

After the resident index completes, apply the secondary rate index:

```bash
docker compose exec -T app php artisan migrate --force --isolated=1 --path=database/migrations/2026_09_19_191000_index_sim_recent_completions.php
```

Both migrations explicitly disable Laravel's wrapping transaction. Do not wrap
them in a transaction externally. An existing valid index with the same name
is retained. A failed concurrent build's INVALID index is removed and rebuilt
on retry; the migration refuses to remove one still listed as building.

No PostgreSQL or Horizon restart is needed for these changes: no worker code
changed. The PHP dashboard changes load with new requests under the existing
deployment's PHP/opcache policy. On a deployment that disables opcache timestamp
validation, use its normal PHP reload procedure. If using prebuilt frontend
assets, compile/publish the two dashboard pages and locale bundles through the
normal asset pipeline; do not run a full Vite build on the loaded demo box.
The database improvement and backend cache do not depend on new frontend assets.

## Verify and remeasure

Use bounded catalog queries, not counts over the residency table:

```sql
SELECT c.relname, i.indisvalid, i.indisready, pg_get_indexdef(i.indexrelid)
FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
WHERE i.indexrelid IN (
    to_regclass('residency_active_jurisdiction_user_idx'),
    to_regclass('sim_items_run_finished_done_idx')
);

SELECT pid, relid::regclass, index_relid::regclass, phase,
       blocks_done, blocks_total, tuples_done, tuples_total
FROM pg_stat_progress_create_index
WHERE relid IN (to_regclass('residency_confirmations'), to_regclass('sim_items'));
```

There must be two valid/ready indexes once both migrations finish. Build-progress
counters describe the current build phase; they are not an overall ETA.

For a few actual in-flight jurisdictions, reuse the same IDs and LIMIT values
as the baseline, substituting a real UUID below. Start with planner-only EXPLAIN:

```sql
EXPLAIN (FORMAT JSON)
SELECT user_id FROM residency_confirmations
WHERE jurisdiction_id = '<jurisdiction-uuid>' AND is_active = true
ORDER BY user_id LIMIT 40;
```

Check for the new index and absence of a separate Sort. Plan choice remains
cost-based. For timing, use the existing `sim_timings` counters for the same
run, sampled twice over comparable windows after the builds have finished:

```sql
SELECT part, count, total_us, max_us FROM sim_timings
WHERE run_id = '<run-uuid>'
  AND part IN ('election.field', 'stage.election_scope');
```

- Window average milliseconds = delta `total_us` / delta `count` / 1000.
- Elections/hour = delta stage `count` / elapsed seconds * 3600.
- Timing updates are batched; use several comparable windows and report that
  uncertainty. Do not use cumulative averages to describe the new performance.
- Compare PostgreSQL CPU/waits, worker utilization and dashboard request latency
  at the same worker count. Leave worker/buffer tuning for the measured follow-up.
- Poll `/api/setup/wizard/step5/progress` and `/api/simworld/progress`: samples
  within ten seconds for the same run/phase/status should share the measurement
  timestamp. Status changes take effect immediately; a refreshing sample is
  explicitly marked stale. UI screenshots need the updated frontend assets.

## Local validation

Focused tests use in-memory SQLite and random isolated PostgreSQL schemas with
no `public` schema in their search path. No local or remote live-world migrations
were applied. PostgreSQL tests build/drop real concurrent indexes, verify the
planner uses them, compare resident ordering before/after, retain an existing
valid index, and recover deliberately failed builds. Cache tests cover exact
counts, window boundaries, multiple readers, expiry, phase/status isolation,
concurrent cold/refresh reads and lock release after failure.

```bash
docker compose exec -T -e RUN_SIM_INDEX_PG_TESTS=1 app php vendor/bin/phpunit tests/Unit/SimProgressSnapshotTest.php tests/Feature/SimulationPerformanceIndexesTest.php tests/Unit/ProgressPollingBoundsTest.php tests/Unit/SimConsoleRailsTest.php
node --experimental-vm-modules --test tests/js/simProgressSnapshot.test.mjs tests/js/step5Readiness.test.mjs tests/js/simDial.test.mjs tests/js/simCeiling.test.mjs tests/js/i18nNamedArgs.test.mjs
```

Rollback, if requested, uses the two migrations' `down()` methods, which drop
only these indexes concurrently. Do not roll back unrelated migrations or the
simulation run. A performance regression in the dashboard can be reverted
independently of retaining the resident index.
