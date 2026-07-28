# Walk findings — the operator's walk, digital log

*Started 2026-07-28, dev box `:8082`, walking beside the 54-card worksheet. His paper carries
pass/fail; this file carries what we saw, for the digest and the fix round. Screenshots at equal
width, claims checked against `resources/js` before being written down.*

---

## W-1 · The app never received the v3 SHELL

**Seen at:** card A-1, first screen of the walk — before any card was even attempted.

| | v3 mockup (`mockups/v3/civic/today.html`) | App (`:8082`, `AppShellV2`) |
|---|---|---|
| Navigation | **Persistent bottom dock — `Menu · Learn · Demo`** — three collapsible panels rising from a fixed bar | Top-left **hamburger → sidebar list** (HOME / ELECTIONS / PETITIONS / COMMONS…) |
| Jurisdiction chain | **Cosmic-address chips in the top bar** — `… · Solar System · Earth · United States · New York · New York County`, clickable | Not visible signed-out. (`JurisdictionSwitcher` exists in both shells — present in code, absent from this first impression) |
| Identity | User chip **with role label** — "Amara Okafor · *voter*" | Log in / Register (signed-out; recheck signed-in) |
| Learn | **First-class dock panel** — welcome video (1:36), audio + caption language pickers, "Link audio & subtitles" | A sidebar item |
| Demo/tour | **Dock panel of its own** | Dev-controls bar only (which is the toolbox, not the tour) |

**Verified, not inferred:** `grep -rliE "bottom-dock|BottomDock|dock-nav|DockNav|bottomnav" resources/js/` → empty. The dock shell **does not exist in the codebase**. Two shells exist (`AppShell`, `AppShellV2`), both top-bar/hamburger.

**Why this survived to the walk:** lane 6's original charter *was* this side-by-side parity pass
(mockups server → 1280 + 375 → `V3_PARITY_PUNCHLIST.md`). It was parked for the walk, then the
pivot turned lane 6's effort into the playtest worksheet, act map and economy pages — **the parity
pass never ran.** The worksheet tests function ("does this exist, can it do a thing"), so this is
exactly the class of finding it structurally cannot catch and the parity pass catches in its
first minute.

**Class:** shell / presentation · **Scope:** every page in the app · **Punch list:** added as P13.

---

## W-2 · Signed-out home is an empty room

**Seen at:** same screen.

The app's signed-out home: brand bar, hamburger, then a **viewport-height blank region** with a
lone centered hero — *"Fair Constitution App"* — and the instance footer. The v3 home is the
**"Today" feed** (sessions, meetings, votes, each with entity chips and an Open→) over the
**"Your rights here"** panel (*"Voting unlocked · Candidacy unlocked · Protected — built into the
rules; no vote or official can take these away"*).

Signed-out vs signed-in is not a fair content comparison — recheck after A-1 — but the signed-out
state is its own finding: **a stranger arriving from a QR code lands on an empty dark page.**
Poland is QR codes.

**Class:** content / first-impression · **Status:** recheck signed-in before sizing the gap.
