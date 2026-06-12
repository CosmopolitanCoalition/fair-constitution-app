All source material is extracted. Here is the complete contract report.

# CGA Mockup Frontend Data Contracts — LEGISLATURE & EXECUTIVE Domains

Sources: `E:/fair-constitution-app/mockups/legislature/*.html` (11 screens), `E:/fair-constitution-app/mockups/executive/*.html` (5 screens), `E:/fair-constitution-app/mockups/flows/WF-LEG-01..20.html`, `WF-EXE-01..09.html`, `E:/fair-constitution-app/mockups/assets/js/fixtures.js` (registry + world blocks).

---

## 1. Shared page contract (every screen)

Every screen declares a `window.CGA_PAGE` envelope and consumes `window.CGA.fixtures` (`registry`, `world`, `byId` index) plus `window.CGA.state` (demo-state scenario toggles):

| CGA_PAGE field | Type | Notes |
|---|---|---|
| `id` | `"module/page"` slug | e.g. `legislature/session-console` |
| `module` | `legislature` \| `executive` | nav grouping |
| `nav` | string | active nav key |
| `roles` | `R-xx[]` | roles that can use the surface |
| `workflows` | `WF-xxx[]` | workflows the surface implements |
| `forms` | `F-xxx[]` | forms surfaced on the page |
| `citation`, `flow`, `register` | strings | constitutional basis, primary flow, copy register |

Scenario toggles read via `CGA.state.getAll().scenario`: `emergency`, `quorumFails`, `challenge`, `bicameral`, `countbackFailed`. Demo "today" is `2031-06-11` (UTC). Convention everywhere: timestamps "shown in your timezone (UTC−5 · America/New_York) · stored as UTC".

### Fixture entities consumed by these two domains (`world.*`)

- **`world.chamber`** — `{ jurisdiction, seats: 9, serving: 8, quorum: 5, quorumGloss, supermajority: 6, supermajorityGloss ('ceil(serving × 2/3)'), termEnds: '2035-11-01', nextSessionDue: '2031-06-23', members[] }`. Member: `{ persona | name, seat, endorsedBy: orgId[], speaker?, voteShareNorm, daysServed, vacant?, note? }`. Seat 4 vacant (countback running). Seniority = `daysServed`, tie-break `voteShareNorm`.
- **`world.committees`** — `{ id, name, seats: 3, chair, alternate, members[] (persona-id or literal name, may carry '(vacated)'), bills?: billId[], note? }` × 3 (com-env, com-budget, com-safety). Comment: 9 placements = 3 committees × 3 seats; per-member share = 9 ÷ (3 × 3) = 1.
- **`world.bills`** — only one fixture bill: `{ id: 'bill-2031-07', title, jurisdiction, state: 'In committee', committee, sponsor (persona id), scale, scope, introduced }`. All other registry rows are page-local.
- **`world.emergency`** — `{ jurisdiction: 'usa-2-new-york', cause: 'natural disaster', label: 'Hurricane Dorinda landfall', day: 41, maxDays: 90, clock: 'CLK-03', invokedVia: 'F-LEG-024', renewalForm: 'F-LEG-025', judicialReview: 'pending', protections }`.
- **`world.departments`** — `{ id, name, kind: chief_executive|treasury|justice|other, governors, workers, coDetermination?, workerSeats?, charter }` × 5 (Chief Executive Office, Treasury 152w/1 worker seat, Public Works & Utilities 1240w/4 worker seats + 7 governors, Justice Administration, Emergency Management).
- **`world.vacancy`** — `{ office, member, declaredVia: 'F-LEG-036', status: 'countback-running', gloss }`; **`world.specialElection`** — `{ trigger: 'countback-exhausted', windowDays: [90,180], clock: 'CLK-04', scheduled, jurisdiction, office }`.
- **`world.challenge`** — feeds the session agenda's "constitutional matters" slot: `{ name, law, judge, finding, remedy, timeframeDays: 60, vetoWindowDays: 30, vetoCloses: '2031-06-20', paths {A,B,C}, clockTimeframe: 'CLK-12', clockVeto: 'CLK-11' }`.
- **`world.organizations`** — endorsement chips and grant applicants; `{ id, name, type: political_party|business|nonprofit|common_good_corp|informal, endorsementCount?, workers?, ownership?, flags? }`. No faction layer anywhere.
- **`world.personas`** — actor records `{ id, name, initials, roles: R-xx[], home, advisorRank? }`. R-17 advisors carry `advisorRank: 1..4`.
- **`registry.entities`** — named state machines rendered live on screens: `Bill`, `Motion`, `Committee Seat`, `Referendum Question`, `Emergency Powers`, `Vacancy`, `Executive Office`, `Department / Board`.
- **`registry.forms`** — 103 canonical form cards `{ id, name, desc, availableTo, availableToRaw, prereq, creates, basis, aliases[] }`. Domain prefixes: F-LEG (36), F-SPK (9), F-CHR (4, catalog alias F-COM), F-EXE (5), F-BOG (2, catalog alias F-GOV).
- **`registry.clocks`** — CLK-01 (election interval 5y), CLK-02 (90-day meeting deadline), CLK-03 (emergency 90-day countdown), CLK-04 (special election 90–180d), CLK-07/08 (max 9 / min 5 seats), CLK-09 (10-yr civil/judicial term), CLK-10 (term lockstep, non-amendable), CLK-19 (referendum act protection, hardened).
- **`registry.voteTypes`** — full threshold matrix (see §3).

