# Delta Deliverables Master — delegation & orchestration ledger

Lane 7's standing artifact (scope ruling 2026-07-24: lane 7 does **not** touch the
constitution/template text — it finds, records, assigns, and feeds; template
updates belong to the operator, executed with the website chats). Sources:
[DELTA_INVENTORY.md](DELTA_INVENTORY.md) · [PHASE_LEDGER_A_TO_O.md](PHASE_LEDGER_A_TO_O.md).

> **⚠ AUDIT CORRECTION LAYER (2026-07-25).** The definitive code audit
> ([BUILT_INVENTORY.md](BUILT_INVENTORY.md) + [evidence](BUILT_INVENTORY_EVIDENCE.md)) overturned
> two phase statuses (I and K-2 = PARTIAL) and six "absent" claims. Matrix rows below carry the
> corrected statuses; per-lane audit addenda are at the top of §3. Registry truth: **108**
> canonical forms + 6 aliases (the earlier 104→109 correction was itself wrong).

## 1. Phase ↔ lane matrix

| Phase | Status | Lane coverage |
|---|---|---|
| A–G (foundation → adoption/G-ID) | ✅ built | No build lane needed. Surfaces: **lane 6** (UI parity). Deployment: **lane 2**. G's two rig gates: verification subtasks → **lane 2** 07-24 (the cloud HTTPS instance provides the secure context for browser-GPS testing, and a real cross-machine peer join against the cloud node = the G-V2-shaped proof); native Capacitor still waits for the device rig |
| **H** districting/planetary map | ✅ converged, residue | **Lane 1** ✓ (495-item review residue + analysis round + geodata pull engine) |
| **I** activation tiers + reach | ◐ **PARTIAL** (audit 07-25) | **Lane 3** — ASSIGNED 2026-07-24: Phase-I design absorbed into the institutions scaling plan; tiers ARE the provisioning dial. **Audit: the activation boot-gate half is already LIVE** (jurisdiction_activations + CLK-06 + cascade + WF-JUR-01 + `jurisdiction:activate`); lane 3 designs only the tier CURVE + the reach/legitimacy layer — [BUILT_INVENTORY §3](BUILT_INVENTORY.md) |
| **J** Coalition as organization | ◻ substrate-only (audit 07-25: deltas confirmed absent, scope exact) | **Lane 14** — ADDED 07-24. RE-TIMED by the standing order's own terms: J ships when the live game opens, and the live game now opens 2026-09-01 → the small additive build lands inside the 40-day window. Public side stays 8a/8b. **Audit landmines**: CgcIpRegisterService hard-rejects non-CGC dedication AND is source-scanned by CgcIpPublicDomainTest (extend the contract, don't just add columns); OrgRegistryService::register() can't set parent_organization_id (seeder sets it programmatically); F-LEG-028 cultural-institution path already built — [BUILT_INVENTORY §3](BUILT_INVENTORY.md) |
| K-1 civic square · K-3 Matrix mesh | ✅ built | No build lane. Surfaces: lane 6. Room-layer scaling: lane 3 |
| **K-2** education + achievements | ◐ **PARTIAL** (audit 07-25) | **Lane 15** — ADDED 07-24. Curriculum half starts now (The_Chart 549-label map + Topic_Knowledge). **Audit: the achievements half is ALREADY BUILT** (journeys engine + append-only achievements + code-registry catalog + badges + medal federation, shipped as v3-wiring Phase 3c) — the engine design starts FROM `JourneyService`, not greenfield; only the reach gauge/leaderboards wait on lane 3's Phase-I draft. Remaining build: graded questions/correct_keys + F-EDU forms + Learn-module packaging of the factions correction (correction copy already live in built surfaces) — [BUILT_INVENTORY §3](BUILT_INVENTORY.md) |
| **L** public finance | ◻ substrate-only (audit 07-25) | **Lane 13** — ADDED 07-24 (L+M one unit per operator ruling). Design-first now — it is next in the standing work order after H; code lands on the long arc. **Audit: start from the real substrate** — live stipend/currency PARAMS in Setup Step 1 (`civic_stipend_floor` etc. on the PROTECTED settings model + `LM-fiscal-civic-stipend.md` design), the Phase-D grants/appropriations stub (award/disburse = dead code, zero tests), the fee/`payment_required` proto-rail in the PROTECTED validator, live dual-door machinery, and **14 finished economy mockups in both v2 and v3** — [BUILT_INVENTORY §3](BUILT_INVENTORY.md) |
| **M** market economy | ◻ substrate-only (audit 07-25) | **Lane 13** (with L — never handled apart). **Audit: the M exit criterion's hardest link is BUILT** — labor hire → co-determination auto-trigger chain (F-IND-014 → labor_recurring contract → countersign → CLK-13/14 recompute), constitutionally pinned; `org_contracts` kind `commercial` already in schema |
| **N** full i18n + a11y + media | ◻ substrate-only (audit 07-25) | **Lane 5** = strings front-runner (extraction, catalogs, MT router, pilot langs). **Lanes 10/11** build the general media/dub machinery N later ports in-app. A11y slice → **folded into lane 6** 07-24. **Audit numbers**: 5 chrome locales live (en 111 keys vs 103 translated — 8-key drift, no CI anywhere: no .github at all); K-3 shipped the MT router's pin-tested plug seam (TranslationProvider/TranslationGate); `public_records.translations` read path + UI badge wired, write/backfill missing; 412 aria attrs across 91/177 Vue files; glossary 36 terms vs charter's 38 — [BUILT_INVENTORY §3](BUILT_INVENTORY.md) |
| **O** full-scale demo | ◻ substrate-only (audit 07-25) | **Lane 4** = design front-runner (the Attained instance). Formal gates H (done) / I (lane 3) / N (long arc) — design proceeds now. **Audit: scope narrower than chartered** — CI-2 (scale_demo → empty federation whitelist) already coded + constitutionally pinned in K-3; `*@demo.invalid` namespace in live seeder use; SANDBOX game mode end-to-end (a DIFFERENT axis from instance_class); the Standard half = Phase H's output. Novel scope = instance_class persistence + CoW overlay + populate engine — [BUILT_INVENTORY §3](BUILT_INVENTORY.md) |
| Cross-phase | — | **Lane 2** scoped cloud launch (earth.* Standard ≠ Phase O) · **Lane 7** this desk · lanes 8–12 content/ops |

