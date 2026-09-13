# CGC governor appointments and expiry — E5 / IO6

Development and targeted internal verification completed on 2026-09-13. This evidence covers the appointment/consent/civil-term/expiry backend and its actual nomination-to-reader contract. The companion frontend work owns the board workspace, nominee directory, route and component checks.

## Completed implementation

`BoardGovernorNomination` now accepts exactly one of `department_id` or `organization_id` through the existing **F-EXE-001** engine handler. The organization route derives its owner and jurisdiction; the handler independently rejects mismatched jurisdiction, missing overseer, malformed nominee IDs, ambiguous owner targets and invalid/oversized dossiers. It resolves the actor's seated membership on the exact overseeing executive. The existing department payload remains supported. No new form or vote-counting rule is introduced; F-LEG-020 remains the consent meaning of a `bog_consent` vote, cast through F-LEG-004.

`BoardGovernorService::nominateCgc` shares the existing nomination machinery and checks the current persisted owner and board. A CGC must have `is_cgc=true`, `type=common_good_corp`, active status/flag, and no dissolution date. Its non-deleted board must be forming or active and agree with both the organization's current `board_id` and the board's morph owner. The creator is the exact `created_by_legislature_id` in the same jurisdiction, with active/forming status. The overseer is the exact `overseen_by_executive_id`, in the same jurisdiction and delegated/elected/conversion-voted status. A governor vacancy has no current holder, appointment or term. Nominee eligibility remains an existing, non-deleted user with an active jurisdiction association; no professional, partisan or educational qualification was added.

The exact target executive membership must also carry `role=principal`, matching `RoleService::hasExecutiveSeat` for R-14/R-15/R-16. Global authority from another executive cannot turn an advisory seat on this executive into appointment authority. Both department and CGC nominations enforce this at the service boundary; the department removal request uses the same exact-target principal guard.

The dossier publishes against the exact appointment, and its consent vote opens in that CGC's **creating legislature**, rather than the first legislature found by jurisdiction. `ChamberActService::resolveConsentVote` now invokes a governor-only check before either adopted or rejected outcomes change appointment state. The appointment must still be nominated by F-EXE-001 without a term, still own its nominated governor seat, and name that exact closed floor `bog_consent` vote. The vote's appointment reference, body, legislature, jurisdiction and outcome must all match. A replaced nomination cannot clear a newer nomination or seat its nominee. Other appointment types retain their existing dispatch paths.

Adopted consent calls the existing `CivilAppointmentService` to create the civil term and arm CLK-09. The configured `civil_appointment_years` value is resolved for the owner jurisdiction; existing `ends_on` values are never changed. The department operating transition remains in the department branch. CGC seating invokes the existing `OrgBoardService::onCompositionChange`, which clears obsolete chair state and replaces stale chair ballots with the current electorate's election when enough members are seated.

`CivilTermExpiryJob` now recognizes both the normal `board_governor` kind and the older simulated CGC `board_seat` kind. A dispatched expiry must carry a due, fired CLK-09 timer for a term in the same jurisdiction. The governor service then locks and validates the exact active civil term, due date, office/seat IDs, holder, current owner/board and governor class. The legacy `board_seat` alias applies only to CGC governor seats, not private or worker terms. Expiry completes the term, ends its current appointment, retains the historical seat/holder/term links, creates one new vacant governor seat, flushes the holder's derived-role cache and invokes the shared CGC chair hook. The operation is transactional and repeated delivery is a no-op.

Expiry also handles a department governor whose removal vote is still pending. A later result for that ended seat cannot restore it or create another vacancy. This is a lifecycle guard, not a change to the existing ordinary-majority removal rule.

## Internal test evidence

| Executed check | Result |
| --- | --- |
| Private CGC workflow + private simulated-board term checks + DB-free constitutional regressions | **70 tests / 853 assertions passed**, including actual nomination-to-reader acceptance and target-principal nomination checks. Command below. |
| Final exact-target principal removal guard | **2 tests / 16 assertions passed** for the advisor-on-target / principal-elsewhere refusal and successful department nomination/consent/expiry with a late removal result. This rerun follows the final removal guard addition. |
| Final CGC workflow including actual Speaker tie decisions | **43 tests / 397 assertions passed** after formatting. The focused actual nomination-reader and tied yes/no cases passed **3 tests / 61 assertions**. |
| PHP syntax checks | Governor service, nomination handler, expiry job and narrow chamber consent edit passed. |
| Formatting / diff | New workflow test formatted with targeted Pint; owned paths pass `git diff --check`. |

