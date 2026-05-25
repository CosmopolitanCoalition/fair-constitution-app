# Cosmopolitan Governance App (CGA)

We endeavor to answer the question: *For a given population, what are the Optimal Methods for distributing power between ALL entities, such that the most individuals would agree that the Methods selected are the fairest that can be implemented?* — or — **How do we get eight billion people to get along?**

The CGA implements [A Fair Constitution (Cosmopolitan Template)](https://cosmopolitancoalition.org) as interactive, automatable software: residency, elections, legislatures, executives, judiciaries, organizations, and nested jurisdictions from neighborhood to planetary scale.

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

---

## Quick Start

The goal: clone the repo, run two commands, walk through the wizard, and end up
with a fully populated instance. No manual schema migrations, no `php artisan`
ceremony — the wizard handles all of it.

### 1. Clone the repository

```bash
git clone https://github.com/CosmopolitanCoalition/fair-constitution-app.git
cd fair-constitution-app
```

### 2. Copy the environment file

```bash
cp .env.example .env
```

`.env.example` ships with a working dev `APP_KEY` baked in. The default
`ARCHIVE_PATH=./data/archive` is repo-relative and works on Windows, macOS,
and Linux without edits. Override `ARCHIVE_PATH` only if your geospatial
archive lives somewhere else.

### 3. Start the stack

```bash
docker compose up -d
```

Services:
- **App** (Laravel + Inertia + Vue 3): `http://localhost:8081`
- **Vite** (hot reload during dev): `http://localhost:5174`
- **PostgreSQL + PostGIS**: `localhost:5433`
- **Redis** (cache + queues)
- **ETL** (Python 3.12 supervisor — picks up wizard-issued runs)

### 4. Open the wizard

Visit `http://localhost:8081/setup` in your browser. The wizard's bootstrap
page detects the empty schema, runs the migrations on a button click, and
walks you through:

1. **Database setup** — apply schema migrations from the UI.
2. **Cosmic address** — what jurisdiction are you running?
3. **Constitutional defaults** — author the constitutional values.
4. **Map data** — kick off the ETL (loads geoBoundaries + WorldPop into the DB).
5. **Districts** — auto-apportion + build districts.
6. **Confirm + seat institutions** — finalize and register the founder account.

### 5. Geospatial data

The ETL needs ~14 GB of external geospatial datasets (geoBoundaries + WorldPop).
The wizard lets you scope to specific countries/ADM levels for faster initial
runs. Full-world ETL takes 6–12 hours depending on host hardware; chunk sizes
auto-tune to your container's memory budget (see [`scripts/etl/memory_budget.py`](scripts/etl/memory_budget.py)).

For data acquisition see **[docs/DATA_ACQUISITION.md](docs/DATA_ACQUISITION.md)**.

---

## Repository Structure

```
fair-constitution-app/
├── app/                    Laravel application (models, controllers, services)
├── database/               Migrations and seeders
├── docs/                   Reference documents + geospatial data acquisition
│   ├── DATA_ACQUISITION.md     How to download geoBoundaries + WorldPop
│   ├── fetch_worldpop.ps1      WorldPop download script (Windows)
│   ├── fetch_worldpop.sh       WorldPop download script (Linux/Mac)
│   ├── Fair_Constitution_Labeled.docx
│   ├── CGA_Architecture_Plan.docx
│   └── extract_docs.py         Extracts reference docs to docs/extracted/
├── resources/              Vue 3 frontend components
├── scripts/etl/            Python geospatial ETL pipeline
│   └── README.md               ETL pipeline documentation
└── docker-compose.yml
```

---

## Documentation

| Document | Description |
|---|---|
| [docs/DATA_ACQUISITION.md](docs/DATA_ACQUISITION.md) | How to download geoBoundaries and WorldPop data |
| [scripts/etl/README.md](scripts/etl/README.md) | ETL pipeline reference |
| [CLAUDE.md](CLAUDE.md) | AI assistant context and constitutional hard constraints |
| `docs/Fair_Constitution_Labeled.docx` | The authoritative policy document |
| `docs/CGA_Architecture_Plan.docx` | Technical architecture and 76-week phasing plan |

---

## License

Open source. Constitutional intellectual property is always public domain per Article III §5
of A Fair Constitution.
