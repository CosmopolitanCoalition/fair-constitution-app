# K2 — Curriculum Map

**Owner:** Lane 15 (Phase K-2) · **Deliverable:** D-16c · **Status:** map + template, 2026-07-25
**Scope boundary (operator decision, 2026-07-25):** *spec + map now, authoring on approval.* This
document delivers the extraction, the reconciliation, the coverage worklist and the authoring
template. **It authors no lessons.** §9 is the template awaiting the operator's word.

---

## 1. Correction: "549 labels" is wrong

My own opening prompt, `PHASE_LEDGER_A_TO_O.md:86` and `DELTA_DELIVERABLES_MASTER.md:372` all
describe The_Chart as *"549 labels."* **That figure is unsourceable** — no subset of the file
yields it (distinct labels = 665; labelled cells = 902; distinct ≥3 words = 374; distinct len>10 =
514).

**The truth, and it is better news:** the curriculum backbone is a small, cleanly delimited island
inside a much larger scratch canvas.

| Measure | Count |
|---|---|
| `mxCell` elements in the file | 1,676 (1,065 vertices, 609 edges) |
| Cells carrying a non-empty label | 902 (665 distinct) |
| **The curriculum grid** | **239 cells = 34 headers + 205 nodes** |
| — Units | **8** |
| — Lessons | **66** |
| — Chapters | **131** |

`docs/The_Chart.drawio` and `docs/extracted/the_chart.xml` are **byte-identical** (md5 match) — the
"extraction" step is a straight copy, so no regeneration is needed to work on it.

**@lane-07:** two master docs carry the 549 figure. Correct to **205 curriculum nodes (8/66/131)**.

---

## 2. The extraction recipe (deterministic, reproducible)

The canvas is **flat** — 1,673 of 1,676 cells have `parent="1"`. There is **no nesting to walk**.
Structure is encoded entirely by **geometry plus a style fingerprint**:

1. **Find the headers.** Header cells are the only cells whose `style` contains `shape=process`
   *and* whose normalized label ends in `" - Units"`, `" - Lessons"` or `" - Chapters"`.
   Exactly **34** qualify (1 Units + 8 Lessons + 25 Chapters), all `w=480, h=40`.
   *(48 cells contain `shape=process`; the other 14 are the Coalition-programs area at x ≤ -3460.)*
2. **Normalize labels before matching.** Values carry HTML and entities —
   `value="&lt;b&gt;Preamble &lt;/b&gt;-&lt;b&gt; &lt;/b&gt;Lessons"`. Strip tags; decode
   `&nbsp; &amp; &lt; &gt; &quot; &#39;`; collapse whitespace; trim.
3. **Band by header `y`** — the 9 distinct values
   `[-2999.5, -2839.5, -2679.5, -2359.5, -2119.5, -1879.5, -1679.5, -1479.5, -1319.5]`.
4. **Column by header `x`** — a content cell belongs to the header in its band whose span
   `[hx, hx+480)` contains its `x`. **Result: 205/205 assigned, 0 unassigned, 0 ambiguous.**
5. **Reading order is `y` then `x`.** There is no other ordering signal (see §3).
6. **Tolerate the operator's punctuation drift** when joining Chapter headers to Lessons:
   `^([IVX]+)\s*\.\s*(\d+)\s*[:.]?\s*(.*?)\s*-\s*Chapters$` against `^(\d+)\s*[:.]\s*(.*)$` —
   real headers include `II.9. Governments`, `IV. 5. Resolving Questions of Law`,
   `III.4:Boards of Governors` (missing space). This regex pair joins **25/25 cleanly**.
7. **Never key by label.** Uniqueness is 204/205 — `"2: Basic Duties"` appears under both Unit II
   and Unit V. Generated ids must be `(unit, section, ordinal)`-scoped.

**What is *not* a signal, verified:**
- **No `fillColor` anywhere in the grid.** Colour is used only on the STV worked example and party
  diagrams. All 131 Chapters share one byte-identical style string.
- **`fontStyle=1` (bold) is not a "has children" flag** — `II.2: Basic Duties` is non-bold yet owns
  13 chapters.
- **Zero of the 609 edges touch any curriculum cell.** There is no prerequisite graph.

---

## 3. The 8 Units (verbatim, in chart order)

