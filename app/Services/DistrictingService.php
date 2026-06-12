<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WI-B3 — DistrictingService: the district auto-composite algorithm,
 * extracted MECHANICALLY from LegislatureController (2026-06-12) so the
 * election scheduling path (F-ELB-001 / ScheduleGeneralElectionJob, design
 * PHASE_B_DESIGN_schema_lifecycle §B.4 — San Marino initial-map
 * auto-generation, WI-B7) can run it without an HTTP controller.
 *
 * EVERY method body below is byte-identical to its controller original —
 * battle-tested code, moved not refactored. The controller now delegates
 * (thresholds / computeSeatBudget / computeNonGiantQuota /
 * recomputeDistrict / runAutoCompositeForScope / publishMassProgress keep
 * their exact signatures there). Constitutional thresholds resolve through
 * ConstitutionalDefaults exactly as before.
 *
 * Per-request memos (legislature rows, seat budgets) live on the instance;
 * the controller holds one instance per request (constructor injection),
 * preserving the original memoization scope.
 */
class DistrictingService
{
    /**
     * Resolve the three constitutional fractional-seats thresholds used
     * throughout the district-mapper. With default 5/9 settings these
     * return 9.5 / 5.0 / 4.5 (matching the historical hardcoded literals).
     * With operator-set 3/7 they return 7.5 / 3.0 / 2.5.
     *
     * @return array{giant: float, floor: float, override: float}
     *   giant    — fractional ≥ giant must be split (ceiling + 0.5)
     *   floor    — composite fractional sum ≥ floor rounds to ≥ floor
     *   override — fractional < override triggers a floor-override flag
     */
    public function thresholds(string $jurisdictionId): array
    {
        return [
            'giant'    => ConstitutionalDefaults::giantThreshold($jurisdictionId),
            'floor'    => ConstitutionalDefaults::floorBoundary($jurisdictionId),
            'override' => ConstitutionalDefaults::floorOverrideBoundary($jurisdictionId),
        ];
    }

    /** Per-request memo for getLegislature(). */
    private array $legislatureMemo = [];

    /** Per-request memo for computeSeatBudget(). Keyed "{legId}:{jid}". */
    private array $seatBudgetMemo = [];

    /**
     * Memoized legislature row loader. Same row may be needed by several
     * computeSeatBudget() walks during one request.
     */
    private function getLegislature(string $legislatureId): ?object
    {
        if (array_key_exists($legislatureId, $this->legislatureMemo)) {
            return $this->legislatureMemo[$legislatureId];
        }
        $row = DB::table('legislatures')
            ->where('id', $legislatureId)
            ->whereNull('deleted_at')
            ->first();
        return $this->legislatureMemo[$legislatureId] = $row;
    }

