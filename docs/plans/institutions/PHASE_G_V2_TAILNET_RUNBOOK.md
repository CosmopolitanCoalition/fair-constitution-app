# Phase G — G-V2 Runbook: real cross-machine peer onboarding over a Headscale tailnet

**Goal (the parked G-V2 gate):** a FRESH Ubuntu box (B) pulls this repo from GitHub,
runs `deploy.sh`, joins a private Headscale/Tailscale overlay, and **onboards as a
live federation peer** (read-only mirror) of an already-running instance (A) — peering
over tailnet IPs (encrypted WireGuard), with **no inbound ports opened on the netgate**.

**Who does what.** Claude owns this runbook + the config files in-repo. **You execute
every step** — Headscale, the Tailscale clients, Cloudflare, and box B. **Never paste
your Cloudflare API token (or any key) into the chat.** Keep it on your machines; the
commands below use it locally and Claude never sees it. Run a step, paste the *output*
back, and we iterate.

```
   ┌─────────────────────────────┐        Headscale control plane (HTTPS)
   │  Cloudflare Tunnel           │◀───────  https://headscale.<domain>
   │  headscale.<domain> → :8080  │
   └──────────────┬──────────────┘
                  │ (outbound-only; no netgate ports)
        ┌─────────▼─────────┐         WireGuard mesh (DERP-relayed or direct)
        │  Headscale server  │  ◀───────────────────────────────────────┐
        └────────────────────┘                                          │
   Box A (host, 100.64.0.1)                          Box B (mirror, 100.64.0.2)
   ┌──────────────────────┐   /api/federation/adopt  ┌──────────────────────┐
   │ CGA instance A        │◀────── signed HTTP ──────│ CGA instance B        │
   │ nginx :8080           │   audit-tail / sync      │ nginx :8080           │
   │ FEDERATION_SELF_URL=   │─────────────────────────▶│ FEDERATION_SELF_URL=  │
   │  http://100.64.0.1:8080│                          │  http://100.64.0.2:8080│
   └──────────────────────┘                          └──────────────────────┘
```

All CGA commands below are verified against the source (`deploy.sh`,
`app/Console/Commands/*`). The Headscale config targets 0.23.x; CLI/flags drift
between versions — if a command errors, paste it back and we adjust.

---

## Part 1 — Stand up the Headscale coordinator (one always-on host; box A is fine)

Headscale is independent of the CGA app stack. The repo ships its config + compose.

```bash
# On box A (or any always-on host), from the repo checkout:
# 1. Put your Cloudflare zone into the config's server_url.
sed -i 's/<YOUR_DOMAIN>/worldofstatecraft.com/' docker/headscale/config.yaml   # <-- your zone

# 2. Start Headscale (control plane on 127.0.0.1:8080).
docker compose -f docker-compose.headscale.yml up -d
docker compose -f docker-compose.headscale.yml exec headscale headscale configtest

# 3. Front it with Cloudflare Tunnel over HTTPS (run with YOUR token — Claude never sees it):
cloudflared tunnel login                       # opens your browser; authorizes YOUR zone
cloudflared tunnel create cga-headscale
cloudflared tunnel route dns cga-headscale headscale.worldofstatecraft.com
# ~/.cloudflared/config.yml:
#   tunnel: <TUNNEL_ID>
#   credentials-file: /root/.cloudflared/<TUNNEL_ID>.json
#   ingress:
#     - hostname: headscale.worldofstatecraft.com
#       service: http://localhost:8080
#     - service: http_status:404
cloudflared tunnel run cga-headscale           # (or install as a service)

# 4. Sanity: the control plane answers over HTTPS.
curl -fsS https://headscale.worldofstatecraft.com/health && echo OK

# 5. Create a tailnet user + a reusable pre-auth key (admits both boxes).
docker compose -f docker-compose.headscale.yml exec headscale headscale users create cga
docker compose -f docker-compose.headscale.yml exec headscale \
    headscale preauthkeys create --user cga --reusable --expiration 24h
#   → prints a long PRE-AUTH KEY (tskey-...). Used to join both boxes below.
```

