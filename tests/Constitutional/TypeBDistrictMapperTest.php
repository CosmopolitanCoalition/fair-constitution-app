<?php

namespace Tests\Constitutional;

use App\Services\Legislature\TypeBDistrictMapper;
use PHPUnit\Framework\TestCase;

/**
 * CONSTITUTIONAL PIN — THE TYPE B DISTRICT MAPPER (operator rulings 2026-09-05).
 *
 * Stage two of the Type B ladder: whole sibling constituents clump into PANELS.
 * THE MODEL:
 *   - EVERY constituent is a clump member — zero-population parts INCLUDED (B3).
 *   - p = floor(bound / rep_floor) panels; seats spread as evenly as possible,
 *     each >= rep_floor. The leftover odd seat is a BONUS seat on ONE panel
 *     (with rep_floor 2, at most one 3-seat panel).
 *   - Member counts are PROPORTIONAL TO SEATS, so representation WEIGHT
 *     (members per seat) is uniform: the bonus panel holds ~1.5x the members.
 *   - Panels are CONTIGUOUS on a connected graph (multi-source Voronoi +
 *     connectivity-safe rebalance). Population is never consulted; STV absorbs
 *     intra-clump lopsidedness.
 *   - Seats are exact by construction (Σ panel seats == bound) — DRIFT law holds.
 *
 * computePanels() is pure arithmetic + graph, like TypeBSeatLadder. If an edit
 * breaks these, the edit is the constitutional violation.
 */
class TypeBDistrictMapperTest extends TestCase
{
    /**
     * THE UNGROUPED MAP (operator order 2026-09-05, Type B as the last scope of
     * every composite map): a chamber whose ladder already fits gets a panel
     * map all the same — one panel per constituent, in constituent order, each
     * seated exactly as the ladder seats the child (rep_floor; min(pop,
     * rep_floor) for a tiny part; 0 for an empty part). No clumping, and the
     * grouping's total equals the ladder's type_b_seats to the seat.
     */
    public function test_a_ladder_fit_chamber_maps_one_panel_per_constituent_with_the_ladder_seats(): void
    {
        $pops = ['a' => 1000, 'b' => 1000, 'c' => 3, 'd' => 0];

        $r = TypeBDistrictMapper::computePanels($pops, [], 34, 2003, 5);

        // bound = min(34, 2003 − 34) = 34; the ladder at 5: 5 + 5 + 3 + 0 = 13 ≤ 34.
        $this->assertSame(4, $r['panel_count'], 'one panel per constituent');
        $this->assertSame([['a'], ['b'], ['c'], ['d']], $r['panels'], 'constituent order, nobody clumped');
        $this->assertSame([5, 5, 3, 0], $r['panel_seats'], 'rep_floor, min(pop, rep_floor) for the tiny part, 0 for the empty part');
        $this->assertSame(13, $r['seats']);
        $this->assertFalse($r['undercount']);
        $ladder = \App\Services\Legislature\TypeBSeatLadder::apportion(34, $pops, 5, 2003);
        $this->assertSame($ladder['seats'], $r['seats'], 'the panel map seats exactly what the at-large ladder seats');
        $this->assertFalse($ladder['needs_districting']);
    }

    /** Clumping begins only where the ladder overflows: 6 parts at 2 = 12 > bound 11. */
    public function test_clumping_starts_only_when_the_ladder_overflows(): void
    {
        $pops = ['a' => 100, 'b' => 100, 'c' => 100, 'd' => 100, 'e' => 100, 'f' => 100];

        $r = TypeBDistrictMapper::computePanels($pops, [], 11, 600, 2);

        $this->assertSame(5, $r['panel_count'], 'floor(11 / 2) = 5 panels');
        $this->assertSame(10, $r['seats'], '5 × 2; the odd spare seat is unused');
        $this->assertSame(6, array_sum(array_map('count', $r['panels'])), 'every part placed');
        $this->assertSame([2, 1, 1, 1, 1], array_map('count', $r['panels']), 'even split: one pair, four singles');
    }

    /**
     * THE CLAUDE.md WORKED EXAMPLE: 50 states, 1,000 people, Type A 10. At 2
     * apiece Type B is 100; the grouping settles on 5 panels of 10 states x 2 =
     * 10, fitting Type A exactly. Ten states share one panel. No adjacency, so
     * this also pins the island distributor SPREADING evenly (never piling on
     * panel 0).
     */
    public function test_fifty_states_group_into_five_panels_of_ten(): void
    {
        $pops = [];
        for ($i = 0; $i < 50; $i++) {
            $pops['s' . sprintf('%02d', $i)] = 20; // Σ = 1000
        }

        $r = TypeBDistrictMapper::computePanels($pops, [], 10, 1000, 2);

        $this->assertSame(5, $r['panel_count'], 'floor(bound 10 / rep_floor 2) = 5 panels');
        $this->assertSame(10, $r['seats'], '5 panels x 2 = 10 = Type A');
        $this->assertLessThanOrEqual(10, $r['seats'], 'never exceeds the bound');
        foreach ($r['panels'] as $panel) {
            $this->assertCount(10, $panel, 'even partition: ten states per panel');
        }
        $this->assertSame(50, array_sum(array_map('count', $r['panels'])), 'every state placed');
    }

