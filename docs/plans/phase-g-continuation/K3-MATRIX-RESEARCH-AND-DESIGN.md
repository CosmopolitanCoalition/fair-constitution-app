# Matrix as the CGA Social Engine — Research & Design Round (Phase K-3 deepening)

> **Status:** Committed design round. No code written; read-only synthesis.
> **Slot:** Phase **K-3 "The Mesh Commons"** (sub-phase of K, per DECISIONS N1).
> **Extends + corrects:** `docs/plans/phase-g-continuation/K3-social-layer-matrix.md`.
> **Companions (already drafted, folded in here):** `K3-moderation-legitimacy-flip.md` (§5), `social-engine-integration` (§6).
> **Constitutional posture:** the **four moderation carve-outs (M-1..M-4)** and *who-may-invoke-which* are HARDENED (they protect the franchise of expression the way ballot secrecy protects the vote); thresholds / activation tiers / rate-limit knobs are POLICY (amendable). **The legitimacy flip itself is structural, not amendable.**
> Corrections to the prior K-3 synthesis are flagged `[CORR]` inline. Every Matrix claim cites a primary source; where a claim could not be verbatim-verified this round it is flagged in §8/§9 or inline as `[unverified]`.

---

## 0. Executive summary — and what's NEW vs the existing K-3 doc

Matrix is **not** "add a chatroom." It is the **integration fabric that glues the three CGA entity classes** — **jurisdictions** (the recursive `jurisdictions` tree), **organizations** (`organizations` rows: parties, businesses, nonprofits, CGCs), and **people** (`users`, pseudonymous) — into one federating, realtime social topology whose membership, visibility, and posting rights are **derived live from constitutional roles, never from a stored Matrix admin bit**. The CGA appservice is the sole bridge between "what Postgres knows about standing" and "what Matrix lets you do," and it is **rule-bound, carve-out-only, structurally incapable of viewpoint censorship in public rooms.**

Three load-bearing reframings the rest of the doc depends on:

1. **Matrix's authority model is a stored integer (power levels); the CGA's is derived-live + consent-traced.** The whole integration is the discipline of never letting a human hold standing Matrix power in a public room, and of translating a live `AttestationService` role snapshot into a *transient* appservice action instead.
2. **Matrix's own version-control machinery** (room versions, the event/auth DAG, state-resolution v2, room upgrade, the MSC process) **is a near-exact structural analogue of the CGA's `constitutional_version` + hash-chained `audit_log` + `PeerUpgradeAgreementService`** — a set of mechanisms to *reuse* and tensions to *resolve* (§2, §5, §6).
3. **Two planes, bridged not merged.** Plane A = the append-only civic record (`social_*` + `public_records` + `audit_log`, the Art. II §2 durable artifact). Plane B = the live Matrix commons (ephemeral relative to A). The **testimony bridge** (§6.5) is the one-way valve between them.

**What's NEW / corrected relative to `K3-social-layer-matrix.md` (all detailed below, all flagged `[CORR]`):**

1. **Auth → delegated OAuth2/OIDC (MAS / MSC3861)**, not bespoke "appservice-as-SSO." The homeserver never sees a password; tokens are short-lived + refresh-rotating, mirroring `AttestationService`'s ≤24h TTL. (§6.2)
2. **Public rooms → room version 12 "immutable sole creator" appservice**, not "appservice holds the only PL≥50." v12 creator-power is infinite, immutable, and unencodable in `m.room.power_levels` — strictly stronger than a 100-vs-100 race. (§4, §5.1, §6.4)
3. **Removals are best-effort across the mesh, NOT guaranteed erasure.** A Matrix redaction keeps the event ID; other servers retain the original and *may decline* to honor it. The durable constitutional artifact is the **`audit_log` entry**, not the disappearance of bytes. (§4, §5.6, §6.5)
4. **Soft-fail is the right primitive for M-4 (anti-spam) + reversible M-1 (judicial)**; hard content-stripping redaction is reserved for **M-2 (rights-protection / triad-leak / doxxing)** and always logged. (§4, §5.6, §6.5)
5. **Mesh-gating is enforced at the homeserver-config + per-room `m.room.server_acl` layers**, NOT by a Matrix-native federation allowlist (Matrix has none) — plus the documented `allow:[]` self-brick footgun guard. (§3, §6.4)
6. **Add cross-signing as the person-trust layer** (master / self-signing / user-signing), with `AttestationService` as the user-signing authority. Absent from the prior doc. (§3, §6.2)
7. **Constrain MSC4284 Policy Servers (now spec-merged, v1.18) and MSC2313 ban-list rooms** to the four carve-outs only on public rooms; viewpoint filtering forbidden. (§4, §6.4)
8. **`constitutional_version` ↔ Matrix room version are loosely coupled (Meter-C admission gate), never hard-pinned.** An upstream Matrix decision must never *trigger* a CGA constitutional event. (§2.6, §6.6)
9. **v12 is now the spec-recommended default** — query the `m.room_versions` capability live, never hardcode. (§2, §6.4)

The centerpiece (§5) is **the legitimacy-gated moderation-control flip**: control over moderating a *public* room is the *same kind of authority* as control over a physical jurisdiction — it begins as the temporary, de-facto standing of whoever runs the box (R-08-anchored election-board steward) and **flips, by the same legitimacy gate that flips physical authority (G-VER Meter A→B; G6 `LocalAutonomyService`), to the seated constitutional bodies** the moment a jurisdiction seats a government — and at no point in either phase may *anyone* viewpoint-censor a public room.

---

## 1. Matrix decentralization & federation — researched facts

Matrix is a federation of **homeservers** communicating over the **server-server (S2S / federation) API** under `/_matrix/federation/v1/` + `/v2/` (keys under `/_matrix/key/v2/`). The trust anchor is per-server Ed25519 keys + per-event signatures, **not** transport.

**Homeserver + replicated-room model.** A Matrix room has **no home server**. Each homeserver whose users have joined a room (identified by a Room ID) holds its *own* full copy of that room's event DAG and computes room state independently. There is no authoritative copy and no central server for a room. — `https://spec.matrix.org/latest/server-server-api/`

**Transaction transport.** All inter-server traffic is pushed as **transactions** via `PUT /_matrix/federation/v1/send/{txnId}`, carrying `origin`, `origin_server_ts`, a `pdus` array, and an `edus` array; per-transaction hard caps are **at most 50 PDUs and 100 EDUs**. Delivery is the *originating* server's responsibility (push/broadcast, not pull) — it must deliver each PDU to every server with members in the room. — `https://spec.matrix.org/latest/server-server-api/`

**PDU vs EDU.** **PDUs** (Persistent Data Units) are room events: persisted, signed, part of history/state, broadcast to all servers in the room, subject to authorization + state resolution. **EDUs** (Ephemeral Data Units) are pushed point-to-point between a *pair* of servers, not persisted, not part of history (e.g. typing/receipts/presence). — `https://spec.matrix.org/latest/server-server-api/`

**Room DAG (per-room hash-linked Merkle-DAG).** Every PDU references `prev_events` (the recent events it builds on — the causal/forward chain) and `auth_events` (the events granting the sender permission). Each event carries `depth`, a `hashes` object (SHA-256 content hash), and `signatures`. Because **event IDs ARE content (reference) hashes**, those back-references are cryptographic — tampering with any ancestor changes its ID and breaks the link. — `[CORR]` the widely-cited "≤20 prev_events / ≤10 auth_events" hard limits are **NOT in the current normative spec** (historical Synapse limits); the spec only says they are "limited in number." Treat both as "bounded/small"; do not cite the numbers as spec constants. — `https://spec.matrix.org/latest/server-server-api/` (backfill section)

**Event ID = reference hash (room v4+).** The event ID is the event's *reference hash*, URL-safe unpadded base64 (62nd/63rd chars `-`/`_`), prefixed `$`. Two-stage hashing: (1) the **content hash** (SHA-256 over the full event JSON) is stored at `event.hashes.sha256` *before signing* (so it survives redaction); (2) the **reference hash** = run the event through the redaction algorithm, then SHA-256, then base64 → the event ID. Redaction-before-hash is why redacting an event does not change its ID. — `https://spec.matrix.org/latest/rooms/v11/`, `https://spec.matrix.org/latest/appendices/`

