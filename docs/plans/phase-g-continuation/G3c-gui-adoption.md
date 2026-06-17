# DESIGN — GUI-Driven Federation Adoption (Host + Join sides)

A design round. Nothing below is implemented. Every claim about existing state is grounded in files I read.

---

## 1. Existing state (what's already built, where the gaps are)

**The join side is GUI; the host side is CLI-only.** Confirmed:

- `FederationConsoleController::show()` (lines 31–95) renders `Jurisdictions/Federation` with `instance`, `mirror`, `peers`, `sync`, `checkpoints`, `claims`. It already reads `ClusterMembership` for the mirror role but **never reads `ClusterJoinKey` or `ClusterAdoptionRequest`** — the host has no props.
- `join()` / `leave()` (lines 103–143) are the only mutating actions, both join-side. `Federation.vue` (lines 89–140) renders exactly one "Cluster membership" card: host URL + optional join key + leave button. It is a flat two-field form — **no negotiation, no instance options, no read-write request.**
- Host primitives exist only as CLI: `cluster:keys:mint|list|revoke` (`ClusterKeysMint/List/RevokeCommand.php`), `cluster:requests`, `cluster:approve`, `cluster:reject` (`ClusterRequests/Approve/RejectCommand.php`). Each is a thin wrapper over `MirrorService` / `MirrorJoinKeyService`.
- The service layer is already complete and GUI-ready. `MirrorService` exposes every host operation the CLI uses: `pendingRequests()` (354), `approveRequest()` (301), `rejectRequest()` (335), and `admitMirror()` (108). `MirrorJoinKeyService` exposes `mint()` (31, returns `[plaintext, key]` — plaintext shown once), `revoke()` (94). **No new service methods are required for the host console** — only a controller + routes + Vue.
- Routes: only three federation web routes exist — `federation.show`, `federation.cluster.join`, `federation.cluster.leave` (`routes/web.php` 349–355). Server-to-server endpoints live in `routes/federation.php` (`/adopt` 50, `/flip` 46, `/write` 55).

**The freely-pullable vs governed boundary is real and enforced in code:**

- Freely pullable = `public_records`. `ColdSyncService::pull()` drains `/api/federation/audit-tail` pages into local `public_records` (PublicRecord.php: `audit_seq` seals each, append-only). `MirrorBackfillService::drain()` orchestrates it. This is what a mirror gets — no permission needed.
- Governed-only = ballots + per-election keys. `OperationalBundleService` (G5) is the **sealed, point-to-point** transfer of raw `k_e` election keys, libsodium-sealed to the gaining cluster, re-wrapped fail-closed on arrival (`BallotKeyRewrapService::adopt`). Its docblock (lines 28–32) states this is "the autonomy-flip EXCEPTION to 'ballots/keys never federate'."
- The flip itself: `AuthorityFlipService::exportFlip()` (41) sets `jurisdictions.authoritative_server_id → peer` and is "gated to an operator at the caller (CLI / console)" (docblock 16–18). Today the only caller is `FederationFlipExportCommand` (CLI). The write-guard in `ConstitutionalEngine.php` (97–102) refuses every constitutional filing while `InstanceSettings::isMirror()`.

**GEODATA_ORIGIN is not yet a code symbol.** The only matches are deploy.sh comment ("a mirror ingests no geodata", line 91) and the `etl_geodata` docker volume (docker-compose.yml 331/354). There is **no federation channel for the geospatial dataset today** — it is delivered out-of-band (archive bind-mount, `/archive`, per the ETL memory note). So "freely pullable geodata" is a *design-time* concept I must define, not an existing endpoint.

**Constitutional anchor for read-write.** `docs/extracted/fair_constitution.md` Art. V §7 (lines 331–337): joining a Union requires "A Supermajority of Individuals in an Applicant Jurisdiction, and a Supermajority of Constituent Jurisdictions in the Union." That is precisely the dual-supermajority gate a read-write request must trigger — it is *not* an adoption checkbox. The Phase G master plan confirms: "Co-member admission → GOVERNED (validated by the authoritative government)" and routes through a `local_autonomy_promotion` vote (PHASE_G_MASTER_PLAN.md lines 28–36, 63).

