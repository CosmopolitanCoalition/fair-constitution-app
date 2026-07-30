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
  ],
  # W4 gate: full suite 1343/0/3-skip (quiet window), GREEN. W5 = close the last 6 screens + 5 caps → all-green → arm → walk.
  "lanes": [
    {"id": "1", "name": "GeoData & District Maps", "status": "held", "items": [
      {"wave": "W5", "label": "Fire the live per-clump Niue general (walk demo)", "status": "held", "note": "approved + fielding-pinned (80b5e3a); a live Type B election electing 10 seats across 5 panels for the operator's walk — his trigger"},
      {"wave": "W5", "label": "Game-box mass pass over ~9,708 flagged chambers", "status": "held", "note": "operator-coordinated: pull L1's commits to the game box, run ETL-chunked; the stale-grouping guard protects re-seeds"},
      {"label": "Type B race fix — SEATING (per-clump) + per-child + hardening", "status": "done", "note": "c500a1f + c96e757 + 1ffde5b; pooled shape fully retired, at_large = type_a-only"},
      {"label": "Niue cleared LIVE + reshape confirmed", "status": "done", "note": "5 panels/10 seats, both chambers elect; 2 stale elections voided, next mint per-clump"},
      {"label": "Adversarial pass + fixture reds + CHECK + Geodata red", "status": "done", "note": "5f2293b (2 defects) · a32bbc8 · 220100 · 06d9545"},
    ]},
    {"id": "2", "name": "Cloud Launch – Multibox", "status": "next", "items": [
      {"wave": "W5", "label": "Authoritative RE-GATE — full suite, quiet window, after the build lands", "status": "next", "note": "SUITE TOKEN + freeze + storage-chown; confirm ALL-GREEN before the walk. Your triage: own-the-world → audit-lock → real defect"},
      {"wave": "W5", "label": "Per-lane LIVE_PG_DATABASE (retire the freeze)", "status": "deferred", "note": "post-alpha infra; a config change (LivePgConnection already takes a conn name), not a refactor"},
      {"label": "Operator/system partials + handshake 409/422 + class-isolation fix", "status": "done", "note": "e702a43 + 1c6b6d9; a demo peer could enter a production mesh (game_mode stripped pre-check)"},
      {"label": "Demo-mesh coordinator + federation reconcile + Matrix red", "status": "done", "note": "32360c0 + 56f137a"},
      {"label": "SUITE STEWARD — the 1343/0 green gate + audit-lock diagnosis", "status": "done", "note": "validated the SUITE-TOKEN / quiet-window procedure"},
    ]},
    {"id": "3", "name": "Institution Scaling", "status": "next", "items": [
      {"wave": "W5", "label": "Committees — rank-ordered assignment + hearings → working", "status": "next", "note": "verify/complete the F-SPK-005 assignment run + the keystone hearing path end-to-end; pin it (currently the capability is partial)"},
      {"wave": "W5", "label": "Amendment workflow (R-C) end-to-end → working", "status": "next", "note": "propose → supermajority chamber vote → APPLY a constitutional setting through the real act pipeline; system/amendments is the front door; pin the loop"},
      {"wave": "W5", "label": "Executive delegation → conversion to directly-elected → working", "status": "next", "note": "the dual-supermajority conversion path (Art. III); verify + pin end-to-end"},
      {"wave": "W5", "label": "Appointed/elected courts (equal-per-constituent) → working", "status": "next", "note": "rides L4's courtTiers=tree-depth reframe; verify the appointed default + the elected conversion; pin"},
      {"wave": "W5", "label": "Agenda per-item schema — build (keystone)", "status": "next", "note": "the deferred keystone debt; flag the desk for the migration slot; completes the Live Civic Room's agenda"},
      {"label": "Type B COUNTING (per-clump + per-child) + keystone exit-walk + Niue void", "status": "done", "note": "6f85d322 + 8ec50402 + b9ea6d6 + 8bcb3ee; ① green on every axis"},
      {"label": "Service formula + electoral partials + liveAggregate + oversight + pollers", "status": "done", "note": "37b7a64 · f333eba · 4a118e74 · 4057b3c · 2/4 pollers"},
    ]},
    {"id": "4", "name": "Simulated World Engine", "status": "next", "items": [
      {"wave": "W5", "label": "Q4a — reframe courtTiers = jurisdiction tree-DEPTH (doc + provisioning)", "status": "next", "note": "operator ruling A: Live Rooms/squares are INFRASTRUCTURE (a courthouse has many court rooms), NOT a court-as-jurisdiction. NEVER weaken the uniqueness constraints. No schema. Unblocks L3's courts capability"},
      {"label": "Atlas (front door) + service-formula provisioning + growth dial AUTONOMOUS", "status": "done", "note": "8d03a19/3b9ff99 · 331271b · f9161f5/74603d4/2ad8b1b; SimGovernanceWiringTest 4/4"},
      {"label": "R-A un-flag + per-child/per-clump sim fielding (+ 2 real defects fixed)", "status": "done", "note": "64c2a27 fielding + 3976919 rosterSize + 80b5e3a per-clump end-to-end fielding pin"},
    ]},
    {"id": "5", "name": "Translation Scaling", "status": "next", "items": [
      {"wave": "W5", "label": "i18n finish — fr/pt shell chrome", "status": "next", "note": "6-locale page bodies already render. OPERATOR'S CALL: (a) accept English chrome for the alpha playtest [desk rec — the chrome polish is cosmetic + rail-held] and mark i18n playtest-adequate, or (b) a reader/stronger-model pass on the chrome. Never ship raw NLLB nav"},
      {"label": "Video player /videos + flows extraction + zh-Hans + translation-home", "status": "done", "note": "2fea981 (app-ported, 77-lang); adversarial review fe5ad51"},
    ]},
    {"id": "6", "name": "UI Design + A11y Audit", "status": "next", "items": [
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
