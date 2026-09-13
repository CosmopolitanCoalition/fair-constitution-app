# Election and office implementation checks

Checked 2026-09-13. This records code inspection and internal tests. It does not claim a complete browser journey. No live world data, elections, offices, queues, or simulation controls were changed.

Follow-up: the initial term-writer test failure is now repaired and passing. The Q1 completion evidence is below. Legislative rollover (EO-1) remains open.

## Completed internal checks

| Scope checked | Result and evidence | Limit |
|---|---|---|
| Proportional ranked counting | PR-STV/Droop/Gregory arithmetic, conservation, deterministic transfers, and weighted-versus-individual ballot identity passed in `StvDroopGregoryTest` and `WeightedBallotIdentityTest`. | Counting inputs were synthetic. This is not a ballot-to-office acceptance test. |
| RCV and vacancy countback | `RcvTest` and `CountbackUniversalTest` passed. Single-winner transfer behavior, advisor derivation, and countback against original ballots are implemented. | Vacancy declaration, subsequent special election, and browser action paths were not exercised together. |
| Election lifecycle guards | `ElectionClockTest` and `PhaseBHandlersTest` passed. Clock routing, forward phase transitions, special-window bounds, registered handlers, and pure input guards are present. | Registration, approval, cutoff, tabulation, and certification persistence were read but not exercised as one isolated journey. |
| Ballot cryptography | Five explicitly selected pure `BallotSecrecyTest` methods passed: commitment/receipt, payload encryption, key wrapping, ranking validation, and publication-root ordering. | The database commit/decrypt/double-vote method was deliberately excluded because it selects the live PostgreSQL connection. |
| Committee allocation core | `CommitteeAssignmentTest` passed. Preferences, normalized vote-share tie resolution, even placements, bicameral partitions, and exhaustion fallback work for synthetic input. | The rollover and UI defects below prevent marking the full committee workflow complete. |
| Election and legislature navigation | Private in-memory `ElectionNavigationTest` and `LegislatureWorkspaceTest` passed. Place selection and scoped histories are preserved. | This does not establish equal UI quality across all institution pages. |
| Judiciary formation rules | Nine explicitly selected pure `JudiciaryCreationConversionTest` methods passed. Creation/conversion thresholds, appointed default, derived nomination mode, equal constituent allocations, term-setting parity, and same-row court evolution are checked. | Live creation, nomination, confirmation, and conversion cases were excluded. |
| CGC settings presentation | `CgcThresholdResolutionTest` passed. The existing CGC surface resolves amendable co-determination thresholds. | CGC creation and appointments remain separate workflows. |

Test evidence:

- The nine-class election/office group ran **60 tests, 51,171 assertions**, with **one failing source-whitelist test** documented below. The other 59 tests passed.
- RCV, countback, and CGC settings ran **14 tests, 98 assertions**, all passed.
- Selected pure ballot cryptography ran **5 tests, 19 assertions**, all passed.
- Selected pure judiciary checks ran **9 tests, 44 assertions**, all passed.
- Total: **88 executed tests; 87 passed; 1 failed; 51,332 assertions**. No skipped or unrun case is counted as a pass.

Each execution supplied `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`. The navigation tests additionally construct named private in-memory connections and their own minimal schema. The executed mixed-class methods were enumerated with `--filter`; none calls its class's live-PostgreSQL helper.

## Existing implementation verified by reading code

