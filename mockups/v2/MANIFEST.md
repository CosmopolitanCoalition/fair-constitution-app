# CGA Mockups v2 — MANIFEST

The game layer over v1's operations contract. v2 reuses v1's design tokens, component CSS,
icons, demo-state, and fixture world; it adds the journey/social/economy/group surfaces on
top and deep-links into v1 for the formal steps. This file is what the production work reads
first: the **Live-Room config contract**, the **v1-reuse map**, the component inventory, and
the a11y/responsive results.

Read alongside `OPEN_QUESTIONS.md` (every divergence from the as-built code) and the source
spec `App Docs/CGA_Mockups_v2_Build_Instructions.md`.

---

## 0. Status — Stage 0 + Stage 1 (this hand-off)

| Stage | What | State |
|---|---|---|
| 0 | Foundation + launchpad | **done** — `index.html`, `assets/js/shell-v2.js`, `assets/js/fixtures-v2.js`, `assets/css/v2.css`, `manifest.json`/`manifest.js`, `shared/coverage.html` |
| 1 | **The Live Civic Room keystone** | **done** — `shared/live-room.html` + this config contract; all 8 meeting types instantiate it; the embodied chamber view (`assets/js/chamber-v2.js`) is the hero |
| 1+ | Embodied chamber view | **done** — institution-accurate SVG seating (hemicycle / courtroom / board / committee / stage / round table / circle), active-speaker glow, move-to-floor, live vote-colouring |
| 2 | Journeys | **done** — `journeys/journey.html` renders all 13 from `?id=`: progress rail, now/your-part/next, live rooms + v1 deep-links |
| L/M | **The economy** | **done (Planned-badged)** — `economy/` exchange, marketplace (+detail), requests (+detail), wallet, joint-ledgers, units & monetary policy, civic stipend, treasury, agreements (+detail), org economics |
| K | Profiles + social + rep↔citizen | **done** — `social/` profile, org-profile, rep, social-home |
| 3 | Connective | **done** — `civic/today.html`, `civic/my-civic-life.html` |
| 3 | Informal groups + legitimacy | **done** — `groups/` (home, create, detail), `social/legitimacy.html` (Phase I gauge, display-only) |
| G | **The operator plane** | **done** — `operator/` (home, setup, console, roles, mesh, dns, identity, moderation, versioning); off the constitutional plane |
| K/N | **Learn & support — the SOP/video/translation/ticket once-over** | **done** — `learn/` (learn-home, lesson, guides), `shared/video-player.html`, `translation/` (translation-home, language), `support/` (support-home, report, tickets, ticket); the SOP panel + multi-track player embedded into journeys |
| 5,polish | classes 4&5 deepening · final a11y/RTL polish | **next** |

### Learn & support — the SOP / video / translation / ticket once-over (this hand-off)
At the operator's direction: make the **standard operating procedure legible in the interface**, give
**every journey/tool/workflow a video in many languages**, build the **education modules**, the
**translation-support interface** (languages × modalities, AI first round → community-verified), and a
**report-an-issue + ticket** system. Three data spines + two shared renderers:
- `assets/js/fixtures-learn.js` (`CGA.fixtures.v2.learn`) — 13 SOPs, 20 videos, 6 tracks, 18 lessons.
  Every SOP is `{steps[{do,detail,cite}], videoId, journeyId, v1, issues[]}`; every lesson wraps a
  video + its SOP + a knowledge check + the live journey.
- `assets/js/fixtures-translation.js` (`CGA.fixtures.v2.tr`) — the canonical 24-language registry
  (curated of **115 mapped in `scripts/etl/languages.py`**, 77 shipped on the marketing site), 6
  modalities (interface, page copy, video audio, captions, education, help), the 5-state lifecycle
  (none → ai_draft → in_review → verified → published), the per-language coverage matrix, the review
  queue, the engine (Haiku tier-1 + NLLB tail, pluggable), and the privacy rail. Exposes `langName`.
- `assets/js/fixtures-support.js` (`CGA.fixtures.v2.support`) — 12 tickets, 6 categories with **routing**
  (bug/accessibility/content → operators, translation → the translation interface, abuse/illegal → the
  moderation & legal plane, idea → backlog), 6 statuses, severities.
