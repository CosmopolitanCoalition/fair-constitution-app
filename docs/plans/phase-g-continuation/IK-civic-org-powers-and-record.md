I have everything I need. The constitution at Art. II §2 ("Publish Public Records") explicitly mandates automatic publication of "statements made, bills presented, and votes cast with explanations" — which the `public_records` layer (kinds `statement`, `bill`, `vote`, `minutes`, `testimony`) already serves. I now understand the full picture: derived roles (never stored), term/seat lapsing, the engine + audit chain + public records, the org module, and the legitimacy exploration's hard rails.

Let me write the design.

---

# Design: Legitimacy-Gated Civic Powers/Duties + Automated Maintenance of the Official Record

## 0. Framing — what is actually being asked, and what the codebase already gives us

The prompt bundles two designs that share one engine. Reading the existing code, **both halves are already mostly built — what's missing is a thin, honest layer and a wiring discipline, not new machinery.** The single most important finding from the code:

**Roles are already "legitimacy-gated and auto-maintained" — by design, and constitutionally so.** `RoleService::derive()` ([app/Services/RoleService.php:163](app/Services/RoleService.php)) is a pure function of authoritative facts, recomputed on demand, **never stored**. A power "lights up" the instant the fact appears (a seat row, an `active` term, a `seated` board seat) and "lapses" the instant the fact changes — `VacancyService::declare()` flips a member to `vacated`, the term `status` to `vacated`, and calls `roles->flushUser()` ([VacancyService.php:109-157](app/Services/VacancyService.php)); `CivilTermExpiryJob` (CLK-09) expires a term and re-opens the seat ([CivilTermExpiryJob.php:35-54](app/Jobs/Clocks/CivilTermExpiryJob.php)). The `EnsureRole` middleware ([EnsureRole.php:26-32](app/Http/Middleware/EnsureRole.php)) and the engine's `authorize()` step ([ConstitutionalEngine.php:178-210](app/Domain/Engine/ConstitutionalEngine.php)) consult those derived roles per request. **This IS the "powers light up / lapse as term or seating changes" system the prompt describes** — fully live for R-01…R-30.

So the design has to be precise about a critical distinction the legitimacy exploration ([achievements-legitimacy.md](../../../explore-achievements/docs/plans/explorations/achievements-legitimacy.md)) hammers and that the codebase enforces:

- **"Legitimacy" in the constitutional / power-conferring sense = office legitimacy:** Do you actually hold a certified seat / a confirmed residency / an active appointment term? That is the *derived-role* system, and it already gates every power. This is HARDENED and **must never read the reach/achievements metric**.
- **"Legitimacy" in the exploration's sense = the reach ratio** (`verified_residents / population_estimate`) — an *enrollment signal*, explicitly **NOT** a power input (CI-1), explicitly **NOT** the Art. VI §3 verdict (R4).

The work is therefore:
1. **Formalize and surface the office-legitimacy gating that already exists** as one coherent model (a "civic agent power profile" and an "organization profile"), driven entirely by the existing derived-role + term/seat lapsing rails — adding only a thin read-model and a "lapse-sweep" safety net, **never** a new power gate.
2. **Build the reach/legitimacy metric** as flexible-layer chrome that decorates the profile **without** ever gating it (this is Phase I in the roadmap).
3. **Close the automation gaps in the official record** so debate/statements/votes/acts are published through the engine + `public_records` + `audit_log` with as little manual filing as possible.

---

## 1. The power/duty model: a derived "Civic Power Profile" (read-model over existing facts)

### 1.1 What exists, restated as a contract

Every power a civic agent can exercise is one of:
- an **engine form** (`F-*`) gated by `handler->requiredRoles()` vs `RoleService::rolesFor($actor)` ([ConstitutionalEngine.php:191-209](app/Domain/Engine/ConstitutionalEngine.php)), or
- a **route** gated by `role:R-xx` middleware ([EnsureRole.php](app/Http/Middleware/EnsureRole.php)).

