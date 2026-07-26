# K2 — Achievement Library (complete catalog)

**Owner:** Lane 15 (Phase K-2) · **Deliverable:** D-16b · **Revised:** 2026-07-25 (earner rule)
**Status:** catalog COMPLETE and wireable. Derived entirely from the as-built app — the 108
canonical forms, the 30 derived roles, and the tables that already record every act.

---

## 0. What this is, and what was already there

Two catalogs existed and neither was the library:

1. **The live ledger** — `config/cga/journeys.php`, **13 arcs** (10 live / 3 planned). Every
   achievement earnable today is one completed journey; `achievements.journey_id` holds the arc key.
2. **A 27-code sketch** in `mockups/{v2,v3}/…/fixtures-v2.js`, which `mockups/v3/manifest.json:489`
   labels *"Proposed; no AchievementCatalog in code."*

**This document is the catalog.** It is derived from **what the code already records**, so every
entry has a real trigger on a real table.

### The generative spine

`ConstitutionalEngine::file()` seals every constitutional act into a hash-chained row carrying
**who did it** and **which form it was**:

```
audit_log( actor_user_id, module, event, ref, jurisdiction_id, rejected, seq )
     indexes on actor_user_id, module, ref  ·  append-only by DB trigger
```

> **`SELECT 1 FROM audit_log WHERE actor_user_id = :user AND ref = :form AND rejected = false`**
>
> One indexed, already-sealed query proves a person performed a given constitutional act.
> **The verified catalog needs no new table, no new column, no new event, and no new clock.**

Where a form is not the right evidence (seatings, memberships, confirmations), the
**role-derivation fact query** is — `RoleService` computes all 30 roles from authoritative tables.

### ⚠ THE EARNER RULE — the actor on a form is not always the earner

**This is a structural property of the app, not an exception list.** Many constitutional forms are
filed *by one person about another*: a board member validates a candidate; an org agent grants an
endorsement to a candidate; a judge summons a juror. Reading `actor_user_id` alone would award the
achievement to the **wrong person** — the validator instead of the candidate.

Every catalog row therefore carries an explicit **Earner**, and there are exactly three values:

| Earner | Meaning | Resolution |
|---|---|---|
| **`self`** | the filer earns | `audit_log.actor_user_id` |
| **`subject`** | the act's *subject* earns, and it is a different person | named explicitly on the row — resolve through the record the act created |
| **`state`** | nobody "filed" it; a fact table already names the holder | the seating / membership / confirmation row |

**No catalog entry may be written without an Earner value.** That is what stops this class from
reappearing: the earner is not an annotation on the exceptions, it is a required field on every row.

*Found by review: `ACH-CAN-005` (candidacy validated) keyed to `F-ELB-002`, which the **board
member** files — so the achievement would have landed on the validator. Five entries were in this
class; all five are now `subject` and named.*

**Three standing rules for every trigger:**
- `rejected = false` — rejections are chained too; an achievement must never count one.
- **Electoral participation reads `ballot_envelopes`, never `ballots`** (§3).
- Presence of the row is the fact. Never read the payload.

---

## 1. The three scopes

| Scope | Store | Why |
|---|---|---|
| **Personal** | `achievements` (user-scoped, append-only, federates) | the person's own record |
| **Organization** | the **org profile**, as display chrome | IK:106 — *"confers no power — same CI-1 rail"* |
| **Jurisdiction / system** | `PublicRecordService::publish(kind: participation\|certification)` | IK:163 — *"public milestones only"* |

Already ruled, not invented here: `IK-civic-org-powers-and-record.md:170` — *"the metric's record
footprint is a separate immutable table + public milestone rows."*

> ⚠ **Never overload `user_id` with a jurisdiction or org UUID.** `achievements.user_id` is
> `NOT NULL` with **no FK** — deliberately, so append-any-verified federation can land achievements
> for identities absent locally. A non-person UUID there would federate a jurisdiction into a person
> column with nothing to catch it, and the partial-unique `(user_id, award_key)` would collide
> across scopes. There is no `jurisdiction_id` / `organization_id` column, and this design adds none.

---

## 2. Counts — where we actually are

