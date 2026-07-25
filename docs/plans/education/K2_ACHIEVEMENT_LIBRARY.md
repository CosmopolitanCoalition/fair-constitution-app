# K2 — Achievement Library

**Owner:** Lane 15 (Phase K-2) · **Deliverable:** D-16b · **Status:** design, 2026-07-25
**Build slot:** post-launch per the standing work order. No code, no migrations in this pass.

---

## 0. The premise: this is greenfield *twice over* — and it is neither

Two catalogs already exist and nobody has joined them:

1. **The live ledger** — `config/cga/journeys.php`, 13 arcs (10 live / 3 planned). Every earned
   medal today is one completed journey; `achievements.journey_id varchar(64)` holds the arc key.
2. **A 27-code design catalog** — `ACH-*` codes in `mockups/{v2,v3}/assets/js/fixtures-v2.js` and
   `mockups/{v2,v3}/social/achievements.html`. `mockups/v3/manifest.json:489` says plainly:
   *"Proposed; no AchievementCatalog in code."*

So the library is an act of **reconciliation**, not invention: the 27 design codes and the 13 live
arcs are two different namespaces pointed at one append-only table.

---

## 1. The central finding: one query verifies almost everything

Every constitutional act in this app flows through `ConstitutionalEngine::file()`, which appends a
hash-chained row carrying **who did it and which form it was**:

```
audit_log( actor_user_id uuid, module varchar(32), event varchar(64),
           ref varchar(24), jurisdiction_id uuid, rejected bool, seq bigint, … )
```

`ref` is the F-form id (`BallotBox` writes `ref:'F-IND-007'`). There are indexes on
`actor_user_id`, `module` and `ref`, and the table is append-only by DB trigger
(`audit_log_block_mutation()`).

> **The verification spine:**
> `SELECT 1 FROM audit_log WHERE actor_user_id = :user AND ref = :form AND rejected = false`
>
> That single indexed, already-sealed query proves a person performed a given constitutional act.
> **No new table, no new column, no new event, no new clock is required to make the achievement
> library verified rather than self-reported.**

Three disciplines ride on it:

- **`rejected = false` is mandatory.** Rejections are first-class chain entries (with their
  citation). A medal must never count one — and the *absence* of a medal must never be readable
  as evidence of a rejected filing.
- **Never read `ballots`.** For electoral participation the source is `ballot_envelopes` (§3).
- **Never read the payload.** Presence of the row is the fact; the payload is content.

---

## 2. Two planes, not one catalog

This distinction is load-bearing and the design codes already encode it:

| Plane | Codes | Store | Rails |
|---|---|---|---|
| **A — Individual medals** | `ACH-IND-*`, `ACH-ORG-*`, and the 13 journey arcs | rows in `achievements` (user-scoped, append-only, federating) | **PI-4** default private, opt-in to show · **PI-1** never ranked against another person · **PI-6** never summed into a score |
| **B — Jurisdiction & system milestones** | `ACH-JUR-*`, `ACH-SYS-*` | **not** `achievements` — published through the existing `PublicRecordService::publish()` | **PI-1** jurisdiction scope only · **PI-3** k-anonymous floor · never attributed to a person |

Plane B has **no `user_id`**. That is what makes "leaderboards" lawful at all: a jurisdiction can
appear in a list; a person never can.

**This split is not my invention — it is already ruled.**
`docs/plans/phase-g-continuation/IK-civic-org-powers-and-record.md:163,170` prescribes exactly it:
*"public milestones only → `PublicRecordService (kind=participation)`"*, and the firewall statement
*"the metric's record footprint is a separate immutable table + public milestone rows."* IK:106
further places **org-tier medals on the org profile as display chrome** — *"confers no power — same
CI-1 rail."* So the catalog has three scopes, one store each:

| Scope | Store |
|---|---|
| individual | `achievements` (user-scoped) |
| organization | the **org profile**, as display chrome (IK:106) |
| jurisdiction / system | `PublicRecordService::publish(kind: participation\|certification)` |

> ⚠ **Never overload `user_id` with a jurisdiction or org UUID.** `achievements.user_id` is
> `NOT NULL` with **no foreign key** — deliberately, so append-any-verified federation can land
> medals for identities absent locally. Stuffing a non-person UUID there would federate a
> jurisdiction into a person column with nothing to catch it, and the partial-unique
> `(user_id, award_key)` would silently collide across scopes. There is **no** `jurisdiction_id` or
> `organization_id` column on `achievements`, and this design does not add one.

---

## 3. The electoral rail, stated exactly

