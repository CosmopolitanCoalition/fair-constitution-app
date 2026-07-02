# Phase 4 design note — one process to peerage

**Status: SETTLED — the operator ruled on all three flags at the 2026-07-02 morning
walkthrough; §5 records the rulings verbatim and their dispositions. The unflagged
dispositions shipped in Phase 4 (6c3ef60); the flag-gated work (achievements sync-tail
registration) shipped at Phase 4 close-out. One residual nuance is re-flagged in §5.1.**

MASTER_PLAN Phase 4 requires this note before implementation: map the operator-settled
peerage model onto the existing mirror/write-guard/authority code, explicitly, not
silently.

## 1. The settled model (operator, 2026-07-01 — verbatim intent)

- ONE operator process to peerage: any node that can get a cert and take clients becomes
  a full, EQUAL peer.
- The only differentiators between nodes: services a node can't host (hardware) and
  trust-elevated roles (broker/DNS).
- G3c read-write petitions become **vestigial**; verified **forwarded writes are the
  norm**.
- "Authority" = **where a jurisdiction's home copy lives** — a fact about data placement,
  never a caste of node.

## 2. The mapping — settled concept → existing code → disposition

| Settled concept | Existing code | Disposition |
|---|---|---|
| Authority = home-copy location | `jurisdictions.authoritative_server_id` + `AuthorityResolver` | **KEEP UNCHANGED** — this already is the settled model. Per-jurisdiction, never per-node. |
| Forwarded writes are the norm | `WriteRouterService::dispatch()` — the single citizen-write seam: local authority executes, otherwise forwards over the multiplex ladder; `ForwardedWrite` gives exactly-once idempotency; `AttestedForwardedActor` verifies the actor; defense-in-depth re-check at the receiving leader | **KEEP UNCHANGED** — production-ready. Phase 4 adds only the missing *receipt surface* (see §4). |
| Mirror | `InstanceSettings::isMirror()` + sync ingest `authorityDisposition()` (authoritative-instance-wins) | **REFRAME, don't remove.** "Mirror" survives only as a *description* — a node holding no jurisdiction's home copy. It is not a mode that blocks writes (dispatch() already forwards from anywhere). Console copy stops presenting mirror as a rank. |
| G3c read-write petition | `POST /federation/cluster/request-read-write` (+ host-side approve/deny in Federation.vue) | **VESTIGIAL — retire from the UI** (⚑ flag 1). The petition models "read-write" as a status a node begs for, which contradicts equal peerage. What legitimately remains is the per-jurisdiction **authority flip** (export → verified flip → re-peer), which is not a petition — it is a data-migration act between consenting authorities. Routes stay for wire-compat this campaign; the new console never shows the ladder. |
| Consent meters (A operator board / B seated government / C peer mesh) | `PeerUpgradeAgreementService` + `MeshRoleGrantService` | **KEEP UNCHANGED.** The meters govern *upgrades* and *role grants* — capability consent, not peerage caste. They are orthogonal to the collapse and constitutionally live (Art. VII admissibility, seated-government supersession). |
| Trust-elevated roles are the exception | `config/mesh_roles.php` / `mesh_channels.php`, qualify→request→approve→join | **KEEP UNCHANGED** — exactly the settled "only differentiators" carve-out. |

**Net finding: the backend already implements the settled model.** The collapse is a
UI/console truth-telling exercise plus one retirement — not a plumbing rewrite. No
PROTECTED file is touched.

## 3. What Phase 4 implements (unflagged — executes the settled slate)

