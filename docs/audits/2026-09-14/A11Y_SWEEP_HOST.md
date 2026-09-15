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

## Consequence for the work list

- W-0234 (Atlas): `/atlas` clean at both widths; the semantics are pinned by the a11y lane's tests; after-shot taken in the operator's Edge. Done.
- W-0336 (color contrast): open, `/building` carries 14 nodes at each width.
- W-0337 (prose links): open, 5 routes carry link-in-text-block.
- W-0338 (scrollable regions and page title): open, `/coverage-ops` carries 3 scrollable-region-focusable nodes at 375 px; the document-title half is clean.
- New: `/system/public-records` answers 502 for a guest; `/learn/manage` and `/videos` overflow at 375 px; `/people` is auth-walled while the roster lists it as a guest page.
