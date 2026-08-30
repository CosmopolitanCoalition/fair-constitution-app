# CLAUDE.md — Fair Constitution App (Cosmopolitan Governance App)

## Project Overview

This is the **Cosmopolitan Governance App (CGA)** — a federated, open-source governance platform
implementing **A Fair Constitution (Cosmopolitan Template)** by the Cosmopolitan Coalition of United Earth.
It models every institution defined in the Template as interactive, automatable software: residency,
elections, legislatures, executives, judiciaries, organizations, and nested jurisdictions from
neighborhood to planetary scale.

Repository: https://github.com/CosmopolitanCoalition/fair-constitution-app

---

## SESSION START — READ THESE DOCS FIRST

At the start of every session, run the document extraction script and read the outputs:

```bash
# Install dependencies if not present
pip install python-docx openpyxl --break-system-packages -q

# Extract all reference documents to readable format
python3 docs/extract_docs.py

# Then read the extracted files before doing any work:
cat docs/extracted/fair_constitution.md        # THE authoritative policy document
cat docs/extracted/architecture_plan.md        # Technical architecture and 76-week phasing plan
cat docs/extracted/roles_forms_chart.md        # All constitutional roles, forms, and dependency chains
```

The `docs/extracted/` folder is .gitignored (generated at session start). Source files in `docs/` are committed.

**Priority of documents:**
1. `Fair_Constitution_Labeled.docx` — the FOUNDING reference, now ADVISORY (operator ruling 2026-08-08): the built game has advanced policy-wise past this document (polymorphic voting, more advanced districting, …) and those changes are not yet incorporated back into it. See THE CODE IS THE AUTHORITY below.
2. `CGA_Architecture_Plan.docx` — historical planning record (see THE CODE IS THE AUTHORITY below)
3. `CGA_Constitutional_Roles_Forms_Chart.xlsx` — exhaustive role/form/institution mapping
4. `The_Chart.drawio` — principles diagram and governance structure visualization
5. `App_Flows.drawio` — application flow maps (work in progress)

---

## THE CODE IS THE AUTHORITY (operator ruling 2026-08-08 — binds every session from this day forward)

The application is BUILT. The plans served as what to build; they are done serving.

- **The code is the authority on what is built and how it behaves.** Planning
  documents — `docs/plans/**`, phase plans, design MDs, formula docs,
  architecture notes — are historical records of what was INTENDED. They are
  not statements of what IS, and they are not a source of memory.
- **When the operator asks whether something "is a certain way," he is asking
  about THE CODE.** Answer by reading the running code (and the live box where
  state matters) — never by quoting a plan. A plan-sourced answer to a
  code question is a wrong answer even when it happens to match.
- **A deviation between code and plan is, at best, a FLAG** — something to
  consider adding or changing in the code or in the plan. It is generally not
  a measure of what is. What is, is the code.
- The goal is that the game RUNS as planned — and "runs" is verified in code,
  never assumed from documents.
- **This includes the constitution document itself** (operator correction,
  same ruling): the game has advanced policy-wise PAST
  `Fair_Constitution_Labeled.docx` — polymorphic voting, more advanced
  districting, and other evolutions live in code but are not yet incorporated
  back into the document — so even the docx is ADVISORY now. The
  authoritative statement of current policy is **the code plus the operator's
  settled rulings** (this file's hard constraints and rulings record those;
  where the code has advanced past a recorded constraint with the operator's
  sanction, the code + ruling win). Where code and docx diverge, that is a
  FLAG — usually a document-update debt, not a code defect. Changes to the
  constitution's TEXT remain the operator's alone.

---

## Technology Stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12, PHP 8.4 |
| Database | PostgreSQL 17 + PostGIS 3.5 |
| Frontend | Vue 3 + Inertia.js |
| Queue | Redis + Laravel Horizon |
| Dev Environment | Docker Compose |
| ETL | Python 3.12 (geospatial processing) |
| Mobile (future) | Capacitor (iOS + Android wrapper) |

### Docker Services

```
fc_app       PHP 8.4-FPM (Laravel)    internal:9000
fc_nginx     Nginx                     localhost:8080
fc_postgres  PostgreSQL 17+PostGIS     localhost:5432
fc_redis     Redis 7.4                 internal
fc_vite      Vite dev server           localhost:5173
fc_horizon   Laravel Horizon (queues)  internal
fc_etl       Python 3.12 ETL          internal
```

