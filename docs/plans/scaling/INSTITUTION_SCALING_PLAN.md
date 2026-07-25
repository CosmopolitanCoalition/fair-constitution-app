# Institution Scaling Plan — provisioning the planet's institutions and rooms

**Lane 3 · 2026-07-25 · absorbs the Phase I design (activation tiers + reach/legitimacy)**

Status: **BUILDING.** Operator ruling 2026-07-25: **eager — "we do it all"**. §5.6's
recommendation is settled in favour of eager provisioning; build log in §11.
Owner: lane 3 (`docs/plans/scaling/`). Consumed by lane 2 (cloud sizing), lane 4 (simulated
world), lane 13 (per-jurisdiction economic objects), lane 15 (reach gauge).

> **Revision 2 (2026-07-25) — corrections from adversarial verification.** Every claim here was
> first verified directly against the live database and the code; a second pass then tried to
> *refute* those claims, and five findings survived and changed the design:
> (1) CLK-06's candidate set is **inverted, not empty** — the only rows that can pass are the
> 1,206 soft-deleted jurisdictions, so the first thing the sweep would ever do is boot a
> government inside a deleted place (§3);
> (2) the provisioning engine must **never write `jurisdiction_activations`** — doing so forges
> the Art. II §1 consent crossing and re-kills CLK-06 by our own hand (§5.2.1);
> (3) the activation threshold is **not amendable by any legislature today**, so the "one
> amendable row" framing is false until two PROTECTED files change (§6.1);
> (4) per-cell k-anonymity is **provably insufficient** on an additive hierarchy — a suppressed
> child is recovered exactly by subtraction (§6.3.1);
> (5) gating the Plane A public square on a headcount is an **Art. I error**, distinct from the
> capacity question about Matrix rooms (§5.6).
> A third verification pass (numbers and Synapse capacity) and the synthesis pass were lost to a
> spend limit; §5.5's capacity argument is therefore **operator-verified reasoning, not
> independently re-checked** — flagged so it is not mistaken for a confirmed figure.

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

Every live jurisdiction now has a legislature (§2.2), so `NOT EXISTS (legislatures …)` is false
for all of them. The job is dispatched unconditionally every minute
(`app/Jobs/EvaluateClocksJob.php:65`).

**The candidate set is not empty — it is inverted, and that is worse.** The query never joins
`jurisdictions` at all: no `deleted_at` filter, no `adm_level`. Measured: live jurisdictions
with no live legislature = **0**; **soft-deleted** jurisdictions with no live legislature =
**1,206**. So the only rows that can ever pass the predicate are the deleted ones, plus any
jurisdiction minted after the autoscale run without a legislature (federation import, manual
split, a later ETL load).

That is a live hazard rather than a curiosity. `residency_confirmations` has an FK
`ON DELETE CASCADE` to `jurisdictions`, but jurisdictions are **soft**-deleted, so the cascade
never fires and an active confirmation outlives its jurisdiction's deletion.
`ActivationService::onCriticalPopulation` performs no liveness check of its own (`:158-201`
reads nothing from `jurisdictions`), so the first thing this sweep would ever do on a populated
instance is **boot a government inside a deleted jurisdiction**.

The predicate was correct when written: "has a legislature" then meant "is already governed".
After the autoscale run it means nothing of the sort — a `forming` chamber with zero members is
scaffolding.

**The fix.** Test governance, not row existence, and make jurisdiction liveness an explicit rail
rather than a side effect of needing `population`:

```sql
SELECT rc.jurisdiction_id, count(*) AS verified_residents
FROM residency_confirmations rc
JOIN jurisdictions j ON j.id = rc.jurisdiction_id AND j.deleted_at IS NULL   -- RAIL, not optional
WHERE rc.is_active
  AND NOT EXISTS (SELECT 1 FROM jurisdiction_activations a
                  WHERE a.jurisdiction_id = rc.jurisdiction_id
                    AND a.deleted_at IS NULL
                    AND a.state IN ('critical_population','bootstrapping','self_governing'))
  AND NOT EXISTS (SELECT 1 FROM legislatures l
                  JOIN legislature_members lm ON lm.legislature_id = l.id
                                             AND lm.deleted_at IS NULL
                  WHERE l.jurisdiction_id = rc.jurisdiction_id AND l.deleted_at IS NULL)
GROUP BY rc.jurisdiction_id
HAVING count(*) >= <threshold>
```