| User's question | What exists |
|---|---|
| Candidate registration | `CandidacyController::store` submits `F-IND-011`; `CandidacyRegistration` checks the approval window and residency attestation. Candidate profiles use the consolidated person-profile tab. |
| Organization endorsements | `EndorsementRequest` and `CandidateEndorsementGrant` implement request, authorized organization decision, standing-candidate guard, and public endorsement creation. `OrgDetail.vue` exposes grant/decline. |
| Approval voting | `ApprovalController` and `ApprovalService` expose cast/revoke, election binding, private individual records, and public aggregate standings. |
| Ranked ballots and closing | `BallotController` submits `F-IND-007`, binds the race to the election, and gives a single-pull receipt. Lifecycle phase jobs advance closing and dispatch tabulation. |
| Certification and legislative promotion | `ElectionResultsCertification` invokes `CertificationService`. General certification closes outgoing legislative members, creates new member/term records, arms the next general cycle, and opens successor approval. Special/countback replacements inherit the original expiry. |
| Committee creation and preference filing | `CommitteeCreationAct`, `CommitteePreferenceRanking`, and `CommitteeAssignmentAdministration` have routes and UI. The preference handler explicitly permits resubmission and supplies defaults for non-submitters at assignment. |
| Executive and judicial appointments | Formation, nomination, consent, and seating services exist. Their presence does not make their current browser entry points functional; see defects below. |

## Confirmed build and repair needs

| ID | Required repair | Direct evidence |
|---|---|---|
| EO-1 | Complete legislative term rollover for committees and speaker state. Retire outgoing committee seats/pointers and make existing committees available to the incoming legislature's allocation. | `CertificationService::turnOverChamber` closes legislative members and delegated executives but does not reset committee records or `speaker_id`. `CommitteeAssignmentService::run` accepts only `Committee::STATUS_CREATED`, while prior committees remain seated. `SpeakerService` chooses election versus replacement solely from the non-null speaker pointer. |
| EO-2 | Complete recurring elected executive/judicial cycles and close outgoing elected seats consistently with their terms. | `ScheduleGeneralElectionJob` and successor creation schedule only general legislative elections. All calls to `scheduleExecutive`/`scheduleJudicial` originate in conversion services. General chamber rollover completes all active lockstep terms, including these offices, but leaves elected executive/judicial seat rows seated. `RoleService::hasExecutiveSeat`, `hasExecutiveAdvisorSeat`, and `hasJudicialSeat` derive authority from those seated rows without checking the completed term. Executive certification closes only delegated-era rows; judicial certification closes only appointed-era rows. |
| EO-3 | Replace institution creation/conversion links with actual institution filings and inputs. Include the CGC creation action. | Executive and judiciary home links carry `subject`/institution query parameters into `/legislature/bills`. `Bills.vue` consumes only `intro` and `setting`; `BillController::store` always files `F-LEG-003` and supplies no institution payload. The specific formation handlers therefore do not run from these links. `CgcService::proposeCreation` exists, but no corresponding user creation route/controller was found. |
| EO-4 | Build judicial nomination and confirmation controls on the court/legislature surfaces. | `JudicialSeatService` implements nominations/consent. `Judiciary/Home.vue` shows a nomination record and explanation only. `JudiciaryController` has a GET surface and no nomination action. Routes contain no judicial nomination/confirmation POST. |
| EO-5 | Add individual endorsement creation/withdrawal and disclosure controls. | `Endorsement` supports `endorser_type=user`; profile/ballot presenters read those rows, and simulation creates them. The application write path found is `CandidateEndorsementGrant`, which forces organization type. No individual endorsement route/controller or user action exists. Secret approval votes are separate and must stay separate. |
| EO-6 | Move open-ballot directory paging and search to the server. | `ApprovalController::standingsFor` loads all candidacies, users, and endorsements of the selected race. `show` then truncates the fully materialized collection in PHP, and `full=1` disables even that output cap. `ApprovalService::standings` also returns the full race snapshot. |
| EO-7 | Remove conflicting committee preference UI gates while retaining server authority. | `Committees.vue` locks the ranker after the first submission (`prefsLocked`) although the handler allows resubmission. Its assignment button requires every member to submit (`allSubmitted`) although the service supplies defaults for non-submitters. A non-submitting simulated member can therefore block the only normal UI assignment action. |

## Internal reviews still to perform

These are review-register entries, not build items until a defect is demonstrated. They are not deferred human checks.

