<?php

namespace App\Console\Commands;

use App\Jobs\AutoscaleWorkerJob;
use App\Models\AutoscaleRun;
use App\Services\AuditService;
use App\Services\Autoscale\AutoscaleRunControl;
use App\Services\Autoscale\SweepScopeProcessor;
use App\Support\AutoscaleClaims;
use App\Support\HostCapacity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The autoscale pump (re-engineering 2026-07-19) — the run's ONLY liveness
 * root. Runs every minute from the scheduler container (`schedule:work` —
 * the one process tree that survived every crash across four runs). Each
 * duty is idempotent and seconds-long; a rare double-pump (overlap-lock
 * expiry) is harmless by construction, not by lock.
 *
 * The self-rescheduling orchestrator tick chain is GONE: no successor
 * payloads to lose, no chain to die. If everything else crashes — horizon,
 * redis payloads, every worker — the next pump minute reclaims stale work
 * and re-seeds workers. Recovery is bounded at minutes, always, with zero
 * operator action.
 */
class AutoscalePumpCommand extends Command
{
    protected $signature = 'autoscale:pump';

    protected $description = 'Advance the active autoscale run: phase transitions, kill sweep, dead-lane reclaims, worker seeding, counters';

    /**
     * Stale thresholds (seconds) for the NON-scope claim kinds. Claims are
     * item-sized — minutes, not levels. SCOPES have no timer: a running
     * scope is reclaimed on BACKEND ABSENCE only (reclaimDeadScopes below,
     * operator order 2026-09-02) — a live lane deep in one long PostGIS
     * statement is never reclaimed, however long the statement runs.
     */
    private const SINGLES_STALE = 1800;  // a batch's 5 statements run minutes; no mid-statement heartbeat
    private const PRECOMP_STALE = 1800;  // one parent's pair pass; heavy parents take minutes
    private const ASSESS_STALE  = 1800;  // completeness assessment is minutes at worst

    /**
     * How long a lease WITHOUT a recorded backend pid may go untouched
     * before the pump treats its worker as gone (2026-08-09, the re-run
     * loop). ONE constant for BOTH the legacy lease prune and rule (c) of
     * the scope reclaim — they answer the same question ("is this worker
     * alive?") and must never drift apart. A lease WITH a pid answers by
     * pg_stat_activity presence, never by this timer.
     */
    private const WORKER_LEASE_STALE = 600;

    /**
     * The reclaim that PARKS (operator order 2026-09-02): a scope reclaimed
     * this many times goes to review with no further retry — the pile
     * never re-eats a scope that kills its lanes.
     */
    public const RECLAIM_PARK_AT = 3;

