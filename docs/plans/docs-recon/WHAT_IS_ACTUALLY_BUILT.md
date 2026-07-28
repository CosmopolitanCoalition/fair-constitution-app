# What is actually built — the honest inventory

*Written 2026-07-26 after the operator asked, correctly, why he was told repeatedly that we were
good to walk. He was told that about a 26-jurisdiction fixture while he was asking about a
956,336-jurisdiction world. This document exists so nobody has to ask again.*

---

## 1. THE WORD "BUILT" HAS BEEN DOING THREE JOBS AND THAT IS THE WHOLE FAILURE

| Sense | Means | Who has been saying it |
|---|---|---|
| **BUILT-CODE** | the code exists in the repository and its tests pass | every lane, every status report |
| **BUILT-DEPLOYED** | that code is running on the box you would walk | **nobody has been tracking this** |
| **BUILT-EXERCISED** | data exists on that box that actually uses it | **nobody has been tracking this either** |

**Every "ready to walk" answer given to the operator was BUILT-CODE, and sometimes
BUILT-EXERCISED on the dev fixture. He was asking about the game box. Those are different
questions and this desk did not notice it was answering a different one.**

---

## 2. THE TWO BOXES, MEASURED THE SAME WAY, 2026-07-26

| | **GAME BOX** `fc` :8080 | **DEV BOX** `fcd` :8082 |
|---|---:|---:|
| jurisdictions | **956,336** | 26 |
| legislatures | **955,130** | 19 |
| districts | **1,988,285** | 34 |
| **members** | **0** | 654 |
| **executives** | **0** | 19 |
| **judiciaries** | **0** | 19 |
| **election boards** | **1** | 19 |
| **civic spaces** | **0** | 38 |
| **organisations** | **0** | 3 |
| **users** | **1** | 1,438 |
| **elections** | **0** | 70 |
| **laws** | **0** | 7 |
| migrations applied | **13 of 29** | 29 of 29 |

**Read that as: the game box is a map of the world with nobody on it and last Tuesday's code.
The dev box is a small working civilisation.**

The 54-card worksheet was written against the right-hand column. **On the game box today,
sections B, C, D, E, G and I have nothing to walk into.**

---

## 3. WHAT IS GENUINELY BUILT AS CODE

These are real, tested, and have run end to end — at small scale.

| Capability | Evidence | Largest run |
|---|---|---|
| Geodata ingest + districting | 956,336 jurisdictions, 1.99M districts on the game box | **planetary — done** |
| Institution provisioning | `InstitutionProvisionService`, 5 steps, chunked at 25k | 19 legislatures |
| Populate (people → elections → count → seat) | Niue empty→governed; 9 castelli seated exactly | **15 jurisdictions** |
| Elections engine | STV/Droop/Gregory, two-phase secrecy, countback | 70 elections on dev |
| Legislature ops | bicameral dual agreement proven at 58 members | 19 chambers |
| Judiciary | 45 judges, cases, juries, verdicts, Art. IV §5 remedy | 19 courts |
| Economy | write path + refusals proven live, ledger conserved | 1 world |
| Education / achievements | 70 surfaces, 14 arcs, awarding service | 1 world |
| Clock controls | fire one timer, advance the world N days, dry-run first | 1 world |
| Federation / multibox | built, pinned | **never run against a second machine** |

---

## 4. THE PUNCH LIST — APPROVED OR SCOPED, NOT BUILT

**This list did not exist before today. That is itself a finding.** The only pre-existing
punch list in the repository is `docs/plans/ui/UI_PUNCHLIST.md` (8 UI items, all deferred with
reasons).

### 4a. Blocking a real walk of the game box

| # | Item | Owner | State |
|---|---|---|---|
| **P1** | **Pull + migrate the game box** — 16 pending migrations, additive, verified non-destructive | operator, `deploy.ps1` | not run |
| **P2** | **Provision institutions at scale** — 920,367 legislatures × 6 rows ≈ 5.5M, ~200 chunks | lane 3 | **never run above 19** |
| **P3** | **Populate people at scale** — cohorts → identities → elections → count → seat | lane 4 | **never run above 15** |
| **P4** | **GOVERNANCE ACTS STAGE — the gap found 2026-07-26** | lane 4 + lane 13 | **scoped, parked, not built** |