Roles derive from facts that are **time-bounded and seating-bounded** already:
- `residency_confirmations.is_active` → R-03/R-04/R-05 (the absolute-rights floor)
- `legislature_members.status ∈ {elected,seated}` → R-09, and *through a current member row* R-10…R-13
- `terms.status='active'` + `appointments.status='seated'` → R-18/R-29/R-30 (10-yr CLK-09 lapsing)
- `judicial_seats.status='seated'` + `judiciaries.type` → R-19/R-20
- `board_seats.status='seated'` → R-26/R-27/R-28
- `executive_members` seat rows → R-14…R-17

**The lapse mechanics already exist** and are the answer to "kept up to date automatically":

| Trigger | Mechanism | Effect on powers |
|---|---|---|
| Seat vacated/removed | `VacancyService::declare()` flips status + `flushUser()` | R-09…R-13 drop atomically (derivation reads `CURRENT_STATUSES`) |
| Term expiry (10-yr) | CLK-09 → `CivilTermExpiryJob` → `expireGovernorTerm`/`expireJudicialTerm` | R-18/R-19 drop (seat → `term_ended`) |
| Residency lapses | `ResidencyService` deactivates confirmation + `flushUser()` | R-03/R-04/R-05 and everything downstream drop |
| Election certified | `CertificationService` seats winners | R-09 lights up |
| Speaker/chair vacates | `cascadeChamberOffices()` clears the pointer | R-10/R-12/R-13 drop |

### 1.2 The new piece: `CivicPowerProfile` (a derived value object, NOT a table)

The gap is that **there is no single place that answers "what can this agent do right now, and why, and until when?"** The roles are derived but scattered; the "until when" (term `ends_on`, election cycle) lives on separate rows; the UI has no unified power surface.

Design a **`App\Domain\Powers\CivicPowerProfile`** — a pure, derived read-model (same discipline as `RoleService::derive`: a static, DB-free assembler fed by fact queries), composed of **`PowerGrant`** value objects:

```
PowerGrant {
  role: string            // R-09
  power_codes: list        // the F-* forms + route capabilities this role unlocks (from the catalog)
  basis: string           // "Art. II §1 — certified election victory"
  source_fact: {type, id} // legislature_members:<uuid> — the row that confers it
  active_since: date       // seat seated_at / term started_at / confirmation confirmed_at
  lapses_at: date|null     // term ends_on (CLK-09), null = lapses on fact-change not clock
  lapse_trigger: enum      // 'term_clock' | 'vacancy' | 'residency' | 'election_cycle' | 'fact_change'
  status: 'active'         // (a profile only ever lists ACTIVE grants — a lapsed grant is simply absent)
}
```

Key properties, each grounded in existing rails:
- **It is 100% derived.** `CivicPowerProfile::for($user)` calls `RoleService::rolesFor()` for the role set, then joins the fact rows that already exist to fill `basis`/`source_fact`/`active_since`/`lapses_at`. **No new authoritative state.** This preserves the Art. I "roles are never stored" pin ([RoleService.php:11-15](app/Services/RoleService.php)).
- **The power_codes per role come from a code registry**, not a DB table — a static `PowerCatalog` mapping `R-xx → [F-codes + capabilities]`, derivable directly from the Roles/Forms chart "Available To Role(s)" column ([roles_forms_chart.md Sheet 3](docs/extracted/roles_forms_chart.md)). This is descriptive metadata; it does NOT add a gate — the actual gate stays `handler->requiredRoles()`. (Pin: `PowerCatalog` must be a *superset-consistent mirror* of what handlers actually require — a test asserts every `handler->requiredRoles()` role appears in the catalog for that form.)
- **"Lights up / lapses automatically"** is already true because the profile is recomputed on read and the underlying facts move via the existing clocks/services. The profile adds the *explanation* (`lapses_at`, `lapse_trigger`), not the *mechanism*.

### 1.3 The one new mechanism: the lapse-sweep safety net (`PowerLapseSweepJob`)

There is a real gap: **term expiry depends on CLK-09 timers being armed and fired.** Derivation is correct on read (a `seated` seat with an expired term still derives the role until the seat status flips — the role query reads `terms.status='active'`, and an *expired but not-yet-swept* term may still read `active` if its timer never fired). The codebase mitigates this for elections with the "inline-then-sweep" belt-and-suspenders pattern (the exploration §4.3 names it). Mirror it here:

