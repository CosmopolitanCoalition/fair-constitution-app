# -*- coding: utf-8 -*-
# Wave 4 standing orders (per lane) + fleet waves + open-questions decision queue.
# Goal: as close to GREEN as possible across UI Screens AND Capabilities -> tested playable game.

FLEET = {
  "waves": [
    {"id": "W1", "name": "Shell · demo · learn", "status": "done"},
    {"id": "W2", "name": "~22 screens · parity UIs · forms→113", "status": "done"},
    {"id": "W3", "name": "Type B mapper · keystone · K-2 · economy · coordinator · tour · forms→117", "status": "done"},
    {"id": "W4", "name": "To GREEN: Type B race fix, screen+capability closes, debt paydown, the walk", "status": "next"},
  ],
  "lanes": [
    {"id": "1", "name": "GeoData & District Maps", "orders": [
      {"wave": "W2", "status": "done", "text": "Fixture family green on a founded box · jurisdiction-browser S-adds · autoscale/maps UI↔CLI parity."},
      {"wave": "W3", "status": "done", "text": "Type B DISTRICT MAPPER: grouping engine + B3 combined cap + B6/B7 pins + Step-3 mapper UI · Niue = first cleared chamber · 30 pins · adversarial pass fixed 3 latent defects."},
      {"wave": "W4", "status": "next", "text": "① THE TYPE B RACE FIX — SEATING HALF (joint w/ lane 3 counting): racePlan/createRaces emit one at-large race PER CHILD, or PER CLUMP when clumped; add a clump/grouping key to election_races. Then re-activate Niue's draft grouping + clear the flag in the CORRECT shape. ② GAME-BOX MASS PASS (~9,708 flagged chambers) under the ETL rule — on the operator's go. ③ Own LegalComplianceTest + MyProfileTabsTest (fixture-borrows-the-world reds). ④ AutoscaleResizeRepair SQL-clamp CHECK constraint (latent). UNBLOCKS: Type B elections + all bicameral acts at scale."},
    ]},
    {"id": "2", "name": "Cloud Launch – Multibox", "orders": [
      {"wave": "W2", "status": "done", "text": "One-command internet node · disintermediation→constituents · declared_class/game_mode keyless queue · operator pages."},
      {"wave": "W3", "status": "done", "text": "Demo-mesh time coordinator (service/CLI/UI, idempotent replay, refusal matrix) · schema queue closed · cloud-rehearsal runbook · fixed the 7 pre-existing federation reds (a Wave-2 route move)."},
      {"wave": "W4", "status": "next", "text": "① Operator/system screen partials → built: operator/setup, operator/dns (Identity Broker), system/setup, system/amendments, system/term-sync. ② Cross-class handshake 500 → graceful 4xx (PeerController catch). ③ Demo-mesh handshake game_mode field + coordinating node + adoption instance_class. ④ Federation-console route divergence (/operator/federation vs mesh spec) — reconcile or log. ⑤ Own MatrixCarveoutEmitter red (Matrix federation plane)."},
    ]},
    {"id": "3", "name": "Institution Scaling", "orders": [
      {"wave": "W2", "status": "done", "text": "All 5 §10 rulings · parity CLIs+UIs · docket · R-A guard · RankedBallot cold-start spec."},
      {"wave": "W3", "status": "done", "text": "THE LIVE CIVIC ROOM keystone: poll-first store (Q1/Q2 ruled) · composed committee-hearing room · operable floor (raise-hand→recognize) · oversight-public fold · per-institution provisioning · behavioral pin."},
      {"wave": "W4", "status": "next", "text": "① THE TYPE B RACE FIX — COUNTING HALF (joint w/ lane 1 seating): VoteCountingService counts per-child/per-clump. ② FINISH THE KEYSTONE EXIT WALK (post-compaction): committee hearing end-to-end. ③ Wire lane 4's service-scale formula into InstitutionScaleService (R-B). ④ Agenda per-item schema (keystone). ⑤ Electoral partials → built: ranked-ballot, department-reporting. ⑥ RankedBallot liveAggregate (on operator trigger). ⑦ Consolidate the 9 hand-rolled pollers onto useLiveRoom. ⑧ Own ModerationFlipTest red."},
    ]},
    {"id": "4", "name": "Simulated World Engine", "orders": [
      {"wave": "W1", "status": "done", "text": "Demo mode D1–D7 · /simworld drive controls · all parity rows."},
      {"wave": "W3", "status": "done", "text": "C3 services-per-population study → SERVICE_SCALE_FORMULA.md (cited) · ATLAS_DESIGN.md · R-A un-flag pin (pins the sim to schedule the Type B race the instant a flag clears)."},
      {"wave": "W4", "status": "next", "text": "① BUILD THE ATLAS (atlas.html absent → built; design approved) — the public world-metrics surface, nightly world_stats rollup, CI-1 gauge-never-lever. ② Wire the service-scale formula into provisioning (operator signed off). ③ R-A un-flag as Type B chambers clear (coordinate with lane 1's race fix). ④ Demo-world sizing partial → built. UNBLOCKS: the Atlas front-door screen + capability."},
    ]},
    {"id": "5", "name": "Translation Scaling", "orders": [
      {"wave": "W2", "status": "done", "text": "NLLB pass · flows sweep · i18n:review CLI · zh-Hans rail held."},
      {"wave": "W4", "status": "next", "text": "① INTEGRATE THE OPERATOR'S EXISTING MULTI-TRACK VIDEO PLAYER into shared/video-player.html (absent → built): the player already EXISTS (operator-built; the v3 mockups are based on it; the Cosmopolitan Coalition site uses it). REFERENCE: fleet-11 video-translate pipeline + the coalition site — NOT a from-scratch build. Lane 15's lessons consume it. ② flows.js action-string i18n extraction. ③ zh-Hans QA (95 flagged skips → Chinese reader). ④ translation-home partial → built (verifier-section props + add-a-language CTA). ⑤ Full i18n capability (6 non-English locales) partial → working. Continuous: translate what lane 15 authors."},
    ]},
    {"id": "6", "name": "UI Design + A11y Audit", "orders": [
      {"wave": "W1", "status": "done", "text": "Shell S1–S9 · AppShellV2 dock/tour/menu."},
      {"wave": "W3", "status": "done", "text": "A2 tour toggle (mode armed in place) · stops → 47/117 · /coverage + /coverage-ops instruments (proven failable) · nav-drift ruling (7 aligned, translations allowlisted) · launchpad + tour + department-detail built."},
      {"wave": "W4", "status": "next", "text": "① CIVIC partials → built: join (live presence), today (feed breadth), my-profile (office tab), advocate-registration, identity-verification, relocation. ② SOCIAL/GROUPS partials → built: org-profile, social-home, groups-home, group-create, group-detail. ③ BUILD bill.html (absent → built — a bill as a conversation). ④ Tour-nav placement fix (/tour index, not straight into stop 1). ⑤ /judiciary/docket 302 fix. ⑥ Orphan-surface deletions (on operator word). ⑦ THE WALK: 54 journey cards + the 117-stop tour + the consolidated pixel-capture pass (operator-present). Biggest screen-count lever to green."},
    ]},
    {"id": "13", "name": "Economy Engine", "orders": [
      {"wave": "W2", "status": "done", "text": "Engine-only writes · reader-privacy accounts-never-people · F-IND-019 + F-ORG-009 minted."},
      {"wave": "W3", "status": "done", "text": "Design Round 2 (11 decisions ruled) then the build: dues, telemetry, exchange (over assets), F-ORG-008 share issuance, redlines (both adapters), person-to-person agreements · forms → 117."},
      {"wave": "W4", "status": "next", "text": "① ECONOMY partials → built: exchange trading-floor (order book / trade tape / KPIs — or ruled honest-empty), org-settings economy (org-ledger card, taxes/levies table, fair-market/conversion), agreements + agreement-detail, marketplace, listing-detail, wallet, units, treasury. ② SECONDARY SHARE TRADING — only if the operator rules it in (own schema). ③ FOUNDING-STAKE structure-aware (100% stake only for stock orgs; touches F-IND-012). 12 economy partials — the second-biggest screen lever to green."},
    ]},
    {"id": "15", "name": "Education + Achievements", "orders": [
      {"wave": "W1", "status": "done", "text": "Learn + K-2 revision · flatJson catalogs."},
      {"wave": "W3", "status": "done", "text": "K-2 engine: SENSITIVE_KEYS, F-EDU-001/002 (→115), education schema, act-gate LIVE (acquiring free / acting asks), 3 Learn pages (server-side keys), e2e proof."},
      {"wave": "W4", "status": "next", "text": "① EDUCATION ARMING SEQUENCING — on the operator's ruling (A: pre-train demo members / B: demo the redirect loop live / C: don't seed). Gates the operator's browser walk of the gate. ② Profile-edit door (F-IND-002 extension) + person-to-person DM (two absent Identity capabilities) — on a design decision. ③ Journey + social-home partials (co-owned). ④ seat_training_window_days amendable setting (registration-day review)."},
    ]},
    {"id": "14", "name": "Coalition Organization (Phase J)", "orders": [
      {"wave": "W4", "status": "held", "text": "HELD by the operator. Foundation = 501(c)(3) parent; Coalition = a PROJECT (child); Action Fund = DO NOT BUILD. Resumes on the operator's word."},
    ]},
  ],
}

