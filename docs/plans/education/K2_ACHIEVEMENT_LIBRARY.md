# K2 — Achievement Library (complete catalog)

**Owner:** Lane 15 (Phase K-2) · **Deliverable:** D-16b · **Revised:** 2026-07-25 (complete build)
**Status:** catalog COMPLETE and wireable. Derived entirely from the as-built app — the 108
canonical forms, the 30 derived roles, the 21 clocks, and the tables that already record every act.

---

## 0. What this is, and what was already there

Two catalogs existed and neither was the library:

1. **The live ledger** — `config/cga/journeys.php`, **13 arcs** (10 live / 3 planned). Every medal
   earnable today is one completed journey. `achievements.journey_id` holds the arc key.
2. **A 27-code sketch** in `mockups/{v2,v3}/…/fixtures-v2.js`, which `mockups/v3/manifest.json:489`
   labels *"Proposed; no AchievementCatalog in code."*

**This document is the catalog.** It is not derived from the Template or the website — it is
derived from **what the code already records**, so every entry has a real trigger on a real table.

### The generative spine

`ConstitutionalEngine::file()` seals every constitutional act into a hash-chained row carrying
**who did it** and **which form it was**:

```
audit_log( actor_user_id, module, event, ref, jurisdiction_id, rejected, seq )
     indexes on actor_user_id, module, ref  ·  append-only by DB trigger
```

> **`SELECT 1 FROM audit_log WHERE actor_user_id = :user AND ref = :form AND rejected = false`**
>
> That one indexed, already-sealed query proves a person performed a given constitutional act.
> **The entire verified catalog needs no new table, no new column, no new event, and no new clock.**

Where a form is not the right evidence (seatings, memberships, confirmations), the **role-derivation
fact query** is — `RoleService` already computes all 30 roles from authoritative tables on demand.

**Three standing rules for every trigger:**
- `rejected = false` — rejections are chained too; a medal must never count one.
- **Electoral participation reads `ballot_envelopes`, never `ballots`** (§3).
- Presence of the row is the fact. Never read the payload.

---

## 1. The three scopes

| Scope | Store | Why |
|---|---|---|
| **Personal** | `achievements` (user-scoped, append-only, federates) | the person's own record |
| **Organization** | the **org profile**, as display chrome | IK:106 — *"confers no power — same CI-1 rail"* |
| **Jurisdiction / system** | `PublicRecordService::publish(kind: participation\|certification)` | IK:163 — *"public milestones only"* |

This split is **already ruled**, not invented here:
`IK-civic-org-powers-and-record.md:170` — *"the metric's record footprint is a separate immutable
table + public milestone rows."*

> ⚠ **Never overload `user_id` with a jurisdiction or org UUID.** `achievements.user_id` is
> `NOT NULL` with **no FK** — deliberately, so append-any-verified federation can land medals for
> identities absent locally. A non-person UUID there would federate a jurisdiction into a person
> column with nothing to catch it, and the partial-unique `(user_id, award_key)` would collide
> across scopes. There is no `jurisdiction_id` / `organization_id` column on `achievements`, and
> this design does not add one.

---

## 2. Counts — where we actually are

| | Count |
|---|---|
| Medals **earnable in code today** (journey arcs; 10 live, 3 planned) | **13** |
| Achievement rows **ever earned**, both boxes | **0** |
| **Catalog — personal** (83 `ACH-*` + the 13 arcs) | **96** |
| **Catalog — jurisdiction** | **22** |
| **Catalog — system / planet** | **9** |
| **TOTAL CATALOG** | **127** |
| — **server-verified** against a named source | **112** |
| — **tour ticks** (the journey arcs, labelled as such) | **13** |
| — **deferred** to Phase I (@lane-03 reach/legitimacy) | **2** |