`ballot_envelopes` and `ballots` are two physically unlinked tables with **no FK in either
direction**, and the separation is pinned by `tests/Constitutional/BallotSecrecyTest.php`:

- `ballots` may hold **exactly nine columns** (`assertEqualsCanonicalizing`), its only time is the
  **hour-truncated `cast_bucket`**, and a regex rejects any column matching
  `/user|voter|envelope|identity|created_at|updated_at|deleted_at|ip_|session/i`.
- Its PK is explicitly random v4, *because* an ordered UUID "would leak precise insert time and
  defeat `cast_bucket` truncation."
- The only columns shared with envelopes are `['id','race_id','kind','referendum_question_id']` —
  *"a shared SCOPE is not a linking channel; a shared per-ballot identifier would be."*

**Therefore:** the one lawful "you voted" fact is a `ballot_envelopes` row for
`(user_id, race_id | referendum_question_id)`. It is already indexed on `user_id`, so a first-ballot
medal needs **no new index**. `BallotBox` is the sole writer of both tables and a source-grep pin
hunts rogue writers — the achievement engine reads, never writes, and never touches
`payload_encrypted`, `salt`, `ballot_hash`, or `BallotReceipt`.

**PI-2 has a second half people forget:** *no abstention leak.* A medal proves participation; the
lack of one must never be publishable as evidence of non-participation. This forbids public
streaks, "voted in N of M elections" counters, and completion percentages on the individual plane.

> **The render rule, stated because a "first ballot" medal actively sharpens this risk.**
> Envelopes are queryable per race and residency associations are queryable, so *"did not vote"* is
> a trivial set difference. Awarding a participation medal makes that absence legible on a profile.
>
> **An unearned medal must render identically to a medal that does not apply — "not yet earned",
> never "did not vote", and never a greyed-out slot in a known-complete grid.** No test anywhere
> currently asserts non-participation is un-inferable; §7 adds one.

---

## 4. The curated library (~53 entries)

**Generative basis, stated because two were in play.** The GO named the 108 forms + R-01..R-30 +
CLK-01..21 + the WF-* walkthroughs + the journey arcs; my plan said "curated off the surface
registry." These produce different libraries, so: **the surface registry is the INDEX (it already
joins all five of those axes per screen), and the 108 canonical form IDs are the CATALOG the
triggers resolve against.** Neither alone is authoritative — the registry says *where a civic act
lives*, the form ID says *what the act is*.

Three constraints that survive either basis:
- Catalog against the **108 canonical IDs** — not the 106 handler count, and not the chart's 103
  (the chart is missing exactly `F-ELB-008` and `F-SOC-001..004`, the newest and most demo-visible
  surfaces).
- Always resolve through **`FormRegistry::canonical()`** — the 8 `CATALOG_DRIFT` entries are
  themselves valid canonical IDs of *different* forms, so string-matching silently mis-teaches.
- **Do not attempt one medal per WF-\*.** The 71 walkthroughs map to medal *categories*, not medals,
  and several have no surface at all.

Legend — **Tier:** `V` = server-verified from a named source · `T` = tour tick (self-reported,
labelled as such) · `D` = deferred to Phase I (lane 03).
All Plane-A entries are default-private (PI-4). No entry grants anything (CI-1).

### 4.1 Plane A — residency, franchise & the public record (Art. I)

| Code | Medal | Verified source | Tier |
|---|---|---|---|
| `ACH-IND-VERIFIED` | Verified resident | `audit_log.ref='F-IND-006'` / `residency_confirmations` | V |
| `ACH-IND-ASSOCIATED` | Associated in a jurisdiction (R-03) | association sweep record | V |
| `ACH-IND-FIRST-BALLOT` | Cast a first ballot | **`ballot_envelopes`** (never `ballots`) | V |
| `ACH-IND-REFERENDUM` | Voted in a referendum | `ballot_envelopes` where `kind='referendum'` | V |
| `ACH-IND-FIRST-STAND` | Stood for office | `audit_log.ref='F-IND-011'` | V |
| `ACH-IND-VALIDATED` | Candidacy validated | `ref='F-ELB-002'` | V |
| `ACH-IND-SEATED` | Held a seat | `legislature_members` | V |
| `ACH-IND-PETITIONER` | Filed a petition | petition filing ref | V |
| `ACH-IND-SIGNATORY` | Signed a petition | signature record | V |
| `ACH-IND-TESTIMONY` | Sealed testimony to the record | `ref='F-SOC-002'` | V |
| `ACH-IND-SQUARE` | Spoke in the public square | `ref='F-SOC-001'` | V |
| `ACH-IND-CHALLENGER` | Filed a constitutional challenge | `ref='F-IND-016'` | V |
| `ACH-IND-JUROR` | Served on a jury | jury membership | V |
| `ACH-IND-ADVOCATE` | Served as an advocate | advocate assignment | V |

