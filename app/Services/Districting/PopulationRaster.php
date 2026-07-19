<?php

namespace App\Services\Districting;

use App\Services\ConstitutionalDefaults;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Phase H (H0) — THE runtime PHP entry point to the WorldPop raster.
 *
 * `population_within(iso, geom, year)` and `population_within_multi(geom, year)`
 * are fully built SQL functions, but until now they had NO PHP runtime caller —
 * they were used only by ETL/migration population-correction passes that seed
 * `jurisdictions.population`; the runtime districting path reads that STORED
 * integer. The manual-draw and shortest-splitline tools are the first runtime
 * consumers: they need the population inside an ARBITRARY drawn/cut polygon.
 * This service is the missing seam (design §4.3).
 *
 * It returns only AGGREGATE population (a BIGINT sum at 100 m resolution) —
 * never raw locations or individual records (§5 P1 / P2). Callers gate access
 * (R-08) and floor tiny polygons before exposing a count to a human.
 */
class PopulationRaster
{
    /**
     * Aggregate population inside a polygon, within one country's rasters.
     * $geoJson is a GeoJSON geometry string (EPSG:4326, as the draw tool emits).
     */
    public function populationWithin(string $iso, string $geoJson, int $year = 2023): int
    {
        $row = DB::selectOne(
            'SELECT population_within(?, ST_GeomFromGeoJSON(?), ?::smallint) AS pop',
            [$iso, $geoJson, $year]
        );

        return (int) ($row->pop ?? 0);
    }

    /**
     * Aggregate population inside a polygon across every intersecting country
     * raster, de-duplicating border-overlap pixels (MAX-per-pixel). Use this for
     * cut/drawn shapes that may straddle a border or sit in a union scope.
     */
    public function populationWithinMulti(string $geoJson, int $year = 2023): int
    {
        $row = DB::selectOne(
            'SELECT population_within_multi(ST_GeomFromGeoJSON(?), ?::smallint) AS pop',
            [$geoJson, $year]
        );

        return (int) ($row->pop ?? 0);
    }

