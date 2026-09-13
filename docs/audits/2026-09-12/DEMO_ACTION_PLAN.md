# Demo build punch list

Updated 13 September 2026. **This list contains confirmed missing features and repairs only.** Each row includes the internal test required to finish that build. Checking whether something works is tracked separately; it is not itself a build item.

[Completed work and passed checks](DEMO_COMPLETED_WORK.md) · [Pending internal checks](DEMO_REVIEW_REGISTER.md) · [Human-dependent checks](DEMO_DEFERRED_CHECKS.md) · [Deployment handoff](NEXT_SESSION_HANDOFF.md)

Finish the current build before starting the next. Consolidation comes first, then the remaining institutional actions and supporting features. Rehearse the completed flows with simulated participants before the final language/accessibility review. Setup must then produce both a walkable demo and a player-ready beta. Performance repairs accompany the affected feature.

## 1. Consolidation and bounded browsing

| ID | Confirmed build / repair | Done when development and internal tests establish |
|---|---|---|
| B3 | Bound the remaining committee bills/reports and advocate case/composer collections. | Entry and selection load bounded pages with every record reachable; independent history navigation preserves forms. Committee testimony, settings changes and advocate filings are complete. Interjurisdictional history remains with S2. |

Evidence for the remaining B3 readers: [committee inventory](../2026-09-13/COMMITTEE_WORKSPACE.md), [advocate archive inspection](../2026-09-13/CIVIC_HISTORIES.md). Completed P2, B4, EO-6 and LE-1 have moved to the archive. Large-race performance and full workflow acceptance remain internal reviews, not claims of completion.

## 2. Complete the institutional action paths

| ID | Confirmed build / repair | Done when development and internal tests establish |
|---|---|---|
| E5 | Add CGC governor nomination and legislative consent against the CGC's own board. | Its actual overseeing executive and creating legislature complete nomination, consent and seating; wrong-institution actions refuse and department appointments still work. |
| IO-6 | Complete CGC governor expiry and replacement consequences. | The armed civil-term timer retires the correct organization-board seat and enters its replacement path. Repeated expiry is safe; department/judicial expiry stays intact. |
| EO-1 | Repair legislative rollover for committee membership and speaker state. | Old committee seats/chairs and speaker pointers retire; retained committees can allocate seats to the incoming legislature. |
| EO-2 | Finish recurring elected executive/judicial cycles and outgoing-seat closure. | Successor elections are scheduled, old elected seats lose authority when their terms end, and certified successors replace them consistently. |
| EO-3 | Replace generic bill links for institution creation/conversion with real institution filings. Include CGC creation. | The UI collects and submits the intended creation/conversion/delegation payload to its actual handler, with authority and refusal cases. |
| EO-4 | Add judicial nomination and confirmation controls. | Authorized court/legislature actors can nominate, consent/refuse and seat judges through the existing services. |
| EO-5 | Add individual endorsement and withdrawal controls. | Individuals can publish/manage their own endorsements; organization endorsement and secret approval voting remain distinct. |
| IO-1 | Add court hearing, deliberation and verdict controls. | Authorized actors can complete the case lifecycle through the UI, with state, panel, actor and repeated-submission checks. |
| IO-2 | Build the appeals workflow beyond its existing status/foreign key. | Appeal filing, review and outcome history work while preserving the original case and criminal reprosecution protection. |
| IO-3 | Connect constitutional findings, recommendations, legislative response/override and remedy application. | The existing judicial and legislative handlers are reachable from the tracker; each outcome preserves law versions and refuses premature or repeated application. |
| IO-4 | Add organization membership review and agent reassignment controls. | The actual agent can page pending applications, accept/decline and transfer agency; unrelated users cannot. |
| IO-5 | Add scoped organization staff delegation. | Task-specific grant/revoke controls enforce the selected organization's permissions without granting constitutional office powers. |
| S2 | Complete interjurisdictional actions and lifecycle history pagination. | Union, disintermediation, border settlement and restoration have actual proposal/consent/completion controls, scoped history beyond 25, and refusal/recovery coverage. |

Evidence: [board audit](ORGANIZATION_BOARD_AUDIT.md), [election and institutional checks](../2026-09-13/ELECTION_OFFICE_CHECKS.md), [interjurisdictional/setup audit](SETUP_AND_SCENARIO_AUDIT.md). E4 board-chair participation is completed and has moved to the archive.

## 3. Education management and achievement integration

| ID | Confirmed build / repair | Done when development and internal tests establish |
|---|---|---|
| LE-2 | Build educational material management and persistent publication. | Authorized editors can save/manage lessons and publish retrievable content through the existing publication boundary; it does more than return an audit payload. |
| LE-3 | Associate lessons/surfaces with multilingual videos. | Learn can play the relevant library material with its audio/subtitle choices; absent media has useful feedback. The player and its caption/error repairs already exist. |
| AC-1 | Wire the broader achievement catalog to actual civic/economic actions and state changes. | Successful actions award once, rejected/rolled-back actions do not, and earned items appear under profile privacy rules. Update stale catalog availability flags after their triggers work. |

Evidence: [education/achievement checks](../2026-09-13/EDUCATION_ACHIEVEMENT_CHECKS.md). The existing profile tab, catalog, lesson registry and video selectors are recorded as implemented and checked where evidence supports it. Do not rebuild them. Full language, lesson accuracy and accessibility checking is in the review register; demonstrated failures become specific repairs here.

## 4. Setup, mesh and scale

| ID | Confirmed build / repair | Done when development and internal tests establish |
|---|---|---|
| G1 | Implement real world-readiness verification and Step 5 completion guards. | Bounded verification records required artifacts and unresolved scopes. Step 5 cannot claim readiness without them. Demo and beta outcomes cover usable institutions, representatives, ownership structures, chairs and scenario prerequisites. |
| G2 | Repair simulation resume enumeration. | Stable scanned-row/cursor progress reaches missing later cohorts even when earlier pages were already inserted; repeat resumes do not skip or duplicate work. |
| G3 | Bound expensive preparation, progress polling and board backstops. | Whole-world preparation/polling statements and eager backstop loads become bounded/resumable work with saved progress. Failures are visible; demo timing uses configured clocks. Host-derived capacity and apportionment rules are preserved. |
| M3 | Preserve identity during Linux/Windows join reruns. | Ordinary update/resume retains APP_KEY and federation identity and resumes the current membership without reusing a consumed join key. Explicit clone re-key remains separate. |
| M4 | Use the resolved Compose project throughout Linux bootstrap. | Deployment and subsequent registration target the same project, including custom and previously configured project names; failures cannot report success. |
| M5 | Bring up join progress while foundation transfer runs asynchronously. | The node serves its UI while resumable sync proceeds, preserves admission state and recovers interruptions without replaying completed pages. |
| M6 | Bound foundation import finalization and progress totals. | Authority stamping and completion verification run in bounded pages/checkpoints; total progress does not require repeated world-table scans. Mirrors claim no authority. |

Evidence: [setup/scenario audit](SETUP_AND_SCENARIO_AUDIT.md), [mesh/setup checks](../2026-09-13/MESH_SETUP_CHECKS.md). The public Matrix state/configuration repairs M1/M2 and term-creation repair Q1 are archived after their targeted passes; full fresh-install and two-node acceptance remain internal checks.

All tests use isolated fixtures, queues and rooms. Step 5/Dev sequences may seed those fixtures; do not rerun, reset or revert the existing completed world. Move each completed build immediately to the archive. A remote-host dependency is an internal check dependency, not a human-only deferral.
