<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan districts:backfill-stats
 *
 * Backfills polsby_popper, num_geom_parts, and is_contiguous for districts
 * that are missing one or more of these cached spatial stats columns.
 *
 * Districts are always created through PHP (createDistrict / massReseed), so
 * all districts have member junctions available for computation.
 *
 * Options:
 *   --legislature-id=UUID   Limit backfill to a single legislature
 *   --dry-run               Report count without making any writes
 *
 * Safe to re-run: the query guard targets rows where is_contiguous IS NULL,
 * so already-processed rows are skipped automatically.
 */
class BackfillDistrictSpatialStatsCommand extends Command
{
    protected $signature = 'districts:backfill-stats
                            {--legislature-id= : Limit backfill to a single legislature UUID}
                            {--force           : Reset all existing PP values and recompute everything}
                            {--dry-run         : Report count without making any writes}';

    protected $description = 'Backfill polsby_popper / num_geom_parts / is_contiguous for districts';

    public function handle(): int
    {
        $legislatureId = $this->option('legislature-id');
        $dryRun        = (bool) $this->option('dry-run');
        $force         = (bool) $this->option('force');

        $label = $dryRun ? ' [DRY RUN]' : '';
        $this->info("Backfilling spatial stats for districts{$label}…");

        $query = DB::table('legislature_districts')->whereNull('deleted_at');

        if ($legislatureId) {
            $query->where('legislature_id', $legislatureId);
        }

        if (!$force) {
            // Default: only rows where compactness hasn't been computed yet
            $query->whereNull('convex_hull_ratio');
        } else {
            $this->info('--force: resetting all compactness values before recompute…');
            $reset = DB::table('legislature_districts')->whereNull('deleted_at');
            if ($legislatureId) $reset->where('legislature_id', $legislatureId);
            $reset->update(['convex_hull_ratio' => null, 'num_geom_parts' => null]);
        }

        $districtIds = $query->pluck('id')->toArray();
        $total       = count($districtIds);

        $this->info("Found {$total} district(s) needing backfill.");

        if ($dryRun || $total === 0) {
            return self::SUCCESS;
        }

        // Process one district at a time — ST_Union on complex sub-national
        // geometries is memory-intensive to batch.  Each individual query is fast.
        $updated = 0;
        $skipped = 0;

        foreach ($districtIds as $districtId) {
            // ── Shape compactness (CHR) + clustering (centroid spread) ────────
            // Union first so shared borders cancel; then derive both metrics from
            // the merged geometry.  Wrapped in try/catch: one bad geometry skips
            // compactness but still records contiguity.
            $spatialRow = null;
            try {
                $spatialRow = DB::selectOne("
                    WITH union_cte AS (
                        SELECT ST_MakeValid(ST_Union(ST_MakeValid(j.geom))) AS geom
                        FROM legislature_district_jurisdictions ldj
                        JOIN jurisdictions j ON j.id = ldj.jurisdiction_id
                            AND j.geom IS NOT NULL AND j.deleted_at IS NULL
                        WHERE ldj.district_id = ?
                    )
                    SELECT
                        ST_Area(geom) / NULLIF(ST_Area(ST_ConvexHull(geom)), 0) AS convex_hull_ratio,
                        ST_NumGeometries(geom)                                   AS num_geom_parts
                    FROM union_cte
                ", [$districtId]);
            } catch (\Throwable $e) {
                // Geometry error on this district — skip compactness, still compute contiguity
            }

            // ── Contiguity: BFS graph connectivity ───────────────────────────
            // Same algorithm as recomputeDistrict() — ST_Intersects adjacency
            // detects shared borders, ignoring internal island geometry within
            // individual members (Michigan UP, Hawaiian islands, etc.).
            $jids = DB::table('legislature_district_jurisdictions')
                ->where('district_id', $districtId)
                ->pluck('jurisdiction_id')
                ->toArray();

            if (empty($jids)) {
                $skipped++;
                continue;
            }

            if (count($jids) <= 1) {
                $isContiguous = true;
            } else {
                $jidPh1   = implode(',', array_fill(0, count($jids), '?'));
                $jidPh2   = implode(',', array_fill(0, count($jids), '?'));
                $adjPairs = DB::select("
                    SELECT a.id AS a_id, b.id AS b_id
                    FROM jurisdictions a
                    JOIN jurisdictions b ON b.id > a.id
                        AND b.id IN ({$jidPh2})
                        AND b.geom IS NOT NULL AND b.deleted_at IS NULL
                        AND a.geom && ST_Expand(b.geom, 1.35)
                    WHERE a.id IN ({$jidPh1})
                      AND a.geom IS NOT NULL AND a.deleted_at IS NULL
                ", array_merge($jids, $jids));

                $adj = [];
                foreach ($adjPairs as $p) {
                    $adj[$p->a_id][] = $p->b_id;
                    $adj[$p->b_id][] = $p->a_id;
                }
                $visited = [];
                $queue   = [$jids[0]];
                while (!empty($queue)) {
                    $node = array_shift($queue);
                    if (isset($visited[$node])) continue;
                    $visited[$node] = true;
                    foreach ($adj[$node] ?? [] as $nb) {
                        if (!isset($visited[$nb])) $queue[] = $nb;
                    }
                }
                $isContiguous = count($visited) === count($jids);

                // If non-contiguous, check whether contiguity was even achievable.
                // Island jurisdictions (Hawaii, Puerto Rico…) can never be made
                // contiguous with mainland members — no map can fix it.
                // Per-orphan EXISTS check: does this member share any border with
                // any sibling (same parent_id)?  GiST bbox pre-filter makes this
                // near-instant for true islands (bbox has no overlap with any other
                // sibling → 0 candidates, exits immediately).
                if (!$isContiguous) {
                    $orphanedJids = array_values(array_filter($jids, fn($j) => !isset($visited[$j])));
                    foreach ($orphanedJids as $oj) {
                        $hasSiblingBorder = DB::selectOne("
                            SELECT 1
                            FROM jurisdictions a
                            JOIN jurisdictions b
                                ON b.parent_id = a.parent_id
                                AND b.id != a.id
                                AND b.deleted_at IS NULL
                                AND b.geom IS NOT NULL
                                AND ST_Intersects(a.geom, b.geom)
                            WHERE a.id = ?
                              AND a.deleted_at IS NULL
                            LIMIT 1
                        ", [$oj]);
                        if (!$hasSiblingBorder) {
                            $isContiguous = true;
                            break;
                        }
                    }
                }
            }

            DB::table('legislature_districts')
                ->where('id', $districtId)
                ->update([
                    'polsby_popper'     => null,
                    'num_geom_parts'    => $spatialRow && $spatialRow->num_geom_parts !== null    ? (int)   $spatialRow->num_geom_parts    : null,
                    'convex_hull_ratio' => $spatialRow && $spatialRow->convex_hull_ratio !== null ? round((float) $spatialRow->convex_hull_ratio, 6) : null,
                    'is_contiguous'     => $isContiguous,
                    'updated_at'        => now(),
                ]);

            $updated++;

            if ($updated % 25 === 0 || $updated === $total) {
                $this->line("  {$updated}/{$total} updated");
            }
        }

        $this->info("Done. Updated: {$updated}  Skipped (no geometry): {$skipped}");

        return self::SUCCESS;
    }
}
