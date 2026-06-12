# PHASE C FRONTEND — 16 SCREENS + COMPONENTS

Worktree root: `E:\fair-constitution-app\.claude\worktrees\practical-payne-17d537\`. All paths below are relative to it. Verified on disk before writing this spec: `resources/css/cga/components.css` (952 lines — all electoral + `.seat-map/.seat-dot/.law-diff/.log-row` families ported), `resources/js/Components/{Ui×26, Electoral×8, Surface×3, Shell×5}`, `resources/js/Navigation/nav.js` (Phase C hrefs already stubbed at `/legislature/*`, phase-flagged), `config/cga/surfaces.php` (record shape confirmed), mockups `mockups/legislature/*.html`, `mockups/civic/{petitions,petition-detail,relocation}.html`, `mockups/system/{public-records,term-sync}.html`.

---

## A) COMPONENT SPECS

### A.0 CSS audit (verified by grep over the 16 target mockups vs `resources/css/cga/components.css`)

Every class used by the 16 screens is **already ported**: `.seat-map/.seat-dot(--vacant/--speaker)` (744–766), `.law-diff del/ins` (744–755), `.meter/.meter-fill(--met)/.meter-threshold/.meter-caption` (578–587), `.lifecycle`, `.state-strip`, `.log-row/.log-seq/.log-hash/--rejected`, `.flow-steps`, `.rank-list/.rank-item/.rank-controls`, `.amendable`, `.hardened`, `.form-chip`, `.stat(--accent)`, `.banner--emergency`, `.table`, `.filter-bar/.chip-toggle/.tag-chip`, `.kbd`.

**Append one block** (`/* legislature additions */`, end of components.css — mirrors Phase B's `.roster-row/.stv-action` precedent for the zero-inline-style posture):

1. `.agenda-list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:var(--space-2); }` — the session agenda `<ol>` (mockup inlines this).
2. `.agenda-slot { display:flex; gap:var(--space-3); align-items:flex-start; border:1px solid var(--gov-border); border-radius:var(--radius-lg); padding:var(--space-3); background:var(--gov-surface); } .agenda-slot--locked { border-color:color-mix(in oklch, var(--cc-gold-400) 45%, transparent); }` — locked positions 1–2 get the gold border + lock icon.
3. `.tally-kind { border-inline-start:3px solid var(--gov-border-strong); padding-inline-start:var(--space-3); } .tally-kind--type-b { border-inline-start-color:var(--status-info); }` — visual rail distinguishing per-kind tally cards (color never the only signal: each card carries its kind label).

Nothing else. The circular chamber is an inline SVG (component-generated, no CSS); the flat `.seat-dot` family stays for compact seat strips (CommitteeDetail roster, Oversight vacancy strip).

### A.1 `Legislature/SeatMap.vue` — the circular chamber (port of `chamberSvg()` in `mockups/legislature/legislature-home.html` lines 183–223)

```js
props: {
  members: { type: Array, required: true },
  // [{ id, seat_no, name, speaker: bool, vacant: bool, seat_kind: 'type_a'|'type_b'|null,
  //    days_served: int, vote_share_norm: number|null, district_label: string|null, note: string|null }]
  highlightId: { type: String, default: null },   // outline one member (roster row hover sync)
  maxWidth:    { type: String, default: '22rem' },
}
```

Algorithm (byte-faithful to the mockup, generalized for chamber size):
- **Seniority order**: occupied seats sorted `days_served` desc, ties by `vote_share_norm` desc (q-ledger #q2 normalized share — the same column Phase B certification wrote); vacants appended last ("vacancies join at the junior-most position").
- **Alternating placement**: even positions take seniors from the front, odd take juniors from the back (`lo++ / hi--`) — seniority-alternating seating per the mockup gloss.
- **Rings**: capacities `12, 20, 28, …` (+8 per ring outward); radius `78 + ring*42`. **Dynamic viewBox** (the mockup hardcodes 280 for 9 seats — wrong for San Marino's 41): `size = 2*(78 + (rings−1)*42 + 17 + 8)`; 41 members → 3 rings (12/20/9), size ≈ 378. Render at `max-inline-size: maxWidth; margin-inline:auto`.
- **Seat rendering**: `<g role="img" aria-label>` + `<title>` per seat (label grammar from mockup line 213–215: `"Seat {n} — {name}{ (Speaker)} · {days} days served · share {x.xx}"`, vacant: `"Seat {n} — vacant (countback running); joins at the junior-most position"`). Fill `color-mix(in oklch, var(--gov-primary) 45%, var(--gov-bg))`; Speaker stroke `var(--cc-gold-400)`; vacant fill `var(--gov-surface-2)` + `stroke-dasharray="4 3"`; center dashed "floor" circle.
- **Deliberate delta — bicameral kinds** (the mockup chamber is unicameral; San Marino is not): `seat_kind === 'type_b'` seats render with stroke `var(--status-info)` + a small inner ring, and the component emits a `<figcaption class="gloss">` legend (`"Gold ring = Speaker · dashed = vacant · blue ring = type B (one per constituent) · Art. V §3"`) whenever any member carries `type_b`. Kind is also in every seat's aria-label — never color-only.
- SVG root: `role="img" aria-label="Circular chamber seat map — {n} seats, {serving} serving"`. The adjacent roster table is the accessible data equivalent (page-level contract, as in the mockup).

### A.2 `Ui/LawDiff.vue` — `.law-diff` (law version diffs)

```js
props: {
  segments: { type: Array, required: true },  // [{ op: 'eq'|'del'|'ins', text }] — SERVER-computed
  label:    { type: String, default: 'Law text changes' },
}
```

Renders `<div class="law-diff" role="group" :aria-label="label" data-no-i18n>` with `whitespace:pre-wrap`; `del` → `<del>`, `ins` → `<ins>`, `eq` → text node. **The diff is computed server-side** (`App\Support\TextDiff::segments($old, $new)` — word-level; a presenter concern, never the page's): the frontend renders verbatim segments so what citizens see is exactly what the audit chain hashed. Used by: BillDetail (version-to-version), Settings (old→new value preview), and forward by Phase E challenge remedies (the component is the same one `constitutional-challenge.html` uses). `del/ins` already carry non-color affordances in the ported CSS (strikethrough/underline backgrounds); add `visually-hidden` "removed:"/"added:" prefixes inside each element.

### A.3 `Legislature/VoteTally.vue` — THE vote surface (full detail in §C)

One component for every chamber/committee decision, unicameral or bicameral, all threshold classes. Composes `Ui/ThresholdMeter` ×1 (unicameral) or ×2-per-kind (bicameral: quorum meter + threshold meter per kind).

### A.4 `Legislature/AgendaStrip.vue` — session agenda

```js
props: {
  items: { type: Array, required: true },
  // [{ position, locked: bool, kind: 'emergency_powers'|'constitutional_matters'|'committee_report'
  //    |'priority'|'motion'|'statement'|'other', title, subject: {type,id,href}|null,
  //    status: 'pending'|'in_progress'|'done'|'none' }]
  editable: { type: Boolean, default: false },   // R-10 + session open
}
emits: ['reorder']   // (fromIndex, toIndex) — unlocked items only
```

Renders `<ol class="agenda-list">` → `li.agenda-slot` (`--locked` for positions 1–2): position number (`.flow-step-n`), lock icon + `HardenedChip` tooltip on locked slots ("Constitutional order — cannot be reordered or removed · Art. II §2; §7"), title + subject Link, `StatusBadge`. Slot 1 always renders even when no emergency power is outstanding — status `none`, copy "No outstanding emergency powers" (the locked slot existing-but-empty is the constitutional statement). Slot 2 same for constitutional matters (fed by Phase E challenges; Phase C renders the honest empty state). Reorder = ↑/↓ `Btn`s on unlocked items only (RankList interaction pattern: focus-retained, `useAnnounce`), guarded again server-side by F-SPK-002's handler.

### A.5 Committee preference ranker — **REUSE `Electoral/RankList.vue`, with one generalization**

Verified fit: RankList is click-to-rank ↑/↓/remove, keyboard-operable, focus-retaining — exactly the F-LEG-010 contract ("keyboard up/down rank list, no drag"). Two gaps:
1. Item shape is `{ candidacy_id, name, write_in }` — electoral-specific. **Generalize to `{ id, name, chips: [] }`** and migrate the single call site (`Pages/Elections/RankedBallot.vue` maps `candidacy_id → id`, `write_in → chips:['write-in']`). Small, contained, no behavior change; Vitest suite re-run is the gate.
2. Committees rank **all** committees with none removable (every member ranks the full list; default order = committee creation order). Add prop `removable: { type: Boolean, default: true }` — `false` hides the remove button and the empty-list path.

Committees page then uses `<RankList :model-value="prefs" :seats="committees.length" :removable="false" …>` with parent guidance gloss "Rank every committee — the assignment algorithm honors your order; ties break by normalized vote share (ledger #q2)".

### A.6 Petition signature meter — `Civic/SignatureMeter.vue` (thin ThresholdMeter wrapper)

```js
props: {
  signatures: { type: Number, required: true },
  threshold:  { type: Number, required: true },   // petitions.threshold_count snapshot
  pct:        { type: String, default: '5.00' },  // initiative_petition_threshold_pct at snapshot
  compact:    { type: Boolean, default: false },  // list-row variant (Petitions index)
}
```

Renders `ThresholdMeter :value="signatures" :max="Math.max(threshold*1.15, signatures)" :threshold="threshold"` + caption grammar: left `"{signatures.toLocaleString()} signatures"`, right `"threshold {threshold.toLocaleString()} = {pct}% of population · CLK-17"`; met state appends `StatusBadge success "Threshold reached"`. Exists so Petitions list rows and PetitionDetail render the identical grammar (and so the threshold denominator is always the **snapshot** `threshold_count`, never recomputed client-side).

### A.7 `Legislature/VoteCastList.vue` — published member positions

```js
props: {
  casts: { type: Array, required: true },
  // [{ member_name, seat_kind: 'type_a'|'type_b'|null, value: 'yes'|'no'|'abstain'|'absent',
  //    explanation: string|null, speaker_tiebreak: bool }]
  groupByKind: { type: Boolean, default: false },
}
```

DataTable-style list: member, kind chip (bicameral), value `StatusBadge` (yes=success, no=danger, abstain=neutral, absent=warning with title "counts the same as a no — peg quorum"), `speaker_tiebreak` row gets gold badge "Speaker · tie-breaking vote · F-SPK-004". Explanations render as expandable `details` ("published with the vote · Art. II §2"). Used by: BillDetail (decided floor votes), CommitteeDetail, Oversight (removal votes), SpeakerTools (tie-break record).

### A.8 `Shell/EmergencyBanner.vue` — cross-surface emergency banner

```js
props: { emergencies: { type: Array, required: true } }
// shared prop app.activeEmergencies: [{ id, label, cause, jurisdiction_name, day, max_days,
//   expires_at, declared_by_legislature, under_review: bool, href }]
```

`Banner tone=emergency role=alert`: "{label} — emergency powers active · day {day} of {max_days} · auto-expires {date}" + civic-process protection line verbatim ("Elections, sessions, and courts cannot be disrupted — enforced in code · Art. II §7 · CLK-03") + Link to the declaring legislature's EmergencyPowers page. Wired in `Layouts/AppShell.vue` above `<main>`; `HandleInertiaRequests` shares active powers whose `area` covers any jurisdiction in the viewer's association chain (this is the mockup's "the county sees the state's power because it lies inside the area of effect"). Renders nothing when empty — every page gets the banner for free (session-console, civic home, executive surfaces later).

**Reused, not new**: `Ui/StateStrip` (Bill/Motion/Committee-Seat/Petition/Referendum/Emergency/Vacancy machines — entries added to `config/cga/state_machines.php`), `Ui/LifecycleTracker` (bill lifecycle with done/current), `Ui/ThresholdMeter`, `Ui/LogRow` (PublicRecords + petition audit trail), `Ui/DataTable`, `Ui/AmendableSetting` (Settings register rows), `Ui/HardenedChip`, `Surface/FormCard` (every F-ID), `Ui/FormChip`, `Ui/Stat`, `Ui/PersonaChip`, `Ui/OrgChip`, `Ui/Banner`, `Ui/CitationLine`, `Electoral/StvBar` (chair/speaker RCV round displays), `Ui/Avatar`.

---

## B) PAGE SPECS — all 16

### Shared conventions (carried from Phase A/B)

- Every page: `PageScaffold :surface` from `config/cga/surfaces.php` (16 new entries; ids = mockup ids). Controllers in `app/Http/Controllers/Legislature/`, `Civic/`, `System/`.
- **Routing model** (per-jurisdiction legislatures are live — San Marino + Montegiardino + Earth): canonical routes are legislature-scoped (`/legislatures/{legislature}/…`). The nav's literal `/legislature/*` hrefs become a **resolver prefix**: `GET /legislature/{sub?}` → `ChamberResolverController`: (1) viewer holds a seated `legislature_members` row → 302 to that chamber's sub-path; (2) multiple seats → chooser page listing chambers (also the shell legislature-switcher affordance, WI-9 backlog); (3) no seat → 302 to the smallest associated jurisdiction's active legislature (public read); (4) none active → empty-state page ("No active legislature in your association chain — jurisdictions activate at critical population · CLK-06").
- **Thresholds are never computed client-side.** Every meter renders server-snapshotted `serving / required_yes / quorum_required` from `chamber_votes` / `legislature_sessions` columns (DESIGN_schema_engine §A.3). The frontend displays the engine's arithmetic; it never re-derives `ceil(serving×2/3)`.
- Role gating: page-level reads are mostly public-to-authenticated (legislature business is public record — Art. II §2); **actions** gate by derived role via `can.*` props + engine 422 as the boundary. Members-only surfaces (SessionConsole, SpeakerTools) gate the route.
- All POSTs route through `ConstitutionalEngine::file()`; FormCard injects canonical `form_id`; engine rejections surface as Banner + Field errors with the citation.

### Nav / mapper integration (FE-C0 + FE-C2)

- `resources/js/Pages/Legislature/Index.vue` (the `/legislatures` index): each row gains a **Chamber** link → `/legislatures/{id}/chamber` rendered when the legislature has seated members (`members_count > 0`), alongside the existing district-mapper link (`/legislatures/{slug}`) and election links. Status column gains `seated`/`forming` badge.
- `resources/js/Pages/Legislature/Show.vue` (district mapper): header gains a "Chamber →" Btn (same condition). Reverse link: Chamber page header carries "Districts & maps →" to the mapper.
- `resources/js/Pages/Jurisdictions/Show.vue`: existing "View Legislature & Districts" CTA splits into "Chamber" + "District map" when seated.
- `nav.js`: add `{ id: 'relocation', labelKey: 'nav.relocation', icon: 'globe', href: '/civic/relocation', phase: 'C' }` to the `home` section; `bill-detail`, `committee-detail`, `petition-detail` surfaces map `nav:` to their parent items. Flip `phase:` gates as pages land (phasesLive mechanism unchanged).

---

### B.1 `Legislature/Chamber.vue` — surface `legislature/legislature-home`

Route `GET /legislatures/{legislature}/chamber` → `ChamberController@show`. Nav `legislature-home`. Public read; `can.takeOath` (own unseated member row → F-LEG-001 POST `/members/{member}/oath`).

```js
props: { surface,
  legislature: { id, name, jurisdiction: {id, name}, mode: 'unicameral'|'bicameral',
    seats, serving, quorum, supermajority,                  // engine-snapshotted, glossed
    by_kind: { type_a: {seats, serving}, type_b: {…} } | null,
    term: { ends_on, days_remaining, election_id },          // CLK-01/CLK-10 card
    next_session_due: date|null },                           // CLK-02
  members: [ /* SeatMap shape + endorsements: orgs[], status */ ],
  vacancies: [ { id, seat_no, member_name, status, declared_via, href } ],   // → /vacancies/{id} (Phase B page)
  firstSessions: [ { form_id, name, desc, available_to, basis, done_at|null, note, act_href|null } ],
  mapperHref, can: { takeOath: bool, oathMemberId }
}
```

Composes: Stat row (seats / serving / peg quorum gloss "of all serving, never of those present" / supermajority `stat--accent` with `ceil(serving × 2/3)` gloss; bicameral adds per-kind stats) · **SeatMap** card with the mockup's circular-chamber prose · roster DataTable (seat, member, OrgChips/"no endorsements", `vote_share_norm` mono, status badges incl. "Speaker · neutral"; ledger #q2 citation) · grid-2: term-lockstep card (Stat days-remaining + HardenedChip + CLK-01/CLK-10 citation) + vacancy card(s) (F-LEG-036 FormChip, countback/special badge, links to oversight + the Phase B VacancyCountback page) · **first-sessions checklist** (WF-LEG-01): numbered FormCards, each `done_at` → success badge + act link, else the next undone step renders its live action (e.g. oath Btn when `can.takeOath`; Speaker election → SessionConsole link). Bicameral chambers add a kind legend card ("32 type A across 4 districts + 9 type B, one per castello · both kinds must independently agree · Art. V §3").

Empty/edge: `forming` legislature (Earth) → SeatMap omitted, Banner "Forming — seats fill at certification (WF-ELE-01)" + checklist all-pending; this is the honest pre-election state. Vacant seats render in SeatMap + roster rows (Montegiardino's live vacancy is the fixture).

### B.2 `Legislature/SessionConsole.vue` — surface `legislature/session-console`

Route `GET /legislatures/{legislature}/session` → `SessionController@show`. Nav `session-console`. **Route-gated**: members of this chamber (R-09..R-13) + R-10 + R-29; others 302 → Chamber with flash ("Sessions are run by members; minutes publish to the public record").

POSTs: `/legislatures/{l}/sessions` (F-SPK-001, R-10) · `/sessions/{s}/attendance` (F-LEG-002, self) · `/sessions/{s}/quorum` (F-SPK-003, R-10) · `/sessions/{s}/agenda` (F-SPK-002 reorder, R-10) · `/sessions/{s}/motions` (F-LEG-007) · `/votes/{vote}/cast` (F-LEG-004) · `/sessions/{s}/statements` (F-LEG-006) · `/sessions/{s}/compel` (F-SPK-008, R-10) · `/sessions/{s}/adjourn` (F-SPK-009, R-10/R-29).

```js
props: { surface, legislature: {…as B.1},
  session: { id, session_no, status: 'scheduled'|'open'|'adjourned'|'failed_quorum',
    opened_at, quorum_required, serving_at_open, quorum_met: bool|null,
    attendance: [ {member_id, name, seat_kind, status: 'present'|'absent'|'compelled'|'excused'} ],
    agenda: [ AgendaStrip shape ], minutes_record_href } | null,
  dueBanner: { due_at, days_left } | null,                   // CLK-02
  motions: [ { id, text, status, vote: VoteTally props|null, casts: []|null } ],
  myAttendanceMarked: bool, compulsion: { issued_at, record_href } | null,
  can: { call, setAgenda, publishQuorum, compel, adjourn, submitMotion, vote, statement }
}
```

Composes: EmergencyBanner (shell) · CLK-02 due Banner ("Session due in {n} days … the scheduler compels it · WF-SYS-02") · Call & open card (F-SPK-001 FormCard or open-session badge with `opened_at`) · Attendance & quorum card: per-member present/absent rows (self-toggle F-LEG-002; R-10 sees all), then the **quorum meter** — `ThresholdMeter value=present max=serving threshold=quorum_required` with the peg gloss "majorities compute against all serving members — never those present"; bicameral renders **two quorum meters** (one per kind, q-ledger #q7 — each kind must meet its own peg quorum); F-SPK-003 publish Btn; failure path → F-SPK-008 compulsion FormCard + WF-LEG-20 branch copy (resume / adjourn + reschedule within CLK-02 / repeated → I-ADM referral) · **AgendaStrip** card (F-SPK-002; locked slots 1–2; HardenedChip) · Motions card: Motion StateStrip, motion list (each voted motion renders **VoteTally** + VoteCastList; tie → "4–4 → Speaker broke the tie · F-SPK-004" record), submit FormCard · grid-2: Statements (F-LEG-006 textarea → "entered verbatim into the immutable public record · WF-SYS-03" + confirmation linking the new public_records row) + Adjourn & minutes (F-SPK-009; adjourning resets CLK-02 — confirmation shows the re-armed `next_meeting_due_by`).

Empty/edge: no session ever held → call card only + "First session constitutes the legislature (WF-LEG-01)" pointing at the Chamber checklist. `failed_quorum` session renders the full failure record read-only. Speaker absent (no R-10 yet) → call card replaced by "Speaker election is the first order of the first session" + F-LEG-008 RCV launch (chamber_vote `vote_method=rcv`, `threshold_class=supermajority`; the RCV ballot reuses **RankList** over members; round display reuses **StvBar**).

### B.3 `Legislature/Bills.vue` — surface `legislature/bills`

Route `GET /legislatures/{legislature}/bills` → `BillController@index`; `POST` same (F-LEG-003). Nav `bills`. **Public read**; intro FormCard renders only with `can.introduce` (R-09 of this chamber).

```js
props: { surface, legislature: {…},
  machine: string[],                                          // Bill ESM-07 legend
  bills: [ { id, title, sponsor: {name}, status, act_type, scale_label, scope_label,
             introduced_at, committee: {id,name}|null, enacted_law: {act_number, href}|null,
             challenge: {veto_closes}|null } ],                // Phase E feeds challenge; null now
  filters: { status: [], act_type: [] },
  introForm: { scaleOptions: [ {id, name} ],                  // ≤ this legislature's authority (own + descendants)
               scopeOptions: [ {id, label, phase} ],          // judiciaries; Phase C: forming stubs, labeled
               actTypes: [ {value, label, threshold_gloss} ],
               settingKeys: [ {key, current, bounds} ] },     // for act_type='setting_change' (F-LEG-031 path)
  can: { introduce } }
