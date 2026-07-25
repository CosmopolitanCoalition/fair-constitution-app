# K2 — Education Engine Plan (the graded half)

**Owner:** Lane 15 (Phase K-2) · **Deliverable:** D-16d · **Status:** design, 2026-07-25
**Permission to write code:** granted for the four §8 changes in `K2_ACHIEVEMENT_LIBRARY.md`
(operator, 2026-07-25). This document specifies; schema needs beyond the approved migration use
this lane's ordinal block **000060+**, and audit summaries append **last**.

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
| Retake | allowed and unlimited-by-design — education must never become a gate (Art. I) |
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

## 5. Minting F-EDU-001 / F-EDU-002

**No `F-EDU-*` form exists anywhere in executable code** — zero matches across `app/`, `config/`,
`tests/`, `database/`. The only hits are prose in `docs/plans`. The registry is a **code artifact by
explicit constitutional design**, so minting is mechanical:

1. **Declare** in `FormRegistry::FORMS` (name + role codes).
2. **Implement** a handler class against the 5-method `FormHandler` contract.
3. **Register** in `FormRegistry::HANDLERS`.
4. **Bump the tripwire:** `tests/Feature/AuditChainSmokeTest.php` asserts exactly 108 canonical
   forms. Change the assertion **and the method name** `test_registry_holds_exactly_108_canonical_forms`
   — that file already carries a stale docblock at line 26 claiming "104" while asserting 108, and
   leaving the name stale would repeat the same failure.
5. **Fix** the `FormRegistry` docblock arithmetic.

No migration, no seeder, no enum, no i18n file.

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
5. **`EducationNoGateTest`** — no education state reaches `RoleService::derive()` or
   `ConstitutionalValidator`; **completing a lesson never becomes a precondition for filing
   anything.** Art. I rights are absolute and unconditioned.

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
4. **Do F-EDU forms need to exist at all? The charter contradicts itself.** The roadmap names
   `F-EDU-001/002` as Phase-K key forms **and** states *"AchievementService (zero new F-forms —
   visibility is a personal setting)."* Completing a lesson is not obviously a *constitutional act*
   — it confers nothing, changes no one's standing, and by Art. I can never gate anything. The
   §5 minting recipe is written so it is ready either way, but **my recommendation is to mint
   zero forms** and record education as ordinary application state, reserving the catalog for acts
   that carry constitutional weight. Minting touches a constitutionally-reviewed file and trips the
   108-count tripwire; doing that for a quiz submission should be a deliberate yes, not a default.
5. ~~The title decision~~ — **APPROVED 2026-07-25, no longer open.** Store an i18n key rather than a
   denormalized English string in an immutable federating ledger
   (`K2_ACHIEVEMENT_LIBRARY.md` §8.2). @lane-05 owns the translating; ~127 achievement titles.
6. **`F-SOC-001` roles are `[]` in code** (public square open to visitors, per your 2026-06-27
   correction, pinned by `PublicSquareTest`) **while the roles/forms chart still says "Filed by
   R-03."** Any education generated from the chart would teach a residency gate on the public
   square that the code does not enforce. This is the largest chart-vs-code semantic divergence and
   it sits inside Phase K. The chart cells wait on D-03/D-05 — flagging so no lesson inherits it.
