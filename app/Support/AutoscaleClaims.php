<?php

namespace App\Support;

use App\Models\AutoscaleRun;
use Illuminate\Support\Facades\DB;

/**
 * The pull engine's claim ladder over THE LEDGER SINGLE HOME (operator
 * ruling 2026-08-31): headers (apportionment_ledger) carry each map's
 * facts + map_status; scopes (apportionment_ledger_scopes) carry the
 * drawing units + their work state. One world, one work-list — run_id
 * lives only on the phase/clock record (autoscale_runs).
 *
 * Workers call next() in a loop; each rung is ONE atomic
 * UPDATE … RETURNING with FOR UPDATE SKIP LOCKED, so any number of workers
 * partition the work-list without an orchestrator dispatching anything.
 * First rung with work wins:
 *
 *   1. singles batches  — 15k leaf-council headers per claim, ≤4 concurrent
 *                         claimants;
 *   2. finalize         — a sweep header whose scopes have ALL closed flips
 *                         running→assessing atomically; the winner runs the
 *                         completeness assessment + activation;
 *   3. precompute       — one adjacency parent per claim, heaviest first;
 *   4. sweep scopes     — THE BLOCK ORDER: lowest unfinished block only,
 *                         composites biggest-first / leaves smallest-first,
 *                         the stamped walk within each map.
 */
final class AutoscaleClaims
{
    public const SINGLES_BATCH = 15000;

    /**
     * THE HEAVY LANE (operator ruling 2026-07-21): area_tier ≥ 4 (bbox above
     * ~30,000 km² — the 0.02°/0.05° grid-ladder class whose single grid
     * query runs tens of minutes). At most 20% of worker threads may hold
     * heavy scopes at once, so light work keeps flying and a consecutive
     * giant block can never capture the whole pool. THE DRAIN RULE: when no
     * light work remains pending, the cap lifts.
     */
    public const HEAVY_TIER = 4;

    public static function heavyWorkerCap(): int
    {
        $override = (int) config('cga.autoscale_heavy_cap', 0);
        if ($override > 0) {
            return $override;
        }

        // MEMORY-DERIVED (operator, 2026-08-29: everything derives). A
        // heavy scope's postgres transients cost ~1.5-3 GB at peak; the cap
        // is whichever is tighter: 20% of the lanes, or the host's memory
        // beyond a base at ~2 GB per concurrent giant.
        $memCap = (int) floor(max(0.0, HostCapacity::hostMemoryGb() - 3.0) / 2.0);

        return max(1, min(
            (int) ceil(0.2 * HostCapacity::autoscaleWorkers()),
            max(1, $memCap),
        ));
    }