- `assets/js/components-v2.js` (`CGA.v2c`) — **`videoPlayer(id)` + `initVideo(root)`**: a faithful mockup
  of the WordPress `functions/video_player.php` (`[subject_video_player]`): ONE silent master MP4 +
  per-language audio `.m4a` + caption `.vtt`, keyed `{Subject}-{Language}.{ext}`, the **link audio &
  subtitles** toggle, drift-correction note, prefs in `localStorage` (carry across videos). Also
  `sopPanel(id)`, `reportLink(ref)` (also injected site-wide into the footer by `shell-v2.js`),
  `stateBadge(tone)`. **No media ships in the prototype — the stage is a labelled placeholder.**
Sidebar gained a **Learn & support** section; the footer gained a site-wide **Report an issue** link.
`qa_scan` clean; every page one h1, no console errors, no 360 px overflow; matrix wrapped + RTL-verified
(Arabic native heading + RTL drafts); the knowledge-check, guides filter, report submit→ticket, ticket
filters, and the accept-draft verify flow all interaction-tested.

### The operator plane (this hand-off)
Added at the operator's direction (the v2 build doc had scoped it out — superseded). The mockups
are now the design contract the coder wires up from Phase A with these layers baked in, so the
operator/infrastructure plane belongs here. Grounded in the as-built code (Federation.vue,
InstanceCapability, OperatorAccount, MeshGateService, PeerUpgradeAgreementService, the G-ID
identity services, ModerationFlipService / LegalComplianceService) and the operator design docs.
Data spine `assets/js/fixtures-operator.js` (`CGA.fixtures.v2.op`): the plane wall, the 9
capability channels (3 self-asserted / 6 governed) → the 4 named roles (Record Keeper / Archivist
/ Social Moderator / Identity Broker), the qualify→request→approve→join lifecycle, the dual-meter
consent (A operator-board / B seated-gov-supersedes / C peer-unanimity-future), the operator
account (plane-walled, device-key possession), peers (ESM-20), FF&C sync, transports, the DNS/cert
broker, G-ID attestation + forwarded writes, the moderation flip + the M-5 legal floor, and the
constitutional versioning. **Framing throughout: off the constitutional plane (capability, never
role); operators are a de-facto board answerable to the seated government (Meter A → Meter B
supersession, automatic).** Built via a fan-out against `operator/operator-home.html`; `qa_scan`
clean; every page one h1, no console errors, no 360 px overflow; RTL/pseudo verified on the
console + mesh. The constitution currency-ops reference has a sibling in the operator pages'
inline citations (Art. V §5 currency/names, Art. V §7 read-write, Art. II §7 freeze, Art. VII
admissibility). Manifest at 36 records.

### Economy + social build (this hand-off)
The economic model is grounded in the constitution (see `CONSTITUTION-CURRENCY-OPS.md`) and the
treasury / civic-stipend design docs. The data spine is `assets/js/fixtures-econ.js`
(`CGA.fixtures.v2.econ`): currency + subdivisions (Art. V §5 measurement standards), the
dual-door monetary levers (F-LEG-031), the economic clock (`ubi_period_days` sweep), the civic
stipend as a **capped role differential** (operator / moderator / office-holder — never a
salary), public/private accounts, **joint-controlled ledgers** (Art. V §2 + Art. I), the private
wallet (never federated), marketplace ↔ request board, instruments of agreement (each with the
Supremacy-of-Rights floor), treasury/budget/ledger, stock (Art. III §5), dues, taxes (Art. V §4,
never on a civic right — Art. II §8), and the rep↔citizen data. Every economy surface badges
itself **Planned** (Phases L/M are design-ahead; the forms F-LEG-037…040, F-IND-018…023,
F-TRE-001…004, F-ORG-008 are reserved, not registered). All built via a fan-out against the
reference page (`economy/economy-home.html`); `qa_scan.py` clean; every page one h1, no console
errors, no 360 px overflow.

