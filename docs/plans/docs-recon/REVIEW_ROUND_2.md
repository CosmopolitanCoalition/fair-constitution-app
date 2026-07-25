# Review Round 2 — plan deliverables (2026-07-25)

Lane 7's review of the plans the fleet delivered after the auto-mode launch. One
independent reviewer per lane, each reading the plan in full and **verifying its
load-bearing claims against the code** rather than accepting them. Findings below are
per-lane revision notes: **the lane owns the fix**; lane 7 owns only the corrections to
its own documents (logged in §0).

Status vocabulary: **APPROVE** = execute as written · **APPROVE WITH NOTES** = execute,
fold notes into the next revision · **NEEDS REVISION** = fix the named items before
executing that part.

---

## 0. Corrections this round forced on lane 7's OWN documents

The review round caught three factual errors in the audit/orchestration docs. All are
fixed and committed; recorded here because lanes built on the wrong versions.

| # | Was | Truth | Commits |
|---|---|---|---|
| 0-a | BUILT_INVENTORY overturned-claim #5: Phase O's CI-2 rail "already enforced and pinned" | The invariant is implemented and pinned, but **inert in production** — the only production caller (`setRoomServerACL()`) passes no argument, so the `true` path is test-only. Naively wiring it trips the anti-self-brick guard (`:70`) and takes the node down. Operator decision, not a wiring task. | `7374afd` |
| 0-b | Lane-3 addendum: "`onOneServer` + LeaderProbe is the ready-made CI-6 rail" | **Wrong axis.** That is the Patroni/HA *scheduler-leader* axis. CI-6 ("only the authoritative instance writes a snapshot") rides `jurisdictions.authoritative_server_id` (`AuthorityResolver`). A mirror runs its own scheduler and wins its own probe, so the writer needs a per-jurisdiction `authoritative_server_id IS NULL` filter. | `adfac97` |
| 0-c | Risk register ended at item 12 | Six new fleet-wide items (13–18) from lane verification passes — see BUILT_INVENTORY §7. | `7374afd`, `52a7e38`, `c10d70d` |

**Standing lesson:** the audit's §8 rule ("sweep for alternate names, enforcement-without-
persistence…") needs a fourth clause — **verify the CALLER, not just the implementation**.
0-a and 0-b are both cases where a mechanism exists and is tested but nothing production
reaches it on the axis claimed.

---

## 1. Lane 2 — Cloud Launch (`AZURE_BRINGUP_PLAN.md`) — **NEEDS REVISION**

Narrow: four substantive fixes on an otherwise unusually solid, factually accurate plan.
It honors the operator's provisioning ruling, plans backward from 09-01 with real dates,
and carries six of seven mandated checklist items.

**Must fix before execution:**

1. **The documented one-command path produces a node that cannot pass the plan's own
   GATE-1 — and is insecure.** §3.1 → `get-started.sh` never calls `deploy.sh`; it does
   `cp .env.example .env` (`:175`), so the node boots on the **committed shared dev
   APP_KEY** (forgeable sessions and signed URLs — `.env.example:8` says so itself), with
   `APP_DEBUG=true`, `APP_ENV=local`, no `key:generate`, no `federation:init`, no clocks.
   Only §3.2's cloud-init path fixes it. **This is the operator's actual path** (his ruling:
   he installs from the GitHub instructions), so §3.1 must hand off to
   `deploy.sh --public-url …`. Risk register #17.
2. **The ClockRegistrySeeder fix targets a seam a fresh box never reaches.** §14 item 2
   aims at `get-started.sh`'s `migrate`, which lives inside the `if UPDATED` branch. The
   fresh-box migrate is `SetupController::runMigrations` (`:503`), which does not seed
   clocks. Right value, wrong location.
