<?php

namespace App\Console\Commands;

use App\Http\Controllers\LegislatureController;
use App\Jobs\MapScopeLaneJob;
use App\Models\Jurisdiction;
use App\Models\Legislature;
use App\Models\LegislatureDistrictMap;
use App\Support\HostCapacity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE PARADIGM-COMPLIANT MAP SWEEP coordinator (operator order 2026-08-29).
 *
 * Enumerates a map's scope closure ONCE into the cost-ordered work pile
 * (map_scope_items), then dispatches host-derived lanes that each draw one
 * scope per process and exit. The coordinator never draws. Re-running on the
 * same map resumes: existing done items stand, open ones re-enter the pile.
 *
 *   php artisan map:sweep earth-0-earth --map-name="gate24 Earth v3"
 *   php artisan map:sweep earth-0-earth --map=<uuid>        (resume/refill)
 */
class MapSweepCommand extends Command
{
    protected $signature = 'map:sweep
                            {jurisdiction : slug or UUID of the map root}
                            {--map= : existing map UUID to sweep/resume}
                            {--map-name= : create a fresh draft map with this name}
                            {--lanes= : override lane count (default: host-derived)}';

    protected $description = 'Draw one legislature map as a multi-lane per-scope pile (chunkable, resumable, host-derived)';

    public function handle(): int
    {
        $j = Jurisdiction::query()
            ->where('slug', $this->argument('jurisdiction'))
            ->orWhere('id', Str::isUuid($this->argument('jurisdiction')) ? $this->argument('jurisdiction') : Str::uuid())
            ->first();
        if ($j === null) {
            $this->error('No such jurisdiction.');

            return 1;
        }
        $leg = Legislature::query()->where('jurisdiction_id', $j->id)->whereNull('deleted_at')->first();
        if ($leg === null) {
            $this->error('No legislature for that jurisdiction (seed it first).');

            return 1;
        }

        if ($this->option('map')) {
            $map = LegislatureDistrictMap::query()->findOrFail($this->option('map'));
        } else {
            $map = LegislatureDistrictMap::create([
                'legislature_id' => $leg->id,
                'name'           => $this->option('map-name') ?: ('sweep '.$j->slug.' '.now()->format('md-Hi')),
                'status'         => LegislatureDistrictMap::STATUS_DRAFT,
            ]);
        }

        // The scope closure, exactly as the monolithic sweep resolved it.
        $legRow    = DB::table('legislatures')->where('id', $leg->id)->first();
        $rootPop   = \App\Services\Districting\LeafGiantResolver::shareBase((string) $j->id);
        $rootQuota = $rootPop / max((int) $legRow->type_a_seats, 1);
        $scopeIds  = app(LegislatureController::class)->resolveMassScopeIds(
            (string) $leg->id, $legRow, (string) $j->id, 'map_plus_children_all', $rootQuota, (string) $map->id
        );

        // est_cost: children count weights the drawing combinatorics,
        // population weights geometry/attribution load. Cheap, order-true.
        $rows = DB::select(
            "SELECT p.id,
                    (SELECT COUNT(*) FROM jurisdictions c
                      WHERE c.parent_id = p.id AND c.deleted_at IS NULL) * 1000
                    + COALESCE(p.population, 0) / 100000 AS est
               FROM jurisdictions p WHERE p.id IN ('".implode("','", array_map('strval', $scopeIds))."')"
        );

        $inserted = 0;
        foreach ($rows as $r) {
            $inserted += DB::table('map_scope_items')->insertOrIgnore([
                'id'             => (string) Str::uuid(),
                'legislature_id' => (string) $leg->id,
                'map_id'         => (string) $map->id,
                'scope_id'       => (string) $r->id,
                'est_cost'       => (int) $r->est,
                'status'         => 'pending',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        $open = DB::table('map_scope_items')
            ->where('map_id', $map->id)->whereIn('status', ['pending', 'running'])->count();
        $lanes = (int) ($this->option('lanes') ?: max(2, min(HostCapacity::autoscaleWorkers(), $open)));
        for ($i = 0; $i < $lanes; $i++) {
            MapScopeLaneJob::dispatch((string) $map->id, $i);
        }

        $this->info("map={$map->id} scopes=".count($scopeIds)." new_items={$inserted} open={$open} lanes={$lanes}");

        return 0;
    }
}