```

Composes: Bill lifecycle legend (StateStrip from machine) · filterable DataTable registry (status badges per the mockup tone map: In committee=info, On floor=warning, Tabled=neutral, Enacted·Published=success with act link, Failed=danger "archived with votes/explanations") · **Introduction FormCard (F-LEG-003)**: title, law text textarea, **scale** multiselect (hint "cannot exceed this legislature's authority — a parent act may bind named constituent jurisdictions; engine-validated"), **scope** select (judiciary level — Phase C lists the forming judiciary stubs honestly: "{name} (forming · Phase E)"), **act type** select where each option carries its threshold gloss (ordinary "majority of all serving" / setting change "majority + pre-vote bounds validation" / supermajority "ceil(serving×2/3)" / dual supermajority "+ constituent jurisdictions"); choosing `setting_change` reveals setting-key select + proposed-value Field with **live bounds pre-flight** (`POST /legislatures/{l}/bills/validate` → engine `validate()` pure check; out-of-range renders the rejection verbatim: "Rejected pre-vote … no UI, admin panel, or legislative act can carry an out-of-range value" — the Settings page deep-links here pre-filled).

Edge: zero bills → "No bills introduced this term — any member may introduce" + machine legend. Scale/scope fixed at introduction (Art. V §4) — stated on the form, enforced by absent edit affordances on BillDetail.

### B.4 `Legislature/BillDetail.vue` — surface `legislature/bill-detail`

Route `GET /bills/{bill}` → `BillController@show`. Nav `bills`. Public read; vote casting gated.

POSTs: `/bills/{bill}/refer` (F-LEG-007 referral motion / direct-floor branch, R-09/R-10) · `/votes/{vote}/cast` (F-LEG-005 committee / F-LEG-004 floor — same endpoint, body resolves the form) · floor amendment motions via session console.

```js
props: { surface,
  bill: { id, title, sponsor, status, act_type, introduced_at,
          scale: [ {id,name} ], scope: {label}, committee: {id,name,href}|null,
          targets_setting: {key, current, proposed, bounds}|null },
  machine: string[], versions: [ {version_no, change_kind, changed_by, created_at} ],
  diff: { from_version, to_version, segments: [] } | null,     // LawDiff — latest amendment
  lawText: string,                                              // current version full text
  committeeVote: { tally: VoteTallyProps, casts: [] } | null,
  floorVote:     { tally: VoteTallyProps, casts: [] } | null,
  constituentProcess: { total, required, consents: [ {jurisdiction, result, act_href} ] } | null,
                                                                // dual_supermajority acts (multi_jurisdiction_votes)
  enactment: { law: {act_number, href}, effective_at, setting_change: {key, old, new}|null,
               record_href } | null,
  can: { castCommittee, castFloor, refer } }