Three things about that shape, each of which a later "cleanup" would otherwise undo:

1. **The `jurisdictions` join must survive every variant**, including the tier-flag-off path
   where the population lookup is not needed. It is the only thing standing between the sweep
   and the 1,206 deleted jurisdictions.
2. **The membership anti-join is load-bearing, not belt-and-braces.** Certification can seat a
   chamber without ever calling `ActivationService` — `activate()` has exactly two callers
   (`JurisdictionActivateCommand.php:112`, `ElectionsDemoCommand.php:250`) — so a seated-but-
   unactivated jurisdiction is reachable, and without this clause the sweep would write
   `critical_population` on top of a working government.
3. **It means "no membership record at all", not "is anyone seated".** Vacancy in
   `legislature_members` is a `status` change with `vacated_at`/`vacancy_reason`, not a soft
   delete. This shape deliberately matches the codebase's own definition of memberless,
   `ActivationService::isMemberlessForming` (`:711-714`). Anyone "fixing" it to
   `lm.status = 'seated'` would re-open a jurisdiction whose entire chamber had vacated.

Dropping the legislature-existence predicate is safe because `ensureLegislature()` **adopts** an
existing legislature untouched (`:361-368`), and `onCriticalPopulation` refuses to re-enter a
state it has passed (`:166-172`). Verified: nothing in `app/` or `database/` ever writes a
`boundary_loaded` row — both insert paths `forceFill` a state first, so the column default is
never taken.

> **Schema note found while verifying:** `residency_confirmations` and `constitutional_settings`
> have **no `deleted_at` column at all**. CLAUDE.md's "Soft deletes: all tables use `deleted_at`"
> is doc drift for at least these two, and any predicate written against them must not assume it.

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
| Activation rows | **0 — never written by the engine (§5.2.1)** | CLK-06 is the sole writer |
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

### 5.2.1 The engine must never write `jurisdiction_activations` — a hard rail

An earlier draft of this plan had the provisioning run stamp every jurisdiction into
`bootstrapping`. That is unconstitutional and self-defeating, for two independent reasons:

1. **It forges the Art. II §1 consent crossing.** `JurisdictionActivation::hasReached()`
   (`app/Models/JurisdictionActivation.php:59-66`) is an index comparison over `STATE_ORDER`, so
   stamping `bootstrapping` makes `hasReached('critical_population')` return **true for all
   955,130** — a planet where nobody lives would report that its consent threshold had been met.
   Consent is the one thing a provisioning engine cannot manufacture.
2. **It re-kills CLK-06 a second time.** §3 removes the legislature predicate; the *other*
   predicate is `a.state <> 'boundary_loaded'`. Writing a non-`boundary_loaded` row everywhere
   satisfies it everywhere, and the sweep is dead again — this time by our own hand.

**The rail:** institution stubs may be minted eagerly as dormant scaffolding, but the activation
state machine has exactly two lawful writers, `CLK-06` and `jurisdiction:activate`. The engine
records its own provenance in its own tables, never in `jurisdiction_activations.state`.

A corollary for revert (§7.3): `jurisdiction_activations` carries a **full** `UNIQUE
(jurisdiction_id)` while the model uses `SoftDeletes`. A soft-deleted activation row is invisible
to every service query but still raises a unique violation on INSERT — so a revert that
soft-deletes activation rows would **permanently wedge** re-provisioning of that jurisdiction.
Since the engine never writes them, it never reverts them either; the rule is simply that this
table is out of the engine's reach in both directions.

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

### 5.4 Four idempotency gaps that block parallel provisioning

`ActivationService::activate()` is idempotent under **sequential** re-run and **not** under
concurrency. Say it in those words, because the code reads as though it were safe:
`InstitutionStubService` decides "does one already exist?" by reading, then inserting
(`:46-59` → `:98-103`, a bare `insert()` with no transaction and no `ON CONFLICT`).

