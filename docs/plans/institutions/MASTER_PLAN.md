# CGA — Institutions Master Architecture Plan + Phase A

## Context

The app has so far been one legislature (Earth) plus its mapping/districting tooling. The mockup
site (`mockups/` — 62 screens, 80 flow walkthroughs, 103 forms, 30 roles, 17 institutions,
21 clocks, 20 entity state machines; all stages complete, owner-reviewed 2026-06-11) and the
design docs (`App Docs/`) define the full constitutional product. This plan wires in ALL
institutions per those requirements: the binding architecture, the full target schema, a
roadmap A–F, and implementation-ready detail for Phase A.

Produced by: 6 parallel exploration readers (constitution, architecture plan, canonical
registries, all mockup contracts, codebase inventory) + 3 parallel design agents. Full design
appendices (read them when executing — they carry column-level schema and component-level
detail this summary references):
- `C:\Users\JOSEPH~1\AppData\Local\Temp\claude\salvage\DESIGN_schema_engine.md`
- `...\salvage\DESIGN_roadmap_phaseA.md`
- `...\salvage\DESIGN_frontend_port.md`
- `...\salvage\{AUTHORITATIVE_ARCHITECTURE,DATA-MODEL_REGISTRY,CIVIC_and_ELECTORAL,LEGISLATURE_and_EXECUTIVE_domains,JUDICIARY_ORGANIZATIONS}.md`
**WI-0 commits these into `docs/plans/institutions/` so they survive temp cleanup.**

## Binding decisions (operator, 2026-06-12)

1. **Per-jurisdiction unified institution model.** Every jurisdiction CAN have its own
   legislature (+ executive + judiciary), activated when player population pegs against real
   population (CLK-06). Each legislature is sized by the cube-root law, subdivided into 5–9
   seat districts when > 9 (the 5–9 cap is per district/voter-pool, not chamber size),
   bicameral (type_a districted + type_b one-per-constituent, both must agree) whenever the
   jurisdiction has constituents. The Earth legislature is simply the first instance; "scope"
   stays a view term inside any one legislature's district map. This unifies the mockups'
   three apparent models (county chamber / large districted chambers / Earth drilldown).
2. **Horizon:** master plan + full target schema + roadmap A–F; implementation detail for
   Phase A only — later phases get their own detailed plans when reached.
3. **Identity:** real session auth in Phase A (UUID users), residency declaration +
   manual/simulated pings, CLK-05 30-day clock, PostGIS ancestor-sweep associations,
   automatic role derivation R-01→R-04 (Art. I: association is the ONLY requirement).
   Demo persona bar becomes a dev-only impersonation tool.
4. **Frontend:** design-system-first. Phase A ports tokens + ~20 shared components + new app
   shell; the 3 developed tools (jurisdiction viewer, district mapper, setup wizard) keep
   their MANIFEST §7 placement slots and are re-skinned, not rebuilt.

## Authoritative sources (priority order)

1. `docs/extracted/fair_constitution.md` — supreme policy authority
2. `App Docs/CGA_Constitutional_Roles_Forms_Chart.xlsx` — canonical registry (roles, forms, dependency chain, bootstrap, vote types)
3. `App Docs/CGA_Workflows_Catalog.xlsx` — 80 workflows, 20 entity state machines (status-column spec), 21 clocks (scheduler spec)
4. `App Docs/CGA_Architecture_Plan.docx` — 10 modules, phasing, federation/identity architecture
5. `mockups/MANIFEST.md` + `manifest.json` — frozen handoff contracts; §1 form-ID alias table; §4 component inventory; §7 dev slots; §8 owner decisions

---

# ARCHITECTURE (binding)

## Cross-cutting decisions

