# Multibox Campaign — Phase G + Phase K (the coordinated three-actor runbook)

> One ordered plan that finishes the real-world certification for BOTH the Phase G federation mesh AND
> the Phase K Matrix mesh — on the same two-box rig, in one sitting. The per-test PROCEDURES live in the
> runbooks this doc sequences (`G-RIG-CAMPAIGN.md`, `G8b-CROSS-WAN-TWO-WAY-RUNBOOK.md`,
> `G8b-OVERLAY-EGRESS.md`, `PHASE_G_PATRONI_HA_RUNBOOK.md`, `K3-N-RIG-CAMPAIGN.md`). THIS doc adds the
> thing those don't: **who acts, in what order, and exactly when each AI must STOP and hand off to the
> Operator before testing can resume.**

## The three actors

| Tag | Role (your names) | Owns |
|---|---|---|
| 💻 **DESIGNER** | Box A / Dev — the **Statecraft Designer** (this repo's session) | ALL code in the repo; tool-doable software on Box A (docker compose, `.env`, `artisan`, `git`). Box A = the **authoritative** instance. |
| 🤖 **ASSISTANT** | Box B / Pi — the **General Assistant** | ALL software on the Pi: `git pull`, `deploy.sh`, `artisan`/CLI, `.env`, Linux host daemons on the Pi. |
| 👤 **OPERATOR** | You — **Physical Infrastructure & Out-of-Reach Host Tasks** | Hardware, power, LAN/WAN, **firewall ports, DNS, TLS certs, the Synapse admin token**, the phone, the Ubuntu HA boxes, Docker-Desktop GUI on Windows, killing processes, blocking ports. AND the **coordination hub** — the two AIs never talk directly; they relay through you. |

## The handoff protocol (this is the part you asked for)

The two AIs cannot see each other. **You are the relay.** Every step below ends in exactly one of:

- **▶ proceed** — the AI keeps going on its own box, no human needed.
- **🛑 NEED OPERATOR** — the AI STOPS and posts: *"NEED OPERATOR: ⟨exact physical task⟩. I will resume when you reply ⟨exact condition / value⟩."* You do the task, then reply with the word/value it asked for. The AI resumes.
- **🔁 RELAY** — the AI needs a value FROM the other box (a `server_id`, a LAN IP, a peer id, a gate result). It posts: *"RELAY: ask ⟨other AI⟩ for ⟨value⟩ and paste it here."* You copy it from the other AI's box and paste it back. (Tip: keep both AI chats open side-by-side.)
- **📋 REPORT** — a gate passed/failed. The AI posts the result to you for **go/no-go**. A **code-fixable** fail → 💻 DESIGNER fixes on Box A + `git push`; 🤖 ASSISTANT `git pull`s the Pi; re-run. This is the standard loop.

**HALT markers (⛔) are hard gates** — do not start the next dependent step until the named gate is green.

---

## PHASE 0 — Operator pre-flight (👤 batch all the physical setup up front)

Doing these now means fewer interruptions later. Have them ready before the AIs start.

- **P0.1 Boxes** — Box A (Windows `payne` / or a 2nd Ubuntu) and Box B (the Pi) powered, on the same LAN. Note each LAN IP.
- **P0.2 Firewall (LAN)** — allow Box A ↔ Box B on the app ports (`:8080`/`:8082`) **and Matrix S2S `:8448`** both ways.
- **P0.3 DNS / TLS** — Matrix federation prefers names + TLS. Decide: (a) real subdomains + certs for each box (cleanest — needed for real `.well-known` delegation + any HTTPS-secure-context test), or (b) LAN-only with `MATRIX_DELEGATE_SERVER` pinned to `IP:8448` + self-signed (faster, fine for a LAN proof). Tell the AIs which.
- **P0.4 Overlay account** — a Tailscale tailnet (or the already-certified self-hosted Headscale) for the survival-mesh leg.
- **P0.5 Optional kit (for the independent legs, only if running them today):**
  - Synapse **admin token** on each box (for K M-5 byte-purge, LEG K5).
  - 2–3 Ubuntu boxes (for Patroni HA, LEG G4).
  - The Android phone, no-SIM (for G-V1 mobile, LEG G6 — *build-gated, see Appendix*).
- **P0.6 Box C — the LAMP broker box (for PHASE 2.5, the roles/channels leg).** Stand up the
  `mesh-cert-broker` on the Azure LAMP host: docroot → `services/mesh-cert-broker/public`, drop the
  **Cloudflare token** + Box A's **pinned authority key** into `config/domains.php`, install `lego`,
  run `php bin/selftest.php` (offline acceptance → 10/0), report ready. *(Needs the **MESH-ROLES-AND-
  CHANNELS-OF-TRUST.md ★1–★12** code on Box A/B first — see PHASE 2.5; if that isn't built yet, skip
  PHASE 2.5 today and run the mesh + Matrix legs, which are ready now.)*
- **🛑 When P0.1–P0.4 are done, tell BOTH AIs: "pre-flight ready, LAN IPs are A=⟨…⟩ B=⟨…⟩".**

---

## PHASE 1 — Shared two-box bring-up

| # | Owner | Action | Handoff |
|---|---|---|---|
| 1.1 | 💻 DESIGNER | Box A already on `main` + pushed. Stand up the instance: `./deploy.ps1 -Prefix fcb -NginxPort 8082` (or it's already the dev stack). Confirm Synapse + MAS healthy, `php artisan migrate --force`. | ▶ then **📋 REPORT: "Box A up @ ⟨LAN IP⟩:8082, Synapse healthy"** |
| 1.2 | 🤖 ASSISTANT | Pi: `git pull` → `main`; `./deploy.sh`; confirm boots, `php artisan migrate --force`, Synapse healthy. | ▶ then **📋 REPORT: "Box B up @ ⟨LAN IP⟩:8080, Synapse healthy"** |
| 1.3 | 👤 OPERATOR | Confirm both REPORTs received; both serve `GET /api/federation/identity` (200) on their LAN IP. | **⛔ GATE 1 — both instances reachable.** Tell both AIs "Gate 1 green, proceed to peer handshake." |

---

## PHASE 2 — Establish BOTH federations (CGA mesh + Matrix S2S)

These are two **separate** trust layers on the same boxes: the CGA mesh (FF&C record sync, the G plane)
and Matrix S2S (Synapse↔Synapse, the K plane). K's moderation-record propagation needs the CGA mesh;
K's message propagation needs Matrix S2S. Establish both now.

| # | Owner | Action | Handoff |
|---|---|---|---|
| 2.1 | 💻 + 🤖 | **CGA peer handshake.** Each sets `FEDERATION_SELF_URL` to its LAN IP. Box A: `php artisan federation:peer:check ⟨B-url⟩` → `:discover` → `:handshake`. | 🔁 **RELAY** the two `server_id`s + URLs between boxes (ask each AI for `php artisan federation:identity`). |
| 2.2 | 💻 + 🤖 | Run handshake BOTH directions; `php artisan mesh:doctor` + `mesh:gates`; confirm both `federation_peers` rows = `trust_established`, `constitutional_version` MATCH, transports learned symmetrically. | **📋 REPORT per box.** ⛔ **GATE 2a — two-way CGA trust green.** |
| 2.3 | 👤 OPERATOR | Matrix S2S: ensure `:8448` is open both ways (P0.2) and the `.well-known/matrix/server` of each box resolves to the other (the choice from P0.3). | 🛑 **NEED OPERATOR** to confirm DNS/cert/port per P0.3; resume when you reply "S2S path ready". |
| 2.4 | 💻 + 🤖 | Verify Matrix federation: from a MAS-logged-in client on Box B, join/peek a room on Box A (e.g. the one `matrix:demo` creates). | **📋 REPORT.** ⛔ **GATE 2b — Matrix S2S reachable (a Box-A message appears on Box B, pseudonymously).** This is the load-bearing two-box step; K-legs 2-4 depend on it. |

---

## PHASE 2.5 — Roles & Channels of Trust (the qualify→request→approve→join leg)

> Full spec: **`MESH-ROLES-AND-CHANNELS-OF-TRUST.md`**. **Gated on the ★1–★12 build** (the manifest +
> lifecycle + in-mesh broker adapter + `mesh:request-cert` + `mesh:role` CLI). If ★1–★12 isn't built,
> **skip this phase today** and run PHASE 3 (mesh + Matrix gates are ready now); add 2.5 once it lands.
> Three-actor protocol unchanged; Box C steps are 👤 OPERATOR; RELAY now also carries channel artifacts
> (a signed grant A→C, the returned FQDN+cert C→A).

For EACH governed channel (`broker.dns`/`broker.tls` first — they unlock real TLS), run one cycle:

| # | Owner | Step | Handoff |
|---|---|---|---|
| 2.5.1 | 👤 OPERATOR | **QUALIFY** — drop the channel's token/key (CF token → Box C `config/domains.php`; Synapse admin token → A/B). | 🛑 **NEED OPERATOR**: drop the token; resume when "qualified". 💻 runs `mesh:role:qualify broker.dns` (prober hits CF live → green). |
| 2.5.2 | 🤖 ASSISTANT | **REQUEST** — `mesh:role:request broker.tls` on the graduating box (the Pi). Emits an auditable request row. | ▶ then **📋 REPORT** "requested". |
| 2.5.3 | 💻 + 🤖 | **APPROVE** — the dual-meter consent. No seated test gov on the rig → **Meter A** (active operator board; 1⇒1 single-box / unanimity-of-the-pair). Cross-box `authority.grant`/peer-zone legs add **Meter C** unanimity. | 🔁 **RELAY** the co-affected peer's consent when Meter C applies. ⛔ **HALT** until the meter passes. |
| 2.5.4 | 💻 + 🤖 | **JOIN** — on ratify an `authority.grant` box mints the grant; the box's advert gains the channel; it runs `mesh:request-cert` with the minted grant → installs the cert (closes the broker loop end-to-end). | **📋 REPORT.** ⛔ **GATE 2.5b** (below). |

- **⛔ GATE 2.5a — role-set integrity.** `mesh:roles` lists a box's channels and **refuses to advertise an un-approved governed channel** (advertised-claim ≠ governed-role).
- **⛔ GATE 2.5b — the broker channel, live.** A promotion-approved box gets a **real trusted `*.<domain>` cert** (LE staging→prod, two explicit REPORTs to spare prod rate limits) — *only because approved*; a browser goes green. **Negative gate:** a non-approved box's identical request is **REFUSED** by `GrantVerifier` (already proven offline by `bin/selftest.php` 10/0). **De-promotion leg:** de-promote the box → next renewal's meter check fails → the broker refuses re-issue → the cert lapses.

---

## PHASE 3 — The gates (run in this order; each is one of the two campaigns)

### G mesh gates (ride the CGA trust from 2.2)

| # | Owner | Action | Handoff |
|---|---|---|---|
| 3.G1 | 💻 + 🤖 | **LAN gates 1,3,4,5,6** (`G8b-CROSS-WAN-TWO-WAY-RUNBOOK §1-4`): two-way handshake · Meter-C divergent-version refusal (DESIGNER bumps `constitutional_version` on Box A → Box B `pushTo`/cold-sync **refuses** `constitutional_version_mismatch`, liveness still flows, then revert) · peer-consent (Box A `federation:upgrade:consent`) · nearest-node (`/api/mesh/nearest`, no `location_pings` written) · authority flip (B hands A a subtree). | **📋 REPORT each gate.** ⛔ **GATE 3.G1.** Code-fail → DESIGNER fixes + pushes, ASSISTANT pulls, re-run. |
| 3.G2 | 👤 OPERATOR | **Overlay** (`G8b-OVERLAY-EGRESS`): create/confirm the tailnet; install Tailscale on each **host**; on Windows enable **Docker Desktop → Networking → Mirrored** (or `tailscale up` in WSL2). | 🛑 **NEED OPERATOR** — this is the one real Windows-host wrinkle. Resume when you reply "overlay up, A=100.x B=100.y". |
| 3.G3 | 💻 + 🤖 | Each sets `FEDERATION_SELF_URL=http://100.x:port`; recreate the app container; `mesh:doctor http://100.⟨peer⟩` **from inside the container** both ways. | **📋 REPORT.** ⛔ **GATE 3.G2 — overlay egress proven both ways.** |
| 3.G4 | 👤 OPERATOR | **Survival mesh** (`…RUNBOOK gate 2`): take Box B's clearnet/https path DOWN (stop the public nginx bind / block `:8080` at the firewall). | 🛑 **NEED OPERATOR** to drop the clearnet path; resume when you reply "clearnet down". |
| 3.G5 | 💻 + 🤖 | Box A's next push/sync must **survive over the overlay** with no error; OPERATOR restores https; the CLK-20 probe re-closes the recovered circuit within one tick (~5 min). | **📋 REPORT.** ⛔ **GATE 3.G3 — survival + auto-recovery.** |

### K Matrix gates (ride GATE 2b — Matrix S2S)

| # | Owner | Action | Handoff |
|---|---|---|---|
| 3.K1 | 💻 + 🤖 | **Peer-judge M-1 redaction** (`K3-N LEG 2`): on Box A, seed a #square post + drive a judicial carve-out (a seated R-19/R-20 attestation) → the `moderation_flip` record rides the FF&C tail to Box B **AND** the `m.room.redaction` federates S2S (content stripped on B). Negative: an operator-relay redaction on a SEATED jurisdiction is REFUSED. | **📋 REPORT.** ⛔ **GATE 3.K1.** |
| 3.K2 | 💻 + 🤖 | **server_acl mesh + brick-guard** (`K3-N LEG 3`): write an M-1/M-4 `m.room.server_acl` on Box A; confirm the allow-list ALWAYS retains the local server + every legitimate peer (no `allow:[]`); it applies on Box B. | **📋 REPORT.** ⛔ **GATE 3.K2.** |
| 3.K3 | 💻 + 🤖 | **LIVE Meter-C for Matrix records** (`K3-N LEG 4`): make Box B's `constitutional_version` differ; Box A pushes a tail → Box B `ingestTail` returns `RESULT_REJECTED_TAMPER` / `constitutional_version_mismatch`; re-converge → applies. *(Same mechanism as 3.G1's Meter-C, proven for the Matrix record path.)* | **📋 REPORT.** ⛔ **GATE 3.K3.** |

---

## PHASE 4 — Independent legs (any order, only with their kit; not on the 1→2→3 chain)

| # | Owner | Leg | Needs | Handoff |
|---|---|---|---|---|
| 4.1 | 👤 + 🤖/💻 | **Patroni HA failover** (G LEG 4, `PHASE_G_PATRONI_HA_RUNBOOK`) | 2–3 Ubuntu boxes | 🛑 **NEED OPERATOR**: stand up the etcd/patroni/haproxy set + `docker kill` the leader on cue. Resume per the runbook's sim steps. ⛔ GATE: timeline 1→2, authority unchanged, scheduler fires once. |
| 4.2 | 👤 + 💻 + 🤖 | **M-5 live byte-purge** (K LEG 5 — **P1 DONE**) | Synapse **admin token** + lawful test fixtures | 🛑 **NEED OPERATOR**: set `MATRIX_ADMIN_TOKEN` on each box + supply a lawful test media fixture. Then drive a CSAM-class purge → confirm the media MXC **404s** + the trail flips `physical_removal_status` to `done`. ⚠️ Real legal process only; never live illegal content. |
| 4.3 | 🤖 + 💻 | **Dendrite-on-Pi v12 spike** (K LEG 6) | the Pi | ▶ ASSISTANT: `MATRIX_IMPL=dendrite ./deploy.sh`; check `roomVersions()` offers `12`. **📋 REPORT** pass OR document the gap (do not force it). |
| 4.4 | 👤 + 🤖/💻 | **Voice/video over LAN** (K LEG 7) | the `voice` profile | 🛑 **NEED OPERATOR** to confirm the UDP range is open on the LAN. `docker compose --profile voice up -d livekit`; two residents request `/civic/matrix/call-token`; an Element Call client on each joins. ⛔ GATE: call connects; a non-resident is 403 (Art. I). |
| 4.5 | 🤖 | **Founder/ETL cold-cycle re-cert** (G LEG 7) | the Pi | ▶ ASSISTANT: `down -v` → `git pull` → `./deploy.sh --with-etl` from a clean clone; drive the wizard founder→…→seat; confirm `setupComplete:true`, rc=0. **📋 REPORT** (any fresh-clone fault → DESIGNER). |

---

## PHASE 5 — Closeout

- 📋 Each AI posts its final gate matrix to 👤 OPERATOR.
- 💻 DESIGNER records the certified gates in the campaign docs + memory; opens DEV follow-ups for any code-fixable fails.
- 👤 OPERATOR gives the overall go/no-go.

---

## Appendix — code-prerequisites & what is NOT runnable today

Two G legs are **build-gated** (a DEV code-prereq isn't finished). Run everything above first; the
DESIGNER can build these dev-stack-first (Http::fake unit-tested) **in parallel** today to unlock them,
or defer to a follow-up rig session.

| Leg | Prereq | Status | Plan |
|---|---|---|---|
| **G5 — live G5/G5a flip handover** | **P1**: the OUTBOUND transport push of the sealed bundle (`finalize` → peer `/flip/operational` over the multiplex) + a governed production trigger. *(The receiver `/api/federation/flip/operational` already exists.)* | **partial** — receiver yes, sender + trigger NO | DESIGNER builds + unit-tests P1 on Box A today (parallel to Phase 3); then run G5 on the Phase-2 pair. |
| **G6 — G-V1 native mobile GPS** | **P2**: the Capacitor wrap (native GPS → the ping API + on-device G-ID device-key signing). | **NOT built** (no `capacitor/android/ios`) | Build WITH the phone in the loop, never blind — its own mini-session. Defer unless the phone + DESIGNER time are both free today. |
| K M-5 byte-purge (4.2) | K-P1 (admin media-DELETE + `physical_removal_status`) | ✅ **DONE** (committed) | Runnable today — just needs the OPERATOR's admin token + fixtures. |

### Today's runnable set (no unbuilt code): 
Phase 1 → 2 → 3 (all G LAN/overlay/survival gates + all K Matrix gates) → Phase 4 legs 4.1–4.5.
**That certifies the entire two-box mesh for BOTH G and K.** G5/G6 are the only carve-outs, and only G6
truly can't start today.

## One-screen ordering
```
P0 OPERATOR pre-flight ─▶ 1 bring-up ─▶ 2 CGA trust + Matrix S2S ─▶ 3 GATES:
                                                                     ├─ G mesh: LAN gates → overlay → survival
                                                                     └─ K Matrix: peer-judge → server_acl → Meter-C
   independent, any time w/ kit ─▶ 4: Patroni · M-5 purge · Dendrite-Pi · voice · cold-cycle
   build-gated ─▶ G5 (DESIGNER builds P1 today) · G6 (needs phone + P2)
```
