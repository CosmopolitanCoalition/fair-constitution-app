# Browser review lane (L1/L2 · video, L2 · accessibility pass 2, D1 rehearsal runner, R2 media)

Date: 2026-09-14. Run in the main tree on branch main (the running app is served from it), one implementer per row, two adversarial reviewers, repair where a finding held, one commit per row. Tooling: operator ruling `video-a11y-tooling` = A; Playwright 1.58.2, axe-core 4.11.1 and @axe-core/playwright added in `ff6a5e73`; Chromium downloaded inside the fc_vite container (not in the image); `playwright.config.mjs` at the repo root (testDir `tests/browser`, headless, baseURL `http://nginx`). The live app was navigated read-only as a guest; no login, no form submission, no demo session, no env or docker change. Docker Desktop's engine API returned HTTP 500 for long stretches during this lane; results below are runs whose output was read.

## L1/L2 · video (commit 3351442b). Passed.

`npx playwright test tests/browser/videoDecoding.test.mjs`: 12 passed, exit 0.

The real `MultiTrackVideoPlayer.vue` is mounted through the running Vite dev server (`tests/browser/harness/`), with fixture media generated in the browser at test time (canvas capture plus MediaRecorder: a VP9 master, an Opus dub, two inline VTT tracks; no media binary committed) and served by route interception at a same-origin media base URL. Established on real HTMLMediaElement state: decode and play (readyState 4, decoded frame geometry, currentTime advancing), seek through the real range control, per-language audio dub switch through the real select (audio element re-pointed and re-synced), caption switch including an RTL Arabic cue, a buffering state from a withheld route, reload with language preferences restored, a 500 master surfacing the retry control and recovering, the poster placeholder path with a null base URL (no video element, disabled play and seek, live language selects), operable transport and captions toggle. Codec matrix by observation: H.264/AAC mp4 and VP9/Opus webm both decode on the Chromium build the config launches. The live `/videos` page as a guest renders the library (61 film rows, one featured poster-mode player, no console errors).

Flags, not defects: the live page lists 61 films while `config/cga/media.php` holds 62 ids (not investigated); from inside the container the app's cross-origin module load from Vite needs an allow-origin header the host browser already receives, which the live-page test adds to Vite's real responses; the bundled Playwright ffmpeg is stripped (no lavfi, VP8 only) and was rejected as a generator.

## L2 · accessibility, pass 2 (browser). In progress.

The first run covered route `/` before the engine failed. One inline fix is pending re-verification: the global viewport meta in `resources/views/app.blade.php` set `maximum-scale=1.0`, disabling pinch zoom on every page (axe rule meta-viewport, WCAG 2.1 AA 1.4.4, moderate); the attribute was removed. The complete sweep (every guest route, axe at desktop and 375 px, overflow, Tab walk, Learn drawer and language switcher by keyboard, lang and dir per locale, player alternatives) is being re-run with the reviewers' corrections (routes enumerated from the code, no swallowed waits, login-gated roster named). Its evidence lands here when it completes.

## D1 · rehearsal runner (commit 50014c3e). Blocked; runner prepared.

`docs/demo/D1_REHEARSAL_PLAN.md` (the presenter journey step by step: actors, pages, form IDs, expected refusals and navigation state) and `scripts/demo/d1_rehearsal.mjs` with the steps as data (`scripts/demo/d1_steps.mjs`): `--plan` prints the steps without a browser; `--dry-run` opens each read step as a guest and asserts it renders; every action step is an explicit not-implemented stub that refuses without a demo session. A reviewer caught one wrong form id in the plan (residency declaration is F-IND-003, not F-IND-001); fixed. The rehearsal itself stays BLOCKED on Setup · worlds, Mesh/rooms · TLS and Host · rollout/internet, all deferred by the 2026-09-14 rulings. A clean end-to-end dry-run capture was not obtained during the engine failure.

## R2 · rooms over real transport (browser media). In progress.

The PHP harness on branch review/edu proves the app's own grant against the real SFU and a real Matrix room round-trip. The media half (frames both directions from generated tracks, interruption and rejoin, forged and expired grants, a removed member's already-issued grant) is being driven from Chromium against the running voice profile. Its evidence lands here when it completes.
