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
 *   3. area_tier ASC       — the pixelGrid ladder's area buckets (operator
 *                            ruling 2026-07-21): the TRUE cost driver is
 *                            geometry size, not admin depth. adm DESC alone
 *                            let every est band end in a consecutive block
 *                            of shallow-admin giants that captured all
 *                            workers at once (the est-2 tail collapse).
 *   4. adm_level DESC      — deepest layers first within a tier
 *   5. population ASC      — smallest first
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

        // area_tier from the geometry BBOX (header-only — no vertex walk):
        // width × height in km at the bbox's mid-latitude, bucketed on the
        // pixelGrid ladder's own thresholds. The bbox over-estimates for
        // diagonal coastal shapes, which errs HEAVY — safe for both the
        // ordering and the claim cap. Geometry-less items tier 1 (they
        // refuse in milliseconds). Idempotent; cheap enough set-based.
        DB::statement("
            UPDATE autoscale_items ai
               SET area_tier = CASE
                       WHEN j.geom IS NULL THEN 1
                       ELSE CASE
                           WHEN bbox.km2 <= 300      THEN 1
                           WHEN bbox.km2 <= 3000     THEN 2
                           WHEN bbox.km2 <= 30000    THEN 3
                           WHEN bbox.km2 <= 300000   THEN 4
                           ELSE 5
                       END
                   END
              FROM jurisdictions j
              LEFT JOIN LATERAL (
                   SELECT (ST_XMax(j.geom) - ST_XMin(j.geom)) * 111.32
                          * GREATEST(cos(radians((ST_YMin(j.geom) + ST_YMax(j.geom)) / 2)), 0.01)
                          * (ST_YMax(j.geom) - ST_YMin(j.geom)) * 110.57 AS km2
              ) bbox ON true
             WHERE j.id = ai.jurisdiction_id AND ai.run_id = ?
        ", [$runId]);

        // Position: the operator's simplest-first key (cost-aware since the
        // 2026-07-21 ruling — area_tier ahead of the adm proxy).
        DB::statement('
            WITH ranked AS (
                SELECT ai.id,
                       ROW_NUMBER() OVER (
                           ORDER BY ai.est_districts ASC, ai.cascade_height ASC,
                                    COALESCE(ai.area_tier, 1) ASC,
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

    /**
     * THE ETL RULE (operator ruling 2026-07-19): planet-scale writes run as
     * BOUNDED CHUNKS, each its own committed statement — visible progress
     * (a psql count moves while it runs), resumable at any boundary (halt,
     * crash, cancel — the NOT-EXISTS guards make redo clean), and never a
     * single opaque multi-hour transaction. The three enumeration-tail
     * writers below are shared by AutoscaleSizingJob and the revert.
     */
    public const CHUNK = 25000;

    /** Mint draft Founding Maps for open sweep items lacking one. */
    public static function mintFoundingMaps(string $runId, ?callable $progress = null): int
    {
        $total = 0;
        do {
            $n = DB::affectingStatement("
                INSERT INTO legislature_district_maps
                    (id, legislature_id, name, description, status, created_at, updated_at)
                SELECT gen_random_uuid(), x.legislature_id, 'Founding Map',
                       'Auto-generated by full-scale autoscale (True All Scale, 2026-07-18) — mixed autoseed sweep.',
                       'draft', now(), now()
                  FROM (
                        SELECT ai.legislature_id
                          FROM autoscale_items ai
                         WHERE ai.run_id = ? AND ai.kind = 'sweep' AND ai.status = 'pending'
                           AND NOT EXISTS (SELECT 1 FROM legislature_district_maps m
                                            WHERE m.legislature_id = ai.legislature_id
                                              AND m.name = 'Founding Map'
                                              AND m.deleted_at IS NULL)
                         LIMIT " . self::CHUNK . '
                  ) x
            ', [$runId]);
            $total += $n;
            if ($progress !== null && $n > 0) {
                $progress($total);
            }
        } while ($n > 0);

        return $total;
    }

    /** Stamp items.map_id from the newest Founding Map per legislature. */
    public static function stampFoundingMapIds(string $runId, ?callable $progress = null): int
    {
        $total = 0;
        do {
            $n = DB::update("
                UPDATE autoscale_items ai
                   SET map_id = fm.id, updated_at = now()
                  FROM (
                        SELECT DISTINCT ON (legislature_id) id, legislature_id
                          FROM legislature_district_maps
                         WHERE name = 'Founding Map' AND deleted_at IS NULL
                         ORDER BY legislature_id, created_at DESC
                  ) fm
                 WHERE ai.id IN (
                        SELECT ai2.id FROM autoscale_items ai2
                         WHERE ai2.run_id = ? AND ai2.kind = 'sweep' AND ai2.map_id IS NULL
                           AND EXISTS (SELECT 1 FROM legislature_district_maps m
                                        WHERE m.legislature_id = ai2.legislature_id
                                          AND m.name = 'Founding Map'
                                          AND m.deleted_at IS NULL)
                         LIMIT " . self::CHUNK . '
                 )
                   AND fm.legislature_id = ai.legislature_id
            ', [$runId]);
            $total += $n;
            if ($progress !== null && $n > 0) {
                $progress($total);
            }
        } while ($n > 0);

        return $total;
    }

    /** Mint the root scope row for every open sweep item lacking one. */
    public static function mintRootScopes(string $runId, ?callable $progress = null): int
    {
        $total = 0;
        do {
            $n = DB::affectingStatement("
                INSERT INTO autoscale_scopes
                    (id, run_id, item_id, legislature_id, scope_jurisdiction_id,
                     depth, status, created_at, updated_at)
                SELECT gen_random_uuid(), ?, x.id, x.legislature_id, x.jurisdiction_id,
                       0, 'pending', now(), now()
                  FROM (
                        SELECT ai.id, ai.legislature_id, ai.jurisdiction_id
                          FROM autoscale_items ai
                         WHERE ai.run_id = ? AND ai.kind = 'sweep' AND ai.status = 'pending'
                           AND NOT EXISTS (SELECT 1 FROM autoscale_scopes s WHERE s.item_id = ai.id)
                         LIMIT " . self::CHUNK . '
                  ) x
                    ON CONFLICT ON CONSTRAINT autoscale_scopes_scope_uq DO NOTHING
            ', [$runId, $runId]);
            $total += $n;
            if ($progress !== null && $n > 0) {
                $progress($total);
            }
        } while ($n > 0);

        return $total;
    }
}