### 4.2 Plane A — legislature service (Art. II)

| Code | Medal | Verified source | Tier |
|---|---|---|---|
| `ACH-LEG-BILL` | Introduced a bill | bill authorship + `audit_log` | V |
| `ACH-LEG-ENACTED` | A bill you introduced became law | law version chain | V |
| `ACH-LEG-COMMITTEE` | Took a committee placement | `ref='F-LEG-010'` / placement row | V |
| `ACH-LEG-CHAIR` | Chaired a committee | chair role derivation (R-12) | V |
| `ACH-LEG-SPEAKER` | Elected speaker | speaker election result | V |
| `ACH-LEG-QUORUM` | Sat in a session that made quorum | session attendance | V |
| `ACH-LEG-SUPERMAJORITY` | Voted in a 2/3 decision | chamber vote record | V |

### 4.3 Plane A — executive, civil service & judiciary (Art. III, IV)

| Code | Medal | Verified source | Tier |
|---|---|---|---|
| `ACH-EXE-DELEGATE` | Seated on an executive committee | executive membership | V |
| `ACH-EXE-ORDER` | Issued an executive order | executive order record | V |
| `ACH-CIV-APPOINTED` | Took a 10-year civil appointment | appointment record (CLK-09) | V |
| `ACH-BOG-GOVERNOR` | Seated on a Board of Governors | BoG membership | V |
| `ACH-JUD-NOMINATED` | Nominated to a judiciary | `judicial_nominations` | V |
| `ACH-JUD-SEATED` | Seated as a judge | judiciary seat | V |
| `ACH-JUD-OPINION` | Authored an opinion | opinion record | V |
| `ACH-JUD-REMEDY` | Issued a judicial remedy (Art. IV §5) | `judicial_remedy` law version | V |

### 4.4 Plane A — organizations (Art. III §§5-6)

| Code | Medal | Verified source | Tier |
|---|---|---|---|
| `ACH-ORG-FOUNDED` | Founded an organization | `ref='F-ORG-001'` | V |
| `ACH-ORG-ENDORSEMENT` | Your organization endorsed a candidate | `ref='F-ORG-002'` | V |
| `ACH-ORG-BOARD` | Seated on a board | board membership | V |
| `ACH-ORG-100-MEMBERS` | 100 members | membership count | V |
| `ACH-ORG-WORKER-REP` | Worker representation reached (100 employees) | CLK-13/14 recompute | V |
| `ACH-ORG-PARITY` | Worker/shareholder parity (2,000 employees) | CLK-13/14 recompute | V |
| `ACH-ORG-CGC` | Chartered a common-good corporation | CGC charter record | V |
| `ACH-ORG-IP-DEDICATION` | Dedicated work to the public domain | `CgcIpRegisterService::dedicate()` | V |
| `ACH-ORG-MULTI-JURISDICTION` | Operating across jurisdictions | org jurisdiction spread | V |

### 4.5 Plane A — federation & node operation

| Code | Medal | Verified source | Tier | Note |
|---|---|---|---|---|
| `ACH-FED-PEER` | Your instance met a peer | peer record | V | |
| `ACH-OPS-NODE` | Ran a node | operator record | V | **Copy must carry the existing house line: *"Keeping it online buys no vote and no seat."*** (`LEARN_BY_MODULE.operator`) |

### 4.6 Plane A — the 13 journey arcs (the tour tier)

The existing arcs stay exactly as they are and keep their keys. They are **tier T** — self-reported
walkthroughs of how the world works, and the UI must say so rather than let them read as
credentials. 10 live (`election`, `committee-session`, `bill`, `court-case`, `start-org`,
`board-meeting`, `form-a-group`, `petition-to-referendum`, `public-service`, `two-governments`);
3 planned (`budget`, `mutual-aid`, `stipend-and-tax`) — **all three gated on lane 13's L+M work**.

### 4.7 Plane B — jurisdiction & system milestones

`ACH-JUR-FIRST-LEGISLATURE` · `ACH-JUR-FIRST-ELECTION` · `ACH-JUR-SUBDIVIDED` ·
`ACH-JUR-FULL-INSTITUTIONS` · `ACH-JUR-CRITICAL-POP` · `ACH-SYS-FIRST-ACTIVATION` ·
`ACH-SYS-100-JURISDICTIONS` · `ACH-SYS-1M-RESIDENTS` · `ACH-SYS-FIRST-UNION` ·
`ACH-SYS-JUR-COVERAGE` — all published via `PublicRecordService::publish()`, k-anon floored,
never person-attributed.

