# The Playtest Worksheet

**Print this. Keep it beside you. Write in the notes column.**
Lane 6 · living document — v4, 2026-07-26 · box: dev (`fcd`) at http://localhost:8082

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

*(This matches how the achievement catalog already separates its entries: 127 are “verified” and
name a proving source; the 14 journey arcs are explicitly labelled tour ticks. Every pass condition
here is drawn from the verified set.)*

---

## How to read a card

Each test has a **name**, one plain line on **what it is**, the **role** you need to be in, any
**clock event** that must fire first, the **ordered steps** with the screen each happens on, and a
single **pass condition**. Then a box and a blank line for you.

Mark **blocked** — not fail — when you can't even attempt it. Fail means you tried and the app did
the wrong thing. That difference is what makes this sheet useful next week.

**A note for whoever extends this sheet — two audits, not one.**

**First: where did the number come from?** Five cards were wrong the same way — the figure came
from reading the constitutional rule instead of the code implementing it. The rule says *"a
majority of all serving members"*; the implementation answers *"of which body?"*, and only the code
knows. When a card quotes a number, check its source and say so.

**Second, and this one is harder: what does the card teach you to ACCEPT?** One card told a tester
that an unfillable seat was "normal" — it wasn't, it was the single genuinely broken thing on that
screen. That is the opposite and worse failure. **A wrong card that fails on working software costs
an hour and starts a conversation. A wrong card that passes on a defect costs nothing and produces
silence.** Read every card twice: once for what it asks you to check, once for what it quietly
tells you not to worry about.

---

## Three controls you'll need constantly

**⚑ First, which of these are pages and which are buttons.** Only five dev addresses are pages you
can type into the browser:

> `/dev/electoral-kit` · `/dev/legislature-kit` · `/dev/judiciary-kit` · `/dev/executive-kit`

**Everything else below is a button on a page, not an address.** Typing one into the browser gives
you *"405 Method Not Allowed"* — which looks like a broken app and isn't. See warning 9.

**To become someone else** — the **dev bar at the bottom of every page**. Open it, type a name or
an email, pick the person, and you are them. A **"Return to yourself"** button appears while you
are someone else. The four kits above are real pages, and each sets up the state a role needs. Dev
mode is on for this box (sandbox game mode).

**To move time** — lane 3 is building the time control right now
(`docs/plans/playtest/DEV_TIME_AND_ROLE_CONTROLS.md`), including a preview of what an advance
*would* fire before it fires. Steps that need it are marked **⏱**. Until it lands, those steps are
*blocked*, not failed.

**To make a whole chamber vote at once** — the bloc-cast control (an action, not a page): it
takes a vote id and the yes / no / abstain counts. It turns a 58-member floor vote into one action instead of 58 logins.

Two things about it matter for testing, and they're the reason it can be trusted on this sheet:
- **It supplies ballots, never outcomes.** Every ballot is filed as a real seated member through
  the real engine. Quorum, supermajority and dual agreement are all still computed the usual way,
  and there is no path in it that can make something pass. **So if a vote fails, it genuinely
  failed** — which is the only kind of refusal worth writing down.
- **It takes a `lane`** — `type_a` or `type_b` — so you can have **one chamber agree while the
  other doesn't**, on purpose. That's how C-5 stages a real bicameral refusal.

Every use is marked in the record as a dev action, so a played timeline never reads as a lived one.

---

## Nine things that will look broken and aren't