| | Count |
|---|---|
| Earnable in code **today** (journey arcs; 11 live, 3 planned) | **14** |
| Ever earned, both boxes | **0** |
| **Catalog — personal** (92 `ACH-*` + the 14 arcs) | **106** |
| **Catalog — jurisdiction** | **26** |
| **Catalog — system / planet** | **9** |
| **TOTAL CATALOG** | **141** |
| — **server-verified** against a named source | **127** |
| — **tour ticks** (the journey arcs, labelled as such) | **14** |
| — **deferred** | **0** — Phase I completed 2026-07-25; every entry now has a live trigger |
| — of the verified, **⏳ awaiting an economy UI** | **12** |

**Earner split across the 91 personal `ACH-*` entries: `self` 48 · `state` 37 · `subject` 6.**
The 31 jurisdiction/system entries are earned by the **jurisdiction itself** — no personal award is
minted from any of them. The 13 tour arcs are `self`.

**So the honest state: 13 of 127 fire today, and 0 have ever been earned.** The gap is not engine
work — the engine, the ledger, the append-only trigger, the federation path and the badge UI are
built and tested. The gap is a catalog (§8): four small additive changes.

---

## 3. The electoral rail

`ballot_envelopes` and `ballots` are physically unlinked — no FK either direction — pinned by
`tests/Constitutional/BallotSecrecyTest.php`: `ballots` may hold **exactly nine columns**, its only
time is the **hour-truncated `cast_bucket`**, its PK is explicitly random v4 (an ordered UUID
*"would leak precise insert time and defeat `cast_bucket` truncation"*), and the only shared columns
are `['id','race_id','kind','referendum_question_id']` — *"a shared SCOPE is not a linking channel."*

**The one lawful "you voted" fact is a `ballot_envelopes` row.** Already indexed on `user_id`.

**PI-2's forgotten half — no abstention leak.** Envelopes are queryable per race and associations
are queryable, so *"did not vote"* is a trivial set difference.

> **Render rule:** an unearned achievement renders **identically to one that does not apply** —
> *"not yet earned"*, never *"did not vote"*, and never a greyed slot in a known-complete grid.
> No public streaks, no "N of M" counters, no completion percentage on the personal plane. Pinned §7.

---

## 4. PERSONAL CATALOG — 96 across 12 civic arcs (83 verified + 13 tour)

Arcs follow the **role progression the app implements** (R-01 → R-30). Every entry is
default-private (PI-4), grants nothing (CI-1), never enters a composite (PI-6).
**Tier:** `V` verified · `T` tour · `D` deferred. **Earner:** `self` / `subject` / `state`.

### Arc 1 — Becoming a citizen (R-01 → R-02 → R-03/R-04/R-05) · 7
| Code | Achievement | Trigger | Earner | Tier |
|---|---|---|---|---|
| `ACH-CIV-001` | Registered | `ref=F-IND-001` | self | V |
| `ACH-CIV-002` | Profile set up | `ref=F-IND-002` | self | V |
| `ACH-CIV-003` | Declared a residency | `ref=F-IND-003` | self | V |
| `ACH-CIV-004` | First location ping | `ref=F-IND-005` | self | V |
| `ACH-CIV-005` | **Residency confirmed — franchise unlocked** | `residency_confirmations.is_active` | state | V |
| `ACH-CIV-006` | Associated at every level, neighborhood to planet | association chain depth | state | V |
| `ACH-CIV-007` | Relocated and re-associated | second confirmed association after a lapse | state | V |

*`ACH-CIV-005` is the most important entry in the catalog — the exact moment Art. I rights attach.
Note it is **`state`, not `self`**: `F-IND-006` is filed by R-02 **or the system** on the CLK-05
threshold, so the confirmation row is the only correct source.*

### Arc 2 — The franchise (R-04) · 5
| Code | Achievement | Trigger | Earner | Tier |
|---|---|---|---|---|
| `ACH-VOT-001` | Cast a first ballot | **`ballot_envelopes`** `kind='ranked'` | state | V |
| `ACH-VOT-002` | Voted in a referendum | `ballot_envelopes` `kind='referendum'` | state | V |
| `ACH-VOT-003` | Voted at three different jurisdiction levels | envelopes × race jurisdiction depth | state | V |
| `ACH-VOT-004` | Voted in every race on one ballot day | envelopes per election day | state | V |
| `ACH-VOT-005` | Verified your own ballot by receipt | receipt-check hit (no hash stored) | self | V |

