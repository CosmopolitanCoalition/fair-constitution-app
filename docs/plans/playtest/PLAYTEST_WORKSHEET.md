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
(`docs/plans/playtest/DEV_TIME_AND_ROLE_CONTROLS.md`), including a preview of what an advance
*would* fire before it fires. Steps that need it are marked **⏱**. Until it lands, those steps are
*blocked*, not failed. Steps needing a whole chamber to vote at once are also marked ⏱ — lane 13's
bloc-cast control is what turns those from 58 separate logins into one action.

---

## Two things that will look broken and aren't

**1. No achievements will appear.** The app has a catalogue of 139 achievements, but the piece that
watches for them and awards them isn't built yet. **Nothing on this sheet asks you to check for an
achievement** for exactly that reason — if you see a card that does, it's a mistake, tell me.

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
| I | The economy | — | ⛔ No screens yet — lane 6 building them |

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
*Underlying proof: `legislature_members` rows tracing to that election. **Expect 58 members across
both chambers** (31 + 27) against 59 seats — one vacancy, which is normal, not a failure.*

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

**PASSES IF** — both chambers have members, totalling around **58 serving**.
*Write the two numbers down. If the second chamber reads 0, stop — that's a regression, and
everything from C-2 on will fail at the vote for that one reason.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** _seated: ___ of 32 · ___ of 27_ ______________

---

### C-2 · Open a session and reach quorum
**What it is.** A legislature can only act when enough of its members show up. Quorum is a majority
of **all serving members** — not a majority of whoever turned up.
**Role:** a seated member · **Clock:** none · **Needs first:** C-1

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Become a seated member | `/dev/users` or `/dev/legislature-kit` | You are in the chamber |
| 2 | Open a session | the legislature's chamber | A session, with a quorum count |
| 3 | Check what quorum it demands | same | A number over half of all serving |

**PASSES IF** — the quorum number is computed against **all serving members**, not attendance.
*Worth checking by hand: with 58 serving, quorum should be 30.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### C-3 · Introduce a bill
**What it is.** Putting a proposed law in front of the chamber.
**Role:** a seated member · **Clock:** none · **Needs first:** C-2

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Start a bill | the legislature page | A drafting form |
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
| 2 | Rank your committee preferences | the legislature page | Your ranking saved |
| 3 | Hold a committee meeting and vote | the committee page | A recorded committee vote |
| 4 | File the committee's report | same | A report attached to the bill |

**PASSES IF** — the bill carries a committee report and a recorded committee vote.
*Underlying proof: `committee_seats`, a committee vote, a `committee_reports` row.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### C-5 · The floor vote — **both** chambers must agree ⏱ easier with the bloc-vote control
**What it is.** The real test of the two-chamber design. One chamber agreeing is not enough.
**Role:** seated members in both chambers · **Clock:** none · **Needs first:** C-4
*58 members must vote. Lane 13's bloc-cast control lands shortly and turns this from 58 logins into
one action — worth waiting for rather than doing by hand.*

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Bring the bill to the floor | the bill page | It's up for a vote |
| 2 | Vote it through the **first** chamber only | the chamber | Recorded, but not passed |
| 3 | Now vote it in the **second** chamber | the chamber | Now it passes |

**PASSES IF** — the bill does **not** pass on one chamber alone, and does once both agree.
*This is the single most important check in Section C. If one chamber can pass a law by itself,
that is a constitutional failure, not a bug.*

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
| 1 | Open a speaker ballot | the legislature page | A ranked ballot of members |
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
| 1 | Declare emergency powers | the legislature page | An end date, at most 90 days out |
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
**Role:** resident → judge · **Needs first:** C-6 (a law to challenge)

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | File a constitutional challenge against the law from C-6 | the law page | The challenge filed |
| 2 | As a judge, make a constitutional finding | the case page | The finding recorded |
| 3 | Apply the remedy | same | **The law's text actually changes** |
| 4 | Look at the law's history | the law page | The old version still there |

