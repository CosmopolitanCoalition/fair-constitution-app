# Voice / SFU (LiveKit)

Live voice/video runs through a **LiveKit** SFU (the MatrixRTC selective-forwarding unit). The
hard part is **ICE networking**: the SFU runs in a bridge-network container that only sees its
internal `172.x` address, so it must be told a **host-reachable IP** to advertise as the ICE
candidate, or media never flows.

## Knobs

| Knob | Where | Tier | Notes |
|---|---|---|---|
| SFU URL (internal) | `LIVEKIT_URL` (`config/matrix.php` → `livekit.url`) | restart | Docker-internal address the appservice mints tokens against. |
| SFU URL (browser) | `LIVEKIT_PUBLIC_URL` | restart | The `wss://…:7443` URL a remote browser dials. Must terminate TLS (a LAN IP over plain `http` blocks mic/cam in the browser). |
| ICE node IP | `LIVEKIT_NODE_IP` (`docker-compose.yml` → `--node-ip`) | restart | The host-reachable IP advertised for ICE. `127.0.0.1` for a localhost box; the LAN IP for a phone/peer on the LAN. |
| `use_external_ip` | `docker/livekit/livekit.yaml` | restart | `true` = STUN-discover the **public** IP at runtime (no hardcoded IP, self-correcting) — for an internet-facing box behind a port-forward. Mutually exclusive with `--node-ip`. |
| Ports | `docker/livekit/livekit.yaml` + `docker-compose.yml` | restart | `7880` signalling, `7881/tcp` fallback, single muxed `7882/udp` media (the high 50000-range collides with Windows/Hyper-V reserved ports). |
| API key / secret | `LIVEKIT_API_KEY` / `LIVEKIT_API_SECRET` | restart | The appservice signs join tokens with the secret. **Ship the `cga_dev_*` defaults only in dev** — the console flags them "dev default — rotate". |

## The two networking modes

- **LAN / solo (default):** `--node-ip <LAN-or-127.0.0.1>` in `docker-compose.yml`, `use_external_ip:
  false`. Good for same-LAN devices and localhost testing.
- **Internet-facing:** set `use_external_ip: true`, **drop** the `--node-ip` flag, and port-forward
  `443`, `7443`, `7882/udp`, `7881/tcp` to this box. LiveKit STUN-discovers its public IP; an
  off-LAN client needs nothing, own-LAN devices need NAT reflection (or split-DNS).
- **Linux "configure once":** `network_mode: host` lets LiveKit auto-advertise all host interfaces
  with no `--node-ip`. (Docker Desktop on Windows/macOS is the exception — its VM hides the host LAN
  interface, so the explicit IP is required there.)

## Bringing it up

1. Start the SFU: `docker compose --profile voice up -d livekit`.
2. Establish the **`voice.sfu`** channel (console → Voice section, or `mesh:role qualify/request/approve voice.sfu`). Without it, token minting degrades to 503.
3. Set `LIVEKIT_PUBLIC_URL` to a browser-reachable `wss://` (TLS via the `tlsproxy` service on `:7443`).
4. Recreate: `docker compose up -d --force-recreate livekit`.

## Ports that must be open to the internet (not just forwarded)

`443` (app/HTTPS), `7443` (wss signalling), `7882/udp` (media), `7881/tcp` (media fallback). On a
Windows host the inbound default is *block* — add a firewall rule on the **host** (not clients):

```powershell
New-NetFirewallRule -Direction Inbound -Protocol TCP -LocalPort 443,7443,7881 -Action Allow -Profile Private
New-NetFirewallRule -Direction Inbound -Protocol UDP -LocalPort 7882 -Action Allow -Profile Private
```

**Never expose** the internal ports `8081/8008/8090/5174/5432` to the internet.