### Arc 3 — Voice: petition, square, testimony (R-05, R-03) · 7
| Code | Achievement | Trigger | Earner | Tier |
|---|---|---|---|---|
| `ACH-VOX-001` | Signed a petition | `ref=F-IND-010` | self | V |
| `ACH-VOX-002` | Created a petition | `ref=F-IND-009` | self | V |
| `ACH-VOX-003` | Your petition reached signature threshold | petition status transition | **subject** → petition creator | V |
| `ACH-VOX-004` | Your petition became a referendum | referendum linked to petition | **subject** → petition creator | V |
| `ACH-VOX-005` | Spoke in the public square | `ref=F-SOC-001` | self | V |
| `ACH-VOX-006` | Sealed testimony in a hall | `ref=F-SOC-002` | self | V |
| `ACH-VOX-007` | Filed a constitutional challenge | `ref=F-IND-016` | self | V |

*The square is **open** — `F-SOC-001` carries `roles => []`. Residency gates the testimony seal
(`F-SOC-002`), never square access.*

### Arc 4 — Standing for office (R-06 → R-07) · 6
| Code | Achievement | Trigger | Earner | Tier |
|---|---|---|---|---|
| `ACH-CAN-001` | Registered a candidacy | `ref=F-IND-011` | self | V |
| `ACH-CAN-002` | Published a campaign profile | `ref=F-CAN-001` | self | V |
| `ACH-CAN-003` | Requested an endorsement | `ref=F-CAN-002` | self | V |
| `ACH-CAN-004` | Endorsed by an organization (R-07) | `endorsements` `endorser_type='organization'` | **subject** → `candidate_id` holder | V |
| `ACH-CAN-005` | Candidacy validated by the board | `ref=F-ELB-002` → the validated candidacy | **subject** → candidacy holder | V |
| `ACH-CAN-006` | **Stood unendorsed and was seated** | seated with zero active endorsements | state | V |

*`ACH-CAN-004` and `ACH-CAN-005` are both filed by someone else — the org agent (R-23) and the
board member (R-08). Awarding on `actor_user_id` would decorate the endorser and the validator.*

### Arc 5 — Serving in a legislature (R-09 → R-10/R-11/R-12/R-13) · 15
| Code | Achievement | Trigger | Earner | Tier |
|---|---|---|---|---|
| `ACH-LEG-001` | Took the oath of office | `ref=F-LEG-001` | self | V |
| `ACH-LEG-002` | First attendance registered | `ref=F-LEG-002` | self | V |
| `ACH-LEG-003` | Introduced a bill | `ref=F-LEG-003` | self | V |
| `ACH-LEG-004` | Cast a floor vote | `ref=F-LEG-004` | self | V |
| `ACH-LEG-005` | **A bill you introduced became law** | law version traced to the bill | **subject** → bill introducer | V |
| `ACH-LEG-006` | Made a motion | `ref=F-LEG-007` | self | V |
| `ACH-LEG-007` | Entered a statement on the public record | `ref=F-LEG-006` | self | V |
| `ACH-LEG-008` | Ranked your committee preferences | `ref=F-LEG-010` | self | V |
| `ACH-LEG-009` | Seated on a committee (R-11) | `committee_seats` seated | state | V |
| `ACH-LEG-010` | Cast a committee vote | `ref=F-LEG-005` | self | V |
| `ACH-LEG-011` | Chaired a committee (R-12) | `committees.chair_member_id` | state | V |
| `ACH-LEG-012` | Acted as alternate chair (R-13) | `committees.alternate_member_id` | state | V |
| `ACH-LEG-013` | **Elected Speaker (R-10)** | `legislatures.speaker_id` | state | V |
| `ACH-LEG-014` | Cast a tie-breaking vote | `ref=F-SPK-004` | self | V |
| `ACH-LEG-015` | Voted on a supermajority act | floor vote on a 2/3 threshold act | self | V |

