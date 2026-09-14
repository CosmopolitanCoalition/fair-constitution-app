# Interjurisdictional actions and history paging (S2)

Date: 2026-09-13. Scope: S2. **Closed.** Union, disintermediation, border settlement and restoration now have proposal, consent and completion controls; each history pages beyond 25 rows on its own scoped cursor; refusal and recovery paths are covered.

## Operator ruling applied

Rubric `interjurisdictional-doors-s2` = A. Constituent consent reuses the generic constituent-consent chamber-vote arm: the consent door opens a peg-quorum vote in the constituent legislature, the generic dispatch records the consent, and the process finalizes itself only when both meters are met (new `union_processes` and `disintermediation_processes` branches in `ExecutiveFormationService::resolveConstituentConsentVote`). The border settlement opens from the Between Governments page as a between-governments act. Restoration gains declare, confirm, tier, complete and abandon, and the tier-3 defect is fixed. Operator note honoured: this world is pre-unioned under Earth, so union formation is built lawful and not staged for the demo; the union page says so.

## What was built

| Piece | Where |
| --- | --- |
| Routes | 14 POST doors on `LifecycleController` (union applicant-referendum, consent, finalize; disintermediation encompassing-consent, consent, finalize; border propose, referendum, adopt; restoration declare, confirm, tier, complete, abandon) |
| Owning-body gates | The applicant referendum is recorded only by a chamber of an applicant jurisdiction; the affected-area referendum only by a chamber of an affected jurisdiction; encompassing consent only by the encompassing chamber (guard in `DisintermediationService::recordEncompassingConsent`) |
| Safe completion | `UnionService::maybeFinalize` and `DisintermediationService::maybeFinalize` apply the change only when both meters are met and leave the process open otherwise; a premature border adopt is refused before `adopt()` so a pending settlement is never marked rejected; finalize on a resolved process refuses cleanly |
| Restoration | `RestorationService::advanceTier` tier-3 fix; `complete()` → restored, `abandon()` → abandoned, each with the WF-JUR-07 audit append; confirm reads the judicial finding from the tied review case, never from input |
| Paging | `app/Support/LifecycleHistoryPager.php`: 25-row cursor pages on (created_at, id) with distinct cursor names and scope-bound tokens; a cross-scope token is refused |
| Pages | `UnionFormation.vue`, `Disintermediation.vue`, `BetweenGovernments.vue` (no longer read-only), `Restoration.vue`: controls gated on the capabilities the controller emits, disabled with a reason otherwise, `HistoryPager` on each, feedback states |
| Learn | Authored steps for the doors in the K-2 source (`K2_CONTENT_WAVE2.md`), generator re-run |

No new form (count stays 131). No migration.

## Passed checks

| Run | Result |
| --- | --- |
| `tests/Unit/InterjurisdictionalDoorsTest.php` (16 tests), `AuditChainSmokeTest.php`, `RestorationJudicialReviewTest.php` | OK (34 tests, 134 assertions) |
| `tests/js/interjurisdictionalDoors.test.mjs` (11), `hostNavigation.test.mjs` | 12 pass, 0 fail |
| Vite transform, the four pages | 200 |
| `node scripts/education/build_education_payload.mjs` | OK, 0 failures, idempotent |

Review: one implementer, three adversarial reviewers, one repair pass. The reviewers caught three real gate breaches in the first build (an ungated encompassing-consent door, a finalize that marked a live process failed on a premature press, referendum meters recordable by any seated member); all were repaired and pinned by four added tests.

Flag, not fixed (pre-existing): the applicant and affected-area referendum outcomes are recorded as a yes-vote count by a seated member of the owning body; no ballot engine feeds these two meters yet. Recorded for the review register (S1 · jurisdictions).

Three live-PostgreSQL constitutional pins (exec conversion, exec delegation proportionality, judiciary creation/conversion) print only passing dots on this box and are then killed by the container's memory limit at shutdown, so no summary line exists here.

Reproduce:

```text
docker compose exec -T app php vendor/bin/phpunit tests/Unit/InterjurisdictionalDoorsTest.php
docker exec fc_vite node --experimental-vm-modules --test tests/js/interjurisdictionalDoors.test.mjs
```
