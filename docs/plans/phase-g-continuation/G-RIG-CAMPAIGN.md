# Phase G — Rig-Certification Campaign (roles · order · handoffs · halts)

> The single coordination list for finishing Phase G's real-world gates. The per-test
> procedures live in the runbooks this doc sequences; this doc adds **who does what, in
> what order, where to halt, and who to report to.** `main` is at the commit that carries
> the G5/G5a flip wiring + the Matrix K-3 design (a6e131f).

## Roles (the four owners + the symbol legend)

| Tag | Owner | Scope (from the operator's division of labor) |
|---|---|---|
| 👤 **OP** | Operator | **Hardware**: boxes, power, LAN/WAN, **firewall ports, DNS, TLS certs**, the phone, travel router/hotspot; **host-level/GUI** changes on the Windows PC (Docker Desktop settings, installing the Windows host overlay daemon). |
| 🤖 **GA** | General Assistant | **All software on the Pi**: `git pull`, `deploy.sh`, artisan/CLI, `.env`, host-daemon install on the Pi's Linux. |
| 💻 **DEV** | Statecraft Developer (me) | **All code changes** in the repo. Also executes **tool-doable software on the Windows PC** (docker compose, `.env`, artisan, git) — I run on `payne`. |
| 👤/💻 | OP **or** DEV | Windows-PC software: DEV does what the tools can; OP does GUI/host-level (the user's rule: "me unless you or another session can handle it"). |

**Reporting rule:** test results → reported to **OP** for go/no-go. A **code-fixable** failure
routes to **DEV**, who fixes on `payne` + pushes; **GA** then `git pull`s the Pi — exactly the
G-V2 deploy-round loop. **HALT** markers are hard gates: do not start the next leg until the
named gate is green.

## What is already proven (do NOT re-test)

- Founder + population path on a real arm64 Pi (G-V2 + ETL) — certified zero-touch.
- Path-3 mesh-join on the Pi (CLI: init → transport:register → discover/handshake → mesh:doctor → sync:push) — all `mesh:gates` green.
- G5/G5a seal + re-wrap + fail-closed — **dev-stack** proven (AutonomyFlipRewrapsKeysTest, 5 pins) + adversarially certified.
- The 6 cross-WAN gates + Patroni failover + LeaderProbe — **dev-stack/unit** proven; this campaign certifies them on **real hardware**, which the dev stack cannot.

## Topology for this campaign

```
  Box B = home anchor (the Pi, 🤖 GA)            Box A = second instance (Windows payne 💻/👤, or a 2nd Ubuntu)
  on the LAN; https fast-path + overlay survivor   on the LAN or a hotspot (double-NAT) for the survival-mesh gates
  Patroni HA: 👤 OP supplies 2 Ubuntu boxes (etcd+patroni1+haproxy / patroni2) + the app box
  G-V1 mobile: 👤 OP supplies the Android phone (OnePlus 8 Pro, no-SIM)
```

## Ordering (dependency graph)

```
LEG 0 prep ──▶ LEG 1 (LAN two-way + 4 LAN-safe gates) ──▶ LEG 2 overlay egress ──▶ LEG 3 (2 WAN/survival gates)
      │                                                                                      
      ├──▶ LEG 4 Patroni HA (independent; any time after LEG 0)                              
      ├──▶ LEG 5 G5/G5a live flip handover (needs DEV code-prereq P1; runs on the LEG-1 pair)
      └──▶ LEG 6 G-V1 native mobile (independent; needs DEV code-prereq P2 + the phone)      
```
LEG 4, 5, 6 are independent of the cross-WAN legs and of each other — run them whenever their
owner + kit are available. **LEG 1 → 2 → 3 is the one hard chain** (each needs the prior green).

---

## LEG 0 — Prep: both nodes on `main`, clean, reachable
**Objective:** two healthy instances on the same commit, on the LAN.
- 💻 DEV: already pushed `main`. (done)
- 🤖 GA (Pi): `git pull` → `main`; `./deploy.sh` (founder already exists, so re-deploy/up); confirm boots, `php artisan mesh:gates` green-ish (federation may be closed until LEG 1).
- 💻 DEV/👤 OP (Windows): stand up a second instance on `payne` — `./deploy.ps1 -Prefix fcb -NginxPort 8082` (or use a 2nd Ubuntu box, 👤 OP) — fresh APP_KEY + identity.
- 👤 OP (hardware): both boxes powered, same LAN, note each LAN IP.
- **GATE 0:** both instances serve `GET /api/federation/identity` (200) on their LAN IP.
- **HANDOFF:** GA reports Pi ready → DEV confirms the second instance → **report to OP** that the pair is up.

## LEG 1 — Two-way mesh handshake + the 4 LAN-safe gates
**Runbook:** `G8b-CROSS-WAN-TWO-WAY-RUNBOOK.md` §1–§4 + gates 1,3,4,5,6 (https/LAN path).
**Objective:** prove two-way trust + the gates that don't need the WAN survivor yet.
- 👤 OP (hardware): ensure the two boxes can reach each other's `:8080`/`:8082` on the LAN.
- 🤖 GA (Pi) + 💻 DEV (Windows): set each `FEDERATION_SELF_URL` to the box's LAN IP; `federation:peer:check` each other (both reachable + `constitutional_version` match); `federation:peer:discover` → `federation:peer:handshake` both directions; confirm `transport:list` learned the peer's transports.
- Then run, over the LAN https path:
  - **Gate 1** two-way handshake (both `trust_established`, transports learned symmetrically).
  - **Gate 3** Meter-C divergent-version refusal: 💻 DEV ratifies a local `constitutional_bump` on one box; the other's `pushTo`/cold-sync **refuses** `constitutional_version_mismatch` (fail-closed) while liveness ping still flows. *(then DEV reverts the bump)*
  - **Gate 4** peer-consent: open a co-affecting upgrade proposal on Box B; from Box A `federation:upgrade:consent <proposal> <box-B-id>`; Box B records the Meter-C consent.
  - **Gate 5** nearest-node: `GET /api/mesh/nearest?jurisdiction=<id>` → ranked list, `Cache-Control: no-store`, **no `location_pings` row written**.
  - **Gate 6** authority flip: Box B hands Box A a subtree; A becomes authoritative; directory + forwarding follow.
- **GATE 1:** all five (1,3,4,5,6) green over the LAN.
- **HANDOFF / HALT:** per-gate pass/fail → **report to DEV**. Any fail = DEV fixes on `payne` + pushes, GA pulls, re-run. **Do not start LEG 3** (the survival gate) until LEG 2's overlay is up.

## LEG 2 — Overlay egress datapath (the load-bearing two-way step)
**Runbook:** `G8b-OVERLAY-EGRESS.md`. **Tailscale is the path of least resistance** for a Windows↔Linux pair.
**Objective:** give each box an inbound-routable overlay address so Box B can call Box A back with **no port-forwarding.**
- 👤 OP (hardware/account): create the tailnet (or self-host Headscale per `PHASE_G_V2_TAILNET_RUNBOOK.md` — already G-V2-certified); install Tailscale on each **host**; on the **Windows** box enable **Docker Desktop → Networking → Mirrored** (or `tailscale up` inside the WSL2 distro) — this is the one real Windows-side wrinkle, 👤 OP/GUI.
- 🤖 GA (Pi): `tailscale up` on the Pi; set `FEDERATION_SELF_URL=http://100.64.<pi>:8080`.
- 💻 DEV (Windows): set `FEDERATION_SELF_URL=http://100.64.<win>:8082` in `.env`; recreate the app container.
- **GATE 2:** `php artisan mesh:doctor http://100.64.<peer>:<port>` **from inside the container** reports the peer reached **on BOTH boxes** (this is the egress proof).
- **HANDOFF / HALT:** GA + DEV report `mesh:doctor` green both ways → **report to OP**. If egress fails, it is usually host/OS (👤 OP) not code; if `mesh:doctor` shows a code error, route to 💻 DEV.

## LEG 3 — The survival-mesh gate (multiplex failover over the overlay)
**Runbook:** `G8b-CROSS-WAN-TWO-WAY-RUNBOOK.md` gate 2. **Needs LEG 2 green.**
**Objective:** prove the network survives a clearnet outage.
- 💻 DEV/👤 OP: with both boxes up + peered over https **and** the overlay, take Box B's **clearnet/https path down** (stop nginx's public bind / 👤 OP blocks `:8080` at the firewall).
- 🤖 GA + 💻 DEV: Box A's next push/sync must **survive over the overlay** with no error; bring https back; the CLK-20 probe (`probeUnhealthy`) must **re-close the recovered https circuit within one tick** (~5 min).
- **GATE 3:** failover survives + auto-recovers.
- **HANDOFF / HALT:** → **report to DEV** (code) + **OP** (go/no-go). A fail here is the survival-mesh promise → DEV-fixable.

## LEG 4 — Patroni HA leader-kill failover (independent)
**Runbook:** `PHASE_G_PATRONI_HA_RUNBOOK.md`. **Independent of LEG 1–3.**
**Objective:** prove leadership flips with **authority unchanged** + scheduler fires once.
- 👤 OP (hardware): 2 Ubuntu boxes (box-A: etcd+patroni1+haproxy; box-B: patroni2) + the app box. *(First a one-box smoke test is fine.)*
- 🤖 GA/👤 OP (software on those boxes): `docker compose -f docker-compose.ha.yml up` the etcd/patroni/haproxy set; `patronictl list` shows one Leader + one Replica.
- 💻 DEV/👤 OP (app box): point `.env` `DB_HOST`=HAProxy, `DB_PORT=5440`; migrate + seed clocks.
- Run the failover sim: record baseline (`isPrimary=true timeline=1`) → `docker kill` the leader → patroni2 promotes (TL 2) → app follows (`isPrimary=true timeline=2`) → **owned-jurisdiction count unchanged** → heal (`pg_rewind` rejoins old leader as replica). Verify exactly-one-scheduler-leader with two `schedule:work` procs.
- **GATE 4:** timeline 1→2, authority unchanged, clock sweep fires once.
- **HANDOFF / HALT:** → **report to DEV** + **OP**. *(DEV code follow-up, only if the lab runs ≥2 real nodes: the deferred `cluster_nodes` follower-naming map + 3-node etcd quorum config — flagged, not blocking.)*

## LEG 5 — G5/G5a live two-instance flip handover (needs DEV code-prereq **P1**)
**Objective:** prove election ballot keys travel WITH authority across two real instances.
- 💻 DEV **CODE-PREREQ P1** (dev-stack-buildable now, Http::fake unit-tested, then rig-verified):
  (a) the **outbound transport caller** — on `finalize`, push the sealed bundle to the gaining peer's `POST /api/federation/flip/operational` over the multiplex; (b) a **production trigger** that fires `LocalAutonomyService::finalize` from a governed/operator action. *(Built on your go; until then the flip seals + returns the bundle but nothing transmits it.)*
- Then on the LEG-1 pair: seed a real counted election in a subtree, run the governed flip on Box B (losing), confirm Box A (gaining) `/flip/operational` re-wraps + **reproduces the certified `record_hash`**; corrupt-bundle case → fail-closed 422.
- 🤖 GA (Pi side) + 💻 DEV (Windows side) drive; the flip trigger = 👤 OP (governed action) or DEV (CLI).
- **GATE 5:** gaining instance re-counts to the identical certified hash; losing side audited; fail-closed path returns 422.
- **HANDOFF / HALT:** → **report to OP** (this exercises the protected ballot path live; OP sign-off).

## LEG 6 — G-V1 native mobile + on-device GPS (independent; needs DEV code-prereq **P2** + the phone)
**Objective:** native GPS → the unchanged `location_pings → ancestor-sweep → residency_confirmations` pipeline, signed on-device via G-ID. **Build WITH the device in the loop — never blind.**
- 💻 DEV **CODE-PREREQ P2**: scaffold the Capacitor wrap (config, native-GPS plugin → the existing ping API, on-device G-ID device-key signing). Built iteratively against the live device, not ahead of it.
- 👤 OP (hardware): the Android phone; an HTTPS path (DNS/cert via the rig) only if a secure-context feature needs it; sideload/install the wrap (or DEV via `adb` if a session can drive it).
- 👤 OP: walk/mock-locate test feeding native GPS.
- **GATE 6:** device GPS pings land in `location_pings` → derive `residency_confirmations`; on-device signing verifies.
- **HANDOFF / HALT:** → **report to OP** + **DEV** (the wrap is DEV code; device behavior is OP).

## LEG 7 — Founder/ETL clean cold-cycle re-cert on `main` (quick, 🤖 GA)
**Objective:** confirm the founder+population path is still zero-touch on the current `main`.
- 🤖 GA (Pi): `down -v` → `git pull` `main` → `./deploy.sh --with-etl` from a clean clone; drive the wizard founder→cosmic→constants→map-data(one country)→districts→seat; confirm `setupComplete:true`.
- **GATE 7:** zero-touch (rc=0, no manual patches) — re-confirms the libexpat1 + casing + sqlite-default fixes hold together.
- **HANDOFF:** → **report to DEV** (any fresh-clone fault = DEV-fixable, the established loop).

---

## DEV code prerequisites (mine — "the rest of Phase G" code)
| ID | What | Blocks | Dev-stack-buildable now? |
|---|---|---|---|
| **P1** | outbound transport push of the G5 sealed bundle (`finalize` → peer `/flip/operational` over multiplex) + a governed production trigger for `finalize` | LEG 5 | **Yes** (Http::fake two-instance unit test), then rig-verified in LEG 5 |
| **P2** | Capacitor native-mobile wrap (native GPS → ping API + on-device G-ID signing) | LEG 6 | Scaffold yes; must be finished **with the device** (LEG 6) |
| **P3** *(opt)* | Patroni `cluster_nodes` follower-naming map + 3-node etcd quorum config | a fuller LEG 4 | Yes, but only worth it once the lab runs ≥2 real nodes |

## Ownership at a glance
| Leg | 👤 OP (hardware/host) | 🤖 GA (Pi sw) | 💻 DEV (code / Win sw) | Report to |
|---|---|---|---|---|
| 0 prep | boxes + LAN | Pi deploy | 2nd instance | OP |
| 1 LAN gates | LAN reachability | Pi CLI | Win CLI, gate-3 bump | DEV |
| 2 overlay | tailnet + Win host/Docker GUI | Pi `tailscale up` + self-url | Win self-url + recreate | OP |
| 3 survival | firewall block | Pi push/sync | code on fail | DEV+OP |
| 4 Patroni | 2–3 Ubuntu boxes | HA up (if Pi/Ubuntu) | app `.env` + sim | DEV+OP |
| 5 flip live | (trigger) | Pi side | **P1 code** + Win side | OP |
| 6 mobile | the phone + install | — | **P2 code** | OP+DEV |
| 7 cold-cycle | — | the cold cycle | fix on fail | DEV |

---

## Fallback — vertically integrate Phase K while waiting on hardware/spacetime
If the rig/device isn't immediately available, **DEV builds Phase K** (which the Matrix round
showed mirrors exactly what we're certifying), in this order — all dev-stack-buildable, each its
own design-round → plan → build with constitutional pins, the A–G discipline:
1. **K-1 — Civic Record plane** (append-only `social_*` + `public_records` testimony bridge, halls of governance). No Matrix, no rig; the constitutionally-compelled part.
2. **K-2 — Education + Learn Area** (point-of-use civic education, server-graded).
3. **K-3 — The Mesh Commons (Matrix), single-instance**: homeserver (Dendrite for the Pi) + the CGA appservice + the **v12 immutable-creator power-clamp** + local OIDC IdP + the legitimacy-gated moderation flip. Single-instance is dev-stack-buildable; **cross-instance Matrix S2S is rig-gated like the cross-WAN legs above** — so K-3's federation half slots into a future rig session alongside LEG 3.

The cross-WAN rig legs (1–3) and K-3's federation are the **same kind of two-instance proof**, so
proving the mesh now de-risks K-3 later — and building K-1/K-2/K-3-single-instance while the rig is
unavailable keeps forward motion with zero idle time.
