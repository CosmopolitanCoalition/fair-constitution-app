# -*- coding: utf-8 -*-
# THE DESK WORK LIST (operator order 2026-09-05): one lane, the Desk, working Waves W6..W11 in order to the conference demo.
# Waves 1-5 are history. Each wave is one ordered batch; each item names its ruling, its evidence path and the test step closes the wave.
# Box E is the development and test box: items describe code to write, never the state of box E; one-off box repairs sit outside the waves.
# item status: next | done | held (operator-gated) | deferred | superseded

FLEET = {
  "waves": [
    {
      "id": "W1",
      "name": "Shell · demo · learn",
      "status": "done"
    },
    {
      "id": "W2",
      "name": "~22 screens · parity UIs · forms→113",
      "status": "done"
    },
    {
      "id": "W3",
      "name": "Type B mapper · keystone · K-2 · economy · coordinator · tour · forms→117",
      "status": "done"
    },
    {
      "id": "W4",
      "name": "To GREEN: Type B race fix · screen+capability closes · debt paydown",
      "status": "done"
    },
    {
      "id": "W5",
      "name": "The finish: 107/107 screens · courts reframe · re-gate ruled away · 2026-09-05 map runs done",
      "status": "done"
    },
    {
      "id": "W6",
      "name": "Step 4 engine and page",
      "status": "next"
    },
    {
      "id": "W7",
      "name": "Step 5 simulation at planet scale",
      "status": "next"
    },
    {
      "id": "W8",
      "name": "Step 6 and the public read-only world",
      "status": "next"
    },
    {
      "id": "W9",
      "name": "Fresh cloud build and the demo mesh",
      "status": "next"
    },
    {
      "id": "W10",
      "name": "Demo polish",
      "status": "next"
    },
    {
      "id": "W11",
      "name": "Live mesh: beta.worldofstatecraft.org readiness",
      "status": "next"
    }
  ],
  "lanes": [
    {
      "id": "W6",
      "name": "Step 4 engine and page",
      "status": "next",
      "items": [
        {
          "wave": "W6",
          "label": "1 · Wizard ladder 0 to 6 and Step 3 Continue",
          "status": "next",
          "note": "Ruled wizard-ladder A, done-flip-vs-pages A. Extend the route range and the stepper to 0..6; keep the counter convention (n done, next is n). Routing rule: the scale choice and the simulate choice made at acceptance decide whether Steps 4 and 5 open; otherwise skip to Step 6. Add the Continue control on Step 3 (today only the mapper banner posts step3/complete: resources/js/Pages/Legislature/Districts.vue:1763-1776). Fix the stepper off-by-one and the undefined deferred field (resources/js/Components/SetupStepper.vue:17-22, 41-51). Evidence: app/Http/Controllers/SetupController.php:97-129, 2713-2725."
        },
        {
          "wave": "W6",
          "label": "2 · Step 4 engine: per-legislature ledger, pump, lanes on the long lane",
          "status": "next",
          "note": "The districting posture applied to institutions (apportionment_ledger + app/Support/AutoscaleClaims.php + AutoscalePumpCommand + HostCapacity as the template). One row per legislature; claim ladder ordered by cost from both ends; halt / resume / requeue / ROLLBACK (a revert like autoscale:revert); per-step and per-lane bars with elapsed and ETA on the Step 4 page. Lanes ride the long lane (config/queue.php:85-92). Remove the done-flip dispatch (app/Console/Commands/AutoscalePumpCommand.php:499-512) and the Finish dispatch (SetupController.php:3608); retire ProvisionInstitutionsJob as the planet driver (app/Jobs/ProvisionInstitutionsJob.php:20-41, default queue, retry_after 90 s)."
        },
        {
          "wave": "W6",
          "label": "3 · Shell steps as lane work + the money plane founding",
          "status": "next",
          "note": "Reuse the five set-based, idempotent, live-unique SQL steps in app/Services/InstitutionProvisionService.php:122-230 (executives, judiciaries, election boards, board members, civic spaces) as lane units. Add: one public treasury per jurisdiction and the world currency + root treasury founded at setup (today nothing outside the demo seeder mints the Currency row; treasury_accounts has no service and no provisioning step). Evidence: app/Services/Economy/*, migration 2026_07_25_000002_institution_live_uniqueness.php."
        },
        {
          "wave": "W6",
          "label": "4 · THE BENCH LAW, one quorum formula, one seat floor",
          "status": "next",
          "note": "Ruled bench-scaling-law B + bench-and-quorum-law A. bench = max(judiciary_min_judges_per_race, next odd >= Type A seats / 10); courts with n constituents take it as the minimum multiple (judges per constituent = ceil(min / n)). Replace the 5/7/9 bands and GREATEST(5, …) in InstitutionProvisionService.php:347-354, the stub's 5 in InstitutionStubService.php:86, and the reads in the sim's JudiciaryStage. Stored quorum capped at the seat count in every writer (ActivationService.php:241-251 is the model; ApportionmentSeedCommand.php:345, TypeBDistrictMapper.php:1425, TypeBMapController.php:1042,1186, AutoscaleResizeRepairCommand.php:54,113 are uncapped). Seat floor from the jurisdiction's own settings row in every sizer (ActivationService.php:81, WorldBuildJob.php:84). Retire InstitutionScaleService::courtTiers (ruled RETIRE f4c1a012). Pin with tests."
        },
        {
          "wave": "W6",
          "label": "5 · Seat lane: one election and its races per legislature",
          "status": "next",
          "note": "Ruled seat-mint-owner A, zero-seat-chambers A. A lane calls ElectionLifecycleService::scheduleGeneral (app/Services/ElectionLifecycleService.php:136, racePlan :576, createRaces :849) per legislature after its board lands. Skip zero-seat chambers (scheduleGeneral would keep an orphan row: :151-179). Fix the zero-population bin under the sub-2 rule (app/Services/DistrictingService.php:6556-6564) so no district carries seats = 0. Add the live-unique index on legislatures(jurisdiction_id) (read-then-insert at ApportionmentSeedCommand.php:337-372). Elections pin the constitutional version at creation (app/Models/Election.php:107-111): no hardened-file deploy between scheduling and certification. Type B seat bands: align the validator's payload path (ConstitutionalValidator.php:685-697) with the generator (2..4 lawful)."
        },
        {
          "wave": "W6",
          "label": "6 · Committees and departments as system acts",
          "status": "next",
          "note": "Ruled sub-institutions-path B. Engine change: a system-actor filing path for F-LEG-009 and F-LEG-016 that records the act and skips the chamber vote. The lane fills committees to K(S) = clamp(round(3.5 + 2.7 ln S), 1, round(S/5)) and departments to D(P) = clamp(round(-7.8 + 1.67 ln P), 3, 30), the five mandatory kinds first (app/Services/InstitutionScaleService.php:175-210; app/Services/Demo/Stages/GovernanceStage.php:142, 243 show the vote path). Check whether F-LEG-016 mints the department board and governor seats (app/Services/Executive/DepartmentService.php:164-173)."
        },
        {
          "wave": "W6",
          "label": "7 · Rooms and subforums for the sculpted world",
          "status": "next",
          "note": "Civic spaces (public square, halls) are in the shell set. Matrix rooms and subforums come only from the nightly EvaluateSocialStructureJob for legislatures with status active (app/Jobs/EvaluateSocialStructureJob.php:34-79) and per-institution rooms reconcile on first view. Decide in build: the Step 4 lane reconciles rooms for every provisioned place, or the first view does. Register the unregistered settings keys (type_b_seats_per_child, activation tier, critical population) in SettingsController::REGISTER_KEYS and the validator bounds so acts can set them."
        },
        {
          "wave": "W6",
          "label": "8 · Pins for the Step 4 engine",
          "status": "next",
          "note": "No test references institution_scale_mode, simulate_at_scale, the provisioning engine, the seat lane or the wizard state machine; AutoscalePinTest skips itself above 10,000 jurisdictions (tests/Constitutional/AutoscalePinTest.php:844-852). Land pins with the build: mode selection, ledger claims, halt/resume/rollback, seat lane skip rule, system acts, bench law, wizard routing."
        },
        {
          "wave": "W6",
          "label": "9 · TEST: iterative Step 4 runs on box E to the full planet, then lock",
          "status": "next",
          "note": "Ruled sim-scope B. Run Step 4 from the wizard page on box E in iterations with halt, resume and rollback until the full planet lands. Record the measured duration as the baseline (never assume). Lock the result. Box E counts (executives 923,093; judiciaries 400,000; boards 1) are the starting state of the test box, not the specification."
        }
      ]
    },
    {
      "id": "W7",
      "name": "Step 5 simulation at planet scale",
      "status": "next",
      "items": [
        {
          "wave": "W7",
          "label": "1 · Sim worker heartbeat during an item",
          "status": "next",
          "note": "Item and lease rows are touched only at claim and settle (app/Support/SimClaims.php:121-123; app/Jobs/SimWorkerJob.php:124-160). An item over 30 min is reclaimed and re-executed; over 2 min the pump seeds a replacement each minute; over 10 min the lease is culled (SimPumpCommand.php:110-113, 148-160, 402-414). Copy the districting lane's heartbeat connection (app/Support/HostCapacity.php:57)."
        },
        {
          "wave": "W7",
          "label": "2 · Resume without OFFSET; stored options; one run selection",
          "status": "next",
          "note": "sim:start --resume stops at the first empty chunk and re-enumerates with the command-line adm-max/limit (app/Console/Commands/SimStartCommand.php:56-59, 112-113, 179-239). Use NOT EXISTS + LIMIT with no OFFSET like the pump's mintWorklist; read the run's stored options; make --resume, the pump and SimRunControl agree on which run is active (SimRunControl.php:83-89 vs SimStartCommand.php:81-83)."
        },
        {
          "wave": "W7",
          "label": "3 · The three empty phases and sim:revert",
          "status": "next",
          "note": "enumerating, profiling and verifying are declared in SimRun::PHASES with no stage and no mint (app/Models/SimRun.php:40-88; SimPumpCommand.php:329, 422-460). Implement a verifying acceptance scan or remove the phases. Build sim:revert: remove one run's rows (cohorts, sim users and residencies, candidacies, tabulations, seated members, governance and judiciary acts, civics rows) so a run can repeat; it is named in four files and absent."
        },
        {
          "wave": "W7",
          "label": "4 · Gate on the locked Step 4 result; adopt the Step 4 election",
          "status": "next",
          "note": "ElectionStage settles done with no election when a jurisdiction has no active board (app/Services/Demo/Stages/ElectionStage.php:96-109) and calls scheduleGeneral itself (:113). After W6 the elections exist: ElectionStage adopts the open election and fields candidates; JudiciaryStage needs the forming court (JudiciaryStage.php:83-89); the counting mint takes only this run's election (SimPumpCommand.php:213-233). Certification flips chambers forming → active (CertificationService.php:1003-1020); the sim's governance stage delegates executives (serving > 5) and its judiciary stage carries courts forming → operating: re-verify both at planet scale in this wave."
        },
        {
          "wave": "W7",
          "label": "5 · Claim order per row",
          "status": "next",
          "note": "position = the chunk offset, so largest-first holds only per 25,000-row band and a smoke run claims in uuid order (SimClaims.php:120-136; SimStartCommand.php:189-207). Write a per-row position."
        },
        {
          "wave": "W7",
          "label": "6 · Step 5 page",
          "status": "next",
          "note": "Rendered only when the world is synthetic-safe. Simulate data? yes / no. Options: turnout, adm-max, limit, scope (pass --jurisdiction through SimRunControl::cliOptions, which drops it today: SimRunControl.php:253-274). Stage bars, workers, live and review items reused from /simworld; halt / resume / rollback. Gate: refuses until Step 4 is locked (boards, courts, seats present)."
        },
        {
          "wave": "W7",
          "label": "7 · Training gate armed as a setup system act; sim actors trained",
          "status": "next",
          "note": "The gate arms only when an operator runs education:seed by hand with a confirm prompt; the box holds 0 tracks and 0 modules. Sim stages file gated forms and catch the refusal as a half result. Publish the catalog as a system act in Step 4 or Step 5 and pre-train sim actors between the seating and governance stages (SeatedMemberTrainingService plucks every seated id into memory: chunk it)."
        },
        {
          "wave": "W7",
          "label": "8 · Resident wallets and the stipend pass (money plane)",
          "status": "next",
          "note": "AccountService::open is idempotent and per-owner but no job, stage or step calls it; the stipend run is one unbounded transaction with a per-recipient loop and a single demo-command caller. Open wallets for the cohort in the identity stage; run the stipend as a chunked, resumable, clock-armed pass."
        },
        {
          "wave": "W7",
          "label": "9 · Organizations and workers at demo scale",
          "status": "next",
          "note": "CivicsStage mints parties, nonprofits, businesses and bills census-flavoured (app/Services/Demo/Stages/CivicsStage.php:109-219). Co-determination, org board elections, the CGC register and org endorsements have no subject on a fresh world until civics runs; verify the civics output feeds them."
        },
        {
          "wave": "W7",
          "label": "10 · Console fixes",
          "status": "next",
          "note": "The 2 s poll runs planet-wide counts (~75 s per the parity test): serve a cached snapshot per minute like Step 3. Labels for governance, judiciary and civics kinds; workers target minus live autoscale lanes; the not-scale_demo banner beside a working Start (app/Http/Controllers/Demo/SimConsoleController.php:56, 140, 192-214, 285-394; resources/js/Pages/Demo/SimConsole.vue:181-218)."
        },
        {
          "wave": "W7",
          "label": "11 · TEST: full-planet run on box E with halt, resume, rollback, then lock",
          "status": "next",
          "note": "Ruled sim-scope B. Measure, do not assume: the serial audit lock is about 28.6 appends per second (app/Services/TabulationRecorder.php:273-280 comment). Repeat with sim:revert until the run is trustworthy. Lock the result."
        },
        {
          "wave": "W7",
          "label": "12 · The Niue walk: the first seated chamber (operator trigger)",
          "status": "held",
          "note": "Held for your trigger: a live Type B election electing 10 seats across 5 panels (tests/Constitutional/ElectionStageTest.php:201, commit 80b5e3a). Precondition: Niue's board and seats from W6. Your standing note: no manual walk until all green."
        }
      ]
    },
    {
      "id": "W8",
      "name": "Step 6 and the public read-only world",
      "status": "next",
      "items": [
        {
          "wave": "W8",
          "label": "1 · Step 6: Confirm and Close",
          "status": "next",
          "note": "Move today's Step4_Confirm.vue to Step 6. Precondition: Steps 4 and 5 settled (or recorded as running in background, ruling subtree-activation-shape B+). Idempotency guard on re-POST (step() has no completion redirect: SetupController.php:97-121, 3596-3633). Audit entry for the founding window closing. CLI twin setup:complete (no CLI writes setup_completed_at today). No dispatch. Page copy: remove 'Phase 2' and 'a planet takes minutes'."
        },
        {
          "wave": "W8",
          "label": "2 · Read-only instance flag, engine and endpoint refusal, operator toggle, banner",
          "status": "next",
          "note": "Ruled read-only-lock A. A new instance flag, operator-toggled with an audit entry. A visitor can assume a role and act through the UI; the ConstitutionalEngine and every write endpoint (economy 14 doors, federation ~20 doors, identity, orgs) discard the change and say so; registration closes; the sim and clock pumps pause; reads and walking stay open. Shell banner: this world is read-only; your changes are shown and not saved."
        },
        {
          "wave": "W8",
          "label": "3 · Every read surface public",
          "status": "next",
          "note": "Ruled public-walk A. The setup allow list (app/Http/Middleware/RedirectIfSetupIncomplete.php:34) and the auth groups block reads today: /simworld and /api/simworld/progress (routes/web.php:687-702), /building (280-282), the 13 economy routes, /executives and /departments, the constitutional-challenge index and show, committees / referendums / emergency-powers indexes, /learn and /support, the federation console, bill and committee detail. Open the reads; keep the drives behind the operator guard and the lock."
        },
        {
          "wave": "W8",
          "label": "4 · Close the open write doors",
          "status": "next",
          "note": "POST /api/import/jurisdictions has no auth and runs pg_restore (routes/web.php:432; JurisdictionController.php:630-667); pull-option, pull-control and deploy-package lack the operator check (SetupController.php:1512-1528, 1798-1922, 4249-4266); the join API has no user check (:332-346); /register is on the allow list. All carry auth and the operator check; all refuse under the lock."
        },
        {
          "wave": "W8",
          "label": "5 · TEST: guest walk on box E under the lock",
          "status": "next",
          "note": "Sign out. Walk every read surface as a guest, including the sim console. Attempt writes as a guest and as an assumed role: every write is refused with the message and nothing persists."
        }
      ]
    },
    {
      "id": "W9",
      "name": "Fresh cloud build and the demo mesh",
      "status": "next",
      "items": [
        {
          "wave": "W9",
          "label": "1 · CLI parity for the fresh build",
          "status": "next",
          "note": "maps:accept skips the world-build verifier gate and the mode and simulate writes and creates the run as queued (app/Console/Commands/MapsAcceptCommand.php:74-126 vs JurisdictionController.php:699-905). The restore-from-backup branch stamps map_accepted_at with no run, no world build and no scale mode (app/Services/MapDataImportService.php:164-168): stamp them from the bundle or block the branch. setup:complete from W8."
        },
        {
          "wave": "W9",
          "label": "2 · Fresh cloud build on the Azure box at 96 cores",
          "status": "next",
          "note": "Ruled demo-mesh-host (your plan). Run Steps 0 to 6 end to end through the wizard on the Azure box scaled to 96 cores. Record a measured baseline per step. Take the measurements you ruled to take there: boot-time prewarm cost (docker/php/entrypoint.sh:109-136), the clock job and its three sweeps (routes/console.php:26, 136; app/Jobs/EvaluateClocksJob.php:55-70). Confirm no zero-seat leaf chambers appear (leaf seeding needs population > 0)."
        },
        {
          "wave": "W9",
          "label": "3 · Scale down; Europe mirror; DNS and TLS",
          "status": "next",
          "note": "Ruled europe-node-identity B. Scale the Azure box down. Mirror-join a node hosted in Europe; the Azure box stays authoritative and online 21 to 23 September. DNS and TLS for demo.worldofstatecraft.org (the read-only simulation) and beta.worldofstatecraft.org (the writable instance, W11)."
        },
        {
          "wave": "W9",
          "label": "4 · TEST: smoke walk on the mirror",
          "status": "next",
          "note": "Guest walk on demo.worldofstatecraft.org under the lock. Compare counts with the Azure box. Record the sync lag."
        }
      ]
    },
    {
      "id": "W10",
      "name": "Demo polish",
      "status": "next",
      "items": [
        {
          "wave": "W10",
          "label": "1 · Re-verify the data-dependent screens and capabilities with data",
          "status": "next",
          "note": "After W7 the world holds elections, ballots, members, committees, departments, courts and cases. Re-grade: candidacy, election detail, approval and ranked ballots, results, vacancy countback, department detail and reporting, your courts; Type A and Type B races, secret ballot, endorsements, bills, votes, bicameral, referendums, petitions, emergency powers, amendments, executive delegation and orders, board elections, courts, cases, double jeopardy, Art. IV §5, CGC register, exchange, currency telemetry, live civic rooms (the chamber session route is missing: LiveRoomController exposes committee only), civic square."
        },
        {
          "wave": "W10",
          "label": "2 · Learn content publication and the nav rows",
          "status": "next",
          "note": "Publish the education catalog (config; only education:seed publishes it; the box holds 0 tracks). Add the missing nav rows for the video library and the translation board. Learn, lesson and video pages then render content instead of empty states."
        },
        {
          "wave": "W10",
          "label": "3 · Copy and control fixes on the wizard and the viewer",
          "status": "next",
          "note": "Step 1: 'clamped to [min, max]' (Step1_Constants.vue:373-375). Step 2: population-mode caption 5–9 vs the actual threshold, section numbering 1, 2, 2, 2, 4, unbound controls (country scope, fresh, skip population, pause on exception) and the engine ref fixed to pull (Step2_MapData.vue:77-124, 765-1324, 1444). Step 3 engine controls: requeueDriftMaps surface, the grind shunt config key, the two doors that bypass AutoscaleRunControl, controls on the newest run vs the pump on the oldest. Reach: the 200-row jurisdiction picker with no search; the unindexed leading-wildcard search on Say where you live; Public records and Term lockstep unbounded chamber lists."
        },
        {
          "wave": "W10",
          "label": "4 · Dead dials",
          "status": "next",
          "note": "time_mode and seconds per year (no reader); cga.critical_population_default and cga.activate_subtree_batch (no reader); cga.autoscale_adm_max (stamp only); instance_class and population_binding (no writer); courtTiers and extraRooms (no caller); cohort clusters and candidacy priors (never read). Remove or wire each."
        },
        {
          "wave": "W10",
          "label": "5 · Atlas a11y findings and the screenshot debt",
          "status": "next",
          "note": "docs/plans/ui/L6W5_ATLAS_A11Y_FINDINGS.md: one medium (domain cards need h3 / region) and three low. Capture the owed after-screenshots at review time."
        },
        {
          "wave": "W10",
          "label": "6 · Docs",
          "status": "next",
          "note": "CLAUDE.md protected files list ElectionTriggerService.php which does not exist (also ConstitutionalVersionService.php:33); the scheduler container fc_scheduler (docker-compose.yml:503-526) is absent from the service table; config/horizon.php:257-260 describes the retired lane law; SetupController.php:42, 3858 name the retired AutoscaleOrchestratorJob; EvaluateSocialStructureJob docblock says unscheduled while routes/console.php:113 schedules it."
        },
        {
          "wave": "W10",
          "label": "7 · fr/pt shell chrome (optional)",
          "status": "deferred",
          "note": "Ruled i18n A for the playtest: English chrome. A reader pass on the fr/pt chrome only if time allows; never raw NLLB navigation."
        }
      ]
    },
    {
      "id": "W11",
      "name": "Live mesh: beta.worldofstatecraft.org readiness",
      "status": "next",
      "items": [
        {
          "wave": "W11",
          "label": "1 · Population-mode autoboot (ruled A, 2026-08-29)",
          "status": "next",
          "note": "EvaluateCriticalPopulationJob writes state only; nothing calls ActivationService::activate (app/Jobs/Clocks/EvaluateCriticalPopulationJob.php:120-126; ActivationService.php:262-305). Close the loop: the crossing boots the place (seats, board, founding election). The effective threshold is 1 resident with the tier disabled; Step 2 copy says 5–9."
        },
        {
          "wave": "W11",
          "label": "2 · Subtree boot recovery (ruled B+)",
          "status": "next",
          "note": "Lanes collapse to one after the root wave; a three-strike running row pins the depth barrier; no reset control; FinishActivationsJob schedules elections in every mode on the default queue; the enumeration and the single Activate run inline in the request (app/Jobs/ActivateSubtreeJob.php:62-93; SubtreeBootLaneJob.php:33, 57-78, 114-127; FinishActivationsJob.php:37-56; JurisdictionController.php:949-1028). Demand priority (ruled) does not exist."
        },
        {
          "wave": "W11",
          "label": "3 · Clock engine at planet scale",
          "status": "next",
          "note": "CLK-01 general-election timers armed and fired at scale (500 fires per minute today); CLK-02 meeting deadline armed at seating; the residency, critical-population and petition sweeps and the nightly world stats sized for 940,327 jurisdictions (ruled default-queue-sweeps C: measure first, then act on evidence); standings rollups chunked and resumable (RankedStandingsRollupJob cursors every ranked_open election in one job)."
        },
        {
          "wave": "W11",
          "label": "4 · Federation hardening for the live mesh",
          "status": "next",
          "note": "pushTo ships the whole audit tail in one body (paging exists on the pull side only); the authority-flip manifest binds every descendant id in one statement above the 65,535-parameter limit; the cluster join and leave doors have no operator gate; the console federation toggle sets federation_enabled without minting the identity or arming CLK-20."
        },
        {
          "wave": "W11",
          "label": "5 · beta.worldofstatecraft.org: the writable instance",
          "status": "next",
          "note": "Your plan: a second instance, scaled up and live, where attendees make accounts. Registration open; the read-only flag off; population mode or eager per your choice at acceptance; the live-mesh items above in place."
        },
        {
          "wave": "W11",
          "label": "6 · Economy doors",
          "status": "next",
          "note": "BudgetService::draft and ::enact have no caller and no form; F-IND-023 records a dues payment as a plain transfer; CHECK(balance >= 0) on economic_accounts (deferred, belt and braces); a web door for F-ORG-008 share issuance."
        },
        {
          "wave": "W11",
          "label": "7 · Deferred schema and infrastructure",
          "status": "next",
          "note": "Agenda per-item schema (agenda_items migration not on main); constituent_requests + room read position; the deliberately-not-built social primitives; per-lane LIVE_PG_DATABASE (retire the quiet-window freeze); the mobile app (Capacitor, Phase 6)."
        },
        {
          "wave": "W11",
          "label": "8 · Lane 14 · Coalition Organization (Phase J)",
          "status": "held",
          "note": "Held by the operator: Foundation = 501(c)(3) parent; Coalition = a project (child); Action Fund = do not build. Resumes on his word."
        }
      ]
    },
    {
      "id": "boxE",
      "name": "Box E one-offs (development box alignment; outside the waves)",
      "status": "deferred",
      "items": [
        {
          "wave": "boxE",
          "label": "Box E · delete the two duplicate legislature rows",
          "status": "deferred",
          "note": "Githunguri and Kalmar each hold two identical rows from 2026-08-29 16:05:47. One-off data repair on the test box; the code fix (unique index) is W6 item 5. Do it only if it blocks W6 or W7 testing."
        },
        {
          "wave": "boxE",
          "label": "Box E · redraw the nine active maps with a zero-seat district",
          "status": "deferred",
          "note": "One-off after the W6 rule fix; a fresh build never produces them once the rule changes."
        },
        {
          "wave": "boxE",
          "label": "Box E · finish the shell set",
          "status": "done",
          "note": "Superseded: the W6 engine test run on box E provisions the planet."
        }
      ]
    },
    {
      "id": "history",
      "name": "Waves 1 to 5 (history, by lane)",
      "status": "done",
      "items": [
        {
          "wave": "W5",
          "label": "L1 · Fire the live per-clump Niue general (walk demo)",
          "status": "held",
          "note": "VERIFIED 2026-09-05: Held for the operator's trigger. The fielding pin stands: tests/Constitutional/ElectionStageTest.php:201 test_a_per_clump_chamber_fields_its_election_end_to_end (80b5e3a). Box E today: Niue legislature 96268bbf is forming, 11 Type A + 10 Type B seats, one active grouping of 5 panels / 10 seats; 0 elections for it; 0 election boards for Niue (jurisdiction 056fb95c). ElectionStage refuses with no_election_board when the jurisdiction has no active board (app/Services/Demo/Stages/ElectionStage.php:96-109). Precondition before his trigger: a Step 4 lane seats Niue's board (Wave 6 ruling, institution scale-up). | approved + fielding-pinned (80b5e3a); a live Type B election electing 10 seats across 5 panels for the operator's walk — his trigger"
        },
        {
          "wave": "W5",
          "label": "L1 · Game-box mass pass over ~9,708 flagged chambers",
          "status": "done",
          "note": "VERIFIED 2026-09-05: Done on box E in the 2026-09-05 planet run. The Type B panel scope runs as the last scope of every composite walk (app/Services/Autoscale/SweepScopeProcessor.php:249-261, drawTypeBPanels); the mass CLI type-b:district stays for re-runs (app/Console/Commands/TypeBDistrictCommand.php:51-53). Box E: 0 chambers flagged type_b_needs_districting; 36,810 active groupings (7,506 archived, 29 draft); 475,352 panels. The stale-grouping guard archives the prior active plan before a re-seed (app/Services/Legislature/TypeBDistrictMapper.php:1384-1390). The 9,708 figure counted flagged chambers only; the run grouped every chamber that holds a Type B. | operator-coordinated: pull L1's commits to the game box, run ETL-chunked; the stale-grouping guard protects re-seeds"
        },
        {
          "wave": "W4",
          "label": "L1 · Type B race fix — SEATING (per-clump) + per-child + hardening",
          "status": "done",
          "note": "c500a1f + c96e757 + 1ffde5b; pooled shape fully retired, at_large = type_a-only"
        },
        {
          "wave": "W4",
          "label": "L1 · Niue cleared LIVE + reshape confirmed",
          "status": "done",
          "note": "5 panels/10 seats, both chambers elect; 2 stale elections voided, next mint per-clump"
        },
        {
          "wave": "W4",
          "label": "L1 · Adversarial pass + fixture reds + CHECK + Geodata red",
          "status": "done",
          "note": "5f2293b (2 defects) · a32bbc8 · 220100 · 06d9545"
        },
        {
          "wave": "W5",
          "label": "L2 · Authoritative RE-GATE — full suite, quiet window, after the build lands",
          "status": "done",
          "note": "SUPERSEDED 2026-09-05: Superseded by the operator ruling of 2026-09-02 (answers, not retests): the full php artisan test never runs on the live box as a gate again. The replacement is the DB-free pass (LIVE_PG_DATABASE=cga_absent_test_db, live pins self-skip) plus the targeted live classes for the touched subsystems, named in the report. The W5 re-gate itself never ran: the desk ledger ends at W5 tick 8 with the re-gate still pending, no gate log exists on disk, and no commit after the W5 launch records a suite gate. The W5 arm and walk sequence did not fire either; the Wave 6 rulings replace it with Step 4 scale-up, Step 5 simulation, Step 6 close. | SUITE TOKEN + freeze + storage-chown; confirm ALL-GREEN before the walk. Your triage: own-the-world → audit-lock → real defect"
        },
        {
          "wave": "W5",
          "label": "L2 · Per-lane LIVE_PG_DATABASE (retire the freeze)",
          "status": "deferred",
          "note": "post-alpha infra; a config change (LivePgConnection already takes a conn name), not a refactor"
        },
        {
          "wave": "W4",
          "label": "L2 · Operator/system partials + handshake 409/422 + class-isolation fix",
          "status": "done",
          "note": "e702a43 + 1c6b6d9; a demo peer could enter a production mesh (game_mode stripped pre-check)"
        },
        {
          "wave": "W4",
          "label": "L2 · Demo-mesh coordinator + federation reconcile + Matrix red",
          "status": "done",
          "note": "32360c0 + 56f137a"
        },
        {
          "wave": "W4",
          "label": "L2 · SUITE STEWARD — the 1343/0 green gate + audit-lock diagnosis",
          "status": "done",
          "note": "validated the SUITE-TOKEN / quiet-window procedure"
        },
        {
          "wave": "W5",
          "label": "L3 · Committees — rank-ordered assignment + hearings → working",
          "status": "done",
          "note": "VERIFIED 2026-09-05: DONE: F-SPK-005 assignment run is the engine handler (CommitteeAssignmentAdministration::handle runs CommitteeAssignmentService::run) with 10 unit pins in CommitteeAssignmentTest; hearing lifecycle F-CHR-005 open / F-CHR-006 adjourn built (c9477336) and driven end-to-end through the real room routes in CommitteeHearingExitWalkTest (open, raise hand, recognize, testify, advance, adjourn with sealed minutes; non-chair refused). Live box holds 0 committees, 0 committee_seats, 0 legislature_members; mass committee creation is a Wave 6 Step 4 item (committees as system acts), not this row. | verify/complete the F-SPK-005 assignment run + the keystone hearing path end-to-end; pin it (currently the capability is partial)"
        },
        {
          "wave": "W5",
          "label": "L3 · Amendment workflow (R-C) end-to-end → working",
          "status": "done",
          "note": "VERIFIED 2026-09-05: DONE: pinned end-to-end in AmendSettingLoopTest (c62e3c23): F-LEG-031 filed → BillService::moveToFloor → all-yes floor vote → constitutional_settings election_interval_months 60→48, setting_changes ledger row with law_id, SettingsResolver reads 48; out-of-range refused at filing. Front door GET /system/amendments (AmendmentsController::show reads the setting_changes ledger). Live box holds 0 setting_changes rows; no amendment has run on the box. | propose → supermajority chamber vote → APPLY a constitutional setting through the real act pipeline; system/amendments is the front door; pin the loop"
        },
        {
          "wave": "W5",
          "label": "L3 · Executive delegation → conversion to directly-elected → working",
          "status": "done",
          "note": "VERIFIED 2026-09-05: DONE: the dual-supermajority conversion is pinned end-to-end through the engine (F-LEG-015 propose → exec_office_create supermajority chamber vote from PROTECTED ConstitutionalValidator → MultiJurisdictionVote constituent leg; a failed leg reverts with no election; a chamber with no constituents converts and schedules the election with trigger conversion_act and a 5-seat exec_committee race). Delegation F-LEG-014 pinned engine-filed end-to-end. Pins are Phase D vintage (4af359b6, e844c7d3); the desk closed the cap at eb8910d1. Not pinned: the post-election flip to status=elected (the test ends at STATUS_CONVERSION_VOTED plus a scheduled election). Live box: 0 elections. | the dual-supermajority conversion path (Art. III); verify + pin end-to-end"
        },
        {
          "wave": "W5",
          "label": "L3 · Appointed/elected courts (equal-per-constituent) → working",
          "status": "done",
          "note": "VERIFIED 2026-09-05: DONE: appointed default, equal-per-constituent nomination, judicial/civil lockstep, appointed creation seating a 10-year bench, and the dual-supermajority elected conversion (failed leg reverts to appointed; solo chamber converts) are pinned in JudiciaryCreationConversionTest (11 tests); the desk closed the cap at 565da663. The courtTiers tail is separate: f4c1a012 ruled RETIRE, and InstitutionScaleService::courtTiers still exists with 5 pins and zero app callers (see addition). Wave 6 bench law sizing was not read for this row. | rides L4's courtTiers=tree-depth reframe; verify the appointed default + the elected conversion; pin"
        },
        {
          "wave": "W5",
          "label": "L3 · Agenda per-item schema — build (keystone)",
          "status": "next",
          "note": "VERIFIED 2026-09-05: still unbuilt; the migration slot was granted by the desk (c97dacb9), so the 'flag the desk' step is finished. Today committee_meetings.agenda is a jsonb string list and LiveRoomController::advance() only yields the floor (comment: 'a schema question — FLAGGED, not written'). Needs a real-dated additive migration (>= 2026-07-05) for per-item rows with status, plus an advance() that walks them. | the deferred keystone debt; flag the desk for the migration slot; completes the Live Civic Room's agenda"
        },
        {
          "wave": "W4",
          "label": "L3 · Type B COUNTING (per-clump + per-child) + keystone exit-walk + Niue void",
          "status": "done",
          "note": "6f85d322 + 8ec50402 + b9ea6d6 + 8bcb3ee; ① green on every axis"
        },
        {
          "wave": "W4",
          "label": "L3 · Service formula + electoral partials + liveAggregate + oversight + pollers",
          "status": "done",
          "note": "37b7a64 · f333eba · 4a118e74 · 4057b3c · 2/4 pollers"
        },
        {
          "wave": "W5",
          "label": "L4 · Q4a — reframe courtTiers = jurisdiction tree-DEPTH (doc + provisioning)",
          "status": "done",
          "note": "VERIFIED 2026-09-05: Verify-and-close landed 2026-07-29 (3e1cce1f doc reframe, f4c1a012 RETIRE ruling). Doc states courtTiers = tree-depth and rooms = infrastructure (SERVICE_SCALE_FORMULA.md sec 4.3). Runtime enforces one live court per jurisdiction (judiciaries_jurisdiction_live_uq on the box; social_spaces_jur_type_unique in the dump). Provisioner mints one flat court per jurisdiction and never reads courtTiers. L3 courts capability pinned working. Residual: the dead courtTiers(tier) static and its test pins still exist; retire execution sits with lane 3. | operator ruling A: Live Rooms/squares are INFRASTRUCTURE (a courthouse has many court rooms), NOT a court-as-jurisdiction. NEVER weaken the uniqueness constraints. No schema. Unblocks L3's courts capability"
        },
        {
          "wave": "W4",
          "label": "L4 · Atlas (front door) + service-formula provisioning + growth dial AUTONOMOUS",
          "status": "done",
          "note": "8d03a19/3b9ff99 · 331271b · f9161f5/74603d4/2ad8b1b; SimGovernanceWiringTest 4/4"
        },
        {
          "wave": "W4",
          "label": "L4 · R-A un-flag + per-child/per-clump sim fielding (+ 2 real defects fixed)",
          "status": "done",
          "note": "64c2a27 fielding + 3976919 rosterSize + 80b5e3a per-clump end-to-end fielding pin"
        },
        {
          "wave": "W5",
          "label": "L5 · i18n finish — fr/pt shell chrome",
          "status": "done",
          "note": "VERIFIED 2026-09-05: Option A ruled and executed 2026-07-29. 7e9b7c06 records i18n=A at the Wave 5 launch. 6c15a27a reverts the fr/pt shell-chrome catalogs to the English fallback (17 files, 1,179 deletions). b0c166c6 records L5 i18n CLOSED. On disk today resources/js/i18n/locales/fr and /pt lack c_shell, c_shellv2, chrome and registry; es, ar, hi and zh-Hans carry them. Fallback default ['en'] at resources/js/i18n/index.js:114. No raw NLLB nav ships. The reader/stronger-model chrome pass is tracked as deferred debt, not fleet work. | 6-locale page bodies already render. OPERATOR'S CALL: (a) accept English chrome for the alpha playtest [desk rec — the chrome polish is cosmetic + rail-held] and mark i18n playtest-adequate, or (b) a reader/stronger-model pass on the chrome. Never ship raw NLLB nav"
        },
        {
          "wave": "W4",
          "label": "L5 · Video player /videos + flows extraction + zh-Hans + translation-home",
          "status": "done",
          "note": "2fea981 (app-ported, 77-lang); adversarial review fe5ad51"
        },
        {
          "wave": "W5",
          "label": "L6 · Messaging trio → built (groups-home · group-create · group-detail)",
          "status": "done",
          "note": "VERIFIED 2026-09-05: BUILT in one atomic commit b12d0582 (PrivateRoomController.php, PrivateRoom.vue, PrivateRoomCreate.vue, PrivateRooms.vue, routes/web.php, tests/Feature/MessagesInboxTest.php). v3 bubble chrome, real last-message previews (Matrix read, null when down), unread / live-now / DM-vs-group kind honest-empty. Pages sit behind auth and the setup lock on the live box today. | ONE atomic commit over the shared PrivateRoom files (splitting = merge conflict); v3 bubble chrome + last-message previews; unread / live-now / DM-vs-group persisted-kind stay honest-empty (no source table)"
        },
        {
          "wave": "W5",
          "label": "L6 · bill.html → built (constitutional path)",
          "status": "done",
          "note": "VERIFIED 2026-09-05: BUILT in commit 9f6a2ab3: BillConversationController + BillConversation.vue at GET /bills/{bill}/conversation (public read) and POST /bills/{bill}/comments. The mockup's per-party accept is deliberately not built (Art. V §3); text changes only through committee_amendment / floor_amendment versions the chamber votes on. Comments ride the bill's auto-bound hall subforum through F-SOC-001 with subforum_id. No summary column; real current text or honest-empty. Page sits behind the setup lock on the live box today. | a bill as a conversation on motion kind='amendment' + chamber vote (NOT the mockup's per-party accept = an Art. V §3 violation the code rejects); comments ride the 8 bill-bound subforums; summary honest-empty; coordinate HallsController subforum_id passthrough with L3"
        },
        {
          "wave": "W5",
          "label": "L6 · Atlas a11y review pass (findings → lane 4)",
          "status": "done",
          "note": "VERIFIED 2026-09-05: DONE in commit 9f0f5e9b: docs/plans/ui/L6W5_ATLAS_A11Y_FINDINGS.md (66 lines), a review of resources/js/Pages/System/Atlas.vue, not a rebuild. One medium finding (F1 domain-card heading/region) plus low findings, handed to lane 4. Lane 4 has not applied F1 as of HEAD. | the optional pass, now in scope; findings-to-L4, not a rebuild"
        },
        {
          "wave": "W4",
          "label": "L6 · Civic 6/6 + tour 47→60 + 4 unplanned bugs + org-profile + social-home restore",
          "status": "done",
          "note": "every 'missing panel' was live data discarded by a collapsing query — no schema"
        },
        {
          "wave": "W5",
          "label": "L13 · board-elections → built",
          "status": "done",
          "note": "VERIFIED 2026-09-05: Built. Page resources/js/Pages/Organizations/BoardElections.vue (429 lines) served by BoardElectionController::show at GET /organizations/{organization}/board-elections (routes/web.php:1026-1029); owner track F-ORG-003, worker track F-ORG-004 (controller lines 113, 135). Governmental boards (BoG) are appointed by nomination + consent, not elected: F-EXE-001 at /departments/{department}/nominations (routes/web.php:964-969). Wave 5 commit d791a38a (2026-07-29) moved the surface partial → built and pinned it in tests/Feature/PhaseDPageSmokeTest.php. | org + governmental board elections surface (prop-fill over the existing board module; verify tables via information_schema first)"
        },
        {
          "wave": "W5",
          "label": "L13 · org-registry → built",
          "status": "done",
          "note": "VERIFIED 2026-09-05: Built. Page resources/js/Pages/Organizations/Registry.vue (381 lines) served by OrganizationController::index at GET /organizations (routes/web.php:994); POST /organizations files F-IND-012 through the ConstitutionalEngine (OrganizationController.php:145-155, routes/web.php:996). Wave 5 commit d791a38a added the monopoly-conversion flag, the 'Endorsing only' chip and the 'Start a club' F-IND-012 shortcut and moved the surface partial → built. | F-IND-012 org registration surface; the founder-stake path already lands here (stock orgs)"
        },
        {
          "wave": "W5",
          "label": "L13 · CHECK(balance>=0) on economic_accounts (defense-in-depth)",
          "status": "deferred",
          "note": "optional; if it takes the migration slot — the app-layer buyer-account row lock already closes the overdraft hole"
        },
        {
          "wave": "W4",
          "label": "L13 · 12 economy partials + founding-stake + secondary trading + 8 adversarial fixes",
          "status": "done",
          "note": "F-IND-021 mint (117→118); e8c2884 hardened concurrency/integrity"
        },
        {
          "wave": "W5",
          "label": "L15 · Arm the game box — education:seed (operator's trigger)",
          "status": "held",
          "note": "pre-train arming is built (seeders file F-EDU-001, then education:seed); seeds the training gate on the game box when the operator is ready to walk. Do NOT seed the dev box"
        },
        {
          "wave": "W4",
          "label": "L15 · Pre-train arming + profile-edit + p2p DM + journey/social-home slices",
          "status": "done",
          "note": "c47b50f · ac72ad9 · 663bf08/47d8d57"
        },
        {
          "wave": "W4",
          "label": "L14 · HELD by the operator",
          "status": "held",
          "note": "Foundation = 501(c)(3) parent; Coalition = a PROJECT (child); Action Fund = DO NOT BUILD. Resumes on his word."
        }
      ]
    }
  ]
}
