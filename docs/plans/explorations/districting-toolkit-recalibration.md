# Districting Toolkit & Autoseed Recalibration

*Exploration design — CGA Phase G-adjacent · branch `explore/jurisdiction-maps` · author: districting session*

> **Status:** exploration. Sections marked **SILENT** are places A Fair Constitution
> (Cosmopolitan Template) prescribes an *outcome* but no *mechanism*. Every mechanism this
> document proposes is **new policy** that an instance's founders (at bootstrap) or its
> legislature/election board (Art. II §2) must author through the normal constitutional
> process. None of it is an immutable rule. Where the Template *does* bind (the seat band,
> STV/Droop, equality, contiguity, supermajority, ballot/location privacy), this document
> treats it as a hard gate, never a tunable.

---

## 1. Constitutional grounding

The toolkit answers to **three distinct layers**, and conflating them is the most common way to get
districting wrong — so this document keeps them apart throughout: (1.1) **Template-bound mechanisms** no
instance may remove; (1.2) **amendable constitutional variables** whose *values* live in settings and are
chosen at bootstrap or by amendment; and (1.3) **derived legitimacy constraints** — silent in the Template
but principled and validated, the way the cube-root sizing law is. Only (1.4) is genuinely open policy.

### 1.1 Template-bound mechanisms (no instance may remove these)

What is bound is the *mechanism*, not any particular number: a seat band must **exist** and be enforced;
a body must **split** once it exceeds its maximum; boundaries must be **equal, contiguous, and fair**;
voting must be **STV/Droop**; **proportionality may never be reduced.**

| Bound mechanism | Citation | Verbatim hook |
|---|---|---|
| **A body must split once it exceeds its maximum size** (default max 9, *amendable* — §1.2) | **Art. II §2** — *Establish Independent Election Boards* | "the Maximum number of Members a Legislature, or one of its subdivisions can have before it must split is nine (9)." (*Unless otherwise amended.*) |
| **A min/max seat band exists for every jurisdiction or subdivision** (default 5/9, *amendable*) | **Art. V §3** — *Min and Max Representative Seats* | "The Minimum number of Representative Seats in a Jurisdiction, or in any necessary subdivision is five (5) with the maximum being nine (9)." (*Unless otherwise amended.*) |
| **Boundaries drawn equally, contiguously, fairly; by an independent election board** | **Art. II §2** | "boundaries of subdivisions are drawn equally, contiguously, and fairly." |
| **Subdivision rule + uniform rep:population ratio for odd seats** | **Art. II §8** — *Subdivision of Legislatures* | "subdivided into contiguous and equal groupings… keep the ratio of representatives to each subdivision's population uniform." |
| **Proportional voting: STV / Droop** | **Art. II §2** — *Establish Proportional Voting Systems* | "Single Transferable Vote using the Droop Quota Method." |
| **Population records maintained for apportioning seats to subdivisions** | **Art. II §2** — *Maintain Population Records* | "apportioning… legislative seats to any subdivisions." |
| **Proportionality may never be reduced; supermajority = 2/3** | **Art. VII** — *Ratification* | "cannot be replaced with a voting method that decreases proportionality in representation." |
| **Equal treatment; rights regardless of any characteristic except jurisdictional association** | **Art. I** — *Equal Treatment / Vote / Stand* | "regardless of any characteristic except jurisdictional association." |
| **Privacy / no unreasonable surveillance** | **Art. I** — *Privacy and Security* | "secure… from… warrantless or unreasonable… surveillance." |

The election board is the constitutional actor (Art. II §2): **R-08 (Election Board Member)**, workflow
**WF-ELE-06**, institution **I-SUB (Subdivision)**, form family **F-ELB-***.

### 1.2 Amendable constitutional variables (held in settings — NOT hard-coded)