The row lock does not help. Step 1 takes `lockForUpdate()` on the activation row, but the only
early return is `state === self_governing` (`:226-228`, `:251-253`) — so worker B, unblocking to
find `bootstrapping`, **proceeds anyway**. And in `ElectionLifecycleService::scheduleGeneral`
(`:136-142`) the `lockForUpdate()` sits on a SELECT that returns **zero rows**, which locks
nothing at all: both workers see `null` and both create.

Verified live on PostgreSQL 17.5 via `pg_constraint` and `pg_index`:

| Table | Actual uniqueness | Concurrent double-insert? |
|---|---|---|
| `judiciaries` | **`judiciaries_pkey` only** — no unique index of any kind | **yes** — two Superior Courts |
| `executives` | `UNIQUE (jurisdiction_id, deleted_at)`, `indnullsnotdistinct = f` | **yes** — two live rows |
| `elections` | **`elections_pkey` only** | **yes** — two open general elections |
| `election_board_members` | partial unique `… WHERE status='seated' AND user_id IS NOT NULL` | **yes** — the `user_id IS NULL` system member is unconstrained |
| `jurisdiction_activations` | `UNIQUE (jurisdiction_id)`, full | no — one transaction aborts |
| `election_boards` | `election_boards_one_active`, partial on the active row | no — aborts |
| `election_races` | full unique **plus** `election_races_one_at_large_per_kind` | no — already covered |

`indnullsnotdistinct = f` on `executives_jurisdiction_unique` is the decisive fact: PostgreSQL
defaults to NULLS DISTINCT, so two rows sharing a `jurisdiction_id` with `deleted_at IS NULL`
both satisfy the constraint. It guards the soft-delete history and not the live invariant.

The correct patterns are already in this schema — `social_spaces` (partial `WHERE deleted_at IS
NULL`), `matrix_rooms` (`NULLS NOT DISTINCT`), `election_boards` — so this is inconsistency, not
ignorance. **Fix: one additive migration with four partial unique indexes**
(`judiciaries`, `executives`, `elections`, `election_board_members`). Not five: `election_races`
already has `election_races_one_at_large_per_kind`, byte-identical in effect, and proposing a
duplicate would be noise.

Two details that will otherwise be got wrong:

- **The `elections` predicate must be `status NOT IN ('certified','final','cancelled')`**, not
  `status IN ('scheduled','approval_open')`. The status CHECK also admits `finalist_cutoff`,
  `ranked_open`, `voting_closed`, `tabulating`, `audit_rerun` — so the narrow form would mint a
  **second live general election during an open ballot**, which is the exact failure the index
  exists to prevent.
- The `election_boards` `ON CONFLICT` clause must **prove** predicate implication against the
  stored index predicate at build time rather than assuming the planner infers it.

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

**Band 1½ — the Plane A public square. Recommend EAGER and UNGATED. This is an Art. I correction.**
An earlier draft gated the `social_spaces` `public_square` row on ≥1 *verified* resident. That is
a speech gate keyed to a headcount, and it fails the same test this document applies everywhere
else. Someone 29 days into residency monitoring (role R-02) would have nowhere to organise, and a
place with real people but no confirmations yet would have no square to recruit into — the
chicken-and-egg moved down one integer rather than removed. One row is ~350 bytes, so all 955,130
squares cost **≈0.33 GB**, comfortably inside the Band 1 budget already accepted. **Gating the
durable record of the square on a headcount is the CI-4 error; gating the Matrix room on capacity
is a legitimate engineering decision.** Different objects, decided separately.

**Band 2 — institution rows. Recommend EAGER, tier-gated.** Executive, judiciary, election board
+ member, bootstrap election — **never the activation row** (§5.2.1). ~4 rows and ~4 chain
entries per jurisdiction; ~2–3 GB of rows plus ~4 GB of audit chain. Cheap in storage; the
binding cost is the serialized chain (§5.3), which is why they are written in chunks with one
chain entry per chunk. Eager here buys a real property: every jurisdiction can *show* its
institutions and its first election before anyone lives there, which is what makes the world
browsable.

**Band 3 — Matrix and LiveKit rooms. Recommend LAZY — and note they already are.** Rooms are
provisioned on seating with a nightly backstop, and LiveKit never pre-creates anything. **Eager
rooms would be a deliberate regression against working code**, and it would target the one
component that provably cannot hold the volume.

