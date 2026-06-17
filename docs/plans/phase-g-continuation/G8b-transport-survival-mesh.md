I have full grounding now. The `/api/federation/identity` advertises a single `url`, not the transport list — that's a key gap the multiplex design must close. I have enough to write the design.

---

# Design: Multi-Transport "Survival Mesh" + Universal Bootstrap + Nearest-Node Routing

**Design round only — no code written or edited.** Every claim below is grounded in files I read this session; paths/symbols are cited inline.

## 0. What exists today (verified)

- **G8 transport seam.** `FederationClient::proxyFor()` (FederationClient.php:53–66) is the *only* transport decision point: a `.onion` host dials `config('cga.federation_socks_proxy')`, everything else uses an optional global `config('cga.federation_proxy')`, else direct. Config keys are `federation_socks_proxy` / `federation_proxy` (config/cga.php:115–116, both `null` by default). `FederationClient::send()` (line 68) signs `METHOD\nREQUEST_TARGET\nTIMESTAMP\nsha256(body)` and travels identically over any channel — the "same signed bytes" property the survival mesh depends on.
- **Transport registry.** `federation_transports` (migration 2026_09_05_000003) — `(server_id, transport, address, is_self, priority, enabled)`, CHECK `transport IN ('https','tailnet','onion','sneakernet')`, unique on `(server_id, transport)`. `TransportService` (TransportService.php) is "pure registry — moves no bytes": `registerSelf()`, `selfEndpoints()`, `forServer($serverId)` all return `[{transport,url}]` ordered by `priority DESC`. `FederationTransport::TRANSPORTS` is the hard allowlist (model line 17).
- **G9 directory.** `directory_entries` (migration 2026_09_05_000002) + `DirectoryService` — signed, advisory `jurisdiction → [{transport,url}]`. `publish()`, `ingest()` (verifies against the *named* server's pinned key, not the relayer's), `resolve($jurisdictionId)` returns endpoints `priority DESC, published_at DESC`, dropping `isExpired()`. Explicitly **powerless**: never decides authority (that's `AuthorityResolver`, reading only `jurisdictions.authoritative_server_id`, AuthorityResolver.php:44).
- **The dialing gap.** `WriteRouterService::forward()` (WriteRouterService.php:91–97) and `PeerService::discover/initiateHandshake` (PeerService.php:45,89) dial a **single `$peer->url`** (FederationPeer.php has one `url` column). They do **not** consult `TransportService::forServer()` or the directory's endpoint list. `/api/federation/identity` (PeerController.php:24–29) advertises one `url`. So the multi-transport tables exist but **nothing fails over across transports yet** — that is the core of deliverable (1).
- **Tailnet substrate.** `docker-compose.headscale.yml` + `PHASE_G_V2_TAILNET_RUNBOOK.md` already wire a Headscale control plane fronted by Cloudflare Tunnel; peers reach each other on `100.64.x.x` WireGuard IPs with no inbound netgate ports. G-V2 is CERTIFIED zero-touch (per memory).
- **Bootstrap.** `deploy.sh` (bash) and `deploy.ps1` (pwsh) are near-identical: write `.env`, arch-detect PostGIS image (deploy.sh:80, **deploy.ps1 lacks this**), bring up the stack, `key:generate`/`migrate`/seed clocks, optional `federation:init --rotate` + `cluster:join`. **Neither installs/configures any transport** (no Tor, no Tailscale) and **neither is interactive** — both are flag-driven. No macOS variant exists.

---

## 1. The Multiplex Layer — "if one survives, all survive"

**Principle.** A *peer* is an identity (`server_id` + pinned Ed25519 key), reachable over a **set** of transports. Today the code conflates "peer" with "one URL." The multiplex makes the peer the unit and the transport set the fallback ladder. Because `FederationClient::send()` already signs transport-independent bytes, failover is purely a question of *which base URL to retry over* — no protocol change, no re-sign.

### 1a. Add YGGDRASIL as a fifth transport

Yggdrasil is an end-to-end-encrypted IPv6 overlay (each node gets a stable `200::/7` address derived from its public key) that self-routes over any peer link — ideal for the "no DNS, no static IP, censored uplink" survival case, complementary to tailnet (needs a coordinator) and onion (high latency).

- **Schema (additive, no edit to protected migrations — honors roadmap §7 "additive-only").** New migration widening the CHECK using the established drop-and-re-add technique (same pattern as `federation_transports_transport_check`, migration line 37–40): `transport IN ('https','tailnet','onion','sneakernet','yggdrasil')`. Add `'yggdrasil'` to `FederationTransport::TRANSPORTS` (model line 17) and the migration comment `https | tailnet | onion | sneakernet | yggdrasil`. The `DirectoryEntry` jsonb endpoints need no migration (free-form `{transport,url}`).
- **Dialing.** Yggdrasil addresses are routable IPv6 (`[200:...]:8080`) reachable directly once the local `yggdrasil` daemon is up — no SOCKS proxy. So `proxyFor()` returns `null` for it (like tailnet). The *only* `proxyFor()` change is that onion stays SOCKS; everything else direct — unchanged.

### 1b. The multiplex client (new `MultiplexClient`, wrapping `FederationClient`)

A new service `App\Services\Federation\MultiplexClient` that **does not** replace `FederationClient` (the protected signing seam) — it sits above it and decides *which base URL* to hand it:

```
MultiplexClient::reach(serverId, method, path, payload):
  candidates = TransportEndpoints::forPeer(serverId)        # ordered ladder, see below
  for (transport, baseUrl) in candidates:
     if not transportLocallyAvailable(transport): continue  # e.g. no Tor daemon → skip onion
     try:
        resp = FederationClient::{get|post}(baseUrl, path, payload)   # SAME signed bytes
        if resp.ok or resp is an authoritative refusal (4xx that is a real answer):
           markHealthy(serverId, transport); return resp
     catch transport/connect/timeout error:
        markDown(serverId, transport); continue              # try the next survivor
  throw NoSurvivingTransport(serverId)
```

**The endpoint ladder** (`TransportEndpoints::forPeer`) is the union of three sources, deduped by `(transport,url)`, then sorted:

1. Peer's learned transports: `TransportService::forServer($serverId)` (already returns priority-ordered `[{transport,url}]`).
2. The directory's advisory endpoints for jurisdictions that peer serves: `DirectoryService::resolve()`.
3. The legacy `$peer->url` (back-compat — wrapped as `{transport: inferFromHost(url), url}`; `.onion`→onion, `200:`/`300:`→yggdrasil, `100.64.`→tailnet, else https).

**Sort key** = `(censorship_floor_first?, health_score DESC, priority DESC, latency_ema ASC)`. `censorship_floor_first` is a per-instance setting (see §4): in a censored posture, onion/yggdrasil sort *above* https so a blocked clearnet endpoint is never even tried first.

### 1c. Health & circuit-breaking (the "survives" bookkeeping)

A new tiny table `federation_transport_health` (additive) keyed `(server_id, transport)`: `last_ok_at`, `last_fail_at`, `consecutive_failures`, `latency_ema_ms`, `circuit_state(closed|open|half_open)`. `markDown` opens the circuit after N consecutive failures (config `federation_transport_failure_threshold`, default 3); `half_open` retries one probe after a cooldown. This is operational state, **not** constitutional — explicitly outside the CLK registry (mirrors how `federation_transport_health` would parallel the heartbeat `last_heartbeat_at` already on `FederationPeer`). A CLK-20 heartbeat tick (config `federation_heartbeat_minutes`, default 5, config/cga.php:95) opportunistically probes `open` circuits via a cheap `GET /api/federation/identity` over each transport to re-learn reachability — this is where "all survive" is actively maintained, not just discovered on demand.

### 1d. Advertise the full transport set

`/api/federation/identity` (PeerController.php:24) must publish `transports: TransportService::selfEndpoints()` alongside `url`, and the handshake (PeerService.php:128 `receiveHandshake`) must persist a peer's advertised transports via `TransportService` (today it discards them). This closes the loop: discovery learns *all* of a peer's channels at handshake, so the ladder is populated before the first clearnet failure — the precondition for "if one survives, all survive."

### 1e. Wiring the existing callers (the only behavior change)

- `WriteRouterService::forward()` (line 97): replace `$this->client->post($peer->url, …)` with `$this->multiplex->reach($serverId, 'POST', '/api/federation/write', …)`.
- `PeerService::initiateHandshake` / `FederationSyncService` / `ColdSyncService`: same swap. Discovery (`PeerService::discover`, line 45) stays single-URL (you bootstrap a peer from *one* known address, then learn the rest).
- Back-compat: with only an https transport row (or just `$peer->url`), the ladder has one rung and behavior is byte-identical to today. **No protected file is edited** — `FederationClient` is untouched; `MultiplexClient` is a new collaborator injected where `FederationClient` is today.

---

## 2. Universal Cross-Platform Bootstrap + Interactive Mesh-Pick

**Goal (from the prompt):** one downloadable-from-the-website bootstrap that (a) installs+configures the chosen transports, (b) walks an interactive pick of which meshes the operator wants/can support, (c) brings up the app layer. Three thin OS front-ends over **one shared spec**, so the three never drift (the same drift lesson as `languages.py` generating both PHP and JS locale registries, per roadmap Phase N).

### 2a. Shared spec: `bootstrap/mesh-catalog.json` (new, in-repo, the single source of truth)

A declarative catalog the three scripts read — never hardcode transport facts in three places:

```jsonc
{
  "transports": {
    "https":      { "label": "Clearnet HTTPS", "needs": [], "censorship": "low",
                    "self_advert": "https://<host>:<port>" },
    "tailnet":    { "label": "Private tailnet (Headscale/Tailscale)",
                    "install": { "linux": "tailscale.install.sh", "macos": "brew tailscale", "windows": "winget Tailscale.Tailscale" },
                    "configure": "tailscale up --login-server <hs> --authkey <key>",
                    "self_advert": "http://<tailnet-ip>:<port>", "censorship": "medium" },
    "onion":      { "label": "Tor hidden service (censorship-resistant)",
                    "install": { "linux": "apt/tor", "macos": "brew tor", "windows": "winget Tor" },
                    "configure": "write torrc HiddenServiceDir → onion hostname; set CGA_FEDERATION_SOCKS_PROXY=socks5h://127.0.0.1:9050",
                    "self_advert": "http://<onion>.onion", "censorship": "high" },
    "yggdrasil":  { "label": "Yggdrasil overlay (self-routing IPv6)",
                    "install": { "linux": "apt/yggdrasil", "macos": "brew yggdrasil", "windows": "winget Yggdrasil" },
                    "configure": "generate config, peer with public peers list, read [200::]/7 address",
                    "self_advert": "http://[<ygg-ip>]:<port>", "censorship": "high" },
    "sneakernet": { "label": "Offline bundle (USB/air-gap export-import)",
                    "needs": [], "self_advert": null, "censorship": "max" }
  },
  "recommend": {
    "volunteer-home":      ["tailnet", "https"],
    "censored-region":     ["onion", "yggdrasil"],
    "air-gapped":          ["sneakernet"],
    "public-anchor-node":  ["https", "tailnet", "onion", "yggdrasil"]
  }
}
```

### 2b. The three OS front-ends (thin; same prompts, OS-specific installers)

- **`bootstrap.sh`** (Linux) and **`bootstrap.command`/`bootstrap.sh`** (macOS) — one POSIX `sh` script branching on `uname` (`Linux`→apt/dnf, `Darwin`→Homebrew). macOS finally gets first-class support (today neither deploy script handles Darwin beyond the arm64 PostGIS line in deploy.sh:80, which deploy.ps1 is *missing*).
- **`bootstrap.ps1`** (Windows) — uses `winget` for installers; reuses `deploy.ps1`'s `Set-EnvVar` helper (deploy.ps1:47).
- All three are **wrappers that converge on the existing `deploy.{sh,ps1}`** for the app layer — they do **not** reimplement the stack bring-up. The split of responsibility: `bootstrap.*` = "install transports + interactive pick + write transport `.env` + register `federation_transports`"; `deploy.*` = "bring the app up" (unchanged contract, already hardened across 5 rounds per memory).

### 2c. Interactive flow (identical wording on all three OSes; non-interactive `--profile` flag for CI/headless)

```
CGA Survival-Mesh Setup
1. What is this node?  [a] volunteer mirror  [b] my jurisdiction's server  [c] public anchor
2. Where are you?      [a] open internet  [b] censored/monitored network  [c] air-gapped
   → preselects a recommend[] profile; operator can toggle individual meshes.
3. For each chosen mesh, capability probe + install:
     tailnet:    "Headscale login server URL?  Pre-auth key?"   → install client, `tailscale up`
     onion:      install Tor, generate hidden service, capture <onion>.onion, set SOCKS env
     yggdrasil:  install, peer with public peers, read [200::] address
     https:      "Public hostname/port? (blank = LAN only)"
     sneakernet: "Export bundles to which directory?"
4. Confirm matrix → write .env (CGA_FEDERATION_SOCKS_PROXY etc.) + queue `transport:register` calls.
5. Hand off to deploy.{sh,ps1} (existing) → app comes up.
6. Post-up: for each chosen transport, `php artisan transport:register <transport> <self-address>`
   (new thin command wrapping TransportService::registerSelf — currently only callable in tinker).
   Then `directory:publish` this node's served jurisdictions with the full endpoint set.
```

A **capability probe** before each install ("is Docker present? is there an outbound 443? is a coordinator reachable?") gracefully degrades — e.g. if clearnet 443 is blocked, it auto-suggests onion+yggdrasil and de-prioritizes https. This is the bootstrap analog of deploy.sh's PostgreSQL/composer wait-loops (deploy.sh:97–115): probe-then-act, never assume.

### 2d. New artisan glue (so the bootstrap can drive the registry without tinker)

- `transport:register {transport} {address} {--priority=}` → `TransportService::registerSelf()`.
- `transport:list` / `transport:disable {transport}` → registry ops.
- `directory:publish {jurisdiction?}` → `DirectoryService::publish()` with `TransportService::selfEndpoints()`.

These are CLI surfaces for services that today have **no command entry point** (verified: `TransportService` is only referenced from other services, no console command exists for it).

### 2e. Website-downloadable

The website hosts the three files + a SHA-256 manifest + an Ed25519 detached signature (so a volunteer can verify the bootstrap before running it — the same self-authenticating-artifact discipline as G9 directory entries). A one-liner per OS (`curl … | sh` is discouraged for a governance tool; instead "download, verify signature, run") with verification steps shown on the page.

---

## 3. Nearest-Node Routing (traveling client / website visitor → nearest mesh node)

**Two distinct cases, two different answers — and the privacy rail differs sharply.**

### 3a. Server-to-server route selection (already mostly there)

For a *forwarding instance* choosing which endpoint of an authoritative peer to use, "nearest" = "lowest-latency surviving transport," which the §1c `latency_ema` health metric already gives. No geography needed — the multiplex ladder *is* the routing. This is the dominant federation path.

### 3b. A human (traveling browser / volunteer's device) → nearest serving node

The directory's `resolve()` returns endpoints by `priority, freshness` (DirectoryService.php:114) — **not** by distance. The design adds a **geo-routing facade** that never moves authority and never logs a location:

- **The signal.** A jurisdiction already has PostGIS `geom` (CLAUDE.md schema; the codebase uses `ST_Distance` in DistrictingService etc., verified via grep). A node serving jurisdiction J has an implicit location = J's centroid. So "nearest node to point P" = the directory entry whose served-jurisdiction centroid is closest to P — computable **entirely server-side** with `ST_Distance(jurisdictions.geom, :p)` over the `directory_entries.jurisdiction_id` set, ordered ascending.
- **The endpoint.** `GET /api/mesh/nearest` (new, public, **no auth, no body persisted**). Input options, privacy-tiered:
  1. **Coarsest (default): no coordinates at all.** The visitor optionally picks their jurisdiction from a list, or the server uses the *country-level* GeoIP of the request (resolved in-memory, never stored) → returns the directory's best endpoints for that jurisdiction's serving node, sorted by transport health. This is enough for "which mesh node should this browser talk to."
  2. **Opt-in fine:** the browser may send a *rounded* lat/long (snapped to a coarse grid, e.g. ~10 km, before it leaves the device) → server returns nearest serving nodes. The server **rounds again** server-side and **never writes the coordinate** to any table (no `location_pings` row — that pipeline is the residency sensor, a different, private-local subsystem per roadmap §7; geo-routing must not touch it).
- **Output.** A ranked list of `{server_id, transport, url}` — the same endpoint shape G9 already speaks — that the client/CDN edge uses to pick its mesh entry point. For a censored visitor the facade respects the same `censorship_floor_first` toggle and returns onion/yggdrasil endpoints first.

### 3c. Why this stays constitutional

- **Routing ≠ authority.** Exactly as `DirectoryService`'s own docblock states ("a stale or hostile entry can at worst send a write to the wrong endpoint — where it is rejected, because authority is checked there" via `AuthorityResolver`). Nearest-node is a *hint*; the receiving node still re-checks authority. A malicious "I'm nearest" claim routes you to a node that simply forwards/rejects per `WriteRouterService` — no trust is conferred by being "near."
- **Geo-routing is advisory and additive** — it reads `jurisdictions.geom` (existing) + `directory_entries` (existing) and adds one read-only endpoint + one optional coarse-grid helper. No new authority surface.

---

## 4. Security / Privacy

| Concern | Design |
|---|---|
| **Censorship resistance** | onion + yggdrasil are first-class transports; the bootstrap's "censored network" profile preselects them; `censorship_floor_first` per-instance setting makes the multiplex ladder try them *first*, so a blocked clearnet endpoint is never the visible first hop. onion keeps the SOCKS seam (`proxyFor`, FederationClient.php:57). |
| **Never leak a user's location** | Geo-routing defaults to **no coordinates** (jurisdiction-pick or coarse country GeoIP, in-memory only). Opt-in fine location is **rounded on-device** then **rounded again server-side**, and **never persisted** — explicitly *not* a `location_pings` write. `/api/mesh/nearest` logs no PII; rate-limited (R-08-style, like Phase H's `POST /population-probe`). The residency GPS pipeline (`location_pings` → ancestor-sweep → `residency_confirmations`) is the *private-local, never-federated* triad (roadmap §7) and stays completely separate from routing. |
| **Same signed bytes, any channel** | The multiplex changes only the base URL; `FederationClient::send()` (the protected signing seam) is untouched, so a transport swap can never alter or forge a federation message. A directory entry is signed by the *named* server and verified against its pinned key on ingest (`DirectoryService::ingest`, line 88) — a relay/CDN cannot forge endpoints. |
| **Bootstrap supply-chain** | The three downloadable scripts ship with a SHA-256 manifest + Ed25519 signature; verification steps shown on the website before run. No `curl\|sh`. |
| **Transport metadata leakage** | The health table stores only `(server_id, transport)` latency/failure counts — no user data. The directory is *advisory* and already designed to be relayed publicly. Onion/yggdrasil addresses are themselves the node's pseudonymous identity, leaking no host geography. |
| **No new immutable rule** | All of this is **flexible-layer [POLICY]** (roadmap §2.1): transport choices, the censorship-floor toggle, geo-routing coarseness are operator/instance settings in `constitutional_settings`-style config — none promoted to the hardened layer. |

