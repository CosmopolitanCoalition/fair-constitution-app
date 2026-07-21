<?php

namespace App\Services\Districting;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phase 5 (5a) — the shortest-splitline AUTOSEED for a childless leaf giant.
 *
 * One call proposes a COMPLETE in-band cut plan for a giant's whole seat
 * budget: a recursive population bisection where every cut is the SHORTEST
 * balanced straight blade (the classic splitline criterion — short cuts hug
 * natural compactness, never snake). The plan is a PREVIEW — committing it
 * files one F-ELB-008 per leaf district through the engine, exactly like a
 * hand-drawn piece; nothing here touches the PROTECTED DistrictingService.
 *
 * DETERMINISM is load-bearing (design §3b): fixed angle sets, exact
 * prefix-sum offset placement (no iterative search), and total tie-break
 * orders mean the same scope + the same rasters reproduce the identical plan
 * on any mesh node. `plan_hash` is that receipt — commit recomputes the plan
 * server-side and refuses when the hash no longer matches the preview.
 *
 * All population arithmetic runs over PopulationRaster::pixelGrid (cached
 * WorldPop centroids — aggregate-only, never individual records); PostGIS is
 * consulted only for geometry (blade clipping, ST_Split, hull ratios).
 */
class SubdivisionAutoseedService
{
    /**
     * The districting TEMPLATES (Phase 5 template wave). All four emit the
     * same plan contract and commit through the identical recompute→hash→
     * F-ELB-008 path; the template is part of the hashed plan identity.
     *  - shortest           : the full shortest-splitline angle sweep (default)
     *  - vertical_strips    : one fixed north–south blade per cut (θ = 90°)
     *  - horizontal_strips  : one fixed east–west blade per cut (θ = 0°)
     *  - community_cells    : the balanced power diagram (SubdivisionCellSeedService)
     *  - components         : whole detached parts as districts — NO cutting
     *                         (run-6 watch fix 2026-07-19; the LA-islands
     *                         doctrine taken to its limit for scopes whose
     *                         every straight cut strands a fragment). Rides
     *                         LAST in the registry so the ladder only reaches
     *                         it when every cutting template has refused.
     */
    public const TEMPLATE_SHORTEST = 'shortest';

    public const TEMPLATE_VERTICAL_STRIPS = 'vertical_strips';

    public const TEMPLATE_HORIZONTAL_STRIPS = 'horizontal_strips';

    public const TEMPLATE_COMMUNITY_CELLS = 'community_cells';

    public const TEMPLATE_COMPONENTS = 'components';

    public const TEMPLATES = [
        self::TEMPLATE_SHORTEST,
        self::TEMPLATE_VERTICAL_STRIPS,
        self::TEMPLATE_HORIZONTAL_STRIPS,
        self::TEMPLATE_COMMUNITY_CELLS,
        self::TEMPLATE_COMPONENTS,
    ];

    /** Blade over-extension in degrees — giants/castelli are << 1°, so this always fully crosses. */
    private const EXTENSION_DEG = 2.0;

    /** Candidate blade angle counts over 180°: the coarse pass, then the fine retry. */
    private const ANGLE_PASSES = [24, 48];

    /** Per-seat deviation guard on each accepted cut side (the search makes it ~exact). */
    private const MAX_PER_SEAT_DEVIATION = 0.05;

    public function __construct(
        private readonly PopulationRaster $raster,
        private readonly SubdivisionCellSeedService $cells,
    ) {
    }

