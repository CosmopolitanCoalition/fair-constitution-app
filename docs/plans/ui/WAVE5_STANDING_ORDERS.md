# Wave 5 standing orders — the finish line to a tested, playable game

*Prepared by the desk (lane 7) 2026-07-29. Goal: close the last 6 screens (→ 107/107) + 5 capabilities
(→ 55/56; mobile/Capacitor = Phase 6, out of alpha scope) → all-green re-gate → arm the box → the walk.
PREPARED for the operator's go/no-go — nothing dispatches until launch. Mirror of the rubric Fleet & Waves
tab (W5). Wave 4 concluded GREEN (authoritative suite 1343 / 0 / 3-skip).*

## The gap to full green
- **Screens 6 → 107/107:** messaging trio (groups-home · group-create · group-detail) · bill.html · board-elections · org-registry
- **Capabilities 5 → 55/56:** committees assignment · amendment workflow (R-C) · exec delegation→conversion · appointed/elected courts · full i18n. *(Mobile app = Phase 6.)*
- **Debt:** 0 open; only the agenda schema is worth pulling in for a complete keystone walk. The other 7 deferred are genuinely post-alpha.
- **Rulings to execute:** Q4a court-scaling reframe (doc, no schema).

## Standing orders per lane (W5)

### Lane 6 — front-end finish (the biggest screen lever)
1. **Messaging trio → built** (groups-home · group-create · group-detail): ONE atomic commit over the shared PrivateRoom files (splitting = a merge conflict); v3 bubble chrome + last-message previews; unread / live-now / DM-vs-group persisted-kind stay HONEST-EMPTY (no source table — never fake).
2. **bill.html → built** on the CONSTITUTIONAL path: a bill as a conversation via motion kind='amendment' + chamber vote (NOT the mockup's per-party accept = an Art. V §3 violation the code already rejects); comments ride the 8 existing bill-bound subforums; summary honest-empty; coordinate the HallsController subforum_id passthrough with lane 3.
3. **Atlas a11y review pass** (findings → lane 4; not a rebuild).

### Lane 13 — org screens → 107/107
1. **board-elections → built** (org + governmental board elections surface; verify tables vs information_schema first — mockups carry fixture names).
2. **org-registry → built** (F-IND-012 registration surface; founder-stake path already lands here for stock orgs).
3. *(optional)* **CHECK(balance>=0)** defense-in-depth if it takes the migration slot — the app-layer row lock already closes the overdraft hole.

### Lane 3 — back-end capability closes (verify end-to-end, then pin)
1. **Committees — rank-ordered, faction-independent assignment + hearings → working** (F-SPK-005 run + keystone hearing path).
2. **Amendment workflow (R-C) → working** — propose → supermajority chamber vote → APPLY a constitutional setting through the real act pipeline; system/amendments is the front door; pin the loop.
3. **Executive delegation → conversion to directly-elected → working** (dual-supermajority conversion, Art. III).
4. **Appointed/elected courts (equal-per-constituent) → working** (rides lane 4's courtTiers reframe; verify appointed default + elected conversion).
5. **Agenda per-item schema — build** (the deferred keystone debt; flag the desk for the migration slot).

### Lane 4 — execute the Q4a ruling
1. **Reframe courtTiers = a jurisdiction's tree-DEPTH** + wire into provisioning. Operator ruling A: Live Rooms / public squares / chats are INFRASTRUCTURE (a courthouse has many court rooms), NOT a court-as-jurisdiction. NEVER weaken the uniqueness constraints (`judiciaries_jurisdiction_live_uq`, `social_spaces_jur_type_unique`). Doc amendment, no schema, nothing built moves. This unblocks lane 3's courts capability.

### Lane 5 — i18n finish
1. **fr/pt shell chrome** — 6-locale page bodies already render. OPERATOR'S CALL: (a) accept English chrome for the alpha playtest [desk rec — cosmetic + rail-held] and mark i18n playtest-adequate, or (b) a reader/stronger-model pass. Never ship raw NLLB nav.

### Lane 2 — steward
1. **Authoritative RE-GATE** — after the build lands, run the FULL suite (SUITE TOKEN + freeze + storage-chown) in a quiet window → confirm ALL-GREEN before the walk. Triage: own-the-world → audit-lock → real defect.
2. *(deferred, post-alpha)* per-lane LIVE_PG_DATABASE to retire the freeze.

### Lane 1 — demo readiness (operator triggers)
1. **Fire the live per-clump Niue general** (approved + fielding-pinned 80b5e3a) — a live Type B election for the walk.
2. **Game-box mass pass** over ~9,708 flagged chambers — operator-coordinated (pull commits to the game box, ETL-chunked).

### Lane 15 — arm the game box (operator trigger)
1. **education:seed** — arm the training gate on the game box (pre-train arming is built). Do NOT seed the dev box. Gates the walk.

### Lane 14 — HELD (operator).

## The sequence to a tested, playable game
1. **Build** — L6 (4 screens) + L13 (2 screens) + L3 (4 caps + agenda) + L4 (court reframe) + L5 (i18n) → 107/107 screens, 55/56 caps.
2. **Re-gate** — L2 authoritative full suite in a quiet window → all-green.
3. **Arm** — education:seed + the live Niue election + demo seeds → the playable box.
4. **Walk** — operator-present, all-green → playtest.

## Launch protocol (same as Wave 4)
Pre-compact HOLD orders sent to lanes 1/2/3/4/5/6/13/15 (14 held) — each holds its W5 context, disposition
HOLD, do NOT start. THE GO is the desk's fresh W5 **ACTION** order (NOT the operator compaction). STEP 0 on the
GO: `git pull` to HEAD ≥ the latest desk commit. Standing law carries: commit-law v2 / private-index CAS +
index-clear; migration slot one-lane-at-a-time (flag the desk); four-way reports; verify against
information_schema before wiring a mockup port; honest-empty over fake data; SETTLED rulings (Q4a, advocate,
government-public, orphans, seating law) — never re-ask.