### Arc 6 — Executive (R-14/R-15/R-16/R-17) · 6
| Code | Achievement | Trigger | Earner | Tier |
|---|---|---|---|---|
| `ACH-EXE-001` | Seated on a delegated executive (R-14) | `executive_members` delegated principal | state | V |
| `ACH-EXE-002` | Elected to an executive committee (R-15) | executive `type=committee`, elected | state | V |
| `ACH-EXE-003` | Elected individual executive (R-16) | executive `type=individual`, elected | state | V |
| `ACH-EXE-004` | Seated as an executive advisor (R-17) | `executive_members` role=advisor | state | V |
| `ACH-EXE-005` | Issued an executive order | `ref=F-EXE-005` | self | V |
| `ACH-EXE-006` | Nominated a Board of Governors member | `ref=F-EXE-001` | self | V |

*R-17 exists because Art. III §3 seats the **top four runners-up** automatically. Losing an election
and still serving is a real civic outcome and deserves its own entry.*

### Arc 7 — Departments, boards & civil service (R-18/R-29/R-30) · 6
| Code | Achievement | Trigger | Earner | Tier |
|---|---|---|---|---|
| `ACH-BOG-001` | Seated as a department governor (R-18) | `board_seats` on `departments` | state | V |
| `ACH-BOG-002` | Implemented a department rule | `ref=F-BOG-001` | self | V |
| `ACH-BOG-003` | Filed a department report | `ref=F-BOG-002` | self | V |
| `ACH-BOG-004` | Took a 10-year civil appointment (R-30) | `appointments` + active term | state | V |
| `ACH-BOG-005` | Seated as administrative staff (R-29) | `appointments` on `admin_offices` | state | V |
| `ACH-BOG-006` | Published session minutes | `ref=F-SPK-009` | self | V |

### Arc 8 — Justice (R-19/R-20/R-21/R-22) · 12
| Code | Achievement | Trigger | Earner | Tier |
|---|---|---|---|---|
| `ACH-JUD-001` | Registered as an advocate (R-21) | `ref=F-IND-015` | self | V |
| `ACH-JUD-002` | Filed a case | `ref=F-IND-017` or `F-ADV-001` | self | V |
| `ACH-JUD-003` | Filed a motion | `ref=F-ADV-002` | self | V |
| `ACH-JUD-004` | Submitted evidence | `ref=F-ADV-003` | self | V |
| `ACH-JUD-005` | Filed a brief | `ref=F-ADV-004` | self | V |
| `ACH-JUD-006` | Summoned as a juror (R-22) | `jury_members` summoned | state | V |
| `ACH-JUD-007` | **Served on an empaneled jury** | `jury_members` empaneled | state | V |
| `ACH-JUD-008` | Seated as a judge (R-19/R-20) | `judicial_seats` seated | state | V |
| `ACH-JUD-009` | Assigned a panel | `ref=F-JDG-001` | self | V |
| `ACH-JUD-010` | Filed an opinion | `ref=F-JDG-003` | self | V |
| `ACH-JUD-011` | Made a constitutional finding | `ref=F-JDG-004` | self | V |
| `ACH-JUD-012` | **Applied a judicial remedy (Art. IV §5)** | `ref=F-JDG-006` | self | V |

*`ACH-JUD-006` is `state`: the summons is `F-JDG-002`, filed by a judge — the juror is named on the
`jury_members` row, not on the audit actor.*

### Arc 9 — Organizations & work (R-23..R-28) · 12
| Code | Achievement | Trigger | Earner | Tier |
|---|---|---|---|---|
| `ACH-ORG-001` | Registered an organization | `ref=F-IND-012` | self | V |
| `ACH-ORG-002` | Became an organization agent (R-23) | `organizations.agent_user_id` | state | V |
| `ACH-ORG-003` | Joined an organization (R-24) | `org_memberships` active | state | V |
| `ACH-ORG-004` | Registered as a worker (R-25) | `org_workers` active (countersigned) | state | V |
| `ACH-ORG-005` | Granted an endorsement as org agent | `ref=F-ORG-002` | self *(+ org-profile mirror)* | V |
| `ACH-ORG-006` | Ran a board election | `ref=F-ORG-003` | self | V |
| `ACH-ORG-007` | Ran a **worker** board election | `ref=F-ORG-004` | self | V |
| `ACH-ORG-008` | Owner-elected board seat (R-26) | `board_seats` `owner_elected` | state | V |
| `ACH-ORG-009` | **Worker-elected board seat (R-27)** | `board_seats` `worker_elected` | state | V |
| `ACH-ORG-010` | Board chair (R-28) | `board_seats.is_chair` | state | V |
| `ACH-ORG-011` | Transferred ownership | `ref=F-ORG-005` | self | V |
| `ACH-ORG-012` | Converted public↔private | `ref=F-ORG-006` | self | V |
| `ACH-ORG-013` | **Dedicated work to the public domain** | `cgc_ip_register` via `CgcIpRegisterService::dedicate()` | self | V |