The seat band's **values** default to **5 and 9** *"unless otherwise amended"* (Art. II §2 / Art. V §3) —
they are **not constants in code.** `ConstitutionalDefaults` already resolves them **per jurisdiction**
from `constitutional_settings` (`legislature_min_seats` / `legislature_max_seats`); `HARD_FLOOR=5` /
`HARD_CEILING=9` are only the Template's **backstop defaults** when a jurisdiction has set nothing. A
founder may bootstrap an instance with a different band, and a legislature may amend it through the
constitutional-variable process (inside Art. VII's proportionality shield).

**Every band-derived number is computed, never literal:** `giantThreshold = ceiling + 0.5`,
`floorOverrideBoundary = floor − 0.5`, the Webster start/feasibility checks, the splitline
`[floor,ceiling]` recursion terminator. **The toolkit resolves `floor`/`ceiling` per scope and derives
the rest; it must never hard-code 5, 9, 9.5, or 4.5.** A pin enforces this (§6): re-point an instance to,
say, a **7/15** band and every method must district within 7/15 with thresholds **15.5 / 6.5** — no code
change.

### 1.3 Derived legitimacy constraints (silent in the Template — principled, like the cube-root law)

The Template prescribes *equal + contiguous + fair* outcomes but names **no metric** for them. The
existing autoseed fills that silence with a deliberately-derived **5-key objective** — ① contiguity,
② population equality (the 2% band), ③ radius-of-gyration² compactness, ④ min-avg-Droop diversity
(district magnitude → effective threshold), ⑤ mean deviation. These were **not invented as arbitrary
knobs.** They evolved out of analysis during system-building — the same way the **cube-root
legislature-sizing law** (Taagepera) was derived to size chambers the Template never sized — and they hit
the **recognized political-science benchmarks** for fair districting (malapportionment ≈ 0, high
compactness, proportionality-preserving district magnitude). Achieving those numbers **intrinsically
builds legitimacy**, exactly as STV, the constitutional checks, and real participation do.

This layer is therefore treated as **principled and protective, not casual config.** It is versioned,
documented, defended by benchmark tests, and **strengthened** by recalibration (§4.4) — never quietly
weakened. Where this document calls a metric "tunable," it means *tunable toward the political-science
ideal and expert human judgment, within published bounds* — never *arbitrary*, and never at the cost of
the phenomenal numbers already achieved on the well-documented cases.

### 1.4 Genuinely open policy (these proposals are new policy an instance must author)

- **Which algorithm** (composite / splitline / manual) and its parameters — Art. II §2/§8 demand the
  outcome, not the method. Election-board policy / founder-or-legislature authorship.
- **The population denominator** (WorldPop raster estimate vs census vs civic/verified-resident count) —
  Art. II §2 requires only *accurate records*. Recommendation in §5.
- **The sub-floor question** — what to do when geography or a tight budget *forces* a piece below the
  **resolved** floor (§4.1.4).
- **The recalibration process itself** — tooling/QA, entirely silent; but because exemplars encode
  *"fairly"* (Art. II §2) it is fairness-bounded (§4.4, §6 C6).

---

## 2. Problem & goals

### 2.1 Problem

The autoseed (`DistrictingService::runAutoCompositeForScope`, which the controller and the
election-scheduling job both delegate to) **does a great job**: it groups a scope's **whole child
jurisdictions** into contiguous bins and apportions seats by Webster, hitting the political-science
benchmarks (§1.3) wherever a scope *has* children. The gap is narrow and specific: it has nothing to work
with for a **childless leaf giant** — a jurisdiction whose seat entitlement exceeds the resolved ceiling
(fractional_seats > giantThreshold) but which has no children to compose from. Today
`InitialDistrictMapService::clampUnassignedLeafGiants` (L181) clamps such a giant to **one ceiling
(9-seat) district**, audits it `clamped_pending_subdivision_capability`, and leaves a code note that
*"the shortest-split-line drawing tool (backlog #1)"* will later restore proportional sizing. **That tool
does not exist.** A giant entitled to (say) 40 seats is seated as 9 — a gross under-representation that
violates the spirit of Art. II §8's uniform rep:population ratio.

Three structural blockers stop the tool from being built:

1. **Geometry persistence (the #1 blocker).** `legislature_districts.geom` was **dropped** (migration
   `2026_04_23_000003`). A district is now just a set of whole member rows in
   `legislature_district_jurisdictions`, and that junction can only reference an **existing** jurisdiction
   id. There is **nowhere to store a drawn or split sub-jurisdiction shape.**
2. **No PHP entry point to `population_within`.** The SQL function
   `population_within(iso_code, geom, year) → BIGINT` is fully built but is called only by the ETL.
   Districting never touches rasters; there is no polygon→population query layer in PHP.
3. **Versioned draft persistence.** Plans must regenerate without destroying history.
   `legislature_district_maps` already gives draft/active/archived for *district plans*; Phase F already
   shipped `jurisdiction_maps` for *boundary* versioning. The split sub-units need a versioned home that
   reuses these, not a new silo.

### 2.2 Goals

- **G1.** A districting toolkit with a clear **division of labour, not three interchangeable methods**:
  **Composite** is *the* method for any scope **with children** (the autoseed). **Shortest-splitline** and
  **Manual** apply **only to a childless leaf giant** — the one case composite cannot touch — and they are
  the **automatic / hand-drawn counterparts of each other** (splitline is the autoseed; manual is the human
  draw of the very same case). Every district, by every method, lands in the resolved seat band (default
  5/9, amendable per §1.2) and serves **STV/Droop** proportionality.
- **G2.** Close the three structural blockers with **additive** schema only (protected migrations —
  `jurisdictions`, `ballots`, `audit_log` — untouched).
- **G3.** Wire the **raster substrate** into districting: a PHP polygon→population layer over
  `population_within`, with **cross-border** summation and a **precision/reconciliation** strategy.
- **G4.** A **human-in-the-loop recalibration loop**: an operator hand-draws exemplar plans; a procedure
  tunes the autoseed so its automated ranking reproduces the human-preferred (constitution-compliant)
  partitions — for the composite type **and** the new splitline/manual types — without ever relaxing a
  hard gate.
- **G5.** Keep the toolkit **extensible** for further raster methods (SKATER, weighted-Voronoi, graph-cut)
  behind one interface and one calibration framework.
- **Non-goal:** changing any hard gate, editing protected migrations, or building the full-scale generator
  (that is the sibling session ③, which **depends on this** — see §8).

---

## 3. Additive data-model deltas

House style throughout: `uuid` PK with `gen_random_uuid()` DB default, `timestampsTz()` +
`softDeletesTz()`, **CHECK-enum strings** (not native enums), **partial-unique** indexes
`WHERE deleted_at IS NULL`, GIST on geometry. All deltas are **new tables / new nullable columns on
non-protected tables / new SQL functions** — never an edit to `jurisdictions`, `ballots`, or `audit_log`.

### 3.0 Reuse first (confirmed present)

- **`jurisdiction_maps`** — **already exists** (Phase F, `2026_07_02_000001`): planet → jurisdiction_maps
  → jurisdictions, `draft|active|archived`, `version_no`, `origin`, one-active-per-root partial unique,
  `jurisdictions.map_id` attaches a row to the version that placed it. **Reused as-is**, *not* recreated.
  Reserved for **administrative boundary** versions (union / disintermediation / border settlement), which
  require Art. V §2 population consent. Electoral subdivisions are **not** that (see §6 C5).
- **`legislature_district_maps`** — already gives `draft|active|archived` district-plan versioning;
  `F-ELB-003` promotes draft→active (archive prior). **All new subdivisions are scoped to a
  `legislature_district_maps` version**, so regeneration never destroys history.

### 3.1 `district_subdivisions` (new table — the geometry home)

The recommended home for drawn/split shapes is a **dedicated table the districting pipeline reads as
virtual leaf-children of a giant** — deliberately **outside** `jurisdictions` so the authoritative
administrative tree, residency point-in-polygon, and civic-population queries are untouched (privacy +
invariant safety; see §6 C5, §7 R3). Alternatives weighed in §3.5.

```
district_subdivisions
  id                   uuid  PK  default gen_random_uuid()
  map_id               uuid  -> legislature_district_maps(id)        -- the draft/active/archived plan
  parent_jurisdiction_id uuid -> jurisdictions(id)                   -- the giant being split (the scope)
  parent_subdivision_id  uuid -> district_subdivisions(id) NULLABLE  -- splitline recursion tree
  method               string(20)  CHECK in ('splitline','manual','composite_synthetic')
  label                string(120)
  geom                 geometry(MultiPolygon,4326)  NOT NULL         -- the drawn/split shape
  centroid             geometry(Point,4326)         NULLABLE
  population           bigint                                        -- reconciled (see §4.2.3)
  population_source    string(16)  CHECK in ('worldpop_raster','civic','manual_override')
  population_year      smallint    NULLABLE
  fractional_seats     decimal(10,6) NULLABLE
  seats                smallint    NULLABLE                          -- leaf within the resolved band after Webster
  status               string(16)  CHECK in ('draft','active','archived')  default 'draft'
  timestampsTz / softDeletesTz
  -- indexes
  GIST(geom), GIST(centroid)
  index(map_id, parent_jurisdiction_id)
  partial-unique(map_id, label) WHERE deleted_at IS NULL
```

A `district_subdivisions` row is an **electoral sub-unit** (the I-SUB "Subdivision" concept), not an
administrative jurisdiction. Splitline produces the recursion tree (`parent_subdivision_id`); only the
**leaves** (`seats` within the resolved band) become districts. Manual draws produce leaves directly.

### 3.2 `legislature_district_jurisdictions` — polymorphic membership (additive, non-protected)

The junction must reference **either** a whole jurisdiction **or** a drawn subdivision:

```
ALTER legislature_district_jurisdictions
  ADD subdivision_id uuid -> district_subdivisions(id) NULLABLE
  -- jurisdiction_id stays; one additive CHECK keeps exactly one populated:
  CHECK ( (jurisdiction_id IS NOT NULL)::int + (subdivision_id IS NOT NULL)::int = 1 )
  -- replace the live-unique partial index to cover both member kinds
```

This is the **minimal** change that lets a district be composed of whole child jurisdictions *and/or*
drawn subdivisions, with no special-casing elsewhere: every existing reader that joins the junction to
`jurisdictions` adds a parallel join to `district_subdivisions`.

### 3.3 Raster query layer (new SQL function, additive — `worldpop_rasters` untouched)

`population_within` is single-`iso_code`. Add a **cross-border** companion that resolves the intersecting
iso footprints and de-duplicates border-overlap pixels with the ETL's MAX-over-overlapping-iso pattern
(WorldPop ships per-country tiles that overlap at borders):

```
CREATE FUNCTION population_within_multi(p_geom geometry, p_year smallint default 2023)
  RETURNS bigint  -- for each pixel covered by >1 country raster, take MAX (not SUM) before clip+sum,
                  -- mirroring RasterTileController's ST_Union and the ETL correction pass.
```

(Implementation sketch in §4.2.2. No change to `worldpop_rasters`.)

### 3.4 Recalibration artifacts (new tables)

```
districting_exemplars                          -- the "what good looks like" labeled benchmark
  id uuid PK gen_random_uuid()
  scope_jurisdiction_id uuid -> jurisdictions(id)
  method  string(20) CHECK in ('composite','splitline','manual')
  map_id  uuid -> legislature_district_maps(id)  -- the hand-drawn plan (never auto-activated)
  captured_by uuid NULLABLE                       -- operator
  stats   jsonb       -- frozen constitutional stat vector (see §4.4.2)
  notes   text NULLABLE
  timestampsTz / softDeletesTz

districting_calibrations                        -- the tuned, versioned parameter sets
  id uuid PK gen_random_uuid()
  scope_jurisdiction_id uuid NULLABLE -> jurisdictions(id)   -- null = planet default
  method   string(20) CHECK in ('composite','splitline','manual')
  strategy string(24) CHECK in ('lexicographic_tuned','weighted_fit')
  params   jsonb       -- SOFT thresholds/weights ONLY; never a hard gate
  trained_against jsonb -- exemplar ids + train/validation/holdout split
  agreement numeric NULLABLE  -- rank-agreement / reproduction rate (see §4.4.3)
  status   string(16) CHECK in ('draft','active','archived') default 'draft'
  timestampsTz / softDeletesTz
  partial-unique(scope_jurisdiction_id, method) WHERE status='active' AND deleted_at IS NULL
```

`DistrictingService` / `ConstitutionalDefaults` resolve the **active** calibration for a scope (falling
back to the planet default, then to hardcoded defaults), exactly as `ConstitutionalDefaults::resolve`
already cascades `constitutional_settings`. The **hard gates are never in `params`.**

### 3.5 Geometry-persistence alternatives (recorded)

| Option | Sketch | Why not (vs §3.1) |
|---|---|---|
| **A. Dedicated `district_subdivisions` (recommended)** | shapes live in their own table; pipeline treats them as virtual leaf-children | minor pipeline polymorphism, but keeps `jurisdictions`/residency/civic-population **clean & private** |
| **B. Synthesize rows in `jurisdictions`** (`source='computed_split'`, scoped by `map_id`) | reuses the *entire* composite/Webster/junction pipeline with **zero** special-casing; precedent exists (`source='computed_skater'`) | electoral splits would leak into residency point-in-polygon & `CivicPopulation`; risk of mis-association/double-count; needs every administrative query to exclude them — high invariant risk (§7 R3) |
| **C. Re-add `drawn_geom` to the junction/district** | smallest change | breaks junction semantics (a row = a *whole* membership); no clean population/versioning; geom was deliberately dropped |

**Recommendation: A.** Option B is genuinely elegant (the synthetic children make the giant "no longer
childless," so the existing cascade just works) and worth revisiting **if and only if** we add a strict
query-boundary guarantee that synthetic rows never reach residency/civic-population code. Until then,
the privacy/authority risk argues for the dedicated table.

---

## 4. Services, flows & constitutional forms

### 4.0 Method selection & the shared interface (G5)

**Dispatch — which method runs where (this is the spine of the toolkit):**
- **Scope has children → Composite (§4.1).** The autoseed bins whole child jurisdictions; it is the default
  and covers the overwhelming majority of scopes. Its hand-assist — rubber-band-selecting whole child
  polygons in the Mapper — is part of *composite*, **not** a separate method.
- **Childless leaf giant (no children, entitlement > resolved ceiling) → Splitline OR Manual.** This is the
  **only** case composite cannot handle and the **only** case in which §4.2 / §4.3 are ever used. The two
  are **counterparts for the same case**: **Shortest-splitline (§4.2) is the autoseed** (automatic);
  **Manual (§4.3) is the human draw**. One is chosen per giant — splitline by default, manual where human
  judgment beats the algorithm. **Neither is ever applied to a scope that has children.**

All methods share one interface and the same hard gates:
```
interface DistrictingMethod {
  generate(scope, seatBudget, mapId, calibration): SubdivisionResult  // emits district_subdivisions + districts
  // every method MUST satisfy the same hard gates before returning:
  //   seats(d) within the resolved [floor,ceiling] band for all leaves (band + derived thresholds
  //   resolved per scope, never literal — §1.2); each leaf contiguous; equality within tolerance; STV/Droop served
}
```
Composite, Splitline, Manual implement it. Future raster **subdividers for the childless-giant case**
(SKATER, weighted-Voronoi, graph-cut) plug in **beside Splitline** behind the same gates and calibration —
they are further *automatic counterparts to Manual*, not new methods for child-bearing scopes.

### 4.1 Composite (exists — refine it)

`runAutoCompositeForScope` **does a great job** and is the heart of districting — the controller,
`InitialDistrictMapService`, and the election-scheduling job all delegate here, and it hits the §1.3
benchmarks on the well-documented cases. The items below are **marginal gains on an already-strong
system**, not corrections; each must be **validated through the recalibration loop (§4.4) before
adoption**, and none may regress the numbers it already achieves. (One non-optional item: the new methods
share its per-scope band resolution — see 4.1.0.)

#### 4.1.0 Resolve the band per scope (shared by all three methods)
Resolve `floor`/`ceiling` from `ConstitutionalDefaults` for the **specific scope** and derive
`giantThreshold = ceiling+0.5`, `floorOverrideBoundary = floor−0.5`, the Webster feasibility check, and
the splitline terminator from them. **Never hard-code 5/9/9.5/4.5** (§1.2). The composite path already
does this; the splitline and manual paths must too, so a bootstrapped non-default band just works.

#### 4.1.1 Binning heuristic
- **Population-aware seeding.** Replace/augment farthest-point seeds with weighted k-means++ on
  population centroids (or seed near the population centroids of contiguous sub-regions). Initial bins
  start near quota → fewer balance swaps, fewer stranded smalls. *(Operational config.)*
- **Quota-aware, contiguity-first merge.** When an undersized bin survives, merge it into the **adjacent**
  bin that minimizes post-merge max-deviation while staying ≤ ceiling, *before* the centroid-distance
  fallback. *(Operational.)*
- **k−1 retry.** When the floor is feasible (`budget ≥ binCount × floor`) but a sub-floor bin survives the
  merge, re-run with one fewer bin: fewer, larger bins eliminate the avoidable sub-floor. *(Operational.)*
- **Adaptive false-edge filter.** The `4× p90` edge-distance multiplier is a magic number; make it a
  calibrated `params` knob bounded so it never *creates* false contiguity. *(Operational, fairness-bounded.)*

#### 4.1.2 The 5-key objective
- **Continuous equality key.** Replace "count of districts >2% off" with the summed overage above 2% — a
  smoother search gradient — and store the **2% tolerance** as an amendable `constitutional_setting`
  (it operationalizes "equally," Art. II §2/§8). *(Tolerance value = needs constitutional authorship; the
  key's shape = operational.)*
- **Lex-order has a constitutional floor — but the current order is a derived asset, not a bug.** Keys
  ① contiguity and ② equality are **constitutional** and must stay on top. One *hypothesis worth testing
  in the recalibration loop* (§4.4): since Art. VII privileges proportionality (it "cannot be reduced")
  while compactness is named nowhere in the Template, **proportionality (min-avg-Droop, ④) might belong
  above pure compactness (rg², ③)** — order ① contiguity, ② equality, ③ Droop-diversity, ④ compactness,
  ⑤ deviation. This is **only a candidate**, adopted **iff** the loop shows it preserves the §1.3
  numbers on documented cases *and* improves expert-exemplar agreement — the present order earned its
  legitimacy and is not changed on a hunch. *(A re-order encodes a constitutional priority → needs
  authorship; thresholds → fairness-bounded calibration.)*
- **Determinism key.** Add a final stable tie-break (district id / seed) so identical inputs → identical
  maps — required for audit reproducibility and federation consistency. *(Operational.)*

#### 4.1.3 Where `floor_override` is *forced* vs where the heuristic leaves quality on the table
- **Mathematically forced** when `nonGiantBudget < binCount × floor` — more contiguous components than
  `floor × seats` can cover, so *some* bin must seat below the floor no matter the search. The canonical
  case is an **archipelago of land-isolated tinies** (no shared land border → each is its own bin → each
  below floor).
  Here the override is a genuine **Art. II §8 (contiguity) vs Art. V §3 (floor) conflict** → a
  constitutional question (§4.1.4), not a bug.
- **Heuristic slack** when the floor *is* feasible but a sub-floor bin survives because seeding stranded a
  small component or the merge's distance filter was too strict. Here a better binning (k−1 retry,
  quota-aware merge, population seeding) removes the sub-floor. **Fix the heuristic; do not paper over it
  with an override.**

#### 4.1.4 Constitutional question — should the soft floor be hardened?
Art. V §3 sets min 5 *"unless otherwise amended."* A district seating <5 therefore violates the **default**
floor unless the instance has amended it. Recommendation for founder/legislative authorship:
- **Harden the floor to 5 via merge-up** as the default resolution; `floor_override` becomes an
  **audited, explicitly-authorized exception** (operator or legislative act), never silent/automatic.
- For the *forced* geographic case (island enclaves), the instance must choose, through the amendment
  process, one of: (a) amend the local minimum below 5 for that scope; (b) accept an audited exception;
  (c) merge the enclave into a contiguous neighbor where geography allows. This is **policy, not
  engineering** — the tool surfaces the conflict and the audit, and refuses to decide it silently.

### 4.2 Shortest-splitline (new — the documented "backlog #1")

**Scope: childless leaf giants only.** Splitline is the **autoseed counterpart to the manual draw (§4.3)** —
the *automatic* way to subdivide the one scope composite cannot (a giant with no children). It is **never
run on a scope that has children** (composite handles those). For a childless giant entitled to
`S = round(fractional_seats)` seats with `S > ceiling`, recursively bisect the giant's **own polygon**
with the shortest line that splits **population** in the ratio of the seats each side will hold, until
every piece lands within the resolved `[floor,ceiling]` band (§1.2).

#### 4.2.1 Algorithm
```
split(region, S, mapId, parent):
  if S <= ceiling:                       # within band -> a leaf district
     assert S >= floor (else merge-up per §4.1.4); persist leaf district_subdivision(seats=S); return
  choose (a, b) with a + b = S, a≈b, each further-decomposable into [floor,ceiling]   # seat split
  target_ratio = a / (a+b)               # population share the 'a' side must hold
  best = null
  for theta in orientation_sweep(coarse->fine):          # candidate cut orientations
     line = directed_line(theta)
     # binary-search the perpendicular OFFSET until population ratio hits target_ratio +/- tol:
     offset = binary_search(o ->
        halves = ST_Split(region.simplified, line@o)
        popA   = population_within_multi(halves.left)     # cross-border aware (§4.2.2)
        popA / (popA + popB))  toward target_ratio
     chord_len = ST_Length(ST_Intersection(line@offset, region.boundary))
     best = argmin chord_len over theta                   # SHORTEST population-balanced chord
  (regionA, regionB) = ST_Split(region, best.line)
  split(regionA, a, mapId, this); split(regionB, b, mapId, this)
```
- **Cut on the simplified geom** (reuse `DistrictingService`'s two-tier `ST_Simplify`) for the search;
  materialize the final cut on full-resolution geom.
- **Orientation sweep** coarse→fine (e.g., 16 → refine the best to 1°) to bound cost.
- The **leaves become `district_subdivisions`** (method `splitline`), each → one district within the
  resolved band. The giant is then districted proportionally (S seats across ⌈S/ceiling⌉…⌊S/floor⌋
  districts), lifting the clamp.

#### 4.2.2 Cross-border handling
`population_within` is single-iso; a half may straddle countries (a union scope, or a giant near a
border). For each half, sum across the iso footprints whose rasters intersect it, **de-duplicating
border-overlap pixels with MAX-per-pixel** (WorldPop per-country tiles overlap at borders; SUM would
double-count). `population_within_multi` (§3.3) does this by unioning intersecting rasters with MAX
before clip+sum — the ETL's correction-pass pattern and `RasterTileController`'s `ST_Union` posture.

#### 4.2.3 Precision & reconciliation (summing many small slices)
`ST_Clip` at slice boundaries introduces partial-pixel error; D slices accumulate error and won't sum to
the parent. Reconciliation:
1. Compute the **parent total once** (`population_within_multi(giant)`).
2. Compute each slice; use `ST_Clip(..., touched=TRUE)` and one fixed pixel-inclusion rule so cuts are
   deterministic and non-overlapping.
3. **Distribute the residual** `(parent − Σ slices)` by **largest-remainder** so slices sum **exactly** to
   the parent. (Seats are integer Webster over reconciled populations, so sub-pixel error changes a seat
   only near a rounding boundary — **flag near-boundary slices for operator review.**)
4. **Cache** slice populations by `geom-hash + map version` for idempotent re-runs.

#### 4.2.4 Forms / flow
- **F-ELB-007 — Splitline Subdivision Generation** (R-08; module `elections`, event
  `district_map.subdivided`). System/board files to run the splitline tool on a childless giant inside a
  **draft** `legislature_district_maps`. Produces `district_subdivisions` + in-band districts; audited
  with the seat vector, the per-slice stats, the reconciliation residual, and the **clamp lift**
  (the prior `clamped_pending_subdivision_capability` audit is superseded). Citations Art. II §2, §8.

### 4.3 Manual (extend what exists)

**Scope: childless leaf giants only — the human counterpart to splitline (§4.2)** for the same case.
Today the Mapper (`resources/js/Pages/Legislature/Show.vue`, Leaflet) can **rubber-band-select whole child
polygons**; that capability **stays as *composite's* hand-assist** for scopes **with** children and is not
the "manual method" here. The new capability is different: a real **draw-on-the-map** tool with a **live
population readout**, so a human can carve **intra-jurisdiction** districts inside a **childless giant**
where judgment beats the algorithm — exactly the case splitline automates, done by hand instead.

- **New read-only endpoint** `POST /api/legislatures/{id}/population-probe` → body GeoJSON polygon →
  returns `{ population, implied_fractional_seats = pop/quota, in_band: 5 ≤ round ≤ 9, contiguous }`.
  Backed by a new **`PopulationRaster` PHP service** (the missing entry point):
  `populationWithin(iso, geom, year)`, `populationWithinMulti(geom, year)`, `quotaFor(scope)`,
  `impliedSeats(pop, quota)`, `inBand(seats)`. The probe is a **read** (no state change) → not a
  constitutional form; R-08-gated; rate-limited; floored against single-household inference (§6 P2).
- **Draw UI:** add Leaflet.draw (freeform polygon + vertex edit); on every drag, debounce-POST the probe;
  show population / implied seats / in-band pill / contiguity live. **Commit is blocked** unless the gates
  pass (within the resolved band, contiguous, within equality tolerance).
- **F-ELB-008 — Manual District Draw** (R-08; event `district.drawn`). Persists a committed hand-drawn
  shape as a `district_subdivisions` row (method `manual`) + its district + membership inside the draft
  map; re-validates the resolved band, contiguity, and coverage. Citations Art. II §2, §8.

### 4.4 The recalibration loop ("retrain the autoseed")

A dedicated hands-on session where the operator manually draws **exemplar** plans (composite **and**
splitline/manual), which become a labeled benchmark of "what good looks like," then a procedure
recalibrates the autoseed so its automated ranking reproduces the human-preferred partitions.

#### 4.4.1 Objective design — (a) tuned-lexicographic vs (b) learned/weighted
Calibration's purpose is to **strengthen the §1.3 legitimacy layer**: push the derived metrics closer to
the political-science ideal and to expert human judgment on the **hard cases** the documented benchmarks
never covered (childless giants, archipelagos, cross-border), **while preserving the phenomenal numbers
already achieved on the well-documented cases.** It never introduces arbitrary taste and never relaxes a
gate.

**Recommendation: transparent tuned-lexicographic (a) by default, with optional weighted-fit (b) confined
to the SOFT tie-break layer.** Rationale: the Template requires *"fairly"* and *"equally"*; an opaque
learned objective deciding districts is hard to defend as *fair* and is unauditable, and the codebase
ethos is *executable constitutional law* with explainable hardened rules. Keeping the objective an
explicit, derived, lexicographic scorer is itself part of what makes the numbers legitimacy-conferring.
- Keep the **lexicographic hard gates** (contiguity, the resolved band, equality tolerance) as immutable
  feasibility filters applied **before** any scoring.
- Tune **only** the soft thresholds (2% band, compactness tolerance, adaptive edge filter) and the
  ③/④ tie-break order/weights, to reproduce the exemplars.
- If a weighted scalarization (b) is used at all, it ranks **already-feasible** maps only, its weights are
  **published with each draft** for observation (F-ELB-003), and its features are a **whitelist of
  geometry/population only** — never anything correlated with a protected characteristic or partisanship
  (Art. I equal treatment; Art. II §2 "fairly"; §6 C6).
- **For splitline/manual** the "objective" differs: splitline is deterministic given the seat-split policy
  + shortest-chord rule; manual is human judgment. Calibration there tunes the **seat-split policy**
  (balanced a≈b vs population-natural), the **orientation-sweep density**, the **chord-length ↔
  population-tolerance** trade-off, and the manual live-readout's acceptance thresholds. Exemplars teach
  which parameterization the human prefers.

#### 4.4.2 Exemplar capture format
A "Save as exemplar" R-08 action (off the live election path — exemplars are **never** auto-activated)
writes a `districting_exemplars` row: the scope, method, the full plan (`map_id` → districts →
subdivisions/members), and a **frozen constitutional stat vector** (`stats` jsonb):
```
{ non_contiguous_count, count_over_2pct, max_dev_pct, avg_dev_pct, avg_rg_sq,
  avg_droop_threshold, seat_vector, district_count,
  per_district:[{seats, pop, dev_pct, rg_sq}] }
```
plus operator notes ("why this is good").

#### 4.4.3 Agreement metric (against the constitutional stats)
The metric has **two halves**, both required: does the calibrated autoseed (i) **reproduce expert human
judgment** on the exemplars, and (ii) **still hit the §1.3 political-science benchmarks** (malapportionment,
compactness, proportionality-preserving magnitude)?
- **Composite:** run the autoseed on the same scope with a candidate calibration and compare its top-1
  partition to the exemplar by (i) **seat-vector exact match**, (ii) **membership agreement** (Jaccard /
  adjusted-Rand over which children land together), (iii) **constitutional-stat dominance** (does the
  autoseed map weakly dominate the exemplar on the 5 keys?). Primary headline metrics: **rank-agreement**
  (across a candidate-partition set, does the calibrated scorer rank the human-preferred exemplar #1 /
  top-k?), **reproduction rate** (reproduces the exemplar's seat vector and ≥X% membership overlap), and
  **benchmark achievement** (the generated map's stat vector meets the documented-case thresholds).
- **Splitline/manual:** **spatial agreement** — population-weighted area-overlap between generated and
  exemplar subdivisions — plus seat-vector match and compactness-within-tolerance.

#### 4.4.4 Tuning / validation protocol
1. **Split exemplars by scope** into train / validation / held-out (never tune and validate on the same
   scope).
2. **Search** (grid → Bayesian) the soft `params` to maximize train rank-agreement, **subject to**: every
   generated map passes the hard gates, and **no param leaves its constitutional bound.**
3. **Validate** on held-out scopes: require agreement ≥ threshold, **zero hard-gate regressions, and no
   regression of the §1.3 benchmark stats on the well-documented cases** (a calibration that improves a
   hard case but degrades the documented numbers is rejected — legitimacy is not traded between cases).
4. **Pin & adopt** via **F-ELB-009 — Districting Calibration Adoption** (R-08; event
   `districting.calibrated`): set the `districting_calibrations` row `active`, archive the prior, publish
   the exemplar benchmark + agreement for observation. A **constitutional pin test** (§6) asserts the
   active calibration's hard gates are unchanged and that re-running the autoseed on the pinned exemplars
   still yields in-band, contiguous, equal maps.
5. Recalibration is **operator-initiated, never automatic**; every adoption is audited.

#### 4.4.5 Flow — WF-ELE-06 refined
Child-bearing scopes never enter this flow — composite districts them directly. The flow exists for the
**childless-giant** case only: a **childless** giant is detected (entitlement > resolved ceiling, no
children) → board opens a **draft** `legislature_district_maps` → runs **F-ELB-007 splitline** (the
autoseed) **or** **F-ELB-008 manual** (its hand-drawn counterpart) → subdivisions generated, each within
the resolved band → **draft published for observation** (observer standing: endorsing orgs + candidates) →
board finalizes via **F-ELB-003** (draft→active, archive prior) → next election runs per-district. The
recalibration loop sits beside it: draw exemplars → calibrate → validate → adopt (F-ELB-009).

| New form | Title | Role | Event | Citation |
|---|---|---|---|---|
| **F-ELB-007** | Splitline Subdivision Generation | R-08 | `district_map.subdivided` | Art. II §2, §8 |
| **F-ELB-008** | Manual District Draw | R-08 | `district.drawn` | Art. II §2, §8 |
| **F-ELB-009** | Districting Calibration Adoption | R-08 | `districting.calibrated` | Art. II §2 ("fairly") |

(F-ELB-001..006 exist; **007 is the next free code.** Each handler implements the `FormHandler` contract;
the engine records the audit entry in the same transaction. **F-ELB-003** is reused unchanged as the
activation step.)

---

## 5. Constitutional & privacy invariants

Restated as the gate set the toolkit holds; the pinned ones are enumerated in §6.

- **C1 — Resolved seat band.** Every district, every method, seats ∈ the **resolved** `[floor,ceiling]`
  band (default 5/9, amendable per §1.2; Art. II §2 / Art. V §3). The band values come from
  `constitutional_settings`; the derived thresholds (`ceiling+0.5`, `floor−0.5`) are computed per scope —
  never literal. What is *hard* is that a resolved band exists and is enforced, not the numbers 5 and 9.
- **C2 — Contiguity.** Every district contiguous (Art. II §2/§8); the existing BFS reachability + island
  exemption applies to subdivisions too.
- **C3 — Equality / uniform ratio.** Population equality within tolerance; subdivisions keep the
  rep:population ratio uniform (Art. II §8). Webster + the 2% tolerance operationalize "equally."
- **C4 — Proportionality preserved.** Districts serve STV/Droop; calibration may never reduce
  proportionality (Art. VII) — e.g., it may not bias toward small districts that raise the Droop entry
  threshold beyond what equality requires (the min-avg-Droop key guards this).
- **C5 — Electoral ≠ administrative (authority + privacy).** `district_subdivisions` are **electoral
  only**; they never mutate administrative `jurisdictions` boundaries, so **no Art. V §2 boundary change
  occurs** (no population-supermajority needed) and residency/association/`CivicPopulation` queries are
  unaffected. Keeping subdivisions out of `jurisdictions` is what makes this hold.
- **C6 — Fairness-bounded calibration.** Exemplars/params may not encode partisan or
  protected-characteristic bias (Art. I; Art. II §2 "fairly"); features are a geometry/population
  whitelist; weights are published for observation.
- **P1 — Aggregate population only.** Districting uses **aggregate WorldPop raster (100 m)** and/or civic
  **counts** — never `residency_pings`, never raw locations, never individual records. `population_within`
  returns a `BIGINT` sum; the probe returns only aggregates. **Ballots, raw locations, and credentials
  never leave their authoritative instance** (unchanged).
- **P2 — No fine-grained inference.** The probe floors tiny-polygon counts / rounds to prevent
  single-household presence inference, is R-08-gated and rate-limited. (Raster is already aggregate at
  100 m; individual inference is not feasible, but the floor is belt-and-braces, Art. I Privacy.)
- **A1 — Audit.** Every generation/draw/activation/calibration appends a hash-chained `audit_log` entry
  (module `elections`), payload carrying seat vectors / stats / clamp lifts / calibration id + agreement —
  **never** ballot content or raw locations.

**Population denominator policy (SILENT → needs authorship).** Recommendation: **civic/verified-resident
population (`CivicPopulation`) is the constitutional denominator for live apportionment** (Art. II §2
"records of residents"); the **WorldPop raster is the seeding estimate** for first-draft maps drawn
*before* residents exist. Flag the source per `district_subdivisions.population_source`; the choice is a
board/legislative policy decision, not an engineering default.

---

## 6. Test strategy

Match the suite's two-mode posture exactly (`tests/TestCase.php` is bare; `RefreshDatabase` is forbidden
on the ~951k-row live dev DB):

**Mode 1 — DB-free pure-static constitutional pins.** Expose the new math as pure statics so they pin
without a database (as `ActivationService::cubeRootSeats` / `seatPlan` already do):
- `Splitline::seatSplit(S, floor, ceiling)` → band-parameterized decompositions; pin that recursion
  **always** terminates with every leaf within `[floor,ceiling]`, that `a+b=S`, and the merge-up rule for
  forced sub-floor — **across several bands** (5/9, 7/15, 3/7), proving nothing is hard-coded.
- **Band-resolution pin (§1.2):** for a stub `constitutional_settings` of 5/9, 7/15, 3/7, assert the
  resolved `giantThreshold`/`floorOverrideBoundary` = `ceiling+0.5` / `floor−0.5` and that the methods
  treat them as the split/clamp boundaries — no literal 5/9/9.5/4.5 anywhere in the path.
- `targetRatio = a/(a+b)` and the largest-remainder reconciliation: pin that reconciled slices sum
  **exactly** to the parent and that seat assignment is stable away from rounding boundaries.
- Calibration `params` bound-checks: pin that no param can leave its constitutional bound and that the
  hard gates are **not** representable in `params`.

**Mode 2 — live-pg, rolled-back (the `LivePgConnection` trait).** For DB-touching paths, clone the
`pgsql` connection (`LIVE_PG_DATABASE`), `setDefaultConnection`, `beginTransaction()`, build a **tiny
synthetic giant sub-tree** (a small polygon over a country with a loaded raster), run the tool, assert,
and `rollBack()` in `finally` (skip if pg unreachable). Pins (each carrying its `$e->citation`):
- **Splitline lifts the clamp:** a synthetic childless giant entitled to N>9 yields districts each in
  the resolved band summing to N, contiguous, within tolerance; the `clamped_pending_subdivision_capability` audit is
  superseded.
- **Cross-border de-dup:** `population_within_multi` over a border-straddling polygon ≠ naive SUM where
  country tiles overlap (MAX-per-pixel proven).
- **Manual gate:** committing an out-of-band or non-contiguous hand-drawn polygon raises a
  `ConstitutionalViolation('Art. II §2')`; the probe never mutates state.
- **C5 boundary:** generating subdivisions leaves `CivicPopulation` and residency point-in-polygon
  results **unchanged** (subdivisions are invisible to administrative queries).
- **Calibration pin:** re-running the autoseed under the active calibration on the pinned exemplars still
  produces in-band, contiguous, equal maps; agreement ≥ threshold on held-out scopes; **and the §1.3
  benchmark stats on documented cases do not regress.**

Discipline (per existing pins): *"If an edit breaks this test, the edit is the constitutional violation —
fix the edit, never the test."*

---

## 7. Risk register (honest)

| # | Risk | Mitigation | Residual |
|---|---|---|---|
| **R1** | Cross-border raster **double-counting** at country-tile overlaps → wrong seats | MAX-over-iso de-dup; reconcile to parent total; flag near-rounding slices for review | WorldPop per-country edge artifacts |
| **R2** | **Precision drift** summing many small splitline slices → seat off-by-one | parent-total largest-remainder reconciliation; deterministic pixel rule; geom-hash cache | pathological coastlines |
| **R3** | Synthetic sub-units **leaking** into administrative/residency/civic-population queries → mis-association, double-count, privacy breach | **dedicated `district_subdivisions` table** outside `jurisdictions`; explicit query boundary; C5 pin | the reason Option B (§3.5) is deferred |
| **R4** | **Performance**: splitline over million-vertex giants (sweep × binary search × recursion) at planetary scale | search on simplified geom; coarse→fine sweep; cache raster sums; queued job w/ `publishMassProgress`; bound orientations | very large giants need chunking |
| **R5** | Calibration **overfitting / fairness drift** — a few exemplars encode idiosyncratic or biased "fair" | hard gates immutable; held-out validation; **feature whitelist**; published weights (F-ELB-003/009); C6 fairness pin; reviewed exemplars | "fairness" is contested → flagged as founder/legislative policy |
| **R6** | **Sub-floor ambiguity** — silent `floor_override` seats <5, arguably violating Art. V §3 default | harden to 5 via merge-up; `floor_override` = audited, authorized exception; raise to founders | island enclaves may make 5 infeasible → documented carve-out needed |
| **R7** | **Manual gerrymandering** — freeform draw enables unfair maps | live readout shows + **blocks** on equality/contiguity/resolved-band; draft published for observation; "fairly" is justiciable (Pham-style precedent) | within-gate unfairness is a political/judicial question |
| **R8** | **Scope confusion** — conflating `jurisdiction_maps` (administrative, needs Art. V §2 consent) with electoral district versioning | electoral subdivisions live in `legislature_district_maps`/`district_subdivisions`; `jurisdiction_maps` reserved for true boundary changes | promotion of an electoral split to an administrative boundary is explicitly out of scope |
| **R9** | Touching a **protected migration** | all deltas additive (new tables, nullable column on the non-protected junction, new SQL fns); `jurisdictions`/`ballots`/`audit_log` untouched | — |
| **R10** | **Dependency**: ③ full-scale-demo cannot complete while giants sit at the clamp | this toolkit + calibration is the stated prerequisite (§8) | sequencing across sessions |

---

## 8. Dependency statement

**This toolkit + recalibration is the prerequisite for the full-scale generation script (sibling session
③ `full-scale-demo`).** A planetary first-draft **cannot complete** while childless giants sit at the
`clampUnassignedLeafGiants` stub (one ceiling district instead of proportional N): every jurisdiction must
be **districtable by a calibrated method** first — composite for child-bearing scopes; splitline (or
manual) for childless giants. ③ **additionally** depends on the institutional modules from sessions
①②④–⑦ (treasury, i18n, education/social, coalition-as-org, achievements) to populate consistent demo
data. This document unblocks the districting half of that dependency.

---

## 9. Tunables ledger — by the three layers of §1

Tier maps to §1: **(M)** Template-bound mechanism (§1.1) · **(V)** amendable constitutional variable in
settings (§1.2) · **(L)** derived legitimacy constraint (§1.3) — principled, versioned, strengthened not
casually retuned · **(P)** genuinely open policy / authorship (§1.4).

| Tunable | Tier | Note |
|---|---|---|
| A seat band exists & is enforced; split-on-exceed; STV/Droop; proportionality preserved | **M** | never removable (Art. II §2/§8, Art. VII) |
| The band **values** `floor`/`ceiling` (default 5/9) | **V** | `constitutional_settings`; set at bootstrap or by amendment, inside Art. VII shield |
| `giantThreshold = ceiling+0.5`, `floorOverrideBoundary = floor−0.5`, Webster feasibility, splitline terminator | **V (derived)** | computed per scope from the band — never literal |
| The 5-key objective & its lex-order (incl. the ③/④ swap hypothesis) | **L** | the cube-root-class layer; re-order needs authorship + recalibration proof; top-two fixed |
| 2% equality tolerance | **L → V** | operationalizes "equally"; store as a tight amendable setting |
| Compactness metric/weight, Droop-diversity weight, seed strategy, edge filter, simplification tol | **L** | calibration `params`; fairness-bounded; published for observation; tuned toward the ideal, not arbitrary |
| Splitline seat-split policy, sweep density, binary-search precision, chord↔pop trade-off | **L** | calibration `params` |
| Splitline seat-ratio target & band termination | **M/V** | termination within the resolved band is the hard gate |
| `floor_override` policy (harden vs audited exception) | **P** | the soft-floor question (§4.1.4) |
| Population denominator (civic vs raster) | **P** | recommend civic for live apportionment, raster for pre-residency seeding |
| Calibration adoption | **board act** | F-ELB-009, audited; never automatic |