- **`PowerLapseSweepJob`** — an **ordinary Laravel scheduled job** (NOT a new CLK — the clock registry is pinned at CLK-01…CLK-21, [ClockRegistrySeeder.php:48](database/seeders/ClockRegistrySeeder.php), and the exploration's §3.4 "no CLK-22" discipline applies identically here). Nightly it finds `terms` whose `ends_on < now()` but `status='active'` and whose CLK-09 timer never fired, and **files the existing expiry path through the engine** (it does not invent a new lapse — it triggers `CivilTermExpiryJob`'s consequence). This guarantees a power can never outlive its term even if a timer was dropped (e.g. across a federation authority flip).
- This job **only ever reconciles facts to their already-decided constitutional outcome** — it never *grants* anything. A test pins that it can only move a seat to a terminal status, never to `seated`.

### 1.4 Hard rail: the reach metric never enters this path

The `CivicPowerProfile` and `PowerLapseSweepJob` **import nothing from the legitimacy/achievements layer.** Pin it exactly as the exploration's CI-1/CI-2 source-grep (mirroring [BallotSecrecyTest.php:189-245] "only BallotBox writes the secrecy tables"): a test asserts `App\Domain\Powers\*` and `RoleService` import no symbol from `App\Services\LegitimacyService`/`AchievementService`/`legitimacy_snapshots`. `RoleService::derive()` returns byte-identically with the metric present or absent.

---

## 2. The Organization Profile: powers/duties gated by org legitimacy

### 2.1 What "org legitimacy" means (and what it does NOT)

An organization's *powers* are also already fact-gated, just through different rows:
- An org can endorse (F-ORG-002) only while `organizations.is_active` / `status='active'` and the agent holds R-23 ([RoleService.php:455-463](app/Services/RoleService.php), [OrgRegistryService.php:96-99](app/Services/Organizations/OrgRegistryService.php)).
- A board can open votes only if "valid" per `OrgBoardService::assertBoardMayOpenVote` ([ChamberVoteService.php:128-133](app/Services/ChamberVoteService.php)) — co-determination validity (CLK-13/14 thresholds, `CoDeterminationService`).
- A dissolved org loses everything: `dissolve()` ends memberships/workers/stakes, dissolves the board, flushes roles ([OrgRegistryService.php:158-233](app/Services/Organizations/OrgRegistryService.php)).

So "org legitimacy" decomposes into **status-legitimacy** (`active`/`suspended`/`dissolved`) and **structural-legitimacy** (board co-determination validity, Art. III §6 — does the board's composition match its worker count?). Both are authoritative facts that already gate powers.

### 2.2 Design: `OrgPowerProfile` (derived, parallel to the civic one)

A derived value object: `{ status, agent (R-23 holder), board_validity, co_determination_state (CLK-13/CLK-14 thresholds vs worker_count), powers: [PowerGrant], standing }` where each `PowerGrant` cites the fact and the constitutional basis (Art. I Economic Freedom for the base powers; Art. III §6 for co-determination-gated board powers).

- **The co-determination state is the org's "term-like" lapsing axis:** crossing 100 workers (CLK-13) *unlocks* the worker-board-election duty (F-ORG-004 auto-trigger); crossing 2000 (CLK-14) unlocks parity. These are exactly the "powers/duties light up as state changes" pattern — and they already fire via `RecomputeWorkerHeadcountJob` + the co-determination service. `OrgPowerProfile` surfaces them; it adds no gate.
- **The reach metric attaches to the org profile as the org-tier achievements** (`ACH-ORG-*`: FOUNDED, 100-MEMBERS, PARITY) **but confers no power** — same CI-1 rail. The org's *display* gauge (member count, worker count milestones) is chrome; its *powers* derive only from status + structure.

### 2.3 The Coalition tie-in (roadmap Phase J)

The Cosmopolitan Coalition is just an `organizations` row (Phase J). Its `OrgPowerProfile` is computed identically; its only special power is **authorship** (`authored_by_organization_id` bridge for K/N education content) — which is an Article-I civil-society capability, firewalled from any Leg/Exec/Jud/CGC power. The profile makes that firewall visible: the Coalition's `powers` list contains authorship/endorsement/assembly grants and **provably** none of the governance grants (a pin asserts a `type='nonprofit'` org's profile never contains R-09…R-22 power codes).

