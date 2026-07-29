<?php

namespace Tests\Constitutional;

use App\Services\Legislature\TypeBDistrictMapper;
use PHPUnit\Framework\TestCase;

/**
 * CONSTITUTIONAL PIN — THE TYPE B DISTRICT MAPPER (Wave 3, operator B1–B7).
 *
 * Stage two of the Type B ladder: whole sibling constituents clump into equal,
 * compact PANELS sharing rep_floor seats, elected in ONE at-large race
 * (seats = panel_count × rep_floor). The grouping is a balanced partition over
 * the constituent adjacency graph — NO geometry is cut. Populations are
 * consulted only for the residual (B2). Seats are exact by construction
 * (panel_count × rep_floor ≤ bound) — DRIFT law holds.
 *
 * computePanels() is pure arithmetic + graph, like TypeBSeatLadder. If an edit
 * breaks these, the edit is the constitutional violation.
 */
class TypeBDistrictMapperTest extends TestCase
{
    /**
     * THE CLAUDE.md WORKED EXAMPLE: 50 states, 1,000 people, Type A 10. At 2
     * apiece Type B is 100; grouping settles on 5 panels of 10 states × 2 = 10,
     * fitting Type A exactly. Ten states share one panel.
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
     * NIUE: 7 inhabited villages (50..436) and 7 empty ones, Type A 11. The
     * empties are INERT (B3 — no seats, not grouped); the 7 inhabited clump into
     * the 5 panels the bound allows, and the two PAIRS fall on the four
     * lowest-population villages (B2 — the residual is borne low).
     */
    public function test_niue_groups_the_inhabited_and_leaves_empties_inert(): void
    {
        $pops = [
            'v1' => 50, 'v2' => 89, 'v3' => 100, 'v4' => 117, 'v5' => 173, 'v6' => 224, 'v7' => 436,
            'e1' => 0, 'e2' => 0, 'e3' => 0, 'e4' => 0, 'e5' => 0, 'e6' => 0, 'e7' => 0,
        ];

        $r = TypeBDistrictMapper::computePanels($pops, [], 11, 1189, 2);

        $this->assertSame(5, $r['panel_count'], 'floor(11/2) = 5 panels');
        $this->assertSame(10, $r['seats'], '5 x 2 = 10 <= Type A 11');
        $this->assertLessThanOrEqual(11, $r['seats']);
        $this->assertCount(7, $r['inert'], 'the seven empty villages are inert');

        $assigned = array_merge(...$r['panels']);
        $this->assertCount(7, $assigned, 'every inhabited village placed exactly once');
        $this->assertSame(7, count(array_unique($assigned)));

        $sizes = array_map('count', $r['panels']);
        rsort($sizes);
        $this->assertSame([2, 2, 1, 1, 1], $sizes, 'as equal as possible: two pairs, three singles');

        // B2: the two PAIRS bear the residual — the four lowest-population
        // villages (50, 89, 100, 117).
        $paired = [];
        foreach ($r['panels'] as $panel) {
            if (count($panel) === 2) {
                $paired = array_merge($paired, $panel);
            }
        }
        sort($paired);
        $this->assertSame(['v1', 'v2', 'v3', 'v4'], $paired, 'lowest-population villages bear the pairing');
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
        $this->assertSame(3, count($r['panels']));
        // Each panel is a contiguous pair along the line a-b-c-d-e-f.
        $contiguousPairs = [['a', 'b'], ['c', 'd'], ['e', 'f']];
        $got = array_map(function (array $panel): array {
            sort($panel);
            return $panel;
        }, $r['panels']);
        foreach ($contiguousPairs as $pair) {
            $this->assertContains($pair, $got, 'panels follow the adjacency line');
        }
    }

    /** Deterministic: identical inputs give byte-identical panels. */
    public function test_deterministic(): void
    {
        $pops = ['a' => 5, 'b' => 6, 'c' => 7, 'd' => 8, 'e' => 9];
        $r1 = TypeBDistrictMapper::computePanels($pops, [], 4, 35, 2);
        $r2 = TypeBDistrictMapper::computePanels($pops, [], 4, 35, 2);

        $this->assertSame($r1['panels'], $r2['panels']);
        $this->assertSame($r1['signature'] ?? null, $r2['signature'] ?? null);
    }

    /**
     * INERT constituents never enter the walk — even when they sit between
     * inhabited ones on the adjacency graph (B3: zero-pop is not grouped). Two
     * inhabited villages bridged only through an empty one must still group
     * the inhabited pair, never the empty bridge.
     */
    public function test_inert_neighbours_never_enter_the_walk(): void
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
        $this->assertNotContains('b', $flat, 'the inert bridge is never grouped');
        $this->assertNotContains('e', $flat);
        foreach (['a', 'c', 'd'] as $inhabited) {
            $this->assertContains($inhabited, $flat);
        }
        $this->assertSame(['b', 'e'], $r['inert']);
    }

    /** A leaf-like empty constituent set produces no panels. */
    public function test_no_constituents_no_panels(): void
    {
        $r = TypeBDistrictMapper::computePanels([], [], 9, 0, 2);
        $this->assertSame(0, $r['panel_count']);
        $this->assertSame(0, $r['seats']);
        $this->assertSame([], $r['panels']);
    }
}