---

## (a) Roadmap slot

This is a **Phase G, Track C completion** item — specifically the **G8 (transport seam) → G9 (directory)** continuation, the `transport seam (tailnet/Tor/sneakernet) → directory → mobile (G10)` line in roadmap §3 (Track C). The work here is **"G8b — multiplex/Yggdrasil + universal bootstrap + nearest-node routing"**, sitting *between* the existing G9 directory and the parked **G10 (mobile)**. It is **not** a new H–O phase: it extends the already-merged G mesh (additive tables, no protected-file edits), and it directly serves the parked **G-V2** (cross-machine onboarding now over multiple survivable transports) and **G-V1** (a traveling phone using nearest-node routing). It precedes G10/Capacitor, which would consume `/api/mesh/nearest`.

## (b) Open decisions for the operator

1. **Yggdrasil public-peers policy** — ship a curated default public-peer list in `mesh-catalog.json`, or require the operator to supply one? (Default-on eases volunteers but creates a maintained dependency.)
2. **Geo-routing fine-location** — allow the opt-in rounded-coordinate path at all, or jurisdiction-pick / country-GeoIP **only**? (Strictest privacy = never accept a coordinate, ever.)
3. **GeoIP source** — bundle a local MaxMind-style DB (offline, no third-party call) vs. a hosted lookup (a network leak)? Offline is the privacy-correct answer but adds a data file + license.
4. **Bootstrap installer trust** — `winget`/`brew`/`apt` (distro-trusted, but version-drifts) vs. pinned vendor installers (reproducible, but you maintain checksums)?
5. **macOS deploy parity** — fold Darwin support into the existing `deploy.{sh,ps1}` (deploy.ps1 currently lacks even the arm64 PostGIS branch deploy.sh:80 has), or keep macOS only in the new `bootstrap.*` layer?
6. **Does a node advertise yggdrasil/onion in the *public* directory** that website visitors can read, or only to handshaked peers? (Public = easier discovery; peer-only = less attack surface for a censor mapping the mesh.)
7. **`censorship_floor_first`** as a global instance posture vs. per-peer/per-request — the simplest is a single instance setting; per-request is more flexible but bigger.