---

## 2. The cardinal boundary this design must honor

Three tiers of data, three different trust mechanics. The UX must make these **visibly distinct** so an operator never confuses "copy public records" with "take over a government."

| Tier | What | Mechanism | Who decides | Existing code |
|---|---|---|---|---|
| **Freely pullable — public corpus** | `public_records` (the FF&C tail) | Cold-sync backfill, signed pages | Mirror operator alone (permissionless) | `ColdSyncService`, `MirrorBackfillService` ✅ |
| **Freely pullable — geodata** | WorldPop rasters + geoBoundaries | *(new)* signed dataset manifest pull, or "already have it" | Mirror operator alone | **none — design defines `GEODATA_ORIGIN`** |
| **Governed — operational** | ballots, per-election `k_e`, authority | Sealed G5 bundle + authority flip, **dual supermajority** | The jurisdiction's **government** (Art. V §7) | `OperationalBundleService`, `AuthorityFlipService` ✅ (CLI-gated) |

**The iron rule for the negotiation UX:** read-write is a *request that triggers a constitutional process*, never a join-wizard toggle. The wizard can *compose and submit* the request; it can never *grant* the result. Granting happens only when the autonomy vote + dual supermajority resolve on the authoritative government's instance.

---

## 3. Data model

Mostly additive columns + one new table. Nothing touches a protected migration.

### 3.1 `cluster_adoption_requests` — extend (additive columns)

The model `ClusterAdoptionRequest` already exists (referenced throughout `MirrorService`, migration `2026_09_04_...` family). Add nullable columns to carry the negotiation:

```
requested_relation        string   default 'mirror'   -- 'mirror' | 'co_member'  (what the applicant ASKED for)
requested_scope_jurisdiction_id  uuid nullable        -- a specific subtree, or null = whole corpus
applicant_name            string   nullable           -- self-declared label, for the host review queue
applicant_url             string   nullable           -- already passed through admitMirror/requestAdoption; persist it
note                      text     nullable           -- free-text from the applicant ("why we want to mirror")
```

`requested_relation='co_member'` is **advisory only** at the adoption layer — it never auto-grants R/W. It is a flag the host operator sees that says "this applicant also intends to pursue autonomy; expect a separate governed flip." Admission still produces a `mirror` membership (authoritative for nothing). This keeps the `admitMirror`/`approveRequest` paths byte-identical.

### 3.2 `read_write_requests` — new table (the governed-flip intake)

The negotiation's read-write ask is **not** an adoption request — it is the front door to the Art. V §7 process. A distinct table keeps it off the mirror-admission path entirely:

```
id                        uuid PK
applicant_server_id       uuid       -- the requesting mirror's identity
applicant_public_key      text
root_jurisdiction_id      uuid       -- the subtree the applicant wants R/W over
status                    string     -- 'submitted' | 'vote_opened' | 'granted' | 'denied' | 'withdrawn'
autonomy_vote_id          uuid nullable  -- FK to the local_autonomy_promotion chamber vote once opened
partition_export_id       uuid nullable  -- FK to PartitionExport once the flip commits
operational_bundle_id     uuid nullable  -- FK to OperationalPartitionExport (the sealed k_e handover)
submitted_at / resolved_at  timestamptz
audit_seq                 int        -- sealed to the chain like every governed act
```

Every transition is an `AuditService::append('federation', 'rw_request.*', …, 'WF-JUR-07')` event (Union = WF-JUR-07 in the Phase F process set; the flip itself stays WF-JUR-08). The table is a **public record** — a government receiving a read-write petition is exactly the kind of consequential act Art. II §2 requires be on the append-only register.

### 3.3 No changes to

`ClusterJoinKey`, `ClusterMembership`, `FederationPeer`, `InstanceSettings` — all already carry the fields the host console reads (`handle`, `uses`, `max_uses`, `expires_at`, `revoked_at`, `scope_jurisdiction_id`; `relation` host/mirror/sovereign). `MirrorJoinKeyService::mint()` already returns the once-shown plaintext.