QUESTIONS = [
  {"q": "Type B race shape — pooled at-large vs per-child/per-clump?", "status": "resolved",
   "detail": "RULED per-child/per-clump (each child, or each clump when clumped, is its own at-large race). CLAUDE.md + design brief + FLEET_CONTEXT corrected @55b8846. The BUILD is Wave 4 (lane 1 seating + lane 3 counting); grouped chambers stay blocked until it lands.", "owner": "operator (ruled) → lanes 1+3 (build)"},
  {"q": "Education arming sequencing — how do untrained demo members behave when the gate arms?", "status": "open",
   "detail": "education:seed arms the act-gate for 6 civic tracks; every untrained role-holder then redirects on their next role-act. Options: (A) seeders pre-train demo members [lane 15 rec] · (B) seed and let the walk demo the redirect loop live · (C) don't seed this wave. Gates the operator's browser walk of the training gate.", "owner": "operator → lane 15"},
  {"q": "Game-box mass pass — run the Type B mapper over the real ~9,708 flagged chambers?", "status": "open",
   "detail": "The mapper is proven on Niue + adversarially hardened, but the ~9,708 flagged chambers live on the GAME box, not dev. Needs the operator to pull lane 1's commits there and give the go (ETL-chunked), OR defer to the Wave-4 rehearsal. Waits on the Type B race fix so cleared chambers get the correct race.", "owner": "operator (his box)"},
  {"q": "Lane 3 compaction — to run the keystone exit walk with fresh context?", "status": "open",
   "detail": "Lane 3 is fully captured and holding; the keystone Live Civic Room is built but not yet WALKED end-to-end (the acceptance gate). The exit walk is the last Wave-3 item and needs the operator to compact lane 3.", "owner": "operator (physical act)"},
  {"q": "RankedBallot liveAggregate — spin the secrecy-critical live-standings build?", "status": "open",
   "detail": "The one deliberately-carried Wave-2 item: live provisional standings during an OPEN ranked ballot, without an in-request decrypt (secrecy-critical). Cold-start spec ready; cadence ruled daily-batch. Awaits the operator's fresh-session trigger.", "owner": "operator (fresh-session trigger)"},
  {"q": "Secondary share trading — pull into Wave 4 or leave deferred?", "status": "open",
   "detail": "The operator ruled share ISSUANCE (delivered: issue → cap table). A holder RESELLING issued shares needs its own schema. Desk deferred it; the exchange shares floor stays honest-empty until ruled. Operator may pull it into Wave 4.", "owner": "operator → lane 13"},
  {"q": "Cross-class federation handshake — return a graceful 4xx instead of 500?", "status": "open",
   "detail": "A genuine cross-class handshake surfaces the class-rule refusal as an uncaught 500 rather than a 409/422. Pre-existing; not a Wave-3 regression. Wave-4 hardening — operator's call whether to do it now.", "owner": "operator → lane 2"},
  {"q": "B2 remainder rule — compact-first vs strictly-lowest-population pairing?", "status": "open",
   "detail": "On real land-border adjacency, compactness drives which children pair (population only orients the walk head). Lane 1 shipped compact-first (matches intent). A soft confirm; the shipped default stands unless the operator flips it.", "owner": "operator → lane 1"},
  {"q": "Oversight live-console disclosure — does 'public to watch' extend to the LIVE console of in-progress proceedings against NAMED members?", "status": "open",
   "detail": "§10-1 makes government proceedings public. Open question: does that extend to a live console of an in-progress removal/discipline against a named member, or only the sealed public record after? Desk recommends keeping the live console gated; the public RECORD stays public.", "owner": "operator → lane 3"},
  {"q": "Orphan-surface deletions — remove unreferenced surfaces?", "status": "open",
   "detail": "e.g. Elections/CandidateProfile.vue (unreferenced) + a couple of orphan surface records. Deletion awaits the operator's word.", "owner": "operator → lane 15/6"},
  {"q": "Video library / multi-track player — build from scratch?", "status": "resolved",
   "detail": "NO from-scratch build. The operator ALREADY BUILT the multi-track player; the v3 mockups are based on it and the Cosmopolitan Coalition website uses the same player. Wave 4 = INTEGRATE it into shared/video-player.html. Reference: fleet-11 (video-translate pipeline) + the coalition site. Lane 5 wires it; lane 15's lessons consume it.", "owner": "operator (player exists) → lane 5 + fleet-11 ref"},
  # resolved-but-worth-recording
  {"q": "Founding-stake-on-registration — auto-equity when an org is founded?", "status": "resolved",
   "detail": "DEFERRED to Wave 4, structure-aware (a 100% founding stake is wrong for member-owned/nonprofit/partnership; only stock has shares). Desk-confirmed; lane 13 owns the Wave-4 build.", "owner": "operator (ruled defer) → lane 13"},
  {"q": "Setup order — account-first (mockup) or fork-first (ruling)?", "status": "resolved",
   "detail": "RULED FORK-FIRST (A3, 2026-07-05): join-or-start, THEN account. Mockup swapped; SetupController already fork-first. A stale mockup-vs-code note remains but the decision is settled.", "owner": "operator (ruled)"},
  {"q": "Oversight console — public or gated?", "status": "resolved",
   "detail": "RULED PUBLIC (A1): 'public if it's government'; the Template has no closed-session provision. The console read is public; write controls stay authenticated. Landed @4057b3c.", "owner": "operator (ruled)"},
]
