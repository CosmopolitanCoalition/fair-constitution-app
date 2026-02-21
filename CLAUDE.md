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

## Database Schema (9 constitutional migrations)

```
jurisdictions                         PostGIS geometry, self-ref parent_id, federation fields
constitutional_settings               Amendable defaults scoped per jurisdiction (1:1)
organizations                         Universal entity (political_party|business|nonprofit|common_good_corp|informal)
legislatures                          Legislature instances, term tracking, bicameral support
elections                             Election trigger/cycle framework
legislature_members                   Elected reps, faction affiliation, seat type, vacancy tracking
legislature_faction_registrations     Org registered as faction for a legislature term (drives proportionality)
endorsements                          Polymorphic — any org or individual can endorse any candidate
location_pings                        Private GPS pings, PostGIS point, auto-trigger for geom
residency_confirmations               Confirmed residency — unlocks voting + candidacy (absolute rights)
```

Plus Laravel defaults: users, cache, jobs.

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

### Organizations (not Factions)
Political parties, businesses, nonprofits, CGCs — all `organizations` with `organization_type`.
Any org OR individual user can endorse candidates. `legislature_faction_registrations` links
an org to a legislature term for committee proportionality.

### Committee Assignment (Modified)
Constitution assumed single-faction members. This app supports multi-faction and factionless:
1. Each member ranks committee preferences
2. `Total Reps / (Committees × seats_per_committee)` = each member's allocation
3. Conflict resolution: 1st-choice election performance, then subsequent rank performance

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
app/Services/DistrictingService.php
app/Services/ElectionTriggerService.php
app/Services/ConstitutionalValidator.php
app/Models/ConstitutionalSettings.php
app/Models/Jurisdiction.php
database/migrations/2026_01_01_000001_create_jurisdictions_table.php
database/migrations/2026_01_01_000002_create_constitutional_settings_table.php
database/migrations/2026_01_01_000005_create_elections_table.php
scripts/etl/run_skater.py
```

---

## Module Build Order

**Phase 0 — Foundation** ← CURRENT PHASE
- [x] Docker stack
- [x] Laravel 12 + Vue 3 + Inertia.js
- [x] 9 constitutional migrations
- [x] docs/ reference library + extract_docs.py
- [ ] Laravel Models + relationships
- [ ] ConstitutionalValidator service
- [ ] Audit log with cryptographic chaining
- [ ] Pinia state management + i18n

**Phase 1 — Identity & Jurisdictions** (Weeks 7-14)
**Phase 2 — Elections Engine** (Weeks 15-24) — VoteCountingService (PROTECTED)
**Phase 3 — Legislature Operations** (Weeks 25-36)
**Phase 4 — Executive & Organizations** (Weeks 37-48)
**Phase 5 — Judiciary & Law** (Weeks 49-60)
**Phase 6 — Federation** (Weeks 61-76)

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