Verified: launchpad (5 classes, 13 journeys, 8 room variants), all 8 room variants render
(one h1 each, locked agenda on the legislative variant), interactions work (assume chair →
recognize → call vote → cast, with `aria-live` announcements), responsive clean at
320/360/768/1440 + en-XA + RTL, zero console errors, `qa_scan.py` clean (no hex/emoji/
physical-properties).

---

## 1. THE LIVE-ROOM CONFIG CONTRACT  *(the single most important artifact)*

One component — `shared/live-room.html` — renders every meeting type from a config object
in `CGA.fixtures.v2.rooms[variant]` (selected by `?variant=`). This is the design contract
the dev project lifts: the props that drive **every** variant. The keystone is where the
Matrix layer, the governance forms, and the live timers/roles meet.

```js
{
  variant: 'committee'|'legislative'|'exec'|'board'|'court'|'forum'|'townhall'|'group',
  title: string,                       // header-band identity
  jurisdiction: '<slug>',              // context chip (v1 jurisdiction)
  status: { state: 'open'|'scheduled'|'recess'|'adjourned', label: string },
  chairRole: 'speaker'|'chair'|'presiding_judge'|'facilitator'|'moderator',
  chair:  { handle, name, persona, seat?, jointChair?, gavel? },  // who holds the gavel
  clocks: { agendaItem: <seconds>, speaking: <seconds> },         // countdown displays
  constitutionalOrder: bool,           // true → agenda slots 1–2 are LOCKED (Art. II §2;§7)
  agenda: [ { position, locked, kind, title, status:'pending'|'in_progress'|'done'|'none', current? } ],
  floor:  { kind, title, body, form:'F-…'|null, citation, deepLink:'<v1 path>'|null },
  vote:   null | {                     // the vote tile (null = deliberation-only room)
            question, method:{ label, citation }, mode:'unicameral'|'bicameral',
            thresholdClass:'majority'|'supermajority'|'committee_majority'|'rcv'|…,
            serving, requiredYes, quorum:{ present, required },
            tallies:{ yes,no,abstain }|null,    // null until the chair calls the vote
            outcome:'pending'|'adopted'|'failed'|'tied'|'tied_broken', gloss, deepLink? },
  presence: [ { handle, name?, persona?, seat?, role:'chair'|'floor'|'member'|'gallery'|'vacant',
                online, speaking?, candidate?, advocate?, vacant?, track?:'worker'|'owner' } ],
  queue: [ { handle, name?, seat?, reason? } ],   // hands raised, in order
  floorHolder: '<handle>'|null,        // who currently holds the floor
  chat: [ { handle, name?, seat?, body, testimony? } ],          // the Matrix timeline
  voice: { enabled, participants:['<handle>'], residencyGated },
  translation: { from, to, isPrivate, rail:'server-local'|'cloud-blocked' },
  record: [ { handle, body, sealState:'recorded'|'sealing'|'live', recordHref? } ],  // testimony bridge
  residencyGated: bool,                // gallery-watch vs floor-speak (Art. I)
  galleryNote: string,                 // the read-only-gallery plain-language note
  composition?: { workerSeats, ownerSeats, total, workers, threshold, parity },  // board only
  meetingType?: { kind, label, options:[…] },     // informal-group only
  decisionNote?: string, nextSteps?: [string],    // informal-group only
  forms: ['F-…'],                      // populate the "How this works" drawer
  chairControls: [string],             // plain-language chair buttons (map to F-SPK/F-CHR)
  reusesV1: ['<v1 path>'],             // deep-links for the formal step
  productionPages: ['resources/js/Pages/…'],      // the Vue surfaces this fuses
}
```