    /**
     * The giant's WorldPop pixels as a flat [[lng, lat, people], ...] grid,
     * clipped to its boundary — computed ONCE and cached. This is what makes
     * the split-line tools interactive: every candidate cut (manual line OR an
     * automatic shortest-split-line sweep) is then an O(pixels) blade-side sum
     * in PHP instead of a fresh PostGIS raster scan per cut. Aggregate-only
     * (pixel/cell population sums) — never individual records.
     *
     * ADAPTIVE RESOLUTION LADDER (2026-07-17 — the LA-County fix): the native
     * 100 m grid was sized for a castello (~10 km², ~1.5k pixels); a giant
     * COUNTY like Los Angeles (10,616 km²) is ~1M pixels — minutes of PHP
     * sorting per candidate blade, a multi-hundred-MB cache entry, and the
     * browser's fetch timeout long gone. Cells are SUM-binned server-side to a
     * size chosen from the giant's geographic area, keeping the grid ≤ ~50k
     * cells at any scale. DETERMINISM holds: the ladder is fixed in code and
     * the bin size derives from the geometry alone, so every mesh node
     * produces the identical grid → identical plan_hash. Balance quality is
     * untouched in practice: a dense 0.005° cell holds a few thousand people
     * vs a ~500k-person seat quota — two orders of magnitude inside the 5%
     * per-seat guard — and the F-ELB-008 handler re-measures every filed
     * piece at FULL raster resolution anyway. Small scopes (≤ 300 km², the
     * San-Marino class) stay native, so their shipped plan hashes are
     * byte-identical to before.
     */
    public function pixelGrid(string $scopeId, int $year = 2023): array
    {
        $areaKm2 = (float) (DB::selectOne(
            'SELECT ST_Area(geom::geography) / 1e6 AS km2 FROM jurisdictions WHERE id = ?',
            [$scopeId]
        )->km2 ?? 0.0);

        $cell = match (true) {
            $areaKm2 <= 300.0    => null,    // native 100 m (castello / small county)
            $areaKm2 <= 3000.0   => 0.002,   // ~200 m cells
            $areaKm2 <= 30000.0  => 0.005,   // ~500 m cells (LA County → ~25k cells, 7 s once)
            $areaKm2 <= 300000.0 => 0.02,    // ~2 km cells (childless giant states)
            default              => 0.05,    // ~5 km cells (continental-class)
        };
        $cellKey = $cell === null ? 'native' : rtrim(rtrim(sprintf('%.3f', $cell), '0'), '.');

        // v2 key: the cell size is part of the identity, so a rescaled ladder
        // (or the pre-ladder native entries) can never serve a stale grid.
        //
        // Autoscale bypass (cycle-2): a full-scale run plans ~473k root-leaf
        // scopes exactly ONCE each — rememberForever would balloon Redis by
        // gigabytes for entries nobody reads twice. Interactive callers keep
        // the cache (the mapper's blade sweeps re-read the grid constantly).
        $compute = function () use ($scopeId, $year, $cell) {
            // Per-tile clip + pixel-centroids of the giant's OWN country raster —
            // NO ST_Union (unioning border-overlapping tiles is ~500× slower). A
            // childless leaf giant sits inside one country, so iso_code suffices.
            // (A rare cross-border giant would need the multi path — out of scope.)
            $inner = "WITH g AS (SELECT ST_MakeValid(geom) AS geom, iso_code FROM jurisdictions WHERE id = ?)
                 SELECT ST_X(pc.geom) AS x, ST_Y(pc.geom) AS y, pc.val AS val
                   FROM worldpop_rasters r
                   CROSS JOIN g
                   CROSS JOIN LATERAL ST_PixelAsCentroids(ST_Clip(r.rast, g.geom, TRUE), 1) AS pc
                  WHERE r.iso_code = g.iso_code
                    AND r.year = ?::smallint
                    AND ST_Intersects(r.rast, g.geom)
                    AND pc.val > 0";

            if ($cell === null) {
                $rows = DB::select($inner, [$scopeId, $year]);
            } else {
                // SUM-preserving bin aggregation at cell centers. floor()-based
                // bin ids are deterministic; population is conserved exactly.
                $rows = DB::select(
                    "SELECT (floor(x / {$cell}) + 0.5) * {$cell} AS x,
                            (floor(y / {$cell}) + 0.5) * {$cell} AS y,
                            SUM(val) AS val
                       FROM ({$inner}) px
                      GROUP BY floor(x / {$cell}), floor(y / {$cell})",
                    [$scopeId, $year]
                );
            }

            return array_map(fn ($r) => [(float) $r->x, (float) $r->y, (float) $r->val], $rows);
        };

        if (\App\Support\AutoscaleContext::active()) {
            return $compute();
        }

        return Cache::rememberForever("districting.pixelgrid.v2.{$scopeId}.{$year}.{$cellKey}", $compute);
    }

    /**
     * Does the raster MEANINGFULLY cover this scope? The gate is
     * POPULATION-based, never a bare tile-intersects test: a geometry whose
     * edge merely touches a tile clips to a one-pixel strip — the planner
     * would then balance cuts against a few percent of the real population
     * and the filing gate would measure pieces at 0 people. Coverage holds
     * when the raster population over the scope's own geometry is nonzero
     * AND at least 10% of the stored population (a genuinely partial
     * cross-border undercount, typically small, stays on the raster path —
     * the accepted posture; a boundary-touch or fully-missed geometry falls
     * to the area-proportional grid). Generalized — the trigger is pure
     * measurement, never a country list. Memoized per process.
     */
    public function hasRasterCoverage(string $scopeId, int $year = 2023): bool
    {
        [$rasterPop, $storedPop] = $this->coverageStats($scopeId, $year);

        return $rasterPop > 0
            && ($storedPop <= 0 || $rasterPop >= 0.1 * $storedPop);
    }

