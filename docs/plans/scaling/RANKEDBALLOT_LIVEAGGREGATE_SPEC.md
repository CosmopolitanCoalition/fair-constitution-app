# RankedBallot liveAggregate — build spec (hand-off, cold-start ready)

*Lane 3, 2026-07-29. The single open Wave-2 item, deliberately deferred for
fresh treatment: it is the one build in the wave where a mistake is a
**constitutional violation** (a weakening of ballot secrecy), not a bug. This
spec is written so a fresh session builds it with zero re-derivation. Verified
against the code by the Wave-2 surface map — file:line references are current.*

## The one-line task

Fill `BallotController::show()`'s hard-coded `'liveAggregate' => null` with a
real "if the window closed now" running standing — **but the tally must be
produced OUT OF BAND, never in the HTTP request**, because the only path from
encrypted ballots to cleartext rankings forbids a request-stack caller.

## The non-negotiable constraint (read first)

**`BallotBox::decryptForCount()` is the ONLY path from `ballots.payload_encrypted`
to cleartext rankings, and its docblock (app/Domain/Ballots/BallotBox.php:52-61)
forbids any HTTP-request-stack caller** — "never a controller, never anything
with an HTTP request in its call stack." `BallotSecrecyTest`
(tests/Constitutional/BallotSecrecyTest.php) actively greps the codebase for
rogue decrypt/writer patterns. **An in-request provisional count trips it and
weakens the secret ballot (Art. II).** This is settled and non-negotiable; only
the *cadence* below is an open question. So:

- the tally is computed by an **out-of-band worker** (queued/scheduled), the
  legitimate non-request caller of `decryptForCount()`;
- the worker **caches a tiny per-race aggregate**;
- the controller **only reads the cache** — it never decrypts.

## What exists today (do not rebuild these)

- **Frontend is DONE.** `resources/js/Pages/Elections/RankedBallot.vue` (prop
  line 46; the card at 435-463) fully renders a `liveAggregate` object shaped
  `{ ballotsSoFar, quotaIfClosedNow, top: [[name, votes], …], remainderNote }`
  as an StvBar list with the Droop mark and `elected = votes >= quotaIfClosedNow`.
  The card is `v-if="liveAggregate"`, so `null` renders nothing (no fake). It
  updates **once per page load** — there is no poll (the only interval, 66-77,
  is the window-close disable).
- **The backend is a null stub:** `app/Http/Controllers/Elections/BallotController.php:105-107`
  sets `'liveAggregate' => null, // null until its backend WI lands`.
- **The canonical post-close path (the model to mirror, NOT reuse in-request):**
  `TabulationRecorder::countInput()` (108-126) builds a `CountInput` via
  `candidacyIds()` + `BallotSet::fromRankings(decryptForCount(race))`; it runs
  inside `TabulateRaceJob` AFTER the window closes. `TabulationRecorder::complete()`
  (144-213) flips `ballots.counted = true` and writes a `tabulations` row — a
  provisional count must do **NEITHER**.
- **The daily-cadence precedent to model the worker on:** the approval standings
  are computed by a scheduled rollup (`app/Jobs/ApprovalStandingsRollupJob.php`,
  design §B.4 — daily-aggregated/frozen). The ranked-window worker follows the
  same shape.
- **Names for `top[]`:** `StvRoundPresenter::candidateRefs(raceId)` (292-311)
  maps `candidacy_id → { name, … }`. Ballots carry only candidacy ids; reuse
  this helper (the Results surface already does) — do not re-query.

## The computation (exactly this, no more)

A **first-preference projection**, NOT a full STV run. The card copy
(RankedBallot.vue:458-462) promises "Projection only — surpluses and
eliminations transfer at the close." Running `VoteCountingService::countStv`
would compute transfers/eliminations the copy says are NOT shown, and would
mislead. So, over the ballots cast so far for the race:

1. Tally each ballot's **first continuing preference** (after canonicalization).
2. `validBallotsSoFar` = ballots with a first preference present (matches the
   engine's `totalValid` semantics — empty/all-unknown ballots are invalid and
   excluded from the denominator).
3. `quotaIfClosedNow = floor(validBallotsSoFar / (seats + 1)) + 1` (Droop).
4. `top` = per-candidacy first-preference counts, sorted desc, mapped to names
   via `candidateRefs`.
