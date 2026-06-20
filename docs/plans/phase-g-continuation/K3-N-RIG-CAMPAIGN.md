# Phase K-3 (K3-N) — Matrix Mesh Rig-Certification Campaign

> The cross-instance / live-homeserver certification for the Matrix mesh commons (Plane B). Everything
> in K3-A…K3-K is BUILT + unit-pinned on the dev stack; this campaign proves the parts that ONLY ≥2
> real boxes (and optionally a Pi) can prove. Mirrors the structure of `G-RIG-CAMPAIGN.md`. Run the legs
> in order; honour the HALTs. Sibling: the G mesh campaign (this one RIDES the same peer handshake).

## Roles
- **🧑 Operator** — owns the physical lab: ≥2 LAN/WAN boxes (A = authoritative, B = peer/mirror), an
  optional ARM Pi (Dendrite spike), DNS/cert + firewall ports on demand. Runs the legs.
- **🤖 Assistant** — owns code + this runbook + the DEV prerequisites (below). Fixes what a leg surfaces.

## What is ALREADY proven on the dev stack (do NOT re-test on the rig)
- The four carve-outs + the legitimacy flip (`ModerationFlipTest`, `MatrixCarveoutEmitterTest`): operator-relay
  vs seated R-19/R-20 attestation, M-3-never-an-appservice-action, power-level-never-moves.
- The M-5 physical-law floor (`LegalComplianceTest`, all 9 guardrails): closed enum, per-item, attestation_id
  NULL, no-hash-in-log, purge-reserved-to-CSAM, the disclosure referral, M-5-never-server-ACLs.
- The testimony FF&C tail (`TestimonyFederationTest`): a sealed testimony is selected by `buildAuditTail`
  and mirrors inbound under authoritative-wins, pseudonymously.
- v12 sole-creator power-clamp (`MatrixV12PowerClampTest`), the topology reconciler, residency-gated posting,
  the translation privacy rail, the residency-gated call token. **These are settled — the rig tests PROPAGATION,
  not the local invariants.**

## Topology
```
   Box A (authoritative)              Box B (peer / mirror)              Pi (optional, LEG 6)
   ┌────────────────────┐            ┌────────────────────┐            ┌──────────────────┐
   │ CGA + Synapse(A)   │◀── S2S ──▶ │ CGA + Synapse(B)   │            │ CGA + Dendrite   │
   │ + MAS + appservice │  (8448 /   │ + MAS + appservice │            │ (MATRIX_IMPL=    │
   │ federation_peer→B  │  .well-    │ federation_peer→A  │            │  dendrite)       │
   └────────────────────┘  known)   └────────────────────┘            └──────────────────┘
```
Box A holds authority for the test jurisdiction; Box B mirrors. The CGA peer handshake (G mesh) MUST be
established first — the Matrix S2S rides the SAME pinned-peer trust.

## Ordering (dependency graph)
```
LEG 0 (prep) → LEG 1 (Matrix S2S reachability)  ─┬─→ LEG 2 (peer-judge redaction)
                                                 ├─→ LEG 3 (server_acl mesh + brick-guard)
                                                 └─→ LEG 4 (Meter C divergent-version refusal)
LEG 5 (M-5 live byte-purge)  — needs DEV-PREREQ P1; independent of S2S
LEG 6 (Dendrite-on-Pi v12 spike) — independent
LEG 7 (voice/video LAN)  — independent; needs the `voice` compose profile
```

---

## LEG 0 — Prep: both boxes on `main`, Synapse healthy, peer handshake established
- Both boxes: `git -C <repo> checkout main && git pull && ./deploy.sh` (clean). Confirm `fcw_matrix` (or
  `<prefix>_matrix`) is **healthy** and MAS is up. `php artisan migrate --force` (picks up 000002/000003/000004).
- Establish the CGA peer handshake A↔B (the G mesh campaign LEG 1 — `transport:register` → `discover/handshake`
  → `mesh:doctor` GREEN). Both `federation_peers` rows = `trust_established`, public keys pinned.
- **HALT** if `mesh:doctor` is not GREEN — the Matrix S2S has no trust to ride. Fix the G handshake first.

