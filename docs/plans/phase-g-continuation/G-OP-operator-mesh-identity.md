No existing operator-mesh concept exists — this is genuinely net-new. I have everything I need. Let me write the design.

---

# DESIGN: Operator-to-Mesh Federated Identity Layer

## 0. Framing & grounding in what already exists

**The citizen G-ID layer (exists, verified by reading the code):**
- `app/Models/User.php` — the citizen account: UUID PK, `home_server_id` (`NULL` = this instance is authoritative for the person), `is_operator` boolean, `password` (`'hashed'` cast, in `$hidden`). Created via `ConstitutionalEngine::file('F-IND-001', …)` except the founder (`SetupController::createFounder`, which sets `is_operator => true`). Roles are **always derived, never stored** (`RoleService::rolesFor`).
- `app/Services/Identity/ActorIdentityService.php` — enrolls a device's Ed25519 **public** key (`ActorDevice`); the secret never leaves the device (no escrow). Devices sign actions; `actionSigningString = METHOD\nTARGET\nTIMESTAMP\nsha256(body)`.
- `app/Services/Identity/AttestationService.php` — the **home-authority-only** CA: `issue()` refuses (`AttestationRefused('not_home_authority')`) unless `subject->home_server_id` is null or equals `identity->serverId()`. Signs a `StandingAttestation` (role-code SNAPSHOT bound to a device key) with the **instance** Ed25519 key. 24h TTL ceiling, signed CRL (`AttestationRevocation`), fails closed.
- `app/Domain/Engine/AttestedForwardedActor.php` + `AttestedActorContext` + `AttestationGate` — the forwarded-write path: verify attestation → verify device action-signature → resolve subject → place attested roles in request context for that one write.