```

Composes: header (status badge, sponsor PersonaChip, "stored as UTC" citation) · **LifecycleTracker** (machine, current) · Scale & scope card (declared-at-introduction citation F-LEG-003 · Art. V §4) · Law text card (`law-diff`-adjacent mono block; **LawDiff** card when `diff` non-null — "Amended in committee, v2 → v3") · grid-2: **Committee stage** card (FormChip F-LEG-005; **VoteTally** `threshold_class=committee_majority`; per the mockup "needs 2 of 3 — all members, not those present"; cast Btns when `can.castCommittee`; VoteCastList once decided) + **Floor vote** card (FormChip F-LEG-004; **VoteTally** — majority gate + supermajority gate when the act class requires; peg gloss "an absent member counts the same as a no"; cast UI when on-floor + `can.castFloor`) · **bicameral section**: in a bicameral chamber this is not a preview — committee and floor cards each render the dual per-kind tally natively (§C); the unicameral chamber omits the section entirely (the mockup's preview-toggle is a demo affordance, not product) · **constituent-consent card** for `dual_supermajority` acts: meter `consents_yes / total` with `required = ceil(total×2/3)` + DataTable of constituent legislatures' own chamber votes (live from `multi_jurisdiction_votes` + `constituent_consents`) · **enactment card** when enacted: act number → law page anchor in PublicRecords, effective date, "versioned · published (WF-SYS-03) · open to Art. IV §5 challenge (Phase E)", linked `setting_changes` row ("election_interval_months 60 → 48 · dependent clocks re-derived" — the Phase C exit criterion made visible) · failed bills: danger Banner + VoteCastList with explanations ("archived with votes and explanations · Art. II §2").

### B.5 `Legislature/Committees.vue` — surface `legislature/committees`

Route `GET /legislatures/{legislature}/committees` → `CommitteeController@index`. Nav `committees`. Public read; forms gated.

POSTs: `/legislatures/{l}/committees` (F-LEG-009 → opens a supermajority chamber_vote, R-09) · `/legislatures/{l}/committee-preferences` (F-LEG-010, R-09) · `/legislatures/{l}/committees/assign` (F-SPK-005, R-10) · chair RCV via `/votes/{vote}/cast` rankings (F-LEG-011).

```js
props: { surface, legislature: {…},
  committees: [ { id, name, purpose, seats, status, created_by: {act_number, vote_summary, href},
                  chair: {name}|null, alternate: {name}|null, members: [ {name, status} ],
                  by_kind: {type_a: n, type_b: n}|null, bills_count } ],
  allocation: { total_reps, committee_count, seats_per, share_formula },   // "9 ÷ (3 × 3) = 1"
  myPreferences: { rankings: [committee_id], submitted_at } | null,
  assignment: { run_at, tie_breaks: [ {committee, won: {name, share}, lost: {name, share}} ] } | null,
  seatMachine: string[],                                       // Committee Seat ESM-09
  can: { create, submitPreferences, runAssignment, voteChair } }
