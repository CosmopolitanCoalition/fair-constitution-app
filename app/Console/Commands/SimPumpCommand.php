<?php

namespace App\Console\Commands;

use App\Models\SimItem;
use App\Models\SimRun;
use App\Jobs\SimWorkerJob;
use App\Services\AuditService;
use App\Support\HostCapacity;
use App\Support\SimClaims;
use App\Support\SimWorldCounters;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The simulated-world run's ONLY liveness root.
 *
 * A scheduled tick and worker kicks share the pump. Phase generation may take
 * longer than the cache lock's TTL, so a database session lock serializes pumps
 * across committed chunks. Durable generation state makes interruption resumable
 * and prevents an empty, partially generated queue being treated as drained.
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

    /**
     * The single lock every pump run serializes behind (the scheduled tick and
     * the on-demand kick). A holder pauses the pump: a tick that cannot get the
     * lock just returns. DemoSessionService::void takes it so a pump cannot
     * advance the world into rows the void is reversing.
     */
    public const EXEC_LOCK = 'sim:pump:exec';

    /**
     * ABSOLUTE-AGE BACKSTOP (tuned 2026-09-07, operator order). The primary reap
     * is the dead-heartbeat check in reclaim() (120 s), which catches a killed
     * worker fast because a live one heartbeats its lease even mid-item. This
     * backstop only fires if that heartbeat ever regresses, so it just has to
     * sit safely above the heaviest real item — measured ~2 s on the Poland run
     * (counting/identities), so 5 minutes is a ~150x margin. The old 30 minutes
     * left a run frozen on screen after a worker died.
     */
    private const STALE_SECONDS = 300;

    /**
     * Network/LLM items get 4 hours: a research call behind a rate limit can
     * legitimately sit far longer than a CPU item, and reclaiming it early
     * would duplicate a call that costs money.
     */
    private const NETWORK_STALE_SECONDS = 14400;

    private const LEASE_STALE_MINUTES = 10;

    /** Session lock survives the Redis lock TTL; released on exit or DB disconnect. */
    public const ADVISORY_LOCK_KEY = 0x53494d50554d50; // SIMPUMP

    /** Completed upstream rosters, traversed with the existing unique index. */
    private const CURSOR_SOURCES = [
        'counting' => 'election_scope',
        'training' => 'seat_scope',
        'governance' => 'seat_scope',
        'judiciary' => 'governance_scope',
        'civics' => 'judiciary_scope',
        'stipends' => 'identity_batch',
        'verifying' => 'cohort_scope',
    ];

    private static function mintChunk(): int
    {
        return HostCapacity::enumerationChunk();
    }

    public function __construct(private readonly AuditService $audit)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Serialize EVERY pump run behind one lock — the scheduled minute tick
        // AND the on-demand kick a worker fires the instant its phase drains
        // (SimWorkerJob::kickPump). Two advancePhase calls racing would double-
        // advance a run, so the kick must share the tick's gate. A run that
        // cannot get the lock just returns: another pump already holds the
        // engine, and the work it would have done is being done.
        $lock = Cache::lock(self::EXEC_LOCK, 120);

        if (! $lock->get()) {
            return self::SUCCESS;
        }

        // Generation can outlive the cache TTL. Hold a session advisory lock
        // across its individually committed chunks, not one giant transaction.
        $pdo = null;
        $held = false;
        try {
            if (DB::getDriverName() === 'pgsql') {
                $pdo = DB::connection()->getPdo();
                $query = $pdo->prepare('SELECT pg_try_advisory_lock(?)');
                $query->execute([self::ADVISORY_LOCK_KEY]);
                $held = (bool) $query->fetchColumn();
                if (! $held) {
                    return self::SUCCESS;
                }
            }
            return $this->runPump();
        } finally {
            try {
                if ($held) {
                    // Use the owning PDO, never reconnect to unlock a new session.
                    $query = $pdo->prepare('SELECT pg_advisory_unlock(?)');
                    $query->execute([self::ADVISORY_LOCK_KEY]);
                }
            } finally {
                $lock->release();
            }
        }
    }

    private function runPump(): int
    {
        $runs = SimRun::query()
            ->whereIn('status', ['queued', 'running', 'halted'])
            ->orderBy('created_at')
            ->get();

        if ($runs->isEmpty()) {
            return self::SUCCESS;
        }

        $run = $runs->first();

        // Recover committed deltas left by a killed worker, also while halted.
        if (SimWorldCounters::available()) { SimWorldCounters::flush((string) $run->id); }

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

        // A queued run is still ENUMERATING. SimStartCommand commits the run row
        // before it finishes minting the cohort worklist (bounded, committed
        // chunks per the ETL rule), so mid-enumeration the cohorts phase can look
        // empty. Promoting and advancing it here let advancePhase RACE the run
        // through the whole pipeline with zero work done (observed on the India
        // start, 2026-09-07: phase reached governance, 2000 cohort items still
        // pending). SimStartCommand now flips the run to 'running' as its LAST
        // step, once the worklist is fully minted; only then does the pump touch
        // it. A start that dies mid-enumeration leaves a queued run for --resume.
        if (! $run->isClaimable()) {
            return self::SUCCESS;
        }

        // 3. The pg-crash breaker is retired (operator order 2026-09-19): LLM-
        // invented app logic that paused claims on any postmaster restart,
        // including a deliberate re-derive or recreate. A dead worker is caught
        // by the reclaim below.

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

        // THE SHARED BUDGET (audit structural row, operator order
        // 2026-08-30): supervisor-autoscale and supervisor-sim each size
        // to the full host, so simultaneous work used to double-book the
        // box at 2× lanes. The budget is enforced HERE, at the seeding
        // plane, where it can be dynamic: sim yields to live autoscale
        // lanes and takes the remainder (floor 2, the lane law's
        // lanes-flow-to-the-survivor in the other direction — when the
        // autoscale run drains, sim inherits the full width on the next
        // pump minute).
        $autoscaleLive = (int) DB::table('autoscale_worker_leases')
            ->where('last_seen_at', '>', now()->subMinutes(2))
            ->count();
        $target = max(2, HostCapacity::autoscaleWorkers() - $autoscaleLive);

        $live = (int) DB::table('sim_worker_leases')
            ->where('run_id', $run->id)
            ->where('last_seen_at', '>', now()->subMinutes(2))
            ->count();

        for ($i = $live; $i < $target; $i++) {
            SimWorkerJob::dispatch((string) $run->id);
        }
    }

    /**
     * The counting-phase mint statement (extracted so a disposable-database
     * test can run the exact production SQL, never a copy).
     *
     * BOUND TO THE TARGET ELECTION OF THE CLAIM (debt row 43). One count item
     * per election an election_scope PRODUCED — the id ElectionStage stamped
     * into election_scope.race_id at settle. The join is on that election, not
     * on the jurisdiction. A jurisdiction can hold more than one open election
     * (a second chamber, a prior run's orphan); a jurisdiction-keyed join
     * minted a count item for each. One election_scope produced exactly one
     * election, so it mints exactly one count item. A scope that produced no
     * election left race_id null and mints nothing.
     *
     * The election id rides in count_election.race_id — the item's spare
     * reference column — because counting and seating act on an election.
     */
    public static function countingMintSql(): string
    {
        // The caller binds a cheap, indexed source roster FIRST. Neither stale
        // target statistics nor an anti-join can expand this into a planet scan.
        return "WITH source_items AS MATERIALIZED (
                    SELECT s.* FROM sim_items s
                    WHERE s.run_id = ? AND s.kind = 'election_scope'
                      AND s.id IN (SELECT value::uuid FROM jsonb_array_elements_text(?::jsonb))
                )
                INSERT INTO sim_items
                    (id, run_id, kind, status, jurisdiction_id, race_id, adm_level, unit_key,
                     position, est_cost, metrics, created_at, updated_at)
                 SELECT gen_random_uuid(), ?, 'count_election', 'pending',
                        e.jurisdiction_id, e.id, s.adm_level, e.id::text,
                        s.position, 0, '{}', now(), now()
                   FROM source_items s
                   JOIN elections e ON s.race_id = e.id
                  WHERE s.status = 'done' AND e.status NOT IN ('certified', 'cancelled')
                    AND EXISTS (SELECT 1 FROM election_races r WHERE r.election_id = e.id)
                 ON CONFLICT (run_id, kind, unit_key) DO NOTHING";
    }

    /** Persist generation progress; completed means every source was examined. */
    private function generationState(SimRun $run, array $state): void
    {
        $timings = $run->phase_timings ?? [];
        $timings[$run->phase]['worklist'] = $state;
        $run->forceFill(['phase_timings' => $timings])->save();
    }

    private function ensureWorklist(SimRun $run): bool
    {
        if ($run->phase_timings[$run->phase]['worklist']['complete'] ?? false) {
            return true;
        }
        // Missing state on an older installation is deliberately incomplete.
        // Replaying the idempotent generator reconciles already-minted items.
        $this->mintWorklist($run, $run->phase);

        return (bool) ($run->phase_timings[$run->phase]['worklist']['complete'] ?? false);
    }

    /**
     * Phases 6–11 derive work only from a bounded, materialized source batch.
     * Keep the existing eligibility rules; unique conflicts collapse both
     * multi-chamber jurisdictions and items already present on an upgraded run.
     */
    public static function scopedMintSql(string $phase): string
    {
        $sourceKind = self::CURSOR_SOURCES[$phase] ?? throw new \InvalidArgumentException('Unknown cursor phase.');
        $targetKind = match ($phase) {
            'training' => 'training_scope', 'governance' => 'governance_scope',
            'judiciary' => 'judiciary_scope', 'civics' => 'civics_scope',
            'stipends' => 'stipend_scope', 'verifying' => 'verify_scope',
            default => throw new \InvalidArgumentException('Not a jurisdiction-scoped phase.'),
        };
        $seated = in_array($phase, ['training', 'governance'], true);
        $jurisdiction = $seated ? 'e.jurisdiction_id' : 's.jurisdiction_id';
        // Cast the bounded source key, not the indexed elections primary key.
        $join = $seated ? 'JOIN elections e ON e.id = s.unit_key::uuid' : '';
        $eligible = match ($phase) {
            // A leaf pays once; ancestors must not pay its residents again.
            'stipends' => "s.status = 'done' AND NOT EXISTS (
                SELECT 1 FROM jurisdictions ch
                WHERE ch.parent_id = s.jurisdiction_id AND ch.deleted_at IS NULL)",
            // Verify every enrolled chamber, even a cohort with a review verdict.
            'verifying' => 'EXISTS (SELECT 1 FROM legislatures l
                WHERE l.jurisdiction_id = s.jurisdiction_id AND l.deleted_at IS NULL)',
            default => "s.status = 'done'",
        };

        return "WITH source_items AS MATERIALIZED (
                    SELECT s.* FROM sim_items s
                    WHERE s.run_id = ? AND s.kind = '{$sourceKind}'
                      AND s.id IN (SELECT value::uuid FROM jsonb_array_elements_text(?::jsonb))
                )
                INSERT INTO sim_items
                    (id, run_id, kind, status, jurisdiction_id, adm_level, unit_key,
                     position, est_cost, metrics, created_at, updated_at)
                 SELECT DISTINCT ON ({$jurisdiction})
                        gen_random_uuid(), ?, '{$targetKind}', 'pending',
                        {$jurisdiction}, s.adm_level, {$jurisdiction}::text,
                        s.position, 0, '{}', now(), now()
                   FROM source_items s {$join}
                  WHERE {$eligible}
                  ORDER BY {$jurisdiction}, s.unit_key
                 ON CONFLICT (run_id, kind, unit_key) DO NOTHING";
    }

    private function mintCursorWorklist(SimRun $run, string $phase): int
    {
        $state = $run->phase_timings[$phase]['worklist'] ?? [];
        $sql = $phase === 'counting' ? self::countingMintSql() : self::scopedMintSql($phase);
        $total = 0;
        while (true) {
            $run->refresh();
            if (! $run->isClaimable() || $run->phase !== $phase) {
                return $total;
            }
            // Existing unique (run_id, kind, unit_key) index is also this cursor's
            // ordered access path. Do NOT filter eligibility before the limit.
            $source = DB::table('sim_items')->where('run_id', $run->id)
                ->where('kind', self::CURSOR_SOURCES[$phase])
                ->when(isset($state['cursor']), fn ($q) => $q->where('unit_key', '>', $state['cursor']))
                ->orderBy('unit_key')->limit(self::mintChunk())->get(['id', 'unit_key']);
            if ($source->isEmpty()) {
                $this->generationState($run, array_replace($state, ['complete' => true]));
                return $total;
            }
            // The inserted chunk and its cursor commit together. A crash cannot
            // advance past unwritten work; duplicates on upgrade are harmless.
            $n = DB::transaction(function () use ($run, $source, $sql, &$state): int {
                $n = DB::affectingStatement($sql, [
                    $run->id, json_encode($source->pluck('id')->all(), JSON_THROW_ON_ERROR), $run->id,
                ]);
                $state = [
                    'complete' => false, 'cursor' => $source->last()->unit_key,
                    'scanned' => ($state['scanned'] ?? 0) + $source->count(),
                    'minted' => ($state['minted'] ?? 0) + $n,
                ];
                $this->generationState($run, $state);
                return $n;
            });
            $total += $n;
            $this->line("{$phase} queue: {$state['scanned']} sources examined, {$state['minted']} new items");
        }
    }

    /**
     * Mint the worklist for a phase we are entering. Bounded, individually
     * committed chunks. Counting and phases 6–11 use durable source cursors
     * and unique conflict guards; earlier phases retain their existing SQL.
     * A missing completion marker makes the next pump resume generation.
     *
     * Ordering is LARGEST-FIRST via `position`, carried over from the item the
     * work derives from.
     */
    private function mintWorklist(SimRun $run, string $phase): int
    {
        if (isset(self::CURSOR_SOURCES[$phase])) {
            return $this->mintCursorWorklist($run, $phase);
        }
        // Only stages whose worklist is derived post-hoc are minted here;
        // `cohort_scope` is enumerated by sim:start from the jurisdiction table.
        $sql = match ($phase) {
            // One identity roster per jurisdiction that actually got a cohort.
            'identities' => "INSERT INTO sim_items
                    (id, run_id, kind, status, jurisdiction_id, adm_level, unit_key,
                     position, est_cost, metrics, created_at, updated_at)
                 SELECT gen_random_uuid(), ?, 'identity_batch', 'pending',
                        c.jurisdiction_id, s.adm_level, c.jurisdiction_id::text,
                        -- BOTTOM-UP claim order (2026-09-07): deepest adm level
                        -- first, so a leaf's people are minted and swept up
                        -- BEFORE its parent's item runs. The parent then sees
                        -- them in its existing-count and mints none of its own
                        -- (the bind-up dedup). Claim order is position ASC, so
                        -- deeper = smaller = claimed first.
                        (99 - COALESCE(s.adm_level, 6)), c.electorate, '{}', now(), now()
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
                  LIMIT ".self::mintChunk(),
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
                  LIMIT ".self::mintChunk(),
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
                  LIMIT ".self::mintChunk(),

            default => null,
        };

        if ($sql === null) {
            $this->generationState($run, ['complete' => true]);
            return 0;
        }

        // Bindings must match the placeholder count of the chosen statement —
        // they differ per phase, and a fixed count silently breaks the phase
        // whose SQL has fewer.
        $bindings = array_fill(0, substr_count($sql, '?'), $run->id);

        $total = 0;

        do {
            $run->refresh();
            if (! $run->isClaimable() || $run->phase !== $phase) {
                return $total;
            }
            $n = DB::affectingStatement($sql, $bindings);
            $total += $n;
        } while ($n > 0);

        $this->generationState($run, ['complete' => true]);
        return $total;
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

        // REAP BY DEAD HEARTBEAT (2026-09-07, operator order — Step 3/4 already
        // do this when a map or an institution gets stuck). A live worker
        // heartbeats its lease even MID-ITEM, so a running item whose claiming
        // lease has not been seen within the grace is held by a DEAD worker and
        // belongs straight back on the pile. This reaps orphans in ~2 minutes
        // instead of waiting the 30-minute absolute STALE_SECONDS, which left
        // the run frozen on screen after a worker died. The network lane keeps
        // its long grace (a blocking LLM call may not heartbeat), and a null
        // token (a half-written claim) is reaped immediately. The absolute-age
        // check stays as a backstop should a heartbeat ever regress.
        DB::update(
            "UPDATE sim_items s
                SET status = ?, claim_token = NULL,
                    reason = 'reclaimed: worker died mid-item', updated_at = now()
              WHERE s.run_id = ?
                AND s.status = ?
                AND (
                    s.claim_token IS NULL
                    OR NOT EXISTS (
                        SELECT 1 FROM sim_worker_leases l
                         WHERE l.id = s.claim_token
                           AND l.last_seen_at > now() - make_interval(secs => CASE
                                 WHEN s.kind IN ({$placeholders}) THEN {$network} ELSE 120 END))
                    OR s.updated_at < now() - make_interval(secs => CASE
                            WHEN s.kind IN ({$placeholders}) THEN {$network} ELSE {$ordinary} END)
                )",
            array_merge(
                [SimItem::STATUS_PENDING, $run->id, SimItem::STATUS_RUNNING],
                $networkKinds,
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
        // Empty today is not drained until enumeration has durably finished.
        // This also resumes a generator killed after publishing the new phase.
        if (! $this->ensureWorklist($run)) {
            return;
        }
        $kinds = $run->currentKinds();

        // An empty-kind phase (enumerating / profiling) has nothing to wait for,
        // so it must ADVANCE, not return. A phase with declared kinds — now
        // including verifying (G1) — blocks the advance until its own items
        // settle; a scope with no chambers mints zero verify items and advances
        // straight through, so a chamberless world never deadlocks here.
        $open = $kinds !== [] && DB::table('sim_items')
            ->where('run_id', $run->id)
            ->whereIn('kind', $kinds)
            ->whereIn('status', SimItem::OPEN)
            ->exists();

        if ($open) {
            return;
        }

        // A worker's DONE status and delta commit together. Do not finish a
        // phase before all its durable progress has reached the headline row.
        if (SimWorldCounters::available()) {
            SimWorldCounters::flush((string) $run->id);
            if (DB::table('sim_world_counter_deltas')->where('run_id', $run->id)->exists()) {
                return; // another bounded pump pass (or the owning worker) merges it
            }
        }

        // Skip phases not in the run's chosen scope (dependency-aware). The
        // closure in nextActivePhase guarantees a selected aspect's prerequisites
        // are in scope, so skipping never drops something a later phase needs.
        $next = $run->nextActivePhase();

        if ($next === null) {
            return;
        }

        $timings = $run->phase_timings ?? [];
        $timings[$run->phase]['finished_at'] = now()->toIso8601String();
        $timings[$next]['started_at'] = now()->toIso8601String();

        $run->forceFill(['phase' => $next, 'phase_timings' => $timings])->save();

        // ARM THE GATE ONCE, at the training transition (W7 item 7). Publishing
        // the catalog makes the tracks live; doing it HERE — the training phase
        // now runs right after seating, BEFORE the content stages — arms the
        // gate and then TrainingStage trains the seated chamber, so the gated
        // F-LEG acts that governance / judiciary / civics file are performed by
        // trained holders (the tutorial-before-you-act model, operator
        // 2026-09-07). Idempotent — a resumed run re-publishing revises in place.
        if ($next === 'training') {
            $counts = app(\App\Services\Education\EducationCatalogService::class)->publish();
            $this->info("education catalog published: {$counts['tracks']} tracks, {$counts['modules']} modules (gate armed)");
        }

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
