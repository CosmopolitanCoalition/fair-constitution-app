# SERVICE_SCALE_FORMULA — how much institution a place gets, and how it grows into it

**Lane 4 (Simulated World Engine) · Wave 3 · 2026-07-29 · C3 deliverable**
**Status: SIGNED OFF (operator, 2026-07-29, via the desk). All §9 calls RULED — option (a) throughout.**
This document is the empirical grounding and the approved formula; lane 3's R-B build wires it into
`InstitutionScaleService` next wave. The §9 rulings are now the contract.

---

## 0. The ruling this answers (charter §10, C3)

> *"This may require a real world investigation to get senses of scales for services at population
> levels then build a formula to auto add… the dial for overtime in the real world player game will
> occur granularly over stages up to their peak potential. At whichever point their player base
> asserts governance… those controls are effectively manual."*

Three obligations, in order:
1. **Investigate real-world service scaling** — cited (§3).
2. **Build the auto-add formula** — institutions/rooms as `f(population, parts, depth)` (§4).
3. **The over-time dial** — granular growth stages up to a "peak potential," then **manual once the
   player base asserts governance** (§5–6).

---

## 1. Scope — what this touches, and what it must never touch

This formula decides **how much a provisioning engine materialises in advance**, and the **target a
sim jurisdiction grows toward**. It is a *materialisation policy*, exactly like the existing
`InstitutionScaleService` it extends. It **never gates a right, a vote, or a candidacy** (Art. I),
and is **never consulted on a rights path**.

| Concern | Owner | This formula |
|---|---|---|
| **Legislature SEATS** (`max(5, round(pop^⅓))`) | `ActivationService::cubeRootSeats` · Art. II §2 | **DEFERS — untouched.** Already law. Cited in §3 only. |
| **Districts** (5–9 band, splitline/composite) | `DistrictingService` · lane 1 | **DEFERS — untouched.** |
| **Type B seating** (per-constituent ladder, grouping) | `TypeBSeatLadder` · lane 1 | **DEFERS — untouched.** |
| **Activation THRESHOLD** (residents-to-boot) | `ActivationTierService` · Art. II §1 | **DEFERS — separate cube root, do not merge (see ⚠ below).** |
| **Judiciary bench floor** (≥5, constituent-per-judge) | `JudiciaryFormationService` · Art. IV §1 | **DEFERS to the floor;** proposes the growth *above* it (§4.3). |
| Tier band (none/minimal/standard/extended/full) | `InstitutionScaleService::tierFor` | **EXTENDS — keeps it, adds counts.** |
| Committees / departments / BoG counts | *(reserved acts of self-government)* | **DEMO-POSTURE TARGET ONLY (§4.4). Never materialised on a production instance.** |
| Civic rooms/venues beyond square+halls | *(new)* | **PROPOSES (§4.5).** |

> ⚠ **THE TWO CUBE ROOTS ARE DIFFERENT FUNCTIONS. DO NOT MERGE THEM.**
> `cubeRootSeats(pop)` sizes a *legislature* — Earth → **1,999 seats**.
> `ActivationTierService::tierThreshold(pop)` sizes a *boot threshold* — Earth → **9 residents**.
> Their defaults (5, 9) coincide numerically by accident (seat floor / district ceiling vs resident
> floor / cap). They **must not share settings keys**. This formula sizes a *third* thing —
> institution counts — and must not be confused with either.

---

## 2. Two postures, one formula

The same `f(population, parts, depth)` is read two ways depending on `instance_class`:

| Posture | Instance | What the formula gives | Who then owns it |
|---|---|---|---|
| **Demo premade** | `scale_demo` (the Attained instance) | The **peak-potential** count for every institution, materialised up front so the demo world looks alive on load. | The sim seats the chambers through the *real* governance engine, so the demo is legitimate, not forged. |
| **Pre-governance growth** | any pre-seating jurisdiction | The **current-stage** count (a fraction of peak), advancing granularly as the place enrols (§5). | Flips to **manual the instant a real chamber seats** (§6). |

