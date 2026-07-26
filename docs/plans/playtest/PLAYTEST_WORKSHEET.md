# The Playtest Worksheet

**Print this. Keep it beside you. Write in the notes column.**
Lane 6 · living document — v1, 2026-07-26 · box: dev (`fcd`) at http://localhost:8082

---

## The one rule this whole sheet is built on

**A tick is not a pass. A tick is somebody saying they did a thing.**

The app has a "journeys" feature where you mark steps done as you go. Those marks are
self-reported — you can tick *"I voted"* without voting. They record intent, not proof. Useful for
teaching, worthless for testing.

**So no test on this sheet ever passes because a step got marked done.** Every test below passes on
a *verified act* — a row in the record, a seated member, a certified count, an entry in the audit
chain. Something the app did, not something someone claimed.

Your own standard for this walk was: **"Does this exist? Can this do a thing?"** That is what each
card checks. The tick is the thing that *looks* like an answer and isn't.

*(This matches how the achievement catalog already separates its entries: 112 are "verified" and
name a proving source; the 13 journey arcs are explicitly labelled tour ticks. Every pass condition
here is drawn from the verified set.)*

---

## How to read a card

Each test has a **name**, one plain line on **what it is**, the **role** you need to be in, any
**clock event** that must fire first, the **ordered steps** with the screen each happens on, and a
single **pass condition**. Then a box and a blank line for you.

Mark **blocked** — not fail — when you can't even attempt it. Fail means you tried and the app did
the wrong thing. That difference is what makes this sheet useful next week.

---

## Two controls you'll need constantly

**To become someone else** — the dev bar. `/dev/users` lists everyone; impersonate from there
(`/dev/login-as` and `/dev/impersonate/{user}` are the same control). `/dev/impersonate/stop`
returns you to yourself. There are also four role kits — `/dev/electoral-kit`,
`/dev/legislature-kit`, `/dev/judiciary-kit`, `/dev/executive-kit` — which set up the state a role
needs. Dev mode is on for this box (sandbox game mode).

**To move time** — lane 3 is building the time control right now
(`docs/plans/playtest/DEV_TIME_AND_ROLE_CONTROLS.md`). Steps that need it are marked
**⏱ needs time control**. Until it lands, those steps are *blocked*, not failed.

---

## Sections — take them in batches

| | Section | Tests | Can you run it today? |
|---|---|---|---|
| **A** | Getting in — becoming a person with rights | 5 | ✅ Yes |
| **B** | An election, end to end | 7 | ⏱ Mostly — two steps need the time control |
| **C** | The legislature at work | 8 | ⚠ Blocked — see the note on section C |
| D | The courts | — | drafting next |
| E | Organizations & work | — | drafting next |
| F | Voice — square, petitions, messages | — | drafting next |
| G | The record & the clock | — | drafting next |
| H | Places & maps (use the **game box** for this one) | — | drafting next |
| I | The economy | — | ⛔ no screens yet — lane 6 building |

**Start with A. It takes about twenty minutes and everything else depends on it.**

---

# SECTION A — Getting in

*Becoming a person the constitution recognises. Nothing else in the app works until this does.*

---

### A-1 · Create an account
**What it is.** The front door. Making a record for yourself.
**Role:** nobody yet · **Clock:** none · **Needs first:** nothing

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open the app | `/` | The login page |
| 2 | Choose "Create an account" | `/login` | A registration form |
| 3 | Fill it in and submit | `/register` | You land signed in, not back at login |

**PASSES IF** — you are signed in and your name appears in the app chrome.
*Underlying proof: a `users` row, and an audit entry for form `F-IND-001`.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### A-2 · Say where you live
**What it is.** Declaring your residency. This is the only requirement the constitution places on
voting or standing for office — there is nothing else to qualify for.
**Role:** yourself · **Clock:** none to declare · **Needs first:** A-1

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open your residency page | `/civic/residency` | A place to declare where you live |
| 2 | Declare a residency — pick one of the nine castelli | `/civic/residency` | Your declaration listed, pending |
| 3 | Note which place you picked → | | write it in Notes; later tests need it |

