# CGA — Phase G Master Plan: Federated Adoption, Earned Autonomy & the Social Mesh

> The **charter** for Phase G — vision, locked decisions, the three tracks, the build order,
> and the invariants that gate everything. The **code-grounded build manual** (column-level
> schema, every named service/route/command/test per sub-phase, the risk register) is
> `PHASE_G_IMPLEMENTATION_PLAN.md` in this directory. All Phase G work is **additive** —
> protected migrations (`jurisdictions`, `ballots`, `audit_log`) are never edited.

## Context

Phase F (COMPLETE, `main` @ `299dcee`) made the app a federation of **sovereign** peers —
each jurisdiction's own authoritative instance trading signed public records under Full
Faith & Credit, two real instances proven peering live. Phase G makes the network
**adoptable and fork-able at the social layer** and lets real jurisdictions **earn
read/write autonomy** as their verified population grows and seats a government. A
constitutional network is legitimate only if (a) **anyone** can stand up an instance and
participate without permission, and (b) **authority** over a real place is **earned by
population and granted by that place's own government**, never handed out by an admin.

## The two-pronged trust model (the soul of Phase G)

- **Prong 1 — PERMISSIONLESS read-only mirror (the commons).** Anyone runs an instance and
  adopts in as a **read-only mirror of the public records** (FF&C). No government approval; a
  mirror is authoritative for **nothing** and writes **nothing** locally. The substrate of
  fork-ability — anyone can run their own instance, an overlapping network, or a separate
  one; the network survives social fracture or tech failure because the public-records layer
  is a replicable commons no one owns.
- **Prong 2 — GOVERNED read/write co-membership (earned authority).** Elevation from mirror
  to a **jurisdiction peer** (co-equal R/W authority over a subtree) requires **approval and
  validation by the current authoritative government** — a constitutional act, not an
  operator's call. Authority is earned by population (CLK-06 activation → a seated
  government) and granted by the jurisdiction's own constitution (the
  `local_autonomy_promotion` vote, and government-validated admission of each co-member
  node). *(Stronger than "require a sovereign peer": the gate is the seated government; the
  exact grain — full vote vs delegated administrative approval under that vote — is a
  G·co-member/G7 detail, but the principle holds: authority-sharing is government-validated.)*
- **The cohesion mechanism — internal SSO among peers (G-ID).** A person's standing
  (residency → derived rights, never stored) becomes **portable + verifiable across federated
  peers WITHOUT replicating credentials**. Social cohesion of the mesh is therefore **as
  strong as the collective player base of all federated peers** — which is why peer-SSO is
  strategically central. This is the G-ID self-sovereign actor-identity / attestation layer.

Together: **infinite fork-ability at the social layer** — the commons is open to all,
legitimate authority is governed, identity binds the social fabric.

## Architecture invariant (the cardinal rule)

THREE relationships: **sovereign peer** (exists) · **mirror** (permissionless, authoritative
for nothing) · **co-member** (governed, co-equal R/W). Two orthogonal axes: **AUTHORITY**
(`jurisdictions.authoritative_server_id`, NULL=us — Phase-F-unchanged) and **LEADERSHIP**
(which node writes — a new data-tier axis decided by Patroni, never PHP consensus). A
follower still presents `authoritative_server_id=NULL` → `authorityDisposition()` and
`AuthorityFlipService` need **zero** semantic change; **no Phase G code reads
leadership/cluster state in any authority path** (a grep test fails the build otherwise).

## Decisions locked (operator)

1. **Ballot transport on autonomy flip → RE-WRAP** each per-election KEK
   (`elections.ballot_key_wrapped`, APP_KEY-derived) to the gaining cluster; **fails closed**
   (verify the gaining cluster reproduces the identical certification `record_hash` before
   commit; mismatch → revert); test + constitutional sign-off **merged before code**; never
   touches `ballot_envelopes`.
2. **Co-member admission → GOVERNED** (validated by the authoritative government); mirror
   adoption stays permissionless.
3. **`PeerService::upsertTrustedPeer` extraction → YES** (one shared TOFU-pin method; additive,
   default `relation='sovereign'`).
4. **§9 defaults accepted:** `etcd` DCS; canonical-JSON + instance-key attestation;
   replicated directory feed; mobile on session cookie pre-G-ID; nightly tailnet CI.

## Tracks + build order

- **TRACK A — volunteer mirror mesh (FIRST; Prong 1; no Patroni, no identity):** Harness G0 →
  **cold-sync chunking** (gates corpus moves) → G1 mirror model → G2 join-key → G3
  request/vouch → G3b wizard → G0b deploy script. *Delivers:* download → adopted → read-only
  synced mirror in minutes, CLI or wizard (one shared `MirrorService`).
- **TRACK B — earned autonomy + cohesion (Prong 2 + SSO):** G-ID attestation (parallel) →
  G·co-member (governed admission) → G4 write-routing → G0/G7 Patroni HA → **G5a ballot
  re-wrap (test-first)** → G5 operational seed → G6 the autonomy vote.
- **TRACK C — reach & clients:** G8 transport (tailnet/tor/sneakernet) → G9 directory →
  G10a/b mobile.
- **Critical path:** `cold-sync → G1 → G2`, G-ID in parallel, → `co-member → Patroni → G5a →
  G5 → G6`. Patroni + ballot re-wrap are the long poles; Track A ships far earlier.

## Constitutional + privacy invariants (CI-blocking, every step)

authority ≠ leadership (grep pin) · a mirror is authoritative-for-nothing + writes nothing
(`ConstitutionalEngine::file()` refuses on `isMirror()`) · the privacy boundary
(ballots/locations/credentials never in the sync tail; operational rows only in the encrypted
flip bundle; `BallotSecrecyTest` grep extended) · ballot re-wrap fails closed · co-member
elevation is governed, never unilateral (single adoption path + two-phase flip +
one-authority partial-unique index).

## Verification

Per sub-phase: named constitutional/property/idempotency tests green (live-pg rolled-back).
The multi-instance rig (`cluster:demo` extending `FederationDemoCommand`) scripts the full
lifecycle on localhost (deploy → adopt → **chunked sync** → CLK-06 → activate → **governed
autonomy promotion** → flip → add co-member → kill leader → verify); a CI `Cluster` suite
boots the rig and fails on green-by-skip. Track A bar: a fresh node via `deploy.sh` + wizard
becomes a read-only synced mirror in minutes. Standing demo: `institutions:demo-g`;
`phasesLive` → G.