```

Composes: creation card (F-LEG-009 FormCard: scope/seat-count/purpose; each existing committee shows its creation-act chip "Act 2031-01 · supermajority 7–1" → VoteTally popover) · allocation card (the formula verbatim; "multi-org-endorsed and endorsement-less members are first-class — no faction layer · ledger #q1") · **preference ranker card** (F-LEG-010): generalized **RankList** (`removable=false`, default = creation order), submit → "input to the assignment algorithm" badge; locked read-only after `submitted_at` · **assignment card** (F-SPK-005, R-10): run Btn (disabled until all serving members submitted, with count "6 of 8 submitted"), and after the run the **tie-break table** — "Chen 1.08 beats Okonkwo 0.99 for the last Environment seat — largest vote share after normalizing quotas; the loser's next preference is honored · Art. II §4 · as implemented (ledger #q2)" rendered from `vote_share_norm` · committees DataTable (chair/alternate "whole-legislature RCV · F-LEG-011" with launch Btn when unfilled; bicameral committees show per-kind seat split — committees mirror the chamber-kind ratio, Art. V §3) · Committee Seat StateStrip.

Edge: no committees → creation card + allocation explainer only. Vacancy-driven proportionality recheck renders as a warning chip on the affected committee ("re-check pending the chamber countback · WF-LEG-13").

### B.6 `Legislature/CommitteeDetail.vue` — surface `legislature/committee-detail`

Route `GET /committees/{committee}` → `CommitteeController@show`. Nav `committees`. Public read (hearings are public record); chair/member forms gated.

POSTs: `/committees/{c}/meetings` (F-CHR-001, R-12/R-13) · `/meetings/{m}/agenda` (F-CHR-002) · `/votes/{vote}/cast` (F-LEG-005, R-11) · `/bills/{bill}/refer-to-floor` (F-CHR-003, R-12 — **server-gated on the committee vote having passed**) · `/committees/{c}/reports` (F-CHR-004) · `/meetings/{m}/testimony` (→ public_records).

```js
props: { surface,
  committee: { id, name, legislature: {id,name,href}, seats, chair, alternate,
               members: [ {name, member_id, seat_kind, endorsements: []} ] },
  meeting: { id, called_at, agenda: [] } | null,
  bills: [ { id, title, status, vote: {tally: VoteTallyProps, casts: [], passed: bool}|null,
             referable: bool, report: {filed_at, record_href}|null } ],
  testimony: [ { who, text, recorded_at, record_href } ],
  can: { call, setAgenda, vote, refer, fileReport, testify } }
```

Composes: roster card (PersonaChips + OrgChips + compact `.seat-dot` strip; chair gold; per-kind labels in bicameral) · meeting card (F-CHR-001/002 FormCards or live agenda) · per-bill cards: **VoteTally** `committee_majority` (bicameral: per-kind committee majorities — q7 applies at committee), VoteCastList ("chair pre-voted yes" pattern), **refer-to-floor Btn** — disabled with citation until the vote passes ("enabled only after the committee vote passes · F-CHR-003"); the engine independently rejects premature referral · testimony card (entries as LogRows → public record; submit Field for any authenticated resident when `can.testify` — testimony to public record per WF-LEG-08) · report card (F-CHR-004 → public_records link).

Edge: committee not yet seated → roster shows allocation state + link back to Committees ranker; no bills referred → honest empty state.

### B.7 `Legislature/SpeakerTools.vue` — surface `legislature/speaker-tools`

Route `GET /legislatures/{legislature}/speaker` → `SpeakerController@show`. Nav `speaker-tools`. **Route-gated R-10** (of this chamber); R-09 may view a read-only "what the Speaker can do" variant per the mockup's nav visibility (roles `['R-09','R-10']`, enabled `R-10`) — actions hidden for non-Speakers.

```js
props: { surface, legislature: {…},
  forms: [ { id: 'F-SPK-001'…'F-SPK-009', name, desc, available_to, basis, aliases } ],  // all 9 cards
  tieBreaks: [ { vote_href, context, tally: '4–4', cast: 'yes', outcome: 'adopted 5–4', at } ],
  priorities: [ { id, who, text, when, agenda_status } ],
  pendingProceedings: [ { id, kind, subject, presiding_blocked: bool } ],   // F-SPK-007; own-case shows blocked
  can: { facilitate, preside } }
```

Composes: the 9 F-SPK FormCards rendered from FormRegistry (name first, ID second, availableTo, basis, aliases) — each with a "go to surface" link (F-SPK-001/002/003/008/009 → SessionConsole; F-SPK-005 → Committees; F-SPK-007 → Oversight) so this page is the Speaker's launchpad, not a duplicate console · neutrality card (HardenedChip: "politically neutral · votes only to break ties · Art. II §3") · **tie-break record** card (VoteCastList rows flagged `speaker_tiebreak`; "1 this term") · **priorities queue** (F-SPK-006): member-submitted priority items DataTable with "add to next agenda" action → AgendaStrip unlocked slots · presiding card: pending removal proceedings with the own-case guard surfaced ("You are the subject — the engine blocks you from presiding · Art. II §3"; the blocked state renders, the engine enforces).

Edge: no Speaker elected → page 302s to SessionConsole's speaker-election state.

### B.8 `Legislature/Oversight.vue` — surface `legislature/oversight`

Route `GET /legislatures/{legislature}/oversight` → `OversightController@show`. Nav `oversight`. Read for chamber members + R-29; investigation **findings** publish to PublicRecords (public). Intake POST open to any authenticated resident (`/legislatures/{l}/investigations` — misconduct intake "from any resident/member/own motion").

POSTs: intake · `/investigations/{i}/refer` (R-29) · `/legislatures/{l}/removal-proceedings` (F-SPK-007 presiding + F-LEG-022 vote open) · `/votes/{vote}/cast` · `/legislatures/{l}/vacancies` (F-LEG-036, R-09/R-10).

```js
props: { surface, legislature: {…},
  adminOffice: { status, staff: [ {name, term_ends} ] } | null,            // I-ADM, R-29, F-LEG-013
  investigations: [ { id: 'INV-2031-NN', subject, re, state, findings_record_href|null } ],
  proceedings: [ { id, kind, subject, presided_by, vote: {tally: VoteTallyProps, casts: []}|null,
                   outcome } ],
  vacancies: [ { id, seat, member, status, machine_index, countback_href, special: {scheduled_for}|null } ],
  can: { intake, refer, openProceeding, declareVacancy, vote } }
