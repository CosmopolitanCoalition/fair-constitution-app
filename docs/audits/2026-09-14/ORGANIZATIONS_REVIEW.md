# Organizations review lane (S1 · rollover, appeals, organization authority, organizations/economy, jurisdictions, consistency)

Date: 2026-09-14. Run on branch `review/orgs` (worktree `.wt/orgs`, base `aee71e9a`), each row built by one implementer, refuted by two reviewers (pass criterion; isolation), repaired where a finding held, committed per row, merged into main as `8c365fd9`. Every journey ran on a private SQLite in-memory named connection or the DB-free compiled-Vue runner; no PostgreSQL fixture was needed (the code under review carries no PostgreSQL-only construct on these paths) and the world database was never touched. No protected file was edited. No product defect was found. One coverage boundary is recorded and enforced in code (consistency row).

## S1 · rollover (commit d05d3c53). Passed.

`tests/Unit/SecondTermRolloverJourneyTest.php`: OK (5 tests, 100 assertions).

Real CertificationService (general turnover, executive and judicial recurring cycles, elected-office retirement), ExecutiveFormationService turnover close, VacancyService, ClockService, RoleService seat queries and ElectionCertificationReconciliationService. A general certification retires the outgoing members and their terms; the real RoleService seat queries return false for every departed officer and true for the retained person's new member row on a new term; the Speaker pointer clears, the committee returns to created with vacated seats, the delegated executive member leaves, the open Speaker ballot voids; a standing elected executive and elected court are retired by the turnover. Recurring executive and judicial cycles linked to the general cycle retire the prior principals and seat successors with the inherited expiry; the EO-2 unique index refuses a duplicate recurring election per cycle. The corrected count keeps the original term window and successor, preserves four members, displaces one through the vacancy service and seats the new winner at the displaced seat with inherited expiry.

Scope: service-level journey; the F-ELB-004 engine path is the elections row; the audit chain is doubled here.

## S1 · appeals (commit b2f92a6f). Passed.

`tests/Unit/AppealWorkflowTest.php`: OK (12 tests, 76 assertions). `tests/js/appealWorkflow.test.mjs`: 5 of 5.

The existing IO-2 journey files already carry the register's checks: a party appeal through the real engine and AppealFiling and OpinionRuling handlers preserving the original case and verdict, the review and outcome history, wrong-actor and wrong-state refusals, the criminal reprosecution refusal, and bidirectional navigation on the real CaseDetail page (the original lists its appeal, the appeal links its original with the en banc note). No new file was needed. Plan flag: the plan quoted 89 tests for the PHP file; it holds 12.

## S1 · organization authority (commit 288706b8). Passed.

`tests/Unit/OrgStaffDelegationTest.php`: OK (18 tests, 328 assertions), one new combined journey.

Four distinct actors through the real handlers and the real OrgDelegationService rail: apply, accept (F-ORG-001), grant the hiring task (F-ORG-011), the delegate opens a posting through the real authority check, reassign-agent refused as never delegable, an out-of-bucket action refused with no write, revoke, the post-revoke posting refused at act time, a fresh membership grant, agency transferred to a third agent with the grant surviving, the new agent revokes. Staff grants confer no office power: the delegate's roles derive through RoleService with no office and an office form refuses.

## S1 · organizations/economy (commit 6b54b84a). Passed.

`tests/Unit/BoardOwnerElectionSeatingTest.php` OK (3 tests, 27 assertions); `tests/Unit/HireAgreementJourneyTest.php` OK (2 tests, 37 assertions); `tests/Unit/AssistanceWorkflowTest.php` OK (14 tests, 146 assertions); with `BoardChairWorkflowTest` and `ShareIssuanceWorkflowTest` together OK (86 tests, 633 assertions).

The board election is certified through the real engine (F-ORG-003), counted by the protected VoteCountingService STV core and seated by the real OrgBoardSeatingService on active organization-cycle terms; the chair ballot is the existing real F-ORG-010 RCV journey. The hire is the true two-consent chain: F-IND-019 application (market surface only), F-IND-014 worker registration through the real LaborBoardService, and the F-ORG-001 organization countersign through the real engine activating the contract and worker rows. Help runs on the real ledger through completion and through withdrawal recovery with no value moved. Share issuance reaches the correct recipient through the real F-ORG-008 handler and OrgOwnershipService. Wrong actor and institution, stale and duplicate actions and rollback refuse or unwind. Plan flags: the plan named counter methods that do not exist (the real API is `countStv`/`countRcv` over a CountInput) and described F-IND-019 as the hire; the code truth is recorded above.

Not established here: the co-determination headcount chain (pinned by `tests/Constitutional/WorkerRepresentationTest.php` on a live-PostgreSQL helper), audit-chain hashing and PostgreSQL row locks (SQLite journeys).

## S1 · jurisdictions (commit e0cee6c4). Passed, two operator flags recorded.

`tests/Unit/InterjurisdictionalDoorsTest.php`: OK (18 tests, 113 assertions), two new. `tests/js/interjurisdictionalDoors.test.mjs`: 13 of 13, two new.

One synthetic tree walks union formation (applicant referendum count, constituent consents, finalization), disintermediation with the law-merge resolution row asserted afterwards, affected-area border consent and judicial restoration in sequence; refusal and recovery paths and the older-history return paths hold; the places pages keep the ancestor trail and legislative-map link across deeper page swaps. Materializing a process row does not establish consent (fixture property test plus a read of `FederationSyncService::ingestTail`); a live two-node ingest is the deferred Mesh join row.

Flags for the operator, not fixed: the union and border referendum meters are a seated-member yes-count recorded by the owning body (`UnionService::markApplicantReferendum`, `BorderSettlementService::recordReferendum`), not a ballot engine; the world is pre-unioned under Earth, so union formation is lawful but not staged for the demo.

## S1 · consistency (commit a69468a3). Passed on the swept surfaces; DOM-level acceptance boundary recorded and enforced.

`tests/Feature/NavRoleGateParityTest.php`: OK (4 tests, 14 assertions). `tests/js/consistencySweep.test.mjs`: 11 of 11.

Every navigable surface resolves to a served GET route (the app's own NAV_ALLOWLIST honoured for the deferred translations board). Four real page components (Judiciary Home, CaseDetail, OrgDetail, UnionFormation) plus the compiled Btn and Banner atoms are driven through in-flight and error states and hold the shared loading, error, disabled, return-path and accessible-interaction conventions.

Reviewer finding confirmed and made enforceable: 41 S1 action-door pages exist; 14 carry a compiled prop-driven companion, 27 do not (Executive has none). The partition is pinned by `test_the_S1_action_door_ui_acceptance_boundary_is_declared_and_enforced` so it cannot grow silently. The row stays in the register narrowed to the 27 uncovered pages; browser-level keyboard, focus, contrast and narrow-layout checks belong to the L2 browser pass.

## Verification on merged main

| Run | Result |
| --- | --- |
| Eight lane PHP files on main `8c365fd9` | OK (76 tests, 841 assertions) |
| Three lane JS files on main | 29 of 29 |
