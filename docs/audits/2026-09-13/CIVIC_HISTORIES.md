# Settings and advocate archives

13 September 2026. The settings-change and advocate-filing archive repairs are developed and internally tested.

- Settings changes page 50 receipts at a time within the route legislature's jurisdiction, preserving act links, exact stored before/after values and recorded dates. Stable ID order includes historical records with null effective dates; the UI does not claim chronological order.
- Advocate filings page 50 records by publication sequence within the authenticated viewer's registered advocate identity. Case names are now links to the exact case. Other advocates cannot be selected through query parameters.
- Strict seek tokens carry a scope digest and reject malformed or cross-institution/actor cursors before history reads. Previous/next/first links preserve the selected place query. Related laws/cases load only for a bounded page; no count or offset query is used.
- Inertia partial responses load only the requested history pair. Browsing an archive no longer reloads the settings register, member/clock summary, advocate case list or composer. Existing action authority is unchanged.

`CivicHistoryDirectoryTest` passed **4 tests / 53 assertions** with explicit private in-memory SQLite fixtures. It traverses 125 records in each archive forwards/backwards, checks missing dates and exact case/act links, foreign/malformed cursors, authenticated actor scope, guest-empty results, and actual controller partial-response query isolation. Settings.vue and AdvocateConsole.vue compiled individually. Migration `2026_09_13_060000_civic_history_indexes.php` was applied locally; its two indexes build concurrently and recover from interrupted invalid indexes. No real setting was amended or court filing submitted.

The four interjurisdictional histories remain with S2. Inspection also confirmed that committee bills/reports and advocate full-entry case/composer lists still materialize their whole institution/actor collection. Those are separate bounded-reader repairs; this archive pass does not claim they are fixed or that a full legislative/case journey passed.
