# Browser accessibility sweep, 14 September 2026 (Edge on the Windows host)

Instrument: `tests/browser/accessibility.test.mjs` driven by Playwright from the Windows host with
`CGA_BROWSER_CHANNEL=msedge` and `CGA_BROWSER_BASE_URL=http://localhost:8080` (config `playwright.config.mjs`,
commit b51b7714 and the two harness fixes that follow it). No browser runs inside Docker. Each guest route is
loaded at 1280 by 900 and at 375 by 812; axe (WCAG 2.1 A and AA) runs at both widths; horizontal overflow at
375 px and a 45-step Tab walk (focus ring, accessible name) are checked. Per-route result files:
`storage/logs/a11y_host2/*.json` on box E (not committed).

Roster: 41 guest routes derived from the route table (pin passed).

## Skipped on operator order (2026-09-14, 8:15 PM Eastern)

`/jurisdictions` and `/legislatures`. Both took 4 to 5 minutes and crashed the renderer in every attempt. The
operator's reading: the index pages load a planet's worth of rows without pagination. To be addressed after the
other tests close out.

## Clean at both widths (27)

`/`, `/atlas`, `/coverage`, `/explore`, `/federation`, `/journeys`, `/jurisdictions/bootstrap`,
`/jurisdictions/disintermediation`, `/jurisdictions/restoration`, `/jurisdictions/union-formation`,
`/launchpad`, `/learn`, `/learn/guides`, `/login`, `/operator/login`, `/reach`, `/rooms`, `/setup`,
`/setup/bootstrap`, `/setup/join`, `/setup/mode`, `/setup/operator`, `/system/accessibility`,
`/system/clocks`, `/system/constitutional-questions`, `/system/term-sync`, `/tour`.

(The first 13 come from the container run of 6:19 PM before it died; the rest from the host run of 8:17 PM.)

## Findings (9 routes)

| Route | Width | Rule | Nodes | Element |
|---|---|---|---|---|
| /achievements | both | link-in-text-block | 1 each | `div > a[href$="login"]` |
| /register | both | link-in-text-block | 1 each | `a[href$="login"]` |
| /support/report | both | link-in-text-block | 1 each | `div > a[href$="login"]` |
| /civic/commons/halls | both | link-in-text-block | 1 each | `a[href$="jurisdictions"]` |
| /civic/commons/square | both | link-in-text-block | 1 each | `a[href$="jurisdictions"]` |
| /building | both | color-contrast | 14 each | the seven step cards: `.rounded-lg.border.p-3 > .justify-between.gap-3.items-baseline > .g…` and `.mt-1.5.text-gray-500` |
| /coverage-ops | 375 | scrollable-region-focusable | 3 | `.card:nth-child(4..6) > .table-wrap` |
| /learn/manage | 375 | horizontal overflow | 88 px | `span.status` (right edge 463 px) |
| /videos | 375 | horizontal overflow | 8 px | `input.vplayer-volume` (right edge 383 px) |

No focus-ring gaps and no accessible-name gaps on any route. No document-title findings.

## Not established (3)

| Route | Result |
|---|---|
| /continue | redirects a guest to /register (expected; the route is the sign-in continuation) |
| /people | redirects a guest to /login (auth wall; the roster derives it as a guest page) |
| /system/public-records | HTTP 502 from nginx |

## Repairs and the closing re-sweep (same evening, 8:45 to 9:25 PM Eastern)

Every finding above was repaired at the desk and the touched routes were swept again in Edge on the host
(result files `storage/logs/a11y_host3/*.json` and `a11y_host4/*.json`):

| Route | Repair | Re-sweep |
|---|---|---|
| /building | `Components/Progress/StageBars.vue`: the step-card kind label, note and empty line use the subtle token instead of raw gray-500 | clean |
| /achievements, /register, /support/report, /civic/commons/halls, /civic/commons/square | the sentence links carry `class="prose-link"`; the shared rule in `components.css` now covers `a.prose-link` | clean |
| /coverage-ops | `System/CoverageOps.vue`: the three table wrappers are named focusable regions (`tabindex="0" role="region" aria-label`) | clean |
| /learn/manage | `Learn/MaterialManager.vue`: the module heading row wraps and long titles wrap inside the column | clean |
| /videos | `components-v2.css`: the transport row wraps and the volume slider is bounded | clean |
| /system/public-records | `PublicRecordsController`: the legislature facet no longer joins every legislature on the box (940,328 rows) on every request, and the four register counts no longer scan 16.7 million rows inline. The total is the sealed sequence high-water mark; the per-kind counts are cached for 15 minutes; the facet holds the active legislature only. Guest GET: 5.9 s cold, 0.9 s warm, was 502 at 70 s. The page then showed one link-in-text-block node (the audit-chain link), fixed the same way | clean |
| /people | `tests/browser/roster/roster.mjs`: viewer-bound pages are a third pinned list, never scanned (40 guest pages, 1 viewer-bound) | roster pin passes |