**Trust model — per-request AND per-event Ed25519 signatures.** TLS (1.2+) secures the wire but is not the identity anchor. (a) Every federation request carries an `Authorization: X-Matrix` header with `origin`, `destination` (must equal the receiver's name, added v1.3), `key`, `sig`; the receiver verifies against the origin's published key. (b) Every PDU is independently signed by its origin server and verified **regardless of which server relayed it** — so events transit untrusted intermediaries safely. Server keys are published at `GET /_matrix/key/v2/server` and can be fetched via a notary at `/_matrix/key/v2/query`. — `https://spec.matrix.org/latest/server-server-api/`

**Event authorization — three-pass + soft-fail.** On receiving a PDU a server checks it against (1) its own `auth_events`, (2) room state *before* the event, (3) the room's *current* state. If the first checks pass but current-state fails, the event is **soft-failed**: accepted into the DAG and used in state resolution, but **not relayed to clients and not referenced by new local events** — blocking ban-evasion via backdated events. — `https://spec.matrix.org/latest/server-server-api/` (PDU-receipt checks)

**Join-over-federation handshake (`make_join`/`send_join`), backfill/gap recovery, and server-name resolution + `.well-known` delegation** all follow the spec's ordered fallbacks; the delegation target must serve a TLS cert valid for the **original** hostname (DNS is not the trust anchor — server-name + Ed25519 keys are). — `https://spec.matrix.org/latest/server-server-api/`

**Eventual consistency + state resolution (no central authority).** On partition, each server keeps accepting events on its own DAG branch; on merge, a room-version-specific **state resolution** deterministically picks the winning state (v2+: iterative auth checks + mainline ordering by power level). All honest servers run the same rules over the same DAG and converge without a coordinator — authority is in the cryptographic rules, not any server. — `https://spec.matrix.org/latest/rooms/v11/`

**CGA relevance:** Matrix's replicated-room + per-event-signature model maps cleanly onto the CGA `federation_peers` mesh. CGA already runs per-instance Ed25519 server identity (`InstanceIdentityService` `sign/verify/sealTo`) and pinned-peer keys (`federation_peers`) — exactly the `GET /_matrix/key/v2/server` `verify_keys` role. The CGA's pinned-peer model is **stricter than Matrix's notary trust** — pinned keys eliminate the malicious-notary surface entirely (§3). **Scale warning:** Matrix replicates every PDU to every participating homeserver + stores the full DAG everywhere; a planet-scale single room is pathological — the design forbids one giant room and treats Plane A (not live Matrix) as the durable record (§6.1).

---

## 2. Matrix version control — researched facts + alignment with `constitutional_version` and the audit DAG

This is the alignment the prior K-3 doc lacked. The CGA's G-VER stack is **already built** — `ConstitutionalVersionService` (the derived `cv1.<32hex>` hash of `HARDENED_SURFACE`), `UpgradeFreezeService` (the Art. II §7 subtree freeze), `PeerUpgradeAgreementService` (Meters A/B/C). Matrix's machinery is a structural mirror.

### 2.1 Room versions are per-room, immutable-once-set rule bundles ≅ `constitutional_version`

A room's version is fixed at creation in `m.room.create.content.room_version` (e.g. `"12"`) and **never changes in place**. Each version is a frozen bundle of four rule families: **(a) event format, (b) auth rules, (c) the redaction algorithm, (d) the state-resolution algorithm.** — `https://spec.matrix.org/latest/rooms/`

| Matrix room-version rule family | CGA hardened-surface analogue |
|---|---|
| auth rules (who may do what) | STV/Droop counting, supermajority/quorum formula, `ConstitutionalValidator` |
| redaction algorithm (what survives removal) | **the four carve-outs M-1..M-4** — which removals are legitimate and what they may strip |
| event format / hashing | the `audit_log` event/hash-chain format |
| state-resolution algorithm | the federation conflict-resolution rule (`authoritative_server_id`) |

The CGA `constitutional_version` is a derived hash of the **hardened-compute surface** — `ConstitutionalVersionService::HARDENED_SURFACE` (the Counting domain, `VoteCountingService`, `ConstitutionalValidator`, `DistrictingService`, `ElectionTriggerService`, `ApprovalService`, `CoDeterminationService`). The string is `cv1.<32hex>` (SHA-256, CRLF→LF normalized cross-platform). **Adopt-from-Matrix:** v12's move where **room ID = hash of the create event** is *exactly* the `cv1.<hash>` move — identity IS the hash of the founding rule set. The CGA already does this correctly; v12 validates the design. — `https://spec.matrix.org/v1.16/rooms/v12/`

**Version history (each version states what it changed):** v1 initial; v2 = State Resolution v2; v3 = event IDs become the reference hash; v4 = URL-safe base64 IDs; v5 = signing-key validity periods enforced; v6 = auth-rule changes; v7 = knocking; v8 = restricted join; v9 = membership-redaction fix; v10 = integer-only power levels; v11 = redaction-algorithm clarification (`redacts` moves under `content`; `m.room.create` keeps its whole content); v12 = room IDs become the create-event hash, **infinite-power immutable creators** (+ `additional_creators`), iterated state resolution. `[CORR]` **v12 is now the spec-recommended default** ("Servers SHOULD use room version 12 as the default room version when creating new rooms"); do not hardcode — query the `m.room_versions` capability live. — `https://spec.matrix.org/latest/rooms/`, `https://spec.matrix.org/v1.16/rooms/v12/`, `https://matrix.org/blog/2025/09/17/matrix-v1.16-release/`

### 2.2 Room upgrade ≅ the constitutional-version flip (new-room + double-link, never in-place)

Matrix **never mutates a room's rules in place.** `POST /_matrix/client/v3/rooms/{roomId}/upgrade` creates a *new* room at the new version, copies forward important state, and **bidirectionally links** old↔new: an `m.room.tombstone` (`{body, replacement_room}`) forward-pointer in the old room, and a `predecessor` object (`{room_id, event_id}`) inside the new room's `m.room.create`. The old room's power levels are raised so it becomes **read-only**. `[CORR]` As of v1.16+ the predecessor `event_id` **MAY be omitted** (v12 room IDs are themselves the create-event ID); there is **no** separate `m.room.predecessor` state event — predecessor stays inside `m.room.create.content`. — `https://spec.matrix.org/latest/client-server-api/` (Room Upgrades)

This is the exact shape a `constitutional_version` bump should take, and it strengthens `UpgradeFreezeService`:
- **Tombstone read-only ≅ the Art. II §7 freeze.** `UpgradeFreezeService::assertThawed()` blocks an upgrade while any live process exists in the subtree (uncertified election, open MJV, live emergency power, in-flight vacancy). Matrix's "raise power so the old room is read-only" is the same instinct: freeze the predecessor, migrate forward.
- **Double-link ≅ preserve history.** Record an explicit predecessor pointer + tombstone-equivalent on a version flip — never rewrite the live institution; instantiate the new-version institution and bidirectionally link it, carrying seated state forward. Matches the existing `judicial_remedy` law-version "full history preserved" pattern.

### 2.3 State resolution v2 ≅ deterministic, independently-checkable "authoritative-wins"

State Resolution v2 splits state into **unconflicted** (identical across inputs — auto-keep) vs **conflicted**; computes the **auth difference** (events in some but not all auth chains); orders power events by **reverse-topological power ordering** (auth-chain topology, tie-broken by sender power, then `origin_server_ts`, then event ID); runs **iterative auth checks**; then orders remaining conflicted events by **mainline ordering**; finally re-overlays the unconflicted map. Every honest server converges identically with no coordinator. — `https://spec.matrix.org/v1.11/rooms/v11/` (State Resolution v2)

**Adopt:** the CGA's "eventual consistency, authoritative-instance-wins" is currently last-writer-ish. `FederationSyncService`/`MirrorService` ingest can upgrade to **"deterministic + independently checkable"** — unconflicted public records merge freely; conflicting records get re-authorized against the hardened rule set in a power-and-timestamp ordering, so every honest CGA instance reaches the identical merged civic record. This makes "authoritative-wins" *auditable* rather than *trusted*.

### 2.4 The auth DAG ≅ uncensorable moderation

Matrix authorizes each event against its **own cited `auth_events`**, not against whatever the receiver currently believes. `auth_events` for `m.room.create` is empty; for any other event it references the create event (version-dependent), the current `m.room.power_levels`, the sender's `m.room.member`, plus (for membership) the target's membership + `m.room.join_rules`. An event is valid only if it passes the ordered auth-rule checks against the state formed by its *own* `auth_events`. — `https://spec.matrix.org/latest/server-server-api/`, `https://spec.matrix.org/v1.11/rooms/v11/`

This is *how* "the appservice holds power" becomes genuinely uncensorable across the mesh (§6.4): if removal-power is encoded in the versioned auth rules and the appservice's authority traces to derived office roles (via `AttestationService`), **any peer can replay the auth checks and independently verify a redaction was M-1..M-4-legitimate.** An operator cannot forge a censorship event — it fails the auth DAG on every other instance.

### 2.5 The MSC process ≅ `PeerUpgradeAgreementService` (Meters A→B→C)

Matrix evolves its hardened rules via a governed, implementation-gated pipeline: Draft → Proposal-In-Review → Proposed-FCP → FCP → Merged. The **Final Comment Period starts only when a 75% Spec-Core-Team majority agrees on a disposition** (merge/close/postpone) and runs a fixed 5 calendar days; crucially **a working implementation must be demonstrated before merge**. Pre-acceptance features ship behind **unstable prefixes** (`/_matrix/client/unstable/<vendor>.mscXXXX/...`; `m.room_versions.available[v]='unstable'`). — `https://spec.matrix.org/proposals/`, `https://github.com/matrix-org/matrix-spec-proposals`

| Matrix MSC mechanism | CGA G-VER analogue |
|---|---|
| 75% SCT threshold to *start* FCP | CGA's **"2/3 of ALL serving members"** supermajority floor — the gate to even start a ratification window (`PeerUpgradeAgreementService` reuses `ConstitutionalValidator`) |
| "working implementation before merge" | require a **passing constitutional-test-suite / dry-run against the new `constitutional_version`** before a flip finalizes (G-VER "certify-boundary version assert" + ratify=finalize) |
| unstable-prefix canary | stage a proposed `constitutional_version` as **experimental / advertised-but-not-authoritative** before a ratified flip |

### 2.6 Should a constitutional upgrade pin a Matrix room version? — **No: loosely couple.**

**Recommendation: keep `constitutional_version` and Matrix room versions decoupled, with one advisory link.** Rationale: (1) Matrix room versions evolve on the **Matrix Foundation's** schedule (MSC), which the CGA does not govern — hard-binding would let an upstream Matrix decision *trigger* a CGA constitutional event, a legitimacy violation (an upgrade not consented to by the governed). (2) The `HARDENED_SURFACE` (counting/validator/districting) has **no overlap** with Matrix's auth/redaction/state-res rules — a `cv1` bump is about vote math; a room-version bump is about DAG semantics; they're orthogonal. (3) **The one link:** Matrix room participation **may** be gated on a *compatible* `constitutional_version` the same way `make_join?ver=...` gates on a compatible room version — i.e. a co-member peer must run a `constitutional_version` your instance accepts. The G-VER **Meter C** peer-mesh sync-refusal already does this for the records tail; extend the same check to Matrix S2S admission. This is **gating, not pinning** — a constitutional flip does *not* rewrite live rooms.

---

## 3. Matrix mesh security — researched facts

**Per-server Ed25519 signing keys.** Each homeserver holds Ed25519 keypairs identified `algorithm:version`; public keys at `GET /_matrix/key/v2/server` carry `verify_keys`, `old_verify_keys` (with `expired_ts`), and a REQUIRED `valid_until_ts`. Hard cap: a server "MUST use the lesser of this field and 7 days into the future" — so a key is never trusted >7 days without re-fetch, bounding a compromised key's blast radius. — `https://spec.matrix.org/latest/server-server-api/`

**Event integrity = two hashes + signature, in order.** (a) **Content hash:** strip `unsigned`/`signatures`/`hashes`, Canonical-JSON the full event, SHA-256 → `hashes.sha256`. (b) **Redact** per the room-version algorithm and **sign** the redacted form. (c) **Reference hash** (= event ID): redact, strip `signatures`+`unsigned`(+`age_ts`), Canonical-JSON, SHA-256, base64. On receipt: verify the signature against the **redacted** form (so it survives redaction); recompute the content hash over the full event — **mismatch ⇒ keep the redacted copy you computed** (a relaying server can strip content but cannot forge the signed skeleton). **Signature failure ⇒ dropped; hash failure ⇒ redacted-but-kept.** — `https://spec.matrix.org/latest/appendices/`, `https://spec.matrix.org/latest/rooms/v11/`

**Rejection vs soft-failure (ordered PDU-receipt checks).** valid event (else DROP) → signatures (else DROP) → hashes (else REDACT) → auth vs own `auth_events` (else REJECT) → auth vs state-before (else REJECT) → auth vs current state (else SOFT-FAIL) → **`[CORR]` a 7th Policy-Server check (else soft-fail), Added in v1.18.** Soft-failed events are not relayed to clients and not used as forward extremities, but still participate in state resolution. — `https://spec.matrix.org/latest/server-server-api/` (PDU-receipt checks)

**E2EE — Olm + Megolm.** Olm (`m.olm.v1.curve25519-aes-sha2`) is a Double-Ratchet 1:1 channel used to deliver room keys; Megolm (`m.megolm.v1.aes-sha2`) is a group ratchet giving forward secrecy without per-message asymmetric crypto. Each device has a Curve25519 identity key + Ed25519 fingerprint key; **server-side key backup stores ciphertext the server cannot read** (`m.megolm_backup.v1.curve25519-aes-sha2`). — `https://spec.matrix.org/latest/client-server-api/#end-to-end-encryption`

**Cross-signing + device verification.** Three keys: **master** (`m.cross_signing.master`, per-user root of trust), **self-signing** (signs the user's own devices), **user-signing** (signs *other* users' master keys). Verifying one device transitively trusts that user's self-signed devices; verifying another user's master key trusts their whole device set. — `https://spec.matrix.org/latest/client-server-api/#cross-signing`

**Access-token auth + the move to OAuth2/OIDC (MAS, MSC3861).** Classic opaque bearer tokens (`Authorization: Bearer`); NEW (spec v1.15+; matrix.org migrated 2025-04-07): a separate OAuth 2.0 API discovered at `GET /_matrix/client/v1/auth_metadata`. Auth is delegated to an external OIDC provider (Matrix Authentication Service) — **the homeserver never sees passwords**; tokens become short-lived + refresh-rotating. — `https://matrix.org/blog/2025/04/matrix-auth-service/`, `https://github.com/matrix-org/matrix-spec-proposals/pull/3861`

**Rate limiting.** Server-side throttling returns `M_LIMIT_EXCEEDED`; clients honor the RFC-9110 `Retry-After` header on HTTP 429. — `https://spec.matrix.org/latest/client-server-api/`

**Threat boundary.** A malicious/compromised homeserver **cannot** forge or alter another server's events (Ed25519 over the redacted skeleton), **cannot** silently tamper content (content-hash mismatch → content dropped), **cannot** fabricate event IDs, **cannot** push auth-rule-violating state (every receiver re-checks against `auth_events`), and **cannot** read E2EE content/backups. It **can**, for its own users/rooms: see plaintext in *unencrypted* rooms, withhold/reorder/lie-by-omission to its own clients, and do metadata surveillance. — `https://spec.matrix.org/latest/server-server-api/`

**CGA relevance:** the malicious-homeserver boundary is **exactly** the model for de-facto operators (§5): a box operator has temporary R-08-anchored standing but the crypto guarantees they cannot rewrite the civic record, impersonate officeholders (their identity never touches `RoleService` / never gets a valid role attestation), or censor public rooms (appservice-monopoly creatorship enforced by self-validating room state). Residual risks (withholding, metadata) are mitigated by mesh redundancy (multiple peers hold the signed tail) + E2EE for private data. Cross-signing maps onto `ActorIdentityService` (per-person un-escrowed device keys) + `AttestationService` as the user-signing authority (§6.2). The 7-day `valid_until_ts` ceiling is a good pattern for `InstanceIdentityService::rotate()` and aligns with `AttestationService`'s already-short-lived (≤24h) snapshots.

---

## 4. Matrix moderation tooling — researched facts (and where each fits a carve-out)

**Power levels (`m.room.power_levels`).** Integer ladder. `[CORR]` spec-vs-implementation divergences: prose says `invite` defaults to 50, but the canonical schema and Synapse use **0** (matrix-spec #1019/#860); `kick`/`ban`/`redact`/`state_default`=50 and `users_default`/`events_default`=0 are undisputed. If a room has **no** `m.room.power_levels` event at all, `state_default` and `events_default` fall to 0. A user can act only on users with a **strictly lower** power level, and can grant only up to their own level. **Design rule: the appservice must EXPLICITLY set every PL field at room creation; never rely on a default.** — `https://github.com/matrix-org/matrix-spec/issues/1019`, `https://github.com/matrix-org/matrix-spec/issues/860`, `https://spec.matrix.org/latest/rooms/v11/`

**Redactions (`m.room.redaction`).** A redaction is itself an event requesting removal of another event's *content*; it does **not** delete the event. The redaction algorithm strips all but a versioned allowlist of keys (in v11, e.g. `m.room.power_levels` keeps `ban/events/.../users`; `m.room.create` keeps ALL keys). `[CORR]` **Redaction is best-effort across federation** — each homeserver controls its own copy, the event keeps its ID, and a hostile/offline server may retain/serve the original. — `https://spec.matrix.org/latest/rooms/v11/`

**Server ACLs (`m.room.server_acl`).** Bans whole servers from a room via `allow`/`deny` globs + `allow_ip_literals`. Order: IP-literal denied if `allow_ip_literals:false` → DENY (takes precedence) → ALLOW → else deny. `[CORR]` **`allow:[]` self-bricks the room**, and an ACLed-out server cannot even send its own leave event (Synapse #5468; matrix-spec #397). Not retroactive. — `https://docs.rs/ruma-events/latest/src/ruma_events/room/server_acl.rs.html`, `https://github.com/matrix-org/synapse/issues/5468`, `https://github.com/matrix-org/matrix-spec/issues/397`

**Policy / ban-list rooms (MSC2313).** Three state types `m.policy.rule.{user,room,server}` with `entity` (glob), `recommendation` (`m.ban`), `reason`. A policy room is a *published list*; it enforces nothing — a separate bot (Mjolnir/Draupnir, a normal privileged room member) subscribes and applies the rules. This is the "subscribe to someone's curated ban list" mechanism. — `https://raw.githubusercontent.com/matrix-org/matrix-spec-proposals/main/proposals/2313-moderation-policy-rooms.md`

**MSC4284 Policy Servers.** `[CORR]` Now spec-merged — the **v1.18 server-server-api PDU-receipt check #7**: an event "is validated by the Policy Server, if the room is using a Policy Server, otherwise it is soft failed." Opt-in per room, soft-fail outcome. This is a **viewpoint-capable prior-restraint chokepoint** — directly at odds with Art. I in public rooms. — `https://spec.matrix.org/latest/server-server-api/`, `https://github.com/matrix-org/matrix-spec-proposals/pull/4194`

**Creator infinite power level (room v12, MSC4289, Matrix v1.16, 2025-09-17).** Room creators (the `m.room.create` sender + optional `additional_creators[]`) have an **effectively infinite, immutable** power level: they sort above any finite integer, **cannot be demoted/kicked/banned**, and **cannot be listed in `power_levels.users`** (an auth rule rejects it). v12 room IDs are the create-event hash, closing the backdating/state-resolution escalation hole (Project Hydra MSC4297). — `https://matrix.org/docs/spec-guides/creator-power-level/`, `https://raw.githubusercontent.com/matrix-org/matrix-spec-proposals/main/proposals/4289-privilege-creators.md`, `https://matrix.org/blog/2025/09/17/matrix-v1.16-release/`

**Per-user ignore (`m.ignored_user_list`).** Client-side account-data; hides all events from chosen users, requires no power level, affects only that user's own view. — `https://spec.matrix.org/latest/client-server-api/`

**Distributed authority.** There is **no global moderator**. Each homeserver is sovereign over its own users + its own copy of a room's events, independently authorizes incoming requests against current auth state, and **decides whether to honor redactions**. Room moderation (power levels, bans) is per-room; server ACLs are per-room; ban-list subscription is voluntary per community.

**The four carve-outs mapped onto these primitives** (full constitutional treatment in §5–§6):

| Carve-out | Matrix primitive | Notes |
|---|---|---|
| **M-1 judicial order (logged)** | appservice-emitted **soft-fail / redaction**, case-id-bound, mirrored to `audit_log` | best-effort across mesh; the log is the durable artifact |
| **M-2 rights-protection (triad-leak / doxxing)** | appservice-emitted **hard redaction** (content-stripping), always logged | the one case where stripping content is the point |
| **M-3 per-user block** | client-side **`m.ignored_user_list`** | **not an appservice action at all** — private feed curation, off the censorship surface |
| **M-4 content-neutral anti-spam** | **rate limiting** (`M_LIMIT_EXCEEDED`/429) + **soft-fail** on behavior/volume | never viewpoint; the constitutionally-permitted form |

---

## 5. The legitimacy-gated moderation-control flip — **the centerpiece**

> **Thesis (one sentence):** Control over moderating a *public* room is the **same kind of authority** as control over a physical jurisdiction — it begins as the **temporary, de-facto standing of whoever runs the box** (R-08-anchored election-board steward) and **flips, by the same legitimacy gate that flips physical authority** (G-VER Meter A→B; G6 `LocalAutonomyService`), to the **seated constitutional bodies** the moment a jurisdiction seats a government — and at no point in either phase may *anyone* viewpoint-censor a public room, because the only removals that exist are the four office-gated carve-outs.

This reuses precedents **verbatim**:
- **G-VER `PeerUpgradeAgreementService`** — Meter A (operator-board attestation, R-08, bootstrap-only) is *superseded* by Meter B (seated-legislature supermajority) the moment `Legislature::STATUS_ACTIVE` exists for the subtree.
- **G6 `LocalAutonomyService`** (`app/Services/Jurisdictions/LocalAutonomyService.php:47-143`) — de-facto/parent authority over a subtree flips to that subtree's *own* seated government by dual supermajority; never an admin's call.
- **G-OP operator plane** — the physical operator's identity lives in `operator_accounts`, authenticated by key possession, and **never touches `RoleService`** (the two-plane firewall). Operator standing is infra-status, not a constitutional role.

The novelty: **`m.room.power_levels` is a literal on-the-wire encoding of "who governs this room."** So the flip is not metaphorical — we pin the *Matrix* power exactly where the *constitution* puts it and move only the off-Matrix authority the appservice honors.

### 5.1 The structural constant that survives the flip — appservice = immutable sole room creator

**Every PUBLIC room (`#square`, `#halls`, every auto-bound governance room) is created in room version 12 with the CGA appservice as the sole `m.room.create` sender and the sole entry in `content.additional_creators` — no human, ever.**

Per MSC4289 (v1.16; v12 is now the spec-recommended default): creator power is infinite + immutable, cannot be demoted/kicked/banned, cannot be encoded in `power_levels`, and v12 room IDs = the create-event hash (so an operator with shell access cannot craft an event onto a stale DAG branch to grab power). This is strictly stronger than "appservice holds the only PL≥50" (a 100-vs-100 race a Synapse admin could win out-of-band). **No human — physical operator or seated judge — ever holds a Matrix power level in a public room.**

- **Do not hardcode the default room version** — query the `m.room_versions` capability live and *design to v12*; **refuse to create a public commons room on a homeserver that cannot offer v12** (the moderation analogue of G-VER's `room_version`/`constitutional_version` join-gate).
- **Explicitly set every power-level field at room creation** (`invite`, `kick`, `ban`, `redact`, `state_default`, `events_default`, `users_default`, the per-event-type `events` map) to values no human can reach — never rely on the disputed `invite` default.

What moves across the flip is **not** the Matrix power level (pinned to the appservice forever) but **which off-Matrix attestation source the appservice honors** before emitting a carve-out redaction.

### 5.2 Phase 1 — BOOTSTRAP: the physical/de-facto operator as election-board steward (R-08)

Before a jurisdiction seats a government, the physical operator who runs the box is steward of the carve-out machinery — and *only* that. This is the exact R-08 bootstrap anchor ("for the very first election, the system acts as the election board," `roles_forms_chart.md:208`). Via the G-OP operator plane (`operator_accounts` + a key-possession-signed console/CLI action — exactly `AuthorityFlipService`'s `$operatorUserId` gate at `app/Services/Federation/AuthorityFlipService.php:41`), the operator may:

- **provision** the jurisdiction Space + canonical public rooms (v12, appservice-as-creator);
- **configure M-4 anti-spam knobs** (rate limits, new-account burst thresholds) — content-neutral `constitutional_settings`, the *only* discretionary lever;
- **stand in for the judicial carve-out path** in the narrow, transparent, election-board sense — relay an **M-2 rights-protection** removal (triad-leak/doxxing redaction) *as a neutral administrator*, audit-logged, because no R-19/R-20 judge exists yet to issue an M-1 order.

That last power is bounded **exactly as G-VER bounds the bootstrap operator-board:**
- **Neutrality-bound (Art. II §2)** — transparent (audit-chained), observable (published), procedurally bound; an M-2 removal is a `public_records` row citing the *rights-breach reason*, never "violates our values."
- **Non-disruption (Art. II §7)** — cannot use bootstrap standing to disrupt a live process (e.g. a live deliberation during an election) — the moderation analogue of the G-VER "game-in-progress freeze."
- **Carve-out-only, never viewpoint** — the appservice has no "moderator" account; it knows only carve-out kinds and (post-flip) R-codes, and **refuses** any removal that isn't M-2/M-4-shaped.

**The bootstrap policy room — what it may and may NOT contain:**

| Allowed in the bootstrap policy room | Forbidden in any public policy room (Art. I) |
|---|---|
| `m.policy.rule.server` for a **demonstrably spamming/abusive server**, keyed on behavior/volume (M-4) | any `m.policy.rule.user`/`.room` whose `reason` is a *viewpoint* ("hate", "misinformation", "violates our values") |
| `m.policy.rule.user` only when it **cites a logged M-1 order** (post-flip) or an M-2 rights-breach reference | a subscription to *any external/discretionary* `m.ban` policy room |
| a content-neutral malware/spam-server list (M-4) | `m.room.server_acl` silencing a *legitimate mesh peer's whole jurisdiction* on viewpoint grounds |

**Hard `m.room.server_acl` rail:** in public rooms the appservice writes a server ACL only for M-4 (behavior-based abusive server) or M-1 (logged order), and **must always keep every legitimate mesh peer + the local server in `allow`** — the `allow:[]` brick/self-ban footgun (Synapse #5468; matrix-spec #397) is a concrete deploy hazard. Private (org/user) rooms are exempt — R-23 agents self-moderate freely (Art. I private half).

### 5.3 The FLIP — supersession to constitutional operators

```
bootstrap (no seated govt)                        seated govt exists
─────────────────────────────                     ──────────────────────────────
Meter A: operator-board steward      ── flip ──>   Meter B: seated bodies supersede
(R-08, key-possession, neutral)      gated by      (R-19/R-20 judicial M-1; seated
appservice honors operator's          is_seated    legislature for room lifecycle;
M-2/M-4 relays                       (CLK-06 /     R-23 for org rooms)
                                     activation     appservice honors ONLY
                                     tier + a       AttestationService role snapshots
                                     seated         from these offices
                                     Legislature)
```

- **`is_seated` is the pivot** (does `Legislature::STATUS_ACTIVE` exist for the subtree?): false ⇒ operator-board bootstrap standing; true ⇒ the seated bodies' authority becomes REQUIRED and *supersedes* the operator's.
- **`is_activated` (CLK-06 / `ActivationTierService`, Phase I)** gates whether a jurisdiction may even seat a government — below its population-pegged tier it is *chartered-but-empty* and runs its square in mirror/observer mode (no authoritative halls).
- **The flip is granted by the current authoritative authority, never seized** — exactly G6 `LocalAutonomyService::open/finalize`. The operator does **not** get to refuse it: once `STATUS_ACTIVE` is true for the subtree, the appservice **stops honoring the operator's M-2/M-4 relay authority** for that subtree's public rooms and starts honoring the seated offices.

**What the flip concretely changes — a single change to the appservice's authorization predicate:**

| | Bootstrap (Meter A) | After flip (Meter B) |
|---|---|---|
| **Matrix power in public rooms** | appservice = sole v12 creator | **unchanged** — appservice = sole v12 creator |
| **M-1 judicial removal** | no R-19/R-20 exists; operator may relay an M-2 hold, neutral + logged | appservice honors an `m.room.redaction` **only** with a live `StandingAttestation` whose role snapshot includes **R-19/R-20** and references a **case id + order** (Art. IV pipeline) |
| **M-2 rights-protection** | operator relays (neutral, logged); triad refusal is structural | structural triad refusal unchanged; a judicial doxxing hold becomes an R-19/R-20 M-1 sub-case |
| **M-4 anti-spam** | operator sets the knobs | knobs become amendable `constitutional_settings` under the seated legislature; R-09 stays exempt in `#halls` (Art. II §3) |
| **Room lifecycle** | operator-board provisions | the auto-bind reconciler is driven by seated institutions' governance objects (bills/committees/petitions); operator no longer provisions discretionarily |
| **Policy room curation** | operator curates the single M-4/M-1-only policy room | the seated bodies own it — judicial for M-1, legislature for the M-4 spam-server list (still M-2/M-4-shaped only) |

The mechanism that makes "honors R-19/R-20" real is the **existing `AttestationService`** (`app/Services/Identity/AttestationService.php`): a judge's home authority issues a short-lived (≤24h, `MAX_TTL_SECONDS = 86400`), revocable, instance-signed **snapshot of derived role codes** bound to the device key. The appservice calls `verifyAttestation` (against the issuer's pinned `federation_peers.public_key`) before emitting any redaction — so **moderation authority is a pure function of which constitutional office a person currently holds, derived live, never a stored bit.** When the term ends, the attestation expires and the carve-out authority evaporates automatically.

### 5.4 The flip is logged and detectable across the mesh

Each flip event (operator-board steward → seated bodies for a subtree's public rooms) is an **audit-chained `public_records` row** (`PublicRecordService.php:81`, hash-chained) and **observable by all factions** (Art. II §2). Because the signed `public_records` tail rides `FederationSyncService::ingestTail`, a peer mirror detects a **flip-that-didn't-happen** (operator still relaying after a government seated) or a **censorship-without-an-order** (a redaction with no matching case id) as a **hash-chain discontinuity** — both detectable across the mesh, the same backstop G-VER relies on.

### 5.5 Physical vs constitutional operators — the explicit contrast

| | **PHYSICAL / de-facto operator** | **CONSTITUTIONAL operator** |
|---|---|---|
| **Who** | whoever runs the box (G-OP `operator_accounts`, key-possession auth) | seated officeholder: R-19/R-20 judge (M-1), R-09 legislator / R-10 speaker (room-class/lifecycle, M-4 knobs), R-18 BoG, R-23 org agent (private rooms only) |
| **Legitimacy source** | **temporary, de-facto**, anchored to R-08; **superseded** by a seated government | **consent of the governed** (Art. II §1); authority traces through the legislature that exists only by Art. II §1 consent |
| **Identity plane** | operator plane — **never touches `RoleService`**, holds **zero** R-codes | citizen plane — roles **derived live**, never stored; presented to the appservice as a `StandingAttestation` |
| **Matrix power level** | **none** — cannot acquire it in a public room (v12 immutable creator) | **none** — never gets one either; authority is the ≤24h attestation the appservice checks |
| **May touch in PUBLIC rooms** | provision rooms; set content-neutral M-4 knobs; **bootstrap-only** neutral relay of M-2 holds (logged) | issue M-1 judicial redactions (case-id-bound); own the M-4 knobs + the M-1/M-4-only policy room post-flip |
| **May NOT touch in PUBLIC rooms** | viewpoint-delete · shadow-ban · discretionary kick · subscribe to a "values" ban list · server-ACL a peer on viewpoint · read DMs/private rooms · **anything once the subtree is seated** | viewpoint-delete · shadow-ban · removal without a logged carve-out · anything in another office's lane (a judge can't rewrite M-4 knobs; a legislator can't issue an M-1) |
| **Private (org/user) rooms** | no special power (not their room) | R-23 agents self-moderate fully (Art. I private half) |

**The crisp invariant:** *neither* operator kind can viewpoint-censor a public room. The physical operator runs the homeserver but, because creatorship is fixed to the appservice at room creation and is unreachable, **cannot acquire removal power**; the constitutional officeholder never gets a Matrix power level either — they get a transient, revocable attestation the appservice translates into exactly one carve-out action. The carve-outs stay office-gated in *both* phases; only *who fills the office* and *what legitimacy backs them* changes across the flip. This is the precise translation of Matrix's malicious-homeserver threat boundary (§3) into the CGA two-operator model.

### 5.6 How legitimacy GATES the flip without making reach a moderation input (CI-1)

1. **The pivot is `is_seated`** (binary structural fact) **+ `is_activated`** (CLK-06 / `ActivationTierService` population tier) — the *same* gates that flip physical authority (G6) and version authority (G-VER). They decide *which office holds the carve-out authority*, never *what content is allowed*.
2. **Reach (`LegitimacyService::reachRatio`, Phase I, not yet built) is DISPLAY-ONLY (hard rail CI-1).** The square may render a legitimacy gauge ("this jurisdiction's government represents N verified residents"), but reach **never enters the appservice's authorization predicate, never weights a removal, never decides whether a post stays up, never decides who may invoke a carve-out.** Monotone safety carries over from G-VER §5.2: more legitimacy can only *raise* the bar, never lower a floor; a low-reach jurisdiction's square degrades **safely** to bootstrap-steward mode (operator relays M-2/M-4 only, neutral, logged).
3. **The carve-out set is reach-independent and ungateable.** No tier, reach value, seated supermajority, or operator can add a fifth carve-out or make viewpoint-removal legitimate — the moderation analogue of G-VER §5.3's reach-independent hardened floor.

### 5.7 Honest limitations (Matrix realities the flip must not over-promise)

- **`[CORR]` Redaction is best-effort, not guaranteed erasure.** A redaction keeps the event ID; other servers retain the original and may decline to honor it. M-1/M-2 removals are best-effort UI removal — **the durable constitutional artifact is the logged `public_records` order**, not the disappearance of bytes. UI must say "removal requested under order X," never "erased." (This *aligns* with append-only Plane A.)
- **MSC4284 Policy Servers are viewpoint-capable prior-restraint** (spec v1.18, opt-in, soft-fail). **Public commons rooms must NOT designate a discretionary policy server.** A CGA policy server, if used, is constrained to M-4 + M-1 (logged), viewpoint filtering forbidden. Private rooms may use one freely.
- **Soft-fail is the right primitive for M-4 + reversible M-1** (suppresses relay/display, preserves the signed event); reserve **hard redaction** (content-stripping) for **M-2** and log it.
- **M-3 per-user block is not an appservice action** — native client-side `m.ignored_user_list`, private, never federated, never audited, not gated by the flip.

---

## 6. Matrix as the social engine

### 6.1 Topology — Spaces ↔ (jurisdictions + organizations + institutions)

Matrix **Spaces** are rooms of type `m.space` whose membership uses `m.space.child`/`m.space.parent` state events; they nest exactly like `jurisdictions.parent_id`, so the topology is generated *from* the existing trees.

```
#space:earth                          (m.space, ADM0 root — jurisdictions.id = f07ff892-…)
├─ m.space.child → #space:<jurisdiction>     one Space per civically-active jurisdiction
│   ├─ #square:<jurisdiction>          PUBLIC square — world_readable, residency-gated posting
│   ├─ #halls:<jurisdiction>           PUBLIC halls of governance — testimony-bridged to Plane A
│   ├─ #bill-<id> / #ref-<id> / #petition-<id> / #committee-<id>   auto-bound per live object
│   └─ m.space.child → child jurisdiction Spaces (recurses)
├─ m.space.child → #org-space:<organization_id>   one Space per organizations row
│   ├─ PRIVATE rooms (org self-moderates — Art. I private half; E2EE on)
│   └─ #org-public:<id>               OPTIONAL public-facing org room (still appservice-clamped)
└─ #institution rooms                  per-institution (legislature/executive/judiciary/BoG)
```

- **Jurisdiction tree → Space tree** is the spine; `SocialTopologyReconciler` rebuilds each Space's `m.space.child` set from `jurisdictions` (idempotency key = `jurisdiction.id`). A jurisdiction below its activation tier still gets a `#square` but **no `#halls`** (no seated government ⇒ no authoritative testimony to bridge).
- **Organizations → org Spaces.** An `organizations` row's R-23 agent owns its Space and holds **full power-level moderation inside it** (Art. I private half). The appservice does *not* clamp power in org/private rooms — only public ones.
- **Institutions → institution rooms** are public + appservice-clamped like `#halls`; officeholder speech carries the `cga.acting_seat` annotation (§6.3).
- **Per-object auto-bound rooms** — one room per active bill/referendum/petition/committee, created on object-activation and **tombstoned** (not deleted, §2.2) on closure, so the discussion is archived alongside the governance artifact.

**Scale rail:** Matrix replicates every PDU to every homeserver with a member + stores the full DAG everywhere — a single planet-wide room is pathological. **The design forbids one giant room.** Topology is always Spaces + per-jurisdiction rooms; the durable civic record is Plane A, not live Matrix replication.

### 6.2 The CGA appservice as bridge + IdP

The appservice is the only component talking to both Postgres and the homeserver, playing **Application Service** (owns a Matrix user/room namespace, holds creatorship in public rooms) + **Identity Provider** (mints Matrix sessions from CGA login) simultaneously.

`[CORR]` **Auth: delegate to a CGA-run OIDC provider (MAS / MSC3861), not bespoke SSO.** Spec-current mechanism is delegated OAuth 2.0/OIDC via Matrix Authentication Service (stable since spec v1.15; matrix.org migrated 2025-04-07; discovery `GET /_matrix/client/v1/auth_metadata`). Strictly better for the CGA: the homeserver **never sees a password** (CGA login *is* the only credential); tokens are **short-lived + refresh-rotating** (same TTL discipline as `AttestationService`); it mirrors the **two-plane firewall** (MAS separates identity/auth from the homeserver exactly as G-OP keeps operator identity off `RoleService`). — `https://matrix.org/blog/2025/04/matrix-auth-service/`, `https://github.com/matrix-org/matrix-spec-proposals/pull/3861`

**Identity bridge table:**

| CGA identity | Code symbol | Matrix concept | Bridge |
|---|---|---|---|
| Instance / server | `InstanceIdentityService::serverId()` + Ed25519 | Homeserver name + S2S signing key at `GET /_matrix/key/v2/server` | one instance = one homeserver; key sharing is an open decision (§7) |
| Person | `users.id` (UUID) + `users.display_name` | Matrix `@<localpart>:<domain>` | MAS provisions on first CGA login; localpart from `social_profiles.handle`; `name`/email never exposed |
| Device | `ActorIdentityService` enrolled device Ed25519 (never escrowed) | Matrix device key + E2EE device key; **cross-signing** master/self/user keys | one enrolled device, one signing identity |
| Standing (roles) | `AttestationService` signed role-code snapshot (≤24h, revocable) | room membership + transient carve-out decisions | appservice calls `verifyAttestation()` to gate posting + carve-out eligibility |

**Pseudonymity (hard rail):** Matrix only ever sees `@handle:domain`, a displayname, a device public key, and a short-lived role-code snapshot. It never sees `users.name`, email, password, residency rows, ballots, locations, or block lists. De-anonymization is judicial-only (M-1).

`[CORR]` **Cross-signing is the missing person-trust layer.** Adopt it: a **master key per actor** signs enrolled device keys (self-signing analogue), and the home-authority `AttestationService` acts as the **user-signing authority** vouching for the binding `device-key ↔ derived-role-snapshot`. Device keys stay un-escrowed, verification stays out-of-band — both already CGA invariants. Absent from the prior K-3 doc. — `https://spec.matrix.org/latest/client-server-api/#cross-signing`

### 6.3 Posting rights & officeholder speech — derived, never stored

- **`#square:<j>` posting** is granted iff the live role set includes residency (R-03+) for jurisdiction `j`. **Residency is the only gate** (Art. I) — no karma/account-age/Matrix-reputation gate.
- **Officeholder speech** in `#halls`/institution rooms carries `cga.acting_seat` (e.g. `legislature_member`, `committee_seat`, `judicial`), validated against the live attestation **at send time** and stripped if the role isn't currently held. A seated member's posts in `#halls` are **exempt from M-4 throttling** (Art. II §3 unobstructable priority channel).
- **No `m.room.power_levels` entry ever encodes a CGA office** — office→speech is enforced by appservice membership/annotation control, keeping the office out of the mutable Matrix ladder.

### 6.4 Mesh-gated S2S federation — `federation_peers` is the allowlist

`[CORR]` **Matrix has no in-protocol federation allowlist** — restricting S2S is a homeserver-config concern (Synapse `federation_domain_whitelist`) + per-room `m.room.server_acl`. Enforce at two layers:

1. **Homeserver-config (coarse):** `MatrixFederationSyncJob` writes the homeserver's `federation_domain_whitelist` from pinned `federation_peers` — *the same peers that mirror your public records federate your Matrix rooms.* A `scale_demo` instance forces the whitelist empty (federation OFF; CI-2 — a demo has no consent).
2. **Per-room (fine):** `m.room.server_acl` on public rooms, written **only by the appservice**, **only for M-1 (judicial) or M-4 (behavior-based abusive server)**. **Hard rail:** `allow` must always retain every legitimate mesh peer + the local server (`allow:[]` self-bricks; an ACLed-out server can't even leave). Server-ACLing a whole peer silences all of that jurisdiction's residents at once (collides with Art. I) → restricted to the two behavior-based carve-outs, never viewpoint.

Mirror-vs-co-member → room rights (driven by `ClusterMembership` + `AttestationService`): a **mirror** peer (`ROLE_MIRROR`) may join/read `world_readable` public-square rooms but **cannot post as your constituents** (residency is local); a **co-member** peer (`ROLE_HOST`) has its attested residents **post as constituents** in shared rooms — the home instance issues a `StandingAttestation` (HOME-authority-only) bound to the device key, your appservice verifies it against the peer's pinned `federation_peers.public_key`, reads the R-codes, and grants room rights for exactly the attestation TTL. This is the person-level analogue of `WriteRouterService`'s forwarded-write attestation, reused. Matrix's "verify the origin's per-event signature regardless of who relayed it" gives the social mesh the same no-trust-in-transport property as `FederationSyncService`'s signed tail; the CGA's pinned-peer model is **stronger** than Matrix's notary trust (no notary layer needed).

`[CORR]` **Pin commons rooms to room v12 (immutable sole creator), not "PL 100."** §5.1. `[CORR]` **MSC4284 Policy Servers + MSC2313 ban-lists constrained to carve-outs only** on public rooms (§4, §5.7); viewpoint filtering forbidden; private rooms may use them freely; M-3 per-user block is client-side `m.ignored_user_list`, off the censorship surface.

### 6.5 The two-plane split + the testimony bridge

| Plane | Substrate | Mutability | Federation path | Contents |
|---|---|---|---|---|
| **A — Civic Record** | `social_*` + `public_records` + hash-chained `audit_log` | append-only; sealed | `FederationSyncService` signed tail + Phase G mirror commons | testimony, published positions, candidate platforms — the Art. II §2 durable record |
| **B — Live Commons** | Matrix homeserver (`fc_matrix`) | realtime; redactable; E2EE-private rooms | Matrix S2S, mesh-gated | the public square, org/community rooms, DMs, the Discord-replacement experience |

**The testimony bridge (one-way valve, Plane B → Plane A):** a discussion *happens* in a Matrix room; when a participant **files testimony** (`F-SOC-002`), the appservice **snapshots that single event** — body + `actor_display` frozen at file time — into `public_records` via `PublicRecordService::publish()`, sealing it into the audit chain, and writes the Matrix event a `cga.published_record_id` back-pointer. The Matrix message stays live and editable; the *civic act* is the immutable snapshot. UI must surface that "filing" — not "posting" — is the constitutional act.

`[CORR]` **Soft-fail formalizes the split.** Matrix soft-failure keeps an event in the DAG + state resolution but withholds it from clients — exactly "the record persists, but display is governed." M-4 anti-spam + M-1 judicial removal are **soft-fail** (suppress relay/display, preserve the signed event); **hard redaction** (content-stripping) is reserved for **M-2** rights-protection and always logged. `[CORR]` **Redaction is best-effort across the mesh** (peers may decline) — the durable artifact is the `audit_log` entry; UI says "removal requested + logged," never "erased."

**Privacy plane (E2EE):** PUBLIC rooms are **unencrypted** (transparency + the appservice must read content to apply M-2/M-4); PRIVATE org/user rooms + DMs use **Megolm/Olm E2EE** with client-side-encrypted backup, so neither the operating instance nor mesh peers can read them (Art. I §16). This creates a real tension with M-1 orders against private rooms — flagged §8.

### 6.6 `constitutional_version` ↔ Matrix room version — **loosely couple, do not hard-pin** (§2.6)

A constitutional upgrade does **not** pin a room version (orthogonal surfaces; upstream Matrix must never trigger a CGA constitutional event). It *may* tighten which peers may federate Matrix with you — reuse the G-VER **Meter C** version-compatibility refusal as the Matrix S2S admission gate. Room-version upgrades remain a Matrix-ops concern, executed via tombstone/predecessor when the CGA chooses (e.g. to adopt v12 immutable-creator on legacy rooms).

---

## 7. Open decisions for the operator

1. **One Ed25519 key for CGA-mesh + Matrix S2S, or two?** Sharing `InstanceIdentityService`'s key = one identity; separating bounds blast radius. **Lean: separate keys, shared rotation policy** — Matrix S2S is a larger CVE surface.
2. **Synapse vs Dendrite** (§9, the arm64/Pi target). Synapse = reference, most feature-complete (appservice/power-levels/server-ACLs/policy-servers/MAS first-class) but Python + RAM-hungry; **Dendrite** = Go single-binary, far lighter (natural Pi default) but historically lags on appservice edges, policy servers, and full v12. **Recommendation:** `MATRIX_IMPL=dendrite` default (Pi) + `MATRIX_IMPL=synapse` override (same env-override pattern as `POSTGIS_IMAGE`), **gated on a verification spike** confirming v12-creation + appservice-sole-creator + `federation_domain_whitelist` + server-ACLs. If Dendrite fails the spike, Synapse becomes the Pi default and voice/video is the first thing cut. **Rig-gated** (amd64 cannot surface the Pi RAM ceiling).
3. **Bootstrap M-2 relay power** — allow the neutral, logged M-2 relay (a triad-leak emergency can't wait for a not-yet-seated judiciary), bounded by Art. II §2 + the audit chain? It's the single discretionary power a bootstrap operator holds. Confirm.
4. **N-of-M operator quorum for bootstrap carve-out relays?** The G-OP `mesh_operator_keys` substrate supports a multi-operator co-sign (better honors "independent board"). Policy choice.
5. **When `is_activated` is false (chartered-but-empty),** does the square exist `world_readable` (no authoritative halls) or not at all? **Recommendation: exists, read-only.**
6. **Soft-fail vs hard-redaction boundary per carve-out** — proposed soft-fail for M-4 + reversible M-1, hard redaction for M-2. Confirm the chosen homeserver honors soft-fail + policy-server semantics as designed.
7. **Does the moderation flip ride the same `peer_upgrade_proposals`-style object as G-VER, or its own `public_records` row?** **Recommendation: its own lightweight `public_records` flip-event** (per-subtree, not a version bump), cross-referenced to the seating event.
8. **DB:** reuse `fc_postgres` with a second logical `matrix` DB (one PG image, keeps the arm64 `imresamu/postgis` rebuild as the single DB image); nginx gains `location /_matrix/` → `fc_matrix:8008` + `.well-known/matrix/{server,client}` delegation (design key-pinning, not DNS-trust — the delegation target must serve a cert valid for the original hostname).
9. **Embedded client vs link-out to Element** — recommend embed `#square`/`#halls` in Inertia/Vue, link-out to hosted Element for power users.
10. **Federation default mesh-only vs open-to-fediverse** — recommend **mesh-only** (whitelist from `federation_peers`), operator-opt-in to wider.
11. **Keep bespoke `social_*` (Plane A) vs thin it to a Matrix index** — recommend **keep Plane A independent** (must be audit-chained regardless of Matrix uptime). Real fork.
12. **Voice/video (LiveKit / Element Call)** — defer to **K-3b**; likely cannot run on the Pi.
13. **Should the CGA run cross-signing as the user-signing CA (§6.2)?** Recommended, but a new responsibility for `AttestationService`; confirm scope.

---

## 8. Risks

1. **Moderation-model inversion (headline).** Matrix's entire native UX is community-standards moderation, which Art. I forbids in public rooms. The appservice must *defeat* the default model. v12 immutable-creator is the strongest mitigation but **depends on the chosen homeserver supporting v12** (Dendrite risk). A homeserver admin with shell access on a pre-v12 room could raise power out-of-band; a reconciler must re-clamp, and cross-mesh auth-DAG replay (§2.4, §5.4) is the backstop.
2. **Removal is best-effort, not erasure** `[CORR]`. UI must present M-1/M-2 as "logged removal request," never guaranteed erasure; the durable artifact is the `audit_log` entry. A doxxing victim cannot be promised the content is gone everywhere — a hard product-truth.
3. **Private-room privacy vs judicial process.** Default E2EE on private rooms/DMs (Art. I §16) means an M-1 order against a private room hits content the operator cannot read. The judicial path can compel a *participant* to surrender keys or act on metadata, but cannot silently decrypt — an explicit documented limitation, not a bug.
4. **Planet-scale replication.** Spaces-not-one-giant-room (§6.1) is mandatory; Plane A is the durable record. Large public jurisdictions need a sharding/room-partition strategy before O-scale.
5. **Two-plane drift.** Mitigated by the snapshot-at-file-time testimony bridge + soft-fail display governance; "filing" must read as the constitutional act.
6. **MSC4284 Policy Server creep.** A future operator could enable a policy server for convenience and silently reintroduce prior-restraint. The constitutional-test-suite must assert **no discretionary policy server on public rooms** as a CI invariant (alongside CI-1/CI-2).
7. **Single-point IdP/appservice compromise (most sensitive seam).** Breaching the appservice/MAS lets an attacker mint Matrix identities or emit carve-out redactions. Mitigated by un-escrowed device keys, short-lived revocable home-authority-only attestations, and v12 creatorship pinned at room creation (an attacker still can't *add* a fifth carve-out kind without shipping code — itself a `constitutional_version` bump under G-VER).
8. **The flip stalls (operator refuses to relinquish).** Structurally mitigated: the appservice predicate keys on `Legislature::STATUS_ACTIVE` (a derived fact the operator can't forge without forging the audit-chained seating itself), so the flip is *automatic* on seating, and a non-flip is detectable as the §5.4 discontinuity.
9. **Authority-flip ↔ Matrix room control.** A jurisdiction `AuthorityFlipService` flip does **not** move Matrix room creatorship (it stays pinned to the appservice; only the honored attestation source flips). Confirm this is intended — it parallels (and reuses) the G6/G-VER off-Matrix flip pattern.
10. **arm64/Pi resource ceiling.** Adding a homeserver (+ optional LiveKit) may exceed the Pi. Dendrite mitigates; voice/video likely can't run on the Pi. Needs a resource-budget pass + **rig certification** (amd64/Docker-Desktop cannot surface the Pi RAM ceiling — the G-V2 verification-gap lesson).

---

## 9. Roadmap slot (K-3) + dev-stack-buildable vs rig-gated

This is **K-3, "The Mesh Commons"** — both the Matrix social engine and its moderation half are the *same* sub-phase, not a new letter. Dependencies (all built unless noted):
- **G-OP** operator plane (`operator_accounts`, two-plane firewall) — built;
- **G-ID `AttestationService`** (the role-snapshot the appservice verifies for carve-outs) — built;
- **G-VER `PeerUpgradeAgreementService`** (Meter A→B supersession reused verbatim; Meter C reused as the Matrix S2S admission gate) — built;
- **G6 `LocalAutonomyService`** (the dual-meter flip the moderation flip mirrors) — built;
- **Phase I `ActivationTierService` / `LegitimacyService`** (the `is_activated` gate + the display-only reach gauge) — **not yet built**; K-3 ships the moderation flip correct on **seatedness alone** and wires the activation-tier gate + reach gauge **additively** when Phase I lands (degrades safely — the same two-layer ship as G-VER).

**Dev-stack-buildable now (single instance):** the Matrix homeserver container + appservice + MAS; Spaces↔jurisdiction/org/institution topology reconciler; appservice-sole-v12-creator power-clamp; residency-gated `#square` posting + `cga.acting_seat` annotation; the testimony bridge (Plane B → Plane A snapshot); bootstrap operator-board carve-out machinery; the **flip predicate keyed on local `Legislature::STATUS_ACTIVE`**; M-3 client-side ignore; M-4 rate-limit knobs.

**Rig-gated (like every Phase G federation increment — amd64 cannot surface it):** Matrix S2S federation between two instances; a **peer judge's attestation driving an M-1 redaction in a co-member room**; the `m.room.server_acl` mesh interactions + the `allow:[]` brick-guard runbook; the Synapse-vs-Dendrite verification spike (§7.2) confirming v12-creation + appservice-sole-creator + `federation_domain_whitelist` on the Pi RAM budget; divergent-`constitutional_version` Matrix-admission refusal (Meter C reuse).

---

### Files this design grounds on (absolute paths, all verified present)
- `E:\fair-constitution-app\.claude\worktrees\practical-payne-17d537\docs\plans\phase-g-continuation\K3-social-layer-matrix.md` (the doc this extends/corrects)
- `E:\fair-constitution-app\.claude\worktrees\practical-payne-17d537\app\Services\ConstitutionalVersionService.php` (derived `cv1.<hash>`, `HARDENED_SURFACE` manifest)
- `E:\fair-constitution-app\.claude\worktrees\practical-payne-17d537\app\Services\UpgradeFreezeService.php` (Art. II §7 subtree freeze — the tombstone analogue)
- `E:\fair-constitution-app\.claude\worktrees\practical-payne-17d537\app\Services\PeerUpgradeAgreementService.php` (Meters A/B/C — the MSC-process analogue)
- `E:\fair-constitution-app\.claude\worktrees\practical-payne-17d537\app\Services\Identity\AttestationService.php` (`MAX_TTL_SECONDS=86400`, `issue/verifyAttestation/revoke` — the carve-out gate)
- `E:\fair-constitution-app\.claude\worktrees\practical-payne-17d537\app\Services\PublicRecordService.php` (`FORBIDDEN_SUBJECT_TYPES` — triad never reaches Plane A; `:81` hash-chain seal)
- `E:\fair-constitution-app\.claude\worktrees\practical-payne-17d537\app\Services\Federation\AuthorityFlipService.php` (`:41` operator-gated, audit-chained, Ed25519-signed subtree action — the bootstrap-operator pattern)
- `E:\fair-constitution-app\.claude\worktrees\practical-payne-17d537\app\Services\Jurisdictions\LocalAutonomyService.php` (`:47-143` the dual-meter `is_seated` flip mirrored here)

### Primary sources (Matrix spec + MSCs)
- Server-Server API (federation, transactions, PDU/EDU, auth, soft-fail, PDU-receipt checks incl. v1.18 Policy-Server step): `https://spec.matrix.org/latest/server-server-api/`
- Room versions index (v12 spec-recommended default): `https://spec.matrix.org/latest/rooms/`
- Room v11 (auth rules, redaction algorithm, state-resolution v2): `https://spec.matrix.org/latest/rooms/v11/`, `https://spec.matrix.org/v1.11/rooms/v11/`
- Room v12 (immutable creators, room-ID = create-event hash): `https://spec.matrix.org/v1.16/rooms/v12/`, `https://matrix.org/docs/spec-guides/creator-power-level/`, `https://raw.githubusercontent.com/matrix-org/matrix-spec-proposals/main/proposals/4289-privilege-creators.md`
- Client-Server API (E2EE, cross-signing, client-auth/OAuth2, room upgrades, capabilities): `https://spec.matrix.org/latest/client-server-api/`
- Appendices (Canonical JSON, content/reference hashing, signing): `https://spec.matrix.org/latest/appendices/`
- MSC process: `https://spec.matrix.org/proposals/`, `https://github.com/matrix-org/matrix-spec-proposals`
- MAS / delegated OAuth2 (MSC3861): `https://matrix.org/blog/2025/04/matrix-auth-service/`, `https://github.com/matrix-org/matrix-spec-proposals/pull/3861`
- MSC4284 Policy Servers: `https://github.com/matrix-org/matrix-spec-proposals/pull/4194`
- MSC2313 moderation-policy rooms: `https://raw.githubusercontent.com/matrix-org/matrix-spec-proposals/main/proposals/2313-moderation-policy-rooms.md`
- Server-ACL footguns: `https://github.com/matrix-org/synapse/issues/5468`, `https://github.com/matrix-org/matrix-spec/issues/397`
- Power-level `invite`-default divergence: `https://github.com/matrix-org/matrix-spec/issues/1019`, `https://github.com/matrix-org/matrix-spec/issues/860`
- Matrix v1.16 release (v12 default; creator power level): `https://matrix.org/blog/2025/09/17/matrix-v1.16-release/`

### Verification caveats carried from the research (re-pin before implementation)
- Exact EDU type-string registry, the field-by-field content/reference-hash strip-set, and the full state-res-v2 pseudo-code were not extracted verbatim this round — re-read the target room version's sections before coding them.
- The `m.room.tombstone` / `m.room.create.predecessor` schema field names are confirmed second-hand (WebSearch over spec results), not from a directly-read schema block; the predecessor `event_id`-MAY-be-omitted (v1.16+) change is confirmed.
- "≤20 prev_events / ≤10 auth_events" are **not** current spec constants (historical Synapse limits) — do not cite as normative.
- MSC2313's promotion status into the stable spec (vs MSC-only) is unconfirmed; the event types/fields are quoted from the MSC.
- Dendrite's v12 / appservice-sole-creator / `federation_domain_whitelist` support is the explicit subject of the §7.2 verification spike — treat as unverified until the rig confirms it.
- Always query the `m.room_versions` capability live for the default version; never hardcode.