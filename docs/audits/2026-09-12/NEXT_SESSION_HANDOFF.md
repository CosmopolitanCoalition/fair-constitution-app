# Next session handoff

This is a continuation aid, not authority over the implementation. Read `CLAUDE.md`, extract/read the reference documents as it requires, inspect Git status, and verify current code before changing it. The operator's settled rulings override older planning assumptions.

## Working environment and coordination

- Windows development checkout: `E:\fair-constitution-app`; app `http://localhost:8080`; Docker Compose project `wos`, containers `fc_*`. Ignore the stale C: checkout and `fcold_*` containers.
- GitHub: `CosmopolitanCoalition/fair-constitution-app`, direct `main` commits authorized; pull before push and use your own attribution. Claude was paused for this pass; re-establish ownership if another writer resumes. Never commit `.env`, credentials or runtime state.
- Existing simulated world must remain intact. Never reset migrations, truncate data, delete volumes or touch `E:\docker-data`. Simulation controls belong to the operator.
- Do not run a production frontend build on this loaded PC. Compile individual Vue components. Use targeted isolated SQLite fixtures and DB-free tests. All diagnostics and readers must be bounded; avoid world aggregates.
- The conference deployment is a separate Linux machine elsewhere on the internet. Claude will pull there. Code and repeatable deployment matter; this PC is not the attendee host.

## Verified work and remaining priorities

| Area | Delivered | Remaining |
|---|---|---|
| Consolidation/navigation | Shared task homes, navigable jurisdiction context, legislative maps, clearer labels/loading feedback. | Continue only against actual remaining duplication; do not restart the inventory. |
| Institutional rooms (2A) | Institution directories, Matrix discussion, LiveKit calls, floor layouts/controls, current-role seating, display-name handling, archives and role previews. | Independent physical devices, remote host networking/media, disconnect/rejoin and conference rehearsal. Local transport and fixtures are not proof of that deployment. See `LIVE_ROOMS.md`. |
| Hiring (2B/E1) | `d7698dd2`: paginated employer posting/review, immutable offers, exact applicant acceptance, existing organization countersign entry. | Full independent-actor scenario rehearsal. The acceptance fixture mocks the engine boundary; do not report a live completed hire. See `HIRING_WORKFLOW.md`. |
| Help (2B/E2) — next feature | Existing schema and public list. | Create/respond/withdraw/resolve workflow with explicit ownership and private-request protection; page new lists. |
| Organization powers (2B/E3) | Existing issuance handler and board machinery. | Share-issuance entry; audit CGC appointments, shareholder/worker elections and chair authority. |
| Browsing (1/B3/P2) | Market, organizations, agreements, asset selectors, session archives and hiring have bounded readers. Seller pending-order pagination is the final small follow-up in this pass. | Wallet transaction/stipend history, committee testimony beyond latest 50, other older institutional records, and public-finance/treasury readers. |
| Rehearsal and coverage | Targeted evidence recorded in audit files. | Complete civic/economic journeys, then settled translations, teaching and accessibility; final conference dress rehearsal last. |

Treasury needs a deliberate scoped redesign: `EconomyController::treasury()` still has unbounded accounts/revenue/levies and aggregate reads. Review public/private treasury account and ledger visibility while fixing it. Reuse saved `CurrencyReportService` publications where appropriate; never call world supply calculations during page entry. Do not declare P2 finished from the money-report improvement alone.

## Rollout and validation

Apply additive migrations on pulling hosts with the existing deployment process; never reset the world. Refresh route/config caches as appropriate and restart existing Horizon workers after shared worker-service changes. `2026_09_12_230000_work_offer_metadata.php` was applied locally, with all four indexes valid. Room transport environment and startup details are in `LIVE_ROOMS.md` and the repository deployment code; do not copy localhost signaling addresses onto the public host.

Hiring's last completed regression: 44 targeted tests / 514 assertions, six individual Vue compilations, and read-only browser checks of both work tabs. Docker Desktop briefly returned API errors but recovered; the final hiring test passed. No actual hiring, contract signing, simulation control or production frontend build was performed.

Final browsing follow-up: seller pending orders now page 20 at a time, with previous/next/first-page navigation and exact seller/listing checks. Four isolated tests / 62 assertions traverse 45 orders both ways and cover unauthorized/deleted listings, cross-listing cursors and malformed cursors before queries. `Listing.vue` compiled individually; changed PHP passed lint. `2026_09_12_231000_pending_order_directory_index.php` was applied locally and both indexes are valid; pulling hosts must apply it too. It indexes both pending-order seeks and the existing listing-wide order count. No live order was placed or settled during validation.

Use `DEMO_ACTION_PLAN.md` as the action table and the adjacent audit documents as evidence. Update states precisely, leaving unperformed remote/multi-participant checks marked unverified. The operator wants natural-language controls, purposeful screens, prominent maps, browsable live rooms and preservation of the whole jurisdiction tree; comprehensive translation comes after the flows settle.
