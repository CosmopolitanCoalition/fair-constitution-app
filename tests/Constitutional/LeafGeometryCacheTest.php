<?php

namespace Tests\Constitutional;

use App\Domain\Forms\Handlers\ManualDistrictDraw;
use App\Services\Districting\SubdivisionAutoseedService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PIN — the scope parts store: DISSOLVE ONCE PER SCOPE (fix set 2026-09-02).
 *
 * ST_Dump(ST_UnaryUnion(ST_MakeValid(geom))) used to run as a fresh
 * expression at plan entry, in componentsPlan, once per component group,
 * once per filed piece in the leaf assembly, and once per filed piece in
 * the filing census. It now fills a session scratch table on first need
 * (SubdivisionAutoseedService::scopePartsRef) and every site reads the rows:
 *  1. repeated scopePartsRef calls issue exactly ONE dissolve statement;
 *  2. forgetScopeParts deletes the scope's rows;
 *  3. the filing census reads the stored parts when present and dissolves
 *     live when none exist (a human draw keeps working);
 *  4. a machine piece's census skips the component census entirely.
 *
 * The test connection is sqlite :memory: and PostGIS statements cannot run
 * there, so the statements are intercepted at the DB facade and counted; a
 * DB::listen counter needs an executed statement and would count nothing.
 */
class LeafGeometryCacheTest extends TestCase
{
    private const GEOJSON = '{"type":"Polygon","coordinates":[[[0,0],[1,0],[1,1],[0,1],[0,0]]]}';

    public function test_the_dissolve_runs_once_per_scope(): void
    {
        $statements = [];
        $filled = false;
        DB::shouldReceive('statement')->andReturnUsing(function (string $sql) use (&$statements, &$filled) {
            $statements[] = $sql;
            if (str_contains($sql, 'ST_UnaryUnion')) {
                $filled = true;
            }

            return true;
        });
        DB::shouldReceive('selectOne')->andReturnUsing(function (string $sql, array $bindings = []) use (&$filled) {
            $this->assertStringContainsString(SubdivisionAutoseedService::SCOPE_PARTS_TABLE, $sql);
            $this->assertSame(['scope-a'], $bindings);

            return $filled ? (object) ['x' => 1] : null;
        });

        SubdivisionAutoseedService::scopePartsRef('scope-a');
        SubdivisionAutoseedService::scopePartsRef('scope-a');
        SubdivisionAutoseedService::scopePartsRef('scope-a');
        $this->assertTrue(SubdivisionAutoseedService::hasScopeParts('scope-a'));

        $dissolves = array_values(array_filter($statements, fn (string $sql) => str_contains($sql, 'ST_UnaryUnion')));
        $this->assertCount(1, $dissolves, 'the dissolve runs once per scope');
        $this->assertStringContainsString('ST_Dump(ST_UnaryUnion(ST_MakeValid(j.geom)))', $dissolves[0]);
        $this->assertStringContainsString('COALESCE(d.path[1], 1)', $dissolves[0], 'the same idx every site derived live');
        $this->assertStringContainsString('ON CONFLICT (scope, idx) DO NOTHING', $dissolves[0]);
    }

    public function test_forgetting_a_scope_deletes_its_rows(): void
    {
        DB::shouldReceive('statement')->andReturnTrue();
        DB::shouldReceive('delete')->once()
            ->withArgs(fn (string $sql, array $bindings) => str_contains($sql, 'DELETE FROM '.SubdivisionAutoseedService::SCOPE_PARTS_TABLE)
                && $bindings === ['scope-a'])
            ->andReturn(0);

        SubdivisionAutoseedService::forgetScopeParts('scope-a');
    }

    public function test_the_census_reads_the_stored_parts_when_present(): void
    {
        $captured = [];
        DB::shouldReceive('statement')->andReturnTrue();
        DB::shouldReceive('selectOne')->andReturnUsing(function (string $sql, array $bindings = []) use (&$captured) {
            if (str_contains($sql, 'SELECT 1 AS x FROM '.SubdivisionAutoseedService::SCOPE_PARTS_TABLE)) {
                return (object) ['x' => 1];                 // the session holds the parts
            }
            $captured[] = [$sql, $bindings];

            return (object) ['parts' => 1, 'cut_components' => 0, 'fragment_pieces' => 0, 'within' => true, 'empty' => false];
        });

        $geo = ManualDistrictDraw::partCensus(self::GEOJSON, 'scope-a');

        $this->assertNotNull($geo);
        $this->assertCount(1, $captured);
        [$sql, $bindings] = $captured[0];
        $this->assertStringContainsString('FROM '.SubdivisionAutoseedService::SCOPE_PARTS_TABLE, $sql);
        $this->assertStringNotContainsString('ST_UnaryUnion', $sql, 'no live dissolve when the parts are stored');
        $this->assertSame('scope-a', $bindings['scope_parts']);
        $this->assertSame('scope-a', $bindings['scope']);
        $this->assertSame(self::GEOJSON, $bindings['gj']);
        $this->assertStringContainsString('CROSS JOIN LATERAL', $sql, 'one intersection per touched landmass');
        $this->assertSame(1, substr_count($sql, 'ST_Intersection('), 'the intersection is computed once and reused');
    }

    public function test_the_census_dissolves_live_when_no_parts_are_stored(): void
    {
        $captured = [];
        DB::shouldReceive('statement')->andReturnTrue();
        DB::shouldReceive('selectOne')->andReturnUsing(function (string $sql, array $bindings = []) use (&$captured) {
            if (str_contains($sql, 'SELECT 1 AS x FROM '.SubdivisionAutoseedService::SCOPE_PARTS_TABLE)) {
                return null;                                // a human draw: nothing stored
            }
            $captured[] = [$sql, $bindings];

            return (object) ['parts' => 1, 'cut_components' => 1, 'fragment_pieces' => 1, 'within' => true, 'empty' => false];
        });

        $geo = ManualDistrictDraw::partCensus(self::GEOJSON, 'scope-b');

        $this->assertSame(1, (int) $geo->cut_components);
        [$sql, $bindings] = $captured[0];
        $this->assertStringContainsString('ST_Dump(ST_UnaryUnion((SELECT g FROM gi)))', $sql);
        $this->assertArrayNotHasKey('scope_parts', $bindings);
        $this->assertSame(['gj' => self::GEOJSON, 'scope' => 'scope-b'], $bindings);
    }

    public function test_a_machine_piece_census_skips_the_component_census(): void
    {
        DB::shouldReceive('statement')->never();
        DB::shouldReceive('delete')->never();
        DB::shouldReceive('selectOne')->once()->andReturnUsing(function (string $sql, array $bindings = []) {
            $this->assertStringNotContainsString('ST_UnaryUnion', $sql);
            $this->assertStringNotContainsString('ST_Intersection', $sql);
            $this->assertStringContainsString('ST_CoveredBy', $sql, 'containment is still proven');
            $this->assertStringContainsString('ST_NumGeometries', $sql, 'the part count is still recorded');
            $this->assertSame(['gj' => self::GEOJSON, 'scope' => 'scope-c'], $bindings);

            return (object) ['parts' => 3, 'cut_components' => 0, 'fragment_pieces' => 0, 'within' => true, 'empty' => false];
        });

        $geo = ManualDistrictDraw::partCensus(self::GEOJSON, 'scope-c', false);

        $this->assertSame(3, (int) $geo->parts);
        $this->assertSame(0, (int) $geo->cut_components);
        $this->assertSame(0, (int) $geo->fragment_pieces);
    }
}
