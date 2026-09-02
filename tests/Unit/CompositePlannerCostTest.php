<?php

namespace Tests\Unit;

use App\Services\DistrictingService;
use Tests\TestCase;

/**
 * THE COMPOSITE PLANNER COST pins (2026-09-02, workstream D).
 *
 * The k-loop visits its k values nearest-first to the middle of the district
 * band and ends early only after an EXACT incumbent has survived two
 * consecutive non-improving k values; it never ends on misses while no exact
 * landing exists (exactness outranks the comparator). The builders and the
 * bisection sweep are gated behind a Phase-B exact landing that leads the
 * incumbent. hullRepairPass returns immediately above the shared 150-member
 * polish cap and bounds its swap grid per pair. Step 12 hands Step 7's
 * adjacency to recomputeDistrict so no per-district ST_Intersects runs.
 *
 * Pure PHP through reflection on the private helpers, plus source pins where
 * the behaviour needs PostGIS to drive. No database.
 */
class CompositePlannerCostTest extends TestCase
{
    // ─── (a) k order ────────────────────────────────────────────────────────

    public function test_k_order_starts_at_the_k_nearest_seven_seats_per_district(): void
    {
        // 42 seats over k = 2..9: seats/district 21, 14, 10.5, 8.4, 7, 6, 5.25, 4.67.
        // Nearest the band middle (5+9)/2 = 7 first: k=6 (0), then k=7 (1),
        // k=5 (1.4), k=8 (1.75), k=9 (2.33), k=4 (3.5), k=3 (7), k=2 (14).
        $order = $this->orderK(range(2, 9), 42, 5, 9);
        $this->assertSame([6, 7, 5, 8, 9, 4, 3, 2], $order);
        $this->assertSame(6, $order[0], 'the first k visited is the one nearest 7 seats per district');

        // 28 seats over k = 2..5: 14, 9.33, 7, 5.6 -> k=4 first.
        $this->assertSame([4, 5, 3, 2], $this->orderK(range(2, 5), 28, 5, 9));
    }

    public function test_k_order_keeps_the_set_and_breaks_ties_to_the_lower_k(): void
    {
        // 24 seats: k=3 -> 8 and k=4 -> 6 are both one seat from 7; the lower k leads.
        $order = $this->orderK(range(2, 5), 24, 5, 9);
        $this->assertSame(3, $order[0]);
        $this->assertSame(4, $order[1]);

        // The set is never changed, only its order.
        $range = range(2, 9);
        $sorted = $this->orderK($range, 61, 5, 9);
        $this->assertEqualsCanonicalizing($range, $sorted);
        $this->assertCount(count($range), $sorted);

        // The middle derives from the band, never a literal 7: a 3..5 band centres on 4.
        // 12 seats: k=3 -> 4 exactly leads.
        $this->assertSame(3, $this->orderK(range(2, 4), 12, 3, 5)[0]);
    }

    // ─── (b) + (c) the early exit ────────────────────────────────────────────

    public function test_two_consecutive_misses_after_an_exact_incumbent_end_the_loop(): void
    {
        $limit = (new \ReflectionClassConstant(DistrictingService::class, 'K_LOOP_MISS_LIMIT'))->getValue();
        $this->assertSame(2, $limit);

        $exact = $this->score(seatDrift: 0);

        // k1 lands exact and becomes the incumbent: no miss.
        $misses = $this->misses(0, $exact, improved: true);
        $this->assertSame(0, $misses);

        // k2 and k3 do not beat it: one miss, then two -> the loop ends.
        $misses = $this->misses($misses, $exact, improved: false);
        $this->assertSame(1, $misses);
        $this->assertLessThan($limit, $misses, 'one miss keeps the loop going');
        $misses = $this->misses($misses, $exact, improved: false);
        $this->assertSame(2, $misses);
        $this->assertGreaterThanOrEqual($limit, $misses, 'the second consecutive miss ends the loop');
    }

    public function test_an_improvement_between_misses_resets_the_count(): void
    {
        $exact = $this->score(seatDrift: 0);
        $misses = $this->misses(0, $exact, improved: false);
        $this->assertSame(1, $misses);
        $misses = $this->misses($misses, $exact, improved: true);
        $this->assertSame(0, $misses, 'a k that beats the incumbent resets the consecutive-miss count');
    }

