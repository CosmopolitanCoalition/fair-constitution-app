<?php

namespace App\Services;

use App\Models\DataReviewDecision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * DataReviewService — Builds the data-quality review surface for the Setup
 * wizard's Step 4 page. Surfaces four categories of post-ETL issues that an
 * operator may want to inspect before finalizing the instance:
 *
 *   1. population_gaps           — jurisdictions with population = 0 or NULL
 *   2. aggregation_discrepancies — country-level rollup where parent vs
 *                                  children sum disagree by > 5%
 *   3. orphans                   — jurisdictions with parent_id IS NULL
 *                                  (find_parent_by_spatial failed at import)
 *   4. sovereign_territories     — territory rows tagged with a sovereign's
 *                                  iso_code that have population=0 because
 *                                  the territory's separate WorldPop raster
 *                                  wasn't loaded
 *
 * The service is read-only. Remediation actions ship in a future iteration
 * (Phase J) — fix_orphans.py, sovereign-territory raster loading, etc. The
 * existing scripts/etl/sovereign_territories.py is the Python side of the
 * map mirrored here; keep them in sync when adding new pairs.
 */
class DataReviewService
{
    /**
     * Sovereign ISO3 → list of dependent-territory ISO3s. Mirror of
     * scripts/etl/sovereign_territories.py SOVEREIGN_TERRITORIES.
     *
     * @var array<string, list<string>>
     */
    private const SOVEREIGN_TERRITORIES = [
        'USA' => ['PRI', 'GUM', 'VIR', 'ASM', 'MNP'],
        'GBR' => ['AIA', 'BMU', 'VGB', 'CYM', 'FLK', 'GIB', 'MSR', 'PCN',
                  'SHN', 'TCA', 'IOT', 'SGS'],
        'FRA' => ['REU', 'MTQ', 'GUF', 'GLP', 'MYT', 'NCL', 'PYF', 'WLF',
                  'SPM', 'BLM', 'MAF'],
        'NLD' => ['ABW', 'CUW', 'SXM', 'BES'],
        'DNK' => ['FRO', 'GRL'],
        'NOR' => ['SJM'],
        'AUS' => ['CCK', 'CXR', 'NFK'],
        'NZL' => ['NIU', 'COK', 'TKL'],
        'CHN' => ['HKG', 'MAC'],
        'FIN' => ['ALA'],
    ];

    /** Threshold for aggregation-discrepancy flag (parent vs children sum). */
    private const AGGREGATION_DELTA_THRESHOLD = 0.05; // 5%

    /**
     * Top-level summary used by the Step 4 page-render handler.
     *
     * @return array{
     *     totals: array<string,int|float>,
     *     issues: array<string,array<string,mixed>>,
     *     severity: 'low'|'medium'|'high'
     * }
     */
    public function summary(): array
    {
        $popGaps   = $this->populationGapsSummary();
        $aggDiscr  = $this->aggregationDiscrepanciesCount();
        $orphans   = $this->orphansSummary();
        $sovTerrs  = $this->sovereignTerritoryCandidatesSummary();
        $parentAudit = $this->parentAssignmentAuditSummary();
        $popAudit    = $this->populationAssignmentAuditSummary();

        $totalRows  = (int) DB::table('jurisdictions')
            ->whereNull('deleted_at')->count();
        $withPop    = (int) DB::table('jurisdictions')
            ->whereNull('deleted_at')
            ->whereRaw('population > 0')
            ->count();
        $pctWithPop = $totalRows > 0
            ? round(($withPop / $totalRows) * 100, 1)
            : 0.0;

        return [
            'totals'   => [
                'jurisdictions'       => $totalRows,
                'with_population'     => $withPop,
                'pct_with_population' => $pctWithPop,
            ],
            'issues'   => [
                'population_gaps' => [
                    'count'    => $popGaps['count'],
                    'by_level' => $popGaps['by_level'],
                ],
                'aggregation_discrepancies' => [
                    'count' => $aggDiscr,
                ],
                'orphans' => [
                    'count'    => $orphans['count'],
                    'by_level' => $orphans['by_level'],
                    'top_iso'  => $orphans['top_iso'],
                ],
                'sovereign_territories' => [
                    'count'                       => $sovTerrs['count'],
                    'territory_count_by_sovereign' => $sovTerrs['by_sovereign'],
                ],
                'parent_assignment_audit' => [
                    'count'       => $parentAudit['count'],   // heuristic-resolved rows
                    'by_strategy' => $parentAudit['by_strategy'],
                ],
                'population_assignment_audit' => [
                    'count'     => $popAudit['count'],         // territory-fallback-rescued rows
                    'by_source' => $popAudit['by_source'],
                ],
            ],
            'severity' => $this->scoreSeverity($popGaps['count'], $aggDiscr, $orphans['count'], $sovTerrs['count']),
        ];
    }

