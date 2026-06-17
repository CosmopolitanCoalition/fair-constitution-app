I now have complete grounding. Matrix was never considered before (the only "matrix" hit is a CI-matrix reference, unrelated). The exploration designed a bespoke in-app forum schema; the operator's task is fundamentally different — adopt **Matrix (Synapse/Dendrite)** as a real chat/community platform integrated with the mesh. Let me produce the design.

---

# Social Layer as Matrix — Design Round (roadmap-letter recommendation: **Phase K-M, "The Mesh Commons"**)

## 0. What I read and the load-bearing facts I'm grounding on

- **Constitution Art. I** (`docs/extracted/fair_constitution.md:9-10, 29-30, 35-36, 16, 17, 31-34, 50`) — verbatim clauses quoted in §3 below.
- **The social exploration** (`explore-social/.../education-social-layer.md`) — its §5.3 moderation stance and the **four carve-outs M-1..M-4** are the spec I inherit; I do *not* re-derive them.
- **The roadmap charter** (`docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md:265-312`) — **Phase K = "Public Square, Civic Education & Achievement Surfaces"** already exists and already owns the bespoke-forum schema (`social_*` tables) + F-SOC/F-EDU forms.
- **Phase G identity primitives I verified exist and will bridge to Matrix:**
  - `App\Services\Federation\InstanceIdentityService` (`InstanceIdentityService.php:27-209`) — per-instance Ed25519 keypair, `serverId()`, `sign()`, `verify()`, `sealTo()/openSealed()`. This is the operator/server identity.
  - `App\Services\Identity\ActorIdentityService` (`ActorIdentityService.php:19-75`) — **G-ID device layer**: per-person enrolled Ed25519 device keys (`enrollDevice`, `verifyActionSignature`), secret never leaves device, no escrow.
  - `App\Services\Identity\AttestationService` (`AttestationService.php:34-155`) — **the CA that signs a short-lived, revocable SNAPSHOT of a person's derived role codes** (`issue()`, `verifyAttestation()`, `revoke()`, `MAX_TTL_SECONDS=86400`), bound to a device key, signed by the instance key. Only the HOME authority attests (`:57`). This is exactly the primitive that gates Matrix moderation power.
  - `App\Services\Identity\AttestationGate` (`AttestationGate.php:21-58`) — `ResolvesRoles`; live role derivation for local users (Art. I: roles never stored).
  - `App\Services\Mirror\MirrorService` (`MirrorService.php:30-380`) — read-only mirror commons, `ClusterMembership` roles `MIRROR`/`HOST`, join-key + vouch admission.
  - `App\Services\Federation\WriteRouterService` (`WriteRouterService.php:29-200`) — authority ≠ leadership; forwarded writes execute through the normal engine; attested-actor context authorizes exactly one forwarded filing.
- **Federation sync boundary:** `PublicRecordService::FORBIDDEN_SUBJECT_TYPES = [ballot, ballot_envelope, location_ping, residency_claim_pings]` (`PublicRecordService.php:34-39`); `FederationSyncService::buildAuditTail()` ships only `whereNull('source_server_id')` (`FederationSyncService.php:93`).
- **Office R-codes that gate moderation:** R-09 Legislative Rep, R-10 Speaker, R-18 BoG, R-19/R-20 Judge, R-21 Advocate, R-23 Org Agent, R-30 Civil Officer (`roles_forms_chart.md:16-30,33`). Derived, never stored.
- **Legitimacy denominator:** `App\Support\CivicPopulation::of()` (`CivicPopulation.php:26-32`) = active `residency_confirmations`. The roadmap's Phase I `LegitimacyService::reachRatio()` is **not yet built** (grep: only `CivicPopulation` exists; `legitimacy_snapshots`/`LegitimacyService` are roadmap-only).
- **Docker stack:** `docker-compose.yml` — `fc_app/nginx/postgres/redis/vite/horizon/scheduler/etl`, single `fc_network` bridge, `POSTGIS_IMAGE` arm64 override pattern, healthcheck-gated startup ordering. No chat service exists.
- **Matrix was never previously considered** — the only "matrix" token in the repo is a CI-matrix note (`PHASE_G_IMPLEMENTATION_PLAN.md:700`).

