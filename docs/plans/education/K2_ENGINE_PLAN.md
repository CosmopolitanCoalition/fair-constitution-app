# K2 — Education Engine Plan (the graded half)

**Owner:** Lane 15 (Phase K-2) · **Deliverable:** D-16d · **Status:** design, 2026-07-25;
**revised 2026-07-28 for operator ruling 6** (`V3_SYNTHESIS_PLAN.md` §10 — training completion
IS a constitutional form; supersedes this plan's own zero-forms recommendation, §5/§10.4 below);
**revised 2026-07-29 for the PRE-SEATING ruling** (operator, 2026-07-28 — the training gate for
elected seats sits at pre-seating; §10.7 closed, gate design in §5.2, pin final in §6.5).
**Permission to write code:** granted for the four §8 changes in `K2_ACHIEVEMENT_LIBRARY.md`
(operator, 2026-07-25; all four SHIPPED 2026-07-26 — catalog `7b9ec17`, award_key migration
`e86c6ac`, arcs live `64d79a9`, `AchievementService` `3f42e87`). This document specifies; schema
needs beyond the approved migration use this lane's ordinal block **000060+**, and audit
summaries append **last**.

---

## 1. Posture: extend `JourneyService`, never replace it

The engine already exists under another name. `JourneyService`'s own docblock states the shape:

> *Two stores, two postures:*
> *— `journey_progress` — **NODE-LOCAL mutable lesson state** (steps ticked off).*
> *— `achievements` — the append-only earned ledger, sealed to the audit chain.*

That is the education architecture, already built and already correct. The graded half **adds a
third store in the first posture** and reuses the second unchanged:

| Store | Posture | Federates? | Why |
|---|---|---|---|
| `journey_progress` (built) | node-local, mutable | **No** — no `source_server_id` | tour state |
| `education_progress` (new) | node-local, mutable | **No** — inherits the same shape | **charter rail: progress never federates** |
| `achievements` (built) | append-only, chain-sealed | **Yes** — `source_server_id` + `audit_seq` | achievements travel |

**The non-federation of progress is not a new rail to build — it is a schema shape to copy.** The
built asymmetry (achievements carry `source_server_id`, `journey_progress` does not) is exactly the
charter's posture already expressed in DDL.

**Reinforcement, free:** `PublicRecordService::FORBIDDEN_SUBJECT_TYPES` already carries the inline
note *"(Phase K-2 adds `education_progress`.)"* Adding it makes publication structurally impossible
at the single `publish()` chokepoint, rather than merely forbidden by convention.

---

## 2. ⚠ THE CRITICAL RAIL — `correct_keys` must never reach the audit chain

**This is the most important finding in the plan and it is not in any prior document.**

`ConstitutionalEngine::SENSITIVE_KEYS` strips sensitive payload keys from **every** chain entry —
including **rejected** filings. It currently covers passwords/tokens, ballot `rankings` and
`choice` (Art. II §2 / §6), and the CSAM hash/locator family.

**It contains no key that would protect a graded-answer key.** There is no `correct_keys`, no
`answers`, no `answer_key`.

The failure mode is specific and permanent:

> An F-EDU grading form is filed. Validation rejects it (wrong shape, double-submit, whatever).
> The engine seals the **rejected** filing into the hash chain *with its payload*. If that payload
> carried the answer key, **the answer key is now in an append-only, immutable, DB-trigger-protected
> table that federates.** There is no delete. There is no rotation. The chain cannot be edited —
> that is the whole point of it.

**Therefore the design is defensive in two independent layers:**

1. **Architectural (primary): answer keys never enter a form payload at all.** Grading is a
   server-side comparison against a catalog the client never sees. The submitted payload carries
   *the learner's answer*, never the correct one. A grading result is a boolean plus a score, not
   a diff.
2. **Belt-and-suspenders (required anyway): add `correct_keys`, `answer_key`, `answers` to
   `SENSITIVE_KEYS`** before any F-EDU form is registered. It is a two-line change to a
   constitutionally-reviewed file, and it costs nothing to do first.

⚠ **The mockup does the wrong thing and must not be copied.** `mockups/v3/learn/lesson.html`
**ships the answer index to the browser.** That is a mockup-only shortcut. Pinned as a test (§6).

---

## 3. `earned_at` — SETTLED: the full timestamp stays (reversal, 2026-07-25)

**An earlier ruling coarsened `earned_at` to `awarded_on DATE` pre-launch. The operator has
reversed it.** His words: *"Keep the timestamp. What does it matter? The timestamp when you got an
achievement, no one cares."*

**The ledger keeps `earned_at timestamptz(0)`, `audit_seq` and `source_server_id` exactly as built.
No migration. Closed — not to be re-raised.**

### What was traded, recorded because it was a real trade

The consequence was put to him in plain terms and accepted: a federated achievement carries its
**exact minute**, so a remote instance can see *when* a pseudonymous person acted. The elections
engine deliberately destroys that same signal on the ballot side — `ballots.cast_bucket` is
hour-truncated (`BallotBox.php:172`, docblock: *"wall-clock insert time is a linking channel"*), the
ballot PK is explicitly random v4 so ordering cannot leak insert time, and `BallotSecrecyTest` pins
both. The achievement ledger will not match that posture.

This is written down so the asymmetry is **a decision on the record rather than an oversight
someone rediscovers later**. It is his call to make, he made it knowingly, and it is now settled.

**Unaffected and still approved:** the i18n-key change (`K2_ACHIEVEMENT_LIBRARY.md` §8.2) — that one
is about the *title* being unrewritable in an append-only table, not about timing.

---

## 3.5 Attempt policy and throttling — the other half of the answer-key rail

**"`correct_keys` never reaches the client" is worth nothing against an unthrottled submit
endpoint.** A 3-option multiple choice is brute-forceable in three requests; the answer key stays
secret while the answer becomes free. Any grading design that stops at serialization has secured
the wrong half.

The house pattern already exists — `routes/web.php` uses `throttle:10,1` on the sensitive path and
`throttle:30,1` elsewhere — so this is a decision, not an engineering problem. The plan states:

| Question | Position |
|---|---|
| Attempt cap | rate-limited per module, house `throttle:` middleware |
| Are wrong answers persisted? | **No.** Storing a per-person error history is a composite score waiting to be computed (PI-6). Persist the *pass*, not the trail. |
| Retake | allowed and unlimited-by-design — unlimited retakes are what keep any lawful training gate (ruling 3, §6.5) passable by effort alone; a capped exam would turn a gate into a filter, and Art. I rights (§6.5) are never gated at all |
| Second achievement on retake? | **Impossible by construction** — the ledger is idempotent on `(user_id, award_key)` |
| Latch shape | copy `markStep`'s one-way latch (`completed_at === null && count(done) === count(steps)`) rather than inventing a second |

The tension resolves cleanly: **throttle the guessing, never gate the learner.** A rate limit
protects the item bank; it does not condition a right.

## 4. Schema (additive, specified — not created here)

```
education_tracks    id, key, title, unit_ref, status, ordering, …
education_modules   id, track_id, key, title, surface_id?, ordering, …
education_questions id, module_id, key, prompt, choices jsonb, correct_keys jsonb,  ← SERVER ONLY
                    weight, …
education_progress  id, user_id, module_id, state, score, completed_at, …           ← NEVER federates
```

Rules carried on the shape itself:
- `education_progress` mirrors `journey_progress` exactly: **no `source_server_id`, no
  `deleted_at`**, unique on `(user_id, module_id)`, FK to `users` ON DELETE CASCADE.
- `education_questions.correct_keys` is **never** selected into any Inertia prop, API response,
  serializer, or form payload. Enforced by pin (§6), not by discipline.
- Weighting follows the corpus's own rule (`K2_CURRICULUM.md` §6): `max(minutes/5, 3)`, with a
  value weighting replacing the flat floor.

The `AchievementCatalog` stays a **code registry, not a table** (charter), and gains the award-key
generalisation specified in `K2_ACHIEVEMENT_LIBRARY.md` §5.1.

---

## 5. Minting the F-EDU family — **REQUIRED (operator ruling 6, 2026-07-28)**

**RULED, not recommended.** The operator's words (via the reconciliation ledger,
`V3_SYNTHESIS_PLAN.md` §10 item 6): **training completion IS a constitutional form.** Completions
tie to achievements and to the one-time civic stipend for finishing a training, so they file
through the engine. This REVERSES the zero-forms recommendation this plan previously carried in
§10 item 4 — that item is closed (see §10).

