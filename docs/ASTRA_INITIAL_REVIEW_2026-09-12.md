# World of Statecraft: initial review

Reviewed 2026-09-12. Checkout: E:\fair-constitution-app, branch main, observed commit 7a5dc94e.

## Product intent

Operator briefing: World of Statecraft teaches civic roles through a functioning governance simulation. The immediate delivery is an OIDP conference demonstration world and an accelerated, participatory beta. The broader audience includes law, political science, and communications students, civic leaders, and their staff. Independent hosting, federation, language access, and multiple learning formats are product requirements.

The founding Template is historical context. Current implementation and settled operator rulings define current behavior. The operator explicitly confirmed that founding settings are configurable; default values must not become new hardcoded restrictions.

The Coalition's [strategy](https://cosmopolitancoalition.org/about/strategy/) describes localized, multimodal education and self-organization. Its [learning track](https://cosmopolitancoalition.org/course-track/cosmopolitan-engagement-track/) organizes lessons into courses. The application already contains a corresponding multi-track media component in `resources/js/Components/Media/MultiTrackVideoPlayer.vue`. Review and reuse this infrastructure before introducing another education system. Playback and catalog completeness were not tested.

## Verified environment

- Git origin is https://github.com/CosmopolitanCoalition/fair-constitution-app.git.
- GitHub CLI is authenticated as CosmopolitanCoalition with repo scope. No push was attempted, so branch write acceptance is not yet verified.
- Docker lists fc_app, fc_etl, fc_nginx, fc_vite, fc_horizon, fc_scheduler, fc_matrix, fc_mas, fc_postgres, fc_redis_queue, and fc_redis as running. Containers with displayed health checks report healthy.
- The reference extraction script completed using the bundled Python runtime. The system Python lacks python-docx and openpyxl. Generated outputs are ignored by Git.
- The welcome page loads at http://localhost:8080/. The visible instance name is United Earth.

## Confirmed findings

### 1. Public Atlas returns HTTP 500

Browser navigation to `/atlas` produced `Attempt to read property "population_year" on null` at `app/Http/Controllers/System/AtlasController.php:266`.

`homeGauge()` permits the latest legitimacy snapshot to be absent, but dereferences it when computing populationYear. Adjacent values use null coalescing. The correct missing-data behavior is already specified in the controller: unavailable measurements remain null.

Repair: preserve null for a missing snapshot, add a focused regression for a jurisdiction without snapshots, and verify the public page against the existing world. Do not generate or reset world data to make this page pass.

### 2. Welcome navigation disagrees with implemented routes

`resources/js/Pages/Home.vue` gives the Atlas card `href: null` and labels it Planned. `routes/web.php:326` serves `/atlas`, and `resources/js/registry/surfaces.js` already links it. The browser confirms the disabled welcome card.

Repair the Atlas first, then derive this destination from the existing navigation source. A route existing is insufficient evidence that a page works.

### 3. First-visit tour is not an independent short sequence

`resources/js/Pages/Tour/Index.vue` resolves FIRST_VISIT entries to their positions in the complete TOUR. Each entry uses `tourHref(o.i)`. `resources/js/composables/useTour.js` advances through the complete TOUR, not FIRST_VISIT.

Consequently, selecting a short-track stop does not make Next follow the short track. FIRST_VISIT also includes `/journeys` after the ballot, although it is stop 5 in the full tour. The live tour exposes those global step URLs.

Repair: define the intended conference sequence within the existing tour model and test its actual next/back behavior. Include concrete election and room resolution rather than assuming static destinations contain usable data. The full tour also contains a hardcoded committee-meeting UUID; its portability requires a fixture or runtime resolver check.

### 4. Entry-page language coverage is incomplete

Home.vue imports vue-i18n but places the hero, door descriptions, and tour call to action directly in English strings. Tour/Index.vue likewise contains direct English prose. The visible welcome selector offers seven languages; Polish is not among them.

The untranslated source strings are verified. Switching languages and reviewing translation quality were not performed. Use the existing translation workflow for these entry surfaces. Do not infer translation coverage from the presence of a selector.

### 5. World rollup does not consistently follow the ETL rules

`app/Services/WorldStatsService.php` uses a fixed CHUNK of 25,000 for its jurisdiction pass. Its reach and representation methods then issue unchunked sums/counts, including legislature members and candidacies. Some counts also omit the service's authoritative-jurisdiction predicate, including candidacies and committees.

These are source-verified findings, not measured performance results. No rollup was executed. A corrective pass should use bounded input rosters, host-derived work sizing, resumable progress, and consistent federation scope. Validate counts against a small isolated fixture before comparing a bounded sample from the live world.

## Suggested implementation sequence

1. Repair the public Atlas and reconnect the welcome card. Verify absent-data and populated-data states.
2. Complete one conference journey: arrive, inspect the world, find a place, observe an institution, enter the beta, perform an eligible civic action, and see its recorded outcome. Use existing demo and residency rulings in the open-question rubric; do not reopen settled choices.
3. Consolidate navigation and contextual actions for that journey. Teach within the action through the existing Learn and media components.
4. Verify localization, keyboard access, narrow screens, and slow connections on that same journey.
5. Repair and profile the rollup path and then inspect ingestion bottlenecks using bounded diagnostics. Do not resize the box or restart the simulation as an optimization shortcut.

This is a proposed engineering sequence, not a claim that those workflows already pass. Any new operator decision belongs in `docs/plans/ui/tools/gen_app_rubric.py`, per CLAUDE.md.

## Validation and boundaries

Ran the four existing scripts with Node's test runner: tourMode, tourPlacement, coverageDrift, and liveRoomPolicy. All four passed. These checks validate limited JavaScript behavior and source contracts; they do not validate complete workflows or live database state.

Read CLAUDE.md, extracted the reference corpus, reviewed portions of the constitutional, architecture, and roles documents, traced the welcome/tour/Atlas implementation, inspected setup setting validation and the statistics service, and opened the local welcome, tour, and Atlas pages. This was an initial review, not an exhaustive audit of 134 Vue pages or the full policy corpus.

No application code, settings, simulation records, Docker services, or schema were changed. No builds, migrations, bulk queries, commits, or pushes were run. This report is the only tracked-file addition. Coordinate file ownership with Claude before the first shared-code edit. Follow the operator's direct-to-main workflow with specific-file staging, targeted validation, upstream synchronization, and no force push.