The single most important reframing: **the exploration and Phase K designed a *bespoke in-app forum* (`social_threads`/`social_posts`). The operator's task is different — adopt a *real federating chat platform (Matrix)* as a Discord replacement.** These are not the same deliverable. My recommendation (§7) reconciles them: Matrix is the **realtime community/chat substrate**; the bespoke `social_*` plane stays as the **append-only civic record** the constitution compels. They are complementary, not competing — and that split is exactly what Art. I forces.

---

## 1. Why Matrix, and the one structural mismatch to resolve up front

Matrix (Synapse reference homeserver, or Dendrite for a lighter arm64/Pi footprint) is the right choice: open-source, self-hostable, **already federated** (server-to-server `/_matrix/federation` over signed JSON, per-server Ed25519 signing keys), rich client ecosystem (Element), spaces/rooms/threads/reactions/voice/video, and an account-data + power-levels model that maps cleanly onto offices.

**The structural mismatch that drives the whole design:** Matrix's *native* moderation model is **power levels** — a per-room integer ladder where higher-power users can redact (delete) messages, kick, and ban. That is precisely **community-standards moderation**, which **Art. I forbids in public spaces** (quoted §3). So Matrix cannot be dropped in with default semantics. The design's central job is to **constrain Matrix's power-level model so that, in *public* rooms, no power level can censor**, while letting it operate fully in *private* (org/user) rooms. This is done with a **CGA-controlled appservice** that owns power in public rooms and refuses redactions except for the four carve-outs.

---

## 2. The two-plane architecture (this is the keystone)

The constitution forces a clean split that also happens to be the cleanest engineering boundary:

| Plane | Substrate | Mutability | Federation path | What lives here |
|---|---|---|---|---|
| **A — Civic Record plane** | the bespoke `social_*` tables + `public_records` (Phase K, already designed) | append-only; halls never deletable | rides existing `FederationSyncService` Path A (`public_records`) + Phase G mirror commons Path B | Halls-of-governance testimony, published positions on bills/referendums/petitions, candidate platforms — **the Art. II §2 mandated public record** |
| **B — Live Commons plane** | **Matrix homeserver** (`fc_matrix`) | realtime, ephemeral-friendly | Matrix native S2S federation, mesh-gated (§4) | The public square (realtime discussion), org/community rooms, DMs, voice/video, the Discord-replacement experience |

The two planes are **bridged, not merged.** A halls discussion *happens* in a Matrix room (plane B) but when a participant **files testimony** (`F-SOC-002`, which already exists in the Phase K design), the appservice copies that one message into `public_records` via `PublicRecordService::publish()` (plane A), sealing it into the audit chain. The message stays in Matrix; the *civic act* lands in the immutable record. This is the existing exploration's **Path A** (`education-social-layer.md` §4.3) realized through a Matrix→`public_records` bridge instead of an in-app form submit.

Rationale: plane A *must* be append-only and audit-chained (Art. II §2 — `PublicRecordService.php:81` seals every publish into the hash chain). Matrix rooms are not audit chains and should not pretend to be. Conversely, realtime chat *must not* be append-only-forever (people delete typos; DMs are private). Keeping them separate means **neither plane has to violate its own invariants.**

---

## 3. Constitutional grounding — Art. I quoted, and what it compels

The four Art. I clauses that govern this layer, verbatim from `docs/extracted/fair_constitution.md`:

> **Freedom of Expression (`:10`):** "Individuals can freely express their thoughts, opinions, and ideas through any medium without fear of censorship, retribution, or suppression **in public spaces** nor subjection to community standards **in their private spaces**."

This is the load-bearing clause. It does two things at once: (a) **bans censorship/suppression of public-space content**, and (b) bans **subjecting** people to **community standards** in their *private* spaces (i.e. an instance cannot impose its house rules on a user's own private room). Crucially, the clause's structure means *community-standards moderation is the prohibited mode* — public spaces get no community-standards moderation at all; private spaces get no *externally imposed* community standards, but the space's *owner* may self-moderate (the exploration's reading, §5.3, which I inherit).

