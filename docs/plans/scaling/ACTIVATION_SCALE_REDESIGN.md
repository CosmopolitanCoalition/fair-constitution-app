# Activation & scale — the R-B redesign (DESIGN ONLY)

*Lane 3, 2026-07-29. The operator ruled (R-B, V3_SYNTHESIS_PLAN §10) that
activation must scale with the REAL PLAYERBASE, 1 → ∞, with no fixed cap; that
the 5–9 band is ONLY the reps-per-district setup band, never an activation
threshold; and that demo/dev worlds premake institutions from WorldPop so people
see potential scale. This is the design that implements that ruling. **No code
this wave — the desk reviews this doc before any build.***

## R-B, verbatim

> **Activation scales with REAL PLAYERBASE, 1 → ∞.** For ongoing human play,
> which institutions exist and how many rooms follow the ACTUAL player base in
> the jurisdiction as it grows — no fixed activation cap. **The 5–9 band is ONLY
> the representative default for reps-per-district floor/ceiling in setup** —
> never an activation threshold. **Demo/Dev worlds premake institutions from
> WorldPop population** so people see potential scale approximating reality.
> Lane 3 redesigns the activation/scale model to this (real = playerbase-driven
> continuous; demo = WorldPop-premade); the tier-cap question dissolves.

## What exists today (the three pieces this touches)

1. **`ActivationTierService`** — the boot GATE. A place may start its government
   when `verified_residents ≥ threshold(population)`, where
   `threshold(P) = clamp(ceil(k·P^(1/exp)), floor, cap)`, defaults
   `k=1, exp=3, floor=5, cap=9`, `HARD_CAP=100`. It reads **real population**
   (WorldPop) and compares **player population** (verified residents). Disabled
   by default (a single resident boots).
2. **`InstitutionScaleService`** — the materialisation POLICY: *how much*
   institution a jurisdiction is entitled to, keyed on
   `instance_settings.population_binding` (`free` = full set for everyone;
   `real` = scale by people/parts/depth). The zero rule: no people → nothing.
3. **`ActivationService` + CLK-06** (`EvaluateCriticalPopulationJob`) — the sweep
   that boots a jurisdiction when its threshold is met, minting the institution
   stubs + bootstrap election.

Two facts about the current state, both load-bearing for the redesign:

- **The CLK-06 sweep predicate is structurally dead on the seeded planet.**
  `EvaluateCriticalPopulationJob` excludes any jurisdiction that already has a
  legislature row (`NOT EXISTS (… legislatures …)`), and autoscale seeded a
  `forming` legislature for **every** one of the 955,130 live jurisdictions. So
  the automatic sweep can never return a candidate — it tests row-existence, not
  governance. (Detailed in `INSTITUTION_SCALING_PLAN.md` Item 0.)
- **`floor=5, cap=9` in the tier curve numerically coincide with the seat
  band by accident**, and the class docblock already warns "do not merge them."
  R-B makes that warning a hard requirement: the activation model must stop
  borrowing the seat band's numbers at all.

## The reframing R-B asks for

The current model is **threshold-then-boot**: derive a fixed threshold from real
population, wait for that many players, boot the whole set at once. R-B replaces
the *philosophy* for real worlds with **continuous, playerbase-driven scale**,
and splits the world into two modes:

| | REAL world (ongoing human play) | DEMO / DEV world |
|---|---|---|
| Driver | the **actual player base** in the jurisdiction | **WorldPop** population estimate |
| Shape | **continuous 1 → ∞**, no cap | premade to approximate reality |
| Institutions | come into being as real players arrive and grow | full set materialised up front |
| Rooms | follow the real player count | sized to the estimate |
| Purpose | a government that is exactly as big as its people | *show potential scale* before players exist |

The unifying idea: **institution and room complexity is a function of a live
count, not a one-time gate.** In a real world that count is verified residents
(players); in a demo world it is the WorldPop estimate, stamped in at seed so an
empty demo San Marino still shows a ~33k-person government.

### Consequences

- **No fixed activation cap.** `ActivationTierService::HARD_CAP` and the
  `cap` parameter, as a *ceiling on how many residents can be required*, dissolve
  for the real path — a metropolis's government grows with its metropolis. (The
  Art. I rail that a cap must never make a community *unbootable* is preserved by
  a different mechanism — see "the floor stays" below.)
