# Corrected-election recertification review — 2026-09-13

## EO-8 repair and internal validation

The demonstrated current-term general-election correction defect is repaired. `ElectionResultsCertification` now serializes each filing on its election row, retains the original `elections.certified_at` cycle anchor, and routes a superseding general count to `ElectionCertificationReconciliationService`. The service locks the chamber/current roster, verifies the prior sealed race hashes against the certification being superseded, and requires a newly resolved corrected audit. Ordinary first certification remains on the existing pipeline.

- Unchanged winners keep their member/term IDs, seated status, speaker and committee offices, original dates, clocks and successor. Their normalized result metadata can be corrected without repeating institutional organization.
- For an unambiguous changed current winner, actual `VacancyService::declare(queueCountback: false)` retires only the displaced member and associated committee/presiding offices. Ex-officio executive membership linked to that member also ends. The corrected winner enters that physical seat as `elected` with a new term starting at correction and inheriting the original expiry; the vacancy is filled without running a countback or scheduling a new cycle. Unaffected offices and prior/open votes are preserved.
- Metadata-only correction remains supported when an original winner independently resigned and was replaced: a real private-fixture countback replacement and the old resignation retain their identities and history.
- A historical-window mismatch, changed-winner race with independent vacancy/replacement lineage, mismatched prior certification snapshot, missing/ambiguous original successor, or repeated accepted count refuses atomically. No old laws or votes are retroactively invalidated.

The former opt-in diagnostic was converted into desired-behavior regressions at `tests/Unit/CorrectedElectionRecertificationTest.php`; `tests/reviews/CorrectedElectionRecertificationReviewTest.php` is removed. Validation:

```powershell
docker exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e CACHE_STORE=array fc_app php vendor/bin/phpunit tests/Unit/CorrectedElectionRecertificationTest.php tests/Constitutional/TermLockstepTest.php --do-not-cache-result --stop-on-error
```

**Passed: 15 tests / 531 assertions, 23.853 seconds, 20.00 MB.** This combines ten private correction tests with five DB-free term invariants. Coverage includes unchanged and changed winners, independent countback history, prior/current offices, preserved successor and cycle, stale-status retry, historical refusal, prior-hash mismatch, unauthorized/reaffirmed controls, and an intentional replacement INSERT failure after vacancy retirement proving complete transaction rollback. Jobs are faked, countback dispatch is explicitly absent, and only the original first-certification social-provisioning job is recorded by the fake.

The immutable-term writer inventory now explicitly includes the new reconciliation service because it creates the replacement's term using `CertificationService::inheritedWindow`. The unconditional source scan proving no `ends_on` update still covers this service and passes; neither that scan nor the expiry rule was weakened. No schema migration, live civic mutation, production build or external call was needed.

**Bounded remaining lifecycle scope:** automatic changed-winner reconciliation across independent resignations/countbacks/special replacements is explicitly unsupported and refuses safely. Historical corrections that would need to alter seating, changed occupied-seat totals, and a corrected winner already holding another chamber seat also require separate reconciliation work. Executive/judicial/organization/special-election recertification, actual concurrent PostgreSQL delivery and full engine/audit-chain integration were not claimed as completed by this SQLite pass. These limits do not prevent the demonstrated same-election repair from preserving a current, unambiguous term.

## Original defect evidence (before repair)

**Confirmed build defect:** certifying a corrected audit of an already seated general election executes another complete chamber rollover. It restarts the term window, advances its number, retires unchanged members and their committee/presiding offices, shifts the next-cycle clock, and opens another successor election. Production code was not changed by this review.

Reviewed at `df72a0a7` (`Retire legislative offices on rollover and verify combined room workflows`). The term/successor problem already exists in the ordinary certification path; the recent, correct new-term cleanup also applies to this misclassified recertification and therefore clears committee and speaker offices. Removing that cleanup would reopen the genuine new-term defect rather than fix recertification.

## Reproduction and actual boundaries

Opt-in harness: `tests/reviews/CorrectedElectionRecertificationReviewTest.php`. It is intentionally outside the normal PHPUnit suites: its first test asserts the **observed defect**, not desired application behavior. A passing reproduction is evidence to build a repair, not a passing correctness check. Convert it into preservation/reconciliation regressions when repairing this path.

```powershell
docker exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e CACHE_STORE=array -e QUEUE_CONNECTION=sync fc_app php vendor/bin/phpunit tests/reviews/CorrectedElectionRecertificationReviewTest.php --do-not-cache-result
```

Executed result: **3 tests, 83 assertions; 3.260 seconds; 18.00 MB**, PHP 8.4.25 / PHPUnit 11.5.55.

The fixture starts with a synthetic forming five-seat legislature. It performs its first certification on September 1, 2026 through the actual `ElectionResultsCertification` handler, `BoardProvenance`, `CertificationService`, `ClockService`, and `ElectionLifecycleService`. The actual successor race plan, race insertion, and transition to approval-open execute. The jurisdiction's interval resolves to a fixture value of 48 months, demonstrating that the failure is not tied to the default duration. The fixture then supplies existing seated members, speaker, committee chair/alternate, one placement and an open chair ballot.

On September 13, the fixture supplies a completed audit count with the **same five winner identities and seat numbers**, changed normalized shares, and a different sealed record hash. It invokes `TabulateRaceJob::resolveAudit` through reflection, exercising the real post-count audit-resolution stage without executing a queued job. The stage marks the prior tabulation superseded and the audit corrected, leaving the election in `audit_rerun`. The real certification handler then accepts the responsible board member's superseding filing inside a database transaction.