`Preamble` · `I: Individuals` · `II: Legislatures` · `III: Governments` · `IV: Judiciaries` ·
`V: Jurisdictions` · `VI: Constitutional Order` · `VII: Entrenchment & Ratification`

66 Lessons hang beneath them; 131 Chapters hang beneath 25 of those Lessons.

---

## 4. Reconciliation against the Template — near-exact

Checked against `docs/extracted/fair_constitution.md`: **25 of 28 comparable groups match the
Template's clause titles verbatim and in order.** Article I's 24 chart Lessons are the Template's
24 Article I clause titles, verbatim. 30 of 31 Lesson labels in Units II–VI match their Template
**Section** title verbatim.

**Three deltas, all benign:**
1. **II.6 Referendums** — same four titles, chart swaps items 3 and 4.
2. **The Preamble's 8 Lessons are original pedagogy** — the Template's Preamble is a single quoted
   paragraph with no clause titles. This is authored teaching structure, not extraction.
3. **Art. VII's 3 Lessons are paraphrases**, 1:1 in order.

**One real coverage gap:** the six single-clause Sections (II.1, III.1, IV.1, V.1, V.8, VI.1) stop
at the Lesson leaf and **drop the Template's lone clause title** — e.g. *"Dissolution of
Intermediary Jurisdictions"*, *"Embedding of A Fair Constitution"*. Six clauses exist in the
Template with **no chart node**. Authoring must add them rather than inherit the omission.

---

## 5. A second curriculum artifact nobody recorded

The same file contains the **"Cooperation at Scale / A Path For Us"** indented outline — 73
labelled cells, 6 indent levels, at x ∈ [-4240, -4040].

- **Indentation is the authoritative signal**: `level = floor((x - (-4240)) / 40)`. **Use `floor()`,
  not `round()`** — three cells are off-grid from hand-dragging (`Outreach`, `Legal`,
  `Communications`, `Field Operations`), and `round()` wrongly promotes two of them a level.
- **Its 192 edges are NOT a tree** — 67 nodes have in-degree > 1 (max 13), the graph has cycles,
  and only 77 of 192 edges agree with indentation. They are a semantic cross-reference web.
- **Its "Cosmopolitan Template" branch has exactly 8 level-4 children mapping 1:1 onto the 8
  Units.** This is the **course-level wrapper** for the Unit grid — the missing outer ring.

---

## 6. There are no weights in the chart — the real weight is a formula

The charter and my prompt both say "Units → Lessons → Chapters **with weights**." **There are no
weights.** An exhaustive label scan finds exactly one `weight` match — *"Entrenchment of
Proportions and Basic Weights"*, which is Art. VII constitutional substance, not a weighting. Zero
matches for credit / hours / points / xp / level / quiz / badge. The 62 percentage labels belong to
a separate STV/RCV/FPTP worked example at x ≈ -5560.

**The real per-topic weight lives in `Topic_Knowledge.xlsx`**, and it is mechanical:

```
Question Count  =  MAX( video_minutes / 5 , 3 )
```

Verified by parsing `docs/Topic_Knowledge.xlsx` directly — **all 54 content rows, zero mismatches**.

> **Parse the .xlsx, not `docs/extracted/topic_knowledge.md`.** Multi-line transcript cells destroy
> row boundaries in the markdown render, and row-boundary errors are how the wrong totals below got
> into circulation.

**The verified item-bank arithmetic:**

| Bucket | Rows | Subjects | Questions |
|---|---|---|---|
| Template-proper (maps 1:1 onto the 8 chart Units) | 22-29 | 8 | **45** |
| Principles · electoral systems · movement | 1-21, 30 | 22 | **87** |
| Site / account procedural (directory, login, profile, shop…) | 31-54 | 24 | **72** |
| **Total** | | **54** | **204** |

Raw sum = 197.8067; **per-row ceiling = 204**.

> ### ⚠ The single most expensive misreading available in this lane
> **"Question Count" is a FORMULA, not a question bank.** The workbook has one sheet, no hidden
> columns, no comments — **no question text, no answer keys, no distractors, no rubrics
> anywhere.** The actual quizzes live in the WordPress LMS on the site, not in this file.
> **Any plan that prices question authoring at zero because "Question Count exists" is wrong by
> the entire item bank.**
>
> **The real authoring target is 132 items** (204 − the 72 site/account questions, which are
> discardable: the app has no shop, no WordPress account pages, no affiliate report). Of those,
> **45 are the irreducible Template core**. Every number in this box was recomputed from the
> workbook this session — earlier figures of 126, 198 and "~96" in circulation are all wrong.

