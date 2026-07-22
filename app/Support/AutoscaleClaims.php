<?php

namespace App\Support;

use App\Models\AutoscaleRun;
use Illuminate\Support\Facades\DB;

/**
 * The pull engine's claim ladder (re-engineering 2026-07-19).
 *
 * Workers call next() in a loop; each rung is ONE atomic
 * UPDATE … RETURNING with FOR UPDATE SKIP LOCKED, so any number of workers
 * partition the work-list without an orchestrator dispatching anything.
 * First rung with work wins:
 *
 *   1. singles batches  — 15k leaf-council rows per claim, ≤4 concurrent
 *                         claimants (the statements are PG-heavy; surplus
 *                         workers fall through and overlap on rungs 2–4);
 *   2. finalize         — a sweep item whose scopes have ALL closed flips
 *                         running→assessing atomically; the winner runs the
 *                         completeness assessment + activation. This rung is
 *                         also the crash recovery for a worker that died
 *                         between its last scope and the assessment;
 *   3. precompute       — one adjacency parent per claim, heaviest first
 *                         (LPT scheduling minimizes the makespan);
 *   4. sweep scopes     — bottom-up by item position (adm DESC, child_count
 *                         ASC at enumeration): all small parents first, the
 *                         monsters and Earth last, so the tail's in-flight
 *                         scope list IS the honest remaining ETA.
 *
 * Rung 4 is gated behind rung 3 unless CGA_AUTOSCALE_PRECOMPUTE=lazy — with
 * the lazy escape hatch sweeps write back adjacency as they go instead.
 */
final class AutoscaleClaims
{
    public const SINGLES_BATCH = 15000;

    /**
     * THE HEAVY LANE (operator ruling 2026-07-21): area_tier ≥ 4 (bbox above
     * ~30,000 km² — the 0.02°/0.05° grid-ladder class whose single grid
     * query runs tens of minutes). At most 20% of worker threads may hold
     * heavy scopes at once (2 of 10 on the game box), so the other workers
     * keep flying through light work and a consecutive giant block can never
     * capture the whole pool again (the est-2 tail collapse + both OOM
     * episodes were exactly that). THE DRAIN RULE: when no light work
     * remains pending, the cap lifts and every worker may take heavy
     * remainder. The cap is soft under claim races (two workers can read
     * the same count in the same instant) — a transient +1 overshoot is
     * harmless; the steady state honors the cap.
     */
    public const HEAVY_TIER = 4;

    public static function heavyWorkerCap(): int
    {
        $override = (int) config('cga.autoscale_heavy_cap', 0);
        if ($override > 0) {
            return $override;
        }

        return max(1, (int) ceil(0.2 * HostCapacity::autoscaleWorkers()));
    }

    /**
     * THE TOP-DOWN LANE (operator ruling 2026-07-22): 20% of the pool works
     * the queue from the TOP — most complex, highest population first
     * (position DESC; the enumeration key already encodes complexity
     * ascending). Composite districting is proven fast (Earth: the whole
     * planetary cascade in ~2h), so this churns the composite/mixed maps
     * early AND surfaces bug classes the bottom-up wall would not reach
     * for days. Top-down claims obey the SAME global heavy cap — the
     * 2-concurrent memory bound binds across lanes; a capped top-down
     * worker takes the highest-position LIGHT work instead.
     */
    public static function topDownWorkerCap(): int
    {
        $override = (int) config('cga.autoscale_topdown_cap', 0);
        if ($override > 0) {
            return $override;
        }

        return max(1, (int) ceil(0.2 * HostCapacity::autoscaleWorkers()));
    }

    /**
     * @return array{type: 'singles'|'precompute'|'scope', ...}|null
     */
    public static function next(AutoscaleRun $run, string $token, string $lane = 'auto'): ?array
    {
        // Top-down workers skip the bottom-up bulk rungs (singles are long
        // done; precompute is a run-level pass the auto pool owns) and go
        // straight for finalize + scopes from the top of the queue.
        if ($lane !== 'topdown') {
            if ($claim = static::claimSingles($run, $token)) {
                return $claim;
            }
        }
        if ($claim = static::claimFinalize($run, $token)) {
            return $claim;
        }
        if ($lane !== 'topdown') {
            if ($claim = static::claimPrecompute($run, $token)) {
                return $claim;
            }
        }
        if ($claim = static::claimScope($run, $token, $lane)) {
            return $claim;
        }

        return null;
    }

