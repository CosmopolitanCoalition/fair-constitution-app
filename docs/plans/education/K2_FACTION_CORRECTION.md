# K2 — Teaching Corrections (the fleet's corrected-wording list)

**Owner:** Lane 15 (Civic Education & Achievements, Phase K-2) · **Deliverable:** D-16a
**Consumers:** lane 09 (presentations) · lane 10 (video) · lane 11 (dubbing) · lane 12 (social)
· relayed by @operator to the site chats 8a/8b
**Status:** published 2026-07-25. **This document lifts the fleet-wide faction-language freeze.**

> **Read this first.** The file carries **three** corrections, not one. The faction correction is
> the largest, but the seat-rule and Webster corrections sit in the same materials and would
> otherwise ship stale in the same breath. All three are "public materials teach a model the app
> does not implement" — the same failure mode, so they travel together.
>
> **The register is fixed and is not the lanes' to choose.** You are correcting the **teaching**
> to match the build. You are **not** presenting rewritten constitutional text as adopted — that
> is the operator's item (D-03, with 8a/8b). The Template still says "faction"; the app does not
> implement it. Both facts are said out loud, in that order. See §1.2.

---

## 1. Correction 1 — factions → polymorphic endorsements

### 1.1 Ground truth (verified in code this session, not assumed)

- The baseline schema contains **zero** occurrences of the string `faction`. The legacy
  `legislature_faction_registrations` table and all faction-id columns are genuinely gone; the
  only surviving repo mention is a historical prose note at `CLAUDE.md:222`.
- `public.endorsements` is polymorphic by string enum: `endorser_type varchar` + `endorser_id
  uuid`, with the schema's own column comment — *"UUID of organization or user"*
  (`database/schema/pgsql-schema.sql:1939-1959`). Unique on
  `(election_id, candidate_id, endorser_type, endorser_id)`.
- Political parties are ordinary `organizations` rows with `type='political_party'` — one of five
  values (`political_party | business | nonprofit | common_good_corp | informal`) in **one
  registry**, no separate party plane (`app/Models/Organization.php:11-14`).
- Proportionality is carried by **STV/Droop at the individual-preference level**. Committee
  assignment is faction-independent: every member rank-orders every committee, placements honour
  rank order, ties break to the largest vote share after normalising quotas.
- **The de-factionization is constitutionally pinned.**
  `tests/Constitutional/CountbackUniversalTest.php:167-178` greps the PROTECTED
  `VoteCountingService.php` for `/faction/i`, `/endors/i`, `/\bparty\b/i`, `/electorate/i` and
  asserts **all four are absent**. This is not a preference; a regression would go red.

### 1.2 The house voice — copy this pattern, do not invent one

The built app already solved the "the Template says faction, the build doesn't" problem, and the
solution is quotable. Canonical example, `resources/js/Pages/Elections/Results.vue:328`:

> All factions can observe and audit · Art. II §2 · **as implemented** — observer standing
> transfers to endorsing organizations and candidates

**Pattern: cite the clause → mark it `· as implemented` → state the built behaviour.** This lets
every deck, video and post keep citing the Template honestly without teaching a mechanism that
does not exist. Use it. Do not paraphrase the clause away, and do not present your rewrite as the
amended text.

### 1.3 The correction table — four rows cover ~90% of the corpus

