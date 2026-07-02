# MASTER PLAN — from the v3 contract to a testable World of Statecraft

**Mission: phase-by-phase write/rewrite until the app is ready for SINGLE-BOX, CLIENT, and
MULTIBOX testing on all things.** This plan supersedes the phase list in `WIRING_PLAN.md`
(whose gap matrix, settled decision slate, and operating rules remain the inputs) and folds
in the operator's tour walkthrough feedback (2026-07-02).

**Where we stand (2026-07-02):**
- Backend: institutions Phases 0–5 + F/G/K live; suite baseline 677 pass / 15 known
  live-mesh-coupled failures (`WIRING_PLAN.md` records it; the 15 are Phase-9 debt).
- Design contract: `mockups/v3` post-simplification (ca34fc0 + tweak round): 108 screens,
  invite-first arrival, two-tier nav, 117-stop tour-as-a-mode, settled models in the copy.
- Operator lanes running in parallel: districting/autoseed (feeds Phase 5), optional Box B
  resume (Gate-2 state preserved).

**Operator tour feedback register (2026-07-02):**
| # | Feedback | Disposition |
|---|---|---|
| 1 | "Party" evokes partisanship → say **direct & group messages** | APPLIED in mockups (display strings; machine keys unchanged) |
| 2 | Tour is a **mode**, not pages — keep my place / follow me to any stop | APPLIED in shell (session-persistent; follows navigation; Exit control) |
| 3 | Step 21: one person = **one profile** — candidate content embeds in the user profile | Phase 0a |
| 4 | Step 23+: live rooms must carry the **real Matrix/LiveKit call surface** (join call, cams, screenshare, controls) like the wired invite-flow UI | Phase 0b (mockups) → Phase 6 (wired) |
| 5 | Step 63: **open nomination/approval windows** for org elections & appointments (configurable days before ranking) | Phase 0d (mockup) → Phase 7 (real setting) |
| 6 | Steps 82–83: districting module clunky as integrated — **mapper full-screen**, map squished/not mobile; sidebar look is right; mimic the setup wizard's elegant map containment | Phase 0c (mockup look) → Phase 5 (integration) |
| 7 | Step 87: **restoration is also a founding path** (chose not to join a federation / nodes fell) | Cross-link APPLIED; setup fork copy in Phase 0e |

---

## Phase 0 — Finish the contract (mockups only, small)
The mockups are the design contract; where the operator changed the design, fix the
contract before wiring it.
- **0a One profile.** Fold `electoral/candidate-profile.html` into `social/profile.html`
  as a Candidacy tab (`?tab=candidacy`, shown when the person is a candidate: approvals
  meter, endorsements, statement, state strip). candidate-profile becomes a redirect;
  tour/nav/manifest updated.
- **0b The call surface.** All 8 live-room variants gain the real call UI: join/leave
  call, mic/cam/screenshare toggles, participant video tiles (cams + screenshare view),
  device pickers — mirroring the WIRED invite-lane voice components
  (`resources/js/…` voice panel, device pickers + screenshare shipped ad74f5f). Mockup
  only; states simulated.
- **0c The mapper look.** Rework `jurisdictions/district-mapper.html` +
  `jurisdiction-browser.html`: full-bleed map that takes all available space inside a thin
  nav wrapper (mimic the setup wizard's built containment: bounded zoom-out, seamless
  infinite horizontal scroll), keep the existing mapper sidebar look/functions, mobile
  posture. Improve freely; mimic what's built.
- **0d Nomination windows.** `board-elections.html` (+ org-settings) show the open
  nomination/approval window: N configurable days preceding ranking, set by the
  appropriate role. No constitutional requirement — an org/appointment-level setting.
- **0e The founding fork.** `system/setup.html` states the three-way fork plainly:
  **start fresh · restore from records · join an existing world** (restoration.html
  already cross-links back).
- Exit: operator walkthrough of 0a–0e.

## Phase 1 — Shell spine into the app
- Port the v2.css component/shell layer onto the app (tokens already shared); AppShell /
  Header / two-tier player nav; wordmark → Home.
- **Tour composable with tour-as-a-mode semantics** (session-persistent, follows route
  changes to any registered stop); Learn drawer (+ "where this fits"); Vue equivalents of
  plainCodes/plannedBanner/plainState.
- Registry unification: one machine source shared by nav, coverage, and the tour
  (manifest ↔ app surface registry).
- **Pretty URLs foundation**: slugs everywhere a slug will do, sqids/base62 short ids
  otherwise; shareable public URLs (public-proceeding pattern already exists).
- `/support/report` intake wired to a real table (routing per category).
- Pilots: Elections/Index + one System page on the new shell.
- Exit: pilots live in the running app; suite green (baseline + no new); walkthrough.

## Phase 2 — Restyle wave
- ~36 served-backend pages re-skinned to the contract (electoral, legislature, executive,
  judiciary, organizations, jurisdictions, system, civic ops surfaces).
