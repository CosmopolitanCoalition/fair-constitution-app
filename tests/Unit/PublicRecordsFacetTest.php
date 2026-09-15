<?php

namespace Tests\Unit;

use App\Http\Controllers\System\PublicRecordsController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * PIN (W-0437) — the public-records legislature facet is bounded by the records.
 *
 * On 2026-09-14 GET /system/public-records answered 502 for a guest on box E:
 * the facet joined every legislature on the box (940,328 rows) to jurisdictions
 * and sorted them by name on every page load. The ETL paradigm's first
 * corollary binds web requests too: never one planet-wide statement. The facet
 * now reads the distinct legislature ids that appear in public_records and
 * names only those. This pin reads the method bodies so it runs with no
 * database, and it fails if the unbounded join returns to index().
 */
class PublicRecordsFacetTest extends TestCase
{
    private function body(string $method): string
    {
        $m = new ReflectionMethod(PublicRecordsController::class, $method);
        $lines = file($m->getFileName());

        return implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    }

    public function test_index_does_not_scan_legislatures_or_count_the_register(): void
    {
        $index = $this->body('index');

        $this->assertStringNotContainsString("DB::table('legislatures", $index, 'index() must not query legislatures directly');
        $this->assertStringNotContainsString('->count()', $index, 'index() must not count the register inline');
        $this->assertStringContainsString('$this->legislatureFacet($legislatureId)', $index, 'the facet is the active legislature only');
        $this->assertStringContainsString('$this->stats()', $index, 'the statistics come from the bounded helper');
    }

    public function test_facet_is_one_row_by_id_or_nothing(): void
    {
        $facet = $this->body('legislatureFacet');

        $this->assertStringContainsString("if (\$legislatureId === ''", $facet, 'no id, no query');
        $this->assertStringContainsString("->where('l.id', \$legislatureId)", $facet, 'one legislature by id');
        $this->assertStringContainsString("->first(", $facet, 'one row');
        $this->assertStringNotContainsString('->get(', $facet, 'never a list');
    }

    public function test_stats_are_a_high_water_mark_plus_cached_kind_counts(): void
    {
        $stats = $this->body('stats');

        $this->assertStringContainsString("->max('seq')", $stats, 'total is the sealed sequence high-water mark');
        $this->assertStringContainsString('Cache::remember(', $stats, 'per-kind counts are served from the cache');
        $this->assertStringContainsString('addMinutes(15)', $stats, 'recomputed at most once per 15 minutes');
    }
}
