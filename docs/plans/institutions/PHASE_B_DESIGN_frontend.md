# PHASE B — ELECTORAL FRONTEND: 8 SCREENS + COMPONENT WI BREAKDOWN

Sources verified on disk: `mockups/electoral/*.html` (all 8), `resources/css/cga/components.css` (electoral CSS already ported — see A.0), `EXPLORE_civic_electoral.md` §3, `DESIGN_frontend_port.md` §B row 26 + §C/§D conventions, `DESIGN_schema_engine.md` §A.2 (column names referenced below are that schema's), built components in `resources/js/Components/` (Ui/* + Surface/* all exist and are reused; FormCard already injects `form_id` into every Inertia submission).

---

## A) COMPONENT SPECS — `resources/js/Components/Electoral/*`

### A.0 CSS audit (components.css, verified by line)

All row-26 classes are **already ported** — no blocking gaps:

| Class family | Lines | Status |
|---|---|---|
| `.candidate-row .candidate-main .candidate-name .candidate-meta` | 637–646 | present |
| `.standing .standing-approvals .standing-delta--up/--down/--flat` | 647–652 | present |
| `.finalist-line` (+ `::before/::after` dashed gold) | 655–662 | present |
| `.switch` + `[aria-pressed="true"]` + `::before` dot | 665–682 | present |
| `.rank-list .rank-item` (+ `::before` CSS counter) `.rank-controls` | 685–700 | present |
| `.stv-round .stv-cand(--elected/--eliminated) .stv-cand-name .stv-track .stv-fill(--elected/--transfer) .stv-quota-mark .stv-votes` + narrow-screen grid swap + `.stv-cand-name a` ellipsis | 703–723, 825 | present |
| `.receipt` | 726–731 | present |
| Supporting: `.meter-fill--met .meter-threshold` (585–586), `.filter-bar` (612), `.chip-toggle` (625), `.tag-chip` (629), `.toast--achievement` (596), `.proposed-flag` (600), `.planned-flag` (803), `.state-strip` (536), `.stack/.cluster/.grid-2` (288–290) | | present |

**Classes to append** (one small block, `/* electoral additions */` at end of components.css):
1. `.roster-row` — the ranked-ballot finalist roster row (`display:flex; justify-content:space-between; align-items:center; border-block-end:1px solid var(--gov-border); padding-block:var(--space-1)`). The mockup does this with inline styles; the qa_scan zero-inline-physical-props posture wants it named.
2. `.stv-action` — alias of the round-action `<span class="citation">` inside the round `<h3>` (mockup uses inline `margin-block` on the h3; name it so StvRound emits no inline styles).
Nothing else — every other mockup inline style is `gap`/`margin-block` on existing layout primitives, acceptable per the ported pages' precedent (`Residency.vue` does the same).

### A.1 `Electoral/ApproveSwitch.vue` — `.switch`

The revocable approval toggle (open-ballot contract: "revocable any time during approval phase; disabled when phase ≠ approval").

```js
props: {
  pressed:        { type: Boolean, required: true },          // v-model:pressed
  candidateName:  { type: String, required: true },           // a11y name
  disabled:       { type: Boolean, default: false },          // phase ≠ approval OR viewer < R-04
  disabledReason: { type: String, default: 'Approval phase is closed' }, // → title attr (mockup verbatim)
  busy:           { type: Boolean, default: false },          // in-flight POST
  labels:         { type: Object, default: () => ({ off: 'Approve', on: 'Approved' }) },
}
emits: ['update:pressed']   // parent owns the POST/DELETE + optimistic revert
```

Renders `<button type="button" class="switch" :aria-pressed="String(pressed)" :disabled="disabled || busy" :title="disabled ? disabledReason : null" :aria-label="(pressed ? 'Withdraw approval for ' : 'Approve ') + candidateName">{{ pressed ? labels.on : labels.off }}</button>`. Never color-only: the CSS contract changes label text too. State change announced through the shell's `useAnnounce()` polite region by the **parent** after server ack ("Approved — revocable" / "Approval withdrawn"), not by the switch.

### A.2 `Electoral/CandidateRow.vue` — `.candidate-row` family

One standings row (open-ballot), markup byte-derived from `rowHtml()` in open-ballot.html lines 167–190.

```js
props: {
  candidacy:   { type: Object, required: true },
  // { id, name, statement, position_tags: [], incumbent: bool, profile_href,
  //   endorsements: { orgs: [{id,name,type}], individual_count: int } }
  rank:        { type: Number, required: true },   // ALWAYS full-race rank, never filtered rank
  approvals:   { type: Number, required: true },   // aggregate, daily (approval_standings.approvals_count)
  delta:       { type: Number, default: 0 },       // approval_standings.delta (signed)
  approved:    { type: Boolean, default: false },  // viewer's own (owner-only read of approvals table)
  approvable:  { type: Boolean, default: false },  // phase === 'approval' && viewer R-04 in race
  busy:        { type: Boolean, default: false },
}
emits: ['toggle-approve']  // (candidacyId, next)
slots: { meta }            // extra chips (e.g. 'withdrawn' badge)
```

Grid: ApproveSwitch (col 1, omitted entirely — not just disabled — when the viewer lacks R-03 in the race jurisdiction, e.g. browsing another county's race) · `.candidate-main` with `#rank` citation span, `Link.candidate-name` → `profile_href` (`title="{name} — open public profile · rank {rank} · {approvals} approvals"`), incumbent `StatusBadge tone=neutral`, statement small line, `.candidate-meta` = `OrgChip`×orgs + `TagChip` "{n} individual endorsements" + `TagChip` "no endorsements" (first-class, when both empty) + `TagChip`×tags · `.standing` with `.standing-approvals` (tabular-nums) + `.standing-delta--up/--down/--flat` ("▲ n since yesterday" / "▼ n" / "— steady", with `visually-hidden` "since yesterday" preserved for the flat glyph). Sets `data-rank` for tests.

**Deliberate delta from the mockup**: the mockup adds the viewer's own approval (+1) to the displayed aggregate. Production must NOT — a single-voter live delta on a daily aggregate de-anonymizes the approval (secrecy: Art. II §2). Instead the switch flips and the row caption (parent-rendered) reads "your approval is recorded — aggregates update daily."

### A.3 `Electoral/RankList.vue` — `.rank-list / .rank-item / .rank-controls`

Click-to-rank, keyboard operable, **no drag** (mockup About note is a contract: "ranking is click-to-rank with ↑/↓ — keyboard operable, no drag required").

```js
props: {
  modelValue: { type: Array, required: true },
  // [{ candidacy_id, name, write_in: bool }] in rank order
  seats:      { type: Number, required: true },   // guidance copy denominator
  disabled:   { type: Boolean, default: false },  // post-commit lock (aria-disabled on the <ol>)
}
emits: ['update:modelValue']
```

Renders `<ol class="rank-list" aria-label="Your ranked candidates">` → `li.rank-item` per entry: `visually-hidden` "Rank {n}" text (the CSS `::before` counter is not reliably announced), name span (`flex:1`), `TagChip` "write-in" when `write_in`, `.rank-controls` = three `Btn`s sized by the ported `.rank-controls .btn` rule: secondary `Icon arrow-up` `aria-label="Move {name} up"` (disabled at index 0), secondary `arrow-down` (disabled at last), danger `x` `aria-label="Remove {name}"`.

A11y beyond the mockup (the mockup loses focus on re-render — the Vue port must not):
- After move: `nextTick()` re-focus the **same control on the moved item** at its new index; announce "{name} moved to rank {n} of {total}" via `useAnnounce()`.
- After remove: focus the next item's remove button (or the list's add-source if empty); announce "{name} removed — {total} ranked".
- Keyboard bonus (cheap, no new CSS): `Alt+ArrowUp/Down` on a focused `.rank-item` moves it (same handler as the buttons).

Guidance line is parent-rendered (`#rank-guidance` gloss: "Rank for all {seats} seats (or more) so your vote can transfer — {seats−n} more recommended." → "All seats covered — extra ranks only help your vote transfer further.").

### A.4 `Electoral/StvBar.vue` + `Electoral/StvRound.vue` — `.stv-*`

`StvBar` is the unit (also used standalone by RankedBallot live-aggregate, VacancyCountback re-run panel, Results RCV variant); `StvRound` composes bars + the expandable transfer breakdown.

```js
// Electoral/StvBar.vue
props: {
  name:       { type: String, required: true },
  votes:      { type: Number, default: null },     // null → renders '—' (struck countback member)
  quota:      { type: Number, required: true },
  scale:      { type: Number, required: true },    // bar max; convention quota × 1.35 (mockup SCALE)
  elected:    { type: Boolean, default: false },   // → .stv-cand--elected + .stv-fill--elected
  eliminated: { type: Boolean, default: false },   // → .stv-cand--eliminated (line-through)
  transferFill: { type: Boolean, default: false }, // → .stv-fill--transfer (gold; breakdown rows)
  writeIn:    { type: Boolean, default: false },   // appends TagChip 'write-in'
  href:       { type: String, default: null },     // candidate profile link (ellipsis rule .stv-cand-name a)
  badge:      { type: String, default: null },     // e.g. 'r16' elected-round chip (StatusBadge success)
  chips:      { type: Array, default: () => [] },  // countback: 'removed from the count'|'reaches quota'|'no remaining preference'
  quotaTitle: { type: String, default: null },     // 'Droop quota 41,239'
}
```
Markup: `.stv-cand` → `.stv-cand-name` (Link or text + chips) · `.stv-track` (`aria-hidden="true"` — the number is the accessible datum) containing `.stv-fill` at `inline-size: min(100, votes/scale*100)%` + `.stv-quota-mark` at `inset-inline-start: quota/scale*100%` with `title=quotaTitle` and a `visually-hidden` sibling "Droop quota {quota}" once per list (parent-rendered, not per bar) · `.stv-votes` formatted `toLocaleString`.

```js
// Electoral/StvRound.vue
props: {
  round:        { type: Object, required: true },  // one `display[]` entry — exact shape in §C
  quota:        { type: Number, required: true },
  scale:        { type: Number, required: true },
  electedRound: { type: Object, default: () => ({}) },  // name → round (badges + tooltips)
  profileHref:  { type: Function, default: null },       // (candidacy_id, name) => href|null
  defaultOpen:  { type: Boolean, default: false },       // transfer <details> open state
}
```
Renders `<h3>Round {n} <span class="citation stv-action">{action}</span></h3>`, then (if `round.tallies`) `.stv-round` of StvBars (elected = `electedSoFar.includes(id) || votes >= quota`, badge `r{electedRound}` exactly as mockup line 157), then the **transfer breakdown**: `<details class="about-surface">` with summary `Where {from}'s votes went · {totalMoved} votes (surplus, fractional Gregory values | elimination, at current value)` and body = StvBar per `transfer.to` pair with `transferFill`, plus the exhausted row (subtle-color name "→ exhausted (no further preference)", empty track, votes) when `transfer.exhausted > 0`. Tally-less rounds (the collapsed middle) render heading + breakdown only — same component, parent decides placement.

### A.5 `Electoral/BallotReceipt.vue` — `.receipt`

```js
props: {
  hash:        { type: String, required: true },   // 64-hex; rendered in 8-char groups (mockup format)
  copyable:    { type: Boolean, default: true },
  resultsHref: { type: String, default: null },    // 'Self-audit in the public count record →'
  compact:     { type: Boolean, default: false },  // referendum receipt: single citation line, first 17 chars
}
```
Renders `Banner tone=warning` "This receipt is shown **once**. Copy it now — it is never retrievable later, by you or by anyone." → `<p class="receipt" data-no-i18n>{grouped hash}</p>` → `.cluster`: `Btn variant=secondary size=sm icon=copy` "Copy receipt" (`navigator.clipboard.writeText(rawHash)`, announce "Receipt copied", swap label to "Copied ✓" for 2s, textarea-select fallback for non-secure contexts) + optional results link. **Deferred from the mockup**: the `toast--achievement` "First ballot committed" block — gamification is `Proposed` and DESIGN_frontend_port row 28 says do not build until approved.

### A.6 `Ui/FinalistLine.vue` — `.finalist-line` (Ui/, per row 26 placement)

```js
props: {
  count: { type: Number, required: true },   // X
  label: { type: String, default: null },    // default: 'finalist line — top {count} advance to the ranked ballot · CLK-21'
}
```
Renders `<div class="finalist-line" role="separator" aria-label="Finalist line">{label}</div>`. Inserted by the standings list **after full-race rank X** regardless of active filters (ranks always reflect the full race — open-ballot contract).

### A.7 `Electoral/PhaseBanner.vue` — extracted shared pattern (not in row 26; justified)

Four mockups (open-ballot, candidacy-registration, candidate-profile, election-detail) hand-roll the same phase banner. One component:

```js
props: {
  phase:   { type: String, required: true },  // 'approval'|'ranked'|'certifying' (frozen vocabulary)
  context: { type: String, required: true },  // 'open-ballot'|'registration'|'profile'
  isFinalist: { type: Boolean, default: null },   // profile context copy branch
  links:   { type: Object, default: () => ({}) }, // { rankedBallot, results, openBallot } hrefs
}
```
Thin wrapper over `Ui/Banner`: approval → render nothing (registration context renders the info "Registration is open now" banner instead); ranked/certifying → `tone=warning role=status` with the exact mockup copy per context, `CitationLine` "CLK-18/CLK-21" trailing. Keeps the frozen `election: approval|ranked|certifying` vocabulary in one file.

**Reused, not new**: `Ui/StateStrip` (all entity strips), `Ui/ThresholdMeter` (candidate-profile approval meter — `threshold` = finalist-line approvals, `met` = isFinalist), `Ui/LogRow` (public-record entries on profile), `Ui/DataTable` (schedule, races, observers, validation queue, requests), `Ui/FilterBar + ChipToggle + TagChip`, `Surface/FormCard` (every F-ID form), `Ui/AmendableSetting` (60-month interval, 3×seats multiplier), `Ui/HardenedChip`, `Ui/Stat`, `Ui/OrgChip`, `Ui/Banner`, `Ui/StatusBadge`, `Ui/CitationLine`.

---

## B) PAGE SPECS — `resources/js/Pages/Elections/*`

Shared conventions: every page is `PageScaffold :surface` (AppShell default layout, `main` standard width); state machines are PHP-owned (`config/cga/state_machines.php` entries `election`, `candidacy`, `approval_standing`, `ballot_ranked`, `vacancy`) and arrive as `machine: string[]` + `status` props — pages never hardcode state lists; all datetimes arrive as ISO-8601 UTC + the page renders user-tz with the "stored as UTC" citation; controllers live in `app/Http/Controllers/Elections/`; **phase derivation is server-side**: `elections.status ∈ {approval_open, finalist_cutoff} → 'approval'`(cutoff transition is instant), `ranked_open → 'ranked'`, `{voting_closed, tabulating, certified, recount, final} → 'certifying'` — exposed as `election.phase` so the frozen scenario vocabulary maps 1:1; surface entries below are appended to `config/cga/surfaces.php` (form names/aliases resolve via the existing `FormRegistry`).

**Race resolution rule** (needed because an election has 1..274 races): controllers resolve the viewer's race via `jurisdiction_associations` ∩ `legislature_district_jurisdictions` of the race's district; `?race={uuid}` overrides for browsing. Single-race elections (seats ≤ 9, no district map — the at-large case the schema section decided per Art. II §8) skip the picker entirely.

### B.1 `ElectionDetail.vue` — `GET /elections/{election}` → `ElectionController@show`

Surface `elections/detail`: module `electoral`, nav `elections`, roles `[R-03,R-04,R-08]`, workflows `[WF-ELE-01]`, forms `[F-ELB-001(R-08), F-ELB-004(R-08), F-ELB-006(R-08)]`, clocks `[CLK-01,CLK-18,CLK-21,CLK-07]`, citation `'General election cycle · two-phase open ballot · Art. II §2 · CLK-18 · CLK-21'`.

Props:
```js
{
  surface,
  election: { id, kind, status, phase, certSubStep,   // certSubStep: 'tabulating'|'certified'|'recount'|null
    jurisdiction: { id, name, adm_level },
    schedule: [ { stage, at|range, key, status: 'done'|'current'|'upcoming' } ],   // 5 rows, server-computed
    interval: { value: 60, unit: 'months', settingKey: 'election_interval_months', citation },
    finalistMultiplier: { value: 3, settingKey: 'finalist_multiplier', clock: 'CLK-21' },
    schedulingOrder: { issued_at, board_name } | null },
  machine: string[], stats: { seats, finalistPlaces, validatedCandidates, stage },
  races: [ { id, label, seats, finalist_count, candidate_count, district_id|null, at_large: bool } ],
  blockers: [ { kind: 'subdivision_required', detail } ] | [],
  others: [ { election_id, jurisdiction_name, kind, seats, finalist_count, phase } ],
  can: { certify: bool, recount: bool },               // policy: R-08 of this election's board
  certification: { certified_at, by } | null,
}
```
Composes: StateStrip (Election machine, current node from status) + `phase-badge` StatusBadge · Stat row · FormCard-less inset card for the F-ELB-001 order record (read-only `FormChip`) · DataTable schedule with done/current/upcoming badges · AmendableSetting ×2 · DataTable races ("Races & pre-published X" — X published **before** cutoff, gloss verbatim) · race boundary: Phase B renders the **real Leaflet boundary** (jurisdiction geom endpoint already exists) replacing the stylized SVG — single district vs link to district mapper for subdivided chambers · others list · phase CTAs (`state-actions` cluster): approval → Btn→OpenBallot + Btn→CandidacyRegistration; ranked → gold Btn→RankedBallot; certifying → Btn→Results + (R-08 only) "Certify results — F-ELB-004" `router.post('/elections/{id}/certify')` and "Order recount — F-ELB-006" (disabled until certified, confirm dialog requires `cause` text — engine rejects empty).

Empty/edge states: **no election scheduled** for the viewer's jurisdiction → `GET /elections` (jurisdiction-scoped resolver) renders the same page in empty mode: Card "No election scheduled. Elections fire from clocks (CLK-01 · every {interval} months), never from official discretion." + the armed CLK-01 clock's `due_at` if one exists + HardenedChip. **Subdivision blocker** (Montegiardino 10 seats > 9, San Marino 32-seat type_a, both with no district map): `blockers[]` non-empty → Banner tone=warning "This chamber has {seats} seats — above the 9-seat ceiling. A district map (5–9 seats each) must be activated before this election can open its approval phase · Art. II §8 · CLK-07 · F-ELB-003" with link to the Legislature browser build mode; schedule table renders with every row `upcoming`. Emergency banner slot reserved (Phase C wiring), exactly like Civic/Home.

Role gating: page is public to authenticated R-01+ (election records are public); certify/recount buttons render only with `can.*`; POST routes guarded by `ElectionBoardPolicy` + engine.

### B.2 `CandidacyRegistration.vue` — `GET /elections/{election}/candidacy` → `CandidacyController@create`; `POST /elections/{election}/candidacy` (F-IND-011)

Surface `elections/candidacy-registration`: nav `candidacy`, roles `[R-03,R-06]`, workflows `[WF-CIV-05]`, forms `[F-IND-011(R-03, 'Art. I (Right to Stand for Office)'), F-ELB-002(R-08)]`, clocks `[CLK-18]`, citation `'Right to stand — residency is the only requirement · Art. I · CLK-18'`.

Props:
```js
{
  surface,
  phase, registrationOpen: bool,                      // CLK-18: open ⇔ phase === 'approval'
  offices: [ { election_id, race_id|null, label, seats } ],  // ONLY races whose jurisdiction ∈ viewer's active associations
  tagVocabulary: string[],                            // fixed vocabulary (config), chip-toggles
  machine: string[],                                  // candidacy machine
  myCandidacy: { id, status, office_label, validated_at, rejection_reason } | null,
}
```
Composes: header HardenedChip + two CitationLines (registration window CLK-18) · PhaseBanner context=registration · grid-2: **FormCard form=F-IND-011** (Field select office with hint "Only jurisdictions you are associated with are listed…", Field textarea platform statement *optional*, ChipToggle group position tags *optional*, CheckboxField attestation **required** "I attest that I reside in the selected jurisdiction. Nothing else is asked of me.") + "What happens next" Card (StateStrip candidacy machine, the 3-step `<ol>` with F-ELB-002/CLK-21 citations).

Result card (replaces the mockup's toggle — production has one truth): after submit, `myCandidacy` drives it. `status='registered'` → Banner info "Submitted — awaiting board validation (F-ELB-002; residency is the only check)". `status='validated'|'in_pool'` → Banner info "Validated — you are in the approval pool" + StatusBadge "In approval pool · R-06" + Btns → OpenBallot / CandidateProfile (F-CAN-001). `status='rejected'` → Banner emergency "Registration rejected — no residency association found…" with the **only-permissible-ground** copy verbatim + appeal-path paragraph (court link renders as `planned-flag` "Planned · Phase E" — judiciary not built).

Edge states: `registrationOpen=false` → form's submit Btn disabled, warning PhaseBanner ("closes at finalist cutoff · reopens at certification"); engine independently rejects (422 with citation) — UI disabling is UX, never the boundary. Already registered in this election → form replaced by the result card (unique `(election_id, user_id)`). Viewer is R-01/R-02 (no associations) → form replaced by Card "Establish residency to stand for office — voting and candidacy unlock together at verification" + link to `/civic/residency`.

Role gating: route `auth`; R-03 enforced by engine on POST (the page renders the explanation, not a 403).

### B.3 `CandidateProfile.vue` — `GET /candidates/{candidacy}` → `CandidateProfileController@show`; `PATCH /candidates/{candidacy}` (F-CAN-001); `POST /candidates/{candidacy}/withdraw` (F-CAN-003); `POST /candidates/{candidacy}/endorsement-requests` (F-CAN-002)

Surface `elections/candidate-profile`: nav `open-ballot`, roles `[R-03,R-04,R-06,R-07]`, workflows `[WF-CIV-05, WF-CIV-08]`, forms `[F-CAN-001(R-06), F-CAN-002(R-06), F-CAN-003(R-06)]`, clocks `[CLK-21]`.

Props:
```js
{
  surface,
  candidacy: { id, name, statement, position_tags, status, withdrawn: bool, incumbent: bool,
               race: { id, election_id, label, seats, finalist_count, phase } },
  standing: { rank, of, approvals, isFinalist, lineApprovals, topApprovals, asOf } | null,
       // null once phase !== 'approval' AND standings were never frozen for this race (generic case) —
       //   then the page renders the 'see the count' card (mockup #standing-generic) instead
  machine: string[], currentState: string,            // server-computed from phase+endorsements+withdrawal
  endorsements: { orgs: [{id,name,type,granted_at}], individual: { total, public: int, private: int },
                  publicWeb: [ { name, user_id, alsoCandidate: bool, endorses: [{candidacy_id, name}] } ] },
  requests: [ { org_name, requested_at, status: 'granted'|'pending'|'declined' } ],
  publicRecord: { votes: [], actions: [{date,label}], statements: [] },   // Phase B: actions only (registration,
       // residency, participation rows from public_records); votes/statements arrive with Phase C pipeline
  isOwner: bool,                                       // manage card + requests table visibility
  can: { withdraw: bool },                             // owner && phase === 'approval' (ballot lock at cutoff)
  organizations: [ {id, name} ],                       // request-form select (minimal org handshake)
}
```
Composes: PhaseBanner context=profile (`isFinalist` branch copy) · Approval standing Card: Stat ×3 (rank `#r of N`, approvals "aggregate · updated daily", finalist places) + **ThresholdMeter** (`value=approvals, max=topApprovals, threshold=lineApprovals, met=isFinalist`, caption "rank #r — inside the top X (finalist track)" / "below the finalist line · write-in eligible", trailing caption "gold tick = finalist line · top X of N · CLK-21") + StateStrip (terminal node label "Elected | Not elected | Withdrawn") · grid-2: Endorsements Card (OrgChips, individual public/private split TagChip, "no organizational endorsements — first-class" TagChip, the expandable **public web** as `details.about-surface` per public endorser listing their other public endorsements as profile Links — straight port; the **SVG endorsement graph is deferred**, see E-defers) + Endorsement requests Card (DataTable org/requested/status with "granted · grants R-07" badge; owner-only F-CAN-002 FormCard inline: select org → POST) · Public record Card (LogRow rows; non-incumbent votes section renders the honest gloss "None — not an incumbent…"; empty record renders "No public record entries yet…" — this **is** the mockup's "generic" branch, which collapses in production since every candidate is a DB row) · Manage Card (`isOwner` only): FormCard F-CAN-001 (statement textarea; save appends a public-record entry) + withdraw section: F-CAN-003 with inline confirm step ("This cannot be undone"), **disabled with citation "ballot locked at the finalist cutoff — withdrawal closed · CLK-21"** when `!can.withdraw`, withdrawn → danger badge "Withdrawn — recorded on the public record".

Role gating: public read; PATCH/withdraw/request guarded by owner policy + engine (F-CAN-003 blocked after `finalist_cutoff_at` server-side).

### B.4 `OpenBallot.vue` — `GET /elections/{election}/open-ballot[?race=]` → `OpenBallotController@show`; `POST /elections/{election}/approvals {candidacy_id}` / `DELETE /elections/{election}/approvals/{candidacy_id}` → `ApprovalController`

Surface `elections/open-ballot`: nav `open-ballot`, roles `[R-03,R-04,R-06]`, workflows `[WF-CIV-08, WF-ELE-01, WF-CIV-05]`, forms `[]` (**no forms — approval engine**; the store/destroy endpoints still route through `ConstitutionalEngine` as engine actions, audit-chained, just without a citizen-facing F-ID — matching the mockup's empty `forms` contract), clocks `[CLK-18, CLK-21]`.

Props:
```js
{
  surface,
  race: { id, election_id, label, seats, finalist_count, phase, asOf },   // asOf = standings date
  stats: { seats, finalistPlaces, validatedCandidates, myActiveApprovals },
  standings: [ { candidacy: {...CandidateRow shape}, rank, approvals, delta } ],  // server-sorted by
       // approval_standings (daily aggregate); frozen snapshot rows when phase !== 'approval'
  myApprovals: string[],          // candidacy_ids — owner-only read of `approvals`
  filters: { orgs: [{id,name,type}], tags: string[] },   // filter-bar option sources
  approvable: bool,               // phase==='approval' && viewer R-04 in race jurisdiction
}
```
Composes: PhaseBanner context=open-ballot · Stat row (4th stat "your active approvals (revocable)") · secrecy Banner info ("Your approvals are secret; standings are aggregate… updated on a daily cycle") · FilterBar (endorser select incl. `__individuals`/`__none`, tag ChipToggles, incumbents ChipToggle, search Field, clear Btn — **all client-side** over the delivered standings; ranks always full-race; "{n} hidden by filters — ranks reflect the full race" caption) · the standings Card (`padding:0` list variant): CandidateRow per entry with **FinalistLine inserted after full-race rank X** · `aria-live="polite"` on the list region, caption flips "aggregate · updated daily" ↔ "frozen at the finalist cutoff" · grid-2 footer: Alignment questionnaire Card (static, `planned-flag` "Future scope", disabled Btn — kept verbatim, it documents the never-auto-approves rule) + Stand-for-office Card (CTA → CandidacyRegistration).

Standings visibility decision (per contract): standings are **always visible** — live during approval, frozen-at-cutoff during ranked/certifying. Individual approvals never visible to anyone but the owner.

Edge states: zero validated candidates → "No validated candidates yet — any associated resident can stand" + register CTA; viewer not R-04 in this race → switches hidden, info caption "You can browse this race; approving requires jurisdictional association here · Art. I"; >200 candidates (Earth-district scale) → server caps initial payload at 100 rows + "Show all {n}" partial reload (`only:['standings']`, `?full=1`) — FinalistLine still positions by full-race rank because rank comes from the server rows.

### B.5 `RankedBallot.vue` — `GET /elections/{election}/ranked-ballot[?race=]` → `RankedBallotController@show`; `POST /elections/{election}/races/{race}/ballots` (F-IND-007); `POST .../referendum-ballots` (F-IND-008); `POST /receipt-check` (public)

Surface `elections/ranked-ballot`: nav `ranked-ballot`, roles `[R-04]`, workflows `[WF-CIV-04, WF-ELE-01]`, forms `[F-IND-007(R-04,'Art. II §2'), F-IND-008(R-04,'Art. II §6')]`, citation `'F-IND-007 ballot submission · STV with Droop quota · Art. II §2'`.

Props:
```js
{
  surface,
  race: { id, label, seats, finalist_count, phase, ranked_closes_at },
  finalists: [ { candidacy_id, name, profile_href } ],            // top X, frozen order
  writeInsAvailable: int,                                          // count only — list is search-driven
  alreadyVoted: { committed_at } | null,                           // ballot_envelopes check
  referendum: { question_id, title, text, threshold, provenance } | null,
  referendumVoted: bool,
  liveAggregate: { ballotsSoFar, quotaIfClosedNow, top: [[name, votes]], remainderNote } | null,
       // first preferences so far; null until backend WI lands (render nothing, not a fake)
  machine: string[],   // ballot machine: Issued → Marked → Committed → Counted → Anonymized-Published
}
```
Composes (full flow in §D): grid-2 `#ballot-area`: Finalists Card (`.roster-row` per finalist: profile Link + "ranked #n" citation + Add Btn (disabled when ranked); write-in block: search Field hitting `GET /elections/{e}/races/{r}/candidacies?validated=1&unranked=1&q=` (search-driven — Earth races can have thousands of validated non-finalists; never enumerate) + Add write-in Btn + the right-to-stand citations) + Your-ranking Card (**RankList**, rank-count citation, guidance gloss, Review Btn (disabled at 0) + Clear ghost Btn) · Review & commit Card (hidden until review: plain `<ol>` of names, the cryptographic-separation copy + citation, gold "Commit ballot" Btn + "Keep editing" ghost) · Ballot-committed Card (**BallotReceipt**, results link) · Live aggregate Card (StvBar list with `quotaIfClosedNow` mark, "Projection only…" citation — standings stay visible through the window by contract) · Referendum Card (RadioGroup yes/no, separate commit Btn → own compact BallotReceipt; provenance citation "Delegated by supermajority act · F-LEG-023 · passes at the threshold matching the act type" — referendum **content** arrives in Phase C; the card renders only when `referendum` non-null, so Phase B ships the slot wired but normally empty).

Role gating: route `auth`; controller 302s non-R-04 viewers to OpenBallot with flash explaining rights chain. Phase ≠ 'ranked' → page renders window-closed state: warning Banner + link to Results/OpenBallot, no ballot area.

### B.6 `Results.vue` — `GET /elections/{election}/results[?race=]` → `ResultsController@show`; `GET .../results.csv`

Surface `elections/results`: nav `results`, roles `[R-03,R-04,R-08]`, workflows `[WF-ELE-01, WF-ELE-05]`, forms `[F-ELB-004(R-08), F-ELB-006(R-08)]`, citation `'STV with Droop quota · Gregory transfers · Art. II §2'`.

Composes: Stat row (valid ballots / **Droop quota with formula label** / seats "all filled in one count" / counting rounds) · Elected Card (`persona-chip` + Avatar initials + profile Link + success badge "elected · round n" + "seat n") · The count Card (§C contract: opening rounds → collapsed middle `details.about-surface` "Rounds a–b — expand any round for its vote transfers" → final round; write-in footnote with TagChip; CSV download Btn → real streaming endpoint) · Certification & chain-of-custody Card (observers DataTable from endorsing orgs + candidates — **no faction layer**, attestation StatusBadges; FormChips F-ELB-004/F-ELB-006; "recount = audit re-run, no hand count" gloss; phase badge) · RCV single-winner variant Card renders **only when** `race.seat_kind === 'single'` (Phase D executive races; StvBar already supports it via majority-as-quota mark) — Phase B legislative races omit the section rather than showing fixture data · About panel.

Edge states: `tabulation.status='running'` → "Tabulating — instant count in progress" Banner + auto partial-reload poll (`only:['tabulation']`, 5s); no tabulation yet (phase approval/ranked) → redirect to ElectionDetail; `recount` → both tabulations listed, `kind: audit_rerun` badged, outcome reaffirmed/corrected.

### B.7 `BoardConsole.vue` — `GET /board` → `BoardConsoleController@show` (route-level `can:access-board` → R-08 seat or bootstrap-system flag)

Surface `elections/board-console`: nav `election-board-console` (sidebar item role-gated `enabledRoles:['R-08']`), roles `[R-08]`, workflows `[WF-ELE-01…10]`, forms all six `F-ELB-001…006 (R-08)` with the mockup's prereq citations.

Props:
```js
{
  surface,
  board: { id, jurisdiction_name, is_bootstrap, status, members: [{name}] },
  stats: { electionsAdministered, validationsPending, countbacksRunning, petitionAuditsDue },
  schedulable: [ { election_id, label, finalist_cutoff_at, ranked_opens_at, ranked_closes_at,
                   races: [{label, finalist_count}] } ],
  validationQueue: [ { candidacy_id, name, office, residency: { found: bool, slug|null, duplicate: bool } } ],
  districtOversight: [ { map_id, name, district_count, seat_string, status } ],   // from legislature_district_maps
  certifiable: [ { election_id, label, rounds, seats, tabulation_complete: bool, certified: bool,
                   recount: { ordered: bool } } ],
  petitionAudits: [],            // Phase C — renders 'No petitions at threshold' empty state in B
  vacancies: [ { vacancy_id, label, status } ],
}
```
Panels (each a Card, mapped to the mockup contract table): **Scheduling** FormCard F-ELB-001 (election select, finalist-cutoff datetime-local, ranked open/close; hint "X per race is pre-published with this order · CLK-21"; "stored as UTC" hint; engine validates window ordering + CLK-04 for specials) · **Validation queue** DataTable (residency badge success "found · {slug}" / danger "not found in jurisdiction" / success+flag "found · duplicate registration flag"; Validate/Reject Btns → `POST /board/validations/{candidacy} {decision}` (F-ELB-002); decided rows show "validated · in approval pool" / "rejected · appeal path open" + court link as `planned-flag` Phase E) · **District-map oversight** DataTable from real `legislature_district_maps` (draft → "draft · published for observation" badge; link to Legislature browser; prereq citation seats > 9) · **Certification** (review-count link → Results, Certify Btn → `POST /elections/{e}/certify` F-ELB-004 "winners granted roles"; Recount Btn disabled until certified, `title="Requires certification first"`, confirm requires cause → F-ELB-006) · **Signature audit** empty-state in B (F-ELB-005 panel chrome ships; petitions are Phase C) · **Vacancies** list → VacancyCountback links · **Bootstrap variant**: `board.is_bootstrap` (real flag, not a toggle) → persistent warning Banner "Bootstrap election board — temporary · replacement queued… WF-ELE-02 · WF-ELE-10" pinned above header; the mockup's toggle button is dev-bar-only (impersonation scenario), not product UI. Emergency banner slot reserved.

### B.8 `VacancyCountback.vue` — `GET /vacancies/{vacancy}` → `VacancyController@show`; `POST /vacancies/{vacancy}/certify` (F-ELB-004); `POST /vacancies/{vacancy}/special-election` (F-ELB-001)

Surface `elections/vacancy-countback`: nav `vacancy-countback`, roles `[R-08]`, workflows `[WF-ELE-03, WF-ELE-04]`, forms `[F-LEG-036(R-09/R-10, alias 'F-LEG-030'), F-ELB-004(R-08), F-ELB-001(R-08)]`, clocks `[CLK-04]`.

Props:
```js
{
  surface,
  vacancy: { id, office_label, seat_no, member_name, declared_at, declared_by, status,
             window: { opens_on, closes_on } },        // declared_at + 90/180d, server-computed
  machine: string[],
  rerun: { source: { election_label, total_valid, seats, quota, quota_formula },
           outcome: 'running'|'winner'|'exhausted',
           winner: { candidacy_id, name } | null,
           bars: [ { name, votes|null, removed, elected, exhausted } ] },   // final-state countback tallies
  certification: { certified_at, winner_name } | null,
  specialElection: { id, scheduled_for } | null,
  can: { certify, schedule },
}
```
Composes: Trigger Card (F-LEG-036 inset form-record with alias citation "catalog alias: F-LEG-030 · renumbering drift", declared-by line, **StateStrip** vacancy machine) · Re-run Card (outcome StatusBadge; **StvBar** list — struck member `eliminated` + chip "removed from the count" + votes "—", winner `elected` + "reaches quota", exhausted row `transferFill` + "no remaining preference"; quota gloss + "universal — no faction filtering" citation) · grid-2 branch cards with active/not-taken badges and `opacity` dimming driven by `rerun.outcome` (not a scenario toggle): **winner found** → Certify Btn (F-ELB-004) → certified badge "…seated via oath F-LEG-001" + "proportionality re-check queued (WF-LEG-13)" citation (`planned-flag` Phase C); **ballots exhausted** → FormCard F-ELB-001 date Field with `min/max` = window bounds and hint "The engine rejects dates outside the window" — client min/max is UX; the **engine** 422s out-of-window dates with CLK-04 citation, and the page surfaces that as the Field error (this is the page's signature moment: deliberately submit out-of-window in the HTTP walkthrough) · Knock-on effects Card (committee re-check pending — Phase C citation).

Edge: `status='countback_running'` → branch cards both neutral + poll partial-reload; F-LEG-036 itself arrives in Phase C — Phase B vacancies are dev-seeded (`php artisan vacancy:declare {member}` dev command), stated on the page only in dev bar.

---

## C) RESULTS ROUND-BY-ROUND CONTRACT (the exact JSON `ResultsController` emits)

Server-side `App\Http\Presenters\StvRoundPresenter` (or controller-private) builds this from `tabulations` + `tabulation_rounds` + `race_results`, matching the mockup `window.STV_DATA` shape with ids added (the mockup keys by display name; production keys by `candidacy_id` and carries `name` denormalized so StvRound never needs a lookup table):

```jsonc
{
  "tabulation": { "id": "…", "kind": "initial|audit_rerun|countback", "engine_version": "stv-1.0.0",
                  "status": "complete", "completed_at": "…", "record_hash": "…64hex" },
  "total": 412383,            // tabulations.total_valid
  "quota": 41239,             // tabulations.quota  (floor(total/(seats+1))+1 — display the formula label client-side)
  "seats": 9,
  "rounds": 27,               // max(round_no)
  "scale": 55673,             // quota × 1.35 — server-fixed so all bars share one axis
  "elected": [                // from race_results, seat order
    { "candidacy_id": "…", "name": "Rita Alvarez", "round": 16, "seat_no": 1, "write_in": false }
  ],
  "display": [                // one entry per round, ascending n
    {
      "n": 1,
      "action": "Tanya Brooks eliminated — 5,224 votes transfer at current value",
        // server-rendered string, EXACT mockup grammar:
        //   elimination: "{name} eliminated — {votes} votes transfer at current value"
        //   surplus:     "{name} elected — surplus {votes} transfers at value {0.011} (Gregory)"
      "transfer": {
        "from": { "candidacy_id": "…", "name": "Tanya Brooks", "write_in": false },
        "kind": "elimination",          // 'elimination' | 'surplus'
        "value": null,                   // surplus only: Gregory fractional transfer value, 3dp
        "to": [ [ { "candidacy_id": "…", "name": "Felipe Ortiz", "write_in": false }, 1650.0 ], … ],
        "exhausted": 0                   // votes with no further preference this round
      },
      "tallies": [ [ { "candidacy_id": "…", "name": "Rita Alvarez", "write_in": false }, 28454.0 ], … ],
        // PRESENT only on "key rounds" (see collapse rule); omitted (not null) otherwise
      "electedSoFar": [ "candidacy_id", … ]   // present iff tallies present
    }
  ]
}
```

Rules:
- **Vote numbers** are JSON numbers with ≤ 3 decimal places (Gregory fractions); StvBar formats (`Math.round` + `toLocaleString` for display, exact value in `title`), CSV carries full precision. `tabulation_rounds.tallies`/`transfer` jsonb store candidacy_ids + exact values; names join in the presenter.
- **Key-round selection (the collapse contract, generalizing the mockup's hand-picked tallies)**: a round carries `tallies` iff `n ≤ 3` (opening field), `action = elect` (every election round), or `n = rounds` (final). Everything else is a "mid round" — heading + transfer only. The page renders: key rounds 1..k−1 inline → one `details.about-surface` containing all mid rounds (`summary` "Rounds {a}–{b} — expand any round for its vote transfers") → final key round inline. This exactly reproduces the 27-round mockup (`fullRounds`/`midRounds` split on `tallies` presence).
- **Pagination at scale**: payload budget ≈ key rounds + mid-round transfer stubs. For ≤ 60 rounds (every 5–9-seat race; Queens = 27) emit everything in the page props. Above 60 (defensive; a 9-seat race with write-ins can exceed it), `display` ships key rounds only plus `midRoundsTruncated: {from, to, count}`, and the mid block lazy-loads via Inertia partial reload `router.reload({ only: ['midRounds'], data: { from, to } })` on first `<details>` toggle — same component path, no separate API shape.
- **Countback reuse**: VacancyCountback's `rerun.bars` is the *final tallies of a `kind='countback'` tabulation* — same presenter, `display` omitted, plus `removed` flag on the struck candidacy. Recount (`audit_rerun`) emits the identical shape; Results renders both tabulations when `election_audits` rows exist, badged.
- **CSV**: `GET /elections/{e}/races/{r}/results.csv` streams `round_no, action, candidacy, votes, transfer_from, transfer_value, transfer_votes, exhausted` rows straight off `tabulation_rounds` — no presenter, full precision, filename `{race}-count-record.csv`.

---

## D) BALLOT UX INTEGRITY (client-side commitment flow)

**OpenBallot — approve/revoke.** Standings always visible (live during approval; frozen caption otherwise — contract-verified). Toggle flow: click → optimistic flip of `pressed` + `myActiveApprovals` stat → `POST /elections/{e}/approvals {candidacy_id}` (or `DELETE` on revoke) with the row `busy`; success → `useAnnounce('Approved — revocable until the finalist cutoff')`; failure (phase closed mid-session, network) → revert + Banner with the engine's citation. **Public aggregates never change on the viewer's action** (daily cycle; secrecy — see A.2 delta note); the viewer's own state lives only in the switch + "your active approvals" stat. Revocation is symmetric and unceremonious — no confirm dialog (revocability is the constitutional point). Phase ≠ approval: switches render disabled with `title="Approval phase is closed"`; the engine independently 422s — UI state is never the enforcement.

**RankedBallot — rank → review → commit → receipt.**
1. *Rank*: RankList v-model is page-local state only — **nothing persists server-side before commit** (no draft endpoint; a draft table would be a voter-intent record adjacent to identity). Closing the tab loses the draft; acceptable and stated in the guidance gloss.
2. *Review*: Review Btn reveals the review Card (plain ordered list re-stating the ranking — a distinct visual register from the editable list, per mockup) and scrolls to it; "Keep editing" returns.
3. *Commit*: gold Btn → `router.post('/elections/{e}/races/{r}/ballots', { rankings: [candidacy_id…] }, { preserveState: true })`. Server (engine, F-IND-007, one transaction): assert phase ranked + R-04 + no existing envelope → insert `ballot_envelopes` row → insert anonymous `ballots` row (encrypt payload, compute `ballot_hash`, hour-truncated `cast_bucket`) → flash `receipt_hash` (session flash = **single-pull by construction**; the redirect-back response is the only place the hash ever crosses the wire tied to this session; refresh after that and it is gone — `ballots` has no user link, `ballot_envelopes` has no hash, no GET endpoint returns it).
4. *Receipt*: page sees `flash.receipt_hash` → swaps `#ballot-area` for the committed state: BallotReceipt (grouped hash, copy Btn, "shown once" warning Banner, results link) + RankList/finalist Cards replaced by a read-only "Ballot committed {time}" Card (do **not** leave the marked ranking on screen under the receipt — shoulder-surf window; the mockup merely `aria-disabled`s it, the port removes it).
5. *Referendum*: independent flow — own envelope `kind='referendum'`, own commit Btn, own compact receipt (F-IND-008). Committing one never touches the other.

**Double-vote prevention UX.** Page-load check is the envelope (`alreadyVoted` prop): the ballot area renders the already-voted Card — StatusBadge "Ballot committed · {committed_at}" + copy: "Your ballot is in the count. Your receipt hash was shown once at commit; it cannot be re-issued." + **receipt-verify input**: Field "Paste a receipt hash" → `POST /receipt-check {hash}` (public, unauthenticated-OK — the lookup is anonymized by design: anyone may check any hash against `ballots.ballot_hash`) → result line "Found — committed {cast_bucket}, counted: yes/no" or "Not found — check for typos; hashes are 64 characters". Race condition (two tabs): the second commit 422s on the envelope unique constraint → page reloads into the already-voted state with the engine's message; no double receipt ever issued.

**Window-close mid-session**: commit after `ranked_closes_at` → engine 422 "ranked window closed · Art. II §2" → page swaps to the closed state with the error Banner; client also disables the commit Btn when `ranked_closes_at` passes on a 30s timer (UX only).

---

## E) WORK-ITEM BREAKDOWN

Backend dependencies named per the Phase B schema/engine WIs (B-WI names descriptive — align to that section's numbering at merge): **B-SCHEMA** (A.2 migrations), **B-OPENBALLOT** (approvals + daily standings job), **B-BALLOTS** (envelopes/ballots commitment scheme), **B-STV** (VoteCountingService, PROTECTED), **B-COUNTBACK**, **B-BOARD** (F-ELB endpoints + bootstrap board), **B-CERT** (certification auto-seating), **B-CLOCKS** (CLK-01/04/18/21 arming).

Sizes: S ≈ ½ day, M ≈ 1–2 days, L ≈ 3+ days. Verification posture per established Phase A practice: `docker compose exec app php artisan test --filter=…` + curl HTTP-walkthrough with the `X-Inertia: true` header asserting props JSON + a logged-in browser pass via dev impersonation.

| WI | Item | Size | Depends on | Verification |
|---|---|---|---|---|
| **FE-B0** | Surface registry entries (8), nav items (`elections`, `open-ballot`, `candidacy`, `ranked-ballot`, `results`, `vacancy-countback`, `election-board-console` — board item `enabledRoles:['R-08']`), state-machine config entries (election, candidacy, approval_standing, ballot_ranked, vacancy), `.roster-row`/`.stv-action` CSS append | S | nothing | existing SurfaceMeta registry test extends to the 8 ids; qa_scan over the CSS append |
| **FE-B1** | Component library: StvBar, StvRound, BallotReceipt, RankList, ApproveSwitch, CandidateRow, FinalistLine, PhaseBanner — **built against fixtures first**: copy `STV_DATA` (results.html lines 125) + the candidates array shape from `mockups/assets/js/fixtures.js` into `resources/js/fixtures/electoral.json` (dev-only) + a dev-route harness page (`/dev/electoral-kit`, debug-env only) rendering every component in every state | L | FE-B0 only — **zero backend** | Vitest: RankList move/remove/focus-retention + aria-labels; StvRound renders 27-round STV_DATA byte-comparable to mockup DOM (class assertions); ApproveSwitch aria-pressed/disabled-title; BallotReceipt clipboard + grouping. Browser pass on the harness page incl. keyboard-only ranking |
| **FE-B2** | ElectionDetail + ElectionController + `/elections` empty resolver + subdivision blocker | M | B-SCHEMA, B-CLOCKS (schedule rows), FE-B1 | curl `/elections/{id}` (X-Inertia) → assert schedule/phase/races props; walkthrough: Montegiardino election shows the Art. II §8 blocker; jurisdiction without election shows the CLK-01 empty state; certify Btn absent for non-R-08 |
| **FE-B3** | CandidacyRegistration + CandidateProfile + controllers + F-IND-011/F-CAN-001/002/003 POST routes | L | B-SCHEMA (candidacies, endorsements handshake), FE-B1 | HTTP walkthrough: register (R-03 user) → 'registered' result card; non-associated office absent from select; engine 422 on direct POST for foreign jurisdiction (citation asserted); withdraw blocked after seeded cutoff (422 + disabled-with-citation UI); F-CAN-002 → F-ORG-002 (tinker) → profile shows R-07 badge |
| **FE-B4** | OpenBallot + ApprovalController + filters | M | B-OPENBALLOT, FE-B1 | curl standings props (rank/delta/finalist_count); browser: approve → switch flips, aggregate does NOT move, stat does; revoke; dev-advance phase → switches disabled + frozen caption + FinalistLine position unchanged; filter hides rows but ranks hold |
| **FE-B5** | RankedBallot + commit flow + receipt + double-vote + receipt-check endpoint | L | B-BALLOTS, FE-B4 (finalists exist) | the signature walkthrough: rank 5 → review → commit → copy receipt → reload → already-voted state → paste receipt → "found"; second-tab commit → 422 envelope; out-of-window commit (dev clock) → 422 citation; `psql`: assert ballots row has no user column populated and envelope no hash |
| **FE-B6** | Results + StvRoundPresenter + CSV stream + tabulating poll state | M | B-STV, FE-B1 | unit-test presenter against a golden tabulation fixture (round-count, key-round selection, action-string grammar vs STV_DATA wording); curl results props for a counted dev race; CSV row-count = Σ transfers; collapse: 27-round race shows rounds 1–3 + elects + final inline |
| **FE-B7** | BoardConsole + Board controllers (scheduling, validation, certification, recount) + bootstrap banner | L | B-BOARD, B-CERT, FE-B2 | walkthrough as impersonated R-08: issue scheduling order (window-order 422 case), validate + reject queue rows, certify Queens-equivalent dev race → legislature browser shows seated members (Phase B exit criterion), recount disabled→enabled, cause-required 422; non-R-08 curl → 403 |
| **FE-B8** | VacancyCountback + VacancyController + `vacancy:declare` dev command + special-election scheduling | M | B-COUNTBACK, B-BOARD, FE-B1/FE-B6 | walkthrough both branches: seed vacancy → countback winner → certify → seat appears; seed exhausted variant → schedule at window-edge dates (opens_on−1 → 422 CLK-04 citation surfaces as Field error; opens_on → 200) |

Critical path: FE-B0 → FE-B1 (pure-frontend, starts day 1 against fixtures) ∥ backend WIs; then FE-B2 → FE-B4 → FE-B5 (the voter spine), FE-B6/FE-B7/FE-B8 parallel once B-STV/B-BOARD land. FE-B1's fixture harness means **every component is pixel/a11y-verified before any backend exists**, and the per-page WIs are wiring, not invention.

**Deferred (with justification):**
1. **Endorsement graph SVG** (candidate-profile) — mockup self-labels "stylized stand-in — production renders from the polymorphic endorsements table"; meaningful rendering needs the full org module (Phase D). Phase B ships chips + public-web list, which carry all the information. 
2. **Achievement toast on commit** — `Proposed` gamification layer; frontend-port row 28 forbids building until approved. 
3. **Alignment questionnaire** — `Future scope` in the mockup; ships as the static disabled card only (documents never-auto-approves). 
4. **RCV single-winner Results section** — component support (StvBar) lands now; the section renders only for `seat_kind='single'` races, which first exist in Phase D (WF-ELE-08). No fixture content in production UI. 
5. **Court-appeal links** (rejection paths) — render as `planned-flag` "Planned · Phase E", keeping the constitutional sitemap visible per the nav convention. 
6. **F-LEG-036 declaration UI** — Phase C (Speaker tooling); Phase B uses the dev seeding command; the VacancyCountback page itself is fully live. 
7. **Elections index page** — no mockup, no contract; entry points are Civic/Home's scoped election cards + ElectionDetail's "other elections" list; `/elections` resolves-or-empty-states instead. 
8. **i18n of page bodies** — chrome-only posture continues (vue-i18n keys for nav/labels only); body copy literal English, matching Phase A.