    /**
     * Compute the full deterministic cut plan for a leaf giant. $ctx is the
     * controller's giantContext (floor/ceiling/budget/quota). Read-only.
     *
     * The strip templates ride the SAME recursion as shortest: parallel
     * balanced cuts commute, so the exact prefix-sum placement needs only the
     * one fixed angle per pass. Contiguity validation still applies and can
     * legitimately fail on a non-convex giant — the error then points back at
     * 'shortest'.
     *
     * @throws RuntimeException when no in-band plan exists (with a plain reason)
     */
    public function plan(string $scopeId, array $ctx, int $year = 2023, string $template = self::TEMPLATE_SHORTEST): array
    {
        if (! in_array($template, self::TEMPLATES, true)) {
            throw new RuntimeException("Unknown districting template '{$template}'.");
        }
        if ($template === self::TEMPLATE_COMMUNITY_CELLS) {
            return $this->cells->plan($scopeId, $ctx, $year);
        }
        if ($template === self::TEMPLATE_COMPONENTS) {
            return $this->componentsPlan($scopeId, $ctx, $year);
        }

        // Cycle-2 (2026-07-19): zero-raster-coverage scopes (a geometry
        // outside its iso's tiles) fall back to the area-proportional grid —
        // same shape, deterministic from geometry + stored population. Only
        // a scope with no geometry or no population still refuses.
        $pixels = $this->raster->gridWithFallback($scopeId, $year);
        if (count($pixels) < 2) {
            throw new RuntimeException('No population raster pixels for this scope — load the WorldPop raster first.');
        }

        $S = (int) $ctx['budget'];
        $sizes = self::seatGroups($S, (int) $ctx['floor'], (int) $ctx['ceiling']);

        $region = DB::selectOne(
            'SELECT ST_AsGeoJSON(ST_MakeValid(geom), 15) AS gj FROM jurisdictions WHERE id = ?',
            [$scopeId]
        );
        if ($region === null || $region->gj === null) {
            throw new RuntimeException('The scope has no geometry.');
        }

        // NON-CONTIGUOUS GIANTS (2026-07-17 — the LA-County islands fix): a
        // giant with detached parts (LA = mainland + Santa Catalina + San
        // Clemente) can never satisfy "each blade side is a single polygon" —
        // every straight cut leaves the islands stranded, so all candidates
        // were refused. Doctrine now matches the composite side: ISLANDS RIDE
        // WHOLE with the blade side their position dictates; only
        // blade-created fragments of a single landmass are refused. The blade
        // search runs on the MOST POPULOUS part (the mainland — by people,
        // not area: only a mainland holding the divisible mass can balance);
        // each island joins the search as ONE super-pixel at its
        // representative point carrying its whole population, so balance
        // stays exact and deterministic.
        // TRUE landmasses (2026-07-20, the Penamaluru class): UnaryUnion
        // dissolves loosely-touching rings and overlapping duplicate slivers
        // BEFORE the island partition — otherwise two stored halves of one
        // landmass can ride opposite blade sides, cutting the landmass by
        // assignment (the census refuses exactly that at filing).
        $comps = DB::select(
            'SELECT ST_AsGeoJSON(g, 15) AS gj,
                    ST_Area(g::geography) AS area,
                    ST_X(ST_PointOnSurface(g)) AS cx,
                    ST_Y(ST_PointOnSurface(g)) AS cy
               FROM (SELECT (ST_Dump(ST_UnaryUnion(ST_MakeValid(geom)))).geom AS g
                       FROM jurisdictions WHERE id = ?) t
              WHERE 2 * ST_Area(g::geography) / NULLIF(ST_Perimeter(g::geography), 0) >= 0.5
              ORDER BY ST_Area(g::geography) DESC, ST_X(ST_PointOnSurface(g)), ST_Y(ST_PointOnSurface(g))',
            [$scopeId]
        );

        $mainlandGj = $region->gj;
        $islands = [];
        if (count($comps) > 1) {
            // Partition the grid across ALL parts first (boundary-ambiguous
            // cells stay with the largest-area part, as ever) …
            $rest = $pixels;
            $partPixels = [];
            $partPops = [0 => 0.0];
            foreach (array_slice($comps, 1) as $i => $comp) {
                $poly = json_decode((string) $comp->gj, true);
                [$inside, $rest] = self::partitionPixelsByPolygon($rest, $poly);
                $pop = 0.0;
                foreach ($inside as $p) {
                    $pop += $p[2];
                }
                $partPixels[$i + 1] = $inside;
                $partPops[$i + 1] = $pop;
            }
            $partPixels[0] = $rest;
            foreach ($rest as $p) {
                $partPops[0] += $p[2];
            }

            // … then the blade MAINLAND is the part holding the MOST PEOPLE,
            // not the most area (run-6 watch fix 2026-07-19, the Chiboo Gaon
            // class: a village whose population lives on the smaller-area
            // part gave the blade search a near-empty mainland — no balanced
            // cut can exist there). Only the mainland is ever cut; every
            // other part rides whole as an island. Ties keep the
            // largest-area part (index order is area DESC — deterministic).
            $mainIdx = 0;
            foreach ($partPops as $i => $pop) {
                if ($pop > $partPops[$mainIdx]) {
                    $mainIdx = $i;
                }
            }

            $mainlandGj = (string) $comps[$mainIdx]->gj;
            foreach ($comps as $i => $comp) {
                if ($i === $mainIdx) {
                    continue;
                }
                $islands[] = [
                    'gj'     => (string) $comp->gj,
                    'cx'     => (float) $comp->cx,
                    'cy'     => (float) $comp->cy,
                    'pop'    => $partPops[$i],
                    'pixels' => $partPixels[$i],
                ];
            }
            $pixels = $partPixels[$mainIdx];
        }

        // The plan's quota is PIXEL-derived so the deviation figures measure the
        // search's own balance (the stored jurisdiction population can drift a
        // little from the raster sum via correction passes).
        $total = 0.0;
        foreach ($pixels as $p) {
            $total += $p[2];
        }
        foreach ($islands as $isl) {
            $total += $isl['pop'];
        }
        $quota = $total / max($S, 1);

        $cuts = [];
        $districts = [];
        $order = 0;
        $this->subdivide($scopeId, 'root', $mainlandGj, $pixels, $islands, $sizes, $quota, $cuts, $districts, $order, $template, (int) $ctx['floor'], (int) $ctx['ceiling']);

        usort($districts, fn (array $a, array $b) => strcmp($a['path'], $b['path']));

        return [
            'scope_id'        => $scopeId,
            'population_year' => $year,
            'seat_budget'     => $S,
            'sizes'           => $sizes,
            'total_pop'       => (int) round($total),
            'quota'           => round($quota, 1),
            'template'        => $template,
            'cuts'            => $cuts,
            'districts'       => $districts,
            'plan_hash'       => self::planHash($scopeId, $year, $sizes, $cuts, $template),
        ];
    }