### 1.1 Approved marching orders (operator, 2026-07-25 — the execution wave)

GO in parallel: **1** map repair (running) · **2** GitHub→Azure one-command bring-up
plan, fresh-box AND multibox (Pi cross-machine join PASSED; Azure environment = the
challenge, so the app can scale before the volunteer mesh matures) · **3**
user/social-infrastructure scaling plan · **4** simulated-workflow/data scaling plan
(parallel with 3) · **5** string-translation/storage scaling plan — **3/4/5 must
show live progress and ride lane 1's autoscale pull-engine pattern** (pump →
SKIP-LOCKED lease workers → halt flag → breaker → revert law) · **9/10/11/12**
build general flows + run ONE test flow each (real runs gated on **15**'s content;
10's manifest conventions feed 11/12; 11 studies the Learn-flyout education
embedding; 12 dry-run only) · **13** economic-engine AUDIT plan → operator
walkthrough · **15** CONTENT KEYSTONE: achievement libraries + education system
around the Learn tab (mixed App/Civic per page). WAITING: **6** (until the 1–5
scale wave lands) · **14** (lane 2's window + operator go). 8a/8b off-board:
current content-design refresh (8a) / theme change + refresh (8b) while template
and constitution copy updates wait on the operator's rounds. GO prompt pack:
PROMPTS.md "GO prompt pack 2026-07-25".

**Remaining unowned: none.** The 2026-07-24 additions closed the set — L+M → lane
13 · J → lane 14 · K-2 → lane 15 · a11y audit → lane 6 · rig-gate verification →
lane 2. Operator-parked (not lanes): D-08 App_Flows dispositions (lane 13 consumes
grants/fundraising/asset-registration as inputs). Exploration-doc originals exist
ONLY in the operator's archived chats (never committed to main; not in the personal
folder) — the charter distillations + memory summaries are the working sources;
optional enrichment = reopen archives and export into docs/plans/explorations/.

## 2. Deliverables ledger

