# World of Statecraft — directories and institutional workspaces

12 September 2026. Continues [the consolidation follow-up](CONSOLIDATION.md), following the operator's approval to consolidate and report of the slow All Organizations link.

## Organization directory

The old registry fetched every active organization, built related records per row, and filtered in the browser. PostgreSQL's catalog estimated approximately 520,000 organization rows on this development instance. That estimate was read from statistics, not a world-wide count.

The directory now selects 25 organizations before resolving their place, board, endorsements and acquisition status. Next and Previous request another page on demand. Name-prefix, type, structure and exact-place filters run on the server; changing filters starts a new page sequence. Indexed composite cursors avoid offsets and full-register counts. Registration is one closed disclosure that mounts its form when opened; the duplicate club form is gone.

Name search deliberately matches the beginning of a name. There is no contains search or endorsements-only filter in this iteration. Any place can link to its organizations through Places; viewing a place does not add it to the resident's registration choices.

Five concurrent, additive indexes support worldwide name order, place/name, type/name, structure/name and type/structure/name. Related reads are restricted to the current page. An invalid cursor is rejected before directory reads. Ownership changes without an organization now redirect to the directory; invalid or unknown organization IDs cannot fall back to global registers. Dissolved organizations retain their selected ownership history.

## Consolidated workspaces

| Family | Result |
|---|---|
| Legislature overview / chamber / session / speaker | Shared navigation retains the selected legislature. Overview leads with maps and seat/term summaries; Members & chamber owns the roster and oath entry; Session owns session business and public records; Speaker work retains priorities and its distinct controls. Maps and bills remain prominent. Closed-session records remain readable. |
| Host Home / Console / Roles / Operations / Federation | One Host overview at `/operator`; `/operator/console` redirects without building another snapshot. Overview, Capabilities, Network and Host settings group the existing destinations. Detailed diagnostics collapse beneath the overview. The duplicate world jurisdiction counts are removed. |
| Work, sales and personal agreements | One My agreements entry contains independently paginated work/sales and personal sections, 20 records per family. Each personal record opens one authorized agreement; its composer reads no existing agreements. Existing signature and proposed-change controls remain on that record. |
| Market / Exchange | Goods and ordinary registered assets have one home in Market. Work and Requests for help have URL-backed tabs. Shares contains actual share offers and the viewer's holdings, with 20-offer cursor pages. It no longer computes a world equity leaderboard, telemetry snapshot or trade tape on entry. Shared Work & trade navigation connects market, agreements, wallet and shares. |

Public display names are used in the changed legislature presenters. Speaker work retains its existing member-only read boundary; broader role exploration is separate follow-up work. Host authentication remains separate from citizen authentication. Agreement terms remain limited to their existing parties and authorized organization members. No constitutional engine actions or permissions were changed.

## Read paths and deployment

- Speaker priorities are filtered to the selected legislature's sessions before pagination, instead of taking the world's latest 50 and discarding unrelated rows afterward.
- Agreement reads begin with the viewer's signer, counterparty, signing-authority or membership relationships. Organization and signer labels are resolved after selecting the agreement page.
- Market selects up to 100 listing IDs through an index before joining asset metadata. The consent picker selects 50 person IDs before sorting only that roster's names.
- Both additive migrations were applied successfully on the development instance: `2026_09_12_160000_organization_directory_indexes.php` and `2026_09_12_161000_transaction_directory_indexes.php`. The second adds listing/share ordering and person-first agreement access. Index builds run sequentially and concurrently with normal reads, using the host's configured maintenance memory. Interrupted invalid indexes can be rebuilt on retry.
- Pulling hosts must apply these migrations to receive the corresponding lookup performance. No route changes, worker restart, production frontend build, simulation operation, world-record replacement or volume deletion was needed.

## Verification

- **26 isolated SQLite tests / 516 assertions passed** together for organization pages, ownership selection, legislature navigation/priorities, host access/redirects and economy pagination/privacy. Fixtures explicitly use in-memory SQLite; they do not migrate or reset the simulated world.
- Changed PHP files passed syntax checks. All 28 changed Vue scripts, templates and styles were compiled individually. Additional economy render checks covered work/application context, configured representation thresholds, precise agreement links, signing and share controls.
- The combined JavaScript run passed all 10 checks across host navigation, route-coverage logic, tour state/placement and foreground loading. Tour-placement assertions now follow the shared arrival and learning components while retaining the rule that only the tour index starts the armed first step. Generated reference labels match their canonical registries.
- Organization read-only probes, with a five-second statement timeout, returned a 25-row first page in 51 ms, a matching prefix page in 11 ms, and an empty prefix in 2 ms. These are local directory-service/database timings, **not complete browser navigation timings or a load test**. A late-page cursor plan sought directly into the name index.
- EXPLAIN checks confirmed indexed organization filter paths after adding the type/structure indexes. Transaction plans use indexed listing/share order, signer-first personal agreement input and the indexed union of the viewer's contract relationships; the checked plans do not scan the global agreement register.
- Signed-in browser reads verified organization search, next-page results and narrow-screen filters; Poland Overview → Members & chamber → Session preserved context and map access; the old host URL redirected to the guarded Host overview; My agreements, Shares and Market rendered the consolidated entry points. The current citizen session was not signed in as an operator, so authenticated host diagnostics received fixture coverage rather than live browser coverage. Empty economic sections were checked live; populated transaction flows used isolated fixtures.

## Remaining work

The maintained [demo action plan](DEMO_ACTION_PLAN.md) presents this work as phases, actions, statuses and completion checks. Its B1/B2 follow-up now replaces the market/work/help and agreement-party caps described in this earlier pass with paginated browsing and indexed search.

The consolidation families listed as outstanding in the preceding follow-up are implemented. Continue checking navigation while adding the remaining institutional room wiring and economic actions, then rehearse civic scenarios. Complete language, educational-content and accessibility coverage after those paths settle, as the operator requested.

Known follow-ups include paginating/searching the remaining market, work/help and person-picker caps; employer posting and decisions, help participation and share issuance; independent-participant room calls; broader Speaker role exploration and session archives; remaining term-sync/telemetry read costs; and the previously recorded board-action audit. This pass establishes bounded organization browsing and clearer workspaces, not complete planet-scale load certification or end-to-end demo readiness.
