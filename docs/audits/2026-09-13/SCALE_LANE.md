# Scale lane (G1, G2, G3)

Date: 2026-09-13. Built in parallel on branch `lane/scale` (worktree `.wt/scale`), each item reviewed by three adversarial lenses, committed per item, merged into main as `0194b57c`. Migrations applied on box E after the merge. No simulation or setup run was started; the live world was never a fixture.

## G1 world readiness (commit 57ed7ab9). Closed.

Ruling `step5-readiness-guard` = A. The sim's verifying phase now does work: `PHASE_KINDS['verifying'] = ['verify_scope']`; the pump mints one verify item per legislature-bearing jurisdiction in the run's own scope (keyset over the run's cohort scopes, idempotent, bounded by the mint chunk); `VerifyStage` reads only that jurisdiction's own rows (seated legislature, delegated or elected executive, judiciary at the five-judge floor, organizations with a filled board chair), gated by the run's aspects, and returns done or review with named gaps. `WorldReadiness` is the single owner of the rollup (index-only aggregate over sim_items) for both the page and the guard. `completeStep5` refuses when the run is not done, holds a done run with zero verify items as verification pending, and requires force=true while review items remain, recording the outstanding list into `setup_completion_notes`; `completeStep6` requires that stamp. The Step 5 page shows the rollup, the gaps and the force control; Lock is enabled only when complete. Low flag left: the organization ownership check cannot fire on the live schema (the column is NOT NULL).

## G2 simulation resume (commit 8bd09a08). Closed.

Ruling `sim-resume-cursor` = A. `sim:start` enumerates by keyset (population desc, id) with scanned and inserted returned together, persists the cursor in the new `sim_runs.enum_cursor`, resumes from it, continues positions from the stored maximum, honours the stored run options over CLI values, and prints scanned, inserted, elapsed and ETA per chunk. The chunk size is derived from the host (`HostCapacity::enumerationChunk`, env `CGA_ENUM_CHUNK`, floor 1,000) and the two siblings (`ProvisionRunControl`, `AutoscaleEnumeration`) read the same derivation. Migration `2026_09_13_181000_sim_runs_enum_cursor.php`.

## G3 bounded progress polling and backstops (commit 64208206). Closed.

Ruling `progress-polling-bounds` = A. `SetupProgressRollup` owns every step-4 and world-scope aggregate; the poll endpoints read a cache snapshot, a cold miss queues one warm job and returns a computing state, and the scheduler warms viewed rollups every minute, so no request scans a world table. `SimSnapshot` world figures come from the warmed rollup and O(1) counters on the run row (`people_founded`, `residencies_founded`, `cohorts`, `chambers_governed`, maintained by the worker); the email LIKE scan is gone. The co-determination backstop derives its grace window from the election demo-compression dial in `EvaluateCoDeterminationJob` (the protected service untouched). `AutoscaleEnumeration::deriveOrderingKeysOnLedger` chunks only its PostGIS area-tier scan by keyset with a phase-and-cursor resume marker; the order-dependent passes stay whole. Migration `2026_09_13_183000_sim_runs_counters.php`.

Not run: the 12-map districting regression gate. G3 changes how the ordering keys are derived (chunked scan, same statements per band), not what they compute; the operator decides whether the gate runs before the next planet sweep.

## Passed checks on the merged main

| Run | Result |
| --- | --- |
| `WorldReadinessGuardTest`, `SimResumeEnumerationTest`, `ProgressPollingBoundsTest`, the districting source pins (`CompositeTransactionSplitPinTest`, `CompositeTransactionBeatRoutingTest`) with the other lane and pin sets | OK (155 tests, 5,706 assertions, 1 expected skip) |
| `tests/js/step5Readiness.test.mjs` within the full compiled-Vue suite | 195 pass, 0 fail |
| Vite transform `Step5_Simulate.vue` | 200 |
| Migrations on box E | 2 DONE, no invalid index |

To confirm read-only on box E later: the verify mint over a real run, and the Step 5 page readiness rollup.