> **A disclosure channel this creates, named rather than hidden.** Synapse's public room
> directory is enumerable, so the existence of `#square-<slug>` publishes "this place has ≥1
> verified resident" whatever k-anonymity is applied to the numbers (§6.3). Making Plane A
> unconditional removes the Plane-A half of that leak. The Plane-B half is inherent to
> demand-driven creation and must be either accepted explicitly as a `≥1` band disclosure with
> that reasoning written down, or mitigated by creating the room on first *visit* rather than
> first resident.

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

#### The amendability premise is false today, and fixing it touches PROTECTED files

This document, the roadmap, and the mockup all describe the threshold as policy a legislature can
amend. Verified: **it cannot.**

- `SettingsController::REGISTER_KEYS` (`app/Http/Controllers/Legislature/SettingsController.php:38-56`)
  lists 17 keys. `critical_population_threshold` is **absent** — so the settings register never
  displays it and `BillController` never offers it.
- `ConstitutionalValidator::SETTING_BOUNDS` (`app/Services/ConstitutionalValidator.php:169+`) —
  **absent**. At `:332` any bill naming a key outside that table is rejected outright:
  *"[key] is not an amendable constitutional setting."*
- `ConstitutionalSettings::$fillable` omits it likewise.

So shipping five `activation_tier_*` columns without registering them would deliver **a policy
dial no legislature can amend and no citizen can see** — precisely contradicting the charter
framing this design rests on. Registering them means editing `ConstitutionalValidator.php` and
`ConstitutionalSettings.php`, **both on the PROTECTED list**, so this is constitutional-review
work and must not be presented as a purely additive migration.

**The bounds must carry a MAX, and that is a rights question, not a tidiness one.** Because
`SettingsResolver` takes the nearest non-NULL ancestor, an unbounded setting lets *any* ancestor
— up to Earth — set an arbitrarily large threshold or `cap` and render every descendant
**permanently unbootable**. That is the franchise harm this curve exists to avoid, arriving by
the back door. Bounds: `k ≥ 1`, `exponent` fixed at 3, `floor ≥ 1`, `cap ≥ floor` and `cap ≤` a
hard ceiling, cited to Art. II §1 and Art. I — plus a pin asserting no resolved threshold can
exceed the cap.

**Alternative, if the operator prefers:** declare the curve params operator/founder-only
configuration rather than legislative settings, and say so plainly in the doc and the UI. What
is not acceptable is the current in-between, where the text calls them amendable and the code
makes that impossible.

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

**A third reader exists and must be named.** `ActivationTierService` is the only place that
resolves the *effective activation threshold*, but it is **not** the only place that reads the
column. `ClockService::resolvedInt` (`app/Services/ClockService.php:258-276`) is generic: it
takes any clock's registry `setting_key` and calls `SettingsResolver::resolveInt`. CLK-06's
registry row names `critical_population_threshold` and is `amendable => true`
(`ClockRegistrySeeder.php:104-111`), so that path resolves the **raw column, bypassing the
curve** — and since CLK-06's `default_value.value` is `null`, it would fall through to the
caller's fallback rather than to the tier. It is unreachable today only because CLK-06 is
`type => 'threshold'` and never arms a timer, and the sole caller
(`ClockRederivationService.php:44-66, 92`) walks armed timers. Pin that CLK-06 never arms, or
route `resolvedInt` through the tier service for CLK-06. Do not leave it undocumented.

### 6.3 Reach

`reach = verified_residents / population_estimate` — honestly named *reach/enrolment*, and
**explicitly not** the Art. VI §3 legitimacy verdict, which is a wartime allegiance test
(roadmap `:202-203`, `:208`).

That disclaimer has to be **in the artefacts, not just in this document**, because everything
this layer is named — `LegitimacyService`, `legitimacy_snapshots`, `legitimacy:revert`,
`cga.legitimacy_k_anon_floor`, the audit event `legitimacy.snapshot_run` — invites exactly the
conflation the charter forbids. Art. VI §2–3 is live implemented code, not an abstraction:
`RestorationService` and `RestorationEvent` (`:10-12`) carry judicially-confirmed restoration on
`countermanded | captured | destroyed`. Conflating a headcount with that regime opens a road to
"this place has low reach, therefore its government is restorable." So the disclaimer appears in
four places — the `LegitimacyService` class docblock, the table and column comments, the surface
`citation` string, and visible copy on the Reach page — and a grep pin asserts that no
`legitimacy_snapshots` value is ever read by `RestorationService` or any other Art. VI path.

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

