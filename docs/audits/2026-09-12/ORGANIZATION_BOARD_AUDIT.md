# Organization board actions

13 September 2026. Code review and isolated fixture evidence for the E3 organization pass. This report covers organization boards only. It does not establish that every institutional scenario is complete.

## Completed repairs

| Repair | Result | Implementation |
|---|---|---|
| Certification authority | An agent cannot certify another organization's election by supplying its ID. Owner and worker filings require the exact selected board and matching election kind before invoking seating. Owner certification also checks that the board belongs to the selected organization. Existing actor checks and seating rules remain authoritative. | [Owner handler](../../../app/Domain/Forms/Handlers/BoardElectionAdministration.php#L98), [worker handler](../../../app/Domain/Forms/Handlers/WorkerBoardElectionAdministration.php#L87). Committed as `d1ec3988`. |
| First ordinary board | The current agent of an active ordinary organization with no board can choose its initial owner/member seats and optional election cycle. Submission uses the existing `provision_board` action of F-ORG-003. It creates no new action type or constitutional rule. | [Controller](../../../app/Http/Controllers/Organizations/BoardElectionController.php#L100), [form](../../../resources/js/Pages/Organizations/BoardElections.vue#L180). |
| Available election actions | Owner/worker scheduling controls require actual vacancies of the appropriate seat class on the selected organization's current, undissolved board. Worker controls also require the existing worker-seat entitlement. The POST still uses the existing engine handlers. | [Capabilities](../../../app/Http/Controllers/Organizations/BoardElectionController.php#L100). |
| CGC board presentation | CGCs no longer show the impossible owner-election action. They retain applicable worker elections. Governor appointment context links to the actual overseeing executive and creating legislature when those records exist. These links are not a CGC nomination form. | [Context links](../../../app/Http/Controllers/Organizations/BoardElectionController.php#L172), [board page](../../../resources/js/Pages/Organizations/BoardElections.vue#L165). |
| Explicit certification | The exact authorized agent can certify the displayed owner/worker election after voting closes or while it is tabulating, provided every race has a complete sealed count. Readiness uses election-scoped existence queries and does not depend on scheduling vacancies. Forms submit the existing track, `certify` action and election ID, with busy/error feedback. The engine retains final authority. | [Readiness](../../../app/Http/Controllers/Organizations/BoardElectionController.php#L293), [owner form](../../../resources/js/Pages/Organizations/BoardElections.vue#L306), [worker form](../../../resources/js/Pages/Organizations/BoardElections.vue#L370). |

Presentation and certification-control changes passed integration checks. A read-only browser check of `'Eua fo'ou Community Health Corporation` displayed its actual overseeing executive, creating legislature and seated governors, without an owner-election scheduling section. No live board was provisioned, election opened or certified, nomination filed or vote cast during this pass.

## Validation

| Targeted run | Result | What it establishes |
|---|---|---|
| Initial [BoardCertificationScopeTest](../../../tests/Unit/BoardCertificationScopeTest.php) run | **15 tests / 79 assertions passed** | Real handler queries on private SQLite memory fixtures. A mocked seating service is never called for other boards, swapped tracks, unauthorized agents or an incorrect organization-to-board pointer. Correct agent and system filings reach the service with the expected election. |
| Final combined [BoardElectionSurfaceTest](../../../tests/Unit/BoardElectionSurfaceTest.php) and [BoardCertificationScopeTest](../../../tests/Unit/BoardCertificationScopeTest.php) run | **26 tests / 174 assertions passed** | Includes the initial scope cases. Real page props check ordinary/CGC capabilities, vacancies, oversight links, other-agent restrictions and provisioning. Certification checks require all races, sealed complete counts and eligible state; another board, another agent, inactive organization, open/final/cancelled elections and missing election selection are refused. Both owner and worker controller payloads and success responses are verified through a mocked engine boundary. |

Both fixture classes explicitly select and verify their own SQLite `:memory:` connection. Neither runs migrations nor accesses the simulated PostgreSQL world. PHP syntax checks passed for all changed PHP files. The modified Vue script and template compiled individually. `git diff --check` passed for the presentation changes. No production frontend build was run.

Commands used:

```text
docker exec fc_app php vendor/bin/phpunit tests/Unit/BoardCertificationScopeTest.php --do-not-cache-result
docker exec fc_app php vendor/bin/phpunit tests/Unit/BoardElectionSurfaceTest.php tests/Unit/BoardCertificationScopeTest.php --do-not-cache-result
```

These checks do not constitute a complete owner/worker election, CGC appointment or board-chair rehearsal. Simulated independent participants remain the required method for that work.

## Remaining gaps verified in code

| Gap | Current behavior and consequence | Evidence |
|---|---|---|
| CGC governor nomination | F-EXE-001 resolves a `Department`, checks that department's executive and nominates onto its board. The service's nomination methods accept `Department`; they cannot nominate onto an organization's CGC board. Sending an attendee to an oversight department's form would nominate to the wrong institution. A CGC appointment path still needs to be built. | [Nomination handler](../../../app/Domain/Forms/Handlers/BoardGovernorNomination.php#L49), [governor service](../../../app/Services/Executive/BoardGovernorService.php#L64), [department route](../../../routes/web.php). |
| Chair ballot and retry | The organization page renders chair tallies and rounds but has no participant ballot or retry control. `castBoardSeat()` provides current-seat, same-board, duplicate-cast and vote-state enforcement, but no application HTTP controller or form handler invokes it. The legislature's floor-vote handler explicitly refuses board votes. A failed chair vote leaves the next ballot to a later action; no participant control exposes that action. | [Chair display](../../../resources/js/Pages/Organizations/BoardElections.vue#L378), [board-seat voting service](../../../app/Services/ChamberVoteService.php#L491), [floor-vote exclusion](../../../app/Domain/Forms/Handlers/FloorVoteCast.php#L59), [chair opening and resolution](../../../app/Services/Organizations/OrgBoardService.php#L269). |
| Backstop timing and scale | The backstop uses an uncompressed 48-hour wait after voting closes. It loads all watched employer subjects and all stalled board elections with `get()`, then silently catches certification failures. This can delay an accelerated demo and consume memory as the world grows. It was inspected, not executed or changed. | [EvaluateCoDeterminationJob](../../../app/Jobs/Organizations/EvaluateCoDeterminationJob.php#L55). |

## Recommended bounded implementation sequence

1. **Connect the board-chair ballot.** Resolve the authenticated person's current seat on the selected board on the server, then use the existing `castBoardSeat()` service. Never accept a caller-supplied seat as authority. Show current candidates and the immutable submitted ranking. Cover another board, removed seat, repeated submission, closed vote and correct completion with independent fixture actors. The service currently records `WF-ORG-05` and explicitly notes the absence of a dedicated ballot form; do not invent a form ID or mislabel this as F-LEG-004. Establish the retry action's authorization separately rather than assuming organization agency authorizes every board action.
2. **Build CGC appointments against the correct institution.** Extend the existing appointment pipeline deliberately to the CGC's board, actual overseeing executive and creating legislature. Preserve the department path and its existing actor checks. Reuse consent votes, civil terms and appointment records; do not route a CGC nomination into a department board. Test cross-institution refusal, nomination, consent, rejection and seating using fixtures before exposing the form.
3. **Make the backstop bounded and observable.** Replace full-world materialization with resumable bounded work, derive bulk processing capacity from the host and report failed items. Review the 48-hour behavior against the existing demo time configuration without changing ordinary-world rules. Validate with synthetic batches and interrupted resumes; do not trigger or reset the operator's simulation.
4. **Rehearse complete organization scenarios with simulated participants.** Cover owner and worker election paths, certification, chair selection, CGC appointments and refusals. The new certification controls still require successful fixture seating and rollback/refusal evidence across a complete scenario. Retain separate evidence for service tests, HTTP actions and browser behavior. A browsable page or completed world seed alone is not proof that these paths work from start to finish.

No human-participant session is required for this sequence. Actual remote transport/device testing remains separate from the simulated civic-action rehearsal.
