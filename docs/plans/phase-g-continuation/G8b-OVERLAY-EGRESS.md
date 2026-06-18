# G8b — Container ⇄ Host ⇄ Overlay Egress (the two-way datapath)

The mesh code is transport-agnostic and complete. The one thing no application code can do
for you is **route packets between the Docker container and the host's overlay daemon** —
that is host networking, and it differs per overlay and per OS. This doc makes it concrete
for a **Windows Box A ⇄ Linux Box B** pair and is overlay-agnostic (Tailscale *and*
Yggdrasil). It is the "RIG-CERTIFIED" step the bootstrap scripts deliberately do not perform
silently.

## The model (why there are two halves)

```
  Box A                                                   Box B
  ┌───────────────────────┐                               ┌───────────────────────┐
  │ app container (bridge)│  ──OUTBOUND──▶  overlay  ──▶  │ host:8080 (0.0.0.0)    │
  │  FederationClient dials│                               │  nginx → app           │
  │  the peer's overlay URL│  ◀──INBOUND───  overlay  ◀──  │  FederationClient dials│
  └───────────┬───────────┘                               └───────────────────────┘
              │ must reach
        host overlay daemon (tailscale0 / ygg tun) — lives on the HOST, not the container
```

- **INBOUND is already solved.** nginx publishes `${NGINX_HOST_PORT}:80` bound to `0.0.0.0`
  ([docker-compose.yml](../../../docker-compose.yml)), so *any* host interface — a Tailscale
  `100.64.x` IP or a Yggdrasil `[200::]` IP — that reaches `host:8080` hits the app. No work.
- **OUTBOUND is the half you wire.** `FederationClient` dials from *inside* the app/horizon
  container (a Docker **bridge**). The overlay address lives on the **host's** interface, so
  the container needs a route to it. What that takes depends on the overlay.

`extra_hosts: host.docker.internal:host-gateway` is now set on app/horizon/scheduler, so the
container can always reach a **host-bound** listener (used by the onion SOCKS path and the
Yggdrasil relay option below).

**Verify either direction, from where the bytes originate:**
```
docker compose -p fc exec app php artisan mesh:doctor http://<the-other-box-overlay-url>:8080
```
`mesh:doctor` dials from inside the container — it is the exact test of whether egress works
(and whether the constitutional_version agrees). Run it on BOTH boxes; both must report the
peer reached.

---

## Option A — Tailscale / Headscale  (recommended for Windows↔Linux)

Tailnet addresses are **IPv4** (`100.64.0.0/10`), which is why this is the low-friction path:
inbound works against the existing IPv4 `0.0.0.0` publish with zero extra config, and
WireGuard + DERP punch through double-NAT automatically.

**Linux Box B (egress works almost for free):** once the *host* has joined the tailnet, a
bridge container's packet to `100.64.x` goes to the docker gateway → the host → routed out
`tailscale0`, and Docker's default masquerade SNATs it. No compose change needed. (If your
host has `ip_forward` disabled, enable it.)

**Windows Box A (the one real wrinkle):** Docker Desktop runs the container in a **WSL2 VM**,
so the Windows-host Tailscale tun is not automatically in the container's network namespace.
Two ways to bridge it:
1. **Docker Desktop mirrored networking** (simplest): Settings → Resources → Networking →
   *Mirrored* (Windows 11 + recent Docker Desktop). The WSL2 VM then shares the Windows host
   network, so the container sees the Windows Tailscale interface. Restart Docker Desktop.
2. **Tailscale inside WSL2**: install + `tailscale up` *in the WSL2 distro* Docker uses, so
   the tun lives in the same namespace as the container's host.

**Headscale coordinator:** if self-hosting the control plane, bring it up with
`docker-compose.headscale.yml` and front it with public HTTPS (Cloudflare Tunnel) per
`docs/plans/institutions/PHASE_G_V2_TAILNET_RUNBOOK.md` — that path is already G-V2-certified.

Self-advert / `FEDERATION_SELF_URL` = `http://100.64.<this-box>:8080`.

---

## Option B — Yggdrasil  (no coordinator, but IPv6 — more plumbing)

Yggdrasil gives each node a self-routing `[200::]/7` **IPv6** address with no coordinator —
ideal in principle for double-NAT, but the Docker bridge has **no IPv6**, so container egress
to `[200::]` needs work, and Docker doesn't publish IPv6 inbound by default.

**Inbound (IPv6 publish):** enable Docker IPv6 and bind the publish to the overlay, or front
nginx with a host-side listener on the ygg interface. Without this a remote peer hitting
`http://[200::a]:8080` won't reach nginx even though the host can.

**Outbound (container → `[200::]`):** pick one —
1. **Host relay (simplest, OS-agnostic):** run a tiny host-side forwarder the container
   reaches via `host.docker.internal`, e.g.
   `socat TCP-LISTEN:9443,fork,reuseaddr TCP6:[<peer-ygg>]:8080` on the host, then advertise/
   dial `http://host.docker.internal:9443`. No Docker IPv6 needed; uses the host-gateway entry.
2. **Docker IPv6 bridge + route** to the host `tun0` (advanced; enable `ipv6` on the daemon +
   a network with an IPv6 subnet + a route to `200::/7`).

**Windows Box A:** same WSL2 caveat as Tailscale — the ygg daemon (or the relay) must live in
(or be reachable from) the WSL2 VM, not just Windows.

Self-advert / `FEDERATION_SELF_URL` = `http://[200::<this-box>]:8080` (or the relay URL).

---

## Onion (hardened / private, either pair)

`mesh-catalog.json` sets `CGA_FEDERATION_SOCKS_PROXY=socks5h://host.docker.internal:9050` —
the Tor daemon runs on the **host**, and the container reaches it via the host-gateway entry
(NOT `127.0.0.1`, which is the container's own loopback). `FederationClient::proxyFor` routes
only `.onion` through it; everything else dials direct.

---

## Bottom line

| | Tailscale | Yggdrasil |
|---|---|---|
| Inbound | works on existing IPv4 publish | needs Docker IPv6 / host listener |
| Linux egress | host route + masquerade (free) | host relay or Docker IPv6 |
| Windows egress | mirrored networking *or* Tailscale-in-WSL2 | same WSL2 caveat + IPv6 |
| Double-NAT traversal | automatic (WireGuard+DERP) | self-routing, volunteer peers |
| Coordinator | yes (Headscale, HTTPS-fronted) | none |

For the Windows-A/Linux-B test, **Tailscale is the path of least resistance**; Yggdrasil is
the no-coordinator fallback. Either way, `mesh:doctor <peer-url>` from inside the container is
the gate that proves the datapath before you run the runbook's six certification gates.
