# World of Statecraft — usability and consolidation follow-up

12 September 2026. Continues the approved [first iteration](ITERATION.md).

The subsequent [directories and workspaces pass](DIRECTORIES_AND_WORKSPACES.md) implements the remaining consolidation families listed below and fixes the All Organizations loading issue. This document preserves the earlier pass's verification record.

## Operator's clarified order

1. Consolidate overlapping screens and settle their navigation.
2. Connect remaining institutional rooms and rehearse calls with independent participants.
3. Complete missing economic actions, alongside the room work after consolidation.
4. Rehearse civic scenarios from beginning to end against the settled paths.
5. Review complete language and accessibility coverage, with the strings and educational requirements settled.

Address slow reads alongside the affected screens. Basic keyboard, status and responsive behavior remains part of implementation; a complete coverage review comes last. This order does not require another design approval before continuing.

## Consolidated surfaces

| Surface family | Current behavior | Preserved |
|---|---|---|
| Home / Launchpad | Both use one arrival component. Places, legislative maps and role exploration lead; Community, Work & trade and Learn follow. The disabled Atlas card and duplicate directories are removed. | Guest registration, invite/setup continuation, member Today and selected-place links. |
| Guides / Journeys | The old Guides URL redirects to the actual journey catalog. Authored journeys are publicly readable; only available journeys appear in the catalog. | Existing URLs, authored steps, member progress and authenticated progress writes. Guest reading does not manufacture personal progress. |
| Bill record / discussion | Shared bill identity and Record / Discussion navigation. Text, Votes and History have direct links. Discussion focuses on comments instead of repeating the record. | Bill text, votes, consent, versions, amendments, enactment and referrals. Comments are paginated within the selected bill instead of silently stopping at 200. |
| Organization detail / CGC / board / finances / representation / ownership | Shared navigation keeps the same organization selected across five destinations. Overviews retain seat summaries and the roster; the full representation explorer lives on Worker representation. | Charter, public-domain IP, board elections, chair selection, membership, economic privacy and formal ownership actions. |

These changes consolidate presentation and entry points. They do not remove distinct constitutional powers or claim that every linked action is complete.

## Loading, maps and readable labels

- A slim top bar and small status message follow foreground Inertia requests and ordinary same-window page links. Slow visits say they are still loading. Background polling does not flash the indicator or incorrectly fail an overlapping visit. Failure, cancellation, browser restoration and overlapping requests are handled separately.
- Initial HTML also contains a small loading notice while the development JavaScript modules arrive. Module-loading failure offers a refresh link. This makes waiting visible; it does not itself reduce server or development-asset latency.
- The election race boundary now uses the application's existing Protomaps basemap helper, including paint and label rules. The local map archive supplied tiles and the Krakow browser check showed roads, towns and the boundary together. Boundary loading has its own status and is cancelled when leaving the page.
- Shared form chips, form cards, page explanations, role labels, citations and setting components resolve reference codes to readable names. Internal form IDs and authorization payloads stay intact. Deliberate reference disclosures retain technical identifiers where useful.
- Registry form/clock names are exported by `scripts/export_ui_reference_labels.php`; `--check` detects generated-label drift without booting Laravel or querying the database. English reference strings are in the localization catalog. This is not a full translation or accessibility certification.
- Department pages explain board work and show actual term dates. The election schedule uses readable stages, and configuration displays use resolved values rather than asserting a fixed game-wide interval or district limit.

## Scoped reads and correctness fixes

- Election entry links honor an explicitly selected place before home residency. A guest without a selected place is directed to Places instead of being assigned an unrelated election.
- The home-election lookup filters active residency in SQL and selects the deepest matching place directly. It no longer loads all matching elections and lazily fetches each jurisdiction to sort them.
- Related elections and the empty-state schedule are scoped to the selected jurisdiction. Related race totals use scoped sums. The empty-state renderer's undefined model reference is fixed.
- Worker representation resolves a selected organization/department before reading its board. A boardless entity retains its identity. Unselected browsing uses a 20-row cursor page without a global count or offset scan. Thresholds resolve for the selected entity's jurisdiction.
- Bill comments query only the bill's bound discussion and use 50-comment pages. Public names remain display names; legal names are not fallback labels.
- An observer without access to an organization's ledger sees a restriction notice instead of an incorrect claim that no account or levy filings exist.

## Verification

- 20 isolated SQLite tests / 434 assertions cover election context and read budgets, public journey reading and protected progress routes, bill workspace records/comments, and selected/paginated worker representation. These tests enforce an in-memory database and do not alter the simulated world.
- JavaScript checks cover loading lifecycle and silent overlapping requests, reference labels and rendered text, navigation registry coverage, tour mode and authored lesson content. All 13 combined checks passed; all 6 focused navigation cases also passed independent re-review.
- Changed PHP passed syntax checks. Changed Vue scripts, templates and styles compiled individually, including render-function-only components. The generated reference-label check passed.
- Browser reads used the existing signed-in session: the Welcome page, an election transition with visible loading, the Krakow map, Agency 2's readable explanation and the selected Anne Arundel Community Health Corporation workspace (overview, representation, finances and board). The organization navigation wrapped on a 390px viewport. The legacy Guides URL redirected to Journeys and preserved the existing election-journey progress.

No migration, world-data mutation, simulation operation, worker restart or production frontend build was required for this follow-up. The route cache was cleared for the public journey routes. Full live tests and multi-participant media rehearsal were not run.

## Remaining work in order

- **Consolidation:** legislature overview / chamber / session / speaker tools; host Home / Console / Roles / legacy Operations / Federation; transaction and agreement overlap within Work & trade. Preserve each action's distinct authorization while giving it a clear institution or transaction context.
- **Costs on remaining screens:** organization registry and unselected ownership-transfer reads, term-sync and economic telemetry, plus other expensive reads in the original inventory. The improved loading indicator does not establish acceptable latency on these routes.
- **Rooms and economic completion:** finish the missing room wiring and independent-device call rehearsal; complete employer posting/decisions, help participation and share issuance entry paths identified in the inventory.
- **Board action audit:** the existing board controller still exposes generic owner-election administration to CGC agents. This presentation pass explains appointed CGC governors correctly but does not alter that controller behavior; verify the institutional action boundary during the board workflow pass.
- **Scenario rehearsal:** choose populated elections, bills/cases and economic activity; verify all participants' actions and recovery. This pass did not seed presentation data. Bill record/discussion received isolated fixture coverage; a populated live bill was not available in the scoped places inspected.
- **Coverage last:** finish the remaining direct code-heavy prose, translations and educational content, then test keyboard, assistive technology, narrow displays, captions and language/audio fallbacks across the settled scenarios.

The 134-page inventory remains a dated pre-change baseline. This follow-up records implementation progress rather than silently rewriting that source review as a claim of current end-to-end readiness.
