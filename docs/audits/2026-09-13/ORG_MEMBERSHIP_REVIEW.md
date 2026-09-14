# Organization membership review and agent reassignment (IO-4)

Date: 2026-09-13. Scope: IO-4. **Closed.** The organization's agent pages pending applications, accepts or declines each one, and transfers agency to another person. Unrelated users cannot.

## Operator ruling applied

Rubric `org-membership-agent-rules` = A. The built engine behaviour stands: any registered user may be named the new agent; a declined application is terminal for that row and a fresh application is allowed; the transfer is unilateral. No new form, no migration.

## What was built

| Piece | Where |
| --- | --- |
| Routes | `POST /organizations/{organization}/memberships/{membership}/decision`, `POST /organizations/{organization}/agent` (auth, uuid) |
| Controller | `OrganizationController::decideMembership` (binds the membership to this organization, refuses a cross-organization id, files F-ORG-001 `accept_member` or `decline_member`) and `reassignAgent` (files F-ORG-001 `reassign_agent`); `show()` emits `pendingMembers` and `agentSearch` only for the agent |
| Page data | `app/Support/OrgMembershipReviewDirectory.php`: bounded 20-row cursor page of applied memberships ordered by applied_at then id, scoped token (a cross-scope cursor is refused). `app/Support/OrgAgentDirectory.php`: a public-name search for the new agent with no jurisdiction filter (any registered user, per the ruling), excluding the current agent, never the legal name |
| Page | `OrgDetail.vue`: an Applications block (public name, profile link, kind, applied date, Accept / Decline per row with busy and feedback, its own pager), an Agent block (current agent, bounded search, Reassign with a line stating the transfer is immediate and unilateral); nothing for non-agents |
| Learn | One authored step on the organization surface in `K2_CONTENT_SURFACES.md`, generator re-run |

The engine layer was already complete: `OrganizationProfileManagement` refuses any actor who is not this organization's agent, and `OrgMembershipService` refuses any decision on a row that is not applied.

## Passed checks

| Run | Result |
| --- | --- |
| `tests/Unit/OrganizationMembershipReviewTest.php`, `OrganizationEndorsementWithdrawalTest.php`, `AuditChainSmokeTest.php`, `IndividualEndorsementTest.php` | OK (48 tests, 297 assertions; form count 130) |
| `tests/js/orgMembershipReview.test.mjs`, `orgEndorsementWithdrawal.test.mjs` | 7 pass, 0 fail |
| Vite transform `OrgDetail.vue` | 200 |
| `node scripts/education/build_education_payload.mjs` | OK, 0 failures, idempotent |

Covered: paging 43 applications as 20/20/3 with no overlap; accept stamps `accepted_by`; decline then a fresh application succeeds; a second decision on the same row, a non-agent, the agent of another organization and a cross-organization id are all refused with no write; reassignment moves `agent_user_id` and the manage capability with it; a non-agent's props carry no applicant identities.

Review: one implementer, three adversarial reviewers, no findings.

Reproduce:

```text
docker compose exec -T app php vendor/bin/phpunit tests/Unit/OrganizationMembershipReviewTest.php
docker exec fc_vite node --experimental-vm-modules --test tests/js/orgMembershipReview.test.mjs
```

No migration. Pulling hosts need only the code.
