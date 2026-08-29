<?php

namespace App\Console\Commands;

use App\Jobs\GeodataAcceptanceScanJob;
use App\Models\GeodataRun;
use App\Services\AuditService;
use App\Support\GeodataClaims;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The geodata pull-engine pump (GEODATA_PULL_ENGINE_PLAN.md §5) — the run's
 * liveness root, mirroring autoscale:pump. Runs every minute from the
 * scheduler while a run is live. Each duty is idempotent and seconds-long.
 *
 * Unlike autoscale it does NOT seed workers: the geodata worker pool is Python
 * processes maintained by supervisor.py (which watches the same run row). The
 * pump owns exactly the DB-side liveness: stale-claim reclaim, the pg-crash
 * breaker, phase ADVANCE (never in workers), counter refresh, acceptance-scan
 * dispatch, and completion.
 */
class GeodataPumpCommand extends Command
{
    protected $signature = 'geodata:pump';

    protected $description = 'Advance the active geodata run: reclaims, phase transitions, counters, acceptance scan, completion';

    /** Stale threshold (seconds): a claim untouched this long returns to pending. */
    private const CLAIM_STALE = 1800;

    /** The Laravel-side acceptance scan has no heartbeat — longer stale bound. */
    private const SCAN_STALE = 7200;

    public function handle(): int
    {
        $runs = GeodataRun::query()
            ->whereIn('status', ['running', 'halted'])
            ->orderBy('created_at')
            ->get();
        if ($runs->isEmpty()) {
            return self::SUCCESS;
        }

        // Supersede dedupe: the OLDEST unfinished run is the single work-list;
        // newer duplicates (ms-window races) yield.
        $run = $runs->first();
        foreach ($runs->slice(1) as $dupe) {
            $dupe->forceFill([
                'status'      => 'failed',
                'last_error'  => "superseded: older unfinished run {$run->id} exists",
                'finished_at' => now(),
            ])->save();
        }

        // ── Halt / resume state machine (DB column is the source of truth) ──
        if ($run->haltRequested() && $run->status !== 'halted') {
            $run->forceFill(['status' => 'halted', 'updated_at' => now()])->save();
            Log::info('Geodata run halted by operator', ['run_id' => $run->id]);

            return self::SUCCESS;
        }
        if ($run->status === 'halted') {
            if ($run->haltRequested()) {
                return self::SUCCESS; // parked until the operator resumes
            }
            // Operator resumed (flag cleared): re-enter running; every duty is
            // idempotent, so re-entry is safe.
            $run->forceFill(['status' => 'running', 'updated_at' => now()])->save();
        }

        // ── pg-crash breaker: pause claims ~10 min while Postgres recovers ──
        $this->breakerTick($run);

        // ── Reclaims: stale running items go back to pending (set-based) ────
        // Python workers heartbeat their claim every ~20 s, so 30 min of
        // silence really means a dead worker. The acceptance_scan item is
        // Laravel-side and has no heartbeat — give it the 2 h bound instead
        // (a planet-scale detector suite can legitimately run long).
        $reclaimed = DB::table('geodata_items')
            ->where('run_id', $run->id)
            ->where('status', 'running')
            ->where(function ($q) {
                $q->where(function ($w) {
                    $w->where('kind', '!=', 'acceptance_scan')
                        ->where('updated_at', '<', now()->subSeconds(self::CLAIM_STALE));
                })->orWhere(function ($w) {
                    $w->where('kind', 'acceptance_scan')
                        ->where('updated_at', '<', now()->subSeconds(self::SCAN_STALE));
                });
            })
            ->update([
                'status' => 'pending', 'claim_token' => null,
                'reason' => 'reclaimed: worker died mid-item', 'updated_at' => now(),
            ]);
        if ($reclaimed > 0) {
            Log::warning('Geodata pump reclaimed stale claims', [
                'run_id' => $run->id, 'count' => $reclaimed,
            ]);
        }

        // Prune stale worker leases (Python worker died without clearing).
        DB::table('geodata_worker_leases')
            ->where('last_seen_at', '<', now()->subMinutes(10))
            ->delete();

        // ── Phase advance (never in workers) ───────────────────────────────
        // Walk forward while the current phase's pool is drained: done, review,
        // and failed all count as settled. Advancing through consecutive empty
        // phases in one tick handles filtered runs (a --countries subset may
        // legitimately have zero items in a phase).
        if (! $run->isPaused()) {
            $this->advancePhases($run);
        }

        // Scanning liveness: the scan job dispatch at the phase transition can
        // be lost (horizon crash, dropped payload). Re-dispatch on EVERY tick
        // while a pending scan item exists — the job's pending→running claim
        // is atomic, so a duplicate dispatch no-ops. This also revives a scan
        // whose item the stale reclaim above returned to pending.
        $run->refresh();
        if ($run->phase === 'scanning' && $run->status === 'running') {
            $pendingScan = DB::table('geodata_items')
                ->where('run_id', $run->id)
                ->where('kind', 'acceptance_scan')
                ->where('status', 'pending')
                ->exists();
            if ($pendingScan) {
                GeodataAcceptanceScanJob::dispatch((string) $run->id);
            }

            // Parallel-scan stall audit (external audit P1): a RUNNING scan
            // item quiet for >5 min with incomplete cats means one or more
            // category jobs died (deploy, restart, OOM). Re-dispatch ONLY
            // the missing categories — never the parent dispatcher, and
            // never overwrite landed results (jsonb_set composes; a re-run
            // detector just refreshes its own flags).
            $stale = DB::table('geodata_items')
                ->where('run_id', $run->id)
                ->where('kind', 'acceptance_scan')
                ->where('status', 'running')
                ->where('updated_at', '<', now()->subMinutes(5))
                ->first(['metrics']);
            if ($stale !== null) {
                $m = json_decode($stale->metrics ?? '{}', true) ?: [];
                $have = array_keys($m['cats'] ?? []);
                $missing = array_values(array_diff(\App\Models\GeodataFlag::CATEGORIES, $have));

                // LIVENESS GATE (2026-08-03, the 4-hour scan thrash): "not
                // yet in cats" is NOT "dead" — a heavy detector is silent
                // for 10+ minutes while its planet-wide MATERIALIZED CTE
                // grinds, so blind re-dispatch stacked duplicates whose
                // concurrent CTEs OOM-killed postgres backends in a loop.
                // A category re-dispatches only when its cat_started marker
                // (stamped by the job itself, and by the chain for the
                // chained category) is absent or older than 30 minutes —
                // the engine's stale-claim constant, not a second invented
                // number.
                $started = $m['cat_started'] ?? [];
                $missing = array_values(array_filter($missing, function ($cat) use ($started) {
                    $t = $started[$cat] ?? null;
                    return $t === null || (microtime(true) - (float) $t) > 1800;
                }));

                // HEAVIES CHAIN, NEVER PARALLEL (2026-08-05, the overnight
                // crash loop): re-dispatching the geometry detectors
                // concurrently OOM-crashed postgres into recovery mode every
                // round — and the recovery window ate each round's result
                // writes, so this audit re-dispatched the identical crash
                // every 31 minutes all night. Missing heavy categories now
                // re-dispatch as ONE ordered chain (one planet-CTE at a
                // time, the giant-gate law); the seconds-cheap pair stays
                // parallel.
                $heavyOrder = ['mis_anchored_cluster', 'displaced_geometry',
                               'same_space_chain', 'raster_coverage'];
                $heavies = array_values(array_intersect($heavyOrder, $missing));
                if ($heavies !== []) {
                    // Stamp every chain member (the duplicate-chain hole,
                    // 2026-08-29): tails queued behind the head are STARTED
                    // state — an unstamped tail re-dispatches as a second
                    // concurrent planet pass five minutes later.
                    foreach ($heavies as $cat) {
                        \App\Jobs\GeodataScanCategoryJob::stampStarted((string) $run->id, $cat);
                    }
                    \App\Jobs\GeodataScanCategoryJob::dispatch(
                        (string) $run->id,
                        array_shift($heavies),
                        $heavies === [] ? null : $heavies,
                    );
                }
                foreach (array_intersect($missing, ['dual_coverage', 'orphaned_rows', 'stray_synthetic']) as $cat) {
                    \App\Jobs\GeodataScanCategoryJob::dispatch((string) $run->id, $cat);
                }
                if ($missing !== []) {
                    DB::table('geodata_items')
                        ->where('run_id', $run->id)
                        ->where('kind', 'acceptance_scan')
                        ->where('status', 'running')
                        ->update(['updated_at' => now()]);
                    $this->info('scan audit: re-dispatched ' . implode(', ', $missing));
                }
            }
        }

        $this->refreshCounters($run);

        return self::SUCCESS;
    }

