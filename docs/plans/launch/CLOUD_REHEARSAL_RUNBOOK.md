# Cloud bring-up rehearsal — dry checklist

*Lane 2, Wave 3 (2026-07-29). **PREP, not execution.** This is the instrument the
operator runs when the cloud box is provisioned — a fresh-clone bring-up rehearsed
end to end, with every post-deploy assertion written down so a miss is visible. It
does **not** provision anything and no cloud resource is created by reading it.*

The public-facing steps (machine, DNS, ports, the one command, the browser wizard)
live in [`docs/FRESH-NODE-START-CLOUD.md`](../../FRESH-NODE-START-CLOUD.md) and are
unchanged. This runbook is the **rehearsal layer on top**: what to verify, in what
order, with the exact command and the exact expected answer — plus the wizard-walk
capture that rides the real bring-up.

---

## What changed since the cloud guide was written (the wave delta to verify)

Everything below already ships in `main`; the rehearsal confirms it landed clean on
a fresh public box. Nothing here changes the one command.

| Change | Where | What the rehearsal checks |
|---|---|---|
| **New migration chain** | `database/migrations/2026_07_29_*` | `migrate` applies the whole wave on top of the flattened baseline with no error: `130000` support-report lifecycle (lane 6), `140000` adoption-declaration columns (lane 2), `150000` Type B district grouping (lane 1), `190000` education engine (lane 15). **`200000` demo-mesh time coordination (lane 2) lands only after its slot signal** — see the note at the end. |
| **Form count → 115** | `FormRegistry`, pinned in `tests/Feature/AuditChainSmokeTest.php` | the registry holds **exactly 115** canonical forms; a fresh box reporting a different number means forms are missing or the pin drifted. |
| **Headless founding** | `setup:found` | the browser `/setup` has a scriptable twin — an automated rehearsal can found the world without a browser (flags below). The **operator walk still uses the browser** so the wizard is captured. |
| **Fork-first setup** | setup wizard | the join-or-start-fresh fork is meant to come **before** account creation (ruling 2026-07-05). ⚠ The v3 mockup shows account-first — an **open operator item**, confirmed by observation during the walk, not resolved here. |
| **Clean-launch gate** | `launch:assert-clean` | the public node carries **no synthetic data and no dev controls** — this is the go/no-go gate, run below. Dev-time controls (including the Wave-3 demo-mesh time coordinator) are **off on a production node by construction**; assert-clean proves it. |

---

## Part A — pre-flight (before the one command)

Tick each before touching `deploy.sh`. These are the misses that cost an hour later.

- [ ] **Hostname decided and final.** `<HOST>` is a real DNS name you control. The Matrix
      server name and federation URL derive from it and **can never change on this box**.
- [ ] **DNS resolves from outside the box.** `dig +short <HOST>`, `dig +short auth.<HOST>`,
      `dig +short rtc.<HOST>` all return the VM's public IP. Certificate issuance looks the
      name up from the internet — it must already resolve.
- [ ] **Ports open:** 80/tcp, 443/tcp, 8448/tcp, 7881/tcp, **7882/udp** (UDP, the common
      miss). Everything else closed; the DB, homeserver and login service bind loopback only.
- [ ] **Data disk mounted** at `/var/lib/docker` (world data on the big, snapshottable disk).
- [ ] **Clock is correct** (`timedatectl`) — signed handshakes and cert issuance are
      time-sensitive.

---

## Part B — the one command

```bash
git clone --depth 1 https://github.com/CosmopolitanCoalition/fair-constitution-app.git \
  && cd fair-constitution-app \
  && ./deploy.sh --public-url https://<HOST> --with-etl
```

- Drop `--with-etl` if this node will **join** an existing world (it becomes a mirror — see
  the note below on which node people actually use).
- **`--seed` is refused with `--public-url`**, and dev clock/role controls are refused on a
  public node — both by design (`deploy.sh` guards). Do not try to combine them.
- **Safe to re-run.** `key:generate` is conditional now, so re-running for an update keeps
  the Ed25519 federation identity it already minted.

**Scripted alternative (automated rehearsal only — the operator walk uses the browser):**

```bash
docker compose exec app php artisan setup:found \
  --name="<Founder>" --email="<you@example.org>" \
  --game-mode=production --instance-name="<World name>" --no-interaction
# password omitted → prompted hidden (argv is visible in process lists)
```

---

## Part C — the dry checklist (post-deploy assertions)

Run each on the box after `deploy.sh` finishes. The **expected** column is the pass; a
red failure is a stop.