```

Composes: I-ADM card (created-by act F-LEG-013, neutrality chip, rules-of-order/ethics-code act links F-LEG-032/033) · investigations DataTable (Intake → Investigating → Referred → Closed badges; findings → public record links) · **removal card**: F-LEG-022 FormCard ("removal parity — legislators, executives, judges removed by the same standard"; Speaker presides except own case); live proceedings render **VoteTally** `supermajority` ("needs {ceil(serving×2/3)} of {serving} — vacancies stay in the denominator") + VoteCastList; `removed` outcome shows the auto-created vacancy chip · **vacancy strip**: Vacancy StateStrip per row (Detected → Declared → Countback-Running → Filled | Countback-Failed → Special-Election-Scheduled), F-LEG-036 declaration FormCard (alias citation "catalog alias: F-LEG-030"), links into the Phase B `/vacancies/{id}` countback page — **this page replaces Phase B's `vacancy:declare` dev command** (FE-B8 deferral closed). Montegiardino's live vacancy + scheduled special is the acceptance fixture.

### B.9 `Legislature/Referendums.vue` — surface `legislature/referendums`

Route `GET /legislatures/{legislature}/referendums` → `ReferendumController@index`. Nav `referendums`. Public read; delegation/modification gated R-09.

POSTs: `/legislatures/{l}/referendums` (F-LEG-023 → supermajority chamber_vote) · `/laws/{law}/referendum-modification` (F-LEG-034 — **CLK-19 server gate**).

```js
props: { surface, legislature: {…},
  machine: string[],                                          // Referendum Question ESM-11
  queue: [ { id, question, threshold: 'majority'|'supermajority', origin: 'delegation'|'petition',
             via: {form, act_href|petition_href}, election: {id, label, href}|null, status } ],
  results: [ { id, title, threshold, yes_pct, passed, law: {act_number, href},
               shielded: bool, shield_expires_with: {election_label} } ],
  delegateForm: { actTypes: [ {value, threshold_derived} ] },
  can: { delegate, modify: { law_id: bool } } }
```

Composes: delegation FormCard (F-LEG-023, supermajority chip): question text + law text + act type — **threshold field is read-only/derived** ("derived from the act type — never editable"; majority-class → majority of population, supermajority-class → 2/3); submit opens the supermajority **VoteTally** · queue DataTable ("queues to the next jurisdiction-wide ballot · WF-ELE-07"; petition-origin rows link their petition; election link once scheduled — this is what fills Phase B's RankedBallot referendum slot) · results DataTable with per-row **modify Btn**: enabled for majority-passed acts (F-LEG-034, supermajority) — **disabled with the CLK-19 citation** for population-supermajority acts ("Blocked this term · passed by population supermajority · CLK-19 · hardened"); post-election rows show "ordinary law since {election} — protection lapsed (WF-LEG-18)" · Referendum Question StateStrip.

Edge: empty queue + no results → machine legend + delegation form only.

### B.10 `Legislature/EmergencyPowers.vue` — surface `legislature/emergency-powers`

Route `GET /legislatures/{legislature}/emergency-powers` → `EmergencyPowerController@index`. Nav `emergency-powers`. **Public read** (citizens must see active powers); invoke/renew gated R-09.

POSTs: `/legislatures/{l}/emergency-powers` (F-LEG-024 → supermajority vote) · `/emergency-powers/{p}/renewals` (F-LEG-025).

```js
props: { surface, legislature: {…},
  machine: string[],                                          // ESM-12
  active: [ { id, label, cause, day, max_days, expires_at, area: {label, geom_href},
              methods, invoke_vote: VoteTallyProps, renewals: [ {extension_days, vote_summary} ],
              renewal_window: {opens_day, open_now}, judicial_review: 'pending'|'none'|… } ],
  expired: [ { id, label, expired_at, record_href } ],
  invokeForm: { maxDays: 90, areaOptions: [ {id, name} ] },   // own + descendant jurisdictions only
  can: { invoke, renew } }
```

Composes: **invoke FormCard (F-LEG-024)**: cause **closed enum radio** (`natural disaster` | `actual invasion` — "Economic, political, or public-order rationales are rejected pre-vote"); duration number Field `min=1 max=90` with **pre-vote engine validation** (out-of-range → inline Field error verbatim: "Rejected pre-vote: exceeds the 90-day constitutional ceiling · CLK-03"); area select ("≤ this legislature's authority"); methods textarea ("within constitutional order"); submit opens supermajority **VoteTally** · per-active-power card: Emergency StateStrip + countdown Stat ("day {n} of {max} · auto-expires {date} — no action required; nothing rolls over silently"), area + methods, invoke-vote record, **renewal panel** ("window opens day {opens_day} · fresh supermajority · fresh ≤90-day maximum" — F-LEG-025 FormCard enabled only inside the window; engine re-validates), **judicial review panel** (F-JDG-007 chip; "available at any time, by any inhabitant" — filing link `planned-flag` Phase E; review status badge), hard-rails card (HardenedChip: "cannot disrupt elections, sessions, courts, or any civic process — enforced in code · Art. II §7"; "first order of business at every session" → AgendaStrip slot-1 cross-link) · expired register (auto-expiry publishes a full audit record — public record links).

Edge: no powers ever → "No emergency powers have ever been invoked in this jurisdiction" + the invoke form + rails card (the rails render even when nothing is active — that is the point).

### B.11 `Legislature/Settings.vue` — surface `legislature/settings`

Route `GET /legislatures/{legislature}/settings` → `SettingsController@show`. Nav `settings`. Public read; propose gated R-09.

```js
props: { surface, legislature: {…},
  settings: [ { key, value, meta, bounds: {min,max}|{whitelist}|null, basis,
                enacted_by: {act_number, href, effective_at}|null,        // setting_changes provenance
                inherited_from: {jurisdiction_name}|null } ],             // parent-chain resolution
  hardenedFloor: { supermajority_floor: 'majority+1', proportionality_ratchet: true },
  can: { propose } }
```

Composes: the 17-key register — one `AmendableSetting` row per key (value, bounds, basis, enacting act link or "founding value · inherited from {ancestor}") grouped as the mockup orders them; lockstep pair (civil/judicial years) rendered as one joined row ("must stay in lockstep · CLK-09/CLK-10") · hardened-floor card (HardenedChip: supermajority never below majority+1; voting_method "only MORE proportional" whitelist; "no UI, admin panel, or legislative act can carry an out-of-range value") · **"Propose change" per row** → deep-link to Bills intro pre-targeted (`/legislatures/{l}/bills?intro=1&setting={key}` — act_type pre-set `setting_change`, key locked, current value shown) with the **live bounds validator** inline (same `bills/validate` pre-flight as B.3: in-range → "proceeds to the bill flow · WF-LEG-14"; out-of-range → rejection verbatim + the `rejected=true` audit row reference) · changes history DataTable from `setting_changes` ("election_interval_months 60 → 48 · Act 2032-02 · effective {date} · dependent clocks re-derived" — **the exit-criterion receipt**: the row links the re-armed CLK-01 timer on TermSync).

### B.12 `Civic/Petitions.vue` — surface `civic/petitions`

Route `GET /civic/petitions` → `Civic\PetitionController@index`; `POST` (F-IND-009); `POST /petitions/{p}/signatures` + `DELETE` (F-IND-010, revocable). Nav `petitions`. Auth; create requires R-03 (engine-enforced; page explains, never 403s).

```js
props: { surface,
  petitions: [ { id, title, jurisdiction: {name, adm_chip}, state, signatures, threshold_count,
                 pct, scale_label, scope_label, signed_by_me: bool, href } ],   // scoped to association chain
  machine: string[],                                          // ESM-10
  thresholdSetting: { pct: '5.00', key: 'initiative_petition_threshold_pct', clock: 'CLK-17' },
  createForm: { scaleOptions: [ {id, name, population, threshold_preview} ] }, // viewer's chain
  isAssociated: bool }
