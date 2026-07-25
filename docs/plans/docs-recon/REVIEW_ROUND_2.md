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

## 4b. Lanes 13, 15, 9–12

*(reviews in flight at the time of this section's writing — appended as they land)*

---

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