    /** Item kinds belonging to each review-pass stage (kept for
     *  GeodataRequeueCommand compat). The pump's own gate is per-PHASE below. */
    private const REVIEW_STAGES = [
        'ingest' => ['boundary_iso', 'boundary_range', 'raster_iso', 'raster_range'],
        'derive' => ['resolve_global', 'resolve_range', 'attribution_pair',
                     'attribution_decompose', 'attribution_range'],
    ];

    /**
     * The review GROUP that fires at the end of each phase, or null. The review
     * runs at the LAST phase of each group — INGEST ends at `rasters`
     * (boundaries already drained to reach it), DERIVE ends at `attribution` —
     * gating the crossing to the next group. Keyed on the WHOLE group so the
     * review waits for BOTH members.
     */
    private function reviewGroupFor(string $phase): ?string
    {
        return match ($phase) {
            'rasters'     => 'ingest',
            'attribution' => 'derive',
            default       => null,
        };
    }

    /**
     * THE PER-GROUP REVIEW GATE (operator, definitive 2026-08-05):
     *   BOUNDARIES + RASTERS → REVIEW → RESOLVE + ATTRIBUTION → REVIEW → …
     *
     * Returns TRUE when the current group's review is not clear and the run must
     * not cross into the next group. Three earlier errors this fixes, all
     * operator-caught live:
     *   1. it reviewed BOUNDARIES ALONE (per-phase) and advanced to resolve
     *      while rasters were still running — resolve started before rasters;
     *   2. it HALF-LANED while rasters were still running, slowing them for no
     *      reason — the review had no business starting yet;
     *   3. the retried giant therefore ran in a crowd, not isolated.
     *
     * The gate now fires ONLY at the group's last phase and ONLY once the whole
     * group's mainline has drained:
     *   • nothing in review                 → don't hold; the next group starts.
     *   • review residue, group still busy  → HOLD, but do NOT half-lane yet
     *       (review_pass stays null — no lane reduction while rasters run).
     *   • group fully drained, residue      → NOW fire one half-lane auto-retry,
     *       ISOLATED: the next group is gated (phase pointer + stage_ready), so
     *       only the requeued items run — a giant is genuinely alone.
     *   • residue survives the retry        → NEEDS OPERATOR: Retry / Continue.
     *   • operator accepted                 → advance over the residue.
     */
    private function reviewGateHolds(GeodataRun $run): bool
    {
        $group = $this->reviewGroupFor($run->phase);
        if ($group === null) {
            return false;   // not a group-ending phase — no review here
        }
        $kinds  = self::REVIEW_STAGES[$group];
        $stamps = $run->phase_timestamps ?? [];

        // Operator pressed Continue for this group — advance over the residue.
        if (isset($stamps['_accepted'][$group])) {
            if ($run->review_pass !== null) {
                $run->forceFill(['review_pass' => null, 'updated_at' => now()])->save();
                $run->refresh();
            }
            return false;
        }

        $c = DB::table('geodata_items')->where('run_id', $run->id)
            ->whereIn('kind', $kinds)
            ->selectRaw("COUNT(*) FILTER (WHERE status IN ('pending','running')) AS open,
                         COUNT(*) FILTER (WHERE status IN ('review','failed'))   AS bad,
                         COUNT(*) AS total")
            ->first();
        $open = (int) ($c->open ?? 0);
        $bad  = (int) ($c->bad ?? 0);
        $retryFired = isset($stamps['_review_pass'][$group]);

        // ── A retry is IN FLIGHT (this group's residue was already requeued).
        //    HOLD until every requeued item settles. This is the fix for the two
        //    live bugs: the next group can never start mid-review (so resolve
        //    does not begin while Canada is still retrying), AND review_pass is
        //    cleared the instant the retry finishes — FULL LANES are restored
        //    BEFORE resolve/attribution run. The stale bug advanced the phase
        //    while the giant was still 'running' (bad==0), stranding half-lanes
        //    into resolve. ──
        if ($retryFired) {
            if ($open > 0) {
                return true;   // retry still running — hold at the group boundary
            }
            if ($bad === 0) {
                // Retry cleared. Drop half-lanes (if set) and let the run cross.
                if ($run->review_pass !== null) {
                    $run->forceFill(['review_pass' => null, 'updated_at' => now()])->save();
                    $run->refresh();
                    $this->info("review [{$group}]: cleared — full lanes restored");
                }
                if (isset($stamps['_review_serial'][$group])) {
                    // Spent serial-ladder state must not leak into a re-entry
                    // (rewind + rerun would inherit fired==total and skip
                    // straight to the operator hold).
                    unset($stamps['_review_serial'][$group]);
                    $run->forceFill(['phase_timestamps' => $stamps, 'updated_at' => now()])->save();
                    $run->refresh();
                }
                return false;
            }
            // ── SERIAL PASS (operator ruling 2026-08-06, run 019fd562: IND L6
            //    resolve and IND L6 attribution deadlocked each other, and the
            //    joint retry re-ran them TOGETHER — same collision, both dead
            //    again, five hours of "awaiting operator" overnight. His call:
            //    "one at a time, back to back"). Residue that survived the
            //    joint retry gets ONE more automatic round, each item ALONE —
            //    an item that only dies in company clears here without waking
            //    anyone. One item per tick; the next fires when the field is
            //    empty again. Only residue that fails EVEN ALONE reaches the
            //    operator hold. ──
            $serial = $stamps['_review_serial'][$group] ?? null;
            if ($serial === null) {
                $serial = ['total' => $bad, 'fired' => 0];
            }
            if ((int) $serial['fired'] < (int) $serial['total']) {
                // Oldest-touched first: a serially-retried item carries a fresh
                // updated_at and sinks behind the not-yet-tried ones, so every
                // residue item gets exactly one solo attempt before the hold.
                $next = DB::table('geodata_items')->where('run_id', $run->id)
                    ->whereIn('kind', $kinds)->whereIn('status', ['review', 'failed'])
                    ->orderBy('updated_at')->value('id');
                if ($next !== null) {
                    DB::table('geodata_items')->where('id', $next)->update([
                        'status' => 'pending', 'claim_token' => null, 'reason' => null,
                        'started_at' => null, 'finished_at' => null,
                        'position' => 0, 'updated_at' => now(),
                    ]);
                }
                $serial['fired'] = (int) $serial['fired'] + 1;
                $stamps['_review_serial'][$group] = $serial;
                $run->forceFill([
                    'review_pass' => null, 'phase_timestamps' => $stamps, 'updated_at' => now(),
                ])->save();
                $run->refresh();
                $this->info(sprintf('review [%s]: SERIAL retry %d/%d — one item, alone',
                    $group, $serial['fired'], $serial['total']));
                return true;
            }
            // Residue failed even ALONE → hand it to the operator (Retry / Continue).
            if (! isset($stamps['_review_hold'][$group])) {
                $stamps['_review_hold'][$group] = now()->toIso8601String();
                $run->forceFill([
                    'review_pass' => null, 'phase_timestamps' => $stamps, 'updated_at' => now(),
                ])->save();
                $run->refresh();
                $this->warn("review [{$group}]: joint + serial retries left {$bad} unresolved — awaiting operator (Retry / Continue)");
            }
            return true;
        }

        // ── No retry fired yet for this group. ──
        if ($bad === 0) {
            return false;   // clean group → advance (phaseDrained governs)
        }
        if ($open > 0) {
            return true;    // residue but the group is still draining → HOLD, no half-lane
        }

        // Group fully drained + residue → fire ONE retry, isolated (the next
        // group is gated, so only these items run). HALF LANES ONLY when the
        // residue is MORE than half the pool: at half or below, the items don't
        // even fill half the lanes, so halving would just idle workers
        // (operator, 2026-08-05). A lone giant is already alone.
        $n = DB::table('geodata_items')->where('run_id', $run->id)
            ->whereIn('kind', $kinds)->whereIn('status', ['review', 'failed'])
            ->update([
                'status' => 'pending', 'claim_token' => null, 'reason' => null,
                'started_at' => null, 'finished_at' => null,
                'position' => 0, 'updated_at' => now(),
            ]);
        $stamps['_review_pass'][$group] = now()->toIso8601String();
        $pool  = $this->activeWorkerCount();
        $halve = $n > intdiv($pool, 2);
        $run->forceFill([
            'review_pass'      => $halve ? $group : null,
            'phase_timestamps' => $stamps,
            'updated_at'       => now(),
        ])->save();
        $run->refresh();
        $this->info(sprintf('review [%s]: retry %d item(s) — %s',
            $group, $n, $halve ? "half lanes ({$n} > half of {$pool})" : 'FULL lanes (<= half the pool)'));
        return true;
    }

    /** Live worker count, for the half-lane threshold. Falls back to a nominal
     *  pool of 10 if no leases are visible yet (the retry fires just after the
     *  group drains, when the field is still at full width, so this reads the
     *  full pool). */
    private function activeWorkerCount(): int
    {
        $n = (int) DB::table('geodata_worker_leases')
            ->where('last_seen_at', '>', now()->subMinutes(2))
            ->count();

        return $n > 0 ? $n : 10;
    }

    private function advancePhases(GeodataRun $run): void
    {
        $phases = GeodataRun::PHASES;

        while ($run->phase !== 'done') {
            // The GROUP review gate runs BEFORE the drain check, at each group's
            // last phase (rasters, attribution). It holds the crossing into the
            // next group until the whole group is done AND review-clear/accepted
            // — so resolve never starts before boundaries AND rasters are done
            // and reviewed. A non-group-ending phase passes straight through.
            if ($this->reviewGateHolds($run)) {
                return;
            }
            if (! GeodataClaims::phaseDrained($run, $run->phase)) {
                return;   // work still pending/running — hold
            }

            $idx  = array_search($run->phase, $phases, true);
            $next = $phases[$idx + 1] ?? 'done';

            // Stamp the benchmark timestamps: finish the current phase, start
            // the next. phase_timestamps is the §9 report.
            $stamps = $run->phase_timestamps ?? [];
            $stamps[$run->phase]['finished_at'] = now()->toIso8601String();
            if ($next !== 'done') {
                $stamps[$next]['started_at'] = now()->toIso8601String();
            }

            $run->forceFill(['phase' => $next, 'phase_timestamps' => $stamps, 'updated_at' => now()])->save();
            $run->refresh();

            // VACUUM ANALYZE the churned tables at the boundary (never inside a
            // transaction — skip when the pump runs under a test transaction).
            $this->vacuumChurned($run->phase);

            // Reached done: close the run + append the hash-chained audit entry.
            if ($run->phase === 'done') {
                $this->completeRun($run);
                break;
            }
        }
    }

    private function completeRun(GeodataRun $run): void
    {
        $this->refreshCounters($run);
        $run->refresh();

        $run->forceFill(['status' => 'done', 'finished_at' => now(), 'updated_at' => now()])->save();

        app(AuditService::class)->append(
            module: 'system',
            event: 'geodata.completed',
            payload: [
                'run_id'        => (string) $run->id,
                'items_total'   => (int) $run->items_total,
                'items_done'    => (int) $run->items_done,
                'items_review'  => (int) $run->items_review,
                'items_failed'  => (int) $run->items_failed,
                'phase_timings' => $run->phase_timestamps,
                'generator'     => 'GeodataPumpCommand (pull engine, 2026-07-20)',
            ],
            ref: 'WF-SYS-01',
        );

        Log::info('Geodata run complete', [
            'run_id' => $run->id,
            'done'   => (int) $run->items_done,
            'review' => (int) $run->items_review,
            'failed' => (int) $run->items_failed,
        ]);
    }

    /**
     * pg crash/recovery detection → pause claims 10 min (pause-only, never a
     * governor). Fingerprint = postmaster start time || stats_reset. Parity
     * with AutoscalePumpCommand::breakerTick.
     */
    private function breakerTick(GeodataRun $run): void
    {
        try {
            $fp = (string) (DB::selectOne("
                SELECT pg_postmaster_start_time()::text || '|' ||
                       COALESCE((SELECT stats_reset::text FROM pg_stat_database
                                  WHERE datname = current_database()), '') AS fp
            ")->fp ?? '');
        } catch (\Throwable) {
            return;
        }
        if ($fp === '') {
            return;
        }
        if ($run->pg_fingerprint === null) {
            GeodataRun::query()->whereKey($run->id)->update(['pg_fingerprint' => $fp]);
            $run->pg_fingerprint = $fp;

            return;
        }
        if ($run->pg_fingerprint !== $fp) {
            GeodataRun::query()->whereKey($run->id)->update([
                'pg_fingerprint' => $fp,
                'paused_until'   => now()->addMinutes(10),
                'last_error'     => 'pg crash/recovery detected '.now()->toIso8601String().' — claims paused 10 min',
                'updated_at'     => now(),
            ]);
            $run->refresh();
            Log::warning('Geodata breaker: pg crash detected, pausing claims', ['run_id' => $run->id]);
        }
    }

    private function vacuumChurned(string $enteredPhase): void
    {
        if (DB::transactionLevel() > 0) {
            return; // never VACUUM inside a transaction (test runs, etc.)
        }
        // Only the phases that just churned a big table are worth the pass.
        $tables = match ($enteredPhase) {
            'resolving', 'rasters'    => ['jurisdictions'],
            'attribution', 'finalizing' => ['jurisdictions', 'worldpop_rasters'],
            default => [],
        };
        foreach ($tables as $t) {
            try {
                DB::statement("VACUUM (ANALYZE) {$t}");
            } catch (\Throwable) {
                // best-effort; the next autovacuum covers it.
            }
        }
    }

    private function refreshCounters(GeodataRun $run): void
    {
        $c = DB::table('geodata_items')
            ->where('run_id', $run->id)
            ->selectRaw("
                COUNT(*)                                    AS total,
                COUNT(*) FILTER (WHERE status = 'done')     AS done,
                COUNT(*) FILTER (WHERE status = 'review')   AS review,
                COUNT(*) FILTER (WHERE status = 'failed')   AS failed
            ")
            ->first();

        $run->forceFill([
            'items_total'  => (int) $c->total,
            'items_done'   => (int) $c->done,
            'items_review' => (int) $c->review,
            'items_failed' => (int) $c->failed,
            'updated_at'   => now(),
        ])->save();
    }
}
