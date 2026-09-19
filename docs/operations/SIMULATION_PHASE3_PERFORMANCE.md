# Step 5 election lookup and progress polling — 2026-09-19

This is the deployment/measurement handoff for the demo-box Astra. The local
developer changes code and pushes it; the demo-box operator owns application of
these migrations and measurements on the running simulation.

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
