# -*- coding: utf-8 -*-
# Fleet & Waves — per-lane Wave 4 responsibilities broken into items with status,
# rendered like UI Screens / Capabilities (drillable, status-badged).
# status per item: done | active (in progress) | held (awaiting operator/desk) | deferred (post-alpha / later slot)

FLEET = {
  "waves": [
    {"id": "W1", "name": "Shell · demo · learn", "status": "done"},
    {"id": "W2", "name": "~22 screens · parity UIs · forms→113", "status": "done"},
    {"id": "W3", "name": "Type B mapper · keystone · K-2 · economy · coordinator · tour · forms→117", "status": "done"},
    {"id": "W4", "name": "To GREEN: Type B race fix · screen+capability closes · debt paydown · the walk", "status": "done"},
  ],
  # Wave-4 gate: authoritative full suite 1343 passed · 0 failed · 3 skipped (quiet window). GREEN.
  "lanes": [
    {"id": "1", "name": "GeoData & District Maps", "status": "active", "items": [
      {"label": "Type B race fix — SEATING half (one at-large race per clump)", "status": "done", "note": "c500a1f; racePlan panels-mode + createRaces + RaceFootprint join"},
      {"label": "Niue cleared LIVE in the per-clump shape", "status": "done", "note": "apply('active') → 5 panels/10 seats, both chambers elect, no drift (verified through racePlan)"},
      {"label": "Adversarial pass — 2 latent defects the 933-pin suite missed", "status": "done", "note": "5f2293b: stale-grouping drift + R-A bypass; by-election panel-id leak"},
      {"label": "Fixture reds (LegalCompliance + MyProfileTabs)", "status": "done", "note": "a32bbc8; own-the-world scoping"},
      {"label": "AutoscaleResizeRepair SQL-clamp CHECK constraint", "status": "done", "note": "migration 220100; 7 rejected / 4 accepted"},
      {"label": "GeodataRepairPlaneTest red (manifest write path)", "status": "done", "note": "06d9545; self-owned subdir"},
      {"label": "Per-child (ungrouped) races — joint L1/3/4 step", "status": "active", "note": "post-gate; L1 leads racePlan; San Marino demo correctness"},
      {"label": "Confirm Niue reshape after L3 voids 2 stale elections", "status": "active", "note": "post-gate coordination with L3"},
      {"label": "Game-box mass pass over ~9,708 flagged chambers", "status": "held", "note": "operator-coordinated deployment; protected by the stale-grouping guard"},
    ]},
    {"id": "2", "name": "Cloud Launch – Multibox", "status": "done", "items": [
      {"label": "Operator/system partials → built (setup · dns · system-setup · amendments · term-sync)", "status": "done", "note": "e702a43 + reconciles; 3 were stale gap-data (already built)"},
      {"label": "Cross-class handshake 500 → 409/422 + a real class-isolation leak", "status": "done", "note": "1c6b6d9; instance_class/game_mode were stripped pre-check (demo peer could enter a production mesh)"},
      {"label": "Demo-mesh game_mode field + coordinating node + adoption class", "status": "done", "note": "handshake + /adopt (demo-gated, migrated-gated)"},
      {"label": "Federation-console route reconcile → /operator/federation", "status": "done", "note": "32360c0; 4 operator docs"},
      {"label": "MatrixCarveoutEmitter red", "status": "done", "note": "56f137a; legislatures-active exclusion (fixture drift)"},
      {"label": "SUITE STEWARD — authoritative green gate in a quiet window", "status": "done", "note": "1343 passed / 0 failed / 3 skipped; validated the SUITE-TOKEN procedure"},
    ]},
    {"id": "3", "name": "Institution Scaling", "status": "active", "items": [
      {"label": "Type B race fix — COUNTING half (per-clump electorate + seat_no)", "status": "done", "note": "6f85d322 count (pair→200/single→100) + 8ec50402 chamber-wide seat_no"},
      {"label": "Keystone Live Civic Room — exit-walk acceptance gate", "status": "done", "note": "8bcb3ee; committee hearing e2e in one room, 80 asserts"},
      {"label": "Service-scale formula → InstitutionScaleService (4 statics)", "status": "done", "note": "37b7a64; parity-pinned against L4's provisioning mirror"},
      {"label": "Electoral partials → built (ranked-ballot + department-reporting)", "status": "done", "note": "f333eba + liveAggregate"},
      {"label": "RankedBallot liveAggregate (cache-based, daily-batched)", "status": "done", "note": "4a118e74 + 4a552614; no in-request decrypt"},
      {"label": "Oversight-public console + test-hardening", "status": "done", "note": "4057b3c (ruling B) + eba53854 (guest sees in-progress removal)"},
      {"label": "ModerationFlipTest red", "status": "done", "note": "6fe2c77; own-the-bootstrap-jurisdiction fix"},
      {"label": "Poller consolidation onto useLiveRoom", "status": "active", "note": "really 4 not 9 — 2/4 byte-parity done (MatrixCommons/PrivateRoom); Results/VacancyCountback deferred"},
      {"label": "Niue stale-election void (2 real UUIDs)", "status": "active", "note": "post-gate; ElectionLifecycleService::cancel → CLK-01 re-mints per-clump"},
      {"label": "Agenda per-item schema", "status": "deferred", "note": "④ migration slot behind L13; not blocking"},
    ]},
    {"id": "4", "name": "Simulated World Engine", "status": "active", "items": [
      {"label": "BUILD the Atlas — front-door screen + capability", "status": "done", "note": "8d03a19 page + 3b9ff99 world_stats rollup; 25 real PostGIS places, gauge-never-lever, unmeasured=em-dash"},
      {"label": "Wire service-scale formula into provisioning + parity pin", "status": "done", "note": "331271b; fixed a real defect (min_judges hardcoded 5 for every court); SQL-mirror parity 366 asserts"},
      {"label": "R-A un-flag — Niue is L1's first real cleared chamber", "status": "done", "note": "W4③ pin keys on the persisted flag; ElectionStage re-run"},
      {"label": "Growth dial — committee half (F-LEG-009 → K)", "status": "done", "note": "f9161f5; created_by_vote_id proves the sim minted no act"},
      {"label": "Growth dial — department half (F-LEG-014/016 → D)", "status": "done", "note": "74603d4; charter_law_id proof; defers-with-reason on every gate"},
      {"label": "Pump wiring — make the growth dial autonomous", "status": "active", "note": "post-gate; register GovernanceStage in SimPumpCommand + sim-behavior verify"},
      {"label": "Q4a — court tiers / civic rooms materialisation", "status": "held", "note": "operator ruling (schema forbids as written; rec courtTiers=tree-depth, rooms=future model)"},
    ]},
    {"id": "5", "name": "Translation Scaling", "status": "done", "items": [
      {"label": "Multi-track VIDEO PLAYER live at /videos", "status": "done", "note": "2fea981; app-ported from the operator's Coalition player (NOT from scratch); 77-lang track-swap browser-proven"},
      {"label": "flows.js action-string i18n extraction", "status": "done", "note": "645 keys / 272 action; translation deferred (long-form ~11h)"},
      {"label": "zh-Hans QA (worst-first for a Chinese reader)", "status": "done", "note": "245 flags incl. the 95; rail intact"},
      {"label": "translation-home → built", "status": "done", "note": "c25a5e9; verifier-section + add-a-language CTA"},
      {"label": "Adversarial review (ultracode) — 4 defects fixed", "status": "done", "note": "fe5ad51; reactive-src audio fix + poster mis-tag"},
      {"label": "Full i18n (6 non-English locales)", "status": "active", "note": "page bodies render for all 6; fr/pt monolithic CHROME withheld (NLLB confidently-wrong nav) → review queue"},
    ]},
    {"id": "6", "name": "UI Design + A11y Audit", "status": "active", "items": [
      {"label": "Tour-nav placement fix + tour 47→60 stops", "status": "done", "note": "0fb053f; 13 nav destinations restored, all probed live"},
      {"label": "ballots_cast=0 bug + the BallotSecrecy fix (via BallotBox)", "status": "done", "note": "aaa0a59 → 3d1abbb; 420 voters saw a false zero; count routed through BallotBox::participationCountFor"},
      {"label": "SECURITY — menu gated tighter than the constitution", "status": "done", "note": "adea521; residents couldn't file an Art. IV §5 challenge / join the bar (the menu WAS the gate)"},
      {"label": "SECURITY — private-room re-invite hole", "status": "done", "note": "32a288a; a removed member could re-invite themselves"},
      {"label": "Civic partials → built (join · today · office-tab · advocate-reg · identity-verify · relocation)", "status": "done", "note": "6/6; every 'missing panel' was live data discarded by a collapsing query — no schema"},
      {"label": "SupportLifecycleTest red", "status": "done", "note": "own-the-world → measures the CLAIM (baseline→+2→both appear)"},
      {"label": "14-agent gap analysis + batch plan", "status": "done", "note": "L6W4_SCREEN_GAP_PLAN.md; every screen YES-with-honest-empty"},
      {"label": "Social/groups partials (org-profile · groups-home · group-create · group-detail)", "status": "active", "note": "in progress after civic"},
      {"label": "bill.html — on the constitutional path (motion+chamber-vote, not per-party accept)", "status": "active", "note": "the mockup's per-party accept is an Art. V §3 violation the code already rejects"},
      {"label": "Atlas a11y review pass (findings → lane 4)", "status": "deferred", "note": "optional; after the civic/social partials"},
      {"label": "THE WALK — 54 journeys + 117-stop tour + pixel capture", "status": "held", "note": "operator-present, runs when all-green"},
    ]},
    {"id": "13", "name": "Economy Engine", "status": "done", "items": [
      {"label": "12 economy partials → built", "status": "done", "note": "treasury · units/wallet/home · org-settings · exchange · agreements pair; read-only prop-fill over existing tables"},
      {"label": "Founding-stake (structure-aware)", "status": "done", "note": "07a8315; 100% only for stock orgs (VIA_FOUNDING); touched shared F-IND-012"},
      {"label": "Secondary share trading — share_offers + F-IND-021 mint", "status": "done", "note": "1cdf535 table + a3c63c2 mint (117→118); populated exchange floor"},
      {"label": "Adversarial review (ultracode) — 8 real defects fixed", "status": "done", "note": "e8c2884; wallet-negative, phantom equity, dangling membership, no-stock-recheck, race, name-leak"},
      {"label": "CHECK(balance>=0) on economic_accounts — defense-in-depth", "status": "deferred", "note": "optional later slot; app-layer row lock already closes the overdraft hole"},
    ]},
    {"id": "15", "name": "Education + Achievements", "status": "done", "items": [
      {"label": "Education arming = PRE-TRAIN (seeders file F-EDU-001, then education:seed)", "status": "done", "note": "c47b50f; dev box left unseeded on purpose; gates the operator's walk"},
      {"label": "Profile-edit door (F-IND-002) + person-to-person DM", "status": "done", "note": "ac72ad9; values off the public chain; DM from a found profile (no directory)"},
      {"label": "Journey + social-home educational slices (co-owned w/ L6)", "status": "done", "note": "663bf08 + 47d8d57; community-standards card grounded in real F-SOC-003/M-4/M-5"},
      {"label": "seat_training_window_days registration-day review", "status": "done", "note": "fa1e428; confirmed RETIRED — A5 stands, no window resurrected"},
    ]},
    {"id": "14", "name": "Coalition Organization (Phase J)", "status": "held", "items": [
      {"label": "HELD by the operator", "status": "held", "note": "Foundation = 501(c)(3) parent; Coalition = a PROJECT (child); Action Fund = DO NOT BUILD. Resumes on his word."},
    ]},
  ],
}