- Backend-ready-but-UI-missing pages built: clocks, amendments.
- **Profile unification lands in the app** (0a's contract): one person page — profile +
  candidacy + achievements(+empty state) + representatives tabs.
- Exit: every wired_current page on the new shell; walkthrough.

## Phase 3 — The player layer (the first-visit funnel)
- `civic/join` wired to the REAL person-invite join-key primitive + registration
  `intended()` continuation (both exist since 2026-06-29).
- Home (today): live feed from real data (elections, sessions, petitions, calendar).
- **Journeys engine**: durable per-user completion, public-profile achievements
  (public-record-like, syncs, zero power), stipend payout hook (dormant until Phase 8),
  soft-gates nudging first live use.
- **Messages**: direct & group messages on the Matrix room primitive (operator
  terminology).
- Monolith retirements: Legislature/Show (5.2k) → mapper extraction + legislature-home;
  Jurisdictions/Show → browser.
- Exit: invite → room → Home → first journey completes E2E on one box.

## Phase 4 — Operator console + the peerage collapse
- `operator/*` suite wired over existing mesh services (wrap, never modify);
  Federation.vue stays flag-routable until the multibox campaign proves parity.
- **Design note THEN implementation**: map one-process-to-peerage onto the
  mirror/write-guard/authority model — G3c read-write petitions become vestigial;
  verified forwarded writes are the norm; "authority" = where a jurisdiction's home copy
  lives. (Operator-settled; touches constitutional-adjacent plumbing — explicit doc first.)
- Exit: every console function usable through the new UI on a live box.

## Phase 5 — Districting deep integration (operator-flagged: "needs a special look")
- Full-screen mapper embed using the setup wizard's containment pattern; mobile posture;
  sidebar functionality preserved.
- Manual district drawing tool (in progress) landed; childless-leaf-giant raster split as
  needed; **autoseeder optimized at 951k**; judicial districts layer.
- Runs WITH the operator's districting lane; goal: auto-district-map ALL jurisdictions —
  base-level full-scale legislative AND judicial districts + institutions.
- Exit: full-scale districts + institutions seeded and browsable in the new mapper.

## Phase 6 — Rooms & events (the keystone)
- Polling-first event layer — browser↔own-node UI state ONLY; node↔node stays CLK-20
  FF&C; Matrix/LiveKit native sub-second transports untouched.
- Live Civic Room wired: Matrix timeline + the REAL LiveKit call surface (0b's contract —
  join call, tiles, screenshare, device pickers, reusing the shipped voice components);
  chair controls; engine-snapshot vote tile; testimony sealing (F-SOC-002).
- **Rooms grounded on Matrix Spaces**: standing rooms bound to institutions /
  jurisdictions / organizations / individuals; nesting & categories (Discord-shaped);
  room management surfaces; cross-node reachability deferred to Matrix/LiveKit.
- session-console / committee-detail fuse into the room per the contract.
- Exit: a committee hearing runs end-to-end in the room on one box — agenda, floor, vote,
  sealed testimony, live voice.

## Phase 7 — Long tail
- Atlas wired to real metrics; learn/lessons/guides + video player; translation
  interface; bill redlines (negotiate); public records + audit chain browse; term-sync +
  clocks pages; **open nomination windows** (0d) as real org/appointment settings.
- Exit: coverage instrument green against the app registry.

## Phase 8 — Economy (design-gated, LAST build phase)
- The **design round first** (operator seed input recorded in WIRING_PLAN §8; the
  currency-distribution auto-management question gets its own round).
- Then per outcome: units as operator-default constitutional settings; UBI base +
  role-holder/operator bonus per interval; wallets (SYNCED, reader-private); open market
  (offers/requests, fungible + non-fungible); agreements; stipend (journeys' one-time
  bonus goes live); treasury; org economics; people-operated tax/inflation levers.
  Forms F-LEG-037…040 / F-IND-018…023 / F-TRE-001…004 / F-ORG-008 registered here.
- Exit: economy suite green; a journey completion pays its bonus.

## Phase 9 — Hardening & cleanup
- Constitutional cleanup pass; the 15 live-mesh test-isolation failures fixed; i18n sweep
  of all new strings; **late-production credential pass** (browser device key off
  localStorage → non-extractable WebCrypto/IndexedDB; trustProxies + staging-cert
  tightening) — the pre-launch gate the operator flagged 2026-06-28.
- Exit: suite fully green (zero known failures), credential audit closed.

## Phase 10 — The testing campaigns (put to bed)
- **10a Single-box:** GRAND RESET — all containers destroyed; Box A stood up from main
  via deploy.sh (allowed now: fresh identities correct); full-scale demo data; the
  117-stop tour as the operator's acceptance checklist; constitutional suite green.
- **10b Client:** browser + phone clients; the invite funnel from a phone; voice/video
  cross-device over TLS (proven rig pattern); GPS residency pings; Capacitor posture.
- **10c Multibox:** Box B campaign gates 0–5 rerun on the new app — discover/join,
  seed+drain, read replication, cross-box role grant + traveling writes, Plane B S2S +
  cross-node rooms/voice/testimony — then a Box B full demo.
- Exit: all three testing modes pass on all things. Then: the public-facing step.

---

**Cross-cutting rules (unchanged):** work on `feature/v3-wiring`; merges to main only at
operator checkpoints on green suite + walkthrough; operator hotfixes ride main directly;
migrations additive-only; mesh-replicated tables reviewed pre-merge; PROTECTED
constitutional files get constitutional review; KEEP-class surfaces (Setup/*, JoinHost,
OperatorLogin, Operator/Operations.vue, Auth, Invite, dev kits) untouched until their
phase; when a design changes, fix the mockup contract first, then wire it.

**Sequence notes:** Phases 0–2 are strictly ordered; 3 and 4 can interleave; 5 tracks the
operator's districting lane and can start any time after 2; 6 needs 3's player layer; 7
follows 6; 8 is gated on its design round (can run during 6–7); 9 before 10; the grand
reset IS 10a's first step.