- Run an isolated multi-actor registration → organization endorsement → approval/revocation → finalist cutoff → ranked ballots → close → tabulate → certify journey. Assert invalid actor/race/window rejection and the resulting member/term/role state.
- Run a second legislative term after EO-1/EO-2 repairs. Include a retained person with a new member record, departed officers, committees, delegated executives, and an elected executive/court.
- Exercise committee creation, amended preferences, assignment, and chair selection through real HTTP sessions after EO-7.
- Exercise executive formation, CGC formation/governors, and judicial nomination/confirmation after their missing action doors are built.
- Compare UI behavior, navigation context, loading/errors, and accessible interaction for these journeys; source presence alone is not visual acceptance.

Historical `ElectionStageTest`, `SeatingStageTest`, and database sections of `JudiciaryCreationConversionTest` explicitly select live PostgreSQL. They were not run against the existing world and are not evidence of an isolated acceptance pass.

## Institutional office follow-up

The following additional checks cover case flow, constitutional law editing, and organizational authority. No live case, law, membership, or delegation was changed.

### Additional completed internal checks

| Scope checked | Result | Limit |
|---|---|---|
| Delegated executive selection | Seven explicitly selected pure `ExecDelegationProportionalityTest` methods passed: **7 tests, 40 assertions**. Size bounds, normalized-share selection, determinism, reuse of committee allocation, and ex-officio provenance are pinned. | Live executive-formation methods were excluded. |
| Court panel sizing | `PanelSizingTest` passed: **5 tests, 1,184 assertions**. Odd panel sizes, minimums, severity progression, capacity, and full-court handling are checked. | No panel roster was written. |
| Criminal reprosecution guard and challenge wiring | Selected `DoubleJeopardyTest` and `Art4Section5Test` methods passed: **3 tests, 18 assertions**. The pure double-jeopardy guard, condition-free challenge filing, form registration, and remedy clock wiring are checked. | Persisted cases and the three remedy outcomes were not run. |

These add **15 passed tests and 1,242 assertions**. The initial review executed **103 tests: 102 passed, 1 failed, 52,574 assertions**. The initial failure is resolved in the Q1 follow-up below.

### Existing implementation checked by reading code

| Area | Existing code and UI |
|---|---|
| Case opening and court actions | `DocketController` and `CaseController` provide case filing, acceptance/dismissal, jury orders, opinion publication, sentencing, warrants, and advocate filings. `CaseDetail.vue` has corresponding forms. `CaseService` stores the case lifecycle and verdict records; `PanelService` and `JuryService` implement panel/jury mechanics. |
| Court live-room floor | `RoomFloorService` scopes presiding controls to a seated case-panel judge and supports the witness position. This controls the speaking floor. It does not advance case hearing, deliberation, or verdict state. |
| Constitutional law editing | `ConstitutionalChallengeService` records findings and recommendations and arms both response windows. `JudicialRemedyService::applyRemedy` locks the challenge/law, refuses before both windows expire, and applies a versioned modify/remove remedy. `ChallengeController`/`Art4Section5Tracker.vue` display the resulting record and differences. |
| Organization authority | `OrganizationProfileManagement` checks the actor against the specific organization's agent. It supports profile changes, agent reassignment, membership decisions, contract countersigning, document packages, settings, and IP dedication. `OrgMembershipService` stores application/acceptance/decline/end states. Membership classes are ownership classes, not staff permission grants. |

### Confirmed additional build needs

