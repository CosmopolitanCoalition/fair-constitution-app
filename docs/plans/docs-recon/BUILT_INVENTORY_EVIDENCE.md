# BUILT INVENTORY — Evidence Appendix (machine-generated, 2026-07-25)

Raw per-item findings from the 14-agent built-vs-unbuilt audit behind
[BUILT_INVENTORY.md](BUILT_INVENTORY.md). Each module block: overall verdict, headline,
then every judged item with status ([BUILT]/[PARTIAL]/[SUBSTRATE]/[ABSENT]), file:line
evidence, and notes. Generated from agent structured output — grep it, don't prettify it.



===== [phase-I] Phase I — Activation Tiers & the Reach/Legitimacy Metric (charter lines 196-231) — PARTIAL =====
Phase I is half-built, not unbuilt. Its (a) half — the activation gate that decides when a jurisdiction's government may boot — exists end-to-end and is live: the jurisdiction_activations state machine, the CLK-06 critical-population clock swept every minute, threshold resolution through the constitutional_settings cascade (dev default 1, exactly the posture the charter's exit criterion requires), the full WF-JUR-01 bootstrap pipeline, an artisan entry point, and a wired UI status line. What that half lacks is only the population-pegged CURVE (clamp(ceil(k*pop^1/3), floor, cap) as settings params + ActivationTierService) — today the threshold is a flat per-jurisdiction integer, though the clock registry already pre-declares 'per_jurisdiction_tier' semantics awaiting it. The (b) half — LegitimacyService, reach ratio, legitimacy_snapshots, SnapshotLegitimacyJob, k-anon leaderboard — is ABSENT from app code (no table, no migration, no service, no route, no test), but both rails it needs are built (active residency-confirmation counts as numerator; jurisdictions.population/population_year/population_baseline WorldPop provenance as denominator) and its display contract is fully designed in mockups v2/v3 with a registered-but-unwired 'Reach' surface stub. The 'live sizing law' the ledger cites is real (ActivationService::cubeRootSeats / ConstitutionalDefaults::sizeFromPopulation) but its leaf ceiling-9 clamp was retired 2026-07-19 — and it is legislature SIZING, a cousin of, not the same thing as, the tier threshold. The four lead tables split cleanly: jurisdiction_activations is genuinely Phase I's gate; approval_standings (Phase B election approval rollups), journey_progress (K-2 journeys engine), and support_reports (support tickets) merely pattern-match the vocabulary.

### [BUILT] Activation boot-gate (jurisdiction_activations + ActivationService + WF-JUR-01 pipeline)
  - database/schema/pgsql-schema.sql:2694-2706 (jurisdiction_activations: states boundary_loaded -> critical_population -> bootstrapping -> self_governing, critical_population_at, legislature_id)
  - app/Services/ActivationService.php:158-201 (onCriticalPopulation, CLK-06 crossing, idempotent, audited)
  - app/Services/ActivationService.php:213-297 (activate(): legislature sizing -> institution stubs -> bootstrap board -> F-ELB-001 first election -> self_governing)
  - app/Console/Commands/JurisdictionActivateCommand.php:32-37 (signature: jurisdiction:activate {slug} {--force} {--replan})
  - app/Models/JurisdictionActivation.php
  - resources/js/Pages/Jurisdictions/Show.vue:181-193,630-650 (WI-9 activation status line) fed by app/Http/Controllers/JurisdictionController.php:161-211
  NOTES: This IS the Phase-0/A 'activation engine' — and it is the SAME activation concept charter Phase I gates, not a different one. Phase I's deliverable (a) is a curve bolted onto this existing gate, and the gate itself is done and browser-visible.

### [BUILT] CLK-06 threshold resolution through the settings cascade (dev default 1)
  - app/Jobs/Clocks/EvaluateCriticalPopulationJob.php:41-75 (one aggregate over residency_confirmations, per-candidate SettingsResolver::resolveInt('critical_population_threshold') -> config default)
  - app/Jobs/EvaluateClocksJob.php:65 dispatches it; routes/console.php:26 schedules EvaluateClocksJob everyMinute with onOneServer + LeaderProbe write-leader gate (routes/console.php:20-25) — the CI-6 'only authoritative instance' pattern ready-made
  - database/schema/pgsql-schema.sql:1391,1454 (constitutional_settings.critical_population_threshold column, 'NULL = inherit ancestor, then code default')
  - config/cga.php:69-78 ('Dev default 1... Production tiers (player population pegged against real population per jurisdiction, owner ruling #15) land in a later phase')
  - database/seeders/ClockRegistrySeeder.php:103-111 (CLK-06 registered, amendable, semantics 'per_jurisdiction_tier', basis Art. II §1)
  NOTES: The charter's exit criterion — 'resolves per-jurisdiction tiers through the settings cascade (dev still = 1)' — is mechanically satisfiable today for FLAT per-jurisdiction values; the cascade, the dev-1 default, the amendable clock, and the flag-off posture all ship. Only the auto-computed population-pegged value is missing.

### [ABSENT] Activation tier CURVE (activation_tier_enabled/k/exponent/floor/cap params + ActivationTierService::tierThreshold)
  - Grep 'ActivationTier|activation_tier' across repo excluding docs/: only ClockRegistrySeeder.php:107 semantics tag — no service, no settings columns (constitutional_settings DDL at pgsql-schema.sql:1330-1460 has critical_population_threshold only)
  - database/migrations/ (13 post-baseline files, all districting/autoscale/setup-wizard — none add tier params)
  NOTES: The clamp(ceil(k*pop^1/3), floor, cap) formula, the one-amendable-settings-row-at-planet-root design, and tier(0|null)=floor exist nowhere in code. Smallest remaining piece of half (a): the resolver seam (SettingsResolver ancestor walk) and consumer (EvaluateCriticalPopulationJob:62-66) are already in place to receive it.

### [ABSENT] LegitimacyService (reachRatio value object, snapshotAll, k-anon leaderboard)
  - Grep 'LegitimacyService|reachRatio|reach_ratio|leaderboard|k_anon' in app/: zero matches (hits only in docs/, mockups/)
  - resources/js/registry/surfaces.js:102 ({ id: 'reach', label: 'Reach', href: null, contract: 'social/legitimacy.html', phase: 7 } — a registered, deliberately unwired surface stub)
  - mockups/v3/OPEN_QUESTIONS.md:85-91 ('reachRatio() ... is specified but the denominator (WorldPop vs CivicPopulation) and the k-anon floor value are flagged TBD and not implemented')
  NOTES: Design is locked to the pixel — mockups/v2/social/legitimacy.html + mockups/v3/social/legitimacy.html + fixtures-v2.js:676-700 model verifiedResidents/populationEstimate/reachPct/tier/suppressed/snapshots including the suppression and no-denominator edge cases — but zero PHP or Vue implementation exists.

