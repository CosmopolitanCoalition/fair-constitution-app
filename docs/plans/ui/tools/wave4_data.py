# -*- coding: utf-8 -*-
"""The Desk work list (operator order 2026-09-05): Waves W6..W11 in order; Waves 1-5 history."""

FLEET = {'waves': [{'id': 'W1', 'name': 'Shell · demo · learn', 'status': 'done'},
           {'id': 'W2', 'name': '~22 screens · parity UIs · forms→113', 'status': 'done'},
           {'id': 'W3',
            'name': 'Type B mapper · keystone · K-2 · economy · coordinator · tour · forms→117',
            'status': 'done'},
           {'id': 'W4',
            'name': 'To GREEN: Type B race fix · screen+capability closes · debt paydown',
            'status': 'done'},
           {'id': 'W5',
            'name': 'The finish: 107/107 screens · courts reframe · re-gate ruled away · '
                    '2026-09-05 map runs done',
            'status': 'done'},
           {'id': 'W6', 'name': 'Step 4 engine and page', 'status': 'done'},
           {'id': 'W7', 'name': 'Step 5 simulation at planet scale', 'status': 'next'},
           {'id': 'W8', 'name': 'Step 6 and the public read-only world', 'status': 'next'},
           {'id': 'W9', 'name': 'Fresh cloud build and the demo mesh', 'status': 'next'},
           {'id': 'W10', 'name': 'Demo polish', 'status': 'next'},
           {'id': 'W11',
            'name': 'Live mesh: beta.worldofstatecraft.org readiness',
            'status': 'next'}],
 'lanes': [{'id': 'W6',
            'name': 'Step 4 engine and page',
            'status': 'done',
            'items': [{'wave': 'W6',
                       'label': '1 · Wizard ladder 0 to 6 and Step 3 Continue',
                       'status': 'done',
                       'note': 'DONE 2026-09-06. App\\Support\\SetupLadder '
                               '(applies/next/reachable/completed/describe); '
                               'SetupController::index/step route on it; pages Step4_ScaleUp, '
                               'Step5_Simulate (placeholder until W7), Step6_Confirm; the stepper '
                               'renders the server ladder; Step 3 Continue posts step3/complete '
                               '(gated on a done map run or an active root map). Pinned: '
                               'SetupLadderTest.'},
                      {'wave': 'W6',
                       'label': '2 · Step 4 engine: per-legislature ledger, pump, lanes on the '
                                'long lane',
                       'status': 'done',
                       'note': 'DONE 2026-09-06. provision_runs / provision_ledger / '
                               'provision_worker_leases; ProvisionClaims (shell batches first, '
                               'then units; topdown largest-first, bottomup smallest-first); '
                               'ProvisionWorkerJob (autoscale queue); provision:pump (halt/resume, '
                               'chunked seeding, ANALYZE while shells land, reclaim on backend '
                               'absence, lane seeding, done flip with baseline); '
                               'ProvisionRunControl start/halt/resume/revert(--shells); '
                               'provision:control CLI; Step 4 page with bars, lanes, review, '
                               'measured ETA. The map done flip and Step 6 dispatch nothing now. '
                               'Dry run (rolled back): ledger 25k rows 1.8 s; shell batch 5,000 '
                               'rows 1.4 to 3.2 s; unit 0.5 to 1.1 s.'},
                      {'wave': 'W6',
                       'label': '3 · Shell steps as lane work + the money plane founding',
                       'status': 'done',
                       'note': 'DONE 2026-09-06. The six shell statements run per claimed batch '
                               '(InstitutionProvisionService::provisionClaim, ledger join, no '
                               'bound lists) plus the treasuries step; FoundingTreasuryService '
                               'founds the currency (root settings row) and the root treasury at '
                               'ledger seeding.'},
                      {'wave': 'W6',
                       'label': '4 · THE BENCH LAW, one quorum formula, one seat floor',
                       'status': 'done',
                       'note': 'DONE 2026-09-06. BenchLaw (PHP + SQL) on the provisioning insert, '
                               'the stub service and the sim stage; QuorumLaw on every writer of '
                               'quorum_required; floor from the own settings row → root → 5. '
                               'Pinned: BenchLawTest, mirror parity test.'},
                      {'wave': 'W6',
                       'label': '5 · Seat lane: one election and its races per legislature',
                       'status': 'done',
                       'note': 'DONE 2026-09-06. LegislatureUnitProcessor::seat calls '
                               'scheduleGeneral after the board landed; zero-seat chambers '
                               'skipped; fully blocked plans file as review without an orphan row. '
                               'Zero bin fold + residue guard + inhabited-only completeness count '
                               'in the districting engine. Unique index + insertOrIgnore in the '
                               'seed command; the two flukes deleted with their maps.'},
                      {'wave': 'W6',
                       'label': '6 · Committees and departments as system acts',
                       'status': 'done',
                       'note': 'DONE 2026-09-06. System-act path on F-LEG-009 / F-LEG-016 (null '
                               'actor + system_act): CommitteeService::createAsSystemAct, '
                               'DepartmentService::charterAsSystemAct with '
                               'EnactmentService::enactFounding (origin founding); a forming '
                               'executive may oversee at founding; both refuse on a seated '
                               'chamber. Filled to K(S) and D(P), mandatory kinds first.'},
                      {'wave': 'W6',
                       'label': '7 · Rooms and subforums for the sculpted world',
                       'status': 'done',
                       'note': 'DONE 2026-09-06 (decided in build). Square + halls in the shell '
                               'batch; subforums follow live objects (none at Step 4); Matrix '
                               'rooms stay demand-driven on first view. Settings keys registered '
                               '(register, meta, bounds, model).'},
                      {'wave': 'W6',
                       'label': '8 · Pins for the Step 4 engine',
                       'status': 'done',
                       'note': 'DONE 2026-09-06. SetupLadderTest (routing, skips, reachability), '
                               'BenchLawTest (bench, next odd, constituent multiple, quorum), '
                               'ProvisionEnginePinTest (two-ended claims, skipped rows, reclaim + '
                               'park, live lease kept; live PG), mirror parity rewritten for the '
                               'bench law, InstitutionScaleTest updated, '
                               'SetupWindowOperatorGateTest covers the new endpoints. '
                               'Halt/resume/rollback and the wizard endpoints are exercised by the '
                               'W6 test step on box E.'},
                      {'wave': 'W6',
                       'label': '9 · TEST: iterative Step 4 runs on box E to the full planet, then '
                                'lock',
                       'status': 'done',
                       'note': 'DONE 2026-09-06. Ran iteratively at full planet scale on box E '
                               'from /setup/step/4, halt/resume/rollback exercised repeatedly '
                               '(incl. one operator-halted deploy, one hard box restart the run '
                               'auto-resumed from). Performance campaign: set-based departments '
                               '(198 -> ~40 ms) and committees (36 -> 11 ms), seat double-racePlan '
                               'removal + SettingsResolver prefetch, windowed founded/h rate + '
                               'accurate ETA (was cumulative, read 3x low), pid-reuse-proof '
                               'dead-lane reaper (self-heals a PostgreSQL restart mid-run), and an '
                               'independent CGA_PROVISION_WORKERS dial on its own provision queue. '
                               'Unit ~365 -> ~183 ms; throughput ~34 -> ~74/s. Lane sweep proved '
                               'Step 4 disk-bound (IO/DataFileRead): 13/16/26 lanes all landed '
                               '~54-61/s, so autoscaleWorkers() (~13) stays the default and the '
                               'dial waits for a faster-disk box. Run climbing to completion '
                               "(720k+/923,092); Lock and Continue is the operator's final click. "
                               'Box E one-off remaining: redraw the nine zero-seat maps drawn '
                               'before the fix.'}]},
           {'id': 'W7',
            'name': 'Step 5 simulation at planet scale',
            'status': 'next',
            'items': [{'wave': 'W7',
                       'label': '1 · Sim worker heartbeat during an item',
                       'status': 'done',
                       'note': 'DONE (3d82a372, repaired 5d6ef08c). A $beat closure is threaded '
                               'from SimWorkerJob into all 8 stages and their inner loops (the '
                               'first cut placed the call in helper scopes that never received it '
                               'and threw at runtime; fixed in Cohort, Civics, Governance, Seating '
                               'and ElectionStage). ORIGINAL: Item and lease rows are touched only '
                               'at claim and settle (app/Support/SimClaims.php:121-123; '
                               'app/Jobs/SimWorkerJob.php:124-160). An item over 30 min is '
                               'reclaimed and re-executed; over 2 min the pump seeds a replacement '
                               'each minute; over 10 min the lease is culled '
                               '(SimPumpCommand.php:110-113, 148-160, 402-414). Copy the '
                               "districting lane's heartbeat connection "
                               '(app/Support/HostCapacity.php:57).'},
                      {'wave': 'W7',
                       'label': '2 · Resume without OFFSET; stored options; one run selection',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): Still unbuilt. SimStartCommand.php:251 '
                               'and 271 still paginate with LIMIT+OFFSET. The --resume path reads '
                               'command-line adm-max/limit, not stored run options. '
                               'SimRunControl::activeRun() orders oldest-first while --resume '
                               'orders newest-first. Evidence: '
                               'app/Console/Commands/SimStartCommand.php:251,271 still use LIMIT ? '
                               'OFFSET ? in both the root-scope and roster-scope INSERT paths. On '
                               '--resume (line 84), command-line adm-max/limit are used rather '
                               'than stored run.options. SimRunControl::activeRun() '
                               "(SimRunControl.php) uses orderBy('created_at') while "
                               "SimStartCommand --resume uses orderByDesc('created_at'), creating "
                               'a disagreement on which run is active. | sim:start --resume stops '
                               'at the first empty chunk and re-enumerates with the command-line '
                               'adm-max/limit (app/Console/Commands/SimStartCommand.php:56-59, '
                               '112-113, 179-239). Use NOT EXISTS + LIMIT with no OFFSET like the '
                               "pump's mintWorklist; read the run's stored options; make --resume, "
                               'the pump and SimRunControl agree on which run is active '
                               '(SimRunControl.php:83-89 vs SimStartCommand.php:81-83).'},
                      {'wave': 'W7',
                       'label': '3 · The three empty phases and sim:revert',
                       'status': 'done',
                       'note': 'DONE (725fe608, repaired 5d6ef08c; sim:revert 21df4ba4). '
                               'enumerating and verifying carry no kind (advancePhase advances '
                               'through them); profiling keeps profile_research, the live network '
                               'kind with its longer reclaim grace and its pin (the first cut '
                               'emptied it too and broke SimPullEnginePinTest). sim:revert ships '
                               'the SAFE subset: clears the run worklist + leases in bounded '
                               'chunks so a fresh run re-enumerates, leaves the produced world in '
                               'place, refuses a live run unless forced. The full population '
                               'teardown (~40 FK cascade) is filed as rubric sim-revert-scope. '
                               'ORIGINAL: enumerating, profiling and verifying are declared in '
                               'SimRun::PHASES with no stage and no mint '
                               '(app/Models/SimRun.php:40-88; SimPumpCommand.php:329, 422-460). '
                               'Implement a verifying acceptance scan or remove the phases. Build '
                               "sim:revert: remove one run's rows (cohorts, sim users and "
                               'residencies, candidacies, tabulations, seated members, governance '
                               'and judiciary acts, civics rows) so a run can repeat; it is named '
                               'in four files and absent.'},
                      {'wave': 'W7',
                       'label': '4 · Gate on the locked Step 4 result; adopt the Step 4 election',
                       'status': 'done',
                       'note': "DONE (5d6ef08c). ElectionStage fast-path adopts Step 4's open "
                               'election with races and skips racePlan (the apportionment walk '
                               'scheduleGeneral ran even on adoption, ~900k times); candidacy '
                               'insert is insert-or-ignore so a reclaimed item re-runs clean; the '
                               'fallback hands its plan to scheduleGeneral so racePlan runs once. '
                               'step5Start is gated on setup_step_completed >= 5 AND the Step 4 '
                               'run done. Pinned: one-election-per-chamber, no double-field. '
                               'ORIGINAL: ElectionStage settles done with no election when a '
                               'jurisdiction has no active board '
                               '(app/Services/Demo/Stages/ElectionStage.php:96-109) and calls '
                               'scheduleGeneral itself (:113). After W6 the elections exist: '
                               'ElectionStage adopts the open election and fields candidates; '
                               'JudiciaryStage needs the forming court (JudiciaryStage.php:83-89); '
                               "the counting mint takes only this run's election "
                               '(SimPumpCommand.php:213-233). Certification flips chambers forming '
                               "→ active (CertificationService.php:1003-1020); the sim's "
                               'governance stage delegates executives (serving > 5) and its '
                               'judiciary stage carries courts forming → operating: re-verify both '
                               'at planet scale in this wave.'},
                      {'wave': 'W7',
                       'label': '5 · Claim order per row',
                       'status': 'done',
                       'note': 'DONE (17d67180). position = $total + row_number() OVER (population '
                               'DESC, id) in both enumerate branches, so largest-first holds '
                               'across the whole worklist, not just per 25,000-row band. ORIGINAL: '
                               'position = the chunk offset, so largest-first holds only per '
                               '25,000-row band and a smoke run claims in uuid order '
                               '(SimClaims.php:120-136; SimStartCommand.php:189-207). Write a '
                               'per-row position.'},
                      {'wave': 'W7',
                       'label': '6 · Step 5 page',
                       'status': 'done',
                       'note': 'DONE (21df4ba4). Step5_Simulate.vue in the Step 4 chrome: header '
                               'tiles (items / done / windowed rate / elapsed+ETA / lanes), '
                               'overall stage bars, segmented per-layer bars, a produced-world '
                               'summary, a lane strip grouped by kind with warn colours, a review '
                               'drilldown, and Start / Halt / Resume / Roll back / Lock. '
                               'SetupController step5 endpoints delegate to SimRunControl and '
                               'SimSnapshot (the single owner shared with /simworld, world() '
                               'cached 10s so an open page never taxes the run). --jurisdiction '
                               'scope pass-through and the console cache remain for a later pass. '
                               'ORIGINAL: Rendered only when the world is synthetic-safe. Simulate '
                               'data? yes / no. Options: turnout, adm-max, limit, scope (pass '
                               '--jurisdiction through SimRunControl::cliOptions, which drops it '
                               'today: SimRunControl.php:253-274). Stage bars, workers, live and '
                               'review items reused from /simworld; halt / resume / rollback. '
                               'Gate: refuses until Step 4 is locked (boards, courts, seats '
                               'present).'},
                      {'wave': 'W7',
                       'label': '7 · Training gate armed as a setup system act; sim actors trained',
                       'status': 'done',
                       'note': 'DONE (eaf3136e). A new `training` phase runs after civics: '
                               'EducationCatalogService (shared publish, single owner) arms the '
                               'catalog once at the phase transition — AFTER the content stages, '
                               'so their gated F-LEG-* forms are never blocked (the gate passes '
                               'system filings but governance votes carry a real member actor). '
                               "TrainingStage pre-trains each jurisdiction's seated holders via "
                               'armForJurisdiction — a bounded, scoped pass, not the global '
                               'in-memory pluck. Pinned: per-jurisdiction arming trains only its '
                               'own and is idempotent. ORIGINAL: The gate arms only when an '
                               'operator runs education:seed by hand with a confirm prompt; the '
                               'box holds 0 tracks and 0 modules. Sim stages file gated forms and '
                               'catch the refusal as a half result. Publish the catalog as a '
                               'system act in Step 4 or Step 5 and pre-train sim actors between '
                               'the seating and governance stages (SeatedMemberTrainingService '
                               'plucks every seated id into memory: chunk it).'},
                      {'wave': 'W7',
                       'label': '8 · Resident wallets and the stipend pass (money plane)',
                       'status': 'done',
                       'note': 'DONE (e5dd8999). SimEconomyService drives the real economy '
                               'services: ensureCurrency defines the root currency once (Art. V '
                               '§5, advisory-locked) + root treasury + opening supply; '
                               'openWalletsFor opens a wallet per resident (accounts-never-people, '
                               'idempotent); runStipendFor runs the real F-TRE-004 over ONE '
                               "jurisdiction's residents (per-jurisdiction is the chunk, so the "
                               'hardened short-pay never runs planet-wide). IdentityStage opens '
                               'wallets in a sim run only ($runId present, so the election '
                               'fixtures stay clean); a new `stipends` phase runs the stipend per '
                               'jurisdiction. Pinned: wallets idempotent, stipend credits every '
                               'resident the floor, residentless is a clean skip. ORIGINAL: '
                               'AccountService::open is idempotent and per-owner but no job, stage '
                               'or step calls it; the stipend run is one unbounded transaction '
                               'with a per-recipient loop and a single demo-command caller. Open '
                               'wallets for the cohort in the identity stage; run the stipend as a '
                               'chunked, resumable, clock-armed pass.'},
                      {'wave': 'W7',
                       'label': '9 · Organizations and workers at demo scale',
                       'status': 'done',
                       'note': 'DONE (bee6fb9e, b432a1d6). Verified: co-determination is FED '
                               '(workerCountAt crosses the 100 first-seat and 2,000 parity '
                               'thresholds). Endorsements BUILT and POLYMORPHIC (operator '
                               '2026-09-06: no party/coalition layer — any org of any type OR any '
                               'individual may endorse any candidate): mintEndorsements draws a '
                               'mix of orgs and residents, round-robin over candidates, so the '
                               'graph is no slate. CGC register BUILT (f47048b4): CivicsStage '
                               'charters CGCs via the real CgcService (public, IP public domain, '
                               'governor board, 100% jurisdiction stake). CGC governors + org '
                               'boards BUILT (1c9740f9, SimBoardService): CGC governor seats '
                               'seated (10yr civil term); a bounded sample of businesses get a '
                               'provisioned board, real org_workers employment, a co-determination '
                               'recompute, and seated owner + worker reps (rows sampled per the '
                               '8GB box: large jurisdictions cross the worker threshold, small are '
                               'owner-only). ORIGINAL: CivicsStage mints parties, nonprofits, '
                               'businesses and bills census-flavoured '
                               '(app/Services/Demo/Stages/CivicsStage.php:109-219). '
                               'Co-determination, org board elections, the CGC register and org '
                               'endorsements have no subject on a fresh world until civics runs; '
                               'verify the civics output feeds them.'},
                      {'wave': 'W7',
                       'label': '10 · Console fixes',
                       'status': 'done',
                       'note': 'DONE (556104e6). SimSnapshot::world() is cached 10s and shared by '
                               'both surfaces (single owner), so the planet-wide counts no longer '
                               'run per poll; labels for governance / judiciary / civics kinds '
                               'live once in SimSnapshot::LABELS and both surfaces read them. '
                               'Remaining minor: the workers-target-minus-live-lanes tile and the '
                               'banner-beside-Start on the legacy /simworld page. ORIGINAL: The 2 '
                               's poll runs planet-wide counts (~75 s per the parity test): serve '
                               'a cached snapshot per minute like Step 3. Labels for governance, '
                               'judiciary and civics kinds; workers target minus live autoscale '
                               'lanes; the not-scale_demo banner beside a working Start '
                               '(app/Http/Controllers/Demo/SimConsoleController.php:56, 140, '
                               '192-214, 285-394; '
                               'resources/js/Pages/Demo/SimConsole.vue:181-218).'},
                      {'wave': 'W7',
                       'label': '11 · TEST: full-planet run on box E with halt, resume, rollback, '
                                'then lock',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): Still not executed. No commit shows a '
                               'full-planet run with halt, resume, rollback, and lock completed on '
                               'box E. The sim pipeline code exists but this test has not been '
                               'run. Evidence: No commit in the 97-commit range records a '
                               'full-planet sim run with halt, resume, rollback, and lock cycle '
                               "completion. sim:revert (SimRevertCommand.php) exists. Row 073's "
                               'OFFSET defect is also not fixed, which is a prerequisite for a '
                               'clean resume. | Ruled sim-scope B. Measure, do not assume: the '
                               'serial audit lock is about 28.6 appends per second '
                               '(app/Services/TabulationRecorder.php:273-280 comment). Repeat with '
                               'sim:revert until the run is trustworthy. Lock the result.'},
                      {'wave': 'W7',
                       'label': '12 · The Niue walk: the first seated chamber (operator trigger)',
                       'status': 'held',
                       'note': "VERIFIED 2026-09-08 (held): Held for the operator's trigger. "
                               'Fielding pin stands at '
                               'tests/Constitutional/ElectionStageTest.php:201 (commit 80b5e3a). '
                               'Precondition is W6 boards and seats. No trigger issued. Evidence: '
                               'Held for operator trigger. Fielding pin confirmed at '
                               'tests/Constitutional/ElectionStageTest.php:201. No commit in the '
                               '97-commit range shows the operator triggered a live Niue walk. | '
                               'Held for your trigger: a live Type B election electing 10 seats '
                               'across 5 panels (tests/Constitutional/ElectionStageTest.php:201, '
                               "commit 80b5e3a). Precondition: Niue's board and seats from W6. "
                               'Your standing note: no manual walk until all green.'}]},
           {'id': 'W8',
            'name': 'Step 6 and the public read-only world',
            'status': 'next',
            'items': [{'wave': 'W8',
                       'label': '1 · Step 6: Confirm and Close',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): Core page, route, and handler are '
                               'built. Step6_Confirm.vue calls POST '
                               '/api/setup/wizard/step6/complete, which stamps setup_completed_at '
                               '(SetupController.php:3940). Route widened in 20dca711. Two items '
                               'remain: no audit log entry for the founding window close, and no '
                               'CLI twin setup:complete command. Evidence: Step6_Confirm.vue '
                               'exists (resources/js/Pages/Setup/Step6_Confirm.vue). Route POST '
                               '/api/setup/wizard/step6/complete exists (routes/web.php:227). '
                               'completeStep6 stamps setup_completed_at and setup_step_completed '
                               '(SetupController.php:3940-3962). Route widened to steps 5 and 6 in '
                               'commit 20dca711. Page copy is clean. Missing: no audit log entry '
                               'in completeStep6, no CLI setup:complete command found in '
                               "app/Console/Commands/. | Move today's Step4_Confirm.vue to Step 6. "
                               'Precondition: Steps 4 and 5 settled (or recorded as running in '
                               'background, ruling subtree-activation-shape B+). Idempotency guard '
                               'on re-POST (step() has no completion redirect: '
                               'SetupController.php:97-121, 3596-3633). Audit entry for the '
                               'founding window closing. CLI twin setup:complete (no CLI writes '
                               "setup_completed_at today). No dispatch. Page copy: remove 'Phase "
                               "2' and 'a planet takes minutes'."},
                      {'wave': 'W8',
                       'label': '2 · Read-only instance flag, engine and endpoint refusal, '
                                'operator toggle, banner',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): No operator-toggled read-only flag '
                               'exists. The schema has no such column. Existing read-only code '
                               'covers federation mirrors only. This item is not built. Evidence: '
                               'The instance_settings table has no read_only_lock or equivalent '
                               'column (database/schema/pgsql-schema.sql:2392). No middleware '
                               'enforces an operator-toggled read-only state. The read-only '
                               'references in the codebase are for federation mirrors only '
                               '(MirrorService.php, ConstitutionalEngine.php:129). | Ruled '
                               'read-only-lock A. A new instance flag, operator-toggled with an '
                               'audit entry. A visitor can assume a role and act through the UI; '
                               'the ConstitutionalEngine and every write endpoint (economy 14 '
                               'doors, federation ~20 doors, identity, orgs) discard the change '
                               'and say so; registration closes; the sim and clock pumps pause; '
                               'reads and walking stay open. Shell banner: this world is '
                               'read-only; your changes are shown and not saved.'},
                      {'wave': 'W8',
                       'label': '3 · Every read surface public',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): /simworld and /api/simworld/progress '
                               "remain behind middleware('auth') (routes/web.php:711-726). The "
                               'setup allow list is unchanged (RedirectIfSetupIncomplete.php:34). '
                               'No read surfaces have been opened since this item was written. '
                               "Evidence: /simworld is inside middleware('auth') group "
                               '(routes/web.php:711). /api/simworld/progress is in the same group '
                               '(routes/web.php:725). RedirectIfSetupIncomplete ALLOW list is '
                               'unchanged: '
                               "['setup','operator','legislatures','jurisdictions','federation','login','logout','register'] "
                               '(RedirectIfSetupIncomplete.php:34). No route changes to open any '
                               'blocked read surface. | Ruled public-walk A. The setup allow list '
                               '(app/Http/Middleware/RedirectIfSetupIncomplete.php:34) and the '
                               'auth groups block reads today: /simworld and '
                               '/api/simworld/progress (routes/web.php:687-702), /building '
                               '(280-282), the 13 economy routes, /executives and /departments, '
                               'the constitutional-challenge index and show, committees / '
                               'referendums / emergency-powers indexes, /learn and /support, the '
                               'federation console, bill and committee detail. Open the reads; '
                               'keep the drives behind the operator guard and the lock.'},
                      {'wave': 'W8',
                       'label': '4 · Close the open write doors',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): All four gaps remain open. '
                               '/api/import/jurisdictions runs pg_restore with no auth '
                               '(routes/web.php:456, JurisdictionController.php:637). pull-option '
                               'and pull-control have auth but not an operator check. The join API '
                               'has no auth (routes/web.php:99). /register is on the setup allow '
                               'list. Evidence: POST /api/import/jurisdictions '
                               '(routes/web.php:456) has no middleware and no '
                               'abort_unless(is_operator) in JurisdictionController::importMaps '
                               '(JurisdictionController.php:637). pull-option (routes/web.php:167) '
                               "and pull-control (routes/web.php:170) carry middleware('auth') but "
                               'SetupController::setGeodataPullOption (line 1629) has no operator '
                               'check. POST /api/setup/join (routes/web.php:99) has no middleware. '
                               '/register is in ALLOW list (RedirectIfSetupIncomplete.php:34). | '
                               'POST /api/import/jurisdictions has no auth and runs pg_restore '
                               '(routes/web.php:432; JurisdictionController.php:630-667); '
                               'pull-option, pull-control and deploy-package lack the operator '
                               'check (SetupController.php:1512-1528, 1798-1922, 4249-4266); the '
                               'join API has no user check (:332-346); /register is on the allow '
                               'list. All carry auth and the operator check; all refuse under the '
                               'lock.'},
                      {'wave': 'W8',
                       'label': '5 · TEST: guest walk on box E under the lock',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): Manual test on a live box. W8 items 1 '
                               'through 4 are not finished. Cannot verify from code. Evidence: '
                               'Manual test requiring a live instance. W8 items 1-4 are not '
                               'complete. Cannot verify from code. | Sign out. Walk every read '
                               'surface as a guest, including the sim console. Attempt writes as a '
                               'guest and as an assumed role: every write is refused with the '
                               'message and nothing persists.'}]},
           {'id': 'W9',
            'name': 'Fresh cloud build and the demo mesh',
            'status': 'next',
            'items': [{'wave': 'W9',
                       'label': '1 · CLI parity for the fresh build',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): CLI gaps confirmed. '
                               'MapsAcceptCommand.php:80 stamps setup_step_completed=max(2) with '
                               'no world-build dispatch or mode write. The restore-from-backup '
                               'branch stamps only map_accepted_at. No setup:complete CLI command '
                               'exists. Evidence: MapsAcceptCommand stamps only '
                               'setup_step_completed=max(2) and map_accepted_at with no '
                               'world-build dispatch or scale mode write '
                               '(MapsAcceptCommand.php:80-83). MapDataImportService stamps only '
                               'map_accepted_at with no run or simulate writes. No setup:complete '
                               'CLI command found in app/Console/Commands/. W8 item 1 is partial, '
                               'not done. | maps:accept skips the world-build verifier gate and '
                               'the mode and simulate writes and creates the run as queued '
                               '(app/Console/Commands/MapsAcceptCommand.php:74-126 vs '
                               'JurisdictionController.php:699-905). The restore-from-backup '
                               'branch stamps map_accepted_at with no run, no world build and no '
                               'scale mode (app/Services/MapDataImportService.php:164-168): stamp '
                               'them from the bundle or block the branch. setup:complete from W8.'},
                      {'wave': 'W9',
                       'label': '2 · Fresh cloud build on the Azure box at 96 cores',
                       'status': 'next',
                       'note': 'UPDATE 2026-09-08: Steps 0 to 4 ran end to end on the Azure box '
                               '(D64als_v7, later D8; the 96-core size was not used). Step 4 done '
                               '14:53, 923,118 units, review 0. The boot-prewarm measurement was '
                               'taken (worker storm on 16 GB) and produced e10d2588. REMAINING: '
                               'Step 5/6 on this box were set aside by operator decision (the box '
                               'is the writable beta); the clock-job sweep measurement (debt row, '
                               'still deferred); the zero-seat leaf chamber confirmation; per-step '
                               "baselines are in the cloud lane's reports, not yet recorded here. "
                               '| VERIFIED 2026-09-08 (next): Infrastructure task. No commits '
                               'address an Azure build run. Status unchanged. Evidence: '
                               'Infrastructure task. No commit in the 97-commit window addresses '
                               'an Azure box build run. Cannot verify from code. | Ruled '
                               'demo-mesh-host (your plan). Run Steps 0 to 6 end to end through '
                               'the wizard on the Azure box scaled to 96 cores. Record a measured '
                               'baseline per step. Take the measurements you ruled to take there: '
                               'boot-time prewarm cost (docker/php/entrypoint.sh:109-136), the '
                               'clock job and its three sweeps (routes/console.php:26, 136; '
                               'app/Jobs/EvaluateClocksJob.php:55-70). Confirm no zero-seat leaf '
                               'chambers appear (leaf seeding needs population > 0).'},
                      {'wave': 'W9',
                       'label': '3 · Scale down; Europe mirror; DNS and TLS',
                       'status': 'next',
                       'note': 'UPDATE 2026-09-08: DONE: scale down (D8), DNS and TLS for '
                               'beta.worldofstatecraft.org (+ auth.beta, rtc.beta) via deploy.sh '
                               '--public-url and the Caddy edge. NOT DONE: the Europe mirror node; '
                               'DNS/TLS for demo.worldofstatecraft.org (the read-only simulation). '
                               '| VERIFIED 2026-09-08 (next): Infrastructure task. No commits '
                               'address scaling, a Europe mirror, or DNS and TLS. Status '
                               'unchanged. Evidence: Infrastructure task: scaling, mirror node, '
                               'DNS, TLS. No commits in the 97-commit window address these. | '
                               'Ruled europe-node-identity B. Scale the Azure box down. '
                               'Mirror-join a node hosted in Europe; the Azure box stays '
                               'authoritative and online 21 to 23 September. DNS and TLS for '
                               'demo.worldofstatecraft.org (the read-only simulation) and '
                               'beta.worldofstatecraft.org (the writable instance, W11).'},
                      {'wave': 'W9',
                       'label': '4 · TEST: smoke walk on the mirror',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): Manual test on a mirror node not yet '
                               'provisioned. W9 items 2 and 3 are not done. Status unchanged. '
                               'Evidence: Manual test on infrastructure that does not yet exist. '
                               'Prerequisite W9 items 2 and 3 are not done. Cannot verify from '
                               'code. | Guest walk on demo.worldofstatecraft.org under the lock. '
                               'Compare counts with the Azure box. Record the sync lag.'}]},
           {'id': 'W10',
            'name': 'Demo polish',
            'status': 'next',
            'items': [{'wave': 'W10',
                       'label': '0a · Demo mode: real writes, compensating purge (rulings C + A)',
                       'status': 'done',
                       'note': 'BUILT 2026-09-10 a1a23600: DemoMode (box-level scale_demo), demo_sessions + demo_session_writes, PG trigger cga_demo_capture on 220 tables gated by the transaction-local setting cga.demo_session, engine waives role + training gates and records the waiver in the act payload (_demo.bypassed), DemoSessionService::void reverses newest-first and appends ONE demo.session.voided entry; logout + demo:void-expired (every 5 min). Pinned: DemoSessionVoidTest 4/4 on box E. Open: deploy.sh should run demo:void-expired --install after migrate for tables added later.'},
                      {'wave': 'W10',
                       'label': '0b · Read everywhere: a role gates actions, not pages; residency instant on beta',
                       'status': 'done',
                       'note': 'SHIPPED 2026-09-10: 6486469d CGA_RESIDENCY_INSTANT (thresholdDays 0, F-IND-006 files at declare); 8a96c779 menu + sidebar never hide a section by role; 253847f0 board console + org economy render read-only for every viewer; 12c1c02b Residency page for the common user. Setup lock (RedirectIfSetupIncomplete) was the gate the operator felt on box E; closed by completeStep6 (box E setup_completed_at 2026-09-10).'},
                      {'wave': 'W10',
                       'label': '0c · A place's own page; election page in the viewer's terms; desktop width',
                       'status': 'done',
                       'note': 'SHIPPED 2026-09-10 32844a68: /jurisdictions/{slug} = Jurisdictions/Home.vue (identity, chain, government, take part), the map viewer at /jurisdictions/{slug}/map; election detail titled 'General election — Place', own race first (RaceFootprint::bestRaceForUser), totals 'across all N districts', Election::kindLabel; .main-content 56rem -> 120rem (.main--wide 140rem). DOM-verified on box E; screenshot proof pending (hidden browser window).'},
                      {'wave': 'W10',
                       'label': '0d · Journeys, ballot, basemap',
                       'status': 'done',
                       'note': 'SHIPPED 2026-09-10: 07e31703 all 14 journeys carry what / your part / where / form with a unified stepper; 08567d89 open ballot names your race, other races in one select; edb799f5 Protomaps basemap on the residency map (lib/protomapsBasemap.js).'},
                      {'wave': 'W10',
                       'label': '1 · Re-verify the data-dependent screens and capabilities with '
                                'data',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): No re-verification pass has been '
                               'committed. W7 sim data exists, but this review task has not been '
                               'executed. Status remains next. Evidence: This is a meta-task pass '
                               'over data-dependent screens after W7 world build. No commit in the '
                               'leads list performs or records a re-verification pass. The task '
                               'remains pending. | After W7 the world holds elections, ballots, '
                               'members, committees, departments, courts and cases. Re-grade: '
                               'candidacy, election detail, approval and ranked ballots, results, '
                               'vacancy countback, department detail and reporting, your courts; '
                               'Type A and Type B races, secret ballot, endorsements, bills, '
                               'votes, bicameral, referendums, petitions, emergency powers, '
                               'amendments, executive delegation and orders, board elections, '
                               'courts, cases, double jeopardy, Art. IV §5, CGC register, '
                               'exchange, currency telemetry, live civic rooms (the chamber '
                               'session route is missing: LiveRoomController exposes committee '
                               'only), civic square.'},
                      {'wave': 'W10',
                       'label': '2 · Learn content publication and the nav rows',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): Nav rows for video library and '
                               'translation board are absent. Education catalog is unseeded (no '
                               'education:seed in the scheduler). No commit in the recent set '
                               'closes any sub-item. Evidence: resources/js/registry/coverage.js '
                               "says the translations nav row 'lands at lane 5's next pass'. No "
                               'nav row for video library was found in '
                               'resources/js/Components/Nav/. No education:seed command call or '
                               'education track seeding exists in routes/console.php or '
                               'database/seeders/. No commit in the leads list addresses any of '
                               'these items. | Publish the education catalog (config; only '
                               'education:seed publishes it; the box holds 0 tracks). Add the '
                               'missing nav rows for the video library and the translation board. '
                               'Learn, lesson and video pages then render content instead of empty '
                               'states.'},
                      {'wave': 'W10',
                       'label': '3 · Copy and control fixes on the wizard and the viewer',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): Commits 77da9b69 (grind shunt removed) '
                               'and 42dc5c7a (requeue never hidden) closed two Step 3 sub-items. '
                               'Step 1 and Step 2 copy/control items, the two AutoscaleRunControl '
                               'bypass doors, and the reach items remain open. Evidence: Commit '
                               '77da9b69 removed the grind-box shunt entirely '
                               '(AutoscaleRunControl.php, AutoscalePumpCommand.php), closing the '
                               "'grind shunt config key' sub-item. Commit 42dc5c7a fixed the "
                               'requeue-never-hide issue (Step3_Districts.vue), closing '
                               "'requeueDriftMaps surface'. Remaining open: "
                               'Step1_Constants.vue:373-375 clamped caption, Step2_MapData.vue '
                               'population-mode caption/section numbering/unbound controls/engine '
                               'ref, the two AutoscaleRunControl bypass doors, newest-run vs '
                               'pump-on-oldest controls, jurisdiction picker search, '
                               'leading-wildcard search, and unbounded chamber lists. The overall '
                               "task is not done. | Step 1: 'clamped to [min, max]' "
                               '(Step1_Constants.vue:373-375). Step 2: population-mode caption 5–9 '
                               'vs the actual threshold, section numbering 1, 2, 2, 2, 4, unbound '
                               'controls (country scope, fresh, skip population, pause on '
                               'exception) and the engine ref fixed to pull '
                               '(Step2_MapData.vue:77-124, 765-1324, 1444). Step 3 engine '
                               'controls: requeueDriftMaps surface, the grind shunt config key, '
                               'the two doors that bypass AutoscaleRunControl, controls on the '
                               'newest run vs the pump on the oldest. Reach: the 200-row '
                               'jurisdiction picker with no search; the unindexed leading-wildcard '
                               'search on Say where you live; Public records and Term lockstep '
                               'unbounded chamber lists.'},
                      {'wave': 'W10',
                       'label': '4 · Dead dials',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): All listed dead dials remain. '
                               'time_mode is stored but not consumed. extraRooms is defined but '
                               'never called. courtTiers has no definition in app/. No commit '
                               'addressed any item. Evidence: time_mode written by '
                               'SetupController:804 but has no downstream reader outside setup '
                               'storage. extraRooms defined at InstitutionScaleService.php:219 but '
                               'grep finds no caller. courtTiers not found in app/ or config/. '
                               'cga.critical_population_default (config/cga.php:194) and '
                               'cga.activate_subtree_batch (config/cga.php:35) have no wired '
                               'consumers. No commit in the leads list removes or wires any of '
                               'these. | time_mode and seconds per year (no reader); '
                               'cga.critical_population_default and cga.activate_subtree_batch (no '
                               'reader); cga.autoscale_adm_max (stamp only); instance_class and '
                               'population_binding (no writer); courtTiers and extraRooms (no '
                               'caller); cohort clusters and candidacy priors (never read). Remove '
                               'or wire each.'},
                      {'wave': 'W10',
                       'label': '5 · Atlas a11y findings and the screenshot debt',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): '
                               'docs/plans/ui/L6W5_ATLAS_A11Y_FINDINGS.md is present with open '
                               'findings. No a11y fix or screenshot capture was committed in the '
                               'recent set. Evidence: docs/plans/ui/L6W5_ATLAS_A11Y_FINDINGS.md '
                               'exists and contains open findings (confirmed present). No commit '
                               'in the leads list fixes a11y issues or captures after-screenshots. '
                               '| docs/plans/ui/L6W5_ATLAS_A11Y_FINDINGS.md: one medium (domain '
                               'cards need h3 / region) and three low. Capture the owed '
                               'after-screenshots at review time.'},
                      {'wave': 'W10',
                       'label': '6 · Docs',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): CLAUDE.md:585 lists '
                               'ElectionTriggerService.php which does not exist. fc_scheduler is '
                               'absent from docker-compose.yml. No commit in the recent set '
                               'corrects any of the listed documentation gaps. Evidence: '
                               'CLAUDE.md:585 lists app/Services/ElectionTriggerService.php in the '
                               'protected files table; the file does not exist on disk (confirmed '
                               'absent). ConstitutionalVersionService.php exists. fc_scheduler is '
                               'absent from docker-compose.yml. No commit in the leads list '
                               'updates CLAUDE.md or any of the other cited stale docs entries. | '
                               'CLAUDE.md protected files list ElectionTriggerService.php which '
                               'does not exist (also ConstitutionalVersionService.php:33); the '
                               'scheduler container fc_scheduler (docker-compose.yml:503-526) is '
                               'absent from the service table; config/horizon.php:257-260 '
                               'describes the retired lane law; SetupController.php:42, 3858 name '
                               'the retired AutoscaleOrchestratorJob; EvaluateSocialStructureJob '
                               'docblock says unscheduled while routes/console.php:113 schedules '
                               'it.'},
                      {'wave': 'W10',
                       'label': '7 · fr/pt shell chrome (optional)',
                       'status': 'deferred',
                       'note': 'VERIFIED 2026-09-08 (deferred): Operator ruling i18n A stands: '
                               'English chrome only. No fr/pt work was committed. Status remains '
                               'deferred. Evidence: No commit in the leads list adds fr/pt chrome '
                               "strings. Operator ruling 'i18n A for the playtest: English chrome' "
                               'is intact. The deferred status is operator-authored. | Ruled i18n '
                               'A for the playtest: English chrome. A reader pass on the fr/pt '
                               'chrome only if time allows; never raw NLLB navigation.'}]},
           {'id': 'W11',
            'name': 'Live mesh: beta.worldofstatecraft.org readiness',
            'status': 'next',
            'items': [{'wave': 'W11',
                       'label': '1 · Population-mode autoboot (ruled A, 2026-08-29)',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): EvaluateCriticalPopulationJob calls '
                               'onCriticalPopulation (app/Services/ActivationService.php:260-305), '
                               'which records the crossing and writes an audit row only. Nothing '
                               'dispatches activate() from the sweep. The founding election, '
                               'bootstrap board, and seat mint do not follow automatically. Close '
                               'the loop: activate() must be called on the crossing. Evidence: '
                               'app/Jobs/Clocks/EvaluateCriticalPopulationJob.php:61-126: the job '
                               'calls ActivationService::onCriticalPopulation(), which writes the '
                               'critical_population state and an audit row but never calls '
                               'activate(). ActivationService::activate() has three callers: '
                               'JurisdictionActivateCommand, ElectionsDemoCommand, and '
                               'JurisdictionActivateController (dev). No automatic boot on '
                               'population crossing exists. | EvaluateCriticalPopulationJob writes '
                               'state only; nothing calls ActivationService::activate '
                               '(app/Jobs/Clocks/EvaluateCriticalPopulationJob.php:120-126; '
                               'ActivationService.php:262-305). Close the loop: the crossing boots '
                               'the place (seats, board, founding election). The effective '
                               'threshold is 1 resident with the tier disabled; Step 2 copy says '
                               '5–9.'},
                      {'wave': 'W11',
                       'label': '2 · Subtree boot recovery (ruled B+)',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): Three gaps confirmed in code. Lanes '
                               'collapse to one after the root wave because N-1 lanes find no '
                               'pending item at depth 0 and exit without redispatching '
                               '(SubtreeBootLaneJob.php:134). A running row at attempts=3 cannot '
                               'be reclaimed and pins the depth barrier forever (line 70-77). '
                               'FinishActivationsJob always fires elections regardless of '
                               'instance_scale_mode (FinishActivationsJob.php:54). No reset '
                               'control and no demand-priority path exist for the subtree boot '
                               'queue. Evidence: app/Jobs/SubtreeBootLaneJob.php:59-88: the claim '
                               'query gates on attempts < MAX_ATTEMPTS (3). A row stuck in '
                               "'running' at attempts=3 stays open indefinitely and blocks "
                               'depth-wave advancement. The depth barrier query (line 70) counts '
                               "'running' rows. app/Jobs/FinishActivationsJob.php:54: always calls "
                               'jurisdiction:activate without --no-election, ignoring '
                               'instance_scale_mode. app/Jobs/ActivateSubtreeJob.php:87-93: '
                               'dispatches host-derived lanes, but only 1 item exists at depth 0 '
                               '(root), so N-1 lanes exit after finding no claimable work. No '
                               'reset endpoint found. AutoscaleClaims demand priority '
                               '(app/Support/AutoscaleClaims.php:89-99) applies to districting '
                               'only. | Lanes collapse to one after the root wave; a three-strike '
                               'running row pins the depth barrier; no reset control; '
                               'FinishActivationsJob schedules elections in every mode on the '
                               'default queue; the enumeration and the single Activate run inline '
                               'in the request (app/Jobs/ActivateSubtreeJob.php:62-93; '
                               'SubtreeBootLaneJob.php:33, 57-78, 114-127; '
                               'FinishActivationsJob.php:37-56; '
                               'JurisdictionController.php:949-1028). Demand priority (ruled) does '
                               'not exist.'},
                      {'wave': 'W11',
                       'label': '3 · Clock engine at planet scale',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): CLK-01 and CLK-02 jobs are built. '
                               'RankedStandingsRollupJob uses cursor() over all ranked_open '
                               'elections in one pass (RankedStandingsRollupJob.php:58), which is '
                               'not restartable if killed mid-run at scale. Planet-scale sizing '
                               'for residency, critical-population, and petition sweeps and the '
                               'nightly world-stats job has not been measured or verified. This '
                               "item remains open pending the operator's measure-first ruling "
                               '(default-queue-sweeps C). Evidence: '
                               'app/Jobs/Clocks/AdvanceElectionPhaseJob.php (CLK-01) and '
                               'app/Jobs/Clocks/MeetingDeadlineJob.php (CLK-02) exist. '
                               'app/Jobs/Elections/RankedStandingsRollupJob.php:58-60: uses '
                               'cursor() over all ranked_open elections in one job invocation; not '
                               'restartable mid-run. Planet-scale sweep sizing and the nightly '
                               'world-stats job are not verified as chunked and resumable for '
                               '940,327 jurisdictions. No operator action recorded in commits '
                               'since 563c4899 to close this item. | CLK-01 general-election '
                               'timers armed and fired at scale (500 fires per minute today); '
                               'CLK-02 meeting deadline armed at seating; the residency, '
                               'critical-population and petition sweeps and the nightly world '
                               'stats sized for 940,327 jurisdictions (ruled default-queue-sweeps '
                               'C: measure first, then act on evidence); standings rollups chunked '
                               'and resumable (RankedStandingsRollupJob cursors every ranked_open '
                               'election in one job).'},
                      {'wave': 'W11',
                       'label': '4 · Federation hardening for the live mesh',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): Three gaps remain. pushTo sends the '
                               'full audit tail in one body with no paging '
                               '(FederationSyncService.php:236). AuthorityFlipService uses whereIn '
                               'on the full descendant set without chunking, which exceeds the '
                               '65,535-parameter limit for large subtrees '
                               '(AuthorityFlipService.php:81). ClusterJoinCommand and '
                               'ClusterLeaveCommand have no operator confirmation gate. The prior '
                               "note's claim that FederationInitCommand skips identity minting and "
                               'CLK-20 is no longer accurate: ensureIdentity() and CLK-20 arming '
                               'are in place (FederationInitCommand.php:43,62). Evidence: '
                               'app/Services/Federation/FederationSyncService.php:236: pushTo '
                               'calls buildAuditTail($fromSeq) with no limit, sending the full '
                               'tail in one HTTP body. '
                               'app/Services/Federation/AuthorityFlipService.php:81,124,127,168,175: '
                               "all use DB::table('jurisdictions')->whereIn('id', "
                               '$descendants)->update() with no chunking; a planet-wide subtree '
                               'exceeds the 65,535-parameter PDO limit. '
                               'app/Console/Commands/ClusterJoinCommand.php and '
                               'ClusterLeaveCommand.php: neither has a confirmation prompt or '
                               'operator gate. '
                               'app/Console/Commands/FederationInitCommand.php:43,62: '
                               'ensureIdentity() mints the identity and CLK-20 IS armed when '
                               "enabling — the prior note's claim about missing identity/CLK-20 is "
                               'now incorrect. | pushTo ships the whole audit tail in one body '
                               '(paging exists on the pull side only); the authority-flip manifest '
                               'binds every descendant id in one statement above the '
                               '65,535-parameter limit; the cluster join and leave doors have no '
                               'operator gate; the console federation toggle sets '
                               'federation_enabled without minting the identity or arming CLK-20.'},
                      {'wave': 'W11',
                       'label': '5 · beta.worldofstatecraft.org: the writable instance',
                       'status': 'next',
                       'note': 'UPDATE 2026-09-08: beta.worldofstatecraft.org is LIVE as the '
                               'writable instance: public TLS, MAS login, rooms and voice '
                               '(operator in a live call 2026-09-08), a cloud-box test account '
                               'created. REMAINING: the live-mesh items above (W11 1 to 4); '
                               'registration policy and the read-only flag confirmed at '
                               "acceptance; the operator's flagged demo concern (the 30-day "
                               'residency confirmation) is an open question in the rubric. | '
                               'VERIFIED 2026-09-08 (next): Commits 080d389c and d9298482 fix '
                               'Matrix, MAS, and LiveKit secret synchronisation in deploy.sh so a '
                               'pull plus --public-url lands a working live room. The deploy '
                               'tooling is closer to ready. The second instance itself is not yet '
                               'deployed. Registration open and read-only flag off remain ops '
                               'steps. Evidence: Commits 080d389c and d9298482 ship deploy.sh '
                               'improvements (Matrix/LiveKit/Synapse wiring for --public-url) that '
                               'are preconditions for a live instance. However, the row describes '
                               'a running second instance where registration is open and the '
                               'read-only flag is off. That is an ops task, not a code artifact. '
                               'No code evidence that a second instance is live. | Your plan: a '
                               'second instance, scaled up and live, where attendees make '
                               'accounts. Registration open; the read-only flag off; population '
                               'mode or eager per your choice at acceptance; the live-mesh items '
                               'above in place.'},
                      {'wave': 'W11',
                       'label': '6 · Economy doors',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): BudgetService::draft and ::enact have '
                               'no form handler or controller route calling them '
                               '(EconomyController.php:346 lists budgets only). The F-ORG-008 '
                               'share issuance web door is absent; OrgEconomyController.php:33 '
                               'marks it as future piece 4. CHECK(balance >= 0) on '
                               'economic_accounts is not in any migration. F-IND-023 '
                               '(FundsTransfer) is fully wired and does ship a transfer form. '
                               'Evidence: app/Services/Economy/BudgetService.php:43 (draft) and '
                               ':95 (enact) exist but no form handler or controller route calls '
                               'them. EconomyController.php:346 only lists budgets. '
                               'app/Http/Controllers/Organizations/OrgEconomyController.php:33: '
                               "comment says F-ORG-008 share issuance is 'piece 4; for now' "
                               'without a form. resources/js/Pages/Economy/OrgSettings.vue: '
                               "labelled 'honest-absence' for share issuance. No CHECK(balance >= "
                               '0) constraint found in economic_accounts migrations. F-IND-023 '
                               'FundsTransfer handler exists '
                               '(app/Domain/Forms/Handlers/FundsTransfer.php) and is wired to '
                               'EconomyActionController.php:53. | BudgetService::draft and ::enact '
                               'have no caller and no form; F-IND-023 records a dues payment as a '
                               'plain transfer; CHECK(balance >= 0) on economic_accounts '
                               '(deferred, belt and braces); a web door for F-ORG-008 share '
                               'issuance.'},
                      {'wave': 'W11',
                       'label': '7 · Deferred schema and infrastructure',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): No code found for any item in this '
                               'row. Agenda_items migration is absent. constituent_requests table '
                               'is not defined. LIVE_PG_DATABASE per-lane config is not in the '
                               'codebase. Mobile app (Capacitor) is not present. All items remain '
                               'deferred. Evidence: grep for agenda_items, constituent_requests, '
                               'LIVE_PG_DATABASE, and Capacitor across app/ and database/ returned '
                               'no results. No migration for agenda_items exists. Social '
                               'primitives explicitly marked not built. No per-lane '
                               'LIVE_PG_DATABASE config found. Mobile app (Capacitor) not present. '
                               '| Agenda per-item schema (agenda_items migration not on main); '
                               'constituent_requests + room read position; the '
                               'deliberately-not-built social primitives; per-lane '
                               'LIVE_PG_DATABASE (retire the quiet-window freeze); the mobile app '
                               '(Capacitor, Phase 6).'},
                      {'wave': 'W11',
                       'label': '8 · Lane 14 · Coalition Organization (Phase J)',
                       'status': 'held',
                       'note': 'VERIFIED 2026-09-08 (held): No Phase J code built. Operator hold '
                               'is active: Foundation = 501(c)(3) parent, Coalition = project '
                               'child, Action Fund = do not build. No commits since 563c4899 '
                               'change this. Evidence: No coalition or action fund code found in '
                               'app/. No commits since 563c4899 touch Phase J. Operator hold on '
                               'record in MEMORY.md (project_phase_j_coalition_orgs.md): '
                               'Foundation = 501(c)(3) parent, Coalition = child project, Action '
                               'Fund = do not build. | Held by the operator: Foundation = '
                               '501(c)(3) parent; Coalition = a project (child); Action Fund = do '
                               'not build. Resumes on his word.'}]},
           {'id': 'boxE',
            'name': 'Box E one-offs (development box alignment; outside the waves)',
            'status': 'deferred',
            'items': [{'wave': 'boxE',
                       'label': 'Box E · delete the two duplicate legislature rows',
                       'status': 'deferred',
                       'note': 'VERIFIED 2026-09-08 (deferred): The unique index fix landed in '
                               '2026_09_06_000001_provision_engine.php:127. The data repair on box '
                               'E is a one-off not yet done. Operator deferred this until it '
                               'blocks W6 or W7 testing. Evidence: The code fix (unique index '
                               'legislatures_live_jurisdiction_uq) landed in migration '
                               '2026_09_06_000001_provision_engine.php:127. The data repair '
                               '(deleting duplicate rows on box E) is a one-off task not addressed '
                               'in any commit. Operator deferred it until it blocks testing. | '
                               'Githunguri and Kalmar each hold two identical rows from 2026-08-29 '
                               '16:05:47. One-off data repair on the test box; the code fix '
                               '(unique index) is W6 item 5. Do it only if it blocks W6 or W7 '
                               'testing.'},
                      {'wave': 'boxE',
                       'label': 'Box E · redraw the nine active maps with a zero-seat district',
                       'status': 'deferred',
                       'note': 'VERIFIED 2026-09-08 (deferred): The zero-bin rule fix is in '
                               'DistrictingService.php:2463. Fresh builds no longer produce '
                               'zero-seat districts. The data repair for the 9 existing maps on '
                               'box E is still a one-off. Operator deferred it. Evidence: The rule '
                               'fix (zero-bin, DistrictingService.php:2463) prevents zero-seat '
                               'districts in fresh builds. The data repair for the 9 existing maps '
                               'on box E is a one-off not addressed in any commit. No operator '
                               'ruling lifted the deferral. | One-off after the W6 rule fix; a '
                               'fresh build never produces them once the rule changes.'},
                      {'wave': 'boxE',
                       'label': 'Box E · finish the shell set',
                       'status': 'done',
                       'note': 'Superseded: the W6 engine test run on box E provisions the '
                               'planet.'}]},
           {'id': 'history',
            'name': 'Waves 1 to 5 (history, by lane)',
            'status': 'done',
            'items': [{'wave': 'W5',
                       'label': 'L1 · Fire the live per-clump Niue general (walk demo)',
                       'status': 'held',
                       'note': "VERIFIED 2026-09-08 (held): Held for the operator's trigger. "
                               'Fielding pin stands at '
                               'tests/Constitutional/ElectionStageTest.php:201. Step 4 complete '
                               '(commit 586e4e51) satisfies the board-seating precondition. No '
                               'operator trigger issued. Evidence: Fielding pin confirmed at '
                               'tests/Constitutional/ElectionStageTest.php:201 '
                               '(test_a_per_clump_chamber_fields_its_election_end_to_end, commit '
                               '80b5e3a). Step 4 closed by commit 586e4e51. No commit since '
                               '563c4899 shows an operator trigger for the Niue walk. | VERIFIED '
                               "2026-09-05: Held for the operator's trigger. The fielding pin "
                               'stands: tests/Constitutional/ElectionStageTest.php:201 '
                               'test_a_per_clump_chamber_fields_its_election_end_to_end (80b5e3a). '
                               'Box E today: Niue legislature 96268bbf is forming, 11 Type A + 10 '
                               'Type B seats, one active grouping of 5 panels / 10 seats; 0 '
                               'elections for it; 0 election boards for Niue (jurisdiction '
                               '056fb95c). ElectionStage refuses with no_election_board when the '
                               'jurisdiction has no active board '
                               '(app/Services/Demo/Stages/ElectionStage.php:96-109). Precondition '
                               "before his trigger: a Step 4 lane seats Niue's board (Wave 6 "
                               'ruling, institution scale-up). | approved + fielding-pinned '
                               '(80b5e3a); a live Type B election electing 10 seats across 5 '
                               "panels for the operator's walk — his trigger"},
                      {'wave': 'W5',
                       'label': 'L1 · Game-box mass pass over ~9,708 flagged chambers',
                       'status': 'done',
                       'note': 'VERIFIED 2026-09-05: Done on box E in the 2026-09-05 planet run. '
                               'The Type B panel scope runs as the last scope of every composite '
                               'walk (app/Services/Autoscale/SweepScopeProcessor.php:249-261, '
                               'drawTypeBPanels); the mass CLI type-b:district stays for re-runs '
                               '(app/Console/Commands/TypeBDistrictCommand.php:51-53). Box E: 0 '
                               'chambers flagged type_b_needs_districting; 36,810 active groupings '
                               '(7,506 archived, 29 draft); 475,352 panels. The stale-grouping '
                               'guard archives the prior active plan before a re-seed '
                               '(app/Services/Legislature/TypeBDistrictMapper.php:1384-1390). The '
                               '9,708 figure counted flagged chambers only; the run grouped every '
                               "chamber that holds a Type B. | operator-coordinated: pull L1's "
                               'commits to the game box, run ETL-chunked; the stale-grouping guard '
                               'protects re-seeds'},
                      {'wave': 'W4',
                       'label': 'L1 · Type B race fix — SEATING (per-clump) + per-child + '
                                'hardening',
                       'status': 'done',
                       'note': 'c500a1f + c96e757 + 1ffde5b; pooled shape fully retired, at_large '
                               '= type_a-only'},
                      {'wave': 'W4',
                       'label': 'L1 · Niue cleared LIVE + reshape confirmed',
                       'status': 'done',
                       'note': '5 panels/10 seats, both chambers elect; 2 stale elections voided, '
                               'next mint per-clump'},
                      {'wave': 'W4',
                       'label': 'L1 · Adversarial pass + fixture reds + CHECK + Geodata red',
                       'status': 'done',
                       'note': '5f2293b (2 defects) · a32bbc8 · 220100 · 06d9545'},
                      {'wave': 'W5',
                       'label': 'L2 · Authoritative RE-GATE — full suite, quiet window, after the '
                                'build lands',
                       'status': 'done',
                       'note': 'SUPERSEDED 2026-09-05: Superseded by the operator ruling of '
                               '2026-09-02 (answers, not retests): the full php artisan test never '
                               'runs on the live box as a gate again. The replacement is the '
                               'DB-free pass (LIVE_PG_DATABASE=cga_absent_test_db, live pins '
                               'self-skip) plus the targeted live classes for the touched '
                               'subsystems, named in the report. The W5 re-gate itself never ran: '
                               'the desk ledger ends at W5 tick 8 with the re-gate still pending, '
                               'no gate log exists on disk, and no commit after the W5 launch '
                               'records a suite gate. The W5 arm and walk sequence did not fire '
                               'either; the Wave 6 rulings replace it with Step 4 scale-up, Step 5 '
                               'simulation, Step 6 close. | SUITE TOKEN + freeze + storage-chown; '
                               'confirm ALL-GREEN before the walk. Your triage: own-the-world → '
                               'audit-lock → real defect'},
                      {'wave': 'W5',
                       'label': 'L2 · Per-lane LIVE_PG_DATABASE (retire the freeze)',
                       'status': 'deferred',
                       'note': 'VERIFIED 2026-09-08 (deferred): Deferred. No per-lane database '
                               'connection was built. No code in app/ or config/ references '
                               'LIVE_PG_DATABASE. Post-alpha infra change only. Evidence: grep -rn '
                               'LIVE_PG_DATABASE returns no results anywhere in app/ or config/. '
                               'No migration or code change in the 97 commits addresses per-lane '
                               'database wiring. | post-alpha infra; a config change '
                               '(LivePgConnection already takes a conn name), not a refactor'},
                      {'wave': 'W4',
                       'label': 'L2 · Operator/system partials + handshake 409/422 + '
                                'class-isolation fix',
                       'status': 'done',
                       'note': 'e702a43 + 1c6b6d9; a demo peer could enter a production mesh '
                               '(game_mode stripped pre-check)'},
                      {'wave': 'W4',
                       'label': 'L2 · Demo-mesh coordinator + federation reconcile + Matrix red',
                       'status': 'done',
                       'note': '32360c0 + 56f137a'},
                      {'wave': 'W4',
                       'label': 'L2 · SUITE STEWARD — the 1343/0 green gate + audit-lock diagnosis',
                       'status': 'done',
                       'note': 'validated the SUITE-TOKEN / quiet-window procedure'},
                      {'wave': 'W5',
                       'label': 'L3 · Committees — rank-ordered assignment + hearings → working',
                       'status': 'done',
                       'note': 'VERIFIED 2026-09-05: DONE: F-SPK-005 assignment run is the engine '
                               'handler (CommitteeAssignmentAdministration::handle runs '
                               'CommitteeAssignmentService::run) with 10 unit pins in '
                               'CommitteeAssignmentTest; hearing lifecycle F-CHR-005 open / '
                               'F-CHR-006 adjourn built (c9477336) and driven end-to-end through '
                               'the real room routes in CommitteeHearingExitWalkTest (open, raise '
                               'hand, recognize, testify, advance, adjourn with sealed minutes; '
                               'non-chair refused). Live box holds 0 committees, 0 '
                               'committee_seats, 0 legislature_members; mass committee creation is '
                               'a Wave 6 Step 4 item (committees as system acts), not this row. | '
                               'verify/complete the F-SPK-005 assignment run + the keystone '
                               'hearing path end-to-end; pin it (currently the capability is '
                               'partial)'},
                      {'wave': 'W5',
                       'label': 'L3 · Amendment workflow (R-C) end-to-end → working',
                       'status': 'done',
                       'note': 'VERIFIED 2026-09-05: DONE: pinned end-to-end in '
                               'AmendSettingLoopTest (c62e3c23): F-LEG-031 filed → '
                               'BillService::moveToFloor → all-yes floor vote → '
                               'constitutional_settings election_interval_months 60→48, '
                               'setting_changes ledger row with law_id, SettingsResolver reads 48; '
                               'out-of-range refused at filing. Front door GET /system/amendments '
                               '(AmendmentsController::show reads the setting_changes ledger). '
                               'Live box holds 0 setting_changes rows; no amendment has run on the '
                               'box. | propose → supermajority chamber vote → APPLY a '
                               'constitutional setting through the real act pipeline; '
                               'system/amendments is the front door; pin the loop'},
                      {'wave': 'W5',
                       'label': 'L3 · Executive delegation → conversion to directly-elected → '
                                'working',
                       'status': 'done',
                       'note': 'VERIFIED 2026-09-05: DONE: the dual-supermajority conversion is '
                               'pinned end-to-end through the engine (F-LEG-015 propose → '
                               'exec_office_create supermajority chamber vote from PROTECTED '
                               'ConstitutionalValidator → MultiJurisdictionVote constituent leg; a '
                               'failed leg reverts with no election; a chamber with no '
                               'constituents converts and schedules the election with trigger '
                               'conversion_act and a 5-seat exec_committee race). Delegation '
                               'F-LEG-014 pinned engine-filed end-to-end. Pins are Phase D vintage '
                               '(4af359b6, e844c7d3); the desk closed the cap at eb8910d1. Not '
                               'pinned: the post-election flip to status=elected (the test ends at '
                               'STATUS_CONVERSION_VOTED plus a scheduled election). Live box: 0 '
                               'elections. | the dual-supermajority conversion path (Art. III); '
                               'verify + pin end-to-end'},
                      {'wave': 'W5',
                       'label': 'L3 · Appointed/elected courts (equal-per-constituent) → working',
                       'status': 'done',
                       'note': 'VERIFIED 2026-09-05: DONE: appointed default, '
                               'equal-per-constituent nomination, judicial/civil lockstep, '
                               'appointed creation seating a 10-year bench, and the '
                               'dual-supermajority elected conversion (failed leg reverts to '
                               'appointed; solo chamber converts) are pinned in '
                               'JudiciaryCreationConversionTest (11 tests); the desk closed the '
                               'cap at 565da663. The courtTiers tail is separate: f4c1a012 ruled '
                               'RETIRE, and InstitutionScaleService::courtTiers still exists with '
                               '5 pins and zero app callers (see addition). Wave 6 bench law '
                               "sizing was not read for this row. | rides L4's "
                               'courtTiers=tree-depth reframe; verify the appointed default + the '
                               'elected conversion; pin'},
                      {'wave': 'W5',
                       'label': 'L3 · Agenda per-item schema — build (keystone)',
                       'status': 'next',
                       'note': 'VERIFIED 2026-09-08 (next): Still unbuilt. '
                               'LiveRoomController.php:164-174 explicitly notes the schema gap: no '
                               'per-item status column exists. No migration for per-item agenda '
                               'rows was added. The advance() method only yields the floor. '
                               'Evidence: '
                               'app/Http/Controllers/Rooms/LiveRoomController.php:164-174: '
                               "advance() comment reads 'The agenda is a plain string list with no "
                               'per-item status column ... a schema question — FLAGGED, not '
                               "written'. Last commit to this file is 0f6ea403, not in the "
                               '97-commit range. No agenda migration appears in '
                               'database/migrations/. | VERIFIED 2026-09-05: still unbuilt; the '
                               "migration slot was granted by the desk (c97dacb9), so the 'flag "
                               "the desk' step is finished. Today committee_meetings.agenda is a "
                               'jsonb string list and LiveRoomController::advance() only yields '
                               "the floor (comment: 'a schema question — FLAGGED, not written'). "
                               'Needs a real-dated additive migration (>= 2026-07-05) for per-item '
                               'rows with status, plus an advance() that walks them. | the '
                               'deferred keystone debt; flag the desk for the migration slot; '
                               "completes the Live Civic Room's agenda"},
                      {'wave': 'W4',
                       'label': 'L3 · Type B COUNTING (per-clump + per-child) + keystone exit-walk '
                                '+ Niue void',
                       'status': 'done',
                       'note': '6f85d322 + 8ec50402 + b9ea6d6 + 8bcb3ee; ① green on every axis'},
                      {'wave': 'W4',
                       'label': 'L3 · Service formula + electoral partials + liveAggregate + '
                                'oversight + pollers',
                       'status': 'done',
                       'note': '37b7a64 · f333eba · 4a118e74 · 4057b3c · 2/4 pollers'},
                      {'wave': 'W5',
                       'label': 'L4 · Q4a — reframe courtTiers = jurisdiction tree-DEPTH (doc + '
                                'provisioning)',
                       'status': 'done',
                       'note': 'VERIFIED 2026-09-05: Verify-and-close landed 2026-07-29 (3e1cce1f '
                               'doc reframe, f4c1a012 RETIRE ruling). Doc states courtTiers = '
                               'tree-depth and rooms = infrastructure (SERVICE_SCALE_FORMULA.md '
                               'sec 4.3). Runtime enforces one live court per jurisdiction '
                               '(judiciaries_jurisdiction_live_uq on the box; '
                               'social_spaces_jur_type_unique in the dump). Provisioner mints one '
                               'flat court per jurisdiction and never reads courtTiers. L3 courts '
                               'capability pinned working. Residual: the dead courtTiers(tier) '
                               'static and its test pins still exist; retire execution sits with '
                               'lane 3. | operator ruling A: Live Rooms/squares are INFRASTRUCTURE '
                               '(a courthouse has many court rooms), NOT a court-as-jurisdiction. '
                               "NEVER weaken the uniqueness constraints. No schema. Unblocks L3's "
                               'courts capability'},
                      {'wave': 'W4',
                       'label': 'L4 · Atlas (front door) + service-formula provisioning + growth '
                                'dial AUTONOMOUS',
                       'status': 'done',
                       'note': '8d03a19/3b9ff99 · 331271b · f9161f5/74603d4/2ad8b1b; '
                               'SimGovernanceWiringTest 4/4'},
                      {'wave': 'W4',
                       'label': 'L4 · R-A un-flag + per-child/per-clump sim fielding (+ 2 real '
                                'defects fixed)',
                       'status': 'done',
                       'note': '64c2a27 fielding + 3976919 rosterSize + 80b5e3a per-clump '
                               'end-to-end fielding pin'},
                      {'wave': 'W5',
                       'label': 'L5 · i18n finish — fr/pt shell chrome',
                       'status': 'done',
                       'note': 'VERIFIED 2026-09-05: Option A ruled and executed 2026-07-29. '
                               '7e9b7c06 records i18n=A at the Wave 5 launch. 6c15a27a reverts the '
                               'fr/pt shell-chrome catalogs to the English fallback (17 files, '
                               '1,179 deletions). b0c166c6 records L5 i18n CLOSED. On disk today '
                               'resources/js/i18n/locales/fr and /pt lack c_shell, c_shellv2, '
                               'chrome and registry; es, ar, hi and zh-Hans carry them. Fallback '
                               "default ['en'] at resources/js/i18n/index.js:114. No raw NLLB nav "
                               'ships. The reader/stronger-model chrome pass is tracked as '
                               'deferred debt, not fleet work. | 6-locale page bodies already '
                               "render. OPERATOR'S CALL: (a) accept English chrome for the alpha "
                               'playtest [desk rec — the chrome polish is cosmetic + rail-held] '
                               'and mark i18n playtest-adequate, or (b) a reader/stronger-model '
                               'pass on the chrome. Never ship raw NLLB nav'},
                      {'wave': 'W4',
                       'label': 'L5 · Video player /videos + flows extraction + zh-Hans + '
                                'translation-home',
                       'status': 'done',
                       'note': '2fea981 (app-ported, 77-lang); adversarial review fe5ad51'},
                      {'wave': 'W5',
                       'label': 'L6 · Messaging trio → built (groups-home · group-create · '
                                'group-detail)',
                       'status': 'done',
                       'note': 'VERIFIED 2026-09-05: BUILT in one atomic commit b12d0582 '
                               '(PrivateRoomController.php, PrivateRoom.vue, '
                               'PrivateRoomCreate.vue, PrivateRooms.vue, routes/web.php, '
                               'tests/Feature/MessagesInboxTest.php). v3 bubble chrome, real '
                               'last-message previews (Matrix read, null when down), unread / '
                               'live-now / DM-vs-group kind honest-empty. Pages sit behind auth '
                               'and the setup lock on the live box today. | ONE atomic commit over '
                               'the shared PrivateRoom files (splitting = merge conflict); v3 '
                               'bubble chrome + last-message previews; unread / live-now / '
                               'DM-vs-group persisted-kind stay honest-empty (no source table)'},
                      {'wave': 'W5',
                       'label': 'L6 · bill.html → built (constitutional path)',
                       'status': 'done',
                       'note': 'VERIFIED 2026-09-05: BUILT in commit 9f6a2ab3: '
                               'BillConversationController + BillConversation.vue at GET '
                               '/bills/{bill}/conversation (public read) and POST '
                               "/bills/{bill}/comments. The mockup's per-party accept is "
                               'deliberately not built (Art. V §3); text changes only through '
                               'committee_amendment / floor_amendment versions the chamber votes '
                               "on. Comments ride the bill's auto-bound hall subforum through "
                               'F-SOC-001 with subforum_id. No summary column; real current text '
                               'or honest-empty. Page sits behind the setup lock on the live box '
                               "today. | a bill as a conversation on motion kind='amendment' + "
                               "chamber vote (NOT the mockup's per-party accept = an Art. V §3 "
                               'violation the code rejects); comments ride the 8 bill-bound '
                               'subforums; summary honest-empty; coordinate HallsController '
                               'subforum_id passthrough with L3'},
                      {'wave': 'W5',
                       'label': 'L6 · Atlas a11y review pass (findings → lane 4)',
                       'status': 'done',
                       'note': 'VERIFIED 2026-09-05: DONE in commit 9f0f5e9b: '
                               'docs/plans/ui/L6W5_ATLAS_A11Y_FINDINGS.md (66 lines), a review of '
                               'resources/js/Pages/System/Atlas.vue, not a rebuild. One medium '
                               'finding (F1 domain-card heading/region) plus low findings, handed '
                               'to lane 4. Lane 4 has not applied F1 as of HEAD. | the optional '
                               'pass, now in scope; findings-to-L4, not a rebuild'},
                      {'wave': 'W4',
                       'label': 'L6 · Civic 6/6 + tour 47→60 + 4 unplanned bugs + org-profile + '
                                'social-home restore',
                       'status': 'done',
                       'note': "every 'missing panel' was live data discarded by a collapsing "
                               'query — no schema'},
                      {'wave': 'W5',
                       'label': 'L13 · board-elections → built',
                       'status': 'done',
                       'note': 'VERIFIED 2026-09-05: Built. Page '
                               'resources/js/Pages/Organizations/BoardElections.vue (429 lines) '
                               'served by BoardElectionController::show at GET '
                               '/organizations/{organization}/board-elections '
                               '(routes/web.php:1026-1029); owner track F-ORG-003, worker track '
                               'F-ORG-004 (controller lines 113, 135). Governmental boards (BoG) '
                               'are appointed by nomination + consent, not elected: F-EXE-001 at '
                               '/departments/{department}/nominations (routes/web.php:964-969). '
                               'Wave 5 commit d791a38a (2026-07-29) moved the surface partial → '
                               'built and pinned it in tests/Feature/PhaseDPageSmokeTest.php. | '
                               'org + governmental board elections surface (prop-fill over the '
                               'existing board module; verify tables via information_schema '
                               'first)'},
                      {'wave': 'W5',
                       'label': 'L13 · org-registry → built',
                       'status': 'done',
                       'note': 'VERIFIED 2026-09-05: Built. Page '
                               'resources/js/Pages/Organizations/Registry.vue (381 lines) served '
                               'by OrganizationController::index at GET /organizations '
                               '(routes/web.php:994); POST /organizations files F-IND-012 through '
                               'the ConstitutionalEngine (OrganizationController.php:145-155, '
                               'routes/web.php:996). Wave 5 commit d791a38a added the '
                               "monopoly-conversion flag, the 'Endorsing only' chip and the 'Start "
                               "a club' F-IND-012 shortcut and moved the surface partial → built. "
                               '| F-IND-012 org registration surface; the founder-stake path '
                               'already lands here (stock orgs)'},
                      {'wave': 'W5',
                       'label': 'L13 · CHECK(balance>=0) on economic_accounts (defense-in-depth)',
                       'status': 'deferred',
                       'note': 'VERIFIED 2026-09-08 (deferred): Deferred. No CHECK(balance>=0) '
                               'constraint exists in any migration or schema file. The app-layer '
                               'row-lock defense is still the only overdraft guard. Evidence: '
                               'database/migrations/2026_07_25_000030_create_economy_plane.php:160-161 '
                               'adds only kind_check and status_check constraints on '
                               'economic_accounts. No CHECK(balance>=0) in any migration file or '
                               'schema dump. | optional; if it takes the migration slot — the '
                               'app-layer buyer-account row lock already closes the overdraft '
                               'hole'},
                      {'wave': 'W4',
                       'label': 'L13 · 12 economy partials + founding-stake + secondary trading + '
                                '8 adversarial fixes',
                       'status': 'done',
                       'note': 'F-IND-021 mint (117→118); e8c2884 hardened concurrency/integrity'},
                      {'wave': 'W5',
                       'label': "L15 · Arm the game box — education:seed (operator's trigger)",
                       'status': 'held',
                       'note': "VERIFIED 2026-09-08 (held): Held for the operator's trigger. The "
                               'education:seed command is built at '
                               'app/Console/Commands/EducationSeedCommand.php:41. No trigger was '
                               'issued in the commits since 563c4899. Evidence: '
                               'app/Console/Commands/EducationSeedCommand.php:41 confirms '
                               'education:seed exists with --force and --no-pretrain options. No '
                               'commit in the 97-commit range shows the operator triggered arming '
                               'on the game box. | pre-train arming is built (seeders file '
                               'F-EDU-001, then education:seed); seeds the training gate on the '
                               'game box when the operator is ready to walk. Do NOT seed the dev '
                               'box'},
                      {'wave': 'W4',
                       'label': 'L15 · Pre-train arming + profile-edit + p2p DM + '
                                'journey/social-home slices',
                       'status': 'done',
                       'note': 'c47b50f · ac72ad9 · 663bf08/47d8d57'},
                      {'wave': 'W4',
                       'label': 'L14 · HELD by the operator',
                       'status': 'held',
                       'note': 'VERIFIED 2026-09-08 (held): Operator hold is active. Foundation = '
                               '501(c)(3) parent, Coalition = project child, Action Fund = do not '
                               'build. No code built and no relevant commits since 563c4899. '
                               'Evidence: Same hold as row 099 (i=7). No Phase J code found '
                               'anywhere in the codebase. No commits since 563c4899 address this. '
                               '| Foundation = 501(c)(3) parent; Coalition = a PROJECT (child); '
                               'Action Fund = DO NOT BUILD. Resumes on his word.'}]}]}
