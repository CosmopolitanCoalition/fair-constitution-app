# PHASE D FRONTEND — 11 SCREENS + COMPONENTS

Worktree root: `E:\fair-constitution-app\.claude\worktrees\practical-payne-17d537\`. Verified on disk before writing: `resources/css/cga/components.css` (986 lines), `resources/js/Components/{Ui×27, Electoral×8, Legislature×4, Civic×1, Surface×3, Shell×6}`, `resources/js/Navigation/nav.js` (executive + organizations sections already exist, phase-D-flagged, role-gated), `config/cga/surfaces.php` (382 entries-lines; record shape confirmed), `resources/js/Pages/Dev/{ElectoralKit,LegislatureKit}.vue`, `app/Services/MultiJurisdictionVoteService.php` (`open/recordConsent/evaluate`), `app/Services/Legislature/ChamberActService.php` (`resolveConsentVote` — the BoG consent lane exists), `database/migrations/2026_04_25_000002_create_executives_tables.php`, `2026_01_01_000003_create_organizations_table.php`, all 11 mockups in `mockups/executive/` + `mockups/organizations/`, and `Pages/Legislature/BillDetail.vue` (already renders a constituent-consent card from a `constituentProcess` prop — the seed of the dual-supermajority component).

---

## A) COMPONENT SPECS

### A.0 CSS audit (grep of all 11 Phase D mockups vs `resources/css/cga/components.css`)

**Every class the 11 screens use is already ported**: `.meter/.meter-fill(--met)/.meter-threshold/.meter-caption/.meter-block`, `.stv-round/.stv-cand(--elected/--eliminated)/.stv-cand-name/.stv-track/.stv-fill(--elected/--transfer)/.stv-quota-mark/.stv-votes` (lines 704–721), `.log-row/--rejected/.log-seq`, `.state-strip/.state-node(--current)/.state-arrow` (537–543), `.lifecycle/.lifecycle-stage(--done/--current)` (552–561), `.stepper/.stepper-step(--done/--active)` (487–495), `.receipt` (726), `.amendable/.amendable-value/.amendable-meta`, `.hardened`, `.form-chip/.form-id`, `.tag-chip`, `.org-chip/.org-type`, `.persona-chip/.avatar`, `.stat(--accent)`, `.banner--emergency/--info/--warning`, `.table/.table-wrap`, `.chip-toggle`, `.grid-2/.cluster/.stack`, `.gloss/.citation/.eyebrow/.cc-small/.mono`. The only unported class is `.demo-control` (org-registry filter labels) — mockup-only, not product.

**Append one block** (`/* executive + organizations additions */`, end of components.css — same posture as Phase B/C appends, zero hex, logical properties only):

1. `.range-input { inline-size: 100%; accent-color: var(--gov-primary); }` — the co-determination slider (mockup inlines this).
2. `.meter--lg { block-size: .875rem; }` — the larger scale-meter track (mockup inlines `block-size:.875rem`).
3. `.seat-dot--worker { fill: color-mix(in oklch, var(--status-info) 55%, var(--gov-bg)); stroke: var(--status-info); }` and flat-strip variant `.seat-pip--worker { background: color-mix(in oklch, var(--status-info) 45%, var(--gov-bg)); border-color: var(--status-info); }` — worker-class seats in board strips (color never the only signal: every pip carries an aria-label + the strip renders a text legend).
4. `.board-strip { display: flex; flex-wrap: wrap; gap: var(--space-1); align-items: center; }` + `.seat-pip { inline-size: 1rem; block-size: 1rem; border-radius: 50%; border: 1px solid var(--gov-border-strong); background: color-mix(in oklch, var(--gov-primary) 45%, var(--gov-bg)); } .seat-pip--vacant { background: var(--gov-surface-2); border-style: dashed; } .seat-pip--chair { box-shadow: 0 0 0 2px var(--cc-gold-400); }` — board composition strip (no SVG; the circular SeatMap stays a chamber-only artifact).

Nothing else.

### A.1 `Legislature/ConstituentConsentPanel.vue` — THE dual-supermajority component (the `multi_jurisdiction_votes` UX)

Promotes BillDetail's inline constituent-consent card (BillDetail.vue ~line 301) into a shared component; BillDetail migrates to it (only call-site change in Phase C code). Composes the existing `VoteTally` + `ThresholdMeter` + `DataTable` — it adds the *pairing*, not new meter math.

```js
props: {
  // Meter 1 — the initiating legislature's own supermajority (a chamber_votes row)
  legislatureVote: { type: Object, required: true },   // full VoteTally props (mode, thresholdClass:'supermajority'|'bicameral_supermajority', serving, requiredYes, tallies, kinds, outcome)
  legislatureLabel: { type: String, required: true },  // "San Marino legislature"
  // Meter 2 — the constituent-jurisdiction supermajority (multi_jurisdiction_votes row)
  process: { type: Object, required: true },
  // { id, kind, status:'open'|'passed'|'failed'|'expired', total, required,   ← required = engine ceil(total×2/3), NEVER client math
  //   yes, no, pending, closes_at|null,
  //   consents: [{ jurisdiction:{id,name,adm_chip}, result:'pending'|'yes'|'no',
  //                chamber_vote: {href, summary:'6 of 8 serving'}|null, decided_at|null }] }
  basis: { type: String, default: 'Art. III §3 · Art. VII' },
  subjectLabel: String,                                 // "Conversion to elected individual office"
}
```

Render: heading + two stacked `card--inset` blocks. Block 1 = `VoteTally` with caption override "{legislatureLabel}: own supermajority". Block 2 = `ThresholdMeter value=yes max=total threshold=required` + caption grammar verbatim from executive-home.html lines 76–79: left `"Constituent jurisdictions: {yes} of {total} in favor ({first 7 names} + {n} more)"`, right `"threshold {required} = ceil({total} × 2/3) · Art. III §3"`; below it the consents `DataTable` (jurisdiction AdmChip, result StatusBadge — pending=neutral/yes=success/no=danger, link to that constituent legislature's own chamber-vote record — "each constituent legislature votes as a body"). Footer gloss verbatim: *"Both meters must clear their threshold — the legislature's own supermajority and a supermajority of the constituent jurisdictions, each counted independently."* Combined-outcome Banner from `process.status` + `legislatureVote.outcome` (both adopted → success; either failed → danger naming the failing leg). Mockup classes: `.meter-block/.meter/.meter-fill--met/.meter-threshold/.meter-caption`, `.card--inset`, `.gloss`, `.badge--success`, `.citation`. No new CSS.

Used by: `Executive/Home` (F-LEG-015 conversion), `Legislature/BillDetail` (any `dual_supermajority` act — existing card replaced), forward by Phase E (F-LEG-018) and F (F-LEG-028/029) — design once here.

### A.2 `Org/CoDetScale.vue` — the co-determination scale visual (port of co-determination.html lines 27–58 + `workerSeats()/nextStep()`)

```js
props: {
  workers:    { type: Number, required: true },         // live: COUNT(org_workers WHERE ended_at IS NULL) — server
  ownerSeats: { type: Number, required: true },         // boards.owner_seats
  workerSeats:{ type: Number, required: true },         // boards.worker_seats — THE ENGINE'S NUMBER, never recomputed
  thresholds: { type: Object, required: true },         // { min: 100, parity: 2000 } — server-resolved worker_rep_min/parity_employees (CLK-13/14 are AMENDABLE; constants never hardcoded client-side)
  nextStepAt: { type: Number, default: null },          // server projection: smallest headcount adding a seat
  interactive:{ type: Boolean, default: false },        // renders the range slider EXPLORER
  entityLabel:{ type: String, default: null },
}
```

Render: `.meter--lg` track with **two** `.meter-threshold` marks positioned at `min/(parity*1.2)` and `parity/(parity*1.2)` of the track; `.meter-caption` `0 · "{min} · first worker seat · CLK-13" · "{parity} · parity · CLK-14"`; stat readout cluster (workers R-25 / `stat--accent` worker-elected seats R-27 / owner-elected seats R-26), status badge (below threshold → neutral / scaling → info / parity → success — grammar verbatim from mockup lines 196–199); `.receipt` formula block (`data-no-i18n`): `worker_seats = max(1, round((W − {min}) ÷ ({parity} − {min}) × owner_seats))` with substituted live numbers; "next seat at {nextStepAt} workers (projection)" citation.

**Constitutional posture split**: the static render shows ONLY server numbers. When `interactive`, the slider recomputes locally using the *same server-supplied thresholds* and labels everything moved off the live value `"projection — the engine recomputes on real headcount change · WF-ORG-04"`; a "reset to live" Btn snaps back. This is the one Phase D component where client arithmetic is permitted, because it is explicitly an explorer of a published formula, never a record. Vitest pins: `workerSeats(99)=0`, `workerSeats(100)=1` (the `max(1,…)` floor), `workerSeats(740,9)=3`, `workerSeats(2000,9)=9` (the `min(owner,…)` cap), and that the live badge ignores slider state.

### A.3 `Org/BoardStrip.vue` — board composition strip (owner / worker / chair)

```js
props: {
  seats: { type: Array, required: true },
  // board_seats rows: [{ id, seat_class:'governor'|'owner_elected'|'worker_elected',
  //   holder:{name}|null, is_chair, status:'vacant'|'nominated'|'seated'|'removal_requested'|'removed'|'term_ended',
  //   term:{starts_on, ends_on, clock:'CLK-09'|'CLK-10'}|null }]
  compositionValid: { type: Boolean, required: true },  // boards.composition_valid — engine output
  requiredWorkerSeats: { type: Number, required: true },// what the scale demands (server)
  compact: { type: Boolean, default: false },           // pip strip only (table rows, DepartmentCard)
}
```

Render: `.board-strip` of `.seat-pip`s (`--worker` for worker_elected, `--chair` ring, `--vacant` dashed), each `role="img"` aria-label `"Seat — {class}, {holder|vacant}, term ends {date}"`; stat cluster (`{n} owner-side · {m} worker-elected · chair {name|unfilled}` — board-elections.html lines 26–30 grammar); text legend (never color-only). When `!compositionValid`: warning Banner verbatim from the constitutional rule — *"Board composition no longer matches the co-determination scale ({workerSeats} of {requiredWorkerSeats} worker seats) — the board is valid only while composition matches the scale; a worker-track election is required, and any composition change re-triggers the joint chair election · Art. III §6 · WF-ORG-04 → WF-ORG-05"*. Non-compact adds the roster DataTable (member, seat type incl. "joint-elected chair", term mono with the **two clock regimes visible**: governors `2030-07-01 → 2040-07-01` CLK-09, worker-elected `→ {legislative term end}` CLK-10 — department-detail.html lines 60–87). Used by: DepartmentDetail, DepartmentCard (compact), OrgDetail, CgcDetail, BoardElections "seated board".

### A.4 `Org/OwnershipPanel.vue` — ownership structure display

```js
props: {
  structure: String,            // 'stock'|'partnership'|'equal_partnership'|'member_owned'|'worker_owned'|'nonprofit'
  isCgc: Boolean,
  stakes: { type: Array, default: () => [] },   // org_ownership_stakes: [{ holder:{type,name,href}, units, pct }]
  memberCounts: Object,         // { members, shareholders, partners, workers } — active org_memberships/org_workers
  structureHistory: { type: Array, default: () => [] },  // restructuring events (preserved per WF-ORG-06 internal path)
}
```

Render: structure TagChip + one-line rule for that structure ("partnership changes require unanimity of partners" etc. — transfers-conversions internal-restructuring copy), stakes DataTable with pct, member/worker Stat cluster. **CGC variant** swaps the stakes table for the owner-ruling card: *"In a Common Good Corporation the Board of Governors stands where shareholders would — the owner side runs on the share system everywhere else · as implemented (ledger #12)"*. History renders as LogRows ("structure history preserved").

### A.5 `Executive/DepartmentCard.vue` — department org-chart cards

```js
props: { department: { type: Object, required: true } }
// { id, name, kind:'chief_executive'|'treasury'|'defense'|'state'|'justice'|'other',
//   status (ESM-17), worker_count, board:{ owner_seats, worker_seats, composition_valid, seats:[compact] },
//   charter:{ act_number, href, reporting_interval_months }, oversees_cgcs:[{name,href}],
//   next_report:{ due_on, status }|null, href }
```

Render: Card with name link, kind TagChip, ESM-17 StatusBadge, badges row (`{workers} workers` / compact BoardStrip pips), **co-determination cell** with the registry logic verbatim (org-registry/departments contract): `workers ≥ parity → "parity" success badge / ≥ min → "{n} worker seat(s) · scaling" info badge + CLK-13 citation / else "below threshold" neutral`; charter chip "Act {n} · F-LEG-016"; reporting due chip (warning when `due soon`/`overdue`). Used as the Departments page grid and the Executive/Home departments summary. Classes: `.card`, `.badge--*`, `.tag-chip`, `.board-strip` — no new CSS.

### A.6 `Executive/OrderScopeCard.vue` — executive order scope-citation card

```js
props: { order: { type: Object, required: true }, detailed: { type: Boolean, default: false } }
// executive_orders row: { id_display:'EO-2031-14', title, department:{name}|null, issued_at_display,
//   status:'drafted'|'scope_validated'|'issued'|'rejected_pre_issuance'|'reviewed'|'struck',
//   enabling:{ type:'law'|'emergency_power', label:'Act 2030-02 — delegation', href },
//   rejection_citation: string|null,          ← engine verbatim, e.g. "outside the delegated scope (Art. III §2); election interference barred by Art. II §7"
//   note, public_record:{ seq, href }, review:{ status }|null }
```

Render: `.log-row` (`--rejected` when rejected_pre_issuance), `.log-seq` = EO id, title strong, dept + timestamp cc-small line, **enabling-basis chip** (FormChip-style link to the delegation act or the emergency power — "emergency methods widen the delegated scope only within the declared area and duration"), status: issued → success badge + `.tag-chip` "judicially reviewable · Art. IV §5"; rejected → danger badge "Rejected pre-issuance" + the `rejection_citation` rendered **verbatim** as `.citation` (executive-actions.html line 208 grammar) + the load-bearing chip: `"on the public record · #{seq}"` → `/system/public-records?seq=…`. `detailed` adds the order body + the order lifecycle StateStrip (Drafted → Scope-Validated → Issued | Rejected-pre-issuance → [Judicially Reviewed]). Classes: `.log-row/--rejected/.log-seq`, `.tag-chip`, `.badge--danger` — no new CSS.

### A.7 `Ui/Stepper.vue` — generic pipeline stepper (the BoG pipeline)

Tiny wrapper over the already-ported `.stepper/.stepper-step(--done/--active)` classes: `props: { steps: [{ label, icon, state:'done'|'active'|'pending' }] }`, `aria-current="step"` on active. First consumer: the BoG pipeline (departments.html lines 90–93: `Nomination dossier · F-EXE-001 → Consent vote · F-LEG-020 → Seated · R-18`); also retires the Phase A backlog item #6 path (SetupStepper re-skin can migrate to it later — noted, not in scope).

**Reused, not new**: `VoteTally` (`body_type='board'` chamber_votes already supported — joint-chair RCV outcome, BoG consent, governor removal), `Electoral/StvBar` + `StvRound` (owner/worker STV tracks + chair RCV rounds — board-elections.html uses exactly the `.stv-*` family these render), `Ui/LifecycleTracker` (monopoly-acquisition 5 stages, org lifecycle), `Ui/StateStrip` (ESM-16/17/18 — add `executive_office`, `department_board`, `organization` entries to `config/cga/state_machines.php`), `Ui/AmendableSetting` (CLK-13/14 cards), `Ui/HardenedChip`, `Surface/FormCard`, `Ui/{DataTable, FilterBar, ChipToggle, Stat, OrgChip, PersonaChip, LogRow, Banner, ThresholdMeter, TagChip, StatusBadge}`, `Shell/EmergencyBanner` (already shell-global — executive surfaces get it free).

---

## B) PAGE SPECS — all 11

### Shared conventions (carried from Phase C, unchanged)

- Every page: `PageScaffold :surface` from `config/cga/surfaces.php` — **11 new entries**, ids = mockup ids (`executive/executive-home`, `executive/departments`, `executive/department-detail`, `executive/department-reporting`, `executive/executive-actions`, `organizations/org-registry`, `organizations/org-detail`, `organizations/cgc-detail`, `organizations/board-elections`, `organizations/co-determination`, `organizations/transfers-conversions`); roles/workflows/forms/clocks/citations copied from the mockup `CGA_PAGE` blocks (verified above) + EXPLORE contract tables; F-BOG alias (F-GOV) and F-IND-016/013 drift resolve through FormRegistry as established.
- Controllers in `app/Http/Controllers/Executive/` and `Organizations/`.
- **Thresholds/seat-counts never computed client-side** — `required_yes`, `required` (ceil(n×2/3)), `worker_seats`, `composition_valid` are all engine snapshots from `chamber_votes` / `multi_jurisdiction_votes` / `boards` rows.
- All POSTs through `ConstitutionalEngine::file()`; FormCard injects canonical `form_id`; 422 renders Banner + Field errors with citation.
- **Public-read posture**: every Phase D surface is publicly readable by authenticated residents (orders, departments, boards, org registry, IP register are public records — Art. II §2, Art. III); *actions* gate by derived role via `can.*` + engine 422. No route in this phase is member-locked the way SessionConsole is — even DepartmentReporting reads publicly (reports file "to executive AND legislature, published to public record"). The nav sections stay role-gated (they are the officeholder's launchpad); public entry is via Jurisdictions/Show CTAs, the org registry (visibility `all`), and direct URLs.

### Entry resolvers + nav integration (FE-D0)

- **`ExecutiveResolverController`** (mirrors `ChamberResolverController`): `GET /executive/{sub?}` → (1) viewer holds an active `executive_members` row → 302 to `/executives/{id}/{sub}`; (2) multiple → chooser; (3) no seat → 302 to the smallest associated jurisdiction's executive (public read); (4) none past `forming` → empty-state page ("Executives start as committees delegated by their legislature — F-LEG-014 · WF-EXE-01"). Sub-paths map the nav's literal hrefs: `/executive → home`, `/executive/departments`, `/executive/actions`, `/executive/reporting` (reporting resolves R-18 viewers to *their* department's reporting page; multiple governorships → chooser; non-governors → 302 to departments index).
- **nav.js**: executive section unchanged (4 items, hrefs already correct). Organizations section gains `{ id:'transfers-conversions', labelKey:'nav.transfers', icon:'refresh-cw', href:'/organizations/transfers-conversions', phase:'D' }`; detail surfaces map `nav:` to parents per the mockups (org-detail/cgc-detail/board-elections/transfers → `org-registry`; department-detail → `departments`). Flip `phase:'D'` items live via `phasesLive` (below).
- Cross-links: `Jurisdictions/Show.vue` CTA row gains "Executive" link beside Chamber/District map when the jurisdiction's executive exists; `Legislature/Chamber.vue` first-sessions checklist's delegation step links `/executives/{id}`; CgcDetail ↔ overseeing DepartmentDetail link both ways; CoDetermination ↔ BoardElections ↔ OrgDetail triangle per the mockup hrefs.

### B.1 `Executive/Home.vue` — surface `executive/executive-home`

Route `GET /executives/{executive}` → `Executive\ExecutiveController@show`. Nav `executive-home`. Public read.

```js
props: { surface,
  executive: { id, type:'committee'|'individual', status /* ESM-16: forming|delegated|conversion_voted|elected|modified|dissolved|reverted */,
    jurisdiction:{id,name,href}, legislature:{id,name,chamber_href},
    term:{ starts_on, ends_on, days_remaining } },                      // lockstep card — CLK-10
  machine: string[],                                                    // ESM-16 legend, current highlighted
  delegation: { act:{act_number, href, enacted_at}, scope_text,
                vote: VoteTallyProps } | null,                          // F-LEG-014 record
  conversion: { subjectLabel, legislatureVote: VoteTallyProps,
                process: ConstituentConsentPanelProps } | null,         // F-LEG-015 — live or historical
  members: [ { name, role:'principal'|'advisor', rank /* 0; advisors 1–4 */,
               legislature_member:{seat_no, href}|null,                 // delegated: remains a seated legislator
               endorsements:[orgs], elected_in_race:{href}|null } ],
  departmentsSummary: [ DepartmentCard props ],                         // top 5 + count
  can: { proposeDelegationBill, proposeConversionBill }                 // R-09 of the source legislature — deep-links, not POSTs here
}
```

Composes: hardened cluster (supermajority formula chip — executive-home.html lines 27–30 verbatim) · **model card** — the mockup's 3-way toggle is a demo affordance; in product the card renders the **live model only**, driven by `type`+`status`: `forming` → honest empty state ("Stub created at setup — awaits the delegation act"); `delegated` → Westminster panel (member rows with "Remains a seated legislator · seat {n}" gloss + OrgChips + "selected proportionally — the same method as legislative committees · Art. III §2 · ledger #q2"); `elected` individual → winner + R-17 advisor rows sorted by rank with "rank {n}" badges + the sequential-exclusion succession gloss ("the popular count — not an appointment — decides succession"); elected committee → officer rows "equal decision-making power · PR-STV winner". ESM-16 StateStrip below, current state per `status` · **Creation act card** (F-LEG-014 FormCard-as-record + the enacted `VoteTally`) · **Conversion act card**: `ConstituentConsentPanel` (A.1) when a conversion exists/ran; otherwise the F-LEG-015 FormCard + `can.proposeConversionBill` deep-link to `/legislature/bills?intro=1&subject=executive_conversion&executive={id}` (dual-supermajority acts ride the Phase C bill flow; the legislature votes, `MultiJurisdictionVoteService::open()` runs the constituent leg — this page *renders* the process, it never originates votes) · **term-lockstep card** (Stat days-remaining, HardenedChip, "one clock drives both elections · Art. III §3 · CLK-10" + TermSync link) · departments summary grid (DepartmentCards + "all departments →").

Edge: Earth/San Marino/Montegiardino stubs are all `forming` today — the empty state with the delegation FormCard reference IS the day-one render. Advisors section renders only for individual type ("Advisory · steps in, in rank order, if the office vacates").

### B.2 `Executive/Departments.vue` — surface `executive/departments`

Route `GET /executives/{executive}/departments` → `Executive\DepartmentController@index`. Nav `departments`. Public read.

```js
props: { surface, executive:{…as B.1 header},
  departments: [ DepartmentCardProps ],                                 // the registry
  machine: string[],                                                    // ESM-17
  pipeline: [ { department:{name,href}, nominee:{name}, dossier_at,
                consent:{ vote: VoteTallyProps|null, scheduled:bool, outcome }|null,
                seated:{ term:{starts_on, ends_on} }|null,
                stepper:[StepperSteps] } ],                             // live F-EXE-001 → F-LEG-020 → R-18 rows (appointments)
  civilOfficers: [ { name, department, role_label, term:{ends_on}, clock:'CLK-09' } ],   // R-30 card
  createDeepLink: '/legislature/bills?intro=1&act=department_creation', // F-LEG-016 — ordinary-majority BILL
  can: { nominate /* R-14/15/16 of this executive */ }
}
```

Composes: registry table/grid of DepartmentCards with the co-determination cell (A.5) · **create-department card**: F-LEG-016 FormCard rendered as registry reference (name, type enum incl. the five constitutional kinds + other, **oversight assignment**, charter, reporting interval) whose CTA is the pre-targeted bill deep-link — department creation is a legislative act at ordinary majority (Phase C Settings precedent: institution-creating forms ride the bill flow, never a side-door POST) · **BoG pipeline card**: `Ui/Stepper` (Nomination F-EXE-001 → Consent F-LEG-020 → Seated R-18) + nominee DataTable (the mockup's Adeyemi "consent 6 of 8 serving" / Patel "consent scheduled" pattern, live from `appointments`+`chamber_votes`); each consented row's vote summary pops the `VoteTally` (majority class — "peg-quorum ordinary majority"); nominate Btn → DepartmentDetail dossier form · removal gloss card ("ordinary majority of all serving — hiring and firing; supermajority applies only where the constitution states it" — owner ruling #14) · **civil officer card** (R-30, 10-yr CLK-09, "duties per department charter").

Edge: zero departments → create card + the five constitutional kinds listed as the honest starting menu ("Chief Executive, Treasury, Defense, State, Justice — and others by act").

### B.3 `Executive/DepartmentDetail.vue` — surface `executive/department-detail` ← **BoG-consent exit surface**

Route `GET /departments/{department}` → `DepartmentController@show`. Nav `departments`. Public read.

POSTs: `/departments/{d}/nominations` (F-EXE-001, R-14/15/16 — creates `appointments` row + opens the F-LEG-020 consent `chamber_votes` via the existing `ChamberActService` consent lane) · `/departments/{d}/removal-requests` (F-EXE-003 — opens ordinary-majority chamber vote) · consent casting happens in the legislature (`/votes/{vote}/cast`, Phase C endpoint — R-09s vote there; this page renders the same row).

```js
props: { surface,
  department: { id, name, kind, status, worker_count, charter:{ text_summary, act:{act_number,href},
    reporting_interval_months }, executive:{name,href}, oversees_cgcs:[{name,href}] },
  machine: string[],                                                    // ESM-17, current
  board: { compositionValid, requiredWorkerSeats, owner_seats, worker_seats,
           seats:[BoardStrip rows], chair:{name}|null },
  nominations: [ { id, nominee:{name}, dossier_excerpt, status:'nominated'|'consented'|'rejected'|'seated'|'ended',
                   consent_vote:{ tally: VoteTallyProps, casts:[VoteCastList rows] }|null,
                   term:{starts_on,ends_on}|null, stepper:[…] } ],
  removals: [ { id, subject:{name}, grounds_published, vote:{tally,casts}|null, outcome } ],
  reporting: { last_filed:{kind, at, record_href}|null, next_due:{on, status}, reporting_href },
  can: { nominate, requestRemoval }
}
```

Composes: header badges (Operating / workers / "{g} governors + {w} worker-elected") · ESM-17 StateStrip (`removal_requested` state flips live when a removal opens) · charter & oversight card (three `hr`-split blocks per the mockup: charter + Act chip; oversight executive + "full and equal investigative power · Art. III §4" + executive-actions link; oversees CGC links + "CGC IP perpetually public domain · Art. III §5") · **BoardStrip full** (A.3 — the two-clock roster: governors 10-yr CLK-09, worker seats ending at the legislative term end, chair joint-elected; "renomination open" warning badge on expiring terms) · **nomination dossier card**: F-EXE-001 FormCard (nominee search, credentials text, neutrality attestation checkbox — "politically neutral" engine-asserted) + per-nomination cards with the Stepper and, once the consent vote opens, **`VoteTally` (threshold_class `majority`, the legislature's serving snapshot) + VoteCastList** — *the chamber vote rendered on the executive surface*; on `adopted` the seated badge + 10-yr term dates appear and the BoardStrip pip fills · **removal card**: F-EXE-003 FormCard ("good-faith competence/ethics finding — grounds published") + live removal VoteTally (majority — deliberately NOT supermajority; gloss states the contrast with officeholder removal) · reporting summary card → DepartmentReporting link.

Edge: board below required worker seats → the BoardStrip invalid Banner (A.3) + worker-election link; no nominations ever → pipeline empty state; rejected consent → "renominate" path stated (WF-EXE-05 branch).

### B.4 `Executive/DepartmentReporting.vue` — surface `executive/department-reporting`

Route `GET /departments/{department}/reporting` → `DepartmentReportingController@show`; resolver `/executive/reporting` (R-18 → own department). Nav `department-reporting`. Public read; filing gated R-18 of this board.

POSTs: `/departments/{d}/rules` (F-BOG-001) · `/departments/{d}/reports` (F-BOG-002 → public_records).

```js
props: { surface, department:{…header}, viewerIsGovernor: bool,
  rules: [ { id, rule_code /* 'PWU-R-2031-02' */, name, status:'draft'|'in_force'|'superseded'|'expired',
             version_no, enabling:{ type:'law'|'emergency_power', label, href,
                                    expires_with_power: bool }, note } ],
  reports: [ { kind:'periodic'|'special', label, recipients:'Executive + legislature',
               due_on, filed_at|null, status:'due'|'due_soon'|'filed'|'overdue', record_href|null } ],
  ruleForm: { enablingOptions: [ { type, id, label } ] },               // charter law + bills + ACTIVE emergency powers only — server-filtered
  can: { fileRule, fileReport }
}
```

Composes: rules register DataTable (rule code mono, status badges, **enabling-act chip per row** — the emergency-enabled rule renders the warning chip *"expires with the emergency power · CLK-03"* and flips to `expired` when CLK-03 fires: the cross-domain cascade made visible) · F-BOG-001 FormCard (name, text, **enabling-basis select** — hint verbatim: *"Rules implement — they cannot exceed — the charter and enabling acts"*; engine rejects scope overruns with citation; drafts publish for comment) + alias citation "catalog alias: F-GOV-001" · report filings DataTable (Q1 filed / special due / Q2 due pattern; recipients column fixed "Executive + legislature"; filed rows link their public_records entry) · F-BOG-002 FormCard (kind, body) → success state links the new record ("published · WF-SYS-03").

Edge: non-governor viewers see the registers read-only with "Filed by this department's governors (R-18)" gloss; no rules yet → register empty state + the implement-don't-exceed rule card anyway.

### B.5 `Executive/Actions.vue` — surface `executive/executive-actions` ← **order-rejection exit surface**

Route `GET /executives/{executive}/actions` → `ExecutiveActionController@index`. Nav `executive-actions`. Public read of all registers (rejections are public record); composer/forms gated R-14/15/16 of this executive.

POSTs: `/executives/{e}/orders` (F-EXE-005) · `/executives/{e}/policy-proposals` (F-EXE-002) · `/executives/{e}/investigations` (F-EXE-004) · `/appropriations/{a}/applications` (grant application).

```js
props: { surface, executive:{…header, delegated_scope_text},
  scopeBanner: { delegation_act:{label,href}, active_powers:[{label, area, expires_at, href}] },
  orders: [ OrderScopeCardProps ],                                      // register incl. rejected_pre_issuance rows
  orderForm: { departmentOptions:[{id,name}], enablingOptions:[{type,id,label}] },
  proposals: [ { title, department:{name,href}, status:'with_board'|'adopted'|'amended'|'declined', decided_at|null } ],
  investigations: [ { title, department, scope, status, outcome|null, findings_record_href|null } ],
  appropriations: [ { line, act:{act_number,href}, appropriated, remaining } ],
  applications: [ { org:{name,href}, line, amount, purpose, status:'submitted'|'awarded'|'declined',
                    disbursements:[{amount, at, audit_seq}] } ],
  grantForm: { orgOptions:[…registry], lines:[…] },
  can: { issueOrder, propose, investigate, administerGrants }
}
```

Composes: the hardened info Banner verbatim (executive-actions.html lines 27–34: *"Scope validation happens before issuance… elections, sessions, and courts cannot be disrupted, even under emergency powers"* + HardenedChip) · **order composer** (F-EXE-005 FormCard): title, department select, body, enabling-basis select (delegation act / active emergency power — **the mockup's "Demo: claimed scope" select is a demo affordance and does not ship**; the engine validates the real order text + basis pre-issuance). Submit → success ("Issued — judicially reviewable at any time · Art. IV §5") **or 422**: the engine citation renders verbatim in the Banner AND the rejected order appears at the top of the register — because the handler persists `status='rejected_pre_issuance'` + `rejection_citation` and publishes the record *before* rethrowing; the confirmation states *"The order never took effect; the rejected attempt is on the public record"* with the `#seq` link · **order register**: OrderScopeCard list (A.6), order-lifecycle StateStrip in the About panel · grid-2: **policy proposals** (F-EXE-002 FormCard + cards with "the board adopts, amends, or declines — proposals do not bypass the board" + board-decision badge) + **investigations** (F-EXE-004 FormCard: scope + records access; outcome branch chips → policy proposal | removal request (→ DepartmentDetail) | legislative referral | closed; "full and equal investigative power · Art. III §4") · **grants & appropriations card**: appropriations DataTable (line, act link, appropriated, remaining — legislature appropriates by act; new lines only via bills), application form (org select from registry, amount, purpose), applications DataTable with award/decline actions (`can.administerGrants`) and per-disbursement audit-seq chips ("every award and disbursement appended to the audit chain · WF-SYS-04").

