# Scoped organization staff delegation (IO-5)

Date: 2026-09-13. Scope: IO-5. **Closed.** An organization's agent grants a person task buckets and revokes them; every organization gate consults one authority at act time; a delegate never gains a constitutional office or the agent's own powers.

## Operator ruling applied

Rubric `org-staff-delegation-model` = A. New form F-ORG-011 Staff Delegation (grant and revoke are audited acts; the form count rises to 131); coarse buckets profile, membership, contracts, documents, hiring, shares; grants persist across an agent reassignment and the new agent sees and can revoke them; reassign_agent and grant/revoke are never delegable.

## What was built

| Piece | Where |
| --- | --- |
| Schema | Migration `2026_09_13_190000_org_staff_grants.php`: `org_staff_grants` with a partial unique index on (organization, grantee, task) while active. Applied on box E (78 ms). |
| The one authority | `OrgDelegationService::mayPerform(organization, actor, task)`: true for the agent, or for a non-agent only with an active grant for the task's bucket on a non-dissolved organization; evaluated inside every handler and service at act time |
| Buckets | `App\Domain\Organizations\StaffTask`: profile, membership, contracts, documents, hiring, shares; never delegable: reassign_agent, dedicate_ip, grant/revoke, board-election administration, ownership transfer, conversion, dissolution |
| Role | R-31 Organization delegate, derived only from active grants, never stored; a pin test proves it appears in no office form's roles and no office surface |
| Form | F-ORG-011 `OrganizationStaffDelegation` (grant_task, revoke_task; agent-only; idempotent; flushes the grantee's role cache) |
| Gates rewired | F-ORG-001 per-action bucket routing (reassign_agent and dedicate_ip stay agent-only), F-ORG-008 shares, `LaborBoardService::assertEmployer` hiring, `OrgEconomyController` shares, `OrganizationController::show` per-task can flags |
| Routes and page | `GET/POST /organizations/{organization}/delegations`, `DELETE .../{grant}`; a Delegation block on `OrgDetail.vue` for the agent (person search, bucket, Grant, active grants with Revoke); delegates see only their bucket's controls |
| Learn | One authored step on surface 49 in `K2_CONTENT_SURFACES.md`, generator re-run |

## Passed checks

| Run | Result |
| --- | --- |
| `tests/Unit/OrgStaffDelegationTest.php` (17 tests) with `OrganizationMembershipReviewTest`, `OrganizationEndorsementWithdrawalTest`, `OrgShareSurfaceTest`, `ShareIssuanceWorkflowTest`, `WorkWorkflowTest`, `FinancialHistoryTest`, `BoardChairWorkflowTest`, `AuditChainSmokeTest`, `RightsAutomaticTest`, `EconomyPrivacyTest`, `EconomyWriteFormsTest` | OK (178 tests, 1,324 assertions; form count 131) |
| `tests/js/orgStaffDelegation.test.mjs`, `orgMembershipReview.test.mjs`, `orgEndorsementWithdrawal.test.mjs` | 11 pass, 0 fail |
| Vite transform `OrgDetail.vue` | 200 |
| `node scripts/education/build_education_payload.mjs` | OK, 0 failures, idempotent |

Covered: grant, idempotent re-grant, revoke, a delegate acting inside and outside its bucket, no delegate can reassign the agent or grant, a non-agent cannot grant, a grant revoked between page render and submit is refused at act time, a dissolved organization refuses, grants persist across reassignment and the new agent revokes them, hiring and shares honour the buckets, R-31 is in no office form or surface.

Incident: because the main tree is the live bind mount, the new role derivation went live before its table existed and every signed-in page failed for a few minutes until the migration was applied. Worktree lanes do not have this exposure.

Review: one implementer, three adversarial reviewers, repair as needed.

Reproduce:

```text
docker compose exec -T app php vendor/bin/phpunit tests/Unit/OrgStaffDelegationTest.php
docker exec fc_vite node --experimental-vm-modules --test tests/js/orgStaffDelegation.test.mjs
```

Pulling hosts: apply the migration together with the code (the role derivation reads the table on every filing), then refresh workers.