**PASSES IF** — your declaration is listed against a named jurisdiction.
*Underlying proof: a declaration row; audit entry for `F-IND-003`.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### A-3 · Residency confirmed — your rights switch on
**What it is.** The moment the app agrees you live there. **This is the single most important
event in the application** — it is the exact point at which your constitutional rights attach.
**Role:** yourself · **Clock:** the residency confirmation sweep (CLK-05) · **Needs first:** A-2

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Either wait for the sweep, or grant it directly in dev | `/civic/residency` · `/dev/residency/grant` | Status moves to confirmed |
| 2 | Open your record | `/civic/record` | Your jurisdiction listed, marked confirmed |

**PASSES IF** — your record shows the jurisdiction as an **active, confirmed** residency.
*Underlying proof: `residency_confirmations.is_active = true` for you. The catalog calls this
"the exact moment Art. I rights attach" — and deliberately reads the row, never the claim.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### A-4 · You appear at every level, neighbourhood to planet
**What it is.** One declaration should associate you with every jurisdiction above you — your
castello, San Marino, and Earth — without you doing anything more.
**Role:** yourself · **Clock:** none · **Needs first:** A-3

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open your record | `/civic/record` | More than one place listed |
| 2 | Check your representatives | `/civic/record` (Representatives) | Reps from more than one level, most local first |

**PASSES IF** — you are associated with **at least three** levels from one declaration.
*This is the ancestor sweep. If only your castello appears, that is a real failure — note the count.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### A-5 · Report something
**What it is.** The simplest complete loop in the whole app — file a report, get a record back.
Worth doing early because if *this* is broken, something basic is wrong.
**Role:** yourself · **Clock:** none · **Needs first:** A-1

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open the report form | `/support/report` | A form |
| 2 | File anything | `/support/report` | A confirmation with a reference you can quote |

**PASSES IF** — you get back a reference for the thing you filed.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

**End of Section A.** If A-3 failed, stop — nothing below will work, and that is the bug to fix first.

---

# SECTION B — An election, end to end

*The flagship. Stand for office, vote, watch the count, take the seat. A machine has done this on
this box already — 200 voters, 120 candidates, 4 races certified. **What has never been tested is a
person doing it through the screens.***

**Before you start:** two steps need the time control, because the ballots open on real dates. One
race is already certified (good for B-6/B-7), the other opens later. Marked below.

---

### B-1 · Find a race
**What it is.** Seeing what's being contested where you live.
**Role:** any confirmed resident · **Clock:** none · **Needs first:** A-3

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open elections | `/elections` | At least one election listed |
| 2 | Open one | `/elections/{id}` | Its phase, its clock, and its seats |

**PASSES IF** — you can tell, from the screen alone, what stage the race is at and when the next
thing happens. *If you can't tell, that's a fail — write down what was missing.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### B-2 · Stand for office
**What it is.** Putting your name forward. Living there is the entire qualification.
**Role:** any confirmed resident · **Clock:** the candidacy window must be open · **Needs first:** A-3

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open the candidacy page | `/elections/candidacy` | A race you're eligible for |
| 2 | File your candidacy | `/elections/{id}/candidacy` | You listed as a candidate |
| 3 | Try it as someone with **no** confirmed residency (dev bar) | `/dev/users` → impersonate | **You should be refused** |

**PASSES IF** — you appear as a candidate, **and** step 3 is refused.
*Step 3 is the real test. The right to stand is absolute for residents and unavailable to
non-residents; if a non-resident can file, that is a constitutional failure, not a UI bug.*
*Underlying proof: a `candidacies` row; audit entry for `F-IND-011`.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### B-3 · The approval round ⏱ needs time control
**What it is.** The first round — you approve everyone you'd be happy to see on the ballot. Not a
choice of one; a signal about many.
**Role:** any confirmed resident · **Clock:** approval phase must be open · **Needs first:** B-1

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Move time to inside the approval window | time control | The race shows as open for approvals |
| 2 | Open the approval ballot | `/elections/open-ballot` | The candidates, with approve controls |
| 3 | Approve several, then remove one | `/elections/{id}/approvals` | Your approvals update both ways |