    /**
     * NIUE: 7 inhabited villages and 7 empty ones, Type A 11. EVERY part is a
     * member (B3 — the empties are territory, placed in a clump), so all 14 group
     * into the 5 panels the bound allows. NO bonus seat: every panel elects
     * rep_floor (2), the total is 10 ≤ bound 11, and the odd spare seat goes
     * UNUSED (Type A is a ceiling, not a target). Members split as even as
     * possible: [3,3,3,3,2] — "equal except one".
     */
    public function test_niue_groups_all_parts_no_bonus_seat(): void
    {
        $pops = [
            'v1' => 50, 'v2' => 89, 'v3' => 100, 'v4' => 117, 'v5' => 173, 'v6' => 224, 'v7' => 436,
            'e1' => 0, 'e2' => 0, 'e3' => 0, 'e4' => 0, 'e5' => 0, 'e6' => 0, 'e7' => 0,
        ];

        $r = TypeBDistrictMapper::computePanels($pops, [], 11, 1189, 2);

        $this->assertSame(5, $r['panel_count'], 'floor(11/2) = 5 panels');
        $this->assertSame(10, $r['seats'], '5 x rep_floor 2 = 10 <= bound 11 (the odd spare seat is unused)');
        $this->assertSame(14, array_sum(array_map('count', $r['panels'])), 'all 14 parts placed — zeros are members (B3)');

        // NO bonus — every panel elects rep_floor.
        $this->assertSame([2, 2, 2, 2, 2], $r['panel_seats'], 'every panel seats rep_floor; no bonus panel');

        // Even member split: base 2, base+1 on the remainder — "equal except one".
        $sizes = array_map('count', $r['panels']);
        rsort($sizes);
        $this->assertSame([3, 3, 3, 3, 2], $sizes, 'members split as even as possible (base, base+1)');
    }

    /** The grouping never seats more than the bound — even at massive fan-out. */
    public function test_grouping_never_exceeds_the_bound(): void
    {
        // Minas-Gerais shape: 862 municipalities, Type A 278.
        $pops = [];
        for ($i = 0; $i < 862; $i++) {
            $pops['m' . sprintf('%04d', $i)] = 10000;
        }

        $r = TypeBDistrictMapper::computePanels($pops, [], 278, 8620000, 2);

        $this->assertSame(139, $r['panel_count'], 'floor(278/2) = 139 panels');
        $this->assertSame(278, $r['seats'], '139 x 2 = 278 = Type A, exactly');
        $this->assertLessThanOrEqual(278, $r['seats']);
        $this->assertSame(862, array_sum(array_map('count', $r['panels'])), 'every municipality placed');
    }

    /** A line graph groups into CONTIGUOUS panels — adjacency is respected. */
    public function test_line_adjacency_yields_contiguous_panels(): void
    {
        $pops = ['a' => 100, 'b' => 100, 'c' => 100, 'd' => 100, 'e' => 100, 'f' => 100];
        $adj  = [
            'a' => ['b' => 1.0],
            'b' => ['a' => 1.0, 'c' => 1.0],
            'c' => ['b' => 1.0, 'd' => 1.0],
            'd' => ['c' => 1.0, 'e' => 1.0],
            'e' => ['d' => 1.0, 'f' => 1.0],
            'f' => ['e' => 1.0],
        ];

        $r = TypeBDistrictMapper::computePanels($pops, $adj, 6, 600, 2);

        $this->assertSame(3, $r['panel_count'], 'floor(6/2) = 3 panels');
        foreach ($r['panels'] as $panel) {
            $this->assertCount(2, $panel, 'even pairs');
            $this->assertTrue($this->isConnected($panel, $adj), 'each pair is contiguous along the line');
        }
        // The pairs follow the line a-b / c-d / e-f.
        $got = array_map(function (array $panel): array {
            sort($panel);
            return $panel;
        }, $r['panels']);
        foreach ([['a', 'b'], ['c', 'd'], ['e', 'f']] as $pair) {
            $this->assertContains($pair, $got, 'panels follow the adjacency line');
        }
    }

