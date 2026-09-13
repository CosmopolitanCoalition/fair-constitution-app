# Individual endorsements (EO-5)

Date: 2026-09-13. Scope: EO-5. **Closed.** Individuals can publish and manage their own endorsements. Organization endorsement and secret approval voting remain distinct.

## Operator ruling applied

"Endorsements can be made and withdrawn and remade and rewithdrawn at ANY time by both individuals and organizations." No election-phase window exists on the endorse, withdraw or re-endorse path, for individuals or organizations. The remaining gates are state gates: the candidacy is standing (registered, validated, in pool or finalist), the endorser is a resident of the election jurisdiction (Art. I footprint), and nobody endorses their own candidacy. Recorded in the rubric as `individual-endorsement-rules`.

## What was built

| Piece | Where |
| --- | --- |
| F-IND-025 Individual Endorsement, F-IND-026 Individual Endorsement Withdrawal (roles R-04) | `app/Domain/Forms/Handlers/IndividualEndorsement.php`, `IndividualEndorsementWithdrawal.php`, `FormRegistry` (125 forms) |
| Write primitive | `Endorsement::recordFor` (B6). One logical row per endorser and candidacy; withdraw sets `withdrawn_at` and `is_active=false`; re-endorse toggles the same row. |
| Controller and routes | `CandidacyController::endorse` / `withdrawEndorsement`; `POST /candidates/{candidacy}/endorsement`, `POST /candidates/{candidacy}/endorsement/withdraw` (auth, uuid) |
| Presenter | `CandidacyPanel` returns `can.endorse`, `can.withdraw_endorsement`, `viewerEndorsement` from one bounded row lookup; never computed for a signed-out viewer |
| Page | `PersonProfile.vue` candidacy tab: endorse with a public/private choice (private by default), withdraw, loading/error/success states, no control for the owner, a read-only line for a signed-out viewer |
| Organizations | `CandidateEndorsementGrant` (F-ORG-002) gains `action` = `grant` (default, unchanged), `withdraw`, `re-endorse`; forced public; `OrganizationController::withdrawEndorsement` / `reEndorse`; controls on `OrgDetail.vue` for the agent |
| Surfaces and Learn | `config/cga/surfaces.php` social/profile forms; one authored step in `K2_CONTENT_SURFACES.md` surface 28, generator re-run |

Not touched: `ApprovalService`, the `approvals` table, finalist ranking, `VoteCountingService`. R-07 still derives only from active organization endorsements (`RoleService::hasOrgEndorsedCandidacy` filters `is_active` and `withdrawn_at`).

## Passed checks

| Run | Result |
| --- | --- |
| `tests/Unit/IndividualEndorsementTest.php`, `OrganizationEndorsementWithdrawalTest.php`, `CandidacyEndorsementDirectoryTest.php`, `AuditChainSmokeTest.php`, `BoardChairWorkflowTest.php`, `CountbackUniversalTest.php`, `PhaseBHandlersTest.php`, `VoteTypeRegistryTest.php`, `JudicialNominationAuthorizationTest.php` | OK (99 tests, 1,544 assertions) |
| `tests/js/individualEndorsements.test.mjs`, `orgEndorsementWithdrawal.test.mjs`, `personProfileHistory.test.mjs` | 12 pass, 0 fail |
| Vite transform `PersonProfile.vue`, `OrgDetail.vue` | 200, 200 |
| `node scripts/education/build_education_payload.mjs` | OK, 0 failures, generated files match the source |

Review: one implementer, three adversarial reviewers (constitution and privacy, correctness, UI) on the first build; two reviewers after the ruling was applied. One medium finding (a stale generated meta file) was repaired by regenerating.

Reproduce:

```text
docker compose exec -T app php vendor/bin/phpunit tests/Unit/IndividualEndorsementTest.php tests/Unit/OrganizationEndorsementWithdrawalTest.php tests/Unit/CandidacyEndorsementDirectoryTest.php
docker exec fc_vite node --experimental-vm-modules --test tests/js/individualEndorsements.test.mjs tests/js/orgEndorsementWithdrawal.test.mjs
```

No migration. No world writes. Pulling hosts need only the code.