| ID | Deliverable | Owner | Status | Trigger / notes |
|----|-------------|-------|--------|-----------------|
| D-01 | `docs/findings/FINDINGS_DIGEST.md` — zero-context plain-language feed (incl. "where the build stands" from the ledger + stale-site-claims section from Topic_Knowledge diff) | Lane 7 | pending draft | Next lane-7 build item; push = operator's update round |
| D-02 | `docs/findings/TEMPLATE_TEXT.md` — VERBATIM markdown rendering of the current template (no editorial changes) | Lane 7 (mechanical) | pending | Rides D-01's push |
| D-03 | Template text updates (de-factionization T-1, tie-break wording T-2, apportionment annex T-3, founding note T-4) | **OPERATOR** — with 8a/8b for site copy | with operator | Lane 7 supplies findings only (inventory §2); no amendments authorship in lane 7 |
| D-04 | `CGA_Architecture_Plan_2026-07.docx` updated COPY (skeleton = phase ledger; original untouched) | Lane 7 | pending | After D-01; operator swaps on review |
| D-05 | Roles/forms chart xlsx updated COPY (+5 forms, +Clocks sheet CLK-01..21, alias note; faction cells wait on D-03) | Lane 7 | pending | After D-04 |
| D-06 | CLAUDE.md citation verification vs the labeled docx's own label scheme | Lane 7 | pending | Before any citation edits |
| D-07 | Phase-I design (activation tiers + reach) inside the institutions scaling plan | **Lane 3** | assigned 07-24 | Update prompt §3 below |
| D-08 | App_Flows unbuilt-concept dispositions (family tree, grants, fundraising/fund-distribution, asset registration, endorse-policies) | **OPERATOR** | parked | Plausible L/M fold-ins; decide when L/M spawn |
| D-09 | `age_of_majority` amendable setting (Art. V §5 gap) | future L/M lane | logged | No 40-day impact; template exempts voting/standing from age |
| D-10 | The_Chart.drawio full label-level reconciliation (incl. faction-language sweep) | future K-2 pass | deferred | Census done; curriculum map confirmed |
| D-11 | Form-count + engine facts corrections in repo docs | Lane 7 | ✅ DONE, re-corrected 07-25 | `d663489` set 109 — the audit proved **108** canonical (103 Template + ELB-008 + SOC-001..004) + 6 aliases; CLAUDE.md fixed in the audit commit. F-LEG-020/021 deliberately handler-unregistered (consent votes ride chamber machinery) |
| D-12 | Phase ledger + status chart | Lane 7 | ✅ DONE `76a036f` | Feeds D-01/D-04 |
| D-13 | Update-prompt pack maintenance (§3 below mirrors into PROMPTS.md) | Lane 7 | continuous | Operator pastes into staged lanes |
| D-14 | `docs/plans/economy/ECONOMY_ENGINE_PLAN.md` — Phases L+M design (one unit) | **Lane 13** | assigned 07-24 | Design only until operator settles; build slot post-launch per work order |
| D-15 | Phase J build — Foundation + Coalition orgs, `public_domain_charter`, `demo-coalition` seeder, civil-society firewall pins | **Lane 14** | assigned 07-24 · seeding gate OPEN 07-25 | **Operator ruling 07-25 supersedes the charter's "two nonprofits" phrasing**: Cosmopolitan Party Foundation = the 501(c)(3), the only incorporated entity today (name verbatim); the Coalition is legally a PROJECT under the Foundation — `parent_organization_id` expresses exactly that; seed Cosmopolitan Coalition of United Earth as the operating/authoring child. "Cosmopolitan Coalition Action Fund" = future 501(c)(4), NOT incorporated, NOT seeded — plan records it as a future third org row. App stores no US tax categories (settled). Plus D-20 #6 build (M-5 console entry point) assigned here |
| D-16 | `docs/plans/education/K2_CURRICULUM.md` (The_Chart extraction, factions→polymorphic teaching correction) + `K2_ENGINE_PLAN.md` (achievements) | **Lane 15** | assigned 07-24 | Curriculum now; engine half gated on D-07 (lane 3's Phase-I draft) |
| D-17 | A11y audit dimension inside the parity punchlist (WCAG 2.2 AA basics per screen) | **Lane 6** | assigned 07-24 | Quick fixes ride parity waves; structural items flagged for Phase N; source: App Docs\accessibility_internationalization.md |
| D-18 | Rig-gate verification via the cloud instance (browser-GPS secure-context test + real cross-machine peer join) | **Lane 2** | ◐ half done 07-25 | **Cross-machine peer join PASSED via the operator's Pi test (2026-07-25)** — the G-V2-shaped proof is in; remaining: phone-browser GPS against the HTTPS cloud URL (needs lane 2's TLS) + native Capacitor (device-gated). Lane 2's challenge re-scoped by the operator: GitHub→Azure one-command bring-up, fresh-box AND multibox, so the app can scale before the volunteer mesh matures |
| D-19 | **Definitive built-vs-unbuilt audit** — `BUILT_INVENTORY.md` + `BUILT_INVENTORY_EVIDENCE.md` (14 agents, 34 absence claims re-hunted, 6 overturned; ledger + matrix corrected) | Lane 7 | ✅ DONE 07-25 | Operator cuts lanes 13+ prompts against it; §3 audit addenda below feed the staged lanes |
| D-20 | Defect follow-ups from the audit's risk register (GrantService dead code · BallotCrypto receipt review pre-launch · locale-key drift · earned_at coarse-DATE decision · M-5 entry point · "records are translated" copy promise) | per item — see note | logged 07-25, owners settled 07-25 | Register = [BUILT_INVENTORY §7](BUILT_INVENTORY.md). Owners: #1 GrantService → **lane 13** (recommended post-launch) · #2 BallotCrypto gate → **lane 2** checklist · #4 locale drift → **lane 5** (checker = first output) · #6 M-5/F-SOC-004 entry point → **K-3 false cognate, NOT lane 13** (lane 13 flag confirmed): launch-gate line = **lane 2**, build = **lane 14** (small in-window app-code) · #8 earned_at → **lane 15** (fix-before-launch recommended) · #12 translation-promise copy → **lane 5 pilots delivery, lane 6 softens on deadline rule** |

