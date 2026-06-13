# PHASE E FRONTEND — 6 JUDICIARY SCREENS + COMPONENTS

Worktree root: `E:\fair-constitution-app\.claude\worktrees\practical-payne-17d537\`. Verified on disk before writing: `resources/js/Components/{Ui×28, Electoral×8, Legislature×5, Civic×1, Surface×3, Shell×6}` — and crucially the Phase C/D substrate this phase reuses wholesale already exists: `Legislature/ConstituentConsentPanel.vue` (the dual-supermajority component, pure renderer — `process.required` is the engine number, "feed `required=99` and it honestly displays 99"), `Ui/LawDiff.vue` (server-computed `segments:[{op:'eq'|'del'|'ins',text}]`, docblock already names "Phase E challenge remedies (Art. IV §5 PATH C)"), `Ui/Stepper.vue` (`steps:[{label,icon,state}]`, `aria-current`), `Ui/StateStrip.vue` (`states[]`,`current`), `Ui/LifecycleTracker.vue` (`stages[]`,`current`), `Legislature/VoteTally.vue` (`mode`,`thresholdClass`,`serving`,`requiredYes`,`tallies`,`kinds`,`outcome`), `Legislature/VoteCastList.vue`, `Electoral/{StvBar,StvRound}.vue`, `Ui/{AmendableSetting,ThresholdMeter,DataTable,FilterBar,ChipToggle,Stat,PersonaChip,LogRow,Banner,TagChip,StatusBadge,FormChip,HardenedChip,RadioGroup,CitationLine}.vue`, `Surface/{PageScaffold,FormCard,AboutSurface}.vue`. Verified: `app/Http/Controllers/Executive/ExecutiveResolverController.php` (the 4-branch resolver pattern this phase mirrors), `config/cga/surfaces.php` (record shape: `title/module/nav/roles/workflows/forms[{id,availableTo?,citation}]/clocks/citation`), `config/cga/state_machines.php` (ESM-16/17/18 entries — Phase E adds `case` + `constitutional_challenge`), `resources/js/Navigation/nav.js` (the `court` section ALREADY exists — 5 items, all `phase:'E'`, role-gated R-19/R-20/R-21/R-22, hrefs `/judiciary`, `/judiciary/docket`, `/judiciary/challenges`, `/judiciary/advocate`, `/judiciary/jury`), `app/Http/Middleware/HandleInertiaRequests.php:73` (`phasesLive => ['A','B','C','D']` — Phase E appends `'E'` at the final landing batch), `app/Models/{Law,LawVersion}.php` (`ORIGIN_JUDICIAL_REMEDY`/`SOURCE_JUDICIAL_REMEDY` + `scope_judiciary_id` RESERVED), `database/migrations/2026_06_12_000004_create_clocks_tables.php` (`clock_timers.override_value` jsonb pre-provisioned with the comment "Phase E per-case override slot (CLK-11/CLK-12: window set by the judiciary)"), `app/Domain/Forms/FormRegistry.php` (F-LEG-017/018/021/035, F-JDG-001..010, F-ADV-001..004, F-IND-015/016/017 all CANONICAL, all UNREGISTERED in `HANDLERS` — Phase E wires them), and all 6 mockups in `mockups/judiciary/`.

This is the FRONTEND companion to the Phase E backend designs (schema, judiciary-services, law-remedy). **Prop dependencies on the other three designs are called out inline as `[DEP: <design> · <table/service>]`.** Where the backend designs are still in flight (authored in parallel), I name the table/column/service I consume and the canonical form/role that produces it, so the contract is bindable at merge — the same posture PHASE_D_DESIGN_frontend.md took against the Phase D backend WIs.

---

## A) COMPONENT SPECS

### A.0 CSS audit (grep of all 6 judiciary mockups vs `resources/css/cga/components.css`)

**Every class the 6 screens use is already ported** by Phases A–D. The judiciary mockups draw exclusively from the existing vocabulary: `.state-strip/.state-node(--current)/.state-arrow` (case ESM + challenge ESM), `.lifecycle/.lifecycle-stage(--done/--current)` (case-detail 10-stage track), `.stepper/.stepper-step(--done/--active)` (juror-view service stepper), `.meter-block/.meter/.meter-fill(--met)/.meter-threshold/.meter-caption` (conversion dual-supermajority + override vote), `.law-diff` with `del/ins` (constitutional-challenge remedy preview — the Phase C/E `LawDiff` component owns this exactly), `.table/.table-wrap`, `.card/.card--inset`, `.form-chip/.form-id`, `.badge--success/--warning/--danger/--neutral/--info`, `.hardened`, `.banner--emergency/--info/--warning/--demo`, `.filter-bar/.chip-toggle`, `.field/.field-input/.select/.field-hint/.field-label`, `.radio-group/.radio`, `.log-row/.log-seq`, `.amendable/.amendable-value/.amendable-meta`, `.gloss/.citation/.eyebrow/.mono`, `.stack/.cluster/.grid-2`, `.about-surface`.

**Append ZERO new CSS.** Unlike Phase D (which needed `.board-strip`/`.seat-pip`/`.range-input`/`.meter--lg`), Phase E introduces no new visual primitive — judiciary is entirely tables, state strips, lifecycle tracks, meters, and law diffs that already exist. The only mockup-local class is `.demo-control` (the docket search label wrapper), which is mockup-only chrome, not product (same exclusion as Phase D). **This is a load-bearing finding: the judiciary frontend is pure composition over the Phase A–D kit.** The `qa_scan`/zero-hex gate has nothing to scan here.

### A.1 `Judiciary/PanelTable.vue` — panel-assignment + conflict-screening table

The one genuinely new judiciary component (everything else reuses). Renders the bench panel for a case with conflict-screening results (case-detail.html stage 3 + judiciary-home.html severity table).

```js
props: {
  // judicial_panels + panel_seats rows  [DEP: judiciary-services · panels/panel_seats]
  seats: { type: Array, required: true },
  // [{ judge:{name, href}, is_presiding, screening:'no_conflicts'|'recused'|'excluded',
  //    screening_reason:string|null, result:'seated'|'recused'|'excluded' }]
  severity: { type: String, required: true },   // 'minor'|'moderate'|'serious'|'major_constitutional'
  panelSize: { type: Number, required: true },  // ENGINE output (CLK-16 rule) — never client-computed
  isFullCourt: { type: Boolean, default: false },// major constitutional question → all judges
  rule: { type: String, default: null },        // "≥3, odd, severity-scaled · CLK-16 · Art. IV §4" — server citation
}
```

Render: hardened cluster (`HardenedChip` "protected by the constitutional test suite" — panel sizing is a hard constraint) + `StatusBadge` "Panel of {n} — odd, severity-scaled" (or "Full court — all {n} judges" when `isFullCourt`); `DataTable` (judge name link, screening result, seated/recused/excluded `StatusBadge` — seated=success/recused=neutral/excluded=neutral with the `screening_reason` as a `.citation`); footer gloss verbatim from judiciary-home.html line 44: *"Severity scaling: the heavier the possible consequence, the more judges must hear it — the panel is always odd so no case can deadlock."* Classes: `.hardened`, `.table`, `.badge--*`, `.citation` — no new CSS. **`panelSize` and `isFullCourt` are engine snapshots** (the CLK-16 hard constraint: panels ≥3, odd, scaled to severity; full court for major constitutional questions — `app/Services/Judiciary/PanelService` per the backend design, never recomputed client-side, same posture as `worker_seats`/`required_yes` in Phase D).

### A.2 `Judiciary/CaseLifecycle.vue` — the 10-stage case walkthrough (case-detail centerpiece)

Promotes case-detail.html's `stages[]` + `STAGE_STATE[]` machinery into a component. Composes the already-ported `.lifecycle` track + `StateStrip` (the dual rendering the mockup shows: the 10-stage `lifecycle` ordinal track AND the Case ESM `state-strip` highlighting the mapped resting state).

```js
props: {
  case: { type: Object, required: true },        // [DEP: judiciary-services · cases]
  // { id, title, kind, severity, court:{name}, panel:PanelTableProps,
  //   double_jeopardy:bool, jury_entitled:bool, current_stage:1..10, current_state }
  machine: { type: Array, required: true },       // Case ESM states (config/cga/state_machines.php 'case')
  stages: { type: Array, required: true },        // [{ index, title, state, content_blocks }] — server-rendered stage payloads
  stageStateMap: { type: Array, required: true }, // STAGE_STATE: stage index → ESM state (server-authored)
  interactive: { type: Boolean, default: false }, // the playable Back/Advance walkthrough (mockup demo affordance)
}
```

Render: `StateStrip :states="machine" :current="case.current_state"` (the live record state) · the 10-item `.lifecycle` ordinal track with `--done`/`--current` + `aria-current="step"` on the live stage · when `interactive`, the Back/Advance buttons replay/preview stages **as a read-only simulation** (the mockup's banner verbatim: *"Stage changes below are simulation only — the real case record is append-only and advances when the court acts"*) — `interactive` is OFF in product by default; the live record renders `current_stage` and the surrounding context, the playable mode is a dev/demo affordance like the CoDetScale explorer was in Phase D. Each stage panel renders its `content_blocks` (PanelTable for stage 3, evidence/motions DataTables for 4–5, the jury `Banner` + juror-view cross-link for stage 6, the chambers/jury-room locked cards for stage 8, the double-jeopardy `Banner--warning` for stage 9, the opinion `FormCard` + challenge cross-link for stage 10). **Append-only posture is the constitutional invariant**: the component never POSTs; stage advances are the court acting through F-JDG-* on the case-detail controller's own endpoints (R-19/R-20 gated), not a client toggle. Classes: `.lifecycle/--done/--current`, `.state-strip`, `.banner--demo/--warning/--info`, `.card--inset` — no new CSS.

### A.3 `Judiciary/Art4§5Tracker.vue` (`ChallengeTracker.vue`) — THE exit-criterion component

The constitutional-challenge centerpiece: the finding → remedy → window → three-path → direct-edit pipeline (Art. IV §5). **This is where the Phase E exit criterion is rendered.** Composes `StateStrip` (challenge ESM) + the F-JDG FormCards + the `ThresholdMeter`/`VoteTally` override meter + `LawDiff` (the `judicial_remedy` law version) + `Ui/Stepper` (the three-path pipeline) + two clock countdowns.

```js
props: {
  challenge: { type: Object, default: null },     // [DEP: law-remedy · constitutional_challenges]
  // null → empty-state. Else:
  // { id, name, law:{id, name, href}, filed_by_label, filed_at,
  //   court:{name}, is_major:bool, writing_judge:{name},
  //   state /* challenge ESM */,
  //   finding:{ form:'F-JDG-004', text },                       // Art. IV §5 finding
  //   remedy:{ form:'F-JDG-005', text,
  //            timeframe_days, timeframe_due_on, clk:'CLK-12',   // judge-set per-case window (clock_timers.override_value)
  //            veto_window_days, veto_closes_on, veto_clk:'CLK-11' },
  //   override:{ form:'F-LEG-035', vote: VoteTallyProps|null,    // supermajority of ALL serving
  //              required, serving, yes, closed:bool },
  //   resolution:'window_open'|'amended'|'overridden'|'applied', // the three paths' terminal
  //   remedy_diff:{ segments:[LawDiff segs], applied:bool,       // judicial_remedy law version
  //                 version_no, prior_version_no, history_href },
  //   enforcement:{ href:'/executives/.../actions' } }
}
```

Render (mockup `renderTracker()` grammar, verbatim copy preserved):
1. **Window banner** (`Banner--warning`): *"Legislative window open — override closes {veto_closes_on}"* + the two-clock line ("{timeframe_days} days to modify or remove (CLK-12, due {timeframe_due_on}) and {veto_window_days} days to override (CLK-11)") with the timezone citation.
2. **Challenge summary card**: name, law challenged, filed-by ("any inhabitant may file; no standing gatekeeper beyond jurisdictional association" — Art. IV §5), heard-by ("full court — all {n} judges · CLK-16" when `is_major`, `HardenedChip`) + the **F-IND-016 `FormCard`** (the filing instrument — the exit-criterion entry point).
3. **Challenge ESM `StateStrip`** with `current` mapped from `resolution` (`window_open`→`Legislative-Window-Open`; `applied`→`Law-Edited`; `overridden`→`Overridden`).
4. **Finding & remedy card** (`grid-2`): F-JDG-004 `FormCard` + the finding text; F-JDG-005 `FormCard` + remedy text + both clock chips. Gloss verbatim: *"The finding lands on the legislature as a mandatory session priority — constitutional matters precede the general agenda (WF-LEG-05)."* `[DEP: law-remedy — the finding sets clock_timers.override_value for CLK-11/CLK-12 per case]`
5. **The three paths** (the mockup's `auto-fit minmax(16rem,1fr)` grid; I use `Ui/Stepper` for the pipeline overview ABOVE the three path cards — steps `[Finding+Remedy → Legislative window → Resolved]` — then the three `Card`s):
   - **Path A — Legislature amends/removes** (F-LEG via the bill flow): status badge, deep-link to the amendment bill in committee (`/legislature/bills/{bill}` — Phase C surface, WF-LEG-06), "due within {timeframe_days} days · CLK-12".
   - **Path B — Supermajority override in the veto window** (F-LEG-035): the **override `VoteTally`** (`thresholdClass:'supermajority'`, `serving`, `requiredYes` = `ceil(serving × 2/3)` engine snapshot) OR the mockup's `ThresholdMeter`(`value=yes`,`max=serving`,`threshold=required`) with caption "needs {required} of {serving} · ceil({serving} × 2/3) · Art. VII"; gloss "Supermajority of all serving members — not just those present — recorded within the veto window"; on `overridden` the `Banner--info` "Judgement overruled — the law stands as written; the finding, the override vote, and every member's position are on the public record."
   - **Path C — Window closes, judiciary edits the law directly** (F-JDG-006): the **`LawDiff` rendering the `judicial_remedy` version** (`:segments="remedy_diff.segments"`) — del the old text, ins the court's remedy; F-JDG-006 `FormCard`; "opinions remain commentary on the law as written or edited · Art. IV §5"; on `applied` the `Banner--info` *"Curfew Ordinance §3 is edited to the text above; a new law version is published with the prior version retained in history"* + **the preserved-history link** (`remedy_diff.history_href` → the law's version list, `version_no` vs `prior_version_no` — the LawDiff substrate is append-only full-text, `LawVersion.SOURCE_JUDICIAL_REMEDY`).
6. **Enforcement banner** (`Banner--info`): *"Executives enforce the outcome — whichever path resolves"* + link to `/executives/{e}/actions` (WF-EXE-07 — cross-link to the Phase D surface).

Empty-state (mockup `renderEmpty()`): "No constitutional challenge is open in your jurisdictions" + F-IND-016 `FormCard` + the explainer. Classes: all already ported — no new CSS.

**Constitutional posture:** every threshold, the `required` override count, the CLK-11/CLK-12 due dates, and the `applied` boolean are **engine snapshots** `[DEP: law-remedy service]`; the component renders the record, it never decides the path. The mockup's `data-sim-*` buttons (simulate vote / simulate window close / reset) are demo affordances and **do not ship** — in product Path B advances through real F-LEG-035 casts on the Phase C vote endpoints, and Path C fires when CLK-11 expires (the engine writes the `judicial_remedy` `LawVersion`, the page re-renders it).

### A.4 `Judiciary/JurorScreening.vue` — the voir-dire conflict questionnaire (juror-view)

Composes `Ui/RadioGroup` + `Banner` (already ported). Port of juror-view.html `QUESTIONS[]` + the submit branch.

```js
props: {
  summons: { type: Object, required: true },     // [DEP: judiciary-services · jury_summonses]
  // { id, case:{id, title, href}, drawn_at, pool_size, report_at, location,
  //   seed_audit_href, service_state /* stepper position */ }
  questions: { type: Array, required: true },     // [{ id, text }] — server-authored, the 5 conflict questions
  can: { type: Object, required: true },          // { submitScreening }  (R-22 of THIS summons only)
}
```

Render: the 6-step service `Ui/Stepper` (`Summoned·done → Conflict-screening·active → Empaneled → Trial → Deliberation → Discharged`) · the summons facts (`grid-2`: drawn/pool/draw-integrity-with-audit-link/report/where + F-JDG-002 `FormCard` "Source of this summons") · the `RadioGroup`-per-question form (No/Yes, default No), submit → `POST /judiciary/jury/{summons}/screening` (F-? — see §B.6 note; in product the answer is a record on the case, R-22 gated). Result `Banner`: flagged → `--warning` "Flagged for voir dire review — a panel judge follows up; if a conflict is confirmed you are excused without penalty and the draw selects a replacement"; clean → `--info` "No conflicts declared — you remain in the panel pool." **Hardened civic-obligation card** verbatim (the two Art. II §8 protections — no interference, no fees — with `HardenedChip`). Locked deliberation-room card (`StatusBadge` "Locked — opens at deliberation"). Classes: `.stepper`, `.radio-group/.radio`, `.banner--*`, `.hardened`, `.grid-2` — no new CSS.

**Reused, not new (the bulk of the phase):** `ConstituentConsentPanel` (the elected-judiciary creation dual-supermajority — §B.1; the constituent meter = the counties, identical pairing to the exec-conversion it was built for), `LawDiff` (challenge remedy), `Stepper` (three-path pipeline, juror service), `StateStrip` (case + challenge ESMs — `config/cga/state_machines.php` gains `case`, `constitutional_challenge`), `LifecycleTracker` (optionally the challenge pipeline if a stage form is preferred over Stepper), `VoteTally` + `VoteCastList` (consent votes F-LEG-021, override F-LEG-035 — `body_type` chamber votes already supported), `Electoral/StvBar` + `StvRound` (elected-judge STV race results — judges elected "in the same manner as the legislature", reuse the PROTECTED count outputs wholesale), `Ui/{AmendableSetting (CLK-09/CLK-15/CLK-16 cards), ThresholdMeter, DataTable, FilterBar, ChipToggle, Stat, PersonaChip, LogRow, Banner, TagChip, StatusBadge, FormChip, HardenedChip}`, `Surface/{PageScaffold, FormCard, AboutSurface}`, `Shell/EmergencyBanner` (shell-global — judiciary surfaces get it free; the emergency banner on judiciary-home states "Courts cannot be disrupted by emergency powers — enforced in code · Art. II §7").

---

## B) PAGE SPECS — all 6

### Shared conventions (carried from Phase C/D, unchanged)

- Every page: `PageScaffold :surface` from `config/cga/surfaces.php` — **6 new entries**, ids = mockup ids: `judiciary/judiciary-home`, `judiciary/case-docket`, `judiciary/case-detail`, `judiciary/constitutional-challenge`, `judiciary/advocate-console`, `judiciary/juror-view`; `roles`/`workflows`/`forms`/`clocks`/`citation` copied from the mockup `CGA_PAGE` blocks (verified above). F-IND-013→F-IND-016 drift (CATALOG_DRIFT, WF-JUD-05) resolves through FormRegistry for display only, never auto-rewritten.
- Controllers in `app/Http/Controllers/Judiciary/`.
- **Thresholds/panel-sizes/required-counts never computed client-side** — `panel_size`, `is_full_court`, `required` (override/consent supermajority `ceil(n×2/3)`), the CLK-11/CLK-12 due dates are engine snapshots from `judicial_panels` / `chamber_votes` / `multi_jurisdiction_votes` / `clock_timers.override_value` rows `[DEP: judiciary-services + law-remedy]`.
- All POSTs through `ConstitutionalEngine::file()`; FormCard injects canonical `form_id`; 422 renders `Banner` + `Field` errors with citation.
- **PUBLIC-READ POSTURE (the defining Phase E rule).** Dockets, opinions, challenges, findings, panel assignments, and judiciary structure are **public record — Art. II §2 (Full Faith and Credit gives public Acts, Records, and Judicial proceedings, Art. V §2)**. Every judiciary surface is publicly readable by any authenticated resident; **actions gate by derived role** (R-19/R-20 judge forms, R-21 advocate forms, R-22 juror, R-03 filing) via `can.*` + engine 422 — never a 403 on the page itself. The single non-public space is deliberation (judges' chambers + jury room — case-detail stage 8: "the only unrecorded space; the verdict itself is recorded"): those render as **locked cards**, content access-controlled, never as page-level gates. The nav `court` section stays role-gated (it is the officeholder's launchpad — R-19/R-20/R-21/R-22); **public entry** is via Jurisdictions/Show CTAs, the public docket (`/judiciary/docket` — visibility for any associated resident), the challenge tracker (`/judiciary/challenges`), and direct case URLs. juror-view is the one exception that needs a real R-22 summons binding (you cannot answer another juror's questionnaire), but the case it belongs to is still public-readable via the docket.

### Entry resolver + nav integration (FE-E0)

- **`JudiciaryResolverController`** (`app/Http/Controllers/Judiciary/`, mirrors `ChamberResolverController`/`ExecutiveResolverController` exactly): `GET /judiciary[/{sub?}]` where `sub ∈ docket|challenges|advocate|jury` →
  1. viewer holds a seated judge seat (`judge_seats.holder_user_id`, status seated → R-19/R-20) → 302 to that judiciary's `/judiciaries/{id}/{sub}` (judiciary-home/docket/challenges);
  2. multiple judiciaries → the same with a chooser status line;
  3. no judge seat → the **deepest associated jurisdiction's judiciary** (public read — exactly the `publicReadExecutiveId` pattern: join `residency_confirmations` → `judiciaries` on `jurisdiction_id`, `orderByDesc('j.adm_level')`);
  4. none in the chain → 302 `/civic` with status *"No judiciary in your association chain yet — courts form when a legislature creates one · F-LEG-017 · WF-JUD-01"* (judiciary-home requires a non-null court; explain rather than render an empty bench, identical to the exec resolver's forming-only branch).
  - **`advocate` sub** resolves R-21 viewers to their own advocate console (`/judiciary/advocate` is a single per-viewer surface, not court-scoped — like a personal dashboard); non-advocates fall through to the public docket of the resolved judiciary with a status line ("Advocate registration is open to any associated resident · F-IND-015").
  - **`jury` sub** resolves R-22 viewers to their active summons (`/judiciary/jury/{summons}`); no active summons → status *"No active jury summons — you are summoned at random if drawn · Art. IV §4"* + the public docket.
  - Sub-paths map the nav's literal hrefs: `/judiciary → judiciary-home`, `/judiciary/docket`, `/judiciary/challenges`, `/judiciary/advocate`, `/judiciary/jury`.
- **nav.js**: the `court` section **already exists** (5 items, phase `'E'`, role-gated R-19/R-20/R-21/R-22, hrefs correct). FE-E0 only needs the detail-surface `nav:` mappings in `config/cga/surfaces.php`: `case-detail` → `case-docket`; `juror-view` keeps its own `juror-view`; `advocate-console`/`constitutional-challenge`/`judiciary-home` map to themselves. **Add a public docket affordance**: the `court` section is role-gated, but the docket + challenge tracker are public record — so add a `{ id:'public-docket', labelKey:'nav.publicDocket', icon:'scale', href:'/judiciary/docket', phase:'E' }` item to the `home` (`visibility:'all'`) or `jurisdictions` section so any resident reaches the public docket without holding a court role (mirrors how `org-registry` lives in an `all`-visibility section). Flip the `phase:'E'` items live via `phasesLive` at the final batch.
- Cross-links: `Jurisdictions/Show.vue` CTA row gains a **"Judiciary →"** link beside Chamber/District-map/Executive when the jurisdiction's judiciary exists (the public entry point — same pattern as the Phase D "Executive →" CTA, which is the most recent commit `7f4bc45`); `Executive/Actions.vue`'s `OrderScopeCard` "judicially reviewable · Phase E" planned-flag chip becomes a **live link** to a constitutional challenge / F-JDG-007 emergency-power review (closing the Phase D deferral #1); `Legislature/EmergencyPower` "judicial review" stub links to F-JDG-007; challenge-tracker Path A links the Phase C bill; case-detail ↔ juror-view ↔ advocate-console triangle per the mockup hrefs.

### B.1 `Judiciary/Home.vue` — surface `judiciary/judiciary-home`

Route `GET /judiciaries/{judiciary}` → `Judiciary\JudiciaryController@show`. Nav `judiciary-home`. Public read.

```js
props: { surface,
  judiciary: { id, name, type:'appointed'|'elected' /* DEFAULT appointed */,
    jurisdiction:{id,name,href}, legislature:{id,name,chamber_href},
    judges_on_bench, status /* judiciary ESM: forming|created|confirmed|operating|converted */,
    min_judges_per_race /* CLK-15, amendable, default 5 */ },               // [DEP: schema · judiciaries]
  panelRule: { rows:[{ severity, panel, rule }] },                          // the severity table (CLK-16) — server citations
  creation: { act:{ act_number, href, enacted_at, effective_on },          // F-LEG-017 record
              vote:{ tally: VoteTallyProps /* supermajority */, required, serving } } | null,
  nominations: [ { nominee:{name}, nominated_by:{jurisdiction|'judicial committee fallback'},
                   consent:{ summary:'248 of 272 serving', outcome:'confirmed'|'not_confirmed' },
                   term:{ starts_on, ends_on /* +10yr CLK-09 */ }|null } ],  // F-LEG-021 ×N
  conversion: { subjectLabel:'Conversion to an elected judiciary',
                legislatureVote: VoteTallyProps,
                process: ConstituentConsentPanelProps } | null,            // F-LEG-018 — dual supermajority
  term: { years /* 10 */, clk:'CLK-09', civilLockstep:'CLK-10', amendable:true },
  can: { proposeCreationBill, proposeConversionBill }                       // R-09 of the legislature — deep-links, not POSTs here
}
```

Composes: header (`type` badge — "Appointed is the default judiciary type · Art. IV §2" citation verbatim) · **"How this court sits" card**: `HardenedChip` + `StatusBadge` "{n} judges on the bench" + the **severity→panel `DataTable`** (PanelTable's `rule` column source — minor/moderate 3, serious 3–5 + jury, major-constitutional full court — all `CLK-16 · Art. IV §4`, the panel-size hard constraint) + the severity gloss verbatim · **"How this court was created" card**: F-LEG-017 `FormCard` + the creation-act `card--inset` (vote "213 of 272 serving · supermajority 182 of 272 · ceil(272 × 2/3) · Art. VII") + the **nomination explainer** ("equal numbers from every constituent jurisdiction; judicial committee as fallback when a constituent declines — the constitutional fallback · Art. IV §2") · **"Confirmation record" card**: F-LEG-021 `FormCard` + the consent-votes `DataTable` (nominee, nominated-by, consent summary, confirmed/not-confirmed `StatusBadge`, 10-yr term dates) + the gloss ("Confirmation needs the same supermajority as creation; counties keep nomination rights; replacement nominees go to a fresh consent vote when a seat next opens · WF-JUD-07") · **"Conversion to an elected judiciary" card**: F-LEG-018 `FormCard` + **`ConstituentConsentPanel`** (A.1 reuse — `legislatureVote` = the state legislature's own supermajority, `process` = the constituent counties' supermajority; subjectLabel "Conversion to an elected judiciary"; the panel's own gloss "Both meters must clear their threshold" IS the Art. IV §3 dual-supermajority rule) when a conversion exists/ran, else the F-LEG-018 `FormCard` + `can.proposeConversionBill` deep-link + the "if conversion passes, judges are elected in groups of at least 5 via STV · CLK-15" copy · **term-lockstep card**: `AmendableSetting` (value "10 years", `settingKey` judicial_appointment_years, "must stay in lockstep with civil appointments · CLK-09 · CLK-10 · Art. IV §4; Art. II §9") + "renewals re-run the nomination and consent process · WF-JUD-07".

Edge: most instances have **no judiciary** day-one (San Marino/Montegiardino/Earth have no court until F-LEG-017 passes) — the resolver 302s to the empty-state status before this page renders; where a `forming` judiciary stub exists, the creation `FormCard` reference is the day-one render. Conversion section renders the F-LEG-018 reference even with no live conversion (the dual-supermajority rule is the point). **`ConstituentConsentPanel` reuse note:** built for exec-conversion (Phase D, Art. III §3), it is structurally identical for judiciary-conversion (Art. IV §3) — same "legislature supermajority + constituent-jurisdiction supermajority" pairing; no component change, only a new `multi_jurisdiction_votes.kind` (`jud_office_create` — coordinate with `[DEP: judiciary-services · MultiJurisdictionVoteService kinds]`, mirrors `exec_office_create`).

### B.2 `Judiciary/Docket.vue` — surface `judiciary/case-docket`

Route `GET /judiciaries/{judiciary}/docket` (resolver `/judiciary/docket`) → `Judiciary\DocketController@index`. Nav `case-docket`. Public read; filing requires R-03 (or R-21 via advocate) — explained, never 403'd (CandidacyRegistration pattern).

POSTs: `/judiciaries/{j}/cases` (F-IND-017 civil/criminal filing, R-03/R-21).

```js
props: { surface,
  judiciary: { id, name, courts:[{name, level}] },
  stats: { open, by_kind:{ constitutional, civil, criminal, administrative } },
  cases: [ { id, docket_no, title, kind:'Constitutional challenge'|'Civil'|'Criminal'|'Administrative',
             court:{name}, panel:{ summary:'3 judges + jury'|'Full court'|'Pending acceptance' },
             severity:'Minor'|'Moderate'|'Serious'|'Major constitutional question'|'… (claimed)',
             state /* case ESM display state */, filed_via:'F-IND-017'|'F-ADV-001'|'F-IND-016',
             double_jeopardy_note:string|null, href } ],                    // [DEP: judiciary-services · cases]
  filters: { kinds:[…4], jurisdictions:[viewer chain] },
  machine: string[],                                                        // case ESM legend
  filingForm: { kinds:[Civil,Criminal,Administrative], scales:[viewer chain w/ adm labels],
                severities:[Minor,Moderate,Serious] },                      // F-IND-017
  acceptanceForm: { id:'F-JDG-001' },                                       // what the court does next (reference)
  entryForms: ['F-IND-016','F-ADV-001'],                                    // other ways cases arrive (reference cards)
  isAssociated: bool,
  can: { fileCase /* R-03 */ }
}
```

Composes: `Stat` tiles (open by kind) · `FilterBar` (kind `ChipToggle`s + search — case-docket.html `filters`) · **docket `DataTable`** (case link → `case-detail`/`constitutional-challenge` by kind, docket-no + filed-via `.citation`, double-jeopardy note when present, kind, court, panel summary + `.citation` gloss, severity, state `StatusBadge` — the mockup's `STATE_BADGE` map: Remedy-recommended=warning/Evidence-docket=info/Jury-selection=info/Deliberation=neutral/Filed=neutral) · **"File a case" card**: F-IND-017 `FormCard` form (kind select, **claimed scale** select from the viewer's jurisdiction chain with adm labels — "the jurisdiction whose law the case arises under, so the right court level hears it", **claimed severity** select with the hint verbatim "the court reclassifies severity at acceptance — panel size follows the court's classification, not yours", title, statement of claim); submit → success `Banner` "Filing accepted for review · docket {id} assigned · the court will classify justiciability and severity, then assign a panel with conflict screening · F-IND-017 → F-JDG-001 · Art. IV §4" **or 422** with citation · **"Other ways cases arrive" card**: F-IND-016 (`FormCard`, "constitutional challenge · R-03 · Art. IV §5") + F-ADV-001 (`FormCard`, "case filing on behalf of client · R-21") reference cards · **"What the court does next" card**: F-JDG-001 `FormCard` + the conflict-screening explainer verbatim ("every candidate judge is screened for personal, financial, or prior-involvement conflicts; conflicted judges are excluded and the draw re-runs; screening results attach to the case record · Art. IV §4 · hardened") + "walk a case through the full lifecycle →" link to `case-detail`. About panel: case ESM `StateStrip`.

Edge: `!isAssociated` → docket renders read-only, filing form replaced by residency CTA; zero cases → "No cases on this docket — anyone jurisdictionally associated can file (Art. I)".

### B.3 `Judiciary/CaseDetail.vue` — surface `judiciary/case-detail`

Route `GET /cases/{case}` → `Judiciary\CaseController@show`. Nav `case-docket`. Public read (proceedings are public record); per-stage actions gate by role (R-19/R-20 court orders, R-21 advocate filings).

POSTs (all R-gated, all through the engine — the court ADVANCES the record, no client toggle): `/cases/{c}/acceptance` (F-JDG-001 panel assignment, R-19/R-20) · `/cases/{c}/jury-orders` (F-JDG-002, R-19/R-20) · `/cases/{c}/opinions` (F-JDG-003) · `/cases/{c}/sentencing` (F-JDG-009) · `/cases/{c}/warrants` (F-JDG-010) · advocate filings route through the advocate console / the case (F-ADV-002 motion, F-ADV-003 evidence, F-ADV-004 brief — §B.5).

```js
props: { surface,
  case: { id, docket_no, title, kind, severity, court:{name},
          double_jeopardy:bool, jury_entitled:bool,
          current_stage:1..10, current_state, accusation, filed_at, filed_by_label },
  machine: string[],                                                        // case ESM
  panel: PanelTableProps,                                                   // A.1 — conflict-screened bench
  stages: [ { index, title, state, blocks:[…server-rendered] } ],          // CaseLifecycle stage payloads (A.2)
  motions: [ { motion, filed_by, ruling:'granted'|'denied', reasons_href } ],
  evidence: [ { exhibit, description, submitted_by, admissibility:'admitted'|'excluded', reason|null } ],
  jury: { drawn:bool, jurors, alternates, pool_size, seed_audit_href }|null,
  forms: ['F-IND-017','F-JDG-001','F-ADV-002','F-ADV-003','F-JDG-002','F-JDG-003','F-JDG-009','F-JDG-010'],
  can: { /* the court / advocate capabilities, role-derived per stage */ }
}
```

Composes: header badges (kind, severity `Banner`-tone, "{panel} + jury" when entitled, court) + the double-jeopardy citation ("criminal outcome will carry the double-jeopardy flag · Art. II §8") · **`CaseLifecycle`** (A.2 — the case ESM `StateStrip` + the 10-stage `.lifecycle` track + per-stage panels: stage 3 = `PanelTable`; stage 4 = motions `DataTable`; stage 5 = evidence `DataTable`; stage 6 = the jury `Banner--info` ("Random draw complete — voir dire under way; {jurors}+{alternates} drawn at random from {pool} eligible; the selection seed is published to the audit chain") + F-JDG-002 `FormCard` + "see this stage as a summoned juror →" juror-view link; stage 8 = the two locked `card--inset` chambers/jury-room cards ("Deliberation is the only unrecorded space; the verdict itself is recorded"); stage 9 = the double-jeopardy `Banner--warning` ("the outcome record carries the double-jeopardy flag: the accused can never be prosecuted again for this same accusation; machine-enforced at filing time · Art. II §8") + F-JDG-009/F-JDG-010 `FormCard`s; stage 10 = F-JDG-003 `FormCard` + "opinions are commentary on the law as written or edited; only the Art. IV §5 process can change the law's text" + challenge-tracker cross-link). About panel: WF-JUD-03/04 + the case ESM.

Edge: case at an early state → later stages render as previews (the lifecycle track greys un-reached stages); major constitutional question → `PanelTable` `isFullCourt` (all judges, no jury), the docket entry would instead link `constitutional-challenge`.

### B.4 `Judiciary/Challenge.vue` — surface `judiciary/constitutional-challenge` ← **EXIT-CRITERION surface**

Route `GET /judiciaries/{judiciary}/challenges[/{challenge}]` (resolver `/judiciary/challenges`) → `Judiciary\ChallengeController@show`. Nav `constitutional-challenge`. Public read (findings + remedies + every member's override position are public record — Art. IV §5); filing gated R-03; override votes cast in the legislature (R-09).

POSTs: `/judiciaries/{j}/challenges` (F-IND-016 filing, R-03) · F-JDG-004/005/006 are court actions (R-19/R-20, filed from the case the challenge rides) · F-LEG-035 override is cast on the Phase C vote endpoints (R-09).

```js
props: { surface,
  judiciary: { id, name },
  challenge: ChallengeTrackerProps | null,                                 // A.3 — the whole pipeline (null → empty state)
  machine: string[],                                                       // constitutional_challenge ESM
  can: { fileChallenge /* R-03 */ }
}
```

Composes: **`Judiciary/Art4§5Tracker` (A.3)** — the entire surface IS the tracker component. The controller hydrates `challenge` from the live `constitutional_challenges` row joined to its finding (F-JDG-004), remedy (F-JDG-005 + the CLK-11/CLK-12 windows from `clock_timers.override_value`), override vote (F-LEG-035 `chamber_votes`), and — when Path C fires — the `judicial_remedy` `LawVersion` `[DEP: law-remedy]`. Empty-state renders the F-IND-016 `FormCard` + explainer.

**THE EXIT-CRITERION SURFACE CHAIN (the deliverable's core requirement):** see §C.1 below for the full F-IND-016 → finding → remedy → window → three-path → direct-edit walkthrough rendered through this surface with the `LawDiff` showing the `judicial_remedy` version + preserved history.

Edge: empty-state is the day-one render everywhere (no findings exist until a court issues one). The mockup's "Load the Novák drill" button is a demo affordance (sets the `challenge` scenario flag) — in product it becomes a seed/demo entry (`judiciary:demo` parallel to `elections:demo`/`institutions:demo-d`), not a live page button.

### B.5 `Judiciary/AdvocateConsole.vue` — surface `judiciary/advocate-console`

Route `GET /judiciary/advocate` → `Judiciary\AdvocateController@show` (per-viewer surface, resolver-bound to the R-21 viewer). Nav `advocate-console`. Public read of the four-instrument explainer + registration form; the viewer's own case list + filings gate to the R-21 holder.

POSTs: `/advocate/registration` (F-IND-015, R-03 — registration confers R-21) · `/cases/{c}/filings` with a `form` discriminator: F-ADV-001 (new case on behalf of client), F-ADV-002 (motion), F-ADV-003 (evidence), F-ADV-004 (brief) — all R-21, all stage-gated by the engine (motions before/during hearing, evidence on the open docket, briefs until deliberation).

```js
props: { surface,
  advocate: { is_registered:bool, persona:{name}, granted_at|null,
              judiciary:{name}, practice_scope:'every court of … and its constituent counties' }|null,
  registrationForm: { id:'F-IND-015' },                                    // [DEP: judiciary-services · advocate_registrations]
  myCases: [ { id, title, kind, court, panel, state, next_action, href } ],// cases filed via F-ADV-001
  filings: [ { seq, form:'F-ADV-001|2|3|4', case:{id,title}, text, when, status:'docketed' } ],
  composer: { types:[F-ADV-001..004], casesForClient:[…myCases] },
  forms: ['F-IND-015','F-ADV-001','F-ADV-002','F-ADV-003','F-ADV-004'],
  can: { register /* R-03 */, file /* R-21 */ }
}
```

Composes: **registration card**: `StatusBadge` "Registered advocate" + grant date + practice scope, or F-IND-015 `FormCard` when unregistered (the constitutional framing verbatim: "Representation is a constitutional right of your clients; registration keeps the bar of advocates zealous and competent · Art. IV §4 · Art. I") + WF-CIV-07 link · **"Your active cases" card**: `card--inset` per case (title, id·kind·court·panel `.citation`, state `StatusBadge`, `NEXT_ACTION` line — "Evidence docket open — submissions accepted (F-ADV-003)" / "In deliberation — no filings accepted" / "Voir dire under way — challenge motions only (F-ADV-002)") · **"New filing" composer**: filing-type select (F-ADV-001..004), case select (hidden for F-ADV-001, which shows a client field instead), summary; the hints verbatim per type; submit → success `Banner` ("{form name} accepted · docketed to the case and visible to all parties and the panel" / for F-ADV-001 "a case record is created and queued for acceptance and panel assignment (F-JDG-001) with conflict screening") **or 422** · **"Recent filings" `LogRow` list** (seq, form chip, text + case citation, when, "Accepted · docketed" `StatusBadge`) · **"Your four instruments" card**: F-ADV-001/002/003/004 `FormCard` grid. About panel: WF-CIV-07 + WF-JUD-03, the case ESM (filings attach at specific states).

Edge: unregistered viewer → registration `FormCard` + empty case list ("Cases you file on behalf of clients appear here"); the four-instrument explainer renders regardless (public read).

### B.6 `Judiciary/JurorView.vue` — surface `judiciary/juror-view`

Route `GET /judiciary/jury/{summons}` → `Judiciary\JurorController@show` (resolver `/judiciary/jury` → the viewer's active summons). Nav `juror-view`. The case is public-readable; **the screening questionnaire binds to the R-22 summons-holder** (you cannot answer another juror's questionnaire — the one real per-record gate in the phase, and even then the underlying case stays public via the docket).

POSTs: `/judiciary/jury/{summons}/screening` (the voir-dire answers — a record on the case attached to this summons, R-22 of THIS summons). *Note: the screening submission has no dedicated F-* form in the catalog (juror answers are not a constitutional instrument — they are a record the court reads); it rides a thin controller endpoint, not `ConstitutionalEngine::file()`. The summons itself was created by F-JDG-002 (the judge's Jury Selection Order). Flagged for the q-ledger: confirm whether juror screening answers warrant a catalog form or stay a plain record.*

```js
props: { surface,
  summons: JurorScreeningProps['summons'],                                 // A.4 — [DEP: judiciary-services · jury_summonses]
  questions: [{ id, text }],                                               // the 5 conflict questions (server-authored)
  serviceState: 'summoned'|'conflict_screening'|'empaneled'|'trial'|'deliberation'|'discharged',
  deliberationRoom: { unlocked:bool },                                     // unlocks only at the deliberation state
  can: { submitScreening /* R-22 of this summons */ }
}
```

Composes: header (case title + "Jury of peers · Art. IV §4 — service protected · Art. II §8") · the 6-step service `Ui/Stepper` (driven by `serviceState`) · **"The summons" card** (`grid-2`: drawn/pool/draw-integrity-with-audit-chain-link/report/where + F-JDG-002 `FormCard` "Source of this summons" + "see the case this summons belongs to →" case-detail link) · **`JurorScreening` (A.4)** — the conflict questionnaire (`RadioGroup` per question, No/Yes default No) + submit branch (flagged → `--warning` voir-dire review / clean → `--info` "you remain in the panel pool") · **"Your service is protected" card** (`HardenedChip` + the two Art. II §8 protections verbatim — no interference, no fees — "no payment, fee, or fine can be required of you to exercise a civic right or fulfill this obligation; attendance, filings, and verification cost you nothing") · **"Jury deliberation room" card** (`StatusBadge` "Locked — opens when the case reaches deliberation"; disabled "Enter deliberation room" button until `deliberationRoom.unlocked`; "the only unrecorded space in the trial — the verdict itself is recorded · Art. IV §4"). About panel: WF-JUD-04 + the case ESM (service spans [Jury-Empaneled] → Deliberation → Decided).

Edge: no active summons → the resolver 302s to the docket with a status line (you reach this page only with a real summons); discharged summons → the stepper shows all done, the questionnaire renders read-only with the recorded answers.

---

## C) EXIT-CRITERION SURFACES

**1. F-IND-016 filing on `constitutional-challenge` → the finding / remedy / window / direct-edit lifecycle rendered with the `LawDiff` showing the `judicial_remedy` version + preserved history. (THE Phase E frontend exit criterion.)**

Surface chain — every screen in the walkthrough:
- **`Judiciary/Challenge.vue`** empty-state composer → `POST /judiciaries/{j}/challenges` (F-IND-016, R-03 — "any inhabitant may file; no standing gatekeeper beyond jurisdictional association · Art. IV §5") → a `constitutional_challenges` row, Filed state. `[DEP: law-remedy · constitutional_challenges + the F-IND-016 handler]`
- The challenge rides the case lifecycle (WF-JUD-03 machinery); a **major constitutional question takes the full court** → `PanelTable isFullCourt` on the case, the challenge summary card reads "heard by the full court — all {n} judges · CLK-16" + `HardenedChip`.
- The court issues the **finding (F-JDG-004)** and **remedy recommendation (F-JDG-005)** → the `Art4§5Tracker` renders both `FormCard`s + the finding/remedy text + **the two clocks set per case**: CLK-12 (remedy timeframe, "due {timeframe_due_on}") and CLK-11 (veto window, "closes {veto_closes_on}") — these are the **judge-set per-case windows stored in `clock_timers.override_value`** (the slot Phase A pre-provisioned with the comment "Phase E per-case override slot — window set by the judiciary"). The challenge ESM `StateStrip` shows `Finding+Remedy-Issued → Legislative-Window-Open`.
- **Three paths render side by side** (the `Stepper` overview + three `Card`s):
  - **Path A** (legislature amends/removes) deep-links the Phase C amendment bill (WF-LEG-06) — "due within {timeframe_days} days · CLK-12".
  - **Path B** (F-LEG-035 supermajority override) renders the **override `VoteTally`** (`thresholdClass:'supermajority'`, `requiredYes` = engine `ceil(serving × 2/3)` — NEVER client math, the same pure-renderer discipline `ConstituentConsentPanel` enforces); on override, the `Banner--info` "Judgement overruled — the law stands as written; the finding, the override vote, and every member's position are on the public record."
  - **Path C** (window closes → judiciary edits the law directly, F-JDG-006) renders the **`LawDiff` of the `judicial_remedy` law version**: `:segments` server-computed (`App\Support\TextDiff::segments`), del the contradictory text / ins the court's remedy. On `applied`: the `Banner--info` "a new law version is published with the prior version retained in history" + **the preserved-history link** to the law's version list (`version_no` vs `prior_version_no`; `LawVersion.SOURCE_JUDICIAL_REMEDY`, full-text append-only — the substrate Phase C built for exactly this). `[DEP: law-remedy · F-JDG-006 handler appends the LawVersion with source='judicial_remedy', source_ref_type/id pointing at the challenge]`
- **Enforcement banner**: "Executives enforce the outcome — whichever path resolves" → cross-link to `/executives/{e}/actions` (WF-EXE-07).

Verification (the acceptance walkthrough, mirroring Phase D's three-screen rejected-order chain): file F-IND-016 → finding/remedy render with both CLK-11/CLK-12 windows live → simulate the override fall-short → window closes → F-JDG-006 fires → the `LawDiff` shows the `judicial_remedy` version with del/ins → the history link lands on a version list showing the prior version preserved + `audit:verify` green over the new `LawVersion` hash. The whole pipeline is rendered through `Art4§5Tracker`; the `judicial_remedy` source value is asserted in the law-version list.

**2. F-LEG-018 conversion to elected judiciary → the dual-supermajority rendered by `ConstituentConsentPanel`.**
Surface chain: `Judiciary/Home.vue` conversion card → `ConstituentConsentPanel` (A.1, the SAME component Phase D built for exec-conversion) with `legislatureVote` = the legislature's own supermajority (a `chamber_votes` row, F-LEG-018 vote) and `process` = the constituent counties' supermajority (a `multi_jurisdiction_votes` row, kind `jud_office_create`). Both meters must independently clear `ceil(n × 2/3)` (the panel's own gloss IS the Art. IV §3 rule). On both-passed: the conversion act passes, I-JUD → I-JDE, a judicial election is scheduled (WF-ELE-09) with judges in groups of ≥5 per race (CLK-15) counted by the **PROTECTED `VoteCountingService` STV** — rendered on the Phase B election surfaces wholesale + the elected-judge `StvBar` results. `[DEP: judiciary-services · MultiJurisdictionVoteService kind jud_office_create + a new election kind/electorate for judges, exactly as Phase D board elections added org_board_owner/worker]`

**3. BoG-pattern is not in Phase E** — but the **F-LEG-017 → F-LEG-021 consent → seated bench** chain (appointed judges) reuses the Phase D BoG `VoteTally` + 10-yr CLK-09 term pattern: judiciary-home's confirmation `DataTable` renders the F-LEG-021 consent votes (supermajority, not the BoG majority — judges confirm at the same supermajority as creation per Art. IV §2) and the 10-yr terms (CLK-09, civil-lockstep CLK-10). The two-clock contrast Phase D made visible (governors CLK-09 vs worker-elected lockstep) here is the appointed-vs-elected judge contrast (appointed = 10-yr CLK-09; elected = lockstep CLK-10 to the general election).

---

## D) WORK-ITEM BREAKDOWN

**Kit decision: extend the existing `/dev/legislature-kit` OR add `/dev/judiciary-kit`** — DECISION: a small **`/dev/judiciary-kit`** (`resources/js/Pages/Dev/JudiciaryKit.vue`), one harness per phase (the established `/dev/electoral-kit`, `/dev/legislature-kit`, `/dev/executive-kit` pattern); nav dev section gains the item (gated on `import.meta.env.DEV`). The new components are few (PanelTable, CaseLifecycle, Art4§5Tracker, JurorScreening) so the kit is light — most of the phase is composition over already-kitted components. Backend WI names referenced for deps (align at merge with the Phase E backend designs): **E-JUD** (judiciaries table + F-LEG-017 creation + F-LEG-021 consent + 10-yr CLK-09 + F-LEG-018 conversion + MJV `jud_office_create` + elected-judge election kind), **E-CASE** (cases + panels/panel_seats + F-IND-017/F-ADV-001 filing + F-JDG-001 acceptance/conflict-screening + CLK-16 panel sizing + case ESM), **E-JURY** (jury_summonses + F-JDG-002 + voir-dire screening records + WF-JUD-04 random draw + seed-to-audit), **E-CHAL** (constitutional_challenges + F-IND-016 + F-JDG-004/005/006 + CLK-11/CLK-12 per-case windows via clock_timers.override_value + F-LEG-035 override + the judicial_remedy LawVersion append + challenge ESM), **E-ADV** (advocate_registrations + F-IND-015 + F-ADV-002/003/004 stage-gated filings), **E-OUT** (F-JDG-003 opinions + F-JDG-009 sentencing + F-JDG-010 warrants + double-jeopardy flag + F-JDG-007 emergency-power review + F-JDG-008 petition review).

### Group 0 — zero-backend (day 1)

| WI | Item | Size | Depends | Verification |
|---|---|---|---|---|
| **FE-E0** | 6 surface entries in `config/cga/surfaces.php` (ids = mockup ids) · `case-detail nav:case-docket` + public-docket nav item (visibility `all`) · state-machine config (`case`, `constitutional_challenge` — the ESMs from case-docket/constitutional-challenge mockups) · `JudiciaryResolverController` skeleton (4-branch + advocate/jury sub specialization, mirror of ExecutiveResolverController) · routes group (judiciary GETs + POSTs, mirror of the executive route block) | S | — | SurfaceMeta test extends to the 6 ids (F-IND-013→F-IND-016 drift display asserted); resolver unit test (judge seat → 302, public → deepest associated judiciary, no-court → /civic empty state, R-21 → advocate console, R-22 → summons); zero CSS append (the §A.0 finding — assert nothing added to components.css) |
| **FE-E1** | Component kit, fixture-first on `/dev/judiciary-kit`: **PanelTable** (3-judge serious w/ 1 recusal + full-court major + pending), **CaseLifecycle** (State v. Whitfield 10-stage fixture, current_stage 6 jury selection; major-constitutional variant), **Art4§5Tracker** (Novák/Curfew §3 fixture: window-open / overridden / applied × 3 + empty state), **JurorScreening** (clean + flagged), plus the surface-level reuse wiring of ConstituentConsentPanel for judiciary conversion (NY State 8/9-leg + 40/62-county fixture from judiciary-home.html) | L | FE-E0 | Vitest: PanelTable renders ONLY server `panelSize`/`isFullCourt` (feed `panelSize=3` w/ a "major" severity, assert no client override); Art4§5Tracker renders the override `required` from props only (feed `required=99`, assert no client ceil); LawDiff path-C renders `judicial_remedy` segments verbatim; CaseLifecycle aria-current on the live stage; JurorScreening flagged-branch trigger + radiogroup a11y. Browser pass: keyboard lifecycle Back/Advance, RTL + pseudo-locale spot check |

### Group A — judiciary spine (sequential; carries the exit criterion via FE-E5)

| WI | Item | Size | Depends | Verification |
|---|---|---|---|---|
| **FE-E2** | Judiciary/Home + JudiciaryController + resolver wiring + cross-links (Jurisdictions/Show "Judiciary →" CTA; Executive/Actions "judicially reviewable" → live link) | M | E-JUD, FE-E1 | curl props: a jurisdiction w/ no court → resolver 302s to /civic empty state; seed F-LEG-017 creation act → "How this court was created" card w/ supermajority vote; seed F-LEG-021 consents → confirmation DataTable w/ 10-yr terms; seed a conversion process → ConstituentConsentPanel shows live county-consent rows matching `multi_jurisdiction_votes`; judge member → `/judiciary` 302s to the bench |
| **FE-E3** | Docket + CaseDetail + DocketController + CaseController + filing/acceptance/jury-order/opinion/sentencing/warrant endpoints | L | E-CASE, FE-E2 | file F-IND-017 → docket row "Pending acceptance"; F-JDG-001 acceptance → PanelTable w/ conflict screening (assert `panelSize`/`isFullCourt` = engine snapshot, recused judge excluded + draw re-run); criminal verdict → double-jeopardy flag Banner asserted; major constitutional question → full court (no jury); opinion published → case ESM Opinion-Published + challenge cross-link |
| **FE-E4** | JurorView + JurorController + screening endpoint; jury order (F-JDG-002) integration on CaseDetail stage 6 | M | E-JURY, FE-E3 | F-JDG-002 jury order → summons created w/ seed published to audit chain (assert seed_audit_href resolves); juror screening clean → "remain in pool"; flagged → voir-dire review; deliberation room locked until the deliberation state; non-summons-holder cannot POST another's screening (engine/ownership 422) |
| **FE-E5** | Challenge + ChallengeController (**THE exit-criterion surface**) — Art4§5Tracker wired to live constitutional_challenges + F-IND-016/F-JDG-004/005/006/F-LEG-035 | L | E-CHAL, FE-E2 (+ E-CASE for the hearing) | **exit-criterion walkthrough**: file F-IND-016 → Filed; court issues finding (F-JDG-004) + remedy (F-JDG-005) → both CLK-11/CLK-12 windows render from `clock_timers.override_value`; Path B override falls short of `ceil(serving×2/3)` (assert required = engine, not client); window closes → F-JDG-006 → LawDiff shows the `judicial_remedy` version (del/ins) + history link lands on the version list w/ prior version preserved; `audit:verify` green over the new LawVersion hash; enforcement cross-link to executive actions |
| **FE-E6** | AdvocateConsole + AdvocateController + registration (F-IND-015) + stage-gated filing endpoints (F-ADV-002/003/004) | M | E-ADV, FE-E3 | register (F-IND-015, R-03) → R-21 conferred (assert on the role derivation); file F-ADV-001 → new case queued for F-JDG-001; F-ADV-003 evidence on the open docket → docketed; F-ADV-004 brief after deliberation → engine 422 (stage gate); recent-filings LogRow list reflects each |

### Flip + critical path

- **phasesLive**: `HandleInertiaRequests.php:73` → `['A','B','C','D','E']` with the **final landing batch** (FE-E5 or whichever lands last); until then individual pages ship behind their routes with the `court` nav items still `Planned · Phase E` (mechanism unchanged from C/D). The `/system/clocks` nav item (currently `phase:'E'`, parked there at the D flip), `/system/amendments` (`phase:'E'`), and the `/civic/learn` item should be reconciled in FE-E0: clocks becomes a trivial read-only DataTable over the `clocks` registry if end-of-phase slack exists (it now carries CLK-11/CLK-12/CLK-15/CLK-16 — judiciary-relevant), else re-flag to F; amendments stays F unless slack. None are part of the 6-screen contract.
- Critical path: **FE-E0 → FE-E1 → FE-E2 → FE-E3 (panels + double-jeopardy) → FE-E5 (THE exit criterion)**; jury fork **FE-E3 → FE-E4**; advocate fork **FE-E3 → FE-E6**. The fixture-first kit means the Art4§5 pipeline (finding → remedy → three-path → LawDiff), the panel conflict-screening, the case lifecycle, and the juror screening are pixel/a11y/unit-verified before any Phase E backend exists — page WIs are wiring. **The phase is unusually light on new components** (4) because Phases C/D already built the dual-supermajority panel, the LawDiff, the Stepper, the StateStrip, and the lifecycle tracker for exactly these surfaces — the §A.0 zero-CSS finding is the headline.

### Deferrals (justified)

1. **F-JDG-007 Emergency Powers Review + F-JDG-008 Petition Constitutional Review** — both are court actions that ride the case/challenge machinery; their entry points are **cross-links not standalone surfaces** in the 6-screen contract (F-JDG-007 from `Legislature/EmergencyPower` "judicial review" stub + the judiciary-home emergency banner; F-JDG-008 from `Civic/Petitions`). The case/challenge components render them when they arise; bespoke review surfaces are post-E polish. (Closes the Phase C/D "judicial review · Phase E" stubs by making them live links.)
2. **Appeals / wider-panel re-entry ([Appealed] state)** — the case ESM carries the `Appealed` state and case-detail stage 10 names it ("an appeal, where available, re-enters the lifecycle at a wider panel"); the appeal-filing surface is a thin variant of F-IND-017/F-ADV-001 and is post-E (the constitution's "all other Judgements can be overturned only by proven contradictions in law and errors · Art. II §8" needs a dedicated grounds form — flagged, not built).
3. **Judicial dissolution path (WF-ORG-10)** — the Phase D `TransfersConversions` deferral #2 lands here as a court-order outcome on a CGC/org dissolution case; it routes through the case lifecycle + an F-JDG-003 opinion, no new surface. Coordinate with `[DEP: judiciary-services]` for the dissolution-case kind; the org-side surface already renders the planned-flag.
4. **Juror screening as a catalog form** — the voir-dire answers ride a thin controller endpoint, not `ConstitutionalEngine::file()` (juror answers are a record the court reads, not a constitutional instrument). Flagged for the q-ledger (§B.6). If the registry gains an F-JDG-* for it, JurorScreening's submit re-points to the engine with zero component change.
5. **Full Faith & Credit cross-jurisdiction judgment sync** (`ffc_synced_at` on judgments) — Phase F federation (Art. V §2 gives FF&C to judicial proceedings across jurisdictions); the case record renders the column honestly ("syncs on federation · Phase F") and stays null on a single instance, exactly as Phase D's transfer FF&C chip does.
6. **Elected-judiciary election UI** — judges "elected in groups of at least 5 in the same manner as the legislature" (Art. IV §3–4) reuse the Phase B election surfaces wholesale (a ≥5-seat STV race like any board/committee race); no live fixture exists until an instance converts, so Judiciary/Home renders the conversion in the About/conversion card only, and the elected-judge results render via the existing `StvBar`/`StvRound` on the Phase B Results surface. The appointed path (F-LEG-017/021) is fully specced.
7. **Per-case window fine a11y on Art4§5Tracker** — the two CLK-11/CLK-12 countdowns render as static due-date citations + `ThresholdMeter` progress; a live ticking countdown is polish, flagged for the all-phases pass (consistent with the Phase D range-slider deferral).