> **If Cloudflare Tunnel gives the control plane trouble** (Tailscale's noise
> protocol over CF): the fallback is a `443→Headscale` port-forward on the netgate
> with a real TLS cert (Caddy/Let's Encrypt in front of Headscale). Same `server_url`.
> Or, for a first LAN-only pass, point `server_url` at `http://<boxA-lan-ip>:8080`
> and join clients with `--login-server http://<boxA-lan-ip>:8080` — proves the mesh
> without Cloudflare, then upgrade to the public HTTPS endpoint for the "real remote" run.

---

## Part 2 — Join both boxes to the tailnet

On **box A** and **box B** (Ubuntu):

```bash
curl -fsSL https://tailscale.com/install.sh | sh
sudo tailscale up \
    --login-server https://headscale.worldofstatecraft.com \
    --authkey tskey-...                      # the pre-auth key from Part 1
tailscale ip -4                              # note this box's 100.64.x.x address
```

Record the two addresses, e.g. **A = 100.64.0.1**, **B = 100.64.0.2**. Confirm they see each other:

```bash
# on box B:
ping -c2 100.64.0.1
```

---

## Part 3 — Instance A (the host)

```bash
git clone https://github.com/CosmopolitanCoalition/fair-constitution-app.git
cd fair-constitution-app

# Deploy A, advertising its TAILNET url so B can reach it.
./deploy.sh --prefix fc --nginx-port 8080 --pg-port 5432 --vite-port 5173 \
            --self-url http://100.64.0.1:8080
# (Has demo data already? add --seed to seed institutions:demo-e for a non-empty corpus.)

art() { docker compose -p fc exec -T app php artisan "$@"; }

# A is the HOST → it must enable the mesh itself (deploy.sh only runs federation:init
# automatically when JOINING). This mints A's Ed25519 identity and arms CLK-20.
art federation:init

# Mint a one-use join key for B (PLAINTEXT shown ONCE — copy it).
art cluster:keys:mint --max-uses=1 --expires="+24 hours"
#   → handle.secret      (this is the --key value B will present)

# Confirm A advertises over the tailnet:
curl -fsS http://100.64.0.1:8080/api/federation/identity | tee /dev/stderr
#   → {"server_id":"…","public_key":"…","schema_version":"1","url":"http://100.64.0.1:8080"}
```

---

## Part 4 — Instance B (the fresh box → live mirror) ★ the G-V2 act

On **box B**, from a clean clone:

```bash
git clone https://github.com/CosmopolitanCoalition/fair-constitution-app.git
cd fair-constitution-app

# One command: build, fresh APP_KEY + Ed25519 identity, migrate, seed clocks,
# then adopt A as a read-only mirror and cold-sync A's public corpus.
./deploy.sh --prefix fc --nginx-port 8080 --pg-port 5432 --vite-port 5173 \
            --self-url http://100.64.0.2:8080 \
            --join     http://100.64.0.1:8080 \
            --key      handle.secret
```

`deploy.sh` (verified, lines 88–116) on B: fresh `APP_KEY` (so B never shares A's
ballot/identity keys) → `migrate` → seed `ClockRegistrySeeder` → `federation:init
--rotate` (B's own server_id + keypair) → `cluster:join http://100.64.0.1:8080
--key handle.secret`, which POSTs `/api/federation/adopt`, A pins B as a `mirror`,
B pins A, B cold-syncs A's audit/public-records in signed pages, and B sets
`mirror_of_server_id = A` → **its write-guard now refuses every constitutional filing.**

---

## Part 5 — Verify the gate (G-V2 = green when all pass)

```bash
artA() { docker compose -p fc exec -T app php artisan "$@"; }   # on box A
artB() { docker compose -p fc exec -T app php artisan "$@"; }   # on box B

# 1. Mutual reachability over the tailnet (both return identity JSON):
curl -fsS http://100.64.0.1:8080/api/federation/identity   # from B
curl -fsS http://100.64.0.2:8080/api/federation/identity   # from A

# 2. B is a LIVE mirror of A (the write-guard flag is set):
docker compose -p fc exec -T postgres psql -U fc_user -d fair_constitution \
  -c "SELECT mirror_of_server_id, mirror_adopted_at FROM instance_settings;"        # on B → A's id, a timestamp
docker compose -p fc exec -T postgres psql -U fc_user -d fair_constitution \
  -c "SELECT role,state,admission_method FROM cluster_memberships;"                 # on B → mirror|live|join_key

# 3. Cold-sync drained to completion (no half-sync):
docker compose -p fc exec -T postgres psql -U fc_user -d fair_constitution \
  -c "SELECT status, pages_applied, records_applied FROM sync_cursors;"             # on B → complete, >0, >0

# 4. The mirror is authoritative for NOTHING — a write is refused (the Prong-1 invariant):
artB tinker --execute "try { app(App\Domain\Engine\ConstitutionalEngine::class)->file('F-LEG-001', null, []); echo 'WROTE — BUG'; } catch (\Throwable \$e) { echo 'refused: '.\$e->getMessage(); }"
#   → 'refused: This instance is a read-only mirror…'

# 5. A sees B as a trusted peer:
docker compose -p fc exec -T postgres psql -U fc_user -d fair_constitution \
  -c "SELECT name, url, status, relation FROM federation_peers;"                    # on A → B, trust_established, mirror

# 6. CLK-20 keeps B synced as A writes (file something on A, wait ~1 heartbeat, see it on B):
artA federation:sync:push                 # push A's tail now (or wait for the 5-min CLK-20 tick)
#   then re-check B's sync_log/records.
```

**Authority ≠ leadership invariant** still holds (it always did): a mirror's
`authoritative_server_id` is untouched; this is the permissionless **Prong-1**
read-only mirror, governed elevation to read/write co-membership is the separate
Prong-2 path (`cluster:request-adoption` / governed flip) — out of scope for G-V2.

---

## Teardown / re-run

```bash
artB() { docker compose -p fc exec -T app php artisan "$@"; }
artB cluster:leave                         # B stops being a mirror (write-guard clears)
# Full reset of a box:  docker compose -p fc down -v   (drops its database)
# New key on A:         artA cluster:keys:revoke <handle>  then  cluster:keys:mint again
```

## Troubleshooting cues (paste the failing command's output back)
- `/api/federation/*` returns **404** → that instance hasn't run `federation:init`
  (the host A must; B's deploy did it via `--join`).
- adopt returns **401** (timestamp window) → box clocks skewed > 5 min; `sudo timedatectl set-ntp true`.
- adopt returns **403** (invalid/exhausted key) → the key was one-use and already spent, or expired → mint a new one.
- B can't reach `100.64.0.1:8080` → tailnet not up (`tailscale status`) or A's nginx not bound (`curl` A locally first).
- cold-sync `aborted / continuity_break` → A's corpus changed mid-pull; re-run `cluster:join` (idempotent) or `federation:cold-sync` to resume.

---

### After G-V2 is green
→ **G-V1** (phone GPS) reuses this tailnet + a Cloudflare HTTPS subdomain pointed at instance A,
so the phone browser gets a secure context and on-device geofenced pinging works. Separate runbook.