Adopt `max(minutes/5, 3)` as a **sizing rule** for new app lessons — the Learn mockup already
renders and rounds it — but replace the flat floor with a value weighting so trivial lessons do
not consume budget.

---

## 7. The Topic_Knowledge corpus — the philosophy half

67 rows / **54 real lessons** / **9:27:35** total runtime.

**Correcting a peer measurement:** the 13 rows without media are **not missing lessons** — they are
Google-Drive **continuation segments** of the six longest videos, order-numbered `X.1/X.2/X.3`
(Introduction 2, Cosmopolitan Template 2, Global Community Reform 2, Electoral Systems Compared 2,
Legislatures 3, Jurisdictions 2). Every row with a Length has both a transcript and web copy —
zero mismatch.

- **Rows 22-29 map 1:1 onto the chart's 8 Units** (Preamble, Individuals, Legislatures,
  Governments, Judiciaries, Jurisdictions, Constitutional Order, Ratification). That is the join
  between the two artifacts, and it is free.
- **24 of the 54 subjects (rows 31-54) are site/account procedural** — Coalition Directory, Donors
  List, Discord, Shop, Login, Manage Account, Edit Profile, Role History, Affiliate Report…
  Achievement and lesson mapping must hang off the other **30**, or the catalog fills with
  WordPress-account trivia for pages the app does not have.
- **Zero subjects cover the app.** Verified exhaustively (governance app / CGA / open source /
  GitHub / Android / iOS / install / sandbox / World of Statecraft — no hits).
- **Reuse is gated by a rewrite pass.** The six faction-heaviest lessons are exactly the six K-2
  most wants. Budget a rewrite, not a copy — see `K2_FACTION_CORRECTION.md`.

**⚠ Naming collision, flagged so no auto-match mis-teaches a hardened mechanism:** Topic_Knowledge
subject 11 "Committees" teaches the **Coalition's 8 organizing committees**. Art. II §4
"Committees" are a **legislature institution** with a rank-order placement algorithm. Title-matching
these would teach the wrong thing about a pinned mechanic.

### The practice half already exists too

`mockups/v3/assets/js/fixtures-learn.js` holds a **20-video app-native spine** (residency, ballots,
floor procedure, bill-to-law, cases, orgs, square, units, nodes, translation) **with SOPs and
knowledge checks**. So the shape of K-2's curriculum is:

> **Topic_Knowledge = the PHILOSOPHY half · fixtures-learn = the PRACTICE half.**
> Neither is a course; together they are the two halves the Learn Area joins.

---

## 8. The coverage worklist — where the education actually has to land

The socket is three parts, **two built and one stubbed**:

| Part | What it is | State |
|---|---|---|
| **Server** | `config/cga/surfaces.php` — **70** records, **8 keys each** (`title, module, nav, roles, workflows, forms, clocks, citation`) | built |
| **Client** | `resources/js/registry/surfaces.js` — hand-maintained, mirrors **nothing** from PHP | built, drifting |
| **Drawer** | `LearnFlyout.vue` — about line + form chips + citation + a disabled stub | stubbed |

**Measured coverage (re-run the check in §8.1 to reproduce):**

| Education half | Source today | Coverage |
|---|---|---|
| **Civic** ("the constitutional why") | `surface.citation` + per-form `citation` + `roles` + `clocks` | **70/70 as a citation · 0/70 as a lesson** |
| **App** ("how to use this page") | `LEARN_BY_SURFACE` → `LEARN_BY_MODULE` → generic string | **5/70 bespoke (7.1%)** · 0/70 with an SOP · 0/70 with a video |

The five bespoke surfaces are `system/audit-chain`, `system/public-records`, `elections/detail`,
`system/clocks`, `system/amendments`. Everything else falls back to one of 12 module paragraphs or
the literal string `'A quick guide to this screen.'` — **silently**, which is why the gap has gone
unnoticed.

Surfaces by module: civic 14 · legislature 12 · electoral 9 · judiciary 7 · executive 6 ·
operator 6 · organizations 6 · system 6 · jurisdictions 4.