    /**
     * Deterministic AND order-independent: identical inputs give a byte-identical
     * result, and REORDERING the input map does not change the panels (seeding,
     * Voronoi and rebalance all rank by graph distance then id, never by array
     * position).
     */
    public function test_deterministic(): void
    {
        $pops = ['a' => 5, 'b' => 6, 'c' => 7, 'd' => 8, 'e' => 9];
        $r1 = TypeBDistrictMapper::computePanels($pops, [], 4, 35, 2);
        $r2 = TypeBDistrictMapper::computePanels($pops, [], 4, 35, 2);

        $this->assertSame($r1, $r2, 'identical inputs give a byte-identical result');
        $this->assertSame(2, $r1['panel_count']);
        $this->assertSame(4, $r1['seats']);

        $reordered = ['e' => 9, 'c' => 7, 'a' => 5, 'd' => 8, 'b' => 6];
        $r3 = TypeBDistrictMapper::computePanels($reordered, [], 4, 35, 2);
        $this->assertSame($r1['panels'], $r3['panels'], 'panels are independent of input map order');
    }

    /**
     * ZERO-POPULATION PARTS ARE MEMBERS (operator ruling 2026-09-05): an
     * unpopulated part is not dropped — it is a clump member (territory that
     * votes with the clump the instant someone lives there). On a connected graph
     * it joins a panel contiguously, never excluded.
     */
    public function test_zero_population_parts_are_members(): void
    {
        $pops = ['a' => 100, 'b' => 0, 'c' => 100, 'd' => 100, 'e' => 0];
        $adj  = [
            'a' => ['b' => 5.0, 'c' => 1.0],
            'b' => ['a' => 5.0, 'c' => 5.0],
            'c' => ['b' => 5.0, 'a' => 1.0, 'd' => 1.0],
            'd' => ['c' => 1.0, 'e' => 3.0],
            'e' => ['d' => 3.0],
        ];

        $r = TypeBDistrictMapper::computePanels($pops, $adj, 4, 300, 2);

        $flat = array_merge(...$r['panels']);
        sort($flat);
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $flat, 'every part — zeros included — is a clump member');
        foreach ($r['panels'] as $panel) {
            $this->assertTrue($this->isConnected($panel, $adj), 'each clump is one connected patch');
        }
    }

    /** A leaf-like empty constituent set produces no panels. */
    public function test_no_constituents_no_panels(): void
    {
        $r = TypeBDistrictMapper::computePanels([], [], 9, 0, 2);
        $this->assertSame(0, $r['panel_count']);
        $this->assertSame(0, $r['seats']);
        $this->assertSame([], $r['panels']);
    }

    /**
     * THE HARD CAP OVER A WHOLE PANEL (B3): when the combined cap leaves less
     * than one full panel's headroom (bound < rep_floor), the mapper seats ZERO
     * panels — never a full panel that would put type_a + type_b over population.
     */
    public function test_bound_below_one_panel_seats_zero_not_a_full_panel(): void
    {
        // pop 6, two children of 3, type_a 5: bound = min(5, 6-5) = 1 < rep_floor 2.
        $r = TypeBDistrictMapper::computePanels(['a' => 3, 'b' => 3], [], 5, 6, 2);
        $this->assertSame(0, $r['panel_count'], 'no whole panel fits under the cap');
        $this->assertSame(0, $r['seats'], 'zero Type B seats — never more reps than people');
        $this->assertTrue($r['undercount'], 'the sub-panel headroom is a genuine undercount');
        $this->assertLessThanOrEqual(max(0, 6 - 5), $r['seats']);

        // The starkest: pop 4, two of 2, type_a 5 → bound 0.
        $r2 = TypeBDistrictMapper::computePanels(['a' => 2, 'b' => 2], [], 5, 4, 2);
        $this->assertSame(0, $r2['seats']);
        $this->assertLessThanOrEqual(max(0, 4 - 5), $r2['seats']);
    }

    /**
     * NO BONUS SEAT — THE SPARE STAYS UNUSED (operator ruling 2026-09-05,
     * corrected). Type A is the CEILING, not a target: the at-large ladder gives
     * each child rep_floor and leaves spare seats unused, so clumping does the
     * same. 21 parts, bound 7 (odd) → 3 panels, every panel rep_floor (2) = 6
     * seats; the 7th seat is UNUSED, never awarded as a bonus. Members divide
     * evenly: [7,7,7].
     */
    public function test_no_bonus_seat_the_spare_stays_unused(): void
    {
        $pops = [];
        for ($i = 0; $i < 21; $i++) {
            $pops['x' . sprintf('%02d', $i)] = 1000; // Σ = 21000
        }

        $r = TypeBDistrictMapper::computePanels($pops, [], 7, 21000, 2);

        $this->assertSame(3, $r['panel_count'], 'floor(7/2) = 3 panels');
        $this->assertSame(6, $r['seats'], '3 x 2 = 6 <= bound 7; the odd spare seat is unused, not a bonus');
        $this->assertSame(21, array_sum(array_map('count', $r['panels'])), 'all placed');
        $this->assertSame([2, 2, 2], $r['panel_seats'], 'every panel seats rep_floor — no bonus');

        $sizes = array_map('count', $r['panels']);
        rsort($sizes);
        $this->assertSame([7, 7, 7], $sizes, 'members divide evenly when n is a multiple of p');
    }

    /**
     * CONTIGUITY IS THE HARD RULE (operator ruling 2026-09-05): on a connected
     * graph EVERY clump is a connected patch. A 2x3 grid partitioned into 3
     * panels of 2 yields three adjacent pairs — never a panel split across the
     * grid.
     */
    public function test_panels_are_contiguous_on_a_connected_graph(): void
    {
        // a-b-c over d-e-f grid.
        $pops = ['a' => 100, 'b' => 100, 'c' => 100, 'd' => 100, 'e' => 100, 'f' => 100];
        $adj = [
            'a' => ['b' => 1.0, 'd' => 1.0],
            'b' => ['a' => 1.0, 'c' => 1.0, 'e' => 1.0],
            'c' => ['b' => 1.0, 'f' => 1.0],
            'd' => ['a' => 1.0, 'e' => 1.0],
            'e' => ['b' => 1.0, 'd' => 1.0, 'f' => 1.0],
            'f' => ['c' => 1.0, 'e' => 1.0],
        ];

        $r = TypeBDistrictMapper::computePanels($pops, $adj, 6, 600, 2);

        $this->assertSame(3, $r['panel_count']);
        foreach ($r['panels'] as $panel) {
            $this->assertCount(2, $panel);
            $this->assertTrue($this->isConnected($panel, $adj), 'each clump is one connected patch');
        }
    }

    /**
     * CONTIGUITY HOLDS ON A LARGER GRAPH: a 4x4 grid with an odd bound partitions
     * into contiguous panels, every panel electing rep_floor (no bonus), the odd
     * spare seat unused (Σ = p x rep_floor <= bound).
     */
    public function test_contiguity_holds_on_a_larger_graph(): void
    {
        $pops = [];
        $adj  = [];
        $id = fn (int $r, int $c): string => "r{$r}c{$c}";
        for ($row = 0; $row < 4; $row++) {
            for ($col = 0; $col < 4; $col++) {
                $pops[$id($row, $col)] = 1000;
            }
        }
        for ($row = 0; $row < 4; $row++) {
            for ($col = 0; $col < 4; $col++) {
                foreach ([[0, 1], [1, 0]] as [$dr, $dc]) {
                    $nr = $row + $dr; $nc = $col + $dc;
                    if ($nr < 4 && $nc < 4) {
                        $adj[$id($row, $col)][$id($nr, $nc)] = 1.0;
                        $adj[$id($nr, $nc)][$id($row, $col)] = 1.0;
                    }
                }
            }
        }

        $r = TypeBDistrictMapper::computePanels($pops, $adj, 7, 16000, 2);

        $this->assertSame(3, $r['panel_count'], 'floor(7/2) = 3 panels');
        $this->assertSame(6, $r['seats'], '3 x 2 = 6 <= bound 7 (the odd spare seat unused)');
        $this->assertSame(16, array_sum(array_map('count', $r['panels'])), 'all 16 cells placed');
        $this->assertSame([2, 2, 2], $r['panel_seats'], 'every panel seats rep_floor — no bonus');
        foreach ($r['panels'] as $panel) {
            $this->assertTrue($this->isConnected($panel, $adj), 'each clump is one connected patch');
        }
    }

    /**
     * @param list<string>                      $members
     * @param array<string,array<string,float>> $adj
     */
    private function isConnected(array $members, array $adj): bool
    {
        if (count($members) <= 1) {
            return true;
        }
        $set = array_flip($members);
        $seen = [$members[0] => true];
        $stack = [$members[0]];
        while ($stack !== []) {
            $cur = array_pop($stack);
            foreach ($adj[$cur] ?? [] as $nbr => $_) {
                $nbr = (string) $nbr;
                if (isset($set[$nbr]) && ! isset($seen[$nbr])) {
                    $seen[$nbr] = true;
                    $stack[] = $nbr;
                }
            }
        }

        return count($seen) === count($set);
    }
}