The combined command was run inside `fc_app` with `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`:

```text
php vendor/bin/phpunit tests/Unit/CgcGovernorWorkflowTest.php tests/Unit/SimBoardTermTest.php tests/Constitutional/TermLockstepTest.php tests/Constitutional/ElectionClockTest.php tests/Constitutional/PegQuorumTest.php tests/Constitutional/BicameralDualAgreementTest.php --colors=never
```

The new fixtures explicitly create a named private SQLite connection and assert that it uses `:memory:`. They invoke the real constitutional engine, F-EXE-001 handler, F-LEG-004 casts, chamber tally/outcome/consent dispatch, public-record service, civil-term writer, clock arm/fire and expiry job. They substitute audit transport, global role/training/settings lookup and unrelated service dependencies; queue transport is faked so no worker consumes fixture work.

Covered behavior:

- A simulated three-member creating legislature approves by the existing majority threshold. A seven-year configured term and exact CLK-09 deadline are created; no term exists before consent. There are two legislature rows for the jurisdiction, and the second/exact creator receives the consent vote.
- Expiry completes the original appointment, keeps its immutable end date, voids the stale chair ballot and opens exactly one vacancy. Repeating the job/service does not add rows or publications. A replacement nomination and actual consent create a distinct seat/term with its own configured end date.
- Failed consent reopens its seat. Replaying rejection of an older nomination cannot clear its replacement.
- Nineteen invalid owner/member/seat/nominee cases reject without appointments, chamber votes or publications. Additional malformed/ambiguous/context/association/dossier cases also reject. An explicit globally-authorized principal elsewhere / advisor on the target executive fixture cannot nominate for either target type or request department governor removal.
- Eleven stale-consent cases verify both adopted and rejected dispatch cannot affect replaced seats, foreign creator/vote metadata, wrong forms/stages or already-linked terms.
- A stale owner discovered during the final actual consent cast rolls back that cast, publication and seat/term changes. Correcting the owner and retrying seats once.
- An injected expiry side-effect failure rolls back term, appointment and vacancy writes; a later retry succeeds once.
- A department still proceeds from nomination through actual consent to operating, gets its configured civil term and expires. A removal result arriving after expiry cannot restore the ended seat.
- Legacy simulated CGC terms expire; private ownership, worker-class seats and replaced term/holder references do not. Cancelled/wrong-jurisdiction/wrong-clock/early timers do not end the term.
- An actual judicial consent vote still dispatches to the judicial service (a strict double for that existing effect), and judicial expiry still dispatches to its own service. The tests do not claim a full judicial appointment/expiry journey.
- The actual service-created nomination is read by `CgcGovernorWorkspace` with `ChamberVotePresenter`: exact dossier, public nominee name, tally vote ID and cast URL are intact. A creator member may cast; an earlier voter, the Speaker and a foreign-legislature member may not.
- An actual three-member chamber with a Speaker auto-closes two ordinary casts (yes/no) as tied without resolving the nomination. The reader offers the existing tie-break endpoint only to that Speaker. Actual F-SPK-004 yes seats a seven-year governor term; no rejects and releases the vacancy. Both retain the original required-yes threshold; a non-Speaker and repeated Speaker submission are refused, and only one tie-break cast/publication exists. No application change to tie dispatch was needed.

The final combined pass attempt ran 72 tests / 902 assertions with one separate concurrent EO-8 failure: `TermLockstepTest` identified the new `ElectionCertificationReconciliationService` writer outside its inventory. All CGC cases passed; the EO-8 owner and integrator were notified to review that writer and its invariant pin. This is not recorded as a fully passing combined suite.

## Scope and deployment notes

No live database filings, simulation commands, migrations, world resets, or full frontend builds were run for this backend task. Existing fixtures that connect to PostgreSQL were not used. This task adds no migration and does not change the shared civil-term writer, constitutional settings or vote-counting implementation. The changed expiry job is worker code: normal rollout must refresh Horizon workers after deployment.

The isolated tests prove the specified application transitions and rollback behavior. They do not prove PostgreSQL row-lock behavior under competing processes, queue delivery over a network, a fresh-box install, or real global-role derivation against a populated world. Those are distinct review checks, not assumed passes. The frontend task separately verifies its reader queries and components.

The existing **CGC governor removal** workflow remains outside this appointment/expiry completion: `BoardGovernorService::requestRemoval` still resolves a department owner, and F-EXE-003's existing department surface does not provide the corresponding CGC operation. This is a separate code-observed build gap; this report does not claim all CGC executive powers are finished.
