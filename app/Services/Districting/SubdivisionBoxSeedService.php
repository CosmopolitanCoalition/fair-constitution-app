<?php

namespace App\Services\Districting;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * THE BOX TEMPLATE (operator method, 2026-09-02: "pretend the real border
 * is this square and cut that; then cut once on the borders and boom you
 * have a map").
 *
 * The scope's own population pixels are the only mass. The seat vector
 * comes from the head alone (seatGroups: 18 -> [9, 9]). The recursion
 * splits the scope's ENVELOPE with axis-aligned cuts placed by cumulative
 * pixel sums — pure arithmetic, no geometry per cut, lossless (every
 * pixel lands on exactly one side). Each leaf rectangle is clipped to the
 * real border ONCE (one intersection against the stored polygon, no
 * dissolve, no per-piece union) and files with its parts recorded.
 * Contiguity is a hope, not a gate (operator ruling 2026-09-02): a box
 * piece on a ring or an archipelago may be several chunks, exactly as
 * composite districts already are (Los Angeles County, USA map).
 * Memory: the pixel list plus one rectangle per piece.
 */
final class SubdivisionBoxSeedService
{
    public function __construct(private readonly PopulationRaster $raster)
    {
    }

    /** @return array the plan, same shape every template returns */
    public function plan(string $scopeId, array $ctx, int $year): array
    {
        $pixels = $this->raster->gridWithFallback($scopeId, $year);
        if (count($pixels) < 2) {
            throw new RuntimeException('No population raster pixels for this scope — load the WorldPop raster first.');
        }
        $S = (int) $ctx['budget'];
        $floor = (int) $ctx['floor'];
        $ceiling = (int) $ctx['ceiling'];
        $sizes = SubdivisionAutoseedService::seatGroups($S, $floor, $ceiling);

        $total = 0.0;
        foreach ($pixels as $p) {
            $total += $p[2];
        }
        if ($total <= 0.0) {
            throw new RuntimeException('The population raster holds no people inside this scope.');
        }
        $quota = $total / max($S, 1);

        $env = DB::selectOne(
            'SELECT ST_XMin(e) AS x0, ST_YMin(e) AS y0, ST_XMax(e) AS x1, ST_YMax(e) AS y1
               FROM (SELECT ST_Envelope(geom) AS e FROM jurisdictions WHERE id = ?) t',
            [$scopeId]
        );
        if ($env === null) {
            throw new RuntimeException('The scope has no geometry.');
        }
        $pad = 0.001;
        $bounds = [(float) $env->x0 - $pad, (float) $env->y0 - $pad, (float) $env->x1 + $pad, (float) $env->y1 + $pad];

        $leaves = [];
        $this->split($pixels, $sizes, $bounds, 'box', $leaves);

        $districts = [];
        foreach ($leaves as $i => $leaf) {
            [$x0, $y0, $x1, $y1] = $leaf['bounds'];
            $row = DB::selectOne(
                'WITH piece AS (
                     SELECT ST_CollectionExtract(ST_Intersection(ST_MakeValid(j.geom),
                                ST_MakeEnvelope(:x0, :y0, :x1, :y1, 4326)), 3) AS g
                       FROM jurisdictions j WHERE j.id = :scope
                 ),
                 shaved AS (
                     SELECT ST_CollectionExtract(ST_Collect(sg), 3) AS g
                       FROM (SELECT ST_CollectionExtract(ST_MakeValid(ST_Buffer(d.geom, -0.00000001)), 3) AS sg
                               FROM piece, LATERAL ST_Dump(piece.g) d) s
                      WHERE NOT ST_IsEmpty(sg)
                 )
                 SELECT ST_AsGeoJSON(g, 15) AS gj,
                        ST_Area(g) / NULLIF(ST_Area(ST_ConvexHull(g)), 0) AS chr,
                        ST_NumGeometries(g) AS parts
                   FROM shaved',
                ['x0' => $x0, 'y0' => $y0, 'x1' => $x1, 'y1' => $y1, 'scope' => $scopeId]
            );
            if ($row === null || $row->gj === null) {
                throw new RuntimeException("Box piece {$i} holds population but clips to no land — the raster and the border disagree here; cut it by hand.");
            }
            $seats = (int) $leaf['seats'];
            $districts[] = [
                'path'                   => sprintf('box.%02d', $i),
                'seats'                  => $seats,
                'pop'                    => (int) round($leaf['pop']),
                'per_seat_deviation_pct' => round(abs($leaf['pop'] / $seats - $quota) / $quota * 100, 2),
                'convex_hull_ratio'      => round((float) ($row->chr ?? 0.0), 3),
                'geometry_json'          => (string) $row->gj,
                'num_parts'              => (int) ($row->parts ?? 1),
                'cut_path'               => null,
                'island_pop'             => 0,
            ];
        }

        return [
            'scope_id'        => $scopeId,
            'population_year' => $year,
            'seat_budget'     => $S,
            'sizes'           => $sizes,
            'total_pop'       => (int) round($total),
            'quota'           => round($quota, 1),
            'template'        => SubdivisionAutoseedService::TEMPLATE_BOX,
            'cuts'            => [],
            'districts'       => $districts,
            'plan_hash'       => hash('sha256', json_encode([$scopeId, $year, $sizes, array_map(fn ($l) => $l['bounds'], $leaves), 'box'])),
        ];
    }

    /**
     * Recursive envelope split by cumulative pixel sums. Every pixel lands
     * on exactly one side; the cut coordinate is the midpoint between the
     * two pixels where the running sum crosses the target share. Both axes
     * are tried; the one whose share lands closest to the seat ratio wins.
     */
    private function split(array $pixels, array $sizes, array $bounds, string $path, array &$leaves): void
    {
        if (count($sizes) === 1) {
            $pop = 0.0;
            foreach ($pixels as $p) {
                $pop += $p[2];
            }
            $leaves[] = ['seats' => (int) $sizes[0], 'pop' => $pop, 'bounds' => $bounds, 'path' => $path];

            return;
        }
        [$a, $b] = self::balancedGroups($sizes);
        $seatsA = array_sum($a);
        $seatsB = array_sum($b);
        $target = $seatsA / ($seatsA + $seatsB);

        $best = null;
        foreach ([0, 1] as $axis) {
            $sorted = $pixels;
            usort($sorted, fn (array $p, array $q) => $p[$axis] <=> $q[$axis] ?: $p[1 - $axis] <=> $q[1 - $axis]);
            $total = 0.0;
            foreach ($sorted as $p) {
                $total += $p[2];
            }
            $run = 0.0;
            $cutIdx = null;
            foreach ($sorted as $i => $p) {
                $next = $run + $p[2];
                if ($next >= $target * $total) {
                    $withIt = abs($next / $total - $target);
                    $without = abs($run / $total - $target);
                    $cutIdx = $withIt <= $without ? $i : $i - 1;
                    break;
                }
                $run = $next;
            }
            if ($cutIdx === null || $cutIdx < 0 || $cutIdx >= count($sorted) - 1) {
                continue;   // one side would be empty on this axis
            }
            $popA = 0.0;
            for ($i = 0; $i <= $cutIdx; $i++) {
                $popA += $sorted[$i][2];
            }
            $dev = abs($popA / $total - $target);
            $c = ($sorted[$cutIdx][$axis] + $sorted[$cutIdx + 1][$axis]) / 2.0;
            if ($best === null || $dev < $best['dev']) {
                $best = ['axis' => $axis, 'c' => $c, 'dev' => $dev, 'sorted' => $sorted, 'cutIdx' => $cutIdx];
            }
        }
        if ($best === null) {
            throw new RuntimeException(
                'The population is too concentrated for this seat budget — a single raster cell holds more than one district; cut it by hand.'
            );
        }
        $axis = $best['axis'];
        $c = $best['c'];
        $left = array_slice($best['sorted'], 0, $best['cutIdx'] + 1);
        $right = array_slice($best['sorted'], $best['cutIdx'] + 1);
        $bl = $bounds;
        $br = $bounds;
        if ($axis === 0) {
            $bl[2] = $c;
            $br[0] = $c;
        } else {
            $bl[3] = $c;
            $br[1] = $c;
        }
        $this->split($left, $a, $bl, $path.'.a', $leaves);
        $this->split($right, $b, $br, $path.'.b', $leaves);
    }

    /** Split a size list into two groups with seat sums as even as possible. */
    private static function balancedGroups(array $sizes): array
    {
        rsort($sizes);
        $a = [];
        $b = [];
        $sa = 0;
        $sb = 0;
        foreach ($sizes as $s) {
            if ($sa <= $sb) {
                $a[] = $s;
                $sa += $s;
            } else {
                $b[] = $s;
                $sb += $s;
            }
        }

        return [$a, $b];
    }
}
