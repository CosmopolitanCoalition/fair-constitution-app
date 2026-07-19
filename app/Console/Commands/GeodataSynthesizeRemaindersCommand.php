<?php

namespace App\Console\Commands;

use App\Services\Geodata\GeodataRemediationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remainder synthesis — the batch repair for the COVERAGE-GAP class
 * (Moscow 13.4M with one 267k child; Islamabad; China's "shi" rows): a
 * parent whose children rows cover only a sliver of its territory gets
 * synthesized children holding the uncovered remainder, making the
 * children-sum law's input truthful.
 *
 * COMPONENT-AWARE since run 3 (2026-07-19): the remainder is split into
 * connected components — material ones become their own children, crumbs
 * aggregate into one scattered child — because 1,206 of the original
 * 1,504 single-child remainders were multi-part swiss cheese the
 * line-splitter lawfully refuses. All per-parent logic lives in
 * GeodataRemediationService::synthesizeRemainder (ONE implementation —
 * command, manifest replay, and UI all share it).
 *
 * --resplit converts old-style multi-part remainder rows in place:
 * soft-deletes the parent's existing synthetic remainder children and
 * re-synthesizes components. Synthetic-row surgery only — it requires the
 * autoscale run to be HALTED, not the acceptance window reopened (the
 * window gates REAL-data repairs; these rows are this plane's own output).
 */
class GeodataSynthesizeRemaindersCommand extends Command
{
    protected $signature = 'geodata:synthesize-remainders
                            {--coverage=0.9 : Only parents whose children-sum < coverage × own population}
                            {--min-pop=10000 : Component keep threshold (also the candidate floor)}
                            {--min-area-frac=0.02 : Component/total keep threshold as a fraction of parent area}
                            {--resplit : Convert existing multi-part remainder rows to components (run must be halted)}
                            {--dry-run : Report candidates without writing}';

    protected $description = 'Synthesize component-aware remainder children for coverage-gapped parents';

    public function handle(GeodataRemediationService $remediation): int
    {
        $coverage    = (float) $this->option('coverage');
        $minPop      = (int) $this->option('min-pop');
        $minAreaFrac = (float) $this->option('min-area-frac');
        $dryRun      = (bool) $this->option('dry-run');
        $resplit     = (bool) $this->option('resplit');

        if ($resplit) {
            return $this->resplit($remediation, $minAreaFrac, $minPop, $dryRun);
        }

        $accepted = DB::table('instance_settings')->whereNull('deleted_at')->value('map_accepted_at');
        if ($accepted !== null) {
            $this->error('The repair window is closed (map data is accepted). Reopen maps first — fresh repairs and acceptance never overlap.');

            return self::FAILURE;
        }

        $candidates = DB::select('
            SELECT j.slug, j.name, j.adm_level, j.population, k.child_sum, k.n_children
              FROM jurisdictions j
              JOIN (
                    SELECT parent_id, SUM(population) AS child_sum, COUNT(*) AS n_children,
                           BOOL_OR(source = \'synthesized-remainder\') AS has_remainder
                      FROM jurisdictions
                     WHERE deleted_at IS NULL AND parent_id IS NOT NULL
                     GROUP BY parent_id
              ) k ON k.parent_id = j.id
             WHERE j.deleted_at IS NULL
               AND j.geom IS NOT NULL
               AND COALESCE(j.population, 0) > 0
               AND NOT k.has_remainder
               AND k.child_sum < (?::float8) * j.population
               AND (j.population - k.child_sum) >= (?::bigint)
             ORDER BY j.adm_level, (j.population - k.child_sum) DESC
        ', [$coverage, $minPop]);

        $this->info(sprintf(
            'Coverage-gapped parents (children-sum < %.0f%% of own, remainder ≥ %s): %d candidates%s',
            $coverage * 100, number_format($minPop), count($candidates), $dryRun ? ' [DRY RUN]' : ''
        ));

        $created = 0;
        $skipped = 0;
        foreach ($candidates as $i => $p) {
            if ($dryRun) {
                if ($i < 25) {
                    $this->line(sprintf('  ADM%d %-40s pop %s | children %d cover %s',
                        $p->adm_level, $p->name, number_format((int) $p->population),
                        $p->n_children, number_format((int) $p->child_sum)));
                }
                $created++;
                continue;
            }

            try {
                $remediation->synthesizeRemainder(null, (string) $p->slug, 'bulk coverage-gap synthesis', null, $minAreaFrac, $minPop);
                $created++;
            } catch (\InvalidArgumentException $e) {
                // The settled noise class (children tile the territory) — skipped by law.
                $skipped++;
            }
        }

        $this->info(($dryRun ? '[DRY RUN] would process' : 'Synthesized for')." {$created} parents | skipped (noise/none): {$skipped}");

        return self::SUCCESS;
    }

    private function resplit(GeodataRemediationService $remediation, float $minAreaFrac, int $minPop, bool $dryRun): int
    {
        // Old-style rows to convert: any parent holding a MULTI-PART
        // synthetic remainder child (single-part rows are already the
        // component shape and stay).
        $targets = DB::select("
            SELECT DISTINCT p.slug, p.name
              FROM jurisdictions r
              JOIN jurisdictions p ON p.id = r.parent_id
             WHERE r.source = 'synthesized-remainder'
               AND r.deleted_at IS NULL
               AND r.slug NOT LIKE '%remainder-scattered%'
               AND ST_NumGeometries(r.geom) > 1
        ");

        $this->info('Multi-part remainder parents to resplit: '.count($targets).($dryRun ? ' [DRY RUN]' : ''));
        if ($dryRun) {
            return self::SUCCESS;
        }

        $done = 0;
        $failed = 0;
        foreach ($targets as $p) {
            try {
                DB::transaction(function () use ($remediation, $p, $minAreaFrac, $minPop) {
                    // Retire the old synthetic children, then re-derive as
                    // components (the diff against the remaining REAL
                    // children reproduces the same remainder).
                    DB::table('jurisdictions')
                        ->where('parent_id', function ($q) use ($p) {
                            $q->select('id')->from('jurisdictions')->where('slug', $p->slug)->limit(1);
                        })
                        ->where('source', 'synthesized-remainder')
                        ->whereNull('deleted_at')
                        ->update(['deleted_at' => now(), 'updated_at' => now()]);

                    $remediation->synthesizeRemainder(
                        null, (string) $p->slug, 'resplit to components (run-3 fragmented-remainder fix)',
                        null, $minAreaFrac, $minPop, requireWindow: false,
                    );
                });
                $done++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("  FAILED {$p->name}: ".$e->getMessage());
            }
        }

        $this->info("Resplit {$done} parents | failed: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
