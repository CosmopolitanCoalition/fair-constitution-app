# Cosmopolitan Governance App (CGA)

> ⚠️ **Early development — experimental and unverified.** This is pre-alpha software: not
> security-audited, not validated in real-world use, and its data formats can change at any time
> with no migration path. **Do not use it for real elections, real governance decisions, or real
> personal data.** It is for evaluation, development, and contribution only — at your own risk,
> with no warranty of any kind (see [LICENSE](LICENSE)).

We endeavor to answer the question: *for a given population, what are the optimal methods for
distributing power between all entities, such that the most individuals would agree that the
methods selected are the fairest that can be implemented?* — or — **how do we get eight billion
people to get along?**

The CGA implements [A Fair Constitution (Cosmopolitan Template)](https://cosmopolitancoalition.org)
as interactive, automatable software: residency, elections, legislatures, executives, judiciaries,
organizations, and nested jurisdictions from neighborhood to planetary scale. It is **federated**:
anyone can run their own world, standalone or joined into a mesh of instances that mirror and
sync each other's public records.

---

## Get started

Everything runs inside **Docker**, so you don't install databases or programming languages —
just Docker, then one command. Plan on ~20 GB of free disk and leaving the computer on for the
first run.

**Step 1 — install Docker.**

- **Windows / Mac:** install [Docker Desktop](https://www.docker.com/products/docker-desktop/),
  open it, and wait until it says **Engine running**.
- **Linux / Raspberry Pi:** `curl -fsSL https://get.docker.com | sh`, then
  `sudo usermod -aG docker $USER` and log out and back in.

**Step 2 — run the start command for your system.** It downloads the app, starts it, and opens
the setup page when it's ready. The first run builds everything — give it 10–30 minutes; later
starts take seconds.

**Windows 10 / 11** — open PowerShell (press **Start**, type `PowerShell`, press **Enter**) and paste:

```powershell
irm https://raw.githubusercontent.com/CosmopolitanCoalition/fair-constitution-app/main/get-started.ps1 | iex
```

**macOS / Linux / Raspberry Pi** — open Terminal and paste:

```bash
curl -fsSL https://raw.githubusercontent.com/CosmopolitanCoalition/fair-constitution-app/main/get-started.sh | bash
```

*Already downloaded the code yourself (ZIP or `git clone`)? Run the same script from inside
that folder — `.\get-started.ps1` on Windows, `./get-started.sh` elsewhere — and it uses your
copy instead of downloading.*

**Step 3 — answer the questions in your browser.** The app comes up at
**<http://localhost:8080/setup>** and the setup wizard carries you the rest of the way:

1. **Create the world's database and your founder account.** The first page detects the empty
   database, applies the schema, and makes you this world's operator.
2. **Start a new world — or join an existing one?** Joining replicates an existing game onto
   this computer: enter the host's address and approve from there. Starting new continues below.
3. **Cosmic address** — which place this world is for: a neighborhood, a city, a country… up to
   the whole planet.
4. **Constitutional defaults** — the rules of the world. Template values are a starting
   reference, not locks; the founder can change them.
5. **Map data** — load real geography and population (see [Map data](#map-data) below).
6. **Districts and institutions** — district maps are drawn and the civic institutions are
   seated. You're in, signed in as the founder, with a live world.

**Didn't open?** Make sure Docker Desktop is running, give a first run a few more minutes (it's
still building), then reload the page.

## Map data

The world is built from real datasets: **geoBoundaries** (jurisdiction boundaries) and
**WorldPop** (population). Download them by following
[docs/DATA_ACQUISITION.md](docs/DATA_ACQUISITION.md).

**The start command asks where they are.** On the first run, the get-started script asks for
your map-data folder and points the app at it before the containers are built — so it's ready
from the moment the app comes up, with no extra steps. Already have your files at, say,
`D:\map-files`? Just type that when prompted. Don't have them yet? Leave it blank and download
them later from inside the app. To change the folder afterward, re-run the start script with
`-Reconfigure` (Windows) / `--reconfigure` (macOS/Linux) — it re-points the app and restarts it
for you, no Docker commands needed. (You can also preseed it non-interactively by setting
`CGA_ARCHIVE_PATH` before running.)

Inside the app, the **Map data** step shows what it detected, lets you scope the import to a
single country for a fast first world, or download the datasets over the internet country by
country. The full planet is ~14 GB of source data and a 6–12 hour import.

## Advanced

For people running servers or contributing code — none of this is needed for a normal install:

- **Scripted / shareable deploys** — [`deploy.sh`](deploy.sh) (Linux · macOS) /
  [`deploy.ps1`](deploy.ps1) (Windows, PowerShell 7): headless one-command node deploy, with
  flags for custom ports, second instances on one machine, and joining as a mirror in one step.
  `./deploy.sh --help` lists everything.
- **Joining across machines** — the operator runbook for a UI-driven join over your network:
  [docs/operator/multibox-ui-run.md](docs/operator/multibox-ui-run.md). One `.env` value
  (`FEDERATION_SELF_URL`) must be set before bring-up so peers can reach you back.
- **The survival mesh** — redundant secure transports between instances (private tailnet, Tor,
  Yggdrasil): [bootstrap/README.md](bootstrap/README.md).
- **Technical and contributor documentation** — [docs/](docs/), including the ETL pipeline
  reference at [scripts/etl/README.md](scripts/etl/README.md).

## License

Open source — see [LICENSE](LICENSE). Constitutional intellectual property is always public
domain per Article III §5 of A Fair Constitution.
