All inputs read and codebase facts verified (including: local `main` holds the 24 mockup commits and `HEAD` is a clean fast-forward ancestor; stock bigint users table; no auth packages installed; empty scheduler; `generateInstitutionStubs()` already loops all legislatures; `acceptMaps` hardcodes Earth-only apportionment dispatch). Here is the design.

---

# CGA MASTER ROADMAP (A–F) + PHASE A IMPLEMENTATION PLAN

All file paths are under the worktree root `E:/fair-constitution-app/.claude/worktrees/practical-payne-17d537/` unless prefixed otherwise. Form/role/clock/workflow IDs are canonical per the Forms Catalog + MANIFEST §1 alias resolutions.

---

## A) ROADMAP A–F

Mapping: 30-step bootstrap (Stages 1–7) and the 76-week architecture phasing (Phases 0–6) onto six concrete increments for this codebase. The repo has already partially delivered Bootstrap Steps 1–2 (Docker stack, GIS load, apportionment, district mapper, setup wizard) — Phase A completes Stage 1–2 plumbing; B–F walk Stages 3–7.

### Phase A — Foundation (arch Phases 0–1; bootstrap steps 1, 2, 4, 5, and the *detection* half of 6)

**Deliverables**
- Git sync worktree ← main (mockup design system lands in-repo).
- Design system port: `colors_and_type.css` + `mockup.css` tokens into Tailwind v4 `@theme`, self-hosted Instrument Sans/Serif fonts, ~20 shared Vue components (MANIFEST §4), new `AppLayout` (header + cosmic-prefix jurisdiction switcher + role-aware sidebar). Re-skin of the 3 developed tools in their placement slots (jurisdiction browser, district mapper, setup wizard).
- Real auth: UUID users rebuild, hand-rolled Inertia session auth (register/login/logout), `HandleInertiaRequests` shared props, dev-only impersonation replacing the demo bar.
- Identity module: residency declaration (F-IND-003), manual/simulated pings (F-IND-005), CLK-05 verification job issuing F-IND-006, PostGIS ancestor-sweep associations, role derivation R-01→R-04 (Art. I automatic — no other gate).
- Constitutional engine skeleton: `ConstitutionalEngine::file()` form-dispatch with canonical-ID+alias `FormRegistry`, `ConstitutionalValidator` hardened-rule checks, append-only `audit_log` with cryptographic hash chaining (`hash(n)=H(hash(n−1)∥payload(n))`, genesis `000000…genesis`), rejections recorded too (WF-SYS-04).
- 21-clock registry table (seeded from the scheduler spec) + Laravel scheduler bootstrapping + CLK-05/CLK-06 evaluator jobs.
- Activation engine skeleton: critical-population check (CLK-06), `jurisdiction:activate` for dev, legislature instantiation (cube-root sizing, bicameral type_b = one per constituent) — a second legislature besides Earth exists in dev.

**Screens wired** (mockup → Vue page): `civic/onboarding` → `Pages/Auth/Register.vue` (+ `Pages/Auth/Login.vue`); `civic/identity-verification` → `Pages/Civic/IdentityVerification.vue` (minimal — never gates rights); `civic/residency` → `Pages/Civic/Residency.vue`; `civic/civic-home` → `Pages/Civic/Home.vue`; `civic/my-record` → `Pages/Civic/MyRecord.vue`; stretch: `system/audit-chain` → `Pages/System/AuditChain.vue` (read-only viewer over `audit_log`). Re-skinned slots: `jurisdiction-browser` ↔ `Pages/Jurisdictions/Show.vue`, `district-mapper` ↔ `Pages/Legislature/Show.vue`, `setup-wizard` ↔ `Pages/Setup/Step0–4`.

**Workflows executable**: WF-CIV-01 (onboarding), WF-CIV-02 (residency establishment, end-to-end with simulated pings), WF-SYS-04 (validation + audit chain, on the first mutating flows), WF-JUR-01 *steps 1–2 only* (critical population detected → legislature instantiated, `forming`).

**Exit criteria (operator can DO)**: register + log in on the re-skinned UI; declare residency anywhere; simulate 30 days of pings; watch CLK-05 verify residency and atomically grant R-02→R-03→R-04 with association chips at every nesting level; read their own hash-chained My Record; attempt an out-of-range settings write and see the engine reject it pre-commit with a recorded rejection; run `php artisan jurisdiction:activate usa-1-new-york` and browse a second, correctly sized, bicameral-flagged legislature at `/legislatures/{id}`; run `php artisan audit:verify` green.

**Key risks**: UUID users rebuild touches every future FK — must land before any other table references users; PostGIS ancestor sweep over ~951k rows needs the recursive-CTE path, not N queries; design-system port can balloon — re-skin slots by token swap only, no rewrites of the 4,700-line mapper; scheduler container is new operational surface (Horizon ≠ scheduler).

### Phase B — Elections Engine (arch Phase 2; bootstrap steps 3, 6–9)