*`ACH-ORG-013` added 2026-07-26. **Found by @lane-06**, who walked the `public-service` arc of
their playtest worksheet against this catalogue and noticed there were twelve organisation
achievements and none for the thing that makes a Common Good Corporation a CGC. Art. III §5 —
everything a CGC creates is universally and eternally in the public domain, with no mechanism
anywhere to privatise it — is arguably the sharpest clause in the constitution, and it had no
entry. Cross-checking two lanes' artefacts against each other is what surfaced it.*

*`ACH-ORG-005` confirmed on review and **renamed**. It is correctly `self` — the agent genuinely
performed the act — but the old title *"Your org endorsed a candidate"* read as though the
organization earned it. The org-scope view is the **org profile mirror** (display chrome, IK:106);
the candidate's side of the same event is `ACH-CAN-004`. One event, three correct surfaces, no
double-award: the ledger is idempotent on `(user_id, award_key)`.*

*`ACH-ORG-009` is Art. III §6 co-determination arriving in a person's life — the worker/shareholder
parity chain (CLK-13/14) is built and pinned.*

### Arc 10 — Running elections (R-08) · 5
| Code | Achievement | Trigger | Earner | Tier |
|---|---|---|---|---|
| `ACH-ELB-001` | Seated on an election board | `election_board_members` seated | state | V |
| `ACH-ELB-002` | Scheduled an election | `ref=F-ELB-001` | self | V |
| `ACH-ELB-003` | **Certified an election** | `ref=F-ELB-004` | self | V |
| `ACH-ELB-004` | Drew a district by hand | `ref=F-ELB-008` | self | V |
| `ACH-ELB-005` | Ordered a recount or audit | `ref=F-ELB-006` | self | V |

### Arc 11 — Federation & node operation · 2
| Code | Achievement | Trigger | Earner | Tier |
|---|---|---|---|---|
| `ACH-FED-001` | Your instance met a peer | peer record | state | V |
| `ACH-FED-002` | Ran a node | operator record | state | V |

*Copy must carry the live house line verbatim: **"Keeping it online buys no vote and no seat."***

### Arc 13 — Economy & work (Phases L+M) · 8 · ⏳ awaiting UI

**Added 2026-07-25 after lane 13 shipped L+M.** The engine and schema are built — 22 economy tables
+ 3 ledger tables, `app/Services/Economy/*` — so these are *derivable today*. They are marked
**⏳ awaiting UI** because there are **no economy routes, no `Pages/Economy`, and no registered
economy surfaces**: a player cannot yet perform these acts in the app.

> ### ⚠ THE AGENCY/CONDITION RULE — new, and it governs this whole arc
>
> Every economy table is **local-only** (no `source_server_id`): `ubi_receipts`, `tax_filings`,
> `market_transactions`, `economic_accounts`, `ubi_disbursements`. **But `achievements` federates.**
> So an achievement derived from a private financial record would **export that record's existence
> across instances** — the `ballot_envelopes` lesson, applied to money.
>
> *"Received your first stipend"* federating tells a remote instance that this person receives
> public assistance where they live. That is a materially sensitive disclosure.
>
> **The rule: the personal plane records what you DID as a civic actor, never what you RECEIVED or
> what your economic condition is.** Giving help is an act; needing help is a condition.
>
> **The schema already agrees.** `assistance_requests` carries a **`privacy`** column on the
> *request* and none on the *response* — the build already treats needing help as sensitive and
> offering it as ordinary. This rule follows the code rather than overriding it.
>
> Consequence: no personal achievement exists for receiving a stipend, holding a balance, filing
> for assistance, or any account milestone. Those become **jurisdiction** milestones (§5) where
> they carry no person.

