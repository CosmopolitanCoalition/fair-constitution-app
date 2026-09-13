# Judicial appointment surface

Date: 2026-09-13. Scope: EO-4. **Confirmation controls and their internal tests are complete. Player nomination still requires the authority decision below.** No live civic actions, world resets or production frontend build were performed.

## Completed confirmation development

| Behavior | Evidence |
| --- | --- |
| Browse a court's nominations | `JudicialConfirmationDirectory` replaces the unbounded nomination load with 20-row seek pages. Nominee names use the civic public-name contract, with public profile references for repeated names. Private social names and legal-name fallbacks are not exposed. |
| Read the correct records | Each row shows its nominating constituent or committee, validated public dossier, nomination status and the appointment's own term. Historical terms are not inferred from a newer seat occupant. Malformed linked consents are omitted with a visible explanation. |
| Cast confirmation | `JudicialConfirmations.vue` uses the existing `ConsentVoteCard` and `/votes/{vote}/cast`. Controls require a current member of the exact source legislature, its valid current per-seat appointment and an open recorded consent. Existing casts, foreign members, stale appointments and the Speaker do not gain ordinary voting controls. |
| Resolve a tie | The exact current Speaker can submit yes/no through `/votes/{vote}/tiebreak` only for the recorded resolvable tied consent. The existing F-SPK-004 engine seats or rejects the nominee. No threshold is recomputed in the UI. |
| Prevent stale seating/rejection | `JudicialSeatService::assertConsentVote` verifies the current nomination, seat, court, exact source chamber and recorded vote before `ChamberActService` changes appointment status. A final vote on a replaced nomination rolls back without overwriting the replacement seat. Direct repeated seating also refuses to create another term. |
| Preserve simulator output | The setup simulator's separate slate path still stages, opens, votes, seats and returns zero on repeated seating. Its closed bench consent is readable on the court page without individual vote controls. |
| Preserve navigation | Confirmation pages load their own Inertia props and preserve other filters/cursors. Loading, empty, error and retry states are visible. The user sees a role preview or their public display name and source-chamber context. |
| Connect institution acts | In coordination with EO-3, the court page links to `/legislatures/{exact source}/institution-acts?action=create-court` and `?action=elect-court`. No source legislature is guessed for a stub without that reference. |

The old confirmation text incorrectly claimed the creation supermajority also governed confirmation. The replacement and the dedicated Learn citation now describe the existing `bog_consent` majority-of-all-serving implementation. Voting law and term settings were not changed.

## Passed internal checks

**Root integration completed later on 13 September:** the combined 117 PHP tests / 1,731 assertions and 20 compiled Vue tests passed; both directory indexes were applied and verified valid, workers refreshed, and five actual confirmed nominees read in the existing court's browser page. See [integration receipt](INTEGRATION_AND_MAP_CLEANUP.md). The earlier subtask's unapplied-migration statement below is superseded; the nomination decision remains open.

| Run | Result |
| --- | --- |
| `JudicialConfirmationSurfaceTest` plus DB-free `TermLockstepTest` | **25 tests / 493 assertions passed**, 29.937 s, 24 MB. Real court controller, judicial nomination service, F-LEG-004, F-SPK-004, consent effects, public records, civil term and clock on explicit SQLite `:memory:`. Settings, audit transport and global role lookup are doubles; exact chamber membership is real. Includes configured seven-year seating, refusal, rejection/renomination, Speaker yes/no outcomes, duplicate refusal, 43-record forward/back paging, private-name exclusion, malformed records and actual setup slate seating. |
| Added real final-vote stale-seat regressions | **2 tests / 16 assertions passed**, 2.641 s, 18 MB. Both all-yes and all-no closing votes refuse a replaced nomination and roll back the final cast and decision; the current seat pointer stays unchanged and no term opens. |
| `tests/js/judicialConfirmations.test.mjs` | **5 tests passed**. Compiled Vue lifecycle checks cover public profile links, exact vote URLs, ordinary consent refusal/retry/success, retained independent pagination state, loading/retry feedback, public role preview, readable closed slates, unavailable records, both Speaker choices and retained explanation. Judiciary Home compiles with its shared component and EO-3 links. Requests are synthetic, not sent to the running world. |
| Patch integrity | `git diff --check` passed. |

