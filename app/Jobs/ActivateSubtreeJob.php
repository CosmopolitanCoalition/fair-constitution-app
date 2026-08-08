<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Activate a jurisdiction AND its subtree, one legislature seed per node
 * (operator, 2026-08-08 — the per-row "+ children recursively" control).
 *
 * THE ETL RULE shape: the roster is fetched cheap (id/slug only), the walk is
 * a bounded loop committing per node (apportionment:seed is its own
 * transaction), nodes that already hold a legislature are skipped, and a kill
 * costs only the in-flight node — re-dispatch resumes. Progress logs every 50.
 * Trees larger than cga.activate_recursive_max never reach this job (the
 * endpoint refuses them toward Activate All / the autoscale engine).
 */
class ActivateSubtreeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 0;

    public function __construct(public readonly string $rootJurisdictionId)
    {
    }

    /** The cache key this job publishes progress to, and the UI reads. */
    public static function progressKey(string $rootJurisdictionId): string
    {
        return "activate_subtree_progress:{$rootJurisdictionId}";
    }

    public function handle(): void
    {
        $roster = DB::select(<<<'SQL'
            WITH RECURSIVE t AS (
                SELECT id, slug, adm_level, name
                  FROM jurisdictions WHERE id = ? AND deleted_at IS NULL
                UNION ALL
                SELECT c.id, c.slug, c.adm_level, c.name
                  FROM jurisdictions c JOIN t ON c.parent_id = t.id
                 WHERE c.deleted_at IS NULL
            )
            SELECT t.id, t.slug FROM t
             ORDER BY t.adm_level, t.name
        SQL, [$this->rootJurisdictionId]);

        // LIVE PROGRESS (operator, 2026-08-08): the job publishes its own
        // counters to cache so the row's mini bar polls a single key instead
        // of re-walking the subtree every tick — a recursive CTE per poll
        // would be brutal on a planet-sized subtree.
        $cacheKey = self::progressKey($this->rootJurisdictionId);
        $total = count($roster);
        $publish = function (int $processed, int $booted, bool $finished = false) use ($cacheKey, $total) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, [
                'total' => $total, 'processed' => $processed,
                'booted' => $booted, 'finished' => $finished,
            ], $finished ? 120 : 7200);
        };
        $publish(0, 0);

        $done = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($roster as $i => $row) {
            $has = DB::table('legislatures')
                ->where('jurisdiction_id', $row->id)
                ->whereNull('deleted_at')
                ->exists();

            if (! $has) {
                $exit = Artisan::call('apportionment:seed', ['--jurisdiction' => $row->slug]);
                $exit === 0 ? $done++ : $failed++;
                if ($exit !== 0) {
                    Log::warning(sprintf('ActivateSubtreeJob: seed failed for %s (exit %d)', $row->slug, $exit));

                    continue;
                }
            } else {
                $skipped++;
            }

            // THE FULL BOOT (2026-08-08): sizing alone is not activation —
            // WF-JUR-01 adopts the legislature and constitutes the bootstrap
            // board (the mapper's R-08 substrate). Idempotent per node.
            $bootExit = Artisan::call('jurisdiction:activate', [
                'slug' => $row->slug, '--force' => true,
            ]);
            if ($bootExit !== 0) {
                Log::warning(sprintf('ActivateSubtreeJob: activation exited %d for %s', $bootExit, $row->slug));
            }

            $publish($i + 1, $done + $skipped);

            if (($i + 1) % 50 === 0) {
                Log::info(sprintf(
                    'ActivateSubtreeJob %s: %d/%d — %d seeded, %d skipped, %d failed',
                    $this->rootJurisdictionId, $i + 1, count($roster), $done, $skipped, $failed,
                ));
            }
        }

        $publish($total, $done + $skipped, finished: true);

        Log::info(sprintf(
            'ActivateSubtreeJob %s COMPLETE: %d seeded, %d already active, %d failed of %d nodes.',
            $this->rootJurisdictionId, $done, $skipped, $failed, count($roster),
        ));
    }
}
