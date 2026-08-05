# Setup-Scale Systems — ETL Paradigm Audit and Integration Plan

**Fourth document of the package** (with the Type A plan, the Type B plan, and the courts addendum). Reviewed at `670ed3d`. **Audience:** the AI refactoring the initial setup code and experience. **Scope:** institution provisioning (executives, judiciaries, boards, and the eager set), sub-institutions (committees and kin), the simulated-world engine's dev-mode sample-data fill, and how all of it integrates into the Setup Steps.

**Headline:** the paradigm has propagated further than anyone tracked. The provisioning service cites "THE ETL RULE" in its own chunk loop; the sim pump's docblock *narrates the autoscale lesson* ("the self-rescheduling orchestrator tick-chain was deleted after it worked for ~3 of 26 hours") and builds accordingly. The remaining work is not re-engineering — it is one missing width, one intent table, one acceptance battery, and a wizard that surfaces engines it already owns.

---

## 1. Scorecard

| Subsystem | Multithreaded | Chunkable | Resumable | Visible | Fast | Verdict |
|---|---|---|---|---|---|---|
| Institution provisioning (`InstitutionProvisionService`, 5 eager steps) | **✗ single lane** | ✓ keyset chunks, one committed txn each | ✓ partial-unique-index idempotency; re-dispatch safe | ✓ progress callback → `/building` 2 s bars; deliberate skips counted, never silent | ✓ set-based per chunk | §2 |
| Sub-institutions (committees, departments…) | — (tier-gated by design) | — | — | — | — | §3 — intent table needed |
| Sim/demo engine (`SimItem`/`SimClaims`/pump, 6 stages) | ✓ pull workers, HostCapacity single width dial | ✓ items per stage; ~907 k-row enumerations async, never in-request | ✓ stale reclaim (two-class), lease cull, halt/resume = one column | ✓ console 2 s poll, per-stage counters | ✓ set-based stage enumeration | §4 — **built, untested** (operator's words); battery prescribed |
| Setup wizard (Steps 1–3 + `/building`) | inherits engines | inherits | Step 2 yes; Step 3 partially | Step 2 yes; rest scattered | — | §5 — the integration itself |

## 2. Institution provisioning — one gap, instrument before fixing

`STEPS = ['executives','judiciaries','election_boards','board_members','social_spaces']`. The shape is right everywhere but width: `provisionAll` is a serial foreach inside one queued job. The dependency structure permits more — `board_members` follows `election_boards`, but executives/judiciaries/social_spaces are mutually independent, and *within* a step the keyset chunks are exactly the raster-band shape, trivially promotable to claimable range items on the autoscale engine.

**Ruling requested from the paradigm itself: measure first.** Log per-step wall time on the next planet run. If every step lands under ~10–15 minutes single-lane, the serial job is honest and simple — leave it. If any step exceeds that, promote *that step* to range items (the idiom exists three times over; this is a day, not a design). Do not pre-build width the numbers haven't asked for. Two small additions regardless: a `halt_requested_at` honored between chunks (parity with every other engine — chunks are short and idempotent, so halt = stop dispatching the next one), and the per-step timings landing in the run record so the measurement is free.

Also verified and worth preserving in the refactor: the population **binding** is read once per run as a founding property (`real` vs `free` — one place cannot exempt itself from the world's physics), and `skippedUninhabited()` reports deliberate skips so "skipped" is never confused with "missing."

## 3. Sub-institutions — an intent table, not a defect list

Committees are **not** in the eager STEPS, and `Committee::create` exists only in `CommitteeService` at chamber-act time. That is *consistent* with the parent plan's tier dial ("what exists in a place is a function of how far that place has come") — a committee is a chamber's act, not scaffolding. But the operator's expectation ("should be built out already") and the eager ruling ("we do it all") need explicit reconciliation, or this becomes a surprise during setup review. **Deliverable for the parent plan:** a disposition table enumerating every sub-institution family with its provisioning class — *eager* (in STEPS), *tier-gated* (created by activation/act, with the creating path named), or *stage-added by this package* (bench ladders and court panels per the courts addendum; Type B grouping per its plan). Committees: tier-gated at chamber act — confirm with the operator. Departments, oversight organs, Matrix rooms (the §5.5 capacity question is flagged unverified in the parent plan itself): each gets a row. Ten lines that prevent a week of "where are the committees?"

## 4. The sim engine — paradigm-mature, verification-poor

The design is the best-practices reel: pump as the **only** liveness root with seven ordered idempotent duties; phase advance pump-only ("a worker that can advance a phase can advance it twice"); **two-class stale reclaim** — 30 min for CPU items, 4 h for network/LLM items because reclaiming a rate-limited research call early *duplicates a call that costs money* (cost-aware reclaim is a genuinely new trick the other engines should steal); pg-crash breaker that pauses and never governs; halt/resume as single column writes honored at claim boundaries; supersede-duplicate-runs; UI↔CLI parity with the guards living in the shared service; the synthetic-data guard failing **closed** (an unanswerable DB resolves to production and refuses). Six stages: Identity → Cohort → Governance → Election → Counting → Seating.

Since it is built-and-untested, the audit prescribes a battery, not changes — run in dev mode, in order: (1) `kill -9` a worker mid-claim in each stale class; verify reclaim at 30 min / 4 h respectively and zero duplicated network calls; (2) double-fire the pump deliberately; verify harmless by construction; (3) halt mid-stage; verify workers park at the next claim boundary within the minute and resume rewinds correctly; (4) run the same stage at two widths; verify identical *structure* (and write down the determinism posture explicitly: structural determinism, not content determinism, wherever LLM-backed stages are inherently nondeterministic — say it, don't leave it implied); (5) attempt `sim:start` against a non-sandbox instance; verify the refusal sentence, verbatim, on both doors. Pass that battery and the sample-data fill is a certified ETL citizen.

## 5. Setup Steps — the integration this package exists for

Step 2 is the pattern: `start` / `progress` / `control` endpoints around a real engine, a bar that tells the truth, halt/resume that work. The refactor's job is to make the *rest of setup look like Step 2*, in an ordered ladder where each rung is an engine the codebase already owns:

1. **Step 1** — instance identity/activation (as-is).
2. **Step 2** — geodata ingest → acceptance (as-is; the scan chips per Stage S).
3. **Step 3** — the autoscale run *surfaced as a first-class step*: sizing → sweep/single → adjacency → `type_b_group` → court panels (per the Type B plan's Stage T-B and the courts addendum §3), with the run panel's per-kind bars. The Step-3 type-B *button* becomes a dispatcher into this engine — already specced (Type B plan §2.6); the serial-foreach-in-request pattern is the one named anti-pattern this refactor must purge wherever found.
4. **Step 4** — institution provisioning, promoted from the `/building` operator page into the wizard proper: same job, same bars, plus the §2 halt flag. Gated on Step 3 complete (it consumes legislatures and seats).
5. **Step 5 (dev mode only)** — simulated-world populate: render only when `syntheticSafe()`, surface `SimRunControl`'s start/halt/resume, embed the sim console's counters. The guard already fails closed; the wizard merely inherits it.

Cross-cutting rules for the refactor, all with precedent in-tree: every step is **re-enterable** — returning to the wizard shows live state, never restarts (the run/items tables are the state; the wizard is a view of them); every long operation runs **off-request** (ProvisionInstitutionsJob's own docblock states the law); one width dial per engine (HostCapacity's contract — no second dials in wizard code); progress denominators come from enumerations, not guesses; and failure surfaces carry **reasons**, not counters (the lesson the type-B button teaches by counterexample).

## 6. Build order for the refactor AI

1. The Step 3/4/5 wizard shell around existing engines — endpoints, bars, gating. No engine internals change.
2. Type-B button → dispatcher (specced), and a sweep for any other request-inline foreach in SetupController.
3. Provisioning: halt flag + per-step timing capture. Range-item promotion **only** if the timings demand it.
4. The §3 disposition table lands in the parent scaling plan; operator confirms the committee row.
5. The §4 sim battery runs in dev mode; findings filed like any drift census.
6. The courts addendum's stages slot into Step 3's run per its own build order.

The through-line for whoever does this work: nothing here invents. Every mechanism this audit touches already exists and already embodies the paradigm — the refactor's whole job is to let the setup experience *show* what the engines already are.
