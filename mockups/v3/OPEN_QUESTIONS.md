# CGA Mockups v2 — OPEN_QUESTIONS

Divergences between the v2 design (per `App Docs/CGA_Mockups_v2_Build_Instructions.md`)
and the **as-built code** as it actually exists on `origin/main` (`b0f7cdc`, surveyed
2026-06-21). Per §13/§15 of the build instructions: *the code wins; this file logs every
gap.* Each entry says what the v2 mockup does about it.

Legend: [Planned] design-ahead (Planned, badged) · [v2-new] net-new v2 layer over built code · [Open] open decision.

---

## A. The economy forms are reserved, not registered (Planned)

The build instructions cite these as if they exist. They **do not** appear in
`app/Domain/Forms/FormRegistry.php` (108 forms registered: 103 Template + F-ELB-008 +
F-SOC-001/002/003/004). They are reserved IDs for unbuilt phases:

| ID(s) | Title (roadmap) | Phase | Registry status |
|---|---|---|---|
| F-LEG-037 / 038 / 039 / 040 | Revenue · Budget · Borrowing · Currency | L | **absent** |
| F-IND-018 … 023 | tax-filing, work, assistance, marketplace listing/order, transfer | M | **absent** (only F-IND-001…017 exist) |
| F-TRE-001 … 004 | Board-of-Governors treasury ops · UBI run | L / M | **absent** (no F-TRE prefix) |
| F-ORG-008 | Org market participation | M | **absent** (only F-ORG-001…007 exist) |

**The mockups do:** every economy surface is badged **Planned** (via the shared
`S.plannedBanner()`) with the abstract-unit note. Form chips for these IDs render with a
"Planned" flag, never as live forms. The "form name first, ID second" rule still applies so
a reserved ID can't be mistaken for a live one.

> **Superseded (operator, 2026-07-01):** the earlier "never-federated wallet rail" is gone.
> Everything syncs between nodes — economic data included; privacy is a READER property
> ("private — like a ballot, only you can read it"), never a topology claim. All economy
> copy was reframed accordingly in the simplification pass.

Forms the Live Civic Room **does** use are all live and verified: F-SPK-001…009,
F-CHR-001…004, F-LEG-002…007, F-JDG-001…003, F-ORG-003/004, F-SOC-001/002, F-IND-014.

## B. The live/social layer is net-new over the built Matrix room ([v2-new])

`SessionConsole.vue`, `CommitteeDetail.vue`, `CaseDetail.vue`, `BoardElections.vue` render
**static Inertia snapshots**. They have **no** speaker queue, no hands-raised mechanic, no
per-speaker timer, no live presence, no chat overlay — there is no WebSocket/SSE layer in
those components. The Matrix room (`MatrixCommons.vue` + `app/Services/Matrix/*`) provides
the live chat / voice / testimony, but it is a *separate* surface from the governance form.

**v2 does:** the Live Civic Room *is* the fusion the build asks for — it adds the
presence/queue/timer/recognition layer on top of the built Matrix room and the built vote
form. **Open:** whether speaker-queue recognition becomes its own constitutional form
(next free id would be F-SPK-010+) or stays an ambient chair affordance is a design
decision for the build team. The mockup treats recognition as a plain chair act mapping to
the existing F-SPK family, not a new form.

## C. Live tallies / real-time updates need an events layer ([v2-new])

`VoteTally.vue` renders server snapshots; nothing streams. The v2 "live" feel (a vote tally
that moves, "X now holds the floor", a running speaking clock) is **simulated in-page** in
the mockup. The production build will need a broadcast/events layer (Laravel Echo / Reverb
or polling) that does not exist yet. Logged so the dev project scopes it.

## D. The three informal-group meeting types are UI, not schema ([v2-new] / [Open])

`casual` / `facilitated` / `formal-with-internal-vote` are **named** in
`K3-social-layer-matrix.md` but are **not** a database enum or a form. Informal groups
ground on `social_spaces(is_private, owner_org_id)` + `social_memberships` (local-only) + a
Matrix room. **v2 does:** renders the meeting type as a UI choice on the group's Live Civic
Room (the `group` variant), with the explicit boundary "this is your group's own decision,
not a law." **Open:** whether a "formal" group vote is persisted as structured data, and
where, is unspecified.

## E. Public-square removal UI is structurally absent — and v2 keeps it that way ([v2-new], confirmed)