```

Composes: Petition StateStrip + AmendableSetting threshold callout · petition list (Card rows: title, AdmChip, state badge, **SignatureMeter** `compact`, scale/scope line, **sign/withdraw toggle** per row — revocable, `ApproveSwitch`-pattern button labeled Sign/Signed; disabled past `signature_audit` with title "audited count frozen at threshold check") · **create FormCard (F-IND-009)**: title, lawText textarea ("the binding text voters ratify — required"), scale select with **live threshold preview** ("≈ {round(pop × 5%)} signatures at {name}'s population" — recomputed per option from props, no request), scope ("the same fields a bill carries"); submit → enters at *Created*, gathering opens immediately.

Edge: `!isAssociated` → list still renders (public reading), create form replaced by the residency CTA card (same pattern as Phase B CandidacyRegistration). Zero petitions → "No open petitions in your association chain — any associated resident can create one".

### B.13 `Civic/PetitionDetail.vue` — surface `civic/petition-detail`

Route `GET /civic/petitions/{petition}` → `PetitionController@show`. Nav `petitions`. Public read; sign toggle as B.12.

```js
props: { surface,
  petition: { id, title, creator, jurisdiction, state, law_text, scale: [], scope_label,
              signatures, threshold_count, pct, signed_by_me },
  machine: string[], currentState,
  audit: { result: {valid, pct_valid, still_above: bool}, board_name, completed_at,
           record_href } | null,                              // F-ELB-005
  review: { status: 'pending'|'validated'|'invalidated', court_label, opinion_record_href|null,
            stubbed: bool } | null,                            // F-JDG-008 — Phase E stub
  ballot: { election_id, label, href } | null }
```

Composes: **LifecycleTracker** (done/current through the 9-state machine) · law text blockquote (mono) · scale + scope cards · **SignatureMeter** (full; "signatures stay open during review; the audited count is frozen at the threshold check") + sign toggle · **audit card**: F-ELB-005 FormCard-as-record from the registry (board, timestamp, "1,031,778 valid = 98.8% — still above threshold" grammar from `audit_result` jsonb; kill-path copy "too many invalid → invalidated") — this is an R-08 action surfaced read-only here; the BoardConsole's Phase B signature-audit panel (shipped as empty-state) goes live against the same data · **review card**: F-JDG-008 chip; Phase C renders the **stub honestly** — `review.stubbed=true` → "Constitutional review · awaiting judiciary (Planned · Phase E). Petitions at this stage hold until review exists; the kill-path is constitutional, not skippable." (deferral justified below) · on-ballot card linking the WF-ELE-07 referendum question/election when scheduled.

### B.14 `Civic/Relocation.vue` — surface `civic/relocation`

Route `GET /civic/relocation` → `Civic\RelocationController@show`. Nav `relocation` (new item, home section). Auth, R-03.

POSTs: `/civic/relocation/travelling` (reset away-pattern detection — engine action, no F-ID, audit-chained like Phase B approvals) · "I'm moving" → links to `/civic/residency` pre-set for re-declaration (reuses F-IND-003 — no new form, per the mockup contract).

```js
props: { surface,
  detection: { away_days, threshold_days, detected_near: {label}|null, since } | null,
  homeClaim: { jurisdiction: {name}, status, declared_at },
  heldOffices: [ { kind: 'legislature_seat'|…, label, grace: {day, of}, vacates_into: 'countback' } ],
  newClaim: { jurisdiction, qualifying_days, threshold_days, status } | null,   // in-flight move
  machine: string[] }   // Residency Claim ESM-02
```

Composes: away-pattern card (**ThresholdMeter** `away_days/threshold_days` — same CLK-05 threshold; "{n} of {t} qualifying days near {label}") · home-vs-detected map card (Leaflet, two boundary layers via existing tile endpoints — same plumbing as Civic/Residency) · **choice buttons**: "I'm travelling" (POST; "detection resets, nothing changes — pings pausable in personal settings") vs "I'm moving" (3-step explainer `flow-steps`: declare in new jurisdiction → away-pattern reaches threshold → associations transfer; CTA → Residency) · **held-office card** rendered only when the viewer holds a seat: "You hold this seat — Seat {n}, {chamber}. Grace period day {d} of {g}; if associations transfer, the seat vacates into countback (F-LEG-036 → WF-ELE-03) · Art. II §5" · zero-rights-gap Banner (info, hardened): "Your old claim stays Active until the new one Verifies — no rights gap, ever" · in-flight move renders the new claim's progress meter + the Superseded-pending state on the old.

Edge: no away-pattern detected → calm empty state ("No relocation pattern detected — this page activates when sustained pings appear outside your declared jurisdiction"), machine legend, link to Residency.

### B.15 `System/PublicRecords.vue` — surface `system/public-records`

Route `GET /system/public-records` → `System\PublicRecordsController@index` (cursor-paginated; filter query params). Nav `public-records`. **Authenticated read for all roles** (unauthenticated/federation read is a Phase F decision); statement composer gated R-09.

`POST /system/public-records/statements` (F-LEG-006 — same handler the SessionConsole composer uses; `subject` selects bill/session/vote/general). Full spec in §D.

### B.16 `System/TermSync.vue` — surface `system/term-sync`

Route `GET /system/term-sync` → `System\TermSyncController@show`. Nav `term-sync`. Public read; zero actions (the page's whole point is that there is **no API** here).

```js
props: { surface,
  legislatures: [ { id, name, jurisdiction, term: {starts_on, ends_on}, interval_months,
                    next_election: {clock_due_at, election_id|null}, chamber_href } ],
  lockstepRoles: [ { kind: 'legislature_seat'|'executive_seat'|'judicial_seat', count, ends_on } ],
  civilTerms: [ { kind: 'election_board_member'|'board_governor'|'admin_staff', count,
                  years: 10, clock: 'CLK-09' } ],
  refusals: [ { attempt, citation, audit_seq|null } ] }   // recorded engine rejections, if any
