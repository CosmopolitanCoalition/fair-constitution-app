# Institution Scaling Plan — provisioning the planet's institutions and rooms

**Lane 3 · 2026-07-25 · absorbs the Phase I design (activation tiers + reach/legitimacy)**

Status: **PLAN ONLY.** No code ships until the operator settles eager-vs-lazy (§5.6).
Owner: lane 3 (`docs/plans/scaling/`). Consumed by lane 2 (cloud sizing), lane 4 (simulated
world), lane 13 (per-jurisdiction economic objects), lane 15 (reach gauge).

---

## 1. Why this plan exists

Lane 1's autoscale run left the planet **sized and mapped but institutionally empty**: 955,130
legislatures, every one of them `status='forming'` with zero members, and nothing else. Before
players arrive — the earth.* Standard instance on 2026-09-01, the simulated world later — every
jurisdiction needs its institution set *derivable* (you can say exactly what it should have) and
*provisionable* (something can create it), with the social infrastructure adapting as population
fills in rather than being pre-built.

Phase I is inside this plan rather than beside it, because **activation tiers are the
provisioning dial**. What exists in a place is a function of how far that place has come.

Prior authorities this plan builds on and does not restate: the roadmap's Phase I charter
(`docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md:196-228`), the built-vs-unbuilt audit
(`docs/plans/docs-recon/BUILT_INVENTORY.md` §3), K-1 and K-3's implementation plans, and the
autoscale pull engine that lane 1 proved at planet scale.

---

## 2. The state of the planet, measured

Read from the live game-box database (`fc_postgres`) on 2026-07-25 using bounded per-layer
queries. The method matters: a single planet-wide aggregate over `jurisdictions` **times out at
110 seconds** on this box because the table is 6.2 GB of PostGIS geometry. Chunking by
`adm_level` returns each layer in seconds. That is the ETL RULE showing up as an empirical fact
rather than a slogan.

### 2.1 Jurisdictions by layer

| Layer | Jurisdictions | Median population | Max population | Σ population |
|---|---:|---:|---:|---:|
| L0 Earth | 1 | 7,991,888,892 | — | 7,991,888,892 |
| L1 | 232 | 5,515,074 | 1,431,026,120 | 7,991,888,892 |
| L2 | 3,243 | 586,351 | 243,656,149 | 8,019,553,917 |
| L3 | 49,540 | 25,844 | 18,431,397 | 8,021,370,665 |
| L4 | 106,096 | 3,586 | 10,780,479 | 5,172,224,854 |
| L5 | 95,042 | 1,514 | 9,233,965 | 2,077,396,906 |
| L6 | 700,976 | 765 | 13,812,999 | 1,531,350,271 |
| **Live total** | **955,130** | | | |

(956,336 rows exist; 1,206 are soft-deleted. Layer sums differ from Earth's stored figure —
that is the accepted geodata population noise, not an error.)

### 2.2 What exists, and what does not

| | Count |
|---|---:|
| Legislatures (live) | **955,130** — one per live jurisdiction, 1:1 verified |
| …all with `status='forming'` | 955,130 |
| …`status='active'` | **0** |
| Seats: type_a / type_b / total | 13,131,216 / 2,162,871 / **15,294,087** |
| Chambers over the ceiling of 9 | 514,036 |
| Bicameral chambers | 48,189 |
| District maps / districts / district seats | 955,130 / **1,895,048** / 13,111,461 |
| `constitutional_settings` rows | 951,622 (all jurisdiction-scoped; **0** carry a threshold) |
| `audit_log` entries | 1,965,017 (1,599 MB, ~853 bytes/entry) |
| Database total | **28 GB** |
| Activations · executives · judiciaries | 0 · 0 · 0 |
| Committees · departments · elections · members | 0 · 0 · 0 · 0 |
| Matrix rooms · social spaces · subforums | 0 · 0 · 0 |
| Election boards | 1 (the root R-08 bootstrap board) |
| Users · active residency confirmations | 1 · 0 |
| `clocks` registry rows | **0** — `ClockRegistrySeeder` has never run on this box |

The 1:1 claim was verified rather than assumed: on `legislatures`,
`count(DISTINCT jurisdiction_id) = count(*) = 955,130`, equal to the live jurisdiction count.

**Correction to the fleet ledger.** The 12:38 board entry records "~955,130 legislatures across
~951,626 jurisdictions." There are 955,130 live jurisdictions, each with exactly one
legislature. 951,626 appears to be the older figure from the original lane prompts; the nearby
real number is 951,622 `constitutional_settings` rows.

### 2.3 On-disk cost anchors

Used throughout §5 instead of guessed per-row costs.

| Table | Total size | Rows | ≈ bytes/row |
|---|---:|---:|---:|
| `jurisdictions` | 6,247 MB | 956,336 | — (geometry) |
| `legislatures` | 325 MB | 955,130 | ~340 |
| `constitutional_settings` | 582 MB | 951,622 | ~611 |
| `legislature_districts` | 823 MB | 1,895,048 | ~434 |
| `legislature_district_maps` | 360 MB | 955,130 | ~377 |
| `audit_log` | 1,599 MB | 1,965,017 | ~853 |

