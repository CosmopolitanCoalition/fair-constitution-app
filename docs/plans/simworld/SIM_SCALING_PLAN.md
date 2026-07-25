# Simulated World Scaling Plan — the Attained instance (Phase O)

How a full-scale copy of Earth gets populated with synthetic residents whose counts
match WorldPop per jurisdiction and whose personas match their locality, and how
those residents are run through residency → elections → seated institutions at
planet scale, on a physically separate demo instance.

Companion to `docs/plans/scaling/INSTITUTIONS_SCALING_PLAN.md` (lane 3, **real**
populations). This document covers **simulated** ones. It rides the autoscale
pull-engine pattern rather than inventing a second orchestration idiom, and follows
the house shape set by `docs/plans/etl/GEODATA_PULL_ENGINE_PLAN.md`.

**STATUS: PLAN ONLY — nothing here is built.** Schema needs are FLAGGED (§3), never
migrated. Build order in §12.

Every planet figure below was measured against the game box (`fc_postgres`,
`fair_constitution`) on 2026-07-25 and the query is printed beside it. Every code
claim carries a `file:line`. Estimates are labelled as estimates.

---

## 0. Doctrine — settled, not to be relitigated

**Two physically separate instances.** `earth.*` is **the Standard**: real
multiplayer, dormant institution scaffolding, and **zero synthetic data, ever**.
`earth-demo.*` is **the Attained**: the same standard broadly materialized,
`instance_class='scale_demo'`, federation forced off, ephemeral and single-player to
every visitor. Physical separation is the constitutional answer, not a convenience —
a demo has received no consent, so it is an **illustration, never a government**
(Preamble; charter §Phase O).

Also settled, and load-bearing here:

- **`DemoPopulateService` drives the engine's statics. Never a copy of the math.**
- **Deterministic seed** = `hash(jurisdiction_id) + version`.
- **Zero new F-forms, zero new clocks, zero new audit modules.**
- **`game_mode` and `instance_class` are different axes** and never merge (§1).
- **Population noise is accepted**, never harmonized: parent-pop ≠ Σchildren-pop by
  construction.
- **The seating law** (2026-07-13): cube root → children-sum shares → a child at
  ≥ ceiling+0.5 rounds nearest and locks → remainder redistributes, recursing →
  drawn districts round to **nearest, independently**; drift is the drawing's defect.
  No Webster, no Sainte-Laguë, no largest-remainder **for seats**. (STV/Droop is the
  unrelated *election* method.)

---

## 1. The two axes, kept apart

|  | `game_mode` | `instance_class` |
|---|---|---|
| values | `production` \| `sandbox` | `production` \| `scale_demo` |
| exists today | **yes, end-to-end** | **no — docs only** |
| stored | `instance_settings.game_mode varchar(16)` + DB CHECK | *to be added (§3)* |
| set by | Setup Step 1; **locked** once setup completes (409) | founding; immutable |
| gates | `/dev/*` triple lock, DevBar, `requiresSandbox` nav | federation off, populate allowed, ephemeral sandbox |
| the question it answers | "may operators use dev tools in this world?" | "is this world an illustration rather than a government?" |

They are orthogonal. A `sandbox` world is a dev playground with real consent
semantics; a `scale_demo` world could have dev tools off. Anyone who merges them
has broken both.

**CI-2 is already coded and constitutionally pinned.**
`MatrixFederationGateService::desiredFederationWhitelist(bool $scaleDemo = false)`
returns `[]` when `$scaleDemo` is true, and
`tests/Constitutional/MatrixFederationWhitelistTest:51-52` asserts it. So this plan
does **not** design CI-2 — it designs the persistence half that gives `$scaleDemo`
a source, because today it is a default-false parameter with exactly one production
caller that never passes `true`.

> ### ⚑ DECISION 1 — CI-2 is INERT, and naive wiring would make it dangerous
> *(Corrected after adversarial verification — an earlier draft of this plan called
> CI-2 "self-contradictory". That was wrong, and the truth is worse.)*
>
> The `if ($scaleDemo) return []` branch (`MatrixFederationGateService.php:38-40`) is
> **dead code**. Its sole production caller, `setRoomServerACL()` at `:67`, passes no
> argument, so `$scaleDemo` is always the default `false`. The branch is reachable
> only from `MatrixFederationWhitelistTest:52`. **CI-2 is pinned but inert: the test
> passes, and the production path never exercises it.** On a `scale_demo` instance
> today, `setRoomServerACL()` would compute and write the **real trusted-peer
> whitelist**.
>
> There is a live anti-self-brick guard at `:71` that throws when the allow-list is
> empty or lacks the local server. So the naive fix — making
> `desiredFederationWhitelist()` read `instance_class` globally — would convert an
> inert rail into a **real self-brick**. Don't.
>
> **Recommended shape:** supply the `scaleDemo` argument at the config-render /
> runbook call site (which is where the whitelist is actually consumed — the class
> docblock says it is computed "for the runbook", applied to Synapse config at
> deploy/rig time), and make `setRoomServerACL()` **refuse early** on a `scale_demo`
> instance with its own explicit error, *before* it ever computes an allow-list.
> Operator's ruling, because it touches a pinned constitutional test.

---

## 2. What the planet actually is

```sql
SELECT adm_level, count(*), sum(population) FROM jurisdictions
WHERE deleted_at IS NULL GROUP BY adm_level ORDER BY adm_level;
```

| adm_level | jurisdictions | leaves | Σ population | pop-zero |
|---|---|---|---|---|
| 0 Earth | 1 | — | 7,991,888,892 | 0 |
| 1 | 232 | 24 | 7,991,888,892 | 3 |
| 2 | 3,243 | 362 | 8,019,553,917 | 23 |
| 3 | 49,540 | 38,001 | 8,021,370,665 | 185 |
| 4 | 106,096 | 83,662 | 5,172,224,854 | 1,107 |
| 5 | 95,042 | 83,870 | 2,077,396,906 | 334 |
| 6 | 700,976 | 700,976 | 1,531,350,271 | 33,111 |
| **total** | **955,130** | **906,895** | Σ leaf = **8,347,150,193** | 34,763 |

**Levels do not partition the planet.** Each level is independently rastered, so
every level's sum is ~8B. Generating `population[j]` people at *every* level would
mint ~33 billion people — the first and most expensive mistake available here.
**People exist at leaf grain**; ancestors acquire them through the residency
ancestor-sweep (`ResidencyService::confirmVerification` writes one
`residency_confirmations` row per ancestor, discriminated by `depth` — there is no
`user_jurisdiction_associations` table).