| Decision | Choice | Why |
|---|---|---|
| users → UUID | Drop + recreate (new migration, not editing stock file) | Table empty, zero auth wired; preserves migration history on live 951k-row DB — no migrate:fresh |
| residency_confirmations | Replace with `residency_claims` (ESM-02, 7 states) + per-level association rows | Old table conflates claim and association; stores rights as toggleable booleans — Art. I forbids that drift |
| location_pings | Recreate (uuid user FK, claim_id, qualifying-day eval); purge raw pings on verify | PostGIS point + trigger design was right; privacy: coordinates never outlive verification |
| Roles | **Derived, never stored** — `RoleService` pure function + `v_user_roles` view for reporting | R-01..R-04 are functions of facts; office roles (R-06..R-30) already have authoritative seat rows; a grants table = second source of truth |
| constitutional_settings | Keep 1:1 per jurisdiction; resolution walks parent chain (own → ancestor → code defaults); activation copies nearest ancestor row | Per-jurisdiction model makes 1:1 exactly right |
| Forms catalog | Code registry `config/constitution/forms.php` (103 canonical IDs + alias map), not DB | Constitutional artifact versioned with code; audit_log stores canonical IDs only |
| Boards | One `boards` + `board_seats` shared by departments, CGCs, private orgs | Art. III §6 co-determination applies identically to all three — one engine, one validity rule |
| Org/board elections | Reuse `elections` machinery (`kind` + `electorate_type`) | Never fork the PROTECTED counting path |
| Ballot secrecy | `ballot_envelopes` (participation) + `ballots` (anonymous content + published hash) — **no linking column**; hour-truncated cast time | Commitment scheme: receipt hash verifiable, double-vote prevented, ballot↔voter unlinkable |
| Laws ≠ bills | `bills(+versions)` → enactment creates `laws(+law_versions)` v1; Art. IV §5 judicial remedies append versions with `source='judicial_remedy'` | Git-style law versioning is the substrate for Phases C/E/F |

## Constitutional engine (Phase A skeleton, grows every phase)

- `ConstitutionalEngine::file(formId, actor, payload)` — single dispatch for every
  state-changing action: canonicalize form ID → role-authorize → `ConstitutionalValidator`
  hardened checks → handler in transaction → `AuditService::append` same transaction.
  Violations throw with citation; engine records a `rejected=true` audit row and rethrows (422).
- `ConstitutionalValidator` (PROTECTED) — rule registry. Phase A rules: settings bounds,
  5–9 seats, supermajority `ceil(serving×2/3)` with majority+1 floor, rights-automatic guard.
- Audit chain: append-only `audit_log` — `seq bigserial`, `prev_hash`, `hash =
  sha256(prev_hash ∥ canonical_json(payload))`, genesis row, Postgres trigger raising on
  UPDATE/DELETE, `rejected` rows part of the chain. `audit:verify` command.

## Clock scheduler

- `clocks` registry (21 seeded rows: type, default, amendable, fires_workflow) +
  `clock_timers` (armed instances; `override_value` slot pre-provisioned for Phase E's
  judge-set CLK-11/12). `ClockService.arm/fire/cancel`; amendable values resolved from
  `constitutional_settings` at evaluation time, never frozen at arm time.
- `EvaluateClocksJob` every minute via `routes/console.php` Schedule; new `scheduler`
  docker-compose service (`php artisan schedule:work`, env-driven name like the others).
- Phase A implements CLK-05 (residency threshold) + CLK-06 (critical population). Phase B
  hangs the no-skip election triggers (CLK-01/02) off the same registry.

## Activation engine

`jurisdiction_activations` table (separate — avoids touching PROTECTED jurisdictions
migration): `boundary_loaded → critical_population → bootstrapping → self_governing`.
`ActivationService`: CLK-06 fires → legislature row created — parents with children reuse
`apportionment:seed --jurisdiction=X` (cube-root over Σ children, type_a) + `type_b_seats =
count(direct children)` (Art. V §3); leaf jurisdictions get `clamp(round(cbrt(own_pop)), 5,∞)`
unicameral; exec + judiciary stubs via `InstitutionStubService` (extracted from
SetupController, shared with Setup Step 4). `jurisdiction:activate {slug} --force` for dev.

## Target schema — phase at a glance

| Phase | Tables |
|---|---|
| **A** | users (recreate) · sessions · residency_claims · location_pings (recreate) · jurisdiction associations · audit_log · clocks(+timers) · jurisdiction_activations · public_records (skeleton) · v_user_roles · settings evolutions |
| **B** | election_boards(+members) · elections (evolve) · election_races · candidacies · approvals(+standings) · ballot_envelopes · ballots · tabulations(+rounds) · race_results · certifications · election_audits · vacancies · terms · appointments · legislature_members (evolve) |
| **C** | legislature_sessions · attendance · motions · chamber_votes(+casts) · multi_jurisdiction_votes(+constituent_consents) · bills(+versions) · laws(+versions) · setting_changes · committees(+seats,preferences) · admin_offices · investigations · removal_proceedings · petitions(+signatures) · referendum_questions · emergency_powers(+renewals) |
| **D** | executives (evolve) · departments · boards(+seats) · executive_orders · policy_proposals · department_rules/reports · appropriations · grants · organizations (evolve) · org_memberships/workers/ownership_stakes/contracts/document_packages/transfers/conversions · cgc_ip_register |
| **E** | judiciaries (evolve) · advocates · cases(+parties) · panels · juries(+members) · filings · verdicts · sentencing_orders · warrants · opinions(+law_links) · constitutional_challenges |
| **F** | federation_peers · authority_claims · sync_log · audit_checkpoints · partition_exports · jurisdiction_maps(+members) · union/disintermediation processes · law_merge_resolutions · border_settlements · restoration_events · cultural_institutions |

