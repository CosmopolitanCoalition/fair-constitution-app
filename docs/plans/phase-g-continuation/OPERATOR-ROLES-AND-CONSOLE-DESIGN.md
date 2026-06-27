# Operator Roles & Mesh Reach — Consolidated Design (2026-06-21)

> **⚑ MODEL CORRECTION (operator, 2026-06-26).** The federation model is **SAME-GAME full replication**, not narrow sync. A mesh is one game → every node's data should MATCH; read = full replication minus per-node-unique (identity/keypair/local ops); **geodata + cosmic sync IN FULL** (no skip); **Full Faith & Credit is the WRITE side** (the node authoritative for a jurisdiction writes; peers credit/accept those writes) — NOT a sync limiter. State lives on **two planes**: Plane A (records + geodata foundation, fully replicated via FF&C; institutions converge by audit-replay; the geodata *seed* is the one gap, closed by the seed-sync build) and Plane B (live Matrix + voice, S2S, load-bearing for gameplay). **Decisions updated by the 2026-06-26 review:** DNS+cert collapse into ONE Identity-Broker role (★12-19); no raster skipping (Plane A replicates in full); mesh-reach (★20-25) extends to `matrix.homeserver`/`voice.sfu` for the mixed environment. Canonical model: the auto-memory `project_federation_same_game_model.md`; campaign plan: `~/.claude/plans/your-interpretation-of-the-fluffy-hartmanis.md`.

*Synthesis of the 5-section operator design round. Every claim is grounded to `file:line` in
`E:/fair-constitution-app/.claude/worktrees/practical-payne-17d537`. This is a DESIGN doc — a model,
decisions, a ★-numbered build table with effort, and the open decisions the operator must rule on before
building. It contains no code.*

---

## 1. Model overview — a NAMED-ROLE layer projected OVER the channel substrate

The whole design rests on one structural move that the operator has already settled: **the 4 constitutional
operator-roles (Record Keeper / Archivist / Social Moderator / Identity Broker) are a presentation +
orchestration grouping over the already-built capability-channel substrate — they add no new power-bearing
surface, no new grant kind, no new consent path.**

A box's "role" is already defined as *the derived set of its enabled channels — never a stored tier*
(`InstanceCapability.php:9-17`, verified). The closed channel vocabulary is exactly 9 slugs:
`mesh.member, mirror, etl, broker.dns, broker.tls, client.serve, authority.grant, matrix.homeserver, voice.sfu`
(`InstanceCapability.php:23-26`, verified), split into SELF_ASSERTED `{mesh.member, mirror, etl}` and
GOVERNED `{broker.dns, broker.tls, client.serve, authority.grant, matrix.homeserver, voice.sfu}`
(`InstanceCapability.php:29-34`, verified).

**The role↔channel mapping (the one source of truth for all four domains):**

| Named role | Channel set | Consent character |
|---|---|---|
| **Record Keeper** | `{mirror, etl}` | both SELF_ASSERTED — one-click Establish, no consent gate (`InstanceCapability.php:29`) |
| **Archivist** | `{client.serve}` + read-write authority | `client.serve` GOVERNED; RW-authority is the *separate* Art. V §7 petition (`FederationConsoleController.php:275`), not a 9-channel slug |
| **Social Moderator** | `{matrix.homeserver, voice.sfu, client.serve}` | all GOVERNED (`InstanceCapability.php:32-34`) |
| **Identity Broker** | `{broker.dns, broker.tls, authority.grant, client.serve}` | all GOVERNED; `broker.dns` + `authority.grant` are the two `affectsPeerSubtree` channels that pull in Meter C (`CapabilityProber.php:34`) |

**Why naming them IS the UX fix.** Today the console (`resources/js/Pages/Jurisdictions/Federation.vue`,
~788 lines) is a flat expert dump that surfaces raw slugs like `broker.dns`, channel-state words
(`qualifiable`/`needs-config`), Meter A/B/C chips, and CLI hints (`mesh:doctor`, `transport:register`) to a
first-run blogger. The settled simplification is to render **4 role cards + their plain-language duties** as
the primary surface, with every raw slug, gate cluster, and Meter chip relegated behind an "Advanced"
disclosure. There is no separate "console redesign" and "roles model" — **they are the same surface**: the
role cards are a re-bucketing of the per-channel projection `MeshGateService::channels()` already returns
(`MeshGateService.php:142-194`).

**Grounded confirmation that the named roles are net-new:** `config/mesh_channels.php` exists
(verified — the precedent: a human-readable label+"what" layer over the model constants,
`mesh_channels.php:5-8,11-50`) but **neither `config/mesh_roles.php` nor `config/operator_roles.php` exists,
and the strings `record_keeper`/`identity_broker`/`social_moderator` have zero references anywhere in
`app/`, `config/`, `resources/`** (verified). So the role grouping is something we build, not something we
refactor.

