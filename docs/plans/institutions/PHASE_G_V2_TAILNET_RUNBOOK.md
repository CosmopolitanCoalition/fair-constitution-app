# Phase G — Headscale / Tailscale tailnet: the overlay-transport runbook

> **Scope.** This is the **live overlay-transport substrate** for the mesh: it stands up a private
> Headscale/Tailscale WireGuard overlay so two CGA boxes reach each other on `100.64.x.x` IPs with
> **no inbound ports opened on the netgate**. G8b (transport-survival mesh, overlay egress) and the
> rig campaign build on this and reference it as the canonical Headscale setup — it stays part of the
> build.
>
> **Superseded — the round-1 mirror-join is gone from this runbook.** This file originally also drove a
> read-only-**mirror** onboarding (`cluster:join` adoption — the "G-V2 gate"). That onboarding is
> **replaced by the current multibox mesh-roles campaign**: once both boxes are on the tailnet (Parts
> 1–3 below), you onboard them with the discover → handshake → sync flow in **`docs/FRESH-NODE-START.md`**,
> not a mirror-join. The mirror-adoption steps have been removed here; only the transport setup remains.
> (The read-only-mirror capability itself still lives in the codebase — `cluster:join`,
> `app/Services/Mirror/*` — it is simply no longer the onboarding path.)

**Who does what.** Claude owns this runbook + the config files in-repo (`docker-compose.headscale.yml`,
`docker/headscale/config.yaml`). **You execute every step** — Headscale, the Tailscale clients,
Cloudflare. **Never paste your Cloudflare API token (or any key) into the chat.** Keep it on your
machines; the commands below use it locally and Claude never sees it. Run a step, paste the *output*
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
   Box A (100.64.0.1)                                  Box B (100.64.0.2)
   ┌──────────────────────┐     signed federation      ┌──────────────────────┐
   │ CGA instance A        │◀──── HTTP over tailnet ───▶│ CGA instance B        │
   │ nginx :8080           │  (discover/handshake/sync  │ nginx :8080           │
   │ FEDERATION_SELF_URL=   │   per FRESH-NODE-START.md) │ FEDERATION_SELF_URL=  │
   │  http://100.64.0.1:8080│                          │  http://100.64.0.2:8080│
   └──────────────────────┘                          └──────────────────────┘
```

All commands below are verified against the source. The Headscale config targets 0.23.x; CLI/flags
drift between versions — if a command errors, paste it back and we adjust.

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

## Part 3 — Deploy each box advertising its tailnet URL

Deploy A and B from a clean clone, each advertising **its own tailnet IP** as its self-url so the
other reaches it over WireGuard (no netgate ports):

```bash
git clone https://github.com/CosmopolitanCoalition/fair-constitution-app.git
cd fair-constitution-app

# On box A (100.64.0.1):
./deploy.sh --self-url http://100.64.0.1:8080
# On box B (100.64.0.2):
./deploy.sh --self-url http://100.64.0.2:8080
```

Then bring each box up and mint its identity by following **`docs/FRESH-NODE-START.md` Part 1**
(`mesh:gates` → `mesh:doctor` prints the `server_id`). Once both have an identity, confirm each
advertises over the tailnet (both return identity JSON):

```bash
curl -fsS http://100.64.0.1:8080/api/federation/identity   # from B
curl -fsS http://100.64.0.2:8080/api/federation/identity   # from A
#   → {"server_id":"…","public_key":"…","schema_version":"1","url":"http://100.64.x.x:8080"}
```

> **Onboarding from here is the multibox campaign, not a mirror-join.** With both boxes reachable on
> their `100.64.x.x` URLs, follow **`docs/FRESH-NODE-START.md` Part 2 onward** (discover → handshake →
> sync). Use each box's tailnet URL wherever the guide says `<OTHER-URL>`.

---

## Troubleshooting cues (paste the failing command's output back)
- `/api/federation/*` returns **404** → that instance hasn't minted its identity yet
  (run `mesh:gates` / `mesh:doctor` per FRESH-NODE-START.md Part 1; a `--join` deploy does it
  automatically).
- A box can't reach `100.64.0.1:8080` → tailnet not up (`tailscale status`) or the target's nginx not
  bound (`curl` it locally first).
- handshake **401** (timestamp window) → box clocks skewed > 5 min; `sudo timedatectl set-ntp true`.
- cold-sync `aborted / continuity_break` → the source corpus changed mid-pull; re-run the sync step
  (idempotent) to resume.

---

### Related
- **Onboarding / sync (the current path):** `docs/FRESH-NODE-START.md` — discover → handshake → sync,
  the multibox mesh-roles campaign.
- **Transport mesh:** `docs/plans/phase-g-continuation/G8b-transport-survival-mesh.md`,
  `G8b-OVERLAY-EGRESS.md` — this tailnet is one of the overlay substrates they select among.
- **G-V1** (phone GPS) reuses this tailnet + a Cloudflare HTTPS subdomain pointed at an instance, so
  the phone browser gets a secure context for on-device geofenced pinging. Separate runbook.