    /** Anything claimable right now? (The pump's worker-seeding gate.) */
    public static function workAvailable(AutoscaleRun $run): bool
    {
        $pendingItems = DB::table('autoscale_items')
            ->where('run_id', $run->id)
            ->where('status', 'pending')
            ->exists();
        if ($pendingItems) {
            return true;
        }

        if (DB::table('autoscale_scopes')->where('run_id', $run->id)->where('status', 'pending')->exists()) {
            return true;
        }

        if (static::precomputeEnabled()
            && DB::table('jurisdiction_adjacency_parents')->where('status', 'pending')->exists()) {
            return true;
        }

        // Items whose scopes all closed but that never reached assessment
        // (crash between scope-done and finalize) — the pump reopens these;
        // meanwhile they still count as work.
        return DB::table('autoscale_items')
            ->where('run_id', $run->id)
            ->whereIn('status', ['running', 'assessing'])
            ->exists();
    }

    public static function precomputeEnabled(): bool
    {
        return config('cga.autoscale_precompute', 'upfront') !== 'lazy';
    }

    private static function claimSingles(AutoscaleRun $run, string $token): ?array
    {
        // Cheap existence probe first — after the singles phase drains this
        // rung must cost one indexed lookup, not a claim attempt.
        $hasPending = DB::table('autoscale_items')
            ->where('run_id', $run->id)
            ->where('kind', 'single')
            ->where('status', 'pending')
            ->exists();
        if (! $hasPending) {
            return null;
        }

        $cap = (int) config('cga.autoscale_singles_workers', 4);
        $active = (int) DB::table('autoscale_items')
            ->where('run_id', $run->id)
            ->where('kind', 'single')
            ->where('status', 'running')
            ->distinct()
            ->count('claim_token');
        if ($active >= $cap) {
            return null;
        }

        $claimed = DB::select('
            UPDATE autoscale_items
               SET status = ?, claim_token = ?,
                   started_at = COALESCE(started_at, now()), updated_at = now()
             WHERE id IN (
                   SELECT id FROM autoscale_items
                    WHERE run_id = ? AND kind = ? AND status = ?
                    ORDER BY position
                    LIMIT ?
                    FOR UPDATE SKIP LOCKED
             )
               AND status = ?
         RETURNING id
        ', ['running', $token, $run->id, 'single', 'pending', self::SINGLES_BATCH, 'pending']);

        if ($claimed === []) {
            return null;
        }

        return ['type' => 'singles', 'count' => count($claimed)];
    }

    private static function claimFinalize(AutoscaleRun $run, string $token): ?array
    {
        $row = DB::selectOne("
            UPDATE autoscale_items ai
               SET status = 'assessing', claim_token = ?, updated_at = now()
             WHERE ai.id = (
                   SELECT i.id FROM autoscale_items i
                    WHERE i.run_id = ? AND i.kind = 'sweep' AND i.status = 'running'
                      AND EXISTS (SELECT 1 FROM autoscale_scopes s
                                   WHERE s.item_id = i.id)
                      AND NOT EXISTS (SELECT 1 FROM autoscale_scopes s
                                       WHERE s.item_id = i.id
                                         AND s.status IN ('pending', 'running'))
                    ORDER BY i.position
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED
             )
         RETURNING ai.id
        ", [$token, $run->id]);

        if ($row === null) {
            return null;
        }

        return ['type' => 'finalize', 'item_id' => (string) $row->id];
    }

    private static function claimPrecompute(AutoscaleRun $run, string $token): ?array
    {
        if (! static::precomputeEnabled()) {
            return null;
        }

        $row = DB::selectOne('
            UPDATE jurisdiction_adjacency_parents
               SET status = ?, claim_token = ?, updated_at = now()
             WHERE parent_id = (
                   SELECT parent_id FROM jurisdiction_adjacency_parents
                    WHERE status = ?
                    ORDER BY child_count DESC, adm_level ASC, parent_id
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED
             )
         RETURNING parent_id
        ', ['running', $token, 'pending']);

        if ($row === null) {
            return null;
        }

        return ['type' => 'precompute', 'parent_id' => (string) $row->parent_id];
    }

    private static function claimScope(AutoscaleRun $run, string $token, string $lane = 'auto'): ?array
    {
        // Sweeps wait for the precompute pass (their Step 7 then reads the
        // table instead of paying ST_Intersection live). Under =lazy the gate
        // is open and the write-back fallback fills the table as sweeps run.
        if (static::precomputeEnabled()) {
            $precomputeOpen = DB::table('jurisdiction_adjacency_parents')
                ->whereIn('status', ['pending', 'running'])
                ->exists();
            if ($precomputeOpen) {
                return null;
            }
        }

        // Heavy-lane gate, HARD since 2026-07-22: the soft cap overshot to 5
        // under OOM-kill/reclaim churn (claim races + orphaned claims), and
        // 3+ concurrent heavies is exactly the memory regime that produces
        // the kills. The eligibility decision and the claim now run inside
        // one transaction serialized by an advisory xact lock — two workers
        // can never both see a free heavy slot. The heavy count ignores
        // claims idle >30 min (orphans of killed workers — the reclaim
        // bound), so a kill can't wedge the lane shut. Serializing ALL
        // scope claims is fine: a claim is milliseconds against seconds-to-
        // minutes of scope work.
        $row = DB::transaction(function () use ($run, $token, $lane) {
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('cga_heavy_claim'))");

            // Tier resolution is SCOPE-first (2026-07-22, the Earth-swarm
            // crash): a geometry-less tier-1 item cascades into continental
            // sub-scopes — the scope's own stamped tier is the real weight;
            // the item tier is the fallback for pre-migration rows.
            $allowHeavy = true;
            $lightPending = DB::table('autoscale_scopes AS s')
                ->join('autoscale_items AS ai', 'ai.id', '=', 's.item_id')
                ->where('s.run_id', $run->id)
                ->where('s.status', 'pending')
                ->whereRaw('COALESCE(s.area_tier, ai.area_tier, 1) < ?', [self::HEAVY_TIER])
                ->exists();
            if ($lightPending) {
                $heavyRunning = (int) DB::table('autoscale_scopes AS s')
                    ->join('autoscale_items AS ai', 'ai.id', '=', 's.item_id')
                    ->where('s.run_id', $run->id)
                    ->where('s.status', 'running')
                    ->where('s.updated_at', '>', now()->subMinutes(30))
                    ->whereRaw('COALESCE(s.area_tier, ai.area_tier, 1) >= ?', [self::HEAVY_TIER])
                    ->count();
                $allowHeavy = $heavyRunning < self::heavyWorkerCap();
            }

            $heavyPredicate = $allowHeavy ? 'true' : 'false';
            $order = $lane === 'topdown'
                ? 'ai.position DESC, s2.depth, s2.id'
                : 'ai.position, s2.depth, s2.id';

            return DB::selectOne("
                UPDATE autoscale_scopes s
                   SET status = ?, claim_token = ?,
                       started_at = COALESCE(s.started_at, now()), updated_at = now()
                 WHERE s.id = (
                       SELECT s2.id FROM autoscale_scopes s2
                        JOIN autoscale_items ai ON ai.id = s2.item_id
                       WHERE s2.run_id = ? AND s2.status = ?
                         AND (COALESCE(s2.area_tier, ai.area_tier, 1) < ? OR {$heavyPredicate})
                       ORDER BY {$order}
                       LIMIT 1
                       FOR UPDATE SKIP LOCKED
                 )
             RETURNING s.id, s.item_id, s.legislature_id, s.scope_jurisdiction_id, s.depth
            ", ['running', $token, $run->id, 'pending', self::HEAVY_TIER]);
        });

        if ($row === null) {
            return null;
        }

        return [
            'type'                  => 'scope',
            'scope_id'              => (string) $row->id,
            'item_id'               => (string) $row->item_id,
            'legislature_id'        => (string) $row->legislature_id,
            'scope_jurisdiction_id' => (string) $row->scope_jurisdiction_id,
            'depth'                 => (int) $row->depth,
        ];
    }
}
