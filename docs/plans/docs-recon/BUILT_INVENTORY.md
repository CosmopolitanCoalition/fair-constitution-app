# BUILT INVENTORY — The Definitive Code-Level Audit (2026-07-25)

**What this is.** The operator suspected the phase ledger's "unbuilt" classifications were wrong
("I believe these systems may actually exist"). This document is the definitive answer: a
code-level audit of every phase and module — 14 independent audit agents, ~1.4M tokens of
investigation, 678 tool calls, evidence discipline of file:line citations throughout, every
"absent" claim re-hunted by adversarial refuters (34 claims checked, **6 overturned**). Runtime
state on both live stacks (game box `fc_*`, dev `fcd_*`) was inspected read-only.

**He was right.** Three of the seven "unbuilt" phases contain substantial shipped systems, and
every one of the seven sits on more finished substrate than the prior ledger recorded. Nothing
previously rated BUILT was found hollow — Phases A–H, K-1, K-3 survived hostile verification.

**Authority relationship.** This document CORRECTS `PHASE_LEDGER_A_TO_O.md` (which now carries a
banner deferring to it) and feeds the lane charters in `DELTA_DELIVERABLES_MASTER.md`. The full
machine-generated evidence base (per-item citations for everything asserted here) is committed
alongside as [BUILT_INVENTORY_EVIDENCE.md](BUILT_INVENTORY_EVIDENCE.md).

---

## 1. The corrected scoreboard

| Phase | Prior rating | **Audited rating** | One-line verdict |
|---|---|---|---|
| A Foundation | BUILT | **BUILT — verified** | Engine, hash-chained audit (advisory-lock serialized), 21-clock registry, activation engine all real |
| B Identity & Jurisdictions | BUILT | **BUILT — verified** | Residency machine, ancestor sweep, R-00..R-30 all derived in one service |
| C Elections | BUILT | **BUILT — verified** | STV/Droop/Gregory fixed-point, two-phase secrecy, commitment scheme, countback |
| D Legislature/Exec/Orgs | BUILT | **BUILT — verified** | 10,270 service lines, zero hollow bodies found; oversight layer richer than the row claimed |
| E Judiciary & Law | BUILT | **BUILT — verified** | All three Art. IV §5 paths pinned; dual-door setting amendments un-credited bonus |
| F Federation substrate | BUILT | **BUILT — verified** | Mesh, FF&C, authority flip, 4 jurisdiction services; plus un-credited broker/cert subsystem |
| G Adoption/Autonomy/G-ID | CODE-COMPLETE, 2 rig gates | **BUILT — verified**, same 2 gates | 823e752 on main; the "dual-meter" is in code a THREE-meter system; both rig gates confirmed the only gaps |
| H Districting | BUILT | **BUILT — converged by different means** | Delivery machinery real + pinned; F-ELB-007 form ID never minted (capability shipped under F-ELB-008), F-ELB-009/exemplars/recalibration loop superseded, never built |
| **I Activation & Legitimacy** | UNBUILT | **PARTIAL — half is live** | Activation boot-gate (jurisdiction_activations + CLK-06 + cascade + WF-JUR-01) is BUILT; missing only the tier CURVE and the entire reach/legitimacy half |
| J Coalition as Org | UNBUILT | **SUBSTRATE_ONLY — correct but under-credited** | J-specific deltas genuinely absent; but parent link column, nonprofit type, F-LEG-028 cultural-institution path all live |
| **K-2 Education & Achievements** | UNBUILT | **PARTIAL — achievements half is BUILT** | Journeys engine + append-only achievements ledger + code-registry catalog + profile badges + medal federation all live; education/grading half absent |
| K-1 Civic Record Plane | BUILT | **BUILT — verified** | Correction: forms are F-SOC-001..003 (F-CHR-* are committee-chair forms) |
| K-3 Matrix mesh | BUILT | **BUILT — verified, 2 soft edges** | Running infrastructure on BOTH stacks; M-5/F-SOC-004 has no HTTP/console entry point; M-S media gate bound but unconsumed |
| L Public Finance | UNBUILT | **SUBSTRATE_ONLY** | Charter tables/forms all absent — but stipend/currency PARAMS live in setup wizard, grants/appropriations stub shipped (half-wired), no-paywall proto-rail in PROTECTED validator, 14-page economy mockups ×2 generations |
| M Market Economy | UNBUILT | **SUBSTRATE_ONLY** | Marketplace/UBI-run/mutual-aid absent — but the M exit criterion's co-determination auto-trigger chain is BUILT and pinned; commercial contract kind in schema |
| N i18n/A11y/Media | UNBUILT | **SUBSTRATE_ONLY** | 5-locale chrome + pseudo-locale + glossary real; K-3 shipped the MT router's exact plug-in seam (pin-tested); 412 aria attributes across 91/177 Vue files; extraction/CI/115-locale registry absent |
| O Full-Scale Demo | UNBUILT | **SUBSTRATE_ONLY** | CI-2 scale_demo→no-federation rail ALREADY coded + pinned (in K-3); `*@demo.invalid` namespace in live use; SANDBOX game mode end-to-end; the CoW overlay + populate engine remain the genuinely novel scope |