    public function test_the_loop_never_ends_while_no_exact_landing_exists(): void
    {
        $limit   = (new \ReflectionClassConstant(DistrictingService::class, 'K_LOOP_MISS_LIMIT'))->getValue();
        $drifted = $this->score(seatDrift: 1);

        // A drifted incumbent, five non-improving k values in a row: the count stays zero.
        $misses = 0;
        for ($i = 0; $i < 5; $i++) {
            $misses = $this->misses($misses, $drifted, improved: false);
            $this->assertSame(0, $misses, 'no miss is counted while the incumbent drifts');
        }
        $this->assertLessThan($limit, $misses);

        // No incumbent at all (every k produced nothing): still zero.
        $this->assertSame(0, $this->misses(0, null, improved: false));

        // Exactness is scoreRank's first key and nothing else.
        $svc   = app(DistrictingService::class);
        $exact = new \ReflectionMethod($svc, 'isExactScore');
        $this->assertTrue($exact->invoke($svc, $this->score(seatDrift: 0)));
        $this->assertFalse($exact->invoke($svc, $this->score(seatDrift: 1)));
        $rank = new \ReflectionMethod($svc, 'scoreRank');
        $this->assertSame(0, $rank->invoke($svc, $this->score(seatDrift: 0))[0]);
        $this->assertSame(3, $rank->invoke($svc, $this->score(seatDrift: 3))[0]);
    }

    // ─── (d) hullRepairPass member gate ─────────────────────────────────────

    public function test_hull_repair_returns_immediately_above_the_member_cap(): void
    {
        $cap = (new \ReflectionClassConstant(DistrictingService::class, 'POLISH_MEMBER_CAP'))->getValue();
        $this->assertSame(150, $cap);

        $svc  = app(DistrictingService::class);
        $pass = new \ReflectionMethod($svc, 'hullRepairPass');
        $meta = new \ReflectionProperty($svc, 'stepMeta');
        $bl   = new \ReflectionProperty($svc, 'borderLen');

        // A 76 x 2 chain: 152 members in two touching bins. borderLen is
        // non-empty so the pass reaches the member gate, not its edge guard.
        [$bins, $childById, $adj, $centroids, $borderLen] = $this->chainFixture(76, 2, 1.25);
        $bl->setValue($svc, $borderLen);
        $meta->setValue($svc, []);

        $out = $pass->invoke($svc, $bins, $childById, $adj, $centroids, 'leg-test', 10, 5, 9, 9.5, 5.0);

        $this->assertSame($bins, $out, 'above the cap the bins come back untouched');
        $recorded = $meta->getValue($svc);
        $this->assertSame(152, $recorded['step8c.hullrepair']['members']);
        $this->assertSame($cap, $recorded['step8c.hullrepair']['member_cap']);
        $this->assertArrayNotHasKey('pairs', $recorded['step8c.hullrepair'],
            'the gate fired before any pair was visited');

        // Control: a 4 x 2 chain (8 members) passes the gate and walks its pair.
        [$bins, $childById, $adj, $centroids, $borderLen] = $this->chainFixture(4, 2, 1.25);
        $bl->setValue($svc, $borderLen);
        $meta->setValue($svc, []);
        $pass->invoke($svc, $bins, $childById, $adj, $centroids, 'leg-test', 10, 5, 9, 9.5, 5.0);
        $recorded = $meta->getValue($svc);
        $this->assertSame(8, $recorded['step8c.hullrepair']['members']);
        $this->assertArrayHasKey('pairs', $recorded['step8c.hullrepair'], 'below the cap the pass runs its pair loop');
        $this->assertArrayHasKey('swap_cap_max', $recorded['step8c.hullrepair']);
        $this->assertLessThanOrEqual(
            (new \ReflectionClassConstant(DistrictingService::class, 'HULL_SWAP_CAP'))->getValue(),
            $recorded['step8c.hullrepair']['swap_cap_max']
        );
    }

    // ─── source pins (the k-loop and Step 12 need PostGIS to drive) ─────────

    public function test_k_loop_visits_the_ordered_set_and_breaks_on_the_miss_limit(): void
    {
        $body = $this->methodSource(DistrictingService::class, 'runAutoCompositeForScope');

        $this->assertStringContainsString('? $this->orderKCandidates($kCandidates, $compBudget, $floor, $ceiling)', $body);
        $this->assertStringContainsString('$fanOutCut = count($component) >= self::FANOUT_CUT_MEMBERS;', $body,
            'the k order applies only under the fan-out size gate');
        $this->assertStringContainsString('foreach (($adoptLineFirst ? [] : $kOrder) as $k) {', $body,
            'the k-loop iterates the ordered set');
        $this->assertStringNotContainsString('foreach (($adoptLineFirst ? [] : $kCandidates) as $k) {', $body);
        $this->assertStringContainsString('$kMisses = $this->kLoopMisses($kMisses, $incumbentScore, $improved);', $body);
        $this->assertStringContainsString('if ($fanOutCut && $kMisses >= self::K_LOOP_MISS_LIMIT) {', $body,
            'the early exit applies only under the fan-out size gate');

        // The line-first path keeps the ascending range.
        $this->assertStringContainsString('$this->lineFirstEngaged($component, $adj, $kCandidates, $lfMode)', $body);

        // Candidates reach the variants plane in ascending k order.
        $this->assertStringContainsString('ksort($perKConfigs);', $body);
        $this->assertLessThan(strpos($body, "\$this->stepBegin('variants');"), strpos($body, 'ksort($perKConfigs);'));
    }

