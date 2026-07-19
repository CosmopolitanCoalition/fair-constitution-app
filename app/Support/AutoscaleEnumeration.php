<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Shared enumeration-key derivation (cycle-2 R2, operator ruling 2026-07-19)
 * — called by BOTH AutoscaleSizingJob (fresh enumeration) and
 * AutoscaleRevertCommand (re-derivation), so the two can never drift.
 *
 * THE WORK ORDER — simplest calculation first, so the run front-loads the
 * bulk and the remaining ETA honestly means "everything left is this
 * complex or more":
 *
 *   1. est_districts ASC   — ceil(type_a / ceiling): one-district
 *                            legislatures (floor→ceiling) first, then 2, 3…
 *   2. cascade_height ASC  — subtree height: leaves (0) before
 *                            parents-of-leaves (1) before deeper cascades
 *   3. adm_level DESC      — deepest layers first within a class
 *   4. population ASC      — smallest first
 */
final class AutoscaleEnumeration
{
    /**
     * Derive est_districts + cascade_height + position for every item of a
     * run. Set-based; idempotent; safe to re-run.
     */
    public static function deriveOrderingKeys(string $runId, int $ceiling): void
    {
        // Planet-wide joins (the height loop, the position ROW_NUMBER) must
        // not recruit parallel workers: their DSM segments exceed Docker's
        // default 64 MB /dev/shm. Serial is fine for these set-based
        // passes; reset before returning so the session's later work (the
        // sweeps) keeps its normal planner freedom.
        DB::statement('SET max_parallel_workers_per_gather = 0');

        // est_districts from the CURRENT lawful size.
        DB::statement('
            UPDATE autoscale_items ai
               SET est_districts = CEIL(l.type_a_seats::numeric / ?)::smallint
              FROM legislatures l
             WHERE l.id = ai.legislature_id AND ai.run_id = ?
        ', [max($ceiling, 1), $runId]);

        // cascade_height: leaves 0, then iterative passes — a parent's height
        // resolves once ALL its children's heights are known (bool_and).
        DB::statement("
            UPDATE autoscale_items SET cascade_height = NULL WHERE run_id = ?
        ", [$runId]);
        DB::statement("
            UPDATE autoscale_items SET cascade_height = 0
             WHERE run_id = ? AND child_count = 0
        ", [$runId]);
        for ($pass = 0; $pass < 12; $pass++) {
            $updated = DB::update('
                UPDATE autoscale_items p
                   SET cascade_height = x.h
                  FROM (
                        SELECT p2.id, (1 + MAX(ci.cascade_height))::smallint AS h
                          FROM autoscale_items p2
                          JOIN jurisdictions c
                                 ON c.parent_id = p2.jurisdiction_id AND c.deleted_at IS NULL
                          LEFT JOIN autoscale_items ci
                                 ON ci.run_id = p2.run_id AND ci.jurisdiction_id = c.id
                         WHERE p2.run_id = ? AND p2.cascade_height IS NULL
                         GROUP BY p2.id
                        HAVING bool_and(ci.cascade_height IS NOT NULL)
                  ) x
                 WHERE p.id = x.id
            ', [$runId]);
            if ($updated === 0) {
                break;
            }
        }
        // Safety valve: a child jurisdiction without an item row (out-of-scope
        // adm level, data quirk) leaves its ancestors NULL — backfill high so
        // they sort last, and log the honest count.
        $orphans = DB::update("
            UPDATE autoscale_items SET cascade_height = 99
             WHERE run_id = ? AND cascade_height IS NULL
        ", [$runId]);
        if ($orphans > 0) {
            Log::warning('AutoscaleEnumeration: cascade_height unresolved for some items (sorted last)', [
                'run_id' => $runId, 'count' => $orphans,
            ]);
        }

        // Position: the operator's simplest-first key.
        DB::statement('
            WITH ranked AS (
                SELECT ai.id,
                       ROW_NUMBER() OVER (
                           ORDER BY ai.est_districts ASC, ai.cascade_height ASC,
                                    ai.adm_level DESC, j.population ASC NULLS FIRST, ai.id
                       ) AS rn
                  FROM autoscale_items ai
                  JOIN jurisdictions j ON j.id = ai.jurisdiction_id
                 WHERE ai.run_id = ?
            )
            UPDATE autoscale_items ai
               SET position = r.rn
              FROM ranked r
             WHERE ai.id = r.id
        ', [$runId]);

        DB::statement('RESET max_parallel_workers_per_gather');
    }
}
