# The desk ledger — rolling fleet record (formerly `WAVE2_QUEUE.md`)

*The Delta Orchestration Desk's running ledger across ALL waves. Renamed 2026-07-29 (operator
flag: a Wave-3 activity was writing to a Wave-2-named file). Sections accrete by wave — Wave 1
inputs first, then the Wave 2 close-out and every Wave 3 delivery/ruling/slot record below.
The desk verifies each landing here (hash + insertion-count) before it is believed. Newest
records are appended at the sections marked with the current wave; nothing here is a lane order
— orders go to lanes by message.*

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

1. ~~The Type B election guard (R-A)~~ **SHIPPED (30e8fec): keyed on the STORED flag (un-blocks the instant the mapper clears it); per-kind (lower house elects beside it); unconditional on world class; Niue stays seated+labeled; the 1141-seat UNFLAGGED at-large pin stays green — R-A blocks exactly the flagged 9,708. Latent model-cast gap closed in passing.** Was: refuse Type B race scheduling on any
   `type_b_needs_districting` chamber, every world class, until the Type B district mapper
   ships — guard + constitutional pin. (Niue's existing seat stays, labeled; re-seat rides
   the mapper.)
2. **Activation/scale redesign (R-B)**: real worlds = institutions + room counts scale
   continuously with ACTUAL playerbase 1→∞; demo worlds = WorldPop-premade; 5–9 is only the
   reps-per-district setup default. Design doc first (touches ActivationTierService /
   InstitutionScaleService), then build.
3. **The amendment workflow loop (R-C)**: verify/complete legislature-amends-a-setting
   end-to-end through the real act pipeline; surface as a walkable workflow + walk journey.

## ⚖ THE TYPE B MAPPER'S SEVEN GATE QUESTIONS (lane 1, `c03b72f` — the operator answers
## before the mapper builds; full statements with costs in
## docs/plans/scaling/TYPE_B_DISTRICT_MAPPER_DESIGN.md)

1. One race or many per grouped panel · 2. the uneven-remainder rule (Niue's 7 villages →
3 pairs + 1?) · 3. do zero-population constituents join panels · 4. the ISLAND /
disconnected-adjacency fallback — **the crux for most of the 9,708** · 5. determinism across
the dual compute paths + SQL mirror · 6. cross-parent grouping (confirm forbidden) · 7.
re-seat stability for Niue's already-seated over-bound chamber.

## For lane 1 — Wave 3 headline (R-A): the Type B district mapper

Stage-two grouping over the adjacency graph (even clumps, compact, no geometry cut) for the
~9,708 flagged chambers. Named plan item per the operator; the campaign's resume point;
Type B election playtesting stays blocked fleet-wide until it ships.

## Lane 15 close-out records (2026-07-29)

- **Art. I disclosure gates (e1db877, announce-fix-pin)**: guests could read any user's
  neighborhood-level home + named residency chain + timestamped travel events — a
  stalking-grade combo, found by lane 15's own 20-agent adversarial verify, fixed and pinned
  (self-or-public gating; location-adjacent events excluded; hidden handles 404; probe
  throttled). No rule changed — code matched to Art. I.
- **THE READING RULE (K2_ENGINE_PLAN §5.2)**: the pre-seating gate reads ONLY F-EDU-001
  completion records — never the achievement ledger (CI-1 stays absolute), never
  education_progress (mesh-wide correctness). `seat_training_window_days` (default 30)
  proposed as an amendable setting, flagged for registration-day review.
- **Orphans for the operator review**: Elections/CandidateProfile.vue (unreferenced) + the
  'elections/candidate-profile' surfaces record (inert) — deletion on his word.
- **Deliberately NOT built (design decisions needed, Wave 3 material)**: per-user DM flow
  (Message button links /civic/rooms; no per-user primitive exists); social-profile
  self-EDIT write path (handle/bio/visibility have no HTTP door — an F-IND-002 extension
  decision); 'jurisdiction' visibility = not-public in v1; record-tab "open the full
  record" link.

## Lane 5 close-out records (2026-07-29)

- **Coverage after the NLLB pass**: fr 84.7 · hi 86.9 · es 86.7 · ar 86 · pt 84.7 ·
  zh-Hans 82.1 — UP 8–10 pts despite 645 new flows keys joining the denominator. 992-string
  gap closed to ai_draft (fr 989/992 … zh-Hans 897/992); everything behind the human
  verification queue, worst-first verified on the rendered surface, ID tokens preserved by
  the masking rail.
- **BUILT-NOT-TESTED strikes again, resolved in-lane**: the "settled default" local NLLB was
  UNPROVISIONED on the dev box (no torch, no GPU, no weights). Lane 5 stood it up in a
  throwaway MEMORY-CAPPED container (a spike kills only its job, never the shared VM — the
  right posture on this box); recipe in shared memory. **⚑ For lane 11 (operator's OneDrive
  migration awareness): the dub path's "shares the GPU" comment is false on this box — there
  is no GPU.**
- **zh-Hans 95 QA-skips**: NLLB-600M long-form fidelity uncertainty, confirmed real with
  samples — the gate correctly routes them to people who read Chinese. Refusal is the
  answer; a stronger model is the future path, never a weakened rail.
- flows keying: text-deterministic slug_sha8 survives regeneration; lanes 15/6 can land
  independently (vue-i18n falls back to en).

## The one deliberately-carried Wave 2 item (desk steer 2026-07-29)

**RankedBallot liveAggregate — HELD for fresh secrecy-first treatment**, lane 3's own
recommendation adopted. It is the ballot-secrecy item: `BallotBox::decryptForCount()` forbids
any HTTP-request-stack caller and `BallotSecrecyTest` greps for exactly that rogue pattern, so
an in-request provisional count is a CONSTITUTIONAL VIOLATION, not a perf choice. The build is
an out-of-band worker (first-preferences + Droop-if-closed-now projection only, writing neither
a tabulations row nor `ballots.counted`), then adversarial verification of the secrecy boundary.
Rushing that at the tail of an 11-commit run is the wrong risk; the desk holds it as the single
open Wave 2 item into the operator review. The 3 parity UIs (audit:reconcile / provision /
activate — thin guarded adapters) land first.

⚖ Operator cadence question (mechanism is non-negotiable regardless): live per-window
first-preference standings during an OPEN ballot can influence later voters. Design §B.5
sanctions visible standings; the CADENCE (per-request vs daily-frozen like approval standings)
is a secret-ballot policy call. The no-in-request-decrypt MECHANISM stands either way.

## Wave 3 desk records (2026-07-29)

- **⚖ B5 PROBE (lane 1, in front of the operator)**: the meaningful tie-break — (a) max
  total internal shared-border length (reuses `border_len`; cheapest; desk + lane 1
  recommend) / (b) minimax intra-panel diameter / (c) min cross-panel edges cut — each with
  a lowest-member-id final fallback; plus SQL-mirror DEFERS to the PHP mapper service
  (grouping is a graph algorithm; one source of truth = the determinism guarantee).
