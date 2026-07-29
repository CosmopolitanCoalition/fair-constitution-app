# K2 — Per-Screen Education Content (authored)

**Owner:** Lane 15 (Phase K-2) · **Deliverable:** D-16c authoring pass · **Started:** 2026-07-25
**Authority:** operator approved the §9 template in `K2_CURRICULUM.md` (2026-07-25) — *"I'm pretty
sure we're already there."* Plus: **in-app education is PER SCREEN — "it's all about every screen.
So it's the shape for everything."**

**✅ COMPLETE — all 70 surfaces authored (140 of 140 halves; 476 translatable strings).**
Waves 1-7: civic 14 · legislature 12 · electoral 9 · judiciary 7 · executive 6 · organizations 6 ·
system 6 · operator 6 · jurisdictions 4. Four dev harnesses excluded by design.

**⚑ EXTRACTED TO CODE 2026-07-28 (V3 synthesis Wave 1, L1).** This file is now the SOURCE for a
generated payload: `scripts/education/build_education_payload.mjs` parses every
`K2_CONTENT_*.md` in this folder and emits `resources/js/i18n/locales/en/c_education.json`,
`resources/js/i18n/meta/en/c_education.json`, and `resources/js/registry/education.js` (plus the
achievements catalog and flow context). **Edit here, re-run the script, commit both.** The counts
below were corrected against the generator's measured output — the earlier header said 482
strings / 187 steps, but the file as committed holds **184 steps → 476 strings**; the 482 was a
counting error in the header, not lost content (every surface's every row is emitted, and the
generator exits nonzero if a surface fails to parse).

---

## ⚑ i18n key contract (for @lane-05) — settled 2026-07-25, before the catalog grew

Written to lane 5's existing convention rather than inventing one. Their shape, verified in
`resources/js/i18n/locales/<locale>/<ns>.json`, is a **flat map of `group.snake_key` → string**,
with a parallel `meta/<locale>/<ns>.json` carrying `{status, provider}` per key.

**Two new namespaces, no changes to any existing one:**

| Namespace file | Key shape | Example |
|---|---|---|
| `c_achievements.json` | `achievement.{code_snake}` | `achievement.ach_civ_005` |
| `c_education.json` | `education_{surface_snake}.{part}` | `education_civic_residency.s1_do` |

`{surface_snake}` = the surface id with `/` and `-` collapsed to `_` (`civic/residency` →
`civic_residency`). `{part}` is one of: `learn` · `s{n}_do` · `s{n}_detail` · `why`.

### ⚠ The volume is bigger than the "140 items" figure I gave you — corrected here

"140 items" counted **surface-halves**, not strings. Each half contains several strings. Measured
from the 42 surfaces authored so far:

**FINAL COUNTS — all 70 surfaces authored, so these are measured, not projected:**

| | Count |
|---|---|
| `learn` sentences | **70** |
| `howto` steps × (do + detail) | 184 × 2 = **368** |
| `why` callouts | **38** |
| **Education strings** | **476** |
| **Achievement titles** | **141** (127 ACH-* + 14 tour arcs) |
| **TOTAL lane-15 payload** | **617 strings** |

