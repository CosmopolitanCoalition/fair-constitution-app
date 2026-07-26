<?php

namespace App\Services\Districting;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * GRAPH PARTITION PLANNER (2026-07-25, operator-approved via lane 7).
 *
 * The last-resort districting primitive for scopes that no straight cut and no
 * power diagram can divide: ~400 review maps that file ZERO districts today
 * (Abu Dhabi 152 seats, Sharjah 124, Sermersooq 23 — whole emirates and
 * municipalities whose child admin level was never ingested, so one polygon
 * must carry dozens of districts).
 *
 * Recursive straight-line bisection is simply the wrong primitive for a
 * concave coastline: every candidate blade strands a fragment. This planner
 * partitions the POPULATION GRAPH instead of the geometry —
 *
 *     nodes = populated raster pixels
 *     edges = grid adjacency (4-neighbour, 8 when the 4-graph is fragmented)
 *     split = build a spanning tree over a component and cut the ONE edge
 *             whose subtree population sits closest to the target share
 *
 * Removing a single tree edge yields exactly two connected components, so
 * CONTIGUITY HOLDS BY CONSTRUCTION rather than by search-and-reject. Detached
 * components ride WHOLE to one side, largest-first to whichever side is
 * furthest below its target — the same LPT rule and the same
 * islands-ride-whole doctrine the composite plane already follows (Art. II §8
 * forbids splitting a landmass into two chunks, never carrying whole ones).
 *
 * Seats come from the seating law untouched: each drawn district rounds
 * pop/quota to NEAREST inside [floor, ceiling], independently, with no
 * total-forcing — so a scope whose clusters are indivisible records honest
 * drift exactly as the drawn plane always has.
 *
 * NOT a replacement for anything. SubdivisionCellSeedService calls this only
 * after its own weight balancer has refused, so every map that draws today
 * takes its historical path and re-plans byte-identically.
 */
class GraphPartitionPlanner
{
    /** Neighbour radius as a multiple of the inferred grid step. */
    private const ADJ_FACTOR = 1.45;

    /** Sampled points used to infer the grid step (median nearest-neighbour). */
    private const STEP_SAMPLE = 400;