| Code | Achievement | Trigger | Earner | Tier |
|---|---|---|---|---|
| `ACH-ECO-001` | Filed a tax return | `tax_filings` (existence only, never amount) | state | ⏳V |
| `ACH-ECO-002` | Voted on a budget | floor vote on a budget act | self | ⏳V |
| `ACH-ECO-003` | Introduced a budget | budget authored by you | **subject** → budget author | ⏳V |
| `ACH-ECO-004` | **Answered a neighbour's request for help** | `assistance_requests.responder_account_id` | state | ⏳V |
| `ACH-ECO-005` | Listed something in the marketplace | `marketplace_listings` | state | ⏳V |
| `ACH-ECO-006` | Completed a trade | `marketplace_orders` settled | state | ⏳V |
| `ACH-ECO-007` | Posted work | `work_postings` | state | ⏳V |
| `ACH-ECO-008` | Registered a joint ledger with someone | `joint_ledger_parties` | state | ⏳V |

*`ACH-ECO-004` is the arc's centrepiece and the rule in miniature: **the responder earns, the
requester does not.** Mutual aid is celebrated from the giving side only — the receiving side is a
private condition and the schema's own `privacy` column says so.*

*`ACH-ECO-001` records **that** you filed, never **what** you filed. Amounts never enter a trigger.*

### Arc 12 — The guided tour (the 14 journey arcs) · 14 · tier T
**`become-a-resident`** · `election` · `committee-session` · `bill` · `court-case` · `start-org` ·
`board-meeting` · `form-a-group` · `petition-to-referendum` · `public-service` ·
`two-governments` · `budget` · `mutual-aid` · `stipend-and-tax` — **all 14 live** as of 2026-07-26.

> ### ✅ RESOLVED 2026-07-26 — all three economy arcs are LIVE
>
> **@lane-13 shipped `F-IND-022/023/024` (`72fdd95`) and the gate cleared the same hour.** The
> three services were always built; what was missing was a constitutional door, so a player could
> read the economy and not act in it. All 14 catalogued arcs are now live.
>
> The history below is kept because the *reasoning* outlived the decision — and because the gate
> moved once, which is the part worth remembering.

> ### How it went — the gate MOVED once before it cleared
>
> The original gate was *"when the page a player reaches is the intended one."*
> **@lane-06 shipped those pages (`82861e9`) — and then told me my gate was wrong**, which was
> the right call and better information than I had.
>
> **Economy v1 is READ-ONLY by @lane-13's own published contract.** A player can *see* the ledger,
> wallets, stipend receipts and assistance requests, and can *do* almost none of it. Measured by
> lane 6 against their own screens:
>
> | Arc | Steps a player can actually perform | |
> |---|---|---|
> | `stipend-and-tax` | Stipend run ✅ · Your receipt ✅ · Tax filing ❌ · Public ledger ✅ | 3 of 4 |
> | `budget` | Revenue ✅ · Budget bill ❌ · Appropriations ⚠ · Disbursement ❌ · Ledger ✅ | ~2 of 5 |
> | `mutual-aid` | Post ❌ · Respond ❌ · Coordinate ❌ · Resolve ❌ | **0 of 4** |
>
> **All three stay planned**, including `stipend-and-tax`, where lane 6 reasonably recommended
> flipping. Two reasons:
> 1. Its one unreachable step is the **only step that is an action** — the other three are things
>    you look at. An arc where the single thing you *do* cannot be done is not a walkable arc.
> 2. I could make it flippable by dropping the tax-filing step. **I am not going to.** That arc
>    teaches *"the money between a person and their government"* — a two-way relationship. Trim
>    the tax step and it teaches that government pays you, which is half the lesson and the wrong
>    half. **Trimming a lesson to fit the build is backwards**; the arc is right and the app is
>    not ready for it yet.
>
> **The real gate, precise and checkable: the write forms `F-IND-022/023/024`.** When a player can
> post an assistance request, file a return and move money, all three flip together — one word per
> arc. Not before.

> **`become-a-resident` added 2026-07-26 — a gap @lane-06 found, and a real one.** Every other arc
> **assumes you are already a resident**, but residency is the prerequisite for *every* right in
> the constitution — voting, standing for office, sealing testimony, jury service — and it is the
> first thing a new player hits. There was no arc for it, so lane 6 had to write their playtest
> worksheet's opening section from scratch. Steps: Register → Declare where you live → Presence
> confirms → **Residency confirmed** → Rights switch on. Its verified counterparts are
> `ACH-CIV-001..005`: **the arc explains the path, the achievements record that it was walked.**

