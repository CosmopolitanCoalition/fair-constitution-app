# Phase K-3 "The Mesh Commons" — Implementation Plan

> **Status:** Buildable plan (grounded 5-lane workflow + the design round). Companion to
> `K3-MATRIX-RESEARCH-AND-DESIGN.md` (the authoritative design). Built slice-by-slice with live pins,
> the same discipline as the K-1 plan (`K1-IMPLEMENTATION-PLAN.md`).
> **Slot:** K-3 — the live Matrix social engine (Plane B), bolted onto the merged K-1 civic-record
> plane (Plane A); **bridged, not merged.** No new phase letter — K-3 lights up under the existing
> **'K'** (K-1 already flipped `phasesLive` + the nav `commons` section to `phase:'K'`).

## Locked operator decisions
1. **Homeserver = Synapse** for the dev build (feature-complete: v12, appservices, MAS, policy-server
   awareness all first-class; the amd64 dev box has the RAM). Image = **`ghcr.io/element-hq/synapse`**
   (the live Element-maintained repo — `matrix-org/synapse` was archived Apr 2024; dev relocated to
   `element-hq/synapse`, latest v1.155.0 Jun 2026). **License = AGPLv3** (dual-licensed). Synapse runs
   as a **separate container**; the CGA talks to it over the Matrix HTTP APIs, so AGPL applies to
   Synapse alone and does not infect the CGA app (arm's-length, separate process, standard protocol).
   `MATRIX_IMPL` env-switch keeps Dendrite (also `element-hq`, AGPLv3) as the Pi candidate (rig spike);
   Conduit-family (Apache-2.0) is the permissive escape hatch if AGPL-in-bundle is ever unacceptable.
2. **Full feature suite incl. voice/video** (LiveKit / Element Call) — built + verified on the dev
   stack. The **Pi A/V resource ceiling is deferred to the scaling boxes (C…Z)** — operator-owned.
3. **In-conversation translation** seam built now (privacy-railed); the full NLLB/Haiku hybrid lands
   with Phase N.
4. **Auth = a CGA-run OIDC provider (MAS / MSC3861)** — the homeserver never sees a password.
5. **The appservice lives IN the Laravel app** (AS-API routes + room governance + testimony bridge +
   carve-out emitter + OIDC IdP), reusing `AttestationService` / `RoleService` / `PublicRecordService`
   / `InstanceIdentityService` directly. Only the homeserver is a new process.

## Appservice-in-Laravel (confirmed)
All five appservice responsibilities are thin wrappers over already-battle-tested services, so a
separate-language sidecar would duplicate crypto/vote/role logic across an RPC boundary for zero gain:
carve-out authority = `AttestationService::issue/verifyAttestation` (MAX_TTL_SECONDS=86400); live
roles = `RoleService::rolesFor` (R-03/R-08/R-09/R-19/R-20 already derived); testimony snapshot =
`PublicRecordService::publish` (the exact F-SOC-002/003 path); the flip predicate = a one-line
`Legislature::STATUS_ACTIVE` query; S2S sign/verify = `InstanceIdentityService`. The AS-API routes
follow the existing `routes/federation.php` pattern (registered outside the web group, Ed25519/token
middleware). The homeserver (Synapse/Dendrite) stays a separate container.

## New tables (additive — UUID PK, timestampsTz/softDeletesTz, CHECK enums, partial-unique)
- **`matrix_rooms`** — every appservice-created room/Space; `matrix_room_id`, `matrix_alias`,
  `room_type` (m.space|commons|org_public|org_private|institution), `room_version` (stored from the
  live capability, never hardcoded), `entity_type`+`entity_id` (jurisdiction/organization/bill/…),
  `space_type` (public_square|halls), `is_public`, `is_seated` (flip snapshot), `is_activated`
  (Phase-I seam, default true), `tombstoned_at`. Partial-unique `(entity_type,entity_id,space_type)
  WHERE deleted_at IS NULL` — the reconciler idempotency key (mirrors `social_subforums_object_unique`).
- **`matrix_identities`** — `user_id` ↔ `matrix_localpart` (= `social_profiles.handle`, pseudonymous)
  ↔ device cross-signing master key id. **No name/email/password column ever.** Partial-unique
  `(user_id) WHERE deleted_at IS NULL`; lower-unique `(localpart)`.
- **`matrix_event_snapshots`** — the one-way testimony valve: `matrix_event_id`, `matrix_room_id`,
  `published_record_id` (**uuid** back-pointer into public_records, NOT seq), `actor_display` (frozen
  pseudonym), `origin_server_ts`, `body_snapshot`. Written inside the engine transaction.
- **`matrix_carveout_log`** — append-only audit of every M-1/M-2/M-4 appservice action (room/event,
  carve_out, soft_fail vs hard_redact, attestation_id, issuer_server_id, the F-SOC-003 record id,
  jurisdiction_id, is_seated_at_time). The durable artifact + the §5.4 cross-mesh discontinuity detector.
- **`matrix_server_acls`** — per-room `m.room.server_acl` mirror (allow[] always retaining local + all
  `federation_peers`, deny[] M-1/M-4 only, written_by carve_out). The `allow:[]` self-brick guard lives
  in the writing service; this table is the audit trail (mesh application is rig-gated).

## Forms / kinds
- **No new F-form.** The engine routes the existing K-1 forms: residency-gated `#square` posting =
  **F-SOC-001** (R-03); filing a Matrix message as testimony = **F-SOC-002** (payload carries
  `matrix_event_id`); a carve-out removal = **F-SOC-003** (R-19/R-20, `checkSocialRemoval` validator) —
  the appservice is the *emitter* that translates an F-SOC-003 success into an `m.room.redaction`.
- **One additive `PublicRecord` kind: `moderation_flip`** (string CHECK, no migration) for the
  legitimacy-flip log. No new CLK clock; reuses WF-JUR-06 (attestation) + WF-SYS-04 (rejection).

## CI invariants (assert every build)
1. **NO-HUMAN-POWER** — every public room is v12 (live `m.room_versions` capability), appservice = sole
   creator + sole `additional_creators`, `power_levels.users = {}`, ban/kick/redact/state_default=100,
   events_default/users_default=0. Refuse to create on a homeserver with no v12.
2. **CARVE-OUT-ONLY REMOVAL** — a redaction/soft-fail fires only for a valid M-1 (verified R-19/R-20 +
   case/order ref), M-2 (rights-breach ref), or M-4 (system, behavior/volume). Viewpoint/no-carve-out →
   rejected `Art. I` (reuses `checkSocialRemoval`). M-3 is client-side `m.ignored_user_list`, never an
   appservice action.
3. **RESIDENCY-ONLY POSTING** — `#square` posting iff live `RoleService::rolesFor` includes R-03; never
   karma/age/reputation; non-resident may read world_readable but not post.
4. **FLIP-ON-SEATEDNESS** — operator-board (R-08) relay honored iff no `Legislature::STATUS_ACTIVE` for
   the subtree; switches to honoring only R-19/R-20 (M-1) / R-09-R-10 (M-4 knobs) the instant
   STATUS_ACTIVE exists. Binary, automatic, not seizable. Matrix power level never moves.
5. **TRANSLATION PRIVACY RAIL** — the translation service hard-rejects `(is_private_room && cloud_provider)`.
6. **NO DISCRETIONARY POLICY SERVER ON PUBLIC ROOMS** (MSC4284 viewpoint prior-restraint forbidden).
7. **PSEUDONYMITY** — only `@handle:domain` + displayname + device key + ≤24h role snapshot ever reach
   the homeserver; never name/email/residency/ballot/location. De-anon is judicial-M-1-only.
8. **BEST-EFFORT-REMOVAL HONESTY** — UI/copy says "removal requested under order X / logged", never
   "erased". The durable artifact is the log + public_records row, not byte disappearance.
9. **SCALE-DEMO FEDERATION OFF (CI-2)** — a `scale_demo` instance forces `federation_domain_whitelist`
   empty.
10. **REACH-IS-DISPLAY-ONLY (CI-1)** — reach/legitimacy never enters the authorization predicate; the
    flip pivots on `is_seated`/`is_activated` structural facts only; low-reach degrades safely to
    bootstrap-steward mode.

## The 14 slices (each independently committable + green before the next)

| Slice | Goal | Rig-gated? |
|---|---|---|
| **K3-A** | Stand up the Synapse homeserver (`ghcr.io/element-hq/synapse`, `MATRIX_IMPL` switch) on a second logical `matrix` DB, nginx-proxied at `/_matrix/`, `.well-known` delegation served dynamically by Laravel | no |
| **K3-B** | The bridge schema (5 `matrix_*` tables) + models + `MatrixSchemaTest` | no |
| **K3-C** | Delegate auth to MAS (OIDC); CGA login mints ≤24h refresh-rotating Matrix tokens; `matrix_identities` provisioned on first login | no |
| **K3-D** | Register the CGA appservice + the AS-API route group in Laravel (transactions/user/room queries), as_token middleware, `MatrixClientService` (CS API wrapper) | no |
| **K3-E** | Create PUBLIC rooms in **v12** with the appservice as immutable sole creator + an explicit power map no human can reach; the `allow:[]` self-brick guard | no (mesh ACL = rig) |
| **K3-F** | Reconcile jurisdiction+org+governance-object topology into Spaces/rooms idempotently (extend `EvaluateSocialStructureJob`); `#square` always, `#halls` only when seated | no |
| **K3-G** | Residency-gated `#square` posting + live `cga.acting_seat` annotation (reuse F-SOC-001 + RoleService) | no |
| **K3-H** | The one-way testimony bridge (Plane B → Plane A): a filed Matrix message snapshots into public_records via F-SOC-002, sealed + back-pointer | no |
| **K3-I** | **The centerpiece** — the four carve-outs + the legitimacy-gated seatedness flip (operator-board R-08 → seated R-19/R-20, automatic on STATUS_ACTIVE; Matrix power never moves) | no (peer-judge = rig) |
| **K3-J** | Voice/video: LiveKit (Element Call SFU) on the dev stack, appservice as call relay + token minter | no (Pi A/V = scaling) |
| **K3-K** | In-conversation translation seam (public rooms server-translatable; private/E2EE client-side + local-only; hard privacy rail) | no |
| **K3-L** | Embedded Vue client for `#square`/`#halls` (link-out to Element for power users) + nav + surfaces | no |
| **K3-M** | A standing `matrix:demo --fresh` seeder (Spaces, posts, a testimony filing, a bootstrap→seated flip demo) | no |
| **K3-N** | **RIG-GATED** cross-instance certification: two-box S2S, peer-judge M-1 redaction, server_acl mesh + brick-guard drill, the Dendrite-on-Pi v12 spike, divergent-`constitutional_version` admission refusal (Meter C) | **yes** |

(Full per-slice `adds` / `pins` / `verify` recipes are in the workflow synthesis; each slice carries a
dev-stack verification command sequence — e.g. K3-A: `docker compose up -d matrix; curl /_matrix/client/versions 200; curl /.well-known/matrix/server; psql -lqt shows the matrix DB`.)

## Rig-gated (built + marked; never blocks the dev build)
Two-box Matrix S2S; a peer judge's M-1 attestation driving a redaction in a co-member room
(cross-instance verify against the pinned `federation_peers.public_key`); `m.room.server_acl` mesh +
the `allow:[]` brick-guard drill (the local write + guard is dev-testable in K3-E); the
Synapse-vs-Dendrite-on-Pi v12 spike; divergent-`constitutional_version` Matrix admission refusal
(Meter C reuse); voice/video on the Pi specifically.

## Open risks / environment gotchas
- **`init.sql` only runs on a fresh `postgres_data` volume** — warm dev/worktree stacks need an
  idempotent `CREATE DATABASE matrix / matrix_auth` guard (handled in K3-A via a psql/artisan step).
- **Synapse wants ≥4 GB; default WSL2 is often 2 GB** — a `.wslconfig` memory bump may be needed on the
  Windows dev box (operator-owned env change), else the homeserver OOMs on first sync.
- **`MATRIX_IMPL` env-switch footgun** (mirrors `POSTGIS_IMAGE`): hand-editing `.env` without running
  `deploy.sh` skips the arch default → wrong image. Document the deploy-script dependency.
- **Redaction is best-effort, not erasure** (design §5.7): UI never says "erased".
- **Private-room E2EE vs M-1**: an M-1 order against an E2EE room hits content the appservice can't
  read; the judicial path compels a participant or acts on metadata — documented limitation.
- **Appservice/MAS is the most sensitive seam**: mitigated by un-escrowed device keys, ≤24h revocable
  home-authority-only attestations, v12 creatorship pinned at room creation.
- **Several Matrix spec details flagged unverified** (EDU registry, hash strip-set, tombstone/
  predecessor field names, soft-fail compliance) — re-pin against the live homeserver's advertised room
  version before coding the redaction/upgrade logic (K3-E/K3-I).
- **Planet-scale replication is pathological** — one giant room is forbidden; topology is always
  Spaces + per-jurisdiction rooms; the durable record is Plane A. Sharding is out of K-3 scope.