---

## 3. Automated maintenance of the official record (acts + spoken/written record)

### 3.1 The constitutional mandate and what's already automated

Art. II §2 **Publish Public Records**: *"Legislatures publish a public and readily available record of statements made, bills presented, and votes cast with explanations for those decisions."* ([fair_constitution.md:80-81](docs/extracted/fair_constitution.md)). This is a **duty**, and the codebase already discharges most of it automatically:

- **Every chamber vote cast** auto-publishes a `public_records` row of `kind='vote'` with the member's value/rankings + explanation, named (Art. II §2 — the opposite of ballots), inside the engine transaction ([ChamberVoteService.php:307-325](app/Services/ChamberVoteService.php)). Board votes too ([:442-457]). Tie-breaks too ([:638-651]).
- **Every state-changing act** flows through `ConstitutionalEngine::file → handler → AuditService::append` in one transaction ([ConstitutionalEngine.php:108-132](app/Domain/Engine/ConstitutionalEngine.php)); rejections are first-class `rejected=true` chain rows ([:133-152]).
- **Acts/registrations/dissolutions** publish curated `public_records` (`kind ∈ {act,bill,...}`) via the **single** `PublicRecordService::publish` path, each sealed to the audit chain via `audit_seq` ([PublicRecordService.php:75-118](app/Services/PublicRecordService.php)).
- The record types needed by the mandate already exist in `PublicRecord::KINDS`: `statement, vote, bill, act, minutes, opinion, certification, testimony` ([PublicRecord.php:22-26](app/Models/PublicRecord.php)).

**So the "official record of official acts" is already automatic and tamper-evident.** The design's job is to (a) close the residual *manual* gaps and (b) add the automated *spoken/written* (debate) record that the social layer (Phase K) will carry.

### 3.2 Gaps to close (the automation deltas)

**Gap A — Statements & debate are still manual / unbuilt.** `F-LEG-006 Public Record Statement` and `F-SPK-009 Session Minutes Publication` exist as forms, but a representative must file them by hand, and there is no *debate* surface. The mandate's "statements made … with explanations" should be **captured as a byproduct of the act, not a separate chore**:
- **Vote explanations are already auto-captured** (the `explanation` param on `cast()` → the `kind='vote'` record body). Good.
- **Bill introductions / motions / committee reports**: extend the existing handlers so that filing `F-LEG-003` (Bill Introduction), `F-LEG-007` (Motion), `F-CHR-004` (Committee Report) **auto-publishes** a `kind='statement'`/`kind='bill'` record in the same transaction (the BillService/SessionService already run inside the engine; add the `records->publish()` call beside the audit append, exactly as `OrgRegistryService::register` does). This makes the "statements made / bills presented" half of the mandate automatic with zero new user action.
- **Session minutes auto-compilation:** instead of a manual `F-SPK-009`, add a **`CompileSessionMinutesJob`** that fires on session close (the existing `SessionService` close path) and **derives** a `kind='minutes'` record by querying the session's `audit_log` + `public_records` slice (attendance, votes, motions, outcomes) — a *generated* document, not hand-typed. The Speaker/Admin office can still file a manual addendum, but the baseline minutes are automatic.