### 6.3.1 k-anonymity — per-cell suppression is provably insufficient here

Suppress any jurisdiction whose active resident count is below `k` (recommend `k = 5`), enforced
on the **write** side so a sub-`k` count never reaches storage, and again on read. That is the
starting point, not the design. Adversarial verification found four ways through it, and the
first is exact arithmetic rather than a residual risk.

**(a) Parent-minus-children differencing is exact.** `ResidencyService`'s F-IND-006 sweep
(`:208-215`, `:539-580`) writes **one confirmation row per distinct enclosing jurisdiction** over
the whole ancestor chain, deduped by `MIN(depth)`. For a tree partition that makes
`verified(parent) = Σ verified(children)` **identically**. So a suppressed child is recovered as
`parent − Σ(published siblings)` whenever exactly one sibling is suppressed — and that is the
*normal* case, since L6 (median population 765) sits under L3 parents (median 25,844) that will
be far above any k. This fires across the majority of the tree.

The fix is **complementary (secondary) suppression**, computed bottom-up once per run: a parent
publishes counts only when **at least two** of its children are suppressed *and* the suppressed
group itself sums to ≥ k; otherwise the parent is suppressed or coarsened too. The alternative is
to publish only **banded** counts (fixed multiple plus a per-jurisdiction fixed offset) so
differences are not invertible. Either is a design change, not a footnote.

**(b) The WI-9 gate is a second, live channel that bypasses the nightly suppression entirely.**
Publishing `verified_residents` and `remaining` on the jurisdiction viewer, computed from a live
`COUNT(*)`, hands an observer the count at **sub-minute resolution** — strictly worse than the
nightly table, and it lets attack (a) run continuously. The WI-9 gate must read the **same
suppression decision** the snapshot writes, never a live raw count. Where §6.4 and this section
would otherwise conflict, the stricter rule wins.

**(c) Temporal differencing.** The design handles k *rising*; it does not handle a count
*falling* below k when residents relocate (`ResidencyService.php:293-297` deactivates
associations outside the new sweep). A place that published 11 last night and is suppressed
tonight is thereby bounded at ≤ k−1, and monthly keyframes are kept forever. So suppression must
apply **retroactively to the stored series**, or raw counts must never be stored at all — only
the banded value. Note the interaction with the chunk hash chain: the digest must be computed
over the **published** tuple, not the raw one, or the chain becomes a permanent commitment to
the secret.

**(d) A pre-existing sub-k publication this plan must stop treating as untouchable.**
`ActivationService::onCriticalPopulation` writes the raw `verified_residents` into both
`jurisdiction_activations.notes` and an `audit_log` payload (`:174-197`). The chain is
hash-chained and append-only, and the doctrine is explicit that *"the chain is the public
record"* (`AuditChainController.php:14-21`). So this count can never be redacted, and Phase 6
FF&C replicates rows. §6.2's "zero signature churn" claim is therefore wrong on the privacy
axis: `onCriticalPopulation` must record the crossing as a **boolean or banded fact**
(`threshold`, `met: true`, `count_band`), never the raw sub-k integer — in `notes` and in the
audit payload alike.

**Channels to enumerate and cover with one decision.** Anything that is a function of the
verified count leaks it: the nightly snapshot, the WI-9 gate, `jurisdiction_activations.state`
and `critical_population_at` (both **already public** via `JurisdictionController.php:166-169`,
`:203-212` — with `threshold = 5` and `k = 10`, the state alone is a sub-k disclosure carrying a
timestamp), Matrix room existence, and Plane A space existence. Either one suppression decision
governs all of them, or the coarser ones are accepted explicitly as `≥ threshold` band
disclosures with the reasoning written down. Silence is not an option.

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

#### 7.1.1 The elections phase needs a pre-flight, or it ratifies map defects forever

