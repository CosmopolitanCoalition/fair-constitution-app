# Appeals workflow (IO-2)

Date: 2026-09-13. Scope: IO-2. **Closed.** A party to a decided or sentenced case files an appeal; the appeal is a new linked case heard by the parent court, or by the same court en banc; the appellate panel records the outcome; the original case and the criminal reprosecution bar are preserved.

## Operator ruling applied

Rubric `appeals-workflow-rules` = B. Criminal verdicts are appealable on error, but the appeal may only affirm or vacate (acquit), never order a new trial. Double jeopardy stands: `cases.double_jeopardy_locked` never lifts and a new criminal filing for the same act is still refused. Civil appeals may affirm, reverse or remand. The original case moves to `appealed` and nothing else on it changes.

## What was built

| Piece | Where |
| --- | --- |
| F-IND-027 Appeal Filing (a party to the original case) | `app/Domain/Forms/Handlers/AppealFiling.php`; `FormRegistry` (130 forms) |
| The appeal case | `CaseService::openAppeal`: the original moves decided or sentenced → appealed through the state machine; a new `cases` row with `appeal_of_case_id`, the original kind, title "Appeal of <docket>", its own lifecycle, distinct record and audit strings (Appeal filed, `case.appealed`) |
| The appellate court | The parent judiciary; the same court en banc when there is no parent or the parent is not operating. A same-court appeal seats the full odd bench with `is_en_banc` (`PanelService`), a parent-court appeal seats a normal severity-scaled panel |
| The outcome | The appeal panel's F-JDG-003 opinion carries `appeal_outcome`, accepted only on an appeal case and only from the lawful list for the kind; it closes the appeal and records the effect on the original as a public record without editing the original's verdict, opinion or sentencing rows |
| Route and page | `POST /cases/{case}/appeals`; `CaseDetail.vue` appeal section (party control with grounds, disabled with a reason otherwise), appeal links on the original, the "Appeal of" link and en banc note on the appeal, the kind-limited outcome select on the opinion form |
| Schema | Migration `2026_09_13_170000_appeal_outcomes.php`: nullable `opinions.appeal_outcome`, partial index on `cases(appeal_of_case_id)`, lock_timeout guard, concurrent build with invalid-index recovery. Applied on box E (437 ms, index valid). |
| Surfaces and Learn | `surfaces.php` judiciary/case-detail lists F-IND-027; three authored steps in `K2_CONTENT_SURFACES.md` surface 38, generator re-run; the two "deferred surface" notes replaced |

Also: `CaseService::allocateDocketNumber` takes its advisory lock only on PostgreSQL (sqlite fixtures have none; the unique index remains the backstop; Postgres behaviour unchanged).

## Passed checks

| Run | Result |
| --- | --- |
| `tests/Unit/AppealWorkflowTest.php`, `CaseLifecycleControlsTest.php`, `AuditChainSmokeTest.php`, `BoardChairWorkflowTest.php`, `TrainingGateTest.php`, `EducationNoGateTest.php`, `JudicialNominationAuthorizationTest.php`, `AdvocateCaseDirectoryTest.php` | OK (89 tests, 3,176 assertions, 1 expected live-pin skip) |
| `tests/js/appealWorkflow.test.mjs`, `caseLifecycleControls.test.mjs`, `advocateCaseDirectory.test.mjs` | 15 pass, 0 fail |
| Vite transform `CaseDetail.vue`, `JudiciaryKit.vue` | 200, 200 |
| `node scripts/education/build_education_payload.mjs` | OK, 0 failures, idempotent |
| `php artisan migrate --force` on box E | `2026_09_13_170000_appeal_outcomes` DONE; column present, index valid, zero invalid indexes |

Review: one implementer, three adversarial reviewers, one repair pass. Two real findings repaired: a no-parent appeal could be seated on a severity-scaled sub-panel instead of the full court (now forced en banc), and the migration's column add ran before the lock_timeout guard. The desk added the non-operating-parent fallback and corrected the registry's count comments.

Flag, not fixed: the docstring at `app/Services/ConstitutionalValidator.php:1349-1352` still says a criminal verdict is final. Under ruling B a criminal verdict can be vacated on appeal (never re-prosecuted). The validator's behaviour is correct; the wording of a protected file is the operator's to change.

Reproduce:

```text
docker compose exec -T app php vendor/bin/phpunit tests/Unit/AppealWorkflowTest.php tests/Unit/CaseLifecycleControlsTest.php
docker exec fc_vite node --experimental-vm-modules --test tests/js/appealWorkflow.test.mjs
```

Pulling hosts: run the additive migration, then refresh workers as usual.