---

## 3. Item 0 — the activation gate cannot fire

Before any of the design below matters, one defect has to be recorded, because it silently
disables the entire Phase I substrate on the seeded planet.

`app/Jobs/Clocks/EvaluateCriticalPopulationJob.php:41-57` enumerates activation candidates:

```sql
SELECT rc.jurisdiction_id, count(*) AS verified_residents
FROM residency_confirmations rc
WHERE rc.is_active
  AND NOT EXISTS (SELECT 1 FROM legislatures l
                  WHERE l.jurisdiction_id = rc.jurisdiction_id AND l.deleted_at IS NULL)
  AND NOT EXISTS (SELECT 1 FROM jurisdiction_activations a
                  WHERE a.jurisdiction_id = rc.jurisdiction_id
                    AND a.deleted_at IS NULL AND a.state <> 'boundary_loaded')
GROUP BY rc.jurisdiction_id
```

Every live jurisdiction now has a legislature (§2.2), so `NOT EXISTS (legislatures …)` excludes
**the entire planet**. The job is dispatched unconditionally every minute
(`app/Jobs/EvaluateClocksJob.php:65`) and can never return a candidate.

The predicate was correct when it was written: "has a legislature" then meant "is already
governed". After the autoscale run it means nothing of the sort — a `forming` chamber with zero
members is scaffolding.

**The fix.** Test governance, not row existence. A jurisdiction is a candidate when it has
active residents and is not yet self-governing:

```sql
SELECT rc.jurisdiction_id, count(*) AS verified_residents
FROM residency_confirmations rc
WHERE rc.is_active
  AND NOT EXISTS (SELECT 1 FROM jurisdiction_activations a
                  WHERE a.jurisdiction_id = rc.jurisdiction_id
                    AND a.deleted_at IS NULL
                    AND a.state IN ('critical_population','bootstrapping','self_governing'))
GROUP BY rc.jurisdiction_id
HAVING count(*) >= <threshold>
```

Dropping the legislature predicate entirely is safe because `ActivationService::ensureLegislature()`
already **adopts** an existing legislature untouched (`app/Services/ActivationService.php:361-368`),
and `onCriticalPopulation` refuses to re-enter a state it has passed (`:166-172`).

**Two scale defects in the same job, fixed at the same time.**

1. It materialises the whole result set into PHP with no cursor or chunk, at `tries = 1`.
2. It calls `SettingsResolver::resolveInt()` **per candidate**, each a recursive CTE up to 32
   levels. The resolver memo is keyed `jurisdictionId:column`, so it gives zero reuse across
   distinct jurisdictions, and with **zero** thresholds set anywhere (§2.2) every walk runs to
   full depth and falls through to the config default.

Once the threshold is a pure function of population (§5.3) it folds into the SQL and the
per-candidate walk disappears for the common case:

```sql
HAVING count(*) >= GREATEST(:floor,
         LEAST(:cap, ceil(:k * power(GREATEST(j.population, 1), 1.0/:exponent))))
```

with the per-jurisdiction cascade consulted only where an explicit override row exists. The
sweep then reads bounded batches ordered by `jurisdiction_id` (keyset), never the whole set.

**Ownership.** This sits on the launch path — until it lands, nobody can activate on the live
instance except by `jurisdiction:activate --force`. Offered to lane 2 for their window; lane 3
carries it otherwise.

---

## 4. (a) The per-jurisdiction formula table

What the code does today, with a constitutional citation and a code citation on every row.
"P" is `jurisdictions.population`; "S" is the chamber's `type_a_seats`.

### 4.1 The chamber

| Object | Count / size | Constitutional basis | Code |
|---|---|---|---|
| Legislature | exactly **1** per jurisdiction; adopted, never resized | Art. V §1 | `ActivationService.php:361-403` |
| `type_a` seats, parent | `max(5, round((Σ children P)^⅓))` — **no ceiling** | Art. II §2 (floor) | `ApportionmentSeedCommand.php:246-247`; `ConstitutionalDefaults.php:54-63` |
| `type_a` seats, leaf | `max(5, round((own P)^⅓))` — **no ceiling** | Art. II §2 | `ActivationService.php:93-98, 448` |
| `type_b` seats | `Σ_children (P_c ≤ 5 ? min(P_c, f) : f)`, `f` descending 5→2 until `type_b ≤ type_a` | Art. V §3 | `TypeBSeatLadder.php:40-73` |
| Quorum | `max(3, ceil(total_seats / 2))` | Art. II §2 | `ActivationService.php:144-147` |
| Districts | `ceil(S / 9)` when `S > 9`; **each district 1–9 seats** | Art. II §2 (subdivision) | `ActivationService.php:630`; CHECK `election_races_seats_check` |

