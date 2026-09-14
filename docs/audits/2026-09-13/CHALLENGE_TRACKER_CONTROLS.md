# Challenge tracker controls (IO-3)

Date: 2026-09-13. Scope: IO-3. **Closed.** The four existing Art. IV §5 handlers are reachable from the tracker: a judge records the finding (F-JDG-004) and the remedy recommendation (F-JDG-005), a member of the offending law's legislature proposes the override (F-LEG-035), a judge applies the direct remedy (F-JDG-006) once both windows have closed. A member can open a remedial bill with the challenge prefilled (Path 1).

## Operator ruling applied

Rubric `challenge-tracker-controls` = A. Routes and controls for all four handlers, each enabled only in its lawful state; the remedy button only after `veto_closes_at` and `remedy_due_at`; a "Propose amendment bill" link that prefills `targets_challenge_id`; `EMERGENCY_PROTECTED_FORMS` unchanged. No new form (the count stays 130).

## What was built

| Piece | Where |
| --- | --- |
| Routes | `POST /constitutional-challenges/{challenge}/finding`, `/remedy-recommendation`, `/override`, `/remedy` (auth, uuid) |
| Controller | `ChallengeController::finding` / `recommend` / `override` / `remedy` hand payloads to the engine exactly as `file()` does; `outcomeGates()` computes enabled-state hints from status, seat and membership and reads the windows off the stamped remedy row, never re-deriving a threshold or a window |
| Path 1 | `BillController::store` forwards `targets_challenge_id` (the missing wiring; `BillService::introduce` already persisted it); the bills page accepts the prefill; the tracker links to it for a member while the window is open |
| Page | `Art4Section5Tracker.vue` and `ConstitutionalChallenge.vue`: each control renders for its actor, disabled with a reason in the wrong state (a closed challenge disables all), absent for other viewers; loading/error/success feedback; the page-level refusal banner no longer claims "the challenge was not filed" for an outcome refusal |
| Learn | Six authored steps on the challenge tracker surface in `K2_CONTENT_SURFACES.md`, generator re-run |

The engine remains the boundary: premature and repeated remedy applications are refused by `JudicialRemedyService` itself, exercised through the route in the tests.

## Passed checks

| Run | Result |
| --- | --- |
| `tests/Unit/ChallengeTrackerControlsTest.php`, `CaseLifecycleControlsTest.php`, `AppealWorkflowTest.php`, `AuditChainSmokeTest.php`, `BillChallengeFeedTest.php`, `BoardChairWorkflowTest.php` | OK (67 tests, 360 assertions; form count 130) |
| `tests/js/challengeControls.test.mjs`, `caseLifecycleControls.test.mjs`, `appealWorkflow.test.mjs` | 13 pass, 0 fail |
| Vite transform `Art4Section5Tracker.vue`, `ConstitutionalChallenge.vue`, `Bills.vue` | 200 |
| `node scripts/education/build_education_payload.mjs` | OK, 0 failures, idempotent |

Not re-verified here: the append-only law-version write of a direct remedy (`EnactmentService::amendLaw`, source `judicial_remedy`) is pinned only by `tests/Constitutional/Art4Section5Test.php`, a live-PostgreSQL pin that skips on this box. The routing, gates and the premature/repeated guards are covered DB-free.

Review: one implementer, three adversarial reviewers, one repair (the banner title).

Reproduce:

```text
docker compose exec -T app php vendor/bin/phpunit tests/Unit/ChallengeTrackerControlsTest.php tests/Feature/BillChallengeFeedTest.php
docker exec fc_vite node --experimental-vm-modules --test tests/js/challengeControls.test.mjs
```

No migration. Pulling hosts need only the code.
