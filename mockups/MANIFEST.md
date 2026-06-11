# CGA Mockups — MANIFEST

Human-readable handoff map. The machine-readable version is [`manifest.json`](manifest.json)
(one record per screen/flow page, §16 schema); [`manifest.js`](manifest.js) is its byte-equivalent
mirror loaded via `<script src>` so the coverage matrix and launchpad work on `file://` with zero
fetch. **This file is the bridge the production implementation work reads first.**

Build stage status: **ALL STAGES COMPLETE (0–7).** 62 screens + 80 flow walkthroughs + the QA
instruments; the coverage matrix reports 30/30 roles · 80/80 workflows · 103/103 forms, with all
142 manifest files resolving and the manifest.js mirror byte-identical.

---

## 1. §2 discrepancy resolutions — as applied

Canonical source: `CGA_Constitutional_Roles_Forms_Chart.xlsx`. The workflows catalog drifts from it;
every drift is resolved once in `assets/js/fixtures.js` (`registry.forms[].aliases`) and surfaced
in the coverage matrix. On screens, the rule is **form name first, ID second** so drift can never
mislead a reader.

| Canonical | Catalog alias | Where the catalog uses it |
|---|---|---|
| R-21 Advocate / R-22 Juror | swapped (R-22 Advocate, R-21 Juror) | WF-CIV-07 (Sheet 1 row 10; Sheet 2 row 153), WF-JUD-03/04 (Sheet 1 rows 56–57) |
| F-CHR-001…004 (committee chair) | F-COM-001…004 | WF-LEG-06/08 inventory rows |
| F-BOG-001/002 (board of governors) | F-GOV-001/002 | WF-EXE-09 inventory row |
| F-IND-004 Identity Verification Submission | F-IND-005 | WF-CIV-01 |
| F-IND-005 GPS Residency Ping | F-IND-004 | WF-CIV-02 |
| F-IND-016 Constitutional Challenge Filing | F-IND-013 | WF-JUD-05 (new find — not in §2; F-IND-013 is canonically Organization Membership Application) |
| F-LEG-022 Removal/Impeachment/Censure/Expulsion | F-LEG-034 | impeachment flows |
| F-LEG-023 Referendum Delegation | F-LEG-022 | WF-LEG-10 |
| F-LEG-024 Emergency Powers Declaration | F-LEG-023 | WF-LEG-11 |
| F-LEG-025 Emergency Powers Renewal | F-LEG-024 | WF-LEG-11 |
| F-LEG-036 Vacancy Declaration | F-LEG-030 | WF-ELE-03, WF-LEG-12 |

Other §2 resolutions applied:

- **80 workflows** (CIV 8 · ELE 10 · LEG 20 · EXE 9 · JUD 9 · ORG 10 · JUR 9 · SYS 5) — counted from
  the Sheet 1 inventory; the catalog Read Me still claims "63". Inventory is authoritative.
