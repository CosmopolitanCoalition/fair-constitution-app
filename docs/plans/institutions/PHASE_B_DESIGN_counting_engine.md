All sources studied: the embedded `STV_DATA` in `results.html` (the count lives there, not in fixtures.js), `vacancy-countback.html`, `gen_fixtures.py` (registry-only — it did NOT generate the count), DESIGN_schema_engine.md A.2, the Phase B roadmap, the EXPLORE docs, the Phase A engine code (`ConstitutionalEngine::file()`, `AuditService::append()`, Horizon `long-running` supervisor), and the named skips in `tests/Constitutional/FuturePhasePlaceholdersTest.php`.

# PHASE B — THE COUNTING ENGINE (PROTECTED): VoteCountingService

## 0. Fixture forensics — exact semantics extracted from the mockup count

Source: `E:/fair-constitution-app/mockups/electoral/results.html` lines 123–125 (`window.STV_DATA`, inline — **not** in `fixtures.js`; `gen_fixtures.py` builds only the registry block; the generating script for the count is NOT in the repo). Every claim below is verified against the fixture numbers:

| Semantic | Evidence |
|---|---|
| **Droop quota** `floor(valid ÷ (seats+1)) + 1` | 412,383 ballots, 9 seats → floor(41,238.3)+1 = **41,239** ✓ (also stated verbatim in the stat label, line 26) |
| **Surplus method = Weighted Inclusive Gregory (WIGM)**, NOT last-parcel/whole-vote Gregory | Transfer value = `surplus ÷ candidate's full total` in every surplus round: r16 458/41,697=0.01098→"0.011"; r19 3,940/45,179=0.0872→"0.087"; r20 4,511/45,750=0.0986→"0.099"; r21 5,003/46,242=0.1082→"0.108"; r22 977/42,216=0.0231→"0.023"; r24 5,995/47,234=0.1269→"0.127"; r25 8,220/49,459=0.1662→"0.166"; r26 2,578/43,817=0.0588→"0.059"; r27 704/41,943=0.0168→"0.017". Under last-parcel Gregory r16 would have been 458/2,749=0.167 — the data rules it out. ALL of the winner's ballots transfer; each ballot moves at `current_weight × (surplus/total)` |
| **Exhausted share of a surplus is lost to exhausted, not retained** | r21 surplus: to-sum 4,659 + exhausted 344 = 5,003 exact; r25: 6,165 + 2,055 = 8,220 exact |
| **Elimination = lowest continuing candidate, ballots move at current (possibly fractional) value** | Eliminated totals strictly increase across rounds: 5,224 → 6,965 → 7,698 → 7,789 → … → 31,384; action text "votes transfer at current value" |
| **One transfer event per round** (one elimination OR one surplus) | 27 rounds, 18 eliminations + 9 surplus distributions |
| **Surpluses processed before further eliminations; cascading elections chain** | r19→r22 are four consecutive surplus rounds (each crossing caused by the prior surplus); elimination resumes only at r23 |
| **`round_elected` = the round the surplus distributes** | Rita crosses quota via r15's elimination transfer (28,729 + 12,967 accumulated ≈ 41,697) but `elected:[{Rita, round:16}]` — election is recorded in the surplus round |
| **Final winner's surplus still distributed** (record completeness) | r27: 9th seat — Aisha elected AND her 704 surplus transferred (Sade +447, exhausted 257) |
| **`tallies` = standings at round START** | Rita r1=28,454, r1 transfer +275 from Tanya, r2=28,729 ✓; Sam r2=17,687 +2,199 → r3=19,886 ✓ |
| **Write-ins tabulated identically** | Quinn Avery (write-in) eliminated r13 with 16,999 — full participant, flag is display metadata only |
| **Integer display, fractional internals** | `Math.round(votes)` in `bar()`; transfer value shown to 3 dp; r16 to-sum 457 vs surplus 458 (1-unit display rounding) — internals must be exact, display rounds |
| **Countback = full re-run at original seat count** | `vacancy-countback.html` line 41: quota 28,755 = floor(287,540÷10)+1 — the **9-seat** quota, on the prior ballot set, vacated member "struck as a candidate"; failure = "no continuing candidate can reach the quota" |
| **RCV majority display** | `rcvMaj = floor(412,386/2)+1`; advisors labeled "by sequential exclusion", advisor 2–4 labeled by their elimination round in the base count |

