# Mesh Roles & Channels of Trust — Design Doc

**Status:** Decision-ready. Drives both the build and the multibox campaign.
**Plane:** Operator / instance plane (G-OP). Never touches the constitutional citizen role
system (R-01…R-30, `RoleService`). The "role" here is an **operator/instance** concept.
**Phase:** Folds into Phase G continuation + Phase K rig work. Most of it is **dev-now**;
the live-cert and cross-instance legs are **rig-gated** (same class as G-V2 / mesh-cert-broker).

---

## 1. THE MODEL — trust is composable capability channels, not tiers

A box does **not** have a *rank*. It has a **set of capability channels** it has established.

> **A box's ROLE = the SET of capability channels it holds.**
> Derived, never stored as a tier:
> `SELECT capability FROM instance_capabilities WHERE server_id = ? AND is_self AND enabled`.

This mirrors how the mesh already models **reachability**: a box is reachable over a *set* of
transports (`federation_transports`: `https`, `tailnet`, `onion`, `sneakernet`, `yggdrasil`).
Capabilities are the exact same shape — a *set of channels* — for **what a box offers**, not how
you reach it. The two registries are structural siblings.

Three properties define the model:

1. **Composable, not tiered.** "broker box", "matrix host", "authority root" are not levels you
   climb. They are channels you *hold* — `broker.dns`, `matrix.homeserver`, `authority.grant` —
   each established independently. A box can hold any subset.
2. **Opt-in per hardware / legal / IT.** Each channel is a per-box toggle on the
   `is_self` / `enabled` path `TransportService` already exposes (`registerSelf` / `disableSelf`).
   A box with no Cloudflare token never enables `broker.dns`. A box behind a restrictive NAT
   disables `client.serve`. An operator forbidden by local law from hosting a homeserver leaves
   `matrix.homeserver` off. The mesh adapts to the box; the box is never forced into a role.
3. **Advertised claim vs governed role.** An *advertised* capability is a claim. A *governed*
   capability is a claim **the mesh has approved** — carried by a signed, expiring grant on the
   row. Self-affecting channels (`mesh.member`, `mirror`, `etl`) are self-asserted. Power-bearing
   channels (`authority.grant`, `broker.dns`, `broker.tls`, `client.serve`, `matrix.homeserver`,
   `voice.sfu`) require a **grant** minted only after the dual-meter consent already built in
   `PeerUpgradeAgreementService`. So "advertised-claim vs mesh-governed-role" is **enforced, not
   cosmetic**.

The whole model is the **JOIN of two existing primitives**: the transport-registry pattern (the
advertise/learn plumbing) and the cert-broker grant + dual-meter consent (the approval gate). No
new crypto, no new vote math, no new approval engine.

---

## 2. THE CAPABILITY VOCABULARY + MANIFEST

### 2.1 The closed vocabulary

A `CapabilityChannel` enum + a `CHECK (capability IN (...))` constraint, exactly mirroring
`FederationTransport::TRANSPORTS = ['https','tailnet','onion','sneakernet','yggdrasil']`
(`app/Models/FederationTransport.php:18`).

| channel | meaning | class |
|---|---|---|
| `mesh.member` | the always-on base — you can't federate without it | **self-asserted** |
| `mirror` | read-only cold-sync mirror (surfaces `mirror_of_server_id`) | **self-asserted** |
| `etl` | hosts the geodata archive / ETL substrate | **self-asserted** |
| `broker.dns` | holds a scoped Cloudflare token; can do DNS-01 naming | **governed** |
| `broker.tls` | runs `lego`; can mint Let's Encrypt certs | **governed** |
| `client.serve` | graduated to serving browser clients on a real cert | **governed** |
| `authority.grant` | the right to MINT promotion/cert grants under a domain | **governed** |
| `matrix.homeserver` | hosts the Synapse social commons (Plane B) | **governed** |
| `voice.sfu` | hosts the voice/video SFU | **governed** |

`mesh.member` is **both implicit and a row**: every box advertises at least `mesh.member`, so the
manifest is self-describing — matching how `transports` always has at least one rung. `mirror` and
`authority.grant` surface flags that already exist (`mirror_of_server_id`,
`attestation_authority_enabled` on `InstanceSettings`) rather than re-modeling them.

**GOVERNED subset** (a constant on the model): the six power-bearing channels above. The split is
the load-bearing rule — `registerSelf` **refuses** to flip `enabled = true` on a governed channel
without a verified, unexpired grant.

### 2.2 The manifest rides the existing handshake — signed by construction

The manifest is **not a new subsystem**. It is a `capabilities` key added alongside the existing
`transports` key on the exact wire seam transports already use.

- **`InstanceIdentityService::handshakePayload()`** (`...:174`) gains a `capabilities` entry.
- **`PeerController::identity()`** (`...:26`) already appends `'transports' => selfEndpoints()`;
  append `'capabilities' => selfCapabilities()` the same way.
