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

                $chainDisplaced = in_array('mis_anchored_cluster', $missing, true)
                    && in_array('displaced_geometry', $missing, true);
                foreach ($missing as $cat) {
                    if ($cat === 'displaced_geometry' && $chainDisplaced) {
                        continue;   // arrives via the cluster job's chain
                    }
                    \App\Jobs\GeodataScanCategoryJob::dispatch(
                        (string) $run->id,
                        $cat,
                        $cat === 'mis_anchored_cluster' && $chainDisplaced
                            ? 'displaced_geometry' : null,
                    );
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

    /** Item kinds belonging to each review-pass stage. */
    private const REVIEW_STAGES = [
        'ingest' => ['boundary_iso', 'boundary_range', 'raster_iso', 'raster_range'],
        'derive' => ['resolve_global', 'resolve_range', 'attribution_pair',
                     'attribution_decompose', 'attribution_range'],
    ];

    /**
     * THE REVIEW-CLEARING PASS (operator, 2026-08-03).
     *
     * A stage that finishes its mainline work but leaves review/failed items
     * behind gets ONE automatic retry at HALF LANES before the run moves on.
     * Half, because the commonest cause of these reviews is memory
     * co-residency (exit -9) — retrying at full width reproduces the kill,
     * while a thinner field is the fix that already works by hand.
     *
     * Returns true when a pass is ACTIVE (mainline must not advance).
     */
    private function reviewPass(GeodataRun $run): bool
    {
        // An active pass blocks advance until its requeued items settle.
        if ($run->review_pass !== null) {
            $stage = $run->review_pass;
            $open = DB::table('geodata_items')->where('run_id', $run->id)
                ->whereIn('kind', self::REVIEW_STAGES[$stage] ?? [])
                ->whereIn('status', ['pending', 'running'])->exists();
            if ($open) {
                return true;   // retry still running — hold at half lanes
            }
            $run->forceFill(['review_pass' => null, 'updated_at' => now()])->save();
            $run->refresh();
            $this->info("review pass [{$stage}] complete — full lanes restored");
            return false;
        }

        // Should a pass START? Only when a stage's mainline is fully closed,
        // it still holds review/failed items, and it has not had its pass.
        $stamps = $run->phase_timestamps ?? [];
        foreach (self::REVIEW_STAGES as $stage => $kinds) {
            if (isset($stamps['_review_pass'][$stage])) {
                continue;   // one bite only — residual review is accepted
            }
            $counts = DB::table('geodata_items')->where('run_id', $run->id)
                ->whereIn('kind', $kinds)
                ->selectRaw("COUNT(*) FILTER (WHERE status IN ('pending','running')) AS open,
                             COUNT(*) FILTER (WHERE status IN ('review','failed'))   AS bad,
                             COUNT(*) AS total")
                ->first();
            if ($counts === null || (int) $counts->total === 0) {
                continue;   // stage not enumerated yet
            }
            if ((int) $counts->open > 0 || (int) $counts->bad === 0) {
                continue;   // still working, or nothing to clear
            }

            // SPLIT ONLY WHAT HAS PROVEN IT CANNOT RUN WHOLE (operator,
            // 2026-08-04: "make sure that happens again, and minimally split
            // the remainder"). 706 of 716 pairs ran whole and fast, and no
            // size threshold catches the 8 real failures without needlessly
            // splitting 22 healthy pairs -- the kill is the CONTAINER
            // OOM-killer taking whoever is fattest at that instant, which is
            // collateral, not fault. So do not predict; react. A pair carries
            // retry_split only after it has actually died, and only then
            // does it slice.
            $n = DB::table('geodata_items')->where('run_id', $run->id)
                ->whereIn('kind', $kinds)->whereIn('status', ['review', 'failed'])
                ->update([
                    'status' => 'pending', 'claim_token' => null, 'reason' => null,
                    'started_at' => null, 'finished_at' => null,
                    'position' => 0, 'updated_at' => now(),
                    'metrics' => DB::raw(
                        "COALESCE(metrics,'{}'::jsonb) || '{\"retry_split\":true}'::jsonb"),
                ]);
            $stamps['_review_pass'][$stage] = now()->toIso8601String();
            $run->forceFill([
                'review_pass' => $stage, 'phase_timestamps' => $stamps,
                'updated_at' => now(),
            ])->save();
            $run->refresh();
            $this->info("review pass [{$stage}]: requeued {$n} item(s) at half lanes");

            return true;
        }

        return false;
    }

    private function advancePhases(GeodataRun $run): void
    {
        $phases = GeodataRun::PHASES;

        // Hold the pointer while a stage clears its review backlog — the run
        // must not reach finalize/scan over items that still have a retry
        // coming (a scan over half-attributed data flags noise, not truth).
        if ($this->reviewPass($run)) {
            return;
        }

        while ($run->phase !== 'done' && GeodataClaims::phaseDrained($run, $run->phase)) {
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

            // (The scanning-phase acceptance-scan dispatch lives in handle() —
            // it fires on EVERY tick while the item is pending, which covers
            // this transition tick too and revives a lost dispatch.)

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
