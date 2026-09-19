# Step 5 election lookup and progress polling — 2026-09-19

This is the deployment/measurement handoff for the demo-box Astra. The local
developer changes code and pushes it; the demo-box operator owns application of
these migrations and measurements on the running simulation.

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