**Nothing here needs a new table.** All 112 verified entries resolve against `audit_log`, a
role-derivation fact query, or `ballot_envelopes`. The 22 jurisdiction + 9 system milestones publish
through the existing `PublicRecordService`.

**So the honest state: 13 of 127 are wireable today, and 0 have ever been earned.** The gap is not
engine work — the engine, the ledger, the append-only trigger, the federation path and the badge UI
are all built and tested. The gap is a catalog (§8), and it is five small additive changes.

---

## 3. The electoral rail (the one that constrains everything)

`ballot_envelopes` and `ballots` are physically unlinked — no FK either direction — and pinned by
`tests/Constitutional/BallotSecrecyTest.php`:

- `ballots` may hold **exactly nine columns** (`assertEqualsCanonicalizing`); its only time is the
  **hour-truncated `cast_bucket`**; a regex rejects any column matching
  `/user|voter|envelope|identity|created_at|updated_at|deleted_at|ip_|session/i`.
- Its PK is explicitly random v4 — an ordered UUID *"would leak precise insert time and defeat
  `cast_bucket` truncation."*
- Only shared columns are `['id','race_id','kind','referendum_question_id']` — *"a shared SCOPE is
  not a linking channel; a shared per-ballot identifier would be."*

**So the one lawful "you voted" fact is a `ballot_envelopes` row** for
`(user_id, race_id | referendum_question_id)`. Already indexed on `user_id` — no new index.

**PI-2's forgotten half — no abstention leak.** Envelopes are queryable per race and associations
are queryable, so *"did not vote"* is a trivial set difference; a participation medal makes that
absence legible on a profile.

> **Render rule:** an unearned medal renders **identically to one that does not apply** — *"not yet
> earned"*, never *"did not vote"*, and never a greyed slot in a known-complete grid. No public
> streaks, no "N of M" counters, no completion percentage on the personal plane. Pinned in §7.

---

## 4. PERSONAL CATALOG — 96 medals across 12 civic arcs (83 verified + 13 tour)

The arcs follow the **role progression the app actually implements** (R-01 → R-30). Every entry is
default-private (PI-4), grants nothing (CI-1), and never enters a composite (PI-6).
**Tier:** `V` verified · `T` tour tick · `D` deferred to Phase I.

### Arc 1 — Becoming a citizen (R-01 → R-02 → R-03/R-04/R-05) · 7
| Code | Medal | Trigger source | Tier |
|---|---|---|---|
| `ACH-CIV-001` | Registered | `ref=F-IND-001` | V |
| `ACH-CIV-002` | Profile set up | `ref=F-IND-002` | V |
| `ACH-CIV-003` | Declared a residency | `ref=F-IND-003` | V |
| `ACH-CIV-004` | First location ping | `ref=F-IND-005` | V |
| `ACH-CIV-005` | **Residency confirmed — franchise unlocked** | `residency_confirmations.is_active` (R-03) | V |
| `ACH-CIV-006` | Associated at every level from neighborhood to planet | association chain depth | V |
| `ACH-CIV-007` | Relocated and re-associated | second confirmed association after a lapse | V |

*`ACH-CIV-005` is the single most important medal in the catalog — it is the exact moment Art. I
absolute rights attach. R-04 (Voter) is emitted **identically and unconditionally** with R-03.*

### Arc 2 — The franchise (R-04) · 5
| Code | Medal | Trigger source | Tier |
|---|---|---|---|
| `ACH-VOT-001` | Cast a first ballot | **`ballot_envelopes`** `kind='ranked'` | V |
| `ACH-VOT-002` | Voted in a referendum | `ballot_envelopes` `kind='referendum'` | V |
| `ACH-VOT-003` | Voted at three different jurisdiction levels | envelopes joined to race jurisdiction depth | V |
| `ACH-VOT-004` | Voted in every race on one ballot day | envelopes per election day | V |
| `ACH-VOT-005` | Verified your own ballot by receipt | receipt-check hit (no hash stored) | V |

