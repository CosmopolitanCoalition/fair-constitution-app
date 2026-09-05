<?php

namespace App\Services\Autoscale;

use App\Models\AutoscaleRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MAP QUALITY STATISTICS (operator order 2026-09-05): the planet-wide
 * quality of a finished run, in the shape of the map view's MAP QUALITY
 * panel, over every ACTIVE map.
 *
 *  Type A district maps — legality (seat drift, ceiling, floor exceptions,
 *  bonus seats), community integrity (districts on administrative borders
 *  vs line-split pieces), constitutional contiguity, population equality,
 *  shape compactness — each row a count and a population, the way the map
 *  view reads them.
 *  Type B panel maps — legality (seat breach, unassigned, empty panels,
 *  seat identity), constitutional contiguity (breaks forced by islands vs
 *  breaks the spread law paid for), uniform political diversity (spread).
 *
 * Computed once per run (the done flip queues MapQualityStatsJob;
 * `autoscale:quality-stats` recomputes on demand) and cached on the run
 * row; the Step 3 poll reads the cache. The Type A part is set-based SQL
 * over indexed columns (no geometry); the Type B contiguity part walks the
 * constituent graph one chamber at a time, in bounded chunks with a
 * progress beat (ETL law).
 */
class MapQualityStats
{
    public const CHUNK = 500;

