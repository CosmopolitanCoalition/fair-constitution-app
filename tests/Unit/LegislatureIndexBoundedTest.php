<?php

namespace Tests\Unit;

use App\Http\Controllers\LegislatureController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * PIN (W-0441) — the /legislatures hub is bounded by adm level, never the planet.
 *
 * Measured 2026-09-14 on box E: the old index() counted every legislature
 * (940,328 rows), joined and sorted all of them by seats with a trailing
 * LIMIT 500, and scanned every election twice with DISTINCT ON, on each
 * request: 30.9 s and 286 KB for a guest, and the browser sweep's renderer
 * died on the route every time. The ETL paradigm's corollary: a LIMIT does
 * not bound a query, the input does. This pin reads the method bodies, so it
 * needs no database, and fails if any of the three planet-wide shapes return.
 */
class LegislatureIndexBoundedTest extends TestCase
{
    private function body(string $method): string
    {
        $m = new ReflectionMethod(LegislatureController::class, $method);
        $lines = file($m->getFileName());

        return implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    }

    public function test_index_counts_through_the_cache_and_bounds_the_election_picks(): void
    {
        $index = $this->body('index');

        $this->assertStringContainsString("Cache::remember(", $index, 'the total is cached, never a live count per request');
        $this->assertStringContainsString("'legislatures.index.total'", $index);
        $this->assertStringContainsString('$this->hubRows()', $index, 'rows come from the level walk');
        $this->assertSame(2, substr_count($index, 'legislature_id IN ({$placeholders})'), 'both election picks are bounded by the hub ids');
        $this->assertStringNotContainsString('AND legislature_id IS NOT NULL', $index, 'no unbounded election scan remains');
        $this->assertStringNotContainsString("->limit(500)", $index, 'no trailing LIMIT over an unbounded join');
    }

    public function test_hub_rows_walk_adm_levels_with_a_bounded_query_per_level(): void
    {
        $hub = $this->body('hubRows');

        $this->assertStringContainsString("->where('j.adm_level', \$level)", $hub, 'each query is bounded to one adm level');
        $this->assertStringContainsString('self::HUB_MAX_ADM_LEVEL', $hub, 'the walk has a floor');
        $this->assertStringContainsString('$rows->count() < self::HUB_ROWS', $hub, 'the walk stops when the hub is full');
        $this->assertStringContainsString('->limit(self::HUB_ROWS - $rows->count())', $hub, 'each level takes only what is still missing');
        $this->assertStringNotContainsString("->orderBy('j.adm_level')", $hub, 'no cross-level sort');
    }

    public function test_hub_constants(): void
    {
        $r = new \ReflectionClass(LegislatureController::class);
        $this->assertSame(500, $r->getConstant('HUB_ROWS'));
        $this->assertSame(6, $r->getConstant('HUB_MAX_ADM_LEVEL'));
    }
}