Minting one race per district looks obvious and is wrong in two places. Both are the seating law
being quietly overridden by an engine, which is exactly what CLAUDE.md forbids.

**Seat drift.** Planet-wide, `type_a` totals 13,131,216 while drawn districts hold 13,111,461 —
a gap of **19,755 lawful seats with no district to elect them in**. The settled law says that
drift is *the drawing's defect, fixed by redrawing, never by a redistribution loop*. Minting
races district-by-district converts a map defect into a **permanent electoral fact**: those
19,755 seats silently never get a race, and once ballots exist the drift is no longer
diagnosable. So the `enumerating` phase computes, per legislature,
`seat_drift = type_a_seats − Σ(district seats)` and **excludes any drifting pool from the
elections phase**, writing it to a flags table for redraw. This is the exactness rule applied one
layer up, and it is not optional.

**Type B chambers awaiting districting.** 9,708 chambers carry `type_b_needs_districting`,
set by `TypeBSeatLadder` when the ladder is still over `type_a` at `rep_floor = 2`. By the
settled 2026-07-19 ladder those are explicitly **not** at-large bodies, so minting a single
at-large `type_b` race for them contradicts the ruling. The elections phase skips `type_b` race
creation wherever that flag is set, and flags them instead.

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

Three constraints on revert that adversarial review surfaced, each of which turns a plausible
implementation into a destructive one:

- **The delete list must be exactly the engine's own write set.** An earlier sketch of the
  TRUNCATE fast path listed `committees`, `departments` and `legislature_members` — none of which
  this engine ever creates (§4.3). Naming a seated-member table in a delete order is a standing
  hazard that no guard should be trusted to hold. Those three appear **only in the refusal
  guards**, never in the delete order.