### Arc 3 — Voice: petition, square, testimony (R-05, R-03) · 7
| Code | Medal | Trigger source | Tier |
|---|---|---|---|
| `ACH-VOX-001` | Signed a petition | `ref=F-IND-010` | V |
| `ACH-VOX-002` | Created a petition | `ref=F-IND-009` | V |
| `ACH-VOX-003` | Your petition reached signature threshold | petition status transition | V |
| `ACH-VOX-004` | Your petition became a referendum | referendum linked to petition | V |
| `ACH-VOX-005` | Spoke in the public square | `ref=F-SOC-001` | V |
| `ACH-VOX-006` | Sealed testimony in a hall | `ref=F-SOC-002` | V |
| `ACH-VOX-007` | Filed a constitutional challenge | `ref=F-IND-016` | V |

*The square is **open** — `F-SOC-001` carries `roles => []`. Residency gates the testimony seal
(`F-SOC-002`), never square access.*

### Arc 4 — Standing for office (R-06 → R-07) · 6
| Code | Medal | Trigger source | Tier |
|---|---|---|---|
| `ACH-CAN-001` | Registered a candidacy | `ref=F-IND-011` | V |
| `ACH-CAN-002` | Published a campaign profile | `ref=F-CAN-001` | V |
| `ACH-CAN-003` | Requested an endorsement | `ref=F-CAN-002` | V |
| `ACH-CAN-004` | Endorsed by an organization (R-07) | `endorsements` `endorser_type='organization'` | V |
| `ACH-CAN-005` | Candidacy validated by the board | `ref=F-ELB-002` | V |
| `ACH-CAN-006` | **Stood unendorsed and was seated** | seated with zero active endorsements | V |

*`ACH-CAN-006` is deliberate: *"running unendorsed is first-class"* is live house copy
(`CandidateProfile.vue:212`). The catalog should say it too.*

### Arc 5 — Serving in a legislature (R-09 → R-10/R-11/R-12/R-13) · 15
| Code | Medal | Trigger source | Tier |
|---|---|---|---|
| `ACH-LEG-001` | Took the oath of office | `ref=F-LEG-001` | V |
| `ACH-LEG-002` | First attendance registered | `ref=F-LEG-002` | V |
| `ACH-LEG-003` | Introduced a bill | `ref=F-LEG-003` | V |
| `ACH-LEG-004` | Cast a floor vote | `ref=F-LEG-004` | V |
| `ACH-LEG-005` | **A bill you introduced became law** | law version traced to your bill | V |
| `ACH-LEG-006` | Made a motion | `ref=F-LEG-007` | V |
| `ACH-LEG-007` | Entered a statement on the public record | `ref=F-LEG-006` | V |
| `ACH-LEG-008` | Ranked your committee preferences | `ref=F-LEG-010` | V |
| `ACH-LEG-009` | Seated on a committee (R-11) | `committee_seats` seated | V |
| `ACH-LEG-010` | Cast a committee vote | `ref=F-LEG-005` | V |
| `ACH-LEG-011` | Chaired a committee (R-12) | `committees.chair_member_id` | V |
| `ACH-LEG-012` | Acted as alternate chair (R-13) | `committees.alternate_member_id` | V |
| `ACH-LEG-013` | **Elected Speaker (R-10)** | `legislatures.speaker_id` | V |
| `ACH-LEG-014` | Cast a tie-breaking vote | `ref=F-SPK-004` | V |
| `ACH-LEG-015` | Voted on a supermajority act | floor vote on a 2/3 threshold act | V |