| # | Source clause / old teaching | What the app actually does | Approved replacement (verbatim from a built surface) |
|---|---|---|---|
| C1 | Art. II §2 — *"where all factions can observe and audit the election and ballot counting processes"* | Observer standing is computed from **active organization endorsements plus the race's own candidates** (`ResultsController::observers()`) | *"All factions can observe and audit · Art. II §2 · as implemented — observer standing transfers to endorsing organizations and candidates"* — `Results.vue:328` |
| C2 | Art. II §2 — *"voting systems where the factional makeup of an elected body is matched to the collective preferences of the voters"* | STV/Droop matches preferences at the **individual** level; there is no group layer to match | *"STV satisfies factional-makeup matching at the individual-preference level with no party layer"* — ledger #q1 |
| C3 | Art. II §4 — *"each faction is awarded their respective committee seats based on the factional proportions"* | Placements distribute **evenly across members** (counts differ by at most one); ties break to the largest vote share after normalising quotas | *"Committee assignment is faction-independent: every member rank-orders every committee; placements honor rank order; ties break to the seat holder with the largest vote share after normalizing quotas. This preserves the proportional representation the STV election produced while making assignment independent of any party layer."* — `Committees.vue:481-487` |
| C4 | Art. III §2 — *"Executive Committees are composed of a Legislature's members in the same kind of factional proportions"* | Delegated members carry **org-endorsement chips**, ordered by normalised vote share | *"Endorsement linkage feeds proportionality — not a faction layer."* — `OrgDetail.vue:204` |

### 1.4 Short phrases, safe to reuse verbatim

- *"There is no faction registration — endorsements are polymorphic rows"* — `OrgDetail.vue:206`
- *"Endorsements inform — they never gate."* — `CandidateProfile.vue:237`
- *"running unendorsed is first-class"* — `CandidateProfile.vue:212`
- *"faction-independent assignment"* — `config/cga/surfaces.php:364`
- *"One registry, no faction layer"* — `config/cga/surfaces.php:696`

### 1.5 ⚠ DEFENSIVE LINE — do not claim individual endorsement is a live action

**This is a correction to the correction, and it is new.** `endorser_type='user'` is supported by
the schema, the model, and **five read paths** (candidacy split, approvals, the open ballot's
"individual endorsers" filter, the candidate public-endorsement web, the CandidateRow chip) — but
the **only** code that creates an endorsement row is
`CandidateEndorsementGrant.php:121`, which hardcodes `ENDORSER_ORGANIZATION`. There is **no form,
no handler and no route** for an individual to endorse.

So the flagship sentence *"any organization or individual can endorse any candidate"* is true of
the **record model and the reading surfaces**, and **not yet fileable in the app**.

- ✅ Safe: *"the endorsements record is polymorphic — an organization or an individual can be the
  endorser."*
- ❌ Unsafe: *"you can endorse a candidate"* / *"individuals can endorse today."*
- ✅ Safe to demonstrate on camera: the **org handshake** F-CAN-002 (candidate requests) →
  F-ORG-002 (agent grants), which is live, forced public, and confers R-07.

Two related limits worth stating once so nobody overreaches: **R-07 is org-only**
(`RoleService::hasOrgEndorsedCandidacy` filters `endorser_type='organization'`), and **observer
standing is org-only too**. The app's rewrite is internally consistent — just do not extend it to
individuals.

### 1.6 The external corpus — scope by TEXT, not by hit count

`docs/extracted/topic_knowledge.md` holds **132 true faction hits** (a naive `/faction/` grep
returns 138 — lines 3621 and 3885 are *"satisfaction"*). Those 132 hits collapse to roughly
**9 distinct texts**. Fix the texts, not the hits.

**MANDATORY re-record / rewrite — the template subjects (hard conflicts):**

| Priority | Subject | Why | Asset |
|---|---|---|---|
| **1** | **24 · Legislatures** (1:01:01) | Worst offender. Quotes all four faction clauses **and** walks the audience through a faction-bucket committee algorithm including an "unaffiliated get their own temporary bucket" step that has no counterpart in code. Re-record block: lines 9898, 10021-10069, 10111-10180 | `youtube.com/watch?v=dMyylWqHa04` |
| 2 | 25 · Governments | Executive committees "in the same kind of factional proportions"; advisor + oversight framing | `watch?v=VzkujL8bb3s` |
| 3 | 16 · Cosmopolitan Template | "These committees mirror the factional composition of the legislature" | `watch?v=n8MWGFm3tkY` |
| 4 | 26 · Judiciaries | Judicial nomination inherits the faction-proportional committee | `watch?v=Vw0m7UDGw58` |
| 5 | 23 · Individuals | Recites the "all factions can observe and audit" clause | `watch?v=mFglBb06r9U` |
| 6 | 6 · Balancing Interests Uniformly | The **"Committee Control"** paragraph — *"Seats within committees are distributed among factions"* | `watch?v=XvyiIcsucOs` |

