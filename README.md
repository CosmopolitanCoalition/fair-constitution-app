# Cosmopolitan Governance App (CGA)

We endeavor to answer the question: *For a given population, what are the Optimal Methods for distributing power between ALL entities, such that the most individuals would agree that the Methods selected are the fairest that can be implemented?* — or — **How do we get eight billion people to get along?**

The CGA implements [A Fair Constitution (Cosmopolitan Template)](https://cosmopolitancoalition.org) as interactive, automatable software: residency, elections, legislatures, executives, judiciaries, organizations, and nested jurisdictions from neighborhood to planetary scale.

It is **federated**: anyone can run their own instance and either keep it standalone or join a
mesh of instances that mirror and sync each other's public records over redundant, secure
transports — so the network survives no matter who is trying to block or tamper with it.

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

Everything runs in Docker containers, so you do **not** install PHP, PostgreSQL, Node, or
Python yourself — only Docker and Git.

---

## Prerequisites (per OS)

You need exactly two things on the host, plus disk space:

| | Windows 10/11 | macOS | Linux |
|---|---|---|---|
| **Container runtime** | [Docker Desktop](https://www.docker.com/products/docker-desktop/) (WSL2 backend) | [Docker Desktop](https://www.docker.com/products/docker-desktop/) | Docker Engine + the Compose v2 plugin (`docker compose`) |
| **Git** | [Git for Windows](https://git-scm.com/download/win) | `xcode-select --install` or Homebrew | your package manager (`apt/dnf/...`) |
| **Disk / RAM** | ~20 GB free + 8 GB RAM | same | same |
| **(only for the deploy/join scripts)** | PowerShell 7+ (`winget install Microsoft.PowerShell`) + `winget` | bash (built in) | bash + `jq` (`apt install jq`) |

> The ~20 GB is mostly the optional world geospatial dataset — you can scope it to one country
> in the wizard and need far less to start. Start Docker Desktop (Windows/macOS) and make sure
> `docker version` works before you begin.

---

## Path 1 — Try it locally, from scratch (any OS)

Clone the repo, start the stack, and walk the setup wizard. No manual migrations, no
`php artisan` ceremony — the wizard does it all.

```bash
git clone https://github.com/CosmopolitanCoalition/fair-constitution-app.git
cd fair-constitution-app
cp .env.example .env          # ships with a working dev key; no edits needed to start
docker compose up -d          # first run builds images + installs deps — a few minutes
```

> **Windows:** run these in PowerShell or Git Bash with Docker Desktop running. The commands
> are identical (`cp` works in PowerShell 7; in older shells use `copy .env.example .env`).

Then open **<http://localhost:8080/setup>** in your browser. (Defaults: app `:8080`,
Vite HMR `:5173`, PostgreSQL `:5432` — change them in `.env` only if those ports are taken.)

### First paint — the setup wizard

On an empty database the wizard detects the blank schema and walks you through, one button at a time:

1. **Database setup** — apply the schema migrations from the UI.
2. **Cosmic address** — which jurisdiction is this instance running (neighborhood → planet)?
3. **Constitutional defaults** — author the constitutional values for it.
4. **Map data** — kick off the ETL (loads geoBoundaries + WorldPop; scope to a country for a fast first run).
5. **Districts** — auto-apportion and build the districts.
6. **Confirm + seat institutions** — finalize and register your **founder account**.

After seating, sign in as the founder and you land on the civic dashboard — a live instance you
can run elections, legislatures, and courts in.

---

## Path 2 — Deploy a shareable / production node

`docker compose up -d` (above) is the dev posture (hot-reload, localhost only). To stand up a
node that serves built assets and is reachable from other machines on your network, use the
one-command deploy script (it writes `.env`, mints a fresh identity, migrates, seeds, builds
assets, and starts everything in the right order):

```bash
# Linux / macOS
./deploy.sh

# Windows (PowerShell 7)
./deploy.ps1
```

Flags let you run a second instance or adopt this node into an existing cluster as a read-only
mirror in one step, e.g. `./deploy.sh --prefix fcm --nginx-port 8082 --join http://<host>:8080 --key <handle.secret>`
(`-Prefix`/`-NginxPort`/`-Join`/`-Key` on Windows). Run `./deploy.sh --help` for the full list.

---

## Path 3 — Join a federation (the survival mesh)

To make a node part of the mesh — discoverable, syncing, and reachable over redundant secure
transports (a private tailnet, a Tor hidden service, the Yggdrasil overlay, or plain HTTPS) —
use the universal bootstrap, which installs/configures the transports you pick, brings the app
up, registers them, and runs a readiness check:

```bash
# Linux / macOS
./bootstrap/bootstrap.sh

# Windows (PowerShell 7)
./bootstrap/bootstrap.ps1
```

Then, in the browser, open **`/federation`**, sign in as operator, and use the
**"Two-way mesh — setup & gates"** panel to **Discover** a peer, **Handshake** it, and **Probe**
the connection — with a green/amber/red readiness checklist (the operator's "run the tests, get
the greens"). The same checks run in the terminal: `php artisan mesh:gates` and
`php artisan mesh:doctor <peer-url>`.

Read these before/while joining:
- **[bootstrap/README.md](bootstrap/README.md)** — the bootstrap layer + verify-before-run (checksums/signature).
- **[docs/plans/phase-g-continuation/G8b-OVERLAY-EGRESS.md](docs/plans/phase-g-continuation/G8b-OVERLAY-EGRESS.md)** — wiring the secure overlay (the one host-side step; Tailscale is the easiest for a Windows↔Linux pair).
- **[docs/plans/phase-g-continuation/G8b-CROSS-WAN-TWO-WAY-RUNBOOK.md](docs/plans/phase-g-continuation/G8b-CROSS-WAN-TWO-WAY-RUNBOOK.md)** — the full two-node, two-way procedure + certification gates.

---

## Geospatial data

The ETL needs ~14 GB of external geospatial datasets (geoBoundaries + WorldPop). The wizard lets
you scope to specific countries/ADM levels for faster initial runs. Full-world ETL takes 6–12
hours depending on host hardware; chunk sizes auto-tune to your container's memory budget (see
[`scripts/etl/memory_budget.py`](scripts/etl/memory_budget.py)).

For data acquisition see **[docs/DATA_ACQUISITION.md](docs/DATA_ACQUISITION.md)**.

---

## Repository Structure

```
fair-constitution-app/
├── app/                    Laravel application (models, controllers, services)
├── bootstrap/              Survival-mesh bootstrap (mesh-catalog.json + the OS front-ends)
├── database/               Migrations and seeders
├── deploy.sh / deploy.ps1  One-command node deploy (Linux·macOS / Windows)
├── docs/                   Reference documents, data acquisition, and the phase plans
├── resources/              Vue 3 frontend components
├── scripts/etl/            Python geospatial ETL pipeline
└── docker-compose.yml
```

---

## Documentation

| Document | Description |
|---|---|
| [bootstrap/README.md](bootstrap/README.md) | The survival-mesh bootstrap + verify-before-run |
| [docs/plans/phase-g-continuation/G8b-CROSS-WAN-TWO-WAY-RUNBOOK.md](docs/plans/phase-g-continuation/G8b-CROSS-WAN-TWO-WAY-RUNBOOK.md) | Two-node, two-way federation procedure |
| [docs/plans/phase-g-continuation/G8b-OVERLAY-EGRESS.md](docs/plans/phase-g-continuation/G8b-OVERLAY-EGRESS.md) | Wiring the secure overlay (per OS) |
| [docs/DATA_ACQUISITION.md](docs/DATA_ACQUISITION.md) | How to download geoBoundaries and WorldPop data |
| [scripts/etl/README.md](scripts/etl/README.md) | ETL pipeline reference |
| [CLAUDE.md](CLAUDE.md) | AI assistant context and constitutional hard constraints |
| `docs/Fair_Constitution_Labeled.docx` | The authoritative policy document |
| `docs/CGA_Architecture_Plan.docx` | Technical architecture and 76-week phasing plan |

---

## License

Open source. Constitutional intellectual property is always public domain per Article III §5
of A Fair Constitution.