## LEG 1 — Matrix S2S federation reachability (THE load-bearing two-box step)
- **Goal:** the two Synapses federate; a post in a shared jurisdiction room on A is visible on B.
- Each box serves `/.well-known/matrix/server` (K3-A) — confirm `curl https://<A-domain>/.well-known/matrix/server`
  returns the federation authority; open **port 8448** (or the delegated port) A↔B in the firewall.
- On Box A: `php artisan matrix:demo --fresh` (seeds #square/#halls on the test jurisdiction). Note a room id.
- From Box B's Synapse, join/peek the room over federation (a real Matrix client logged into B via MAS, or
  `GET /_matrix/federation/v1/...`). 
- **PASS:** a #square message authored on A appears on B (and vice-versa); the sender shows as the pseudonymous
  `@u-<handle>:A-domain`, never a legal name.
- **HALT** if S2S won't connect — capture the Synapse federation logs + the `.well-known` responses; it is
  almost always a DNS/cert/port-8448 issue, not CGA code. (Assistant note: the `.well-known` delegate authority
  is computed in `WellKnownController::delegateAuthority` — verify `MATRIX_DELEGATE_SERVER` per box.)

## LEG 2 — Peer-judge M-1 redaction propagation
- **Goal:** a judicial carve-out removal on A is honoured/visible on B.
- Prereq: the test jurisdiction has a SEATED legislature on A (so the flip requires an R-19/R-20 attestation),
  or run the bootstrap operator-relay path. Seed an offending #square post.
- On A: drive `CarveoutEmitterService::emit(... 'judicial_order', reference, $attestation)` (a seated judge’s
  R-19/R-20 `StandingAttestation`). This (1) seals `matrix_carveout_log` + `public_records('moderation_flip')`,
  (2) emits the `m.room.redaction`.
- **PASS (two independent signals):** (a) the `moderation_flip` record rides the FF&C tail to B (query B:
  `public_records` where kind='moderation_flip' AND source_server_id=A); (b) the Synapse-native redaction
  federates S2S so the event content is stripped on B too. attestation_id is SET on the carve-out log (a real
  order), is_seated_at_time=true.
- **PASS (negative):** an operator-relay redaction (no attestation) on a SEATED jurisdiction is REFUSED
  (the flip no longer honours the operator) — confirm no redaction emitted, a rejected audit edge recorded.
- **HALT** if the `moderation_flip` record does NOT mirror to B — that is the FF&C tail (G-owned); check
  `buildAuditTail`/`ingestTail` + the peer watermark. The redaction-event federation is Synapse-native.

## LEG 3 — server_acl mesh + the allow:[] brick-guard drill
- **Goal:** an M-1/M-4 server ACL written on A applies across the mesh WITHOUT self-bricking.
- On A: write an `m.room.server_acl` via the appservice for a behaviour-based abusive server (M-4) or a logged
  judicial order (M-1) — recorded in `matrix_server_acls` (carve_out ∈ {m1_judicial, m4_antispam} ONLY — M-5 may
  NEVER write one).
- **PASS:** the allow-list ALWAYS retains the local server + every legitimate `federation_peer` (the brick-guard);
  attempting `allow:[]` is REFUSED by the writing service. The ACL is visible/applied on B for the target server.
- **HALT** if the local server or a legitimate peer is ever in the deny set (a self-ban / peer-ban footgun).

## LEG 4 — Meter C: divergent `constitutional_version` admission refusal (LIVE two-instance)
- **Goal:** the unit-pinned G-VER Meter C refuses cross-mesh sync when the hardened versions diverge — proven
  LIVE between two real instances.
- Make Box B's `constitutional_version` differ from A (e.g. B on an older commit of the hardened surface, or a
  deliberately diverged build). Confirm `InstanceSettings::current()->constitutionalVersion()` differs A vs B.
- On A: push a tail to B (the FF&C sync). 
- **PASS:** B's `ingestTail` returns `RESULT_REJECTED_TAMPER` with reason `constitutional_version_mismatch`
  (a peer counting under a different hardened version no longer counts identically — fail-closed). Re-converge
  the versions and confirm sync then APPLIES.
- **HALT** if a divergent-version tail is APPLIED — that is a Meter-C regression (records counted under a
  different hardened surface must not cross until the mesh agrees).

## LEG 5 — M-5 live byte-purge + report path (DEV-PREREQ **P1 = DONE**, commit 01616b6)
- **Goal:** a CSAM-class removal actually DESTROYS the media bytes on the homeserver (not just redacts), and the
  PRESERVE→REPORT→PURGE sequence is wired to real operator credentials.