    /**
     * The COMPONENTS template (run-6 watch fix 2026-07-19): a multipart scope
     * whose every straight cut strands a fragment (two detached villages, a
     * population-heavy "island") is districted WITHOUT cutting — the detached
     * parts themselves become the districts, grouped LPT-greedy into
     * k = ceil(S/ceiling) population-balanced districts when there are more
     * parts than districts. Seats follow the drawn-district law exactly as
     * the F-ELB-008 handler will re-derive them: nearest-round of measured
     * population over the quota, sub-floor filed under the autoseed floor
     * posture, seats < 1 or > ceiling refused. Σ-seat drift is the
     * indivisible-atom case (no exact drawing exists once every cutting
     * template refused) and ships honestly — never total-forced.
     *
     * Deterministic: components ordered by area DESC then point-on-surface,
     * pixel partition by the same ray-cast the islands doctrine uses
     * (boundary-ambiguous cells stay with the largest part), LPT ties broken
     * by component index.
     *
     * @throws RuntimeException single landmass / too few parts / a group out of band
     */
    private function componentsPlan(string $scopeId, array $ctx, int $year): array
    {
        $S = (int) $ctx['budget'];
        $ceiling = (int) $ctx['ceiling'];
        $k = intdiv($S + $ceiling - 1, $ceiling);

        // TRUE landmasses — same dissolve as the splitline island partition
        // and the filing census (one bookkeeping everywhere).
        $comps = DB::select(
            'SELECT ST_AsGeoJSON(g, 15) AS gj,
                    ST_Area(g::geography) AS area,
                    ST_X(ST_PointOnSurface(g)) AS cx,
                    ST_Y(ST_PointOnSurface(g)) AS cy
               FROM (SELECT (ST_Dump(ST_UnaryUnion(ST_MakeValid(geom)))).geom AS g
                       FROM jurisdictions WHERE id = ?) t
              WHERE 2 * ST_Area(g::geography) / NULLIF(ST_Perimeter(g::geography), 0) >= 0.5
              ORDER BY ST_Area(g::geography) DESC, ST_X(ST_PointOnSurface(g)), ST_Y(ST_PointOnSurface(g))',
            [$scopeId]
        );
        if (count($comps) < 2) {
            throw new RuntimeException('This scope is a single landmass — the components template needs detached parts.');
        }
        if (count($comps) < $k) {
            throw new RuntimeException(
                count($comps)." detached parts cannot fill {$k} whole-component districts — a cut is required."
            );
        }

        $pixels = $this->raster->gridWithFallback($scopeId, $year);
        if (count($pixels) < 2) {
            throw new RuntimeException('No population raster pixels for this scope — load the WorldPop raster first.');
        }

        // Population per part: pull each smaller part's pixels out of the
        // grid; the remainder (boundary-ambiguous cells included) stays with
        // the largest part — the exact posture of the islands partition.
        $partPops = [0 => 0.0];
        $rest = $pixels;
        foreach (array_slice($comps, 1) as $i => $comp) {
            $poly = json_decode((string) $comp->gj, true);
            [$inside, $rest] = self::partitionPixelsByPolygon($rest, $poly);
            $pop = 0.0;
            foreach ($inside as $p) {
                $pop += $p[2];
            }
            $partPops[$i + 1] = $pop;
        }
        foreach ($rest as $p) {
            $partPops[0] += $p[2];
        }
        $total = array_sum($partPops);
        if ($total <= 0.0) {
            throw new RuntimeException('No population found across the detached parts.');
        }

        // MEASUREMENT PARITY (run-6 watch fix 2026-07-19, the Maniari
        // sliver): seats gate on the SAME oracle F-ELB-008 will re-derive
        // from — measureWithFallback over each clipped district against the
        // stored-population quota the handler divides by. The ray-cast part
        // populations above remain the GROUPING heuristic only; a
        // boundary-dominated sliver the handler would measure to 0 seats now
        // refuses here at plan stage instead of dying at filing.
        $quota = (float) ($ctx['quota'] ?? 0) > 0 ? (float) $ctx['quota'] : $total / max($S, 1);

        // LPT-greedy into k districts: heaviest part first, always onto the
        // lightest district so far; every tie breaks by index. With
        // parts == k this degenerates to one part per district.
        $byWeight = array_keys($partPops);
        usort($byWeight, fn (int $a, int $b) => $partPops[$b] <=> $partPops[$a] ?: $a <=> $b);
        $groups = array_fill(0, $k, ['pop' => 0.0, 'members' => []]);
        foreach ($byWeight as $ci) {
            $g = 0;
            for ($j = 1; $j < $k; $j++) {
                if ($groups[$j]['pop'] < $groups[$g]['pop']) {
                    $g = $j;
                }
            }
            $groups[$g]['pop'] += $partPops[$ci];
            $groups[$g]['members'][] = $ci;
        }
        usort($groups, fn (array $a, array $b) => min($a['members']) <=> min($b['members']));

        $sizes = [];
        $districts = [];
        foreach ($groups as $n => $group) {
            sort($group['members']);
            $groupGj = count($group['members']) === 1
                ? (string) $comps[$group['members'][0]]->gj
                : json_encode([
                    'type'       => 'GeometryCollection',
                    'geometries' => array_map(
                        fn (int $ci) => json_decode((string) $comps[$ci]->gj, true),
                        $group['members'],
                    ),
                ]);

            // The same clip-and-shave the splitline leaves get: proof-safe
            // against the handler's exact ST_CoveredBy after the GeoJSON
            // round-trip.
            $row = DB::selectOne(
                'WITH gi AS (SELECT ST_MakeValid(geom) AS g FROM jurisdictions WHERE id = :scope),
                      ix AS (
                          SELECT ST_CollectionExtract(ST_Intersection(
                                     ST_CollectionExtract(ST_MakeValid(ST_GeomFromGeoJSON(:gj)), 3),
                                     (SELECT g FROM gi)), 3) AS g
                      ),
                      -- PER-PART shave (2026-07-20, the Penamaluru merge): a
                      -- collection-level negative buffer re-nodes lattice-
                      -- adjacent parts together (13 raster diamonds came out
                      -- as 4 fused parts). Shaving each dumped part on its
                      -- own can never merge parts; parts the shave empties
                      -- drop out and the null-geometry refusal catches a
                      -- fully-vanished piece.
                      leaf AS (
                          SELECT ST_CollectionExtract(ST_Collect(sg), 3) AS g
                            FROM (SELECT ST_CollectionExtract(ST_MakeValid(ST_Buffer(
                                             (ST_Dump((SELECT g FROM ix))).geom,
                                             -0.00000001)), 3) AS sg) shaved
                           WHERE NOT ST_IsEmpty(sg)
                      )
                 SELECT ST_AsGeoJSON((SELECT g FROM leaf), 15) AS gj,
                        ST_Area((SELECT g FROM leaf))
                            / NULLIF(ST_Area(ST_ConvexHull((SELECT g FROM leaf))), 0) AS chr',
                ['scope' => $scopeId, 'gj' => $groupGj]
            );
            $geometry = $row?->gj !== null ? json_decode($row->gj, true) : null;
            if ($geometry === null) {
                throw new RuntimeException("Component district c{$n} collapsed to an empty geometry — cut it by hand.");
            }

            $pop = (float) $this->raster->measureWithFallback($scopeId, json_encode($geometry), $year)['pop'];
            $seats = (int) round($pop / max($quota, 1e-9));
            if ($seats < 1) {
                throw new RuntimeException(
                    'A group of detached parts holds too little population for a seat — cut this scope by hand.'
                );
            }
            if ($seats > $ceiling) {
                throw new RuntimeException(
                    "A detached part holds {$seats} seats of population — above the ceiling {$ceiling}; cut it by hand."
                );
            }

            $sizes[] = $seats;
            $districts[] = [
                'path'                   => "root.c{$n}",
                'seats'                  => $seats,
                'pop'                    => (int) round($pop),
                'per_seat_deviation_pct' => round(abs($pop / $seats - $quota) / $quota * 100, 2),
                'convex_hull_ratio'      => round((float) ($row->chr ?? 0.0), 3),
                'geometry'               => $geometry,
            ];
        }

        return [
            'scope_id'        => $scopeId,
            'population_year' => $year,
            'seat_budget'     => $S,
            'sizes'           => $sizes,
            'total_pop'       => (int) round($total),
            'quota'           => round($quota, 1),
            'template'        => self::TEMPLATE_COMPONENTS,
            'cuts'            => [],
            'districts'       => $districts,
            'plan_hash'       => self::planHash($scopeId, $year, $sizes, [], self::TEMPLATE_COMPONENTS),
        ];
    }

