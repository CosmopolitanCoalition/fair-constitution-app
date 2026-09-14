# Browser review lane (L1/L2 · video, L2 · accessibility pass 2, D1 rehearsal runner, R2 media)

Date: 2026-09-14. Run in the main tree on branch main (the running app is served from it), one implementer per row, two adversarial reviewers, repair where a finding held, one commit per row. Tooling: operator ruling `video-a11y-tooling` = A; Playwright 1.58.2, axe-core 4.11.1 and @axe-core/playwright added in `ff6a5e73`; Chromium downloaded inside the fc_vite container (not in the image); `playwright.config.mjs` at the repo root (testDir `tests/browser`, headless, baseURL `http://nginx`, `--disable-dev-shm-usage`). The live app was navigated read-only as a guest; no login, no form submission, no demo session. Docker Desktop's engine API returned HTTP 500 for long stretches during this lane; results below are runs whose output was read from the per-route result files, not from a dropped exec.

## L1/L2 · video (commit 3351442b). Passed.

`npx playwright test tests/browser/videoDecoding.test.mjs`: 12 passed, exit 0.

The real `MultiTrackVideoPlayer.vue` is mounted through the running Vite dev server (`tests/browser/harness/`), with fixture media generated in the browser at test time (canvas capture plus MediaRecorder: a VP9 master, an Opus dub, two inline VTT tracks; no media binary committed) and served by route interception at a same-origin media base URL. Established on real HTMLMediaElement state: decode and play (readyState 4, decoded frame geometry, currentTime advancing), seek through the real range control, per-language audio dub switch through the real select (audio element re-pointed and re-synced), caption switch including an RTL Arabic cue, a buffering state from a withheld route, reload with language preferences restored, a 500 master surfacing the retry control and recovering, the poster placeholder path with a null base URL (no video element, disabled play and seek, live language selects), operable transport and captions toggle. Codec matrix by observation: H.264/AAC mp4 and VP9/Opus webm both decode on the Chromium build the config launches. The live `/videos` page as a guest renders the library (61 film rows, one featured poster-mode player, no console errors).

Flags, not defects: the live page lists 61 films while `config/cga/media.php` holds 62 ids (not investigated); from inside the container the app's cross-origin module load from Vite needs an allow-origin header the host browser already receives, which the live-page test adds to Vite's real responses; the bundled Playwright ffmpeg is stripped (no lavfi, VP8 only) and was rejected as a generator.

## L2 · accessibility, pass 2 (browser) (commit 9e8b7e84). Defects recorded; row retained.

`tests/browser/accessibility.test.mjs` with `tests/browser/roster/` (the guest-route roster derived from `routes/web.php` and `config/cga/surfaces.php` at test time and pinned in `route-list.json`: 41 pages, 35 non-page endpoints): 46 tests, one worker, judged from the 44 per-route result files.

| Check | Result |
| --- | --- |
| Routes established (ready marker or network idle) | 34 of 41 |
| Not established | 7: `/continue` and `/people` redirect to sign-in (recorded by name); `/learn/manage` returned 500 from a stale config cache on box E (cache rebuilt by the desk afterwards, now 200); `/tour`, `/coverage-ops`, `/legislatures`, `/system/public-records` crashed the renderer inside the 400 MB container (server answered 200; not established) |
| Horizontal overflow at 375 px | 0 routes |
| Tab walk: focus indicator gaps / accessible-name gaps | 0 / 0 across 34 routes |
| Learn drawer, language switcher by keyboard | pass (open, close by Escape; switcher focusable and named) |
| html lang and dir per locale, first paint and client switch | pass (ar rtl, en ltr) |
| Video player captions and audio alternatives | pass (77 audio and 77 caption options, captions toggle with aria-pressed) |
| meta-viewport (pinch zoom) after the app.blade.php fix | fires on 0 routes |
| axe violation nodes (WCAG 2.1 AA) | 1,044 across 15 routes: color-contrast 1,008; link-in-text-block 30; scrollable-region-focusable 4; document-title 2 |

Defects (punch list A11Y-2 to A11Y-4):

1. Contrast (A11Y-2). The token `--gov-fg-subtle` (`resources/css/cga/tokens.css:94`) renders at 4.16:1 on the page background, below the 4.5:1 floor; its comment claims 5.57:1. `Social/Achievements.vue` dims role cards further with inline opacity (lines 89, 119, 145, 158; `.role-card--planned` in `components.css:818`), which produces 968 of the nodes. `Setup/Progress.vue:148` and `Social/Reach.vue` use Tailwind `text-gray-500` (3.66:1) on the dark page (32 nodes). `Atlas.vue:686` colours the eyebrow with an accent that renders at 1.84:1 (6 nodes). `/setup/bootstrap` has 2 nodes at 3.65:1.
2. Inline prose links (A11Y-3). 30 nodes on 11 routes rely on colour alone at as low as 1.41:1 against the surrounding text with no underline (`.citation a` and the generic prose link style).
3. Semantics (A11Y-4). The shared `DataTable` scroll container (`components.css:480`) is not keyboard focusable when it overflows (4 nodes on `/system/clocks` at 375 px); `Setup/Bootstrap.vue` paints with no page title (2 nodes).

Re-check of the sign-in pages after the Learn bar landed (LE-5, merge 688627ad): pending the engine; recorded in the handoff when run.

## D1 · rehearsal runner (commit 50014c3e). Blocked; runner prepared.

`docs/demo/D1_REHEARSAL_PLAN.md` (the presenter journey step by step: actors, pages, form IDs, expected refusals and navigation state) and `scripts/demo/d1_rehearsal.mjs` with the steps as data (`scripts/demo/d1_steps.mjs`): `--plan` prints the steps without a browser; `--dry-run` opens each read step as a guest and asserts it renders; every action step is an explicit not-implemented stub that refuses without a demo session. A reviewer caught one wrong form id in the plan (residency declaration is F-IND-003, not F-IND-001); fixed. The rehearsal itself stays BLOCKED on Setup · worlds, Mesh/rooms · TLS and Host · rollout/internet, all deferred by the 2026-09-14 rulings.

## R2 · rooms over real transport, media half (commit dd893861). Blocked from inside the container; harness prepared.

`tests/browser/roomsTransport.test.mjs` with a Vite-served harness importing `livekit-client`, grants minted in node with the committed development key pair (distinct presider, member and witness identities, a nonced room, a forged grant, an expired grant), SFU URLs from `SFU_WS` and `SFU_HTTP` (a reviewer caught them hardcoded; fixed). Established from Chromium against the real SFU: the join and signaling layer for the three identities, retained identity, the forged grant refused, the expired grant refused; floor and history are covered by the case journey and the PHP harness. Not established: media frames both directions, interruption and rejoin with media resuming, and the removed member's live connection loss. Cause: the SFU advertises node IP 127.0.0.1 (`docker-compose.yml:337`) and STUN is blocked from inside fc_vite, so ICE never completes in the container. Escape hatch recorded in the test: run the same file from a host browser with `SFU_WS=ws://localhost:7880 SFU_HTTP=http://localhost:7880`. A vacuous removed-member assertion was noted by a reviewer and the test now polls the participant roster before the removal.

The PHP half (`tests/Unit/RoomTransportTest.php`, branch review/edu, merge cd289c3c) proves the app's own grant against the real SFU, the expiry and forgery refusals, a real Matrix room round-trip and door refusals with zero transport calls: OK (3 tests, 33 assertions) on main.