    public function handle(): int
    {
        $this->worldBuildTick();

        $runs = AutoscaleRun::query()
            ->whereIn('status', ['queued', 'sizing', 'mapping', 'halted'])
            ->orderBy('created_at')
            ->get();
        if ($runs->isEmpty()) {
            return self::SUCCESS;
        }

        // Supersede dedupe: the OLDEST unfinished run is the world's single
        // work-list; newer duplicates (ms-window races) yield.
        $run = $runs->first();
        foreach ($runs->slice(1) as $dupe) {
            $dupe->forceFill([
                'status'      => 'failed',
                'last_error'  => "superseded: older unfinished run {$run->id} exists and was resumed instead",
                'finished_at' => now(),
            ])->save();
        }

        // ── Halt / resume state machine (DB column is the source of truth) ──
        if ($run->haltRequested() && $run->status !== 'halted') {
            $running = DB::table('apportionment_ledger_scopes')
                ->where('status', 'running')
                ->distinct()
                ->pluck('legislature_id');
            foreach ($running as $legId) {
                // Best-effort in-flight force: the sweep polls this flag; the
                // workers themselves stop at their next claim boundary.
                Cache::put("legislature.{$legId}.mass_halt", true, 14400);
            }
            $run->forceFill(['status' => 'halted', 'updated_at' => now()])->save();
            Log::info('Autoscale halted by operator', [
                'run_id' => $run->id, 'sweeps_signalled' => count($running),
            ]);

            return self::SUCCESS;
        }
        if ($run->status === 'halted') {
            if ($run->haltRequested()) {
                // THE HALT SEIZES (operator order 2026-09-02, the three
                // Tumaco lanes that outlived the halt by an hour): while
                // the run is parked, every pump minute reaps the lanes.
                // An operator's kill request is honored while parked too.
                app(AutoscaleRunControl::class)->sweepKills($run);
                self::reapHaltedLanes($run);

                return self::SUCCESS; // parked until the operator resumes
            }
            // Operator resumed (flag cleared): rewind to the interrupted
            // phase; every phase step is idempotent, so re-entry is safe.
            // Sizing retired into the world build (2026-08-31): a resumed
            // run maps; a run born queued (ms creation window) maps on its
            // first pump minute via the queued branch below.
            $run->forceFill([
                'status' => 'mapping',
                'mapping_started_at' => $run->mapping_started_at ?? now(),
                'updated_at' => now(),
            ])->save();
        }

        // ── pg-crash breaker: pause claims while Postgres recovers ─────────
        $this->breakerTick($run);

        // Sizing retired (operator plan 2026-08-31): phase 2 wrote every
        // fact, acceptance verified it — a queued run flips straight to
        // mapping on its first pump minute.
        if (in_array($run->status, ['queued', 'sizing'], true)) {
            $run->forceFill([
                'status' => 'mapping',
                'mapping_started_at' => $run->mapping_started_at ?? now(),
                'updated_at' => now(),
            ])->save();
        }

        if ($run->status !== 'mapping') {
            return self::SUCCESS;
        }

        // ── Kill controls (operator order 2026-09-02) ──────────────────────
        // Deadlines are warnings; kills are manual (kill_requested_at) or
        // opt-in automatic (auto_kill_minutes). A killed scope PARKS in
        // review. One implementation, AutoscaleRunControl::killLease.
        $killed = app(AutoscaleRunControl::class)->sweepKills($run);
        if ($killed > 0) {
            Log::warning('Autoscale pump killed lanes', ['run_id' => $run->id, 'count' => $killed]);
        }

        // ── Reclaims: dead lanes' claims go back to pending (set-based, bounded) ──
        $reclaimed = 0;
        // A LIVE WORKER IS NEVER RECLAIMED (2026-08-09, the re-run loop;
        // made structural 2026-09-02). Staleness of the WORK is not
        // evidence of death of the WORKER: a heavy scope's search runs as
        // long as it runs, and reclaiming it did not stop the original
        // worker — it started a SECOND one on the same scope (Tumaco: three
        // lanes, retry_count 4). The evidence of death is the lane's
        // postgres backend leaving pg_stat_activity, or its lease row
        // leaving the table. reclaimDeadScopes reads exactly that.
        $scopeReclaim = self::reclaimDeadScopes();
        $reclaimed += $scopeReclaim['reclaimed'];
        $reclaimed += DB::table('apportionment_ledger')
            ->where('kind', 'single')
            ->where('map_status', 'running')
            ->where('updated_at', '<', now()->subSeconds(self::SINGLES_STALE))
            ->update([
                'map_status' => 'pending', 'claim_token' => null,
                'reason' => 'reclaimed: worker died mid-batch', 'updated_at' => now(),
            ]);
        $reclaimed += DB::table('jurisdiction_adjacency_parents')
            ->where('status', 'running')
            ->where('updated_at', '<', now()->subSeconds(self::PRECOMP_STALE))
            ->update(['status' => 'pending', 'claim_token' => null, 'updated_at' => now()]);
        $reclaimed += DB::table('apportionment_ledger')
            ->where('map_status', 'assessing')
            ->where('updated_at', '<', now()->subSeconds(self::ASSESS_STALE))
            ->update([
                'map_status' => 'running', 'claim_token' => null,
                'reason' => 'reclaimed: worker died mid-assessment', 'updated_at' => now(),
            ]);

        if ($reclaimed > 0) {
            Log::warning('Autoscale pump reclaimed dead lanes\' claims', [
                'run_id' => $run->id, 'count' => $reclaimed,
                'scopes_parked' => $scopeReclaim['parked'],
            ]);
        }

        // Precompute-worklist repair: a run whose SIZING predates the pull
        // engine (or any path that reaches mapping unseeded) has an empty
        // jurisdiction_adjacency_parents — the upfront gate would sit open
        // and every sweep would pay live Step-7 geometry. Seed it exactly
        // once per run (precompute_started_at is the latch; revert keeps
        // it). This one duty can take minutes on a planet-scale table —
        // withoutOverlapping covers the slow pump minute.
        if (AutoscaleClaims::precomputeEnabled() && $run->precompute_started_at === null) {
            $pending = app(\App\Services\Autoscale\AdjacencyPrecompute::class)->seedWorklist();
            AutoscaleRun::query()->whereKey($run->id)
                ->update(['precompute_started_at' => now(), 'updated_at' => now()]);
            $run->refresh();
            Log::info('Autoscale pump seeded the adjacency precompute worklist', [
                'run_id' => $run->id, 'pending_parents' => $pending,
            ]);
        }

        // Enumeration-crash repair: a pending sweep item must always have its
        // root scope row (idempotent through the unique key).
        // A pending sweep header with no scope rows gets an UNSTAMPED self
        // scope (walk/budget NULL) — the first claim's repair branch
        // rebuilds the tree from the walk.
        DB::statement("
            INSERT INTO apportionment_ledger_scopes
                (legislature_id, scope_jurisdiction_id, depth, status, created_at, updated_at)
            SELECT h.legislature_id, h.jurisdiction_id, 0, 'pending', now(), now()
              FROM apportionment_ledger h
             WHERE h.kind = 'sweep' AND h.map_status = 'pending'
               AND NOT EXISTS (SELECT 1 FROM apportionment_ledger_scopes s
                                WHERE s.legislature_id = h.legislature_id)
                ON CONFLICT (legislature_id, scope_jurisdiction_id) DO NOTHING
        ");

        // ── Worker seeding: keep the fixed pool topped up ──────────────────
        // THE LOCK IS LIVENESS (2026-08-30, the corpse-claims blackout): a
        // worker inside a scope transaction beats its lease UNCOMMITTED, so
        // outside snapshots still read a stale last_seen_at — and this
        // prune, as a naked DELETE, then WAITED on that worker's row lock.
        // One in-flight beat wedged the pump mid-pass; withoutOverlapping
        // silently skipped every scheduled pump behind it, so reclaim and
        // reseeding both ceased: three-hour corpse claims filled the heavy
        // slots and the pool bled 13 → 10 with nobody healing it. SKIP
        // LOCKED makes the row lock itself the liveness signal: locked =
        // alive mid-scope (skip), unlocked and stale = truly dead (prune).
        // THE REAPER (operator order 2026-08-30, the ten-ghost afternoon).
        // Positive death detection: a lease whose heartbeat is stale AND
        // whose recorded postgres backend is GONE from pg_stat_activity is
        // certainly dead — a live lane mid-query cannot heartbeat, but its
        // backend is present, so it is never touched. Each certain corpse's
        // lease is deleted here; its scopes were already returned by
        // reclaimDeadScopes above (the ONE owner of scope reclaim state,
        // 2026-09-02), and the seeding below dispatches the replacement in
        // this same tick.
        $corpses = DB::select('
            SELECT id FROM autoscale_worker_leases l
             WHERE l.last_seen_at < ?
               AND l.pg_backend_pid IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM pg_stat_activity a WHERE a.pid = l.pg_backend_pid)
        ', [now()->subMinutes(2)]);
        foreach ($corpses as $corpse) {
            DB::table('autoscale_worker_leases')->where('id', $corpse->id)->delete();
            Log::warning('Autoscale reaper: dead worker lease deleted', ['lease' => (string) $corpse->id]);
        }

        // TRANSIENT REVIEWS SELF-REQUEUE (operator order 2026-08-30, the
        // Saudi/Australia class): an item that fell to review on certain
        // infrastructure weather — a dead connection, a reloading Redis —
        // goes back on the pile ONCE, redraw-flagged. The marker in its
        // reason bounds it: a second transient fall stays in review for a
        // human. Engine-shaped reasons (unassigned constituents, seat
        // disagreements) never auto-retry.
        $requeued = DB::update("
            UPDATE apportionment_ledger
               SET map_status = 'pending',
                   redraw_requested_at = now(),
                   priority_at = now(),
                   transient_retries = transient_retries + 1,
                   reason = 'transient auto-retry: ' || LEFT(reason, 900),
                   seats_seated = NULL, drift = NULL,
                   started_at = NULL, finished_at = NULL, claim_token = NULL,
                   updated_at = now()
             WHERE map_status = 'review'
               AND transient_retries < 1
               AND reason ~* 'SQLSTATE|no connection|Connection refused|LOADING Redis|server closed the connection|Connection timed out|went away|recovery mode|not yet accepting connections'
        ");
        // The retried items' scopes go back with them — EVERY tick, not
        // only the tick that requeued (that gating stranded the first
        // three for one cycle, caught live 2026-08-30). Idempotent: it
        // matches only transient-retry items still pending with un-pended
        // scope trees.
        DB::update("
            UPDATE apportionment_ledger_scopes s
               SET status = 'pending', claim_token = NULL, started_at = NULL,
                   finished_at = NULL, updated_at = now()
              FROM apportionment_ledger h
             WHERE h.legislature_id = s.legislature_id
               AND h.map_status = 'pending' AND h.redraw_requested_at IS NOT NULL
               AND h.reason ~* '^transient auto-retry'
               AND s.status NOT IN ('pending', 'running')
        ");
        if ($requeued > 0) {
            Log::warning('Autoscale pump: transient reviews requeued', ['count' => $requeued]);
        }

        // TRANSIENT FAILED SCOPES SELF-REQUEUE (operator catch 2026-09-02,
        // the four county leaves stuck 25 hours): a SCOPE that failed on
        // infrastructure weather (a redis restart's DNS blip, a lost
        // connection) under a header still open never reached the header
        // rule above — nothing retried it, the map never closed, and its
        // block stayed open. Bounded by retry_count like the processor's own
        // three strikes; engine-shaped failures never auto-retry.
        $scopesRequeued = DB::update("
            UPDATE apportionment_ledger_scopes
               SET status = 'pending', claim_token = NULL, started_at = NULL, finished_at = NULL,
                   retry_count = retry_count + 1,
                   reason = 'transient auto-retry: ' || LEFT(reason, 900),
                   updated_at = now()
             WHERE status = 'failed'
               AND retry_count < 3
               AND reason ~* 'LOADING Redis|Connection refused|server closed the connection|no connection|SQLSTATE\\[08|Connection timed out|Connection lost|getaddrinfo|went away|recovery mode|not yet accepting connections'
        ");
        if ($scopesRequeued > 0) {
            // A header already closed over a failed scope (done with the
            // seats it never drew, or review) reopens with the redraw flag
            // so the retried scope redraws through the normal flow and the
            // header finalizes again on the real result.
            DB::update("
                UPDATE apportionment_ledger h
                   SET map_status = 'pending', redraw_requested_at = now(), seats_seated = NULL, drift = NULL,
                       finished_at = NULL, claim_token = NULL, updated_at = now()
                 WHERE h.map_status IN ('done', 'review', 'failed')
                   AND EXISTS (SELECT 1 FROM apportionment_ledger_scopes s
                                WHERE s.legislature_id = h.legislature_id AND s.status = 'pending'
                                  AND s.reason LIKE 'transient auto-retry%')
            ");
            Log::warning('Autoscale pump: transient failed scopes requeued', ['count' => $scopesRequeued]);
        }

        // Legacy timer prune, for pre-migration leases with no recorded
        // backend only — claimless and stale means gone.
        DB::statement('
            DELETE FROM autoscale_worker_leases
             WHERE id IN (SELECT id FROM autoscale_worker_leases
                           WHERE last_seen_at < ?
                             AND pg_backend_pid IS NULL
                             AND claim_started_at IS NULL
                             FOR UPDATE SKIP LOCKED)
        ', [now()->subSeconds(self::WORKER_LEASE_STALE)]);

        // Seed only against CLAIMABLE work (operator catch 2026-08-30, the
        // idle-lane churn at the tail): an in-flight item with zero pending
        // scopes is bookkeeping, not a seat for a worker — seeding against
        // it spawns lanes that look, find nothing, and leave.
        if (! $run->isPaused() && AutoscaleClaims::claimableWork($run)) {
            // Two pools since the top-down ruling (2026-07-22): 20% of the
            // threads work the queue from the TOP (most complex, highest
            // pop — the fast composite/mixed maps + early bug discovery),
            // the rest bottom-up as ever. Legacy NULL-lane leases count as
            // 'auto'. Both pools obey the one global heavy cap in claims.
            $target = HostCapacity::autoscaleWorkers();
            if (config('cga.autoscale_two_ended')) {
                // THE MEET-IN-THE-MIDDLE SPLIT (operator order 2026-08-31):
                // the larger half claims top-down (odd counts favor it, 13
                // lanes = 7 + 6), the other half bottom-up by
                // reverse_position — trivial mass first, meeting in the
                // middle. Lanes still flow to the survivor through the
                // claim fallthroughs.
                $targetBottom = intdiv($target, 2);
                $targetMain   = $target - $targetBottom;
                $fresh = DB::table('autoscale_worker_leases')
                    ->where('run_id', $run->id)
                    ->where('last_seen_at', '>', now()->subMinutes(2))
                    ->selectRaw("COUNT(*) FILTER (WHERE lane = 'bottomup') AS bottom,
                                 COUNT(*) FILTER (WHERE lane IS NULL OR lane != 'bottomup') AS main")
                    ->first();
                for ($i = 0; $i < ($targetMain - (int) ($fresh->main ?? 0)); $i++) {
                    AutoscaleWorkerJob::dispatch((string) $run->id, 'auto');
                }
                for ($i = 0; $i < ($targetBottom - (int) ($fresh->bottom ?? 0)); $i++) {
                    AutoscaleWorkerJob::dispatch((string) $run->id, 'bottomup');
                }
            } else {
                // ONE POOL, ONE DIRECTION (operator ruling 2026-08-31): the
                // block order decides everything; the old top-down/auto lane
                // split is retired.
                $fresh = (int) DB::table('autoscale_worker_leases')
                    ->where('run_id', $run->id)
                    ->where('last_seen_at', '>', now()->subMinutes(2))
                    ->count();
                for ($i = 0; $i < ($target - $fresh); $i++) {
                    AutoscaleWorkerJob::dispatch((string) $run->id, 'auto');
                }
            }
        }

        // ── Counters + completion ──────────────────────────────────────────
        $counts = $this->refreshCounters($run);

        if ((int) $counts->open_headers === 0 && (int) $counts->open_scopes === 0) {
            $run->forceFill(['status' => 'done', 'finished_at' => now()])->save();

            // THE EAGER CHAIN (operator, 2026-08-08 — the three activation
            // modes): under 'eager' the full-scale build's completion chains
            // the institution shell set (idempotent, all steps) and — dev
            // sandbox + simulate_at_scale only — the simulated world after
            // it. This flip is the run's single done-transition, so the
            // chain dispatches exactly once per run.
            // CONSISTENCY WITH PER-JURISDICTION ACTIVATION (operator question,
            // 2026-08-08: "I just want to make sure the behavior is both
            // correct and consistent"). Provisioning runs on EVERY completed
            // run, in every mode — a planet whose places hold seats and maps
            // but no election board is the R-08 dead end at planet scale,
            // exactly what "+ children" was fixed to avoid. The MODE decides
            // whether the build auto-STARTS at acceptance; it never decides
            // what a started build produces. Idempotent, set-based, chunked.
            $instance = \App\Models\InstanceSettings::query()->whereNull('deleted_at')->first();
            if ($instance !== null
                && $instance->institution_scale_mode === 'eager'
                && (bool) $instance->simulate_at_scale
                && $instance->game_mode === 'sandbox') {
                \Illuminate\Support\Facades\Bus::chain([
                    new \App\Jobs\ProvisionInstitutionsJob(),
                    new \App\Jobs\StartSimulationJob(),
                ])->dispatch();
                $this->info('autoscale done → provisioning chain + simulation queued (eager + simulate).');
            } else {
                \App\Jobs\ProvisionInstitutionsJob::dispatch();
                $this->info('autoscale done → institution provisioning queued (all modes).');
            }

            app(AuditService::class)->append(
                module: 'elections',
                event: 'autoscale.completed',
                payload: [
                    'run_id'       => (string) $run->id,
                    'singles_done' => (int) $counts->singles_done,
                    'sweeps_done'  => (int) $counts->sweeps_done,
                    'review_count' => (int) $counts->review_count,
                    'generator'    => 'AutoscalePumpCommand (pull engine, 2026-07-19)',
                ],
                ref: 'WF-ELE-02',
            );

            Log::info('Autoscale run complete', [
                'run_id'  => $run->id,
                'sweeps'  => (int) $counts->sweeps_done,
                'singles' => (int) $counts->singles_done,
                'review'  => (int) $counts->review_count,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Detect a Postgres crash/recovery and pause claims for 10 minutes so a
     * recovering PG isn't stampeded by the full worker pool. Fingerprint =
     * postmaster start time + stats_reset (a backend-OOM crash recovery
     * moves stats_reset WITHOUT a postmaster restart). Pause-only — no
     * width, no AIMD: a circuit breaker, not a governor.
     */
    /**
     * PHASE-2 LIVENESS (operator plan 2026-08-31): a building world with a
     * stale lease gets its job re-dispatched — the sizing-dispatch throttle
     * pattern, no new schedule entry. Never fires beside a truly active run
     * (the job's own guard also refuses).
     */
    private function worldBuildTick(): void
    {
        if (DB::table('autoscale_runs')->whereIn('status', ['queued', 'sizing', 'mapping'])->exists()) {
            return;
        }
        $stale = DB::table('world_builds')
            ->where('status', 'building')
            ->where(function ($q) {
                $q->whereNull('lease_at')->orWhere('lease_at', '<', now()->subMinutes(10));
            })
            ->orderByDesc('created_at')
            ->first(['id']);
        if ($stale !== null) {
            DB::table('world_builds')->where('id', $stale->id)->update(['lease_at' => now(), 'updated_at' => now()]);
            \App\Jobs\WorldBuildJob::dispatch();
        }
    }

    private function breakerTick(AutoscaleRun $run): void
    {
        try {
            $fp = (string) (DB::selectOne("
                SELECT pg_postmaster_start_time()::text || '|' ||
                       COALESCE((SELECT stats_reset::text FROM pg_stat_database
                                  WHERE datname = current_database()), '') AS fp
            ")->fp ?? '');
        } catch (\Throwable) {
            return; // PG unreachable — workers are failing anyway; next pump retries.
        }
        if ($fp === '') {
            return;
        }

        if ($run->pg_fingerprint === null) {
            AutoscaleRun::query()->whereKey($run->id)->update(['pg_fingerprint' => $fp]);
            $run->pg_fingerprint = $fp;

            return;
        }

        if ($run->pg_fingerprint !== $fp) {
            // PROBE, NEVER A TIMER (operator, 2026-08-30): the fingerprint
            // query above only succeeds when postgres is already answering,
            // so recovery is over the moment a crash is detectable. Claims
            // resume after two consecutive healthy probes 15 s apart (the
            // pair filters a flapping mid-recovery restart); the old flat
            // 10-minute pause was pure dead time.
            $healthy = 0;
            for ($i = 0; $i < 40 && $healthy < 2; $i++) {
                try {
                    $inRecovery = (bool) (DB::selectOne('SELECT pg_is_in_recovery() AS r')->r ?? true);
                    $healthy = $inRecovery ? 0 : $healthy + 1;
                } catch (\Throwable) {
                    $healthy = 0;
                }
                if ($healthy < 2) {
                    sleep(15);
                }
            }
            AutoscaleRun::query()->whereKey($run->id)->update([
                'pg_fingerprint' => $fp,
                'paused_until'   => $healthy >= 2 ? null : now()->addSeconds(30),
                'last_error'     => $healthy >= 2
                    ? null
                    : 'pg crash/recovery detected '.now()->toIso8601String().' — probe still failing, retrying',
                'updated_at'     => now(),
            ]);
            $run->refresh();
            Log::warning('Autoscale breaker: pg restart detected', [
                'run_id'  => $run->id,
                'resumed' => $healthy >= 2,
            ]);
        }
    }

    /**
     * RECLAIM ON BACKEND ABSENCE, NEVER ON A TIMER (operator order
     * 2026-09-02, the three Tumaco lanes on one scope). A running scope
     * returns to the pile only when its lane is certainly gone:
     *  (a) it carries no token, or its lease row is missing;
     *  (b) its lease records a backend pid and that pid is absent from
     *      pg_stat_activity;
     *  (c) its lease records no pid (pre-pid lease) and the heartbeat is
     *      older than WORKER_LEASE_STALE.
     * A live lane deep in one long statement is never touched: its backend
     * is present. The rows are selected FOR UPDATE OF the scope SKIP LOCKED,
     * so the pump never waits behind a lane's row lock. Every reclaim bumps
     * retry_count; when it reaches RECLAIM_PARK_AT the scope PARKS in review
     * (no retry) and its header is handed to the finalize rung, the
     * processor's one review path (SweepScopeProcessor::handHeaderToFinalize).
     *
     * @return array{reclaimed:int, parked:int}
     */
    public static function reclaimDeadScopes(): array
    {
        $rows = DB::select("
            WITH dead AS (
                SELECT s.id,
                       CASE
                         WHEN s.claim_token IS NULL      THEN 'reclaimed: running scope held no lease'
                         WHEN wl.id IS NULL              THEN 'reclaimed: lease gone mid-scope'
                         WHEN wl.pg_backend_pid IS NOT NULL THEN 'reclaimed: worker backend gone mid-scope'
                         ELSE 'reclaimed: worker heartbeat stale mid-scope'
                       END AS cause,
                       -- THE SCOPE IN HAND (review catch 2026-09-02): a batch
                       -- lane holds up to 100 scopes under one token but works
                       -- one at a time. Only that one carries the evidence of
                       -- a bad scope; the untouched remainder returns to the
                       -- pile with no bump and no reason.
                       NOT (wl.claim_type = 'scope_batch'
                            AND wl.current_scope_id IS NOT NULL
                            AND s.id <> wl.current_scope_id) AS in_hand
                  FROM apportionment_ledger_scopes s
                  LEFT JOIN autoscale_worker_leases wl ON wl.id = s.claim_token
                 WHERE s.status = 'running'
                   AND (
                        s.claim_token IS NULL
                     OR wl.id IS NULL
                     OR (wl.pg_backend_pid IS NOT NULL
                         AND NOT EXISTS (SELECT 1 FROM pg_stat_activity a WHERE a.pid = wl.pg_backend_pid))
                     OR (wl.pg_backend_pid IS NULL
                         AND wl.last_seen_at < now() - make_interval(secs => ?::double precision))
                   )
                 FOR UPDATE OF s SKIP LOCKED
            )
            UPDATE apportionment_ledger_scopes s
               SET status      = CASE WHEN d.in_hand AND s.retry_count + 1 >= ?::int THEN 'review' ELSE 'pending' END,
                   claim_token = NULL,
                   started_at  = NULL,
                   finished_at = CASE WHEN d.in_hand AND s.retry_count + 1 >= ?::int THEN now() ELSE NULL END,
                   retry_count = CASE WHEN d.in_hand THEN s.retry_count + 1 ELSE s.retry_count END,
                   reason      = CASE WHEN NOT d.in_hand THEN s.reason
                                      WHEN s.retry_count + 1 >= ?::int
                                      THEN LEFT('reclaimed ' || (s.retry_count + 1)::text || ' times: ' || d.cause
                                                || COALESCE(' | prior: ' || LEFT(s.reason, 300), ''), 1000)
                                      ELSE d.cause END,
                   updated_at  = now()
              FROM dead d
             WHERE s.id = d.id
         RETURNING s.id, s.legislature_id, s.status, s.reason, d.in_hand
        ", [self::WORKER_LEASE_STALE, self::RECLAIM_PARK_AT, self::RECLAIM_PARK_AT, self::RECLAIM_PARK_AT]);

        $parked = 0;
        $processor = null;
        foreach ($rows as $row) {
            if ($row->status !== 'review') {
                continue;
            }
            $parked++;
            $processor ??= app(SweepScopeProcessor::class);
            $handed = $processor->handHeaderToFinalize((string) $row->legislature_id);
            Log::warning('Autoscale pump parked a scope in review after repeated reclaims', [
                'scope_id'       => (string) $row->id,
                'legislature_id' => (string) $row->legislature_id,
                'reason'         => (string) $row->reason,
                'header_running' => $handed,
            ]);
        }

        $inHand = count(array_filter($rows, static fn ($r) => (bool) $r->in_hand));

        return ['reclaimed' => $inHand, 'released' => count($rows) - $inHand, 'parked' => $parked];
    }

    private function refreshCounters(AutoscaleRun $run): object
    {
        $counts = DB::table('apportionment_ledger')
            ->selectRaw("
                COUNT(*) FILTER (WHERE kind = 'single' AND map_status = 'done') AS singles_done,
                COUNT(*) FILTER (WHERE kind = 'sweep'  AND map_status = 'done') AS sweeps_done,
                COUNT(*) FILTER (WHERE map_status = 'review')                   AS review_count,
                COUNT(*) FILTER (WHERE map_status IN ('pending','running','assessing')) AS open_headers
            ")
            ->first();
        $counts->open_scopes = (int) DB::table('apportionment_ledger_scopes')
            ->whereIn('status', ['pending', 'running'])
            ->count();

        $run->forceFill([
            'singles_done' => (int) $counts->singles_done,
            'sweeps_done'  => (int) $counts->sweeps_done,
            'review_count' => (int) $counts->review_count,
            'updated_at'   => now(),
        ])->save();

        return $counts;
    }

    /**
     * A parked run owns no lanes (THE ESCAPE-HATCH LAW). Three steps, each
     * bounded to this run's lease rows:
     *  1. A lane whose database session is still connected two minutes after
     *     the halt was requested is terminated at the database; its job
     *     fails at the next statement and exits.
     *  2. A lease with no connected session (or, when the session pid is
     *     unknown, no heartbeat for two minutes) is deleted — the dashboard
     *     shows lanes from this table, so a dead lease is a phantom worker.
     *  3. A running claim held by no lease returns to pending; a header with
     *     no running scope returns to pending. The resume claims them fresh.
     */
    public static function reapHaltedLanes(AutoscaleRun $run): void
    {
        if ($run->halt_requested_at !== null && $run->halt_requested_at->lt(now()->subMinutes(2))) {
            DB::select("
                SELECT pg_terminate_backend(a.pid)
                  FROM autoscale_worker_leases l
                  JOIN pg_stat_activity a ON a.pid = l.pg_backend_pid
                 WHERE l.run_id = ? AND a.usename = current_user
            ", [$run->id]);
        }

        $leases = DB::delete("
            DELETE FROM autoscale_worker_leases l
             WHERE l.run_id = ?
               AND CASE WHEN l.pg_backend_pid IS NOT NULL
                        THEN NOT EXISTS (SELECT 1 FROM pg_stat_activity a WHERE a.pid = l.pg_backend_pid)
                        ELSE l.last_seen_at < now() - interval '2 minutes' END
        ", [$run->id]);

        $scopes = DB::update("
            UPDATE apportionment_ledger_scopes s
               SET status = 'pending', claim_token = NULL, started_at = NULL, updated_at = now()
             WHERE s.status = 'running'
               AND NOT EXISTS (SELECT 1 FROM autoscale_worker_leases l WHERE l.id = s.claim_token)
        ");
        // THE FINALIZE INPUT STAYS RUNNING (review catch 2026-09-02): a
        // header whose scopes are all closed is exactly what claimFinalize
        // takes (map_status = 'running'); flipping it to pending stranded
        // it for good, because no rung claims a pending header with no
        // pending scope. Only a header that still owns a pending scope
        // returns to pending; a finalize claim in flight (assessing)
        // returns to running for re-claim on resume.
        $headers = DB::update("
            UPDATE apportionment_ledger h
               SET map_status = 'running', claim_token = NULL, updated_at = now()
             WHERE h.map_status = 'assessing'
        ");
        $headers += DB::update("
            UPDATE apportionment_ledger h
               SET map_status = 'pending', claim_token = NULL, updated_at = now()
             WHERE h.map_status = 'running'
               AND NOT EXISTS (SELECT 1 FROM apportionment_ledger_scopes s
                                WHERE s.legislature_id = h.legislature_id AND s.status = 'running')
               AND EXISTS (SELECT 1 FROM apportionment_ledger_scopes s
                            WHERE s.legislature_id = h.legislature_id AND s.status = 'pending')
        ");

        if ($leases > 0 || $scopes > 0 || $headers > 0) {
            Log::info('Autoscale halt reaper', [
                'run_id' => $run->id, 'leases' => $leases, 'scopes' => $scopes, 'headers' => $headers,
            ]);
        }
    }
}
