Current release: [D016 combined performance response](STEP5_REPAIR_D016_HANDOFF.md).

# D012 developer response — repair the existing world and prevent recurrence

2026-09-20. Completed developer handoff for operator manual relay. Deploy the
commit containing this document (the final developer response supplies its SHA).
No remote operation or cross-task message was performed. The original run is
`01a0ba29-3dbb-7181-8654-2d41ce1dea86`; it must not be restarted or reset.

**Current D015 continuation:** the full repair is running on `96f840aa`.
Use [the chair-audit and authorized election recovery handoff](STEP5_REPAIR_D015_HANDOFF.md)
to halt/drain, deploy, enable the settled recovery choice and resume the SAME run
`01a0bed7-d6c6-7399-b288-44053ffe00e1`. No new inventory or world restart.

**Historical D013 continuation:** D012 was deployed, but inspection exposed the worker
claim-type width defect. Follow [the D013 hotfix handoff](STEP5_REPAIR_D013_HANDOFF.md)
to migrate and resume existing pilot `01a0bec3-abfe-71d5-a809-55bf5d161eab`.
Do not repeat the new-pilot creation instructions below for that deployment.

## What this release does

| Path | Implemented behavior |
| --- | --- |
| Fresh Step 5 elections | Reserve candidate identities across the entire election, serialize assignment on its election row, draw narrower footprints first, refill from eligible residents, preserve district/panel membership, and report persisted counts. Population ceilings remain enforced. |
| Fresh executive/committee/court growth | Exactly five serving members pass the simulator's delegation guard. Both bicameral checks use the model's canonical lowercase seat-kind mapping. The existing real voting services still decide adoption. |
| Fresh organization boards | Complete the real full-board ranked chair election after seating. Existing chairless boards are reachable, and repeated business-board generation revisits the same bounded sample. |
| Existing world | A separate repair run inventories the original verification worklist, pauses, and applies only after an explicit operator command. Original run outcomes remain historical records. |
| Recovery | Receipt keys contain source run, repair version, action kind and target. A target's effects, audit and receipt commit together. Duplicate delivery reuses the receipt. Process loss rolls back an unfinished action; committed actions survive item re-delivery. |
| Acceptance | Each scope gets before/after verification, candidate coverage, actual Type B representation, committee/department/governor checks, chair membership and adopted-vote provenance, and court seats/terms/expiry-clock checks. Remaining actions and failed prerequisites stay in review. |

The stages repair in order: election fielding/counting/certification/seating,
once-only training for eligible holders, government, courts, missing civic work,
and new board chairs. Existing seated boards can elect their chairs even when
an unrelated election remains blocked. Open supported institutional proposals
and consents are resumed through the real vote service; closed votes are not
rewritten. Known failed action receipts are not blindly retried.

No Phase 10 stipend is replayed. Existing stipend items and wallet/ledger history
are retained. New offices may change future stipend eligibility; this release
does **not** issue retroactive adjustments. Newly eligible training uses the
existing once-only award/payment mechanism.

The repair has host-sized cursor enumeration, the normal worker pool, halt and
resume, independent durable worker heartbeats during action transactions, and
claim-token fencing on repair settlement. Its summary is accumulated in bounded
chunks and saved before the apply gate; dashboard polls do not rebuild it.
Pilot success cannot unlock completion for the entire world.

## Important unresolved election recovery

This release **does not rewrite certified elections or existing deficient
tabulations**. It repairs never-counted races in an open nomination election and
keeps existing complete counts intact. A previously counted race that filled all
its seats is retained even when it was uncontested.

For a certified race with fewer candidates/winners than seats, the current
`VacancyService::declare` requires an existing legislature member, and
`ElectionLifecycleService::scheduleSpecial` derives the replacement seat from
that member. A never-filled seat has no member to vacate. Inventing a member or
revoking an otherwise valid certification would not be a safe reuse of that
workflow. Such scopes remain `blocked_recovery`/review, with their actual deficits
recorded. This may include more than D012's 340 failed-delegation cases; the
inventory inspects all source scopes, including scopes whose majority check
previously passed.

**Decision needed for a follow-up:** authorize a supplemental election for only
the never-filled seats, retaining original winners/certification/closed votes
and the original term end. That requires extending the vacancy representation
to support never-occupied seats and using the existing special-election/count/
certification machinery. No such policy extension is silently included here.
An alternative is to leave these places explicitly unfinished for the conference
while presenting the independently repaired jurisdictions. Do not force world
verification green to hide them.

## Deployment

1. Preserve the existing four local configuration files. Build frontend assets in
   the established isolated build environment; do not build on a loaded box.
