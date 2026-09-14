# Education, languages, accessibility and setup review lane (L1 · education, L1 · achievements, L1 · languages, L2 pass 1, Setup · schema, Setup · installer, R2)

Date: 2026-09-14. Run on branch `review/edu` (worktree `.wt/edu`, base `aee71e9a`), each row built by one implementer, refuted by two reviewers (pass criterion; isolation), repaired where a finding held, committed per row, merged into main as `66ba6e34`. PostgreSQL rows used disposable databases (`cga_edu_*`, `codex_lessons_*`, `cga_schema_*`) created from the baseline dump and dropped afterwards; the live-PostgreSQL helper was pointed at those names only, never at the world database. No protected file was edited. Docker Desktop's engine API returned HTTP 500 for a period during this lane; every result recorded here is a run whose output was read, not a dropped exec.

## L1 · education (commit d620ede9). Passed.

Runs: `learnFlyout.test.mjs` 8 of 8; `lessonVideo.test.mjs` 7 of 7; `lessonContent.test.mjs` 3 of 3; `LessonVideoTest` and `LearnProfileReadFixtureTest` OK (10 tests, 69 assertions); `IsolatedLessonWorkflowTest` OK (3 tests, 70 assertions) on a disposable database; `LearnPagesTest` and `EducationFormsTest` OK (11 tests, 76 assertions) with the live helper pointed at a disposable database loaded from the baseline dump and migrated.

The Learn drawer renders live surface controls and links to the intended Learn home and the video library; the five new surface IDs (work, help, help detail, rooms directory, video library) resolve authored guidance; lesson and video links resolve through the real controllers; lesson bodies carry translated teaching strings, not only IDs; the legacy AppShell carries the Learn disclosure for the Dev kits and the operator operations page, and the V2 shell for setup and operator pages.

