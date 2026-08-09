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
        $this->skipElections = \App\Models\InstanceSettings::query()
            ->whereNull('deleted_at')->value('institution_scale_mode') === 'manual';
        // MATERIALISE ONCE, THEN KEYSET-WALK (THE ETL RULE: bound the INPUT).
        // The recursive walk runs a single time into a temp roster carrying
        // its own sequence — shallowest first, so a parent boots before its
        // children — and the loop below reads that roster in batches. Working
        // memory is flat whether the subtree holds 34 nodes or the whole
        // planet, which is what lets the old 5,000-node cap go entirely
        // (operator ruling 2026-08-08: "Remove this restriction").
        // DDL first, then a PARAMETERISED insert: PostgreSQL does not accept
        // bound parameters inside CREATE TABLE AS (a utility statement), and
        // interpolating an id into DDL is not something this codebase does.
        DB::statement('DROP TABLE IF EXISTS subtree_roster');
        DB::statement('CREATE TEMP TABLE subtree_roster (seq bigint PRIMARY KEY, id uuid NOT NULL, slug text NOT NULL)');
        DB::insert(<<<'SQL'
            INSERT INTO subtree_roster (seq, id, slug)
            WITH RECURSIVE t AS (
                SELECT id, slug, adm_level, name
                  FROM jurisdictions WHERE id = ? AND deleted_at IS NULL
                UNION ALL
                SELECT c.id, c.slug, c.adm_level, c.name
                  FROM jurisdictions c JOIN t ON c.parent_id = t.id
                 WHERE c.deleted_at IS NULL
            )
            SELECT row_number() OVER (ORDER BY t.adm_level, t.name, t.id),
                   t.id, t.slug
              FROM t
        SQL, [$this->rootJurisdictionId]);

        // LIVE PROGRESS (operator, 2026-08-08): the job publishes its own
        // counters to cache so the row's mini bar polls a single key instead
        // of re-walking the subtree every tick — a recursive CTE per poll
        // would be brutal on a planet-sized subtree.
        $cacheKey = self::progressKey($this->rootJurisdictionId);
        $total = (int) DB::table('subtree_roster')->count();
        $publish = function (int $processed, int $booted, bool $finished = false) use ($cacheKey, $total) {
            \Illuminate\Support\Facades\Cache::put($cacheKey, [
                'total' => $total, 'processed' => $processed,
                'booted' => $booted, 'finished' => $finished,
            ], $finished ? 120 : 7200);
        };
        $publish(0, 0);

        $batchSize = max(50, (int) config('cga.activate_subtree_batch', 500));
        $done = 0;
        $skipped = 0;
        $failed = 0;
        $processed = 0;
        $afterSeq = 0;

        while (true) {
            $batch = DB::table('subtree_roster')
                ->where('seq', '>', $afterSeq)
                ->orderBy('seq')
                ->limit($batchSize)
                ->get(['seq', 'id', 'slug']);

            if ($batch->isEmpty()) {
                break;
            }

            foreach ($batch as $row) {
                $afterSeq = (int) $row->seq;
                $processed++;

                // One bad node must never end the pass — everything behind it
                // still deserves its board (all-or-nothing is a bug).
                try {
                    $has = DB::table('legislatures')
                        ->where('jurisdiction_id', $row->id)
                        ->whereNull('deleted_at')
                        ->exists();

                    if (! $has) {
                        $exit = Artisan::call('apportionment:seed', ['--jurisdiction' => $row->slug]);
                        if ($exit !== 0) {
                            $failed++;
                            Log::warning(sprintf('ActivateSubtreeJob: seed failed for %s (exit %d)', $row->slug, $exit));
                            $publish($processed, $done + $skipped);

                            continue;
                        }
                        $done++;
                    } else {
                        $skipped++;
                    }

                    // THE FULL BOOT: sizing alone is not activation —
                    // WF-JUR-01 adopts the legislature and constitutes the
                    // bootstrap board (the mapper's R-08 substrate). In
                    // MANUAL mode the founding election is NOT scheduled
                    // (operator, 2026-08-08): he is activating to get into
                    // position to DRAW, and elections are his act to take
                    // when he is ready.
                    $bootExit = Artisan::call('jurisdiction:activate', array_filter([
                        'slug' => $row->slug, '--force' => true,
                        '--no-election' => $this->skipElections,
                    ]));
                    if ($bootExit !== 0) {
                        Log::warning(sprintf('ActivateSubtreeJob: activation exited %d for %s', $bootExit, $row->slug));
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning(sprintf('ActivateSubtreeJob: %s threw — %s', $row->slug, $e->getMessage()));
                }

                $publish($processed, $done + $skipped);

                if ($processed % 50 === 0) {
                    Log::info(sprintf(
                        'ActivateSubtreeJob %s: %d/%d — %d seeded, %d already active, %d failed',
                        $this->rootJurisdictionId, $processed, $total, $done, $skipped, $failed,
                    ));
                }
            }
        }

        $publish($total, $done + $skipped, finished: true);
        DB::statement('DROP TABLE IF EXISTS subtree_roster');

        Log::info(sprintf(
            'ActivateSubtreeJob %s COMPLETE: %d seeded, %d already active, %d failed of %d nodes.',
            $this->rootJurisdictionId, $done, $skipped, $failed, $total,
        ));
    }
}
