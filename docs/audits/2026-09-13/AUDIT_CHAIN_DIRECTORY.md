# Audit history and exact receipts — B5

Date: 2026-09-13. Development and internal checks completed for this bounded reader repair.

## Confirmed defects and repair

`AuditChainController::show()` previously ignored `?seq=` even though committee report links supplied that receipt number. It always displayed the latest 25 rows. Its offset paginator ran a whole-table total count, and the separate chain statistic ran another `audit->count()` on every page. The query selected all audit columns before mapping the public fields.

The reader now has two modes:

| Request | Read behavior |
|---|---|
| `/system/audit-chain` and valid `entries_cursor` links | At most 25 displayed entries; one 26-row seek query detects whether another page exists. Previous/next browse by the unique `seq` key. No offset or total count. |
| `?seq=<positive BIGINT>` | One indexed equality lookup, limited to one row. Returns that exact receipt even when it is older than the first history page. |
| Missing valid entry number | Explicit “not found on this instance” state. Does not substitute the latest history or infer corruption from a sequence gap. |
| Invalid number or cursor | Recoverable error state with a latest-history URL; no history query. |
| Chain head | One latest-`seq` query limited to one row. Empty history returns `null`; sequence number is never presented as an entry count. |

The flattened PostgreSQL baseline already has the unique `audit_log_seq_unique` constraint/index. No migration is needed. The read uses the existing public metadata projection only: sequence, occurrence time, module, event, reference, hashes, rejected status and blocked reason. It never selects payload, actor identity or jurisdiction identity columns. Exact receipts use the same projection as history. BIGINT sequence values cross the browser boundary as decimal strings, preserving numbers above JavaScript's safe integer range.

Both read props are lazy. An `entries` partial performs only the history/receipt query; a head-only partial need not load history. Navigation retains a valid `jurisdiction` context value but does not echo arbitrary query parameters into generated links. Returning to latest history removes receipt and cursor parameters.

The page has an entry-number lookup, receipt links, newer/older buttons, a latest-history return, loading status, disabled duplicate navigation, and retry recovery for failed requests. Cancelled visits clear loading without a false failure. The selected receipt shows the full existing hash metadata. A successful selection change updates the lookup input; failed requests retain the user's number. The explanation and hash formula now live in the existing PageScaffold Learn/about slot.

**`verify()` and `reconcile()` are unchanged and were never invoked in this pass.** Their existing operator gates remain in the page. Viewing an entry is not represented as chain verification.

## Executed internal checks

| Check | Result |
|---|---|
| `tests/Unit/AuditChainDirectoryTest.php` | **7 tests / 225 assertions passed**, 4.257 seconds, 18 MB. |
| `tests/js/auditChainDirectory.test.mjs` | **8 tests passed**, zero failures/skips, 1.133 seconds. |
| PHP lint | Controller and new PHP test passed. |
| Vue compilation | Actual page, PageScaffold and LogRow scripts/templates compiled in the component harness; audit scoped styles compiled independently. No production build. |
| Patch whitespace | `git diff --check` passed. |

Commands:

```sh
docker exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: fc_app php vendor/bin/phpunit tests/Unit/AuditChainDirectoryTest.php --do-not-cache-result
docker exec fc_vite node --experimental-vm-modules --test tests/js/auditChainDirectory.test.mjs
```

The PHP test explicitly creates and verifies its own named SQLite memory connection and synthetic audit table. It does not use `LivePgConnection`, migrations or world actors. It exercises the real controller and Inertia partial response against 63 synthetic entries with gaps, navigating every row in both directions, then appending a newer entry during browsing. It checks exact out-of-page receipts, BIGINT preservation, missing entries, malformed numbers and cursors, empty installation behavior, metadata-only output, exact bound SQL, and absence of counts/offsets/private columns. Mocked AuditService methods assert that count, latestSeq and verifyChain are never called. No mutations are exercised against an application database.

The Vue tests mount the compiled page and actual PageScaffold/LogRow with a synthetic Vue renderer and a mocked Inertia router. They execute real input directives, lookup submission, page clicks, failure/retry, cancellation and selection refresh. They confirm dedicated Learn placement, receipt hashes, truthful empty/error states and operator controls absent for an ordinary viewer. No HTTP request or verify/reconcile POST is sent by the fixture.

Root supplied additional **local read-only browser acceptance**: the history loaded with latest sequence **#5596603**; opening **#5596582** displayed loading feedback and then **Receipt #5596582** with that entry's metadata. No verification or reconciliation control was clicked. This is receipt-navigation evidence on the development instance, not a full-chain verification, a fresh-host deployment check or multi-host load measurement.

## Remaining scope

No confirmed B5 reader defect remains from this pass. Full-chain verification/reconciliation performance and transport/deployment work are separate scopes; this reader repair does not claim to have checked them. No human-only test is required to establish the bounded reader behavior covered here.
