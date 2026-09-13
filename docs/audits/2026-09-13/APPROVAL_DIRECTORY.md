# Approval ballot directory and public candidate identity — 2026-09-13

Status: EO-6 development and the scoped internal checks below are complete. B4 is complete for the approval ballot only. The related public-presenter privacy repair is complete. This is not a blanket pass for all elections, selectors, public profiles, or planet-scale performance.

## Completed build scope

| Area | Result |
|---|---|
| Candidate directory | Replaced whole-race candidate/profile/endorsement materialization and the `full=1` escape hatch with fixed 20-candidate cursor pages. Every eligible candidate remains reachable by next/previous navigation, including newly validated candidates without a standings row. |
| Search | Name, public handle, existing public candidacy reference, statement/topic text, endorsing organization name, endorsement category, current officeholder and own-active-approval filters run on the server before the page limit. Search is not limited to the currently visible candidates or organization preview. |
| Organization endorsements | Candidate rows show at most three organizations. Additional organizations load only on request, in separate 20-organization cursor pages; individual endorsements remain aggregate counts, never identities. Private, withdrawn, inactive or deleted organization endorsements are not displayed. |
| Daily public standings | Public ranks/counts come only from the latest daily or frozen snapshot. Newly validated candidates show “Awaiting daily ranking” and “First daily count pending,” with no invented live rank. Page breaks do not move the finalist line. Shared counting/cutoff rules were not changed. |
| Personal choices | The private total spans the selected race, while candidate IDs are limited to the visible page and authenticated viewer. Mutations serialize in the page; failed actions restore private state, canceled requests explain that a refresh is needed, and successful server responses reconcile the total and switches. Public counts do not change when one viewer acts. |
| Page recovery | Search controls remain available after empty results. Page links bind the race, snapshot and filters; expired/malformed cursor contents return to the first page with an explanation rather than entering a GET validation redirect loop. |
| Repeated names (B4) | A public handle, when the social profile is public, or a labeled suffix of the already-public candidacy reference accompanies each profile link. The link and accessible title/label contain the full reference. Approval-switch accessible names also include this public context. The suffix is a visual abbreviation; the full UUID/link remains the authoritative reference. No residence or wallet ownership is added. |
| Name privacy | Approval name/search resolution no longer falls back to legal `users.name`. It uses the chosen civic display name, a public social pseudonym, or the existing Resident-hash fallback. Private/jurisdiction-only social profiles do not disclose names or handles through the ballot. |
| Related confirmed defect fixed | `CandidacyPanel::displayName()` and `displayNames()` previously used private social names/handles as public display fallbacks even though the person-profile controller hid the separate `handle` field and prohibited private handle lookup. Both presenter methods now restrict social fallback to `visibility=public`; chosen civic names remain available and legal names remain unreachable. |

The nonfunctional alignment-questionnaire card was removed from this ballot page. Registration and the election learning door remain available. No endorsement-write feature was added.

## Executed internal checks

All fixture writes used explicit private in-memory SQLite connections. No simulation command, live ballot mutation, migration/reset, frontend build, or live PostgreSQL test helper was run.

| Check | Evidence | Result |
|---|---|---|
| Candidate traversal and page bounds | `ApprovalDirectoryTest`: 97 candidates (90 ranked, seven awaiting ranking), full forward traversal without duplicates, reverse traversal, `full=1` ignored | Passed |
| Search beyond first page and beyond preview | Candidate 44 found by public name, statement, topic, literal `%` and an organization outside the first three previews; SQL wildcard characters treated literally | Passed |
| Endorsement traversal and privacy | 43 public organizations across three pages; private/inactive/withdrawn/deleted records excluded; individual identity excluded; another race's candidate rejected | Passed |
| Owner totals and page switches | 45 candidates, revoked approval, another viewer's approval and another race; page-local IDs, race-wide own total, guest empty personal data and own-only filter | Passed |
| Snapshot integrity | Frozen snapshot preferred over a newer nonfrozen snapshot; casting changes the viewer's total without changing public standings | Passed |
| Cursor recovery and SQL shape | Changed filters/snapshot and malformed cursor recover to first page; logged candidate `LIMIT 21`, organization preview `LIMIT 4`, and visible-candidate bounds for private/endorsement enrichment | Passed |
| Real controller mutation methods | Approval cast is idempotent; submitted foreign `user_id` cannot change the actor; revocation affects only that actor; foreign election, missing footprint and closed phase reject; audit mock receives no per-approval append | Passed |
| Inertia response contract | `ApprovalController::show()` returns 20 candidates/private IDs and the full own total; organization details absent from the initial response and present only in an explicit partial response | Passed |
| Repeated public names across pages | 22 matching display names retain distinct full public references and links; legal names and private social handles absent from payload and search | Passed |
| Public-name presenter privacy | Private/jurisdiction social names and handles hidden in both single and bulk naming; public pseudonyms/handles and chosen civic names preserved; deleted social profile excluded | Passed |
| Vue state and rendering | Actual SFC scripts compiled in isolated Vue runtime; mutation serialization, optimistic failure/cancellation, successful refresh, page transitions, server filter requests, endorsement query preservation, cutoff placement, empty-search controls and accessible references | Passed |