`PublicSquare.vue` / `Halls.vue` render **zero** moderation/removal controls. Viewpoint
removal has **no code path**: `CarveoutEmitterService` maps only `judicial_order→m1` and
`rights_protection→m2`; `FlipDecision`'s basis enum is closed (`judicial_attested |
operator_relay | system_antispam | unavailable_no_judge | client_side | refused`); the
`matrix_server_acls` CHECK constraint allows only `m1 | m4`. The `flag` reaction is an M-4
behavioral anti-spam signal, never a viewpoint takedown.

**v2 does:** the Live Civic Room's moderation note explains the four carve-outs and that
there is **no "remove for content" control** — matching the built reality structurally. The
build instructions flag F-SOC-003 as an "open decision" on whether to introduce a removal
*UI*; v2 sides with the as-built code (no such UI on civic pages; carve-outs are
engine-driven via derived judicial roles).

## F. Reach / legitimacy denominator is unsettled (Planned, Phase I)

`LegitimacyService::reachRatio() = verified_residents / population_estimate` is specified but
the denominator (WorldPop vs `CivicPopulation`) and the k-anon floor value are flagged TBD
and not implemented. **v2 does:** the legitimacy gauge (Stage 5) is badged Planned and
renders the hard rails verbatim — display-only, never a governance input (CI-1), no
per-person score, no individual leaderboard, measured from the envelope not the ballot.

## G. Achievement surfacing is [POLICY], unspecified (Proposed)

`AchievementCatalog` is a code registry (no table); how achievements appear on profiles or
any leaderboard is unspecified. **v2 does:** any achievement surface is badged **Proposed**
and decorative — never tied to a right, a vote, or office.

## H. Stored `is_seated` snapshot vs. live seated-check ([v2-new])

`matrix_rooms.is_seated` is a stored snapshot; `ModerationFlipService::isSeated` queries
`Legislature.status = ACTIVE` **live**. The live query is canonical. **v2 does:** the Live
Civic Room renders the live-seated semantics (halls exist iff seated; authority flips at the
seated boundary). The mockup does not depend on the snapshot.

## I. Cross-government / cross-instance surfaces are backend-only ([v2-new])

Federation, union formation, border settlement, and the cross-instance public square have
built services but **no UI** (or only the v1 `federation.html` mock). **v2 does:** the
`two-governments` journey (Stage 5) and a cross-government Live Civic Room (`townhall`
variant, trade-talk framing) mock the *player-facing* surface, clearly noting the UI is
design-ahead of the shipped backend.

## J. Assistance-request form code is unassigned ([Planned])

`assistance_requests` (Phase M, default private) has no form ID assigned in the roadmap.
**v2 does:** the mutual-aid surface (Stage 4) renders it without a form chip and badges it
Planned.

## K. Education (Phase K-2) — now mocked in v2 ([v2-new])

The Learn area / `education_*` schema, progress grading, and form IDs are explored but not
specified. **Superseded:** at the operator's direction v2 now mocks the education layer —
`learn/` (learn-home, lesson, guides) plus the SOP panels embedded into journeys. The
`education_*` tables, lesson/track/check schema, progress persistence, and any form IDs are
still **unregistered** — the design is ahead of the code, and the lessons key off
`fixtures-learn.js`, not a registry.

## L. The video + translation pipeline models external scripts not in the repo ([Open])

The multi-track player is a faithful mockup of the operator's WordPress player
(`functions/video_player.php`, the `[subject_video_player]` shortcode) and the translation
toolchain (`Import/Post/Check Translations.ps1`, `Convert ASS↔SRT/VTT.ps1`) — both live
**outside the repo** (OneDrive + the `appsvr` plugin tree), so the mockup is built from the
documented contract (one silent master MP4 + `{Subject}-{Language}.{m4a,vtt}`, the link toggle,
drift-correction, prefs), not the source. **For the wiring pass:** fold the actual scripts in to
lock the exact filename conventions, the drift threshold, and the ASS↔VTT step. The language set
is real (`scripts/etl/languages.py`, 115 codes); the per-modality status states, the review-queue
quorum, the AI engine choice (Haiku tier-1 + NLLB tail), and the contributor-gating ("verify only
the language you read") are **design proposals** — no `translation_*` schema or form IDs exist yet.
Likewise the **ticket/support** surface (`support/`) has no `tickets`/`reports` schema or routing
service; the category→destination routing (abuse/illegal → the moderation & legal plane, never the
support queue) is the design intent to wire.

---

### Naming/path notes (non-blocking)
- The build doc's example variant ("Harbor Air Quality Act", "Port Meridian") is replaced
  with the **existing v1 demo world** for continuity: the New York County **Clean Air Act**
  (`bill-2031-07`), the **Environment & Infrastructure** committee, **Bluefin Logistics**'
  co-determination board, the Manhattan approval race. No new geography invented.
- The v2 demo bar **extends** the frozen scenario vocabulary additively (`liveSession`,
  `marketplace`, `ubiRun`, `groupForming`, `tradeTalk`) in `demo-state.js` — extend, never
  rename. v1 pages ignore the new flags.