**Timing:** the family is **proposed here and registered with the engine build**, not before.
No `FormRegistry` change happens in the wave that revises this plan (Wave 1 is the Learn payload;
the engine build is a later wave). `F-EDU-*` still has zero matches in executable code — that
remains true until the build, and this section is the design it builds from.

### 5.0 The proposed family (proposal — IDs settle at registration, desk review)

| ID | Name | Filed by | What it records | What it never records |
|---|---|---|---|---|
| **F-EDU-001** | Training Completion | the learner (R-01 — every user; see §5.0.2) | module/track key, pass, completion time | **answers — the learner's or the correct ones.** The §2 rail stands unchanged. |
| **F-EDU-002** | Training Material Publication | the authoring body's agent (Δ4 bridge, §7) | publication/revision of a training module, its public-domain dedication ref | question banks' `correct_keys` (server-side catalog only) |

Two forms, not one, because the two constitutional acts are different: one is a person's act
(completing), one is the content plane's act (publishing under Art. III §5 public domain). The
roadmap already named `F-EDU-001/002` as Phase-K key forms; these assignments give the pair
substance. If the desk or operator wants publication left as ordinary application state, F-EDU-002
drops and 001 ships alone — that is a registration-day decision, flagged not assumed.

#### 5.0.1 The stipend hook (design only — build coordinates with lane 13's economy write path)