Self-reported and **labelled as walkthroughs in the UI**. They keep their existing keys and their
value — they explain how the world works — and stop borrowing the verified tier's credibility.

---

## 5. JURISDICTION CATALOG — 22 milestones (Plane B, no `user_id`)

Published through `PublicRecordService::publish()`, k-anon floored, never person-attributed.
Earner is always the **jurisdiction**; no personal award is minted from any of these.

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
| `ACH-JUR-023` | First budget enacted | `budgets` enacted ⏳ |
| `ACH-JUR-024` | First stipend run completed | `ubi_disbursements` ⏳ |
| `ACH-JUR-025` | First revenue stream established | `revenue_streams` ⏳ |
| `ACH-JUR-026` | **First mutual-aid request answered** | `assistance_requests` resolved ⏳ |

*The four economy milestones (⏳) carry the **jurisdiction**, never a person — which is exactly
where the receiving side of the agency/condition rule (Arc 13) safely lives. "This place ran its
first stipend" is a civic fact; "this person received one" is not ours to publish.*

*`ACH-JUR-022` deliberately celebrates the **lapse**, not the declaration — the constitutional
achievement is the 90-day ceiling holding.*

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
| `ACH-SYS-007` | Jurisdiction coverage % | `legitimacy_snapshots` | V |
| `ACH-SYS-008` | Earth reach milestones (25/50/75/100) | `LegitimacyService::reachRatio()` | V |
| `ACH-SYS-009` | Every continent represented | association geography | V |

**Tier D is now empty — @lane-03 completed Phase I on 2026-07-25.** `LegitimacyService::reachRatio(?int $verified, ?int $population, bool $suppressed)` is live and static, `legitimacy_snapshots`
carries `ratio_micro / state / suppressed`, and the nightly job runs with a real `K_ANON_FLOOR`.
**All 139 entries now have a live trigger source.**

> ⚠ **The suppression rule, attached because publishing would otherwise defeat it.** Lane 3's
> design does **complementary** suppression — hiding one cell is provably insufficient when
> siblings let you derive it, so a parent is suppressed when its children's disclosure would
> reconstruct it. A reach milestone published from a suppressed or sub-floor snapshot would
> **re-disclose exactly what the suppression exists to hide**.
>
> Therefore `ACH-SYS-007/008` publish **only** where `suppressed = false` **and** `state` is
> measurable. A jurisdiction that never reaches a publishable reach simply never earns the
> milestone — and, per PI-2's no-abstention-leak half, **the absence must render identically to
> "not yet reached", never as "suppressed"**. Saying *why* a milestone is missing would leak the
> very population fact the floor protects.

---

## 7. Rails & pins

