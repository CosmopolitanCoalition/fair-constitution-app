# D014 developer response — protect dependencies and recover no-op receipts

2026-09-20. Completed developer handoff for manual operator relay. The final
response supplies the pushed revision and test totals. The received report is
preserved at [step5-benchmarks/D014.md](step5-benchmarks/D014.md).

## What changed

- Inspection classifies each race as uncounted/counted and sufficient/deficient
  for its seats. A complete deficient count blocks recovery before fielding,
  even when other races are uncounted and the election itself remains scheduled.
  Full-seat uncontested counts are retained without being treated as deficient.
- An election action must be applied **and** its election certified with a
  fully seated legislature before dependent training/institution actions run.
  Each subsequent action checks its own postcondition; failed prerequisites
  stop downstream actions. Valid existing boards still elect chairs independently.
- Demonstrable prerequisite no-ops are `deferred`, not `applied`. Genuine
  failures/incomplete postconditions stay blocked. Applied receipts reused in
  a changed world must still satisfy their postcondition; they do not grant a
  bypass. The stage's civic voting thresholds and election protections are unchanged.
- A scope-targeted receipt correction recognizes only the exact effect-free
  result shapes from D014 (zero holders/training, unseated-chamber government
  and court skips, zero-output civics). It refuses to treat nonzero work or
  resumed votes as a no-op. It appends correction audit entries and preserves
  the entire previous receipt result and timestamps in `_prior_receipts`.
  Successful receipts and all existing audit rows remain intact. Repeating the
  correction changes nothing a second time; it does not execute civic actions.
- A bounded, resumable classification refresh uses the existing full inventory.
  It revisits affected election plans, preserves their previous metadata, and
  rebuilds the category summary. Existing run/work-item IDs and receipts remain.
  The saved cursor advances atomically with each page; the pump's PostgreSQL
  ownership lock prevents overlapping refreshes/pumps. Old summaries are marked
  stale and cannot authorize application until the refresh completes.

No migration, frontend build, PostgreSQL/Redis restart, worker-count change or
world reset is required. This is runtime PHP: **refresh drained Horizon and the
scheduler** so the running processes use the dependency and inventory guards.

## Continue the existing halted full inventory

Full repair run: **`01a0bed7-d6c6-7399-b288-44053ffe00e1`**.
Original source: `01a0ba29-3dbb-7181-8654-2d41ce1dea86`; repair version stays **1**.
The pilot already execution-completed; do not resume it or create another run.

1. Keep the full run halted and application disabled. Confirm zero leases and
   running items. Stop the drained Horizon/scheduler with the established bounded
   procedure, pull the supplied revision, then start those services on the new
   code. Preserve local configuration and infrastructure settings.
2. Correct only Earth's four proven no-op receipts, using its existing targets
   discovered through the source/version/scope keys and checked against the
   owning legislature/court:

   ```bash
   php artisan sim:repair --recover-noops=01a0bed7-d6c6-7399-b288-44053ffe00e1 --scope=27cf7c64-2c76-4fd5-8544-bfd223cfdc72
   ```

   Expect four `corrected` entries: training, governance, judiciary, civics.
   A mismatched/nonzero receipt is retained and reported, not forcibly reset.
   If the result differs from D014, inspect the reported receipt before applying.
   The election refusal and all genuine successful receipts are untouched.
3. Refresh the existing completed inventory and read its revised summary:

   ```bash
   php artisan sim:repair --refresh-plan=01a0bed7-d6c6-7399-b288-44053ffe00e1
   php artisan sim:repair --status=01a0bed7-d6c6-7399-b288-44053ffe00e1
   ```

   This scans metadata in host-sized pages, re-inspects affected election plans,
   and rebuilds category totals. It does **not** replay the 923,095 inspections
   as a new simulation, reset receipts or execute domain repairs. If interrupted,
   repeat the same command to continue the saved cursor. The run remains halted
   and `authorized=false`; `classification_stale=false` and `plan_complete=true`
   confirm the revised summary is ready. Old and revised counts are not repair
   throughput. All apply paths reject stale classifications.
4. Check Earth's revised classification is `blocked_recovery`, with the recorded
   deficient counts still protected, and that the previously repaired pilot
   scopes retain their real results. Under the operator's existing authorization
   to continue the full repair after this fix, apply **this same full run**:

   ```bash
   php artisan sim:repair --apply=01a0bed7-d6c6-7399-b288-44053ffe00e1
   ```

   Do not use ordinary Resume as a substitute for explicit Apply. Benchmark
   actual `repair_scope` completion/review outcomes, not inventory DONE counts.
   Application always re-inspects a jurisdiction's current prerequisites.

## Checks and remaining boundary

The migrated PostgreSQL fixtures cover a mixed election with an uncounted race,
a complete deficient race, and a retained complete full-seat race. They assert
blocked classification, unchanged candidate/tabulation rows, no new dependent
receipts, and real independent board-chair success. Additional checks cover
failed election receipts, postcondition failure, no-op versus successful receipts,
targeted correction repeated twice, exact preserved receipt history, continuation
once an eligible recovered chamber is supplied by the fixture, and no repeated
votes/terms/payments on redelivery. The eligible fixture is **not** an implementation
of a new election recovery policy.

Inventory tests retain the same work IDs, preserve prior classifications,
rebuild totals, keep application disabled, and refuse stale manifests. Existing
real-worker PostgreSQL and repair workflow regressions run alongside these checks.
The combined focused suite passed **38 tests / 440 assertions**. All test writes
are confined to guarded disposable databases; no local world or
remote operation was performed.

Earth's already-counted deficient races and the certified underfilled cases
still require the separate authorized election-recovery decision documented in
the original handoff. This patch prevents false success and makes future eligible
continuation possible; it does not rewrite those counts, invent legislators,
change thresholds or force global readiness. Phase 10 is never replayed; newly
eligible training still uses the existing once-only mechanism. Leave the separate
Claude storage/monitor/shutdown loop unchanged.
