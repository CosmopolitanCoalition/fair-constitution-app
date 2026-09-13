# Advocate case directory and filing selection — B3

Development and targeted internal tests completed on 2026-09-13. The advocate roster and filing composer no longer each materialize the advocate's complete case list.

| Completed repair | Runtime behavior |
|---|---|
| Bounded case roster | `AdvocateCaseDirectory` reads 20 cases plus one paging sentinel, ordered by title or docket number and exact case ID. It preserves the existing authenticated advocate, original advocate-filing form and non-deleted-case scope. Panel and court details load only for the selected roster page; claim bodies are not loaded. |
| Search and full traversal | Both directories offer literal title/docket-prefix search and Previous/More/First navigation. Duplicate titles use case ID as the tie-breaker. Cursor context binds the registered advocate, directory kind, search field and query; malformed or cross-context requests fail before reading cases. Completed and deliberating cases remain reachable, matching the previous eligibility set. |
| Independent Inertia reads | `AdvocateController` memoizes each requested page. `myCases`/`case_pages`, `composer_cases`/`composer_case_pages`, and existing `filings`/`filing_pages` are separate prop groups. Full entry reads one roster page and no composer choices. Composer choices use optional props and load only when an existing-case filing is opened or searched. Custom case visits update only their own URL parameters; the filing pager identifies `filings_cursor`, preserving the other lists' positions and filters for refresh/back. |
| Exact selection and draft persistence | `AdvocateConsole.vue` remembers filing type, selected case title/docket/reference and draft fields per user and registered advocate ID. Paging/searching does not silently select a new case or replace the prior selection with the first result. Opening the selected case uses `/cases/<exact uuid>`. Returning to the saved draft restores its original case. |
| Feedback and recovery | Roster and composer have independent loading, error and empty states, retry/search controls and disabled repeat requests. The selected case remains visible even when it is not on the current result page. Missing selection is explained locally; submission progress and rejection messages preserve the draft. |
| Existing filing boundary preserved | New-case and append-to-case endpoints and their payload shapes are unchanged. The UI does not infer whether a case's current stage permits a filing; the existing constitutional engine retains that decision. Case registration, panel/state rules and filing history are not rewritten. |
| Additive index support | `2026_09_13_090000_advocate_case_directory_indexes.php` adds concurrent partial indexes for `(advocate_id, lower(title), id)` and the corresponding docket expression, matching the reader's `COALESCE`, C collation and original-form/non-deleted predicate. It retries interrupted invalid index builds without changing case records. |

## Passed internal checks

| Check | Result |
|---|---|
| `php vendor/bin/phpunit tests/Unit/AdvocateCaseDirectoryTest.php tests/Unit/CivicHistoryDirectoryTest.php` | **10 tests / 232 assertions passed** using explicit private SQLite memory connections. The real controller and Inertia response execute. Both directories traverse 47 same-title/tied-title cases across three pages in both directions, preserve exact links/states/panel snapshots, exclude other advocates/forms/deleted cases, support docket/prefix/wildcard/non-Latin searches, reject invalid cursor contexts, and leave unrelated prop readers unloaded. Guest reads query no world cases. Existing filing-history isolation still passes. |
| `node --experimental-vm-modules --test tests/js/advocateCaseDirectory.test.mjs` | **8 tests passed** against the actual compiled AdvocateConsole page in a synthetic Vue renderer. Inertia form/remember/navigation boundaries are simulated. Tests cover on-demand choices, no implicit selection, duplicate-title case retention, independent roster refresh, draft/type restoration after remount, exact submission URLs/payloads, loading/error/empty recovery, missing-case feedback, retained draft on rejection, successful text reset, different-advocate draft separation and no unregistered reads/actions. Search, stale next-page links and first-page links preserve the other directories' current URL filters/cursors. |
| Migration PHP syntax | Passed with `php -l` for the additive migration. |
| Diff hygiene | `git diff --check` passed. |

The test engine mock forbids civic writes. There was no live PostgreSQL query, production build, simulation action or migration application. PostgreSQL index installation and representative production query-plan/load checks remain deployment/performance verification; the SQLite checks prove paging/scoping behavior, not PostgreSQL timing. Real hearing/filing lifecycle acceptance remains its existing separate workflow check.

Changed files: `app/Http/Controllers/Judiciary/AdvocateController.php`, `app/Support/AdvocateCaseDirectory.php`, `resources/js/Pages/Judiciary/AdvocateConsole.vue`, `database/migrations/2026_09_13_090000_advocate_case_directory_indexes.php`, `tests/Unit/AdvocateCaseDirectoryTest.php`, `tests/js/advocateCaseDirectory.test.mjs`, and this report.


## Integration verification

The parent task applied this reader's additive concurrent index migration locally. Bounded read-only PostgreSQL smoke checks executed the actual first-page query against a nonexistent fixture UUID, with a 3-second statement timeout. Advocate forward/backward cursor SQL also executed successfully. No record was created or changed; zero-row smoke checks establish SQL compatibility, not populated-result ordering or throughput. Those behaviors are covered only to the fixture scope described above.
