# Azure Bring-Up Plan — GitHub to an online node

**Lane 2 (Cloud Launch — Multibox) · authored 2026-07-25 · repo state `ea19d8d`**
**Target: 2026-09-01 (L-0). Today is L-38.**

> Line references are as of `ea19d8d`. Re-check before editing any cited file — several
> are moving under other lanes.

---

## §1 What launch is

A working, internationally accessible, real-multiplayer instance at
**`earth.worldofstatecraft.org`** — the earth.\* **"Standard"** instance:

- **Real consent only. Zero synthetic data.** No demo voters, no seeded elections, no
  manufactured residency.
- **Institutions dormant by design.** `apportionment:seed` creates legislatures at
  `status = 'forming'`; nothing seats until a real election certifies.
- **All ten services, plus voice.** Operator ruling 2026-07-25: everything is in scope —
  app, nginx, postgres, redis, horizon, scheduler, Synapse, MAS, ETL, and LiveKit for
  voice/video. Multibox joining was proven on the Pi; single-box Matrix video over the
  internet was proven separately. The remaining unknown is *environment*, not protocol.
- **Not Phase O.** The Attained full-scale demo is a separate instance and a separate phase
  (lane 4). Anyone writing outward-facing copy: see the framing note in §14.

Why this hostname: `config/cga.php:257` already ships `https://worldofstatecraft.org` as the
federation bootstrap front door, so a volunteer node with no configuration at all discovers
this instance. The rig's existing wildcard cert already names the domain.

**Permanently locked at first boot** — wrong here is unrecoverable, so §5 treats these as
gates: `MATRIX_DOMAIN` (Synapse `server_name`, pinned by peers at S2S),
`FEDERATION_SELF_URL` (pinned by peers), `APP_URL` (the OIDC issuer MAS pins), and `APP_KEY`
(everything encrypted at rest derives from it).

---

## §2 The principle: a cloud node is a volunteer node

> **Operator ruling, 2026-07-25:** *"The cloud nodes should be treated no different in terms
> of their setup than any other volunteer node."* The join-or-start-fresh choice is made in
> the setup bootstrap, exactly as on bare metal or a home VM.

This is the organising idea of the whole plan, and it has teeth:

1. **There is no bespoke cloud seeding path.** The node runs the same wizard everyone runs.
2. **Every gap below is written as a defect in the *general* path** that happens to bite
   hardest in the cloud. Fixing it helps every volunteer on bare metal and at home, not just
   Azure. Where a fix would only ever help a rented VM, that is a smell and the plan says so.
3. **The documentation path must work with zero scripts.** Operator ruling: *he provisions
   Azure himself, as an operator would — by going to GitHub and following the instructions.*
   Tooling is a convenience layered on top, never the product and never a prerequisite.
4. **Sizing is a recommendation, never an install gate.** Any script that genuinely needs a
   size input ships the minimal-possible best-guess default, parameterized for later change.

The strategic payoff: once Azure is proven, "pull to cloud" paths for other providers are new
folders under the same structure, so a volunteer can donate rented capacity as easily as a
spare box under a desk.

---

## §3 The GitHub front door

### 3.1 What the operator actually follows (the deliverable)

A **Cloud** entry in `README.md` beside Windows / macOS / Linux / Pi, pointing at a new
`docs/FRESH-NODE-START-CLOUD.md`. It reads, in the README's existing voice:

1. Create a Linux VM — **recommended size table, §4** — with a public IP and a data disk.
2. Point three DNS records at it (§5.1).
3. Open five ports (§5.4).
4. On the VM, run the **same one command every other volunteer runs**, with the public URL:

```bash
curl -fsSL https://raw.githubusercontent.com/CosmopolitanCoalition/fair-constitution-app/main/get-started.sh | bash
```

5. Open `https://earth.<domain>/setup` and complete the wizard exactly as on any other box.

Steps 1–3 are provider-specific and get a short Azure appendix (`az` one-liners, or portal
click-paths — both, because the operator may use either). Steps 4–5 are provider-agnostic and
identical to bare metal. **That symmetry is the point.**

### 3.2 The convenience layer (optional, second)

`cloud-up.ps1` / `cloud-up.sh` at repo root, with a **provider module** at
`infra/cloud/azure/` so a second provider is a new folder rather than a rewrite. Phase-for-
phase parity with `get-started.ps1` so the shape is familiar:

| Phase | `get-started.ps1` | `cloud-up.ps1` |
|---|---|---|
| 1 | Docker installed? | `az` installed? |
| 2 | Docker running? | `az account show` / `az login` |
| 3 | Ask map-data folder | Ask domain / node / region |
| 4 | `docker compose up -d` | `az deployment group create` (Bicep) |
| 5 | Poll `/setup`, open browser | Poll `https://<fqdn>/up`, open `/setup` |

**Azure provisioning via Bicep**, not imperative `az` calls: ARM deployments are declaratively
idempotent, so re-running converges the ~10 resources (RG, VNet, subnet, NSG + rules, static
public IP, NIC, VM, data disk, DNS zone, A records) with no hand-written "does this exist?"
guards. An imperative script needs about ten such guards and every one is a bug that only
appears on the *second* run — which is the run performed under deadline pressure. `az`
auto-installs Bicep on first use, so it is not a new toolchain for the operator to acquire.

`cloud-init` then runs the existing `deploy.sh` on the VM. **SSH is closed by default**:
`az vm run-command invoke` reaches the box over the Azure control plane with no inbound port
at all, which is how the wrapper fetches logs and performs updates. `-AllowSsh` opens 22 to
the operator's detected public /32 only.

**Size is a parameter with a minimal best-guess default, never a blocking prompt.**

### 3.3 Re-running must be safe (the APP_KEY trap)