> **Access to Information (`:30`):** "Individuals have access to news and information and can report news freely without fear of censorship or interference."

→ Public rooms are read-open; no characteristic-based read gate; no viewpoint filtering of the room timeline.

> **Freedom of Assembly and Association (`:36`):** "Individuals can peacefully assemble and associate with any other individual without fear of warrantless or unreasonable intervention or repression."

→ Rooms/spaces/DMs are protected assemblies; no fiat dissolution of a lawful room. Matrix room-create is a protected act.

> **Privacy and Security (`:16`):** "Individuals are secure in their bodies, homes, property, and **communications** from both Government and private interference… warrantless or unreasonable… surveillance."

→ **DMs and private rooms are constitutionally private communications.** This forbids the operator from reading/scanning private Matrix rooms; it forbids server-side content scanning of private rooms; de-anonymization only by judicial process (the exploration's P-5).

> **Right to Vote / Stand (`:31-34`):** participation "regardless of any characteristic except jurisdictional association."

→ **Residency is the only gate** on posting *as a constituent* in a jurisdiction's public square. No karma/account-age/Matrix-reputation gate. (Phase K's iron rule, `education-social-layer.md:726`.)

**The carve-outs (inherited verbatim from the exploration §5.3, not re-derived):** the only permitted removals in a public space are **M-1** judicial order, **M-2** protecting another's rights (privacy breach / triad leak / doxxing), **M-3** per-user block (a *private* act curating one's own feed), **M-4** content-neutral anti-spam (behavior/volume, never viewpoint). Everything else — "violates our values," viewpoint shadow-ban, operator/legislator discretionary delete — is **forbidden in public rooms.**

---

## 4. Part 1 — Matrix self-hosting in the docker stack + mesh federation

### 4.1 Docker integration

Add to `docker-compose.yml` (additive, same `fc_network`, same env-override discipline as the existing services):

```
fc_matrix      Dendrite (arm64-friendly) or Synapse    internal:8008 (CS API), :8448 (S2S)
fc_matrix_db   (REUSE fc_postgres — add a `synapse`/`dendrite` database to init.sql; no second PG)
fc_appservice  CGA Application Service (Laravel-side bridge, runs in fc_app or a sibling)
```

