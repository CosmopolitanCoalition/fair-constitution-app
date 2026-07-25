# Delta Deliverables Master — delegation & orchestration ledger

Lane 7's standing artifact (scope ruling 2026-07-24: lane 7 does **not** touch the
constitution/template text — it finds, records, assigns, and feeds; template
updates belong to the operator, executed with the website chats). Sources:
[DELTA_INVENTORY.md](DELTA_INVENTORY.md) · [PHASE_LEDGER_A_TO_O.md](PHASE_LEDGER_A_TO_O.md).

## 1. Phase ↔ lane matrix

| Phase | Status | Lane coverage |
|---|---|---|
| A–G (foundation → adoption/G-ID) | ✅ built | No build lane needed. Surfaces: **lane 6** (UI parity). Deployment: **lane 2**. G's two rig gates: verification subtasks → **lane 2** 07-24 (the cloud HTTPS instance provides the secure context for browser-GPS testing, and a real cross-machine peer join against the cloud node = the G-V2-shaped proof); native Capacitor still waits for the device rig |
| **H** districting/planetary map | ✅ converged, residue | **Lane 1** ✓ (495-item review residue + analysis round + geodata pull engine) |
| **I** activation tiers + reach | ◻ unbuilt | **Lane 3** — ASSIGNED 2026-07-24 (was unowned): Phase-I design absorbed into the institutions scaling plan; tiers ARE the provisioning dial |
| **J** Coalition as organization | ◻ unbuilt | **Lane 14** — ADDED 07-24. RE-TIMED by the standing order's own terms: J ships when the live game opens, and the live game now opens 2026-09-01 → the small additive build lands inside the 40-day window. Public side stays 8a/8b |
| K-1 civic square · K-3 Matrix mesh | ✅ built | No build lane. Surfaces: lane 6. Room-layer scaling: lane 3 |
| **K-2** education + achievements | ◻ unbuilt | **Lane 15** — ADDED 07-24. Curriculum half starts now (The_Chart 549-label map + Topic_Knowledge); achievements/engine half waits on lane 3's Phase-I draft; code lands post-launch per work order |
| **L** public finance | ◻ unbuilt | **Lane 13** — ADDED 07-24 (L+M one unit per operator ruling). Design-first now — it is next in the standing work order after H; code lands on the long arc |
| **M** market economy | ◻ unbuilt | **Lane 13** (with L — never handled apart) |
| **N** full i18n + a11y + media | ◻ unbuilt | **Lane 5** = strings front-runner (extraction, catalogs, MT router, pilot langs). **Lanes 10/11** build the general media/dub machinery N later ports in-app. A11y slice → **folded into lane 6** 07-24 (audit rides the parity tour; full WCAG certification stays Phase N) |
| **O** full-scale demo | ◻ unbuilt | **Lane 4** = design front-runner (the Attained instance). Formal gates H (done) / I (lane 3) / N (long arc) — design proceeds now |
| Cross-phase | — | **Lane 2** scoped cloud launch (earth.* Standard ≠ Phase O) · **Lane 7** this desk · lanes 8–12 content/ops |

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
| D-11 | Form-count + engine facts corrections in repo docs | Lane 7 | ✅ DONE `d663489` | CLAUDE.md 104→109 |
| D-12 | Phase ledger + status chart | Lane 7 | ✅ DONE `76a036f` | Feeds D-01/D-04 |
| D-13 | Update-prompt pack maintenance (§3 below mirrors into PROMPTS.md) | Lane 7 | continuous | Operator pastes into staged lanes |
| D-14 | `docs/plans/economy/ECONOMY_ENGINE_PLAN.md` — Phases L+M design (one unit) | **Lane 13** | assigned 07-24 | Design only until operator settles; build slot post-launch per work order |
| D-15 | Phase J build — two nonprofits, `public_domain_charter`, `demo-coalition` seeder, civil-society firewall pins | **Lane 14** | assigned 07-24 | Ships by live-game opening (2026-09-01); plan first, migrations via the one-lane rule; org names/details verified with operator before seeding |
| D-16 | `docs/plans/education/K2_CURRICULUM.md` (The_Chart extraction, factions→polymorphic teaching correction) + `K2_ENGINE_PLAN.md` (achievements) | **Lane 15** | assigned 07-24 | Curriculum now; engine half gated on D-07 (lane 3's Phase-I draft) |
| D-17 | A11y audit dimension inside the parity punchlist (WCAG 2.2 AA basics per screen) | **Lane 6** | assigned 07-24 | Quick fixes ride parity waves; structural items flagged for Phase N; source: App Docs\accessibility_internationalization.md |
| D-18 | Rig-gate verification via the cloud instance (browser-GPS secure-context test + real cross-machine peer join) | **Lane 2** | assigned 07-24 | In the launch plan's soak phase; native Capacitor stays device-gated |

## 3. Update-prompt pack (paste into the staged lane chats)

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

### New-lane opening prompts (added 2026-07-24; open in E:\fair-constitution-app)

**→ Lane 13 — Economy Engine (Phases L+M, one unit):**
> NEW LANE — ECONOMY ENGINE (Phases L + M as ONE unit — operator ruling: never
> handled apart). Design-first: NO code, NO migrations until the operator settles
> the plan. Authority: docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md §Phase L +
> §Phase M (theses, schema, forms, hard rails, exit criteria — the original
> exploration doc is gone; the charter + memory recall are your sources). Also
> read docs/plans/docs-recon/PHASE_LEDGER_A_TO_O.md + DELTA_DELIVERABLES_MASTER.md
> (you are D-14).
>
> Deliverable: docs/plans/economy/ECONOMY_ENGINE_PLAN.md — (a) the fiscal layer:
> revenue/levies (filings private), budgets → the existing Phase-D appropriations
> substrate, double-entry hash-chained public ledger (LedgerService sole writer),
> borrowings, currency RESERVED to the root jurisdiction (Art. V §5); (b) the
> market layer: labor board feeding co-determination, marketplace, mutual aid,
> UBI — eligibility = active residency ONLY, public aggregate + private receipts,
> never federated; (c) hard rails as pins: no paywall on civic rights (Art. II
> §8), monetary levers legislative-only dual-door (F-LEG-031), private-local
> FORBIDDEN_SUBJECT_TYPES additions; (d) dispositions for App_Flows' unbuilt
> concepts (grants, fundraising/fund-distribution, asset registration — census in
> the phase ledger): fold-in or retire, per concept, with reasoning; (e) the form
> plan F-LEG-037..040, F-TRE-001..004, F-IND-018..023, F-ORG-008. Build slot is
> post-launch per the standing work order — your design runs parallel now. Lane
> owns docs/plans/economy/ only.

**→ Lane 14 — Coalition Organization (Phase J build):**
> NEW LANE — COALITION ORGANIZATION (Phase J). Re-timed by the standing order's
> own terms: J ships when the live game opens, and the live game now opens
> 2026-09-01 — this small additive build lands INSIDE the 40-day window,
> coordinated with lane 2's launch plan. Authority:
> docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md §Phase J + memory (coalition org
> structure) + docs/plans/docs-recon/DELTA_DELIVERABLES_MASTER.md (you are D-15).
>
> Scope (small by design, rides the built Phase-D org module): two nonprofits —
> Cosmopolitan Party Foundation (legal parent) + Cosmopolitan Coalition of United
> Earth (operating/authoring child) — type='nonprofit', linked by
> organizations.parent_organization_id, registered at Earth (ADM0);
> organizations.public_domain_charter (ONE-WAY false→true) +
> cgc_ip_register.dedication_basis (constitutional_mandate|voluntary_charter);
> optional org_memberships.is_public. The Δ4 authorship bridge is OWNED BY K/N —
> do not create it. Strict civil-society firewall: Article-I levers only, zero
> Leg/Exec/Jud/CGC power — pin it; the CGC Art. III §5 branch stays
> byte-identical. Exit: institutions:demo-coalition --fresh seeds both nonprofits
> at Earth with a member-elected co-determined board and a public-domain corpus,
> firewall pins green. Sequence: plan first
> (docs/plans/coalition/PHASE_J_PLAN.md), build on operator go; migrations
> coordinate through the one-lane-at-a-time rule. These are the same two
> nonprofits the 8a/8b websites represent — verify naming/details with the
> operator before seeding.

**→ Lane 15 — Civic Education & Achievements (Phase K-2):**
> NEW LANE — CIVIC EDUCATION & ACHIEVEMENTS (Phase K-2). Two halves on different
> clocks. Authority: docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md §Phase K +
> docs/plans/docs-recon/PHASE_LEDGER_A_TO_O.md + DELTA_DELIVERABLES_MASTER.md
> (you are D-16).
>
> HALF 1 — CURRICULUM (start now): The_Chart.drawio is the curriculum map — 549
> labels decomposing A Fair Constitution into Units → Lessons → Chapters with
> weight percentages (python docs/extract_docs.py → docs/extracted/the_chart.xml).
> Deliverable: docs/plans/education/K2_CURRICULUM.md — the structured extraction
> (section → unit → lesson → chapter, weights preserved), reconciled against the
> as-built app. The factions→polymorphic teaching correction is a NAMED work
> item: the STV teaching materials predate open endorsements — keep factions as a
> labeled teaching device + add a how-endorsements-actually-work module.
> Cross-reference docs/Topic_Knowledge.xlsx transcripts for existing video
> lessons per subject.
>
> HALF 2 — ACHIEVEMENTS + education engine design (starts when lane 3's Phase-I
> draft exists — achievements model on the legitimacy/reach gauge): deliverable
> docs/plans/education/K2_ENGINE_PLAN.md per charter §K — education
> tracks/modules/questions/progress (progress NEVER federates; correct_keys never
> serialized to client), achievements append-only + AchievementCatalog as a code
> registry, iron rails: NO governance advantage, NO per-person composite score,
> NO individual leaderboards, participation measured from the envelope not the
> ballot. Build slot post-launch per work order. Lane owns docs/plans/education/
> only; no code until settle.

## 4. Maintenance contract

Lane 7 updates this file when: a lane reports a deliverable done (flip D-status) ·
the operator assigns/spawns/retires a lane (matrix row) · a new delta surfaces
(new D-row + prompt if needed). The PROMPTS.md §7 prompt and the fleet memory point
here.
