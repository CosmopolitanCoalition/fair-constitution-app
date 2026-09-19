# Step 5 election lookup and progress polling — 2026-09-19

This is the deployment/measurement handoff for the demo-box Astra. The local
developer changes code and pushes it; the demo-box operator owns application of
these migrations and measurements on the running simulation.

## Changes

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