**PASSES IF** — the law text changes **and** the previous version remains readable.
*Underlying proof: a new `law_versions` row of kind `judicial_remedy`; audit `F-JDG-006`.*

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

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Grow the organization past 100 workers | `/dev/users` + the org page | Worker count crosses 100 |
| 2 | Look at the board | same | **A worker seat has appeared by itself** |

**PASSES IF** — the worker seat appears **without anyone requesting it**.
*This is one of the hardest-won pieces of the build. If it doesn't fire, that's a headline failure.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### E-5 · Equal footing at 2,000 employees
**Role:** organization agent · **Needs first:** E-4

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Grow past 2,000 workers | the org page | Count crosses 2,000 |
| 2 | Look at the board's make-up | same | Worker and owner seats **equal** |

**PASSES IF** — the split reaches parity on its own.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### E-6 · A public-good company's work stays public forever
**What it is.** Common-good corporations put their work in the public domain permanently. Nobody —
not even them — can take it back.
**Role:** organization agent · **Needs first:** E-1

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Register a common-good corporation | `/organizations` | It listed |
| 2 | Add something to its work register | the org page | Listed as public domain |
| 3 | **Try to make it private** | same | **Refused** |

**PASSES IF** — step 3 is refused. *An absolute rule; there should be no way round it.*

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
| 2 | Follow it to the legislature | the legislature page | It has arrived there |
| 3 | See a referendum created | same | A question everyone can vote on |

**PASSES IF** — reaching the threshold moves it forward **without anyone deciding to allow it**.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### F-6 · A private message stays private
**Role:** any two people · **Needs first:** A-1

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open messages | `/civic/rooms` | Your conversations |
| 2 | Message someone | same | It arrives |
| 3 | As a **third** person, go looking for it | `/dev/users` | **You cannot find it** |

**PASSES IF** — a third party cannot read it. *Private means private, like a ballot.*

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

# SECTION G — The record & the clock

*Short section, all runnable, and the most reassuring one to do after a long walk.*

---

### G-1 · The audit chain verifies
**What it is.** Every constitutional act, chained in order, so nothing can be changed after the
fact without it showing. Currently over 7,000 entries on this box.
**Role:** anyone · **Needs first:** nothing

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open the chain | `/system/audit-chain` | Entries in order |
| 2 | Verify it | same | It confirms unbroken |
| 3 | Find one of **your** acts from today | same | Your act, in order |

**PASSES IF** — the chain verifies **and** you can find something you personally did.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### G-2 · The public record can't be quietly edited
**Role:** anyone · **Needs first:** F-1

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open the record | `/system/public-records` | Records, readable |
| 2 | Read it signed out | same | Still readable |
| 3 | Try to change or remove one | same | **No way to do it quietly** |

**PASSES IF** — records are public and cannot be silently altered.

**Result** ☐ pass ☐ fail ☐ blocked  **Notes** ________________________________________

---

### G-3 · The clocks are armed
**What it is.** The processes that start on their own — deadlines, sweeps, thresholds — with no
one pressing anything.
**Role:** anyone · **Needs first:** nothing

| # | Do this | On this screen | You should see |
|---|---|---|---|
| 1 | Open the clocks | `/system/clocks` | The full list, with timers |
| 2 | Find the one that confirmed your residency in A-3 | same | It, and when it last ran |

**PASSES IF** — you can point at the clock that acted on you in A-3.
*That connection — a rule firing on its own, on you — is the thing worth checking.*

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
**Role:** operator · **Needs first:** H-3

| # | Do this | On this screen | You should see |
|---|---|---|---|
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

**⛔ Not walkable yet, and it's mine to fix.** The economy engine is built and running underneath —
a currency, a treasury with 250,000 issued, 60 wallets, a completed payment run to 60 people, a
marketplace with a completed sale. **The screens don't exist yet**, so the six economy addresses
currently show a blank page rather than an empty one.

**Please don't walk to `/economy` until I've said it's ready** — you'd get a white screen and
reasonably conclude the economy is broken, when it's one of the more finished things in the build.

I'll add Section I when the screens land.

---

## Running notes — anything, any section

_____________________________________________________________________________________

_____________________________________________________________________________________

_____________________________________________________________________________________

_____________________________________________________________________________________

---

## Changelog
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