2. Verify that the finished original run has no simulation workers/claims still
   active. If any remain, use the established bounded halt/drain procedure.
   Stop the drained **Horizon and scheduler** for the update so old and new pumps
   cannot mix. Do not restart PostgreSQL, recreate Redis, or rederive host sizing.
3. Pull the supplied revision. Apply the one additive migration:

   ```bash
   php artisan migrate --path=database/migrations/2026_09_20_120000_create_sim_repair_receipts.php --force
   php artisan route:clear
   ```

   The migration creates only a new receipt table and its indexes. There is no
   concurrent index build over populated world tables. Its rollback deliberately
   refuses to delete recovery history. Publish the frontend assets and refresh
   the drained services using the demo box's established procedure.
4. Confirm `php artisan help sim:repair` and the Step 5 repair panel. The existing
   original run remains done with its original review history. No repair starts
   automatically on deployment.

The independent Claude storage/usage/shutdown loop is outside this change.
Coordinate its existing operator shutdown arrangement before starting a repair;
this code does not alter or send instructions to it.

## Representative pilot: inspect first, then apply

Commands below run inside the demo app container, using its usual wrapper.
The scope IDs are from D012, not a new live census.

```bash
php artisan sim:repair 01a0ba29-3dbb-7181-8654-2d41ce1dea86 \
  --scope=2f31238b-43ac-487a-8014-1ad5dd841f93 \
  --scope=000032b7-dc88-4ac8-9edd-3dfdf92630aa \
  --scope=36542893-dac7-4a71-bbba-6efb4813d3fb \
  --scope=27cf7c64-2c76-4fd5-8544-bfd223cfdc72 \
  --scope=01316535-a869-42fb-859d-fdbf58dd96dd
```

Record the **new repair run UUID** printed by the command. Enumeration and
inspection use the existing pump/worker mechanism. Wait for `repair_planning`
to halt with `plan_complete=true`. Read its summary and manifest:

```bash
php artisan sim:repair --status=REPAIR_RUN_UUID
```

The status response contains at most 50 scopes; use `--after=NEXT_KEY` to page.
The Step 5 and `/simworld` operator surfaces expose the same inventory, summary
and apply control. Inspection writes the plan and audit trail, **not domain
repairs**. Review prerequisites and proposed actions before applying:

```bash
php artisan sim:repair --apply=REPAIR_RUN_UUID
```

`sim:halt` and `sim:resume` apply to the active repair. A halt between committed
actions returns the scope to pending, retaining receipts. An interrupted initial
enumeration can be continued with `sim:repair --resume=REPAIR_RUN_UUID`; a halted
run must first be resumed. The scheduler also recovers queued repair enumeration.

After the pilot settles, inspect each before/after result and action receipt.
Check actual chair vote/casts/record provenance, five-member delegation,
committee/court adoption and judge terms/clocks. For election recovery compare
original candidate IDs and existing tabulation hashes, then confirm any newly
counted races and seated members. Confirm that the deliberately blocked certified
case remained certified and its earlier failed acts were not rewritten.
Compare existing Phase 10 items and a bounded wallet/ledger sample. Check a
bounded audit tail; local tests do not certify the remote world's whole history.

## Expand without replaying the pilot

Once the pilot is execution-complete and its checks pass, create a second repair
plan **without `--scope`**, using the same source run and default repair version 1:

```bash
php artisan sim:repair 01a0ba29-3dbb-7181-8654-2d41ce1dea86
```

Inspect the complete summary, then apply that new repair run. Existing successful
receipts and already-complete domain state prevent repeating pilot effects. This
full manifest includes the original lawfully inactive places as no-ops. Execution
completion and world readiness remain separate; any unresolved scope stays in
review. Do not increase `--repair-version` merely to repeat a failed act: inspect
its receipt and the underlying prerequisite first.

Measure direct repair-scope completions and review categories at unchanged worker
concurrency. Record planning separately from mutation, and distinguish scopes
rechecked from institutions newly repaired. `repair.*` timers describe individual
receipt transactions; existing stage timers remain nested. No planet throughput
or time-to-completion claim is made from the local fixtures.

## Internal validation

The focused suite uses private SQLite and nonce PostgreSQL databases, including
a full baseline plus all additive migrations. It tests real chair ballots,
five-member delegation and 4/6 boundaries, lowercase Type B committee/court
formation, an empty election through counting/certification/seating, overlapping
resident scopes, district membership and the real population ceiling. It also
tests receipt rollback/interruption, concurrent target locking, repeated runs,
pending-vote reuse, resumable enumeration, inactivity, and the pilot/full-world
completion distinction. No development-world simulation or remote test was run.
The combined focused PHP suite passed **110 tests / 1,049 assertions**. All
**10 worker-display JavaScript tests** passed, and Vue compilation passed for
the repair controls and both host pages without a production asset build.
The pushed revision is in the completed developer response.
