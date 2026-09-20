# D016 response: combined repair performance fixes

2026-09-20. Developer release for the EXISTING repair run
`01a0bed7-d6c6-7399-b288-44053ffe00e1`, repair version 1. Incoming measurement:
52,278 scopes/hour on 7aa09548 was insufficient. No remote speedup is claimed
from the local tests below. The operator subsequently authorized this developer
to use `ssh wos-demo` and deploy directly because their Codex remote login failed.
This overrides the earlier developer-only deployment boundary for this recovery;
it does not authorize changing the independent Claude monitoring/shutdown loop.

## Combined changes

- New audit and public-record UUIDs are version 7, allocated before chain ownership.
  Covers synchronous append, individual batch append, repair collection, ordinary
  and bulk publication, and direct founding-publication writers. Bulk chamber
  ballots also use UUIDv7. Existing UUIDs, audit hashes and numeric chain sequence
  order are unchanged; no rewrite or index rebuild of existing history.
- All seven repair action types now collect individual events transactionally and
  acquire the global audit lock only after domain work, postconditions and receipts.
  Election recovery no longer takes that lock before filling/counting its races.
  Final immutable records receive their exact individual audit sequence on INSERT.
  CGC IP dedications and achievements are staged with their seals; training and
  achievement reads see the current transaction's staged entries. Savepoint rollback
  discards staged evidence along with its domain effects. No asynchronous audit.
- A jurisdiction's chair elections share one flush/commit in groups of up to 16,
  reduced by existing worker memory headroom (8 MiB reserve per board). Each board
  keeps its own vote, receipt and audit events. An exception/pause rolls back the
  entire current group; earlier committed groups remain. No cross-scope claims.
- Full-board synthetic ballots use one locked current roster and bounded bulk writes.
  Existing casts remain immutable; every new cast retains its public record.
  The existing RCV closer, quorum, majority denominator and chair adoption run.
  Three-seat fixture SQL fell from 64 to 50 calls using the former per-seat path
  as the comparison, even though that comparison benefits from new staging too.
- Large repair evidence sets are canonicalized in pages before lock ownership and
  flushed in pages of 500, preventing an entire large election's evidence from
  being loaded at once. Small chair groups avoid the extra preparation round trip.
- One guarded migration retires only `public_records_actor_user_id_index` after
  verifying the unfiltered `(actor_user_id, seq DESC)` replacement, default operator
  classes/collations, exact index definitions and absence of protected dependencies.
  Concurrent drop, bounded waits, exclusive migration ownership, retry after an
  interrupted drop, and restoration are covered. Audit actor index is retained.

## Deployment

1. Capture fresh direct repair completion counts at current settings. The demo
   operator already applied PostgreSQL `shared_buffers=4GB` at 14:08 UTC; preserve
   it and distinguish its effect from this code release. Do not repeat that restart.
2. `php artisan sim:halt`; wait for this same run to be halted with zero running
   items and leases. Refresh drained Horizon and scheduler using their established
   bounded stop (120 seconds). Preserve all four existing config edits and `.env`.
3. Pull the release commit supplied in the completed developer response, then run:

   ```sh
   php artisan migrate --force --isolated=1 --path=database/migrations/2026_09_20_180000_retire_duplicate_publication_actor_index.php
   ```

   No frontend build, route changes, PostgreSQL/Redis restart, size rederive,
   inventory repeat, new repair run, or payment replay. If the guarded drop refuses,
   investigate the reported condition; application code remains compatible with
   either index set. Never replace the guard with an ad hoc destructive schema edit.
4. Start refreshed workers/scheduler; `php artisan sim:resume` continues the same
   applied repair run. Election recovery is already enabled; do not repeat Apply
   or reset receipts. Verify the run UUID, version, fresh leases and review count.
5. Compare steady direct completion windows, excluding drain/startup. Measure
   `repair.audit_lock_wait`, `repair.audit_flush`, `repair.audit_commit`, full item
   and action mix. Grouping changes the number of flush invocations; compare total
   timer time per completed scope, not only per-call means. Chair timers inside a
   group now exclude the shared final commit; full item still includes it.
6. Check bounded new chair ballots/publication seals, achievements and IP seals,
   audit chain hashes/adjacent links, sampled election recovery and preserved old
   winners/counts/terms, once-only receipts and payments. Record actual limitations.

## Validation and limits

**70 tests / 4,372 assertions passed.** Tests use nonce
PostgreSQL databases loaded from the real migrated schema, never the local world.
They include real institution/election repair, worker settlement, concurrent audit
writers, another process committing while a repair prepares evidence, grouped
rollback/redelivery, old ballot preservation, all UUID writers, staged training
visibility, exact separate IP/publication/achievement seals, 1,003 publications
across page boundaries, migration recovery and scoped lookup EXPLAIN ANALYZE.

This package does not change vote rules, sample size, money boundaries, fsync,
synchronous_commit, worker concurrency, or the global chain ordering contract.
Final evidence append/commit still serializes. Cross-jurisdiction repair batching,
other proposed index removals, global audit redesign and speculative I/O changes
are not part of this release: their correctness and benefit are not established.