**Corrected score: 10 verified built · 2 partial (I, K-2) · 5 substrate-only (J, L, M, N, O) · 0 zero-trace.**

---

## 2. The six overturned "absent" claims

1. **K-2 education tracks/progress** — EXISTS under the name **journeys**: `journey_progress` +
   `achievements` (baseline tables, DB-trigger append-only), `JourneyService` (13 arcs, 10 live,
   idempotent award sealed to the audit chain), `config/cga/journeys.php` as the server-side
   curriculum registry, routed pages, 10 tests. Shipped in mockups-v3-wiring Phase 3c, never
   attributed to K-2. Missing: graded questions (`correct_keys`), F-EDU forms.
2. **K-2 factions→polymorphic teaching correction** — the correction is woven through built
   surfaces (OrgDetail banner, Committees teaching copy, VacancyCountback, surfaces registry
   citations, constitutional-questions ledger items 1 and 6). Remaining: package as a Learn
   module + fix the Coalition's external STV materials — authoring, not app code.
3. **M UBI** — the **civic stipend parameter layer is live end-to-end**: `civic_stipend_floor`,
   `stipend_bump_cap`, `pay_node_operator/social_moderator/office_holder`, `stipend_interval`,
   `currency_name/code/symbol` on the PROTECTED ConstitutionalSettings model, collected by Setup
   Step 1 (defaults: floor 50, bump 20, monthly), designed in
   `docs/plans/phase-g-continuation/LM-fiscal-civic-stipend.md`. Missing: disbursement tables +
   the system-only run.
4. **N translation_cache** — the named tables are absent, but the dynamic layer's function exists
   in another shape: `public_records.translations` jsonb (per-locale status, read/write/federation
   wired, UI badge) + the K-3 TranslationProvider/TranslationGate seam whose code comment says
   "The full hybrid router is Phase N; the rail is permanent."
5. **O instance_class** — the column is absent, but the `scale_demo` class's key invariant (CI-2:
   no federation consent) is **already implemented and constitutionally pinned**:
   `MatrixFederationGateService::desiredFederationWhitelist(scaleDemo: true) === []`,
   `MatrixFederationWhitelistTest:51-52`. Persistence half unbuilt; note `game_mode`
   (production|sandbox) is a DIFFERENT axis.
   **⚠ QUALIFIED 2026-07-25 (lane 4 adversarial verification — sharper than the original claim):
   the rail is INERT IN PRODUCTION.** The only production caller, `setRoomServerACL()`
   (`MatrixFederationGateService.php:67`), passes no argument, so `$scaleDemo` is always false;
   the `true` path is exercised *only* by the test. Worse, the anti-self-brick guard at `:70`
   (`empty($allow) || ! in_array($local, $allow)` → ConstitutionalViolation) means naively wiring
   it globally converts an inert rail into a live self-brick. Correct reading: **the invariant
   exists and is pinned; the production path never reaches it.** Wiring it touches a pinned
   constitutional test → operator decision, routed via lane 4's plan.
