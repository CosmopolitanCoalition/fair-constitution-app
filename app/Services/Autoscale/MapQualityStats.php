<?php

namespace App\Services\Autoscale;

use App\Models\AutoscaleRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MAP QUALITY STATISTICS (operator order 2026-09-05): the planet-wide
 * quality of a finished run, in the shape of the map view's MAP QUALITY
 * panel, over every ACTIVE map — for ALL layers and for EACH layer (the
 * Step 3 card's tabs, operator order 2026-09-05 evening).
 *
 *  Proportional Population District Maps (Type A) — constitutional legality
 *  (seat drift, floor exceptions, bonus seats, review), community integrity
 *  on the composite maps (whole-jurisdiction districts vs line-split pieces),
 *  leaf maps (at large vs line-split, by the ladder rung that filed),
 *  constitutional contiguity, population equality, uniform political
 *  diversity (drawn seat vectors vs the map view's Optimal), shape
 *  compactness — each row a count and a population.
 *  Equal-Constituent Jurisdiction Maps (Type B) — legality (seat breach,
 *  unassigned, empty panels, seat identity), chamber shapes (meet floor, the
 *  claim ladder below the floor by rung, tiny parts, clumped, empty),
 *  contiguity (island breaks apart from spread-law breaks), uniform political
 *  diversity (spread).
 *
 * Computed once per run (the done flip queues MapQualityStatsJob;
 * `autoscale:quality-stats` recomputes on demand) and cached on the run
 * row; the Step 3 poll reads the cache. The set-based parts are SQL over
 * indexed columns grouped by the map's layer (no geometry); the Type B
 * contiguity walk and the Type A diversity check run in bounded chunks
 * with a progress beat (ETL law).
 */
class MapQualityStats
{
    public const CHUNK = 500;

    public const LEVEL_LABELS = [
        0 => 'Planet', 1 => 'Countries', 2 => 'States / Provinces',
        3 => 'Counties', 4 => 'Municipalities', 5 => 'Townships', 6 => 'Neighborhoods',
    ];

    private const LADDER = ['shortest', 'box', 'community_cells', 'vertical_strips', 'horizontal_strips', 'components', 'mask'];

    /**
     * @param  callable(string):void|null  $tick  progress beat
     * @return array<string,mixed>
     */
    public function compute(?callable $tick = null): array
    {
        $beat = static function (string $msg) use ($tick): void { if ($tick) { $tick($msg); } };
        $t0 = microtime(true);
        $floor   = \App\Services\ConstitutionalDefaults::floor();
        $ceiling = \App\Services\ConstitutionalDefaults::ceiling();

        // ── Type A: districts on active maps, per layer of the map's header. ──
        $beat('Type A districts: aggregating by layer');
        $aRows = DB::select("
            WITH d AS (
                SELECT d.id, d.map_id, d.seats, d.bonus_seats, d.floor_override, d.is_contiguous,
                       d.convex_hull_ratio, d.fractional_seats, d.actual_population, h.kind, h.adm_level,
                       (h.child_count > 0) AS composite_map,
                       EXISTS (SELECT 1 FROM legislature_district_jurisdictions x
                                WHERE x.district_id = d.id AND x.subdivision_id IS NOT NULL) AS line_split
                  FROM legislature_districts d
                  JOIN legislature_district_maps m ON m.id = d.map_id AND m.status = 'active' AND m.deleted_at IS NULL
                  JOIN apportionment_ledger h ON h.legislature_id = d.legislature_id
                 WHERE d.deleted_at IS NULL
            )
            SELECT adm_level,
                   COUNT(DISTINCT map_id)                                            AS maps,
                   COUNT(*)                                                         AS districts,
                   COALESCE(SUM(seats), 0)                                          AS seats,
                   COALESCE(SUM(bonus_seats), 0)                                    AS bonus_seats,
                   COUNT(DISTINCT map_id) FILTER (WHERE bonus_seats > 0)            AS bonus_maps,
                   COUNT(*) FILTER (WHERE floor_override)                            AS floor_overrides,
                   COUNT(*) FILTER (WHERE seats > ?)                                 AS over_ceiling,
                   COUNT(*) FILTER (WHERE seats < ? AND NOT floor_override)          AS sub_floor_unflagged,
                   COALESCE(SUM(actual_population), 0)                              AS pop,
                   COUNT(DISTINCT map_id) FILTER (WHERE composite_map)                                   AS integrity_maps,
                   COUNT(*) FILTER (WHERE composite_map AND NOT line_split)                            AS intact_count,
                   COALESCE(SUM(actual_population) FILTER (WHERE composite_map AND NOT line_split), 0) AS intact_pop,
                   COUNT(*) FILTER (WHERE composite_map AND line_split)                                AS segmented_count,
                   COALESCE(SUM(actual_population) FILTER (WHERE composite_map AND line_split), 0)     AS segmented_pop,
                   COUNT(*) FILTER (WHERE kind = 'single')                                             AS at_large_maps,
                   COALESCE(SUM(actual_population) FILTER (WHERE kind = 'single'), 0)                  AS at_large_pop,
                   COUNT(DISTINCT map_id) FILTER (WHERE kind = 'sweep' AND NOT composite_map)          AS leaf_split_maps,
                   COALESCE(SUM(actual_population) FILTER (WHERE kind = 'sweep' AND NOT composite_map), 0) AS leaf_split_pop,
                   COUNT(*) FILTER (WHERE is_contiguous IS NOT FALSE)                AS contiguous_count,
                   COALESCE(SUM(actual_population) FILTER (WHERE is_contiguous IS NOT FALSE), 0) AS contiguous_pop,
                   COUNT(*) FILTER (WHERE is_contiguous = FALSE)                     AS non_contiguous_count,
                   COALESCE(SUM(actual_population) FILTER (WHERE is_contiguous = FALSE), 0) AS non_contiguous_pop,
                   COUNT(*) FILTER (WHERE kind = 'sweep' AND seats > 0 AND fractional_seats IS NOT NULL) AS eq_count,
                   COALESCE(SUM(ABS(fractional_seats / NULLIF(seats, 0) - 1)) FILTER (WHERE kind = 'sweep' AND seats > 0), 0) AS eq_dev_sum,
                   COUNT(*) FILTER (WHERE kind = 'sweep' AND seats > 0 AND ABS(fractional_seats / seats - 1) <= 0.05) AS eq_good,
                   COALESCE(SUM(actual_population) FILTER (WHERE kind = 'sweep' AND seats > 0 AND ABS(fractional_seats / seats - 1) <= 0.05), 0) AS eq_good_pop,
                   COUNT(*) FILTER (WHERE kind = 'sweep' AND seats > 0 AND ABS(fractional_seats / seats - 1) > 0.05 AND ABS(fractional_seats / seats - 1) <= 0.10) AS eq_ok,
                   COALESCE(SUM(actual_population) FILTER (WHERE kind = 'sweep' AND seats > 0 AND ABS(fractional_seats / seats - 1) > 0.05 AND ABS(fractional_seats / seats - 1) <= 0.10), 0) AS eq_ok_pop,
                   COUNT(*) FILTER (WHERE kind = 'sweep' AND seats > 0 AND ABS(fractional_seats / seats - 1) > 0.10) AS eq_bad,
                   COALESCE(SUM(actual_population) FILTER (WHERE kind = 'sweep' AND seats > 0 AND ABS(fractional_seats / seats - 1) > 0.10), 0) AS eq_bad_pop,
                   COALESCE(SUM(actual_population) FILTER (WHERE kind = 'sweep' AND seats > 0), 0) AS eq_pop,
                   COALESCE(SUM(convex_hull_ratio), 0)                               AS chr_sum,
                   COUNT(*) FILTER (WHERE convex_hull_ratio IS NOT NULL)              AS chr_count,
                   COUNT(*) FILTER (WHERE convex_hull_ratio >= 0.70)                  AS chr_compact,
                   COALESCE(SUM(actual_population) FILTER (WHERE convex_hull_ratio >= 0.70), 0) AS chr_compact_pop,
                   COUNT(*) FILTER (WHERE convex_hull_ratio >= 0.50 AND convex_hull_ratio < 0.70) AS chr_moderate,
                   COALESCE(SUM(actual_population) FILTER (WHERE convex_hull_ratio >= 0.50 AND convex_hull_ratio < 0.70), 0) AS chr_moderate_pop,
                   COUNT(*) FILTER (WHERE convex_hull_ratio < 0.50)                   AS chr_irregular,
                   COALESCE(SUM(actual_population) FILTER (WHERE convex_hull_ratio < 0.50), 0) AS chr_irregular_pop,
                   COALESCE(SUM(actual_population) FILTER (WHERE convex_hull_ratio IS NOT NULL), 0) AS chr_pop
              FROM d
             GROUP BY adm_level
        ", [$ceiling, $floor]);

        $beat('Type A ledger: exactness by layer');
        $ledgerRows = DB::select("
            SELECT adm_level,
                   COUNT(*) FILTER (WHERE kind = 'sweep' AND map_status = 'done')                            AS sweeps_done,
                   COUNT(*) FILTER (WHERE kind = 'sweep' AND map_status = 'done' AND COALESCE(drift, 0) = 0) AS sweeps_exact,
                   COUNT(*) FILTER (WHERE map_status IN ('review', 'failed'))                                AS maps_review
              FROM apportionment_ledger
             GROUP BY adm_level
        ");

        // Leaf line-split maps by the rung that filed. The pieces' own method
        // column carries the filing form's stamp ('manual' for every auto-filed
        // piece), so the template is read from the scope's step timings: every
        // rung tried leaves its key under 'n', so the furthest rung present is
        // the one that filed; a grind-shunted scope (force_box) filed as box.
        // Scopes drawn before the timings existed read 'unrecorded'.
        $beat('Type A leaf maps: methods by layer');
        $methodRows = DB::select("
            SELECT h.adm_level,
                   CASE
                     WHEN s.force_box                                                    THEN 'box'
                     WHEN jsonb_exists(s.step_timings -> 'n', 'leaf.components')        THEN 'components'
                     WHEN jsonb_exists(s.step_timings -> 'n', 'leaf.horizontal_strips') THEN 'horizontal_strips'
                     WHEN jsonb_exists(s.step_timings -> 'n', 'leaf.vertical_strips')   THEN 'vertical_strips'
                     WHEN jsonb_exists(s.step_timings -> 'n', 'leaf.community_cells')   THEN 'community_cells'
                     WHEN jsonb_exists(s.step_timings -> 'n', 'leaf.box')               THEN 'box'
                     WHEN jsonb_exists(s.step_timings -> 'n', 'leaf.mask')              THEN 'mask'
                     WHEN jsonb_exists(s.step_timings -> 'n', 'leaf.shortest')          THEN 'shortest'
                     ELSE 'unrecorded'
                   END AS method,
                   COUNT(*) AS maps
              FROM apportionment_ledger_scopes s
              JOIN apportionment_ledger h ON h.legislature_id = s.legislature_id
             WHERE s.scope_kind = 'type_a' AND s.is_leaf IS TRUE AND s.status = 'done'
               AND h.child_count = 0 AND h.map_status = 'done'
             GROUP BY 1, 2
        ");

        // ── Type B: groupings, panels, legality, shapes, diversity, per layer. ──
        $beat('Type B groupings: aggregating by layer');
        $bRows = DB::select("
            WITH g AS (
                SELECT g.id, g.legislature_id, g.panel_count, g.seats_total, g.rep_floor, l.type_b_seats, l.type_a_seats, l.jurisdiction_id, h.adm_level
                  FROM legislature_type_b_groupings g
                  JOIN legislatures l ON l.id = g.legislature_id AND l.deleted_at IS NULL
                  JOIN apportionment_ledger h ON h.legislature_id = g.legislature_id
                 WHERE g.status = 'active' AND g.deleted_at IS NULL
            ),
            kids AS (
                SELECT g.id AS gid, COUNT(c.id) AS n, COALESCE(SUM(GREATEST(c.population, 0)), 0) AS pop
                  FROM g JOIN jurisdictions c ON c.parent_id = g.jurisdiction_id AND c.deleted_at IS NULL
                 GROUP BY g.id
            ),
            panels AS (
                SELECT p.grouping_id AS gid, COUNT(*) AS panels, SUM(p.seats) AS seats,
                       MAX(p.member_count) - MIN(p.member_count) AS spread,
                       COUNT(*) FILTER (WHERE p.member_count = 0) AS empty,
                       MIN(p.seats) AS min_seats
                  FROM legislature_type_b_panels p JOIN g ON g.id = p.grouping_id
                 WHERE p.deleted_at IS NULL
                 GROUP BY p.grouping_id
            ),
            mem AS (
                SELECT pj.grouping_id AS gid, COUNT(DISTINCT pj.jurisdiction_id) AS assigned
                  FROM legislature_type_b_panel_jurisdictions pj JOIN g ON g.id = pj.grouping_id
                 GROUP BY pj.grouping_id
            )
            SELECT g.adm_level,
                   COUNT(*)                                                                   AS groupings,
                   COUNT(*) FILTER (WHERE COALESCE(panels.panels, 0) < kids.n AND COALESCE(panels.panels, 0) > 0) AS clumped,
                   COUNT(*) FILTER (WHERE COALESCE(panels.panels, 0) = kids.n)                AS ungrouped,
                   COUNT(*) FILTER (WHERE COALESCE(panels.panels, 0) = kids.n AND g.rep_floor >= ? AND panels.min_seats >= g.rep_floor) AS ungrouped_meet_floor,
                   COUNT(*) FILTER (WHERE COALESCE(panels.panels, 0) = kids.n AND g.rep_floor = 4 AND g.rep_floor < ? AND panels.min_seats >= g.rep_floor) AS ungrouped_rung4,
                   COUNT(*) FILTER (WHERE COALESCE(panels.panels, 0) = kids.n AND g.rep_floor = 3 AND panels.min_seats >= g.rep_floor) AS ungrouped_rung3,
                   COUNT(*) FILTER (WHERE COALESCE(panels.panels, 0) = kids.n AND g.rep_floor = 2 AND panels.min_seats >= g.rep_floor) AS ungrouped_rung2,
                   COUNT(*) FILTER (WHERE COALESCE(panels.panels, 0) = kids.n AND panels.min_seats < g.rep_floor) AS ungrouped_tiny,
                   COUNT(*) FILTER (WHERE COALESCE(panels.panels, 0) = 0)                     AS zero_panel,
                   COALESCE(SUM(panels.panels), 0)                                            AS panels,
                   COALESCE(SUM(g.seats_total), 0)                                            AS seats,
                   COUNT(*) FILTER (WHERE g.seats_total > LEAST(g.type_a_seats, GREATEST(0, kids.pop - g.type_a_seats))) AS breach,
                   COUNT(*) FILTER (WHERE COALESCE(panels.panels, 0) > 0 AND COALESCE(mem.assigned, 0) < kids.n) AS unassigned_chambers,
                   COALESCE(SUM(kids.n - COALESCE(mem.assigned, 0)) FILTER (WHERE COALESCE(panels.panels, 0) > 0 AND COALESCE(mem.assigned, 0) < kids.n), 0) AS unassigned_parts,
                   COALESCE(SUM(panels.empty), 0)                                             AS empty_panels,
                   COUNT(*) FILTER (WHERE COALESCE(panels.seats, 0) <> g.seats_total OR g.seats_total <> g.type_b_seats) AS identity_mismatch,
                   COUNT(*) FILTER (WHERE COALESCE(panels.spread, 0) = 0)                     AS spread0,
                   COUNT(*) FILTER (WHERE COALESCE(panels.spread, 0) = 1)                     AS spread1,
                   COUNT(*) FILTER (WHERE COALESCE(panels.spread, 0) > 1)                     AS spread_over,
                   COALESCE(SUM(kids.n), 0)                                                   AS constituents
              FROM g JOIN kids ON kids.gid = g.id
              LEFT JOIN panels ON panels.gid = g.id
              LEFT JOIN mem ON mem.gid = g.id
             GROUP BY g.adm_level
        ", [$floor, $floor]);

        // ── Type B contiguity: one chamber at a time, chunked, per layer. ──
        $contig = [];
        $lastId = '00000000-0000-0000-0000-000000000000';   // keyset start: below every uuid
        $chambers = 0;
        while (true) {
            $rows = DB::table('legislature_type_b_groupings as g')
                ->join('legislatures as l', 'l.id', '=', 'g.legislature_id')
                ->join('apportionment_ledger as h', 'h.legislature_id', '=', 'g.legislature_id')
                ->where('g.status', 'active')->whereNull('g.deleted_at')
                ->where('g.id', '>', $lastId)
                ->orderBy('g.id')
                ->limit(self::CHUNK)
                ->get(['g.id', 'l.jurisdiction_id', 'h.adm_level']);
            if ($rows->isEmpty()) {
                break;
            }
            foreach ($rows as $r) {
                $lastId = (string) $r->id;
                $lvl = (int) $r->adm_level;
                $contig[$lvl] ??= self::emptyContig();
                $this->chamberContiguity((string) $r->id, (string) $r->jurisdiction_id, $contig[$lvl]);
            }
            $chambers += $rows->count();
            $beat("Type B contiguity: {$chambers} chambers");
        }

        // ── Type A uniform political diversity, per layer. ──
        $diversity = $this->typeADiversity($beat, $floor, $ceiling);

        // ── Assemble: one block per layer, and the ALL block by summation. ──
        $byLevel = static function (array $rows): array {
            $out = [];
            foreach ($rows as $r) { $out[(int) $r->adm_level] = $r; }
            return $out;
        };
        $aBy = $byLevel($aRows);
        $lBy = $byLevel($ledgerRows);
        $bBy = $byLevel($bRows);
        $methodsBy = [];
        foreach ($methodRows as $r) {
            $methodsBy[(int) $r->adm_level][(string) $r->method] = (int) $r->maps;
        }
        $allLevels = array_unique(array_merge(array_keys($aBy), array_keys($lBy), array_keys($bBy), array_keys($contig), array_keys($diversity)));
        sort($allLevels);
        $levels = [];
        foreach ($allLevels as $lvl) {
            $levels[(string) $lvl] = self::block(
                $aBy[$lvl] ?? null, $lBy[$lvl] ?? null, $methodsBy[$lvl] ?? [],
                $bBy[$lvl] ?? null, $contig[$lvl] ?? self::emptyContig(), $diversity[$lvl] ?? self::emptyDiversity(),
                $floor
            );
        }
        $all = self::block(
            self::sumRows($aRows), self::sumRows($ledgerRows), self::sumMethods($methodsBy),
            self::sumRows($bRows), self::sumAssoc(array_values($contig), self::emptyContig()),
            self::sumAssoc(array_values($diversity), self::emptyDiversity()),
            $floor
        );

        $seconds = round(microtime(true) - $t0, 1);
        $labels = [];
        foreach ($allLevels as $lvl) {
            $labels[(string) $lvl] = self::LEVEL_LABELS[$lvl] ?? "Level {$lvl}";
        }

        return [
            'computed_at'  => now()->toIso8601String(),
            'seconds'      => $seconds,
            'level_labels' => $labels,
            'type_a'       => $all['type_a'],
            'type_b'       => $all['type_b'],
            'levels'       => ['all' => $all] + $levels,
        ];
    }

    /** Sum every numeric column of a set of per-layer rows into one row. */
    private static function sumRows(array $rows): ?object
    {
        if ($rows === []) {
            return null;
        }
        $sum = [];
        foreach ($rows as $r) {
            foreach ((array) $r as $k => $v) {
                if ($k === 'adm_level') {
                    continue;
                }
                $sum[$k] = ($sum[$k] ?? 0) + (float) $v;
            }
        }

        return (object) $sum;
    }

    /** @param array<int,array<string,int>> $methodsBy */
    private static function sumMethods(array $methodsBy): array
    {
        $out = [];
        foreach ($methodsBy as $m) {
            foreach ($m as $k => $v) {
                $out[$k] = ($out[$k] ?? 0) + $v;
            }
        }

        return $out;
    }

    /** @param list<array<string,int>> $parts */
    private static function sumAssoc(array $parts, array $empty): array
    {
        $out = $empty;
        foreach ($parts as $p) {
            foreach ($p as $k => $v) {
                $out[$k] = ($out[$k] ?? 0) + $v;
            }
        }

        return $out;
    }

    private static function emptyContig(): array
    {
        return ['contiguous_count' => 0, 'contiguous_pop' => 0, 'forced_count' => 0, 'forced_pop' => 0,
                'spread_count' => 0, 'spread_pop' => 0, 'chambers' => 0];
    }

    private static function emptyDiversity(): array
    {
        return ['scopes' => 0, 'optimal' => 0, 'optimal_with_singles' => 0, 'suboptimal' => 0,
                'pop' => 0, 'optimal_pop' => 0, 'suboptimal_pop' => 0];
    }

    /** One card block (the same shape for a layer and for all layers). */
    private static function block(?object $a, ?object $l, array $methods, ?object $b, array $contig, array $diversity, int $floor): array
    {
        $g = static fn (?object $o, string $k) => (int) ($o->$k ?? 0);
        $eqCount  = $g($a, 'eq_count');
        $chrCount = $g($a, 'chr_count');
        $rank = static fn (string $m) => (($i = array_search($m, self::LADDER, true)) === false ? 99 : $i);
        $sorted = $methods;
        uksort($sorted, static fn ($x, $y) => $rank((string) $x) <=> $rank((string) $y));

        return [
            'type_a' => [
                'maps'        => $g($a, 'maps'),
                'districts'   => $g($a, 'districts'),
                'seats'       => $g($a, 'seats'),
                'population'  => $g($a, 'pop'),
                'legality'    => [
                    'sweeps_done'         => $g($l, 'sweeps_done'),
                    'sweeps_exact'        => $g($l, 'sweeps_exact'),
                    'maps_review'         => $g($l, 'maps_review'),
                    'over_ceiling'        => $g($a, 'over_ceiling'),
                    'sub_floor_unflagged' => $g($a, 'sub_floor_unflagged'),
                    'floor_overrides'     => $g($a, 'floor_overrides'),
                    'bonus_seats'         => $g($a, 'bonus_seats'),
                    'bonus_maps'          => $g($a, 'bonus_maps'),
                ],
                'integrity'   => [
                    'maps' => $g($a, 'integrity_maps'),
                    'intact_count' => $g($a, 'intact_count'), 'intact_pop' => $g($a, 'intact_pop'),
                    'segmented_count' => $g($a, 'segmented_count'), 'segmented_pop' => $g($a, 'segmented_pop'),
                ],
                'leaves'      => [
                    'at_large_maps' => $g($a, 'at_large_maps'), 'at_large_pop' => $g($a, 'at_large_pop'),
                    'line_split_maps' => $g($a, 'leaf_split_maps'), 'line_split_pop' => $g($a, 'leaf_split_pop'),
                    'methods' => $sorted,
                ],
                'contiguity'  => [
                    'contiguous_count' => $g($a, 'contiguous_count'), 'contiguous_pop' => $g($a, 'contiguous_pop'),
                    'non_contiguous_count' => $g($a, 'non_contiguous_count'), 'non_contiguous_pop' => $g($a, 'non_contiguous_pop'),
                ],
                'equality'    => [
                    'district_count' => $eqCount,
                    'avg_pct' => $eqCount > 0 ? round(((float) ($a->eq_dev_sum ?? 0)) / $eqCount * 100, 2) : 0,
                    'pop' => $g($a, 'eq_pop'),
                    'good_count' => $g($a, 'eq_good'), 'good_pop' => $g($a, 'eq_good_pop'),
                    'ok_count' => $g($a, 'eq_ok'), 'ok_pop' => $g($a, 'eq_ok_pop'),
                    'bad_count' => $g($a, 'eq_bad'), 'bad_pop' => $g($a, 'eq_bad_pop'),
                ],
                'diversity'   => $diversity,
                'compactness' => [
                    'mean' => $chrCount > 0 ? round(((float) ($a->chr_sum ?? 0)) / $chrCount, 4) : 0,
                    'count' => $chrCount, 'pop' => $g($a, 'chr_pop'),
                    'compact_count' => $g($a, 'chr_compact'), 'compact_pop' => $g($a, 'chr_compact_pop'),
                    'moderate_count' => $g($a, 'chr_moderate'), 'moderate_pop' => $g($a, 'chr_moderate_pop'),
                    'irregular_count' => $g($a, 'chr_irregular'), 'irregular_pop' => $g($a, 'chr_irregular_pop'),
                ],
            ],
            'type_b' => [
                'groupings'    => $g($b, 'groupings'),
                'clumped'      => $g($b, 'clumped'),
                'ungrouped'    => $g($b, 'ungrouped'),
                'floor'        => $floor,
                'ungrouped_meet_floor' => $g($b, 'ungrouped_meet_floor'),
                'ungrouped_rung4'      => $g($b, 'ungrouped_rung4'),
                'ungrouped_rung3'      => $g($b, 'ungrouped_rung3'),
                'ungrouped_rung2'      => $g($b, 'ungrouped_rung2'),
                'ungrouped_tiny'       => $g($b, 'ungrouped_tiny'),
                'zero_panel'   => $g($b, 'zero_panel'),
                'panels'       => $g($b, 'panels'),
                'seats'        => $g($b, 'seats'),
                'constituents' => $g($b, 'constituents'),
                'legality'     => [
                    'breach' => $g($b, 'breach'), 'unassigned_chambers' => $g($b, 'unassigned_chambers'),
                    'unassigned_parts' => $g($b, 'unassigned_parts'), 'empty_panels' => $g($b, 'empty_panels'),
                    'identity_mismatch' => $g($b, 'identity_mismatch'),
                ],
                'contiguity'   => $contig,
                'diversity'    => [
                    'spread0' => $g($b, 'spread0'), 'spread1' => $g($b, 'spread1'), 'spread_over' => $g($b, 'spread_over'),
                ],
            ],
        ];
    }

    /**
     * TYPE A UNIFORM POLITICAL DIVERSITY (operator order 2026-09-05): for every
     * composite scope (a scope whose jurisdiction has children; the line-split
     * leaves are not judged here), the drawn seat vector at that scope is
     * compared with the map view's OPTIMAL — the balanced partition of the
     * scope's composed seats into d districts, d chosen in [ceil(P/ceiling),
     * floor(P/floor)] for the lowest average Droop threshold (ties to fewer
     * districts), with a one-jurisdiction district that rounds past that
     * partition's largest size counted as a lawful single beside it (the map
     * view's expansion singles, in parens). A scope whose composed seats fall
     * below the floor has no Optimal and is not counted. Chunked by scope id,
     * accumulated per layer of the map's header.
     *
     * @return array<int,array<string,int>>  layer => accumulator
     */
    private function typeADiversity(callable $beat, int $floor, int $ceiling): array
    {
        $acc = [];
        $lastId = '00000000-0000-0000-0000-000000000000';
        $walked = 0;
        while (true) {
            $scopes = DB::select("
                SELECT s.id, s.legislature_id, s.scope_jurisdiction_id, h.map_id, h.adm_level
                  FROM apportionment_ledger_scopes s
                  JOIN apportionment_ledger h ON h.legislature_id = s.legislature_id
                 WHERE s.scope_kind = 'type_a' AND COALESCE(s.is_leaf, FALSE) = FALSE
                   AND s.status = 'done' AND h.map_status = 'done' AND h.map_id IS NOT NULL
                   AND s.id > ?
                 ORDER BY s.id
                 LIMIT ?
            ", [$lastId, 4 * self::CHUNK]);
            if ($scopes === []) {
                break;
            }
            $mapIds = []; $jids = []; $want = [];
            foreach ($scopes as $s) {
                $lastId = (string) $s->id;
                $mapIds[(string) $s->map_id] = true;
                $jids[(string) $s->scope_jurisdiction_id] = true;
                $want[(string) $s->map_id . '|' . (string) $s->scope_jurisdiction_id] = (int) $s->adm_level;
            }
            $rows = DB::table('legislature_districts as d')
                ->whereIn('d.map_id', array_keys($mapIds))
                ->whereIn('d.jurisdiction_id', array_keys($jids))
                ->whereNull('d.deleted_at')
                ->selectRaw('d.map_id, d.jurisdiction_id, d.seats, d.actual_population,
                             (SELECT COUNT(*) FROM legislature_district_jurisdictions x WHERE x.district_id = d.id) AS members')
                ->get();
            $byScope = [];
            foreach ($rows as $r) {
                $key = (string) $r->map_id . '|' . (string) $r->jurisdiction_id;
                if (! isset($want[$key])) {
                    continue;
                }
                $byScope[$key][] = ['seats' => (int) $r->seats, 'members' => (int) $r->members, 'pop' => (int) $r->actual_population];
            }
            foreach ($byScope as $key => $districts) {
                $verdict = self::scopeVerdict($districts, $floor, $ceiling);
                if ($verdict === null) {
                    continue;
                }
                $lvl = $want[$key];
                $acc[$lvl] ??= self::emptyDiversity();
                $pop = array_sum(array_column($districts, 'pop'));
                $acc[$lvl]['scopes']++;
                $acc[$lvl]['pop'] += $pop;
                if ($verdict === 'suboptimal') {
                    $acc[$lvl]['suboptimal']++;
                    $acc[$lvl]['suboptimal_pop'] += $pop;
                } else {
                    $acc[$lvl]['optimal']++;
                    $acc[$lvl]['optimal_pop'] += $pop;
                    if ($verdict === 'singles') {
                        $acc[$lvl]['optimal_with_singles']++;
                    }
                }
            }
            $walked += count($scopes);
            $beat("Type A diversity: {$walked} composite scopes");
        }

        return $acc;
    }

    /**
     * One composite scope: 'optimal' (the drawn seat vector is the balanced
     * partition), 'singles' (optimal once its lawful one-jurisdiction singles
     * stand beside the partition), 'suboptimal', or null (no Optimal exists:
     * composed seats below the floor).
     *
     * @param list<array{seats:int, members:int, pop:int}> $districts
     */
    private static function scopeVerdict(array $districts, int $floor, int $ceiling): ?string
    {
        $current = array_map(static fn (array $d) => $d['seats'], $districts);
        sort($current);
        $pool      = array_sum($current);
        $singles   = [];
        $remaining = $districts;
        $best = null;
        for ($iter = 0; $iter < 20; $iter++) {
            if ($pool < $floor) {
                break;
            }
            $dMin = (int) ceil($pool / $ceiling);
            $dMax = (int) floor($pool / $floor);
            if ($dMin > $dMax) {
                break;
            }
            $best = null;
            for ($d = $dMin; $d <= $dMax; $d++) {
                $q = intdiv($pool, $d);
                $r = $pool % $d;
                $t = ($r / ($q + 2) + ($d - $r) / ($q + 1)) / $d;
                if ($best === null || $t < $best['t'] - 1e-9 || (abs($t - $best['t']) < 1e-9 && $d < $best['d'])) {
                    $best = ['d' => $d, 'q' => $q, 'r' => $r, 't' => $t];
                }
            }
            $maxAllowed = $best['q'] + ($best['r'] > 0 ? 1 : 0);
            // Lawful singles: one-jurisdiction districts rounding past the
            // partition's largest size leave the pool and stand beside it.
            $moved = false;
            foreach ($remaining as $i => $d) {
                if ($d['members'] === 1 && $d['seats'] > $maxAllowed) {
                    $singles[] = $d['seats'];
                    $pool -= $d['seats'];
                    unset($remaining[$i]);
                    $moved = true;
                }
            }
            if (! $moved) {
                break;
            }
        }
        if ($best === null) {
            return $singles === [] ? null : (count($remaining) === 0 ? 'singles' : 'suboptimal');
        }
        $canonical = array_merge(
            array_fill(0, $best['r'], $best['q'] + 1),
            array_fill(0, $best['d'] - $best['r'], $best['q']),
            $singles
        );
        sort($canonical);
        if ($canonical !== $current) {
            return 'suboptimal';
        }

        return $singles === [] ? 'optimal' : 'singles';
    }

    /** Store the stats on the run row. */
    public function store(AutoscaleRun $run, array $stats): void
    {
        AutoscaleRun::query()->whereKey($run->id)->update([
            'quality_stats'       => json_encode($stats),
            'quality_computed_at' => now(),
            'updated_at'          => now(),
        ]);
    }

    /** Compute + store for one run, logging the duration. */
    public function refresh(AutoscaleRun $run, ?callable $tick = null): array
    {
        $stats = $this->compute($tick);
        $this->store($run, $stats);
        Log::info('Map quality statistics computed', ['run_id' => (string) $run->id, 'seconds' => $stats['seconds']]);

        return $stats;
    }

    /**
     * One chamber's panels: contiguity per panel over the constituent graph,
     * populations summed per class. Bounded to the chamber's own children.
     *
     * @param array<string,int> $acc (mutated)
     */
    private function chamberContiguity(string $groupingId, string $parentId, array &$acc): void
    {
        $kids = DB::table('jurisdictions')->where('parent_id', $parentId)->whereNull('deleted_at')->get(['id', 'population']);
        $pop = []; $set = [];
        foreach ($kids as $k) { $pop[(string) $k->id] = max(0, (int) $k->population); $set[(string) $k->id] = true; }
        $adj = [];
        foreach (DB::table('jurisdiction_adjacency')->where('parent_id', $parentId)->where('dim', '>=', 1)->get(['j1', 'j2']) as $e) {
            $x = (string) $e->j1; $y = (string) $e->j2;
            if (isset($set[$x], $set[$y])) { $adj[$x][$y] = true; $adj[$y][$x] = true; }
        }
        $compOf = []; $cid = 0;
        foreach (array_keys($set) as $id) {
            if (isset($compOf[$id])) continue;
            $stack = [$id]; $compOf[$id] = $cid;
            while ($stack !== []) {
                $c = array_pop($stack);
                foreach ($adj[$c] ?? [] as $nb => $_) { if (! isset($compOf[$nb])) { $compOf[$nb] = $cid; $stack[] = $nb; } }
            }
            $cid++;
        }
        $panels = [];
        foreach (DB::table('legislature_type_b_panel_jurisdictions')->where('grouping_id', $groupingId)->get(['panel_id', 'jurisdiction_id']) as $m) {
            $panels[(string) $m->panel_id][] = (string) $m->jurisdiction_id;
        }
        $acc['chambers']++;
        foreach ($panels as $members) {
            $ppop = 0;
            foreach ($members as $m) { $ppop += $pop[$m] ?? 0; }
            if (count($members) <= 1) { $acc['contiguous_count']++; $acc['contiguous_pop'] += $ppop; continue; }
            $ms = array_flip($members);
            $seen = [$members[0] => true]; $stack = [$members[0]];
            while ($stack !== []) {
                $c = array_pop($stack);
                foreach ($adj[$c] ?? [] as $nb => $_) { if (isset($ms[$nb]) && ! isset($seen[$nb])) { $seen[$nb] = true; $stack[] = $nb; } }
            }
            if (count($seen) === count($members)) { $acc['contiguous_count']++; $acc['contiguous_pop'] += $ppop; continue; }
            $comps = [];
            foreach ($members as $m) { $comps[$compOf[$m] ?? -1] = true; }
            if (count($comps) > 1) { $acc['forced_count']++; $acc['forced_pop'] += $ppop; }
            else { $acc['spread_count']++; $acc['spread_pop'] += $ppop; }
        }
    }
}