---

## 2. Per-screen contracts

### 2a. Legislature screens

| Screen | Roles | Workflows | Forms | Data consumed | Page-local state (backend must own in real app) |
|---|---|---|---|---|---|
| **legislature-home** ("Chamber") | R-09..13, R-29 | WF-LEG-01, 02 | F-LEG-001, 008, 032, 033, 013, 012, 009 | `chamber` (seat map SVG, roster, quorum/supermajority stats, termEnds countdown vs DEMO_NOW), `byId.forms`, `byId.organizations`, `vacancy` | `FIRST_SESSIONS` checklist (7 steps with done-dates + act numbers: oath → Speaker (supermajority RCV 7 of 9) → rules Act 2030-01 → ethics Act 2030-02 → admin office Act 2030-03 → election board Act 2030-04 → committees Acts 2031-01…03) |
| **session-console** | R-09, R-10 | WF-LEG-05, 09, 20, WF-SYS-02 | F-SPK-001, 002, 003, 008, 009; F-LEG-002, 004, 006, 007 | `chamber` (serving members for attendance), `emergency` (banner + agenda item 1), `challenge` (agenda item 2), scenario `emergency`/`quorumFails` | `sessionOpen`, `adjourned`, `absent{}` map, `compelled`, `motions[]` `{text, state, tally}` (e.g. "4–4 → Speaker broke the tie (F-SPK-004)"); statement submission; session-due banner ("due in 12 days", CLK-02) |
| **bills** | R-09..13 | WF-LEG-06, 07, 14 | F-LEG-003, F-LEG-028 | `bills[0]` fixture, `byId.entities['Bill'].states` (lifecycle legend), `challenge.vetoCloses`, `S.activePersona()` | `bills[]` registry rows `{id, title, sponsor, state, tone, icon, scale, scope, introduced, note, detail?, challengeLink?}` covering states: In committee / On floor / Tabled / Enacted·Published / Challenged. Intro form fields: title, law text, **scale** (jurisdictions bound), **scope** (judiciary level), **act type** (threshold class) |
| **bill-detail** | R-09..12 | WF-LEG-06, 07 | F-LEG-004, F-LEG-005 | `byId.entities['Bill']`, scenario `bicameral` | `CURRENT = 'In-Committee'`; committee meter (1 of 3, needs 2 of 3); floor meters (6 projected yes of 9; majority gate 5/9 tick at 56%; supermajority gate 6/9 tick at 67%); bicameral dual-tally preview (committee A: 2/3, B: 2/2; floor A: 4/6, B: 2/3; need = `floor(total/2)+1` of all serving of that kind) |
| **committees** | R-09, 10, 11, 13 | WF-LEG-03, 04, 13 | F-LEG-009, 010, F-SPK-005, F-LEG-011 | `committees`, `chamber.members.voteShareNorm` (tie-break table), `byId.organizations` | `CREATION` act map (`'Act 2031-01 · supermajority 7–1'` etc.), `prefs[]` rank order (keyboard-reorderable, no drag), `submitted` flag |
| **committee-detail** | R-11, 12, 13 | WF-LEG-08, 13 | F-CHR-001..004, F-LEG-005 | `committees[0]`, `chamber.members` (org chips) | `votes{}` per-member yes/no (chair pre-voted yes), `referred` gate (refer-to-floor enabled only when 2-of-3 passes), `TESTIMONY[]` `{who, text}`, report filing |
| **speaker-tools** | R-10 | WF-LEG-02, 05, 17, 20 | F-SPK-001..009 | `byId.forms` (all 9 F-SPK cards with availableTo/basis/aliases) | tie-break record (1 this term: 4–4 → yes → adopted 5–4), `priorities[]` `{who, text, when}` queue |
| **oversight** | R-29, R-09, R-10 | WF-LEG-16, 17, 12 | F-LEG-022, F-LEG-036 | `vacancy`, scenario `countbackFailed` | `investigations[]` `{id INV-2031-NN, subject, re, state: Intake|Investigating|Referred to proceeding|Closed — no finding}`, removal-vote simulation `removalYes=5`, `SERVING=8`, `NEED=6 (=ceil(8×2/3))`; vacancy state strip (current index 2 = Countback-Running, or 4 = Special-Election-Scheduled when scenario flips) |
| **referendums** | R-09, R-10 | WF-LEG-10, 19, WF-ELE-07 | F-LEG-023, F-LEG-034 | — (all page-local) | `queue[]` `{q, threshold, via}`; `RESULTS[]` `{title, threshold: majority|supermajority, yesPct, tick, note, shielded?}`; threshold field is **derived from act type, never editable**; modify buttons (majority act enabled; population-supermajority act `disabled` + "Blocked this term · CLK-19") |
| **emergency-powers** | R-09, R-10 | WF-LEG-11, WF-JUD-06, WF-LEG-05 | F-LEG-024, F-LEG-025, F-JDG-007 | `emergency`, scenario `emergency` | invoke form: cause enum (`disaster`/`invasion` ONLY), duration number input with pre-vote validation (1–90, error banner "Rejected pre-vote: exceeds the 90-day constitutional ceiling"), area select (whole jurisdiction / named area — "≤ this legislature's authority"), methods textarea ("within constitutional order"); renewal panel ("Renewal window opens day 76 · fresh supermajority · max 90-day extension"); judicial review panel (pending / none) |
| **settings** | R-09, R-10 | WF-LEG-14, 15 | F-LEG-031, 032, 033 | — | `SETTINGS[]` register of **17 amendable keys** `{key, value, meta, bounds, basis, act, min?, max?}` (election_interval_months 60; voting_method stv_droop "only MORE proportional"; legislature_min/max_seats 5/9; special_election_min/max_days 90/180; supermajority_numerator/denominator 2/3 "never below majority+1"; max_days_between_meetings 90; emergency_powers_max_days 90; civil/judicial_appointment_years 10 lockstep (set by Act 2031-14); residency_confirmation_days 30; initiative_petition_threshold_pct 5.00; judiciary_is_elected false; worker_rep_min/parity_employees 100/2000). "Propose change" → pre-targeted bill + live bounds validator (in-range → proceed to bill flow; out-of-range → "Rejected pre-vote … no UI, admin panel, or legislative act can carry an out-of-range value") |

