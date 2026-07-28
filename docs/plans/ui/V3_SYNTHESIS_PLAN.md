# V3 Synthesis Plan — making the app BE the v3 mockups

*Produced 2026-07-28 by the investigation chartered in `V3_SYNTHESIS_CHARTER.md` (15 recon
agents over all 107 manifest records, front end and back end, plus the four cross-cutting
contracts). The evidence sits in `V3_GAP_MATRIX.md` — one verified row per screen. This
document is the build order that closes the gaps, slotted into the **definitive A–O plan**
(`docs/plans/CGA_PHASE_G_AND_BEYOND_ROADMAP.md`) with fleet-lane assignments. The designer's
`mockups-v3-wiring/MASTER_PLAN.md` is consumed here as input, not authority.*

---

## 0. The verdict

**Of the 107 specification screens: 43 conformant (or app-ahead) · 36 partial · 28 absent.
Effort to close: 35 S · 27 M · 21 L · 6 XL (18 need nothing).** The detail is one row per
screen in `V3_GAP_MATRIX.md`.

The app is much closer to the v3 specification than the walk's first screen suggested — but
the distance is concentrated exactly where a walk begins. **A real v3 shell exists in the code
(`AppShellV2`: dock command bar, tour-as-a-mode composable with verbatim mockup semantics,
cosmic-address chips on live residency data, role-aware two-tier menu that is AHEAD of the
mockup) — but it is opt-in rather than default, ~19 of 98 pages still render the old v1 shell
(including the signed-out home the operator landed on), the dock has two flyouts instead of
three (no Demo), the Learn flyout serves specific teaching copy on only 5 of 107 screens, and
the tour knows 21 of 117 stops.** Behind the shell, the institution modules (electoral,
legislature, judiciary, executive, civic) are substantially conformant with the app frequently
AHEAD of its mockups; the true absences are concentrated: the Live Civic Room fusion (all
atoms built, no molecule, no events layer), the public person profile, the achievements page,
five jurisdiction-lifecycle pages over complete backends, two operator pages (dns,
moderation), the economy's design-ahead half, the Learn/lesson pages, the support lifecycle,
and the Atlas. Nothing found contradicts the standing order; most of it makes the standing
order cheaper than feared.

## 1. The frame

1. **The mockups are the spec** (operator ruling 2026-07-28). Default resolution of any
   difference: the app conforms.
