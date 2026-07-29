# Wave 2 queue — inputs accumulated during Wave 1

*Desk file. Items land here as Wave 1 surfaces them; each gets folded into the owning lane's
Wave 2 marching order. Nothing here is an order yet.*

## For lane 2 (G) — federation, from lane 4's D7 build (2026-07-29)

1. **Handshake identity payload should carry `game_mode`** — the refined peer rail (ruling 4)
   reads `metadata->>'game_mode'`, and a peer counts as demo ONLY when its signed handshake
   declared it. One payload field lights up sandbox dev meshes (Pi-style multibox playtests).
2. **Demo-mesh time advance needs ONE coordinating node** — per-node advances skew shared
   deadlines. Recorded in the gate docblock by lane 4, deliberately not built. Design + build
   ride lane 2's multibox work.
3. **The mirror/adoption exchange carries no `instance_class` at all** — adoption-minted demo
   meshes stay time-frozen until it does.
4. (Already ordered, ruling 2): `DisintermediationService` fix — constituents inherit.

## For lane 3 (I) — judiciary surfaces

- ~~CaseDetail.vue: `:interactive="isDemoMode"` flip~~ — **DONE at the desk 2026-07-29**
  (one attribute + docblock, on lane 4's flag; announce-fix-record, no waiting).
- **Challenge-tracker "simulate" buttons: desk disposition — client-side simulation DROPPED.**
  If the teaching value is wanted, it returns as a D5 scenario preset that files REAL forms
  through the engine — never a client-side fake (same doctrine as the consent-slider drop).
  Revisit only if lane 3 or the operator asks.

## D6 dispositions (desk rulings on lane 4's scope truth, 2026-07-29)

| Affordance | Recon claimed | Truth | Disposition |
|---|---|---|---|
| CaseLifecycle Back/Advance | built-but-disabled | BUILT | flipped on (world-keyed), desk-applied |
| Judiciary-home consent sliders | built-but-disabled | never built; docblocks say "DO NOT ship" | **DROPPED** — a slider faking consent contradicts the engine-snapshot rail (lane 4's recommendation, adopted) |
| Challenge-tracker simulate | built-but-disabled | never built | client-side fake DROPPED; possible D5 real-filing preset |

## For lane 5 (N) — translation, from lane 15's Wave 1 (2026-07-29)

1. **⚑ THE flatJson FIND (`cb73c5c`)**: vue-i18n's default resolver walks nested objects only —
   every flat dotted-key namespace catalog under `locales/<code>/<ns>.json` was UNRESOLVABLE
   (`t()` returned the raw key) for every namespace, every locale, since the day they were
   written. Invisible because no component had ever consumed one; lane 15's flyout was the
   first. Fixed with `flatJson: true` on `createI18n` (+8 lines in `resources/js/i18n/index.js`
   — lane 5's file, announce-fix). **Implication for lane 5: re-verify anything that assumed
   the catalogs rendered; coverage numbers measured the files, not the screen.**
2. Two NEW English-only namespaces to translate: `en/c_education.json` (851 strings) +
   `en/c_achievements.json` (141) — meta files included in lane 5's shape; cite tokens
   deliberately NOT in the payload (never translated). Total lane-15 payload: 992 strings.
3. Flow-step action strings in `registry/flows.js` are spec-side English, unextracted — lane
   5's call whether to sweep them.

## Lane 5's Wave 2 slate (its own READY-TO-RUN items, held at the desk pending the operator's review)

1. **Machine-translate `c_education` (851) + `c_achievements` (141) across the 6 registered
   locales** — local NLLB, ZERO SPEND (the settled ruling; cloud stays off unless local
   capacity runs out, which would be a spend question for the operator). Direct scripted pass
   via the built `translate_catalog.py` (the resumable pull-engine is not built; fine at 992
   strings). Output lands as `ai_draft` → the human-verification queue gates publication.
2. `registry/flows.js` flow-step strings: extraction sweep (en keys + a wave doc for lane 6).
3. Its parity debt from `2eb7137`: CLI `i18n:review` verdict command carrying the
   reader-of-language gate.

Context recorded 2026-07-29: loose ends from `2f5953a` all closed (`45d9a44` — 8 regressed
pins hardened via the §4b fixture trap ×2; 18 green/221 assertions); honest coverage across
the 6 translated locales is now **73.7–77%**, the dip being exactly the 992 new untranslated
strings (~5,950 of 6,425 missing keys) — the gate flagging precisely the gap item 1 closes.
Prod-build rebuild is moot while Vite dev serves (`public/hot` present); the heap-capped
rebuild recipe is on file and needs an announced `fcd_vite` restart if ever switching.

## For lanes 3/6 — walk-list observation (lane 15, 2026-07-29)

- `/judiciary/docket` 302s to `/civic` for a fresh dev-login user (SITEMAP lists the docket
  as public). Likely data-dependent (no judiciary in the viewer's chain) — belongs on the
  walk list either way.

## Lane 3 additions from the R-A/R-B/R-C rulings (2026-07-28, plan §10)

1. **The Type B election guard (R-A)**: refuse Type B race scheduling on any
   `type_b_needs_districting` chamber, every world class, until the Type B district mapper
   ships — guard + constitutional pin. (Niue's existing seat stays, labeled; re-seat rides
   the mapper.)
2. **Activation/scale redesign (R-B)**: real worlds = institutions + room counts scale
   continuously with ACTUAL playerbase 1→∞; demo worlds = WorldPop-premade; 5–9 is only the
   reps-per-district setup default. Design doc first (touches ActivationTierService /
   InstitutionScaleService), then build.
3. **The amendment workflow loop (R-C)**: verify/complete legislature-amends-a-setting
   end-to-end through the real act pipeline; surface as a walkable workflow + walk journey.

## For lane 1 — Wave 3 headline (R-A): the Type B district mapper

Stage-two grouping over the adjacency graph (even clumps, compact, no geometry cut) for the
~9,708 flagged chambers. Named plan item per the operator; the campaign's resume point;
Type B election playtesting stays blocked fleet-wide until it ships.

## Suite triage at Wave 1 close (lane 4's checkpoint, 2026-07-29 — 22 failures, four families)

| Family | Tests | Cause | Owner / remedy |
|---|---|---|---|
| Class-refusal | PeerTransportLearning ×3, CapabilityRegistry ×1 (+feeds autonomy stack) | the box is `instance_class=scale_demo`; fixtures fake peers advertising NO class, the SYMMETRIC rule refuses — pin firing on borrowed fixtures | lane 2: fixture-scoped `InstanceClass::override`; no rail weakened |
| Fixture-borrows-the-world | AutonomyFlipRewraps ×5 + LocalAutonomyGoverned, AutoscalePin ×3, RemainderSynthesis ×3, ManualDistrictDraw ×1, LegalCompliance ×1 | the filled, now TIME-TRAVELED shared world | lanes 1/2/3 per surface (the documented class) |
| Order-dependence | MatrixCarveoutEmitter, ModerationFlip | pass isolated; documented | unowned, known |
| Real findings | LedgerIntegrity: `TreasuryDemoCommand` + `EconomyController` write `ledger_entries` outside `LedgerService` (predates Wave 1) | writer-or-heuristic call | lane 13 |
| ~~CgcIpPublicDomain~~ | ~~grant drift + catalog reference~~ | **CLOSED at the desk (`9ac567f`)** — born-failing grant revocation written as a real migration; doc-string false positive renamed; 6/6 green | — |

## ⚖ Operator items accumulated during Wave 1

- ~~Art. I elected-training-gate placement~~ — **RULED 2026-07-28: PRE-SEATING** ("you need
  to do the training to do the job"). Ballot access untouched; winners train before taking
  the seat; decliners fall to countback. Lane 15 records in K2_ENGINE_PLAN §10.7; the pin
  narrows to no-gate-on-ballot-access-ever.
- Screenshot debts for the Wave 1 review: lane 4 (flyout clock advance) + lane 15 (flyout on
  2–3 surfaces) — both DOM/server-verified, pixel shots blocked while no browser pane
  composites; capture at review time when the operator is present.

## Small fixes applied at the desk during Wave 1

- `Districts.vue` dev-seat gate: `import.meta.env.DEV ||` leak removed — world-keyed only
  (lane 4's flag; the nav.js registry warning pattern).

## D5 ruling (desk, 2026-07-29)

**Full async once** — queued-run + poll pair for the scenario presets (lane 4's
recommendation, adopted): `elections:demo`-class seeders run minutes; the async plumbing also
serves D4's relocation path. V1-sync-only rejected.
