# World of Statecraft — first conference UI iteration

12 September 2026. Approved by the operator; Claude paused for this pass.

## What changed

- Six everyday destinations: Today, Places, Community, Work & trade, Learn & help, My profile. Legislative maps and role exploration have direct shortcuts. Host/development tools and deeper activities are collapsed separately. Navigation closes after moving to a page.
- Places is a public hierarchy browser with ancestor links, direct-child search and pagination. It reaches every level independently of residence. Search is intentionally within the selected parent. Existing activation controls remain at `/jurisdictions?view=operations`.
- Legislative map sidebars link to the viewed place, its children, and the world. Existing mapping, apportionment and certification algorithms are unchanged.
- `/explore?jurisdiction=pol-1-poland` offers eight civic perspectives. Each opens the selected place's existing institutions. The election-board read surface accepts a selected active board independently of membership. Role selection does not change identity or grant credentials.
- Live rooms use court, legislature, committee and board seating zones, with a list alternative. Connected participants stay audible when the assigned roster is collapsed. Assigned officeholders and connected callers are distinguished. Public display names use the existing presenter; legal names and raw Matrix IDs are not fallback labels.
- Public committee hearings use the existing opt-in call client. Voice authorization verifies the exact public room, jurisdiction and institution before local minting or peer forwarding. Unknown, private, encrypted and tombstoned room targets are rejected.
- Community pages share direct links to recorded discussions, live rooms and private messages. Explicit place selection on public square, halls and petitions is separated from the viewer's lawful posting choices.
- Learn restores legacy stored translation keys and five legacy curriculum surface IDs, renders authored teaching before the comprehension check, and links roles, guided journeys and videos from one hub.
- Work & trade puts buying, selling, jobs, wallet and organizations first. Its home controller uses at most four point reads. Global ledger verification, aggregates and counts no longer run on every visit. Missing metrics remain unknown, rather than being reported as zero or healthy.
- Today activity links retain their election IDs or place slugs. Atlas tolerates missing reach history. A separate shared shell-instance payload preserves navigation mode on pages with their own instance metadata.

## Demo integrity and local activation

The additive migration `2026_09_12_120000_demo_reversal_evidence.php` was applied locally. It adds captured after-images, per-write reversal outcomes and session closing state, and replaces the capture function without reinstalling world-table triggers. Cleanup compares the current row with its captured after-image, preserves later changes and existing foreign-key dependencies, and resumes per committed row. Conflicts are recorded in the audit summary.

Laravel configuration was recached and `fc_horizon` restarted to load the changes. No simulation was started, stopped or reverted; no world reset or production frontend build was run.

The local runtime already had `CGA_RESIDENCY_INSTANT=true` and `game_mode=sandbox`. Its instance-class check returned non-scale-demo. This iteration did not change the box's classification: reversible demo capture still depends on the existing scale-demo class rule. The participating beta continues using its existing account/authority rules.

## Verification

- Demo cleanup: 12 tests / 121 assertions against a separate guarded PostgreSQL fixture database, including interleaved sessions, dependencies, interruption recovery, idempotence and two-connection locks.
- Voice access/public token names: focused in-memory tests; no game-world writes.
- Economy home: 4 in-memory tests / 65 assertions; point-read budgets and no GET-time wallet creation.
- JavaScript: navigation coverage, tour mode/placement, live-room policy, seating/public names, and curriculum content mapping passed.
- Changed Vue scripts/templates were compiled individually. Changed PHP passed syntax checks. No `vite build` or full live PHP suite was used.
- Browser checks in the existing signed-in session: world search for Poland, Poland legislative map, drill into Greater Poland's map scope, role explorer, desktop and 390px courtroom layout, the seating-list toggle, the restored election-board lesson, Work & trade, and menu closure after navigation.
- Controller validation used database-enforced read-only transactions for hierarchy traversal, all generated Poland explorer links, and election-board observation.

## Remaining conference rehearsal work

This is a working first iteration, not a claim that every inventoried workflow is conference-ready.

- Rehearse actual calls with multiple independent accounts/devices, including camera/microphone permission, reconnect and remote peer transport. A rendered call control and unit tests do not establish network/media quality.
- Court, board and chamber seating layouts exist; institution-specific live AV wiring beyond the existing committee hearing and commons still needs a full workflow pass.
- Select and rehearse short live scenarios with populated candidates, agendas, cases and economic activity. This pass did not seed new activity or alter the completed simulation.
- Demo compensation remains conservative rather than complete isolation: asynchronous jobs do not inherit demo-session context, semantic dependencies without foreign keys are not detectable, and handlers must reject new references to inactive parents.
- Some advanced pages retain older scaling costs, including dedicated economy telemetry and petition creation's residency-population calculations. They need bounded/materialized reads before a full-planet load rehearsal.
- New strings are available to localization with English fallback; this pass does not certify every supported language, Polish translation coverage or assistive-technology combination.
- The initial 134-page inventory and interactive map remain the pre-change baseline. See [README.md](README.md) for the outstanding employer, assistance, share-issuance and other workflow gaps.