    /**
     * THE BLOCK ORDER IS A PRIORITY, NEVER A LOCKOUT (operator ruling
     * 2026-08-31, second ruling: "all 13 lanes should be going at all
     * times"): every claim ORDERS by block_rank first — the lowest block's
     * work always wins — and when the current block cannot feed a lane,
     * the lane spills forward into the next block instead of idling.
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
        // THE LADDER IS BLOCK-AWARE (operator ruling 2026-08-31, benchmark
        // 14's out-of-order evidence): rung TYPE never outranks THE BLOCK
        // ORDER. Finalize and precompute lead (cheap, order-neutral); then
        // the demand-priority jump; then the block decides WHICH kind of
        // work comes next — singles claim when their lowest block precedes
        // the scopes' lowest block, scopes otherwise — and the batch and
        // singles rungs run as fallthroughs so a lane never idles.
        if ($claim = static::claimFinalize($run, $token)) {
            return $claim;
        }
        if ($claim = static::claimPrecompute($run, $token)) {
            return $claim;
        }
        // DEMAND PRIORITY (operator order 2026-08-30, world-entry-early):
        // a stamped header — someone is LOOKING at that legislature — jumps
        // the pile. The probe is an indexed EXISTS.
        if (static::priorityPending($run)) {
            if ($claim = static::claimScope($run, $token, $lane, true)) {
                return $claim;
            }
        }

        $singlesBlock = DB::scalar("
            SELECT MIN(block_rank) FROM apportionment_ledger
             WHERE kind = 'single' AND map_status = 'pending' AND block_rank IS NOT NULL
        ");
        // HEADER-ONLY, INDEX-SHAPED (operator catch 2026-08-31, the
        // between-claims dead time): the block question is answered by the
        // partial header index in microseconds — never by joining the
        // planet's pending scope pile.
        $scopeBlock = DB::scalar("
            SELECT MIN(block_rank) FROM apportionment_ledger
             WHERE map_status IN ('pending', 'running')
               AND kind = 'sweep' AND block_rank IS NOT NULL
        ");

        if ($singlesBlock !== null && ($scopeBlock === null || (int) $singlesBlock < (int) $scopeBlock)) {
            if ($claim = static::claimSingles($run, $token, (int) $singlesBlock)) {
                return $claim;
            }
        }
        if ($claim = static::claimScope($run, $token, $lane)) {
            return $claim;
        }
        // THE TWO-CUTTER BATCH (operator order 2026-08-29, the 4-day law):
        // the trivial-split leaf mass claims 100 at a time — as the
        // fallthrough behind the ordered scope claim, never ahead of it.
        if ($claim = static::claimScopeBatch($run, $token)) {
            return $claim;
        }
        // LANES NEVER IDLE (lane law): singles from their lowest block as
        // the final fallthrough.
        if ($claim = static::claimSingles($run, $token, $singlesBlock !== null ? (int) $singlesBlock : null)) {
            return $claim;
        }

        return null;
    }

    /** Any demanded (priority-stamped) map still holding a pending scope? */
    public static function priorityPending(AutoscaleRun $run): bool
    {
        return DB::table('apportionment_ledger_scopes AS s')
            ->join('apportionment_ledger AS h', 'h.legislature_id', '=', 's.legislature_id')
            ->where('s.status', 'pending')
            ->whereNotNull('h.priority_at')
            ->exists();
    }

    /** Anything claimable right now? (The pump's worker-seeding gate.) */
    public static function workAvailable(AutoscaleRun $run): bool
    {
        if (DB::table('apportionment_ledger')->where('map_status', 'pending')->exists()) {
            return true;
        }

        if (DB::table('apportionment_ledger_scopes')->where('status', 'pending')->exists()) {
            return true;
        }

        if (static::precomputeEnabled()
            && DB::table('jurisdiction_adjacency_parents')->where('status', 'pending')->exists()) {
            return true;
        }

        // Maps whose scopes all closed but that never reached assessment
        // (crash between scope-done and finalize) — still work.
        return DB::table('apportionment_ledger')
            ->whereIn('map_status', ['running', 'assessing'])
            ->exists();
    }

    /**
     * CLAIMABLE work only (operator catch 2026-08-30, the tail churn): a
     * lane's self-respawn asks this, not workAvailable.
     */
    public static function claimableWork(AutoscaleRun $run): bool
    {
        if (DB::table('apportionment_ledger')->where('map_status', 'pending')->exists()) {
            return true;
        }
        if (DB::table('apportionment_ledger_scopes')->where('status', 'pending')->exists()) {
            return true;
        }

        return static::precomputeEnabled()
            && DB::table('jurisdiction_adjacency_parents')->where('status', 'pending')->exists();
    }

    public static function precomputeEnabled(): bool
    {
        return config('cga.autoscale_precompute', 'upfront') !== 'lazy';
    }

