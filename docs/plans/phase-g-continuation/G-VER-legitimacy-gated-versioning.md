# DESIGN — Peer Version Tracking & the Constitutional Upgrade-Agreement Protocol

**Status:** Design round. No code written, no files edited. Read-only.
**Slug:** `peer-version-upgrade-agreement`
**Constitutional posture:** the *upgrade-consent mechanism* is HARDENED (it protects the franchise the same way ballot secrecy is hardened); the *thresholds and the per-jurisdiction flag set* are POLICY (amendable, founder/legislature-authored).

---

## 0. The problem, stated precisely

Today an instance has exactly one version surface: `config('cga.schema_version', '1')` ([config/cga.php:71](config/cga.php), `InstanceIdentityService::handshakePayload()` stamps it at [InstanceIdentityService.php:174-184](app/Services/Federation/InstanceIdentityService.php)). It is exchanged at handshake and stored only as loose metadata: `PeerService::discover/upsertTrustedPeer` drops it into `FederationPeer.metadata['schema_version']` ([PeerService.php:69,175](app/Services/Federation/PeerService.php)) and never reads it back to gate anything. **No peer tracks an *app/code* version, no peer negotiates an upgrade, and a `migrate:fresh` / redeploy is the de-facto upgrade path** — every deploy-hardening round in memory (db295eb and predecessors) is a *code* change with no recorded version transition and no peer agreement.

The danger this design closes: an operator pushes code that changes a hardened computation — STV transfer rounding, the supermajority formula, the finalist-cutoff ordering in `ApprovalService::rollupRace` ([ApprovalService.php:210-218](app/Services/ApprovalService.php)) — **while an election or other constitutional process is live**, silently re-ruling a contest already in flight. The constitution forbids exactly this: emergency powers "cannot disrupt the Legislative, Judicial, or **Electoral** process" (Art. II §7, [fair_constitution.md:128](docs/extracted/fair_constitution.md)); an upgrade is a far more total intervention than an emergency power and must be held to at least the same standard.

---

## 1. Constitutional anchor: operators ARE the election board (R-08), in bootstrap mode

The roadmap calls operators "a de-facto election board." That maps to a real, already-modeled role with a precise anchor:

- **R-08 Election Board Member** — "An independent, politically neutral officer responsible for election administration, boundary drawing, and **vote certification**," grounded in **Art. II §2 — Establish Independent Election Boards**: boards are "responsible for ensuring that boundaries... are drawn equally, contiguously, and fairly as well as for **administrating a transparent and public election process**" ([roles_forms_chart.md:14,50](docs/extracted/roles_forms_chart.md); [fair_constitution.md:72-73](docs/extracted/fair_constitution.md)).
- **The bootstrap note is the exact bridge** (roles_forms_chart.md:208): *"For the very first election, **the system acts as the election board**. The first legislature then creates a proper election board."* The operator who controls the infra **is** the system before a jurisdiction has seated a government to appoint a real R-08 board. So: **before activation/seating, the operator stands in the constitutional shoes of the election board; after, the seated institutions hold the gate.** This is the same posture `AuthorityFlipService` already takes — it is "gated to an operator at the caller (CLI/console), rather than a citizen-filed catalog form" ([AuthorityFlipService.php:14-17](app/Services/Federation/AuthorityFlipService.php)).
- **The neutrality clause is the live rail.** Art. II §2 demands the board be **independent and politically neutral** and run a process "where all factions can observe and audit" ([fair_constitution.md:64-65,72](docs/extracted/fair_constitution.md)). That means an operator-board's upgrade act is *not* discretionary fiat — it must be **transparent (audit-chained), observable (published), and procedurally bound (cannot disrupt a live process)**. The whole protocol below is the software expression of "politically neutral election administration."

