# Wallet and organization financial histories

13 September 2026. P2's wallet transaction and organization payment, levy-filing and conversion history scopes are developed and internally tested. Treasury is documented separately in PUBLIC_FINANCE_DIRECTORY.md.

## Changed behavior

- Wallet and organization transactions have 20-record pages, ordered by timestamp and unique ID. Two account-scoped seeks each retrieve at most 21 records; a bounded merge replaces the old OR-query sort. Internal and self-transfers appear once. Previous/next navigation reaches older records without offset queries.
- Organization levy filings and ownership conversions have independent 20-record pages. These use stable ID order because their historical timestamps can be null. Levy information is enriched only for the displayed filings. Exact decimal values remain strings.
- Account scope comes from the authenticated wallet or route organization's bindings. Private organization payments and filings remain restricted to its agent and seated board. Query parameters and cursors cannot grant access to another account. Public ownership conversions retain their existing visibility.
- Each card uses an Inertia partial response, retaining other cards and form state. Shared history controls show loading, disable repeated clicks while pending, and provide error/first-page recovery.
- Additive migration `2026_09_13_040000_financial_history_indexes.php` provides concurrent account/timestamp/ID and organization/ID indexes, including recovery from an interrupted invalid index. Applied locally; pulling hosts must migrate normally.

## Executed internal checks

`FinancialHistoryTest`, `EconomyAssetPartialTest`, `OrgShareSurfaceTest` and `StipendReceiptDirectoryTest` passed: **23 tests / 350 assertions** across the final scoped runs. All database fixtures explicitly select private in-memory SQLite connections. The new tests exercise the actual history queries and Inertia controller partials, including 45-record bidirectional traversal, timestamp ties, self/internal transfers, exact amount strings, malformed/impossible-date cursors, foreign-account parameters, unrelated-prop exclusion, guest access and organization agent/board/outsider permissions. The share-controller fixture was updated for B4's new public-profile lookup and additionally verifies private-handle suppression. Existing asset, receipt and share-action isolation tests remain passing. No live transfer, tax filing or ownership conversion was submitted.

Wallet.vue, OrgSettings.vue and HistoryPager.vue compiled individually (scripts, templates and styles). No production build was run.

Four read-only PostgreSQL plans used one selected account/organization, `LIMIT 21` and a five-second statement timeout. They chose the new transaction, levy and conversion indexes without a sort. These selected histories were empty; their execution times (0.012–0.047 ms) demonstrate the local empty-result plan only, not a populated-wallet or planet-scale throughput benchmark. Complete financial action journeys and PostgreSQL contention remain in the internal review register.
