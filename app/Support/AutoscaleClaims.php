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
     * @return array{type: 'singles'|'precompute'|'scope', ...}|null
     */
    public static function next(AutoscaleRun $run, string $token): ?array
    {
        if ($claim = static::claimSingles($run, $token)) {
            return $claim;
        }
        if ($claim = static::claimFinalize($run, $token)) {
            return $claim;
        }
        if ($claim = static::claimPrecompute($run, $token)) {
            return $claim;
        }
        if ($claim = static::claimScope($run, $token)) {
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

    private static function claimScope(AutoscaleRun $run, string $token): ?array
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

        $row = DB::selectOne('
            UPDATE autoscale_scopes s
               SET status = ?, claim_token = ?,
                   started_at = COALESCE(s.started_at, now()), updated_at = now()
             WHERE s.id = (
                   SELECT s2.id FROM autoscale_scopes s2
                    JOIN autoscale_items ai ON ai.id = s2.item_id
                   WHERE s2.run_id = ? AND s2.status = ?
                   ORDER BY ai.position, s2.depth, s2.id
                   LIMIT 1
                   FOR UPDATE SKIP LOCKED
             )
         RETURNING s.id, s.item_id, s.legislature_id, s.scope_jurisdiction_id, s.depth
        ', ['running', $token, $run->id, 'pending']);

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