| # | Command | Expected |
|---|---|---|
| C1 | `docker compose ps` | every service **Up**: `app`, `nginx`, `postgres`, `redis`, `horizon`, `scheduler`, `matrix`, `mas`, `livekit`, `edge` (the public TLS layer), `etl` if `--with-etl`. `vite` is dev-only and not expected on a public box |
| C2 | `curl -sSI https://<HOST>/ \| head -1` | `HTTP/2 200` (or a 302 to `/setup`) — a **real** certificate, no TLS warning |
| C3 | `docker compose exec app php artisan migrate:status \| grep -c Ran` | all wave migrations show **Ran**; none Pending. Spot-check `2026_07_29_150000` and `190000` are present |
| C4 | `docker compose exec app php artisan tinker --execute="echo \App\Models\Clock::count();"` | **21** (CLK-01…21 — `ClockRegistrySeeder` ran; without it `federation:init` throws) |
| C5 | `docker compose exec app php artisan launch:assert-clean` | **passes** — no synthetic data, no dev controls, `--allow-users` default 1 (the founder). This is the **go/no-go gate** |
| C6 | `docker compose exec app php artisan test --filter=AuditChainSmokeTest` | green — the FormRegistry holds **exactly 115** forms and the audit chain verifies |
| C7 | `docker compose exec app php artisan mesh:gates` | **"Node is ready to federate."** Amber warnings normal on a fresh node; red is not |
| C8 | `docker compose exec app php artisan mesh:doctor` | prints this node's server_id — federation identity minted |
| C9 | `docker compose logs edge \| tail` | certificate obtained; no ACME loop. (Only needed if C2 failed) |

**If C5 fails**, do not open the node to the public — read what it names (a demo command
ran, a dev flag is set, synthetic rows exist) and clear it first.

---

## Part D — the operator-wizard E2E walk (capture rides the real bring-up)

Open **`https://<HOST>/setup`** and walk it as a first-time founder. Fill this table
*during* the walk — it is the capture the desk asked for; screenshots go to the operator
review pile (the in-app pane does not composite yet — that is the standing fleet pixel
debt, not a walk failure).

| Step | What the screen should show | Entered / observed | Shot | ✓/✗ |
|---|---|---|---|---|
| 0 · landing | Founder account **or** the join/fresh fork first — **note which comes first** (open item) | | | |
| 1 · account | create founder + operator login | | | |
| 2 · fork | **Join an existing world** vs **Start fresh** — pick fresh for the public front door | | | |
| 3 · cosmic address | pick where in the address tree this world sits | | | |
| 4 · constitution | defaults shown; values editable (unlocked) | | | |
| 5 · map data | accept map data (or import) | | | |
| 6 · districts | apportionment runs, districts confirmed | | | |
| 7 · institutions | scaffolding created, first election scheduled | | | |
| done | land on the world; founder is the one real user | | | |

**Two things to confirm by observation, not assumption:**

1. **Fork order** — does the wizard put the join/fresh choice before or after account
   creation? Code is fork-first (2026-07-05); the mockup is account-first. Record what the
   built wizard does; the reorder is the operator's call, not the rehearsal's.
2. **Empty second chamber** — a fresh world *declares* Type B seats but only an election
   *fills* them, so bicameral acts correctly refuse until the first election seats members.
   That reads as "broken" to a new operator; the walk should note the refusal is expected
   and the message points at holding an election.

---

## Part E — multibox (only if the rehearsal includes a second node)

A node that **joins** another world is a read-only **mirror**: it serves the record but is
authoritative for nothing and has **no user accounts** — people sign in on the node that
*started* the world. So the public, real-multiplayer front door must be the **fresh**
(sovereign) node; a home box joins it, never the reverse.

```bash
docker compose exec app php artisan federation:peer:discover  https://<FIRST-HOST>
docker compose exec app php artisan federation:peer:handshake https://<FIRST-HOST>
docker compose exec app php artisan mesh:doctor               https://<FIRST-HOST>
```

Both nodes must run the same code version — `mesh:doctor` says so if they do not.

**Demo-mesh time coordination does not appear here.** It is a *sandbox* capability (behind
`DevTimeControlsEnabled`); on a production public node it is inert, and C5 proves it. It is
exercised only in a demo-mode multibox playtest, where the coordinator advances time and
declared-demo followers replay it — see
[`DEMO_MESH_TIME_COORDINATION.md`](DEMO_MESH_TIME_COORDINATION.md).

---

## Part F — if you brought it up wrong

- **Wrong hostname** — the Matrix domain cannot change. Start over on a clean box, or
  `docker compose down -v` (erases everything) and re-run Part B.
- **Update / partial failure** — just re-run Part B; the keys survive.
- **Voice silent** — 7882 must be open for **UDP**.

---

## The one held item this rehearsal waits on

The **`2026_07_29_200000_demo_mesh_time_coordination`** migration (lane 2) is written but
**not yet landed** — it sits third in the wave-3 slot behind lane 1 (`150000`, landed) and
lane 15 (`190000`, landed), and lands on the desk's signal. Its **code already ships** and
degrades to solo without the columns, so a rehearsal today is unaffected: `migrate` (C3)
applies whatever migrations are committed at rehearsal time, and C5/C6 pass either way.
Once the slot opens and the migration lands, re-run C3 and confirm `200000` shows **Ran**.
