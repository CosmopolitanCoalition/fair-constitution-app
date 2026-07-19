<?php

namespace App\Console\Commands;

use App\Jobs\AutoscaleSizingJob;
use App\Jobs\AutoscaleWorkerJob;
use App\Models\AutoscaleRun;
use App\Services\AuditService;
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

    protected $description = 'Advance the active autoscale run: phase transitions, stale-claim reclaims, worker seeding, counters';

    /**
     * Stale thresholds (seconds). Claims are item/scope-sized — minutes, not
     * levels. SCOPE_STALE = 30 min, the proven cadence bound from the old
     * engine: publishMassProgress heartbeats between engine phases and
     * districts, but a single monster PostGIS call (ST_Union on a giant
     * district) can legitimately go ~quiet for many minutes — a tighter
     * bound would false-reclaim a LIVE worker and double-run its scope.
     */
    private const SCOPE_STALE   = 1800;
    private const SINGLES_STALE = 1800;  // a batch's 5 statements run minutes; no mid-statement heartbeat
    private const PRECOMP_STALE = 1800;  // one parent's pair pass; heavy parents take minutes
    private const ASSESS_STALE  = 1800;  // completeness assessment is minutes at worst

    public function handle(): int
    {
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
            $running = DB::table('autoscale_scopes')
                ->where('run_id', $run->id)
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
                return self::SUCCESS; // parked until the operator resumes
            }
            // Operator resumed (flag cleared): rewind to the interrupted
            // phase; every phase step is idempotent, so re-entry is safe.
            $run->forceFill([
                'status' => $run->mapping_started_at !== null
                    ? 'mapping'
                    : ($run->sizing_started_at !== null ? 'sizing' : 'queued'),
                'updated_at' => now(),
            ])->save();
        }

        // ── pg-crash breaker: pause claims while Postgres recovers ─────────
        $this->breakerTick($run);

        // ── Sizing: dispatch (10-min throttle; the job's per-run advisory
        //    lock is the true single-writer guard, so duplicates no-op) ─────
        if (in_array($run->status, ['queued', 'sizing'], true)) {
            $leaseStale = $run->sizing_lease_at === null
                || $run->sizing_lease_at->lt(now()->subMinutes(10));
            if ($leaseStale) {
                AutoscaleRun::query()->whereKey($run->id)
                    ->update(['sizing_lease_at' => now(), 'updated_at' => now()]);
                AutoscaleSizingJob::dispatch((string) $run->id);
            }
            $this->refreshCounters($run);

            return self::SUCCESS;
        }

        if ($run->status !== 'mapping') {
            return self::SUCCESS;
        }

        // ── Reclaims: stale claims go back to pending (set-based, bounded) ──
        $reclaimed = 0;
        $reclaimed += DB::table('autoscale_scopes')
            ->where('run_id', $run->id)
            ->where('status', 'running')
            ->where('updated_at', '<', now()->subSeconds(self::SCOPE_STALE))
            ->update([
                'status' => 'pending', 'claim_token' => null,
                'reason' => 'reclaimed: worker died mid-scope', 'updated_at' => now(),
            ]);
        $reclaimed += DB::table('autoscale_items')
            ->where('run_id', $run->id)
            ->where('kind', 'single')
            ->where('status', 'running')
            ->where('updated_at', '<', now()->subSeconds(self::SINGLES_STALE))
            ->update([
                'status' => 'pending', 'claim_token' => null,
                'reason' => 'reclaimed: worker died mid-batch', 'updated_at' => now(),
            ]);
        $reclaimed += DB::table('jurisdiction_adjacency_parents')
            ->where('status', 'running')
            ->where('updated_at', '<', now()->subSeconds(self::PRECOMP_STALE))
            ->update(['status' => 'pending', 'claim_token' => null, 'updated_at' => now()]);
        $reclaimed += DB::table('autoscale_items')
            ->where('run_id', $run->id)
            ->where('status', 'assessing')
            ->where('updated_at', '<', now()->subSeconds(self::ASSESS_STALE))
            ->update([
                'status' => 'running', 'claim_token' => null,
                'reason' => 'reclaimed: worker died mid-assessment', 'updated_at' => now(),
            ]);
        // Legacy-engine compat: 'queued' items belonged to the retired
        // dispatch model — nothing delivers their payloads anymore.
        $reclaimed += DB::table('autoscale_items')
            ->where('run_id', $run->id)
            ->whereIn('status', ['queued', 'halted'])
            ->update([
                'status' => 'pending', 'claim_token' => null,
                'reason' => 'reclaimed: legacy dispatch state', 'updated_at' => now(),
            ]);
        if ($reclaimed > 0) {
            Log::warning('Autoscale pump reclaimed stale claims', [
                'run_id' => $run->id, 'count' => $reclaimed,
            ]);
        }

        // Enumeration-crash repair: a pending sweep item must always have its
        // root scope row (idempotent through the unique key).
        DB::statement("
            INSERT INTO autoscale_scopes
                (id, run_id, item_id, legislature_id, scope_jurisdiction_id,
                 depth, status, created_at, updated_at)
            SELECT gen_random_uuid(), ?, ai.id, ai.legislature_id, ai.jurisdiction_id,
                   0, 'pending', now(), now()
              FROM autoscale_items ai
             WHERE ai.run_id = ? AND ai.kind = 'sweep' AND ai.status = 'pending'
               AND NOT EXISTS (SELECT 1 FROM autoscale_scopes s WHERE s.item_id = ai.id)
                ON CONFLICT ON CONSTRAINT autoscale_scopes_scope_uq DO NOTHING
        ", [$run->id, $run->id]);

        // ── Worker seeding: keep the fixed pool topped up ──────────────────
        DB::table('autoscale_worker_leases')
            ->where('last_seen_at', '<', now()->subMinutes(10))
            ->delete();

        if (! $run->isPaused()) {
            $target = HostCapacity::autoscaleWorkers();
            $alive  = (int) DB::table('autoscale_worker_leases')
                ->where('run_id', $run->id)
                ->where('last_seen_at', '>', now()->subMinutes(2))
                ->count();
            if ($alive < $target && AutoscaleClaims::workAvailable($run)) {
                for ($i = 0; $i < ($target - $alive); $i++) {
                    AutoscaleWorkerJob::dispatch((string) $run->id);
                }
            }
        }

        // ── Counters + completion ──────────────────────────────────────────
        $counts = $this->refreshCounters($run);

        if ((int) $counts->open_items === 0 && (int) $counts->open_scopes === 0) {
            $run->forceFill(['status' => 'done', 'finished_at' => now()])->save();

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
            AutoscaleRun::query()->whereKey($run->id)->update([
                'pg_fingerprint' => $fp,
                'paused_until'   => now()->addMinutes(10),
                'last_error'     => 'pg crash/recovery detected '.now()->toIso8601String().' — claims paused 10 min',
                'updated_at'     => now(),
            ]);
            $run->refresh();
            Log::warning('Autoscale breaker: pg crash detected, pausing claims', ['run_id' => $run->id]);
        }
    }

    private function refreshCounters(AutoscaleRun $run): object
    {
        $counts = DB::table('autoscale_items')
            ->where('run_id', $run->id)
            ->selectRaw("
                COUNT(*) FILTER (WHERE kind = 'single' AND status = 'done')   AS singles_done,
                COUNT(*) FILTER (WHERE kind = 'sweep'  AND status = 'done')   AS sweeps_done,
                COUNT(*) FILTER (WHERE status = 'review')                     AS review_count,
                COUNT(*) FILTER (WHERE status IN ('pending','queued','running','assessing')) AS open_items
            ")
            ->first();
        $counts->open_scopes = (int) DB::table('autoscale_scopes')
            ->where('run_id', $run->id)
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
}
