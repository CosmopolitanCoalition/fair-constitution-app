<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RecolorDistrictsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Allow up to 10 minutes — the ST_Intersects scan over a fully-populated
     * Earth-scope map can take several minutes on first run.
     */
    public int $timeout    = 600;
    public int $tries      = 3;
    public int $retryAfter = 660;  // longer than timeout so a kill/restart doesn't cause an instant retry

    public function __construct(
        private readonly string  $legislatureId,
        private readonly ?string $mapId,
    ) {}

    public function handle(): void
    {
        try {
            $this->runRecolor();
        } finally {
            Cache::forget("legislature.{$this->legislatureId}.mass_running");
            Cache::forget("legislature.{$this->legislatureId}.recolor_progress");
        }
    }

    private function progress(string $phase, int $total, int $startedAt): void
    {
        Cache::put("legislature.{$this->legislatureId}.recolor_progress", [
            'phase'      => $phase,
            'total'      => $total,
            'started_at' => $startedAt,
        ], 7200);
    }

    private function runRecolor(): void
    {
        $startedAt = now()->timestamp;

        // Fetch all district IDs for this map
        $q = DB::table('legislature_districts')
            ->where('legislature_id', $this->legislatureId)
            ->whereNull('deleted_at');
        if ($this->mapId !== null) {
            $q->where('map_id', $this->mapId);
        }
        $districtIds = $q->pluck('id')->toArray();

        if (count($districtIds) < 2) return;

        // UUIDs contain only [0-9a-f-] — safe to interpolate into the array literal.
        $idsLiteral = "ARRAY['" . implode("','", $districtIds) . "']::uuid[]";

        // ETL-style adjacency query: start the join from jurisdictions so PostGIS
        // can use the GiST index on geom.  j1.id < j2.id avoids duplicate pairs.
        // No ST_Simplify — the GiST index provides spatial pruning without it.
        $this->progress('adjacency', count($districtIds), $startedAt);
        $edges = DB::select("
            SELECT DISTINCT ldj1.district_id AS d1, ldj2.district_id AS d2
            FROM jurisdictions j1
            JOIN jurisdictions j2
                ON ST_Intersects(j1.geom, j2.geom)
               AND j1.id < j2.id
               AND j1.geom  IS NOT NULL
               AND j2.geom  IS NOT NULL
               AND j1.deleted_at IS NULL
               AND j2.deleted_at IS NULL
            JOIN legislature_district_jurisdictions ldj1
                ON ldj1.jurisdiction_id = j1.id
               AND ldj1.district_id = ANY({$idsLiteral})
            JOIN legislature_district_jurisdictions ldj2
                ON ldj2.jurisdiction_id = j2.id
               AND ldj2.district_id = ANY({$idsLiteral})
            WHERE ldj1.district_id != ldj2.district_id
        ");

        // Build adjacency map
        $adj = array_fill_keys($districtIds, []);
        foreach ($edges as $e) {
            if (!in_array($e->d2, $adj[$e->d1])) $adj[$e->d1][] = $e->d2;
            if (!in_array($e->d1, $adj[$e->d2])) $adj[$e->d2][] = $e->d1;
        }

        // Greedy 7-coloring — most-connected districts first
        $this->progress('coloring', count($districtIds), $startedAt);
        uasort($adj, fn($a, $b) => count($b) - count($a));
        $colors = [];
        foreach (array_keys($adj) as $did) {
            $used = array_values(array_unique(array_filter(
                array_map(fn($nb) => $colors[$nb] ?? null, $adj[$did]),
                fn($c) => $c !== null
            )));
            $colors[$did] = 0;
            for ($c = 0; $c < 7; $c++) {
                if (!in_array($c, $used)) { $colors[$did] = $c; break; }
            }
        }

        // Persist — batch by color_index (at most 7 UPDATE statements)
        $this->progress('persisting', count($districtIds), $startedAt);
        $byColor = [];
        foreach ($colors as $did => $color) {
            $byColor[$color][] = $did;
        }
        DB::transaction(function () use ($byColor) {
            foreach ($byColor as $color => $ids) {
                DB::table('legislature_districts')
                    ->whereIn('id', $ids)
                    ->update(['color_index' => $color]);
            }
        });

        // Invalidate cached GeoJSON — color_index is included in revealed.geojson payload.
        // Use tag-based flush (same mechanism as flushRevealedCache in the controller).
        $mapKey   = $this->mapId ?? 'null';
        $scopeIds = DB::table('legislature_districts')
            ->where('legislature_id', $this->legislatureId)
            ->whereNull('deleted_at')
            ->when($this->mapId !== null, fn($q) => $q->where('map_id', $this->mapId))
            ->distinct()
            ->pluck('jurisdiction_id');
        foreach ($scopeIds as $sid) {
            Cache::tags(["revealed.{$this->legislatureId}.{$mapKey}." . ($sid ?? '')])->flush();
        }
    }
}