6. **H F-ELB-007 splitline** — the automated shortest-splitline generator SHIPPED
   (`SubdivisionAutoseedService` + `LeafGiantResolver`, 473k planetary line-splits, filings under
   F-ELB-008); only the form ID was never minted. `ManualDistrictDraw.php:17` literally calls
   splitline "F-ELB-007."

---

## 3. Remaining true scope per disputed phase (what lanes actually build)

### Phase I (lane 3 carries the draft) — HALF the prior scope
- **Already built:** `jurisdiction_activations` state machine → CLK-06 every-minute sweep →
  settings-cascade threshold (dev default 1) → full WF-JUR-01 bootstrap → `jurisdiction:activate`
  → WI-9 UI status line. The clock registry already tags CLK-06 `per_jurisdiction_tier`; the
  `Reach` nav surface is registered (href:null) against the mockup contract; the numerator query
  (active residency counts) and denominator rails (population + provenance columns) exist.
- **To build:** the tier CURVE (`activation_tier_enabled/k/exponent/floor/cap` settings +
  `ActivationTierService::tierThreshold = clamp(ceil(k·pop^⅓), floor, cap)`) feeding the existing
  resolver seam; `legitimacy_snapshots` + `LegitimacyService` + nightly `SnapshotLegitimacyJob`
  (**⚠ CORRECTED 2026-07-25 — the original wording here was WRONG, caught by lane 3's
  verification**: `onOneServer` + `LeaderProbe::isPrimary()` is the **Patroni/HA scheduler-leader
  axis**, NOT federation authority. CI-6 — "only the authoritative instance writes a snapshot" —
  rides the *separate* `jurisdictions.authoritative_server_id` axis
  (`app/Services/Federation/AuthorityResolver.php`; `LocalAutonomyService.php:32` names the two
  apart). A mirror node runs its own scheduler and wins its own probe, so the writer needs an
  explicit per-jurisdiction `authoritative_server_id IS NULL` filter — the leader probe alone
  does NOT satisfy CI-6. Still undecided: whether `legitimacy_snapshots` carries
  `source_server_id`, i.e. whether snapshots federate at all);
  k-anon suppression; the reach UI against `mockups/v3/social/legitimacy.html`.
- **Caution:** the "sizing law" (`cubeRootSeats`) is legislature SIZING, not the tier threshold —
  cousins, not the same thing. Its leaf ceiling-9 clamp was retired 2026-07-19 (floor-clamp only).
- **Coverage gap:** the activation pipeline has ZERO automated tests today (verified live-stack only).

### Phase J (lane 14) — exactly as small as chartered, minus nothing
- **To build (all confirmed absent):** `organizations.public_domain_charter` +
  `cgc_ip_register.dedication_basis` + `org_memberships.is_public` (one migration),
  `institutions:demo-coalition` seeder, firewall pin tests, voluntary-dedication branch.
- **Landmines:** `CgcIpRegisterService::dedicate()` currently hard-REJECTS non-CGC orgs and is
  source-scanned by `CgcIpPublicDomainTest` — the J build must extend that test's contract, not
  just add a column. `OrgRegistryService::register()` doesn't accept `parent_organization_id` —
  the seeder must set the parent link programmatically. `parent_organization_id` itself has zero
  test coverage.