**Contract rules the renderer enforces:**
- The vote tile's **denominator is always visible** (`serving`) and the threshold tick is
  drawn at `requiredYes / serving`. Numbers are treated as engine snapshots, never recomputed
  in the UI (mirrors `VoteTally.vue`'s constitutional posture).
- `constitutionalOrder: true` locks agenda slots 1–2 (emergency powers, then constitutional
  matters) with the gold lock + `Art. II §2; §7` citation; the chair may reorder only the rest.
- **Chair controls are live iff the active persona === `chair.persona`** (switch persona in
  the demo bar to take the gavel). Recognition, the speaking clock, calling the question, and
  declaring the result are human chair acts; quorum/tabulation/sealing are ambient.
- Required `aria-live` announcements: **"X now holds the floor", "the vote is called",
  "vote result …"**, plus speaking-clock start/expire.
- Handles are always pseudonymous `@u-<localpart>` (helper `mxid()` — never a legal name).
- Residency gates the **floor**, not the **gallery** (anyone may watch; `galleryNote` says so).
- Testimony seals to the record only from halls-type rooms, own-post only (F-SOC-002).
- Translation: a `isPrivate` room shows the privacy rail as **cloud-blocked** (server-local
  only) — mirrors `TranslationGate`.

### The eight variants (all in `fixtures-v2.js`)
| variant | room | chair | vote? | reuses (v1) | fuses (production) |
|---|---|---|---|---|---|
| `committee` | Clean Air Act hearing | chair (Marcus Chen) | committee majority | committee-detail | CommitteeDetail.vue · MatrixCommons.vue |
| `legislative` | NY County floor session | speaker (Yuki Tanaka) | majority, peg quorum, **locked agenda** | session-console | SessionConsole.vue · VoteTally.vue · AgendaStrip.vue |
| `exec` | exec-committee meeting | facilitator (Kwame Mensah) | majority (equal power) | executive-home | Executive/Home.vue |
| `board` | Bluefin board meeting | joint chair (Tomás Ferreira) | majority (board) | board-elections · co-determination | BoardElections.vue · CoDetermination.vue |
| `court` | Tenant assoc v. Crown Ridge | presiding judge (Lena Novák) | none (judgment) | case-detail | CaseDetail.vue |
| `forum` | Manhattan candidate forum | facilitator (Fatima Al-Rashid) | none (approvals are secret) | open-ballot | OpenBallot.vue |
| `townhall` | participatory-budget referendum | facilitator (Halima Diallo) | none (ballot decides) | referendums · petition-detail | Referendums.vue |
| `group` | Harbor Cleanup Crew | facilitator (Amara Okafor) | none (facilitated) | civic-home | MatrixCommons.vue |

---

## 2. v2 ↔ v1 REUSE MAP  *(which journeys deep-link which screens)*

v2 never re-mocks a v1 governance screen — it wraps it in journey/social context and
deep-links to it via `hrefV1()` (carries demo state). Journeys live at `journeys/<id>.html`
(Stage 2+); each passes through ≥1 Live Civic Room.

| Journey | Class | Live room(s) | Deep-links into (v1 `mockups/`) | Production-equivalent |
|---|---|---|---|---|
| **election** (flagship) | 3 | forum | open-ballot · ranked-ballot · results · election-detail | Elections/* |
| committee-session | 3 | committee | committee-detail · session-console | Legislature/CommitteeDetail · SessionConsole |
| bill | 3 | legislative | bill-detail · bills · session-console | Legislature/BillDetail · SessionConsole |
| court-case | 3 | court | case-detail · constitutional-challenge · case-docket | Judiciary/CaseDetail |
| budget [Planned] | 3 | legislative | session-console | (Phase L) |
| start-org | 2 | board | org-registry · org-detail · board-elections | Organizations/* |
| board-meeting | 2 | board | board-elections · co-determination · org-detail | Organizations/BoardElections · CoDetermination |
| form-a-group | 1 | group | civic-home | Civic/MatrixCommons |
| mutual-aid [Planned] | 1 | group | — | (Phase M) |
| petition-to-referendum | 5 | townhall | petitions · petition-detail · referendums · ranked-ballot | Civic/Petitions · Legislature/Referendums |
| public-service | 5 | legislative | cgc-detail · transfers-conversions · departments | Organizations/CgcDetail |
| stipend-and-tax [Planned] | 5 | — | — | (Phase L/M) |
| two-governments | 4 | townhall | federation · union-formation · disintermediation | Jurisdictions/Federation |

[Planned] = planned-layer (badged Planned). The reuse list is also machine-readable in each
`manifest.json` record (`reusesV1`, `productionPages`).

---

## 3. Architecture — how v2 wires to v1

- **No fork.** v2 pages load v1's `colors_and_type.css`, `fonts.css`, `mockup.css`, plus
  `v2.css`; and v1's `demo-state.js`, `fixtures.js`, `icons.js`, `i18n.js`, plus
  `fixtures-v2.js` (augments `CGA.fixtures.v2`) and `shell-v2.js` (the sole v2 chrome).
- **Load order** (every v2 page): `<head>` css (4) + `demo-state.js`; `</body>`
  `fixtures.js → fixtures-v2.js → manifest.js → icons.js → i18n.js → shell-v2.js`.
- **`CGA.shellV2`** exposes `icon, esc, badge, pill, formatPop, admLabel, hrefV1, hrefV2,
  isBuiltV2, plannedFlag, announce, activePersona, refresh`. `pill(tone,label,tip)` is the
  operator-console plain-language pattern (human label, precise term in the tooltip).
- **Two roots:** `hrefV2(rel)` stays inside `mockups/v2/`; `hrefV1(rel)` crosses back to
  `mockups/` — both carry demo state through `CGA.state.link()`.
- **Demo bar extended additively:** `demo-state.js` `DEFAULTS.scenario` gained five v2 flags
  (`liveSession, marketplace, ubiRun, groupForming, tradeTalk`) — the frozen vocabulary is
  *extended, never renamed*; v1 pages ignore them.
- **CGA_PAGE contract** unchanged from v1: `{ id, title, module, nav, citation, register }`.

---

## 4. Component inventory — `assets/css/v2.css` (additions over v1)

Pills `.pill --live/--wait/--vote/--pass/--closed/--info/--planned` (+`.dotlive` pulse) ·
launchpad `.v2-hero .class-grid .class-card .journey-card .journey-rail` · Live Civic Room
`.lr .lr-band .lr-timers .lr-timer .lr-agenda .lr-agenda-item(.ag--locked/--current/--none)
.lr-body .lr-stage .lr-rail .lr-floor .lr-floor-holder .lr-vote .lr-cast .lr-panel
.presence-row .presence-dot .queue-row .lr-chat-list .chat-msg .chat-seat .chat-testimony
.lr-compose .lr-voice .lr-translate .lr-rail-flag .lr-record .record-row .seal--* .lr-note
.lr-controls .lr-composition .comp-seat` · economy `.planned-surface .planned-banner
.never-federated` · `.wordmark-tag .v1-tag`. All tokens-only, logical properties; inherits
v1's forced-colors, `prefers-contrast`, and `prefers-reduced-motion` handling.

---

## 5. Accessibility & responsive (carried from v1, plus v2 emphases)

- The Live Civic Room is fully keyboard-operable: chair controls, recognize/raise-hand, the
  vote cast cluster, the speaker-rail disclosures, and the chat compose are all buttons/native
  controls. A persistent polite **live region** (`#cga-live`) announces floor changes, the vote
  call, results, and the speaking clock — the hardest a11y surface in the project, treated as a
  first-class requirement (§11).
- Responsive: the two-pane body collapses to one column ≤64rem; the speaker-rail panels become
  collapsible `<details>` on narrow screens. Verified clean (no horizontal scroll) at
  320/360/768/1440 px and under en-XA (+35%) and RTL on the launchpad, coverage, and every room
  variant.
- Status never color-only (pill + label + icon); `flag` is rendered as a behavioral signal,
  never a removal control; the moderation note structurally lacks a viewpoint-removal control.

---

## 6. Regenerating the manifest mirror

`manifest.js` mirrors `manifest.json`. After editing the JSON, from `mockups/v2/`:

```bash
{ echo "/* GENERATED from manifest.json - regenerate with the snippet in MANIFEST.md. */"; \
  printf 'window.CGA_MANIFEST = '; cat manifest.json; echo ';'; } > manifest.js
```

Then run `python ../tools/qa_scan.py` (it walks `mockups/**`, v2 included) and reload
`shared/coverage.html` over http.
