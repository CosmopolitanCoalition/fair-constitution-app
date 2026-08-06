<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * geodata:repair-country-names — align country-level jurisdiction names (and
 * slugs) with the curated geoboundary_metadata country name.
 *
 * WHY (2026-08-06, operator-caught on the live viewer): the loader's ADM0
 * name override read the wrong dict key (`name` vs the CSV's `boundaryName`)
 * and silently never fired, so every country row kept the boundary FILE's
 * shapeName. 42 rows drifted from canonical — most as long-form variants or
 * source typos ("Bangledesh"), two as WRONG-ENTITY names where the ADM0
 * file names the country after one of its own children. The loader is fixed
 * for future fresh runs; this command repairs a live planet in place.
 *
 * Repair-window law: only legal while the map is NOT accepted and setup is
 * incomplete — after acceptance the dataset is load-bearing and renames go
 * through governance, not ops.
 *
 * Dry-run by default; --apply writes. Slugs are regenerated from the new
 * name in the loader's country convention (iso-1-kebab), with a collision
 * suffix. Child rows are untouched — their names and slugs are their own.
 */
class GeodataRepairCountryNamesCommand extends Command
{
    protected $signature = 'geodata:repair-country-names
                            {--apply : Write the changes (default: dry-run report)}';

    protected $description = 'Align country-level jurisdiction names/slugs with the curated metadata country name (repair window only)';

    public function handle(): int
    {
        $instance = DB::table('instance_settings')->whereNull('deleted_at')->first();
        if ($instance === null) {
            $this->error('No instance_settings row — bootstrap not complete.');

            return self::FAILURE;
        }
        if ($instance->setup_completed_at !== null || $instance->map_accepted_at !== null) {
            $this->error('Repair window is closed (map accepted or setup complete) — '
                .'renames now go through governance, not ops.');

            return self::FAILURE;
        }

        // The country name rides every one of an iso's metadata rows — take
        // the lowest-level row per iso as the canonical carrier.
        $plan = DB::select(<<<'SQL'
            WITH canon AS (
                SELECT DISTINCT ON (iso_code) iso_code, name
                  FROM geoboundary_metadata
                 WHERE COALESCE(name, '') <> ''
                   AND lower(name) NOT IN ('unknown', 'none', 'null')
                 ORDER BY iso_code, adm_level
            )
            SELECT j.id, j.iso_code, j.name AS old_name, j.slug AS old_slug,
                   c.name AS new_name
              FROM jurisdictions j
              JOIN canon c ON c.iso_code = j.iso_code
             WHERE j.adm_level = 1 AND j.deleted_at IS NULL
               AND j.name IS DISTINCT FROM c.name
             ORDER BY j.iso_code
        SQL);

        if ($plan === []) {
            $this->info('All country names already match the canonical metadata — nothing to do.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($plan as $p) {
            $slug = strtolower($p->iso_code).'-1-'.Str::slug($p->new_name);
            $n = 2;
            while (DB::table('jurisdictions')->where('slug', $slug)
                    ->where('id', '!=', $p->id)->exists()) {
                $slug = strtolower($p->iso_code).'-1-'.Str::slug($p->new_name).'-'.$n++;
            }
            $rows[] = ['id' => $p->id, 'iso' => $p->iso_code,
                       'old_name' => $p->old_name, 'new_name' => $p->new_name,
                       'old_slug' => $p->old_slug, 'new_slug' => $slug];
        }

        $this->table(['ISO', 'Old name', 'New name', 'Old slug', 'New slug'],
            array_map(fn ($r) => [$r['iso'], $r['old_name'], $r['new_name'],
                                  $r['old_slug'], $r['new_slug']], $rows));

        if (! $this->option('apply')) {
            $this->info(sprintf('%d country row(s) would change. Re-run with --apply to write.', count($rows)));

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows as $r) {
                DB::table('jurisdictions')->where('id', $r['id'])->update([
                    'name' => $r['new_name'], 'slug' => $r['new_slug'],
                    'updated_at' => now(),
                ]);
            }
        });

        foreach ($rows as $r) {
            Log::info(sprintf('geodata:repair-country-names — %s "%s" (%s) → "%s" (%s)',
                $r['iso'], $r['old_name'], $r['old_slug'], $r['new_name'], $r['new_slug']));
        }
        $this->info(sprintf('Renamed %d country row(s) to canonical metadata names.', count($rows)));

        return self::SUCCESS;
    }
}
