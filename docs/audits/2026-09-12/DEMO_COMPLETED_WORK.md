# Completed demo development

Updated 13 September 2026. Reference archive for finished development scopes and their passed internal checks. These items are removed from the [active punch list](DEMO_ACTION_PLAN.md) and require no repeat work unless a later change or test exposes a defect.

Where a larger item was mixed, only its finished scope is archived here. Full application journeys, combined room tests and PostgreSQL contention checks remain separate active test tasks. They are not reported as passed.

## Completed scopes

| Former item | Finished development and internal checks | Evidence |
|---|---|---|
| Phase 0 | Consolidated the identified arrival, learning, bill, organization, legislature, host and agreement families. Shared navigation retains institution/place context; maps and the full place tree remain reachable. | [Consolidation](CONSOLIDATION.md), [directories/workspaces](DIRECTORIES_AND_WORKSPACES.md), [iteration](ITERATION.md) |
| Organization and agreement directories | Cursor pages and scoped filters replace world-sized organization reads. Agreements, share offers and selected ownership-change workspaces have bounded access. | [Directories/workspaces](DIRECTORIES_AND_WORKSPACES.md) |
| B1 | Market/work/help browsing selects the requested tab and pages eligible records. Privacy and cursor traversal checks passed. | Directory foundation below; subsequent [help workflow](HELP_WORKFLOW.md) |
| B2 | Agreement-party name-prefix search replaces the first-50 picker; selected parties and drafts survive navigation. | Directory foundation below |
| B3, completed part | Owned-asset/listing selectors page and search within the owner scope, retaining selected assets and drafts. | Asset/report evidence below |
| P1 | Scoped term schedules and saved money reports replace the identified world-wide entry queries. Bounded report collection, retry/resume and stale-job protection passed their tests. | Asset/report evidence below |
| P2, completed parts | Seller pending orders and private stipend receipts have 20-row pages. Exact owner/listing scope and partial-response behavior passed. | [Handoff validation](NEXT_SESSION_HANDOFF.md); commits 690a9893, 54bee754 |
| R1 | Room directory, institution-specific calls/discussion, display names, floor controls and connected-role placement are implemented. Access, identity, floor, privacy and recovery checks passed. | [Room evidence](LIVE_ROOMS.md) |
| R2, completed transport checks | Two independent synthetic identities exchanged generated audio/video in both directions and after rejoin. Two synthetic Matrix senders exchanged messages in an isolated private room. | [Room evidence](LIVE_ROOMS.md) |
| R3 | Speaker/role previews, exact-institution links, session archives and hearing continuity are implemented and internally tested. | [Room archives and continuity](LIVE_ROOMS.md) |
| E1, feature implementation | Employer posting/review, immutable offers, applicant acceptance/withdrawal and countersign entry passed targeted feature and authority tests. | [Hiring evidence](HIRING_WORKFLOW.md); real-engine journey remains S1 |
| E2, feature implementation | Private help drafts/publication, private responses, matching, withdrawal/reopening and completion passed separate-actor service/controller tests. | [Help evidence](HELP_WORKFLOW.md); application journey remains S1 |
| E3, share implementation | Exact-agent issuance, public-name recipient search, ownership pages, decimal validation and bounded percentage calculation passed targeted tests. | [Share evidence](SHARE_ISSUANCE.md); application/engine journey and PostgreSQL contention remain S1/S3 |
| E3, board repairs | Exact-board/track certification, first-board provisioning, appropriate scheduling controls and completed-count certification entry passed targeted tests. CGCs no longer show impossible owner elections. | [Board evidence](ORGANIZATION_BOARD_AUDIT.md); missing chair/CGC actions remain E4/E5 |
| Setup/civic audit | Inspected Step 5/Dev scenario behavior, actual civic surfaces, isolation requirements and setup performance/correctness gaps. The audit/documentation task is complete and needs no runtime testing of its own. | [Setup/scenario audit](SETUP_AND_SCENARIO_AUDIT.md); implementation remains G1–G3 |

## Directory foundation evidence

B1/B2's combined regression passed **24 isolated tests / 683 assertions**. Fixtures traversed 120 records in each market section in both directions, checked privacy/exclusions, constant query counts and malformed cursors. Person search checked prefix/cursor/privacy behavior. Browser checks reached the work tab and a paginated 20-person search result. Both Vue screens compiled individually and changed PHP passed syntax checks.

PostgreSQL plans used the browse/search indexes, including late-page seeks. Additive migrations 2026_09_12_170000_agreement_party_directory_index.php and 2026_09_12_171000_market_section_indexes.php were applied locally. Live market sections were empty; populated traversal used fixtures. Repeated public names are a separate selector-refinement task, B4.

## Asset and reporting evidence

| Scope | Completed behavior and evidence |
|---|---|
| Owned assets | Twenty-item owner pages and literal prefix search. Listing eligibility is checked per item. Actual Inertia partial-response fixtures omit unrelated wallet histories and market feeds. PostgreSQL initial/prefix/late-page plans use the owner/name index. |
| Term schedules | Exact-place and optional legislature views with 25-row pages. Recorded terms, configured appointments and armed election dates remain distinct. Scope/privacy/cursor/settings fixtures and live place/legislature navigation passed. |
| Money reports | Page entry reads one saved report. Host-derived collection chunks retain the previous publication, checkpoint atomically, recover lost continuations and reject stale jobs. A local collection processed 3,885,557 source records in 161 seconds. Fixtures covered decimal totals, rollback, failure/resume and absence of source scans on GET. |
| Regression | **42 isolated tests / 2,010 assertions passed** across reports, term schedules, assets, market and people search. PHP syntax and individual Vue compilation passed. The separate recorded TermLockstepTest failure remains active as Q1; it is not a passing check. |

Local additive migrations: 2026_09_12_180000_currency_reports.php, 2026_09_12_181000_owned_asset_directory_indexes.php, 2026_09_12_182000_term_sync_directory_indexes.php. Indexes were valid; routes/configuration refreshed and Horizon restarted for the report job.

The money report reflects observations across its collection interval, not one simultaneous accounting snapshot. Concurrent inserts/balance changes can affect observations. This is a documented report limit, not an unfinished page feature. Owner identities and locations are excluded. Transfer-history date/ID optimization and larger session-history indexes can be evaluated under measured performance work when justified.

## Historical validation and deployment

The linked evidence files retain exact test scope and limitations. A passing feature test is not a claim of a completed full-application rehearsal. Do not repeat whole suites merely because this archive is revisited.

Pulling hosts apply additive migrations and refresh caches/workers through the existing deployment process; see the [handoff](NEXT_SESSION_HANDOFF.md). Existing worlds are retained. Human/device and conference-host checks have their [own deferred list](DEMO_DEFERRED_CHECKS.md).