- **`PeerController::handshake()`** (`...:39`) already whitelists `transports.*`; add a parallel
  `capabilities.*` validation block (slug + grant + grant_signature).
- **`PeerService::discover` + `receiveHandshake`** already call `recordPeerTransports`; add the
  parallel `recordPeerCapabilities` call right beside each.

Because the **entire handshake payload is already Ed25519-signed and TOFU/pinned-verified** by the
federation middleware, the manifest is **signed-by-construction** — no new signature field, no new
crypto. A receiving peer learns it via `CapabilityService::recordPeerCapabilities()`, a line-for-line
sibling of `TransportService::recordPeerTransports()` (`...:58`): skip-unknown-label defense,
idempotent per `(server_id, capability)`, latest-advert-wins.

### 2.3 The table

`instance_capabilities` copies `federation_transports`' shape verbatim, plus grant columns:

```
instance_capabilities
  id                     uuid pk default gen_random_uuid()
  server_id              uuid                  -- whose capability (self or a peer)
  capability             string(32)            -- CHECK (capability IN <closed vocab>)
  is_self                bool
  enabled                bool
  priority               int
  granted_by_server_id   uuid null             -- the authority that signed the grant
  grant_signature        text null             -- detached Ed25519 over the grant envelope
  grant_expires_at       timestamptz null      -- revocation-by-expiry clock
  timestampsTz, softDeletesTz
  UNIQUE (server_id, capability) WHERE deleted_at IS NULL
```

> **Naming note (collision resolved).** The four input designs proposed three names for this table
> (`instance_capabilities`, `mesh_capabilities`) and several service names. **Canonical for the
> build: one table `instance_capabilities`, one model `InstanceCapability`, one service
> `CapabilityService`** (the manifest/advertise/learn sibling of `TransportService`), and one
> lifecycle service `MeshRoleGrantService` (the approval orchestrator, §3). The grant *records*
> live on the `instance_capabilities` row itself for self/peer capability state; the cert-broker's
> separate `cert_grants` store remains its own thing (the broker consumes a grant; the row caches
> the receipt). We do **not** ship parallel `mesh_role_requests` / `mesh_role_grants` tables unless
> §9-D says otherwise — one request flows through the existing `PeerUpgradeProposal` lifecycle.

---

## 3. THE LIFECYCLE — qualify → request → approve → join

The lifecycle is a near-exact structural clone of the **G-VER upgrade-agreement protocol**, applied
to capabilities instead of constitutional versions. One orchestrator, **`MeshRoleGrantService`**,
sibling of `PeerUpgradeAgreementService`.

### 3.1 State machine

```
                 prober fails
   ┌──────────────────◄──────────────────┐
   │                                      │
[ABSENT] ──qualify──► [QUALIFIED] ──request──► [REQUESTED]
self-asserted │              (prober green)        │
channel:      │                                    │ APPROVE
register      ▼                                    ▼  (dual-meter consent)
directly  [ENABLED] ◄──join/ratify── [APPROVED] ◄──┘
(mesh.member,         (advert carries     │
 mirror, etl)          the channel)       │ grant minted + signed
                            │             │ by authority.grant holder
                  self-disable (always    │
                   unilateral)            │
                            │             ▼
                            ▼        [grant on row, enabled=true]
                       [DISABLED]         │
                                          │ grant_expires_at passes
                                          │ OR de-promotion lapses standing
                                          ▼
                                      [LAPSED] ──(re-enable re-runs consent)──► back to REQUESTED
```

### 3.2 The four steps

1. **QUALIFY** — pure-PHP `CapabilityProber`, a registry keyed by capability slug (mirroring the
   `TRANSPORTS` whitelist). Each channel declares its prerequisites + a `probe()`:
   - `broker.dns` → a live `GET zones/{id}` against the Cloudflare API with the `.env` token
     (the exact dependency the broker README documents).
   - `broker.tls` → `lego` on PATH.
   - `authority.grant` → this box holds a signing-authority key in the domain's `authority_keys`.
   - `matrix.homeserver` → Synapse health check.
   - `etl` → the geodata archive bind-mount is present.

   A request for a channel whose prober fails is **refused before it can be opened** —
   capable-before-request. Tokens/keys live in `.env` / secret store and **never federate** (same
   rule as the operator password and the CF token).

2. **REQUEST** — `requestCapability(channel)` opens a `PeerUpgradeProposal` of a new
   `KIND_ROLE_GRANT`, signed by the box's instance Ed25519 key (`InstanceIdentityService::sign`),
   naming the channel(s) + scope jurisdiction. Self-asserted channels skip this entirely and go
   straight to `registerSelf`.

