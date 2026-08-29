# -*- coding: utf-8 -*-
"""Rubric v3 — five views: UI Screens, Capabilities, Tech Debt, Fleet & Waves, Open Questions.
Native-feeling drill (search / filter / expand-all) with per-item punch detail, per the operator's
beloved v3_gap_dashboard, extended to carry the whole plan to a tested playable game."""
import json, io, sys, os
_HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, _HERE)
from wave4_data import FLEET
# Structured, fillable open-questions (options + owning lane). Resolved ones are read-only.
QUESTIONS = [
 {"id":"era1-retest-maps","q":"First-era problem maps not yet re-tested — which join the retest round before the mass respawn?","status":"open","lane":"map",
  "detail":"Sweep of the surviving first-era record (git era log, engine/test fossils, True-All-Scale + run-6 + pipeline memories). Every jurisdiction fossil in the engine and every draft-campaign specimen (Ukraine, Russia 6x6, Oromia 8+5, Puducherry 16-of-17, Germany +3, Serravalle, Zhoushan, LA/Kentucky/Texas...) is INSIDE the eight standards' scope trees — covered. Named era trouble NOT covered by the block, with the recorded evidence: (1) MALDIVES — the zero-pop atoll district (run-6: Gnaviyani 'FOA' 5 seats @ population 0, Dev -100%, ACTIVATED, 16/21 seats unrepresented; micro-island raster/geometry mismatch); (2) MALAYSIA — champion of the capital-metro stranded-giant class (~8-10% of first-era country sweeps: local-frame giant the root-frame BFS never drills into, 'No compositable children'; siblings Nepal (2 provinces), Portugal/Lisboa, Bolivia/Cochabamba, Cameroon/Mfoundi — one champion exercises the class, the era remedy was geodata repair and a retest shows whether the current engine clears it); (3) BANGLADESH — all-giant-children false-review class (gate fix 712a4fa at 550/551) + delta fragmentation, 552 seats over just 8 divisions = extreme giant recursion; (4) TAIWAN synthetic tree — dual-footprint ISO-independence (Kinmen lives under PRC Fujian; overlap keyed on the Taiwan-province row alone misses Kinmen/Matsu); (5) PUERTO RICO synthetic tree — dual-tree sibling (901 barrios exist only in the PRI tree; the USA standard covers usa-2-puerto-rico, not this legislature); (6) informational first-era giant-drift records: China +10 (1,129 seats/34 provinces — the deepest recursion anywhere), Japan +1 (metro giants + archipelago), Nigeria -1. All candidates verified live on the box with geometry-bearing children. Each pick = auto map on the block engine (6f4ee42) -> your walk -> gate panel if it earns it.","options":[
   {"k":"A","t":"The three era champions: Maldives + Malaysia + Bangladesh — each the named worst of an uncovered class (atoll zero-pop, capital-metro strand, all-giant-children delta). [desk rec]"},
   {"k":"B","t":"Five: the three + the dual-footprint pair (Taiwan, Puerto Rico) — adds the ISO-independence overlap class the pipeline memory flags."},
   {"k":"C","t":"The full named set (the five + China/Japan/Nigeria drift-record nationals) — eight retests, heaviest coverage, China alone is a 1,129-seat run."},
   {"k":"D","t":"None — the block's eight already cover every class champion the era record names inside their scope trees; proceed to the next phase."}]},
 {"id":"gate-expansion-maps","q":"Regression-gate expansion — which national maps join Earth+USA as auto-vs-manual standards?","status":"open","lane":"map",
  "detail":"History mined (lane-H mixed-autoseed record + the engine's comment fossil record + the Good Maps campaign): the broken-map/reprocessing era's failure classes each have a champion jurisdiction. Earth+USA already gate: planetary composite, satellite pools, fat-atom states, leaf-giant line-split (LA), smalls pools, Kentucky share-base, Texas land-then-compete. NOT deeply exercised: deep multi-level giant recursion (India — Draft-9 undercount birthplace, Kerala/Kozhikode one-frame law, UP shatter class), end-to-end archipelago (Philippines — chain adjacency + island exemption + spread-over-water; the iter-12 walk flagged PHL grouping), vertex-monster geometry + giant-separated composite (Canada — Nunavut 5.4M verts, Maritimes class), fat-atom linear chain (Egypt — the Nile 7+7+7+7 probe class), tiny-chamber floor dance (San Marino — 9 castelli, ladder/override at national scale), extreme aspect ratio (Chile — cut-vs-hull anticorrelation champion). Each new gate = one manual blessing (auto-draft + your tweaks, the USA method), stats saved to database/good_maps, added to the per-tweak gate run. Cost: every algorithm tweak re-runs all gates (~5-15 min per national map; India the heavyweight at ~1,118 seats).","options":[
   {"k":"A","t":"Core trio: India + Philippines + Canada — the three heaviest uncovered classes (recursion depth, archipelago, vertex-monster). Gate stays fast. [desk rec]"},
   {"k":"B","t":"Five: the trio + Egypt (linear fat-atom chain) + San Marino (tiny-chamber floor dance — seconds to run, and the planet sweep will hit that class ~hundreds of thousands of times)."},
   {"k":"C","t":"Earth + USA suffice — their 81+30 scopes already contain PHL/India/Canada AS SCOPES; skip national-depth gates and rely on the planet sweep's own review lane."},
   {"k":"D","t":"A different set — name the jurisdictions from your memory of the broken-map pile (note them in the box)."}]},
 {"id":"good-maps-adopt","q":"Good Maps achieved — adopt iteration 12 and proceed to the planet re-hook?","status":"open","lane":"map",
  "detail":"Campaign 2026-08-23, twelve full-planet iterations against your two standards (record: database/good_maps/, scoreboard artifact bf1116d9). Iteration 12 (engine 0c60da4) vs your maps, in your priority order — Earth: legality parity (2003 exact) · contiguity BETTER (19 clusters vs 21) · compactness BETTER (CHR .6334 vs .6320) · deviation ~parity (fit 37.86 vs 37.21). USA: legality parity (702 exact) · contiguity BETTER (5 vs 7) · compactness BETTER (.7729 vs .7677) · deviation trails (12.33 vs 10.96 — the measured price of the two wins above it; iterations 10–11 proved the frontier both ways). Known per-scope residue: California .740 vs your .834 (the 9.5-ceiling fat-assembly wall — fat plans strand members during BFS growth; a deeper structural build, deliberately deferred). Twelve draft maps from the campaign sit on the box under 'Good Maps — Auto Iteration N'.","options":[
   {"k":"A","t":"Adopt: iteration 12's engine is the auto-districting result; delete iterations 1–11's draft maps (keep 12's two as reference), and the planet-wide Type A re-hook ('Start planet-wide generation') runs on this engine. [desk rec]"},
   {"k":"B","t":"Adopt after your eye: walk the two iteration-12 maps in the mapper first (they are ordinary draft maps in the picker); then A."},
   {"k":"C","t":"One more push first: crack the California fat-assembly wall (assembly must reach 9.4-frac bins) before the re-hook — a structural build with planet-wide regression risk, worth its own session."}]},
 {"id":"cloud-geodata-source","q":"First cloud box — how does it get the planet's geodata?","status":"open","lane":"2",
  "detail":"Setup-loop audit 2026-08-23 (code read, not docs): a cloud VM has NO /archive, and the wizard's only in-box alternative — 'Download from official sources' — is hard-routed to the LEGACY single-threaded seeder (Step2_MapData.vue: 'a download run always uses legacy'; seed_database.py last touched 2026-05-25, attribution = the old per-level population_within() SQL the grid engine replaced). The proven 3h20m engine is the PULL engine, which only accepts archive|folder. The downloader writes exactly the layout the pull engine reads (geoBoundaries_repo/releaseData/gbOpen + worldpop_100m_latest under /data), so the pieces fit — the wizard just never offers download→pull. Also: maps:export/maps:import (export-bundle-equals-seed) can carry the finished planet from the home box instead of re-ingesting. FRESH-NODE-START-CLOUD.md + the rehearsal runbook are silent on the data step.","options":[
   {"k":"A","t":"First cloud run: stage the ~14 GB archive on the VM data disk (scp/rsync from D:\\fair-constitution-map-files), set ARCHIVE_PATH, force-recreate etl+app, run the PULL engine from 'Local archive'. Zero code; the engine that already holds the record. Doc the step in FRESH-NODE-START-CLOUD.md. [desk rec for the FIRST box]"},
   {"k":"B","t":"Carry the finished planet: maps:export on the home box (skip-rasters optional) → upload/import on the cloud box → accept. No ETL on the cloud at all; the cloud box inherits the accepted map (and, if you choose, the manual districts)."},
   {"k":"C","t":"Build download→pull: add source=download to pull-start (downloader step first, pool mode after, pump waits on the download phase), retire the legacy-seeder routing for downloads. The right long-term shape for instant-deploy templates; a real build, not a first-box task."}]},
 {"id":"cloud-burst-architecture","q":"Cloud world build: what machine shape runs the big ingest, and what happens to it after?","status":"open","lane":"2",
  "detail":"Full research: docs/plans/launch/CLOUD_BURST_SCALING.md. Short form: the engines size themselves from whatever host they wake on, one 48-core VM reaches the current 56-lane postgres ceiling, and the full build burst costs $33 to $200 pay-go or $8 to $46 spot. Serverless cannot serve the game itself (no inbound UDP for voice). A deallocated VM bills zero compute and resizes in place.","options":[
   {"k":"A","t":"One standard VM, the resize law: create D48 or D64 pay-as-you-go, run the full build (about $33 to $200 total), then deallocate, resize to D4as_v5, start. Simplest, one identity, disks persist, no eviction risk. [desk rec]"},
   {"k":"B","t":"Spot build box: same build for $8 to $46. Azure can evict with 30 seconds notice (the chunked resumable engines tolerate this; an eviction costs one chunk). Because spot cannot become standard, serving afterward means creating a small standard VM and attaching the same data disk. Saves roughly $25 to $150 for extra steps."},
   {"k":"C","t":"Container Apps Jobs worker burst: queue-scaled worker containers that scale to zero, next to a small always-on VM. Needs an image registry and VNet wiring, and the postgres ceiling still binds. The right long-term shape for instant-deploy volunteer templates; a real build, not the first run."}]},
 {"id":"cloud-idle-posture","q":"Cloud box between play sessions, before public launch: keep it running or deallocate?","status":"open","lane":"2",
  "detail":"A deallocated VM bills zero compute; disk plus static IP is about $45/mo. Always-on small is about $170/mo. Start takes about 2 minutes. Applies only to the window before real players exist.","options":[
   {"k":"A","t":"Deallocate between sessions until launch: about $45/mo idle, start on demand for playtests. [desk rec pre-launch]"},
   {"k":"B","t":"Always on from day one: about $170/mo, the box is continuously reachable for peers and testing."}]},
 {"id":"cloud-region","q":"Which Azure region hosts the first cloud box?","status":"open","lane":"2",
  "detail":"Prices verified for East US 2. Pay-go, spot, and eviction rates differ per region; the portal compares them side by side at create time.","options":[
   {"k":"A","t":"East US 2: prices verified there, near the home mesh. [desk rec]"},
   {"k":"B","t":"Another US region picked at create time by comparing spot price and eviction rate in the portal."},
   {"k":"C","t":"A European region: closer to September travel, higher latency to the home mesh, prices unverified."}]},
 {"id":"population-mode-autoboot","q":"Population mode — should CLK-06 actually BOOT a place, or only record the crossing?","status":"open","lane":"3",
  "detail":"Setup-loop audit 2026-08-23: acceptMaps documents 'population → CLK-06 boots each place as verified residents cross its threshold', but in code EvaluateCriticalPopulationJob only calls ActivationService::onCriticalPopulation, which writes the activation row to critical_population and audits it. Nothing then calls activate() — its callers are the CLI (jurisdiction:activate), the dev controller and elections:demo (the job's own docblock says so). So a population-mode world stalls at the crossing: no legislature sized, no map, no board, until the operator runs the CLI per place. ScheduleFoundingElectionJob already assumes population mode auto-activates ('a place activates because residents arrived').","options":[
   {"k":"A","t":"Close the loop: on a crossing in population mode, dispatch a per-place boot job (the same WF-JUR-01 pipeline ActivateSubtreeJob drives — seed + jurisdiction:activate, WITH the founding election since residents are real). Pin it. [desk rec]"},
   {"k":"B","t":"Keep the crossing as consent-only and make the operator's activation the explicit act — then fix acceptMaps' wording + the mode's description so the UI doesn't promise a boot."}]},
 {"id":"subtree-activation-shape","q":"The '+ children' button activates a place and every place under it. How should the system do that work?","status":"open","lane":"map",
  "detail":"REWRITTEN IN PLAIN LANGUAGE (operator order 2026-08-29). The button on the jurisdiction viewer activates one place plus everything below it. Example: Sri Lanka holds 14,409 places. Today the system boots them one at a time, start to finish, with no progress display. That is about 29,000 commands and hours of waiting. Most of the work can be done in bulk within minutes: sizing each place's seats and creating each place's board (the bulk method already exists and is proven). One part cannot be bulked: each place's FIRST ELECTION, because every election writes its own tamper-proof audit records one at a time. The choice: what should the button include?","options":[
   {"k":"A","t":"Bulk the seats and boards; skip the first elections. Every place is ready in minutes. Elections then happen through normal play or the simulation, which runs its own. [desk rec]"},
   {"k":"B","t":"Keep the first elections, but run many places at the same time with a progress bar. Faster than today, much slower than A."},
   {"k":"C","t":"Change nothing. The button is rarely used, so slow is acceptable."}]},
 {"id":"sim-org-bill-rates","q":"Sim org + bill generators — what should the simulation create, and how much?","status":"open","lane":"sim",
  "detail":"The sim now seats chambers, grows committees/departments, and forms courts through the real forms — but generates ZERO organizations and ZERO bills (only 'org affinity' priors are reserved in the plan). Rates are policy-flavored choices, not derivable from code. Real-world anchors: effective parties per chamber 2–8; US nonprofits ≈ 1 per 180 people; bills: one founding bill per committee ties to the already-ruled K(S) formula.","options":[
   {"k":"A","t":"Minimal-legible: 3 parties per active chamber + 1 founding bill PER COMMITTEE (rides K(S)) + a handful of orgs per local place — dialed via config, demo-scale not census-scale. [desk rec]"},
   {"k":"B","t":"Census-flavored: per-capita org rates (parties, nonprofits, businesses) + bills per session — realistic but mints millions of org rows planet-wide."},
   {"k":"C","t":"Defer both — demo ships with governance+courts only; orgs/bills stay player-driven."}]},
 {"id":"sim-leaf-courts","q":"Leaf-jurisdiction courts in the sim — when to build the committee-slate nomination round?","status":"open","lane":"sim",
  "detail":"JudiciaryStage forms constituent-mode courts (every non-leaf: Earth 232, USA 56, …) through F-LEG-017/021. LEAF courts derive COMMITTEE nomination — a different service verb (slate gated on a passed committee act) — and currently defer-with-reason on their sim items. ~90% of jurisdictions are leaves, but their courts are the least demo-visible.","options":[
   {"k":"A","t":"Next sim round, before the demo — full courts everywhere. [desk rec if the demo drills into villages]"},
   {"k":"B","t":"After the demo — the mapped/governed tiers carry the demo; leaf benches follow."}]},
 {"id":"edu-arming","q":"Education arming sequencing — how do untrained demo members behave when the gate arms?","status":"open","lane":"15",
  "detail":"education:seed arms the act-gate for 6 civic tracks; every untrained role-holder then redirects on their next role-act. Gates your browser walk of the training gate.",
  "options":[{"k":"A","t":"Pre-train demo members (seeders file F-EDU-001) — the walk shows a trained fleet. [lane 15 rec]"},
             {"k":"B","t":"Seed and leave demo members untrained — the walk DEMOS the redirect→train→act loop live."},
             {"k":"C","t":"Don't seed this wave — the gate is proven by the e2e only, no live walk."}]},
 {"id":"mass-pass","q":"Game-box mass pass — run the Type B mapper over the real ~9,708 flagged chambers?","status":"open","lane":"1",
  "detail":"The ~9,708 flagged chambers live on the GAME box, not dev. Waits on the Type B race fix so cleared chambers get the correct race.",
  "options":[{"k":"A","t":"After the race fix, pull lane 1's commits to the game box and run the mass pass now (ETL-chunked)."},
             {"k":"B","t":"Defer to the Wave-4 cloud rehearsal."}]},
 {"id":"lane3-compact","q":"Lane 3 compaction — run the keystone exit walk with fresh context?","status":"open","lane":"3",
  "detail":"The Live Civic Room is built but not yet WALKED end-to-end (the acceptance gate). The exit walk needs lane 3 compacted.",
  "options":[{"k":"A","t":"Compact lane 3 now — it resumes straight into seating a committee + the exit walk."},
             {"k":"B","t":"Hold lane 3 for now."}]},
 {"id":"ranked-live","q":"RankedBallot live standings — spin the secrecy-critical build?","status":"open","lane":"3",
  "detail":"Live provisional standings during an OPEN ranked ballot, without an in-request decrypt. Cold-start spec ready; cadence ruled daily-batch.",
  "options":[{"k":"A","t":"Trigger the fresh-session build now."},
             {"k":"B","t":"Defer — the electoral partial stays as-is."}]},
 {"id":"secondary-trade","q":"Secondary share trading — pull into Wave 4 or leave deferred?","status":"open","lane":"13",
  "detail":"You ruled share ISSUANCE (delivered). A holder RESELLING issued shares needs its own schema.",
  "options":[{"k":"A","t":"Pull into Wave 4 — lane 13 builds share resale on the exchange."},
             {"k":"B","t":"Leave deferred — the exchange shares floor stays honest-empty."}]},
 {"id":"handshake-4xx","q":"Cross-class federation handshake — return a graceful 4xx instead of 500?","status":"open","lane":"2",
  "detail":"A genuine cross-class handshake surfaces the class-rule refusal as an uncaught 500 rather than a 409/422. Pre-existing.",
  "options":[{"k":"A","t":"Fix in Wave 4 — catch it, return 409/422 gracefully."},
             {"k":"B","t":"Leave as-is (pre-existing, low priority)."}]},
 {"id":"b2-pairing","q":"B2 remainder rule — compact-first vs strictly-lowest-population pairing?","status":"open","lane":"1",
  "detail":"On real adjacency, compactness drives which children pair (population only orients the walk head). Lane 1 shipped compact-first.",
  "options":[{"k":"A","t":"Keep compact-first (shipped, matches intent)."},
             {"k":"B","t":"Force strictly-lowest-population pairing even when less compact."}]},
 {"id":"oversight-live","q":"Oversight — does 'public to watch' extend to the LIVE console of in-progress proceedings against NAMED members?","status":"open","lane":"3",
  "detail":"§10-1 makes government proceedings public. Open: the LIVE console of an in-progress removal/discipline against a named member, or only the sealed public record after?",
  "options":[{"k":"A","t":"Keep the live console gated; the public RECORD stays public. [desk rec]"},
             {"k":"B","t":"Make the live console public too (fully open in-progress)."}]},
 {"id":"orphans","q":"Orphan-surface deletions — remove unreferenced surfaces?","status":"open","lane":"6",
  "detail":"e.g. Elections/CandidateProfile.vue (unreferenced) + a couple of orphan surface records.",
  "options":[{"k":"A","t":"Delete the orphan surfaces."},
             {"k":"B","t":"Keep them for now."}]},
 {"id":"q4a-rooms","q":"Q4a — provisioning can't materialise court tiers / extra civic rooms (the schema forbids it). How should the scaling model resolve this?","status":"open","lane":"4",
  "detail":"One live court per jurisdiction (hierarchy is expressed ACROSS THE TREE via parent_judiciary_id); one public space per type. courtTiers/extraRooms have no lawful shape as extra rows at one place. The min_judges-from-tier fix already wires the meaningful bench scaling. Your framing: the court JURISDICTION stays singular; the scalable thing is rooms/chambers within the infrastructure.",
  "options":[{"k":"A","t":"Reframe courtTiers as a jurisdiction's tree-DEPTH; extra rooms = group-type or a future room model. Doc amendment, no schema, nothing built moves. [desk rec]"},
             {"k":"B","t":"Weaken the two uniqueness constraints (allow duplicate courts/squares). Needs a migration; trades two real safety rails."},
             {"k":"C","t":"Defer past this wave — the min_judges-from-tier fix already advances the scaling capability; build the room model later."}]},
 {"id":"advocate-gate","q":"Should advocates have a qualification catalog + an approval lifecycle, or stay an instant competence register?","status":"open","lane":"6",
  "detail":"The advocate-registration mockup wanted 'I attest to X law' checkboxes + a 'pending judiciary review' banner. F-IND-015 registers instantly (rejecting only on association + duplicate) — the bar is a competence REGISTER, not a merits gate on a client's Art. I right. A catalog + pending→approved lifecycle would be a RULE change + an advocates.status CHECK migration. Held honest-empty, flagged not smuggled.",
  "options":[{"k":"A","t":"Keep it a competence register — instant, no merits gate. [held honest-empty; desk rec]"},
             {"k":"B","t":"Add a qualification catalog + an approval lifecycle (rule change + schema)."}]},
 # ── Setup + Jurisdiction-Viewer walkthrough, opened 2026-08-04. Raised during
 #    the operator's live walk of the post-ingestion screens; lane "map"/"ui"
 #    rather than a fleet number (this is direct desk work, no lane owns it). ──
 {"id":"map-adopt-scope","q":"Map adoption — planetary only, or scoped per jurisdiction?","status":"open","lane":"map",
  "detail":"Your two phrasings point different ways: 'this is the map that applies to this CHAIN of jurisdictions' reads scoped; 'we as a planet kinda need to agree' reads planetary. Decides whether adoption carries a jurisdiction_id and whether a child can adopt a geography its parent has not.",
  "options":[{"k":"A","t":"Planetary only — one Earth-wide geography per adoption; every jurisdiction inherits it. Simplest, and matches 'we as a planet need to agree'. [desk rec for v1]"},
             {"k":"B","t":"Scoped — any jurisdiction adopts for its own subtree; children inherit unless they adopt their own. Federation-shaped, much larger build."},
             {"k":"C","t":"Planetary now, scoped-ready later — ship planetary but carry the scope column so B is additive, not a rewrite."}]},
 {"id":"map-select-authority","q":"Who SELECTS which draft map becomes the next one?","status":"open","lane":"map",
  "detail":"Given the ruled lifecycle (drafts → one selected → locked in → effective at a term boundary): anyone may DRAFT, but selection is the consequential act — it redraws every district for the coming term. Your census example sets the cadence by rule ('if we set a rule that we do a census every ten years'), which reads legislative, but you did not say who pulls the trigger on the map itself.",
  "options":[{"k":"A","t":"Bicameral legislative act, like any other — both chambers agree on the next map. Matches 'we as a planet need to agree'. [desk rec]"},
             {"k":"B","t":"Referendum / constituent supermajority — it changes everyone's district, so it goes to the people."},
             {"k":"C","t":"Operator/admin act — geography stays infrastructure even after setup."},
             {"k":"D","t":"Whoever the standing census RULE names — selection is automatic from the rule (census year → derived map → next term), with a legislative act only to override."}]},
 {"id":"merge-bulk","q":"11,919 same-space chains, no bulk apply — how do we work that queue?","status":"open","lane":"map",
  "detail":"Every repair endpoint is one-chain-per-POST, and the repair window shuts on acceptance. Working 11,919 by hand is not a thing. They are real-world (a source recording one village at two ADM levels), concentrated in CZE/SVK/IND/JAM/AUT — not damage.",
  "options":[{"k":"A","t":"Build a filtered bulk apply — 'merge all chains in ISO X' / 'at level N' / 'all', chunked + resumable per the ETL rule. [desk rec]"},
             {"k":"B","t":"Collapse them at INGEST — a single-child same-space pair merges on import, so the queue never fills with them."},
             {"k":"C","t":"Leave manual and accept with them open — they are real geography, and the flag is informational."}]},
 {"id":"adm-empty-walk","q":"The empty-ADM-level walk — fix it before the next fresh run?","status":"open","lane":"map",
  "detail":"Pass 2 walks levels 0-5 for every country unconditionally, and discover_geoboundaries_files() re-lists the whole 715-entry tree on EVERY call with no memoisation — measured 11.07 s a call. 678 nonexistent levels planet-wide ≈ 2 h of aggregate lane time, ~25 min of wall clock per run. The metadata that says which levels exist is ALREADY loaded in the same function (used for the split decision) and simply is not consulted.",
  "options":[{"k":"A","t":"Fix both now — skip levels the metadata says are absent, and memoise the directory walk. Small, self-contained, ~25 min/run back. [desk rec]"},
             {"k":"B","t":"Memoise the walk only — the cheap half, keeps the level loop untouched."},
             {"k":"C","t":"Leave it — the run completes correctly, it is only slow."}]},
 {"id":"list-stats-columns","q":"Jurisdiction list — which statistics become sortable columns, over what scope?","status":"open","lane":"ui",
  "detail":"You asked for the statistics on columns, sortable, 'for their internal chain'. /api/geodata/flags has NO jurisdiction scoping at all — no subtree filter, no per-row rollup — so this needs a new endpoint before any column can exist. Scope decides how expensive that endpoint is.",
  "options":[{"k":"A","t":"Subtree rollup per row — the 7 map-health checks + populated/total + population, counted over each row's descendants. Most useful, needs a recursive-CTE endpoint. [desk rec]"},
             {"k":"B","t":"Own-row + direct children only — much cheaper, no recursion, less informative at Earth level."},
             {"k":"C","t":"Defer until the jurisdiction map viewer conversation you flagged as coming next."}]},
 {"id":"setup-shell-menus","q":"Setup inside the main shell — which menus unlock when?","status":"open","lane":"ui",
  "detail":"You asked for the nav bar and menu present during setup (you are authenticated anyway), with irrelevant menus locked. Needs a rule for what 'relevant' means at each step.",
  "options":[{"k":"A","t":"Lock everything except Setup, Jurisdictions and Learn until setup completes — the two surfaces setup actually uses, plus help. [desk rec]"},
             {"k":"B","t":"Unlock progressively — each completed setup step unlocks the menus it enables (elections after districting, etc.)."},
             {"k":"C","t":"Unlock everything and let empty states speak for themselves; setup is just another surface."}]},
 {"id":"guest-banner","q":"'You're viewing as a guest' — where does it go?","status":"open","lane":"ui",
  "detail":"Today a full-width banner eating the fold above the map. You said it is 'kinda like a pop-up thing, and I guess that can be in the map area somewhere'.",
  "options":[{"k":"A","t":"Dismissible chip overlaid in a map corner — present, not blocking. [desk rec]"},
             {"k":"B","t":"Collapse into the header bar as a small badge beside Log in / Register."},
             {"k":"C","t":"Keep it a banner but show once per session, then remember the dismissal."}]},
 {"id":"about-surface","q":"'About this surface' on the viewer — move to Learn, or delete?","status":"open","lane":"ui",
  "detail":"You said it 'doesn't need to exist' on the viewer and belongs in the Learn tab. Confirming whether the CONTENT survives, because deleting is not the same as relocating.",
  "options":[{"k":"A","t":"Move the content into the Learn tab, remove the block from the viewer. [desk rec]"},
             {"k":"B","t":"Delete outright — the surface explains itself."},
             {"k":"C","t":"Keep on the viewer but collapsed by default."}]},
 # ── District-mapping INTEGRATION package (the four scaling docs, stored
 #    ea1ed8e). Reviewer-flagged operator questions; lane "scale". These gate the
 #    Fable-5 integration build that follows the geodata run. ──
 {"id":"scale-committees","q":"Committee provisioning — eager (built up front) or tier-gated (created at chamber act)?","status":"open","lane":"scale",
  "detail":"Setup audit §3: committees are NOT in the eager provisioning STEPS; Committee::create runs only in CommitteeService at chamber-act time. That is consistent with the tier dial (\'what exists in a place is a function of how far it has come\'), but your stated expectation was \'should be built out already\'. The reviewer asks you to reconcile the two explicitly so it is not a surprise during setup review. Your answer seeds a disposition table naming every sub-institution family\'s class.",
  "options":[{"k":"A","t":"Tier-gated — a committee is a chamber\'s ACT, created when the chamber acts (matches current code + the tier-dial doctrine). [reviewer\'s read of the code]"},
             {"k":"B","t":"Eager — provision committees up front in STEPS alongside executives/judiciaries, so an accepted planet already has them."},
             {"k":"C","t":"Per-family disposition table — committees tier-gated, other sub-institutions (departments, oversight organs, Matrix rooms) each get their own class; specify in notes."}]},
 {"id":"scale-record-disposition","q":"On disintermediation, how do a dissolving intermediary\'s NON-ACT records move to its constituents?","status":"open","lane":"scale",
  "detail":"Courts addendum §5 — the one place the package explicitly requests your review BEFORE build. Acts already clone-merge to each constituent as an independent copy with full history (built, F-LEG-030). The sketch extends that to the sealed records of ALL branches: chamber votes & proceedings, executive records & offices, judicial records. Each family needs a disposition — COPY-PER-CONSTITUENT (like Acts), SEAL-ONLY (immutable snapshot on the dissolved row, constituents start fresh), or TRANSFER. (Open cases and sitting judges are the two questions below.) The reviewer also asks that whatever table this produces read correctly IN REVERSE for `union`, or the asymmetry be justified.",
  "options":[{"k":"A","t":"Mirror Acts — copy-per-constituent WITH history for every record family; the two hard cases handled separately. Symmetric with union by construction. [desk rec — least surprise, matches the built Act path]"},
             {"k":"B","t":"Seal-only — snapshot the intermediary\'s records immutably; constituents inherit a citation, not the content. Cleanest, loses continuity."},
             {"k":"C","t":"Per-family — you specify copy / seal / transfer for chamber, executive, and judicial each, in notes."}]},
 {"id":"scale-case-venue","q":"Open court cases when an intermediary dissolves — where does venue go?","status":"open","lane":"scale",
  "detail":"Courts addendum §5 hard case. A case in progress at the dissolving intermediary\'s court needs a new venue among the now-independent constituents. Where court panels exist (§3.2), the panel a case belongs to is a natural target; without panels the choice is open.",
  "options":[{"k":"A","t":"To the panel where panels exist, else to the encompassing (grandparent) court the constituents re-parent to. [desk rec — uses the structure §3.2 already builds]"},
             {"k":"B","t":"To the specific constituent court the case\'s parties/territory map to (case-by-case re-venue)."},
             {"k":"C","t":"Seal and require refiling — the case closes without prejudice at dissolution; parties refile in the successor court."}]},
 {"id":"scale-judge-tenure","q":"Sitting judges when their court\'s jurisdiction dissolves — serve out, or close?","status":"open","lane":"scale",
  "detail":"Courts addendum §5 hard case; the doc notes \'Art. IV needs to say so.\' The B7 serve-out doctrine (a fresh grouping while sitting members serve out) suggests seats close at TERM rather than at dissolution, but the constitution has not stated it for judges specifically.",
  "options":[{"k":"A","t":"Serve out the 10-year term — seats close at term end (mirrors the B7 serve-out doctrine); the judge migrates with the case load to the successor venue. [desk rec — consistent with existing serve-out]"},
             {"k":"B","t":"Close at dissolution — the court ceases with its jurisdiction; appointments end and the successor court re-nominates."},
             {"k":"C","t":"Migrate to the successor court for the remainder of the term, re-confirmed by the successor\'s nomination process."}]},
 {"id":"protomaps-online-fallback","q":"Basemap while the protomaps file downloads — online fallback, local seed, or none?","status":"open","lane":"map",
  "detail":"The self-hosted basemap is a ~128 GB pmtiles download that runs as a detached lane and can take many hours; until it lands, map surfaces have no basemap tiles (jurisdiction geometry still renders — this is cosmetics under the geometry, display-only, never gates ingestion). Operator asked (08-29) whether an online source could serve as a fallback meanwhile. Trade-off: self-host philosophy (a federation box should not depend on third parties) vs a blank background for the first hours of a new box. OSM's public tile servers prohibit production app traffic; Protomaps offers a hosted keyed API (free tier); Protomaps also publishes small low-zoom planet extracts (tens of MB) that could download FIRST in seconds and self-host immediately, upgraded in place when the full file lands.",
  "options":[{"k":"A","t":"Low-zoom local seed first — fetch a small z0-8 planet extract before the full file; instant self-hosted basemap, no third party, upgrades in place. [desk rec — preserves self-hosting, smallest build]"},
             {"k":"B","t":"Hosted API fallback — style points at Protomaps' keyed API until the local file is ready, then flips. Needs an API key per box and sends viewer traffic to a third party meanwhile."},
             {"k":"C","t":"Both — seed immediately, API only if the seed also hasn't landed yet."},
             {"k":"D","t":"None — blank background until the full file arrives (current behaviour)."}]},
 {"id":"box-vm-memory","q":"The database keeps getting killed for lack of memory during heavy scans. Give Docker's virtual machine a larger share of the computer's memory?","status":"open","lane":"ops",
  "detail":"The computer has 15.8 GB of memory. Docker's virtual machine gets the Windows default of half: 8 GB. Inside that, the database container is allowed 4.6 GB and sits near full; each heavy map-scan pass pushes one database process over the line and the system kills it. Today: six kills in two hours. Every kill self-healed (the engine reclaims and retries, no data lost), but each cost minutes to half an hour of waiting, and one made a scan detector report an error instead of a number. The fix is a small config file (.wslconfig) raising the virtual machine's share, then a Docker restart: the box is down about two minutes, done between runs. The same pressure existed on the C box; it was luckier.",
  "options":[{"k":"A","t":"10 GB to the virtual machine; Windows keeps 6. The database cap rises to about 6 GB. Comfortable for Windows and the browser; should end the kills. [desk rec]"},
             {"k":"B","t":"12 GB to the virtual machine; Windows keeps 4. Most headroom for the database; Windows may feel tight while the box grinds."},
             {"k":"C","t":"Leave it at 8 GB. Kills continue now and then; the engine keeps absorbing them with retries."}]},
 {"id":"ingest-tail-apportionment","q":"Move apportionment and the border precompute into the ingestion run itself?","status":"open","lane":"map",
  "detail":"Ruled 2026-08-29 (see answer). The border precompute needs only shapes and the tree; sizing needs only population plus the constants, which the wizard authors before map data loads. Neither needs map acceptance — unaccepted data makes them moot, not wrong. Folding both into the ingestion tail means a fresh box arrives at the district phase already sized and border-paid, cutting about seventy minutes of waiting from the interactive path.",
  "options":[{"k":"A","t":"Build it for the next fresh box: ingestion tail runs sizing + border precompute after finalize; repairs that change parentage re-queue just the touched parents. [desk rec]"},
             {"k":"B","t":"Keep them where they are (acceptance-triggered)."}]},
 # --- resolved (read-only, recorded) ---
 {"id":"map-adopt-lifecycle","q":"Map re-adoption after setup — is the certification lock overturned?","status":"resolved","lane":"map",
  "detail":"RULED 2026-08-04: NOT OVERTURNED — EXTENDED. A certified map is never reopened; the desk had this backwards and proposed an overturn. Instead: after setup, maps made in-game are DRAFTS, and one draft is SELECTED as the next map. Selection LOCKS IT IN but does NOT take effect in the moment — the new geographic reality arrives when the next TERM starts. Operator's worked example (rules, not code): a rule sets a census every 10 years on the zero year → census 2030; terms run on 0 and 5 at 5-year length → the maps derived from that census take effect 2035. ⚑ TIMING WRINKLE, parked by the operator ('we'll have to explore when we get there'): districts must be remapped, and elections open INSTANTLY at the end of a term for the next term — so a new map can only land in the next election that has NOT yet opened, which is the term AFTER the next one. Consequence for the four sub-questions the desk raised: institutions already elected are never disturbed, because adoption only ever lands on a term boundary."},
 {"id":"map-fork","q":"Can a certified map be used as the basis for a new one?","status":"resolved","lane":"map",
  "detail":"RULED 2026-08-04: a certified map cannot be REOPENED, but a NEW map may be created BASED OFF an old map — fork/clone-from-existing as a first-class action. Operator: 'That would be a really cool and convenient mechanism. I would add that to every mapper.' Applies to EVERY mapper — jurisdiction mapper and district mapper alike. This is the mechanism that makes the draft lifecycle usable: you start the next map from the certified one rather than from nothing."},
 {"id":"typeb-shape","q":"Type B race shape — pooled vs per-child/per-clump?","status":"resolved","lane":"1",
  "detail":"RULED per-child/per-clump (each child, or clump, is its own at-large race). CLAUDE.md corrected @55b8846. Build = Wave 4 (lanes 1+3)."},
 {"id":"video","q":"Video library / multi-track player — build from scratch?","status":"resolved","lane":"5",
  "detail":"NO from-scratch build — the operator's player already exists; the mockups are based on it. Wave 4 = integrate it (ref fleet-11 + coalition site)."},
 {"id":"founding-stake","q":"Founding-stake-on-registration — auto-equity when an org is founded?","status":"resolved","lane":"13",
  "detail":"DEFERRED to Wave 4, structure-aware (100% stake wrong for member-owned/nonprofit; only stock has shares)."},
 {"id":"setup-order","q":"Setup order — account-first (mockup) or fork-first (ruling)?","status":"resolved","lane":"2",
  "detail":"RULED FORK-FIRST: join-or-start, THEN account. Mockup swapped; SetupController already fork-first."},
 {"id":"oversight-public","q":"Oversight console — public or gated?","status":"resolved","lane":"3",
  "detail":"RULED PUBLIC ('public if it's government'; no closed-session provision). Console read public; write controls authenticated. @4057b3c."},
]
# Operator answers (2026-07-29) — flip the 9 open to RESOLVED with the ruling folded in.
_ANS = {
 'subtree-activation-shape':('B+','MULTI-LANE PER-PLACE BOOT, structure only, never one opaque transaction. The standing paradigms dictate the shape: lanes sized to the machine, one committed chunk per place, resumable, visible per-lane progress. Elections fire where voters exist: real mode has no voters at boot (residents arriving trigger each founding election later, per the population-mode ruling); simulation mode elects through the sim itself. Setup exits when everything completable-now is done; remaining boots continue in background. NEW BUILD ITEMS queued: an app-wide background job monitor (floating indicator + drill-in list with live bars) and demand priority (opening a pending place promotes its boot to the queue front, the streaming-install model). Duration gets a measured baseline, not an assumption.','"Always using multi lane up to whatever the system resources can handle... always making it visible. I believe there is a paradigm in the documentation somewhere that should dictate this. ... stay in the setup process until everything is done that can be done ... some sort of background job monitor system needs to be in the app ... similar to how games like World of Warcraft did it ... anything else the player happened to stumble upon, it would bring those things to the front of the work queue to minimize the gap." (2026-08-29)'),
 'ingest-tail-apportionment':('A','PINNED for the next fresh box: the ingestion run itself finishes with apportionment (sizing) and the border precompute, before activation and before map acceptance — acceptance is not a precondition because unaccepted data makes the precomputation moot, not wrong. The Philippines race that made mid-ingest synthesis dangerous is dead (the positional resume class was removed), so the tree at the finalize barrier is trustworthy. One rider ships with it: a lawful repair that changes parentage (a collapse ruling, a manual re-parent) re-queues just the touched parents\' borders. Tested on the next fresh run.','"The Philippines issue was a bug in the code during this development. That wouldn\'t occur in reality... we can get the apportionments and the geometry precomputed as part of the ingestion phase. Pin that for the next fresh box... you wouldn\'t even need acceptance of the map data yet, because if the map data wasn\'t accepted, it wouldn\'t even matter." (2026-08-29)'),
 'box-vm-memory':('C+','LEAVE MEMORY ALONE — the paradigm is the fix. Raising the VM share is ruled impossible (other processes on the machine and in the application own their memory). Every kill class this box saw traces to unbounded single-process work; the remedy is ETL-paradigm shape (bounded units, fresh processes, host-derived lanes) — already proven by the per-scope map sweep (Earth in 9 minutes, zero deaths, through the scope that killed the monolith twice). Engine items A1-A4 sanctioned 2026-08-29 to finish the job.','"Leave it alone... increasing memory is impossible because there are other processes on this machine, and there are other processes in this application that have memory of their own. But what will work is following the ETL paradigm." (2026-08-29)'),
 'population-mode-autoboot':('A','CLOSE THE LOOP. Plain statement of what was asked: in population mode (a REAL game mode for live worlds, not only dev/demo — a place turns on when enough verified residents live there), the code recorded the crossing but never booted the place; an operator had to run a command per place by hand. Ruling A: the crossing now triggers the full boot automatically — seats sized, board seated, founding election opened, because the residents are real. Applies the same in dev and live. Build queued post-certification, lane 3.','"If this only matters in dev mode (which simulates data for demo purposes) closing the loop seems fine. I am not sure what this means. I think this question predates our new communication menthod of using technical english. I have ADHD." (2026-08-29 — the question also triggered the standing order: rubric entries use plain technical English per the communication guidelines.)'),
 'protomaps-online-fallback':('D+','BLANK UNTIL THE FILE ARRIVES stays the behaviour NOW (option D). Future build: a SETUP OPTION where each instance operator sets the standard — three choices at setup: (1) install protomaps locally, (2) use the web-hosted tile service, (3) install locally AND use the web-hosted service while the download runs. Queued as setup-wizard work post-certification.','"I believe there is a web serving method. What we can do is offer it as an option at setup. The user can choose to install protomaps, use the web hosted version, install and use webhosted. To this end the operator sets the standard." (2026-08-29)'),
 'cloud-geodata-source':('C (staged)','Build and test the REMOTE DOWNLOAD path with visible progress, pinned for equivalence with the D: archive. Verified upstream equivalents: geoBoundaries = public repo commit 78a697d23 (clone-at-commit = byte-identical; upstream HEAD has moved, so the downloader must pin the commit) · WorldPop = frozen release R2025A v1, 2023, 100m constrained (exact archive filenames exist at data.worldpop.org/GIS/Population/Global_2015_2030/R2025A/2023/<ISO>/v1/100m/constrained/) · protomaps pmtiles = display-only, not a math input. First run: fresh E: box, remote pull, expect the eleven base maps to land the same mathematical conclusions, then the full planet with desk watch.','"look at what is in the d drive and then go look at the online sources to see if you can find their absolute equivalence, and then we can test pulling that data. I would need to see progress bars and or something that indicates that it is pulling that data properly and downloading." (2026-08-28)'),
 'cloud-burst-architecture':('C (wiring, staged)','Container worker jobs next to an on/off VM. Ruled FEASIBLE by code read 2026-08-29: the mapping plane is fully queue-shaped (AutoscaleWorkerJob + MapScopeLaneJob on the autoscale queue) and all run state is database-backed (leases, heartbeats, halt/resume on the run row; population math reads worldpop_rasters in postgres; no local control files in the worker path). External worker containers pointed at the box\'s redis + postgres therefore consume mapping work with NO engine rebuild. Wiring needed: app image in a registry, Container Apps Job with a KEDA redis scaler on the autoscale queue, private network to the VM, and a plain queue:work command override (never the horizon entrypoint, which auto-dispatches a planet prewarm on every boot). Two bounds: the geodata INGEST phase is not queue-shaped (python lanes on the VM, local archive), and postgres anchors everything (connection budget caps all lanes at 56 total; at full burst postgres itself needs about 17 cores, so the VM resizes up during a build regardless). NO separate always-on VM is needed: the one game box turns on and off, workers burst around it.','"I would like to do the container worker apps jobs next to a VM that can turn on and off. I\'m not sure if that\'s possible based on the way the code is written. Effectively if I can have workers spin up as needed to scale, that\'s fine. If we need an always-on VM that\'s separate, then let me know. But it doesn\'t seem like I would need that." (2026-08-29)'),
 'cloud-idle-posture':('A','Deallocate between sessions before launch. About $45/mo idle (disk + IP), start on demand in about 2 minutes. The VM is on only while people play or a build runs; workers alone cannot serve the game (no UDP ingress on serverless, and postgres + redis live on the box).','"Minimum workers. The whole point of using workers I thought was so that you only had up the ones you needed when you needed them, and then it could spin up more as necessary. So always on is no. We are definitely going to deallocate." (2026-08-29)'),
 'cloud-region':('A (staged multibox)','First box in US East. Then a second box in Europe, transferred or cloned, for access from Poland. The Europe box\'s identity gets decided when it is planned: a JOIN makes a read-only mirror with no accounts; a clone-restore must mint its own identity (the import path filters instance_settings).','"We are doing Azure. I am going to build the first one in US East, and then I am going to transfer or clone one to Europe so that I can access it from Poland." (2026-08-29)'),
 'era1-retest-maps':('A','The three era champions drawn on the block engine (6f4ee42) as auto standards for his walk: Maldives 199e55ac · Malaysia 2b559c81 · Bangladesh a876aca1. His tweaks join the tweak-potentials pile (ALGORITHM.md §10c). If the walk clears them, the next phase begins.','"If you are identifying these candidates to walk, make their maps form the current standard and I will walk them. We can add any tweaks I make to the pile of tweak potentials. If there are no new candidates to walk we can prepare for the next phase." (2026-08-28)'),
 'gate-expansion-maps':('B+','ALL SIX join the gate (the B five + Chile): India, Philippines, Canada, Egypt, Chile, San Marino — the test block of eight. Auto baselines regenerated per engine change; his manual clones become the standards as he blesses them (the USA method).','"go ahead and provide me the links to all the examples and I will auto map via stepper then manual map and tweak to add to the non regression panel … This way, I should be able to have nonregression gates across the entire test block of eight maps." (2026-08-26/27)'),
 'good-maps-adopt':('A','ADOPTED at iteration 19 (engine 42495b5) after the Class-1 round: rewalk verdict accepts the set; next phase = the planet-wide Type A sweep on this engine. STANDING REGRESSION GATE: any algorithm tweak requires a fresh Earth+USA iteration scored against the standards before shipping (database/good_maps/ALGORITHM.md §11).','"With very little exception I would say this is the best map set you have ever created. … Next phase will be to fully map all jurisdictions making sure they stay legal and optimize their statistics like these maps are optimized. Any tweaks made to the algorithm would necessitate an iteration of Earth and USA map to make sure the logic isn\'t being compromised." (2026-08-25)'),
 'sim-org-bill-rates':('B','Census-flavored: per-capita org rates (parties, nonprofits, businesses) + bills per session — realistic even though it mints millions of org rows planet-wide (implemented with a representative sample dial + true-density aggregates so an 8 GB box still runs).','"For the Purposes of Demo simulation and development purposes We want census flavored." (2026-08-08)'),
 'sim-leaf-courts':('A','Next sim round, BEFORE the demo — full courts everywhere, committee-slate nomination for leaves.','"What you and I are inching toward is full scale deployment simulation. So I will cotest the manual Map and the Simulation of that specific jurisdiction in the same go. Therefore it is imperative that we have this ready." (2026-08-08 — see the GOAL PATH: narrow sim → tour/refine → local multibox mesh (2 Linux PCs + Pi 5 + Pi 3B+ + this Windows box) → cloud instances Azure→GCP→AWS + instant-deploy templates → smaller providers. Close dev with THREE meshes: Demo Simulation, Real-World High-Acceleration prebuilt, Real-World Real-Time. Then lanes 9-12 marketing. Deadline: Labor Day weekend; hard stop Sept 19 Poland flight.)'),
 'edu-arming':('A','Pre-train demo members (seeders file F-EDU-001) — the walk shows a trained fleet.',''),
 'mass-pass':('A','After the race fix, pull lane 1\'s commits to the game box and run the ~9,708 mass pass now (ETL-chunked).',''),
 'lane3-compact':('A','Compact lane 3 now — it resumes into seating a committee + the exit walk.','Operator will NOT manually walk anything until we are all GREEN and ready.'),
 'ranked-live':('A','Build the secrecy-safe live aggregate, DAILY-BATCHED (results are invisible-until-count today, so daily provisional standings — no in-request decrypt).',''),
 'secondary-trade':('A','Pull into Wave 4 — lane 13 builds share resale on the exchange (needs schema).',''),
 'handshake-4xx':('A','Fix in Wave 4 — catch the cross-class refusal, return 409/422 gracefully.',''),
 'b2-pairing':('A','Keep compact-first (Type B clumping; matches intent).',''),
 'oversight-live':('B','GOVERNMENT IS PUBLIC BY DEFAULT — the live console of in-progress proceedings too. Organizations decide their own visibility. ⚑ SETTLED LAW, never re-ask.','"I dont know how many times I need to reanswer this question."'),
 'orphans':('A','Delete the orphan surfaces (CandidateProfile.vue etc.). ⚑ SETTLED, never re-ask.','"I already answered this many times as well."'),
 'q4a-rooms':('A',"courtTiers = a jurisdiction's tree-DEPTH. Live Rooms / public squares / chats are INFRASTRUCTURE — NOT constitutionally the same as a court-as-jurisdiction. A COURTHOUSE has many COURT ROOMS. Reframe the formula (doc amendment); no schema.",'"LIVE ROOMS AND PUBLIC SQUARES ARE INFRASTRUCTURE AND NOT CONSTITUTIONALLY THE SAME AS A COURT AS A JURISDICTION. Court House DOES NOT EQUAL Court Room." ⚑ SETTLED, never re-ask.'),
 'advocate-gate':('A','Advocate stays an INSTANT competence REGISTER — no merits gate, no approval lifecycle, no qualification catalog. Same as every role: WANT THE ROLE → DO THE (K-2) TRAINING.','"We settled this with the other roles. You want the role, you do the training. To do the role you do the training." ⚑ SETTLED, never re-ask.'),
 'scale-committees':('B','EAGER — provision committees up front in STEPS alongside executives/judiciaries; an accepted planet already has them. Doctrine: ALL sub-institutions build eagerly, scaled to the recorded population of the jurisdiction.','"All things should be eagerly built up front up to the recorded population of that jurisdiction in the data. This should properly scale the map."'),
 'scale-record-disposition':('A','MIRROR ACTS — copy-per-constituent WITH history for every non-Act record family; the two hard cases (open cases, sitting judges) ruled separately below. Symmetric with union by construction.','"Mirror"'),
 'scale-case-venue':('B',"Case-by-case re-venue: the case's JUSTICES direct each open case to the appropriate constituent jurisdiction, within the bounds of their court-system rules.",'"Open cases would be directed by the justices of the cases to approparite jurisdictions wihtin the bounds of their court system rules."'),
 'scale-judge-tenure':('B','CLOSE AT DISSOLUTION — the court ceases with its jurisdiction; appointments end and the successor court re-nominates.','"It all closes together."'),
 # ── Round 1 of the Setup + Viewer walkthrough (operator, 2026-08-04) ──
 'map-adopt-scope':('C','Planetary now, scoped-READY later — ship planetary but carry the scope column so scoped is additive.','Each PARENT jurisdiction controls its own internal subdivisions for jurisdictional purposes. The global layer locks in by the EARTH election board; national election boards subdivide within, and so on down the tree. Once that cascade is complete it becomes a DRAFT CANDIDATE.'),
 'map-select-authority':('D','Selection is automatic from the standing census RULE (census year -> derived map -> next term); a legislative act only OVERRIDES it.','Operator/admin by default, then whoever is the current keeper of the rule.'),
 'merge-bulk':('B','Collapse same-space chains at INGEST so the queue never fills with them.','⚑ Three constraints on the collapse, operator: (1) POPULATION CHECK — geometry alone is not enough. Jurisdictions that MOSTLY overlap but do not are the tricky case, and any populated lack of overlap must not get locked out; same space + same population confirms same PLACE. (2) NEVER ORPHAN anything below the stack — no jurisdiction or cluster beneath a collapsed chain may lose its lineage. (3) Real players snap to the nearest appropriate ADM level at GROUND level and chain up from there. "Overall im fine with doing it at ingestion."'),
 'adm-empty-walk':('A','Fix both now — skip levels the metadata says are absent AND memoise the directory walk.','For testing; another fresh run is coming to make sure we stay on track.'),
 'list-stats-columns':('B','Own-row + direct children only — no recursion, no new recursive-CTE endpoint.',''),
 'setup-shell-menus':('A','Lock everything except Setup, Jurisdictions and Learn until setup completes.','Plus the OPERATOR CONTROLS — everything is locked except operator controls, setup controls, and the three in A.'),
 'guest-banner':('B','Collapse into the header bar as a small badge beside Log in / Register.',''),
 'about-surface':('A','Move the content into the Learn tab; remove the block from the viewer.','⚑ CHECK LEARN FIRST — do not duplicate or override what is already there. And a STANDING instruction for the whole walkthrough: much of the UI is explanatory text that previous design/developer AIs generated. Expect to be cleaning a lot of that out as we go.'),
}
for _q in QUESTIONS:
    a = _ANS.get(_q['id'])
    if a:
        k, txt, note = a
        _q['status'] = 'resolved'
        _q['detail'] = 'RULED = %s. %s%s · %s' % (k, txt, (' [operator: '+note+']') if note else '', _q['detail'])
        _q.pop('options', None)