    /**
     * @param  callable(string):void|null  $tick  progress beat
     * @return array<string,mixed>
     */
    public function compute(?callable $tick = null): array
    {
        $beat = static function (string $msg) use ($tick): void { if ($tick) { $tick($msg); } };
        $t0 = microtime(true);

        // ── Type A: districts on active maps, joined to the ledger for the
        // map class (composite/line-split sweep vs at-large single). ──────
        $beat('Type A districts: aggregating');
        $a = DB::selectOne("
            WITH d AS (
                SELECT d.id, d.map_id, d.seats, d.bonus_seats, d.floor_override, d.is_contiguous,
                       d.convex_hull_ratio, d.fractional_seats, d.actual_population, h.kind,
                       EXISTS (SELECT 1 FROM legislature_district_jurisdictions x
                                WHERE x.district_id = d.id AND x.subdivision_id IS NOT NULL) AS line_split
                  FROM legislature_districts d
                  JOIN legislature_district_maps m ON m.id = d.map_id AND m.status = 'active' AND m.deleted_at IS NULL
                  JOIN apportionment_ledger h ON h.legislature_id = d.legislature_id
                 WHERE d.deleted_at IS NULL
            )
            SELECT COUNT(DISTINCT map_id)                                            AS maps,
                   COUNT(*)                                                         AS districts,
                   COALESCE(SUM(seats), 0)                                          AS seats,
                   COALESCE(SUM(bonus_seats), 0)                                    AS bonus_seats,
                   COUNT(DISTINCT map_id) FILTER (WHERE bonus_seats > 0)            AS bonus_maps,
                   COUNT(*) FILTER (WHERE floor_override)                            AS floor_overrides,
                   COUNT(*) FILTER (WHERE seats > 9)                                 AS over_ceiling,
                   COUNT(*) FILTER (WHERE seats < 5 AND NOT floor_override)          AS sub_floor_unflagged,
                   COALESCE(SUM(actual_population), 0)                              AS pop,
                   COUNT(*) FILTER (WHERE NOT line_split)                            AS intact_count,
                   COALESCE(SUM(actual_population) FILTER (WHERE NOT line_split), 0) AS intact_pop,
                   COUNT(*) FILTER (WHERE line_split)                                AS segmented_count,
                   COALESCE(SUM(actual_population) FILTER (WHERE line_split), 0)     AS segmented_pop,
                   COUNT(*) FILTER (WHERE is_contiguous IS NOT FALSE)                AS contiguous_count,
                   COALESCE(SUM(actual_population) FILTER (WHERE is_contiguous IS NOT FALSE), 0) AS contiguous_pop,
                   COUNT(*) FILTER (WHERE is_contiguous = FALSE)                     AS non_contiguous_count,
                   COALESCE(SUM(actual_population) FILTER (WHERE is_contiguous = FALSE), 0) AS non_contiguous_pop,
                   COUNT(*) FILTER (WHERE kind = 'sweep' AND seats > 0 AND fractional_seats IS NOT NULL) AS eq_count,
                   AVG(ABS(fractional_seats / NULLIF(seats, 0) - 1)) FILTER (WHERE kind = 'sweep' AND seats > 0) AS eq_avg,
                   COUNT(*) FILTER (WHERE kind = 'sweep' AND seats > 0 AND ABS(fractional_seats / seats - 1) <= 0.05) AS eq_good,
                   COALESCE(SUM(actual_population) FILTER (WHERE kind = 'sweep' AND seats > 0 AND ABS(fractional_seats / seats - 1) <= 0.05), 0) AS eq_good_pop,
                   COUNT(*) FILTER (WHERE kind = 'sweep' AND seats > 0 AND ABS(fractional_seats / seats - 1) > 0.05 AND ABS(fractional_seats / seats - 1) <= 0.10) AS eq_ok,
                   COALESCE(SUM(actual_population) FILTER (WHERE kind = 'sweep' AND seats > 0 AND ABS(fractional_seats / seats - 1) > 0.05 AND ABS(fractional_seats / seats - 1) <= 0.10), 0) AS eq_ok_pop,
                   COUNT(*) FILTER (WHERE kind = 'sweep' AND seats > 0 AND ABS(fractional_seats / seats - 1) > 0.10) AS eq_bad,
                   COALESCE(SUM(actual_population) FILTER (WHERE kind = 'sweep' AND seats > 0 AND ABS(fractional_seats / seats - 1) > 0.10), 0) AS eq_bad_pop,
                   COALESCE(SUM(actual_population) FILTER (WHERE kind = 'sweep' AND seats > 0), 0) AS eq_pop,
                   AVG(convex_hull_ratio)                                             AS chr_mean,
                   COUNT(*) FILTER (WHERE convex_hull_ratio IS NOT NULL)              AS chr_count,
                   COUNT(*) FILTER (WHERE convex_hull_ratio >= 0.70)                  AS chr_compact,
                   COALESCE(SUM(actual_population) FILTER (WHERE convex_hull_ratio >= 0.70), 0) AS chr_compact_pop,
                   COUNT(*) FILTER (WHERE convex_hull_ratio >= 0.50 AND convex_hull_ratio < 0.70) AS chr_moderate,
                   COALESCE(SUM(actual_population) FILTER (WHERE convex_hull_ratio >= 0.50 AND convex_hull_ratio < 0.70), 0) AS chr_moderate_pop,
                   COUNT(*) FILTER (WHERE convex_hull_ratio < 0.50)                   AS chr_irregular,
                   COALESCE(SUM(actual_population) FILTER (WHERE convex_hull_ratio < 0.50), 0) AS chr_irregular_pop,
                   COALESCE(SUM(actual_population) FILTER (WHERE convex_hull_ratio IS NOT NULL), 0) AS chr_pop
              FROM d
        ");
        $beat('Type A ledger: exactness');
        $ledger = DB::selectOne("
            SELECT COUNT(*) FILTER (WHERE kind = 'sweep' AND map_status = 'done')                            AS sweeps_done,
                   COUNT(*) FILTER (WHERE kind = 'sweep' AND map_status = 'done' AND COALESCE(drift, 0) = 0) AS sweeps_exact,
                   COUNT(*) FILTER (WHERE map_status = 'done')                                               AS maps_done,
                   COUNT(*) FILTER (WHERE map_status IN ('review', 'failed'))                                AS maps_review
              FROM apportionment_ledger
        ");

        // ── Type B: groupings, panels, legality, diversity (set-based). ──
        $beat('Type B groupings: aggregating');
        $b = DB::selectOne("
            WITH g AS (
                SELECT g.id, g.legislature_id, g.panel_count, g.seats_total, g.rep_floor, l.type_b_seats, l.type_a_seats, l.jurisdiction_id
                  FROM legislature_type_b_groupings g
                  JOIN legislatures l ON l.id = g.legislature_id AND l.deleted_at IS NULL
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
            SELECT COUNT(*)                                                                   AS groupings,
                   COUNT(*) FILTER (WHERE COALESCE(panels.panels, 0) < kids.n AND COALESCE(panels.panels, 0) > 0) AS clumped,
                   COUNT(*) FILTER (WHERE COALESCE(panels.panels, 0) = kids.n)                AS ungrouped,
                   -- One panel per constituent: every panel seats the full rep floor
                   -- (meets the floor), or some constituent is too small to fill
                   -- its panel — the ladder seats a tiny part at its population
                   -- (sub floor). Operator order 2026-09-05: called out separately.
                   COUNT(*) FILTER (WHERE COALESCE(panels.panels, 0) = kids.n AND panels.min_seats >= g.rep_floor) AS ungrouped_meet_floor,
                   COUNT(*) FILTER (WHERE COALESCE(panels.panels, 0) = kids.n AND panels.min_seats <  g.rep_floor) AS ungrouped_sub_floor,
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
        ");

        // ── Type B contiguity: one chamber at a time, chunked. A panel of one
        // is contiguous by definition; a break is FORCED when the panel spans
        // components of the constituent graph (islands), else the spread law
        // paid for it. ──────────────────────────────────────────────────
        $contig = ['contiguous_count' => 0, 'contiguous_pop' => 0, 'forced_count' => 0, 'forced_pop' => 0,
                   'spread_count' => 0, 'spread_pop' => 0, 'chambers' => 0];
        $lastId = '00000000-0000-0000-0000-000000000000';   // keyset start: below every uuid
        while (true) {
            $rows = DB::table('legislature_type_b_groupings as g')
                ->join('legislatures as l', 'l.id', '=', 'g.legislature_id')
                ->where('g.status', 'active')->whereNull('g.deleted_at')
                ->where('g.id', '>', $lastId)
                ->orderBy('g.id')
                ->limit(self::CHUNK)
                ->get(['g.id', 'l.jurisdiction_id']);
            if ($rows->isEmpty()) {
                break;
            }
            foreach ($rows as $r) {
                $lastId = (string) $r->id;
                $this->chamberContiguity((string) $r->id, (string) $r->jurisdiction_id, $contig);
            }
            $contig['chambers'] += $rows->count();
            $beat("Type B contiguity: {$contig['chambers']} chambers");
        }

        $seconds = round(microtime(true) - $t0, 1);

        return [
            'computed_at' => now()->toIso8601String(),
            'seconds'     => $seconds,
            'type_a' => [
                'maps'        => (int) $a->maps,
                'districts'   => (int) $a->districts,
                'seats'       => (int) $a->seats,
                'population'  => (int) $a->pop,
                'legality'    => [
                    'sweeps_done'         => (int) $ledger->sweeps_done,
                    'sweeps_exact'        => (int) $ledger->sweeps_exact,
                    'maps_review'         => (int) $ledger->maps_review,
                    'over_ceiling'        => (int) $a->over_ceiling,
                    'sub_floor_unflagged' => (int) $a->sub_floor_unflagged,
                    'floor_overrides'     => (int) $a->floor_overrides,
                    'bonus_seats'         => (int) $a->bonus_seats,
                    'bonus_maps'          => (int) $a->bonus_maps,
                ],
                'integrity'   => [
                    'intact_count' => (int) $a->intact_count, 'intact_pop' => (int) $a->intact_pop,
                    'segmented_count' => (int) $a->segmented_count, 'segmented_pop' => (int) $a->segmented_pop,
                ],
                'contiguity'  => [
                    'contiguous_count' => (int) $a->contiguous_count, 'contiguous_pop' => (int) $a->contiguous_pop,
                    'non_contiguous_count' => (int) $a->non_contiguous_count, 'non_contiguous_pop' => (int) $a->non_contiguous_pop,
                ],
                'equality'    => [
                    'district_count' => (int) $a->eq_count, 'avg_pct' => round(((float) $a->eq_avg) * 100, 2), 'pop' => (int) $a->eq_pop,
                    'good_count' => (int) $a->eq_good, 'good_pop' => (int) $a->eq_good_pop,
                    'ok_count' => (int) $a->eq_ok, 'ok_pop' => (int) $a->eq_ok_pop,
                    'bad_count' => (int) $a->eq_bad, 'bad_pop' => (int) $a->eq_bad_pop,
                ],
                'compactness' => [
                    'mean' => round((float) $a->chr_mean, 4), 'count' => (int) $a->chr_count, 'pop' => (int) $a->chr_pop,
                    'compact_count' => (int) $a->chr_compact, 'compact_pop' => (int) $a->chr_compact_pop,
                    'moderate_count' => (int) $a->chr_moderate, 'moderate_pop' => (int) $a->chr_moderate_pop,
                    'irregular_count' => (int) $a->chr_irregular, 'irregular_pop' => (int) $a->chr_irregular_pop,
                ],
            ],
            'type_b' => [
                'groupings'    => (int) $b->groupings,
                'clumped'      => (int) $b->clumped,
                'ungrouped'    => (int) $b->ungrouped,
                'ungrouped_meet_floor' => (int) $b->ungrouped_meet_floor,
                'ungrouped_sub_floor'  => (int) $b->ungrouped_sub_floor,
                'zero_panel'   => (int) $b->zero_panel,
                'panels'       => (int) $b->panels,
                'seats'        => (int) $b->seats,
                'constituents' => (int) $b->constituents,
                'legality'     => [
                    'breach' => (int) $b->breach, 'unassigned_chambers' => (int) $b->unassigned_chambers,
                    'unassigned_parts' => (int) $b->unassigned_parts, 'empty_panels' => (int) $b->empty_panels,
                    'identity_mismatch' => (int) $b->identity_mismatch,
                ],
                'contiguity'   => $contig,
                'diversity'    => [
                    'spread0' => (int) $b->spread0, 'spread1' => (int) $b->spread1, 'spread_over' => (int) $b->spread_over,
                ],
            ],
        ];
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