1. **The operator/* console suite** on the v3 shell, wrapping existing services
   (wrap, never modify): operator-home (readiness rollup + role chips + 8 surface
   doors), console (health + named roles + channel grid + the three meters, read from
   the real services), roles (the qualify→request→approve→join lifecycle over
   `MeshRoleGrantService` — HTTP endpoints wrapping the `mesh:role` CLI verbs),
   mesh (peers table, join wizard, transports, sync log — over `FederationSyncService`
   + the existing Federation.vue forms), dns + identity (over the Operations console
   inventory + `InstanceIdentityService`), versioning (over
   `PeerUpgradeAgreementService`: our versions, peer versions, proposals + meter
   status). moderation stays a designed placeholder (Phase I service).
2. **Federation.vue stays flag-routable** until the multibox campaign proves parity
   (settled). The new suite links to it under an "operations (legacy)" door.
3. **Achievements join the sync tail** (the Phase-3 deferral): `buildAuditTail` exports
   locally-originated sealed achievements alongside public_records; `ingestTail`
   applies them idempotently (the partial-unique (user_id, journey_id) index makes
   replays harmless). See ⚑ flag 2 for the ingest policy choice.
4. **Console copy speaks the settled language**: "authority" always attaches to a
   jurisdiction, never a node ("this node holds the home copy of N places"), and
   "become a peer" is one process (cert + clients), with role elevation as the separate,
   trust-gated ladder.

## 4. The traveling-write receipt (small build, closes a known gap)

The 2026-07-01 pre-flight verified forwarded writes EXECUTE but flagged the missing
citizen surface. Phase 4 adds the minimal honest receipt: when `dispatch()` returns
`status: forwarded`, the acting page shows "Filed — carried to {jurisdiction}'s home
node" with the idempotency key as the reference, and a small
`GET /api/federation/write-status/{origin}/{key}` (auth, own writes only) lets the UI
poll the `ForwardedWrite` outcome (executed + audit_seq | rejected + citation). No new
semantics — it reads the existing table.

## 5. ⚑ Flags — SETTLED by the operator, 2026-07-02

1. **RW-petition UI: retire — CONFIRMED, with the keyless rule.** Ruling (verbatim):
   *"When providing a key then the adoption is presupposed assuming the way they get
   those keys is from some prior step. If no key is provided then the joined mesh
   needs to approve the adoption."* Dispositions: the ladder never enters the new
   console; the legacy Federation.vue copy keeps it only until multibox parity (§2
   row 4), then it goes; wire routes untouched this campaign.
   Code facts (verified 2026-07-02): both halves of the ruling already exist on ONE
   endpoint, `POST /api/federation/adopt`. Keyed: `MirrorJoinKeyService` mint →
   `MirrorService::admitMirror` — immediate admission, consent presupposed. Keyless
   (`key === ''`): `MirrorService::requestAdoption` → pending `ClusterAdoptionRequest`
   → host approves (`cluster:approve` CLI / the GUI queue) while the joiner polls
   until admitted — the setup wizard and the federation console both support it.
   ⚑ **RESIDUAL NUANCE (queued, not built):** today the keyless approver is ONE host
   operator's click — no meter runs. If "the joined mesh approves" means METERED
   consent (a PeerUpgradeProposal-style adoption vote — Meter A board, Meter C
   co-affected peers), that gate is new wiring; the pending-queue model, the poll
   loop, and the meter services it would reuse all exist. Decision belongs with the
   multibox campaign phase; flagged rather than silently built.
2. **Achievements ingest: APPEND-ANY-VERIFIED — confirmed and implemented.**
   Export: locally-originated sealed rows (`source_server_id IS NULL`, `audit_seq`
   set) windowed to the tail, ordered by `audit_seq` (achievements has no bigint
   `seq` column). Ingest: after the four tail gates, `insertOrIgnore` keeping the
   ORIGIN id, `audit_seq` NULL + `source_server_id` = the shipping peer (the
   mirrorRecord posture); NO authorityDisposition, NO `users.home_server_id` gate.
   Idempotency is two-layer: pk replay + the partial-unique (user_id, journey_id)
   collapses cross-node double-earns to first-arrival-wins. Applied medals fold into
   the sync result classification, ledger as `detail.achievements_applied`, and count
   on the cold-sync cursor. Rollout note: a tail pushed to a PRE-UPGRADE peer drops
   the achievements key silently while `last_synced_seq` still advances — same-version
   mesh, or a cold-sync re-pull, backfills.
3. **Traveling-write receipt: minimal-now CONFIRMED.** The owner-only poll endpoint
   stands as shipped; notification plumbing (async rejected-write receipts, "watch
   your filing travel") moves to Phase 6 on the event feed — operator: *"Notification
   Plumbing moves to Phase 6 is fine."*

## 6. What Phase 4 does NOT do

- No changes to `WriteRouterService`, `AuthorityResolver`, the meters' math, or any
  PROTECTED constitutional file.
- No removal of wire routes (only console presentation changes).
- No authority-flip implementation changes (the flip is exercised in the multibox
  campaign, Phase 10c).
- No merges to main — Phase 4 rides feature/v3-wiring until an operator-declared pause.