    /**
     * The scope's raster mass over its own geometry + its stored population
     * — the two oracles every measurement reconciles. Memoized per process.
     *
     * @return array{0: int, 1: int} [rasterPop, storedPop]
     */
    private function coverageStats(string $scopeId, int $year): array
    {
        static $memo = [];
        $key = "{$scopeId}.{$year}";
        if (! isset($memo[$key])) {
            $row = DB::selectOne(
                'SELECT COALESCE(population_within_multi(ST_Multi(ST_MakeValid(j.geom)), ?::smallint), 0) AS raster_pop,
                        GREATEST(COALESCE(j.population, 0), 0) AS stored_pop
                   FROM jurisdictions j
                  WHERE j.id = ? AND j.geom IS NOT NULL',
                [$year, $scopeId]
            );
            $memo[$key] = [(int) ($row->raster_pop ?? 0), (int) ($row->stored_pop ?? 0)];
        }

        return $memo[$key];
    }

    /**
     * AREA-PROPORTIONAL grid (cycle-2 fallback, 2026-07-19): the same
     * [[lng, lat, people], …] shape as pixelGrid, for scopes with ZERO
     * raster coverage — cells from ST_SquareGrid over the geometry, each
     * carrying population × (its clipped area / the scope's area). The
     * stored jurisdictions.population is the total; distribution is
     * uniform by land area. Deterministic from geometry + stored
     * population alone, so plan_hash reproducibility holds. Empty when the
     * scope has no geometry or no population — those refusals stay honest.
     */
    public function areaGrid(string $scopeId, int $year = 2023): array
    {
        $areaKm2 = (float) (DB::selectOne(
            'SELECT ST_Area(geom::geography) / 1e6 AS km2 FROM jurisdictions WHERE id = ?',
            [$scopeId]
        )->km2 ?? 0.0);

        // The pixelGrid ladder, with a floor: no native 100 m tier exists
        // without a raster, so small scopes take the finest synthetic cell.
        $cell = match (true) {
            $areaKm2 <= 3000.0   => 0.002,
            $areaKm2 <= 30000.0  => 0.005,
            $areaKm2 <= 300000.0 => 0.02,
            default              => 0.05,
        };

        $compute = function () use ($scopeId, $cell) {
            $rows = DB::select("
                WITH g AS (
                    SELECT ST_MakeValid(geom) AS geom, GREATEST(COALESCE(population, 0), 0) AS pop
                      FROM jurisdictions WHERE id = ?
                ),
                cells AS (
                    SELECT ST_Intersection(sq.geom, g.geom) AS cg, g.pop,
                           ST_Area(g.geom) AS total_area
                      FROM g
                     CROSS JOIN LATERAL ST_SquareGrid({$cell}, g.geom) AS sq
                     WHERE ST_Intersects(sq.geom, g.geom)
                )
                SELECT ST_X(ST_Centroid(cg)) AS x, ST_Y(ST_Centroid(cg)) AS y,
                       pop * (ST_Area(cg) / NULLIF(total_area, 0)) AS val
                  FROM cells
                 WHERE NOT ST_IsEmpty(cg) AND ST_Area(cg) > 0
            ", [$scopeId]);

            $grid = [];
            foreach ($rows as $r) {
                if ((float) $r->val > 0) {
                    $grid[] = [(float) $r->x, (float) $r->y, (float) $r->val];
                }
            }

            return $grid;
        };

        if (\App\Support\AutoscaleContext::active()) {
            return $compute();
        }

        return Cache::rememberForever("districting.areagrid.v1.{$scopeId}", $compute);
    }

    /**
     * Grid with the coverage fallback: raster pixels when the raster
     * meaningfully covers the scope (hasRasterCoverage — the SAME gate the
     * F-ELB-008 measurement uses, so a planned piece can never be refused
     * by a differently-gated measurer), the area-proportional grid
     * otherwise. The planners' one entry point.
     */
    public function gridWithFallback(string $scopeId, int $year = 2023): array
    {
        if ($this->hasRasterCoverage($scopeId, $year)) {
            return $this->pixelGrid($scopeId, $year);
        }

        return $this->areaGrid($scopeId, $year);
    }

    /**
     * Measure a drawn piece's population with the same fallback the
     * planners use: raster sum when the SCOPE has coverage; otherwise
     * population × area-share (provenance 'area_proportional'). Without
     * this, the F-ELB-008 handler's band gate would refuse every piece the
     * area-planner lawfully cut.
     *
     * @return array{pop: int, source: string}
     */
    public function measureWithFallback(string $scopeId, string $geoJson, int $year = 2023): array
    {
        if ($this->hasRasterCoverage($scopeId, $year)) {
            // ONE denominator (2026-07-19, the 10-seat-village review class):
            // the raster is the DISTRIBUTION oracle, the stored population is
            // the AMOUNT oracle — it sized the legislature, so it is the mass
            // the seat quota divides. A piece measures as its SHARE of the
            // scope's raster mass × the stored population; where raster and
            // stored agree this is the identity, and where they drift (the
            // accepted geodata noise) a share-balanced plan measures exactly
            // as the planner balanced it instead of being band-refused by
            // the offset.
            [$rasterPop, $storedPop] = $this->coverageStats($scopeId, $year);
            $pieceRaw = $this->populationWithinMulti($geoJson, $year);
            $pop = ($rasterPop > 0 && $storedPop > 0)
                ? (int) round($storedPop * $pieceRaw / $rasterPop)
                : $pieceRaw;

            return ['pop' => $pop, 'source' => 'worldpop_raster'];
        }

        $row = DB::selectOne('
            SELECT GREATEST(COALESCE(j.population, 0), 0)
                   * (ST_Area(ST_Intersection(ST_MakeValid(ST_GeomFromGeoJSON(?)), ST_MakeValid(j.geom)))
                      / NULLIF(ST_Area(ST_MakeValid(j.geom)), 0)) AS pop
              FROM jurisdictions j
             WHERE j.id = ?
        ', [$geoJson, $scopeId]);

        return ['pop' => (int) round((float) ($row->pop ?? 0)), 'source' => 'area_proportional'];
    }

    /**
     * Split a pixel grid by a directed blade A→B: returns [popLeft, popRight] by
     * the sign of the cross product (which side of the line each pixel sits on).
     * O(pixels), no database — the hot path of every split evaluation.
     */
    public static function splitByBlade(array $grid, float $ax, float $ay, float $bx, float $by): array
    {
        $dirx = $bx - $ax;
        $diry = $by - $ay;
        $left = 0.0;
        $right = 0.0;
        foreach ($grid as [$x, $y, $v]) {
            if ($dirx * ($y - $ay) - $diry * ($x - $ax) >= 0) {
                $left += $v;
            } else {
                $right += $v;
            }
        }

        return [$left, $right];
    }

    /**
     * The local quota: population per seat at a scope. A drawn piece's implied
     * fractional seats = its population / this quota (the same quota the
     * LegislatureController computes for the scope).
     */
    public function quota(int $population, int $seats): float
    {
        return $population / max($seats, 1);
    }

    /** Implied fractional seats for a population against a quota. */
    public function impliedSeats(int $population, float $quota): float
    {
        return $quota > 0 ? $population / $quota : 0.0;
    }

    /**
     * Whether a rounded seat count sits in the resolved band for a scope. The
     * band (default 5/9) is amendable per jurisdiction — resolved, never literal.
     */
    public function inBand(int $seats, ?string $jurisdictionId = null): bool
    {
        $floor   = ConstitutionalDefaults::floor($jurisdictionId);
        $ceiling = ConstitutionalDefaults::ceiling($jurisdictionId);

        return $seats >= $floor && $seats <= $ceiling;
    }
}