# Screens / caps / debt all come from the enriched, badged corpus in this dir (repo-stable).
_enr = json.load(open(os.path.join(_HERE, 'badged.json'), encoding='utf-8'))
screens = _enr['screens']; caps = _enr['caps']; debt = _enr['debt']
DATA = {'asOf': '2026-08-04', 'head': 'ea1ed8e', 'forms': 120,
        'screens': screens, 'caps': caps, 'debt': debt, 'fleet': FLEET, 'questions': QUESTIONS}

TEMPLATE = r"""<title>App Progress Rubric — CGA</title>
<style>
:root{--bg:#F6F6F3;--surface:#FFFFFF;--ink:#1B1E28;--muted:#5C6070;--faint:#8B8F9E;--line:rgba(27,30,40,.12);--line-strong:rgba(27,30,40,.22);--accent:#3B4A8C;--accent-soft:rgba(59,74,140,.08);--good:#1D8A47;--warn:#C98500;--bad:#C4553B;--block:#7A46B8;--good-s:rgba(29,138,71,.12);--warn-s:rgba(201,133,0,.14);--bad-s:rgba(196,85,59,.13);--block-s:rgba(122,70,184,.14);--mono:"Cascadia Code",Consolas,ui-monospace,monospace;--sans:"Segoe UI Variable Text","Segoe UI",system-ui,sans-serif;}
@media (prefers-color-scheme:dark){:root{--bg:#14161D;--surface:#1C1F29;--ink:#ECEDF2;--muted:#9BA0B0;--faint:#6E7385;--line:rgba(236,237,242,.12);--line-strong:rgba(236,237,242,.25);--accent:#8C9AD9;--accent-soft:rgba(140,154,217,.12);--good:#3FBF74;--warn:#E8A23D;--bad:#E07856;--block:#B189E0;--good-s:rgba(63,191,116,.14);--warn-s:rgba(232,162,61,.15);--bad-s:rgba(224,120,86,.15);--block-s:rgba(177,137,224,.16);}}
:root[data-theme="dark"]{--bg:#14161D;--surface:#1C1F29;--ink:#ECEDF2;--muted:#9BA0B0;--faint:#6E7385;--line:rgba(236,237,242,.12);--line-strong:rgba(236,237,242,.25);--accent:#8C9AD9;--accent-soft:rgba(140,154,217,.12);--good:#3FBF74;--warn:#E8A23D;--bad:#E07856;--block:#B189E0;--good-s:rgba(63,191,116,.14);--warn-s:rgba(232,162,61,.15);--bad-s:rgba(224,120,86,.15);--block-s:rgba(177,137,224,.16);}
:root[data-theme="light"]{--bg:#F6F6F3;--surface:#FFFFFF;--ink:#1B1E28;--muted:#5C6070;--faint:#8B8F9E;--line:rgba(27,30,40,.12);--line-strong:rgba(27,30,40,.22);--accent:#3B4A8C;--accent-soft:rgba(59,74,140,.08);--good:#1D8A47;--warn:#C98500;--bad:#C4553B;--block:#7A46B8;--good-s:rgba(29,138,71,.12);--warn-s:rgba(201,133,0,.14);--bad-s:rgba(196,85,59,.13);--block-s:rgba(122,70,184,.14);}
*{box-sizing:border-box}body{background:var(--bg);color:var(--ink);font-family:var(--sans);margin:0;line-height:1.5}
.wrap{max-width:70rem;margin:0 auto;padding:2rem 1.1rem 4rem}
.eyebrow{font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;color:var(--faint);margin:0 0 .4rem}
h1{font-size:1.55rem;font-weight:600;margin:0 0 .3rem}
.stamp{font-size:.82rem;color:var(--muted);margin:0 0 1.3rem}
.stamp code{font-family:var(--mono);font-size:.78rem;background:var(--accent-soft);padding:.08em .4em;border-radius:4px}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:.7rem;margin:0 0 1rem}
.tile{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:.8rem .95rem}
.tile .lbl{font-size:.72rem;letter-spacing:.09em;text-transform:uppercase;color:var(--muted);margin:0 0 .25rem}
.tile .num{font-size:1.6rem;font-weight:650;font-variant-numeric:tabular-nums;line-height:1.1}
.tile .sub{font-size:.77rem;color:var(--faint)}
.tile .meter{display:flex;block-size:.5rem;border-radius:4px;overflow:hidden;margin-top:.45rem;background:var(--line)}
.tile .meter span{block-size:100%}
.dot{inline-size:.55rem;block-size:.55rem;border-radius:50%;display:inline-block;flex:none;vertical-align:middle}
.d-good{background:var(--good)}.d-warn{background:var(--warn)}.d-bad{background:var(--bad)}.d-block{background:var(--block)}.d-low{background:var(--faint)}
.s-good{background:var(--good)}.s-warn{background:var(--warn)}.s-bad{background:var(--bad)}.s-block{background:var(--block)}
.note{background:var(--surface);border:1px solid var(--line);border-inline-start:4px solid var(--bad);border-radius:9px;padding:.75rem .9rem;font-size:.85rem;margin:.2rem 0 1.4rem}.note b{color:var(--ink)}
.views{display:flex;gap:.35rem;border-bottom:2px solid var(--line);margin:0 0 1rem;flex-wrap:wrap}
.view-btn{padding:.5rem .8rem;font:inherit;font-size:.9rem;font-weight:600;color:var(--muted);background:none;border:0;border-bottom:2px solid transparent;margin-bottom:-2px;cursor:pointer}
.view-btn[aria-selected=true]{color:var(--ink);border-bottom-color:var(--accent)}
.view-btn:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.controls{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin:0 0 1rem}
.controls input[type=search]{flex:1 1 13rem;background:var(--surface);border:1px solid var(--line-strong);border-radius:8px;color:var(--ink);font:inherit;font-size:.88rem;padding:.42rem .7rem}
.controls input[type=search]:focus{outline:2px solid var(--accent);outline-offset:1px}
.chip{background:var(--surface);border:1px solid var(--line-strong);border-radius:999px;color:var(--muted);font:inherit;font-size:.8rem;padding:.28rem .75rem;cursor:pointer}
.chip[aria-pressed=true]{background:var(--accent);border-color:var(--accent);color:var(--bg)}
.chip:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.expanders{margin-inline-start:auto;display:flex;gap:.5rem}
.area{background:var(--surface);border:1px solid var(--line);border-radius:12px;margin:0 0 .6rem;overflow:hidden}
.area-head{display:grid;grid-template-columns:16rem 1fr 9rem 1.2rem;gap:.9rem;align-items:center;inline-size:100%;background:none;border:0;color:inherit;font:inherit;text-align:start;padding:.7rem .95rem;cursor:pointer}
.area-head:hover{background:var(--accent-soft)}.area-head:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}
.area-name{font-weight:600;font-size:.93rem}
.bar{display:flex;block-size:.8rem;border-radius:5px;overflow:hidden;background:var(--line)}.bar span{block-size:100%}
.counts{font-family:var(--mono);font-size:.75rem;color:var(--muted);text-align:end;white-space:nowrap}
.chev{color:var(--faint);transition:transform .15s}.area-head[aria-expanded=true] .chev{transform:rotate(90deg)}
.rows{border-top:1px solid var(--line)}
.scr{border-top:1px solid var(--line)}.scr:first-child{border-top:0}
.scr-head{display:grid;grid-template-columns:auto 1fr auto auto auto;gap:.6rem;align-items:baseline;inline-size:100%;background:none;border:0;color:inherit;font:inherit;text-align:start;padding:.55rem .95rem .55rem 1.2rem;cursor:pointer}
.scr-head:hover{background:var(--accent-soft)}.scr-head:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}
.scr-title{font-size:.88rem}.scr-file{font-family:var(--mono);font-size:.75rem;color:var(--faint);display:block;margin-top:.1rem}
.pill{font-size:.68rem;font-weight:600;letter-spacing:.03em;border-radius:999px;padding:.14em .6em;white-space:nowrap}
.p-built,.p-working,.p-done,.p-resolved{background:var(--good-s);color:var(--good)}
.p-partial,.p-next,.p-medium,.p-active{background:var(--warn-s);color:var(--warn)}
.p-absent,.p-high,.p-open{background:var(--bad-s);color:var(--bad)}
.p-blocked,.p-held{background:var(--block-s);color:var(--block)}
.p-low,.p-deferred{background:var(--accent-soft);color:var(--muted)}
.eff{font-family:var(--mono);font-size:.72rem;color:var(--faint);white-space:nowrap}
.lwbadge{font-family:var(--mono);font-size:.66rem;font-weight:700;background:var(--accent-soft);color:var(--accent);padding:.14em .45em;border-radius:4px;white-space:nowrap;letter-spacing:.02em}
.qbar{display:flex;gap:.8rem;align-items:center;margin:0 0 1rem;flex-wrap:wrap}
.qhint{font-size:.78rem;color:var(--faint);flex:1;min-width:14rem}
#qexport{font-weight:700;color:var(--accent);border-color:var(--accent)}
.qexport-wrap{position:relative;margin:0 0 1rem}.qexport-wrap.hidden{display:none}.qcopy{position:absolute;top:.45rem;right:.45rem;z-index:2;cursor:pointer;font:600 .72rem/1 var(--mono);padding:.35rem .6rem;border-radius:6px;background:var(--surface2,#1b1f27);color:var(--accent);border:1px solid var(--accent);opacity:.85}.qcopy:hover{opacity:1}.qcopy.ok{color:var(--good);border-color:var(--good)}.qexport{background:var(--surface);border:1px solid var(--accent);border-radius:8px;padding:.7rem .9rem;font-family:var(--mono);font-size:.76rem;white-space:pre-wrap;color:var(--ink);margin:0;padding-top:1.9rem;user-select:all}
.qcard{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:.9rem 1rem;margin:0 0 .7rem}
.qcard.resolved{opacity:.65}
.qhead{display:flex;align-items:baseline;gap:.6rem;flex-wrap:wrap}
.qtext{font-weight:600;font-size:.95rem;flex:1;min-width:12rem}
.qdetail{font-size:.82rem;color:var(--muted);margin:.4rem 0 .7rem}
.qopts{display:flex;flex-direction:column;gap:.4rem;margin:0 0 .7rem}
.qopt{display:flex;gap:.55rem;align-items:flex-start;padding:.5rem .7rem;border:1px solid var(--line);border-radius:8px;cursor:pointer;font-size:.86rem}
.qopt:hover{background:var(--accent-soft)}
.qopt.on{border-color:var(--accent);background:var(--accent-soft);box-shadow:inset 0 0 0 1px var(--accent)}
.qopt input{margin-top:.15rem;accent-color:var(--accent)}
.qk{font-family:var(--mono);font-weight:700;color:var(--accent);flex:none}
.qnotes{width:100%;min-height:2.4rem;background:var(--bg);border:1px solid var(--line-strong);border-radius:8px;color:var(--ink);font:inherit;font-size:.85rem;padding:.45rem .6rem;resize:vertical}
.qnotes:focus{outline:2px solid var(--accent);outline-offset:1px}
.wavesline{font-size:.82rem;color:var(--muted);margin:0 0 1rem;background:var(--surface);border:1px solid var(--line);border-radius:8px;padding:.6rem .9rem}
.lanecard{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:.9rem 1rem;margin:0 0 .7rem}
.lanehd{display:flex;align-items:center;gap:.6rem;margin:0 0 .5rem;flex-wrap:wrap}
.lanenm{font-weight:650;font-size:.98rem}
.laneorder{font-size:.87rem;line-height:1.55}
.lanehist{margin-top:.6rem;font-size:.8rem}
.lanehist summary{color:var(--faint);cursor:pointer;user-select:none}
.donerow{color:var(--muted);font-size:.8rem;margin:.4rem 0;padding-inline-start:.6rem;border-inline-start:2px solid var(--line)}
.wv{font-family:var(--mono);font-size:.72rem;font-weight:700;color:var(--accent);white-space:nowrap}
.detail{padding:.35rem 1.2rem 1rem 2.15rem;font-size:.85rem;border-top:1px dashed var(--line)}
.detail dl{margin:0}.detail dt{font-size:.7rem;letter-spacing:.09em;text-transform:uppercase;color:var(--faint);margin:.7rem 0 .2rem}
.detail dt.blk{color:var(--bad)}.detail dd{margin:0}.detail ul{margin:.1rem 0 0;padding-inline-start:1.1rem}.detail li{margin:.15rem 0}
.detail p{margin:.1rem 0 0}.detail .meta{font-family:var(--mono);font-size:.76rem;color:var(--muted)}
.ok{color:var(--good)}.hidden{display:none}mark{background:var(--warn-s);color:inherit;border-radius:3px}
.foot{font-size:.76rem;color:var(--faint);margin-top:2rem;border-top:1px solid var(--line);padding-top:.8rem}
@media (max-width:46rem){.area-head{grid-template-columns:1fr 6rem 1rem;grid-template-rows:auto auto}.area-head .bar{grid-column:1/-1;grid-row:2}.scr-head{grid-template-columns:auto 1fr auto}}
</style>
<div class="wrap">
<p class="eyebrow">Fair Constitution App · App Progress Rubric</p>
<h1>Where does the app stand — and what's the road to a playable game?</h1>
<p class="stamp">%%STAMP%% · verified against live code · click a group, then a row, for the detail · search + filter + expand-all below</p>
<div class="tiles" id="tiles"></div>
<div class="note" style="border-inline-start-color:var(--good)"><b>✅ Wave 4 concluded GREEN</b> (authoritative suite 1343 / 0). <b>The Type B second-chamber race is RESOLVED</b> — the pooled shape is fully retired; per-clump (grouped) and per-child (ungrouped) chambers now elect, count, seat, and field candidates, hardened and live-verified (Niue cleared). Every open question is ruled. <b>Wave 5 = the finish, now LAUNCHED and in progress:</b> the build lanes are closing the last screens + capabilities → all-green re-gate → arm → the operator's walk. See <b>Fleet &amp; Waves</b> for live W5 status.</div>
<div class="views" role="tablist">
  <button class="view-btn" role="tab" data-v="screens" aria-selected="true">UI Screens</button>
  <button class="view-btn" role="tab" data-v="caps" aria-selected="false">Capabilities</button>
  <button class="view-btn" role="tab" data-v="debt" aria-selected="false">Tech Debt</button>
  <button class="view-btn" role="tab" data-v="fleet" aria-selected="false">Fleet &amp; Waves</button>
  <button class="view-btn" role="tab" data-v="questions" aria-selected="false">Open Questions</button>
</div>
<div class="controls">
  <input type="search" id="q" placeholder="Search…" aria-label="Search">
  <span id="filters"></span>
  <span class="expanders"><button class="chip" id="exAll">Expand all</button><button class="chip" id="coAll">Collapse all</button></span>
</div>
<div id="body"></div>
<p class="foot">Generated from <code>v3_gap_data.json</code> + the 8-agent verification workflow + the Wave-4 standing orders. UI screens vs the 107 <code>mockups/v3</code> screens; capabilities, debt, fleet &amp; questions from the live-code sweep and the desk plan.</p>
</div>
<script>
const D=%%DATA%%;
const esc=s=>String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const CO={built:'good',working:'good',partial:'warn',absent:'bad',blocked:'block',high:'bad',medium:'warn',low:'low',done:'good',next:'warn',held:'block',resolved:'good',open:'bad',active:'warn',deferred:'low'};
const LB={built:'built',working:'working',partial:'partial',absent:'absent',blocked:'blocked',high:'high',medium:'medium',low:'low',done:'done',next:'next',held:'held',resolved:'resolved',open:'open',active:'active',deferred:'deferred'};
let view='screens',q='',filter='all';
let ANS={};try{for(let i=0;i<localStorage.length;i++){const k=localStorage.key(i);if(k&&k.indexOf('cga4qs_')===0){const id=k.slice(7);ANS[id]=ANS[id]||{};ANS[id].sel=localStorage.getItem(k);}if(k&&k.indexOf('cga4qn_')===0){const id=k.slice(7);ANS[id]=ANS[id]||{};ANS[id].notes=localStorage.getItem(k);}}}catch(e){}
function saveAns(id,f,v){ANS[id]=ANS[id]||{};ANS[id][f]=v;try{localStorage.setItem('cga4q'+(f==='sel'?'s':'n')+'_'+id,v);}catch(e){}}
const FILTERS={screens:['all','built','partial','absent'],caps:['all','working','partial','blocked','absent'],debt:['all','open','deferred','resolved'],fleet:['all','next','done','held','deferred'],questions:['all','open','resolved']};
const sc=t=>D.screens.filter(r=>r.bucket===t).length,cc=t=>D.caps.filter(r=>r.maturity===t).length,dc=t=>D.debt.filter(r=>r.severity===t).length,ds=t=>D.debt.filter(r=>(r.state||'open')===t).length;
const qOpen=D.questions.filter(x=>x.status==='open').length,qRes=D.questions.filter(x=>x.status==='resolved').length;
function meter(parts){return parts.map(p=>p[0]?`<span class="s-${p[1]}" style="flex:${p[0]}"></span>`:'').join('');}
document.getElementById('tiles').innerHTML=`
 <div class="tile"><p class="lbl">UI Screens</p><div class="num">${sc('built')} / ${D.screens.length}</div><div class="sub">${sc('partial')} partial · ${sc('absent')} absent</div><div class="meter">${meter([[sc('built'),'good'],[sc('partial'),'warn'],[sc('absent'),'bad']])}</div></div>
 <div class="tile"><p class="lbl">Capabilities</p><div class="num">${cc('working')} / ${D.caps.length}</div><div class="sub">${cc('partial')} part · ${cc('blocked')} blocked · ${cc('absent')} absent</div><div class="meter">${meter([[cc('working'),'good'],[cc('partial'),'warn'],[cc('blocked'),'block'],[cc('absent'),'bad']])}</div></div>
 <div class="tile"><p class="lbl">Technical Debt</p><div class="num">${ds('open')+ds('deferred')} outstanding</div><div class="sub">${ds('open')} open · ${ds('deferred')} deferred · ${ds('resolved')} resolved</div><div class="meter">${meter([[ds('open'),'bad'],[ds('deferred'),'warn'],[ds('resolved'),'good']])}</div></div>
 <div class="tile"><p class="lbl">Open Questions</p><div class="num">${qOpen} open</div><div class="sub">${qRes} resolved · Wave 4 green</div><div class="meter">${meter([[qRes,'good'],[qOpen,'bad']])}</div></div>`;
function hi(t){if(!q)return esc(t);const i=String(t).toLowerCase().indexOf(q);if(i<0)return esc(t);const s=String(t);return esc(s.slice(0,i))+'<mark>'+esc(s.slice(i,i+q.length))+'</mark>'+esc(s.slice(i+q.length));}
function screenDetail(r){const li=x=>x.map(i=>`<li>${hi(i)}</li>`).join('');let h='<dl>';
  h+=`<dt>Where</dt><dd class="meta">${r.page?esc(r.page):'<em>no page</em>'}${r.route?' · '+esc(r.route):''} · props: ${esc(r.props)} · backend: ${esc(r.backend)}${r.owner?' · owner '+esc(r.owner):''}</dd>`;
  if(r.propsMissing.length)h+=`<dt>Props missing</dt><dd><ul>${li(r.propsMissing)}</ul></dd>`;
  if(r.backendMissing.length)h+=`<dt>Backend missing</dt><dd><ul>${li(r.backendMissing)}</ul></dd>`;
  if(r.specHas.length)h+=`<dt>Spec has · app lacks</dt><dd><ul>${li(r.specHas)}</ul></dd>`;
  if(r.appAhead.length)h+=`<dt>App has · spec lacks (reconcile, don't strip)</dt><dd><ul>${li(r.appAhead)}</ul></dd>`;
  if(r.notes)h+=`<dt>Notes</dt><dd>${hi(r.notes)}</dd>`;
  if(!r.propsMissing.length&&!r.backendMissing.length&&!r.specHas.length&&!r.notes)h+=`<dd class="ok">Conformant — nothing outstanding.</dd>`;return h+'</dl>';}
function groupView(items,areaKey,barKeys,matchFn,rowHTML,valKey){
  const order=[...new Set(items.map(i=>i[areaKey]))];const byA={};order.forEach(a=>byA[a]=items.filter(i=>i[areaKey]===a));
  const rank=a=>{const g=byA[a];const good=g.filter(x=>['built','working','done'].includes(x[valKey])).length;return g.filter(matchFn).length?good/g.length:-1;};
  order.sort((a,b)=>rank(b)-rank(a));let html='';
  order.forEach(a=>{const g=byA[a],vis=g.filter(matchFn);if(!vis.length)return;const c={};barKeys.forEach(k=>c[k]=0);g.forEach(r=>{const v=r[valKey];if(v in c)c[v]++;});
    const bar=barKeys.map(k=>c[k]?`<span class="s-${CO[k]}" style="flex:${c[k]}"></span>`:'').join('');const cnt=barKeys.map(k=>c[k]).join(' / ');
    html+=`<section class="area"><button class="area-head" aria-expanded="false"><span class="area-name">${esc(a)}</span><span class="bar">${bar}</span><span class="counts">${cnt}</span><span class="chev">›</span></button><div class="rows hidden">${vis.map(rowHTML).join('')}</div></section>`;});
  return html;}
function render(){const b=document.getElementById('body');
  if(view==='screens'){b.innerHTML=groupView(D.screens,'area',['built','partial','absent'],r=>(filter==='all'||r.bucket===filter)&&(!q||(r.badge+' '+r.file+' '+r.title+' '+r.notes+' '+r.specHas.join(' ')+' '+r.appAhead.join(' ')+' '+r.propsMissing.join(' ')+' '+r.backendMissing.join(' ')).toLowerCase().includes(q)),r=>`<div class="scr"><button class="scr-head" aria-expanded="false"><span class="dot d-${CO[r.bucket]}"></span><span><span class="scr-title">${hi(r.title)}</span><span class="scr-file">${esc(r.file)}</span></span><span class="lwbadge">${esc(r.badge)}</span><span class="pill p-${r.bucket}">${LB[r.bucket]}</span><span class="eff">${r.effort==='none'?'—':r.effort}</span></button><div class="detail hidden">${screenDetail(r)}</div></div>`,'bucket');}
  else if(view==='caps'){b.innerHTML=groupView(D.caps,'area',['working','partial','blocked','absent'],r=>(filter==='all'||r.maturity===filter)&&(!q||(r.badge+' '+r.capability+' '+r.scaleNote+' '+r.blocker).toLowerCase().includes(q)),r=>{let d='<dl>';if(r.blocker)d+=`<dt class="blk">⛔ Blocker</dt><dd>${hi(r.blocker)}</dd>`;if(r.scaleNote)d+=`<dt>At scale</dt><dd>${hi(r.scaleNote)}</dd>`;if(!r.blocker&&!r.scaleNote)d+='<dd class="ok">Working.</dd>';d+='</dl>';return `<div class="scr"><button class="scr-head" aria-expanded="false"><span class="dot d-${CO[r.maturity]}"></span><span class="scr-title">${hi(r.capability)}</span><span class="lwbadge">${esc(r.badge)}</span><span class="pill p-${r.maturity}">${LB[r.maturity]}</span><span class="eff"></span></button><div class="detail hidden">${d}</div></div>`;},'maturity');}
  else if(view==='debt'){
    const STATES=[['open','Outstanding — not yet addressed'],['deferred','Deferred — intentionally later (post-alpha / a future slot)'],['resolved','Resolved this wave']];
    let html='';
    STATES.forEach(([st,label])=>{
      const g=D.debt.filter(r=>(r.state||'open')===st&&(filter==='all'||(r.state||'open')===filter)&&(!q||(r.badge+' '+r.title+' '+r.owner+' '+r.location+' '+r.status+' '+(r.note||'')).toLowerCase().includes(q)));
      if(!g.length)return;
      const op=st!=='resolved';
      html+=`<section class="area"><button class="area-head" aria-expanded="${op}"><span class="area-name"><span class="pill p-${st}">${LB[st]||st}</span> ${esc(label)}</span><span class="counts">${g.length}</span><span class="chev">›</span></button><div class="rows${op?'':' hidden'}">`+g.map(r=>`<div class="scr"><button class="scr-head" aria-expanded="false"><span class="dot d-${CO[r.state||'open']}"></span><span class="scr-title">${hi(r.title)}</span><span class="lwbadge">${esc(r.badge)}</span><span class="eff">${esc(r.severity)} sev</span></button><div class="detail hidden"><dl><dt>Status</dt><dd>${hi(r.status)}</dd><dt>Owner</dt><dd>${hi(r.owner)}</dd><dt>Where</dt><dd class="meta">${hi(r.location)}</dd>${r.note?`<dt>Note</dt><dd>${hi(r.note)}</dd>`:''}<dt>Severity if unresolved</dt><dd>${esc(r.severity)}</dd></dl></div></div>`).join('')+`</div></section>`;
    });
    b.innerHTML=html||'<div class="detail">No matches.</div>';
  }
  else if(view==='fleet'){
    const waves=D.fleet.waves.map(w=>`<span class="wv">${w.id}</span> ${esc(w.name)} <span class="pill p-${w.status}">${LB[w.status]}</span>`).join(' &nbsp;·&nbsp; ');
    let html=`<div class="note" style="border-inline-start-color:var(--good)">🚀 <b>Wave 5 LAUNCHED (2026-07-30) — finish-line build in progress.</b> Wave 4 is GREEN (authoritative suite 1343/0). The build lanes are closing the last screens + capabilities; each flips to <span class="pill p-done">done</span> as its four-way report lands. Re-gate → arm → the operator's walk to follow. Each lane's W5 orders (<span class="pill p-next">next</span> = finish-line work) are drillable below; completed Wave-4 work collapses under each lane.</div><div class="wavesline"><b>Waves:</b> ${waves}</div>`;
    const bk=['next','done','held','deferred','active'];
    D.fleet.lanes.forEach(l=>{const items=l.items||[];
      const w5=items.filter(it=>it.wave==='W5'),w4=items.filter(it=>it.wave!=='W5');
      const cur=w5.length?w5:w4;
      const vis=cur.filter(it=>(filter==='all'||it.status===filter)&&(!q||('l'+l.id+' '+l.name+' '+it.label+' '+(it.note||'')).toLowerCase().includes(q)));
      const w4hit=q?w4.filter(it=>(it.label+' '+(it.note||'')).toLowerCase().includes(q)):[];
      if(!vis.length&&!w4hit.length)return;
      const c={};bk.forEach(k=>c[k]=0);cur.forEach(it=>{if(it.status in c)c[it.status]++;});
      const bar=bk.map(k=>c[k]?`<span class="s-${CO[k]}" style="flex:${c[k]}"></span>`:'').join('');
      const cnt=bk.filter(k=>c[k]).map(k=>c[k]+' '+k).join(' · ')||'—';
      const wl=w5.length?'W5':'W4';
      html+=`<section class="area"><button class="area-head" aria-expanded="false"><span class="area-name"><span class="lwbadge">L${esc(l.id)}·${wl}</span> Lane ${esc(l.id)} · ${esc(l.name)} <span class="pill p-${l.status}">${LB[l.status]||esc(l.status)}</span></span><span class="bar">${bar}</span><span class="counts">${esc(cnt)}</span><span class="chev">›</span></button><div class="rows hidden">`;
      vis.forEach(it=>{html+=`<div class="scr"><button class="scr-head" aria-expanded="false"><span class="dot d-${CO[it.status]}"></span><span class="scr-title">${hi(it.label)}</span><span class="pill p-${it.status}">${LB[it.status]||esc(it.status)}</span></button><div class="detail hidden"><dl>${it.note?`<dt>Detail</dt><dd>${hi(it.note)}</dd>`:'<dd class="ok">—</dd>'}</dl></div></div>`;});
      if(w5.length&&w4.length){html+=`<details class="lanehist"><summary>Wave 4 · ${w4.length} done ✓</summary>${w4.map(it=>`<div class="donerow"><span class="dot d-${CO[it.status]}"></span> ${hi(it.label)}</div>`).join('')}</details>`;}
      html+=`</div></section>`;
    });
    b.innerHTML=html;
  }
  else if(view==='questions'){
    const vis=D.questions.filter(r=>(filter==='all'||r.status===filter)&&(!q||(r.q+' '+r.detail).toLowerCase().includes(q)));
    let html='<div class="qbar"><button class="chip" id="qexport">⭳ Export answers</button><span class="qhint">Pick an option and add notes on each open question — your answers save in the page. When done, click Export (copies to clipboard) or screenshot; either lets the desk read them and update the fleet orders.</span></div><div id="qexport-wrap" class="qexport-wrap hidden"><button class="qcopy" id="qexport-copy" title="Copy answers to clipboard">⧉ Copy</button><pre id="qexport-out" class="qexport"></pre></div>';
    vis.forEach(r=>{
      if(r.status==='resolved'){html+=`<div class="qcard resolved"><div class="qhead"><span class="lwbadge">${/^\d+$/.test(String(r.lane))?'L':''}${esc(r.lane)}</span><span class="qtext">${hi(r.q)}</span><span class="pill p-resolved">resolved</span></div><div class="qdetail">${hi(r.detail)}</div></div>`;}
      else{const a=ANS[r.id]||{};const sel=a.sel||'';const nt=a.notes||'';
        html+=`<div class="qcard open"><div class="qhead"><span class="lwbadge">${/^\d+$/.test(String(r.lane))?'L':''}${esc(r.lane)}</span><span class="qtext">${hi(r.q)}</span><span class="pill p-open">open</span></div><div class="qdetail">${hi(r.detail)}</div><div class="qopts">`+r.options.map(o=>`<label class="qopt${sel===o.k?' on':''}"><input type="radio" name="q_${r.id}" value="${o.k}"${sel===o.k?' checked':''}><span class="qk">${o.k}</span><span>${esc(o.t)}</span></label>`).join('')+`</div><textarea class="qnotes" data-id="${r.id}" placeholder="Notes / your own answer…">${esc(nt)}</textarea></div>`;}
    });
    b.innerHTML=html;
    b.querySelectorAll('input[type=radio]').forEach(inp=>inp.addEventListener('change',e=>{const id=e.target.name.slice(2);saveAns(id,'sel',e.target.value);e.target.closest('.qopts').querySelectorAll('.qopt').forEach(l=>l.classList.toggle('on',l.querySelector('input').checked));}));
    b.querySelectorAll('.qnotes').forEach(ta=>ta.addEventListener('input',e=>saveAns(e.target.dataset.id,'notes',e.target.value)));
    const ex=document.getElementById('qexport');if(ex)ex.addEventListener('click',()=>{const L=['CGA OPEN-QUESTIONS — operator answers'];D.questions.filter(x=>x.status==='open').forEach(x=>{const a=ANS[x.id]||{};const s=a.sel||'(none)';const ot=(x.options.find(o=>o.k===s)||{}).t||'';L.push('\n[L'+x.lane+'] '+x.q+'\n  = '+s+(ot?' — '+ot:'')+(a.notes?'\n  notes: '+a.notes:''));});const txt=L.join('\n');const o=document.getElementById('qexport-out');o.textContent=txt;document.getElementById('qexport-wrap').classList.remove('hidden');try{navigator.clipboard.writeText(txt);}catch(e){}});const cp=document.getElementById('qexport-copy');if(cp)cp.addEventListener('click',()=>{const t=document.getElementById('qexport-out').textContent;const done=()=>{cp.textContent='\u2713 Copied';cp.classList.add('ok');setTimeout(()=>{cp.textContent='\u29c9 Copy';cp.classList.remove('ok');},1400);};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(done).catch(()=>{});}else{const ta=document.createElement('textarea');ta.value=t;document.body.appendChild(ta);ta.select();try{document.execCommand('copy');done();}catch(e){}ta.remove();}});
    return;
  }
  b.querySelectorAll('.area-head').forEach(h=>{const list=h.nextElementSibling;if(!list)return;h.addEventListener('click',()=>{const hid=list.classList.toggle('hidden');h.setAttribute('aria-expanded',String(!hid));});});
  b.querySelectorAll('.scr-head').forEach(h=>{const dt=h.nextElementSibling;h.addEventListener('click',()=>{const hid=dt.classList.toggle('hidden');h.setAttribute('aria-expanded',String(!hid));});});
  if(q||filter!=='all')b.querySelectorAll('.rows').forEach(x=>x.classList.remove('hidden'));
}
function buildFilters(){document.getElementById('filters').innerHTML=FILTERS[view].map(f=>`<button class="chip" data-f="${f}" aria-pressed="${f===filter}">${f[0].toUpperCase()+f.slice(1)}</button>`).join('');
  document.querySelectorAll('.chip[data-f]').forEach(ch=>ch.addEventListener('click',()=>{filter=ch.dataset.f;document.querySelectorAll('.chip[data-f]').forEach(x=>x.setAttribute('aria-pressed',String(x===ch)));render();}));}
document.querySelectorAll('.view-btn').forEach(vb=>vb.addEventListener('click',()=>{view=vb.dataset.v;filter='all';document.querySelectorAll('.view-btn').forEach(x=>x.setAttribute('aria-selected',String(x===vb)));buildFilters();render();}));
document.getElementById('q').addEventListener('input',e=>{q=e.target.value.trim().toLowerCase();render();});
document.getElementById('exAll').addEventListener('click',()=>{document.querySelectorAll('#body .rows,#body .detail').forEach(x=>x.classList.remove('hidden'));document.querySelectorAll('#body .area-head,#body .scr-head').forEach(x=>x.setAttribute('aria-expanded','true'));});
document.getElementById('coAll').addEventListener('click',()=>{document.querySelectorAll('#body .rows,#body .detail').forEach(x=>x.classList.add('hidden'));document.querySelectorAll('#body .area-head,#body .scr-head').forEach(x=>x.setAttribute('aria-expanded','false'));});
buildFilters();render();
</script>
"""

stamp = "As of %s · main @ <code>%s</code> · Geodata clean (0 orphans) · UI walkthrough resolved · District-mapping INTEGRATION questions OPEN (4, lane scale) — gate the Fable-5 build" % (DATA['asOf'], DATA['head'])
html = TEMPLATE.replace('%%DATA%%', json.dumps(DATA, separators=(',', ':'))).replace('%%STAMP%%', stamp)
# Output next to the script, not a hard-coded box path — the generator now
# runs on whichever checkout you are in (this regen ran on the GAME box).
out = os.path.join(_HERE, 'app_progress_rubric.html')
with io.open(out, 'w', encoding='utf-8') as f:
    f.write(html)
print('wrote', out, len(html), 'bytes ·', len(screens), 'screens', len(caps), 'caps', len(debt), 'debt', len(FLEET['lanes']), 'lanes', len(QUESTIONS), 'questions')