**The plane wall is preserved throughout.** Operator-plane vocabulary is `capability`, never `role`, so it
can never collide with the citizen R-01…R-30 system (`InstanceCapability.php:9-17`, verified). The named-role
layer is operator-plane UX copy; it carries no DB state of its own and lives in config, not a migration.

### Naming the config file (resolved overlap)

Two sections independently proposed the role-grouping config file under different names —
`config/mesh_roles.php` (roles-model, console-ux) and `config/operator_roles.php` (role-assumption-devtools).
**This design adopts ONE file: `config/mesh_roles.php`**, beside `config/mesh_channels.php`, for catalog
parity. It is the single source of truth consumed by the console, the dev role-lab, and SOP generation.
(Surfaced as Open Decision #1 for the operator's final call.)

---

## 2. Domain designs

### 2.1 Roles model — derivation, lifecycle, accountability

**A role's STATE is derived from its channels' states, never stored.** `MeshGateService::channels()` already
derives a per-channel state ∈ {established, qualifiable, needs-config, requested, lapsed}
(`MeshGateService.php:142-194`). A new sibling `MeshGateService::roles()` folds those into a per-role rollup
by *reading each required channel's existing state — NOT re-probing*. Deterministic fold: `established` iff
every required channel established; else `requested` if any channel requested; else `qualifiable` if every
not-yet-established channel is qualifiable; else `needs-config`; `partial` if some-but-not-all established.
`channels()` and `evaluate()` stay byte-identical so `mesh:gates` and the existing Role Board pin keep
passing (`MeshRoleBoardTest.php:31-56`).

**Lifecycle reuses the substrate verbatim — no forking:**
- **Adopt** a role = batch the existing per-channel qualify→request→approve→join. Self-asserted channels go
  straight to `CapabilityService::registerSelf` (`CapabilityService.php:21`); each governed channel opens a
  `MeshRoleGrantService::request()` proposal (`MeshRoleGrantService.php:57`) ratified through the IDENTICAL
  dual-meter consent (`MeshRoleGrantService::ratify` reusing `PeerUpgradeAgreementService.php:484-545`,
  `MeshRoleGrantService.php:131-155`). No new vote math, no new grant TTL (the 90-day clock,
  `MeshRoleGrantService.php:37`).
- **Revoke** = drop each channel via `MeshRoleGrantService::revoke()` (`MeshRoleGrantService.php:263`),
  already unilateral ("stopping a service is unilateral", `CapabilityService.php:57`).
- **Handoff** = the receiving box adopts (grant minted to ITS pubkey via `mintGrant`,
  `MeshRoleGrantService.php:287`) and the outgoing box revokes. No "transfer" primitive — the grant is
  per-grantee-pubkey by construction, so handoff is adopt-then-revoke.

**Standing-government accountability is already encoded and reused unchanged.**
`applicableConsentLeg()` (`PeerUpgradeAgreementService.php:474`) pivots live: bootstrap ⇒ operator board
(Meter A, scaling 1/unanimity/2-3), seated government ⇒ Meter B supermajority which SUPERSEDES the operator
board (`PeerUpgradeAgreementService.php:174-182`). The "operators = overlapping de-facto board, answerable to
the in-game government" model the operator settled on is precisely the Meter A→B supersession. A
Social-Moderator adoption in a seated jurisdiction routes through the MJV exactly as a raw
`matrix.homeserver` request does today.

### 2.2 Simplified console — role-first, two-tier progressive disclosure

The redesigned `/federation` page has exactly **two tiers**:

- **Tier 1 (default, non-technical):** 4 role cards (friendly label + one-line "what" + plain "your duty to
  peers" line + status pill + a single primary CTA), plus a "Peers & sync at a glance" health line (one
  green/amber/red rollup of the 6 `evaluate()` gates, `MeshGateService.php:52-119`).
- **Tier 2 ("Advanced" toggle, off by default):** *everything* on today's page survives, just relegated —
  the raw channel grid (`Federation.vue:186-234`), pending requests + Meter A/B/C chips (`236-259`), broker
  credentials form (`261-301`), two-way-mesh setup / gates / discover-handshake-probe / transport switcher
  (`303-424`), host adoption console (`604-717`), and the Peers/Sync/Claims/Checkpoints tables (`719-785`).
  Nothing is deleted; it stops being the *first* thing.

**Channel-state → plain-pill mapping** (Tier 1): established → "Active", qualifiable → "Ready to turn on",
needs-config → "Needs setup", requested → "Waiting for approval", lapsed → "Stopped".

**A true first-run wizard, gated on fresh state.** Detect fresh from props already assembled by
`FederationConsoleController::show` (`instance.enabled` false + zero peers + no established channels + not
mirror, `Controller:84-198`). When fresh, open on a welcome wizard: (1) name the instance
(`InstanceSettings.php:21`); (2) pick role — 4 big choice cards with duties, **Record Keeper pre-selected,
"Recommended for a first node"**; (3) role-specific setup (Record Keeper → the existing join wizard,
`Federation.vue:483-601`; Broker → credential drop then DNS-before-TLS); (4) "You're set". The wizard reuses
existing POST routes verbatim (`/federation/cluster/join`, `/federation/roles/establish|request`,
`/federation/broker/credentials` — `web.php:357,767,769,782`) — pure re-skinning of working flows.

**Honest UX truth the wizard copy must carry (surfaced, not a pivot):** only Record Keeper is one-click
(self-asserted); Archivist/Social Moderator/Identity Broker are GOVERNED and can only be *requested* in the
wizard, then await dual-meter approval. This is fully consistent with the settled "assume role = assume duty"
model — the lift is requesting the channels; the duty is the constitutional copy — but the wizard must say
"you're requesting this role; your peers/government must approve."

### 2.3 DNS-first Identity Broker — DDNS, providers, wildcard backup

**Where we stand (grounded):** the broker today is CERT-FIRST — `Broker::issue()` issues the cert first
(`Broker.php:28`), then does a best-effort A-record upsert that "does NOT void the cert"
(`Broker.php:36-45`). The A-record path is real end to end (`Cloudflare::upsertAddressRecord`,
`Cloudflare.php:13`, gated on an IP-validated `target`, `GrantVerifier.php:108-115`); it's just sequenced
last. **The code refuses wildcards by construction (verified):** the grant carries one `subdomain` label
(`GrantVerifier.php:95-99`), the CSR must request EXACTLY that one FQDN
(`array_diff($csrNames, [$fqdn]) !== []` → reject, `GrantVerifier.php:102-106`), and
`Broker::assertCertCoversOnly` re-checks the issued cert names `=== [$fqdn]` in-process
(`Broker.php:91`, verified). **Per-name is the only thing built; the wildcard is README copy, not capability.**

**Conceptual model — DNS is the identity, the cert is the proof.** Four pillars, each on an existing seam:

1. **DNS-then-cert ordering.** Reorder `Broker::issue` so the A-record upsert happens BEFORE
   `acme->issueFromCsr`. When a `target` is present and the A-record write fails, fail BEFORE the ACME call
   (so a cert never points at nothing AND no Let's Encrypt budget is burned). The no-target path (peer sets
   its own DNS) is unchanged.
2. **Per-name primary + wildcard backup behind an operator gate.** Per-name stays the default and the ONLY
   ungated path. A wildcard requires (a) a DISTINCT grant `type` (`cert_grant_wildcard`) the authority
   explicitly mints — unreachable by editing a per-name request, since `GrantVerifier` pins
   `type == cert_grant` (`GrantVerifier.php:47`, verified), and (b) a per-domain
   `wildcard_backup_approved: true` flag. `assertCertCoversOnly` is generalized to accept `[$fqdn]` OR
   `["*.$domain"]` *only* for the wildcard kind, preserving the refuse-unexpected-name net. Fallback
   SELECTION is mechanical: the client tries per-name first; only when per-name is unavailable does it fall
   back to the pre-approved wildcard.
3. **DDNS — moving-node support.** A node's held `cert_grant` already proves it owns `<name>.<domain>`, and
   its request-signing identity proves it's the same peer. Add a CLIENT loop (`mesh:ddns-update`) that POSTs
   a signed, nonce-fresh `ddns_update` to a NEW broker endpoint that re-runs the SAME grant+sig+nonce checks
   (`GrantVerifier.php:67-92`) then calls `upsertAddressRecord` ONLY — no cert, so no LE budget consumed.
   The endpoint is a sibling of `/cert-request` (`routes/federation.php:94`) under `federation.signed`.
4. **DNS PROVIDER abstraction.** Mirror the existing `AcmeProvider` seam (`AcmeProvider.php:12`): a
   `DnsProvider` interface, a `CloudflareDnsProvider` lifting the current static `Cloudflare`, and STUB
   `Route53`/`DigitalOcean`/`Manual` impls that throw a clear "not yet implemented" `BrokerError`. Selection
   is per-domain config (`dns_provider`, default `cloudflare`), resolved fail-closed like `acme.provider`
   (`InMeshBrokerService.php:110-118`).

**LE-limit awareness (the real scaling ceiling, currently invisible).** The `issuances` ledger already
carries `fqdn, domain, issued_at` (`Store.php:34-45`) — the substrate for a zero-cost pre-flight: count
issuances per domain/7d (default 50, the LE registered-domain weekly limit) and per exact fqdn/7d (default
5, the duplicate-cert limit); refuse with a 429 + remediation ("wildcard backup or add a second
domain/provider") BEFORE burning the ACME attempt. Make limits config so a future provider/account can tune
them. **Multi-domain + multi-provider + the wildcard backup are the horizontal scaling valves** the
pre-flight tells the operator when to reach for.

### 2.4 Live-app role-assumption dev tools — walk a role-combo, feed SOPs

The operator needs to **assume a COMBINATION of roles and walk the actual processes that combo unlocks**, to
test flows and write SOPs. Two planes, each with a different "assumption" mechanism — and the codebase
already has the load-bearing primitive for each. Per the settled decision, these tools live in the **LIVE
app** (impersonation), not the static mockup.

**Plane 1 — Citizen roles (R-01…R-30) are DERIVED, never stored.** `RoleService::derive()` is a pure
function of authoritative facts, recomputed per-request (`RoleService.php:92-141,163-329`); Art. I forbids
any condition between R-03 and R-04 (`RoleService.php:148-159`). **You cannot "assign" a citizen role** — the
only honest assumption is to *become a user who already derives it*, via the existing `LoginAsController`
(`LoginAsController.php:33-59`) or `ImpersonationController` (`abort_unless is_operator`, audited to module
`dev`, `ImpersonationController.php:66-124`). The walk-through engine is the **surface registry**: intersect
the assumed R-xx set against all 56 surfaces' `roles` (`surfaces.php:32-59`, `SurfaceMeta.php:45-62`) +
`nav.js` `enabledRoles`/`prereq` (`nav.js:90-118`) — *no new gating logic*, since the sidebar already does
`roles ∩ enabledRoles` (`nav.js:19-23`).

**Plane 2 — Operator/capability roles** live on a DIFFERENT guard (`auth:operator` over
`operator_accounts`, `config/auth.php:44-51`) and the channel vocabulary. "Assuming" a named-role combo here
is NOT impersonation (channels are an instance property) — it's a **READ-ONLY DRY-RUN PREVIEW**: group
`MeshGateService::channels()` (`MeshGateService.php:142-194`) by the 4 roles and list the
establish/request/approve actions (`web.php:765-785`) each role's channels would drive, plus SOP steps.

**The unifying surface:** one dev page `/dev/role-lab` (in the existing `dev` route group, double-locked by
`app()->environment('local')` + `DevToolsEnabled`, `DevToolsEnabled.php:24-32`, `web.php:703-730`) with two
tabs. Tab 1 (Citizen): R-xx combo picker → resolve/synthesize a matching user → impersonate → live
walk-through of unlocked surfaces with deep-links. Tab 2 (Operator): pick a named-role combo → dry-run
projection of channels + gates + console actions + SOP steps. Both emit a copyable "process inventory" per
combo. **Critical plane-wall rule:** R-xx and channel slugs stay in separate panes and an operator-channel
"assumption" must never mutate a citizen role or vice-versa (`InstanceCapability.php:14-16`).

### 2.5 Mesh HA reach-across — the Record Keeper serving the network

**Problem in one sentence:** a client wants a service the local node cannot serve (population rasters /
large geodata — the Record Keeper's payload) and the mesh must transparently find another node that holds
that capability, rank candidates by health+latency, and redirect/proxy — WITHOUT ever changing who is
authoritative.

**Everything exists except the composition itself:** capability discovery by slug
(`CapabilityService::holdersOf`, `CapabilityService.php:120`), the per-peer failover ladder with
circuit/latency health (`TransportEndpoints::forPeer`, `TransportEndpoints.php:40,129`), the multiplex
dialer with circuit breaking (`MultiplexClient::reach` → `NoSurvivingTransport`, `MultiplexClient.php:41-78`),
latency/distance ranking for client entry (`NearestNodeService` + `/api/mesh/nearest`,
`MeshRoutingController.php:25`), origin-signed dataset descriptors (`GeodataManifestService`,
`GeodataController.php:20`, `routes/federation.php:106`), and the untouched authority axis
(`AuthorityResolver::authorityFor` reads ONLY `authoritative_server_id`, `AuthorityResolver.php:38`).

**The gap:** `holdersOf` ranks by a static capability priority only — a dead-but-high-priority holder sorts
first and the client pays the timeout. And the only consumer of capability-routed reach today is the broker
cert channel; geodata/raster serving has no mesh-reach path at all (`RasterTileController` serves PURELY
local rasters and returns transparent tiles forever on a node with none, `RasterTileController.php:91`).

**Key decisions (within the settled 4-role model):**
- **D1 — one composition seam, `ServiceReachService`**, sibling to `InMeshBrokerService`; nothing below it
  changes. It refuses governed channels — it serves ONLY the self-asserted copy channels (`mirror`/`etl`).
- **D2 — ranking = capability-holders ∩ health ∩ latency/distance**, reusing both existing ranking
  mechanisms (`TransportEndpoints` health, `NearestNodeService` geo tiebreak / cold-start). Add a
  health-aware `CapabilityService::holdersOfRanked`.
- **D3 — 307 REDIRECT-first, PROXY-fallback.** For byte-heavy raster/dataset traffic, answer
  `307 Temporary Redirect` (method-preserving, `no-store`, re-resolves health each request) to the chosen
  holder's best clearnet URL + the dataset's signed-manifest sha256 for client integrity. Proxy via
  `MultiplexClient` is the fallback ONLY for callers that can't cross origins, with a hard size ceiling —
  multi-GB proxying is out of scope.
- **D4 — the authority boundary is absolute.** Reaching a Record Keeper is a READ of public, license-bound,
  origin-signed data; it confers NO authority and is NOT a write. Integrity rides the origin-pinned manifest
  signature, so a hostile holder can at worst serve garbage that fails the sha256 check.
- **D5 — degrade safely.** No reachable holder ⇒ a typed `NoReachableHolder` (sibling of
  `NoSurvivingTransport`); callers fall back to existing local behavior (transparent tile), never a 500.
  CLK-20's `probeUnhealthy` re-floats recovered holders with no new scheduler.
- **D6 — console = the 4-role grouping.** The Record Keeper role card shows the ranked reachable holders
  ("geodata served by N peers, nearest healthy = X"), never raw slugs.

---

## 3. Sequenced build table (★-numbered, all domains)

Effort: **S** ≈ ≤½ day, **M** ≈ 1–2 days, **L** ≈ 3+ days / touches a protected/trust surface.
Recommended order is dependency-driven. The single `config/mesh_roles.php` (★1) is the keystone consumed by
the console, the dev tool, and SOPs — build it first.

| ★ | Item | Where | Effort | Depends on |
|---|---|---|---|---|
| **★1** | **`config/mesh_roles.php` RoleCatalog** — the 4 named roles → channel-set mapping + duty/"what"/console-action/SOP copy. The ONE source of truth for console + dev-lab + SOPs. | `config/mesh_roles.php` (new, beside `config/mesh_channels.php`) | S | — |
| **★2** | **`MeshGateService::roles()`** — fold per-channel state into a per-role rollup (reads ★1; does NOT re-probe). Leave `evaluate()`/`channels()` byte-identical. | `app/Services/Federation/MeshGateService.php` | M | ★1 |
| **★3** | **Role-aware adopt/revoke orchestration** — batch over the existing single-channel flow (`registerSelf` / `request()` per channel; `revoke()` to drop; handoff = adopt-then-revoke). No new vote math. | `MeshRoleGrantService.php` or a thin `MeshRoleOrchestrator` | M | ★1, ★2 |
| **★4** | **Controller role props + role-level endpoints** — add `roles_named` (= ★2 rollup), `roles.active`, `fresh` to `show()`; add adopt-role/drop-role POSTs delegating to ★3 (reuse the per-channel approve+ratify path). | `FederationConsoleController.php`; `routes/web.php:767-773` | M | ★2, ★3 |
| **★5** | **Console Tier 1: 4 named role cards** — friendly label + duty + rollup pill + one primary CTA; plain channel-state pills; replaces today's Ed25519/FF&C header. CTAs reuse existing establish/request handlers. | `resources/js/Pages/Jurisdictions/Federation.vue` | M | ★4 |
| **★6** | **"Advanced" progressive-disclosure container** — wrap existing sections (`186-785`) + identity server_id/key-fp in a default-collapsed toggle (localStorage). Move, don't delete; handlers/routes unchanged. | `Federation.vue` | M | ★5 |
| **★7** | **"Peers & sync at a glance" rollup line** — one green/amber/red health line from `evaluate()` gates + "N trusted peers, last sync …"; replaces the 4 raw tables in the default view. | `Federation.vue` | S | ★5 |
| **★8** | **First-run Welcome Wizard** — name → pick role (Record Keeper recommended) → role-specific setup → done; reuses existing join/establish/request/credentials POST routes. Copy must state governed roles can only be *requested*. | `Federation.vue` (`<FirstRunWizard>` on `props.fresh`) | M | ★4, ★5 |
| **★9** | **Plain-language copy pass + CLI-hint relegation** — Tier-1 strings rewritten for a CMS/wizard user; CLI hints survive only as Advanced footnotes. Consider the `design:ux-copy` skill on the duty strings. | `Federation.vue`; `config/mesh_roles.php` | S | ★5, ★6 |
| **★10** | **`mesh:role` CLI role verbs** — add `roles` / `adopt <role>` / `drop <role>` delegating to ★3; existing per-channel verbs untouched. | `app/Console/Commands/MeshRoleCommand.php` | S | ★3 |
| **★11** | **Constitutional pin: role↔channel mapping + rollup derivation + Meter-B supersession** — follow `MeshRoleBoardTest` LivePgConnection pattern. | `tests/Constitutional/MeshNamedRoleTest.php` (new) | S | ★2, ★3 |
| **★12** | **DNS-then-cert reordering + `DnsProvider` seam** — A-record step 1; hard-fail before ACME when `target` present and write fails; `CloudflareDnsProvider` lifts static `Cloudflare`. | `services/mesh-cert-broker/src/Broker.php:23-66`; new `src/Dns/DnsProvider.php` | M | — |
| **★13** | **`DnsProvider` stubs** (Route53/DigitalOcean/Manual) — throw clear "not yet implemented"; Manual returns the record to set by hand; `dns_provider` config (default cloudflare). | new `src/Dns/{Route53,DigitalOcean,Manual}DnsProvider.php`; `Config.php` | S | ★12 |
| **★14** | **LE rate-limit pre-flight** on the issuance ledger — count per domain/7d (50) and per fqdn/7d (5); refuse 429 + remediation BEFORE ACME; limits in config. | `Store.php:34-45`; `Broker.php`; `Config.php` | S | ★12 |
| **★15** | **DDNS server endpoint** (A-record-only, grant-gated, no cert) — re-run `GrantVerifier` grant+sig+nonce checks, then `upsertAddressRecord` only; sibling of `/cert-request` under `federation.signed`; Box C `?op=ddns` branch. | new `DdnsUpdateController.php` + `routes/federation.php`; `Broker.php` updateDns | M | ★12 |
| **★16** | **DDNS client process** (`mesh:ddns-update`) — detect public IP, POST signed nonce-fresh body to ★15; reuses `CertClientService` signing path; cron/loop friendly; no new credential. | new `app/Console/Commands/MeshDdnsUpdateCommand.php` | M | ★15 |
| **★17** | **Wildcard backup: distinct grant kind + operator approval gate** — accept `cert_grant_wildcard` + `*.<domain>` ONLY for the wildcard kind in `GrantVerifier`/`assertCertCoversOnly`/`CertGrantService`; per-domain `wildcard_backup_approved`. **Touches the PROTECTED trust core — adversarial selftests required.** | `GrantVerifier.php:47,95-106`; `Broker.php:69-94`; `CertGrantService.php:49-66`; config | L | ★12, **Open Decision #4 + #6** |
| **★18** | **Wildcard fallback selection in the cert client** — try per-name first, fall back to the pre-approved wildcard only when per-name unavailable; `--wildcard`/auto-fallback flag. | `CertClientService.php`; `MeshRequestCertCommand.php:25-29` | M | ★17 |
| **★19** | **Lazy (grant-rechecking) renewal + broker console surfacing** — renewal re-runs the grant check, respects the 5-duplicate/name limit; console shows per-domain budget headroom, wildcard/DDNS state under the Identity Broker card. | new `mesh:renew-cert` or extend `MeshRequestCertCommand`; `Federation.vue:265-297` | M | ★5, ★14, ★15 |
| **★20** | **`CapabilityService::holdersOfRanked`** — health-aware holder discovery (best-ladder health → latency_ema → geo distance); leave `holdersOf` untouched for existing callers. | `app/Services/Federation/CapabilityService.php` | S | — |
| **★21** | **`ServiceReachService` + `NoReachableHolder`** — the capability→best-reachable-holder composition seam; refuse governed channels (copy channels only); typed safe-degrade result. | new `app/Services/Federation/ServiceReachService.php` + `NoReachableHolder.php` | M | ★20 |
| **★22** | **307 HA-redirect + signed-manifest integrity hop** — on a node with no local rasters, `307` to the best reachable holder + manifest sha256; fall back to transparent tile (never 500); proxy fallback with size ceiling. | new `ServiceReachController.php` + route; `RasterTileController.php:91`; `GeodataController` | M | ★21 |
| **★23** | **Record Keeper reach panel on the Role Board** — read-only ranked reachable holders (server_id, transport, latency_ema, distance) under the Record Keeper card. | `MeshGateService.php:142`; `Federation.vue` | M | ★5, ★21 |
| **★24** | **`mesh:reach` CLI** — `mesh:reach geodata [--jurisdiction=ID]` prints ranked reachable holders + chosen rung + redirect/proxy decision; feeds SOP writing. | new `app/Console/Commands/MeshReachCommand.php` | S | ★21 |
| **★25** | **ServiceReach test pins** — governed-channel reach refused; ranking order; dead-holder skip; safe-degrade-not-500; 307 carries signed manifest; reach never mutates `authoritative_server_id`. | new `tests/Feature/Federation/ServiceReachTest.php` | M | ★21, ★22 |
| **★26** | **Role-combo resolver (citizen plane)** — find existing users whose derived roles superset-match a target R-xx set; pure lookup, never writes a role. | new `app/Services/Dev/RoleComboResolver.php` | M | — |
| **★27** | **Citizen-plane walk-through projector** — intersect an R-xx set against the 56 surfaces' `roles` + nav `enabledRoles`; output the ordered SOP inventory. No new gating logic. | new `app/Services/Dev/SurfaceWalkProjector.php` | M | ★26 |
| **★28** | **Operator-plane named-role dry-run projector** — group the channel projection by role, list each channel's state/gates + establish/request/approve actions + SOP steps. READ-ONLY. | new `app/Services/Dev/OperatorRoleWalkService.php` | M | ★1, ★2 |
| **★29** | **Role Lab page — two-tab combo picker + walk-through UI** — Tab 1 Citizen (resolve → impersonate → unlocked-surface deep-links), Tab 2 Operator (dry-run); "Copy SOP inventory"; R-xx and slugs in separate panes; double-locked dev route. | new `resources/js/Pages/Dev/RoleLab.vue`; `web.php:704-720`; link from `DevBar.vue` | L | ★27, ★28 |
| **★30** | **Combo-aware impersonation return + active-combo banner** — extend the `auth.impersonating` shared prop with the assumed-combo label + "back to Role Lab" link. | `HandleInertiaRequests.php:98-110`; `DevBar.vue` | S | ★29 |
| **★31** | **Synthetic-fixture minting for unmatched combos** *(conditional — see Open Decision #8)* — mint a fixture user through the REAL engine paths so the combo genuinely derives; audited to module `dev`. Heaviest, pollutes the demo dataset. | new `app/Http/Controllers/Dev/RoleLabController.php`; reuse `ResidencyGrantController.php:123-145` | L | ★26, Open Decision #8 |
| **★32** | **Role Lab tests** — 404 when toggle off + non-local; requires `is_operator`; resolver never writes a role row; operator dry-run mutates nothing; plane-wall isolation. | new `tests/Feature/RoleLabTest.php` | M | ★29 |

**Recommended phasing:**
1. **Foundation (★1–★2):** the role catalog + rollup derivation — keystone everything else reads.
2. **Console + roles model (★3–★11):** the settled UX simplification, the largest user-visible win.
3. **Broker DNS-first (★12–★16):** independent of the console; can run in parallel with phase 2. Ship the
   safe pieces (reorder, providers, DDNS, rate-limit) before the trust-core wildcard.
4. **Wildcard (★17–★19):** GATED on Open Decisions #4 and #6 — do not start until the operator rules.
5. **Mesh HA reach (★20–★25):** the Record Keeper's serving story; depends only on ★5 for the console panel.
6. **Dev role-lab (★26–★32):** consumes ★1; can start once the catalog lands; ★31 conditional.

---

## 4. Consolidated OPEN DECISIONS (deduped across all sections)

The operator must rule on these before the affected build items start. (Per project norms, each is surfaced
as a flag — none reverses a settled decision.)

1. **Where the role→channel map lives.** This design adopts **one `config/mesh_roles.php`** (catalog parity
   with `config/mesh_channels.php`), resolving the `mesh_roles.php` vs `operator_roles.php` naming collision
   between sections. *Confirm the single filename.* (Blocks ★1.)

2. **Archivist's lift isn't purely a channel set.** RW authority is the Art. V §7 governed *petition*
   (`FederationConsoleController.php:275`), not one of the 9 channels — it flips `authoritative_server_id`, a
   different mechanism. *Recommend: the Archivist role card GROUPS the existing RW-petition flow alongside
   `client.serve`* (surface both under one role) rather than inventing an RW pseudo-channel. (Affects ★1, ★5.)

3. **Multi-channel adoption with mixed consent legs.** Identity Broker spans 2 peer-subtree channels
   (Meter C) + 2 that don't, so "adopt role" fans out to N proposals with different meter requirements.
   *Recommend: present one "adopt role" action with a per-channel progress strip; no change to per-proposal
   consent math.* (Affects ★3, ★5.)

4. **The wildcard reality gap (highest-priority flag).** The README (`README.md:5`) and GATE-2.5b copy say a
   peer asks for a `*.<domain>` cert and the operator's memo says "per-name + wildcard certs issued" on Box
   A — but the in-tree code refuses wildcards by construction (**verified**: `GrantVerifier.php:47,95-106`,
   `Broker.php:69-94`). Either a wildcard was issued by a path not in this tree (e.g. a manual `lego` run) or
   the gate-green claim is ahead of the code. *Confirm before building ★17* — it determines whether wildcard
   is net-new (this design assumes net-new + gated).

5. **DNS hard-fail contract change.** DNS-then-cert with a hard-fail on A-record write changes today's "DNS
   failure never voids the cert" contract (`Broker.php:36`). No-target path unchanged; *for the target path,
   confirm hard-fail (proposed — saves LE budget, avoids a cert pointing at nothing) vs. best-effort.*
   (Affects ★12.)

6. **Wildcard consent bar + blast radius.** A `*.<domain>` cert is a domain-wide blast radius. The
   per-domain `wildcard_backup_approved` gate limits WHICH domains allow it; *should a wildcard ALSO require
   a higher consent bar (e.g. seated-gov Meter B even on the bootstrap operator path)?* (Gates ★17.)

7. **DnsProvider scope vs. ACME DNS-01.** A non-Cloudflare `DnsProvider` for the A record does NOT make
   `lego`'s DNS-01 challenge work for that provider (separate flag/credential, `LegoAcmeProvider.php:39`).
   *Confirm: do the non-CF stubs cover only the A-record/DDNS path initially (cert issuance stays
   Cloudflare-only), or is full per-provider DNS-01 in scope?* (Scopes ★13.)

8. **DDNS frequency vs. provider rate limits.** A record is written at `ttl=120` (`Cloudflare.php:23`);
   Cloudflare allows ~1200 req/5min/account. *Confirm a 120s TTL + per-IP-change update is acceptable, or
   add a minimum-interval / change-detection debounce in the client.* (Affects ★16.)

9. **Synthetic-fixture minting (the heaviest dev-tool piece).** Auto-minting users for unmatched combos
   risks polluting the demo dataset / audit chain. *Recommend: restrict combo-assumption to EXISTING users
   first (lean on `elections:demo` / `institutions:demo-*`) and merely REPORT "no user derives this combo —
   run demo-X", deferring auto-minting (★31).* (Gates ★31.)

10. **First home for the 4 named roles.** This design builds the catalog (★1) and the console grouping
    (★5) from the same `config/mesh_roles.php`, so the dev role-lab consumes the same source — no ordering
    conflict. *Confirm config-first is the intended source-of-truth home* (vs. building the grouping into
    the console and having the dev tool consume the controller).

11. **Operator-plane "assume a combo" = dry-run only.** Channels are instance-wide, so true per-session
    operator impersonation is impossible without actually establishing channels. *Confirm a read-only
    dry-run preview is sufficient for SOP writing*, or whether the operator needs a throwaway dev instance
    to walk the real establish→request→approve→join flow end to end (in which case ★28/★29 should deep-link
    to the real `/federation/roles/*` actions rather than only previewing). (Affects ★28, ★29.)

12. **First-run wizard dismissability + operator bootstrap.** *Confirm the wizard is re-openable from the
    console (recommend a persistent "Run setup again" link in Advanced) vs. one-shot*; and *confirm whether
    first-run should bootstrap the operator account or assume the deploy command already created one* (most
    actions are operator-guarded, `Controller:164`; founder flow already sets `is_operator`,
    `SetupController.php:262-269`). (Affects ★8.)

13. **Mesh-reach: `geodata` slug vs. reuse `etl`.** *Recommend reusing the existing self-asserted `etl`
    (and `mirror`) channels rather than adding a `geodata` slug to the closed `CHANNELS` vocab* (verified
    9-slug list, `InstanceCapability.php:23`) — adding a slug churns the DB CHECK + every
    `recordPeerCapabilities` path. Touches role naming, so needs confirmation. (Affects ★20–★23.)

14. **Mesh-reach: redirect vs. proxy default + onion scope.** *Recommend 307-first with a proxy fallback +
    hard byte ceiling; confirm the ceiling and whether onion-only/strict-origin clients are in scope for
    v1* (a 307 leaks the peer's clearnet address; proxying puts bytes on the PHP path). (Affects ★22.)

15. **Mesh-reach: integrity granularity + cold-start + enumeration posture.** (a) Static dataset bundles are
    fully manifest-verifiable; live dynamic tiles can only verify the holder hosts the named dataset version
    — *recommend (a) fully integrity-checked, (b) best-effort.* (b) *Confirm geo distance is the accepted
    cold-start proxy for an untried holder's latency* (it already is for client-entry) vs. one warm-up
    probe. (c) *Confirm capability-holder enumeration may be public like `/api/mesh/nearest`*, or whether
    geodata reach should require `federation.signed`. (Affects ★21, ★22.)
