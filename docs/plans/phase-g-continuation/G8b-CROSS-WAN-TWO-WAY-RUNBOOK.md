# G8b — Cross-WAN Two-Way Mesh Runbook (rig procedure)

**Status:** code complete + dev-stack tested on `main`; this is the **physical-rig**
procedure to certify the two-way survival mesh. I own the runbook; the operator executes
it on the lab boxes (the host-daemon + real-WAN steps cannot be certified on
Docker-Desktop — they inherit the native-arm64/Linux fault class that produced the six
G-V2 deploy blockers).

## Topology

```
Box A (mobile)                                Box B (home anchor)
laptop → travel router → hotspot → internet → home firewall → Box B
        DOUBLE NAT, no inbound ports                 LAN, no inbound ports opened
```

The point: **two-way** comms with **no port-forwarding on either side**. A NAT-traversing
overlay (Yggdrasil — self-routing IPv6, or a Headscale tailnet) makes Box A *inbound-
reachable* so Box B can call it back. Box A is NOT a read-only mirror; the multiplex +
overlay is the mechanism that carries S2S in both directions.

## 0. Prerequisites (both boxes)

- Docker + Docker Compose, a clone of `main`, pwsh 7 (Windows) or bash + `jq` (Linux/macOS).
- Decide the overlay: **Yggdrasil** (no coordinator, ideal for double-NAT) or **tailnet**
  (needs the Headscale control plane from `docker-compose.headscale.yml`). This runbook uses
  Yggdrasil as the survivor and HTTPS as the LAN-side fast path.

## 1. Box B — the home anchor (genesis)

```bash
# bring the app up + pick transports (https for LAN, yggdrasil for the WAN survivor)
./bootstrap/bootstrap.sh --profile public-anchor-node --prefix fc --nginx-port 8080
#   when prompted: include https (LAN url) + yggdrasil; confirm the yggdrasil install;
#   read the [200::]/7 address and give it as the yggdrasil self-advert.
```

- Bootstrap now runs `federation:init` for you (mints the identity + opens the mesh
  endpoints). Separately, found the instance (operator + genesis) via the setup wizard so
  there is a jurisdiction to govern and publish into the directory.
- Confirm the transports registered: `docker compose -p fc exec app php artisan transport:list`
  → expect `https` + `yggdrasil`, both enabled.
- Note Box B's `server_id` (`GET /api/federation/identity`) and its yggdrasil URL
  `http://[200:…]:8080`.

## 2. Box A — the mobile node (on the hotspot)

```bash
./bootstrap/bootstrap.sh --profile secure-default --prefix fc --nginx-port 8080
#   include yggdrasil (the double-NAT survivor); optionally https (will only work LAN-side).
```

- Read Box A's yggdrasil address the same way.

## 3. Establish two-way reachability (the load-bearing step)

1. Peer the two Yggdrasil daemons (add each other, or a shared public peer) until each can
   ping the other's `200::/7` address — **from both directions**. Wiring the route from the
   app *container* to the *host* overlay daemon is per
   [G8b-OVERLAY-EGRESS.md](G8b-OVERLAY-EGRESS.md) (Tailscale is the lower-friction option for
   a Windows↔Linux pair; Yggdrasil needs the relay/IPv6 step there).
2. Triage with the read-only diagnostic before any handshake (`mesh:doctor <url>` does the
   same probe over a peer's whole transport ladder, and bootstrap runs it automatically):
   ```bash
   # on Box A, dialling Box B over the overlay:
   docker compose -p fc exec app php artisan federation:peer:check 'http://[<box-B-ygg>]:8080'
   # on Box B, dialling Box A over the overlay (proves INBOUND reachability of A):
   docker compose -p fc exec app php artisan federation:peer:check 'http://[<box-A-ygg>]:8080'
   ```
   Both must report reachable + matching `constitutional_version`. **Box B reaching Box A is
   the two-way proof** — it works only because the overlay gave A an inbound-routable address.

## 4. Peer → handshake → trust (both directions)

- From Box A: `federation:peer:discover http://[<box-B-ygg>]:8080` then
  `federation:peer:handshake http://[<box-B-ygg>]:8080` (or the
  /federation console). The handshake now **advertises + learns transports** (C3), so Box A
  learns Box B's https + yggdrasil and vice-versa — the multiplex ladder is populated on both
  sides before any clearnet failure.
- Confirm `transport:list` / the peer row shows the learned transports for the other server.

## 5. Sync both ways

- The CLK-20 heartbeat (every 5 min) pushes each side's FF&C tail and drains any open cold
  cursor. Force a tick or wait. Confirm `sync_log` shows OUTBOUND `applied` on both boxes and
  the public-record counts converge.
- A fresh Box A cold-syncs Box B's public corpus in bounded signed pages (resumable).

## 6. Certification gates (what the rig proves that the dev stack cannot)

1. **Two-way handshake over the overlay** — both `peer:check`s green; both peers
   `trust_established`; transports learned symmetrically. *(C3)*
2. **Multiplex failover** — with both up, take the **clearnet/https path down** on Box B
   (stop nginx's public exposure / block 8080). Box A's next push/sync must **survive over
   yggdrasil** with no error (the survival-mesh promise). Bring https back; the CLK-20 probe
   (`probeUnhealthy`) must re-close the recovered https circuit within a tick. *(C2/C4)*
3. **Meter C divergent-version refusal** — bump Box A to a different `constitutional_version`
   (a `constitutional_bump` proposal, ratified locally). Box B's `pushTo`/cold-sync to A must
   **refuse** with `constitutional_version_mismatch` (fail-closed); the heartbeat liveness ping
   keeps flowing. *(G-VER 4)*
4. **Peer-consent delivery** — open a co-affecting upgrade proposal on Box B; from Box A run
   `federation:upgrade:consent <proposal> <box-B-server-id>`. Box B records the Meter C consent
   (refuses if A is not authoritative for a co-affected subtree). *(A2)*
5. **Nearest-node routing** — `GET http://<box-B>/api/mesh/nearest?jurisdiction=<id>` returns a
   ranked node list, `Cache-Control: no-store`, and writes **no `location_pings`** row. *(C5)*
6. **Authority flip** *(pre-existing)* — Box B hands A a partition subtree; A becomes
   authoritative; the directory + forwarding follow.

Record each gate pass/fail; a fail is a rig-surfaced blocker to fix on `payne` and re-push,
exactly as the G-V2 deploy rounds worked.

## 7. Teardown

`docker compose -p fc down` on each box (add `-v` to wipe state for a clean re-cert). Stop the
Yggdrasil daemons if they were installed for the test.

---

**Cross-references:** transport seam + multiplex = `G8b-transport-survival-mesh.md`; the
deploy contract = `deploy.{sh,ps1}`; the bootstrap layer = `bootstrap/README.md`; the
versioning gates = the G-VER slices (memory `project_phase_g_continuation`).