These pieces are supplied rather than claimed as tested:

- Sealed initial/audit counts; ballot creation, decryption and counting are not run.
- Audit transport and referendum effects are doubles; the full constitutional engine/validator and real audit chain are not run. The surrounding transaction required by the handler is real.
- Settings resolution returns explicit fixture values. Constitutional-version pin checking and the small at-large race planner are real.
- Existing institutional organization/oaths are fixture rows, not freshly filed civic actions. No delegated executive exists in this synthetic chamber.

Isolation is explicit: a separately named SQLite `:memory:` connection is selected and its driver/database asserted before schema setup. Current-member, current-certification and live-committee-seat uniqueness indexes are included. All tables and actors are synthetic; all queries use this fixture connection. Jobs are faked; stray HTTP is blocked, with no HTTP calls asserted. No PostgreSQL, Redis, live rooms, simulation commands or production build run. Teardown purges only this in-memory connection. This does not establish PostgreSQL concurrency behavior or validate the complete production schema.

## Observed changes

| Fact | First certification / organized term | Same-election corrected certification |
|---|---|---|
| Current certification | First sealed record | First record becomes `superseded_by_audit`; one new current record |
| Elected people | Five winner IDs | The exact same five winner IDs |
| Legislature term number | 1 | **2** |
| Term window | 2026-09-01 through 2030-09-01 | **2026-09-13 through 2030-09-13** |
| Existing member records | Five seated members | **All five `term_ended`; five new records created as `elected`** |
| Existing terms | Five active terms | **All completed; five new active terms**; old rows retain their historical expiry |
| Speaker | First winner; speaker flag true | **Speaker cleared; every speaker flag false** |
| Committee | Seated, chair and alternate set | **Reset to `created`; both offices cleared** |
| Committee placement | Seated | **Vacated with `chamber_turnover`, dated September 13** |
| Open chair ballot | Open | **Voided**; closed historical ballot remains closed |
| Next general-election timer | Original cycle anchor | **Cancelled and replaced; deadline 12 days later** |
| Term flags | Original five armed | **Original five cancelled; five new flags armed** |
| Successor election | One approval-open successor | **Two approval-open successors with the same `prior_election_id`**, each separate; original remains open |

The new successor's race actually exists and is approval-open. This is not a mocked `openSuccessor()` return value or just a duplicated ID in a response.

Control checks pass: an unchanged-hash audit is reaffirmed by the real resolution stage, returns the election to certified, and cannot be recertified. Even forcibly restoring its fixture status to `audit_rerun` does not bypass the corrected-outcome gate. An unseated outsider cannot certify a corrected audit. Both controls leave the original term, offices, clock, certification and sole successor untouched.

## Code explanation

- `app/Jobs/Elections/TabulateRaceJob.php:225` determines `corrected` from record-hash differences, so unchanged winners are a valid corrected-audit scenario.
- `app/Domain/Forms/Handlers/ElectionResultsCertification.php:192` permits superseding after a corrected audit; lines 120–134 use the current certification time and call the ordinary pipeline.
- `app/Services/CertificationService.php:172` creates a fresh general-election window and calls `turnOverChamber`; lines 247–258 advance the term, re-arm the cycle and open a successor without distinguishing same-election audit correction.
- `app/Services/CertificationService.php:1127` retires the current term and offices; line 1201 advances the chamber's term counter and dates.
- `app/Services/ElectionLifecycleService.php:248` unconditionally inserts a successor. The committed schema and additive migrations supply a non-unique `prior_election_id` lookup index, not a one-successor constraint. The unique current-certification index does not protect term or successor identity.

## Minimal repair proposal and boundaries

1. Give superseding certification an explicit same-election correction path, tied to the prior certification and its original term identity/window. Do not route it through whole-chamber turnover, term-number advancement, or a new general-election cycle. Preserve append-only certification/tabulation history.
2. For unchanged winners, retain their member/term identities, seated status, committee placements, presiding offices and valid ongoing ballots. Update only corrected result metadata that is meant to change. Reuse the original successor and cycle anchor, retaining its approval/candidacy progress.
3. For changed winners, reconcile only affected seats under the original term expiry and retire only authority actually lost. Keep this scoped to the election's term; an audit of an older election must not retire a later, currently serving chamber. Do not infer retroactive cancellation of enacted laws, past votes or unrelated offices.
4. Pin desired invariants for unchanged winners, changed winners, retries/concurrent filings, existing successor activity and an older audited term. Keep the ordinary new-general-election rollover regression. If adding a uniqueness migration, scope any repair of pre-existing duplicates and preserve history; do not run world-wide corrective writes.

Under `CLAUDE.md`'s development ruling, preventing a same-election correction from pretending to be a new term is an ordinary defect repair. This review was specifically assigned **verification only**, so no production patch is included. Count math, voting rules and configured intervals must remain unchanged. Any newly chosen rule about retroactive validity of decisions, displaced-office tenure, or precedence between a historical correction and a later election needs separate operator policy direction if existing law/code cannot settle it. The confirmed same-winner lifecycle defect does not depend on settling those broader questions.

The executive, judicial, organization-board, and special-election recertification branches have not been exercised here. Repeated use of a previously corrected audit and concurrent superseding filings also remain separate checks, not additional defects claimed by these results.