`deploy.sh:207` runs `key:generate --force` **unconditionally**, while three separate operator
docs say never to re-run the script. The reason: the instance Ed25519 federation keypair lives
in `instance_settings.private_key_encrypted`, encrypted with Laravel `Crypt` — i.e. derived
from `APP_KEY`. Rotate the key and `Crypt::decryptString` throws *"MAC is invalid"* forever.
**There is no re-wrap path.** Recovery is `federation:init --rotate` = a brand-new node
identity requiring every peer to re-handshake.

Required change (my file, my lane):

```
if APP_KEY is empty OR equals the committed .env.example key:
    php artisan key:generate --force
else:
    echo "→ Preserving existing APP_KEY (federation identity intact)"
```

`.env.example:8` ships a real, committed, shared key, so "equals the example key" is a precise
test for *this box has never been keyed*. With this one change `deploy.sh` becomes what its own
docblock already claims to be — idempotent and safe to re-run — and "run the command again to
update" becomes true on Azure, the Pi and the rig alike. This is item 3.3 in §14 and it is on
the critical path.

---

## §4 Sizing, cost, and the serverless question

### 4.1 Measured inputs (not estimates)

| Thing | Measured | Note |
|---|---|---|
| Seeded-planet Postgres volume | **31.24 GB** | game box `fair-constitution-app_postgres_data` |
| Redis volume | **834 MB** | capped `--maxmemory 768mb` |
| Source geodata archive (total) | **153.73 GB** | operator's `D:\fair-constitution-map-files` |
| ├ protomaps `.pmtiles` | **126.31 GB** | ONE file — basemap only, **belongs in object storage** |
| ├ geoBoundaries repo | **17.95 GB** | ETL input |
| └ WorldPop 100 m | **9.48 GB** | ETL input |
| ETL input actually needed on a node | **~27.4 GB** | geoBoundaries + WorldPop only |

Memory floor derived from the compose caps themselves: postgres `mem_limit 5g` + `shm_size 1gb`
(`docker-compose.yml:122,132`), etl `mem_limit 2g`, Horizon workers
`clamp(cores−2, 2, 12) × 512 MB` (`app/Support/HostCapacity.php:33`), plus Synapse ~1–1.5 GB,
php-fpm ~1 GB, MAS/Caddy ~0.2 GB, OS/docker ~1 GB.

- **Serve a pre-seeded world:** ≈ **11 GB** → 16 GiB gives real headroom, 8 GiB does not.
- **Also build worlds (ETL / districting / prewarm):** ≈ **14 GB floor**, spiking on index
  builds → 32 GiB is the honest number.

Note the compose tuning comment targets a *~7.5 GB host* and it OOM-thrashed there. Do not
read those caps as a sizing recommendation; they are a survival setting.

### 4.2 Recommended sizes (recommendations — never install gates)

| Tier | VM | Disk | ≈ $/mo | For |
|---|---|---|---|---|
| **Small** | `D4as_v5` 4 vCPU / 16 GiB | 256 GiB Premium SSD P15 | **≈ 172** | Serving a seeded world — the Standard node |
| **Large** | `D8as_v5` 8 vCPU / 32 GiB | 512 GiB P20 | **≈ 330** | Also runs ETL / districting / prewarm |
| **Peer** | `D2as_v5` 2 vCPU / 8 GiB | 128 GiB P10 | **≈ 86** | A regional-subtree peer (override postgres `mem_limit` to 3g) |

Small-tier breakdown: VM ≈ $125 · P15 ≈ $38 · static IP ≈ $3.65 · DNS zone ≈ $0.50 · Blob hot
for the 126 GB basemap ≈ $2.65 · snapshots ≈ $1.55. Egress adds ≈ $35 at 500 GB/mo
(Zone 1 ≈ $0.087/GB after 100 GB free). **Ingress is free**, so uploading the basemap and any
database dump costs nothing.

Burstable B-series is *not* recommended: B4ms ≈ $121 vs D4as_v5 ≈ $125 — the saving is noise,
and the credit model punishes exactly the sustained-low-with-spikes profile a public governance
node has.

> Prices are approximate list, gathered 2026-07-25 from public aggregators. **Confirm in the
> Azure portal before committing** — regional variation is significant and these move.

**Recommendation:** run Small. Do not make the always-on public node also the world-builder.
World-building is a batch job: run it on the existing rig, or on a temporary large VM
(`D16as_v5` ≈ $0.688/hr ≈ $33 for a 48-hour run), and let the Standard node serve. That halves
steady-state cost and keeps ~27 GB of ETL input off the public box entirely.

### 4.3 The basemap does not go on the VM

PMTiles is designed for HTTP range requests, and the Vue map pages already fall through to
`import.meta.env.VITE_PROTOMAPS_URL` when no local bundle is present. So the 126.31 GB file
lives in **Azure Blob** (≈ $2.65/mo hot) and the browser pulls only the visible tiles —
hundreds of KB per session regardless of bundle size.

Two operational notes:
- `VITE_PROTOMAPS_URL` is a **build-time** constant and is **absent from `.env.example`**. It
  must be in `.env` *before* the asset build step, and it should be added to the example file.
- Copy the file into Blob **server-side from `build.protomaps.com`** (`azcopy` accepts a source
  URL). Ingress is free and this avoids pushing 126 GB up a home connection entirely.

### 4.4 Serverless: evaluated, and rejected for this stack

The operator asked directly whether Azure has a Cloud Run analogue and whether we should use
it. It does — **Azure Container Apps** — and the answer is no, for four reasons:

1. **No UDP.** Container Apps ingress supports **HTTP and TCP only**. LiveKit media needs
   `7882/udp`, and voice is explicitly in scope. This alone is disqualifying. (External TCP
   ingress also requires a VNet and cannot use port 80/443.)
2. **It costs more here, not less.** Consumption compute runs ≈ $63 per vCPU-month; 4 vCPU
   always-on ≈ $252/mo *before* a managed PostGIS database — versus ≈ $125 for the equivalent
   VM.
3. **Scale-to-zero earns nothing.** The scheduler fires every minute, the clock registry
   evaluates continuously, and CLK-20 re-arms on a rolling deadline. A governance node is
   never idle by design, so the one feature you would be paying for cannot engage.