    /**
     * Snap-to-balance for a hand-placed line: keep its angle, slide it along
     * its own normal to the seat split a:b (a+b=S, both in band) whose ratio is
     * nearest the line's current population split. One angle of the plan's
     * inner loop. $blade is the extended [ax, ay, bx, by] the controller built.
     *
     * @return array{line: array, angle_deg: float, seat_split: array{int,int}, pops: array{int,int}}
     *
     * @throws RuntimeException when no single straight cut is feasible for S
     */
    public function balanceLine(string $scopeId, array $ctx, array $blade, int $year = 2023): array
    {
        $S = (int) $ctx['budget'];
        $floor = (int) $ctx['floor'];
        $ceiling = (int) $ctx['ceiling'];

        $aMin = max($floor, $S - $ceiling);
        $aMax = min($ceiling, $S - $floor);
        if ($aMin > $aMax) {
            throw new RuntimeException(
                "No single straight cut can serve {$S} seats — one cut makes exactly two districts, "
                ."which together hold ".(2 * $floor)."–".(2 * $ceiling)." seats (band [{$floor}, {$ceiling}] each). "
                .'Use the autoseed for a full multi-cut plan.'
            );
        }

        // Cycle-2 (2026-07-19): zero-raster-coverage scopes (a geometry
        // outside its iso's tiles) fall back to the area-proportional grid —
        // same shape, deterministic from geometry + stored population. Only
        // a scope with no geometry or no population still refuses.
        $pixels = $this->raster->gridWithFallback($scopeId, $year);
        if (count($pixels) < 2) {
            throw new RuntimeException('No population raster pixels for this scope — load the WorldPop raster first.');
        }
        [$total, $lon0, $lat0, $cosLat] = self::gridFrame($pixels);

        // The line's angle in the local equirectangular frame (Δlon honest in
        // meters), normalized to [0, π) — the balanced blade keeps it exactly.
        [$ax, $ay, $bx, $by] = $blade;
        $theta = atan2($by - $ay, ($bx - $ax) * $cosLat);
        if ($theta < 0) {
            $theta += M_PI;
        }
        if ($theta >= M_PI) {
            $theta -= M_PI;
        }
        $nx = -sin($theta);
        $ny = cos($theta);

        // Where the hand-placed line sits now (both endpoints project equally
        // onto the normal — average them for robustness), and its current split.
        $c0 = ((($ax - $lon0) * $cosLat) * $nx + ($ay - $lat0) * $ny
             + (($bx - $lon0) * $cosLat) * $nx + ($by - $lat0) * $ny) / 2;
        $popA0 = 0.0;
        foreach ($pixels as $p) {
            if (($p[0] - $lon0) * $cosLat * $nx + ($p[1] - $lat0) * $ny < $c0) {
                $popA0 += $p[2];
            }
        }
        $frac0 = $popA0 / $total;

        $a = $aMin;
        for ($cand = $aMin + 1; $cand <= $aMax; $cand++) {
            if (abs($cand / $S - $frac0) < abs($a / $S - $frac0)) {
                $a = $cand;
            }
        }

        $found = self::bladeOffsetSearch($pixels, $nx, $ny, $lon0, $lat0, $cosLat, $a / $S * $total);
        if ($found === null) {
            throw new RuntimeException('This line cannot be slid to a balanced cut — too little population lies across it.');
        }
        [$c, $popA, $popB] = $found;

        $region = DB::selectOne(
            'SELECT ST_AsGeoJSON(ST_MakeValid(geom), 15) AS gj FROM jurisdictions WHERE id = ?',
            [$scopeId]
        );
        $line = $region?->gj !== null
            ? $this->clippedLine($region->gj, self::bladeThrough($c, $theta, $lon0, $lat0, $cosLat), $cosLat)
            : null;
        if ($line === null) {
            throw new RuntimeException('The balanced line no longer crosses the jurisdiction — place it nearer the middle.');
        }

        return [
            'line'       => $line,
            'angle_deg'  => rad2deg($theta),
            'seat_split' => [$a, $S - $a],
            'pops'       => [(int) round($popA), (int) round($popB)],
        ];
    }

    // ── deterministic seat arithmetic (pure, no DB) ─────────────────────────

    /**
     * Group a seat budget S into in-band district sizes: k = ceil(S/ceiling)
     * districts, as even as possible, smaller sizes first. 10→[5,5], 13→[6,7],
     * 21→[7,7,7], 32→[8,8,8,8].
     *
     * @return int[] each in [floor, ceiling], summing to $S
     */
    public static function seatGroups(int $S, int $floor = 5, int $ceiling = 9): array
    {
        $k = intdiv($S + $ceiling - 1, $ceiling);
        $q = intdiv($S, $k);
        $r = $S % $k;

        // q+1 > ceiling is impossible (q = ceiling forces r = 0), so only the
        // floor can fail — a band too tight for this budget.
        if ($q < $floor) {
            throw new RuntimeException(
                "A {$S}-seat budget cannot be grouped into districts of {$floor}–{$ceiling} seats."
            );
        }

        return array_merge(array_fill(0, $k - $r, $q), array_fill(0, $r, $q + 1));
    }

    /**
     * TIER 1 (2026-07-21) — the lawful two-split fallback order. When the
     * balanced grouping's single cut strands a fragment at every angle, a
     * 2-district node may still be cut at ANY in-band sizing (each side in
     * [floor, ceiling]). This returns every OTHER lawful low-side seat count
     * a — i.e. a ∈ [max(floor, S−ceiling), min(ceiling, S−floor)] minus the
     * balanced $balancedSeatsA already tried — ordered MOST-BALANCED-FIRST
     * (|a − S/2| asc, then a asc). The caller takes the first a whose cut is
     * contiguous, so the least-unbalanced lawful split that works is chosen —
     * honoring the autoseed doctrine's balance-over-compactness ordering.
     * Per-seat balance is not sacrificed: a 5:7 split of proportional
     * population deviates ~0 per seat (the 7-seat side rightly holds more
     * people). Pure, deterministic; pinned in AutoscalePinTest.
     *
     * @return int[] low-side seat counts to try, in order (empty if none)
     */
    public static function lawfulTwoSplitFallback(int $S, int $floor, int $ceiling, int $balancedSeatsA): array
    {
        $lo = max($floor, $S - $ceiling);
        $hi = min($ceiling, $S - $floor);
        $alts = [];
        for ($a = $lo; $a <= $hi; $a++) {
            if ($a !== $balancedSeatsA) {
                $alts[] = $a;
            }
        }
        usort($alts, fn (int $x, int $y) => abs($x - $S / 2) <=> abs($y - $S / 2) ?: $x <=> $y);

        return $alts;
    }