    private static function claimSingles(AutoscaleRun $run, string $token, ?int $blockRank = null): ?array
    {
        $hasPending = DB::table('apportionment_ledger')
            ->where('kind', 'single')
            ->where('map_status', 'pending')
            ->exists();
        if (! $hasPending) {
            return null;
        }

        $cap = (int) config('cga.autoscale_singles_workers', 4);
        $active = (int) DB::table('apportionment_ledger')
            ->where('kind', 'single')
            ->where('map_status', 'running')
            ->distinct()
            ->count('claim_token');
        if ($active >= $cap) {
            return null;
        }

        // ONE BLOCK PER BATCH (operator ruling 2026-08-31): a batch never
        // straddles a block boundary — the 15k bite eats its own block's
        // singles only, so deep-layer leaves cannot ride an early batch.
        $blockPredicate = $blockRank !== null ? 'h.block_rank = '.((int) $blockRank) : 'true';
        $claimed = DB::select("
            UPDATE apportionment_ledger
               SET map_status = ?, claim_token = ?,
                   started_at = COALESCE(started_at, now()), updated_at = now()
             WHERE legislature_id IN (
                   SELECT h.legislature_id FROM apportionment_ledger h
                    WHERE h.kind = ? AND h.map_status = ?
                      AND {$blockPredicate}
                    ORDER BY h.position ASC NULLS LAST
                    LIMIT ?
                    FOR UPDATE SKIP LOCKED
             )
               AND map_status = ?
         RETURNING legislature_id
        ", ['running', $token, 'single', 'pending', self::SINGLES_BATCH, 'pending']);

        if ($claimed === []) {
            return null;
        }

        return ['type' => 'singles', 'count' => count($claimed)];
    }

    private static function claimFinalize(AutoscaleRun $run, string $token): ?array
    {
        $row = DB::selectOne("
            UPDATE apportionment_ledger h
               SET map_status = 'assessing', claim_token = ?, updated_at = now()
             WHERE h.legislature_id = (
                   SELECT i.legislature_id FROM apportionment_ledger i
                    WHERE i.kind = 'sweep' AND i.map_status = 'running'
                      AND EXISTS (SELECT 1 FROM apportionment_ledger_scopes s
                                   WHERE s.legislature_id = i.legislature_id)
                      AND NOT EXISTS (SELECT 1 FROM apportionment_ledger_scopes s
                                       WHERE s.legislature_id = i.legislature_id
                                         AND s.status IN ('pending', 'running'))
                    ORDER BY i.position
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED
             )
         RETURNING h.legislature_id
        ", [$token]);

        if ($row === null) {
            return null;
        }

        return ['type' => 'finalize', 'legislature_id' => (string) $row->legislature_id];
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
        // table instead of paying ST_Intersection live).
        if (static::precomputeEnabled()) {
            $precomputeOpen = DB::table('jurisdiction_adjacency_parents')
                ->whereIn('status', ['pending', 'running'])
                ->exists();
            if ($precomputeOpen) {
                return null;
            }
        }

        // Heavy-lane gate, HARD since 2026-07-22: eligibility decision and
        // claim run inside one transaction serialized by an advisory xact
        // lock — two workers can never both see a free heavy slot. The
        // heavy count ignores claims idle >30 min (orphans of killed
        // workers), so a kill can't wedge the lane shut.
        $row = DB::transaction(function () use ($token, $lane, $priorityOnly) {
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('cga_heavy_claim'))");

            $allowHeavy = true;
            // BLOCK-SCOPED DRAIN RULE (2026-08-31): the cap lifts when the
            // CURRENT BLOCK holds no light work — a later block's light must
            // not keep lanes idle in a heavy-only block.
            $lightPending = DB::table('apportionment_ledger_scopes AS s')
                ->join('apportionment_ledger AS h', 'h.legislature_id', '=', 's.legislature_id')
                ->where('s.status', 'pending')
                ->whereRaw('COALESCE(s.area_tier, h.area_tier, 1) < ?', [self::HEAVY_TIER])
                ->exists();
            if ($lightPending) {
                $heavyRunning = (int) DB::table('apportionment_ledger_scopes AS s')
                    ->join('apportionment_ledger AS h', 'h.legislature_id', '=', 's.legislature_id')
                    ->where('s.status', 'running')
                    ->where('s.updated_at', '>', now()->subMinutes(30))
                    ->whereRaw('COALESCE(s.area_tier, h.area_tier, 1) >= ?', [self::HEAVY_TIER])
                    ->count();
                $allowHeavy = $heavyRunning < self::heavyWorkerCap();
            }

            $heavyPredicate = $allowHeavy ? 'true' : 'false';
            $priorityPredicate = $priorityOnly ? 'h.priority_at IS NOT NULL' : 'true';
            // THE DISPATCH POP (operator confirmation 2026-08-31): the
            // stamped GLOBAL walk_position is the whole benchmark's
            // sequence — block order, within-block order, and spill in one
            // number. The (status, walk_position) index serves its leading
            // edge; a claim is an index descent, never a sort.
            $order = $priorityOnly
                ? 'h.priority_at ASC, s2.walk_position ASC NULLS LAST, s2.id'
                : ($lane === 'bottomup'
                    ? 's2.reverse_position ASC NULLS LAST, s2.walk_position DESC, s2.id'
                    : 's2.walk_position ASC NULLS LAST, s2.id');

            return DB::selectOne("
                UPDATE apportionment_ledger_scopes s
                   SET status = ?, claim_token = ?,
                       started_at = COALESCE(s.started_at, now()), updated_at = now()
                 WHERE s.id = (
                       SELECT s2.id FROM apportionment_ledger_scopes s2
                        JOIN apportionment_ledger h ON h.legislature_id = s2.legislature_id
                       WHERE s2.status = ?
                         AND {$priorityPredicate}
                         AND (COALESCE(s2.area_tier, h.area_tier, 1) < ? OR {$heavyPredicate})
                       ORDER BY {$order}
                       LIMIT 1
                       -- OF s2 (2026-08-30, the single-item Earth stall):
                       -- lock scopes only; never the joined header row.
                       FOR UPDATE OF s2 SKIP LOCKED
                 )
             RETURNING s.id, s.legislature_id, s.scope_jurisdiction_id, s.depth
            ", ['running', $token, 'pending', self::HEAVY_TIER]);
        });

        if ($row === null) {
            return null;
        }

        return [
            'type'                  => 'scope',
            'scope_id'              => (string) $row->id,
            'legislature_id'        => (string) $row->legislature_id,
            'scope_jurisdiction_id' => (string) $row->scope_jurisdiction_id,
            'depth'                 => (int) $row->depth,
        ];
    }

    /** Claim up to 100 light childless small-split scopes in one shot. */
    private static function claimScopeBatch(AutoscaleRun $run, string $token): ?array
    {
        // The dispatch pop, batch edition: 100 consecutive tickets.
        $rows = DB::select("
            UPDATE apportionment_ledger_scopes s
               SET status = 'running', claim_token = ?,
                   started_at = COALESCE(s.started_at, now()), updated_at = now()
             WHERE s.id IN (
                   SELECT s2.id FROM apportionment_ledger_scopes s2
                    JOIN apportionment_ledger h ON h.legislature_id = s2.legislature_id
                   WHERE s2.status = 'pending'
                     AND h.child_count = 0
                     AND COALESCE(h.est_districts, 99) <= 3
                     AND COALESCE(s2.area_tier, h.area_tier, 1) < ?
                   ORDER BY s2.walk_position ASC NULLS LAST, s2.id
                   LIMIT 100
                   FOR UPDATE OF s2 SKIP LOCKED
             )
         RETURNING s.id, s.legislature_id, s.scope_jurisdiction_id, s.depth
        ", [$token, self::HEAVY_TIER]);

        if ($rows === []) {
            return null;
        }

        return [
            'type'   => 'scope_batch',
            'scopes' => array_map(static fn ($r) => [
                'type'                  => 'scope',
                'scope_id'              => (string) $r->id,
                'legislature_id'        => (string) $r->legislature_id,
                'scope_jurisdiction_id' => (string) $r->scope_jurisdiction_id,
                'depth'                 => (int) $r->depth,
            ], $rows),
        ];
    }
}
