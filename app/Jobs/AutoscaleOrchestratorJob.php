<?php

namespace App\Jobs;

use App\Models\AutoscaleRun;
use App\Services\AuditService;
use App\Services\ConstitutionalDefaults;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Full-scale autoscale orchestrator (operator ruling 2026-07-18): map-data
 * acceptance kicks off governance for ALL jurisdictions — size every
 * legislature (TRUE ALL SCALE: all ~951k rows, adm6 villages included), then
 * district-map every one (48k parent sweeps + ~903k set-based single-district
 * leaf councils).
 *
 * TICK MODEL — this job is NOT a days-long loop. Each dispatch is one tick:
 *   1. schedule the next tick FIRST (delayed self-dispatch), then work —
 *      a crash mid-tick self-heals when the pre-scheduled successor adopts;
 *   2. phase-advance the run: queued → sizing (Phase A parents per-row +
 *      leaves set-based, Phase B item enumeration) → mapping (one adm level
 *      of set-based singles per tick + sweep-wave top-ups) → done;
 *   3. recompute the dashboard counters + heartbeat (updated_at).
 *
 * Concurrent ticks (multiple chains after resume dispatches) are safe: phase
 * ownership is an atomic status UPDATE, every phase step is NOT-EXISTS
 * idempotent, and a tick that finds a live owner (fresh heartbeat) no-ops.
 * The wave dispatcher flips items pending → queued with UPDATE … RETURNING,
 * so two ticks can never dispatch the same legislature twice.
 *
 * All heavy state lives in autoscale_runs / autoscale_items — the run is
 * fully resumable across horizon restarts, box reboots, and worker deaths.
 */
class AutoscaleOrchestratorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Sizing ticks run 1–2 h; supervisor-autoscale-tick has timeout 0. */
    public int $timeout = 0;
    public int $tries   = 1;

    /** Seconds between ticks while mapping (sweep-wave top-ups). */
    private const TICK_DELAY = 90;

    /**
     * Sweep-wave buffer: keep workers × 30 jobs queued+running (min 12).
     * The buffer must outlast a full tick interval at the FAST tail —
     * deep-level sweeps run ~3-5 s, so a fixed dozen would drain in seconds
     * and leave every worker idle until the next tick: the tick cadence,
     * not the host, would set the pace (operator ruling 2026-07-18: the
     * host sets the pace). Queue depth is near-free; halt stays safe (a
     * queued job re-checks the halt flag and hands its item back).
     */
    private static function inflightTarget(): int
    {
        return max(12, \App\Support\HostCapacity::autoscaleWorkers() * 30);
    }

    /**
     * Per-run pg advisory-lock class (bytes of "ASCA"): a tick that cannot
     * take pg_try_advisory_lock(LOCK_CLASS, hashtext(run_id)) is a redundant
     * chain link and no-ops. THIS is the single-writer guarantee for phase
     * work — never the supervisor's maxProcesses (a hand-run `queue:work`
     * during a stall must not be able to double-run sizing).
     */
    private const LOCK_CLASS = 0x41534341;

    /**
     * A 'running' sweep item whose legislature has NO mass_running cache flag
     * (the job refreshes it — and touches the item row — via every progress
     * publish) and whose row hasn't moved for this long is a dead worker's
     * orphan — reclaim it. Singles level claims (no cache flag) reclaim on a
     * 4 h-stale row instead.
     */
    private const RECLAIM_AFTER_SECONDS = 1800;

    public function __construct(private readonly string $runId)
    {
        $this->onQueue('autoscale-tick');
    }

    public function handle(): void
    {
        $run = AutoscaleRun::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        // Terminal runs end every tick chain — no reschedule.
        if (in_array($run->status, ['done', 'failed'], true)) {
            return;
        }

        // Duplicate-run dedupe (ms-window races between acceptMaps and the
        // CLI): the NEWEST run yields to an older unfinished one, so exactly
        // one run's chains survive and its items are the world's single
        // work-list.
        $older = AutoscaleRun::query()
            ->whereIn('status', ['queued', 'sizing', 'mapping', 'halted'])
            ->where('created_at', '<', $run->created_at)
            ->orderBy('created_at')
            ->first();
        if ($older !== null && in_array($run->status, ['queued', 'sizing', 'mapping', 'halted'], true)) {
            $run->forceFill([
                'status'     => 'failed',
                'last_error' => "superseded: older unfinished run {$older->id} exists and was resumed instead",
                'finished_at' => now(),
            ])->save();
            static::dispatch((string) $older->id);
            return;
        }

        // Operator halt: park the run, kill the chains. Resume (accept-maps
        // re-POST, dashboard Resume, or districting:autoscale --resume)
        // clears the flag, flips the status back, and dispatches a fresh
        // chain. The halt FANS OUT to every running sweep's per-legislature
        // mass_halt flag — that is the flag the sweep actually polls between
        // scopes, so "in-flight sweeps stop at their next scope boundary" is
        // true, not copy.
        if (Cache::get(AutoscaleRun::HALT_CACHE_KEY)) {
            if ($run->status !== 'halted') {
                $running = DB::table('autoscale_items')
                    ->where('run_id', $run->id)
                    ->where('kind', 'sweep')
                    ->where('status', 'running')
                    ->pluck('legislature_id');
                foreach ($running as $legId) {
                    Cache::put("legislature.{$legId}.mass_halt", true, 14400);
                }
                $run->forceFill(['status' => 'halted'])->save();
                Log::info('Autoscale halted by operator', [
                    'run_id' => $run->id, 'sweeps_signalled' => count($running),
                ]);
            }
            return;
        }
        if ($run->status === 'halted') {
            // Flag already cleared but status still parked → a resume dispatch
            // is reviving this run. Rewind to the phase that was interrupted;
            // every phase step is idempotent, so re-entering is always safe.
            $run->forceFill([
                'status' => $run->mapping_started_at !== null
                    ? 'mapping'
                    : ($run->sizing_started_at !== null ? 'sizing' : 'queued'),
            ])->save();
        }

        // Schedule the successor BEFORE working: a crash mid-tick self-heals.
        static::dispatch($this->runId)->delay(now()->addSeconds(self::TICK_DELAY));

        // Single-writer guarantee: a per-run session advisory lock. A tick
        // that cannot take it is a redundant chain link (another tick is
        // mid-phase — e.g. the 1–2 h sizing Artisan call, which never
        // heartbeats). SIGKILL releases session locks with the connection,
        // so a dead owner never wedges the run.
        $locked = (bool) (DB::selectOne(
            'SELECT pg_try_advisory_lock(?, hashtext(?)) AS ok',
            [self::LOCK_CLASS, (string) $run->id]
        )->ok ?? false);
        if (! $locked) {
            return;
        }

        try {
            if ($run->status === 'queued') {
                // Atomic ownership: exactly one tick flips queued → sizing.
                $owned = AutoscaleRun::query()
                    ->whereKey($run->id)
                    ->where('status', 'queued')
                    ->update(['status' => 'sizing', 'sizing_started_at' => now(), 'updated_at' => now()]);
                if ($owned === 0) {
                    return; // another tick owns sizing
                }
                $run->refresh();
            }

            if ($run->status === 'sizing') {
                $this->runSizing($run);
                $run->refresh();
            }

            if ($run->status === 'mapping') {
                $this->runMappingTick($run);
            }
        } catch (\Throwable $e) {
            // Never let one bad tick kill the run — the pre-scheduled
            // successor retries. Record for the dashboard.
            Log::error('Autoscale tick error: '.$e->getMessage(), ['run_id' => $run->id]);
            AutoscaleRun::query()->whereKey($run->id)->update([
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
        } finally {
            try {
                DB::statement('SELECT pg_advisory_unlock(?, hashtext(?))', [self::LOCK_CLASS, (string) $run->id]);
            } catch (\Throwable) {
                // A dropped connection released the session lock already.
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('AutoscaleOrchestratorJob failed', [
            'run_id'  => $this->runId,
            'message' => $exception->getMessage(),
        ]);

        // Keep the chain alive on unexpected worker-level failures (the
        // in-handle catch already covers work errors). A terminal run's tick
        // exits before working, so this cannot loop a finished run.
        $run = AutoscaleRun::query()->find($this->runId);
        if ($run !== null && $run->isActive()) {
            $run->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 2000)])->save();
            static::dispatch($this->runId)->delay(now()->addSeconds(600));
        }
    }

    // -------------------------------------------------------------------------
    // Phase A + B — sizing (TRUE ALL SCALE) and item enumeration
    // -------------------------------------------------------------------------

    private function runSizing(AutoscaleRun $run): void
    {
        $admMax = (int) $run->adm_max;

        // A1 — parents (48k): the proven per-row cube-root path. Idempotent
        // (upserts). Childless rows are excluded (--parents-only); they get
        // the set-based treatment below — a per-row loop over 903k leaves
        // would alone take days.
        Log::info('Autoscale Phase A: sizing parents', ['run_id' => $run->id]);
        Artisan::call('apportionment:seed', [
            '--parents-only' => true,
            '--adm-max'      => $admMax,
        ]);
        $run->forceFill(['updated_at' => now()])->save(); // heartbeat

        if ($this->haltRequested()) {
            return;
        }

        // A2 — leaves (~903k incl. all adm6 villages): ONE INSERT…SELECT per
        // adm level. Seats mirror the per-row leaf path exactly:
        // min(ceiling, max(floor, round(pop^⅓))) under the planet defaults
        // (lawful in a founding world — every jurisdiction inherits them),
        // quorum = max(3, ceil(total/2)).
        $floor   = ConstitutionalDefaults::floor();
        $ceiling = ConstitutionalDefaults::ceiling();

        for ($lvl = 0; $lvl <= $admMax; $lvl++) {
            if ($this->haltRequested()) {
                return;
            }
            DB::statement("
                INSERT INTO legislatures
                    (id, jurisdiction_id, term_number, status,
                     total_seats, type_a_seats, type_b_seats, quorum_required,
                     created_at, updated_at)
                SELECT gen_random_uuid(), j.id, 1, 'forming',
                       s.seats, s.seats, 0,
                       GREATEST(3, CEIL(s.seats / 2.0))::int,
                       now(), now()
                  FROM jurisdictions j
                 CROSS JOIN LATERAL (
                       SELECT LEAST(?, GREATEST(?, ROUND(POWER(GREATEST(COALESCE(j.population, 0), 1)::numeric, 1.0/3.0))))::int AS seats
                 ) s
                 WHERE j.deleted_at IS NULL
                   AND j.adm_level = ?
                   AND NOT EXISTS (SELECT 1 FROM jurisdictions c
                                    WHERE c.parent_id = j.id AND c.deleted_at IS NULL)
                   AND NOT EXISTS (SELECT 1 FROM legislatures l
                                    WHERE l.jurisdiction_id = j.id AND l.deleted_at IS NULL)
            ", [$ceiling, $floor, $lvl]);
            $run->forceFill(['updated_at' => now()])->save(); // heartbeat per level
        }

        // Counters + the canonical apportionment completion stamp (the same
        // record ApportionmentSeedCommand --stamp-instance writes; here it
        // covers parents AND leaves, so the orchestrator stamps it itself).
        $sizedParents = (int) DB::scalar("
            SELECT COUNT(*) FROM legislatures l
             WHERE l.deleted_at IS NULL
               AND EXISTS (SELECT 1 FROM jurisdictions c
                            WHERE c.parent_id = l.jurisdiction_id AND c.deleted_at IS NULL)
        ");
        $sizedLeaves = (int) DB::scalar("
            SELECT COUNT(*) FROM legislatures l
             WHERE l.deleted_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM jurisdictions c
                                WHERE c.parent_id = l.jurisdiction_id AND c.deleted_at IS NULL)
        ");

        DB::table('instance_settings')
            ->whereNull('deleted_at')
            ->update([
                'apportionment_completed_at' => now(),
                'apportionment_log'          => sprintf(
                    'Autoscale apportionment %s — TRUE ALL SCALE: %d parent legislatures (per-row cube root), %d leaf legislatures (set-based), adm ≤ %d.',
                    now()->toIso8601String(),
                    $sizedParents,
                    $sizedLeaves,
                    $admMax,
                ),
                'updated_at' => now(),
            ]);

        // The founding bootstrap board (design §B.3.1) — R-08's substrate.
        // The sweeps' F-ELB-008 line-split filings authorize through the
        // operator's R-08, which RoleService derives from "is_operator AND an
        // active bootstrap board exists" — so the root's board must be
        // constituted BEFORE the first wave, exactly as ActivationService
        // does at CLK-06 activation (board + synthetic system member + audit).
        $this->ensureRootBootstrapBoard($run);

        // Phase B — enumerate every legislature into autoscale_items: kind
        // sweep (has children → real mixed-autoseed run) or single (childless
        // leaf → set-based at-large district). Position = adm ASC, pop DESC:
        // Earth first, giants early. Idempotent via the per-run NOT EXISTS.
        DB::statement("
            INSERT INTO autoscale_items
                (id, run_id, legislature_id, jurisdiction_id, adm_level, kind,
                 status, position, seats_expected, created_at, updated_at)
            SELECT gen_random_uuid(), ?, l.id, j.id, j.adm_level,
                   CASE WHEN EXISTS (SELECT 1 FROM jurisdictions c
                                      WHERE c.parent_id = j.id AND c.deleted_at IS NULL)
                        THEN 'sweep' ELSE 'single' END,
                   'pending',
                   ROW_NUMBER() OVER (ORDER BY j.adm_level ASC, j.population DESC NULLS LAST, j.id),
                   l.type_a_seats,
                   now(), now()
              FROM legislatures l
              JOIN jurisdictions j ON j.id = l.jurisdiction_id AND j.deleted_at IS NULL
             WHERE l.deleted_at IS NULL
               AND j.adm_level <= ?
               AND NOT EXISTS (SELECT 1 FROM autoscale_items ai
                                WHERE ai.run_id = ? AND ai.legislature_id = l.id)
        ", [$run->id, (int) $run->adm_max, $run->id]);

        $singlesTotal = (int) DB::table('autoscale_items')
            ->where('run_id', $run->id)->where('kind', 'single')->count();
        $sweepsTotal = (int) DB::table('autoscale_items')
            ->where('run_id', $run->id)->where('kind', 'sweep')->count();

        $run->forceFill([
            'status'             => 'mapping',
            'mapping_started_at' => $run->mapping_started_at ?? now(),
            'sized_parents'      => $sizedParents,
            'sized_leaves'       => $sizedLeaves,
            'singles_total'      => $singlesTotal,
            'sweeps_total'       => $sweepsTotal,
        ])->save();

        app(AuditService::class)->append(
            module: 'elections',
            event: 'autoscale.sizing_completed',
            payload: [
                'run_id'        => (string) $run->id,
                'sized_parents' => $sizedParents,
                'sized_leaves'  => $sizedLeaves,
                'adm_max'       => (int) $run->adm_max,
                'generator'     => 'AutoscaleOrchestratorJob (True All Scale, 2026-07-18)',
            ],
            ref: 'WF-ELE-02',
        );

        Log::info('Autoscale sizing complete', [
            'run_id' => $run->id, 'parents' => $sizedParents, 'leaves' => $sizedLeaves,
            'singles' => $singlesTotal, 'sweeps' => $sweepsTotal,
        ]);
    }

    /**
     * Mirror of ActivationService::ensureBootstrapBoard for the planet root:
     * one active is_bootstrap board + the synthetic system member (user_id
     * NULL, always seated per the B-2 CHECK). An existing ACTIVE board on the
     * root is adopted. Idempotent across resumes.
     */
    private function ensureRootBootstrapBoard(AutoscaleRun $run): void
    {
        $root = DB::table('jurisdictions')
            ->where('adm_level', 0)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->first(['id']);
        if ($root === null) {
            return;
        }

        $existing = DB::table('election_boards')
            ->where('jurisdiction_id', $root->id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->exists();
        if ($existing) {
            return;
        }

        $legislatureId = DB::table('legislatures')
            ->where('jurisdiction_id', $root->id)
            ->whereNull('deleted_at')
            ->value('id');

        $boardId  = (string) \Illuminate\Support\Str::uuid();
        $memberId = (string) \Illuminate\Support\Str::uuid();
        DB::table('election_boards')->insert([
            'id'              => $boardId,
            'jurisdiction_id' => $root->id,
            'legislature_id'  => $legislatureId,
            'is_bootstrap'    => true,
            'status'          => 'active',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        DB::table('election_board_members')->insert([
            'id'                => $memberId,
            'election_board_id' => $boardId,
            'user_id'           => null, // THE SYSTEM ITSELF (B-2 schema)
            'status'            => 'seated',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        app(AuditService::class)->append(
            module: 'elections',
            event: 'bootstrap_board_constituted',
            payload: [
                'election_board_id' => $boardId,
                'legislature_id'    => $legislatureId,
                'is_bootstrap'      => true,
                'system_member_id'  => $memberId,
                'banner'            => 'temporary · replacement queued (retired by WF-ELE-10, Phase C)',
                'generator'         => 'AutoscaleOrchestratorJob (True All Scale, 2026-07-18)',
            ],
            ref: 'WF-ELE-02',
            jurisdictionId: (string) $root->id,
        );
    }

    // -------------------------------------------------------------------------
    // Mapping tick — singles levels, sweep waves, reclaim, completion
    // -------------------------------------------------------------------------

    private function runMappingTick(AutoscaleRun $run): void
    {
        // 1. Singles: process at most ONE adm level per tick (each level is a
        //    handful of set-based statements; the biggest — adm6, ~700k — runs
        //    minutes). Sweep waves below keep the workers fed in parallel.
        $nextSinglesLevel = DB::table('autoscale_items')
            ->where('run_id', $run->id)
            ->where('kind', 'single')
            ->where('status', 'pending')
            ->min('adm_level');

        if ($nextSinglesLevel !== null) {
            $this->processSinglesLevel($run, (int) $nextSinglesLevel);
        }

        // 2. Reclaim orphans, kind-tiered:
        //    - sweeps: a 'running' item whose legislature has no mass_running
        //      flag (jobs hold + refresh it — and DB-heartbeat the item row —
        //      via every progress publish; finally clears it) and whose row
        //      is stale is a dead worker's leftovers. Bounded by
        //      INFLIGHT_TARGET, so the per-item cache check is cheap.
        //    - queued: a payload lost between the wave flip and delivery
        //      (redis flush, SIGKILL mid-dispatch) would otherwise sit
        //      `queued` FOREVER — it counts as in-flight and blocks both
        //      top-ups and completion. Reclaiming queued → pending is always
        //      safe: a late-arriving stale payload finds the item non-queued
        //      and exits at its atomic flip.
        //    - singles: level claims have no cache flag and up to ~700k rows
        //      — ONE set-based statement, 4 h-stale only (far beyond any
        //      level statement).
        $staleSweeps = DB::table('autoscale_items')
            ->where('run_id', $run->id)
            ->where('kind', 'sweep')
            ->where('status', 'running')
            ->where('updated_at', '<', now()->subSeconds(self::RECLAIM_AFTER_SECONDS))
            ->get(['id', 'legislature_id']);
        foreach ($staleSweeps as $item) {
            if (! Cache::get("legislature.{$item->legislature_id}.mass_running")) {
                DB::table('autoscale_items')
                    ->where('id', $item->id)
                    ->where('status', 'running')
                    ->update([
                        'status'     => 'pending',
                        'reason'     => 'reclaimed: worker died mid-sweep',
                        'updated_at' => now(),
                    ]);
                Log::warning('Autoscale reclaimed orphaned sweep item', [
                    'run_id' => $run->id, 'legislature_id' => $item->legislature_id,
                ]);
            }
        }

        $reclaimedQueued = DB::table('autoscale_items')
            ->where('run_id', $run->id)
            ->where('status', 'queued')
            // 2 h, not the sweep threshold: the host-scaled buffer means an
            // item can legitimately sit queued behind slow giants for a long
            // while — churning it would just pile up no-op payloads. A
            // genuinely lost payload (redis flush) still self-heals.
            ->where('updated_at', '<', now()->subHours(2))
            ->update([
                'status'     => 'pending',
                'reason'     => 'reclaimed: queued payload never delivered',
                'updated_at' => now(),
            ]);
        if ($reclaimedQueued > 0) {
            Log::warning('Autoscale reclaimed undelivered queued items', [
                'run_id' => $run->id, 'count' => $reclaimedQueued,
            ]);
        }

        $reclaimedSingles = DB::table('autoscale_items')
            ->where('run_id', $run->id)
            ->where('kind', 'single')
            ->where('status', 'running')
            ->where('updated_at', '<', now()->subHours(4))
            ->update([
                'status'     => 'pending',
                'reason'     => 'reclaimed: tick died mid-level',
                'updated_at' => now(),
            ]);
        if ($reclaimedSingles > 0) {
            Log::warning('Autoscale reclaimed a dead singles level claim', [
                'run_id' => $run->id, 'count' => $reclaimedSingles,
            ]);
        }

        // 3. Sweep waves: keep the host-scaled buffer queued+running. The
        //    UPDATE…RETURNING flip makes double-dispatch impossible even with
        //    concurrent ticks.
        $target   = self::inflightTarget();
        $inflight = (int) DB::table('autoscale_items')
            ->where('run_id', $run->id)
            ->where('kind', 'sweep')
            ->whereIn('status', ['queued', 'running'])
            ->count();

        if ($inflight < $target) {
            $flipped = DB::select("
                UPDATE autoscale_items
                   SET status = 'queued', updated_at = now()
                 WHERE id IN (
                       SELECT id FROM autoscale_items
                        WHERE run_id = ? AND kind = 'sweep' AND status = 'pending'
                        ORDER BY position
                        LIMIT ?
                        FOR UPDATE SKIP LOCKED
                 )
                   AND status = 'pending'
             RETURNING id
            ", [$run->id, $target - $inflight]);

            foreach ($flipped as $row) {
                AutoscaleLegislatureJob::dispatch((string) $row->id);
            }
        }

        // 4. Counters + heartbeat for the dashboard.
        $counts = DB::table('autoscale_items')
            ->where('run_id', $run->id)
            ->selectRaw("
                COUNT(*) FILTER (WHERE kind = 'single' AND status = 'done')   AS singles_done,
                COUNT(*) FILTER (WHERE kind = 'sweep'  AND status = 'done')   AS sweeps_done,
                COUNT(*) FILTER (WHERE status = 'review')                     AS review_count,
                COUNT(*) FILTER (WHERE status IN ('pending','queued','running')) AS open_count
            ")
            ->first();

        $run->forceFill([
            'singles_done' => (int) $counts->singles_done,
            'sweeps_done'  => (int) $counts->sweeps_done,
            'review_count' => (int) $counts->review_count,
            'updated_at'   => now(),
        ])->save();

        // 5. Completion: nothing pending, queued, or running → done. Items in
        //    review/failed/halted stay on the list for the operator; they
        //    never block completion (failures never sink the run).
        if ((int) $counts->open_count === 0) {
            $run->forceFill(['status' => 'done', 'finished_at' => now()])->save();

            app(AuditService::class)->append(
                module: 'elections',
                event: 'autoscale.completed',
                payload: [
                    'run_id'       => (string) $run->id,
                    'singles_done' => (int) $counts->singles_done,
                    'sweeps_done'  => (int) $counts->sweeps_done,
                    'review_count' => (int) $counts->review_count,
                    'generator'    => 'AutoscaleOrchestratorJob (True All Scale, 2026-07-18)',
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
    }

    /**
     * Set-based singles for one adm level: founding map (active) + single
     * at-large district (row shape =
     * InitialDistrictMapService::clampUnassignedLeafGiants) + membership +
     * spatial stats, then flip the items done. Every statement is NOT-EXISTS
     * idempotent — a crashed level re-runs cleanly.
     */
    private function processSinglesLevel(AutoscaleRun $run, int $lvl): void
    {
        // ATOMIC LEVEL CLAIM: flip the level's items pending → running first.
        // Two concurrent ticks (multiple chains after a resume) must never run
        // the set-based statements for the same level at once — the map INSERT's
        // NOT-EXISTS check is read-then-write, and a doubled run would mint TWO
        // active founding maps per legislature. The loser of this UPDATE claims
        // nothing and skips. A crashed claim is reclaimed (kind-tiered rule in
        // runMappingTick) and every statement below is NOT-EXISTS idempotent,
        // so a redo is clean.
        $claimed = DB::update("
            UPDATE autoscale_items
               SET status = 'running', started_at = COALESCE(started_at, now()), updated_at = now()
             WHERE run_id = ? AND kind = 'single' AND status = 'pending' AND adm_level = ?
        ", [$run->id, $lvl]);
        if ($claimed === 0) {
            return;
        }

        $itemFilter = "ai.run_id = ? AND ai.kind = 'single' AND ai.status = 'running' AND ai.adm_level = ?";

        // 1. Founding maps — created directly ACTIVE: the founding context
        //    (setup, v1 maps) activates without a board, same as the founder's
        //    manual v1 maps. Legislatures that already have an active map
        //    (operator work) are left untouched.
        DB::statement("
            INSERT INTO legislature_district_maps
                (id, legislature_id, name, description, status, effective_start, created_at, updated_at)
            SELECT gen_random_uuid(), l.id, 'Founding Map',
                   'Auto-generated by full-scale autoscale (True All Scale, 2026-07-18) — single at-large district.',
                   'active', CURRENT_DATE, now(), now()
              FROM autoscale_items ai
              JOIN legislatures l ON l.id = ai.legislature_id AND l.deleted_at IS NULL
             WHERE {$itemFilter}
               AND NOT EXISTS (SELECT 1 FROM legislature_district_maps m
                                WHERE m.legislature_id = l.id
                                  AND m.status = 'active'
                                  AND m.deleted_at IS NULL)
        ", [$run->id, $lvl]);

        // 2. The single at-large district: jurisdiction_id = self, district 1,
        //    seats = type_a (already ∈ [floor, ceiling] from sizing).
        DB::statement("
            INSERT INTO legislature_districts
                (id, legislature_id, jurisdiction_id, district_number, seats,
                 target_population, actual_population, status,
                 fractional_seats, floor_override, map_id, created_at, updated_at)
            SELECT gen_random_uuid(), l.id, l.jurisdiction_id, 1, l.type_a_seats,
                   COALESCE(j.population, 0), COALESCE(j.population, 0), 'active',
                   l.type_a_seats::numeric, false, m.map_id, now(), now()
              FROM autoscale_items ai
              JOIN legislatures l ON l.id = ai.legislature_id AND l.deleted_at IS NULL
              JOIN jurisdictions j ON j.id = l.jurisdiction_id
             CROSS JOIN LATERAL (
                   SELECT m2.id AS map_id FROM legislature_district_maps m2
                    WHERE m2.legislature_id = l.id AND m2.status = 'active' AND m2.deleted_at IS NULL
                    ORDER BY m2.created_at DESC LIMIT 1
             ) m
             WHERE {$itemFilter}
               AND NOT EXISTS (SELECT 1 FROM legislature_districts d
                                WHERE d.map_id = m.map_id AND d.deleted_at IS NULL)
        ", [$run->id, $lvl]);

        // 3. Membership: the jurisdiction itself (ldj XOR: jurisdiction side).
        DB::statement("
            INSERT INTO legislature_district_jurisdictions (id, district_id, jurisdiction_id)
            SELECT gen_random_uuid(), d.id, d.jurisdiction_id
              FROM autoscale_items ai
              JOIN legislatures l ON l.id = ai.legislature_id AND l.deleted_at IS NULL
             CROSS JOIN LATERAL (
                   SELECT m2.id AS map_id FROM legislature_district_maps m2
                    WHERE m2.legislature_id = l.id AND m2.status = 'active' AND m2.deleted_at IS NULL
                    ORDER BY m2.created_at DESC LIMIT 1
             ) m
              JOIN legislature_districts d ON d.map_id = m.map_id AND d.deleted_at IS NULL
             WHERE {$itemFilter}
               AND d.jurisdiction_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM legislature_district_jurisdictions x
                                WHERE x.district_id = d.id)
        ", [$run->id, $lvl]);

        // 4. Spatial stats, mirroring DistrictingService::recomputeDistrict:
        //    single-member districts are contiguous BY DEFINITION; parts +
        //    hull ratio from the (simplify-laddered, validity-repaired) geom.
        //    Scoped to the at-large district of the ACTIVE map (self-scoped,
        //    number 1) — never a blanket stamp over other districts an
        //    adopted operator map might hold.
        DB::statement("
            UPDATE legislature_districts d
               SET num_geom_parts    = ST_NumGeometries(g.geom),
                   convex_hull_ratio = ROUND((ST_Area(g.geom) / NULLIF(ST_Area(ST_ConvexHull(g.geom)), 0))::numeric, 6),
                   is_contiguous     = true,
                   updated_at        = now()
              FROM autoscale_items ai
              JOIN legislatures l ON l.id = ai.legislature_id AND l.deleted_at IS NULL
              JOIN jurisdictions j ON j.id = l.jurisdiction_id AND j.geom IS NOT NULL
             CROSS JOIN LATERAL (
                   SELECT m2.id AS map_id FROM legislature_district_maps m2
                    WHERE m2.legislature_id = l.id AND m2.status = 'active' AND m2.deleted_at IS NULL
                    ORDER BY m2.created_at DESC LIMIT 1
             ) m
             CROSS JOIN LATERAL (
                   SELECT CASE WHEN ST_NPoints(j.geom) > 1000000 THEN ST_MakeValid(ST_Simplify(j.geom, 0.01))
                               WHEN ST_NPoints(j.geom) > 50000  THEN ST_MakeValid(ST_Simplify(j.geom, 0.001))
                               ELSE ST_MakeValid(j.geom) END AS geom
             ) g
             WHERE {$itemFilter}
               AND d.legislature_id = l.id
               AND d.map_id = m.map_id
               AND d.jurisdiction_id = l.jurisdiction_id
               AND d.district_number = 1
               AND d.deleted_at IS NULL
               AND d.num_geom_parts IS NULL
        ", [$run->id, $lvl]);

        // 4b. Geometry-less leaves still get the definitional contiguity flag
        //     (recomputeDistrict writes true for every single-member district
        //     regardless of geometry) — viewers must not render "not yet
        //     computed" for a lawful at-large council.
        DB::statement("
            UPDATE legislature_districts d
               SET is_contiguous = true, updated_at = now()
              FROM autoscale_items ai
              JOIN legislatures l ON l.id = ai.legislature_id AND l.deleted_at IS NULL
             CROSS JOIN LATERAL (
                   SELECT m2.id AS map_id FROM legislature_district_maps m2
                    WHERE m2.legislature_id = l.id AND m2.status = 'active' AND m2.deleted_at IS NULL
                    ORDER BY m2.created_at DESC LIMIT 1
             ) m
             WHERE {$itemFilter}
               AND d.legislature_id = l.id
               AND d.map_id = m.map_id
               AND d.jurisdiction_id = l.jurisdiction_id
               AND d.district_number = 1
               AND d.deleted_at IS NULL
               AND d.is_contiguous IS NULL
        ", [$run->id, $lvl]);

        // 5. Flip the level's items done, seats/drift recorded from the map
        //    that actually exists (drift is INFORMATIONAL — the seating law
        //    forbids total-forcing; a single district drifts only when a
        //    pre-existing operator map is being adopted).
        DB::statement("
            UPDATE autoscale_items ai
               SET status = 'done',
                   seats_seated = s.seated,
                   drift = s.seated - COALESCE(ai.seats_expected, 0),
                   started_at = COALESCE(ai.started_at, now()),
                   finished_at = now(),
                   updated_at = now()
              FROM (
                    SELECT ai2.id, COALESCE(SUM(d.seats), 0) AS seated
                      FROM autoscale_items ai2
                      JOIN legislatures l ON l.id = ai2.legislature_id AND l.deleted_at IS NULL
                      LEFT JOIN legislature_district_maps m
                             ON m.legislature_id = l.id AND m.status = 'active' AND m.deleted_at IS NULL
                      LEFT JOIN legislature_districts d
                             ON d.map_id = m.id AND d.deleted_at IS NULL
                     WHERE ai2.run_id = ? AND ai2.kind = 'single'
                       AND ai2.status = 'running' AND ai2.adm_level = ?
                     GROUP BY ai2.id
              ) s
             WHERE ai.id = s.id
               AND s.seated > 0
        ", [$run->id, $lvl]);

        // Anything still claimed at this level has no seated district even
        // after the passes above (no legislature row? no active map?) —
        // surface it for review instead of looping forever.
        DB::table('autoscale_items')
            ->where('run_id', $run->id)
            ->where('kind', 'single')
            ->where('status', 'running')
            ->where('adm_level', $lvl)
            ->update([
                'status'      => 'review',
                'reason'      => 'set-based single-district seeding produced no seated district',
                'finished_at' => now(),
                'updated_at'  => now(),
            ]);

        // ONE summary audit append per level — never 700k chain entries.
        $doneCount = (int) DB::table('autoscale_items')
            ->where('run_id', $run->id)->where('kind', 'single')
            ->where('adm_level', $lvl)->where('status', 'done')->count();

        app(AuditService::class)->append(
            module: 'elections',
            event: 'autoscale.singles_generated',
            payload: [
                'run_id'         => (string) $run->id,
                'adm_level'      => $lvl,
                'maps_generated' => $doneCount,
                'shape'          => 'single at-large district (seats = type_a, member = self)',
                'generator'      => 'AutoscaleOrchestratorJob set-based singles (True All Scale, 2026-07-18)',
            ],
            ref: 'WF-ELE-02',
        );

        Log::info('Autoscale singles level complete', [
            'run_id' => $run->id, 'adm_level' => $lvl, 'done' => $doneCount,
        ]);
    }

    private function haltRequested(): bool
    {
        return (bool) Cache::get(AutoscaleRun::HALT_CACHE_KEY);
    }
}