    /**
     * Split a sizes multiset into the two groups a cut separates, minimizing
     * |sumA − sumB|; ties prefer fewer elements in A, then the lexicographically
     * greatest sorted-descending A. k is small — full enumeration.
     *
     * @param  int[]  $sizes  at least two entries
     * @return array{int[], int[]} [A, B]
     */
    public static function bisectSizes(array $sizes): array
    {
        $sizes = array_values($sizes);
        rsort($sizes);
        $n = count($sizes);
        $total = array_sum($sizes);

        $best = null;
        for ($mask = 1; $mask < (1 << $n) - 1; $mask++) {
            $a = [];
            $sumA = 0;
            for ($i = 0; $i < $n; $i++) {
                if ($mask & (1 << $i)) {
                    $a[] = $sizes[$i];       // subsequence of a desc sort — already desc
                    $sumA += $sizes[$i];
                }
            }
            $cand = ['diff' => abs($total - 2 * $sumA), 'count' => count($a), 'a' => $a, 'mask' => $mask];
            if ($best === null || self::bisectionBeats($cand, $best)) {
                $best = $cand;
            }
        }

        $b = [];
        for ($i = 0; $i < $n; $i++) {
            if (! ($best['mask'] & (1 << $i))) {
                $b[] = $sizes[$i];
            }
        }

        return [$best['a'], $b];
    }

    private static function bisectionBeats(array $x, array $y): bool
    {
        if ($x['diff'] !== $y['diff']) {
            return $x['diff'] < $y['diff'];
        }
        if ($x['count'] !== $y['count']) {
            return $x['count'] < $y['count'];
        }
        foreach ($x['a'] as $i => $v) {
            if ($v !== $y['a'][$i]) {
                return $v > $y['a'][$i];
            }
        }

        return false;
    }

    /**
     * Exact one-angle balance: project every pixel onto the blade normal
     * (n = (nx, ny), unit, in the cosLat-scaled local frame), sort, prefix-sum,
     * and place the blade at the midpoint between the two pixels where the
     * cumulative population crosses $target. No iteration. Returns
     * [offset, popA, popB] (side A = projection < offset) or null when the
     * target cannot be crossed with both sides non-empty.
     */
    public static function bladeOffsetSearch(
        array $pixels,
        float $nx,
        float $ny,
        float $lon0,
        float $lat0,
        float $cosLat,
        float $target,
    ): ?array {
        $proj = [];
        $total = 0.0;
        foreach ($pixels as [$x, $y, $v]) {
            $proj[] = [($x - $lon0) * $cosLat * $nx + ($y - $lat0) * $ny, $v];
            $total += $v;
        }
        if ($target <= 0.0 || $target >= $total) {
            return null;
        }

        usort($proj, fn (array $p, array $q) => $p[0] <=> $q[0]);

        $n = count($proj);
        $cum = 0.0;
        for ($j = 0; $j < $n; $j++) {
            $cum += $proj[$j][1];
            if ($cum >= $target) {
                break;
            }
        }
        if ($j >= $n - 1) {
            return null;                       // all pixels would land on one side
        }

        $c = ($proj[$j][0] + $proj[$j + 1][0]) / 2;

        // Recount by the strict side predicate every consumer uses (t < c), so
        // tied projections at the boundary never desynchronize pop from geometry.
        $popA = 0.0;
        foreach ($proj as [$t, $v]) {
            if ($t < $c) {
                $popA += $v;
            }
        }

        return [$c, $popA, $total - $popA];
    }

    /**
     * Partition a pixel grid by containment in a GeoJSON Polygon/MultiPolygon
     * (an island component): returns [insidePixels, outsidePixels]. Pure PHP
     * ray casting with hole support and a bbox pre-filter — deterministic, and
     * cheap at binned-grid scale (tens of thousands of cells × a few islands).
     * A boundary-ambiguous cell defaults OUTSIDE (it stays with the mainland);
     * at cell scale that is noise against a ~500k-person quota, and every
     * filed piece is re-measured at full raster resolution by the handler.
     *
     * @param  array<int, array{0: float, 1: float, 2: float}>  $pixels
     * @return array{0: array, 1: array} [inside, outside]
     */
    public static function partitionPixelsByPolygon(array $pixels, array $geometry): array
    {
        $polys = match ($geometry['type'] ?? '') {
            'Polygon'      => [$geometry['coordinates']],
            'MultiPolygon' => $geometry['coordinates'],
            default        => [],
        };
        if ($polys === []) {
            return [[], $pixels];
        }

        // bbox pre-filter across all rings.
        $minX = INF; $minY = INF; $maxX = -INF; $maxY = -INF;
        foreach ($polys as $rings) {
            foreach ($rings[0] as [$x, $y]) {
                if ($x < $minX) $minX = $x;
                if ($x > $maxX) $maxX = $x;
                if ($y < $minY) $minY = $y;
                if ($y > $maxY) $maxY = $y;
            }
        }

        $inside = [];
        $outside = [];
        foreach ($pixels as $p) {
            [$px, $py] = $p;
            $in = false;
            if ($px >= $minX && $px <= $maxX && $py >= $minY && $py <= $maxY) {
                foreach ($polys as $rings) {
                    if (! self::pointInRing($px, $py, $rings[0])) {
                        continue;
                    }
                    $inHole = false;
                    for ($r = 1; $r < count($rings); $r++) {
                        if (self::pointInRing($px, $py, $rings[$r])) {
                            $inHole = true;
                            break;
                        }
                    }
                    if (! $inHole) {
                        $in = true;
                        break;
                    }
                }
            }
            if ($in) {
                $inside[] = $p;
            } else {
                $outside[] = $p;
            }
        }

        return [$inside, $outside];
    }

    /** Standard even-odd ray cast against one linear ring ([[x,y], ...], closed or not). */
    private static function pointInRing(float $px, float $py, array $ring): bool
    {
        $in = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if ((($yi > $py) !== ($yj > $py))
                && ($px < ($xj - $xi) * ($py - $yi) / (($yj - $yi) ?: 1e-300) + $xi)) {
                $in = ! $in;
            }
        }

