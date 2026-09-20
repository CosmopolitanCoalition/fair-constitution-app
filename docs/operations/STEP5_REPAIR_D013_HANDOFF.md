# D013 developer response — worker claim width hotfix

2026-09-20. Completed developer handoff for manual operator relay. The final
developer response supplies the pushed revision. No remote operation occurred.

## Fix and scope

The D012 release missed an installed-schema constraint: `repair_plan_scope` is
17 characters, while `sim_worker_leases.claim_type` was `varchar(16)`. The prior
service tests did not execute this lease-reporting path. The new additive
migration widens the reporting column to **24**, matching `sim_items.kind`.
It preserves existing values, NULLs and defaults. PostgreSQL lock acquisition
is bounded at five seconds and execution at thirty seconds. A failed migration
rolls back and may be retried while the pilot remains halted. Rollback does not
narrow the column, which would reintroduce the failure or truncate reporting.

No worker, institutional repair, payment, election-policy or frontend code changes
are included. All original D012 certified-election restrictions remain in force.

The installed-schema audit covers every `SimRun::PHASE_KINDS` value against the
item kind and lease claim type columns, phase names against `sim_runs.phase`,
and stage/action timer names against `sim_timings.part`. Receipt action kinds
fit `sim_repair_receipts.kind`. Claim labels are already bounded by the worker
to 155 characters against a 160-character column; activity labels are separate
short values, not copies of the item kind. Other engines' lease tables do not
receive the Step 5 repair kinds and are unchanged.

## Deployment and continuation — use the existing pilot

The demo is already on `24994ae7` with the receipt migration and assets applied.
Its existing pilot is **`01a0bec3-abfe-71d5-a809-55bf5d161eab`**; the original source
is `01a0ba29-3dbb-7181-8654-2d41ce1dea86`. **Do not create another repair run.**

1. Keep the pilot halted. Confirm no worker leases remain, as reported in D013.
   Preserve the four local configuration files and the original completed world.
2. Pull the supplied revision. Apply only this new migration inside the app
   container using the established wrapper:

   ```bash
   php artisan migrate --path=database/migrations/2026_09_20_123000_widen_sim_worker_claim_type.php --force
   php artisan sim:repair --status=01a0bec3-abfe-71d5-a809-55bf5d161eab
   ```

   Confirm the column is nullable `varchar(24)` and the same pilot remains
   unauthorized for application. This is a schema-only hotfix: no asset build,
   route clear, Horizon/scheduler refresh or PostgreSQL/Redis restart is needed
   for processes already running `24994ae7`.
3. Resume that active pilot and run the normal pump:

   ```bash
   php artisan sim:resume
   php artisan sim:pump
   ```

   The pump reclaims orphaned claims using the existing lease mechanism. Do not
   delete or manually rewrite work items, receipts or leases. It then runs the
   original five-scope inspection. No repair application is authorized here.
4. Wait for `repair_planning`, `status=halted`, `plan_complete=true` and
   `authorized=false`. Read the completed manifest:

   ```bash
   php artisan sim:repair --status=01a0bec3-abfe-71d5-a809-55bf5d161eab
   ```

   Check all five inspections settled without the lease-width error and inspect
   any reported prerequisite blockers. Confirm the source run, completed stipend
   items and ledger head remain unchanged. Return the completed plan for the
   operator's representative mutation-pilot assessment. Do not invoke `--apply`
   merely because inspection now succeeds.

The independent Claude storage/monitor/shutdown loop is unchanged.

## Regression evidence

`SimRepairWorkerTest` uses the complete baseline plus additive migrations in a
guarded nonce PostgreSQL database, not a handcrafted schema or SQLite:

- Restores the old 16-character width in the fixture and reproduces SQLSTATE
  `22001` from **`SimWorkerJob::handle()`**, before inspection; its cleanup removes
  the lease and leaves the same claimed item orphaned.
- Halts through the real control service/pump, applies the migration, resumes
  the same pilot and reclaims it through the real pump.
- Executes real lease reporting, inspection, the independent heartbeat connection,
  claim-token/status-fenced settlement and the worker's pump kick. A real planned
  election receipt and completed inventory are produced without application.
- Confirms the plan pauses with application disabled, even after ordinary resume;
  original source verification/election/stipend items and election candidates
  are unchanged. No lease remains and the full stage timer is recorded.
- Covers old values/NULL preservation, repeated migration application and
  installed widths for every declared Step 5 kind.

The combined focused suite passed **33 tests / 374 assertions**: the new
PostgreSQL worker/schema regression, existing repair integration tests, and
worker heartbeat/reporting tests. Remote inspection success is still to be
measured; these tests do not claim the world has been repaired.