### 8.1 Live drift, measured

Running the cross-check I recommend making permanent:

- ✅ All 5 `LEARN_BY_SURFACE` keys are real surface ids.
- ❌ **3 `LEARN_BY_MODULE` keys — `federation`, `support`, `social` — have no surface of that
  module.** The JS registry carries Learn copy for modules the server registry does not know.
- ✅ Every PHP module has a `LEARN_BY_MODULE` entry.

`SurfaceMeta::ids()` **already exists and is unused** — it is exactly the hook for this test.

### 8.2 The 19 pages outside the socket

All of Setup, Auth, Dev, Home and Invite sit outside AppShellV2 and therefore outside the Learn
drawer entirely. **The founding wizard is the highest-value education surface in the product and
currently has none.** Either those pages opt into V2 (lane 06's shell decision, operator-gated) or
onboarding education needs a second delivery vehicle. Flagged, not assumed.

---

## 9. The authoring template — MIXED App + Civic

**The design contract already exists and should not be reinvented.** The mockups' `sopPanel`
primitive is literally app-education + civic-education in one shape, already CSS'd
(`.sop`, `.sop-steps`, `.sop-do`, `.sop-detail`) and already committed to in three mockup pages:

```
step := {
  do:     "<the app action the player takes on this screen>",   ← APP education
  detail: "<how it actually works underneath>",                  ← APP education
  cite:   "<the constitutional basis>"                           ← CIVIC education
}
```

`Ui/LearnMore.vue` (named in the charter) should almost certainly **be** this component, fed
per-surface. Zero new CSS, per the roadmap's own constraint.

**Per-surface authoring unit — the thing awaiting approval:**

| Field | Source | Notes |
|---|---|---|
| `learn` | authored | one plain-language sentence: what this screen is about |
| `howto` | authored | 3-6 `sopPanel` steps (`do` / `detail` / `cite`) |
| `citation` | **exists** | already on all 70 records — reused, never re-authored |
| `forms[].citation` | **exists** | per-form article basis, already resolved by `SurfaceMeta::for()` |
| `where_this_fits` | `surface.workflows` | see §10 |

**Recommendation:** move `learn` and add `howto` **server-side onto the surfaces.php record**.
`SurfaceMeta::for()` already flows the whole record to every page and uses `??` defaults, so added
keys are purely additive and nothing breaks — and it kills the drift in §8.1 at the source rather
than testing around it.

**Authoring volume, stated honestly: 70 × 2 = 140 items.** The component work is small; the copy work is the
deliverable. **That authoring does not start until the operator approves this template.**

---

## 10. The block that was designed, CSS'd, and never built

`LearnFlyout.vue` was ported from the mockup's `hydrateLearnDrawer` and **dropped two blocks**:

1. a multi-track guide video;
2. **"Where this fits — the process(es) this screen is part of"** — WF step-in-flow cards.

**Their CSS already ships unused** in `components-v2.css` (`.ld-video`, `.ld-flow*`).

This matters because every surface record **already carries `workflows`** (71 distinct WF-* codes
across the 70 surfaces). The data and the styling both exist; only the markup is missing. This is
the cheapest large education win available and it is where the WF-* walkthroughs finally get a home.

**Unblocking "Full lessons"** is one line — swap the disabled `<span>` at `LearnFlyout.vue:63-66`
for `<Link href="/learn">` — but registering `learn/learn-home`, `learn/lesson`, `learn/guides` in
`surfaces.php` is a **prerequisite**, because `SurfaceMeta::for()` **throws** on unknown ids.

⚠ Also fix the stub's copy: it reads **"Planned · Phase 7"**, a *third* phase vocabulary alongside
the audited A–O map and CLAUDE.md's 0–5 build log. Flagged to @lane-07.

### 10.1 ⚠ A LIVE DEFECT: the sidebar renders an enabled link to a 404

Found while measuring the socket, verified three ways, and it is this lane's to own because the
Learn surface is K-2:

- `resources/js/Navigation/nav.js:32` — `{ id: 'learn', href: '/civic/learn', phase: 'D' }`.
- Its own comment (`nav.js:29-31`) explains the intent: *"No /civic/learn route exists yet —
  flagged Planned (**phase D now that C is live**) so the sidebar never renders a dead link; flip
  back to 'A' when the Learn surface ships."*
- `app/Http/Middleware/HandleInertiaRequests.php:73` — `'phasesLive' => ['A','B','C','D','E','F','K']`.
- `routes/` — **no `/civic/learn` route exists.** Zero matches.

**The mitigation expired.** Phase D was chosen precisely *because it was not live*; D has since
shipped, so the item now renders as a normal enabled link to a route that 404s. This is a
silent-expiry bug — correct when written, broken by an unrelated change, and invisible to every
test.

**Two honest fixes**, either acceptable, and the choice belongs with the Learn build:
(a) re-flag to an unshipped phase until `/learn` exists — the same trick, and it will expire again;
(b) ship the route (§10's one-line unblock) and flip the item to `'A'`.
**Recommendation: (b)**, and until then (a) as a stopgap. Per the standing screenshot rule, the
fix is not done without an after-shot of the sidebar. @lane-06 — this is your markup; flagging
rather than patching, per lane discipline.

---

## 11. Two teaching gaps that are constitutional risks

Not content gaps — risks, because they are the two things most likely to be taught wrong:

1. **Residency.** It is the single gate to Art. I's absolute voting and candidacy rights, and it
   is mentioned **once** in the entire 54-lesson corpus. There is no source narration to lean on;
   this must be authored from the Template.
2. **The seat-budget cascade / cube-root sizing.** **Zero** mentions corpus-wide. This is precisely
   why the seat rule is taught wrong (see `K2_FACTION_CORRECTION.md` §2) — it has never been taught
   at all.

### 11.1 All three corrections bind specific Chart nodes

`K2_FACTION_CORRECTION.md` is not just for lanes 09-12 — it binds this curriculum, because the
Chart will generate lessons directly on top of the stale models. The nodes that must carry a
correction rather than inherit the error:

| Chart node | Correction | Rule to teach |
|---|---|---|
| V.3 *"Minimum and Maximum Number of Representative Seats"* | §2 seat rule | 5-9 bounds a **district**; legislatures scale by cube root |
| V.3 *"Apportionment of Representative Seats"* | §3 Webster | the giant-cascade — **no** Webster / Sainte-Laguë / largest-remainder |
| II.8 *"Subdivision of Legislatures"* | §2 seat rule | subdivision adds **districts**, it does not cap a legislature |
| II.2 *"Establish Proportional Voting Systems"* | §1 factions | STV/Droop matches preferences at the **individual** level |
| II.4 Committees (all 13 chapters) | §1 factions | faction-independent placement, normalised-quota tie-break |

⚠ **`CLAUDE.md:112-113` still reads `legislature_min_seats 5 / legislature_max_seats 9`** — the
superseded framing. The curriculum will be read against CLAUDE.md by anyone building from it.
**@operator @lane-07:** that table is the upstream source of the seat-rule error and is worth
correcting at the root.

---

## 12. Translation payload (for @lane-05)

Measured, English, before any new app lessons:

| Corpus | Words | Characters |
|---|---|---|
| Video transcripts | 92,834 | 509,559 |
| Web copy | 39,069 | 272,172 |
| **Total** | **131,903** | **781,731** |
| — constitutional-content subset | 61,036 | — |

At the mockups' 24 curated languages ≈ **3.17M words** of MT output; at the marketing site's
claimed 77 languages ≈ **10.2M**. The new app-native curriculum lands **on top of** this.

**Free leverage:** Topic_Knowledge's `Subject` column and the mockup player's
`{Subject}-{Language}.{ext}` track naming are the **same key space** — a K-2 asset manifest can
unify existing Coalition videos and new app videos under one identifier scheme with no translation
table. @lane-10 @lane-11, this is your naming contract meeting mine.

---

## 13. What happens next

1. **Operator approves (or edits) the §9 authoring template.** Nothing is authored before that.
2. Then: the 70 × 2 authoring pass, ordered by module traffic, delivered in waves.
3. In parallel and independent of authoring: the §8.1 drift test, the §10 "Where this fits" block,
   and the `learn/*` surface registrations.
4. The six-course LMS grouping already shipped publicly (Cosmopolitan Coalition Explained · Focus
   Areas · PRCV · Cosmopolitan Template · Initiatives · Getting Started). K-2 tracks that diverge
   from it need a stated reason — @operator, that is a call for you and the site chats, not for me.
