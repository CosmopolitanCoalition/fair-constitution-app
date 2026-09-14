# -*- coding: utf-8 -*-
"""Rubric v4 — one work list, one question list, one archive, one file.

The operator's order: one list of work, one list of open questions, default to
showing only open questions, consolidate the fleets and waves, carry the
sortable order of operations, one file to pay attention to.

Three tabs render from work.json (built by migrate_to_work.py) plus the
QUESTIONS/_ANS decision channel kept in this file unchanged:
  WORK      the sortable order of operations. Default. Done and moot hidden.
  QUESTIONS the operator's decision channel. Copy and Export unchanged.
  ARCHIVE   done and moot items grouped by original list and wave.

Run:       python3 docs/plans/ui/tools/gen_app_rubric.py
Validate:  python3 docs/plans/ui/tools/gen_app_rubric.py --check
Self-test: python3 docs/plans/ui/tools/gen_app_rubric.py --selftest

--check validates work.json (unique ids, unique order among open items, known
phases, resolvable depends_on, a done_when on every open item). The generator
refuses to write when --check fails.
"""
import json, io, sys, os
_HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, _HERE)
QUESTIONS = [
  {"id":"translation-first-pass-provider","q":"Conference languages (LG-1, LG-2): which provider runs the machine first pass over the 14 absent namespace catalogs and the 10,597 untranslated keys for ar, es, fr, hi, pt and zh-Hans?","status":"open","lane":"edu",
   "detail":"The 14 September languages review measured the gap with tests/js/i18nCoverage.test.mjs. scripts/i18n/translate_run.py exists and is resumable per chunk; its providers are a local NLLB-200 model (free, needs the model and a GPU or a slow CPU run on box E), Claude Haiku (needs ANTHROPIC_API_KEY and an explicit --yes-spend), or the offline stub (marks strings, never a translation). A run is a run: nothing starts without the operator's GO. Human naturalness review stays a separate register concern.",
   "options":[{"k":"A","t":"Local NLLB-200 on box E, all six locales, desk starts it on GO. [desk rec if the model is present]"},{"k":"B","t":"Claude Haiku provider with --yes-spend (operator places the key), all six locales."},{"k":"C","t":"Defer: ship the conference with English fallback for the missing namespaces; translate after."}]},
  {"id":"windows-public-deploy-policy","q":"Deployment (DP-1): is a Windows host ever a PUBLIC deployment target, so that deploy.ps1 needs the public-Matrix configuration-generation gate that deploy.sh carries?","status":"open","lane":"mesh",
   "detail":"The installer review found deploy.ps1 has no matrix:setup / bundle-check branch (deploy.sh gates at lines 452 and 456), so a Windows public deploy would fall back to the committed development Matrix secrets. Box E is the development box; the conference host is Linux. Adding the gate mirrors about 15 lines of bash plus one harness case.",
   "options":[{"k":"A","t":"Windows is development-only: record it in deploy.ps1's header and the handoff, refuse --public on Windows, close DP-1. [desk rec]"},{"k":"B","t":"Add the public gate to deploy.ps1 and the Windows configuration-failure harness case."},{"k":"C","t":"Leave as is."}]},
  {"id":"learn-disclosure-auth-pages","q":"Learn guidance: should the pre-authentication pages (Login, OperatorLogin, Register) and the bare Operator console carry the Learn drawer?","status":"open","lane":"ui",
   "detail":"The education review found these four pages mount no shell and therefore expose no Learn disclosure; every other page carries it through the legacy or V2 shell. Adding it means mounting the Learn-only command bar on four pages that have no signed-in context.",
   "options":[{"k":"A","t":"No drawer before sign-in or on the bare console; record the boundary. [desk rec]"},{"k":"B","t":"Mount the Learn-only command bar on the four pages."}]},
  {"id":"disposable-pg-on-box-e","q":"Review campaign: may the review lanes create and drop nonce-guarded disposable PostgreSQL databases on box E's postgres for the journeys that need real PostgreSQL (enactment locks, order numbering, ballot crypto, virgin-schema load)?","status":"open","lane":"review",
   "detail":"The pattern already exists in the tree and has run on box E: tests/concurrency/judicial_nomination_migration.php and tests/Integration/IsolatedLessonWorkflowTest.php create a database named cga_* / codex_* with a nonce, refuse any other name, verify the fixture identity and that public.organizations does not exist, and drop it at the end. The world database is never touched. Lanes 1 (legislature, elections, executive), 2 (judiciary) and part of 4 (schema, achievements) need it. Proceeding under the desk rec from 2026-09-14; say stop and the PG journeys pause.","options":[
    {"k":"A","t":"Allow disposable cga_* / codex_* databases on box E's existing postgres, world untouched, dropped at the end of each run. [desk rec, in use]"},
    {"k":"B","t":"Require a separate postgres instance or port for every fixture database."},
    {"k":"C","t":"SQLite only: skip every PostgreSQL-dependent journey (no real enactment, order numbering or ballot crypto coverage)."}]},
  {"id":"second-compose-project-box-e","q":"Review campaign: may a second Compose project run on box E to unblock the two-node mesh join review (and the TLS review, which Docker Desktop is unlikely to carry)?","status":"open","lane":"review",
   "detail":"Mesh join needs two app and postgres instances with distinct keys and identities; TLS needs a public overlay with certificates. The standing rule is that no second stack starts on a box without the operator's word. Until answered, both rows stay BLOCKED and only their harness scripts and assertions are prepared.","options":[
    {"k":"A","t":"Authorize a second Compose project for the mesh join review only (two app + postgres, distinct keys, plain localhost HTTP); keep TLS for a Linux host. [desk rec]"},
    {"k":"B","t":"Defer both to a Linux host or the restored cloud box."},
    {"k":"C","t":"Authorize both on box E (TLS will most likely fail on Docker Desktop)."}]},
  {"id":"benchmark-go-scope","q":"Review campaign: what simulation scope, if any, is authorized for the Setup worlds and Scale reviews on an isolated disposable database?","status":"open","lane":"review",
   "detail":"Both rows are held by the benchmark GO gate: no simulation or setup run starts without an explicit GO. A scoped sim:start --limit 3 on a five-jurisdiction synthetic tree in a disposable database writes nothing to the world but is still a run. Until answered, the fixtures and harness are written and nothing runs.","options":[
    {"k":"A","t":"Authorize one scoped run (limit 3, fixed five-jurisdiction tree) on an isolated disposable database, to exercise the verify phase and the Step 5 force path. [desk rec]"},
    {"k":"B","t":"Hold every simulation run for a dedicated benchmark window."},
    {"k":"C","t":"Authorize the 1k-tree Scale fixture as well (wants a second host)."}]},
  {"id":"video-a11y-tooling","q":"Review campaign: install browser automation (Playwright) and axe-core as dev dependencies to unblock the video decoding review, accessibility pass 2 and a rehearsal runner for D1?","status":"open","lane":"review",
   "detail":"No headless browser or axe tooling exists in package.json; three register rows and the final rehearsal runner need one. Installing means an npm dependency add and a browser download inside the Vite container. Until answered, video stays at the passing synthetic component tests and accessibility at the static pass.","options":[
    {"k":"A","t":"Add Playwright and axe-core now; one dependency add unblocks the video review, accessibility pass 2 and the D1 runner. [desk rec]"},
    {"k":"B","t":"Add Playwright only; keep accessibility at the static pass."},
    {"k":"C","t":"Defer both; ship with synthetic component and static coverage only."}]},
  {"id":"step5-readiness-guard","q":"World readiness (G1): may Step 5 finish with unresolved scopes, what happens to runs completed before the verify phase existed, and where does the exclusion acknowledgement persist?","status":"open","lane":"scale",
   "detail":"completeStep5 (SetupController 3919-3932) advances the ladder on any operator POST with no read of the run, its phase, its review residue or any artifact; the sim's 'verifying' phase has no item kinds and runs nothing (SimRun 116); the world aggregate is eight planet-wide statements and omits executives, judiciaries, board chairs, ownership structures and scenario prerequisites. The fix mints one bounded verify_scope sim item per legislature-bearing jurisdiction (status is the progress store, index-only rollups, host-derived concurrency) and the completion endpoint reads that rollup. Built 2026-09-13 under the desk rec.","options":[
    {"k":"A","t":"Block completion by default while any verify scope is in review; allow force=true that records the outstanding list into setup_completion_notes (documented exclusions, never silent); a done run with zero verify items is 'verification pending' until the bounded verify phase is run; no new table or column. [desk rec, built]"},
    {"k":"B","t":"Hard block, no force; grandfather runs completed before the verify phase as passed; a new instance_settings.setup_verification_notes column."},
    {"k":"C","t":"Advisory only, never block; auto-mint verify items on the first refused POST; a verification_runs table."}]},
  {"id":"sim-resume-cursor","q":"Simulation resume (G2): chunk sizing, where the enumeration cursor lives, and which parameters a resume trusts?","status":"open","lane":"scale",
   "detail":"sim:start --resume re-pages from OFFSET 0 and stops after the first fully pre-inserted chunk because the loop tests rows INSERTED, not rows SCANNED (SimStartCommand 288); adm-max and limit come from the CLI on resume instead of the stored run options; positions restart at 0 across the resume boundary. The Step 4 sibling already does it right (ProvisionRunControl 100-141: keyset walk, scanned and inserted returned together, durable cursor). Built 2026-09-13 under the desk rec.","options":[
    {"k":"A","t":"Keyset walk with scanned/inserted returned together; a durable enum_cursor jsonb column on sim_runs (additive migration); a resume always reads adm_max and limit from the stored run options; the chunk size is derived from the host through HostCapacity (env-overridable) and the two sibling constants (ProvisionRunControl LEDGER_CHUNK, AutoscaleEnumeration CHUNK) are retrofitted the same way in the same build (the derive-from-host law generalized to every sibling). [desk rec, built]"},
    {"k":"B","t":"As A but keep the fixed 25000 chunk in all three places (smallest diff)."},
    {"k":"C","t":"Cursor inside sim_runs.options (no migration); CLI overrides stored options on resume; 25000 default with an env override."}]},
  {"id":"progress-polling-bounds","q":"Progress polling and backstops (G3): how are the sim world figures produced, does the co-determination backstop follow demo time, and how far is the ordering-key derive chunked?","status":"open","lane":"scale",
   "detail":"Verified unbounded reads on every poll: step4ProgressPayload runs four uncached provision_ledger and legislatures aggregates (SetupController 3973-4047), buildStep4Summary four planet counts on Step 4/6 render, jurisdictionsCounts an uncached GROUP BY on the 2-second Step 2 poll, SimSnapshot world figures eight whole-table statements with a 10-second cache and a 75-second cold miss (incl. an email LIKE scan). The autoscale snapshot warm-path (scheduler keeps the key fresh) is the correct pattern but its cold start still scans inline in the request. The worker-board backstop uses wall-clock 48 h while elections use the compressed demo clock. Built 2026-09-13 under the desk rec.","options":[
    {"k":"A","t":"World totals from a rollup written by the pump tick (poll reads the rollup), O(1) counters on the run row replace the email LIKE scan, every remaining poll aggregate behind the scheduler-warmed snapshot pattern with no inline cold scan (stale-with-timestamp instead); the co-determination backstop reads the same demo/dev clock elections use; the ordering-key derive chunks only its geometry scan by keyset under a resumable phase marker. [desk rec, built]"},
    {"k":"B","t":"Keep the 10-second cache and accept the cold miss; wall-clock 48 h backstop; no chunking, visible progress only."},
    {"k":"C","t":"Full re-architecture of the ordering-key derive into a chunked worklist table; a separate CGA_BOARD_BACKSTOP_HOURS dial."}]},
  {"id":"join-rerun-identity","q":"Join reruns (M3): how does a rerun of the same join command know it is a rerun, how is clone re-key exposed, and what happens when the membership is departed or the key exhausted?","status":"open","lane":"mesh",
   "detail":"Verified defects: deploy.ps1 157-158 runs key:generate --force on every run (rotating APP_KEY breaks the encrypted federation private key); both deploy.sh 496-500 and deploy.ps1 174-178 run federation:init --rotate on every join rerun (a fresh identity the host never pinned); both then re-post /adopt through cluster:join, which consumes a single-use join key again. The correct resume path already exists (federation:resume-join, the browser wizard branches on isMirror()). The packaged one-liner is identical for the first run and a rerun, so a flag cannot distinguish them. Built 2026-09-13 under the desk rec.","options":[
    {"k":"A","t":"Scripts self-detect a rerun (an existing non-example APP_KEY, then federation:resume-join first and fall through to cluster:join only when no membership exists); federation:init never rotates on --join; an explicit --clone-rekey / -CloneRekey flag is the only rotate; a departed membership or exhausted key fails loud with the instruction to mint a fresh key on the host. [desk rec, built]"},
    {"k":"B","t":"A new read-only federation:state command returns JSON the scripts branch on; otherwise as A."},
    {"k":"C","t":"Keep --rotate on --join but only when no membership exists; on an exhausted key fall back to a keyless join request awaiting host approval."}]},
  {"id":"bootstrap-project-and-failures","q":"Linux bootstrap (M4): which post-deploy failures become fatal, how is directory:publish's no-authority state told from an error, and is the Windows pair fixed in the same build?","status":"open","lane":"mesh",
   "detail":"bootstrap/bootstrap.sh 145 keys its compose calls to the container PREFIX (fc), not the project deploy.sh resolved (--project, COMPOSE_PROJECT_NAME in .env, or the directory basename), so federation:init, transport:register, directory:publish and mesh:doctor can address a different, possibly empty, stack; every one of them is followed by || echo or || true and the script prints success unconditionally (168). deploy.ps1 and bootstrap.ps1 carry the same class of defect. Built 2026-09-13 under the desk rec.","options":[
    {"k":"A","t":"bootstrap.sh reads the project deploy.sh pinned into .env and uses it everywhere; federation:init and transport:register become fatal under set -e, directory:publish stays informational only for the genuine no-authority state (a real error is fatal), and a final fatal mesh:gates is the readiness contract; the Windows pair (deploy.ps1, bootstrap.ps1) gets the same fix in the same build. [desk rec, built]"},
    {"k":"B","t":"Accumulate a failure flag across every step, attempt them all, exit non-zero with a summary; Linux only, Windows filed as a sibling item."},
    {"k":"C","t":"Only federation:init fatal; transport warnings stay warnings."}]},
  {"id":"join-async-dispatch","q":"Async join (M5): where does deploy.sh dispatch the foundation transfer, how does cluster:join treat a rerun, and is async the default?","status":"open","lane":"mesh",
   "detail":"The browser wizard already admits with sync:false and dispatches ClusterJoinJob (resumable, WithoutOverlapping, long-running queue); the CLI path deploy.sh 516 runs cluster:join inline at step 5, BEFORE the asset build and nginx, blocking the terminal for the whole seed and drain (an SSH drop kills it before seeded_at). Built 2026-09-13 under the desk rec.","options":[
    {"k":"A","t":"cluster:join defaults to async (dispatches the job, --sync opt-in), detects an existing mirror membership and resumes it without re-admitting; deploy.sh dispatches after the app, horizon and scheduler restart and after nginx is up, so the UI serves while the transfer runs and the worker holds the final APP_KEY. [desk rec, built]"},
    {"k":"B","t":"Keep the join at step 5 but non-blocking; keep default sync and add --async that deploy.sh passes."},
    {"k":"C","t":"Leave cluster:join admit-only and sync; deploy.sh sequences federation:resume-join separately on reruns."}]},
  {"id":"import-finalization-bounds","q":"Foundation import finalization (M6): where does the authority stamp live, how is completion verified, and what feeds the progress denominator?","status":"open","lane":"mesh",
   "detail":"After the bounded keyset drain, MirrorService 511-513 (paginated, the live default) and 458-460 (tarball) stamp authoritative_server_id on every unowned jurisdiction row in ONE planet-wide UPDATE (951k rows, one transaction); completion is a whole-table count of NULLs (465, 515); the donor's totalRows does an exact count(*) on a cold cache (FoundationServeService 270-277); the geodata pull's jurisdictionsCounts also re-scans. ETL paradigm: never one planet-wide statement. Built 2026-09-13 under the desk rec.","options":[
    {"k":"A","t":"The stamp rides each drained page (a scoped id-range UPDATE inside the page transaction); the tarball path is marked legacy and gets a chunked keyset stamp; completion = the page ledger (rows_applied equals total) plus one index-assisted exists() probe before seeded_at; the denominator reads pg_class.reltuples (bounded catalog read); the geodata counts get the existing 8-second cache. [desk rec, built]"},
    {"k":"B","t":"Stamp at insert time inside applyPage; ledger-only completion; keep the exact count with a longer cache TTL; geodata counts left to G3."},
    {"k":"C","t":"Keep both import paths first-class with chunked stamps; add a partial index for the NULL probe; a trigger-maintained counts table for the denominator."}]},
  {"id":"interjurisdictional-doors-s2","q":"Interjurisdictional acts (S2): how do constituents consent to a union or disintermediation, how is a border settlement opened, and what does scoped history mean?","status":"open","lane":"ui",
   "detail":"Union (F-LEG-029) and disintermediation (F-LEG-030) can be PROPOSED from the UI, but the applicant referendum, the constituent consents, the encompassing consent and finalize exist only as service methods with no routes; border settlement and restoration have no routes at all; restoration's tier 3 never completes (a degenerate ternary at RestorationService 111); the four histories are hard-capped at 25 rows. No new forms and no migration are needed. Built 2026-09-13 under the desk rec.","options":[
    {"k":"A","t":"Consent reuses the generic constituent_consent chamber-vote arm (the door opens a peg-quorum vote in the constituent legislature; on close the process records the consent and finalizes itself when both meters are met) with union_processes and disintermediation_processes branches added; the border settlement is opened from the Between Governments page as a between-governments act (no chamber proposal kind); restoration gains declare, confirm, tier and complete doors; each history is a bounded 25-row cursor page with its own scoped token. [desk rec, built]"},
    {"k":"B","t":"Consent as a direct per-constituent record route (no chamber vote); border gets a new chamber proposal kind adopted by both bordering chambers; histories filtered per jurisdiction as well as paged."},
    {"k":"C","t":"Ship proposal, finalize and paging now; consent and the border door as a follow-up item."}]},
  {"id":"lesson-publication-shape","q":"Lesson management and publication (LE-2): what does F-EDU-002 persist, how is a revision tracked, and what happens under a demo session?","status":"open","lane":"ui",
   "detail":"F-EDU-002 Training Material Publication validates and refuses answer keys but writes nothing (an audit entry only); the only writer of education rows is EducationCatalogService::publish from the seed command; lesson prose comes from the generated K-2 registry by surface id; no editor page exists. Built 2026-09-13 under the desk rec.","options":[
    {"k":"A","t":"Structural publish: the handler writes the module row (title, surface, status, revision_number, published_by, published_at via one additive migration) through EducationCatalogService; prose stays in the K-2 source and generator; an R-23 editor page lists modules and files F-EDU-002; under a demo session the write is captured and reversed at session end like any other demo write (ruling C). [desk rec, built]"},
    {"k":"B","t":"DB-stored prose: a body column on education_modules read when the registry has no entry (breaks the single-source prose model); revision by audit chain only; publication blocked in demo sessions."},
    {"k":"C","t":"Full module_revisions version table and a DB prose override; publication blocked in demo sessions."}]},
  {"id":"lesson-video-association","q":"Lessons and videos (LE-3): where does the surface-to-video association live, which videos map now, and where does the player render?","status":"open","lane":"ui",
   "detail":"The multi-track player, its error and caption repairs, the 61-video catalog (config/cga/media.php from video-translate subjects.json) and the /videos library exist; nothing associates a surface or lesson with a video, the generator emits no video field, and the lesson page renders no player. Built 2026-09-13 under the desk rec.","options":[
    {"k":"A","t":"The association is authored in the K-2 source (a video line per surface) and emitted by the generator (no DB, no form); only surfaces with a clean match get a video now, the rest show the honest poster state and the /videos chip; the player renders on the lesson page, the Learn flyout deep-links the assigned video. [desk rec, built]"},
    {"k":"B","t":"An education_modules.video_id column (additive migration) seeded per module; every surface mapped to the closest existing marketing video now; player inline in the Learn flyout on every surface."},
    {"k":"C","t":"Produce the 20 missing WoS lesson videos through the video pipeline first, then map; player on the lesson page only."}]},
  {"id":"achievement-wiring-scope","q":"Achievements (AC-1): how are state-based awards delivered, do demo sessions earn awards, and is the jurisdiction/system milestone plane in scope now?","status":"open","lane":"ui",
   "detail":"AchievementService is the single idempotent writer (audit seal + row in one transaction) but only two of 126 catalog keys have a live call site (ACH-EDU-001 and the tour arcs); awardSubject and awardState have no callers. Under demo ruling C every demo write is captured and voided at session end, so a demo award would ride that path. Built 2026-09-13 under the desk rec.","options":[
    {"k":"A","t":"Personal ACH-* keys only this pass: subject awards at their action sites (engine handlers) and an idempotent sweep command for state awards read from the fact tables (seats, residency, ballots), plus write-site calls where the seat is minted; demo awards are captured and voided like any other demo write; the jurisdiction/system milestone plane is a tracked follow-up. [desk rec, built]"},
    {"k":"B","t":"As A but suppress awards entirely under a demo session (a demo user never earns)."},
    {"k":"C","t":"Include the jurisdiction/system milestone plane now (a PublicRecordService milestone publish path) and state awards only by write-site calls, no sweep."}]},
  {"id":"org-membership-agent-rules","q":"Organization membership review and agent reassignment (IO-4): who may be named the new agent, may a declined applicant re-apply, and does the incoming agent consent?","status":"open","lane":"ui",
   "detail":"The engine already carries accept_member, decline_member and reassign_agent as F-ORG-001 actions (OrganizationProfileManagement 78-82) with the two-layer R-23 gate; only routes, the pending-applications page data and the controls are missing. Today the handler lets the agent name any registered user, a declined applicant can file a fresh application, and the transfer needs no consent from the incoming agent. Built 2026-09-13 under the desk rec (no new form, no migration).","options":[
    {"k":"A","t":"Keep the built engine behaviour: any registered user may be named agent; declined is terminal for that row and a fresh application is allowed; the transfer is unilateral. [desk rec, built]"},
    {"k":"B","t":"Restrict the new agent to a resident of the organization's jurisdiction (R-01) and add a two-step consent (offer, accept) mirroring the contract co-sign gate."},
    {"k":"C","t":"Restrict the new agent to an existing active member or worker; add a cooldown before a declined applicant may re-apply."}]},
  {"id":"cgc-governor-removal-shape","q":"CGC governor removal (IO-7): reuse F-EXE-003 with dual-owner routing through the overseeing executive and the creating legislature, or mint a separate CGC removal form?","status":"open","lane":"ui",
   "detail":"Department governor removal exists (BoardGovernorService requestRemoval 357-421 / resolveRemovalVote 424-503, ordinary majority of the legislature). The CGC path is missing: the requester should be the seated principal of overseen_by_executive_id and the consent vote sits in created_by_legislature_id, matching nominateCgc. resolveRemovalVote also omits the board composition refresh after a removal. Built 2026-09-13 under the desk rec.","options":[
    {"k":"A","t":"One owner-neutral service path (requestRemoval / resolveRemovalVote resolve the body from the board's owner), F-EXE-003 reused with dual-owner handler routing like F-EXE-001; the overseeing executive initiates, the creating legislature consents by ordinary majority; composition refresh added on adoption. [desk rec, built]"},
    {"k":"B","t":"A separate requestRemovalCgc branch and a new F-ORG form for CGC removal (raises the exact form-count pin), department path untouched."},
    {"k":"C","t":"As A, but the creating legislature must co-initiate the removal (two-body initiation)."}]},
  {"id":"org-staff-delegation-model","q":"Scoped organization staff delegation (IO-5): a dedicated delegation form, which task buckets, and do grants survive an agent reassignment?","status":"open","lane":"ui",
   "detail":"Organization authority is binary today: one agent_user_id derives R-23, membership derives R-24, and four separate gates check agent_user_id (profile handler, market participation, labor board, org economy). A delegation layer needs a grant table (additive migration), a derived delegate role that confers no constitutional office, a single mayPerform check every gate routes through, and grant/revoke controls; reassign_agent and grant/revoke themselves must never be delegable. Built 2026-09-13 under the desk rec.","options":[
    {"k":"A","t":"New form F-ORG-011 Staff Delegation (grant and revoke as audited acts; pin rises deliberately); coarse task buckets profile, membership, contracts, documents, hiring, shares; grants persist across an agent reassignment and the new agent sees and can revoke them. [desk rec, built]"},
    {"k":"B","t":"Fold grant_task / revoke_task into F-ORG-001 (no new form); one key per action (finest control); auto-revoke every grant when the agent changes."},
    {"k":"C","t":"No form, service-only writes; two tiers (read configuration vs act); grants flagged orphaned until the new agent confirms each."}]},
  {"id":"challenge-tracker-controls","q":"Constitutional challenges (IO-3): expose the four existing handlers (finding F-JDG-004, recommendation F-JDG-005, override F-LEG-035, direct remedy F-JDG-006) on the tracker, and should the override be emergency-protected?","status":"open","lane":"ui",
   "detail":"All four handlers exist and are registered; only routes and controls are missing (the tracker renders read-only cards). Path 3 already auto-fires by clock CLK-11. F-LEG-035 is the only Art. IV sec. 5 form not in EMERGENCY_PROTECTED_FORMS (ConstitutionalValidator 362), so an active emergency can pause an override proposal but not a filing, finding or remedy. Path 1 (a remedial bill) has no affordance that sets bills.targets_challenge_id. Built 2026-09-13 under the desk rec; the emergency-protection question is a constitutional call left untouched.","options":[
    {"k":"A","t":"Add POST routes and controls for all four handlers on the tracker (each enabled only in its lawful state, the remedy button only once both windows closed), plus a 'Propose amendment bill' link that prefills targets_challenge_id; leave the emergency list as it is. [desk rec, built]"},
    {"k":"B","t":"As A, and add F-LEG-035 to EMERGENCY_PROTECTED_FORMS so an emergency can never pause an override (constitutional change)."},
    {"k":"C","t":"Controls for finding, recommendation and override only; Path 3 stays clock-fired with no button; Path 1 stays CLI-only for the demo."}]},
  {"id":"appeals-workflow-rules","q":"Appeals (IO-2): are criminal verdicts appealable, where do appeal outcomes live, and who files the appeal?","status":"open","lane":"ui",
   "detail":"The schema already carries cases.appeal_of_case_id and the status 'appealed' (edges decided/sentenced -> appealed in CaseService 39-40), but no form, route, control or outcome vocabulary exists. The constitution has no word 'appeal'; Art. II sec. 8 says a criminal prosecution can never be repeated and 'all other Judgements can be overturned only by proven contradictions in law and errors found in the cases'. ConstitutionalValidator 1349-1352 already reads that as: non-criminal verdicts can be vacated via the appeal path, a criminal one is final. Built 2026-09-13 under the desk rec.","options":[
    {"k":"A","t":"Criminal verdicts are final (appeal path refuses kind=criminal, citing Art. II sec. 8); the appeal is a NEW case row linked by appeal_of_case_id, heard by the parent judiciary (or the same court en banc when there is no parent); its outcome (affirm, reverse, remand) is recorded as the appeal case's opinion (no migration); a party to the original case files it, like a case filing (F-IND). [desk rec, built]"},
    {"k":"B","t":"Criminal verdicts appealable on error but only to vacate or acquit, never a new trial; otherwise as A."},
    {"k":"C","t":"As A, but outcomes get their own appeal_reviews table (additive migration) and an advocate may file on a party's behalf."}]},
  {"id":"case-lifecycle-controls-shape","q":"Court case lifecycle (IO-1): are hearing, deliberation and dismissal orders constitutional FORMS (engine, training gate, catalog) or bare controller transitions, and how hard are the panel checks on a verdict?","status":"open","lane":"ui",
   "detail":"Today a paneled case cannot reach a hearing, deliberation or verdict from any page: the three transitions exist only in CaseService (advanceToHearing 218, enterDeliberation 229, recordVerdict 252) and are called only by the Phase E demo command. Every other judge act (acceptance F-JDG-001, jury order F-JDG-002, opinion F-JDG-003, sentencing F-JDG-009, warrant F-JDG-010) is a form with the judiciary training gate. Two explicit design notes say the VERDICT is a CaseService transition, not a form. recordVerdict checks the actor is a seated judge of the court, not a member of THIS case's panel, and does not check that the recorded panel vote carries the outcome. Built 2026-09-13 under the desk rec.","options":[
    {"k":"A","t":"Hearing, deliberation, dismissal and motion/evidence rulings become F-JDG-011..014 forms (engine, catalog, training gate, surfaces, Learn copy; the exact form-count pin rises deliberately); the verdict stays a transition behind a judge-only route; the verdict actor must sit on the case panel, a panel verdict must record for+against equal to the panel size with the outcome carried by the majority, a jury verdict must record unanimity; the case row is locked for the write. [desk rec, built]"},
    {"k":"B","t":"All of it as bare controller transitions (no new forms, pin untouched, no training gate on these acts); same panel and vote checks."},
    {"k":"C","t":"Forms for hearing and deliberation only; dismissal and rulings deferred to a later item; panel membership enforced, vote fields stay advisory."}]},
  {"id":"individual-endorsement-rules","q":"Individual endorsements (EO-5): when may a resident endorse or withdraw, may they re-endorse after withdrawing, and is the record private by default?","status":"open","lane":"ui",
   "detail":"The punch list item EO-5 adds the first individual (person-to-candidate) endorsement controls; the writer Endorsement::recordFor existed (B6) but enforced nothing. The org path (F-ORG-002) binds only to candidacy standing; candidacy withdrawal binds to CLK-21 (the finalist cutoff); the endorsements table comment says an endorsement can be withdrawn before voting opens; the schema default for is_public is false and org endorsements are forced public. Built 2026-09-13 under the desk rec below (F-IND-025 endorse, F-IND-026 withdraw): change the ruling and the handler gates change, nothing else.","options":[
    {"k":"A","t":"Window: endorse and withdraw while the candidacy stands (registered..finalist) AND voting has not opened; re-endorse after withdraw allowed (one row toggled); private by default, opt in to public. [desk rec, built]"},
    {"k":"B","t":"Window: mirror the org grant exactly, candidacy standing only (endorse and withdraw even after voting opened); re-endorse allowed; private by default."},
    {"k":"C","t":"Window: bind to CLK-21 like candidacy withdrawal (nothing after the finalist cutoff); once withdrawn, locked (no re-endorse); public by default, opt out to private."}]},
  {"id":"conference-navigation-iteration","q":"How should attendees find places, maps and civic roles?","status":"resolved","lane":"ui",
   "detail":"Operator approved Astra's inventory-led consolidation on 2026-09-12: six everyday destinations (Today, Places, Community, Work & trade, Learn, My profile); host/developer tools separate; preserve all jurisdiction levels and make legislative maps prominent. Role workspaces are open to observation, with court/chamber/committee/board seating arrangements and public display names. The existing instant-residency beta configuration remains the timing mechanism. See docs/audits/2026-09-12/ITERATION.md for implementation and tested limits."},
  {"id":"jurisdiction-nav-flush-tools","q":"Jurisdiction navigation: how do the full-bleed tool pages (map viewer, districts mapper, panels) carry the place's tools?","status":"open","lane":"ui",
  "detail":"Design session 2026-09-10 (3 designers, 2 judges) chose ONE CONTEXT BAR: the header chain shows the place you are viewing on every place and institution page (built: JurisdictionContext, a1a23600..HEAD), and a JurisdictionRail carries the general jurisdiction tools on document pages (built on the place page). The open fork is the three flush tool pages, which keep their own left sidebar (the mapper's). Both judges warned against adding a rail column to the shell grid (a phantom grid column once left-pinned the app, components-v2.css:1146-1152). The routing boundary is settled by the panel: /legislatures/{id}/... routes stay; context rides the shared prop.","options":[
   {"k":"A","t":"Tools block at the TOP of each tool's own sidebar (same list, same component in a compact variant) — one source, no second sidebar, the mapper's scope trail stays beneath it as the in-map axis. [desk rec] Stage 2 of the panel's plan."},
   {"k":"B","t":"A slim PlaceTabs strip above the split (Overview · Map · Districts · Panels · Government · Elections) so the three tools read as tabs of one tool, plus A. Stage 3; more surface."},
   {"k":"C","t":"Header chain only on the tool pages (they are already reachable from the rail); the mapper sidebar keeps its own cross-nav links as today."}]},
  {"id":"demo-mode-write-isolation","q":"Demo mode: how are a user's go-through-the-motions actions kept from persisting beyond their session?","status":"resolved","lane":"demo",
  "detail":"Your ruling 2026-09-10: in demo mode a user goes through the motions and the actions do not persist beyond their session. The code has ONE seam for action gates: ConstitutionalEngine::file() -> authorize() (app/Domain/Engine/ConstitutionalEngine.php:107, 216-240) enforces each form's requiredRoles() and appends the audit entry in the same transaction (:160, :174). The audit log is a hash chain, hash(n) = sha256(hash(n-1) || payload) with one head (app/Services/AuditService.php:15, :254), so a row that enters it can never be removed without breaking every later hash. That fixes the shape: demo actions must NOT enter the permanent chain. The teardown pattern already exists: SimRunControl::revert deletes a run's provenance-scoped rows in bounded committed chunks (app/Services/Demo/SimRunControl.php:253). Session end = POST /logout (AuthenticatedSessionController::destroy) plus a sweep for expired sessions.","options":[
   {"k":"A","t":"Session-tagged writes + purge. In demo mode authorize() lets the actor through as if holding the role; every row the filing writes carries demo_session_id; the audit entry goes to a separate demo_audit_log (off the permanent chain); a purge deletes the session's rows in FK-safe chunks at logout and on session expiry (the SimRunControl::revert pattern). Downstream effects are real inside the session (a bill appears, a vote tallies). [desk rec]"},
   {"k":"B","t":"Client-side only. Demo actions never write: the page shows the result optimistically and nothing reaches the server. Cheapest; no downstream effects (a filed bill does not appear on the chamber page)."},
   {"k":"C","t":"Real writes, compensating purge. Demo actions enter the real tables AND the permanent audit chain, tagged; at session end a compensating void entry is appended per action. The chain stays valid but the permanent record carries every demo action forever."},
   {"k":"D","t":"Something else; describe it in the notes."}]},
  {"id":"demo-mode-identity","q":"What IS demo mode on a box: the instance's class, or a per-session toggle a user can switch on?","status":"resolved","lane":"demo",
  "detail":"Two axes exist today on instance_settings: game_mode ('production' | 'sandbox', the dev-toolbox gate, app/Support/GameMode.php) and instance_class ('production' | 'scale_demo', the federation class). Your plan names two boxes: demo.worldofstatecraft.org (the read-only simulation) and beta.worldofstatecraft.org (the writable instance where attendees make accounts). The read-everywhere ruling applies to BOTH; the go-through-the-motions ruling needs a switch that says which requests are demo.","options":[
   {"k":"A","t":"Box-level: demo mode = instance_class 'scale_demo' (the demo box). Every action on that box is a demo action; the beta box writes for real. One switch, no per-user state. [desk rec]"},
   {"k":"B","t":"Per-session toggle on any box: a user turns on 'Try it' and their actions become demo actions until they turn it off or log out; the beta box gets it too."},
   {"k":"C","t":"Both: box-level on the demo box, and the per-session toggle available on the beta box for attendees who want to rehearse before acting for real."},
   {"k":"D","t":"Something else; describe it in the notes."}]},
  {"id":"demo-residency-window","q":"Beta demo: the 30-day residency confirmation blocks attendees from acting during the conference. Which relief?","status":"resolved","lane":"demo",
  "detail":"Your heads-up (via the cloud lane, 2026-09-08): you are steering the beta toward a hybrid demo mode and flagged the 30-day residency ping as a problem for the demo. residency_confirmation_days is an AMENDABLE setting (default 30, constitutional_settings, per jurisdiction); the Art. I right itself (residency is the only requirement) is not touched by any option. The Kraków window is 21 to 23 September.","options":[
   {"k":"A","t":"Shorten residency_confirmation_days on the beta box only (a setting change through the normal amend path, e.g. 1 day), so attendees confirm and act within the conference."},
   {"k":"B","t":"Hybrid demo mode: attendee accounts are seeded as pre-confirmed residents of a demo jurisdiction at registration; the 30-day rule stays for every other place."},
   {"k":"C","t":"Keep 30 days; attendees observe and the simulated population does the acting (read-only for people, live for the sim)."},
   {"k":"D","t":"Something else; describe it in the notes."}]},
 {"id":"bench-scaling-law","q":"Court bench size: keep the three population bands (5, 7, 9 judges) or tie the bench to a continuous formula?","status":"open","lane":"3",
  "detail":"Not blocking the demo. Today the bench (judicial seats on a jurisdiction's one court) starts at 5 below 250,000 people, 7 from 250,000, 9 from 10,000,000; a court with constituents grows past that as judges per constituent x constituents; a leaf court stays at the floor unless its chamber votes a bigger bench. The constitution fixes the floor of 5 and no ceiling; the bands are a policy dial (InstitutionScaleService), pinned by test. Case panels are 3, 5 or the whole court regardless of bench size. Your note expects the bench to scale with population like the seat law.","options":[
   {"k":"A","t":"Keep the bands for the conference; revisit after. [desk rec]"},
   {"k":"B","t":"Tie the bench to the legislature: bench = max(5, next odd number at or above seats / 10) (Earth 1,999 seats gives 201; a 10-seat council gives 5)."},
   {"k":"C","t":"Tie the bench to population with its own cube-root style law and a floor of 5."}]},
 {"id":"europe-node-identity","q":"The Europe node behind demo.worldofstatecraft.org: a mirror of the Azure build box, or a sovereign restore from its export bundle?","status":"open","lane":"00",
  "detail":"Your plan (2026-09-05): build the world on the Azure box at 96 cores, scale it down, then mesh it to a node hosted in Europe. Two ways exist in code. A mirror joins the Azure box's mesh and copies its state; the Azure box stays the authority and must stay online for the whole conference. A sovereign restore loads the Azure box's full export bundle onto the Europe node, which becomes the authority; the Azure box can then scale to zero. The export panel on Step 4 and the import panel on Step 0 exist; the mirror join exists on the join fork.","options":[
   {"k":"A","t":"Sovereign restore on the Europe node; it is the authority for the conference; the Azure box is the backup. [desk rec]"},
   {"k":"B","t":"Mirror join; the Azure box stays authoritative and online 21 to 23 September."},
   {"k":"C","t":"Mirror for the conference, then flip authority to the Europe node after."}]},
 {"id":"seat-mint-owner","q":"Seat minting at scale: which engine creates the elections and races for 940,327 legislatures?","status":"open","lane":"3",
  "detail":"Seats exist only as integers on legislatures, districts and panels. Elections and races are created by ElectionLifecycleService::scheduleGeneral (per legislature), by the F-ELB-001 handler, and by openSuccessor at certification. None has a chunked caller. The sim's ElectionStage calls scheduleGeneral per jurisdiction item and needs an active board per jurisdiction; it runs only on a sandbox or scale_demo world. A live mesh has no sim, so it needs a non-sim caller. Members are created only at F-ELB-004 certification, and a legislature flips forming → active only there.","options":[
   {"k":"A","t":"A Step 4 lane calls scheduleGeneral per legislature after the boards land. Works in every game mode. Needed for the live mesh."},
   {"k":"B","t":"The sim's election phase mints them (exists today). Sandbox only. Seats depend on a sim run."},
   {"k":"C","t":"B for the conference demo now; A built after the conference for the live mesh. [desk rec]"}]},
 {"id":"zero-seat-chambers","q":"Zero-seat chambers and zero-seat districts: what does the seat-minting lane do with them?","status":"open","lane":"3",
  "detail":"17,250 legislatures hold type_a_seats 0 (zero population, Class D). scheduleGeneral creates the elections row before racePlan runs and keeps the row when the plan is fully blocked, so a mass caller leaves an orphan 'scheduled' election per zero-seat chamber; the F-ELB-001 engine path throws and rolls back instead. Nine ACTIVE maps carry one district with seats = 0 (a zero-population bin under the sub-2 rule); racePlan blocks the whole Type A half of those nine legislatures (type_a 10 to 78).","options":[
   {"k":"A","t":"Skip every zero-seat chamber (no election row). File the nine maps for redraw so the zero-seat bin is absorbed; those nine elect after the redraw. [desk rec]"},
   {"k":"B","t":"Skip zero-seat chambers. Leave the nine maps as they are; those nine legislatures elect nothing for the demo."},
   {"k":"C","t":"Read the nine bins first (population, geometry) and rule after the read."}]},
 {"id":"bench-and-quorum-law","q":"Judges per court and the stored quorum number: one rule for every path that creates them?","status":"open","lane":"3",
  "detail":"OPERATOR NOTE 2026-09-05: 'I need a deeper understanding of this and why this question is important.' RESTATED. Bench = the number of judges on a court. The constitution sets a minimum of 5 judges and no maximum. Two code paths create courts. The mass path (Step 4 provisioning, the one that runs at scale) gives 5, 7 or 9 judges by population band (below 250,000: 5; 250,000 and above: 7; 10,000,000 and above: 9). The single-jurisdiction path (the Activate button, jurisdiction:activate) gives 5 to every court. The same jurisdiction gets a different number of judges depending on which path reaches it first. Quorum = the minimum number of members present for a legislature to act; the constitution says a majority of all serving members. At vote time the code computes quorum from the serving members and does not read any stored number. At creation time, seven code paths also store a quorum number on the legislature row: two cap it at the seat count, five do not (a zero-seat chamber shows quorum 3). That stored number appears on legislature pages and in the ledger. The two sets of rules exist because the single-jurisdiction path was written in an earlier phase and the mass path later, and nobody unified them. Why it matters for the demo: two neighbouring places of the same size show a 5-judge court and a 7-judge court; legislature pages show quorum values that disagree with the rule the votes use. The seat floor for the cube-root sizing has the same split: a constant 5 in one path, the planet row in another, the jurisdiction's own row in the rest (every row holds 5 today, so no divergence yet).","options":[
   {"k":"A","t":"One rule everywhere: judges 5/7/9 by population band; stored quorum capped at the seat count; seat floor from the jurisdiction's own row. [desk rec]"},
   {"k":"B","t":"One rule everywhere: judges 5 for every court; stored quorum capped; seat floor from the own row."},
   {"k":"C","t":"Leave the two sets of rules; document them."}]},
 {"id":"sub-institutions-path","q":"Committees and departments for the demo: which path delivers them by 09-18? (Ruling scale-committees B, eager, stands.)","status":"open","lane":"3",
  "detail":"The provisioning steps are executives, judiciaries, election_boards, board_members, social_spaces. Committees K(S) and departments D(P) are sim vote targets: GovernanceStage files F-LEG-009 and F-LEG-016 and every seated member casts a vote. No provisioning step writes them. Boards of governors and rooms have no mass path either (the nightly social-structure sweep touches active legislatures only).","options":[
   {"k":"A","t":"The sim's governance phase produces them through real votes after seating (exists today). [desk rec]"},
   {"k":"B","t":"A Step 4 lane files F-LEG-009 and F-LEG-016 as system acts without a chamber vote. Needs an engine change."},
   {"k":"C","t":"Defer committees and departments past the conference."}]},
 {"id":"sim-scope-for-demo","q":"What does the demo simulation run cover before 09-18?","status":"open","lane":"4",
  "detail":"Every constitutional filing appends under one global advisory lock at about 28.6 appends per second (code comment). Seating alone is one F-ELB-004 per election: 940,327 chambers ≈ 9 hours of lock time. Governance adds K(S) + 1 + D(P) filings and one cast per seated member per vote; judiciary adds 1 + 2 per seat. A planet run is days, not hours, and no baseline exists. Jurisdictions by level: 1 / 232 / 3,238 / 49,263 / 104,020 / 83,860 / 699,711 (adm 0 to 6). The sim needs the worker heartbeat and the resume fix before a long run is trustworthy.","options":[
   {"k":"A","t":"One measured pilot first (one country, all stages), then choose the scope from the measured rate. [desk rec]"},
   {"k":"B","t":"Full planet, all stages, start when Step 4 lands, accept a multi-day run."},
   {"k":"C","t":"adm 0 to 3 only (52,734 jurisdictions), all stages; deeper levels as time allows."},
   {"k":"D","t":"The N largest jurisdictions by population (--limit), all stages."}]},
 {"id":"sim-revert-scope","q":"Roll back the simulation: how deep should the Step 5 rollback go?","status":"open","lane":"sim",
  "detail":"OPERATOR QUESTION 2026-09-06: how do Steps 2, 3, 4 handle rollback? THE PATTERN: each step's rollback deletes THAT step's own produced output and keeps every layer beneath it, chunked (the ETL rule), refusing a live run unless forced, scoped by provenance. Step 2 Fresh drops the whole world (geodata run tables TRUNCATE CASCADE, then jurisdictions chunked deepest-first, residencies, pings, settings), gated to refuse if real court filings exist. Step 3 autoscale:revert deletes drawn output (districts, memberships, subdivisions) and keeps the facts (legislatures, ledger reset to pending, map containers flipped active to draft not deleted, accepted maps). Step 4 provision revert deletes the run's product (elections, races, timers, system-act committees, departments with boards and seats and charter laws, zero-balance treasuries; --shells also executives, courts, boards, spaces) and keeps maps and jurisdictions. So Step 5's product is the POPULATION layer: people (sim-%@demo.invalid), cohorts, candidacies, ballots, tabulations, seated members, civic records. What is BUILT today is only the bookkeeping part (sim:revert clears the run worklist + leases so a fresh run re-enumerates; the produced world stays). To match 2/3/4, Step 5 needs the full population teardown: delete the sim's people and every FK-dependent row in FK-safe chunks (the same problem Step 2 already solved for jurisdictions), un-certify the elections seating advanced, and stop before Step 4's institutions. The ~40 RESTRICT foreign keys make it a real build, but the chunked-provenance-scoped template exists in Step 2.","options":[
   {"k":"B","t":"Build the full app-level population teardown, matching Steps 2/3/4: delete the sim's people and every FK-dependent row in FK-safe chunks scoped by the sim provenance, un-certify the touched elections, leave Step 4's institutions and maps. The bookkeeping reset already built stays as its first step. [desk rec]"},
   {"k":"A","t":"Keep only the safe bookkeeping reset (built). A clean slate comes from a box-level snapshot restore, not an app cascade. Inconsistent with how 2/3/4 roll back."},
   {"k":"C","t":"No app rollback of the product. 'Roll back' means restore the post-Step-4 box image with the existing box tooling."}]},
 {"id":"read-only-lock","q":"Read-only lock for the demo mesh: what does the lock refuse, and where is it enforced?","status":"open","lane":"demo",
  "detail":"Your words: once the scaled demo mesh is proven, lock it to read only; the read-only view is public and walkable. No such flag exists. Writes enter through the ConstitutionalEngine (every form), through non-form endpoints (residency pings, registration, orgs), and through the pumps and clocks (the sim, elections advancing).","options":[
   {"k":"A","t":"A new instance flag. The engine refuses every filing; every write endpoint returns 423; registration closes; the sim and clock pumps pause. Reads and walking stay open. Operator toggles it. [desk rec]"},
   {"k":"B","t":"Close registration and pause the pumps only; the engine stays open for existing accounts."},
   {"k":"C","t":"Enforce at PostgreSQL with a read-only role (breaks sessions, queues and caches; not recommended)."}]},
 {"id":"public-walk","q":"Public walk on the demo mesh: which surfaces open to a guest?","status":"open","lane":"demo",
  "detail":"After setup completes, the setup redirect lifts. Most read routes (jurisdictions, legislatures, federation, elections views) are public today; /simworld and /building sit inside auth groups; the operator console and dev tools stay gated. A guest can register on /register unless registration is closed.","options":[
   {"k":"A","t":"Every read surface public, including the sim console as a read-only view; all writes refuse under the lock. [desk rec]"},
   {"k":"B","t":"Public = the viewer, legislatures, elections and institution pages; the sim console and the build page stay behind login."},
   {"k":"C","t":"Everything requires an account."}]},
 {"id":"demo-mesh-host","q":"Where does the demo mesh run for the conference?","status":"open","lane":"00",
  "detail":"Box E holds the world (940,327 legislatures, 940,315 maps, 36,810 panel maps). The WoS Azure box (project wos, D8als_v7, 10 vCPU) is the cloud target. Memory records the open identity question for Poland access: mirror versus sovereign restore. A mirror keeps box E authoritative and online for the whole conference; a sovereign restore makes WoS the authority and box E a backup.","options":[
   {"k":"A","t":"Sovereign restore on WoS from a full export bundle of box E; WoS is the authority; box E is the backup. [desk rec]"},
   {"k":"B","t":"WoS joins as a mirror of box E; box E stays authoritative and must stay online 21–23 Sep."},
   {"k":"C","t":"Serve from box E only through a tunnel."}]},
 {"id":"wizard-ladder","q":"Wizard ladder: Steps 0 to 6 as your diagram, or fold the new work into the existing five steps?","status":"open","lane":"ui",
  "detail":"Today: 0 Cosmic Address, 1 Constitutional Defaults, 2 Map Data, 3 Build Districts, 4 Confirm and Seat Institutions. The route accepts n in 0 to 4 and the stepper renders five steps. The counter convention is 'n done, next is n'; Step 4 already writes 5. Your diagram: Step 4 Scale Up (optional), Step 5 Simulate (optional, sandbox), Step 6 Confirm and Close.","options":[
   {"k":"A","t":"Steps 0 to 6 as the diagram. Route range and stepper extend; the counter convention stays. [desk rec]"},
   {"k":"B","t":"Keep 0 to 4. Scale Up and Simulate become sections on the Step 3 page; Step 4 stays Confirm."}]},
 {"id":"done-flip-vs-pages","q":"Who starts Steps 4 and 5: the map run's done flip, or the wizard pages?","status":"open","lane":"ui",
  "detail":"Today eager acceptance starts the map run and the run's done flip dispatches provisioning and (with the simulate flag) the sim, with no wizard surface for either. The Step 4 page then re-dispatches provisioning only. The two pages must either observe an automatic chain or trigger the work themselves.","options":[
   {"k":"A","t":"The pages trigger: Step 4 'Scale institutions? yes/no', Step 5 'Simulate data? yes/no'. The done flip only refreshes the map quality statistics. Eager acceptance ends at maps. [desk rec]"},
   {"k":"B","t":"Acceptance triggers the whole chain as today; the pages observe and offer halt/resume only."}]},
 {"id":"boot-prewarm","q":"Boot-time prewarm: keep the planet raster and geojson warm on every horizon restart?","status":"open","lane":"00",
  "detail":"docker/php/entrypoint.sh queues rasters:prewarm z0-12 and geojson:prewarm on every fc_horizon container start. The deploy recipe is docker restart fc_horizon. Both ride the long lane (retry_after 14400 s, tries 1); 139 of the 343 failed rows are these jobs. They compete with provisioning and the sim for the box.","options":[
   {"k":"A","t":"Gate on a per-dataset sentinel: warm once after ingest, never on restart. [desk rec]"},
   {"k":"B","t":"Remove from the entrypoint; run by CLI when wanted."},
   {"k":"C","t":"Keep as is."}]},
 {"id":"default-queue-sweeps","q":"Clock sweeps on the default queue: move them to the long lane?","status":"open","lane":"00",
  "detail":"EvaluateClocksJob runs every minute on the default queue (tries 1, timeout 60), fires up to 500 timers, and dispatches the residency, critical-population and petition sweeps onto the same lane; SnapshotWorldStatsJob runs nightly there. Nine timeout rows exist with zero timers armed. Founding elections armed by Step 4 are advanced by this engine.","options":[
   {"k":"A","t":"Long lane, chunked, own timeout per job. [desk rec]"},
   {"k":"B","t":"Raise supervisor-1's timeout for the whole default queue."},
   {"k":"C","t":"Keep as is."}]},
 {"id":"dup-legislatures","q":"Two jurisdictions each hold two identical legislature rows: delete the duplicate row?","status":"open","lane":"1",
  "detail":"OPERATOR NOTE 2026-09-05: provisional answer B with 'I dont know what you mean. Are you referring to the Type A map and Type B maps?' RESTATED. Not Type A and Type B: one legislature row holds both chambers (the columns type_a_seats and type_b_seats). This is two ROWS in the legislatures table for the same jurisdiction. Box read 2026-09-05: two jurisdictions are affected; each has two rows created in the same second (2026-08-29 16:05:47) by the parent seeding pass, with identical seats (55 Type A + 25 Type B; 41 Type A + 34 Type B), both forming. The elections and the sim take the first row per jurisdiction; the second row never elects and its map and seats are a shadow. The table has no unique index on jurisdiction_id, so the double insert can recur on the fresh cloud run.","options":[
   {"k":"A","t":"Soft-delete the later row of each pair and its map; add a live-unique index on legislatures(jurisdiction_id) so it cannot recur. [desk rec]"},
   {"k":"B","t":"Keep both rows; the first elects, the second stays forming."}]},
 {"id":"founding-map-mint-phase","q":"Founding-map minting — stay a run-launch step, or move to the ingest tail beside the ledgers?","status":"open","lane":"map",
  "detail":"From the 2026-08-31 pipeline review (the phase list walk). A founding map is an empty container per legislature. Today the RUN mints it at launch (after the adopt pass), and the run's revert deletes and re-mints — the run owns map lifecycle, and adopt distinguishes run-minted from operator-accepted work. The ingest tail now owns the dataset-shaped precomputes (geometry ledger, apportionment ledger), and the same once-per-dataset argument could cover the empty map containers. Moving it changes ownership: revert must spare tail-minted maps, empty containers exist whether or not a run fills them, and the adopt distinction needs a new marker. Functional order today is correct (adopt before mint prevents minting beside accepted work); this question is only about which phase OWNS the container.","options":[
   {"k":"A","t":"Keep at run launch: the run owns map lifecycle end to end (mint, fill, revert). The mint is chunked and fast; nothing is gained but seconds. [desk rec]"},
   {"k":"B","t":"Move to the ingest tail: containers become dataset infrastructure like the ledgers; revert learns to spare them; adopt keys on districts-present only."},
   {"k":"C","t":"Split: tail mints for leaves (which trivially self-fill), run mints for composites."}]},
 {"id":"wos-vcpu-quota","q":"WoS box — raise the Azure vCPUs quota so the box can scale past 8 cores?","status":"open","lane":"00",
  "detail":"Filed by node 00 after the 2026-09-01 crash review. The eastus Dalsv7 family quota is 10 vCPUs (regional total also 10, 2 used elsewhere); the largest self-resize target is D8als_v7 (8c/16Gi). Benchmark ladder sizes D16/D32 are refused at current quota. The raise is a portal request (Quotas > Compute > Standard Dalsv7 Family vCPUs, eastus), usually auto-approved in minutes; cost accrues only when a bigger size runs.","options":[
   {"k":"A","t":"Raise Dalsv7 + regional to 32 vCPUs — unlocks D16 and D32 for the benchmark ladder. [desk rec]"},
   {"k":"B","t":"Raise to 16 vCPUs — unlocks D16 only."},
   {"k":"C","t":"Stay at 10 — benchmarks cap at D8als_v7 on this box."}]},
 {"id":"host-memory-budget","q":"Close the memory derivation into one budget ledger so the app can never exhaust the host?","status":"open","lane":"00",
  "detail":"The WoS freeze (2026-09-01 03:10Z): every memory value is derived, but each derives independently against the whole host and nothing enforces that the parts sum below it. Postgres takes 60% of RAM alone, the etl container is derived-to-unlimited (the live wall governs admission, sets no bound), other containers carry no caps. Commit hit 293% of host and the kernel entered reclaim livelock before its own OOM killer could act: frozen guest, no logs, 18 hours dark. Azure reported the VM running the whole time. Operator constraints (2026-09-01, verbatim intent): values stay derived and expand to the host; a mechanism must hold the total below it (~80%); hard caps acceptable only if OS-derived; no swap. The E box carries the same exposure (sum of its container limits exceeds its host today). Failure contract: app fails, host lives — worker dies, chunk parks, the pg-crash breaker absorbs postgres kills.","options":[
   {"k":"A","t":"Adopt the budget ledger: derived HOST_BUDGET_PCT (default 80), every container cap = a fixed share of the budget, shares sum to 1.0 so overcommit is impossible by construction; enforcement = the cgroup OOM killer. Revises the 2026-08-06 live-wall ruling: the wall stays as governor, the cap becomes the guarantee. [desk rec]"},
   {"k":"B","t":"Ledger + earlyoom on Linux nodes as a second belt (userspace OOM daemon catches any derivation bug that leaks past the caps)."},
   {"k":"C","t":"No caps: harden the live wall's admission instead (peak-sum charging per the lane law). Host stays exhaustible when estimates lag."},
   {"k":"D","t":"A different closing mechanism; the recorded constraint stands: derived, closed, app-fails-host-lives."}]},
 {"id":"era1-retest-maps","q":"First-era problem maps not yet re-tested — which join the retest round before the mass respawn?","status":"open","lane":"map",
  "detail":"Sweep of the surviving first-era record (git era log, engine/test fossils, True-All-Scale + run-6 + pipeline memories). Every jurisdiction fossil in the engine and every draft-campaign specimen (Ukraine, Russia 6x6, Oromia 8+5, Puducherry 16-of-17, Germany +3, Serravalle, Zhoushan, LA/Kentucky/Texas...) is INSIDE the eight standards' scope trees — covered. Named era trouble NOT covered by the block, with the recorded evidence: (1) MALDIVES — the zero-pop atoll district (run-6: Gnaviyani 'FOA' 5 seats @ population 0, Dev -100%, ACTIVATED, 16/21 seats unrepresented; micro-island raster/geometry mismatch); (2) MALAYSIA — champion of the capital-metro stranded-giant class (~8-10% of first-era country sweeps: local-frame giant the root-frame BFS never drills into, 'No compositable children'; siblings Nepal (2 provinces), Portugal/Lisboa, Bolivia/Cochabamba, Cameroon/Mfoundi — one champion exercises the class, the era remedy was geodata repair and a retest shows whether the current engine clears it); (3) BANGLADESH — all-giant-children false-review class (gate fix 712a4fa at 550/551) + delta fragmentation, 552 seats over just 8 divisions = extreme giant recursion; (4) TAIWAN synthetic tree — dual-footprint ISO-independence (Kinmen lives under PRC Fujian; overlap keyed on the Taiwan-province row alone misses Kinmen/Matsu); (5) PUERTO RICO synthetic tree — dual-tree sibling (901 barrios exist only in the PRI tree; the USA standard covers usa-2-puerto-rico, not this legislature); (6) informational first-era giant-drift records: China +10 (1,129 seats/34 provinces — the deepest recursion anywhere), Japan +1 (metro giants + archipelago), Nigeria -1. All candidates verified live on the box with geometry-bearing children. Each pick = auto map on the block engine (6f4ee42) -> your walk -> gate panel if it earns it.","options":[
   {"k":"A","t":"The three era champions: Maldives + Malaysia + Bangladesh — each the named worst of an uncovered class (atoll zero-pop, capital-metro strand, all-giant-children delta). [desk rec]"},
   {"k":"B","t":"Five: the three + the dual-footprint pair (Taiwan, Puerto Rico) — adds the ISO-independence overlap class the pipeline memory flags."},
   {"k":"C","t":"The full named set (the five + China/Japan/Nigeria drift-record nationals) — eight retests, heaviest coverage, China alone is a 1,129-seat run."},
   {"k":"D","t":"None — the block's eight already cover every class champion the era record names inside their scope trees; proceed to the next phase."}]},
 {"id":"gate-expansion-maps","q":"Regression-gate expansion — which national maps join Earth+USA as auto-vs-manual standards?","status":"open","lane":"map",
  "detail":"History mined (lane-H mixed-autoseed record + the engine's comment fossil record + the Good Maps campaign): the broken-map/reprocessing era's failure classes each have a champion jurisdiction. Earth+USA already gate: planetary composite, satellite pools, fat-atom states, leaf-giant line-split (LA), smalls pools, Kentucky share-base, Texas land-then-compete. NOT deeply exercised: deep multi-level giant recursion (India — Draft-9 undercount birthplace, Kerala/Kozhikode one-frame law, UP shatter class), end-to-end archipelago (Philippines — chain adjacency + island exemption + spread-over-water; the iter-12 walk flagged PHL grouping), vertex-monster geometry + giant-separated composite (Canada — Nunavut 5.4M verts, Maritimes class), fat-atom linear chain (Egypt — the Nile 7+7+7+7 probe class), tiny-chamber floor dance (San Marino — 9 castelli, ladder/override at national scale), extreme aspect ratio (Chile — cut-vs-hull anticorrelation champion). Each new gate = one manual blessing (auto-draft + your tweaks, the USA method), stats saved to database/good_maps, added to the per-tweak gate run. Cost: every algorithm tweak re-runs all gates (~5-15 min per national map; India the heavyweight at ~1,118 seats).","options":[
   {"k":"A","t":"Core trio: India + Philippines + Canada — the three heaviest uncovered classes (recursion depth, archipelago, vertex-monster). Gate stays fast. [desk rec]"},
   {"k":"B","t":"Five: the trio + Egypt (linear fat-atom chain) + San Marino (tiny-chamber floor dance — seconds to run, and the planet sweep will hit that class ~hundreds of thousands of times)."},
   {"k":"C","t":"Earth + USA suffice — their 81+30 scopes already contain PHL/India/Canada AS SCOPES; skip national-depth gates and rely on the planet sweep's own review lane."},
   {"k":"D","t":"A different set — name the jurisdictions from your memory of the broken-map pile (note them in the box)."}]},
 {"id":"good-maps-adopt","q":"Good Maps achieved — adopt iteration 12 and proceed to the planet re-hook?","status":"open","lane":"map",
  "detail":"Campaign 2026-08-23, twelve full-planet iterations against your two standards (record: database/good_maps/, scoreboard artifact bf1116d9). Iteration 12 (engine 0c60da4) vs your maps, in your priority order — Earth: legality parity (2003 exact) · contiguity BETTER (19 clusters vs 21) · compactness BETTER (CHR .6334 vs .6320) · deviation ~parity (fit 37.86 vs 37.21). USA: legality parity (702 exact) · contiguity BETTER (5 vs 7) · compactness BETTER (.7729 vs .7677) · deviation trails (12.33 vs 10.96 — the measured price of the two wins above it; iterations 10–11 proved the frontier both ways). Known per-scope residue: California .740 vs your .834 (the 9.5-ceiling fat-assembly wall — fat plans strand members during BFS growth; a deeper structural build, deliberately deferred). Twelve draft maps from the campaign sit on the box under 'Good Maps — Auto Iteration N'.","options":[
   {"k":"A","t":"Adopt: iteration 12's engine is the auto-districting result; delete iterations 1–11's draft maps (keep 12's two as reference), and the planet-wide Type A re-hook ('Start planet-wide generation') runs on this engine. [desk rec]"},
   {"k":"B","t":"Adopt after your eye: walk the two iteration-12 maps in the mapper first (they are ordinary draft maps in the picker); then A."},
   {"k":"C","t":"One more push first: crack the California fat-assembly wall (assembly must reach 9.4-frac bins) before the re-hook — a structural build with planet-wide regression risk, worth its own session."}]},
 {"id":"cloud-geodata-source","q":"First cloud box — how does it get the planet's geodata?","status":"open","lane":"2",
  "detail":"Setup-loop audit 2026-08-23 (code read, not docs): a cloud VM has NO /archive, and the wizard's only in-box alternative — 'Download from official sources' — is hard-routed to the LEGACY single-threaded seeder (Step2_MapData.vue: 'a download run always uses legacy'; seed_database.py last touched 2026-05-25, attribution = the old per-level population_within() SQL the grid engine replaced). The proven 3h20m engine is the PULL engine, which only accepts archive|folder. The downloader writes exactly the layout the pull engine reads (geoBoundaries_repo/releaseData/gbOpen + worldpop_100m_latest under /data), so the pieces fit — the wizard just never offers download→pull. Also: maps:export/maps:import (export-bundle-equals-seed) can carry the finished planet from the home box instead of re-ingesting. FRESH-NODE-START-CLOUD.md + the rehearsal runbook are silent on the data step.","options":[
   {"k":"A","t":"First cloud run: stage the ~14 GB archive on the VM data disk (scp/rsync from D:\\fair-constitution-map-files), set ARCHIVE_PATH, force-recreate etl+app, run the PULL engine from 'Local archive'. Zero code; the engine that already holds the record. Doc the step in FRESH-NODE-START-CLOUD.md. [desk rec for the FIRST box]"},
   {"k":"B","t":"Carry the finished planet: maps:export on the home box (skip-rasters optional) → upload/import on the cloud box → accept. No ETL on the cloud at all; the cloud box inherits the accepted map (and, if you choose, the manual districts)."},
   {"k":"C","t":"Build download→pull: add source=download to pull-start (downloader step first, pool mode after, pump waits on the download phase), retire the legacy-seeder routing for downloads. The right long-term shape for instant-deploy templates; a real build, not a first-box task."}]},
 {"id":"cloud-burst-architecture","q":"Cloud world build: what machine shape runs the big ingest, and what happens to it after?","status":"open","lane":"2",
  "detail":"Full research: docs/plans/launch/CLOUD_BURST_SCALING.md. Short form: the engines size themselves from whatever host they wake on, one 48-core VM reaches the current 56-lane postgres ceiling, and the full build burst costs $33 to $200 pay-go or $8 to $46 spot. Serverless cannot serve the game itself (no inbound UDP for voice). A deallocated VM bills zero compute and resizes in place.","options":[
   {"k":"A","t":"One standard VM, the resize law: create D48 or D64 pay-as-you-go, run the full build (about $33 to $200 total), then deallocate, resize to D4as_v5, start. Simplest, one identity, disks persist, no eviction risk. [desk rec]"},
   {"k":"B","t":"Spot build box: same build for $8 to $46. Azure can evict with 30 seconds notice (the chunked resumable engines tolerate this; an eviction costs one chunk). Because spot cannot become standard, serving afterward means creating a small standard VM and attaching the same data disk. Saves roughly $25 to $150 for extra steps."},
   {"k":"C","t":"Container Apps Jobs worker burst: queue-scaled worker containers that scale to zero, next to a small always-on VM. Needs an image registry and VNet wiring, and the postgres ceiling still binds. The right long-term shape for instant-deploy volunteer templates; a real build, not the first run."}]},
 {"id":"cloud-idle-posture","q":"Cloud box between play sessions, before public launch: keep it running or deallocate?","status":"open","lane":"2",
  "detail":"A deallocated VM bills zero compute; disk plus static IP is about $45/mo. Always-on small is about $170/mo. Start takes about 2 minutes. Applies only to the window before real players exist.","options":[
   {"k":"A","t":"Deallocate between sessions until launch: about $45/mo idle, start on demand for playtests. [desk rec pre-launch]"},
   {"k":"B","t":"Always on from day one: about $170/mo, the box is continuously reachable for peers and testing."}]},
 {"id":"cloud-region","q":"Which Azure region hosts the first cloud box?","status":"open","lane":"2",
  "detail":"Prices verified for East US 2. Pay-go, spot, and eviction rates differ per region; the portal compares them side by side at create time.","options":[
   {"k":"A","t":"East US 2: prices verified there, near the home mesh. [desk rec]"},
   {"k":"B","t":"Another US region picked at create time by comparing spot price and eviction rate in the portal."},
   {"k":"C","t":"A European region: closer to September travel, higher latency to the home mesh, prices unverified."}]},
 {"id":"population-mode-autoboot","q":"Population mode — should CLK-06 actually BOOT a place, or only record the crossing?","status":"open","lane":"3",
  "detail":"Setup-loop audit 2026-08-23: acceptMaps documents 'population → CLK-06 boots each place as verified residents cross its threshold', but in code EvaluateCriticalPopulationJob only calls ActivationService::onCriticalPopulation, which writes the activation row to critical_population and audits it. Nothing then calls activate() — its callers are the CLI (jurisdiction:activate), the dev controller and elections:demo (the job's own docblock says so). So a population-mode world stalls at the crossing: no legislature sized, no map, no board, until the operator runs the CLI per place. ScheduleFoundingElectionJob already assumes population mode auto-activates ('a place activates because residents arrived').","options":[
   {"k":"A","t":"Close the loop: on a crossing in population mode, dispatch a per-place boot job (the same WF-JUR-01 pipeline ActivateSubtreeJob drives — seed + jurisdiction:activate, WITH the founding election since residents are real). Pin it. [desk rec]"},
   {"k":"B","t":"Keep the crossing as consent-only and make the operator's activation the explicit act — then fix acceptMaps' wording + the mode's description so the UI doesn't promise a boot."}]},
 {"id":"subtree-activation-shape","q":"The '+ children' button activates a place and every place under it. How should the system do that work?","status":"open","lane":"map",
  "detail":"REWRITTEN IN PLAIN LANGUAGE (operator order 2026-08-29). The button on the jurisdiction viewer activates one place plus everything below it. Example: Sri Lanka holds 14,409 places. Today the system boots them one at a time, start to finish, with no progress display. That is about 29,000 commands and hours of waiting. Most of the work can be done in bulk within minutes: sizing each place's seats and creating each place's board (the bulk method already exists and is proven). One part cannot be bulked: each place's FIRST ELECTION, because every election writes its own tamper-proof audit records one at a time. The choice: what should the button include?","options":[
   {"k":"A","t":"Bulk the seats and boards; skip the first elections. Every place is ready in minutes. Elections then happen through normal play or the simulation, which runs its own. [desk rec]"},
   {"k":"B","t":"Keep the first elections, but run many places at the same time with a progress bar. Faster than today, much slower than A."},
   {"k":"C","t":"Change nothing. The button is rarely used, so slow is acceptable."}]},
 {"id":"sim-org-bill-rates","q":"Sim org + bill generators — what should the simulation create, and how much?","status":"open","lane":"sim",
  "detail":"The sim now seats chambers, grows committees/departments, and forms courts through the real forms — but generates ZERO organizations and ZERO bills (only 'org affinity' priors are reserved in the plan). Rates are policy-flavored choices, not derivable from code. Real-world anchors: effective parties per chamber 2–8; US nonprofits ≈ 1 per 180 people; bills: one founding bill per committee ties to the already-ruled K(S) formula.","options":[
   {"k":"A","t":"Minimal-legible: 3 parties per active chamber + 1 founding bill PER COMMITTEE (rides K(S)) + a handful of orgs per local place — dialed via config, demo-scale not census-scale. [desk rec]"},
   {"k":"B","t":"Census-flavored: per-capita org rates (parties, nonprofits, businesses) + bills per session — realistic but mints millions of org rows planet-wide."},
   {"k":"C","t":"Defer both — demo ships with governance+courts only; orgs/bills stay player-driven."}]},
 {"id":"sim-leaf-courts","q":"Leaf-jurisdiction courts in the sim — when to build the committee-slate nomination round?","status":"open","lane":"sim",
  "detail":"JudiciaryStage forms constituent-mode courts (every non-leaf: Earth 232, USA 56, …) through F-LEG-017/021. LEAF courts derive COMMITTEE nomination — a different service verb (slate gated on a passed committee act) — and currently defer-with-reason on their sim items. ~90% of jurisdictions are leaves, but their courts are the least demo-visible.","options":[
   {"k":"A","t":"Next sim round, before the demo — full courts everywhere. [desk rec if the demo drills into villages]"},
   {"k":"B","t":"After the demo — the mapped/governed tiers carry the demo; leaf benches follow."}]},
 {"id":"edu-arming","q":"Education arming sequencing — how do untrained demo members behave when the gate arms?","status":"open","lane":"15",
  "detail":"education:seed arms the act-gate for 6 civic tracks; every untrained role-holder then redirects on their next role-act. Gates your browser walk of the training gate.",
  "options":[{"k":"A","t":"Pre-train demo members (seeders file F-EDU-001) — the walk shows a trained fleet. [lane 15 rec]"},
             {"k":"B","t":"Seed and leave demo members untrained — the walk DEMOS the redirect→train→act loop live."},
             {"k":"C","t":"Don't seed this wave — the gate is proven by the e2e only, no live walk."}]},
 {"id":"mass-pass","q":"Game-box mass pass — run the Type B mapper over the real ~9,708 flagged chambers?","status":"open","lane":"1",
  "detail":"The ~9,708 flagged chambers live on the GAME box, not dev. Waits on the Type B race fix so cleared chambers get the correct race.",
  "options":[{"k":"A","t":"After the race fix, pull lane 1's commits to the game box and run the mass pass now (ETL-chunked)."},
             {"k":"B","t":"Defer to the Wave-4 cloud rehearsal."}]},
 {"id":"lane3-compact","q":"Lane 3 compaction — run the keystone exit walk with fresh context?","status":"open","lane":"3",
  "detail":"The Live Civic Room is built but not yet WALKED end-to-end (the acceptance gate). The exit walk needs lane 3 compacted.",
  "options":[{"k":"A","t":"Compact lane 3 now — it resumes straight into seating a committee + the exit walk."},
             {"k":"B","t":"Hold lane 3 for now."}]},
 {"id":"ranked-live","q":"RankedBallot live standings — spin the secrecy-critical build?","status":"open","lane":"3",
  "detail":"Live provisional standings during an OPEN ranked ballot, without an in-request decrypt. Cold-start spec ready; cadence ruled daily-batch.",
  "options":[{"k":"A","t":"Trigger the fresh-session build now."},
             {"k":"B","t":"Defer — the electoral partial stays as-is."}]},
 {"id":"secondary-trade","q":"Secondary share trading — pull into Wave 4 or leave deferred?","status":"open","lane":"13",
  "detail":"You ruled share ISSUANCE (delivered). A holder RESELLING issued shares needs its own schema.",
  "options":[{"k":"A","t":"Pull into Wave 4 — lane 13 builds share resale on the exchange."},
             {"k":"B","t":"Leave deferred — the exchange shares floor stays honest-empty."}]},
 {"id":"handshake-4xx","q":"Cross-class federation handshake — return a graceful 4xx instead of 500?","status":"open","lane":"2",
  "detail":"A genuine cross-class handshake surfaces the class-rule refusal as an uncaught 500 rather than a 409/422. Pre-existing.",
  "options":[{"k":"A","t":"Fix in Wave 4 — catch it, return 409/422 gracefully."},
             {"k":"B","t":"Leave as-is (pre-existing, low priority)."}]},
 {"id":"b2-pairing","q":"B2 remainder rule — compact-first vs strictly-lowest-population pairing?","status":"open","lane":"1",
  "detail":"On real adjacency, compactness drives which children pair (population only orients the walk head). Lane 1 shipped compact-first.",
  "options":[{"k":"A","t":"Keep compact-first (shipped, matches intent)."},
             {"k":"B","t":"Force strictly-lowest-population pairing even when less compact."}]},
 {"id":"oversight-live","q":"Oversight — does 'public to watch' extend to the LIVE console of in-progress proceedings against NAMED members?","status":"open","lane":"3",
  "detail":"§10-1 makes government proceedings public. Open: the LIVE console of an in-progress removal/discipline against a named member, or only the sealed public record after?",
  "options":[{"k":"A","t":"Keep the live console gated; the public RECORD stays public. [desk rec]"},
             {"k":"B","t":"Make the live console public too (fully open in-progress)."}]},
 {"id":"orphans","q":"Orphan-surface deletions — remove unreferenced surfaces?","status":"open","lane":"6",
  "detail":"e.g. Elections/CandidateProfile.vue (unreferenced) + a couple of orphan surface records.",
  "options":[{"k":"A","t":"Delete the orphan surfaces."},
             {"k":"B","t":"Keep them for now."}]},
 {"id":"q4a-rooms","q":"Q4a — provisioning can't materialise court tiers / extra civic rooms (the schema forbids it). How should the scaling model resolve this?","status":"open","lane":"4",
  "detail":"One live court per jurisdiction (hierarchy is expressed ACROSS THE TREE via parent_judiciary_id); one public space per type. courtTiers/extraRooms have no lawful shape as extra rows at one place. The min_judges-from-tier fix already wires the meaningful bench scaling. Your framing: the court JURISDICTION stays singular; the scalable thing is rooms/chambers within the infrastructure.",
  "options":[{"k":"A","t":"Reframe courtTiers as a jurisdiction's tree-DEPTH; extra rooms = group-type or a future room model. Doc amendment, no schema, nothing built moves. [desk rec]"},
             {"k":"B","t":"Weaken the two uniqueness constraints (allow duplicate courts/squares). Needs a migration; trades two real safety rails."},
             {"k":"C","t":"Defer past this wave — the min_judges-from-tier fix already advances the scaling capability; build the room model later."}]},
 {"id":"advocate-gate","q":"Should advocates have a qualification catalog + an approval lifecycle, or stay an instant competence register?","status":"open","lane":"6",
  "detail":"The advocate-registration mockup wanted 'I attest to X law' checkboxes + a 'pending judiciary review' banner. F-IND-015 registers instantly (rejecting only on association + duplicate) — the bar is a competence REGISTER, not a merits gate on a client's Art. I right. A catalog + pending→approved lifecycle would be a RULE change + an advocates.status CHECK migration. Held honest-empty, flagged not smuggled.",
  "options":[{"k":"A","t":"Keep it a competence register — instant, no merits gate. [held honest-empty; desk rec]"},
             {"k":"B","t":"Add a qualification catalog + an approval lifecycle (rule change + schema)."}]},
 # ── Setup + Jurisdiction-Viewer walkthrough, opened 2026-08-04. Raised during
 #    the operator's live walk of the post-ingestion screens; lane "map"/"ui"
 #    rather than a fleet number (this is direct desk work, no lane owns it). ──
 {"id":"map-adopt-scope","q":"Map adoption — planetary only, or scoped per jurisdiction?","status":"open","lane":"map",
  "detail":"Your two phrasings point different ways: 'this is the map that applies to this CHAIN of jurisdictions' reads scoped; 'we as a planet kinda need to agree' reads planetary. Decides whether adoption carries a jurisdiction_id and whether a child can adopt a geography its parent has not.",
  "options":[{"k":"A","t":"Planetary only — one Earth-wide geography per adoption; every jurisdiction inherits it. Simplest, and matches 'we as a planet need to agree'. [desk rec for v1]"},
             {"k":"B","t":"Scoped — any jurisdiction adopts for its own subtree; children inherit unless they adopt their own. Federation-shaped, much larger build."},
             {"k":"C","t":"Planetary now, scoped-ready later — ship planetary but carry the scope column so B is additive, not a rewrite."}]},
 {"id":"map-select-authority","q":"Who SELECTS which draft map becomes the next one?","status":"open","lane":"map",
  "detail":"Given the ruled lifecycle (drafts → one selected → locked in → effective at a term boundary): anyone may DRAFT, but selection is the consequential act — it redraws every district for the coming term. Your census example sets the cadence by rule ('if we set a rule that we do a census every ten years'), which reads legislative, but you did not say who pulls the trigger on the map itself.",
  "options":[{"k":"A","t":"Bicameral legislative act, like any other — both chambers agree on the next map. Matches 'we as a planet need to agree'. [desk rec]"},
             {"k":"B","t":"Referendum / constituent supermajority — it changes everyone's district, so it goes to the people."},
             {"k":"C","t":"Operator/admin act — geography stays infrastructure even after setup."},
             {"k":"D","t":"Whoever the standing census RULE names — selection is automatic from the rule (census year → derived map → next term), with a legislative act only to override."}]},
 {"id":"merge-bulk","q":"11,919 same-space chains, no bulk apply — how do we work that queue?","status":"open","lane":"map",
  "detail":"Every repair endpoint is one-chain-per-POST, and the repair window shuts on acceptance. Working 11,919 by hand is not a thing. They are real-world (a source recording one village at two ADM levels), concentrated in CZE/SVK/IND/JAM/AUT — not damage.",
  "options":[{"k":"A","t":"Build a filtered bulk apply — 'merge all chains in ISO X' / 'at level N' / 'all', chunked + resumable per the ETL rule. [desk rec]"},
             {"k":"B","t":"Collapse them at INGEST — a single-child same-space pair merges on import, so the queue never fills with them."},
             {"k":"C","t":"Leave manual and accept with them open — they are real geography, and the flag is informational."}]},
 {"id":"adm-empty-walk","q":"The empty-ADM-level walk — fix it before the next fresh run?","status":"open","lane":"map",
  "detail":"Pass 2 walks levels 0-5 for every country unconditionally, and discover_geoboundaries_files() re-lists the whole 715-entry tree on EVERY call with no memoisation — measured 11.07 s a call. 678 nonexistent levels planet-wide ≈ 2 h of aggregate lane time, ~25 min of wall clock per run. The metadata that says which levels exist is ALREADY loaded in the same function (used for the split decision) and simply is not consulted.",
  "options":[{"k":"A","t":"Fix both now — skip levels the metadata says are absent, and memoise the directory walk. Small, self-contained, ~25 min/run back. [desk rec]"},
             {"k":"B","t":"Memoise the walk only — the cheap half, keeps the level loop untouched."},
             {"k":"C","t":"Leave it — the run completes correctly, it is only slow."}]},
 {"id":"list-stats-columns","q":"Jurisdiction list — which statistics become sortable columns, over what scope?","status":"open","lane":"ui",
  "detail":"You asked for the statistics on columns, sortable, 'for their internal chain'. /api/geodata/flags has NO jurisdiction scoping at all — no subtree filter, no per-row rollup — so this needs a new endpoint before any column can exist. Scope decides how expensive that endpoint is.",
  "options":[{"k":"A","t":"Subtree rollup per row — the 7 map-health checks + populated/total + population, counted over each row's descendants. Most useful, needs a recursive-CTE endpoint. [desk rec]"},
             {"k":"B","t":"Own-row + direct children only — much cheaper, no recursion, less informative at Earth level."},
             {"k":"C","t":"Defer until the jurisdiction map viewer conversation you flagged as coming next."}]},
 {"id":"setup-shell-menus","q":"Setup inside the main shell — which menus unlock when?","status":"open","lane":"ui",
  "detail":"You asked for the nav bar and menu present during setup (you are authenticated anyway), with irrelevant menus locked. Needs a rule for what 'relevant' means at each step.",
  "options":[{"k":"A","t":"Lock everything except Setup, Jurisdictions and Learn until setup completes — the two surfaces setup actually uses, plus help. [desk rec]"},
             {"k":"B","t":"Unlock progressively — each completed setup step unlocks the menus it enables (elections after districting, etc.)."},
             {"k":"C","t":"Unlock everything and let empty states speak for themselves; setup is just another surface."}]},
 {"id":"guest-banner","q":"'You're viewing as a guest' — where does it go?","status":"open","lane":"ui",
  "detail":"Today a full-width banner eating the fold above the map. You said it is 'kinda like a pop-up thing, and I guess that can be in the map area somewhere'.",
  "options":[{"k":"A","t":"Dismissible chip overlaid in a map corner — present, not blocking. [desk rec]"},
             {"k":"B","t":"Collapse into the header bar as a small badge beside Log in / Register."},
             {"k":"C","t":"Keep it a banner but show once per session, then remember the dismissal."}]},
 {"id":"about-surface","q":"'About this surface' on the viewer — move to Learn, or delete?","status":"open","lane":"ui",
  "detail":"You said it 'doesn't need to exist' on the viewer and belongs in the Learn tab. Confirming whether the CONTENT survives, because deleting is not the same as relocating.",
  "options":[{"k":"A","t":"Move the content into the Learn tab, remove the block from the viewer. [desk rec]"},
             {"k":"B","t":"Delete outright — the surface explains itself."},
             {"k":"C","t":"Keep on the viewer but collapsed by default."}]},
 # ── District-mapping INTEGRATION package (the four scaling docs, stored
 #    ea1ed8e). Reviewer-flagged operator questions; lane "scale". These gate the
 #    Fable-5 integration build that follows the geodata run. ──
 {"id":"scale-committees","q":"Committee provisioning — eager (built up front) or tier-gated (created at chamber act)?","status":"open","lane":"scale",
  "detail":"Setup audit §3: committees are NOT in the eager provisioning STEPS; Committee::create runs only in CommitteeService at chamber-act time. That is consistent with the tier dial (\'what exists in a place is a function of how far it has come\'), but your stated expectation was \'should be built out already\'. The reviewer asks you to reconcile the two explicitly so it is not a surprise during setup review. Your answer seeds a disposition table naming every sub-institution family\'s class.",
  "options":[{"k":"A","t":"Tier-gated — a committee is a chamber\'s ACT, created when the chamber acts (matches current code + the tier-dial doctrine). [reviewer\'s read of the code]"},
             {"k":"B","t":"Eager — provision committees up front in STEPS alongside executives/judiciaries, so an accepted planet already has them."},
             {"k":"C","t":"Per-family disposition table — committees tier-gated, other sub-institutions (departments, oversight organs, Matrix rooms) each get their own class; specify in notes."}]},
 {"id":"scale-record-disposition","q":"On disintermediation, how do a dissolving intermediary\'s NON-ACT records move to its constituents?","status":"open","lane":"scale",
  "detail":"Courts addendum §5 — the one place the package explicitly requests your review BEFORE build. Acts already clone-merge to each constituent as an independent copy with full history (built, F-LEG-030). The sketch extends that to the sealed records of ALL branches: chamber votes & proceedings, executive records & offices, judicial records. Each family needs a disposition — COPY-PER-CONSTITUENT (like Acts), SEAL-ONLY (immutable snapshot on the dissolved row, constituents start fresh), or TRANSFER. (Open cases and sitting judges are the two questions below.) The reviewer also asks that whatever table this produces read correctly IN REVERSE for `union`, or the asymmetry be justified.",
  "options":[{"k":"A","t":"Mirror Acts — copy-per-constituent WITH history for every record family; the two hard cases handled separately. Symmetric with union by construction. [desk rec — least surprise, matches the built Act path]"},
             {"k":"B","t":"Seal-only — snapshot the intermediary\'s records immutably; constituents inherit a citation, not the content. Cleanest, loses continuity."},
             {"k":"C","t":"Per-family — you specify copy / seal / transfer for chamber, executive, and judicial each, in notes."}]},
 {"id":"scale-case-venue","q":"Open court cases when an intermediary dissolves — where does venue go?","status":"open","lane":"scale",
  "detail":"Courts addendum §5 hard case. A case in progress at the dissolving intermediary\'s court needs a new venue among the now-independent constituents. Where court panels exist (§3.2), the panel a case belongs to is a natural target; without panels the choice is open.",
  "options":[{"k":"A","t":"To the panel where panels exist, else to the encompassing (grandparent) court the constituents re-parent to. [desk rec — uses the structure §3.2 already builds]"},
             {"k":"B","t":"To the specific constituent court the case\'s parties/territory map to (case-by-case re-venue)."},
             {"k":"C","t":"Seal and require refiling — the case closes without prejudice at dissolution; parties refile in the successor court."}]},
 {"id":"scale-judge-tenure","q":"Sitting judges when their court\'s jurisdiction dissolves — serve out, or close?","status":"open","lane":"scale",
  "detail":"Courts addendum §5 hard case; the doc notes \'Art. IV needs to say so.\' The B7 serve-out doctrine (a fresh grouping while sitting members serve out) suggests seats close at TERM rather than at dissolution, but the constitution has not stated it for judges specifically.",
  "options":[{"k":"A","t":"Serve out the 10-year term — seats close at term end (mirrors the B7 serve-out doctrine); the judge migrates with the case load to the successor venue. [desk rec — consistent with existing serve-out]"},
             {"k":"B","t":"Close at dissolution — the court ceases with its jurisdiction; appointments end and the successor court re-nominates."},
             {"k":"C","t":"Migrate to the successor court for the remainder of the term, re-confirmed by the successor\'s nomination process."}]},
 {"id":"protomaps-online-fallback","q":"Basemap while the protomaps file downloads — online fallback, local seed, or none?","status":"open","lane":"map",
  "detail":"The self-hosted basemap is a ~128 GB pmtiles download that runs as a detached lane and can take many hours; until it lands, map surfaces have no basemap tiles (jurisdiction geometry still renders — this is cosmetics under the geometry, display-only, never gates ingestion). Operator asked (08-29) whether an online source could serve as a fallback meanwhile. Trade-off: self-host philosophy (a federation box should not depend on third parties) vs a blank background for the first hours of a new box. OSM's public tile servers prohibit production app traffic; Protomaps offers a hosted keyed API (free tier); Protomaps also publishes small low-zoom planet extracts (tens of MB) that could download FIRST in seconds and self-host immediately, upgraded in place when the full file lands.",
  "options":[{"k":"A","t":"Low-zoom local seed first — fetch a small z0-8 planet extract before the full file; instant self-hosted basemap, no third party, upgrades in place. [desk rec — preserves self-hosting, smallest build]"},
             {"k":"B","t":"Hosted API fallback — style points at Protomaps' keyed API until the local file is ready, then flips. Needs an API key per box and sends viewer traffic to a third party meanwhile."},
             {"k":"C","t":"Both — seed immediately, API only if the seed also hasn't landed yet."},
             {"k":"D","t":"None — blank background until the full file arrives (current behaviour)."}]},
 {"id":"box-vm-memory","q":"The database keeps getting killed for lack of memory during heavy scans. Give Docker's virtual machine a larger share of the computer's memory?","status":"open","lane":"ops",
  "detail":"The computer has 15.8 GB of memory. Docker's virtual machine gets the Windows default of half: 8 GB. Inside that, the database container is allowed 4.6 GB and sits near full; each heavy map-scan pass pushes one database process over the line and the system kills it. Today: six kills in two hours. Every kill self-healed (the engine reclaims and retries, no data lost), but each cost minutes to half an hour of waiting, and one made a scan detector report an error instead of a number. The fix is a small config file (.wslconfig) raising the virtual machine's share, then a Docker restart: the box is down about two minutes, done between runs. The same pressure existed on the C box; it was luckier.",
  "options":[{"k":"A","t":"10 GB to the virtual machine; Windows keeps 6. The database cap rises to about 6 GB. Comfortable for Windows and the browser; should end the kills. [desk rec]"},
             {"k":"B","t":"12 GB to the virtual machine; Windows keeps 4. Most headroom for the database; Windows may feel tight while the box grinds."},
             {"k":"C","t":"Leave it at 8 GB. Kills continue now and then; the engine keeps absorbing them with retries."}]},
 {"id":"ingest-tail-apportionment","q":"Move apportionment and the border precompute into the ingestion run itself?","status":"open","lane":"map",
  "detail":"Ruled 2026-08-29 (see answer). The border precompute needs only shapes and the tree; sizing needs only population plus the constants, which the wizard authors before map data loads. Neither needs map acceptance — unaccepted data makes them moot, not wrong. Folding both into the ingestion tail means a fresh box arrives at the district phase already sized and border-paid, cutting about seventy minutes of waiting from the interactive path.",
  "options":[{"k":"A","t":"Build it for the next fresh box: ingestion tail runs sizing + border precompute after finalize; repairs that change parentage re-queue just the touched parents. [desk rec]"},
             {"k":"B","t":"Keep them where they are (acceptance-triggered)."}]},
 # --- resolved (read-only, recorded) ---
 {"id":"map-adopt-lifecycle","q":"Map re-adoption after setup — is the certification lock overturned?","status":"resolved","lane":"map",
  "detail":"RULED 2026-08-04: NOT OVERTURNED — EXTENDED. A certified map is never reopened; the desk had this backwards and proposed an overturn. Instead: after setup, maps made in-game are DRAFTS, and one draft is SELECTED as the next map. Selection LOCKS IT IN but does NOT take effect in the moment — the new geographic reality arrives when the next TERM starts. Operator's worked example (rules, not code): a rule sets a census every 10 years on the zero year → census 2030; terms run on 0 and 5 at 5-year length → the maps derived from that census take effect 2035. ⚑ TIMING WRINKLE, parked by the operator ('we'll have to explore when we get there'): districts must be remapped, and elections open INSTANTLY at the end of a term for the next term — so a new map can only land in the next election that has NOT yet opened, which is the term AFTER the next one. Consequence for the four sub-questions the desk raised: institutions already elected are never disturbed, because adoption only ever lands on a term boundary."},
 {"id":"map-fork","q":"Can a certified map be used as the basis for a new one?","status":"resolved","lane":"map",
  "detail":"RULED 2026-08-04: a certified map cannot be REOPENED, but a NEW map may be created BASED OFF an old map — fork/clone-from-existing as a first-class action. Operator: 'That would be a really cool and convenient mechanism. I would add that to every mapper.' Applies to EVERY mapper — jurisdiction mapper and district mapper alike. This is the mechanism that makes the draft lifecycle usable: you start the next map from the certified one rather than from nothing."},
 {"id":"typeb-shape","q":"Type B race shape — pooled vs per-child/per-clump?","status":"resolved","lane":"1",
  "detail":"RULED per-child/per-clump (each child, or clump, is its own at-large race). CLAUDE.md corrected @55b8846. Build = Wave 4 (lanes 1+3)."},
 {"id":"video","q":"Video library / multi-track player — build from scratch?","status":"resolved","lane":"5",
  "detail":"NO from-scratch build — the operator's player already exists; the mockups are based on it. Wave 4 = integrate it (ref fleet-11 + coalition site)."},
 {"id":"founding-stake","q":"Founding-stake-on-registration — auto-equity when an org is founded?","status":"resolved","lane":"13",
  "detail":"DEFERRED to Wave 4, structure-aware (100% stake wrong for member-owned/nonprofit; only stock has shares)."},
 {"id":"setup-order","q":"Setup order — account-first (mockup) or fork-first (ruling)?","status":"resolved","lane":"2",
  "detail":"RULED FORK-FIRST: join-or-start, THEN account. Mockup swapped; SetupController already fork-first."},
 {"id":"oversight-public","q":"Oversight console — public or gated?","status":"resolved","lane":"3",
  "detail":"RULED PUBLIC ('public if it's government'; no closed-session provision). Console read public; write controls authenticated. @4057b3c."},
]
# Operator answers (2026-07-29) — flip the 9 open to RESOLVED with the ruling folded in.
_ANS = {
 'step5-readiness-guard':('A','Block completion by default while any verify scope is in review; force=true records the outstanding list into setup_completion_notes; a done run with zero verify items is verification pending until the bounded verify phase runs; no new table or column.','"I have no formal opinion and defer to the desk." (2026-09-13)'),
 'sim-resume-cursor':('A','Keyset walk returning scanned and inserted together; durable enum_cursor jsonb on sim_runs (additive migration); a resume reads adm_max and limit from the stored run options; the chunk size is derived from the host through HostCapacity (env-overridable) and the two sibling constants are retrofitted in the same build.','"I have no formal opinion and defer to the desk." (2026-09-13)'),
 'progress-polling-bounds':('A','World totals from a pump-tick rollup, O(1) counters replace the email LIKE scan, every poll aggregate behind the scheduler-warmed snapshot with no inline cold scan; the co-determination backstop reads the demo/dev clock; the ordering-key derive chunks only its geometry scan by keyset under a resumable phase marker.','"I have no formal opinion and defer to the desk." (2026-09-13)'),
 'join-rerun-identity':('A','Scripts self-detect a rerun; federation:init never rotates on --join; an explicit --clone-rekey / -CloneRekey flag is the only rotate; a departed membership or exhausted key fails loud with the instruction to mint a fresh key on the host.','"I have no formal opinion and defer to the desk." (2026-09-13)'),
 'bootstrap-project-and-failures':('A','bootstrap.sh uses the project deploy.sh pinned into .env everywhere; federation:init and transport:register fatal; directory:publish informational only for the genuine no-authority state; a final fatal mesh:gates; the Windows pair fixed in the same build.','"I have no formal opinion and defer to the desk." (2026-09-13)'),
 'join-async-dispatch':('A','cluster:join defaults to async with --sync opt-in and resumes an existing membership without re-admitting; deploy.sh dispatches after app, horizon, scheduler restart and nginx up.','"I have no formal opinion and defer to the desk." (2026-09-13)'),
 'import-finalization-bounds':('A','The authority stamp rides each drained page; the tarball path is legacy with a chunked keyset stamp; completion = page ledger plus one index-assisted exists() probe; denominator from pg_class.reltuples; geodata counts behind the existing 8-second cache.','"I have no formal opinion and defer to the desk." (2026-09-13)'),
 'interjurisdictional-doors-s2':('A','Constituent consent reuses the generic constituent_consent chamber-vote arm with union_processes and disintermediation_processes branches; the border settlement opens from the Between Governments page; restoration gains declare, confirm, tier and complete doors; each history is a bounded 25-row cursor page with its own scoped token. OPERATOR NOTE folded in: this world is built pre-unioned (Earth is the root jurisdiction), so union formation is a door for future worlds and sub-unions, not a demo path; build it lawful, do not stage it for the demo.','"TECHNICALLY THIS WORLD IS BUILD PREUNIONED SINCE EARTH JURISDICTION IS A THING. Otherwise I have no formal opinion and defer to the desk." (2026-09-13)'),
 'lesson-publication-shape':('A','Structural publish: the handler writes the module row (title, surface, status, revision_number, published_by, published_at via one additive migration) through EducationCatalogService; prose stays in the K-2 source and generator; an R-23 editor page lists modules and files F-EDU-002; under a demo session the write is captured and reversed at session end like any other demo write.','"I have no formal opinion and defer to the desk." (2026-09-13)'),
 'lesson-video-association':('A','The association is authored in the K-2 source and emitted by the generator (no DB, no form); the player renders on the lesson page and the Learn flyout deep-links the assigned video. OPERATOR NOTE folded in: for the demo he records ONE video and reuses it across the slots, with the promise of future videos for the other slots; so the K-2 source carries a default demo video every surface falls back to, per-surface overrides replace it as videos arrive, and the copy says so honestly.','"I have no formal opinion and defer to the desk. For the Demo itself I will record one video to reuse with the promis of future videos for other slots." (2026-09-13)'),
 'achievement-wiring-scope':('A','Personal ACH-* keys only this pass: subject awards at their action sites and an idempotent sweep command for state awards from the fact tables plus write-site calls where the seat is minted; demo awards are captured and voided like any other demo write; the jurisdiction/system milestone plane is a tracked follow-up.','"I have no formal opinion and defer to the desk." (2026-09-13)'),
 'org-membership-agent-rules':('A','Keep the built engine behaviour: any registered user may be named agent; declined is terminal for that row and a fresh application is allowed; the agent transfer is unilateral (no consent step).','A, no note (2026-09-13)'),
 'cgc-governor-removal-shape':('A','One owner-neutral service path: requestRemoval / resolveRemovalVote resolve the consenting body from the board owner; F-EXE-003 reused with dual-owner handler routing like F-EXE-001; the overseeing executive initiates, the creating legislature consents by ordinary majority; the board composition refresh runs on adoption.','A, no note (2026-09-13)'),
 'org-staff-delegation-model':('A','New form F-ORG-011 Staff Delegation: grant and revoke are audited acts (the exact form-count pin rises deliberately); coarse task buckets profile, membership, contracts, documents, hiring, shares; grants persist across an agent reassignment and the new agent sees and can revoke them; reassign_agent and grant/revoke are never delegable.','A, no note (2026-09-13)'),
 'challenge-tracker-controls':('A','POST routes and controls for all four existing handlers on the tracker (finding F-JDG-004, recommendation F-JDG-005, override F-LEG-035, direct remedy F-JDG-006), each enabled only in its lawful state, the remedy button only once both windows closed; a Propose amendment bill link prefills targets_challenge_id; EMERGENCY_PROTECTED_FORMS unchanged.','A, no note (2026-09-13)'),
 'appeals-workflow-rules':('B','Criminal verdicts ARE appealable on error, but the appeal may only VACATE or ACQUIT, never order a new trial (double jeopardy stands: no re-prosecution, the lock never lifts). Otherwise as A: the appeal is a new case row linked by appeal_of_case_id, heard by the parent judiciary (same court en banc when there is none), outcome recorded as the appeal case opinion (affirm / reverse / remand for civil; affirm / vacate for criminal), filed by a party to the original case.','B, no note (2026-09-13)'),
 'case-lifecycle-controls-shape':('A','Hearing, deliberation, dismissal and motion/evidence rulings become F-JDG-011..014 forms (engine, catalog, training gate, surfaces, Learn copy; the exact form-count pin rises deliberately); the verdict stays a CaseService transition behind a judge-only route; the verdict actor must sit on the case panel; a panel verdict records for+against equal to the panel size and the outcome must be carried by the majority; a jury verdict records unanimity; the case row is locked for the write.','A, no note (2026-09-13)'),
 'individual-endorsement-rules':('none of A/B/C: his note is the ruling','NO TIME WINDOW AT ALL. Endorsements can be made, withdrawn, remade and re-withdrawn at ANY time, by individuals AND by organizations. Build consequence: the individual path (F-IND-025/026) carries no election-phase gate; the organization path gains a withdrawal and re-endorsement act with the same freedom; the only gates left are Art. I footprint (a resident of the election jurisdiction), a STANDING candidacy (registered, validated, in pool or finalist: a state gate, never a time gate; the same list the organization grant already used), and not endorsing yourself; private by default for individuals stands (schema default), org endorsements stay public; organizations withdraw and re-endorse through F-ORG-002 actions (no new form).','"Endorsements can be made and withdrawn and remade and rewithdrawn at ANY time by both indiviudals and organizations." (2026-09-13)'),
 'jurisdiction-nav-flush-tools':('A (selected by accident; NO ACTION)','Operator instruction: take no action on this question. Recorded so it is never re-asked; the tool pages keep their own sidebars as they are today.','"I dont know what this question is about. Given the date, Im not sure I care. It may be moot. Take no action on this question. I cant unselect my answer." (2026-09-13)'),
 'conference-navigation-iteration':('A','Implement the approved navigation, role exploration, and physical room presentation; repair broken workflow connections before adding more front-door explanation.','"I agree with your assessment and approve your plan"; entire jurisdiction tree reachable, Legislative Maps visible and walkable, live rooms arranged by civic roles, display names, instant beta residency and open role observation. Claude paused during this iteration (2026-09-12).'),
 'demo-mode-write-isolation':('C (real writes, compensating void)',"Demo actions enter the real tables AND the permanent audit chain, tagged with the demo session; at session end a compensating void entry is appended per action, so the chain stays valid and the permanent record carries every demo action. Chosen for multi-user interaction: during the session other users see the demo actions as real. Build: demo mode bypasses role gates (engine authorize + the controller POST role gates; owner-privacy, operator and auth gates stay), the audit entry carries demo_session_id, logout/expiry appends the void entries and reverts the session's data effects.",'"I like this because this will allow multiuser interaction." (2026-09-10)'),
 'demo-mode-identity':('A (box-level: instance_class scale_demo)',"Demo mode is the instance's class, set at setup: instance_class = scale_demo. Every action on that box is a demo action; the beta box (production class) writes for real. The desk should not have asked: the class is tagged at setup and was already known.",'"This is tagged to the instance on setup as is already known. This isnt new. Im not sure why its a question other than a failure by the subagent to know this" (2026-09-10)'),
 'demo-residency-window':('A (instant, 0 days on the beta box)','Confirmation is instant on the beta box: CGA_RESIDENCY_INSTANT=true makes ResidencyService::thresholdDays resolve 0 (the single owner every reader uses); the constitutional residency_confirmation_days setting stays 30 and rules again when the flag is off. Two further rulings in the same message: read access everywhere regardless of residency or role (a role gates actions, never the page), and demo mode where a user goes through the motions but the actions do not persist beyond the session.','"I think the 30 days on residency verification is gating far too much. Since this is Beta lets make it instant. Also you should be able to navigate through ALL jurisdictions regardless of your residency anyway. ... I want users to be able to go anywhere and see the current actions happening. It seems a lot of pages are gates on permissions they ought not to be. Even if someone doesn\'t have a given role that doesn\'t mean the user shouldn\'t be able to see the page. In non Demo mode they should still be able to see everything even if they cant affect the outcome for roles they don\'t have. In demo mode they should be able to go through the motions. The impact is the actions shouldn\'t persist beyond their session." (2026-09-10)'),
 'bench-scaling-law':('B','THE BENCH LAW. F = the jurisdiction\'s judiciary_min_judges_per_race setting (the floor; default 5; a new instance may move it). S = the legislature\'s Type A seats (the population chamber; Earth 1,999). Leaf court: bench = max(F, the next odd number at or above S / 10). Court with n constituents: the same value is the minimum; judges per constituent = ceil(minimum / n); bench = judges per constituent x n (equal per constituent, Art. IV §2; oddness is enforced on case panels, not on the bench). Examples: a 10-seat council with F = 5 gives 5; Earth with 1,999 Type A seats gives 201. The three population bands (5/7/9) and the hard-coded GREATEST(5, ...) in the provisioning SQL are replaced by this law on every path. Step 4 writes min_judges from it. B and C are the same shape because the seat law is a cube root of population; B reuses the stored seat count.','"a court with constituents grows past that as judges per constituent - This implies the formula only applies to leaves then. The floor isnt actually 5. The floor is the floor. In constitution settings in the setup the floor is defaulted to 5. new instances can move the floor. B and C seem similar since the legislature is also tiesd to the cube root." (2026-09-05)'),
 'europe-node-identity':('B','Mirror join. The Azure (US East) box stays authoritative and online 21 to 23 September; the Europe node mirrors it. More mirrors (Africa, Asia, South America) if affordable.','"Once I havegood US East Boxes I will mirror join them in Europe. I may even spin up another mirror in Africa and Asia and South America if I can afford it." (2026-09-05)'),
 'bench-and-quorum-law':('A','One rule everywhere. The court bench follows THE BENCH LAW (question bench-scaling-law, ruled B the same day): bench = max(judiciary_min_judges_per_race, next odd at or above Type A seats / 10), applied as the minimum multiple for courts with constituents. The stored quorum number is capped at the seat count. The seat floor comes from the jurisdiction\'s own settings row. Meaning recorded for the operator: 5/7/9 is the number of judicial SEATS on the jurisdiction\'s one court (the bench), not the judges on a case. A case panel is 3 judges (minor, moderate), 5 (serious) or the whole court (major constitutional, forced odd), clamped to the seated pool. A court with constituents grows past the floor: bench = judges per constituent x constituents. Rooms are infrastructure (ruling q4a-rooms); appeal tiers follow the jurisdiction tree (settled). The constitution binds only the floor of 5 per race; the 7 and 9 are a config dial. The dial is replaced by the bench law of question bench-scaling-law (ruled B).','"I think I get why this question seems not to make sense. Is this for Scaling purposes? I would imagine that there would be pools of judges being appointed or elected. When I see 5/7/9 I think this is in reference to the number of judges on a given case, not total members of the circuit. The analogy is Court Rooms vs Court Houses vs Court Circuits/Systems. IF this is for scaling puropses in Step 4 it shoudl scale with the population appropriately per the constitution. I dont think that is bound to 5 7 or 9 in the ceneral. If I have this wrong though let me know." (2026-09-05)'),
 'dup-legislatures':('A (by the operator\'s rule)','Two jurisdictions of 940,327 are affected: Githunguri and Kalmar. The cause is in code: the parent seeding reads the row then inserts, and legislatures(jurisdiction_id) has no unique index; the retired parallel sizing job of 2026-08-29 hit that window twice. The operator\'s rule: a fluke is deleted, a code problem is fixed. Both apply: soft-delete the later row of each pair and its map; add the live-unique index so the window is closed for the fresh cloud run.','"Wait you mean like two jurisdictions total have this problem? Out of the whole million? Like a bug introduced during testing or a problem with the code? If a fluke delete the flukes, if a problem with the code where many if not all jurisdictions have extraneous rows then a code fix is in order to unify that." (selected B; resolved A by the stated rule, 2026-09-05)'),
 'seat-mint-owner':('A','A Step 4 lane calls ElectionLifecycleService::scheduleGeneral per legislature after the boards land. It works in every game mode. The same lane serves the live mesh later.','"A" (2026-09-05)'),
 'zero-seat-chambers':('A','The seat-minting lane skips every zero-seat chamber and writes no election row. Box read 2026-09-05: 17,250 zero-seat chambers, of which 17,233 are leaves; a fresh run seeds leaf legislatures only where population > 0, so the leaf chambers are a box artifact and do not recur; the 17 zero-seat parents are empty subtrees, recur, and the skip rule covers them. The nine zero-seat DISTRICTS are engine behaviour (a zero-population bin under the sub-2 rule writes seats = 0) and recur on a fresh run: fix the rule so a zero-population bin gets no district of its own, then redraw the nine.','"The probably are empty but were created due to some lack of skipping. This is a box issue. Unless you are suggesting these would show back up in a Fresh Cloud Run (Which is slated for after this next build and refinment phase) then its a non issue." (2026-09-05)'),
 'sub-institutions-path':('B','A Step 4 lane files F-LEG-009 (committees to K(S)) and F-LEG-016 (departments to D(P)) as system acts without a chamber vote. Needs an engine change: a system-actor filing path that records the act and skips the vote. Step 4 sculpts the world on the geography of Steps 2 and 3; Step 5 simulates what people do in it. A setup that reaches Step 5 is the vision for the conference; a setup that stops at Step 4 is the attendee\'s chance to build that vision.','"committees and departments would exist in Step 4 insititution scaling on a scaled live box. Its like a game of Sim City where the entire map with all the infrastructure buildings are there and done and the roads and electricity are in and water pipes etc. all the zones are made and all the player decisions have effectivly been made. Step 5 is the point in sim sicty after the play button has been turned on and the world has filled with sims who have built their homes and businesses and industries already as they do and the load is now on the infrastructure grid (traffic, water usage, electric usage, etc). Step 4 Sculpts the world that sits on the geographic reality built in Steps 2 and 3. Step 5 simulates what people would do in that world. A game setup including Step 5 is a vision for the conference to see. A game setup going to step 4 is hteir oppurtunity to build that vision for themselves" (2026-09-05)'),
 'sim-scope-for-demo':('B','Full planet, all stages. Order: optimise Step 4 to the ETL paradigm; run it multilane on box E in iterations with halt, resume and rollback; gun for the full planet; lock the Step 4 result; then the same iterative build, test and rollback procedure for Step 5. Duration is measured on box E, never assumed.','"Both Step 2 and Step 3 have finely tuned Geodata and LEgislative Map engines that are designed to process at scaling speeds on the scaling hardware they find themself on. The ETL paradigm is what guided that development. Once we optimize Step 4 for this Paradigm we will see how fast it can really run on this PC. I for one dont think it wil ltake that long. Unlike Steps 2 and 3 there is no GIS data to process. With Step 3 now locked in on E box we will iteratively do Step 4 multilane runs. Then let it go depending on the actual time we observe. We will gun for Full Planet. Once we do that We will lock in Step 4 results and then perform the same iterative build test and roll back procedure for Step 5." (2026-09-05)'),
 'sim-revert-scope':('B','The full app-level population teardown, matching how Steps 2/3/4 roll back: each step deletes its own produced output and keeps the layers beneath. Step 5 deletes the sim POPULATION layer in FK-safe bounded chunks scoped by the sim provenance (sim-%@demo.invalid users, jurisdiction_cohorts, this run\'s rows) — the Step 2 map-fresh template — namely people, cohorts, candidacies, ballots/vote-casts, tabulations, seated legislature_members, and the sim civic records (organizations, bills), then un-certifies the elections seating advanced (back to their post-Step-4 scheduled state), and stops before Step 4\'s institutions and every map. The bookkeeping reset already built (sim_items + leases + run row) is its first step.','"How does Step 4 3 and 2 handle their roll back procedures if any?" then, after the 2/3/4 pattern was shown, selected B. (2026-09-06)'),
 'read-only-lock':('A','A new instance flag, toggled by the operator. Reads and walking stay open. A visitor can assume a role and act through the UI; the engine and every write endpoint discard the change and say so; registration closes; the sim and clock pumps pause.','"We will arrive at a steady state where they will be able toassume roles and do what they want. The changes just wont actualyl write to anything." (2026-09-05)'),
 'public-walk':('A','Every read surface is public, including the sim console as a read-only view. All writes refuse under the lock.','"A" (2026-09-05)'),
 'demo-mesh-host':('plan','The Azure cloud box builds the world fresh through the setup wizard: scale it up to 96 cores for the build, then scale it down and mesh it to a node hosted in Europe. demo.worldofstatecraft.org = the read-only simulation; beta.worldofstatecraft.org = the joinable, writable instance. More nodes if time allows. The fresh cloud run follows the Step 4 and Step 5 build-and-refine phase on box E. The identity of the Europe node (mirror or sovereign restore) is the question europe-node-identity.','"I have a cloud Box on Microsoft Azure ready to scale up for fast setup build (on my 12 core Alienware m17r3 I was able to do geodata injestion in hours, and while not benchmarked end to end I think I was able to do maps in 24 hours. I will have access to temporarily scale up a 96 core machine that I will scale down then mesh to a node that will be hosted in Europe. If I have ample time I might add more. The idea is demo.worldofstatecraft.org will be the simulation and beta.worldofstatecraft.org will be the player joinable and writable version)" (2026-09-05)'),
 'wizard-ladder':('A','Steps 0 to 6 as the diagram. The route range and the stepper extend; the counter convention stays. The scale choice and the simulate choice made earlier decide whether Steps 4 and 5 open; otherwise the wizard skips to Step 6. Unify one step at a time.','"We will be unifying the setup and doing it one step at a time. The autoscale choice made with the demo simulated choice will dictate if Steps 4 and 5 open up. If those options werent selected then it would skip to step 6." (2026-09-05)'),
 'done-flip-vs-pages':('A','The pages trigger. Step 4 shows bars per the ETL paradigm with halt and resume, and the operator improves Step 4 in iterations the way geodata ingestion and map drawing were improved. The done flip only refreshes the map quality statistics. Eager acceptance ends at maps.','"When I got to Step 4 I should see the UI I would review and bars following the ETL paradigm and choose to halt and resume the scale up. This is how we iteratively improves geodata ingestions and map drawing." (2026-09-05)'),
 'boot-prewarm':('C','Keep as is. The warm builds the raster tiles and the boundary caches the map viewer needs; it stays. The failed rows are redelivery ghosts of jobs that completed. Measure the restart cost on the fresh cloud box; follow up only if it competes with Step 4 or Step 5.','"I believe the prewarming was needed to view the district/panel maps and the rasters. If they arent needed after setup though I guess they dont need to be? I am not sure what the impact these failed jobs would have on a fresh box in the cloud. You may be paying attention to a non issue on thsi question." (2026-09-05)'),
 'default-queue-sweeps':('C','Keep as is. Recorded: the timer half matches the cron analogy (one clock job each minute reads the timer registry and fires due timers, 500 per minute at most); the three sweeps it also starts scan jurisdiction-scale tables each minute and grow with rows, not with timers. Measure them on the fresh cloud run; follow up only on evidence.','"I think you are paying attention to errors made in development that may be non issues. Alternatively there may be something with the clock sweeps that scale with the jursidcitions or insitutions that I need to pay attention to. I am imagining a cronjob like system. In that world there is one clock and all the time based things are registered and fire ass appropriate. Explain to me what the clock sweeps are. Analgoy: I can have thousands if not millions of crton jobs on a linux server but not each of them need to be called. Only the main clock which scans the registry of objects that have filed for cron jobs and fires using some compact logic mechanism. Is that what this is or am I off base" (2026-09-05)'),
 'host-memory-budget':('deferred','Deferred to the fresh cloud run. Follow up only if resource issues appear there.','"This might be moot as implemented already. unsure. If we run into resource issues on the fresh run in the cloud we can follow up." (2026-09-05)'),
 'founding-map-mint-phase':('B (already shipped)','RESOLVED as moot by the ledger single-home build (2026-08-31): the world build (phase 2) mints and stamps founding-map containers at the ingest tail beside the ledgers — option B is what shipped. The benchmark reset spares the containers (active flips to draft, never deleted); adoption keys on districts-present.','"irrelevant and solved in another chat" (2026-09-01)'),
 'wos-vcpu-quota':('C','STAY at 10 vCPUs. The WoS box caps at D8als_v7. Quota expansions are the operator\'s manual act only — the box and its sessions never request them.','"stay. I will call for expansions manually only." (2026-09-01)'),
 'subtree-activation-shape':('B+','MULTI-LANE PER-PLACE BOOT, structure only, never one opaque transaction. The standing paradigms dictate the shape: lanes sized to the machine, one committed chunk per place, resumable, visible per-lane progress. Elections fire where voters exist: real mode has no voters at boot (residents arriving trigger each founding election later, per the population-mode ruling); simulation mode elects through the sim itself. Setup exits when everything completable-now is done; remaining boots continue in background. NEW BUILD ITEMS queued: an app-wide background job monitor (floating indicator + drill-in list with live bars) and demand priority (opening a pending place promotes its boot to the queue front, the streaming-install model). Duration gets a measured baseline, not an assumption.','"Always using multi lane up to whatever the system resources can handle... always making it visible. I believe there is a paradigm in the documentation somewhere that should dictate this. ... stay in the setup process until everything is done that can be done ... some sort of background job monitor system needs to be in the app ... similar to how games like World of Warcraft did it ... anything else the player happened to stumble upon, it would bring those things to the front of the work queue to minimize the gap." (2026-08-29)'),
 'ingest-tail-apportionment':('A','PINNED for the next fresh box: the ingestion run itself finishes with apportionment (sizing) and the border precompute, before activation and before map acceptance — acceptance is not a precondition because unaccepted data makes the precomputation moot, not wrong. The Philippines race that made mid-ingest synthesis dangerous is dead (the positional resume class was removed), so the tree at the finalize barrier is trustworthy. One rider ships with it: a lawful repair that changes parentage (a collapse ruling, a manual re-parent) re-queues just the touched parents\' borders. Tested on the next fresh run.','"The Philippines issue was a bug in the code during this development. That wouldn\'t occur in reality... we can get the apportionments and the geometry precomputed as part of the ingestion phase. Pin that for the next fresh box... you wouldn\'t even need acceptance of the map data yet, because if the map data wasn\'t accepted, it wouldn\'t even matter." (2026-08-29)'),
 'box-vm-memory':('C+','LEAVE MEMORY ALONE — the paradigm is the fix. Raising the VM share is ruled impossible (other processes on the machine and in the application own their memory). Every kill class this box saw traces to unbounded single-process work; the remedy is ETL-paradigm shape (bounded units, fresh processes, host-derived lanes) — already proven by the per-scope map sweep (Earth in 9 minutes, zero deaths, through the scope that killed the monolith twice). Engine items A1-A4 sanctioned 2026-08-29 to finish the job.','"Leave it alone... increasing memory is impossible because there are other processes on this machine, and there are other processes in this application that have memory of their own. But what will work is following the ETL paradigm." (2026-08-29)'),
 'population-mode-autoboot':('A','CLOSE THE LOOP. Plain statement of what was asked: in population mode (a REAL game mode for live worlds, not only dev/demo — a place turns on when enough verified residents live there), the code recorded the crossing but never booted the place; an operator had to run a command per place by hand. Ruling A: the crossing now triggers the full boot automatically — seats sized, board seated, founding election opened, because the residents are real. Applies the same in dev and live. Build queued post-certification, lane 3.','"If this only matters in dev mode (which simulates data for demo purposes) closing the loop seems fine. I am not sure what this means. I think this question predates our new communication menthod of using technical english. I have ADHD." (2026-08-29 — the question also triggered the standing order: rubric entries use plain technical English per the communication guidelines.)'),
 'protomaps-online-fallback':('D+','BLANK UNTIL THE FILE ARRIVES stays the behaviour NOW (option D). Future build: a SETUP OPTION where each instance operator sets the standard — three choices at setup: (1) install protomaps locally, (2) use the web-hosted tile service, (3) install locally AND use the web-hosted service while the download runs. Queued as setup-wizard work post-certification.','"I believe there is a web serving method. What we can do is offer it as an option at setup. The user can choose to install protomaps, use the web hosted version, install and use webhosted. To this end the operator sets the standard." (2026-08-29)'),
 'cloud-geodata-source':('C (staged)','Build and test the REMOTE DOWNLOAD path with visible progress, pinned for equivalence with the D: archive. Verified upstream equivalents: geoBoundaries = public repo commit 78a697d23 (clone-at-commit = byte-identical; upstream HEAD has moved, so the downloader must pin the commit) · WorldPop = frozen release R2025A v1, 2023, 100m constrained (exact archive filenames exist at data.worldpop.org/GIS/Population/Global_2015_2030/R2025A/2023/<ISO>/v1/100m/constrained/) · protomaps pmtiles = display-only, not a math input. First run: fresh E: box, remote pull, expect the eleven base maps to land the same mathematical conclusions, then the full planet with desk watch.','"look at what is in the d drive and then go look at the online sources to see if you can find their absolute equivalence, and then we can test pulling that data. I would need to see progress bars and or something that indicates that it is pulling that data properly and downloading." (2026-08-28)'),
 'cloud-burst-architecture':('C (wiring, staged)','Container worker jobs next to an on/off VM. Ruled FEASIBLE by code read 2026-08-29: the mapping plane is fully queue-shaped (AutoscaleWorkerJob + MapScopeLaneJob on the autoscale queue) and all run state is database-backed (leases, heartbeats, halt/resume on the run row; population math reads worldpop_rasters in postgres; no local control files in the worker path). External worker containers pointed at the box\'s redis + postgres therefore consume mapping work with NO engine rebuild. Wiring needed: app image in a registry, Container Apps Job with a KEDA redis scaler on the autoscale queue, private network to the VM, and a plain queue:work command override (never the horizon entrypoint, which auto-dispatches a planet prewarm on every boot). Two bounds: the geodata INGEST phase is not queue-shaped (python lanes on the VM, local archive), and postgres anchors everything (connection budget caps all lanes at 56 total; at full burst postgres itself needs about 17 cores, so the VM resizes up during a build regardless). NO separate always-on VM is needed: the one game box turns on and off, workers burst around it.','"I would like to do the container worker apps jobs next to a VM that can turn on and off. I\'m not sure if that\'s possible based on the way the code is written. Effectively if I can have workers spin up as needed to scale, that\'s fine. If we need an always-on VM that\'s separate, then let me know. But it doesn\'t seem like I would need that." (2026-08-29)'),
 'cloud-idle-posture':('A','Deallocate between sessions before launch. About $45/mo idle (disk + IP), start on demand in about 2 minutes. The VM is on only while people play or a build runs; workers alone cannot serve the game (no UDP ingress on serverless, and postgres + redis live on the box).','"Minimum workers. The whole point of using workers I thought was so that you only had up the ones you needed when you needed them, and then it could spin up more as necessary. So always on is no. We are definitely going to deallocate." (2026-08-29)'),
 'cloud-region':('A (staged multibox)','First box in US East. Then a second box in Europe, transferred or cloned, for access from Poland. The Europe box\'s identity gets decided when it is planned: a JOIN makes a read-only mirror with no accounts; a clone-restore must mint its own identity (the import path filters instance_settings).','"We are doing Azure. I am going to build the first one in US East, and then I am going to transfer or clone one to Europe so that I can access it from Poland." (2026-08-29)'),
 'era1-retest-maps':('A','The three era champions drawn on the block engine (6f4ee42) as auto standards for his walk: Maldives 199e55ac · Malaysia 2b559c81 · Bangladesh a876aca1. His tweaks join the tweak-potentials pile (ALGORITHM.md §10c). If the walk clears them, the next phase begins.','"If you are identifying these candidates to walk, make their maps form the current standard and I will walk them. We can add any tweaks I make to the pile of tweak potentials. If there are no new candidates to walk we can prepare for the next phase." (2026-08-28)'),
 'gate-expansion-maps':('B+','ALL SIX join the gate (the B five + Chile): India, Philippines, Canada, Egypt, Chile, San Marino — the test block of eight. Auto baselines regenerated per engine change; his manual clones become the standards as he blesses them (the USA method).','"go ahead and provide me the links to all the examples and I will auto map via stepper then manual map and tweak to add to the non regression panel … This way, I should be able to have nonregression gates across the entire test block of eight maps." (2026-08-26/27)'),
 'good-maps-adopt':('A','ADOPTED at iteration 19 (engine 42495b5) after the Class-1 round: rewalk verdict accepts the set; next phase = the planet-wide Type A sweep on this engine. STANDING REGRESSION GATE: any algorithm tweak requires a fresh Earth+USA iteration scored against the standards before shipping (database/good_maps/ALGORITHM.md §11).','"With very little exception I would say this is the best map set you have ever created. … Next phase will be to fully map all jurisdictions making sure they stay legal and optimize their statistics like these maps are optimized. Any tweaks made to the algorithm would necessitate an iteration of Earth and USA map to make sure the logic isn\'t being compromised." (2026-08-25)'),
 'sim-org-bill-rates':('B','Census-flavored: per-capita org rates (parties, nonprofits, businesses) + bills per session — realistic even though it mints millions of org rows planet-wide (implemented with a representative sample dial + true-density aggregates so an 8 GB box still runs).','"For the Purposes of Demo simulation and development purposes We want census flavored." (2026-08-08)'),
 'sim-leaf-courts':('A','Next sim round, BEFORE the demo — full courts everywhere, committee-slate nomination for leaves.','"What you and I are inching toward is full scale deployment simulation. So I will cotest the manual Map and the Simulation of that specific jurisdiction in the same go. Therefore it is imperative that we have this ready." (2026-08-08 — see the GOAL PATH: narrow sim → tour/refine → local multibox mesh (2 Linux PCs + Pi 5 + Pi 3B+ + this Windows box) → cloud instances Azure→GCP→AWS + instant-deploy templates → smaller providers. Close dev with THREE meshes: Demo Simulation, Real-World High-Acceleration prebuilt, Real-World Real-Time. Then lanes 9-12 marketing. Deadline: Labor Day weekend; hard stop Sept 19 Poland flight.)'),
 'edu-arming':('A','Pre-train demo members (seeders file F-EDU-001) — the walk shows a trained fleet.',''),
 'mass-pass':('A','After the race fix, pull lane 1\'s commits to the game box and run the ~9,708 mass pass now (ETL-chunked).',''),
 'lane3-compact':('A','Compact lane 3 now — it resumes into seating a committee + the exit walk.','Operator will NOT manually walk anything until we are all GREEN and ready.'),
 'ranked-live':('A','Build the secrecy-safe live aggregate, DAILY-BATCHED (results are invisible-until-count today, so daily provisional standings — no in-request decrypt).',''),
 'secondary-trade':('A','Pull into Wave 4 — lane 13 builds share resale on the exchange (needs schema).',''),
 'handshake-4xx':('A','Fix in Wave 4 — catch the cross-class refusal, return 409/422 gracefully.',''),
 'b2-pairing':('A','Keep compact-first (Type B clumping; matches intent).',''),
 'oversight-live':('B','GOVERNMENT IS PUBLIC BY DEFAULT — the live console of in-progress proceedings too. Organizations decide their own visibility. ⚑ SETTLED LAW, never re-ask.','"I dont know how many times I need to reanswer this question."'),
 'orphans':('A','Delete the orphan surfaces (CandidateProfile.vue etc.). ⚑ SETTLED, never re-ask.','"I already answered this many times as well."'),
 'q4a-rooms':('A',"courtTiers = a jurisdiction's tree-DEPTH. Live Rooms / public squares / chats are INFRASTRUCTURE — NOT constitutionally the same as a court-as-jurisdiction. A COURTHOUSE has many COURT ROOMS. Reframe the formula (doc amendment); no schema.",'"LIVE ROOMS AND PUBLIC SQUARES ARE INFRASTRUCTURE AND NOT CONSTITUTIONALLY THE SAME AS A COURT AS A JURISDICTION. Court House DOES NOT EQUAL Court Room." ⚑ SETTLED, never re-ask.'),
 'advocate-gate':('A','Advocate stays an INSTANT competence REGISTER — no merits gate, no approval lifecycle, no qualification catalog. Same as every role: WANT THE ROLE → DO THE (K-2) TRAINING.','"We settled this with the other roles. You want the role, you do the training. To do the role you do the training." ⚑ SETTLED, never re-ask.'),
 'scale-committees':('B','EAGER — provision committees up front in STEPS alongside executives/judiciaries; an accepted planet already has them. Doctrine: ALL sub-institutions build eagerly, scaled to the recorded population of the jurisdiction.','"All things should be eagerly built up front up to the recorded population of that jurisdiction in the data. This should properly scale the map."'),
 'scale-record-disposition':('A','MIRROR ACTS — copy-per-constituent WITH history for every non-Act record family; the two hard cases (open cases, sitting judges) ruled separately below. Symmetric with union by construction.','"Mirror"'),
 'scale-case-venue':('B',"Case-by-case re-venue: the case's JUSTICES direct each open case to the appropriate constituent jurisdiction, within the bounds of their court-system rules.",'"Open cases would be directed by the justices of the cases to approparite jurisdictions wihtin the bounds of their court system rules."'),
 'scale-judge-tenure':('B','CLOSE AT DISSOLUTION — the court ceases with its jurisdiction; appointments end and the successor court re-nominates.','"It all closes together."'),
 # ── Round 1 of the Setup + Viewer walkthrough (operator, 2026-08-04) ──
 'map-adopt-scope':('C','Planetary now, scoped-READY later — ship planetary but carry the scope column so scoped is additive.','Each PARENT jurisdiction controls its own internal subdivisions for jurisdictional purposes. The global layer locks in by the EARTH election board; national election boards subdivide within, and so on down the tree. Once that cascade is complete it becomes a DRAFT CANDIDATE.'),
 'map-select-authority':('D','Selection is automatic from the standing census RULE (census year -> derived map -> next term); a legislative act only OVERRIDES it.','Operator/admin by default, then whoever is the current keeper of the rule.'),
 'merge-bulk':('B','Collapse same-space chains at INGEST so the queue never fills with them.','⚑ Three constraints on the collapse, operator: (1) POPULATION CHECK — geometry alone is not enough. Jurisdictions that MOSTLY overlap but do not are the tricky case, and any populated lack of overlap must not get locked out; same space + same population confirms same PLACE. (2) NEVER ORPHAN anything below the stack — no jurisdiction or cluster beneath a collapsed chain may lose its lineage. (3) Real players snap to the nearest appropriate ADM level at GROUND level and chain up from there. "Overall im fine with doing it at ingestion."'),
 'adm-empty-walk':('A','Fix both now — skip levels the metadata says are absent AND memoise the directory walk.','For testing; another fresh run is coming to make sure we stay on track.'),
 'list-stats-columns':('B','Own-row + direct children only — no recursion, no new recursive-CTE endpoint.',''),
 'setup-shell-menus':('A','Lock everything except Setup, Jurisdictions and Learn until setup completes.','Plus the OPERATOR CONTROLS — everything is locked except operator controls, setup controls, and the three in A.'),
 'guest-banner':('B','Collapse into the header bar as a small badge beside Log in / Register.',''),
 'about-surface':('A','Move the content into the Learn tab; remove the block from the viewer.','⚑ CHECK LEARN FIRST — do not duplicate or override what is already there. And a STANDING instruction for the whole walkthrough: much of the UI is explanatory text that previous design/developer AIs generated. Expect to be cleaning a lot of that out as we go.'),
 'disposable-pg-on-box-e':('A','Review lanes create and drop nonce-guarded disposable cga_* / codex_* databases on the existing postgres of box E; the world database is never a fixture; every database is dropped at the end of its run.','"1: A" (2026-09-14)'),
 'second-compose-project-box-e':('B','Deferred: the two-node mesh join and TLS reviews run on a Linux host or the restored cloud box; no second Compose project on box E; both register rows stay BLOCKED with their harnesses prepared.','"2 and 3 Sounds like this is just testing that isn\'t blocking building. if so defer. if not let me know." (2026-09-14; desk assessment: both rows are verification only, no build item depends on them, so both are deferred)'),
 'benchmark-go-scope':('B','Deferred: no simulation or setup run starts on box E for the review campaign; the Setup worlds and Scale rows keep their written fixtures and harness and wait for a dedicated benchmark window.','"2 and 3 Sounds like this is just testing that isn\'t blocking building. if so defer. if not let me know." (2026-09-14; desk assessment: both rows are verification only, no build item depends on them, so both are deferred)'),
 'video-a11y-tooling':('A','Playwright and axe-core added as dev dependencies with a Chromium download inside the Vite container; unblocks the video decoding review, accessibility pass 2 and the D1 rehearsal runner.','"4: A" (2026-09-14)'),
 'translation-first-pass-provider':('A','Local model on box E for the machine first pass, started on GO; plus a master export of the missing strings as JSON with a per-surface context header that any AI can translate, and a validating importer for the returned file (build item LG-0).','"I need a master string file (probably JSON?) I can drop in any competant AI that will then translate an equivilent. Include some header block for the surface the string belongs to for proper context to help the translation mechanism. By exporting this I should be able to scale up fast. If you have a reccomended local AI give me more detail so I can look it up. I am totally fine with using a local AI if it is accurate enough. I would prefer it even." (2026-09-14)'),
 'windows-public-deploy-policy':('B','deploy.ps1 gains the public-Matrix configuration-generation gate mirroring deploy.sh, plus the Windows configuration-failure harness case (DP-1 confirmed). Any volunteer hardware may host a public deployment.','"Any volunteer hardware can be a public deployment by anyone. THIS deployment you are developing on should be capable of it but I dont intend to make it public as the demo and beta will be on linux boxes in the cloud." (2026-09-14)'),
 'learn-disclosure-auth-pages':('B','Mount the Learn-only command bar on Login, OperatorLogin, Register and the bare Operator console (build item LE-5).','"Education is important. If there are front door pages that need education surfaces add them." (2026-09-14)'),
}
for _q in QUESTIONS:
    a = _ANS.get(_q['id'])
    if a:
        k, txt, note = a
        _q['status'] = 'resolved'
        _q['detail'] = 'RULED = %s. %s%s · %s' % (k, txt, (' [operator: '+note+']') if note else '', _q['detail'])
        _q.pop('options', None)