2. **Two logged exceptions, never silent** (both operator-sanctioned in kind):
   - **App-ahead functionality** (e.g. `VoteTally`'s bicameral lanes + tiebreak record, the
     judiciary's operative R-19/R-20 forms, role-gated menus, real audited impersonation):
     port INTO the mockup contract — the spec absorbs the stronger behavior. §9 lists them.
   - **Settled-ruling conflicts** (e.g. the mockup wallet's name-based transfer picker vs the
     reader-privacy accounts-never-people ruling): the MOCKUP gets fixed. §9 lists them.
3. **Constitutional or policy questions surfaced by a divergence go to the operator** — §10
   is that ledger. Nothing there is decided by this plan.
4. Work lands on the A–O phase that owns each module's backend, executed by the lane that
   owns that phase (roster snapshot 2026-07-28 in fleet memory).

## 2. Slice 1 — SHELL COMPLETE (the walk unblock; lane 6)

The single highest-leverage slice: every item is S or M, and finishing it changes what every
page looks like — including card A-1 of the walk.

*(Verified 2026-07-28: the 19 pages not on `AppShellV2` are Auth ×3, Setup ×9, Dev kits ×4,
Operator/Operations and Invite/Landing — all deliberately bare/KEEP-class — **plus exactly one
player page: `Home.vue`, the signed-out home. The missing dock the operator saw at walk card
A-1 was that single page.** S1+S2 are therefore one small change, not a 19-page migration.)*

| # | Item | Effort | Evidence |
|---|---|---|---|
| S1 | **Make `AppShellV2` the default layout** (`app.js:16` registers v1); the only player page affected is `Home.vue`; Auth/Setup/kits keep their bare layouts explicitly | S | shell finding 1; verified above |
| S2 | **Signed-out arrival** — replace `Home.vue`'s empty hero with the v3 arrival cover on the V2 shell (four doors: invited / Today / Atlas / a live room) (walk finding W-2) | M | W-2; `index.html` |
| S3 | **Third dock flyout: Demo** — add `cmd-demo` to `CmdBar.vue`, gated on the already-shared `instance.sandbox` prop; retire the separate `DevBar` strip (contents move into it, §3) | S | demo finding 1 |
| S4 | **Restore the role label on the V2 user chip** (v1 has it, V2 dropped it — a regression against the spec) | S | shell finding 4 |
| S5 | **Footer: site-wide Report-an-issue (`?ref=<surface>`) + Accessibility links** (intake already live at `/support/report`) | S | shell finding 9 |
| S6 | **"For the build team" menu section** (coverage, styleguide, kits, `/building`) in the sitemap tier | S | shell finding 2 |
| S7 | **Restore RTL-flip + pseudo-locale QA toggles** in the V2 shell (v1 has them; V2 lost them) | S | shell finding 6 |
| S8 | **Enforce the plain-language idiom** — `plainCodes`/`plainState` are ported but DEAD CODE (zero consumers); sweep player-facing strings through them, keep citations in the Learn layer | M | shell finding 8 |
| S9 | Whole-DOM pseudo-localization parity (server-data strings currently escape en-XA padding) | M | shell finding 12 |

## 3. Slice 2 — DEMO MODE (lane 4 semantics, lane 6 mounts; delivers punch P3 en route)

Every capability the ruling names EXISTS server-side, gated and audit-chained. What is
missing is the one panel and two compositions.

| # | Item | Effort |
|---|---|---|
| D1 | Mount in the Demo flyout: `DevPersonaSwitcher`, residency-grant link, the four kits, `/simworld`, `/system/clocks` | S |
| D2 | **Clock controls UI**: advance-N-days with the dry-run plan RENDERED before apply (this is punch P3), fire-one-timer; the `DevTimeControlsEnabled` 404 is server truth — show its refusal sentence verbatim | S |
| D3 | **Chamber-cast UI** (P4's console): the bloc-ballot POST exists and is pinned ballots-only | S |
| D4 | **"Assume a resident/role of a place"** — one composed endpoint: pick place + role → find (or dev-relocate) an appropriate user → login-as. Today it is three manual steps; the mockup's role/jurisdiction selects promise one | M |
| D5 | **Scenario presets** — the mockup's named situations (`liveSession`, `ubiRun`, …) map to the real demo seeders (`elections:demo`, `institutions:demo-*`), CLI-only today. Web surface = seeder-backed preset buttons, `GuardsSyntheticData` intact. Design note first | L |
| D6 | Case-detail's built-but-disabled Back/Advance walkthrough, the challenge tracker's simulate buttons, judiciary-home's consent meters — switch on under Demo mode | S |
| D7 | ~~Gating decision~~ **RULED (§10 item 4)**: derive the time controls from the sandbox setup choice; refusal rail keys on any NON-demo peer, not on peering itself | S |

## 4. Slice 3 — LEARN PAYLOAD (lane 15)

The join is free: lane 15's K-2 corpus (70 surfaces × two halves, 482 strings) is keyed by
the SAME `config/cga/surfaces.php` surface id the live `LearnFlyout` already receives. It is
simply stranded in Markdown.

| # | Item | Effort |
|---|---|---|
| L1 | **Extract the K-2 corpus to code** — `en/c_education.json` + generated `registry/education.js` per the settled `K2_ENGINE_PLAN` §8 (answer keys, if ever, stay server-only) | M |
| L2 | **Upgrade the flyout body** — render learn sentence + howto steps (`do`/`detail`/`cite`) + why callout with the mockups' sop styling | S |
| L3 | **Author the ~48 uncovered screens** (all economy, support, groups, social, jurisdiction lifecycle, learn, operator dns/moderation/setup, misc shared) — sequence with §6 so copy lands with pages | L |
| L4 | **"Where this fits"** — run the existing flow-context generator against the app registry (key rename: mockup `electoral/` ↔ app `elections/`) | M |
| L5 | Learn pages (learn-home, lesson, guides): L-grade config+page work per `K2_ENGINE_PLAN`; lesson answer-index stays server-side (mockup's client-side answer key flagged must-not-copy) | L |
| L6 | Video spine: **XL, parked** — no media exists anywhere; player design is proven on the operator's WP player; production is an operator-lane concern (lanes 10/11 are leaving the repo) | XL |

## 5. Slice 4 — TOUR TO 117 (lane 6, riding every other slice)

The MODE is fully delivered (`useTour.js` is a verbatim-semantics port). Missing: the stops
and the doorway. Grow `TOUR` in `registry/surfaces.js` from 21 stops toward the 117-stop
contract as each surface conforms (each slice PR adds its stops); build the `/tour` index
page with the First-visit track and give the bar its "All steps" link (S). The tour is also
the single-player learning campaign's skeleton (`TWO_INSTANCES_AND_THE_ROAD_TO_ALPHA.md`) —
stops added here are product content, not scaffolding.

## 6. Slice 5 — MISSING PAGES WAVE (cheap wins first — backends are waiting)

| Page(s) | Backend state | Effort | Lane (phase) |
|---|---|---|---|
| `economy/request-detail` | `LaborBoardService` + pinned F-IND-014 chain complete, just unrouted | S/M | 13 (L+M) |
| `economy/stipend` | `StipendService` implements the mockup's exact formula/classes/cap/k-anon | S/M | 13 (L+M) |
| Public **person profile** (`social/profile`, `?who=` + follow/message + Candidacy/Office tabs) | `social_profiles` + `social_follows` tables sit UNUSED; candidate-profile stub currently points at nothing | L | 15 (K-2) + 6 |
| **Achievements page** | 139-entry catalog + append-only sealed ledger fully built; manifest claim "no catalog in code" is stale | M | 15 (K-2) |
| Jurisdiction lifecycle ×5: bootstrap tracker, union formation, disintermediation, restoration, border settlement | `ActivationService`, `UnionService`, `DisintermediationService`, `RestorationService`, `BorderSettlementService` ALL complete with engine handlers + audit trails; zero routes/pages | 5×L | 2 (G) |
| `operator/dns` | broker backend largely exists (`services/mesh-cert-broker`, CertGrant/BrokerAuthorization); budget rails/DDNS/wildcard unbuilt | L | 2 (G) |
| `operator/moderation` | F-SOC-003/004 backends live and correctly shaped; no surface, no operator-relay path | L | 2 (G) |
| Operator setup rework (five-step wizard, named-role cards, Path-B device-key link) | steps live behind a divergent UI | M | 2 (G) |
| Support lifecycle (tickets list/detail/routing) | intake + `support_reports` live; no lifecycle schema/pages | L | 6 + desk schema |
| `shared/accessibility` + `shared/constitutional-questions` (citations currently 404) | static contract pages | 2×S | 6 |
| Launchpad + `/tour` index | registries exist | S | 6 |
| **Atlas** | public metrics aggregation + opt-in approximate location — the area's one true XL | XL | 3 (I) data · 4 (O) world |
| `economy/joint-ledgers` | all three tables shipped, zero code over them | L | 13 |
| Agreements register (+detail) | `org_contracts` with DB-enforced both-sign; no unified register | M/L | 13 |
| Exchange · org-settings economy half · clause-redline negotiation | design-ahead, forms F-IND-018..021/F-TRE-*/F-LEG-037/038/F-ORG-008 unregistered — the remaining TRUE Phase L/M backend work | XL | 13 design round first |

## 7. Slice 6 — THE LIVE CIVIC ROOM (the keystone; lane 3 + desk)

Production has world-class atoms and no molecule. `VoteTally` IS the contract's vote tile
(the contract was written from it); `AgendaStrip` locks slots 1–2 with the engine re-guard;
`BoardStrip` is the composition strip; all F-SPK/F-CHR/F-SOC-002 registered; the ad74f5f
LiveKit stack covers the call surface ~1:1; the testimony bridge seals to the hash chain.
Missing: the fusion, and the live substrate. Build order the gaps imply:

1. **Events substrate** — no push layer exists at all (no echo/reverb/broadcasting config).
   Bridge: extend the PROVEN 5-second Inertia partial-reload poll (MatrixCommons pattern) to
   session/committee/case props. Real fix: one broadcast substrate (Reverb or SSE) that
   presence, queue, tallies and the timeline all ride. Decision at build time; poll-first
   matches the designer plan's own Phase-6 posture (L).
2. **`LiveCivicRoom` Vue shell** composing the existing atoms (band + agenda + floor tile +
   vote + timeline + call) + per-institution Matrix room provisioning — rooms exist today
   only for square/halls/private, not sessions/committees/cases/boards (M).
3. **Queue / floorHolder / recognition** — entirely absent; new chair-granted state with its
   speaking clock and the four aria-live announcements; `SessionService` already notes
   ESM-08 as the intended host (M).
4. **Session gallery read path** — SessionConsole 302s non-members away, contradicting the
   contract's Art. I gallery rule (§10 item 1) (M).
5. Small closures: translate control over the live server-enforced `TranslationGate` (no
   client UI calls it), testimony badges + Record panel with seal states, floor tile,
   pre-join device pickers (each S).
6. Informal groups' `meetingType` needs the groups feature itself — nearest substrate is
   PrivateRooms (M, after the groups/Messages model is ratified).

## 8. Slice 7 — PER-AREA CONFORMANCE PUNCH (S/M closures on live pages)

Full row-level detail in the matrix; the headline items:

- **civic** (lane 6 + 3): `TodayFeedService` breadth — feed knows 4 row kinds; hearings,
  commons calls, group meetings, stipend runs all have existing data sources (M). MyRecord's
  Office + Groups-&-orgs tabs, overview open-votes (M). **Stale Planned banner on the wallet
  tab — the economy ledger has been live since 2026-07-26** (S). Advocate-registration UX
  (M; policy half in §10).
- **electoral-exec** (lane 3): RankedBallot `liveAggregate` hard-ships null — the mockup's
  signature "if the window closed now" never renders (M); department↔CGC oversight link (M);
  DepartmentReporting "your appointment" card (S); emergency banners on election/executive
  pages (S); two stale-copy defects that mislead (S).
- **legislature** (lane 3): Bills challenge feed is a hard-coded null — [Challenged] chip +
  Art. IV §5 tracker can't render (M); F-LEG-028 has a backend and no filing door (S);
  lifecycle legend past `enacted` (S); conversation/live-room links (S, rides slice 5).
- **judiciary** (lane 3): docket spans one judiciary, spec spans the viewer's chain (M);
  stage 1/2/7 content panels (S); emergency banner (S).
- **jurisdictions-sys** (lanes 1/2): term-sync SVG timeline, amendments current-value cards,
  setup three-way founding fork restructure (M); browser/mapper are the SOURCE of their
  mockups — S additions only.
- **operator** (lane 2): identity enrol/link actions, mesh join-wizard placement (§10).
- **social-groups** (lane 15 + 3): square place-ness (halls presence, @u-handles, seat
  badges) (M); OrgDetail's economy face over existing tables (M); journey world-live strip +
  SOP layer (S/M).
- **learn-tr-support** (lane 5): translation home verifier sign-up + add-a-language CTA (S).
- **economy** (lane 13): live-page additive props — CGC/seller identity, subdivisions,
  budget lines, borrowings (S/M each).
- **organizations** (lanes 13/14): board-elections **open nomination window** (the operator's
  own v3.2 item 0d — org-configurable window-days, third-party nominate/accept-decline; no
  org-settings backend exists yet) (M); transfers-conversions internal-restructuring write
  path (restructurings hardcoded empty — new form + consent rules + history) (L); **fidelity
  defect: `CgcController::codetProps` hardcodes 100/2000 instead of resolving the amendable
  CLK-13/14 settings** (S, fix in passing); registry chrome (endorsing filter, no-faction
  banner) (S).

## 9. Mockup-side fixes (the spec conforms to reality here)

**Staleness** (fix the manifest/notes; no design change): operator module rows point at
`Jurisdictions/Federation.vue` — the built `/operator/*` suite exists; groups notes predate
the Messages pivot; "no AchievementCatalog in code" false; economy "forms absent" predates
F-IND-022/023/024; petition copy "judiciary forming · Phase E"; VacancyCountback "F-LEG-036
arrives with Phase C" (registered, live handler); OversightController stale route comment.

**Settled-ruling conflicts** (mockup wrong): wallet's name-based transfer picker violates
reader-privacy accounts-never-people (shipped write path enforces it).

**App-ahead behavior to absorb into the contract**: bicameral vote lanes + F-SPK-004 tiebreak
record; judiciary's operative R-19/R-20 forms, juror screening + audit read-back; role-gated
menu with Requires-R-xx hints; audited real impersonation; emergency/schema banners; invite
fail-soft landing + carried destination; election-window compression dial; real journeys +
achievements engines (mockups only faked persistence).

## 10. ⚖ Reconciliation ledger — status after the operator's answers (2026-07-28)

### SETTLED — the operator's words, now law for this plan

| # | Ruling |
|---|---|
| 1 | **Session gallery — context decides.** Civic/government actions are PUBLIC to watch, even for non-residents (sessions, committees, courts, referendums get the gallery read path). Private organizations decide their own visibility (board/org/group rooms follow the org's choice). |
| 2 | **Disintermediation — the CONSTITUENTS inherit.** "Constituents inheriting the rules of their former encompassing is the correct direction — this way they can edit themselves independently with respect to their new encompassing jurisdiction if it exists, or just themselves." **The mockup is right; `DisintermediationService` is WRONG and gets fixed** (lane 2, Wave 2, announce-fix-pin — constitutional-adjacent). |
| 3 | **Advocate registration — instant for now.** AND a standing forward directive: **ALL civic roles get a training gate before applying/registering — elected, appointed or otherwise** (K-2 scope, lane 15). ⚠ Constitutional flag the desk must surface before that gate is built: Art. I makes candidacy an absolute right with NO requirement beyond residency — a training gate on ELECTED positions as a ballot-access requirement would collide with the hard constraint. Gates on appointed/registered roles are clean; the elected case needs the operator's word on where the gate sits (pre-ballot vs pre-seating vs advisory). |

Side-ruling recorded from the same answers: **all storage stays UTC; display is always the
user's local via client/browser** — already the app's posture (`timestamptz` UTC, client
formatting); reaffirmed, not a change.

### SETTLED 2026-07-28 (second round) — all ten items now ruled

| # | Ruling |
|---|---|
| 4 | **Demo mode unlocks the clocks — derive from the sandbox setup choice, no `.env` edit.** AND the peer-refusal rail is REFINED, not removed: **demo instances are EXPECTED to peer for full-scale multibox testing**, so the refusal keys on *peered with any NON-demo node*, never on *peered at all*. A demo mesh may time-travel; a mesh containing any real node never does. `launch:assert-clean` unchanged. (Design note for lane 2's multibox work: per-node advances skew shared deadlines — a demo mesh should advance via one coordinating node or explicitly assert skew tolerance.) |
| 5a | **Executive model explorer → Learn, "multiple ways."** AND the live page keeps a real in-game door because the model is AMENDABLE — delegated ↔ elected conversion is a constitutional act; the existing `proposeConversionBill`/`proposeDelegationBill` deep links are that door and stay on the live page. |
| 5b | **Doctrine recorded**: the legislature creates and oversees executive departments, including the **chief executive department, which acts as the orchestrator; an elected executive is those departments in elected form.** The bill-flow deep-link posture (no side-door composer) is RATIFIED; verify the deep link pre-fills the act fields. |
| 6 | **REVERSED from the recommendation: training completion IS a constitutional form.** Completions tie to achievements and the one-time civic stipend for finishing a training, so they file through the engine — an F-EDU form family gets registered (raising the pinned form count deliberately, per the `AuditChainSmokeTest` rule). Quiz ANSWERS stay private — the filing records completion, never answers; the K2 answer-secrecy rail stands. **Any user may take ALL trainings, whether or not they apply to a role they hold or seek.** Supersedes `K2_ENGINE_PLAN`'s zero-forms recommendation — lane 15 updates that plan before building. The Art. I flag from ruling 3 (no training gate as a ballot-access requirement on elected positions) still stands and must be resolved in the training-gate design. |
| 7 | **Adopt the spec's routed six** (`bug/translation/accessibility/content/abuse/idea` with live routing); map `conduct`→abuse, `legal`→the legal plane; `appeal` leaves support intake for the court system. Additive migration for subject + routing columns. |
| 8 | **`/system/clocks` is public, read-only.** |
| 9 | **Join-a-cluster wizard + mesh tooling → `/operator/mesh`; `/federation` becomes the read-only citizen view.** Placement only. |
| 10 | **Build the scenario presets** (Demo-flyout buttons over the real demo seeders; `GuardsSyntheticData` keeps them off real worlds; short design note first). AND a fleet-wide STANDING ORDER: **anything terminal-only gets a UI version; anything UI-only gets a CLI equivalent.** First step: an inventory pass (the artisan command registry vs the UI surface registry) to size the parity debt — desk scopes it, lanes execute per surface ownership. |

## 11. Fleet assignment + build order

```
WAVE 1 — walk unblock (parallel):
  lane 6:   Slice 1 (shell) + S-grade stale-copy fixes fleet-wide
  lane 4+6: Slice 2 (Demo flyout; D7 ruling requested up front)
  lane 15:  L1+L2 (K-2 extraction + flyout body)
  desk:     §9 mockup fixes · §10 ledger to the operator · matrix upkeep

WAVE 2 — pages + conformance (parallel, after or overlapping wave 1):
  lane 13:  economy quick wins (request-detail, stipend) → joint-ledgers, agreements
  lane 2:   DisintermediationService fix (RULED #2: constituents inherit) →
            jurisdiction lifecycle ×5 → operator dns/moderation/setup
  lane 15:  person profile + achievements + L3 authoring
  lane 3:   per-area punch (electoral/legislature/judiciary M items)
  lane 6:   support lifecycle + static pages + launchpad + /tour index
  lane 5:   i18n extraction of every new string (rides each PR)

WAVE 3 — the keystone:
  lane 3 + desk: Slice 6 (events substrate → LiveCivicRoom → queue/floor → gallery)
  lane 6:        tour stops to 117 as surfaces conform
  lane 3/4:      Atlas (XL — after I-phase metrics exist)
  lane 13:       economy design round → exchange/org-settings/redlines (XL, design-gated)

CONTINUOUS: every PR adds its tour stops + Learn copy + i18n keys; suite green at
checkpoints; hot-file discipline (CLAUDE.md, routes/web.php, FormRegistry) per fleet memory.

STANDING ORDER (operator, 2026-07-28, ruling 10): UI <-> CLI PARITY — anything
terminal-only gets a UI version; anything UI-only gets a CLI equivalent. Desk scopes
the inventory (artisan registry vs surface registry); lanes close parity per surface
ownership as they touch each area.
```

**The walk resumes when Wave 1 lands** — that is the charter's exit: the same 54 cards, on
the real shell, with the Demo flyout driving the clocks. Wave 2/3 items become new walk
sections as they ship.

## 12. What this supersedes

- The designer's `mockups-v3-wiring/MASTER_PLAN.md` phase list (0–10) — consumed: its
  Phase 0 items shipped in the mockups (v3.2); its Phases 1–5 partially shipped in code (the
  shell spine, the mapper); its Phases 6–8 map to Slices 5–7 here under A–O ownership.
- Nothing in the A–O roadmap is superseded. This plan is the UI-conformance face of phases
  already built (A–G, K-1/K-3, L+M write path, K-2 corpus) plus the N/O capstones' front door.