- **`jurisdiction_activations` is out of bounds in both directions.** The engine never writes it
  (§5.2.1), so it never reverts it. This matters beyond tidiness: the table's `UNIQUE
  (jurisdiction_id)` is full while the model uses `SoftDeletes`, so a soft-deleted activation row
  is invisible to every service query yet still raises a unique violation on INSERT —
  **permanently wedging** re-provisioning of that jurisdiction, with a `critical_population`
  audit entry that can never be reconciled against it.
- **Guards are re-checked per chunk, not once.** A chunked revert runs for minutes; evaluating
  guards 3–8 only at the start means a citizen who files a candidacy mid-revert is deleted out
  from under. The guards are cheap `NOT EXISTS` probes, so they re-assert inside every chunk
  transaction and abort the revert on first violation.

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
- the corrected CLK-06 candidate predicate — returns a jurisdiction with residents and a
  memberless `forming` legislature; does **not** return one already `self_governing`; does
  **not** return a **soft-deleted** jurisdiction (the 1,206-row hole, §3); does **not** return a
  jurisdiction whose chamber is seated but unactivated;
- **the engine never writes `jurisdiction_activations`** — run a full provisioning pass over the
  fixture and assert the table is untouched, and that `hasReached('critical_population')` is
  still false everywhere (§5.2.1);
- **a concurrency pin**: two workers provisioning the same jurisdiction produce exactly one
  executive, one judiciary, one election and one system board member — fails today, passes after
  §5.4's migration. Written against those four tables specifically, since `election_races` is
  already covered and would pass regardless;
- reach k-anonymity, including the **exact differencing** case: a parent with one suppressed
  child publishes no counts; and the **temporal** case: a jurisdiction falling below k has its
  prior series suppressed too;
- `onCriticalPopulation` records a band or boolean, never a raw sub-k count, in `notes` or the
  audit payload (§6.3.1d);
- the elections pre-flight: a legislature with `seat_drift ≠ 0` is excluded and flagged, and a
  `type_b_needs_districting` chamber gets no at-large race (§7.1.1);
- one chunk of provisioning emits exactly one audit chain entry, and `verifyChain()` passes;
- a grep pin: no Art. VI path reads `legitimacy_snapshots`.

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
3. **The k-anonymity floor, and what it costs.** Recommending 5. But note §6.3.1: honest
   suppression on an additive hierarchy needs **complementary suppression or banded counts**,
   which means a good many parents publish a ratio without a count. That is a visible product
   change, not just a privacy setting, and it is worth your eye before it is built. It also
   interacts with the cap: a threshold below the suppression floor lets a place activate while
   its own reach stays hidden — intended, but confirm it.
4. **Are the curve params legislative settings or founder configuration?** (§6.1). Today they
   are neither: the column exists and no legislature can amend it. Making them genuinely
   amendable means editing two **PROTECTED** files under constitutional review. Declaring them
   operator-only is also a legitimate answer. The status quo — documented as amendable, coded as
   unamendable — is the only option that is not.
5. **Ownership of the Item 0 fix and the §5.4 migration** — lane 2's launch window or lane 3's.
6. **Documentation drift** (§4.1): three stale docblocks and one stale column comment still
   describe the retired ceiling clamp. Cheap to fix; needs a nod because the files are PROTECTED.
   Adjacent, and free to fix in the same pass: CLAUDE.md's "Soft deletes: all tables use
   `deleted_at`" is false for `residency_confirmations` and `constitutional_settings` (§3).

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

---

## 11. Build log

**Operator ruling 2026-07-25: eager — "we do it all."** §5.6's three-band recommendation is
settled in favour of eager provisioning. The one refinement carried into the build, stated once
and not re-litigated: the **civic record** is fully eager (every jurisdiction gets its executive,
court, election board and both civic spaces as real rows), while the **Matrix room** that fronts
a space is materialised on first contact, because a single Synapse cannot hold 2.87M rooms
(§5.5). Every place is fully built; only the chat transport is lazy.

### Shipped

| Item | Artefact |
|---|---|
| Live-row uniqueness (§5.4) | `database/migrations/2026_07_25_000002_institution_live_uniqueness.php` — four partial unique indexes |
| Re-entrant stubs | `InstitutionStubService` now `insertOrIgnore`, returning rows the DB actually accepted |
| CLK-06 unblocked (§3) | `EvaluateCriticalPopulationJob` — liveness JOIN rail, membership anti-join, keyset chunking |
| The tier curve (§6.1) | `app/Services/ActivationTierService.php` — pure statics, DB-free |
| The threshold seam (§6.2) | `ActivationService::thresholdFor()`; both call sites rewired |
| Curve parameters | `2026_07_25_000003_activation_tier_curve.php` + `config/cga.php` `activation_tier` |
| Planet-scale provisioning | `app/Services/InstitutionProvisionService.php` + `institutions:provision` |
| Curve pins | `tests/Constitutional/ActivationTierTest.php` — 8 tests |

### Measured throughput

| Fixture | Wall clock | Rate |
|---|---:|---:|
| 2,000 jurisdictions | 9.3 s | — |
| 48,000 jurisdictions (288,000 rows) | **36.7 s** | **1,308 jurisdictions/sec** |

Extrapolating to 955,130 jurisdictions: **≈12 minutes** on the dev box, comfortably inside the
~2 h whole-planet baseline. Expect 2–4× slower on the game box, whose `jurisdictions` table
carries 6.2 GB of PostGIS geometry — call it **25–50 minutes**, still well inside baseline.

> **A performance bug worth recording, because the first cut looked fine and was not.** The
> initial implementation pulled each keyset page into PHP and rebound it as an
> `IN (?,?,?…)` list — 25,000 placeholders per chunk. That turned a 13 ms query into ~75 ms per
> jurisdiction and projected to **twenty hours** for the planet, ten times the baseline. Keeping
> the batch inside the statement as a CTE fixed it: **149.4 s → 9.3 s on the same fixture, 16×**.
> The lesson generalises — a set-based engine stops being set-based the moment ids round-trip
> through the application.

### 11.1 The un-indexed foreign keys (found by measurement, mostly still open)

PostgreSQL does not index foreign keys automatically. A schema-wide audit found **212
single-column FKs with no plain index**. Most sit on small tables and cost nothing today. Three
were measured causing real pathology at scale, and all three are now fixed:

| Column | Symptom measured | After index |
|---|---|---|
| `election_board_members.election_board_id` (only *partial* indexes existed) | 3-way delete over 50k boards: **>7 min, CPU-bound** | **9.25 s** |
| `election_boards` deletion blocked behind the above | **24 min** | **5.3 s** |
| `executives.parent_executive_id` (**self-referencing**) | 50k-row delete: **>7 min, zero rows removed** | **6.4 s** |

The self-referencing case is the dangerous class and deserves naming: every DELETE must prove no
sibling still points at the row, so with no index that is **one full table scan per deleted row**
— quadratic. Six self-referencing FKs were un-indexed; `2026_07_25_000005` indexes the four on
tables that reach roughly one row per jurisdiction or more (`executives`, `judiciaries`,
`elections`, `organizations` — the last because lane 14's Foundation → Coalition parent link uses
exactly that column). The two remaining (`cases.appeal_of_case_id`,
`department_rules.supersedes_rule_id`) are on tables that stay small.

**Deliberately not fixed: the other ~206.** Indexing every FK would cost write throughput and
disk for no measured benefit. The right rule is to index a FK when its table reaches planet scale
or when a measurement shows it hurting — not by policy. Anyone provisioning, reverting or merging
at scale should run the audit query below first rather than discovering it the way this lane did.

```sql
SELECT c.conrelid::regclass AS tbl, a.attname AS fk_col, c.conrelid = c.confrelid AS self_ref
  FROM pg_constraint c
  JOIN unnest(c.conkey) WITH ORDINALITY AS k(attnum, ord) ON true
  JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum
 WHERE c.contype = 'f' AND array_length(c.conkey, 1) = 1
   AND NOT EXISTS (SELECT 1 FROM pg_index i
                    WHERE i.indrelid = c.conrelid AND i.indpred IS NULL
                      AND i.indkey[0] = a.attnum)
 ORDER BY self_ref DESC, 1;