- **The 5–9 band belongs to districting only.** `cubeRootSeats` sizes a chamber;
  the 5–9 band bounds a *district's* seats. Neither is ever an activation number.
  The tier service stops using `floor=5, cap=9` defaults; if a resident floor
  survives at all it is named and defaulted independently (see §"What the floor
  becomes").
- **`population_binding` (`real`/`free`) is renamed and re-centred** as the
  world-mode switch (real-playerbase vs demo-premade), the founding property it
  already almost is — `InstitutionScaleService` already treats it as "a founding
  world property, not a legislative setting."

## The model (design)

### Real worlds — continuous, playerbase-driven

Activation stops being a boolean gate and becomes a **derivation from
`CivicPopulation::of()` (verified residents)** that re-evaluates as players join:

- **Genesis (1 player).** The first verified resident brings the jurisdiction to
  life: its legislature leaves `forming`, a bootstrap election board and the
  minimal room set appear. No threshold to clear — one real person governing one
  real place is lawful (this is the R-B "1" end of "1 → ∞").
- **Growth (N players).** As verified residents accumulate, the derived
  institution/room set grows monotonically — more committees become available,
  more Matrix/live rooms provision, deeper structure unlocks — each keyed on the
  live resident count, never on a fixed population estimate. This reuses the
  existing lazy room provisioning (`CertificationService` already dispatches
  rooms on seating; K-1 left the population-gated growth toggle as an explicit
  seam) rather than eager pre-creation.
- **No ceiling.** The derivation is unbounded above; a jurisdiction with a
  million players scales to a million-player government's worth of rooms
  (subject to the *homeserver* capacity reality, which is an ops concern, not a
  model cap — see `INSTITUTION_SCALING_PLAN.md` Band 3).

The CLK-06 sweep is **repaired, not deleted**: its predicate changes from
"has no legislature row" to "is not yet governed" (a `forming` legislature with
zero members is scaffolding, not a government), so it can once again find real
candidates as players arrive. This is the Item-0 fix, now with a purpose.

### Demo / dev worlds — WorldPop-premade

A demo world **premakes** the institution set from `jurisdictions.population`
(WorldPop) at seed time, so the whole tree is browsable at realistic scale before
a single player exists. This is what the seeded planet already is (955,130
`forming` legislatures sized by the cube-root law) — R-B names it as the demo
posture, not the real one. The premake:

- sizes each jurisdiction's institutions to its WorldPop estimate via
  `InstitutionScaleService` (people/parts/depth), under the existing
  set-based/chunked provisioning (the audit chain is a global mutex — see the
  ETL rule and `INSTITUTION_SCALING_PLAN.md`);
- is **guarded to demo/sandbox** (`GuardsSyntheticData` / `instance_class`) so it
  never runs on a real Standard instance, exactly like the demo seeders;
- carries an honest label so a premade-but-unplayed institution reads as
  "potential scale," not a live government.

### What the floor becomes (the Art. I rail, preserved)

The one thing the tier curve did that R-B does **not** discard is the Art. I
protection: a place must never be *harder* to boot than the law allows, and a
lone actor must not boot a government over a place they do not live in. Under the
new model that rail is carried by the **genesis rule itself** — a real resident
of the place brings it to life; a non-resident cannot. There is no population
threshold to tune, so there is no cap to abuse and no floor that can strand a
community. The `HARD_CAP`/`floor` machinery retires; the *residency requirement*
(already an Art. I absolute) does the protecting.

## Code touch-list (for the build wave, after desk review)

- **`ActivationTierService`** — retire the population-threshold curve for the
  real path (or reduce it to the genesis/residency check); remove the `5/9`
  defaults and `HARD_CAP` as activation numbers. Keep the pure-static, DB-free
  posture so `ActivationMathTest`/`ActivationTierTest` can re-pin the new shape.
- **`InstitutionScaleService`** — re-centre `population_binding` as the
  world-mode switch (real=playerbase, demo=WorldPop); the "how much institution"
  policy stays but its *input* becomes the live resident count on the real path.
- **`ActivationService`** — genesis on the first verified resident; continuous
  growth hooks keyed on `CivicPopulation`.
- **`EvaluateCriticalPopulationJob` (CLK-06)** — the Item-0 predicate repair
  (governed-ness, not row-existence), rebuilt for scale (it currently
  materialises the whole result set into PHP every minute).
- **The demo premake path** — a guarded, chunked provisioning entry that sizes
  from WorldPop (this is close to what `institutions:provision` already does;
  it becomes the *demo* half explicitly).
- **Settings** — any surviving activation parameters get their **own** nullable
  `constitutional_settings` columns (cascade via `SettingsResolver`), never the
  seat-band keys. Once settings, they are amendable like the rest of the register
  (the R-C loop applies).

## Migration — the seeded planet

The live game box (955,130 `forming` legislatures, everything else zero) is a
**demo-premade world** under the new taxonomy — it was sized from WorldPop with
no players. No teardown is needed: it simply IS the demo posture R-B describes.
The real path begins the first time a Standard instance takes its first verified
resident. The autoscale/districting artefacts (maps, seats) are orthogonal —
they are the *seat* math (cube-root + 5–9 districting), which R-B explicitly
keeps as setup-only.

## Open questions for the desk / operator

1. **Does a resident floor survive at all on the real path, or is genesis purely
   "one verified resident of the place"?** R-B says "1 → ∞," which reads as
   genesis-at-one; confirm there is no residual N-resident gate.
2. **Is `population_binding`'s `free` mode folded into "real" (every real world is
   playerbase-driven) or kept as a third posture** (tabletop/single-org worlds
   that want the full set with no population binding at all)? `free` currently
   serves the small-world adopter; R-B's two modes may or may not subsume it.
3. **Continuous growth granularity:** which institution/room tiers unlock at
   which live-resident counts on the real path? R-B fixes the *shape*
   (continuous, uncapped); the specific unlock points are a design dial this doc
   leaves open for the build.
4. **The seeded planet's label:** should the premade demo world advertise its
   institutions as "potential scale (unplayed)" in the UI, and where — the Atlas,
   the jurisdiction viewer's activation card, or both?

## What does NOT change (guardrails)

- Voting/candidacy stay absolute on residency (Art. I). Nothing in the activation
  model is ever consulted on a rights path — that invariant is *strengthened*
  here (the cap that could theoretically strand a community is gone).
- The seat law is untouched: cube-root chamber sizing + the 5–9 district band +
  the giant-cascade apportionment (`CLAUDE.md` Apportionment Law). R-B decouples
  activation FROM that band; it does not alter it.
- The audit chain stays the global append mutex; all provisioning stays
  set-based/chunked (the ETL rule).