> **The 5–9 band binds districts, not chambers.** 514,036 chambers exceed nine seats and
> Earth's holds 1,999; all 1,895,048 drawn districts sit between 1 and 9 (average 6.92,
> database-enforced). The leaf ceiling clamp was **retired 2026-07-19** — a childless chamber
> above the ceiling keeps its lawful size and gets line-split districts
> (`ActivationService.php:618-637`).
>
> **Documentation drift to fix while here:** three docblocks in `ActivationService.php`
> (`:32-37`, `:48`, `:556-559`) still describe the retired clamp, and
> `pgsql-schema.sql:3090` still comments `total_seats` as "Between 5 and 9".

### 4.2 Institutions created eagerly by WF-JUR-01

Every write performed by `ActivationService::activate()` (`:213-297`):

| Object | Count | Shape | Constitutional basis | Code |
|---|---:|---|---|---|
| Activation row | 1 | `boundary_loaded → critical_population → bootstrapping → self_governing` | Art. II §1 | `pgsql-schema.sql:2694-2706` |
| Executive | 1 | `type='committee'`, `status='forming'` | Art. III — executives start legislature-delegated | `InstitutionStubService.php:64-77` |
| Judiciary | 1 | `'Superior Court'`, `type='appointed'`, `min_judges=5`, `term_years=10` | Art. IV §1 | `InstitutionStubService.php:79-92` |
| Bootstrap election board | 1 | `is_bootstrap=true`, status active | Art. II §2 | `ActivationService.php:521-526` |
| Bootstrap board member | 1 | `user_id = NULL` — the system itself | — | `ActivationService.php:528-532` |
| Bootstrap election | 1 | `kind='general'`, `trigger='bootstrap'` | F-ELB-001 | `ElectionSchedulingOrder.php:312-323` |
| Election races | 1 per district, else 1 per seat kind | `finalist_count = multiplier × seats` | Art. II §2 | `ElectionLifecycleService.php:700-722` |
| Clock timers | up to 3 | CLK-18 cutoff, CLK-01 open, CLK-01 close | — | `ElectionLifecycleService.php:898-943` |
| Audit entries | ~5–6 | hash-chained | WF-SYS-04 | `AuditService.php:63-101` |

Members, seats and appointments are **not** created — stubs only
(`InstitutionStubService.php:15-16`), and the chamber stays memberless until an election
certifies.

### 4.3 Institutions that are structurally zero

These are acts of self-government. **No provisioning engine may create them.**

| Object | Count at t=0 | Why | Code |
|---|---:|---|---|
| Committees | **0** | vote-created by F-LEG-009; no catalog, seeder or config anywhere | `CommitteeService.php:115-170` |
| Departments | **0** | *"NEVER auto-seeded — Art. II §9 says legislatures create them"* | `DepartmentService.php:25-32` |
| Boards of Governors | **0** | one per **department**, not per jurisdiction | `DepartmentService.php:132-137` |
| Admin offices, organizations, CGCs | **0** | act- or user-driven | F-LEG-013, R-23 |
| Treasury / accounts | **0** | no such table; fiscal rows are act-driven appropriations | — |

Sizes when they *are* created: committee seats are operator-chosen (≥1) with only the
type_a/type_b split derived by largest remainder (`CommitteeService.php:61-103`); an executive
committee seats `5 ≤ n ≤ serving members` (Art. III §2, `ExecutiveFormationService.php:62-77`);
a department's board fixes `owner_seats ≥ 1` by charter (Art. III §4); an elected judicial race
seats ≥5 with no ceiling (Art. IV §1, `JudiciaryFormationService.php:86-99`); a case panel is 3,
5, or en banc by severity, always odd (`PanelSizing.php:33-59`); a real election board seats ≥3
(`ElectionBoardTransitionService.php:83`).

### 4.4 The room layer

| Object | Count per jurisdiction | Trigger | Code |
|---|---|---|---|
| Matrix `m.space` | 1 | on seating | `SocialTopologyReconcilerService.php:35-38` |
| Matrix `#square` | 1 | on seating | `:40-43` |
| Matrix `#halls` | 1 — **only if seated AND activated** | on seating | `:47-51` |
| Plane A `social_spaces` | 2 (`public_square`, `halls`) | on seating | `EvaluateSocialStructureJob.php:41-49` |
| `social_subforums` | 1 per live governance object (bills, petitions, referendum questions, committee meetings, candidacies) | reconciled | `SubforumReconciler.php:82-160` |
| LiveKit rooms | **0 pre-created** | first participant join | `LiveKitTokenService.php:36-53` |

**Rooms are already lazy.** Nothing in `jurisdiction:activate` touches a room. Provisioning is
event-driven on **seating** — `CertificationService.php:1000-1006` dispatches
`EvaluateSocialStructureJob` asynchronously, deliberately never `dispatchSync`, so a slow or
dead homeserver can never block certification — with a nightly backstop sweep at 00:30 scoped
to `Legislature::STATUS_ACTIVE` (`routes/console.php:73`). Matrix mirroring sits inside a
try/catch so Plane A rows land even when Synapse is down (`EvaluateSocialStructureJob.php:56-64`).

Room kinds that exist as constants with **zero production callers** — per-institution rooms,
organization spaces, per-object rooms — are designed but unbuilt (`app/Models/MatrixRoom.php:19-40`).

---

## 5. (b) Planet totals and the provisioning economics

### 5.1 Method