    /**
     * Returns the seat count for a jurisdiction at its own scope.
     *
     * Exit paths (first match wins):
     *   1. ROOT     — jurisdiction is the legislature's root jurisdiction:
     *                 return legislatures.type_a_seats.
     *   2. LOOKUP   — jurisdiction is already a member of a non-deleted
     *                 legislature_districts row in this legislature:
     *                 return that row's seats. Cheap gate that covers
     *                 ~all non-giant jurisdictions after autoseed.
     *   3. CASCADE  — only fires when the lookup misses (jurisdiction is
     *                 a GIANT at its parent's scope, so Step 12 never
     *                 inserts a district for it; or parent scope hasn't
     *                 been autoseeded yet). Recursively compute parent's
     *                 budget, then apply Calc A at parent scope:
     *                   Q(parent) = sum_children_pop(parent) / S(parent)
     *                   frac(self) = self.pop / Q(parent)
     *                 Return max(floor, round(frac)).
     *
     * Memoized per request. Recursion depth ≤ ADM hierarchy depth (~5).
     * Works at N layers of nested giants (Earth → China → Guangzhou
     * → Shenzhenxian → …) without code change.
     *
     * The lookup gate (Path 2) keeps the helper bounded when called
     * across many jurisdictions: only giants reach Path 3, and giants
     * are a small fraction of the table.
     *
     * @return int|null  null when chain breaks (no legislature, no
     *                   parent, zero children pop, etc.)
     */
    public function computeSeatBudget(string $jurisdictionId, string $legislatureId): ?int
    {
        $key = "{$legislatureId}:{$jurisdictionId}";
        if (array_key_exists($key, $this->seatBudgetMemo)) {
            return $this->seatBudgetMemo[$key];
        }

        $leg = $this->getLegislature($legislatureId);
        if (!$leg) return $this->seatBudgetMemo[$key] = null;

        // ── Path 1: ROOT ─────────────────────────────────────────────
        if ($jurisdictionId === $leg->jurisdiction_id) {
            return $this->seatBudgetMemo[$key] = (int) $leg->type_a_seats;
        }

        // ── Path 2: LOOKUP (cheap; gates the recursion) ──────────────
        // If this jurisdiction is already a member of a district in this
        // legislature, return that district's seats. Avoids any cascade
        // work for the common non-giant case.
        $row = DB::selectOne("
            SELECT ld.seats
              FROM legislature_districts ld
              JOIN legislature_district_jurisdictions ldj
                ON ldj.district_id = ld.id
             WHERE ldj.jurisdiction_id = ?
               AND ld.legislature_id  = ?
               AND ld.deleted_at IS NULL
             ORDER BY ld.seats DESC LIMIT 1
        ", [$jurisdictionId, $legislatureId]);
        if ($row) {
            return $this->seatBudgetMemo[$key] = (int) $row->seats;
        }

        // ── Path 3: CASCADE — only when lookup missed ────────────────
        // Reaches here ONLY when the jurisdiction has no district
        // membership: either it's a giant at its parent's scope
        // (Step 12 doesn't insert a district for giants) or the parent
        // scope's autoseed hasn't run yet (first-time-create path).
        $self = DB::table('jurisdictions')
            ->where('id', $jurisdictionId)
            ->whereNull('deleted_at')
            ->first(['id', 'parent_id', 'population']);
        if (!$self || !$self->parent_id) {
            return $this->seatBudgetMemo[$key] = null;
        }

        $parentBudget = $this->computeSeatBudget($self->parent_id, $legislatureId);
        if ($parentBudget === null) {
            return $this->seatBudgetMemo[$key] = null;
        }

        // Calc A: Q(parent) = Σ children pop / S(parent);
        //         frac(self) = self.pop / Q(parent).
        // Sum of all parent-children fracs equals S(parent) exactly,
        // so chaining stays budget-exact at every level.
        $parentChildrenPop = (int) DB::table('jurisdictions')
            ->where('parent_id', $self->parent_id)
            ->whereNull('deleted_at')
            ->sum('population');
        if ($parentChildrenPop <= 0) {
            return $this->seatBudgetMemo[$key] = null;
        }

        $parentLocalQuota = $parentChildrenPop / max($parentBudget, 1);
        $frac  = ((int) $self->population) / max($parentLocalQuota, 1);
        $floor = ConstitutionalDefaults::floor($leg->jurisdiction_id);

        return $this->seatBudgetMemo[$key] = max($floor, (int) round($frac));
    }

    /**
     * Core auto-composite algorithm for a single scope.
     *
     * Caller is responsible for the DB transaction boundary. Colors are
     * computed at read time by colorIndicesForDistricts() (scope-local greedy
     * adjacency 7-coloring), so no recompute step is needed here.
     * Returns ['districts_created' => int, 'error' => string|null].
     * 'error' is non-null for recoverable no-op cases (e.g. no compositable children).
     * Throws on genuine exceptions — caller should catch and roll back.
     *
     * @param int $seatBudget  Exact integer seat allocation for this scope
     *                         (leg->type_a_seats at root; type_a_apportioned at sub-scopes).
     */
    public function runAutoCompositeForScope(
        string  $legislature_id,
        object  $leg,
        string  $scopeId,
        bool    $clearExisting,
        int     $seatBudget,
        ?string $mapId = null
    ): array {
        // Constitutional thresholds — substituted throughout the algorithm
        // for the legacy 9.5 / 5.0 / 4.5 / 9 / 5 literals.
        ['giant' => $giantThreshold, 'floor' => $floorBoundary] = $this->thresholds($leg->jurisdiction_id);
        $floor   = ConstitutionalDefaults::floor($leg->jurisdiction_id);
        $ceiling = ConstitutionalDefaults::ceiling($leg->jurisdiction_id);

        // ── Step 1: Fetch ALL direct children with geometry ──────────────────
        $this->publishMassProgress($legislature_id, [
            'phase'       => 'loading',
            'phase_label' => 'Loading children + centroids',
        ]);
        $allChildrenRows = DB::select("
            SELECT
                j.id, j.name, j.population,
                ST_X(ST_Centroid(j.geom)) AS centroid_x,
                ST_Y(ST_Centroid(j.geom)) AS centroid_y
            FROM jurisdictions j
            WHERE j.parent_id = :scope_id
              AND j.deleted_at IS NULL
              AND j.geom IS NOT NULL
            ORDER BY j.population DESC
        ", ['scope_id' => $scopeId]);

        if (empty($allChildrenRows)) {
            return ['districts_created' => 0, 'error' => 'No children with geometry found at this scope'];
        }

        // ── Step 2: Level-local quota + fractional seats ──────────────────────
        $totalChildPop = array_sum(array_map(fn($c) => (int) $c->population, $allChildrenRows));
        $localQuota    = $totalChildPop / max($seatBudget, 1);

        foreach ($allChildrenRows as $c) {
            $c->fractional_seats = (float) $c->population / max($localQuota, 1);
        }

        // ── Step 3: Classify giants vs non-giants ─────────────────────────────
        $giantRows    = [];
        $nonGiantRows = [];
        foreach ($allChildrenRows as $c) {
            if ($c->fractional_seats >= $giantThreshold) {
                $giantRows[] = $c;
            } else {
                $nonGiantRows[] = $c;
            }
        }
        $this->publishMassProgress($legislature_id, [
            'phase'         => 'classified',
            'phase_label'   => sprintf(
                'Classified %d children: %d giant + %d compositable, budget %d seats',
                count($allChildrenRows), count($giantRows), count($nonGiantRows), $seatBudget,
            ),
            'phase_current' => 0,
            'phase_total'   => count($nonGiantRows),
        ]);

        // ── Step 4: Lock giant seat allocations ───────────────────────────────
        // Each giant's locked seat count is the round-up of its fractional
        // seats. The value isn't persisted to a column — downstream readers
        // (computeSeatBudget Path 3) recompute it on demand by walking the
        // parent cascade. Step 12 below only inserts non-giant bin
        // districts; giants have no row at this scope and their budget is
        // derived through the cascade when sub-scopes need it.
        $giantSeats = [];
        foreach ($giantRows as $g) {
            $seats = max($floor, (int) round($g->fractional_seats));
            $giantSeats[$g->id] = $seats;
        }

        // ── Step 5: Non-giant seat budget ─────────────────────────────────────
        $nonGiantBudget = $seatBudget - array_sum($giantSeats);

        if (empty($nonGiantRows)) {
            return ['districts_created' => 0, 'error' => 'No compositable (non-giant) children found at this scope'];
        }

        // ── Step 6: Filter already-assigned non-giants (when not clearing) ────
        if (!$clearExisting) {
            $nonGiantIds  = array_column($nonGiantRows, 'id');
            $assignedQuery = DB::table('legislature_district_jurisdictions as ldj')
                ->join('legislature_districts as ld', 'ld.id', '=', 'ldj.district_id')
                ->where('ld.legislature_id', $legislature_id)
                ->whereNull('ld.deleted_at')
                ->whereIn('ldj.jurisdiction_id', $nonGiantIds);
            if ($mapId !== null) {
                $assignedQuery->where('ld.map_id', $mapId);
            }
            $assignedIds  = $assignedQuery->pluck('ldj.jurisdiction_id')->toArray();
            $nonGiantRows = array_values(array_filter($nonGiantRows, fn($c) => !in_array($c->id, $assignedIds)));
        }

        if (empty($nonGiantRows)) {
            return ['districts_created' => 0, 'error' => 'No unassigned compositable children found at this scope'];
        }

        // Build childById + centroids for BFS (non-giants only)
        $childById = [];
        $centroids  = [];
        foreach ($nonGiantRows as $c) {
            $childById[$c->id] = $c;
            $centroids[$c->id] = ['x' => (float) $c->centroid_x, 'y' => (float) $c->centroid_y];
        }
        $childIds = array_column($nonGiantRows, 'id');

        // ── Step 7: Adjacency + BFS connected components ──────────────────────
        // Two-tier conditional simplify on huge geoms. ST_Intersection on raw
        // multipart polygons (Quebec, Russian oblasts, Nunavut) takes 30-180s
        // per pair and is uninterruptible; on simplified geoms it's seconds.
        //
        // Tier 1 (>1M vertices, e.g. Nunavut at 5.4M): 0.01° ≈ 1.1km. Even
        // with single-tier 0.001° simplification, Nunavut still emerges at
        // 434k vertices, and the simplify call alone takes ~55s. The 0.01°
        // tier brings Nunavut to ~40k, completing in <10s for all of Canada.
        // Tier 2 (>50k vertices): 0.001° ≈ 110m, finer than geoBoundaries'
        // real border precision.
        //
        // ST_MakeValid wraps each simplify because simplifying complex
        // coastlines can introduce self-intersections (e.g. James Bay coast)
        // that crash ST_Intersection with a GEOS topology exception.
        $idsStr = '{' . implode(',', $childIds) . '}';
        $edges  = DB::select("
            WITH g AS (
                SELECT id,
                       CASE
                           WHEN ST_NPoints(geom) > 1000000
                                THEN ST_MakeValid(ST_Simplify(geom, 0.01))
                           WHEN ST_NPoints(geom) > 50000
                                THEN ST_MakeValid(ST_Simplify(geom, 0.001))
                           ELSE geom
                       END AS geom
                FROM jurisdictions
                WHERE id = ANY(:ids::uuid[])
                  AND deleted_at IS NULL
                  AND geom IS NOT NULL
            )
            SELECT a.id AS j1, b.id AS j2
            FROM g a
            JOIN g b ON a.id < b.id
                AND a.geom && b.geom
                AND ST_Intersects(a.geom, b.geom)
                AND ST_Dimension(ST_Intersection(a.geom, b.geom)) >= 1
        ", ['ids' => $idsStr]);

        $adj = [];
        foreach ($childIds as $id) $adj[$id] = [];
        foreach ($edges as $edge) {
            $adj[$edge->j1][] = $edge->j2;
            $adj[$edge->j2][] = $edge->j1;
        }

        $visited    = [];
        $components = [];
        foreach ($childIds as $id) {
            if (isset($visited[$id])) continue;
            $component = [];
            $queue     = [$id];
            $visited[$id] = true;
            while (!empty($queue)) {
                $curr        = array_shift($queue);
                $component[] = $curr;
                foreach ($adj[$curr] as $neighbor) {
                    if (!isset($visited[$neighbor])) {
                        $visited[$neighbor] = true;
                        $queue[] = $neighbor;
                    }
                }
            }
            $components[] = $component;
        }

        // ── Step 8: Multi-attempt seed expansion — retain best by constitutional priority ───
        // For each component, tries every integer k in [kMin, min(kMax, kMin+7)] across 3 seed
        // strategies, scoring every candidate in-memory.  Constitutional scoring priority:
        //   1. Fewest non-contiguous bins
        //   2. Lowest avg deviation %   (overall population equality — primary equality metric)
        //   3. Lowest avg Droop threshold (UPD diversity: 5×9+2×8 beats 9×6+1×7 — bigger districts
        //                                  clear at a lower threshold; see scoreConfiguration())
        //   4. Lowest max deviation %   (worst-district tiebreaker)
        //
        // The Uniform Political Diversity metric is the average Droop entry threshold (1/(s+1) per
        // district): larger magnitudes → lower threshold → more proportional → more diverse, while a
        // lone small district raises the average (spread penalty). It replaces the old seat-variance
        // uniformity proxy, which preferred the opposite (many equal small districts).
        // Zero DB queries; uses the adjacency graph already in memory.
        $allBins     = [];
        $totalBinPop = array_sum(array_map(fn($jid) => (int) $childById[$jid]->population, $childIds));

        foreach ($components as $component) {
            $compFrac = array_sum(array_map(fn($jid) => (float) $childById[$jid]->fractional_seats, $component));

            // Single-district components need no splitting — skip multi-attempt overhead
            if ($compFrac < $giantThreshold) {
                $allBins[] = $component;
                continue;
            }

            // k range: constitutional ceiling (max seats) → constitutional floor (min seats)
            $kMin = max(2, (int) ceil($compFrac / (float) $ceiling));
            $kMax = max($kMin, (int) floor($compFrac / (float) $floor));

            // Exhaustive integer range [kMin, min(kMax, kMin+7)].
            // Cap at kMin+7 so runtime stays bounded for very large budgets (≤8 k values × 3 strategies = ≤24 BFS runs).
            // Full range ensures UPD-optimal k values (e.g. k=10 for a 61-seat budget) are never skipped.
            $kCandidates = range($kMin, min($kMax, $kMin + 7));

            // Component-level proportional budget (fixed regardless of k)
            $compBinPop = array_sum(array_map(fn($jid) => (int) $childById[$jid]->population, $component));
            $compBudget = $totalBinPop > 0
                ? (int) round($compBinPop * $nonGiantBudget / $totalBinPop)
                : $nonGiantBudget;

            $bestBins  = null;
            $bestScore = null;

            foreach ($kCandidates as $k) {
                $targetPopK = $compBinPop > 0 ? (float) $compBinPop / $k : 0.0;

                // ── Phase A: BFS-only scan over ALL jids as potential first seeds ─────
                // geographicSeedExpansion($bfsOnly=true) returns after BFS before passes.
                // Scoring here is a cheap raw sum-of-absolute-deviations proxy (no Webster).
                // This lets us try every possible geographic partition from every starting
                // point, rather than just 6 fixed cardinal-direction anchors.
                $bfsCandidates = [];
                foreach ($component as $firstSeed) {
                    $seeds    = $this->farPointSeeds($firstSeed, $k, $component, $centroids);
                    $bfsBins  = $this->geographicSeedExpansion($component, $childById, $adj, $centroids, $seeds, $giantThreshold, $floorBoundary, true);
                    $devProxy = 0.0;
                    foreach ($bfsBins as $bin) {
                        $pop       = array_sum(array_map(fn($jid) => (float) $childById[$jid]->population, $bin));
                        $devProxy += abs($pop - $targetPopK);
                    }
                    $bfsCandidates[] = ['seeds' => $seeds, 'dev' => $devProxy];
                }
                usort($bfsCandidates, fn($a, $b) => $a['dev'] <=> $b['dev']);

                // ── Phase B: Full pipeline (balance + compact + balance) on top 20 ────
                // Running balance+compact+balance on every jid as first seed is unnecessary;
                // the raw BFS score is a reliable proxy for which starting configurations
                // will converge to better final results.
                $topN = min(count($component), 20);
                foreach (array_slice($bfsCandidates, 0, $topN) as $candidate) {
                    $bins = $this->geographicSeedExpansion($component, $childById, $adj, $centroids, $candidate['seeds'], $giantThreshold, $floorBoundary);

                    $effectiveBudget = max(count($bins), $compBudget);

                    $score = $this->scoreConfiguration($bins, $childById, $adj, (float) $compBinPop, $effectiveBudget, $floor, $ceiling, $floorBoundary);

                    // Lexicographic priority:
                    //   1. Fewest non-contiguous bins (hard constraint)
                    //   2. Fewest districts with |dev| > 2%  (per-district balance target)
                    //   3. Lowest avg Rg²                    (compactness — stringy = bad)
                    //   4. Lowest avg Droop threshold        (UPD diversity — bigger districts win)
                    //   5. Lowest avg deviation %            (fine-tune balance)
                    if (
                        $bestScore === null ||
                        $score['non_contiguous_count'] < $bestScore['non_contiguous_count'] ||
                        ($score['non_contiguous_count'] === $bestScore['non_contiguous_count'] &&
                         $score['count_over_2pct']      < $bestScore['count_over_2pct']) ||
                        ($score['non_contiguous_count'] === $bestScore['non_contiguous_count'] &&
                         $score['count_over_2pct']      === $bestScore['count_over_2pct'] &&
                         $score['avg_rg_sq']            < $bestScore['avg_rg_sq']) ||
                        ($score['non_contiguous_count'] === $bestScore['non_contiguous_count'] &&
                         $score['count_over_2pct']      === $bestScore['count_over_2pct'] &&
                         $score['avg_rg_sq']            === $bestScore['avg_rg_sq'] &&
                         $score['avg_droop_threshold']  < $bestScore['avg_droop_threshold']) ||
                        ($score['non_contiguous_count'] === $bestScore['non_contiguous_count'] &&
                         $score['count_over_2pct']      === $bestScore['count_over_2pct'] &&
                         $score['avg_rg_sq']            === $bestScore['avg_rg_sq'] &&
                         $score['avg_droop_threshold']  === $bestScore['avg_droop_threshold'] &&
                         $score['avg_deviation_pct']    < $bestScore['avg_deviation_pct'])
                    ) {
                        $bestBins  = $bins;
                        $bestScore = $score;
                    }
                }
            }

            $allBins = array_merge($allBins, $bestBins ?? [$component]);
        }

        $this->publishMassProgress($legislature_id, [
            'phase'         => 'binning_done',
            'phase_label'   => sprintf('Bin partitioning complete: %d bins formed', count($allBins)),
            'phase_current' => count($allBins),
            'phase_total'   => count($allBins),
        ]);

        // Cross-component post-repair: merge undersized bins (< floor fractional) into
        // nearest absorbable bin (merged total < giant_threshold). Handles isolated
        // island jurisdictions.
        $globalBinFracs = array_map(fn($bin) =>
            array_sum(array_map(fn($jid) => (float) $childById[$jid]->fractional_seats, $bin)),
            $allBins
        );

        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($globalBinFracs as $i => $t) {
                if ($t >= $floorBoundary || empty($allBins[$i])) continue;
                $iCenter  = $this->binCentroid($allBins[$i], $centroids);
                $bestJ    = -1;
                $bestDist = PHP_FLOAT_MAX;
                foreach ($globalBinFracs as $j => $tj) {
                    if ($j === $i || empty($allBins[$j])) continue;
                    if ($tj + $t >= $giantThreshold) continue;
                    $jCenter = $this->binCentroid($allBins[$j], $centroids);
                    $dx = $iCenter['x'] - $jCenter['x'];
                    $dy = $iCenter['y'] - $jCenter['y'];
                    $d  = $dx * $dx + $dy * $dy;
                    if ($d < $bestDist) { $bestDist = $d; $bestJ = $j; }
                }
                if ($bestJ >= 0) {
                    $allBins[$bestJ]        = array_merge($allBins[$bestJ], $allBins[$i]);
                    $globalBinFracs[$bestJ] += $globalBinFracs[$i];
                    $allBins[$i]            = [];
                    $globalBinFracs[$i]     = 0.0;
                    $changed = true;
                    break;
                }
            }
        }
        $allBins = array_values(array_filter($allBins, fn($b) => !empty($b)));

        // ── Step 9: Clear existing districts if requested ─────────────────────
        if ($clearExisting) {
            $this->publishMassProgress($legislature_id, [
                'phase'       => 'clearing',
                'phase_label' => 'Clearing existing districts at this scope',
            ]);
            // Clear null-jurisdiction composites whose members are direct children of this scope
            $nullClearQuery = DB::table('legislature_districts AS ld')
                ->join('legislature_district_jurisdictions AS ldj', 'ldj.district_id', '=', 'ld.id')
                ->join('jurisdictions AS j', 'j.id', '=', 'ldj.jurisdiction_id')
                ->where('ld.legislature_id', $legislature_id)
                ->whereNull('ld.jurisdiction_id')
                ->where('j.parent_id', $scopeId)
                ->whereNull('j.deleted_at');
            if ($mapId !== null) {
                $nullClearQuery->where('ld.map_id', $mapId);
            }
            $nullIds = $nullClearQuery->distinct()->pluck('ld.id')->toArray();
            foreach ($nullIds as $eid) {
                DB::table('legislature_district_jurisdictions')->where('district_id', $eid)->delete();
                DB::table('legislature_districts')->where('id', $eid)->delete();
            }

            // Clear districts scoped directly to this jurisdiction
            $existClearQuery = DB::table('legislature_districts')
                ->where('legislature_id', $legislature_id)
                ->where('jurisdiction_id', $scopeId);
            if ($mapId !== null) {
                $existClearQuery->where('map_id', $mapId);
            }
            $existingIds = $existClearQuery->pluck('id');
            foreach ($existingIds as $eid) {
                DB::table('legislature_district_jurisdictions')->where('district_id', $eid)->delete();
                DB::table('legislature_districts')->where('id', $eid)->delete();
            }
        }

        // ── Step 10: Collect bin populations ─────────────────────────────────
        $binData = [];
        foreach ($allBins as $binJids) {
            if (empty($binJids)) continue;
            $pop = array_sum(array_map(fn($jid) => (int) $childById[$jid]->population, $binJids));
            $binData[] = [
                'jids'          => $binJids,
                'pop'           => $pop,
                'floor_override'=> false,   // set in Step 11
                'seats'         => 0,       // set in Step 11
                'fractional'    => 0.0,     // set in Step 11
            ];
        }

        // ── Step 11: Webster (Sainte-Laguë) distribution across bins ────────
        // effectiveBudget = nonGiantBudget (true remaining budget after locking giants).
        // Constitutional floor (≥ floor per bin) applies only when the budget can support it.
        // When nonGiantBudget < floor × bins, distribute exactly what's available (floor_override=true).
        $totalBinPop     = array_sum(array_column($binData, 'pop'));
        $effectiveBudget = $nonGiantBudget;
        $binCount        = count($binData);
        $floorFeasible   = ($effectiveBudget >= $binCount * $floor);
        $startSeats      = $floorFeasible ? $floor : 1;
        $binQuota        = $totalBinPop / max($effectiveBudget, 1);

        foreach ($binData as &$b) {
            $b['fractional']     = $b['pop'] / max($binQuota, 1);
            $b['floor_override'] = $b['fractional'] < $floorBoundary;
            $b['seats']          = $startSeats;
        }
        unset($b);

        // Distribute remaining seats one-by-one using Webster priority (pop / (2s+1)).
        // When floor is not feasible, skip the floor_override gate so all budget is distributed.
        $remaining = $effectiveBudget - $startSeats * $binCount;
        for ($r = 0; $r < $remaining; $r++) {
            $bestIdx = -1; $bestPriority = -1.0;
            foreach ($binData as $i => $b) {
                if ($b['seats'] >= $ceiling) continue;
                if ($floorFeasible && $b['floor_override']) continue;
                $priority = $b['pop'] / (2 * $b['seats'] + 1);
                if ($priority > $bestPriority) { $bestPriority = $priority; $bestIdx = $i; }
            }
            if ($bestIdx >= 0) $binData[$bestIdx]['seats']++;
        }

        // ── Safety: exhaust any budget not placed by the main Webster loop ────────
        // Occurs when floor_override bins were skipped AND all other bins hit the ceiling,
        // leaving bestIdx=-1 for one or more rounds. Distribute leftover seats by Webster
        // priority without the floor_override gate — the constitutional ceiling is the
        // only hard limit. floor_override stays recorded on the district for audit purposes.
        $safeAssigned = array_sum(array_column($binData, 'seats'));
        $safeRemain   = $effectiveBudget - $safeAssigned;
        for ($r = 0; $r < $safeRemain; $r++) {
            $bestIdx = -1; $bestPri = -1.0;
            foreach ($binData as $i => $b) {
                if ($b['seats'] >= $ceiling) continue;   // constitutional max is still hard
                $priority = $b['pop'] / (2 * $b['seats'] + 1);
                if ($priority > $bestPri) { $bestPri = $priority; $bestIdx = $i; }
            }
            if ($bestIdx >= 0) $binData[$bestIdx]['seats']++;
            // If bestIdx is -1 here, ALL bins are at the constitutional ceiling and the
            // budget genuinely cannot be placed (only possible if budget > ceiling × binCount).
        }

        // ── Step 12: Insert districts ──────────────────────────────────────────
        // The district's `seats` value is the canonical seat budget for any
        // composite member at this scope. When a downstream caller needs a
        // member's locked seat budget, computeSeatBudget()'s Path 2 lookup
        // returns this district's `seats`. Giants (skipped here — Step 12
        // only inserts non-giant bins) take Path 3 and recompute their
        // budget via the parent cascade.
        $districtsCreated = 0;
        $totalDistricts   = count($binData);
        $this->publishMassProgress($legislature_id, [
            'phase'         => 'inserting',
            'phase_label'   => "Inserting {$totalDistricts} districts (computing geometry per district)",
            'phase_current' => 0,
            'phase_total'   => $totalDistricts,
        ]);
        foreach ($binData as $binIdx => $bin) {
            // Per-district progress so the operator can tell whether a slow
            // scope is stuck on geometry computation (Step 12, dominant cost)
            // versus stuck in the bin-balancing inner loops (earlier steps).
            $this->publishMassProgress($legislature_id, [
                'phase'         => 'geometry',
                'phase_label'   => sprintf(
                    'District %d of %d — %d members, %d seats — running ST_Union…',
                    $binIdx + 1, $totalDistricts, count($bin['jids']), $bin['seats'],
                ),
                'phase_current' => $binIdx + 1,
                'phase_total'   => $totalDistricts,
            ]);

            $distNumQ = DB::table('legislature_districts')
                ->where('legislature_id', $legislature_id)
                ->where('jurisdiction_id', $scopeId)
                ->whereNull('deleted_at');
            if ($mapId !== null) {
                $distNumQ->where('map_id', $mapId);
            }
            $districtNumber = 1 + (int) $distNumQ->max('district_number');

            $districtId = (string) \Illuminate\Support\Str::uuid();

            DB::table('legislature_districts')->insert([
                'id'               => $districtId,
                'legislature_id'   => $legislature_id,
                'map_id'           => $mapId,
                'jurisdiction_id'  => $scopeId,
                'district_number'  => $districtNumber,
                'seats'            => $bin['seats'],
                'fractional_seats' => $binQuota > 0 ? round($bin['pop'] / $binQuota, 6) : 0.0,
                'floor_override'   => $bin['floor_override'],
                'target_population'=> $bin['pop'],
                'actual_population'=> $bin['pop'],
                'status'           => 'active',
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            $memberships = array_map(fn($jid) => [
                'id'              => (string) \Illuminate\Support\Str::uuid(),
                'district_id'     => $districtId,
                'jurisdiction_id' => $jid,
            ], $bin['jids']);
            DB::table('legislature_district_jurisdictions')->insert($memberships);

            // Compute and cache spatial stats (convex_hull_ratio, num_geom_parts, is_contiguous)
            // so reseeded districts have stats immediately — same as manual create/update.
            // Pass $skipSeatsUpdate=true: Webster already assigned the correct seats at INSERT;
            // recomputeDistrict must not overwrite them with independent per-district rounding.
            $this->recomputeDistrict($districtId, $legislature_id, $leg, true);

            $districtsCreated++;
        }

        return ['districts_created' => $districtsCreated, 'error' => null];
    }

    /**
     * Compute the non-giant quota for a scope.
     *
     * When giants lock in integer seats (via computeSeatBudget() or round(frac)), the
     * remaining non-giant pool is apportioned over exactly (seatBudget − giantSeats) seats.
     * ngQuota = nonGiantPop / (seatBudget − giantSeats) guarantees SUM(non-giant fracs) is
     * exactly (seatBudget − giantSeats).  This mirrors what runAutoCompositeForScope() does
     * via $binQuota.  Returns $fullQuota unchanged when no giants are present.
     *
     * @param array  $allChildren     stdClass rows with ->population, ->fractional_seats
     *                                (full-quota), and optionally ->type_a_apportioned.
     * @param float  $fullQuota       effectivePop / seatBudget
     * @param int    $seatBudget      Total seat budget at this scope
     * @param int    $effectivePop    SUM(all direct children pops)
     * @param float  $giantThreshold  fractional ≥ this is a giant (ceiling + 0.5)
     * @param int    $floor           seat floor (composite minimum, e.g. 5)
     */
    public function computeNonGiantQuota(
        array $allChildren,
        float $fullQuota,
        int   $seatBudget,
        int   $effectivePop,
        float $giantThreshold,
        int   $floor
    ): float {
        $giantSeatsTotal = 0;
        $giantPopTotal   = 0;
        foreach ($allChildren as $c) {
            $frac = (float) ($c->fractional_seats ?? ((float) $c->population / max($fullQuota, 1)));
            if ($frac >= $giantThreshold) {
                $lockedSeats = isset($c->type_a_apportioned) && $c->type_a_apportioned !== null
                    ? (int) $c->type_a_apportioned
                    : max($floor, (int) round($frac));
                $giantSeatsTotal += $lockedSeats;
                $giantPopTotal   += (int) $c->population;
            }
        }
        if ($giantSeatsTotal === 0) return $fullQuota;
        $ngBudget = max($seatBudget - $giantSeatsTotal, 1);
        $ngPop    = max($effectivePop - $giantPopTotal, 1);
        return $ngPop / $ngBudget;
    }

    /**
     * Recompute seats + geometry for a district based on its current members.
     * If the district has no remaining members, soft-delete it.
     */
    public function recomputeDistrict(
        string $districtId,
        string $legislatureId,
        object $leg,
        bool   $skipSeatsUpdate = false  // true when called from auto-composite: preserve Webster seats
    ): void
    {
        ['giant' => $giantThreshold, 'floor' => $floorBoundary] = $this->thresholds($leg->jurisdiction_id);
        $floor   = ConstitutionalDefaults::floor($leg->jurisdiction_id);
        $ceiling = ConstitutionalDefaults::ceiling($leg->jurisdiction_id);

        $jids = DB::table('legislature_district_jurisdictions as ldj')
            ->where('ldj.district_id', $districtId)
            ->pluck('ldj.jurisdiction_id')
            ->toArray();

        if (empty($jids)) {
            DB::table('legislature_districts')
                ->where('id', $districtId)
                ->update(['deleted_at' => now()]);
            return;
        }

        $totalPop = (int) DB::table('jurisdictions')->whereIn('id', $jids)->sum('population');

        // Use local quota from the district's scope rather than the root quota.
        $districtRow = DB::table('legislature_districts')->where('id', $districtId)->first();
        $distScopeId = $districtRow ? $districtRow->jurisdiction_id : null;
        if ($distScopeId) {
            $scopeChildrenPop = (int) DB::table('jurisdictions')
                ->where('parent_id', $distScopeId)
                ->whereNull('deleted_at')
                ->sum('population');
            $distScopeRow = DB::table('jurisdictions')->where('id', $distScopeId)->whereNull('deleted_at')->first();
            $reRootPop    = max((int) DB::table('jurisdictions')->where('id', $leg->jurisdiction_id)->value('population'), 1);
            // Seat budget via the gated cascade. Falls back to proportional
            // approximation only in degenerate cases.
            $distSeatBudget = $this->computeSeatBudget($distScopeId, $legislatureId)
                ?? max($floor, (int) round((int) ($distScopeRow ? $distScopeRow->population : 0) * (int) $leg->type_a_seats / $reRootPop));
            $fullQuota = $scopeChildrenPop / max($distSeatBudget, 1);
            // Adjust to non-giant quota so stored fractional is comparable to
            // sibling fracs. `type_a_apportioned` here is the legacy property
            // name carried into computeNonGiantQuota() — populated via the
            // gated cascade.
            $distChildren = DB::table('jurisdictions')
                ->where('parent_id', $distScopeId)
                ->whereNull('deleted_at')
                ->get(['id', 'population']);
            $distChildStd = $distChildren->map(function ($c) use ($fullQuota, $legislatureId) {
                $obj = new \stdClass();
                $obj->population         = $c->population;
                $obj->fractional_seats   = (float) $c->population / max($fullQuota, 1);
                $obj->type_a_apportioned = $this->computeSeatBudget($c->id, $legislatureId);
                return $obj;
            })->all();
            $quota = $this->computeNonGiantQuota($distChildStd, $fullQuota, $distSeatBudget, $scopeChildrenPop, $giantThreshold, $floor);

            // Quota cap: when giants consume most of the seat budget, the remaining
            // non-giant pool may be < floor. effectiveFloor = min(floor, nonGiantBudget) so
            // that the constitutional floor yields to the quota cap (not the reverse).
            $giantSeatsForFloor = 0;
            foreach ($distChildStd as $c) {
                if ((float) $c->fractional_seats >= $giantThreshold) {
                    $giantSeatsForFloor += $c->type_a_apportioned !== null
                        ? (int) $c->type_a_apportioned
                        : max($floor, (int) round((float) $c->fractional_seats));
                }
            }
            $effectiveFloor = min($floor, max(1, $distSeatBudget - $giantSeatsForFloor));
        } else {
            $reRootPop = max((int) DB::table('jurisdictions')->where('id', $leg->jurisdiction_id)->value('population'), 1);
            $quota = $reRootPop / max((int) $leg->type_a_seats, 1);
            $effectiveFloor = $floor;   // no scope context — cannot determine quota cap
        }
        $fractional = $totalPop / max($quota, 1);
        $seats      = max($effectiveFloor, min($ceiling, (int) round($fractional)));
        $floorOverride = $seats < $floor;

        // Pre-compute spatial stats from member jurisdiction geometries.
        // Running per-district at write time (create/update) is fast — typically
        // 1–40 member jurisdictions per district.  This avoids the O(n) ST_Union
        // fan-out that timed out when computed for all 274 Earth districts on page load.
        // Compactness: convex hull ratio + centroid spread.
        // Both metrics are better suited than Polsby-Popper for admin-unit districting
        // because they do not penalise natural coastlines or water bodies.
        //
        // convex_hull_ratio = ST_Area(union) / ST_Area(ST_ConvexHull(union))  [0–1, higher=better]
        // Union first so shared borders cancel cleanly before deriving metrics.
        // Two-tier conditional simplify on huge geoms — same pattern as the
        // adjacency queries. ST_Union over multiple multipart polygons is
        // super-linear in total vertex count, so a Canadian district holding
        // Nunavut (5.4M verts) + Ontario (3.7M) + Quebec (2.2M) at raw
        // resolution can spend 5-10 min in this one call. Simplified inputs
        // make Union seconds. Compactness (area-ratio) and component count
        // are insensitive to ~110m boundary precision; the metric is robust.
        $jidPlaceholders = implode(',', array_fill(0, count($jids), '?'));
        $spatialRow = DB::selectOne("
            WITH g AS (
                SELECT CASE
                           WHEN ST_NPoints(geom) > 1000000
                                THEN ST_MakeValid(ST_Simplify(geom, 0.01))
                           WHEN ST_NPoints(geom) > 50000
                                THEN ST_MakeValid(ST_Simplify(geom, 0.001))
                           ELSE ST_MakeValid(geom)
                       END AS geom
                FROM jurisdictions
                WHERE id IN ({$jidPlaceholders})
                  AND geom IS NOT NULL AND deleted_at IS NULL
            ),
            union_cte AS (
                SELECT ST_MakeValid(ST_Union(g.geom)) AS geom FROM g
            )
            SELECT
                ST_Area(geom) / NULLIF(ST_Area(ST_ConvexHull(geom)), 0) AS convex_hull_ratio,
                ST_NumGeometries(geom)                                   AS num_geom_parts
            FROM union_cte
        ", $jids);

        // Contiguity: graph connectivity check via ST_Intersects adjacency + BFS.
        // Single-member districts are always contiguous by definition — their
        // internal island geography (Michigan UP, Hawaiian islands, etc.) is irrelevant.
        //
        // Multi-member districts: two members are "adjacent" if their geometries
        // actually intersect (share at least one point — i.e., a real land border).
        // We use the GiST index bbox operator && as a fast pre-filter, then confirm
        // with ST_Intersects.  This prevents coastal jurisdictions separated by water
        // (harbors, straits, bays) from being falsely declared adjacent; the old
        // approach used ST_Expand(geom, 1.35) which created ~150 km false adjacencies.
        //
        // BFS from the first member; if all N members are reachable the district is
        // contiguous.  FALSE means ≥1 member is isolated (not reachable via real borders).
        if (count($jids) <= 1) {
            $isContiguous = true;
        } else {
            // Two-tier conditional simplify — same pattern as Step 7. Tier 1
            // (>1M verts) at 0.01° (~1.1km) for Nunavut-class outliers; Tier 2
            // (>50k verts) at 0.001° (~110m) for normal large geoms.
            // ST_MakeValid handles self-intersections that simplification can
            // introduce on complex coastlines.
            $jidPh = implode(',', array_fill(0, count($jids), '?'));
            $adjPairs = DB::select("
                WITH g AS (
                    SELECT id,
                           CASE
                               WHEN ST_NPoints(geom) > 1000000
                                    THEN ST_MakeValid(ST_Simplify(geom, 0.01))
                               WHEN ST_NPoints(geom) > 50000
                                    THEN ST_MakeValid(ST_Simplify(geom, 0.001))
                               ELSE geom
                           END AS geom
                    FROM jurisdictions
                    WHERE id IN ({$jidPh})
                      AND geom IS NOT NULL
                      AND deleted_at IS NULL
                )
                SELECT a.id AS a_id, b.id AS b_id
                FROM g a
                JOIN g b ON b.id > a.id
                    AND a.geom && b.geom
                    AND ST_Intersects(a.geom, b.geom)
            ", $jids);

            $adj       = [];
            $adjCounts = [];
            foreach ($adjPairs as $p) {
                $adj[$p->a_id][] = $p->b_id;
                $adj[$p->b_id][] = $p->a_id;
                $adjCounts[$p->a_id] = ($adjCounts[$p->a_id] ?? 0) + 1;
                $adjCounts[$p->b_id] = ($adjCounts[$p->b_id] ?? 0) + 1;
            }

            // Start BFS from the most-connected member (highest adjacency count).
            // This prevents the case where $jids[0] is a geographic island with zero
            // land borders (e.g. Nanaoxian in Guangzhou Province): if BFS starts at
            // the island it visits only 1 node, wrongly orphaning all mainland members
            // and causing the island-exemption loop to check mainland nodes (which all
            // have sibling borders), so the exemption never fires.
            // Starting from the most-connected node guarantees BFS finds the largest
            // mainland cluster first, leaving only true islands as orphans.
            $startNode = $jids[0];
            if (!empty($adjCounts)) {
                arsort($adjCounts);
                $startNode = (string) array_key_first($adjCounts);
            }

            $visited = [];
            $queue   = [$startNode];
            while (!empty($queue)) {
                $node = array_shift($queue);
                if (isset($visited[$node])) continue;
                $visited[$node] = true;
                foreach ($adj[$node] ?? [] as $nb) {
                    if (!isset($visited[$nb])) $queue[] = $nb;
                }
            }
            $isContiguous = count($visited) === count($jids);

            // If non-contiguous, check whether contiguity was even achievable.
            // Island jurisdictions (Hawaii, Puerto Rico, Guam…) can never be made
            // contiguous with mainland members — no map drawing can fix it.
            //
            // For each orphaned (BFS-unreachable) member, ask: does it share any
            // land border with ANY sibling jurisdiction (same parent_id)?
            // The GiST bbox pre-filter makes this near-instant for true islands —
            // Hawaii's bbox has zero overlap with any other US state → 0 candidates.
            // If ANY orphaned member has no sibling border at all, the non-contiguity
            // is geographic/unavoidable → override to contiguous (no flag).
            if (!$isContiguous) {
                $orphanedJids = array_values(array_filter($jids, fn($j) => !isset($visited[$j])));
                foreach ($orphanedJids as $oj) {
                    // Ask: does this orphaned member share any spatial border with
                    // any sibling (same parent_id) jurisdiction at all?
                    // Uses ST_Intersects (not ST_Touches or ST_Dimension) because:
                    //   • Simplified geoBoundaries polygons sometimes share only a vertex
                    //     (dim=0) rather than a full edge; ST_Intersects still returns TRUE.
                    //   • The BFS start-node fix (most-connected node) is what correctly
                    //     orphans true islands. Once Nanaoxian-style islands ARE orphaned,
                    //     they have NO bbox-overlapping siblings at all → this query returns
                    //     nothing → $hasSiblingBorder = null → exemption fires correctly.
                    //   • ST_Intersects only fails for containment artifacts (coastal polygon
                    //     geometrically containing an island), but those islands have no bbox
                    //     overlap with any sibling anyway, so this query never reaches them.
                    $hasSiblingBorder = DB::selectOne("
                        SELECT 1
                        FROM jurisdictions a
                        JOIN jurisdictions b
                            ON b.parent_id = a.parent_id
                            AND b.id != a.id
                            AND b.deleted_at IS NULL
                            AND b.geom IS NOT NULL
                            AND a.geom && b.geom
                            AND ST_Intersects(a.geom, b.geom)
                        WHERE a.id = ?
                          AND a.deleted_at IS NULL
                        LIMIT 1
                    ", [$oj]);
                    if (!$hasSiblingBorder) {
                        $isContiguous = true;
                        break;
                    }
                }
            }
        }

        // No geometry stored on the district record itself —
        // the revealed layer renders member jurisdiction polygons directly.
        //
        // When $skipSeatsUpdate is true (called from auto-composite), Webster already assigned
        // the correct seats at INSERT time — do NOT overwrite with per-district rounding, which
        // can diverge from the guaranteed-total Webster result (e.g. frac 7.49 rounds to 7 but
        // Webster gave 8 to balance the scope total).  Only spatial stats are refreshed.
        // NOTE: polsby_popper column was dropped by migration
        // 2026_04_23_000003_drop_unused_district_and_jurisdiction_columns —
        // superseded by convex_hull_ratio. Do NOT add it back here.
        $distUpdate = [
            'actual_population' => $totalPop,
            'num_geom_parts'    => $spatialRow?->num_geom_parts !== null ? (int) $spatialRow->num_geom_parts : null,
            'convex_hull_ratio' => $spatialRow?->convex_hull_ratio !== null ? round((float) $spatialRow->convex_hull_ratio, 6) : null,
            'is_contiguous'     => $isContiguous,
            'updated_at'        => now(),
        ];
        if (!$skipSeatsUpdate) {
            $distUpdate['seats']            = $seats;
            $distUpdate['fractional_seats'] = $fractional;
            $distUpdate['floor_override']   = $floorOverride;
        }
        DB::table('legislature_districts')
            ->where('id', $districtId)
            ->update($distUpdate);

        // Flush all revealed GeoJSON caches for this legislature.
        // The broad tag "revealed.{$legislatureId}" was added to every revealedGeoJson()
        // cache entry, so one flush here invalidates every scope × map × zoom combination.
        Cache::tags(["revealed.{$legislatureId}"])->flush();
    }

    /**
     * Build k seeds via greedy farthest-point from a given first seed.
     * Seeds 2..k are chosen iteratively as the jurisdiction whose minimum distance
     * to any already-chosen seed is maximised (maximises inter-seed spread).
     * Used by the Phase-A exhaustive scan to generate diverse BFS starting configurations
     * from every possible first seed.
     */
    private function farPointSeeds(string $firstSeed, int $k, array $jids, array $centroids): array
    {
        $seeds   = [$firstSeed];
        $seedSet = [$firstSeed => true];
        while (count($seeds) < $k) {
            $farthest   = null;
            $maxMinDist = -1.0;
            foreach ($jids as $jid) {
                if (isset($seedSet[$jid])) continue;
                $minDist = PHP_FLOAT_MAX;
                foreach ($seeds as $seed) {
                    $dx = ($centroids[$jid]['x'] ?? 0.0) - ($centroids[$seed]['x'] ?? 0.0);
                    $dy = ($centroids[$jid]['y'] ?? 0.0) - ($centroids[$seed]['y'] ?? 0.0);
                    $d  = $dx * $dx + $dy * $dy;
                    if ($d < $minDist) $minDist = $d;
                }
                if ($minDist > $maxMinDist) { $maxMinDist = $minDist; $farthest = $jid; }
            }
            if ($farthest === null) break;
            $seeds[]            = $farthest;
            $seedSet[$farthest] = true;
        }
        return $seeds;
    }

    /**
     * Partition a connected component into geographically compact, contiguous districts
     * using distance-filtered BFS expansion from pre-selected starting jurisdictions.
     *
     * The adjacency table may contain false-positive long-distance edges (data artefacts).
     * All adjacency traversals in this function filter out edges longer than 4× the
     * 90th-percentile edge length for the component.  This prevents false edges from
     * pulling distant jids into a bin during BFS (root cause of geometric non-contiguity)
     * and from fooling the per-swap contiguity guard or post-swap full-revert check.
     *
     * The multi-attempt loop in runAutoCompositeForScope() calls this 3× (one per
     * seed strategy) per k value and keeps the best-scoring configuration.
     *
     * Algorithm:
     *  1. Distance threshold: compute p90 × 16 of adjacency edge lengths for the component
     *  2. BFS expansion (distance-filtered): round-robin from k seeds
     *  3. Isolated-jid assignment: adjacency-aware, distance-filtered, standalone fallback
     *  4. Population balance swaps: border transfers that strictly reduce avg deviation
     *  5. Post-swap contiguity validation: full revert if any swap broke contiguity
     *  6. Post-repair merge: merge undersized bins (< 5.0 frac) into adjacent absorbers
     *
     * @param  array $seeds    Pre-computed seed jurisdiction IDs (one per desired district)
     * @param  array $jids     Jurisdiction IDs in this component
     * @param  array $childById Jurisdiction data keyed by ID (population, fractional_seats, …)
     * @param  array $adj      Adjacency map [jid => [neighbor_jid, …]]
     * @param  array $centroids ['x' => lon, 'y' => lat] keyed by jurisdiction ID
     * @return array           Array of bins; each bin = array of jurisdiction IDs
     */
    private function geographicSeedExpansion(
        array $jids,
        array $childById,
        array $adj,
        array $centroids,
        array $seeds,
        float $giantThreshold,
        float $floorBoundary,
        bool  $bfsOnly = false  // when true: return after BFS expansion, skip balance/compact passes
    ): array {
        // Pre-compute the "BFS full" threshold (slightly below giant) used to gate
        // expansion. With default 5/9 this is 9.49 (giant=9.5 minus epsilon).
        $bfsFullThreshold = $giantThreshold - 0.01;
        if (empty($jids)) return [];

        // Degenerate: caller provided no seeds — return everything as one bin
        $k = count($seeds);
        if ($k < 1) return [$jids];

        $jidSet    = array_flip($jids); // O(1) membership test
        $totalPop  = array_sum(array_map(fn($jid) => (float) $childById[$jid]->population, $jids));
        $targetPop = $totalPop / $k;

        // ── Distance filter — computed once, used throughout all phases ──────────────
        // BFS expansion is the root cause of non-contiguous districts: when the adjacency
        // table contains a false-positive long-distance edge, BFS traverses it during
        // expansion and pulls a geographically distant jid into a bin.  The resulting bin
        // looks contiguous in the adjacency graph (reachable via the false edge) but is
        // geometrically non-contiguous.
        //
        // Fix: compute the 90th-percentile squared distance of all adjacency edges in this
        // component, multiply by 16 (= 4²).  Any edge longer than 4× the "typical longest
        // real edge" is ignored in BFS queuing, isolated-jid lookup, swap guards, and the
        // post-swap full-revert check.
        $adjDistsSq = [];
        foreach ($jids as $jid) {
            foreach ($adj[$jid] ?? [] as $nb) {
                if (!isset($jidSet[$nb])) continue;
                $dx = ($centroids[$jid]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                $dy = ($centroids[$jid]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                $adjDistsSq[] = $dx * $dx + $dy * $dy;
            }
        }
        sort($adjDistsSq);
        $p90Idx        = max(0, (int) floor(count($adjDistsSq) * 0.90) - 1);
        $maxEdgeDistSq = !empty($adjDistsSq) ? $adjDistsSq[$p90Idx] * 16.0 : PHP_FLOAT_MAX;

        // --- Initialize BFS bins ---
        $bins     = array_fill(0, $k, []);
        $binPops  = array_fill(0, $k, 0.0);
        $binFracs = array_fill(0, $k, 0.0);
        $assigned = [];
        $queues   = array_fill(0, $k, []);

        foreach ($seeds as $i => $seed) {
            $bins[$i][]      = $seed;
            $binPops[$i]     = (float) $childById[$seed]->population;
            $binFracs[$i]    = (float) $childById[$seed]->fractional_seats;
            $assigned[$seed] = $i;
            foreach ($adj[$seed] ?? [] as $n) {
                if (!isset($jidSet[$n]) || isset($assigned[$n])) continue;
                $dx = ($centroids[$seed]['x'] ?? 0.0) - ($centroids[$n]['x'] ?? 0.0);
                $dy = ($centroids[$seed]['y'] ?? 0.0) - ($centroids[$n]['y'] ?? 0.0);
                if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                $queues[$i][] = $n;
            }
        }

        // --- BFS round-robin expansion (distance-filtered) ---
        // A bin is "full" when it exceeds the population target OR is at/near the 9.5
        // fractional cap.  Each iteration each bin BFS-grows by one adjacent jurisdiction.
        // Edges longer than $maxEdgeDistSq are skipped to prevent false-positive entries
        // from assigning geographically distant jids to the same bin.
        $maxIter = count($jids) * $k * 3;
        for ($iter = 0; $iter < $maxIter; $iter++) {
            $anyProgress = false;

            for ($i = 0; $i < $k; $i++) {
                $popFull  = $binPops[$i]  >= $targetPop  * 1.1;
                $fracFull = $binFracs[$i] >= $bfsFullThreshold;
                $binFull  = $popFull || $fracFull;

                $activeBins = 0;
                for ($j = 0; $j < $k; $j++) {
                    if ($binPops[$j] < $targetPop * 1.1 && $binFracs[$j] < $bfsFullThreshold) $activeBins++;
                }

                if ($binFull && $activeBins > 0) continue;

                while (!empty($queues[$i])) {
                    $next = array_shift($queues[$i]);
                    if (isset($assigned[$next]) || !isset($jidSet[$next])) continue;

                    $nextFrac = (float) $childById[$next]->fractional_seats;

                    if ($binFracs[$i] + $nextFrac >= $giantThreshold) {
                        foreach ($adj[$next] ?? [] as $nbOfNext) {
                            if (!isset($assigned[$nbOfNext])) continue;
                            $adjJ = $assigned[$nbOfNext];
                            if ($adjJ !== $i && $binFracs[$adjJ] + $nextFrac < $giantThreshold) {
                                $queues[$adjJ][] = $next;
                            }
                        }
                        continue;
                    }

                    $bins[$i][]      = $next;
                    $binPops[$i]    += (float) $childById[$next]->population;
                    $binFracs[$i]   += $nextFrac;
                    $assigned[$next]  = $i;

                    foreach ($adj[$next] ?? [] as $n) {
                        if (!isset($jidSet[$n]) || isset($assigned[$n])) continue;
                        $dx = ($centroids[$next]['x'] ?? 0.0) - ($centroids[$n]['x'] ?? 0.0);
                        $dy = ($centroids[$next]['y'] ?? 0.0) - ($centroids[$n]['y'] ?? 0.0);
                        if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                        $queues[$i][] = $n;
                    }
                    $anyProgress = true;
                    break;
                }
            }

            if (!$anyProgress) break;
        }

        // --- Assign isolated jurisdictions ---
        // Jids not reached by BFS (their only adjacency paths exceeded the distance
        // threshold, or all neighbouring bins were full).  Distance filter applied here
        // too — prevents the same false edges from pulling them into non-adjacent bins.
        foreach ($jids as $jid) {
            if (isset($assigned[$jid])) continue;

            $jFrac      = (float) $childById[$jid]->fractional_seats;
            $nearestBin = -1;
            $minDist    = PHP_FLOAT_MAX;

            $adjacentBins = [];
            foreach ($adj[$jid] ?? [] as $neighbor) {
                if (!isset($assigned[$neighbor])) continue;
                $dx = ($centroids[$jid]['x'] ?? 0.0) - ($centroids[$neighbor]['x'] ?? 0.0);
                $dy = ($centroids[$jid]['y'] ?? 0.0) - ($centroids[$neighbor]['y'] ?? 0.0);
                if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                $adjacentBins[$assigned[$neighbor]] = true;
            }

            if (!empty($adjacentBins)) {
                foreach (array_keys($adjacentBins) as $i) {
                    if (!isset($binFracs[$i])) continue;
                    if ($binFracs[$i] + $jFrac >= $giantThreshold) continue;
                    $iCenter = $this->binCentroid($bins[$i], $centroids);
                    $dx = ($centroids[$jid]['x'] ?? 0.0) - $iCenter['x'];
                    $dy = ($centroids[$jid]['y'] ?? 0.0) - $iCenter['y'];
                    $d  = $dx * $dx + $dy * $dy;
                    if ($d < $minDist) { $minDist = $d; $nearestBin = $i; }
                }
            } else {
                // No real adjacency or all filtered — nearest centroid fallback
                foreach (range(0, $k - 1) as $i) {
                    if (!isset($binFracs[$i])) continue;
                    if ($binFracs[$i] + $jFrac >= $giantThreshold) continue;
                    $iCenter = $this->binCentroid($bins[$i], $centroids);
                    $dx = ($centroids[$jid]['x'] ?? 0.0) - $iCenter['x'];
                    $dy = ($centroids[$jid]['y'] ?? 0.0) - $iCenter['y'];
                    $d  = $dx * $dx + $dy * $dy;
                    if ($d < $minDist) { $minDist = $d; $nearestBin = $i; }
                }
            }

            if ($nearestBin >= 0) {
                $bins[$nearestBin][] = $jid;
                $binFracs[$nearestBin] += $jFrac;
                $assigned[$jid]       = $nearestBin;
            } else {
                $bins[]     = [$jid];
                $binFracs[] = $jFrac;
                $k++;
                $assigned[$jid] = $k - 1;
            }
        }

        // --- Border swap refinement: improve population balance after BFS ---
        // Iteratively moves border jurisdictions between adjacent bins to minimise
        // population imbalance (sum of |binPop − targetPop| across all bins).
        // Each move must: (a) strictly reduce total imbalance, (b) keep the donor bin
        // at ≥ 5.0 fractional (constitutional floor), (c) keep the receiver bin
        // below 9.5 fractional (constitutional ceiling).
        //
        // The per-swap BFS contiguity guard and the post-swap full-revert both use
        // $maxEdgeDistSq (computed before BFS above) so false-positive edges cannot
        // trick them into allowing or missing a contiguity break.
        //
        // Runs at most count($jids) improvements (typically converges in much fewer).

        // Recompute $binPops — the isolated-jid section above does not update it.
        $binPops = array_map(
            fn($b) => (float) array_sum(array_map(fn($jid) => (float) $childById[$jid]->population, $b)),
            $bins
        );

        // BFS-only mode: return raw partition without running balance/compact passes.
        // Used by the Phase A exhaustive-seed scan in runAutoCompositeForScope() to
        // cheaply evaluate all N first-seed candidates before committing to the full pipeline.
        if ($bfsOnly) {
            return array_values(array_filter($bins, fn($b) => !empty($b)));
        }

        // Save pre-swap state so we can fully revert if the post-swap validation
        // detects that swaps created non-contiguous bins despite the per-swap guard.
        $preSwapBins  = array_map(fn($b) => array_values($b), $bins);
        $preSwapFracs = $binFracs;

        // Per-bin moment-of-inertia statistics for O(1) incremental Rg² computation.
        // Identity: Rg²_i = (Sx2_i + Sy2_i)/M_i − (Sx_i²+Sy_i²)/M_i²
        // Updated in O(1) after every swap by adding/subtracting the moved jid's contribution.
        $binSx  = array_fill(0, $k, 0.0); // sum(pop × lon)
        $binSy  = array_fill(0, $k, 0.0); // sum(pop × lat)
        $binSx2 = array_fill(0, $k, 0.0); // sum(pop × lon²)
        $binSy2 = array_fill(0, $k, 0.0); // sum(pop × lat²)
        for ($i = 0; $i < $k; $i++) {
            foreach ($bins[$i] as $sid) {
                $sp  = (float) $childById[$sid]->population;
                $sx  = $centroids[$sid]['x'] ?? 0.0;
                $sy  = $centroids[$sid]['y'] ?? 0.0;
                $binSx[$i]  += $sp * $sx;
                $binSy[$i]  += $sp * $sy;
                $binSx2[$i] += $sp * $sx * $sx;
                $binSy2[$i] += $sp * $sy * $sy;
            }
        }

        // --- Balance swap refinement: "best improvement" minimax steepest-descent ---
        // Each pass scans border-jid candidates and applies the single swap that most reduces
        // the MAXIMUM per-district deviation (Chebyshev / minimax norm), directly targeting
        // the user's goal of sub-2% deviation per district rather than sub-2% on average.
        //
        // Fast-skip: only swaps involving the current max-deviation bin can reduce the max.
        // New max is recomputed in O(k) per candidate (k ≤ 12 — negligible cost).
        // Constitutional constraints: donor bin stays ≥ 5.0 frac; receiver stays < 9.5 frac.
        $swapIter = 0;
        $swapMax  = count($jids) * 3;
        do {
            // Precompute current maximum deviation — once per iteration, O(k)
            $currentMaxDev = 0.0;
            foreach ($binPops as $bp) {
                $d = abs($bp - $targetPop);
                if ($d > $currentMaxDev) $currentMaxDev = $d;
            }

            $bestImprovement = 0.0;
            $bestBI = -1; $bestBJ = -1;
            $bestBJid = null; $bestBRemainingI = null;
            $bestBJPop = 0.0; $bestBJFrac = 0.0;

            for ($i = 0; $i < $k; $i++) {
                if (empty($bins[$i])) continue;
                foreach ($bins[$i] as $jid) {
                    $jFrac = (float) $childById[$jid]->fractional_seats;
                    if ($binFracs[$i] - $jFrac < $floorBoundary) continue; // donor floor

                    $adjBins = [];
                    foreach ($adj[$jid] ?? [] as $nb) {
                        if (isset($assigned[$nb]) && $assigned[$nb] !== $i) {
                            $adjBins[$assigned[$nb]] = true;
                        }
                    }
                    if (empty($adjBins)) continue;

                    $jPop = (float) $childById[$jid]->population;
                    foreach (array_keys($adjBins) as $j) {
                        if ($binFracs[$j] + $jFrac >= $giantThreshold) continue;

                        // Fast skip: if neither bin i nor bin j holds the current max deviation,
                        // no swap between them can reduce the maximum.
                        $devI = abs($binPops[$i] - $targetPop);
                        $devJ = abs($binPops[$j] - $targetPop);
                        if ($devI < $currentMaxDev && $devJ < $currentMaxDev) continue;

                        // Compute new maximum if this swap were applied — O(k)
                        $newMaxDev = 0.0;
                        foreach ($binPops as $bi => $bp) {
                            $newPop = $bp;
                            if ($bi === $i) $newPop -= $jPop;
                            if ($bi === $j) $newPop += $jPop;
                            $d = abs($newPop - $targetPop);
                            if ($d > $newMaxDev) $newMaxDev = $d;
                        }
                        $improvement = $currentMaxDev - $newMaxDev;
                        if ($improvement <= $bestImprovement) continue; // not the global best yet

                        // Contiguity guard — only run BFS for genuinely better candidates
                        $remainingI = array_values(array_filter($bins[$i], fn($x) => $x !== $jid));
                        if (count($remainingI) >= 2) {
                            $remSet = array_flip($remainingI);
                            $vis    = [$remainingI[0] => true];
                            $bfsQ   = [$remainingI[0]];
                            while (!empty($bfsQ)) {
                                $cur = array_shift($bfsQ);
                                foreach ($adj[$cur] ?? [] as $nb) {
                                    if (!isset($remSet[$nb]) || isset($vis[$nb])) continue;
                                    $dx = ($centroids[$cur]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                                    $dy = ($centroids[$cur]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                                    if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                                    $vis[$nb] = true;
                                    $bfsQ[]   = $nb;
                                }
                            }
                            if (count($vis) < count($remainingI)) continue;
                        }

                        $bestImprovement  = $improvement;
                        $bestBI = $i; $bestBJ = $j; $bestBJid = $jid;
                        $bestBRemainingI  = $remainingI;
                        $bestBJPop = $jPop; $bestBJFrac = $jFrac;
                    }
                }
            }

            $swapMade = false;
            if ($bestBI >= 0) {
                $bx = $centroids[$bestBJid]['x'] ?? 0.0;
                $by = $centroids[$bestBJid]['y'] ?? 0.0;
                $bins[$bestBJ][]     = $bestBJid;
                $binPops[$bestBJ]   += $bestBJPop;
                $binFracs[$bestBJ]  += $bestBJFrac;
                $binSx[$bestBJ]     += $bestBJPop * $bx;
                $binSy[$bestBJ]     += $bestBJPop * $by;
                $binSx2[$bestBJ]    += $bestBJPop * $bx * $bx;
                $binSy2[$bestBJ]    += $bestBJPop * $by * $by;
                $bins[$bestBI]       = $bestBRemainingI;
                $binPops[$bestBI]   -= $bestBJPop;
                $binFracs[$bestBI]  -= $bestBJFrac;
                $binSx[$bestBI]     -= $bestBJPop * $bx;
                $binSy[$bestBI]     -= $bestBJPop * $by;
                $binSx2[$bestBI]    -= $bestBJPop * $bx * $bx;
                $binSy2[$bestBI]    -= $bestBJPop * $by * $by;
                $assigned[$bestBJid] = $bestBJ;
                $swapMade = true;
            }
            $swapIter++;
        } while ($swapMade && $swapIter < $swapMax);

        // --- Compactness refinement pass ---
        // After population balance converges, reshape bins toward compact forms by moving
        // border jids that reduce the sum of per-bin radius-of-gyration² (Rg²) across the
        // two affected bins, subject to:
        //   (a) constitutional floor/ceiling (≥5.0 frac donor; <9.5 frac receiver),
        //   (b) neither bin's population deviation worsens by more than $compactTol %pts,
        //   (c) donor bin remains contiguous after the move.
        // Uses the O(1) per-bin Sx/Sy/Sx2/Sy2 statistics maintained above.
        // Rg² formula: (Sx2+Sy2)/M − Sx²/M² − Sy²/M²   (in geographic degree² units)
        $compactTol  = 0.025; // absolute cap (2.5%) — leaves margin for post-compact minimax pass to reach sub-2%
        $compactIter = 0;
        $compactMax  = count($jids) * 2;
        do {
            $bestCGain = 0.0;
            $bestCI = -1; $bestCJ = -1;
            $bestCJid = null; $bestCRemainingI = null;
            $bestCJPop = 0.0; $bestCJFrac = 0.0;

            for ($i = 0; $i < $k; $i++) {
                if (count($bins[$i]) <= 1) continue;

                $iM  = $binPops[$i];
                $iRg = $iM > 0
                    ? ($binSx2[$i] + $binSy2[$i]) / $iM
                      - ($binSx[$i] * $binSx[$i] + $binSy[$i] * $binSy[$i]) / ($iM * $iM)
                    : 0.0;

                foreach ($bins[$i] as $jid) {
                    $jFrac = (float) $childById[$jid]->fractional_seats;
                    if ($binFracs[$i] - $jFrac < $floorBoundary) continue;

                    $jPop = (float) $childById[$jid]->population;
                    $jx   = $centroids[$jid]['x'] ?? 0.0;
                    $jy   = $centroids[$jid]['y'] ?? 0.0;

                    // Absolute deviation cap for donor bin i after removal.
                    // Using an absolute cap (not a per-swap delta) prevents successive compactness
                    // swaps from accumulating large deviations in a single bin.
                    $newIM = $iM - $jPop;
                    if ($newIM <= 0) continue;
                    $devIAfter = abs($newIM - $targetPop) / max($targetPop, 1.0);
                    if ($devIAfter > $compactTol) continue;

                    // Incremental Rg² for bin i after removing jid (O(1) via statistics)
                    $nISx  = $binSx[$i]  - $jPop * $jx;
                    $nISy  = $binSy[$i]  - $jPop * $jy;
                    $nISx2 = $binSx2[$i] - $jPop * $jx * $jx;
                    $nISy2 = $binSy2[$i] - $jPop * $jy * $jy;
                    $nIRg  = ($nISx2 + $nISy2) / $newIM
                           - ($nISx * $nISx + $nISy * $nISy) / ($newIM * $newIM);

                    $adjBins = [];
                    foreach ($adj[$jid] ?? [] as $nb) {
                        if (isset($assigned[$nb]) && $assigned[$nb] !== $i) {
                            $adjBins[$assigned[$nb]] = true;
                        }
                    }
                    if (empty($adjBins)) continue;

                    foreach (array_keys($adjBins) as $j) {
                        if ($binFracs[$j] + $jFrac >= $giantThreshold) continue;

                        $jM  = $binPops[$j];
                        $jRg = $jM > 0
                            ? ($binSx2[$j] + $binSy2[$j]) / $jM
                              - ($binSx[$j] * $binSx[$j] + $binSy[$j] * $binSy[$j]) / ($jM * $jM)
                            : 0.0;

                        // Absolute deviation cap for receiver bin j after addition.
                        $newJM = $jM + $jPop;
                        $devJAfter = abs($newJM - $targetPop) / max($targetPop, 1.0);
                        if ($devJAfter > $compactTol) continue;

                        // Incremental Rg² for bin j after adding jid (O(1))
                        $nJSx  = $binSx[$j]  + $jPop * $jx;
                        $nJSy  = $binSy[$j]  + $jPop * $jy;
                        $nJSx2 = $binSx2[$j] + $jPop * $jx * $jx;
                        $nJSy2 = $binSy2[$j] + $jPop * $jy * $jy;
                        $nJRg  = ($nJSx2 + $nJSy2) / $newJM
                               - ($nJSx * $nJSx + $nJSy * $nJSy) / ($newJM * $newJM);

                        $cGain = ($iRg + $jRg) - ($nIRg + $nJRg); // positive = more compact
                        if ($cGain <= $bestCGain) continue;

                        // Contiguity guard for donor bin i
                        $remainingI = array_values(array_filter($bins[$i], fn($x) => $x !== $jid));
                        if (count($remainingI) >= 2) {
                            $remSet = array_flip($remainingI);
                            $vis    = [$remainingI[0] => true];
                            $bfsQ   = [$remainingI[0]];
                            while (!empty($bfsQ)) {
                                $cur = array_shift($bfsQ);
                                foreach ($adj[$cur] ?? [] as $nb) {
                                    if (!isset($remSet[$nb]) || isset($vis[$nb])) continue;
                                    $dx = ($centroids[$cur]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                                    $dy = ($centroids[$cur]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                                    if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                                    $vis[$nb] = true; $bfsQ[] = $nb;
                                }
                            }
                            if (count($vis) < count($remainingI)) continue;
                        }

                        $bestCGain = $cGain;
                        $bestCI = $i; $bestCJ = $j; $bestCJid = $jid;
                        $bestCRemainingI = $remainingI;
                        $bestCJPop = $jPop; $bestCJFrac = $jFrac;
                    }
                }
            }

            $compactSwapMade = false;
            if ($bestCI >= 0) {
                $cx = $centroids[$bestCJid]['x'] ?? 0.0;
                $cy = $centroids[$bestCJid]['y'] ?? 0.0;
                $bins[$bestCJ][]     = $bestCJid;
                $binPops[$bestCJ]   += $bestCJPop;
                $binFracs[$bestCJ]  += $bestCJFrac;
                $binSx[$bestCJ]     += $bestCJPop * $cx;
                $binSy[$bestCJ]     += $bestCJPop * $cy;
                $binSx2[$bestCJ]    += $bestCJPop * $cx * $cx;
                $binSy2[$bestCJ]    += $bestCJPop * $cy * $cy;
                $bins[$bestCI]       = $bestCRemainingI;
                $binPops[$bestCI]   -= $bestCJPop;
                $binFracs[$bestCI]  -= $bestCJFrac;
                $binSx[$bestCI]     -= $bestCJPop * $cx;
                $binSy[$bestCI]     -= $bestCJPop * $cy;
                $binSx2[$bestCI]    -= $bestCJPop * $cx * $cx;
                $binSy2[$bestCI]    -= $bestCJPop * $cy * $cy;
                $assigned[$bestCJid] = $bestCJ;
                $compactSwapMade = true;
            }
            $compactIter++;
        } while ($compactSwapMade && $compactIter < $compactMax);

        // --- Post-compact balance pass (minimax) ---
        // Re-optimises population equality after the compactness reshaping phase.
        // Uses the same minimax (Chebyshev) objective as the initial balance pass:
        // minimises max|dev| rather than sum|dev|, directly targeting sub-2% per district.
        // The $binSx/Sy/Sx2/Sy2 statistics are already up-to-date from the compact phase.
        $swapIter2 = 0;
        $swapMax2  = count($jids) * 2;
        do {
            // Precompute current maximum deviation — once per iteration, O(k)
            $currentMaxDev2 = 0.0;
            foreach ($binPops as $bp) {
                $d = abs($bp - $targetPop);
                if ($d > $currentMaxDev2) $currentMaxDev2 = $d;
            }

            $bestImprovement2  = 0.0;
            $bestBI2 = -1; $bestBJ2 = -1;
            $bestBJid2 = null; $bestBRemainingI2 = null;
            $bestBJPop2 = 0.0; $bestBJFrac2 = 0.0;

            for ($i = 0; $i < $k; $i++) {
                if (empty($bins[$i])) continue;
                foreach ($bins[$i] as $jid) {
                    $jFrac = (float) $childById[$jid]->fractional_seats;
                    if ($binFracs[$i] - $jFrac < $floorBoundary) continue;

                    $adjBins = [];
                    foreach ($adj[$jid] ?? [] as $nb) {
                        if (isset($assigned[$nb]) && $assigned[$nb] !== $i) {
                            $adjBins[$assigned[$nb]] = true;
                        }
                    }
                    if (empty($adjBins)) continue;

                    $jPop = (float) $childById[$jid]->population;
                    foreach (array_keys($adjBins) as $j) {
                        if ($binFracs[$j] + $jFrac >= $giantThreshold) continue;

                        // Fast skip: neither bin involved = cannot reduce the max
                        $devI2 = abs($binPops[$i] - $targetPop);
                        $devJ2 = abs($binPops[$j] - $targetPop);
                        if ($devI2 < $currentMaxDev2 && $devJ2 < $currentMaxDev2) continue;

                        // Compute new maximum — O(k)
                        $newMaxDev2 = 0.0;
                        foreach ($binPops as $bi2 => $bp2) {
                            $newPop2 = $bp2;
                            if ($bi2 === $i) $newPop2 -= $jPop;
                            if ($bi2 === $j) $newPop2 += $jPop;
                            $d2 = abs($newPop2 - $targetPop);
                            if ($d2 > $newMaxDev2) $newMaxDev2 = $d2;
                        }
                        $improvement = $currentMaxDev2 - $newMaxDev2;
                        if ($improvement <= $bestImprovement2) continue;

                        // Contiguity guard — only run BFS for genuinely better candidates
                        $remainingI = array_values(array_filter($bins[$i], fn($x) => $x !== $jid));
                        if (count($remainingI) >= 2) {
                            $remSet = array_flip($remainingI);
                            $vis    = [$remainingI[0] => true];
                            $bfsQ   = [$remainingI[0]];
                            while (!empty($bfsQ)) {
                                $cur = array_shift($bfsQ);
                                foreach ($adj[$cur] ?? [] as $nb) {
                                    if (!isset($remSet[$nb]) || isset($vis[$nb])) continue;
                                    $dx = ($centroids[$cur]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                                    $dy = ($centroids[$cur]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                                    if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                                    $vis[$nb] = true;
                                    $bfsQ[]   = $nb;
                                }
                            }
                            if (count($vis) < count($remainingI)) continue;
                        }

                        $bestImprovement2 = $improvement;
                        $bestBI2 = $i; $bestBJ2 = $j; $bestBJid2 = $jid;
                        $bestBRemainingI2 = $remainingI;
                        $bestBJPop2 = $jPop; $bestBJFrac2 = $jFrac;
                    }
                }
            }

            $swapMade2 = false;
            if ($bestBI2 >= 0) {
                $bx = $centroids[$bestBJid2]['x'] ?? 0.0;
                $by = $centroids[$bestBJid2]['y'] ?? 0.0;
                $bins[$bestBJ2][]     = $bestBJid2;
                $binPops[$bestBJ2]   += $bestBJPop2;
                $binFracs[$bestBJ2]  += $bestBJFrac2;
                $binSx[$bestBJ2]     += $bestBJPop2 * $bx;
                $binSy[$bestBJ2]     += $bestBJPop2 * $by;
                $binSx2[$bestBJ2]    += $bestBJPop2 * $bx * $bx;
                $binSy2[$bestBJ2]    += $bestBJPop2 * $by * $by;
                $bins[$bestBI2]       = $bestBRemainingI2;
                $binPops[$bestBI2]   -= $bestBJPop2;
                $binFracs[$bestBI2]  -= $bestBJFrac2;
                $binSx[$bestBI2]     -= $bestBJPop2 * $bx;
                $binSy[$bestBI2]     -= $bestBJPop2 * $by;
                $binSx2[$bestBI2]    -= $bestBJPop2 * $bx * $bx;
                $binSy2[$bestBI2]    -= $bestBJPop2 * $by * $by;
                $assigned[$bestBJid2] = $bestBJ2;
                $swapMade2 = true;
            }
            $swapIter2++;
        } while ($swapMade2 && $swapIter2 < $swapMax2);

        // --- Post-swap full contiguity validation ---
        // Even with the per-swap BFS guard, a false-positive adjacency edge can trick
        // the guard into allowing a bridge-removal swap.  After ALL swaps settle, run
        // a second distance-filtered BFS over every bin.  If ANY bin fails the check,
        // revert the entire swap phase — the clean BFS layout is always contiguous.
        $swapValid = true;
        foreach ($bins as $checkBin) {
            if (count($checkBin) <= 1) continue;
            $cs = array_flip($checkBin);
            $cv = [$checkBin[0] => true];
            $cq = [$checkBin[0]];
            while (!empty($cq)) {
                $cur = array_shift($cq);
                foreach ($adj[$cur] ?? [] as $nb) {
                    if (!isset($cs[$nb]) || isset($cv[$nb])) continue;
                    $dx = ($centroids[$cur]['x'] ?? 0.0) - ($centroids[$nb]['x'] ?? 0.0);
                    $dy = ($centroids[$cur]['y'] ?? 0.0) - ($centroids[$nb]['y'] ?? 0.0);
                    if ($dx * $dx + $dy * $dy > $maxEdgeDistSq) continue;
                    $cv[$nb] = true;
                    $cq[]    = $nb;
                }
            }
            if (count($cv) < count($checkBin)) { $swapValid = false; break; }
        }
        if (!$swapValid) {
            // Revert bins and fracs; rebuild assigned map so post-repair uses correct data
            $bins     = $preSwapBins;
            $binFracs = $preSwapFracs;
            $assigned = [];
            foreach ($bins as $bi => $binJids) {
                foreach ($binJids as $bj) {
                    $assigned[$bj] = $bi;
                }
            }
        }

        // --- Post-repair: merge undersized bins (< 5.0 fractional) if possible ---
        // $binFracs is already live-tracked throughout BFS — no need to recompute.
        // After swap refinement this path is rare (standalone isolated-jid bins only).
        //
        // Priority: merge into an ADJACENT absorber (shares a border in $adj) to preserve
        // contiguity.  Only fall back to nearest-centroid when no adjacent absorber exists
        // (truly isolated jids with no adjacency data — unavoidable non-contiguity).
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($binFracs as $i => $t) {
                if ($t >= $floorBoundary || empty($bins[$i])) continue;

                // Collect bins that share at least one adjacency edge with bin i
                $adjBorderBins = [];
                foreach ($bins[$i] as $myJid) {
                    foreach ($adj[$myJid] ?? [] as $nb) {
                        if (isset($assigned[$nb])) {
                            $bj = $assigned[$nb];
                            if ($bj !== $i && !empty($bins[$bj])) {
                                $adjBorderBins[$bj] = true;
                            }
                        }
                    }
                }

                $bestJ    = -1;
                $bestDist = PHP_FLOAT_MAX;
                $iCenter  = $this->binCentroid($bins[$i], $centroids);

                // Phase 1: adjacent absorbers only (contiguity-safe merge)
                foreach (array_keys($adjBorderBins) as $j) {
                    if ($binFracs[$j] + $t >= $giantThreshold) continue;
                    $jCenter = $this->binCentroid($bins[$j], $centroids);
                    $dx = $iCenter['x'] - $jCenter['x'];
                    $dy = $iCenter['y'] - $jCenter['y'];
                    $d  = $dx * $dx + $dy * $dy;
                    if ($d < $bestDist) { $bestDist = $d; $bestJ = $j; }
                }

                // Phase 2: fallback to any absorber by centroid (truly isolated jids only)
                if ($bestJ < 0) {
                    foreach ($binFracs as $j => $tj) {
                        if ($j === $i || empty($bins[$j])) continue;
                        if ($tj + $t >= $giantThreshold) continue;
                        $jCenter = $this->binCentroid($bins[$j], $centroids);
                        $dx = $iCenter['x'] - $jCenter['x'];
                        $dy = $iCenter['y'] - $jCenter['y'];
                        $d  = $dx * $dx + $dy * $dy;
                        if ($d < $bestDist) { $bestDist = $d; $bestJ = $j; }
                    }
                }

                if ($bestJ >= 0) {
                    $bins[$bestJ]     = array_merge($bins[$bestJ], $bins[$i]);
                    $binFracs[$bestJ] += $binFracs[$i];
                    $bins[$i]         = [];
                    $binFracs[$i]     = 0.0;
                    $changed          = true;
                    break;
                }
            }
        }

        return array_values(array_filter($bins, fn($b) => !empty($b)));
    }

    /**
     * Score a candidate bin configuration against constitutional priority criteria.
     * Returns an array suitable for lexicographic comparison (lower = better for all fields).
     *
     * Priority order:
     *   1. Fewest non-contiguous bins       (BFS reachability check in-memory via $adj)
     *   2. Fewest districts with |dev| > 2% (per-district balance: sub-2% is the primary goal)
     *   3. Lowest avg Rg²                   (compactness — stringy districts penalised heavily)
     *   4. Lowest avg Droop threshold       (UPD diversity: prefer 5×9+2×8 over 9×6+1×7)
     *   5. Lowest average deviation %       (fine-tune balance within tied configurations)
     *
     * count_over_2pct as criterion 2 encodes the user's priority hierarchy: get every district
     * under 2% first, then optimise compactness, then UPD, then average balance.  The implicit
     * "expand by 2% and retry" relaxation emerges naturally: if no configuration achieves
     * count_over_2pct = 0, the one with fewest over-threshold districts wins, and the algorithm
     * still found the best solution it could at the strict tolerance before relaxing.
     * avg_rg_sq (radius of gyration², lower = more compact) ranks above the UPD-diversity metric
     * because stringy non-compact districts are almost as bad as non-contiguity.
     *
     * Simulates Webster apportionment in-memory to compute accurate per-district deviations.
     * Zero DB queries — uses the adjacency graph already loaded in runAutoCompositeForScope().
     *
     * Note: compactness (convex_hull_ratio) requires ST_Union geometry — scored post-insert
     * by recomputeDistrict(). Community integrity is determined at classification time
     * (giants pre-separated) — not scored here.
     */
    private function scoreConfiguration(
        array $bins,
        array $childById,
        array $adj,
        float $totalBinPop,
        int   $nonGiantBudget,
        int   $floor,
        int   $ceiling,
        float $floorBoundary
    ): array {
        $binCount      = count($bins);
        $binQuota      = $totalBinPop / max($nonGiantBudget, 1);
        $floorFeasible = ($nonGiantBudget >= $binCount * $floor);
        $startSeats    = $floorFeasible ? $floor : 1;

        // Simulate Webster (Sainte-Laguë) apportionment in-memory
        $binPops        = array_map(
            fn($b) => array_sum(array_map(fn($jid) => (int) $childById[$jid]->population, $b)),
            $bins
        );
        $binSeats       = array_fill(0, $binCount, $startSeats);
        $floorOverrides = array_map(fn($p) => $binQuota > 0 && ($p / $binQuota) < $floorBoundary, $binPops);

        $remaining = $nonGiantBudget - $startSeats * $binCount;
        for ($r = 0; $r < $remaining; $r++) {
            $bestIdx = -1;
            $bestPri = -1.0;
            foreach ($binSeats as $i => $s) {
                if ($s >= $ceiling) continue;
                if ($floorFeasible && $floorOverrides[$i]) continue;
                $pri = $binPops[$i] / (2 * $s + 1);
                if ($pri > $bestPri) { $bestPri = $pri; $bestIdx = $i; }
            }
            if ($bestIdx >= 0) $binSeats[$bestIdx]++;
        }

        // Compute per-bin deviation percentages
        $deviations = [];
        foreach ($bins as $i => $binJids) {
            $pop   = $binPops[$i];
            $seats = $binSeats[$i];
            if ($seats <= 0 || $binQuota <= 0) { $deviations[] = 0.0; continue; }
            $deviations[] = abs($pop / $seats - $binQuota) / $binQuota * 100;
        }

        // Uniform Political Diversity — average Droop entry threshold across the
        // districts (lower = more diverse). A district of s seats has a Droop
        // threshold of 1/(s+1); larger magnitudes clear at a lower threshold, so
        // more factions win representation (more proportional → more diverse).
        // Averaging per district rewards larger districts AND punishes a lone
        // small outlier — a 3-seat district sits at 1/4, dragging the mean up far
        // more than the convex curve gives back — so the single scalar captures
        // "maximise political diversity, accounting for spread". This REPLACES the
        // former seat-variance uniformity proxy: for a 61-seat budget it now
        // prefers 5×9+2×8 (avg ≈ 0.103) over 9×6+1×7 (avg ≈ 0.141) — bigger
        // districts, lower thresholds, more diversity.
        $droopSum = 0.0;
        foreach ($binSeats as $s) {
            $droopSum += 1.0 / ($s + 1);
        }
        $avgDroopThreshold = $binCount > 0 ? $droopSum / $binCount : 1.0;

        // In-memory contiguity: BFS reachability within each bin using the adjacency graph.
        // Apply the same distance-based false-positive filter used in geographicSeedExpansion:
        // ignore adjacency edges whose centroid distance exceeds 4× the 90th-percentile edge
        // length for this component.  Without this, a false-positive long-distance edge in the
        // adjacency table lets two disconnected halves appear "reachable" and hides the
        // non-contiguous configuration from the scorer — causing it to win the competition.
        // Centroids are available as centroid_x / centroid_y on each $childById entry.
        $allJids      = array_merge(...$bins);
        $jidInComp    = array_flip($allJids);
        $scAdjDistsSq = [];
        foreach ($allJids as $jid) {
            foreach ($adj[$jid] ?? [] as $nb) {
                if (!isset($jidInComp[$nb])) continue;
                $dx = ($childById[$jid]->centroid_x ?? 0.0) - ($childById[$nb]->centroid_x ?? 0.0);
                $dy = ($childById[$jid]->centroid_y ?? 0.0) - ($childById[$nb]->centroid_y ?? 0.0);
                $scAdjDistsSq[] = $dx * $dx + $dy * $dy;
            }
        }
        sort($scAdjDistsSq);
        $scP90Idx        = max(0, (int) floor(count($scAdjDistsSq) * 0.90) - 1);
        $scMaxEdgeDistSq = !empty($scAdjDistsSq) ? $scAdjDistsSq[$scP90Idx] * 16.0 : PHP_FLOAT_MAX;

        // Average radius of gyration² (compactness proxy, lower = more compact).
        // Computed in-memory using centroid_x/y; no PostGIS required.
        // Formula: Rg²_i = (Sx2+Sy2)/M − (Sx²+Sy²)/M²  where M=total pop, Sx=sum(pop×lon), etc.
        $totalRgSq = 0.0;
        foreach ($bins as $i => $binJids) {
            $M = (float) $binPops[$i];
            if ($M <= 0.0) continue;
            $sx = 0.0; $sy = 0.0; $sx2 = 0.0; $sy2 = 0.0;
            foreach ($binJids as $jid) {
                $p  = (float) $childById[$jid]->population;
                $x  = $childById[$jid]->centroid_x ?? 0.0;
                $y  = $childById[$jid]->centroid_y ?? 0.0;
                $sx  += $p * $x;   $sy  += $p * $y;
                $sx2 += $p * $x * $x; $sy2 += $p * $y * $y;
            }
            $totalRgSq += ($sx2 + $sy2) / $M - ($sx * $sx + $sy * $sy) / ($M * $M);
        }
        $avgRgSq = $binCount > 0 ? $totalRgSq / $binCount : 0.0;

        $nonContiguousCount = 0;
        foreach ($bins as $binJids) {
            if (count($binJids) <= 1) continue; // single-member bins are trivially contiguous
            $binSet  = array_flip($binJids);
            $visited = [$binJids[0] => true];
            $queue   = [$binJids[0]];
            while (!empty($queue)) {
                $curr = array_shift($queue);
                foreach ($adj[$curr] ?? [] as $nb) {
                    if (!isset($binSet[$nb]) || isset($visited[$nb])) continue;
                    $dx = ($childById[$curr]->centroid_x ?? 0.0) - ($childById[$nb]->centroid_x ?? 0.0);
                    $dy = ($childById[$curr]->centroid_y ?? 0.0) - ($childById[$nb]->centroid_y ?? 0.0);
                    if ($dx * $dx + $dy * $dy > $scMaxEdgeDistSq) continue;
                    $visited[$nb] = true;
                    $queue[]      = $nb;
                }
            }
            if (count($visited) < count($binJids)) $nonContiguousCount++;
        }

        // count_over_2pct: number of districts whose per-seat deviation exceeds 2%.
        // This is the primary post-contiguity criterion: the algorithm prefers configurations
        // where every district is ≤2% over configurations that are merely better on average.
        $countOver2pct = count(array_filter($deviations, fn($d) => $d > 2.0));

        return [
            'non_contiguous_count' => $nonContiguousCount,
            'count_over_2pct'      => $countOver2pct,
            'avg_rg_sq'            => $avgRgSq,
            'avg_droop_threshold'  => $avgDroopThreshold,
            'avg_deviation_pct'    => empty($deviations) ? 0.0 : array_sum($deviations) / count($deviations),
            'max_deviation_pct'    => empty($deviations) ? 0.0 : max($deviations),
        ];
    }

    /**
     * Compute the average centroid of a set of jurisdictions.
     * Used for cross-component post-repair merging (e.g. joining island bins to mainland bins).
     */
    private function binCentroid(array $jids, array $centroids): array
    {
        $x = 0.0;
        $y = 0.0;
        $n = count($jids);
        foreach ($jids as $jid) {
            $x += $centroids[$jid]['x'] ?? 0.0;
            $y += $centroids[$jid]['y'] ?? 0.0;
        }
        return [
            'x' => $n > 0 ? $x / $n : 0.0,
            'y' => $n > 0 ? $y / $n : 0.0,
        ];
    }

    /**
     * Publish granular phase progress for an in-flight mass operation.
     * The Vue side polls /mass-status every 2.5 s and displays the latest
     * snapshot. Cache::put is independent of any open DB transaction, so
     * progress is visible to other backends even mid-tx.
     *
     * Pass a partial array — keys are merged into the existing snapshot so
     * a phase change doesn't clobber unrelated fields like `completed`.
     *
     * Pass `$reset = true` when starting a fresh operation so stale fields
     * from a previous run (e.g., scope_started_at, current_scope, phase_total)
     * don't leak through the merge. Without this flag the UI shows a confusing
     * "Queued — waiting for worker" paired with "5m 12s on scope" leftover
     * from a previous Sudan run.
     */
    public function publishMassProgress(string $legislature_id, array $patch, bool $reset = false): void
    {
        $key = "legislature.{$legislature_id}.mass_progress";
        $existing = $reset ? [] : (Cache::get($key, []) ?: []);
        if (! is_array($existing)) $existing = [];
        Cache::put($key, array_merge($existing, $patch, [
            'last_update_at' => time(),
        ]), 7200);
    }
}
