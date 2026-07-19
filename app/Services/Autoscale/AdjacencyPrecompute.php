<?php

namespace App\Services\Autoscale;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Run-level geometry precompute (pull engine, 2026-07-19).
 *
 * Sibling adjacency + shared-border lengths are stable topology keyed on
 * parent_id, but the sweep engine's Step 7 rederived them live on EVERY
 * sweep — 30–180 s per high-vertex pair, paid 48k times. This pass computes
 * each parent's pair set ONCE into jurisdiction_adjacency (plus the exact
 * tiered ST_Simplify geoms into jurisdiction_simplified and ST_Centroid
 * into jurisdiction_centroids), and DistrictingService reads the tables
 * with an exact-fallback to the live SQL.
 *
 * EXACTNESS CONTRACT (the Draft-4/5 byte-identity property): every
 * expression here mirrors DistrictingService Step 7 verbatim — the same
 * two-tier ST_MakeValid(ST_Simplify()) ladder, the same a.id < b.id pair
 * orientation, ST_Intersection computed once per pair, dim =
 * ST_Dimension(ix), border_len = ST_Length(ST_CollectionExtract(ix, 2)).
 * A table-fed Step 7 must be byte-identical to a live one.
 *
 * The tables are GLOBAL (no run_id): geometry-derived, so they survive
 * every halt→fix→revert→resume iteration and are invalidated only by
 * geometry changes — which the acceptance gate locks out during a run.
 */
