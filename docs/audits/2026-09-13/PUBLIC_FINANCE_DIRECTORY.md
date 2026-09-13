# Public finance directory — 2026-09-13

This closes the treasury-reader portion of P2. It does not claim completion of wallet/organization finance, unrelated economic actions, or whole-world performance testing. No live financial/civic records were changed, no report job was scheduled, and no simulation or production frontend build was run.

## Repairs implemented and internally checked

| Previous code defect | Resulting behavior |
|---|---|
| Treasury loaded every live public account and revenue source into one response. | Exact selected place; 20-account seek pages combine the jurisdiction treasury and that place's department treasuries through separate indexed owner paths. Place/account lookup does not enumerate other jurisdictions. |
| World ledger showed only the latest 50 entries. | Default selected-public-account ledger and explicit **All currency accounts** view, each with stable sequence seek pages, previous/next/first controls, 50 displayed records. The latter preserves publicly readable pseudonymous economic-account legs. |
| Budgets, borrowing and issuance stopped after 20 records. | Independent seek histories; budgets, borrowing and revenue stay in the exact place and currency. Issuance is explicitly currency-wide and ordered by timestamp plus unique ID. |
| Every displayed budget counted all its lines; line display stopped at 50. Every revenue source loaded every levy. | Select one budget/source to read independently paginated spending lines/levies. No per-parent counts or unbounded child materialization. Legacy nested arrays remain empty, and the former line count is honestly null. |
| Every visit synchronously aggregated money supply and all treasury balances. | `CurrencyReportService::read()` supplies the last completed totals and completion time. Unavailable totals stay null; an in-progress/failed refresh is described separately. GET never starts or advances report work. |
| Treasury offered no place/account navigation. | Finance-specific ancestor links and paged immediate-child links walk the complete jurisdiction tree. `JurisdictionContext` keeps the viewed-place header consistent. Account and child-detail links retain unrelated paging context. |
| Copy claimed a person's money was invisible and linked to an overview as if it verified the chain. | The surface accurately describes pseudonymous public account movements and clearly states that a visit does not run chain verification. No user or account-binding joins are introduced. |

Implementation: `app/Support/PublicFinanceDirectory.php`; only `treasury()` was replaced in the shared `EconomyController.php`; `resources/js/Pages/Economy/Treasury.vue` uses the shared `HistoryPager.vue`. History arrays and their link props share request-local cached readers, so an Inertia partial request loads one selected section and does not repeat its query for its pagination metadata. Parent-record law labels use one bounded ID-list query per displayed page.

Exact account/budget/revenue IDs are validated against selected place/currency; foreign, deleted and nonpublic account selections return 404. Explicit unknown places return 404 instead of silently falling back. Cursors contain validated seek positions, never authority, ownership or identity. Selection and rendering do not confer residency or action rights.

## Executed evidence

All PHP cases below explicitly switch to their own SQLite `:memory:` connection, assert that driver/database, create small private schemas and clean only those fixtures. They do not use `LivePgConnection`.

| Check | Exact result |
|---|---|
| Initial `PublicFinanceDirectoryTest.php` | **8 tests, 335 assertions**, passed, 8.096 seconds / 20 MB. |
| Final treasury plus regression run, including the subsequently added populated-full-response test | `docker exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: fc_app php vendor/bin/phpunit tests/Unit/PublicFinanceDirectoryTest.php tests/Unit/FinancialHistoryTest.php tests/Unit/CurrencyReportTest.php --do-not-cache-result` — **26 tests, 1,737 assertions**, passed with no skips, 16.444 seconds / 22 MB. Nine of these tests are treasury-specific. |
| Vue rendering, actual treasury/table/pager components | `docker exec fc_vite node --experimental-vm-modules --test tests/js/publicFinance.test.mjs` — **3 tests passed**, no skips, 1.304 seconds. |
| Vue syntax | Treasury and shared HistoryPager parse, script, template and scoped-style compilation all passed; no production build. |
| PHP syntax | PublicFinanceDirectory, EconomyController, private fixture test and the new migration passed `php -l`. |
| Patch hygiene | `git diff --check` passed; existing Windows newline notices only. |

The fixture covers 111 selected-account movements across three pages; currency-wide view retains economic-account movement 112 and another public treasury movement 113 while excluding another currency's movement 114. Public accounts, child places, budgets, borrowing, issuance, revenue, spending lines and levies all cross multiple page boundaries and return to prior pages without gaps/duplicates. Tied issuance timestamps exercise the unique-ID tiebreaker. Large money values and levy rates retain all digits as strings.

Further assertions cover foreign/deleted/nonpublic/wrong-currency accounts, deleted departments, foreign budget/source IDs, unknown place slugs, malformed/extra-key cursors, valid cursors reused under a different explicit scope, exact source selection, null/missing reports, the last saved report during a refresh, and fresh/populated complete Inertia responses. Query logging verifies single bounded history reads, no aggregate/count/offset or account-binding reads, and no initial budget-line/levy queries before selection. Dispatch fakes assert no background work is triggered.

The component tests render real `Treasury`, `DataTable` and `HistoryPager` SFCs and verify all nine previous/next/first-page controls, natural place navigation, selection links retaining unrelated cursors, precise money display, truthful currency scope/report state, selected child details and empty-install rendering. Inertia routing is mocked; no browser civic actions are performed.

`tests/Feature/EconomyPropContractTest.php` treasury expectations and `docs/plans/economy/ECONOMY_PROP_CONTRACT.md` were updated for nullable saved totals and independent nested pages. The live-backed feature suite was **not run**; the actual controller/prop behavior was exercised by the private fixture instead.

## Index deployment and remaining review

`2026_09_13_050000_public_finance_history_indexes.php` supplies resumable concurrent PostgreSQL indexes for currency/account ledger sequence, issuance timestamp/ID, place/currency budgets/borrowing/revenue, budget lines, levies and immediate-child place paging. It is additive, leaves rows intact, and repairs an invalid prior index build before retrying. Existing account owner/currency and department jurisdiction indexes support public-account selection.

The parent confirmed that migration **050000 applied successfully to the local PostgreSQL instance in 25 seconds** after these tests. The subagent did not execute live migrations itself. PostgreSQL plan/latency measurement, fresh Linux migration execution, and real-browser treasury traversal remain review checks until executed; they are not recorded as BUILD defects or human-only dependencies. The isolated tests prove correctness and query scope, not the loaded world's wall-clock latency. No additional treasury-reader defect was confirmed in this pass.