**1. An achievement may have a plain-looking name.** Achievements now fire, but their wording is
stored as a label that gets translated later, and the translations aren't authored yet. So one may
read **"Court case"** where it will eventually read *"A court case, end to end."* Terse, not broken
— and it will never show you raw code. **No card on this sheet asks you to check for an
achievement anyway** (a self-reported tick isn't proof), so this is only about what you might
notice in passing.

**2. You cannot look up how you voted, and you never will be able to.** Your vote and your identity
are kept in two deliberately unconnected places — one holds the vote with no voter attached, the
other records that you voted without holding the vote. So "I can't find my own ballot" is the
system working. Where this sheet proves a vote happened, it proves *participation*, never content.

**3. "Elected" and "seated" mean the same thing here.** The record marks every current member of
San Marino's chambers as **elected**, and nothing is marked "seated" — both count as serving, and
all 58 voted in the committee act that carried. So a member being "elected" is not a half-finished
state. **Judge every card on what you can see** — the person is in the chamber and their vote is
counted — not on the word used. *(Verified in the database and in the code, which treats the two as
one throughout.)*

**4. ⚑ San Marino's is the chamber with people in it. The nine villages may or may not be seated
yet — and BOTH answers are correct.** Every card in Section C names `smr-1-san-marino` for that
reason.

Here's what's true either way. **All nine village maps are drawn, and every one of them divides
its seats exactly** — no village is short or over by even one:

> Serravalle 22 · Borgo Maggiore 19 · Città di San Marino 16 · Domagnano 15 · Fiorentino 14 ·
> Acquaviva 13 · Chiesanuova 10 · Faetano 10 · Montegiardino 10

**They are now seated** — 19 chambers governed across this world, 257 seats filled, no seat held
twice. So open any village and expect to find named representatives in it, in the numbers above.

| What you see | What it means |
|---|---|
| Named representatives, matching the count above | Correct — walk it like any chamber |
| An empty chamber | A regression. Write it down: they were seated on 2026-07-26 |
| A seat count not matching the list | **A finding.** Every village lands its number exactly |

*Why villages need maps at all: a village is a leaf — nothing beneath it to represent — so it has
only the population-based chamber, and that chamber is divided into districts of five to nine
seats. Above nine seats, running one at-large race would be unlawful, and the app refuses. The
five-to-nine rule governs **districts**, not chambers: the second chamber, representing constituent
places, is at-large by design and can hold far more than nine — San Marino's holds 27. It binds the
villages because they are leaves.*

**5. ⚑ Earth is EMPTY on this box — the real Earth is on the other one.** `earth-0-earth` at
:8082 has no members and no district map. The Earth with **1,999 seats across 282 districts** is on
the **game box at :8080**, which is where Section H sends you. Opening Earth on the dev box and
finding nothing is the single easiest way to conclude the app is broken when it isn't.

**6. A blank page is not proof of a blank page — look twice.** These screens are drawn by the
browser after the page arrives, so for a moment they are legitimately empty. On a loaded box that
moment stretches. **Reload once before writing anything down.** Two of us have already nearly filed
a working page as dead this way.

**7. A crash mentioning `laravel.log`, "Permission denied", or an *undefined* method is the box,
not the app.** Two shapes, same answer:
- **A log file or a permission** in the error text — the machine failing to write its own diary.
- **"Undefined method" or a name that sounds like it should exist** — the running copy of the app
  can be *older than the code*. That happened today: a method existed in the project and not in the
  container serving it. Nothing on disk was wrong; only running it revealed the gap.

**Reload. If it clears, note the time and carry on.** One page did exactly this today — fine an
hour earlier, fine an hour later.

**8. If EVERYTHING fails at once, it's the box, not the app.** One broken page is a finding; every
page broken together is the machine. Three shapes, all seen today:
- **Every page blank** → the asset server isn't running.
- **Every page "502"** → the app server is restarting. Wait a minute and retry.
- **Every page "500"** → see 7.

**Write down the time and move on — don't fill in a column of failures.** One line in your notes
("everything 502 at 15:47") is worth more than twenty cards marked fail, and it tells us exactly
where to look.

**9. "405 Method Not Allowed" on a `/dev/…` address means it's a button, not a page.** Most dev
controls are actions fired from a screen — there is nothing to visit. Go to the page that owns the
control instead; the list of the five that *are* pages is above.

---

## Sections — take them in batches

| | Section | Tests | Can you run it today? |
|---|---|---|---|
| **A** | Getting in — becoming a person with rights | 5 | ✅ Yes |
| **B** | An election, end to end | 7 | ⏱ Mostly — two steps need the time control |
| **C** | The legislature at work | 8 | ✅ Yes — both chambers are seated |
| **D** | The courts | 8 | ✅ Yes |
| **E** | Organizations & work | 6 | ✅ Yes |
| **F** | Voice — square, petitions, messages | 6 | ✅ Yes |
| **G** | The record & the clock | 4 | ✅ Yes |
| **H** | Places & maps — **use the game box, :8080** | 5 | ✅ Yes |
| **I** | The economy | 5 | ✅ Yes — live, and you can spend |

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
| 1 | Either wait for the sweep, or use the **grant button on the same page** | `/civic/residency` | Status moves to confirmed |
| 2 | Open your record | `/civic/record` | Your jurisdiction listed, marked confirmed |

*The grant button is a dev shortcut and still does the real thing — it declares, simulates the
location pings, and verifies, all through the same engine the slow path uses. It skips the waiting,
not the work.*

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

**Before you start, two things about this world's elections:**

**San Marino has already run two complete election cycles.** 57 of its 58 seats are the founding
cohort; one was re-seated later. So you are walking into a chamber with history, not a fresh one.

**There are two elections currently open, and they are supposed to be.** When a count is certified,
the app automatically opens the *next* cycle's election — that's the term clock doing its job. So
an open election here is evidence the machinery works, **not** leftover debris from a demo.
Certified races are good for B-6 and B-7; the open ones need the time control to reach their
ballot windows.

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
*Underlying proof: `legislature_members` rows tracing to that election.*

**⚠ Expect 58 of 59, and that missing seat is a REAL DEFECT — not a normal vacancy.** The law sizes
this chamber at 32 population seats; the map that was drawn only adds up to 31. **A seat with no
district has no race, so nobody can ever be elected to it** — not at this election and not at any
future one. It is already known and a redraw has been asked for. **Note it and move on; don't spend
your walk on it.** Recorded here because the rule is that a chamber must be exactly the size the
law says — a seat that cannot be filled is never acceptable, and an earlier draft of this card
wrongly called it normal.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

**End of Section B.**

---

# SECTION C — The legislature at work

## Read this first — how the two chambers work

San Marino's legislature has **two chambers**: one sized by population, one representing the nine
castelli equally. **The constitution requires both to agree before anything passes** — a bill, a
court, an executive, a committee. One chamber alone can never pass a law, and testing that is the
point of C-5.

Both chambers are seated: **31 in the first, 27 in the second, 58 serving.** Three acts have
already carried on that basis — an executive delegation, the creation of the judiciary, and a
committee (Public Works, 5 seats). So this section is fully runnable.

*Earlier today the second chamber was empty and everything here failed at the vote. That's fixed.
C-1 below just confirms it in thirty seconds before you invest in the rest — if the numbers come
back different from the ones above, stop and say so, because something has regressed.*

---

### C-1 · Confirm both chambers are seated
**What it is.** A thirty-second check before you invest an hour in this section.
**Role:** anyone · **Clock:** none · **Needs first:** nothing

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open the legislature | `/legislatures/smr-1-san-marino` | Its seats and members |
| 2 | Count members of **each** chamber | same | Roughly 31 and 27 |

**PASSES IF** — both chambers have members, totalling **58 serving**.
*Write the two numbers down. Expect **31 of 32** and **27 of 27**. The one short seat in the first
chamber is a known defect (see B-7), not something to investigate here. If the second chamber reads
0, stop — that's a regression, and everything from C-2 on will fail at the vote for that reason.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** _seated: ___ of 32 · ___ of 27_ ______________

---

### C-2 · Open a session and reach quorum
**What it is.** A chamber can only act when enough of its members show up — and the bar is a
majority of everyone **serving**, not a majority of whoever turned up. Absence never lowers it.
**Role:** a seated member · **Clock:** none · **Needs first:** C-1

**⚠ Each chamber has its OWN quorum, counted against its own members.** The two are never pooled.
Expect **16** for the district chamber (31 serving) and **14** for the constituent chamber (27).

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Become a seated member | `/dev/users` or `/dev/legislature-kit` | You are in the chamber |
| 2 | Open a session | `/legislatures/smr-1-san-marino` | A session, with quorum shown |
| 3 | Check the number for **each** chamber | same | **16** and **14** |

**PASSES IF** — each chamber shows its own bar, roughly **16** and **14**.
**A single pooled number near 30 is the FAILURE**, not the pass — it would mean the two chambers
are being counted as one body, which is exactly what a two-chamber legislature must never do.

*If a seat is vacant it simply isn't counted — the bar is measured against members serving, not
against seats existing. That's why it's 16 and not 17.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### C-3 · Introduce a bill
**What it is.** Putting a proposed law in front of the chamber.
**Role:** a seated member · **Clock:** none · **Needs first:** C-2

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Start a bill  | `/legislatures/smr-1-san-marino` | A drafting form |
| 2 | Submit it | same | The bill listed, with a stage |
| 3 | Open it | `/bills/{id}` | Its text and where it is in the process |

**PASSES IF** — the bill exists and shows its current stage.
*Underlying proof: a `bills` row; audit entry for `F-LEG-003`.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### C-4 · Send it to committee, and let the committee work
**What it is.** Bills get shaped by a smaller group first. Committee seats are assigned by members
ranking their preferences — there are no parties here to hand them out.
**Role:** seated member, then committee member · **Clock:** none · **Needs first:** C-3

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Refer the bill to a committee | the bill page | It moves to committee |
| 2 | Rank your committee preferences  | `/legislatures/smr-1-san-marino` | Your ranking saved |
| 3 | Hold a committee meeting and vote | the committee page | A recorded committee vote |
| 4 | File the committee's report | same | A report attached to the bill |

**PASSES IF** — the bill carries a committee report and a recorded committee vote.
*Underlying proof: `committee_seats`, a committee vote, a `committee_reports` row.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### C-5 · The floor vote — **both** chambers must agree
**What it is.** The real test of the two-chamber design, and **the most important card in this
section.** One chamber agreeing is not enough, and here you make that refusal happen on purpose.
**Role:** the whole chamber, via the bloc-cast control · **Clock:** none · **Needs first:** C-4

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Bring the bill to the floor | the bill page | It's up for a vote |
| 2 | Cast the **district** chamber in favour — `lane: type_a` | bloc-cast control | 31 votes recorded |
| 3 | Look at the bill | the bill page | **Recorded, and NOT passed** |
| 4 | Now cast the **constituent** chamber too — `lane: type_b` | bloc-cast control | 27 more votes |
| 5 | Look again | the bill page | **Now it passes** |

**PASSES IF** — a majority in one chamber alone does **not** pass the bill, and it passes only once
both chambers have agreed independently.

*Why this is worth doing carefully: step 3 is a **refusal**, and a refusal is where the
constitution actually lives. A screen that renders perfectly while quietly letting one chamber
legislate alone looks identical to a correct one. **If the bill passes at step 3, that is a
constitutional failure, not a bug** — stop and write down exactly what you saw.*

*The control supplies ballots, never outcomes — every vote is filed as a real member through the
real engine, and nothing in it can force a pass. So the refusal you see at step 3 is genuine.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### C-6 · It becomes law, and anyone can read it
**What it is.** The payoff — a bill turning into a law on the public record.
**Role:** anyone · **Clock:** none · **Needs first:** C-5

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open the enacted bill | `/bills/{id}` | Marked enacted, linked to a law |
| 2 | Read the law itself | the law page | Its text, and a version history |
| 3 | Sign out and read it again | same, logged out | Still readable |

**PASSES IF** — the law is readable **without logging in**, and its version history is intact.
*Underlying proof: a `law_versions` row traced to the bill.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### C-7 · Elect a Speaker
**What it is.** The chamber choosing who runs it — by ranked vote, needing a two-thirds majority.
**Role:** seated members · **Clock:** none · **Needs first:** C-1

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open a speaker ballot  | `/legislatures/smr-1-san-marino` | A ranked ballot of members |
| 2 | Vote it through | same | A Speaker named |

**PASSES IF** — a Speaker is recorded, and the bar was **two-thirds of all serving members**, not
two-thirds of those voting.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### C-8 · Emergency powers expire on their own
**What it is.** Emergency powers exist, and the constitution caps them at 90 days. The cap should
be enforced by the app, not by anyone remembering.
**Role:** seated member · **Clock:** ⏱ needs time control · **Needs first:** C-2

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Declare emergency powers  | `/legislatures/smr-1-san-marino` | An end date, at most 90 days out |
| 2 | Try to set a longer period | same | **Refused** |
| 3 | Move time past the end date | time control | They lapse on their own |

**PASSES IF** — you cannot exceed 90 days, and they end without anyone acting.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

# SECTION D — The courts

*You asked "can I run a court case?" — this is that. The court already exists: creating it was
itself an act both chambers had to agree to, and that carried.*

---

### D-1 · A court exists, with judges
**Role:** anyone · **Needs first:** C-1

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Find the court | `/judiciary` | A court for the jurisdiction |
| 2 | Look at its judges | `/judiciaries/{id}` | **At least five** judges seated |

**PASSES IF** — a court exists with **five or more** judges. *Five is the constitutional minimum;
fewer is a failure, not a small court.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### D-2 · Register as an advocate
**What it is.** Anyone can advocate — there is no bar exam or licence in this constitution.
**Role:** any confirmed resident · **Needs first:** A-3

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open the advocate page | `/judiciary/advocate` | A registration form |
| 2 | Register | same | You listed as an advocate |

**PASSES IF** — you are registered **without** needing a qualification.
*Underlying proof: audit entry for `F-IND-015`.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### D-3 · File a case
**Role:** any resident, or an advocate · **Needs first:** D-1

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open the docket | `/judiciaries/{id}/docket` | The list of cases |
| 2 | File a new case | same | Your case listed, with parties |

**PASSES IF** — the case appears on the public docket.
*Underlying proof: a `cases` row; `F-IND-017` or `F-ADV-001`.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### D-4 · A panel of judges is assigned
**Role:** a judge · **Needs first:** D-3

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Become a judge | `/dev/judiciary-kit` | Judge tools |
| 2 | Assign a panel to the case | the case page | Named judges on the case |

**PASSES IF** — a panel is recorded against the case.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### D-5 · A jury of residents
**What it is.** Juries are drawn from residents — the same absolute qualification as everything else.
**Role:** judge, then juror · **Needs first:** D-4

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Summon a jury | the case page | Summonses issued |
| 2 | Become a summoned juror | `/dev/users` | Your summons |
| 3 | Go through screening | `/judiciary/jury/{id}` | Empanelled, or excused |

**PASSES IF** — a jury is empanelled from residents.
*Underlying proof: `jury_members` rows moving from summoned to empanelled.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### D-6 · Judgment, and an opinion on the record
**Role:** a judge · **Needs first:** D-5

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Enter a judgment | the case page | The case resolved |
| 2 | File an opinion | same | The reasoning, publicly readable |
| 3 | Read it signed out | same | Still readable |

**PASSES IF** — the judgment **and its reasoning** are public.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### D-7 · Challenge a law — and watch a court change it
**What it is.** The sharpest thing in the whole application. A court can rule a law
unconstitutional and **edit it directly**, with the old version preserved. Worth seeing.
**Role:** anyone, to read it · **Needs first:** the judiciary demo has been seeded

**You do not have to drive this one.** The seeded world already contains a law that a court has
struck and rewritten — pushed the whole way through, with the setup refusing to finish if the
result came out wrong. So you can go and *read the outcome* instead of steering a challenge through
its waiting periods, which would take the rest of the afternoon.

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Find the law a court has amended | the laws list | A law with **two versions** |
| 2 | Read the newer version | the law page | Its text differs, and it's marked as coming from a court |
| 3 | Read the **original** version | the same page's history | **Still there, still readable** |

**PASSES IF** — the law's text was changed by a court **and** the version it replaced is still
readable. *Nothing was overwritten; a new version was laid on top.*

*If you want to drive one yourself: file a challenge, make a finding as a judge, apply the remedy.
It's the same path — just with real waiting periods in the middle.*

*Underlying proof: a second `law_versions` row with `source='judicial_remedy'`, the first still
present; audit `F-JDG-006`.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### D-8 · You cannot be tried twice
**Role:** advocate · **Needs first:** D-6

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | File the same case again, same parties, same matter | the docket | **Refused** |

**PASSES IF** — it is refused. *A protection, not a bug — if it goes through, that's serious.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

# SECTION E — Organizations & work

---

### E-1 · Register an organization
**Role:** any confirmed resident · **Needs first:** A-3

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open organizations | `/organizations` | A registry (may be empty) |
| 2 | Register one — try a business | same | It listed, with you as its agent |

**PASSES IF** — the organization appears in the open registry.
*Underlying proof: an `organizations` row; audit `F-IND-012`.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### E-2 · Join it, and be counted as a worker
**Role:** another resident · **Needs first:** E-1

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Become someone else | `/dev/users` | A second person |
| 2 | Join the organization | its page | Membership recorded |
| 3 | Register as a worker — **both sides must agree** | same | Countersigned, then active |

**PASSES IF** — worker status requires **both** the person and the organization to agree.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### E-3 · Run a board election
**Role:** organization agent · **Needs first:** E-2

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open a board election | the organization page | Nominations open |
| 2 | Run it through to a result | same | Board seats filled |

**PASSES IF** — board seats are filled by a recorded vote, not appointment.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### E-4 · Workers get a board seat at 100 employees
**What it is.** Article III's co-determination rule. At 100 workers a seat appears for them
automatically — nobody has to ask.
**Role:** organization agent · **Needs first:** E-2

**⏳ Before you start — the seat does not appear the instant you hire.** The recount runs in the
background, so it lands a moment later, not on the page you're redirected to. **Wait a few seconds
and reload before judging this card.** If it never appears, check the background worker is running
before recording a failure — "the seat never came" and "the queue is dead" look identical from the
screen, and only one of them is about co-determination.

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Grow the organization past 100 workers | `/dev/users` + the org page | Worker count crosses 100 |
| 2 | **Wait a few seconds, then reload** | same | **A worker seat has appeared by itself** |

**PASSES IF** — the worker seat appears **without anyone requesting it**.
*This is one of the hardest-won pieces of the build. If it genuinely doesn't fire — and the queue
is alive — that's a headline failure.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### E-5 · Equal footing at 2,000 employees
**Role:** organization agent · **Needs first:** E-4

**⚠ It is a slope, not a step.** The share doesn't sit at one seat until 2,000 and then jump to
parity — it **climbs steadily** the whole way: one seat at 100, rising with every worker, reaching
equal footing at 2,000. So an organization with 1,000 workers gets **neither one seat nor parity**,
but something in between — **and that is correct.** Only test the two ends; a middle number is the
rule working, not a fault.

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Grow past 2,000 workers | the org page | Count crosses 2,000 |
| 2 | Wait a few seconds, then reload | same | Worker and owner seats **equal** |
| 3 | *(optional)* Look at an org in the middle | same | A number between the two — **not a fault** |

**PASSES IF** — the split reaches parity on its own at the top of the range.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### E-6 · A public-good company's work stays public forever
**What it is.** Common-good corporations put their work in the public domain permanently. Nobody —
not even them — can take it back.
**Role:** organization agent · **Needs first:** E-1

**⚑ You will find nothing to click, and that IS the pass.** Don't mark this blocked because you
couldn't attempt step 3 — there is deliberately no control to attempt it *with*. The guarantee is
built in three layers: **no way to ask** (the service has one method, "dedicate", and no opposite),
**no way to store it** (the record permits exactly one value — public domain, with no second state
to move to), and **an outright refusal** if someone forges the request anyway.

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Register a common-good corporation | `/organizations` | It listed |
| 2 | Add something to its work register | the org page | Listed as public domain |
| 3 | **Hunt for any way to make it private** | anywhere | **Nothing. No button, no menu, no field** |

**PASSES IF** — you searched and **there is no control anywhere** to take it back.
*If you find one, that is among the most serious findings possible in this app — Article III §5 is
perpetual and irrevocable by design.*

*Underlying proof, for anyone who wants it: the register's status column accepts only
`public_domain`, so privatising isn't merely forbidden — it's unrepresentable.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

# SECTION F — Voice

*Runnable today. Nothing here depends on the chamber.*

---

### F-1 · Speak in the public square
**What it is.** Open speech on the public record. **The square is open to everyone** — you do not
need a confirmed residency to speak.
**Role:** anyone signed in · **Needs first:** A-1

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open the square | `/civic/square` | The square (may be empty) |
| 2 | Post something | same | Your post on the record |
| 3 | Try to delete it | same | **You should not be able to quietly remove it** |

**PASSES IF** — the post persists and cannot be silently erased.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### F-2 · Seal testimony in a hall
**What it is.** Stronger than a square post — testimony, tied to your confirmed residency.
**Role:** confirmed resident · **Needs first:** A-3

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Find a hall | `/civic/square` | Halls alongside the square |
| 2 | Seal testimony | same | Recorded, attributed |
| 3 | Try the same as someone unconfirmed | `/dev/users` | **Refused** |

**PASSES IF** — sealing needs confirmed residency; **speaking in the square does not.**
*That difference is deliberate. If both are gated, the square is wrong.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### F-3 · Create a petition
**Role:** confirmed resident · **Needs first:** A-3

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open petitions | `/civic/petitions` | The list |
| 2 | Create one | same | Yours listed, with a signature target |

**PASSES IF** — the petition exists and shows how many signatures it needs.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### F-4 · Sign one — and change your mind
**Role:** other residents · **Needs first:** F-3

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | As someone else, sign it | `/civic/petitions/{id}` | Count goes up |
| 2 | Withdraw the signature | same | Count goes down |
| 3 | Try to sign twice | same | **Refused** |

**PASSES IF** — signatures can be withdrawn, and cannot be doubled.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### F-5 · A petition becomes a referendum
**What it is.** The whole point of petitions — enough signatures and the question goes to everyone.
**Role:** residents, then the legislature · **Clock:** ⏱ may need time control · **Needs first:** F-4

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Sign it past its threshold | `/civic/petitions/{id}` | Threshold reached |
| 2 | Follow it to the legislature  | `/legislatures/smr-1-san-marino` | It has arrived there |
| 3 | See a referendum created | same | A question everyone can vote on |

**PASSES IF** — reaching the threshold moves it forward **without anyone deciding to allow it**.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### F-6 · A private message stays private
**Role:** any two people, then a third · **Needs first:** A-1

**A precondition worth knowing.** Messages are carried by a separate messaging server, not by the
civic record — deliberately, because private conversation shouldn't live on the public plane. So
if nothing sends at all, check that server is up before recording a failure: **"my message didn't
arrive" and "the messaging server is down" look identical from here**, and only one is a defect.

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open messages | `/civic/rooms` | Your conversations, and a way to start one |
| 2 | Start a direct conversation with someone | same | A new room, just the two of you |
| 3 | Send them something | the room | It appears in the thread |
| 4 | Become the person you messaged | the dev bar | They can read it |
| 5 | Now become a **third** person and go hunting | the dev bar | **They cannot find it at all** |

**PASSES IF** — the two of you can read it and **a third person cannot** — not the text, not the
room, not the fact it happened.

*Step 5 is the actual test. Steps 1–4 only prove messaging works; a private message is only
private if someone outside it comes away with nothing.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

# SECTION G — The record & the clock

*Short section, all runnable, and the most reassuring one to do after a long walk.*

---

### G-1 · The audit chain verifies
**What it is.** Every constitutional act, chained in order, so nothing can be changed after the
fact without it showing. Currently over 7,000 entries on this box.
**Role:** ⚑ **YOURSELF, as the operator** · **Needs first:** nothing

**⚑ Stop impersonating before this card.** Every section before this one had you being somebody
else. **Verifying the chain is operator-only** — it's an expensive walk of every entry — so if you
arrive still wearing someone else's face, the verify control simply isn't there and this card fails
for a reason that has nothing to do with the chain. Return to yourself first.

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 0 | **Return to yourself** | the dev bar | You are you again |
| 1 | Open the chain | `/system/audit-chain` | Entries in order, newest first |
| 2 | Verify it | same | It confirms unbroken |
| 3 | Find something **you** filed — match the form id in the `ref` column against the time you did it (your residency declaration is `F-IND-003`) | same | Your entry, in sequence |

**PASSES IF** — the chain verifies **and** you can pick out an act you performed.

*Look at what step 3 made you do: the chain records **what happened and never who**. There is no
name on any entry. You can find your own act because you know what you did and when — and nobody
else can. That's the privacy rail, and it's easier to see by hunting for your own entry than by
being told about it.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### G-2 · The public record cannot be edited at all
**Role:** anyone · **Needs first:** F-1

**Same shape as E-6 — you'll find nothing to click, and that's the pass.** Don't mark this blocked.
"Can't be edited *quietly*" would understate it: edits don't happen and get logged, they are
**refused by the database itself**, which will not accept a change or a deletion of these rows.

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open the record | `/system/public-records` | Records, readable |
| 2 | Read it signed out | same | Still readable |
| 3 | **Hunt for any way to change or remove one** | anywhere | **Nothing** |

**PASSES IF** — records are public, readable without an account, and **there is no way to alter
one**. If you find an edit or delete control, that is a serious finding.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### G-3 · The clocks are armed
**What it is.** The rules that act without anyone pressing anything. **Most of them are not
timers** — of the 21, only two run on a schedule. The rest are thresholds, windows and conditions
that fire when something becomes true.
**Role:** anyone · **Needs first:** nothing

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open the clocks | `/system/clocks` | All 21, each with what fires it |
| 2 | Find the one that confirmed your residency in A-3 | same | A **threshold**, not a schedule |
| 3 | Read what trips it | same | A number of qualifying days, and what it starts |

**PASSES IF** — you can point at the rule that acted on you in A-3 and read the condition that
tripped it. *Don't look for "when it last ran" — most of these never "run". Yours fired because
**you** crossed a line, which is the more interesting thing anyway.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### G-4 · The constitution changes through exactly two doors
**Role:** anyone · **Needs first:** nothing

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open amendments | `/system/amendments` | The rules that cannot change, and the ledger of changes |
| 2 | Read the hardened list | same | Plain statements of the fixed rules |

**PASSES IF** — you can tell which rules are fixed and which can be changed by a vote.
*⚠ Known defect: one line reads "5–9 seats with mandatory subdivision above 9" with no subject.
It should say that **districts** elect 5–9 representatives — the legislature total has no cap.
A fix is queued. **Note it, don't file it.** The change ledger will also be empty on this box.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

# SECTION H — Places & maps

## ⚑ Do this section on the OTHER box — the game box at http://localhost:8080

San Marino has 11 places. **The game box has 956,336** — the whole planet, with Earth's real
1,999-seat legislature and its drawn district map. It is the only place the map work exists at
scale, and no fixture can show it.

**Two things to know before you click:**
1. **The first page takes up to 40 seconds.** Measured: the mapper takes ~42s cold and ~4s after.
   `/legislatures` ~30s cold. **Wait. Do not judge it on the first load.** If you get an error page,
   reload once before writing anything down.
2. **Most of that box is behind its setup wizard** — only places, legislatures, the chamber and the
   mapper are open. Everything else redirects you to setup. That's expected.

---

### H-1 · Browse the planet
| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open places (**wait**) | `:8080/jurisdictions` | Jurisdictions, planet down |
| 2 | Open Earth | `:8080/jurisdictions/earth-0-earth` | Earth as a place that governs itself |

**PASSES IF** — you can move from the planet down toward a neighbourhood.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### H-2 · A legislature sized by its population
| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open Earth's legislature | `:8080/legislatures/earth-0-earth` | **1,999 seats** |
| 2 | Look at how it's divided | same | Hundreds of districts, each 5–9 seats |

**PASSES IF** — the total is 1,999 across **282** districts, and the screen makes clear the 5–9
band is about **districts**, not the legislature.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### H-3 · The district mapper, at planetary scale
| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open the mapper (**wait ~40s**) | `:8080/legislatures/earth-0-earth/districts` | A real map with drawn districts |
| 2 | Move around and zoom | same | It stays usable |
| 3 | Open a district | same | Its seats and the places in it |

**PASSES IF** — the map draws and you can explore it. *Slow is a note, not a fail — write the
seconds down.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** _cold: ___s · warm: ___s_ ________________

---

### H-4 · Draw a district by hand
**Role:** ⚑ **yourself, as the operator** · **Needs first:** H-3

**Same warning as G-1: be yourself for this one.** If you're still wearing somebody else's face
from an earlier section, the drawing tools won't be there and the card fails on permissions rather
than on drawing.

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 0 | Return to yourself if you're impersonating | the dev bar | You are you again |
| 1 | Start a manual draw | the mapper | Drawing tools |
| 2 | Draw one and accept it | same | It saved, with its seats |

**PASSES IF** — your district is saved and counted.
*Underlying proof: audit entry for `F-ELB-008`.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### H-5 · A chamber anyone can watch without an account
**What it is.** Legislative proceedings are public by constitutional requirement — no login.
**Role:** **nobody — sign out entirely** · **Needs first:** nothing

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Sign out, or use a private window | `:8080` | Signed out |
| 2 | Open a legislature's chamber | `:8080/legislatures/{id}/chamber` | The chamber, readable |

**PASSES IF** — you can watch **without an account**. *If it asks you to log in, that is a
constitutional failure, not a convenience issue.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

# SECTION I — The economy

*The screens are built and the economy is now **live** — you can register a thing, offer it, order
it, and send money. Every control files through the constitutional engine rather than an economy
API, so what these pages can do is exactly what the constitution permits, and no more.*

---

### I-1 · The money, and whether the books balance
**Role:** anyone signed in · **Needs first:** A-1

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open the economy | `/economy` | The currency, and what's in circulation |
| 2 | Look at the ledger panel | same | Entries, a chain state, and a residual |

**PASSES IF** — the chain reads **verified** and the residual reads **zero**.
*That zero is the real check. Issuing money is the only way value enters; everything after that
just moves it about. So a healthy ledger balances to nothing left over — a non-zero residual means
value appeared from somewhere, which is the one thing that must never happen.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### I-1b · Try to spend money you don't have
**What it is.** The sharpest card in this section, and it takes a minute. There is **no overdraft**
in the individual economy — so the app should refuse, and refuse *by explaining itself*.
**Role:** a resident with a wallet · **Needs first:** A-3

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Note your balance | `/economy/wallet` | Some amount |
| 2 | Send far MORE than that to any account | same | **Refused** |
| 3 | Read the refusal | same | A plain reason, and which article it comes from |
| 4 | Check your balance again | same | **Unchanged** |

**PASSES IF** — you are refused, told **why** in words, and nothing moved.
*A refusal here is the constitution answering you, not the app failing. If it reads like an error —
a red crash, a stack trace, a silent nothing — that's the finding, even though the money correctly
stayed put. The rule working and the rule **explaining itself** are two different tests.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### I-2 · Your wallet is yours alone
**Role:** a resident with a wallet, then someone else · **Needs first:** A-3

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open your wallet | `/economy/wallet` | Your balance and your own movements |
| 2 | Note whether any **other person** is named | same | Only accounts, never people |
| 3 | Become someone else and open theirs | `/dev/users` → `/economy/wallet` | **Their own** wallet, not yours |

**PASSES IF** — you can see your own balance and **cannot** see anyone else's, and no counterparty
is identified as a person anywhere on the page.
*The public accounts in I-4 are the deliberate opposite. Public money is examined; private money
isn't.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### I-3 · The market has both sides
**Role:** anyone signed in · **Needs first:** A-1

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open the market | `/economy/market` | Things for sale |
| 2 | Switch to work, then to requests for help | same | Three sections, each with a count |
| 3 | Open a listing | `/economy/market/{id}` | Price, quantity, and how many have ordered |

**PASSES IF** — all three sections render, and the listing shows the seller as an **account**, never
as a named person.
*A market with only sellers is a catalogue — the work and mutual-aid sides are the point.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### I-4 · Public money is public
**Role:** anyone · **Needs first:** nothing

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open public finance | `/economy/treasury` | Ledger rows with their hashes |
| 2 | Look at the accounts | same | Balances, marked public or private |
| 3 | Open the levers | `/economy/units` | The currency, and what can be changed |
| 4 | Try to change one | same | **There is nothing to change it with** |

**PASSES IF** — the public ledger is readable, **and** the levers page offers no control at all.
*Step 4 is the point: every monetary lever moves only by an act of a legislature, within bounds it
cannot exceed. There is deliberately no admin knob — not for an operator, not for you. If you find
one, that's a serious finding.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

## Running notes — anything, any section

_____________________________________________________________________________________

_____________________________________________________________________________________

_____________________________________________________________________________________

_____________________________________________________________________________________

---

## Changelog
- **v4** (2026-07-26) — Front matter gains the three warnings most likely to produce a false
  failure: **only San Marino's chamber has anybody in it** (ten of eleven legislatures are empty,
  correctly), **Earth is empty on the dev box** (the real 1,999-seat Earth is on the game box), and
  **a blank page is not proof of a blank page** — these screens draw after arrival, so reload once
  before writing. Every generic "the legislature page" now names `smr-1-san-marino`. C-5 rewritten
  around lane 13's bloc-cast control: with its `lane` parameter you can make **one chamber agree
  while the other doesn't** and watch a genuine bicameral refusal, which is the whole point of the
  card. Six economy screens shipped (`82861e9`), so Section I is no longer a "don't walk here".
- **v3** (2026-07-26) — **Section C unblocked and fully runnable.** The second chamber is seated
  (31 + 27 = 58 serving) and three bicameral acts have carried, so C, D and E all run normally;
  C-1 is now a thirty-second confirmation rather than a gate. Added the two "looks broken and
  isn't" notes up front: achievements will not appear (the awarding code isn't built), and a vote
  cannot be traced back to its voter by design. Bloc-vote steps marked ⏱ for lane 13's control.
  *Seating taken on three independent live-database reads by lanes 3, 4 and 13; the container
  engine was down when this was written, so I could not read it myself — verify on C-1.*
- **v2** (2026-07-26) — Sections **C–H in full** (37 more tests). Fixed the contents table, which
  promised 8 section-C tests that didn't exist yet. Section H points at the game box.
- **v1** (2026-07-26) — Frame, the one rule, the two controls, Sections A and B in full.
