# UI <-> CLI Parity Inventory — the standing order's debt, sized

*Generated 2026-07-29 by the desk's parity census (3 agents over both directions), per the
operator's standing order (ruling 10, `V3_SYNTHESIS_PLAN.md` §11): anything terminal-only
needs a UI version; anything UI-only needs a CLI equivalent; guards travel with the pair —
parity of capability, never of exposure. Wave 2+ orders draw from the debt tables below;
exempt rows carry their reason and are the desk's judgment calls, revisable.*

## Rollup

| Verdict | Count |
|---|---:|
| has-pair | 42 |
| needs-ui | 25 |
| needs-cli | 15 |
| exempt | 12 |
| **Total capabilities surveyed** | **94** |

| Debt owner | items |
|---|---:|
| lane 2 | 16 |
| lane 4 | 0 ✓ (all 8 closed — Wave 2, see below) |
| lane 1 | 7 |
| lane 3 | 6 |
| lane 6 | 2 |
| lane 5 | 1 |

## Wave 2 closures — lane 4 (2026-07-29)

All eight lane-4 debt rows are now closed; the census predated Wave 1's D5 scenario
panel for four of them.

| Row | Was | Now paired by | Pin |
|---|---|---|---|
| `sim:start` | needs-ui | Start/Resume/Halt on the /simworld console → `SimRunControl` (operator-gated); halt/resume are NEW on BOTH surfaces (`sim:halt`/`sim:resume`), parity by construction | `SimControlParityTest` |
| `federation:demo` | needs-ui | `'federation'` preset in `ScenarioPresetService` (guard travels by construction) | `SyntheticDataGuardTest` |
| `matrix:demo` | needs-ui | `'matrix-commons'` preset (queues `matrix:demo --offline` — Plane-A artifacts, never blocks on a homeserver) | `SyntheticDataGuardTest` |
| `institutions:demo-d` | needs-ui | already closed by D5's `'executive-orgs'` preset (census predated it) | — |
| `institutions:demo-e` | needs-ui | already closed by D5's `'judiciary'` preset | — |
| `social:demo` | needs-ui | already closed by D5's `'social'` preset | — |
| `institutions:demo-treasury` | needs-ui | already closed by D5's `'economy'` preset | — |
| Dev board seat/unseat | needs-cli | **`dev:board-seat` — a STANDALONE command, NOT an option on `dev:assume`.** The census proposal conflicts with the pinned AssumeService-never-seats invariant; both doors share `DevBoardSeatService` + the `DevToolsEnabled::allowed()` gate | `DevBoardSeatParityTest` |

New has-pair capabilities minted this wave: `sim:halt` ↔ console Halt, `sim:resume` ↔ console
Resume (both doors built together, guards in the one shared `SimRunControl`).

## Wave 3 closures — lane 1 (2026-07-29)

| Row | Was | Now paired by | Pin |
|---|---|---|---|
| `Type B districting` (NEW capability) | minted paired | `type-b:district` CLI ↔ the Step-3 dashboard's "Group Type B chambers" control. Both doors call the ONE `TypeBDistrictMapper` service (single source); the operator gate (`abort_unless is_operator`) travels with the UI door, mirroring the eight other Step-3 operator actions. The CLI's `--dry-run`/`--limit` and the UI's bounded batch are the same guard against an unattended planet-wide sweep. | `TypeBDistrictMapperApplyTest` (service + un-flag + race-schedulable), `TypeBDistrictMapperTest` (grouping law B1–B7) |

## Wave 4 closures — lane 4 (2026-07-29)

| Row | Was | Now paired by | Pin |
|---|---|---|---|
| `World rollup / the Atlas` (NEW capability) | minted paired | **`world:stats` CLI ↔ the `/atlas` screen.** Both doors read the SAME nightly `world_stats` row through the one `WorldStatsService`, so a metric cannot exist on one surface only. `--refresh` is the CLI half of what `SnapshotWorldStatsJob` does nightly. ⚑ **NO `GuardsSyntheticData`, deliberately** — that guard stops synthetic MINTING and refuses on a production instance, whereas a rollup recomputes a real public aggregate; gating it would deny ops the gauge on a live node (corrects ATLAS_DESIGN §9; desk ACCEPTED, W4 tick 8). The rail that does travel with both doors is the suppression contract: a withheld figure is a GAP, never a zero. | `AtlasGaugeNeverLeverTest` (never counts the world · one mutating call · gap-never-zero · public read), `WorldRollupSuppressionTest` (suppressed adds nothing but is gauged · all-suppressed night publishes no total · planned domains absent), `AtlasPageTest` |