```

### 11.2 What the constitutional suite caught, and what it did not

The suite is the reason two of these are recorded rather than shipped silently.

**Mine, and wrong: `elections_open_general_uq` was over-broad.** It asserted one unfinished
*general* election per jurisdiction. `PeerUpgradeAgreementTest` legitimately creates a second one
while probing the Art. II §7 version guard, and the index rejected it. The hazard the index was
written for is narrower — `scheduleBootstrapElection()`'s read-then-write can mint two
**bootstrap** elections — so `2026_07_25_000006` rescopes it to `trigger = 'bootstrap'`. Ordinary
general elections across successive cycles are lawful and none of this engine's business.

> A uniqueness constraint asserts a constitutional invariant. Asserting one **wider** than the
> law requires does not make the system safer; it makes lawful states unreachable. That is a
> failure mode worth naming, because it looks like rigour.

**Not mine: the `clocks` table was empty.** Two `GovernorRemovalOrdinaryMajorityTest` cases failed
inserting a CLK-09 timer against an empty `clocks` table — the known gap where
`ClockRegistrySeeder` does not ride the schema dump. Running the seeder turned those green
(5 passed, 218 assertions). This corroborates lane 2's launch-checklist item from a second
direction: **a bare `php artisan migrate` leaves an instance whose clock-dependent paths fail at
runtime, not at boot.**

**A methodological error of mine worth recording:** the first suite run happened while I was
mutating the same dev database, which contaminated the results and produced a failure that did
not reproduce. Full-suite runs need the database to themselves — the fleet's "full-suite gates
serialized" rule exists for exactly this, and I ignored it.

### Deliberately not built

- **`jurisdiction_activations` rows.** §5.2.1 — writing them would forge the Art. II §1 consent
  crossing and re-break CLK-06. CLK-06 and `jurisdiction:activate` remain the only writers.
- **Committees, departments, boards of governors.** Art. II §9 reserves them to legislatures.
  They arrive by vote (F-LEG-009 / F-LEG-016 / F-EXE-001) once a chamber is seated, which is
  the correct behaviour and not a gap — a provisioning engine that minted them would be
  manufacturing acts of self-government.
- **Members, seats, appointments.** These come from the elections engine on certification.

### Still open

The reach/legitimacy layer (§6.3), the Reach page (§6.4), and the amendability ruling (§9 item 4)
are not built. The curve parameters ship as a working mechanism with their bounds enforced in
`ActivationTierService::clampParams`, but they are not yet registered as legislature-amendable
settings — that decision is still yours.
