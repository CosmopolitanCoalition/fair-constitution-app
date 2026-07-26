<?php

namespace App\Console\Commands;

use App\Models\SimItem;
use App\Models\SimRun;
use App\Jobs\SimWorkerJob;
use App\Services\AuditService;
use App\Support\HostCapacity;
use App\Support\SimClaims;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The simulated-world run's ONLY liveness root.
 *
 * Every duty below is idempotent and seconds-long, so a missed tick costs a
 * minute and a double tick costs nothing. This is the lesson of the autoscale
 * re-engineering: the self-rescheduling orchestrator tick-chain was deleted
 * after it worked for ~3 of 26 hours, and a plain every-minute pump plus pull
 * workers replaced it. A rare double pump is harmless BY CONSTRUCTION, not by
 * lock.
 *
 * Duties, in order:
 *   1. supersede duplicate runs (oldest unfinished wins)
 *   2. the halt state machine, and the resume rewind
 *   3. the pg-crash breaker (pause only — never a governor)
 *   4. stale-claim reclaim
 *   5. lease cull
 *   6. PHASE ADVANCE — here, and nowhere else
 *   7. counter refresh + completion
 *
 * Phase advance lives here rather than in a worker for the same reason the
 * autoscale engine put it here: a worker that can advance a phase can advance
 * it twice.
 */
class SimPumpCommand extends Command
{
    protected $signature = 'sim:pump';

    protected $description = 'Advance the active simulated-world run: phase transitions, stale-claim reclaims, counters';

    /** Ordinary items: a worker that has not touched its claim in 30 min is gone. */
    private const STALE_SECONDS = 1800;

    /**
     * Network/LLM items get 4 hours: a research call behind a rate limit can
     * legitimately sit far longer than a CPU item, and reclaiming it early
     * would duplicate a call that costs money.
     */
    private const NETWORK_STALE_SECONDS = 14400;

    private const LEASE_STALE_MINUTES = 10;

    /** THE ETL RULE chunk size for phase-transition minting. */
    private const MINT_CHUNK = 25000;

    public function __construct(private readonly AuditService $audit)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $runs = SimRun::query()
            ->whereIn('status', ['queued', 'running', 'halted'])
            ->orderBy('created_at')
            ->get();

        if ($runs->isEmpty()) {
            return self::SUCCESS;
        }

        $run = $runs->first();

        // 1. Supersede — one live run at a time; the oldest unfinished wins.
        foreach ($runs->slice(1) as $dupe) {
            $dupe->forceFill([
                'status' => 'failed',
                'last_error' => 'superseded: an older unfinished run holds the engine',
                'finished_at' => now(),
            ])->save();
        }

        // 2. Halt / resume.
        if ($run->haltRequested() && $run->status !== 'halted') {
            $run->forceFill(['status' => 'halted'])->save();
            $this->info('run halted');

            return self::SUCCESS;
        }

        if (! $run->haltRequested() && $run->status === 'halted') {
            $run->forceFill(['status' => 'running'])->save();
            $this->info('run resumed');
        }

        if ($run->status === 'queued') {
            $run->forceFill(['status' => 'running', 'started_at' => $run->started_at ?? now()])->save();
        }

        // 3. Breaker.
        $this->breakerTick($run);

        // 4. Stale-claim reclaim — set-based, both lanes.
        $this->reclaim($run);

        // 5. Lease cull.
        DB::table('sim_worker_leases')
            ->where('run_id', $run->id)
            ->where('last_seen_at', '<', now()->subMinutes(self::LEASE_STALE_MINUTES))
            ->delete();

        // 6. Phase advance.
        $this->advancePhase($run);

        // 7. Worker top-up — the pool is the ONE concurrency dial.
        $this->seedWorkers($run->fresh());

        // 8. Counters + completion.
        $this->refreshCounters($run);

