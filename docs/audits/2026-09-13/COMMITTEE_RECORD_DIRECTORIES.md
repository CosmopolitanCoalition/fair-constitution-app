# Committee bill and report directories — B3

Checked against the working code on 2026-09-13. This closes the committee bill/report reader portion of B3. It does not certify the entire committee lifecycle or global throughput.

## Completed development and internal checks

`CommitteeController::show` now delegates bill and report histories to `CommitteeRecordDirectories`. Each independent directory returns at most 20 rows using a 21-row seek query, ordered by creation time and UUID. Nullable and tied timestamps have a deterministic order. There is no total-count query or all-history materialization in these directories. Previous, next, and first links keep every record reachable. Cursors are validated and scoped to the directory, committee, and selected hearing.

Bill cards retain their exact bill, vote, and referral targets. A card's latest report is selected with one bounded seek per visible bill. A separate report directory exposes all published reports, including older reports on the same bill and general reports without a bill. Selecting a report opens its exact full text in the committee page, with its related bill and public record references. Missing or mismatched publications stay visibly unavailable without displaying another committee's publication.

Related bill metadata is restricted to the committee's legislature and excludes deleted bills. Public report presentation uses the stored public actor display; it does not select the author's user ID or fall back to a legal name. A report can retain its valid related bill after that bill moves to a different committee within the same legislature. Summary queries select only the first 351 characters of each body in SQL before constructing a 350-character excerpt; full body text is read only for the selected report.

Paging preserves the hearing, jurisdiction/place, return destination, selected report, and the other directory's position. Existing testimony readers and closed-hearing controls remain intact. A report draft retains its text and selected bill when that bill moves off the current bill page. All new text is rendered as escaped text.

The review also exposed and repaired a filing defect: `CommitteeReportFiling` previously copied an arbitrary optional bill ID into the report. It now accepts a committee-wide report with no bill, or an existing, non-deleted bill currently assigned to the selected committee and its legislature. A malformed, missing, deleted, unassigned, other-committee, or other-legislature target is rejected before publication or report creation. The selected bill is locked within the engine's existing transaction through publication, so a concurrent referral cannot alter its association mid-filing. No new lifecycle-status restriction was added. Historical reports remain readable after a later referral to another committee within the same legislature.

## Cross-list navigation repair

Server-generated links include the other directory's current cursor. Refreshing only one list can leave another list's links stale, so alternating the pagers can silently reset a previously selected position. The committee page refreshes its bounded bill/report props and contextual report/testimony links together. Each new `HistoryPager` also supplies its precise `cursor-key` (`bills_cursor` or `reports_cursor`) to the shared current-query merge added by the parent task. This permits the shared pager to preserve current positions even when supplied a stale URL. The committee regression alternates bill, report, backward bill, and testimony navigation and checks both positions and selected-hearing context.

## Validation evidence

| Check | Result and scope |
| --- | --- |
| `php vendor/bin/phpunit tests/Unit/CommitteeRecordDirectoriesTest.php tests/Unit/CommitteeMeetingContextTest.php tests/Unit/CommitteeReportFilingScopeTest.php --colors=never` | **24 tests, 277 assertions passed**, with `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` and explicit private SQLite connections. Directory + meeting tests passed 16 / 215; filing-scope tests add 8 / 62. |
| Actual-controller directory fixtures | Traversed 47 bills and 53 reports forward and backward without omissions or duplicates; tied/null timestamps, new insertions, repeated bill reports, general reports, independent cursor positions, exact report bodies/links, scope/privacy, malformed and foreign cursors, closed historical hearings, and retained bill vote/referral contracts passed. |
| Query-shape assertions | Bill/report page reads use `LIMIT 21`; latest report seeks use `LIMIT 1`; publication lookups contain no more than 20 record IDs plus their three scope bindings and use SQL `substr(body, 1, 351)` for summaries. Invalid cursor/report inputs are rejected before reader queries. |
| Actual-handler filing fixtures | Two valid filings (committee-wide and currently assigned bill) publish once and record the exact target/committee/chair; six invalid target cases are rejected before the mocked publisher or report insertion. The separate actual-controller reader still opens full historical report text and its related bill after a later within-legislature referral. |
| `node --experimental-vm-modules --test tests/js/committeeRecords.test.mjs` | **4 tests passed**. Compiles the actual Vue SFC and checks selected-bill/draft retention, reset after submission success, bounded prop refresh and cursor keys, exact report/body/context rendering, escaped text, read-only controls, and empty pages. |
| PHP lint | Controller, report filing handler, new helper, new filing-scope test, and additive migration passed. Targeted Pint formatted only new helper/tests/migration files. |

The reader fixtures invoke the actual controller with engine filing/publication mocked to reject calls. The additional scope fixtures invoke the actual filing handler inside a private SQLite transaction with publication mocked; they create only private fixture report rows. No live ballots, filings, simulation commands, PostgreSQL tests, world resets, or full frontend builds were run. The historical `CommitteeHearingExitWalkTest` uses a live PostgreSQL helper and was deliberately not executed as part of this private-fixture check.

## Deployment and limits

`2026_09_13_081000_committee_record_directory_indexes.php` adds three PostgreSQL indexes concurrently: active committee bill history, committee report history, and latest reports per committee/bill. They match the null-safe creation-time expression and UUID seek order. The migration is idempotent and retries an invalid prior concurrent index. **It is authored and linted, but has not been applied by this subtask.** No existing rows or schema baseline are rewritten.

These checks establish bounded record counts and functional isolated readers. They do not measure PostgreSQL index deployment, production query latency, complete page byte size, or browser/network behavior. Existing roster loading and the vote presenter's cast lists remain outside this change; a large institutional membership may still make those separate collections expensive. Full report text is fetched only for the selected report; summary body text is bounded in SQL. No claim of planet-scale throughput follows from the fixture results.

## Separately observed repair needs

- **Audit-chain exact-entry navigation:** `AuditChainController::show` does not read the existing `?seq=` argument; it always returns the latest page. The committee report reader now has its own exact `?report=<uuid>` destination, so report access no longer depends on this broken deep link. Repairing the shared audit-chain entry destination remains separate.

Unperformed whole-meeting journeys, network/browser verification, and production measurements belong in the review register; they are not additional build work merely because this focused check did not perform them.


## Integration verification

The parent task applied this reader's additive concurrent index migration locally. Bounded read-only PostgreSQL smoke checks executed the actual first-page query against a nonexistent fixture UUID, with a 3-second statement timeout. Advocate forward/backward cursor SQL also executed successfully. No record was created or changed; zero-row smoke checks establish SQL compatibility, not populated-result ordering or throughput. Those behaviors are covered only to the fixture scope described above.
