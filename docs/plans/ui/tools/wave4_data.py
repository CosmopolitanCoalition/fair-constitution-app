# -*- coding: utf-8 -*-
# Fleet & Waves — per-lane responsibilities, drillable + status-badged (like UI Screens / Capabilities).
# item status: next (W5 order, for review) | done | held (operator-gated) | deferred (post-alpha / later slot)
# items carry "wave": "W5" for the finish-line orders; unmarked items are Wave-4 history (done).

FLEET = {
  "waves": [
    {"id": "W1", "name": "Shell · demo · learn", "status": "done"},
    {"id": "W2", "name": "~22 screens · parity UIs · forms→113", "status": "done"},
    {"id": "W3", "name": "Type B mapper · keystone · K-2 · economy · coordinator · tour · forms→117", "status": "done"},
    {"id": "W4", "name": "To GREEN: Type B race fix · screen+capability closes · debt paydown", "status": "done"},
    {"id": "W5", "name": "THE FINISH: 6 screens → 107/107 · 5 caps · court reframe · re-gate · arm · walk", "status": "next"},
    {"id": "W6", "name": "CONFERENCE DEMO: read-only scaled demo mesh, simulated data, public and walkable · OIDP 25th Conference, Kraków, 21–23 Sep 2026 · operator in Kraków by 09-18", "status": "next"},
  ],
  # W4 gate: full suite 1343/0/3-skip (quiet window), GREEN. W5 = close the last 6 screens + 5 caps → all-green → arm → walk.
  "lanes": [
    {"id": "1", "name": "GeoData & District Maps", "status": "held", "items": [
      {"wave": "W6", "label": "P2 · Redraw the 9 active maps that carry a zero-seat district", "status": "next", "note": "A zero-population bin under the sub-2 rule writes seats = 0. racePlan blocks the whole Type A half of those 9 legislatures (type_a 10..78). Decision: question zero-seat-chambers. Evidence: DistrictingService.php:6556-6564; ElectionLifecycleService.php:594-603"},
      {"wave": "W6", "label": "P3 · Two live legislatures share one jurisdiction_id", "status": "next", "note": "940,327 legislatures vs 940,325 jurisdictions at adm ≤ 6. The sim stages take ->first(); the second chamber never elects. Decision: question dup-legislatures"},
      {"wave": "W6", "label": "P3 · requeueDriftMaps: give it a surface or retire it; grind shunt: add the config key", "status": "next", "note": "AutoscaleRunControl::requeueDriftMaps has no route, CLI or page. cga.districting.grind_box_seconds is read (default 120) and defined nowhere under config/"},
      {"wave": "W5", "label": "Fire the live per-clump Niue general (walk demo)", "status": "held", "note": "approved + fielding-pinned (80b5e3a); a live Type B election electing 10 seats across 5 panels for the operator's walk — his trigger"},
      {"wave": "W5", "label": "Game-box mass pass over ~9,708 flagged chambers", "status": "held", "note": "operator-coordinated: pull L1's commits to the game box, run ETL-chunked; the stale-grouping guard protects re-seeds"},
      {"label": "Type B race fix — SEATING (per-clump) + per-child + hardening", "status": "done", "note": "c500a1f + c96e757 + 1ffde5b; pooled shape fully retired, at_large = type_a-only"},
      {"label": "Niue cleared LIVE + reshape confirmed", "status": "done", "note": "5 panels/10 seats, both chambers elect; 2 stale elections voided, next mint per-clump"},
      {"label": "Adversarial pass + fixture reds + CHECK + Geodata red", "status": "done", "note": "5f2293b (2 defects) · a32bbc8 · 220100 · 06d9545"},
    ]},
    {"id": "2", "name": "Cloud Launch – Multibox", "status": "next", "items": [
      {"wave": "W6", "label": "P1 · Stand up the demo mesh host and load the world", "status": "next", "note": "Decision: question demo-mesh-host (WoS Azure box with a sovereign restore of box E's world, or a mirror join). Then: export bundle from box E, restore, DNS + TLS, smoke walk. Memory: project_lane2_cloud_launch (clone/transfer for Poland access)"},
      {"wave": "W6", "label": "P1 · Read-only lock for the demo mesh", "status": "next", "note": "Decision: question read-only-lock. Reads and walking stay open; every write refuses. Applied once the demo world is proven"},
      {"wave": "W6", "label": "P1 · Public walk for guests after setup completes", "status": "next", "note": "Decision: question public-walk. Today RedirectIfSetupIncomplete lifts at completion and most read routes are public already; /simworld and /building sit inside auth groups"},
      {"wave": "W6", "label": "P2 · Boot-time prewarm on every horizon restart", "status": "next", "note": "docker/php/entrypoint.sh:133-134 queues a planet raster + geojson warm on each fc_horizon start; 139 of the 343 failed rows are these. Decision: question boot-prewarm"},
      {"wave": "W6", "label": "P2 · Clock sweeps off the 60 s default queue", "status": "next", "note": "EvaluateClocksJob + residency/critical-population/petition sweeps + SnapshotWorldStatsJob ride supervisor-1 (tries 1, timeout 60); 9 timeout rows already with zero timers armed. Decision: question default-queue-sweeps"},
      {"wave": "W6", "label": "P3 · Document the scheduler container and remove the stale protected-file entry", "status": "next", "note": "fc_scheduler (docker-compose.yml:503-526) is the liveness root for every pump and is absent from CLAUDE.md's service table; app/Services/ElectionTriggerService.php is listed in CLAUDE.md and ConstitutionalVersionService and does not exist"},
      {"wave": "W5", "label": "Authoritative RE-GATE — full suite, quiet window, after the build lands", "status": "next", "note": "SUITE TOKEN + freeze + storage-chown; confirm ALL-GREEN before the walk. Your triage: own-the-world → audit-lock → real defect"},
      {"wave": "W5", "label": "Per-lane LIVE_PG_DATABASE (retire the freeze)", "status": "deferred", "note": "post-alpha infra; a config change (LivePgConnection already takes a conn name), not a refactor"},
      {"label": "Operator/system partials + handshake 409/422 + class-isolation fix", "status": "done", "note": "e702a43 + 1c6b6d9; a demo peer could enter a production mesh (game_mode stripped pre-check)"},
      {"label": "Demo-mesh coordinator + federation reconcile + Matrix red", "status": "done", "note": "32360c0 + 56f137a"},
      {"label": "SUITE STEWARD — the 1343/0 green gate + audit-lock diagnosis", "status": "done", "note": "validated the SUITE-TOKEN / quiet-window procedure"},
    ]},
    {"id": "3", "name": "Institution Scaling", "status": "next", "items": [
      {"wave": "W6", "label": "P1 · Step 4 engine: per-legislature ledger + pump + lanes on the long lane", "status": "next", "note": "The autoscale posture (apportionment_ledger + AutoscaleClaims + pump + HostCapacity) applied to institutions: one row per legislature, claim ladder ordered by cost from both ends, halt/resume/requeue, per-step and per-lane progress with ETA. Replaces the single ProvisionInstitutionsJob (default queue, retry_after 90 s, tries 1: failed 2026-09-05 18:47:36). Remove the done-flip dispatch. Ruling subtree-activation-shape B+ applies"},
      {"wave": "W6", "label": "P1 · Finish the shell set through the ledger", "status": "next", "note": "Live: executives 923,093 / judiciaries 400,000 / election boards 1 / board members 1 / civic spaces 0 for 940,327 legislatures. The five SQL steps in InstitutionProvisionService are set-based, idempotent and live-unique (migration 2026_07_25_000002); reuse them as lane work"},
      {"wave": "W6", "label": "P1 · Seat minting per legislature (elections + races)", "status": "next", "note": "No mass caller exists for scheduleGeneral / racePlan / createRaces. Decision: question seat-mint-owner. Hazards: 17,250 zero-seat chambers (scheduleGeneral keeps an orphan row), constitutional_version pin at creation (no hardened-file deploy between scheduling and certification)"},
      {"wave": "W6", "label": "P2 · One bench law, one quorum formula, one floor resolution", "status": "next", "note": "Mass path 5/7/9 by tier vs stub 5; capped quorum in 2 writers vs uncapped in 5 (17 zero-seat chambers hold quorum 3); cube-root floor from a constant, the planet row, or the own row depending on the caller. Decision: question bench-and-quorum-law"},
      {"wave": "W6", "label": "P2 · Committees and departments for the demo", "status": "next", "note": "Ruling scale-committees B (eager) stands; the code has no provisioning step for them and the sim files them through real votes. Decision: question sub-institutions-path"},
      {"wave": "W6", "label": "P2 · Population-mode autoboot: close the loop (ruled A, 2026-08-29)", "status": "next", "note": "EvaluateCriticalPopulationJob writes state only; nothing calls activate(). Effective threshold is 1 resident (tier disabled) while Step 2 copy says 5–9. Needed for the live mesh, not for the read-only demo"},
      {"wave": "W6", "label": "P3 · Subtree boot: regrow lanes, reset stuck rows, resume", "status": "next", "note": "Lanes collapse to one after the root wave; a three-strike running row pins the depth barrier; no reset control; FinishActivationsJob schedules elections in every mode on the default queue"},
      {"wave": "W6", "label": "P3 · Three Type B seat bands", "status": "next", "note": "Generator emits 2–4 seat Type B races unchecked; the validator binds type_b to 5–9 on the explicit F-ELB-001 payload path; F-ELB-003 binds districts to 5–9 while racePlan accepts 1..ceiling. type_b_seats_per_child, activation tier and critical population keys are absent from the amendment register and the bill key list"},
      {"wave": "W5", "label": "Committees — rank-ordered assignment + hearings → working", "status": "next", "note": "verify/complete the F-SPK-005 assignment run + the keystone hearing path end-to-end; pin it (currently the capability is partial)"},
      {"wave": "W5", "label": "Amendment workflow (R-C) end-to-end → working", "status": "next", "note": "propose → supermajority chamber vote → APPLY a constitutional setting through the real act pipeline; system/amendments is the front door; pin the loop"},
      {"wave": "W5", "label": "Executive delegation → conversion to directly-elected → working", "status": "next", "note": "the dual-supermajority conversion path (Art. III); verify + pin end-to-end"},
      {"wave": "W5", "label": "Appointed/elected courts (equal-per-constituent) → working", "status": "next", "note": "rides L4's courtTiers=tree-depth reframe; verify the appointed default + the elected conversion; pin"},
      {"wave": "W5", "label": "Agenda per-item schema — build (keystone)", "status": "next", "note": "the deferred keystone debt; flag the desk for the migration slot; completes the Live Civic Room's agenda"},
      {"label": "Type B COUNTING (per-clump + per-child) + keystone exit-walk + Niue void", "status": "done", "note": "6f85d322 + 8ec50402 + b9ea6d6 + 8bcb3ee; ① green on every axis"},
      {"label": "Service formula + electoral partials + liveAggregate + oversight + pollers", "status": "done", "note": "37b7a64 · f333eba · 4a118e74 · 4057b3c · 2/4 pollers"},
    ]},
    {"id": "4", "name": "Simulated World Engine", "status": "next", "items": [
      {"wave": "W6", "label": "P1 · Worker heartbeat during an item", "status": "next", "note": "sim_items.updated_at and the lease are touched only at claim and settle. An item over 30 min is reclaimed and re-executed by a second worker; over 2 min the pump seeds a replacement each minute; over 10 min the lease is culled. The districting lane has a heartbeat connection; copy it"},
      {"wave": "W6", "label": "P1 · Resume: enumerate without OFFSET and use the run's stored options", "status": "next", "note": "sim:start --resume stops at the first empty chunk and re-enumerates with the command-line adm-max/limit. SimStartCommand.php:56-59, 112-113, 179-239"},
      {"wave": "W6", "label": "P1 · Gate the run on Step 4 output and pick the demo scope", "status": "next", "note": "ElectionStage settles done with no election when a jurisdiction has no active board (1 board today); JudiciaryStage skips where no forming court exists. Decision: question sim-scope-for-demo (measure a pilot first; serial audit lock ≈ 28.6 appends/s means 940k certifications ≈ 9 h before governance and judiciary filings)"},
      {"wave": "W6", "label": "P2 · Claim order per row; counting mint scoped to this run's election", "status": "next", "note": "position = chunk offset, so largest-first holds only per 25,000-row band; the counting mint takes every open election in the jurisdiction (SimPumpCommand.php:213-233)"},
      {"wave": "W6", "label": "P2 · Empty phases: implement or remove enumerating, profiling, verifying; sim:revert", "status": "next", "note": "Declared in SimRun::PHASES, no stage, mint nothing; the run passes verifying to done with no acceptance scan. sim:revert is named in four files and absent"},
      {"wave": "W6", "label": "P2 · Console: cached snapshot, stage labels, workers target, banner", "status": "next", "note": "The 2 s poll runs planet-wide counts (~75 s per the parity test); governance/judiciary/civics kinds render raw; workers target shows the unsubtracted width; the not-scale_demo banner says the engine will refuse beside a working Start"},
      {"wave": "W5", "label": "Q4a — reframe courtTiers = jurisdiction tree-DEPTH (doc + provisioning)", "status": "next", "note": "operator ruling A: Live Rooms/squares are INFRASTRUCTURE (a courthouse has many court rooms), NOT a court-as-jurisdiction. NEVER weaken the uniqueness constraints. No schema. Unblocks L3's courts capability"},
      {"label": "Atlas (front door) + service-formula provisioning + growth dial AUTONOMOUS", "status": "done", "note": "8d03a19/3b9ff99 · 331271b · f9161f5/74603d4/2ad8b1b; SimGovernanceWiringTest 4/4"},
      {"label": "R-A un-flag + per-child/per-clump sim fielding (+ 2 real defects fixed)", "status": "done", "note": "64c2a27 fielding + 3976919 rosterSize + 80b5e3a per-clump end-to-end fielding pin"},
    ]},
    {"id": "5", "name": "Translation Scaling", "status": "next", "items": [
      {"wave": "W5", "label": "i18n finish — fr/pt shell chrome", "status": "next", "note": "6-locale page bodies already render. OPERATOR'S CALL: (a) accept English chrome for the alpha playtest [desk rec — the chrome polish is cosmetic + rail-held] and mark i18n playtest-adequate, or (b) a reader/stronger-model pass on the chrome. Never ship raw NLLB nav"},
      {"label": "Video player /videos + flows extraction + zh-Hans + translation-home", "status": "done", "note": "2fea981 (app-ported, 77-lang); adversarial review fe5ad51"},
    ]},
    {"id": "6", "name": "UI Design + A11y Audit", "status": "next", "items": [
      {"wave": "W6", "label": "P1 · Wizard Steps 3 → 6: Continue, Scale Up, Simulate, Confirm and Close", "status": "next", "note": "Step 3 gets a Continue. Step 4 page: Scale institutions? yes/no, per-step bars, lanes, halt/resume/requeue, ETA. Step 5 page (synthetic-safe only): Simulate data? yes/no, options turnout/adm-max/limit/scope, gate on Step 4 output. Step 6: completion stamp with precondition, idempotency guard, audit entry, CLI twin setup:complete; no dispatch. Decisions: questions wizard-ladder, done-flip-vs-pages"},
      {"wave": "W6", "label": "P2 · Stepper off-by-one and the dead deferred field", "status": "next", "note": "SetupStepper marks completed+1 reachable while the server needs completed ≥ n; s.deferred is read and never defined"},
      {"wave": "W6", "label": "P3 · Copy fixes on the wizard", "status": "next", "note": "Step 2 population-mode caption (5–9 vs actual 1); Step 4 'Phase 2' and 'a planet takes minutes'; Step 1 'clamped to [min, max]'; Step 2 section numbering 1, 2, 2, 2, 4; dead Step 2 controls (country scope, fresh, skip population, pause on exception)"},
      {"wave": "W5", "label": "Messaging trio → built (groups-home · group-create · group-detail)", "status": "next", "note": "ONE atomic commit over the shared PrivateRoom files (splitting = merge conflict); v3 bubble chrome + last-message previews; unread / live-now / DM-vs-group persisted-kind stay honest-empty (no source table)"},
      {"wave": "W5", "label": "bill.html → built (constitutional path)", "status": "next", "note": "a bill as a conversation on motion kind='amendment' + chamber vote (NOT the mockup's per-party accept = an Art. V §3 violation the code rejects); comments ride the 8 bill-bound subforums; summary honest-empty; coordinate HallsController subforum_id passthrough with L3"},
      {"wave": "W5", "label": "Atlas a11y review pass (findings → lane 4)", "status": "next", "note": "the optional pass, now in scope; findings-to-L4, not a rebuild"},
      {"label": "Civic 6/6 + tour 47→60 + 4 unplanned bugs + org-profile + social-home restore", "status": "done", "note": "every 'missing panel' was live data discarded by a collapsing query — no schema"},
    ]},
    {"id": "13", "name": "Economy Engine", "status": "next", "items": [
      {"wave": "W5", "label": "board-elections → built", "status": "next", "note": "org + governmental board elections surface (prop-fill over the existing board module; verify tables via information_schema first)"},
      {"wave": "W5", "label": "org-registry → built", "status": "next", "note": "F-IND-012 org registration surface; the founder-stake path already lands here (stock orgs)"},
      {"wave": "W5", "label": "CHECK(balance>=0) on economic_accounts (defense-in-depth)", "status": "deferred", "note": "optional; if it takes the migration slot — the app-layer buyer-account row lock already closes the overdraft hole"},
      {"label": "12 economy partials + founding-stake + secondary trading + 8 adversarial fixes", "status": "done", "note": "F-IND-021 mint (117→118); e8c2884 hardened concurrency/integrity"},
    ]},
    {"id": "15", "name": "Education + Achievements", "status": "held", "items": [
      {"wave": "W5", "label": "Arm the game box — education:seed (operator's trigger)", "status": "held", "note": "pre-train arming is built (seeders file F-EDU-001, then education:seed); seeds the training gate on the game box when the operator is ready to walk. Do NOT seed the dev box"},
      {"label": "Pre-train arming + profile-edit + p2p DM + journey/social-home slices", "status": "done", "note": "c47b50f · ac72ad9 · 663bf08/47d8d57"},
    ]},
    {"id": "14", "name": "Coalition Organization (Phase J)", "status": "held", "items": [
      {"label": "HELD by the operator", "status": "held", "note": "Foundation = 501(c)(3) parent; Coalition = a PROJECT (child); Action Fund = DO NOT BUILD. Resumes on his word."},
    ]},
  ],
}