    /**
     * Phase P.6 — per-jurisdiction review-category summary.
     *
     * Returns a flat array of issue flags + helpful metadata for the
     * Jurisdiction Viewer's review-issue badges panel. Each badge in the
     * UI lights up when its corresponding flag is true.
     *
     * Cheap to compute on demand (one indexed row read + a child rollup) so
     * it can run on every viewer page-load without caching.
     *
     * Surfaces `population_baseline` as a historical snapshot of the Phase 2
     * baseline value (set by the ETL after baseline injection + topological
     * raster fallback). The Phase T pixel-attribution correction columns
     * (overlap/gap/cross-iso) were ripped in migration
     * 2026_05_22_000001_rip_pixel_attribution_correction.php — that approach
     * produced row-level garbage (tiny hamlets credited with millions of
     * people) and was abandoned in favor of pure Phase 2 baseline.
     *
     * @return array{
     *   is_population_gap:             bool,
     *   is_orphan:                     bool,
     *   is_aggregation_discrepancy:    bool,
     *   is_sovereign_territory:        bool,
     *   parent_assigned_via:           string|null,
     *   parent_iso_differs:            bool,
     *   children_with_pop:             int,
     *   children_total:                int,
     *   rollup_delta_pct:              float|null,
     *   population_baseline:           int|null
     * }
     */
    public function summaryForJurisdiction(string $jurisdictionId): array
    {
        $row = DB::selectOne(
            "
            SELECT j.id::text, j.iso_code, j.adm_level, j.population, j.parent_id::text,
                   j.parent_assigned_via,
                   j.population_baseline,
                   p.iso_code AS parent_iso
            FROM jurisdictions j
            LEFT JOIN jurisdictions p ON p.id = j.parent_id
            WHERE j.id = :id AND j.deleted_at IS NULL
            ",
            ['id' => $jurisdictionId]
        );
        if (! $row) {
            return [
                'is_population_gap'             => false,
                'is_orphan'                     => false,
                'is_aggregation_discrepancy'    => false,
                'is_sovereign_territory'        => false,
                'parent_assigned_via'           => null,
                'parent_iso_differs'            => false,
                'children_with_pop'             => 0,
                'children_total'                => 0,
                'rollup_delta_pct'              => null,
                'population_baseline'           => null,
            ];
        }

        $isPopGap = ((int) ($row->population ?? 0)) === 0
            && ! in_array($row->iso_code, ['ATA'], true);

        $isOrphan = $row->parent_id === null && (int) $row->adm_level > 1;

        // Aggregation discrepancy: parent population vs sum-of-children
        // delta > 5 %. Compute children sum as a single query.
        $children = DB::selectOne(
            "
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN population > 0 THEN 1 ELSE 0 END) AS with_pop,
                   COALESCE(SUM(population), 0) AS sum_pop
            FROM jurisdictions
            WHERE parent_id = :pid AND deleted_at IS NULL
            ",
            ['pid' => $jurisdictionId]
        );
        $childrenTotal   = (int) ($children->total ?? 0);
        $childrenWithPop = (int) ($children->with_pop ?? 0);
        $childrenSum     = (int) ($children->sum_pop ?? 0);

        $rollupDeltaPct = null;
        $isAggDiscr     = false;
        if ((int) $row->population > 0 && $childrenTotal > 0 && $childrenWithPop > 0) {
            $delta          = (int) $row->population - $childrenSum;
            $rollupDeltaPct = round(($delta / $row->population) * 100, 2);
            $isAggDiscr     = abs($rollupDeltaPct) > 5.0;
        }

        // Sovereign-territory: this row's iso is one of the curated dependent
        // territories (so the operator may want to dual-footprint it under a
        // sovereign). Uses the same const the existing summary does.
        $isSovTerr = false;
        foreach (self::SOVEREIGN_TERRITORIES as $sov => $territories) {
            if (in_array($row->iso_code, $territories, true)) {
                $isSovTerr = true;
                break;
            }
        }

        // population_baseline is the Phase 2 baseline snapshot (set by
        // the ETL after baseline injection + topological raster fallback).
        // The columns that recorded the abandoned pixel-attribution
        // correction (overlap/gap/cross-iso) were dropped in migration
        // 2026_05_22_000001_rip_pixel_attribution_correction.php.
        $popBaseline = $row->population_baseline !== null
            ? (int) $row->population_baseline
            : null;