    public function test_builders_and_bisection_are_gated_behind_a_leading_exact_phase_b(): void
    {
        $body = $this->methodSource(DistrictingService::class, 'runAutoCompositeForScope');

        $gate = strpos($body, '$phaseBLeads = $bestScoreK !== null');
        $this->assertNotFalse($gate);
        $this->assertStringContainsString('$this->isExactScore($bestScoreK)', substr($body, $gate, 300));
        $this->assertStringContainsString('if ($quotaPopC > 0 && ! ($fanOutCut && $phaseBLeads)) {', $body,
            'the builders and the bisection sweep run only when Phase B does not already lead with an exact landing');

        // Both generators sit inside the gated block.
        $gatedBlock = substr($body, strpos($body, 'if ($quotaPopC > 0 && ! ($fanOutCut && $phaseBLeads)) {'),
            strpos($body, 'if ($bestBinsK !== null) {') - strpos($body, 'if ($quotaPopC > 0 && ! ($fanOutCut && $phaseBLeads)) {'));
        $this->assertStringContainsString('$this->sequentialBuild(', $gatedBlock);
        $this->assertStringContainsString('$this->bisectionCandidates(', $gatedBlock);
        // Phase B itself stays ungated.
        $this->assertLessThan($gate, strpos($body, "\$this->stepEnd(\"phaseB.k{\$k}\");"));
    }

    /**
     * THE FAN-OUT SIZE GATE (operator ruling 2026-09-02, the gate27 New York
     * regression): the k order, the early exit and the builder gate apply only
     * to components with FANOUT_CUT_MEMBERS or more children; below that the
     * full search runs as before. One shared cap with the polish passes.
     */
    public function test_the_fan_out_cut_applies_only_at_or_above_the_shared_member_cap(): void
    {
        $body = $this->methodSource(DistrictingService::class, 'runAutoCompositeForScope');
        $this->assertStringContainsString('$fanOutCut = count($component) >= self::FANOUT_CUT_MEMBERS;', $body);
        $this->assertStringContainsString(': array_values($kCandidates);', $body, 'small components keep the ascending range');
        $this->assertStringContainsString('if ($quotaPopC > 0 && ! ($fanOutCut && $phaseBLeads)) {', $body);
        $this->assertStringContainsString('if ($fanOutCut && $kMisses >= self::K_LOOP_MISS_LIMIT) {', $body);

        $ref = new \ReflectionClassConstant(DistrictingService::class, 'FANOUT_CUT_MEMBERS');
        $this->assertSame(150, $ref->getValue());
        $polish = new \ReflectionClassConstant(DistrictingService::class, 'POLISH_MEMBER_CAP');
        $this->assertSame($polish->getValue(), $ref->getValue(), 'one member cap shared with the polish passes');
    }

    public function test_step12_hands_the_step7_adjacency_to_recompute_and_times_the_write_phase(): void
    {
        $body = $this->methodSource(DistrictingService::class, 'runAutoCompositeForScope');
        $this->assertStringContainsString('$this->recomputeDistrict($districtId, $legislature_id, $leg, true, $adj);', $body);
        foreach (['step9.clear', 'step10.seat', 'step12', 'step12.writes', 'step12.recompute',
                  'step8.final', 'step8c.override', 'step8c.breakrepair', 'step8c.hullrepair', 'step8c.polish',
                  'step1.load', 'step4.cascade'] as $label) {
            $this->assertStringContainsString("\$this->stepBegin('{$label}');", $body, "timer {$label} opens");
            $this->assertStringContainsString("\$this->stepEnd('{$label}');", $body, "timer {$label} closes");
        }
        // The district number is read once per scope, not once per district.
        $this->assertSame(1, substr_count($body, "->max('district_number')"));

        $recompute = $this->methodSource(DistrictingService::class, 'recomputeDistrict');
        $this->assertStringContainsString('?array $poolAdj = null', $recompute);
        $this->assertStringContainsString('$needFrame       = ! $skipSeatsUpdate || $poolAdj === null;', $recompute);
        $this->assertStringNotContainsString('$distScopeRow', $recompute, 'the unused scope-row read is gone');
        $this->assertSame(1, substr_count($recompute, 'LeafGiantResolver::shareBase('), 'shareBase is read once, on the branch that uses it');
        // The pairwise ST_Intersects queries sit behind the null-adjacency branch.
        $adjQuery = strpos($recompute, '$adjPairs = DB::select(');
        $poolPath = strpos($recompute, 'if ($poolAdj !== null) {');
        $this->assertNotFalse($adjQuery);
        $this->assertNotFalse($poolPath);
        $this->assertLessThan($adjQuery, $poolPath);
        $this->assertStringContainsString('if (! empty($poolAdj[$oj])) {', $recompute);
        // The union + hull is timed as step12.geometry inside the store-miss branch.
        $geom = strpos($recompute, "\$this->stepBegin('step12.geometry');");
        $this->assertNotFalse($geom);
        $this->assertStringContainsString('ST_ConvexHull', substr($recompute, $geom, 1800));
        $this->assertStringContainsString("\$this->stepEnd('step12.geometry');", $recompute);
    }

