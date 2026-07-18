<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Remainder synthesis — the batch repair for the COVERAGE-GAP class the
 * first full-scale autoscale run surfaced (2026-07-18): a parent whose
 * children rows cover only a sliver of its territory/population (Moscow
 * 13.4M with one 267k child; Islamabad; Kuala Lumpur; China's "shi" rows).
 * The children-sum law then sizes the chamber off the sliver and the
 * composite strands the uncovered interior — territory with no district.
 *
 * The repair: synthesize ONE child per gapped parent holding the uncovered
 * remainder — geometry = parent minus the children's union, population =
 * parent − children-sum. With the remainder row in place every downstream
 * law works unchanged: the children-sum becomes truthful (sizing), the
 * remainder composites or line-splits like any constituent (usually a
 * childless giant → the ✂ path), and nothing is total-forced anywhere.
 *
 * THE DISCRIMINATOR: a GEOMETRIC remainder. Parents whose children tile the
 * whole area but whose populations disagree (Delhi +165) are the SETTLED
 * population-noise class — never harmonized, never touched here; they skip
 * on the area check. Only real uncovered territory gets a row.
 *
 * Repair-plane posture: window-gated (map_accepted_at must be open), every
 * synthesis writes a geodata_repairs ledger row (action
 * 'synthesize_remainder', full params for revert = soft-delete created_id),
 * rows carry source='synthesized-remainder' and are KEEP-class synthetic
 * rows per the lineage doctrine.
 */
class GeodataSynthesizeRemaindersCommand extends Command
{
    protected $signature = 'geodata:synthesize-remainders
                            {--coverage=0.9 : Only parents whose children-sum < coverage × own population}
                            {--min-pop=10000 : Skip parents whose population remainder is below this}
                            {--min-area-frac=0.02 : Skip when the uncovered area is below this fraction of the parent (population-noise class — settled, untouched)}
                            {--dry-run : Report candidates without writing}';

    protected $description = 'Synthesize remainder children for coverage-gapped parents (the stranded-satellite repair)';

    public function handle(): int
    {
        $accepted = DB::table('instance_settings')->whereNull('deleted_at')->value('map_accepted_at');
        if ($accepted !== null) {
            $this->error('The repair window is closed (map data is accepted). Reopen maps first — repairs and acceptance never overlap.');

            return self::FAILURE;
        }

        $coverage    = (float) $this->option('coverage');
        $minPop      = (int) $this->option('min-pop');
        $minAreaFrac = (float) $this->option('min-area-frac');
        $dryRun      = (bool) $this->option('dry-run');

        $candidates = DB::select('
            SELECT j.id, j.name, j.slug, j.iso_code, j.adm_level, j.population,
                   k.child_sum, k.n_children
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
            'Coverage-gapped parents (children-sum < %.0f%% of own, remainder ≥ %s): %d candidates',
            $coverage * 100, number_format($minPop), count($candidates)
        ));

        $created = 0;
        $skippedNoise = 0;
        $skippedEmpty = 0;
        $byLevel = [];

        foreach ($candidates as $i => $p) {
            // The geometric remainder — computed per parent, validity-repaired,
            // polygonal parts only (dust lines/points dropped).
            $rem = DB::selectOne('
                WITH kids AS (
                    SELECT ST_MakeValid(ST_Union(geom)) AS g
                      FROM jurisdictions
                     WHERE parent_id = ? AND deleted_at IS NULL AND geom IS NOT NULL
                ),
                diff AS (
                    SELECT ST_Multi(ST_CollectionExtract(
                               ST_MakeValid(ST_Difference((SELECT geom FROM jurisdictions WHERE id = ?), kids.g)), 3
                           )) AS g
                      FROM kids
                )
                SELECT (d.g IS NOT NULL AND NOT ST_IsEmpty(d.g)) AS has_geom,
                       COALESCE(ST_Area(d.g), 0) AS rem_area,
                       ST_Area((SELECT geom FROM jurisdictions WHERE id = ?)) AS parent_area
                  FROM diff d
            ', [$p->id, $p->id, $p->id]);

            $areaFrac = ($rem->parent_area ?? 0) > 0 ? $rem->rem_area / $rem->parent_area : 0.0;

            if (! $rem->has_geom || $rem->rem_area <= 0) {
                $skippedEmpty++;
                continue;
            }
            if ($areaFrac < $minAreaFrac) {
                // Children tile the territory; only the POPULATIONS disagree —
                // the settled noise class. Never harmonized, never synthesized.
                $skippedNoise++;
                continue;
            }

            $remainderPop = max(0, (int) $p->population - (int) $p->child_sum);

            if ($dryRun) {
                if ($i < 25) {
                    $this->line(sprintf(
                        '  ADM%d %-40s pop %s | children %d cover %s | remainder %s (%.0f%% of area)',
                        $p->adm_level, $p->name, number_format((int) $p->population),
                        $p->n_children, number_format((int) $p->child_sum),
                        number_format($remainderPop), $areaFrac * 100
                    ));
                }
                $created++;
                $byLevel[$p->adm_level] = ($byLevel[$p->adm_level] ?? 0) + 1;
                continue;
            }

            DB::transaction(function () use ($p, $remainderPop) {
                $id = (string) Str::uuid();
                // Geometry computed and inserted SERVER-SIDE (WKB does not
                // survive a PDO round trip) — same CTE as the probe above.
                DB::insert('
                    WITH kids AS (
                        SELECT ST_MakeValid(ST_Union(geom)) AS g
                          FROM jurisdictions
                         WHERE parent_id = ? AND deleted_at IS NULL AND geom IS NOT NULL
                    ),
                    diff AS (
                        SELECT ST_Multi(ST_CollectionExtract(
                                   ST_MakeValid(ST_Difference((SELECT geom FROM jurisdictions WHERE id = ?), kids.g)), 3
                               )) AS g
                          FROM kids
                    )
                    INSERT INTO jurisdictions
                        (id, name, slug, iso_code, adm_level, parent_id, population,
                         is_active, is_civic_active, source, parent_assigned_via,
                         official_languages, timezone, geom, centroid, created_at, updated_at)
                    SELECT ?, ?, ?, ?, ?, ?, ?, true, true, \'synthesized-remainder\', \'remainder_synthesis\',
                           \'[]\', \'UTC\', d.g, ST_PointOnSurface(d.g), now(), now()
                      FROM diff d
                     WHERE d.g IS NOT NULL AND NOT ST_IsEmpty(d.g)
                ', [
                    $p->id,
                    $p->id,
                    $id,
                    mb_substr($p->name, 0, 200).' (Remainder)',
                    Str::slug($p->slug.'-remainder-'.substr($id, 0, 8)),
                    $p->iso_code,
                    (int) $p->adm_level + 1,
                    $p->id,
                    $remainderPop,
                ]);

                DB::table('geodata_repairs')->insert([
                    'id'          => (string) Str::uuid(),
                    'action'      => 'synthesize_remainder',
                    'target_slug' => $p->slug,
                    'params'      => json_encode([
                        'parent_id'       => (string) $p->id,
                        'created_id'      => $id,
                        'remainder_pop'   => $remainderPop,
                        'children_sum'    => (int) $p->child_sum,
                        'parent_pop'      => (int) $p->population,
                        'revert'          => 'soft-delete created_id',
                    ]),
                    'applied_at'  => now(),
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            });

            $created++;
            $byLevel[$p->adm_level] = ($byLevel[$p->adm_level] ?? 0) + 1;
        }

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] would synthesize' : 'Synthesized')." {$created} remainder children"
            .' | skipped as population-noise (area covered): '.$skippedNoise
            .' | skipped empty-geometry: '.$skippedEmpty);
        foreach ($byLevel as $lvl => $n) {
            $this->line("  under ADM{$lvl} parents: {$n}");
        }

        return self::SUCCESS;
    }
}