        return [
            'is_population_gap'             => $isPopGap,
            'is_orphan'                     => $isOrphan,
            'is_aggregation_discrepancy'    => $isAggDiscr,
            'is_sovereign_territory'        => $isSovTerr,
            'parent_assigned_via'           => $row->parent_assigned_via,
            'parent_iso_differs'            => $row->parent_iso !== null
                && $row->parent_iso !== $row->iso_code,
            'children_with_pop'             => $childrenWithPop,
            'children_total'                => $childrenTotal,
            'rollup_delta_pct'              => $rollupDeltaPct,
            'population_baseline'           => $popBaseline,
        ];
    }

    // ─── Drill endpoints — per-category paginated row lists ──────────────────

    /**
     * Population-gap rows at a given adm_level, ordered by area (biggest
     * "missing" jurisdictions surface first since they're most impactful).
     */
    public function populationGapsRows(int $admLevel, int $limit, int $offset): array
    {
        $total = (int) DB::table('jurisdictions')
            ->whereNull('deleted_at')
            ->whereRaw('COALESCE(population, 0) = 0')
            ->where('adm_level', $admLevel)
            ->count();

        // P.5 perf fix: ORDER BY uses planar ST_Area (degree²) instead of
        // geography (km²). Both produce the same ordering for ranking
        // "biggest missing", but planar uses the GIST geometry index
        // efficiently. The km² in SELECT stays geography-cast for display
        // accuracy — that's a per-row computation on the LIMIT'd rows
        // only, not a sort key. Combined effect: drill loads in <1 s
        // instead of 5–30 s on world-scale jurisdictions tables.
        $rows = DB::select(
            "
            SELECT j.id::text, j.name, j.iso_code, j.adm_level,
                   j.population,
                   p.name AS parent_name,
                   ROUND((ST_Area(j.geom::geography) / 1000000)::numeric, 2) AS area_km2
            FROM jurisdictions j
            LEFT JOIN jurisdictions p ON p.id = j.parent_id
            WHERE j.deleted_at IS NULL
              AND COALESCE(j.population, 0) = 0
              AND j.adm_level = :lvl
            ORDER BY ST_Area(j.geom) DESC NULLS LAST
            LIMIT :lim OFFSET :off
            ",
            ['lvl' => $admLevel, 'lim' => $limit, 'off' => $offset]
        );

        return $this->paginatedResponse($rows, $total, $offset, $limit);
    }

    /**
     * Aggregation discrepancies — countries where parent population vs
     * sum-of-children disagree by > AGGREGATION_DELTA_THRESHOLD.
     */
    public function aggregationDiscrepancyRows(int $limit, int $offset): array
    {
        $threshold = self::AGGREGATION_DELTA_THRESHOLD;
        $total = (int) DB::scalar(
            "
            WITH pc AS (
                SELECT p.id, p.population AS parent_pop, SUM(c.population) AS children_sum
                FROM jurisdictions p
                JOIN jurisdictions c ON c.parent_id = p.id AND c.deleted_at IS NULL
                WHERE p.deleted_at IS NULL AND p.population > 0 AND p.adm_level = 1
                GROUP BY p.id, p.population
            )
            SELECT COUNT(*) FROM pc
            WHERE ABS((parent_pop - children_sum)::numeric / NULLIF(parent_pop, 0)) > :t
            ",
            ['t' => $threshold]
        );

        $rows = DB::select(
            "
            WITH pc AS (
                SELECT p.id, p.name, p.iso_code, p.adm_level,
                       p.population AS parent_pop,
                       SUM(c.population) AS children_sum,
                       COUNT(c.id) AS child_count,
                       COUNT(c.id) FILTER (WHERE c.population > 0) AS children_with_pop
                FROM jurisdictions p
                JOIN jurisdictions c ON c.parent_id = p.id AND c.deleted_at IS NULL
                WHERE p.deleted_at IS NULL AND p.population > 0 AND p.adm_level = 1
                GROUP BY p.id, p.name, p.iso_code, p.adm_level, p.population
            )
            SELECT id::text, name, iso_code, adm_level,
                   parent_pop, children_sum, child_count, children_with_pop,
                   parent_pop - children_sum AS delta,
                   ROUND(((parent_pop - children_sum)::numeric / NULLIF(parent_pop, 0)) * 100, 2) AS delta_pct
            FROM pc
            WHERE ABS((parent_pop - children_sum)::numeric / NULLIF(parent_pop, 0)) > :t
            ORDER BY ABS((parent_pop - children_sum)::numeric / NULLIF(parent_pop, 0)) DESC
            LIMIT :lim OFFSET :off
            ",
            ['t' => $threshold, 'lim' => $limit, 'off' => $offset]
        );

        return $this->paginatedResponse($rows, $total, $offset, $limit);
    }

    /**
     * Orphan jurisdictions (parent_id IS NULL, adm_level > 0).
     * Optional filter by adm_level — when null, returns all orphans.
     */
    public function orphanRows(?int $admLevel, int $limit, int $offset): array
    {
        $where = 'j.deleted_at IS NULL AND j.parent_id IS NULL AND j.adm_level > 0';
        $params = ['lim' => $limit, 'off' => $offset];
        if ($admLevel !== null) {
            $where .= ' AND j.adm_level = :lvl';
            $params['lvl'] = $admLevel;
        }

        $total = (int) DB::scalar(
            "SELECT COUNT(*) FROM jurisdictions j WHERE $where",
            $admLevel !== null ? ['lvl' => $admLevel] : []
        );

        $rows = DB::select(
            "
            SELECT j.id::text, j.name, j.iso_code, j.adm_level,
                   j.population, j.source,
                   ROUND((ST_Area(j.geom::geography) / 1000000)::numeric, 2) AS area_km2
            FROM jurisdictions j
            WHERE $where
            ORDER BY j.adm_level, j.iso_code, j.name
            LIMIT :lim OFFSET :off
            ",
            $params
        );

        return $this->paginatedResponse($rows, $total, $offset, $limit);
    }

    /**
     * Sovereign-territory candidates: rows tagged with a sovereign's
     * iso_code that have population=0 and whose name matches one of the
     * known territory names. The operator can opt to load that territory's
     * separate WorldPop raster in Phase J to fill the gap.
     */
    public function sovereignTerritoryRows(?string $sovereign, int $limit, int $offset): array
    {
        $sovereigns = $sovereign !== null
            ? [strtoupper($sovereign)]
            : array_keys(self::SOVEREIGN_TERRITORIES);

        // No sovereign-territory candidates have population data, so just
        // surface the territory ADM2 rows tagged with the sovereign's iso
        // that have pop=0. Use a name-based match against the canonical
        // territory iso list — territory names are stable in geoBoundaries.
        $rows = [];
        $totalCount = 0;

        foreach ($sovereigns as $sov) {
            if (! isset(self::SOVEREIGN_TERRITORIES[$sov])) {
                continue;
            }
            $territoryIsos = self::SOVEREIGN_TERRITORIES[$sov];
            $territoryNamePatterns = $this->territoryNamePatterns($territoryIsos);

            $sovRows = DB::select(
                "
                SELECT j.id::text, j.name, j.iso_code, j.adm_level,
                       j.population,
                       :sovereign AS sovereign,
                       (SELECT COUNT(*) FROM jurisdictions c
                          WHERE c.parent_id = j.id AND c.deleted_at IS NULL) AS child_count,
                       ROUND((ST_Area(j.geom::geography) / 1000000)::numeric, 2) AS area_km2
                FROM jurisdictions j
                WHERE j.deleted_at IS NULL
                  AND j.iso_code = :sov
                  AND COALESCE(j.population, 0) = 0
                  AND j.adm_level >= 2
                  AND j.name ~* :pat
                ORDER BY j.adm_level, j.name
                ",
                ['sov' => $sov, 'sovereign' => $sov, 'pat' => $territoryNamePatterns]
            );
            $rows = array_merge($rows, $sovRows);
            $totalCount += count($sovRows);
        }

        $sliced = array_slice($rows, $offset, $limit);
        return $this->paginatedResponse($sliced, $totalCount, $offset, $limit);
    }

    // ─── Internal: summary builders ──────────────────────────────────────────

    private function populationGapsSummary(): array
    {
        $rows = DB::select(
            "
            SELECT adm_level,
                   COUNT(*) FILTER (WHERE COALESCE(population, 0) = 0) AS without_pop,
                   COUNT(*) AS total
            FROM jurisdictions
            WHERE deleted_at IS NULL
            GROUP BY adm_level
            ORDER BY adm_level
            "
        );

        $byLevel = [];
        $count   = 0;
        foreach ($rows as $r) {
            $without = (int) $r->without_pop;
            $byLevel[] = [
                'adm_level'    => (int) $r->adm_level,
                'total'        => (int) $r->total,
                'without_pop'  => $without,
            ];
            $count += $without;
        }

        return ['count' => $count, 'by_level' => $byLevel];
    }

    private function aggregationDiscrepanciesCount(): int
    {
        $threshold = self::AGGREGATION_DELTA_THRESHOLD;
        // Restricted to adm_level=1 (countries) to match the
        // validate_national_population log warnings. Deeper levels have
        // expected polygon-precision drift between geoBoundaries' ADM
        // representations and inflate the count with false positives.
        // The drill endpoint can opt into a broader scan.
        return (int) DB::scalar(
            "
            WITH pc AS (
                SELECT p.id, p.population AS parent_pop, SUM(c.population) AS children_sum
                FROM jurisdictions p
                JOIN jurisdictions c ON c.parent_id = p.id AND c.deleted_at IS NULL
                WHERE p.deleted_at IS NULL AND p.population > 0 AND p.adm_level = 1
                GROUP BY p.id, p.population
            )
            SELECT COUNT(*) FROM pc
            WHERE ABS((parent_pop - children_sum)::numeric / NULLIF(parent_pop, 0)) > :t
            ",
            ['t' => $threshold]
        );
    }

    private function orphansSummary(): array
    {
        $byLevelRows = DB::select(
            "
            SELECT adm_level, COUNT(*) AS cnt
            FROM jurisdictions
            WHERE deleted_at IS NULL AND parent_id IS NULL AND adm_level > 0
            GROUP BY adm_level ORDER BY adm_level
            "
        );
        $topIsoRows = DB::select(
            "
            SELECT iso_code, COUNT(*) AS cnt
            FROM jurisdictions
            WHERE deleted_at IS NULL AND parent_id IS NULL AND adm_level > 0
            GROUP BY iso_code
            ORDER BY cnt DESC LIMIT 10
            "
        );

        $byLevel = array_map(
            fn ($r) => ['adm_level' => (int) $r->adm_level, 'count' => (int) $r->cnt],
            $byLevelRows,
        );
        $topIso = array_map(
            fn ($r) => ['iso_code' => (string) $r->iso_code, 'count' => (int) $r->cnt],
            $topIsoRows,
        );
        $count = array_sum(array_column($byLevel, 'count'));

        return ['count' => $count, 'by_level' => $byLevel, 'top_iso' => $topIso];
    }

    /**
     * Phase JK audit: distribution of `parent_assigned_via` values.
     *
     * Operator uses this to spot whether the import had to fall back to
     * heuristics (skip_ancestor, buffered) or synthesize country rows
     * (synthetic_country) — useful for sanity-checking automated decisions.
     *
     * @return array{count:int, by_strategy:array<string,int>}
     */
    public function parentAssignmentAuditSummary(): array
    {
        $rows = DB::select(
            "
            SELECT COALESCE(parent_assigned_via, '__null__') AS strategy, COUNT(*) AS cnt
            FROM jurisdictions
            WHERE deleted_at IS NULL AND adm_level > 0
            GROUP BY parent_assigned_via
            "
        );

        $byStrategy = [];
        $heuristicCount = 0;
        foreach ($rows as $r) {
            $strategy = $r->strategy === '__null__' ? null : (string) $r->strategy;
            $byStrategy[$strategy ?? 'orphan_or_pre_jk'] = (int) $r->cnt;
            // The "heuristic" count for the audit card excludes 'direct'
            // (always normal) and the null bucket (orphan or pre-JK). After
            // the Phase JK polish, 'synthetic_country' is no longer used —
            // synthesized rows are tagged source='synthetic' but their
            // parent_assigned_via is 'direct' (parent IS Earth).
            if (in_array($strategy, ['skip_ancestor', 'buffered'], true)) {
                $heuristicCount += (int) $r->cnt;
            }
        }

        return [
            'count'       => $heuristicCount,   // rows that needed a heuristic
            'by_strategy' => $byStrategy,
        ];
    }

    public function parentAssignmentAuditRows(string $strategy, int $limit, int $offset): array
    {
        $strategyParam = $strategy === 'orphan_or_pre_jk' ? null : $strategy;

        $where = "j.deleted_at IS NULL AND j.adm_level > 0";
        $params = ['lim' => $limit, 'off' => $offset];

        if ($strategyParam === null) {
            $where .= " AND j.parent_assigned_via IS NULL";
        } else {
            $where .= " AND j.parent_assigned_via = :strat";
            $params['strat'] = $strategyParam;
        }

        $total = (int) DB::scalar(
            "SELECT COUNT(*) FROM jurisdictions j WHERE $where",
            $strategyParam === null ? [] : ['strat' => $strategyParam]
        );

        $rows = DB::select(
            "
            SELECT j.id::text, j.name, j.iso_code, j.adm_level, j.population,
                   j.parent_assigned_via,
                   p.name AS parent_name,
                   p.adm_level AS parent_adm_level,
                   ROUND((ST_Area(j.geom::geography) / 1000000)::numeric, 2) AS area_km2
            FROM jurisdictions j
            LEFT JOIN jurisdictions p ON p.id = j.parent_id
            WHERE $where
            ORDER BY j.adm_level, j.iso_code, j.name
            LIMIT :lim OFFSET :off
            ",
            $params
        );

        return $this->paginatedResponse($rows, $total, $offset, $limit);
    }

    /**
     * Phase JK audit: distribution of `population_assigned_via` values.
     */
    public function populationAssignmentAuditSummary(): array
    {
        $rows = DB::select(
            "
            SELECT COALESCE(population_assigned_via, '__null__') AS source, COUNT(*) AS cnt
            FROM jurisdictions
            WHERE deleted_at IS NULL
            GROUP BY population_assigned_via
            "
        );

        $bySource = [];
        $rescuedCount = 0;
        foreach ($rows as $r) {
            $source = $r->source === '__null__' ? null : (string) $r->source;
            $bySource[$source ?? 'no_data_or_pre_jk'] = (int) $r->cnt;
            if ($source === 'territory_fallback') {
                $rescuedCount += (int) $r->cnt;
            }
        }

        return [
            'count'     => $rescuedCount,   // rows rescued by the territory fallback
            'by_source' => $bySource,
        ];
    }

    public function populationAssignmentAuditRows(string $source, int $limit, int $offset): array
    {
        $sourceParam = $source === 'no_data_or_pre_jk' ? null : $source;

        $where = "j.deleted_at IS NULL";
        $params = ['lim' => $limit, 'off' => $offset];

        if ($sourceParam === null) {
            $where .= " AND j.population_assigned_via IS NULL";
        } else {
            $where .= " AND j.population_assigned_via = :src";
            $params['src'] = $sourceParam;
        }

        $total = (int) DB::scalar(
            "SELECT COUNT(*) FROM jurisdictions j WHERE $where",
            $sourceParam === null ? [] : ['src' => $sourceParam]
        );

        $rows = DB::select(
            "
            SELECT j.id::text, j.name, j.iso_code, j.adm_level, j.population,
                   j.population_assigned_via,
                   ROUND((ST_Area(j.geom::geography) / 1000000)::numeric, 2) AS area_km2
            FROM jurisdictions j
            WHERE $where
            ORDER BY j.adm_level, j.iso_code, j.name
            LIMIT :lim OFFSET :off
            ",
            $params
        );

        return $this->paginatedResponse($rows, $total, $offset, $limit);
    }

    private function sovereignTerritoryCandidatesSummary(): array
    {
        $bySovereign = [];
        $totalCount  = 0;

        foreach (self::SOVEREIGN_TERRITORIES as $sov => $territories) {
            $territoryNamePatterns = $this->territoryNamePatterns($territories);
            $count = (int) DB::scalar(
                "
                SELECT COUNT(*) FROM jurisdictions
                WHERE deleted_at IS NULL
                  AND iso_code = :sov
                  AND COALESCE(population, 0) = 0
                  AND adm_level >= 2
                  AND name ~* :pat
                ",
                ['sov' => $sov, 'pat' => $territoryNamePatterns]
            );
            if ($count > 0) {
                $bySovereign[$sov] = $count;
            }
            $totalCount += $count;
        }

        return ['count' => $totalCount, 'by_sovereign' => $bySovereign];
    }

    /**
     * Build a regex pattern matching territory names. Used to identify
     * territory rows by name when they're tagged with the sovereign's
     * iso_code rather than their own. e.g. ['PRI', 'GUM'] →
     * '^(puerto rico|guam|...)' (case-insensitive).
     *
     * Maps each territory ISO to the typical English name fragments
     * geoBoundaries uses for that territory.
     */
    private function territoryNamePatterns(array $territoryIsos): string
    {
        $map = [
            // USA territories
            'PRI' => 'puerto rico',
            'GUM' => 'guam',
            'VIR' => 'virgin islands',
            'ASM' => 'american samoa|samoa',
            'MNP' => 'mariana|northern mariana',
            // GBR
            'AIA' => 'anguilla',
            'BMU' => 'bermuda',
            'VGB' => 'british virgin',
            'CYM' => 'cayman',
            'FLK' => 'falkland|malvinas',
            'GIB' => 'gibraltar',
            'MSR' => 'montserrat',
            'PCN' => 'pitcairn',
            'SHN' => 'saint helena|st\.? helena',
            'TCA' => 'turks and caicos',
            'IOT' => 'british indian ocean',
            'SGS' => 'south georgia',
            // FRA
            'REU' => 'r[eé]union',
            'MTQ' => 'martinique',
            'GUF' => 'french guiana|guyane',
            'GLP' => 'guadeloupe',
            'MYT' => 'mayotte',
            'NCL' => 'new caledonia|nouvelle-cal[eé]donie',
            'PYF' => 'french polynesia|polyn[eé]sie',
            'WLF' => 'wallis|futuna',
            'SPM' => 'saint pierre|miquelon',
            'BLM' => 'saint barth[eé]lemy',
            'MAF' => 'saint martin',
            // NLD
            'ABW' => 'aruba',
            'CUW' => 'cura[çc]ao',
            'SXM' => 'sint maarten',
            'BES' => 'bonaire|sint eustatius|saba',
            // DNK
            'FRO' => 'faroe',
            'GRL' => 'greenland|kalaallit',
            // NOR
            'SJM' => 'svalbard|jan mayen',
            // AUS
            'CCK' => 'cocos|keeling',
            'CXR' => 'christmas island',
            'NFK' => 'norfolk',
            // NZL
            'NIU' => 'niue',
            'COK' => 'cook island',
            'TKL' => 'tokelau',
            // CHN
            'HKG' => 'hong kong',
            'MAC' => 'macao|macau',
            // FIN
            'ALA' => '[åa]land',
        ];

        $patterns = [];
        foreach ($territoryIsos as $iso) {
            if (isset($map[$iso])) {
                $patterns[] = $map[$iso];
            }
        }
        // PostgreSQL POSIX regex; (?i) for case-insensitive when used with ~*
        return $patterns
            ? '(' . implode('|', $patterns) . ')'
            : '__no_territories_for_this_sovereign__';
    }

    /**
     * Reverse lookup: given a territory ISO ('PRI'), return the list of
     * sovereigns that claim it (typically just one, e.g. ['USA']). Returns
     * an empty list if the iso isn't a known dependent territory.
     */
    private function sovereignsForTerritory(string $iso): array
    {
        $iso = strtoupper($iso);
        $out = [];
        foreach (self::SOVEREIGN_TERRITORIES as $sov => $territories) {
            if (in_array($iso, $territories, true)) {
                $out[] = $sov;
            }
        }
        return $out;
    }

    private function scoreSeverity(int $popGaps, int $aggDiscr, int $orphans, int $sovTerrs): string
    {
        // Severity weighted by impact:
        //   - sovereign territories at 0 pop: actionable (load the raster)
        //   - orphans: structural, but fix_orphans.py handles most
        //   - aggregation discrepancies: source-data quirks, low priority
        //   - population gaps: dominated by uninhabited level-6 cells, lowest priority
        if ($sovTerrs >= 10 || $orphans >= 1000 || $aggDiscr >= 30) {
            return 'high';
        }
        if ($sovTerrs >= 1 || $orphans >= 100 || $aggDiscr >= 5) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * @param  array<int, object>  $rows
     */
    private function paginatedResponse(array $rows, int $total, int $offset, int $limit): array
    {
        $nextOffset = ($offset + count($rows)) < $total
            ? $offset + count($rows)
            : null;

        return [
            'rows'        => array_map(fn ($r) => (array) $r, $rows),
            'total'       => $total,
            'next_offset' => $nextOffset,
        ];
    }

    // ─── Per-row detail builders ─────────────────────────────────────────────
    //
    // Each detail method returns the row's full review context — the data the
    // operator needs to make a manual decision without flipping between
    // tools. NO actions are taken; we just gather context.

    /**
     * Detail for a population-gap row: the row itself, its parent, its
     * sibling jurisdictions at the same level (so the operator can compare
     * "my row has 0 pop, nearby rows have N").
     */
    public function detailForPopulationGap(string $jurisdictionId): ?array
    {
        $row = DB::selectOne(
            "
            SELECT j.id::text, j.name, j.iso_code, j.adm_level,
                   j.population, j.population_year, j.source,
                   p.id::text   AS parent_id,
                   p.name       AS parent_name,
                   p.iso_code   AS parent_iso,
                   p.adm_level  AS parent_adm_level,
                   ROUND((ST_Area(j.geom::geography) / 1000000)::numeric, 2) AS area_km2
            FROM jurisdictions j
            LEFT JOIN jurisdictions p ON p.id = j.parent_id
            WHERE j.id = :id AND j.deleted_at IS NULL
            ",
            ['id' => $jurisdictionId]
        );
        if (! $row) return null;

        // Top 8 sibling jurisdictions ordered by area, with their populations
        // so the operator can see whether the 0 looks like a real anomaly.
        // P.5 perf fix: planar ST_Area in ORDER BY (uses GIST), geography
        // ST_Area in SELECT for displayed km² accuracy.
        $siblings = DB::select(
            "
            SELECT j.id::text, j.name, j.population,
                   ROUND((ST_Area(j.geom::geography) / 1000000)::numeric, 2) AS area_km2
            FROM jurisdictions j
            WHERE j.deleted_at IS NULL
              AND j.parent_id = :pid
              AND j.adm_level = :lvl
              AND j.id != :self
            ORDER BY ST_Area(j.geom) DESC
            LIMIT 8
            ",
            ['pid' => $row->parent_id, 'lvl' => $row->adm_level, 'self' => $jurisdictionId]
        );

        return [
            'row'      => (array) $row,
            'siblings' => array_map(fn ($s) => (array) $s, $siblings),
            'decision' => $this->getDecisionPayload('population_gaps', $jurisdictionId),
        ];
    }

    /**
     * Detail for an aggregation-discrepancy row: the parent, its full
     * children list with populations, plus the rollup math so the operator
     * can see exactly which children are pulling the sum away from the
     * national figure.
     */
    public function detailForAggregationDiscrepancy(string $parentId): ?array
    {
        $parent = DB::selectOne(
            "
            SELECT j.id::text, j.name, j.iso_code, j.adm_level,
                   j.population, j.population_year,
                   ROUND((ST_Area(j.geom::geography) / 1000000)::numeric, 2) AS area_km2
            FROM jurisdictions j
            WHERE j.id = :id AND j.deleted_at IS NULL
            ",
            ['id' => $parentId]
        );
        if (! $parent) return null;

        $children = DB::select(
            "
            SELECT c.id::text, c.name, c.population,
                   ROUND((ST_Area(c.geom::geography) / 1000000)::numeric, 2) AS area_km2
            FROM jurisdictions c
            WHERE c.parent_id = :pid AND c.deleted_at IS NULL
            ORDER BY c.population DESC NULLS LAST, c.name
            ",
            ['pid' => $parentId]
        );

        $childrenSum = array_sum(array_map(fn ($c) => (int) $c->population, $children));
        $delta       = (int) $parent->population - $childrenSum;
        $deltaPct    = $parent->population > 0
            ? round(($delta / $parent->population) * 100, 2)
            : null;

        return [
            'parent'   => (array) $parent,
            'children' => array_map(fn ($c) => (array) $c, $children),
            'rollup'   => [
                'parent_pop'   => (int) $parent->population,
                'children_sum' => $childrenSum,
                'delta'        => $delta,
                'delta_pct'    => $deltaPct,
                'child_count'  => count($children),
                'children_with_pop' => count(array_filter($children, fn ($c) => (int) $c->population > 0)),
            ],
            'decision' => $this->getDecisionPayload('aggregation_discrepancies', $parentId),
        ];
    }

    /**
     * Detail for an orphan row: the row itself, plus candidate parents
     * computed from spatial overlap (largest-overlap-area first) and
     * centroid distance (nearest first). NO autoassignment — the operator
     * sees the candidates and picks one (or marks "true orphan").
     */
    public function detailForOrphan(string $jurisdictionId): ?array
    {
        $row = DB::selectOne(
            "
            SELECT j.id::text, j.name, j.iso_code, j.adm_level,
                   j.population, j.source,
                   ROUND((ST_Area(j.geom::geography) / 1000000)::numeric, 2) AS area_km2
            FROM jurisdictions j
            WHERE j.id = :id AND j.deleted_at IS NULL
            ",
            ['id' => $jurisdictionId]
        );
        if (! $row) return null;

        // Candidate parents — search at every adm_level less than the orphan's,
        // restricted to the same iso_code (PR child should chain to PR or USA,
        // not to TWN). Returns the top 5 spatial-overlap candidates and the
        // top 5 nearest-centroid candidates. The lists may overlap.
        // Build the candidate-iso list: the orphan's own iso, plus its
        // sovereign if it's a dependent territory (e.g. PRI orphans see
        // USA-iso candidates so the operator can chain to "Puerto Rico"
        // under USA when no PRI ADM0/1 row exists in our schema).
        $candidateIsos = array_values(array_unique(array_merge(
            [$row->iso_code],
            $this->sovereignsForTerritory($row->iso_code),
        )));

        // P.5 perf fix: spatial overlap candidate query.
        //
        // BEFORE: `ST_Intersection(p.geom, self.geom)` was computed once in
        //   SELECT and AGAIN in ORDER BY for every candidate row (typically
        //   10–100k rows pre-LIMIT for sub-country isos). Geography cast on
        //   the result invalidated the spatial index. World-scale drills
        //   took 5–30 s and sometimes timed out.
        //
        // AFTER: bbox-prefilter using ST_Intersects (cheap GIST hit, narrows
        //   to ≤ 100 candidates), order by planar ST_Area(p.geom) (uses
        //   GIST), LIMIT 5; then compute the precise overlap_km2 only on
        //   the 5 winners. Same ordering quality (bigger polygons usually
        //   have larger overlap) at a fraction of the cost.
        $spatialCandidates = DB::select(
            "
            WITH self AS (SELECT geom FROM jurisdictions WHERE id = :id),
                 candidates AS (
                     SELECT p.id, p.name, p.adm_level, p.iso_code, p.geom,
                            ST_Area(p.geom) AS planar_area
                     FROM   jurisdictions p, self
                     WHERE  p.deleted_at IS NULL
                       AND  p.iso_code = ANY(:isos::text[])
                       AND  p.adm_level < :lvl
                       AND  p.id != :id
                       AND  ST_Intersects(p.geom, self.geom)
                     ORDER BY planar_area DESC
                     LIMIT 5
                 )
            SELECT c.id::text, c.name, c.adm_level, c.iso_code,
                   ROUND((ST_Area(ST_Intersection(c.geom,
                          (SELECT geom FROM self))::geography) / 1000000)::numeric, 2)
                          AS overlap_km2
            FROM candidates c
            ORDER BY overlap_km2 DESC NULLS LAST
            ",
            ['id' => $jurisdictionId, 'isos' => '{' . implode(',', $candidateIsos) . '}', 'lvl' => $row->adm_level]
        );

        // P.5 perf fix: centroid-distance candidate query.
        //
        // BEFORE: `ST_Distance(p.centroid::geography, self.centroid::geography)`
        //   in ORDER BY computed full geodesic distance per row. Geography
        //   cast invalidated the centroid GIST index → seq scan.
        //
        // AFTER: planar `<->` KNN operator (uses the GIST index on centroid),
        //   LIMIT 5; geography ST_Distance computed only on the 5 winners
        //   for the displayed km. Same outcome ordering at a fraction of
        //   the cost.
        $centroidCandidates = DB::select(
            "
            WITH self AS (SELECT centroid FROM jurisdictions WHERE id = :id),
                 candidates AS (
                     SELECT p.id, p.name, p.adm_level, p.iso_code, p.centroid
                     FROM   jurisdictions p, self
                     WHERE  p.deleted_at IS NULL
                       AND  p.iso_code = ANY(:isos::text[])
                       AND  p.adm_level < :lvl
                       AND  p.centroid IS NOT NULL
                       AND  p.id != :id
                     ORDER BY p.centroid <-> self.centroid
                     LIMIT 5
                 )
            SELECT c.id::text, c.name, c.adm_level, c.iso_code,
                   ROUND((ST_Distance(c.centroid::geography,
                          ((SELECT centroid FROM self))::geography) / 1000)::numeric, 2)
                          AS distance_km
            FROM candidates c
            ORDER BY distance_km ASC
            ",
            ['id' => $jurisdictionId, 'isos' => '{' . implode(',', $candidateIsos) . '}', 'lvl' => $row->adm_level]
        );

        return [
            'row'                  => (array) $row,
            'spatial_candidates'   => array_map(fn ($c) => (array) $c, $spatialCandidates),
            'centroid_candidates'  => array_map(fn ($c) => (array) $c, $centroidCandidates),
            'decision'             => $this->getDecisionPayload('orphans', $jurisdictionId),
        ];
    }

    /**
     * Detail for a sovereign-territory candidate: the territory row, its
     * sovereign, its child count under our schema, and a check for whether
     * the territory's own WorldPop TIF exists in the archive (so the
     * operator knows whether a manual re-run with that ISO would even
     * have data to load).
     */
    public function detailForSovereignTerritory(string $jurisdictionId): ?array
    {
        $row = DB::selectOne(
            "
            SELECT j.id::text, j.name, j.iso_code, j.adm_level,
                   j.population,
                   (SELECT COUNT(*) FROM jurisdictions c
                      WHERE c.parent_id = j.id AND c.deleted_at IS NULL) AS child_count,
                   (SELECT COUNT(*) FROM jurisdictions c
                      WHERE c.parent_id = j.id AND c.deleted_at IS NULL
                        AND COALESCE(c.population, 0) = 0) AS children_at_zero,
                   ROUND((ST_Area(j.geom::geography) / 1000000)::numeric, 2) AS area_km2
            FROM jurisdictions j
            WHERE j.id = :id AND j.deleted_at IS NULL
            ",
            ['id' => $jurisdictionId]
        );
        if (! $row) return null;

        // Try to identify which territory ISO this row corresponds to by
        // matching name against the canonical territory map.
        $sovereign       = (string) $row->iso_code;
        $matchedIso      = $this->matchTerritoryIsoByName($sovereign, (string) $row->name);
        $territoryIso    = $matchedIso ?? '?';
        $rasterAvailable = $matchedIso !== null
            ? $this->territoryRasterExists($matchedIso)
            : null;

        // Sample neighbouring sibling rows (other ADM2 entries under the
        // same sovereign) so the operator can sanity-check the population
        // expectation: similar small-island-state populations should give
        // a rough baseline.
        $siblings = DB::select(
            "
            SELECT j.id::text, j.name, j.population,
                   ROUND((ST_Area(j.geom::geography) / 1000000)::numeric, 2) AS area_km2
            FROM jurisdictions j
            WHERE j.deleted_at IS NULL
              AND j.iso_code = :iso
              AND j.adm_level = :lvl
              AND j.id != :self
            ORDER BY ABS(j.population - :pop) ASC NULLS LAST
            LIMIT 5
            ",
            ['iso' => $row->iso_code, 'lvl' => $row->adm_level, 'self' => $jurisdictionId, 'pop' => $row->population ?? 0]
        );

        return [
            'row'               => (array) $row,
            'sovereign'         => $sovereign,
            'territory_iso'     => $territoryIso,
            'raster_available'  => $rasterAvailable,
            'raster_path_hint'  => $matchedIso !== null
                ? sprintf('/archive/worldpop_100m_latest/%s/%s_pop_2023_*.tif', $matchedIso, strtolower($matchedIso))
                : null,
            'siblings'          => array_map(fn ($s) => (array) $s, $siblings),
            'decision'          => $this->getDecisionPayload('sovereign_territories', $jurisdictionId),
        ];
    }

    /**
     * Best-effort match: given a sovereign ISO and a row name, pick the
     * territory ISO whose name pattern matches. Returns null if no match.
     */
    private function matchTerritoryIsoByName(string $sovereign, string $rowName): ?string
    {
        $sovereign = strtoupper($sovereign);
        if (! isset(self::SOVEREIGN_TERRITORIES[$sovereign])) {
            return null;
        }
        // Reuse the territoryNamePatterns logic — but iterate per-iso to
        // figure out which one matched.
        $rowLower = mb_strtolower($rowName);
        foreach (self::SOVEREIGN_TERRITORIES[$sovereign] as $iso) {
            $pattern = $this->territoryNamePatterns([$iso]);
            // PHP regex needs delimiters; PostgreSQL's POSIX pattern works
            // when wrapped — convert to a PHP regex.
            $php = '/' . str_replace('/', '\/', $pattern) . '/i';
            if (@preg_match($php, $rowName)) {
                return $iso;
            }
        }
        return null;
    }

    /**
     * Check whether the territory's own WorldPop TIF exists in the
     * /archive bind mount. Returns null when the ETL container's archive
     * isn't reachable from PHP (in which case we just don't show the hint).
     */
    private function territoryRasterExists(string $territoryIso): ?bool
    {
        $iso = strtoupper($territoryIso);
        $base = '/archive/worldpop_100m_latest/' . $iso;
        if (! is_dir($base)) {
            return null;   // archive not visible from PHP container — unknown
        }
        $glob = glob($base . '/*_pop_2023_*.tif') ?: [];
        return count($glob) > 0;
    }

    // ─── Decision persistence ────────────────────────────────────────────────

    /**
     * Suggested decision values per category. Frontend renders these as
     * radio options; backend doesn't enforce — operators may save anything.
     */
    public const DECISION_VALUES = [
        'population_gaps' => [
            'confirmed_zero'      => 'Confirmed zero (genuinely uninhabited)',
            'will_fix_manually'   => 'Will fix manually (re-run ETL or edit)',
            'unknown'             => 'Unknown — leave for later',
        ],
        'aggregation_discrepancies' => [
            'trust_national'      => 'Trust the national value (children sum is wrong)',
            'trust_children'      => 'Trust the children sum (national value is wrong)',
            'polygon_artifact'    => 'Polygon-precision artifact — accept as-is',
            'investigate'         => 'Investigate further (not decided yet)',
        ],
        'orphans' => [
            'true_orphan'         => 'Genuinely top-level (no parent expected)',
            'pick_parent'         => 'Pick a parent (operator chose from candidates)',
            'delete'              => 'Delete this row',
            'unknown'             => 'Unknown — leave for later',
        ],
        'sovereign_territories' => [
            'will_load_raster'    => 'Will load this territory\'s WorldPop raster',
            'treat_as_zero'       => 'Treat population as zero (intentional)',
            'flag_for_phase_j'    => 'Flag for the Phase J auto-loader',
            'unknown'             => 'Unknown — leave for later',
        ],
    ];

    /**
     * Persist (or update) a per-row decision. Idempotent — re-saving updates
     * the existing record's decision/note rather than inserting a duplicate.
     *
     * @return array  The persisted decision payload, including timestamps.
     */
    public function recordDecision(
        string $category,
        string $jurisdictionId,
        string $decision,
        ?string $note,
    ): array {
        $existing = DataReviewDecision::query()
            ->where('category', $category)
            ->where('jurisdiction_id', $jurisdictionId)
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            $existing->decision = $decision;
            $existing->note     = $note;
            $existing->save();
            return $this->serializeDecision($existing);
        }

        $row = new DataReviewDecision([
            'id'              => (string) Str::uuid(),
            'category'        => $category,
            'jurisdiction_id' => $jurisdictionId,
            'decision'        => $decision,
            'note'            => $note,
        ]);
        $row->save();
        return $this->serializeDecision($row);
    }

    /**
     * Get the existing decision (if any) for a (category, jurisdiction_id)
     * pair, formatted for the detail panel.
     */
    public function getDecisionPayload(string $category, string $jurisdictionId): ?array
    {
        $row = DataReviewDecision::query()
            ->where('category', $category)
            ->where('jurisdiction_id', $jurisdictionId)
            ->whereNull('deleted_at')
            ->first();
        return $row ? $this->serializeDecision($row) : null;
    }

    /**
     * Map ids that already have a decision so the row table can mark them
     * as "✓ decided" in the list view without an N+1 fetch.
     *
     * @param  list<string>  $jurisdictionIds
     * @return array<string,array>  jurisdiction_id → decision payload
     */
    public function decisionsForRows(string $category, array $jurisdictionIds): array
    {
        if (empty($jurisdictionIds)) return [];
        $rows = DataReviewDecision::query()
            ->where('category', $category)
            ->whereIn('jurisdiction_id', $jurisdictionIds)
            ->whereNull('deleted_at')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->jurisdiction_id] = $this->serializeDecision($r);
        }
        return $out;
    }

    private function serializeDecision(DataReviewDecision $d): array
    {
        return [
            'id'              => $d->id,
            'category'        => $d->category,
            'jurisdiction_id' => $d->jurisdiction_id,
            'decision'        => $d->decision,
            'note'            => $d->note,
            'updated_at'      => $d->updated_at?->toIso8601String(),
        ];
    }
}