```

Composes: single-clock card per live legislature ("legislative term defines `election_interval_months` · CLK-01; elected executive + judicial terms equal it · CLK-10 structural — next election exists from the moment the prior certifies: {due_at}") with the **armed CLK-01 timer's real `due_at`** from `clock_timers` · lockstep table (every term-bearing elected role anchored to its legislature's clock; San Marino + Montegiardino rows are the live fixtures) · decoupled card (appointed officers, 10-yr CLK-09, "deliberately decoupled") · **engine-refusals card** (HardenedChip): the four refusal rules verbatim — no skip/delay/reschedule API; no extension past common expiry even under emergency powers; no executive/judicial drift; "vacancies never reset the clock — countback/special winners serve the remainder" — plus any real recorded rejection rows (LogRow, `rejected` styling, linking audit seq) · staggered-activation note ("jurisdictions activate at their own thresholds; lockstep harmonization toward a shared election day is an encompassing-level end-state").

---

### Surface registry — the 16 ids to append to `config/cga/surfaces.php`

`legislature/legislature-home` · `legislature/session-console` · `legislature/bills` · `legislature/bill-detail` · `legislature/committees` · `legislature/committee-detail` · `legislature/speaker-tools` · `legislature/oversight` · `legislature/referendums` · `legislature/emergency-powers` · `legislature/settings` · `civic/petitions` · `civic/petition-detail` · `civic/relocation` · `system/public-records` · `system/term-sync` — roles/workflows/forms/clocks/citations copied from each mockup's `CGA_PAGE` block + the EXPLORE contract tables (form `availableTo` + alias resolution via FormRegistry as established). State-machine config entries added: `bill`, `motion`, `committee_seat`, `petition`, `referendum_question`, `emergency_powers` (`vacancy` exists from B).

---

## C) THE BICAMERAL VOTE UI — `Legislature/VoteTally.vue`

One component renders **every** chamber/committee decision; mode is data-driven by the chamber, never a toggle (the mockup's bill-detail preview-toggle was a demo affordance — in product, San Marino votes are always dual, Montegiardino always single).

```js
props: {
  mode:   { type: String, required: true },   // 'unicameral' | 'bicameral'  (legislatures.type_b_seats > 0)
  stage:  { type: String, default: 'floor' }, // 'committee' | 'floor' — caption grammar only
  thresholdClass: { type: String, required: true },
  // 'majority'|'supermajority'|'committee_majority'|'bicameral_majority'|'bicameral_supermajority'|'rcv'
  // UNICAMERAL: single block
  serving:     Number,  requiredYes: Number,           // chamber_votes.serving_snapshot / required_yes
  tallies:     Object,                                 // { yes, no, abstain } | null (pending)
  quorum:      Object,                                 // { present, required } | null — session-level context
  // BICAMERAL: one entry per kind — ALL numbers server-computed
  kinds: { type: Array, default: null },
  // [{ kind: 'type_a'|'type_b', label, serving, requiredYes, yes, no, abstain,
  //    quorum: {present, required}, agreed: bool|null }]
  outcome: { type: String, default: 'pending' },       // 'pending'|'adopted'|'failed'|'tied'|'tied_broken'
  speakerTiebreak: Boolean,
  basis: { type: String, default: 'Art. II §2' },
}
```

**Unicameral render** (Montegiardino, 8 serving): one `ThresholdMeter value=yes max=serving threshold=requiredYes` + caption `"{yes} yes of {serving} (all serving)"` / `"needs {requiredYes} of {serving} · {basis}"`; supermajority classes append the formula gloss `"ceil({serving} × 2/3) = {requiredYes}"` — **display of the server snapshot, never client arithmetic**; the peg gloss "an absent member counts the same as a no" renders under every meter; outcome StatusBadge; `tied_broken` renders the gold F-SPK-004 line.

**Bicameral render** (San Marino: type_a 32 → majority 17 / supermajority 22; type_b 9 → 5 / 6): a `grid-2` of two `card--inset.tally-kind` blocks (type_b adds `--type-b` rail + label — never color-only), each containing:
1. **Quorum meter** — `ThresholdMeter present/serving threshold=quorum.required`, caption "peg quorum of this kind: {required} of {serving} serving" (q-ledger #q7: *each kind meets its own peg quorum*; vacancies stay in this denominator);
2. **Threshold meter** — `ThresholdMeter yes/serving threshold=requiredYes`, caption "{yes} yes of {serving} (all serving of this kind)" / "needs {requiredYes} · Art. V §3 · ledger #q7";
3. per-kind agreement badge ("This kind agrees" / "does not agree" — mockup grammar).

Below the grid, the combined-outcome Banner: adopted → "Both kinds agree {at committee | on the floor} — the act passes"; failed → "A failure in either kind, at either stage, fails the act" with the failing kind named; citation "Independent agreement of both seat kinds — {majority|supermajority} of all serving of each kind · Art. V §3 · as implemented (ledger #q7) · WF-LEG-07".

**Where it appears** (the same component everywhere): BillDetail committee + floor cards (committee stage in a bicameral chamber renders per-kind committee majorities — q7 binds at committee AND floor); SessionConsole motions; Committees creation acts; Oversight removal votes; Referendums delegation; EmergencyPowers invoke/renew; Speaker/chair RCV votes render VoteTally for the supermajority outcome + `Electoral/StvBar` rounds for the count itself. Data source is always one `chamber_votes` row (`serving_by_kind` + `tallies` jsonb per-kind for bicameral; `required_yes` engine-snapshotted) — the component is a pure renderer of the engine's record, which is the constitutional posture: if the UI and the engine ever disagree, the audit chain shows the engine.

Casting UI (when the viewer may vote): yes/no/abstain `Btn` cluster + optional explanation textarea ("published with your vote · Art. II §2") → `POST /votes/{vote}/cast`; the member's own cast renders back highlighted in VoteCastList.

---

## D) PUBLIC RECORDS PAGE — `System/PublicRecords.vue` vs `/system/audit-chain`

**The distinction, stated on both pages:**

| | `/system/public-records` (Phase C, new) | `/system/audit-chain` (Phase A, exists) |
|---|---|---|
| Source table | `public_records` (curated, append-only) | `audit_log` (raw hash chain) |
| Audience | Citizens — "public, readily available, immutable records with translations" (WF-SYS-03, Art. II §2) | Auditors — every state transition incl. **rejections**, hashes, chain verification |
| Contents | statements, votes (with explanations), acts/laws, certifications, opinions, residency/participation outcomes | everything above **plus** engine denials, clock fires, impersonation, internal transitions — payload hashes, prev/hash links |
| Mutation story | corrections **append** superseding entries (`supersedes_record_id`) | nothing ever supersedes; tampering raises |
| Link between them | each record carries `audit_seq` — "sealed into the audit chain at commit" chip → `/system/audit-chain?seq=N` | chain entries do not link back (the chain doesn't know about curation) |

Props + render:

```js
props: { surface,
  records: { data: [ { seq, kind, title, body_excerpt, actor_display, jurisdiction: {name}|null,
                       via: {form|workflow|clock}, published_at, audit_seq,
                       translations: { done, total, locales: [{code, quality}] },
                       supersedes: {seq, href}|null, subject: {type, label, href}|null } ],
             cursor },
  filters: { modules: [], legislatures: [ {id, name} ], kinds: ['statement','vote','act','certification','opinion','other'] },
  stats: { total, acts, votes, statements },
  can: { statement: bool } }                                   // R-09