**One rewrite fixes two pages:** the *Committee Control* paragraph is duplicated **word-for-word**
at `topic_knowledge.md:2120` (Subject 6) and `:8564` (Subject 21).

**DO NOT reshoot the electoral-systems explainers** — Subjects 13, 18, 19, 20, 21. Their faction
usage is **comparative pedagogy**: colour-coded sample-ballot groupings used to show that PR-RCV
tracks voters rather than blocs. Their own thesis already agrees with the build — *"PR-RCV is
compatible with One-Party, and No-Party Systems as it pays attention to Individuals, not
Factions"*, and Subject 21's transcript says outright *"the factions were just helpful colors."*
The cheap, correct fix is **one disclaimer card / lower-third**: the colours are illustrative
groupings; the app has no faction or party column.

**Find-and-replace across the whole corpus** — the PR-RCV benefit blurbs repeat verbatim 13 times
across seven subjects:
- *"negotiations with faction leadership"* → **"negotiations with any bloc or coalition that forms"**
- *"single faction majorities unlikely"* → **"single-bloc majorities unlikely"**

### 1.7 The best on-camera proof

The open ballot's **"Endorsed by"** filter (`OpenBallot.vue:299-306`) — options *— any —* / each
org by name / *individual endorsers* / *no endorsements* — is the single most legible visual
demonstration that **there is no party column**. Pair it with the Chamber seat table's
"Endorsements" column and its *"no endorsements"* empty state.

---

## 2. Correction 2 — the seat rule (districts are 5–9, legislatures are not)

Ruled by lane 07 on 2026-07-25 and binding on all content lanes.

**The error:** materials (and CLAUDE.md's own hard-constraints table, which reads
*"Legislature min seats 5 / max seats 9"*) state 5–9 as a **legislature** bound. It is a
**district** bound.

**The truth:** districts elect 5–9 representatives. **Legislatures** scale by **cube root of
population** and are **composed of** districts. The leaf ceiling-9 clamp was retired 2026-07-19
(floor-clamp only).

**Approved public line — use verbatim:**

> Power stays local: every district elects five to nine representatives. Bigger places simply have
> more districts — Earth's world legislature seats 1,999 members across 282 of them.

**Planet figures for any teaching copy** (conflation fixed in `6b685ff`): **~955,130 legislatures
across ~951,626 jurisdictions**. Never the single conflated number. Earth root = **1,999 seats
exact** — that is the sizing law's own output, not a rounding.

---

## 3. Correction 3 — Webster is not the apportionment method (ledger #q4 is stale)

