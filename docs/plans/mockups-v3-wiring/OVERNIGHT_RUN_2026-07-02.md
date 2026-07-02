# Overnight run — 2026-07-02 (Phases 0–4 built)

**One night, five phases, every gate green.** This is the morning walkthrough guide:
what was built, in review order, with the decisions that need you. Everything is on
`feature/v3-wiring`; nothing merged to main; Box A identity untouched; your districting
lane unblocked throughout.

## The ledger

| Phase | Commits | Suite after |
|---|---|---|
| 0 — finish the mockup contract | `c90b0b4` | (mockups only) |
| 1 — shell spine into the app | `bf52cff` | 681 / 15 known |
| 2 — restyle wave | `35d4cc9` | 690 / 15 |
| 3 — player funnel + messages + monolith retirements | `2ab9cb7`, `abab709` | 713 / 15 |
| 4 — peerage design note + operator console | `28ddcff`, `6c3ef60` | **720 / 15** |

Baseline was 677/15. Every added test green; zero new failures; the 15 are the known
live-mesh test-isolation debt (Phase 9). Assets built and served on the worktree box.

## Walkthrough — in this order

**A. The mockup contract (Phase 0)** — static server :8123
1. `mockups/v3/system/setup.html` — the three-way founding fork.
2. `mockups/v3/organizations/board-elections.html` (+ `economy/org-settings.html`) — the nomination window and its dial.
3. `mockups/v3/social/profile.html?who=diego-ramos&tab=candidacy` — the one profile; then click any name on the open ballot or count pages to feel the forwarding.
4. `mockups/v3/shared/live-room.html?variant=committee` — Join voice, toggle mic/cam, share your screen.
5. `mockups/v3/jurisdictions/district-mapper.html` — drag across the date line (seamless), try to zoom out (bounded), shrink to phone width (45dvh stack).

**B. The app (Phases 1–4)** — `http://localhost:8081`, log in as yourself
1. Anywhere: the v3 chrome — floating header, bottom Menu/Learn bar. Open Menu: the player tier + "All screens" sitemap with honest Planned flags.
2. Add `?step=1` to any wired page: the tour mode arms; wander — it follows you (21 stops). Exit ends it.
3. `/civic` — the today feed over real data (rows, calendar, on-the-record).
4. `/civic/record` — the one tabbed profile: Record, Representatives (real resolver), Achievements.
5. `/journeys` → open `An election, end to end` → mark all steps → the medal lands on your profile (achievements tab), audit-sealed.
6. `/civic/rooms` — the Messages inbox; start a group → the share step mints a real invite; open the invite link in a private window: the landing shows the live room preview → register → you land in the room, a member.
7. `/legislatures/earth-0-earth` — the overview (166 lines now); the Districts & maps door → the FULL mapper on its own page, everything intact. Old deep links (incl. the setup wizard handoff) 302 correctly.
8. `/system/clocks` and `/system/amendments` — the two dead links are real pages over the live registry + amendment ledger.
9. `/support/report` — the intake (files a real row, base62 reference).
10. `/operator` (operator sign-in) — the new console suite: home rollup, console meters, roles lifecycle over the real services, mesh, identity, versioning. Legacy Operations + Federation doors kept.

## ⚑ Decisions queued for you (PHASE_4_DESIGN_peerage.md §5)

1. **Retire the G3c read-write petition UI?** The new console omits the ladder (join-key
   mint = the peerage consent); the legacy Federation page keeps it until you rule.
2. **Achievements sync ingest policy** — proposed append-any-verified (medals are facts
   about play anywhere, idempotent on (user, journey)); alternative: home-server-only
   export. The FederationSyncService tail registration WAITS on this.
3. **Traveling-write receipt scope** — the minimal owner-only poll endpoint shipped;
   rejected-write async receipts + "watch your filing travel" UX proposed for Phase 6.

## What's deliberately NOT started

- **Phase 5 (districting deep integration)** — it runs WITH your lane; the mapper now
  lives on its own route ready for it, but the manual-draw tool and autoseed perf are
  your in-flight work. Say the word and the lane opens.
- **Phase 6 (rooms & events keystone)** — wants your flag answers (esp. flag 3) and
  fresh design attention; it is the campaign's biggest build.
- **Merges to main** — Phases 4/5 merge only at a pause you declare.

## Known small residue

- Screenshot tooling on this rig still times out (renderer quirk) — all verification is
  DOM/test-level; your eyes are the pixel check.
- The nav.js v1 sidebar still exists for v1-shell pages (KEEP-class); it retires when
  they do.
- `desktop.ini` noise and `.claude/settings.local.json` remain uncommitted by design.