## (c) Risks

- **`/api/mesh/nearest` as a deanonymization oracle** — even coarse GeoIP + "which jurisdiction" can fingerprint a censored user *if logged*. Mitigation is strict no-persistence + rate-limiting, but the endpoint's mere existence is an attack surface; an adversary could probe it to enumerate which jurisdictions a node serves. Needs an adversarial review pin (like G-V2's 10-agent cert).
- **CHECK-constraint widening** for `yggdrasil` is a drop-and-re-add on a live table — must follow the established additive technique (migration 2026_09_05_000003 pattern) and stay reversible; a botched widening would block all transport inserts.
- **Multiplex masking real outages** — "if one survives, all survive" can hide that the *preferred* (fast/cheap) transport is down because a slow onion path silently succeeds, degrading performance invisibly. The health table + a heartbeat-surfaced "degraded transport" signal must be observable, or operators won't know clearnet is blocked.
- **Bootstrap installing system daemons (Tor/Yggdrasil/Tailscale)** crosses from "Docker-only app" to "modifies the host OS" — raises the support/blast-radius bar enormously across three OSes and the same native-arm64/Linux-fault class that produced *six* deploy blockers (memory). This **cannot** be certified on amd64/Docker-Desktop alone; it needs the physical rig — i.e. it inherits G-V2's verification-gap lessons and is **parked-pending-rig** for real-world certification, exactly like G-V1/G-V2.
- **Yggdrasil maturity** — smaller ecosystem than Tor/WireGuard; the public-peer mesh is volunteer-run and can be unreliable. Treat it as a *fallback survivor*, never the primary transport.
- **Directory poisoning of nearest-node** — a hostile relayed directory entry claiming "nearest" routes humans to an attacker node. It can't forge authority (re-checked downstream) but can do **traffic analysis / denial**. The signature-on-ingest (DirectoryService.php:88) protects integrity of the *named* server's entry, but a *valid* entry from a hostile-but-pinned peer is still trusted — peer-trust hygiene is load-bearing.

**Files read this session (all absolute):** `app/Services/Federation/{FederationClient,TransportService,DirectoryService,WriteRouterService,PeerService,AuthorityResolver}.php`, `app/Models/{FederationTransport,DirectoryEntry,FederationPeer}.php`, `database/migrations/2026_09_05_000002_create_directory_entries_table.php`, `database/migrations/2026_09_05_000003_create_federation_transports_table.php`, `app/Http/Controllers/Federation/PeerController.php`, `config/cga.php`, `deploy.sh`, `deploy.ps1`, `docker-compose.headscale.yml`, `docs/plans/institutions/PHASE_G_V2_TAILNET_RUNBOOK.md`, `docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md`, `docs/extracted/roles_forms_chart.md`.