Every figure is a bounded read. Counts by layer use the `adm_level` index one layer at a time;
aggregates over `legislatures` and `legislature_districts` run as single indexed passes that
complete in seconds; the wide `jurisdictions` table is never scanned whole. The engine (§7)
reads by keyset in chunks of 25,000, matching `AutoscaleEnumeration`'s `CHUNK` constant.

### 5.2 Totals at full planet activation

| Object | Planet total | Basis |
|---|---:|---|
| Activation rows | 955,130 | 1 each |
| Executives | 955,130 | 1 each |
| Judiciaries | 955,130 | 1 each |
| Bootstrap boards + members | 955,130 + 955,130 | 1 each |
| Bootstrap elections | 955,130 | 1 each |
| Election races | ~1,895,048 | 1 per district |
| Audit chain entries | **~4.8M** | ~5 per jurisdiction |
| **Legislature seats to fill** | **15,294,087** | measured |
| Judicial seats at the minimum of 5 | 4,775,650 | Art. IV §1 floor |
| Executive seats at the minimum of 5 | 4,775,650 | Art. III §2 floor |
| Plane A social spaces | 1,910,260 | 2 per seated jurisdiction |
| Matrix rooms, as-built topology | **2,865,390** | 3 per seated jurisdiction |

Storage: institution rows ≈ **2–3 GB** at the measured 340–610 bytes/row, plus **~4 GB** of
audit chain at 853 bytes/entry — roughly 25% growth on a 28 GB database. Affordable.

### 5.3 The constraint that actually binds: the audit chain is a global mutex

`AuditService::append()` takes `pg_advisory_xact_lock(0x4155444954)` on **every** append —
"one global appender at a time → the head read is always current → the chain cannot fork"
(`AuditService.php:36-40, 77`). The docblock records why a `FOR UPDATE` on the head row was
insufficient: a second appender re-read the stale head after the first committed and anchored a
sibling, forking the chain, "observed on the live multi-worker stack: scheduler + Horizon
racing" (`:51-62`).

So **pull workers buy nothing for audited writes.** Measured on this box: the head read
(`ORDER BY seq DESC LIMIT 1`) is an index-scan-backward, 10 ms cold and negligible warm, so the
real per-append cost is the commit — and `synchronous_commit` is `on`, so every one fsyncs. At
1–3 ms per serialized append, ~4.8M appends is **1.5 to 4 hours of irreducible single-threaded
time**, against a ~2 h whole-planet anchor for the districting sweep *with the fleet working in
parallel*.

**Consequence, binding on §7:** the engine must not call `ActivationService::activate()` in a
loop. It follows the precedent already in the codebase — `AutoscaleSizingJob` provisions leaves
set-based, and `autoscale:revert` appends **one** chain entry for an entire revert. Institution
writes go out as bounded committed chunks with **one chain entry per chunk**, carrying the chunk
manifest in its payload. Tamper-evidence is preserved; the fleet is not serialized.

### 5.4 Three idempotency gaps that block parallel provisioning

`InstitutionStubService` decides "does one already exist?" by reading, then inserting — safe
single-threaded, unsafe the moment two workers claim neighbouring batches. Verified on
PostgreSQL 17.5:

| Table | State | Effect |
|---|---|---|
| `judiciaries` | **no uniqueness on `jurisdiction_id`** — primary key only | two workers → two Superior Courts |
| `executives` | `UNIQUE (jurisdiction_id, deleted_at)` | **ineffective**: unique indexes treat NULLs as distinct, and every live row has `deleted_at IS NULL`, so duplicate live rows do not conflict |
| `elections` | primary key only | duplicate bootstrap elections |

Safe by design, for contrast: `jurisdiction_activations` (`UNIQUE (jurisdiction_id)`),
`election_boards` (`election_boards_one_active`, partial on the active row), `social_spaces`
(partial `WHERE deleted_at IS NULL`), `matrix_rooms` (`NULLS NOT DISTINCT`), `social_subforums`.

The correct patterns are therefore already in this schema — this is inconsistency, not
ignorance. **Fix: one short additive migration** adding a partial unique index
`WHERE deleted_at IS NULL` on `judiciaries(jurisdiction_id)`, replacing the executives index
with the same shape, and adding a partial unique on `elections` for the bootstrap general
election.

> **A trap worth naming, because it looks like protection and is not:** the global audit
> advisory lock serializes chain **appends**, not the reads that precede them. Both workers can
> read "no judiciary here", queue politely for the lock, and both insert. Constraints — not the
> audit mutex — are what make provisioning re-entrant.

**These land before any parallel engine runs.** Otherwise the planet acquires duplicate
institutions that no later pass can safely disambiguate.

### 5.5 The room economics

| Topology | Matrix rooms at full planet seating |
|---|---:|
| As-built (Space + `#square` + `#halls`) | 3 × 955,130 = **2,865,390** |
| \+ per-institution rooms (legislature, executive, judiciary) | +2,865,390 → **5.73M** |
| \+ BoG rooms at full department build-out (5 mandatory kinds) | +≤4,775,650 → **10.5M** |
| \+ one room per candidacy at the bootstrap election | +≥15,294,087 → **>25M** |