## 3. Update-prompt pack (paste into the staged lane chats)

### 3.0 AUDIT ADDENDA (2026-07-25) — paste the matching block to each lane

> **→ Lane 3 (institutions scaling / Phase I):** Audit correction (lane 7, 07-25): Phase I is
> HALF-BUILT. The activation boot-gate is live end-to-end (`jurisdiction_activations` state
> machine → CLK-06 every-minute sweep → `constitutional_settings` cascade with dev default 1 →
> WF-JUR-01 bootstrap → `jurisdiction:activate` → WI-9 status line on Jurisdictions/Show.vue);
> CLK-06 is already tagged `per_jurisdiction_tier` and the `Reach` nav surface is pre-registered
> (surfaces.js:102, href:null) against `mockups/v3/social/legitimacy.html`. Your design scope is
> ONLY: the tier CURVE (settings params + `ActivationTierService::tierThreshold =
> clamp(ceil(k·pop^⅓), floor, cap)` feeding the existing resolver seam) + the reach/legitimacy
> layer (`legitimacy_snapshots`, `LegitimacyService`, nightly job, k-anon suppression).
> **CORRECTION 2026-07-25 (lane 3 verified; this addendum was wrong):** the onOneServer +
> LeaderProbe pattern is the Patroni/HA scheduler-leader axis, NOT federation authority — CI-6
> requires a per-jurisdiction `authoritative_server_id IS NULL` filter (AuthorityResolver), since
> a mirror node runs its own scheduler and wins its own probe. Caution: `cubeRootSeats` is legislature
> SIZING, not the tier threshold — and its leaf ceiling-9 clamp was retired 07-19. Also note the
> live activation pipeline has ZERO automated tests — pin it as part of your work. Full detail:
> docs/plans/docs-recon/BUILT_INVENTORY.md §3.

> **→ Lane 13 (economy L+M):** Audit correction (lane 7, 07-25): read
> docs/plans/docs-recon/BUILT_INVENTORY.md §3 before outlining. Your design starts from real
> substrate, not zero: (1) the UBI/stipend PARAMETER layer is live (civic_stipend_floor,
> stipend_bump_cap, pay_* toggles, stipend_interval, currency_name/code/symbol — Setup Step 1 +
> PROTECTED ConstitutionalSettings + `docs/plans/phase-g-continuation/LM-fiscal-civic-stipend.md`);
> (2) the Phase-D grants/appropriations stub exists but `GrantService::award/decline/disburse/
> createAppropriation` are DEAD CODE (zero callers, zero tests — appropriations unpopulatable
> today; wiring them is your cheapest early win); (3) the labor→co-determination chain (M's exit
> criterion) is BUILT and pinned; (4) `org_contracts` kind `commercial` exists; (5) the Art. II §8
> no-paywall rail has a proto-form in the PROTECTED validator (`fee`/`payment_required` forbidden
> keys); (6) 14 finished economy mockup pages exist in BOTH mockups/v2/economy and v3/economy plus
> a live "Market · planned" nav section — design to those contracts. Fund-DISTRIBUTION exists
> (appropriations-by-act); only donation-intake fundraising is absent (App_Flows fold-in). D-09
> age_of_majority lands with you.

> **→ Lane 14 (Coalition J):** Audit confirmation (lane 7, 07-25): your scope is exactly as
> chartered — every J-specific delta confirmed absent. Landmines found:
> `CgcIpRegisterService::dedicate()` hard-rejects non-CGC orgs AND the service is source-scanned
> by `CgcIpPublicDomainTest` — your voluntary-dedication branch must extend that test's contract;
> `OrgRegistryService::register()` does not accept `parent_organization_id` (your seeder sets it
> programmatically; the column + model relations exist with zero test coverage — add pins);
> F-LEG-028 Cultural-Institution recognition is ALREADY BUILT (engine-reachable, no UI/tests);
> `foundation_sync_cursors` is federation plumbing, not a Foundation artifact.

> **→ Lane 15 (K-2):** Audit correction (lane 7, 07-25): the achievements half of K-2 is ALREADY
> BUILT — journeys engine (`JourneyService`, 13 arcs/10 live, `config/cga/journeys.php` as the
> code-registry catalog), `journey_progress` + append-only `achievements` (DB-trigger enforced),
> profile badges, and cross-instance medal federation (AchievementFederationTest). Shipped as
> v3-wiring Phase 3c. Your K2_ENGINE_PLAN starts FROM JourneyService, not greenfield; only the
> reach gauge/leaderboards wait on lane 3. Remaining engine scope: graded questions/correct_keys +
> server grading + F-EDU forms. The factions→polymorphic correction COPY is already live across
> built surfaces (OrgDetail banner, Committees, constitutional-questions ledger items 1+6) — your
> job is packaging it as a Learn module + flagging the Coalition's external STV materials. Two
> fidelity decisions to surface early: journey steps are self-reported ticks (not verified acts),
> and `earned_at` is a full federating timestamptz where the charter wanted coarse DATE — cheap to
> fix before real medals exist. Full detail: docs/plans/docs-recon/BUILT_INVENTORY.md §3.