- **A5 SUPERSESSION recorded (lane 15's correct reading)**: acquiring-free extends to
  appointed/registered roles too — the first-ACT gate is the ONLY gate point, superseding
  the original pre-seating ruling's application-time gate, per A5's own words ("when they
  go to take their first action in that role").
- **The quorum design ACCEPTED**: seated = serving unconditionally; no training term ever
  enters the Art. II §2 / Art. VII denominators (hard-constraint arithmetic untouched; the
  withhold-training manipulation vector closed). Untrained counts and may be present, cannot
  cast until trained; never-trains falls to existing vacancy machinery.
- **All seven Wave 3 lanes compaction-pending** (the escape hatch used fleet-wide before the
  heavy builds); lanes 1/15 landed their first items pre-hold (3d1a1dc, 7bd2ea7).

## Divergence ledger (spec ↔ app, logged never silent)

- **Ruling 9 placement (`d69aff0`)**: substance delivered (/federation = citizen view;
  console on the operator plane) but the console landed at **/operator/federation** as its
  own surface rather than inside /operator/mesh per the ruling's letter. Plausible reason:
  mesh.html's contract covers PEERING, not cluster hosting. Lane 2 to state the reasoning in
  its report; reconciliation question for the mockup side: does operator/mesh.html absorb
  the cluster console, or does the spec gain a screen? Neither blocks.

## Attribution corrections (permanent record — history is never rewritten)

- **`7721372`** (lane 2, "the four jurisdiction lifecycle pages") also carries, swept by the
  pathspec-commit trap: lane 15's `/people` + `/achievements` routes, the `social/profile`
  surface record, the achievements nav flip, the `/candidates` 302 forwarder; and lane 4's
  simworld route block. Treat those hunks as lanes 15/4's work. (Sweep #5; law v2 + corollaries
  in FLEET_CONTEXT. The referenced controllers landed in `3f81290`, closing the broken-origin
  window; the desk's push carried both.)

## Suite triage at Wave 1 close (lane 4's checkpoint, 2026-07-29 — 22 failures, four families)

| Family | Tests | Cause | Owner / remedy |
|---|---|---|---|
| ~~Class-refusal~~ | ~~PeerTransportLearning ×3, CapabilityRegistry ×1~~ | **CLOSED by lane 2 (`e3df1ba`): fixture-scoped `InstanceClass::override`, rail re-run green untouched** | — |
| Fixture-borrows-the-world | ~~AutonomyFlipRewraps ×5 + LocalAutonomyGoverned~~ **CLOSED (`e3df1ba` — yes-votes = actual civic population, dual-gate meter positively asserted)**; REMAINING: AutoscalePin ×3, RemainderSynthesis ×3, ManualDistrictDraw ×1 (lane 1), LegalCompliance ×1, MyProfileTabsTest representatives-chain ×1 (wants an adm1 without a legislature; flagged by lane 15, pre-existing) | the filled, time-traveled shared world | lane 1 (+2 unowned) |
| ~~Disintermediation direction~~ | — | **CLOSED by lane 2 (`5f6615e`, ruling 2 + Art. V §8's own text): per-constituent copies w/ full history + incorporation marker, original archival-superseded, encompassing gets NOTHING; pin asserts the ruled direction** | — |
| Order-dependence | MatrixCarveoutEmitter, ModerationFlip | pass isolated; documented | unowned, known |
| ~~Real findings~~ | ~~LedgerIntegrity writers~~ | **CLOSED by lane 13 (`5c2742e`, Wave 2 item 1): SCAN HEURISTIC confirmed — every hit was a READ; zero writes. Pin rewritten to match writes not mentions, scope widened to database/+routes/, negative controls both directions; 13/13 green. Doctrine kept: reads are lawful — the ledger is PUBLIC; reader-privacy = accounts-never-people, never a hidden ledger.** | — |
| ~~CgcIpPublicDomain~~ | ~~grant drift + catalog reference~~ | **CLOSED at the desk (`9ac567f`)** — born-failing grant revocation written as a real migration; doc-string false positive renamed; 6/6 green | — |

## ⚖ NEW operator items from Wave 2 execution

-1. **Oversight-console disclosure (lane 3, ⚖)**: does §10-1's "public to watch" extend to
   the LIVE oversight console — in-progress ethics investigations and removal proceedings
   against NAMED members — or only to published findings (already public via
   /system/public-records)? §10-1 named sessions/committees/courts/referendums, not
   oversight; the controller's own contract says "findings publish to the public record."
   Lane 3 verified the fold is technically clean and correctly HELD it as a policy
   extension. **Desk recommendation: keep the live console member+R-29-gated, findings
   public — an in-progress investigation of a named person is not yet a proceeding of
   record, and premature exposure is weaponizable; extend only if the operator wants the
   stronger transparency posture deliberately.** (Speaker-tools stays gated by lane 3's own
   call — an acting workspace, not a proceeding; the session is the watchable thing.)

0. **Tour-nav placement (lane 6, flag 2)**: the mockup's nav "tour" opens the /tour INDEX
   first (Start arms the mode); the app's PLAYER_NAV still jumps straight into stop 1. The
   index exists and is reachable via TourBar "All steps" + the launchpad. Repointing touches
   MenuNav's special case — placement decision at review. Also recorded: two param-dependent
   tour targets deferred until a demo world guarantees a stable posting/handle (flag 3);
   static pages made PUBLIC per the records precedent (flag 4, endorsed).

### ✎ Spec fixed by the desk (2026-07-29): the mockup taught Webster

`shared/constitutional-questions.html` entry #4 said "Webster-apportioned / Webster-rounded"
— against the SETTLED seating law (never a textbook method). Lane 6 caught it while building
the app page (which renders the correct giant-cascade / round-NEAREST doctrine); the desk
rewrote the mockup entry — the only Webster left in the spec is the prohibition itself.

1. **Setup ORDER conflict — spec vs settled ruling (lane 2)**: the v3 mockup's setup flow puts
   ACCOUNT before the founding FORK; the settled 2026-07-05 ruling (pinned in SetupController)
   says FORK-FIRST. Lane 2 matched the mockup's shape only where the ruling allows; reordering
   needs the operator's word. Options at review: re-rule to the mockup's order / fix the
   mockup to fork-first.
2. **Pre-account Path-B** (device-key linking with no local password) needs a pre-auth
   endpoint — a trust-surface design question; the setup card states it honestly instead of
   building silently.
3. `seat_training_window_days` (default 30) — registration-day review (lane 15, §5.2).

## Migration slot queue (one at a time)

1. ~~lane 13~~ — LANDED (cf8a090, down() proven live)
2. ~~lane 6~~ — LANDED (096a1e9, routed-six live, down() proven at 0 rows)
3. ~~lane 2~~ — LANDED (3c68031, declared_class/game_mode; keyless queue carries demo-ness; DevTimeGate order-dependence hardened; family green bundled 30/107). MIGRATION SLOT NOW EMPTY. (until then a
   queue-admitted mirror declares at its first real handshake — recorded, safe)

## Attribution corrections (permanent record — history is never rewritten)

- **`cde4b1c`** (lane 2, the operator pages) also carries lane 15's 4-line `/people` throttle
  hunk — staged into the shared index between lane 2's verify and commit (sweep #6, the
  index-race mechanism; law corollary recorded in FLEET_CONTEXT). Complete and self-contained;
  attribution noise only.
- **`cd6f56e`** (desk, the first-sweep ledger commit) also carries lane 15's staged stage-①
  unit: `ConstitutionalEngine.php` +10 (SENSITIVE_KEYS), `EducationAnswerKeySecrecyTest.php`
  +146 (new), `FuturePhasePlaceholdersTest.php` +6 — sweep #7, same index-race mechanism, at
  the desk's own hand: the chained `add && diff && commit` showed 4 files staged and barreled
  past its own verify step. Lane 15 notified (verify content, run pins, follow-up commit for
  any half-staged remainder). Desk procedure hardened: stage → count staged files → commit
  ONLY on exact-match, in one gated shell call.

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

## WAVE 3 RESUME DISPATCH (desk, 2026-07-29 — operator's final GO, post-compaction)

All seven compacted lanes (1, 2, 3, 4, 6, 13, 15) received `ACTION:` resume orders off
HEAD `50b8456`. Lane 5 deliberately NOT messaged (uncompacted; continuous order armed —
a message is an actuator). Lane 14 stays HELD. Deltas dispatched:

- **Lane 1**: mapper build per 3d1a1dc; **B5 RULED = option (a)** max total internal
  shared-border length (border_len), lowest-member-id fallback; SQL mirror DEFERS to the
  PHP grouping service; B4 hull validation FIRST with early report; **migration slot FIRST**.
- **Lane 15**: staged ①→⑤ per 7bd2ea7; **slot SECOND** — flag at step ③, wait for grant;
  113→115 mint announced deliberately; answer keys server-side; no-ballot-gate pin absolute.
- **Lane 3**: keystone; A1 oversight-public warm-up; poll-first substrate stands;
  **store contract to the desk BEFORE composition**; exit test = committee hearing in one room.
- **Lane 13**: design round DOCUMENTS ONLY → docs/plans/economy/DESIGN_ROUND_2.md,
  delivered as OPEN QUESTION; F-IND-019 reconciled in-doc.
- **Lane 4**: C3 study → SERVICE_SCALE_FORMULA.md (cited, Niue/San Marino/Earth examples,
  manual-after-governance) + ATLAS_DESIGN.md; formula to operator before wiring;
  R-A un-flag keys strictly on `type_b_needs_districting` clearing.
- **Lane 2**: coordinator service/CLI/UI first against the columns; migration written but
  **slot THIRD**, lands on desk signal; refusal matrix pinned; rehearsal runbook second.
- **Lane 6**: A2 toggle arm-in-place (/tour = index, every page a stop); stops toward 117 on
  existing surfaces only (keystone stops wait on lane 3); coverage instruments must provably
  FAIL on seeded drift; opportunistic S8.

Migration slot queue (Wave 3): **1 → 15 → 2**. RankedBallot liveAggregate still awaits the
operator's fresh-session trigger (spec 3505f51; cadence RULED daily-batch).

**Slot progression:** lane 1 LANDED (23452a7, `2026_07_29_150000_type_b_district_grouping.php`,
144=144, down() proven) → lane 15 LANDED (fee13a1, `2026_07_29_190000_education_engine_tables.php`,
down() proven; ② at 96634ee minted F-EDU-001/002 — **the engine holds 115 forms**, pin +
CLAUDE.md same commit; ① verified byte-intact in cd6f56e) → slot GRANTED to lane 2 (LAST in
queue; date after 190000; queue closes on its clear). Lane 15 proceeding ④ TrainingGateService
+ ⑤ Learn pages.

**First sweep (7-agent audit, ~25 min post-dispatch): ALL SEVEN LANES ON PATH, zero
deviations.** Lane 3 landed the A1 oversight-public fold (4057b3c) and correctly excluded a
peer's dirty engine file from its commit; lane 15 mid-①; lane 13 running four read-only recon
agents before authoring (docs-only respected); lane 4 gathering cited anchors (US House
435→20 committees; civic-venue-per-population standards); lanes 2/6 in early build reads.

**The 100/107 dependency note (recovered from the pre-compaction projection verbatim):** the
~100 target COUNTS (a) the economy build (~10 rows) landing after the operator approves lane
13's design round mid-wave, and (b) the RankedBallot fresh-session build (1 row). Video
player + possibly Atlas were parked by name outside the 100. Both operator triggers must fire
mid-wave for the target to hold.

**Store contract RULED (desk, c6399aa reviewed): Q1 = A uniform 5s + per-group cadenceMs
affordance (lane 2's future load valve, no rewrite); Q2 = B status-gated (full cadence
open/recess, ~30s heartbeat scheduled, STOP on adjourned) + desk pin: the adjournment-announcing
snapshot merges BEFORE the stop.** Composition unblocked; §4 guarantees + both knobs pinned in
LiveRoomStoreContractTest. NAMED DEBT recorded: 9 hand-rolled pollers (PrivateRoom,
RankedBallot, Results, VacancyCountback, Build/Progress, Operations, Districts, SimConsole,
Step2_MapData) await a later peer-file consolidation pass onto useLiveRoom.

**B4 hull validation delivered (db93712, B4_HULL_ISLAND_VALIDATION.md) → OPERATOR ITEM:** the
invited check ("check me on it") found: the engine already seats islands via the shipped
`closestApproachSq` nearest-host attachment (islands-ride-whole, Art. II §8); enclave logic is
sound iff both clips hold; antimeridian guarding is real and already precedented; and
hull-CONTACT adjacency for GROUPING reduces to a buffered proximity graph (hulls of separated
islands never touch — the buffer distance d becomes a free parameter controlling everything).
**LANE 13 ECONOMY BUILD 4/6 shipped (migration-free half, commit-law clean): dues 18fd02d
(set()-merges-onto-persisted footgun fixed) · telemetry 5940c99 (account-clean, source-scan
pinned) · exchange 3f6da2a (fungible/non-fungible over F-IND-022, order book honest "not built")
· F-ORG-008 share issuance 07ae162 (equity on the NAMED plane, stock-only, agent-gated; count
115→116 PINNED). MIGRATION SLOT GRANTED for pieces 5-6 (the anticipated agreements/redlines
exception) — 4 additive tables (clauses/redlines/resident_agreements/resident_agreement_signers)
as ONE migration ≥210000, migrations-folder discipline enforced, no foreign pending. TWO FLAGS
DISPOSED: (1) founding-stake-on-registration DEFERRED to Wave 4 (structure-aware — 100% stake
wrong for member-owned/nonprofit; touches F-IND-012) — desk-confirmed; (2) secondary share
TRADING DEFERRED to Wave 4 (operator ruled ISSUANCE, delivered; secondary needs own schema +
his ruling; shares floor stays honest-empty) — surfaced to operator, doesn't block 5-6. P2P
agreement form = a mint → 116→117 pinned in the build.**

**⚑ WAVE 4 PRE-COMPACT DISPATCH (2026-07-29, operator's explicit instruction — NOT the launch).**
Seven pre-compact HOLD messages sent to lanes 1, 2, 4, 5, 6, 13, 15 (lane 3 EXCLUDED — already
compacting; lane 14 HELD). Each carries: its L#W4 order verbatim, the rubric link + badge, the
corrected Type B model, the two settled rulings, commit law v2, four-way reporting, STEP 0 =
pull ≥ 9fb0a60. Disposition = HOLD (do NOT start; the LAUNCH is a later ACTION order). SEQUENCE
per the operator: (1) pre-compact sent [DONE] → (2) operator compacts the lanes → (3) operator
compacts the DESK → (4) desk returns → (5) operator's word = LAUNCH Wave 4 = send each lane its
W4 order with an ACTION disposition (same text, from WAVE4_STANDING_ORDERS.md / the rubric Fleet
tab). Do NOT launch before that explicit word.

**⚑ OPEN QUESTIONS ALL ANSWERED (2026-07-29) + WAVE 4 ORDERS FOLDED, AWAITING GO/NO-GO.** The
operator answered all 9 open questions via the rubric form (0 open / 14 resolved now). Answers:
education arming = A (pre-train demo members); mass pass = A (GO after the race fix, game box);
lane 3 compact = A (he will NOT manually walk until all-green); RankedBallot = A (build,
DAILY-BATCHED — standings are invisible-until-count today, controller passes liveAggregate=null);
secondary trading = A (IN, lane 13); handshake 500→4xx = A (FIX, lane 2); B2 = A (compact-first);
oversight LIVE console = **B (government is PUBLIC by default incl. in-progress vs named members;
orgs decide their own — ⚑ SETTLED LAW in FLEET_CONTEXT, never re-ask)**; orphans = A (DELETE —
⚑ SETTLED, never re-ask). All folded into the Fleet-tab W4 orders (RULED = …). **Orders are
PREPARED and shown in the rubric Fleet tab with a "awaiting go/no-go" banner — NOTHING dispatched
to any lane. On the operator's GO the desk sends per-lane ACTION orders.** (Desk drifted toward
dispatch mid-turn; operator corrected — draft ≠ launch.)

**⚑ WAVE 4 STANDING ORDERS PREPARED (2026-07-29, operator asked, PREPARED not dispatched —
awaiting his GO):** goal = as GREEN as possible across UI Screens + Capabilities → tested
playable game. Per-lane orders in docs/plans/ui/WAVE4_STANDING_ORDERS.md + the rubric's new
**Fleet & Waves** and **Open Questions** tabs. Headlines: lanes 1+3 = THE TYPE B RACE FIX
(seating+counting, per-child/per-clump — unblocks Type B elections + all bicameral acts); lane 6
= civic/social partials + bill.html + THE WALK; lane 13 = 12 economy partials; lane 4 = build
the Atlas; lane 2 = operator/system screens + handshake hardening; lane 5 = INTEGRATE the
operator's EXISTING multi-track video player (he built it; mockups based on it; ref fleet-11
video-translate + coalition site — NOT a from-scratch build) + i18n; lane 15 = education arming
+ profile-edit/DM; lane 14 HELD. OPEN QUESTIONS: 9 open (education arming · game-box mass-pass go
· lane 3 compaction · RankedBallot trigger · secondary trading · handshake 500→4xx · B2 · live
oversight scope · orphan deletions), 5 resolved (Type B shape · founding-stake defer · setup
fork-first · oversight public · video player exists-integrate). Rubric artifact (5 tabs, same
URL): https://claude.ai/code/artifact/101c137d-df9b-4785-b8ff-8072532e2619.

**⚑ VERIFIED APP-STATE RUBRIC (2026-07-29, 8-agent verification workflow vs LIVE code — not
asserted): SCREENS 72 built · 32 partial · 3 absent of 107** (up from 63/34/10 at Wave 2 close;
3 absent = video-player, atlas, bill). **CAPABILITIES 38 working · 10 partial · 3 blocked · 5
absent of 56** (blocked = Type B second-chamber race + the bicameral-agreement it cascades to;
absent = profile-edit, DM, founding-stake, RankedBallot-live, mobile app). **TECH DEBT 23 items
(2 high · 10 medium · 11 low)** — high = the Type B race defect + its doc drift. Forms 117 pinned,
phases 0–5 complete. Rubric artifact (drillable, screens+caps+debt+phases):
https://claude.ai/code/artifact/101c137d-df9b-4785-b8ff-8072532e2619 · source
docs/plans/ui/tools/app_progress_rubric.html.

**⚑ WAVE 3 CLOSE TALLY (2026-07-29 ~12:15, HEAD=origin, all pushed):**
- **CLOSED (four-way confirmed): lanes 1, 2, 4, 6.** Lane 2 SLOT CLEAR — coordinator migration
  landed 82f4683 (re-dated 200000, LAST in slot; down() proven batch-scoped so lane 15's batch
  untouched; reversibility pinned; contained a 13-file self-sweep BEFORE origin, insertion-count
  caught it). **THE SCHEMA QUEUE IS CLOSED FOR THE WAVE.** Lane 6 done — live-room stop (tour
  47 stops/12 acts) + nav-drift ruling adopted (7 aligned, translations allowlisted, coverage
  reads All-clear 0/0/0); holding only for lane 3's step-5 to append more live-room stops (a
  dependency-hold, its own slate is complete). Lane 4 live-verified racePlan on REAL Niue
  (at_large 10, blocked=false) read-only.