Pins: `a11yContrastFixes` (StageBars), `a11yProseLinks` (the six files and the selector), `a11yTableFocusTitle`
(CoverageOps wrappers), `PublicRecordsFacetTest` (no legislature scan, no inline counts, one row by id,
high-water mark plus cached kind counts).

Follow-up filed: a typed legislature search for the public-records filter needs an index on
`jurisdictions.name` (a prefix search over 951,626 rows takes 3 to 6 s without one).

## Consequence for the work list (as first assessed, before the repairs)

- W-0234 (Atlas): `/atlas` clean at both widths; the semantics are pinned by the a11y lane's tests; after-shot taken in the operator's Edge. Done.
- W-0336 (color contrast): open, `/building` carries 14 nodes at each width.
- W-0337 (prose links): open, 5 routes carry link-in-text-block.
- W-0338 (scrollable regions and page title): open, `/coverage-ops` carries 3 scrollable-region-focusable nodes at 375 px; the document-title half is clean.
- New: `/system/public-records` answers 502 for a guest; `/learn/manage` and `/videos` overflow at 375 px; `/people` is auth-walled while the roster lists it as a guest page.

## Single-route pass (operator procedure, 9:15 PM to 10:15 PM Eastern)

The roster was refreshed after the reads lane opened the economy, sim console and operator pages to guests: 55 guest pages, 38 non-page endpoints, 1 viewer-bound page (`/people`). Every route then ran ALONE with a 30 s budget (HOLD past 30 s, requeued at 60 s, then examined), per-route results in `storage/logs/a11y_one/*`.

| Result | Count | Routes |
|---|---|---|
| PASS | 53 | every route not listed below, including `/jurisdictions` (17.4 s alone; its earlier crashes were the bulk runs) |
| Fixed this pass, then PASS | 6 | `/economy/help`, `/economy/stipend` (wallet links underlined), `/economy/units` (default lever label at the subtle token), `/operator/federation` (14 slate-400 texts raised, header and step chips, placeholder and field colours), `/operator/operations` (header colours; and a harness defect: the ready probe read only the first main/form/h1, this page opens with a text-less form), `/simworld` (26 grey-500 labels, page title) |
| Open | 2 | `/legislatures` (server fixed, sweep 98 s over 500 rows, pagination next, W-0441); `/simworld` (server 94 s from three planet-wide rails on every poll, lazy-load and bound them, W-0443; a cache patch was reverted on operator order) |

Per-route timing on the host: median 8 s; 33 of 45 under 15 s; the slow ones were `/login` 78 s (first host run, host at 300 MB free), `/building` 42 to 55 s.

## Close-out (10:35 PM Eastern): 55 of 55 guest pages pass

- `/legislatures` (W-0441): server 30.9 s to 1.8 s; zero nodes at both widths on its own run after the Chamber and Results row links took the underline. The scanner's own 98 s on 500 rows is not a concern once the page passes (operator ruling).
- `/simworld` (W-0443): server 94 s to 0.8 s. The three honesty rails (active-map count, over-bound chambers, seat-gap chambers) left the page and the 2 s poll; the page fetches them once after mount from `/api/simworld/rails` (0.8 s). The drawn-seat total and the gap now live on the map row, kept by statement-level database triggers (migration `2026_09_14_223000`), with partial indexes for drifted active maps and over-bound chambers; the 940,417 existing maps were filled once by `maps:drawn-seats-backfill` in chunks of about 24,000 (host-derived), resumable, about 10 minutes. Triggers proven end to end on a disposable database (`tests/concurrency/map_drawn_seats_trigger_check.php`: insert across maps, bonus seats netted, soft delete, move, hard delete, chamber resize, backfill function, drift index). Sweep alone: PASS in 19.1 s. A cache-and-scheduler patch built earlier the same evening was reverted on operator order before this fix.
