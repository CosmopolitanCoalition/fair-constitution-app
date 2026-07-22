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
            $inner = "SELECT ST_X(pc.geom) AS x, ST_Y(pc.geom) AS y, pc.val AS val
                   FROM worldpop_rasters r
                   CROSS JOIN g
                   CROSS JOIN LATERAL ST_PixelAsCentroids(ST_Clip(r.rast, g.geom, TRUE), 1) AS pc
                  WHERE r.iso_code = g.iso_code
                    AND r.year = ?::smallint
                    AND ST_Intersects(r.rast, g.geom)
                    AND pc.val > 0";

            $pts = $cell === null
                ? $inner
                // SUM-preserving bin aggregation at cell centers. floor()-based
                // bin ids are deterministic; population is conserved exactly.
                : "SELECT (floor(x / {$cell}) + 0.5) * {$cell} AS x,
                          (floor(y / {$cell}) + 0.5) * {$cell} AS y,
                          SUM(val) AS val
                     FROM ({$inner}) px
                    GROUP BY floor(x / {$cell}), floor(y / {$cell})";

            // EVERY POINT INSIDE ITS TERRITORY (2026-07-21, the arctic
            // all-or-nothing class): a coastal bin's center — and even a
            // native pixel centroid on a ragged shore — can fall in the SEA.
            // Avannaata carried 53% of its population at offshore bin
            // centers: the planner split that mass by blade-sign while the
            // filing measurement's nearest-piece recovery assigned each
            // outside point WHOLESALE to one piece → one piece measured the
            // whole scope (18.00 quotas) and its sibling zero. An outside
            // point now snaps to the closest point ON the territory, so
            // ST_Covers-by-piece and blade-side agree by construction
            // (pieces partition the scope), and no mass is ever placed in
            // the water. Deterministic; sums conserved exactly.
            $rows = DB::select(
                "WITH g AS (SELECT ST_MakeValid(geom) AS geom, iso_code FROM jurisdictions WHERE id = ?)
                 SELECT CASE WHEN ST_Covers(g.geom, q.p) THEN q.x
                             ELSE ST_X(ST_ClosestPoint(g.geom, q.p)) END AS x,
                        CASE WHEN ST_Covers(g.geom, q.p) THEN q.y
                             ELSE ST_Y(ST_ClosestPoint(g.geom, q.p)) END AS y,
                        q.val
                   FROM g
                  CROSS JOIN LATERAL (
                        SELECT pts.x, pts.y, pts.val,
                               ST_SetSRID(ST_MakePoint(pts.x, pts.y), 4326) AS p
                          FROM ({$pts}) pts
                  ) q",
                [$scopeId, $year]
            );

            return array_map(fn ($r) => [(float) $r->x, (float) $r->y, (float) $r->val], $rows);
        };

        if (\App\Support\AutoscaleContext::active()) {
            // PER-PROCESS MEMO (2026-07-21, the mega-band 3× regression): the
            // Redis bypass is right (473k one-shot scopes would balloon the
            // cache), but measurement parity re-reads the grid PER MEASURED
            // PIECE — so a 2-district scope recomputed the SAME grid three
            // times (planner + one per filed piece). On a mega-area scope
            // whose binning query runs tens of minutes, that tripling stalled
            // the run (all 10 workers observed stuck in pixelGrid, 29-min
            // single queries). One worker processes one scope at a time, so a
            // last-2 memo makes planner and handler share ONE computation —
            // bytes identical, no semantic change, bounded memory.
            static $memo = [];
            $mkey = "{$scopeId}.{$year}.{$cellKey}";
            if (! isset($memo[$mkey])) {
                if (count($memo) >= 2) {
                    $memo = [];
                }
                $memo[$mkey] = $compute();
            }

            return $memo[$mkey];
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
     * SUB-PIXEL DIVISION (operator ruling 2026-07-19): a district boundary
     * is vector geometry — only the MEASUREMENT was pixel-atomic. For
     * scopes below this many native pixels, each pixel is divided into a
     * 4×4 grid of subcells carrying value/16 at fixed offsets from the
     * tile's own scale: between-pixel density structure is preserved (what
     * WorldPop knows), within-pixel mass is uniform by area (the honest
     * limit of the data). The planner's blades/cells and the F-ELB-008
     * measurement both run on the SAME subcell basis, so a plan can never
     * be refused by a coarser scorekeeper. Deterministic from geometry +
     * pixel values + tile scale.
     */
    public const SUBPIXEL_THRESHOLD = 400;

    /**
     * CONCENTRATION EXTENSION (2026-07-21, the atomic-pixel review class): a
     * scope can hold ample pixels yet keep whole seat-quotas locked inside
     * ONE cell — a desert town in a giant municipality (Bilma, McKinlay:
     * 500-1000 native pixels, one pixel holding 4-8 quotas). Pixel-atomic
     * measurement then refuses every blade at every angle: no cut passes the
     * 5% guard when moving one pixel moves several quotas. Such scopes take
     * the sub-pixel basis too: dominant cell ≥ 3% of grid mass (≈ half a
     * quota at S=17), bounded to grids the 16× expansion keeps at
     * interactive scale (probed: 45-46 of 48 blade candidates unlock).
     */
    public const SUBPIXEL_CONCENTRATION = 0.03;

    private const SUBPIXEL_CONCENTRATION_MAX_PIXELS = 4000;

    /** Pure concentration test over a grid — public so the pin can interrogate it. */
    public static function concentrated(array $grid): bool
    {
        $n = count($grid);
        if ($n >= self::SUBPIXEL_CONCENTRATION_MAX_PIXELS) {
            return false;
        }
        $total = 0.0;
        $max = 0.0;
        foreach ($grid as [, , $v]) {
            $total += $v;
            if ($v > $max) {
                $max = $v;
            }
        }

        return $total > 0.0 && $max >= self::SUBPIXEL_CONCENTRATION * $total;
    }

    /** Is this scope in the sub-resolution class? Memoized per process. */
    public function subResolution(string $scopeId, int $year = 2023): bool
    {
        return $this->basis($scopeId, $year) === 'subpixel';
    }

    /** The scope's raster tile scale [sx, sy] (degrees per pixel). */
    private function tileScale(string $scopeId, int $year): array
    {
        static $memo = [];
        $key = "{$scopeId}.{$year}";
        if (! isset($memo[$key])) {
            $row = DB::selectOne(
                'SELECT ST_ScaleX(r.rast) AS sx, ST_ScaleY(r.rast) AS sy
                   FROM worldpop_rasters r
                   JOIN jurisdictions g ON g.id = ?
                  WHERE r.iso_code = g.iso_code AND r.year = ?::smallint
                  LIMIT 1',
                [$scopeId, $year]
            );
            $memo[$key] = [
                abs((float) ($row->sx ?? 0.000833)) ?: 0.000833,
                abs((float) ($row->sy ?? 0.000833)) ?: 0.000833,
            ];
        }

        return $memo[$key];
    }

    /**
     * The 4×4 subcell expansion of a native pixel grid: 16 points per
     * pixel at ±{1,3}/8 of the scale, each carrying value/16.
     *
     * @param  list<array{0: float, 1: float, 2: float}>  $pixels
     * @return list<array{0: float, 1: float, 2: float}>
     */
    private function subdividePixels(array $pixels, float $sx, float $sy): array
    {
        $offs = [-0.375, -0.125, 0.125, 0.375];
        $out = [];
        foreach ($pixels as [$x, $y, $v]) {
            $v16 = $v / 16.0;
            foreach ($offs as $ox) {
                foreach ($offs as $oy) {
                    $out[] = [$x + $ox * $sx, $y + $oy * $sy, $v16];
                }
            }
        }

        return $out;
    }

    /**
     * The scope's raster mass over its own geometry + its stored population.
     * The raster_pop here is a COVERAGE-GATE input ONLY (hasRasterCoverage's
     * >0 and ≥10%-of-stored test, and basis()'s raster-vs-area decision) —
     * every measurement that needs an exact, border-deduped population reads
     * measureWithFallback, which uses only stored_pop from here. Memoized per
     * process.
     *
     * PER-TILE COVERAGE (2026-07-21, the mega-area OOM class): raster_pop was
     * population_within_multi, whose ST_Union(rast,'MAX') allocates ONE mosaic
     * over the whole scope — a 95,902 km² Siberian district (Tuguro-Chumikansky)
     * or 135k km² Canadian territory then demands a multi-GB planet-scale
     * raster and the postgres backend is OOM-killed (signal 9), taking the run
     * with it. The est-3+ tail is DENSE with such giants, so the crash looped.
     * The gate never needed the mosaic: clip each intersecting tile
     * INDEPENDENTLY (bounded by the tile's own extent, freed per tile — the
     * exact pattern pixelGrid and population_within already use safely at
     * continental scale) and SUM. ONE-ISO FRAME (operator ruling 2026-07-21):
     * only the scope's OWN country's tiles are read — every scope in the
     * district pipeline resolves its country through the chain, and the grid
     * this gate guards IS the own-iso grid, so a gate reading neighbor tiles
     * could pass a scope whose planning grid is empty. In the one-iso frame
     * the only possible error is the small accepted undercount (people
     * outside the admin line are lost, never invented). A NULL/absent iso
     * yields raster_pop 0 → the area basis, deterministically.
     * Antimeridian-safe by construction (no cross-date-line union).
     *
     * The clip carries WORLDPOP_NODATA explicitly: population is definitionally
     * non-negative, so the WorldPop -99999 nodata AND the ±3.4e38 fill that a
     * no-nodata band leaves in a crop's outside pixels are BOTH excluded by one
     * scalar. Without it, a partial/edge clip on a band lacking its own nodata
     * sums the float sentinel and ROUND(±3.4e38)::bigint throws "bigint out of
     * range" — production rasters all carry -99999 so it never bit there, but
     * the gate must not depend on every tile's nodata hygiene.
     *
     * @return array{0: int, 1: int} [rasterPop, storedPop]
     */
    private const WORLDPOP_NODATA = -99999.0;

    private function coverageStats(string $scopeId, int $year): array
    {
        static $memo = [];
        $key = "{$scopeId}.{$year}";
        if (! isset($memo[$key])) {
            $row = DB::selectOne(
                'WITH s AS MATERIALIZED (
                     SELECT ST_MakeValid(
                                CASE WHEN ST_NPoints(j.geom) > 50000
                                     THEN ST_SimplifyPreserveTopology(j.geom, 0.001)
                                     ELSE j.geom END
                            ) AS geom,
                            j.iso_code,
                            GREATEST(COALESCE(j.population, 0), 0) AS stored_pop
                       FROM jurisdictions j
                      WHERE j.id = ? AND j.geom IS NOT NULL
                 )
                 SELECT COALESCE((
                            SELECT ROUND(SUM((ST_SummaryStats(
                                       ST_Clip(r.rast, 1, s.geom, '.self::WORLDPOP_NODATA.'::double precision, TRUE)
                                   )).sum))::bigint
                              FROM worldpop_rasters r
                             WHERE r.year = ?::smallint
                               AND r.iso_code = s.iso_code
                               AND ST_Intersects(r.rast, s.geom)
                        ), 0) AS raster_pop,
                        s.stored_pop AS stored_pop
                   FROM s',
                [$scopeId, $year]
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

        // The pixelGrid ladder, extended DOWNWARD: no native 100 m tier
        // exists without a raster, and a micro-village (~0.04 km²) at the
        // old 0.002° floor yielded ONE cell — under the two-point minimum,
        // so even the fallback refused. Sub-block scopes take finer cells.
        $cell = match (true) {
            $areaKm2 <= 0.5      => 0.0002,
            $areaKm2 <= 3.0      => 0.0005,
            $areaKm2 <= 3000.0   => 0.002,
            $areaKm2 <= 30000.0  => 0.005,
            $areaKm2 <= 300000.0 => 0.02,
            default              => 0.05,
        };

        $compute = function () use ($scopeId, $cell) {
            // Same inside-the-territory guarantee as pixelGrid (2026-07-21):
            // the centroid of a clipped concave/multipart cell can fall
            // outside it (a bay) — snap to the closest point on the cell.
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
                SELECT CASE WHEN ST_Covers(cg, ST_Centroid(cg)) THEN ST_X(ST_Centroid(cg))
                            ELSE ST_X(ST_ClosestPoint(cg, ST_Centroid(cg))) END AS x,
                       CASE WHEN ST_Covers(cg, ST_Centroid(cg)) THEN ST_Y(ST_Centroid(cg))
                            ELSE ST_Y(ST_ClosestPoint(cg, ST_Centroid(cg))) END AS y,
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
            // Same per-process memo as pixelGrid: parity measurement re-reads
            // the grid per filed piece — share one computation per scope.
            static $memo = [];
            if (! isset($memo[$scopeId])) {
                if (count($memo) >= 2) {
                    $memo = [];
                }
                $memo[$scopeId] = $compute();
            }

            return $memo[$scopeId];
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
    /**
     * THE ONE BASIS DECISION: which population oracle plans AND measures a
     * scope — 'subpixel' (raster, area-divided below the resolution floor),
     * 'raster' (native pixels), or 'area' (the geometric grid). Planner and
     * F-ELB-008 measurement both switch on THIS, so they can never disagree.
     * 'area' is also the fall-through when coverage looks real (the
     * multi-country probe found people) but the scope's own-iso pixel grid
     * is unusable — a cross-border commune whose people sit on a
     * neighboring tile, or a sliver at a tile seam. Memoized per process.
     */
    public function basis(string $scopeId, int $year = 2023): string
    {
        static $memo = [];
        $key = "{$scopeId}.{$year}";
        if (! isset($memo[$key])) {
            if (! $this->hasRasterCoverage($scopeId, $year)) {
                $memo[$key] = 'area';
            } else {
                $grid = $this->pixelGrid($scopeId, $year);
                $n = count($grid);
                $memo[$key] = $n < 2 ? 'area'
                    : (($n < self::SUBPIXEL_THRESHOLD || self::concentrated($grid)) ? 'subpixel' : 'raster');
            }
        }

        return $memo[$key];
    }

    public function gridWithFallback(string $scopeId, int $year = 2023): array
    {
        return match ($this->basis($scopeId, $year)) {
            'raster'   => $this->pixelGrid($scopeId, $year),
            'subpixel' => (function () use ($scopeId, $year) {
                [$sx, $sy] = $this->tileScale($scopeId, $year);

                return $this->subdividePixels($this->pixelGrid($scopeId, $year), $sx, $sy);
            })(),
            default    => $this->areaGrid($scopeId, $year),
        };
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
        $basis = $this->basis($scopeId, $year);

        // ONE denominator (2026-07-19): the grid the PLANNER cut on is the
        // distribution oracle; the stored population is the amount oracle —
        // it sized the legislature, so it is the mass the seat quota
        // divides. A piece measures as its SHARE of the planning grid's
        // mass × the stored population, on the SAME basis the planner used
        // (a differently-based scorekeeper would refuse the very cuts the
        // planner lawfully balanced).
        //
        // MEASUREMENT PARITY (2026-07-21, the band-drift class): the raster
        // basis previously normalized against population_within_multi — a
        // MULTI-country, native-resolution clip — while the planner balanced
        // on the own-iso BINNED pixel grid. Two different distributions over
        // the same geometry: a neighbor country's overlapping border tile
        // inflated one side's share (Patichhari: plan 8:7, handler 12:3) and
        // the band gate refused lawful cuts. Both raster bases now measure
        // as share-of-the-planner's-grid — the one-basis law this file
        // declares, extended to its last branch. This is NOT trust-the-plan:
        // the filed GeoJSON is still measured independently; an empty,
        // misplaced, or unbalanced piece still measures wrong and refuses.
        //
        // The point-assignment predicate mirrors the planner's blade-sign
        // TOTALITY: a grid point inside the piece counts for it; a point
        // outside the SCOPE entirely (a binned cell center that fell off the
        // boundary — Tinzaouten's border town, 49% of its mass) counts for
        // the piece achieving the scope's minimum distance (the blade-side
        // owner; 1e-6° tolerance absorbs GeoJSON round-tripping). Without
        // recovery, that mass silently vanishes from every piece and a
        // lawful plan misseats.
        // THE RAY-CAST ORACLE (2026-07-22, the fallback-drift class): every
        // basis now measures a piece as its ray-cast share of the planner's
        // own grid — partitionPixelsByPolygon, the EXACT assignment the
        // absorb recount and the components grouping already used at plan
        // time. Parity by construction for every fallback path (the
        // geometric SQL's coverage/recovery drifted 1.5-5 quotas on
        // scattered/coastal multipart pieces: La Guaira 14.19q, Subei
        // 13.53q), no geometry SQL left in measurement (nothing to OOM),
        // and grid points are already snapped ON the territory. Machine
        // cuts with a frame chain use measureByCutPath and never reach
        // here. Boundary-ambiguous cells default outside a piece
        // (deterministic; edge-cell mass only, the guard's territory).
        [, $storedPop] = $this->coverageStats($scopeId, $year);
        $grid = $this->gridWithFallback($scopeId, $year);
        $gridTotal = 0.0;
        foreach ($grid as [, , $v]) {
            $gridTotal += $v;
        }

        $geometry = json_decode($geoJson, true) ?: [];
        $members = ($geometry['type'] ?? '') === 'GeometryCollection'
            ? ($geometry['geometries'] ?? [])
            : [$geometry];

        $pieceSum = 0.0;
        $rest = $grid;
        foreach ($members as $member) {
            [$inside, $rest] = SubdivisionAutoseedService::partitionPixelsByPolygon($rest, $member);
            foreach ($inside as $p) {
                $pieceSum += $p[2];
            }
        }

        $pop = ($gridTotal > 0 && $storedPop > 0)
            ? (int) round($storedPop * $pieceSum / $gridTotal)
            : (int) round($pieceSum);

        return [
            'pop'    => $pop,
            'source' => $basis === 'area' ? 'area_proportional' : 'worldpop_raster',
        ];
    }

    /**
     * THE HALF-PLANE MEASUREMENT (operator ruling 2026-07-22, "a simpler
     * strategy to cut the line"): a machine-cut piece IS the intersection of
     * half-planes down its cut path, so its population is the grid mass
     * satisfying every level's side test — the planner's own total per-point
     * rule, re-applied. Pure arithmetic: no ST_Covers, no ST_Distance, no
     * geometry SQL at all (the geometric measurement's distance recovery ran
     * HOURS per piece on archipelago monsters — Falkland ~700 islands).
     * Local non-contiguity (stranded slivers, island riders, boundary
     * subcells) is accounted for by construction: every point has a side.
     * Islands that STRADDLE a cut line drift by sub-island amounts vs the
     * planner's rep-point riding — the 5% guard's territory, not a gate
     * bypass. Fail-closed is preserved: the sums are recomputed here from
     * the filed frames, never copied from the plan.
     *
     * @param  list<array{0: float, 1: float, 2: float}>  $grid
     * @param  list<array{0:float,1:float,2:float,3:float,4:float,5:float,6:int}>  $cutPath  [nx,ny,c,lon0,lat0,cosLat,side]
     * @return array{0: float, 1: float} [pieceSum, gridTotal]
     */
    public static function sumByCutPath(array $grid, array $cutPath): array
    {
        $total = 0.0;
        $sum = 0.0;
        foreach ($grid as [$x, $y, $v]) {
            $total += $v;
            foreach ($cutPath as $level) {
                $t = ($x - (float) $level[3]) * (float) $level[5] * (float) $level[0]
                   + ($y - (float) $level[4]) * (float) $level[1];
                if (($t < (float) $level[2]) !== (((int) $level[6]) === 0)) {
                    continue 2;
                }
            }
            $sum += $v;
        }

        return [$sum, $total];
    }

    /**
     * Measure a machine-cut piece by its half-plane chain against the SAME
     * grid the planner cut on (one-denominator: share × stored population).
     *
     * @return array{pop: int, source: string}
     */
    public function measureByCutPath(string $scopeId, array $cutPath, int $year = 2023): array
    {
        [, $storedPop] = $this->coverageStats($scopeId, $year);
        $grid = $this->gridWithFallback($scopeId, $year);
        [$sum, $total] = self::sumByCutPath($grid, $cutPath);
        $pop = ($total > 0 && $storedPop > 0)
            ? (int) round($storedPop * $sum / $total)
            : (int) round($sum);

        return [
            'pop'    => $pop,
            'source' => $this->basis($scopeId, $year) === 'area' ? 'area_proportional' : 'worldpop_raster',
        ];
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