### Common Commands

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:fresh
docker compose exec app php artisan tinker
docker compose exec app composer require <package>
docker compose exec app npm run build
```

---

## Constitutional Hard Constraints (NEVER MODIFY)

These are immutable rules enforced at the application layer. No UI, admin panel, or legislative act can change them.

| Rule | Value | Source |
|---|---|---|
| Voting method | STV with Droop quota — never FPTP or plurality | Art. II §2 |
| District min seats | 5 | Art. II §2 |
| District max seats | 9 — a legislature above 9 MUST subdivide into districts. This is a DISTRICT rule; a legislature TOTAL has no ceiling (Earth = 1,999 across 282 districts). Does not bind an at-large Type B race — see Bicameral Support | Art. II §2 |
| Supermajority | 2/3 of ALL serving members (not just present) | Art. VII |
| Quorum | Majority of ALL serving members | Art. II §2 |
| Max days between meetings | 90 | Art. II §2 |
| Emergency powers max duration | 90 days | Art. II §7 |
| Voting/candidacy requirements | None beyond jurisdictional residency — absolute right | Art. I |
| Civil/judicial appointment term | 10 years | Art. II §9, Art. IV §1 |
| Judiciary min judges per race | 5 | Art. IV §1 |
| Default judiciary type | Appointed (not elected) | Art. IV §1 |
| Worker rep threshold (first seat) | 100 employees | Art. III §6 |
| Worker/shareholder parity threshold | 2000 employees | Art. III §6 |
| CGC intellectual property | Always public domain — never privatized | Art. III §5 |
| Ballot secrecy | Cryptographic separation of voter identity from ballot | Art. II |
| Supermajority formula | ceil(serving_members * 2/3) | Art. VII |

---

## Amendable Settings (Stored in `constitutional_settings` table)

These can be changed by valid legislative acts within constitutional bounds:

| Setting | Default | Notes |
|---|---|---|
| election_interval_months | 60 | 5-year default |
| voting_method | stv_droop | Can only be replaced with MORE proportional method |
| legislature_min_seats | 5 | Floor on a DISTRICT's seats. Cannot go below 5 · CLK-08 |
| legislature_max_seats | 9 | **Legacy name — this is the DISTRICT ceiling, not a legislature's size.** The code states it correctly: *"ceiling 9 — mandatory subdivision above"* (`SettingsController:80`). A legislature TOTAL has no ceiling: Earth's chamber holds 1,999, San Marino's 59. Read the Bicameral Support section before touching anything that uses this. |
| special_election_min_days | 90 | |
| special_election_max_days | 180 | |
| supermajority_numerator/denominator | 2/3 | Cannot produce result < majority+1 |
| max_days_between_meetings | 90 | |
| emergency_powers_max_days | 90 | Constitutional ceiling |
| civil_appointment_years | 10 | Must stay in lockstep with judicial |
| judicial_appointment_years | 10 | Must stay in lockstep with civil |
| residency_confirmation_days | 30 | |
| initiative_petition_threshold_pct | 5.00 | % of jurisdiction population |
| judiciary_is_elected | false | Requires supermajority + constituent supermajority |
| worker_rep_min_employees | 100 | |
| worker_rep_parity_employees | 2000 | |

---

## Database Schema (flattened baseline)

The full schema (183 tables) ships as a one-step baseline at
`database/schema/pgsql-schema.sql` — a full `pg_dump` of a virgin database after
the final run of the 196 dev-era migrations (flattened 2026-07-05, operator
order). Fresh installs load it in seconds via plain `php artisan migrate`;
reference rows (cosmic_addresses, the instance_settings singleton, the
audit_log genesis entry) ride inside the dump.

**New schema changes** = new migration files in `database/migrations/`,
REAL-dated (≥ 2026-07-05), additive-only, applied on top of the dump. Never
date a migration before an object it references: the retired dev-era files
used fake-future dates (2026_08…2026_12) as phase namespaces, and a real-dated
file landing mid-sequence broke virgin installs. Re-flattening (dump + prune)
is allowed ONLY when no live box holds unapplied migration history — operator
sign-off required.

Core tables (orientation):

```
jurisdictions                         PostGIS geometry, self-ref parent_id, federation fields
constitutional_settings               Amendable defaults scoped per jurisdiction (1:1)
organizations                         Universal entity (political_party|business|nonprofit|common_good_corp|informal)
legislatures                          Legislature instances, term tracking, bicameral support
legislature_district_maps             Versioned district plans per legislature (draft / active / archived)
legislature_districts                 Districts within a plan, seat counts NEAREST-ROUNDED (see apportionment law below)
legislature_district_jurisdictions    Members of each district (join: district_id ↔ jurisdiction_id)
legislature_members                   Elected reps, seat type, term dates, vacancy tracking
elections                             Election trigger/cycle framework
endorsements                          Polymorphic — any org or individual can endorse any candidate
location_pings                        Private GPS pings, PostGIS point, auto-trigger for geom
residency_confirmations               Confirmed residency — unlocks voting + candidacy (absolute rights)
```

Plus Laravel defaults: users, cache, jobs.

`jurisdiction_maps` parallels `legislature_district_maps` —
`planet → jurisdiction_maps → jurisdictions` mirrors
`legislature → district_maps → districts`, so boundary changes version over
time without destroying historical data (in the baseline since Phase F).

---

## Apportionment Law (operator ruling 2026-07-13 — SETTLED, do not re-derive)

There is **NO Webster, Sainte-Laguë, largest-remainder, or any other textbook
apportionment method** anywhere in seat allocation. Do not describe, propose,
or implement one. The procedure (per legislature):

0. **THE LEVEL LAW (operator ruling 2026-08-30, his walk. Read this first;
   every step below reads population this way.)** Every seat split reads
   exactly ONE level: the direct children's own population rows. Each
   child's own row is its numerator. The sum of the children's own rows is
   the denominator. Drilling into a giant starts the same calculation
   fresh over THAT giant's direct children's own rows. The root's chamber
   sizes from the ROOT'S OWN row. One split, one level, one frame. His
   words: "at no point ever will you use the numerator for the prior step
   as the denominator for the next step."
1. The legislature ROOT's seats = rounded cube root of the root's OWN
   population row (Germany 84,399,813 → 439).
2. Split to children by population share. Each child's share = the child's
   own row over the sum of all the direct children's own rows. Quota =
   children-row-sum / budget; frac(child) = child's own row / quota.
3. A child whose share would round **past the ceiling** (frac ≥ ceiling + 0.5)
   rounds to its **nearest whole immediately and locks** (a "giant"). Shares
   that round to the ceiling or below do not round here.
4. Budget minus locked giants **redistributes among the rest**; repeat down the
   layers. If redistribution pushes a share past the ceiling, the giant split
   repeats until no layer has an unsplit giant.
5. **Drawn districts round to NEAREST, independently** — no total-forcing, no
   rebudgeting after the giant split. If a pool's drawn districts miss whole
   multiples, the seated total drifts from the pool budget; that drift is the
   drawing's defect, fixed by redrawing — never by a redistribution loop.
   (Exceptions: an above-ceiling jurisdiction with no children awaits manual
   split; STV/Droop in `VoteCountingService` is the ELECTION method, unrelated.)
6. **Exactness rule for the autoseeder** (ruling 2026-07-13, Draft-9 India
   undercount): generated configurations whose nearest-rounded seats do not
   sum to the pool budget are **excluded** — another configuration must be
   considered. Only when NO exact drawing exists (indivisible-atom scopes)
   does the closest one ship, under the undercount flag.
7. **DRIFT IS ALWAYS WRONG** (ruling 2026-07-26, `0e9eda0`). The chamber size
   is FIXED by the cube-root law, so a total that misses the pool budget
   leaves seats unfillable or unallotted. Drift is never lawful, acceptable,
   "noise", or "not the theoretical ideal" — do not describe it as any of
   those. Two consequences, both now in `DistrictingService`:
   - **Exactness outranks the comparator.** An exact landing is adopted
     unconditionally. Spread and compactness are qualities; exactness is the
     law. `landSeatVector` attempts whenever the total misses — never gated
     on the spread being worse than canonical.
   - **No child-count ceiling on the repair.** Closing a seat gap needs a
     MOVE, not an enumeration of every partition, so the old ≤10-children
     limit is gone. `repairSeatSumByMoves` walks single-child moves between
     bins (adjacency preferred, band respected, bins never emptied), taking
     the move that most reduces `|total − budget|`, repeating until exact.
     Deterministic ties: lowest bin index, then lowest child id.
   This is still a REDRAWING, not a redistribution loop — step 5 stands.

8. **THE LEGISLATURE CEILING EXCEPTION** (operator ruling 2026-08-28 —
   "everyone, everywhere, all the time"). A FORCED floor exception whose
   lawful landing is 1 or 0 seats receives BONUS SEATS added to the
   legislature itself, raising that district to exactly 2 — so the
   runner-up is represented too, and no resident is ever seatless. The
   chamber total for such a map = cube-root total + Σ bonus. Bonus is
   stored per district (`legislature_districts.bonus_seats`); every
   exactness identity compares `seats − bonus_seats` against the budget.
   Elimination still comes first (the override repair); the bonus is the
   LAST step, applied only to forced sub-2 landings. The zero case is the
   giant-consumed residue plane (Kuala Lumpur, 2026-08-28); the one case
   is dominant-atom dust (Cordillera's [9,1] → [9,2]).

Implementation: `DistrictingService::computeSeatBudget` + `levelRows` (the
level law, cascade steps 0-4), Step 11 of `runAutoCompositeForScope` (step 5),
`seat_drift` as `scoreRank()`'s first key, the final-bin break-tolerant repair
for scattered-component pools that never enter the k-loop (step 6),
`overrideRepairPass` (last-resort elimination) and the sub-2 bonus lift at
every seat-writing plane (step 8). Three landings close step 7 exactly
(2026-08-30, the Germany 439 convergence): the fixed-composition seat landing
at Step 11 (`optimalIntegerTargets` lands the vector when no recomposition is
legal — the Bayern pool 7+7+6+6→26 lands 7+7+6+5=25), the all-giant lock
landing in `giantChildrenForScope` (locks land the budget when every child is
a giant — NRW 95→94, Arnsberg 19→18), and the stale-scope purge at the end of
every full-map sweep (districts of scopes outside the lawful walk disband —
the Brandenburg-under-Berlin +13). Pinned in `DistrictingDoctrineTest`.

---

## THE ETL PARADIGM (operator ruling 2026-08-02 — NEVER VIOLATE)

**Read this before writing any query or pipeline code. It binds DIAGNOSTIC and
EXPLORATORY queries exactly as hard as production writes** — that carve-out is
the most repeated mistake in this repo's history. A read-only `SELECT` carrying
a planet-wide `ST_SymDifference` OOM-killed PostgreSQL on 2026-08-04; the
planner does not care that the author only meant to "look".

Five laws for **all** bulk pipelines:

1. **Flexible + multithreaded** — the same code runs on a Raspberry Pi and on a
   supercomputer. Derive sizing from the host; never hard-code.
2. **Chunkable** — bounded units of work, always.
3. **Resumable** — commit each chunk; a kill mid-run costs one chunk, not the pass.
4. **Visible** — fine-grained chunks with elapsed and ETA. No opaque bars, and
   never fabricate progress.
5. **FAST** — *"the existing ETL code base is NOT the paradigm."* Research novel
   techniques. Examine the GOAL — how the data is actually used — before
   optimising the mechanism.

**Corollaries, each learned the hard way:**

- **Never one planet-wide statement.** Bulk writes are bounded, committed chunks
  with per-chunk progress. `VACUUM` before dependent passes.
- **A `LIMIT` does not make a query bounded.** If the CTE or join beneath it is
  planet-wide, the expensive predicate still materialises before the limit
  bites. Bound the INPUT — fetch a cheap id roster, then process it in batches.
  Never rely on bounding the output.
- **All-or-nothing is a bug, not a safety property.** A single unchunked
  `UPDATE` under a `statement_timeout` discards every row it already computed.
  This cost India: 7,143 orphans dragging 649,578 correctly-parented descendants
  out of the tree, to avoid holding up a run for 16 islets.
- **Derive from host, never hard-code.** Sizing = clamp(fraction/appetite) read
  from the real cgroup/host limits, env-overridable, written if absent. A magic
  number (640 MB per pair, a 180 s budget, a 500-row cap) is a defect waiting
  for a different host. Floor: it must still run on a Pi — days are acceptable.
- **DONE must be EVIDENCED.** Compare audit counts against meta counts. A
  function that returns normally after recording per-item failures has not
  succeeded.

---

## THE MULTI-LANE AUTOSCALING PARADIGM (MAX USE OF RESOURCES — operator ruling 2026-08-05, NEVER FORGET)

The lane law for every parallel engine in this codebase. **Recall it at the
START of every turn that touches engine code; VERIFY the turn's changes held
it at the END of every such turn** — the operator had to restate parts of it
five times in one day, and that must never happen again.

1. **ONE PILE PER KIND, ordered by est_cost, drained from BOTH ENDS AT ONCE**:
   half its lanes eat largest→smallest (monsters start immediately — crashes
   surface early), half eat smallest→largest (lights churn — small-class bugs
   surface on their own). *Debug to the middle.*
2. **Independent sibling kinds split the pool 50/50** (boundaries+rasters;
   resolve+attribution), odd lane tiebroken arbitrarily. **A finished
   sibling's lanes flow to the other side immediately.** Two directions per
   pile × two piles = four concurrent drain directions per group.
3. **THE SMALLS NEVER STOP.** A reservation for a waiting monster holds the
   monster's space OUT of the budget while lights keep admitting into the
   remainder. Any mechanism that zeroes the small-first direction violates
   the paradigm (proven live: the width-2 full-stop drain, 2026-08-05).
4. **MAX USE OF RESOURCES, all derived, nothing hard-coded**: pool width from
   host cores; admission from container memory (charged PEAK-sum — actuals
   look generous while incumbents are below peak, then peaks arrive together:
   ten exit-9s in one minute, cc63c80); slice/chunk sizes adaptive. When one
   phase releases resources, the surviving phase takes them automatically.
5. **THE ESCAPE-HATCH LAW**: a recovery control (Fresh, rewind, halt-resume,
   retry) is NEVER blocked by, waiting on, or deferring to the state it
   exists to recover from — in ANY layer: endpoint guard, DB state, control
   files, AND frontend enablement. It SEIZES: abandon stale runs, terminate
   holders, clear control files, then act. The only lawful refusal protects
   a DIFFERENT thing (e.g. an accepted load-bearing map). When the operator
   states a law about one control, apply it to EVERY sibling control in the
   same turn.
6. **Reviews run MINIMAL, not zero**: a review retry fires only after its
   whole GROUP drains, retries all residue together, halves lanes only when
   residue exceeds half the pool, and the next group waits for review-clear.

## COMMUNICATION GUIDELINES (operator ruling 2026-08-28 — binds every session, every lane, every box)

All user-visible text follows these six rules:

1. **Technical Neutrality — THE FIRST AND MOST IMPORTANT RULE (operator
   order 2026-08-30).** Tone consistent with ASD-STE100 Simplified
   Technical English in EVERY message. Robotic, precise, purely
   functional. Short sentences. One idea per sentence. Analysis answers
   follow this rule too.
2. **No Fake Work (operator order 2026-08-30: rule two).** Reviews and
   updates contain only actual improvements. If no improvement exists, say
   "no change needed." This rule covers FLAGS: report a flag only when it
   is legitimate and verified. A speculative or padding flag is fake work.
3. **Be Objective.** No flattery, social cushioning, or "glazing." No
   praise for the operator's ideas. No expressed excitement about tasks.
4. **Use Plain Speech.** Direct and concise. No jargon, no filler.
5. **Strict Formatting.** No excessive punctuation. No em-dashes. Avoid
   overused colons. Simple, readable structure.
6. **Evidence-Based.** No claims or source citations without a direct,
   verifiable link. Never invent a link.

The diagram-first rule and the open-question rubric below are unaffected.

## THE OPEN-QUESTION RUBRIC (standing communication channel — operator ruling 2026-08-04)

**How the operator wants decisions surfaced to him. Reference it the way you
reference the ETL Paradigm — always, and BEFORE you park a decision in prose.**
This is the operator's chosen interface for direction; future models must learn
to communicate with him through it.

When work surfaces a choice that is the operator's to make — a constitutional
option, a build-order call, a "build now vs defer", a design fork — it does NOT
go in a chat paragraph, a scattered TODO, or a plan doc's prose where it will be
lost or re-litigated. It goes in the **fleet-management open-question rubric**:

- **Source of truth:** `docs/plans/ui/tools/gen_app_rubric.py`, the `QUESTIONS`
  list. Each entry is
  `{"id", "q", "status":"open", "lane", "detail", "options":[{"k","t"}, …]}`.
  Give 2–4 real options, a `detail` that states what is actually at stake, and
  mark `[desk rec]` on an option when you have a defensible recommendation.
- **Regenerate:** `python3 docs/plans/ui/tools/gen_app_rubric.py` writes
  `app_progress_rubric.html` beside it (path is self-relative — runs on any box).
  The operator opens the **Open Questions** tab, picks an option, adds notes,
  and clicks **Copy** / **Export answers**, then pastes the block back into chat.
- **Record the answer; never re-ask.** Fold his reply into the `_ANS` dict — it
  flips the question to `resolved` with the ruling AND his verbatim note
  preserved — then regenerate. A resolved question is read-only history. Settled
  rulings are **never re-asked**, the same standing order the constitutional
  rulings carry.
- **Lane tag:** a fleet number for lane-owned work, or a short word (`map`,
  `ui`, `scale`) for desk-owned work no lane owns. The badge renders non-numeric
  lanes as-is.

Why this and not prose: it hands the operator options instead of an open-ended
prompt, a notes box for nuance, a copy-paste round-trip that costs him seconds,
and a durable record so no future model re-asks what he already settled. The
diagram-first rule is its companion — he reads the chart, skips the paragraph.
**When you have a question for the operator, the rubric is the answer to "where
does it go."**

---

## Architecture Principles

### Two-Layer Constitutional Hardening
- **Hardened Layer**: STV algorithm, supermajority calculation, rights enforcement, bicameral
  dual-agreement, proportionality guarantees. Protected by constitutional test suite.
- **Flexible Layer**: Reads `constitutional_settings`. Changes only through valid legislative acts.

### Federation-First
- UUIDs on all primary keys (cross-instance safe)
- `authoritative_server_id` NULL = this server is authoritative
- Eventual consistency, authoritative-instance-wins conflict resolution
- No assumption of single-server authority anywhere

### Organizations (no Factions)
Political parties, businesses, nonprofits, CGCs — all `organizations` with `organization_type`.
Any org OR individual user can endorse any candidate via the polymorphic `endorsements` table.
The legacy `legislature_faction_registrations` table and the faction-id columns on
`legislature_members` were removed during the 2026-05 apportionment cleanup —
there is no faction layer in the baseline schema.

### Committee Assignment (Faction-Independent)
Each legislature member rank-orders committee preferences. Each committee has a
fixed number of seats per the constitutional settings; the total committee
seats across all committees = the number of placements to fill.

1. Each member rank-orders committee preferences
2. Placements respect rank order
3. Tie-breaks go to the legislative seat holder with the largest share of votes
   after normalizing quotas to account for one-person-one-vote deviations.
   This preserves the proportional representation produced by the STV election
   while making committee assignment independent of any party/faction layer.

### Executive Types (Article III)
- **Committee**: 5+ via PR-STV, equal voting power (UK model)
- **Individual**: Single winner via RCV, top 4 runners-up as automatic advisors (US model)
Both start as legislature-delegated. Converts to directly elected by supermajority.

### Bicameral Support (Article V §3) — SETTLED 2026-07-26, RACE STRUCTURE corrected 2026-07-29

The two chambers answer **different questions** and are sized by **different rules**. The old
one-line summary here had them backwards and that error propagated into `racePlan()`, which
blocked ~30k chambers. **A SECOND error lived here until 2026-07-29: it said Type B was "ONE STV
race" — WRONG.** Type B is one at-large race PER CHILD (or per clump) — now BUILT + hardened that
way (Wave 4; Niue cleared LIVE, the pooled shape is retired). Read this section before touching
seats, races or districting.

**Type A — proportional. Population is everything.**
- Total = `max(5, round(population^(1/3)))` — the cube-root law over the
  jurisdiction's OWN row (THE LEVEL LAW, apportionment step 0). **No
  ceiling on the total.**
- That total is **districted into races of 5–9 seats**, drawn so population shares are as exact
  as the engine can make them (splitline · cells · composite · graph partition).
- **The 5–9 band is a DISTRICT rule, not a chamber rule.** A legislature total above 9 is normal
  and is resolved by subdividing — Earth is 1,999 seats across 282 districts.

**Type B — equal representation of the constituent jurisdictions. Population is IRRELEVANT.**
- Every direct constituent gets the **same** number of seats regardless of its population.
- Total = `seats_per_constituent × number_of_constituents` (setting `type_b_seats_per_child`,
  default 5).
- **⚑ THE RACE STRUCTURE (operator ruling 2026-07-29, DEFINITIVE — one at-large race PER CHILD,
  or PER CLUMP; NOT one pooled race).** Type B is **one at-large STV race per direct child
  jurisdiction**, each electing that child's own equal seats from that child's own residents.
  When a size ceiling forces clumping (below), it becomes **one at-large race per clump**, voted
  at-large within the clump for the clump's seats. This holds at every size — **ungrouped =
  per-child, grouped = per-clump, same rule** (no big single election over everybody, ever). The
  5–9 district band does NOT bind a Type B race (like `exec_committee` / `judicial_group`).
- **✅ BUILT + HARDENED (Wave 4).** `racePlan` / `createRaces` emit **per-child / per-clump**
  at-large races — never a pooled race — and PROTECTED `VoteCountingService` counts per-unit
  against the clump/grouping key on `election_races`. Niue cleared LIVE (5 clumps × 2 = 10, both
  chambers elect). The old pooled shape is fully retired. Ungrouped chambers elect per-child;
  grouped chambers (~9,708 flagged worldwide) elect per-clump via an operator-coordinated mass pass.
- **A leaf has no Type B** — no constituents to represent. Its own representation appears in its
  PARENT's Type B chamber.

**The two perspectives (operator's spec, 2026-07-29 — the definitive statement of the model):**
- **As PARENT:** *Type A* = your active map; districts drawn from your children's
  population/config (cube-root total). *Type B* = equal seats per child, **one at-large race per
  child**; if `children × reps` overflows the Type A size, step reps-per-child 5→4→3→2, then clump
  nearest neighbours (pair/tri/quad…) until it fits — **each clump one at-large race across the
  clump**. No children → Type B disabled.
- **As CHILD:** *Type A* = population-based — you as a whole at-large district (composable/single)
  or split internally (giant-split via your descendants, or splitline if you have none). *Type B*
  = equal with your peers under the same parent; hit a size ceiling and you **clump with your
  immediate neighbour**; **the clump votes at-large for the clump, whatever its size** (pair/tri/
  quad — clump size does not change this).

**The only bound on Type B: it may not exceed the Type A total.** When it does, reduce in two
stages, in this order:
1. **The ladder** — step `seats_per_constituent` down 5 → 4 → 3 → 2 until the total fits.
   (San Marino: 9 castelli × 5 = 45 > 32, step to 3 → 27, fits. Done, no grouping.)
2. **Type B districting — ONLY if 2-per-constituent still overflows.** Clump whole constituent
   jurisdictions into shared panels: pairs, then triples, and so on until the total fits.
   **Evenly** (every group holds the same count, so representation stays equal) and **compactly**
   (nearest neighbours clump; adjacency matters). **No geometry is ever cut** — this is a
   balanced grouping over an adjacency graph, not a drawing operation. Populations are not
   consulted at any step.
   (50 states, 1,000 people → Type A 10; at 2 apiece = 100; pairs = 50; **5 groups of 10 states
   × 2 = 10**, fits. Ten states share one panel.)

**Both chambers must independently agree for an act to pass.** An empty Type B chamber therefore
blocks every bicameral act — creating an executive, creating a judiciary, committee creation,
enacting a bill. That is correct behaviour, not a bug: fix the seating, never the rule.

---

## Protected Files

**WHAT "PROTECTED" MEANS — operator ruling 2026-07-26. Read this before escalating anything.**

- These files are guarded **for a LIVE deployment**. We are in **DEVELOPMENT**. Fix them.
- **Protecting a file that is broken protects nothing.** If the file is wrong, that IS the reason
  to change it.
- **Do NOT stop and ask permission to fix a defect in these files.** Announce on the board, make
  the fix, pin it, move on. The goal is a demoable, playable state.
- **What still needs the operator's word:** changing a constitutional RULE (what the law says),
  not repairing code that fails to implement the rule correctly. Weakening a pin needs his word;
  extending or correcting one does not.
- Rule of thumb: *"am I changing what the constitution requires, or making the code match what it
  already requires?"* The second is ordinary work.

Files (announce, fix, pin — do not wait):

```
app/Services/VoteCountingService.php
app/Domain/Counting/   (counting core: Micro, BallotSet, CountInput, CountResult, RoundResult, CountbackResult)
app/Services/DistrictingService.php
app/Services/ElectionTriggerService.php
app/Services/ConstitutionalValidator.php
app/Services/Organizations/CoDeterminationService.php   (Art. III §6 hardened math — Phase D)
app/Models/ConstitutionalSettings.php
app/Models/Jurisdiction.php
database/schema/pgsql-schema.sql   (baseline DDL for jurisdictions / constitutional_settings / elections lives here)
```

---

## Module Build Order

**Phases 0–5 COMPLETE** (Foundation → Judiciary & Law). All live, constitutionally
tested (suite green, zero skips), each with standing browsable demo data
(`elections:demo`, `institutions:demo-d`, `institutions:demo-e`). Detailed phase
plans + designs in `docs/plans/institutions/PHASE_{A..E}_*.md`. The 118-form
ConstitutionalEngine (103 through Phase 5 + F-ELB-008 Manual District Draw from
Phase H + F-SOC-001..004 from the G/K social-mesh work + F-IND-022/023/024, the
Phase M economy write path + F-IND-019 Work Application and F-ORG-009 Internal
Restructuring, both minted in the v3 Wave 2 economy build + F-EDU-001/002
Training Completion / Training Material Publication, the Phase K-2 education
pair minted in the Wave 3 engine build (operator ruling 6) + F-ORG-008
Organization Market Participation (share issuance) and F-IND-020 Resident
Agreement (person-to-person / N-party agreements + clause redlines), the
Economy Design Round 2 build + F-IND-021 Share Trade (holder-to-holder
secondary share resale on the exchange), the Wave 4 economy build; FormRegistry also
resolves 6 legacy alias IDs —
F-COM-*→F-CHR-*, F-GOV-*→F-BOG-*), the PROTECTED hardened layer, and the
hash-chained audit log span every phase. The count is pinned exactly in
`AuditChainSmokeTest` — adding a form means raising it there deliberately.

- [x] **Phase 0 — Foundation**: Docker stack · Laravel 12 + Vue 3 + Inertia ·
  constitutional migrations · ConstitutionalEngine + hash-chained `audit_log` ·
  clocks + scheduler · activation engine · design system + AppShell + i18n
- [x] **Phase 1 — Identity & Jurisdictions**: UUID users + session auth ·
  residency claims + GPS pings · recursive ancestor-sweep associations · derived
  roles (R-01→R-04, never stored)
- [x] **Phase 2 — Elections Engine**: PROTECTED `VoteCountingService`
  (PR-STV/Droop/Gregory · RCV · universal countback) · two-phase open ballot ·
  ballot commitment scheme · bootstrap board · certification auto-seating
- [x] **Phase 3 — Legislature Operations**: peg-quorum chamber votes · bicameral
  dual agreement · speaker (RCV supermajority) · committees · bills → versioned
  laws · referendums · petitions · emergency powers (CLK-03)
- [x] **Phase 4 — Executive & Organizations**: exec delegation/conversion (dual
  supermajority) · departments + BoG (10-yr CLK-09) · executive orders w/
  pre-issuance scope validation · full org module + co-determination (CLK-13/14) ·
  board elections · CGC public-domain IP register
- [x] **Phase 5 — Judiciary & Law**: appointed/elected courts (equal-per-constituent
  nomination) · cases/panels/juries/advocates · double jeopardy · the Art. IV §5
  three-path challenge ending in DIRECT judicial law-editing (`judicial_remedy`
  law version, full history preserved)

**Phase 6 — Federation & mobile** (Weeks 61-76) ← NEXT
- Peer mesh + Full Faith & Credit sync + authority flip (export bundle = seed) ·
  union formation / disintermediation / border settlement / restoration · Sanctum
  + Capacitor geofenced GPS pinging · full i18n

---

## Naming Conventions

- Database: `snake_case` tables and columns
- PostGIS geometry: always named `geom`, SRID 4326
- UUIDs: all PKs and cross-table references
- Soft deletes: **MOST tables carry `deleted_at` — this is a convention, NOT a guarantee.**
  Verified exceptions found the hard way: `emergency_power_renewals` (no `deleted_at`, no
  `status`), `remedy_recommendations` (no `status`), `residency_confirmations`,
  `constitutional_settings`. **Check `information_schema` before writing a query that assumes
  either column.** This line previously read "all tables" and broke three separate lanes' work.
- Timestamps: UTC, PostgreSQL `timestamptz`
- Enums: strings with app-layer validation (not PostgreSQL ENUM type)

---

## Geospatial Data Sources

| Dataset | License | Use |
|---|---|---|
| geoBoundaries | CC BY 4.0 | Administrative boundaries ADM0-ADM2 |
| WorldPop | CC BY 4.0 | Population estimates |
| OpenStreetMap | ODbL | Supplemental local boundaries |