Edge: `forming` executive → composer disabled with "No delegated scope exists yet — F-LEG-014" banner; zero orders → *"No orders issued — the scope-validation rails apply from the first order"* (rails card renders regardless: that is the point); zero appropriations → "The legislature has appropriated no funds — appropriation is an act (deep-link to bills)".

### B.6 `Organizations/Registry.vue` — surface `organizations/org-registry`

Route `GET /organizations` → `Organizations\OrganizationController@index`; `POST` same (F-IND-012). Nav `org-registry` (section visibility `all`). Public read; registration requires R-03 (engine-enforced; page explains, never 403s — CandidacyRegistration pattern).

```js
props: { surface,
  stats: { total, endorsing, in_codetermination /* workers ≥ min */, cgcs },
  organizations: [ { id, name, type, structure, jurisdiction:{name, adm_chip}, workers,
                     endorsement_count, codet:{state:'parity'|'scaling'|'below', worker_seats}|null,
                     is_cgc, status /* ESM-18 */, flags:['monopoly_target']|[], href } ],
  filters: { types:[…5], structures:[…6], jurisdictions:[viewer chain] },
  machine: string[],                                                    // ESM-18 legend
  createForm: { structures:[{value,label,rule_gloss}], jurisdictionOptions:[viewer chain] },
  isAssociated: bool, thresholds:{min, parity}                          // for the codet cell legend
}
```

