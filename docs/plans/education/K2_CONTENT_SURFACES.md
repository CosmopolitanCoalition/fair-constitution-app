# K2 — Per-Screen Education Content (authored)

**Owner:** Lane 15 (Phase K-2) · **Deliverable:** D-16c authoring pass · **Started:** 2026-07-25
**Authority:** operator approved the §9 template in `K2_CURRICULUM.md` (2026-07-25) — *"I'm pretty
sure we're already there."* Plus: **in-app education is PER SCREEN — "it's all about every screen.
So it's the shape for everything."**

**Volume:** 70 surfaces × 2 halves = **140 items**. Delivered in waves by module.
**Wave 1 — `civic` (14 surfaces / 28 items) — COMPLETE below.**

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

## Two things this wave surfaced

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
| 2 | `legislature` | 12 | 24 |
| 3 | `electoral` | 9 | 18 |
| 4 | `judiciary` | 7 | 14 |
| 5 | `executive` · `organizations` | 12 | 24 |
| 6 | `operator` · `system` | 12 | 24 |
| 7 | `jurisdictions` | 4 | 8 |
| | **Total** | **70** | **140** |