### [ABSENT] legitimacy_snapshots table + SnapshotLegitimacyJob nightly
  - Grep 'legitimacy_snapshots' in database/schema/pgsql-schema.sql: no match (183-table baseline lacks it)
  - Glob database/migrations/*.php: 13 files, none legitimacy-related
  - Grep 'SnapshotLegitimacy' in app/: no matches; routes/console.php scheduled jobs are clocks/autoscale/approval-rollup/dept-report/co-determination only
  NOTES: The table Phase O consumes does not exist in any form. Nothing in the schedule computes or persists a reach ratio.

### [SUBSTRATE] Numerator rail: verified-resident counting per jurisdiction
  - app/Jobs/Clocks/EvaluateCriticalPopulationJob.php:41-57 (SELECT jurisdiction_id, count(*) AS verified_residents FROM residency_confirmations WHERE is_active ... GROUP BY, over the residency_confirmations_jurisdiction_active_idx partial index)
  NOTES: Built for CLK-06 (Phase A/1), but it is literally the reach numerator query the charter's LegitimacyService needs — reusable as-is.

### [SUBSTRATE] Denominator rail: population estimates + provenance on jurisdictions
  - database/schema/pgsql-schema.sql:5640 (jurisdictions COPY column list: population, population_year, population_baseline, population_assigned_via)
  - database/schema/pgsql-schema.sql:1671-1682 (district_subdivisions.population_source CHECK: worldpop_raster|civic|manual_override — the provenance vocabulary already exists)
  NOTES: Built by the geodata/districting lanes (Phases F/H), these are the WorldPop/CivicPopulation rails the charter says Phase I shares; population_provenance for snapshots has a ready pattern in district_subdivisions.population_source.

### [BUILT] Live sizing law max(5, round(pop^(1/3)))
  - app/Services/ActivationService.php:93-98 (cubeRootSeats: max($floor, round(pow($pop, 1/3))), SEAT_FLOOR=5 at line 73)
  - app/Services/ConstitutionalDefaults.php:54-63 (sizeFromPopulation: settings-resolved sizing law + floor clamp; docblock 'No ceiling is applied here — a too-large legislature gets subdivided, not truncated')
  - app/Services/ActivationService.php:618-637 (cycle-2 leaf law 2026-07-19: 'the ceiling clamp is RETIRED' — over-ceiling leaf chambers await line-split, posture-audited)
  - tests/Constitutional/ActivationMathTest.php:27-58 (pins the law: Earth 8e9 -> 2000, floor-of-5, round-half-up)
  NOTES: Found and cited as requested — but classify carefully: this is the LEGISLATURE-SIZING law (how many seats), a cube-root cousin of, not an implementation of, the charter's tierThreshold (how many verified residents to boot). Memory/ledger's 'ceiling 9 leaves-only' clamp no longer exists.

### [SUBSTRATE] approval_standings table
  - database/schema/pgsql-schema.sql:419-429 (race_id, candidacy_id, approvals_count, rank, is_frozen)
  - app/Jobs/ApprovalStandingsRollupJob.php:13-25 (WI-B3 ESM-04 daily approval-phase standings rollup, scheduled routes/console.php:46)
  - app/Services/ApprovalService.php, app/Http/Controllers/Elections/ApprovalController.php
  NOTES: NOT Phase I. Phase B elections machinery: daily public standings during the two-phase open ballot's approval window. Shares only the word 'standing' with anything tier/legitimacy-shaped; no reuse path.

### [SUBSTRATE] journey_progress table (+ achievements)
  - database/schema/pgsql-schema.sql:2579-2587 (journey_progress: user_id, journey_id, steps_done jsonb, completed_at) and 306-317 (achievements: append-only earned ledger with audit_seq)
  - app/Services/JourneyService.php:11-24 ('journeys nudge, they NEVER block — nothing here grants or denies any capability')
  - app/Http/Controllers/Civic/JourneysController.php, resources/js/registry/journeys.js, config/cga/journeys.php
  NOTES: NOT Phase I — this is the mockups-v3-wiring journeys/achievements engine, i.e. Phase K-2 territory (civic education + achievements), already honoring K-2's no-governance-advantage rail. Belongs in the K-2 audit, where it contradicts that phase's UNBUILT rating.

### [SUBSTRATE] support_reports table
  - database/schema/pgsql-schema.sql:4492-4503 (public_id, category, body, reporter_id, status open/...)
  - app/Models/SupportReport.php, app/Http/Controllers/Support/SupportReportController.php
  NOTES: NOT Phase I. A plain support/feedback ticket module ('support' as in helpdesk, not political support). No relation to reach, legitimacy, or tiers.

### [SUBSTRATE] Dual-meter / consent meter (Phase G lead check)
  - app/Services/Identity/MeshRoleGrantService.php:24,118-127, app/Services/Federation/CapabilityService.php:32, app/Http/Controllers/Federation/FederationConsoleController.php:59,396-411
  NOTES: Confirmed unrelated to Phase I: the dual-meter is Phase G's constituent-consent vote mechanism for governed federation channels and upgrades. It measures consent on a proposal, not population reach; no overlap with the reach metric beyond the word 'meter'.

### [BUILT] Forms/clocks delta for Phase I
  - Grep 'F-ACT|activation' in app/Domain/Forms/FormRegistry.php: no matches
  - database/seeders/ClockRegistrySeeder.php:103-111 (CLK-06 pre-existing)
  NOTES: Trivially satisfied by design: the charter specifies ZERO new F-forms/clocks/audit-modules, and indeed none exist and none are needed — CLK-06 already covers the gate.

UNEXPECTED: ClockRegistrySeeder.php:107 already tags CLK-06 with semantics 'per_jurisdiction_tier' and config_default 'cga.critical_population_default' — the tier concept is pre-anchored in the seeded clock registry, waiting only for the curve to compute values into the existing setting_key. | resources/js/registry/surfaces.js:102 registers a 'Reach' surface (href: null, phase: 7, contract social/legitimacy.html) — the app's own navigation registry already reserves Phase I's UI slot against the mockup contract. | A full journeys + achievements engine (JourneyService, journey_progress + achievements tables, Civic/JourneysController, config/cga/journeys.php, registry/journeys.js) shipped with the v3 wiring — substantial Phase K-2 substrate the prior ledger does not attribute anywhere, discovered via the journey_progress lead. | The CI-6 rail ('only the authoritative instance writes a snapshot') has a ready-made implementation pattern: routes/console.php:20-25 onOneServer + LeaderProbe::isPrimary() write-leader gating already guards the clock sweeps a SnapshotLegitimacyJob would join. | config/cga.php:69-78 and EvaluateCriticalPopulationJob.php:29 both cite 'owner ruling #15' verbatim — the code was written with charter Phase I explicitly in view, deferring only the curve.
TESTS: tests/Constitutional/ActivationMathTest.php is the only Phase-I-adjacent suite — a DB-free constitutional pin of cubeRootSeats/seatPlan/quorumRequired (cube-root law, floor-of-5, round-half-up, bicameral trigger, Type B ladder). Grep for critical_population|jurisdiction_activations|EvaluateCriticalPopulation across tests/: zero files — the CLK-06 job, the activation pipeline, and the state machine have NO automated coverage (docblock says the DB-touching pipeline is verified by live-stack jurisdiction:activate runs). Nothing legitimacy/reach-related exists to test.
CORRECTIONS: PHASE_LEDGER_A_TO_O.md line 26 rates Phase I '◻ UNBUILT (design locked)' — too coarse. The (a) activation-gate half is BUILT and live (jurisdiction_activations state machine, CLK-06 every-minute sweep, settings-cascade threshold with dev default 1, full WF-JUR-01 pipeline, jurisdiction:activate command, wired WI-9 UI status line on Jurisdictions/Show.vue). Only the tier CURVE params/service and the entire reach/legitimacy half are missing. Correct rating: PARTIAL. | Ledger line 26's note 'live sizing law today is max(5, round(pop^⅓)), ceiling 9 leaves-only' is stale on the ceiling: the leaf ceiling clamp was RETIRED by the cycle-2 leaf law 2026-07-19 (ActivationService.php:618-637 — over-ceiling leaf chambers keep lawful size and line-split their districts; ConstitutionalDefaults::sizeFromPopulation applies no ceiling anywhere, line 49-53 docblock). Sizing is floor-clamped only, at every node type. | Ledger line 18 lists 'activation engine' under Phase A as if it were unrelated foundation plumbing — it is in fact the direct substrate of Phase I deliverable (a): the same JurisdictionActivation/CLK-06 machinery the charter's tier gates, already resolving thresholds per-jurisdiction through the cascade. The two phases' 'activation' are the same concept, not homonyms. | Ledger line 30 rates K-2 '◻ UNBUILT' — contradicted in passing by this audit: the journeys/achievements engine (journey_progress + achievements tables, JourneyService with the exact K-2 rails: soft-gate, no governance advantage, append-only earned ledger) is already shipped. K-2 deserves re-audit as PARTIAL.


===== [phase-J] Phase J — The Cosmopolitan Coalition as Organization (charter: docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md:232-261) — SUBSTRATE_ONLY =====
The prior ledger's "UNBUILT" call on Phase J is essentially correct for the J-specific deltas — there is no Coalition/Foundation org pair seeded anywhere, no institutions:demo-coalition command, no organizations.public_domain_charter column, no cgc_ip_register.dedication_basis, no org_memberships.is_public, and no civil-society firewall pins — but the ledger under-credits how much of J is already standing under it. The two-org link column (organizations.parent_organization_id) exists in the baseline with FK and model relations exactly as the charter assumes ("no delta for the two-org link"); type='nonprofit' is self-registrable end-to-end via F-IND-012; the whole Phase-D board/co-determination/board-election/IP-register machinery J rides on is built, pinned, and has Vue pages; and the Art. V Cultural-Institution recognition hook the charter cites (F-LEG-028) is already built. Crucially, the CGC IP register's only writer hard-rejects non-CGC dedications and a DB CHECK pins status='public_domain', so the voluntary-dedication path is not merely missing — it is actively (and correctly, pre-J) blocked, confirming the Art. III §5 branch is byte-for-byte intact as the charter demands. Phase J remains a genuinely new but small additive build: one migration (two columns + one nullable enum), one seeder command, a voluntary-dedication branch in CgcIpRegisterService, and firewall pin tests.

### [BUILT] organizations.parent_organization_id (two-org link substrate)
  - database/schema/pgsql-schema.sql:3735 (column, uuid nullable)
  - database/schema/pgsql-schema.sql:13054-13058 (FK organizations_parent_organization_id_foreign, ON DELETE SET NULL)
  - app/Models/Organization.php:84 (fillable), :143-151 (parent()/children() self-relations)
  NOTES: Exists in the baseline exactly as the charter assumes ('existing parent_organization_id', 'no delta for the two-org link'). Dormant, though: no service, controller, Vue page, or test writes/reads it — OrgRegistryService::register() (F-IND-012) does not accept it in the payload (OrgRegistryService.php:84-101), so the parent link can currently only be set by direct model/DB write. Built pre-Phase-D as part of the universal org table; ready for J unchanged.

### [BUILT] type='nonprofit' organization (registrable Article-I creature)
  - app/Models/Organization.php:28 (TYPE_NONPROFIT), :45 (STRUCTURE_NONPROFIT), :71 (nonprofit structure → member-kind membership)
  - app/Services/Organizations/OrgRegistryService.php:53-58 (F-IND-012 self-registration accepts TYPE_NONPROFIT)
  - resources/js/Pages/Organizations/Registry.vue, OrgDetail.vue (org module UI)
  NOTES: A user can register a nonprofit at any jurisdiction (incl. Earth ADM0) today via F-IND-012, with member-based org_memberships. This is Phase-D machinery, not J-specific, but it satisfies the charter's 'both type=nonprofit' prerequisite with zero new build.

### [ABSENT] organizations.public_domain_charter (one-way false→true flag)
  - database/schema/pgsql-schema.sql:3725-3761 (full organizations DDL — no such column; nearest is ip_is_public_domain:3741, the CGC Art. III §5 flag per COMMENT:3796)
  - grep 'public_domain_charter' across repo (excl. docs/) = zero hits
  - database/migrations/ — all 13 post-baseline files are setup-wizard/geodata/districting/autoscale; grep 'organizations' in migrations = zero hits
  NOTES: Genuinely new build. Do not confuse with ip_is_public_domain, which is the CGC hard-constraint flag pinned by Organization::booted() (app/Models/Organization.php:126-136) and CgcIpPublicDomainTest — a different, constitutional-mandate mechanism.

### [ABSENT] cgc_ip_register.dedication_basis (constitutional_mandate|voluntary_charter)
  - database/schema/pgsql-schema.sql:910-926 (full cgc_ip_register DDL — no dedication_basis; CHECK cgc_ip_register_status_public_domain pins status='public_domain' at :925)
  - app/Services/Organizations/CgcIpRegisterService.php:41-46 (dedicate() throws ConstitutionalViolation for any non-CGC org — the ONLY write surface, per docblock :12-19)
  - tests/Constitutional/CgcIpPublicDomainTest.php (389 lines, 6 tests — source-scans that dedicate() is the only writer)
  NOTES: The voluntary-dedication path is not just missing a column — it is actively blocked by the sole writer's is_cgc guard. That is correct pre-J behavior and proves the charter's locked decision ('CGC Art. III §5 branch byte-for-byte unchanged') holds. J's build must add the enum column plus a voluntary branch in CgcIpRegisterService gated on public_domain_charter, without touching the CGC branch.

### [ABSENT] org_memberships.is_public (optional membership visibility)
  - database/schema/pgsql-schema.sql:3631-3648 (full org_memberships DDL — no is_public)
  - The is_public hits at pgsql-schema.sql:1951 and :3219 belong to other tables — endorsements (COPY list :5407) and matrix_rooms (COPY list :5776) — pattern-matches, not this deliverable
  - grep is_public in resources/js for org contexts = zero hits
  NOTES: New build (charter marks it optional).

### [ABSENT] institutions:demo-coalition seeder (two nonprofits at Earth + board + corpus)
  - grep "signature = 'institutions:demo" in app/Console/Commands = only PhaseDDemoCommand.php:107 (institutions:demo-d) and PhaseEDemoCommand.php:103 (institutions:demo-e); plus ElectionsDemoCommand.php:85
  - app/Console/Commands/PhaseDDemoCommand.php:551 — demo-d seeds Organization::TYPE_BUSINESS only; grep 'nonprofit' in app/Console/Commands = zero
  - grep -i 'Coalition|Cosmopolitan|nonprofit' in database/seeders = zero; the only app-code 'Cosmopolitan' hits are branding strings (MatrixSetupCommand.php:144, DeployPackageService.php:83,165-166,268-269)
  NOTES: The exit-criterion command does not exist in any form. No Coalition/Party-Foundation org rows are seeded anywhere.

### [ABSENT] Civil-society firewall (Article-I levers only; zero Leg/Exec/Jud/CGC power) + pin tests
  - grep -i 'firewall' across repo excluding docs/ = zero hits
  - tests/Constitutional/ listing — no coalition/firewall test; FuturePhasePlaceholdersTest.php:124 references only the CGC pin (CgcIpPublicDomainTest)
  NOTES: No explicit firewall pins exist. Structurally, the built org module already gives a nonprofit zero governmental power (orgs are not wired into legislature/executive/judiciary levers), so the firewall is implicit today — but the charter's exit criterion demands explicit green pins, which are new build.

### [ABSENT] Δ4 authorship bridge (authored_by_organization_id / authored_by_user_id / ip_register_entry_id)
  - grep across repo = hits only in docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md:93,251-252 and docs/plans/phase-g-continuation/IK-civic-org-powers-and-record.md:110 — zero in schema/app/tests
  NOTES: Correctly absent per the charter itself — the Δ4 contract is 'owned by K/N, not created here'. J only hands it over; nothing to build in J beyond documenting the contract.

### [SUBSTRATE] Co-determination / board / board-election machinery J rides on
  - app/Services/Organizations/CoDeterminationService.php:44,73,91 (PROTECTED Art. III §6 hardened math, workerSeats min=100/parity=2000, CLK-13/14 arming :245,:258)
  - app/Services/Organizations/OrgBoardService.php:39,105-137 (worker-seat reconciliation, CLASS_WORKER_ELECTED)
  - app/Services/Organizations/OrgBoardElectionService.php:19,31 (reuses VoteCountingService::countStv — never forked)
  - app/Services/Organizations/OrgElectorateService.php:33-69 (one-member-one-vote electorate incl. nonprofit member class)
  - tests/Constitutional/WorkerRepresentationTest.php (569 lines, 8 tests), BoardTransitionTest.php (62 lines)
  - resources/js/Pages/Organizations/{BoardElections,CoDetermination,CgcDetail,OrgDetail,Registry,TransfersConversions}.vue
  NOTES: Built in Phase D for the general org module, not for the Coalition — but it is precisely the 'member-elected co-determined board' the J exit criterion requires, working end-to-end with STV counting and Vue pages. J needs only to instantiate it on the seeded nonprofits.

### [SUBSTRATE] Cultural-Institution recognition hook (charter's Art. V §6 optional grounding)
  - app/Domain/Forms/FormRegistry.php:123 (F-LEG-028 'Cultural Institution Recognition Vote', R-09)
  - app/Domain/Forms/Handlers/CulturalInstitutionRecognitionVote.php:11-12,26 (supermajority chamber vote → powerless cultural institution)
  - app/Models/CulturalInstitution.php:14-25 (honour on the public record, NO powers)
  - app/Models/ChamberVoteProposal.php:92-97 (KIND_CULTURAL_INSTITUTION)
  NOTES: Already built (Phase G adoption-lane work, cited there as Art. V §2). Note: cultural_institutions has no organization_id — recognition is free-standing (name/description), so honoring the Coalition org specifically would be by name, or J adds a link column.

### [SUBSTRATE] foundation_sync_cursors (suspected Coalition artifact)
  - database/schema/pgsql-schema.sql:2233-2249 (peer_id, table_name, from_key/next_from_key keyset cursors, pages_applied/rows_applied)
  - app/Services/Federation/FoundationDrainService.php:12-35 (docblock: JOINER side of the paginated geodata-foundation seed drain, signed keyset pages, resumable cursor)
  - also used by app/Services/Mirror/MirrorService.php, app/Services/Federation/SyncProgressService.php; model app/Models/FoundationSyncCursor.php
  NOTES: NOT Coalition-related at all. 'Foundation' here means the geodata-foundation table set (jurisdictions, constitutional_settings, cosmic_addresses) in the Phase-F/seed-redesign federation drain — zero connection to the Cosmopolitan Party Foundation nonprofit. Classified SUBSTRATE only in the sense 'exists, built for another purpose'; it is not reusable for J and should not be pattern-matched into it.

UNEXPECTED: Cultural-Institution recognition (F-LEG-028) — the charter's Art. V 'optional Cultural-Institution recognition' grounding for J is ALREADY BUILT end-to-end (FormRegistry.php:123, CulturalInstitutionRecognitionVote handler, CulturalInstitution model, ChamberVoteProposal KIND_CULTURAL_INSTITUTION) as Phase-G adoption-lane work; neither the charter's J section nor the prior ledger's J row credits it. | CgcIpRegisterService actively enforces the pre-J state: dedicate() throws ConstitutionalViolation for any non-CGC org (CgcIpRegisterService.php:41-46) and the DB CHECK at pgsql-schema.sql:925 pins status='public_domain'. J's voluntary-dedication branch therefore requires modifying a file on the CLAUDE.md PROTECTED-adjacent constitutional surface (the service is source-scanned by CgcIpPublicDomainTest) — the J build must extend that test's contract, not just add a column. | OrgRegistryService::register() (F-IND-012) does not accept parent_organization_id in its payload (OrgRegistryService.php:84-101) — the charter's 'no delta for the two-org link' holds at the schema layer, but there is no user-facing way to create the parent link; the J seeder (or a form delta) must set it programmatically. | foundation_sync_cursors is federation seed-drain plumbing (FoundationDrainService.php docblock), not a Coalition/'Party Foundation' artifact — worth recording so the name never gets pattern-matched into Phase J again.
TESTS: No Phase-J-specific tests exist (no coalition, firewall, public_domain_charter, or dedication_basis test anywhere in tests/). The substrate J rides on is well covered: tests/Constitutional/CgcIpPublicDomainTest.php (389 lines, 6 tests — append-only register, CHECK pin, dedicate()-only write surface via source scan, CGC flag never flips), tests/Constitutional/WorkerRepresentationTest.php (569 lines, 8 tests — Art. III §6 seat math, CLK-13/14 thresholds), tests/Constitutional/BoardTransitionTest.php (62 lines), plus tests/Concerns/SeatsBoardUser.php helper; FuturePhasePlaceholdersTest.php:124 lists CgcIpPublicDomainTest among protected pins. parent_organization_id has zero test coverage (grep in tests/ = no matches).
CORRECTIONS: PHASE_LEDGER_A_TO_O.md:27 (Phase J '◻ UNBUILT') is directionally correct but overstated as a blanket: the two-org link column it names (organizations.parent_organization_id) already exists in the baseline with FK and model relations (pgsql-schema.sql:3735, :13058; Organization.php:143-151), and type='nonprofit' is already self-registrable end-to-end via F-IND-012 (OrgRegistryService.php:53-58). The genuinely unbuilt items are exactly: public_domain_charter, dedication_basis, org_memberships.is_public, the demo-coalition seeder, the two seeded org rows, and explicit firewall pins. | The ledger's J row omits that the F-LEG-028 Cultural-Institution recognition path the roadmap cites as J's Art. V grounding is already built (FormRegistry.php:123, app/Domain/Forms/Handlers/CulturalInstitutionRecognitionVote.php, app/Models/CulturalInstitution.php). | The ledger's J row lists the 'Δ4 authorship bridge feeding K + N' as if it were a J deliverable; the roadmap itself (line 251-252) assigns creation of those columns to K/N — J only defines the contract. Its absence should not count against J. | No prior doc claims foundation_sync_cursors is Coalition-related, but to preempt the pattern-match: it is the Phase-F/seed-redesign geodata-foundation drain cursor table (FoundationDrainService.php:12-35), unrelated to the Cosmopolitan Party Foundation.


===== [phase-K2] K-2 — Civic Education & Achievements — PARTIAL =====
The prior ledger's "K-2 UNBUILT" is half wrong. The achievements half of K-2 is BUILT and live: a full journeys engine (13 guided learn-by-doing arcs, 10 live), a genuinely append-only achievements ledger enforced by database triggers, a code-registry catalog (config/cga/journeys.php — exactly the charter's "AchievementCatalog is a code registry, not a table" posture), medals sealed to the hash-chained audit log, an Achievements tab on the unified profile, and — beyond the charter — cross-instance federation of medals under an append-any-verified rule with its own constitutional test file. The iron rails hold: soft-gate ("journeys nudge, they NEVER block"), no governance service reads the achievements table, no composite score, no leaderboard. It was built during mockups-v3-wiring Phase 3c and simply never attributed to K-2. The curriculum/education half is ABSENT: no education_tracks/modules/questions/progress tables, no F-EDU forms in FormRegistry, no quiz/correct_key grading, no K2_CURRICULUM.md, and no factions→polymorphic teaching correction; point-of-use education exists only as the per-surface Learn drawer glosses, with "Full lessons" explicitly stubbed as "Planned · Phase 7". The legitimacy surfaces (reach gauge, jurisdiction-only leaderboards) are absent as expected — they ride unbuilt Phase I.

### [BUILT] Achievements ledger (append-only, idempotent award)
  - database/schema/pgsql-schema.sql:306-317 (achievements table: user_id, journey_id varchar(64), title, source_server_id, audit_seq, earned_at)
  - database/schema/pgsql-schema.sql:8217-8220 (partial-unique index achievements_user_journey_unique ON (user_id, journey_id) WHERE deleted_at IS NULL — the charter's idempotent-award mechanism verbatim)
  - database/schema/pgsql-schema.sql:119-126, 10765-10775 (achievements_block_mutation() + achievements_immutable/achievements_no_truncate triggers — UPDATE/DELETE/TRUNCATE raise exceptions at the DB layer)
  - app/Services/JourneyService.php:172-202 (recordAchievement: seals earn into the hash chain via AuditService::append in the same transaction, then insertOrIgnore)
  - app/Models/Achievement.php:21
  - tests/Feature/JourneysTest.php:52,193 (exactly-one-idempotent award; trigger blocks UPDATE)
  NOTES: Matches the charter's 'achievements (append-only, partial-unique = idempotent award)' spec. One deviation: charter says awarded_on is a coarse DATE; as built it is earned_at timestamptz (full timestamp, and it federates).

### [BUILT] AchievementCatalog as a code registry (not a table)
  - config/cga/journeys.php:1-118 (server-side validation source: 13 journeys, id/title/steps/status live|planned)
  - resources/js/registry/journeys.js:1-157 (client rendering mirror: arcs, interaction classes, your-part copy, earn copy)
  - app/Services/JourneyService.php:129-146 (liveJourneyOrFail validates against config; planned journeys reject marking)
  NOTES: Named JourneyService + config/cga/journeys.php rather than AchievementCatalog, but the posture is exactly the charter's: catalog lives in code, journey_id varchar(64) on rows keys into it, no catalog table exists.

### [BUILT] Journeys engine / Learn Area (learn-by-doing surface)
  - routes/web.php:847-859 (GET /journeys, GET /journeys/{id}, POST/DELETE /journeys/{id}/steps)
  - app/Http/Controllers/Civic/JourneysController.php:33-118
  - app/Services/JourneyService.php:46-107 (markStep/unmarkStep; completion freezes steps, 422 on un-mark after completion)
  - database/schema/pgsql-schema.sql:2579-2587 (journey_progress: node-local mutable steps_done jsonb — never federates, matching the charter's education_progress posture)
  - resources/js/Pages/Civic/Journeys.vue (107 lines), resources/js/Pages/Civic/Journey.vue (222 lines)
  - tests/Feature/JourneysTest.php (7 tests, 259 lines)
  NOTES: This is the achievements-engine flavor of a Learn Area: 13 guided civic arcs (election end-to-end, bill becomes law, court case, petition→referendum, etc.), 10 live / 3 planned (budget, mutual-aid, stipend-and-tax gate on unbuilt L phases). Caveat: steps are SELF-marked by the user (checklist), not auto-driven by performing the real acts, and there is no graded content.

### [BUILT] No-governance-advantage / soft-gate rails
  - app/Services/JourneyService.php:21-23 ('journeys nudge, they NEVER block — nothing here grants or denies any capability')
  - resources/js/registry/journeys.js:13-15 (same rule client-side)
  - app/ grep for Achievement: only 6 files — JourneyService, JourneysController, MyRecordController, FederationSyncService, the two models; no role/capability/election service reads the table
  - No leaderboard code anywhere (grep leaderboard across app/ = 0 relevant hits); no composite-score field in schema
  NOTES: The rails hold structurally: medals grant nothing, no per-person composite score, no leaderboard exists. Rail compliance is by absence + docblock convention; there is no dedicated doctrine test pinning 'no governance path reads achievements'.

### [BUILT] Achievements profile surface (badges)
  - app/Http/Controllers/Civic/MyRecordController.php:47-55,61 (achievements tab in the unified profile; JourneyService injected)
  - resources/js/Pages/Civic/MyRecord.vue:601-620 (medal grid, earned dates, links to /journeys/{id})
  - app/Services/JourneyService.php:110-124 (achievementsFor)
  - tests/Feature/JourneysTest.php:167 (test_achievements_appear_on_the_profile)
  NOTES: The charter's 'badges' surface, live on /civic/record.

### [ABSENT] Education tracks/modules/questions/progress (curriculum + server-graded engine)
  - database/schema/pgsql-schema.sql: grep education|curriculum|lesson|quiz|correct_key|track = 0 matches
  - app/Domain/Forms/FormRegistry.php: grep F-EDU = 0 matches (no EDU family exists)
  - docs/plans/education/ does not exist (K2_CURRICULUM.md and K2_ENGINE_PLAN.md unwritten)
  NOTES: The charter's education_tracks/modules/questions/progress schema, F-EDU-001/002 forms, correct_keys-never-serialized grading, and The_Chart-derived curriculum are all untraced. This is the genuinely unbuilt half; DELTA_DELIVERABLES_MASTER.md Lane 15 (lines 196-221) correctly scopes it as future docs-only work.

### [PARTIAL] Point-of-use civic education (Ui/LearnMore.vue)
  - resources/js/Components/ShellV2/LearnFlyout.vue:1-72 (per-surface plain-language gloss + 'the machinery behind this screen': constitutional forms in play + Article citation from SurfaceMeta/config/cga/surfaces.php)
  - resources/js/registry/surfaces.js:175-196 (LEARN_BY_MODULE — 12 module glosses; LEARN_BY_SURFACE — 5 per-surface overrides)
  - resources/js/Components/ShellV2/LearnFlyout.vue:63-66 ('Full lessons — Planned · Phase 7' disabled stub)
  NOTES: The Learn drawer delivers point-of-use constitutional context on every surface (what the screen is, which F-forms drive it, the Article citation) — real point-of-use education chrome. But it is static registry text; the full lesson layer is an explicit in-UI 'Planned' stub.

### [ABSENT] Factions→polymorphic teaching correction (named work item)
  - config/cga/journeys.php + resources/js/registry/: grep faction = 0 matches
  - Only doc traces: docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md:298-300 and DELTA_DELIVERABLES_MASTER.md:207-209 (assigned to Lane 15 Half 1)
  NOTES: No teaching materials exist in-app to correct; the work item is planned, not started.

### [ABSENT] Legitimacy surfaces (jurisdiction reach gauge + jurisdiction-only leaderboards)
  - grep leaderboard|reach gauge|legitimacy across app/ hits only Matrix moderation-flip and mesh-role files (app/Services/Matrix/ModerationFlipService.php etc.) — unrelated vocabulary matches
  - Charter line 268: 'the legitimacy gauge rides Phase I' — Phase I is unbuilt
  NOTES: Correctly absent — the charter itself sequences these behind Phase I's k-anon reach gauge. Nothing pattern-matches them.

### [BUILT] Achievement federation (medals cross instances)
  - app/Services/Federation/FederationSyncService.php:121-131 (locally-originated sealed medals ride the signed audit tail), :346-378 (inbound append-any-verified: insertOrIgnore, no authority gate, source_server_id = shipping peer, audit_seq NULL)
  - database/schema/pgsql-schema.sql:311-312 (source_server_id, audit_seq columns)
  - tests/Constitutional/AchievementFederationTest.php (3 tests: sealed medal rides tail + mirrored never re-exports; inbound peer medal appends with no authority gate; tampered tail lands nothing)
  NOTES: Operator-settled per PHASE_4_DESIGN_peerage §5.2 comments: medals are per-user facts about play, federated append-any-verified. Not demanded by the K-2 charter (which only forbids federating education_progress) — this exceeds it. Note tension with the charter's coarse-DATE privacy rail: full earned_at timestamps ship cross-instance.

UNEXPECTED: Achievements FEDERATE cross-instance under an operator-settled 'append-any-verified' rule (FederationSyncService.php:121-147, 346-378, citing PHASE_4_DESIGN_peerage §5.2) with a dedicated constitutional test file — the K-2 charter never asked for medal federation at all. | The append-only rail is enforced at the DATABASE layer, not just app convention: achievements_block_mutation() triggers reject UPDATE/DELETE/TRUNCATE (pgsql-schema.sql:119-126, 10765-10775). | Point-of-use civic education already exists in the shell: ShellV2/LearnFlyout.vue shows every surface's plain-language purpose, its constitutional F-forms, and its Article citation via SurfaceMeta — with 'Full lessons' as an honest in-UI 'Planned · Phase 7' stub (LearnFlyout.vue:64-65). | Medals are denormalized (title copied onto the achievement row at earn time) and sealed to the hash chain via audit_seq in the same transaction — the public_records posture applied to achievements (JourneyService.php:185-201). | Charter-deviation flag: the charter's privacy rail says awarded_on is a coarse DATE; as built, earned_at is a full timestamptz (pgsql-schema.sql:313) and ships cross-instance in federation tails. | Journey step completion is self-reported (user ticks steps via POST /journeys/{id}/steps), not derived from actually performing the constitutional acts — a fidelity gap if Lane 15 expects verified learn-by-doing.
TESTS: tests/Feature/JourneysTest.php — 7 tests (index renders all 13 journeys; completing every step earns exactly one achievement idempotently; unmark-after-completion rejected + steps frozen; unmark-before-completion works; planned journey rejects marking; achievements appear on the profile; DB append-only trigger blocks UPDATE) on the guarded live-pg connection (LivePgConnection, rolled-back transaction, skips if pg unreachable — run in the app container). tests/Constitutional/AchievementFederationTest.php — 3 constitutional tests (sealed medal rides the signed tail / mirrored medal never re-exports; inbound peer medal appends with no authority gate; tampered medal tail lands nothing). Zero tests exist for education/curriculum (nothing to test — absent). No dedicated doctrine test pins the no-governance-advantage rail, though structural absence of readers holds.
CORRECTIONS: PHASE_LEDGER_A_TO_O.md line 30 marks K-2 '◻ UNBUILT' wholesale — wrong for the achievements half: the journeys engine, append-only achievements ledger (DB-trigger-enforced), code-registry catalog, profile badges tab, soft-gate rails, and even cross-instance medal federation are all live in the baseline schema (pgsql-schema.sql:306, 2579), routed (routes/web.php:852-859), and covered by 10 tests. They shipped under 'mockups-v3-wiring Phase 3c' and were never attributed to K-2. | PHASE_LEDGER_A_TO_O.md line 36 'Score: 7 unbuilt (I, J, K-2, L, M, N, O)' — K-2 should be scored PARTIAL, not unbuilt. | DELTA_DELIVERABLES_MASTER.md line 17 and Lane-15 Half 2 (lines 213-221) say the 'achievements/engine half waits on lane 3's Phase-I draft' and 'code lands post-launch' — the achievements engine code has ALREADY landed (JourneyService, achievements/journey_progress tables, federation path); what actually waits on Phase I is only the legitimacy surfaces (reach gauge, jurisdiction leaderboards) plus the education tracks/questions engine. Lane 15's engine-design deliverable should start from the as-built JourneyService, not greenfield. | PHASE_LEDGER_A_TO_O.md line 30 lists 'consumes I's reach gauge' as part of the K-2 gap — correct, but it omits that journey_progress already implements the charter's never-federates local-progress posture and that achievements already satisfy the append-only + idempotent-award schema spec verbatim.


===== [phase-LM] L+M — Public Finance + Market Economy (one unit) — SUBSTRATE_ONLY =====
The charter's Phase L+M deliverables are genuinely unbuilt — none of the fiscal/market tables (treasury_accounts, ledger_entries, currencies, budgets, levies, economic_accounts, marketplace, work_postings, ubi_*) exist in the 183-table baseline or the 13 post-baseline migrations, no LedgerService exists (every "ledger" in app/ is the Full-Faith-&-Credit audit tail), and the form registry stops at F-LEG-036/F-ORG-007/F-IND-017 with no F-TRE family. But the lane starts much further from zero than "UNBUILT" implies: Phase D shipped a real, constitutionally-guarded fiscal execution stub (appropriations attached to enacted acts + grant apply/award/disburse arithmetic — though award/disburse are dead code with zero callers and zero tests), the entire labor-contract tail the M exit criterion needs (F-IND-014 → labor_recurring org_contract → countersign → queued co-determination recompute) is BUILT and pinned by WorkerRepresentationTest, Treasury departments with R-18 governors are modeled, a proto no-paywall rail ('fee'/'payment_required' in FORBIDDEN_ELIGIBILITY_KEYS) already exists, and — unrecorded by prior docs — the live AppShell nav carries a "Market · planned" section wired to 14 finished economy design mockups per version (marketplace, wallet, stipend/UBI, treasury, units/currencies, joint-ledgers, requests/mutual-aid) in mockups/v2/economy and mockups/v3/economy. The true remaining scope is the ledger/revenue/budget/currency data plane, the F-TRE + F-LEG-037..040 + F-IND-018..023 form surface, the marketplace/labor-board/mutual-aid/UBI front ends, and wiring budgets to the already-existing appropriations rows — not the co-determination trigger, not the contract lifecycle, not the design layer.

### [ABSENT] Public ledger (treasury_accounts, ledger_entries, LedgerService, double-entry hash-chain)
  - grep treasury_accounts|ledger_entries in E:\fair-constitution-app\database\schema\pgsql-schema.sql — no matches
  - grep LedgerService across app/ — no matches; grep debit|credit|double-entry in app/ hits only Full-Faith-&-Credit federation files (app\Services\Federation\FederationSyncService.php:16 etc.)
  - all 13 post-baseline migrations are districting/autoscale (database\migrations\2026_07_05_000001_setup_wizard_v2.php … 2026_07_22_000003_add_redraw_requested_at_to_autoscale_items.php)
  NOTES: No financial ledger of any kind. The hash-chain infrastructure it would reuse (audit_log + audit_log_block_mutation trigger, AuditService) exists from Phase 0 and is the charter's stated implementation vehicle.

### [ABSENT] Revenue/taxation plane (revenue_streams, levies, tax_filings, borrowings)
  - grep revenue_streams|tax_filings|borrowings|levies and tax|levy|borrow in pgsql-schema.sql — no matches
  - no F-LEG-037 (Revenue) / F-LEG-039 (Borrowing) in app\Domain\Forms\FormRegistry.php — F-LEG family ends at F-LEG-036 (line 131)
  NOTES: Zero trace. NO_FEE_FORMS pin also absent, but ConstitutionalValidator::FORBIDDEN_ELIGIBILITY_KEYS already blocks 'fee' and 'payment_required' riders on rights-automatic forms (app\Services\ConstitutionalValidator.php:140-154) — an existing partial Art. II §8 rail the L pin would generalize.

### [ABSENT] Budgets (budgets, budget_lines, F-LEG-038 budget-spawns-appropriations)
  - grep budget in pgsql-schema.sql — no matches
  - 'budget' in app/ is exclusively districting seat budgets (app\Services\DistrictingService.php computeSeatBudget) plus GrantService.php:23 comment 'Budget UX is post-F backlog'
  - GrantService::createAppropriation (app\Services\Executive\GrantService.php:34) has zero call sites in app/, routes/, database/, tests/
  NOTES: The charter's design (budget enactment creates existing appropriations rows in the same txn) has its landing pad built — the appropriations table — but no budget object, no F-LEG-038, and today NOTHING can create an appropriation row at runtime: createAppropriation is uncalled dead code.

### [ABSENT] Currency plane (currencies, issuance_events, Art. V §5 root-reserved; monetary settings keys)
  - grep currencies|issuance_events|currency in pgsql-schema.sql — no matches
  - SETTING_BOUNDS (app\Services\ConstitutionalValidator.php:169-211) contains no monetary/UBI keys; DUAL_DOOR_KEYS = ['judiciary_is_elected'] only (line 132)
  - F-LEG-040 absent from FormRegistry
  NOTES: Nothing exists. The dual-door lever mechanism the charter reuses IS built and tested: F-LEG-031 AmendableSettingChange handler (FormRegistry.php:239), DUAL_DOOR_KEYS enforcement in BillService.php:360-367, EnactmentService.php:378-423, SettingAmendmentDoorService — adding monetary keys is config+bounds work on live machinery.

### [SUBSTRATE] Appropriations + grants stub (appropriations, grant_applications, grant_disbursements, GrantService)
  - pgsql-schema.sql:398-412 (appropriations, remaining<=amount CHECK), 2335-2349 (grant_applications), 2356-2364 (grant_disbursements)
  - app\Services\Executive\GrantService.php:18-24 (header: 'D-6 — appropriations + grants, minimal viable… Budget UX is post-F backlog'), :93-165 award FOR-UPDATE ≤ remaining, :186-254 disburse Σ ≤ award append-only, audit-chained + PublicRecordService-published
  - only apply() is wired: routes\web.php:677-678 executive.appropriations.applications.store; award/decline/disburse/createAppropriation have no callers (repo-wide grep)
  - UI: resources\js\Pages\Executive\Actions.vue:426-520 (appropriation lines table, applications table, apply form; administerGrants flag at ExecutiveActionController.php:105 but no award/disburse controls)
  - zero tests: grep GrantApplication|disburse|Executive\\GrantService in tests/ — no matches
  NOTES: Built by Phase D (D-6) as the deliberate fiscal stub the L charter names as the budget execution substrate. Coverage of L: the execution tail's arithmetic and display only — creation path unreachable, award/disburse unreachable, untested. Roughly the last 10-15% of the budget→appropriation→disbursement cycle, wired halfway.

### [PARTIAL] Labor board (work_postings, work_applications, accept→F-IND-014 flow)
  - work_postings|work_applications absent from pgsql-schema.sql (grep no match)
  - the ENTIRE downstream chain is BUILT: F-IND-014 handler app\Domain\Forms\Handlers\WorkerRegistration.php:49-76 → OrgMembershipService::registerWorker (app\Services\Organizations\OrgMembershipService.php:158) creates draft labor_recurring org_contract + org_workers 'applied'; F-ORG-001 countersign_contract (:209) activates → R-25 (RoleService.php:538) + queued headcount recompute
  - org_contracts cosign CHECK constraint pgsql-schema.sql:3558-3561; route POST /contracts/{contract}/cosign (app\Http\Controllers\Organizations\OrganizationController.php:40,272)
  - tests\Constitutional\WorkerRepresentationTest.php:267-317 pins the F-IND-014→F-ORG-001 path end-to-end
  NOTES: PARTIAL rather than ABSENT because the charter's M spec is 'accept → F-IND-014 → org_contracts(labor_recurring)' and everything from F-IND-014 onward — including the co-determination auto-trigger that IS the M exit criterion — already works and is constitutionally pinned. Missing: the posting/application/discovery board itself (two tables + UI + F-IND-019-ish form).

### [BUILT] Co-determination trigger (M exit criterion: 'labor-board hire auto-triggers co-determination')
  - app\Services\Organizations\CoDeterminationService.php:15-43 (PROTECTED header; recompute() behind every headcount event via queued RecomputeWorkerHeadcountJob + nightly EvaluateCoDeterminationJob; CLK-13/14)
  - org_workers pgsql-schema.sql:3704-3718 (polymorphic employer organizations|departments)
  - tests\Constitutional\WorkerRepresentationTest.php — 8 tests pin the pure math and the form path
  - app\Http\Controllers\Organizations\CoDeterminationController.php
  NOTES: Built by Phase D for Art. III §6, not for M — but it is literally the mechanism M's exit criterion invokes; a labor-board hire that lands an F-IND-014 already triggers it with no new code.

### [ABSENT] Marketplace (marketplace_listings, marketplace_orders, market_transactions, economic_accounts)
  - grep marketplace|market_transactions|economic_accounts in pgsql-schema.sql and app/ — no matches (the sole app/ 'ubi' grep hit is the substring in $subIds, LegislatureController.php:5025)
  - F-IND-020..023, F-ORG-008 absent — F-ORG family ends at F-ORG-007 (FormRegistry.php:84), F-IND at F-IND-017 (:70)
  - FORBIDDEN_SUBJECT_TYPES (app\Services\PublicRecordService.php:34-45) lacks market_transaction/ubi_receipt/economic_account — the mechanism M extends exists, keys not added
  NOTES: No code. Design layer exists: mockups/v3/economy/marketplace.html, listing-detail.html, exchange.html, wallet.html, units.html (currencies), joint-ledgers.html, plus the live nav placeholder (see unexpected).

### [ABSENT] Mutual aid (assistance_requests)
  - grep assistance in pgsql-schema.sql and app/ — no matches
  - design mockups only: mockups\v3\economy\requests.html, request-detail.html
  NOTES: No trace in code or schema.

### [ABSENT] UBI (ubi_disbursements, ubi_receipts, F-TRE-004 system-only run)
  - grep ubi in pgsql-schema.sql — no matches; no F-TRE-* anywhere in FormRegistry.php
  - eligibility substrate BUILT elsewhere: residency_confirmations + derived roles (Phase 1) provide the 'active residency association ONLY' gate; G-ID identity assurance shipped with Phase G
  - design mockup: mockups\v3\economy\stipend.html; nav placeholder 'The civic stipend' resources\js\registry\surfaces.js:112
  NOTES: Zero UBI code. The absolute-rights residency gate and the sybil-defense identity layer the charter leans on are both already built (Phases 1 and G).

### [BUILT] Treasury departments + R-18 (charter's 'already modeled' claim)
  - app\Models\Department.php:29 KIND_TREASURY
  - R-18 governor role app\Services\RoleService.php:56,265-269; F-BOG-001/002 forms (FormRegistry.php:158-159) with handlers DepartmentRuleImplementation/DepartmentReportFiling
  - app\Console\Commands\PhaseDDemoCommand.php:346-350 seeds a demo Treasury department with BoG
  NOTES: Phase D built this. The charter's F-TRE-001..003 forms are absent, but their actor (R-18/BoG), their institution (treasury-kind department), and their reporting channel (F-BOG-002, Art. III §4) all exist.

### [SUBSTRATE] org_ownership_stakes + org_transfers + org_conversions (capital/ownership economy)
  - pgsql-schema.sql:3655-3671 (stakes, open/close history), 3678-3697 (transfers, mutual-consent CHECK), 3569-3591 (conversions, fair_market_floor ≤ compensation CHECK)
  - app\Services\Organizations\OrgOwnershipService.php:11-15 (D-O4 cap-table writer), OrgTransferService.php:14-37 (D-O6, F-ORG-005, CGC-never-transfers Art. III §5 gate), OrgConversionService call sites :281,:493
  - UI resources\js\Pages\Organizations\TransfersConversions.vue; pinned by tests\Constitutional\CgcIpPublicDomainTest.php:309-310
  NOTES: Phase D governance machinery (Art. I freedom-to-contract for org ownership, Art. III §5 CGC economics). It pattern-matches 'market' vocabulary but is the ownership/conversion plane, not the M marketplace; reusable as M's freedom-to-contract precedent and as the counterparty model for commercial org_contracts (kind='commercial' exists in the enum, pgsql-schema.sql:3560, with no creation path in any UI).

### [SUBSTRATE] broker_authorizations
  - pgsql-schema.sql:740-752 (domain, broker_server_id, authority_pubkey, signature)
  - routes\federation.php:100-104 (/cert-grant, gossiped broker_authorizations), app\Services\Federation\CertGrantService.php, instance_capabilities broker.dns/broker.tls (pgsql-schema.sql:2384)
  NOTES: NOT economy at all — Phase G federation DNS/TLS certificate brokering. 'Broker' here is mesh infrastructure. Pure vocabulary collision; irrelevant to L/M and should not appear in economy inventories.

### [ABSENT] age_of_majority / age_of_consent settings
  - repo-wide grep (code, schema, migrations) — zero matches in code; sole mention is docs\plans\docs-recon\DELTA_DELIVERABLES_MASTER.md:44 (D-09, 'future L/M lane', logged)
  NOTES: Prior finding of absence is CONFIRMED correct. Not in SETTING_BOUNDS, not in constitutional_settings DDL.

UNEXPECTED: The live AppShell nav already declares a 'Market · planned' section — resources/js/registry/surfaces.js:109-114: marketplace ('The open market'), wallet ('My wallet'), stipend ('The civic stipend' = UBI), treasury ('Public finance'), all href:null phase:8, each mapped to an economy/*.html mockup contract; plus surfaces.js:31 economy-home and registry/journeys.js:79 'Market (opt.)' rail stop in the org journey. | A complete two-generation economy design layer exists in-repo: mockups/v2/economy/ and mockups/v3/economy/ each hold 14 finished pages — economy-home, marketplace, listing-detail, wallet, stipend (UBI), treasury, exchange, joint-ledgers, units (currencies), agreements, agreement-detail, requests (mutual aid), request-detail, org-settings. The L+M UI is designed, not just chartered. | A partial Art. II §8 no-paywall rail already exists: ConstitutionalValidator::FORBIDDEN_ELIGIBILITY_KEYS includes 'fee' and 'payment_required' (app/Services/ConstitutionalValidator.php:143-144), rejecting fee riders on rights-automatic forms — the charter's NO_FEE_FORMS pin would generalize an existing mechanism, not invent one. | GrantService::award/decline/disburse/createAppropriation are dead code — full arithmetic guards implemented (FOR UPDATE, ConstitutionalViolation citations) but no route, form handler, artisan command, seeder, or test invokes them; only apply() is reachable (routes/web.php:677). At runtime no appropriation row can currently come into existence. | org_contracts already supports kind='commercial' (pgsql-schema.sql:3560) with the two-signature cosign constraint — the M marketplace's contract primitive exists in the schema with no creation UI. | The dual-door amendment machinery the L charter reuses for monetary levers is fully live and multi-service (BillService.php:360-367, EnactmentService.php:378-423, SettingAmendmentDoorService.php) — adding monetary keys to SETTING_BOUNDS/DUAL_DOOR_KEYS is configuration on tested rails.
TESTS: Zero tests exist for any charter L/M deliverable (nothing to test). The substrate's coverage: tests/Constitutional/WorkerRepresentationTest.php (8 tests — PROTECTED Art. III §6 math + the full F-IND-014→F-ORG-001 countersign→recompute path, lines 267-317); tests/Constitutional/CgcIpPublicDomainTest.php (pins OrgConversionService/OrgTransferService gates, lines 309-310); tests/Feature/PhaseDPageSmokeTest.php (renders /executives/{id}/actions including the Grants & appropriations card, line 82 — smoke only). GrantService itself has ZERO direct tests, and its award/decline/disburse/createAppropriation methods have zero callers anywhere in the repo — the grant arithmetic (award ≤ remaining, Σ disbursements ≤ award) is entirely unexercised.
CORRECTIONS: PHASE_LEDGER_A_TO_O.md line 88-93 lists 'Apply for Grants' among App_Flows concepts that were 'never built' — wrong: org grant applications ARE built and route-wired (GrantService::apply, routes/web.php:677-678, apply form in resources/js/Pages/Executive/Actions.vue:491-520). What's missing is individual-applicant grants and the award/disburse UX, not the application flow. | Ledger row L's 'budgets → existing appropriations' correctly notes the appropriations substrate but overstates its liveness: the Phase-D stub is only half-wired (display + apply), appropriation CREATION is unreachable from any route/command/seeder, award/disburse are uncalled, and GrantService has zero test coverage — the 'existing appropriations' are an empty, unpopulatable table today. | Ledger rows L and M say flatly '◻ UNBUILT' — correct for the charter's own tables/forms/services, but materially incomplete: (a) the M exit criterion's co-determination auto-trigger chain (F-IND-014 → labor_recurring contract → countersign → queued CLK-13/14 recompute) is BUILT and constitutionally pinned; (b) a 14-page economy mockup set exists in BOTH mockups/v2 and mockups/v3 plus a live 'Market · planned' nav section — the ledger's mockups row doesn't connect this to L/M; (c) an Art. II §8 fee-rider block already exists in the PROTECTED validator. The lane-13 design should start from these, not from zero. | Prior finding that age_of_majority/age_of_consent are absent: CONFIRMED (only mention is DELTA_DELIVERABLES_MASTER.md:44, D-09 logged for the L/M lane). | Any inventory that groups broker_authorizations with economy tables is wrong — it is Phase G federation DNS/TLS cert brokering (routes/federation.php:100-104), unrelated to finance.


===== [phase-N] Phase N — Full i18n, Accessibility & Media (charter lines 390-427) — SUBSTRATE_ONLY =====
Phase N itself is unbuilt, but it sits on much more finished substrate than the prior ledger records. The Phase-F machinery is real and live: a vue-i18n 11 instance registered in app.js with 5 chrome locales (en 111 keys; es/ar/hi/zh-Hans 103 keys each), a deterministic en-XA pseudo-locale that preserves ID tokens, a 36-term pinned glossary, a working locale switcher persisted to users.locale, and genuine RTL plumbing (document.dir + icon flipping). Separately, Phase K-3 already shipped the exact seam Phase N's machine-translation router plugs into — TranslationProvider interface + TranslationGate privacy rail + offline stub + a live POST /matrix/translate endpoint, constitutionally pin-tested — with code comments explicitly reserving 'the full NLLB-local-tail + Haiku-tier-1 hybrid ROUTER is Phase N'. What does NOT exist: any string-extraction/CI gate (no scripts/i18n/, no .github/ at all, zero per-namespace catalog files despite the loader glob being ready), the 115-locale/77-language registry (no config/locales.php; scripts/etl/languages.py is a different artifact — a country→official-language ETL map), NLLB/Haiku anywhere, translation_cache/translation_string_status tables, F-SYS-LOC-PUBLISH/F-SYS-TR-REVIEW forms, axe-core or any WCAG 2.2 certification pass, and the video→translated-video pipeline/MultiTrackPlayer.vue. Body copy across 87 pages + 90 components is hardcoded English — only the 6 shell files use $t(). Accessibility baseline is stronger than 'unbuilt' implies (412 aria- attributes across 91 of 177 Vue files, a WCAG 4.1.3 live region composable), but it is craftsmanship from earlier phases, not the Phase N certification.

### [BUILT] i18n machinery (Phase F claim): vue-i18n instance, loader, pseudo-locale
  - resources/js/i18n/index.js:16-99 (createI18n, NS_MODULES glob line 28, pseudo() lines 60-79, postTranslation hook lines 93-96)
  - resources/js/app.js:24-30 (initialLocale + app.use(i18n))
  - package.json dependency vue-i18n ^11.4.5
  - app/Http/Middleware/HandleInertiaRequests.php:60,79 ('locale' => $user->locale; 'locale' => app()->getLocale())
  NOTES: Built for Phase F, works end-to-end for chrome copy. vue-i18n ^11.4.5 (package.json), legacy:false instance with per-namespace merge loader (import.meta.glob('./locales/*/*.json')) and en-XA pseudo-locale (accent + 35% pad + bracket markers, ID tokens R-/WF-/F-/I-/CLK- preserved). Registered in app.js line 30 (.use(i18n)) with initial locale from the shared Inertia 'locale' prop. This is the F machinery the ledger credits — confirmed live, not vapor.

