# Cosmopolitan Governance App (CGA)

> # ⚠️ Early development — experimental and UNVERIFIED
>
> **This is pre-alpha software under active development. It is NOT production-ready, has NOT
> been security-audited, and has NOT been validated in real-world use.** Features — including
> the federation / two-way mesh — are exercised only by an automated test suite on a developer
> stack; they are **not certified** on real hardware or networks, and the data model, APIs, and
> on-disk formats can change at any time with no migration path.
>
> **Do not use it for real elections, real governance decisions, or with real personal data.**
> It is for evaluation, development, and contribution only — **at your own risk, with no warranty
> of any kind** (see the License). Treat anything a node produces as throwaway.

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

## 🚀 Start here — choose your operating system

**You do not need to be technical.** You install two free programs, copy a few lines, and open a
web page. Pick your system below and follow it top to bottom. (Plan on ~20 GB free disk and
leaving the computer on for the first run.)

Why two programs: the app runs inside **Docker** — a free tool that runs everything (database,
web server, the lot) in a self-contained box, so you don't install any of that by hand. The
second thing is just **the code** from this page.

<details open>
<summary><b>🪟  Windows 10 / 11</b></summary>

1. **Install Docker Desktop.** Download from <https://www.docker.com/products/docker-desktop/>,
   run the installer (say yes if it offers *WSL2*), then **open Docker Desktop and wait** until
   the bottom-left says **Engine running**.
2. **Get the code.** On this GitHub page click the green **`<> Code`** button → **Download ZIP**,
   then unzip it somewhere easy, like your Desktop. *(If you already have Git, you can instead run
   `git clone https://github.com/CosmopolitanCoalition/fair-constitution-app.git`.)*
3. **Open PowerShell.** Click **Start**, type **PowerShell**, press **Enter**.
4. **Start the app.** Paste these one line at a time. A ZIP usually unzips to a folder named
   `fair-constitution-app-main` — adjust the path if yours differs:
   ```powershell
   cd "$HOME\Desktop\fair-constitution-app-main"
   copy .env.example .env
   docker compose up -d
   ```
   The first run takes a few minutes while it downloads and builds.
5. **Open it.** In your browser go to **<http://localhost:8080/setup>**.

</details>

<details>
<summary><b>🍎  macOS</b></summary>

1. **Install Docker Desktop** from <https://www.docker.com/products/docker-desktop/>, open it, and
   wait until it says **Engine running**.
2. **Get the code.** Green **`<> Code`** button → **Download ZIP**, unzip it (e.g. to your Desktop).
3. **Open Terminal.** Press **⌘ + Space**, type **Terminal**, press **Enter**.
4. **Start the app** (adjust the path to where you unzipped):
   ```bash
   cd ~/Desktop/fair-constitution-app-main
   cp .env.example .env
   docker compose up -d
   ```
5. **Open it.** Go to **<http://localhost:8080/setup>**.

</details>

<details>
<summary><b>🐧  Linux  ·  🍓  Raspberry Pi</b></summary>

1. **Open your terminal** and install Docker + Git.
   **Already have Docker?** Skip the `curl … | sh` line — re-running it on a machine that already
   has Docker can conflict with your existing install. Just confirm `docker compose version` prints
   **v2.x** (if not: `sudo apt install -y docker-compose-plugin`), then continue at step 2.
   ```bash
   sudo apt update && sudo apt install -y git
   curl -fsSL https://get.docker.com | sh        # ONLY if Docker isn't already installed
   sudo usermod -aG docker $USER      # then log out and back in so 'docker' works
   ```
2. **Get the code:**
   ```bash
   git clone https://github.com/CosmopolitanCoalition/fair-constitution-app.git
   cd fair-constitution-app
   ```
3. **Start the app:**
   - On a normal PC: `cp .env.example .env && docker compose up -d`
   - **On a Raspberry Pi** (or any ARM device): run **`./deploy.sh --with-etl`** instead — it
     auto-picks the Pi-compatible database image **and** starts the map-data importer (both now
     build on ARM). The plain `docker compose up` uses an Intel-only database image.
4. **Open it.** From the same machine: **<http://localhost:8080/setup>**. From another device on
   your network: `http://<the-computer's-IP-address>:8080/setup`.

</details>

### What you'll see — the setup wizard ("first paint")

The first page detects the empty database, has you **apply the schema and create your
operator/founder account first**, then asks whether this node stands **solo** or **joins an existing
mesh**. **Solo** walks you through the build, one button at a time:

1. **Cosmic address** — which place is this instance for (a neighborhood, a city, a country… up to the whole planet)?
2. **Constitutional defaults** — set the rules.
3. **Map data** — load the maps + population. You stage the geoBoundaries + WorldPop files
   first (see [docs/DATA_ACQUISITION.md](docs/DATA_ACQUISITION.md)); scope to **one country** to
   keep it small instead of the full ~14 GB world.
4. **Districts** — it draws them automatically.
5. **Confirm + seat institutions** — finish, and you're in.

You're already signed in as the founder — a live instance where you can run elections, legislatures,
and courts. **Didn't open?** Make sure Docker is running, wait a couple more minutes on the very
first launch (it's still building), then reload the page.

> **Joining an existing mesh instead?** Choose **Join** at the fork and follow
> [docs/operator/multibox-ui-run.md](docs/operator/multibox-ui-run.md) — the UI-driven mirror join
> (Discover the host on your LAN → request join → the host operator approves → the whole game
> replicates with a live progress bar). It has one mandatory pre-bringup `.env` value
> (`FEDERATION_SELF_URL`) — read step 0 first.

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
up, registers them, and runs a readiness check. *(Linux/Pi needs `jq` first:
`sudo apt install -y jq`. Windows needs PowerShell 7.)*

```bash
# Linux / macOS
./bootstrap/bootstrap.sh

# Windows (PowerShell 7)
./bootstrap/bootstrap.ps1
```

> **Your node needs a peer-reachable address first.** A plain founder deploy (Path 1/2) sets
> `FEDERATION_SELF_URL` to a container-local default (`host.docker.internal:8080`) that other
> machines can't reach — so peers can't call you back. `bootstrap.sh` sets this for you, and
> `deploy.sh --self-url http://<this-machine's-LAN-IP-or-public-host>:8080` (`-SelfUrl` on
> Windows) does it on a deploy. If you deployed by hand, set `FEDERATION_SELF_URL` in `.env` to
> an address peers can actually reach before joining. `php artisan mesh:gates` flags a still-local
> address as the "callback URL is local-only" warning.

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