```

Composes: FilterBar (module select, legislature select — **the per-legislature filter is the citizen's view into any chamber**, kind ChipToggles, date range, search) · the feed: **`Ui/LogRow` reuse** (`seq`, no `hash` prop — hashes live on the audit page; instead a trailing "sealed · audit #{audit_seq}" `FormChip`-style link), row body = kind badge + title + actor + via-chip (form/WF/CLK) + UTC-stored citation + **translation badge** (`{done}/{total} languages` with per-locale machine/human quality on hover — pipeline state from `translations` jsonb) · correction rows render "supersedes #{seq}" with both visible ("corrections append, never edit") · **statement composer** (F-LEG-006, `can.statement`): textarea + attach-to select (bill/session/vote/general — subject search) · hardened footer: "Record-keeping cannot be suspended under emergency powers · nothing is publishable-optional · Art. II §2 · WF-SYS-03" + the audit-chain cross-link card explaining the table above in one sentence each way.

Entity transitions feed the page automatically (every published Bill/Election/Session transition = one record row from the Phase C records pipeline) — the page is a reader; it owns only the composer.

Edge: pre-Phase-C records exist already (Phase A skeleton wrote `registration`/`residency`/`participation` kinds) — the page is non-empty on day one; an empty filtered view states which filter produced it.

---

## E) WORK-ITEM BREAKDOWN

Backend WI names referenced (align to the Phase C backend section at merge): **C-SESSIONS** (sessions/attendance/CLK-02), **C-VOTES** (chamber_votes/vote_casts/multi_jurisdiction_votes + engine threshold snapshots), **C-BILLS** (bills/versions + F-LEG-003/004/005/007), **C-LAWS** (laws/versions/setting_changes + enactment + clock re-derive), **C-COMMITTEES** (committees/seats/preferences + F-SPK-005 algorithm), **C-ADMIN** (admin_offices/investigations/removal_proceedings + F-LEG-022/036), **C-REFERENDUM** (referendum_questions + CLK-19 gate), **C-EMERGENCY** (emergency_powers/renewals + CLK-03), **C-PETITIONS** (petitions/signatures + CLK-17 + F-ELB-005), **C-RECORDS** (public_records pipeline + F-LEG-006), **C-RELOCATION** (away-pattern detection on ResidencyService).

Sizes S/M/L as Phase A/B. Verification posture unchanged: `docker compose exec app php artisan test --filter=…` + curl `X-Inertia: true` props assertions + impersonated browser pass.

### Group 0 — zero-backend (starts day 1)

| WI | Item | Size | Depends | Verification |
|---|---|---|---|---|
| **FE-C0** | 16 surface entries · nav (resolver hrefs unchanged, `relocation` item, detail-page `nav:` mappings, phasesLive flips per landing page) · state-machine config (6 entries) · CSS append (`.agenda-list/.agenda-slot/--locked/.tally-kind/--type-b`) · `ChamberResolverController` skeleton | S | — | SurfaceMeta registry test extends to 16 ids; qa_scan over the CSS append; resolver unit test (seat → 302, no seat → smallest associated, none → empty state) |
| **FE-C1** | Component kit, **fixture-first**: SeatMap (9-member NY-County fixture from the mockup + a 41-member San Marino fixture w/ type_b + vacancy + speaker), LawDiff, **VoteTally (all 6 threshold classes × uni/bicam × pending/adopted/failed/tied-broken)**, AgendaStrip (locked/empty/reorder), RankList generalization (`id`/`removable` + RankedBallot call-site migration), SignatureMeter, VoteCastList, EmergencyBanner — all on `/dev/legislature-kit` (debug-env harness page beside `/dev/electoral-kit`) | L | FE-C0 | Vitest: SeatMap ring math (9→1 ring/viewBox 280-class, 41→3 rings/scaled viewBox), seniority-alternation order pinned against the mockup algorithm, aria-labels; VoteTally renders **only** server numbers (test feeds `requiredYes=99` and asserts no client ceil); RankList suite green post-migration incl. `removable=false`; LawDiff del/ins + hidden prefixes; AgendaStrip locked slots unreorderable. Browser pass on the harness: keyboard-only ranking + agenda reorder; RTL + pseudo-locale spot check |

### Group A — the chamber spine (sequential; carries the Phase C exit criterion)

| WI | Item | Size | Depends | Verification |
|---|---|---|---|---|
| **FE-C2** | Chamber + ChamberController + resolver wiring + **nav integration** (Legislature/Index Chamber links, Show.vue header link, Jurisdictions/Show CTA split) | M | C-SESSIONS (term/serving snapshots), FE-C1 | curl `/legislatures/{sanMarino}/chamber` → props: mode bicameral, serving 41, by_kind 32/9, members carry `vote_share_norm`; Montegiardino shows vacancy dot + 8 serving; Earth shows `forming` empty state; `/legislature` as seated member 302s correctly; `/legislatures` index rows show Chamber links |
| **FE-C3** | SessionConsole + SessionController + attendance/quorum/agenda/motion/statement/adjourn endpoints | L | C-SESSIONS, C-VOTES, FE-C2 | walkthrough as impersonated Speaker: call → attendance → quorum publish (Montegiardino: 5 of 8; mark 4 present → failure branch + F-SPK-008 card) → agenda locked slots → motion → vote → tie (4–4 seeded) → F-SPK-004 → adjourn → CLK-02 re-armed (`clock_timers` due_at asserted) + minutes row in public_records; bicameral session asserts **two** quorum meters |
| **FE-C4** | Bills + BillDetail + BillController + intro/refer/cast endpoints + bounds pre-flight | L | C-BILLS, C-VOTES, C-LAWS, FE-C3 | **the exit-criterion walkthrough, both modes**: Montegiardino — introduce ordinary bill → refer → committee 2-of-3 → floor 5-of-8 → enacted → law version 1 + act link + public record; San Marino — same bill passes type_a 17/32 AND type_b 5/9 at committee and floor (assert per-kind meters + combined banner); then fail type_b only → act fails with the failing kind named; out-of-range F-LEG-031 value → 422 citation + `rejected=true` audit row surfaced in the form error |
| **FE-C5** | Settings + SettingsController + pre-targeted bill deep-link + changes history | M | C-LAWS (setting_changes), FE-C4 | **exit-criterion second half**: propose `election_interval_months 60→48` from the register row → pre-targeted bill → enact → register shows new value + act provenance; `clock_timers` CLK-01 `due_at` re-derived (assert via TermSync props in FE-C10); propose 9→12 max_seats → rejected pre-vote verbatim |

### Group B — committees, speaker, oversight (parallel with Group A from FE-C2 on)

| WI | Item | Size | Depends | Verification |
|---|---|---|---|---|
| **FE-C6** | Committees + CommitteeDetail + CommitteeController + preferences/assign/chair-RCV/hearing endpoints | L | C-COMMITTEES, C-VOTES, FE-C2 | walkthrough: create 3 committees (supermajority VoteTally each) → all members rank (RankList `removable=false`) → F-SPK-005 run → seats match the allocation formula; seed a tie → assert the winner is the higher `vote_share_norm` and the table names both shares (ledger #q2 pinned by the backend test; UI asserts display); chair RCV → R-12/R-13 badges; refer-to-floor disabled until the committee vote passes, engine 422 on direct POST |
| **FE-C7** | SpeakerTools + SpeakerController + priorities queue | M | FE-C3 | impersonated R-10 sees all 9 F-SPK cards + launchpad links; R-09 sees read-only variant, actions absent; priority item → appears in next session's unlocked agenda; own-case presiding block renders + engine 422 |
| **FE-C8** | Oversight + OversightController + intake/proceeding/vacancy endpoints (**replaces Phase B `vacancy:declare` dev command**) | M | C-ADMIN, C-VOTES, FE-C2 | intake → investigating → refer; removal proceeding: VoteTally needs `ceil(8×2/3)=6` of 8 (Montegiardino) — assert vacancies stay in the denominator; `removed` → vacancy row → link lands on the Phase B `/vacancies/{id}` page and the countback runs; F-LEG-036 declared from this page end-to-end |

### Group C — powers, civic, system (parallel; each lands independently)

| WI | Item | Size | Depends | Verification |
|---|---|---|---|---|
| **FE-C9** | Referendums + EmergencyPowers + controllers | L | C-REFERENDUM, C-EMERGENCY, FE-C2 | delegation: threshold field read-only-derived; queued question appears on the next election's RankedBallot (Phase B slot fills — assert `referendum` prop non-null); CLK-19: population-supermajority act's modify Btn disabled with citation + engine 422 on direct POST; emergency: cause enum closed (form has no third option; direct POST `cause=economic` → 422 verbatim), duration 91 → inline field error, invoke supermajority VoteTally, **EmergencyBanner appears on every page for residents inside the area** (assert on Civic/Home + SessionConsole), renewal window gating, dev clock advance → auto-expiry + audit record |
| **FE-C10** | Petitions + PetitionDetail + Relocation + controllers; TermSync (read-only) | L | C-PETITIONS, C-RELOCATION, FE-C1; TermSync needs only B's terms/clocks | petition: create with live threshold preview → sign/revoke → seed to threshold → F-ELB-005 audit panel in BoardConsole goes live (Phase B empty state retired) → audit card renders result grammar → review card shows the honest Phase E stub; relocation: simulate away-pings → meter + held-office grace card (seed an R-09 viewer) → "travelling" resets; TermSync: CLK-01 due_at matches `clock_timers` after FE-C5's settings change (the re-derive receipt), refusal rules card renders |
| **FE-C11** | PublicRecords + controller + F-LEG-006 composer + cursor pagination + translation badges | M | C-RECORDS, FE-C1 | feed shows Phase A+B rows day one; filter by legislature=Montegiardino shows only its chamber's records; statement composer (R-09) → row appears with `audit_seq` chip → chip lands on `/system/audit-chain` at that seq; correction row renders both entries; non-R-09 sees no composer; `audit:verify` still green after the session's writes |

Critical path: **FE-C0 → FE-C1 → FE-C2 → FE-C3 → FE-C4 → FE-C5** (exit criterion). FE-C6/7/8 fork after FE-C2/C3; FE-C9/10/11 fork after FE-C2. The fixture-first harness (FE-C1) means SeatMap, VoteTally, LawDiff, AgendaStrip and the tally grammar are pixel/a11y-verified before any Phase C backend exists — the per-page WIs are wiring.

### Deferrals (justified)

1. **F-JDG-008 constitutional review of petitions** — judiciary is Phase E. PetitionDetail renders the review stage as an honest hold ("awaiting judiciary · Planned · Phase E"); petitions cannot reach `validated`/`on_ballot` past a real review, so Phase C petitions park at `constitutional_review` unless the operator dev-advances. The kill-path is constitutional — stubbing the *decision* would be worse than stubbing the *stage*.
2. **Challenge-fed agenda slot 2 + bill `[Challenged]` states** — Phase E; AgendaStrip renders the locked slot with its `none` state, BillDetail omits the challenge card until `challenge` prop arrives.
3. **Court-appeal links** (oversight referrals, petition invalidation) — `planned-flag` Phase E, consistent with Phase B.
4. **Dual-supermajority act *initiation* UI beyond the consent meter** (F-LEG-028 etc. on the bills act-type select) — the act type renders and the `multi_jurisdiction_votes` consent panel works (tables are Phase C), but constituent chambers casting their consent votes is just each constituent's own BillDetail/motions surface — no extra screen; cultural-institution/union/disintermediation *subjects* are D/F.
5. **Public-records translation pipeline** — badges render pipeline state from `translations` jsonb; the MT/human-review pipeline itself is backend C-RECORDS scope, and full i18n is Phase F. Chrome-only i18n posture continues.
6. **Unauthenticated public read of `/system/public-records`** — constitutionally desirable, deferred to Phase F federation (same decision point as head-hash publication); page ships auth-gated with the note in the About panel.
7. **Speaker-election re-ballot loop UI** ("no supermajority → re-ballot per rules") — first ballot + result + "re-ballot" Btn ship; automated multi-round scheduling is rules-of-order territory (post-C polish).
8. **`/system/clocks` registry page** (nav phase C) — not in the 16; flip to a later WI or ship as a trivial read-only DataTable over the `clocks` registry if slack exists; nav item stays `Planned` otherwise.