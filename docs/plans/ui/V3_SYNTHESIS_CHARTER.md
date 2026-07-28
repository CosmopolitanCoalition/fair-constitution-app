# V3 Synthesis Charter — make the app BE the v3 mockups

*Operator direction, 2026-07-28, given standing at card A-1 of the walk after finding W-1 (the
app never received the v3 shell). This charter is the brief for the deep investigation and the
synthesis plan that follows it. It supersedes any reading of "v3 wiring = done."*

---

## 1. THE STANDING ORDER

> **"Anything and everything currently built across the fleet should use the v3 mockups and this
> system."**

The v3 environment (`mockups/v3/` — 107 screens, one shell, one tour, `MANIFEST.md` as the
contract the production wiring reads first) is **the specification**. The app conforms to it —
not the other way round. Walk findings W-1/W-2 stand as the first two conformance defects.

## 2. THE SHELL SEMANTICS — operator's own words, 2026-07-28

The dock is not three links. Each element has a meaning:

### `Learn` — the per-screen teaching mechanism
- Its own dock menu, **and every Learn flyout is SPECIFIC to the page you are on** — it teaches
  that screen and the concepts that screen is trying to get across.
- Not a sidebar destination, not a separate "learn area": **the teaching mechanism travels with
  the screen.** (Raw material exists: lane 15's 70 surfaces × two halves of authored education
  copy, keyed per screen — that content is the flyout's payload.)

### `Demo` — the flyout that exists only in Demo mode
- **Demo mode and Dev mode are the same thing**, selected during the setup process. When the
  world was set up in that mode, this menu is active.
- It lets a person **step through the entire process**: assume the position of **any appropriate
  role or resident of a particular place**, control the clocks, and everything needed for
  pre-play testing.
- (Raw material exists: the persona switcher, `/dev/residency/grant`, the clock controls
  P1–P3, the chamber-cast P4, the four kits. **They are currently scattered across a dev bar
  and POST endpoints — the Demo flyout is their one home.**)

### `Tour` — a MODE, not pages
- **Tour is a mode the whole app can be walked in**, not a set of tour pages. Entering it walks
  the entire thing.
- (The 54-card worksheet and the 120-stop v3 tour are the two existing expressions; the mode is
  what unifies them.)

### `Menu` — vast, role-aware
- The main menu is large and contains items **needed for a given role** plus **common tools
  across users**. Role-aware composition, not a static list.

## 3. THE INVESTIGATION — scope

**Question: what is the codebase (front end AND back end) actually set up as, relative to v3?**

| Track | What to inventory | Against |
|---|---|---|
| FE shell | `AppShell.vue` / `AppShellV2.vue`, nav, dock absence (confirmed), Learn/Demo/Tour mechanics | v3 shell (`shell-v2`, the dock, per-page Learn drawer, Demo flyout, tour mode) |
| FE pages | every `resources/js/Pages/**` + route | the 107-screen manifest (`manifest.json`), per-area |
| BE props | what each controller ships | what each v3 screen's fixtures show it needs |
| BE capabilities | forms/services that exist with no screen (the punch-list inverse) | v3 screens that imply a capability |
| Contracts | `MANIFEST.md` §1 Live-Room config contract, reuse maps, component inventory | what production wiring actually reads |
| Divergences | **`mockups/v3/OPEN_QUESTIONS.md` — already tracks every divergence from as-built code. READ FIRST.** | |

**Prior art that must be consumed, not re-derived:** lane 6's `TOUR_ACT_COVERAGE.md` (21 wired vs
117 contract), `UI_PUNCHLIST.md` (8 deferred), `WALK_FINDINGS.md` (W-1, W-2),
`WHAT_IS_ACTUALLY_BUILT.md` (P1–P13), the v3 `MASTER_PLAN.md` and `MANIFEST.md` "current state"
section, and lane 15's per-surface education copy (the Learn payload).

## 4. THE OUTPUT

1. **A gap matrix** — per v3 screen: exists-in-app? / shell-conformant? / props available? /
   BE capability present? / Learn payload wired? One row each, 107 rows, no vibes.
2. **A synthesis plan** — the build order that turns the app into the v3 system: shell first
   (dock + Learn flyout + Demo flyout + Tour mode), then per-area page conformance, with the
   fleet-lane assignment for each slice.
3. **Both delivered before any further walk cards are attempted** — the walk resumes on the
   synthesized shell, per the standing order.

## 5. WHY THIS IS FIRST-ORDER

The walk stopped at its first screen because the app and the specification have diverged at the
shell — the thing every screen sits inside. Walking 54 cards against the wrong shell certifies
the wrong thing. **Synthesis precedes certification.**