**The formula is read ONLY while a place is pre-governance.** A governed place's counts are whatever
its chamber votes — the formula does not advance, correct, or override them, ever.

---

## 3. Real-world evidence (the "senses of scale")

Every count below is anchored to published data. Sources are hyperlinked at §10.

| Institution | Real-world scaling law | Key data points | App anchor |
|---|---|---|---|
| **Legislature size** | `A ≈ k·P^⅓` — the **cube-root law** (Taagepera 1972), "one of the strongest relationships in political science." Empirical fit `A ≈ 0.66·P^⅓` (Margaritondo 2021). | Lithuania 2.79M→141 (exact); Denmark 5.82M→179; Canada 37.6M→338; US 328M→435 (capped, −37%). | App uses the **pure** `k=1` form: `round(P^⅓)`, Earth→1,999. |
| **Judges** | **~22 professional judges per 100,000** (Europe median, CEPEJ 2024 / 2022 data); range 3 (Eng & Wales) to 42.4 (Croatia, Montenegro). | Aggregate across a whole national court system, all tiers. | Art. IV §1 floor 5/bench; distributed across the tree. |
| **Executive (cabinet/ministries)** | **Narrow sublinear band, 5–38 ministers**; grows ~log in population, not linearly; federal states larger (Indriðason & Bowler 2014). | Denmark 18, Norway 19, Singapore 20 (all ~5–6M); India ~30 cabinet (71 w/ deputies). | Reserved act (F-EXE-001); demo target only. |
| **Standing committees** | Narrow band, strongly sublinear in chamber size. | US House 435→**20**; US Senate 100→**16**; small parliaments ~10–15. | Reserved act (F-LEG-009); demo target only. |
| **Civic meeting venues** | **1 community/civic center per ~25,000–100,000 people** (LA City Planning). Function set (meeting, education, library, citizens' advice) mirrors the CGA square+halls (Standards of LIFE). | Mid-anchor ≈ 1 per 50,000. | square+halls unconditional (Art. I); extra rooms scale. |

**Reading of the evidence:** institution complexity is **sublinear and heavily damped** in
population. Seats follow a cube root; judges a per-capita rate distributed across a tree; cabinets
and committees sit in a tight band that barely moves from a small nation to a huge one; venues are
the only thing that scales near-linearly, and only at the *local* tier. **Nothing scales
proportionally with headcount.** The existing `InstitutionScaleService` band model already encodes
this intuition; this formula makes it numeric and cited.

---

## 4. The formulas

Notation: `P` = real population (0/null = uninhabited), `C` = direct constituents ("parts"),
`S` = legislature seats = `max(5, round(P^⅓))`, `T` = tier from `InstitutionScaleService::tierFor`.
All formulas are **pure, DB-free statics** (pinnable like `cubeRootSeats` and the existing service).

### 4.1 The zero rule and binding — INHERITED UNCHANGED

`f = 0` for everything when `tierFor(P, C, binding) === TIER_NONE` (no people, no parts). Under
`free` binding every place gets the full standard set. **These are already law in
`InstitutionScaleService`; this formula sits on top of them and never overrides them.**

### 4.2 Seats & districts — DEFER

`S = max(5, round(P^⅓))`, districted 5–9 by `DistrictingService`. **Not this formula's to compute.**
Listed only because the other counts key off `S`. Earth→1,999 · San Marino→32 · Niue→12.

### 4.3 Judiciary — bench size + court tiers

**Bench floor stays 5 (Art. IV §1), no ceiling; the constituent-per-judge rule keeps precedence
where it applies.** The existing tier bumps already sit inside the empirical envelope:

| Tier | Existing `judgeCount` | Proposed court tiers (NEW) | Real-world reading |
|---|---|---|---|
| minimal (village) | 5 | 1 (trial only) | one bench |
| standard (town/city) | 5 | 1 (trial only) | one bench |
| extended (large city/province) | 7 | 2 (trial + appellate) | appellate layer appears |
| full (nation/planet) | 9 | 3 (trial + appellate + supreme/constitutional) | apex court |

**Recommendation: KEEP the existing 5/5/7/9 bench bumps (zero delta, already pinned in
`InstitutionScaleTest`), ADD the court-tier count as the new scaled thing.** The 22-judges-per-100k
figure is the *aggregate* calibration target, reached by **distribution across the tree** (every
jurisdiction with a judiciary holds ≥5), not by inflating any single bench — concentrating it would
break the min-5-per-race law. See §9-Q2 for the alternative (a log bench curve) and its cost.

### 4.4 Legislative committees & executive departments — DEMO-POSTURE TARGETS ONLY

⚠ **These are ACTS OF SELF-GOVERNMENT.** `InstitutionProvisionService` **deliberately creates none**
of them ("a provisioning engine that minted them would be manufacturing acts of self-government…
they arrive through F-LEG-009 / F-LEG-016 / F-EXE-001 once a chamber is seated and votes"). The
formula below is the **target the SIM drives toward through the real governance forms**, and the
**pre-governance ceiling** — never a row the provisioning engine writes on a production instance.

**Committees** (fit to House 435→20, Senate 100→16):
```
K(S) = clamp( round(3.5 + 2.7·ln S), 1, round(S/5) )
```
The `round(S/5)` upper clamp keeps a tiny chamber from having more committees than it can staff (a
committee wants ~5 members); the log term binds for large chambers. Hits both anchors exactly.

**Executive departments** (fit to the 5–38 cabinet band, sublinear):
```
D(P) = clamp( round(-7.8 + 1.67·ln P), 3, 30 )
```
Floor 3 (a minimal executive still needs a few functions), cap 30 (the top of the observed core-
cabinet band; the Earth chamber can vote itself more — the formula is a demo default, not a limit).

| Place | `S` | `K` committees | `D` demo departments |
|---|---|---|---|
| Niue (1,819) | 12 | 2 | 5 |
| San Marino (33,581) | 32 | 6 | 10 |
| Earth (7.99B) | 1,999 | 24 | 30 |

### 4.5 Civic rooms & venues

**square + halls are UNCONDITIONAL for every inhabited place** (Art. I — gating speech on a headcount
is the error; already enforced in `spaceTypes` and `InstitutionProvisionService`). Beyond those, a
**local** jurisdiction earns neighbourhood civic rooms (the substrate for lane 3's live civic room):
```
extra_rooms(P) = clamp( floor(P / 50000), 0, 20 )   # local tier only; the tree carries the aggregate
```
Anchored to 1 civic center per ~50k (§3). **The cap and the "local tier only" rule are essential:**
Earth's 7.99B ÷ 50k = 159,800 rooms is nonsense at the planet node — those rooms belong to Earth's
*descendants*, not Earth. Rooms are a leaf/local metric; the jurisdiction tree distributes them.
Niue and San Marino (both < 50k) get square + halls and **0 extra rooms** — correct for a microstate.

---

## 5. The over-time dial — growth stages up to peak potential

The sim ramps a jurisdiction through stages, each a fraction of the §4 peak. **The stages ARE the
existing activation lifecycle** (`jurisdiction_activations.state`), not a parallel machine — the
formula only supplies the *target at each stage*.

| Stage | Lifecycle state | Institutions present | Fraction of peak |
|---|---|---|---|
| 0 · Seeded | `boundary_loaded` | none (zero rule if uninhabited) | 0 |
| 1 · Settling | inhabited, pre-boot | **square + halls open** (Art. I, the moment a resident appears) | speech only |
| 2 · Booting | `activating` | legislature shell + election board (the `InstitutionProvisionService` set) | shell |
| 3 · Governing | `active`, chamber seated | judiciary bench (§4.3), executive committee `forming` | ~⅓ |
| 4 · Maturing | seated chamber acting | committees → `K`, departments → `D`, appellate court if extended+ | ~⅔ → peak |
| 5 · Peak | full set at the place's peak potential | everything §4 grants for `(P, C, depth)` | 1.0 |

**Granularity of the dial:** stages 1→5 advance on **simulated enrolment (reach)** crossing evenly-
spaced fractions of the activation threshold, so a place fills in gradually rather than snapping to
full. (Alternative: advance on sim ticks — see §9-Q3.) The dial is **monotone** — more enrolment
never removes an institution, matching `InstitutionScaleService`'s monotonicity pin.

---

## 6. The manual-after-governance handoff — precise boundary

**The handoff fires the instant a real chamber seats with real elected members** — the exact line
`InstitutionProvisionService` already draws (it provisions the shell but *never* the acts of
self-government, which "arrive… once a chamber is seated and votes").

After that line:
- The formula is **no longer read** for that jurisdiction. Committee/department/court counts become
  **whatever the seated chamber votes** (F-LEG-009, F-EXE-001, F-LEG-016).
- **The formula never overrides a governed choice.** A governed chamber with 3 committees when the
  formula's peak is 12 is left at 3. A dissolved department is not "auto-restored." A place that
  votes past the demo cap (Earth voting 35 departments) is left there.
- The dial **stops advancing.** "Peak potential" is a pre-governance ceiling, not a mandate.
- Only the **unconditional** civic spaces (square + halls) and the **constitutional floors** (≥5
  judges, ≥5 seats) survive the handoff as guarantees — because those are rights-adjacent, not
  materialisation policy.

This is why the formula is *safe*: at no point can it manufacture, undo, or second-guess an act of
self-government. It fills an empty stage and then gets out of the way.

---

## 7. Worked examples (peak potential)

Populations: Niue 1,819 · San Marino 33,581 · Earth 7.99B (2024 UN; Earth = the app's modelled figure).

| | **Niue** | **San Marino** | **Earth** |
|---|---|---|---|
| Population `P` | 1,819 | 33,581 | 7.99 B |
| Direct constituents `C` | 0 (leaf microstate) | 9 castelli | 282 districts / national tree |
| Tier `T` | standard | standard | full |
| **Seats `S`** (defer) | 12 | 32 | 1,999 |
| Type B (defer, lane 1) | n/a (leaf) | 9×5→ladder→27 | per-constituent |
| **Committees `K`** (demo) | 2 | 6 | 24 |
| **Departments `D`** (demo) | 5 | 10 | 30 |
| **Judiciary** | 5 judges · 1 court | 5 judges · 1 court | 9 judges · 3 court tiers |
| **Civic spaces** | square + halls · 0 extra | square + halls · 0 extra | square + halls at node; rooms in descendants |
| Sanity check | Real Fono ≈ 20; cube-root 12 is the app's lawful size | 32+27 = **59** total bicameral (matches the settled figure) | 1,999 across 282 districts (matches settled) |

Every "defer" figure is produced by existing law; every bold figure is this formula's proposal.

---

## 8. How it wires (for lane 3's R-B build — after operator sign-off)

- **Extend `InstitutionScaleService`, do not fork it.** Add pure statics
  `committeeTarget(int $seats)`, `departmentTarget(?int $pop)`, `courtTiers(string $tier)`,
  `extraRooms(?int $pop, bool $isLocal)`. Keep them **DB-free** so `InstitutionScaleTest` can pin
  them without a schema — the existing posture.
- **The provisioning engine still writes only the shell.** Committee/department targets are consumed
  by the **sim's governance stages** (the seated chamber forms them via the real forms), never by
  `InstitutionProvisionService`. Court-tier and room counts *may* extend the provisioning INSERTs
  since a court and a civic room are not acts of self-government — operator's call, §9-Q4.
- **Pins to add:** committee/department curves hit their anchors; rooms cap and local-only; court
  tiers monotone; **the handoff pin — a seated chamber's counts are never rewritten by the formula.**
- **Pins NOT to touch:** the two-cube-roots separation, the zero rule, the ≥5 bench floor, the
  unconditional square. Extending `judgeCount` (Q2) would touch a pinned value — flagged, not done.

---

## 9. Judgment calls — RULED 2026-07-29 (operator, all option (a))

**Operator signed off the formula 2026-07-29 (via the desk). Every call below resolved to option (a);
these rulings are the contract lane 3's R-B build implements.**

- **Q1 — department demo cap.** ✅ **RULED (a): cap 30**, tight to the core-cabinet band. A governed
  chamber may vote itself more; the demo default reads as "a full government," not a record-setter.
- **Q2 — bench growth.** ✅ **RULED (a): keep 5/5/7/9** — no pinned-value change. The tier bumps sit
  inside the empirical envelope and tree distribution already reaches the per-capita target.
- **Q3 — dial granularity.** ✅ **RULED (a): advance stages on simulated reach/enrolment fractions** —
  the sim rehearses the real activation lifecycle rather than a clock.
- **Q4 — may provisioning materialise extra courts & rooms directly?** ✅ **RULED (a): YES for court
  tiers and local civic rooms** (not acts of self-government) — and **NEVER for committees /
  departments / BoG**, which stay reserved to a seated chamber (F-LEG-009 / F-EXE-001 / F-LEG-016).
- **Q5 — civic-room denominator.** ✅ **RULED (a): 1 per 50,000** (mid of the 25k–100k range).

---

## 10. Sources

- Cube-root law of assembly size — [Wikipedia: Cube root law](https://en.wikipedia.org/wiki/Cube_root_law) ·
  Taagepera, R. (1972), *The size of national assemblies* ·
  [AIP: Math could help set the sizes of national legislatures](https://www.aip.org/inside-science/math-could-help-set-right-the-sizes-of-national-legislatures)
- Judges per capita — [CEPEJ 2024 evaluation report (Council of Europe)](https://www.coe.int/en/web/portal/-/efficacit%C3%A9-et-qualit%C3%A9-de-la-justice-en-europe-le-conseil-de-l-europe-publie-son-rapport-2024) ·
  [eucrim summary of the CEPEJ 2024 report](https://eucrim.eu/news/cepej-2024-report-on-european-judicial-systems/) ·
  [IJCA: Explaining cross-country differences in judges per capita](https://iacajournal.org/articles/10.36745/ijca.581)
- Cabinet size — Indriðason & Bowler (2014), *Determinants of cabinet size*, EJPR
  ([PDF](https://media.snopes.com/2020/09/IndridasonandBowler-DeterminantsofCabinetSizeEJPR2014.pdf)) ·
  [Wehner (2022), Cabinet size and governance in Sub-Saharan Africa](https://onlinelibrary.wiley.com/doi/full/10.1111/gove.12575)
- Standing committees — [US Senate: about the committee system](https://www.senate.gov/about/origins-foundations/committee-system/overview.htm) ·
  [List of US Senate committees](https://en.wikipedia.org/wiki/List_of_United_States_Senate_committees)
- Civic venues — [LA City Planning: Community Centers (serves 25,000–100,000)](https://planning.lacity.gov/cwd/framwk/chapters/03/03204.htm) ·
  [Standards of LIFE: Community Center](https://standardsoflife.org/standards/new-democracy/mlr/community/community-center/)
- Populations — [Niue ≈ 1,819 (2024 UN)](https://statisticstimes.com/demographics/country/niue-demographics.php) ·
  [San Marino ≈ 33,581 (2024 UN)](https://danso.info/en/San-Marino-population/)

---

*Relates: `app/Services/InstitutionScaleService.php` (extends) · `app/Services/InstitutionProvisionService.php`
(the shell / handoff boundary) · `app/Services/ActivationTierService.php` (the OTHER cube root) ·
`app/Services/ActivationService.php` (cubeRootSeats, deferred) · lane 3 R-B `InstitutionScaleService`
build (consumer) · `ATLAS_DESIGN.md` (the surface that displays the resulting world).*