Every initial and subsequent nomination reader query is explicitly scoped to one court and requests at most 21 rows, including lookahead. An internal query-log assertion confirms confirmation-only visits do not recompute header counts. Related identities, votes, dossiers and terms are loaded only for the returned page.

The additive migration `2026_09_13_102000_judicial_confirmation_directory_index.php` creates concurrent partial indexes for `(judiciary_id, id DESC)` and `appointment_id`, with invalid-index recovery. It has **not been applied by this subtask**. No PostgreSQL throughput measurement is claimed by these SQLite tests.

Reproduction:

```text
docker exec fc_app php vendor/bin/phpunit tests/Unit/JudicialConfirmationSurfaceTest.php tests/Constitutional/TermLockstepTest.php
docker exec fc_vite node --experimental-vm-modules --test tests/js/judicialConfirmations.test.mjs
```

## Remaining EO-4 nomination decision

This is a concrete missing player entry point, not a failing confirmation test or a human-test deferral.

| Established in code | Missing authority definition |
| --- | --- |
| `JudicialSeatService::nominate` accepts only the seat's allocated constituent jurisdiction. It validates active association with the court jurisdiction as the nominee's sole eligibility requirement. | No runtime role identifies the constituent's nominating agent. The method accepts an optional user ID for attribution and does not authorize that user. No controller or form handler invokes it for players. |
| `committeeNominate` fills committee-nominated seats. Its comment says a passed committee-supermajority vote gates it upstream. `ChamberVoteService` already supports committee electorates and supermajority votes. | No judicial nomination proposal/decision handler performs that upstream step. No court field or committee field designates which committee is the judicial committee. Arbitrary committee names and purposes are not an authority assignment. |
| Each accepted nomination opens an individual `bog_consent` vote in the source legislature. Existing F-LEG-004 and F-SPK-004 perform voting; the civil appointment service opens the configured term. | `F-LEG-021` deliberately names this consent vote and remains unregistered in `FormRegistry`. Registering it as a new nomination/cast handler would contradict the existing form contract. |

Proposed player contract for the operator to settle:

1. A current member of the **exact constituent legislature** proposes a nominee for that constituent's allocated seat. A recorded decision by that legislature authorizes the nomination, which then enters the existing separate source-legislature confirmation. The choice of this actor/body and its nomination-decision threshold are not defined by current code.
2. For committee-mode courts, the source legislature **explicitly designates its judicial committee**. A member or chair of that exact committee proposes the nominee; a recorded committee supermajority authorizes `committeeNominate`, followed by the existing source-legislature confirmation. The designation method and proposer role still need to be settled; neither is inferred from a committee's name.

No player nomination POST, broad resident picker, new role, eligibility requirement, allocation rule or term rule has been introduced while this decision remains open. Once settled, the remaining implementation is the authorized nomination proposal/decision entry point, its bounded nominee selector and isolated end-to-end actor tests. The existing confirmation work above can be reviewed independently.

## Changed files

- `app/Http/Controllers/Judiciary/JudiciaryController.php`
- `app/Support/JudicialConfirmationDirectory.php`
- `app/Services/Judiciary/JudicialSeatService.php`
- `app/Services/Legislature/ChamberActService.php` (judicial consent guard call only)
- `config/cga/surfaces.php` (one confirmation citation)
- `resources/js/Pages/Judiciary/Home.vue`
- `resources/js/Components/Judiciary/JudicialConfirmations.vue`
- `database/migrations/2026_09_13_102000_judicial_confirmation_directory_index.php`
- `tests/Unit/JudicialConfirmationSurfaceTest.php`
- `tests/js/judicialConfirmations.test.mjs`
