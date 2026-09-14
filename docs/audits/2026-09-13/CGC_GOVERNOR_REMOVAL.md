# CGC governor removal (IO-7)

Date: 2026-09-13. Scope: IO-7. **Closed.** A seated principal of the executive that oversees a common-good corporation requests the removal of a governor; the corporation's creating legislature decides by ordinary majority of all serving members; the seat reopens on its own board and the board composition is refreshed.

## Operator ruling applied

Rubric `cgc-governor-removal-shape` = A. One owner-neutral service path; F-EXE-003 reused with dual-owner handler routing like F-EXE-001; the overseeing executive initiates, the creating legislature consents by ordinary majority; composition refresh on adoption. No new form, no migration.

## What was built

| Piece | Where |
| --- | --- |
| Service | `BoardGovernorService::requestRemoval` resolves owner, board and legislature through `context(ownerOf(board))`: a corporation's requester is a seated principal of `overseen_by_executive_id` and its vote opens in `created_by_legislature_id`; a department keeps its executive check and its jurisdiction chamber. `resolveRemovalVote` uses the owner-neutral resolution, keeps the pending-outcome and seat-status guards, and calls `OrgBoardService::onCompositionChange` after a corporation seat reopens. `departmentOf()` had no other caller and is gone |
| Handler | `BoardMemberRemovalRequest` (F-EXE-003) routes department and organization boards; an organization board gates the actor with `ExecutiveActor::member` against the overseeing executive |
| Route and controller | `POST /organizations/{organization}/governor-removals` → `CgcController::requestRemoval`; `show()` gains `can.requestRemoval` |
| Page | `CgcDetail.vue`: a removal form for the seated principal only, seat picker limited to seated governor seats, grounds, disabled with a reason otherwise; pending removals show through the seat status badge |
| Surfaces and Learn | `surfaces.php` organizations/cgc-detail lists F-EXE-003; one authored step on surface 50 in `K2_CONTENT_SURFACES.md`, generator re-run |

The decision rule stays `procedural_motion` (ordinary majority of all serving). A department's removal vote now opens in the chamber the owner context resolves, which is the same chamber as before in single-chamber jurisdictions and the correct chamber in multi-chamber ones.

## Passed checks

| Run | Result |
| --- | --- |
| `tests/Unit/CgcGovernorWorkflowTest.php` (5 new removal tests), `CgcGovernorSurfaceTest.php`, `CgcThresholdResolutionTest.php`, `AuditChainSmokeTest.php`, `NavRoleGateParityTest.php` | OK (80 tests, 722 assertions; form count 130) |
| `tests/js/cgcGovernorRemoval.test.mjs`, `cgcGovernors.test.mjs` | 12 pass, 0 fail |
| Vite transform `CgcDetail.vue` | 200 |
| `node scripts/education/build_education_payload.mjs` | OK, 0 failures, idempotent |
| `tests/Constitutional/WorkerRepresentationTest.php` | green after the read allowlist gained `Support/OrganizationDirectory.php` (a Codex directory reader from 12 Sep had tripped the static write-guard; it reads the board snapshot for display and writes nothing) |

Covered: a stale outcome after term expiry reopens nothing; a superseded board is refused; adoption reopens the seat only on its own board and refreshes composition; failure restores the seat; the wrong executive, an advisor, a bystander, a vacant seat and an already-pending seat are all refused with no write.

Not run: `tests/Constitutional/GovernorRemovalOrdinaryMajorityTest.php` (live PostgreSQL, skips here); its fixtures are single-chamber, where the resolved chamber is unchanged.

Review: one implementer, three adversarial reviewers, no material finding.

Reproduce:

```text
docker compose exec -T app php vendor/bin/phpunit tests/Unit/CgcGovernorWorkflowTest.php
docker exec fc_vite node --experimental-vm-modules --test tests/js/cgcGovernorRemoval.test.mjs
```

No migration. Pulling hosts need only the code.
