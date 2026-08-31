<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE ONE HEAD, ahead of the lanes (operator ruling 2026-08-30, the Guyana
 * 91-on-84): the whole apportionment of a map is knowable on a blank map —
 * the root sizes once (own-row law), every scope's budget stamps at
 * materialization, and the pre-draw gate refuses a head that cannot
 * distribute. This command runs that materialization for pending sweep
 * items so a requeue or a fresh run draws to stamped heads from the first
 * lane. Chunked, resumable, visible (the ETL rule).
 *
 *   php artisan autoscale:materialize <runId>              all pending sweeps
 *   php artisan autoscale:materialize <runId> --item=<jid>  one jurisdiction
 */
class AutoscaleMaterializeCommand extends Command
{
    protected $signature = 'autoscale:materialize {runId} {--item=}';

    protected $description = 'Resize each root once, stamp every scope budget, and gate the head before any drawing';

    public function handle(): int
    {
        $runId = (string) $this->argument('runId');
        $itemJid = $this->option('item');

        $q = DB::table('autoscale_items')
            ->where('run_id', $runId)
            ->where('kind', 'sweep')
            ->whereIn('status', ['pending'])
            ->orderBy('position');
        if ($itemJid !== null) {
            $q->where('jurisdiction_id', (string) $itemJid);
        }
        $items = $q->get(['id', 'jurisdiction_id']);
        if ($items->isEmpty()) {
            $this->warn('No pending sweep items matched.');

            return self::SUCCESS;
        }

        $names = DB::table('jurisdictions')
            ->whereIn('id', $items->pluck('jurisdiction_id'))
            ->pluck('name', 'id');

        $done = 0;
        $failed = 0;
        $t0 = microtime(true);
        foreach ($items as $i => $item) {
            $name = $names[$item->jurisdiction_id] ?? $item->jurisdiction_id;
            try {
                $scopes = \App\Support\AutoscaleEnumeration::materializeScopeTree($runId, (string) $item->id);
                $done++;
                $this->line(sprintf(
                    '[%d/%d] %s — %d scopes stamped (%.1fs elapsed)',
                    $i + 1, $items->count(), $name, $scopes, microtime(true) - $t0
                ));
            } catch (\Throwable $e) {
                // The pre-draw gate's refusal (or any failure) is the item's
                // honest review verdict — recorded before a single district
                // could draw wrong.
                $failed++;
                DB::table('autoscale_items')->where('id', $item->id)->update([
                    'status'     => 'review',
                    'reason'     => mb_substr('materialize: '.$e->getMessage(), 0, 1000),
                    'updated_at' => now(),
                ]);
                $this->error("[{$name}] ".$e->getMessage());
            }
        }

        // THE MEET-IN-THE-MIDDLE KEY (operator order 2026-08-31): stamp
        // reverse_position across the whole run — deepest admin layer
        // first, lowest population first within a layer — the bottom-up
        // lanes' claim order. Set-based, idempotent, one statement over
        // the run's scopes (bounded: scopes are the enumerated tree, not
        // the planet's jurisdictions).
        if (Schema::hasColumn('autoscale_scopes', 'reverse_position')) {
            DB::statement('
                WITH ranked AS (
                    SELECT s.id,
                           ROW_NUMBER() OVER (
                               ORDER BY j.adm_level DESC,
                                        j.population ASC NULLS FIRST,
                                        s.id
                           ) AS rn
                      FROM autoscale_scopes s
                      JOIN jurisdictions j ON j.id = s.scope_jurisdiction_id
                     WHERE s.run_id = ?
                )
                UPDATE autoscale_scopes s
                   SET reverse_position = r.rn
                  FROM ranked r
                 WHERE s.id = r.id
            ', [$runId]);
            $this->line('reverse_position stamped (bottom-up claim key).');
        }

        $this->info("Materialized {$done}, refused {$failed}.");

        return self::SUCCESS;
    }
}