Against that, the deployment: **one** Synapse container (`docker-compose.yml:206-232`), a single
HTTP listener on 8008 carrying both client and federation resources, and `cp_min: 5 / cp_max: 10`
Postgres connections — the only numeric capacity knob in the repository
(`docker/matrix/conf.d/10-cga.yaml`). There is **no** `worker_app`, no `instance_map`, no
`stream_writers`, no replication listener, no `rc_*` overrides, no `event_cache_size`. Synapse
wants ≥4 GB RAM merely to boot (`K3-IMPLEMENTATION-PLAN.md:123-124`).

The repository already concedes the point twice:

- *"Planet-scale replication is pathological — one giant room is forbidden; topology is always
  Spaces + per-jurisdiction rooms; the durable record is Plane A. Sharding is out of K-3 scope."*
  (`K3-IMPLEMENTATION-PLAN.md:135-136`)
- *"Large public jurisdictions need a sharding/room-partition strategy before O-scale."*
  (`K3-MATRIX-RESEARCH-AND-DESIGN.md:384`)

The gap between one unsharded Synapse and 2.87M rooms is two to three orders of magnitude —
a category error, not a tuning problem.

### 5.6 (d) Eager vs lazy — the recommendation

**The question is three questions**, because the objects differ in cost by orders of magnitude.

**Band 1 — scaffolding. Already eager, already paid.** 955,130 legislatures, 955,130 maps,
1,895,048 districts, 951,622 settings rows: 2.1 GB of the existing 28 GB. Nothing to decide.

**Band 2 — institution rows. Recommend EAGER, tier-gated.** ~5 rows and ~5 chain entries per
jurisdiction; ~2–3 GB of rows plus ~4 GB of audit chain. Cheap in storage; the binding cost is
the serialized chain (§5.3), which is why they are written in chunks with one chain entry per
chunk. Eager here buys a real property: every jurisdiction can *show* its institutions and its
first election before anyone lives there, which is what makes the world browsable.

**Band 3 — rooms. Recommend LAZY — and note it already is.** Rooms are provisioned on seating
with a nightly backstop, and LiveKit never pre-creates anything. **Eager rooms would be a
deliberate regression against working code**, and it would target the one component that
provably cannot hold the volume.

#### The two worlds

**Live Standard instance (earth.*, 2026-09-01).** Real consent only, zero synthetic data.
Realistically a few hundred to a few thousand residents in year one, concentrated in a handful
of places. Under this recommendation: Band 1 unchanged; Band 2 provisions only for jurisdictions
crossing their tier — dozens to low hundreds of jurisdictions, a few MB; Band 3 creates two or
three rooms per *seated* place, so tens of rooms, well inside one Synapse. **Growth is bounded
by real enrolment, not by the planet.** That is lane 2's host-sizing answer: size for the
existing 28 GB plus single-digit-GB headroom, not for a planet-wide institution pass.

**Simulated world (Phase O).** Synthetic population at planetary scale, so Band 2 runs to
completion — ~7 GB and, more importantly, the 1.5–4 h serialized-audit floor unless chunked as
in §5.3. Band 3 is where the sim breaks: a fully seated synthetic planet demands 2.87M rooms.
Mitigations, in the order I would try them: (i) rooms stay demand-driven even in the sim, so
only jurisdictions a demo actually visits materialise; (ii) a per-tier room budget capping
which places get `#halls`; (iii) Dendrite or a Synapse worker deployment, which the repo already
flags as an unresolved spike; (iv) rooms disabled entirely for `scale_demo`, where CI-2 already
forces an empty federation whitelist. **(i) alone is sufficient for a demo** and costs nothing
to build, because it is the behaviour already shipped.

#### The recommendation, in one paragraph

Provision Band 2 eagerly on tier crossing, in chunked set-based writes with one audit entry per
chunk; keep Band 3 strictly demand-driven and make its trigger tier-aware, adding the
population-gated growth toggle that K-1 left as an explicit seam for Phase I
(`K1-IMPLEMENTATION-PLAN.md:299`) so a hamlet gets one bare square and a metropolis grows its
tree. Nothing about Band 3 changes the code's existing posture; it formalises it and puts a dial
on it.

#### The strongest argument against this

Lazy rooms mean the first visitor to a place pays a cold-start cost, and if Synapse is slow or
down that lands at the worst possible moment — someone's first look at their own town. Three
answers: the durable civic record is Plane A in Postgres and never depends on Matrix; room
creation is already inside a try/catch, so a dead homeserver degrades rather than blocks; and a
tier-gated pre-warm can cover the small set of places that actually have residents.

**What would change my mind:** evidence that a single Synapse comfortably holds low-millions of
rooms. Note the recommendation is not fragile to this — at 10× my estimate, 2.87M rooms is still
out of reach, so Band 3 stays lazy either way. What *would* change is the sim-world mitigation
ordering.

**RESERVED TO THE OPERATOR. No code until settled.**

---

## 6. (c) Phase I — the tier curve and the reach layer