3. **Sizing omits four of ten services and the production Horizon profile.** Recomputed
   serving floor ≈14 GB vs the plan's derivation, so "16 GiB gives real headroom, 8 GiB
   does not" is unsupported and the Small tier sits at the edge. `HostCapacity::
   autoscaleWorkers()` is the autoscale limiter, not Horizon's process count — don't size
   from it. Re-derive, or mark provisional on Phase 0's measurement. Risk register #18.
4. **GATE-3 collides with lane 14, and the mandated J window is missing.** GATE-3 requires
   `organizations` = 0 and `public_records` = 0 at cutover; D-15 has lane 14 seeding the
   Foundation + Coalition **inside** the 40 days (and `OrgRegistryService` writes public
   records too). **Lane 7 adjudication: the gate's predicate is wrong, not the seeding.**
   The Standard instance's rule is *zero SYNTHETIC data*; the two nonprofits are real
   civil-society entities, legitimately seeded. Re-express GATE-3 as a synthetic-data
   assertion (demo emails/`@cga.test`/`*@demo.invalid`, demo-command markers), and add the
   J migration+seeding window to §10 against the 08-29 freeze.

**Also fold in:** give the M-5/K-3 legal-compliance gate a number, a machine-checkable
pass criterion and a date ≤ 08-20 · state the BallotCrypto ruling as **in force** with
R-12/Q5 as the proposed variance, so an unanswered Q5 fails *to the ruling* · route the
app-code items in §14 (3/4/5) through the board like items 6-7, or get ownership extended
(`map:import-seed`, `launch:assert-clean`, demo-command guards, `jurisdiction:activate
--force` = lane 3's pipeline) · name `DeployPackageService` as an affected consumer · fix
the §5.4 loopback recipe, which breaks lane 2's own `get-started` port parsing · name the
exact production service roster (ideally a compose profile) so GATE-1 is checkable · say
*cluster/mirror join* in §6.3 (a `discover→handshake` peer join does not yield a mirror) ·
add the Capacitor/mobile out-of-scope line · relabel GATE-6(ii) as multibox verification
(D-18's cross-machine half already PASSED on the Pi) · add a slip rule for a
restart-the-clock soak failure after ~08-24.

**Good catches (verified):** the APP_KEY regeneration trap that would orphan
`instance_settings.private_key_encrypted` · the Matrix delegation portless-`m.server` bug ·
`deploy.sh --seed` running `institutions:demo-e` (residency confirmations into an
append-only ledger) on a zero-synthetic-data launch, with fail-closed reasoning ·
`VITE_PROTOMAPS_URL` build-time and absent from `.env.example` · `matrix_data` as a third
irreversible identity in the backup set · boot-time prewarm re-dispatch on every Horizon
restart · serverless evaluated and rejected **with numbers**.

---

## 2. Lane 3 — Institution Scaling (`INSTITUTION_SCALING_PLAN.md`, rev2) — **APPROVE WITH NOTES**

Findings 1–3 are must-fix before any code; nothing needs re-architecting. Criteria for the
two cube roots, the ETL rule, eager-vs-lazy-with-recommendation, and the zero-test-coverage
deliverable are all fully met.

1. **The Type B exclusion is keyed to the wrong predicate — 20,667 chambers missed.** The
   plan skips where `type_b_needs_districting` is set (9,708); the engine's real rejection
   is `type_b_seats > ceiling` — **30,262 legislatures**, of which 20,667 carry no flag.
   See the fleet-wide item in §5 below; adopt lane 4's per-kind `racePlan()` question as a
   **shared** operator decision instead of routing around it.
2. **`seat_drift` is computed over ALL districts, not the ACTIVE map `racePlan` reads.**
   Measured against `status='active'`: 21,153 seats across 3,809 legislatures, vs the
   plan's 19,755. An unscoped predicate produces a different exclusion set than the engine
   it protects. (Reassuring rider worth stating: 0 legislatures with `type_a ≤ 9` lack
   active-map districts today — an accident of the data, not a property of the formula.)
3. **CI-6 is not implemented by the proposed mechanism** — see §0-b. Lane 3 inherited the
   error from lane 7's addendum; both are now corrected. Decide explicitly whether
   `legitimacy_snapshots` carries `source_server_id` (i.e. whether snapshots federate),
   since lane 15 plans to consume leaderboards.
4. **Lane 2's dated debt is unpaid**: it asked for *one integer* by 08-05 (the interim
   activation threshold for the Standard instance), not the curve. Name it or record it as
   a dated owed item — read literally the plan leaves the live instance at config default 1,
   which contradicts its own rationale ("stop one actor booting a government").
5. **State the audit-append ordering rule inside a chunk** (append the batch summary LAST,
   after the bulk commit, in its own short transaction) — risk register #13.
6. Assert the charter's "zero new F-forms/clocks/audit-modules" property rather than
   leaving it inferred from absence (lane 7 polices form-count drift).
7. Small: `config/cga/surfaces.php` exists (only the *reach record* is absent); 00:25 is
   taken by `EvaluateCoDeterminationJob` (00:40 is genuinely free); doc citations should
   carry paths like the app/ ones do.

**Good catches (verified):** four real concurrency holes in the uniqueness table, with the
correct `elections` predicate and why the narrow form is dangerous · **the engine must never
write `jurisdiction_activations`** — a planet-wide stamp *forges the Art. II §1 consent
crossing* and re-kills CLK-06 (reverses the author's own rev1; strongest finding in the
document) · CLK-06's candidate set is inverted, not empty · the third reader
(`ClockService::resolvedInt`) that bypasses the curve · the amendability premise is false in
code, and an unbounded ancestor setting could render descendants permanently unbootable —
a franchise harm by the back door · parent-minus-children differencing is *exact* because of
the ancestor sweep · the raw sub-k count written into the append-only chain can never be
redacted · Band 1½ gating the Plane A square on a headcount as an Art. I error distinct
from Matrix capacity.

---

## 3. Lane 4 — Simulated World Engine (`SIM_SCALING_PLAN.md`) — **APPROVE WITH NOTES**

Scope discipline correct on every critical axis; no re-invention of the four
already-delivered items; every spot-checked code claim verified true.

1. **Chartered artifact names are renamed or missing** — `demo_generation_runs` (0 hits;
   it is `sim_runs`/`sim_items`), `DemoPopulateService` (named once, never designed),
   `DemoSandboxService` (0 hits). The build surface is defined *by those names*; add a
   one-line reconciliation or revert, else a charter-vs-plan diff reads all three as
   undelivered.
2. **Executives and judiciaries are never materialized — CI-5 half-covered.** The DAG ends
   at legislature seating; `InstitutionStubService::generate()`'s own docblock says no
   members or seats are populated and status stays `forming`. The roadmap names "2/3
   supermajority, ≥5 judges" as what must hold in synthetic data, and the exit criterion is
   *every institution*. Add a phase or state the deferral explicitly.
3. **The activation/consent seam is unaddressed** — and lane 3 ruled on it two minutes
   before this plan's last edit (the engine must never write `jurisdiction_activations`).
   State lane 4's side: either it writes them (and must square that with its own "nothing
   synthetic dressed up as attained consent") or it doesn't — in which case ~924k
   legislatures hold seated members inside jurisdictions still `forming`, which the demo
   renders. Largest unstated 3↔4 coupling; both plans are on main silent about it.
4. **Build order contradicts itself**: §13 step 1 is `instance_class` + boot assertion
   ("nothing else is safe to build first"), but the migration that mints it is step 4.
   Split item #1 into its own earlier migration or reorder.
5. Broken cross-reference: cites `INSTITUTIONS_SCALING_PLAN.md`; the file is
   `INSTITUTION_SCALING_PLAN.md` (singular) — and it is this plan's primary dependency.
6. Lane 1's filter taken half: the 452-review exclusion is adopted, but the *safe* half
   (3,351 drift maps, in-band) is unrecorded; and the plan's own 34,763 pop-zero figure
   never reconciles with lane 1's 34,738.
7. **Machine-written prose about real, named places is not covered by the honesty rail** —
   §5's bullets cover synthetic people, not the requirement that inherited/modulated
   jurisdiction profiles render as machine-generated with their stored confidence/sources.
   The one contamination vector the five layers don't reach, because it is content, not rows.
8. Four build items sit outside the chartered three-item surface; only one is flagged to
   the operator. Name all four as scope additions (`CohortBallotExpander`, the
   `TabulationRecorder` extraction, the counting perf pin) — the safety reasoning holds
   (verified: none is on `HARDENED_SURFACE`), but they are app code outside the lane's path.
9. "Tier" is now overloaded: fidelity tiers here vs lane 3's activation tiers. One
   disambiguating sentence (BUILT_INVENTORY §5 exists because names have fooled this
   program before).

**Good catches (verified):** **CI-2 is inert in production** (§0-a — this review corrected
lane 7's audit) · the 23.8% blocked-seats measurement and its mechanism, self-corrected in
public from 13.5% with the reasoning attached · the weighted-ballot identity proof given a
keystone test rather than an argument · **the transaction-scoped audit lock** (risk register
#13 — a fleet-level save) · 86,066 soft-deleted districts inside active maps (#15) ·
`CivicPopulation` as the pre-existing honesty rail, with the uncomfortable consequence
stated openly instead of fixed by corrupting it · refusing to place the expander in
`app/Domain/Counting/` because it sits on `HARDENED_SURFACE` and would break mid-election
certification · a raw-multiply overflow only a planet-scale demo would reach · rejecting
`@cga.test`/`Hash::make('demo')` for `*@demo.invalid` + random secrets, **plus** the finding
that no artisan command carries an environment guard (#14).

---

## 4. Lane 5 — Translation Scaling (`TRANSLATION_SCALING_PLAN.md`) — **APPROVE WITH NOTES**

The strongest-grounded plan of the round: ~40 code citations spot-verified, **all seven
audit facts stated correctly**, both 12:38 rulings honored, the pull-engine retrofit a
faithful port with exact precedent citations. Nothing architectural below.

**Two items that are the OPERATOR's, not engineering:**

- **W1. WF-SYS-03 compliance is forward-only, and the consequence is permanent.**
  `public_records` rows are immutable (trigger-enforced), so records **already published
  carry `translations = []` forever** — badge total 0, no locale ever addable. WF-SYS-03
  ("public records publish *with* translations") is the only hard constitutional mandate in
  Phase N. "Records published before this ships are permanently non-compliant" is an
  operator-facing fact, not a dial. Decide: accept, or add a compliant re-publication path.
- **W2. The 112-locale roster inherits a country→official-language bias.** The base derives
  from `scripts/etl/languages.py`'s 115 codes — a map of *official* languages per country —
  and the ladder only ever subtracts. Result: **Telugu, Marathi, Punjabi, Gujarati, Kannada,
  Malayalam, Hausa, Yoruba, Igbo, Javanese** — all NLLB-200 pairs, hundreds of millions of
  speakers — can never enter the roster. For a cosmopolitan template whose Art. I rights do
  not key off officialdom, the base list deserves an explicit widening pass, not just a floor.

**Engineering fixes:**

1. **`ON CONFLICT` key mismatch — a real SQL failure in the hot write path.** §2 declares
   `UNIQUE(source_hash, locale, is_private)`; §4 infers `ON CONFLICT (source_hash, locale)`.
   Postgres rejects a 2-column inference against a 3-column constraint — every batch aborts.
2. **The ownership-extension list is materially incomplete** — the one item whose whole
   purpose is getting it right. Missing: `resources/js/app.js` (the lazy-serving hook +
   `availableLocales` seed), the new dashboard page under `Pages/**` (lane 6's tree),
   `routes/web.php` + controller for the progress endpoint, `AppServiceProvider`, and
   `scripts/etl/supervisor.py` (lane 1's ETL tree).
3. **The budget rail is soft by one pump interval** — the claim path reads a pump-denormalized
   `budget_tokens_spent`, which is exactly what the plan forbids for progress bars. Read
   `SUM(ledger)` directly in the claim path.
4. Two numbers for one quantity ($0.032 vs $0.023 per record-across-77; ~5,100 vs 4,650
   corpus) against the plan's own single-provenance rule.
5. The git-ergonomics argument defeats itself: catalogs stay uncommitted "so nobody reviews a
   1,540-file diff", but per-key meta is the same 1,540 files. Either meta is `en`-only or
   the diff moved rather than vanished.
6. **Uncommitted catalogs have no provenance off the sweep box** — a fresh clone, a peer, or
   future CI builds English-only, because `translation_memory` is Plane B and doesn't ship.
   Violates the plan's own Plane A/B rule. Needs an artifact-distribution answer.
7. The charter's deferred `translation_cache`/`translation_string_status` names are superseded
   silently by `translation_memory` — BUILT_INVENTORY §2 claim #4 turns on those names. One
   paragraph distinguishes deliberate supersession from oversight.
8. The Register.vue pilot is step 10 of 10 against a deadline **lane 6 controls** (Auth/Register
   is an early tour stop) — maximizing the chance the fallback fires. The honest minimum
   (publish-time pending map in `PublicRecordService::publish()`, ~10 lines) is independent of
   the record sweep and can land at step 5.
9. §2 contradicts §7/§12 on whether the read path changes. (Good news buried there: the badge
   needs no change — it already computes `done` as `quality !== 'pending'`.)
10. The "worker strip, unchanged" reuse is inline markup in **lane 6's** `Step3_Districts.vue` —
    reusing it means extracting it (which §11 forbids) or duplicating it. Say which.
11. Board hygiene: `lane-05.md` 12:57 still carries pre-revision cost figures the plan's own
    revision reversed. Post the correction.
12. Minor: D-20 #4 says "checker = first output" but §10 puts the extractor first (the parity
    half has no dependency on extraction and the drift is live) · "112 registered locales"
    asserted in bold two sentences before "this document does not assert them" · three
    off-by-a-line citations.

**Good catches (verified):** **the global-rebinding trap** — rebinding the
`TranslationProvider` interface globally would disarm the K-3 privacy gate **while all four
constitutional pins stay green**, because the test builds the gate directly with doubles and
never touches the container; the fix (refuse the global rebind, pin what the controller
actually receives) is correct and this is the round's best single finding · **the
`public_records` immutability discovery**, which kills every sweep-and-fill design fleet-wide,
*plus* the publish-time pending map that fits the already-shipped badge reader with zero
reader change · **vue-i18n is not ICU** — no plural rules registered, warnings off, so a
literal `|`/`{`/`@` corrupts silently across ~5,100 strings × 77 locales, and Arabic's six
CLDR categories meet a three-form selector · `mergeNamespaces` hardcodes five locale codes
and would have silently dropped locales 6–77 · the self-corrected meta-sidecar catch (the
sidecar matched the loader glob and would have shipped provenance to every browser) · corpus
census verified to the file (87 pages / 90 components / 179 `.vue`; 183 `data-no-i18n` sites) ·
spend as an append-only ledger because a stale-reclaimed segment is two billed calls and one
row · declaring the NLLB segment size PROVISIONAL pending a bench with a stated measurement
format.

---

## 4b. Lanes 9–12 — the workflow lanes — **ALL ON TRACK** (notes on 9, 11, 12)

Snapshot: all four were mid-flight during the review (files landing 14:47–14:57). Rails were
judged **at code level, not from prose**. No lane wrote into the app repo — verified two ways.

### ⚑ OPERATOR RULING NEEDED — lane 11 is synthesizing the operator's voice

`bench/xtts_smoke.py:65-67` calls XTTS with `speaker_wav` pointing at
`work/_smoke/ref_jd_20s.wav` — a 20-second reference of the operator — and generates Spanish
speech he never said. The lane's charter **explicitly sanctions this** ("Coqui XTTS-v2
(quality/voice-clone)") and the file is framed as evaluation-only, so the lane is inside its
orders. The problem is the *boundary*: lane 10 solved the identical risk with a code-level
`audio_provenance` enum + fail-closed quarantine, and **lane 11 has no provenance, rail,
publishable or quarantine gate anywhere** (grep across `vtdub/` and `bench/` returns nothing).
The line between "benchmark a cloned voice" and "publish a cloned voice" is currently held by
prose alone. Two questions for the operator: (1) is cloning his voice for dubbing **allowed at
all**, or must every language track be performed or use a neutral synthetic voice? (2) either
way, lane 11 should adopt lane 10's provenance+quarantine pattern so the answer is enforced in
code. Until ruled: benchmarking may continue; **nothing cloned may be published.**

### Lane 9 — Presentations · ON TRACK WITH NOTES
Built: 3 design docs, a house-style system derived from the app's mockup CSS (40 icons × 4
colorways), `build_deck.js`, a **built and rendered** `template.pptx` (→ PDF + 16 PNGs), and a
screenshot harness with a 117-stop target file parsed from the mockups tour.
**Test flow partially run** — corpus parse passed 13/13 self-checks with input sha256 recorded;
8 shots captured at 3 sizes with provenance. The deck itself and the V1–V14 driver are not yet
built. **Lane 9 shipped the `claims_check` lint lane 12 promised** — 6 rules with real teeth:
the build-status rule parses BUILT_INVENTORY §1's table into `{phase → rating}` and fails an
inconsistent claim *even with a correct citation* (calling L or M "built" fails; I/K-2 "built"
fails, "partly built" passes). Open: **no WORKLOG** (its charter names one), deck+verification
not run, and its asks to lane 1 are still open.

### Lane 10 — Video Factory · ON TRACK (cleanest execution in the fleet)
**Test flow RAN END-TO-END and the rail proved itself.** Run `20260725T185609Z_177e9d`
produced master/silent/audio/captions/manifest + 4 per-beat clips + 3 thumbs, QC 4/4 beats,
`duration_delta_s −0.001333` against a one-frame tolerance — and emitted
`publishable:false, publish_blocks:[RAIL-VOICE-01]` because the test audio wasn't operator
performance. The no-synthetic-voice rail is **enforced, not promised**: `audio_provenance` is
settable only in `00-project.json`, never by CLI flag, so an automated run cannot assert
publishability; non-conforming output quarantines under a deliberately hyphenless stem that is
invisible to all four of the operator's ingest filters (each documented with file:line).
**Manifest v1.0 conforms to the 12:38 ruling and exceeds it** — timestamped transcript is
*required by schema*, `exports.variants[]` and per-beat `clips[]` both required, plus two
explicit timebases so a consumer can't silently mix them. `{Subject}` resolved to
**"Governance App"**. Open: WORKLOG predates today's work; the pilot script still carries the
old "five to nine seats" line — free to fix now, a reshoot later.

### Lane 11 — Video Translation · ON TRACK WITH NOTES
Built: the `vtdub/` package (write gate, fitting-grid with explicit capacity algebra,
byte-exact VTT round-trip preserving the library's existing EOL/BOM quirks, never-translate
token protect→restore→*pronounce*), 4 benches, a 77-language join table generated from the
**live** player with names copied verbatim because changing one would mutate 4,697 live tracks.
**Strongest write-gate in the fleet**: `guard_write()` refuses anything under protected roots —
which include the app repo **and lane 10's tree** — with no CLI flag to disable it.
Test flow partially run: the grid stage produced 20 units on a real subject, but `out/` and
`reports/` are empty — no fitted track, no drift report, no benchmark numbers. Also caught a
real app-side gap and **reported rather than patched** it (the `ID_TOKEN` regex misses `Art.`
and `§` though the glossary claims citations are never translated) → routed to lane 5.

### Lane 12 — Social Posting · ON TRACK WITH NOTES
Git-init'd itself and committed `.gitignore` **first**, deliberately, "so the credential rule
binds from commit one". Built `netguard.py` + 20 guard tests, `counting.py` + 55 tests (two real
emoji bugs caught), `limits.py` + 29 tests with per-row citations. **The publish rail is
genuinely enforced in four independent layers**: an AST walk asserting no module imports any of
24 network libraries (with `webbrowser` named because the operator's own script uses it for
OAuth); runtime interception patched at `socket`/`getaddrinfo`/`ssl`/`Popen` rather than at
`requests`, so an unaudited transitive dependency is still caught; a **self-test that makes a
real connection attempt and requires it to be refused** ("a guard never tested against a real
attempt is indistinguishable from a no-op"); and subprocess containment allowing only
ffmpeg/ffprobe with argv scanned for network tokens — because *this box's* ffmpeg is built with
RIST/SRT/SSH/GnuTLS, making `ffmpeg -f flv rtmp://…` a publish. No password/login handling
anywhere. The account blocker is **resolved and already absorbed** (12:38 ruling cited verbatim
in `limits.py`). Open: **`claims_check` does not exist here** (zero hits) while lane 9 shipped a
working one — see the consolidation item below; `PLATFORM_MATRIX.md` + the account-field UI not
built; no WORKLOG.

### Cross-lane items
1. **⚑ Language allowlists disagree — a filename-slot break.** Lane 10 carries 79 tokens from
   the operator's `Combine Videos.py`; lane 11 carries 77 from the live WP player, lacking
   **"Chinese"** and **"Norwegian Bokmål"** and carrying **"Mandarin"** / **"Norwegian Bokmal"**
   instead. Since those tokens *are* the filename slot, a lane-11 `{Subject}-Mandarin.m4a` would
   never pair with a lane-10 `{Subject}-Chinese.mp4` under the operator's inventory scripts.
   Lane 10 already ships `check_language_table.py` for exactly this diff; it hasn't been run
   across lanes. **Lane 10 reconciles as upstream authority.**
2. **Two `claims_check` implementations are converging** — lane 9 shipped one with teeth; lane 12
   owes one. Lane 9's own design doc says it: "two lanes shipping divergent lints is worse than
   one imperfect lint." **Ruling: lane 12 adopts lane 9's implementation** and both swap in lane
   15's §5 machine-readable term table (lane 9's rules file already carries a `_swap_point` for
   exactly this, so it's a data swap, not a code change).
3. **Lane 12 is coded against lane 10's pre-v1.0 emitter** — a pure timing artifact (its headers
   were true this morning, false by 14:56). v1.0's `exports` is an object keyed by role, not a
   positional array of basenames; the version key moved; the frozen fixture still expects
   `"CGA-Intro"`. Fix is a re-read; lane 12 already declared "you are upstream: I adapt to you."
4. **The faction list landed at 14:53 and none of the four has consumed it yet.** Per lane:
   9 swaps its rules file wholesale and re-runs · 10 applies corrected wording **plus the 12:38
   vetted seat-rule line to `01-script.md` before the operator records** · 11 confirms its proof
   specimen is clear in the drift report · 12 builds the lint from §5 by adopting lane 9's.
5. **WORKLOG discipline is the fleet's weakest link right now** — 9, 11, 12 have none and 10's
   predates its own completed run. Board rule 7 makes the WORKLOG the non-repo lane's substitute
   for a commit hash; without it the operator cannot see what a lane did without reading its
   tree. All four: write the WORKLOG before the next work item.

---

## 4c. Lane 15 — Civic Education & Achievements (four documents)

| Document | Verdict |
|---|---|
| `K2_FACTION_CORRECTION.md` (D-16a) | **APPROVE WITH NOTES** |
| `K2_CURRICULUM.md` (D-16c) | **APPROVE WITH NOTES** |
| `K2_ACHIEVEMENT_LIBRARY.md` (D-16b) | **NEEDS REVISION** |
| `K2_ENGINE_PLAN.md` (D-16d) | **NEEDS REVISION** |

Both revisions are narrow. The premise is correctly honored throughout — the engine plan
**extends** `JourneyService` and specifies `education_progress` to the `journey_progress`
shape; all seven iron rails are pinned in both documents.

### ⚑ FREEZE-LIFT RULING
**LIFTED for lanes 9, 10, 11 — unconditionally.** The corrected wording was verified against
live source, not against the document's own citations, and every approved replacement matched
verbatim. Ground truth holds: zero `faction` anywhere in the schema; `endorsements` is
genuinely polymorphic. **Lane 12 may use the PROSE, but §5's JSON must be patched before it is
wired as a publication gate** — see finding 1.

1. **⚑ The lint blocks the document's own approved wording.** `FAC-1` fails on `\bfaction`
   with only four exempt phrases — but **six of the nine approved replacements contain
   "faction"** and none of the exemptions ("faction-independent", "not a faction layer", "no
   faction registration"…). `FAC-2` compounds it by failing "no party column", which the
   document itself nominates as the best on-camera proof. As written, the lint would reject the
   exact copy lanes 9–11 are told to use verbatim.
2. `unless_within` has no defined scope (same line? sentence? document?) in a rule set handed
   to another lane as an implementation contract.
3. **`APP-1` is factually contradicted by the build** — it fails any mention of
   largest-remainder as "no textbook apportionment method is used anywhere in seat allocation",
   but largest-remainder **is** used for committee seat apportionment over the type_a:type_b
   ratio (`CommitteeService.php:28,56,79`; `CommitteeCreationAct.php:15`). See the operator
   item below — the doctrine needs a scope qualifier, not a change.
4. Do-not #6 (the public-square residency-gate error) has no lint rule despite being called the
   largest chart-vs-code divergence.
5. **Webster scope understated by ~13 files** — it survives in `mockups/v3` fixtures/manifests
   and in *visible page copy* in `mockups/v2` ("Webster allocation", "192, Webster-split by
   population"), plus a stale docblock in live app code (`LegislatureController.php:1387`).
   Those are exactly the surfaces lanes 9/10 would screenshot.
6. **Achievement catalog form-ID errors** — `ACH-ORG-FOUNDED` keys to F-ORG-001 (profile
   management, agent-only, no creation branch); founding is F-IND-012. And the verification
   spine has **no actor-vs-subject rule**, so `ACH-IND-VALIDATED` awards the candidate's medal
   to the **board member who validated**, and `ACH-LEG-COMMITTEE` keys to preference *ranking*,
   not placement. Re-audit the catalog against an explicit rule.
7. **The `earned_at` federation table is wrong on two of three rows — and it is the evidence
   base for an operator ruling.** `audit_seq` and `source_server_id` do **not** federate
   (exporter-local, pinned by `assertArrayNotHasKey`), so the "audit_seq compounds it" threat
   narrative is false. **The core finding survives**: `earned_at` is a full timestamptz and it
   *does* federate unredacted, so the coarse-DATE migration is still right — but the operator
   was given an overstated threat model.
8. **The costed three-way on self-reported ticks was promised and not delivered** — the
   documents present a resolved recommendation instead, so the operator can approve or reject
   but cannot compare. Requested at the lane's own 09:37 commitment.
9. Smaller: "nine places" for the never-block quote is three (and not byte-identical, which
   defeats the reason for quoting); the placeholder roll-call is 16, not 17; C2's replacement is
   sourced from a **mockup**, not a built surface, and the ledger item it comes from contains the
   very individual-endorsement claim §1.5 forbids — lanes quoting it need that warning inline.
10. Criterion 8 half-met: lane 5 got the **legacy** corpus number (131,903 words → ≈10.2M at 77
    languages) but not the app-native curriculum K-2 will create.

**Good catches (verified):** **no individual-endorsement write path exists** — the only writer
hardcodes `ENDORSER_ORGANIZATION`, so R-07 and observer standing are org-only, which genuinely
changes what lanes 9–12 may say · the `correct_keys` permanence hazard (rejected filings are
sealed with a sanitized payload, and `SENSITIVE_KEYS` holds no answer-key entry — while the
mockup lesson ships the answer index client-side) · the envelope-not-ballot rail carried through
to its abstention-leak second half · a **live defect**: the sidebar renders an *enabled* Learn
link to a route that does not exist (404) · two constitutional sections absent from the
curriculum chart entirely · PI-5 does not exist anywhere.

### Independent recomputation of the curriculum's heavy figures (deferred check, returned after the review)

The review deliberately did not rest on the curriculum's recomputed numbers; they were sent for
independent recomputation from the source artifacts. Result: **the structural claims hold, most
of them exactly** — the drawio parse (1,676 cells, the 9 band headers, 205 content cells → 204
distinct with one genuine duplicate), the 8 Unit labels verbatim, the Topic_Knowledge totals to
the character (131,903 words), the 9:27:35 runtime, and — impressively — **Article I's 24 chart
lessons are word-for-word identical and in order to the Template's 24 clause titles**, with all
six claimed coverage gaps structurally confirmed.

Real defects found, for lane 15 to fold in:
- **`config/cga/surfaces.php` has 70 records, not 69** (the `system` module is 6, not 5) — and
  **4 records are missing the `workflows`/`clocks` keys entirely**, so "each with 8 keys" is
  false. The 71 WF-codes are right but tied to the wrong denominator.
- Two "zero matches" claims are false on literal search (the substantive conclusion survives —
  every hit is a homonym: "Full Faith and **Credit**", "All **Points** Matter", "E**xp**ansive").
- The "62 percentage labels" and "61,036-word constitutional subset" figures **could not be
  reproduced** by any measure tried; likewise the doc's own "549 labels" is unsourceable
  (nearest candidates 543/547/555). Drop or re-derive.
- Bookkeeping: **4 of the 108 canonical forms are never referenced in any surface record** —
  `F-LEG-029`, `F-LEG-030`, `F-SOC-003`, `F-SOC-004`.

**⚠ Severity correction to a finding this desk relayed:** the "sidebar renders an *enabled* link
to a 404" defect is real and mechanically exact, but it is **not live on the app's primary
surfaces**. The legacy `AppShell`+`nav.js` sidebar is the fallback layout for only ~5 of 88
pages; 66 pages opt into `AppShellV2`, whose nav hardcodes `href: null` for learn, and the Setup
pages force minimal chrome. In practice it is reachable at the public `/operator/operations`
route and on four `local`-only dev-kit pages. Still worth fixing — but it is not the
front-of-house defect the earlier report implied.

### ⚑ CONFIRMED LIVE: the §5 lint defect has propagated into lane 12's publish gate

Predicted in finding 1 above; now real. Lane 12 imported `K2_FACTION_CORRECTION.md` §5 verbatim
at 15:27 with a sha256 pin and an explicit **do-not-hand-edit** note (`claims/terms.json`,
`source_kind: lane-15`, 8 upstream rules + 1 local guard). Lane 12 did exactly the right thing —
which is precisely why the defect is now enforced downstream:

| Rule | Severity | Live effect |
|---|---|---|
| `FAC-1` | **block** | Exemptions are the same four phrases ("as implemented", "helpful colors", "illustrative grouping", "the Template still says") — **none** covers the approved replacements *"faction-independent"*, *"not a faction layer"*, *"no faction registration"*. The gate rejects the fleet's own approved wording. |
| `FAC-2` | **block** | Fails "party column" — the phrase lane 15's own document nominates as the best on-camera proof. |
| `APP-1` | **block** | Fails any mention of largest-remainder, which **is** used today for committee seat apportionment. Accurate copy about the bicameral committee split is unpublishable. |

**Fix at the source, not downstream:** lane 15 patches §5 (widen `FAC-1`'s exemptions to the
approved wordings, scope `unless_within`, narrow `APP-1` to seat *allocation* per the doctrine
clarification in §5 item 9), bumps `upstream_version`, and lane 12 re-imports. **No lane should
hand-edit the imported table** — lane 12's discipline here is correct and worth preserving.

*(Credit where due: the imported set also carries `SEAT-2`, which warns on the "951,626
legislatures" conflation this desk corrected — the fleet is now policing that error itself.)*

### Follow-up on the revised catalog (`a4d1179`, 127 medals — checked by lane 7 after the review)

The catalog was **re-derived from code rather than patched**, which is the right response: both
specific form-ID errors are gone, and the spine now routes non-form evidence (seatings,
memberships, confirmations) to `RoleService` role-derivation instead of forcing a form match.
Verified: 83 personal medals, and a scan of every passive-named medal keyed to a form found
**exactly one surviving actor-vs-subject mismatch**:

- **`ACH-CAN-005` "Candidacy validated by the board" → `ref=F-ELB-002`.** That form is filed by
  the **election board member** (R-08), not the candidate, so under the stated spine
  (`actor_user_id = :user AND ref = :form`) the medal lands on the board member — every board
  member collecting "candidacy validated" medals for other people's candidacies, and no
  candidate ever earning it. Correct evidence for the candidate is the validated-candidacy
  fact (`candidacies` status), not the board's filing.
- The other four passive-named form-keyed medals check out (`ACH-EXE-006`, `ACH-JUD-009`,
  `ACH-ELB-003` all describe acts the earner *performed*). One to confirm rather than fix:
  `ACH-ORG-005` "Your org endorsed a candidate" keys to `F-ORG-002`, filed by the org's agent —
  fine if the medal is the agent's, wrong if it is meant for all org members.

**Still worth one sentence in the document:** the escape hatch names *categories* (seatings,
memberships, confirmations) rather than the general rule. State it — *the actor on a form is not
always the medal's earner; where a form's role gate is a third party, use the fact query* — so
the next catalog pass can't reintroduce the class.

**Lane discipline note (self-disclosed, no harm):** commit `ddde935` swept lane 3's plan file
into a K-2 commit (`git add <path> && git commit` commits the *index*). The lane found it
itself, disclosed it in full with the mechanism, and proposed the fleet-wide fix
(`git commit -- <path>`). No work lost; lane 3 committed on top.

---

## 4d. Lane 13 — Economy Engine

**Sequencing is correct, not a violation:** the operator cancelled the walkthrough in-chat and
directed the lane straight to design, so `ECONOMY_ENGINE_PLAN.md` (D-14, `31cca78`) landing
before the walkthrough is his call, not a jumped gun. The audit plan shipped first anyway
(`0bd9a63` + `b459881`).

**The lane self-corrected a published claim** — it had written that `acquired_via='founding'`
has no writer anywhere; `CgcService.php:147-153` is one. It published the correction with the
narrowed finding rather than quietly amending, which is the standard this fleet should hold.

**Its security finding is the most serious item of the entire round — verified by this desk and
promoted to risk-register #19 (LAUNCH-BLOCKING).** See §5 item 8.

### `ECONOMY_AUDIT_PLAN.md` — **APPROVE WITH NOTES**

Genuinely a walkthrough script, not a design doc in disguise: every station is
observation-first with a method code, all eight named systems carry the four required
elements, the vocabulary concordance is explicit, and both commits touched only the lane's own
file. Act 2 is runnable end-to-end. **The self-correction is exactly right** — one writer for
`acquired_via='founding'`, zero for `'issue'`, published as a correction rather than quietly
amended.

1. **⚑ The security finding's framing was wrong in a way that changes the fix — and the correct
   diagnosis is stronger.** The plan said the endpoint's "three immediate neighbours are all
   auth-gated"; they are not — `state`, `cosmic-address`, the bootstrap trio and the step
   endpoints are *all* unauthenticated, because the pre-founding setup API is deliberately open
   (nobody is logged in before `createFounder`). **The defect is the missing
   `isSetupComplete()` refusal**, which every sibling with post-founding consequence carries.
   Rest the finding on that leg. **Corollary (widens the finding to a class):**
   `POST /api/setup/wizard/step2/start` is itself a **live ETL trigger on a founded world** —
   an endpoint risk, not the navigation risk the plan called it. → risk register #19, updated.
2. `equal_partnership` is called "a validated label with no distinguishing behaviour" — false,
   and it contradicts the plan's own declared base: `BoardElectionAdministration.php:72-75`
   seats one owner per active partner. The decision question survives; the premise doesn't.
3. §1b's rename evidence is wrong (`neverFederated` is a string property that still exists;
   `privacyNote` appears nowhere) — and the truth **strengthens** the blocking question: the
   operator's ruling was never propagated into the fixtures.
4. Act 2 step 5's `adm_levels` cannot be entered — the wizard never sends that field. Harmless
   to the outcome, but the instruction is unfollowable.
5. §2.3 and §2.5 contradict each other on whether Act 2 needs the fleet's one-lane `migrate`
   slot. §2.5 is right (migrations already applied; it's a no-op) — don't burn a coordination slot.
6. **Station 0's own numbers open a hole S4 never sees**: 956,336 jurisdictions vs 951,622
   settings rows = **4,714 jurisdictions with no settings row**, for which S4's universal ("the
   resolver always matches the child's own row first") is false — the ancestor walk does reach.
   The proof query only groups rows that exist, so it structurally cannot see them.
7. **The ledger substrate — L's central deliverable — has no station**, and the ordered rail
   (reuse the existing `audit_log_block_mutation()` chain with `LedgerService` as sole writer,
   never fork it) appears nowhere in the doc. Add a short station so the walk ends with the
   operator having *seen* the chain he's being asked to reuse.
8. Two ordered rails absent: **currency reserved to root** (and the doc holds the disconfirming
   evidence unused — all 951,622 rows carry `currency_name/code/symbol`, i.e. every jurisdiction
   on the planet has private currency columns, which is sharper than "the cascade is dead") and
   **UBI eligibility = active residency ONLY** (appears once, as quoted help text).
9. **D-08 dispositions are routed to the design lane, but the ledger says the OPERATOR owns
   them** — so as written the walkthrough will never put them in front of him. Move into §6.
10. Q5 records the age settings as absent but never asks the operator for the **values** — the
    decision D-09 actually poses.
11. §6's engineering list oversteps: two entries are D-14 design decisions, not dials, and one
    sits in **lane 6's** path (which the plan itself flags two sections earlier).
12. Accuracy sweep: "exactly three callers" of `openStake` is four; the 56-command menu is 55;
    the `org_conversions` fair-market CHECK is null-permissive on both sides (weaker than
    implied); "no code path cites Art. II §8" is marginally too strong (narrow to
    *enforcement* paths); several path/range citation drifts.

**Good catches (verified):** the §1a security finding down to every element, including the
**CLK-13/14 desync** second-order consequence (armed timers drift from `worker_rep_*` because
the rederive job never fires on that path) · `writeConstitutionalSettings` is a raw
`DB::table()->update()` bypassing model and validator, and the setup-lock middleware cannot
backstop it · **S2's three-edit cost analysis** — bounds rejection runs *before* the door check,
so adding a key to `DUAL_DOOR_KEYS` alone is genuinely inert (bounds + door + register + pin is
the real cost) · **S9 corrected the lane's own brief**: `FORBIDDEN_ELIGIBILITY_KEYS` early-returns
outside the six rights-automatic forms and inspects only payload *keys*, so `NO_FEE_FORMS` really
would be the **first** no-fee rail — master §3.0 and the lane-13 opening prompt both need that
correction · a validator docblock names a pin file that does not exist · the `curl` false-pass
trap in the setup lock (and console runs bypass it entirely) · the `-p fc` hazard matching
*neither* stack · §4.0's ordering discipline (stations ordered around the three observations that
advancing the world destroys) · the honest yield statement before asking for two hours of the
operator's time · the co-determination chain verified hop-for-hop.

---

## 4e. ⚑ PROCESS FAILURE — the shared index sweeps other lanes' in-flight work (three confirmed instances, including this desk)

Reported by lane 5 at 15:40; this desk then found it had caused an instance itself.

**Mechanism (counter-intuitive, which is why it keeps happening):** `git add <my-file>` stages only
that file — but `git commit -m …` **commits the entire index**, including whatever another lane
staged in the shared working tree and had not yet committed. The `add` is safe; **the `commit` is
what sweeps.**

**Confirmed instances:** lane 15's `ddde935` took lane 3's 710-line plan file · lane 5 reports being
swept repeatedly · **lane 7's `4ac2d7e`** — nominally a one-file docs correction — committed **70
files, 69 of them lane 5's** entire i18n build (extractor, gate, status board, 35 locale + 35 meta
catalogs, a controller, a Vue page). Lane 5's own commit `002fb44` two minutes later therefore holds
only 3 files.

**Nothing was lost in any instance.** The damage is (a) attribution and (b) **revertability** —
reverting lane 7's "curriculum" commit would delete lane 5's build. History is deliberately **not**
being rewritten: fifteen lanes share this tree, and rebasing published commits under active work is
far more destructive than a wrong message. The record is the remedy — **lane 5's step-1/2 build
lives in `4ac2d7e`.**

**THE RULE (recommended fleet-wide, adopted by this desk immediately):**
`git commit -- <path> [<path>…]` — the `--` pathspec commits ONLY those paths and ignores the index.
**Never `git add X && git commit`.** Then verify with `git show --stat <sha>`; if the file count is
not what you expect, post it to the board at once. Recommend adding both to board README rule 7.

## 4f. ⚑ MIGRATION NUMBERING COLLIDED — the slot rule doesn't cover filenames

Three lanes now write migrations (3, 4, 13); five landed today. **Two independently took the same
sequence number:** `2026_07_25_000001_instance_class` (lane 4) and
`2026_07_25_000001_add_monetary_settings` (lane 13).

**Harmless this time, by luck** — verified they touch different tables (`instance_settings` vs
`constitutional_settings`), so neither depends on the other's objects. But Laravel orders by
filename, so on a **virgin install** (i.e. the Azure node) `add_monetary_settings` runs *before*
`instance_class` purely on alphabetical tiebreak. A future collision between two migrations that
*do* share objects would break fresh installs while every existing box stays green — precisely the
failure mode CLAUDE.md already warns about ("never date a migration before an object it references…
a real-dated file landing mid-sequence broke virgin installs").

**Gap:** the one-lane-at-a-time **slot** rule serializes `migrate` *runs*; it does not stop two
lanes from *naming* files independently, because names are chosen while another lane holds the slot.

**RESOLVED 2026-07-25 17:14 — per-lane ORDINAL BLOCKS assigned** (adopting lane 4's proposal over
this desk's weaker one; lane 4 counted **three** collisions across four lanes, not one). Claiming a
number on the board still required every lane to read the board before naming a file; **blocks
require no coordination at all**: lane 1 `1–9` · lane 3 `000010+` · lane 4 `000020+` · lane 13
`000030+` · lane 14 `000040+` · lane 5 `000050+` · lane 2 `000060+`. Within a block a lane is the
only writer, so collision is impossible rather than unlikely. **The five already-applied files are
NOT renumbered** — renaming an applied migration re-runs it on every box that has seen it, and
today's ties are verified independent. Fresh installs must still be smoke-tested from the migration
**files**, not only the flattened baseline.

### ⚑ Fleet-wide: `ConstitutionalVersionTest` can go FALSE RED under concurrent work

`ConstitutionalVersionService` hashes the hardened files **off disk**, and `DistrictingService.php`
is on that surface — so if any lane commits to a hardened file *while another lane's suite runs*,
the test fails for a reason unrelated to the code under test and does not reproduce. **Both
directions matter:** a red result during concurrent work is not automatically real (re-run alone
before believing it), and a green result is not proof if a hardened file changed mid-run. Report
either outcome on the board rather than silently re-running until green. Found by lane 4.

### Suite state during the build wave: 571 passed / 8 failed — and the 8 are NOT this wave's

Lane 3 ran a clean full suite on a quiet dev DB (571 passed, 8 failed, 134 skipped, 295,207
assertions, 752s — down from 11) and **proved** the remainder weren't its own by reverting its five
files to their pre-build state and re-running to the identical eight. Lane 7 extended the
attribution, because "not lane 3's" ≠ "pre-existing" while lanes 4 and 13 also have code in the
tree: **not one of the eight tests' subject files was touched in today's wave** (swept every commit
since 12:00 against CgcIpRegisterService/OrgOwnership/Conversion/Transfer · CoDeterminationService/
OrgMembership/org_contracts · TabulationRecorder/Domain-Counting/VoteCounting/BallotCrypto ·
ColdSync/Mirror · Matrix/social · Transport/Federation). **The build wave did not break these.**

**Working hypothesis: environmental, not code.** Three of the original eleven vanished purely by
seeding `clocks` — database-state failures wearing the costume of code failures. The dev box is a
virgin install, and the reported causes are assertion failures plus *one empty-uuid cast*, which is
what a lookup returning nothing looks like. **Re-run on a seeded instance before reading service
code.** Not assigned to a build lane mid-wave; lane 7 carries them until a seeded run adjudicates.
**Lane 2's checklist gains an item: a green suite must be demonstrated on a SEEDED instance** — a
virgin box cannot produce one, and that failure mode is indistinguishable from broken code at a
glance.

### Two SQL constructs that pass review and fail in production (lane 4's pins, propagated)

1. **`make_interval(secs => ?)` is broken with a bound parameter** — PDO binds integers as text, so
   it fails **at runtime under load**, not at parse time. Any lane binding into `make_interval`
   has a latent production failure its tests may not show.
2. **`ON CONFLICT ON CONSTRAINT` resolves constraint NAMES only** — an expression index can never
   be one. Relevant to anyone copying the autoscale re-mint idiom onto a nullable-column key.

## 4g. Lane 13's ledger spine (L-2, `01d019a`) — a REASONED deviation from an ordered rail, checked and endorsed

The charter ordered the ledger to reuse "the **same** `audit_log_block_mutation()` trigger". As
built, it reuses the *primitives* — `AuditService::chainHash` / `canonicalJson` / genesis constant,
explicitly "one hash discipline in the codebase, not two" — but runs its **own** chain, its own
`ledger_entries_immutable` DB trigger, and a **separate advisory-lock key** (`0x4c45444752`,
"LEDGR"), documented as *"distinct from the audit chain's key so the two chains never contend."*

**This is the better design and it should not be flagged as drift.** It absorbs the fleet-wide
finding lane 4 made and this desk broadcast (risk #13): `AuditService::append()` holds a
**transaction-scoped global** advisory lock, so routing every economic posting through the audit
chain would serialize the entire economy behind the one global appender. A separate chain with
identical crypto and DB-enforced immutability keeps the guarantee without the contention. It also
matches the house pattern — the schema already carries six sibling per-table immutability
functions, so a per-table trigger is the convention, not an exception.

Other rails verified present: `LedgerService` is the **sole writer**, enforced by a source scan
over `app/` (the same technique `CgcIpPublicDomainTest` uses for the public-domain register);
direction semantics stated explicitly because the accounting convention is ambiguous and the
ambiguity would be a bug; **minting deliberately lives elsewhere** (`IssuanceService`, slice L-3)
so money can only come into existence where Art. V §5 permits; a correction is a new balanced
posting, never an edit.

**Doc action:** the roadmap's §Phase L wording ("same `audit_log_block_mutation()` trigger") should
be read as *same hash discipline*, not *same trigger object* — worth a one-line annotation if the
charter is ever revised.

## 5. Fleet-wide items (belong to no single lane)

1. **⚑ `racePlan()` blocks a whole election plan when only the Type B half is illegal.**
   Found independently by lanes 3 and 4. Run-level `$blocked`; `scheduleGeneral()` returns
   before `createRaces()`; `generateRaces()` throws for the entire plan. 30,262 legislatures
   affected; 23.8% of planet seats collateral. **Operator decision** (it touches the
   elections engine): make `racePlan()` per-kind so lawful Type A district races schedule
   while only the illegal Type B half defers. Risk register #16.
2. **Append audit summaries LAST** in any chunked/bulk writer (#13) — lanes 1, 3, 4, 13.
3. **No artisan command carries an environment guard** (#14) → lane 2's launch checklist.
4. **The install path** (#17) and **the sizing floor** (#18) → lane 2.
5. **Verify the caller, not just the implementation** — the §0 lesson, now a standing rule
   for this desk's audits.
6. **`public_records` rows are immutable (trigger-enforced)** — every sweep-and-fill or
   backfill design against them is dead on arrival, fleet-wide. Anything that must appear on
   a public record has to be written **at publish time**. Found by lane 5; binding on any lane
   that plans a records backfill.
7. **Rebinding a container interface can disarm a constitutional rail while its pins stay
   green** — the K-3 translation privacy gate is the live example (its test constructs the
   gate directly and never touches the container). Any lane swapping a bound implementation
   must add a pin on *what the production consumer actually receives*, not just on the class.
8. **⚑⚑⚑ LAUNCH-BLOCKING: `POST /api/setup/constants` is unauthenticated.** No route
   middleware, no `is_operator` check, no setup-complete refusal — while the sibling endpoint
   `saveGameMode` on the next line has all three *plus a comment explaining why a guest trigger
   would be unacceptable*. All 29 constitutional keys are writable by anyone who can reach the
   URL on a founded world, and the armed CLK-13/14 timers then desync from the settings row.
   Found by lane 13, verified by lane 7 at both the route and handler layers. Risk register
   #19 → lane 2's launch checklist as a **blocking gate**. Every dual-door and supermajority
   guarantee above it is decorative until it closes.
9. **Doctrine scope question for the operator (not a change):** the seating law says no
   textbook apportionment method appears "anywhere in seat allocation", but **largest-remainder
   is used today for committee seat apportionment** across the type_a:type_b ratio
   (`CommitteeService.php:28,56,79`, Phase C, built and shipped). The districting/seat-budget
   doctrine is untouched by this. What is needed is a one-line scope qualifier — the law governs
   *jurisdiction and legislature seat allocation*; committee splits within a chamber are a
   different layer — so lints and teaching copy stop contradicting the build. **Operator's call
   to word it; nobody should "fix" either side unilaterally.**