### 3.4 GEODATA_ORIGIN (design definition)

Define geodata distribution as a **separate, optional, signed dataset channel** — deliberately *not* part of the audit tail (rasters are large and license-bound: geoBoundaries CC BY 4.0, WorldPop CC BY 4.0, per CLAUDE.md). Minimal model:

```
config('cga.geodata_origin')  -- a URL or 'self' or null; the upstream that serves the dataset manifest
geodata_dataset_manifests     -- (new, optional) {dataset, version, sha256, license, size_bytes, fetched_at}
```

The join wizard offers three geodata postures (§5.3); the actual transport (HTTP range-pull of raster tarballs, or "I already have the archive bind-mounted") is a Phase-H-adjacent concern. For *this* build target the wizard records the **choice**; the pull mechanism can land with Phase H (which is the first consumer of rasters at runtime, per the districting memory note). This keeps the first build target scoped to adoption, not ETL.

---

## 4. Controller + routes

### 4.1 New controller: `FederationHostController`

Splits host actions out of the read-only `FederationConsoleController` (which should stay a reader, per its docblock line 21). All web-group, session-authed, operator-gated.

```php
mintKey(Request)      -> MirrorJoinKeyService::mint(maxUses, expiresAt, scope)
                         flashes plaintext ONCE via session (->with('minted_key', $plaintext))
revokeKey(Request)    -> MirrorJoinKeyService::revoke($handle)
approveRequest($id)   -> MirrorService::approveRequest($id)
rejectRequest($id)    -> MirrorService::rejectRequest($id)
```

The plaintext-once contract: the minted key rides a **one-shot flash**, never a prop that survives a refresh. `Federation.vue` shows it in a dismissible banner; reloading the page loses it (matching `ClusterKeysMintCommand` "shown only once" and `ClusterJoinKey::$hidden = ['key_hash']`).

### 4.2 Extend `FederationConsoleController::show()` props

Add host-side reads (only when the operator is authorized — see §6):

```php
'host' => [
    'keys'    => ClusterJoinKey live+dead list (handle, uses/max_uses, state, expires_at, scope) — NEVER key_hash,
    'requests'=> MirrorService::pendingRequests() mapped (id, applicant_server_id, applicant_name, requested_relation, requested_scope, note, created_at),
    'mirrors' => ClusterMembership role=host (peers we host),
    'rw_requests' => ReadWriteRequest::pending() (id, applicant, root_jurisdiction, status, autonomy_vote link),
],
```

### 4.3 Extend the join wizard controller

`join()` gains negotiation params: `requested_relation`, `requested_scope_jurisdiction_id`, `geodata_posture`, `note`. These flow into `MirrorService::requestJoin()` / `joinHost()` (which already accept a URL; extend signatures to carry the negotiation into `ClusterAdoptionRequest`). The "I want to become read-write" action is a **separate** form/route — `POST /federation/cluster/request-read-write` → creates a `ReadWriteRequest`, never touches the mirror admission.

### 4.4 Routes (all in the web group, after the existing three)

```php
// Host console
POST /federation/host/keys            host.keys.mint
POST /federation/host/keys/{handle}/revoke  host.keys.revoke
POST /federation/host/requests/{id}/approve  host.requests.approve
POST /federation/host/requests/{id}/reject   host.requests.reject
POST /federation/host/rw/{id}/open-vote      host.rw.open      // opens the autonomy vote (governed)
POST /federation/host/rw/{id}/deny           host.rw.deny

// Join negotiation (extends existing)
POST /federation/cluster/request-read-write  cluster.rw.request
```

### 4.5 Server-to-server (extend `routes/federation.php`)

`/adopt` (AdoptionController) already carries `url`/`public_key`/`nonce`. Extend the body parse to read `requested_relation`, `requested_scope`, `note` from the raw signed bytes (same `json_decode(getContent())` discipline at AdoptionController.php:37) and pass them through `requestAdoption()` / `admitMirror()`. **No new endpoint** for adoption negotiation. A new `POST /api/federation/request-read-write` (pinned — only an established mirror may ask) receives the cross-instance R/W petition and creates the host-side `ReadWriteRequest`.