    public function test_polish_passes_share_one_member_cap(): void
    {
        $hull   = $this->methodSource(DistrictingService::class, 'hullRepairPass');
        $polish = $this->methodSource(DistrictingService::class, 'comparatorPolishPass');
        $this->assertStringContainsString('self::POLISH_MEMBER_CAP', $hull);
        $this->assertStringContainsString('self::POLISH_MEMBER_CAP', $polish);
        $this->assertStringNotContainsString('> 150', $polish, 'no literal cap beside the shared constant');
        $this->assertStringContainsString('min(self::HULL_SWAP_CAP, (int) ceil(sqrt($n)))', $hull);
        $this->assertStringNotContainsString('array_slice($iBorderH, 0, 16)', $hull);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function orderK(array $ks, int $budget, int $floor, int $ceiling): array
    {
        $svc = app(DistrictingService::class);
        $m   = new \ReflectionMethod($svc, 'orderKCandidates');

        return $m->invoke($svc, $ks, $budget, $floor, $ceiling);
    }

    private function misses(int $misses, ?array $incumbent, bool $improved): int
    {
        $svc = app(DistrictingService::class);
        $m   = new \ReflectionMethod($svc, 'kLoopMisses');

        return $m->invoke($svc, $misses, $incumbent, $improved);
    }

    /** A full score array so scoreRank can read it; only seat_drift varies. */
    private function score(int $seatDrift): array
    {
        return [
            'seat_drift'           => $seatDrift,
            'ceiling_breach_count' => 0,
            'floor_override_count' => 0,
            'avg_deviation_pct'    => 1.0,
            'max_deviation_pct'    => 2.0,
            'fragment_gap'         => 0.0,
            'non_contiguous_count' => 0,
            'seat_spread'          => 0,
            'seat_spread_excess'   => 0,
            'cut_length'           => 1.0,
            'neck_count'           => 0,
            'avg_rg_sq'            => 1.0,
            'avg_droop_threshold'  => 0.1,
        ];
    }

    /**
     * A cols x rows grid chain split into two bins down the middle, with
     * adjacency, centroids and border lengths for every grid edge.
     *
     * @return array{0: array, 1: array, 2: array, 3: array, 4: array}
     */
    private function chainFixture(int $cols, int $rows, float $fracEach): array
    {
        $childById = []; $centroids = []; $adj = []; $byCell = []; $borderLen = [];
        for ($gy = 0; $gy < $rows; $gy++) {
            for ($gx = 0; $gx < $cols; $gx++) {
                $id = sprintf('c%02d%02d', $gx, $gy);
                $byCell["$gx,$gy"] = $id;
                $childById[$id] = (object) ['population' => 100_000, 'fractional_seats' => $fracEach,
                                            'centroid_x' => $gx + 0.5, 'centroid_y' => $gy + 0.5];
                $centroids[$id] = ['x' => $gx + 0.5, 'y' => $gy + 0.5];
                $adj[$id] = [];
            }
        }
        foreach ($byCell as $key => $id) {
            [$gx, $gy] = array_map('intval', explode(',', $key));
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nb = $byCell[($gx + $dx) . ',' . ($gy + $dy)] ?? null;
                if ($nb === null) continue;
                $adj[$id][] = $nb;
                if (strcmp($id, $nb) < 0) $borderLen["$id|$nb"] = 1.0;
            }
        }
        $left = []; $right = [];
        foreach ($byCell as $key => $id) {
            [$gx] = array_map('intval', explode(',', $key));
            if ($gx < intdiv($cols, 2)) $left[] = $id; else $right[] = $id;
        }

        return [[$left, $right], $childById, $adj, $centroids, $borderLen];
    }

    private function methodSource(string $class, string $method): string
    {
        $m = new \ReflectionMethod($class, $method);
        $lines = file($m->getFileName());

        return implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    }
}