        return self::SUCCESS;
    }

    /**
     * Top the fixed worker pool back up. Process count IS concurrency — there
     * is no second width dial (HostCapacity's contract). Only workers seen in
     * the last two minutes count as alive, so a crashed worker is replaced on
     * the next tick without anyone tracking PIDs.
     */
    private function seedWorkers(SimRun $run): void
    {
        if (! $run->isClaimable() || ! SimClaims::workAvailable($run)) {
            return;
        }

        $target = HostCapacity::autoscaleWorkers();

        $live = (int) DB::table('sim_worker_leases')
            ->where('run_id', $run->id)
            ->where('last_seen_at', '>', now()->subMinutes(2))
            ->count();

        for ($i = $live; $i < $target; $i++) {
            SimWorkerJob::dispatch((string) $run->id);
        }
    }

    /**
     * Mint the worklist for a phase we are entering. Bounded, individually
     * committed chunks with a NOT-EXISTS guard, so a pump that dies mid-mint
     * resumes cleanly on the next tick and a double pump is a no-op (THE ETL
     * RULE).
     *
     * Ordering is LARGEST-FIRST via `position`, carried over from the item the
     * work derives from.
     */
    private function mintWorklist(SimRun $run, string $phase): int
    {
        // Only stages whose worklist is derived post-hoc are minted here;
        // `cohort_scope` is enumerated by sim:start from the jurisdiction table.
        $sql = match ($phase) {
            // One identity roster per jurisdiction that actually got a cohort.
            'identities' => "INSERT INTO sim_items
                    (id, run_id, kind, status, jurisdiction_id, adm_level, unit_key,
                     position, est_cost, metrics, created_at, updated_at)
                 SELECT gen_random_uuid(), ?, 'identity_batch', 'pending',
                        c.jurisdiction_id, s.adm_level, c.jurisdiction_id::text,
                        s.position, c.electorate, '{}', now(), now()
                   FROM jurisdiction_cohorts c
                   JOIN sim_items s
                     ON s.run_id = ? AND s.kind = 'cohort_scope'
                    AND s.unit_key = c.jurisdiction_id::text AND s.status = 'done'
                  WHERE c.run_id = ?
                    AND NOT EXISTS (
                        SELECT 1 FROM sim_items x
                         WHERE x.run_id = ? AND x.kind = 'identity_batch'
                           AND x.unit_key = c.jurisdiction_id::text
                    )
                  LIMIT ".self::MINT_CHUNK,
            // One election per jurisdiction that has a chamber AND a roster.
            'elections' => "INSERT INTO sim_items
                    (id, run_id, kind, status, jurisdiction_id, adm_level, unit_key,
                     position, est_cost, metrics, created_at, updated_at)
                 SELECT gen_random_uuid(), ?, 'election_scope', 'pending',
                        l.jurisdiction_id, s.adm_level, l.jurisdiction_id::text,
                        s.position, COALESCE(l.type_a_seats, 0), '{}', now(), now()
                   FROM legislatures l
                   JOIN sim_items s
                     ON s.run_id = ? AND s.kind = 'identity_batch'
                    AND s.unit_key = l.jurisdiction_id::text AND s.status = 'done'
                  WHERE l.deleted_at IS NULL
                    AND NOT EXISTS (
                        SELECT 1 FROM sim_items x
                         WHERE x.run_id = ? AND x.kind = 'election_scope'
                           AND x.unit_key = l.jurisdiction_id::text
                    )
                  LIMIT ".self::MINT_CHUNK,
            // One counting item per ELECTION this run scheduled. The election id
            // rides in race_id — the item's spare reference column — because
            // counting and seating act on an election, not a jurisdiction.
            'counting' => "INSERT INTO sim_items
                    (id, run_id, kind, status, jurisdiction_id, race_id, adm_level, unit_key,
                     position, est_cost, metrics, created_at, updated_at)
                 SELECT gen_random_uuid(), ?, 'count_election', 'pending',
                        e.jurisdiction_id, e.id, s.adm_level, e.id::text,
                        s.position, 0, '{}', now(), now()
                   FROM elections e
                   JOIN sim_items s
                     ON s.run_id = ? AND s.kind = 'election_scope'
                    AND s.unit_key = e.jurisdiction_id::text AND s.status = 'done'
                  WHERE e.status NOT IN ('certified', 'cancelled')
                    AND EXISTS (SELECT 1 FROM election_races r WHERE r.election_id = e.id)
                    AND NOT EXISTS (
                        SELECT 1 FROM sim_items x
                         WHERE x.run_id = ? AND x.kind = 'count_election'
                           AND x.unit_key = e.id::text
                    )
                  LIMIT ".self::MINT_CHUNK,

            // One seating item per election whose races have all been counted.
            'seating' => "INSERT INTO sim_items
                    (id, run_id, kind, status, jurisdiction_id, race_id, adm_level, unit_key,
                     position, est_cost, metrics, created_at, updated_at)
                 SELECT gen_random_uuid(), ?, 'seat_scope', 'pending',
                        e.jurisdiction_id, e.id, s.adm_level, e.id::text,
                        s.position, 0, '{}', now(), now()
                   FROM elections e
                   JOIN sim_items s
                     ON s.run_id = ? AND s.kind = 'count_election'
                    AND s.unit_key = e.id::text AND s.status = 'done'
                  WHERE e.status NOT IN ('certified', 'cancelled')
                    AND NOT EXISTS (
                        SELECT 1 FROM sim_items x
                         WHERE x.run_id = ? AND x.kind = 'seat_scope'
                           AND x.unit_key = e.id::text
                    )
                  LIMIT ".self::MINT_CHUNK,

            default => null,
        };

        if ($sql === null) {
            return 0;
        }

        // Bindings must match the placeholder count of the chosen statement —
        // they differ per phase, and a fixed count silently breaks the phase
        // whose SQL has fewer.
        $bindings = array_fill(0, substr_count($sql, '?'), $run->id);

        $total = 0;

        do {
            $n = DB::affectingStatement($sql, $bindings);
            $total += $n;
        } while ($n > 0);

        return $total;
    }

    /**
     * A backend-OOM crash-recovery moves stats_reset WITHOUT a postmaster
     * restart, so the fingerprint is both values. Pause-only — a circuit
     * breaker, not a governor: no width dial, no AIMD.
     */
    private function breakerTick(SimRun $run): void
    {
        try {
            $fp = DB::selectOne(
                "SELECT pg_postmaster_start_time()::text || '|' ||
                        COALESCE((SELECT stats_reset::text FROM pg_stat_database
                                   WHERE datname = current_database()), '') AS fp"
            );
        } catch (\Throwable) {
            return; // PG unreachable — next tick retries. Never a violation.
        }

        if ($fp === null) {
            return;
        }

        if ($run->pg_fingerprint === null) {
            $run->forceFill(['pg_fingerprint' => $fp->fp])->save();

            return;
        }

        if ($run->pg_fingerprint !== $fp->fp) {
            $run->forceFill([
                'paused_until' => now()->addMinutes(10),
                'pg_fingerprint' => $fp->fp,
                'last_error' => 'pg crash/recovery detected — claims paused 10 min',
            ])->save();

            Log::warning('Sim breaker: pg crash detected, pausing claims');
        }
    }

    /** Reclaim claims whose worker died, with a longer grace for the network lane. */
    private function reclaim(SimRun $run): void
    {
        $networkKinds = SimClaims::NETWORK_KINDS;
        $placeholders = implode(',', array_fill(0, count($networkKinds), '?'));

        // The two thresholds are INLINED, not bound. PDO binds integers as text
        // and `make_interval(secs => text)` does not exist — the same shape the
        // autoscale reclaim uses, and a bound parameter here fails at runtime
        // rather than at parse time, so it would only surface under load.
        $network = (int) self::NETWORK_STALE_SECONDS;
        $ordinary = (int) self::STALE_SECONDS;

        DB::update(
            "UPDATE sim_items
                SET status = ?, claim_token = NULL,
                    reason = 'reclaimed: worker died mid-item', updated_at = now()
              WHERE run_id = ?
                AND status = ?
                AND updated_at < now() - make_interval(secs => CASE
                        WHEN kind IN ({$placeholders}) THEN {$network} ELSE {$ordinary} END)",
            array_merge(
                [SimItem::STATUS_PENDING, $run->id, SimItem::STATUS_RUNNING],
                $networkKinds
            )
        );
    }

    /**
     * A phase's barrier opens when its pool has zero pending+running. Done,
     * review and failed all count as SETTLED — a refused unit's absence is
     * honest, and the acceptance scan flags it. Failures never sink the run.
     */
    private function advancePhase(SimRun $run): void
    {
        $kinds = $run->currentKinds();

        if ($kinds === []) {
            return;
        }

        $open = DB::table('sim_items')
            ->where('run_id', $run->id)
            ->whereIn('kind', $kinds)
            ->whereIn('status', SimItem::OPEN)
            ->exists();

        if ($open) {
            return;
        }

        $next = $run->nextPhase();

        if ($next === null) {
            return;
        }

        $timings = $run->phase_timings ?? [];
        $timings[$run->phase]['finished_at'] = now()->toIso8601String();
        $timings[$next]['started_at'] = now()->toIso8601String();

        $run->forceFill(['phase' => $next, 'phase_timings' => $timings])->save();

        // Mint the incoming phase's worklist HERE, at the transition, because
        // most stages can only be sized once the previous one has landed: an
        // identity roster depends on the cohort that decides how many people a
        // place needs. Enumerating everything up front would either guess or
        // block.
        $minted = $this->mintWorklist($run, $next);

        $this->info("phase → {$next}".($minted > 0 ? " ({$minted} items minted)" : ''));
    }

    /** One aggregate pass; also closes the run when nothing is left open. */
    private function refreshCounters(SimRun $run): void
    {
        $counts = DB::table('sim_items')
            ->where('run_id', $run->id)
            ->selectRaw(
                'COUNT(*) AS total,
                 COUNT(*) FILTER (WHERE status = ?) AS done,
                 COUNT(*) FILTER (WHERE status IN (?, ?)) AS review,
                 COUNT(*) FILTER (WHERE status IN (?, ?)) AS open',
                [
                    SimItem::STATUS_DONE,
                    SimItem::STATUS_REVIEW, SimItem::STATUS_FAILED,
                    SimItem::STATUS_PENDING, SimItem::STATUS_RUNNING,
                ]
            )
            ->first();

        $patch = [
            'items_total' => (int) $counts->total,
            'items_done' => (int) $counts->done,
            'items_review' => (int) $counts->review,
            'open_items' => (int) $counts->open,
        ];

        $finished = (int) $counts->open === 0
            && (int) $counts->total > 0
            && $run->phase === 'done';

        if ($finished && $run->status !== 'done') {
            $patch['status'] = 'done';
            $patch['finished_at'] = now();
        }

        $run->forceFill($patch)->save();

        if ($finished && $run->wasChanged('status')) {
            $this->audit->append(
                module: 'simworld',
                event: 'sim.completed',
                payload: [
                    'run_id' => (string) $run->id,
                    'items_total' => $patch['items_total'],
                    'items_done' => $patch['items_done'],
                    'items_review' => $patch['items_review'],
                ],
                ref: 'WF-SYS-04',
            );
        }
    }
}