### 2b. Executive screens

| Screen | Roles | Workflows | Forms | Data consumed | Page-local state |
|---|---|---|---|---|---|
| **executive-home** | R-14..17 | WF-EXE-01, 02, 03 | F-LEG-014, F-LEG-015 | `chamber.members` (delegated committee = 5 sitting legislators by seat), `byId.personas['ingrid-solberg']`, `world.personas` filtered `R-17` sorted by `advisorRank`, `byId.entities['Executive Office']`, scenario `emergency` | `model` toggle (`delegated` live NY County / `elected-committee` illustrative / `elected-individual` live NY State); creation act meter (7 of 8 serving, threshold 6 = ceil(8×2/3)); conversion act DUAL meters (state legislature 8 of 9, threshold 6; constituent jurisdictions 51 of 62 counties, threshold 42 = ceil(62×2/3)); term lockstep card (ends 2035-11-01) |
| **departments** | R-14..16, R-30 | WF-EXE-04, 05, 06 | F-LEG-016, F-EXE-001, F-LEG-020, F-EXE-003 | `world.departments` (registry table with co-determination cell: ≥100 workers → worker-seat badge + CLK-13 citation), `byId.forms` | create-department form (name, type enum chief_executive/treasury/defense/state/justice/other, **oversight assignment** select, charter); BoG pipeline stepper (Nomination F-EXE-001 → Consent F-LEG-020 → Seated R-18); nominee table (Adeyemi seated "consent 6 of 8 serving", term 2030-07-01→2040-07-01; Patel consent scheduled); removal sim ("ordinary majority of all serving — hiring and firing"); civil officer card (Grace Mwangi R-30, 10y CLK-09) |
| **department-detail** (Public Works & Utilities) | R-14..16, R-18, R-30 | WF-EXE-04, 05, 06 | F-EXE-001, F-EXE-003 | `byId.entities['Department / Board']` (state strip; current = Operating, shifts to `[Member Removal Requested` on sim) | static board roster: 7 appointed governors (10-yr terms 2030-07-01→2040-07-01; one expiring 2021→2031 "renomination open"; chair = joint-elected by entire board) + 4 worker-elected members (**terms 2030-07-01→2035-11-01, i.e. ending with the legislative term**); charter card (Act 2030-11, quarterly reporting, oversees Manhattan Water & Power CGC); nomination dossier sim → F-LEG-020 consent; removal sim |
| **department-reporting** (governor surface) | R-18 | WF-EXE-09 | F-BOG-001, F-BOG-002 | `byId.forms` (canonical F-BOG with F-GOV catalog aliases) | `rules[]` `{id PWU-R-YYYY-NN, name, act (enabling act + href), status: in-force|draft|superseded, note?}` — enabling acts include a bill AND the emergency declaration ("expires with the emergency power · CLK-03"); report filings table `{report, recipients: 'Executive + legislature', due, status: Filed|Due soon|Due}` (Q1 filed 2031-04-15, special Dorinda report due 2031-06-30, Q2 due 2031-07-15); "Rules implement — they cannot exceed — the charter and enabling acts" |
| **executive-actions** | R-14..16 | WF-EXE-07, 08 | F-EXE-005, F-EXE-002, F-EXE-004 | `world.organizations` (grant applicant select), `world.emergency`, scenario `emergency` | `orders[]` register `{id EO-2031-NN, title, dept, when, status: issued|rejected, note}` — includes a **rejected pre-issuance** order ("Defer the ranked-window opening… elections cannot be disrupted (Art. II §7), outside delegated scope (Art. III §2)"); order form with demo scope select (in/out of delegated scope → engine issues or rejects pre-issuance; rejection itself goes on public record); policy proposal card ("the board adopts, amends, or declines — proposals do not bypass the board"); investigation card; grants & appropriations table `{line, act, appropriated, remaining}` + application form (org, amount, purpose) → "audit-chained · WF-SYS-04". Order lifecycle (about-panel): Drafted → Scope-Validated → Issued \| Rejected-pre-issuance → [Judicially Reviewed] |