**P4 in full, because it is the one that was mis-reported as a constitutional boundary:**
`InstitutionProvisionService` deliberately does not create committees, departments or boards of
governors, on the grounds that Art. II §9 reserves them to legislatures. **That rule is correct
for a real instance and irrelevant for a simulated one.** The path already exists and was
demonstrated on 2026-07-26: lane 13 created a committee on San Marino through a **real F-LEG-009
filing and a real bicameral vote cast by 58 fictional legislators**. What is missing is a
**governance-acts stage** in the populate pipeline that drives those votes at scale — elect a
speaker, form committees, delegate an executive, staff a judiciary. Lane 4 scoped this weeks ago
as its **DECISION 3 (batching for governance acts)** and it has been parked on the operator's desk
since. **It is assembly, not invention** — every act already works.

### 4b. Approved, scoped, not built

| # | Item | Owner | Note |
|---|---|---|---|
| P5 | **Type B stage-two grouping** — clump constituents into shared panels | lane 1 / 3 | **9,708 chambers** exceed the bound and are correctly flagged, waiting on this |
| P6 | **Activation threshold switch-on** | operator ruling | dial built, currently **off**, so effective threshold is 1 |
| P7 | **Phase J — the two coalition orgs** | lane 14 | plan written (`a600982`), build **held by the operator** |
| P8 | **Federation / multibox first run** | lane 2 | cloud path on GitHub, never run on a real machine |
| P9 | **i18n extraction, CI, full locale registry** | lane 5 | 6 locales at 95–99%; the machinery around them absent |
| P10 | **8 UI punchlist items** | lane 6 | deferred with reasons, incl. `tone="emergency"` colliding across 44 sites |
| P11 | **Atomic write in lane 10's summary emitter** | lane 10 | 6 lines, ~10 min, written up not applied |
| P12 | **7 untriaged suite failures** | unowned | left red and named deliberately |
| **P13** | **⚑ V3 SHELL + SYSTEM SYNTHESIS — the app must BE the v3 mockups** | charter: `docs/plans/ui/V3_SYNTHESIS_CHARTER.md` | Found at walk card A-1 (findings W-1/W-2): no dock exists in `resources/js`, Learn is a sidebar link instead of the per-screen teaching flyout, Demo mode's controls are scattered across a dev bar and POST endpoints instead of the Demo flyout, Tour exists as pages/worksheet instead of a MODE. Operator's standing order 2026-07-28: *"anything and everything currently built across the fleet should use the v3 mockups and this system."* **Supersedes the walk until synthesized** — walking 54 cards against the wrong shell certifies the wrong thing. **Investigation DELIVERED 2026-07-28**: `docs/plans/ui/V3_GAP_MATRIX.md` (all 107 screens: 43 conformant · 36 partial · 28 absent) + `docs/plans/ui/V3_SYNTHESIS_PLAN.md` (3-wave build order on the A–O frame, fleet assignments, 10-item operator reconciliation ledger). The walk resumes when Wave 1 (shell complete) lands. |

---

## 5. WHAT WAS SAID VERSUS WHAT WAS TRUE

- *"Ready to walk"* — true of a 26-jurisdiction fixture, **false of the game box**, said without
  distinguishing them.
- *"All principal structures built"* — answered as a phase scoreboard (BUILT-CODE). **Nothing on
  the game box beyond the map has ever been created.**
- *"The build is closed"* — true of the code the lanes were assigned. **Not true of the world.**
- *"Committees are withheld on purpose"* — relayed a service docblock as a settled boundary
  **an hour after another lane had demonstrated the way through it.**

**The pattern:** this desk reported what the lanes reported, in the lanes' frame (code and
fixtures), to a person asking in his own frame (the world he would walk). Nobody lied. **The
question changed and the answer did not.**

---

## 6. THE RULE THAT PREVENTS THE REPEAT

**Every readiness claim from now on names the box and the sense.**

> ❌ *"Ready to walk."*
> ✅ *"BUILT-CODE and BUILT-EXERCISED on `fcd` at 26 jurisdictions. BUILT-DEPLOYED on `fc`: no.
> BUILT-EXERCISED on `fc`: no."*

If a claim cannot be written in that form, it is not yet a claim about anything.