- **Factions → organizations.** No faction layer anywhere. The constitution's faction touchpoints
  (verified verbatim): Art. II §2 *Establish Proportional Voting Systems*, Art. II §2 *Ensure
  Election Security and Integrity*, Art. II §4 *Committees* (three clauses), Art. III §2 *Executive
  Committee composition*. All four are ledgered ([constitutional-questions #q1](shared/constitutional-questions.html#q1)).
  The word "faction" appears only inside verbatim constitutional citations.
- **Committee tie-break** uses the repo wording — largest vote share after normalizing quotas —
  cited `Art. II §4 · as implemented`, linking to [#q2](shared/constitutional-questions.html#q2).
- **Geography is real, pre-united.** adm 0 Earth → 1 national → 2 state/province → 3 county →
  4 local → 5+ sub-local; slugs `{iso3}-{adm_level}-{name}`; cosmic chain
  Multiverse → … → Solar System → Earth; **no supranational tier**.

**Citation-dictionary correction (verified against `Fair_Constitution_Labeled.docx`):**
Art. II §8 is titled *Legislatures: Forbidden Actions*. Its subsections carry the citations the
build instructions gloss as "Art. II §8": juror/civic-service protections cite
`Art. II §8 · Non-Interference with Civic Obligations` and
`Art. II §8 · Prohibition of Compulsory Payments for Civic Rights`; the mandatory >9 split cites
`Art. II §8 · Subdivision of Legislatures`. The "drawn equally, contiguously, and fairly" boundary
language lives in `Art. II §2 · Establish Independent Election Boards`.

**Definitive registry counts** (from fresh sheet dumps, not summaries): 30 roles · 17 institutions ·
**103 forms** (the instructions' "~110" was an estimate) · 80 workflows · 21 clocks ·
20 entity state machines · 33 special vote types · 30 bootstrap steps.

## 2. `--adm-N` aliases + ramp-rename recommendation

`mockup.css` defines `--adm-0…5` (with paired `--adm-N-fg`) as the only tier tokens components may
use, mapped **altitude-true** onto the existing six-color ramp:

| Alias | Ramp token | Level |
|---|---|---|
| `--adm-0` | `--tier-planetary` (brand purple) | Earth |
| `--adm-1` | `--tier-supranational` | National |
| `--adm-2` | `--tier-national` | State / Province |
| `--adm-3` | `--tier-regional` | County / Region |
| `--adm-4` | `--tier-municipal` | Local |
| `--adm-5` | `--tier-neighborhood` (teal) | Sub-local |

Because Earth has no supranational tier, the hues named `national`/`supranational` are recycled one
slot down. **Design-system recommendation: rename the ramp tokens altitude-neutral
(`--tier-1…6` or `--adm-0…5`)** so token names stop encoding a tier model the product doesn't have.
Chips always pair color with a text label (WCAG 1.4.1).

## 3. Architecture decisions

- **No ES modules, no fetch on the critical path.** Plain IIFEs on `window.CGA`; data travels via
  `<script src>` globals and URL query params, so `file://` behaves identically to http(s).
- **Load order** (every page): `demo-state.js` in `<head>` (sets `lang`/`dir` pre-paint), then at
  the end of `<body>`: `fixtures.js` → `manifest.js` → `icons.js` → `i18n.js` → `shell.js`
  (→ page script). `shell.js` hard-fails with a visible banner if the order is wrong.
- **Page contract:** each page ships only `<main id="main">` + a `window.CGA_PAGE` config
  (`id, title, module, nav, roles, workflows, forms, citation, flow, register`). The shell renders
  header / role-aware sidebar / footer / demo bar from one NAV object and warns in the console if
  the page has no manifest record (QA §15 hook).
- **Demo state** `{role, persona, jurisdiction, locale, dir, scenario}` — defaults ← localStorage
  (best-effort) ← URL params (**URL wins**); every internal link is rewritten through
  `CGA.state.link()` so state survives navigation even where storage is blocked. Scenario-flag
  vocabulary (frozen): `election: approval|ranked|certifying`, `emergency`, `challenge`,
  `quorumFails`, `bicameral`, `countbackFailed`, `restoration`, `unionDrill`.
- **flowData contract** (frozen; stress-tested on the style guide against WF-CIV-02, WF-ELE-03,
  WF-JUD-05): header card · steps `{n, actor, action, form|engine, outcome, screen{href, params},
  branches[{label, goto: stepN | {wf, step} | "terminal:STATE"}], entityState?}` · entity
  state-strip · deep links via `CGA.state.link`. Flow pages (Stages 1–6) are pure data + this
  renderer.
- **Icons:** Lucide-style local SVG sprite (`assets/js/icons.js`, stroke-width 2) injected once and
  referenced same-document — the design README's flagged substitution, with the offline-fallback
  set promoted to primary so no runtime CDN exists (`file://`-proof). Standalone copies in
  `assets/img/icons/`. Production may swap to the `lucide` package.
- **Fonts:** `colors_and_type.css` @imports Google Fonts; offline/`file://` falls back to the
  system stack by design. No woff2 files were supplied (design README FONTS note).
- **i18n:** full `en` chrome strings; `es/ar/zh-Hans/hi` stubs falling back to `en`; `en-XA`
  pseudo-locale (accents + ~35 % expansion + ⟦brackets⟧, skipping citations/code/IDs) and an RTL
  override live in the demo bar. RTL correctness is carried entirely by logical-properties CSS +
  directional-icon flips — there are no `[dir="rtl"]` layout overrides to maintain.

### Regenerating the manifest mirror (every stage commit)

```powershell
python -c "import json; d=json.load(open('mockups/manifest.json',encoding='utf-8')); open('mockups/manifest.js','w',encoding='utf-8').write('/* GENERATED from manifest.json - regenerate with the snippet in MANIFEST.md. */\nwindow.CGA_MANIFEST = '+json.dumps(d,ensure_ascii=False,indent=1)+';\n')"
```

The coverage page deep-compares the two over http(s) and shows a drift banner.

## 4. Component inventory (Stage 0 — live on [the style guide](shared/styleguide.html))

Candidates for shared Vue components in the production build:

| Component | Classes | Suggested Vue component |
|---|---|---|
| App shell (header/sidebar/footer) | `.app-shell .app-header .sidebar .app-footer` | `Layouts/AppLayout.vue` (exists — reconcile) |
| Jurisdiction switcher + cosmic prefix | `.jur-switcher .cosmic-prefix .adm-chip--N .adm-sep` | `Components/JurisdictionSwitcher.vue` |
| ADM chip / tier dot | `.adm-chip .tier-dot` | `Components/AdmChip.vue` |
| Status badge | `.badge--success/warning/danger/info/neutral` | `Components/StatusBadge.vue` |
| Citation / as-implemented marker | `.citation .citation--implemented` | `Components/Citation.vue` |
| Hardened chip / amendable block | `.hardened .amendable` | `Components/HardenedRule.vue` / `AmendableSetting.vue` |
| Form chip / engine chip | `.form-chip .engine-chip` | `Components/FormChip.vue` |
| Org chip / persona chip / avatar | `.org-chip .persona-chip .avatar` | `Components/OrgChip.vue` etc. |
| Card / inset card / about-surface | `.card .card--inset .about-surface` | `Components/Card.vue` |
| Buttons | `.btn--primary/secondary/ghost/gold/danger/sm` | `Components/Button.vue` (ui-kit parity) |
| Fields | `.field .field-input .field-hint .field-error .select .radio-group .checkbox` | `Components/Field.vue` |
| Stat | `.stat .stat--accent` | `Components/Stat.vue` |
| Registry table | `.table .table-wrap` | `Components/RegistryTable.vue` |
| Horizontal stepper | `.stepper .stepper-step--done/--active` | `Components/SetupStepper.vue` (exists) |
| Flow walkthrough stepper | `.flow-steps .flow-step .flow-actor .flow-branches .branch-btn` | `Components/FlowStepper.vue` |
| Entity state strip | `.state-strip .state-node--current` | `Components/StateStrip.vue` |
| Lifecycle tracker | `.lifecycle .lifecycle-stage--current` | `Components/LifecycleTracker.vue` |
| Banners | `.banner--info/warning/emergency/demo` | `Components/Banner.vue` |
| Threshold meter | `.meter .meter-fill .meter-threshold .meter-caption` | `Components/ThresholdMeter.vue` |
| Achievement toast (proposed) | `.toast--achievement .proposed-flag` | gamification layer — flagged proposed |
| Coverage matrix | `.coverage-table .coverage-cell--*` | QA tooling only |
| Demo bar | `.demo-bar .demo-control` | mockup-only — not product UI |

## 5. A11y posture & how to run the checks

Every page: semantic landmarks, one `h1`, skip link, gold `:focus-visible` ring, keyboard-operable
controls (steppers, branch buttons, popovers are native `<details>`), status never color-only,
`prefers-reduced-motion` honored, touch targets ≥ 24 px, logical properties only.

Scripted scans (run from repo root; results recorded per stage):

```powershell
# zero hex literals outside colors_and_type.css; zero physical left/right properties; no emoji
python mockups/tools/qa_scan.py
```

Manual per stage: keyboard-only walkthrough; RTL flip + pseudo-locale on the style guide;
380 px-wide pass on civic/electoral screens. axe-core/Pa11y was not available in this environment
at Stage 0 — the §13 scripted+manual subset ran instead; superseded by the §9 harness below.

```text
# full responsive + a11y sweep over every manifest page (serve mockups/, open in a browser):
tools/audit_harness.html   — 7 widths (320–1920) overflow + pseudo-locale & RTL passes +
                             per-page checks (title, lang, h1, heading order, duplicate ids,
                             img alt, control labels, accessible names, 24px targets,
                             unnamed SVGs, positive tabindex)
```

### Stage 0 QA results

Recorded in the Stage 0 commit: hex / physical-properties / emoji / link scans clean; both
protocols verified (http + headless-Chrome `file://`); RTL + pseudo-locale + 380 px passes on the
style guide; flow-stepper contract stress-tested on WF-CIV-02 / WF-ELE-03 / WF-JUD-05.

## 6. Stages 1–7 — what was built and verified

**Screens (62).** Four flagship pages hand-authored (`civic/civic-home`, `electoral/open-ballot`,
`electoral/ranked-ballot`, `electoral/results` — the results page embeds a REAL Gregory-method STV
count over synthetic ballots: 412,383 valid ballots, Droop quota 41,239, 27 rounds, one write-in
tabulated identically); the remaining screens were authored per the §9 specs module-by-module
against the exemplar contract. Every screen carries its "About this surface" panel, citation
footer, and visible form-cards (name first, ID second, available-to, citation, aliases).

**Flow walkthroughs (80).** Generated by `tools/gen_flows.py` from catalog Sheet 2 through the
frozen flowData contract: header card, keyboard stepper, BRANCH buttons (verbatim catalog labels,
incl. failure/terminal paths), sub-workflow handoffs, entity state strip, "Open in app" deep links
resolved to the first manifest screen rendering each step's form. 16 drifted form IDs were
normalized during transcription (logged by the generator; same alias table as §1). Three flows
(WF-CIV-02, WF-ELE-03, WF-JUD-05) use the hand-authored `fixtures.flowSamples` data.

**Shared-layer fixes landed during integration** (found by the module verification passes):
- `shell.js` `renderChrome()` now no-ops before `buildShell` — page scripts calling
  `CGA.shell.refresh()` pre-DOMContentLoaded previously threw and silently killed their
  `cga:statechange` listener registration (broke scenario reactivity on early pages).
- `demo-state.js` `link()` / `mirrorUrl()` now pass through page-local query params
  (e.g. `?candidate=` on candidate-profile) instead of stripping them.
- `.badge` wraps long labels; `.state-node` wraps long state names; the launchpad hero's bleed
  margins moved into the stylesheet so the 380 px padding change tracks (mobile overflow fixes).

**Stage 7 QA results (final).**
- `tools/qa_scan.py`: hex / physical-properties / emoji / static-link scans clean over all 146 files.
- Runtime error sweep (every page loaded in an instrumented iframe with an injected `onerror`
  hook): 142/142 pages with zero uncaught errors, shell rendered, exactly one `h1`, content present.
- Load-time state-mutation sweep: 0 of 62 screens write demo state at load.
- Coverage page over http: 30/30 · 80/80 · 103/103; manifest drift check identical; live dead-link
  scan resolves all 142 files.
- 380 px sweep over all civic + electoral screens + launchpad: zero horizontal overflow.
- `file://` (headless Chrome, real protocol): launchpad, open ballot, WF-JUD-05 flow,
  district mapper, and the coverage matrix all render without shell errors.
- axe-core/Pa11y unavailable in this environment — §13's scripted+manual subset ran instead;
  a third-party a11y audit remains a production-phase task.

**Component inventory additions since Stage 0** (all on the style guide implicitly via usage):
`.filter-bar/.chip-toggle/.tag-chip` (faceted registries), `.candidate-row/.standing/.finalist-line`
(open ballot), `.switch` (revocable approval), `.rank-list/.rank-item/.rank-controls` (click-to-rank
ballot), `.stv-*` (round-by-round count bars + quota mark), `.receipt` (ballot hash),
`.log-row/.log-hash/--rejected` (audit chain, sync logs, public records), `.law-diff del/ins`
(Art. IV §5 PATH C), `.seat-map/.seat-dot` (chamber roster).

**Module agent resolutions.** 52 conservative resolutions were logged while authoring (full list
preserved in the build session record); the ones that read as open product questions were promoted
to `OPEN_QUESTIONS.md` (#12–#17).

## 7. Developed-component slots — round peg, round hole

Three tools already exist as working software in the product worktree. The mockups carry their
**placement contracts**: same panes, same position in the global navigation, recreated in the
mockup design system — because the developed parts ADOPT this design system, not the reverse.
Each slot is marked on-page with a `.dev-slot` strip.

| Mockup page | Developed component | What the slot fixes |
|---|---|---|
| `jurisdictions/jurisdiction-browser.html` | Jurisdiction viewer (`Pages/Jurisdictions/Show.vue`) | Left profile panel (breadcrumb, natural-level badge, Wong-orange population / sky-blue members, Region & dataset provenance, maps-accepted record, View Legislature & Districts) + right Leaflet map with Names/Population/Members/Raster toggles and the geoBoundaries/Protomaps/WorldPop attribution. |
| `jurisdictions/district-mapper.html` | Legislature Browser (`Pages/Legislature/Show.vue`) | Left browser panel (root seats + quota, Districts/Assigned/Unassigned, plan selector + Activate, MAP QUALITY ledger, Autoseed/Clear, prev/up/next wizard row, member table with Dev·CHR·Contig·Intact strips, inline sub-district expansion, drill into subdividing member scopes — the Mexico case) + right map with Seats/Pop/Names/Jurs/Stats/Raster label layers. |
| `system/setup-wizard.html` | Setup wizard (`Pages/Setup/Step0…Step4`) | The five-step founding loop: cosmic address + restore-from-backup → constitutional defaults (defaults-of-defaults as reference, never locks) → ETL run with per-layer live progress and jurisdiction-viewer review → apportionment + district-mapper handoff → confirm/seat + instance export (the federation seed for Step 0 restores). |

The maps inside the slots are stylized SVG stand-ins; the running tools render Leaflet +
protomaps + geoBoundaries + WorldPop. Data shown mirrors a real development run (Earth legislature,
1,999 seats, quota 3,985,245; Autoseed Attempt 2; ~951k imported jurisdictions).

## 8. Owner review round (2026-06-11) — applied decisions

JD's review answers (recorded against `OPEN_QUESTIONS.md` 1–17; only #12 remains open):

- **Demo world re-centered on New York.** The dataset is ADM 0–6 (~1M jurisdictions) and the US
  chain honestly ends at the **county** level, so the default chain is Earth → United States →
  New York → **New York County (Manhattan)** with Kings/Queens/Bronx/Richmond siblings; instance
  `manhattan.cga.example`. All fixtures, screens, and copy re-pinned (orgs renamed: Five Boroughs
  Chamber of Commerce, Hudson Mutual Aid, Uptown Neighbors, Manhattan Water & Power; mapper
  scenario now US→New York: 42 seats, quota 480,982).
- **Numeric adm levels never display** — natural labels only (Planet / Country / State / Province /
  County / Municipality / Township / Neighborhood, the ETL repo's vocabulary).
- **"Giant"/"leaf" removed from user-facing copy** (developer jargon) — replaced with
  "exceeds the seat ceiling — subdivides further" / "no child subdivisions — manual line-drawing".
- **Ledger grew to seven entries:** #q6 universal countback (faction-dependent procedure made
  meaningless by polymorphic endorsements — next-draft candidate), #q7 bicameral per-kind
  threshold (each kind needs its own quorum + majority; text resolution pending).
- **Unstated vote thresholds are ordinary majorities** (peg-quorum basis); supermajority only
  where stated. Governor removal = hiring-and-firing majority.
- **No real recount** — the count is in-system; "recount" copy reframed as audit review.
- **R-17 is not a pickable path** — launchpad card is informational (sequential-exclusion
  derivation noted); the role stays assumable via the demo bar.
- **Activation model recorded** on bootstrap + term-sync: institutions activate where player
  population pegs against real population; lockstep harmonization is the end-state.

## 9. Responsive + accessibility hardening pass (2026-06-11)

Run before website publication, per `App Docs/accessibility_internationalization.md`.
Verification instrument: `tools/audit_harness.html` — every manifest page loaded at
320 / 360 / 412 / 768 / 1024 / 1440 / 1920 px plus a pseudo-locale (en-XA, +35% text)
pass and an RTL pass at 360 px, plus the per-page a11y battery. **Final result:
144 / 144 pages, zero findings.** `qa_scan.py` clean. Coverage 30/80/103 green, 144 records.

**Self-hosted fonts (LAN requirement).** Instrument Sans (400/500/600/700 + italic 400) and
Instrument Serif (regular + italic) mirrored to `assets/fonts/` (14 woff2 files, ~256 KB,
OFL-1.1 — license in `assets/fonts/OFL.txt`). `colors_and_type.css` now `@import`s the local
`fonts.css` (unicode-range subsetting kept, `font-display: swap`). Verified zero external
requests site-wide — the site runs fully offline / LAN-only.

**Responsive.**
- Demo bar is a collapsible `<details>`: open by default on large viewports, collapsed on
  phones and short (landscape) viewports; the user's toggle survives re-renders.
- ≤48rem: header un-sticks (full canvas back), jurisdiction switcher shows only the current
  chip (full chain stays in the panel); ≤30rem: header popovers become viewport-pinned sheets.
- `max-height: 30rem` (landscape phones): all sticky chrome becomes static.
- `.stack > *, .grid-2 > * { min-inline-size: 0 }` — kills the min-width:auto flex trap that
  let wide tables veto narrow viewports.
- `shell.js wrapTables()`: every bare `table.table` is wrapped in a scrolling `.table-wrap`
  at render time (semantics preserved); `.table-wrap` is `position: relative` so
  visually-hidden absolute descendants cannot escape the scroll clip (the settings-register
  phantom-overflow bug).
- STV rows collapse to two lines under 40rem; chips (`org/tag/form`) wrap instead of
  overflowing; selects clamp to their container; `main--wide` modifier gives map/wizard
  surfaces the full canvas on large monitors (96rem).
- Pseudo-locale pad is now word-chunked so en-XA tests truncation, not fake overflow.

**Accessibility.**
- Focus ring: `outline: 3px solid transparent` + gold box-shadow — visible under Windows
  forced-colors where box-shadow is stripped (SC 2.4.7/2.4.13). `scroll-padding-block`
  reserves room so sticky chrome never obscures focus (SC 2.4.11).
- `@media (forced-colors: active)`: data-carrying fills (meters, STV bars, tier dots, seats,
  swatches) keep author colors; chips/badges get CanvasText borders.
- `@media (prefers-contrast: more)`: quiet text/border tiers step up.
- Measured contrast (canvas-resolved oklch/color-mix, WCAG 2.x ratios) across the
  component-dense pages. Two real failures fixed at source:
  `--gov-fg-subtle` raised from gray-500 (3.67:1 on cards) to a 45% gray-400 mix
  (4.91:1 cards / 5.57:1 page — documented in `colors_and_type.css`), and
  `.proposed-flag` text gold-700→gold-500 (3.53:1→7.38:1). Also fixed:
  `button.card` inherited near-black UA ButtonText on dark cards (invisible text on the
  wizard's time-mode tiles).
- Targets ≥24px extended to chip controls and to links that sit as flex/grid items
  (footer, demo bar, wf rows, log rows, steppers, persona chips, `a.citation`) — flex items
  lose the WCAG 2.5.8 inline exception.
- Persistent polite live region (`CGA.shell.announce`) exists before any content insertion
  (SC 4.1.3); the flow stepper announces step changes and terminal states.
- Header popovers: Escape closes and restores focus to the trigger; outside click closes.
- Setup-wizard `field()` helper now emits associated `for`/`id` labels; autocomplete tokens
  added where HTML autofill tokens exist (`url`, `nickname`).
- NEW `shared/accessibility.html` — the accessibility statement (WCAG 2.2 AA + selected AAA,
  EN 301 549), linked from every footer per the doc's Phase 13. Manifest: 144 records.

Known instrument caveat: the contrast walker reads CSS `color`, not SVG `fill` — the stylized
map labels it flags are actually white-on-dark backplates (pass). Assistive-technology
walkthroughs with human testers remain scheduled against the production build.