| ID | Required build/repair | Evidence |
|---|---|---|
| IO-1 | Add authorized court controls for opening the hearing, entering deliberation, and recording the verdict, with state/actor validation and public outcome feedback. | `CaseService::advanceToHearing`, `enterDeliberation`, and `recordVerdict` exist, but repository-wide call-site searches found only `PhaseEDemoCommand` application callers. `CaseController` has no actions for these steps, and `CaseDetail.vue` has no corresponding controls. Existing forms cannot finish a case through the browser. |
| IO-2 | Build the appeals workflow, preserving the original case/verdict and the criminal reprosecution protection. | `CourtCase` declares `STATUS_APPEALED` and `appeal_of_case_id`; no appeal service, handler, route, or user form was found. The dev judiciary kit explicitly labels wider-panel appeal re-entry a deferred surface. A status and foreign key are not a working appeals process. |
| IO-3 | Connect constitutional finding, remedy recommendation, legislative response/override, and explicit remedy application to authorized UI actions. | `F-JDG-004/005/006` and `F-LEG-035` handlers exist. `ChallengeController` exposes only the initial challenge POST. The tracker displays record cards, windows, and results; searches of routes/controllers/Vue found no filing endpoints for the judicial actions or opening the override. The existing automatic clock remedy cannot begin until a finding and recommendation exist. |
| IO-4 | Add organization membership review and agent reassignment controls. | `OrganizationProfileManagement` has `accept_member`, `decline_member`, and `reassign_agent` actions. `OrganizationController` exposes applications but no corresponding decision/reassignment POST. `OrgDetail.vue` shows the viewer's membership and aggregate members, but no pending-member review list or decision controls. |
| IO-5 | Build scoped organization staff delegation with grant/revoke controls and enforcement. | Organization management checks a single `agent_user_id`. `OrgMembership` stores member/shareholder/partner class and lifecycle only. No task-specific grant model/service/controller was found. Existing employee membership, board authority, and whole-agent reassignment do not provide scoped delegation to several staff. The new controls must use an explicit permitted-task model and may not silently confer constitutional office powers. |
| IO-6 | Complete CGC governor expiry dispatch as part of the CGC appointment lifecycle. | Simulated CGC civil terms have `office_kind=board_seat`. `CivilTermExpiryJob` handles `board_governor` and `judicial_seat`; its default records no seat transition. `BoardGovernorService::expireGovernorTerm` requires a department board and cannot handle an organization/CGC board. The Q1 repair supplies the configurable expiry and timer, but the CGC lifecycle still needs the matching completion/renomination consequence. |

### Remaining internal checks for the review register

- Isolated multi-actor civil and criminal case journeys through HTTP, including panel conflicts, juror screening, verdict/sentencing refusal cases, and closed-case behavior after IO-1.
- All three constitutional challenge outcomes with version history, expired-window refusals, and an overridden or legislatively remedied challenge that cannot be applied again.
- Real actor-bound membership decisions, agent transfer, and scoped delegation revocation after IO-4/IO-5.
- Appeal acceptance/refusal and replacement outcome history after IO-2.

`CaseLifecycleTest` and the persisted scenarios in `Art4Section5Test`/`DoubleJeopardyTest` select live PostgreSQL. None was executed. These unperformed checks belong in the internal review register, not the completed or human-deferred sections.

## Completed Q1 follow-up: term-writer inventory and simulated term creation

The previously failing term-writer inventory is repaired. `JudicialSeatService::seatSlateOnAdoption` creates civil terms with the resolved `judicial_appointment_years`, preserves appointment provenance, and arms CLK-09 for each new term. It does not update existing expiry dates. The inventory now explicitly includes that writer.

Closer inspection of `SimBoardService` found two actual defects alongside its missing inventory entry: CGC governors always received ten years, and civil expiry timers were not armed. The service now resolves `civil_appointment_years` for the jurisdiction. A bounded transaction per seat creates the term, fills the seat, and arms CLK-09. Failure to arm rolls back the new term and seat. Business board terms retain their own configured month cycle.

`SimBoardTermTest`, `TermLockstepTest`, and `ElectionClockTest` passed together: **14 tests, 414 assertions**. These checks cover a seven-year CGC term, exact expiry timer payload/date, repeat-call idempotency, an eighteen-month organization cycle without a civil timer, and rollback when expiry arming fails. The full write-once `ends_on` scan and immutable-clock checks still run without exceptions for either new writer.

This completes the Q1 writer-inventory/term-creation repair, formerly EO-8. It does not close legislative rollover or the separately identified CGC expiry dispatch (IO-6). No existing world terms were rewritten, and no simulation command ran.