- **Reuse `fc_postgres`.** Add a `matrix` DB in `docker/postgres/init.sql` (the file is already mounted at `:170`). One Postgres, two logical DBs — keeps the arm64 `imresamu/postgis` rebuild (`:93`) as the single DB image, honoring the memory-tuning already done for the Pi.
- **Dendrite over Synapse for the default/Pi profile.** Synapse is the reference but RAM-hungry; Dendrite is Go, single-binary, far lighter on a Raspberry Pi (the operator's real deploy target, per MEMORY G-V2). Offer Synapse as a `MATRIX_IMPL` env override (same pattern as `POSTGIS_IMAGE`).
- **nginx** (`docker/nginx/default.conf`) gains a `location /_matrix/` proxy block to `fc_matrix:8008` and the `.well-known/matrix/{server,client}` delegation files, so the homeserver is reachable on the *same* host/domain as the app (server name = the instance's federation domain). nginx already gates on `app` healthcheck (`:74-78`); add `fc_matrix` to its `depends_on`.
- **Matrix's own signing key** is *separate* from the CGA instance key by default. **Recommendation:** derive the Matrix `signing.key` deterministically from, or co-locate it with, `instance_settings.private_key_encrypted` so an instance has **one cryptographic identity** across both the CGA mesh and Matrix S2S. (Open decision §8 — Matrix uses Ed25519 too, so the same keypair *can* sign both; needs a careful key-namespacing review.)

### 4.2 Mesh-gated Matrix federation (the novel part)

Matrix federation is normally open to the whole public Matrix network. **For the CGA we gate Matrix S2S federation to the CGA mesh** so the "social layer is as fork-able as everything else" but does not silently bridge to the entire fediverse unless the operator opts in.

- **Federation allowlist driven by `FederationPeer`.** Matrix supports `federation_domain_whitelist` (Synapse) / per-server ACLs. A small `MatrixFederationSyncJob` writes the homeserver's allowlist from the **already-pinned `federation_peers`** table (each peer that has a Matrix homeserver and a live `ClusterMembership`). So **the same peers that mirror your public records can federate Matrix rooms** — one trust fabric, two protocols. A peer admitted via `MirrorService::admitMirror` (`MirrorService.php:108`) optionally gets added to the Matrix allowlist.
- **Mirror vs co-member maps onto Matrix room visibility:**
  - **Mirror peers** (read-only commons, Prong 1) → can *view/join* public-square rooms as read-only-ish participants (Matrix `world_readable` history + low default power) but their users have **no posting-as-constituent** right in *your* jurisdiction's square (residency is local — `CivicPopulation::of` counts *your* `residency_confirmations`).
  - **Co-member clusters** (gov-validated R/W, Prong 2, `ClusterMembership::ROLE_HOST` + the autonomy track) → their attested residents *can* post as constituents in shared rooms, because Phase G's `AttestationService` lets a peer present a signed standing snapshot (§6).
- **A `scale_demo` instance forces Matrix federation OFF** — mirrors the roadmap's CI-2 boot assertion (`CGA_PHASE_G_AND_BEYOND_ROADMAP.md:457`); a demo has no consent so it never federates chat.

### 4.3 Room/space topology (mirrors the jurisdiction tree)

- One **Matrix Space** per civically-active jurisdiction (Matrix Spaces are rooms-of-rooms; they nest exactly like `jurisdictions.parent_id`).
- Inside each jurisdiction space, **canonical rooms** auto-provisioned by the appservice:
  - `#square:<domain>` — the **public square** (public, `world_readable`, residency-gated posting).
  - `#halls:<domain>` — **halls of governance** (public, append-bridged to plane A).
  - **auto-bound rooms** per live governance object (one room per active bill / referendum question / committee / petition / candidacy) — the Matrix realization of the exploration's `social_subforums` auto-bind reconciler (`education-social-layer.md:479`). The reconciler creates/archives Matrix rooms instead of subforum rows; idempotency key = the anchor `(type,id)`.
- Org/community rooms (the Discord-replacement use case) are **private spaces** owned by an `organizations` row (R-23 agent) — full self-moderation (Art. I private half).
- The flat→structured scaling (`education-social-layer.md` §4.1) still applies: a small jurisdiction is one `#square` room; a large one grows the auto-bound room tree. `EvaluateSocialStructureJob` (already in the Phase K design) toggles whether auto-bound rooms are created, gated on `CivicPopulation::of` thresholds.

---

## 5. Part 2 — realizing the public square + halls of governance

| Exploration concept | Matrix realization |
|---|---|
| **Public square** (open resident discourse, `education-social-layer.md` §2.2) | `#square` room, `world_readable`, join/post gated to residents (R-03+) via appservice membership control; **CGA appservice holds the only PL≥50** so no human can redact |
| **Halls of governance** (deliberation tied to institutions, Art. II §2) | `#halls` + auto-bound per-object rooms; **filing testimony** = appservice copies the event into `public_records` (`F-SOC-002`), back-pointer `published_record_id` (the exploration's `social_threads.published_record_id`, `education-social-layer.md:256`) |
| **Officeholder speaking in office** (`social_posts.is_official`, `education-social-layer.md:273`) | a verified seat-holder's message in `#halls` is tagged via a custom event field `cga.acting_seat` (`legislature_member`/`committee_seat`/…), validated by the appservice against the live attestation roles |
| **Unobstructable priority channel** (Art. II §3, Speaker ensures each member can communicate priorities) | a seated member's posts in `#halls` can **never** be rate-limited/redacted by anyone — the appservice exempts R-09 from M-4 spam throttling in the halls |
| **Petition pathway** (Art. II §6) | a `#petition:<id>` auto-bound room links to the existing `PetitionController`; signing stays in-app (the civic act), discussion in Matrix |
| **Multilingual access** (Art. V §4) | Matrix is content-agnostic; per-message translation rides Phase N's pipeline as an appservice-side enrichment, not a gate |

The **in-app Vue surfaces stay** — a `Discussion` panel on `BillDetail.vue`/`Referendums.vue`/etc. (the exploration's §4.2 hook map) embeds the relevant Matrix room (via Matrix JS SDK / a thin embedded Element, or a custom Inertia component reading the room timeline). The civic-record plane A still renders the append-only testimony list. Users who prefer a full client use **Element** pointed at the homeserver.

---

## 6. Part 3 — moderation GATED by legitimacy + the Art. I rule

This is the heart of the design. **Matrix power levels are inverted from constitutional moderation:** Matrix says "high power → can delete." The CGA says "in public rooms, *no one* can delete except for four carve-outs, and *who* may invoke a carve-out is gated by *office* (a derived role), not by power-buying or operator fiat."

### 6.1 The appservice owns power in public rooms

- In every **public** room (`#square`, `#halls`, auto-bound), the **CGA appservice bot is the sole holder of PL≥50** (redact/kick/ban power). **No human user is ever granted redaction power** in a public room. This is enforced at room creation and re-asserted by a reconciler (so a Synapse admin can't quietly raise someone's PL).
- All redaction/kick requests in public rooms go **through the appservice**, which **refuses** unless the request matches a carve-out. A raw `m.room.redaction` from any human in a public room is rejected (the appservice, holding the only power, simply never honors it; clients can't redact others' messages without PL).

### 6.2 The carve-outs, each gated by a *derived office role* via the G-ID attestation

The appservice resolves the actor's standing through the **existing `AttestationGate`/`AttestationService`** (`AttestationGate.php:34`, `AttestationService.php:72`) — the snapshot of `RoleService::rolesFor`. So "who can moderate what" is a pure function of *which constitutional office a person currently holds*, never a stored moderator flag:

| Carve-out | Who may invoke (gated role) | Mechanism | Always logged |
|---|---|---|---|
| **M-1 — Judicial order** | a judicial actor (**R-19/R-20 Judge**) presenting a *case outcome* (the Art. IV pipeline), or `F-SOC` judicial-removal form | appservice redacts the specific event; writes a `public_records` row citing the **case id + order**, not "standards" | **yes — public record** |
| **M-2 — Protecting another's rights (privacy breach / triad leak)** | **automatic** (no human needed) for triad content; otherwise a judicial hold (R-19/R-20) pending review | the triad case is structural: `PublicRecordService::FORBIDDEN_SUBJECT_TYPES` already refuses ballot/location/credential content (`PublicRecordService.php:34-39`); the appservice mirrors that refusal so triad data can't even reach `#halls` plane A; a doxxing hold is a judicial M-1 sub-case | **yes** |
| **M-3 — Per-user block** | **any user (R-01)** for *their own* feed | native Matrix `m.ignored_user_list` (account-data, client-side, private) — content stays up for everyone else; block list **never federates, never audited** (P-6) | **no — it's private** |
| **M-4 — Content-neutral anti-spam** | **system only** (appservice), keyed on behavior/volume/automation | appservice rate-limits by message frequency / new-account-burst / link-malware scan — **never by topic/viewpoint**; thresholds are amendable `constitutional_settings`; **R-09 exempt in halls** (Art. II §3) | rate-limit events logged, content not deleted |

**Legitimacy gating, precisely.** "Moderation powers tied to legitimacy gates" means:

1. **Office is the gate, and office is earned through the legitimacy chain.** R-19/R-20 (judges) only exist after `I-JUD` is created by a legislature that itself exists only by `Art. II §1` consent. So the *only* humans who can cause a public-room removal are officeholders whose authority traces back to consent of the governed — never an operator, never a sysadmin, never a "moderator" role that exists outside the constitution. The appservice **has no concept of a "moderator" account** — it only knows R-codes.
2. **The instance's own legitimacy gates whether the square federates as authoritative.** A jurisdiction that has not reached its **activation tier** (Phase I, `ActivationTierService`, roadmap `:216`) — i.e. has too few `CivicPopulation::of` residents to have a real government — runs its square in **mirror/observer mode**: its `#square` exists but it cannot host *authoritative* halls testimony, because there's no seated legislature to deliberate. Reach (`LegitimacyService::reachRatio`, Phase I) is **displayed** beside the square (the "legitimacy gauge") but — per the roadmap's hard rail CI-1 — **reach is never a moderation input**; it's transparency, not power.
3. **Org/private rooms are exempt** — the org (its R-23 agents) holds full power-level moderation in its own space (Art. I private half). The appservice does *not* clamp power in private rooms; it only clamps **public** ones.

### 6.3 Why this cannot become stealth censorship

Every M-1/M-2 action is itself a `public_records` row (append-only, hash-chained, `PublicRecordService.php:81`) and appealable through the Art. IV §5 pipeline — exactly the exploration's invariant (`education-social-layer.md:720`). A redaction with no matching case id is structurally impossible: the appservice requires the order reference before it will act, and the action is sealed into the audit chain. **An operator with root on the box can still tamper with their own DB — but a *peer* mirror holding the signed `public_records` tail will detect a censored-but-unlogged removal as a hash-chain discontinuity** (the same Ed25519-verified tail that `FederationSyncService.ingestTail` already checks). Censorship is detectable across the mesh.

---

## 7. Part 4 — the identity bridge (mesh/operator/citizen ⟷ Matrix accounts)

Three identity tiers already exist in code; each maps to a Matrix concept:

| CGA identity | Code symbol | Matrix concept | Bridge mechanism |
|---|---|---|---|
| **Instance / operator** | `InstanceIdentityService` server_id + Ed25519 (`InstanceIdentityService.php:49,77`) | **Matrix homeserver name + S2S signing key** | one instance = one homeserver; share/derive the Ed25519 key (§4.1) so the same identity signs CGA peer messages *and* Matrix federation |
| **Citizen / person** | `users.id` (UUID) + `users.display_name` (pseudonym-capable) | **Matrix user `@<localpart>:<domain>`** | appservice owns a namespace; a CGA login provisions/binds a Matrix account via **SSO** (the appservice is the IdP); `display_name` ↔ Matrix displayname, **`name`/email never exposed** (P-3) |
| **Device** | `ActorIdentityService` enrolled device Ed25519 (`ActorIdentityService.php:24`) | **Matrix device / E2EE device key** | the G-ID device key can be the same Ed25519 used as the Matrix device signing key — one enrolled device, one signing identity for both action-signing and E2EE |
| **Standing (roles)** | `AttestationService` signed role snapshot (`AttestationService.php:72`) | **room membership + the appservice's power decisions** | the appservice verifies the attestation (`verifyAttestation`, `:107`) to decide square-posting rights and carve-out eligibility |

**Bridge flow (single-instance, available now without the rig):**
1. User logs into the CGA web app (existing session auth).
2. The **CGA appservice acts as the Matrix SSO IdP**: it provisions `@<user-uuid-localpart>:<domain>` (or `@<handle>` from the exploration's `social_profiles.handle`, `education-social-layer.md:185`) and issues a Matrix access token. **No separate Matrix password** — Matrix identity is *derived* from CGA identity, so there is exactly one account a person manages.
3. The appservice sets the user's Matrix displayname = `display_name`, never `name`/email (pseudonymity preserved, P-5).
4. Room membership in `#square:<jurisdiction>` is granted iff `RoleService::rolesFor($user)` (live, via `AttestationGate.php:34`) includes R-03 for that jurisdiction. **Residency is the only gate** (Art. I).

**Cross-instance flow (rides Phase G, lights up when co-membership lands):**
- A person whose home authority is **peer P** wants to post in **our** shared co-member room. Their home instance issues a `StandingAttestation` (`AttestationService::issue`, only the HOME authority attests, `:57`) bound to their device key. Our appservice verifies it against P's pinned `federation_peers.public_key` (`verifyAttestation`, `:107`), reads the attested R-codes, and grants room rights for exactly the TTL (≤24h ceiling, `:37`). This is the **person-level analogue of the forwarded-write attestation** the `WriteRouterService` already uses (`WriteRouterService.php:152`) — reused, not reinvented.
- The attestation **never carries credentials/ballots/locations** (it's a role-code snapshot, `:72`) — so the Matrix identity bridge inherits the privacy boundary for free; the protected triad never travels.

**What never bridges to Matrix:** `users.name`/email/password, raw locations, ballots, `residency_confirmations` rows, follow/block lists (Matrix ignore-list stays client-local), `education_progress`. Matrix only ever sees a pseudonymous handle, a displayname, a device key, and a short-lived role snapshot.

---

## (a) Which roadmap letter / sub-phase this slots into

**Recommendation: this is NOT a new letter — it extends and partly *replaces the substrate of* Phase K, and I'd name the Matrix-bearing increment "Phase K-M: The Mesh Commons" (K, third sub-phase).**

Reasoning:
- **Phase K already exists** (`CGA_PHASE_G_AND_BEYOND_ROADMAP.md:265`) and already owns "the public square + halls of governance + moderation-with-four-carve-outs + identity." Creating a *new* letter would fork ownership of the same constitutional surface and collide with the existing `social_*` schema, F-SOC/F-EDU forms, and the §5.3 moderation stance. The roadmap explicitly warns against self-assigned colliding letters (MEMORY: "USE THESE LETTERS").
- **But Matrix is a material change to *how* Phase K is built** — it swaps the realtime layer from bespoke `social_threads`/`social_posts` to a real federating chat server. That is significant enough to be its own **design round + implementation plan** (the A–G discipline), so it should be a clearly-marked **sub-phase of K**, sequenced as:
  - **K-1 (Civic Record plane):** the append-only `social_*` + `public_records` bridge, halls testimony, education — *as the exploration designed it.* Build first; it's the constitutionally-compelled part and needs no Matrix.
  - **K-2 (Education + Learn Area):** unchanged from the exploration.
  - **K-3 (The Mesh Commons / Matrix):** the homeserver, the appservice, the power-clamp, the identity bridge, mesh-gated S2S. This is the new work this design specifies.
- **Dependency-wise** K-3 sits exactly where K already sits in the critical path (`G → H → I → J → K → …`): it **depends on Phase G** (`AttestationService`/`MirrorService`/`WriteRouterService` — all built and merged per MEMORY) for the cross-instance identity bridge, and on **Phase I** (`ActivationTierService`/`LegitimacyService`) for the legitimacy gauge. Single-instance K-3 (homeserver + power-clamp + local SSO) is **buildable on the dev stack now**; the cross-instance federation half is **rig-gated** like Phase G's G-V2.

If the operator strongly prefers a distinct letter for visibility, the *only* defensible standalone letter is a **post-N infrastructure track** — but I recommend **against** it; it belongs in K.

## (b) OPEN DECISIONS for the operator

1. **Synapse vs Dendrite as the default homeserver.** Dendrite is lighter on the arm64 Pi (the real deploy target) but historically less feature-complete (some moderation/appservice edges). Synapse is the reference but RAM-heavy. Recommendation: **Dendrite default + `MATRIX_IMPL=synapse` override**, but the operator should confirm Dendrite's appservice + power-level + room-ACL support covers the §6 clamp.
2. **One Ed25519 key for both CGA mesh and Matrix S2S, or two separate keys?** Sharing (`InstanceIdentityService` key as the Matrix signing key) gives one instance identity and simplest UX; separating is cryptographic hygiene (key compromise blast-radius). Needs a security review of Matrix's key-rotation semantics vs `InstanceIdentityService::rotate()`.
3. **Embedded client vs link-out to Element.** Embed a Matrix room view inside the Inertia/Vue surfaces (heavier build, seamless UX) vs. link out to a hosted Element (lighter, but two UIs). Recommendation: embed for `#square`/`#halls`, link-out for power users.
4. **Does Matrix federation default open-to-fediverse or mesh-only?** Recommendation **mesh-only by default** (allowlist from `federation_peers`), operator-opt-in to wider federation. Confirm.
5. **Bespoke `social_*` plane: keep, or thin it to a Matrix index?** My design keeps the *civic-record* `social_*` tables (plane A) and lets Matrix own realtime (plane B). The operator could instead make `social_*` a *thin index* of Matrix room/event ids. Recommendation: **keep plane A independent** (it must be audit-chained regardless of Matrix uptime), but this is a real fork in the design.
6. **Voice/video (the full Discord-replacement).** Matrix supports it via Element Call / LiveKit (a separate SFU container). In scope for K-3 or deferred? Adds a `fc_livekit` service and TURN/STUN ops. Recommendation: defer to a K-3b.
7. **Who runs the homeserver in the demo (`scale_demo`)?** Federation is forced off; should the demo even *have* a square, or just a read-only illustration? (Mirrors CI-2.)
8. **Legitimacy gauge semantics confirmation.** Phase I's `LegitimacyService`/`legitimacy_snapshots` is **not built yet**. K-3 should consume it read-only when it lands; until then the square shows raw `CivicPopulation::of`. Confirm the gauge is display-only and never a moderation input (CI-1).

## (c) Risks

1. **The moderation-model inversion is the headline risk.** Matrix's entire native moderation UX (power levels, redaction, room admins) is built for community-standards moderation, which Art. I forbids in public rooms. The appservice must *defeat* the default model in public rooms (sole-PL-holder + reconciler). A Synapse admin with shell access can still raise power levels out-of-band; the reconciler must re-clamp aggressively, and cross-mesh hash-chain detection (§6.3) is the backstop. **This will surprise operators expecting Discord-style moderation** — same warning the exploration flagged (`education-social-layer.md:798`), now sharper because Matrix *invites* the forbidden behavior.
2. **One-identity bridge is a single point of compromise.** If the CGA appservice/IdP is breached, an attacker provisions arbitrary Matrix identities. Mitigated by: device keys never escrowed (`ActorIdentityService` secret stays on device, `:24`), attestations short-lived + revocable (`:37,119`), and the home-authority-only attestation rule (`:57`). Still, the SSO seam is sensitive.
3. **Matrix S2S federation surface is large and CVE-prone.** Synapse/Dendrite are big network-facing services. Mesh-gating the allowlist (§4.2) shrinks the attack surface to pinned peers; a `scale_demo` forces it off. But this adds a substantial new federation daemon to harden — heavier than the existing Laravel-only mesh.
4. **Privacy of DMs/private rooms must hold against the operator.** Art. I `:16` makes private communications constitutionally protected. The operator runs the homeserver and *technically* could read unencrypted private rooms. **Default E2EE on all private rooms + DMs** is essentially mandatory to honor `:16`; this complicates the M-1 judicial-order path (you can't redact what you can't read). Tension between privacy and judicial process needs explicit handling.
5. **Two-plane bridge drift.** A halls discussion in Matrix (plane B) and its filed testimony in `public_records` (plane A) can diverge (message edited in Matrix after testimony filed). Mitigation: testimony is a *snapshot* (`actor_display`/body frozen at file time, exactly as `social_posts.author_display` snapshots, `education-social-layer.md:268`); the Matrix message is the live conversation, the record is the immutable civic act. Must be clearly surfaced so users understand "filing" is the constitutional act.
6. **Arm64/Pi resource pressure.** The Pi deploy (MEMORY G-V2) already runs 8 containers under a ~7.5 GB cap with PG memory-tuned to fit. Adding a homeserver (+ optional LiveKit) may exceed the Pi. Dendrite mitigates; voice/video likely can't run on the Pi at all. Needs a resource budget pass like the Phase L PG tuning (`docker-compose.yml:113-131`).
7. **Federation identity collision with the existing flip model.** Matrix has its own room-authority/state-resolution model that is *independent* of CGA's `authoritative_server_id`. A jurisdiction authority-flip (`AuthorityFlipService`) does not automatically move Matrix room control. Reconciling "who is authoritative for this jurisdiction's square room" across a flip is unsolved here and parallels Phase G's authority-flip work — flagged, not solved.
8. **Scope.** This is a large new infrastructure surface (a whole federating daemon + appservice + IdP) layered on the already-large Phase K. Strongly recommend the sub-phasing (K-1 record plane → K-2 education → K-3 Matrix) so the constitutionally-compelled append-only record ships independently of the heavier, rig-gated Matrix federation.