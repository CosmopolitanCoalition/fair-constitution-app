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
144=144, down() proven) and reported clear ~25 min after dispatch → slot GRANTED to lane 15
(land at stage ③ without further wait, announce hash, report clear for lane 2).

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

**B4 hull validation delivered (db93712, B4_HULL_ISLAND_VALIDATION.md) → OPERATOR ITEM:** the
invited check ("check me on it") found: the engine already seats islands via the shipped
`closestApproachSq` nearest-host attachment (islands-ride-whole, Art. II §8); enclave logic is
sound iff both clips hold; antimeridian guarding is real and already precedented; and
hull-CONTACT adjacency for GROUPING reduces to a buffered proximity graph (hulls of separated
islands never touch — the buffer distance d becomes a free parameter controlling everything).
QUESTION QUEUED: for Type B grouping adjacency only, centroid nearest-approach (A, lane +
desk recommend) vs literal buffered hull-contact (B). The hull KEEPS its true home either
way: Type A line-splitting of over-ceiling archipelago constituents (both clips +
antimeridian guard). DESK STEER: contiguous path proceeds at full speed and may un-flag;
ISLAND-path chambers held at the adjacency seam until the operator rules.