# ---------------------------------------------------------------------------
# work.json load and validation
# ---------------------------------------------------------------------------
ACTIONABLE = ('open', 'blocked', 'awaiting_go', 'deferred')


def check_work(work):
    """Return a list of fault strings. Empty list means valid."""
    faults = []
    items = work.get('items', [])
    ids = [it.get('id') for it in items]
    seen = set()
    dup = set()
    for i in ids:
        if i in seen:
            dup.add(i)
        seen.add(i)
    if dup:
        faults.append('duplicate ids: ' + ', '.join(sorted(str(x) for x in dup)))
    phase_ids = {p.get('id') for p in work.get('phases', [])}
    order_seen = {}
    for it in items:
        if it.get('phase') not in phase_ids:
            faults.append('%s: unknown phase %r' % (it.get('id'), it.get('phase')))
        st = it.get('status')
        if st in ACTIONABLE:
            o = it.get('order')
            if not isinstance(o, int):
                faults.append('%s: open item has no integer order' % it.get('id'))
            else:
                if o in order_seen:
                    faults.append('duplicate order %s among open items (%s, %s)' % (o, order_seen[o], it.get('id')))
                order_seen[o] = it.get('id')
            if not (it.get('done_when') or '').strip():
                faults.append('%s: open item has no done_when' % it.get('id'))
    idset = set(ids)
    for it in items:
        for d in it.get('depends_on', []):
            if d not in idset:
                faults.append('%s: depends_on %s does not resolve' % (it.get('id'), d))
    return faults


