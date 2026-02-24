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

### 1. Clone the repository

```bash
git clone https://github.com/CosmopolitanCoalition/fair-constitution-app.git
cd fair-constitution-app
```

### 2. Configure environment

```bash
cp .env.example .env
# Edit .env — set APP_KEY and any local overrides
```

### 3. Start the stack

```bash
docker compose up -d
```

Services:
- **App** (Laravel): `http://localhost:8080`
- **Vite** (hot reload): `http://localhost:5173`
- **PostgreSQL**: `localhost:5432`

### 4. Run database migrations

```bash
docker compose exec app php artisan migrate
```

### 5. (Optional) Load geospatial data

The jurisdictions table is populated by a separate ETL pipeline. This step requires
downloading ~14 GB of external datasets and takes 6–12 hours to run.

See **[docs/DATA_ACQUISITION.md](docs/DATA_ACQUISITION.md)** for full instructions.

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