> **→ Lane 5 (translation scaling):** Audit numbers (lane 7, 07-25): en.json = 111 keys but all
> four translations = 103 (8-key drift already, no CI to catch it — and the repo has NO .github at
> all, so any CI gate includes standing up CI). The per-namespace catalog dir the loader globs
> does not exist yet. The MT router's plug seam is ALREADY BUILT and pin-tested in K-3
> (`app/Services/Matrix/Translation/` — TranslationProvider/TranslationGate; comment says "the
> full hybrid router is Phase N") — build INTO that seam, don't invent one. `scripts/etl/
> languages.py` is a geodata artifact, NOT the locale registry (same filename as the charter's
> planned registry — don't check it off). Glossary is 36 terms vs the charter's 38 — reconcile.
> `public_records.translations` jsonb has a fully wired read path + UI badge; the write/backfill
> side is the WF-SYS-03 gap. Registration UI already PROMISES per-user record translation —
> deliver minimally or flag the copy to lane 6.

> **→ Lane 6 (UI parity + a11y):** Audit facts (lane 7, 07-25): your a11y baseline is real — 412
> aria- attributes across 91/177 Vue files + a WCAG 4.1.3 live-region composable (useAnnounce.js);
> your audit is close-the-gaps, not from-zero. Two shells coexist (AppShell.vue + AppShellV2.vue)
> — consolidation candidate. "Records are translated per your selection" (Register.vue,
> MyRecord.vue) promises an undelivered pipeline — soften or flag. The Grants & appropriations
> card on Executive/Actions.vue renders empty on demo data (GrantService award/disburse dead code).

> **→ Lane 4 (sim world / demo design):** Audit scope-narrowing (lane 7, 07-25): CI-2 (scale_demo
> → empty federation whitelist) is ALREADY enforced + constitutionally pinned
> (MatrixFederationGateService + MatrixFederationWhitelistTest); `*@demo.invalid` is in live
> seeder use; the SANDBOX `game_mode` axis exists end-to-end but is a DIFFERENT axis from
> `instance_class` (don't merge them); dormant `time_mode`/`time_scale_seconds_per_year` columns
> exist unconsumed. Your genuinely novel design surface: instance_class persistence + boot
> assertion, demo_sessions/demo_overlays CoW, demo_generation_runs + DemoPopulateService.

> **→ Lane 2 (cloud launch):** Audit items for your checklist (lane 7, 07-25): (1) BallotCrypto's
> own docblock flags the {ballot_hash, salt} receipt as a vote-selling channel and names a
> cryptographer review as a PRODUCTION GATE — that's a launch-checklist line now; (2) the 21-clock
> registry does NOT ride in the schema dump — a bare `php artisan migrate` leaves clocks empty
> until ClockRegistrySeeder runs (deploy scripts handle it; your runbook must); (3) CLAUDE.md's
> Docker table lists 7 services but both stacks run 10 (Synapse, MAS, scheduler); (4) game box
> setup wizard is parked at step 3 — planet accepted, founding unfinished.



**→ Lane 1 (GeoData & District Maps)** — context only, no order change:
> Ledger note (lane 7, 2026-07-24): Phase H is recorded CONVERGED; your remaining
> scope (495 review items + analysis round + geodata pull engine) is the official
> residue. Reference: docs/plans/docs-recon/PHASE_LEDGER_A_TO_O.md. No change to
> your orders.

**→ Lane 2 (Cloud Launch – Multibox):**
> Delta update (lane 7, 2026-07-24): read docs/plans/docs-recon/DELTA_INVENTORY.md
> + PHASE_LEDGER_A_TO_O.md before finalizing the launch plan. Binding
> clarifications: (1) the 2026-09-01 launch = the earth.* "Standard" instance —
> real consent only, dormant institution scaffolding, ZERO synthetic data; it is
> NOT Phase O. (2) Capacitor/mobile is unbuilt (G-V1 parked) — out of scope; the
> old architecture doc's ActivityPub federation section is superseded by the built
> mesh (FF&C, authority flip, mesh roles). (3) Institution provisioning at launch
> keys off the activation-tier dial — lane 3 owns that design (charter Phase I);
> consume their output as a launch input, don't derive your own.

**→ Lane 3 (Institution Scaling)** — scope addition:
> Scope addition (operator-approved, 2026-07-24): your plan ABSORBS Phase I design
> — activation tiers + the reach denominator. Authority:
> docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md §Phase I — tier gates when a
> government may BOOT (never the franchise); threshold = clamp(ceil(k·pop^(1/3)),
> floor, cap) stored as ONE amendable settings row at planet root;
> legitimacy_snapshots = k-anon reach ratio (verified residents ÷ population);
> zero new forms/clocks. Reconcile with the LIVE sizing law max(5, round(pop^(1/3)))
> ceiling-9-leaves-only (see memory, exploration correction 2026-07-07). Institution
> catalog + creation order: docs/extracted/roles_forms_chart.md sheets 1/2/4/5.
> Express your eager-vs-lazy recommendation IN TERMS OF tiers: what provisions at
> which tier, for both the live Standard instance and the sim world.

**→ Lane 4 (Simulated World Engine):**
> Delta update (lane 7, 2026-07-24): you are Phase O's design front-runner. Read
> the charter's §Phase O first (docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md) — the
> settled frame is all there: two instances (earth.* Standard / earth-demo.*
> Attained), instance_class='scale_demo' FORCES federation off, DemoPopulateService
> drives engine statics (demo math == engine math), demo_sessions/demo_overlays
> copy-on-write sandbox (read-only demo = MVP fallback), deterministic seeds
> hash(jurisdiction_id)+version, synthetic namespace *@demo.invalid. Plus
> docs/plans/docs-recon/PHASE_LEDGER_A_TO_O.md. O is formally gated on H (done),
> I (lane 3, in design), N (long arc) — your design proceeds now; include as an
> operator option which parts could demo pre-N (an English-only Attained).

**→ Lane 5 (Translation Scaling):**
> Delta update (lane 7, 2026-07-24): you are Phase N's front-runner for the string
> layer. Design references in the charter's §Phase N
> (docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md): scripts/i18n extract+check CI-gate
> pattern; ONE languages registry generating both config/locales.php and the JS
> registry (kills PHP↔JS drift); TranslationProvider router (local-NLLB tail /
> Haiku tier-1 / human for constitutional namespaces); the 38 glossary terms + all
> R-/WF-/F-/CLK ID tokens byte-identical in every locale. Known baseline: ~90% of
> body copy across ~64 pages / ~48 components is hardcoded — your extraction audit
> quantifies exactly that. WCAG/a11y is in Phase N but NOT in your lane — leave it
> flagged unowned. Ledger: docs/plans/docs-recon/PHASE_LEDGER_A_TO_O.md.

**→ Lane 6 (UI Design Implementation)** — context only:
> Note (lane 7, 2026-07-24): delta inventory + phase ledger at
> docs/plans/docs-recon/. Your parity pass covers built surfaces only (A–H, K-1,
> K-3). The Learn-layer curriculum source (The_Chart.drawio) belongs to unbuilt
> K-2 — out of your scope. No other change.

**→ Lane 11 (Video Translation)** — context only:
> Note: your pipeline is the forerunner of the app's Phase N media layer (the
> in-app MultiTrackPlayer port). No change to your orders — just know your
> conventions will be ported, so keep {Subject}-{Language} naming exact.

**→ 8a/8b (website chats)** — no update now; they act on the digest push (D-01/D-02)
when the operator calls the round. The template-copy work (D-03) runs under the
operator's direction.

**→ Lane 2 — second addendum (rig gates, D-18):**
> Scope note (lane 7, 2026-07-24): your cloud instance doubles as the Phase-G
> rig-gate advance. Once HTTPS is live: (1) browser geolocation finally has its
> secure context — schedule a phone-browser GPS ping test against the cloud URL
> (G-V1's blocker was LAN-HTTP; the native Capacitor wrap still waits for the
> device rig); (2) a real cross-machine peer join against the cloud node = the
> G-V2-shaped proof (fresh box → deploy script → join over the real network). Put
> both in the launch plan's soak/verification phase.

**→ Lane 6 — second addendum (a11y audit, D-17):**
> Scope addition (lane 7, 2026-07-24): your parity tour ADDS an accessibility
> audit dimension — while touring each screen, record WCAG 2.2 AA basics
> (keyboard nav, focus order, contrast, labels/alt text, touch targets at 375) as
> a column in the punchlist. Quick fixes ride your parity waves; structural items
> get flagged for Phase N. Early source doc:
> E:\fair-constitution-personal\App Docs\accessibility_internationalization.md.
> The full WCAG certification pass remains Phase N — you are the audit
> front-runner, not the certifier.

### New-lane opening prompts — v2, audit-integrated (2026-07-25; open in E:\fair-constitution-app)

*(v1 prompts of 2026-07-24 superseded — they predated the code audit and the fleet
board. These are the canonical openers; PROMPTS.md mirrors them.)*

**→ Lane 13 — Economy Engine (Phases L+M, one unit):**
> NEW LANE — ECONOMY ENGINE (Phases L + M as ONE unit — operator ruling: never
> handled apart). Design-first: NO code, NO migrations until the operator settles
> the plan. FIRST ACTION: read E:\fair-constitution-personal\parallel-chats\fleet\
> README.md (the fleet-board standing order binds you), sweep the board starting
> with lane-07's broadcast, claim fleet\lane-13.md (fill the STATUS block).
>
> Required reading, in order: docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md §Phase L
> + §Phase M (the spec: theses, schema, forms, hard rails, exit criteria) ·
> docs/plans/docs-recon/BUILT_INVENTORY.md §3 (Phases L+M) — the 2026-07-25 code
> audit's substrate list · DELTA_DELIVERABLES_MASTER.md §3.0 lane-13 addendum +
> §2 (you are D-14, D-09, D-20 items).
>
> THE AUDIT CHANGED YOUR STARTING POINT — you do not design from zero: (1) the
> UBI/stipend PARAMETER layer is live (civic_stipend_floor, stipend_bump_cap,
> pay_* toggles, stipend_interval, currency_name/code/symbol — Setup Step 1 → the
> PROTECTED ConstitutionalSettings model; design doc
> docs/plans/phase-g-continuation/LM-fiscal-civic-stipend.md); (2) the Phase-D
> grants/appropriations stub exists but GrantService::award/decline/disburse/
> createAppropriation are DEAD CODE — zero callers, zero tests; wiring them is
> your cheapest early win; (3) the labor→co-determination chain (M's exit
> criterion's hardest link) is BUILT and constitutionally pinned; (4)
> org_contracts kind 'commercial' is in schema with the cosign constraint; (5) an
> Art. II §8 proto-rail exists (fee/payment_required in the PROTECTED validator's
> FORBIDDEN_ELIGIBILITY_KEYS) — NO_FEE_FORMS generalizes it; (6) the dual-door
> machinery is live — monetary levers are configuration on tested rails; (7) the
> UI is designed: 14 economy pages in BOTH mockups/v2/economy and v3/economy plus
> a live "Market · planned" nav section — design to those contracts.
>
> Deliverable: docs/plans/economy/ECONOMY_ENGINE_PLAN.md — (a) fiscal layer:
> revenue/levies (filings private), budgets → the existing appropriations rows,
> double-entry hash-chained public ledger (LedgerService sole writer), borrowings,
> currency RESERVED to root (Art. V §5); (b) market layer: labor board feeding
> co-determination, marketplace, mutual aid, UBI — eligibility = active residency
> ONLY, public aggregate + private receipts, never federated; (c) hard rails as
> pins: no paywall on civic rights, monetary levers dual-door only (F-LEG-031),
> FORBIDDEN_SUBJECT_TYPES additions; (d) App_Flows dispositions — CORRECTED by the
> audit: grants = half-built (finish, don't invent), fund-DISTRIBUTION exists
> (only donation-intake fundraising is absent — dispose of that half), asset
> registration = truly absent (fold-in or retire, with reasoning); (e) form plan
> F-LEG-037..040, F-TRE-001..004, F-IND-018..023, F-ORG-008; (f) the
> age_of_majority/age_of_consent settings gap (D-09) lands with you. Build slot is
> post-launch per the standing work order — design runs parallel now. Lane owns
> docs/plans/economy/ only. Post readiness to the board and stand by for the
> operator's go.

**→ Lane 14 — Coalition Organization (Phase J build):**
> NEW LANE — COALITION ORGANIZATION (Phase J). Re-timed by the standing order's
> own terms: J ships when the live game opens = 2026-09-01, so this small additive
> build lands INSIDE the 40-day window, coordinated with lane 2's launch plan.
> FIRST ACTION: read E:\fair-constitution-personal\parallel-chats\fleet\README.md
> (fleet-board standing order binds you), sweep the board starting with lane-07's
> broadcast, claim fleet\lane-14.md (fill the STATUS block).
>
> Required reading: docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md §Phase J ·
> docs/plans/docs-recon/BUILT_INVENTORY.md §3 (Phase J) ·
> DELTA_DELIVERABLES_MASTER.md §3.0 lane-14 addendum (you are D-15).
>
> Scope (audit-confirmed exactly as chartered — every J delta verified absent):
> two nonprofits — Cosmopolitan Party Foundation (legal parent) + Cosmopolitan
> Coalition of United Earth (operating/authoring child) — type='nonprofit',
> linked by organizations.parent_organization_id (column EXISTS; zero test
> coverage — add pins), registered at Earth (ADM0);
> organizations.public_domain_charter (ONE-WAY false→true) +
> cgc_ip_register.dedication_basis (constitutional_mandate|voluntary_charter);
> optional org_memberships.is_public; institutions:demo-coalition seeder;
> civil-society firewall pins (Article-I levers only, zero Leg/Exec/Jud/CGC
> power); the CGC Art. III §5 branch stays byte-identical. The Δ4 authorship
> bridge is OWNED BY K/N — do not create it.
>
> AUDIT LANDMINES (read before writing a line): CgcIpRegisterService::dedicate()
> hard-rejects non-CGC orgs AND the service is source-scanned by
> CgcIpPublicDomainTest — your voluntary-dedication branch must EXTEND that
> test's contract, never weaken it; OrgRegistryService::register() cannot set
> parent_organization_id — your seeder sets it programmatically; F-LEG-028
> Cultural-Institution recognition (your Art. V grounding) is ALREADY BUILT;
> foundation_sync_cursors is federation plumbing, NOT a Foundation artifact.
>
> Exit: institutions:demo-coalition --fresh seeds both nonprofits at Earth with a
> member-elected co-determined board and a public-domain corpus, firewall pins
> green. Sequence: plan first (docs/plans/coalition/PHASE_J_PLAN.md), build on
> operator go; migrations through the one-lane-at-a-time rule; these are the same
> two nonprofits the 8a/8b websites represent — verify naming/details with the
> operator before seeding. Post readiness to the board and stand by.

**→ Lane 15 — Civic Education & Achievements (Phase K-2):**
> NEW LANE — CIVIC EDUCATION & ACHIEVEMENTS (Phase K-2). FIRST ACTION: read
> E:\fair-constitution-personal\parallel-chats\fleet\README.md (fleet-board
> standing order binds you), sweep the board starting with lane-07's broadcast,
> claim fleet\lane-15.md (fill the STATUS block).
>
> Required reading: docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md §Phase K (the
> K-2 slice) · docs/plans/docs-recon/BUILT_INVENTORY.md §3 (Phase K-2) —
> THE 2026-07-25 AUDIT REWROTE YOUR PREMISE · DELTA_DELIVERABLES_MASTER.md §3.0
> lane-15 addendum (you are D-16, plus two D-20 items).
>
> THE PREMISE CHANGE: the achievements half of K-2 is ALREADY BUILT — the journeys
> engine (JourneyService, 13 arcs/10 live, config/cga/journeys.php as the
> charter's code-registry catalog), journey_progress + append-only achievements
> (DB-trigger enforced), profile badges, and cross-instance medal federation
> (AchievementFederationTest). It shipped as mockups-v3-wiring Phase 3c and was
> never attributed to K-2. Your engine plan therefore starts FROM JourneyService,
> not greenfield, and does NOT wait on lane 3 — only the reach gauge +
> jurisdiction-only leaderboards wait on Phase I.
>
> HALF 1 — CURRICULUM (start now): The_Chart.drawio = the curriculum map — 549
> labels decomposing A Fair Constitution into Units → Lessons → Chapters with
> weights (python docs/extract_docs.py → docs/extracted/the_chart.xml).
> Deliverable: docs/plans/education/K2_CURRICULUM.md — structured extraction,
> reconciled against the as-built app; cross-reference docs/Topic_Knowledge.xlsx
> transcripts for existing video lessons. The factions→polymorphic teaching
> correction: the correction COPY is already live across built surfaces (OrgDetail
> banner, Committees, constitutional-questions items 1+6) — your job is packaging
> it as a Learn module + flagging the Coalition's external STV materials for the
> operator's site chats.
>
> HALF 2 — EDUCATION ENGINE plan (start now, from the as-built): deliverable
> docs/plans/education/K2_ENGINE_PLAN.md — the graded half that's genuinely
> missing: education questions/correct_keys + server-side grading (correct_keys
> NEVER serialized to client; progress NEVER federates — the journeys posture
> already models this), F-EDU forms, integration with JourneyService rather than
> replacement. Iron rails: NO governance advantage, NO per-person composite
> score, NO individual leaderboards, participation from the envelope not the
> ballot. SURFACE EARLY to the operator (via the board): two as-built fidelity
> gaps — journey steps are self-reported ticks, not verified acts; and
> achievements.earned_at is a full federating timestamptz where the charter's
> privacy rail wanted a coarse DATE (cheap to fix before real medals exist).
> Build slot post-launch per work order. Lane owns docs/plans/education/ only; no
> code until settle. Post readiness to the board and stand by.

## 4. Maintenance contract

Lane 7 updates this file when: a lane reports a deliverable done (flip D-status) ·
the operator assigns/spawns/retires a lane (matrix row) · a new delta surfaces
(new D-row + prompt if needed). The PROMPTS.md §7 prompt and the fleet memory point
here.
