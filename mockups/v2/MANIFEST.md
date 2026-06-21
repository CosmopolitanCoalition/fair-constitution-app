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
| 1 | **The Live Civic Room keystone** | **done** — `shared/live-room.html` + this config contract; all 8 meeting types instantiate it |
| 2–6 | Journeys · groups/connective · economy · classes 4&5 · polish | **next** (each badged in the launchpad + coverage) |

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