class AdjacencyPrecompute
{
    /**
     * Seed the claimable worklist: every live parent of ≥1 geometry-bearing
     * child. Parents with <2 such children are pre-marked done (no pairs to
     * compute — the read path still short-circuits on the done row).
     * Idempotent (ON CONFLICT DO NOTHING keeps prior states).
     */
    public function seedWorklist(): int
    {
        DB::statement("
            INSERT INTO jurisdiction_adjacency_parents
                (parent_id, adm_level, child_count, status, updated_at)
            SELECT p.id, p.adm_level, c.n,
                   CASE WHEN c.n >= 2 THEN 'pending' ELSE 'done' END,
                   now()
              FROM jurisdictions p
              JOIN LATERAL (
                    SELECT COUNT(*) AS n FROM jurisdictions c
                     WHERE c.parent_id = p.id AND c.deleted_at IS NULL
                       AND c.geom IS NOT NULL
              ) c ON c.n >= 1
             WHERE p.deleted_at IS NULL
                ON CONFLICT (parent_id) DO NOTHING
        ");

        return (int) DB::table('jurisdiction_adjacency_parents')
            ->where('status', 'pending')->count();
    }

    /**
     * Compute one parent's full sibling adjacency (ALL geometry-bearing
     * children — giants included, so the table is a superset of any Step-7
     * pool filter). Also fills the simplify cache for this parent's heavy
     * children first (the simplify itself is the expensive part of heavy
     * pairs — Nunavut's tier-1 pass alone is ~55 s, paid once here).
     */
    public function processParent(string $parentId): void
    {
        $t0 = microtime(true);

        try {
            // 1. Simplify cache for heavy children (exact Step-7 tiers).
            DB::statement("
                INSERT INTO jurisdiction_simplified (jurisdiction_id, geom)
                SELECT id,
                       CASE
                           WHEN ST_NPoints(geom) > 1000000
                                THEN ST_MakeValid(ST_Simplify(geom, 0.01))
                           ELSE ST_MakeValid(ST_Simplify(geom, 0.001))
                       END
                  FROM jurisdictions
                 WHERE parent_id = ?
                   AND deleted_at IS NULL
                   AND geom IS NOT NULL
                   AND ST_NPoints(geom) > 50000
                    ON CONFLICT (jurisdiction_id) DO NOTHING
            ", [$parentId]);

            // 2. Centroids (exact Step-1 expression: ST_Centroid, never the
            //    mixed-provenance stored column).
            DB::statement('
                INSERT INTO jurisdiction_centroids (jurisdiction_id, x, y)
                SELECT id, ST_X(ST_Centroid(geom)), ST_Y(ST_Centroid(geom))
                  FROM jurisdictions
                 WHERE parent_id = ?
                   AND deleted_at IS NULL
                   AND geom IS NOT NULL
                    ON CONFLICT (jurisdiction_id) DO NOTHING
            ', [$parentId]);

            // 3. All sibling pairs — Step 7's CTE verbatim, reading the
            //    simplify cache where present (identical values by
            //    construction: same expression, deterministic ST_Simplify).
            DB::statement("
                INSERT INTO jurisdiction_adjacency (parent_id, j1, j2, dim, border_len, computed_at)
                SELECT ?, pair.j1, pair.j2,
                       ST_Dimension(pair.ix),
                       ST_Length(ST_CollectionExtract(pair.ix, 2)),
                       now()
                  FROM (
                    WITH g AS (
                        SELECT j.id,
                               COALESCE(s.geom,
                                   CASE
                                       WHEN ST_NPoints(j.geom) > 1000000
                                            THEN ST_MakeValid(ST_Simplify(j.geom, 0.01))
                                       WHEN ST_NPoints(j.geom) > 50000
                                            THEN ST_MakeValid(ST_Simplify(j.geom, 0.001))
                                       ELSE j.geom
                                   END
                               ) AS geom
                        FROM jurisdictions j
                        LEFT JOIN jurisdiction_simplified s ON s.jurisdiction_id = j.id
                        WHERE j.parent_id = ?
                          AND j.deleted_at IS NULL
                          AND j.geom IS NOT NULL
                    )
                    SELECT a.id AS j1, b.id AS j2,
                           ST_Intersection(a.geom, b.geom) AS ix
                    FROM g a
                    JOIN g b ON a.id < b.id
                        AND a.geom && b.geom
                        AND ST_Intersects(a.geom, b.geom)
                  ) pair
                    ON CONFLICT (parent_id, j1, j2) DO NOTHING
            ", [$parentId, $parentId]);

            DB::table('jurisdiction_adjacency_parents')
                ->where('parent_id', $parentId)
                ->update([
                    'status'      => 'done',
                    'claim_token' => null,
                    'duration_ms' => (int) ((microtime(true) - $t0) * 1000),
                    'error'       => null,
                    'updated_at'  => now(),
                ]);
        } catch (\Throwable $e) {
            // A failed parent falls back to live Step-7 compute forever —
            // safe (today's behavior), just unaccelerated. Never rethrow.
            DB::table('jurisdiction_adjacency_parents')
                ->where('parent_id', $parentId)
                ->update([
                    'status'      => 'failed',
                    'claim_token' => null,
                    'duration_ms' => (int) ((microtime(true) - $t0) * 1000),
                    'error'       => mb_substr($e->getMessage(), 0, 1000),
                    'updated_at'  => now(),
                ]);
            Log::warning('Adjacency precompute failed for parent (falls back to live compute)', [
                'parent_id' => $parentId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Write-back from a LIVE Step-7 computation (the lazy/fallback path).
     * Inserts the computed edges; marks the parent done ONLY when the
     * computed pool covered ALL geometry-bearing children (Step 7 usually
     * runs the non-giant pool — a subset must never mark completeness).
     *
     * @param list<array{j1: string, j2: string, dim: int, border_len: ?float}> $edges
     * @param list<string> $poolIds the ids the live query ran over
     */
    public function writeBack(string $parentId, array $poolIds, array $edges): void
    {
        try {
            foreach (array_chunk($edges, 500) as $chunk) {
                $rows = [];
                foreach ($chunk as $e) {
                    $rows[] = [
                        'parent_id'  => $parentId,
                        'j1'         => $e['j1'],
                        'j2'         => $e['j2'],
                        'dim'        => $e['dim'],
                        'border_len' => $e['border_len'],
                    ];
                }
                DB::table('jurisdiction_adjacency')->insertOrIgnore($rows);
            }

            $allChildren = (int) DB::table('jurisdictions')
                ->where('parent_id', $parentId)
                ->whereNull('deleted_at')
                ->whereNotNull('geom')
                ->count();
            if ($allChildren === count($poolIds)) {
                DB::table('jurisdiction_adjacency_parents')->updateOrInsert(
                    ['parent_id' => $parentId],
                    ['status' => 'done', 'child_count' => $allChildren, 'updated_at' => now()],
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Adjacency write-back failed (non-fatal)', [
                'parent_id' => $parentId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** Is this parent's adjacency fully materialized? */
    public function isDone(string $parentId): bool
    {
        return DB::table('jurisdiction_adjacency_parents')
            ->where('parent_id', $parentId)
            ->where('status', 'done')
            ->exists();
    }
}