    /**
     * @param  array  $pixels  [[lon, lat, pop], ...] the scope's raster grid
     * @param  int[]  $sizes   seat sizes from the seating law, summing to the budget
     * @return array{districts: array<int, array<string, mixed>>, step: float, components: int}
     */
    public function partition(string $scopeId, array $pixels, array $sizes, float $quota, int $floor, int $ceiling): array
    {
        if (count($pixels) < 2) {
            throw new RuntimeException('No population raster pixels for this scope.');
        }

        // Deterministic order before anything reads the grid.
        usort($pixels, fn (array $a, array $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $step = self::inferStep($pixels);
        [$nodes, $byCell] = self::snapToCells($pixels, $step);
        $n = count($nodes);

        $adj = self::adjacency($nodes, $byCell, false);
        $compCount = count(self::components($adj, range(0, $n - 1)));
        if ($compCount > 1) {
            // A fragmented 4-graph means the grid is sparse (desert, archipelago);
            // the 8-neighbour graph reconnects diagonal runs. Genuinely detached
            // settlements stay separate and ride whole.
            $adj8 = self::adjacency($nodes, $byCell, true);
            if (count(self::components($adj8, range(0, $n - 1))) < $compCount) {
                $adj = $adj8;
                $compCount = count(self::components($adj, range(0, $n - 1)));
            }
        }

        $parts = [];
        $this->recurse(range(0, $n - 1), $sizes, $nodes, $adj, $parts);

        $districts = [];
        foreach ($parts as $i => [$members, $plannedSeats]) {
            $pop = 0.0;
            foreach ($members as $u) {
                $pop += $nodes[$u][2];
            }
            // The seating law: nearest rounding inside the band, independently.
            $seats = max($floor, min($ceiling, (int) round($pop / max($quota, 1e-9))));
            $geom = $this->emitGeometry($scopeId, $members, $nodes, $step);
            if ($geom === null) {
                throw new RuntimeException("Graph part {$i} clips to an empty geometry against this region.");
            }
            $districts[] = [
                'path'                   => sprintf('graph.%02d', $i),
                'seats'                  => $seats,
                'planned_seats'          => $plannedSeats,
                'pop'                    => (int) round($pop),
                'per_seat_deviation_pct' => round(abs($pop / max($seats, 1) - $quota) / max($quota, 1e-9) * 100, 2),
                'convex_hull_ratio'      => round((float) ($geom['chr'] ?? 0.0), 3),
                'geometry_json'          => (string) $geom['gj'],
                'num_parts'              => (int) ($geom['parts'] ?? 1),
                'cut_path'               => null,   // geometry IS the assignment truth
            ];
        }

        usort($districts, fn (array $a, array $b) => strcmp($a['path'], $b['path']));

        return ['districts' => $districts, 'step' => $step, 'components' => $compCount];
    }

    /**
     * Median nearest-neighbour distance. The MINIMUM positive coordinate delta
     * looks like the obvious choice and is wrong: two identical longitudes can
     * differ by 1e-12 through float noise, collapsing the step to zero and
     * making every pixel its own component (measured on the first probe run).
     */
    private static function inferStep(array $pixels): float
    {
        $m = count($pixels);
        $sample = min(self::STEP_SAMPLE, $m);
        $nn = [];
        for ($i = 0; $i < $sample; $i++) {
            $best = INF;
            for ($j = 0; $j < $m; $j++) {
                if ($j === $i) {
                    continue;
                }
                $d = ((float) $pixels[$i][0] - (float) $pixels[$j][0]) ** 2
                   + ((float) $pixels[$i][1] - (float) $pixels[$j][1]) ** 2;
                if ($d < $best) {
                    $best = $d;
                }
            }
            if (is_finite($best) && $best > 0.0) {
                $nn[] = sqrt($best);
            }
        }
        if ($nn === []) {
            throw new RuntimeException('Could not infer a raster grid step for this scope.');
        }
        sort($nn);

        return $nn[intdiv(count($nn), 2)];
    }

    /**
     * @return array{0: array<int, array{0:int,1:int,2:float}>, 1: array<string,int>}
     */
    private static function snapToCells(array $pixels, float $step): array
    {
        $minX = INF;
        $minY = INF;
        foreach ($pixels as $p) {
            $minX = min($minX, (float) $p[0]);
            $minY = min($minY, (float) $p[1]);
        }
        $nodes = [];
        $byCell = [];
        foreach ($pixels as $p) {
            $c = (int) round(((float) $p[0] - $minX) / $step);
            $r = (int) round(((float) $p[1] - $minY) / $step);
            $key = $c.','.$r;
            if (isset($byCell[$key])) {
                $nodes[$byCell[$key]][2] += (float) $p[2];
                continue;
            }
            $byCell[$key] = count($nodes);
            $nodes[] = [$c, $r, (float) $p[2], (float) $p[0], (float) $p[1]];
        }

        return [$nodes, $byCell];
    }

    /** @return array<int, int[]> */
    private static function adjacency(array $nodes, array $byCell, bool $diagonal): array
    {
        $adj = array_fill(0, count($nodes), []);
        $dirs = $diagonal
            ? [[1, 0], [0, 1], [-1, 0], [0, -1], [1, 1], [1, -1], [-1, 1], [-1, -1]]
            : [[1, 0], [0, 1], [-1, 0], [0, -1]];
        foreach ($nodes as $i => [$c, $r]) {
            foreach ($dirs as [$dc, $dr]) {
                $key = ($c + $dc).','.($r + $dr);
                if (isset($byCell[$key])) {
                    $adj[$i][] = $byCell[$key];
                }
            }
        }

        return $adj;
    }

    /**
     * Connected components of the induced subgraph on $members.
     *
     * @return array<int, int[]>
     */
    public static function components(array $adj, array $members): array
    {
        $set = array_flip($members);
        $seen = [];
        $comps = [];
        foreach ($members as $s) {
            if (isset($seen[$s])) {
                continue;
            }
            $stack = [$s];
            $seen[$s] = true;
            $comp = [];
            while ($stack) {
                $u = array_pop($stack);
                $comp[] = $u;
                foreach ($adj[$u] as $v) {
                    if (isset($set[$v]) && ! isset($seen[$v])) {
                        $seen[$v] = true;
                        $stack[] = $v;
                    }
                }
            }
            sort($comp);
            $comps[] = $comp;
        }
        // Largest first, ties by lowest member — deterministic.
        usort($comps, fn (array $a, array $b) => count($b) <=> count($a) ?: $a[0] <=> $b[0]);

        return $comps;
    }

    /**
     * Split $members into two connected groups approaching $targetShare of the
     * population. Returns null when the members cannot be split at all.
     *
     * @return array{0: int[], 1: int[]}|null
     */
    public static function splitOnce(array $members, float $targetShare, array $nodes, array $adj): ?array
    {
        $comps = self::components($adj, $members);
        $main = $comps[0];
        $grand = 0.0;
        foreach ($members as $u) {
            $grand += $nodes[$u][2];
        }
        $wantA = $targetShare * $grand;

        // BFS spanning tree over the largest component.
        $root = $main[0];
        $parent = [$root => -1];
        $order = [$root];
        $queue = [$root];
        $inMain = array_flip($main);
        while ($queue) {
            $u = array_shift($queue);
            foreach ($adj[$u] as $v) {
                if (isset($inMain[$v]) && ! isset($parent[$v])) {
                    $parent[$v] = $u;
                    $order[] = $v;
                    $queue[] = $v;
                }
            }
        }
        // Subtree population sums, leaves first.
        $sub = [];
        foreach ($order as $u) {
            $sub[$u] = $nodes[$u][2];
        }
        for ($i = count($order) - 1; $i >= 1; $i--) {
            $sub[$parent[$order[$i]]] += $sub[$order[$i]];
        }

        $bestU = null;
        $bestErr = INF;
        foreach ($order as $u) {
            if ($u === $root) {
                continue;
            }
            $err = abs($sub[$u] - $wantA);
            if ($err < $bestErr || ($err === $bestErr && $bestU !== null && $u < $bestU)) {
                $bestErr = $err;
                $bestU = $u;
            }
        }
        if ($bestU === null) {
            return null;
        }

        $children = [];
        foreach ($order as $u) {
            if ($u !== $root) {
                $children[$parent[$u]][] = $u;
            }
        }
        $inA = [];
        $stack = [$bestU];
        while ($stack) {
            $u = array_pop($stack);
            $inA[$u] = true;
            foreach ($children[$u] ?? [] as $v) {
                $stack[] = $v;
            }
        }
        $A = array_keys($inA);
        $B = array_values(array_filter($main, fn ($u) => ! isset($inA[$u])));
        $popA = 0.0;
        foreach ($A as $u) {
            $popA += $nodes[$u][2];
        }
        $popB = 0.0;
        foreach ($B as $u) {
            $popB += $nodes[$u][2];
        }

        // Detached components ride WHOLE, largest first, to whichever side is
        // furthest below its target (LPT — the composite plane's own rule).
        $extras = [];
        foreach (array_slice($comps, 1) as $comp) {
            $cp = 0.0;
            foreach ($comp as $u) {
                $cp += $nodes[$u][2];
            }
            $extras[] = [$cp, $comp];
        }
        usort($extras, fn (array $x, array $y) => $y[0] <=> $x[0] ?: $x[1][0] <=> $y[1][0]);
        $wantB = (1.0 - $targetShare) * $grand;
        foreach ($extras as [$cp, $comp]) {
            if (($wantA - $popA) >= ($wantB - $popB)) {
                $A = array_merge($A, $comp);
                $popA += $cp;
            } else {
                $B = array_merge($B, $comp);
                $popB += $cp;
            }
        }
        if ($A === [] || $B === []) {
            return null;
        }
        sort($A);
        sort($B);

        return [$A, $B];
    }

    /** @param array<int, array{0: int[], 1: int}> $parts */
    private function recurse(array $members, array $sizes, array $nodes, array $adj, array &$parts): void
    {
        if (count($sizes) === 1) {
            $parts[] = [$members, (int) $sizes[0]];

            return;
        }
        [$aSizes, $bSizes] = SubdivisionAutoseedService::bisectSizes($sizes);
        $share = array_sum($aSizes) / max(array_sum($aSizes) + array_sum($bSizes), 1);
        $split = self::splitOnce($members, $share, $nodes, $adj);
        if ($split === null) {
            // Indivisible: the whole remainder becomes one district carrying
            // the group's seats (honest, and still contiguous).
            $parts[] = [$members, (int) array_sum($sizes)];

            return;
        }
        [$A, $B] = $split;
        $this->recurse($A, $aSizes, $nodes, $adj, $parts);
        $this->recurse($B, $bSizes, $nodes, $adj, $parts);
    }

    /**
     * District geometry = the union of the part's pixel cells, clipped to the
     * live scope with the proven 1e-8° inward shave (the same armor every
     * other filing path uses).
     *
     * @return array{gj: string, parts: int, chr: float}|null
     */
    private function emitGeometry(string $scopeId, array $members, array $nodes, float $step): ?array
    {
        // RUN-LENGTH ENCODE THE CELLS FIRST (2026-07-26 — this cost a postgres
        // crash loop). Emitting one box per pixel and unioning them is
        // unbounded work: Sharjah alone is 23,501 pixels, and the union took
        // the server down with exit code 2. Adjacent cells in a row collapse
        // into ONE rectangle, so a district of thousands of cells becomes a
        // few hundred boxes — same geometry, bounded cost (THE ETL RULE).
        $byRow = [];
        foreach ($members as $u) {
            $byRow[$nodes[$u][1]][] = $nodes[$u][0];
        }
        ksort($byRow);
        $half = $step / 2.0;
        $x0 = [];
        $y0 = [];
        $x1 = [];
        $y1 = [];
        foreach ($byRow as $r => $cols) {
            sort($cols);
            $runStart = $cols[0];
            $prev = $cols[0];
            $emit = function (int $a, int $b) use (&$x0, &$y0, &$x1, &$y1, $r, $nodes, $members, $half, $step): void {
                // Cell (col,row) centres map back through the stored lon/lat of
                // any member in that cell; reconstruct from the grid origin.
                $x0[] = $a; $x1[] = $b; $y0[] = $r; $y1[] = $r;
            };
            foreach (array_slice($cols, 1) as $c) {
                if ($c === $prev + 1) { $prev = $c; continue; }
                $emit($runStart, $prev);
                $runStart = $c;
                $prev = $c;
            }
            $emit($runStart, $prev);
        }

        // Grid origin: recover the lon/lat of cell (0,0) from any node.
        $anchor = $nodes[$members[0]];
        $originX = $anchor[3] - $anchor[0] * $step;
        $originY = $anchor[4] - $anchor[1] * $step;

        $xs = [];
        $ys = [];
        $xe = [];
        $ye = [];
        foreach ($x0 as $i => $c0) {
            $xs[] = $originX + $c0 * $step - $half;
            $xe[] = $originX + $x1[$i] * $step + $half;
            $ys[] = $originY + $y0[$i] * $step - $half;
            $ye[] = $originY + $y1[$i] * $step + $half;
        }

        // A runaway union must fail THIS district, never the server.
        DB::statement("SET LOCAL statement_timeout = '120s'");

        $row = DB::selectOne(
            'WITH pts AS (
                 SELECT unnest(:xs::float8[]) AS xa, unnest(:xe::float8[]) AS xb,
                        unnest(:ys::float8[]) AS ya, unnest(:ye::float8[]) AS yb
             ),
             boxes AS (
                 SELECT ST_MakeEnvelope(xa, ya, xb, yb, 4326) AS b FROM pts
             ),
             u AS (SELECT ST_UnaryUnion(ST_Collect(b)) AS g FROM boxes),
             gi AS (SELECT ST_MakeValid(geom) AS g FROM jurisdictions WHERE id = :scope),
             leaf AS (
                 SELECT ST_CollectionExtract(ST_MakeValid(ST_Buffer(
                            ST_CollectionExtract(ST_Intersection(
                                (SELECT g FROM u), (SELECT g FROM gi)), 3),
                            -0.00000001)), 3) AS g
             )
             SELECT ST_AsGeoJSON((SELECT g FROM leaf), 15) AS gj,
                    ST_NumGeometries(ST_Multi((SELECT g FROM leaf))) AS parts,
                    ST_Area((SELECT g FROM leaf))
                        / NULLIF(ST_Area(ST_ConvexHull((SELECT g FROM leaf))), 0) AS chr',
            [
                'xs'    => '{'.implode(',', $xs).'}',
                'xe'    => '{'.implode(',', $xe).'}',
                'ys'    => '{'.implode(',', $ys).'}',
                'ye'    => '{'.implode(',', $ye).'}',
                'scope' => $scopeId,
            ]
        );

        if ($row?->gj === null) {
            return null;
        }

        return ['gj' => (string) $row->gj, 'parts' => (int) $row->parts, 'chr' => (float) ($row->chr ?? 0.0)];
    }
}