Column-level detail for every table: `DESIGN_schema_engine.md` §A.

---

# ROADMAP A–F

| Phase | Bootstrap steps | Delivers | Exit criterion (operator can DO) |
|---|---|---|---|
| **A Foundation** (XL) | 1,2,4,5 + detection of 6 | Design system + shell · auth + identity (R-01→R-04) · engine + audit chain · clocks + scheduler · activation skeleton | Register → declare residency → 30 simulated ping-days → CLK-05 verifies → association chips at every level → rights auto-unlock; out-of-range setting rejected pre-commit + recorded; `jurisdiction:activate usa-1-new-york` yields a second correctly-sized bicameral legislature; `audit:verify` green |
| **B Elections** (XL) | 3, 6–9 | PROTECTED VoteCountingService (PR-STV/Droop + Gregory; RCV for individual exec; universal countback) · two-phase open ballot (CLK-18/21) · ballot commitment scheme · bootstrap board · certification auto-seating | A dev jurisdiction crosses critical population → board schedules → candidates register → approvals → finalists → ranked ballots with receipts → STV fills all seats → R-09 members seated, chamber browsable |
| **C Legislature ops** (XL) | 10–19 | Sessions/motions/peg-quorum votes · bicameral dual agreement · speaker (RCV supermaj) · committees (normalized-quota tie-break) · bills → versioned laws · referendums + petitions · emergency powers (CLK-03) · vacancy closed-loop · 90-day enforcement · public records | A seated legislature passes a bill into versioned law under peg quorum, unicameral AND bicameral; settings bill changes election interval and clocks re-derive |
| **D Exec + Orgs** (L) | 20–23 | Exec delegation/conversion (dual supermajority) · departments + BoG (10-yr CLK-09) · executive orders with pre-issuance scope validation · full org module: registration, membership/worker counts, co-determination (CLK-13/14), board elections, transfers, CGC public-domain IP register | Delegated exec governs departments with consented governors; 100-worker org auto-triggers first worker seat; out-of-scope order rejected on the record |
| **E Judiciary + Law** (L/XL) | 24–28 | Courts (equal constituent nominations) · cases/panels/juries/advocates · Art. IV §5 three-path challenge with per-case clocks and direct judicial law-text editing · amendments two-door | Any resident files F-IND-016 → full court finds contradiction → legislature misses both windows → judiciary edits law text directly, version history preserved |
| **F Federation + mobile** (XL) | 29–30 | Peer mesh + FF&C sync + authority flip (export bundle = seed, already built) · union formation/disintermediation/border settlement/restoration · Sanctum + Capacitor geofenced GPS pinging · full i18n | Two instances peer; a county instance becomes authoritative for its partition; a phone establishes residency by walking around |

Screens-per-phase and workflow IDs: `DESIGN_roadmap_phaseA.md` §A.

---

# PHASE A — WORK ITEMS (implementation-ready)

Critical path: WI-0 → WI-2 → WI-3 → WI-5 → WI-6 → WI-7. WI-1 fully parallel; WI-8 joins both
tracks; WI-4/WI-9 anywhere after deps. Full file-level detail: `DESIGN_roadmap_phaseA.md` §B.

**WI-0 〔S〕 Git sync.** Commit dirty WIP (review untracked GeojsonPrewarm files for
root-jurisdiction assumptions first); `git merge --ff-only main` (verified clean: main = HEAD
+ 24 mockup-only commits); push main. Commit the design appendices into
`docs/plans/institutions/`. Verify mockup assets exist in worktree.

**WI-1 〔L〕 Design-system port + shell.** Per `DESIGN_frontend_port.md`:
- CSS: `resources/css/cga/{tokens,fonts,components,dev-bar}.css` — mockup CSS ported
  verbatim-ish, imported in `layer(components)` under Tailwind v4 utilities (existing pages
  keep winning until migrated); `@theme inline` maps semantic tokens (`--color-gov-*`,
  `--color-adm-0..5`) as var() references. Fonts → `resources/fonts/` (14 woff2, Vite-hashed).
  `--adm-0..5` become canonical; tier names become deprecated aliases.
- Icons: `lucide-vue-next` (vite container npm install) wrapped in `Ui/Icon.vue` keeping the
  mockup's 38-name vocabulary + RTL directional flips.
