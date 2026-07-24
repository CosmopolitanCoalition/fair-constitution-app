# Delta Deliverables Master — delegation & orchestration ledger

Lane 7's standing artifact (scope ruling 2026-07-24: lane 7 does **not** touch the
constitution/template text — it finds, records, assigns, and feeds; template
updates belong to the operator, executed with the website chats). Sources:
[DELTA_INVENTORY.md](DELTA_INVENTORY.md) · [PHASE_LEDGER_A_TO_O.md](PHASE_LEDGER_A_TO_O.md).

## 1. Phase ↔ lane matrix

| Phase | Status | Lane coverage |
|---|---|---|
| A–G (foundation → adoption/G-ID) | ✅ built | No build lane needed. Surfaces: **lane 6** (UI parity). Deployment: **lane 2**. G's two rig gates (G-V1 mobile, G-V2 cross-machine) = operator's physical-rig backlog, out of 40-day scope |
| **H** districting/planetary map | ✅ converged, residue | **Lane 1** ✓ (495-item review residue + analysis round + geodata pull engine) |
| **I** activation tiers + reach | ◻ unbuilt | **Lane 3** — ASSIGNED 2026-07-24 (was unowned): Phase-I design absorbed into the institutions scaling plan; tiers ARE the provisioning dial |
| **J** Coalition as organization | ◻ unbuilt | **No lane — intentional** (work order ships J at live-game opening). Public-facing side already covered by 8a/8b site chats (same two nonprofits) |
| K-1 civic square · K-3 Matrix mesh | ✅ built | No build lane. Surfaces: lane 6. Room-layer scaling: lane 3 |
| **K-2** education + achievements | ◻ unbuilt | **NO LANE** — post-launch by work order. Content sources already staged: The_Chart.drawio (curriculum map) + Topic_Knowledge.xlsx + J's authorship |
| **L** public finance | ◻ unbuilt | **NO LANE** — long arc (L+M one unit, post-launch per work order) |
| **M** market economy | ◻ unbuilt | **NO LANE** — long arc (with L) |
| **N** full i18n + a11y + media | ◻ unbuilt | **Lane 5** = strings front-runner (extraction, catalogs, MT router, pilot langs). **Lanes 10/11** build the general media/dub machinery N later ports in-app. **A11y slice (WCAG 2.2 AA) = UNOWNED** — flagged |
| **O** full-scale demo | ◻ unbuilt | **Lane 4** = design front-runner (the Attained instance). Formal gates H (done) / I (lane 3) / N (long arc) — design proceeds now |
| Cross-phase | — | **Lane 2** scoped cloud launch (earth.* Standard ≠ Phase O) · **Lane 7** this desk · lanes 8–12 content/ops |

**Unowned items (operator's call whether/when to spawn):** K-2 · L · M · N's a11y
slice · G's rig gates. All are post-launch by the standing work order — no 40-day
impact.

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

## 4. Maintenance contract

Lane 7 updates this file when: a lane reports a deliverable done (flip D-status) ·
the operator assigns/spawns/retires a lane (matrix row) · a new delta surfaces
(new D-row + prompt if needed). The PROMPTS.md §7 prompt and the fleet memory point
here.
