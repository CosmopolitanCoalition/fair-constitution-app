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
1. `Fair_Constitution_Labeled.docx` — supreme authority on all policy and rules
2. `CGA_Architecture_Plan.docx` — authoritative technical architecture decisions
3. `CGA_Constitutional_Roles_Forms_Chart.xlsx` — exhaustive role/form/institution mapping
4. `The_Chart.drawio` — principles diagram and governance structure visualization
5. `App_Flows.drawio` — application flow maps (work in progress)

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
| Legislature min seats | 5 | Art. II §2 |
| Legislature max seats | 9 (mandatory subdivision above this) | Art. II §2 |
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
| legislature_min_seats | 5 | Cannot go below 5 |
| legislature_max_seats | 9 | Cannot go above 9 |
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

1. The legislature ROOT's seats = rounded cube root of its population (Earth → 1999).
2. Split to children by population share **with the CHILDREN-SUM as denominator**
   — never the parent's stored population (geodata noise: parent ≠ Σchildren).
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

Implementation: `DistrictingService::computeSeatBudget` (cascade, steps 1-4),
Step 11 of `runAutoCompositeForScope` (step 5), `seat_drift` as `scoreRank()`'s
first key, and the final-bin break-tolerant repair for scattered-component
pools that never enter the k-loop (step 6). Pinned in `DistrictingDoctrineTest`.

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

### Bicameral Support (Article V §3)
- `type_a_seats`: constituent jurisdiction reps
- `type_b_seats`: at-large reps
- Both types must independently agree for acts to pass

---

## Protected Files (Constitutional Review Required Before Modification)

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
plans + designs in `docs/plans/institutions/PHASE_{A..E}_*.md`. The 104-form
ConstitutionalEngine (103 through Phase 5 + F-ELB-008 Manual District Draw from
Phase H), the PROTECTED hardened layer, and the hash-chained audit log span every
phase.

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
- Soft deletes: all tables use `deleted_at`
- Timestamps: UTC, PostgreSQL `timestamptz`
- Enums: strings with app-layer validation (not PostgreSQL ENUM type)

---

## Geospatial Data Sources

| Dataset | License | Use |
|---|---|---|
| geoBoundaries | CC BY 4.0 | Administrative boundaries ADM0-ADM2 |
| WorldPop | CC BY 4.0 | Population estimates |
| OpenStreetMap | ODbL | Supplemental local boundaries |
