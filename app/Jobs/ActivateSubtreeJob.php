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

    /** True in MANUAL mode: activate to READY (board + posture), no elections. */
    private bool $skipElections = false;

    public function handle(): void
    {
        // Prebuild activates to READY, not to ELECTING — only population
        // mode (CLK-06, a real resident arriving) schedules. See
        // JurisdictionController::skipFoundingElections for the ruling.
        $this->skipElections = \App\Models\InstanceSettings::query()
            ->whereNull('deleted_at')->value('institution_scale_mode') !== 'population';
        // A4 (operator ruling 2026-08-29): THE COORDINATOR SHAPE. This job
        // now only enumerates the subtree into the pile (one row per node,
        // depth = adm_level so parents sit in shallower waves) and
        // dispatches host-derived lanes; SubtreeBootLaneJob does the
        // per-node boots one claim per fresh-slate job. Chunkable,
        // resumable (a kill costs one node), visible (the same progress
        // cache the mini bar always polled), lanes from the host, and the
        // depth-wave claim keeps every parent before its children.
        DB::insert(<<<'SQL'
            INSERT INTO subtree_boot_items
                (id, root_id, jurisdiction_id, slug, depth, status, created_at, updated_at)
            WITH RECURSIVE t AS (
                SELECT id, slug, adm_level
                  FROM jurisdictions WHERE id = ? AND deleted_at IS NULL
                UNION ALL
                SELECT c.id, c.slug, c.adm_level
                  FROM jurisdictions c JOIN t ON c.parent_id = t.id
                 WHERE c.deleted_at IS NULL
            )
            SELECT gen_random_uuid(), ?, t.id, t.slug, t.adm_level, 'pending', now(), now()
              FROM t
             ON CONFLICT (root_id, jurisdiction_id) DO NOTHING
        SQL, [$this->rootJurisdictionId, $this->rootJurisdictionId]);

        $open = (int) DB::table('subtree_boot_items')
            ->where('root_id', $this->rootJurisdictionId)
            ->whereIn('status', ['pending', 'running'])
            ->count();
        $total = (int) DB::table('subtree_boot_items')
            ->where('root_id', $this->rootJurisdictionId)->count();
        \Illuminate\Support\Facades\Cache::put(
            self::progressKey($this->rootJurisdictionId),
            ['total' => $total, 'processed' => $total - $open, 'booted' => 0, 'finished' => $open === 0],
            7200,
        );

        $lanes = max(2, min(\App\Support\HostCapacity::autoscaleWorkers(), $open));
        for ($i = 0; $i < $lanes; $i++) {
            SubtreeBootLaneJob::dispatch($this->rootJurisdictionId, $i, $this->skipElections);
        }
        Log::info('ActivateSubtreeJob: pile enumerated, lanes dispatched', [
            'root' => $this->rootJurisdictionId, 'open' => $open, 'total' => $total, 'lanes' => $lanes,
        ]);
    }
}