**Flagged, not previously known to the fleet.** `mockups/v3/shared/constitutional-questions.html:87-97`
(ledger **#q4**) still describes districts as *"Webster-apportioned composites"* with
*"Webster-rounded seats."*

That is **contrary to settled law**. Per `CLAUDE.md` §Apportionment Law (operator ruling
2026-07-13): there is **no Webster, Sainte-Laguë, largest-remainder or any other textbook
apportionment method** anywhere in seat allocation. The procedure is the giant-cascade (rounded
cube root → children-sum shares → a share at/above ceiling+0.5 rounds nearest and locks →
remainder redistributes, recursing), with drawn districts rounding to **nearest, independently**.

**Consequence for the lanes:** ledger #q4 sits in the same file you will be quoting #q1 and #q6
from. **Do not carry the Webster wording forward.** If apportionment must be described, describe
the cascade or say nothing.

---

## 4. Do-not list (all lanes)

1. **Do not link users to the constitutional-questions ledger.** It exists **only** as static
   mockup pages; `routes/web.php` has no `/system/constitutional-questions` route, so every
   "ledger #q1" reference in live copy and every `CitationLine` anchor is a **dead link** in the
   running product. Quote the ledger text as house wording; if you need to show "where this is
   written down," screenshot the **OrgDetail Endorsements banner** or the **Committees About
   panel** — those are real running surfaces.
2. **Do not claim individual endorsement is fileable** (§1.5).
3. **Do not carry Webster wording** (§3).
4. **Do not treat `docs/extracted/roles_forms_chart.md` as corrected** — it still carries faction
   wording in R-14, F-LEG-010 and two workflow rows; those cells wait on D-03/D-05.
5. **Do not present rewritten constitutional text as adopted.** The Template is unamended; that is
   the operator's item with 8a/8b.

---

## 5. Machine-readable term table (for lane 12's `claims_check` lint)

Consume this directly rather than maintaining a second copy. `severity: fail` blocks publication;
`warn` requires a human read.

```json
{
  "version": 1,
  "source": "docs/plans/education/K2_FACTION_CORRECTION.md",
  "updated": "2026-07-25",
  "rules": [
    { "id": "FAC-1", "severity": "fail", "pattern": "(?i)\\bfaction(s|al|ally)?\\b",
      "unless_within": ["as implemented", "helpful colors", "illustrative grouping", "the Template still says"],
      "message": "Faction-model language. The app has no faction layer — endorsements are polymorphic rows. See K2_FACTION_CORRECTION §1.3." },
    { "id": "FAC-2", "severity": "fail", "pattern": "(?i)(party|faction)\\s+(list|column|slate|bucket)",
      "message": "No party/faction column exists on any ballot or seat table." },
    { "id": "FAC-3", "severity": "fail", "pattern": "(?i)you can endorse|individuals can endorse|endorse a candidate today",
      "message": "No individual-endorsement write path exists. Say 'the endorsements record is polymorphic'. See §1.5." },
    { "id": "SEAT-1", "severity": "fail", "pattern": "(?i)legislatures?\\s+(have|of|with)\\s+(five to nine|5[-–– ]to[-– ]9|5\\s*[-–]\\s*9)",
      "message": "5-9 bounds a DISTRICT, not a legislature. Legislatures scale by cube root. See §2." },
    { "id": "SEAT-2", "severity": "warn", "pattern": "(?i)951,?626\\s+legislatures",
      "message": "Conflated figure. ~955,130 legislatures across ~951,626 jurisdictions (6b685ff)." },
    { "id": "APP-1", "severity": "fail", "pattern": "(?i)webster|sainte[-– ]lagu|largest[-– ]remainder",
      "message": "No textbook apportionment method is used anywhere in seat allocation. See §3." },
    { "id": "LINK-1", "severity": "fail", "pattern": "/system/constitutional-questions",
      "message": "Dead route — the ledger is mockup-only. Quote it; never link it. See §4.1." },
    { "id": "STATUS-1", "severity": "warn", "pattern": "(?i)every institution,? modeled and automated|all phases complete",
      "message": "J/L/M/N/O are substrate-only; I and K-2 are PARTIAL. Cite BUILT_INVENTORY.md §1." }
  ]
}
```

---

## 6. What is NOT in this document

- **Lesson authoring.** Decision (2026-07-25): spec + map now, authoring on approval. This file
  corrects and flags; it does not teach.
- **Template amendment wording.** Operator's item D-03 with 8a/8b.
- **The Learn-module packaging** of the faction correction — that lands in `K2_CURRICULUM.md`
  once the authoring template is approved. Today there is **no lesson, quiz or module** teaching
  factions→polymorphic anywhere in the app: `config/cga/journeys.php` and `resources/js/registry/`
  contain zero faction matches. **Lanes 09-12 are producing the first packaged teaching of this
  correction**, which is exactly why the wording has to be one wording.