That is **~4.4× the "140 items" figure I first gave you** — that number counted surface-halves,
and each half holds several strings. Corrected the same night rather than after you had planned
French and Portuguese capacity around it. *(2026-07-28: step count corrected 187→184 and
achievements 139→141 against the generator's measured output — the payload is 476 + 141 = 617.)*

**What is NOT in the payload — do not translate these:**
- The **`cite` column**. It is Article references and form/role/clock ID tokens
  (`F-IND-003`, `Art. II §6`, `CLK-05`). Per your own rule those tokens are **never localised**,
  and the parenthetical glosses (`(Right to Reside)`) already live in `config/cga/surfaces.php`
  citations, which is existing scope. **I emit zero new `cite` strings.**
- Dev harness surfaces (`dev/*-kit`) — excluded from the education pass entirely.

**Stability guarantee:** keys are derived mechanically from the surface id and step ordinal, so
they are stable across re-authoring. If a step is inserted mid-list I will append rather than
renumber, so a translated `s2_do` never silently becomes a different sentence.

---

## How to read this file

Each surface gets exactly the two halves the operator asked for, on every screen:

| Half | Field | Source |
|---|---|---|
| **App education** — *how to use this page* | `learn` (one sentence) + `howto` (SOP steps) | **authored here** |
| **Civic education** — *the constitutional why* | `cite` on each step + the surface `citation` | **already exists** in `config/cga/surfaces.php` — reused, never re-authored |

`howto` steps use the mockups' existing `sopPanel` primitive, which is already CSS'd
(`.sop`, `.sop-do`, `.sop-detail`) and needs no new styling:

```
step := { do: "<the action you take>", detail: "<how it works underneath>", cite: "<the basis>" }
```

**Voice rules I'm holding:** second person, present tense, no jargon on first use, no phase
vocabulary, no "medal" (say **achievement**), and never promise a capability the code does not have.
Where the app deliberately does *not* gate something, say so — the absence is the lesson.

---

## Wave 1 — `civic` (14 surfaces)

### 1. `auth/register` — Create your account
**learn:** Creating an account gives you a place to stand — nothing more and nothing less. It does
not make you a resident, and it never decides whether you may vote.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Enter an email and a password | Your account is yours alone. You can use a name nobody can trace to you — the app is built for pseudonyms. | Art. I |
| 2 | Confirm and sign in | You are now **R-01 (Individual)**. You can look around everything public and file the handful of forms about yourself. | F-IND-001 |
| 3 | Set up your profile if you want | Entirely optional, and always editable. **No profile choice ever unlocks or blocks a right.** | F-IND-002 · Art. I |

> **The why:** rights here are *inherent* — the app never grants them, it only recognises them once
> you live somewhere. Registering is bookkeeping, not permission.

---

### 2. `civic/residency` — Residency
**learn:** This is the one screen that matters most. Confirming where you live is the **only** thing
standing between you and the right to vote and to run for office.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Declare where you live | You name the place. Nobody approves it — you are not asking permission. | F-IND-003 · Art. I (Right to Reside) |
| 2 | Let the app confirm you are there over time | Your device checks in quietly in the background. The pings prove presence; they are private and are never published. | F-IND-005 · CLK-05 |
| 3 | Cross the confirmation threshold | The system files the confirmation itself once you have been there long enough. You do not have to ask, and no official signs off. | F-IND-006 |
| 4 | **Your rights switch on — all of them, at once** | The moment residency is confirmed you are Associated, and Voter is emitted **in the same breath**. There is no separate approval, no waiting list, no test. | Art. I; Art. V §1 |

> **The why:** Art. I says you may vote *"regardless of any characteristic except jurisdictional
> association."* The code takes that literally — Voter (R-04) is derived **identically** to
> Associated (R-03), so nothing can ever be inserted between living somewhere and voting there.

---

### 3. `civic/home` — Civic home
**learn:** Your civic front page: what is happening where you live, and what is yours to act on
right now.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read what is live in your places | You belong to every jurisdiction that contains you — neighbourhood up to the planet — and each one may have its own business. | Art. V §1 |
| 2 | Act on what is open to you | Ballots, petitions, and meetings you can join appear here. Anything you cannot act on yet says plainly why. | WF-CIV-02, WF-CIV-04 |
| 3 | Check your status line | It tells you whether residency is confirmed. If it is, you can vote and stand for office — there is no other requirement. | Art. I |

> **The why:** one person is a resident of many nested places at once, and each of those places
> governs itself. This screen is the join of all of them.

---

### 4. `civic/identity-verification` — Identity verification
**learn:** Optional. It exists for situations that need a verified identity — and it is **never** a
requirement for voting or for standing for office.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Submit verification only if you want or need it | Nothing on this screen is required to participate in governance. | F-IND-004 |
| 2 | Understand what it does not do | It does not speed up residency, does not grant a role, and does not unlock a right you did not already have. | Art. I |

> **The why:** the app's validator carries a hard list of things that may never be attached to a
> rights-bearing form — including `requires_identity_verification`. A filing that tried to make
> identity a condition of voting would be **rejected by the engine**, not merely discouraged.

---

### 5. `civic/my-record` — My record
**learn:** Everything the app holds about you, in one place — what is public, what is private, and
what you can change.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Review your record | Your acts as an officeholder or candidate are public by constitutional design. Your ordinary life here is not. | Art. II §2 |
| 2 | Edit what is yours to edit | Your profile is self-managed. You never file a request to change your own details. | F-IND-002 · Art. I |
| 3 | See your achievements | They record what you have done. **An achievement never changes a vote, a seat, or what you are allowed to do** — and they are private unless you choose to show them. | Art. I |

> **The why:** the line is drawn at *power*. Hold power and the record is public, permanently. Live
> your life and it is yours.

---

### 6. `civic/petitions` — Petitions
**learn:** How people make law without waiting for a legislature to agree.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read the open petitions where you live | Anyone associated with the jurisdiction can sign. | Art. II §6 |
| 2 | Sign one | Your signature is a civic act on the record. | F-IND-010 |
| 3 | Start your own | You write the text. If it reaches the signature threshold, the legislature must take it up. | F-IND-009 |

> **The why:** Art. II §6 is the escape hatch from a legislature that will not act. The threshold is
> a percentage of the jurisdiction's population, so a small place needs few signatures and a large
> one needs many — the bar scales with who has to be persuaded.

---

### 7. `civic/petition-detail` — Petition detail
**learn:** One petition, its text, its signatures, and exactly where it stands.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read the full text before signing | What you sign is what goes forward — the wording does not change underneath you. | Art. II §6 |
| 2 | Sign, or withdraw a signature you already gave | **You can change your mind** — right up until the audited count freezes. | F-IND-010 |
| 3 | Watch the audit | The election board audits the signatures; a court can review the petition's constitutionality before it proceeds. | F-ELB-005 · F-JDG-008 |

> **The why:** a signature is revocable because consent that cannot be withdrawn is not consent.
> It stops being revocable at the audit, because a count has to be a count.

---

### 8. `civic/public-square` — Public Square
**learn:** The open public forum for your jurisdiction. It cannot be censored — and that is a
constitutional rule, not a policy the operators chose.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read and post | **Anyone signed in can post here — resident or not.** The square is open; residency gates *powers*, never speech. | F-SOC-001 (no role gate) · Art. I |
| 2 | Block anyone you do not want to hear | Blocking is *your* private act. It changes your view and nobody else's. | Art. I |
| 3 | Know the only four exceptions | A post can be removed **only** by judicial order, to protect someone else's rights, by your own block, or by content-neutral anti-spam. Every removal is logged with its reason. | Art. I |

> **The why:** there is no "violates our guidelines" here, and no operator or legislature can add
> one. The removal check accepts exactly two reasons and demands a reference for each — a takedown
> on any other ground is refused by the engine.

---

### 9. `civic/halls` — Halls of Governance
**learn:** Where discussion attaches to actual government business — a bill, a referendum, a
petition, a committee — and where you can put testimony permanently on the record.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Find the hall for the thing you care about | Halls bind themselves to real governance objects, so the conversation sits with the decision. | Art. II §2 |
| 2 | Join the discussion | Same open posting as the square. | F-SOC-001 · Art. I |
| 3 | **Seal testimony** | This is the step that differs: sealing puts your words into the permanent public record, and it requires residency in that jurisdiction. | F-SOC-002 · R-03 |

> **The why:** talking is open to everyone; *testifying* to a government is an act of a person that
> government answers to. That is the one place residency matters here — and it is about the record,
> not about who may speak.

---

### 10. `civic/commons-square` — Live Square
**learn:** The live, real-time version of the square, carried over the mesh so people on different
servers share one room.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Join the live room | Real-time chat and voice, as opposed to the threaded square. | Art. I |
| 2 | Note the difference from the threaded square | The **live** room is residency-only; the threaded square is open to anyone signed in. Same principle, different rooms. | Art. I |

> **The why:** the permanent record and the live conversation are different things with different
> rules. The record is uncensorable and open; the live room is a place your neighbours hold.

---

### 11. `civic/commons-halls` — Live Halls
**learn:** Live discussion tied to government business — and you can turn something said here into
sealed testimony.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Join the hall's live room | Same binding to bills, referendums and committees as the threaded halls. | Art. II §2 |
| 2 | Seal a live message as testimony | A spoken or typed contribution becomes part of the permanent record when you file it. | F-SOC-002 |

> **The why:** nothing is on the record until someone deliberately puts it there. Live talk is talk;
> testimony is a filing.

---

### 12. `civic/relocation` — Relocation
**learn:** What happens to your civic life when you move.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Start living somewhere else | You do not file a transfer. Association follows where you actually are. | Art. V §1–2 |
| 2 | Let the new association build | Sustained residence in the new place confirms there, and the old association lapses on its own. | WF-CIV-03 · CLK-05 |
| 3 | Keep your record | Your history stays with you. What changes is where you vote, not who you are. | Art. I |

> **The why:** the franchise follows the person, automatically. Nobody can strand you between two
> places, and nobody has to approve your move.

---

### 13. `civic/journeys` — Journeys
**learn:** Guided walkthroughs of how the world actually works — an election end to end, a bill
becoming law, a court case from filing to opinion.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Pick a journey | Each one is a real process in this app, broken into its actual steps. | — |
| 2 | Work through the steps | **These are a tour you tick off yourself, not a record of what you did.** They are here to explain, not to certify. | — |
| 3 | Finish one | Completing the arc records an achievement. **It grants nothing** — not a vote, not a seat, not a capability. | Art. I |

> **The why:** the app is a lot of machinery. Journeys are the map. They deliberately give you
> nothing, so that learning your way around can never become a requirement for participating.

---

### 14. `civic/journey` — A journey
**learn:** One walkthrough, step by step.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read the step | Each step names the real form or process behind it, so you can go and find it. | — |
| 2 | Tick it off when you understand it | Self-reported by design — nothing is checking up on you. | — |
| 3 | Finish the arc | The achievement lands on your record and freezes. **An achievement never changes a vote, a seat, or what you are allowed to do.** | Art. I |

---

## Wave 2 — `legislature` (12 surfaces)

### 15. `legislature/legislature-home` — Chamber
**learn:** The legislature for one place: who holds its seats, what it has adopted, and what it is
working on.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See who is seated | Seats come from the election, filled proportionally. Members appear with the organisations that endorsed them, if any — **running unendorsed is first-class**. | Art. II §1 |
| 2 | Take the oath if you were just elected | Seating is your own act; nobody swears you in. | F-LEG-001 |
| 3 | Read the house's own rules | A chamber adopts its rules of order and ethics code itself. | F-LEG-032 · F-LEG-033 |
| 4 | Watch what it creates | Legislatures create committees, election boards and administrative offices — the institutions everything else runs on. | F-LEG-009 · F-LEG-012 · F-LEG-013 |

> **The why:** a legislature is small on purpose. **Districts elect five to nine representatives
> each**; a legislature is *composed of* districts and scales by the cube root of population. Bigger
> places get more districts, not bigger rooms.

---

### 16. `legislature/session-console` — Session console
**learn:** A meeting while it is happening — attendance, quorum, the agenda, and every vote as it
is cast.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Register your attendance | Presence is recorded per member, per session. | F-LEG-002 |
| 2 | Watch quorum | Quorum is a majority of **all serving members** — not of whoever turned up. Empty chairs count against it. | F-SPK-003 · Art. II §2 |
| 3 | Move, speak, and vote | Motions, statements for the record, and floor votes all happen here and all land on the permanent record. | F-LEG-007 · F-LEG-006 · F-LEG-004 |

> **The why:** counting against *all serving members* is the whole trick. It means absence can never
> shrink the bar — you cannot pass something by emptying the room.

---

### 17. `legislature/bills` — Bills
**learn:** Every proposed law in this jurisdiction, and where each one has got to.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Browse what is proposed | Bills are public from introduction, not from passage. | Art. II §2 |
| 2 | Introduce one if you hold a seat | You write it; the chamber decides. | F-LEG-003 |
| 3 | Note what is fixed at introduction | A bill's **scale and scope are locked when it is introduced** — it cannot quietly grow into something else on the way through. | Art. V §4 |

---

### 18. `legislature/bill-detail` — Bill detail
**learn:** One bill: its text, its committee, its votes, and the exact threshold it must clear.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read the text and its history | Every version is kept. Nothing is overwritten. | Art. II §2 |
| 2 | Follow it through committee and floor | Committee vote first, then the floor. | F-LEG-005 · F-LEG-004 |
| 3 | Check the threshold it faces | Ordinary acts need a majority; some need two-thirds of all serving members. **The threshold comes from what the act does — it is never chosen by whoever is counting.** | Art. VII |

---

### 19. `legislature/committees` — Committees
**learn:** How the chamber splits its work up, and how members end up on the committees they are on.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See the committees and who sits on them | Created by a supermajority act of the chamber. | F-LEG-009 · Art. II §4 |
| 2 | Rank every committee in your order of preference | You rank them all. You are not assigned by anyone. | F-LEG-010 |
| 3 | Understand how placements fall out | **Assignment is faction-independent.** Placements honour rank order and spread evenly across members — counts differ by at most one. Ties break to the member with the largest vote share after normalising quotas. | F-SPK-005 · Art. II §4 |

> **The why:** this is the mechanism that keeps committees proportional **without a party layer**.
> Members endorsed by several organisations and members endorsed by none are treated identically —
> there is no faction to be sorted into.

---

### 20. `legislature/committee-detail` — Committee detail
**learn:** One committee at work — meetings, agenda, votes, and the report it sends back.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See when it meets and what is on the agenda | The chair calls the meeting and sets the agenda. | F-CHR-001 · F-CHR-002 |
| 2 | Follow the committee's votes | Committee members vote here before anything reaches the floor. | F-LEG-005 |
| 3 | Read the report | A committee refers a bill to the floor and files a report that becomes public record. | F-CHR-003 · F-CHR-004 |

---

### 21. `legislature/speaker-tools` — Speaker tools
**learn:** What the Speaker can actually do — which is less than most people assume.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Call sessions and set the agenda | The Speaker runs the meeting. | F-SPK-001 · F-SPK-002 |
| 2 | Publish quorum and minutes | Both are public acts, not internal bookkeeping. | F-SPK-003 · F-SPK-009 |
| 3 | Break a tie — and only a tie | **The Speaker does not hold an ordinary vote.** The casting vote exists solely to resolve a deadlock. | F-SPK-004 |
| 4 | Preside over a removal, never decide it | The Speaker chairs impeachment and censure proceedings; the chamber votes. | F-SPK-007 |

> **The why:** the chair runs the room, it does not rule it. Every power here is procedural.

---

### 22. `legislature/oversight` — Oversight & ethics
**learn:** How a legislature holds officeholders — including its own members — to account.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See open proceedings | Removal, impeachment, censure and expulsion all run through the chamber. | F-LEG-022 |
| 2 | Understand the bar | Removal takes a **supermajority of all serving members** — the same bar for everyone, in any office. **Removal parity** means no office is easier to remove from than another. | Art. II §3 · Art. VII |
| 3 | Watch vacancies | When a seat empties it is declared, and the count is re-run from the original ballots rather than a new election being called. | F-LEG-036 |

> **The why:** the countback is **universal** — every prior ballot counts, with no filtering
> anywhere in the procedure. The voters who elected the chamber decide the replacement.

---

### 23. `legislature/referendums` — Referendums
**learn:** When the legislature hands a decision back to the people, and what happens to the result.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See what has been delegated to a vote | The chamber can put a question to the jurisdiction. | F-LEG-023 · Art. II §6 |
| 2 | Check the threshold | **Derived from the act type, never editable.** Whoever writes the referendum cannot also choose how hard it is to pass. | Art. II §6 |
| 3 | See how a result can be changed | A referendum act can be modified or repealed — but only by a route the constitution fixes, not by ordinary rewriting. | F-LEG-034 |

---

### 24. `legislature/emergency-powers` — Emergency powers
**learn:** The most dangerous power in the constitution, and the three things that box it in.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See any active declaration | Active emergency powers are shown on **every page** of the app, not hidden here. | Art. II §7 |
| 2 | Know the grounds | **Disaster or invasion only.** The grounds are validated *before* the vote is taken, not argued about afterwards. | F-LEG-024 |
| 3 | Know the clock | **Ninety days, maximum.** Renewal is a fresh vote, never automatic, and the ceiling cannot be raised by any act. | F-LEG-025 · CLK-03 |
| 4 | Know who can stop it | A court can review an active declaration. | F-JDG-007 |

> **The why:** every historical failure of emergency powers is a failure of the exit, not the entry.
> So the exit is automatic: the clock runs whether or not anyone remembers to stop it.

---

### 25. `legislature/settings` — Constitutional settings register
**learn:** The dials a legislature is allowed to turn, the bounds they must stay inside, and the
ones nobody can turn at all.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read the current values | Election intervals, thresholds, term lengths — the amendable layer. | Art. VII |
| 2 | Change one by passing a bill | Settings move by ordinary act, inside locked bounds. | F-LEG-031 |
| 3 | Watch what gets refused | **An out-of-range value is rejected before the vote is even taken** — you cannot pass an unconstitutional setting and argue later. | Art. VII |

> **The why:** there are exactly two doors. Settings move by ordinary acts within fixed bounds; the
> hardened core changes only by a software release that passes the public constitutional checks.
> There is no third door, and no majority anywhere can open one.

---

### 26. `dev/legislature-kit` — Legislature component kit
**learn:** A development harness for checking components against fixtures. **Not product UI** — it
is not part of anyone's civic life and carries no education content.

*(No `howto`: dev surfaces are excluded from the education pass by design.)*

---

## Wave 3 — `electoral` (9 surfaces)

### 27. `elections/detail` — Election detail
**learn:** One election end to end: when it was called, who is standing, how it will be counted,
and when each stage closes.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read the schedule | Every deadline is published with the order that called the election, not decided as it goes. | F-ELB-001 |
| 2 | Note the finalist number | **How many candidates advance is fixed and published up front**, derived from the number of seats. Nobody adjusts it once they can see who is winning. | CLK-21 · Art. II §2 |
| 3 | Follow to certification | The board certifies the result; a recount or audit can be ordered on the record. | F-ELB-004 · F-ELB-006 |

---

### 28. `elections/candidacy-registration` — Candidacy registration
**learn:** How you put your name forward. There is nothing to qualify for.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Register your candidacy | If you are associated with the jurisdiction, you may stand. **No fee, no signatures, no endorsement, no approval.** | F-IND-011 · Art. I |
| 2 | Wait for validation | The board checks one thing: that you are actually associated here. It is a check, not a judgement. | F-ELB-002 |

> **The why:** Art. I gives the right to stand for office *"regardless of any characteristic except
> jurisdictional association."* The validator refuses any filing that tries to attach a fee or an
> eligibility condition to this form — the app will not let a legislature invent one.

---

### 29. `elections/candidate-profile` — Candidate profile
**learn:** What a candidate says for themselves, and who has backed them.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Publish your platform | Candidate platforms are **mandatory-public** — standing for power means saying what you would do with it. | F-CAN-001 · Art. I |
| 2 | Ask an organisation to endorse you | You request; the organisation decides. Its grant is always public. | F-CAN-002 |
| 3 | Withdraw if you change your mind | Standing down is your own act. | F-CAN-003 |

> **The why:** **endorsements inform, they never gate.** A candidate with no endorsements appears on
> the ballot exactly like any other — running unendorsed is first-class. Any organisation *or*
> individual can be an endorser on the record; there is no party layer and no slate.

---

### 30. `elections/open-ballot` — Open ballot
**learn:** The first round. Everyone standing appears; you mark everyone you would be content with.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Approve as many as you like | This is not a choice of one. Approve everybody you could live with. | Art. II §2 |
| 2 | Filter by who has endorsed them, if it helps | The filter offers organisations, "individual endorsers", or "no endorsements". **There is no party column, because there are no parties in the model.** | — |
| 3 | Understand what this round decides | Approval picks the finalists. It does not pick the winners. | CLK-21 |

---

### 31. `elections/ranked-ballot` — Ranked ballot
**learn:** The round that fills the seats. You rank the finalists; the count does the rest.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Rank the finalists in your true order | **Ranking your real favourite first can never hurt them.** There is no tactical reason to rank dishonestly. | Art. II §2 |
| 2 | Submit | Your ballot is separated from your identity at the moment it is cast. | F-IND-007 |
| 3 | Keep your receipt | You can check your ballot was included, without revealing what it said. | F-IND-007 |

> **The why:** seats are filled by single transferable vote with a Droop quota. A vote for someone
> who has already won, or who cannot win, **transfers** instead of being thrown away — which is why
> almost no vote is wasted and why the chamber ends up proportional without anyone sorting people
> into blocs.

---

### 32. `elections/results` — Results
**learn:** The count, round by round, and how to check it yourself.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read the rounds | Every transfer and elimination is shown. The count is not a black box that emits a winner. | Art. II §2 |
| 2 | Verify your own ballot | Check your receipt against the published set. | — |
| 3 | See who may observe | Observation and audit standing belongs to the **endorsing organisations and the candidates themselves**. | F-ELB-004 · F-ELB-006 |

---

### 33. `elections/board-console` — Election board console
**learn:** The working surface for the people who run an election — and the limits on what they can
touch.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Call the election and validate candidacies | Scheduling and validation are the board's core acts. | F-ELB-001 · F-ELB-002 |
| 2 | Draw or review districts | Boundaries are versioned; old plans are archived, never overwritten. | F-ELB-003 |
| 3 | Certify, audit, recount | Certification seats the winners automatically. Audits and recounts are filed acts on the public record. | F-ELB-004 · F-ELB-005 · F-ELB-006 |

> **The why:** a board runs the process and **cannot touch the count**. The finalist number is
> pre-published, the counting method is hardened in code, and certification is the trigger for
> seating rather than a decision about who won.

---

### 34. `elections/vacancy-countback` — Vacancy countback
**learn:** What happens when a seat empties mid-term — and why there is usually no by-election.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See the declared vacancy | A vacancy is declared on the record before anything else happens. | F-LEG-036 |
| 2 | Watch the countback | **The original ballots are re-run with the departed member removed as a candidate.** Every prior ballot counts, with no filtering anywhere in the procedure. | Art. II §5 |
| 3 | Know the fallback | If the countback cannot fill the seat, a special election runs within 90–180 days. | F-ELB-001 |

> **The why:** the people who elected that chamber already expressed a full ranking. Asking them
> again would let a different, smaller electorate decide — so the app asks their original ballots
> instead. That is what "universal" countback means.

---

### 35. `dev/electoral-kit` — Electoral component kit
**learn:** Development harness. **Not product UI** — excluded from the education pass.

---

## Wave 4 — `judiciary` (7 surfaces)

### 36. `judiciary/judiciary-home` — Judiciary home
**learn:** The courts for this jurisdiction: how they were created, who sits on them, and how they
are chosen.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See how this judiciary exists | A legislature creates it by supermajority act. | F-LEG-017 · Art. IV §2 |
| 2 | See how judges got there | Appointed by default, for **ten-year terms**. Nominations are consented to by the chamber. | F-LEG-021 |
| 3 | Note it can be converted | A judiciary can be made elected instead — but that takes a supermajority *and* the constituents' agreement. | F-LEG-018 |

> **The why:** appointment is the default because a judge who must campaign has an incentive to
> please. Ten-year terms and a hard floor of five judges per court are there for the same reason.

---

### 37. `judiciary/case-docket` — Case docket
**learn:** Every case before this court, and how a case gets in.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Browse the docket | Cases are public record. | Art. IV §4 |
| 2 | File a case | You can file yourself, or an advocate can file for you. | F-IND-017 · F-ADV-001 |
| 3 | Watch acceptance | The court classifies justiciability and severity **at acceptance** and assigns a panel. | F-JDG-001 |

---

### 38. `judiciary/case-detail` — Case detail
**learn:** One case from filing to judgment — panel, evidence, jury, ruling.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Follow the panel and the jury | Judges are assigned to a panel; jurors are summoned by order. | F-JDG-001 · F-JDG-002 |
| 2 | Read the filings | Motions, evidence and arguments are on the record as they are submitted. | F-ADV-002 · F-ADV-003 |
| 3 | Read the ruling | Opinions, sentences and warrants are all published acts. | F-JDG-003 · F-JDG-009 · F-JDG-010 |

> **The why:** you cannot be tried twice for the same thing. Double jeopardy is enforced by the
> engine, not by a judge remembering.

---

### 39. `judiciary/constitutional-challenge` — Constitutional challenge tracker
**learn:** How anyone can challenge a law — and the one place in the app where a court can change
the law directly.

| # | do | detail | cite |
|---|---|---|---|
| 1 | File a challenge | **Any inhabitant may file. There is no standing gatekeeper**, no fee, and no eligibility test beyond living here. | F-IND-016 · Art. IV §5 |
| 2 | Follow the finding | The court makes a constitutional finding and may recommend a remedy. | F-JDG-004 · F-JDG-005 |
| 3 | See the remedy applied | Where the path allows it, the court **edits the law itself** — and the full version history is preserved. | F-JDG-006 |
| 4 | See the legislature's answer | A chamber can override a judicial outcome by the constitutional route, not by ignoring it. | F-LEG-035 |

> **The why:** this is the deepest act in the app. It is deliberately open to *anyone* who lives
> there, because a constitution that only lawyers can invoke belongs to lawyers.

---

### 40. `judiciary/advocate-console` — Advocate console
**learn:** The working surface for advocates — the people who argue cases for others.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Register as an advocate | Registration confers R-21. The bar is open to residents. | F-IND-015 · Art. IV §4 |
| 2 | File and argue | Case filings, motions, evidence and briefs on behalf of a client. | F-ADV-001 · F-ADV-002 · F-ADV-003 · F-ADV-004 |

---

### 41. `judiciary/juror-view` — Juror view
**learn:** What you see if you are summoned, and what is expected of you.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read your summons | It names the order that created it. | F-JDG-002 · Art. IV §4 |
| 2 | Understand your position | **Jurors file nothing.** Your role is to hear and decide, not to submit forms. | — |

---

### 42. `dev/judiciary-kit` — Judiciary component kit
**learn:** Development harness. **Not product UI** — excluded from the education pass.

---

## Wave 5 — `executive` + `organizations` (12 surfaces)

### 43. `executive/executive-home` — Executive home
**learn:** The doing arm of a government: who carries out the laws, and who they answer to.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See how this executive exists | An executive starts as something the legislature **delegates**, not as a separate throne. | F-LEG-014 · Art. III §1 |
| 2 | See its shape | Either a **committee** of five or more with equal voting power, or a **single officeholder** with the top four runners-up seated automatically as advisors. | Art. III §2–3 |
| 3 | Remember who it answers to | It executes the laws; the legislature that created it can convert, constrain or remove it. | Art. III §1 |

> **The why:** the executive is the only branch that begins as a *loan* of someone else's power.
> Becoming directly elected takes a supermajority **and** the constituents' agreement.

---

### 44. `executive/departments` — Department registry
**learn:** The standing offices that actually do the work — and how each one came to exist.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Browse the departments | Each was created by an act of the legislature, not by executive fiat. | F-LEG-016 |
| 2 | See who governs each | Departments are run by a Board of Governors, seated on ten-year terms. | Art. II §9 · CLK-09 |

---

### 45. `executive/department-detail` — Department detail
**learn:** One department: its mandate, its governors, its rules and its reports.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read the mandate | A department can only act inside the scope its creating act gave it. | F-LEG-016 |
| 2 | See the governors and their terms | Ten years, in lockstep with judicial appointments — long enough to outlast the politics. | CLK-09 |
| 3 | Read the rules it has implemented | Rules are filed acts, on the record. | F-BOG-001 |

---

### 46. `executive/department-reporting` — Department reporting
**learn:** What departments must publish about themselves, and when.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read the filed reports | Reporting is a constitutional obligation, not a courtesy. | F-BOG-002 · Art. III §4 |
| 2 | Note what reporting is for | Transparency is the mechanism that makes a ten-year term safe. | Art. III §4 |

---

### 47. `executive/executive-actions` — Executive actions
**learn:** Orders and decisions the executive has issued — and the check that runs before any of
them can be.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read the issued orders | Every executive order is a public act from the moment it is issued. | F-EXE-005 |
| 2 | Note the pre-issuance check | **Scope is validated before an order can issue.** An order outside the executive's delegated authority is refused by the engine, not litigated afterwards. | Art. III §1 |
| 3 | See investigations and nominations | Departmental investigations and Board of Governors nominations are filed acts too. | F-EXE-004 · F-EXE-001 |

> **The why:** the dangerous executive act is the one nobody sees until it has already happened.
> Validating scope *before* issuance is what stops the order existing in the first place.

---

### 48. `organizations/org-registry` — Organization registry
**learn:** Every registered organization where you live — parties, businesses, nonprofits and
public companies, in **one open list**.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Browse the registry | One registry, one model, discriminated only by type. **There is no faction layer.** | Art. I · Art. III §5–6 |
| 2 | Start one | Anyone who lives here can. | F-IND-012 |
| 3 | See which are endorsing candidates | Endorsement, not membership, is the political-linkage metric. | F-ORG-002 |

---

### 49. `organizations/org-detail` — Organization profile
**learn:** One organization's public face — ownership, board, endorsements, and how to join.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read the profile | This is a public record. | Art. II §2 |
| 2 | Follow the endorsement handshake | A candidate requests (F-CAN-002); the agent grants (F-ORG-002), which is forced public and confers R-07 on the candidate. | Art. I; Art. II §2 |
| 3 | Join, or register as a worker | Membership and work are separate paths with separate consequences. | F-IND-013 · F-IND-014 |

> **The why:** **endorsement linkage feeds proportionality, never a faction layer.** The worker
> headcount feeds the co-determination scale. One organization model carries both.

---

### 50. `organizations/cgc-detail` — Common Good Corporation
**learn:** A company chartered to serve the public — and the one rule that makes it different from
every other company.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See its charter | Created by an act of a legislature, to serve a public purpose. | F-LEG-019 |
| 2 | **Read its intellectual property register** | Everything a CGC creates — patents, trade secrets, copyrighted works — is **universally and eternally in the public domain**. | Art. III §5 |
| 3 | Note what cannot happen | That status is permanent. It cannot be sold, licensed away, or reversed by any later act. | Art. III §5 |

> **The why:** this is the sharpest single clause in the constitution. A public-purpose company's
> work belongs to everyone, forever, with no mechanism anywhere to privatise it.

---

### 51. `organizations/board-elections` — Board elections
**learn:** How an organization's board is chosen — and why some seats are elected by workers.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See the seats and their classes | Owner-elected and worker-elected seats sit on the same board. | Art. III §6 |
| 2 | Run an election | Owner and worker elections are administered separately by the agent. | F-ORG-003 · F-ORG-004 |
| 3 | Note the chair | The chair is **classless** — the joint chair belongs to neither side. | Art. III §6 |

---

### 52. `organizations/co-determination` — Co-determination scaling
**learn:** The rule that gives the people who do the work a share of the decisions — and how it
scales with headcount.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See the current headcount and what it earns | Worker representation begins at **100 employees**. | Art. III §6 |
| 2 | See the parity threshold | At **2,000 employees**, worker and shareholder representation reach **parity**. | Art. III §6 |
| 3 | Note that it recomputes itself | The scale is recalculated on a clock as headcount changes. Nobody has to remember to apply it. | CLK-13 · CLK-14 |

> **The why:** this is automatic, and that is the point. Co-determination that depends on an owner
> choosing to grant it is not co-determination.

---

### 53. `organizations/transfers-conversions` — Transfers and conversions
**learn:** Changing who owns an organization, or changing what kind of thing it is.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Initiate a transfer of ownership | A filed act, on the record. | F-ORG-005 |
| 2 | Request a public↔private conversion | Conversion changes the rules that apply — including, for a CGC, the permanent public-domain status of what it has already created. | F-ORG-006 · Art. III §5 |
| 3 | Dissolve, if that is the end | Dissolution is also a public act. | F-ORG-007 |

---

### 54. `dev/executive-kit` — Executive & organizations component kit
**learn:** Development harness. **Not product UI** — excluded from the education pass.

---

## Wave 6 — `system` + `operator` (12 surfaces)

### 55. `system/audit-chain` — Audit chain
**learn:** *(existing live copy, reused verbatim)* The audit chain — every constitutional act,
hash-chained in order. Anyone can verify that nothing was quietly changed.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read the chain | Every filing — accepted **and rejected** — is an entry, in order. | Art. II §2 |
| 2 | Verify it yourself | Each entry carries the hash of the one before it. Changing any past entry breaks every entry after it. | — |
| 3 | Note what is *not* in it | Ballot contents are never chained. Participation is recorded; how you voted is not. | Art. II §2 |

> **The why:** tamper-**evidence**, not tamper-proofing. Nobody claims the past cannot be altered —
> the claim is that altering it cannot be hidden.

---

### 56. `system/public-records` — Public records
**learn:** *(existing live copy, reused verbatim)* The permanent public record — testimony, votes,
acts, and rulings, readable by anyone, editable by no one.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Search the record | Testimony, votes, acts and rulings. | Art. II §2 |
| 2 | Note what may never enter it | Ballots, envelopes, location pings and the private social graph are **structurally barred** from publication — refused at the single point where anything gets published. | Art. I; Art. II §2 |

---

### 57. `system/term-sync` — Term lockstep
**learn:** Why civil and judicial appointment terms move together, and why that matters.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See the two term lengths | Both ten years, and they must stay in lockstep. | Art. II §9 · Art. IV §1 |
| 2 | Understand the lock | Changing one without the other is refused. | Art. VII |

> **The why:** if one branch's terms could be shortened alone, whoever controlled that lever could
> time appointments to their advantage. Locking them together removes the lever.

---

### 58. `system/translations` — Translation status
**learn:** Which languages the app speaks, and how completely.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See coverage per language | Machine and human translation are marked differently, so you know what you are reading. | — |
| 2 | Note what is never translated | Form, role and clock identifiers stay identical in every language, so a citation means the same thing everywhere. | — |

---

### 59. `system/clocks` — The clocks
**learn:** *(existing live copy, reused verbatim)* The scheduled sweeps that drive the world — every
interval, deadline, window, and threshold that starts a process without anyone asking. Clocks hold
no state; they move other things.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read what each clock does | Twenty-one clocks, each with a stated cadence and a stated effect. | — |
| 2 | Note the ones with constitutional ceilings | The emergency-powers clock cannot be extended past ninety days by any act. | CLK-03 · Art. II §7 |
| 3 | Understand why clocks exist | **A deadline that depends on someone remembering is not a deadline.** | — |

---

### 60. `system/amendments` — Amendments
**learn:** *(existing live copy, reused verbatim)* The constitution changes through exactly two
doors: settings move by ordinary acts within locked bounds, and the hardened core changes only by a
software release that passes the public constitutional checks.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See what is amendable | The settings register, inside fixed bounds. | F-LEG-031 · Art. VII |
| 2 | See what is hardened | The counting method, the supermajority formula, the rights gates. **No majority anywhere can move these by act.** | Art. VII |
| 3 | Note there is no third door | Anything that is not one of these two is not a route. | Art. VII |

---

### 61. `operator/home` — Operator home
**learn:** Running a node — the volunteer servers the world runs on. **Keeping it online buys no
vote and no seat.**

| # | do | detail | cite |
|---|---|---|---|
| 1 | See your node's state | Health, storage, and what it is serving. | — |
| 2 | Understand the boundary | Operating the infrastructure confers **no governance power whatsoever**. | Art. I |

> **The why:** whoever holds the keys always *could* be powerful. So the app puts nothing behind
> that door — no role, no vote, no seat — and says so out loud on the operator's own home screen.

---

### 62. `operator/console` — Mesh console
**learn:** The controls for the node you run, and the acts that are logged when you use them.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Operate the node | Service state, jobs and infrastructure. | — |
| 2 | Note what is on the operator plane, not the constitutional one | Operator acts are logged as operator acts. They are never constitutional filings and never enter the governance chain. | Art. I |

---

### 63. `operator/roles` — Mesh roles
**learn:** Trust between nodes, expressed as specific capabilities rather than blanket authority.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See what each peer is trusted for | Trust is composable — a peer trusted for one channel is not trusted for all. | — |
| 2 | Grant or revoke a channel | Each is a separate, reversible decision. | — |

---

### 64. `operator/mesh` — Peers & transports
**learn:** Which other instances this node talks to, and how.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See connected peers | Federation is between whole governments, not between users. | — |
| 2 | Note what crosses and what does not | Public records and earned achievements travel. Ballots, envelopes, education progress and the private social graph **never** do. | Art. I; Art. II §2 |

---

### 65. `operator/identity` — Node identity
**learn:** How this instance proves it is itself to the rest of the mesh.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See the node's identity and keys | Identity is how Full Faith and Credit works — a peer must know whose record it is trusting. | — |
| 2 | Note the separation | Node identity is not any person's identity. | Art. I |

---

### 66. `operator/versioning` — Versioning
**learn:** Which version of the constitutional code this instance is running, and why that is a
public fact.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See the running version | The hardened core lives in code, so *which code* is a constitutional fact. | Art. VII |
| 2 | Understand the second door | This is the only route by which hardened rules change — a release that passes the public constitutional checks. | Art. VII |

---

## Wave 7 — `jurisdictions` (4 surfaces)

### 67. `legislature/index` — Legislatures
**learn:** Every legislature you can see from here, at every level of nesting.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Browse the legislatures | A place can have one at every scale — neighbourhood to planet. | Art. V §1 |
| 2 | Note the sizing | **Districts elect five to nine representatives each.** A legislature is *composed of* districts and scales by the cube root of population — bigger places get more districts, not bigger rooms. | Art. II §2 |

---

### 68. `legislature/overview` — A legislature
**learn:** One legislature in context: its jurisdiction, its districts, its seats and its term.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See its jurisdiction and districts | Seats are apportioned across the districts that make it up. | Art. V §1 |
| 2 | See its term and next election | Terms and intervals come from the settings register, inside constitutional bounds. | Art. VII |

---

### 69. `legislature/districts` — The district mapper
**learn:** The map itself — how the seats of a legislature are distributed across real places.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Read the active map | District plans are **versioned**: adopting a new one archives the old, never overwrites it. | Art. II §2 |
| 2 | See seats per district | Each district elects between five and nine. | Art. II §2 |
| 3 | Note how boundaries change | Drawing is a filed act by the election board, on the public record. | F-ELB-003 · F-ELB-008 |

> **The why:** versioned maps mean a boundary change can always be compared against what it
> replaced. A redistricting you cannot see the before-and-after of is not reviewable.

---

### 70. `jurisdictions/viewer` — A jurisdiction
**learn:** One place: its boundaries, its population, its institutions, and whether its government
has woken up yet.

| # | do | detail | cite |
|---|---|---|---|
| 1 | See the place and its nesting | Every jurisdiction sits inside others and may contain others. | Art. V §1 |
| 2 | See its institutions | Legislature, executive, judiciary — each present or not. | Art. V §1 |
| 3 | Check whether it has activated | A government boots when enough real residents have confirmed there. **Until then the scaffolding exists but nothing governs.** | Art. V §1 |

> **The why:** every place on Earth exists in the app from the start, but none of them govern until
> actual people live there and say so. Institutions follow residents, never the other way round.

---

## Things these waves surfaced

1. **A real distinction worth teaching deliberately, not glossing.** `civic/public-square` is
   **open to anyone signed in** (`F-SOC-001` carries `roles => []`, per the 2026-06-27 operator
   correction, pinned by `PublicSquareTest`), while `civic/commons-square` — the *live Matrix* room
   — is **residency-only**. Two squares, two rules. Both entries above state it explicitly rather
   than letting a reader generalise from one to the other and get it wrong.

2. **Shipped copy said "medal".** `config/cga/surfaces.php` carried *"a medal grants nothing"* and
   *"A medal never changes a vote…"* on three lines of user-facing citation text. The operator said
   the word means nothing to him — **swapped to "achievement"** in the same pass. No behaviour
   change; the constitutional claim is identical.

---

## Remaining waves

| Wave | Module | Surfaces | Items |
|---|---|---|---|
| ✅ 1 | `civic` | 14 | 28 |
| ✅ 2 | `legislature` | 12 | 24 |
| ✅ 3 | `electoral` | 9 | 18 |
| ✅ 4 | `judiciary` | 7 | 14 |
| ✅ 5 | `executive` · `organizations` | 12 | 24 |
| ✅ 6 | `system` · `operator` | 12 | 24 |
| ✅ 7 | `jurisdictions` | 4 | 8 |
| | **Total — COMPLETE** | **70** | **140** |

**Next for this content, in order:** (1) @operator reads a sample and rules on voice before it is
moved anywhere; (2) the `learn` + `howto` keys move **server-side onto the `surfaces.php` record**
(`K2_CURRICULUM.md` §9) so the client registry stops being a hand-maintained second copy;
(3) @lane-05 extracts the 621 keys into `c_education.json` + `c_achievements.json`.
