# Legislative and executive review lane (S1 · elections, S1 · legislature, S1 · executive/CGC)

Date: 2026-09-14. Run on branch `review/leg` (worktree `.wt/leg`, base `aee71e9a`), each row built by one implementer, refuted by two reviewers (pass criterion; isolation), repaired where a finding held, committed per row, merged into main as `c446f2af`. Every journey ran in its own nonce-guarded disposable PostgreSQL database (`cga_<domain>_YYYYMMDD_<16hex>`, refusal when `public.organizations` exists, statement and lock timeouts set, dropped in a `finally` block); the world database was never a fixture (`live_world_used:false` on every run). No protected file was edited. No product defect was found; the two inline fixes were coverage gaps inside the elections journey file.

The three journeys drive the real ConstitutionalEngine form handlers and the real ChamberVoteService, VoteCountingService counting core, CertificationService, EnactmentService (advisory-lock act numbering), SessionService, BillService, CommitteeService, CommitteeAssignmentService, ExecutiveFormationService, DepartmentService, BoardGovernorService, CivilAppointmentService, ExecutiveOrderService with the ConstitutionalValidator scope shield, CgcService and RoleService. Only peripheral collaborators are doubled (AuditService, SettingsResolver, ClockService, AchievementService, and in the elections row ReferendumService and the successor-cycle methods of ElectionLifecycleService).

## S1 · elections (commit 7f0c72d9). Passed after two coverage repairs.

`tests/concurrency/election_journey_fixture.php --run`: exit 0, `all_pass`, deterministic on repeated runs.

Participants: three resident candidates, one nonresident, one wrong-jurisdiction resident, a Type B candidate, ten voters plus two child-jurisdiction voters, one organization with an agent, one board member. Order: F-IND-011 registration; F-CAN-002 endorsement request then F-ORG-002 grant (organization endorsements are public); F-IND-025 individual endorsement, F-IND-026 withdrawal and re-endorsement on the same row; approvals cast, one revoked and recast; finalist cutoff with frozen standings; F-IND-007 ranked ballots (ten on the Type A race, two on the Type B race); close; STV tabulation by the protected counter (quota 4, two elected); F-ELB-004 certification of both races. Seating: Type A members seat type a, the Type B winner seat type b, terms on a fresh 60-month window, candidacies flipped to elected or defeated.

Reviewer findings repaired: (1) the countback and special-election continuation was first proven from two primitives called directly; the journey now drives the orchestration owner for both branches (a countback that fills the vacated seat with inherited expiry, and the special-election fallback when no countback replacement exists). (2) Role state was asserted from member and term rows only; the journey now derives roles through `RoleService::rolesFor` for both chamber types.

Refusals held as real constitutional violations: nonresident candidacy, wrong-jurisdiction candidacy, wrong-race ballot, duplicate ballot, late ballot, duplicate candidacy, wrong-agent grant, self-endorsement, approval after cutoff.

Deliberate shortcut, documented in the file: tabulation input is pre-sealed rankings (the BallotBox decrypt and the voter cryptographic receipt are not exercised; the double-vote barrier stays live). Board candidate validation (F-ELB-002) is applied as a fixture action rather than filed.

## S1 · legislature (commit ab604698). Passed.

`tests/concurrency/legislature_journey_fixture.php --run`: `all_pass`, failures 0.

Committee assignment through the real preference and allocation flow; chair selection by committee RCV with the committee electorate; attendance by members and the Speaker; agenda; a failed quorum, then compel, recount and the session reopening; committee-stage votes through the F-LEG-005 door; a bicameral floor vote where one chamber refuses and the act fails, then a passing dual-agreement vote; the bill carried by `BillService::resolveBillVote` into `EnactmentService::enact`, which writes the Law and LawVersion v1 under the act-number lock; Speaker tie-break (F-SPK-004); committee meeting lifecycle (F-CHR-001/002/005/006) with the post-adjourn refusal. Refusals held: outsider attendance, non-Speaker agenda, non-member chair ranking, non-chair meeting call, reconvene after close, non-Speaker tie-break.

Known contract, not a defect: F-CHR-006 closes a committee meeting permanently with no reconvene door; session-level resume is the failed-quorum path.

## S1 · executive/CGC (commit 361cdd6f). Passed.

`tests/concurrency/executive_cgc_journey_fixture.php --run`: exit 0, `all_pass`.

Delegated executive formation (F-LEG-014/016, five principals, creation act, R-14 derived); a department chartered with a board and one vacant governor seat and a due periodic report; governor nominated, consented (F-LEG-020 bog_consent) and seated on a ten-year civil-appointment term (R-18 derived, department operating); the department report filed (F-BOG-002); a CGC chartered by F-LEG-019 driven to adoption (created by the legislature, overseen by the executive) with its own board; an executive order (F-EXE-005) issued inside the enabling law's scope through the validator's scope shield. Refusals held: cross-institution actions, former-officer actions after a real term end, out-of-scope orders.

Plan flags (documentation): the campaign plan named the order form F-EXO-001; the registry form is F-EXE-005, which the journey drives.

## Verification on merged main

| Run | Result |
| --- | --- |
| Three disposable-PostgreSQL journeys | exit 0 each in the worktree at the merged code |
| Full DB-free unit suite | run at the campaign gate, recorded in the handoff |