- Components (priority P0 shell → P1 civic slice): `Shell/{AppHeader,AppSidebar,AppFooter,
  JurisdictionSwitcher,DevBar}.vue`; `Ui/{Icon,Btn,Banner,Card,Field,StatusBadge,CitationLine,
  FormChip,StateStrip,LifecycleTracker,HardenedChip,AmendableSetting,ThresholdMeter,Stat,
  Avatar,AdmChip,DataTable…}.vue` — thin wrappers over ported CSS classes, enum-validated props.
- `Layouts/AppShell.vue` registered as Inertia default layout (pages override); `main--wide` +
  new `main--flush` variant (the only net-new CSS) reproducing the Leaflet height contract for
  the 3 tools. NAV object ported to `resources/js/Navigation/nav.js` with `phase:` field —
  full constitutional sitemap visible from day one, unbuilt items "Planned · Phase X".
- i18n: vue-i18n now, **chrome-only** (~90 keys ported from i18n.js + pseudo-locale QA tool).
- Page convention: `surface` prop from `SurfaceMeta` (server-side registry =
  `config/cga/surfaces.php` + FormRegistry incl. alias table) → `Surface/PageScaffold.vue`
  (one h1, AboutSurface panel, citation footer) + `Surface/FormCard.vue` (name first, ID
  second; every state-changing form carries canonical `form_id` asserted server-side).
- Migration path for existing pages (sequenced, each shippable): CSS substrate → shell beside
  old AppLayout → Setup steps first → map tools with `main--flush` (highest regression risk:
  verify Leaflet sizing + drill-down manually) → Index/Home → delete old AppLayout + the
  `/api/setup/state` client fetch (replaced by shared prop).

**WI-2 〔L〕 audit_log + engine.** Migration (append-only, seq, hash chain, genesis, UPDATE/
DELETE trigger; soft-delete exception documented); `AuditService` (FOR UPDATE on last row,
sha256 chain, `verifyChain`); `FormRegistry` (103 canonical + aliases);
`ConstitutionalEngine::file()`; `ConstitutionalValidator` (PROTECTED) with Phase A rules;
handlers F-IND-001/002/003/005/006 + F-LEG-031 (validation/rejection path only);
`audit:verify` command. Verify: 100-entry chain green; tinker UPDATE raises; out-of-range
F-LEG-031 → `rejected=true` row citing Art. II §2.

**WI-3 〔M〕 Users UUID + session auth.** Hand-rolled Inertia session auth (no Breeze/Fortify —
they fight the bespoke stack; Sanctum deferred to F). Migration drops + recreates users
(uuid, languages jsonb, timezone, terms_accepted_at, is_operator) + sessions; converts
location_pings/residency_confirmations user FKs; adds the deferred executive_members.user_id
FK; grep-sweep other user_id columns. Auth controllers route registration through
`ConstitutionalEngine::file('F-IND-001', …)`. `HandleInertiaRequests` shares
auth/roles/jurisdiction-chain/instance/impersonation/setupComplete. Update
`SetupController::createFounder` (founder `is_operator=true`). Pages: `Auth/Register.vue`
(onboarding contract), `Auth/Login.vue`.

**WI-4 〔S〕 Dev impersonation.** Local-env-only routes + `Shell/DevBar.vue` (`.dev-bar` CSS):
impersonate user / view-context jurisdiction / RTL + pseudo-locale toggles / reset. Roles
always derived — bar displays the derivation chain, never forces a role. Test: 404 in prod.

**WI-5 〔L〕 Identity module.** `residency_claims` migration (ESM-02 states, one active claim
per user, ping consent required) + `claim_id` on location_pings. `ResidencyService`:
declare (F-IND-003) → recordPing (F-IND-005; audited as count-bump, coordinates stay private)
→ `qualifyingDays` (DISTINCT ping-days ST_Contains declared boundary) → `verify` (F-IND-006
system-filed: recursive-CTE ancestor sweep incl. dual-footprint twins, bulk association rows,
raw-ping purge). `RoleService`: R-01 = authenticated; R-02 = active claim; R-03 = active
associations; **R-04 ⇔ R-03** (Art. I — pinned by constitutional test). Controllers + routes
under `auth`+`/civic`. Dev `POST /dev/pings/simulate {days:30}` (backdated, gated like WI-4).

**WI-6 〔M〕 Clocks + scheduler.** `clocks` (21 seeded) + `clock_timers` (with
`override_value`); `ClockService`; `EvaluateClocksJob` every minute; CLK-05 job (idempotent
threshold evaluator → `ResidencyService::verify`); CLK-06 job (verified-resident count vs
`critical_population_threshold` — new nullable settings column, dev default 1 in
`config/cga.php`); new `scheduler` compose service. Verify: 21 rows; fires appear in audit_log;
WI-5 E2E passes through the real job path.