def load_work():
    return json.load(open(os.path.join(_HERE, 'work.json'), encoding='utf-8'))


TEMPLATE = r"""<meta charset="utf-8"><title>App Progress Rubric — CGA</title>
<style>
:root{--bg:#F6F6F3;--surface:#FFFFFF;--ink:#1B1E28;--muted:#5C6070;--faint:#8B8F9E;--line:rgba(27,30,40,.12);--line-strong:rgba(27,30,40,.22);--accent:#3B4A8C;--accent-soft:rgba(59,74,140,.08);--good:#1D8A47;--warn:#C98500;--bad:#C4553B;--block:#7A46B8;--good-s:rgba(29,138,71,.12);--warn-s:rgba(201,133,0,.14);--bad-s:rgba(196,85,59,.13);--block-s:rgba(122,70,184,.14);--mono:"Cascadia Code",Consolas,ui-monospace,monospace;--sans:"Segoe UI Variable Text","Segoe UI",system-ui,sans-serif;}
@media (prefers-color-scheme:dark){:root{--bg:#14161D;--surface:#1C1F29;--ink:#ECEDF2;--muted:#9BA0B0;--faint:#6E7385;--line:rgba(236,237,242,.12);--line-strong:rgba(236,237,242,.25);--accent:#8C9AD9;--accent-soft:rgba(140,154,217,.12);--good:#3FBF74;--warn:#E8A23D;--bad:#E07856;--block:#B189E0;--good-s:rgba(63,191,116,.14);--warn-s:rgba(232,162,61,.15);--bad-s:rgba(224,120,86,.15);--block-s:rgba(177,137,224,.16);}}
:root[data-theme="dark"]{--bg:#14161D;--surface:#1C1F29;--ink:#ECEDF2;--muted:#9BA0B0;--faint:#6E7385;--line:rgba(236,237,242,.12);--line-strong:rgba(236,237,242,.25);--accent:#8C9AD9;--accent-soft:rgba(140,154,217,.12);--good:#3FBF74;--warn:#E8A23D;--bad:#E07856;--block:#B189E0;--good-s:rgba(63,191,116,.14);--warn-s:rgba(232,162,61,.15);--bad-s:rgba(224,120,86,.15);--block-s:rgba(177,137,224,.16);}
:root[data-theme="light"]{--bg:#F6F6F3;--surface:#FFFFFF;--ink:#1B1E28;--muted:#5C6070;--faint:#8B8F9E;--line:rgba(27,30,40,.12);--line-strong:rgba(27,30,40,.22);--accent:#3B4A8C;--accent-soft:rgba(59,74,140,.08);--good:#1D8A47;--warn:#C98500;--bad:#C4553B;--block:#7A46B8;--good-s:rgba(29,138,71,.12);--warn-s:rgba(201,133,0,.14);--bad-s:rgba(196,85,59,.13);--block-s:rgba(122,70,184,.14);}
*{box-sizing:border-box}body{background:var(--bg);color:var(--ink);font-family:var(--sans);margin:0;line-height:1.5}
.wrap{max-width:70rem;margin:0 auto;padding:2rem 1.1rem 4rem}
.eyebrow{font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;color:var(--faint);margin:0 0 .4rem}
h1{font-size:1.55rem;font-weight:600;margin:0 0 .3rem}
.stamp{font-size:.82rem;color:var(--muted);margin:0 0 1.3rem}
.stamp code{font-family:var(--mono);font-size:.78rem;background:var(--accent-soft);padding:.08em .4em;border-radius:4px}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:.7rem;margin:0 0 1rem}
.tile{background:var(--surface);border:1px solid var(--line);border-radius:10px;padding:.8rem .95rem}
.tile .lbl{font-size:.72rem;letter-spacing:.09em;text-transform:uppercase;color:var(--muted);margin:0 0 .25rem}
.tile .num{font-size:1.6rem;font-weight:650;font-variant-numeric:tabular-nums;line-height:1.1}
.tile .sub{font-size:.77rem;color:var(--faint)}
.tile .meter{display:flex;block-size:.5rem;border-radius:4px;overflow:hidden;margin-top:.45rem;background:var(--line)}
.tile .meter span{block-size:100%}
.dot{inline-size:.55rem;block-size:.55rem;border-radius:50%;display:inline-block;flex:none;vertical-align:middle}
.d-good{background:var(--good)}.d-warn{background:var(--warn)}.d-bad{background:var(--bad)}.d-block{background:var(--block)}.d-low{background:var(--faint)}
.s-good{background:var(--good)}.s-warn{background:var(--warn)}.s-bad{background:var(--bad)}.s-block{background:var(--block)}
.note{background:var(--surface);border:1px solid var(--line);border-inline-start:4px solid var(--bad);border-radius:9px;padding:.75rem .9rem;font-size:.85rem;margin:.2rem 0 1.4rem}.note b{color:var(--ink)}
.views{display:flex;gap:.35rem;border-bottom:2px solid var(--line);margin:0 0 1rem;flex-wrap:wrap}
.view-btn{padding:.5rem .8rem;font:inherit;font-size:.9rem;font-weight:600;color:var(--muted);background:none;border:0;border-bottom:2px solid transparent;margin-bottom:-2px;cursor:pointer}
.view-btn[aria-selected=true]{color:var(--ink);border-bottom-color:var(--accent)}
.view-btn:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.controls{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;margin:0 0 1rem}
.controls input[type=search]{flex:1 1 13rem;background:var(--surface);border:1px solid var(--line-strong);border-radius:8px;color:var(--ink);font:inherit;font-size:.88rem;padding:.42rem .7rem}
.controls input[type=search]:focus{outline:2px solid var(--accent);outline-offset:1px}
.chip{background:var(--surface);border:1px solid var(--line-strong);border-radius:999px;color:var(--muted);font:inherit;font-size:.8rem;padding:.28rem .75rem;cursor:pointer}
.chip[aria-pressed=true]{background:var(--accent);border-color:var(--accent);color:var(--bg)}
.chip:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
.expanders{margin-inline-start:auto;display:flex;gap:.5rem}
.area{background:var(--surface);border:1px solid var(--line);border-radius:12px;margin:0 0 .6rem;overflow:hidden}
.area-head{display:grid;grid-template-columns:16rem 1fr 9rem 1.2rem;gap:.9rem;align-items:center;inline-size:100%;background:none;border:0;color:inherit;font:inherit;text-align:start;padding:.7rem .95rem;cursor:pointer}
.area-head:hover{background:var(--accent-soft)}.area-head:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}
.area-name{font-weight:600;font-size:.93rem}
.bar{display:flex;block-size:.8rem;border-radius:5px;overflow:hidden;background:var(--line)}.bar span{block-size:100%}
.counts{font-family:var(--mono);font-size:.75rem;color:var(--muted);text-align:end;white-space:nowrap}
.chev{color:var(--faint);transition:transform .15s}.area-head[aria-expanded=true] .chev{transform:rotate(90deg)}
.rows{border-top:1px solid var(--line)}
.scr{border-top:1px solid var(--line)}.scr:first-child{border-top:0}
.scr-head{display:grid;grid-template-columns:auto 1fr auto auto auto;gap:.6rem;align-items:baseline;inline-size:100%;background:none;border:0;color:inherit;font:inherit;text-align:start;padding:.55rem .95rem .55rem 1.2rem;cursor:pointer}
.scr-head:hover{background:var(--accent-soft)}.scr-head:focus-visible{outline:2px solid var(--accent);outline-offset:-2px}
.scr-title{font-size:.88rem}.scr-file{font-family:var(--mono);font-size:.75rem;color:var(--faint);display:block;margin-top:.1rem}
.pill{font-size:.68rem;font-weight:600;letter-spacing:.03em;border-radius:999px;padding:.14em .6em;white-space:nowrap}
.p-built,.p-working,.p-done,.p-resolved{background:var(--good-s);color:var(--good)}
.p-partial,.p-next,.p-medium,.p-active{background:var(--warn-s);color:var(--warn)}
.p-absent,.p-high,.p-open{background:var(--bad-s);color:var(--bad)}
.p-blocked,.p-held{background:var(--block-s);color:var(--block)}
.p-low,.p-deferred{background:var(--accent-soft);color:var(--muted)}
.eff{font-family:var(--mono);font-size:.72rem;color:var(--faint);white-space:nowrap}
.lwbadge{font-family:var(--mono);font-size:.66rem;font-weight:700;background:var(--accent-soft);color:var(--accent);padding:.14em .45em;border-radius:4px;white-space:nowrap;letter-spacing:.02em}
.qbar{display:flex;gap:.8rem;align-items:center;margin:0 0 1rem;flex-wrap:wrap}
.qhint{font-size:.78rem;color:var(--faint);flex:1;min-width:14rem}
#qexport,#wexport{font-weight:700;color:var(--accent);border-color:var(--accent)}
.wovr{display:flex;flex-wrap:wrap;gap:.6rem;align-items:flex-start;margin-top:.8rem;padding-top:.7rem;border-top:1px dashed var(--line)}.wovr .wnotes{flex:1 1 18rem;min-height:3.2rem;font:inherit;font-size:.82rem;padding:.4rem .55rem;border:1px solid var(--line);border-radius:6px;background:var(--surface);color:var(--ink)}.wovr .wsel{font:inherit;font-size:.82rem}.wovr .qhint{flex-basis:100%}tr.wrow.chg td.c-id{font-weight:700;color:var(--accent)}
.qexport-wrap{position:relative;margin:0 0 1rem}.qexport-wrap.hidden{display:none}.qcopy{position:absolute;top:.45rem;right:.45rem;z-index:2;cursor:pointer;font:600 .72rem/1 var(--mono);padding:.35rem .6rem;border-radius:6px;background:var(--surface2,#1b1f27);color:var(--accent);border:1px solid var(--accent);opacity:.85}.qcopy:hover{opacity:1}.qcopy.ok{color:var(--good);border-color:var(--good)}.qexport{background:var(--surface);border:1px solid var(--accent);border-radius:8px;padding:.7rem .9rem;font-family:var(--mono);font-size:.76rem;white-space:pre-wrap;color:var(--ink);margin:0;padding-top:1.9rem;user-select:all}
.qcard{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:.9rem 1rem;margin:0 0 .7rem}
.qcard.resolved{opacity:.65}
.qhead{display:flex;align-items:baseline;gap:.6rem;flex-wrap:wrap}
.qtext{font-weight:600;font-size:.95rem;flex:1;min-width:12rem}
.qdetail{font-size:.82rem;color:var(--muted);margin:.4rem 0 .7rem}
.qopts{display:flex;flex-direction:column;gap:.4rem;margin:0 0 .7rem}
.qopt{display:flex;gap:.55rem;align-items:flex-start;padding:.5rem .7rem;border:1px solid var(--line);border-radius:8px;cursor:pointer;font-size:.86rem}
.qopt:hover{background:var(--accent-soft)}
.qopt.on{border-color:var(--accent);background:var(--accent-soft);box-shadow:inset 0 0 0 1px var(--accent)}
.qopt input{margin-top:.15rem;accent-color:var(--accent)}
.qk{font-family:var(--mono);font-weight:700;color:var(--accent);flex:none}
.qnotes{width:100%;min-height:2.4rem;background:var(--bg);border:1px solid var(--line-strong);border-radius:8px;color:var(--ink);font:inherit;font-size:.85rem;padding:.45rem .6rem;resize:vertical}
.qnotes:focus{outline:2px solid var(--accent);outline-offset:1px}
.wavesline{font-size:.82rem;color:var(--muted);margin:0 0 1rem;background:var(--surface);border:1px solid var(--line);border-radius:8px;padding:.6rem .9rem}
.lanecard{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:.9rem 1rem;margin:0 0 .7rem}
.lanehd{display:flex;align-items:center;gap:.6rem;margin:0 0 .5rem;flex-wrap:wrap}
.lanenm{font-weight:650;font-size:.98rem}
.laneorder{font-size:.87rem;line-height:1.55}
.lanehist{margin-top:.6rem;font-size:.8rem}
.lanehist summary{color:var(--faint);cursor:pointer;user-select:none}
.donerow{color:var(--muted);font-size:.8rem;margin:.4rem 0;padding-inline-start:.6rem;border-inline-start:2px solid var(--line)}
.wv{font-family:var(--mono);font-size:.72rem;font-weight:700;color:var(--accent);white-space:nowrap}
.detail{padding:.35rem 1.2rem 1rem 2.15rem;font-size:.85rem;border-top:1px dashed var(--line)}
.detail dl{margin:0}.detail dt{font-size:.7rem;letter-spacing:.09em;text-transform:uppercase;color:var(--faint);margin:.7rem 0 .2rem}
.detail dt.blk{color:var(--bad)}.detail dd{margin:0}.detail ul{margin:.1rem 0 0;padding-inline-start:1.1rem}.detail li{margin:.15rem 0}
.detail p{margin:.1rem 0 0}.detail .meta{font-family:var(--mono);font-size:.76rem;color:var(--muted)}
.ok{color:var(--good)}.hidden{display:none}mark{background:var(--warn-s);color:inherit;border-radius:3px}
.foot{font-size:.76rem;color:var(--faint);margin-top:2rem;border-top:1px solid var(--line);padding-top:.8rem}
@media (max-width:46rem){.area-head{grid-template-columns:1fr 6rem 1rem;grid-template-rows:auto auto}.area-head .bar{grid-column:1/-1;grid-row:2}.scr-head{grid-template-columns:auto 1fr auto}}
.wtable{width:100%;border-collapse:collapse;font-size:.85rem}
.wtable th{text-align:start;background:var(--surface);border-bottom:2px solid var(--line-strong);padding:.45rem .5rem;cursor:pointer;white-space:nowrap;font-size:.72rem;letter-spacing:.04em;text-transform:uppercase;color:var(--muted);position:sticky;top:0;z-index:1}
.wtable th:hover{color:var(--ink)}.wtable th .ar{color:var(--accent);font-weight:700}
.wtable td{padding:.45rem .5rem;border-bottom:1px solid var(--line);vertical-align:top}
.wtable tr.wrow{cursor:pointer}.wtable tr.wrow:hover td{background:var(--accent-soft)}
.wtable .c-order{font-family:var(--mono);color:var(--faint);text-align:end;width:2.6rem}
.wtable .c-id{font-family:var(--mono);font-size:.74rem;color:var(--accent);white-space:nowrap}
.wtable .c-title{min-width:13rem}
.srcbadge{font-family:var(--mono);font-size:.64rem;background:var(--accent-soft);color:var(--accent);padding:.1em .4em;border-radius:4px;margin-inline-end:.25rem;white-space:nowrap}
.kindb{font-family:var(--mono);font-size:.68rem;color:var(--muted)}
.drow>td{background:var(--bg);padding:.2rem 1rem 1rem 1rem}
.p-awaiting_go{background:var(--warn-s);color:var(--warn)}
.p-moot{background:var(--accent-soft);color:var(--muted)}
.flab{font-size:.8rem;color:var(--muted);display:inline-flex;gap:.3rem;align-items:center}
.flab select{background:var(--surface);border:1px solid var(--line-strong);border-radius:8px;color:var(--ink);font:inherit;font-size:.82rem;padding:.3rem .5rem}
.wgrp-h{font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;color:var(--faint);padding:.4rem .95rem;background:var(--accent-soft);border-top:1px solid var(--line)}
.tblwrap{overflow-x:auto;background:var(--surface);border:1px solid var(--line);border-radius:12px}
</style>
<div class="wrap">
<p class="eyebrow">Fair Constitution App · Consolidated Work</p>
<h1>One list of work. One list of questions.</h1>
<p class="stamp">%%STAMP%% · click a column to sort · click a row for the detail · done and moot are hidden until you toggle them</p>
<div class="tiles" id="tiles"></div>
<div class="note" style="border-inline-start-color:var(--good)"><b>One file to pay attention to.</b> The Work tab is the whole punch list in one sortable table: past waves, fleets and the demo lists consolidated. Every closed item is kept as done, so nothing is lost. Open items carry the order of operations. The Open Questions tab is the operator's decision channel, unchanged. The Archive tab holds every done and moot item, grouped by its original list and wave. Source of the rows is <code>work.json</code>, built by <code>migrate_to_work.py</code> from the screens, capabilities, debt, fleet, punch and review lists.</div>
<div class="views" role="tablist">
  <button class="view-btn" role="tab" data-v="work" aria-selected="true">Work</button>
  <button class="view-btn" role="tab" data-v="questions" aria-selected="false">Open Questions</button>
  <button class="view-btn" role="tab" data-v="archive" aria-selected="false">Archive</button>
</div>
<div class="controls">
  <input type="search" id="q" placeholder="Search…" aria-label="Search">
  <span id="filters"></span>
</div>
<div id="body"></div>
<p class="foot">Generated from <code>work.json</code> (built by <code>migrate_to_work.py</code>) and the QUESTIONS list in this generator. The Work tab carries the sortable order of operations. Validate with <code>--check</code>.</p>
</div>
<script>
const D=%%DATA%%;
const esc=s=>String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const ACT=['open','blocked','awaiting_go','deferred'];
const SCO={open:'bad',blocked:'block',awaiting_go:'warn',deferred:'low',done:'good',moot:'low'};
const SLB={open:'open',blocked:'blocked',awaiting_go:'awaiting GO',deferred:'deferred',done:'done',moot:'moot'};
const items=D.items;
const phaseName={};D.phases.forEach(p=>phaseName[p.id]=p.name);
const isAct=it=>ACT.indexOf(it.status)>=0;
let view='work',q='',showDone=false,sortKey='order',sortDir=1,qShowResolved=false;
let flt={phase:'all',kind:'all',status:'all',blocker:'all'};
const tilesEl=document.getElementById('tiles');
function hi(t){if(!q)return esc(t);const s=String(t==null?'':t);const i=s.toLowerCase().indexOf(q);if(i<0)return esc(s);return esc(s.slice(0,i))+'<mark>'+esc(s.slice(i,i+q.length))+'</mark>'+esc(s.slice(i+q.length));}
// open-question answers persist in the page, unchanged from prior versions.
let ANS={};try{for(let i=0;i<localStorage.length;i++){const k=localStorage.key(i);if(k&&k.indexOf('cga4qs_')===0){const id=k.slice(7);ANS[id]=ANS[id]||{};ANS[id].sel=localStorage.getItem(k);}if(k&&k.indexOf('cga4qn_')===0){const id=k.slice(7);ANS[id]=ANS[id]||{};ANS[id].notes=localStorage.getItem(k);}}}catch(e){}
function saveAns(id,f,v){ANS[id]=ANS[id]||{};ANS[id][f]=v;try{localStorage.setItem('cga4q'+(f==='sel'?'s':'n')+'_'+id,v);}catch(e){}}
// operator status changes on work items persist in the page the same way (cga4ws_<id> status, cga4wn_<id> notes) until exported and folded in by the desk.
const STATUSES=['open','blocked','awaiting_go','deferred','done','moot'];
let OVR={};try{for(let i=0;i<localStorage.length;i++){const k=localStorage.key(i);if(k&&k.indexOf('cga4ws_')===0){const id=k.slice(7);OVR[id]=OVR[id]||{};OVR[id].sel=localStorage.getItem(k);}if(k&&k.indexOf('cga4wn_')===0){const id=k.slice(7);OVR[id]=OVR[id]||{};OVR[id].notes=localStorage.getItem(k);}}}catch(e){}
function saveOvr(id,f,v){OVR[id]=OVR[id]||{};if(v){OVR[id][f]=v;}else{delete OVR[id][f];}try{if(v)localStorage.setItem('cga4w'+(f==='sel'?'s':'n')+'_'+id,v);else localStorage.removeItem('cga4w'+(f==='sel'?'s':'n')+'_'+id);}catch(e){}if(!OVR[id].sel&&!OVR[id].notes)delete OVR[id];}
const changed=id=>!!(OVR[id]&&(OVR[id].sel||OVR[id].notes));
let showChanged=false;
// A change the desk has already folded into the list clears itself: the item now carries that status
// and the note is in its history, so the next export holds only what is still pending.
(function reconcileFolded(){const byId={};D.items.forEach(it=>byId[it.id]=it);
  Object.keys(OVR).forEach(id=>{const o=OVR[id],it=byId[id];if(!it)return;
    const statusIn=!o.sel||it.status===o.sel;
    const noteIn=!o.notes||(it.history||[]).some(h=>String(h.note||'').indexOf(o.notes.trim())>=0);
    if(statusIn&&noteIn){saveOvr(id,'sel','');saveOvr(id,'notes','');}});})();
// tiles: open items per phase, awaiting-GO, blocked, open questions
function tile(lbl,num,sub){return `<div class="tile"><p class="lbl">${esc(lbl)}</p><div class="num">${num}</div><div class="sub">${esc(sub)}</div></div>`;}
function renderTiles(){
  const acts=items.filter(isAct);
  let h='';
  D.phases.filter(p=>p.id!=='done').forEach(p=>{const n=acts.filter(it=>it.phase===p.id).length;h+=tile(p.name,n,'open items');});
  h+=tile('Awaiting GO',acts.filter(it=>it.status==='awaiting_go').length,'operator go');
  h+=tile('Blocked',acts.filter(it=>it.status==='blocked').length,'host or ruling');
  h+=tile('Open questions',D.questions.filter(x=>x.status==='open').length,'decisions');
  tilesEl.innerHTML=h;
}
function ctrlHTML(){
  if(view==='work'){
    const sel=(id,cur,vals,lab)=>`<label class="flab">${id[0].toUpperCase()+id.slice(1)} <select id="f_${id}">`+vals.map(v=>`<option value="${esc(v)}"${v===cur?' selected':''}>${esc(v==='all'?'all':(lab?lab(v):v))}</option>`).join('')+`</select></label>`;
    const phs=['all'].concat(D.phases.filter(p=>p.id!=='done').map(p=>p.id));
    const kinds=['all'].concat([...new Set(items.map(i=>i.kind))].sort());
    const stats=['all'].concat([...new Set(items.map(i=>i.status))].sort());
    const blks=['all'].concat([...new Set(items.map(i=>i.blocker))].sort());
    return sel('phase',flt.phase,phs,v=>phaseName[v]||v)+sel('kind',flt.kind,kinds)+sel('status',flt.status,stats,v=>SLB[v]||v)+sel('blocker',flt.blocker,blks)+`<button class="chip" id="showdone" aria-pressed="${showDone}">Show done and moot</button><button class="chip" id="showchanged" aria-pressed="${showChanged}">Changed only</button>`;
  }
  if(view==='questions'){return `<button class="chip" id="qres" aria-pressed="${qShowResolved}">Show resolved</button>`;}
  return '';
}
const COLS=[['order','#'],['id','id'],['title','title'],['phase','phase'],['kind','kind'],['status','status'],['blocker','blocker'],['sources','sources']];
function cmp(a,b){
  let x,y;
  if(sortKey==='sources'){x=(a.sources[0]||{}).list||'';y=(b.sources[0]||{}).list||'';}
  else if(sortKey==='order'){x=a.order==null?1e9:a.order;y=b.order==null?1e9:b.order;}
  else{x=(a[sortKey]==null?'':a[sortKey]);y=(b[sortKey]==null?'':b[sortKey]);}
  if(x<y)return -sortDir;if(x>y)return sortDir;
  const ao=a.order==null?1e9:a.order,bo=b.order==null?1e9:b.order;
  if(ao!==bo)return ao-bo;return a.id<b.id?-1:1;
}
function workRows(){
  const wantSt=flt.status!=='all';
  return items.filter(it=>{
    if(!showDone&&!isAct(it)&&!(wantSt&&flt.status===it.status))return false;
    if(flt.phase!=='all'&&it.phase!==flt.phase)return false;
    if(flt.kind!=='all'&&it.kind!==flt.kind)return false;
    if(flt.status!=='all'&&it.status!==flt.status)return false;
    if(flt.blocker!=='all'&&it.blocker!==flt.blocker)return false;
    if(showChanged&&!changed(it.id))return false;
    if(q){const hay=(it.id+' '+it.title+' '+it.detail+' '+it.done_when+' '+it.sources.map(s=>s.list+' '+s.ref).join(' ')).toLowerCase();if(hay.indexOf(q)<0)return false;}
    return true;
  }).sort(cmp);
}
function ovrHTML(it){
  const o=OVR[it.id]||{};const sel=o.sel||'';const nt=o.notes||'';
  const opts=['<option value="">(keep: '+esc(SLB[it.status]||it.status)+')</option>'].concat(STATUSES.filter(s=>s!==it.status).map(s=>`<option value="${s}"${sel===s?' selected':''}>${esc(SLB[s]||s)}</option>`)).join('');
  return `<div class="wovr"><label class="flab">Set status <select class="wsel" data-id="${esc(it.id)}">${opts}</select></label><textarea class="wnotes" data-id="${esc(it.id)}" placeholder="Why, or what to do instead…">${esc(nt)}</textarea><button class="chip wreset" data-id="${esc(it.id)}"${changed(it.id)?'':' disabled'}>Reset</button><span class="qhint">Your change saves in this page. Click Export changes above, then paste the block to the desk.</span></div>`;
}
function exportChanges(){
  const L=['CGA WORK — operator changes'];
  items.slice().sort(cmp).forEach(it=>{const o=OVR[it.id];if(!o||(!o.sel&&!o.notes))return;L.push('\n['+it.id+'] '+it.title+'\n  = '+(o.sel||'(no status change)')+(o.notes?'\n  notes: '+o.notes:''));});
  if(L.length===1)L.push('\n(no changes)');
  return L.join('\n');
}
function detailHTML(it){
  const idmap={};items.forEach(x=>idmap[x.id]=x.title);
  let h='<dl>';
  h+=`<dt>Done when</dt><dd>${hi(it.done_when)||'<em>-</em>'}</dd>`;
  if(it.detail)h+=`<dt>Detail</dt><dd>${hi(it.detail)}</dd>`;
  if(it.depends_on&&it.depends_on.length)h+=`<dt>Depends on</dt><dd>${it.depends_on.map(d=>`<span class="srcbadge">${esc(d)}</span>${esc(idmap[d]||'')}`).join(' · ')}</dd>`;
  if(it.sources&&it.sources.length)h+=`<dt>Sources</dt><dd>${it.sources.map(s=>`<span class="srcbadge">${esc(s.list)}${s.wave?' '+esc(s.wave):''}</span>${esc(s.ref)}`).join('<br>')}</dd>`;
  if(it.evidence&&it.evidence.length)h+=`<dt>Evidence</dt><dd>${it.evidence.map(e=>`<a href="${esc(e.href)}">${esc(e.label)}</a>`).join(' · ')}</dd>`;
  if(it.history&&it.history.length)h+=`<dt>History</dt><dd><ul>${it.history.map(x=>`<li><span class="meta">${esc(x.date)}</span> ${hi(x.note)}</li>`).join('')}</ul></dd>`;
  return h+'</dl>'+ovrHTML(it);
}
function workView(){
  const rows=workRows();
  const th=COLS.map(([k,l])=>`<th data-k="${k}">${esc(l)}${sortKey===k?` <span class="ar">${sortDir>0?'▲':'▼'}</span>`:''}</th>`).join('');
  const nchg=Object.keys(OVR).filter(changed).length;
  let h=`<div class="qbar"><button class="chip" id="wexport">⭳ Export changes${nchg?' ('+nchg+')':''}</button><span class="qhint">Open a row, set a status and add notes; changes save in this page. Export copies a block of the changes still pending; a change the desk has folded in clears itself on the next reload.</span></div><div id="wexport-wrap" class="qexport-wrap hidden"><button class="qcopy" id="wexport-copy" title="Copy changes to clipboard">⧉ Copy</button><pre id="wexport-out" class="qexport"></pre></div>`;
  h+=`<p class="stamp">${rows.length} of ${items.length} items shown</p><div class="tblwrap"><table class="wtable"><thead><tr>${th}</tr></thead><tbody>`;
  rows.forEach(it=>{
    const src=it.sources.map(s=>`<span class="srcbadge">${esc(s.list)}</span>`).join('');
    const o=OVR[it.id]||{};const ov=o.sel?` <span class="pill p-${o.sel}" title="your change, not yet folded in">→ ${SLB[o.sel]||o.sel}</span>`:(o.notes?' <span class="pill p-open" title="note pending export">note</span>':'');
    h+=`<tr class="wrow${changed(it.id)?' chg':''}" data-id="${esc(it.id)}"><td class="c-order">${it.order==null?'—':it.order}</td><td class="c-id">${esc(it.id)}</td><td class="c-title">${hi(it.title)}</td><td>${esc(phaseName[it.phase]||it.phase)}</td><td class="kindb">${esc(it.kind)}</td><td><span class="pill p-${it.status}">${SLB[it.status]||it.status}</span>${ov}</td><td>${esc(it.blocker==='none'?'-':it.blocker)}</td><td>${src}</td></tr>`;
    h+=`<tr class="drow hidden" data-for="${esc(it.id)}"><td colspan="${COLS.length}"><div class="detail" style="border:0;padding:0">${detailHTML(it)}</div></td></tr>`;
  });
  h+='</tbody></table></div>';
  if(!rows.length)h+='<div class="detail">No items match.</div>';
  return h;
}
function archiveView(){
  const LORD=['screens','caps','debt','fleet','punch','review'];
  const arch=items.filter(it=>it.status==='done'||it.status==='moot').filter(it=>{if(!q)return true;const hay=(it.id+' '+it.title+' '+it.detail+' '+it.sources.map(s=>s.list+' '+s.ref).join(' ')).toLowerCase();return hay.indexOf(q)>=0;});
  const g={};
  arch.forEach(it=>{const l=(it.sources[0]||{}).list||'other';const w=(it.sources[0]||{}).wave||'—';g[l]=g[l]||{};(g[l][w]=g[l][w]||[]).push(it);});
  let h=`<p class="stamp">${arch.length} done and moot items · grouped by original list and wave · collapsed</p>`;
  const order=LORD.concat(Object.keys(g).filter(l=>LORD.indexOf(l)<0));
  order.forEach(l=>{
    if(!g[l])return;
    const waves=Object.keys(g[l]).sort();
    const tot=waves.reduce((n,w)=>n+g[l][w].length,0);
    h+=`<section class="area"><button class="area-head" aria-expanded="false"><span class="area-name">${esc(l)}</span><span class="bar"></span><span class="counts">${tot}</span><span class="chev">›</span></button><div class="rows hidden">`;
    waves.forEach(w=>{
      h+=`<div class="wgrp-h">wave ${esc(w)} · ${g[l][w].length}</div>`;
      g[l][w].forEach(it=>{
        const ev=(it.evidence||[]).map(e=>`<a href="${esc(e.href)}">${esc(e.label)}</a>`).join(' · ');
        h+=`<div class="scr"><button class="scr-head" aria-expanded="false"><span class="dot d-${SCO[it.status]}"></span><span class="scr-title">${hi(it.title)}</span><span class="pill p-${it.status}">${SLB[it.status]}</span></button><div class="detail hidden"><dl><dt>Detail</dt><dd>${hi(it.detail)}</dd>${ev?`<dt>Evidence</dt><dd>${ev}</dd>`:''}<dt>Id</dt><dd class="meta">${esc(it.id)}</dd></dl></div></div>`;
      });
    });
    h+='</div></section>';
  });
  return h||'<div class="detail">No archived items.</div>';
}
function questionsView(){
  const vis=D.questions.filter(r=>(r.status==='open'||(qShowResolved&&r.status==='resolved'))&&(!q||(r.q+' '+r.detail).toLowerCase().includes(q)));
  let html='<div class="qbar"><button class="chip" id="qexport">⭳ Export answers</button><span class="qhint">Pick an option and add notes on each open question — your answers save in the page. When done, click Export (copies to clipboard) or screenshot; either lets the desk read them and update the fleet orders.</span></div><div id="qexport-wrap" class="qexport-wrap hidden"><button class="qcopy" id="qexport-copy" title="Copy answers to clipboard">⧉ Copy</button><pre id="qexport-out" class="qexport"></pre></div>';
  vis.forEach(r=>{
    if(r.status==='resolved'){html+=`<div class="qcard resolved"><div class="qhead"><span class="lwbadge">${/^\d+$/.test(String(r.lane))?'L':''}${esc(r.lane)}</span><span class="qtext">${hi(r.q)}</span><span class="pill p-resolved">resolved</span></div><div class="qdetail">${hi(r.detail)}</div></div>`;}
    else{const a=ANS[r.id]||{};const sel=a.sel||'';const nt=a.notes||'';
      html+=`<div class="qcard open"><div class="qhead"><span class="lwbadge">${/^\d+$/.test(String(r.lane))?'L':''}${esc(r.lane)}</span><span class="qtext">${hi(r.q)}</span><span class="pill p-open">open</span></div><div class="qdetail">${hi(r.detail)}</div><div class="qopts">`+r.options.map(o=>`<label class="qopt${sel===o.k?' on':''}"><input type="radio" name="q_${r.id}" value="${o.k}"${sel===o.k?' checked':''}><span class="qk">${o.k}</span><span>${esc(o.t)}</span></label>`).join('')+`</div><textarea class="qnotes" data-id="${r.id}" placeholder="Notes / your own answer…">${esc(nt)}</textarea></div>`;}
  });
  return html;
}
function render(){
  const b=document.getElementById('body');
  if(view==='work'){b.innerHTML=workView();
    b.querySelectorAll('.wtable th').forEach(th=>th.addEventListener('click',()=>{const k=th.dataset.k;if(sortKey===k)sortDir=-sortDir;else{sortKey=k;sortDir=1;}render();}));
    b.querySelectorAll('tr.wrow').forEach(tr=>tr.addEventListener('click',()=>{const dr=b.querySelector('tr.drow[data-for="'+CSS.escape(tr.dataset.id)+'"]');if(dr)dr.classList.toggle('hidden');}));
    b.querySelectorAll('.wsel').forEach(s=>s.addEventListener('change',e=>{saveOvr(e.target.dataset.id,'sel',e.target.value);const open=[...b.querySelectorAll('tr.drow:not(.hidden)')].map(x=>x.dataset.for);render();open.forEach(id=>{const dr=b.querySelector('tr.drow[data-for="'+CSS.escape(id)+'"]');if(dr)dr.classList.remove('hidden');});}));
    b.querySelectorAll('.wnotes').forEach(ta=>ta.addEventListener('input',e=>{saveOvr(e.target.dataset.id,'notes',e.target.value);const rs=e.target.parentElement.querySelector('.wreset');if(rs)rs.disabled=!changed(e.target.dataset.id);}));
    b.querySelectorAll('.wreset').forEach(bt=>bt.addEventListener('click',e=>{const id=e.target.dataset.id;saveOvr(id,'sel','');saveOvr(id,'notes','');const open=[...b.querySelectorAll('tr.drow:not(.hidden)')].map(x=>x.dataset.for);render();open.forEach(x=>{const dr=b.querySelector('tr.drow[data-for="'+CSS.escape(x)+'"]');if(dr)dr.classList.remove('hidden');});}));
    const wx=document.getElementById('wexport');if(wx)wx.addEventListener('click',()=>{const txt=exportChanges();const o=document.getElementById('wexport-out');o.textContent=txt;document.getElementById('wexport-wrap').classList.remove('hidden');try{navigator.clipboard.writeText(txt);}catch(e){}});
    const wc=document.getElementById('wexport-copy');if(wc)wc.addEventListener('click',()=>{const t=document.getElementById('wexport-out').textContent;const done=()=>{wc.textContent='✓ Copied';wc.classList.add('ok');setTimeout(()=>{wc.textContent='⧉ Copy';wc.classList.remove('ok');},1400);};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(done).catch(()=>{});}else{const ta=document.createElement('textarea');ta.value=t;document.body.appendChild(ta);ta.select();try{document.execCommand('copy');done();}catch(e){}ta.remove();}});
    return;}
  if(view==='archive'){b.innerHTML=archiveView();
    b.querySelectorAll('.area-head').forEach(hh=>{const list=hh.nextElementSibling;if(!list)return;hh.addEventListener('click',()=>{const hid=list.classList.toggle('hidden');hh.setAttribute('aria-expanded',String(!hid));});});
    b.querySelectorAll('.scr-head').forEach(hh=>{const dt=hh.nextElementSibling;hh.addEventListener('click',()=>{const hid=dt.classList.toggle('hidden');hh.setAttribute('aria-expanded',String(!hid));});});
    return;}
  // questions
  b.innerHTML=questionsView();
  b.querySelectorAll('input[type=radio]').forEach(inp=>inp.addEventListener('change',e=>{const id=e.target.name.slice(2);saveAns(id,'sel',e.target.value);e.target.closest('.qopts').querySelectorAll('.qopt').forEach(l=>l.classList.toggle('on',l.querySelector('input').checked));}));
  b.querySelectorAll('.qnotes').forEach(ta=>ta.addEventListener('input',e=>saveAns(e.target.dataset.id,'notes',e.target.value)));
  const ex=document.getElementById('qexport');if(ex)ex.addEventListener('click',()=>{const L=['CGA OPEN-QUESTIONS — operator answers'];D.questions.filter(x=>x.status==='open').forEach(x=>{const a=ANS[x.id]||{};const s=a.sel||'(none)';const ot=(x.options.find(o=>o.k===s)||{}).t||'';L.push('\n[L'+x.lane+'] '+x.q+'\n  = '+s+(ot?' — '+ot:'')+(a.notes?'\n  notes: '+a.notes:''));});const txt=L.join('\n');const o=document.getElementById('qexport-out');o.textContent=txt;document.getElementById('qexport-wrap').classList.remove('hidden');try{navigator.clipboard.writeText(txt);}catch(e){}});
  const cp=document.getElementById('qexport-copy');if(cp)cp.addEventListener('click',()=>{const t=document.getElementById('qexport-out').textContent;const done=()=>{cp.textContent='✓ Copied';cp.classList.add('ok');setTimeout(()=>{cp.textContent='⧉ Copy';cp.classList.remove('ok');},1400);};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(done).catch(()=>{});}else{const ta=document.createElement('textarea');ta.value=t;document.body.appendChild(ta);ta.select();try{document.execCommand('copy');done();}catch(e){}ta.remove();}});
}
function buildControls(){
  document.getElementById('filters').innerHTML=ctrlHTML();
  const wire=(id,fn)=>{const el=document.getElementById(id);if(el)el.addEventListener('change',fn);};
  wire('f_phase',e=>{flt.phase=e.target.value;render();});
  wire('f_kind',e=>{flt.kind=e.target.value;render();});
  wire('f_status',e=>{flt.status=e.target.value;render();});
  wire('f_blocker',e=>{flt.blocker=e.target.value;render();});
  const sd=document.getElementById('showdone');if(sd)sd.addEventListener('click',()=>{showDone=!showDone;sd.setAttribute('aria-pressed',String(showDone));render();});
  const sc=document.getElementById('showchanged');if(sc)sc.addEventListener('click',()=>{showChanged=!showChanged;sc.setAttribute('aria-pressed',String(showChanged));render();});
  const qr=document.getElementById('qres');if(qr)qr.addEventListener('click',()=>{qShowResolved=!qShowResolved;qr.setAttribute('aria-pressed',String(qShowResolved));render();});
}
document.querySelectorAll('.view-btn').forEach(vb=>vb.addEventListener('click',()=>{view=vb.dataset.v;document.querySelectorAll('.view-btn').forEach(x=>x.setAttribute('aria-selected',String(x===vb)));buildControls();render();}));
document.getElementById('q').addEventListener('input',e=>{q=e.target.value.trim().toLowerCase();render();});
renderTiles();buildControls();render();
</script>
"""


