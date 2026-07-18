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
     */
    public const TEMPLATE_SHORTEST = 'shortest';

    public const TEMPLATE_VERTICAL_STRIPS = 'vertical_strips';

    public const TEMPLATE_HORIZONTAL_STRIPS = 'horizontal_strips';

    public const TEMPLATE_COMMUNITY_CELLS = 'community_cells';

    public const TEMPLATES = [
        self::TEMPLATE_SHORTEST,
        self::TEMPLATE_VERTICAL_STRIPS,
        self::TEMPLATE_HORIZONTAL_STRIPS,
        self::TEMPLATE_COMMUNITY_CELLS,
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

        $pixels = $this->raster->pixelGrid($scopeId, $year);
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
        // search runs on the LARGEST part (the mainland); each island joins
        // the search as ONE super-pixel at its representative point carrying
        // its whole population, so balance stays exact and deterministic.
        $comps = DB::select(
            'SELECT ST_AsGeoJSON(g, 15) AS gj,
                    ST_Area(g::geography) AS area,
                    ST_X(ST_PointOnSurface(g)) AS cx,
                    ST_Y(ST_PointOnSurface(g)) AS cy
               FROM (SELECT (ST_Dump(ST_MakeValid(geom))).geom AS g
                       FROM jurisdictions WHERE id = ?) t
              ORDER BY ST_Area(g::geography) DESC, ST_X(ST_PointOnSurface(g)), ST_Y(ST_PointOnSurface(g))',
            [$scopeId]
        );

        $mainlandGj = $region->gj;
        $islands = [];
        if (count($comps) > 1) {
            $mainlandGj = (string) $comps[0]->gj;
            $mainPixels = $pixels;
            foreach (array_slice($comps, 1) as $comp) {
                $poly = json_decode((string) $comp->gj, true);
                [$inside, $mainPixels] = self::partitionPixelsByPolygon($mainPixels, $poly);
                $pop = 0.0;
                foreach ($inside as $p) {
                    $pop += $p[2];
                }
                $islands[] = [
                    'gj'     => (string) $comp->gj,
                    'cx'     => (float) $comp->cx,
                    'cy'     => (float) $comp->cy,
                    'pop'    => $pop,
                    'pixels' => $inside,
                ];
            }
            $pixels = $mainPixels;
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
        $this->subdivide($scopeId, 'root', $mainlandGj, $pixels, $islands, $sizes, $quota, $cuts, $districts, $order, $template);

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

        $pixels = $this->raster->pixelGrid($scopeId, $year);
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
                      leaf AS (
                          SELECT ST_CollectionExtract(ST_MakeValid(ST_Buffer(
                                     ST_CollectionExtract(ST_Intersection(
                                         ST_CollectionExtract(ST_MakeValid(ST_GeomFromGeoJSON(:gj)), 3),
                                         (SELECT g FROM gi)), 3),
                                     -0.00000001)), 3) AS g
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
        $cut = $this->findBlade($gj, $pixels, $islands, array_sum($aSizes), array_sum($bSizes), $quota, $template);

        $cuts[] = [
            'order'       => $order++,
            'parent_path' => $path,
            'line'        => $cut['line'],
            'angle_deg'   => $cut['angle_deg'],
            'sides'       => [
                ['pop' => (int) round($cut['pop_a']), 'seats' => array_sum($aSizes)],
                ['pop' => (int) round($cut['pop_b']), 'seats' => array_sum($bSizes)],
            ],
        ];

        $this->subdivide($scopeId, "{$path}.0", $cut['gj_a'], $cut['pixels_a'], $cut['islands_a'], $aSizes, $quota, $cuts, $districts, $order, $template);
        $this->subdivide($scopeId, "{$path}.1", $cut['gj_b'], $cut['pixels_b'], $cut['islands_b'], $bSizes, $quota, $cuts, $districts, $order, $template);
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