Flags, not defects: no test enumerates the concrete non-V2 page components (the disclosure is proven on the shell's Learn-only command bar); Auth/Login, Auth/OperatorLogin, Auth/Register and Operator/Console mount no shell and carry no Learn drawer (rubric question `learn-disclosure-auth-pages`).

## L1 · achievements (commit cc273f4e). Passed.

`tests/Unit/IsolatedAchievementJourneyTest.php` (new) plus the existing catalog, earner and profile-read tests: verified actions award the proper person once; refusals and retries award nothing; the sweep run twice on the disposable database creates no duplicate; new awards appear in the catalog and profile and confer no power. Recorded gap: 12 catalog entries (8 economic, 4 jurisdiction milestones) remain `awaiting_ui` and are unwired to any trigger by design. Flag: the pre-existing live-PostgreSQL helper has no disposable-name guard and defaults to the world database when `LIVE_PG_DATABASE` is unset (carried from the judiciary review).

## L1 · languages (commit 07b49170). Defects recorded; row retained.

`tests/js/i18nCoverage.test.mjs` (new, DB-free): 8 subtests, 3 pass, 5 fail by design. The instrument passes its self-check (detects a seeded gap, passes identical sets); the conference Chinese tag is `zh-Hans`; the fallback path renders English, not raw key ids. Conference locales read from the registry: ar, es, fr, hi, pt, zh-Hans.

Defects (punch list LG-1 to LG-3):

1. Fourteen English namespace files are absent from all six conference locales (c_bill, c_community, c_explore, c_host, c_learn, c_legislature_workspace, c_live_commons, c_loading, c_navigation, c_references, c_rooms, c_term_sync, flows, places); fr and pt additionally lack c_shell, c_shellv2, chrome and registry. Every string in them renders as English for every non-English attendee. The Learn flyout guides and video-library scenario notes (c_learn, 127 keys) and the room and voice controls with their audio error and fallback strings (c_rooms, 83 keys) are inside this set.
2. Across the present files, 10,597 English keys are untranslated in the six conference locales; the largest gap is the lessons namespace c_education (165 to 259 keys per locale).
3. `resources/js/Components/Media/MultiTrackVideoPlayer.vue` is not internationalized: the audio and captions labels, the transport aria-labels and the three audio, video and caption error and fallback messages are English literals with no vue-i18n binding.

Not assessed: human translation naturalness (a separate register concern); rendered-browser confirmation of the fallback (static analysis here). The stale `resources/js/i18n/coverage.json` snapshot (2026-07-30, 35 namespaces) is superseded by this diff.

## L2 · accessibility, pass 1 (commit bb7bd232; re-run in progress).

The first journey agent died on an API error before writing the static scan; the commit step made one unreviewed change on its own (an `aria-label` bound to the video title on the live `<video>` element; the binding resolves to the component's `video` prop). The static accessible-name scan over every .vue is being re-run as a proper journey with two reviewers; its evidence lands in this file when it completes. Browser-level keyboard, focus, contrast and narrow-layout checks are the L2 browser pass in the browser lane.

## Setup · schema (commit b05aba0d). Passed for the database half; restart criterion retained.

`tests/concurrency/schema_baseline_migration.php --run`: a disposable database created from template0 with PostGIS, the baseline `database/schema/pgsql-schema.sql` loaded, `php artisan migrate --force` applied cleanly (the post-flatten additive migrations), a second migrate run a no-op, the required singletons and default records present, `federation:init` run twice with the identity unchanged. The world database was never touched. Not established this pass, by register scope: identity persistence across a full restart of a uniquely named disposable Compose project (rubric `second-compose-project-box-e` = B, deferred to a Linux host or the cloud box).

## Setup · installer, stub phase (commit f957a49c). Passed for the stub contracts; cold install retained.

`tests/deploy/test_installer_contracts.sh` (new) and `tests/deploy/test_installer_contracts.ps1` (new, added after a reviewer finding that only the Linux deployer was exercised): ready and not-ready gates, configuration generation failure and missing output, custom and existing project, key preservation, interrupted join and retry, no destructive fallback and no identity regeneration on ordinary resume, for `deploy.sh` and `deploy.ps1`. Not established: the cold container install on a Linux Docker host. Flag recorded for the punch list (DP-1, pending rubric `windows-public-deploy-policy`): `deploy.ps1` has no public-Matrix configuration-generation gate (the bash deployer gates at deploy.sh:452 and :456), so a Windows public deploy would fall back to the committed development Matrix secrets.

## R2 · rooms over real transport. Blocked; harness prepared.

Live checks that ran against the running voice profile: Matrix appservice `whoami` HTTP 200; the real LiveKit SFU accepts the app-minted room grant (200), refuses an expired grant (401, token expired) and a forged signature (401). The harness (`tests/Unit/RoomTransportTest.php`, `tests/transport/transport-media-workflow.test.mjs`) covers door refusals with zero transport calls, a real Matrix room round-trip and SFU grant, expiry and forgery checks; it is being verified and committed in the follow-up run. Not established on box E in this lane: WebRTC media both directions, interruption and rejoin, and the removed member's connection lifetime, which need a browser LiveKit client; with Playwright now installed (rubric `video-a11y-tooling` = A) that part moves to the browser lane.

## Verification on merged main

| Run | Result |
| --- | --- |
| `LessonVideoTest`, `LearnProfileReadFixtureTest` on main `66ba6e34` | OK (10 tests, 69 assertions) |
| `IsolatedAchievementJourneyTest` on main with `LIVE_PG_DATABASE` at a disposable `cga_ach_20260914_*` database (baseline dump plus 123 migrations, dropped after) | OK (7 tests, 33 assertions) |
| `bash tests/deploy/test_installer_contracts.sh` | ALL INSTALLER CONTRACT CASES PASSED |
| `tests/js/i18nCoverage.test.mjs` | 8 subtests, 3 pass, 5 fail (the recorded defects) |