---

## 5. Vue surfaces & negotiation UX

`Federation.vue` becomes three operator-facing zones plus the existing read-only ledgers. Reuse the existing card grammar (`rounded-lg border border-slate-200 bg-white p-5`, status pill helpers `statusClass`/`resultClass`).

### 5.1 Host console panel — Invite keys

A new card "**Invite keys (host)**". Reuses the existing peers-table styling.

- **Mint form:** max-uses (default 1), expiry (`+7 days` presets + custom), scope (jurisdiction picker, default "whole corpus"). On submit → flash banner: *"Copy now — shown once: `handle.secret`"* with a copy button, dismissible. This is the GUI of `cluster:keys:mint`.
- **Live keys table:** handle · uses/max · state (live/dead pill) · expires · scope · **Revoke** button (confirm). GUI of `cluster:keys:list` + `cluster:keys:revoke`.
- Toggle "show dead keys" mirrors `--all`.

### 5.2 Host console panel — Pending adoption requests

Card "**Adoption requests (host)**" — the GUI of `cluster:requests` + `approve`/`reject`.

- One row per pending `ClusterAdoptionRequest`: applicant name + short server-id · requested relation badge (`mirror` neutral / `co_member` amber "intends autonomy") · requested scope · note · requested-at.
- Two buttons: **Approve** (vouch → `approveRequest`) and **Reject**. Approve shows a confirm explaining "this admits a read-only mirror; it remains authoritative for nothing."
- If `requested_relation='co_member'`, an inline note: *"This applicant also intends to pursue read-write. Approving here grants mirror access only — read-write is decided by [jurisdiction]'s government (Art. V §7)."* This is the seam that keeps adoption and governance distinct.

### 5.3 Join wizard — negotiate what-to-pull + instance options

Replace the flat two-field form (Federation.vue 115–139) with a **stepped wizard** (steps in one Inertia form, client-side stepping — matches the Setup wizard pattern already in the codebase):

- **Step 1 — Host & credential.** Host URL + optional join key (as today). Key present → one-step admit; absent → request a vouch.
- **Step 2 — What to pull (negotiation).**
  - Public corpus: **always pulled** (this is what being a mirror *means*) — shown as a fixed, checked, disabled row with the line "the public records of [host] — free to mirror."
  - Scope: "whole corpus" vs "a specific jurisdiction subtree" (picker). Maps to `requested_scope_jurisdiction_id`; the host's join key may itself be scoped (`ClusterJoinKey.scope_jurisdiction_id`), in which case the wizard shows the constraint.
  - **Geodata:** radio — (a) "I already have the map archive" (default; the bind-mount path), (b) "Pull geodata from `GEODATA_ORIGIN`" (records the posture; shows license CC BY 4.0), (c) "Skip — text-only mirror." Records `geodata_posture`.
- **Step 3 — Relationship & instance options.**
  - Relationship radio: **Read-only mirror** (default, neutral) vs **Read-only mirror, *and* I intend to request read-write** (amber). Choosing the second sets `requested_relation='co_member'` on the adoption request *and* surfaces the read-write explainer.
  - A non-negotiable info block: *"Read-write is not granted by joining. After you are a mirror, you submit a read-write request; [host]'s government must approve it by a supermajority of your jurisdiction's residents and a supermajority of constituent jurisdictions (Art. V §7). Only then does authority flip and the sealed operational bundle transfer."* — verbatim-grounded in the constitution clause I read.
- **Step 4 — Review & submit.** Plain-language summary: "You will become a read-only mirror of [host], pulling its public records[, scoped to X][, plus geodata]. You [will / will not] request read-write afterward." Submit → existing `join`/`requestJoin` path.

### 5.4 Join wizard — the read-write request (post-join)

Once the instance *is* a mirror, the "Cluster membership" card (the `mirror.is_mirror` branch, Federation.vue 98–112) gains a **"Request read-write authority"** button next to "Leave the cluster." It opens a small form: pick the subtree (`root_jurisdiction_id`), a justification note → `POST /federation/cluster/request-read-write`. This composes the petition; it does not change anything locally. Status thereafter shows "Read-write requested for [jurisdiction] — awaiting [host] government (Art. V §7 vote)."