This is also a clean, faithful reading and not an overreach: an upgrade is *election administration* (it changes how votes are counted, how seats are apportioned, how supermajorities are computed). It is squarely Art. II §2 board territory, not Art. VI §3 "legitimacy" territory — and like the legitimacy exploration ([achievements-legitimacy.md:47-60](../../explore-achievements/docs/plans/explorations/achievements-legitimacy.md)) **this design explicitly disclaims Art. VI §3**: an operator-board has no wartime-allegiance authority, only neutral-administration duties.

---

## 2. The peer-version model

### 2.1 Three version axes (today there is one, conflated)

| Axis | What it gates | Source today | Why it must be distinct |
|---|---|---|---|
| **`schema_version`** (wire/canonical-JSON shape) | FF&C sync hash agreement; cross-instance `canonicalJson` byte-equality | `config('cga.schema_version')` ([config/cga.php:64-71](config/cga.php)) | A peer mismatch breaks *replication*, not *governance*. Already used (informally) to refuse sync. |
| **`constitutional_version`** (hardened-compute version) — **NEW** | Whether two instances *count the same election the same way*: STV/Droop transfers, RCV, `ConstitutionalValidator::supermajority`, finalist cutoff, Webster apportionment | not tracked anywhere | This is the one that, if it changes mid-game, re-rules a live contest. The protocol's center of gravity. |
| **`app_release`** (code/migration tag) — **NEW** | Operational provenance (which commit/migration set is deployed); the human-readable thing operators reason about | `git` only, off-chain | Lets the audit trail say *which code* a jurisdiction's elections ran under, the way `AuthorityClaim` records *which instance* held authority. |

The `constitutional_version` is the load-bearing addition. It is **derived, not declared**: a build-time function hashes the hardened surface (the `app/Domain/Counting/*` core, `VoteCountingService`, `ConstitutionalValidator::supermajority`, the Droop/Gregory/Webster constants, the `RIGHTS_AUTOMATIC_FORMS` set, the CLK-01…CLK-21 registry shape from [ClockRegistrySeeder.php](database/seeders/ClockRegistrySeeder.php)). Mirrors the existing "executable constitutional law" idea (architecture_plan.md §6.2): the test suite *is* the constitution, so the **hash of what the suite pins** is the constitutional version. Two instances with the same `constitutional_version` provably count identically; a bump means a hardened rule moved and demands agreement.

### 2.2 Where it lives (additive only — never touch `audit_log`/`ballots`/`jurisdictions` migrations)

Following the established additive discipline (roadmap §2.2, "new tables only; existing tables evolve by nullable columns"):

- **`instance_settings`** gains nullable columns (new migration, the pattern of `2026_07_01_000001_add_federation_identity_to_instance_settings.php`): `constitutional_version` (varchar), `app_release` (varchar), `version_pinned_at` (timestamptz). `InstanceSettings::current()` is the singleton accessor ([InstanceSettings.php:80-88](app/Models/InstanceSettings.php)); add `constitutionalVersion()` alongside the existing `isMirror()` helper.
- **`FederationPeer`** gains nullable `constitutional_version` + `app_release` columns (promoted out of the loose `metadata` blob where `schema_version` currently hides at [PeerService.php:69](app/Services/Federation/PeerService.php)). `handshakePayload()` ([InstanceIdentityService.php:174](app/Services/Federation/InstanceIdentityService.php)) starts carrying all three; `upsertTrustedPeer` pins them.
- **`peer_upgrade_proposals`** — NEW table, the upgrade equivalent of `partition_exports`/`union_processes`. Tamper-evident **audit_log pattern** (no `deleted_at`/`updated_at`, BEFORE-UPDATE/DELETE immutability trigger — the same `audit_log_block_mutation()` the legitimacy snapshots reuse). Columns: `id` uuid · `kind` (`constitutional_bump`|`schema_bump`|`app_release`) · `from_constitutional_version` / `to_constitutional_version` · `from_app_release` / `to_app_release` · `proposed_by_server_id` · `signature` (Ed25519 over the canonical proposal, like the partition manifest) · `affected_root_jurisdiction_id` (subtree scope, mirroring `AuthorityFlipService`) · `status` (`open`/`ratified`/`rejected`/`superseded`) · `ratified_at`. **Cross-references** to the per-jurisdiction gate rows below.
- **`peer_upgrade_consents`** — NEW, mirrors `ConstituentConsent` ([MultiJurisdictionVoteService.php:70-76](app/Services/MultiJurisdictionVoteService.php)): one row per consenting authority (a board/operator or a seated institution) with `result` (pending/yes/no), `decided_at`, optional `chamber_vote_id` / `mjv_process_id` linking the *constitutional* leg.