3. **APPROVE** — reuses `PeerUpgradeAgreementService`'s meter machinery **verbatim**:
   - `applicableConsentLeg($scope)` (`...:474`) decides **seated vs bootstrap**.
   - **Meter A (de-facto operator board)** — `recordOperatorConsent` (`...:154`), vetting-gated to
     ACTIVE operators, scaling **1 ⇒ 1 / 2 ⇒ unanimity / 3+ ⇒ 2/3** via the PROTECTED
     `ConstitutionalValidator::supermajority`.
   - **Meter B (seated R-19/R-20 government)** — `openSeatedLeg` (`...:217`), the MultiJurisdictionVote
     supermajority. **Supersedes A** when a government is seated.
   - **Meter C (co-affected peers)** — `meterCPassed` (`...:432`) / `coAffectedPeerServerIds`,
     unanimity — required **only** for channels that act for a *peer's* subtree (`authority.grant`,
     `broker.dns` for a name under a peer's zone). This is a **per-capability declaration** in the
     `CapabilityProber` registry, not hardcoded.

4. **JOIN** — `MeshRoleGrantService::ratify()` runs the `LocalAutonomyService::finalize`
   **refuse-with-citation-unless-every-gate-cleared** discipline. On all-gates-pass, an
   `authority.grant`-holding box **mints + signs the grant** (§4.3), writes
   `granted_by_server_id` + `grant_signature` + `grant_expires_at` to the requester's
   `instance_capabilities` row, flips `enabled = true`. The box's next handshake carries the
   approved channel; every peer that discovers/handshakes it learns the full role-set into its own
   `instance_capabilities` and starts dialing the service.

### 3.3 Revocation / role-drop = de-promotion lapse

No separate revocation engine. Two mechanisms, both already proven:

- **Self-disable is always unilateral** — a box can always stop offering a service
  (`disableSelf`). No consent needed to *stop*.
- **Grant standing is scoped to the promotion state.** Short grant TTL (the cert-broker's
  revocation-by-expiry, 90-day LE clock) + **renewal re-runs the meter check**. A de-promoted box
  (authority for a subtree flips away, or a seated government de-seats, or an operator account is
  de-vetted) simply **isn't re-granted** at renewal — the grant lapses, the channel drops. This is
  `LocalAutonomyService`'s fail-closed dual-ratification discipline applied to a capability instead
  of an authority flip.
- **Re-enabling re-runs consent** — getting a lapsed channel back is a fresh `REQUEST → APPROVE`.

A faster-than-expiry path (a gossiped CRL via `MeshOperatorService::revokeKey`) is available but
**deferred to v2** (§9-E).

---

## 4. THE BROKER ROLE — broker.dns + broker.tls as adoptable capabilities

Today `services/mesh-cert-broker/` is an external LAMP service. The move: **the broker is a mesh
role, not just an external box.** `broker.dns` and `broker.tls` are two channels in the same model;
"external LAMP Box C" and "in-mesh box adopting the broker role" are **the same role, differing only
in deployment.**

### 4.1 The issuance core stays framework-free (verbatim)

`Broker::issue()` / `GrantVerifier::verify()` / `Canonical.php` stay a framework-free library — they
already are. `Canonical.php` is **byte-identical** to `AuditService::canonicalJson` +
`InstanceIdentityService`, so a CGA-signed grant/request verifies identically whether the broker
runs on Box C (LAMP) or in-mesh. The 5-point trust chain — peer-signed request + authority-signed
grant + unexpired + authorized-for-this-domain + exact-name CSR — is unchanged and is the **JOIN-time
verification template**.

### 4.2 Routing / discovery — generalize the pinned `authority_keys`

