# PHASE G — Implementation Plan (Mirror Mesh · Earned Local Autonomy · Reach & Clients)

**Status:** REVIEW ARTIFACT — operator sign-off required before any code is written.
**Base:** Phase F (sovereign peering) COMPLETE on `main` @ `299dcee`. All Phase G work is **additive**.
**Source:** synthesized + de-duplicated from 8 code-grounded design specs (clusters `g-membership`,
`g-adoption`, `g-syncrouting`, `g-identity`, `g-autonomy`, `g-infra`, `g-clients`, `g-testrig`),
reconciled against ground-truth code in this repo.

> This document resolves every contradiction across the 8 specs (migration-ordering collisions,
> two different meanings of "cluster", and an alternate G-numbering proposed by one spec). Where a
> spec conflicts with the orchestrator's four refinements or with the real code, **the refinements
> and the code win** and the resolution is called out inline.

---

## 1. Context — what Phase F already gives us (the reuse surface)

Everything below is reused **unchanged** unless explicitly marked *extend*. File paths are real.

| Capability | Artifact (real path) | Phase G use |
|---|---|---|
| Instance Ed25519 identity, `sign()`/static `verify()` | `app/Services/Federation/InstanceIdentityService.php` | signs attestations (G-ID), cluster handshakes, seed bundles, operational-bundle DEK seal |
| Signed S2S transport, canonical `METHOD\nTARGET\nTS\nsha256(body)` | `app/Services/Federation/FederationClient.php` (`signingString`) | every new S2S call; *extend* with optional SOCKS proxy (G8) |
| Peer signature middleware (`tofu`/`pinned`/`public` modes) | `app/Http/Middleware/VerifyPeerSignature.php` | reused as-is for all new federation routes |
| Peer lifecycle (TOFU pin, guarded transitions) | `app/Services/Federation/PeerService.php`, `app/Models/FederationPeer.php` | mirror/co-member edges reuse `receiveHandshake` pin block; *extend* peer with a `relation` discriminator |
| Signed audit tail + ingest (verify+apply+watermark) | `FederationSyncService::buildAuditTail(int $fromSeq)` (L46), `ingestTail` (L185), `verifyForeignSegment` (L278), `publishCheckpoint` (L122) | **a page is a tail** → chunked sync reuses `ingestTail` byte-for-byte; *extend* `buildAuditTail` with a `limit` |
| Authority disposition (NULL=us) | `FederationSyncService::authorityDisposition()` (L309, reads `jurisdictions.authoritative_server_id`) | **ZERO semantic change** (the load-bearing invariant); predicate extracted to shared `AuthorityResolver` |
| Two-phase authority flip + claim | `app/Services/Federation/AuthorityFlipService.php`, `authority_claims_one_authority_per_jurisdiction` partial-unique index (`2026_07_01_000003`) | **ZERO change**; G6 drives it; anti-split-brain backstop reused |
| Manifest export/sign | `app/Services/Federation/PartitionExportService.php`, `app/Services/MapDataExportService.php` (`runPgDump`) | operational-bundle exporter (G5) reuses pg_dump plumbing |
| Single mutation path | `app/Domain/Engine/ConstitutionalEngine.php` (`file()`), `ResolvesRoles` DI seam | write-forwarding + attested writes resolve an actor, then call `file()` **with no bypass** |
| Derived standing (never stored) | `app/Services/RoleService.php` (`rolesFor`/`derive`) | attestation *snapshots* the derivation (Art. I pin preserved) |
| Residency pipeline (PROTECTED) | `app/Services/ResidencyService.php`, `app/Http/Controllers/Civic/PingController.php`, `app/Models/LocationPing.php` (`'mobile'` already in `SOURCES`) | mobile is just a `source:'mobile'` ping — **no new residency logic** |
| Hash-chained audit | `app/Services/AuditService.php` (`append`, `canonicalJson`, `verifyChain`) | every new edge under a **new module string** |
| Clocks / scheduler | `app/Services/ClockService.php`, `app/Jobs/Federation/FederationHeartbeatJob.php` (CLK-20), `database/seeders/ClockRegistrySeeder.php` | new CLK ids armed for sweeps/follows |
| Live-pg test harness | `tests/Concerns/LivePgConnection.php`, `tests/Concerns/FederationSyncSupport.php`, `tests/Constitutional/BallotSecrecyTest.php` (the grep + rolled-back posture) | every Phase G test |
| Roadmap-as-tests gate | `tests/Constitutional/FuturePhasePlaceholdersTest.php` | extend the existence-array with every Phase G pin filename |
| Demo idiom | `app/Console/Commands/FederationDemoCommand.php` (`institutions:demo-f`) | `cluster:demo` / `institutions:demo-g` extend it |
| Ballot crypto (PROTECTED math) | `elections.ballot_key_wrapped` (text, `2026_06_13_000003`), `BallotCrypto` (`kekFromAppKey`, `wrap/unwrapDataKey`), `BallotBox::decryptForCount` | KEK re-wrap (G5a) **composes** these with **zero change** to `BallotBox`/`BallotCrypto` |