**Tier D (deferred to lane 03's Phase-I draft):** `ACH-JUR-LEGIT-25/50/75/100` and
`ACH-SYS-EARTH-REACH` depend on the reach ratio and its denominator. I consume lane 03's
`LegitimacyService` numbers; I do not derive them.

---

## 5. Decisions this design makes

### 5.1 The keying fork — generalise `journey_id` to an award key

`achievements.journey_id` is the identity column and `JourneyService::recordAchievement()`
hardcodes it. `ACH-IND-FIRST-BALLOT` is not a journey, so today it has nowhere to live.

**Recommendation:** rename `journey_id` → **`award_key varchar(64)`** in one additive migration,
holding *either* a journey arc key *or* an `ACH-*` code, validated against a code-side
`AchievementCatalog` (the charter's *"code registry, not a table"*).

Why a rename rather than a second table or a second column:
- One ledger, one append-only trigger (`achievements_immutable` / `achievements_no_truncate`),
  one federation path, one idempotency index — all preserved.
- The 13 existing arc keys stay valid without translation.
- **Both tables hold zero rows on both boxes**, so this costs nothing today.

Carry over untouched: the partial-unique `(user_id, award_key) WHERE deleted_at IS NULL`, and the
deliberate **absence of an FK to `users`** — federated medals legitimately arrive for users who do
not exist locally.

### 5.2 Fidelity gap 1 — self-reported ticks, resolved per medal

`markStep()` validates only that the arc is live and the index in range; nothing checks the user
did the thing. This design resolves it **per entry rather than globally**: every Plane-A medal in
§4.1-4.5 is **tier V** with a named source (§1's spine covers most of them), and the 13 journey
arcs stay **tier T** and are *labelled* as walkthroughs.

The tour tier keeps its value — it is the guided explanation of how the world works — and stops
borrowing the credibility of the verified tier. Both can coexist in one ledger precisely because
the tier is on the catalog entry, not on the user.

### 5.3 Fidelity gap 2 — `earned_at` (operator ruling: fix pre-launch)

Specified in full in `K2_ENGINE_PLAN.md` §earned_at. In summary: `earned_at` is full-second,
`audit_seq` points at an exact position in the origin's audit chain, `source_server_id` is set,
and **all three federate** — while the elections engine deliberately destroys precisely this
signal (`cast_bucket`, hour-truncated, pinned). Charter wanted `awarded_on` as a coarse **DATE**.
Zero rows exist today.

### 5.4 The medal title is an English string in an immutable, federating ledger

`achievements.title` is denormalized from config **at earn time** as a raw English string, into a
table whose `BEFORE UPDATE` trigger makes it **permanently unrewritable** — and the row federates.
A ~50-medal library multiplies that by ~4× against the project's 77-language commitment.

**Decide this in the same migration as `awarded_on` (§5.3), while both tables hold zero rows:**
store an **i18n key** (e.g. `award_key` alone, with the title resolved at render) rather than a
denormalized English string. A translated medal is then a display concern; today it is a permanent
data fact that no later pass can fix.

*(Denormalization was the right call for a 13-arc tour catalog. It stops being right the moment the
catalog is a translated curriculum.)*

### 5.5 A live divergence from the charter, flagged not silently resolved

The roadmap states achievements get **no `audit_log` module of their own** — *"achievements
deliberately does NOT get a module — it reuses records."* But `JourneyService:186` already appends
`module: 'journeys'`.

**Recommendation:** keep `'journeys'` (it is shipped, it is clearer than overloading `'records'`,
and zero rows depend on either choice) and correct the charter text. **@lane-07** owns that doc —
flagged rather than edited.

---

## 6. Rail compliance

| Rail | How this library satisfies it |
|---|---|
| **CI-1** no governance advantage | No entry mints a role or clock — the closed sets `R-01..R-30` / `CLK-01..CLK-21` are untouched. Roles are **derived, never stored** (`RoleService::derive()`, no grants table), and every gate in the app funnels through `ResolvesRoles`→`AttestationGate`→`RoleService`. The non-interference argument is therefore a *symbol-reachability* argument, and the pin shape is already specified (§7). |
| **CI-2** never a role | Same mechanism. *(Note: `CI-2` is used for two different things in the corpus — Phase O's `scale_demo` federation rule and this one. Disambiguated here; flagged to @lane-07.)* |
| **CI-3** no economic reward | No entry touches treasury or exchange. @lane-13's rule adopted verbatim: money grants no governance advantage either, and UBI eligibility is active residency association only. |
| **CI-6** authoritative writer only | Plane-B snapshots ride the existing `onOneServer` + `LeaderProbe::isPrimary()` write-leader rail. |
| **PI-1** no individual leaderboard | Plane B has no `user_id` by construction. Plane A is never ordered against another person. |
| **PI-2** envelope, not ballot; no abstention leak | §3. Forbids public streaks and "N of M" counters. |
| **PI-3** k-anonymous floor | Every Plane-B count suppressed below the floor — a jurisdiction can activate at 1 resident. |
| **PI-4** default private, opt-in | Visibility is a personal setting — **zero new F-forms** for achievements (charter). |
| **PI-6** no composite score | **No sum, no percentage, no rank, no "level" — anywhere, ever.** Not stored *and* not computed at view time. This is the rail most likely to be broken by a well-meaning UI; it is a non-existence pin, not a policy. |
| **Art. II §8** no fee | Nothing here is purchasable. |

**`PublicRecordService::FORBIDDEN_SUBJECT_TYPES` already carries the inline note
*"(Phase K-2 adds `education_progress`.)"*** — the blocklist is waiting for this phase. K-2 extends
it; the publish() chokepoint then makes the leak structurally impossible rather than merely
forbidden.

---

## 7. Test pins (house style)

`tests/Constitutional/<Mechanic>Test.php`, PascalCase, docblock opening
`CONSTITUTIONAL PIN — <Article> (<rule>)` and closing *"If an edit breaks these tests, that edit is
a constitutional violation — fix the edit, never the test."*

1. **`AchievementNonInterferenceTest`** — the CI-1 firewall, in the shape
   `IK-civic-org-powers-and-record.md:86` already specifies: a **source-grep** pin (modelled on
   `BallotSecrecyTest:189-245`) asserting `App\Domain\Powers\*` and `RoleService` import no symbol
   from `AchievementService`, plus `RoleService::derive()` returning **byte-identically** with the
   achievement state present and absent.
2. **`AchievementNoCompositeScoreTest`** — PI-6 non-existence: no column, no accessor, no
   serializer field that sums, counts, ranks or percentages a person's medals.
3. **`AchievementEnvelopeOnlyTest`** — PI-2 source-grep: no achievement code path references
   `ballots`, `payload_encrypted`, `salt`, `ballot_hash` or `BallotReceipt`.
4. **`AchievementSoftGateTest`** — pins the rule `JourneyService`'s own docblock already asserts
   but nothing currently tests: *"journeys nudge, they NEVER block."* Quote the sentence rather
   than paraphrasing it — it appears verbatim in nine places in the built engine.
5. **`AchievementNoAbstentionSignalTest`** — §3's render rule: the serialized medal shape carries no
   "not earned" enumeration, no denominator, and no per-race slot list from which absence could be
   differenced.

The **reader** pin is the mirror of the existing writer pin and is worth calling out: pin 3 asserts
that no file in the achievement/education namespaces contains `ballots`, `payload_encrypted`,
`ballot_hash`, `salt`, `BallotReceipt` or `decryptForCount` — exactly as `BallotSecrecyTest` asserts
that nothing but `BallotBox` *writes* them. Model the mechanics on `BallotSecrecyTest:186-245`
(a `RecursiveDirectoryIterator` source-grep — no live DB, so it cannot skip).

**Register these in `FuturePhasePlaceholdersTest`** — the documented mechanism for pre-registering
an unbuilt phase's pins (*"The constitutional suite IS the roadmap"*). It currently holds zero
skips and a roll-call of 17 replacement files; K-2's four entries join that roll-call.

⚠ **No CI exists in this repo** (no `.github`). Any claim that a pin is "CI-gated" implies standing
CI up first — @lane-05 is building their checker standalone for the same reason.

---

## 8. Open items

- **PI-5 does not exist anywhere in the repo** — zero occurrences across `docs/`, `mockups/`,
  `tests/`, `app/`, `resources/`, `config/`. PI-1/2/3/4/6 are all present. The definitive source
  (`achievements-legitimacy.md`) is **not in this repo** — it lives on the uncommitted
  `explore/achievements` branch. **@operator:** either PI-5 is a numbering gap or it is a rail we
  are currently unable to honour because nobody can read it. Worth recovering that branch.
- **The v3 mockups dropped the CI-*/PI-* citations** that v2 carried, replacing them with plain
  language. Good for players, a fidelity regression for builders — the coded rails now survive
  only in `mockups/v2`. Flagged so the codes are not lost when v2 is retired.
- **Tier D entries** wait on lane 03.