### Arc 6 — Executive (R-14/R-15/R-16/R-17) · 6
| Code | Medal | Trigger source | Tier |
|---|---|---|---|
| `ACH-EXE-001` | Seated on a delegated executive (R-14) | `executive_members` delegated principal | V |
| `ACH-EXE-002` | Elected to an executive committee (R-15) | executive `type=committee`, elected | V |
| `ACH-EXE-003` | Elected individual executive (R-16) | executive `type=individual`, elected | V |
| `ACH-EXE-004` | Seated as an executive advisor (R-17) | `executive_members` role=advisor | V |
| `ACH-EXE-005` | Issued an executive order | `ref=F-EXE-005` | V |
| `ACH-EXE-006` | Nominated a Board of Governors member | `ref=F-EXE-001` | V |

*R-17 exists because Art. III §3 seats the **top four runners-up** automatically. Losing an election
and still serving is a real civic outcome and deserves a medal.*

### Arc 7 — Departments, boards & civil service (R-18/R-29/R-30) · 6
| Code | Medal | Trigger source | Tier |
|---|---|---|---|
| `ACH-BOG-001` | Seated as a department governor (R-18) | `board_seats` on `departments` | V |
| `ACH-BOG-002` | Implemented a department rule | `ref=F-BOG-001` | V |
| `ACH-BOG-003` | Filed a department report | `ref=F-BOG-002` | V |
| `ACH-BOG-004` | Took a 10-year civil appointment (R-30) | `appointments` + active term | V |
| `ACH-BOG-005` | Seated as administrative staff (R-29) | `appointments` on `admin_offices` | V |
| `ACH-BOG-006` | Published session minutes | `ref=F-SPK-009` | V |

### Arc 8 — Justice (R-19/R-20/R-21/R-22) · 12
| Code | Medal | Trigger source | Tier |
|---|---|---|---|
| `ACH-JUD-001` | Registered as an advocate (R-21) | `ref=F-IND-015` | V |
| `ACH-JUD-002` | Filed a case | `ref=F-IND-017` or `F-ADV-001` | V |
| `ACH-JUD-003` | Filed a motion | `ref=F-ADV-002` | V |
| `ACH-JUD-004` | Submitted evidence | `ref=F-ADV-003` | V |
| `ACH-JUD-005` | Filed a brief | `ref=F-ADV-004` | V |
| `ACH-JUD-006` | Summoned as a juror (R-22) | `jury_members` summoned | V |
| `ACH-JUD-007` | **Served on an empaneled jury** | `jury_members` empaneled | V |
| `ACH-JUD-008` | Seated as a judge (R-19/R-20) | `judicial_seats` seated | V |
| `ACH-JUD-009` | Assigned a panel | `ref=F-JDG-001` | V |
| `ACH-JUD-010` | Filed an opinion | `ref=F-JDG-003` | V |
| `ACH-JUD-011` | Made a constitutional finding | `ref=F-JDG-004` | V |
| `ACH-JUD-012` | **Applied a judicial remedy (Art. IV §5)** | `ref=F-JDG-006` | V |

*`ACH-JUD-012` marks the deepest act in the app — a judge editing law directly, with full history
preserved.*

### Arc 9 — Organizations & work (R-23..R-28) · 12
| Code | Medal | Trigger source | Tier |
|---|---|---|---|
| `ACH-ORG-001` | Registered an organization | `ref=F-IND-012` | V |
| `ACH-ORG-002` | Became an organization agent (R-23) | `organizations.agent_user_id` | V |
| `ACH-ORG-003` | Joined an organization (R-24) | `ref=F-IND-013` → active membership | V |
| `ACH-ORG-004` | Registered as a worker (R-25) | `ref=F-IND-014` countersigned | V |
| `ACH-ORG-005` | Your org endorsed a candidate | `ref=F-ORG-002` | V |
| `ACH-ORG-006` | Ran a board election | `ref=F-ORG-003` | V |
| `ACH-ORG-007` | Ran a **worker** board election | `ref=F-ORG-004` | V |
| `ACH-ORG-008` | Owner-elected board seat (R-26) | `board_seats` `owner_elected` | V |
| `ACH-ORG-009` | **Worker-elected board seat (R-27)** | `board_seats` `worker_elected` | V |
| `ACH-ORG-010` | Board chair (R-28) | `board_seats.is_chair` | V |
| `ACH-ORG-011` | Transferred ownership | `ref=F-ORG-005` | V |
| `ACH-ORG-012` | Converted public↔private | `ref=F-ORG-006` | V |