### 5.5 Nav

`/federation` is currently reachable only by URL — it has **no entry in `resources/js/Navigation/nav.js`** (grep found none; the AppShell line 321 hit was a CSS `cluster` class, unrelated). Add a "Federation" nav item so both consoles are discoverable. This is a small, separate UX fix worth flagging.

---

## 6. Constitutional gates & guardrails

1. **Read-write is never an adoption checkbox.** The wizard can only *create a `ReadWriteRequest`*. Granting requires the host government to (a) open a `local_autonomy_promotion` chamber vote, (b) reach Art. V §7 dual supermajority (supermajority of applicant-jurisdiction individuals **and** supermajority of constituent jurisdictions), then (c) `AuthorityFlipService::exportFlip()` + `OperationalBundleService::buildSealedFor()` execute. The GUI host action `host.rw.open-vote` only *opens the vote* — it does not decide it. The supermajority math reuses the hardened `ceil(serving*2/3)` path; this design adds no new immutable rule (per roadmap §2 principle 1, "[POLICY] vs [HARDENED]").

2. **A mirror stays authoritative for nothing until the flip commits.** The write-guard (`ConstitutionalEngine.php:97`) keeps refusing all filings while `mirror_of_server_id` is set. Nothing in the negotiation UX writes `authoritative_server_id`. Only `importFlip` (on the gaining side, after the governed flip) sets it to NULL.

3. **The sealed boundary holds.** Ballots and `k_e` keys travel **only** via `OperationalBundleService` (sealed, point-to-point, fail-closed re-wrap), and **only** as a consequence of a granted R/W request — never in the cold-sync tail a mirror pulls. The `FORBIDDEN_SUBJECT_TYPES` export filter (roadmap §7) is unchanged.

4. **Join keys: secret shown once, hash-only at rest.** GUI mint reuses `MirrorJoinKeyService::mint()` (Argon2id, plaintext returned once). The host props **must** map `ClusterJoinKey` without `key_hash` (the model already `$hidden`s it; the controller mapping must not re-expose it). Plaintext rides a one-shot flash, never an Inertia prop.

5. **Operator authorization.** Host actions (mint/revoke/approve/reject/open-vote) are operator-grade, not any-resident. Gate behind the existing instance-operator policy (the same authority that runs `artisan` today). The read-only `show()` mesh state stays public (Art. II §2, per the controller docblock). **OPEN: which exact policy/role gates host actions — see §8.**

6. **Every host action is audited.** Mint/revoke/approve/reject already append `mirror.*` events via the services. The new R/W intake appends `rw_request.*` under WF-JUR-07. The `ReadWriteRequest` row is itself a public record.

7. **Anti-replay & TOFU preserved.** Negotiation params ride the same raw-signed-bytes parse (`getContent()`), so adding fields to `/adopt` does not break the `(applicant, nonce)` replay guard (AdoptionController.php:74) or the tofu signature check.

---

## (a) Roadmap slot

This is **Phase G, Track A finish-work** — specifically a new sub-phase I'd label **G3c (host adoption console)** + **G·co-member intake (the GUI front door to the governed flip)**. It is *not* H–O. The roadmap (`CGA_PHASE_G_AND_BEYOND_ROADMAP.md` §3) lists `G3b` (join wizard) as ✅ but the host side (mint/approve) was only ever CLI; this closes that asymmetry. It is fully dev-stack-buildable today (no physical rig — the parked gates are G-V1 mobile + G-V2 cross-machine, §3.1, neither of which this touches). It sits *before* Phase H on the critical path only in the sense that it completes G; H remains the recommended next *new* phase.

## (b) Open decisions for the operator