| Rail | Satisfied how |
|---|---|
| **CI-1** no governance advantage | No entry mints a role or clock — `R-01..R-30` / `CLK-01..CLK-21` untouched. Roles are **derived, never stored**; every gate funnels through `ResolvesRoles`→`AttestationGate`→`RoleService`. |
| **CI-2** never a role | Same mechanism. *(CI-2 is used for two different things in the corpus — Phase O's `scale_demo` rule and this one. Disambiguated; flagged @lane-07.)* |
| **CI-3** no economic reward | Nothing purchasable. Per @lane-13: money grants no governance advantage either; UBI eligibility is active residency association only. |
| **CI-6** authoritative writer only | Plane-B milestones ride the existing `onOneServer` + `LeaderProbe::isPrimary()` rail. |
| **PI-1** no individual leaderboard | Plane B has no `user_id` by construction; personal entries are never ordered against another person. |
| **PI-2** envelope not ballot, no abstention leak | §3, including the render rule. |
| **PI-3** k-anon floor | Every Plane-B count suppressed below the floor. |
| **PI-4** default private | Visibility is a personal setting — **zero new F-forms**. |
| **PI-6** no composite score | **No sum, percentage, rank or level — not stored and not computed at view time.** |

**Pins** (`tests/Constitutional/`, opener `CONSTITUTIONAL PIN — <Article> (<rule>)`, closer
*"fix the edit, never the test"*) — **announce before touching any existing pin:**

1. **`AchievementNonInterferenceTest`** — CI-1 firewall in the shape
   `IK-civic-org-powers-and-record.md:86` specifies: source-grep that `App\Domain\Powers\*` and
   `RoleService` import no achievement symbol, plus `RoleService::derive()` byte-identical with and
   without ledger state.
2. **`AchievementEnvelopeOnlyTest`** — the **reader** pin mirroring the existing writer pin: no file
   in the achievement namespace contains `ballots`, `payload_encrypted`, `ballot_hash`, `salt`,
   `BallotReceipt` or `decryptForCount`. Model on `BallotSecrecyTest:186-245`.
3. **`AchievementNoCompositeScoreTest`** — PI-6 non-existence.
4. **`AchievementSoftGateTest`** — quote `JourneyService`'s own sentence: *"journeys nudge, they
   NEVER block."*
5. **`AchievementNoAbstentionSignalTest`** — the §3 render rule.
6. **`AchievementEarnerResolutionTest`** — **new**: every catalog entry declares an Earner, and no
   `subject`-earner entry resolves through `actor_user_id`. This is the pin that keeps §0's class
   from reappearing.

Register all six in `FuturePhasePlaceholdersTest` — *"the constitutional suite IS the roadmap."*

---

## 8. What has to change in code

Four additive changes (operator-approved 2026-07-25). Both tables hold zero rows, so all are free:

1. **`achievements.journey_id` → `award_key varchar(64)`** — one additive migration; holds a journey
   arc key **or** an `ACH-*` code. Keeps one ledger, one append-only trigger, one federation path,
   one idempotency index; the 13 arc keys stay valid untouched.
2. **Store an i18n key, not a denormalized English title** — the ledger is immutable and federates,
   so a title written today can never be rewritten. **@lane-05 owns the translating**; expect ~127
   achievement titles.
3. **`AchievementCatalog`** — the code registry the charter specifies (*"not a table"*):
   `ACH-*` → `{title_key, scope, tier, earner, trigger}`.
4. **`AchievementService`** — resolves triggers and awards **through the existing
   `JourneyService` seal path**. Zero new F-forms, no CLK-22, no new audit module.

Schema ordinal block for this lane if anything beyond the approved migration is needed: **000060+**.
Audit summaries append **last**.

---

## 9. Decisions carried

### 9.1 Keying — one ledger, generalised key
§8.1. The deliberate **absence of an FK to `users`** stays: federated achievements legitimately
arrive for users who do not exist locally.

### 9.2 Verified vs self-reported — resolved per entry
**112 verified** entries each name a proving source and an earner; the **13 journey arcs** stay tour
ticks, labelled as such.

### 9.3 `earned_at` — REVERSED 2026-07-25: the full timestamp stays

An earlier ruling had `earned_at` coarsened to `awarded_on DATE` pre-launch. **The operator has
reversed that ruling and it is settled the other way:** *"Keep the timestamp. What does it matter?
The timestamp when you got an achievement, no one cares."*

Recorded openly because the trade was made with the consequence stated plainly, not overlooked:
a federated achievement carries its **exact minute**, so a remote instance can see *when* a
pseudonymous person acted. The elections engine deliberately destroys that signal on the ballot side
(`cast_bucket`, hour-truncated, pinned by `BallotSecrecyTest`); the achievement ledger will not.
**Accepted. Not to be re-raised.**

Everything downstream stands unchanged — the ledger keeps `earned_at timestamptz`, `audit_seq` and
`source_server_id` exactly as built, and change #2 in §8 (the i18n key) is unaffected.

### 9.4 A charter divergence, flagged not silently resolved
The roadmap says achievements get no `audit_log` module (*"it reuses records"*), but
`JourneyService:186` already appends `module: 'journeys'`. **Recommend keeping `'journeys'`** and
correcting the charter text. @lane-07 owns that doc.

---

## 10. Open

- **`PI-5` does not exist anywhere in the repo.** PI-1/2/3/4/6 all appear. Its source
  (`achievements-legitimacy.md`) is on the uncommitted `explore/achievements` branch.
- **The v3 mockups dropped the CI-*/PI-* citations** v2 carried — the coded rails survive only in
  `mockups/v2`. Flagged so they are not lost when v2 retires.
- **Tier D** (2 codes) waits on @lane-03.
