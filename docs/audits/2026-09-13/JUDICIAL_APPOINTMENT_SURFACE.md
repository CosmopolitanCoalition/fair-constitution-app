# Judicial appointment surface

Date: 2026-09-13. Scope: EO-4. **EO-4 is complete: nomination, committee designation, confirmation and seating are implemented and internally tested.** No live civic actions, world resets or production frontend build were performed.

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

**Root integration completed later on 13 September:** the combined 117 PHP tests / 1,731 assertions and 20 compiled Vue tests passed; both directory indexes were applied and verified valid, workers refreshed, and five actual confirmed nominees read in the existing court's browser page. See [integration receipt](INTEGRATION_AND_MAP_CLEANUP.md). The earlier subtask's unapplied-migration statement below is superseded; the nomination decision was subsequently settled and implemented below.

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

## Settled nomination authority and completed implementation

The operator settled all four decisions on 13 September:

| Path | Who proposes | Authorization | Next step |
|---|---|---|---|
| Constituent nomination | Any current member of that constituent’s legislature | Ordinary majority of all serving members of that legislature | Separate confirmation in the legislature that created the court |
| Committee designation | A current member of the court’s creating legislature selects an existing committee, or creates one through the existing committee process first | Recorded legislative supermajority act | The designated committee may nominate |
| Committee nomination | Any serving member of the designated committee, including its chair | Committee supermajority | Separate confirmation in the creating legislature |

`JudicialNominationService` implements these as `ChamberVoteProposal` records, using the existing voting engine and its configured thresholds and bicameral lanes. F-LEG-037 files nomination proposals; F-LEG-038 files committee designations. F-LEG-021 remains the separate consent contract, unregistered as a filing handler. Actual casts use the existing floor/committee forms and Speaker tie endpoint. The existing court seat allocation, nominee association requirement, simulation slate path and configured term service are preserved.

The existing court page now has vacancy selection, public-name/profile-reference nominee search, committee designation, proposal decisions and confirmation history. All selectors/history pages use independent 20-row seeks. Search begins only after input; it reuses the existing privacy-safe nominee directory. Every nominee links to the same `/people?who=…` public profile. Loading, empty, failure and retry states are visible; previewing a role grants no filing authority. The dedicated Learn content records the settled process.

Exact committee, court, legislature, seat, nominee and vote bindings are checked. Identical filing retries reuse the proposal. Changed committee designations or intervening nominations invalidate earlier pending proposals; an invalid final cast rolls back. Rejected confirmation leaves the allocated seat vacant for a fresh proposal. No existing committee is silently assigned by migration.

The real committee fixture exposed a shared voting defect: unicameral committee members retained `type_a` seat kinds but the vote expected the `all` tally. The repair uses the vote’s stored lane structure after membership verification; bicameral votes retain separate kind lanes.

### Final internal evidence

- **61 targeted PHP tests / 1,618 assertions passed**, covering the actual filing controllers/forms, nomination authorization, separate confirmation and configured seven-year seating; majority of all serving; failed committee/designation votes; a configured 3/4 supermajority; bicameral lanes; former/foreign actors; dissolved courts; committee reassignment; stale competing proposals; duplicate filings; corrupted vote links; privacy and independent 43-row directory traversal. Existing judicial confirmation, governor selector, vote registry and immutable term checks are included.
- **Nine compiled Vue tests passed** across nomination and confirmation controls: exact submissions, profile links, committee/seat/person selection, preview restrictions, configured tally display, independent pagination, busy states, errors and retries.
- The **disposable PostgreSQL migration probe passed** with 3,000 synthetic rows in each indexed collection. All four selected-scope plans used their intended indexes. The migration was rerunnable and preserved existing records and a prior proposal-kind extension. The nonce-guarded fixture database was removed. This is not a planet-throughput or concurrent-vote benchmark.
- Migration `2026_09_13_150000_judicial_nomination_authorization.php` was applied locally. Configuration and routes were refreshed and the existing Horizon worker restarted. Pulling hosts must apply this additive migration, refresh configuration/routes and restart their existing workers. Never reset an existing world.
- Browser verification: the existing Superior Court page rendered the new nomination workspace, its Oversight/Budget/Rules committee choices, public preview controls, and its five existing confirmed judges and their full-profile links. No nomination, designation or vote was filed against the live world.

Reproduce the new checks with the explicit isolated fixtures:

```text
docker exec fc_app php vendor/bin/phpunit tests/Unit/JudicialNominationAuthorizationTest.php tests/Unit/JudicialConfirmationSurfaceTest.php tests/Unit/CgcGovernorSurfaceTest.php tests/Constitutional/VoteTypeRegistryTest.php tests/Constitutional/TermLockstepTest.php
docker exec fc_vite node --experimental-vm-modules --test tests/js/judicialNominations.test.mjs tests/js/judicialConfirmations.test.mjs
docker exec fc_app php tests/concurrency/judicial_nomination_migration.php --run
```

EO-4 is removed from the build punch list. The broader formation-to-appointment-to-conversion rehearsal remains in the internal review register; no full review item is closed by this targeted build.
