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

        // MEMORY-DERIVED (operator, 2026-08-29: everything derives). A
        // heavy scope's postgres transients cost ~1.5-3 GB at peak, and
        // the 20%-of-lanes rule alone would let a big pool overrun a small
        // box. The cap is whichever is tighter: 20% of the lanes, or the
        // host's memory beyond a 4 GB base at ~2 GB per concurrent giant.
        // 8 GB reference box → 2 (today's proven number); 256 GB cloud
        // box → lane-bound, so the Earth-class tail parallelizes.
        $memCap = (int) floor(max(0.0, HostCapacity::hostMemoryGb() - 3.0) / 2.0);

        return max(1, min(
            (int) ceil(0.2 * HostCapacity::autoscaleWorkers()),
            max(1, $memCap),
        ));
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
    /**
     * THE BLOCK GATE (operator ruling 2026-08-31, spoken table): a claim
     * serves only the LOWEST unfinished block_rank — planet, then each
     * layer's composites, then that layer's leaves. 'running' counts as
     * unfinished so a block holds until its last map lands. Items without
     * a stamp (legacy runs) pass the gate unchanged.
     *
     * @param string $alias the autoscale_items alias in the calling query
     */
    private static function blockGateSql(string $alias): string
    {
        return "({$alias}.block_rank IS NULL OR {$alias}.block_rank = (
                    SELECT MIN(x.block_rank) FROM autoscale_items x
                     WHERE x.run_id = {$alias}.run_id
                       AND x.status IN ('pending', 'running')
                       AND x.block_rank IS NOT NULL))";
    }

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
        // DEMAND PRIORITY (operator order 2026-08-30, world-entry-early):
        // a stamped item — someone is LOOKING at that legislature — jumps
        // the whole pile. The probe is an indexed EXISTS (partial index on
        // priority_at), so the fast batch path pays ~nothing when nobody
        // is waiting; claimScope's ORDER serves stamped items first.
        if (static::priorityPending($run)) {
            if ($claim = static::claimScope($run, $token, $lane, true)) {
                return $claim;
            }
        }
        // THE TWO-CUTTER BATCH (operator order 2026-08-29, the 4-day law):
        // 364k lawful 2-3 district leaf splits each cost more to CLAIM (a
        // planet-join) than to DRAW. Bottom-up lanes take them 100 at a
        // time — order within the class is irrelevant (they are peers), so
        // the claim skips the position sort entirely and runs in
        // milliseconds. The drawing path per scope is untouched.
        if ($lane !== 'topdown') {
            if ($claim = static::claimScopeBatch($run, $token)) {
                return $claim;
            }
        }
        if ($claim = static::claimScope($run, $token, $lane)) {
            return $claim;
        }

        // LANES FLOW TO THE SURVIVOR (lane law #2, applied 2026-08-30, the
        // corpse-claims blackout): a top-down lane whose whole top is
        // heavy-capped — including slots held by dead claims until the
        // heavy margin clears them — must not die idle while light work
        // remains. It flows to the bottom-up side for THIS claim and tries
        // the top again on its next one, so giant pickup resumes the moment
        // a heavy slot frees. Without this, reseeded top-down workers
        // claimed nothing, exited without a lease, and the pump reseeded
        // them into the same wall every minute (13 lanes bled to 10).
        if ($lane === 'topdown') {
            if ($claim = static::claimScopeBatch($run, $token)) {
                return $claim;
            }
            if ($claim = static::claimSingles($run, $token)) {
                return $claim;
            }
            if ($claim = static::claimPrecompute($run, $token)) {
                return $claim;
            }
        }

        return null;
    }

    /** Any demanded (priority-stamped) item still holding a pending scope? */
    public static function priorityPending(AutoscaleRun $run): bool
    {
        return DB::table('autoscale_scopes AS s')
            ->join('autoscale_items AS ai', 'ai.id', '=', 's.item_id')
            ->where('s.run_id', $run->id)
            ->where('s.status', 'pending')
            ->whereNotNull('ai.priority_at')
            ->exists();
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

    /**
     * CLAIMABLE work only (operator catch 2026-08-30, the tail churn): a
     * lane's self-respawn asks this, not workAvailable — an in-flight item
     * with zero pending scopes is work for the PUMP's bookkeeping, but a
     * fresh lane spawned against it finds nothing, exits, and respawns
     * another, spinning until the finalize lands. Respawn only when a
     * claim could actually succeed.
     */
    public static function claimableWork(AutoscaleRun $run): bool
    {
        if (DB::table('autoscale_items')->where('run_id', $run->id)->where('status', 'pending')->exists()) {
            return true;
        }
        if (DB::table('autoscale_scopes')->where('run_id', $run->id)->where('status', 'pending')->exists()) {
            return true;
        }

        return static::precomputeEnabled()
            && DB::table('jurisdiction_adjacency_parents')->where('status', 'pending')->exists();
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
                   SELECT ai.id FROM autoscale_items ai
                    WHERE ai.run_id = ? AND ai.kind = ? AND ai.status = ?
                      AND ' . self::blockGateSql('ai') . '
                    ORDER BY ai.block_order ASC NULLS LAST, ai.position
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

    private static function claimScope(AutoscaleRun $run, string $token, string $lane = 'auto', bool $priorityOnly = false): ?array
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
        $row = DB::transaction(function () use ($run, $token, $lane, $priorityOnly) {
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
            // DEMAND PRIORITY runs as its OWN tiny query ($priorityOnly),
            // never as an ORDER prefix on the main claim: putting
            // priority_at first in this ORDER BY defeated the position
            // index and made every claim sort the whole pending pile —
            // seconds per claim, seven lanes reading idle (caught live on
            // the dashboard, 2026-08-30 02:40). The priority path filters
            // to stamped items (partial index, a handful of rows); the
            // normal path keeps the index-friendly position order.
            $priorityPredicate = $priorityOnly ? 'ai.priority_at IS NOT NULL' : 'true';
            // THE STEPPER ORDER (operator order 2026-08-30, benchmark 3):
            // stamped walk_position leads every lane's order — post-order,
            // largest-first, root last, identical to the UI wizard. Unstamped
            // rows (legacy incremental materialization) sort after and keep
            // their old keys.
            // THE MEET-IN-THE-MIDDLE ORDER (operator order 2026-08-31):
            // 'bottomup' lanes read reverse_position — deepest layer first,
            // lowest population first — so they fly through the trivial mass
            // while the other half grinds the giants; the directions meet in
            // the middle and SKIP LOCKED gives the tie to whichever side
            // claims first (top-down, at the meeting point, by arrival).
            // THE BLOCK ORDER (operator ruling 2026-08-31): every lane runs
            // ONE direction — the current block's maps by block_order
            // (composites biggest-first, leaves smallest-first), the stamped
            // walk within each map. Legacy runs (block_order NULL) fall
            // through to the old position keys unchanged.
            $order = $priorityOnly
                ? 's2.walk_position ASC NULLS LAST, ai.priority_at ASC, s2.depth, s2.id'
                : match ($lane) {
                    'bottomup' => 's2.reverse_position ASC NULLS LAST, s2.walk_position DESC, s2.depth, s2.id',
                    'topdown'  => 'ai.block_order ASC NULLS LAST, s2.walk_position ASC NULLS LAST, ai.position DESC, s2.depth, s2.id',
                    default    => 'ai.block_order ASC NULLS LAST, s2.walk_position ASC NULLS LAST, ai.position, s2.depth, s2.id',
                };

            return DB::selectOne("
                UPDATE autoscale_scopes s
                   SET status = ?, claim_token = ?,
                       started_at = COALESCE(s.started_at, now()), updated_at = now()
                 WHERE s.id = (
                       SELECT s2.id FROM autoscale_scopes s2
                        JOIN autoscale_items ai ON ai.id = s2.item_id
                       WHERE s2.run_id = ? AND s2.status = ?
                         AND {$priorityPredicate}
                         AND " . self::blockGateSql('ai') . "
                         AND (COALESCE(s2.area_tier, ai.area_tier, 1) < ? OR {$heavyPredicate})
                       ORDER BY {$order}
                       LIMIT 1
                       -- OF s2 (2026-08-30, the single-item Earth stall):
                       -- locking the joined ITEM row too made every claim
                       -- skip ALL of one item's scopes whenever any worker
                       -- held that item row — a one-legislature run
                       -- collapsed to width 2. The claim mutates scopes
                       -- only; lock scopes only.
                       FOR UPDATE OF s2 SKIP LOCKED
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

    /** Claim up to 100 light childless small-split scopes in one shot. */
    private static function claimScopeBatch(AutoscaleRun $run, string $token): ?array
    {
        $rows = DB::select("
            UPDATE autoscale_scopes s
               SET status = 'running', claim_token = ?,
                   started_at = COALESCE(s.started_at, now()), updated_at = now()
             WHERE s.id IN (
                   SELECT s2.id FROM autoscale_scopes s2
                    JOIN autoscale_items ai ON ai.id = s2.item_id
                   WHERE s2.run_id = ? AND s2.status = 'pending'
                     AND ai.child_count = 0
                     AND COALESCE(ai.est_districts, 99) <= 3
                     AND " . self::blockGateSql('ai') . "
                     AND COALESCE(s2.area_tier, ai.area_tier, 1) < ?
                   ORDER BY ai.block_order ASC NULLS LAST, s2.id
                   LIMIT 100
                   FOR UPDATE OF s2 SKIP LOCKED
             )
         RETURNING s.id, s.item_id, s.legislature_id, s.scope_jurisdiction_id, s.depth
        ", [$token, $run->id, self::HEAVY_TIER]);

        if ($rows === []) {
            return null;
        }

        return [
            'type'   => 'scope_batch',
            'scopes' => array_map(static fn ($r) => [
                'type'                  => 'scope',
                'scope_id'              => (string) $r->id,
                'item_id'               => (string) $r->item_id,
                'legislature_id'        => (string) $r->legislature_id,
                'scope_jurisdiction_id' => (string) $r->scope_jurisdiction_id,
                'depth'                 => (int) $r->depth,
            ], $rows),
        ];
    }
}