The broker's per-domain `authority_keys` whitelist (`config/domains.php`) generalizes into a
**mesh-replicated `broker_authorizations` fact**: *"which boxes the mesh trusts to broker under
domain X, attested by which authority"* — `(domain, broker_server_id, authority_server_id,
authority_pubkey, signature, issued_at, revoked_at)`. It rides the **already-proven signed-fact
gossip**: each fact is signed by the authority and gossiped to pinned peers; a receiver verifies it
against the authority's **own** pinned key (never the relayer's), exactly as
`MeshOperatorService::ingestAnnounce` verifies each key binding. The static whitelist becomes a
**live, mesh-distributed routing table.**

- **In-mesh broker** sources `authority_keys` from `broker_authorizations` (via a thin
  `BrokerRoleService`).
- **Box C (LAMP)** keeps reading `config/domains.php` verbatim.
- Both feed the **same** `GrantVerifier`.

A needy peer **discovers** a broker by capability + domain through `CapabilityService` /
`TransportEndpoints::forPeer` — the same failover ladder + health/circuit-breaker that ranks
transports — so it can ROUTE to a broker over clearnet/tailnet/onion/yggdrasil without out-of-band
knowledge.

### 4.3 The peer cert-client + authority grant-on-promotion

The two README "still-to-wire (CGA side)" halves:

- **`php artisan mesh:request-cert {domain} {subdomain}`** (the peer side): generate a TLS keypair +
  CSR locally (**private key never leaves**), assemble the README's signed request body (canonical-JSON
  byte-identical via `InstanceIdentityService::sign` ⇄ broker `Canonical.php`, fresh nonce), resolve
  a broker via `CapabilityService::forCapability('broker.tls', domain)`, POST over
  `MultiplexClient::reach`, install the returned full-chain PEM. `bin/test-client.php` is the
  reference to port.
- **Authority grant-on-promotion** (the missing approval half): when a box is approved for
  `broker.tls` / `client.serve`, the `authority.grant`-holding box signs a **`cert_grant`** with its
  instance key — the same key already in the broker's `authority_keys`. The grant envelope:

  ```
  { v:1, type:"cert_grant", domain, subdomain,
    peer_pubkey, peer_server_id,
    authority_pubkey, authority_server_id,
    issued_at, expires_at }      // canonical-JSON + detached Ed25519
  ```

  This is the **general capability grant** — add a `capability` field and the cert-broker's
  `GrantVerifier` becomes **one consumer** of a universal grant the mesh mints. The grant is minted
  **only on ratify**, so cert issuance **follows legitimacy** through the identical dual-meter
  consent that gates every other elevation. The grant is the **cryptographic receipt** of that
  approval. The **Cloudflare token stays pinned to the broker box** — it never federates, never
  appears in a grant or response. The grant carries **only public keys + names.**

### 4.4 The in-mesh adapter

A thin Laravel wrapper: a new `/api/federation/cert-request` endpoint (mirroring
`FlipController::receiveOperational`: raw `getContent()` bytes so the signed canonical isn't mutated
by `TrimStrings`; `federation.signed:pinned`) hands the body to the **same** `Broker::issue()`,
sourcing `authority_keys` from `broker_authorizations`. The in-mesh box also **appears in the mesh**
as a peer holding `broker.dns` / `broker.tls` in `instance_capabilities`.

### 4.5 Box C — the broker-only box the mesh trusts (the demo home)

Box C is a LAMP/Azure host running **only** `services/mesh-cert-broker/`: Apache docroot → `public/`,
`config/domains.php` with the operator's Cloudflare token + Box A's pinned authority key, `lego` on
PATH. **No CGA repo, no Synapse, no governance plane.** It is the operator's hardware + DNS + token
kit. **LAMP now → cloud later is the demo home** — the same role, redeployed. `bin/selftest.php` is
its offline readiness gate.

---

## 5. DUAL-CONTROL — which roles flip to two-party on adoption

The split is **self-affecting vs other-affecting**, reusing the M-5 / promotion-flip shape.

| channel | approval on adoption | why |
|---|---|---|
| `mesh.member`, `mirror`, `etl` | **none** (self-assert) | affects only the box itself |
| `voice.sfu`, `matrix.homeserver` | **Meter A or B** (operator board / seated gov) | public-facing service, governance-bearing, but local to the box |
| `broker.tls`, `client.serve` | **Meter A or B** | operational graduation (serve clients / mint own-domain certs) |
| `broker.dns` / `authority.grant` **for a peer's zone** | **Meter A/B + Meter C unanimity** | acts for a *peer's* subtree — co-affected peers must consent |

**The rule:** transport composition and self-affecting channels are the operator's own infra choice
(no governance gate). A channel that grants the box **authority over others** or a **public-facing
name under a peer's zone** routes through the dual-meter — and adds **Meter C** when it touches a
peer's subtree. This is the **same M-5 / G6 promotion-flip discipline** (`LocalAutonomyService`
fail-closed dual-ratification): both meters or it flips nothing.

**De-promotion** rides this directly: when authority for a subtree flips away or a government
de-seats, the grant's standing lapses at the next renewal check — **role-drop is the same lapse,
generalized to every capability.**

---

## 6. THE OPERATOR CONSOLE — the G-OP-plane UI

**Not a new app.** It is the existing federation console
(`resources/js/Pages/Jurisdictions/Federation.vue`, `FederationConsoleController::show`, gated by
the `auth:operator` plane from G3c) **reorganized around one mental model**: a box's role is the SET
of channels it has established; each channel is qualified, requested, approved, joined independently.
The page already has every primitive — it just lacks the organizing frame.

### 6.1 The reorganization

1. **ROLE BOARD (top)** — one card per channel, status:
   `established | qualifiable | needs-config | requested | approved | lapsed`, a "what this lets the
   box do" line, and the qualify/request CTA. Status is derived by extending `MeshGateService` from a
   flat gate list into **channel-keyed gate clusters** — each cluster self-contained pass/warn/fail.
   "Qualified" = the channel's cluster has no FAIL. Surfaced identically by the `mesh:gates` CLI and
   the GUI (the dual-surface contract already in the class header). The flat list stays as a derived
   view so `mesh:gates` is unchanged.
2. **mesh-member channel detail** — the existing "Two-way mesh — setup & gates" / transports panel:
   list transports + live status from `mesh:doctor`, **connect / test / troubleshoot**
   (`discoverPeer` / `handshakePeer` / `probePeer`, already operator-authed POST routes flashing into
   `mesh.probe`), **switch method** (`transport:register` / `disableSelf`, surfaced as buttons —
   today CLI-only), and the broker README's **token rotation** as a `broker.dns` key sub-step.
3. **PENDING REQUESTS panel** — unifies today's adoption-request list + read-write petition list into
   one "role requests + their dual-control approval state", reading the **live meter state directly
   off `PeerUpgradeAgreementService`** (Meter A board attestation / Meter B seated MJV). It renders
   the meter; it does not re-implement approval.
4. **Onboarding copy** threaded through, in the existing amber Art. V §7 explainer voice.

The whole surface stays **public-readable** (Art. II §2); the actionable controls sit behind
`Auth::guard('operator')->check()` — identical to the current `host.authed` gate.

### 6.2 Key-capture pattern

Any secret a channel needs the operator to capture once (a rotated token) uses
`FederationHostController::mintKey`'s **one-shot plaintext-flash** pattern (never an Inertia prop,
Argon2id-at-rest). For `broker.dns` the CF token lives **only on the broker box** — if the same
operator runs both boxes, the console offers a real rotation walkthrough; otherwise it shows
"broker.dns configured" as status and links the broker-box walkthrough (§9-C).

### 6.3 Campaign walkthrough

Each box's operator opens `/federation` (signed in via `/operator/login`), reads the **Role Board** to
see established vs qualifiable channels, runs gates/probe to **TEST**, drops tokens/keys to
**QUALIFY**, submits a **REQUEST**, watches the dual-control **APPROVAL** meter flip, then runs
discover/handshake/probe to **JOIN** carrying the approved set. The console makes the campaign legible
as a **sequence of channel grants** rather than an opaque deploy.

---

## 7. WHAT TO BUILD

> **MINIMAL (campaign-critical)** rows are marked ★. Everything else is the fuller build.

> **STATUS (2026-06-20): ★1–★12 BUILT + tested green** (branch `claude/practical-payne-17d537`).
> 28 constitutional pins across 4 live-pg files — `CapabilityRegistryTest` (7), `MeshRoleGrantTest` (4),
> `BrokerAuthorizationTest` (2), `CertBrokerLoopTest` (2, the full mint→request→issue loop offline + an
> unauthorized-authority refusal). The cert loop issues a real cert end-to-end in-mesh via the stub ACME.
> Remaining = the **fuller build**: console Role Board UI (13–16), S2S grant delivery (17 = rig), live LE
> issuance (20 = rig), plus op-config (18) + Box C (19). The campaign drives the lifecycle from the
> `mesh:role` + `mesh:request-cert` CLI now; the Role Board UI is a convenience layer over it.

| # | piece | where | dev-now / rig / op-config | enables (campaign leg) |
|---|---|---|---|---|
| ★1 | `instance_capabilities` migration (table §2.3, closed-vocab CHECK, unique partial index) | `database/migrations/..._create_instance_capabilities_table.php` | **dev-now** | role-set integrity (GATE 2.5a) |
| ★2 | `InstanceCapability` model + `CapabilityChannel` enum/const + GOVERNED subset | `app/Models/InstanceCapability.php` | **dev-now** | role-set integrity |
| ★3 | `CapabilityService` — `selfCapabilities()`, `recordPeerCapabilities()`, `registerSelf()`/`disableSelf()`, `forCapability()`; refuses governed-enable without a verified unexpired grant | `app/Services/Federation/CapabilityService.php` | **dev-now** | manifest advertise/learn + broker discovery |
| ★4 | Manifest on the wire: `handshakePayload()` + `PeerController::identity()/handshake()` + `PeerService::discover/receiveHandshake` gain `capabilities` | `InstanceIdentityService.php`, `PeerController.php`, `PeerService.php` | **dev-now** | JOIN — peers learn the role set |
| ★5 | `CapabilityProber` registry (per-slug `probe()`, Meter-C-affecting flag) | `app/Services/Federation/CapabilityProber.php` | **dev-now** | QUALIFY step |
| ★6 | `MeshRoleGrantService` — `request()` (opens `PeerUpgradeProposal` `KIND_ROLE_GRANT`), reuses A/B/C meters, `ratify()` mints grant + flips enabled, `lapse()`/`revoke()` | `app/Services/Identity/MeshRoleGrantService.php` | **dev-now** | APPROVE + JOIN |
| ★7 | `KIND_ROLE_GRANT` on `PeerUpgradeProposal` + ratify mints the capability grant | extend `PeerUpgradeAgreementService` + `PeerUpgradeProposal` | **dev-now** | APPROVE gate |
| ★8 | `broker_authorizations` table + `BrokerAuthorizationService` (gossiped, per-author verified) | `database/migrations/..._create_broker_authorizations_table.php` + service | **dev-now** | broker routing table (GATE 2.5b) |
| ★9 | In-mesh broker adapter: `/api/federation/cert-request` (raw bytes, `federation.signed:pinned`) → `Broker::issue()` from `broker_authorizations`; composer path-repo to `services/mesh-cert-broker` | `app/Http/Controllers/Federation/CertRequestController.php` + `InMeshBrokerService.php` | **dev-now** | reconciles Box-C-LAMP ⇄ in-mesh broker |
| ★10 | `mesh:request-cert {domain} {subdomain}` peer client (CSR + signed request + install) | `app/Console/Commands/MeshRequestCertCommand.php` + `CertClientService.php` | **dev-now** (offline-testable) | broker channel JOIN (live = rig) |
| ★11 | `cert_grant` minting on promotion (`type:"cert_grant"` + `capability` field) + `/api/federation/cert-grant` delivery | extend `LocalAutonomyService` (or `CertGrantService`) + `CertGrantController` | **dev-now** | authority grant-on-promotion |
| ★12 | `mesh:role` CLI: `qualify` / `request` / `approve` / `list` / `revoke` (+ `mesh:roles` listing, refuses to advertise un-approved) | `app/Console/Commands/MeshRole*Command.php` | **dev-now** | operator drives QUALIFY/REQUEST/APPROVE, GATE 2.5a |
| 13 | Channel-keyed `MeshGateService::evaluate()` + `ChannelCatalog` config | `app/Services/Federation/MeshGateService.php` | dev-now | console Role Board status |
| 14 | Console Role Board + per-channel panels + PENDING REQUESTS panel (`roles` prop) | `FederationConsoleController.php` + `Federation.vue` | dev-now | operator console §6 |
| 15 | Console controls: transport toggle/switch + broker.dns key-rotation (one-shot flash) | `Federation.vue` + 2–3 `auth:operator` POST routes | dev-now | console actions |
| 16 | Broker-readiness gates in `MeshGateService` + `mesh:doctor` (holds broker.dns? broker discoverable? valid cert?) | `MeshGateService.php`, `MeshDoctorCommand.php` | dev-now | operator certifies the broker channel |
| 17 | S2S grant delivery over `MultiplexClient::reach` (cross-instance) | `RoleGrantController` + routes | **rig** | cross-instance grant delivery |
| 18 | Per-box secrets: CF token (scoped Zone→DNS→Edit), authority private key, Synapse admin token, archive mount | `.env` / `config/domains.php` / secret store | **op-config** | QUALIFY (drop tokens) |
| 19 | Box C — LAMP broker-only host (Apache vhost `auth.<domain>`, `config/domains.php`, `lego`, CF token) | operator's LAMP/Azure box | **op-config** | GATE 2.5b broker box |
| 20 | Live cert issuance (LE staging→prod, real CF zone + `lego`); two-instance manifest learn; governed-channel refused without real grant | test rig | **rig** | GATE 2.5b certification + negative gate |

**MINIMAL set for the campaign = rows ★1–★12** + op-config 18–19 + rig 20. That's: the table, model,
manifest on the wire, the prober, the lifecycle service + `KIND_ROLE_GRANT`, the broker authorization
table + in-mesh adapter + `mesh:request-cert` + grant-on-promotion + the `mesh:role` CLI. The console
UI (13–16), the convenience controls (15), and S2S delivery (17) are the **fuller build** — the
campaign can be driven from the CLI before the Role Board UI lands.

---

## 8. CAMPAIGN THREADING

A new **PHASE 2.5 — "Roles & Channels of Trust"** slots between **GATE 2a** (CGA mesh trust
established) and **PHASE 3** (capability gates) in
`docs/plans/phase-g-continuation/MULTIBOX-CAMPAIGN-GK.md`. Box C is added as a fourth host in
**PHASE 0 pre-flight (P0.6)**. The three-actor handoff protocol (▶ / 🛑 NEED OPERATOR / 🔁 RELAY /
📋 REPORT) is **preserved verbatim**; Box C steps are tagged 👤 OPERATOR (host-level kit, no AI
session drives it). RELAY now also carries **channel artifacts** (a signed grant A→C, the returned
FQDN+cert C→A).

### 8.1 Dependency order (channels established as gates need them)

```
mesh.member  (PHASE 2, done)
   └─► broker.dns / broker.tls   (Box C — unlocks real TLS)        ← PHASE 2.5
         └─► matrix.homeserver / voice.sfu  (GATE 2b + LEG 7, reframed as channels)
               └─► authority.grant  (the G6 flip — GATE 3.G1)