- **Anti-pattern-match:** `foundation_sync_cursors` is federation seed-drain plumbing, NOT a
  "Party Foundation" artifact. F-LEG-028 Cultural-Institution recognition (J's Art. V grounding)
  is already built.

### Phase K-2 (lane 15) — engine half must start from JourneyService, NOT greenfield
- **Already built:** everything in overturned-claim #1 plus medal federation
  (append-any-verified, own constitutional test) and DB-trigger append-only enforcement.
- **To build:** graded education modules (`education_questions`/`correct_keys`, server grading,
  progress-never-federates rail is already the journeys posture), F-EDU forms, the curriculum
  extraction from The_Chart, the Learn-module packaging of the factions correction, and (after
  Phase I) the reach gauge + jurisdiction-only leaderboards.
- **Fidelity flags for lane 15:** journey steps are self-reported ticks, not verified acts;
  `earned_at` is a full timestamptz that federates (charter wanted coarse DATE) — decide before
  more medals exist.

### Phases L+M (lane 13) — design starts from a real substrate list
- **Genuinely absent:** ledger/treasury/revenue/budget/currency planes, marketplace, mutual aid,
  UBI disbursement tables, F-TRE/F-LEG-037..040/F-IND-018..023 forms, age_of_majority settings.
- **Start from:** the live stipend/currency PARAMS (overturned claim #3); the Phase-D
  appropriations+grants stub — noting `GrantService::award/decline/disburse/createAppropriation`
  are **dead code with zero callers and zero tests** (apply() alone is routed; the appropriations
  table is unpopulatable today); the BUILT labor→co-determination chain (F-IND-014 →
  `labor_recurring` contract → countersign → CLK-13/14 recompute — the M exit criterion's
  hardest link, already pinned); `org_contracts` kind `commercial` in schema; the
  fee/`payment_required` FORBIDDEN_ELIGIBILITY_KEYS proto-rail in the PROTECTED validator; the
  live dual-door machinery (monetary keys = configuration on tested rails); **14 finished economy
  mockup pages in BOTH v2 and v3** + a live "Market · planned" nav section.

### Phase N (lane 5 strings / lane 6 a11y) — numbers, not vibes
- **Real today:** vue-i18n with 5 chrome locales (en 111 keys; es/ar/hi/zh-Hans 103 each — 8-key
  drift already, no CI to catch it), deterministic en-XA pseudo-locale, 36-term glossary (charter
  says 38 — reconcile), locale switcher persisted to users.locale, RTL plumbing, the K-3
  TranslationProvider seam + privacy rail (pin-tested), `public_records.translations` read path +
  UI badge, 412 aria- attributes across 91/177 Vue files + a WCAG 4.1.3 live-region composable.
- **Absent:** string extraction + CI gate (NOTE: repo has NO .github at all — the CI gate
  deliverable includes standing up CI itself), per-namespace catalogs (loader glob ready, dir
  doesn't exist), 115-locale registry (`scripts/etl/languages.py` is a DIFFERENT artifact —
  country→official-language ETL), NLLB/Haiku router, media pipeline, F-SYS-* forms. Body copy
  hardcoded across 87 pages + 90 components; only the 6 shell files use `$t()`.
- **Orphan:** POST /matrix/translate is server-complete with no frontend caller; Register.vue
  already PROMISES "Records are translated per your selection" — a shipped promise nothing delivers.

### Phase O (lane 4) — scope narrower than chartered
- **Already delivered elsewhere:** the earth.* "Standard" half (dormant scaffolding at ~951k
  jurisdictions) is Phase H's output; CI-2 enforcement half (overturned claim #5); `*@demo.invalid`
  in live seeder use; SANDBOX game mode end-to-end (wizard → GameMode → /dev/* triple-lock →
  DevBar → deploy packages); dormant `time_mode`/`time_scale_seconds_per_year` columns nothing consumes.
- **To build (the genuinely novel):** `instance_class` persistence + boot assertion,
  `demo_sessions`/`demo_overlays` CoW sandbox, `demo_generation_runs` + `DemoPopulateService`
  populate engine.

---

## 4. Sketchbook concept dispositions (corrected)

| Concept | Prior claim | Audited truth |
|---|---|---|
| Apply for Grants | never built | **PARTIAL — half shipped in Phase D**: tables + hardened GrantService + live apply route + UI card; award/disburse dead code, individual-applicant grants absent |
| Fundraising / Fund Distribution | never built | **Half wrong**: fund DISTRIBUTION = the appropriations-by-act pipeline (exists); only donation-intake fundraising is absent — fold THAT half into L/M |
| Equal Partnership | never built | **Overstated**: `equal_partnership` is an implemented org structure (board elections seat every partner); only a dedicated formation flow is missing |
| Endorse Policies | never built | **Confirmed absent** — and `policy_proposals` is a FALSE COGNATE (Phase D F-EXE-002 department-internal policy). `endorsements` is structurally candidate-only; the concept needs new schema |
| Family Tree | never built | **Confirmed absent** (exhaustive sweep) |
| Asset Registration | never built | **Confirmed absent** (`cgc_ip_register.asset` is the IP register, unrelated) |

All 14 previously-unattributed baseline tables have firm phase homes (public_records, admin_offices,
misconduct_investigations, cultural_institutions → C; governor_removal_requests, policy_proposals,
grants trio → D; warrants, sentencing_orders, remedy_recommendations → E; restoration_events,
directory_entries, operational_partition_exports, read_write_requests → F/G; data_review_decisions →
setup wizard; invites → growth flow). None are orphans. Details in the evidence file.

---

## 5. Anti-pattern-match table (names that fooled us once)

| Artifact | Looks like | Actually is |
|---|---|---|
| `approval_standings` | Phase I legitimacy | Phase B election approval rollups (live daily ESM-04 job) |
| `journey_progress` | onboarding | **the K-2 education-progress plane** |
| `support_reports` | Phase I support metric | support tickets |
| `policy_proposals` | Endorse Policies | Phase D department-internal F-EXE-002 |
| `foundation_sync_cursors` | Party Foundation | federation seed-drain cursors |
| `broker_authorizations` | economy | Phase G federation DNS/TLS cert brokering |
| `oidc_*` + fc_mas/fcd_mas | Phase G G-ID | Phase K-3 Matrix auth (MAS) |
| `scripts/etl/languages.py` | Phase N locale registry | geodata country→official-language ETL |
| `game_mode` sandbox | Phase O scale_demo | dev-toolbox world property — a different axis |
| `vote_casts` | citizen ballots | chamber/board voting lanes |
| `audit_checkpoints` | Phase A audit | Phase F federation checkpoint publishing |
| F-CHR-001..004 | civic-record (Charter?) forms | Committee CHAIR forms; civic record = F-SOC-* |
| "M-5" (risk item 6) | Phase M market economy | K-3 Matrix legal-compliance layer (illegal-content plane) — the letter fools exactly this way; flagged by lane 13 |

---

## 6. Runtime truth (both stacks, 2026-07-25)

- **21 containers, zero unhealthy.** Each stack runs **10 services** (CLAUDE.md's table lists 7 —
  missing: matrix/Synapse, mas, scheduler). Matrix infrastructure is RUNNING on both boxes;
  `matrix_rooms`/`social_spaces` hold zero rows — infrastructure live, data empty.
- **Schema-identical DBs** — 193 base tables each (182 baseline CREATEs + 10 post-baseline +
  migrations table; CLAUDE.md's "183" is a near-miss), identical 13-migration heads through
  2026_07_22_000003. Zero drift between boxes.
- **Game box** (`fc_*`): the accepted planet — ≈951,626 jurisdictions / ≈955,130 legislatures /
  ≈1,963,037 districts; map accepted + apportionment complete 2026-07-19; **setup wizard parked at
  step 3** ("United Earth", solo/sandbox, federation off) — the planet exists, the founding is
  unfinished. **Dev box** (`fcd_*`): virgin (step 0, 0 users, audit_log=35).
- **Every later-phase-vocabulary table** (achievements, journey_progress, social_spaces,
  matrix_rooms, approval_standings, grant_applications, policy_proposals, cultural_institutions,
  jurisdiction_activations…) ships in the 2026-07-05 baseline on both boxes with ZERO rows:
  shipped substrate, not exercised features.
- **Install-order coupling nobody recorded:** the 21-clock registry does NOT ride in the schema
  dump — `COPY public.clocks` is empty; clocks are seeded by `ClockRegistrySeeder` via
  deploy.ps1/deploy.sh. A bare `php artisan migrate` install has an empty clocks table.

---

## 7. Defects & risk register (found in passing — none blocks the 40-day path by itself)

1. **GrantService dead code** — award/decline/disburse/createAppropriation: zero callers, zero
   tests; the grant lifecycle cannot complete through any surface; demo grants card renders empty.
2. **BallotCrypto's own docblock flags the {ballot_hash, salt} receipt as a vote-selling channel**
   and names a cryptographer review as a production gate — an honest open security TODO on the
   elections engine, relevant to the cloud launch.
3. **No CI exists at all** (no .github) — every "CI gate" plan implies creating the pipeline first.
4. **Chrome locale drift**: en=111 keys vs 103 translated (8 strings already untranslated in-scope).
5. **Form-count drift**: registry = **108** canonical (103 + ELB-008 + SOC-001..004) + 6 aliases.
   CLAUDE.md's "109/104" corrected this commit. F-LEG-020/021 are deliberately handler-unregistered
   (consent votes ride chamber machinery) → 106/108 dispatchable, by design.
6. **K-3 M-5/F-SOC-004 has no HTTP/console entry point** (service + tests only, tinker-invocable);
   the M-S media admission gate is container-bound but consumed by nothing.
7. **Misconduct-investigation intake is an audited NON-form action** — a known, flagged gap in the
   form catalog (OversightService "Intake has NO catalog form").
8. **Achievements deviation**: `earned_at` full timestamptz federates cross-instance; charter's
   privacy rail wanted coarse DATE. Cheap to fix now, expensive after real medals exist.
9. **Two app shells coexist** (AppShell.vue + AppShellV2.vue) — consolidation candidate for lane 6.
10. **Reserved-not-dead**: MJV kinds `cultural_institution` + `additional_articles` are in the
    CHECK constraint with no opener service (both work as chamber vote types).
11. **Phase I activation pipeline has zero automated tests** (live-stack verified only).
12. **users.languages multi-select + "records are translated" UI copy promise a pipeline that
    doesn't exist** — either deliver minimally in N or soften the copy pre-launch.

*Added 2026-07-25 from lane adversarial-verification passes (fleet-wide, not lane-local):*

13. **The audit lock is transaction-scoped — bulk writers must append LAST.**
    `AuditService::append()` takes `pg_advisory_xact_lock` (`AuditService.php:77`) *inside the
    caller's transaction*, so any worker that appends its batch summary early holds a GLOBAL
    appender lock for the whole chunk, serializing every other lane's writes behind it. Rule for
    every bulk/chunked writer (lanes 1, 3, 4, 13): append the batch summary **after** the bulk
    write commits, in its own short transaction. Found by lane 4; broadcast to 1/3/13.
14. **No artisan command carries an environment guard** — every `*:demo*` / seeding command will
    run against whatever database it is pointed at, including a production cloud node. Pre-launch
    exposure → lane 2's launch checklist. (Related: existing demo commands use `@cga.test` +
    `Hash::make('demo')`; the reserved `*@demo.invalid` namespace + random secrets is the correct
    pattern, live only in `SocialDemoCommand`/`MatrixDemoCommand`.)
15. **86,066 soft-deleted districts sit inside active maps** (~4.5% overcount) — any district
    count that omits `whereNull('deleted_at')` overstates. Found by lane 4, posted to lane 1;
    all district figures are timestamped because the healing loop moves them hourly.

---

## 8. Standing rule for future audits

Before classifying anything unbuilt: (1) sweep for **alternate names** (education→journeys,
UBI→stipend, splitline→F-ELB-008 filings); (2) check whether an invariant shipped **as enforcement
without persistence** (scale_demo CI-2); (3) check whether a **parameter layer** shipped ahead of
its engine (stipend); (4) check the **mockups + registry stubs** — this codebase pre-registers nav
surfaces (href:null) and mockup contracts for unbuilt phases, which are commitments, not noise;
(5) remember baseline tables predate the ledger — the 2026-07-05 flatten swallowed features nobody
itemized. The six overturned claims in §2 all fell to one of these five patterns.