- **BUILDING (git-observed): lane 3** — behavioral useLiveRoom node pin landed 86fcdb7 (per the
  desk JS-runner ruling: extracted a framework-free liveRoomPolicy.js state machine, 15 node
  assertions + source-scan belt; JS-runner question CLOSED fleet-wide — this extract-to-pin
  pattern is the template for any future JS behavioral pin). 7-commit run accepted. **CAPTURE
  COMPLETE + VERIFIED — safe to compact at will, zero state loss;** holding for the operator's
  compaction before the exit walk (STEP 0 → seat committee A → 4b → the EXIT WALK). **lane 15** — ACTIVE, NOT done (desk-corrected 2026-07-29: I wrongly inferred "done" from
  git commits + a memory file, never a live sweep — the stale-status-from-inference failure).
  LIVE STATE: its steps landed (a2061f9 gate, 6c4ee09 Learn pages, b885dab e2e proof) but it has
  NOT committed since b885dab and has NOT sent a four-way DONE. It ran the FULL-SUITE GATE and
  found **12 FAILURES**; verified all 45 of its own education/gate tests green (so the 12 are NOT
  its); flagged lane 13's FormRegistry docblock drift (115 vs the 116 pin — routed to lane 13);
  is naming the 12 for its report. **lane 13** — economy 4/6 shipped, 5-6 building on the granted
  slot (schema 4d58d53).
- **LANE 13 DONE (four-way, verified):** economy all 6 pieces + migration + routes + UI, 9
  commits (…d624596 backend 5+6 · 9f00b04 routes+UI · 42c4f73 render pin · 6366c47 docblock→117).
  Registry 117 in lockstep (FormRegistry docblock + pin + CLAUDE.md; lane 13 also corrected a
  STALE CLAUDE.md 115→117 that F-ORG-008 had missed). Subsystem sweep 123 tests / 232,743
  assertions GREEN (economy/org/redline/ledger/co-determination/audit-117/telemetry). Two Wave 4
  items to operator (founding-stake, secondary trading). Schema queue CLOSED.
- **LANE 15 DONE (four-way, verified):** K-2 all 6 steps; namespace 45/45 green
  (Education*/TrainingGate*/LearnPages/AuditChainSmoke). Arming-sequencing OPEN QUESTION refined
  to 3 options (A: seeders file F-EDU-001 for seated demo members = "trained" pass [rec] · B:
  seed but leave untrained so the walk demos the redirect loop live · C: don't seed, e2e-only) —
  gates the operator's browser walk of the gate; already in the Wave 4 decision queue.
- **⚑ FULL-SUITE GATE: 12 FAILURES — RESOLVED TRIAGE (3rd + final; two desk mis-diagnoses
    corrected).** ALL 12 ARE PRE-EXISTING; **Wave 3 introduced ZERO regressions.** History of my
    errors, kept as the record: (1) I called lane 15 "done" from git+memory (operator caught it);
    (2) I then "verified" the 7 federation reds as a Wave-3 regression via an instance_settings
    serialization leak — WRONG. Lane 2 read the code and corrected me; I re-verified the correction:
  - **7 federation console/handshake — PRE-EXISTING, now FIXED + GREEN (lane 2, 3a6c542, 9 tests
    128 assertions):** 6 broke at lane 2's **Wave 2** route move `d69aff0` (verified ancestor of
    50b8456: /federation → /operator/federation; the tests kept hitting /federation, getting the
    citizen view). Uncaught only because **the full suite was never run at the Wave 2 gate.** The
    handshake 500 was the scale_demo class-fixture pattern (fixed with InstanceClass::override).
    My "leak" was fiction — the console's `instance` prop was ALREADY a field whitelist. My error:
    checked the CONTROLLER (untouched) but not the ROUTE; treated an INCOMPLETE known-failures list
    as "green at W2." Root cause = a route move, not the migration.
  - **5 remain (pre-existing, none touched by Wave 3 — git-verified):** LegalComplianceTest +
    MyProfileTabsTest (documented Wave 2 triage, fixture-borrows-the-world) · MatrixCarveoutEmitter
    + ModerationFlipTest + SupportLifecycleTest (⚠ each FAILS ALONE now — the Wave 2 triage said
    the first two "pass isolated," so either stale or shifted; NOT certified pre-existing with the
    federation-level confidence — a shared-change interaction can't be 100% ruled out without a
    baseline run, which is unsafe on the shared DB). Route to owners for Wave 4 debt-paydown.
  **THE REAL PROCESS FINDING:** the full suite was NOT run at the Wave 2 gate, so pre-existing red
  hid until now. "Every lane green in its own domain" ≠ "the suite is green." Run the full suite
  at EVERY wave gate. Wave 3 BUILD = clean; the suite is now at ~5 pre-existing reds (12 − lane 2's
  7); a clean full-suite confirmation is the real close gate.
- **REMAINING BEFORE WAVE 3 CLOSES:** lane 3's exit walk (post-compaction), lane 13's economy
  5-6, lane 15's ACTUAL DONE (after the 12-failure gate clears), the 12-failure triage.

**⚑ OPERATOR DECISION QUEUE (all Wave 4 scope — NONE block Wave 3 close):**
1. **Grouped-Type-B RACE SHAPE (lane 1, code-verified).** A grouped Type B chamber currently
   produces a BARE at-large STV race — racePlan emits `at_large` with N seats, createRaces makes
   one plain STV race over all residents, and `election_races` has NO panel/grouping column, so
   "seats follow the panels" is inexpressible without a schema + counting decision. FORK:
   **⚑ OPERATOR CLARIFIED THE MODEL (2026-07-29, two-context spec) — the fork is RESOLVED to the
   per-unit family:** Type B = ONE AT-LARGE RACE PER CHILD JURISDICTION; when a size ceiling forces
   clumping (reps-per-child ladder 5→2, then nearest-neighbor clump pair/tri/quad), ONE AT-LARGE
   RACE PER CLUMP — voted at-large WITHIN that child/clump for its own equal seats. NOT one pooled
   race over all residents. CONSISTENT: ungrouped = per-child, grouped = per-clump, same rule, no
   wrinkle. **THE BUILT CODE IS WRONG:** racePlan/createRaces emit ONE pooled at-large race over
   ALL residents (the old "(i)"); the CLAUDE.md line "Type B is ONE STV race, however many seats"
   is ALSO wrong and must be corrected. Seat MATH already matches (Niue 5 clumps × 2 = 10); only
   the RACE STRUCTURE changes — one at-large race per child/clump, in racePlan/createRaces +
   PROTECTED VoteCountingService (needs a grouping/clump key on election_races). WAVE 4 BUILD
   (joint lane-1 seating + lane-3 counting). ONE CONFIRM PENDING: is it per-child even when
   nothing overflows (no clumping)? Desk reads YES from "one per child jurisdiction" — awaiting
   the operator's word before marching orders. **NIUE RE-FLAGGED + DESK-VERIFIED (branch YES — trigger was ARMED):**
   EvaluateClocksJob->CLK-01->ScheduleGeneralElection is live and Niue has armed CLK-01 timers
   (2026-08-18/09-01) on an advanceable dev clock, so a fired timer would have minted the bare
   wrong-shape race. Lane 1 re-flagged in one tx; desk confirmed on-box: flagged_chambers=1,
   Niue 11/14/25 (ladder placeholder restored, the type_b>type_a-IFF-flagged invariant holds),
   grouping demoted active->DRAFT (5 panels/10 seats PERSIST as the ready-to-activate plan).
   racePlan type_b = BLOCKED again; type_a half still generates. The "first cleared chamber"
   milestone stands proven; re-activating the draft + clearing the flag restores Niue correctly
   once the ruling + panel read-side land.
2. **Education ARMING SEQUENCING (lane 15).** `education:seed` arms the act-gate for 6 civic
   tracks — once armed, every UNTRAINED role-holder redirects on their next role-act. The dev
   box is deliberately UN-seeded (gate inert, suite green, e2e seeds in-transaction). Before the
   operator's training-loop walk needs an ARMED box: decide **pre-train the demo members** OR a
   **demo-members-are-trained seeding pass**. Playtest-prep item.
3. Carried: B2 residual (compact-first shipped), game-box mass pass, lane 3's compaction.

**⚑ LANE 1 ADVERSARIAL VERIFICATION VERDICT (2dc5cd0, desk-verified, 30 pins green): the pass
FOUND 3 REAL DEFECTS the 27 pins missed — all in the tight-bound/island regimes the ample pins
never exercised (= exactly the game box's atolls/hamlets/archipelagos). ALL FIXED + PINNED,
0 refuted.**
- **#1 HIGH — a DRIFT / seating-law violation** (the headline): the pMax<1 branch forced one
  full rep_floor panel the combined cap couldn't afford → type_a+type_b > population (7 reps for
  6 people) AND cleared the flag resolved-forever. A micro-territory would have been permanently
  mis-seated the instant the mass pass touched it. FIX: seat ZERO panels when bound < rep_floor.
  **Caught BEFORE it ran against 9,708 real chambers — "DRIFT IS ALWAYS WRONG" working as
  intended.**
- **#2 HIGH — B7 violation:** apply()'s legislatures UPDATE ran for status='draft' too, so a
  next-term DRAFT resized + un-flagged the SITTING chamber. FIX: gate on 'active'.
- **#3 MED — B2:** island/no-signal walk ordered its tail by id → remainder could land on the
  HIGHEST-pop constituent. FIX: rank (distance, population, id). Plus a vacuous signature test
  replaced with a real order-independence pin.
- **LATENT (desk-ruled DEFER to Wave 4):** AutoscaleResizeRepair SQL mirror clamps
  type_b_seats_per_child to 5 while PHP doesn't — unreachable today (setting validated out >5,
  DDL default 5, all 26 rows =5; PHP is authoritative, mirror defers). Additive CHECK-constraint
  is a Wave 4 hardening candidate; cannot fire against the mass pass. HIGH CONFIDENCE recorded
  for the game-box mass pass. This vindicates "27 pins ≠ verified-at-scale."

**LANE 4 WAVE 3 CLOSED (four-way confirmed): R-A UN-FLAG PIN 7d14649 (ElectionStageTest 10/10;
non-vacuous — a before-state assertion proves the race appears BECAUSE the flag cleared, via
the REAL TypeBDistrictMapper::apply(), no stage edit). BONUS RESOLVED: lane 4's Wave-2
coordination flag (would a large at-large Type B be misreclassified?) came out green with NO
coordinated edit — the guard keys strictly on the persisted `type_b_needs_districting` column,
which the "lawful at any size" fixture never sets, so the 2026-07-26 "lawful at any size" and
the R-A "blocked pending districting" rulings stay cleanly separated by design. R_A_OBSERVANCE
records both. Lane 4 HOLDS for the Wave 4 Atlas build order.

LANE 1 WAVE 3 BUILD CLOSED (four-way confirmed; dev-side complete; 27 Type B pins green): engine 00e959e +
B3 cap 4214721 + Niue proven + B6/B7 pins 56a70dc (never-cross-parent + versioning, 117 ins) +
MAPPER UI DOOR 67e1aaa (Step-3 "Group Type B chambers" — the CLI's parity twin; UI↔CLI parity
row closed). ONLY REMAINING lane-1 item = the mass pass over the real ~9,708 flagged chambers,
which live on the GAME box (dev had only Niue, now cleared) — an operator-box logistics call,
queued to him. LANE 2 migration reversibility proof 2ad6262 + re-date to 200000 b14be80
(down() re-proven). All hash-verified.**

**LANE 3 CHECKPOINT (6 commits, all verified; floor OPERABLE via 3c107cf — raise-hand →
FIFO/named recognition → clocks reset → yield, chair-gated, ephemeral, guest-bounced 7 green):
exit-test data RULED option A at the desk (lane seats a minimal committee as verification
setup + standing demo artifact); AGENDA-SCHEMA FLAG recorded (per-item progression needs
structured agenda; committee_meetings.agenda is a plain string list — Wave 4 candidate);
ESCAPE HATCH INVOKED before steps 4b+5 — lane HOLDING with captured state, compaction
REQUESTED OF THE OPERATOR (his act alone); resume order = seat (A) → 4b → the exit WALK.**