Σ leaf population is **8,347,150,193, or +4.4% above the Earth row's 7,991,888,892**.
That gap is doctrine, not drift: accepted raster noise plus ISO-tree dual coverage
(PRI-in-USA, TWN-in-CHN, the FRA DOMs, LIE-in-CHE). **The Earth row is the
authoritative headline figure**; the leaf sum is what the generator actually walks;
the demo renders the delta honestly rather than silently reconciling it.

> ⚑ **`@lane-07`** — the 12:38 ruling records "~955,130 legislatures across ~951,626
> jurisdictions". Measured live, **both are 955,130** (zero null `adm_level`). The
> 951,626 figure appears to predate rows minted since the audit. Content lanes are
> about to publish it.

### Institutions on that planet

```sql
SELECT count(*), sum(type_a_seats), sum(type_b_seats) FROM legislatures WHERE deleted_at IS NULL;
```

| | measured |
|---|---|
| legislatures | 955,130 |
| Σ `type_a_seats` | 13,131,216 |
| Σ `type_b_seats` | 2,162,871 |
| **Σ seats** | **15,294,087** |
| active district maps | 954,682 |
| districts in active maps | 1,894,746 |
| Σ district seats | 13,109,298 |
| bicameral legislatures (`type_b > 0`) | 48,189 |
| legislatures with `type_a > 9` | 514,036 |

The 21,918 gap between Σ `type_a_seats` and Σ district seats is the settled honest
drift — the drawing's defect, visible, fixed by redrawing, never total-forced.

> ⚑ **Query trap — `legislature_districts` carries soft-deleted rows inside active
> maps.** 1,980,908 rows sit under `status='active'` maps, of which **86,066 are
> soft-deleted**; only **1,894,842 are live**. A query that forgets
> `d.deleted_at IS NULL` overcounts districts by 4.5% and seats by ~4%. This is not
> hypothetical — it produced a wrong district count during this plan's own design
> review. Every figure in this document filters it; anyone re-deriving them must too.
> (Lane 1's healing loop moves these numbers continuously: the same query returned
> 1,894,746 districts at 12:00 and 1,894,842 at 13:30. Treat all district figures as
> timestamped, not fixed.)

**Baseline: the world today is pure scaffolding.** `jurisdiction_activations`,
`executives`, and `judiciaries` are all **0 rows**; `users` is **1**;
`residency_confirmations` is **0**. Nothing this plan describes has to displace
existing data — the populate engine writes onto an empty institutional plane.

**`official_languages` is populated for 951,625 of 955,130 jurisdictions (99.6%).**
Persona languages come free from the DB; `scripts/etl/languages.py` covers only the
3,505 gaps.

Earth itself: `type_a_seats` **1,999** across **282 districts summing to exactly
1,999** — zero drift at the root, corroborating both the Draft-9 result and the
fleet's vetted public copy line.

---

## 3. ⚑ THE BLOCKER — 23.8% of the planet's seats cannot be elected today

This is the largest single constraint on what the Attained can truthfully claim, and
it must be resolved before the demo means anything above leaf level.

`VoteCountingService::run()` hard-throws for any race outside 1–9 seats
(`app/Services/VoteCountingService.php:203-208`, a hardcoded `9`, **not**
`ConstitutionalDefaults::ceiling()`). A bicameral chamber's Type B half elects
at-large as **one race**. Measured:

| | legislatures | Type B seats |
|---|---|---|
| Type B in the legal 1–9 range | 17,927 | 93,051 **seatable** |
| Type B above 9 (max **1,724**) | 30,262 | 2,069,820 **blocked** |

Type B districting is the deferred half of the 2026-07-19 ladder work — `seatPlan()`
still returns `type_b_needs_districting`, a flag written by two commands and read by
nothing.

**And the damage is not confined to the Type B half.**
`ElectionLifecycleService::racePlan()` carries a **single run-level `$blocked` flag**:
when `type_b > $ceiling` it sets `$blocked = true` (`:617`), condemning the entire
plan — including the perfectly lawful `type_a` district races already computed into
`$kinds['type_a']`. The docblock is explicit: a blocked plan "is a SCHEDULING-TIME
ENGINE REJECTION … the violation rolls the whole filing back."

```sql
SELECT count(d.id), sum(d.seats) FROM legislature_districts d
JOIN legislature_district_maps m ON m.id=d.map_id AND m.status='active' AND m.deleted_at IS NULL
JOIN legislatures l ON l.id=d.legislature_id AND l.deleted_at IS NULL
WHERE d.deleted_at IS NULL AND l.type_b_seats > 9;
--  218,320 districts / 1,568,448 type_a seats
```

| | seats |
|---|---|
| Type B blocked | 2,069,820 |
| Type A blocked as collateral | 1,568,448 |
| **total blocked** | **3,638,268 of 15,294,087 = 23.8%** |
| **seatable** | **≈ 11.66M (76.2%)** |

**Earth is in the blocked set.** It carries `type_b_seats = 1,141`. So does every one
of the 232 countries and the 3,243 provinces. Without a ruling, **the Attained is
leaf-only — 906,895 village councils under permanently-"forming" countries and a
world legislature that cannot be seated.**