**The instance/peer trust layer (exists):**
- `app/Services/Federation/InstanceIdentityService.php` — the **one** PKI: instance Ed25519 keypair on the `instance_settings` singleton, private key encrypted at rest with Laravel `Crypt` (APP_KEY-derived), `$hidden`. `sign()` / static `verify(pubKey,msg,sig)` / `sealTo()` (anonymous sealed box) / `openSealed()`. `handshakePayload()` shares `server_id + public_key + name + schema_version`.
- `app/Models/FederationPeer.php` — pinned peer: `server_id`, `public_key`, `status` (ESM-20), `relation` (sovereign/host/mirror). `scopeMatchingNeedle` resolves a uuid-or-url needle.
- `app/Http/Middleware/VerifyPeerSignature.php` — three modes: `public` / `tofu` (first-contact TOFU against body key) / `pinned` (against the pinned `public_key`), 300s replay window.
- `app/Services/Federation/PeerService.php` — `discover → initiateHandshake/receiveHandshake → upsertTrustedPeer` (TOFU pin to `trust_established`).
- `app/Services/Federation/DirectoryService.php` — the advisory, **deliberately powerless** signed `jurisdiction → endpoints` lookup (each entry signed by the server it names; verified against the named server's pinned key). This is the existing precedent for the traveler-routing surface.

**Constitutional grounding (read `docs/extracted/fair_constitution.md`):** the constitution is **silent on infrastructure operators** — every `administ*` hit is about *government* offices (election boards, exec departments, ethics offices), never the people who run the servers. Per the roadmap's §2 discipline, anything the Template is silent on is **[POLICY]**, lives in the flexible layer, and is **never promoted to the hardened layer**. So the operator role is **[POLICY] infrastructure**, distinct from the citizen franchise. This satisfies the prompt's Art. I requirement: a citizen needs nothing beyond residency; being an operator is an *infrastructure* status, not a constitutional privilege, and **must never appear in `RoleService` or grant any R-## role.**

---

## 1. Core principle: two orthogonal identity planes that never cross

| | **Citizen plane (G-ID, exists)** | **Operator plane (this design, new)** |
|---|---|---|
| Account | `users` (residency-derived) | new `operator_accounts` |
| What it certifies | derived governance standing (R-## roles) | infra/governance-of-the-instance role |
| Authority to attest | the person's **home** instance | the **mesh** (a quorum of trusted peers) |
| Signing key | per-**device** (`actor_devices`) | per-**operator-device** (`operator_devices`) |
| Identity binding | `home_server_id` | `mesh_operator_id` (a mesh-wide UUID) |
| Federates? | only short-lived attestations, never PII | only public-key bindings + signed claims, **never passwords/secrets** |
| Touches the engine / `RoleService`? | **never directly** (forwarded-write context only) | **never** |

**Hard wall (CI-blocking pin):** `operator_accounts` and `users` are separate tables with no FK between them and no shared identity. A human may be both (the founder is a citizen *and* an operator), but the app stores them as two unrelated rows. `RoleService::rolesFor` must never read the operator plane — grep-pin it the way "authority ≠ leadership" is grep-pinned. The operator role grants infra capabilities (consent to upgrades, peering decisions) and **zero** R-## governance roles.

---

## 2. Data model (additive only — new tables, per roadmap §2)

### 2.1 `operator_accounts` — the **local** operator login
```
id                    uuid PK
server_id             uuid       -- the instance this account is local to (instance_settings.server_id)
username              string     -- local login handle, unique per (server_id)
password              string     -- bcrypt/argon hash, $hidden, NEVER federated
mesh_operator_id      uuid null  -- the mesh-wide identity this local account is linked to (null = unlinked)
status                string     -- active | suspended | closed
last_login_at         timestamptz null
created_at/updated_at/deleted_at
UNIQUE (server_id, username)
INDEX (mesh_operator_id)
```
Registered at founding. The first one is created by the setup wizard (the natural extension of `SetupController::createFounder`, but on the operator plane, not on `users`). This is the username/password the prompt calls "registered when founding an instance."

### 2.2 `operator_devices` — the operator's signing keys (mirrors `actor_devices`)
```
id                    uuid PK
operator_account_id   uuid -> operator_accounts.id
device_public_key     string  -- Ed25519 PUBLIC key only; secret never leaves the device (no escrow)
label                 string null
enrolled_at           timestamptz
revoked_at            timestamptz null
```
This is the operator-plane analogue of `ActorDevice`. **This is the key that actually federates** — not the password. A device signs operator actions (upgrade consent, peering approval) exactly like `ActorIdentityService::verifyActionSignature`.

### 2.3 `mesh_operator_identities` — the **mesh-wide** identity (the synced object)
```
id                    uuid PK         -- the mesh_operator_id, minted once, stable across the mesh
display_handle        string          -- a non-secret human label (NOT a login; chosen at first link)
genesis_server_id     uuid            -- which instance first minted this mesh identity
created_at/updated_at/deleted_at
```
This row is **replicable**: it carries only a UUID + a display handle + provenance. No secret. Each instance that has a linked local operator holds a copy.

### 2.4 `mesh_operator_keys` — public-key bindings (the federated trust material)
```
id                    uuid PK
mesh_operator_id      uuid -> mesh_operator_identities.id
device_public_key     string          -- one of the operator's enrolled device public keys
bound_by_server_id    uuid            -- which instance asserts this binding
binding_signature     string          -- Ed25519 sig by bound_by_server_id over the canonical binding
status                string          -- active | revoked
bound_at              timestamptz
revoked_at            timestamptz null
UNIQUE (mesh_operator_id, device_public_key)
```
**This is the heart of the sync.** A mesh operator is *defined* by the set of device public keys bound to its `mesh_operator_id`, each binding signed by an instance the mesh trusts (a `FederationPeer` with a pinned key). Verifying "is this action from operator X?" = check the action signature against a public key whose binding to `mesh_operator_id = X` is signed by a trusted instance. Mirrors `DirectoryService`'s "each entry signed by the server it names" pattern exactly.

### 2.5 `mesh_operator_local_links` — local↔mesh sync ledger (audit of links)
```
id                    uuid PK
operator_account_id   uuid -> operator_accounts.id (local)
mesh_operator_id      uuid
linked_via_peer_id    uuid -> federation_peers.id  -- which authenticated peer the link rode in on
linked_at             timestamptz
unlinked_at           timestamptz null
```
Records "local operator account A on this instance is the same person as mesh identity M." This is the `home_server_id`-analogue made many-to-many: one mesh operator may have a local account on *several* instances (that is the whole point — a traveling operator).

> All five tables: UUID PK, `timestampsTz`, `softDeletesTz`, CHECK-enum strings, partial-unique indexes — per the roadmap §6 conventions. `audit_log.module` gains `operator_identity` (app-validated string, no migration), alongside the existing `actor_identity` / `federation`.

---

## 3. The local↔mesh sync protocol (built on Ed25519 peer trust + the attestation pattern)

The protocol reuses the *exact* primitives already in the tree. **Precondition: a `FederationPeer` is already `trust_established`** (`PeerService`), so both instances hold each other's pinned `public_key` and `VerifyPeerSignature('pinned')` works. Operator sync rides on top of that — it never introduces a second PKI.

### 3.1 Flow A — link a local operator to a NEW mesh identity (no mesh account exists yet)
1. Operator logs into instance B (local username/password → `operator_accounts`), enrolls an `operator_device` (Ed25519 pubkey).
2. Operator chooses "create my mesh operator identity." Instance B:
   - mints a `mesh_operator_identities` row (`genesis_server_id = B`),
   - creates a `mesh_operator_keys` binding: `device_public_key` → `mesh_operator_id`, signed by **B's instance key** (`InstanceIdentityService::sign` over a byte-stable canonical: `{mesh_operator_id, device_public_key, bound_by_server_id, bound_at}`, the `AuditService::canonicalJson` pattern),
   - writes `mesh_operator_local_links`, sets `operator_accounts.mesh_operator_id`.
3. Instance B publishes the new identity + key bindings to trusted peers over a **new signed endpoint** `POST /api/federation/operator/announce` (behind `VerifyPeerSignature('pinned')`). Peers ingest exactly like `DirectoryService::ingest`: verify the binding signature **against the binding's `bound_by_server_id` pinned key** (not the relayer's), then store. Replication is gossip — same posture as the directory feed.

### 3.2 Flow B — SYNC a local account to an EXISTING mesh identity (a traveling operator arrives at instance C)
1. Operator logs into C with a **local** C username/password (or registers one) and enrolls a device key on C.
2. Operator asserts "I am mesh identity M." C must prove it before linking:
   - **Proof = a device signature from an already-bound key.** The operator's existing device (already in `mesh_operator_keys` for M, bound by some trusted instance) signs a challenge `actionSigningString('POST','/operator/link', ts, sha256({mesh_operator_id:M, target_server_id:C, new_device_public_key:K_new}))`. C verifies the signature against the bound key (fetched via the announce feed / fetched live from a trusted peer). This is the **possession proof** — only someone holding M's private device key can produce it. Passwords are *never* involved cross-instance.
3. On valid proof, C:
   - links the local account (`mesh_operator_local_links`, sets `mesh_operator_id`),
   - binds `K_new` to M with a **C-signed** `mesh_operator_keys` row, and announces it to peers.
   - Result: M now has a local account on both B and C, and a device key bound by both. A traveling operator is recognized everywhere.

### 3.3 Revocation
- Lost device → `operator_devices.revoked_at` set locally **and** a signed `mesh_operator_keys` revocation announced (`status='revoked'`), gossiped like the attestation CRL (`AttestationRevocation`). Verifiers fail closed on a revoked binding, exactly as `AttestationService::verifyAttestation` fails closed on a revoked attestation.

### 3.4 Conflict / trust model
- A `mesh_operator_keys` binding is only honored if `bound_by_server_id` resolves to a **trusted** `FederationPeer` (or self). A hostile instance can only bind keys to mesh identities *it* genesis-minted or for which it already holds a trusted binding chain — it cannot hijack another operator because the link-proof in Flow B requires a signature from an **already-trusted** bound key. Authority-instance-wins (the existing federation conflict rule) applies: the `genesis_server_id` (or the operator's designated home instance) is authoritative for the identity's canonical handle.

---

## 4. THE hard security rule: never sync passwords/secrets across the mesh

This is the load-bearing invariant, enforced in three independent places (defense in depth, the codebase's house style):

1. **Schema/serialization wall.** `operator_accounts.password` is in Eloquent `$hidden` (like `User::$hidden` and `InstanceSettings::$hidden['private_key_encrypted']`). The announce/link wire payloads are *explicit allowlists* of fields (`mesh_operator_id`, `device_public_key`, `bound_by_server_id`, `binding_signature`, `bound_at`, `display_handle`) — a password field is structurally absent, never "filtered out."
2. **The `FORBIDDEN_SUBJECT_TYPES` extension** (roadmap §7 — "the privacy boundary grows each phase"). Add `operator_account` and the operator password/secret subject to `PublicRecordService::FORBIDDEN_SUBJECT_TYPES` and the four Phase-F export filters, so an operator credential can never ride the public-records sync tail or an export bundle. CI pin: assert the announce payload schema contains no `password`/secret key; assert `operator_accounts` is in the forbidden set.
3. **Only public keys + signed bindings federate.** Identical to G-ID: the device *secret never leaves the device* (no escrow); the mesh only ever sees Ed25519 **public** keys and instance signatures over them. The instance private key (`private_key_encrypted`) signs the bindings but is itself never transmitted — same as every existing federation message.

A local password resets are local-only (`password_reset_tokens`, already local). There is **no cross-mesh password.** Authentication across the mesh is **key-possession only** (device signature), never credential replay.

---

## 5. Interface to the versioning-agreement (the de-facto election board)

**Problem the prompt names:** WHO may consent to a version/schema upgrade across the mesh. The roadmap and `InstanceIdentityService::handshakePayload()` already carry `schema_version` (`config('cga.schema_version')`); a mesh of instances must agree before a breaking schema migration, or sync (which is byte-canonical) breaks.

**Design — `MeshUpgradeProposal` consented by mesh operators:**
```
mesh_upgrade_proposals
  id uuid PK · from_schema_version · to_schema_version · proposed_by_mesh_operator_id
  proposal_canonical (the exact bytes consented to) · status (open|adopted|rejected|expired)
  opens_at · closes_at · created_at...

mesh_upgrade_consents
  id uuid PK · proposal_id -> mesh_upgrade_proposals
  mesh_operator_id · consenting_server_id
  device_public_key (which bound key signed) · consent_signature (Ed25519 device sig)
  consented_at
  UNIQUE (proposal_id, mesh_operator_id)   -- one operator, one consent
```
- A consent is valid iff: (a) the `consent_signature` verifies against a `mesh_operator_keys` binding that is `active` and bound by a trusted instance, and (b) `actionSigningString('POST','/operator/upgrade-consent', ts, sha256(proposal_canonical))` matches. This **reuses `ActorIdentityService::verifyActionSignature` verbatim** on the operator plane.
- **The "de-facto election board"** = the set of mesh operators (one device-signed vote each). The *threshold* is **[POLICY]** (not a constitutional supermajority — operators are infrastructure, not a legislature). A defensible default: simple majority of distinct active `mesh_operator_id`s known to the proposing cluster, with a `closes_at` quorum window. This is a `constitutional_settings`-style amendable knob at planet root (e.g. `mesh_upgrade_consent_threshold_pct`), **not** a hardened rule — keep it explicitly out of the Constitutional Hard Constraints table.
- On adoption, the new `schema_version` is recorded; instances refuse to sync with peers below the agreed floor (a clean extension of the existing `metadata.schema_version` already pinned at handshake). This gives the mesh a controlled, *consented* migration rather than a unilateral operator flipping a schema and forking everyone.

**Why operators, not citizens:** a schema upgrade is an infrastructure act with zero governance content; making citizens vote on it would violate Art. I (it is not a franchise matter) and the "no governance advantage" rail. Operators consenting is the correct, [POLICY], infra-plane analogue.

---

## 6. Interface to traveler-routing (nearest node)

**Problem the prompt names:** route a traveling client to the nearest node. The mesh already has the powerless advisory `DirectoryService` (`jurisdiction → endpoints`, priority + freshness). The operator-identity layer adds the *who-am-I-everywhere* half so a client carrying a mesh operator (or, separately, a citizen's home pointer) can be steered.

**Design — `OperatorRoutingService` (advisory, reuses `DirectoryService` posture):**
- A linked mesh operator's client knows its `mesh_operator_id`. When it connects to any instance, that instance resolves candidate nodes from:
  1. the existing `DirectoryService::resolve(jurisdictionId)` for the operator's/citizen's jurisdiction of interest, then
  2. ranks by an advisory locality signal: peer `last_heartbeat_at` freshness + an optional `metadata` region hint + RTT measurement. **Like the directory, this is deliberately powerless** — it can only *suggest* an endpoint; authority is still checked at the destination (`WriteRouterService::executeForwarded` re-verifies `isLocalAuthority`), so a bad route at worst causes a 421 redirect, never a wrong write.
- **For the citizen traveler** (orthogonal but worth wiring the same surface): a citizen's `home_server_id` already pins their authoritative instance; routing sends *writes* home (existing `WriteRouterService.forward`) and serves *reads* from the nearest mirror. The operator layer does not change citizen routing — it adds the operator's own multi-home awareness (an operator with local accounts on B and C can be sent to whichever is reachable). New endpoint: `GET /api/federation/operator/nearest?mesh_operator_id=…` returning a signed, ranked endpoint list — self-authenticating like `DirectoryService::wire`.

**Crucial separation:** routing must consult **only public, advisory** data (directory entries, heartbeats, region hints). It must never read residency facts or operator credentials. The operator's *device key* identifies them for routing; their *password* is irrelevant to routing and never leaves home.

---

## 7. HTTP surface & middleware (all new, all reuse existing patterns)
- `POST /api/federation/operator/announce` — gossip identity + key bindings. `VerifyPeerSignature('pinned')`. Ingest = `DirectoryService::ingest` clone (verify against the **bound_by** server's pinned key).
- `POST /api/federation/operator/link` — Flow B link-proof verification (device-signature challenge).
- `POST /api/federation/operator/upgrade-consent` — record a device-signed upgrade consent.
- `GET /api/federation/operator/nearest` — advisory routing.
- All operator-plane *local* auth (login, device enroll) is **session-based on a separate guard** (`auth:operator`), not the `web` citizen guard — keeps the two planes from ever sharing a session principal. Operator login is rate-limited; failures audited under `operator_identity`.

---

## 8. Closing section (required)

### (a) Roadmap letter / sub-phase
This is **net-new** (grep confirmed: no `mesh_account`/`operator_mesh`/`nearest_node` exists). It is **Phase G Track B** material — the same track as G-ID, co-member clusters, and the autonomy vote — and slots in as a **new sub-phase, "G-OP" (Operator Mesh Identity)**, parallel to the existing **G-ID** citizen layer. It depends only on shipped G primitives (`InstanceIdentityService`, `FederationPeer` trust, `DirectoryService`, the attestation/device-signature pattern) and the dev stack — **no physical rig needed**, so it is buildable now alongside the H–M work the roadmap says is "buildable + testable NOW." It is *not* a new lettered phase H–O; it completes G's identity story. The `schema_version` consent piece directly hardens the cross-instance sync that every later phase (K Path B, M UBI, N corpus) rides on.

### (b) OPEN DECISIONS for the operator
1. **Upgrade-consent threshold & quorum** — simple majority of active mesh operators? Weighted by population served? A fixed council? This is [POLICY]; the default proposed (simple majority, amendable settings key) needs your sign-off. Also: does a *single-instance* mesh (one operator) auto-consent, or still require an explicit click?
2. **Who may mint a mesh operator identity** — any local operator self-serve (Flow A open), or only after a peer vouches? G-ID's mirror-adoption used a vouch/request model (`ClusterAdoptionRequest`); mirror that, or keep operator minting permissionless?
3. **Authoritative home for a mesh operator** — `genesis_server_id` forever, or a movable "operator home" pointer (operator-plane analogue of `home_server_id`)? Movable is more traveler-friendly but adds an authority-flip-style handover.
4. **Founder bootstrapping** — should `SetupController::createFounder` *also* create the founder's `operator_account` + mint a mesh identity in the same wizard step, or keep operator-account creation a distinct post-setup action? (Recommend: create the local operator account at founding; mesh-link is opt-in later.)
5. **Routing locality signal** — RTT probing vs. static region hints vs. GeoIP. RTT is most honest but needs client cooperation; region hints are simplest.

### (c) RISKS
1. **Plane leakage** — the single worst failure: an operator capability bleeding into the citizen governance plane (or vice versa). Mitigate with the grep-pin (`RoleService` never reads operator tables) and a separate `auth:operator` guard. Must be a CI-blocking pin from day one.
2. **Operator capture of the upgrade gate** — if mesh-operator minting is permissionless (Flow A open), a Sybil operator can stack upgrade consents and force/block a schema migration. Mitigation: the consent threshold + the vouch decision (open decision #2). This is the operator-plane analogue of UBI's Sybil problem — flag it as such.
3. **Key-binding gossip trust** — a `mesh_operator_keys` binding is only as trustworthy as the instance that signed it; a compromised-but-trusted peer can bind a rogue device key. Mitigation: bindings are signed + auditable + revocable (CRL pattern); the link-proof in Flow B requires an *already-trusted* bound key, so a rogue peer can't bootstrap a new operator from nothing.
4. **Credential exfiltration regression** — a future careless `->toArray()` on `operator_accounts` in an announce/export path could leak a hash. Mitigation: `$hidden` + explicit allowlist wire payloads + the `FORBIDDEN_SUBJECT_TYPES` pin + a test asserting the announce schema has no secret-shaped key. (This is exactly the class of regression the G-V2 deploy-hardening rounds taught: a fix verified one way can regress another path — pin it everywhere.)
5. **Routing as a covert authority channel** — if `OperatorRoutingService` ever became load-bearing for *authority* (not just suggestion), it would violate "authority ≠ leadership." Keep it powerless like `DirectoryService`; pin that a routing answer can never be consulted in an authority decision.

**Files read (all absolute):** `app/Services/Federation/InstanceIdentityService.php`, `FederationClient.php`, `PeerService.php`, `DirectoryService.php`, `WriteRouterService.php`, `OperationalBundleService.php`; `app/Services/Identity/{AttestationService,ActorIdentityService,AttestationGate,AttestedActorContext}.php`; `app/Domain/Engine/AttestedForwardedActor.php` + `Contracts/ResolvesForwardedActor.php`; `app/Http/Middleware/VerifyPeerSignature.php`; `app/Models/{User,FederationPeer,InstanceSettings,StandingAttestation,ActorDevice,Cluster,ClusterMembership}.php`; `app/Http/Controllers/SetupController.php` (`createFounder`); `app/Http/Controllers/Dev/ImpersonationController.php` + `app/Services/RoleService.php` (`is_operator` usage); `database/migrations/2026_06_12_000002_rebuild_users_uuid.php`; `docs/extracted/fair_constitution.md`; `docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md`. This is a design only — no files were created or edited.