### [PARTIAL] Locale content: 5 chrome locales vs charter's 115 registered locales / 77+ languages
  - resources/js/i18n/{en,es,ar,hi,zh-Hans}.json (key counts 111/103/103/103/103 via JSON walk)
  - bash check: resources/js/i18n/locales -> NO_LOCALES_DIR; config/locales.php -> NO_CONFIG_LOCALES
  - scripts/etl/languages.py:1-16 (docstring: 'Static ISO3 country code → official language(s) mapping ... jurisdictions.official_languages')
  - database/schema/pgsql-schema.sql:2750 (official_languages json DEFAULT '["en"]')
  NOTES: Exactly 5 product locales exist (en/es/ar/zh-Hans/hi) covering chrome only: en.json 111 keys, the other four 103 keys each (~103-111 strings per locale, not page bodies). resources/js/i18n/locales/ (the per-namespace catalog dir the loader expects) does NOT exist — zero namespace files. No config/locales.php, no 115-locale registry. The charter's 'single languages.py registry generating config/locales.php + JS registry' is ABSENT — do not confuse it with scripts/etl/languages.py, which is a pre-existing ISO3-country→official-language map feeding jurisdictions.official_languages (schema line 2750), a different artifact serving geodata ETL.

### [ABSENT] Body-copy extraction + CI gate (scripts/i18n/extract.mjs + check.mjs)
  - bash: ls scripts/i18n -> 'no scripts/i18n'; ls .github -> NO_GITHUB_DIR
  - grep -rln 'useI18n|$t(' resources/js --include=*.vue -> exactly 6 files, all in Components/Shell/ + Layouts/
  - find resources/js/Pages -name '*.vue' -> 87; Components -> 90
  NOTES: No scripts/i18n/ directory (scripts/ holds only etl/, ops/, setup/). No .github/ directory at all, so no CI of any kind exists to host the gate. Hardcoded-English scale confirmed: only 6 of 177 Vue files use useI18n/$t() — all shell chrome (AppShell, AppShellV2, AppHeader, AppFooter, AppSidebar, JurisdictionSwitcher); 87 Pages + 90 Components ship literal English bodies, matching the charter's ~90% claim (repo has since grown past the charter's 64 pages/48 components).

### [SUBSTRATE] Machine-translation router (local NLLB tail + Claude-Haiku tier-1, TranslationProvider router)
  - app/Services/Matrix/Translation/TranslationProvider.php:9 ('The full NLLB-local-tail + Haiku-tier-1 hybrid ROUTER is Phase N; K3-K ships only the seam + the rail + an offline stub')
  - app/Services/Matrix/Translation/TranslationGate.php:25-52 (isPrivate fail-closed, rail refusal lines 39-45)
  - app/Services/Matrix/Translation/LocalStubTranslationProvider.php:14-18 ('[targetLanguage] '.text stub; 'Phase N replaces this with the real local NLLB tail')
  - routes/web.php:819 (Route::post('/matrix/translate', MatrixTranslationController::class))
  - app/Providers/AppServiceProvider.php:25 (bind TranslationProvider -> LocalStubTranslationProvider)
  - grep NLLB|Haiku across app/, scripts/ -> only docs/plans + mockups fixtures
  NOTES: The seam is BUILT (by Phase K-3, K3-K), the router is ABSENT. TranslationProvider interface + TranslationGate privacy rail (private/E2EE/tombstoned room + cloud provider = refused BEFORE the provider sees text, fail-closed) + LocalStubTranslationProvider (isCloud=false, marked passthrough) + live endpoint POST /matrix/translate + container binding. Code comments in both files explicitly defer the hybrid router to Phase N. Zero NLLB/Haiku code anywhere in app/ or scripts/ (all repo NLLB/Haiku hits are docs and mockup fixtures). No frontend caller of /matrix/translate exists yet. This is exactly the plug-point Phase N's router drops into without touching the rail.

### [ABSENT] translation_cache + translation_string_status tables (deferred dynamic layer)
  - grep 'translation_cache|translation_string_status' database/schema/pgsql-schema.sql -> no matches
  - ls database/migrations -> 13 files, 2026_07_05..2026_07_22, all districting/autoscale names
  NOTES: Neither table exists in the 183-table baseline or in any of the 13 post-baseline migrations (all 13 are districting/autoscale/geodata: setup_wizard_v2, geodata_repair_plane, autoseed_template, autoscale_*, worker leases, antimeridian guard). Consistent with the charter, which defers these tables — but nothing pre-built them either. The privacy rail the charter wants as a CHECK constraint exists today only as PHP logic in TranslationGate.

### [SUBSTRATE] WF-SYS-03 public_records.translations backfill
  - database/schema/pgsql-schema.sql:4028 (public_records.translations jsonb DEFAULT '{}' NOT NULL)
  - app/Models/PublicRecord.php:54,61 (fillable + array cast)
  - app/Services/PublicRecordService.php:120 ('translations' => $attrs['translations'] ?? [])
  - app/Services/Federation/FederationSyncService.php:117,514
  - app/Http/Controllers/System/PublicRecordsController.php:166-199 (locale/quality summarization)
  - resources/js/Pages/System/PublicRecords.vue:207-211 (badge + 'Planned · Phase F' placeholder)
  NOTES: The jsonb column exists in the baseline, the model casts it, PublicRecordService accepts it on write (defaults to []), FederationSyncService round-trips it across the mesh, and PublicRecords.vue renders a per-record 'X/Y languages' badge from it — whose fallback tooltip literally reads 'machine translation pipeline · Planned · Phase F'. Nothing anywhere writes an actual translation into the column; every writer passes []. Read path built, write path (the Phase N backfill) absent.