**Gap B — The spoken/written *debate* record (Phase K dependency).** The constitution's "statements made" extends to deliberation. The roadmap puts deliberation in **Phase K — halls of governance** ("deliberation tied to bills/referendums/petitions … = mandated append-only public record"). Design the contract now so K builds onto this layer, not a parallel one:
- **Halls posts that are *governance deliberation*** (a post in the subforum auto-bound to a bill/referendum/petition) publish through **the same `PublicRecordService::publish`** with `kind='statement'`, `subject_type='bill'|'referendum'|'petition'`, sealing them to the audit chain — making the deliberative record tamper-evident exactly like votes. (The public square's *general* discourse stays in the social tables, NOT the audit chain — only deliberation tied to a governance object is a "public record" under Art. II §2.)
- **The four-carve-out moderation rail** (roadmap Phase K) means this record is **uncensorable**: once a statement is published it's append-only; a redaction is a new `kind='correction'` row pointing back via `supersedes_record_id` (the existing correction discipline, [PublicRecord.php:12-13]). A judicial order (carve-out 1) supersedes; nothing else can.

**Gap C — Coverage / completeness verification.** Today nothing *proves* the record is complete. Add a read-only **`OfficialRecordCoverageService`** that, for a jurisdiction, reconciles `audit_log` governance events against `public_records` and flags any act/vote/statement event lacking a published-record counterpart. This is a *monitoring* surface (and a test fixture), never a gate — it makes the Art. II §2 duty *auditable* rather than assumed.

### 3.3 What stays manual (and must)

- **A representative's own free-text position statement** (genuinely authored speech, F-LEG-006) stays a deliberate filing — auto-generating speech would be compelled/fabricated speech (Art. I). Automation captures *that an act happened and its recorded explanation*; it never *writes* a member's opinion for them.
- **Ballot content never enters the record** — the engine already strips `rankings`/`choice` from chain payloads ([ConstitutionalEngine.php:48-57]) and `PublicRecordService::FORBIDDEN_SUBJECT_TYPES` blocks ballot/location subjects ([PublicRecordService.php:34-39]). Every new auto-publish path inherits this guard (it's enforced in the one `publish()` method, not in callers).

### 3.4 The reach/legitimacy metric's record footprint

Per the exploration: **private individual achievements stay off the governance chain** (chrome, not constitutional acts); **public milestone awards** (jurisdiction/global) publish through the existing `PublicRecordService::publish` with `kind='participation'`/`'certification'` ([achievements-legitimacy.md §3.4]). The nightly `SnapshotLegitimacyJob` (Phase I) is an ordinary scheduled job writing the immutable `legitimacy_snapshots` table — **outside** the audit chain, with its own append-only trigger. No new `audit_log.module`. This keeps the official-act record (governance) and the reach metric (chrome) cleanly separated, which is the whole point of CI-1/CI-6.

---

## 4. How the two halves connect (and stay apart)

```
            DERIVED, AUTHORITATIVE                 |   FLEXIBLE-LAYER CHROME (never a gate)
  ──────────────────────────────────────────────  | ──────────────────────────────────────
  RoleService::derive  ← facts (seats/terms/conf)  |   LegitimacyService::reachRatio  (Phase I)
        │                                          |        │ verified_residents / pop_estimate
        ▼                                          |        ▼
  CivicPowerProfile / OrgPowerProfile  ───────────────►  decorated WITH reach gauge + achievements
   (powers light up / lapse w/ facts)              |   (display only; CI-1 source-grep pin)
        │                                          |        │
        ▼ exercised via                            |        ▼ public milestones only
  ConstitutionalEngine::file → AuditService.append |   PublicRecordService (kind=participation)
        │ (same txn)                               |   SnapshotLegitimacyJob → legitimacy_snapshots
        ▼                                          |        (ordinary job, NOT a CLK; own immutable table)
  PublicRecordService.publish  (Art. II §2 record) |
   votes/acts/statements/minutes — auto, sealed    |
```

The firewall is the existing pins extended: **the power profiles never read the metric; the metric never gates a power; the official-act record is the engine's audit/public-record chain; the metric's record footprint is a separate immutable table + public milestone rows.**

---

## 5. Deliverables summary (all additive, flexible-layer, zero new immutable rules)

| Component | Kind | Existing rail reused | New? |
|---|---|---|---|
| `CivicPowerProfile` + `PowerGrant` | derived read-model | `RoleService::derive`, term/seat rows | new value objects, **no table** |
| `PowerCatalog` (R-xx → F-codes) | code registry | Roles/Forms chart, `handler->requiredRoles()` | new static array |
| `PowerLapseSweepJob` | scheduled job (NOT a CLK) | CLK-09 expiry consequences, "inline-then-sweep" | new job |
| `OrgPowerProfile` | derived read-model | `OrgRegistryService`, `CoDeterminationService`, board validity | new value object |
| Auto-publish on bill/motion/report/minutes | engine handler additions | `PublicRecordService::publish` (same txn) | wiring + `CompileSessionMinutesJob` |
| Deliberation→record contract for Phase K | contract | `PublicRecordService`, `supersedes_record_id` | contract only (built in K) |
| `OfficialRecordCoverageService` | read-only monitor | `audit_log` ↔ `public_records` reconciliation | new service |

Test posture matches the suite (live-pg guarded/rolled-back + pure-logic pins), per the exploration's §6 and the A-G discipline:
- `PowerProfileDerivationTest` (pure): profile is identical with/without legitimacy state; a vacated/expired fact removes the grant.
- `PowerConfersFromFactNotMetricTest` (source-grep, mirrors BallotSecrecy grep): `Powers\*` import nothing from the metric layer.
- `LapseSweepNeverGrantsTest`: the sweep can only move seats/terms to terminal states.
- `OfficialRecordCompletenessTest`: every governance act event has a published-record counterpart; ballot content never appears.
- `OrgPowerFirewallTest`: a nonprofit profile never lists governance power codes.

---

## (a) Roadmap slot

- **The power-profile + auto-record half slots primarily into Phase I** (Activation Tiers & the Reach/Legitimacy Metric) as its *power-facing companion* — Phase I already owns `LegitimacyService`/`legitimacy_snapshots`, and the `CivicPowerProfile`/`OrgPowerProfile` are the surfaces that consume reach *as decoration* while proving the CI-1 firewall. The **office-legitimacy gating itself is already live (Phases B-E)** — this design formalizes it as a read-model and adds only the lapse-sweep safety net.
- **The debate/spoken-written record and the achievement/profile *surfaces* slot into Phase K** (Public Square, Civic Education & Achievement Surfaces) — the halls-of-governance deliberation record and the badge/gauge UI. The `PublicRecordService` deliberation contract (§3.2 Gap B) is defined here so K builds onto it.
- The **auto-publish wiring for bills/motions/minutes (§3.2 Gap A)** can land independently as a small hardening increment on the existing Phase C legislature module — it needs no new phase.
- Net: **Phase I owns the power-profile + metric firewall + reach record footprint; Phase K owns the debate record + visible profile surfaces; a standalone Phase-C hardening lands the auto-minutes.**

## (b) Open decisions for the operator

1. **Is `CivicPowerProfile` strictly read-only forever, or may it ever cache?** Recommendation: strictly derived (no cache row) to preserve the Art. I "never stored" pin — but a profile across ~2000 Earth seats may be a heavy read. Accept on-demand derivation (request-cached like `RoleService`), or allow a *non-authoritative* materialized view rebuilt nightly?
2. **`PowerLapseSweepJob` cadence and authority.** Nightly is proposed; should it be gated on `authoritative_server_id IS NULL` (like `snapshotAll`) so a mirror/peer never sweeps another instance's terms? (Recommend yes — same CI-6 discipline.)
3. **How much of the "statements made" mandate auto-publishes vs. stays manual?** §3.3 draws the line at "capture the act + its recorded explanation automatically; never fabricate a member's opinion." Confirm that line, especially for committee reports (F-CHR-004) — auto-generate a skeleton report from the committee's vote record, or keep it fully authored?
4. **Auto-compiled minutes authorship.** Should `CompileSessionMinutesJob` publish under the system actor (null) or require the Speaker/Admin office to *ratify* the generated minutes before they're public? (Constitution names the Speaker/Admin as the publisher — F-SPK-009 — so a "generate-then-ratify" flow may be the faithful reading.)
5. **Does the org profile expose the co-determination *countdown*** (e.g. "12 workers to the 100-worker board-election trigger") as chrome? It's the org analogue of the jurisdiction reach gauge — useful, but verify it can't be read as pressuring hiring (Art. I no-compelled-anything posture for orgs is weaker than for individuals, so likely fine).
6. **`PowerCatalog` source of truth.** Generate it from the Roles/Forms chart (risk: drift from `handler->requiredRoles()`) or derive it *from* the handlers at boot (authoritative, but loses the chart's human-readable basis text)? Recommend: handler-derived for the gate-truth, chart-text for the `basis` string, with a pin that the two agree.

## (c) Risks

- **R1 — "Legitimacy" overload (gravest).** The word means two different things (office legitimacy = power-conferring; reach = chrome). If the UI ever labels the reach gauge "legitimacy" next to a power surface, an observer (or a future dev) may infer reach gates power. **Mitigation:** name the chrome **reach/enrollment** everywhere (the exploration's R4 discipline); the power profile's `basis` always cites the *office* fact, never a metric; CI-1 source-grep pin.
- **R2 — Derivation/sweep divergence.** A power derives `active` on read while an expired term hasn't been swept (timer dropped across a federation flip). **Mitigation:** the `PowerLapseSweepJob` belt-and-suspenders; and the *derivation queries should read term `ends_on` directly* where the role depends on a term, so an expired term derives no role even before the sweep runs (tighten `hasCivilOfficerTerm`/`hasAdminAppointment` to also check `ends_on > now()`). Flag: this is a small change to PROTECTED-adjacent `RoleService` and needs a constitutional pin update.
- **R3 — Auto-publish bloat / performance.** Auto-publishing statements for every bill/motion adds `public_records` writes inside hot engine transactions. **Mitigation:** these already happen for every vote cast at scale; the additions are 1 row per act, not per cast. The heavy `OfficialRecordCoverageService` reconciliation is a read-only monitor, run off the critical path.
- **R4 — Compelled-speech creep.** Aggressive auto-publishing of "statements" could fabricate positions a member never took. **Mitigation:** §3.3 hard line — automation records *that an act occurred and its captured explanation*; it never authors opinion. Pin: auto-published `kind='statement'` rows carry only factual act-summary text, never inferred sentiment.
- **R5 — Coverage monitor mistaken for a gate.** `OfficialRecordCoverageService` flags gaps; a future dev could wire a flag to block an act. **Mitigation:** it returns a report, has no write path, and a test asserts no caller treats its output as authorization.
- **R6 — Org-profile firewall erosion (Coalition).** A future change could let the Coalition org's profile acquire a governance power. **Mitigation:** `OrgPowerFirewallTest` pins that a `nonprofit` profile never lists R-09…R-22 power codes; the existing `type='nonprofit'` decision (Phase J) is the structural guarantee.

---

**Files I actually read to ground this:** [app/Services/RoleService.php](app/Services/RoleService.php), [app/Http/Middleware/EnsureRole.php](app/Http/Middleware/EnsureRole.php), [app/Domain/Engine/ConstitutionalEngine.php](app/Domain/Engine/ConstitutionalEngine.php), [app/Services/AuditService.php](app/Services/AuditService.php), [app/Services/PublicRecordService.php](app/Services/PublicRecordService.php), [app/Services/Identity/AttestationGate.php](app/Services/Identity/AttestationGate.php), [app/Services/ChamberVoteService.php](app/Services/ChamberVoteService.php), [app/Services/VacancyService.php](app/Services/VacancyService.php), [app/Services/Organizations/OrgRegistryService.php](app/Services/Organizations/OrgRegistryService.php), [app/Jobs/Clocks/CivilTermExpiryJob.php](app/Jobs/Clocks/CivilTermExpiryJob.php), [database/seeders/ClockRegistrySeeder.php](database/seeders/ClockRegistrySeeder.php), [app/Models/PublicRecord.php](app/Models/PublicRecord.php), [docs/extracted/roles_forms_chart.md](docs/extracted/roles_forms_chart.md), [docs/extracted/fair_constitution.md](docs/extracted/fair_constitution.md) (Art. II §2 Publish Public Records, l.80-81), [docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md](docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md), and the legitimacy exploration at [explore-achievements/docs/plans/explorations/achievements-legitimacy.md](../../../explore-achievements/docs/plans/explorations/achievements-legitimacy.md). This is a design only — no files were edited.