**Deliverables**: elections/candidacies/approvals/ballots schema; two-phase open ballot (CLK-18 continuous approval, CLK-21 finalist cutoff X=3×seats); `VoteCountingService` (PROTECTED) — PR-STV/Droop with Gregory fractional transfers, single-winner RCV (individual exec only), countback engine (universal, no faction filter — q-ledger #q6); ballot commitment scheme (encrypt + receipt hash + anonymized hash publication); bootstrap election board (system-as-board, flagged temporary); election scheduling (F-ELB-001) with UTC windows; certification (F-ELB-004) auto-seating winners into `legislature_members`; minimal org endorsement handshake (F-CAN-002 → F-ORG-002 → R-07) so candidate profiles render — full org module stays in D; CLK-01/CLK-04/CLK-10 term clocks; queued tabulation on the `long-running` Horizon queue.

**Screens**: `electoral/election-detail` → `Pages/Elections/ElectionDetail.vue`; `electoral/candidacy-registration` → `Pages/Elections/CandidacyRegistration.vue`; `electoral/candidate-profile` → `Pages/Elections/CandidateProfile.vue`; `electoral/open-ballot` → `Pages/Elections/OpenBallot.vue`; `electoral/ranked-ballot` → `Pages/Elections/RankedBallot.vue`; `electoral/results` → `Pages/Elections/Results.vue` (round-by-round `STV_DATA` contract); `electoral/election-board-console` → `Pages/Elections/BoardConsole.vue` (bootstrap variant); `electoral/vacancy-countback` → `Pages/Elections/VacancyCountback.vue` (engine live; F-LEG-036 trigger arrives in C).

**Workflows**: WF-CIV-04, WF-CIV-05, WF-CIV-08, WF-ELE-01, WF-ELE-02 (completes WF-JUR-01 step 3), WF-ELE-03 (engine), WF-ELE-05 (audit re-run reframing), WF-ELE-10 (bootstrap half).

**Exit criteria**: a dev jurisdiction crosses critical population → bootstrap board auto-schedules → operator-played candidates register → approvals accumulate → cutoff freezes finalists → ranked ballots commit with verifiable receipts → instant STV fills all seats in one count → certification seats R-09 members → the chamber appears in the legislature browser. The Earth 1,999-seat race can also be exercised at district scope.

**Risks**: STV correctness is constitutional law — port a property-tested Gregory implementation behind the constitutional suite before any UI; ballot secrecy crypto (commitment scheme) needs a real design review, don't improvise; performance of approval standings at Earth scale (aggregate daily, never per-request).

### Phase C — Legislature Operations (arch Phase 3; bootstrap steps 10–19)

**Deliverables**: sessions/attendance/motions/statements, bill lifecycle with **laws/acts versioned table** (scale + scope + act-type fixed at introduction), peg-quorum vote engine (all serving denominators, supermajority `ceil(n·2/3)`), bicameral dual-agreement engine (per-kind peg quorum — q-ledger #q7), speaker election (supermajority RCV), committees (allocation formula, ranked prefs, normalized-quota tie-break — q-ledger #q2), admin office + ethics/rules, proper election board replacing bootstrap (WF-ELE-10 completion), referendum delegation + execution (rides B's ballots, F-IND-008, CLK-19 shield), petitions (WF-CIV-06, CLK-17, F-ELB-005), emergency powers (CLK-03 countdown, closed cause enum, pre-vote validation, locked agenda slot 1), vacancy → countback/special election fully closed-loop (F-LEG-036 → WF-ELE-03/04, CLK-04 hard window), CLK-02 90-day enforcement (WF-SYS-02), public records pipeline (WF-SYS-03), settings changes via bill (F-LEG-031/WF-LEG-14), relocation (WF-CIV-03 — needs vacancy machinery).

**Screens**: `legislature/legislature-home` → `Pages/Legislature/Chamber.vue`; `session-console` → `Pages/Legislature/SessionConsole.vue`; `bills` → `Pages/Legislature/Bills.vue`; `bill-detail` → `Pages/Legislature/BillDetail.vue`; `committees` → `Pages/Legislature/Committees.vue`; `committee-detail` → `Pages/Legislature/CommitteeDetail.vue`; `speaker-tools` → `Pages/Legislature/SpeakerTools.vue`; `oversight` → `Pages/Legislature/Oversight.vue`; `referendums` → `Pages/Legislature/Referendums.vue`; `emergency-powers` → `Pages/Legislature/EmergencyPowers.vue`; `settings` → `Pages/Legislature/Settings.vue`; plus `civic/petitions` → `Pages/Civic/Petitions.vue`, `civic/petition-detail` → `Pages/Civic/PetitionDetail.vue`, `civic/relocation` → `Pages/Civic/Relocation.vue`, `system/public-records` → `Pages/System/PublicRecords.vue`, `system/term-sync` → `Pages/System/TermSync.vue`.

**Workflows**: WF-LEG-01…20, WF-SYS-01/02/03, WF-CIV-03/06, WF-ELE-04/07 closed, WF-ELE-10 completed.

**Exit**: bootstrap step 19 — a seated legislature passes a bill into versioned law under peg quorum, with public records, in both unicameral and bicameral modes; a settings bill changes `election_interval_months` and the clocks re-derive.

**Risks**: bill/law/act versioning schema is the substrate for E (Art. IV §5 text edits) and F (disintermediation law-merge) — design it git-style now; bicameral per-kind tallies leak into every vote surface; emergency-power civic-process protection must be engine-level, not UI-level.

### Phase D — Executive & Organizations (arch Phase 4; bootstrap steps 20–23 + dependency-chain 4.7–4.10)

**Deliverables**: exec committee delegation (proportional selection, F-LEG-014), conversion to elected (dual-supermajority meters incl. constituent-jurisdiction votes — *needs C's multi-legislature voting*), individual-exec RCV + top-4 advisors by sequential exclusion, departments + charters + oversight, BoG nominate/consent (10-yr CLK-09), executive orders with pre-issuance scope validation (rejections on public record), full organizations module: registration (F-IND-012), membership/worker records (R-24/R-25 headcount), co-determination engine (CLK-13/14, `workerSeats` formula, board-validity rule), board elections (owner STV + worker STV + joint chair RCV reusing B's engine), transfers/monopoly acquisition (fair-market floor)/CGC creation with irreversible public-domain IP register. Activation engine upgraded: `executives`/`judiciaries` stubs flip from `forming` to operational paths.

**Screens**: `executive/executive-home` → `Pages/Executive/Home.vue`; `departments` → `Pages/Executive/Departments.vue`; `department-detail` → `Pages/Executive/DepartmentDetail.vue`; `department-reporting` → `Pages/Executive/DepartmentReporting.vue`; `executive-actions` → `Pages/Executive/Actions.vue`; `organizations/org-registry` → `Pages/Organizations/Registry.vue`; `org-detail` → `Pages/Organizations/OrgDetail.vue`; `cgc-detail` → `Pages/Organizations/CgcDetail.vue`; `board-elections` → `Pages/Organizations/BoardElections.vue`; `co-determination` → `Pages/Organizations/CoDetermination.vue`; `transfers-conversions` → `Pages/Organizations/TransfersConversions.vue`.

**Workflows**: WF-EXE-01…09, WF-ORG-01…10, WF-ELE-08.

**Exit**: bootstrap step 23 — delegated exec governs departments with consented governors; a 100-worker org auto-triggers its first worker seat; an out-of-scope executive order is rejected pre-issuance and the rejection is on the record.

**Risks**: constituent-jurisdiction supermajority votes require N live constituent legislatures (dev seeding strategy needed); co-determination recalc triggers on every headcount write (queue it).

### Phase E — Judiciary & Law (arch Phase 5; bootstrap steps 24–28)

**Deliverables**: judiciary creation/confirmation (equal constituent nominations, judicial-committee fallback), cases (claimed vs classified scale/severity), panels (CLK-16 hardened: ≥3, odd, severity-scaled, full court), juries (audit-chained random seed, voir dire), advocates (WF-CIV-07), opinions-as-commentary linkage, Art. IV §5 three-path challenge workflow with per-case CLK-11/CLK-12 and **direct judicial law-text editing** (new law version, diff render), emergency-powers review (WF-JUD-06), petition constitutionality review (WF-JUD-09 — closes C's petition kill-path), double-jeopardy machine flag, elected-judiciary conversion (WF-ELE-09, ≥5/race).

**Screens**: `judiciary/judiciary-home` → `Pages/Judiciary/Home.vue`; `case-docket` → `Pages/Judiciary/CaseDocket.vue`; `case-detail` → `Pages/Judiciary/CaseDetail.vue`; `juror-view` → `Pages/Judiciary/JurorView.vue`; `advocate-console` → `Pages/Judiciary/AdvocateConsole.vue`; `constitutional-challenge` → `Pages/Judiciary/ConstitutionalChallenge.vue`; `civic/advocate-registration` → `Pages/Civic/AdvocateRegistration.vue`; `system/amendments` → `Pages/System/Amendments.vue`.

**Workflows**: WF-JUD-01…09, WF-CIV-07, WF-ELE-09, WF-SYS-05 (amendment two-door).

**Exit**: bootstrap step 28 — any resident files F-IND-016, full court finds contradiction, legislature misses both windows, judiciary edits the law text directly, version history preserved, executives enforce.

**Risks**: per-case clocks (CLK-11/12) are judge-set, not settings-read — clock registry must support per-instance overrides (design that into Phase A's `clock_timers` now); law-diff UX depends on C's versioning being clean.

### Phase F — Federation & Mobile GPS (arch Phase 6; bootstrap steps 29–30)

**Deliverables**: peer mesh (discovery/handshake/trust/heartbeat CLK-20), Full Faith & Credit sync (signed broadcast, authoritative-wins, sync_log into audit chain, head-hash checkpoints), authority claims + partition export/authority flip (setup export bundle = federation seed, already built), union formation (compatibility diff over `constitutional_settings`, codification, dual-supermajority ratification, new encompassing bicameral row), disintermediation (unanimity + law-merge engine), border settlement (affected-population referendum + re-association sweep), restoration mode (Art. VI monitors, 3-tier cascade), Sanctum token auth + Capacitor wrapper with geofenced GPS pinging replacing manual pings, full i18n of the public-records pipeline.

**Screens**: `jurisdictions/federation` → `Pages/Jurisdictions/Federation.vue`; `union-formation` → `Pages/Jurisdictions/UnionFormation.vue`; `disintermediation` → `Pages/Jurisdictions/Disintermediation.vue`; `restoration` → `Pages/Jurisdictions/Restoration.vue`; `bootstrap` → `Pages/Jurisdictions/Bootstrap.vue` (the 30-step tracker, fed by real activation records).

**Workflows**: WF-JUR-02…08, WF-CIV-03 federation notify, WF-JUR-06 continuous.

**Exit**: two instances peer, a county instance becomes authoritative for its partition, a phone establishes residency by walking around.

**Risks**: protocol design (ActivityPub + extensions) is research-grade — prototype early in E; mobile ping privacy (encrypted at rest, purge-on-verify) gets regulator-level scrutiny.

---

## B) PHASE A — IMPLEMENTATION-READY WORK ITEMS

Ordered by dependency. (Operator's enumeration mapped: item 1→WI-0, 2→WI-3, 3→WI-5, 4→WI-2 [engine must precede auth so registration is audit-chained], 5→WI-6, 6→WI-7, 7→WI-8, 8→WI-9.)

### WI-0 — Git sync worktree ← main 〔S〕
- Commit the dirty WIP first (`app/Console/Commands/GeojsonPrewarmCommand.php`, `app/Jobs/PrewarmGeojsonCachesJob.php`, 11 modified files) — the mockup commits touch only `mockups/`, so the merge itself is conflict-free, but keep history clean.
- `git merge --ff-only main` (verified: `HEAD` is an ancestor of local `main`; `origin/main` does NOT yet have the mockup commits — push main afterwards).
- **Verify**: `mockups/MANIFEST.md`, `mockups/assets/css/{colors_and_type.css,fonts.css,mockup.css}`, `mockups/assets/fonts/*.woff2` exist in the worktree; `git status` clean.

### WI-1 — Design-system port + AppLayout 〔L〕
Files:
- `resources/css/tokens.css` — translate `mockups/assets/css/colors_and_type.css` custom properties into Tailwind v4 `@theme` tokens (color ramp incl. `--adm-0…5` aliases, type scale, radii, spacing); imported by `resources/css/app.css`.
- `resources/css/fonts.css` + `public/fonts/*.woff2` — copy the 18 self-hosted Instrument woff2 files from `mockups/assets/fonts/`; `@font-face` with local paths (offline/LAN rule preserved).
- `resources/js/Components/` — port the MANIFEST §4 inventory (~20): `AdmChip.vue`, `StatusBadge.vue`, `Citation.vue`, `HardenedRule.vue`, `AmendableSetting.vue`, `FormChip.vue`, `OrgChip.vue`, `PersonaChip.vue`, `Card.vue`, `Btn.vue`, `Field.vue`, `Stat.vue`, `RegistryTable.vue`, `FlowStepper.vue`, `StateStrip.vue`, `LifecycleTracker.vue`, `Banner.vue`, `ThresholdMeter.vue`, `JurisdictionSwitcher.vue`, `Icon.vue` (inlines `mockups/assets/img/icons/*.svg`).
- `resources/js/Layouts/AppLayout.vue` — rebuild: header (instance name + cosmic-prefix `JurisdictionSwitcher` reading `/api/cosmic-addresses/default-path` + jurisdiction chain), role-aware sidebar (sections appear per derived roles from shared props — Civic always; Legislature/Executive/etc. appear in later phases), keep `SchemaUpdateBanner`, keep `hideNav` for setup. Slot for impersonation banner (WI-4).
- Re-skin only (no logic changes): `resources/js/Pages/Jurisdictions/Show.vue`, `resources/js/Pages/Legislature/Show.vue`, `resources/js/Pages/Setup/Step0–4 + Bootstrap.vue`, `resources/js/Pages/Home.vue` — swap hand-rolled gray-950 classes for tokens; placement slots per MANIFEST §7 stay put.
- **Verify**: `docker compose exec vite npm run build` clean; visual pass on `/`, `/setup`, `/jurisdictions/earth-0-earth`, `/legislatures/{id}`; fonts load with zero external requests (network tab).

### WI-2 — audit_log + AuditService + Constitutional Engine skeleton 〔L〕
Files:
- `database/migrations/2026_06_12_000001_create_audit_log_table.php` — `id uuid pk default gen_random_uuid()`, `seq bigserial unique`, `occurred_at timestamptz`, `actor_user_id uuid null`, `module string`, `event string`, `ref string null` (F-/WF-/CLK- id), `jurisdiction_id uuid null`, `payload jsonb`, `prev_hash char(64)`, `hash char(64)`, `rejected boolean default false`, `blocked_reason text null`. Genesis row seeded (`hash = '000000…genesis'`). Postgres trigger raising an exception on UPDATE/DELETE (append-only by construction). **No soft deletes** — deliberate convention exception, documented in the migration docblock.
- `app/Services/AuditService.php` — `append(string $module, string $event, array $payload, ?string $ref=null, ?string $actorId=null, ?string $jurisdictionId=null, bool $rejected=false, ?string $blockedReason=null): AuditEntry`. Inside the caller's DB transaction: `SELECT … ORDER BY seq DESC LIMIT 1 FOR UPDATE`, compute `hash = hash('sha256', prev_hash . canonical_json(payload))`. Plus `verifyChain(): bool` walking the chain.
- `app/Models/AuditEntry.php`.
- `app/Domain/Forms/FormRegistry.php` — the 103 canonical form IDs + alias map (F-IND-004↔005 swap, F-IND-016←F-IND-013, F-LEG-022/023/024/025/034/036 drift, F-CHR←F-COM, F-BOG←F-GOV); `canonical(string $id): string`. Seed data as a plain PHP array (the registry is constitutional-static, not DB).
- `app/Domain/Engine/ConstitutionalEngine.php` — `file(string $formId, ?User $actor, array $payload): EngineResult`: canonicalize ID → role-authorize via `RoleService` (WI-5) → `ConstitutionalValidator::check()` → handler `handle()` in transaction → `AuditService::append` same transaction. Violations throw `ConstitutionalViolation(citation)`; the engine catches, appends a `rejected=true` entry, rethrows for HTTP 422.
- `app/Services/ConstitutionalValidator.php` (PROTECTED — flag for constitutional review) — rule registry. Phase A rules: `settings.bounds` (hardened min/max per key, from `ConstitutionalDefaults`), `seats.range` (5–9 per district/chamber where no constituents), `supermajority.formula` helper `ceil(serving * 2/3)` with majority+1 floor, `rights.automatic` (no handler may add requirements to R-04 derivation — enforced as a guard that the residency handlers cannot consult anything but association).
- `app/Domain/Forms/Handlers/` — Phase A handlers: `IndividualRegistration.php` (F-IND-001), `ProfileManagement.php` (F-IND-002), `ResidencyDeclaration.php` (F-IND-003), `GpsResidencyPing.php` (F-IND-005), `ResidencyVerificationConfirmation.php` (F-IND-006, system-filed), `AmendableSettingChange.php` (F-LEG-031 — Phase A: validation + rejection path only; legislative path comes in C; lets the operator demo a recorded rejection).
- `app/Console/Commands/AuditVerifyCommand.php` — `audit:verify`.
- **Verify**: feature test appends 100 entries, `audit:verify` green; manual `UPDATE audit_log …` in tinker raises; out-of-range F-LEG-031 produces a `rejected=true` row citing Art. II §2.

### WI-3 — Users UUID rebuild + session auth 〔M〕
**Recommendation: hand-rolled Inertia session auth.** No Breeze/Jetstream (their scaffolding fights the existing bespoke Tailwind v4 `@theme` + custom AppLayout + non-kit Inertia wiring, and their stock pages don't match the F-IND-001 contract); no Fortify (headless but brings 2FA/feature surface we don't need; route/middleware count for plain register/login/logout is ~4 small controllers). Sanctum deferred to Phase F (mobile). Use the stock `web` session guard, `Hash`, login throttling.

Files:
- `database/migrations/2026_06_12_000002_rebuild_users_uuid.php` — drop+recreate `users` (table is effectively empty; only `SetupController::createFounder` writes to it): `id uuid pk`, `name`, `display_name null`, `email unique`, `email_verified_at timestamptz null`, `password`, `languages jsonb default '[]'`, `timezone string default 'UTC'`, `terms_accepted_at timestamptz`, `is_operator boolean default false`, `remember_token`, `deleted_at`, `timestamps` (timestamptz). Recreate `sessions.user_id` as uuid. Alter `location_pings.user_id` + `residency_confirmations.user_id` `unsignedBigInteger→uuid` (drop/re-add FK; tables empty — verified unused). `executive_members.user_id` is already uuid; add its FK now (the migration comment at `database/migrations/2026_04_25_000002` line 75 defers exactly this). Grep-sweep for other `user_id` columns before finalizing.
- `app/Models/User.php` — `HasUuids`, `SoftDeletes`, casts, `Authenticatable`.
- `app/Http/Controllers/Auth/RegisteredUserController.php` — `create()`/`store()`; `store` routes through `ConstitutionalEngine::file('F-IND-001', null, $payload)` then `Auth::login`. Fields per the onboarding contract: `name`, `languages[]`, `timezone`, `terms` (required-true).
- `app/Http/Controllers/Auth/AuthenticatedSessionController.php` — login/logout with throttle + session regeneration.
- `routes/auth.php` — guest: `GET/POST /register`, `GET/POST /login`; auth: `POST /logout`; required from `routes/web.php`.
- `app/Http/Middleware/HandleInertiaRequests.php` — subclass `Inertia\Middleware`; shares `auth: { user, roles: [R-01..], associations: [chips], impersonating }`, `instance: { name, setupComplete }`. Register in `bootstrap/app.php` replacing the bare `\Inertia\Middleware::class` (line 16).
- `resources/js/Pages/Auth/Register.vue` (onboarding contract: Individual state strip, languages multiselect, timezone, terms validation), `resources/js/Pages/Auth/Login.vue`.
- Update `SetupController::createFounder` (~line 255) and the `Schema::hasTable('users')` probe (~line 335) for the new columns; founder gets `is_operator = true`.
- **Verify**: feature tests — register creates uuid user + `F-IND-001` audit row; login/logout; guest redirect from `/civic/*`; founder creation in the bootstrap page still works.

### WI-4 — Dev impersonation tool 〔S〕
- `app/Http/Controllers/Dev/ImpersonationController.php` + routes registered **only** when `app()->environment('local') && config('cga.impersonation')`: `GET /dev/users` (search), `POST /dev/impersonate/{user}`, `POST /dev/impersonate/stop`. Stores `impersonator_id` in session; requires `is_operator` on the real account.
- `resources/js/Components/DevImpersonationBar.vue` — the demo-bar replacement, rendered in `AppLayout` when `auth.impersonating` shared prop is set (amber banner: "Viewing as X — return").
- `config/cga.php` — new config home (`impersonation`, later: clock cadence, activation defaults).
- **Verify**: test asserts routes 404 in `production` env; impersonation flips shared `auth.user` + roles.

### WI-5 — Identity module: residency, pings, sweep, roles 〔L〕
Files:
- `database/migrations/2026_06_12_000003_create_residency_claims_table.php` — the Residency Claim state machine: `id uuid`, `user_id uuid FK`, `jurisdiction_id uuid FK` (smallest declared boundary), `status` string enum (`declared|ping_monitoring|threshold_met|verified|active|superseded|lapsed`), `ping_consent_at timestamptz` (required), `qualifying_days int default 0`, `declared_at`, `verified_at null`, `superseded_at null`, soft deletes, unique partial index one active claim per user. Add `claim_id uuid null` to `location_pings`.
- `app/Models/{ResidencyClaim,LocationPing,ResidencyConfirmation}.php`.
- `app/Services/ResidencyService.php` —
  - `declare(User, Jurisdiction)`: via engine F-IND-003; supersedes nothing yet (relocation is Phase C); starts `ping_monitoring`.
  - `recordPing(User, lat, lng, source)`: engine F-IND-005 (audited as a count-bump, not coordinates — pings stay private); insert into `location_pings` (geom trigger fills point).
  - `qualifyingDays(claim)`: `SELECT count(DISTINCT date_trunc('day', pinged_at)) FROM location_pings p JOIN jurisdictions j ON j.id = :declared WHERE p.claim_id = :claim AND ST_Contains(j.geom, p.geom)`.
  - `verify(claim)`: system-files F-IND-006 → sets claim `verified→active`, **ancestor sweep**: recursive CTE up `jurisdictions.parent_id` from the declared jurisdiction (plus dual-footprint twins per the dual-footprint rule — same footprint pairs both get rows), bulk-insert `residency_confirmations` one row per enclosing level (`days_confirmed`, `confirmed_at`, rights booleans true — the columns already encode Art. I), purge raw `location_pings` rows for the claim (keep `qualifying_days`), audit entry per the confirmation (single entry, association list in payload).
- `app/Services/RoleService.php` — pure derivation, no role table (roles are *derived*, never granted): R-01 = authenticated; R-02 = has any active `residency_claims.status='active'`; R-03 = has active `residency_confirmations`; R-04 = R-03 (identical by Art. I — encoded as `R-04 ⇔ R-03`, with a constitutional test pinning it). Returns role list + association chain for shared props. Laravel Gates: `Gate::define('associated', …)` etc.; route middleware alias `role:R-03` for later phases.
- `app/Http/Controllers/Civic/ResidencyController.php` — `show` (Inertia: claim, meter, threshold from `constitutional_settings.residency_confirmation_days` of the declared jurisdiction, confirmation-panel state), `declare`, `confirm` ("this is my residence"), `redeclare` ("correct the boundary" → new F-IND-003).
- `app/Http/Controllers/Civic/PingController.php` — `store` (manual ping: browser geolocation or lat/lng fields); dev-only `simulate` endpoint (`POST /dev/pings/simulate {days: 30}` — backdated one-per-day pings inside the declared boundary; gated like WI-4).
- Routes in `routes/web.php` under `Route::middleware('auth')->prefix('civic')` group.
- **Verify**: E2E feature test — declare → simulate 29 days (panel locked) → 30th day + CLK-05 job (WI-6) → confirm → assert `residency_confirmations` rows exist for the full ancestor chain (synthetic 4-level fixture tree), rights booleans true, raw pings purged, roles = R-01..R-04.

### WI-6 — Clock registry + scheduler + CLK-05/CLK-06 jobs 〔M〕
Files:
- `database/migrations/2026_06_12_000004_create_clocks_tables.php` — `clocks` (21 seeded registry rows: `id` = 'CLK-01'… string pk, `name`, `type` (recurring|countdown|window|threshold|derived|flag), `default_value jsonb`, `amendable boolean`, `fires_workflow`, `basis`) + `clock_timers` (`id uuid`, `clock_id FK`, `jurisdiction_id null`, `subject_type/subject_id` nullable morph, `armed_at`, `fires_at null` (null = threshold-watch), `state` (`armed|fired|cancelled|expired`), `payload jsonb`, `override_value jsonb null` ← per-case override slot needed by CLK-11/12 in Phase E). Seeder `database/seeders/ClockRegistrySeeder.php` from the scheduler spec table.
- `app/Services/ClockService.php` — `arm()`, `fire()` (appends audit entry `module=Clocks, ref=CLK-xx`, dispatches the mapped job), `cancel()`; resolves amendable values from `constitutional_settings` per jurisdiction at evaluation time (never frozen at arm time).
- `app/Jobs/EvaluateClocksJob.php` — sweep due `fires_at` timers + threshold clocks; dispatches per-clock jobs.
- `app/Jobs/Clocks/EvaluateResidencyThresholdsJob.php` (CLK-05) — for claims in `ping_monitoring`: recompute `qualifyingDays`; ≥ threshold → `ResidencyService::verify` (which files F-IND-006). Idempotent.
- `app/Jobs/Clocks/EvaluateCriticalPopulationJob.php` (CLK-06) — per civic-active, not-yet-activated jurisdiction: `count(residency_confirmations where jurisdiction_id = X and is_active)` vs threshold → `ActivationService::onCriticalPopulation` (WI-7). Threshold source: new nullable `critical_population_threshold` column on `constitutional_settings` (migration in this WI) falling back to a dev default in `config/cga.php` (e.g. 1 — so a single verified resident activates a jurisdiction in dev; production tiers come later, per owner ruling #15: player population pegged against real population).
- `routes/console.php` — `Schedule::job(new EvaluateClocksJob)->everyMinute()->withoutOverlapping()` + `Schedule::command('horizon:snapshot')->everyFiveMinutes()`.
- `docker-compose.yml` — new `scheduler` service (same image/profile as `fc_horizon`, command `php artisan schedule:work`, env-driven container name like the others).
- **Verify**: `php artisan schedule:run` manually fires the sweep; the WI-5 E2E test goes green through the real job path; `clocks` table shows 21 rows; every fire appears in `audit_log`.

### WI-7 — Activation engine skeleton 〔M〕
Files:
- `database/migrations/2026_06_12_000005_create_jurisdiction_activations_table.php` — separate table (avoids touching the PROTECTED jurisdictions migration): `id uuid`, `jurisdiction_id uuid unique FK`, `state` (`boundary_loaded|critical_population|bootstrapping|self_governing`), `critical_population_at null`, `activated_at null`, `legislature_id uuid null`, `notes jsonb`, timestamps, soft deletes. (Jurisdiction entity machine states beyond these arrive with their phases.)
- `app/Services/ActivationService.php` —
  - `onCriticalPopulation(Jurisdiction)`: upsert activation row → `critical_population`, audit (`ref=CLK-06`, `WF-JUR-01`).
  - `activate(Jurisdiction)`: → `bootstrapping`; ensure a legislature row: jurisdictions **with direct children** → reuse the existing sizing path (`Artisan::call('apportionment:seed', ['--jurisdiction' => $id])` — cube-root over Σ direct-children population, type_a) **plus** bicameral: set `type_b_seats = count(direct children)` (one per constituent, Art. V §3 — both kinds required whenever constituents exist); **leaf jurisdictions** → `type_a_seats = clamp(round(cbrt(own population)), 5, ∞)`, unicameral, districted later if >9 (the 5–9 cap is per district/voter-pool, not chamber size); status `forming`, `term_number = 1`. Then create exec/judiciary stubs via extracted service (below). Audit each step — these become the Bootstrap-tracker records (Phase F screen).
- `app/Services/InstitutionStubService.php` — extract `SetupController::generateInstitutionStubs()` (verified: already loops all legislatures) so both Setup Step 4 and `ActivationService` share it; `SetupController` delegates.
- `app/Console/Commands/JurisdictionActivateCommand.php` — `jurisdiction:activate {slug} {--force}` for dev: bypass population check, run `activate()`.
- **Verify**: `php artisan jurisdiction:activate usa-1-new-york --force` → second legislature row with cube-root `type_a_seats`, `type_b_seats = (#NY counties)`, exec+judiciary stubs; visible/operable at `/legislatures/{newId}` (controller already parameterized); `audit_log` shows the activation chain; constitutional test pins sizing math.

### WI-8 — Civic vertical slice (Home, My Record, Residency) 〔L〕
Files:
- `resources/js/Pages/Civic/Home.vue` + `app/Http/Controllers/Civic/HomeController.php` — per the civic-home contract: rights badges (from shared roles — "Voting unlocked"/"Candidacy unlocked", hardened framing), association chip chain (real `residency_confirmations` join up the parent chain), elections list (empty-state honest: "No elections scheduled — Phase B"), petitions list (empty-state), my-record stats, emergency banner slot (scenario stub until C).
- `resources/js/Pages/Civic/Residency.vue` + `ResidencyController::show` (WI-5) — Residency Claim `StateStrip`, ping-day `ThresholdMeter` (`{qualifying_days}/{threshold}`), declared-boundary Leaflet map (reuse existing tile endpoints from the jurisdiction viewer), F-IND-003 declare form with jurisdiction search (slug/name search endpoint on `JurisdictionController` or new `Civic/JurisdictionSearchController`; smallest-boundary picker), required ping-consent checkbox (server-rejected without), manual-ping button + dev simulate, F-IND-006 panel in its 3 contract states (locked/pending-confirm-or-correct/verified with chips for every level).
- `resources/js/Pages/Civic/MyRecord.vue` + `app/Http/Controllers/Civic/MyRecordController.php` — the user's own audit-chain slice: `{seq, date (UTC stored, user-tz shown), type, text, hash}` for `actor_user_id = me` (Account created / Residency declared / Residency verified); stats; personal settings panel (F-IND-002 via engine: display name, language, timezone, ping pause toggle); **never** ballot content or raw locations (structurally true — they're never written).
- `resources/js/Pages/Civic/IdentityVerification.vue` (minimal) — manual attestation-request path only, "verification is never a rights requirement; skipping always allowed" banner; external-ID bridge = Phase F.
- Routes: `/civic`, `/civic/residency`, `/civic/record`, `/civic/identity` under `auth` middleware; `/` redirects authenticated users to `/civic` (Home.vue stays the logged-out landing).
- **Verify**: Manual walkthrough of the WI-5 E2E in the browser; chips render the full chain incl. Earth; My Record hashes match `audit_log` rows.

### WI-9 — Multi-legislature touch-ups to existing tools 〔M〕
Verified single-legislature assumptions to fix (and non-issues to leave):
1. **`app/Http/Controllers/JurisdictionController.php` `acceptMaps` (~line 405–430)** — hardcodes `adm_level = 0` lookup and dispatches `apportionment:seed --jurisdiction=<earth>` ("Earth-only scope for now"). Change: derive the scope from the jurisdiction whose maps were accepted (the route already knows it), falling back to planet root for the setup path.
2. **`app/Http/Controllers/SetupController.php` `step3Summary`/`completeStep3` + `resources/js/Pages/Setup/Step3_Districts.vue`** — copy and summary assume "1 legislature, 1,999 seats, Earth". Generalize the summary to enumerate legislatures (`COUNT`, per-row seats) and reword: setup builds the *first* legislature; others activate via CLK-06. Handoff link resolves legislature by jurisdiction, not `LIMIT 1`.
3. **`SetupController::generateInstitutionStubs`** — already loops all legislatures (no change needed beyond the WI-7 extraction so activation reuses it).
4. **`resources/js/Pages/Jurisdictions/Show.vue` / `JurisdictionController::show`** — already resolves `legislature_id` dynamically per jurisdiction (lines 62–76); the old hardcoded-UUID entry link is gone. Ensure the "View Legislature & Districts" CTA renders for any jurisdiction with a legislature row (not gated on adm_level 0) and add an "Activation" status line (from `jurisdiction_activations`) so dormant vs self-governing reads correctly.
5. **`app/Console/Commands/ApportionmentSeedCommand.php`** — already per-jurisdiction-parameterized and level-sweeping; no Earth assumption inside. Leave; just make `ActivationService` its second caller. Note: it stamps the global `instance_settings.apportionment_completed_at` on every run — scope that stamp to setup-context runs only (flag `--stamp-instance`), so dev activations don't rewrite setup state.
6. **`resources/js/Pages/Legislature/Show.vue` / `LegislatureController`** — already parameterized by `legislature_id` with scope drill-down; only needs a legislature *switcher* affordance in the new AppLayout (list legislatures by jurisdiction) instead of relying on memory of UUIDs.
7. **Review with fresh eyes**: untracked `app/Jobs/PrewarmGeojsonCachesJob.php` + `app/Console/Commands/GeojsonPrewarmCommand.php` for root-jurisdiction assumptions before committing them in WI-0.
- **Verify**: after `jurisdiction:activate`, Setup remains green, `/jurisdictions/usa-1-new-york` shows its own legislature CTA, district mapper opens on the NY legislature, and the Earth legislature is untouched.

---

## C) TESTING STRATEGY

**Two-tier suite, mirroring the two-layer hardening:**

1. **`tests/Constitutional/` — the CI-gated hardened layer ("executable constitutional law").** Separate PHPUnit testsuite in `phpunit.xml`; a dedicated CI job that must pass for any merge; the PROTECTED-files list in CLAUDE.md grows to include this directory. Phase A skeleton:
 - `SupermajorityTest` — `ceil(serving × 2/3)` over serving (not present, not seats): 8 serving → 6; 9 → 6; floor never below majority+1; vacancies stay in the denominator.
 - `SettingsBoundsTest` — every amendable key: in-range accepted; out-of-range rejected **pre-commit** with citation; rejection row appended `rejected=true`; hardened keys immutable through every write path.
 - `RightsAutomaticTest` — creating an active residency confirmation yields R-02→R-03→R-04 with **zero** additional conditions: assert `RoleService` derives R-04 from R-03 identity; assert no handler in `app/Domain/Forms/Handlers` can register a gate on voter derivation (reflection/architecture test); identity verification status has no effect on roles.
 - `AuditChainTest` — append N entries, `verifyChain()` true; mutate one payload at the DB level (trigger disabled inside the test) → verify fails at exactly that seq; UPDATE/DELETE on `audit_log` raises; rejected entries are part of the chain.
 - `ActivationMathTest` — cube-root sizing: known populations → expected seats (incl. `max(5, …)` floor, leaf own-population fallback, Earth ≈1,999 regression from a synthetic fixture); bicameral trigger: constituents present ⇒ `type_b_seats = child count`, none ⇒ unicameral; 5–9 per-district cap enforced by validator.
 - Placeholders (skipped, named now so the suite *is* the roadmap): `StvDroopGregoryTest`, `CountbackUniversalTest`, `BicameralDualAgreementTest`, `PegQuorumTest`, `EmergencyCeilingTest`, `TermLockstepTest`.

2. **`tests/Feature/` — Phase A behavior.** Auth (register files F-IND-001 + audit row; throttling; guest redirects); residency E2E with `Carbon::setTestNow` fast-forward + simulated pings against a **synthetic 4-level PostGIS fixture tree** (Planet→Country→State→County squares; never the 951k production rows) including a dual-footprint pair (both twins get confirmations); ancestor sweep correctness + ping purge; CLK-05/CLK-06 job idempotency (re-runs don't double-confirm/double-activate); impersonation 404 in production; activation command creates exec/judiciary stubs exactly once.

**What Phase A tests prove**: residency→rights is automatic and ungateable; the audit chain is tamper-evident end-to-end including rejections; activation/sizing math matches the sizing law and bicameral rule. **Infra**: tests run against the dockerized Postgres/PostGIS on a dedicated test database (`RefreshDatabase`); CI = `docker compose exec app php artisan test --testsuite=Constitutional` gate + full suite.

---

## D) SEQUENCING + RELATIVE SIZE

| # | Work item | Size | Depends on |
|---|---|---|---|
| WI-0 | Git sync worktree ← main (+ commit WIP) | S | — |
| WI-1 | Design system + components + AppLayout + re-skin slots | L | WI-0 |
| WI-2 | audit_log + AuditService + engine skeleton + FormRegistry + validator | L | WI-0 |
| WI-3 | Users UUID rebuild + session auth + HandleInertiaRequests | M | WI-2 (registration audit-chained), WI-1 (pages) |
| WI-4 | Dev impersonation tool | S | WI-3 |
| WI-5 | Identity: claims, pings, sweep, RoleService | L | WI-2, WI-3 |
| WI-6 | Clock registry + scheduler container + CLK-05/06 jobs | M | WI-5 |
| WI-7 | Activation engine + `jurisdiction:activate` + stub extraction | M | WI-6 |
| WI-8 | Civic slice: Home / Residency / My Record / Identity | L | WI-1, WI-5 |
| WI-9 | Multi-legislature touch-ups (acceptMaps, Step 3, CTA, stamp scoping) | M | WI-7 |
| WI-T | Constitutional suite skeleton + feature tests (woven through, gate at end) | M | WI-2..WI-7 |

**Critical path**: WI-0 → WI-2 → WI-3 → WI-5 → WI-6 → WI-7 (backend spine). WI-1 runs fully parallel; WI-8 joins both tracks; WI-4/WI-9 slot anywhere after their deps. Phase A total ≈ XL (roughly 2× the elections engine's L core, because it carries all one-time infrastructure: auth rebuild, audit chain, scheduler, design system).

**Phase relative sizes**: A = XL (infrastructure-heavy) · B = XL (STV + ballot crypto are unforgiving) · C = XL (20 workflows, law versioning, most screens) · D = L · E = L/XL (case machinery + law editing) · F = XL (protocol design + mobile).

Key risk to carry forward from Phase A's design: `clock_timers.override_value` and the versioned-law substrate (Phase C) are the two pieces later phases cannot retrofit cheaply — both are pre-provisioned above.