        return $in;
    }

    // ── the recursive bisection tree ────────────────────────────────────────

    private function subdivide(
        string $scopeId,
        string $path,
        string $gj,
        array $pixels,
        array $islands,
        array $sizes,
        float $quota,
        array &$cuts,
        array &$districts,
        int &$order,
        string $template,
        int $floor,
        int $ceiling,
    ): void {
        if (count($sizes) === 1) {
            $pop = 0.0;
            foreach ($pixels as $p) {
                $pop += $p[2];
            }
            foreach ($islands as $isl) {
                $pop += $isl['pop'];
            }
            $seats = (int) $sizes[0];

            // A LEAF is what F-ELB-008 will file, and the handler proves exact
            // ST_CoveredBy against the giant — but the piece has round-tripped
            // through decimal GeoJSON, whose serialization epsilon (~1e-15°)
            // can nudge a boundary vertex just outside. Clip against the LIVE
            // giant geometry and shave 1e-8° (~1 mm) inward so the interior
            // margin dwarfs any round-trip error. Deterministic; invisible.
            // Islands riding this side union in here — the leaf files as ONE
            // multi-part piece whose extra parts are whole giant components
            // (the exact shape the handler's Art. II §8 rule admits).
            $leafGj = $islands === []
                ? $gj
                : json_encode([
                    'type'       => 'GeometryCollection',
                    'geometries' => array_merge(
                        [json_decode($gj, true)],
                        array_map(fn (array $isl) => json_decode($isl['gj'], true), $islands),
                    ),
                ]);

            $row = DB::selectOne(
                'WITH gi AS (SELECT ST_MakeValid(geom) AS g FROM jurisdictions WHERE id = :scope),
                      ix AS (
                          SELECT ST_CollectionExtract(ST_Intersection(
                                     ST_CollectionExtract(ST_MakeValid(ST_GeomFromGeoJSON(:gj)), 3),
                                     (SELECT g FROM gi)), 3) AS g
                      ),
                      -- PER-PART shave (2026-07-20, the Penamaluru merge): a
                      -- collection-level negative buffer re-nodes lattice-
                      -- adjacent parts together (13 raster diamonds came out
                      -- as 4 fused parts). Shaving each dumped part on its
                      -- own can never merge parts; parts the shave empties
                      -- drop out and the null-geometry refusal catches a
                      -- fully-vanished piece.
                      leaf AS (
                          SELECT ST_CollectionExtract(ST_Collect(sg), 3) AS g
                            FROM (SELECT ST_CollectionExtract(ST_MakeValid(ST_Buffer(
                                             (ST_Dump((SELECT g FROM ix))).geom,
                                             -0.00000001)), 3) AS sg) shaved
                           WHERE NOT ST_IsEmpty(sg)
                      )
                 SELECT ST_AsGeoJSON((SELECT g FROM leaf), 15) AS gj,
                        ST_Area((SELECT g FROM leaf))
                            / NULLIF(ST_Area(ST_ConvexHull((SELECT g FROM leaf))), 0) AS chr',
                ['scope' => $scopeId, 'gj' => $leafGj]
            );
            $geometry = $row?->gj !== null ? json_decode($row->gj, true) : null;
            if ($geometry === null) {
                throw new RuntimeException("District {$path} collapsed to an empty geometry — cut it by hand.");
            }

            $districts[] = [
                'path'                   => $path,
                'seats'                  => $seats,
                'pop'                    => (int) round($pop),
                'per_seat_deviation_pct' => round(abs($pop / $seats - $quota) / $quota * 100, 2),
                'convex_hull_ratio'      => round((float) ($row->chr ?? 0.0), 3),
                'geometry'               => $geometry,
            ];

            return;
        }

        [$aSizes, $bSizes] = self::bisectSizes($sizes);
        $seatsA = (int) array_sum($aSizes);
        $seatsB = (int) array_sum($bSizes);

        try {
            $cut = $this->findBlade($gj, $pixels, $islands, $seatsA, $seatsB, $quota, $template);
        } catch (RuntimeException $e) {
            // TIER 1 (operator-sanctioned 2026-07-21, the concave-residue fix):
            // the balanced grouping's single cut stranded a fragment at every
            // angle. But a 2-district node may lawfully split at ANY in-band
            // sizing — each side only needs seats in [floor, ceiling]. The
            // balanced pair is the FIRST choice (tried above, preserving the
            // deterministic map for every scope where it works), not the ONLY
            // one. On its failure, try each other lawful low-side seat count,
            // most-balanced-first, and take the first that yields a contiguous
            // cut. Per-seat balance is preserved — a 5:7 split of proportional
            // population deviates ~0 per seat, because the 7-seat side rightly
            // holds more people. Only the TERMINAL 2-way cut gets this
            // fallback; deeper (k>2) nodes keep the original throw so a giant's
            // upper-level balance is never traded away silently.
            if (count($sizes) !== 2) {
                throw $e;
            }
            $sNode = $seatsA + $seatsB;
            $alts  = self::lawfulTwoSplitFallback($sNode, $floor, $ceiling, $seatsA);

            $cut = null;
            foreach ($alts as $a) {
                try {
                    $cut    = $this->findBlade($gj, $pixels, $islands, $a, $sNode - $a, $quota, $template);
                    $aSizes = [$a];
                    $bSizes = [$sNode - $a];
                    $seatsA = $a;
                    $seatsB = $sNode - $a;
                    break;
                } catch (RuntimeException $inner) {
                    continue;
                }
            }
            if ($cut === null) {
                $lo = max($floor, $sNode - $ceiling);
                $hi = min($ceiling, $sNode - $floor);
                $tried = implode(', ', array_map(fn (int $a) => "{$a}:" . ($sNode - $a), range($lo, $hi)));
                throw new RuntimeException(
                    "No contiguous in-band straight cut found for a {$sNode}-seat two-district split "
                    ."at any lawful sizing ({$tried}) — cut it by hand."
                );
            }
        }

        $cuts[] = [
            'order'       => $order++,
            'parent_path' => $path,
            'line'        => $cut['line'],
            'angle_deg'   => $cut['angle_deg'],
            'sides'       => [
                ['pop' => (int) round($cut['pop_a']), 'seats' => $seatsA],
                ['pop' => (int) round($cut['pop_b']), 'seats' => $seatsB],
            ],
        ];

        $this->subdivide($scopeId, "{$path}.0", $cut['gj_a'], $cut['pixels_a'], $cut['islands_a'], $aSizes, $quota, $cuts, $districts, $order, $template, $floor, $ceiling);
        $this->subdivide($scopeId, "{$path}.1", $cut['gj_b'], $cut['pixels_b'], $cut['islands_b'], $bSizes, $quota, $cuts, $districts, $order, $template, $floor, $ceiling);
    }

    /**
     * The shortest valid balanced blade for one tree node: sweep the
     * template's angle set (theta = BLADE DIRECTION: 0° = an east–west blade,
     * 90° = a north–south blade), place each blade exactly by prefix-sum,
     * score by IN-REGION blade length (geography meters), then validate
     * winners shortest-first — ST_Split must leave each side a SINGLE polygon
     * (a U-shaped region can strand a fragment) and each side within the
     * per-seat deviation guard. The strip templates offer a single fixed
     * angle, so "shortest" degenerates to "the one candidate".
     */
    private function findBlade(string $regionGj, array $pixels, array $islands, int $seatsA, int $seatsB, float $quota, string $template): array
    {
        // Islands join the balance search as ONE super-pixel each — their
        // whole population at their representative point — so the prefix-sum
        // placement accounts for them exactly, and the SAME strict t < c
        // predicate that recounts the sides also decides which side each
        // island rides. The blade itself only ever cuts the mainland.
        $searchPixels = $pixels;
        foreach ($islands as $isl) {
            $searchPixels[] = [(float) $isl['cx'], (float) $isl['cy'], (float) $isl['pop']];
        }

        [$total, $lon0, $lat0, $cosLat] = self::gridFrame($searchPixels);
        if (count($searchPixels) < 2 || $total <= 0.0) {
            throw new RuntimeException('Too few populated pixels remain to cut this region.');
        }
        $target = $seatsA / ($seatsA + $seatsB) * $total;

        foreach (self::anglePasses($template) as $pass) {
            $candidates = [];
            foreach ($pass as $i => $angleDeg) {
                $theta = deg2rad($angleDeg);
                $nx = -sin($theta);
                $ny = cos($theta);

                $found = self::bladeOffsetSearch($searchPixels, $nx, $ny, $lon0, $lat0, $cosLat, $target);
                if ($found === null) {
                    continue;
                }
                [$c, $popA, $popB] = $found;
                if (abs($popA / $seatsA - $quota) / $quota > self::MAX_PER_SEAT_DEVIATION
                 || abs($popB / $seatsB - $quota) / $quota > self::MAX_PER_SEAT_DEVIATION) {
                    continue;
                }

                $candidates[] = [
                    'i' => $i, 'angle_deg' => $angleDeg,
                    'nx' => $nx, 'ny' => $ny, 'c' => $c,
                    'pop_a' => $popA, 'pop_b' => $popB,
                    'blade' => self::bladeThrough($c, $theta, $lon0, $lat0, $cosLat),
                ];
            }

            foreach ($candidates as &$cand) {
                $row = DB::selectOne(
                    'SELECT ST_Length(ST_Intersection(
                         ST_SetSRID(ST_MakeLine(ST_MakePoint(?, ?), ST_MakePoint(?, ?)), 4326),
                         ST_MakeValid(ST_GeomFromGeoJSON(?))
                     )::geography) AS len',
                    [...$cand['blade'], $regionGj]
                );
                $cand['len'] = (float) ($row->len ?? 0.0);
            }
            unset($cand);
            usort($candidates, fn (array $a, array $b) => $a['len'] <=> $b['len'] ?: $a['i'] <=> $b['i']);

            foreach ($candidates as $cand) {
                $sides = $this->splitRegion($regionGj, $cand, $lon0, $lat0, $cosLat);
                if ($sides === null) {
                    continue;
                }
                $line = $this->clippedLine($regionGj, $cand['blade'], $cosLat);
                if ($line === null) {
                    continue;
                }

                $pixelsA = [];
                $pixelsB = [];
                foreach ($pixels as $p) {
                    if (($p[0] - $lon0) * $cosLat * $cand['nx'] + ($p[1] - $lat0) * $cand['ny'] < $cand['c']) {
                        $pixelsA[] = $p;
                    } else {
                        $pixelsB[] = $p;
                    }
                }

                // Each island rides WHOLE by its super-pixel's side — the same
                // strict predicate as the recount, so population and geometry
                // can never disagree about where an island went.
                $islandsA = [];
                $islandsB = [];
                foreach ($islands as $isl) {
                    $t = ((float) $isl['cx'] - $lon0) * $cosLat * $cand['nx'] + ((float) $isl['cy'] - $lat0) * $cand['ny'];
                    if ($t < $cand['c']) {
                        $islandsA[] = $isl;
                    } else {
                        $islandsB[] = $isl;
                    }
                }

                return [
                    'angle_deg' => $cand['angle_deg'],
                    'line'      => $line,
                    'pop_a'     => $cand['pop_a'],
                    'pop_b'     => $cand['pop_b'],
                    'gj_a'      => $sides['a'],
                    'gj_b'      => $sides['b'],
                    'pixels_a'  => $pixelsA,
                    'pixels_b'  => $pixelsB,
                    'islands_a' => $islandsA,
                    'islands_b' => $islandsB,
                ];
            }
        }

        throw new RuntimeException(
            "No contiguous in-band straight cut found for a {$seatsA}:{$seatsB} split of this region "
            .match ($template) {
                self::TEMPLATE_VERTICAL_STRIPS   => "(the vertical_strips template's single 90° blade tried) — try the 'shortest' template or cut it by hand.",
                self::TEMPLATE_HORIZONTAL_STRIPS => "(the horizontal_strips template's single 0° blade tried) — try the 'shortest' template or cut it by hand.",
                default                          => '(48 candidate angles tried) — cut it by hand.',
            }
        );
    }

    /**
     * The candidate blade-angle passes for a template. Shortest sweeps a
     * coarse then a fine fan; each strip template is ONE fixed angle (the
     * prefix-sum placement is exact, so no retry pass exists to offer).
     *
     * @return float[][] passes of blade-direction angles in degrees
     */
    private static function anglePasses(string $template): array
    {
        return match ($template) {
            self::TEMPLATE_VERTICAL_STRIPS   => [[90.0]],
            self::TEMPLATE_HORIZONTAL_STRIPS => [[0.0]],
            default => array_map(
                fn (int $steps) => array_map(fn (int $i) => 180.0 * $i / $steps, range(0, $steps - 1)),
                self::ANGLE_PASSES,
            ),
        };
    }

    /**
     * ST_Split the region by the blade and union the pieces by side of the
     * blade (normal projection of each piece's point-on-surface vs the offset).
     * Returns ['a' => geojson, 'b' => geojson] only when BOTH sides exist and
     * each is a single polygon; null otherwise (try the next candidate).
     */
    private function splitRegion(string $regionGj, array $cand, float $lon0, float $lat0, float $cosLat): ?array
    {
        [$ax, $ay, $bx, $by] = $cand['blade'];

        $rows = DB::select(
            "WITH r AS (SELECT ST_MakeValid(ST_GeomFromGeoJSON(:gj)) AS g),
                  blade AS (SELECT ST_SetSRID(ST_MakeLine(ST_MakePoint(:ax, :ay), ST_MakePoint(:bx, :by)), 4326) AS l),
                  parts AS (
                      SELECT (ST_Dump(ST_Split((SELECT g FROM r), (SELECT l FROM blade)))).geom AS piece
                  ),
                  sided AS (
                      SELECT piece,
                             CASE WHEN :nx * ((ST_X(ST_PointOnSurface(piece)) - :lon0) * :coslat)
                                     + :ny * (ST_Y(ST_PointOnSurface(piece)) - :lat0) < :c
                                  THEN 'a' ELSE 'b' END AS side
                        FROM parts
                  )
             SELECT side,
                    ST_AsGeoJSON(ST_Union(piece), 15) AS gj,
                    ST_NumGeometries(ST_Multi(ST_Union(piece))) AS parts
               FROM sided GROUP BY side ORDER BY side",
            [
                'gj' => $regionGj, 'ax' => $ax, 'ay' => $ay, 'bx' => $bx, 'by' => $by,
                'nx' => $cand['nx'], 'lon0' => $lon0, 'coslat' => $cosLat,
                'ny' => $cand['ny'], 'lat0' => $lat0, 'c' => $cand['c'],
            ]
        );

        if (count($rows) !== 2) {
            return null;
        }
        $out = [];
        foreach ($rows as $row) {
            if ((int) $row->parts !== 1) {
                return null;                   // a stranded fragment — side not contiguous
            }
            $out[$row->side] = $row->gj;
        }

        return isset($out['a'], $out['b']) ? $out : null;
    }

    /**
     * The 2-point display line: the extremes of the blade clipped to the
     * region, along the blade's own direction. Null when the blade misses.
     *
     * @return array{type: string, coordinates: array}|null
     */
    private function clippedLine(string $regionGj, array $blade, float $cosLat): ?array
    {
        [$ax, $ay, $bx, $by] = $blade;
        $row = DB::selectOne(
            'SELECT ST_AsGeoJSON(ST_Intersection(
                 ST_SetSRID(ST_MakeLine(ST_MakePoint(?, ?), ST_MakePoint(?, ?)), 4326),
                 ST_MakeValid(ST_GeomFromGeoJSON(?))
             ), 15) AS gj',
            [$ax, $ay, $bx, $by, $regionGj]
        );

        $coords = self::collectCoordinates($row?->gj !== null ? json_decode($row->gj, true) : null);
        if (count($coords) < 2) {
            return null;
        }

        $dux = ($bx - $ax) * $cosLat;
        $duy = $by - $ay;
        $lo = null;
        $hi = null;
        $loT = INF;
        $hiT = -INF;
        foreach ($coords as $pt) {
            $t = ($pt[0] - $ax) * $cosLat * $dux + ($pt[1] - $ay) * $duy;
            if ($t < $loT) {
                $loT = $t;
                $lo = $pt;
            }
            if ($t > $hiT) {
                $hiT = $t;
                $hi = $pt;
            }
        }

        return [
            'type'        => 'LineString',
            'coordinates' => [[(float) $lo[0], (float) $lo[1]], [(float) $hi[0], (float) $hi[1]]],
        ];
    }

    /**
     * The extended blade [ax, ay, bx, by] through offset $c along the normal of
     * angle $theta, mapped back from the scaled local frame to lon/lat.
     */
    private static function bladeThrough(float $c, float $theta, float $lon0, float $lat0, float $cosLat): array
    {
        $px = $lon0 + ($c * -sin($theta)) / $cosLat;
        $py = $lat0 + $c * cos($theta);

        $dx = cos($theta) / $cosLat;           // undo the equirectangular scale
        $dy = sin($theta);
        $len = sqrt($dx * $dx + $dy * $dy);
        $ux = $dx / $len;
        $uy = $dy / $len;

        return [
            $px - $ux * self::EXTENSION_DEG, $py - $uy * self::EXTENSION_DEG,
            $px + $ux * self::EXTENSION_DEG, $py + $uy * self::EXTENSION_DEG,
        ];
    }

    /**
     * The scaled local frame every consumer (splitline AND the cell seeder)
     * projects into: equirectangular about the pixel centroid, Δlon scaled by
     * cos(meanLat) so distances are honest in meters.
     *
     * @return array{float, float, float, float} [totalPop, meanLon, meanLat, cos(meanLat)]
     */
    public static function gridFrame(array $pixels): array
    {
        $total = 0.0;
        $sumLon = 0.0;
        $sumLat = 0.0;
        foreach ($pixels as [$x, $y, $v]) {
            $total += $v;
            $sumLon += $x;
            $sumLat += $y;
        }
        $n = max(count($pixels), 1);
        $lat0 = $sumLat / $n;

        return [$total, $sumLon / $n, $lat0, max(cos(deg2rad($lat0)), 1e-9)];
    }

    /** All coordinate pairs of any GeoJSON geometry (collections included). */
    private static function collectCoordinates(?array $geom): array
    {
        if ($geom === null) {
            return [];
        }
        if (isset($geom['geometries'])) {
            $out = [];
            foreach ($geom['geometries'] as $g) {
                $out = array_merge($out, self::collectCoordinates($g));
            }

            return $out;
        }

        return self::flattenCoordinates($geom['coordinates'] ?? []);
    }

    private static function flattenCoordinates(array $coords): array
    {
        if ($coords === []) {
            return [];
        }
        if (is_numeric($coords[0] ?? null)) {
            return [$coords];
        }
        $out = [];
        foreach ($coords as $c) {
            $out = array_merge($out, self::flattenCoordinates($c));
        }

        return $out;
    }

    /**
     * The determinism receipt: sha256 over the canonical plan identity —
     * scope, raster year, TEMPLATE, seat grouping, and every cut line's
     * coordinates rounded to 7 decimals (~1 cm). Commit recomputes and
     * compares, so a template swap between preview and commit fails closed.
     */
    private static function planHash(string $scopeId, int $year, array $sizes, array $cuts, string $template): string
    {
        $lines = array_map(
            fn (array $cut) => array_map(
                fn (array $pt) => [round($pt[0], 7), round($pt[1], 7)],
                $cut['line']['coordinates'],
            ),
            $cuts,
        );

        return hash('sha256', json_encode([
            'scope_id'        => $scopeId,
            'population_year' => $year,
            'sizes'           => array_values($sizes),
            'template'        => $template,
            'lines'           => $lines,
        ]));
    }
}
