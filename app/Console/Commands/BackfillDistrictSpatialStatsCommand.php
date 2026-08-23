<?php

namespace App\Console\Commands;

use App\Services\DistrictingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * php artisan districts:backfill-stats
 *
 * Backfills the cached spatial stats — convex_hull_ratio, num_geom_parts,
 * is_contiguous — for districts missing them (cloned maps before 0befbb9
 * carried none; older rows predate the columns).
 *
 * ONE OWNER OF THE MATH (2026-08-23). This command used to carry its own copy
 * of the contiguity test, and that copy still used the RETIRED predicate
 * `ST_Expand(geom, 1.35)` — the one DistrictingService::recomputeDistrict
 * replaced because it "created ~150 km false adjacencies". So a backfilled
 * district could read Intact where a redraw of the same district read
 * non-contiguous, on the exact constitutional metric the operator was
 * tuning. It now delegates to recomputeDistrict with $skipSeatsUpdate=true
 * (stats only — seats are never re-derived), so backfilled and redrawn
 * verdicts are identical by construction, and the revealed-GeoJSON cache is
 * flushed ONCE per legislature at the end rather than per district.
 *
 * Options:
 *   --legislature-id=UUID   Limit to one legislature
 *   --map-id=UUID           Limit to one district map
 *   --force                 Reset existing values and recompute everything in scope
 *   --dry-run               Report the count without writing
 *
 * Safe to re-run: without --force only rows with convex_hull_ratio IS NULL
 * are touched.
 */
class BackfillDistrictSpatialStatsCommand extends Command
{
    protected $signature = 'districts:backfill-stats
                            {--legislature-id= : Limit backfill to a single legislature UUID}
                            {--map-id=         : Limit backfill to a single district map UUID}
                            {--force           : Reset existing stats in scope and recompute everything}
                            {--dry-run         : Report count without making any writes}';

    protected $description = 'Backfill convex_hull_ratio / num_geom_parts / is_contiguous for districts (delegates to DistrictingService::recomputeDistrict)';

    public function handle(DistrictingService $districting): int
    {
        $legislatureId = $this->option('legislature-id');
        $mapId         = $this->option('map-id');
        $dryRun        = (bool) $this->option('dry-run');
        $force         = (bool) $this->option('force');

        $label = $dryRun ? ' [DRY RUN]' : '';
        $this->info("Backfilling spatial stats for districts{$label}…");

        $scope = DB::table('legislature_districts')->whereNull('deleted_at');
        if ($legislatureId) {
            $scope->where('legislature_id', $legislatureId);
        }
        if ($mapId) {
            $scope->where('map_id', $mapId);
        }

        if ($force) {
            if ($dryRun) {
                $this->info('--force with --dry-run: nothing reset.');
            } else {
                $this->info('--force: resetting stats in scope before recompute…');
                (clone $scope)->update([
                    'convex_hull_ratio' => null, 'num_geom_parts' => null, 'is_contiguous' => null,
                ]);
            }
        } else {
            $scope->whereNull('convex_hull_ratio');
        }

        $rows  = $scope->orderBy('legislature_id')->orderBy('district_number')->get(['id', 'legislature_id']);
        $total = $rows->count();

        $this->info("Found {$total} district(s) needing backfill.");
        if ($dryRun || $total === 0) {
            return self::SUCCESS;
        }

        // The legislature rows recomputeDistrict wants, fetched once each.
        $legs = DB::table('legislatures')
            ->whereIn('id', $rows->pluck('legislature_id')->unique()->all())
            ->get()->keyBy('id');

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;
        $failed  = 0;
        foreach ($rows as $row) {
            $leg = $legs->get($row->legislature_id);
            if ($leg === null) {
                $failed++;
                $bar->advance();

                continue;
            }

            try {
                // stats only — seats are NEVER re-derived here
                $districting->recomputeDistrict((string) $row->id, (string) $row->legislature_id, $leg, true);
                $updated++;
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->warn("  {$row->id}: {$e->getMessage()}");
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        // recomputeDistrict skips the flush on the stats-only path; flush once
        // per legislature so the mapper repaints with the new numbers.
        foreach ($legs->keys() as $lid) {
            Cache::tags(["revealed.{$lid}"])->flush();
        }

        $this->info("Done. Updated {$updated}, failed {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