The mockup display compacts rounds (full `tallies` only on rounds 1–3 + final; mid rounds carry only `action`+`transfer`); the final-round display zeroes already-elected candidates — both are **display artifacts**, not storage semantics. `tabulation_rounds` stores full tallies every round; elected candidates rest at exactly quota.

---

## A. ALGORITHM SPEC

### A.1 PR-STV, Droop quota, Weighted Inclusive Gregory — `countStv()`

Fills **all** seats of one race in one count (Art. II §2, hardened). Input: candidate set, `seats` (1–9), ballots (ordered candidacy-id rankings), excluded set, tie seed.

1. **Validate & canonicalize ballots**: strip ids not in the validated candidate set and ids in `excluded` (withdrawn-pre-lock, countback strikes — preferences pass over, never exhaust on them); collapse duplicate ids (keep first); a ballot with zero remaining rankings is **invalid** and excluded from `total_valid`. Group identical rankings (multiplicity).
2. **Quota** = `intdiv(total_valid, seats + 1) + 1`, computed once, never recomputed mid-count (snapshot to `election_races.quota`).
3. **Round 0 state**: every ballot weight = 1.0 (scaled integer, §B.2); first preferences tallied; `tallies_before` of round 1 = first-preference totals.
4. **Loop** (each iteration = one round, one transfer event):
   a. **Election check**: every continuing candidate with tally ≥ quota is *marked elected* (order: descending tally; ties by §A.5-T). They stop receiving transfers immediately.
   b. **Surplus queue first**: if any elected candidate has an undistributed surplus, distribute the **largest surplus** (tie → §A.5-T) as this round: `value = surplus / total` (exact fraction in scaled integers); each of the winner's ballots (all parcels) transfers to its next continuing preference at `floor(weight × surplus / total)` (per-ballot truncation, §B.2); ballots with no continuing next preference contribute their share to `exhausted`. Winner's tally rests at exactly `quota`. `action='elect'`, `round_elected` = this round (fixture convention). Zero surplus → still a round: `action='elect'`, `transfer.value=0`, empty `to`, no movement.
   c. **Else eliminate**: lowest continuing candidate (tie → §A.5-T); all their ballots transfer at **current weight** to next continuing preference; no next preference → exhausted. `action='eliminate'`.
   d. **Shortcut fill**: if continuing candidates == unfilled seats, declare them all elected (`action='elect'`, `transfer=null`, flag `elected_without_quota=true` in the round payload) in descending-tally order. (Not demonstrated in the fixture — Queens filled all 9 by quota — but required for termination.)
   e. **Terminate** when `count(elected) == seats` **and** the final winner's surplus round has been emitted (the fixture distributes it — keep, for record completeness and audit symmetry), or after step d.
5. **Seat numbers**: `seat_no` = election order (fixture: "seat 1..9" by `elected[]` order = round order).

### A.2 Single-winner RCV (instant-runoff) — `countRcv()`

**Only** for the individual executive (Art. III §3; registry vote-type table "Individual executive election"). Same ballot canonicalization, same numeric core, weights stay 1 (no fractional transfers in IRV):

1. Tally first preferences. **Win condition**: a candidate's tally > ½ of *continuing* (non-exhausted) ballots → elected.
2. Else eliminate lowest (tie → §A.5-T), transfer at full value, repeat. Two candidates left → higher tally wins (tie → §A.5-T).
3. Round record: identical `tabulation_rounds` shape, `transfer.kind='elimination'`, final round `action='elect'`. Display majority line = `floor(total_valid/2)+1` (mockup convention) but the *win test* uses continuing ballots so exhaustion cannot deadlock the count.

### A.3 Top-4 advisors by sequential exclusion — `deriveAdvisors()`