Final commands/results:

```text
docker exec -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: fc_app php vendor/bin/phpunit tests/Unit/ApprovalDirectoryTest.php tests/Unit/CandidacyPublicNameTest.php --colors=never
OK (16 tests, 265 assertions)

docker exec fc_vite node --experimental-vm-modules --test tests/js/approvalDirectory.test.mjs
6 tests passed, 0 failed
```

Targeted PHP lint passed for `ApprovalDirectory`, `ApprovalController` and `CandidacyPanel`. The Vue tests compile both changed SFCs, including their templates. Targeted PHP formatting and `git diff --check` passed.

## Bounded PostgreSQL plan inspection

Evidence: `APPROVAL_DIRECTORY_PLAN.txt` beside this document.

Used the election URL supplied in the user's screenshot, then selected one race with `LIMIT 1`: `01a079cf-8b7b-7320-ad8c-da9ecd6ae971`. All SQL ran inside `BEGIN READ ONLY` with a three-second local statement timeout. The selected race has no daily snapshot, so its candidate plan used the same false standings join as the application. `EXPLAIN` used `ANALYZE FALSE`; neither listing query was executed.

The candidate plan uses the existing election and race/status candidacy indexes, user primary key and active social-profile user index, followed by the expression sort and result limit. The endorsement plan chooses a sequential scan on the currently tiny/empty endorsement relation, then an organization primary-key lookup and sort. That plan choice is not evidence of throughput with substantial endorsement data.

**EO-6 closes bounded materialization and reachable paging, not planet-scale throughput.** Race-scoped candidate totals, own approval counts and snapshot maxima still perform database work over their eligible rows. Contains-search and expression ordering can still scan/sort a large race. Per-candidate organization previews have a fixed maximum of 20 queries per page, each bounded to four returned rows. Existing candidate/race and endorsement/candidate indexes were inspected; no new index or schema migration was added in this pass.

## Review-register scope

Move the specific passed checks above to completed internal verification. Do not leave them as generic “check elections” work. These checks do not establish the following, which remain internal review/performance scope rather than confirmed new build defects:

- Full HTTP middleware, real-browser network cancellation, a rollup racing a browser navigation, and an entire approval-to-ranked-vote-to-certification journey. The write/controller methods and Inertia response shape were exercised in isolation; real transport callbacks were simulated.
- Large-race latency, concurrent browser/worker load and query plans with a substantial frozen snapshot or a heavily endorsed candidate. The recorded `EXPLAIN` is a bounded planning check, not a load test or `EXPLAIN ANALYZE`.
- Multilingual search/collation and final visual/accessibility review. SQLite fixture name-search cases used ASCII text; the public-name privacy rules were exercised for public/private/jurisdiction/deleted profiles.
- Other repeated-name selectors and all other CandidacyPanel/person-profile functions. The small presenter repair only changes public naming visibility.

The synthetic Resident-hash fallback remains reachable by paging and by the displayed public candidacy reference. It is not itself a SQL search key; chosen public names, handles, candidacy references, statements and topics are searchable.

## Changed files

- `app/Support/ApprovalDirectory.php`
- `app/Http/Controllers/Elections/ApprovalController.php`
- `resources/js/Pages/Elections/OpenBallot.vue`
- `resources/js/Components/Electoral/CandidateRow.vue`
- `tests/Unit/ApprovalDirectoryTest.php`
- `tests/js/approvalDirectory.test.mjs`
- `app/Http/Presenters/CandidacyPanel.php`
- `tests/Unit/CandidacyPublicNameTest.php`

No master action plan, completed-work register or review register was edited by this subtask. No commit was made.
