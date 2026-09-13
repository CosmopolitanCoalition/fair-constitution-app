# Demo build punch list

Updated 13 September 2026. **This list contains confirmed missing features and repairs only.** Each row includes the internal test required to finish that build. Checking whether something works is tracked separately; it is not itself a build item.

[Completed work and passed checks](DEMO_COMPLETED_WORK.md) · [Pending internal checks](DEMO_REVIEW_REGISTER.md) · [Human-dependent checks](DEMO_DEFERRED_CHECKS.md) · [Deployment handoff](NEXT_SESSION_HANDOFF.md)

Finish the current build before starting the next. Consolidation comes first, then the remaining institutional actions and supporting features. Rehearse the completed flows with simulated participants before the final language/accessibility review. Setup must then produce both a walkable demo and a player-ready beta. Performance repairs accompany the affected feature.

## Progress since the instruction to work through both lists

Fixed baseline: commit `8bf59184`, immediately before this development batch. Closed means development and required internal tests passed; partial review passes do not count as a closed review.

| List | At baseline | New confirmed items | Closed | Remaining |
|---|---:|---:|---:|---:|
| Build punch list | 29 | 4 | 13 | 20 |
| Internal review register | 29 | 0 | 2 | 27 |

The thirteen closed builds are B3, P2, B4, EO-6, LE-1, EO-7, B5, EO-1, E5, IO-6, EO-8, EO-2 and EO-3. The two closed reviews are S3 ownership concurrency and L1 lesson completion/awards/stipends. Added builds are B5 and EO-8 (already closed), B6 and IO-7, each tied to a demonstrated defect or missing action. EO-4 confirmation is integrated, but its nomination entry remains open and the item is not counted as closed. The separately requested map-sidebar cleanup is completed extra work, outside this fixed baseline. These are item counts, not a percentage of effort or a cost forecast. Earlier completed work remains in the archive and is excluded from this fixed-baseline comparison.

## 1. Candidacy-tab performance within the shared public profile

| ID | Confirmed build / repair | Done when development and internal tests establish |
|---|---|---|
| B6 | Bound the shared public profile's candidacy standings, endorsements and public-web expansion; recognize existing endorsement type spellings. | One profile does not materialize its whole race, all endorsers or their election-wide web. Independent pages and selected-person expansion preserve all public records and individual privacy; existing simulated endorsements remain visible without duplicate logical endorsements. There remains one public profile per person, with all candidacies and offices as sections of it. |

Evidence: [candidate profile readers](../2026-09-13/CANDIDATE_PROFILE_READERS.md). This newly identified consumer is separate from the completed open-ballot directory. CGC appointments are complete. Repair this reader alongside EO-5 controls after the current election lifecycle repairs.

The separately requested public-history repair is completed: older civic actions, published documents and past offices are reachable through the same profile. See [public history](../2026-09-13/PERSON_PUBLIC_HISTORY.md). That repair does not close B6's remaining standing/endorsement expansion work or add another punch-list row.

## 2. Complete the institutional action paths

| ID | Confirmed build / repair | Done when development and internal tests establish |
|---|---|---|
| EO-4 | Add the player judicial nomination entry after resolving the nominating actor/body and committee designation. Confirmation, refusal and seating controls are completed. | The settled nomination authority is enforced through actual proposal/decision handlers and bounded nominee selection; isolated actor tests reach the existing confirmation/seating path. See the [remaining nomination decision](../2026-09-13/JUDICIAL_APPOINTMENT_SURFACE.md#remaining-eo-4-nomination-decision). |
| EO-5 | Add individual endorsement and withdrawal controls. | Individuals can publish/manage their own endorsements; organization endorsement and secret approval voting remain distinct. |
| IO-1 | Add court hearing, deliberation and verdict controls. | Authorized actors can complete the case lifecycle through the UI, with state, panel, actor and repeated-submission checks. |
| IO-2 | Build the appeals workflow beyond its existing status/foreign key. | Appeal filing, review and outcome history work while preserving the original case and criminal reprosecution protection. |
| IO-3 | Connect constitutional findings, recommendations, legislative response/override and remedy application. | The existing judicial and legislative handlers are reachable from the tracker; each outcome preserves law versions and refuses premature or repeated application. |
| IO-4 | Add organization membership review and agent reassignment controls. | The actual agent can page pending applications, accept/decline and transfer agency; unrelated users cannot. |
| IO-7 | Add CGC governor removal through its actual overseeing executive and creating legislature. | The CGC removal action targets its current board/holder and follows the existing authorized removal decision rules; stale outcomes cannot revive expired seats or affect another institution. |
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