**WI-7 〔M〕 Activation engine.** `jurisdiction_activations` migration; `ActivationService`
(onCriticalPopulation → activate → legislature row via apportionment path + bicameral
type_b + stubs); `InstitutionStubService` extraction; `jurisdiction:activate {slug} --force`.
Verify: NY activation → second legislature with cube-root type_a, type_b = #counties, stubs;
operable at `/legislatures/{id}`; audit chain shows the steps; sizing math pinned by test.

**WI-8 〔L〕 Civic vertical slice.** `Civic/{Home,Residency,MyRecord,IdentityVerification}.vue`
+ controllers per the CIVIC contracts: Home = rights badges + association chips + honest
empty-states; Residency = flagship (StateStrip, ThresholdMeter ping-days, Leaflet declared
boundary via existing tile endpoints, F-IND-003 FormCard with jurisdiction search, consent
checkbox, manual ping + dev simulate, F-IND-006 3-state panel); MyRecord = own audit slice
(hashes shown) + F-IND-002 settings; IdentityVerification = attestation stub, "never a rights
requirement" banner. `/` redirects authed users to `/civic`. Stretch: `System/AuditChain.vue`
read-only LogRow viewer.

**WI-9 〔M〕 Multi-legislature touch-ups.** (verified list) `JurisdictionController::acceptMaps`
~405–430: derive apportionment scope from the accepting jurisdiction (drop the Earth-only
hardcode); `SetupController` step3 + `Step3_Districts.vue`: enumerate legislatures, resolve by
jurisdiction not LIMIT 1, reword (setup builds the FIRST legislature); Jurisdictions/Show CTA
renders for any jurisdiction with a legislature + activation status line;
`ApportionmentSeedCommand`: scope the `instance_settings.apportionment_completed_at` stamp to
setup-context runs (`--stamp-instance`); add a legislature switcher affordance in the shell.

**WI-T 〔M〕 Tests (woven through).** `tests/Constitutional/` = CI-gated hardened layer added
to the PROTECTED list: SupermajorityTest, SettingsBoundsTest, RightsAutomaticTest (no handler
can gate R-04 — architecture test), AuditChainTest (tamper at seq N detected),
ActivationMathTest (cube-root, bicameral trigger, 5–9 cap) + named-skip placeholders for B/C
(StvDroopGregoryTest, CountbackUniversalTest, BicameralDualAgreementTest, PegQuorumTest…).
`tests/Feature/` = auth, residency E2E on a **synthetic 4-level PostGIS fixture tree** (incl.
a dual-footprint pair; never the 951k production rows), job idempotency, impersonation
prod-404. CI: constitutional suite is a merge gate.

## Phase A risks
- UUID users rebuild must land before any other table references users (it's WI-3, early).
- Ancestor sweep must use the recursive-CTE path, not N queries.
- Design-system port balloons if re-skins become rewrites — token swap only on the 4,700-line
  mapper; `main--flush` Leaflet sizing is the single highest UI regression risk (manual verify).
- Scheduler container is a new operational surface (Horizon ≠ scheduler).
- Pre-provisioned for later phases (cannot retrofit cheaply): `clock_timers.override_value`
  (E) and the versioned-law substrate design (C).

---

# VERIFICATION (Phase A end-to-end)

1. `php artisan test --testsuite=Constitutional` green; full suite green.
2. Browser: register → log in (re-skinned UI, gold focus ring, Instrument Sans, zero external
   font requests) → declare residency at a county → `POST /dev/pings/simulate {days:30}` →
   scheduler tick → residency verified → association chips render the full chain to Earth →
   My Record shows hash-chained entries matching `audit_log`.
3. `php artisan audit:verify` green; tinker `UPDATE audit_log…` raises.
4. Out-of-range settings write via F-LEG-031 → 422 with citation + `rejected=true` row.
5. `php artisan jurisdiction:activate usa-1-new-york --force` → second legislature
   (cube-root type_a, type_b = county count) → district mapper opens on it → Earth untouched
   → Setup still green.
6. The three re-skinned tools: jurisdiction viewer, district mapper (drill-down, autoseed,
   antimeridian, labels), setup wizard — full manual pass, before/after screenshots.
7. `tools/qa_scan.py`-adapted scan over `resources/css/cga/` + `Components/` (zero hex outside
   tokens, logical properties only) wired into CI.