```

### 8.2 The qualify → request → approve → join cycle per channel

For each channel, PHASE 2.5 runs one cycle:

1. **QUALIFY** — 👤 OPERATOR drops the token (CF token onto Box C; Synapse admin token onto A/B). The
   box self-detects qualification. `💻 DESIGNER` runs `mesh:role:qualify broker.dns` on Box A (prober
   hits CF live → green). 🛑 NEED OPERATOR if a token is missing.
2. **REQUEST** — `🤖 ASSISTANT` runs `mesh:role:request broker.tls` on the Pi (the graduating client
   box). Emits an auditable request row; the role-card shows "requested".
3. **APPROVE** — the dual-meter. On the rig there is **no seated test government**, so it runs
   **Meter A** (active operator board, R-08, 1⇒1 single-box / unanimity-of-the-pair). The cross-box
   `authority.grant` / `broker.dns`-for-a-peer's-name legs add **Meter C** unanimity — a 🔁 RELAY of
   the co-affected peer's consent. ⛔ HALT until the meter passes.
4. **JOIN** — on ratify, an `authority.grant` box mints the grant; the Pi's advert gains `broker.tls`;
   it re-requests its cert via `mesh:request-cert` with the now-minted grant (**closing the README's
   "still to wire" loop end-to-end**); 📋 REPORT confirms the cert issues. Every peer that discovers
   the Pi learns `broker.tls` into `instance_capabilities`.

### 8.3 Box C in P0.6

Stand up the LAMP broker box: drop the CF token + Box A's pinned authority key into
`config/domains.php`, run `bin/selftest.php` (offline acceptance), report ready. Batching the physical
work up front means the operator isn't interrupted mid-gate.

### 8.4 The two HALT gates

- **⛔ GATE 2.5a — role-set integrity.** `mesh:roles` lists a box's established channels and
  **refuses to advertise an un-approved channel.** Proves advertised-claim ≠ governed-role.
- **⛔ GATE 2.5b — the broker channel (live).** Box A (promotion-approved) gets a **real trusted
  `*.<domain>` cert** (staging→prod, two explicit REPORTs to spare LE prod rate limits) — *only
  because it was approved*. The operator watches a browser go green on a real subdomain.
  **Negative gate:** a non-promoted box's identical request is **REFUSED by `GrantVerifier`**
  (rogue-authority / tampered-grant / name-mismatch — already proven offline by `bin/selftest.php`).
  **De-promotion leg:** de-promote the Pi (or close its operator account) → the next renewal's meter
  check fails → the broker refuses re-issue → the cert lapses (a clean rig-observable negative gate,
  same family as GATE 3.G1's Meter-C refusal).

### 8.5 The campaign demonstrates (concretely)

- **Box A** holds `authority.grant` + `broker.dns`/`broker.tls` on the domain → grants **Box B**
  `client.serve` so B graduates to a real `*.domain` cert.
- **Box C** = the LAMP broker, run as the **same** GATE 2.5b with two deployment variants (LAMP +
  in-mesh) — proving the reconciliation.
- **Box D** requests `matrix.homeserver` → **REFUSED until the operator board attests** — proving the
  gate is enforced, not cosmetic.

The per-box visual is a **role card** (checklist of channels: qualified / requested / approved /
joined = the `mesh:roles` output) + a mesh-wide **boxes × channels matrix**. For the campaign, a
markdown matrix + the `mesh:roles` text output is **enough and dev-now**; the real Role Board UI is
the larger G-OP follow-up (rows 13–16).

---

## 9. OPEN DECISIONS — the operator must settle

- **A. `mesh.member` as a row.** Keep it as both implicit *and* a row (so the manifest is
  self-describing, matching transports always having ≥1 rung)? **Recommend: yes, both.** The TOFU
  handshake stays the bootstrap "join the mesh at all"; capability grants layer on top.

- **B. `broker.dns` vs `broker.tls` granularity.** The cert-broker fuses them (DNS-01 implies the zone
  token). Keep them **separate channels** (a box can host DNS naming without issuing certs) or collapse
  to one `broker.cert`? **Recommend: separate** — the operator's legal/IT constraint on holding a CF
  token is distinct from running `lego`.

- **C. Promotion granularity for `broker.tls`.** Full G6 earned-autonomy flip (requires a seated gov +
  parent grant, `authoritative_server_id` flip) or a **lighter** "serve-clients" grant gated only by
  the applicable meter (operator board in bootstrap)? Serving browsers is an *operational graduation,
  not an authority transfer*. **Recommend: lighter, meter-gated grant via a sibling `MeshRoleGrantService`
  — a box can host `broker.tls` WITHOUT becoming authoritative for a jurisdiction.** (Needs the
  operator's explicit call — this is the one the input designs flagged hardest.)

- **D. One request lifecycle vs a parallel table.** Reuse the existing `PeerUpgradeProposal` (new
  `KIND_ROLE_GRANT`) — *or* — ship dedicated `mesh_role_requests` / `mesh_role_grants` tables?
  Read-write authority is conceptually just the `authority.grant` channel. **Recommend: reuse
  `PeerUpgradeProposal` + `KIND_ROLE_GRANT`, grant receipts on the `instance_capabilities` row** (one
  lifecycle, no parallel approval machinery). Ship parallel tables only if grant audit needs a
  dedicated home.

- **E. Revocation speed.** Revocation-by-expiry (short TTL + renewal re-runs the meter check) for v1,
  or near-real-time (a `broker_authorizations.revoked_at` + gossiped CRL via
  `MeshOperatorService::revokeKey`)? **Recommend: expiry-only for v1** (least new machinery; matches the
  cert-broker's posture); CRL deferred to v2 like the operator-key CRL.

- **F. Founder self-grant at genesis.** At genesis there is exactly one operator (Meter A 1⇒1) and no
  seated government — so the founder box **self-grants** its first capabilities (`broker.dns` on its
  own domain) to bootstrap the naming root before any peers exist. **Confirm this matches intent** (the
  cert-broker authority-box scenario).

- **G. `authority.grant` cardinality.** Singular-per-mesh-subtree (one naming/cert root per domain) or
  freely multi-held? The broker already supports **multiple `authority_keys` per domain**. **Recommend:
  multi-held, with the domain's authorized set as the source of truth.**

- **H. Manifest and counted sync.** Should a capability mismatch refuse governance sync (like
  `constitutional_version` / Meter C), or stay advisory like transports? **Recommend: advisory** — a
  capability mismatch only affects which services a peer will dial; it must not refuse governance sync.

- **I. Vocabulary wall.** "role" / "channel" are operator-plane terms; the codebase already overloads
  "role" for citizen R-01…R-30 (`RoleService`, the plane wall). **Confirm the operator-plane
  channel/role vocabulary stays strictly on the operator plane** so it never collides with the
  constitutional role system. (The canonical names in §2.3 — `InstanceCapability`, `CapabilityService`,
  `MeshRoleGrantService` — deliberately say *capability*, not *role*, in code to avoid the collision.)

---

## 10. DECISIONS SETTLED — operator, 2026-06-20

All nine settled. Build proceeds on these.

- **A — agree.** `mesh.member` is both implicit AND a row (self-describing manifest); TOFU handshake
  remains the bootstrap "join at all", grants layer on top.
- **B — agree.** `broker.dns` and `broker.tls` stay **separate** channels (CF-token vs `lego` are
  distinct legal/IT constraints).
- **C — agree, with a refinement.** A box may hold `broker.tls` **independently** — a lighter,
  meter-gated grant via `MeshRoleGrantService`, NOT the full G6 authority flip (no `authoritative_server_id`
  change). **BUT the constitutional removal power persists:** the standing government can **de-pool a
  broker** — revoke *just that capability* from the pool (not eject the node) as a GOVERNANCE act. In v1
  (expiry-only revocation, decision E) this is the government **withholding the next grant** (de-authorize
  in `broker_authorizations` → renewal's meter check fails → the channel lapses); an explicit
  government-initiated revoke is the v2 CRL. So: *adoption is operational; removal is constitutional.*
- **D — agree.** Reuse `PeerUpgradeProposal` + `KIND_ROLE_GRANT`; grant receipts on the
  `instance_capabilities` row. No parallel request/grant tables.
- **E — agree.** Expiry-only revocation for v1 (short TTL + renewal re-runs the meter); gossiped CRL = v2.
- **F — confirmed.** The founder box **self-grants** its first capabilities at genesis (one operator,
  Meter A 1⇒1, no seated gov). A single box federated to nothing stands alone as the first node of a new
  mesh + bootstraps its own naming root.
- **G — agree.** `authority.grant` is **multi-held**; the domain's authorized set (`authority_keys` /
  `broker_authorizations`) is the source of truth.
- **H — agree.** Manifest is **advisory** — a capability mismatch only changes which services a peer dials;
  it NEVER refuses governance sync (unlike `constitutional_version` / Meter C).
- **I — refined (the recommendation is CORRECTED).** The **code-level** plane wall holds: `capability`
  (never `role`) in code, so `RoleService` citizen-role derivation is never contaminated. BUT operators
  are **NOT** outside the constitution — **the physical operator is a constitutional actor with real
  duties**: a duty to the in-game constitutional governance AND a duty to the physical law of where they
  operate, and they **report to their own operator board and/or the standing governments they serve.**
  This is the same operator-as-bridge the M-5 floor already encodes ([[project-phase-k3-illegal-content]]:
  operator acts physically + discloses to seated constitutional actors). So the wall is a CODE-HYGIENE
  measure (derivation isolation), not a denial that operators are duty-bearing participants. The operator
  console (§6) should surface this reporting duty; the constitutional model recognizes the operator class.