Scope is the corrected one from the 2026-07-25 audit: the activation boot-gate is **already
live** end-to-end. What is missing is the tier *curve* and the entire reach half.

### 6.1 The curve

```
tierThreshold(P) = clamp(ceil(k · P^(1/exponent)), floor, cap)
```

Defaults `k=1, exponent=3, floor=5, cap=9`, matching the mockup contract
(`mockups/v3/assets/js/fixtures-v2.js:708`).

**Stored as five nullable columns on `constitutional_settings`, set once at the planet root** —
the roadmap is explicit: *"one amendable row, not 951k thresholds"* (`:211-212`). Nullability is
load-bearing, not stylistic: `SettingsResolver` selects the first ancestor row whose column is
non-NULL (`SettingsResolver.php:60-65`), so a `NOT NULL DEFAULT` would pin every jurisdiction to
its own row and destroy the cascade. A jurisdiction that genuinely needs an override still sets
`critical_population_threshold` on its own row and wins, exactly as today.

Worked against the real planet:

| Scope | Population | Threshold |
|---|---:|---:|
| L6 median | 765 | 9 |
| L5 median | 1,514 | 9 |
| L4 median | 3,586 | 9 |
| L3 median | 25,844 | 9 |
| L2 median | 586,351 | 9 |
| L1 median | 5,515,074 | 9 |
| Earth | 7,991,888,892 | 9 |
| A hamlet | 216 | 6 |
| A hamlet | 27 | 5 |
| Unpopulated / unknown | 0 or NULL | 5 (floor) |

**The curve is deliberately flat**: above 729 population every place needs nine verified
residents. That is the intent. The threshold exists to stop *one actor* booting a government,
not to demand proportional enrolment — and it must never approach gating the franchise. Art. I
makes voting and candidacy absolute on residency alone; the tier gates only when a government
may **boot** (CI-4). The cap is a policy dial (§9).

Two naming hazards the implementation must respect:

- **Two different cube roots.** `cubeRootSeats` sizes a legislature; `tierThreshold` gates a
  boot. Their 5 and 9 coincide numerically with the seat floor and ceiling and must **not**
  reuse `legislature_min_seats` / `legislature_max_seats`. Separate settings, separate meaning.
- **The input is real population; the counter is player population.** The threshold is a
  function of `jurisdictions.population` (WorldPop provenance); what is compared against it is
  `CivicPopulation::of()` — active residency confirmations, *"never WorldPop"*
  (`app/Support/CivicPopulation.php:12-13`). That is owner ruling #15, "player population pegged
  against real population", and both halves already exist.

With `activation_tier_enabled = false` the resolver returns today's config default of 1
unchanged, preserving dev behaviour exactly.

### 6.2 The seam

There is no `ActivationService::thresholdFor()`. The threshold is resolved in two copy-paste
twins — `EvaluateCriticalPopulationJob.php:59-73` and `JurisdictionActivateCommand.php:92-96` —
so a curve added to one silently diverges from the other. The design adds **one** pure static
`ActivationTierService::tierThreshold(int $population, array $params): int` plus a single
resolver method, and rewires both call sites to it.
`ActivationService::onCriticalPopulation($jurisdictionId, $verifiedResidents, $threshold)`
already accepts the threshold as a parameter and records it in `notes` and the audit payload
(`:158`), so a curve-derived value flows through with zero signature churn.

Putting the curve in a **pure static** is deliberate: it matches the established DB-free test
posture (§8) so it can be pinned without a schema, exactly as `cubeRootSeats` is.

### 6.3 Reach

`reach = verified_residents / population_estimate` — honestly named *reach/enrolment*, and
**explicitly not** the Art. VI §3 legitimacy verdict, which is a wartime allegiance test
(roadmap `:202-203`, `:208`).

`legitimacy_snapshots`: `jurisdiction_id`, `as_of_date`, `verified_residents`,
`population_estimate`, `ratio_micro` (integer millionths — no float drift),
`population_provenance` (carried from `jurisdictions.population_assigned_via` and
`population_year`), `suppressed` (bool), `created_at`. Unique `(jurisdiction_id, as_of_date)`;
index `(as_of_date)`. Append-only, following the `approval_standings` precedent.

`LegitimacyService`: `reachRatio()` returning a value object that models the mockup's four
honest states — **unmeasurable** (no population estimate; never rendered as 0%), **activating**
(count null or suppressed), **measured**, **capped** (ratio > 1, bar shows 100% with the raw
figure disclosed, because the estimate lags the place). `snapshotAll()` and `leaderboard()` —
**jurisdiction-only, never people**.

`SnapshotLegitimacyJob` on the established rail:
`Schedule::job(...)->dailyAt('00:40')->withoutOverlapping()->onOneServer()` plus a
`LeaderProbe::isPrimary()` early return inside `handle()` — the documented two-layer HA guard
(`routes/console.php:20-26`). 00:40 is free; 00:10, 00:20 and 00:30 are taken.