### [SUBSTRATE] WCAG 2.2 AA + EN 301 549 certification (axe-core in CI)
  - package.json (no axe, no test runner; scripts: build/dev only)
  - grep -ro 'aria-*' resources/js --include=*.vue -> 412 occurrences in 91 files (of 177 .vue)
  - resources/js/composables/useAnnounce.js:1-20 (WCAG 4.1.3 live region #cga-live)
  - resources/js/Layouts/AppShell.vue:253 (persistent polite live region comment); :221-224 (Escape/focus management)
  - resources/js/Components/Ui/DataTable.vue:4 (WCAG 1.4.10 reflow fix)
  NOTES: No axe-core (absent from package.json), no JS test runner at all (no vitest/jest; scripts = build/dev only), no CI (.github absent) — so the certification deliverable is untouched. But the accessibility BASELINE is substantial and organic to earlier phases: 412 aria- attributes across 91 of 177 Vue files, a shell-owned polite live region + useAnnounce() composable citing WCAG 4.1.3, DataTable citing WCAG 1.4.10 reflow, Escape-close + focus-restore popover handling in AppShell, 95 files using tabindex/focus patterns, sr-only/role usage in 80 files. Good raw material; zero systematic audit.

### [PARTIAL] RTL support
  - resources/js/i18n/index.js:45 (ar, dir: 'rtl')
  - resources/js/Layouts/AppShell.vue:177-186 (applyDir), 201-211 (dev-bar RTL flip)
  - resources/js/Components/Ui/Icon.vue:12,114 (.icon--directional flipped under [dir="rtl"])
  NOTES: Real but chrome-scoped: Arabic registered dir:'rtl' in LOCALES; locale change sets document.documentElement.lang + dir; the dev bar has an RTL force-flip toggle; Icon.vue flips directional glyphs under [dir="rtl"]. Because body copy is untranslated, RTL is only ever exercised on the ~103-key chrome. No RTL-specific layout audit beyond the icon system.

### [BUILT] Locale switcher UI + per-user persistence
  - resources/js/Layouts/AppShell.vue:299 and AppShellV2.vue:223 (locale <select>)
  - database/schema/pgsql-schema.sql:4686 (users.locale varchar(12) DEFAULT 'en')
  - app/Http/Controllers/Civic/MyRecordController.php:44,161-193 (LOCALES const, validation, persist)
  - app/Http/Controllers/Auth/RegisteredUserController.php:36 (LANGUAGES = en/es/ar/zh-Hans/hi)
  - resources/js/Pages/Civic/MyRecord.vue:741 + Auth/Register.vue:190 ('Records are translated per your selection.')
  NOTES: Built (Phase F/G era), works end-to-end for the 5 locales: <select> over LOCALES in both shells; users.locale column in baseline; MyRecordController validates against LOCALES const and persists; HandleInertiaRequests shares it back; app.js applies it on boot. Users also pick a multi-select 'languages' preference at registration (same 5 codes) with UI copy promising 'Records are translated per your selection' — a promise the absent pipeline cannot yet keep.

### [PARTIAL] Glossary (38 constitutional terms pinned per locale)
  - resources/js/i18n/glossary/term-base.json (36 terms + _comment; e.g. Jurisdiction -> Jurisdicción/ولاية قضائية/辖区/अधिकार-क्षेत्र)
  - grep -rln 'glossary|term-base' tests app -> no matches
  NOTES: resources/js/i18n/glossary/term-base.json holds 36 pinned terms (charter says 38), each with canonical es/ar/zh-Hans/hi translations and a _comment pinning the never-translate rule for ID tokens and Art./§ citations. Phase F artifact; the Phase N task of seeding these terms into each NEW locale before MT obviously awaits the new locales. No test pins the glossary file (grep for glossary/term-base in tests/ finds nothing).

### [ABSENT] Media: video→translated-video pipeline + MultiTrackPlayer.vue
  - grep MultiTrackPlayer across repo -> only docs/plans and mockups
  - grep 'video|audio|.vtt|<track' resources/js --include=*.vue -> only Civic/Room voice components (K-3)
  - package.json (livekit-client ^2.20.0; no player libs)
  NOTES: No MultiTrackPlayer.vue, no <track>/VTT handling, no video pipeline anywhere in the repo. The only media subsystem is LiveKit VOICE from Phase K-3 (useVoiceRoom.js, ChamberStage.vue, ParticipantTile.vue, livekit-client ^2.20.0) — out of Phase N scope. The HeyGen-era external toolchain the charter aims to replace lives outside this repo entirely.

### [ABSENT] F-SYS-LOC-PUBLISH + F-SYS-TR-REVIEW forms
  - grep 'F-SYS|SYS-LOC|SYS-TR' app/Domain/Forms/FormRegistry.php -> no matches
  NOTES: No F-SYS-* form family exists in FormRegistry (109 canonical forms, families IND/CAN/ORG/ELB/LEG/SPK/CHR/EXE/BOG/JDG/ADV/SOC). Charter-only identifiers.

UNEXPECTED: K3-K translation seam trio (app/Services/Matrix/Translation/{TranslationProvider,TranslationGate,LocalStubTranslationProvider}.php) with code comments explicitly reserving the NLLB+Haiku hybrid router for Phase N — a deliberate, tested Phase-N plug point no prior phase-N accounting mentions. | No frontend caller of POST /matrix/translate exists yet — the K3-K endpoint is server-complete but UI-orphaned; Phase N (or K-3 polish) owes it a Translate button. | users.languages multi-select preference (RegisteredUserController::LANGUAGES) plus shipping UI copy 'Records are translated per your selection.' (Register.vue:190, MyRecord.vue:741) — the product already promises per-user record translation that no pipeline delivers. | The en.json chrome dict has 111 keys vs 103 in all four translations — 8 chrome strings are already drifting untranslated even inside the 5-locale chrome scope, and no CI gate exists to catch it. | The repo has NO .github directory whatsoever — the Phase N 'CI gate' deliverable implies standing up CI itself, not just adding a job. | scripts/etl/languages.py + jurisdictions.official_languages (schema:2750, default '["en"]') give every jurisdiction row an official-language list — a data substrate the 115-locale rollout could key on, built for geodata purposes.
TESTS: Exactly one translation-related test file exists: tests/Constitutional/TranslationPrivacyRailTest.php (4 test methods) — a constitutional pin on the K3-K TranslationGate: cloud provider refused on private rooms without ever seeing the text, allowed on public rooms, local provider admissible everywhere, fail-closed on unknown/tombstoned rooms. There is NO JavaScript test runner at all (package.json scripts are only build/dev; no vitest/jest/axe-core), so the vue-i18n instance, pseudo-locale, glossary, and locale switcher have zero automated coverage, and no accessibility test exists anywhere. No CI pipeline exists to host any gate (.github/ absent from the repo).
CORRECTIONS: PHASE_LEDGER_A_TO_O.md line 33 marks N '◻ UNBUILT (machinery live from F...)' — directionally right, but it omits that Phase K-3 already built and pin-tested the Phase-N router's exact insertion seam: TranslationProvider interface + TranslationGate privacy rail + LocalStubTranslationProvider + live POST /matrix/translate endpoint (routes/web.php:819) + container binding (AppServiceProvider.php:25) + TranslationPrivacyRailTest (4 tests). Phase N's MT router is a drop-in provider swap, not a greenfield build. | Ledger line 23 credits Phase F with '5-locale chrome' — verified accurate (en 111 keys, es/ar/hi/zh-Hans 103 each, pseudo-locale en-XA, 36-term glossary) — but the per-namespace catalog layer is loader-only: index.js globs ./locales/*/*.json and that directory does not exist. Any reading of the F row as 'catalog system live' would overstate it; only the monolithic chrome dicts exist. | The charter's planned 'single languages.py registry generating config/locales.php + JS registry' must not be checked off against the existing scripts/etl/languages.py — that file is an ISO3-country→official-language map feeding jurisdictions.official_languages (schema:2750) for geodata ETL, unrelated to locale registration. Same filename, different artifact. | Unrecorded substrate for WF-SYS-03: public_records.translations jsonb is not just a dormant column — the read path is fully wired (model cast, service write-through defaulting to [], federation sync at FederationSyncService.php:117/514, and a per-record 'X/Y languages' UI badge at PublicRecords.vue:207-211 whose placeholder tooltip still says 'Planned · Phase F'). Only the write/backfill side is missing. | The a11y picture is better than any prior doc records: 412 aria- attributes across 91 of 177 Vue files, a WCAG 4.1.3 live-region composable (useAnnounce.js), WCAG-cited fixes in DataTable and the shells, and RTL icon flipping. Phase N's certification job is an audit-and-close-gaps pass over a real baseline, not a from-zero accessibility build. | Glossary term count is 36, not the charter's 38 (resources/js/i18n/glossary/term-base.json) — two terms short or the charter number is off; worth a one-line reconciliation before Phase N seeds new locales.


===== [phase-O] O — The Full-Scale Demo (capstone) — SUBSTRATE_ONLY =====
Phase O's named machinery is genuinely unbuilt — there is no instance_class column, no demo_sessions/demo_overlays/demo_generation_runs table (the string "demo" appears nowhere in the 183-table baseline DDL), and no DemoPopulateService or DemoSandboxService anywhere in app/. But the prior ledger's flat "UNBUILT" undersells real, working substrate: the CI-2 "scale_demo forces federation off" rail is ALREADY coded and constitutionally pinned for the Matrix plane (shipped with K-3), the reserved *@demo.invalid synthetic-identity namespace is already in live use by two seeders, a founding-locked SANDBOX/PRODUCTION world game mode with a triple-locked dev toolbox exists end-to-end (setup wizard step → GameMode gate → /dev/* routes → DevBar), six standing demo commands prove the charter's core doctrine that "the generator runs the engine, not a copy" at microstate scale, and the earth.* "Standard" half of the two-instance thesis (dormant cube-root scaffolding at every ~951k jurisdiction) is substantially materialized by Phase H's autoscale convergence. What is missing is exactly the hard, novel piece the charter itself flags: the per-visitor ephemeral copy-on-write overlay and the ~8-billion-person deterministic populate engine.

### [ABSENT] instance_settings.instance_class ('production'|'scale_demo')
  - database/schema/pgsql-schema.sql:2392-2426 — full instance_settings DDL has no instance_class column
  - database/migrations/2026_07_05_000001_setup_wizard_v2.php:31-36 — only post-baseline instance_settings change adds game_mode, not instance_class
  - grep 'instance_class' over the whole tree hits only docs (PHASE_LEDGER_A_TO_O.md, DELTA_DELIVERABLES_MASTER.md, CGA_PHASE_G_AND_BEYOND_ROADMAP.md)
  NOTES: The table exists and is rich (setup state, federation identity keys, mirror fields, game_mode, setup_mode, map_mode, time_mode) but carries no instance-class concept and nothing that FORCES federation_enabled=false.

### [SUBSTRATE] instance_settings table (existence check requested)
  - database/schema/pgsql-schema.sql:2392-2426 — columns incl. federation_enabled boolean DEFAULT false (line 2414), game_mode via 2026_07_05_000001_setup_wizard_v2.php, setup_mode 'solo'|'join' (SetupController.php:264), map_mode 'physical_earth|multiverse|elsewhere|no_map' (COMMENT line 2433), time_mode 'real|accelerated' (line 2440)
  NOTES: Built across Phases 0/F/G/setup-v2 as the singleton world-settings row. The natural home for instance_class — one additive migration away — but the column and its forcing semantics do not exist.

### [SUBSTRATE] instance_capabilities table (existence check requested)
  - database/schema/pgsql-schema.sql:2371-2385 — capability CHECK constraint: mesh.member, mirror, etl, broker.dns, broker.tls, client.serve, authority.grant, matrix.homeserver, voice.sfu; grant_signature/granted_by_server_id columns
  NOTES: Exists but was built for the Phase F/G mesh roles-and-channels model (signed capability grants between federation peers). It pattern-matches Phase O vocabulary ('instance') only; nothing in it is demo- or instance-class-related. Unrelated to the O deliverable, reusable only in the loose sense that a capability row could someday model a demo grant.

### [ABSENT] demo_sessions + demo_overlays (per-session copy-on-write sandbox, TTL-evicted) — DemoSandboxService
  - grep 'demo_sessions|demo_overlays|DemoSandboxService' → matches only in docs/plans/* (roadmap, ledger, K3 design docs)
  - grep -i 'demo' over database/schema/pgsql-schema.sql → zero matches (no demo table, no is_demo column anywhere in 183 tables)
  NOTES: The charter itself calls the CoW overlay 'the hardest, most novel piece — its own roadmap line' with read-only demo as MVP fallback. No trace of either the overlay or the read-only-session fallback exists. The closest conceptual relative — the world-grain SANDBOX game mode — is per-WORLD and permanent, not per-visitor and ephemeral.

### [ABSENT] demo_generation_runs (resumable generation cursor)
  - no table/model/migration named demo_generation_runs anywhere
  - SUBSTRATE pattern proven elsewhere: database/migrations/2026_07_18_000001_autoscale_run_state.php + 2026_07_19_000001_autoscale_pull_engine.php (autoscale runs/items/scopes), app/Console/Commands/AutoscalePumpCommand.php:31 (resumable pump), AutoscaleRevertCommand.php:30
  NOTES: The exact engineering pattern O needs — resumable, cursor-driven, planet-scale generation over 951k jurisdictions with revert — was built and battle-proven in Phase H's autoscale engine (521k sweeps + 903k singles). Reusable blueprint, but no demo-generation instance of it exists.

### [ABSENT] DemoPopulateService (deterministic seed = hash(jurisdiction_id)+version, drives engine statics)
  - grep 'DemoPopulateService' → only docs/plans/docs-recon/*.md and CGA_PHASE_G_AND_BEYOND_ROADMAP.md
  - app/Services/ contains no Demo* service (verified via glob + grep)
  NOTES: No synthetic-population engine exists at any scale. The 'demo math == engine math' doctrine it must obey is however already the house style of every standing demo seeder (see demo command fleet item).

### [PARTIAL] CI-2 hard rail — scale_demo instance refuses to federate
  - app/Services/Matrix/MatrixFederationGateService.php:36 — desiredFederationWhitelist(bool $scaleDemo = false) returns [] when true; header comment lines 12+27: 'a scale_demo instance forces it empty (CI-2, no consent)'
  - tests/Constitutional/MatrixFederationWhitelistTest.php:51-52 — pinned: assertSame([], $gate->desiredFederationWhitelist(scaleDemo: true))
  - docker/matrix/conf.d/10-cga.yaml:20-23 — federation_domain_whitelist: [] default with the CI-2 comment
  - app/Http/Middleware/VerifyPeerSignature.php:35 + app/Jobs/Federation/FederationHeartbeatJob.php:36 — federation_enabled=false (the DDL default, pgsql-schema.sql:2414) already makes an instance refuse peer traffic
  NOTES: The Matrix-plane half of CI-2 is genuinely implemented and constitutionally pinned — shipped forward with K-3, not Phase O. But it is driven by a hardcoded parameter defaulting to false (nothing reads an instance class), and the charter's app-level boot-assertion (refuse to SERVE a scale_demo instance with federation on) does not exist. Operationally an instance is federation-off by default until federation:init (FederationInitCommand.php:49), which is 'off by default', not 'forced off'.

### [PARTIAL] Reserved synthetic-identity namespace *@demo.invalid
  - app/Console/Commands/SocialDemoCommand.php:185 — $email = self::RESIDENT_MARKER.'-'.$slug.'@demo.invalid'
  - app/Console/Commands/MatrixDemoCommand.php:149 — same convention for Matrix demo residents
  NOTES: The exact namespace the O charter reserves is already in de facto production use by the K-1 and K-3 demo seeders — an unexpected head start. Nothing ENFORCES the reservation (no validation rule blocks a real signup at @demo.invalid), so it is a convention, not a rail.

### [SUBSTRATE] SANDBOX/DEV game mode (the memory question: what is it, where enforced)
  - app/Support/GameMode.php:26-27 — PRODUCTION|SANDBOX world property on instance_settings.game_mode; fail-closed reads (lines 41-54)
  - database/migrations/2026_07_05_000001_setup_wizard_v2.php:31-36 — the column + CHECK constraint (NOT a districting migration)
  - app/Http/Controllers/SetupController.php:2625-2650 — POST /api/setup/game-mode, operator-only, LOCKED once setup completes (409 at line 2636)
  - app/Http/Middleware/DevToolsEnabled.php:35-40 — triple lock: APP_ENV=local AND cga.impersonation AND GameMode::isSandbox(), else 404
  - routes/web.php:890-921 — /dev/* toolbox: impersonation, login-as, ping simulator, board seat/unseat (lines 910-911); registered only in local env
  - app/Http/Middleware/HandleInertiaRequests.php:275 — 'sandbox' shared prop drives nav dev group + resources/js/Components/Shell/DevBar.vue
  - app/Services/Setup/DeployPackageService.php:85 — GAME_MODE propagates into deploy packages
  NOTES: This is a WORLD-grain founding choice that unlocks dev tooling (assume-any-role, manufactured qualifications) on an unhardened world — the principled replacement for ambient dev flags. It is demo-ADJACENT substrate: the concept 'a world where nothing is a real government' exists and is enforced, but it is permanent and world-wide, not the per-visitor ephemeral CoW sandbox O specifies. Also note time_mode 'real|accelerated' + time_scale_seconds_per_year (pgsql-schema.sql:2397-2398) is stored but no engine consumes it — storage-only.

### [SUBSTRATE] Demo/seed command fleet (the requested inventory)
  - app/Console/Commands/ElectionsDemoCommand.php:85-90 — elections:demo {slug} {--voters=40} {--candidates=12} {--instant} {--again}: full real election end-to-end, 'every mutation goes through the constitutional engine' (lines 40-42); demo voters password 'demo', impersonatable
  - app/Console/Commands/PhaseDDemoCommand.php:107-108 — institutions:demo-d {--fresh}: Phase D executive/org flows, live-DB, per-step transactions (lines 97-103)
  - app/Console/Commands/PhaseEDemoCommand.php:103-106 — institutions:demo-e {--fresh}: judiciary exit-criterion flows on San Marino via the REAL ConstitutionalEngine
  - app/Console/Commands/SocialDemoCommand.php:36-38 — social:demo {--fresh}: K-1 civic commons (square+halls+testimony), self-mints @demo.invalid residents, drives F-SOC-001/002, ends with audit:verify
  - app/Console/Commands/MatrixDemoCommand.php:38-40 — matrix:demo {--fresh} {--offline}: K-3 Matrix commons topology + testimony + legitimacy flip on San Marino
  - app/Console/Commands/FederationDemoCommand.php:29-31 — federation:demo {--fresh}: synthetic trusted peer + FF&C sync history + flipped partition; --fresh retires only demo-tagged rows, never append-only history
  NOTES: Six idempotent standing demos, all obeying the exact CI-5 doctrine the O charter mandates ('the generator runs the engine, not a copy' — the demo IS the verification). They are microstate-scale showcases of individual phases, not the ~8B world materializer, and they write REAL data into the live instance — the opposite of O's zero-synthetic-data-on-earth.* rule. Pattern proven; scale and isolation absent.

### [SUBSTRATE] Setup wizard mode selection (setup_wizard_v2)
  - app/Http/Controllers/SetupController.php:264 — setup_mode in ['solo','join']
  - SetupController.php:2616-2650 — game-mode step (production|sandbox)
  - SetupController.php:3033-3034 — wizard serializer exposes setup_mode + game_mode
  - pgsql-schema.sql:2433,2440 — map_mode and time_mode comments (physical_earth|multiverse|elsewhere|no_map; real|accelerated)
  NOTES: Yes, the wizard carries mode selection — four axes (solo/join, production/sandbox, map mode, time mode) — but no instance-class axis. An instance_class picker would slot into this existing machinery naturally.

### [BUILT] earth.* 'Standard' half of the two-instance thesis (dormant scaffolding at every jurisdiction)
  - docs/plans/docs-recon/PHASE_LEDGER_A_TO_O.md:25 — H CONVERGED 2026-07-23: 951,626 legislatures districted, Earth 1,999 seats EXACT, leaf giants line-split
  - database/migrations/2026_07_18..2026_07_22 autoscale files — the run machinery that produced it
  NOTES: Belongs to Phase H, not O — but it IS the 'every ~951k jurisdiction shows the cube-root chamber / district map / institution scaffolding it could attain' half of O's thesis (charter line 436-438). The O-side gates on this are satisfied; what O adds on top of the Standard is the tier/consent dormancy (Phase I) — absent — and the separate Attained instance — absent.

UNEXPECTED: The CI-2 'scale_demo federates with no one' rail was built EARLY, inside Phase K-3: MatrixFederationGateService.php:36 + a constitutional pin (MatrixFederationWhitelistTest.php:51-52) + the default-empty Synapse whitelist (docker/matrix/conf.d/10-cga.yaml:23). No prior doc credits any Phase O implementation as existing. | The charter's reserved synthetic-identity namespace *@demo.invalid is already in live use by SocialDemoCommand.php:185 and MatrixDemoCommand.php:149 — the convention predates the phase that specifies it. | A founding-locked, operator-only, world-grain SANDBOX game mode exists end-to-end (setup_wizard_v2 migration → GameMode support class → DevToolsEnabled middleware → /dev/* toolbox incl. login-as and board-seat → DevBar.vue → GAME_MODE in deploy packages via DeployPackageService.php:85) — a demo-adjacent 'not-a-real-government world' primitive no Phase O doc mentions as substrate. | instance_settings.time_mode 'real|accelerated' + time_scale_seconds_per_year exist in the baseline DDL (pgsql-schema.sql:2397-2398) but nothing in app/ consumes them — a stored-but-dormant accelerated-clock knob relevant to any demo world. | The string 'demo' does not appear once in database/schema/pgsql-schema.sql — demo data is tagged purely by naming convention (email markers, slugs), never by schema.
TESTS: Direct pins that already exercise Phase O vocabulary: tests/Constitutional/MatrixFederationWhitelistTest.php (2 tests — the scale_demo empty-whitelist CI-2 pin at lines 51-52 plus whitelist invariants); tests/Feature/DevImpersonationTest.php (dev-route triple-lock 404s including the game-mode bolt, lines 24-50); tests/Constitutional/SubdivisionAutoseedTest.php:913-931 (GameMode::override drives dev board-seat gating pins — sandbox world reachable, production world 404). Zero tests exist for CoW overlays, demo sessions, or synthetic population (nothing to test). The six demo commands self-verify by ending in audit:verify rather than by phpunit coverage.
CORRECTIONS: PHASE_LEDGER_A_TO_O.md:34 marks O '◻ UNBUILT' — correct for the named deliverables, but it omits that one of O's hard rails (CI-2, scale_demo → empty federation whitelist) is already implemented and constitutionally pinned in the K-3 code (MatrixFederationGateService.php:12,27,36; MatrixFederationWhitelistTest.php:51-52), and that the *@demo.invalid namespace it specifies is already in production use by two seeders. | The working assumption that the 13 post-baseline migrations are 'all districting/autoscale' is wrong: 2026_07_05_000001_setup_wizard_v2.php adds instance_settings.game_mode (the production|sandbox world property) and 2026_07_08_000001_geodata_repair_plane.php is geodata-repair, not districting. | The roadmap (line 578-580) says 'the seven exploration docs in docs/plans/explorations/ are the authoritative seed material', but only districting-toolkit-recalibration.md exists there — full-scale-demo.md, O's cited source, is not in the repo at all. | The ledger frames O as having zero in-repo footing; in fact the earth.* Standard half of O's two-instance thesis is materially delivered by Phase H (951,626 districted legislatures, ledger's own line 25) — O's remaining novel scope is narrower than the row implies: the Attained instance (populate engine + CoW sandbox + instance_class isolation), not the planetary scaffolding.


===== [sketchbook] App_Flows sketchbook concepts + unattributed baseline systems — PARTIAL =====
The prior ledger's "never built" sketchbook list is materially wrong on one item and half-wrong on two more: Apply-for-Grants shipped in Phase D (tables, hardened GrantService, a live application route and UI card — though award/disburse are dead code with zero callers), the fund-DISTRIBUTION half of "Fundraising/Fund Distribution" is that same appropriations-by-act pipeline, and equal_partnership is a first-class org structure with real board-seating behavior. Family Tree and Asset Registration are genuinely absent, and Endorse Policies is absent — the policy_proposals table is a false cognate (Phase D's department-internal F-EXE-002, decided by the department board, not citizen policy endorsement). Meanwhile all 14 "unattributed" baseline tables are attributable, live systems: Phase C (public_records, admin_offices, misconduct_investigations, cultural_institutions), Phase D (governor_removal_requests, grants trio), Phase E (warrants, sentencing_orders, remedy_recommendations), the F/G federation arc (restoration_events, directory_entries, operational_partition_exports, read_write_requests), plus the Setup-wizard data-review surface and the invite-links growth flow. None are orphans.

### [ABSENT] Family Tree Support (App_Flows concept)
  - grep family|kinship|relative|guardian across app/ hits only false positives: app/Services/TileCacheInvalidator.php:55 ('relative to storage/app'), app/Services/ConstitutionalVersionService.php:95 (relative paths)
  - grep family|kinship|guardian in database/schema/pgsql-schema.sql: zero matches
  - resources/js matches are CSS/clock 'families' only (resources/js/Pages/System/Clocks.vue:36-45)
  NOTES: No table, model, service, form, route, or page anywhere. Correctly classified as unbuilt by the prior ledger.

### [PARTIAL] Apply for Grants (App_Flows concept)
  - appropriations DDL pgsql-schema.sql:398; grant_applications :2335-2349; grant_disbursements :2356-2364
  - app/Services/Executive/GrantService.php:17-24 (D-6 docblock: legislatures appropriate BY ACT, executives administer; award<=remaining, sum(disbursements)<=awarded under FOR UPDATE; 'Budget UX is post-F backlog')
  - Who can grant: GrantService.php:256-265 assertDecider — only SEATED members of the administering executive; appropriations attach only to in-force acts (GrantService.php:36-41)
  - Live intake: routes/web.php:677 POST /appropriations/{appropriation}/applications -> ExecutiveActionController::storeApplication (ExecutiveActionController.php:181-192); UI card 'Grants & appropriations' with apply form at resources/js/Pages/Executive/Actions.vue:427-523
  - Dead half: grep 'award(|disburse(|createAppropriation' across all *.php — only the definitions at GrantService.php:34,93,186 match; zero callers, no route, no F-* form, no seeder (PhaseDDemoCommand has no Appropriation/Grant references), no test mentions appropriation/disburse anywhere in tests/
  NOTES: Built in Phase D as D-6 'minimal viable', not an unbuilt concept. Application intake works end-to-end in the UI; appropriation creation, award/decline, and disbursement exist only as un-invoked service methods — no operator or executive can actually complete the grant lifecycle through any surface today, and nothing tests it.

### [PARTIAL] Legislature Fundraising / Fund Distribution Tools (App_Flows concept)
  - Fundraising side: grep fundrais|donat across app/, database/schema/pgsql-schema.sql, resources/js — zero real matches (only a fixture person named 'Donatella', resources/js/fixtures/legislature.json:74)
  - Distribution side: the Phase D appropriations->grants pipeline (GrantService.php:17-24 'legislatures appropriate BY ACT... executives administer'; appropriations DDL pgsql-schema.sql:398)
  NOTES: Split verdict: fund DISTRIBUTION is the built Phase D appropriations/grants machinery (with the caller gaps noted above); FUNDRAISING (donations, revenue intake, campaign finance) has no trace in schema or code. The ledger's suggestion to fold this into L/M public finance only applies to the missing raising half.

### [ABSENT] Asset Registration Support (App_Flows concept)
  - grep asset in pgsql-schema.sql: only cgc_ip_register.asset column (:914) and its COPY row (:5077)
  - grep property|land_regist in pgsql-schema.sql: zero matches
  - cgc_ip_register is the Phase D Art. III Sec. 5 public-domain IP register (app/Services/Organizations/CgcIpRegisterService.php; append-only, dedicate-only; pinned by tests/Constitutional/CgcIpPublicDomainTest.php)
  NOTES: No general asset/property registration exists. The only 'asset' artifact is the CGC intellectual-property dedication register — built for a different constitutional purpose (public-domain IP, Phase D D-O6); it is at most an adjacent pattern, not a substrate for property registration.

### [ABSENT] Endorse Policies (App_Flows concept) — and whether policy_proposals is its built version
  - policy_proposals DDL pgsql-schema.sql:3991-4006: executive_id + department_id + proposed_by_member_id + board_vote_id, decision in pending/adopted/amended/declined — executive-internal, not citizen-facing
  - app/Domain/Forms/Handlers/DepartmentPolicyProposal.php:13-19: F-EXE-002 'The executive proposes; the department BOARD decides' (FormRegistry.php:152,334; route routes/web.php:673-674; roles R-14/15/16 only)
  - endorsements DDL pgsql-schema.sql:1939-1952: election_id and candidate_id both NOT NULL — endorsement targets are strictly candidates, no polymorphic policy target
  - Citizen policy substrate: app/Services/PetitionService.php:35 + petition_signatures (pgsql-schema.sql:3943) + referendum_questions origin='petition' (pgsql-schema.sql:4104-4125)
  NOTES: policy_proposals is a FALSE COGNATE — it is Phase D executive/department machinery, not the sketchbook's citizen 'endorse policies' flow. The concept as sketched is unbuilt; the closest built analogs are candidate endorsements (candidates only) and the petition->referendum ladder (initiative support, not endorsement). Building it would need schema work since endorsements cannot point at anything but candidates.

### [BUILT] cultural_institutions + CulturalInstitutionService
  - pgsql-schema.sql:1517-1530
  - app/Services/Jurisdictions/CulturalInstitutionService.php:13-18: F-LEG-028, Art. V Sec. 2 — chamber SUPERMAJORITY recognizes a POWERLESS honour row (no legislative/executive/judicial powers by construction), published to the public record
  - Filed via engine form F-LEG-028 (FormRegistry.php:395 -> Handlers/CulturalInstitutionRecognitionVote.php:44) -> ChamberActService::proposeCulturalInstitution (:178) -> adoption effect (:483)
  - Vote type 'cultural_institution' pinned in tests/Constitutional/VoteTypeRegistryTest.php:26; designed in docs/plans/institutions/PHASE_C_DESIGN_votes_laws.md
  NOTES: Phase C chamber-vote machinery (Art. V Sec. 2 honour). End-to-end through the form engine + chamber supermajority vote; no dedicated Vue page or route (rides the generic proposal machinery) and no dedicated test file beyond the vote-type registry pin — thin but functionally complete.

### [BUILT] admin_offices
  - pgsql-schema.sql:341-351
  - F-LEG-013 AdminOfficeCreationAct (FormRegistry.php:293)
  - route POST /legislatures/{legislature}/admin-office (routes/web.php:594-595, OversightController::createOffice)
  - app/Services/Legislature/OversightService.php:20-28 (chamber ops Sec. D.3); tests/Feature/PhaseCGroupBControllersTest.php, tests/Feature/PhaseCChamberOpsHandlersTest.php
  NOTES: Phase C oversight: legislature-created administrative offices that host the misconduct docket (I-ADM). Created by act/vote; statuses created/staffed/dissolved.

### [BUILT] invites
  - pgsql-schema.sql:2503-2520
  - app/Services/Invites/InviteService.php:16-23: person-to-person 'handle.secret' growth primitive; server-built same-origin destinations only (SSRF/open-redirect guard); redeeming confers NO power
  - routes/web.php:179-191 (GET /i/{token} land, POST /invites mint); app/Http/Controllers/Auth/Concerns/RedeemsPendingInvite.php
  - tests/Constitutional/InviteFlowTest.php, tests/Feature/InviteLandingPreviewTest.php
  NOTES: The invite-links + share-to-signup growth flow (post-Phase-G social/growth work, per the invite-links project) — not a charter-phase deliverable but fully built and tested.

### [BUILT] directory_entries
  - pgsql-schema.sql:1624-1637
  - app/Services/Federation/DirectoryService.php:10-25: Phase G G9 — advisory, SIGNED, replicable jurisdiction->endpoints lookup; deliberately powerless (never decides authority, never gates a filing)
  - routes/mesh.php:14 (G9 routing hints); tests/Constitutional/DirectoryAdvisoryTest.php, tests/Feature/NearestNodeRoutingTest.php
  NOTES: Phase G federation directory for write-forwarding (G4). Entries self-authenticate via the named server's signature; tampered relays rejected on ingest.

### [BUILT] public_records
  - pgsql-schema.sql:4013-4034 (16-kind CHECK incl. moderation_flip and legal_compliance_removal)
  - app/Services/PublicRecordService.php:10-27: C-1 (WF-SYS-03) — the ONLY write path into the curated public register; audit-chain sealed (audit_seq); FORBIDDEN_SUBJECT_TYPES guard (ballots, location pings, K-1 social graph) at :34-45
  - routes/web.php:628-631 (public read + F-LEG-006 statements); tests/Feature/PublicProceedingsGuestTest.php, tests/Constitutional/ModerationFlipTest.php, tests/Constitutional/LegalComplianceTest.php
  NOTES: Phase C foundation (C-1), later extended as the substrate of the Phase K-1 civic record plane and federation record sync (source_server_id, translations). Consumed by services across every phase (grants, cultural institutions, oversight, judiciary).

### [BUILT] data_review_decisions
  - pgsql-schema.sql:1537-1546
  - app/Services/DataReviewService.php:9-29: Setup wizard Step 4 post-ETL data-quality surface (population gaps, aggregation discrepancies >5%, orphans, sovereign territories); decisions persisted at DataReviewService.php:1184-1232
  - Rides map-data exports: app/Services/MapDataExportService.php:93
  NOTES: Geodata/Setup-wizard era (ETL lane), not a constitutional system: records the operator's per-category acknowledgment decisions before finalizing an instance. No dedicated test file found — the one thin spot.

### [BUILT] remedy_recommendations
  - pgsql-schema.sql:4136-4158 (remedy modify/remove, CLK-11/12 timer ids, veto windows)
  - F-JDG-005 handler (FormRegistry.php:382, app/Domain/Forms/Handlers/RemedyRecommendation.php); app/Services/Judiciary/JudicialRemedyService.php
  - tests/Constitutional/Art4Section5Test.php
  NOTES: Phase E — the middle path of the Art. IV Sec. 5 three-path constitutional challenge: court recommends modify/remove with a legislative veto window before direct judicial law-editing.

### [BUILT] warrants
  - pgsql-schema.sql:4777-4797 (arrest/search/seizure, max_hold_duration_hours, stated_reason NOT blank, quashable)
  - F-JDG-010 WarrantIssuance (FormRegistry.php:370); route POST /cases/{case}/warrants (routes/web.php:761-762, CaseController::warrant)
  - tests/Constitutional/CaseLifecycleTest.php
  NOTES: Phase E judiciary — case-bound warrants issued by a judicial seat, with hold-duration and reason constraints enforced in DDL.

### [BUILT] sentencing_orders
  - pgsql-schema.sql:4260-4274 (case_id + verdict_id bound; issued/stayed/vacated/completed)
  - F-JDG-009 (FormRegistry.php:369, app/Domain/Forms/Handlers/SentencingOrder.php); route POST /cases/{case}/sentencing (routes/web.php:759-760)
  - tests/Constitutional/CaseLifecycleTest.php
  NOTES: Phase E judiciary — verdict-bound sentencing with public-record linkage (record_id).

### [BUILT] misconduct_investigations
  - pgsql-schema.sql:3336-3351 (admin_office_id NOT NULL; intake/investigating/referred/closed_no_finding; referred_proceeding_id)
  - app/Services/Legislature/OversightService.php:20-35: I-ADM docket; intake is an audited NON-form action (flagged registry gap), CLK-02 repeated-quorum-failure refers with NULL complainant; referral feeds removal_proceedings (F-LEG-022/F-SPK-007)
  - routes/web.php:586-589 (intake + refer); tests/Feature/PhaseCGroupBControllersTest.php, tests/Constitutional/GovernorRemovalOrdinaryMajorityTest.php
  NOTES: Phase C oversight machinery, extended in Phases D/E as the removal-proceeding feeder (executive removal, judge removal). Adopted removals system-file F-LEG-036 into the Phase B vacancy machinery.

### [BUILT] governor_removal_requests
  - pgsql-schema.sql:2315-2328 (board_seat_id, grounds, vote_id, removed/retained)
  - F-EXE-003 Board Member Removal Request (FormRegistry.php:153,335); app/Services/Executive/BoardGovernorService.php
  - Dedicated test: tests/Constitutional/GovernorRemovalOrdinaryMajorityTest.php
  NOTES: Phase D Board-of-Governors machinery — removal of a department governor by board vote at ordinary majority, distinct from legislature removal_proceedings.

### [BUILT] restoration_events
  - pgsql-schema.sql:4237-4253 (conditions countermanded/captured/destroyed; tier 1-3; judicially_confirmed)
  - app/Services/Jurisdictions/RestorationService.php:10-18: Art. VI Sec. 2-3 — declared on a condition, CONFIRMED only via a judicial constitutional finding on a tied Phase E case (no unilateral activation); strict three-tier restoration cascade (constituents -> encompassing -> individuals)
  - tests/Constitutional/RestorationJudicialReviewTest.php
  NOTES: The Art. VI constitutional-restoration ladder, built in the Phase F/G federation arc (union/disintermediation/border/restoration group per the Phase F charter). WF-JUR-07 audit refs.

### [BUILT] operational_partition_exports
  - pgsql-schema.sql:3447-3460 (outbound/inbound, election_count, sealed_fingerprint)
  - app/Services/Federation/OperationalBundleService.php:13-35: Phase G G5 — the SEALED point-to-point handover of per-election ballot keys (k_e) during an autonomy flip; libsodium sealed box to the gaining cluster; ledger + audit carry counts and fingerprints only, never keys; re-wrap via BallotKeyRewrapService::adopt proves counts reproduce before commit
  - app/Console/Commands/FederationFlipExportCommand.php; tests/Constitutional/OperationalBundleSealedTest.php, tests/Constitutional/AutonomyFlipRewrapsKeysTest.php
  NOTES: Phase G authority-flip machinery — the one sanctioned exception to 'ballots/keys never federate'. The table is the no-key-material ledger of those sealed transfers.

### [BUILT] read_write_requests
  - pgsql-schema.sql:4080-4094 (applicant_server_id, root_jurisdiction_id, autonomy_process_id; submitted/vote_opened/granted/denied/withdrawn)
  - app/Services/Federation/ReadWriteRequestService.php:9-21: Phase G G3c — host-side intake of a mirror's petition to become a read-write peer; deliberately OFF the mirror-admission path; the grant itself is the Art. V Sec. 7 governed flip (LocalAutonomyService/AuthorityFlipService)
  - routes/federation.php:86-89 (POST /request-read-write), routes/web.php:958-960 (mirror-side GUI front door); app/Console/Commands/FederationReadWriteRequestCommand.php; tests/Feature/ReadWriteRequestTest.php, tests/Feature/MirrorReadWriteRequestTest.php
  NOTES: Phase G federation autonomy ladder — the petition/status ledger that feeds the dual-supermajority (or operator-board) authority grant.

### [SUBSTRATE] Equal Partnership Agreement flow (ledger's sixth 'never built' concept, checked in passing)
  - app/Models/Organization.php:37-48 (STRUCTURE_PARTNERSHIP, STRUCTURE_EQUAL_PARTNERSHIP as first-class org structures; :67-68 maps both to partner memberships)
  - app/Domain/Forms/Handlers/BoardElectionAdministration.php:72-75: 'Equal partnerships seat every partner by convention' in board elections
  - app/Http/Controllers/Organizations/OrganizationController.php:58: UI copy 'Equal partners; partnership changes require unanimity'
  NOTES: Not a dedicated agreement flow, but equal_partnership is an implemented Phase D org structure with real board-seating behavior — the ledger's blanket 'never built' overstates the gap.

UNEXPECTED: GrantService dead code: award(), decline(), disburse(), and createAppropriation() (app/Services/Executive/GrantService.php:34,93,167,186) have ZERO callers repo-wide — no route, no form, no demo command, no seeder, no test. The grant lifecycle cannot actually be completed through any surface even though the service enforces full hardened invariants; PhaseDDemoCommand seeds no appropriations, so the Executive/Actions.vue 'Grants & appropriations' card renders empty on demo data. | The endorsements table is structurally candidate-only (election_id + candidate_id NOT NULL, pgsql-schema.sql:1940-1942) — any future 'endorse policies' feature needs new schema, not a config change. | misconduct-investigation intake is an audited NON-form action with a flagged registry gap (OversightService.php:26-28 'Intake has NO catalog form') — a known hole in the 109-form catalog worth carrying in the delta ledger. | equal_partnership already carries behavior (board elections seat every partner, BoardElectionAdministration.php:72-75) despite being listed as a never-built concept. | cultural_institutions (F-LEG-028) has no dedicated route, Vue page, or test — it is engine-reachable only through the generic chamber-vote proposal machinery. | public_records' kind enum already carries the K-era moderation_flip and legal_compliance_removal kinds inside the baseline dump (pgsql-schema.sql:4034) — the Phase C register and the Phase K civic-record plane are one table, not two systems.
TESTS: Unattributed systems are well covered where constitutional: tests/Constitutional/Art4Section5Test.php (remedy_recommendations), CaseLifecycleTest.php (warrants + sentencing_orders), GovernorRemovalOrdinaryMajorityTest.php (governor_removal_requests + removal loop), RestorationJudicialReviewTest.php, OperationalBundleSealedTest.php + AutonomyFlipRewrapsKeysTest.php (operational_partition_exports), DirectoryAdvisoryTest.php + tests/Feature/NearestNodeRoutingTest.php (directory_entries), tests/Feature/ReadWriteRequestTest.php + MirrorReadWriteRequestTest.php, InviteFlowTest.php + InviteLandingPreviewTest.php, PhaseCGroupBControllersTest.php + PhaseCChamberOpsHandlersTest.php (oversight/admin offices), PublicProceedingsGuestTest.php + ModerationFlipTest.php + LegalComplianceTest.php (public_records), VoteTypeRegistryTest.php:26 (cultural_institution vote type pin). Zero-coverage spots: the entire grants/appropriations pipeline (no test file references Appropriation, GrantApplication, or disburse anywhere in tests/), data_review_decisions, and cultural_institutions beyond the vote-type pin.
CORRECTIONS: PHASE_LEDGER_A_TO_O.md:89-90 lists 'Apply for Grants' among App_Flows concepts 'never built' — wrong. Phase D D-6 shipped appropriations (pgsql-schema.sql:398), grant_applications (:2335), grant_disbursements (:2356), GrantService with hardened invariants, a live application route (routes/web.php:677) and the Grants & appropriations UI card (resources/js/Pages/Executive/Actions.vue:427). Correct status is PARTIAL: award/disburse/appropriation-creation are un-invoked service methods with no surface or tests. | PHASE_LEDGER_A_TO_O.md:90 'Legislature Fundraising / Fund Distribution Tools' — half wrong. Fund DISTRIBUTION exists as the Phase D appropriate-by-act -> executive-administers grants pipeline (GrantService.php:18-24). Only the fundraising/donation-intake half is truly absent; the fold-into-L/M note should apply to that half alone. | PHASE_LEDGER_A_TO_O.md:91-92 'Equal Partnership Agreement flow' as never built — overstated. equal_partnership is an implemented org structure (app/Models/Organization.php:39) with board-seating behavior (BoardElectionAdministration.php:72-75) and unanimity copy in the org UI (OrganizationController.php:58); only a dedicated agreement/formation flow is missing. | PHASE_LEDGER_A_TO_O.md:91 'Endorse Policies (vs built endorse-candidates/petitions)' — the ABSENT verdict stands, but add the guard: the policy_proposals table (pgsql-schema.sql:3991) must NOT be read as this concept's built version — it is Phase D F-EXE-002 department-internal policy decided by the department board (DepartmentPolicyProposal.php:13-19). | Family Tree Support and Asset Registration Support — prior 'never built' claims CONFIRMED correct after exhaustive search (only false positives: 'relative path' comments, CSS families, cgc_ip_register.asset which is the Phase D public-domain IP register). | All 14 baseline tables the prior docs left unattributed now have firm phase homes: Phase C — public_records (C-1/WF-SYS-03), admin_offices (F-LEG-013), misconduct_investigations (I-ADM), cultural_institutions (F-LEG-028); Phase D — governor_removal_requests (F-EXE-003), policy_proposals (F-EXE-002), appropriations/grants (D-6); Phase E — warrants (F-JDG-010), sentencing_orders (F-JDG-009), remedy_recommendations (F-JDG-005); F/G federation arc — restoration_events (Art. VI), directory_entries (G9), operational_partition_exports (G5), read_write_requests (G3c); non-charter lanes — data_review_decisions (Setup wizard Step 4 / geodata ETL), invites (share-to-signup growth flow).


===== [phases-ABC] Phases A–C verification (A=Foundation, B=Identity & Jurisdictions, C=Elections — CLAUDE.md Phases 0–2; prior ledger's rows "A" and "B") — BUILT =====
Phases A–C are genuinely built end-to-end, and the prior ledger's BUILT claims for them survive a hostile audit: the ConstitutionalEngine really is the single filing pipeline with a sha256 hash-chained audit log serialized under a Postgres advisory lock; all 21 clocks CLK-01..21 exist as a seeded registry with an every-minute scheduler sweep and mapped handler jobs; residency is a full declare→ping→verify machine with a recursive-CTE ancestor sweep and privacy purge of raw GPS; roles R-00..R-30 are all derived (never stored) in one service; and the PROTECTED VoteCountingService implements STV/Droop/Gregory in pure fixed-point integers with two-phase envelope/ballot secrecy, a salt+sha256 commitment scheme, bootstrap boards, certification auto-seating, and a queued universal countback. The only factual drift found: the registry holds 108 canonical forms, not the 109 CLAUDE.md and the ledger cite; 106 of 108 are handler-wired (F-LEG-020/021 deliberately unregistered by design); and the 21-clock registry rows ship via a deploy-script seeder, not inside the baseline dump.

### [BUILT] A: ConstitutionalEngine + FormRegistry dispatch
  - app/Domain/Engine/ConstitutionalEngine.php:38-127 — file() pipeline: canonicalize → authorize → validate → execute-in-transaction → audit in SAME transaction; rejections recorded as rejected=true chain entries with credential/ballot-content stripping (SENSITIVE_KEYS :41-75)
  - app/Domain/Forms/FormRegistry.php:8-16,44-52 — 108 canonical forms (103 Template + F-ELB-008 + F-SOC-001..004); ALIASES (6) at :195-202; CATALOG_DRIFT (8 stale workflow IDs, never auto-resolved) at :211+; HANDLERS map at :231 wires 106/108 forms to handler classes
  - app/Domain/Forms/Handlers/ — 76+ handler classes (147 files under app/Domain total)
  - F-LEG-020/021 unwired BY DESIGN: FormRegistry.php:285,309,328,343 — consent VOTES cast through chamber-vote machinery, not separate filings
  - tests/Feature/AuditChainSmokeTest.php (17 tests, incl. :141 alias/drift pinning, :279 rights-automatic guard on residency forms)
  NOTES: Works exactly as claimed; one precision: engine now also carries the Phase G mirror write-guard (ConstitutionalEngine.php:108-120 — a read-only mirror refuses every filing), layered onto the Phase A core without altering it.

### [BUILT] A: hash-chained audit_log + AuditService + audit_checkpoints + audit_chain_reconciliations
  - app/Services/AuditService.php:31-101 — sole audit_log insert path; hash(n)=sha256(hash(n-1)||canonical_json); every append serialized on pg_advisory_xact_lock key 0x4155444954 (:40,77) so the chain cannot fork under scheduler+Horizon concurrency; verifyChain() walk at :115+
  - database/schema/pgsql-schema.sql:525 (audit_log), :491 (audit_checkpoints), :468 (audit_chain_reconciliations); genesis row rides in the dump (COPY public.audit_log at :4948)
  - app/Services/ChainReconciliationService.php:24-60 — detectBreaks() + constitutional acknowledgement repair path (breaks re-grounded by recorded authority, never rewritten)
  - tests/Constitutional/AuditAppendSerializationTest.php (3), tests/Constitutional/ChainReconciliationTest.php (3), tests/Feature/AuditChainSmokeTest.php (17)
  NOTES: Chain + reconciliation fully live. Nuance: audit_checkpoints is a Phase F artifact — append-only signed head checkpoints written exclusively by FederationSyncService::publishCheckpoint() (app/Models/AuditCheckpoint.php:10-16); it belongs to the audit family but was built for federation, not the original Phase A scope.

### [BUILT] A: clocks CLK-01..21 census (registry + runtime)
  - app/Services/ClockService.php:20-100 — arm()/fire()/cancel() runtime; HANDLERS map :50-90; STEP_HANDLERS :99 (CLK-01 dual duty: schedule_general + election phase timers → AdvanceElectionPhaseJob)
  - database/seeders/ClockRegistrySeeder.php:59-240 — all 21 registry rows; seeded by deploy.ps1:167 / deploy.sh:216 (COPY public.clocks in the baseline dump is EMPTY — pgsql-schema.sql:5117)
  - database/schema/pgsql-schema.sql:1051 (clocks), :1029 (clock_timers)
  - Census: CLK-01 General Election Interval→ScheduleGeneralElectionJob; CLK-02 Meeting Deadline (90d)→MeetingDeadlineJob; CLK-03 Emergency Powers Max→ExpireEmergencyPowerJob; CLK-04 Special Election Window (countback-failed backstop)→SpecialElectionBackstopJob (VacancyService.php:387-416); CLK-05 Residency Threshold→EvaluateResidencyThresholdsJob; CLK-06 Critical Population (activation trigger)→EvaluateCriticalPopulationJob; CLK-07 Legislature Max Size=9 (validator-side, SettingsController.php:63); CLK-08 Min Size=5 (:62); CLK-09 Judicial/Civil 10-yr Term→CivilTermExpiryJob; CLK-10 Term Lockstep (derived flag, fires_at NULL — CertificationService.php:48-58); CLK-11 Judicial Veto Window→JudicialAutoRemedyJob (Art. IV §5 auto-remedy); CLK-12 Legislative Remedy Timeframe→LegislativeWindowLapsedJob; CLK-13 Co-det Minimum 100→EvaluateCoDeterminationJob; CLK-14 Co-det Parity 2000→same; CLK-15 Min Judges/Elected Race=5 (JudiciaryController.php:74); CLK-16 Case Panel Minimum (PanelSizing.php:35, full court for major constitutional questions); CLK-17 Petition Signature Threshold→EvaluatePetitionThresholdJob; CLK-18 Approval/Registration Window→FinalistCutoffJob; CLK-19 Referendum Act Protection — NO timer, validator gate referendum.shield (ClockService.php:85-89); CLK-20 Federation Sync Heartbeat→FederationHeartbeatJob (Phase F); CLK-21 Finalist Count per Race — derived formula X=multiplier×seats, no timer (ElectionLifecycleService)
  - tests/Feature/SystemClocksAmendmentsTest.php (4, pins '21 canonical rows'), tests/Constitutional/ElectionClockTest.php (6)
  NOTES: All 21 clock IDs present in code with live semantics. CLK-07/08/15/16/19/21 are correctly timerless (validator rules or derived formulas), the rest have mapped handler jobs.

### [BUILT] A: scheduler wiring (routes/console.php)
  - routes/console.php:26 — Schedule::job(new EvaluateClocksJob)->everyMinute()->withoutOverlapping()->onOneServer() (fires due clock_timers + CLK-05/06 threshold sweep)
  - routes/console.php:39-40 autoscale:pump everyMinute; :46 ApprovalStandingsRollupJob daily; :51-73 Phase D/G/K-1 nightly sweeps
  - app/Jobs/EvaluateClocksJob.php (sweep job; :24 CLK-05/CLK-06, :66 CLK-17)
  NOTES: Every-minute constitutional sweep plus HA tick-leader election via Redis onOneServer lock — exactly the claimed design.

### [BUILT] A: activation engine
  - app/Services/ActivationService.php:17-80 — onCriticalPopulation (CLK-06) + activate() pipeline: bootstrapping → legislature sizing (cube-root/leaf clamp) → institution stubs → bootstrap election board (is_bootstrap=true, synthetic system member) → initial district map (system-filed F-ELB-003) → F-ELB-001 first general election → self_governing; replan() path
  - database/schema/pgsql-schema.sql:2694 (jurisdiction_activations)
  - tests/Constitutional/ActivationMathTest.php (8 tests — pure seat-math statics pinned without DB)
  NOTES: Complete WF-JUR-01 bootstrap pipeline, audited at every step, idempotent.

### [BUILT] A: design system + AppShell + i18n scaffold
  - resources/js/Layouts/AppShell.vue + AppShellV2.vue
  - resources/js/Components/Ui/ — 29 components (Btn, Card, Field, DataTable, EngineChip, HardenedChip, CitationLine, ThresholdMeter, LawDiff, LifecycleTracker, ...)
  - resources/js/i18n/ — en/es/ar/hi/zh-Hans.json + glossary/ + index.js
  NOTES: Design system and shell present with the 5-locale chrome catalogs; full body-copy extraction remains the Phase N charter item (out of this module's scope).

### [BUILT] B: UUID users + session auth
  - database/schema/pgsql-schema.sql:4673-4697 — users.id uuid DEFAULT gen_random_uuid(), status/identity_verified_via CHECK constraints, home_server_id, is_operator, soft deletes
  - routes/auth.php:11-19 — register/login/logout session routes
  - tests/Feature/AuthPagesTest.php (4 tests)
  NOTES: As claimed.

### [BUILT] B: residency_claims/confirmations + ResidencyService + location_pings
  - app/Services/ResidencyService.php:15-110 — F-IND-003/005/006 delegate behind the engine; supersede/relocation postures (active claim keeps rights until new claim verifies — zero rights gap :60-66,88-95); privacy invariant: raw pings DELETEd on verification/supersession (:28-32,102-104)
  - database/schema/pgsql-schema.sql:4191 (residency_claims), :4215 (residency_confirmations — voting_right_active + candidacy_right_active default true, depth column), :3130 (location_pings)
  - app/Domain/Forms/Handlers/{ResidencyDeclaration,GpsResidencyPing,ResidencyVerificationConfirmation}.php
  - tests/Feature/AuditChainSmokeTest.php:69-71 (declare→verify chain events), :279 (residency forms may never carry eligibility conditions)
  NOTES: Full lifecycle built with the Art. I privacy discipline (coordinates never enter audit payloads).

### [BUILT] B: recursive ancestor-sweep associations
  - app/Services/ResidencyService.php:208-218 — recursive-CTE ancestor sweep from declared jurisdiction up, plus overlapping jurisdictions and THEIR chains; :296-297 transfer semantics on relocation (shared ancestors keep active rows); :504-539 ancestorChain()/recursive CTE up parent_id; :584-587 idempotent bulk association insert
  - residency_confirmations.depth column (pgsql-schema.sql:4228)
  NOTES: The single F-IND-006 filing carries the whole association jurisdiction-id list (:342-348) — one audit entry per verification, as designed.

### [BUILT] B: derived roles — R-01..R-04 and the full R-xx census
  - app/Services/RoleService.php:9-92 — roles DERIVED never stored (no grants table); request-cached singleton with flushUser()
  - Census (all found in RoleService.php:17-79 + derivation code): R-00 Visitor (display-only mapping for guests, HandleInertiaRequests.php:27) · R-01 Individual · R-02 Verified (active claim) · R-03 Associated (active confirmation) · R-04 Voter (≡R-03, Art. I) · R-05 Petitioner (:206-210) · R-06 Candidate (standing candidacy) · R-07 Endorsed · R-08 Board member (incl. operator-as-bootstrap-board posture) · R-09 Legislator · R-10 Speaker · R-11 Committee member · R-12 Committee chair · R-13 Alternate chair · R-14 Delegated exec · R-15 Exec committee member · R-16 Individual exec · R-17 Exec advisor · R-18 Dept governor · R-19 Appointed judge (:275) · R-20 Elected judge (:279) · R-21 Advocate · R-22 Juror · R-23 Org agent · R-24 Org member · R-25 Worker · R-26 Owner-elected board · R-27 Worker-elected board · R-28 Board chair · R-29 Admin staff · R-30 Civil officer
  - tests/Constitutional/RightsAutomaticTest.php — pins R-04 ⇔ R-03 (voting right automatic upon association)
  NOTES: Far beyond the R-01..R-04 Phase B claim: 31 role codes (R-00..R-30) all derive in one service, with each later phase's roles layered in. No stored-grant drift is possible by construction.

### [BUILT] C: VoteCountingService + app/Domain/Counting/*
  - app/Services/VoteCountingService.php:13-90 — PROTECTED header; PR-STV Droop floor(valid/(seats+1))+1 with Weighted Inclusive Gregory transfers in 6-dp scaled integers; Art. II §5 countback = full deterministic re-run with vacating candidacies struck; Art. III §3 RCV + top-4 advisors; PURITY CONTRACT (no DB/clock/RNG — byte-identical CountResult incl. record_hash); ENGINE_VERSION 'stv-droop-wig/1.0.0' (:75); deterministic backwards tie-break + audit-chained seeded lot (:47-60); per-round conservation invariant Σtallies+exhausted+residue == total×SCALE (:62-65)
  - app/Domain/Counting/{Micro,BallotSet,CountInput,CountResult,RoundResult,CountbackResult}.php — all six present
  - tests/Constitutional/StvDroopGregoryTest.php (11), RcvTest.php (6), CountbackUniversalTest.php (5); tests/Support/SyntheticBallotGenerator.php
  NOTES: Matches the protected-file charter exactly, pinned against the 412,383-ballot Queens fixture semantics.

### [BUILT] C: two-phase ballots + commitment scheme (ballot_envelopes / ballots)
  - database/schema/pgsql-schema.sql:585 ballot_envelopes (voter identity + committed_at, kind ranked|referendum) and :602 ballots (payload_encrypted, salt char(64), ballot_hash char(64), cast_bucket, NO user_id) — cryptographic separation of identity from content
  - app/Domain/Ballots/BallotCrypto.php:9-41 — XSalsa20-Poly1305 secretbox under per-election key k_e wrapped by app-key-derived KEK; commitment ballot_hash = sha256(salt_hex ‖ canonical_rankings_json); receipt = {ballot_hash, salt}
  - app/Domain/Ballots/BallotBox.php:37,62-95 — single insert path, one-time receipt shown once and stored nowhere
  - tests/Constitutional/BallotSecrecyTest.php (9), BallotRewrapFailsClosedTest.php; ConstitutionalEngine.php:49-56 strips 'rankings'/'choice' from rejection payloads
  NOTES: Precision on the module prompt: vote_casts (pgsql-schema.sql:4753) is NOT part of the citizen ballot flow — it is the CHAMBER vote-cast table (member_id XOR board_seat_id, lanes all/type_a/type_b) belonging to legislature/board operations. The citizen two-phase secrecy pair is ballot_envelopes + ballots. Honest limitation documented in-code: receipt-freeness explicitly out of scope (vote-selling channel), flagged for cryptographer review before production (BallotCrypto.php:27-29).

### [BUILT] C: election_boards bootstrap + certification auto-seating + countback
  - database/schema/pgsql-schema.sql:1752 election_boards (is_bootstrap boolean, forming|active|retired), :1732 election_board_members (synthetic system member user_id NULL), :1772 election_certifications (count_record_hash char(64), certified|superseded_by_audit)
  - app/Services/ActivationService.php:41-53 — step 3.5a constitutes the bootstrap board; app/Services/CertificationService.php:121 certify(), :317 certifyExecutive(), :569 certifyJudicial(), :788 certifyCountback() — seats winners + terms (CLK-10 lockstep), re-arms CLK-01, replacement terms inherit original expiry (:39-60,81-137)
  - app/Jobs/Elections/RunCountbackJob.php:14-24 — queued countback: countback_running → universal re-run → seat first eligible replacement OR countback_failed → CLK-04 backstop special election
  - tests/Constitutional/BoardTransitionTest.php (3), CountbackUniversalTest.php (5), tests/Feature/TabulationCertificationPipelineTest.php (full pipeline), tests/Feature/PhaseBHandlersTest.php (7 — the 11 election engine handlers; 'Phase B' in the internal build lettering = elections)
  NOTES: The whole loop — bootstrap board at activation, certification that auto-seats winners and arms the next cycle's clocks, vacancy countback with CLK-04 backstop — is present and test-pinned.

UNEXPECTED: ConstitutionalEngine carries a Phase G mirror write-guard baked into the Phase A pipeline (ConstitutionalEngine.php:108-120): a read-only mirror refuses EVERY filing and records the refusal on its own local chain — the A-phase entry point has quietly become the federation write-wall. | AuditService appends are serialized by a global pg advisory lock (0x4155444954 = 'AUDIT', AuditService.php:40,77) after a real observed fork on the live multi-worker stack — the docblock records the incident; chain forks are structurally impossible now, at the cost of a single global appender. | ChainReconciliationService implements a full constitutional acknowledgement protocol for chain breaks (blessed-break map consulted during verify walks) — a tamper-evident repair path more sophisticated than anything the phase docs claim for A. | BallotCrypto.php:27-29 openly documents that the {ballot_hash, salt} receipt is a vote-selling channel (receipt-freeness out of scope) and flags a cryptographer review as a production gate — an honest, currently-open security TODO on the elections engine. | Two app shells coexist: resources/js/Layouts/AppShell.vue AND AppShellV2.vue. | FormRegistry's CATALOG_DRIFT mechanism (8 stale Workflows Catalog IDs that collide with other canonical forms, surfaced for display but never auto-resolved — FormRegistry.php:211+) is pinned by AuditChainSmokeTest.php:141. | RoleService derives 31 role codes (R-00..R-30) — the full constitutional role surface through Phase E — in one request-cached service with zero stored grants; the census in this report is complete.
TESTS: tests/ = 202 PHP files: Constitutional/ 138, Feature/ 56, Concerns/ 4 (shared fixtures incl. CountedRaceFixture, LivePgConnection), Support/ 1 (SyntheticBallotGenerator), Unit/ 1, fixtures/ 0 .php. A–C coverage: Phase A — AuditAppendSerializationTest (3), AuditChainSmokeTest (17), ChainReconciliationTest (3), ActivationMathTest (8), SystemClocksAmendmentsTest (4), ElectionClockTest (6); Phase B — RightsAutomaticTest (incl. residency-forms guard), AuthPagesTest (4), RelocationVacancyTest; Phase C — StvDroopGregoryTest (11), RcvTest (6), CountbackUniversalTest (5), BallotSecrecyTest (9), BoardTransitionTest (3), TabulationCertificationPipelineTest (1 end-to-end pipeline), PhaseBHandlersTest (7), SupermajorityRcvTest, RaceStructureTest, VoteTypeRegistryTest, BallotRewrapFailsClosedTest.
CORRECTIONS: Form count is 108, not 109: PHASE_LEDGER_A_TO_O.md:87 says 'the shipped 109-form registry' (CLAUDE.md repeats 109 = '104 through Phase 5' + 5), but app/Domain/Forms/FormRegistry.php:8 states '108 total: the 103 Template forms + F-ELB-008 + F-SOC-001/002/003 + F-SOC-004', and a unique-key count of the FORMS array confirms exactly 108 canonical IDs (+6 pure aliases). Either the ledger/CLAUDE.md counted one form twice or a form claimed for Phase 5 was never registered — the code's own docblock and key count agree on 108. | Handler coverage nuance the ledger doesn't mention: only 106/108 canonical forms are handler-wired; F-LEG-020 (BoG Consent Vote) and F-LEG-021 (Judicial Nomination Consent Vote) are deliberately unregistered because the consent VOTE is cast through the chamber-vote machinery (FormRegistry.php:285,309,328,343). Intentional design, not rot — but 'all forms dispatchable' would be an overclaim. | Install-order coupling not recorded anywhere: the 21-clock registry does NOT ride inside database/schema/pgsql-schema.sql (COPY public.clocks is empty at :5117, contradicting the impression that all reference rows ride in the dump); it is seeded post-migrate by ClockRegistrySeeder via deploy.ps1:167 / deploy.sh:216. A bare 'php artisan migrate' install has an empty clocks table until the deploy script or seeder runs. | Module-prompt correction (not the ledger's error): vote_casts belongs to chamber/board voting (member_id XOR board_seat_id, lanes type_a/type_b — pgsql-schema.sql:4753), not to the citizen two-phase ballot flow; and audit_checkpoints is a Phase F federation artifact (written only by FederationSyncService::publishCheckpoint — app/Models/AuditCheckpoint.php:14), grouped under the Phase A audit family only by vocabulary.


===== [phases-DE] D-E (Legislature Ops + Executive & Organizations + Judiciary & Law) — BUILT =====
Phases D and E are genuinely built end-to-end, not hollow: every claimed subsystem has a substantive service (10,270 lines across the 23 core D/E services alone), baseline schema tables, Inertia Vue pages, routes, a dedicated constitutional test suite (PegQuorum, BicameralDualAgreement, Art4Section5, DoubleJeopardy, WorkerRepresentation, CgcIpPublicDomain, etc.), and self-verifying demo commands (institutions:demo-d, institutions:demo-e). A grep for TODO/stub/NotImplemented across app/Services found zero hollow bodies in D/E code — the only 'stub' is PetitionService's deliberately-retained Phase-C constitutional-review fallback that its own code retires whenever an operating court exists. The prior ledger's BUILT claims for these rows are confirmed accurate. Two multi_jurisdiction_votes kinds (cultural_institution, additional_articles) are reserved in the CHECK constraint but never opened as MJVs by any service — the only genuinely dormant corner found.

### [BUILT] Peg-quorum chamber votes
  - app/Services/ChamberVoteService.php:1149 lines; peg math at :713-725 (ConstitutionalValidator::quorum snapshotted at open), re-close against UNCHANGED peg at :653-659
  - pgsql-schema.sql:991 chamber_votes, :969 chamber_vote_tallies, :947 chamber_vote_proposals
  - tests/Constitutional/PegQuorumTest.php (8 tests)
  NOTES: Serving-member peg snapshotted at vote open; presence feeds only the quorum gate, required_yes is over serving. Ledger files this under Phase C (legislature ops); the task's D framing merges C into D.

### [BUILT] Bicameral dual agreement
  - app/Services/ChamberVoteService.php:737 lanePlan(), :778 dual agreement across ALL lanes (q-ledger #q7), :861-865 type_a/type_b lane split
  - tests/Constitutional/BicameralDualAgreementTest.php (7 tests)
  NOTES: One code path for unicameral, committee, and per-kind bicameral lanes; a vote adopts only when EVERY lane independently passes.

### [BUILT] Speaker election (RCV supermajority)
  - app/Services/Legislature/SpeakerService.php:312 lines — F-LEG-008 supermajority RCV, win condition = peg required_yes over serving, exhausted ballots stay in denominator
  - tests/Constitutional/SupermajorityRcvTest.php (7 tests) pins supermajorityRcvOutcome() DB-free against the live closeRcv()
  NOTES: Uses PROTECTED VoteCountingService::countRcv for rounds; repeat-balloting never auto-loops (failed re-election leaves incumbent seated).

### [BUILT] Committees (faction-independent assignment)
  - app/Services/Legislature/CommitteeAssignmentService.php:453 lines; app/Services/Legislature/CommitteeService.php
  - pgsql-schema.sql:1259 committees, :1236 committee_seats, :1206 committee_preferences, :1186 committee_meetings, :1221 committee_reports
  - tests/Constitutional/CommitteeAssignmentTest.php (10 tests)
  NOTES: Rank-order preference placement with normalized-quota tie-breaks per CLAUDE.md doctrine.

### [BUILT] Bills -> versioned laws
  - app/Services/BillService.php:409 lines; app/Services/EnactmentService.php:519 lines — sequential law number under pg_advisory_xact_lock, writes laws + law_versions v1 (:20), CLK-19 shield admits only SOURCE_JUDICIAL_REMEDY past it (:183)
  - pgsql-schema.sql:637 bills, :621 bill_versions, :2883 laws, :2865 law_versions
  - tests/Constitutional/SettingEnactmentTest.php (4 tests), tests/Constitutional/LawMergePreservesHistoryTest.php
  NOTES: Full version history preserved; Vue pages Legislature/Bills.vue + BillDetail.vue.

### [BUILT] Referendums
  - app/Services/ReferendumService.php:659 lines
  - pgsql-schema.sql:4101 referendum_questions
  - tests/Constitutional/ReferendumShieldTest.php (5 tests — CLK-19 population-supermajority shield)
  - resources/js/Pages/Legislature/Referendums.vue
  NOTES: Shield interplay with judicial remedy pinned in Art4Section5Test::test_remove_remedy_strikes_and_pierces_a_referendum_shield.

### [BUILT] Petitions
  - app/Services/PetitionService.php:628 lines; Phase-C review stub explicitly retired when a court operates (:479-496), real F-JDG-008 path at :514+
  - pgsql-schema.sql:3957 petitions, :3943 petition_signatures
  - tests/Constitutional/JudicialReservedHoldsTest.php:114 test_petition_review_struck_and_cleared_through_the_engine
  NOTES: Struck petition invalidates with NO referendum queued; cleared petition validates and queues the ballot. The retained stub is design (forming-court jurisdictions only), not hollowness.

### [BUILT] Emergency powers (CLK-03, 90-day ceiling)
  - app/Services/EmergencyPowerService.php:643 lines; CLK-03 armed non-re-derivable at :207-210; hardened min(90, resolved) ceiling at :91
  - pgsql-schema.sql:1891 emergency_powers, :1850 emergency_power_renewals, :1866 emergency_power_reviews
  - tests/Constitutional/EmergencyCeilingTest.php (9 tests — 91 days must breach, renewals re-capped)
  NOTES: Also consumed by UpgradeFreezeService.php:23 (a live CLK-03 window freezes peer upgrades) and DepartmentReportingController (emergency-enabled rules expire with the power).

### [BUILT] Executive delegation/conversion (dual supermajority)
  - app/Services/Executive/ExecutiveFormationService.php:866 lines — opens exec_office_create MJV at :316, exec_office_alter at :546, subject-effects in onProcessEvaluated :462-480; proposal path app/Services/Executive/ExecutiveActService.php:153
  - pgsql-schema.sql:2060 executives, :2002 executive_members, :3381 multi_jurisdiction_votes, :1285 constituent_consents
  - tests/Constitutional/ExecDelegationProportionalityTest.php (9 tests), ExecConversionDualSupermajorityTest.php (8 tests)
  NOTES: First live MultiJurisdictionVoteService consumer; committee (PR-STV) and individual (RCV + 4 advisors) types per Art. III.

### [BUILT] Departments + Boards of Governors (CLK-09 10-yr)
  - app/Services/Executive/DepartmentService.php:608 lines; app/Services/Executive/BoardGovernorService.php:588 lines; app/Http/Controllers/Executive/DepartmentController.php:311-312 (governor seats on CLK-09, worker seats CLK-10)
  - pgsql-schema.sql:1601 departments, :1575 department_rules, :1553 department_reports, :694 boards, :671 board_seats, :2315 governor_removal_requests
  - tests/Constitutional/TermLockstepTest.php (5 tests, CLK-09/CLK-10 lockstep), GovernorRemovalOrdinaryMajorityTest.php (5 tests)
  NOTES: TermSyncController.php:133-148 surfaces CLK-01/09/10 clocks; Vue pages Executive/Departments.vue + DepartmentDetail.vue + DepartmentReporting.vue.

### [BUILT] Executive orders w/ pre-issuance scope validation
  - app/Services/Executive/ExecutiveOrderService.php:320 lines — three preflight rules (enabling_instrument, scope_containment, hardened civic_process_protection) documented :15-38; rejection-on-record commits rejected_pre_issuance row + public_records + chain entry + 422
  - pgsql-schema.sql:2029 executive_orders
  - tests/Constitutional/OrderScopeValidationTest.php (5 tests)
  NOTES: Runs at validator stage OUTSIDE the engine transaction so the rejection artifacts survive the throw — the Phase D exit criterion.

### [BUILT] Organizations module (registry/membership/ownership/transfers/conversions)
  - app/Services/Organizations/: 12 services incl. OrgRegistryService, OrgMembershipService, OrgOwnershipService, OrgTransferService, OrgConversionService, OrgElectorateService
  - pgsql-schema.sql:3725 organizations, :3631 org_memberships, :3655 org_ownership_stakes, :3678 org_transfers, :3569 org_conversions, :3704 org_workers, :3542 org_contracts, :3598/:3612 org_document_packages
  - resources/js/Pages/Organizations/{Registry,OrgDetail,TransfersConversions}.vue
  NOTES: Universal entity model (political_party|business|nonprofit|common_good_corp|informal) per CLAUDE.md — no faction layer.

### [BUILT] Co-determination (CLK-13/14 hardened math)
  - app/Services/Organizations/CoDeterminationService.php:301 lines (PROTECTED) — linear scale f(min)=1 to f(par)=ownerSeats at :61-67, CLK-13/14 registry fires :194-258
  - app/Jobs/Organizations/RecomputeWorkerHeadcountJob.php + EvaluateCoDeterminationJob.php (CLK-13/14 fire handler, threshold-lowering watcher)
  - tests/Constitutional/WorkerRepresentationTest.php (8 tests incl. test_the_100th_worker_auto_triggers_the_first_seat_clk13_chain and single-implementation source pin)
  NOTES: Thresholds resolved amendable (never hardcoded 100/2000) — CoDeterminationController.php:293-311.

### [BUILT] Org board elections
  - app/Services/Organizations/OrgBoardElectionService.php:181 lines + OrgBoardSeatingService.php + OrgBoardService.php; app/Http/Controllers/Organizations/BoardElectionController.php:226-227 (CLK-14 sets worker seat count, CLK-13 first seat), :382-383 (owner seats CLK-09, worker seats CLK-10)
  - resources/js/Pages/Organizations/BoardElections.vue
  - tests/Constitutional/WorkerRepresentationTest.php:379 test_invalid_board_blocks_acts_and_chair_majority_is_of_all_seated; TermLockstepTest references board elections
  NOTES: Dual-track (owner-elected + worker-elected) board seating; chair election via ChamberVoteService reuse (WorkerRepresentationTest:407).

### [BUILT] CGC public-domain IP register
  - app/Services/Organizations/CgcIpRegisterService.php:100 lines — dedicate() is the SOLE write surface, no update/delete anywhere (source-scanned by the test), non-CGC rejected as ConstitutionalViolation Art. III S5
  - pgsql-schema.sql:910 cgc_ip_register
  - tests/Constitutional/CgcIpPublicDomainTest.php (6 tests)
  NOTES: Irreversible dedications; cgc_to_private conversion never touches the table (WF-ORG-09). CgcController.vue surface at Organizations/CgcDetail.vue.

### [BUILT] Appointed/elected courts + equal-per-constituent nomination
  - app/Services/Judiciary/JudiciaryFormationService.php:483 lines — F-LEG-017 creation, judgesPerConstituent invariant at :56-63, :188-190 ConstitutionalValidator::assertEqualConstituentNomination; conversion via judiciary_convert MJV at :333-387; app/Services/Judiciary/JudicialSeatService.php:76 (a constituent nominates only onto ITS OWN allocated seats), :324 equal-count re-check at appointed flip
  - pgsql-schema.sql:2641 judiciaries, :2616 judicial_seats, :2594 judicial_nominations
  - tests/Constitutional/JudiciaryCreationConversionTest.php (11 tests)
  NOTES: Both Art. IV S2 nomination paths (constituent equal-count and committee); conversion mirrors the executive F-LEG-015 pattern via the shared MJV substrate.

### [BUILT] Cases / panels / juries / advocates
  - app/Services/Judiciary/CaseService.php:382, PanelService.php:188 + PanelSizing.php, JuryService.php:218 (deterministicDraw with published seed), AdvocateService.php:116, CaseFilingService.php
  - pgsql-schema.sql:871 cases, :806 case_filings, :847 case_parties, :3831 panels, :3810 panel_judges, :2672 juries, :2826 jury_members, :358 advocates, :4729 verdicts, :4260 sentencing_orders
  - tests/Constitutional/CaseLifecycleTest.php (full criminal path file->accept->panel->jury(12+2, reproducible draw)->verdict->sentence through the engine), PanelSizingTest.php (5 tests)
  - resources/js/Pages/Judiciary/{CaseDocket,CaseDetail,AdvocateConsole,JurorView}.vue
  NOTES: Jury draw is a verifiable deterministic draw from a published seed (CaseLifecycleTest:165-173).

### [BUILT] Double jeopardy (Art. II S8)
  - app/Services/ConstitutionalValidator.php:1178 assertNoDoubleJeopardy (HARDENED, pre-commit bar at :1236-1278); app/Services/Judiciary/CaseService.php:279-295 sets cases.double_jeopardy_locked + verdict flag ATOMICALLY
  - tests/Constitutional/DoubleJeopardyTest.php (2 tests)
  - app/Console/Commands/PhaseEDemoCommand.php:524 self-verifies the lock in the demo
  NOTES: Criminal-only; bar runs at validator stage so the engine records the rejected=true chain row.

### [BUILT] Art. IV S5 three-path challenge + judicial_remedy law-editing
  - app/Services/Judiciary/ConstitutionalChallengeService.php:799 lines — full lifecycle filed->...->legislative_window_open with the three exits documented :26-31, CLK-11 armed to max(veto_closes_at, remedy_due_at); JudiciaryOverrideService.php (Path 2); JudicialRemedyService.php:177 lines — appends law_versions row source='judicial_remedy', the ONE source EnactmentService::amendLaw admits past a CLK-19 shield (EnactmentService.php:183)
  - pgsql-schema.sql:1301 constitutional_challenges, :1337 constitutional_findings, :4136 remedy_recommendations, :2197 finding_offending_laws, :3521 opinions, :3503 opinion_law_links
  - tests/Constitutional/Art4Section5Test.php — 8 tests covering ALL THREE paths: test_legislative_amendment_in_time_closes_the_challenge (Path 1), test_supermajority_override_leaves_the_law_unchanged + test_override_after_the_veto_window_is_rejected (Path 2), test_auto_remedy_edits_the_law_when_the_legislature_does_nothing + shield-piercing remove-remedy (Path 3)
  NOTES: THE Phase E exit criterion, fully clock-driven (JudicialAutoRemedyJob + LegislativeWindowLapsedJob wired, Art4Section5Test:119-124); version history preserved, prior versions never mutated.

### [BUILT] multi_jurisdiction_votes kind census (10 kinds in CHECK at pgsql-schema.sql:3400)
  - exec_office_create -> ExecutiveFormationService.php:316 (+ ExecutiveActService.php:153)
  - exec_office_alter -> ExecutiveFormationService.php:546
  - judiciary_convert -> JudiciaryFormationService.php:333 (+ JudiciaryActService.php:154)
  - setting_amendment -> Judiciary/SettingAmendmentDoorService.php:100 (Phase E dual-door; Art4Section5Test::test_dual_door_setting_rejects_door_one_alone)
  - union -> Jurisdictions/UnionService.php:70 (UnionDualSupermajorityTest)
  - disintermediation -> Jurisdictions/DisintermediationService.php:60, UNANIMITY basis (DisintermediationUnanimityTest)
  - local_autonomy -> Jurisdictions/LocalAutonomyService.php:75 (LocalAutonomyGovernedTest)
  - peer_upgrade -> PeerUpgradeAgreementService.php:255 (PeerUpgradeAgreementTest)
  - cultural_institution -> NO MJV opener anywhere; the name is a CHAMBER vote_type only (ChamberActService.php:185, single-chamber supermajority recognition via CulturalInstitutionService)
  - additional_articles -> NO MJV opener; exists only in the model KINDS list (app/Models/MultiJurisdictionVote.php:36) and as chamber vote_type config/constitution/vote_types.php:255
  NOTES: 8 of 10 kinds have live consumers. union/disintermediation/local_autonomy/peer_upgrade are later-phase (F/G) consumers riding the D-built MJV substrate — evidence the substrate generalized as designed. cultural_institution and additional_articles are reserved-unopened at the MJV level: the only dormant corner found in this audit.

UNEXPECTED: SettingAmendmentDoorService (app/Services/Judiciary/SettingAmendmentDoorService.php) — the Phase E dual-door setting-amendment MJV path is a real deliverable not explicitly named in the prior ledger's E row, tested by Art4Section5Test::test_dual_door_setting_rejects_door_one_alone. | The MJV substrate built in D has four later-phase consumers (union, disintermediation, local_autonomy, peer_upgrade in app/Services/Jurisdictions/ and PeerUpgradeAgreementService) — prior docs file these under F/G but they prove D's substrate generalized. | Two MJV kinds (cultural_institution, additional_articles) are permitted by the schema CHECK (pgsql-schema.sql:3400) and the model KINDS list but have NO opener service — reserved capacity, not dead code, since both names function as chamber vote_types (config/constitution/vote_types.php:255). | Extra E-adjacent substance beyond the charter list: misconduct_investigations (pgsql-schema.sql:3336), removal_proceedings (:4165), warrants (:4777), sentencing_orders (:4260), opinions + opinion_law_links (:3521/:3503), executive_investigations (:1980), and Legislature/OversightService.php + Oversight.vue — an oversight/accountability layer the ledger rows do not enumerate. | PetitionService retains an explicit, self-retiring Phase-C constitutional-review stub for forming-court jurisdictions (PetitionService.php:479-496) — intentional design with the retirement condition coded in, not an unfinished body.
TESTS: tests/Constitutional/ carries the D/E suite: PegQuorumTest (8), BicameralDualAgreementTest (7), SupermajorityRcvTest (7), CommitteeAssignmentTest (10), ChamberOpsRulesTest (10), SettingEnactmentTest (4), LawMergePreservesHistoryTest (1), ReferendumShieldTest (5), JudicialReservedHoldsTest (2, petitions), EmergencyCeilingTest (9), ExecDelegationProportionalityTest (9), ExecConversionDualSupermajorityTest (8), OrderScopeValidationTest (5), WorkerRepresentationTest (8), CgcIpPublicDomainTest (6), TermLockstepTest (5), GovernorRemovalOrdinaryMajorityTest (5), JudiciaryCreationConversionTest (11), CaseLifecycleTest (3), PanelSizingTest (5), DoubleJeopardyTest (2), Art4Section5Test (8, all three S5 paths), plus VoteTypeRegistryTest, UnionDualSupermajorityTest, DisintermediationUnanimityTest for the MJV substrate. Feature side: PhaseDPageSmokeTest, PhaseCChamberOpsHandlersTest, PhaseCGroupBControllersTest. Demo commands institutions:demo-d (PhaseDDemoCommand.php:107) and institutions:demo-e (PhaseEDemoCommand.php:103) are self-verifying (e.g. PhaseEDemoCommand.php:524 asserts the double-jeopardy lock).
CORRECTIONS: No corrections required to the D or E rows of docs/plans/docs-recon/PHASE_LEDGER_A_TO_O.md — every claim in row D (exec delegation/conversion, departments+BoG CLK-09, order scope validation, org module, co-determination CLK-13/14, board elections, CGC IP register) and row E (courts, equal-per-constituent nomination, cases/panels/juries/advocates, double jeopardy, three-path S5 challenge with judicial_remedy law-editing) verified against code, schema, and tests. | Mapping note only: this audit module's charter folds legislature operations (peg-quorum, bicameral, speaker, committees, bills->laws, referendums, petitions, emergency powers) into 'Phase D', while the ledger and CLAUDE.md file that work under Phase C / build 3. All of it is BUILT either way; no status discrepancy, just phase-label drift between documents. | The ledger's E row could additionally credit the dual-door setting-amendment path (SettingAmendmentDoorService + the setting_amendment MJV kind) and the oversight/misconduct/warrant/sentencing table set, which exist and are tested but go unmentioned.


===== [phases-FG] F–G (Federation substrate · Federated Adoption, Earned Autonomy, Social Mesh, G-ID) — BUILT =====
Phases F and G are genuinely built, end to end, exactly as the prior ledger claims. Every subsystem named in the charter (roadmap lines 102-146) resolves to a real service with a doc-blocked contract, a baseline table, routes, an operator console surface, and named constitutional tests: peer mesh, FF&C signed-tail sync, authority flip, the four Art. V/VI jurisdiction services, the whole Track A mirror ladder (cold-sync through the join wizard and deploy scripts), G-ID device/attestation identity, co-member clusters with Patroni-observed leadership, fail-closed ballot re-wrap, the sealed operational bundle, the governed autonomy flip, and the meter consent machinery. The Phase G merge 823e752 is an ancestor of main. The two parked rig gates are confirmed real and are the ONLY gaps: no Capacitor artifact exists anywhere (G-V1), and cross-machine peer join is code-complete but only same-box-proven (G-V2). Two attribution nuances: the "dual-meter" is in code a THREE-meter system (A operator board / B seated government / C co-affected peers) carried by peer_upgrade_proposals + peer_upgrade_consents over the Phase-C multi_jurisdiction_votes/constituent_consents substrate; and the oidc_* tables + fc_mas/fcd_mas container are Phase K-3 Matrix-auth machinery (matrix-authentication-service), not Phase G G-ID.

### [BUILT] F: Peer mesh (federation_peers / federation_transports / federation_transport_health)
  - database/schema/pgsql-schema.sql:2123,2157,2178 (three tables in baseline)
  - E:\fair-constitution-app\app\Services\Federation\PeerService.php, TransportService.php, TransportEndpoints.php:170 (tailnet kind)
  - Commands: FederationPeerHandshakeCommand, FederationPeerCheckCommand, FederationPeerDiscoverCommand, TransportRegisterCommand/TransportListCommand/TransportDisableCommand
  - tests/Constitutional/TransportSeamTest.php, PeerTransportLearningTest.php, FederationDiscoveryTest.php, FederationIdentityPersistenceTest.php; tests/Feature/FederationHandshakeTest.php
  NOTES: Instance identity + pinned-key peers + multi-transport (tailnet/Tor/sneakernet seam) with health rows and console commands.

### [BUILT] F: Full Faith & Credit sync (sync_cursors / sync_log) + CLK-20 heartbeat
  - app/Services/Federation/FederationSyncService.php:16-29 (signed audit tail; independent chain recompute; authoritative-instance-wins; append-only sync_log; ballots/locations/credentials never sync)
  - database/schema/pgsql-schema.sql:4510 sync_cursors, 4538 sync_log
  - app/Jobs/Federation/FederationHeartbeatJob.php:20 (CLK-20), config/cga.php:140
  - tests/Constitutional/SyncLogAppendOnlyTest.php, FederationChainIntegrityTest.php; tests/Feature/FederationRoundTripTest.php, FederationHeartbeatClockTest.php
  NOTES: forwarded_writes (schema:2212) is NOT part of this — its model doc (app/Models/ForwardedWrite.php:2-5) marks it Phase G G4 write-forwarding, exactly-once via (origin_server_id, idempotency_key).

### [BUILT] F: Authority flip (AuthorityFlipService + partition_exports)
  - app/Services/Federation/AuthorityFlipService.php:13-27 (exportFlip/importFlip/revert, two-phase across two DBs, audited)
  - database/schema/pgsql-schema.sql:3854 partition_exports
  - app/Console/Commands/FederationFlipExportCommand.php
  - tests/Constitutional/AuthorityFlipTest.php
  NOTES: Signed subtree manifest; authoritative_server_id flip; idempotent re-run; revert path for un-ACKed flips.

### [BUILT] F: Union / Disintermediation / Border settlement / Restoration services
  - app/Services/Jurisdictions/UnionService.php (F-LEG-029, Art. V §7 dual ratification), DisintermediationService.php (F-LEG-030, Art. V §8 unanimity + law incorporation via EnactmentService::amendLaw), BorderSettlementService.php (Art. V §2, affected-area denominator, writes new jurisdiction_maps version), RestorationService.php (Art. VI §2-3 court-confirmed three-tier cascade)
  - tests/Constitutional/UnionDualSupermajorityTest.php, DisintermediationUnanimityTest.php, BorderAffectedAreaDenominatorTest.php, RestorationJudicialReviewTest.php
  - app/Domain/Forms/Handlers/UnionFormationJoinVote.php; FormRegistry.php:392 (union/disintermediation open the constituent process)
  NOTES: All four ride the Phase-C MultiJurisdictionVote/constituent_consents substrate; jurisdiction_maps versioning (schema:2713) is the boundary-history side.

### [BUILT] F: Cluster + mirror seed/drain (foundation drain, sync progress)
  - app/Services/Federation/FoundationDrainService.php:36, FoundationServeService.php, GeodataSeedTransportService.php, SyncProgressService.php; app/Services/Mirror/MirrorBackfillService.php
  - routes/federation.php:49-58 (paginated foundation drain endpoints), :132-137 (geodata seed byte ranges)
  - tests/Feature/FoundationSeedTransportTest.php, MirrorSyncProgressTest.php, SetupJoinAsyncTest.php
  NOTES: Keyset-paginated geodata/corpus drain for joining mirrors, resumable, with per-table progress UI.

### [BUILT] F: i18n machinery bootstrap
  - resources/js/i18n/index.js:1-50 (vue-i18n, 5-locale chrome en/es/ar/zh-Hans/hi, per-namespace locales/<code>/<ns>.json loader, en-XA pseudo-locale QA hook)
  - resources/js/i18n/glossary/ dir; app/Http/Middleware/SetLocale.php
  - tests/Constitutional/TranslationPrivacyRailTest.php
  NOTES: Machinery only, as claimed — body copy is still literal English by design (index.js header); full extraction is Phase N. No Laravel lang/ dir; i18n is frontend-side.

### [BUILT] G Track A: mirror mesh (cold-sync → G1 mirror → G2 join-key → G3 request/vouch → G3b wizard → G0b deploy)
  - app/Services/Federation/ColdSyncService.php:1-13 (paged signed pulls, cross-page continuity, resumable SyncCursor); app/Services/Mirror/MirrorService.php, MirrorJoinKeyService.php, MirrorBackfillService.php
  - database/schema/pgsql-schema.sql:1069 cluster_adoption_requests, 1096 cluster_join_keys, 1117 cluster_members, 1135 cluster_memberships
  - routes/federation.php:71-73 POST /adopt (tofu-signed); routes/web.php:485-493 join-a-cluster wizard + sync-progress; deploy.sh + deploy.ps1 at repo root
  - tests/Constitutional/ColdSyncChunkingTest.php, ClusterAdoptionTest.php, ClusterRequestVouchTest.php, ClusterJoinSurfaceTest.php, MirrorJoinFlowTest.php, MirrorJoinKeyServiceTest.php, MirrorIsAuthoritativeForNothingTest.php
  - CLI ladder: ClusterJoinCommand, ClusterRequestAdoptionCommand, ClusterApproveCommand/RejectCommand, ClusterKeysMint/List/RevokeCommand, FederationColdSyncCommand, FederationResumeJoinCommand
  NOTES: Prong 1 complete: permissionless read-only mirror, authoritative for nothing (pinned by MirrorIsAuthoritativeForNothingTest).

### [BUILT] G-ID: actor identity + standing attestations + AttestationGate
  - app/Services/Identity/ActorIdentityService.php (Ed25519 device key enrollment, no escrow), AttestationService.php, AttestationGate.php (implements ResolvesRoles; local users always live-derive, attested path only for verified forwarded writes), AttestedActorContext.php
  - database/schema/pgsql-schema.sql:324 actor_devices, 4472 standing_attestations (subject_user_id, device_public_key, issuer_server_id, roles jsonb, signature, expires_at), 450 attestation_revocations
  - HTTP surface: routes/web.php:795-796 (POST /actor/devices, /actor/attestations → app/Http/Controllers/Identity/ActorIdentityController.php)
  - tests/Constitutional/ActorEnrollmentTest.php, ActorIdentitySurfaceTest.php, AttestationIntegrityTest.php, AttestationRevocationFederationTest.php, AttestedForwardingTest.php; tests/Feature/DeviceSigningInteropTest.php
  NOTES: The person-level signing layer the charter calls G-ID core + keystone + HTTP surface. Distinct from OIDC/MAS (see that item).

### [BUILT] G: co-member clusters + write routing + Patroni HA
  - app/Services/Cluster/ClusterMembershipService.php (sole epoch-fenced writer of leader_server_id/leader_epoch; authority≠leadership grep pin), LeaderProbe.php (pg_is_in_recovery + timeline_id — 'Patroni decides, PHP observes')
  - app/Services/Federation/WriteRouterService.php (G4 — forwarded writes execute through the NORMAL ConstitutionalEngine pipeline); app/Models/ForwardedWrite.php:2-5; schema:2212 forwarded_writes
  - docker/patroni/Dockerfile + entrypoint.sh; docs/plans/institutions/PHASE_G_PATRONI_HA_RUNBOOK.md
  - tests/Constitutional/ClusterAuthoritySeparationTest.php, AttestedForwardingTest.php, MeshGateServiceTest.php
  NOTES: Leadership (Patroni-observed) kept orthogonal to authority (authoritative_server_id) and pinned by test.

### [BUILT] G: fail-closed ballot re-wrap + sealed operational bundle + autonomy vote
  - app/Services/Federation/BallotKeyRewrapService.php (re-wraps only the 32-byte per-election k_e; FAIL-CLOSED gate reproduces every certified record_hash before the new key stands), OperationalBundleService.php (libsodium sealed box, point-to-point, never in the sync tail)
  - app/Services/Jurisdictions/LocalAutonomyService.php (G6 dual ratification: promoting population supermajority + parent local_autonomy MJV), AutonomyFlipResult.php; schema:3107 local_autonomy_processes
  - tests/Constitutional/BallotRewrapFailsClosedTest.php, AutonomyFlipRewrapsKeysTest.php, LocalAutonomyGovernedTest.php
  NOTES: Prong 2 core: authority earned by population, granted by the current authoritative government, privacy boundary held.

### [BUILT] G: dual-meter consent — WHICH TABLES: peer_upgrade_proposals + peer_upgrade_consents (meter enum) over multi_jurisdiction_votes + constituent_consents
  - app/Models/PeerUpgradeConsent.php:13-23 (meter = 'operator' Meter A | 'seated' Meter B via MJV | 'peer' Meter C); schema:3913 peer_upgrade_proposals, 3890 peer_upgrade_consents
  - app/Services/PeerUpgradeAgreementService.php:484,510,526 (meterAPassed/meterBPassed/meterCPassed); app/Models/MultiJurisdictionVote.php:44 (Meter B leg); schema:1285 constituent_consents
  - Capability grants ride the same flow: app/Models/InstanceCapability.php:14-31, app/Services/Federation/CapabilityService.php:32, CapabilityProber.php:21-33 (per-capability Meter-C declaration), app/Http/Controllers/Operator/MeshRolesController.php:117-145
  - Console UI: app/Http/Controllers/Operator/MeshConsoleController.php:204-260 + resources/js/Pages/Operator/Console.vue, Versioning.vue, Roles.vue
  - tests/Constitutional/PeerUpgradeAgreementTest.php, UpgradeFreezeTest.php, MeshRoleGrantTest.php, MeshRoleBoardTest.php, MeshNamedRoleTest.php; tests/Feature/UpgradeConsentDeliveryTest.php; app/Console/Commands/FederationUpgradeConsentCommand.php (delivers Meter C)
  NOTES: In code it is a THREE-meter system (A operator board / B seated government / C co-affected peers); 'dual-meter' is the colloquial name for the A/B consent leg. constituent_consents + multi_jurisdiction_votes are Phase-C substrate (MultiJurisdictionVoteService doc-block: 'C-5 wiring') reused as Meter B.

### [BUILT] G-VER: constitutional_version tracking + upgrade freeze
  - app/Services/ConstitutionalVersionService.php (DERIVED hash of the hardened-compute surface, CRLF→LF-normalized for cross-platform determinism; a vote-math change cannot ship as a mere app_release)
  - instance_settings.constitutional_version + version_pinned_at columns (pgsql-schema.sql:2392 CREATE TABLE instance_settings, cols at ~2421); config/cga.php
  - app/Services/UpgradeFreezeService.php; resources/js/Pages/Operator/Versioning.vue
  - tests/Constitutional/ConstitutionalVersionTest.php, UpgradeFreezeTest.php, MeshDoctorTest.php
  NOTES: Two instances with equal constitutional_version provably count identically; a differing value demands the Meters A/B/C agreement.

### [BUILT] G Track C: transport seam + directory + broker/cert layer
  - app/Services/Federation/TransportService.php:11-49 (G9 directory publication), TransportEndpoints.php, DirectoryService.php; app/Console/Commands/DirectoryPublishCommand.php
  - Broker/cert: InMeshBrokerService.php, BrokerAuthorizationService.php, BrokerCredentialService.php, BrokerFailoverService.php, CertClientService.php, CertGrantService.php, CertGrantStore.php; commands MeshCertGrantCommand, MeshRequestCertCommand, MeshBrokerFailoverCommand, MeshReachCommand, MeshGatesCommand, MeshDoctorCommand
  - tests/Constitutional/DirectoryAdvisoryTest.php, TransportSeamTest.php, TransportConsoleCommandsTest.php, BrokerAuthorizationTest.php, BrokerCredentialTest.php, BrokerFailoverTest.php, CertBrokerLoopTest.php
  NOTES: Directory is advisory-only (pinned); the broker/cert loop is an in-mesh credential brokerage the prior ledger never itemized.

### [BUILT] OIDC provider + MAS containers (oidc_authorization_codes / oidc_signing_keys, fc_mas/fcd_mas)
  - app/Services/Oidc/OidcProviderService.php header: 'Phase 5 / K-3 (K3-C.2/.3) — the GAME OIDC provider… Serves exactly ONE relying party: MAS' (authorization_code + PKCE S256 mandatory, RS256, pseudonym-only claims); OidcKeyService.php
  - database/schema/pgsql-schema.sql:3409 oidc_authorization_codes, 3429 oidc_signing_keys; routes/oidc.php
  - docker-compose.yml:245-247: mas = ghcr.io/element-hq/matrix-authentication-service, container ${CONTAINER_PREFIX:-fc}_mas (→ fc_mas on game box, fcd_mas on dev)
  - tests/Constitutional/OidcProviderTest.php
  NOTES: ANSWER: fc_mas/fcd_mas IS matrix-authentication-service (MSC3861), fronting Synapse; CGA is its upstream OIDC. This whole stack is Phase K-3 Matrix-auth machinery, NOT Phase G G-ID — do not count oidc_* toward G. G's 'peer-SSO cohesion' is the attestation layer (standing_attestations), a separate system.

### [ABSENT] Parked gate G-V1: native Capacitor mobile + on-device GPS
  - grep 'capacitor' package.json + package-lock.json = 0 matches; no capacitor.config.*, no android/ or ios/ dirs at repo root
  - Roadmap docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md:129-136 declares it parked (build with the device in the loop, never blind)
  - The unchanged target pipeline exists: location_pings → ancestor sweep → residency_confirmations (schema + app/Models/LocationPing.php)
  NOTES: Correctly parked, correctly ABSENT — absence here is the declared state, not a ledger error.

### [PARTIAL] Parked gate G-V2: real cross-machine peer onboarding
  - Code path fully present: deploy.sh + deploy.ps1 at repo root; app/Console/Commands/FederationResumeJoinCommand.php; routes/web.php:69-76 zero-foreknowledge JOIN discovery; FederationDiscoveryService.php
  - Same-box simulation proven: tests/Feature/SetupJoinAsyncTest.php, FederationResumeJoinCommandTest.php, FederationRoundTripTest.php
  - Roadmap:137-140 — real multi-machine proof needs ≥2 lab boxes + firewall + DNS
  NOTES: PARTIAL only in the verification sense the roadmap itself states: code complete, real-network realization pending the rig. No third G gap exists — every other charter checkmark resolved to code + tests.

UNEXPECTED: An entire in-mesh broker/cert subsystem (InMeshBrokerService, BrokerAuthorization/Credential/FailoverService, CertClient/CertGrantService, CertGrantStore, MeshCertGrant/MeshRequestCert/MeshBrokerFailover commands, 4+ constitutional tests) that neither the charter checkmarks nor the prior ledger F/G rows itemize. | A mesh named-roles/channels-of-trust layer riding the meter machinery: mesh_operator_identities/mesh_operator_keys/mesh_operator_local_links tables (schema:3254-3288), MeshRoleOrchestrator/MeshRoleGrantService, MeshRoleCommand, Operator Roles console (resources/js/Pages/Operator/Roles.vue), MeshNamedRoleTest/MeshRoleBoardTest. | Zero-foreknowledge federation auto-discovery + /.well-known/cga-federation descriptor (routes/web.php:59-76, WellKnownFederationController, FederationDiscoveryService) — a JOIN screen that FINDS a federation with no foreknowledge. | Social-mesh federation of testimony and achievements already crosses instances (TestimonyFederationTest, AchievementFederationTest) — K-1 content riding the F sync tail. | A translation privacy rail already exists (app/Services/Matrix/Translation + TranslationPrivacyRailTest) — Phase-N-adjacent machinery live today. | UpgradeFreezeService: a freeze that blocks engine writes while a constitutional bump is unagreed — the enforcement teeth behind G-VER, not named in the ledger row.
TESTS: tests/Constitutional holds 139 files; ~47 cover F/G directly (AuthorityFlipTest, SyncLogAppendOnlyTest, FederationChainIntegrityTest, UnionDualSupermajorityTest, DisintermediationUnanimityTest, BorderAffectedAreaDenominatorTest, RestorationJudicialReviewTest, ClusterAdoptionTest, ClusterRequestVouchTest, ClusterJoinSurfaceTest, ClusterAuthoritySeparationTest, MirrorIsAuthoritativeForNothingTest, MirrorJoinFlowTest, MirrorJoinKeyServiceTest, ColdSyncChunkingTest, ActorEnrollmentTest, ActorIdentitySurfaceTest, AttestationIntegrityTest, AttestationRevocationFederationTest, AttestedForwardingTest, BallotRewrapFailsClosedTest, AutonomyFlipRewrapsKeysTest, LocalAutonomyGovernedTest, PeerUpgradeAgreementTest, UpgradeFreezeTest, ConstitutionalVersionTest, MeshDoctorTest, MeshGateServiceTest, MeshNamedRoleTest, MeshRoleBoardTest, MeshRoleGrantTest, DirectoryAdvisoryTest, TransportSeamTest, PeerTransportLearningTest, TransportConsoleCommandsTest, Broker*/CertBrokerLoop 4 files, FederationDiscovery/IdentityPersistence, OidcProviderTest, TranslationPrivacyRailTest, MatrixFederationWhitelistTest, ModerationFlipTest, TestimonyFederationTest, AchievementFederationTest). tests/Feature holds 58 files, ~19 F/G-related (FederationRoundTripTest, FederationHandshakeTest, FederationHeartbeatClockTest, FederationResumeJoinCommandTest, FederationMeshConsoleTest, FederationHostConsoleActionsTest, FederationConsolePropsTest, FederationPageSmokeTest, FederationPeerNeedleLookupTest, FoundationSeedTransportTest, MirrorReadWriteRequestTest, MirrorSyncProgressTest, SetupJoinAsyncTest, DeviceSigningInteropTest, UpgradeConsentDeliveryTest, MeshOperatorServiceTest, MatrixCommonsClientTest, MatrixDemoCommandTest). Suite not executed in this audit (read-only pass; dev stack not up) — the ledger's '322 pins, 0 skips @ 823e752' is consistent with this census and 823e752 is a confirmed ancestor of main.
CORRECTIONS: No status corrections: PHASE_LEDGER_A_TO_O.md rows F and G are accurate as written — every claimed subsystem verified BUILT with code+table+test evidence, and 823e752 confirmed on main. | Attribution nuance (also for the module prompt): forwarded_writes is Phase G G4 write-routing (app/Models/ForwardedWrite.php:2-5), not part of Phase F's FF&C sync; F's sync tables are sync_cursors + sync_log only. | Vocabulary: the 'dual-meter consent' is implemented as THREE meters (A operator board / B seated government via MultiJurisdictionVote / C co-affected peers) in peer_upgrade_proposals + peer_upgrade_consents (meter enum, PeerUpgradeConsent.php:13-23); and constituent_consents/multi_jurisdiction_votes are Phase-C substrate ('C-5 wiring' per MultiJurisdictionVoteService doc-block) reused by F unions and G Meter B — not new G tables. | Classification: oidc_authorization_codes/oidc_signing_keys + the fc_mas/fcd_mas container are Phase K-3 machinery (OidcProviderService header: 'Phase 5 / K-3'; MAS = ghcr.io/element-hq/matrix-authentication-service, docker-compose.yml:245-247) — they should never be counted as Phase G G-ID deliverables. | MEMORY.md index line 'only G-V1 phone GPS parked' understates: the roadmap (lines 123-140) parks TWO gates, G-V1 (mobile) and G-V2 (real cross-machine onboarding); the codebase audit confirms both, and confirms they are the only G gaps.