Composes: Stat tiles · FilterBar (type ChipToggles, structure select, jurisdiction select, search) · registry DataTable: name link (CGC rows → CgcDetail), type + structure TagChips, workers, **co-determination cell** (`≥parity → parity badge / ≥min → "{n} seats · scaling" + CLK-13 / else below-threshold` — same logic as A.5, server-computed), endorsement count, ESM-18 status badge · **registration FormCard (F-IND-012)**: name, **structure** select (stock | partnership | equal_partnership | member_owned | worker_owned | nonprofit, each with its ownership-rule gloss), jurisdiction (viewer's chain), purpose; the CGC carve-out stated verbatim: *"Common Good Corporations are not self-registered — the legislature creates them by act (F-LEG-019 · WF-ORG-08)"* · ESM-18 StateStrip legend card.

Edge: `!isAssociated` → list renders, form replaced by residency CTA; zero orgs → "No organizations registered in your association chain — any associated resident may register one (Art. I)".

### B.7 `Organizations/OrgDetail.vue` — surface `organizations/org-detail`

Route `GET /organizations/{organization}` → `OrganizationController@show` (302s to the CgcDetail component when `is_cgc` — one route, two page components). Nav `org-registry`. Public read.

POSTs: `/organizations/{o}` PATCH profile (F-ORG-001, R-23) · `/organizations/{o}/endorsements/{request}/grant` (F-ORG-002, R-23 — the F-CAN-002 requests arrive from Phase B candidacy surfaces) · `/organizations/{o}/memberships` (F-IND-013, any R-01) · `/organizations/{o}/workers` (F-IND-014, any R-01 — **the headcount feed**) · `/organizations/{o}/documents` + `/documents/{pkg}/versions` (R-23) · `/contracts/{c}/cosign` · `/organizations/{o}/contracts` (draft).

```js
props: { surface,
  organization: { id, name, type, structure, status, jurisdiction, purpose, registered_at,
                  agent:{name, is_viewer}, worker_count, member_counts:{member, shareholder, partner} },
  machine: string[],                                                    // ESM-18, current
  ownership: OwnershipPanelProps,
  board: { exists: bool, strip: BoardStripProps|null, codet:{ workers, ownerSeats, workerSeats,
           thresholds, compositionValid }, elections_href, codet_href } | null,
  endorsements: { incoming:[{ id, candidate:{name, race, href}, requested_at }],   // R-23 only
                  granted:[{ candidate:{name, href}, race, granted_at }], total },
  documents: [ { package, version, status:'adopted'|'current'|'self_managed' } ],
  contracts: [ { title, kind:'labor_recurring'|'labor_single'|'commercial'|'other', counterparty,
                 signed_a:bool, signed_b:bool, status, feeds_headcount:bool } ],
  myMembership:{kind}|null, myWorker:{since}|null,
  can: { manage /* R-23 agent */, join, registerWorker, cosign }
}
```

Composes: profile card (F-ORG-001 FormChip; edit Fields when `can.manage`) · **endorsements card**: the two-form handshake stated (F-CAN-002 request → F-ORG-002 grant → R-07), the no-faction info Banner verbatim ("Endorsement linkage feeds proportionality… ledger #q1"), incoming-request queue with Grant/Decline Btns (R-23), granted list with candidate links into the Phase B open-ballot surfaces · **join card** grid-2: F-IND-013 (membership class select → R-24) + F-IND-014 (contract reference Field, hint *"Worker headcount feeds the co-determination scale · CLK-13/CLK-14"* → R-25); joined state renders the viewer's own chips · **document packages** table + upload/adopt-version Btns + the floor gloss verbatim ("Internal packages never override the constitutional forms") · **contracts card**: contract cards with co-signature state badges (Active · co-signed / Pending co-signature + co-sign Btn — engine rejects effect before both signatures), `labor_recurring` rows carry the "counts toward the worker headcount" chip · OwnershipPanel · board summary card (BoardStrip compact + CoDetScale static + links to BoardElections/CoDetermination) when a board exists · ESM-18 StateStrip.

Edge: org below `min` workers → board card replaced by "No board constituted — co-determination begins at {min} workers (CLK-13); ownership governs per its structure rules"; viewer already a worker → F-IND-014 form shows the registered state, not a duplicate form.

### B.8 `Organizations/CgcDetail.vue` — surface `organizations/cgc-detail`

Route: same `show` route, CGC component. Nav `org-registry`. Public read.

POSTs: `/organizations/{o}/ip-register` (R-18 of the overseeing board or R-23 — additive only; **no update/delete route exists**).

```js
props: { surface,
  organization: {…as B.7 minus ownership stakes},
  charter: { purpose, act:{act_number, href}, effective_at },           // F-LEG-019
  oversight: { department:{name, href}, executive:{name, href}, reporting_interval },
  codet: CoDetScale props,                                              // governors stand where shareholders would
  board: BoardStripProps,
  ipRegister: [ { asset, kind, published_at, status:'public_domain' /* the only value */ } ],
  actionsDeepLinks: { reorganize:'/legislature/bills?intro=1&act=cgc_reorg&org={id}' },   // F-LEG-027
  can: { registerIp }
}
```

Composes: charter card (chartering act chip, "legislature creates · executive oversees") · oversight card with the **identical-regulation HardenedChip** verbatim: *"Regulated identically to private peers — hardened"* + reports-to-department line · CoDetScale static + the ledger-#12 owner-side card (A.4 CGC variant) + BoardStrip · **public-domain IP register**: DataTable (asset, kind, published date, status column always the success badge `public domain`) + HardenedChip card: *"Every work this corporation produces is public domain from the moment of creation — universally, eternally, irreversibly · Art. III §5"*; add-asset FormCard (`can.registerIp`) — the form has **no status field at all** (the column admits one value; absence of the affordance is the UI statement of irreversibility; the engine enforces it regardless) · reorganization/sale/dissolution card: *"Only the legislature may reorganize, sell, or dissolve a CGC (F-LEG-027 · WF-ORG-09); existing public-domain IP status survives any sale"* + bill deep-link; conversion history (org_conversions) as LifecycleTracker when one exists.

Edge: CGC with empty IP register → "No works registered yet — the public-domain rule attaches at creation, not at registration".

### B.9 `Organizations/BoardElections.vue` — surface `organizations/board-elections`

Route `GET /organizations/{organization}/board-elections` → `BoardElectionController@show`. Nav `org-registry`. Public read (counts publish like all elections); administration gated R-23; voting happens on the **Phase B ballot surfaces** (board elections reuse the elections machinery: `elections.kind = org_board_owner|org_board_worker`, `races.electorate_type = owners|workers` — never a forked ballot UI).

POSTs: `/organizations/{o}/board-elections` (F-ORG-003 owner track / F-ORG-004 worker track — creates the election via the existing election pipeline; F-ORG-004 also fires system-side from CLK-13).

```js
props: { surface, organization:{…header},
  composition: { ownerSeats, workerSeats, chair:{name}|null, compositionValid, requiredWorkerSeats },
  ownerTrack: { election:{ id, status, href /* → Phase B ElectionDetail/RankedBallot */ }|null,
                result:{ ballots, candidates, seats, quota, rows:[StvBar rows], certified_at }|null,
                form: F-ORG-003 card, electorate_count },               // active shareholder memberships
  workerTrack: { …same shape, trigger:'clk13'|'scaling'|'cyclical'|null, electorate_count },  // F-IND-014 actives
  chair: { vote:{ tally: VoteTallyProps /* body=board, full-board majority */,
                  rounds:[StvRound rows], required, board_size }|null,
           pending_reason:'composition_changed'|null },
  seated: BoardStripProps,
  can: { administerOwner, administerWorker }
}
```

Composes: stat cluster (owner R-26 / `stat--accent` worker R-27 / chair R-28 — board-elections.html lines 26–30) · **owner track card**: Droop arithmetic line verbatim (`floor(1,204 ÷ (9+1)) + 1 = 121`, `data-no-i18n`), the STV gloss, **StvBar final-round rows** (same component as public Results — quota mark, elected fill, eliminated strikethrough), F-ORG-003 FormCard or "schedule" Btn; election in flight → link chip to the live race ("vote on the ranked ballot →" — eligible R-24s see the race on their Phase B ballot surface) · **worker track card**: same + the trigger provenance line ("this track exists because the organization crossed {min} workers · CLK-13; its {n} seats come from the uniform scale · CLK-14" + CoDetermination link) · **joint chair card**: HardenedChip *"Chair elected jointly by the entire Board · Art. III §6"*, "majority of the full board: {required} of {board_size}", StvRound round-by-round (round 1 no-majority → elimination + transfer → round 2 elected — the mockup's two-round fixture grammar), VoteTally for the outcome; `pending_reason` renders *"Composition changed — a fresh joint chair election is required before the board acts"* · **seated board** (BoardStrip + PersonaChips with R-26/R-27/R-28 labels) + the re-trigger citation verbatim ("Any composition change — a seat added by the scale, a vacancy, a transfer — re-triggers the joint chair election").

Edge: no elections yet → both track cards render electorate counts + forms only; worker track absent below CLK-13 ("No worker track — first seat at {min} workers").

### B.10 `Organizations/CoDetermination.vue` — surface `organizations/co-determination` ← **CLK-13 exit surface**

Route `GET /organizations/co-determination` → `CoDeterminationController@show`; `?org={id}` binds the meter to a live org. Nav `co-determination` (own item, exists). Public read.

```js
props: { surface,
  focus: { entity:{name, href, kind}, scale: CoDetScaleProps } | null,  // bound org/department, else the explorer default
  appliesTable: [ { entity:{name, href}, kind:'Private enterprise (stock)'|'Common Good Corporation'|'Executive department'|…,
                    workers, owner_side:{ seats, label:'shareholder-elected'|'appointed governors' }|null,
                    worker_seats, state:'below'|'scaling'|'parity',
                    composition_valid: bool, election:{status, href}|null } ],   // LIVE rows: every boards row joined to its boardable
  clk13: { value, basis, enacted_by:{act,href}|null, bounds_gloss:'must stay below the parity threshold' },
  clk14: { value, basis, enacted_by|null, bounds_gloss:'must stay above the minimum threshold' },
  jointChairForm: F-ORG-004 registry card
}
```

Composes: **CoDetScale interactive** (A.2 — bound to `focus` when present, Bluefin-style; otherwise generic explorer at the resolved thresholds) · **composition-change → joint chair card**: the hardened rule verbatim ("composition change triggers a fresh joint chair election by the entire board… the board is valid only while its composition matches the scale" + HardenedChip + F-ORG-004 FormCard + BoardElections link) · **applies-equally table** — the constitutional centerpiece: one row per live board across all three entity kinds (private orgs, CGCs, departments — *one boards table, one engine*, and this table proves it), owner-side column distinguishing "shareholder-elected" vs "appointed governors", state badges per the mockup logic; rows with `composition_valid=false` carry the warning chip + election link — **this is where the CLK-13 exit criterion is observed**: when an org's F-IND-014 registrations cross 100, its row flips `below → scaling · 1 seat`, `composition_valid=false`, "worker-track election open" appears · grid-2 CLK-13/CLK-14 `AmendableSetting` cards (live resolved values, enacting-act provenance or "Template default · founding value", bounds glosses — these are two of the Phase D constitutional-placeholder conversions, so provenance must render).

Edge: zero boards anywhere → applies-table empty state ("No entity has reached the first-seat threshold — the scale binds from the first qualifying organization") + the explorer still fully functional.

### B.11 `Organizations/TransfersConversions.vue` — surface `organizations/transfers-conversions`

Route `GET /organizations/transfers-conversions` → `TransferController@index`; `?org={id}` focuses. Nav `org-registry` (new sidebar item per FE-D0). Public read; initiation gated R-23/R-24.

POSTs: `/organizations/{o}/transfers` (F-ORG-005) · `/transfers/{t}/consent` (counterparty co-consent) · `/organizations/{o}/conversion-requests` (F-ORG-006) · `/organizations/{o}/dissolution` (F-ORG-007). F-LEG-026/F-LEG-027 are bill deep-links (legislative acts).

```js
props: { surface,
  transfers: [ { id, from:{name,href}, to:{type,name}, status:'proposed'|'consented'|'completed'|'abandoned',
                 consent_a_at|null, consent_b_at|null, ffc_synced_at|null } ],
  acquisitions: [ { org:{name,href}, stage_index /* 0–4 */, finding:{act,href}|null,
                    vote:{ tally: VoteTallyProps /* MAJORITY class — owner ruling #13 */ }|null,
                    compensation:{ amount, fair_market_floor }|null,
                    governor_offers:[{ name, status:'offered'|'accepted'|'declined' }] } ],
  conversions: [ { org, direction:'private_to_cgc'|'cgc_to_private', via, authorizing_act|null, status } ],
  restructurings: [ { org, from_structure, to_structure, rule_applied, at } ],
  dissolutions: [ { org, kind:'voluntary'|'judicial', status, archived_record_href|null } ],
  can: { initiateTransfer, requestConversion, dissolve }
}
```

Composes — four path cards (the mockup's structure):
1. **Mutual transfer** (F-ORG-005 FormCard + transfer rows with two consent badges — "both consents required on record; the engine rejects anything less" — and the FF&C sync chip);
2. **Monopoly acquisition**: `LifecycleTracker` over the 5 stages verbatim (Legislative finding → Acquisition vote F-LEG-026 → Compensation ≥ fair market → Conversion to CGC → Founding governor seats offered to prior board); the vote stage renders VoteTally at **ordinary majority of all serving** (gloss cites owner ruling #13 — the only path overriding owner consent); compensation stage renders the HardenedChip floor (*"shareholders paid ≥ fair market — the engine blocks underpayment"*) with `amount` vs `fair_market_floor`; final stage = governor-offer table (declines fall through to the WF-EXE-05 analog);
3. **Public ↔ private conversion** (F-ORG-006 request card; F-LEG-027 bill deep-link; the irreversibility line verbatim: *"public-domain IP irreversibly stays public — new works after privatization follow private rules"*);
4. **Internal restructuring** (no legislature: "owner consent per the current structure's own rules — partnership changes require unanimity"; history rows preserved) + **dissolution** card (F-ORG-007 voluntary; judicial path WF-ORG-10 `planned-flag · Phase E`; "obligations settled, records archived, audit chain preserved").

Edge: all-empty (likely day one) → the four path cards render as registry/explainer with forms — that is the mockup's own posture.

---

## C) EXIT-CRITERION SURFACES

**1. Out-of-scope executive order rejected pre-issuance, on the public record.**
Surface chain: `Executive/Actions.vue` composer → `POST /executives/{e}/orders` (F-EXE-005) → engine scope rule fails → handler persists `executive_orders` row `status='rejected_pre_issuance'` + `rejection_citation`, publishes the public-records row + `rejected=true` audit row, **then** rethrows 422 → the page renders (a) the danger Banner with the engine citation **verbatim** ("Rejected pre-issuance: outside the delegated scope (Art. III §2); election interference barred by Art. II §7"), (b) the new `OrderScopeCard` at the top of the register in `.log-row--rejected` styling with the `"on the public record · #{seq}"` chip, (c) that chip lands on **`/system/public-records?seq=N`** (existing Phase C page — zero changes needed; the rejection arrives as a normal record row, kind `act`/`other`, via-chip `F-EXE-005`, with its `audit_seq` seal linking `/system/audit-chain`). Verification is a three-screen walkthrough: compose → 422 verbatim → register row → public-records row → audit-chain `rejected` row.

**2. CLK-13 first worker seat at 100.**
Surface chain: `Organizations/OrgDetail.vue` F-IND-014 worker form is the feed (the 100th active `org_workers` row) → WF-ORG-04 fires → `boards.worker_seats 0→1`, `composition_valid=false`, one `board_seats` row (`seat_class='worker_elected'`, `status='vacant'`) → observed on: **`Organizations/CoDetermination.vue`** applies-equally row flips `below threshold → scaling · 1 seat` + invalid chip + "worker-track election open" link (the meter, bound via `?org=`, shows the fill crossing the CLK-13 threshold mark with the success-state badge grammar); **`OrgDetail`** board card now renders BoardStrip with the new dashed `--worker --vacant` pip + the invalid Banner; **`BoardElections`** worker-track card materializes with `trigger:'clk13'` provenance line and the F-ORG-004/system-triggered election. Board-validity state is the same `composition_valid` flag on all three — one engine output, three renders.

**3. BoG nominate → consent → seated.**
Surface chain: `Executive/DepartmentDetail.vue` dossier FormCard (F-EXE-001, R-14) → `appointments` row + Stepper step 1 done → consent `chamber_votes` row opens (the existing `ChamberActService` consent lane — same machinery that already seats election-board members) → **the chamber `VoteTally` (threshold_class `majority`, peg-quorum gloss) renders on DepartmentDetail itself** alongside VoteCastList; R-09s cast on the Phase C vote endpoints from their chamber surfaces; on `adopted` → Stepper step 3, `board_seats` row seated, **10-yr CLK-09 term dates render in the BoardStrip roster** (visibly decoupled from the worker seats' legislative-term end dates in the same table — the two-clock contrast is the acceptance check), and `Executive/Departments.vue` pipeline table shows "consent {yes} of {serving} serving".

---

## D) WORK-ITEM BREAKDOWN

**Kit decision: new `/dev/executive-kit`** (`resources/js/Pages/Dev/ExecutiveOrgKit.vue`), not an extension of legislature-kit — Phase D's components are a distinct vocabulary (boards/orders/co-det) and the established pattern is one harness per phase (`/dev/electoral-kit`, `/dev/legislature-kit`); nav dev section gains the item. Backend WI names referenced for deps (align at merge with the Phase D backend section): **D-EXEC** (executives evolve + delegation/conversion + MJV wiring), **D-DEPTS** (departments + boards/board_seats + appointments/consent lane + CLK-09), **D-ORDERS** (executive_orders + pre-issuance scope rules + policy_proposals + investigations), **D-RULES** (department_rules/reports + CLK-03 expiry cascade), **D-GRANTS** (appropriations/applications/disbursements), **D-ORGS** (organizations evolve + memberships/workers/contracts/document_packages + F-IND-012/013/014), **D-CODET** (co-det engine + CLK-13/14 + composition_valid), **D-BELECT** (elections kind `org_board_owner/worker` + electorate_type + chair board-vote), **D-TRANSFERS** (transfers/conversions/cgc_ip_register + fair-market floor).

### Group 0 — zero-backend (day 1)

| WI | Item | Size | Depends | Verification |
|---|---|---|---|---|
| **FE-D0** | 11 surface entries in `config/cga/surfaces.php` · nav (transfers item; detail `nav:` mappings; resolver hrefs unchanged) · state-machine config (`executive_office`, `department_board`, `organization`) · CSS append (§A.0: `.range-input`, `.meter--lg`, `.seat-pip` family, `.board-strip`) · `ExecutiveResolverController` skeleton (4-branch) | S | — | SurfaceMeta test extends to the 11 ids (F-BOG/F-GOV alias resolution asserted); qa_scan over the append (zero hex, logical properties); resolver unit test (exec member → 302, public → smallest associated, forming-only → empty state) |
| **FE-D1** | Component kit, fixture-first on `/dev/executive-kit`: **ConstituentConsentPanel** (NY-State fixture 8/9 + 51/62-counties from the mockup; pending/passed/failed/one-leg-failed × 4), **CoDetScale** (static 740/9/3 + interactive + thresholds≠defaults case proving no hardcoded 100/2000), **BoardStrip** (7+4+chair valid; invalid w/ vacant worker pip; compact), **OwnershipPanel** (stock w/ stakes; equal-partnership; CGC ledger-#12 variant), **DepartmentCard** (5 dept fixtures incl. Treasury 152w/1 seat + below-threshold), **OrderScopeCard** (issued / rejected w/ verbatim citation / emergency-enabled), **Ui/Stepper** (BoG 3-step), BillDetail call-site migration to ConstituentConsentPanel | L | FE-D0 | Vitest: CoDetScale formula pins (99→0, 100→1, 740→3, 2000→cap) + live-badge-ignores-slider + thresholds-from-props-only; ConstituentConsentPanel renders ONLY server `required` (feed `required=99`, assert no client ceil); BoardStrip invalid-banner trigger + aria-labels; OrderScopeCard rejected row carries the record link; BillDetail snapshot unchanged post-migration. Browser pass: keyboard slider, RTL + pseudo-locale spot check |

### Group A — executive spine (sequential; carries exit criteria 1 + 3)

| WI | Item | Size | Depends | Verification |
|---|---|---|---|---|
| **FE-D2** | Executive/Home + ExecutiveController + resolver wiring + cross-links (Jurisdictions/Show CTA, Chamber checklist link) | M | D-EXEC, FE-D1 | curl props: San Marino exec `forming` → empty-state model card; seed delegation act → `delegated` panel w/ members carrying legislature seat links; seed a conversion process → ConstituentConsentPanel shows live consent rows matching `multi_jurisdiction_votes`; `/executive` as exec member 302s |
| **FE-D3** | Departments + DepartmentDetail + DepartmentController + nomination/removal endpoints | L | D-DEPTS, FE-D2 | **exit-criterion 3 walkthrough**: create dept via pre-targeted bill → charter card; nominate (F-EXE-001) → Stepper + consent VoteTally appears (assert numbers = chamber_votes snapshot) → R-09s cast → seated → BoardStrip shows 10-yr CLK-09 dates beside worker seats' term-end dates; removal request → **majority** (not supermajority) VoteTally asserted; reject consent → renominate path renders |
| **FE-D4** | Actions + ExecutiveActionController + order/proposal/investigation/grant endpoints | L | D-ORDERS, D-GRANTS, FE-D2 | **exit-criterion 1 walkthrough**: issue in-scope order → success + register; issue out-of-scope (election-deferral fixture) → 422 verbatim + `rejected_pre_issuance` row in register + row on `/system/public-records` + `rejected=true` audit row + `audit:verify` green; grants: appropriation table from a seeded act, application → award → disbursement audit-seq chips |
| **FE-D5** | DepartmentReporting + controller + rule/report endpoints | M | D-RULES, FE-D3 | R-18 files a rule citing the charter → in-force; rule citing an active emergency power → warning chip; dev-advance CLK-03 → rule flips `expired` (the cascade receipt); F-BOG-002 filing → public_records link; overdue report renders `overdue` badge; non-R-18 sees registers read-only |

### Group B — organizations spine (parallel with Group A from FE-D1; carries exit criterion 2)

| WI | Item | Size | Depends | Verification |
|---|---|---|---|---|
| **FE-D6** | Registry + OrgDetail + OrganizationController + registration/join/worker/document/contract/endorsement-grant endpoints | L | D-ORGS, FE-D1 | register an org (R-03; non-associated sees CTA) → registry row + agent chip; F-IND-013 join → member count bumps; F-IND-014 → worker count bumps; contract co-sign two-sided gate (single-sided POST → 422); endorsement grant → candidate gains R-07 (assert on the Phase B candidate profile); document version adopt |
| **FE-D7** | CoDetermination + CoDeterminationController (+ the applies-equally live query) | M | D-CODET, FE-D6 | **exit-criterion 2 walkthrough**: seed an org to 99 workers → applies-row `below`; register the 100th worker on OrgDetail → row flips `scaling · 1 seat` + `composition_valid=false` + vacant worker pip on OrgDetail + worker-track card live on BoardElections; thresholds changed by act (D-CODET test fixture) → meter marks move (no hardcoded 100/2000 anywhere — grep gate) |
| **FE-D8** | BoardElections + BoardElectionController (elections-machinery reuse: owner/worker tracks + joint chair board vote) | L | D-BELECT, FE-D7 | owner track: F-ORG-003 → election appears on Phase B surfaces with `electorate_type=owners` (non-shareholder gets engine 422 on ballot); certify → StvBar rows + quota line match tabulation rows; worker track auto-created from FE-D7's CLK-13 fire; chair: board RCV (body_type='board') → VoteTally full-board majority + rounds; composition change re-triggers chair (`pending_reason` renders) |
| **FE-D9** | CgcDetail + TransfersConversions + controllers (IP register, transfers, conversions) | L | D-TRANSFERS, FE-D6 | create CGC via F-LEG-019 bill → CgcDetail charter/oversight/identical-regulation chip; IP entry add-only (no edit/delete routes — route:list asserted); monopoly path: finding → **majority** vote → compensation below floor → engine 422 verbatim → at/above floor proceeds → org flips `is_cgc` + governor offers; mutual transfer dual-consent gate; CGC sale → IP register rows untouched (`public_domain` immutable) |

### Flip + critical path

- **phasesLive**: `HandleInertiaRequests.php:73` → `['A','B','C','D']` with the **final landing batch** (FE-D9 or whichever lands last); until then individual pages ship behind their routes with nav items still `Planned · Phase D` (mechanism unchanged from C). The `/system/clocks` nav item (currently `phase:'D'`) is **re-flagged `'E'`** in FE-D0 unless slack exists at the end — it is not one of the 11 Phase D surfaces (it was parked at D only to avoid a dead link at the C flip); if slack: trivial read-only DataTable over the `clocks` registry.
- Critical path: **FE-D0 → FE-D1 → FE-D2 → FE-D3 (exit 3) → FE-D4 (exit 1)**; org fork **FE-D1 → FE-D6 → FE-D7 (exit 2) → FE-D8/FE-D9**; FE-D5 hangs off FE-D3. The fixture-first kit means the consent-panel pairing, the co-det formula rendering, board validity, and the rejected-order grammar are pixel/a11y/unit-verified before any Phase D backend exists — page WIs are wiring.

### Deferrals (justified)

1. **Judicial review of orders/emergency rules (Art. IV §5)** — Phase E; OrderScopeCard renders `review` as a planned-flag chip ("judicially reviewable · filing arrives with the judiciary · Phase E"), consistent with Phase C's F-JDG-007 stub.
2. **Judicial dissolution path (WF-ORG-10)** — Phase E court order; TransfersConversions renders the branch as planned-flag; voluntary F-ORG-007 ships.
3. **Org-side grant application self-service (R-23 applying from OrgDetail)** — the mockup contract puts the application form on executive-actions (the administering surface); R-23 self-service is a thin duplicate form, post-D polish once the grants lane is proven.
4. **FF&C cross-jurisdiction transfer sync** (`ffc_synced_at`) — Phase F federation; the chip renders the column honestly ("syncs on federation · Phase F") and stays null on a single instance.
5. **Elected-committee executive election UI** — F-LEG-015 committee-type conversion + WF-ELE-08 PR-STV reuse the Phase B election surfaces wholesale (a 5+-seat race like any other); no live fixture exists (mockup marks it illustrative), so Executive/Home renders the model in the About panel only until an instance converts. The *individual* path is fully specced (deriveAdvisors exists).
6. **Department `defense`/`state` specialized surfaces** — kinds render as enum values on the shared pages; bespoke surfaces (war powers etc.) are constitutionally Phase F-adjacent (reserved powers, Art. V §4–5) and out of the 11-screen contract.
7. **Range-slider fine a11y on CoDetScale** — native `<input type=range>` + aria-describedby readout ships; a stepped custom control (exact seat-boundary snapping) is polish, flagged for the all-phases pass.