**It must not walk 955,130 jurisdictions.** The numerator is a grouped read over
`residency_confirmations`, whose size is *users × ancestry depth*, not the planet — and it has
exactly the right index, the partial `(jurisdiction_id) WHERE is_active`. The job walks the
residency table in keyset chunks and emits a snapshot only where a numerator exists. Places with
no residents are *unmeasurable* and need no nightly row. On the live instance in year one that
is a few thousand rows a night; it stays proportional to real enrolment forever.

**k-anonymity, and the subtlety that has to be closed.** Suppress any jurisdiction whose active
resident count is below `k` (recommend `k = 5`, matching the seat floor's "a crowd is at least
five" intuition), enforced on the **write** side so a suppressed count never reaches storage,
and again on read. But independent thresholding is not enough: because residency confirmations
sweep every ancestor, a parent's count is very nearly the sum of its children's, so publishing a
parent and all-but-one sibling **leaks the remaining child by subtraction**. Suppression
therefore cascades — a parent publishes ratio-only, without counts, whenever any child of it is
suppressed. The same reasoning applies across time: publishing nightly deltas on a place with a
handful of residents reveals individual arrivals, so a suppressed place publishes no series
either.

Rails, restated because they are the point of the feature: reach is *a gauge, never a lever* —
it changes no vote, no seat, no right (CI-1); no per-person score, ever; no leaderboard of
people, only places; only the authoritative instance writes a snapshot (CI-6).

### 6.4 UI

Surface record in `config/cga/surfaces.php` (none exists), `href` filled in at
`resources/js/registry/surfaces.js:102` (currently `null`), and a new page against
`mockups/v3/social/legitimacy.html` — **on `AppShellV2`**, per the fleet shell ruling.

Also the WI-9 gap: `Jurisdictions/Show.vue:680-684` renders `boundary_loaded` identically to
"no row" (both "Dormant"), and `JurisdictionController.php:166-169` sends neither the verified
count nor the threshold — so the mockup's "N more to go" line is unrenderable today. Both need
the small change.

---

## 7. (e) The provisioning engine

Rides lane 1's proven chassis. Where a rail exists, it is reused rather than re-implemented.

### 7.1 Run model

Phases: `enumerating → provisioning → finalizing → done`. **One work item = a chunk of
jurisdictions, not one jurisdiction** — forced by §5.3: per-jurisdiction audited filings
serialize on the global chain lock, so an item is a batch that produces set-based writes and
**one** audit entry. At 1,000 jurisdictions per item that is ~955 items, which is also the right
progress resolution (sub-1% steps) and keeps a reclaim cheap.

Ordering: **simplest-first by layer**, matching autoscale's triage rather than the geodata
plan's largest-first inversion — deep leaf layers dominate by count (700,976 at L6) and
finishing them early makes the per-layer bars move honestly from the start.

### 7.2 Schema

One REAL-dated additive migration (≥ 2026-07-05), mirroring the proven shapes:
`institution_runs` (status, phase, counters, `halt_requested_at`, `paused_until`,
`pg_fingerprint`, phase timestamps), `institution_items` (`run_id`, `kind`, `adm_level`,
`status`, `claim_token`, `position`, `jurisdiction_from/to` keyset bounds, `counts jsonb`,
`reason`, timestamps), and `institution_worker_leases` **byte-compatible with the autoscale
lease display** so the existing worker strip renders unchanged — the same move
`GEODATA_PULL_ENGINE_PLAN.md:84-86` makes.

Index `(run_id, kind, adm_level, status)` so the per-layer progress GROUP BY is index-only,
modelled on `autoscale_items_layers_idx`.

### 7.3 Claim ladder, pump, and the safety rails

Claim, exactly the autoscale shape (`AutoscaleClaims.php:146-190`):

```sql
UPDATE institution_items SET status='running', claim_token=?,
       started_at=COALESCE(started_at, now()), updated_at=now()
 WHERE id = (SELECT id FROM institution_items
              WHERE run_id=? AND status='pending'
              ORDER BY position LIMIT 1 FOR UPDATE SKIP LOCKED)
RETURNING *
```

No `lease_expires_at`, no `attempts` — staleness is `updated_at < now() - 1800s`, identity is
`claim_token`, matching the proven design.

Pump: `Schedule::command('institutions:pump')->everyMinute()->withoutOverlapping(10)
->runInBackground()->onOneServer()`, the **only** liveness root. Duties, in order: pick the
oldest unfinished run and supersede duplicates; halt/resume state machine; breaker tick; stale
reclaims; worker seeding against `HostCapacity`; counter refresh; phase advance; completion and
audit append. **Phase advance belongs to the pump, never a worker.**

Concurrency: `HostCapacity::institutionWorkers()` as a sibling of `autoscaleWorkers()` —
`max(2, min(12, cores − 2))`. One dial, no second governor.

Halt: a DB `halt_requested_at` column, re-checked by the worker via `$run->refresh()` on **every**
claim-loop iteration. Breaker: `pg_postmaster_start_time() || stats_reset` fingerprint, 10-minute
pause on mismatch — copied verbatim, both terms, since a backend-OOM recovery moves `stats_reset`
without a postmaster restart.

Revert (`institutions:revert`, protocol halt → fix → revert → resume): deletes only
**provenance-tagged** rows minted by this engine, never adopted or voted institutions. It must
never delete audit entries — the chain is append-only, so a revert *appends* its own entry, as
`autoscale:revert` does. Certified elections and seated members are likewise never revertible;
if a run reaches seating, revert refuses and says why.

Queue: its own `institutions` queue and its own `supervisor-institutions` block on `redis-long`
(retry_after 14400). **Never shares the `autoscale` queue.**

### 7.4 Live progress bars

Same contract, same component, same feel. Endpoint returns per-layer rows from:

```sql
SELECT kind, adm_level, COUNT(*) AS total,
       COUNT(*) FILTER (WHERE status = 'done')                   AS done,
       COUNT(*) FILTER (WHERE status IN ('running','assessing')) AS running,
       COUNT(*) FILTER (WHERE status IN ('review','failed'))     AS review
  FROM institution_items WHERE run_id = ?
 GROUP BY kind, adm_level ORDER BY adm_level DESC
```

Layer contract `{key, kind, adm_level, total, done, running, review, status}`, unchanged from
`SetupController.php:2120-2131`. Poll every 2000 ms, **always armed while the page is open**,
stopping only on `done`/`failed` — the conditional arming that froze the page is a recorded bug
(`Step3_Districts.vue:218-220`). Numbers tween 1700 ms ease-out-cubic so they land before the
next poll. `paused_until` renders only when it is in the future.

Bars per layer plus overall, the worker strip, and the live-item list all reuse
`Step3_Districts.vue`'s markup.

---

## 8. Test pins

The activation pipeline has **zero** automated coverage today — the only existing file,
`tests/Constitutional/ActivationMathTest.php`, is deliberately DB-free and pins pure statics
(`:14-18`). Pinning the pipeline is part of this work.

**Pure static** (no schema, following the established posture): the tier curve across the real
median populations; `tier(0)` and `tier(null)` both return the floor; the clamp at both ends;
`activation_tier_enabled=false` returns the config default unchanged; the curve is *not*
`cubeRootSeats` (a pin that fails if anyone unifies them).

**Live PG** (`LivePgConnection`, rolled-back transaction, synthetic fixture):
- the four-state machine, including that no reverse transition exists;
- the corrected CLK-06 candidate predicate — returns a jurisdiction that has residents and a
  `forming` legislature, and does **not** return one already `self_governing`;
- **a concurrency pin**: two workers provisioning the same jurisdiction produce exactly one
  executive, one judiciary and one election — this fails today and passes after §5.4's migration;
- reach snapshot k-anonymity, including the **differencing** case: a parent whose children are
  partly suppressed publishes no counts;
- one chunk of provisioning emits exactly one audit chain entry, and the chain verifies.

**Engine**, modelled on `AutoscalePinTest`'s `driveRun` harness (`:70-80`): halt parks and resume
completes; a stale claim is reclaimed and redo never double-provisions; the breaker trips on a
forged fingerprint and hands out zero claims; revert round-trips deterministically and leaves
the audit chain intact.

**Screenshot proof** of the progress page under a real run before this work is called done.

---

## 9. Open questions, reserved to the operator

1. **Eager vs lazy (§5.6).** Recommendation: Band 2 eager and tier-gated, Band 3 lazy and
   tier-aware. No code until settled.
2. **The tier cap.** 9 by the mockup. With cap 9 every place above 729 population needs nine
   verified residents. Higher makes large places harder to boot; lower makes them trivially
   bootable. A policy dial the roadmap marks `[POLICY]`, founder-authored — not an engineering
   constant.
3. **The k-anonymity floor.** Recommending 5. It interacts with the cap: a threshold below the
   suppression floor means a place can activate while its own reach stays hidden, which is
   intended but worth confirming.
4. **Ownership of the Item 0 fix and the §5.4 migration** — lane 2's launch window or lane 3's.
5. **Documentation drift** (§4.1): three stale docblocks and one stale column comment still
   describe the retired ceiling clamp. Cheap to fix; needs a nod because the files are PROTECTED.

---

## 10. What other lanes consume

- **Lane 2 (cloud launch).** Size for the existing 28 GB plus single-digit-GB headroom, not a
  planet-wide institution pass — on the live Standard instance provisioning is bounded by real
  enrolment. **Launch-checklist item: the activation sweep cannot currently fire at all (§3).**
- **Lane 4 (simulated world).** Planet totals in §2 and §5.2; tier vocabulary in §6; the
  sim-world side of eager-vs-lazy and its mitigation ladder in §5.6.
- **Lane 13 (economy).** Per-jurisdiction economic objects sit in Band 2 — same tier gate, same
  chunked-write and audit-batching constraint.
- **Lane 15 (K-2).** The tier curve (§6.1) and the reach denominator (§6.3) are what the reach
  gauge and jurisdiction-only leaderboards consume. Nothing else in K-2 waits on this document.
- **Lane 9/10 (content).** §2.2 carries the measured planet figures; §4.1 carries the seat rule.
  Note Earth is bicameral — 1,999 district-elected seats across 282 districts, **3,140 members in
  total** across two chambers.
