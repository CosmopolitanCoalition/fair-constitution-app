<?php

namespace App\Services\Districting;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5 — COMMUNITY CELLS, the second autoseed template (design §3b): a
 * balanced power diagram over the giant's WorldPop pixel grid. Where the
 * splitline recursion carves with straight blades, this seeds one site per
 * district at a population density peak and grows Aurenhammer-weighted cells
 * around the sites until every cell holds its seat-proportional share — so
 * districts form around where people actually cluster instead of where a
 * blade happens to fall.
 *
 * Emits the SAME plan contract as SubdivisionAutoseedService::plan()
 * (districts[] {path, seats, pop, per_seat_deviation_pct, convex_hull_ratio,
 * geometry}), with `cuts: []` and an extra `seeds` list for the UI. Commit is
 * the identical path: recompute → hash_equals → one F-ELB-008 per cell.
 *
 * DETERMINISM is load-bearing here exactly as in the splitline service:
 * pixelGrid carries NO ORDER BY (cache-stable per node, not cross-node), so
 * the pixels are sorted by (lon, lat) FIRST; every later step (seed picking,
 * size→seed matching, weight iteration, tie-breaks) has a total order.
 */
class SubdivisionCellSeedService
{
    /** Hard cap on balance iterations — unconverged plans fail plainly, never spin. */
    public const MAX_ITERATIONS = 120;

    /** Convergence: every cell within 2% of its target (well inside the 5% guard). */
    public const TOLERANCE = 0.02;

    /** Per-seat deviation guard on every final cell (same figure the splitline enforces). */
    private const MAX_PER_SEAT_DEVIATION = 0.05;

    public function __construct(
        private readonly PopulationRaster $raster,
    ) {
    }