- ✅ **P1 LANDED:** `MatrixClientService::purgeEvent` redacts, then (when `config('matrix.admin_token')` is set)
  resolves the event's mxc media and `DELETE /_synapse/admin/v1/media/<server>/<media_id>`. The trail's
  `physical_removal_status` (deferred|done|failed) is HONEST — on dev (no token) a purge is `deferred`; it only
  reports `done` after the admin DELETE actually runs. **The rig step is now ONLY: set `MATRIX_ADMIN_TOKEN`
  (your Synapse admin token) and confirm the bytes 404 + the status flips to `done`.**
- Operator supplies: a real known-illegal HASH list (IWF/NCMEC/PhotoDNA, access-controlled, their credentials)
  bound into `config('matrix.scan.local_hashes')` or a cloud `MediaScanProvider`; an operator Synapse admin token;
  NCMEC CyberTipline credentials.
- **PASS:** M-S blocks a known-illegal hash at upload (admission gate); an M-5 `purge` removal DELETEs the media
  file + thumbnails (verify the MXC URI 404s on disk afterward); the evidence trail + a NCMEC report (dry-run) are
  produced; the trail records `physical_removal_status=done`. attestation_id NULL on every M-5 row.
- **HALT** if a "purged" item's bytes are still fetchable — `physical_removal_status` must reflect reality.
- ⚠️ Handle with REAL legal process. Use only the operator's lawful test fixtures; never live CSAM.

## LEG 6 — Dendrite-on-Pi v12 power-clamp spike (independent)
- **Goal:** does the Pi-candidate homeserver (Dendrite) offer the v12 sole-creator power-clamp the uncensorable
  public rooms depend on?
- On the Pi: `MATRIX_IMPL=dendrite ./deploy.sh` (the env-switch; `config/matrix.php` reads `impl`). Bring up the
  appservice against it.
- **PASS:** `MatrixClientService::roomVersions()` reports `12` in `available` (the K3-E clamp can create an
  immutable-creator room). If NOT, **document the gap** (which room version Dendrite tops out at) — this gates
  whether the Pi can host an uncensorable commons or must stay a Synapse-only role. Do NOT force it.

## LEG 7 — Voice/video over LAN (independent; the `voice` profile)
- **Goal:** the residency-gated call token mints and a two-participant call connects over the LAN SFU.
- `docker compose --profile voice up -d livekit` (publishes 7880 + the UDP range). `.well-known/matrix/client`
  advertises the `rtc_foci` LiveKit focus.
- Two residents request `POST /civic/matrix/call-token` for the same room; an Element Call client on each joins.
- **PASS:** both tokens verify (HS256 over the api_secret), are room-scoped + pseudonymous; the call connects
  (audio/video flows over the LAN). A non-resident is refused (403 Art. I).
- **HALT** if media won't connect — almost always the UDP port range / `use_external_ip` in
  `docker/livekit/livekit.yaml`, not CGA code.

---

## DEV prerequisites (assistant — land before the gated legs)
- ~~**P1 (LEG 5):** `purgeEvent` → admin media-DELETE + `physical_removal_status`~~ ✅ **DONE (01616b6).**
  LEG 5 now needs only the operator's `MATRIX_ADMIN_TOKEN` + lawful test fixtures.
- Optional: a `matrix:mesh-doctor`-style command that asserts the Matrix S2S reachability + the moderation-record
  mirror in one shot (speeds LEG 1/2 triage).

## Ownership at a glance
| Leg | Owner | Gate |
|---|---|---|
| 0 prep · 1 S2S · 2 redaction · 3 server_acl · 4 Meter C | 🧑 run / 🤖 fix | needs ≥2 boxes |
| 5 M-5 byte-purge | 🤖 P1 then 🧑 run | needs admin token + real hash list |
| 6 Dendrite-Pi v12 | 🧑 run / 🤖 assess | needs the Pi |
| 7 voice/video | 🧑 run / 🤖 fix | needs the `voice` profile (LAN) |

**While these run, the operator does H districting + geodata (the manual lane). LEG 1 is the load-bearing
two-box step — if S2S won't federate, legs 2–4 are blocked; everything else (5/6/7) is independent.**