**PASSES IF** — your approvals are recorded and can be withdrawn again.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### B-4 · The ranked ballot ⏱ needs time control
**What it is.** The real vote. You rank people in order. Seats go out in fair shares, so a vote for
someone who can't win transfers instead of being wasted.
**Role:** any confirmed resident · **Clock:** ranked phase must be open · **Needs first:** B-3

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Move time into the ranked window | time control | The race opens for ranking |
| 2 | Rank your choices and submit | `/elections/ranked-ballot` | A confirmation, and a receipt |
| 3 | Try to vote a second time | same | **You should be refused** |

**PASSES IF** — one ballot is accepted, a second is refused, and **the screen never shows you how
anyone voted** — including you.
*Underlying proof: a `ballot_envelopes` row of kind `ranked`. Secrecy is cryptographic here: your
identity is separated from your ballot by design, so "I can't see my own vote" is correct behaviour,
not a missing feature.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### B-5 · Check your own ballot by receipt
**What it is.** Proving your vote was counted without revealing what it was.
**Role:** yourself · **Clock:** after B-4 · **Needs first:** B-4

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Use the receipt from B-4 | `/elections/{id}` | Confirmation it's in the count |

**PASSES IF** — the receipt confirms inclusion and still doesn't reveal the vote.
*Note: there is an open security question on this receipt — it is flagged in the code itself as a
possible vote-selling channel awaiting review. Worth forming your own view while you're here.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### B-6 · Watch the count
**What it is.** The count, round by round — votes moving between candidates until every seat fills.
This is the part no other voting system can show you, and it works on this box today.
**Role:** anyone · **Clock:** none — use the already-certified race · **Needs first:** nothing

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open results for the certified race | `/elections/results` | Round-by-round counts |
| 2 | Follow one candidate's votes across rounds | same | Where their surplus went, and to whom |
| 3 | Download the count | `/elections/{id}/results.csv` | A file that matches the screen |

**PASSES IF** — you can follow a single vote's journey through the rounds and the file agrees with
the screen. *If the rounds are shown but you can't tell what happened, that's a fail — it's the
whole point of the screen.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### B-7 · The winners take their seats
**What it is.** Certification, then the seats actually filling. The end of the journey.
**Role:** anyone to view · **Clock:** certification · **Needs first:** B-6

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open the certified race | `/elections/{id}` | Marked certified |
| 2 | Open the legislature it fills | `/legislatures/smr-1-san-marino` | The winners seated as members |
| 3 | Count the seats against the seats contested | same | They match |

**PASSES IF** — every seat contested is filled by the person the count produced.
*Underlying proof: `legislature_members` rows tracing to that election. **Expect 31 seated, not
59** — see the Section C note; that gap is a known issue, not a fault of this test.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

**End of Section B.**

---

# SECTION C — The legislature at work

## ⚠ Read this before starting Section C

**Most of this section cannot pass yet, and it is one problem, not eight.**

San Marino's legislature has two chambers. The first (32 seats, by population) is seated — 31 of
them. **The second chamber, which represents the nine castelli equally, has 27 seats and none of
them are filled.**

The constitution requires **both** chambers to agree before an act passes. With an empty second
chamber every such act fails automatically — and that list is: **passing a bill, creating a court,
creating an executive, and creating a committee.** This is the app behaving correctly; the seating
is what's missing, not the rule.

**What this means for your walk:** tests C-1 through C-5 will fail at the vote every time, for the
same reason, no matter what you do. Mark them **blocked**, not failed. They become testable the
moment the second chamber is seated.

*(This is the same wall that stopped the automated executive/organisation setup at its first step.)*

---

*Section C cards, plus Sections D–H, are drafted in the next pass. C is written but held back from
this print run because you would only be filling in "blocked" twenty times — I'd rather give you A
and B, which you can actually run tonight, and hand you C when it can pass.*

---

## Running notes — anything, any section

_____________________________________________________________________________________

_____________________________________________________________________________________

_____________________________________________________________________________________

_____________________________________________________________________________________

---

## Changelog
- **v1** (2026-07-26) — Frame, the one rule, the two controls, Sections A and B in full;
  Section C blocked with its single cause explained. D–I outlined.