1. **Operator authorization model.** What gates host actions (mint/approve/open-vote)? Today it's "whoever has shell access runs artisan." Options: (i) a simple `is_instance_operator` flag on `users`, (ii) reuse an existing R-## role, (iii) a setup-wizard-designated owner. The host console needs *some* authority predicate before it ships in a browser.
2. **Geodata channel depth for *this* build.** Do we (i) record the geodata posture only and defer the actual signed-dataset pull to Phase H (my recommendation — keeps scope tight), or (ii) build the `GEODATA_ORIGIN` transport now? There is no geodata federation endpoint today.
3. **`requested_relation='co_member'` — advisory flag vs. distinct intake.** I propose it stays advisory at adoption and the real R/W ask is the separate `ReadWriteRequest`. Confirm you don't want co-member intent to alter the *admission* path itself.
4. **Who opens the autonomy vote — host operator or applicant-jurisdiction government?** Art. V §7 needs *both* a supermajority of applicant individuals and of constituent jurisdictions. The GUI `open-vote` action belongs on whichever instance is authoritative for the subtree; confirm the routing (likely: the request lands on the *authoritative* instance, which opens its own chamber vote).
5. **Nav entry placement.** Add "Federation" to `nav.js` — top-level, or nested under a "System/Instance" group?

## (c) Risks

1. **Secret leakage via Inertia.** The minted plaintext key is the single highest-risk surface. If it ever lands in a prop (not a one-shot flash) it survives in the Inertia page object / browser history. Must be flash-only, dismissible, copy-then-gone. `key_hash` must be stripped in the host-props mapper even though the model `$hidden`s it (a careless `->toArray()` could re-expose).
2. **Conflating mirror-admission with governance.** The biggest *conceptual* risk: an operator clicks "approve" expecting it grants the read-write the applicant asked for. Mitigated by the §5.2 inline explainer and by keeping `ReadWriteRequest` a wholly separate table/flow — but the copy must be unambiguous.
3. **Negotiation params breaking the signed-body discipline.** `/adopt` and any new endpoint sign over raw `getContent()`. New fields must be added to the *signed payload* on the sender and parsed from raw bytes on the receiver, or signatures fail. Follow the AdoptionController.php:37 / SyncController precedent exactly.
4. **Scope mismatch between join key and requested scope.** A scoped `ClusterJoinKey` (`scope_jurisdiction_id`) vs a wizard-requested broader scope must reconcile deterministically (key wins, or reject). Undefined today; needs a rule.
5. **Read-only `show()` controller bloat.** Adding host props risks making the public read-only console leak host-only data (pending requests, key handles) to non-operators. The host props must be conditionally populated behind the authorization predicate, not always sent.
6. **GEODATA_ORIGIN is greenfield.** Treating it as a federation channel when none exists could balloon scope into ETL/transport work that belongs in Phase H. Recommend recording posture only now (decision b.2).

**Files grounding this design:** `app/Http/Controllers/Federation/FederationConsoleController.php`, `app/Http/Controllers/Federation/AdoptionController.php`, `app/Http/Controllers/Federation/FlipController.php`, `resources/js/Pages/Jurisdictions/Federation.vue`, `app/Services/Mirror/MirrorService.php`, `app/Services/Mirror/MirrorJoinKeyService.php`, `app/Services/Mirror/MirrorBackfillService.php`, `app/Services/Federation/ColdSyncService.php`, `app/Services/Federation/OperationalBundleService.php`, `app/Services/Federation/AuthorityFlipService.php`, `app/Console/Commands/ClusterKeysMint|List|RevokeCommand.php`, `app/Console/Commands/ClusterRequests|Approve|RejectCommand.php`, `app/Console/Commands/ClusterJoin|RequestAdoption|LeaveCommand.php`, `app/Console/Commands/FederationFlipExportCommand.php`, `app/Models/{FederationPeer,ClusterMembership,ClusterJoinKey,InstanceSettings,PublicRecord}.php`, `app/Domain/Engine/ConstitutionalEngine.php` (write-guard, line 97), `routes/web.php` (349–355), `routes/federation.php`, `resources/js/Layouts/AppShell.vue`, `resources/js/Navigation/nav.js`, `docs/extracted/fair_constitution.md` (Art. V §7, lines 331–337), `docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md` (§3), `docs/plans/institutions/PHASE_G_MASTER_PLAN.md` (Prong 2, lines 28–66).