---

## 3. The operator/board agreement protocol (mirror the dual-supermajority/MJV machinery)

The existing dual-ratification pattern is the template, and it already comes in two flavors I will reuse verbatim:

1. **`MultiJurisdictionVoteService`** — the PROTECTED supermajority substrate. `open(kind, initiating, constituentIds, basis)` → required = `ConstitutionalValidator::supermajority(total)` (or `total` for unanimity) → `recordConsent` increments counters → `evaluate` flips status when `yes >= required` or when it can no longer be reached ([MultiJurisdictionVoteService.php:33-170](app/Services/MultiJurisdictionVoteService.php)). **All supermajority math routes through the protected `ConstitutionalValidator::supermajority` — never reimplemented.**
2. **The `LocalAutonomyService`/`UnionService` dual-meter envelope** — *two independent meters must both pass*: a population supermajority **AND** a constituent-jurisdiction MJV ([LocalAutonomyService.php:126-143](app/Services/Jurisdictions/LocalAutonomyService.php); [UnionService.php:113-129](app/Services/Jurisdictions/UnionService.php)). `finalize()` refuses unless **both** legs cleared, with a precise Article-cited error.

### 3.1 The new service: `PeerUpgradeAgreementService`

Structurally a sibling of `LocalAutonomyService` (same constructor shape — inject `MultiJurisdictionVoteService`, `AuditService`, and an `UpgradeFreezeService` per §4). Three meters, **all of which must pass** before an upgrade applies to a jurisdiction subtree:

- **Meter A — the operator/board attestation (the R-08 leg).** A signed, audit-chained operator action (CLI/console, exactly like `AuthorityFlipService`'s `$operatorUserId` gate, [AuthorityFlipService.php:41](app/Services/Federation/AuthorityFlipService.php)) declaring *this operator, as the election board for jurisdiction X (in bootstrap mode) or as the administrator executing a seated board's order, ratifies the move to constitutional_version Y.* This is the "system acts as the election board" bootstrap path made explicit and recorded. It is **transparent** (chained, `module='federation'` or a new `'upgrade'` module string — note `audit_log.module` is an app-validated string needing no migration, roadmap §6) and **neutral** (the proposal is a version diff, not a content choice).

- **Meter B — the seated-institution leg (post-bootstrap), via MJV.** The moment a jurisdiction has a *seated government* (the same `Legislature::STATUS_ACTIVE` test `LocalAutonomyService::open` already enforces, [LocalAutonomyService.php:47-53](app/Services/Jurisdictions/LocalAutonomyService.php)), the operator's bootstrap standing is **superseded** by the constituent institutions — exactly as the bootstrap note says ("the first legislature then creates a proper election board"). Meter B opens a `peer_upgrade` MJV over the affected constituent jurisdictions at `BASIS_SUPERMAJORITY`. This is the constitutional teeth: a hardened-rule change to a jurisdiction that has a real government **requires that government's supermajority consent**, the same arithmetic Art. VII demands for amendments ("a Supermajority of Constituent Jurisdictions or a Supermajority of The Legislature if there are no Constituent Jurisdictions," [fair_constitution.md:367](docs/extracted/fair_constitution.md)).

- **Meter C — peer mesh agreement (federation safety).** Every `trust_established` peer authoritative for a co-affected subtree records consent. A `constitutional_version` mismatch across the mesh means peers no longer count identically; the heartbeat ([FederationHeartbeatJob.php](app/Jobs/Federation/FederationHeartbeatJob.php), CLK-20) **stops pushing FF&C to a peer whose `constitutional_version` differs** (extending the existing schema-version refusal at [config/cga.php:64-71](config/cga.php)) until Meter C ratifies — fail-closed, the same posture as the ballot re-wrap "fails closed" rail (memory: G-V2).

**Why bump = Art. VII territory.** A `constitutional_version` bump that alters STV proportionality is *forbidden outright* by Art. VII §"Protection of Proportional Representation" ([fair_constitution.md:368-369](docs/extracted/fair_constitution.md): the default method "cannot be replaced with a voting method that decreases proportionality"; a supermajority "cannot be changed to be less than a majority"). So Meter A/B/C is the *consent* gate; on top of it sits a **hardened admissibility filter** (§5) that rejects a bump which would *decrease* proportionality or weaken the supermajority floor — no amount of operator+legislature+peer consent can pass it, mirroring how `ConstitutionalValidator` rejects sub-majority supermajority settings today.

### 3.2 Flow (one figure)

```
operator builds/deploys code  →  constitutional_version recomputed (derived hash)
        │
        ▼  if changed:
  PeerUpgradeAgreementService::propose(kind, fromV, toV, rootJurisdiction, sign)
        │   → peer_upgrade_proposals row (signed, chained)  → status=open
        │   → HARDENED admissibility filter (§5): reject if proportionality↓ / supermajority floor↓ (Art. VII)
        ▼
  ┌─ Meter A (R-08 operator/board attestation)  ── bootstrap-only standing
  ├─ Meter B (seated-legislature/constituent MJV @ supermajority)  ── once a govt is seated, REQUIRED, supersedes A
  └─ Meter C (peer-mesh consent over co-affected subtrees)
        │
        ▼  ratify() — refuses unless ALL applicable meters passed (LocalAutonomyService::finalize shape)
   apply upgrade to the subtree:
     instance_settings.constitutional_version := toV ; peer rows updated ; release the freeze (§4)
     audit: 'upgrade.ratified' on the hash chain  (transparent + observable, Art. II §2)
```

`ratify()` is byte-for-byte the `LocalAutonomyService::finalize` discipline: refuse-with-citation unless every required meter cleared, then apply inside `DB::transaction` and append the chain entry.

---

## 4. "Game in progress" — the freeze that makes this constitutional

This is the heart of the prompt. An upgrade must **never silently re-rule an active election** (or any live constitutional process). The mechanism:

### 4.1 What counts as "a game in progress" (a live constitutional process)

A jurisdiction subtree is **frozen for `constitutional_version` bumps** while *any* of these are live within it — each is already a queryable state in the built system:

- An **election not yet certified** — `Election.status` is anything before `F-ELB-004` certification ([ApprovalService::assertApprovalPhaseOpen](app/Services/ApprovalService.php:289-302) keys on `Election::STATUS_APPROVAL_OPEN`; the lifecycle runs through `ElectionLifecycleService`/`CertificationService`). Especially: approval phase open, ranked window open, tabulation queued-but-not-certified.
- An **open MJV / dual-supermajority process** — `MultiJurisdictionVote.status = OPEN` ([MultiJurisdictionVoteService.php:107](app/Services/MultiJurisdictionVoteService.php)): a union, autonomy, disintermediation, border, executive-conversion, or Art. VII amendment vote in flight.
- An **active emergency power** — `EmergencyPowerService` declaration within its CLK-03 window. (Directly Art. II §7's "cannot disrupt... civic process.")
- An **open judicial process** with a running CLK-11 veto / CLK-12 remedy window — re-ruling the counting engine mid-challenge would corrupt the Art. IV §5 path.
- An **open countback/special-election window** (CLK-04) — a vacancy being filled from prior results must use the *same* engine the original election used.

### 4.2 The freeze contract (`UpgradeFreezeService`)

- **Bumps are blocked, reads are not.** Like the mirror write-guard ([ConstitutionalEngine.php:90-102](app/Domain/Engine/ConstitutionalEngine.php)), the freeze does not stop the world — citizens keep voting, sessions keep meeting. It blocks exactly one thing: *applying a `constitutional_version` change to a subtree with a live process.* `ratify()` calls `UpgradeFreezeService::assertThawed(rootJurisdiction)` and throws a `ConstitutionalViolation` citing **Art. II §7** ("an upgrade cannot disrupt the Electoral/Legislative/Judicial process in flight") if anything is live.
- **Election-version pinning (the strongest guarantee).** When an election opens, stamp the *then-current* `constitutional_version` onto the election (a nullable `constitutional_version` column on the elections table — additive). The counting path (`VoteCountingService`/`ApprovalService::rollupRace`) is contractually required to **run under the pinned version, not the deployed version**, for the life of that contest. This is the "config is never data" lesson from `election_demo_compression` ([config/cga.php:38-50](config/cga.php): "no election row ever records that its windows were compressed... re-running restores constitutional timing") inverted: here the election *does* record its constitutional version, so a redeploy mid-count cannot change the rules under it. A countback (CLK-04/05) re-runs under the **pinned** version — the constitution's countback is literally "as if the vacated member was never a candidate in *the election*" ([fair_constitution.md:106](docs/extracted/fair_constitution.md)), i.e. the original election's rules.
- **`schema_version` and `app_release` bumps are NOT frozen** — they don't change *how a vote is counted*, only wire shape / provenance. They flow without the freeze (Meter C / sync-refusal handles schema; app_release is provenance-only). Only `constitutional_bump` hits the freeze. This keeps the deploy-hardening cadence (the db295eb-style operational fixes) unobstructed — those are `app_release` bumps with an unchanged `constitutional_version`.

### 4.3 Two operator escape valves, both bounded

- **Wait for the thaw (default).** The freeze auto-releases when the last live process closes; the proposal sits `open` and `ratify()` succeeds once `assertThawed` passes. No special action.
- **Scope-narrow.** Because the proposal carries `affected_root_jurisdiction_id` (subtree scope, like `AuthorityFlipService`), an operator can ratify the upgrade for **quiet subtrees now** and let busy ones thaw later — different jurisdictions can sit at different `constitutional_version`s transiently, which is *exactly* the federation model (`authoritative_server_id` already lets subtrees diverge in authority). The mesh-sync refusal (Meter C) keeps divergent subtrees from cross-counting until aligned.

There is deliberately **no "force" valve** for a `constitutional_bump` over a live election. Art. II §7's non-disruption clause is not waivable by an operator; the bootstrap election board has *administrative* power, never the power to disrupt a process in flight.

---

## 5. Legitimacy-flag gating (per jurisdiction, grounded in the exploration + master plan)

The prompt asks for upgrade consent **gated by legitimacy flags/thresholds per jurisdiction**. This is where Phase I's `legitimacy_snapshots` / `LegitimacyService::reachRatio()` ([achievements-legitimacy.md §3.2](../../explore-achievements/docs/plans/explorations/achievements-legitimacy.md); roadmap Phase I) becomes a **gate input** — but with the exploration's HARD RAILS strictly preserved.

### 5.1 The rail that constrains the entire gate

The legitimacy exploration's rails are non-negotiable and this design lives under them:
- **No governance advantage (CI-1):** a legitimacy flag may **never** weight or reorder a *vote, candidacy, seat, or franchise act* ([achievements-legitimacy.md:344-352](../../explore-achievements/docs/plans/explorations/achievements-legitimacy.md)).
- **One-person-one-vote untouched (CI-2):** `RoleService::derive` returns identically with/without legitimacy state.
- **Reach is enrollment, NOT the Art. VI §3 legitimacy verdict** ([:47-60](../../explore-achievements/docs/plans/explorations/achievements-legitimacy.md)), and it is **k-anon-floored / honestly-denominated** (`ratio_micro` NULL when un-ETL'd; `suppressed` below k).

**The reconciliation:** an upgrade is *not a governance act of the franchise* — it is *election administration* (R-08/Art. II §2), one meta-level up. So using reach to decide **which consent gate applies** does not give anyone a governance advantage; it does not touch any ballot, seat, or right. It only decides *how much consent an operator needs before changing the counting rules*. That is squarely board-administration, and it is the one legitimate, rail-respecting use of the reach signal. The flag never enters `VoteCountingService`, never enters `RoleService`, never weights a ballot — it only selects a *consent tier*.

### 5.2 The candidate legitimacy flags + thresholds (per jurisdiction)

These resolve through the existing settings cascade (`SettingsResolver`: own row → ancestor walk → registry default, the CLK-06 pattern) so they are **amendable POLICY**, authored at setup or by legislature, not hardcoded:

| Flag | Source / definition | Gate effect on a `constitutional_bump` |
|---|---|---|
| **`is_seated`** | a `Legislature::STATUS_ACTIVE` exists ([LocalAutonomyService.php:47](app/Services/Jurisdictions/LocalAutonomyService.php)) | **The pivot.** False ⇒ operator-board bootstrap standing (Meter A alone). True ⇒ Meter B (seated-legislature supermajority) becomes REQUIRED and supersedes A. Directly the bootstrap-note transition. |
| **`is_activated` (CLK-06)** | `jurisdiction_activations.state` / crossed the critical-population tier | Below activation, a jurisdiction is *chartered but empty* (roadmap Phase I); operator-board alone suffices — there is no electorate to consult yet. |
| **`reach_floor_met`** | `LegitimacyService::reachRatio()` ≥ an amendable `upgrade_reach_gate_pct` setting, **AND** `measurable=true` (denominator not NULL) **AND** not `suppressed` (≥ k) | A high-reach jurisdiction (many real verified residents) gets the **strictest** gate — a `constitutional_bump` there requires the full A+B+C with the seated-supermajority leg. A low-reach / unmeasurable / sub-k jurisdiction cannot meaningfully consult a population, so the gate **degrades to operator-board + peer mesh only** (never *more* permissive than the seated-legislature path would be). Reach raises the bar; it never lowers a constitutional floor. |
| **`live_process_present`** | the §4.1 freeze predicate | Hard block on `constitutional_bump` regardless of any other flag (Art. II §7). Not really a "tier," it's the freeze. |

**Threshold direction is monotone-safe:** more legitimacy ⇒ *more* consent required, never less. The worst a low-reach jurisdiction can do is fall back to the operator-board+mesh gate — which is still the full Art. II §2 transparent-and-neutral administration, just without a population that exists to ratify. This makes the gate impossible to game by *deflating* reach (the R2 denominator risk from the exploration): lowering your reach can only *weaken your own ability to consent*, never weaken the protection, and the hardened admissibility filter (§5.3) is reach-independent.

### 5.3 The reach-independent hardened floor (cannot be gated away)

On top of all consent tiers, **independent of any flag**, the `constitutional_bump` admissibility filter rejects (pre-commit, with an Art. VII citation, exactly as `ConstitutionalValidator` rejects sub-majority settings):
- a bump that the proportionality monotone-check says **decreases** proportional representation (Art. VII);
- a bump that lowers the supermajority below `majority+1` (Art. VII);
- a bump applied to a subtree with a `live_process_present` (Art. II §7).

No operator, no legislature supermajority, no peer quorum, and no reach value can pass these — they are the hardened layer, pinned by tests (the "executable constitutional law" posture, architecture_plan.md §6.2).

---

## 6. How it threads the existing invariants

- **Authority ≠ leadership** (roadmap §2.4): the upgrade gate reads `authoritative_server_id` (who *owns* a subtree) to scope Meter C; it **never** reads Patroni/cluster-leader state. Which node *runs* the migration is a data-tier concern; which jurisdictions *consent* is the constitutional one.
- **The protected triad never federates:** `peer_upgrade_proposals`/`consents` carry version strings + signatures + counts only — no ballots, no locations, no keys. They ride the public chain like `partition_exports`.
- **Reuse the engine, never fork it:** the supermajority math is `ConstitutionalValidator::supermajority` via `MultiJurisdictionVoteService`; the signing is `InstanceIdentityService::sign`; the operator gate is the `$operatorUserId` console pattern; the freeze read-guard mirrors the mirror write-guard. **No new crypto, no new vote math.**
- **Additive-only:** new tables (`peer_upgrade_proposals`, `peer_upgrade_consents`) + nullable columns on `instance_settings`, `federation_peers`, `elections`. `audit_log`/`ballots`/`jurisdictions` migrations untouched. `audit_log.module` gains the app-string `'upgrade'` (no migration).

---

## (a) Roadmap slot

This is a **Phase G sub-phase** — specifically a **new Track B increment, `G-VER` (peer version tracking + upgrade-agreement)** — *not* a new H–O letter. Rationale grounded in what I read:
- It is pure federation/mesh trust machinery, and Phase G is "Federated Adoption, Earned Autonomy & the Social Mesh" with `authoritative_server_id` as its axis (roadmap §3). `LocalAutonomyService` (the dual-meter pattern I mirror) is itself a **G6** artifact.
- It **depends on Phase I's `LegitimacyService`/`legitimacy_snapshots`** for the reach gate (§5). Since I is not yet built, **`G-VER` ships in two layers:** the version model + operator-board + MJV + freeze (all on built A–G substrate, no I dependency) land first; the `reach_floor_met` tier is a **thin, additive gate input wired when Phase I lands** (it degrades safely to the seated-legislature gate until then — the gate is *correct* without reach, just not reach-tiered).
- It is the natural companion to G-V2 (the real cross-machine peer onboarding gate, roadmap §3.1): the first time a *second machine* pulls code and joins, it will have its own `app_release`/`constitutional_version`, so the version-agreement surface is exactly what G-V2 exercises in the field.

Sequencing: **build `G-VER` core now (dev-stack testable, like every G increment); wire the reach tier after Phase I.**

## (b) Open decisions for the operator

1. **Derived vs. declared `constitutional_version`.** I recommend *derived* (a build-time hash of the hardened surface + pinned test set) so it cannot drift from reality. Alternative: a hand-maintained string in `config/cga.php` next to `schema_version`. Derived is safer but needs a canonical "what counts as hardened surface" manifest — your call on the exact file/symbol list to hash.
2. **Bootstrap operator-board standing — single operator, or N-of-M?** Today operator actions are single-actor CLI (`AuthorityFlipService`). For a *constitutional* upgrade, do you want a multi-operator quorum (e.g. 2 of the cluster's operators must co-sign Meter A) to better honor "independent board"? The MJV substrate supports it trivially; it's a policy choice.
3. **The `upgrade_reach_gate_pct` default** (§5.2). What reach ratio flips a jurisdiction from "operator-board+mesh" to "seated-supermajority required"? Recommend keying it to **seatedness primarily** (a seated legislature *always* gates, regardless of reach) and using reach only as a secondary "is there a population worth consulting" signal — but the numeric default is yours.
4. **Subtree-granular upgrade vs. whole-instance.** §4.3 lets different subtrees sit at different `constitutional_version`s transiently. Do you want that (maximally faithful to federation, but more states to reason about), or whole-instance-atomic (simpler, but a single busy election freezes the entire instance's upgrade)? I lean subtree-granular.
5. **Does a `schema_bump` ever need the freeze?** I argue no (it's wire-shape, not vote-math). If a future schema change ever co-changes a counted payload, that *is* a `constitutional_bump` by definition — confirm the two axes can never be conflated in your build pipeline.

## (c) Risks

- **R1 — Version-derivation false negatives (gravest).** If the `constitutional_version` hash misses a hardened symbol, a real vote-math change ships as a mere `app_release` and bypasses the freeze + agreement entirely — the exact silent re-ruling this design exists to prevent. *Mitigation:* derive the hash from the **same surface the constitutional test suite pins**, and add a CI pin that fails if a file under `app/Domain/Counting/` or `VoteCountingService`/`ConstitutionalValidator` changes without a `constitutional_version` bump (the suite is already the source of truth).
- **R2 — Reach denominator gaming feeds the gate.** The exploration flags `jurisdictions.population` as mutable/off-chain ([achievements-legitimacy.md R2](../../explore-achievements/docs/plans/explorations/achievements-legitimacy.md)). Here it's mostly defused by the **monotone rail** (§5.2: deflating reach only weakens *your own* consent, never the floor) and the reach-independent hardened filter (§5.3). Residual: an operator could *inflate* reach to *raise* the bar against themselves — harmless. Still, recommend the exploration's "audit event when `jurisdictions.population` changes" hardening so the gate input is tamper-evident.
- **R3 — Stuck upgrade / liveness.** A perpetually-busy jurisdiction (back-to-back elections) could indefinitely freeze a `constitutional_bump`. *Mitigation:* subtree-granular ratification (§4.3) localizes the stall; and a stuck *security* fix is itself an argument for the operator to schedule a quiet window — which is correct constitutional behavior (you don't change the rules mid-game), not a bug.
- **R4 — Operator-board neutrality is unenforceable in code.** Art. II §2 demands a *politically neutral* board; software can enforce *transparency and procedure* (audit chain, freeze, supermajority) but not an operator's *motive*. *Mitigation:* every meter is chained and published (observable "by all factions," Art. II §2); the seated-legislature leg (Meter B) removes operator discretion the moment a real government exists — the bootstrap standing is explicitly temporary, by constitutional design.
- **R5 — Mesh partition under version divergence.** Meter-C sync-refusal across `constitutional_version` mismatch could fragment a mesh mid-rollout. *Mitigation:* this is fail-*safe* (divergent instances simply stop cross-counting, never mis-count); the `schema_version`/`app_release` axes keep flowing so liveness/heartbeat (CLK-20) and provenance are unaffected — only FF&C of *counted* records pauses.
- **R6 — Phase-I coupling.** The `reach_floor_met` tier depends on an unbuilt phase. *Mitigation:* §(a)'s two-layer ship — the gate is constitutionally complete without reach (seatedness does the real work); reach is an additive refinement.

---

**Files/symbols this design builds on (all read, all real):** `config/cga.php` (`schema_version`); `app/Services/Federation/InstanceIdentityService.php` (`handshakePayload`, `sign`); `app/Services/Federation/PeerService.php` (`upsertTrustedPeer`, `metadata['schema_version']`); `app/Services/MultiJurisdictionVoteService.php` (`open`/`recordConsent`/`evaluate`, `ConstitutionalValidator::supermajority`); `app/Services/Jurisdictions/LocalAutonomyService.php` + `UnionService.php` (dual-meter `finalize`); `app/Services/Federation/AuthorityFlipService.php` (operator-gated subtree action, Ed25519 manifest); `app/Domain/Engine/ConstitutionalEngine.php:90-102` (mirror write-guard pattern for the freeze); `app/Models/InstanceSettings.php` (`current()`, `isMirror()`); `app/Jobs/Federation/FederationHeartbeatJob.php` (CLK-20); `database/seeders/ClockRegistrySeeder.php` (CLK-01…CLK-21 hardened surface); `app/Services/ApprovalService.php` (`rollupRace` count surface, the freeze-pin target). Constitutional anchors: Art. II §2 (election boards, neutrality, bootstrap note roles_forms_chart.md:208), Art. II §7 (non-disruption freeze), Art. VII (proportionality + supermajority floor). Legitimacy rails from `explore-achievements/.../achievements-legitimacy.md` (CI-1/CI-2, reach honesty, k-anon).