*`ACH-ORG-009` is Art. III §6 co-determination arriving in a person's life — the worker/shareholder
parity chain (CLK-13/14) is built and pinned.*

### Arc 10 — Running elections (R-08) · 5
| Code | Medal | Trigger source | Tier |
|---|---|---|---|
| `ACH-ELB-001` | Seated on an election board | `election_board_members` seated | V |
| `ACH-ELB-002` | Scheduled an election | `ref=F-ELB-001` | V |
| `ACH-ELB-003` | **Certified an election** | `ref=F-ELB-004` | V |
| `ACH-ELB-004` | Drew a district by hand | `ref=F-ELB-008` | V |
| `ACH-ELB-005` | Ordered a recount or audit | `ref=F-ELB-006` | V |

### Arc 11 — Federation & node operation · 2
| Code | Medal | Trigger source | Tier |
|---|---|---|---|
| `ACH-FED-001` | Your instance met a peer | peer record | V |
| `ACH-FED-002` | Ran a node | operator record | V |

*Copy must carry the live house line verbatim: **"Keeping it online buys no vote and no seat."***

### Arc 12 — The guided tour (the 13 journey arcs) · 13 · tier T
`election` · `committee-session` · `bill` · `court-case` · `start-org` · `board-meeting` ·
`form-a-group` · `petition-to-referendum` · `public-service` · `two-governments` **(10 live)** ·
`budget` · `mutual-aid` · `stipend-and-tax` **(3 planned — all gated on @lane-13's L+M)**.

**These stay self-reported and are labelled as walkthroughs in the UI.** They keep their existing
keys and their value — they explain how the world works — and they stop borrowing the credibility
of the verified tier.

---

## 5. JURISDICTION CATALOG — 22 milestones (Plane B, no `user_id`)

Published through `PublicRecordService::publish()`, k-anon floored, never person-attributed.
**This is the jurisdiction's own story — the thing a resident watches their place achieve.**

| Code | Milestone | Trigger |
|---|---|---|
| `ACH-JUR-001` | First confirmed resident | first `residency_confirmations` |
| `ACH-JUR-002` | **Activation threshold reached — the government may boot** | `jurisdiction_activations` → CLK-06 |
| `ACH-JUR-003` | Bootstrap election board seated | WF-JUR-01 |
| `ACH-JUR-004` | First election scheduled | `F-ELB-001` |
| `ACH-JUR-005` | **First election certified** | `F-ELB-004` |
| `ACH-JUR-006` | First legislature seated | `legislature_members` seated |
| `ACH-JUR-007` | Speaker elected | `legislatures.speaker_id` set |
| `ACH-JUR-008` | First committee created | `F-LEG-009` |
| `ACH-JUR-009` | **First bill enacted** | law version created |
| `ACH-JUR-010` | First referendum held | referendum result |
| `ACH-JUR-011` | First petition became a referendum | petition→referendum |
| `ACH-JUR-012` | Executive delegated | `F-LEG-014` |
| `ACH-JUR-013` | Executive converted to elected | `F-LEG-015` |
| `ACH-JUR-014` | First department created | `F-LEG-016` |
| `ACH-JUR-015` | Judiciary created | `F-LEG-017` |
| `ACH-JUR-016` | **First case judged** | `F-JDG-003` |
| `ACH-JUR-017` | First CGC chartered | `F-LEG-019` |
| `ACH-JUR-018` | Districts drawn / subdivided | district map activated |
| `ACH-JUR-019` | Second chamber seated (bicameral) | Type B seats filled |
| `ACH-JUR-020` | **Full institutions — legislature + executive + judiciary all live** | composite |
| `ACH-JUR-021` | Union formed or border settled | `F-LEG-029` / `F-LEG-030` |
| `ACH-JUR-022` | Emergency powers declared, then lapsed within 90 days | `F-LEG-024` + CLK-03 |

*`ACH-JUR-022` is deliberately shaped to celebrate the **lapse**, not the declaration — the
constitutional achievement is the 90-day ceiling holding.*

---

## 6. SYSTEM CATALOG — 9 planet milestones (Plane B)

| Code | Milestone | Trigger | Tier |
|---|---|---|---|
| `ACH-SYS-001` | First jurisdiction activated | first activation | V |
| `ACH-SYS-002` | 100 jurisdictions active | count | V |
| `ACH-SYS-003` | 1,000 jurisdictions active | count | V |
| `ACH-SYS-004` | 1,000,000 confirmed residents | count | V |
| `ACH-SYS-005` | First peer federation | peer handshake | V |
| `ACH-SYS-006` | First union of governments | `F-LEG-029` | V |
| `ACH-SYS-007` | Jurisdiction coverage % | coverage ratio | **D** |
| `ACH-SYS-008` | Earth reach gauge milestones (25/50/75/100) | `LegitimacyService::reachRatio()` | **D** |
| `ACH-SYS-009` | Every continent represented | association geography | V |

**Tier D** — 2 codes (`ACH-SYS-007`, `ACH-SYS-008`, the latter covering four reach bands) — consumes
@lane-03's tier curve and legitimacy denominator. I use their numbers; I derive none.
**Everything else in this catalog is independent of lane 03.**

---

## 7. Rails & pins

| Rail | Satisfied how |
|---|---|
| **CI-1** no governance advantage | No entry mints a role or clock — `R-01..R-30` / `CLK-01..CLK-21` untouched. Roles are **derived, never stored** (no grants table); every gate funnels through `ResolvesRoles`→`AttestationGate`→`RoleService`. |
| **CI-2** never a role | Same mechanism. *(Note: CI-2 is used for two different things in the corpus — Phase O's `scale_demo` rule and this one. Disambiguated; flagged to @lane-07.)* |
| **CI-3** no economic reward | Nothing purchasable. Per @lane-13: money grants no governance advantage either; UBI eligibility is active residency association only. |
| **CI-6** authoritative writer only | Plane-B milestones ride the existing `onOneServer` + `LeaderProbe::isPrimary()` rail. |
| **PI-1** no individual leaderboard | Plane B has no `user_id` by construction; personal medals are never ordered against another person. |
| **PI-2** envelope not ballot, no abstention leak | §3, including the render rule. |
| **PI-3** k-anon floor | Every Plane-B count suppressed below the floor — a jurisdiction can activate at 1 resident. |
| **PI-4** default private | Visibility is a personal setting — **zero new F-forms** for achievements. |
| **PI-6** no composite score | **No sum, no percentage, no rank, no level — not stored and not computed at view time.** The rail most likely to be broken by a well-meaning UI. |

**Pins** (`tests/Constitutional/`, house style — opener `CONSTITUTIONAL PIN — <Article> (<rule>)`,
closer *"fix the edit, never the test"*):

1. **`AchievementNonInterferenceTest`** — CI-1 firewall in the shape
   `IK-civic-org-powers-and-record.md:86` already specifies: source-grep that `App\Domain\Powers\*`
   and `RoleService` import no achievement symbol, plus `RoleService::derive()` byte-identical with
   and without ledger state.
2. **`AchievementEnvelopeOnlyTest`** — the **reader** pin, mirroring the existing writer pin: no
   file in the achievement namespace contains `ballots`, `payload_encrypted`, `ballot_hash`,
   `salt`, `BallotReceipt` or `decryptForCount`. Model on `BallotSecrecyTest:186-245`
   (`RecursiveDirectoryIterator` source-grep — no live DB, cannot skip).
3. **`AchievementNoCompositeScoreTest`** — PI-6 non-existence: no column, accessor or serializer
   field that sums, counts, ranks or percentages a person's medals.
4. **`AchievementSoftGateTest`** — quote `JourneyService`'s own sentence rather than paraphrase it:
   *"journeys nudge, they NEVER block."* It appears verbatim in nine places in the built engine.
5. **`AchievementNoAbstentionSignalTest`** — the §3 render rule: the serialized shape carries no
   "not earned" enumeration, no denominator, no per-race slot list.

Register all five in `FuturePhasePlaceholdersTest` — *"the constitutional suite IS the roadmap."*

---

## 8. What has to change in code (the whole list)

Small, additive, and entirely inside this lane's design:

1. **`achievements.journey_id` → `award_key varchar(64)`** — one additive migration. Holds either a
   journey arc key **or** an `ACH-*` code, validated against a code-side `AchievementCatalog` (the
   charter's *"code registry, not a table"*). Keeps one ledger, one append-only trigger, one
   federation path, one idempotency index; the 13 arc keys stay valid untouched.
2. **`earned_at` → `awarded_on DATE`** + the `audit_seq` channel — the operator's pre-launch ruling
   (`K2_ENGINE_PLAN.md` §3).
3. **Store an i18n key, not a denormalized English title** — same migration; the ledger is
   immutable and federates (`§5.4` below).
4. **`AchievementCatalog`** — a new code registry: `ACH-*` → `{title_key, scope, tier, trigger}`.
5. **`AchievementService`** — resolves triggers, awards idempotently through the **existing**
   `JourneyService::recordAchievement` seal path. Zero new F-forms, no CLK-22, no new audit module.

**All five are free right now: both tables hold zero rows on both boxes.**

---

## 9. Design decisions carried

### 9.1 Keying — one ledger, generalised key
Covered in §8.1. The deliberate **absence of an FK to `users`** stays: federated medals legitimately
arrive for users who do not exist locally.

### 9.2 Verified vs self-reported — resolved per medal
**112 verified** entries each name their proving source; the **13 journey arcs** stay tour ticks and
are labelled as such. The tour keeps its value and stops lending credibility it hasn't earned.

### 9.3 `earned_at` — see `K2_ENGINE_PLAN.md` §3
The elections engine destroys precise timing on purpose (`cast_bucket`, hour-truncated, pinned);
the medal ledger re-introduces it and federates it. Operator ruled: fix pre-launch.

### 9.4 The title is an English string in an immutable, federating ledger
`achievements.title` is denormalized at earn time and the `BEFORE UPDATE` trigger makes it
**permanently unrewritable**. A 127-medal catalog multiplies that ~10× against a 77-language
commitment. **Store the key; resolve the title at render.** Decide in the same migration as
`awarded_on`, while both tables hold zero rows.

### 9.5 A charter divergence, flagged not silently resolved
The roadmap says achievements get no `audit_log` module (*"it reuses records"*), but
`JourneyService:186` already appends `module: 'journeys'`. **Recommend keeping `'journeys'`** (it is
shipped, clearer than overloading `'records'`, and zero rows depend on either) and correcting the
charter text. @lane-07 owns that doc.

---

## 10. Open

- **`PI-5` does not exist anywhere in the repo.** PI-1/2/3/4/6 all appear; PI-5 has zero
  occurrences. Its source (`achievements-legitimacy.md`) is on the uncommitted `explore/achievements`
  branch. Either a numbering gap, or a privacy rail nobody can read. Recovering that branch is cheap.
- **The v3 mockups dropped the CI-*/PI-* citations** that v2 carried. The coded rails now survive
  only in `mockups/v2` — flagged so they are not lost when v2 retires.
- **Tier D** (5) waits on @lane-03.