---

## 3. Voting / quorum / threshold mechanics (as encoded)

**Peg quorum (hardened):** all denominators are **all serving members, never those present**. Chamber: 9 seats, 8 serving (seat 4 vacant), quorum 5, supermajority `ceil(serving × 2/3)` (= 6 at both 8 and 9 serving; oversight page computes `NEED=6` from `SERVING=8`). An absent member counts the same as a no. Quorum failure → F-SPK-008 compulsion order (WF-LEG-20: quorum reached → resume; not reached → adjourn + reschedule within CLK-02; repeated failure → I-ADM referral).

**Threshold classes (bills.html act-type select + registry.voteTypes):**

| Class | Threshold | Examples |
|---|---|---|
| Ordinary act | Majority of all serving (5 of 9) | bills, BoG consent (F-LEG-020), governor removal (F-EXE-003 → floor vote), department creation (F-LEG-016) |
| Amendable-setting change | Majority + **pre-vote hardened range validation** (F-LEG-031 / WF-LEG-14) | constitutional_settings keys |
| Supermajority-class | `ceil(serving × 2/3)` of all serving | Speaker elect/replace (RCV), committee creation, exec delegation F-LEG-014, exec office creation F-LEG-015, judiciary creation/conversion, referendum delegation F-LEG-023, emergency invoke/renew F-LEG-024/025, removal/impeach F-LEG-022, judiciary override F-LEG-035, referendum-act modification same term F-LEG-034 |
| Dual supermajority | legislature supermajority **+** supermajority of constituent jurisdictions (independent meters) | F-LEG-015 conversion, F-LEG-018 judiciary conversion, F-LEG-028 cultural institution, WF-EXE-03 office modification (constituent supermajority **only**) |
| Committee vote | Majority of **all committee members** (2 of 3) | F-LEG-005 |
| Bicameral (Art. V §3) | both seat kinds (type A population-apportioned, type B equal-apportioned) must independently agree — `floor(total/2)+1` of all serving **of each kind** — at committee AND floor; failure in either kind at either stage fails the act | WF-LEG-07 dual-tally engine (hardened) |

