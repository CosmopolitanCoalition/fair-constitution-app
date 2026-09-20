# D015 response — release the chair audit bottleneck and recover elections

2026-09-20. Completed local developer handoff for manual relay. Deploy the commit
containing this file; the final developer message supplies its SHA and test total.
Received evidence is preserved in `step5-benchmarks/D015.md`.

## Deploy to the CURRENT run

D015 confirms the full run **01a0bed7-d6c6-7399-b288-44053ffe00e1 is already
applying repairs**. Do not repeat inventory, Apply, receipt correction, or create
another run. Original run and all Phase 10 payments remain untouched. Repair
version remains 1. Both election-recovery choices were explicitly authorized by
the operator; the command below records that settled choice, not another request.

1. Capture a short current chair-repair baseline if practical. Halt and drain the
   current run; require zero running items and leases. Refresh drained Horizon
   and scheduler using the established bounded stop procedure around this update.
   Preserve all local configuration and host sizing.
2. Pull the supplied commit. Apply the single additive migration while halted:

   ```bash
   php artisan migrate --force --path=database/migrations/2026_09_20_160000_add_never_filled_vacancy_slots.php
   ```

   It adds two nullable vacancy seat-number fields and a unique source-race/slot
   constraint. Existing vacancy rows retain their meaning. The audit optimization
   uses per-connection temporary tables and needs no schema migration of its own.
   No frontend build, PostgreSQL restart, Redis change or world reset is required.
3. On the halted existing repair run, record the operator's election instruction:

   ```bash
   php artisan sim:repair --enable-election-recovery=01a0bed7-d6c6-7399-b288-44053ffe00e1
   ```

   This enables supplemental/unfinished-election recovery for pending work. It
   also visits REVIEW items in bounded pages and requeues only those now eligible
   for election recovery. DONE and other pending items are unchanged. Old blocked
   election receipts remain; the authorized recovery uses a separate receipt kind.
   Repeating the command is safe. It reports `reviews_requeued` honestly, possibly
   zero. It does not run repairs or restart the world. The original inventory is
   historical; each execution inspects current facts, so an active repair run does
   not need another full inventory refresh.
4. Start refreshed services and resume the same already-applied run:

   ```bash
   php artisan sim:resume
   ```

   If deployment finds the run is still in **repair_planning** instead, the enable
   command explicitly reports that branch: refresh its existing inventory, then
   Apply that same run. Do not use that branch for D015's current repairing phase.

## D015 performance fix

The dominant independent chair action now stages each audit event and public
record in a PostgreSQL temporary table inside its existing outer transaction.
Ballots, tallies and chair adoption proceed under their existing local locks.
After domain work, postconditions and the receipt write, the collector prepares
canonical payloads, takes the global audit lock, writes individually hash-linked
events in bulk and inserts public records with their actual matching audit seqs.
The SAME transaction then commits. No public-record UPDATE/backfill bypasses its
immutability trigger; records are inserted once with their final references.

Temporary staging participates in PostgreSQL savepoints and rollback. A crash
before commit loses the entire action, its receipt and evidence together; there
is no after-commit volatile worker flush. Another repair can finish while the
first prepares its ballots. Final chain append/commit remains serialized, as
required. Receipt redelivery preserves once-only effects.

This optimization is deliberately confined to **independent chair actions**, the
765,341-scope dominant D015 category, plus other explicit chair actions. Training,
governance, judicial and election actions retain their existing synchronous audit
behavior. This avoids changing services that consume audit sequences or training
completion events during their own work. It is not a claim that every remaining
bottleneck is removed, or a measured remote speedup.

New timings: `repair.audit_lock_wait`, `repair.audit_flush`,
`repair.audit_commit` (flush through return from the outer action transaction,
including durable COMMIT in runtime), nested beneath `repair.chair`. Do not add
nested timings. Collect direct `repair_scope` DONE and REVIEW counts over steady
windows; exclude the halt/startup and distinguish harder institution/election
work from chair-only throughput. Leave worker counts/durability unchanged.

## Authorized election recovery

- A certified race's never-filled positions are represented by vacancies pointing
  to the source race and missing result-seat ordinal, without inventing a former
  member. Each receives the existing real special-election lifecycle, scoped to
  the original district/Type B footprint. Existing serving legislators are excluded
  from these synthetic supplemental candidate pools. Original winners, certification,
  count records, member rows and term dates remain; replacements inherit the
  current original chamber expiry. A later/expired chamber term is not overwritten.
- An unfinished nomination-stage election can retain sufficient completed counts,
  mark deficient completed counts superseded, complete candidate fields and run
  the real counting/certification path. Old hashes, rounds, results and candidate
  IDs remain. For Earth's reported mix, this means retaining the 355 sufficient
  counts, replacing the 69 deficient counts and counting the 90 unfinished races,
  subject to actual current inspection. Supersession and new effects are atomic.
- Candidate footprints, population ceilings, actual STV counting, vote thresholds,
  and constitutional certification remain enforced. A recovery count must fill
  every advertised seat before certification; partial recovery rolls back.
- Successful recovery must satisfy the same fully seated-chamber prerequisite
  before training and dependent institutions. Phase 10 is never replayed. Newly
  eligible training still uses its existing once-only stipend mechanism.

Fresh Step 5 runs retain the earlier distinct-candidate, exactly-five delegation,
canonical Type B and real board-chair fixes. Recovery flags do not weaken ordinary
candidate-fielding protections or permit mutation on non-synthetic worlds.

## Internal validation and remote checks

Final focused suite: **55 tests / 963 assertions passed**, including the real
concurrent-process audit test. No local or remote game-world writes.

Guarded disposable PostgreSQL fixtures exercise real general/special counting,
certification and terms; mixed deficient/full/uncounted races; original history
retention; repeat delivery; exhausted pools; interruptions after count supersession
and after the first supplemental election; authorization and same-run continuation.
The already-applied run test retains DONE/pending work and the old blocked receipt.

Chair tests validate exact ballot-to-public-record-to-audit references and complete
hash links. A second real PHP/database process commits a different chair repair
while the first has finished ballots but has not flushed its audit. Additional
tests cover savepoint rollback, failures before/after audit flush, no orphan public
records or success receipts, cleanup/redelivery and individual event visibility.
Existing actual PostgreSQL worker, repair dependency and term-lockstep regressions
run alongside them. All writes are in disposable fixtures, not either game world.

On deployment, compare steady direct repair throughput and new timers. Sample
chair ballot record/audit links and chain tails as in D015. As election recoveries
arrive, sample retained originals, filled vacancy special elections/term ends,
and Earth's superseded versus retained counts. Report actual reviews; never force
verification success. The independent Claude storage/shutdown loop is unchanged.