**LANE 2 WAVE 3 SLATE CLOSED (2nd lane done): migration LANDED fba9669 (98 ins, applied,
suite green vs real schema) — THE SCHEMA QUEUE IS CLOSED for the wave (any further need =
desk flag; lane 13's agreements table is the known future one). Runbook 2f34222
(CLOUD_REHEARSAL_RUNBOOK.md: C1–C9 dry checklist + wizard-walk capture + 115-pin delta);
pixel debt logged b785bae. ⚑ FLEET LESSON PINNED (FLEET_CONTEXT): `migrate` runs every FILE
in database/migrations/ committed or not — lane 15's migrate applied lane 2's held file
early (landed clean; no harm). Held migrations live in the SCRATCHPAD until the slot opens;
check migrate:status for foreign Pending before running. Lesson relayed to lane 13. Lane 4
rulings folded (84838b7, verified); Niue ping RE-ISSUED (its HOLD crossed the announcement).**

**TRIPLE LANDING (desk-verified all seven hashes): LANE 1 — B3 cap 4214721 (SQL mirror
byte-identical, zero drift) + THE GROUPING ENGINE 00e959e (TypeBDistrictMapper + type-b:district
CLI, ETL-rule chunked; 25 pins; both CLAUDE.md worked examples reproduced) + **NIUE = THE FIRST
CLEARED CHAMBER** (14 villages → 5 panels, 7 inert, 10 seats, flag FALSE, racePlan at_large) —
announced to lane 4 for the true-path pin. B4 Option-A ruling RE-RELAYED (another busy-lane
miss). B2 residual interpretation → OPERATOR (compact-first shipped, rec (a)); game-box mass-run
logistics → OPERATOR. LANE 3 — useLiveRoom 82834c2 (Q1/Q2 honored incl. final-snapshot-before-
stop) + LiveCivicRoom 0f6ea40 (THE MOLECULE: committee hearing composed on real data,
DOM-verified guest gallery; floor = ephemeral cache, no schema). JS-runner ruled at the desk:
lane 6's node tests/js idiom, NO vitest. LANE 6 — A2 toggle 6c7569d (mode armed in place) +
stops 29→46 d96dcfa + coverage instruments 4788822 (PROVEN FAILABLE live + 20 pins; caught 16
false-deads and 9 REAL nav drifts — 7 ruled align-to-registry at the desk, translations pair
allowlisted for lane 5). Keystone stop relayed to lane 6 (/rooms/committee demo artifact).
Pixel debts all carry.**

**Lane 2 coordinator SHIPPED (fa4e628, 1213 ins, desk-verified): DemoMeshTimeCoordinator
(originate/replay claim-then-advance idempotent, §3 refusal names the coordinator, §4 skew
hatch), ingestTail side-effect replay post-txn, dev:mesh-time CLI + /dev/clock/coordinator +
flyout mesh state (parity three-legged). 8 tests/46 assertions; refusal matrix pinned both
ways; degrades SOLO clean unmigrated. DESK-ACCEPTED CALL: hasColumn/hasTable null-safe guards
let code+routes commit NOW while only the migration holds (DevTimeControlsEnabled idiom;
routes-committed-immediately honored). Slot signal MISSED it (same busy-lane pattern as lane
3) — re-issued short-form; its written 180000 file re-dates after 190000 before landing.
Pixel debt (flyout mesh state needs columns+demo peers): review list.**

**Lane 3 provisioning DONE (30d72fe, 3 pins green): session/committee/case = PUBLIC commons
rooms under the jurisdiction Space; board = PRIVATE entity room never Space-bound (§10-1);
ENTITY_CASE as a PHP constant, no migration; idempotent lazy provisioning. FLAG RECORDED
(deferred, post-wave): an organization-visibility setting (public opt-in for boards) would
need a schema slot — correctly not written, the private default is constitutional as-is.
QUEUED-MESSAGE MISS confirmed live (rule 4): lane 3 reported idle on Q1/Q2 after the ruling
was sent — ruling RE-ISSUED idempotently. THE RE-ISSUE MISSED TOO (two consecutive body
losses to the same busy session; other lanes' deliveries fine) — third issue sent SHORT-FORM
(the two answers + the pin only). Fleet lesson recorded: rulings to a busy lane go short-form
first, detail second; a lane that suspects a miss may page the desk transcript (lane 3 did —
correct behavior, and it refused to build on a guessed ruling).**

**Lane 13 DELIVERED (eaabedb, DESIGN_ROUND_2.md, 529 lines, docs only, sweep-clean despite ~14
dirty peer files): four surfaces designed OPTIONS+COSTS+REC; 11-decision matrix + 2
cross-cutting rulings (A: separate the controller / fold the telemetry; B: equity on the NAMED
ownership plane, money legs account-scoped — a genuine privacy ruling, flagged not settled)
QUEUED TO THE OPERATOR — formatted in the desk chat. ID reconciliation done in-doc (registry
is 115 live; F-IND-019 is Work Application, NOT free; free = F-IND-018/020/021, F-ORG-008,
F-TRE-001..004, F-LEG-037..040 — re-verify at build time). Headline design calls: NO matching
engine (ride the shipped list→order→settle rail; order book = honest chrome); NO dues
scheduler (dues = recurring org_contract + honest absence); one shared clause/redline model
with TWO authority adapters (bilateral consent for agreements; motion+chamber-vote for bills;
BillVersion stays whole-text); Art. I floor = structured rights-tags refuse pre-commit +
void-in-part backstop with the honest limit STATED (prose cannot be proven rights-safe).
NOTHING BUILDS until the operator rules; build order on ruling = substrated pieces first.**

**Lane 4 DELIVERED (6b33e19, docs only, sweep-clean): SERVICE_SCALE_FORMULA.md (cited —
Taagepera cube-root, CEPEJ ~22 judges/100k, House/Senate committee ratios, civic-center
1/25–100k; extends the EXISTING InstitutionScaleService, defers to the two cube-roots;
manual-after-governance grounded in InstitutionProvisionService's refusal boundary; Niue/San
Marino 59/Earth 1,999 worked) + ATLAS_DESIGN.md (nightly world_stats rollup read like reach —
NEVER a live count; CI-1 gauge-never-lever end to end). NINE operator judgment calls queued
(formula §9 ×5, Atlas §8 ×4) — formatted to the operator in the desk chat; formula wires into
InstitutionScaleService only after his sign-off (lane 3 R-B, next wave). COORDINATION FINDING
relayed to lane 1: racePlan()'s un-flagged else-branch emits bare at-large and does not read
groupings — the read-side must land before/with the first un-flag; racePlan is a hot file
(lane 3's guard landed there this wave). DESK OBLIGATION: ping lane 4 at lane 1's first real
cleared chamber (it pins the un-flag path against true behavior, not a synthetic flip).**

~~QUESTION QUEUED~~ **RULED (operator, 2026-07-29): OPTION A** — Type B grouping adjacency =
centroid nearest-approach (closestApproachSq reuse), one unified graph (border_len edges where
land borders exist, nearest-approach where they don't), B5 tie chain unchanged. The hull keeps
its Type A home: line-splitting over-ceiling archipelago constituents (both clips +
antimeridian guard) — empirical exercise is a game-box item, does not gate the wave. Island
path UNBLOCKED; island chambers un-flag through the same engine path as contiguous.

---

## WAVE 4 LAUNCH (2026-07-29 — operator's word given post-compaction)

The full 5-step hand-off completed: pre-compact HOLD orders sent → operator compacted the lanes
→ operator compacted the desk → desk returned → **operator: "Launch Wave 4."**

**8 ACTION orders DELIVERED** off a FRESH session listing (located by title, ids copied verbatim
into the send calls; per session-id law NO id is persisted here — locate fresh by title):

| Lane | Session title (locate fresh) | Headline W4 item |
|---|---|---|
| 1 | 1 (H) - GeoData and District Maps | Type B race fix — SEATING half (+ mass pass, 2 fixture reds, SQL-clamp CHECK) |
| 2 | 2 (G) - Cloud Launch - Multibox | Operator/system partials + cross-class handshake 409/422 + Matrix red |
| 3 | 3 (I) - Institution Scaling | Type B race fix — COUNTING half + keystone exit walk + RankedBallot daily-batch + oversight-public |
| 4 | 4 (O) - Simulated World Engine | BUILD the Atlas + wire service-scale formula + R-A un-flag |
| 5 | 5 (N) - Translation Scaling | Integrate operator's EXISTING video player + i18n + zh-Hans QA + translation-home |
| 6 | 6 (N) - UI Design + A11y Audit | Civic + social/groups partials + bill.html + orphan delete + THE WALK (biggest screen lever) |
| 13 | 13 (L+M) - Economy Engine | 12 economy partials + secondary share trading + structure-aware founding-stake |
| 15 | 15 (K-2) - Education + Achievements | Education arming=PRE-TRAIN + profile-edit/DM + journey partials + window-days review |

Lane 14 HELD (unmessaged). Every order: STEP 0 = `git pull` to HEAD ≥ 9fb0a60; verbatim L#W4
items from WAVE4_STANDING_ORDERS.md; full standing law (commit v2, migration-slot-one-at-a-time,
four-way reports, session-id law, settled rulings).

**COORDINATION PAIRS (desk watches):** Type B race = L1 seating + L3 counting (shape must agree,
PROTECTED VoteCountingService); service-scale formula = L4 provisioning + L3 scaling (one formula,
two call sites); R-A un-flag = L4 pins on L1's first real cleared chamber (desk pings L4 there);
journey/social-home = L6 + L15 co-owned.

**MIGRATION SLOT QUEUE (grant ONE, flag the rest):** L1 election_races clump/grouping key ·
L3 agenda per-item schema · L13 secondary-trading table. Sequence on first-flag order; the race
fix (L1) is the headline unblock so it has priority if contested.

**DESK NOW IN THE WAVE 4 MANAGEMENT LOOP** — sweep, verify hashes, process findings into this
ledger + the rubric (badge items as work lands, redeploy same URL), sequence the slot, format
deliverables for the operator, run the FULL suite at the wave gate. GOAL: as GREEN as possible
across UI Screens AND Capabilities → a tested, playable game. The operator will NOT walk anything
until all-Green.

### W4 tick 1 — first ACKs + migration-slot ruling (2026-07-29)

**6/8 lanes running** (1/2/3/5/13/15); **lanes 4 & 6 transient 529 Overloaded** — orders
delivery-confirmed + queued, NO re-send (double-delivery is the hazard); they self-resume on a
plain "continue" nudge when the API settles. Lane 3's async alignment msg to lane 4 is queued,
not lost.

**LANE 3 four-way ACK (L3W4):** STEP 0 clean (HEAD 8f70cb2 ≥ 9fb0a60). Grounded ① — the
counting engine run() is already per-race/agnostic; the POOLED defect is enforced by (a) schema
constraint `election_races_one_at_large_per_kind`, (b) racePlan's type_b branch, (c) createRaces,
(d) a stale VoteCountingService comment L223-226 ("…ONE STV race"). Fired shape-lock → lane 1,
formula-alignment → lane 4 (async), building all unblocked items (⑨⑧⑦⑤⑥②). Surfaced (not
decided) that lane 1's TypeBDistrictMapper still encodes the old "ONE race" B1 — aligning WITH
lane 1, no ruling reversed.

**⚑ MIGRATION-SLOT RULING (desk):** the slot is RESERVED for **lane 1's race-fix election_races
migration** (clump/grouping key **+ relax/replace `election_races_one_at_large_per_kind`** to
allow multiple at-large races per kind — ONE migration, lane 1 is the ONLY lane touching
election_races). Rationale: it's the headline unblock and lane 3's counting side is blocked on it.
**HOLDING behind it:** lane 3's ④ agenda per-item table, lane 13's secondary-trading table. Both
told to build non-schema parts meanwhile. Release order: agenda (L3) right after L1's race-fix
migration lands, then secondary-trading (L13). Lane 3 told: wire ③ against the COMMITTED formula
(84838b7), don't wait on lane 4's live confirm.

### W4 tick 2 — slot GRANTED to lane 1 + Type B file boundary + Niue gate (2026-07-29)

**LANE 1 four-way:** STEP 0 clean (HEAD 8f70cb2 ≥ 9fb0a60). ④ fixture reds both diagnosed as
own-the-world reds on the now-seated box (MyProfileTabsTest:109 no unseated adm1;
LegalComplianceTest:288 aJurisdiction grabbed a seated one) → fixing in the rolled-back tx
(Wave-2 technique 1), no migration, no coordination. ① design doc writing.

**⚑ KEY CONVERGENCE — Type B counting core needs ZERO change.** Lane 1's analysis (consistent
with lane 3's "run() is per-race/agnostic"): a per-clump Type B race == a multi-seat at-large STV
race, which VoteCountingService ALREADY runs (San Marino's live at-large race is the proof). The
only functional election-domain edit is **RaceFootprint** — add a panel-jurisdiction LEFT JOIN so
a per-clump race enfranchises the UNION of its constituents (per-CHILD races already work via
jurisdiction_id). RaceFootprint is NOT protected. So the race fix is LOWER-RISK than feared — no
PROTECTED counting-algorithm surgery, just schema + racePlan/createRaces + RaceFootprint + a
stale-comment cleanup.

**⚑ SLOT GRANTED → LANE 1** (both additive files, one slot turn):
 (a) election_races.type_b_panel_id (nullable uuid, FK legislature_type_b_panels) — clump key.
 (b) ⑤ AutoscaleResizeRepair SQL-clamp CHECK constraint.
 + the SAME migration relaxes/replaces `election_races_one_at_large_per_kind` (the pooled-race
 enforcer). Release order on lane 1's report: L3 agenda → L13 secondary-trading (both holding).

**⚑ FILE-OWNERSHIP BOUNDARY (Type B race fix):**
 • LANE 1 = schema (type_b_panel_id), racePlan, createRaces, RaceFootprint (electorate LEFT JOIN).
 • LANE 3 = PROTECTED VoteCountingService verdict + stale L223-226 comment + counting pin.
 Lane 1's "zero counting change" is INPUT to lane 3, NOT a verdict on lane 3's protected file
 (standing rule: the lane that reads the code outranks reasoning-at-a-distance). Direct L1↔L3
 coordination approved; desk arbitrates only on shape divergence.

**⚑ DESK-HELD GATE:** Niue's grouping flag does NOT clear in the new shape until lane 3 CONFIRMS
per-clump counting (we re-flagged Niue once to avoid seating a wrong-shape race — don't repeat).
Sequence: L1 lands schema+racePlan+RaceFootprint → sends L3 the shape → L3 verifies+pins → THEN
clear Niue. Independent adversarial verification of the counting claim reserved for the wave gate.

### W4 tick 3 — slot queue set (L1→L4→L3→L13) + InstitutionScaleService seam contract (2026-07-29)

**LANE 4 four-way:** STEP 0 clean (HEAD 4db2085 ≥ 9fb0a60, 0/0). Building the Atlas now
(AtlasController, Pages/System/Atlas.vue porting mockups/v3/atlas.html, SnapshotWorldStatsJob,
world:stats CLI) — everything non-schema first so world_stats is the last, smallest step.
Correctly read the ledger, saw the slot is lane 1's, did NOT author the migration. NO open
questions.

**⚑ MIGRATION-SLOT QUEUE (updated):** L1 (ACTIVE — race-fix election_races) → **L4 (world_stats,
one table)** → L3 (agenda per-item) → L13 (secondary-trading). Lane 4 inserted ahead of L3/L13
because world_stats is the final gate on the entire Atlas front-door (absent screen + capability
= big green lever) and L4 lands the surface the same tick the slot opens; L3/L13 aren't
blocked-waiting on their tables. Ready-first override stands (no idle slot). Each releases on
landing + hash report; desk pings the next holder "slot open".

**⚑ InstitutionScaleService SEAM — resolved lane4↔lane3 direct, logged as a standing contract:**
1. Formula faithful: lane 3's four §8 statics reproduce (Niue K=2/D=5 · San Marino 6/10 · Earth
   24/30), signatures verbatim; their departmentTarget zero-rule guard is a faithful §4.1
   improvement (kept — the doc's bare floor-3 would've given an uninhabited place 3 departments).
2. Clean seam, no file collision: InstitutionScaleService = lane 3's; lane 4 owns the provisioning
   CALL SITE only, consuming ONLY courtTiers + extraRooms (Q4 ruling a) — committeeTarget /
   departmentTarget stay reserved to a seated chamber (F-LEG-009 / F-EXE-001 / F-LEG-016).
3. ETL wrinkle, solved by precedent NOT a fork: InstitutionProvisionService is set-based chunked
   INSERT…SELECT (ETL rule) → can't call PHP statics per row. Lane 4's SQL is a MIRROR of lane 3's
   tierFor() reference impl (same contract as the existing sourceSql()), held by a PARITY PIN
   across every band/boundary.
⚑ CONTRACT (desk-pinned): lane 3 owes lane 4 a ping if any curve/clamp moves, so mirror + pin
travel in the SAME commit. Desk flags a formula edit that lands without the ping. Sequencing:
lane 4's parity pin depends on lane 3's statics being COMMITTED first (uncommitted as of L4's read)
→ L4 ② after L3 ①.

**WAVE-GATE CHECKLIST (accruing):** run the parity pin (mirror==PHP), run lane 3's per-clump
counting pin, run the FULL suite — verify the cross-lane "zero counting change" + "SQL mirror ==
PHP" claims by EXECUTION, not by trusting the ping-pong. (Standing: verify the diagnosis, don't
infer it.)

### W4 tick 3b — CORRECTION: 37b7a64 swept in lane 3's WIP (shared-index race, desk error)

**WHAT HAPPENED:** the tick-3 ledger commit `37b7a64` accidentally captured lane 3's uncommitted
work — `app/Services/InstitutionScaleService.php` (107) + `tests/Constitutional/InstitutionScaleTest.php`
(91), the four §8 formula statics. A plain no-pathspec commit swept the WHOLE shared index while
lane 3's files were staged in it. Desk error.

**VERIFIED, nothing broken:** ran the test against the committed code → PASS, 13 tests / 101
assertions, GREEN. Lane 3's working tree is clean for both files (full content committed, no
fragment, nothing dangling). Index now empty.

**DISPOSITION:** NOT rewriting history (other lanes have pulled 37b7a64) — the commit stands.
Lane 3 + lane 4 both informed. Silver lining: lane 4's parity-pin dependency (needs lane 3's
statics committed) is now satisfied EARLY. Mis-attribution (lane 3's code under a desk ledger
message) noted here; the work is lane 3's.

**ROOT CAUSE + HARDENING (commit law refinement):**
1. I chained `reset → add → git diff --cached → commit` in ONE shell call. The diff PRINTED the 3
   foreign files, but chaining meant the commit fired regardless — the "read the diff BEFORE
   committing" gate only works if the read is a SEPARATE step I actually inspect and act on. NEVER
   chain the diff-check into the commit call.
2. A plain no-pathspec commit takes the index → vulnerable to the shared-index race (a peer lane
   stages files in the window). For a WHOLE-FILE doc commit (the ledger, append-only), use a
   PATHSPEC commit `git commit <path>` — it commits only that path from the working tree and
   IGNORES foreign staged files (race-immune). The "pathspec-commit trap" only bites PARTIAL
   staging (working-tree ≠ your carefully-staged index); for a whole-file append there is no
   divergence, so pathspec is both correct and race-safe. This very correction is committed that way.

### W4 tick 4 — lane 1 DONE (slot released) + the 3-commit mislabel record (NO REWRITE) + lock-free commit primitive

**LANE 1 DONE + accepted (live sweep):**
• `126d753` — election_races.type_b_panel_id (nullable uuid, FK legislature_type_b_panels) +
  RELAXED election_races_one_at_large_per_kind to exclude type_b + election_races_type_b_unit_unique
  on COALESCE(type_b_panel_id, jurisdiction_id) + ⑤ CHECK constitutional_settings.type_b_seats_per_child
  ∈ {NULL, 2..5} (PROVEN in a rolled-back tx: 7 rejected SQLSTATE 23514, 4 accepted). Applied on dev.
• ④ fixture reds `a32bbc8` — MyProfileTabsTest + LegalComplianceTest green (20 passed / 142 assertions).
• IN PROGRESS (pure code, no slot): racePlan/createRaces → per-child/per-clump Type B races;
  RaceFootprint → panel-jurisdiction LEFT JOIN. Then hands lane 3 the exact race shape + column names.
  ⚑ NIUE GATE honored — lane 1 HOLDS the flag-clear for the desk until lane 3 confirms per-clump counting.

**⚑ MIGRATION-SLOT QUEUE (advanced):** L1 DONE → **L3 (agenda — OFFERED; may defer to its counting-half
work) → L13 (secondary-trading).** If L3 defers, L13 gets it next and the desk circles back to L3.

**⚑ THE 3-COMMIT MISLABEL INCIDENT — AUTHORITATIVE ATTRIBUTION RECORD (ruling: NO REWRITE).** During
the white-hot W4 shared index, three commits carry the WRONG message; ALL code is present, correct, and
green. We do NOT rewrite history (11 live committers have pulled). This entry IS the correction:
  1. **258d611** (lane 3's ③ message) ALSO contains **LANE 13's 4 economy files** — swept by lane 3's
     two-call stage/commit split. Lane 13's code is SAFE; true author = lane 13.
  2. **37b7a64** (desk "W4 tick 3 … seam contract") ALSO contains **LANE 3's ③** —
     InstitutionScaleService.php (107) + InstitutionScaleTest.php (91). Swept by the desk's broad add.
     True author = lane 3. Verified green (13/101).
  3. **59510f4** — lane 3's recovery empty no-op, harmlessly buried under lane 1's 126d753. Inert.

**⚑ LOCK-FREE COMMIT PRIMITIVE — adopted fleet-wide** (lane 3's derivation; full recipe in memory
`feedback_git_commit_pathspec_multilane`). Build the commit in a PRIVATE GIT_INDEX_FILE seeded from HEAD
(`git read-tree`) → `git add` into it → `write-tree` → `commit-tree -p HEAD` → CAS
`git update-ref HEAD <new> <parent>` (moves only if HEAD unchanged; retry on a concurrent move). Never
touches the shared .git/index, can't sweep foreign files, immune to the diff→commit micro-race. Caveat:
commit-tree runs NO hooks (this repo has none). Discipline: commit IMMEDIATELY after each edit — never
leave work uncommitted in the hot tree. Desk's simpler variant for my-only files (this ledger): pathspec
commit `git commit <path>` (race-immune, proven tick 3b — and this very entry).

### W4 tick 5 — Atlas ownership split RULED + rubric corrected (lane 6 stale finds) + generator repo-stabilized

**LANE 6 four-way #1:** STEP 0 clean. ④ tour-nav DONE (`0fb053f`, 8-assert pin proven failable,
verified in Vite-served modules). Flagged 2 stale rubric rows + the Atlas ownership collision +
running a 13-agent READ-ONLY gap analysis over its 12 partials + bill.html (honest-empty, never
fake data). Co-ownership handled direct (journey/social-home w/ L15; org-profile economy reads w/
L13, holding accounts-never-people).

**⚑ ATLAS OWNERSHIP — RULED SPLIT.** badged.json badged the PAGE L6; WAVE4_STANDING_ORDERS gave
"build the Atlas" to L4; lane 4 had started Atlas.vue — collision, XL screen. Ruling (the proven
fleet seam):
 • LANE 4 = Atlas BACKEND: AtlasController, SnapshotWorldStatsJob, nightly world_stats rollup,
   world:stats CLI, CI-1 gauge-never-lever, the PROP CONTRACT. Told to STOP building Atlas.vue +
   hand L6 the props/scaffold.
 • LANE 6 = Atlas PAGE: Pages/System/Atlas.vue (porting mockups/v3/atlas.html) against L4's props.
 Both messaged. wave4_data.py updated (L4 ①=backend, L6 ⑦=page).

**⚑ RUBRIC CORRECTED (lane 6's verified stale finds — verify-before-delete, exactly right):**
 • Debt "orphan surfaces" → RESOLVED: already executed (ruling A4); CandidateProfile.vue gone,
   surfaces record removed; /candidates/{candidacy}→people.show?tab=candidacy survives (not orphan).
 • Debt "/judiciary/docket 302" → RESOLVED: fixed `8745a71` (Inertia + honest empty state); "public"
   was a nav-slug misread; the real public read is /judiciaries/{judiciary}/docket.
 • L6 order ⑤⑥ annotated DONE. (V3_GAP_MATRIX.md:611's candidate-profile description is still stale
   — doc-only, deferred to a later doc sweep; the live rubric artifact is now correct.)

**⚑ GENERATOR REPO-STABILIZED:** gen_app_rubric.py no longer depends on the vanished scratchpad
(355910cb) — sys.path + badged.json resolve via `__file__` (repo tools dir); dead base/res/trans
block removed. Regenerated (207,592 bytes; 107 screens / 56 caps / 23 debt / 9 lanes / 14 questions)
+ REDEPLOYED to the same artifact URL (101c137d…). Stamp → "Wave 4 LAUNCHED — road to green, in
progress", head 41d5239.

### W4 tick 6 — L3 (3 landings + scope) & L15 (arming done) + journey/social-home boundary + slot queue lightened

**LANE 15 four-way:** ① PRE-TRAIN ARMING DONE @ c47b50f (SeatedMemberTrainingService; education:seed
publishes-then-pretrains; dev box UNSEEDED on purpose; 49/49 green; EducationNoGateTest strengthened,
enforcement pinned to one home). ② PROFILE-EDIT (F-IND-002 ext, social_profiles cols exist) + p2p DM
(reuses social_spaces/memberships via PrivateRoomService create+admit) — NO SLOT NEEDED; social values
OFF the public chain (deanon vector); "message-from-a-found-profile, not a directory" honors
pseudonymity. ④ seat_training_window_days dead-in-code (doc refs only), A5 stands. Holding nothing.

**⚑ JOURNEY + SOCIAL-HOME BOUNDARY RULED** (L6+L15 co-owned, DESK_LEDGER co-own note). Specialty-aligned
split (lane 15 proposed, desk confirmed): **L15 = educational slices** (journey "Understand it first" SOP
+ Learn deep-links; social-home community-standards card — four carve-outs + why-drawer); **L6 = presence
strip / @handles / seat badges / tag chips / a11y layout**. social-home = Civic/PublicSquare.vue @
/civic/square. L15 holds the shared .vue until L6 acks; both messaged.

**LANE 3 four-way — 3 landings + scope corrections:**
 • Landings (all green, CAS-clean): ③ formula 37b7a64 (13/101) · ⑧ ModerationFlipTest 6fe2c77 (4/29) ·
   ⑤ department-reporting half f333eba (PhaseDPageSmokeTest 99 asserts). Accepted.
 • ⑦ "9 pollers" → really **4** (only MatrixCommons/PrivateRoom/Results/VacancyCountback are the
   useLiveRoom shape; the rest are job-progress fetch pollers a reload-store can't model). Ratified.
 • ⑨ oversight-public **already LANDED** 4057b3c (ruling B in full — public incl. in-progress + named).
   Residual = TEST-HARDENING only (guest-read pin + widen write-bounce list). Ratified.
 • ⑥ liveAggregate **CACHE-BASED** (Redis daily-frozen ≥24h TTL + counts-only chain entry) → MIGRATION-FREE,
   NO SLOT. Ratified (durable ranked_standings table = future slot only if audit-parity demands).
 • ① counting half HOLDING for lane 1's confirm on 3 specifics (racePlan/createRaces status · does
   RaceFootprint scope production electorate · CountingStage/SeatingStage fixture ownership) — RELAYED to
   lane 1 for its shape handoff.

**⚑ MIGRATION-SLOT QUEUE (lightened):** L1 DONE (released) → **slot OPEN, L4 (world_stats) next** (pinged;
claims when its non-schema Atlas-backend parts are ready) → L3 agenda (HELD behind L1, no rush) → L13
secondary-trading. DROPPED from the queue: L3 ⑥ (cache) + L15 ② (columns exist) — neither needs schema.

**RUBRIC deltas to fold at next regen (not yet regenerated — batching):** L15 ① done; L3 ③⑧⑤ done;
⑦ count 9→4; ⑨ production half done (residual test-only); Atlas split already in.

### W4 tick 7 — ⚑ FLEET-WIDE COMMIT-LAW DEFECT (deletion-blind guard) + L6 ballots_cast bug

**LANE 6 escalated a safety-critical hole:** the INSERTION-COUNT sweep-guard every lane runs is BLIND
to deletion-heavy foreign sweeps. A peer staging a mostly-deletions change into the shared index
between `git reset` and `git commit` passes the insertion compare silently — and deletion/replacement
is the NORMAL shape of this wave. Lane 6's f28d626 carried lane 3's DepartmentReportingController.php
(−40) + DepartmentReporting.vue (−28) — a MID-FLIGHT half-written state (differs from lane 3's later
f333eba), incident #4's failure mode. REPAIRED: `git reset --soft HEAD~1` → mixed reset → re-add only
its 2 files → plain commit → aaa0a59 (+10 −2, file-list verified). --soft/mixed never touch the working
tree, so lane 3's work survived → they committed f333eba themselves. Nothing lost / misattributed.

**⚑ CORRECTED GUARD (fleet standard — BROADCAST to all 8 active lanes):** guard on the FILE LIST, not
counts. `EXPECT=sorted($MINE); STAGED=sorted(git diff --cached --name-only); [ "$STAGED"="$EXPECT" ] ||
ABORT`; after commit compare `git show --name-only` to the same list + BOTH insertion AND deletion sums.
BEST option = lane 3's LOCK-FREE CAS commit (private GIT_INDEX_FILE → commit-tree → update-ref CAS;
immune, never touches the shared index). The foreign-name grep is NOT reliable (no keyword list is
complete). Commit IMMEDIATELY after each edit. Canonical home: memory feedback_git_commit_pathspec_multilane
(lane 6 recorded incident #6). Desk's my-only-file commits are already immune (pathspec ignores the index).

**L6 PROGRESS: ballots_cast BUG fixed (real user-visible harm).** /civic + /civic/record hardcoded
`ballots_cast => 0` (stale "// Phase B") → 420 distinct voters (640 committed envelopes) shown zero
ballots on their record. Now counted from ballot_envelopes by user_id; ballots carries NO user_id
(verified) so it proves THAT you voted, never HOW — the PI-2 rail. ballots_cast:2 rendered for a real
2-envelope voter; MyProfileTabsTest 5/90 green; pin deferred to L1's fixture fix (not fighting them for
the file). 11/13 gap-specs in; batch plan pending.

**TINKER on the dev box (fleet tip):** `docker exec -u www-data -e HOME=/tmp fcd_app php artisan tinker
--execute='…'` — psysh can't write /var/www/.config/psysh as www-data. Renders controllers/props fast,
no browser.

### W4 tick 8 — ⚑ THE ATLAS IS LIVE & GREEN (split reversed) + phantom-tables fleet warning

**LANE 4 — ATLAS DONE, WALKED, GREEN.** `8d03a19` (System/Atlas.vue 896-line mockup port +
AtlasController + surface + registry href + /atlas route + 8 pins; 7 files, 1741 ins) + `69401f5`
(ATLAS_DESIGN.md corrections, docs). WALKED: /atlas → 200; 9 domain cards; living map 25 places from
real PostGIS centroids (Niue, San Marino); Earth reach 2.06% off real legitimacy_snapshots; economy/people
Planned per Q4; every unmeasured figure an em-dash (never 0); zero console errors. Green:
AtlasGaugeNeverLeverTest 4/4 · AtlasPageTest 4/4 (303 asserts) · coverageDrift green.
**HEADLINE: the Atlas is TRUTHFUL with NO rollup table** — suppression rail (null→'—', never 0) renders
an unmeasured world as gaps, not zeros. So world_stats no longer blocks the SCREEN or CAPABILITY (it only
turns gaps into numbers).

**⚑ ATLAS SPLIT REVERSED (desk).** Tick-5 ruled a page(L6)/backend(L4) split; lane 4 built the FULL page
GREEN + WALKED before it propagated, and lane 6 correctly hadn't started (waiting for props). Rebuild for
org-chart tidiness = waste. **Lane 4 owns the Atlas end-to-end.** Lane 6 stood down on the page; offered an
OPTIONAL a11y review pass (findings-to-L4, no rebuild). No work lost. RUBRIC delta: atlas screen ABSENT→built,
Atlas capability → working (badge L4W4).

**⚑ PHANTOM-TABLES FLEET WARNING (broadcast to L6/L13/L2).** Lane 4 found THREE phantom tables in its own
APPROVED design by reading information_schema, not the doc — v3 mockups carry FIXTURE table names ≠ real
schema: candidates → **candidacies** (endorsements.candidate_id points there) · boards_of_governors →
**boards** (seats in board_seats) · **personas DOES NOT EXIST** (dev-tool concept, no table). Shape traps:
jurisdictions stores a PostGIS **centroid** POINT (not lat/lng); federation_peers has **no geometry** and no
label/base_url/role/is_self (real peer lifecycle = discovered…departed, mockup vocab was fiction);
legislature_members holds elected AND term_ended → naive count over-reports current seats **~2.5×**. RULE:
verify every table+column against information_schema before wiring a mockup port.

**⚑ routes/web.php has ≥2 LIVE EDITORS** (lane 4 caught + preserved lane 15's 2 unstaged route lines via
partial-stage). Reminded the mockup-porting lanes: edit routes LAST, stage ONLY your hunk or use the CAS commit.

**ACCEPTED:** GuardsSyntheticData must NOT gate the world:stats rollup refresh (it stops synthetic MINTING;
a rollup recomputes a real public aggregate, mints nothing — gating denies ops the live gauge; rail
untouched). PUNCH ITEM: SnapshotLegitimacyJob has no CLI twin (parity gap; Reach owner). SLOT: world_stats
OPEN for L4 (take when the computation's ready); L3 agenda + L13 secondary-trading behind.

### W4 tick 9 — L2 all 5 done (+ a real class-isolation bug) + 2 questions ruled

**LANE 2 four-way — all 5 L2W4 items done, file-list re-audited (adopted the deletion-blind fix same-turn):**
 • ⑤ 56f137a MatrixCarveoutEmitter — fixture drift (aJurisdiction picked a SEATED jurisdiction on the
   seeded box → isSeated drove the wrong branch); legislatures-active exclusion added, 6/6 green.
 • ②③ 1c6b6d9 — handshake 500→409 (typed CrossClassPeeringException + PeerController catch). ⚑ REAL
   SECURITY BUG caught: handshake validate() never whitelisted instance_class/game_mode → signed on the
   wire but STRIPPED before the class check → null→production (a DEMO peer leaks into a PRODUCTION mesh; a
   demo receiver falsely refuses a real demo peer). Class-isolation breach; both whitelisted; +coordinating-
   node adoption on /adopt (demo-gated, migrated-gated, never self). 26 federation green.
 • ④ 32360c0 — 4 operator docs reconciled to /operator/federation (bare /federation = citizen since d69aff0).
 • ① e702a43 (+pixel 41d5239) — system/amendments (bounds checker client-side, authoritative check SERVER-
   side) + system/term-sync (inline-SVG lockstep timeline). SystemClocksAmendmentsTest pinned.

**Q1 RULED — RUBRIC RE-BADGE (5 screens partial→built):** operator/setup · operator/dns · system/setup
were ALREADY built pre-wave (stale gap-data, same class as lane 6's stale finds); system/amendments +
system/term-sync built this wave. All 5 → built, badge L2W4. To fold in the next batch regen.

**Q2 RULED — PATH-B PRE-AUTH LINKING = DEFER.** operator/setup's only true remainder is a pre-auth
/operator/link endpoint the backend deliberately lacks — a TRUST SURFACE. Ruling: leave the honest
"operator-session-required" card (green, working); a new pre-auth endpoint gets deliberate security review,
not a speculative build. Queued as a DEFERRED operator-design open-question (post-alpha). Not blocking green.

**SUITE GATE IN FLIGHT:** lane 2 (fleet suite steward) is running the FULL suite as its gate; result pending
— a key wave signal, awaiting. ORIGIN note: fleet shares ONE clone, so desk pushes carry every lane's
local-main commits (last push 0/0) — lane 2's 5 commits are already on origin though L2 never pushed.

**RUBRIC deltas to fold at next regen (accumulating):** atlas absent→built (L4) · L2's 5 screens →built ·
L1 ①④⑤ done · L3 ③⑧⑤ done + ⑨ already-landed + ⑦ 9→4 · L15 ① done · L6 ④⑤⑥ done + ballots_cast fix ·
2 debt rows already RESOLVED (done last regen) · new open-Q: Path-B pre-auth linking (deferred).

### W4 tick 10 — L13 ①③ done (12 economy partials) + slot granted for ② + F-IND-021 mint blessed

**LANE 13 four-way — ① (12 economy partials) + ③ (founding-stake) DONE.** 85 tests / 232,678 assertions
green, 0 reds; all read-only prop-fill over EXISTING tables (verified vs information_schema). Commits:
treasury 258d611(*) · units/wallet/home d087450 · org-settings economy af2b7e0 · exchange 4c6d45d
(market KPIs + real trade tape + issued-equity register; order_book stays honest-false) · agreements
pair 068580c (unified org-contract+resident-agreement register + F-IND-020 redline overlay; accept-amendment
voids both sigs → re-sign) · ③ founding-stake 07a8315 (STOCK org founder owns 100% VIA_FOUNDING; non-stock
none). (*) treasury's 4 files = the tick-4 258d611 mislabel (swept under lane 3's msg; correct+green; no
rewrite). L13 has since collapsed stage+commit into one call.

**⚑ SLOT GRANTED → L13 (ready-first) for ② share_offers.** One additive table (organization_id,
seller_holder_type/id, units, price_per_unit, currency_id, status[open|filled|cancelled], filled-only
buyer + money_transfer_id + settled_at, timestamps + softDeletes; money leg account-scoped in
market_transactions, ownership leg named per Ruling B). Fills the exchange shares floor. L13 was READY
(draft done) while L4 still builds its world_stats computation → ready-first. QUEUE: **L13 (now) → L4
(world_stats) → L3 (agenda, held).** L4 told.

**⚑ F-IND-021 "Share Trade" MINT — BLESSED (117→118).** A holder trading their OWN shares is an R-01
INDIVIDUAL act, not an org-agent act (folding into F-ORG-008 = wrong actor). Operator approved the
secondary-trading capability; minting the form to implement it is in-scope engineering. Pin discipline
(same commit): FormRegistry + AuditChainSmokeTest count + CLAUDE.md count line. L13 is the ONLY mint this
wave → count clean.

**⚑ F-IND-012 SHARED-FORM HEADS-UP (recorded):** OrgRegistryService::register() now opens a founding stake
for STOCK orgs only (additive; org/registration/institution suites green, 66). Any lane registering a stock
org via F-IND-012 now gets a founder stake — surfaced so no fixture is surprised.

### W4 tick 11 — ⚑ Type B race-fix SEATING HALF done + L3 5 landings + ⚑ SECRECY-CRITICAL wave-gate red

**LANE 1 — ① per-clump SEATING half DONE (0f02655, CAS commit, file-list guard: exactly 7 files, 171+/48-,
zero foreign sweep):** racePlan 'panels' mode (one at-large race per panel; flagged+no-grouping still blocks;
unflagged interim pooled — per-child is the follow-up) · createRaces one race per panel (type_b_panel_id,
Σ=grouping.seats_total) · RaceFootprint panel LEFT JOIN (COALESCE ldj/ltbpj/er.jurisdiction_id) ·
ElectionRace.type_b_panel_id fillable · B1 docblock corrected to per-panel · pins TypeBDistrictMapperApplyTest
(blocked→panels + 2 per-clump races) + ElectionStageTest. FULL suite 933 pass / 3 skip / 1 FAIL (302,310
asserts); lane 1's surface 100% green. Shape FULLY HANDED to lane 3 (columns + electorate join + seat mapping
+ Niue 5×2 fixture recipe). Niue clear HELD per the desk gate. NEXT: per-child ungrouped follow-up + an
adversarial verification pass on the seating shape before lane 3 finishes its protected build.

**⚑⚑ SECRECY-CRITICAL WAVE-GATE RED (routed to L6, desk-VERIFIED against the code):** the 1 suite failure is
BallotSecrecyTest "ballot box is the only writer" — HomeController.php:127 + MyRecordController.php:171 do
`DB::table('ballot_envelopes')` directly (lane 6's tick-7 ballots_cast fix). Only app/Domain/Ballots/BallotBox.php
may touch the secrecy tables; a raw controller read breaks the Art. II cryptographic-separation rail. FIX (NOT
a revert — the 420-voters-see-zero bug was real): route the count through a BallotBox method
(BallotBox::envelopeCountFor($userId)); the count proves THAT you voted, never HOW, so it's secrecy-safe iff
BallotBox is the only toucher. Lane 6 given it as priority #1. THIS is the single red blocking a green suite.
LESSON reinforced: a real bug fix that reads a hardened table directly still trips the rail — run the FULL
suite (the constitutional guards are why).

**LANE 3 — 5 landings (③⑤⑥⑧⑨ all green, CAS-clean):** ⑥ RankedBallot liveAggregate (4a118e74 + 4a552614;
cache-based, MIGRATION-FREE, no-decrypt source-scan pinned, daily 00:15) · ⑤ complete (dept-reporting +
ranked backend) · ⑨ complete (production 4057b3c + test-hardening eba53854: PublicProceedingsGuestTest 32 +
OversightGalleryDisclosureTest 22 — guest sees in-progress removal + named members). ① UNBLOCKED (lane 1's
0f02655 shape answers all 3 hold-questions; fixtures: L1 owns seating pins, L3 owns CountingStage/SeatingStage).
⚑ NIUE GATE TRIGGER: when L3's per-clump counting lands + pins, L3 tells the desk → desk authorizes the Niue
clear (L1 holds until then). NEXT: ② exit walk → ⑦ 4 pollers → ① counting.

### W4 tick 12 — Atlas COMPLETE + L3 keystone exit-walk done + L6 5 commits (2 more bugs) + 3 rulings

**⚑ SLOT DRAINED, no collision:** world_stats (3b9ff99, mig 230000) + share_offers (1cdf535, mig 240000)
both landed clean — different timestamps/tables. Slot FREE; only L3 agenda (④, held) remains. CAS commits
made the shared-index race moot for both.

**LANE 4 — ATLAS FULLY LIVE (world_stats rollup 7c6a6af + migration 3b9ff99).** Walked E2E: world:stats
--refresh wrote the row (economy+people ABSENT not zeroed), audit_log world.stats_rollup ref CI-6, /atlas
renders 2,443 residents · 26 jur · 290 seats/257 filled · 28 open elections · 19-of-26 gauged, gaps as
em-dashes. 00:50 schedule AFTER reach's 00:40 (sums that night's snapshots). Traps caught: seatsFilled 257
not 654 (elected+term_ended), orgs 2 not 3 (soft-delete). W4③ ElectionStageTest 10/10 vs L1's per-clump.
NEXT: W4② provisioning mirror + parity pin (courtTiers+extraRooms only) → W4④.

**LANE 3 — KEYSTONE EXIT WALK DONE (8bcb3ee, green)** — committee-hearing e2e in one room = the acceptance
gate. (Plus its tick-11 five landings.) NEXT: ⑦ 4 pollers → ① counting.

**LANE 6 — 5 commits (through adea521), 2 MORE real bugs:** ③ 32a288a SECURITY (a removed member could
re-invite themselves back into a private room — spaceDestination/InviteController/leave() gaps; 2 pins, 11
green) · ④ 6d9d4a0 the first journey rendered raw class-id "people" (become-a-resident absent from
journeys.js) + flipped L/M "planned" labels live · ⑤ ⚑ adea521 THE MENU GATED TIGHTER THAN THE CONSTITUTION
— advocate-console/constitutional-challenge/departments unreachable by exactly whom they exist for, no route
gate (the menu WAS the gate): residents couldn't file an Art. IV §5 challenge, nobody could join the bar.
One-directional pin (client-more tolerated=refusal-is-an-answer; client-fewer fails). 14-agent gap analysis:
every screen YES-WITH-HONEST-EMPTY.

**RULINGS:**
1. **PER-CHILD (ungrouped) = (a) joint-sequence AFTER L3's per-clump count pins + secrecy clears** (NOT defer,
   NOT (c)). WHY not defer: San Marino is a DEMO jurisdiction, UNGROUPED (9×3=27), so the interim pooled race
   shows the WRONG Type B shape in the operator's playtest walk — wrong shapes don't ship. Joint L1/3/4 step,
   each rewrites its OWN bicameral fixtures to seed real children. Reassess with operator if it threatens the gate.
2. **BILL-CONVERSATION: L6 builds bill.html** (Vue page + controller read) on the CONSTITUTIONAL path (motions
   kind='amendment' + chamber vote via POST /sessions/{session}/motions — the mockup's per-party accept/reject
   is an Art. V §3 ConstitutionalViolation RedlineService already throws); L3 keeps the live-room half; comments
   ride 8 existing bill-bound social_subforums (no schema). HallsController subforum_id one-liner = L3's file,
   L6 coordinates direct.
3. **CIVIC/JOIN PRESENCE: honest-empty/reword, never fake** — verified NO presence source (useLiveRoom is a
   poll/freshness layer). "{n} people in this room" reads as presence but is roster size; relabel or empty.

**COMMIT-LAW AMENDMENT (L4, accepted):** file-list guard NARROWS the race; temp-index CAS ELIMINATES it. ⚑
GOTCHA to carry: after `update-ref`, the real .git/index still points at OLD HEAD → reads as DELETING your new
files → a peer committing right then would delete them → follow every plumbing commit with `git reset -q HEAD
-- <paths>` to re-sync. CAS-for-every-commit is the fleet standard. (L4 self-reported 2 sweeps this tick — both
repaired forward, no work lost, no rewrite.)

**PIXEL DEBT de-mystified (L6):** the pane isn't broken — it must be VISIBLE to composite frames. The
operator-present walk WILL produce pixels. Closes the unknown-cause debt.

**RUBRIC deltas (still batching):** atlas built (L4) · L6 tour/ballots/journey/menu · L3 exit-walk + 5 · L2 5
screens · L13 12 economy + share_offers · new open-Qs: Path-B (deferred), civic/join-presence (honest-empty).

### W4 tick 13 — ⚑ per-clump REVERTED→re-landed (c500a1f) + index-clear FLEET LAW + steward gate (2 reds left)

**⚑⚑ THE HEADLINE RACE FIX SILENTLY REVERTED FOR ~2 TICKS — now re-landed.** Lane 1's per-clump commit
0f02655 got undone: its 7 files stayed staged in the SHARED index (from earlier attempts), and lane 4's
later commit (pre-876fb8e) took the whole index and re-committed a STALE snapshot over 0f02655, wiping the
change (git diff HEAD = 171+/48- missing). The shared WORKING TREE always had it (live testing + lane 3's
in-tree build unaffected), but a fresh checkout of HEAD had old pooled racePlan. **RE-LANDED as `c500a1f`**
(CAS, content-identical, 23 pins green) + lane 1 CLEARED its 7 files from the shared index. **SUPERSEDE
0f02655 → c500a1f everywhere.**

**⚑ INDEX-CLEAR LAW (broadcast fleet-wide, all 8 lanes):** after ANY commit, `git reset -- <your files>` so
index==HEAD. A file left staged in the shared index gets re-committed (stale) by a peer's later commit — this
is lane 4's post-update-ref gotcha, now CONFIRMED as the root cause of the headline reversion. CAS protects a
commit's CREATION, not against a later peer committing your still-staged files. Pair CAS with the reset.

**⚑ STEWARD GATE RESULT (lane 2, full suite): 1276 passed · 3 failed · 3 skipped · 307,391 asserts · ~24min.**
Re-ran the flaky-looking 2 in isolation → real reds, not contention. Triage (git-confirmed ownership):
 1. BallotSecrecyTest:240 — **CLEARED after the run**: lane 6 routed the count through new
    BallotBox::participationCountFor(User) (**3d1abbb**, 9/9 green + ballots_cast:2 preserved). Pin-tighten
    (grep→any-access, closes the BallotEnvelope::query() bypass) APPROVED as a hardening. Docblock now states
    the line: a per-voter COUNT is safe (content-free half); pairing voter+race+time reading ballots is not.
 2. GeodataRepairPlaneTest:283/:397 → **LANE 1** (4214721/54a3671; export-manifest file_put_contents). Routed.
 3. SupportLifecycleTest:62 → **LANE 6** (2c23d49; own-the-world isolation, totalCount 2 vs 3 — scope the
    count, same class as the fixture reds). Routed.
 3 skipped = pre-existing env-gate skips (pdo_pgsql/live-pg), not new.
**PLAN: L1 fixes Geodata + L6 fixes SupportLifecycle → lane 2 (steward) RE-RUNS the full suite = the wave's
green signal.** After BallotSecrecy's clear + per-clump re-land, those 2 are the only reds between us and green.

**LANDINGS:** L3 keystone exit-walk (8bcb3ee, 80 asserts) — 6 of 9 (②③⑤⑥⑧⑨); ① now builds on c500a1f
(shape re-landed), Niue clear still desk-gated on L3's count pin. L13 share_offers slot DONE (1cdf535),
slot released — only L3 agenda (held) remains. L2W4 DONE + holding. L2 verified its federation surfaces on
the REAL federation_peers shape (heeded the phantom-table warning). L6 batch plan banked (L6W4_SCREEN_GAP_PLAN.md,
9d427a9; needs-schema later: constituent_requests + room read-position — neither blocks batches 1-6).

### W4 tick 14 — L4 W4② (real defect fixed) + ⚑ Q4a OPERATOR RULING NEEDED + L3 ① unblocked

**LANE 4 W4② (331271b) — CORRECTED SHAPE.** The order said "courtTiers + extraRooms only"; the SCHEMA
FORBIDS both — judiciaries_jurisdiction_live_uq = exactly ONE live court per jurisdiction (hierarchy is
across the tree via parent_judiciary_id); social_spaces_jur_type_unique = one public space per type (3 legal
types). So those two are computed-but-unmaterialised. Lane 4 did NOT work around it. **Instead it fixed the
REAL defect:** provisioning hardcoded min_judges 5 for EVERY court (19 live judiciaries, nations included),
and InstitutionScaleService::judgeCount() had ZERO callers — the 5/7/9 bumps were pinned + ruled-keep (§9-Q2)
but DEAD CODE. Now tierSql()+judgeCountSql() size the bench from tier; Art. IV §1 floor 5 UNBREAKABLE by
construction (GREATEST(5,…)). LEDGER: **W4② = min_judges-from-tier + parity pin**, not courtTiers/extraRooms.

**⚑ PARITY PIN — InstitutionProvisionMirrorParityTest** (3 tests / 366 asserts): SQL==PHP across all 60
(population,children) pairs + every band boundary + free short-circuit + non-vacuity (≥4 tiers, benches
5/7/9 reachable). This is the L3↔L4 formula CONTRACT enforcer in CI — L3 moves a curve/clamp without pinging
L4 → red. The desk-pinned contract is now machine-enforced.

**⚑⚑ Q4a — OPERATOR RULING NEEDED (surfaced; recommendation = (a)).** Q4a ruled provisioning MAY materialise
court tiers + local civic rooms — UNIMPLEMENTABLE as written (schema forbids, above). Options:
 (a) **RECOMMENDED** — reframe courtTiers as a jurisdiction's tree-DEPTH (which parent_judiciary_id + reality
     already model), extra civic rooms as group-type spaces or a future room model. Cost: doc amendment, NO
     schema, NO slot, nothing built moves.
 (b) Weaken the two uniqueness constraints. Cost: a slot + trades two real safety rails (no duplicate courts /
     squares) for a materialisation convenience. NOT recommended.
NOT blocking the wave (the min_judges fix is the meaningful formula-wiring; it's done + green). Held for his word.

**LANE 3 checkpoint:** 6 items + ⑦ half (②③⑤⑥⑧⑨ + ⑦ 2/4 byte-parity 1775f4bb). ① UNBLOCKED — c500a1f
stable in HEAD; L3 proceeds with counting (CountingStage/SeatingStage are L3's to flip to per-CLUMP; the
per-CHILD case is the later joint step). ⑦ Results+VacancyCountback DEFERRED honestly (useLiveRoom stops on
'adjourned', won't auto-restart on a recount status flip — tracked room-wiring). Niue clear gated on L3's
count pin. **test_a_large_type_b_is_one_lawful_at_large_race goes RED when per-CHILD lands (asserts the pooled
shape) → L1+L4 co-update in ONE commit at the joint step.**

**DEBT ROW (5 pre-existing suite reds) claimed by L4 — 4 already FIXED this wave** (LegalCompliance +
MyProfileTabs = L1 a32bbc8; MatrixCarveout = L2 56f137a; ModerationFlip = L3 6fe2c77); only SupportLifecycleTest
remains (→ L6). **Lane 2 = the SINGLE authoritative suite steward** — runs the wave-gate suite once, after the
2 reds + L3's ① counting land. No competing full-suite runs.

### W4 tick 15 — ⚑⚑ CONVERGENCE: Niue AUTHORIZED · ① fully done · 2 gate reds cleared · L5+L13 DONE · quiet-window gate

**⚑⚑ NIUE CLEAR AUTHORIZED (the wave's headline unblock).** Gate condition MET: lane 3 pinned per-clump
COUNTING 6f85d322 (electorateFor scopes to panel members — pair→200, single→100, never parent 10k) + SEATING
8ec50402 (chamber-wide type_b seat_no) against lane 1's c500a1f shape; purity-pin comment red fixed 826859af.
Lane 1 given explicit GO → apply('active'): 5 panels (2 pairs+3 singles), type_b_seats 14→10, flag cleared,
racePlan emits 5 per-clump races × 2 = 10. FIRST Type B chamber cleared in the CORRECT per-clump shape.

**LANE 1 — adversarial pass (5f2293b) found 2 defects the 933-pin suite MISSED:** (1) MED drift + R-A bypass
(racePlan read grouping before the flag; stale grouping emitted drift races + bypassed R-A) → per-clump fires
ONLY when grouping is CURRENT (not re-flagged AND seats_total==type_b_seats); protects the mass pass. (2) HIGH
by-election electorate leak (scheduleSpecial dropped type_b_panel_id → whole parent voted) → copy the panel id.
Both pinned. + GeodataRepairPlaneTest FIXED 06d9545 (manifest → self-owned subdir; was root-owned dir).

**⚑ BOTH WAVE-GATE REDS CLEARED:** BallotSecrecy (L6 3d1abbb, BallotBox::participationCountFor) + Geodata
(L1 06d9545). Suite effectively green; L13 already got a clean **1336 passed / 0 failed / 3 skipped** re-run.

**LANE 3 ① FULLY DONE** (count+seat+reword). ⚑ Dedicated per-clump pin file APPROVED (don't convert
CountingStageTest — its unflagged fixture pins the interim POOLED behavior, real until per-child; converts to
per-CHILD at the joint step). LESSON: editing a PROTECTED core trips source-SCAN pins in OTHER files — grep the
forbidden-term set after. SEVEN of nine (①②③⑤⑥⑧⑨); ⑦ deferred, ④→L13.

**LANE 6** — SupportLifecycleTest CLEARED (own-the-world → measures the CLAIM). civic/join honest (roster≠presence:
"N members"+citation, dropped pill--live). Tour 47→60 (13 restored). ⚑ PIN-TIGHTEN RULING RE-RECORDED: my
"any access" was WRONG — the rail is single-WRITER + no-RAW-access, NOT single-reader (reads distributed by
design, 8+ legit sites). L6's narrower fix (a2751ae — catch DB::query()->from('x') too) is CORRECT. Tour
build-team surfaces (/coverage etc.) stay OUT of the player walk (separate act if the operator wants).

**LANE 5 DONE** — ① MULTI-TRACK VIDEO PLAYER live at /videos (2fea981, app-ported from the operator's Coalition
player, NOT from scratch; browser-proven, 77-lang track-swap). ④ board · ⑤ fr+pt · ② flows extraction · ③ zh-Hans.
Adversarial review fe5ad51 (reactive-src audio fix + poster \bunit + comment). ⚑ fr/pt monolithic chrome WITHHELD
(NLLB confidently-wrong nav; rail = refusal-is-the-answer; mechanism shipped, raw output not). Handoffs → L6
(/videos nav + Learn-flyout video seam) + L11.

**LANE 13 CLOSED** — ①②③ + ⚑ ADVERSARIAL REVIEW (ultracode, 5 reviewers, e8c2884) fixed 8 REAL defects: HIGH
(same-buyer wallet-negative, same-seller phantom equity, full-sale dangling membership, no stock re-check) + MED
(cancel race, legal-name leak). Clean re-run 1336/0. F-IND-021 mint 118. CHECK(balance>=0) flagged for a later slot.

**⚑ AUDIT-LOCK CONTENTION (L4 diagnosed):** AuditService::APPEND_LOCK_KEY (0x4155444954) = ONE global advisory
lock on every audited filing → concurrent test runs serialise → timeouts → SPURIOUS reds (+ instance_settings
singleton cross-talk). ADOPTED: **lane 2 holds a "SUITE TOKEN"** — the single authoritative gate run in a QUIET
window, other lanes' test runs FROZEN. Triage order = own-the-world → audit-lock → real defect. Per-lane
LIVE_PG_DATABASE = the real fix, post-alpha. ⚑ FLEET RULE: never assert an ABSOLUTE count over a shared-world
table — assert the DELTA or scope to fixture rows (3 confirmations: SupportLifecycle, MyProfileTabs, L1 W2).

**LANE 4 growth dial (f9161f5) — committee half:** GovernanceStage files real F-LEG-009 → supermajority vote →
adoption writes the committee row (created_by_vote_id PROVES no sim-minted act); formula = pre-governance CEILING
not mandate. Bicameral chambers decline-with-reason until Type B seats (Niue = first). W4④ PARTIAL: department
half + pump wiring remain. Debt row CLOSED 5/5.

**PENDING:** Q4a — with the operator (framing: courtTiers=tree-depth, extra-rooms=future room model; singular
entities stay singular). Quiet-window gate to trigger once Niue lands + freeze confirmed (+ desk runs the chown).

### W4 tick 16 — ✅ NIUE CLEARED (live-verified) + authoritative quiet-window gate TRIGGERED

**✅ NIUE CLEARED — the wave's headline unblock, DONE.** Lane 1 executed TypeBDistrictMapper::apply('active')
on Niue (dev, id 0c935e4b) and LIVE-VERIFIED through the real production racePlan path (not SQL-green):
BEFORE type_b_seats=14/needs_districting=true/BLOCKED → AFTER type_b_seats=10/cleared/active grouping ce288f7a
= 5 panels (2 pairs member_count=2 + 3 singles member_count=1, all 7 villages), seats_total=10. racePlan NOW:
type_b = PANELS, 5 per-clump races, Σ=10 (no drift), blocked=false, generable_kinds=[type_a, type_b] — BOTH
chambers elect (was type_a only), each panel scoped to its own members. Stale-grouping guard satisfied,
undercount=false. FIRST Type B chamber cleared in the CORRECT per-clump shape. Lane 4's W4③ sim-schedule pin
keys on the cleared flag (CLK-01 auto-mints the 5 races on schedule).

**DEV-BOX HYGIENE FIXED (desk):** `chown -R www-data:www-data storage .phpunit.result.cache` on fcd_app —
they were root-owned (a root-run artisan), which caused the GeodataRepair write failure + the .phpunit
permission-warning family. Now www-data:www-data (verified). Clears the whole warning family before the gate.

**⚑ AUTHORITATIVE QUIET-WINDOW GATE TRIGGERED.** Adopted lane 4's audit-lock finding: lane 2 (steward) holds
the SUITE TOKEN and is running the ONE authoritative full-suite pass in a QUIET window — test runs FROZEN on
the building lanes (4/6/15); holding lanes (1/3/5/13) aren't testing. Both wave-gate reds cleared (BallotSecrecy
3d1abbb + Geodata 06d9545); lane 13 already saw clean 1336/0. Lane 2 applies triage (own-the-world → audit-lock
→ real defect) + isolates any red. Its tally = the wave's DEFINITIVE green signal. AWAITING (~25 min).

**⚑ PER-CHILD (joint step) HELD until AFTER the gate** — lock the current green first, THEN convene the joint
lane-1/3/4 per-child fixture rewrite (San Marino's ungrouped correctness) so a late fixture change can't muddy
the green signal. Lane 1 leads racePlan/createRaces per-child + co-updates test_a_large_type_b_is_one_lawful_
at_large_race with lane 4. ② game-box mass pass = operator coordination, after per-child.

### W4 tick 16b — L4 growth-dial DEPARTMENT half built + pump wiring greenlit (post-gate)

**L4 W4④ DEPARTMENT HALF (74603d4, 7/7).** Growth dial now runs the full §5 chain through real forms:
F-LEG-014 (supermajority) delegates a forming executive → F-LEG-016 (majority) charters departments toward
D(P), mandatory kinds first. Proof-by-column: department.charter_law_id + committee.created_by_vote_id are
adoption-path-only → their presence proves the vote engine (not the sim) wrote the row. Defers-with-reason on
every gate; bicameral Type B deferral kept. Growth-dial engine complete end-to-end.

**⚑ PUMP WIRING — GREENLIT, SEQUENCED AFTER THE GATE.** GovernanceStage runs on demand; registering it into
SimPumpCommand makes maturation autonomous ("living world scales with playerbase"). Held out of the gate window
(adds audited filings + a live-behavior change). Post-gate: L4 registers it, then VERIFIES sim behavior (demo
populate run matures chambers correctly, no runaway, no audit-lock contention in the pump path) → W4④ built +
autonomous. W4④ ledger: committee half f9161f5 + dept half 74603d4 BUILT; pump wiring = post-gate; Q4a = operator.

### W4 — ⚑ POST-GATE QUEUE (execute AFTER lane 2's authoritative green lands + freeze lifts)

All three file audited acts or change fixtures, so they wait until the gate locks the current green and the
test-freeze lifts:

1. **PER-CHILD (ungrouped) joint step — L1 leads, L1/L3/L4.** racePlan/createRaces per-child for UNFLAGGED
   chambers (currently interim pooled); each lane rewrites its OWN bicameral fixtures to seed real children;
   L1+L4 co-update test_a_large_type_b_is_one_lawful_at_large_race (it asserts the pooled shape → goes red →
   per-child). Ruled (a) — needed for San Marino (demo, ungrouped) to show the correct shape in the walk.
2. **PUMP WIRING — L4.** Register GovernanceStage into SimPumpCommand (4 edits pre-designed read-only: insert
   'governance' phase after 'seating'; mintWorklist branch; SimWorkerJob dispatch; verify). Makes the growth
   dial autonomous. Then verify sim behavior (matures correctly, no runaway, no audit-lock contention). → W4④
   built + autonomous.
3. **NIUE STALE-ELECTION VOID — L3 voids, L1 confirms.** 2 approval_open elections (019f9f58, 019f9f76) carry
   the PRE-FIX pooled shape (type_b_panel_id=NULL, seats=14 pre-ladder); createRaces idempotent so the fix
   won't reshape them → would certify a wrong 14-seat pooled chamber. RULED (a): void → CLK-01 timer re-mints
   per-clump. Niue-only proving-churn data; NOT a mass-pass blocker (the 9,708 were BLOCKED, never minted a
   pooled race). 3 certified Niue elections = history, untouched.

Also pending: **Q4a** (operator — courthouse/rooms framing: courtTiers=tree-depth, extra-rooms=future room model).
Still building (freeze-compatible, no test runs): **L6 civic partials** (today/my-profile/advocate-registration/
identity-verification/relocation → social/groups → bill.html on the constitutional path). After all: RUBRIC REGEN
+ redeploy + the wave-close review package for the operator.

**FLEET DONE:** L2 · L5 · L13 · L15 (4 fully closed). **Held-at-last-item:** L1 (Niue done, per-child+void
queued), L3 (① done, void+④ queued), L4 (growth dial done, pump queued). **Building:** L6. Awaiting the gate tally.