===== [phases-HK] H (Districting completion) + K-1 (Civic Record Plane) + K-3 (Matrix mesh commons) — MOSTLY_BUILT =====
All three phases are genuinely built and the prior ledger's BUILT verdicts hold. Phase H's entire delivery machinery is in the repo and pinned (seat-budget cascade, exactness law, autoscale pull engine, LeafGiantResolver line-splits, TypeBSeatLadder, F-ELB-008 manual draw with live blade tooling), with lane 1 still landing residue fixes through 2026-07-24; however three charter H line-items (F-ELB-007 as a registered form, F-ELB-009 + the exemplar/calibration tables, the DistrictingMethod interface + recalibration loop) never shipped — they were superseded by the fixed scoring doctrine and the F-ELB-008 line-split path, so H is converged-by-different-means, not charter-literal. K-1 is end-to-end BUILT: 9 social tables, F-SOC-001..003 with the Art. I carve-out shape hardened in ConstitutionalValidator, no removal route, per-user block on social_memberships, testimony to the append-only public record, scheduled auto-bind reconciler, and Vue pages routed. K-3 is BUILT: 5 matrix tables with carve-out enums in CHECK constraints, appservice bridge with registration.yaml, Synapse+MAS+LiveKit in docker-compose, OIDC IdP, voice token services, and the M-5 physical-law layer off the constitutional plane — with two soft edges: F-SOC-004/M-5 has no operator-facing HTTP or console entry point (service + 15 tests only), and the M-S MediaAdmissionGate is container-bound but consumed by nothing in production code.