Confirmed ground-truth that corrects the original plan's guesses:
- **`users.home_server_id` already exists** (`2026_06_12_000002`) — the home-authority pointer; not added.
- **The ballot KEK is derived from `APP_KEY`** (`BallotCrypto::kekFromAppKey(config('app.key'))`), domain-separated
  `cga.ballot-key-wrap.v1` — it is **NOT** the Ed25519 federation keypair. This is why a cross-cluster authority
  flip (different `APP_KEY`) makes `ballot_key_wrapped` unreadable without re-wrap (refinement #4).
- **Migration bands `2026_07_*` and `2026_08_*` are TAKEN** (Phase F federation + Phase E judiciary). Several specs
  proposed `2026_08_01_000001` and would collide with shipped Phase E migrations. **All Phase G migrations use the
  `2026_09_*` band onward** (resolution applied throughout §4).

---

## 2. The architecture — three relationships, two axes (state once, honor throughout)

Phase G keeps **THREE relationships strictly distinct**, and introduces **leadership** as a new axis
*orthogonal* to **authority**.

| # | Relationship | Storage | Trust | Authoritative for the subtree? |
|---|---|---|---|---|
| 1 | **External sovereign peer** (Phase F, exists) | `federation_peers` (`relation='sovereign'`) | Ed25519 handshake | No — a *different* cluster |
| 2a | **CLIENT mirror** (NEW, TRACK A) | `cluster_memberships` (`role='mirror'/'host'`) + `federation_peers.relation='host'/'mirror'` | join key OR vouch + server Ed25519 | **No** — a mirror is authoritative for **nothing** |
| 2b | **Cluster CO-MEMBER** (NEW, TRACK B) | `clusters` + `cluster_members` + `cluster_join_requests` | trust-established peer + admit | **Yes, co-equally** — every member presents `authoritative_server_id = NULL` for the subtree |
| 3 | **Client (browser/mobile)** (NEW, TRACK C) | the `directory_entries` lookup + a device key | session today; device attestation (G-ID) later | n/a |

### The two axes (the cardinal Phase G distinction)

```
AUTHORITY   = jurisdictions.authoritative_server_id   (NULL = "our cluster owns it")   — Phase F, UNCHANGED
LEADERSHIP  = which node inside "us" accepts writes right now                          — NEW, decided at the DATA TIER by Patroni
IDENTITY    = server_id + Ed25519 keypair + APP_KEY                                    — SHARED across all nodes of one cluster
```

**THE ZERO-SEMANTIC-CHANGE INVARIANT (must be true structurally, pinned by a grep test):**
A node that is a Patroni *follower* (not the write-leader) still has `authoritative_server_id = NULL`.
Therefore `authorityDisposition()` and `AuthorityFlipService` need **zero** semantic change, and **no Phase G
code introduces any read of leadership/cluster state into any authority-disposition path.** Leadership is a
*mirror* of Patroni's DCS decision (Patroni decides; PHP only reports), never a PHP-consensus decision.

### Naming resolution (the biggest cross-spec contradiction)

Three specs used the names `clusters` / `cluster_members` / `ClusterService` for **different concepts**.
Resolved as follows (this naming is authoritative for the whole plan):

| Concept | Owner spec | Tables | Service |
|---|---|---|---|
| **Mirror membership** (relationship 2a) | `g-adoption` | `cluster_memberships`, `cluster_join_keys`, `cluster_adoption_requests` | `App\Services\Mirror\MirrorService` (renamed from the spec's `ClusterService` to avoid the collision), `MirrorJoinKeyService`, `MirrorBackfillService` |
| **Co-member cluster** (relationship 2b) | `g-membership` | `clusters`, `cluster_members`, `cluster_join_requests` | `App\Services\Cluster\ClusterMembershipService` |
| **Patroni leadership topology** (the leadership axis) | `g-infra` | reuses the `clusters` row (adds leadership columns) + `cluster_nodes` (renamed from the spec's second `cluster_members` to avoid collision) | `App\Services\Cluster\LeaderProbe`, `App\Services\Cluster\ClusterTopologyService` |

So: **one `clusters` table** carries both the co-membership identity (relationship 2b, from `g-membership`)
**and** the leadership-mirror columns (`leader_server_id`, `leader_epoch`, `topology`, `dcs_backend`, …, from
`g-infra`) — they describe the same object (the local R/W group) from two angles. `cluster_members` =
co-member nodes (the membership roster). `cluster_nodes` = the Patroni/PG node inventory (data-tier inventory).
The **mirror** track uses its own `cluster_memberships` table and never touches `clusters`/`cluster_members`.

---

## 3. The four refinements (and how they reshape sequencing)

These **override** the original plan wherever they conflict.

1. **Mirror mesh needs NO Patroni.** A read-only mirror is an independent single-node instance with its own DB
   syncing public records. Patroni HA (G0/G7) is **only** for intra-cluster multi-node R/W on the autonomy track.
   → **TRACK A** (the buildable near-term win) is: *chunked cold-sync → mirror membership (G1) → join-key (G2) →
   request/vouch (G3) → wizard (G3b) → deploy script (G0b)*. **Patroni moves off the critical path** onto TRACK B.

2. **Self-sovereign actor-identity / attestation is its OWN workstream (G-ID).** It gates write-forwarding (G4),
   autonomy writes (G6), and mobile signing (G10b) — and **nothing else**. The read-only mesh needs **none** of it;
   adoption is authenticated by the existing *server* Ed25519 identity + a shared join key. → G-ID is built in
   parallel with TRACK A but on TRACK B; the mesh ships without it.

3. **Chunked/paginated initial sync is a FIRST-CLASS PREREQUISITE.** A fresh mirror pulling the full ~8MB audit
   tail in one body fails on body size (the exact bug that hit the live two-instance demo). Chunked cold-sync
   **gates G2** and everything downstream that moves a corpus. It ships **first**, before any adoption.

4. **The ballot-key re-wrap (G5a) is the single most dangerous, constitutionally-sensitive piece.** It gets its
   own design note + a **required constitutional-test pass merged BEFORE its code**, and uses the **real**
   ballot-KEK schema (`elections.ballot_key_wrapped`, APP_KEY-derived KEK), not the original plan's guess. A
   re-wrap that silently corrupts a KEK makes every historical election in the subtree permanently uncountable on
   the new owner — so it **fails closed** (verify-reproduced-count before commit; mismatch → revert).

---

## 4. Schema at a glance (every new table + additive column, by sub-phase)

All migrations: additive only; UUID PK with `ALTER COLUMN id SET DEFAULT gen_random_uuid()` *after* `Schema::create`;
`timestampsTz()` + `softDeletesTz()`; every enum is a `string` column + a named `CHECK` via raw `DB::statement`;
partial-unique indexes via raw SQL `WHERE deleted_at IS NULL`. **Never edit a protected migration**
(`jurisdictions`, `ballots`, `audit_log`) — additive columns go on `instance_settings`/`federation_peers` via
`Schema::table`. **Band `2026_09_*` onward** (08 is Phase E).

### TRACK A — volunteer mirror mesh

| Migration (`2026_09_…`) | Table / change | Key columns (terse) |
|---|---|---|
| `09_01_000001` | `sync_cursors` (cold-sync) | `peer_id`, `direction CHECK(inbound\|outbound)`, `mode CHECK(cold\|incremental)`, `anchor_seq`, `from_seq`, `next_from_seq`, `page_size`, `pages_applied`, `records_applied`, `status CHECK(open\|paused\|complete\|aborted)`, `last_page_hash char(64)`, `detail jsonb`; **partial-unique** one open cold cursor per (peer,direction) |
| `09_01_000002` | `cluster_memberships` (mirror) | `peer_id`, `role CHECK(mirror\|host)`, `state CHECK(requested\|admitted\|syncing\|live\|suspended\|departed\|rejected)`, `admission_method CHECK(join_key\|request)`, `scope_jurisdiction_id`, `backfill_cursor_seq`, `backfill_target_seq`, `backfilled_at`; **partial-unique** one active mirror; one row per (peer,role) |
| `09_01_000003` | `cluster_join_keys` (host) | `handle`, `key_hash` (Argon2id, plaintext shown once), `scope_jurisdiction_id`, `max_uses`, `use_count`, `expires_at`, `revoked_at`, `created_by_user_id`; partial-unique handle |
| `09_01_000004` | `cluster_adoption_requests` (host queue + nonce ledger) | `applicant_server_id`, `applicant_public_key`, `nonce`, `admission_method`, `status CHECK(pending\|admitted\|rejected\|expired)`, `join_key_handle`, `cluster_membership_id`; **partial-unique** `(applicant_server_id, nonce)` = anti-replay backstop |
| `09_01_000005` | `federation_peers` *+ column* | `relation string(16) default 'sovereign' CHECK(sovereign\|host\|mirror)` (all existing rows = sovereign) |
| `09_01_000006` | `instance_settings` *+ columns* | `mirror_of_server_id` (set ⇒ this instance is a read-only mirror), `mirror_adopted_at` |

### TRACK B — earned local autonomy (incl. G-ID identity)

| Migration (`2026_09_…`) | Table / change | Key columns (terse) |
|---|---|---|
| `09_02_000001` | `clusters` (co-member + leadership) | `cluster_uuid` (defaults to our `server_id`), `root_jurisdiction_id` (NULL for non-authority), `kind CHECK(mirror\|authority)`, `status CHECK(forming\|active\|suspended\|dissolved)`, **leadership:** `leader_server_id`, `leader_epoch bigint default 0` (monotonic fence), `leader_observed_at`, `topology CHECK(single\|ha_pair\|multi_node)`, `dcs_backend CHECK(etcd\|consul\|none)`, `patroni_rest_url`, `last_probe_ok`; `authority_claim_id` FK; **partial-unique** `cluster_uuid`; **partial-unique** one `authority` cluster per `root_jurisdiction_id` (mesh-mirror anti-split-brain twin) |
| `09_02_000002` | `cluster_members` (co-member roster) | `cluster_id`, `server_id`, `peer_id`, `is_self bool`, `role CHECK(leader\|follower\|replica\|witness)`, `status CHECK(invited\|joining\|active\|suspended\|departed)`, `public_key`, `member_epoch`; partial-unique one server per cluster; **one `is_self` per cluster** |
| `09_02_000003` | `cluster_join_requests` (co-member admission) | `cluster_uuid`, `requester_server_id`, `requester_public_key`, `desired_role`, `status CHECK(pending\|admitted\|rejected\|withdrawn\|expired)`, `member_id`; partial-unique one pending per requester |
| `09_02_000004` | `cluster_nodes` (Patroni/PG inventory) | `cluster_id`, `member_id` (stable per node, stored node-local), `member_name`, `role CHECK(primary\|replica\|app_only\|unknown)`, `pg_state`, `app_url`, `is_scheduler_leader`, `last_seen_at`; partial-unique `(cluster_id, member_id)` |
| `09_02_000005` | `instance_settings` *+ column* | `home_cluster_id` (the cluster this instance co-members; NULL = standalone) |
| `09_02_000006` | proposal-kind CHECK *widen* | add `local_autonomy_adoption`, `local_autonomy_revocation`, `local_autonomy_promotion` to `chamber_vote_proposals_kind_check` (drop-and-re-add, per `2026_07_02_000007`) |
| `09_02_000007` | `forwarded_writes` (G4 idempotency ledger) | `idempotency_key`, `direction CHECK(outbound\|inbound)`, `origin_server_id`, `target_server_id`, `jurisdiction_id`, `form_id`, `actor_assertion_kind CHECK(system\|server_vouched\|citizen_signed)`, `payload_hash char(64)`, `status CHECK(pending\|applied\|rejected\|duplicate\|unauthorized)`, `result_audit_seq`; **partial-unique** `idempotency_key WHERE direction='inbound'` = exactly-once |
| `09_03_000001` | `actor_devices` (G-ID) | `user_id`, `public_key` (Ed25519, private half NEVER stored), `label`, `device_kind CHECK(browser\|mobile\|hardware)`, `status CHECK(active\|revoked\|superseded)`, `enrolled_via`, `last_used_at`, `revoked_reason`; **partial-unique** active pubkey |
| `09_03_000002` | `standing_attestations` (G-ID) | `user_id`, `device_id`, `device_public_key`, `subject_jurisdiction_id`, `scope_jurisdiction_ids jsonb`, `roles jsonb` (snapshot of `RoleService::rolesFor`), `issuer_server_id`, `issuer_public_key`, `issued_at`, `expires_at` (hard TTL ceiling), `nonce`, `signature`, `status CHECK(active\|revoked\|expired\|superseded)`, `audit_seq`, `source_server_id` (NULL=ours) |
| `09_03_000003` | `attestation_revocations` (G-ID CRL) | `attestation_id`, `device_id`, `user_id`, `reason`, `issuer_server_id`, `revoked_at`, `signature`, `audit_seq`, `source_server_id`; append-mostly, converges via sync tail |
| `09_03_000004` | `instance_settings` *+ column* | `attestation_authority_enabled bool default false` (gates issue/honor, like `federation_enabled`) |
| `09_04_000001` | `operational_partition_exports` (G5) | `partition_export_id` FK, `jurisdiction_id`, `direction`, `peer_id`, `checkpoint_audit_seq` (= manifest's), `included_tables jsonb`, `archive_sha256`, `plaintext_sha256`, `enc_algo`, `key_wrap jsonb` (DEK **sealed** to gaining X25519 key), `signature`, `status CHECK(prepared\|encrypted\|transmitted\|received\|verified\|restored\|failed\|reverted)` |
| `09_04_000002` | `election_ballot_key_rewraps` (G5a ledger) | `operational_partition_export_id`, `election_id`, `jurisdiction_id`, `had_sealed_ballots`, `count_verified`, `reproduced_record_hash`, `expected_record_hash`, `status CHECK(pending\|rewrapped\|verified\|mismatch\|skipped)`; **partial-unique** `(bundle, election)` — **NO key material in this table** |
| `09_04_000003` | proposal-kind CHECK *widen* | confirm `local_autonomy_promotion` present (idempotent with `09_02_000006`) |

### TRACK C — reach & clients

| Migration (`2026_09_…`) | Table / change | Key columns (terse) |
|---|---|---|
| `09_05_000001` | `federation_transports` (G8) | `peer_id` (NULL=self-advert), `channel CHECK(clearnet\|tailnet\|tor\|sneakernet)`, `endpoint`, `socks_proxy`, `preference int`, `health CHECK(unknown\|healthy\|degraded\|unreachable)`, `last_probe_at`; partial-unique `(peer_id, channel)` |
| `09_05_000002` | `instance_settings` *+ columns* | `tailnet_address`, `onion_address`, `preauth_key_encrypted` (Crypt + `$hidden`) |
| `09_05_000003` | `directory_entries` (G9) | `jurisdiction_id`, `cluster_server_id`, `role CHECK(authoritative\|mirror)`, `endpoints jsonb` (`[{channel, base_url, chunk_capable}]`), `geo_lat/lng`, `geom geometry(Point,4326)`, `health`, `latency_ms`, `source_server_id` (NULL=ours), `signature`; partial-unique `(jurisdiction_id, cluster_server_id, role)` |
| `09_05_000004` | `push_subscriptions` (G10) | `user_id`, `platform CHECK(ios\|android\|web)`, `token` (Crypt + `$hidden`), `jurisdiction_id`, `last_seen_at`; partial-unique `(user_id, token)` |

### Harness (cross-cutting)

| Migration (`2026_09_…`) | Table | Key columns (terse) |
|---|---|---|
| `09_06_000001` | `harness_runs` | `kind CHECK(cluster_demo\|chaos\|e2e)`, `scenario`, `profile CHECK(localhost\|tailnet)`, `node_count`, `status CHECK(running\|passed\|failed\|torn_down)`, `demo_tag`, `manifest jsonb` |
| `09_06_000002` | `chaos_faults` | `harness_run_id`, `fault CHECK(...)`, `target_node`, `expected_invariant`, `observed_ok`, `audit_seq`; **append-only** (immutable + no-truncate triggers, like `sync_log`) |

> **No new column on `jurisdictions`** anywhere in Phase G. The Patroni leader-URL advert (`g-infra`) reuses the
> existing `authoritative_server_url`, rewritten **only** `WHERE authoritative_server_id IS NULL` — never touching
> peer-owned rows. This keeps the AUTHORITY/LEADERSHIP boundary clean.

New `AuditService` module strings (one per workstream): `cluster` (co-member + leadership), `mirror`
(adoption), `federation_cold_sync`, `federation_write_router`, `actor_identity`, `federation_operational`,
`directory`, `federation.transport`, `harness`, `cluster_demo`.

---

## 5. Workstreams in dependency order

For each sub-phase: **goal** · **new artifacts** · **reuses/extends** · **tests** · **deps** · **risk + guardrail**.

---

### TRACK A — Volunteer mirror mesh (the near-term win; NO Patroni, NO actor-identity)

#### A·cold-sync — Chunked/paginated cold sync *(refinement #3; gates everything)*
- **Goal:** a fresh mirror pulls the full corpus in bounded, resumable, signed pages — never one ~8MB body.
- **New:** `app/Services/Federation/ColdSyncService.php` (`pull`, `pullOnePage`); `app/Models/SyncCursor.php`;
  `app/Console/Commands/FederationColdSyncCommand.php` (`federation:cold-sync {peer} {--pages=}`); migration
  `sync_cursors`.
- **Reuses/extends:** *extend* `FederationSyncService::buildAuditTail(int $fromSeq, int $limit = 0)` — `limit=0`
  is byte-identical to Phase F (no F-test churn); add `buildAuditPage(fromSeq, toSeq, pageSize)` beside it (a page
  IS a tail: its `head_hash` = last entry hash, so `verifyForeignSegment`/`ingestTail` work unchanged). *Extend*
  `SyncController::auditTail` with server-capped `to_seq`/`page_size` query params. **Reuse `ingestTail` 100%.**
  *Extend* `FederationHeartbeatJob` (CLK-20) to drain an open cold cursor `N` pages/tick (self-draining backfill).
- **Tests:** `tests/Feature/ColdSyncChunkingTest` (M entries at page K ⇒ ceil(M/K) pages; union == single-shot
  tail; body < cap); `tests/Constitutional/ColdSyncContinuityTest` (**the new invariant:** page N+1's
  `entries[0].prev_hash` must equal cursor `last_page_hash`; a spliced page aborts the cursor, applies nothing);
  `ColdSyncIdempotencyTest`; `ColdSyncResumeAfterCrashTest`; `ColdSyncWatermarkReconcileTest`.
- **Deps:** Phase F only.
- **Risk + guardrail:** *forged page spliced between good pages* → cross-page continuity gate (`last_page_hash`)
  + abort + rejected audit edge, pinned by `ColdSyncContinuityTest`. *Moving head mid-pull* → cursor freezes
  `anchor_seq`; pages walk to the frozen anchor. *OOM via giant page request* → producer caps `page_size` at
  `federation_sync_page_max`. **Cold sync is PULL-ONLY** so it never round-trips the body-mutating inbound
  middleware (the `SyncController::receive` raw-`getContent()` concern is moot on GET).

#### G1 — Mirror membership model
- **Goal:** make the **mirror** relationship (2a) a first-class object distinct from sovereign peer and authority.
- **New:** `app/Services/Mirror/MirrorService.php` (the shared service for CLI *and* browser: `joinHost`,
  `acceptAdmission`, `leave`, host-side `admitViaJoinKey`, `requestAdoption`, `approveRequest`, `rejectRequest`);
  `app/Models/ClusterMembership.php`; migrations `cluster_memberships`, `federation_peers.relation`,
  `instance_settings.mirror_of_server_id`.
- **Reuses/extends:** the `PeerService::receiveHandshake` TOFU-pin block (consider extracting
  `PeerService::upsertTrustedPeer(identity, url, relation)` — a 1-method additive refactor, default
  `relation='sovereign'` so Phase F is untouched). Adds `FederationPeer::RELATION_*` constants + `clusterMembership()`
  relation. `InstanceSettings::isMirror()` helper.
- **Tests:** covered by G2/G3 feature tests + `MirrorIsAuthoritativeForNothingTest` (TRACK-wide, below).
- **Deps:** cold-sync (a mirror's backfill is a cold pull).
- **Risk + guardrail:** *mirror claims authority* → mirror NEVER sets `authoritative_server_id=NULL`; mirrored
  records keep `source_server_id=host`; pinned by `MirrorIsAuthoritativeForNothingTest`.

#### G2 — Join-key adoption *(host hands a secret; mirror admitted immediately)*
- **Goal:** one-step mirror onboarding via a shared join key.
- **New:** `app/Services/Mirror/MirrorJoinKeyService.php` (Argon2id `mint`/`verify`/`consume`-`FOR UPDATE`/`revoke`,
  plaintext shown **once**, only the `handle` ever audited); `app/Services/Mirror/MirrorBackfillService.php`
  (`pullChunk`/`drain` — wraps `ColdSyncService`/`ingestTail`); `app/Http/Controllers/Federation/AdoptionController.php`
  (`POST /api/federation/adopt`, `tofu`); `app/Jobs/Mirror/IngestMirrorBackfillJob.php`;
  `cluster:join {host_url} {--key=} {--wait}`, `cluster:keys:{mint,list,revoke}`, `cluster:leave`; migrations
  `cluster_join_keys`, `cluster_adoption_requests`.
- **Reuses/extends:** `VerifyPeerSignature:tofu` (a would-be mirror has never handshaked); `FederationClient::post`;
  `ConstitutionalEngine::file()` **mirror write-guard** (§ below). The **300s replay window** + the
  `(server_id,nonce)` partial-unique index together defeat replay even in-window.
- **Tests:** `ClusterJoinKeyAdoptionTest` (admit; wrong/expired/revoked/exhausted ⇒ 403; self ⇒ refused);
  `ClusterAdoptionReplayTest` (replayed body ⇒ 409); `MirrorBackfillChunkedTest` (refinement #3 pin: multi-chunk
  backfill, monotonic cursor, idempotent re-run, mid-drain resume).
- **Deps:** cold-sync, G1.
- **Risk + guardrail:** *join-key leak/brute force* → Argon2id hash only, 32-byte CSPRNG, `max_uses`/`expires_at`/
  `revoke`, constant-time check. *A mirror mutates local state* → `ConstitutionalEngine::file()` refuses on a
  mirror (`InstanceSettings::isMirror()`) with a `ConstitutionalViolation` + a chained `rejected` edge — the write
  surface is "indistinguishable from absent."

#### G3 — Request / vouch adoption *(keyless; host operator reviews a queue)*
- **Goal:** mirror requests adoption with no key; host operator approves/rejects.
- **New:** `MirrorService::{requestAdoption, approveRequest, rejectRequest, pendingRequests}`;
  `cluster:request-adoption`, `cluster:requests`, `cluster:approve`, `cluster:reject`; console review props on the
  existing `Jurisdictions/Federation` page (operator-gated web routes).
- **Reuses/extends:** **pull-based finalization** (NAT-safe, no inbound endpoint on the mirror): the mirror
  re-`/adopt` polls with a fresh nonce; once approved, the host returns `200 {admitted,…}` and the mirror finalizes
  via `acceptAdmission`.
- **Tests:** `ClusterRequestVouchTest` (202 pending → approve → admitted; reject path); `ClusterAdoptionChaosTest`
  (concurrent `/adopt` on a 1-use key; double-approve idempotent; crash mid-backfill resumes; host head advances
  during backfill).
- **Deps:** G2.
- **Risk + guardrail:** *double approval* → `approveRequest` idempotent on request id; one-active-mirror
  partial-unique index.

#### G3b — Join-cluster setup wizard
- **Goal:** the browser one-step onboarding, sharing the **same** `MirrorService` path as the CLI.
- **New:** `app/Http/Controllers/JoinClusterController.php` (`show`/`join`/`progress`, operator-gated);
  `resources/js/Pages/Setup/JoinCluster.vue` (form → pending → backfill progress bar → "read-only mirror of «host»");
  `resources/js/Components/MirrorBanner.vue` (mounted in `AppLayout.vue` next to `SchemaUpdateBanner`); web routes
  `/setup/join-cluster`, `/api/setup/join-cluster[/progress]`.
- **Reuses/extends:** progress UI modeled on `Setup/Step2_MapData.vue` (zero new CSS). One `SetupController` edit:
  when `isMirror()`, skip the constitution-authoring steps (a mirror has no constitution of its own).
- **Tests:** `JoinClusterWizardSmokeTest` (wizard and CLI go through the **same** service ⇒ one membership row, not
  two; mirror banner prop set).
- **Deps:** G2/G3.
- **Risk + guardrail:** *CLI/browser drift* → both call `MirrorService::joinHost`; pinned by the smoke test.

#### G0b — One-command deploy script *(operator onboarding; also dogfooded by the rig)*
- **Goal:** reproducible, idempotent, key-safe, clone-safe single-node bring-up.
- **New:** `scripts/deploy/deploy.sh` + `scripts/deploy/deploy.ps1` (Windows-first dev rig). Honors the already-
  parameterized `CONTAINER_PREFIX`/`*_HOST_PORT` env. Steps: pull@tag → **APP_KEY rule** (generate + LOUD warning;
  hard-refuse on `--join-hub` with a freshly-minted key) → compose up → wait `/up` (poll, not sleep) → `migrate
  --force` → `federation:init` (`--rotate` iff flagged) → **clone-identity safety** (`assertNoIdentityCollision`)
  → optional `cluster:join`/demo seed → print the setup URL.
- **Reuses/extends:** `FederationInitCommand` (idempotent identity mint). *Extend* `bootstrap/app.php`/`routes/web.php`
  with a richer `/up` (`HealthController`: 200 only when PDO ok AND `last_probe_ok`).
- **Tests:** `DeployIdempotencyTest`, `CloneIdentityCollisionTest`, `SharedAppKeyGuardTest`,
  `HaProxyHealthRoutingContractTest`.
- **Deps:** none (TRACK A) for single-node; the `--ha`/`--join-hub` paths depend on TRACK B's `clusters` bootstrap.
- **Risk + guardrail:** *un-rotated clone shares `server_id`* → `assertNoIdentityCollision` (checks
  `federation_peers`, never `cluster_members`) + `--rotate`. *Per-node distinct APP_KEY* → loud warning +
  hard-refuse + `SharedAppKeyGuardTest`.

---

### TRACK B — Earned local autonomy (Patroni + identity + the autonomy vote)

#### G-ID — Self-sovereign actor-identity / attestation *(refinement #2; gates G4/G6/G10b; build in parallel with TRACK A)*
- **Goal:** a portable device signing key + a short-lived, revocable, server-signed attestation of *derived*
  standing, so a node that does NOT hold a person's residency facts can authorize a write they signed — **without**
  replicating credentials/ballots/locations across the privacy boundary.
- **New:** `app/Services/Identity/ActorIdentityService.php` (enroll device, `actionSigningString`, verify action
  sig); `app/Services/Identity/AttestationService.php` (the CA: `issue`, `attestationCanonical`, `verifyAttestation`,
  `revoke` — **signs with the existing INSTANCE Ed25519 key, no second PKI**); `app/Services/Identity/AttestationGate.php`
  (`implements ResolvesRoles` — returns **attested** roles on `attested`-mode requests, else falls through to
  `RoleService`); `app/Http/Middleware/VerifyActorAttestation.php` (alias `actor.signed`, the person-level sibling
  of `VerifyPeerSignature`); controllers `DeviceEnrollmentController`, `AttestationController`,
  `Federation/AttestationSyncController` (`GET /attestation/{id}`, `GET /attestation-revocations`); jobs
  `ExpireStandingAttestationsJob` (CLK), `PropagateAttestationRevocationJob`; commands `actor:enroll`,
  `actor:attest`, `actor-identity:demo`; migrations `actor_devices`, `standing_attestations`,
  `attestation_revocations`, `instance_settings.attestation_authority_enabled`.
- **Reuses/extends:** `InstanceIdentityService::sign/verify` (same key signs attestations); `AuditService::canonicalJson`
  (so issuer + any verifier hash identically); `RoleService::rolesFor` (**snapshotted**, never short-circuited for
  local users — preserves the Art. I "never stored" pin); `FederationPeer.public_key` (already pinned + converged) is
  the issuer-verification key; the `public_records.source_server_id` + DO-NOTHING idempotency convention mirrors
  attestations/revocations. *Extend* `HandleInertiaRequests` `share()` with an `identity` prop.
  **Migration path:** ship dark (`attestation_authority_enabled=false`, `ResolvesRoles`=`RoleService`) → dual-stack
  (bind `AttestationGate` wrapping `RoleService`; zero behavior change for local session users) → forwarded writes
  (with G4). **No session-auth code is modified or removed at any step.**
- **Tests:** `tests/Constitutional/AttestationIntegrityTest` (sig verifies; any field mutation breaks it;
  foreign issuer fails; **Art. I pin:** `roles` used ONLY on `attested` requests, `RoleService` never
  short-circuited for local users; **privacy pin:** the three tables carry no credential/location/ballot column and
  never enter the sync tail); `ActorEnrollmentTest`; `AttestationRoundTripTest` (two-instance: issue → verify on a
  peer that pinned the issuer; expired/revoked/untrusted-issuer all refused); `AttestationRevocationConvergenceTest`;
  `AttestationChainIntegrityTest`.
- **Deps:** Phase F only (it IS the dependency for G4/G6/G10b). Independent of cold-sync/G1/G2.
- **Risk + guardrail:** *stale standing* → short hard TTL (24h ceiling) + fast CRL; relocation emits a revocation.
  *issuer-trust-from-body forgery* → issuer pubkey resolved from a **pinned peer**, body's `issuer_public_key` is a
  hint cross-checked against the pin (mismatch ⇒ hard 401, audited). *lost device* → recovery = re-verify at the
  home jurisdiction + enroll a fresh key; **no key escrow** (standing lives in `residency_confirmations`, not the
  key). *self-issuance* → `issue()` refuses if `user.home_server_id` points at a peer (only the home authority attests).

#### G4 — Write-routing (WriteRouter) *(HARD dep on G-ID for citizen writes; ships system-scoped now)*
- **Goal:** reads serve locally; a write for jurisdiction `J` we don't own is **forwarded** to
  `J.authoritative_server_url` (the leader) with an idempotency key; the leader executes via its normal
  `ConstitutionalEngine::file()` — **no bypass**.
- **New:** `app/Services/Federation/WriteRouterService.php` (`routeFor`, `isLocalAuthority`, `forward`,
  `executeForwarded`); `app/Services/Federation/AuthorityResolver.php` (the shared authority predicate extracted from
  `authorityDisposition` — single source of truth, both call it); `app/Domain/Engine/Contracts/ResolvesForwardedActor.php`
  + `SystemOnlyForwardedActor` (pre-identity binding: accepts only `system` forwards, throws
  `actor_attestation_required` for citizen forwards) — swapped for `AttestedForwardedActor` (delivered by G-ID) with
  **one DI binding change, no WriteRouter edit**; `app/Http/Controllers/Federation/WriteForwardController.php`
  (`POST /api/federation/write`, raw-`getContent()` parse); `app/Http/Middleware/RouteWriteToAuthority.php` (alias
  `write.route`); migration `forwarded_writes`.
- **Reuses/extends:** `FederationClient::post` (forward), `VerifyPeerSignature:pinned` (proves the *server*),
  `ConstitutionalEngine::file` (no bypass), the `ResolvesRoles` DI pattern (mirrored by `ResolvesForwardedActor`).
  *Refactor* `authorityDisposition` to delegate to `AuthorityResolver` (behavior-preserving; `AuthoritativeWinsConflictTest`
  pins it unchanged).
- **Tests:** `WriteRoutingDecisionTest`; `WriteForwardIdempotencyTest` (same key ⇒ `file()` once); 
  `tests/Constitutional/WriteForwardEnginePathTest` (a forwarded hardened-rule violation is rejected on the leader,
  no special path); `tests/Constitutional/WriteForwardActorAttestationTest` (pre-identity: citizen forward refused
  `actor_attestation_required`; system forward applies); `WriteRouterLocalUnchangedTest`; `ForwardedWriteSignatureTest`.
- **Deps:** **HARD on G-ID** for citizen writes (inert until `AttestedForwardedActor` lands; system-scoped ships now).
  Architectural contract on TRACK B's Patroni: forwards to `authoritative_server_url`, which the cluster's ingress
  resolves to the current primary — the WriteRouter is **Patroni-agnostic** (routes on AUTHORITY only).
- **Risk + guardrail:** *forging a citizen* → server sig proves the mirror; `ResolvesForwardedActor` proves the
  citizen; two distinct checks. *double-executed forward* → `forwarded_writes_idem_inbound_unique` + pre-`file()`
  idempotency check.

#### G·co-member (G1-autonomy) — Co-member cluster model
- **Goal:** make the **co-member** relationship (2b) first-class: co-equal R/W members all presenting
  `authoritative_server_id=NULL`, with **leadership a separate, Patroni-mirrored axis**.
- **New:** `app/Services/Cluster/ClusterMembershipService.php` (`form`, `admit`, `suspend`, `depart`,
  `reconcileLeadership` — **the ONLY writer of `leader_server_id`/`leader_epoch`**, `isWriteLeader`, guarded
  `transitionMember`); `app/Models/{Cluster,ClusterMember,ClusterJoinRequest}.php`;
  `app/Http/Controllers/Federation/ClusterController.php` (`/cluster/join`, `/cluster/admit/{req}`,
  `/cluster/leadership`, all `pinned`); `cluster:form`, `cluster:admit`, `cluster:status`; migrations `clusters`
  (incl. leadership columns), `cluster_members`, `cluster_join_requests`, `instance_settings.home_cluster_id`, and the
  `local_autonomy_*` proposal-kind widening.
- **Reuses/extends:** `InstanceIdentityService`, `AuditService` (module `cluster`), `VerifyPeerSignature:pinned`
  (co-members are trusted peers first), `FederationClient`. `clusters.authority_claim_id` FKs an existing
  `authority_claims` row — the cluster is the membership *around* a claim, never a replacement.
- **Tests:** `tests/Constitutional/ClusterAuthoritySeparationTest` (**the load-bearing pin:** a follower leaves
  every `authoritative_server_id=NULL`; `leader_epoch` monotonic + fences; **grep pin** that `FederationSyncService`
  and `AuthorityFlipService` contain NO reference to `clusters`/`cluster_members`/`leader_server_id`/`is_self`);
  `ClusterMembershipLiveTest` (one authority cluster per jurisdiction; mirror clusters exempt; one `is_self`);
  `ClusterServiceTest`; `ClusterAuditDisciplineTest`; `ClusterLeadershipChaosTest` (fuzz `reconcileLeadership`:
  epoch never regresses; ≤1 leader at settle; authority columns never move).
- **Deps:** Patroni data-tier (supplies the observed-leader read into `reconcileLeadership`; ships a single-node
  "we are our own leader" stub until then).
- **Risk + guardrail:** *leadership mutates authority (the cardinal sin)* → `leader_server_id` written by exactly
  one method; the grep pin fails the build if any authority-path file references cluster state. *two authority
  clusters claim one subtree* → `clusters_one_authority_cluster_per_jurisdiction` partial-unique index. *stale leader
  comeback* → `leader_epoch` monotonic; `reconcileLeadership` ignores an observation with epoch < current.

#### G0 / G7 — Patroni HA clone pair → multi-node topology *(refinement #1: Patroni lives HERE, not first)*
- **Goal:** the DATA-TIER leadership layer — Patroni decides which node accepts writes; `authoritative_server_id`
  is untouched; a cluster looks like one instance to any peer.
- **New (infra):** `docker-compose.ha.yml` (etcd DCS + `patroni1/2[/3]` from `docker/patroni/Dockerfile` mirroring
  the tuned PG params + streaming replication; `haproxy` routing writes to the Patroni primary via `/primary`
  health, app round-robin via `/up`; shared `redis` for session/cache/queue + scheduler lock). **New (app):**
  `app/Services/Cluster/LeaderProbe.php` (`probe`/`reconcile`/`advertiseLeaderUrl`/`tryAcquireSchedulerLock`),
  `LeaderSnapshot`, `ClusterTopologyService` (`bootstrapLocalCluster`, `registerSelf`, `joinHub`,
  `assertNoIdentityCollision`); `app/Models/ClusterNode.php`; `cluster:probe`, `cluster:join` (hub),
  `cluster:status`; `app/Http/Middleware/RequireWritableNode.php` (alias `cluster.writable`, belt-and-suspenders
  409 during failover); `LeaderProbeJob`; migration `cluster_nodes`.
- **Reuses/extends:** **THE load-bearing edit** — wrap the four consequence sweeps (`EvaluateClocksJob`,
  `ApprovalStandingsRollupJob`, `DepartmentReportSweepJob`, `EvaluateCoDeterminationJob`) in `routes/console.php`
  with `->skip(fn () => ! app(LeaderProbe::class)->isSchedulerLeader())` — the two-schedulers-double-fire fix
  (Redis `SET NX PX` lock that follows the Patroni primary). `cluster:probe` is **not** gated (every node probes its
  own role). `ClockService::fire()`'s `STATE_ARMED` `lockForUpdate()` guard is the double-fire backstop.
  `LeaderProbe::advertiseLeaderUrl` rewrites `authoritative_server_url` **only** `WHERE authoritative_server_id IS NULL`.
  **Shared APP_KEY rule:** all cluster nodes share one APP_KEY (federation private key + ballot KEK + sessions all
  derive from it) → single login across nodes with zero new code (Redis session).
- **Tests:** `tests/Constitutional/ClusterLeadershipDoesNotTouchAuthorityTest`,
  `tests/Constitutional/FollowerPresentsNullAuthorityTest`; `SingleSchedulerLeaderTest`,
  `SchedulerLockFollowsPrimaryTest`, `LeaderProbeIdempotentTest`, `LeaderProbeReconcileChaosTest` (dual-primary /
  no-primary / vanished-member ⇒ **fail closed:** nobody dispatches).
- **Deps:** G0b (deploy), G·co-member. HA-pair clone uses Patroni `pg_basebackup` (storage-layer) → **no chunked-sync
  dependency**; multi-node HTTP join is gated behind cold-sync.
- **Risk + guardrail:** *two scheduler leaders double-fire* → single Redis lock + lock-follows-primary + the
  `STATE_ARMED` no-op backstop. *dual-primary (etcd partition)* → Patroni DCS fences the loser; LeaderProbe fails
  closed on ambiguous output. *Patroni REST exposed to peers* → internal-only network; `SharedAppKeyGuardTest` greps
  that no cluster table enters any federation payload.

#### G5 — Operational-data seed *(the encrypted private-row bundle that rides an autonomy flip)*
- **Goal:** move the subtree's private operational rows (identity, drafts, sessions, **sealed ballots**) to the
  gaining cluster, encrypted, anchored to the **same** checkpoint as the manifest — these rows **NEVER** ride the
  routine sync tail.
- **New:** `app/Services/Federation/OperationalPartitionExportService.php` (`exportOperational`,
  `restoreOperational`, `runScopedPgDump`; `OPERATIONAL_TABLES` allowlist + per-table `SCOPE_JOINS`);
  `app/Models/OperationalPartitionExport.php`; `app/Jobs/ExportOperationalBundleJob.php` (heavy dump off-request);
  `FlipController::receiveOperational` + `POST /api/federation/flip/operational`; `federation:bundle:transmit`;
  migration `operational_partition_exports`.
- **Reuses/extends:** `PartitionExportService::descendants/buildManifest`, `FederationSyncService::publishCheckpoint`
  (the shared anchor — `checkpoint_audit_seq` passed in verbatim), `MapDataExportService::runPgDump` plumbing,
  `AuthorityFlipService::{exportFlip,importFlip,revert}` (**zero change**), Ed25519→X25519
  (`sodium_crypto_sign_ed25519_pk_to_curve25519` + `crypto_box_seal`) to seal a random bundle DEK to the gaining
  cluster. **`FederationSyncService::buildAuditTail` UNCHANGED** (still audit + public_records only).
- **Tests:** `tests/Constitutional/OperationalDataNeverInTailTest` (grep + runtime + allowlist-disjointness with
  `MapDataExportService::TABLES`); `OperationalBundleRoundTripTest` (idempotent export/restore keyed on
  `archive_sha256`); `OperationalExportCheckpointAnchorTest` (bundle `checkpoint_audit_seq` == manifest's);
  `OperationalBundleOversizeChunkTest` (chunked transmit, no body-size failure).
- **Deps:** cold-sync (chunked transmit of the large bundle), G5a (re-wrap runs **inside** the export), TRACK B
  co-member/Patroni (the gaining node is a co-member with a pinned X25519/Ed25519 identity).
- **Risk + guardrail:** *operational rows leak into the routine tail* → `buildAuditTail` unchanged +
  `OperationalDataNeverInTailTest`. *bundle DEK exposed* → random per-bundle DEK sealed via `crypto_box_seal`
  (anonymous sender); signed manifest binds `archive_sha256`.

#### G5a — Ballot-key re-wrap *(refinement #4: design note + constitutional pass merged BEFORE code)*
- **Goal:** make every historical sealed election in the subtree **countable on the gaining cluster** (which has a
  different APP_KEY) by re-wrapping each per-election KEK — **never** exposing `k_e` plaintext on the wire, **never**
  touching voter-identity (`ballot_envelopes`).
- **New:** `app/Services/Federation/BallotKeyRewrapService.php` (`rewrapForSubtree`, `rewrapOne`,
  `verifyReproducedCounts`); `app/Models/ElectionBallotKeyRewrap.php`; migration `election_ballot_key_rewraps`
  (audit ledger only — **NO key material**).
- **Reuses/extends:** `BallotCrypto::{kekFromAppKey,wrap/unwrapDataKey}` and `BallotBox::decryptForCount` — **ZERO
  change** to either. The re-wrap is a **two-hop, never-plaintext-at-rest** operation: losing app-key KEK → bundle
  DEK KEK (inside the encrypted bundle) → gaining app-key KEK (on restore). `k_e` exists only transiently in memory
  on each side. `verifyReproducedCounts` re-runs `decryptForCount` gaining-side and asserts the recomputed
  certification `record_hash` equals the manifest's expected hash; **a mismatch HALTS the flip and triggers `revert`.**
- **Tests (must be GREEN + constitutional sign-off BEFORE the G5 bundle code):**
  `tests/Constitutional/BallotKeyRewrapPreservesSecrecyTest` (re-wrap round-trips; touches NO envelope/user data
  (grep); not a rogue `ballots`/`ballot_envelopes` writer; **count reproduction:** commit under app-key A, re-wrap
  to B, `decryptForCount` under B yields identical canonical rankings + identical `record_hash`);
  `AutonomyRewrapTamperChaosTest` (corrupt one byte / wrong gaining key ⇒ `verifyReproducedCounts` marks
  `mismatch` ⇒ flip not committed ⇒ `revert` — **fails closed**).
- **Deps:** independently testable against `BallotBox`/`BallotCrypto` with **no federation stack** (build first
  within TRACK B). **CONSTITUTIONAL REVIEW REQUIRED** (Protected-Files-adjacent — `BallotCrypto`/`BallotBox` are
  *not* edited, which keeps the change reviewable as additive).
- **Risk + guardrail:** *re-wrap corrupts a KEK → permanently uncountable* → verify-before-commit + revert (fails
  closed). *re-wrap couples KEK to voter identity* → operates on `elections.ballot_key_wrapped` (anonymous content
  only), never `ballot_envelopes`; pinned by grep.

#### G6 — The autonomy vote (government-initiated promotion)
- **Goal:** a seated legislature **votes** (supermajority + quorum of ALL serving) to move authority for its OWN
  subtree onto a local volunteer cluster — never unilateral, parent participation required.
- **New:** `app/Services/Jurisdictions/LocalAutonomyPromotionService.php` (`propose`, `adoptPromotion`); a
  `ChamberVoteProposal::KIND_LOCAL_AUTONOMY_PROMOTION` const + a `proposeLocalAutonomy` on `ChamberActService`;
  `autonomy:promote`, `institutions:demo-g` (standing browsable demo, like `institutions:demo-e`).
- **Reuses/extends:** wire the new kind into `ChamberActService::applyProposalAdoption()` `match` (the single
  adoption path). On enactment: `publishCheckpoint()` → `partition->buildManifest` + `flips->exportFlip` (manifest
  half) → `operational->exportOperational` (data half, incl. G5a re-wrap) → record a public_record + audit edge.
  A dedicated `local_autonomy` vote-type in `config/constitution/vote_types.php` (supermajority + quorum of ALL
  serving, per the hard constraints).
- **Tests:** `tests/Constitutional/AutonomyPromotionRequiresSupermajorityTest` (sub-threshold ⇒ authority unchanged,
  no export/bundle); `tests/Constitutional/AutonomyNeverSplitBrainTest` (partial-unique index rejects a second
  recognized claim; a `verifyReproducedCounts` mismatch reverts); `AutonomyPromotionRoundTripTest` (manifest +
  operational export share the SAME `checkpoint_audit_seq`; authority flipped; ledger `verified`).
- **Deps:** G·co-member + G-ID + G5/G5a (data tier + re-wrap ready) + cold-sync (bundle transmit).
- **Risk + guardrail:** *unilateral promotion* → reachable ONLY through `applyProposalAdoption` (the single adoption
  path) on an `OUTCOME_ADOPTED` vote; CHECK constraint blocks SQL-level forgery. *split-brain* → two-phase flip +
  partial-unique index + revert-on-mismatch.

---

### TRACK C — Reach & clients

#### G8 — Transport hardening *(sneakernet + SOCKS proxy; trust is channel-independent)*
- **Goal:** carry the *same signed bytes* over a tailnet, a `.onion`, or a USB stick — packaging, not new protocol.
- **New:** `app/Services/Federation/TransportResolver.php` (`resolve`/`baseUrl`/`socksProxy`);
  `app/Console/Commands/ClusterSeedImportCommand.php` (`cluster:seed-import` — verifies a `.cgaseed` by **signature,
  not channel**, then hands to `AuthorityFlipService::importFlip`); migrations `federation_transports`,
  `instance_settings` transport columns; optional `docker-compose.tailnet.yml` (Headscale) + `docker-compose.tor.yml`.
- **Reuses/extends:** **the one true code seam** — `FederationClient::send` gains an optional `?string $socksProxy`
  threaded into Guzzle's `proxy` (`socks5h://` = remote DNS, resolves `.onion`); signature computed before transport,
  unaffected. `PeerService`/`FederationSyncService::pushTo`/`FederationHeartbeatJob` ask `TransportResolver` instead
  of `$peer->url` (backwards-compatible: no transport row ⇒ identical to today). *Extend* `FederationFlipExportCommand`
  with `--to-file`.
- **Tests:** `tests/Constitutional/TransportTrustIsChannelIndependentTest`,
  `tests/Constitutional/SeedImportVerifiesBySignatureNotChannelTest`, `tests/Chaos/TransportFailoverTest`.
- **Deps:** none (Phase-F server identity). Ships early on TRACK C.
- **Risk + guardrail:** *SOCKS DNS leak* → `socks5h` forces remote DNS; a misconfig fails closed (signature still
  required). *sneakernet replays an old flip* → `importFlip` two-phase + the authority partial-unique index.

#### G9 — Client directory *(advisory metadata; never authority)*
- **Goal:** map `jurisdiction → {authoritative endpoints, nearby mirrors}` ranked by geo/latency/health; itself a
  signed, replicable public-record-shaped feed (no single point of failure).
- **New:** `app/Services/Directory/DirectoryService.php` (`resolve`/`upsertSelf`/`ingestFeed`/`buildFeed`);
  `app/Http/Controllers/Federation/DirectoryController.php` (`/directory/resolve` `public`, `/directory/feed`
  `pinned`, `/directory/ingest` `pinned`; `ingest` parses raw `getContent()`); `app/Jobs/Directory/DirectoryHealthProbeJob.php`
  (a new CLK id); `directory:publish`; migration `directory_entries`.
- **Reuses/extends:** the `public_records` federation idiom (`source_server_id` + `signature` ⇒ authoritative-wins
  replication); PostGIS `ST_Distance` ranking (same GIST pattern as residency); `VerifyPeerSignature:public` for the
  client bootstrap read (advisory, Art. II §2 public-read safe).
- **Tests:** `tests/Constitutional/DirectoryHoldsNoAuthorityTest` (never calls `ConstitutionalEngine::file`, never
  writes `authoritative_server_id`); `DirectoryReplicationTest`; `DirectoryResolveRankingTest`;
  `tests/Chaos/DirectoryStaleFailoverTest`.
- **Deps:** cold-sync (advertise `chunk_capable` so a fresh client lands on a chunk-capable endpoint); G3 (mirror
  adoption populates mirror entries).
- **Risk + guardrail:** *forged directory entry redirects clients* → `signature` over canonical form, verified
  against the pinned peer key; resolve returns only advisory endpoints — records still require the receiving
  instance's own `VerifyPeerSignature`. *directory becomes a hidden authority channel* → `DirectoryHoldsNoAuthorityTest`.

#### G10 — Mobile / native client *(a native ping source; G10a no identity dep, G10b gated on G-ID)*
- **Goal:** wrap the existing Inertia SPA in Capacitor; emit geofence-triggered pings into the **unchanged**
  residency pipeline; push notifications; best-instance connect via the directory.
- **New (G10a, no identity dep):** `capacitor.config.ts`; `resources/js/native/{index,residency}.js` (geofence →
  ONE ping per dwell, no polling, no on-device track); `app/Services/Notifications/PushDispatchService.php` +
  `Civic/PushSubscriptionController.php` (`POST/DELETE /civic/push/subscribe`); `GET /civic/residency/geofence`
  (centroid for the geofence, reuses `ST_PointOnSurface`); `LocationPingAgeOffJob` (a new CLK id — hard-delete stale
  unverified pings); migration `push_subscriptions`. **(G10b, HARD dep on G-ID):** on-device key signs
  `ActorIdentityService::actionSigningString` for forwarded writes.
- **Reuses/extends:** **the entire residency pipeline is untouched** — the native ping hits the existing
  `POST /civic/pings` with `source:'mobile'` (already a valid `LocationPing::SOURCES` value). `CLK-05` threshold →
  `verify` → `confirmVerification` (the `LocationPing` purge) is invisible to the app. Only the derived
  `residency_confirmations` ever federates. SPA bootstrap (`app.js`/`bootstrap.js`) unchanged (Capacitor shim loads
  behind `Capacitor.isNativePlatform()`). Install deps in the **vite** container (per MEMORY).
- **Tests:** `tests/Feature/MobilePingFeedsExistingPipelineTest` (a `source:'mobile'` ping increments
  `qualifying_days` via the unchanged service; no new audit event types); `LocationPingAgeOffJobTest`;
  `tests/Constitutional/PrivacyBoundaryFederationTest` (**extends** the privacy grep: no federation/directory/push
  builder references `location_pings`/coords); `tests/Constitutional/BallotKeyNeverTransportedTest` (no G8/G9/G10
  file references `BallotCrypto`/`ballots`/`ballot_envelopes`/`payload_encrypted`/`salt`).
- **Deps:** G9 (best-instance) + G3 (adoption populates mirror entries). **G10a has NO identity dep; G10b gated on G-ID.**
- **Risk + guardrail:** *geofence becomes a covert track* → geofence not polling (one ping/dwell), no on-device
  history, server age-off even for abandoned claims, raw coords never federate. *push reveals voting* → tokens Crypt
  + `$hidden`; notifications carry only "election open in jurisdiction Y", never ballot content or whether-you-voted.

---

## 6. Cross-cutting test & demo harness

The **meta-cluster** (`g-testrig`) — builds the apparatus, not product domain. (Note: this spec proposed an
alternate G0–G10 numbering; **the prompt's track structure + G-numbers above are authoritative**; the harness's
*deliverables* are folded in below.)

- **Multi-instance rig** — `app/Services/Harness/ClusterHarness.php` (dev/test-only façade over `docker compose`:
  `bringUp`/`exec`/`addClusterNode`/`killLeader`/`partition`/`heal`/`tearDown`) + `ClusterInvariantProbe.php` (pure
  assertions over node snapshots: `oneAuthorityPerJurisdiction`, `mirrorWritesNothing`, `promotionHadEnactedAct`,
  `everyEdgeAudited`, `leadershipInvisibleToAuthority`). `docker-compose.cluster.yml` (`node1/2/3` full stacks under
  `profiles:[cluster]`) + `docker-compose.tailnet.yml`. The rig's "node B" is a **real** second stack signing with
  its own real `InstanceIdentityService` — no fake-peer trait for cross-stack tests.
- **`cluster:demo`** — `app/Console/Commands/ClusterDemoCommand.php` **extends `FederationDemoCommand`** (idempotent,
  `--fresh`, drives REAL services, ends with "visit /…"). Scripted lifecycle (each step audited under module
  `cluster_demo`): init (deploy.sh per node) → join-key+vouch adopt → **chunked sync** (asserts the ~8MB bug is
  gone) → force CLK-06 → activate → `local_autonomy_promotion` → flip → add cluster node (assert follower reports
  `authoritative_server_id IS NULL`) → kill leader (assert failover + one-authority intact) → verify. Teardown keyed
  on `harness_runs.demo_tag`, never touching append-only ledgers. `institutions:demo-g` is the standing
  single-instance browsable seed (parallels `institutions:demo-e`).
- **Schema:** `harness_runs` (idempotency/teardown anchor) + `chaos_faults` (append-only, immutable triggers like
  `sync_log`).
- **CI-blocking pins** (the union gate):
  - **Every-PR (single-DB, live-pg rolled back):** all `tests/Constitutional/*` pins named above — most importantly
    the **privacy boundary** (`OperationalDataNeverInTailTest`, `MeshSyncOperationalBoundaryTest`,
    `PrivacyBoundaryFederationTest`, `BallotKeyNeverTransportedTest` — all extend the `BallotSecrecyTest` grep), the
    **authority/leadership separation** (`ClusterAuthoritySeparationTest`, `ClusterLeadershipDoesNotTouchAuthorityTest`,
    `FollowerPresentsNullAuthorityTest`), and **one-authority** (`OneAuthorityPerJurisdictionTest`).
  - **Dedicated `cluster` CI job** (rig-gated, new `<testsuite name="Cluster">` in `phpunit.xml`): boots
    `cluster:demo --no-chaos` then runs `tests/Cluster/*` (`LeaderKillFailoverTest`, `PartitionDuringFlipTest`,
    `DroppedAckRevertTest`, `DuplicateForwardedWriteTest`, `CloneWithoutRotateRefusedTest`, the Join-cluster wizard
    E2E). These **skip only when the rig is genuinely absent** (same discipline as live-pg skips); a green-by-skip
    run **fails** the gate (`harness_runs.status` must be `passed`).
  - **Roadmap gate:** extend `FuturePhasePlaceholdersTest`'s existence-array with every Phase G pin filename — no
    mechanic can merge with its pin skipped out. Fold `chaos_faults` into `SyncLogAppendOnlyTest`'s table loop.
- **Property/chaos** grows with the tracks: cold-sync chunking, attestation convergence, write-forward idempotency,
  re-wrap tamper, leader-kill failover, partition-during-flip exactly-one-side.

---

## 7. Consolidated dependency graph + recommended build order

```
                              Phase F @ 299dcee
                                     │
            ┌────────────────────────┼─────────────────────────────┐
   TRACK A (mirror mesh)      TRACK B (earned autonomy)      TRACK C (reach & clients)
   NO Patroni, NO identity                                   ride Phase-F server identity
            │                        │                              │
   [cold-sync]  ◄── refinement #3 gate; FIRST                G8 sneakernet + SOCKS (no deps)
            │                        │                              │
       G1 mirror model        G-ID identity (parallel,        G9 directory (needs cold-sync
            │                  ship dark → dual-stack)          chunk-capable flag, G3 entries)
       G2 join-key                   │                              │
            │              ┌─────────┼──────────┐            G10a mobile (needs G9 + G3;
       G3 request/vouch    │         │          │                  NO identity dep)
            │            G4 write-  G·co-member  │            G10b on-device signing
       G3b wizard        forward   (clusters +   │                  (needs G-ID)
            │            (needs    leadership)   │
       G0b deploy.sh     G-ID)        │       G0/G7 Patroni HA
   (harness G0: rig + compose          │       (pg_basebackup clone;
    profiles + schema + suite)         │        multi-node HTTP join
                                 G5 operational  gated by cold-sync)
                                 seed (needs cold-sync,
                                 co-member, Patroni)
                                       │
                              G5a ballot re-wrap  ◄── refinement #4:
                              (TEST-FIRST, constitutional pass         build first within
                               + sign-off BEFORE code; runs INSIDE G5) TRACK B; no fed stack
                                       │
                                 G6 autonomy vote (needs G-ID + G5/G5a)
```

**Recommended build order (honoring all four refinements):**

1. **Harness G0** (`deploy.sh`, compose profiles, `harness_runs`/`chaos_faults`, `Cluster` suite) — you cannot
   integration-test a mesh without N stacks; lands first.
2. **cold-sync** (refinement #3 — gates G2 and every corpus move).
3. **TRACK A:** G1 → G2 → G3 → G3b → G0b. **← ship the mirror mesh here (the near-term win; no Patroni, no identity).**
4. **G-ID** in parallel with steps 2–3 (ship dark → dual-stack; gates G4/G6/G10b).
5. **G8** (sneakernet + SOCKS) early on TRACK C (no deps).
6. **TRACK B autonomy:** G·co-member → G4 (system-scoped now; citizen flips on with G-ID) → G0/G7 Patroni HA.
7. **G5a FIRST within the operational sub-track** (test + constitutional pass merged BEFORE code), then **G5**
   operational seed, then **G6** autonomy vote.
8. **TRACK C clients:** G9 directory, G10a mobile, then G10b (gated on G-ID).
9. **Harness Gx (continuous):** `cluster:demo`, `cluster:invariants`, the chaos suite — each pin lands alongside the
   cluster it pins; the full kill-leader campaign runs once G·co-member/G7/G6 exist. Extend `phasesLive` to G.

**The single critical path** (longest hard-dependency chain, everything else parallelizes around it):
`cold-sync → G1 → G2` **and** `G-ID` (parallel) **→** `G·co-member → G0/G7 Patroni → G5a → G5 → G6`. Patroni HA +
the ballot re-wrap are the two long poles on TRACK B; TRACK A reaches a shippable mirror mesh far earlier off the
same `cold-sync` root.

---

## 8. Risk register (top risks · each with its guardrail)

| # | Risk | Guardrail (test that pins it) |
|---|---|---|
| R1 | **Leadership leaks into authority** (the cardinal Phase G sin — wiring Patroni primary into `authoritative_server_id`) | `leader_server_id` written by exactly one method (`reconcileLeadership`); **grep pin** that `FederationSyncService`/`AuthorityFlipService` reference no cluster/leader state (`ClusterAuthoritySeparationTest`, `ClusterLeadershipDoesNotTouchAuthorityTest`, `FollowerPresentsNullAuthorityTest`). |
| R2 | **Split-brain: two clusters claim one subtree** | `authority_claims_one_authority_per_jurisdiction` (Phase F, unchanged) + `clusters_one_authority_cluster_per_jurisdiction` (local twin) + two-phase flip + revert-on-mismatch (`OneAuthorityPerJurisdictionTest`, `AutonomyNeverSplitBrainTest`, `PartitionDuringFlipTest`). |
| R3 | **Cold-sync ~8MB body-size failure** (the live-demo bug — refinement #3) | host-bounded `buildAuditPage(limit)` + persisted cursor + self-draining heartbeat; **G2 cannot start until the gate is green** (`ColdSyncChunkingTest`, `MirrorBackfillChunkedTest`). |
| R4 | **Ballot-key re-wrap corrupts a KEK / exposes plaintext** (the single most dangerous piece — refinement #4) | verify-reproduced-count **before** commit ⇒ mismatch reverts (fails closed); re-wrap never touches `ballot_envelopes`; **test + constitutional sign-off merged BEFORE code** (`BallotKeyRewrapPreservesSecrecyTest`, `AutonomyRewrapTamperChaosTest`). |
| R5 | **Privacy boundary regression** (ballots/locations/credentials ride the sync tail) | `buildAuditTail` ships audit + public_records only (unchanged); operational rows move ONLY in the encrypted flip bundle; the `BallotSecrecyTest` grep is extended to every new surface (`OperationalDataNeverInTailTest`, `MeshSyncOperationalBoundaryTest`, `PrivacyBoundaryFederationTest`, `BallotKeyNeverTransportedTest`). |
| R6 | **A mirror mutates local state** (defeats read-only) | `ConstitutionalEngine::file()` refuses on `isMirror()` + chains a `rejected` edge; write surface "indistinguishable from absent" (`MirrorIsAuthoritativeForNothingTest`). |
| R7 | **Forwarded write forges a citizen** (server-local auth can't prove who the actor is) | server sig proves the mirror (`VerifyPeerSignature`); `ResolvesForwardedActor`/attestation proves the citizen; pre-identity binding **refuses** citizen forwards (`WriteForwardActorAttestationTest`). |
| R8 | **Replay / double-execute** (adoption, forwarded write) | `(server_id,nonce)` and `idempotency_key` partial-unique indexes inside the admitting/executing transaction + the 300s replay window (`ClusterAdoptionReplayTest`, `WriteForwardIdempotencyTest`). |
| R9 | **Two scheduler leaders double-fire CLK timers/elections** | Redis `SET NX PX` single lock that follows the Patroni primary + the `ClockService::fire()` `STATE_ARMED` no-op backstop; fail-closed on ambiguous Patroni (`SingleSchedulerLeaderTest`, `LeaderProbeReconcileChaosTest`). |
| R10 | **Clone-without-rotate ⇒ two instances share `server_id`** | `deploy.sh` `assertNoIdentityCollision` (checks `federation_peers`, never `cluster_members`) + `--rotate`; existing `PeerService` self-refusal (`CloneIdentityCollisionTest`, `CloneWithoutRotateRefusedTest`). |
| R11 | **Per-node distinct APP_KEY** breaks federation-key decrypt / ballot KEK / sessions | `deploy.sh` loud warning + hard-refuse on `--join-hub` with a freshly-minted key (`SharedAppKeyGuardTest`). |
| R12 | **Forged attestation (issuer-trust-from-body)** | issuer pubkey resolved from a **pinned peer**, body's `issuer_public_key` only a cross-checked hint (mismatch ⇒ audited 401); short TTL + fast CRL bound staleness (`AttestationIntegrityTest`, `AttestationRoundTripTest`). |
| R13 | **Flaky rig masks regressions (green-by-skip)** | `Cluster` suite skips ONLY when the rig is genuinely absent; the dedicated `cluster` CI job boots the rig and requires `harness_runs.status='passed'`; `chaos_faults` append-only + audited. |

---

## 9. Open decisions for the operator (genuine forks needing a human call)

1. **DCS for Patroni (G0/G7): `etcd` vs Patroni-with-Consul vs single-node `none`.** The spec defaults to a single
   `etcd` node for the HA-pair and 3-node etcd for multi-node. Decision affects `docker-compose.ha.yml` and the
   `clusters.dcs_backend` CHECK set. *Recommendation:* `etcd` (best-tested with Patroni); confirm whether the HA-pair
   ships a single etcd (simpler, a SPOF for failover decisions) or a 3-node etcd from the start.

2. **Attestation format / canon (G-ID).** Reuse `AuditService::canonicalJson` over a fixed body dict (the spec's
   choice — one PKI, the instance Ed25519 key) vs a standard envelope (JWT/COSE/verifiable-credential). *Recommendation:*
   the spec's canonical-JSON + instance-key approach (no second PKI, reuses existing convergence); flag if interop
   with external VC tooling is a near-term requirement.

3. **Ballot transport on autonomy flip (G5a): re-wrap-in-place vs move-the-count.** Re-wrap each per-election KEK to
   the gaining APP_KEY (the spec's choice — historical elections stay countable on the new owner) **vs** leave sealed
   ballots on the losing cluster and transport only the certified `record_hash` (no re-wrap, but the gaining cluster
   can never re-count). *Recommendation:* re-wrap (constitutional re-countability), but this is the highest-risk
   piece — confirm before G5a code.

4. **Directory hosting model (G9).** Self-published-per-instance + peer-replicated feed (the spec's choice, no SPOF)
   **vs** a small set of well-known seed directories clients bootstrap from. *Recommendation:* the replicated feed;
   decide whether to additionally publish a curated seed list for cold client bootstrap.

5. **Co-member admission trust (G·co-member).** Require an existing trust-established **sovereign peer** before
   co-membership (the spec's choice — `pinned` mode) vs a lighter join-key like the mirror path. *Recommendation:*
   require sovereign-peer trust (co-membership is far more powerful than mirroring); confirm.

6. **`PeerService::upsertTrustedPeer` extraction.** The mirror path wants to reuse the exact TOFU-pin block from
   `receiveHandshake`. Extract it into a shared method now (a 1-method additive refactor of a Phase-F-protected-
   adjacent service) vs copy the block. *Recommendation:* extract (single source of truth), but it touches
   `PeerService` — confirm the additive refactor is acceptable.

7. **Mobile auth before G-ID (G10a).** Session-cookie via the Capacitor cookie jar (the spec's choice — ships
   mobile pings before the identity layer) vs wait for G-ID device signing. *Recommendation:* ship G10a on the
   session cookie (read-only ping path needs no identity), defer signed writes to G10b.

8. **Transport overlays as CI matrix.** Run the `tailnet`/`tor` compose overlays as a nightly CI axis vs manual-only.
   *Recommendation:* nightly tailnet matrix (prod-shaped path), bridge per-PR — confirm CI budget.

---

*End of Phase G implementation plan. Constitutional/privacy invariants — authority≠leadership, mirror-is-authoritative-
for-nothing, the privacy boundary (ballots/locations/credentials never in the sync tail), and re-wrap-fails-closed —
are CI-blocking and must stay green at every step.*