**⚑ PUNCH ITEM RAISED (not lane 4's to close): `SnapshotLegitimacyJob` has NO CLI twin at all.**
Verified three ways — no command references `snapshotAll`/`SnapshotLegitimacyJob`, and the only
reach-named command (`mesh:reach`) is unrelated service reachability. The nightly reach pass can be
run ONLY by the scheduler or a manual dispatch, so the Reach surface is a UI-only capability with an
un-runnable engine half. Found while building the Atlas rollup, which does not inherit the omission.
Owner: whoever owns Reach/Phase I.

## NEEDS A UI — terminal-only capabilities

| Capability | Purpose | Guards (travel with the pair) | Proposal | Owner |
|---|---|---|---|---|
| `audit:reconcile` | Detect audit-chain breaks and re-ground them by a signed constitutional acknowledgement | Requires an active OperatorAccount (or founder is_operator user) to sign; reason recorded on the chain | Operator-gated 'Reconcile break' action with required reason field on /system/audit-chain, wired to the same signer resolution | lane 3 |
| `autoscale:revert` | Rewind the autoscale run to the mapping start: delete generated maps, reset items, keep sizing + precompute | --force/--resume flags; adopted/operator maps never tagged, never touched; TRUNCATE fast path only when no operator maps and no seated world | Guarded 'Rewind mapping' control beside halt/resume on the Step-3 autoscale dashboard (operator-gated, confirm step standing in for --force) | lane 1 |
| `directory:publish` | Publish this node's transport endpoints into the federation directory | Publishes only for jurisdictions this server is explicitly authoritative for | A 'Publish to directory' action on the /operator/mesh transports panel (or auto-publish on transport register/disable) | lane 2 |
| `federation:cold-sync` | Pull a trusted peer's full public corpus in bounded, resumable, signed pages | Trusted (handshaken) peer only; signed pages; resumes an open cursor | Per-peer 'Pull full corpus' action on /federation, progress via the existing sync-progress poll | lane 2 |
| `federation:demo` | Stand up a browsable demo federation peer + sync history + a flipped partition | GuardsSyntheticData; --fresh retires demo-tagged rows first | Add a 'federation' preset to ScenarioPresetService (the panel queues the exact command; GuardsSyntheticData travels by construction) | lane 4 |
| `federation:flip:export` | Export a jurisdiction partition and flip its authority to a trusted peer (WF-JUR-08) | Trusted peer required; signed manifest; sealed operational bundle rides the separate /flip/operational channel | Operator-gated 'Flip authority to peer' action on the /federation host block, wired from an approved read-write petition row | lane 2 |
| `federation:geodata:seed-publish` | Build the geodata-foundation seed tarball and publish its signed manifest (donor side) | Origin-signed manifest; integrity by sha256; peers pull over pinned channels | 'Publish geodata seed' operator action on the /federation host block with build-progress readout | lane 2 |
| `federation:sync:push` | Push our Full-Faith-&-Credit tail to trusted federation peers now | Trusted peers only; the CLK-20 heartbeat job pushes automatically | Per-peer 'Sync now' button on the /federation sync panel calling the same FederationSyncService::pushTo | lane 2 |
| `federation:upgrade:consent` | Deliver this node's Meter C mesh consent for an upgrade proposal to the proposer | Signed as this node; standing (co-affected authority) enforced by the receiving service; --reject delivers a NO | Consent / Reject actions on /operator/versioning proposal rows (auth:operator), wrapping the same delivery service | lane 2 |
| `geodata:repairs-apply` | Replay a geodata repair manifest (idempotent — matching state skipped) | Idempotent replay; repair window (setup incomplete + maps unaccepted) enforced in GeodataRemediationService | Manifest upload + replay action on the geodata flag-queue panel (window guard already lives in the service) | lane 1 |
| `geodata:repairs-export` | Export the applied geodata repairs as a replayable JSON manifest | Read-only | 'Download repair manifest' button on the repair-queue panel (one GET endpoint over the existing export service) | lane 1 |
| `geodata:synthesize-remainders` | Synthesize component-aware remainder children for coverage-gapped parents | Population-based coverage gate; --resplit converts legacy multi-part remainder rows in place | Surface as a repair action on coverage-gap flags in the geodata queue (same window guard as the other repairs) | lane 1 |
| `institutions:provision` | Provision executive, court, election board + civic spaces for every jurisdiction with a legislature | Idempotent + resumable (partial unique indexes); --dry-run preview; --no-audit testing only; zero-population rule under real binding | 'Provision missing institutions' operator action on /building, with --dry-run as the preview state | lane 3 |
| `jurisdiction:activate` | Run the WF-JUR-01 activation pipeline for one jurisdiction (sizing + institution stubs + bootstrap elections) | CLK-06 critical-population gate unless --force (dev bootstrap); --replan refuses seated chambers | Dev-gated 'Activate now' button on the jurisdiction viewer's activation card (force rides the dev gate; same engine refusals) | lane 3 |
| `launch:assert-clean` | Assert this instance carries no synthetic data and no dev controls (pre-launch + nightly gate) | It IS the guard — borrows DevTimeControlsEnabled as the live-gate truth; read-only assertions | Render its pass/fail checklist as a launch-readiness panel on /operator/console beside the mesh gates (read-only run) | lane 2 |
| `matrix:demo` | Seed a standing Matrix-commons demo (topology + testimony + the legitimacy flip) on San Marino | GuardsSyntheticData; ephemeral demo operator force-deleted so Meter A counts never skew; --offline skips homeserver round-trips | Add a 'commons-live' preset to ScenarioPresetService (queue with --offline fallback when no homeserver answers) | lane 4 |
| `mesh:broker-failover` | Drive trusted-broker credential failover: status / designate / share / allow (+ undesignate / deny) | Per-domain accept opt-in; sealed payload must name this box + sender; token never echoed; trusted pinned peers only | Designate/allow/share failover controls beside the broker-credential panel on /federation (auth:operator, same sealed-share service) | lane 2 |
| `mesh:cert-grant` | Mint + deliver a cert_grant for a peer's name over the mesh (no copy-paste) | Grantee verifies against the authority's OWN pinned key, never the relayer's; authority standing required | 'Grant certificate' action (peer + domain/subdomain picker) on the /federation roles-broker panel | lane 2 |
| `mesh:reach` | Resolve mesh reach for a live service (matrix.homeserver / voice.sfu) — ranked holders + chosen reach | Read-only resolution (the app exercises the same path live via voice-reach) | A reach readout card on /operator/mesh (same resolver, one row per service) | lane 2 |
| `mesh:request-cert` | Request + install a TLS cert for <subdomain>.<domain> from a mesh broker | Requires an authority-delivered grant (--grant-file) or authorized-authority self-cert; --local = offline stub broker | 'Request certificate' form on /operator/mesh wired to CertClientService (grant-file upload travels with it) | lane 2 |
| `institutions:demo-d (PhaseDDemoCommand.php)` | Persist the Phase D exit-criterion flows on San Marino (delegated executive, department+CLK-09 governor, executive orders incl. a rejected one, 100-worker co-determination org, CGC with public-domain IP) so the executive/org pages are browsable. | GuardsSyntheticData (runs only on instance_class=scale_demo or game_mode=sandbox); idempotent plain re-run; --fresh teardown is tag-exact and never touches append-only planes (audit_log, public_records, cgc_ip_register); ends with audit:verify. | A seed panel in the Demo flyout: one button per demo seeder Artisan::call-ing the command (+--fresh toggle), with the GuardsSyntheticData refusal computed server-side and rendered verbatim (the D2 refusal-sentence contract) — the guard travels with the pair. | lane 4 |
| `institutions:demo-e (PhaseEDemoCommand.php)` | Persist the Phase E exit-criterion flows on San Marino (appointed court seated via F-LEG-017/021, advocate + advocate filing, two cases mid-lifecycle/closed, two Art. IV §5 challenges incl. one driven to judicial_remedy_applied) so the judiciary pages are browsable. | GuardsSyntheticData; idempotent plain re-run; --fresh teardown tag-exact, soft-deletes rows pinned by append-only tables (case_filings, judicial_remedy law_versions), never deletes audit/public_records; ends with audit:verify; never writes clock fires_at (the ElectionClockTest source-scan pin). | Same Demo-flyout seed panel row as demo-d — button + --fresh toggle, server-side synthetic-data refusal rendered verbatim. | lane 4 |
| `sim:start (SimStartCommand.php)` | Start or --resume a simulated-world populate run: create the SimRun (world-version/turnout/adm-max/limit) and enumerate the ~907k cohort_scope worklist in committed chunks, largest-first. | GuardsSyntheticData; refuses a second concurrent run without --resume (one run holds the engine); ETL-RULE chunked enumeration with NOT-EXISTS redo guard; summary audit entry appended last to avoid holding the global advisory lock. | Start/Resume/Halt controls on the /simworld console posting to a controller that calls the same code paths, keeping the single-run law and rendering the GuardsSyntheticData refusal verbatim (halt = the existing DB halt flag the pump already honors). | lane 4 |
| `social:demo (SocialDemoCommand.php)` | Seed a standing Phase K-1 civic commons on San Marino — square + halls spaces via EvaluateSocialStructureJob, three F-SOC-001 square posts, one halls thread with an F-SOC-002 testimony sealed into the append-only register. | GuardsSyntheticData; self-sufficient (mints its own tagged residents); --fresh soft-deletes only the demo social graph — public_records and audit chain never deleted; ends with audit:verify; missing seated legislature is a note, not a failure. | Same Demo-flyout seed panel row as the other seeders (button + --fresh, server-side refusal verbatim). | lane 4 |
| `institutions:demo-treasury (TreasuryDemoCommand.php)` | Seed a standing Phase L+M economy demo (root currency, public treasury, 250k opening mint, resident wallets, a stipend run, a market with a settled sale) driven through the real economy services only. | GuardsSyntheticData (added 2026-07-28 after the D5 preset audit — it previously shipped unguarded); never re-mints supply (ledger append-only — --fresh cannot un-mint); services-only writes so an unbalanced posting fails rather than seeds. | Same Demo-flyout seed panel row (button + --fresh, server-side refusal verbatim); the doors-never-shortcuts ruling stands — the UI button invokes the command, it does not open a new economy write surface. | lane 4 |

## NEEDS A CLI — UI-only capabilities

| Capability | Purpose | Guards (travel with the pair) | Proposal | Owner |
|---|---|---|---|---|
| `Constitutional form filings + engine actions (the class)` | ~80 web POSTs file F-IND/F-CAN/F-ELB/F-LEG/F-SPK/F-CHR/F-EXE/F-BOG/F-ORG/F-JDG/F-ADV/F-SOC/F-IND-022..024 + audited engine actions (approvals, travelling, testimony, oath, votes) through ConstitutionalEngine::file() | Role gates + phase windows + state guards live in the ENGINE, never route middleware; ConstitutionalViolation → citation verbatim; hash-chained audit log | ONE generic `engine:file {form} {--actor=} {--payload=json}` closes the whole class — the engine re-validates identically and the audit chain records console origin, so guards travel by construction | lane 3 |
| `Citizen ballot casting (two-phase commitment)` | F-IND-007 ranked / F-IND-008 referendum ballots ride the ballot commitment scheme (client-side commit/reveal, cryptographic voter-ballot separation) | Ballot secrecy hard constraint (Art. II); commitment computed client-side; engine validates phase windows | engine:file plus a --commit/--reveal helper that computes the commitment hash locally (bespoke because the crypto handshake is client work, not just a filing) | lane 3 |
| `Setup founding flow` | Create founder, cosmic address, founding constitution defaults, game mode, operator profile+roles, step-3/4 completion — the world-founding sequence | auth + is_operator in handlers; refuses once setup complete; constants write bypasses F-LEG-031 only pre-founding | `setup:found` (or per-step setup:* verbs) calling the same SetupController service layer, keeping the is_operator + setup-incomplete refusals — enables headless box founding | lane 2 |
| `Map acceptance gate + step-2 review decisions` | Accept Map Data (locks the repair window), reopen-maps, and per-row review decision capture on population/orphan/sovereignty discrepancies | is_operator in controller; reopen 403s once setup complete; no autofix — decisions recorded only | `maps:accept` / `maps:reopen` + `maps:review-decide {jurisdiction} {category} {decision}` wrapping the same service calls with the same window checks | lane 1 |
| `Portable archive export/import` | Stream/queue a tar.gz of jurisdictions+worldpop+meta tables; import pg_restores into a truncated schema (the export-bundle-equals-seed paradigm) | halt flag the job polls; filename whitelist on download | `maps:export {--skip-rasters}` / `maps:import {file}` artisan wrapping the same jobs (halt = signal) | lane 2 |
| `Autoscale run halt/resume` | Operator halts or resumes the full-scale districting run from the Step-3 dashboard | is_operator; halt = halt_requested_at flag + immediate pump to park | `autoscale:halt` / `autoscale:resume {--requeue-review}` — trivial wrappers over the flag + pump dispatch the UI already uses | lane 1 |
| `District plan lifecycle` | Create/copy/activate/delete district map plans and hand-edit district membership/seats | draft/active/archived versioning; activation swaps the live plan; auth actor | `district-map:{create|copy|activate|delete} {legislature} {plan}` thin wrappers keeping the status-transition guards | lane 1 |
| `Ballot receipt self-audit` | Anyone checks a receipt hash against ballots.ballot_hash (anonymized, unauthenticated by design) | anonymized lookup, throttle-free public POST | `elections:receipt-check {hash}` — a five-line read command; verification parity matters most for the least-trusting user | lane 3 |
| `Invite link minting` | Mint a shareable handle.secret invite link carrying a destination the inviter can reach (growth loop) | auth + throttle; destination reachability check; invite grants NO power | `invite:mint {user} {destination}` reusing InviteController's service + reachability guard | lane 6 |
| `Support report intake` | Attributed filing of a support/report request (routes a request, removes nothing) | POST auth-gated (attribution); category whitelist; plain model write, NOT an engine filing | `support:report {category} {--user=} {--ref=}` + a `support:list` read verb (the missing triage door) in the same small command | lane 6 |
| `Travelling declaration` | "I'm travelling — keep my residency": an audited standing declaration, deliberately NOT a form (no F-ID, RelocationController §B.14) | POST auth-gated; changes NOTHING (residency + associations untouched); mirrors AuditService::append, not an engine filing — so `engine:file` does NOT cover it | **DONE `civic:travelling {--user=}` (L6W4, 69c4b9d)** — appends the identical audit entry (module residency, event relocation.travelling_declared, ref WF-CIV-03) the web action does | lane 6 |
| `Read-write petition deny (host side)` | Host operator denies a mirror's petition for read-write authority (the governed Art. V §7 flip keeps the approve path) | auth:operator; deny grants nothing, the flip itself is governed | `federation:rw:deny {id}` wrapper over the same FederationHostController service call | lane 2 |
| `Broker credential set/forget` | Operator drops a Cloudflare token for a domain into the local encrypted never-federated store (write-only, never read back) | auth:operator; token never echoed to UI; encrypted at rest | `mesh:broker-credential {domain} {zone_id} {--forget}` with a hidden secret prompt (never an argv token), calling BrokerCredentialService | lane 2 |
| `Operator tuning knobs + host-apply` | Instant-tier infra_overrides knob edits (apply next request) and restart-tier host-apply (LiveKit ICE) from /operator/operations | auth:operator; overrides overlay config at boot; host supervisor applies staged desired-state file | `operator:tune {key} {value}` / `operator:tune --reset` over OperatorSettingsService (host-apply keeps its file protocol) | lane 2 |
| `Dev board seat/unseat (one-click R-08)` | Seat/unseat the current user on a jurisdiction's active election board for districting walkthroughs | local-env + DevToolsEnabled + auth; dev:assume deliberately never seats anyone | `--board-seat {legislature}` / `--board-unseat` options on dev:assume (same active-board resolution the controller uses) | lane 4 |
| `Translation run control + review verdicts` | Halt/resume a live translation run; a reader of the language rules verdicts on machine-draft strings | halt/resume operator-only; verdicts gated to readers of the language (canVerify in controller) | `i18n:review {locale} {namespace} {key} {verdict} {--user=}` carrying the reader-of-language guard (the reviewer's languages are on their record) | lane 5 |

## EXEMPT — with the reason on every row

| Capability | Why exempt | Owner |
|---|---|---|
| `autoscale:pump` | schedule-internals liveness plumbing — the pump is the run's heartbeat, not a human act; the human acts (start/halt/resume) already have UI | lane 1 |
| `autoscale:resize-repair` | one-shot data-repair migration for worlds sized under the pre-cycle-2 law — new runs apply the leaf law + Type B ladder natively, so this is migration tooling, not an ongoing capability | lane 1 |
| `districts:backfill-stats` | migration-style backfill of derived columns now computed at write time — not an ongoing human capability | lane 1 |
| `geojson:prewarm` | cache-warming plumbing — the capability (a warm map) is not a human act; the job dispatches it when operator actions invalidate caches | lane 1 |
| `matrix:setup` | deploy-time provisioning tooling — it writes host secret files outside the app runtime; the human act is deployment itself (deploy.sh territory), and the runtime knobs already have the operations console | lane 2 |
| `rasters:prewarm (RasterTilePrewarmCommand.php)` | Cache plumbing, not a human governance act (the exemption list names cache); the setup wizard already dispatches the identical job automatically, leaving the CLI as the manual/partial re-warm override. | lane 1 |
| `sim:pump (SimPumpCommand.php)` | Schedule internals — a human never invokes the pump and must not (a worker that can advance a phase can advance it twice); the human acts it serves (start/halt/resume) are sim:start's needs-ui row. | lane 4 |
| `Manual district drawing` | Exempt: the capability IS pointer-driven geometry over a live map — a terminal cannot draw; the filing half is already engine-shaped and falls to the generic engine:file door | lane 1 |
| `Journeys progress (step/unstep)` | Exempt: UI pedagogy state — marking a step done from a terminal is not the capability (the capability is doing the step on the surface that teaches it) | lane 6 |
| `G-ID device enrolment + attestation minting` | Exempt: the actor is the client DEVICE holding the key — a CLI equivalent would be a client app, not an operator door; node-level certs already have mesh:request-cert/mesh:cert-grant | lane 2 |
| `Matrix session services (call tokens, voice-reach, translate)` | Exempt: machine-minted session tokens for a live client — no human console act exists apart from the client consuming them; matrix:setup covers the operator half | lane 2 |
| `Operator account ↔ mesh identity link (Flow B)` | Exempt: the proof half already lives CLI-side on the possessing device; the completing half must execute inside the very browser session being linked | lane 2 |

## HAS A PAIR — verified, both doors named

| Capability | Counterpart |
|---|---|
| `apportionment:seed` | Setup wizard Step-1/2 Continue (activateStep1 fires apportionment) + autoscale sizing phase off Accept Map Data |
| `audit:verify` | POST /system/audit-chain/verify (AuditChainController::verify) |
| `districting:autoscale` | Accept Map Data & Continue (POST /api/jurisdictions/accept-maps creates/resumes AutoscaleRun + kicks pump) + Step-3 dashboard with halt/resume |
| `cluster:approve` | POST /federation/host/requests/{id}/approve (auth:operator) |
| `cluster:join` | POST /federation/cluster/join + the /setup/join screen (MirrorService::joinHost) |
| `cluster:keys:list` | /federation host block keys table (ClusterJoinKey listing, safe fields only) |
| `cluster:keys:mint` | POST /federation/host/keys (auth:operator; flash-only plaintext, same once-only contract) |
| `cluster:keys:revoke` | POST /federation/host/keys/revoke (auth:operator) |
| `cluster:leave` | POST /federation/cluster/leave |
| `cluster:reject` | POST /federation/host/requests/{id}/reject (auth:operator) |
| `cluster:request-adoption` | /setup/join keyless path (MirrorService::requestJoin via joinFromSetup; returns pending_host_approval) |
| `cluster:requests` | /federation host block pending-requests panel (mirror->pendingRequests with approve/reject buttons) |
| `dev:assume` | POST /dev/assume (same gate as middleware) |
| `dev:chamber-cast` | POST /dev/chamber/cast |
| `dev:clock-advance` | POST /dev/clock/advance (Demo flyout clock console) |
| `dev:clock-fire` | POST /dev/clock/fire/{timer} (Demo flyout clock console) |
| `dev:scenario` | GET /dev/scenario/state + POST /dev/scenario/{preset} (D5 scenario panel; probe advisory, seeder is the truth) |
| `elections:demo` | Demo flyout scenario presets 'election' + 'election-instant' (queue this exact command; guard stays server-side) |
| `federation:init` | ensureIdentity() during setup (SetupController) + the federation_enabled instant-tier knob on /operator/operations |
| `federation:peer:check` | POST /federation/mesh/probe (MeshProbeService — the G8b probe front door) |
| `federation:peer:discover` | POST /federation/mesh/discover (explicit GUI front door per G8b) |
| `federation:peer:handshake` | POST /federation/mesh/handshake |
| `federation:request-read-write` | POST /federation/cluster/request-read-write (auth:operator) |
| `federation:resume-join` | Re-submitting /setup/join (or the /federation cluster join panel) resumes; sync-progress poll watches |
| `geodata:scan` | POST /api/geodata/scan + /api/geodata/scan/status + flags list (the Jurisdiction Viewer flag queue) |
| `institutions:demo-lawmaking` | Demo flyout scenario preset 'lawmaking' |
| `mesh:doctor` | POST /federation/mesh/probe (named as mesh:doctor's GUI front door) |
| `mesh:gates` | /federation gates panel + /operator/console readiness (MeshGateService::evaluate on page load) |
| `mesh:role` | /operator/roles/{qualify,request,approve,revoke} + /federation/roles/* — one-for-one wrappers per the Phase 4 design |
| `transport:disable (TransportDisableCommand.php)` | FederationConsoleController::disableTransport (POST on the federation console) calls the identical TransportService::disableSelf. |
| `transport:list (TransportListCommand.php)` | FederationConsoleController::show renders transports via TransportService::selfEndpoints, and /operator/mesh (MeshConsoleController::mesh) shows the same registry. |
| `transport:register (TransportRegisterCommand.php)` | FederationConsoleController::registerTransport (POST with validated transport/address/priority) calls the identical TransportService::registerSelf. |
| `vacancy:declare (VacancyDeclareCommand.php)` | Legislature Oversight page — OversightController::declareVacancy (POST /legislatures/{legislature}/vacancies, F-LEG-036, gated by can.declareVacancy) plus VacancyCountback certify (F-ELB-004) / scheduleSpecial (F-ELB-001) for the downstream machine. |
| `Schema bootstrap (migrate from the wizard)` | php artisan migrate |
| `Map-data ETL start/control (step 2)` | the request.json control file consumed by the step-2 supervisor + fc_etl python pipeline entrypoints (documented runbook) |
| `Districting mass operations` | apportionment:seed --jurisdiction, districting:autoscale, autoscale:pump/revert/resize-repair |
| `Audit chain verification` | audit:verify (+ audit:reconcile) |
| `Cluster mirror join/leave + setup-time discovery` | cluster:join / cluster:leave / cluster:request-adoption / federation:resume-join / federation:peer:discover |
| `Host adoption console (invite keys + requests)` | cluster:keys:mint / cluster:keys:revoke / cluster:keys:list / cluster:approve / cluster:reject / cluster:requests |
| `Mesh peer discover/handshake/probe` | federation:peer:discover / federation:peer:handshake / mesh:doctor (+ federation:peer:check, mesh:reach, mesh:gates for reads) |
| `Mesh role + transport lifecycle` | mesh:role {list|roles|qualify|request|adopt|drop|approve|deliver|revoke} / transport:register / transport:disable / transport:list |
| `Dev persona/time/scenario/residency controls` | dev:assume / dev:clock-advance / dev:clock-fire / dev:chamber-cast / dev:scenario |

## Census notes (agents' summaries)

- Artisan inventory, first half (A–M by filename): 54 commands from app/Console/Commands. Verdicts: 29 has-pair, 20 needs-ui, 5 exempt, 0 needs-cli (every row here IS a CLI door). The cluster/mesh-role/peer-setup families are in excellent shape — G3c and Phase 4 deliberately built one-for-one GUI wrappers (/federation host block, /operator/roles), and the scenario-preset panel gives elections:demo and demo-lawmaking their UI door with GuardsSyntheticData traveling by construction. The debt clusters in three places: (1) federation lane 2 one-way doors — flip:export, sync:push, cold-sync, upgrade:consent (Meter C), seed-publish, directory:publish, cert request/grant, broker-failover lifecycle, mesh:reach, launch:assert-clean — mostly small buttons over existing services on /federation and /operator/*; (2) geodata/autoscale lane 1 — repairs-export/apply manifests, synthesize-remainders, and autoscale:revert (Step-3 has halt/resume but no rewind); (3) two demo seeders (federation:demo, matrix:demo) missing only a ScenarioPresetService entry each, and two engine drivers (institutions:provision, jurisdiction:activate) whose UI only watches. Exempts are all genuine plumbing: the pump (scheduled liveness root), two migration-style backfills/repairs (districts:backfill-stats, autoscale:resize-repair), a cache warmer (geojson:prewarm), and deploy provisioning (matrix:setup). Guards verified per row: GuardsSyntheticData on all demo seeders, DevTimeControlsEnabled shared refusal on all dev:* controls, operator-signer requirements on audit:reconcile and districting:autoscale, trusted-peer/pinned-key requirements across the mesh family.
- Artisan commands, second half (files N-Z alphabetically in app/Console/Commands: PhaseDDemo, PhaseEDemo, RasterTilePrewarm, SimPump, SimStart, SocialDemo, TransportDisable, TransportList, TransportRegister, TreasuryDemo, VacancyDeclare — 11 commands). Score: 4 has-pair (the three transport:* commands pair with FederationConsoleController registerTransport/disableTransport/show + /operator/mesh; vacancy:declare pairs with the Oversight page's F-LEG-036 declaration and the VacancyCountback flow), 5 needs-ui (the four demo seeders institutions:demo-d, institutions:demo-e, social:demo, institutions:demo-treasury — the /dev/*-kit pages are fixture-only harnesses, not seeders — plus sim:start, the known drive-half gap of the watch-only /simworld console), 2 exempt (rasters:prewarm is cache plumbing whose job the setup wizard already auto-dispatches; sim:pump is the everyMinute scheduled liveness root a human must never invoke). The dominant missing door is ONE Demo-flyout seed panel covering all four demo seeders plus Start/Resume/Halt on /simworld — in every case the GuardsSyntheticData refusal must travel to the UI server-side and be rendered verbatim, per the existing D2 refusal-sentence contract in PlaytestStateController.
- Swept all 1,213 lines of routes/web.php (plus verified the 68-command artisan inventory and spot-checked borderline controllers). THE SYSTEMIC FINDING: the single largest UI-only class — roughly 80 of the ~110 mutating web routes — is ConstitutionalEngine filings and engine actions (elections, candidacy, board console, legislature sessions/votes/bills/committees/oversight/referendums/emergency powers, petitions, residency, executive, organizations, judiciary, economy F-IND-022/023/024, square/halls/commons), and ONE generic `engine:file {form} {--actor=} {--payload=json}` command closes it all at once, because every guard already lives in the engine (role gates, phase windows, state guards, ConstitutionalViolation citations) and the audit chain can mark console origin — the CLI door is a second door to the SAME rails, not a second write path, so it does not violate the no-economy-write-API doctrine. Two engine-adjacent exceptions need bespoke help: citizen ballots need a client-side commitment/reveal helper (crypto handshake, not just a filing), and manual district drawing is exempt (pointer geometry; its commits are already engine filings). Outside the engine class, 11 genuine needs-cli gaps, all small: setup founding flow, map acceptance gate + review decisions, portable archive export/import, autoscale halt/resume, district plan lifecycle (copy/activate/delete), receipt self-audit, invite minting, support report intake (side observation: support reports have NO read/triage door on either surface), rw-petition deny, broker credential set, operator tuning knobs, dev board seat/unseat, and the translation review verdict. The federation/mesh/dev planes are in excellent shape — the operator consoles were explicitly built as one-for-one GUI front doors over cluster:*, federation:*, mesh:role, transport:*, and dev:* commands. Exempt with stated reasons: manual drawing (geometric), G-ID device keys (device-bound custody), Matrix session tokens (machine-minted), journeys progress (pedagogy state), operator-link Flow B (proof already CLI-side).