Per `results.html` line 86 ("re-run the count without the winner, then without the top two, and so on") and WF-ELE-08:

```
excluded = {}; advisors = []
winner  = countRcv(ballots, candidates, excluded).winner
for rank in 1..4:
    excluded += previous winner
    advisors[rank] = countRcv(ballots, candidates − excluded).winner
```

Five full IRV counts over the same ballot set; advisor `rank` 1–4 → `race_results.is_runner_up=true, runner_up_rank` (DESIGN A.2 column already provided). Each derivation re-run is stored as its own `tabulations` row (`kind='audit_rerun'` is wrong here — **add enum value `advisor_derivation`** to `tabulations.kind`, see §B.3) so the public record shows all five counts. If fewer than 5 candidates exist, derive as many advisors as candidates allow; remaining advisor ranks stay vacant.

### A.4 Countback (universal) — `countback()`

Art. II §5; WF-ELE-03; q-ledger #q6; `vacancy-countback.html` evidence:

1. Inputs: the **original race's** ballot set (ballots persist post-certification precisely for this), original `seats`, original candidate set, plus `struck` = the vacating member's candidacy id (multiple ids if simultaneous vacancies in one race).
2. **Full deterministic re-run** of A.1 with `excluded += struck` — same seat count, hence the fixture's unchanged quota 28,755 ("as if the vacating member never ran", Art. II §5). **No filter of any kind is accepted by the API** — no electorate, faction, endorsement, or party parameter exists on the signature (universality is structural, not policy).
3. **Replacement** = elected set of the re-run minus current sitting members of that race, taken in re-run election order (handles the theoretical case where the re-run elects >1 non-sitting candidate while only 1 seat is vacant: first new winner fills it; sitting members' seats are never disturbed — countback fills *the vacancy*, it does not re-litigate held seats).
4. **Failure** = the re-run elects fewer candidates than vacancies among non-sitting candidates (ballots exhausted below quota and the shortcut-fill pool contains no eligible new winner who is still a validated, living, associated candidate) → `vacancies.status='countback_failed'`, arm CLK-04 (90–180 day window, engine rejects out-of-window dates — already specced in DESIGN A.2 `vacancies`).
5. Eligibility re-check at certification, not inside the count: a re-run winner who has since died/relocated out of the race footprint is skipped (next new winner in election order; none left → countback failed). The counting core stays pure; eligibility is the certification handler's job.
6. Stored as `tabulations` row `kind='countback'`, `excluded_candidacy_id` set (for multi-strike, see §B.3 refinement).

### A.5 Edge cases (all hardened, all tested)

- **T — Deterministic tie-break (eliminations, simultaneous quota order, surplus order, final-two RCV)**. Neither the mockups nor the registry spec STV count ties (the registry's only tie rule is committee assignment: "prior-election 1st-choice then subsequent ranks", EXPLORE_registry.md line 411 — same spirit, adopted here). **Spec (two stages):**
  1. *Backwards tie-break*: compare the tied candidates' tallies in the most recent earlier round where they differ; walk back to round 1 (first preferences). This is the registry's "prior performance, then subsequent ranks" principle applied to the count itself. Cite "Art. II §4 principle · as implemented".
  2. *Audit-chained seeded lot*: if tied in **every** round, order by `sha256(tie_seed ∥ candidacy_id)` ascending, where `tie_seed = sha256(canonical ballots hash ∥ race_id ∥ round_no)` — published in the round record and to the audit chain (precedent: `juries.draw_seed` "published to audit chain", DESIGN A.5). Deterministic: same inputs → same lot. No wall clock, no RNG state.
- **Fewer candidates than seats**: all candidates elected via shortcut-fill; `CountResult.seats_unfilled > 0`; the counting engine only reports — the certification handler creates `vacancies` rows (`status='detected'`) for unfilled seats, which route to special election (countback is impossible: no prior ballots contain unelected candidates).
- **Zero-vote candidates**: eliminated first, one per round (no batch elimination — fixture shows strictly one event per round; record fidelity beats the negligible speed win), ties among them by T.
- **Simultaneous quota attainment**: all marked elected the same instant (step 4a); surpluses distributed largest-first, each as its own round; `round_elected` = each candidate's own surplus round.
- **Write-ins**: not a counting concept at all. The ballot payload stores candidacy UUIDs; finalist vs written-in is candidacy metadata the Results page renders as a chip. The counting core cannot distinguish them — that is the constitutional guarantee, enforced by the type system rather than by a rule.
- **Exhausted ballots**: tracked per round (`transfer.exhausted`); conservation invariant: `Σ tallies + Σ exhausted + truncation_residue == total_valid` exactly, every round (§B.2).
- **Withdrawal mid-count**: structurally impossible — F-CAN-003 is engine-blocked after `finalist_cutoff_at` (ballot lock, DESIGN A.2 `candidacies`), and the count input is frozen at `ranked_closes_at`. Pre-lock withdrawals enter `excluded` (rankings pass over them). Death between close and certification is an eligibility matter at certification (A.4 item 5), never a mid-count mutation. The pure core takes an immutable input and cannot be interrupted with a candidate change — re-running with a new exclusion is a new `tabulations` row.
- **Seats bounds**: the core asserts `1 ≤ seats ≤ 9` and throws `ConstitutionalViolation('Art. II §2/§8')` otherwise — defense in depth under the `election_races.seats` CHECK. **FLAG: Montegiardino's legislature was activated with 10 unicameral seats, which exceeds the Art. II §2 hard max of 9.** It cannot be elected as a single at-large race. Constitutionally it must subdivide (Art. II §8: contiguous, equal, uniform ratio — e.g., 5+5 districts), or the activation sizing must be corrected to 9 before its first F-ELB-001. San Marino's 32 type_a seats likewise require a district map before scheduling; its 9 type_b seats are one at-large race. **Decision: a chamber with `type_a_seats ≤ 9` and no district map elects via ONE at-large race (Art. II §8 requires subdivision only above 9); `> 9` with no active map is a scheduling-time engine rejection with citation, never a counting-time fudge.** This belongs to the scheduling/board section but the counting engine enforces the per-race ceiling regardless.

---

## B. CLASS DESIGN

### B.1 Files

```
app/Services/VoteCountingService.php            ← PROTECTED (constitutional review header,
                                                   added to CLAUDE.md protected list — already listed)
app/Domain/Elections/Counting/CountInput.php    ← DTO (readonly)
app/Domain/Elections/Counting/BallotSet.php     ← grouped, canonicalized ballots
app/Domain/Elections/Counting/CountResult.php
app/Domain/Elections/Counting/RoundResult.php
app/Domain/Elections/Counting/Micro.php         ← scaled-integer arithmetic (final class, static)
app/Jobs/Elections/TabulateElectionJob.php      ← fan-out, long-running queue
app/Jobs/Elections/TabulateRaceJob.php          ← per-race wrapper (DB in, DB out)
app/Jobs/Elections/RunCountbackJob.php
```

**Purity contract (the protected property):** `VoteCountingService` has **no constructor dependencies, no DB, no Eloquent, no clock, no RNG, no config reads**. Same input → byte-identical `CountResult` (incl. `record_hash`) on any machine. All I/O lives in the jobs.

```php
/**
 * PROTECTED FILE — Constitutional review required before modification.
 * Implements Art. II §2 (PR-STV, Droop quota, Gregory fractional transfers),
 * Art. II §5 (countback), Art. III §3 (single-winner RCV + advisors).
 * Pinned by tests/Constitutional/StvDroopGregoryTest et al. CI gates on the suite.
 */
final class VoteCountingService
{
    public const ENGINE_VERSION = 'stv-droop-wig/1.0.0';   // → tabulations.engine_version, part of record_hash
    public const SCALE = 1_000_000;                         // microvotes, §B.2

    public function countStv(CountInput $in): CountResult;
    public function countRcv(CountInput $in): CountResult;                       // seats must be 1
    /** @return CountResult[5] base count + 4 exclusion re-runs, advisor rank = index */
    public function deriveAdvisors(CountInput $in): array;
    /** @param string[] $struck @param string[] $sitting — NO other filter parameter exists */
    public function countback(CountInput $in, array $struck, array $sitting): CountbackResult;
}
```

`CountInput { string[] $candidacyIds; int $seats; BallotSet $ballots; string[] $excluded; string $tieSeedBase; }` — `tieSeedBase = sha256(canonical-ballots-hash ∥ race_id)` is computed by the job, so the lot is reproducible from public data.

`BallotSet::fromRankings(iterable $rankings): self` — canonicalization (strip/dedupe/group) happens here, once; internally rankings are packed binary strings of small-int candidate indexes (uuid→index map built from sorted `candidacyIds`), with `multiplicity` per distinct ranking. This is both the determinism canonicalization (iteration over sorted packed keys) and the memory strategy (§C perf).

### B.2 Numeric strategy — **scaled 64-bit integers (microvotes), truncation toward zero; bcmath only for the one overflow-prone product**

**Recommendation: fixed-point integers at scale 10⁻⁶ (1 vote = 1,000,000 µv), all arithmetic in PHP ints, per-ballot transfer weight `new_w = floor(w × surplus_µv / total_µv)` computed via bcmath (`bcdiv(bcmul(...))`) for that single product, then cast back to int.**

Justification:
- **Floats are disqualified**: Gregory transfers chain multiplications; IEEE-754 rounding is platform/order-sensitive and unauditable — `record_hash` determinism dies. The mockup's 2–3-decimal *display* is irrelevant to internals (its own to-sums show 1-unit display rounding, e.g. r16's 457 vs 458).
- **Exact rationals (GMP fractions) are overkill**: denominators grow multiplicatively through cascaded surpluses (r19→r22 chains four), round records become unreadable fractions, and no real-world STV statute uses them. Auditors expect fixed-decimal truncation.
- **Fixed-point truncation is the legal-world standard** (Scottish STV: 5 dp truncated; we use 6) and gives an *exact* conservation law: truncation loses ≤ 1 µv per ballot per transfer; the engine **accumulates the loss explicitly** in `truncation_residue_micro` per round so the invariant `Σtallies + exhausted + residue == total_valid × 10⁶` holds with `==`, not `≈`.
- **Range analysis**: worst realistic race = Earth district, ~3×10⁷ ballots → totals ≤ 3×10¹³ µv (fits int64). The only product that can overflow is `w × surplus_µv` (≤ 10⁶ × 3×10¹³ = 3×10¹⁹ > 2⁶³) — hence that one operation goes through bcmath strings inside `Micro::mulDiv(int $w, int $num, int $den): int`. Everything else (sums, comparisons) is native int. No `bcscale()` global state — explicit scale-0 integer bc ops only.
- Display: Results controller divides by 10⁶; votes rendered `round()`, transfer value rendered 3 dp — reproducing the mockup exactly.

### B.3 Round record — `tabulation_rounds` (refines DESIGN_schema_engine.md A.2, no contradictions)

Schema as designed (`id, tabulation_id FK cascade, round_no smallint, action CHECK('elect','eliminate'), candidacy_id, transfer jsonb, tallies jsonb, created_at`, unique `(tabulation_id, round_no)`), with the JSON shapes now pinned:

```jsonc
// transfer (null only for shortcut-fill rounds)
{
  "kind": "surplus" | "elimination",
  "value_micro": 10984,              // surplus only: floor(surplus×10⁶/total); elimination: null ("current value")
  "to": [["<candidacy_id>", 93741], ...],   // µv, sorted by candidacy_id asc (canonical)
  "exhausted_micro": 344000123,
  "truncation_residue_micro": 41,
  "tie_break": null | {"stage":"prior_rounds","decided_at_round":12}
              | {"stage":"lot","seed":"<sha256>","order":["id1","id2"]}
}
// tallies — standings at round START (fixture convention), µv map + meta
{
  "candidates": {"<candidacy_id>": 28454000000, ...},   // continuing + elected (elected rest at quota×10⁶)
  "exhausted_micro": 0,
  "elected_so_far": ["<candidacy_id>", ...],            // election order
  "elected_without_quota": false                        // true on shortcut-fill rounds
}
```

Two enum refinements to the A.2 spec (additive): `tabulations.kind` gains `'advisor_derivation'` (A.3); and for multi-strike countbacks `excluded_candidacy_id uuid null` is kept for the single-vacancy common case plus `excluded_candidacy_ids jsonb NOT NULL DEFAULT '[]'` as the authoritative list. `tabulations.record_hash` = `sha256(canonical_json({engine_version, seats, quota, total_valid, rounds[...]}))` — RFC-8785-style sorted keys, the same canonicalization `AuditService` already uses; the hash is what F-ELB-004 certifies and what `election_audits.outcome` compares.

`race_results` rows written per winner: `(tabulation_id, candidacy_id, round_elected, seat_no)`; advisor rows with `is_runner_up=true, runner_up_rank 1–4`.

### B.4 Determinism guarantee

Same `CountInput` → identical `CountResult.record_hash`, proven by: (1) candidate index = sort(candidacy uuids); (2) `BallotSet` grouped + sorted by packed ranking key — ballot *insertion order can never matter*; (3) all map iterations over ksorted arrays; (4) integer-only arithmetic; (5) tie-break fully specified (A.5-T) with a seed derived from the inputs; (6) `ENGINE_VERSION` inside the hash so any future algorithm change is visibly a different engine, never a silent drift. Property-tested by shuffling ballot order and re-running (§C).

---

## C. TEST STRATEGY

`tests/Constitutional/` — replace the four Phase-B named skips in `FuturePhasePlaceholdersTest.php` (`test_stv_droop_gregory_fractional_transfers`, `test_countback_is_universal_no_faction_filter`, plus keep `ballot_envelope` and `clock` skips for their own sections) in the same PR as the engine code, per that file's header convention.

**Fixture reality check:** the 412,383-ballot count **cannot be reproduced** — `STV_DATA` is round summaries only, embedded in `results.html`; the synthetic ballots and their generator are not in the repo (`gen_fixtures.py` builds the registry only; `mockups/tools/` has no STV generator). Therefore: **commit the round-summary pin + a new golden ballot fixture**, as follows.

1. **`tests/Fixtures/stv/queens_2031_rounds.json`** — verbatim copy of `STV_DATA`. `StvDroopGregoryTest::test_mockup_semantics_pin` asserts every machine-checkable invariant of §0 against it: quota formula; every surplus `value ≈ surplus/total` at 3 dp; every surplus to-sum + exhausted == surplus (±1 display unit); elimination totals strictly increasing; one event per round; elected rounds == surplus rounds; final surplus distributed; write-in present in transfer flows. This pins the *demonstrated semantics* forever even though the ballots are gone.
2. **`tests/Support/SyntheticBallotGenerator.php`** — seeded generator (popularity-weighted candidate draw + preference-cluster sampling, ~24 candidates) committed as code + seed, ballots regenerated in-test (never committed). `test_golden_count_412k` generates 412,383 ballots (seed pinned), runs `countStv(seats:9)`, and asserts the full `record_hash` against a committed golden value plus spot-asserted round structure (first crossing happens via accumulation then surplus round, cascading surplus chain occurs, exhausted appears in late surpluses — the qualitative shape of the Queens count). Marked `@group slow`.
3. **Hand-computed micro-fixtures** (the arithmetic pins): 3–6 elections of 6–30 ballots with *every round hand-derived under WIGM + 6-dp truncation* and committed as expected-rounds JSON — including: zero-surplus exact-quota election; simultaneous double quota with surplus ordering; shortcut-fill; all-rounds-tied lot tie-break (asserting the seed-ordered outcome); excluded-candidate pass-over; duplicate-preference dedupe.
4. **Property tests** (seeded, ~200 random elections, 50–5,000 ballots, 2–9 seats):
   - all seats filled whenever candidates ≥ seats; never more than `seats` elected;
   - elected candidates never receive transfers after election ("quota never exceeded twice");
   - **exact conservation**: every round, `Σ tallies + exhausted + Σ residues == total_valid × 10⁶`;
   - monotone elimination (eliminated candidate had the minimum continuing tally, modulo recorded tie-break);
   - surplus `value_micro ≤ 10⁶`;
   - **determinism**: shuffle ballots → identical `record_hash`; run twice → identical;
   - write-in indifference: flipping the finalist flag on candidacies changes nothing (the flag never reaches the core's input type).
5. **`CountbackUniversalTest`**: re-run minus one winner over a fixture where a new candidate crosses (found branch) and one where ballots exhaust (failed branch); sitting members' seats unchanged; multi-vacancy strike; **reflection assertion that `countback()`'s signature contains no filter parameter** and a grep-style source assertion that the words `faction`/`endorse` do not appear in `VoteCountingService.php` (cheap, brutal, effective).
6. **`RcvTest`**: majority-of-continuing termination; exhaustion does not deadlock; final-two; tie-breaks; round record shape parity with STV.
7. **`AdvisorDerivationTest`**: 5-run sequential exclusion yields ranked top-5; advisor 1 ≠ base runner-up by raw count in a crafted fixture (proving exclusion-re-run, not standings-read); < 5 candidates → vacant ranks; determinism.
8. **Performance pin** (`@group performance`, nightly not per-commit): 500,000 ballots, 24 candidates, 9 seats — budget: **pure count < 60 s, < 256 MB** (packed-string `BallotSet`: 500k × ~12 B keys grouped, realistic grouping collapses this far below worst case); **end-to-end `TabulateRaceJob` (stream + decrypt + count + write rounds) < 5 min** on the `long-running` queue. Earth scale is 274 *parallel* races of district-sized electorates, so this single-race budget dominates everything real.

---

## D. INTEGRATION CONTRACT

**Trigger chain (no official discretion, Art. II §2 hardened):** clock fire at `elections.ranked_closes_at` → election `status='voting_closed'` → `TabulateElectionJob::dispatch($electionId)->onQueue('long-running')` (existing `supervisor-long-running` in `config/horizon.php`). The job is system-actor; it does not pass through a form (tabulation is not in the 103-form catalog — it is engine machinery), but **every step audit-chains** via `AuditService::append(module:'elections', event:'race.tabulated', …)`.

**`TabulateElectionJob`**: flips election → `tabulating`, fans out one `TabulateRaceJob` per `election_races` row (Earth general = 274 jobs, Horizon parallelizes; Montegiardino = blocked upstream per §A.5 flag; San Marino type_b = 1 race × 9 seats… which is one at-large 9-seat STV race). Completion watermark: last race job marks election ready-to-certify.

**`TabulateRaceJob` (per race):**
1. `tabulations` insert: `kind='initial'`, `status='running'`, `engine_version`, `seats`, `started_at`.
2. **Stream ballots**: `Ballot::where('race_id')->where('kind','ranked')->cursor()` in 10k chunks → decrypt `payload_encrypted` (envelope key server-side; plaintext ranking arrays only ever exist inside this job) → `BallotSet::fromRankings()` incrementally. Compute `ballots_hash` (sha256 over sorted `ballot_hash` values — public inputs) → `tieSeedBase`.
3. `VoteCountingService::countStv()` (or `countRcv` + `deriveAdvisors` for `seat_kind='single'` executive races).
4. One transaction: batch-insert `tabulation_rounds` (chunks of 500), `race_results`, update `tabulations` (`status='complete'`, `quota`, `total_valid`, `record_hash`, `completed_at`), update `election_races` (`quota`, `total_valid_ballots`, status), bulk `ballots.counted=true`, `AuditService::append` with `record_hash` — mutation and chain entry atomic, matching the Phase-A engine pattern.
5. Failure → `status` stays `running` with Horizon retry; a superseding re-run inserts a new `tabulations` row and marks the old `superseded` (append-only count history; rounds are never edited).

**Results page contract** (`Pages/Elections/Results.vue`): controller `ElectionResultsController@show` builds **exactly the mockup `STV_DATA` shape** from `tabulations` + `tabulation_rounds` + `race_results` + candidacy/user joins:

```jsonc
{ "total": …, "quota": …, "seats": …, "rounds": 27,
  "elected": [{"candidacy_id":…, "name":…, "round":16, "seat_no":1}, …],
  "display": [{ "n":1, "action":"<built from action+kind+value_micro>",
                "transfer": {"from":…, "kind":…, "to":[[name,votes]…], "exhausted":…},
                "tallies": [[name, votes]…], "electedSoFar":[…] }, …] }
```

— with µv→vote division and `round()` at the edge, transfer value formatted to 3 dp, write-in chip from candidacy finalist status. The controller sends **all** rounds with tallies; the Vue page applies the mockup's collapse (full rounds 1–3 + final, `<details>` middles) as pure presentation. CSV download = flat per-round export of the same rows (replaces the mockup's stub).

**Certification (F-ELB-004 handler, Phase B form)**: pre-checks every race `tabulations.status='complete'` and re-verifies each `record_hash` against recomputed canonical JSON; writes `election_certifications.count_record_hash`; seats winners (`legislature_members` evolve-columns + `terms` rows + CLK-01/CLK-10 arming); R-09 derives from the seat rows; next approval phase opens. **Recount (F-ELB-006)** = new `tabulations` `kind='audit_rerun'` over the same ballots; `election_audits.outcome` = `reaffirmed` iff hashes match, else `corrected` + superseding certification.

**Countback (`RunCountbackJob`)**: `vacancies.status='countback_running'` → load prior race ballots (same streaming path), strike per §A.4, `kind='countback'` tabulation, `vacancies.countback_tabulation_id` set → found: certification handler path seats the replacement (term ends at the *original* lockstep expiry); failed: `countback_failed` + CLK-04 armed. The VacancyCountback.vue panel renders the re-run's final-round tallies in exactly the mockup's `RERUN` bar shape (removed / reaches-quota / exhausted rows).

---

## E. DEFERRED (with justification)

| Item | Deferral |
|---|---|
| **Meek/Warren STV** | Out of scope forever unless amended: Art. VII permits only *more proportional* replacements via legislative act; WIGM is what the fixture demonstrates and what ships hardened. The `ENGINE_VERSION` string is the upgrade seam. |
| **Batch elimination of hopeless candidates** | Deferred indefinitely — changes round-record shape vs the fixture's one-event-per-round; perf doesn't need it (§C.8 budget met without). |
| **Reverb live round-streaming during tabulation** | Phase C+ nicety; counts are seconds-to-minutes, the Results page reads committed rounds. |
| **Mid-count candidate death handling beyond §A.4(5)** | Needs judiciary/vital-records machinery (Phase E); certification-time eligibility skip is constitutionally sufficient now. |
| **Montegiardino 10-seat resolution** | Flagged in §A.5 — belongs to the activation/districting work item (correct sizing to 9, or auto-split 5+5); the counting engine's 1–9 assertion guards it either way. Must be resolved before Montegiardino's first F-ELB-001 can issue. |
| **Referendum (yes/no) tallying** | Not a ranked count — simple threshold aggregation in the certification path (WF-ELE-07, Phase C per roadmap); shares ballot tables, not this service. |

Key paths: `app/Services/VoteCountingService.php` (new, PROTECTED), `app/Domain/Elections/Counting/*`, `app/Jobs/Elections/{TabulateElectionJob,TabulateRaceJob,RunCountbackJob}.php`, `tests/Constitutional/{StvDroopGregoryTest,CountbackUniversalTest,RcvTest,AdvisorDerivationTest}.php`, `tests/Fixtures/stv/queens_2031_rounds.json`, `tests/Support/SyntheticBallotGenerator.php`; evidence sources `E:/fair-constitution-app/mockups/electoral/results.html` (lines 123–125), `E:/fair-constitution-app/mockups/electoral/vacancy-countback.html` (lines 38–48, 140–158); schema base `docs/plans/institutions/DESIGN_schema_engine.md` §A.2 (`tabulations`/`tabulation_rounds`/`race_results` — refined additively per §B.3).