    /**
     * The full deterministic community-cells plan for a leaf giant. $ctx is
     * the controller's giantContext (floor/ceiling/budget/quota). Read-only.
     *
     * @throws RuntimeException when no in-band plan exists (with a plain reason)
     */
    public function plan(string $scopeId, array $ctx, int $year = 2023): array
    {
        $pixels = $this->raster->pixelGrid($scopeId, $year);
        if (count($pixels) < 2) {
            throw new RuntimeException('No population raster pixels for this scope — load the WorldPop raster first.');
        }

        // Total-order the grid before ANYTHING reads it (see class docblock).
        usort($pixels, fn (array $a, array $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $S = (int) $ctx['budget'];
        $sizes = SubdivisionAutoseedService::seatGroups($S, (int) $ctx['floor'], (int) $ctx['ceiling']);
        $k = count($sizes);

        [$total, $lon0, $lat0, $cosLat] = SubdivisionAutoseedService::gridFrame($pixels);
        if ($total <= 0.0) {
            throw new RuntimeException('The population raster holds no people inside this scope.');
        }
        $quota = $total / max($S, 1);

        // The scaled local frame (equirectangular, Δlon honest in meters) —
        // all diagram math happens here; only final vertices map back.
        $px = [];
        foreach ($pixels as [$x, $y, $v]) {
            $px[] = [($x - $lon0) * $cosLat, $y - $lat0, $v];
        }
        [$minU, $minV, $maxU, $maxV] = self::bbox($px);
        $bboxArea = max(($maxU - $minU) * ($maxV - $minV), 1e-18);

        // ── Seeds: density peaks, min-separated, deterministically relaxed ──
        $seedIdx = self::pickSeeds($px, $k, 0.5 * sqrt($bboxArea / max($k, 1)));
        $seeds = array_map(fn (int $i) => [$px[$i][0], $px[$i][1]], $seedIdx);

        // ── Seat sizes → seeds: bigger sizes to bigger unweighted catchments ──
        // (initial w = 0 assignment; ties on captured pop break by seed index).
        $pops0 = self::assignPops($px, $seeds, array_fill(0, $k, 0.0));
        $byCatchment = range(0, $k - 1);
        usort($byCatchment, fn (int $a, int $b) => $pops0[$b] <=> $pops0[$a] ?: $a <=> $b);
        $sizesDesc = $sizes;
        rsort($sizesDesc);
        $seedSizes = [];
        foreach ($byCatchment as $rank => $seedI) {
            $seedSizes[$seedI] = $sizesDesc[$rank];
        }
        ksort($seedSizes);
        $targets = array_map(fn (int $sz) => $sz * ($total / $S), $seedSizes);

        // ── Balance the diagram ─────────────────────────────────────────────
        [$weights, $pops] = self::balanceWeights($px, $seeds, $targets);

        // ── Exact cell polygons (radical-axis half-planes), then ONE PostGIS
        // clip+shave per cell against the live giant geometry ────────────────
        $pad = 0.25 * max($maxU - $minU, $maxV - $minV) + 1e-6;
        $frame = [
            [$minU - $pad, $minV - $pad],
            [$maxU + $pad, $minV - $pad],
            [$maxU + $pad, $maxV + $pad],
            [$minU - $pad, $maxV + $pad],
        ];

        $districts = [];
        for ($i = 0; $i < $k; $i++) {
            $poly = $frame;
            for ($j = 0; $j < $k; $j++) {
                if ($j === $i) {
                    continue;
                }
                // |x−s_i|²−w_i ≤ |x−s_j|²−w_j  ⇔  2(s_j−s_i)·x ≤ |s_j|²−|s_i|²+w_i−w_j
                // (the quadratic terms cancel — each pairwise boundary is a line).
                $ax = 2.0 * ($seeds[$j][0] - $seeds[$i][0]);
                $ay = 2.0 * ($seeds[$j][1] - $seeds[$i][1]);
                $b = ($seeds[$j][0] ** 2 + $seeds[$j][1] ** 2)
                   - ($seeds[$i][0] ** 2 + $seeds[$i][1] ** 2)
                   + $weights[$i] - $weights[$j];
                $poly = self::clipHalfPlane($poly, $ax, $ay, $b);
                if (count($poly) < 3) {
                    break;
                }
            }
            if (count($poly) < 3) {
                throw new RuntimeException(
                    "Community cell {$i} collapsed to nothing — the seeds sit too close. Use the 'shortest' template."
                );
            }

            // Map the cell back to lon/lat (the bladeThrough inverse) and clip
            // it to the giant with the proven clip + 1e-8° inward shave.
            $ring = [];
            foreach ($poly as [$u, $v]) {
                $ring[] = [$lon0 + $u / $cosLat, $lat0 + $v];
            }
            $ring[] = $ring[0];
            $cellGj = json_encode(['type' => 'Polygon', 'coordinates' => [$ring]]);

            $row = DB::selectOne(
                'WITH gi AS (SELECT ST_MakeValid(geom) AS g FROM jurisdictions WHERE id = :scope),
                      leaf AS (
                          SELECT ST_CollectionExtract(ST_MakeValid(ST_Buffer(
                                     ST_CollectionExtract(ST_Intersection(
                                         ST_MakeValid(ST_GeomFromGeoJSON(:gj)), (SELECT g FROM gi)), 3),
                                     -0.00000001)), 3) AS g
                      )
                 SELECT ST_AsGeoJSON((SELECT g FROM leaf), 15) AS gj,
                        ST_NumGeometries(ST_Multi((SELECT g FROM leaf))) AS parts,
                        ST_Area((SELECT g FROM leaf))
                            / NULLIF(ST_Area(ST_ConvexHull((SELECT g FROM leaf))), 0) AS chr',
                ['scope' => $scopeId, 'gj' => $cellGj]
            );
            $geometry = $row?->gj !== null ? json_decode($row->gj, true) : null;
            if ($geometry === null) {
                throw new RuntimeException(
                    "Community cell {$i} clips to an empty geometry against this region — use the 'shortest' template."
                );
            }
            if ((int) $row->parts !== 1) {
                throw new RuntimeException(
                    "Community cell {$i} clips to {$row->parts} disjoint parts against this region's boundary "
                    ."(non-convex giant) — use the 'shortest' template or cut it by hand."
                );
            }

            $pop = $pops[$i];
            $seats = (int) $seedSizes[$i];
            $deviation = abs($pop / $seats - $quota) / $quota;
            if ($deviation > self::MAX_PER_SEAT_DEVIATION) {
                throw new RuntimeException(
                    sprintf(
                        'Community cell %d lands at %.1f%% per-seat deviation (guard 5%%) — '
                        ."use the 'shortest' template.",
                        $i, $deviation * 100
                    )
                );
            }
            // The F-ELB-008 handler recomputes seats = round(pop/quota) at
            // commit — a rounding mismatch must fail HERE, never at filing.
            if ((int) round($pop / $quota) !== $seats) {
                throw new RuntimeException(
                    "Community cell {$i} rounds to a different seat count than its planned {$seats} — "
                    ."use the 'shortest' template."
                );
            }

            $districts[] = [
                'path'                   => sprintf('cell.%02d', $i),
                'seats'                  => $seats,
                'pop'                    => (int) round($pop),
                'per_seat_deviation_pct' => round($deviation * 100, 2),
                'convex_hull_ratio'      => round((float) ($row->chr ?? 0.0), 3),
                'geometry'               => $geometry,
            ];
        }

        usort($districts, fn (array $a, array $b) => strcmp($a['path'], $b['path']));

        $seedsLngLat = array_map(fn (array $s) => [
            'lng' => round($lon0 + $s[0] / $cosLat, 7),
            'lat' => round($lat0 + $s[1], 7),
        ], $seeds);

        return [
            'scope_id'        => $scopeId,
            'population_year' => $year,
            'seat_budget'     => $S,
            'sizes'           => $sizes,
            'quota'           => round($quota, 1),
            'template'        => SubdivisionAutoseedService::TEMPLATE_COMMUNITY_CELLS,
            'cuts'            => [],
            'seeds'           => $seedsLngLat,
            'districts'       => $districts,
            'plan_hash'       => self::planHash($scopeId, $year, $sizes, $seedsLngLat, $weights),
        ];
    }

    // ── deterministic diagram math (pure, no DB — pinned by unit tests) ─────

    /**
     * Greedy density-peak seed picking: pixels ordered (val desc, u asc,
     * v asc), take each at least $minSep from every chosen seed; when fewer
     * than $k qualify, halve $minSep and restart (deterministic relaxation).
     *
     * @param  array  $px  [[u, v, val], ...] in the scaled frame
     * @return int[] $k pixel indexes
     */
    public static function pickSeeds(array $px, int $k, float $minSep): array
    {
        if (count($px) < $k) {
            throw new RuntimeException('Fewer populated pixels than districts — cut this region by hand.');
        }

        $order = array_keys($px);
        usort($order, fn (int $a, int $b) => $px[$b][2] <=> $px[$a][2]
            ?: $px[$a][0] <=> $px[$b][0]
            ?: $px[$a][1] <=> $px[$b][1]);

        while (true) {
            $sep2 = $minSep * $minSep;
            $seeds = [];
            foreach ($order as $i) {
                $ok = true;
                foreach ($seeds as $s) {
                    $du = $px[$i][0] - $px[$s][0];
                    $dv = $px[$i][1] - $px[$s][1];
                    if ($du * $du + $dv * $dv < $sep2) {
                        $ok = false;
                        break;
                    }
                }
                if ($ok) {
                    $seeds[] = $i;
                    if (count($seeds) === $k) {
                        return $seeds;
                    }
                }
            }
            // Distinct pixels always separate at SOME scale, so this terminates.
            $minSep /= 2;
        }
    }

    /**
     * Aurenhammer-style weight balancing: assign each pixel to
     * argmin(d² − w_i) (tie → lower seed index), then push every weight
     * toward its population target on a fixed decaying step schedule.
     * Converges when every |pop_i − target_i| ≤ TOLERANCE·target_i; a plan
     * that cannot balance inside MAX_ITERATIONS fails plainly.
     *
     * @param  array  $px  [[u, v, val], ...] scaled frame
     * @param  array  $seeds  [[u, v], ...]
     * @param  float[]  $targets  per-seed target populations
     * @return array{0: float[], 1: float[]} [weights, final per-seed pops]
     */
    public static function balanceWeights(array $px, array $seeds, array $targets): array
    {
        $k = count($seeds);
        $total = 0.0;
        foreach ($px as $p) {
            $total += $p[2];
        }
        [$minU, $minV, $maxU, $maxV] = self::bbox($px);
        // The step scale: an average cell's squared extent. Weights live in
        // distance² units, so this keeps one full-deficit step ~one cell wide.
        $spread2 = max(($maxU - $minU) * ($maxV - $minV), 1e-18) / max($k, 1);

        $weights = array_fill(0, $k, 0.0);
        for ($t = 0; $t < self::MAX_ITERATIONS; $t++) {
            $pops = self::assignPops($px, $seeds, $weights);

            $converged = true;
            for ($i = 0; $i < $k; $i++) {
                if (abs($pops[$i] - $targets[$i]) > self::TOLERANCE * $targets[$i]) {
                    $converged = false;
                    break;
                }
            }
            if ($converged) {
                return [$weights, $pops];
            }

            // Fixed schedule (data-independent, hence cross-node identical):
            // big early steps to cross the region, decaying to fine settling.
            $eta = 1.0 / (1.0 + $t / 10.0);
            for ($i = 0; $i < $k; $i++) {
                $weights[$i] += $eta * (($targets[$i] - $pops[$i]) / $total) * $spread2;
            }
        }

        throw new RuntimeException(
            'The community-cells balance did not converge for this region — '
            ."use the 'shortest' template."
        );
    }

    /**
     * Sutherland–Hodgman against ONE half-plane {x : ax·x + ay·y ≤ b}.
     * $poly is an open ring [[x, y], ...]; returns the clipped open ring
     * (possibly empty).
     */
    public static function clipHalfPlane(array $poly, float $ax, float $ay, float $b): array
    {
        $n = count($poly);
        if ($n === 0) {
            return [];
        }

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $p = $poly[$i];
            $q = $poly[($i + 1) % $n];
            $dp = $ax * $p[0] + $ay * $p[1] - $b;
            $dq = $ax * $q[0] + $ay * $q[1] - $b;

            if ($dp <= 0.0) {
                $out[] = $p;
                if ($dq > 0.0) {
                    $out[] = self::edgeCross($p, $q, $dp, $dq);
                }
            } elseif ($dq <= 0.0) {
                $out[] = self::edgeCross($p, $q, $dp, $dq);
            }
        }

        return $out;
    }

    /** @return array{float, float} the p→q segment's crossing of the clip line */
    private static function edgeCross(array $p, array $q, float $dp, float $dq): array
    {
        $t = $dp / ($dp - $dq);

        return [$p[0] + $t * ($q[0] - $p[0]), $p[1] + $t * ($q[1] - $p[1])];
    }

    /**
     * Per-seed population sums under the power-diagram assignment
     * argmin(d² − w_i); ties go to the lower seed index (strict < scan).
     *
     * @return float[] per-seed populations
     */
    private static function assignPops(array $px, array $seeds, array $weights): array
    {
        $k = count($seeds);
        $pops = array_fill(0, $k, 0.0);

        foreach ($px as [$u, $v, $val]) {
            $best = 0;
            $bestScore = INF;
            for ($i = 0; $i < $k; $i++) {
                $du = $u - $seeds[$i][0];
                $dv = $v - $seeds[$i][1];
                $score = $du * $du + $dv * $dv - $weights[$i];
                if ($score < $bestScore) {
                    $bestScore = $score;
                    $best = $i;
                }
            }
            $pops[$best] += $val;
        }

        return $pops;
    }

    /** @return array{float, float, float, float} [minU, minV, maxU, maxV] */
    private static function bbox(array $px): array
    {
        $minU = INF;
        $minV = INF;
        $maxU = -INF;
        $maxV = -INF;
        foreach ($px as [$u, $v]) {
            $minU = min($minU, $u);
            $minV = min($minV, $v);
            $maxU = max($maxU, $u);
            $maxV = max($maxV, $v);
        }

        return [$minU, $minV, $maxU, $maxV];
    }

    /**
     * The determinism receipt for a cells plan: seeds (7 dp ≈ 1 cm) and the
     * balanced weights (6 dp) pin the exact diagram; commit recomputes and
     * compares, exactly like the splitline hash.
     *
     * @param  array  $seedsLngLat  [{lng, lat}, ...] already rounded to 7 dp
     * @param  float[]  $weights
     */
    private static function planHash(string $scopeId, int $year, array $sizes, array $seedsLngLat, array $weights): string
    {
        return hash('sha256', json_encode([
            'scope_id'        => $scopeId,
            'population_year' => $year,
            'sizes'           => array_values($sizes),
            'template'        => SubdivisionAutoseedService::TEMPLATE_COMMUNITY_CELLS,
            'seeds'           => array_map(fn (array $s) => [$s['lng'], $s['lat']], $seedsLngLat),
            'weights'         => array_map(fn (float $w) => round($w, 6), $weights),
        ]));
    }
}