The ruling ties completion to **the one-time civic stipend for finishing a training**. Design:

- **The payout fires inside the F-EDU-001 handler, through the engine — never a side API.**
  Lane 13's settled posture (doors, never shortcuts: economy writes file through the engine only)
  applies to this money the same as all other money.
- **"One-time" is enforced by the achievement ledger, which is already once-only by
  construction.** The handler awards through `AchievementService` (idempotent on
  `(user_id, award_key)` against an append-only, trigger-guarded table); **the stipend pays if
  and only if the achievement row was NEWLY minted.** No second idempotency mechanism, no
  parallel bookkeeping — the ledger IS the once-only proof. A retake never pays twice for the
  same reason it never decorates twice.
- **Amount and funding follow the `StipendService` posture** (PROTECTED, F-TRE-004): amount from
  an amendable constitutional setting (proposed `training_stipend_amount`, jurisdiction-scoped
  like `civic_stipend_floor`), funding source resolving the same way (`minted` default), the
  transaction citing its F-EDU-001 chain entry.
- **CI-1 is not violated and the plan says why rather than leaving it to be asked:** CI-1 bars
  *governance* advantage — a role, a vote, a seat, a capability. The stipend is money. The
  operator ruled the tie deliberately; the non-interference pin (§6.4) keeps asserting that no
  education state reaches `RoleService::derive()` or vote counting, stipend included.
- **PI-6 is not violated:** paying on a completion is a per-event act on the LIST of earnings,
  not a composite score. Nothing sums.

#### 5.0.2 All trainings are open to every user — a rule of the family, not a UI choice

The ruling's second half: **any user may take ALL trainings, whether or not they apply to a role
the user holds or seeks.** Consequences carried into the schema and forms:

- `education_modules` carries **no role gate** — there is no `required_role`, no eligibility
  column, and F-EDU-001's role list is the universal `R-01`.
- The UI may *recommend* trainings by relevance to a role; it may never *hide or refuse* one.
- `ConstitutionalValidator` never sees a "may this person take this training" question, because
  the question is unaskable by construction.

### 5.1 Registration mechanics (unchanged, counts corrected 2026-07-28)

The registry is a **code artifact by explicit constitutional design**, so minting is mechanical:

1. **Declare** in `FormRegistry::FORMS` (name + role codes).
2. **Implement** a handler class against the 5-method `FormHandler` contract.
3. **Register** in `FormRegistry::HANDLERS`.
4. **Bump the tripwire deliberately:** `tests/Feature/AuditChainSmokeTest.php` now asserts
   **111** canonical forms (`assertCount(111, ...)`, line ~131 — F-IND-022/023/024 landed
   2026-07-26 after this plan's first draft said 108). Raising the pinned count on registration
   day is the documented, deliberate act CLAUDE.md describes — change the assertion **and any
   count-bearing method name/docblock** in the same edit; that file has drifted before.
5. **Fix** the `FormRegistry` docblock arithmetic.
6. **FIRST, before any of 1–5: land the §2 belt-and-suspenders** — add `correct_keys`,
   `answer_key`, `answers` to `ConstitutionalEngine::SENSITIVE_KEYS` (verified still absent,
   2026-07-28). Ruling 6 makes F-EDU registration certain, which makes this two-line change
   mandatory rather than precautionary. It is the first commit of the engine build.

The completion filing needs no new chain machinery — but F-EDU-001 acceptance now touches three
stores in one transaction (education_progress latch, achievement append, stipend transaction),
which is exactly the `markStep` → `AchievementService` shape already shipped, plus lane 13's
transaction posture for the money leg.

### 5.2 The training gate — RULED: PRE-SEATING (operator, 2026-07-28). The design it builds from.

*"Pre-seating is best. You need to do the training to do the job."* Design only — the build
rides the F-EDU engine build, after the Wave 2 pages. Three gate points, one reading rule.

**THE READING RULE, before the gate points, because everything hangs on it: the gate reads
ONLY the F-EDU-001 completion record — never the achievement ledger, never
`education_progress`.** Three separate rails force this single choice:

1. **CI-1 stays absolute.** Achievements are decorative-never-power with no exception — if the
   gate read the achievement ledger, an achievement would confer a seat, and
   `AchievementNonInterferenceTest` would have to carve its first hole. It never does: the
   LEGAL fact is the filed constitutional act; the achievement remains a decoration minted
   alongside it.
2. **Federation.** `education_progress` is node-local and never federates (§6.2 pin) — a
   winner whose completion lives on another node would be un-seatable if the gate read the
   latch. A filed F-EDU-001 is a public constitutional act that travels under Full Faith &
   Credit; the gate works mesh-wide by construction.
3. **Answer-key secrecy is untouched** — the completion record carries module key + pass +
   time (§5.0), so a gate reading it can never leak what §2 protects.

**Gate point 1 — elected seats (pre-seating latch in the certification pipeline).**
`CertificationService` (WI-B5) today seats winners at F-ELB-004 certification. With the gate:

- Certification still certifies the race in one act, but each winner lands as
  `pending_training` rather than serving **unless a qualifying F-EDU-001 completion for the
  role's track already exists** — the common case for re-elected members, who seat instantly.
- A `pending_training` member is **not serving**: quorum and supermajority denominators
  (majority / two-thirds **of ALL SERVING members**, Art. II §2 / Art. VII) exclude the seat
  until the latch flips. The chamber operates meanwhile — same arithmetic as any vacancy.
- **The latch flips on F-EDU-001 acceptance** for the matching track: handler side-effect,
  same transaction shape as §5.1's three-store commit. Seating timestamps and inherited-term
  arithmetic (CLK-10) are unchanged — the term was fixed at certification; training delay
  never extends a term.
- **The window:** the winner has `seat_training_window_days` (proposed amendable setting,
  default 30, jurisdiction-scoped — a PROPOSAL for registration-day review, not settled law)
  from certification. Expiry or an explicit decline **declares the seat vacant through the
  existing `VacancyService::declare()`** (distinct reason code, e.g.
  `training_not_completed`), which queues the **universal countback** — the replacement
  winner passes through the same latch. No new counting machinery, no new vacancy machinery;
  the CLK-04 backstop and special-election window inherit unchanged.

**Gate point 2 — the countback handoff.** Nothing new to design, and that is the point:
"exactly like any declined seat" is literal. Countback replacements are re-run winners
(eligibility re-checked at certification, §A.4.5) and enter the same `pending_training` latch.
A chain of decliners resolves exactly as a chain of vacancies always has — ending, if
everyone declines, at the auto-scheduled special election discretion can never suppress.

**Gate point 3 — appointed/registered roles (application-time gate).** As originally ruled:
the gate sits on FILING the application/registration form (advocate registration F-IND-015
and kin; the registration-day build enumerates the exact form list in
`config/cga/education.php`). Mechanics:

- A single `TrainingGateService` (name proposed) answers one question — *does a qualifying
  F-EDU-001 completion exist for this user and this role's track?* — and the affected form
  handlers consult it in their validate step. One rule, one home; no handler grows its own
  copy.
- The role→track map lives in `config/cga/education.php` (§8.1) beside the module catalog.
- The refusal is a teaching surface, not a wall: it names the missing training and links the
  learn flow (§5.0.2 — every training is open to every user, so the door it points to is
  never itself gated).

**What the gate NEVER touches, stated as law:** candidacy registration (F-IND-011), voting
(F-IND-007/008), residency (F-IND-003/006), speech (F-SOC-001/002). `ConstitutionalValidator`
keeps refusing eligibility conditions on candidacy — the pre-seating latch lives entirely
post-certification, so the validator and the gate never meet.

**Deliberately deferred, flagged not designed:** module-version currency (does an F-EDU-002
revision of a training re-open the gate for sitting completions?). Default posture for the
build: any completion of the track qualifies; version-currency is a future refinement that
would need its own ruling if wanted.

**Facts that constrain the library and the education content** (corrections to assumptions in
circulation):
- The real prefixes are 12: `F-IND 17 · F-CAN 3 · F-ORG 7 · F-ELB 7 · F-LEG 36 · F-SPK 9 ·
  F-CHR 4 · F-EXE 5 · F-BOG 2 · F-JDG 10 · F-ADV 4 · F-SOC 4` = **108**.
  **There is no `F-JUD`, `F-SYS` or `F-JUR` family** — the judicial prefix is **`F-JDG`**.
- **Catalog against the 108 canonical IDs** — not the 106 handler count, and not the chart's 103.
  The chart is missing exactly `F-ELB-008` and `F-SOC-001..004` — the newest, most demo-visible
  surfaces.
- **Always call `FormRegistry::canonical()`.** The 8 `CATALOG_DRIFT` entries are themselves valid
  canonical IDs of **different** forms (`F-LEG-022/023/024/030/034`, `F-IND-004/005/013`), so
  naive string-matching would silently teach the wrong form.
- **`F-LEG-020` / `F-LEG-021` are catalog forms, not filable actions** — consent is cast via
  `F-LEG-004`. Their unregistered state is deliberate and test-pinned. An achievement keyed to
  "file F-LEG-020" can never fire.
- **The registry carries no Article citation.** Education wanting per-form constitutional grounding
  must join to `config/cga/surfaces.php` citations (104 of 108, in-repo, version-controlled,
  readable at runtime) rather than the `.gitignored` extracted chart.

---

## 6. Test pins (house style, `tests/Constitutional/`)

Docblock opens `CONSTITUTIONAL PIN — <Article> (<rule>)` and closes *"If an edit breaks these
tests, that edit is a constitutional violation — fix the edit, never the test."*

1. **`EducationAnswerKeySecrecyTest`** — the §2 rail, two assertions:
   (a) source-grep that no controller/resource/serializer selects `correct_keys`;
   (b) `SENSITIVE_KEYS` contains `correct_keys`, `answer_key`, `answers`.
2. **`EducationProgressNeverFederatesTest`** — `education_progress` has no `source_server_id`; it
   is in `FORBIDDEN_SUBJECT_TYPES`; the federation export shape omits it entirely.
3. **`AchievementExportShapeTest`** — the federation export ships exactly the five documented
   fields; `audit_seq` and `source_server_id` stay **exporter-local** and never cross. (This pin
   replaces the withdrawn `awarded_on`-is-coarse pin — §3 is settled the other way, but the export
   shape is still worth holding, and it was never the part the operator reversed.)
4. **`AchievementNonInterferenceTest`** — CI-1, in the shape `IK-civic-org-powers-and-record.md:86`
   already specifies (see `K2_ACHIEVEMENT_LIBRARY.md` §7).
5. **`EducationNoGateTest`** — **FINAL SHAPE, RULED 2026-07-28: no gate on BALLOT ACCESS,
   ever.** The elected case is closed (§10 item 7: PRE-SEATING), so the pin's every clause is
   now settled law rather than safe-direction caution:
   - **No education state ever conditions an Art. I absolute right**: voting (F-IND-007/008),
     candidacy for elected office (F-IND-011), residency (F-IND-003/006), speech
     (F-SOC-001/002). Unconditional and permanent — the pre-seating ruling REAFFIRMS this
     (ballot access untouched); it does not soften it.
   - **No education state reaches `RoleService::derive()`** — roles derive from residency and
     acts, never from lessons. The pre-seating latch changes WHEN a winner starts serving; it
     never makes a role derive from education.
   - **The gate reads only F-EDU-001 completion records** — never the achievement ledger
     (CI-1 stays absolute: no achievement ever confers a seat), never `education_progress`
     (node-local; §5.2's reading rule). Assert both directions.
   - Training gates on **appointed/registered** roles (advocate registration F-IND-015 and
     kin) are LAWFUL and gate at application filing; the **elected** gate is LAWFUL and sits
     **post-certification only** (`pending_training` latch, §5.2). The pin asserts no gate
     code touches candidacy registration or any pre-certification election step.

Register all five in `FuturePhasePlaceholdersTest` — the documented mechanism for pre-registering
an unbuilt phase's pins (*"The constitutional suite IS the roadmap"*).

⚠ **There is no CI in this repo** (no `.github`). "CI-gated" claims imply standing CI up first.

---

## 7. The Δ4 authorship bridge (from @lane-14)

Lane 14 hands this lane `authored_by_organization_id` / `authored_by_user_id` /
`ip_register_entry_id` on education content. Per **operator ruling 3 (2026-07-25)** the authoring
org is **Cosmopolitan Coalition of United Earth**, the operating/authoring **child** of the
**Cosmopolitan Party Foundation** (the only incorporated entity). Content is dedicated
public-domain under **Art. III §5**.

⚠ **Landmine, inherited from lane 14's own reading:** `CgcIpPublicDomainTest` pins
`CgcIpRegisterService`'s public surface to **exactly `['dedicate']`** and source-scans `app_path()`
against a two-file allowlist. Anything specified here **extends that contract, never bypasses it** —
and education seeders must go **through the service**, never naming the table or the model.

---

## 8. Single source of truth

`config/cga/journeys.php`'s own header warns that **config, the JS registry, and the mockup
fixtures must be kept in sync by hand**. `resources/js/registry/surfaces.js` mirrors *nothing* from
the PHP registry, nothing tests either direction, and **three live drifts already exist**
(`LEARN_BY_MODULE` keys `federation`, `support`, `social` have no surface of that module —
`K2_CURRICULUM.md` §8.1).

**The education catalog must not add a fourth hand-synced copy.** Recommendation: server-side is
canonical (`config/cga/*` + `surfaces.php`), the client mirror is **generated**, and a cross-check
test uses `SurfaceMeta::ids()` — which already exists and is **unused**.

### 8.1 A separate `config/cga/education.php` — and why it cannot go in `journeys.php`

Two concrete breakages make this non-optional:

1. **`tests/Feature/JourneysTest.php:36` is `test_the_index_renders_all_thirteen_journeys`.**
   Adding education keys to `config/cga/journeys.php` breaks it immediately.
2. `JourneyService::liveJourneyOrFail()` is the *only* validity gate and reads
   `config("cga.journeys.{$id}")`; `JourneysController::index` lists everything in that config.
   Education entries would render as journeys in the players' journeys index.

**This registry is also the first deliberate break from the mirror pattern.** Every other
`config/cga/*` registry has a client mirror, and `journeys.php`'s header instructs keeping them in
sync. Education cannot: `correct_keys` is server-only. So the split must be **explicit in both file
headers** — `config/cga/education.php` (server-only: `correct_keys`, explain-on-fail) and
`resources/js/registry/education.js` (display: titles, prompts, options, video ids) — with a note
saying the non-mirroring is deliberate, or the next maintainer will "fix" it.

Audit the three leak paths in the same pass, none of which anyone has checked for a config-shaped
leak: `HandleInertiaRequests::share()`, `SurfaceMeta::for()`'s whole-record pass-through, and any
controller that would hand the education config to Inertia.

---

## 9. Deferred to Phase I (@lane-03)

The **reach gauge** and **jurisdiction-only leaderboards** consume lane 03's tier curve and the
legitimacy denominator (`LegitimacyService::reachRatio()` = verified residents ÷
`jurisdictions.population`). This lane **consumes those numbers and derives none**. Corresponding
catalog entries are marked tier **D** in `K2_ACHIEVEMENT_LIBRARY.md` §4.7.

---

## 9.5 `education:demo` cannot tear itself down — settle it in the plan, not at build

The house pattern for every standing demo is an **idempotent `--fresh`** seeder. Education cannot
have one for achievements: `achievements_immutable` blocks `UPDATE`/`DELETE` and `achievements_no_truncate`
blocks `TRUNCATE`. **Any demo that mints an achievement creates a permanent, federating row on the
operator's box** — and per the standing screenshot rule a demo is mandatory, so this will be hit.

Three workable answers; the plan recommends the first:

1. **`education:demo` seeds tracks, modules, questions and `education_progress` — never an achievement.**
   `education_progress` is mutable and node-local, so `--fresh` works normally, and the demo can
   still screenshot a completed lesson. Achievements stay for real acts.
2. Mint demo achievements only for `*@demo.invalid` users and accept them as permanent — acceptable only
   on a `scale_demo` instance, which by CI-2 has federation off.
3. A `deleted_at` soft-delete path — **rejected**: the partial-unique index is
   `WHERE deleted_at IS NULL`, so this quietly re-permits re-minting and erodes the append-only
   guarantee the trigger exists to make absolute.

## 10. Open items for @operator

1. **PI-5 does not exist.** PI-1/2/3/4/6 are all present in the corpus; **PI-5 has zero occurrences
   anywhere** in `docs/`, `mockups/`, `tests/`, `app/`, `resources/`, `config/`. The definitive
   source — `achievements-legitimacy.md` — **is not in this repo**; it lives on the uncommitted
   `explore/achievements` branch. Either PI-5 is a numbering gap, or it is a privacy rail nobody
   can currently read. **Recovering that branch is cheap and worth doing.**
2. **The self-reported-tick decision** is resolved per-entry rather than globally
   (`K2_ACHIEVEMENT_LIBRARY.md` §5.2): verified entries name their source, journey arcs stay
   labelled walkthroughs. Confirm that split is what you want.
3. **A charter divergence, flagged not silently fixed:** the roadmap says achievements get no
   `audit_log` module ("it reuses records"), but `JourneyService:186` already appends
   `module: 'journeys'`. Recommend keeping `'journeys'` and correcting the charter — @lane-07 owns
   that text.
4. ~~Do F-EDU forms need to exist at all?~~ — **RULED 2026-07-28, REVERSED from this plan's
   recommendation, closed.** The operator: training completion IS a constitutional form —
   completions tie to achievements and the one-time civic stipend, so they file through the
   engine; the F-EDU family gets registered (raising the pinned form count deliberately), quiz
   ANSWERS stay private, and every training is open to every user. §5 now carries the design.
   The zero-forms recommendation was put, considered, and overruled — recorded here so nobody
   re-argues it.

7. ~~⚠ THE ART. I QUESTION~~ — **RULED 2026-07-28 (~9:50 PM): PRE-SEATING. Closed.**
   The operator: *"pre-seating is best. You need to do the training to do the job."* Option (b)
   as recommended — recorded with the mechanics so nobody re-derives them:
   - **Ballot access untouched.** Anyone registers candidacy and appears on the ballot; Art. I
     stays absolute; the validator's refusal of candidacy eligibility conditions stands.
   - **A WINNER completes the role's training BEFORE taking the seat.** The gate touches
     office-holding, never candidacy.
   - **A winner who declines or fails to complete falls to the existing universal countback,
     exactly like any declined seat** — and a declined seat already IS the
     `VacancyService::declare()` → `runCountback()` → `certifyCountback` path. No new counting
     machinery.
   - **Appointed/registered roles gate at application/registration**, as originally ruled.
   - `EducationNoGateTest` **narrows from "no gate anywhere" to "no gate on BALLOT ACCESS,
     ever"** — the pre-seating gate is lawful. §6.5 carries the narrowed pin; §5.2 carries the
     gate design. The three options and the recommendation that preceded this ruling are
     preserved in git history (`e0fba8a`); the table is gone from the living doc because the
     question is closed.
5. ~~The title decision~~ — **APPROVED 2026-07-25, no longer open.** Store an i18n key rather than a
   denormalized English string in an immutable federating ledger
   (`K2_ACHIEVEMENT_LIBRARY.md` §8.2). @lane-05 owns the translating; ~127 achievement titles.
6. **`F-SOC-001` roles are `[]` in code** (public square open to visitors, per your 2026-06-27
   correction, pinned by `PublicSquareTest`) **while the roles/forms chart still says "Filed by
   R-03."** Any education generated from the chart would teach a residency gate on the public
   square that the code does not enforce. This is the largest chart-vs-code semantic divergence and
   it sits inside Phase K. The chart cells wait on D-03/D-05 — flagging so no lesson inherits it.