> ### ⚑ DECISION 2 — make `racePlan()` per-kind, `@operator`
> **Recommended:** emit the lawful `type_a` district races and record the `type_b`
> blocked posture **on the election**, instead of failing the whole plan. It is a
> small change; it is honest (the Type B half still renders as *awaiting Type B
> districting*, per the charter's PI-2 clamp-honesty rail); and
> `ElectionLifecycleService` is **not** in
> `ConstitutionalVersionService::HARDENED_SURFACE`, so it does not move
> `constitutional_version` and cannot trip
> `ElectionResultsCertification`'s mid-election version check.
>
> The alternatives are worse. Building Type B districting is large and is
> @lane-01/@lane-03 surface, not this lane's. Inventing demo-only grouping math is
> forbidden outright by the doctrine ("never a copy of the math").
>
> This ruling gates everything above the leaf tier. Until it lands, §7's throughput
> numbers describe a leaf-only world.

**There are ZERO at-large Type A races on this planet.** All 448 legislatures without
an active district map have `type_a_seats > 9`, so `racePlan()` blocks them under
Art. II §8 rather than falling back to at-large. The `(D > 0 ? D : 1)` at-large branch
is unreachable at current data — worth knowing before anyone budgets for it.

**Related, same class:** 444 over-ceiling Type A chambers have no active map and are
election-blocked on their own account. That independently corroborates @lane-01's
finding (board, 10:58) that **391 of 452 review maps are childless root leaves
holding ~84M people whose municipalities were never ingested** — the same defect
reached from the other direction. No districting algorithm fixes it; ingestion does.

---

## 4. The architecture

### 4.1 The load-bearing identity: weighted ballots are exact, not approximate

`legislature_members.user_id` is `uuid NOT NULL`, and
`CertificationService::seatWinner()` is its only writer, reachable only through
`certifiedTabulation()`, which throws unless a `tabulations` row is `complete` with a
non-null `record_hash`. **A real count is constitutionally mandatory. There is no
shortcut that seats anyone.**

So any design that reaches ~11.66M seated members without ~8.35 billion ballot rows
has already made the weighted-ballot bet. This plan makes it with a proof.

`BallotSet::fromRankings()` calls `add($ranking, 1)` per ballot;
`BallotSet::fromGrouped()` calls `add($ranking, $count)` once
(`app/Domain/Counting/BallotSet.php:32-79`). Both feed the same private `add()`,
both `ksort(SORT_STRING)`, and `groups()` + `count()` are the counting core's entire
read surface. The class docblock states the property directly: memory is
"proportional to the number of DISTINCT rankings, not ballots", and insertion order
"can never matter".

The core then **re-canonicalizes anyway** — `VoteCountingService::run()` folds its
input into `$canon[$prefs] += $group['count']`, and everything downstream (round-0
tallies, the per-group parallel arrays, the Gregory pile iteration, the Droop quota
`intdiv($totalValid, $seats + 1) + 1`) reads only `$canon`.

**Therefore, for any grouped set G and its individual expansion E, `fromGrouped(G)`
and `fromRankings(E)` produce the same `$canon` — hence the same rounds, the same
`elected`, and the same `CountResult::recordHash()`** (sha256 over canonical JSON of
exactly `{engine_version, seats, quota, total_valid, rounds}`).

Two consequences worth stating plainly:

- **`total_valid` is the true planet-scale number.** The published Droop quota and
  `tabulations.total_valid` are real integers computed by the PROTECTED engine over
  the real electorate — not display fictions.
- **The demo is the first thing to exercise the bcmath path at volume.** `Micro`'s
  docblock anticipates exactly this: weight × surplus exceeds 2⁶³ at Earth-district
  scale, and `mulDiv()` routes that product through bcmath.

**Measured — the count is not the bottleneck, and it is not close.** Run against the
real pure service with zero DB (`docker exec fc_app php -r`, using
`tests/Support/SyntheticBallotGenerator::grouped()` verbatim):

| candidates | seats | ballots | distinct rankings | count time | peak RSS |
|---|---|---|---|---|---|
| 24 | 9 | 500,000 | 2,117 | **0.894 s** | 14 MB |
| 12 | 7 | 20,000 | 1,607 | 0.013 s | 16 MB |
| 10 | 9 | 12,000 | 954 | **0.014 s** | 16 MB |
| 9 | 8 | 2,500 | 514 | 0.011 s | 16 MB |
| 8 | 7 | 900 | 293 | 0.006 s | 16 MB |

500,000 ballots collapse to 2,117 distinct rankings — the `BallotSet` property,
confirmed empirically rather than argued. The design spec's budget in
`docs/plans/institutions/PHASE_B_DESIGN_counting_engine.md` §C.8 (500k / 24 / 9 in
under 60 s, under 256 MB) is **beaten 67×, at 14 MB**. That spec has never had a test;
**this table is that missing performance pin**, and it can be written today.

Ballot crypto is likewise free: `newSaltHex` + `commitmentHash` + `encryptCanonical`
runs at **8.7 µs/ballot = ~115,500 ballots/sec/core**, 264-byte ciphertext.

**So the constitutional count is cheap and the bookkeeping is the wall.** Every
throughput decision in this plan follows from that inversion.

**One guard rail.** Round-0 tallying does `$tally[$first] += $mult * Micro::SCALE` —
a **raw native multiply**, not `mulDiv`. It is safe to `$mult ≈ 9.22e12`
(`PHP_INT_MAX / 1e6`). Earth's 8.35e9 has ~1,100× headroom, but an operator dial that
inflated the electorate (a "year 2300" demo) would silently overflow. **The expander
must hard-assert `electorate <= 9_000_000_000_000` before emitting.**

### 4.2 The seam is exactly one method

`TabulationRecorder::countInput(ElectionRace): CountInput`
(`app/Services/TabulationRecorder.php:107-125`) is the only place ballots become a
count. It assembles `candidacyIds` + `seats` +
`BallotSet::fromRankings($this->ballots->decryptForCount($race))` + a `tieSeedBase`
hashed from the sorted `ballot_hash` root. Everything downstream —
`complete()`, `tabulation_rounds`, `race_results`, `election_certifications`,
`CertificationService::seatWinner` — consumes a `CountResult` and is **indifferent to
how the input was assembled**.

So the demo needs one gated alternate `CountInput` provider and nothing else changes.
That is what "drives the engine statics, never a copy" means concretely.

**Where the expander may NOT live.** `app/Domain/Counting` is inside
`ConstitutionalVersionService::HARDENED_SURFACE`. Adding *any* `.php` file there
changes `derive()`, and `ElectionResultsCertification` **refuses to certify** an
election whose pinned version moved. The expander therefore lives at
**`App\Services\Demo\CohortBallotExpander`**, outside the hardened surface, and is
pure — no DB, no clock, no process-global RNG state:

```
expand(seed, candidacyIds, electorate, archetypes, groups)
    : list<array{0: list<string>, 1: int}>   // exactly BallotSet::fromGrouped's shape
```

- **PRNG is a hash chain** (`h₀ = sha256(seed)`, `hᵢ₊₁ = sha256(hᵢ)`), **not**
  `mt_srand`. `tests/Support/SyntheticBallotGenerator` uses `mt_srand`, which is
  process-global — fine in a test, unsafe in a worker looping thousands of races.
  The sampling algorithm is otherwise that class's: popularity weights → cluster
  orderings by weighted shuffle → per-cluster ranking sampling.
- **Exact integer partition.** Multiplicities are apportioned across the distinct
  rankings by largest-remainder over integer weights, so `Σ multiplicities ==
  electorate` exactly, with no float anywhere. **This apportions ballots to rankings,
  not seats to jurisdictions** — no conflict with the settled seating law, but the
  docblock must say so or someone will correctly file it as a violation.
- `electorate` comes from `legislature_districts.actual_population` (already written
  by `SinglesBatchProcessor`) × a turnout dial.

**`tieSeedBase` mirrors the real shape** so it stays publicly reproducible:

```
cohortRoot  = PublishBallotHashesJob::rootHash([ sha256(rankingKey.':'.multiplicity), … ])
tieSeedBase = sha256(cohortRoot . ':' . race_id)
```

Same function, same concatenation. Anyone holding the published seed re-runs the pure
expander, recomputes the root, recomputes the count, and gets the same `record_hash`.
The cohort is never stored expanded — only its seed.

### 4.3 The throughput ceiling: the audit chain

`AuditService::append()` takes a **global** `pg_advisory_xact_lock(0x4155444954)`
and re-reads the chain head for **every** entry
(`app/Services/AuditService.php:63-101`). It is a strictly serial writer,
instance-wide; no amount of Horizon parallelism helps.

**Measured from the live chain** (`audit_log` is 1,965,182 rows / 1,599 MB ≈ 853 B/row):

```sql
SELECT date_trunc('minute', occurred_at) AS minute, count(*) FROM audit_log
GROUP BY 1 ORDER BY count(*) DESC LIMIT 5;
--  2026-07-23 09:32  1718      2026-07-23 09:31  1657
--  2026-07-23 09:33  1717      2026-07-23 09:30  1610
--                              2026-07-23 09:29  1603
```

Five consecutive minutes above 1,600 under a live 10-worker autoscale load:
**~28.6 appends/second sustained.** State this honestly — that workload was already
batching (one summary entry per unit), so it was never trying to saturate the lock.
**28.6/s is the highest rate this system has ever been observed to sustain, i.e. a
lower bound on the ceiling, not the ceiling itself.** Nothing in the repo
demonstrates more, so it is the number to plan against.

At that rate, one append per race across ~1.69M electable races is **~16.4 hours of
unparallelizable lock time**, before certification even starts. Per-person engine
filing is worse by orders of magnitude: 8.35B filings ÷ 28.6/s ≈ **9.3 years**, and
`ResidencyService::simulatePings` files one F-IND-005 per day per resident and then
*deletes them all* at verification — ~240 billion serialized appends to produce
nothing.

**The settled precedent is autoscale's**: bulk set-based writes plus **one summary
audit entry per batch**, already constitutionally pinned in `AutoscalePinTest`
("`autoscale.singles_generated` once per batch, `autoscale.completed` once").

**A hazard that must be encoded.** The lock is `pg_advisory_xact_lock` —
*transaction-scoped* — and `append()` runs inside the caller's transaction when one
is open. A worker that opens a long chunk transaction and appends its summary entry
*early* holds the **global** audit lock for the whole chunk, stalling every other
worker instance-wide. **Rule: append the batch summary last, after the bulk write
commits, in its own short transaction.** (This applies to every lane doing bulk
writes, not just this one — flagged to @lane-01, @lane-03, @lane-13 on the board.)

Related: `verifyChain()` walks and recomputes the entire chain, so it is unrunnable
at demo scale. Batching is what keeps `audit:verify` meaningful.

> ### ⚑ DECISION 3 — audit posture for synthetic governance acts, `@operator`
> Batching is settled precedent for **infrastructure** writes (autoscale draws maps).
> Seating a legislature is arguably a different category. The recommendation is to
> batch, with the summary entry carrying a **Merkle root over the batch's
> `record_hash` values** so the batch remains individually verifiable:
>
> ```
> event:   'populate.races_tabulated'
> payload: { run_id, batch_token, races, seats, engine_version,
>            record_hash_root, total_valid_sum, generator: 'CohortBallotExpander v1' }
> ```
>
> That is strictly more verifiable than a per-race entry that carries no root.

### 4.4 The one additive persistence method

`TabulationRecorder::complete()` is correct in every respect except that it appends
one audit entry per race. `TabulationRecorder` is **not** on the hardened surface, so
the change is additive and safe:

1. extract `roundRows(...)` and `resultRows(...)` from `complete()`'s existing body;
2. `complete()` keeps calling them — behaviour and row bytes unchanged, every
   existing test stays green;
3. add **`completeBatch(iterable<array{Tabulation, ElectionRace, CountResult}>)`** —
   one transaction per bounded chunk, the same row builders, `array_chunk(…, 500)`
   inserts, the same `tabulations` seal, then **one** audit entry.

That is precisely `SinglesBatchProcessor`'s shape. No PROTECTED file is opened.

### 4.5 Reused verbatim — the honest inventory

| Seam | How it is used |
|---|---|
| `VoteCountingService::countStv()` | untouched; the PROTECTED file is never opened |
| `BallotSet::fromGrouped()` | its documented generator entry point |
| `TabulationRecorder::begin()` / `voteShareNorms()` | unchanged |
| `CertificationService::lockstepWindow()` | pure static (CLK-10 math) |
| `ActivationService::cubeRootSeats/seatPlan/quorumRequired` | pure statics — the seat math |
| `TypeBSeatLadder::apportion()` | pure |
| `ConstitutionalDefaults::sizeFromPopulation/ceiling` | pure — **`::flush()` per chunk is mandatory**, its static cache is unbounded and keyed per jurisdiction |
| `InstitutionStubService::generate()` | per bounded chunk — **already chunked bulk inserts with no per-row audit append** (`:99-102`), so it needs no adaptation |
| `App\Support\CivicPopulation` | the honesty rail; already the denominator for every population-pegged threshold (§5) |
| `PublishBallotHashesJob::rootHash()` | batch Merkle root + `tieSeedBase` |
| `AuditService::append()` | once per batch |
| `jurisdictions.official_languages` (120 distinct sets / 232 countries) | persona languages |
| `scripts/etl/languages.py` (ISO3 → ISO 639-1) | research-language selection; unclaimed by lane 5 |
| `Approval::SCOPE_OWNER` | the structural template for the sandbox scope |
| `ExpireStandingAttestationsJob` | the TTL-eviction rail |

**Do not use** `ActivationService::activate()`'s parent path at scale — it shells out
to `Artisan::call('apportionment:seed')` **per jurisdiction** (`:415`), a full Laravel
boot each time. Drive `seatPlan()` + a direct bulk insert, mirroring
`instantiateLeaf()`'s `DB::table('legislatures')->insert()` (`:454-465`).

---

## 5. Fidelity tiers and the honesty rail

The charter promises a fully-materialized ~8-billion-person world. Taken literally
that is 8.35B `users` rows plus ~42.3B `residency_confirmations` (population-weighted
mean ancestor depth ≈ 5.07–6.60 rows per leaf resident) plus 8.35B ballots — **>12 TB
before indexes**, and 8.35B serialized audit appends. It is not a budget problem; it
is impossible on any single box.

The resolution is that **individual identity is materialized only where it is
constitutionally required or actually rendered**, and everything else is a
distribution — which, per §4.1, is *lossless for the math that matters*.

| Tier | What it is | Individually materialized | Scope |
|---|---|---|---|
| **0 Scaffold** | jurisdiction + legislature + district map + institution stubs | none | all 955,130 — already built; this *is* `earth.*` |
| **1 Seated** | cohort distribution → weighted exact count → real tabulation → certification → seated members | ~17.2M (candidates/members only) | planet-wide default |
| **2 Populated** | Tier 1 + a bounded resident sample so the civic-record plane has life | +25–100 per jurisdiction | showcase set from @lane-01's clean list |
| **3 Full fidelity** | every resident real: F-IND-001/003/006 filings, real ballots through `BallotBox` | everyone | a handful of hero jurisdictions |

Minimum individually-materialized users = `Σ(seats_r + 1)` = Σ seats + Σ races
≈ **17.2M** — one `users` row per candidacy, because winners must be candidates and
`legislature_members.user_id` is `NOT NULL`. **The gap between 17.2M and 8.35B is the
entire design.**

**Tier 3's real job is validation, not spectacle.** Take one small jurisdiction,
materialize it fully, cast real ballots, count it through the ordinary path — then
re-count the identical electorate as a cohort-weighted `BallotSet` and assert the two
`CountResult::recordHash()` values are byte-identical. That converts "demo math ==
engine math" from a claim into an executable pin (§11).

### The honesty rail — and the good news that it already exists

**`App\Support\CivicPopulation` already draws exactly the line this design needs.**
Its docblock is unambiguous:

> civic population = count of ACTIVE `residency_confirmations` rows for the
> jurisdiction — **never WorldPop `jurisdictions.population`**.

It is already *the* denominator for every population-pegged threshold in the app —
petition CLK-17, referendum majority and supermajority, Phase F union formation and
border votes — and it is pinned by `ReferendumShieldTest`. Real population is
explicitly "provenance data".

That is a large gift. It means **the tiering is already self-describing in the app's
own math**: the demo's synthetic residents *are* the civic population, WorldPop stays
provenance, and every threshold pegs itself to what was actually materialized without
a single new mechanism. The demo does not have to invent honesty; it has to avoid
breaking the honesty already there.

> ⚑ **The consequence, stated so nobody is surprised by it.** At Tier 1 the civic
> population of a jurisdiction is roughly its candidate count, so petitions and
> referendums in the demo clear on a handful of signatures. That is arithmetically
> *correct* and looks broken. Either a tier deliberately materializes enough
> `residency_confirmations` to make thresholds meaningful (this is what the Tier-2
> resident sample is really for), or the demo shows the tiny numbers honestly. It
> must not fake the denominator — that would corrupt the one seam the app already
> gets right.

Nothing synthetic may be dressed up as attained consent. Concretely:

- Every Tier-1 tabulation records its provenance and the demo surfaces it: *"counted
  from this jurisdiction's cohort distribution — N ballots represented as M distinct
  preference orderings, seed `…`"*. The count is real; the electorate is
  statistical; both facts are visible.
- Blocked chambers render as **awaiting Type B districting** (§3), never as seated.
- The 34,763 pop-zero jurisdictions and @lane-01's 391 childless-root-leaf
  territories get an explicit visible case, never a silent skip.
- The +4.4% leaf-sum delta (§2) is shown, not reconciled away.

> ⚑ **Schema need — provenance has nowhere to live.** `tabulations.kind` is
> CHECK-constrained to `initial|audit_rerun|countback`, so a cohort-weighted count
> cannot label itself without a flagged change. (Checked while there:
> `tabulations.total_valid` is `integer` and the largest district on the planet holds
> 37,917,625 people — ~57× headroom, no per-race overflow risk.)

---

## 6. The persona model and the locality-research layer

### 6.1 Personas are a deterministic function of the seed and the profile

Every persona attribute is derived from `hash(jurisdiction_id) + version` against a
**jurisdiction profile** (§6.2) plus signals already present on the row: `population`,
`adm_level`, `official_languages`, `centroid`, `timezone`, and area-derived
urbanicity. Nothing is stored that can be recomputed.

The profile payload carries: weighted given-name lists (by gender bucket) and family
names; naming convention (patronymic / given-family / family-given / mononym);
spoken-language shares; occupation shares; settlement type; age structure; and
**civic-desire priors** — turnout, candidacy rate, org affinity — which are what
drive the cohort archetypes fed to `CohortBallotExpander`.

### 6.2 Research is hierarchical, because per-jurisdiction is unaffordable

Per-jurisdiction web research at 955,130 jurisdictions fails on both axes. Order of
magnitude, with assumptions stated: ~20k input + ~3k output tokens per call at
Haiku-class pricing is roughly **$0.03–0.04 per jurisdiction**, so ~**$30,000+**, and
at any sane concurrency the wall-clock runs to **weeks**. Neither is acceptable for a
demo asset.

**The design instead researches ancestors and inherits downward:**

| cutover | researched jurisdictions | ≈ cost | ≈ wall-clock |
|---|---|---|---|
| ADM1 only | 232 | ~$8 | minutes |
| **ADM1 + ADM2 (recommended)** | **3,475** | **~$120** | **3.6–14 h** |
| + largest ADM3s | +5,000 | ~$300 | ~1 day |
| every jurisdiction | 955,130 | ~$30,000+ | weeks |

Every descendant inherits its **nearest researched ancestor** and is deterministically
modulated from its own seed and row signals. **No LLM call below the cutover.**

Research runs **in the local language**, selected from `official_languages`
(populated: 120 distinct sets across 232 countries) with `scripts/etl/languages.py`
as the ISO3 → ISO 639-1 fallback.

**Research prompt contract** — inputs: name, parent chain, ISO, adm level, population,
official languages, centroid. Task: search in the local language for demographics,
common given and family names, principal occupations and industries, languages
actually spoken, urbanization, and locally-discussed civic issues. Output: strict JSON
matching the payload schema, plus sources. **A null field with lowered confidence is
always preferred to an invented one** — this is the single most important instruction
in the template.

**QA** is double-blind resampling: a per-level, per-language sample is researched a
second time by an independent agent on the same prompt, and inter-pass agreement is
the metric. Deterministic gates run on every profile regardless: valid ISO 639-1
codes, shares summing to ~1, non-empty name lists, at least one source present.

---

## 7. The run model — riding the pull engine

Three tables mirroring autoscale's proven shapes (§8), phases as a DAG with
single-writer barriers:

```
enumerating → profiling → cohorts → identities → elections → counting → seating → verifying → done
```

| phase | kind | item = | count | bound by |
|---|---|---|---|---|
| enumerating | `manifest` | resolve the target set (@lane-01's clean list), insert items | 1 | PG |
| profiling | `profile_research` | one ancestor's local-language research | ~3,475 | LLM / network |
| profiling | `profile_inherit` | 25k descendants inherit + modulate | ~38 | PG |
| cohorts | `cohort_scope` | one jurisdiction's cohort distribution | 906,895 | CPU + PG |
| identities | `identity_batch` | 25k users + residency confirmations | ~690 | PG |
| elections | `election_scope` | one legislature: election + races + candidacies | 955,130 | PG |
| counting | `count_race` | one race: cohort → `CountInput` → real count → tabulation rows | ~1,694,888 electable | CPU |
| seating | `seat_scope` | certification + `seatWinner` + terms | ~924,868 | PG + audit lock |
| verifying | `acceptance_scan` | detectors + the Tier-3 equivalence pin + summary audit | 1 | PG |

**The claim ladder is autoscale's verbatim** — the only `FOR UPDATE SKIP LOCKED`
idiom in the repo:

```sql
UPDATE sim_items SET status='running', claim_token=?,
       started_at=COALESCE(started_at, now()), updated_at=now()
 WHERE id IN (SELECT id FROM sim_items
               WHERE run_id=? AND kind=? AND status='pending'
               ORDER BY position LIMIT ? FOR UPDATE SKIP LOCKED)
   AND status=? RETURNING id
```

batched for the 25k kinds exactly as `AutoscaleClaims::claimSingles` batches.
**Phase advance lives in the pump, never in a worker.**

- **`sim:pump`** — `everyMinute()->withoutOverlapping(10)->runInBackground()->onOneServer()`,
  the run's only liveness root: stale-claim reclaim, breaker tick, phase advance,
  counter refresh, lease cull, worker top-up. Each duty idempotent and seconds-long.
- **Halt** — `sim_runs.halt_requested_at`, read per pump, per claim-loop iteration,
  and per claimed item *before* work.
- **Breaker** — the same `pg_postmaster_start_time() || stats_reset` fingerprint;
  mismatch sets `paused_until = now() + 10 min`. **Pause-only, never a governor.**
- **Revert** — `sim:revert {--keep-profiles} {--resume} {--force}` mirrors
  `autoscale:revert` including its guards (refuse unless halted/done; refuse if any
  lease was seen in the last 2 minutes). **It keeps `jurisdiction_profiles`** —
  LLM-derived research is this engine's equivalent of autoscale's "sizing survives
  the revert". The protocol is autoscale's, verbatim:
  **run → check → halt → fix → REVERT → resume, until clean.**
- **Limiter** — ONE dial, `HostCapacity::autoscaleWorkers()` = `max(2, min(12,
  cores-2))`, enforced as Horizon `maxProcesses` on `redis-long`. A pull worker
  claims one unit at a time, so process count *is* concurrency.

**Two deliberate divergences, each with a named precedent:**

1. **Ordering is largest-first** by `est_cost` — the geodata plan's inversion of
   autoscale's simplest-first. There is no triage benefit here, and the biggest
   populations must not define the tail.
2. **The LLM research lane gets a sub-cap inside the one limiter**, mirroring
   `AutoscaleClaims::heavyWorkerCap()` at 20% of the pool, so rate-limited network
   calls cannot starve the CPU pool.

**The deterministic seed is what makes revert cheap.** Because personas, cohorts and
rankings are a pure function of `hash(jurisdiction_id) + version`, `sim:revert` is a
bounded `DELETE` followed by re-derivation that is byte-identical modulo generated
UUIDs. No snapshot, no restore path. A bug in the persona model is fixed by bumping
`version` and re-running, not by surgically repairing 150 million rows. TTL eviction
of cold data stops being data loss and becomes cache eviction.

### PG posture (encode, don't rediscover)

- `SET max_parallel_workers_per_gather = 0` before any planet-wide join — their DSM
  segments exceed Docker's default 64 MB `/dev/shm`.
- `VACUUM ANALYZE` churned tables at phase boundaries, guarded by
  `DB::transactionLevel() === 0`, and **after** the key passes, not before.
- THE ETL RULE, `CHUNK = 25000`: every planet-scale write is a bounded, individually
  committed statement with a progress callback —

```php
do {
    $n = DB::affectingStatement("INSERT ... SELECT ... WHERE NOT EXISTS (...) LIMIT 25000");
    $total += $n;
    if ($progress !== null && $n > 0) { $progress($total); }
} while ($n > 0);
```

---

## 8. Schema needs — FLAGGED, not migrated

Nothing below is authored as a migration. When the plan settles, these land in **one
REAL-dated additive migration** (`≥ 2026-07-05`, never dated before an object it
references).

| # | Object | Shape | Why |
|---|---|---|---|
| 1 | `instance_settings.instance_class` | `varchar(16)` + CHECK `(production\|scale_demo)`, default `production` | gives `$scaleDemo` a source; the kill-switch for every guard below |
| 2 | `sim_runs` | id, status, phase, options jsonb, counters, `halt_requested_at`, `paused_until`, `pg_fingerprint`, phase timestamps jsonb, timestamps | mirrors `autoscale_runs` |
| 3 | `sim_items` | id, run_id, kind, jurisdiction_id, legislature_id, adm_level, status, position, `est_cost` bigint, `claim_token`, reason, metrics jsonb, started/finished, timestamps; indexes `(run_id,kind,status,position)` and `(run_id,status)` | mirrors `autoscale_items`; the `(run_id,kind,status,position)` index is also the live-bar feed |
| 4 | `sim_worker_leases` | id, run_id, started_at, last_seen_at, claim_type, claim_label, claim_started_at, lane | **byte-compatible with `autoscale_worker_leases`** so the Step-3 worker strip renders it unchanged |
| 5 | `jurisdiction_profiles` | jurisdiction_id, scope `researched\|inherited`, source_profile_id, research_lang, version, payload jsonb, confidence, sources jsonb, researched_at, qa_status, qa_notes; `unique(jurisdiction_id, version)` | the research store |
| 6 | `jurisdiction_cohorts` | jurisdiction_id, version, archetype key, weight, attributes jsonb | the population distribution; the alternative is to derive at read time from the seed and store nothing |
| 7 | tabulation provenance | widen `tabulations.kind`'s CHECK, or add `tabulations.electorate_source varchar(24)` | the honesty rail (§5) has nowhere to write today |
| 8 | `demo_sessions` | id, seed, created_at, last_seen_at, expires_at | the per-visitor sandbox |
| 9 | `demo_overlays` | session_id, table_name, row_id, op `insert\|update\|delete`, payload jsonb, created_at | copy-on-write deltas |
| 10 | synthetic marking | a boolean or provenance column on generated rows | makes `sim:revert` a bounded, provable DELETE rather than a `description LIKE` scan |

---

## 9. Live progress

**No progress table.** The established pattern is a fresh aggregate per poll —
`SetupController::autoscaleProgress()` states it outright: *"real numbers every 2 s,
never the pump's once-a-minute denormalized copies. Index-only on
`autoscale_items_layers_idx`."*

- **Endpoint** — one `COUNT(*) FILTER (…) GROUP BY kind` over `sim_items`, index-only
  on `(run_id, kind, status, position)`, plus a per-`adm_level` breakdown during the
  cohort and identity phases.
- **Bars** — one per phase/kind, `{key, label, total, done, running, review, status}`,
  the same `bars[]` contract the setup wizard and `SyncProgressService` already share.
- **Worker strip** — read straight from `sim_worker_leases`; because the table is
  byte-compatible with `autoscale_worker_leases`, `Step3_Districts.vue`'s existing
  component renders it with no changes.
- **Poll at 2 s**, tween numbers with `easeOutCubic` over ~1.7 s, keep polling while
  `halted` (only terminal states stop the timer — the conditional-arming bug that
  froze the districting page until a manual refresh is a solved problem; don't
  reintroduce it).
- **Rate + ETA** from a 30-minute window of `finished_at`.

No SSE, no websockets — the repo has none, and this is not the place to introduce one.

---

## 10. The per-visitor copy-on-write sandbox

`demo_sessions` + `demo_overlays`; reads resolve base ∪ overlay with the overlay
winning; TTL eviction rides `ExpireStandingAttestationsJob`'s pattern (hourly,
`onOneServer` + LeaderProbe — the repo's only scheduled row-pruning job).

**The write surface is bounded.** Intercepting ~164 models is not realistic; the
overlay covers only what a visitor can actually mutate in a demo session, and
everything else is read-only base. **Read-only demo remains the charter's stated MVP
fallback**, and this plan keeps it as the honest fallback rather than a failure mode.

**The scope's template is `Approval::SCOPE_OWNER`** — the repo's only Eloquent global
scope, which already **fails closed** to zero rows when there is no authenticated
context, exactly the right behaviour for console and queue.

> ⚑ **Design-panel caution worth carrying:** an Eloquent global scope is bypassed by
> `DB::table()`, which this codebase uses heavily. If the overlay must be
> unbypassable, Postgres **row-level security** is the enforcement that cannot be
> sidestepped by a raw query builder. That is a real trade-off — RLS is stronger and
> less familiar — and it belongs in the build round, not settled here.

**A subtlety the sandbox forces.** `audit_log` and `public_records` are append-only
and never deleted — every demo command's docblock says so. If a visitor's sandbox
actions appended to the real chain, "nothing persists" would be false, the chain would
grow without bound on visitor noise, and `verifyChain()` — already unrunnable at demo
scale — would be hopeless. **Sandbox-session writes, including their audit entries,
stay inside the overlay and never touch the base chain.** That is a design constraint,
not an implementation detail.

**`time_mode` is free and unclaimed.** `instance_settings.time_mode`
(`real|accelerated`) and `time_scale_seconds_per_year` are written by Setup Step 0,
read once for display, and consumed by nothing — the whole CLK-01..21 registry runs on
wall-clock. A demo world wants terms to elapse in minutes, so these are the natural
fit.

> ### ⚑ DECISION 4 — consume `time_mode`? `@operator`
> Free and dormant, but it touches the clock plane. Carried as an option, not assumed.

---

## 11. Contamination control — the iron constraint

**`earth.*` carries zero synthetic data, ever.** Today that rests entirely on operator
discipline, which is not a control: there is **no `GameMode` check, no environment
check, and no reserved-domain blocklist in any of the six demo commands**.
`elections:demo` will happily mint 40 permanent `@cga.test` users with password
`demo` on a production world.

Adversarial review confirmed the gap is **wider** than first stated: `app/Providers/`
contains four providers and a grep for `throw|abort|ConstitutionalViolation` across
all of them returns **zero matches** — there is no boot-time assertion of any kind
anywhere in the provider layer. And across all 54 artisan commands there is not one
`GameMode`, `app()->environment()`, `isSandbox()`, `APP_ENV`, or `confirmToProceed`
check. Every demo command runs, unprompted, against whatever database `.env` points at.

**Enforcement is FIVE layers, not two.** `instance_class` plus a boot assertion closes
only the local-artisan and restored-dump vectors:

| # | layer | closure |
|---|---|---|
| 1 | local execution | every generator and demo command refuses unless `instance_class = 'scale_demo'` — one shared check, not per-command discipline; plus a boot assertion refusing to serve a `scale_demo` instance with federation on |
| 2 | **signed peer handshake** | the class travels in the handshake, and `ColdSyncService` / `FederationSyncService` **refuse a `scale_demo` peer on ingest**. Critical: the demo's *local* column is invisible to a remote Standard instance, so layer 1 cannot protect it |
| 3 | **export bundle** | the class rides the `OperationalBundleService` manifest with importer refusal — Phase F makes the bundle *the seed*, so this is the highest-bandwidth contamination path |
| 4 | **authority flip** | a class check inside `AuthorityFlipService` so a demo instance can never be flipped to authority |
| 5 | **row-level synthetic provenance** | a marker on every generated row so contamination is **detectable and removable after the fact**. Today it is neither |
| — | shared Postgres / Redis | physical stack separation (distinct `COMPOSE_PROJECT_NAME`, distinct volumes) |
| — | Matrix homeserver whitelist | rendered at **deploy time**, in Synapse config — **not reachable by any PHP boot assertion**, so it needs its own deploy-side check (see §1) |

Layer 5 is the one most likely to be skipped and the one that matters most on a bad
day: **prevention-only enforcement fails closed exactly once and then never again.**
Once a synthetic row is on the Standard instance without a provenance marker, there is
no query that finds it.

Synthetic identities use the reserved **`*@demo.invalid`** namespace with
`Str::random(40)` passwords (unloggable), matching `SocialDemoCommand` and
`MatrixDemoCommand` — **not** the `@cga.test` / `Hash::make('demo')` pattern, which is
deliberately impersonatable and has no place in a public demo.

**A boot assertion refuses to serve a `scale_demo` instance with federation on** (CI-2
has enforcement but no boot check today; `FederationInitCommand:69-76` is the nearest
precedent for a loud, deploy-time persistence assertion).

---

## 12. Tests

Mirroring `AutoscalePinTest`'s mechanics coverage, live-pg, synthetic fixtures:

1. **`WeightedBallotIdentityTest` — the keystone.**
   `countStv(CountInput(ids, seats, BallotSet::fromGrouped(G), [], seed))->recordHash()`
   is **byte-identical** to
   `countStv(CountInput(ids, seats, BallotSet::fromRankings(expand(G)), [], seed))->recordHash()`,
   asserted over hundreds of random cohorts, plus one case at 8e9-scale multiplicity
   to cover the bcmath Gregory path and the raw-multiply tally headroom (§4.1).
   *Without this test the Attained is an expensive claim that its numbers mean
   something. With it, "demo math == engine math" is a fact the CI gate keeps true.*
2. **Tier-3 equivalence** — one small jurisdiction materialized fully, real ballots
   through `BallotBox`, counted the ordinary way, versus the same electorate counted
   as a cohort: identical `record_hash`.
3. **Halt / resume round-trip** — a halted run claims zero items; clearing the flag
   rewinds the phase and the run completes with the same counters.
4. **Breaker** — a bogus `pg_fingerprint` sets `paused_until`; a paused run hands out
   zero claims.
5. **Stale-claim reclaim** — a backdated claim returns to `pending` and the redo is
   clean.
6. **Revert round-trip** — generated output gone, `jurisdiction_profiles` survives,
   the audit chain survives, one `sim.reverted` entry; re-running reproduces
   byte-identical output (determinism).
7. **Contamination** — every generator refuses on a `production` instance; a Standard
   instance refuses a `scale_demo` bundle.
8. **`completeBatch` equivalence** — the batch path writes the same rows as
   `complete()` for the same input, differing only in audit-entry count.
9. **Overflow guard** — the expander refuses an electorate above the raw-multiply
   ceiling.
10. **The counting-engine performance pin that has never existed.** §4.1's table is
    the measurement; `PHASE_B_DESIGN_counting_engine.md` §C.8 budgeted 500k / 24 / 9
    in under 60 s and under 256 MB and no test was ever written. Actual: 0.894 s at
    14 MB. This is free to pin (`SyntheticBallotGenerator::grouped()` + a pure
    `countStv`, zero DB) and it protects the engine for every lane, not just this
    one. **Worth landing even if the rest of this plan is deferred.**

`DistrictingDoctrineTest` and the autoscale pins must stay green: nothing here touches
districting or any PROTECTED file.

---

## 13. Build order

Each step lands green before the next.

1. `instance_class` + the boot assertion + the generator refusal check + contamination
   pins. **Nothing else is safe to build first.**
2. `CohortBallotExpander` (pure) + `WeightedBallotIdentityTest`. The keystone proof
   before anything depends on it.
3. `TabulationRecorder::completeBatch()` refactor + equivalence pin.
4. Migration (§8) + models + `SimClaims` + `sim:pump` + the mechanics pins (3–6 above).
5. The generation stages, cheapest first: cohorts → identities → elections.
6. The counting stage + the seating stage.
7. Live bars (reusing the Step-3 components).
8. `sim:revert` + the requeue recipe.
9. `jurisdiction_profiles` + the research lane + QA sampling. **Last**, because it is
   the only stage that costs money and the only one whose output survives revert.
10. The CoW sandbox — or ship read-only and defer it, per the charter's own MVP
    fallback.

---

## 14. Non-goals and guardrails

- **No PROTECTED file is modified.** `VoteCountingService`, `app/Domain/Counting/`,
  `DistrictingService`, `ConstitutionalValidator`, `ConstitutionalSettings`,
  `Jurisdiction` are read and driven, never edited. **No file is added to
  `app/Domain/Counting/`** — that directory is inside `HARDENED_SURFACE` and adding to
  it moves `constitutional_version` and breaks mid-election certification.
- **Zero new F-forms, clocks, or audit modules** (charter).
- **No synthetic data on `earth.*`.** Ever.
- **No re-derivation of the seating law**, and no Webster/Sainte-Laguë/largest-
  remainder anywhere in seat allocation. (§4.2's largest-remainder apportions ballots
  to rankings, not seats to jurisdictions.)
- **No harmonization** of population noise; no second districting engine; no second
  MT router (@lane-05 owns that seam).
- **No second orchestration idiom** — this rides the autoscale pattern or it does not
  ship.

---

## 15. Open decisions — collected, not buried

| # | Decision | Owner | Blocks |
|---|---|---|---|
| 1 | The CI-2 self-brick contradiction (§1) | @operator | the boot assertion |
| 2 | **Make `racePlan()` per-kind** (§3) — without it the Attained is leaf-only and 23.8% of seats are unelectable | @operator | everything above leaf level |
| 3 | Audit posture for synthetic governance acts (§4.3) | @operator | the counting + seating stages |
| 4 | Consume `time_mode` for demo time compression (§10) | @operator | nothing; it is an enhancement |
| 5 | Research cutover level — ADM2 recommended (§6.2) | @operator | the research lane's budget |
| 6 | Eloquent global scope vs Postgres RLS for the overlay (§10) | build round | the sandbox |
| 7 | **Pre-N option** — which parts could ship as an English-only Attained | @operator | the Phase N gate |
| 8 | Type B districting: honest "awaiting" rendering, or a hard gate on the demo | @operator + @lane-01/@lane-03 | the demo's claim |

---

## 16. Cross-lane dependencies

- **@lane-03** — this lane consumes their tier curve and planet totals and expresses
  the sim-world side of eager-vs-lazy in *their* tier terms. Their institution
  formulas decide what gets seated. The sim-world answer to eager-vs-lazy is
  **~17.2M individually-materialized identities against 8.35B residents** — three
  orders of magnitude, and it is the whole design.
- **@lane-01** — their single clean-set entry is this lane's materialization
  allowlist and research QA sampling frame; this lane waits for it rather than
  deriving a second one. Their 391 childless-root-leaf territories (~84M people) and
  the 34,763 pop-zero jurisdictions each need an explicit visible case.
- **@lane-05** — demo research prose is demo-instance content, not app chrome, so it
  is outside their extraction/CI scope; any MT-router overlap is flagged to them
  rather than solved twice.
- **@lane-07** — the jurisdiction-count discrepancy (§2).