**Speaker:** politically neutral; votes **only** to break ties (F-SPK-004); presides over removals except own case (F-SPK-007); elected/replaced by supermajority RCV (F-LEG-008, WF-LEG-02 — no supermajority → re-ballot per rules).

**Session order of business (hardened, F-SPK-002):** position 1 = outstanding emergency powers (locked), position 2 = constitutional matters (locked, fed by `world.challenge` veto-window items), position 3 = general agenda (Speaker-ordered: committee reports, member priorities F-SPK-006, motions, statements). WF-LEG-05: call F-SPK-001 → attendance F-LEG-002 → quorum F-SPK-003 (branch to WF-LEG-20) → emergency → constitutional → general → adjourn + minutes F-SPK-009 (resets CLK-02). Motion machine: Submitted → Seconded/Recognized → Debated → Voted → Adopted | Failed.

---

## 4. Bill lifecycle

Entity states (registry, rendered as legend/tracker): **Introduced → Referred → In-Committee → Reported | Tabled → On-Floor → [Amended] → Passed | Failed → Enacted → Published → [Challenged] → [Edited per Art. IV §5] → Repealed/Superseded**.

Contract points:
- **Introduction (F-LEG-003)** requires: title, law text, **scale** (which jurisdictions are bound — cannot exceed the legislature's authority; a parent-level act may bind named constituent jurisdictions), **scope** (which judiciary level hears disputes — county vs state court select), **act type** (sets the threshold class). Scale/scope fixed at introduction per Art. V §4.
- Referral (F-LEG-007 by R-10/R-09; branch committee path vs direct floor) → committee hearing WF-LEG-08 (F-CHR-001 call, F-CHR-002 agenda, testimony to public record, F-LEG-005 vote, F-CHR-003 referral gate enabled only after committee vote passes, F-CHR-004 report) → floor debate/amendments via motions → floor vote F-LEG-004 → enactment by System: **version law, link any settings changes, publish (WF-SYS-03), set effective date, open to Art. IV §5 challenge**. Failed bills archived **with votes/explanations**.
- WF-LEG-14 (setting-targeted bill): Constitutional Engine validates value vs hardened min/max **pre-vote**; on enactment writes the setting with act linkage + effective date; "all dependent clocks/engines re-derive automatically" (`jurisdiction_settings` is the cited store name in flow text — note the shipped DB calls it `constitutional_settings`).

---

## 5. Committee assignment + chair forms

- **Creation:** F-LEG-009 per committee (scope, seat count, purpose), supermajority each (fixture acts 2031-01…03 with tallies 7–1, 6–2, 8–0).
- **Allocation formula:** per-member share = Total reps ÷ (Committees × seats per committee); total committee seats = placements to fill (9 = 3×3 → share 1). Multi-org-endorsed and endorsement-less members are first-class — no faction layer.
- **Preferences:** F-LEG-010 ranked ballot (keyboard up/down rank list, default = committee order, submit → "input to the assignment algorithm").
- **Run:** F-SPK-005 (Speaker administers). **Tie-break = largest STV vote share after normalizing quotas** (`chamber.members[].voteShareNorm`; demo: Chen 1.08 beats Okonkwo 0.99 for last Environment seat; loser's next preference honored). Flow text alternative phrasing: "ties by prior-election 1st-choice then subsequent-rank performance".
- **Chairs:** F-LEG-011 — chair (R-12) + alternate (R-13 = top runner-up) per committee by **whole-legislature single-winner RCV**.
- **Committee Seat state machine:** Allocated (formula) → Preferences-Submitted → Assigned | Tie-Broken → Seated → Vacated → Refilled (whole-legislature RCV, proportion-safe) — WF-LEG-13 for mid-term vacancy/new committee; proportionality re-check pending the chamber countback is surfaced as `committees[2].note`.

---

## 6. Emergency powers lifecycle (90-day clock + renewal)

State machine: **Invoked (supermajority) → Active (duration/area/methods defined) → [Under Judicial Review] → [Renewed ≤ max] → Expired | Struck | Narrowed**.

- **Invoke (F-LEG-024, supermajority):** cause enum is closed — `natural disaster` | `actual invasion` only ("Economic, political, or public-order rationales are rejected pre-vote"). Engine validations all pre-vote: duration 1–90 days (CLK-03 ceiling, inline field error), area ≤ the legislature's jurisdictional authority, methods within constitutional order.
- **Active:** CLK-03 countdown (fixture: day 41 of 90, auto-expiry date displayed, "no action required"); first order of business at every session (locked agenda slot); banner propagates to session-console, executive-home, executive-actions; the fixture power is declared at the **state** level and the county sees it because it lies inside the area of effect.
- **Renewal (F-LEG-025):** fresh supermajority, fresh ≤90-day maximum, "nothing rolls over silently"; UI exposes a renewal window ("opens day 76"). Flow branch: renewed → new countdown; not renewed → auto-expire step.
- **Judicial review (F-JDG-007 / WF-JUD-06):** available at any time, on challenge or court's own motion, by any inhabitant; outcomes upheld | narrowed | struck. Fixture review pending at full court.
- **Hard rails (hardened):** civic-process protection — elections, sessions, courts cannot be disrupted, "enforced in code" (an executive order attempting election deferral is rejected pre-issuance); auto-expiry publishes a full audit record; the 90-day ceiling is itself the amendable setting's constitutional maximum.
- Cross-domain hook: a department implementation rule can cite the emergency declaration as its enabling act and **expires with the power**.

---

## 7. Referendum delegation

State machine: **Delegated/Queued → Scheduled → Voted → Passed (matching threshold) | Failed → Law → [supermajority-shielded same term] → ordinary law after general election**.

- **Delegate (F-LEG-023, supermajority):** question text + act type. **The population threshold is derived from the act type and is not editable** (majority-class → majority of population; supermajority-class → 2/3 of population). Question queues to the **next jurisdiction-wide ballot** (WF-ELE-07); individual votes cast via F-IND-008.
- **Modify/repeal (F-LEG-034 / WF-LEG-19):** same-term modification needs supermajority; acts passed by **population supermajority are completely shielded this term** (hardened gate CLK-19, button disabled); after the next general election all referendum acts convert to ordinary law (protection lapse is wired into WF-LEG-18 step 3).
- Citizen petitions reach the same ballot via WF-CIV-06 (threshold = `initiative_petition_threshold_pct`, CLK-17).

---

## 8. Oversight, removal, vacancy

- **I-ADM (admin office, R-29):** politically neutral, created by F-LEG-013; enforces rules of order + ethics code (F-LEG-032/033). Misconduct intake from any resident/member/own motion; docket entries `{id, subject, re, state}`; findings published; outcomes: refer to WF-LEG-17 | refer to courts | close.
- **Removal (F-LEG-022, alias F-LEG-034 in catalog):** supermajority of all serving; **removal parity** — legislators, executives, judges removed by the same standard; Speaker presides except own case; expulsion triggers the vacancy workflow.
- **Vacancy (F-LEG-036, alias F-LEG-030):** Detected → Declared → Countback-Running → Filled | Countback-Failed → Special-Election-Scheduled (90–180d, CLK-04) → Filled. Countback = prior election's ballots re-run with the vacated member removed, **universal, no faction filtering**; committee proportionality re-checked on refill (WF-LEG-13). Vacancies join the chamber at the junior-most seat position.
- **Contrast:** governor (R-18) removal is **ordinary majority** ("hiring and firing — supermajority applies only where the constitution states it") — deliberately different from officeholder removal.

---

## 9. Executive: models, departments, actions, reporting

**Executive Office state machine:** Delegated (committee) → [Conversion-Voted] → Elected-Office (committee 5+ STV | individual RCV+4 advisors) → [Modified by constituent supermajority] → Dissolved/Reverted. **Terms always equal the legislative term (CLK-10, hardened).**

- **Delegated committee (default, WF-EXE-01):** F-LEG-014 supermajority act with explicit delegated scope; members chosen **proportionally from the legislature by the same method as legislative committees**; they remain seated legislators (Westminster framing). Fixture: 5 of the 9 NY County members.
- **Elected committee:** 5+ officers, equal power, one PR-STV/Droop count (illustrative only in demo).
- **Elected individual (WF-EXE-02):** F-LEG-015 dual supermajority (legislature + constituent jurisdictions, two independent meters); single-winner RCV (the only single-winner race in the system); **top-4 runners-up become R-17 advisors with `advisorRank` 1–4 and step in, in rank order, if the office vacates** — succession by popular count, not appointment. I-EXC dissolves/transfers on seating of I-EEO; first election synced to term cycle (WF-ELE-08).
- **Modification (WF-EXE-03):** altering an existing elected office requires supermajority of constituent jurisdictions only.
- **Departments (WF-EXE-04):** created by legislative act F-LEG-016 (ordinary majority); fields = name, type (chief_executive | treasury | defense | state | justice | other), **oversight assignment** (which executive — full and equal investigative power per member), charter (function, powers, reporting interval). Department/Board machine: Chartered → Oversight-Assigned → Governors-Nominated → Consented → Operating → [Member Removal Requested → Voted] → Reporting → Re-chartered | Dissolved.
- **Board of Governors (WF-EXE-05):** F-EXE-001 nomination dossier (published) → F-LEG-020 consent vote (**peg-quorum ordinary majority**) → R-18 seated, politically neutral, 10-yr civil-officer term (CLK-09); rejection → renominate. **Co-determination applies to departments identically to private orgs:** ≥100 workers → first worker-elected seat (CLK-13), uniform scaling to parity at 2,000 (CLK-14). PW&U: 1,240 workers → 4 worker seats of 11; **worker-elected board terms end 2035-11-01 (the legislative term end), while appointed governors hold 10-yr terms**; chair jointly elected by the whole board. Departments oversee CGCs (Manhattan Water & Power).
- **Governor removal (WF-EXE-06):** F-EXE-003 good-faith competence/ethics finding (grounds published) → legislature votes by ordinary majority → removed → vacancy → WF-EXE-05, or retained.
- **Executive actions (WF-EXE-07/08):** F-EXE-005 orders — Constitutional Engine validates scope **before issuance** against the delegation act + constitutional order; out-of-scope orders are rejected pre-issuance and **the rejected attempt is itself recorded on the public record**; issued orders remain judicially reviewable (Art. IV §5). Emergency powers widen delegated scope only within declared area/duration. F-EXE-002 policy proposals route to the department board (adopt/amend/decline — never bypass). F-EXE-004 investigations: scope + records access defined, findings published, outcomes branch to policy proposal | removal request | legislative referral | close. Grants: legislature appropriates by act; executive administers applications; every award/disbursement appended to the audit chain (WF-SYS-04).
- **Reporting (WF-EXE-09, R-18 surface):** F-BOG-001 implementation rules (versioned; must cite an enabling act — charter, bill, or emergency declaration; cannot exceed them; drafts publish for comment) and F-BOG-002 periodic + special reports filed to **executive AND legislature**, published to public record (WF-SYS-03), feeding oversight/investigations. Canonical IDs F-BOG-001/002; workflows catalog aliases F-GOV-001/002 (both shown).

---

## 10. Multiple legislatures vs. single Earth legislature with scopes

The mockups are **predominantly multi-legislature**: every jurisdiction level has its own legislature instance. Evidence:

1. **Per-jurisdiction chambers:** the entire legislature module is the "New York County legislature" — a county-level 9-seat chamber with its own Speaker, committees, admin office, election board, settings register ("Constitutional settings — New York County"), and acts numbered per jurisdiction.
2. **`world.elections`:** separate concurrent general elections per level, each with its own seat total — Manhattan 7 seats; Kings 5; Queens 9; **New York State legislature 272 seats / 34 districts**; **US federal legislature 692 seats / 87 districts**; **Earth global parliament 1,999 seats / 274 districts** ("a real race here, not a metaphor").
3. **Cross-jurisdiction vote engine:** WF-EXE-02/03, F-LEG-015/018/028 all require "supermajority of constituent jurisdictions" — i.e., **constituent jurisdiction legislatures vote as bodies** (51 of 62 counties on executive-home). Assumes a legislature per county.
4. **Bicameral preview (bill-detail):** explicitly frames New York State as "a legislature with constituent jurisdictions — its counties" with type A/type B seat kinds.
5. **Cross-level emergency powers:** the Dorinda power is invoked by the **state** legislature; the county's session console and executive surfaces display it because the county "lies inside the area of effect" — implies per-level legislatures with downward visibility.
6. **Bill scale hint:** "a state-level act could instead bind named constituent counties" — parent legislatures legislate over constituent jurisdictions.
7. **Federation:** `world.instance` = `manhattan.cga.example`, `authoritativeFor: 'usa-3-new-york-county'` — one authoritative server per jurisdiction.

The **single-Earth-legislature-with-scopes** model appears in exactly one place: **`world.districtScenario`**, which mirrors the developed Legislature Browser — `legislature: 'earth-0-earth'`, `scope: 'earth-0-earth'`, cube-root sizing (1,999 seats, quota 3,985,245, Webster), draft maps, and `subdividingMembers` where over-ceiling members (India 358 seats, Mexico 32) "subdivide over their own scope **inside the parent budget** — their election boards draw those districts" plus a manual-line-drawing case (Fujian, 9.42 fractional seats, no child subdivisions).

**Internal inconsistency worth resolving in the architecture plan:** executive-home's conversion act says the NY **State** legislature voted "8 of 9 serving (threshold 6 = ceil(9 × 2/3))" — a 9-seat body — while `world.elections` gives the same state legislature 272 seats across 34 districts. The mockups thus mix (a) one small chamber per jurisdiction, (b) large districted legislatures at state/federal/planetary scale, and (c) the scope-drilldown Earth-legislature model, without reconciling them. The county chamber UI (9 seats, single circular seat map) is only coherent for model (a); every threshold meter hard-assumes the small-chamber denominators.

Other contract-relevant notes:
- Settings noscript copy says "16 settings"; the array has 17 keys (supermajority numerator/denominator are two rows).
- WF-LEG-14 flow text cites the store as `jurisdiction_settings`; the shipped migration is `constitutional_settings`.
- Catalog ID drift is institutionalized: F-LEG-022/023/024/025/034/036 and F-CHR/F-BOG all carry `aliases` that screens render ("catalog alias: …") — any backend should preserve canonical-ID + alias mapping.
- Large amounts of displayed data (bill registry rows, motions, acts, investigations, orders, rules, reports, board rosters, appropriations, first-sessions checklist) are page-local literals, not fixtures — these enumerate the entity types a real backend must persist: sessions, attendance records, motions, statements, acts/laws (versioned), committee meetings/testimony/reports, investigations, removal proceedings, referendum questions/results, emergency power records, executive orders (incl. rejected attempts), policy proposals, BoG nominations/consents, department rules, report filings, appropriations/grant applications.