# Committee workspace

13 September 2026. Development and internal testing are complete for committee/hearing testimony pagination and committee preference controls. No live testimony, preference, assignment, or other civic action was performed.

## Completed

| Scope | Result |
|---|---|
| B3: committee and hearing testimony | [CommitteeTestimonyDirectory](../../../app/Support/CommitteeTestimonyDirectory.php) retrieves 50 published entries per page, newest first, with a unique publication-sequence cursor. Older and newer pages remain reachable. The query is scoped to the selected hearing or the committee's meetings before pagination. There is no count query or offset traversal. |
| Hearing and navigation context | Cursors contain the committee and selected-hearing scope. A token from another committee or a different hearing/combined view is refused before database reads. Pagination retains meeting, place and return query parameters. [CommitteeDetail.vue](../../../resources/js/Pages/Legislature/CommitteeDetail.vue) preserves local form state and scroll while paging. Closed-hearing and dissolved-institution filing restrictions remain unchanged. |
| EO-7: preference revision | [Committees.vue](../../../resources/js/Pages/Legislature/Committees.vue) permits an authorized member to update an already-submitted ranking. The saved status remains visible. Inputs and submission pause while a request is processing. The page explains that updates affect future assignments, matching the existing [preference handler](../../../app/Domain/Forms/Handlers/CommitteePreferenceRanking.php). |
| EO-7: assignment with defaults | The permitted assignment control no longer waits for every member to submit. It explains the existing creation-order default. The server's `can.runAssignment` condition remains the visibility gate, and [CommitteeAssignmentService](../../../app/Services/Legislature/CommitteeAssignmentService.php) still owns placement rules and defaults. No constitutional algorithm or authority rule changed. |

## Executed evidence

| Check | Result |
|---|---|
| [CommitteeMeetingContextTest](../../../tests/Unit/CommitteeMeetingContextTest.php), [CommitteePreferenceRevisionTest](../../../tests/Unit/CommitteePreferenceRevisionTest.php), and the pure [CommitteeAssignmentTest](../../../tests/Constitutional/CommitteeAssignmentTest.php) | **21 tests, 138 assertions, no skips.** Explicit private SQLite connections for controller/handler fixtures; pure arrays for assignment. Tests cover page round trips, concurrent append stability, foreign-hearing exclusion, malformed/cross-scope token refusal, optional empty meeting filters, selected-room context, closed-hearing controls, actual preference revision, nonmember/former-member refusal, foreign committee refusal, duplicate rankings, and assignment defaults. |
| [committeeWorkspace.test.mjs](../../../tests/js/committeeWorkspace.test.mjs) and [committeeRoom.test.mjs](../../../tests/js/committeeRoom.test.mjs) | **6 tests passed.** The actual compiled committee Vue component is rendered with synthetic props to verify enabled revision/default assignment, processing states, and server authority gates. Existing room tests retain exact-hearing posting and discussion behavior. |
| Vue compilation | `Committees.vue` and `CommitteeDetail.vue` script, template and styles compiled successfully. No production build was run. |
| Patch checks | `git diff --check` passed for the changed workspace files. |

The PHP fixtures do not connect through `LivePgConnection`, do not run the constitutional engine, and do not publish audit or civic records. The preference test executes the real handler only against its private schema. This evidence closes these development scopes; it does not claim a complete legislative-session rehearsal.

## Concrete remaining B3 history work

The current code in the Legislature, Judiciary, Executive and Jurisdictions controller directories was searched for `limit()`/`take()` and the following remaining history owners were inspected. These are actual missing archive pages, not unperformed checks.

| Remaining owner | Current behavior | Required build |
|---|---|---|
| [SettingsController::changesHistory](../../../app/Http/Controllers/Legislature/SettingsController.php) and [Settings.vue](../../../resources/js/Pages/Legislature/Settings.vue) | Only the latest 50 changes for the jurisdiction reach the changes-history table. No older-page control exists there. | Page the jurisdiction's enactment receipts with stable ordering and retained place context. |
| [AdvocateController::filingRows](../../../app/Http/Controllers/Judiciary/AdvocateController.php) and [AdvocateConsole.vue](../../../resources/js/Pages/Judiciary/AdvocateConsole.vue) | The advocate's filing feed stops after its latest 50 entries. It is scoped to the advocate, but older entries have no page control in that feed. | Page the actor-scoped filing archive while retaining exact case links and existing access rules. |
| [LifecycleController](../../../app/Http/Controllers/Jurisdictions/LifecycleController.php) | Union, disintermediation, border settlement and restoration surfaces each stop at 25 newest records. Union/disintermediation also eagerly load their consent relationships. | Add scoped browsable histories and bound related consent records. This overlaps the existing interjurisdictional build scope; it is not four newly discovered systems. |

Not counted as an archive defect: `ExecutiveController::departmentsSummary` shows five preview cards but provides a full departments link. Its load-all-before-preview query is a separate performance issue, not proof that the sixth department is unreachable.

Other selection caps found during the same search are not classified as histories: bill scope children (200) and judiciary choices (20), challengeable laws (100), executive organization options (200), and jurisdiction lifecycle sibling options (100). The corresponding methods return limited selection lists. They require selection/search review under their action owners; this pass did not alter those controllers. Committee bills and reports are still loaded in full by `CommitteeController::show`; paging that separate workload also remains outside this testimony change.
