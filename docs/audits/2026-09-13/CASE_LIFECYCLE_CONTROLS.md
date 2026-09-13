# Case lifecycle controls (IO-1)

Date: 2026-09-13. Scope: IO-1. **Closed.** A seated judge of the court can carry a case from acceptance through hearing, deliberation and verdict from the case page, with state, panel, actor and repeated-submission checks. Dismissal and motion/evidence rulings are included.

## Operator ruling applied

Rubric `case-lifecycle-controls-shape` = A. Hearing, deliberation, dismissal and rulings are constitutional forms (engine, catalog, judiciary training gate, surfaces, Learn copy). The verdict stays a `CaseService` transition behind a judge-only route. The verdict actor must sit on the case panel. A panel verdict records for + against equal to the panel size and the outcome is carried by the majority. A jury verdict records unanimity and is accepted only when a jury was empaneled. The case row is locked for the write.

## What was built

| Piece | Where |
| --- | --- |
| F-JDG-011 Hearing Order, F-JDG-012 Deliberation Order, F-JDG-013 Dismissal Order, F-JDG-014 Motion or Evidence Ruling (roles R-19, R-20) | `app/Domain/Forms/Handlers/HearingOrder.php`, `DeliberationOrder.php`, `DismissalOrder.php`, `MotionEvidenceRuling.php`; `FormRegistry` (129 forms); `config/cga/education.php` gated_forms (the judiciary training gate is a per-id map) |
| Actor gate | `JudicialActor::seat` (a seated judge of this court) on every order; `CaseService::assertTransition` refuses repeats |
| Verdict | `POST /cases/{case}/verdict` → `CaseController::verdict`; `CaseService::assertActorOnPanel`; `CaseService::assertVerdictRecordable` called inside `recordVerdict` (decided_by, outcome consistent with the case kind, panel vote sum and majority, jury unanimity only with an empaneled jury); the case row is locked in the transaction |
| Routes | `POST /cases/{case}/hearing`, `/deliberation`, `/dismissal`, `/rulings`, `/verdict` (auth, uuid) |
| Page | `CaseDetail.vue` "Case proceedings": each control enabled only at its lawful state, disabled not hidden for the court, absent for other viewers; inline ruling controls on motion and evidence rows; the Jury option appears only when a jury sat |
| Surfaces and Learn | `config/cga/surfaces.php` judiciary/case-detail lists the four forms; five authored steps added to surface 38 in `K2_CONTENT_SURFACES.md`, generator re-run |
| Demo command | `PhaseEDemoCommand` panel verdict now records the seated panel size |

Double jeopardy is unchanged: `recordVerdict` still locks a criminal case atomically. The rulings handler refuses a ruling that does not match the filing kind (a motion is granted or denied, evidence is admitted or excluded).

## Passed checks

| Run | Result |
| --- | --- |
| `tests/Unit/CaseLifecycleControlsTest.php`, `AuditChainSmokeTest.php`, `BoardChairWorkflowTest.php`, `JudicialNominationAuthorizationTest.php`, `AdvocateCaseDirectoryTest.php`, `IndividualEndorsementTest.php`, `VoteTypeRegistryTest.php` | OK (86 tests, 1,398 assertions) before the repair pass; the repair pass added one test and re-ran the set green with `EducationNoGateTest` and `TrainingGateTest` (77 tests, 3,098 assertions, 1 pre-existing skip) |
| `tests/js/caseLifecycleControls.test.mjs`, `advocateCaseDirectory.test.mjs`, `judicialNominations.test.mjs` | 14 pass, 0 fail |
| Vite transform `CaseDetail.vue` | 200 |
| `node scripts/education/build_education_payload.mjs` | OK, 0 failures, idempotent |

Review: one implementer, three adversarial reviewers, one repair pass. Two real findings were repaired: the four new forms were missing from the training-gate map (the gate is a per-id map, not a prefix), and a jury verdict could be recorded on a case with no empaneled jury. `tests/Constitutional/DoubleJeopardyTest.php` (a live pin that opens its own PostgreSQL connection) recorded exactly that unlawful jury verdict and now records a lawful panel verdict.

Not run to green here: `tests/Constitutional/CaseLifecycleTest.php` and `Art4Section5Test.php` are full-world live pins that skip without seeded PostgreSQL rows and exceed this box's memory when forced. The live world received no writes (verified: zero rows in three hours).

Reproduce:

```text
docker compose exec -T app php vendor/bin/phpunit tests/Unit/CaseLifecycleControlsTest.php tests/Feature/AuditChainSmokeTest.php
docker exec fc_vite node --experimental-vm-modules --test tests/js/caseLifecycleControls.test.mjs
```

No migration. Pulling hosts need only the code.