def build_html(work):
    data = {'asOf': work.get('asOf'), 'head': work.get('head'),
            'phases': work.get('phases', []), 'items': work.get('items', []),
            'questions': QUESTIONS}
    acts = [it for it in work.get('items', []) if it.get('status') in ACTIONABLE]
    qopen = len([x for x in QUESTIONS if x['status'] == 'open'])
    stamp = ('As of %s · main @ %s · %d open work items · %d open questions · one file'
             % (work.get('asOf'), work.get('head'), len(acts), qopen))
    return (TEMPLATE
            .replace('%%DATA%%', json.dumps(data, separators=(',', ':')))
            .replace('%%STAMP%%', stamp))


def selftest():
    good = {
        'asOf': 'x', 'head': 'h',
        'phases': [{'id': 'P1', 'name': 'One', 'goal': ''}, {'id': 'done', 'name': 'Done', 'goal': ''}],
        'items': [
            {'id': 'W-0001', 'title': 't', 'phase': 'P1', 'order': 1, 'kind': 'build',
             'status': 'open', 'blocker': 'none', 'detail': 'd', 'done_when': 'ok',
             'depends_on': [], 'sources': [], 'evidence': [], 'history': []},
            {'id': 'W-0002', 'title': 'd', 'phase': 'done', 'order': None, 'kind': 'build',
             'status': 'done', 'blocker': 'none', 'detail': '', 'done_when': '',
             'depends_on': ['W-0001'], 'sources': [], 'evidence': [], 'history': []},
        ],
    }
    assert check_work(good) == [], ('good fixture should pass', check_work(good))
    bad = {
        'asOf': 'x', 'head': 'h',
        'phases': [{'id': 'P1', 'name': 'One', 'goal': ''}],
        'items': [
            {'id': 'W-1', 'title': 't', 'phase': 'P1', 'order': 1, 'kind': 'build',
             'status': 'open', 'blocker': 'none', 'detail': 'd', 'done_when': '',
             'depends_on': ['W-9'], 'sources': [], 'evidence': [], 'history': []},
            {'id': 'W-1', 'title': 't2', 'phase': 'NOPE', 'order': 1, 'kind': 'build',
             'status': 'open', 'blocker': 'none', 'detail': 'd', 'done_when': 'x',
             'depends_on': [], 'sources': [], 'evidence': [], 'history': []},
        ],
    }
    f = check_work(bad)
    assert any('duplicate ids' in x for x in f), f
    assert any('unknown phase' in x for x in f), f
    assert any('duplicate order' in x for x in f), f
    assert any('does not resolve' in x for x in f), f
    assert any('done_when' in x for x in f), f
    # the real file, if present, must pass
    p = os.path.join(_HERE, 'work.json')
    if os.path.exists(p):
        real = json.load(open(p, encoding='utf-8'))
        rf = check_work(real)
        assert rf == [], ('real work.json failed --check', rf[:10])
        # and it must render
        h = build_html(real)
        assert '<title>' in h and '%%DATA%%' not in h
    print('gen_app_rubric selftest OK')


def main():
    argv = sys.argv[1:]
    if '--selftest' in argv:
        selftest()
        return
    work = load_work()
    faults = check_work(work)
    if '--check' in argv:
        if faults:
            print('work.json FAILED --check:')
            for x in faults:
                print('  -', x)
            sys.exit(1)
        print('work.json OK ·', len(work.get('items', [])), 'items ·',
              sum(1 for it in work['items'] if it['status'] in ACTIONABLE), 'open ·',
              len(work.get('phases', [])), 'phases')
        return
    if faults:
        print('REFUSING TO WRITE: work.json failed --check:')
        for x in faults:
            print('  -', x)
        sys.exit(1)
    html = build_html(work)
    out = os.path.join(_HERE, 'app_progress_rubric.html')
    with io.open(out, 'w', encoding='utf-8') as f:
        f.write(html)
    print('wrote', out, len(html), 'bytes ·', len(work.get('items', [])), 'items ·',
          len(QUESTIONS), 'questions')


if __name__ == '__main__':
    main()