### [BUILT] H: DistrictingService seat-budget cascade + exactness law
  - app/Services/DistrictingService.php:135 (computeSeatBudget), :273 (runAutoCompositeForScope), :562 (BUDGET EXACTNESS seat_drift step 0), :2930 (scoreRank key 1 = seat_drift)
  - tests/Feature/DistrictingDoctrineTest.php (28 test methods; doctrine pins incl. lumpy-scope, uneven-integer-targets, fine-tuning-never-buys-breaks)
  - git ec7f536 'Step 11b: the exactness rule on the composite plane'
  NOTES: The nearest-round/no-total-forcing law is implemented and pinned; the exactness rule was extended to the composite plane on 2026-07-24 (lane 1 still active).

### [BUILT] H: district_subdivisions schema + polymorphic membership
  - database/schema/pgsql-schema.sql:1663 (district_subdivisions: geom MultiPolygon, method splitline|manual|composite_synthetic, status draft/active/archived)
  - pgsql-schema.sql:2945-2946 (subdivision_id + ldj_member_kind_xor_check exactly-one-of)
  - pgsql-schema.sql:9557 (ldj_district_subdivision_unique partial index)
  NOTES: Matches the charter's key-schema spec exactly, including the XOR CHECK.

### [BUILT] H: PopulationRaster substrate + population probe
  - pgsql-schema.sql:226 (CREATE FUNCTION population_within_multi, cross-border)
  - database/migrations/2026_07_21_000001_antimeridian_guard_population_within_multi.php
  - app/Services/Districting/PopulationRaster.php (incl. splitByBlade pixel-grid fast path)
  - routes/web.php:301 (POST /api/legislatures/{id}/population-probe → SubdivisionDrawController::probe)
  NOTES: The charter's 'missing PHP entry point' is closed; the probe endpoint is live and used by the drag-readout in the draw UI.