5. `remainderNote` for the tail (the copy's contract — see the Vue).

## The build (files + shape)

1. **Worker** — a queued/scheduled job (model on `ApprovalStandingsRollupJob`)
   that, for each race in an election whose status is `ranked_open`, calls
   `decryptForCount(race)` (LEGITIMATE — it is not on a request stack), computes
   the projection above, and writes a small cached aggregate. **Throttle it**
   (the decrypt is the same heavy op `TabulateRaceJob` does; the 412k-ballot
   Queens fixture shows the scale — do NOT decrypt every ballot on every page
   GET). Cache store or a clearly **non-authoritative** table — NEVER a
   `tabulations` row, NEVER `ballots.counted`, so `TabulateRaceJob`'s
   idempotency gate (74-88: a COMPLETE 'initial' tabulation is terminal and
   flips the race to TABULATING) is never tripped.
2. **Consumer** — replace the `null` at `BallotController.php:107` with the
   cached aggregate for the viewer's race, shaped to the prop contract
   `{ ballotsSoFar, quotaIfClosedNow, top: [[name, votes]], remainderNote }`.
   Return `null` when there is no aggregate yet (before the window opens) — the
   Vue already handles null.
3. **(Optional) auto-refresh** — only if "live" must update without a reload:
   add an Inertia partial reload `only: ['liveAggregate']` on the page's
   existing timer. Not required by the current frontend; call it out, don't
   assume it.

## Verification (adversarial — this is the point)

- **`BallotSecrecyTest` stays green** — its rogue-writer/decrypt grep must not
  fire. This is the gate.
- **A NEW pin** asserting there is **no request-stack decrypt path**: e.g. the
  only callers of `decryptForCount` are the tabulation pipeline, audit re-runs,
  and the new worker — never a controller. (Model the assertion on
  BallotSecrecyTest's existing source-scan style.)
- A pin that the aggregate is **null before `ranked_open`** and **populated
  during it**, and that computing it writes **no `tabulations` row and never
  flips `ballots.counted`** (assert the counts before/after).
- Screenshot/DOM proof of the card rendering a real projection on a race with
  ballots (carry pixels if no pane composites — the fleet condition).

## ⚖ The one operator input (does NOT block the mechanism)

**Cadence: per-request-fresh vs daily-frozen.** Approval standings are
deliberately daily-frozen (§B.4); §B.5 says ranked-window standings "stay
visible through the window by contract." Live per-window first-preference
standings can **influence later voters** — a secret-ballot policy call.
Queued for the operator (WAVE2_QUEUE, with lane 3's framing). **The
no-in-request-decrypt MECHANISM is fixed regardless of his answer** — only the
worker's *schedule* (every-few-minutes vs once-daily) waits on it. So the worker
can be built now; wire its cadence to a setting the operator's answer sets.

## Trap list (the surface map's gotchas, condensed)

- In-request decrypt = the central trap. Out-of-band only.
- Don't over-build into a full STV projection — first preferences + Droop only.
- Don't trip finalization — no `tabulations` row, no `ballots.counted`.
- `quotaIfClosedNow` uses VALID ballots, not raw ballot/envelope count.
- `top` needs NAMES — reuse `StvRoundPresenter::candidateRefs`, don't re-query.
- The frontend has no poll — "live" auto-refresh is an ADDITIONAL change, not
  implied by the current Vue.

## Pointers

- Controller stub: `app/Http/Controllers/Elections/BallotController.php:105-107`
- Vue (done): `resources/js/Pages/Elections/RankedBallot.vue` (46, 208-213, 435-463)
- Decrypt boundary: `app/Domain/Ballots/BallotBox.php:52-61, 328-360`
- Post-close model: `app/Services/TabulationRecorder.php:108-126, 144-213`
- Names: `app/Http/Presenters/StvRoundPresenter.php:292-311`
- Idempotency gate to avoid: `app/Jobs/Elections/TabulateRaceJob.php:74-88`
- Cadence precedent: `app/Jobs/ApprovalStandingsRollupJob.php`
- Secrecy gate: `tests/Constitutional/BallotSecrecyTest.php`