4. **It breaks §2.** Compose-on-a-VM is the *same artifact* a volunteer runs on bare metal or
   at home. A serverless decomposition would be a second architecture maintained in parallel —
   exactly the cloud special case the operator's ruling forbids.

**Where serverless genuinely does fit, and we should use it:** the PMTiles basemap on Blob
(+ CDN if egress grows), and intermittent world-building as a Container Apps **Job** — batch,
no ingress, no UDP, genuinely bursty. Both are additive and neither forks the stack.

---

## §5 Networking

### 5.1 DNS

| Record | Type | Target | Purpose |
|---|---|---|---|
| `earth.<domain>` | A | VM static public IP | app origin, Matrix client + S2S, `.well-known` |
| `auth.earth.<domain>` | A | same | MAS issuer (whole origin — MAS's `public_base`/`issuer` are origins, not paths) |
| `rtc.earth.<domain>` | A | same | LiveKit `wss://` signalling |
| `earth.<domain>` | AAAA | VM IPv6 | optional; "internationally accessible" argues for it, defer to v1.1 |

No `_matrix-fed._tcp` SRV record is needed — `.well-known` delegation takes precedence and
`WellKnownController` already serves it.

**Naming decision: `MATRIX_DOMAIN` = `APP_URL` host = `earth.<domain>`.** The prettier
alternative (`server_name` = bare apex, so handles read `@alice:worldofstatecraft.org`) forces
the apex to serve `/.well-known/matrix/server` forever, which means giving up the apex for a
website or maintaining a separate static host permanently. Take the less pretty option.

Zone hosting: an **Azure DNS zone in the same resource group** keeps everything on the
operator's existing `az login` with zero extra credentials. If the parent domain lives
elsewhere, delegate a subzone once. The script supports `-DnsProvider none`, which prints the
records and waits for them to resolve before certificate issuance is attempted.

### 5.2 TLS — a Caddy edge

A new `edge` service (`caddy:2-alpine`) in a production compose overlay, with automatic HTTP-01
issuance and certificates on a **named volume** (certificate loss on container recreation burns
Let's Encrypt's 5-per-week duplicate limit in an afternoon).

```
earth.<domain>       → reverse_proxy nginx:80
auth.earth.<domain>  → reverse_proxy mas:8080
rtc.earth.<domain>   → reverse_proxy livekit:7880   (websocket)
:8448                → reverse_proxy nginx:80       (Matrix S2S backstop, same cert)
```

Why not the alternatives:

- **The baked-in `lego` binary** (`docker/php/Dockerfile:86-97`) is wired to the *governed*
  broker path — `mesh:request-cert` needs an established `broker.tls` capability channel, a
  stored Cloudflare token and a grant. The **first** node has no peers and no channel, so this
  is a chicken-and-egg. Keep `lego`/the broker for what it is for: issuing certs to *peers*
  under mesh governance. Caddy bootstraps node one; the broker serves the mesh. Complementary,
  not competing.
- **The existing `tlsproxy` profile** has no issuance at all (it consumes certs the broker
  already made), hardcodes `wildcard.worldofstatecraft.org` cert filenames
  (`docker/nginx/tls-proxy.conf:22,46`), and caps `client_max_body_size` at **25m** — which
  would break the map-data import path that the app's own nginx sizes at 20g. Leave it for the
  LAN rig.
- **Cloudflare proxy** cannot carry LiveKit UDP, terminates TLS at a third party (directly
  against the sovereign-node premise), and makes a Cloudflare account a hard dependency of
  "one command → online". Cloudflare **DNS-only** is fine and compatible with the broker.
- **Front Door / Application Gateway**: $20–250/mo before traffic, no UDP, and HTTP-only so the
  8448 backstop is lost. Solves problems this node does not have.

Laravel is already ready for an edge: `bootstrap/app.php:65` sets `trustProxies(at: '*')`.

### 5.3 Two traps that will cost a day each if undocumented

1. **Matrix delegation silently points at the wrong port.**
   `WellKnownController::delegateAuthority()` computes `"<server_name>:<port>"` from
   `parse_url(APP_URL, PHP_URL_PORT)`. With `APP_URL=https://earth.<domain>` there *is* no
   port, so it emits a bare hostname — and per the Matrix spec a portless `m.server` means
   **8448**, not 443. Remote homeservers would be sent to a port Caddy is not serving.
   **Fix:** set `MATRIX_DELEGATE_SERVER=earth.<domain>:443` explicitly, and open 8448 anyway as
   a backstop.
2. **MAS hairpins on its own public FQDN.** `matrix:setup` renders MAS with
   `discovery_mode: oidc`, so the MAS container fetches
   `https://earth.<domain>/.well-known/openid-configuration` *at runtime, from inside Docker*.
   An Azure VM reaching its own frontend public IP is not reliably supported, and the failure
   surfaces late, as "login is broken."
   **Fix:** give the `edge` service a **network alias equal to the FQDN** on `fc_network`, so
   internal callers resolve it to Caddy's container IP over the bridge and get the same valid
   certificate.

### 5.4 Firewall / NSG

**Inbound allow:**

| Port | Proto | Source | Why |
|---|---|---|---|
| 80 | TCP | Internet | ACME HTTP-01 + redirect to https |
| 443 | TCP | Internet | app, Matrix client + S2S, MAS, LiveKit wss |
| 8448 | TCP | Internet | Matrix S2S backstop |
| 7881 | TCP | Internet | LiveKit media TCP fallback |
| 7882 | UDP | Internet | LiveKit muxed media |
| 22 | TCP | operator's /32 | **closed by default**; `az vm run-command` replaces it |

**Ports that must stop binding `0.0.0.0`.** Verified: every published port in
`docker-compose.yml` is `"${VAR:-default}:container"`, and the host half of a compose port spec
accepts a bind address — so **`.env` alone closes them, with no compose edit**:

```
NGINX_HOST_PORT=127.0.0.1:8080     POSTGRES_HOST_PORT=127.0.0.1:5432
MATRIX_HOST_PORT=127.0.0.1:8008    MAS_HOST_PORT=127.0.0.1:8090
VITE_HOST_PORT=127.0.0.1:5173      LIVEKIT_HOST_PORT=127.0.0.1:7880
```

Checked for fallout: `config/matrix.php` reads `MATRIX_HOST_PORT` as an int, but a repo-wide
grep finds **zero consumers** of that value — it is a dead key. Safe.

> **⚑ Verified exception:** LiveKit's `"7881:7881"` is **hardcoded**
> (`docker-compose.yml:282`) and cannot be rebound from `.env`. It needs a compose change or
> host networking. `docker-compose.yml` is outside lane 2's path ownership — see §14.

Today `postgres` publishes 5432 on `0.0.0.0` with `fc_user`/`fc_password`; Synapse 8008 and MAS
8090 are likewise open. `docs/operator/livekit.md:49` already says never to expose these. This
change makes the deployed default match the doc. Bind to loopback **and** deny at the NSG: a
future NSG mistake should not equal instant database compromise, and the same `.env` will be
copied onto volunteer boxes that have no NSG at all.

---

## §6 Setup on the node — the ordinary volunteer paths

Per §2 the node runs the normal wizard: **bootstrap → JOIN or START FRESH**, and fresh is
either *fresh-fresh* (ETL pull) or *fresh-from-restore*. Each path below gets an honest verdict.

### 6.1 JOIN — works, but produces a **mirror**

Fully proven, resumable, and signed: Ed25519-signed pages, per-table keyset cursors, cold-load
index drop/rebuild, page-apply and cursor-advance in one transaction. On Azure it is *easier*
than the Pi test, because both ends have real public FQDNs — no LAN-IP detection, no
`host.docker.internal`, no NAT, no split DNS.

**But joining sets `mirror_of_server_id` before syncing and stamps
`authoritative_server_id = <host>` on every jurisdiction, fail-closed.** The foundation transfer
carries only `{cosmic_addresses, jurisdictions, worldpop_rasters, geoboundary_metadata,
constitutional_settings}`, and the audit tail applies only `public_records`, `achievements` and
attestation revocations. Legislatures, districts, elections, organizations, **`users`** and
`matrix_rooms` do **not** replicate.

**⚑ Consequence the operator must rule on (§13 Q1):** a mirror is authoritative for nothing and
has no user accounts, so **players cannot register or act on it**. An internationally accessible
*real-multiplayer* front door must therefore be the FRESH/sovereign node, with the home box
joining *it* — not the reverse.

### 6.2 FRESH-FRESH (ETL pull) — works, and the cloud is the best place for it

`scripts/etl/*` in the `etl` container, driven by the wizard's Step 2 control files.
Downloads ~27.4 GB (geoBoundaries + WorldPop) and imports over 6–12 h, resumable per
`(iso3, adm_level)`. Azure gives this its best environment: fast upstream bandwidth and free
ingress. Needs the `etl` service (`deploy.sh --with-etl`, opt-in) and Large-tier headroom while
it runs.

The honest caveat: after import, the node must still run the districting sweep to size ~955,130
legislatures — the work lane 1 has spent weeks on. Clean-room provenance is real, but so is the
time. See §10's freeze contingency.

### 6.3 FRESH-FROM-RESTORE — **the one genuinely broken path**

And it is broken for *everyone*, not just cloud — which is exactly the §2 test:

1. **The only import seam is a browser multipart upload.** A planet-scale bundle cannot be
   restored at all: nginx allows 20g and PHP 20G, but a ~31 GB volume exceeds that and there is
   no chunked/resumable upload, no "import from server path" route, and the `tlsproxy` variant
   caps at 25m.
2. **It silently adopts the donor's identity.** `instance_settings` rides the default export
   table set and carries `server_id`, `public_key` and `private_key_encrypted` — the last of
   which is undecryptable under the receiving box's `APP_KEY`. Two nodes then share an identity
   and one of them has a broken keypair, with no error until federation calls start throwing
   *"MAC is invalid"*. The derivation test asserts `users`/`operator_accounts` are excluded but
   never checks `instance_settings`.
3. **Bundles are unsigned and unchecksummed.** The manifest carries `exported_at`,
   `schema_version` and row counts — no sha256, no signature. (Contrast the federation seed
   manifest, which is origin-signed and digest-verified.)

**The good news:** the engine that does this correctly already exists.
`MapDataImportService::importSeedFromFile` filters `instance_settings` out and performs an
identity-safe `cosmic_addresses` re-point by slug. It simply has **no CLI**. And — verified —
`MapDataImportService` never writes `authoritative_server_id` anywhere; the only writers are
`MirrorService.php:400,453`, `AuthorityFlipService`, `LocalAutonomyService` and
`FederationDemoCommand`.

> **This is the hinge of the whole plan: a file restore yields a SOVEREIGN node; a peer join
> yields a MIRROR.**

**Fix (general, benefits every volunteer):** a `map:import-seed {path} {--expect-sha256=}`
console command wrapping `importSeedFromFile`, which verifies the digest *before* importing and
refuses if `instance_settings` appears in the manifest's included tables; plus a `--tables=`
option on the existing seed-publish command so a bundle can carry the district plane. ~70 LOC
total. Two hard ordering constraints for the runbook:

- **Step 0 (cosmic address) must be completed before importing.** `importSeedFromFile` reads
  `instance_settings.cosmic_address_id` to remember the prior slug; if it is NULL the re-point
  throws — *after* the destructive clear has already committed.
- **Exclude `autoscale_runs`/`autoscale_items`/`autoscale_scopes`.** With an unfinished
  autoscale run present, re-accepting map data *resumes a planet-wide sweep* that would
  overwrite the imported district plane for days. Excluding them is what makes the wizard's
  "Accept Map Data & Continue" button harmless.

---

## §7 Multibox — additional Azure nodes

Same path, different node name: `alpha.<domain>` gets its own resource group, static IP, NSG,
Caddy cert, `APP_KEY`, Ed25519 `server_id`, and its own Synapse homeserver
(`MATRIX_DOMAIN=alpha.<domain>`). One CGA instance = one homeserver.

Joining is the proven sequence over real FQDNs:

```bash
docker compose exec app php artisan federation:peer:discover https://earth.<domain>
docker compose exec app php artisan federation:peer:handshake https://earth.<domain>
docker compose exec app php artisan mesh:doctor https://earth.<domain>
docker compose exec app php artisan federation:sync:push <EARTH-SERVER-ID>
```

What a peer **gets**: the geodata foundation, the public record tail, achievements. What it
**does not get**: institutions, users, Matrix rooms (§6.1). A peer that should govern a region
takes a signed partition/subtree seed instead of the whole planet — cheaper and
constitutionally correct.

Version discipline: `mesh:doctor` reports a version mismatch between nodes, so the fleet must
stay on the same `main`. The wrapper's update mode (`git pull && ./deploy.sh`) is the mechanism
— which is why §3.3's conditional `key:generate` is load-bearing for multibox, not just tidy.

---

## §8 Security and credential pass

The deferred pre-launch credential gate, scheduled (Phase 4, §10):

- **Rotate every committed dev secret.** `matrix:setup` regenerates the appservice `as_token`/
  `hs_token`, the MAS encryption secret, the OIDC client secret and the LiveKit key/secret as
  one matched bundle. **Assert no `cga_dev_` string survives in any running container** — they
  are committed in `docker/matrix/mas/config.yaml`, `docker/matrix/appservice/registration.yaml`
  and `docker/livekit/livekit.yaml`.
- **⚑ `matrix:setup` writes `config.generated.yaml`, but compose mounts `config.yaml` — and
  nothing in the repo copies one to the other.** The committed `config.yaml` contains live
  RSA/EC private keys. The cloud path must run `matrix:setup` *before* MAS first starts and then
  perform the copy. File a separate item to purge the committed keys from history.
- **Browser device key off `localStorage`** → non-extractable WebCrypto Ed25519 in IndexedDB.
  Ed25519 is deterministic so backend verification is byte-identical and the interop pin holds.
  **If this slips, do not wire `signTravelingWrite` to a real form** — ship the write path late
  rather than on an XSS-extractable key.
- **Production guards on the six demo commands** (`elections:demo`, `institutions:demo-d`,
  `institutions:demo-e`, `social:demo`, `matrix:demo`, `federation:demo`) and on
  `jurisdiction:activate --force`. None has any guard today, and **`deploy.sh --seed` invokes
  `institutions:demo-e`**, which inserts residency confirmations directly — manufactured consent,
  into an append-only ledger. Guards must fail **closed** (`refuse unless GameMode::isSandbox()`),
  not `!isProduction()`, because the mode is null before founding and a negative test would fail
  open. `--seed` must additionally be refused whenever `APP_ENV=production`.
- **At rest:** `APP_KEY` into Azure Key Vault on day one and nowhere else; `.env` permissions;
  `APP_DEBUG=false`; `/dev/*` returns 404.
- **Postgres credentials** (`fc_user`/`fc_password`) are duplicated across `docker-compose.yml`,
  `docker/matrix/conf.d/10-cga.yaml` and `MatrixSetupCommand.php` — a coordinated three-file
  rotation. File it; with 5432 on loopback behind an NSG deny, do not block launch on it.

---

## §9 Backups and monitoring

**The coupling that makes this non-obvious:** a Postgres backup *without the matching APP_KEY*
is not a restorable node — it is a database whose `private_key_encrypted` cannot be decrypted,
with no re-wrap path. So three artifacts must be backed up, and the key material must live in a
**different trust boundary** from the dump:

1. **`APP_KEY` + the Matrix/MAS/LiveKit/OIDC bundle** → Azure Key Vault, as individual secrets.
   Never in the same container as the database dump.
2. **`postgres_data`** → nightly `pg_dump --format=custom` to Blob with lifecycle retention,
   **plus** a nightly managed-disk snapshot (crash-consistent; restore-to-yesterday, not PITR).
3. **`matrix_data`** → the third irreversible identity, and the one nobody counts. It holds
   Synapse's signing key and media store; lose it and the homeserver's identity under
   `MATRIX_DOMAIN` is dead and remote rooms break. Snapshot nightly.
4. `storage/app/mesh-tls` → certificates; recoverable, but archive them.

Not backed up: Redis (regenerable), `etl_geodata` (re-downloadable), built assets.

**The restore test is the only thing that counts.** Restore onto a *second* VM: `.env`
(APP_KEY) + DB dump + `matrix_data`, boot **without running `deploy.sh`** (its unconditional
`key:generate` would destroy the very thing under test), then assert `server_id` matches, gates
are green, and a **self-sign/self-verify canary** passes — i.e. the box can actually decrypt its
private key and produce a signature that verifies against its stored public key.
**Run the drill with federation disabled or on an isolated network** — two live boxes sharing a
`server_id` and both signing is a split brain.

Monitoring, minimum viable: uptime on `/up`; disk headroom on the data disk; Horizon queue depth
and failed jobs; certificate expiry; a nightly `launch:assert-clean` run (§11); and a
**forced-activation tripwire** that alerts on any `jurisdiction_activations` row whose
jurisdiction had fewer verified residents than its resolved threshold.

> Architecture note, deliberately *not* recommended now: Azure Database for PostgreSQL Flexible
> Server would give managed PITR and supports PostGIS. It is the better long-run answer. Do not
> migrate five weeks before launch. Revisit post-launch.

---

## §10 Schedule — backwards from 2026-09-01

L-0 = 2026-09-01. Phases overlap where they are genuinely independent.

| Phase | Dates | Content | Exit criterion |
|---|---|---|---|
| **0 · Seams + measurement** | 07-26 → 08-01 | The code seams in §14 (conditional `key:generate`, `--public-url`, `matrix:setup` + config copy, `--seed` guard, ClockRegistrySeeder in `get-started`, `map:import-seed`, `launch:assert-clean`, demo guards). **Measure**: timed 1 GB upload to Blob; dry-run bundle size; the game box's real 10-service RAM/CPU under load. | Seams merged; bundle size and transfer time are *numbers*; DNS zone ownership answered |
| **1 · Local rehearsal** | 07-28 → 08-04 | The settled grand-reset ruling executed on the dev stack: fresh clone → `get-started` → bootstrap → SOLO → sandbox → wizard Steps 0–4, on a bounded 3-country ETL. Then a second run in **production** mode against the restore path. | Both green, with after-screenshots; runbooks written |
| **2 · Provisioning + DNS/TLS** | 08-03 → 08-09 | Region/subscription decision; VM + disks; Blob; Key Vault; cold bring-up in the correct order (`MATRIX_DOMAIN` → `matrix:setup` → `deploy.sh` **once** → APP_KEY to Key Vault); DNS records; certificates issued. | Empty, identity-minted, production-mode sovereign node; gates green; 21 clocks present |
| *slack* | 08-08 → 08-10 | | |
| **3 · World on the node** | 08-10 → 08-15 | **08-10 = MAP FREEZE** (the gating dependency). Bundle cut + verified + transferred; wizard Steps 0–4 completed on Azure; prewarm; `launch:assert-clean`; geodata flags reviewed. | Sovereign node holding the healed plane, zero consent rows, institutions dormant |
| **4 · Security pass** | 08-15 → 08-20 | §8 in full. | No `cga_dev_` anywhere; demo commands refuse; device key hardened or `signTravelingWrite` left unwired |
| **5 · Backups + restore drill** | 08-18 → 08-22 | §9 in full, including a real restore onto a second VM. | **A restore has actually been performed and verified. If it has not, we do not launch.** |
| **6 · Soak** | 08-22 → 08-30 | 8 continuous days; all §11 gates; both D-18 rig gates; one restore drill mid-soak. | 8 clean days, nightly assert-clean 8/8 |
| **7 · Freeze + cutover** | 08-29 → 08-31 | Code freeze; final assert-clean, backup and screenshot set; go/no-go one line per gate; DNS TTL lowered; public URL live. | Operator signs the gate list |
| **L-0** | **09-01** | **Launch.** | |

**Restart-the-clock gates** (failure resets the soak): identity integrity, zero-synthetic-data,
backups+restore. **Fix-and-continue** for everything else.

**Honest read on slack: about 5 explicit days out of 38 (~13%)**, against one hard external
dependency (lane 1's map convergence) and one unmeasured physical constraint (the donor's
uplink). That is thin, and the operator should be told at the **08-10 freeze**, not on 08-28.

### The map-freeze contingency (updated from lane 1's 10:58 board entry)

The review pile is 443 and falling (~23% heal rate on a 40-map sample), but **391 of those are
childless root leaves** — jurisdictions whose municipalities are simply not ingested, so the
splitter must carve ~48 districts out of a single polygon. That is an **ingestion gap, not a
districting bug**, and its fix is the geodata pull-engine rebuild, which sits *behind* the
analysis round and will not land before 09-01.

**So: freeze on 08-10 regardless.** Drift maps (~3,351) are complete and playable — ±1 seat in
339 is under 1%. The remaining review territories ship flagged "under review" against ~955,130
legislatures across ~951,626 jurisdictions; the affected class is ~84 M people, which is
material and should be stated plainly rather than buried.

Lane 1's later fixes land post-launch as a **district-only re-import scoped to legislatures
still `status = 'forming'` with zero members**. Because the district tables are disjoint from
every consent table, such a re-import cannot disturb a single real resident — and there is a
natural multi-week window, since nothing can seat without a certified election.

---

## §11 Launch checklist and soak verification

**GATE-1 · Bring-up.** `mesh:gates` exits 0 and prints "Node is ready to federate"; the
self-URL gate shows the public https URL, not `host.docker.internal`;
`SELECT COUNT(*) FROM clocks` = **21**; **all ten services** healthy for 24 h with zero
restarts. *(Audit item: a bare `php artisan migrate` leaves `clocks` EMPTY — the registry ships
in `ClockRegistrySeeder`, invoked only from `deploy.sh:216` / `deploy.ps1:167`, and
`federation:init` throws without it.)*

**GATE-2 · Seed integrity.** Received sha256 == signed manifest sha256; per-table row counts
match the manifest exactly; `COUNT(*) FROM jurisdictions WHERE authoritative_server_id IS NOT
NULL` = **0**; `instance_settings.mirror_of_server_id` IS NULL; the node's `server_id` differs
from the donor's.

**GATE-3 · Zero synthetic data** *(the launch's defining claim, machine-checked by
`launch:assert-clean`)*: `residency_confirmations`, `residency_claims`, `constituent_consents`,
`standing_attestations`, `jurisdiction_activations`, `legislature_members`, `executives`,
`judiciaries`, `elections`, `candidacies`, `ballots`, `vote_casts`, `organizations`, `social_*`,
`matrix_rooms`, `achievements`, `public_records` = **0 rows**.
`COUNT(*) FROM legislatures WHERE status <> 'forming'` = **0**. `COUNT(*) FROM users` = the
exact number of real humans (at cutover: 1). No user at a test or `.invalid` domain.

**GATE-4 · Dormancy.** `critical_population_threshold` resolves to the agreed value at the root
**and** at a sampled ADM3 leaf (proving the ancestor walk reaches leaves). **It must not be 1.**
Set both the DB row (amendable in-game) and the env backstop, so a missing row can never fall
back to the config default of 1. Zero `matrix_rooms`, because no `#hall` can exist without
`isSeated && isActivated`.

**GATE-5 · Safety.** Each of the six demo commands run and asserted to exit non-zero having
written nothing; `jurisdiction:activate --force` refuses; `deploy.sh` re-run preserves APP_KEY;
`/dev/*` 404s.

**GATE-6 · D-18 rig gates.** (i) **Phone browser on cellular** — not office wifi, so the
internet path is genuinely proven — against the public HTTPS URL, geolocation granted, GPS ping
submitted and landed. This is the gate LAN-HTTP could never clear, having no secure context.
(ii) **Cross-machine peer join** from a second box: discover → handshake → two-way
`mesh:doctor` green → a signed sync payload applies. The Pi pass proved the code; this proves it
across real DNS/TLS/NAT.

**GATE-7 · Browser proof** *(operator's standing rule: a fix is not done until an
after-screenshot of the affected page proves it)*. Required: the wizard at the completed step
showing the transplanted plane; the planet-scope viewer at Earth; **one clean ADM1 map and one
drift map** (ship honest, not curated); the federation console gates green; a jurisdiction page
reading forming/dormant; the phone GPS ping page. **No gate closes without its screenshot.**

**GATE-8 · Soak.** 8 clean days; zero unplanned restarts; nightly `launch:assert-clean` 8/8;
nightly backup 8/8; ≥1 restore drill passed *during* soak; Postgres disk headroom > 40%; Redis
under `maxmemory` without thrash.

**Assigned at fleet review round 1 (2026-07-25):**

- **M-5 / K-3 legal-compliance launch gate** — the compliance surface must be reachable and
  correct before public launch. *(The build half — a console command wrapping
  `LegalComplianceService` — is lane 14's; this line is the gate.)*
- **CI decision (one line):** *no CI is stood up inside the 40 days.* Lane 5's drift/extraction
  checker runs standalone with a clean exit code and gates locally from day one; CI bring-up is
  explicitly not 40-day-critical and is recorded as post-launch work. There is no `.github`
  directory in the repo today, so standing up CI would mean building the harness as well as the
  check — not a cost this window can absorb.
- **`Register.vue` translation promise resolved either way** pre-launch — lane 5 pilots minimal
  delivery; if unproven when lane 6's parity wave reaches Auth/Register, lane 6 softens the copy
  (two lines, reversible in Phase N). Either outcome closes this line; shipping an unqualified
  promise does not.
- **BallotCrypto receipt cryptographer review** — see §12 R-12 for the scoping recommendation.

---

## §12 Risk register

Ranked by irreversibility × likelihood. Each carries a mitigation and a detection signal.

| # | Risk | Mitigation | How we would know |
|---|---|---|---|
| **R-1** | **APP_KEY destroyed by a `deploy.sh` re-run** — unconditional `key:generate --force` orphans the Ed25519 identity; no re-wrap path; recovery is a new identity + full re-handshake. Likelihood **moderate** (re-running is the natural reflex when something looks wrong). | §3.3 conditional guard; APP_KEY in Key Vault day one; a `DEPLOYED` lock file; first line of the runbook | **Not `mesh:gates`** — it only checks the keys are *present*, not that the private key *decrypts*. Real signals: "MAC is invalid" in the log, 500s on federation sync. **Add a scheduled self-sign/self-verify canary** — the gate set has a hole here |
| **R-2** | **`MATRIX_DOMAIN` wrong at first Synapse boot** — `deploy.sh` never sets it, `.env.example` says `localhost`, and it is permanently locked once events exist. Likelihood **high** if the script ships as-is | Set before the first `up`; `matrix:setup --server-name=` before MAS starts; pre-flight assertion | `curl /_matrix/key/v2/server`; any user ID minted as `@x:localhost` |
| **R-3** | **`FEDERATION_SELF_URL` wrong or changed after peers pin it** — env-only, restart-gated, no runtime override | Decide the final public URL before provisioning; never publish the temporary `*.cloudapp.azure.com` name to anyone | `mesh:gates` self-URL gate; peer handshake failures |
| **R-4** | **A demo command run on production** — zero guards; `institutions:demo-e` inserts residency confirmations directly into an append-only ledger, falsifying the launch's central claim | §8 guards, fail-closed; else remove the commands from the production image | Forced-activation tripwire; a residency row with no ping/attestation trail |
| **R-5** | **Activation threshold left at 1** — the founder's own residency would activate a jurisdiction, firing bootstrap and scheduling an election | Set the root settings row **and** the env backstop; needs lane 3's number by 08-05, or an explicit interim value stated publicly | CLK-06's minute sweep writes activations within minutes of the first confirmation — alert on any |
| **R-6** | **Lane 1's plane does not settle by 08-10** — likelihood **moderate-high**; 391 of 443 remaining are an ingestion gap whose fix ships after the analysis round | Freeze anyway; ship drift maps; flag the review class; post-launch district-only re-import (§10) | Lane 1's board STATUS; the review+drift trend line |
| **R-7** | **The donor's uplink cannot move the bundle in time** — currently **unmeasured**. Ingress is free; a home upload is not | Measure 07-27; `azcopy` with resume; decide any physical-media fallback by **08-01**, not 08-10, because of lead time | The timed 1 GB test |
| **R-8** | **The bundle carries something unintended** | The table allowlist *is* the sanitiser; assert the manifest's included tables equal the intended list *before* import | GATE-3 |
| **R-9** | **Split brain during a restore drill** — two boxes, one `server_id`, both signing | Drills run federation-disabled or air-gapped; tear the drill box down | Peers see two signatures for one sequence; sync conflicts |
| **R-10** | **Committed dev secrets reach production** — `cga_dev_*` in three committed files, and MAS starts before anyone runs `matrix:setup` | §8; `matrix:setup` before MAS first boot + the `config.generated.yaml` copy | `grep cga_dev_` inside the running containers |
| **R-11** | **Browser device key in `localStorage`** — extractable by any XSS | WebCrypto non-extractable in IndexedDB; **if it slips, do not wire `signTravelingWrite`** | A code state, not a runtime signal — tracked as a checklist item |
| **R-12** | **BallotCrypto `{ballot_hash, salt}` receipt as a vote-selling channel** — its own docblock names a cryptographer review as a production gate | **Recommendation: scope it as a gate on the FIRST ELECTION, not on launch** — at launch no election can certify (dormant institutions + threshold), so the ballot path is not live on day one. **This reframing is valid only if GATE-4 holds**; if dormancy cannot be proven it reverts to a hard launch blocker with near-zero chance of clearing in the window. GATE-4 is load-bearing twice over | GATE-4; any `elections` row appearing |
| **R-13** | **Sizing off the wrong service count** — CLAUDE.md says 7 services; compose defines 12 and a deployed stack runs 10 | Size off the Phase-0 measurement, not the doc | OOM kills; Horizon stalling |
| **R-14** | **Geodata acceptance flags never acknowledged** — a CLI import bypasses the confirm dialog the UI path enforces | `geodata:scan` + explicit review as a Phase-3 exit item | Open flags at cutover |

---

## §13 Decision sheet

Settled 2026-07-25:

- **D-A · Hostname** = `earth.worldofstatecraft.org`; `MATRIX_DOMAIN` = `APP_URL` host. ✅
- **D-B · Scope** = everything, including voice/video. ✅
- **D-C · Provisioning** = the operator provisions Azure himself from GitHub instructions;
  sizing is a recommendation, never a gate. ✅
- **D-D · Serverless** = rejected for the stack (§4.4); used for the basemap and for batch
  world-building. ✅

Open — needed before provisioning:

| Q | Question | Needed by | Recommendation |
|---|---|---|---|
| **Q1** | **⚑ Which box is sovereign?** A JOIN makes the Azure node a mirror — authoritative for nothing, no user accounts, so players cannot register there. An international real-multiplayer front door must be the FRESH node. Confirm: Azure is FRESH and the home box joins it? | 08-03 | **Azure FRESH; home box joins it.** |
| **Q2** | **Where does the DNS zone live today** — who controls `worldofstatecraft.org`? If the answer is "nobody yet," Phase 2's TLS/DNS budget is wrong and the schedule needs re-cutting. | **08-01** | Azure DNS zone in the same RG, or delegate a subzone |
| **Q3** | **Region.** Latency, or law? For a project about sovereign governance the host's jurisdiction is arguably a constitutional question, not an ops one. | 08-03 | Operator's call; state the reason in the doc |
| **Q4** | **Activation threshold integer** (from lane 3, or an explicit interim). The dev default of 1 must not ship. | 08-05 | Interim flat value, stated publicly as interim |
| **Q5** | **BallotCrypto review scoping** — gate on the first election rather than on launch? | 08-15 | Yes, contingent on GATE-4 |
| **Q6** | **Peer subscriptions** — do peer nodes run under the operator's Azure account or their own? A federation where one tenant owns every node is a fleet wearing a federation's clothes. | post-launch | Own subscriptions where a peer is genuinely sovereign |

---

## §14 Work items by owner

**Lane 2 (mine — `deploy.*`, `get-started.*`, `docs/plans/launch/`, `docs/FRESH-NODE-START*`):**

1. `deploy.sh`/`deploy.ps1`: conditional `key:generate` (§3.3); `--public-url`/`--cloud` setting
   `APP_URL`, `FEDERATION_SELF_URL`, `MATRIX_DOMAIN`, `MATRIX_DELEGATE_SERVER`; run
   `matrix:setup` before MAS first boot **and copy `config.generated.yaml` → `config.yaml`**;
   refuse `--seed` under `APP_ENV=production`; add `config:cache`/`route:cache`/`view:cache`.
2. `get-started.sh`/`.ps1`: run `db:seed --class=ClockRegistrySeeder --force` after `migrate`.
   **Three lines, and the highest value per line in the whole set** — without it a from-clone
   box has an empty clock registry and `federation:init` throws.
3. `map:import-seed` console command + `--tables=` on seed-publish (§6.3); `launch:assert-clean`
   (§11); production guards on the six demo commands and `jurisdiction:activate --force` (§8).
4. `docs/FRESH-NODE-START-CLOUD.md` + README "Cloud" entry; `cloud-up.{ps1,sh}` +
   `infra/cloud/azure/` (Bicep + cloud-init); production compose overlay + Caddyfile +
   `php-prod.ini`.
5. Reconcile the stale docs found en route: `bootstrap/bootstrap.sh` claims `deploy.sh` runs
   `federation:init` only on `--join` (it is unconditional); `multibox-ui-run.md` points at a
   `--join` path that `FRESH-NODE-START.md` does not document; README and FRESH-NODE-START are
   mutually silent, so a README user lands in a state FRESH-NODE-START assumes cannot happen.

**Needs coordination (outside lane 2's paths) — raised on the fleet board:**

6. `docker-compose.yml`: LiveKit's hardcoded `"7881:7881"` (§5.4). Also, eventually, the
   duplicated `fc_user`/`fc_password`.
7. `docker/php/entrypoint.sh`: gate the boot-time prewarm auto-dispatch behind
   `CGA_PREWARM_ON_BOOT` (default 1, preserving today's behaviour). Without it every Horizon
   restart on the cloud node re-dispatches a z0–12 raster prewarm (~1 M land tiles).
   *Fallback if it cannot land: the horizon service bind-mounts its entrypoint, so the overlay
   can mount a cloud variant for that service only — at the cost of a forked file. Prefer the
   one-liner.*

**Consumed from other lanes:** lane 3's activation-threshold integer (Q4, by 08-05); lane 1's
map-freeze declaration (08-10); lane 14's `LegalComplianceService` console command (the build
half of the M-5/K-3 gate); lane 5's translation pilot and lane 6's copy decision for
`Register.vue`.

**Framing note for outward-facing lanes (9/10/11/12):** the 2026-09-01 launch is the earth.\*
**Standard instance** — real consent, dormant scaffolding, zero synthetic data. It is **not**
the Phase-O demo. Dates in this document are plannable and may be cited; the freeze date
(08-10) is the one most likely to move.