### [BUILT] H: Autoscale pull engine (planetary generation machinery)
  - migrations 2026_07_18_000001 (autoscale_runs, autoscale_items), 2026_07_19_000001 (autoscale_scopes, autoscale_worker_leases), 2026_07_19_000002/000003, 2026_07_22_000002 (worker lane)
  - app/Services/Autoscale/{AdjacencyPrecompute,SinglesBatchProcessor,SweepScopeProcessor}.php
  - commands: autoscale:pump (AutoscalePumpCommand.php:31), districting:autoscale, autoscale:resize-repair {--dry-run}, autoscale:revert {--keep-singles} {--resume} {--force}, apportionment:seed
  - tests/Constitutional/AutoscalePinTest.php (3), HeavyLaneClaimTest, MixedAutoseedSweepTest (7)
  NOTES: This is the engine that ran run 6. The '951,626 legislatures / Earth 1,999 EXACT / 282 districts' convergence numbers are operational game-box state, not verifiable from this repo — repo evidence is the machinery plus the ledger/memory attestation.

### [BUILT] H: LeafGiantResolver (childless-giant line-split, no clamp stubs)
  - app/Services/Districting/LeafGiantResolver.php:30 (context/commit/planWithFallback/retireDrawnDistricts)
  - tests/Constitutional/LineSplitLadderTest.php (5)
  - app/Domain/Forms/Handlers/ManualDistrictDraw.php:37 (replaces the clamped_pending_subdivision_capability stub)
  - app/Services/InitialDistrictMapService.php:113,178 (clampUnassignedLeafGiants retained as bootstrap fallback only)
  NOTES: Giants split themselves via the root-leaf branch filed as F-ELB-008; the clamp code path still exists for freshly bootstrapped single maps outside autoscale — 'no clamp stubs remaining' is a claim about the generated planetary data, not code removal.

### [BUILT] H: TypeBSeatLadder
  - app/Services/Legislature/TypeBSeatLadder.php:26
  - tests/Constitutional/TypeBLadderTest.php (8 tests)
  NOTES: Type B chamber sizing bound by Type A is implemented and pinned; per memory ~9,708 scopes flagged for deferred Type B districting remain operational residue, not missing code.

### [BUILT] H: F-ELB-008 Manual District Draw (form + UI)
  - app/Domain/Forms/FormRegistry.php:93 (registered, R-08) and :245 (handler binding)
  - app/Domain/Forms/Handlers/ManualDistrictDraw.php
  - tests/Constitutional/ManualDistrictDrawTest.php (20 tests)
  - resources/js/Pages/Legislature/Districts.vue:638 ('Manual draw' selector reveals draw tools)
  - app/Http/Controllers/Legislature/SubdivisionDrawController.php:40-525 (probe/draw/splitProbe/splitCommit/autoseedPreview/autoseedCommit/remainder/splitBalance)
  NOTES: Complete end-to-end: form, handler, constitutional pins, and a live blade-drag UI with in-band seat readout.

### [ABSENT] H: F-ELB-007 Splitline as a registered form
  - app/Domain/Forms/FormRegistry.php:86-93 (F-ELB family jumps 006 → 008; no F-ELB-007 entry)
  - app/Services/Districting/Splitline.php:18 (seat-math statics only; header says the geometry generator 'lands in H3 behind the DistrictingMethod interface')
  - SubdivisionDrawController.php:143/197 (splitProbe/splitCommit blade endpoints deliver the capability manually)
  NOTES: The CAPABILITY (splitting a giant into in-band districts) is fully delivered — automated via LeafGiantResolver and manually via the blade tools, both filed under F-ELB-008. The dedicated F-ELB-007 form was superseded, not forgotten.

### [ABSENT] H: F-ELB-009 Calibration Adoption + districting_exemplars/districting_calibrations + recalibration loop + DistrictingMethod interface
  - zero matches for 'districting_exemplars' or 'districting_calibrations' in database/schema/pgsql-schema.sql and database/migrations/
  - no F-ELB-009 in FormRegistry.php
  - 'DistrictingMethod' appears only as a comment in Splitline.php:11
  NOTES: The charter's grid→Bayesian SOFT-parameter recalibration loop was replaced by the operator-settled fixed scoring doctrine (rounds 10-12 arc, scoreRank + seat_drift first key) pinned in DistrictingDoctrineTest. Superseded by ruling, but charter-literal these deliverables do not exist.

### [BUILT] K-1: Social schema (spaces/subforums/threads/posts/reactions/profiles/follows/memberships)
  - pgsql-schema.sql:4327 (social_follows), :4343 (social_memberships), :4360 (social_posts w/ acting_seat CHECK), :4379 (social_profiles), :4397 (social_reactions), :4413 (social_spaces, space_type CHECK public_square|halls|group), :4435 (social_subforums w/ governing_object_type/id), :4453 (social_threads w/ published_record_id)
  - tests/Constitutional/SocialSchemaTest.php (4)
  NOTES: All nine charter tables present in the baseline with the halls-binding columns.

### [BUILT] K-1: F-SOC-001..003 forms + handlers + routes + UI
  - FormRegistry.php:183-185 (F-SOC-001 NO role gate — open square; F-SOC-002 R-03; F-SOC-003 R-19/R-20) and :403-405 (handler bindings)
  - app/Domain/Forms/Handlers/{SocialThreadPost,SocialTestimonyFiling,SocialRemoval}.php
  - routes/web.php:802-806 (civic.square, civic.halls, halls.testimony)
  - resources/js/Pages/Civic/{PublicSquare,Halls}.vue
  - tests: PublicSquareTest (3), SquarePostingTest (4), HallsTestimonyTest (5)
  NOTES: Testimony publishes immutably via PublicRecordService::publish (SocialTestimonyFiling.php:19) — the K-1 exit criterion's append-only record path works.

### [BUILT] K-1: Art. I uncensorable carve-out enforcement
  - app/Services/ConstitutionalValidator.php:864 (checkSocialRemoval — closed enum judicial_order|rights_protection + mandatory justifying reference; viewpoint removal structurally unrepresentable)
  - routes/web.php:800 ('There is NO removal route — the square is uncensorable')
  - app/Models/SocialMembership.php:13 (block_user_id = M-3 per-user block, never a removal)
  - tests/Constitutional/SocialModerationCarveoutsTest.php (4)
  NOTES: The four-carve-out architecture is real: M-1/M-2 via F-SOC-003 (validator-hardened), M-3 as a private membership row / client-side ignore, M-4 anti-spam handled on the Matrix plane (CarveoutEmitterService m4_antispam + matrix_server_acls carve-out CHECK).

### [BUILT] K-1: auto-bind reconciler + scheduled evaluation
  - app/Services/Social/SubforumReconciler.php:22 (idempotent, archive-never-delete)
  - app/Jobs/EvaluateSocialStructureJob.php scheduled at routes/console.php:73 (dailyAt 00:30)
  - tests/Constitutional/SubforumReconcilerTest.php (3)
  - app/Console/Commands/SocialDemoCommand.php (standing demo)
  NOTES: Subforums auto-bind to bills/referendums/petitions/committees/candidacies per the charter.

### [BUILT] K-1: local-only never-federate rule (follows/reactions/memberships)
  - app/Services/Federation/FederationSyncService.php:44-47 (FF&C tail = audit entries + public records + achievements ONLY; 'What NEVER syncs' docblock)
  - tests/Constitutional/TestimonyFederationTest.php (2 — testimony DOES federate as a public record)
  NOTES: Holds by construction: the three local-only tables are not public records and never enter the FF&C payload. Note they ARE included in cold-sync mirror exports (MapDataExportService DERIVE_DENYLIST at :111 does not list them) — correct for same-instance mirrors, worth knowing.

### [BUILT] K-3: Matrix schema (rooms/identities/event_snapshots/server_acls/carveout_log)
  - pgsql-schema.sql:3152 (matrix_carveout_log, carve_out CHECK m1_judicial|m2_rights|m4_antispam|m5_legal), :3176 (matrix_event_snapshots), :3194 (matrix_identities), :3210 (matrix_rooms, 12 entity_types + room_type + space_type CHECKs), :3237 (matrix_server_acls, written_by_carve_out CHECK m1|m4 only)
  - tests/Constitutional/MatrixSchemaTest.php (5)
  NOTES: Carve-out vocabulary is baked into DB CHECK constraints — a viewpoint ACL is unrepresentable at the schema layer.

### [BUILT] K-3: appservice bridge + homeserver stack (Synapse/MAS/LiveKit)
  - routes/matrix.php:10-15 (PUT /_matrix/app/v1/transactions, GET users/rooms → AppServiceController)
  - docker/matrix/appservice/registration.yaml + docker/matrix/conf.d/{10-cga,20-mas,30-appservice}.yaml + docker/matrix/mas/config.yaml
  - docker-compose.yml:207 (synapse), :246 (matrix-authentication-service), :266 (livekit-server)
  - matrix:setup command (MatrixSetupCommand.php:22)
  - tests/Feature/Matrix/AppServiceAuthTest.php (4), tests/Constitutional/MatrixSetupTest.php, MatrixTestimonyBridgeTest (4), RoomCreationTest (3)
  NOTES: K-1↔K-3 bridge is real: TestimonyBridgeService + SocialTopologyReconcilerService mirror the civic plane into Matrix rooms; OIDC IdP routes (routes/oidc.php:21-24, OidcProviderTest) mean the operator never authenticates to Matrix directly.

### [BUILT] K-3: voice (LiveKit tokens, reach, traveling)
  - app/Services/Matrix/{LiveKitTokenService,VoiceReachService,TravelingVoiceTokenService}.php
  - routes/web.php:844 (POST /matrix/voice-reach, mixed-environment resolution)
  - app/Http/Controllers/Matrix/{CallTokenController,PrivateCallTokenController,VoiceReachController}.php
  - docker/livekit/livekit.yaml + docs/operator/livekit.md
  - tests: VoiceReachTest (3), TravelingVoiceTokenTest (4), CallTokenResidencyTest (4)
  NOTES: Room-scoped, short-lived, pseudonymous, appservice-signed tokens per the design; degrades to no-voice when the server is down.

### [PARTIAL] K-3: illegal-content layer OFF the constitutional plane (M-5 + M-S)
  - app/Services/Matrix/LegalComplianceService.php:27 (operator-plane orchestrator; closed basis enum csam_hashmatch|court_order_specific|true_threat; purge reserved to CSAM)
  - FormRegistry.php:188,407 (F-SOC-004, zero role codes = operator plane) + Handlers/LegalComplianceRemoval.php
  - pgsql-schema.sql:2915 (legal_compliance_removals w/ basis + action + physical_removal_status CHECKs)
  - ConstitutionalValidator.php ~:887 (checkLegalComplianceRemoval, six structural guards)
  - tests: LegalComplianceTest (15), LegalComplianceSchemaTest (4)
  - GAP: no controller/command references LegalComplianceService (grep across app/Http, app/Console = zero hits)
  - GAP: MediaAdmissionGate bound at app/Providers/AppServiceProvider.php:20 but zero production consumers (only tests)
  NOTES: The engine/service/schema layer is complete and heavily tested, but the operator has no HTTP or artisan surface to actually invoke an M-5 removal, and the M-S proactive hash gate sits in front of nothing yet — two wiring gaps, not design gaps.

### [BUILT] K-3: moderation flip + carve-out emitter + federation whitelist
  - app/Services/Matrix/ModerationFlipService.php:30,72 (M-3 never an appservice removal — structural refusal)
  - app/Services/Matrix/CarveoutEmitterService.php:33 (judicial_order→m1_judicial mapping; M-3 explicitly has no path)
  - app/Services/Matrix/MatrixFederationGateService.php:25-27 (whitelist = trusted mesh peers; scale_demo forces empty)
  - tests: ModerationFlipTest (4), MatrixCarveoutEmitterTest (6), MatrixFederationWhitelistTest (2)
  NOTES: Federation of the commons is gated to exactly the peers that mirror public records, and the demo instance is structurally barred from federating.

### [BUILT] K-3: embedded live commons UI
  - routes/web.php:812-815 (commons.square/halls/post/testimony)
  - resources/js/Pages/Civic/MatrixCommons.vue + app/Http/Controllers/Civic/MatrixCommonsController.php
  - tests/Feature/MatrixCommonsClientTest.php (2), MatrixClientReadSeamTest (2), MatrixDemoCommandTest + matrix:demo {--fresh} {--offline}
  NOTES: Reads degrade to empty when the homeserver is down; posting stays open per Art. I.

UNEXPECTED: The K-2 'achievements' deliverable is partially pre-built: an append-only achievements table ships in the baseline (pgsql-schema.sql:306) with a mutation-blocking trigger (achievements_block_mutation, pgsql-schema.sql:122), an Achievement model, JourneyService as the awarder, AND achievements already federate in the FF&C tail (FederationSyncService.php:128-155, AchievementFederationTest 3 tests). Prior ledger's 'K-2 UNBUILT' row does not mention this substrate. | Phase-N-vocabulary translation rails already live inside K-3: app/Services/Matrix/Translation, app/Http/Controllers/Matrix/MatrixTranslationController.php, TranslationPrivacyRailTest (4 tests). | F-SOC-001 carries NO role gate at all (FormRegistry.php:180-183) — square/halls ACCESS is open to any player; residency gates only the testimony SEAL (F-SOC-002). Stronger-than-charter openness, per operator correction 2026-06-27. | The F-ELB-007 splitline capability exists as live operator tooling: SubdivisionDrawController splitProbe/splitCommit (routes via the Districts.vue blade drag with real-time in-band seat readout off the cached pixel grid) — the form ID was never registered but the tool is arguably better than the charter's version. | The M-5 layer is framed in code as a generalized 'physical hand-brake on reality' explicitly anticipating the Phase-M market economy (LegalComplianceService.php header) — a forward hook prior docs don't record. | app/Services/Matrix/Scan/MediaAdmissionGate.php (M-S proactive hash-list admission filter) exists with a swappable MediaScanProvider seam and container binding (AppServiceProvider.php:20) but no ingestion consumer — a dormant, test-only defense layer.
TESTS: Phase H: tests/Feature/DistrictingDoctrineTest.php (28 methods) + constitutional suites ManualDistrictDrawTest (20), SubdivisionAutoseedTest (22), TypeBLadderTest (8), MixedAutoseedSweepTest (7), SubdivisionSeatMathTest (6), SubdivisionCellSeedTest (6), LineSplitLadderTest (5), AutoscalePinTest (3), plus ~10 measurement/robustness pins (CutPathMeasurementTest, FragmentAbsorptionTest, FragmentAccountingTest, GridInsideScopeTest, RemainderSynthesisTest, TwoSplitFallbackTest, FullCrossingBladeTest, ConservationMeasurementTest, CoverageGateRobustnessTest, BorderAffectedAreaDenominatorTest, HeavyLaneClaimTest). K-1: 10 suites ≈ 46 methods — PublicSquareTest (3), SquarePostingTest (4), HallsTestimonyTest (5), SocialSchemaTest (4), SocialModerationCarveoutsTest (4), SubforumReconcilerTest (3), PrivateRoomTest (4), TestimonyFederationTest (2), LegalComplianceTest (15), LegalComplianceSchemaTest (4). K-3: 15 suites ≈ 48 methods — MatrixSchemaTest (5), MatrixSetupTest (1), MatrixCarveoutEmitterTest (6), MatrixClientReadSeamTest (2), MatrixFederationWhitelistTest (2), MatrixTestimonyBridgeTest (4), ModerationFlipTest (4), RoomCreationTest (3), TravelingVoiceTokenTest (4), VoiceReachTest (3), CallTokenResidencyTest (4), TranslationPrivacyRailTest (4), Feature/Matrix/AppServiceAuthTest (4), MatrixCommonsClientTest (2), MatrixDemoCommandTest.
CORRECTIONS: K-1 row says 'F-CHR/F-SOC forms live' — wrong family: F-CHR-001..004 are the Phase-C Committee Chair forms (FormRegistry.php:144-148, R-12 roles; legacy aliases F-COM-*→F-CHR-*). The civic-record-plane forms are F-SOC-001..003 only (F-SOC-004 belongs to K-3's operator plane). | H row says 'F-ELB-007/008 live' — only F-ELB-008 is a registered form (FormRegistry.php:93,245). F-ELB-007 was never registered; the splitline exists as seat-math statics (app/Services/Districting/Splitline.php) plus manual blade endpoints, and childless-giant splits file under F-ELB-008 via LeafGiantResolver. | H row omits that three charter deliverables were superseded and never shipped: F-ELB-009 Calibration Adoption, the districting_exemplars/districting_calibrations tables, and the DistrictingMethod interface + grid→Bayesian recalibration loop (zero traces in schema/migrations/registry; replaced by the settled fixed-scoring doctrine). The as-built docx should record the supersession, not imply charter-literal completion. | H row's 'no clamp stubs remaining' should be read as a data claim about the generated planetary map: clampUnassignedLeafGiants still exists as the bootstrap fallback for freshly generated single maps (app/Services/InitialDistrictMapService.php:113,178, audited note 'clamped_pending_subdivision_capability'). | K-3 '✅ BUILT' is correct for the mesh/bridge/voice/carve-out planes, but overstates M-5 operational readiness: LegalComplianceService and F-SOC-004 have no HTTP controller or artisan command anywhere (service + 19 tests only), and the M-S media admission gate is bound but consumed by nothing — the illegal-content layer is invocable today only from code/tinker.


===== [runtime] Runtime & container state (both stacks: game box fc_* / dev fcd_*) — BUILT =====
Both stacks are fully alive — 21 containers, zero unhealthy or restarting, and each stack runs 10 services (not the 7 CLAUDE.md lists): the Matrix plane (Synapse + Matrix Authentication Service) and a dedicated scheduler container are live on BOTH boxes. The two databases are schema-identical (193 tables, identical 13-row post-baseline migration history, all districting/autoscale/geodata), but their data diverges completely: the game box carries the accepted planet (≈951,626 jurisdictions, ≈955,130 legislatures, ≈1,963,037 districts, map accepted + apportionment completed 2026-07-19, setup wizard parked at step 3 in solo/sandbox mode) while the dev box is a virgin install (setup step 0, users=0, audit_log=35). Critically for the phase audit: every later-phase-vocabulary table the orchestrator asked about — achievements, journey_progress, social_spaces, matrix_rooms, approval_standings, support_reports, grant_applications, policy_proposals, cultural_institutions — EXISTS in the baseline DDL on both boxes but holds ZERO rows on both; they are shipped substrate, not exercised features. The scheduler and Horizon topology are real, running machinery spanning Phases 0–K (clocks, autoscale pump, ESM-04 approval rollup, Phase D sweeps, G-ID attestation pruning, K-1 social-structure sweep).

### [BUILT] Container fleet — game box (fc_*)
  - docker ps -a: fc_app (fair-constitution-app-app, Up 15h healthy, :9000 internal)
  - fc_nginx (nginx:1.27-alpine, Up 3d, 0.0.0.0:8080->80)
  - fc_postgres (postgis/postgis:17-3.5, Up 3d healthy, 0.0.0.0:5432->5432)
  - fc_redis (redis:7.4-alpine, Up 45h)
  - fc_vite (Up 3d healthy, :5173)
  - fc_horizon (Up 7h)
  - fc_scheduler (Up 2d)
  - fc_etl (Up 3d)
  - fc_matrix (ghcr.io/element-hq/synapse:latest, Up 3d healthy, 0.0.0.0:8008->8008)
  - fc_mas (ghcr.io/element-hq/matrix-authentication-service:latest, Up 4h, 0.0.0.0:8090->8080)
  NOTES: 10 services, all Up, none unhealthy/restarting. fc_mas (4h), fc_horizon (7h), fc_app (15h), fc_redis (45h) restarted more recently than the 3-day base — normal churn, all currently stable. Synapse + MAS = Phase K-3 Matrix infrastructure DEPLOYED, not just planned.

### [BUILT] Container fleet — dev box (fcd_*)
  - docker ps -a: fcd_app (fcd-app, Up 3d healthy), fcd_nginx (0.0.0.0:8082->80), fcd_postgres (postgis/postgis:17-3.5, 0.0.0.0:5434->5432, healthy)
  - fcd_redis, fcd_vite (0.0.0.0:5175->5173, healthy), fcd_horizon, fcd_scheduler, fcd_etl (all Up 3d)
  - fcd_matrix (synapse:latest, Up 3d healthy, 0.0.0.0:8010->8008)
  - fcd_mas (matrix-authentication-service:latest, Up 24h, 0.0.0.0:8092->8080)
  NOTES: Mirror topology of the game box on offset ports (8082/5434/5175/8010/8092), matching the COMPOSE_PROJECT_NAME=fcd two-stack rule. All Up, none unhealthy.

### [BUILT] Dev DB (fcd_postgres) schema + migration state
  - 193 base tables in information_schema (public schema)
  - migrations table: exactly 13 rows, batches 1-13, ids 197-211: 2026_07_05_000001_setup_wizard_v2 ... 2026_07_22_000003_add_redraw_requested_at_to_autoscale_items
  - database/schema/pgsql-schema.sql contains 182 CREATE TABLE statements; post-baseline migrations add 10 tables (geodata_flags, geodata_repairs @ 2026_07_08_000001_geodata_repair_plane.php:40,74; autoscale_runs, autoscale_items @ 2026_07_18_000001:34,60; autoscale_scopes, autoscale_worker_leases @ 2026_07_19_000001:77,104; jurisdiction_adjacency, jurisdiction_adjacency_parents, jurisdiction_centroids, jurisdiction_simplified @ 2026_07_19_000001:118-156); 182+10+migrations = 193
  NOTES: Every post-baseline migration is districting/autoscale/geodata/setup-wizard — confirms no later-phase (H-O) schema work has landed as migrations; anything phase-shaped in the DB came from the baseline dump itself.

### [SUBSTRATE] Dev DB data state
  - pg_class reltuples/relpages: jurisdictions 0/0, legislatures 0/0, legislature_districts 0/0, elections 0/0, plus all 13 other audited tables at 0 relpages
  - SELECT count(*) FROM users = 0 (4 stale relpages — rows existed, then deleted: grand-reset residue)
  - SELECT count(*) FROM audit_log = 35 (genesis + setup entries)
  NOTES: Virgin install. instance_settings: instance_name='Unnamed Instance', setup_step_completed=0, setup_completed_at NULL, map_mode=physical_earth, time_mode=real, federation_enabled=f, signing keypair minted 2026-07-05 (server_id 8f4120dc-0fd5-4937-a01b-75fb75e98c7f). Full column list (33 cols) proves shipped machinery: federation/mirror (mirror_of_server_id, mirror_adopted_at, home_cluster_id, attestation_authority_enabled), game modes (setup_mode, game_mode, time_scale_seconds_per_year), ops overlay (infra_overrides), version plane (constitutional_version, app_release, version_pinned_at), geodata_posture.

### [BUILT] Game box DB (fc_postgres) data state
  - reltuples estimates: jurisdictions ≈951,626 (207,369 pages), legislatures ≈955,130 (19,550 pages), legislature_districts ≈1,963,037 (50,432 pages)
  - migrations table identical to dev: 13 post-baseline rows, batches 1-13, same filenames; 193 tables
  - instance_settings: instance_name='United Earth', setup_step_completed=3, map_accepted_at=2026-07-19 00:45:02+00, apportionment_completed_at=2026-07-19 03:06:51+00, setup_mode=solo, game_mode=sandbox, setup_completed_at NULL, setup_districts_confirmed_at NULL, federation_enabled=f
  - EXISTS probe: users=t (has rows)
  NOTES: The accepted-Earth planet-scale dataset is live and matches memory (951,626 jurisdictions). Setup wizard is parked at step 3 — apportionment done, districts not yet confirmed, setup not closed out. jurisdictions estimate exactly matches the 951,626 figure in project memory.

### [SUBSTRATE] Later-phase-vocabulary tables (achievements, journey_progress, social_spaces, matrix_rooms, approval_standings, support_reports, grant_applications, policy_proposals, cultural_institutions, jurisdiction_activations)
  - ALL ten exist in the BASELINE dump: pgsql-schema.sql:306 (achievements), :419 (approval_standings), :1517 (cultural_institutions), :2335 (grant_applications), :2579 (journey_progress), :2694 (jurisdiction_activations), :3210 (matrix_rooms), :3991 (policy_proposals), :4413 (social_spaces), :4492 (support_reports)
  - Game box one-row EXISTS probes: all ten = false (zero rows), same for laws, elections, organizations
  - Dev box: all at 0 relpages (empty)
  NOTES: The DDL shipped in the 2026-07-05 flattened baseline — meaning these were built during Phases A-K development, NOT by any post-baseline phase work. Zero rows on both stacks: no runtime exercise of achievements/journey/grants/policy/cultural machinery anywhere. approval_standings and social_spaces/matrix_rooms differ from the rest: they have LIVE scheduler machinery feeding them (see scheduler item) and are empty only because no elections/civic activity has run since the reset.

### [BUILT] Artisan command inventory (fcd_app, php artisan list --raw)
  - autoscale: pump, resize-repair, revert (3)
  - apportionment:seed (1); districting:autoscale, districts:backfill-stats (2)
  - geodata: repairs-apply, repairs-export, scan, synthesize-remainders (4); geojson:prewarm, rasters:prewarm
  - federation: cold-sync, demo, flip:export, geodata:seed-publish, init, peer:check, peer:discover, peer:handshake, request-read-write, resume-join, sync:push, upgrade:consent (12)
  - cluster: approve, join, keys:list, keys:mint, keys:revoke, leave, reject, request-adoption, requests (9)
  - matrix: demo, setup (2); mesh: broker-failover, cert-grant, doctor, gates, reach, request-cert, role (7); transport: disable, list, register (3)
  - demos: elections:demo, institutions:demo-d, institutions:demo-e, federation:demo, matrix:demo, social:demo (6)
  - audit: reconcile, verify (2); singles: jurisdiction:activate, vacancy:declare, directory:publish
  NOTES: ~60 app-specific commands. NO setup: namespace exists (setup wizard is UI/route-driven, not CLI). No commands matching education/treasury/achievement/journey vocabulary — consistent with FormRegistry having no EDU/TRE/ACT families. The federation(12)+cluster(9)+mesh(7)+transport(3)+matrix(2) block = 33 commands of Phase F/G/K-3 federation machinery, all registered and callable.

### [BUILT] Scheduler entries (routes/console.php)
  - routes/console.php:26 — EvaluateClocksJob everyMinute (WI-6 constitutional clocks, CLK-05/06)
  - routes/console.php:39-40 — autoscale:pump everyMinute withoutOverlapping(10) runInBackground (pull-engine liveness root)
  - routes/console.php:46 — ApprovalStandingsRollupJob dailyAt 00:10 (WI-B3/ESM-04 — feeds approval_standings)
  - routes/console.php:51 — Executive\DepartmentReportSweepJob dailyAt 00:20 (Phase D-5)
  - routes/console.php:58 — Organizations\EvaluateCoDeterminationJob dailyAt 00:25 (Phase D-O4, CLK-13/14)
  - routes/console.php:63 — Identity\ExpireStandingAttestationsJob hourly (Phase G-ID)
  - routes/console.php:73 — EvaluateSocialStructureJob dailyAt 00:30 (Phase K-1 civic-structure sweep, provisions public_square + halls + Matrix topology)
  - routes/console.php:76 — horizon:snapshot everyFiveMinutes
  NOTES: 8 entries spanning Phases 0, D, G, K-1 and the autoscale campaign; all onOneServer()+withoutOverlapping (HA-ready posture documented in comments at lines 20-25). Executed by the dedicated fc_scheduler/fcd_scheduler containers (schedule:work) — both Up.

### [BUILT] Horizon queue topology (config/horizon.php)
  - config/horizon.php:200-205 — supervisor-1: connection redis, queue ['default'], balance auto
  - config/horizon.php:226-230 — supervisor-long-running: connection redis-long, queue ['long-running'], balance simple, maxProcesses 1
  - config/horizon.php:245-249 — supervisor-autoscale: connection redis-long, queue ['autoscale'], maxProcesses = App\Support\HostCapacity::autoscaleWorkers()
  - config/horizon.php:264-268 — supervisor-prewarm: connection redis-long, queue ['prewarm'], maxProcesses 1
  - config/horizon.php:278-308 — production env: supervisor-1 maxProcesses 10; local: 3
  NOTES: Four supervisors over two Redis connections (redis / redis-long), matching the memory-documented queue split; autoscale worker count is host-capacity-derived (cores-2 law) via HostCapacity::autoscaleWorkers(), not hardcoded.

UNEXPECTED: Matrix plane is RUNNING infrastructure on BOTH stacks: fc_matrix/fcd_matrix (element-hq/synapse:latest, healthy, ports 8008/8010) AND fc_mas/fcd_mas (matrix-authentication-service, ports 8090/8092). Any phase audit treating K-3 Matrix as paper-only is wrong at the infrastructure layer — though matrix_rooms has zero rows on both boxes, so no bridged rooms exist yet. | All ten later-phase-vocabulary tables (achievements, journey_progress, social_spaces, matrix_rooms, approval_standings, support_reports, grant_applications, policy_proposals, cultural_institutions, jurisdiction_activations) ship inside the 2026-07-05 BASELINE dump (pgsql-schema.sql lines 306-4492) — none came from post-baseline migrations. Whoever audits Phases H-O must check what BASELINE-era feature created each table rather than assuming they're future-phase scaffolding. | approval_standings is not passive substrate: a live daily scheduler entry (routes/console.php:46, ApprovalStandingsRollupJob/ESM-04 at 00:10) actively maintains it — the table is empty only because no approvals exist post-reset. | Each stack runs a dedicated scheduler container (fc_scheduler/fcd_scheduler running schedule:work) and the MAS containers — none of which appear in CLAUDE.md's 7-row Docker Services table; the runtime fleet is 10 services per stack. | Game box setup wizard is parked at step 3: map_accepted_at and apportionment_completed_at are stamped 2026-07-19 but setup_completed_at and setup_districts_confirmed_at are NULL — 'United Earth', solo/sandbox mode, federation disabled. The planet exists; the founding is unfinished. | Dev box users table has 4 stale relpages but count(*)=0 — users were created and hard-deleted (grand-reset residue), while its audit_log retains 35 rows. | Both stacks are on IDENTICAL migration heads (13 post-baseline files through 2026_07_22_000003, batches 1-13) — no schema drift between game box and dev despite divergent data.
TESTS: Not applicable to this module (read-only runtime census; no test suite executed). All evidence gathered via docker ps, SELECT-only psql on both boxes (reltuples/relpages estimates + one-row EXISTS probes only — no COUNT(*) on large tables; the two COUNT(*) calls were on dev tables proven ≤4 pages), php artisan list --raw on fcd_app, and reads of routes/console.php + config/horizon.php. No repo file was modified.
CORRECTIONS: PHASE_LEDGER_A_TO_O.md:30 marks K-2 (Civic education + achievements) as UNBUILT — correct for the deliverable, but incomplete: the `achievements` (pgsql-schema.sql:306) and `journey_progress` (pgsql-schema.sql:2579) tables already exist in the baseline DDL on both live databases (zero rows). K-2's true status is SUBSTRATE-at-the-schema-layer, not zero-trace; the DDL predates the ledger and shipped with the 2026-07-05 flatten. | Any ledger reading that treats Matrix/K-3 as undeployed is contradicted by runtime: Synapse + Matrix Authentication Service containers are Up (healthy) on both stacks (fc_matrix/fc_mas, fcd_matrix/fcd_mas), matrix:setup and matrix:demo artisan commands are registered, and the K-1 EvaluateSocialStructureJob (routes/console.php:73) already provisions Matrix topology best-effort daily. What is genuinely absent is DATA: matrix_rooms and social_spaces are empty on both boxes. | CLAUDE.md's '183 tables' baseline figure is slightly off at runtime: the dump contains 182 CREATE TABLE statements and both live DBs hold 193 base tables (182 baseline + 10 post-baseline autoscale/geodata tables + Laravel's migrations table).