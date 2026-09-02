<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Services\Districting\LeafGiantResolver;
use App\Services\Districting\PlanRefused;
use App\Services\Districting\PopulationRaster;
use App\Services\Districting\SubdivisionAutoseedService;
use App\Services\Districting\SubdivisionBoxSeedService;
use App\Services\Districting\SubdivisionCellSeedService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * PIN — the leaf line-split ladder (fix set 2026-09-02).
 *
 *  1. THE BOX LEADS EVERY MULTI-PART SCOPE (operator ruling 2026-09-02):
 *     any scope with more than one polygon part orders the box template
 *     first, regardless of seat budget or area share. A one-part scope
 *     keeps the cutting ladder (requested template first) with the box last.
 *  2. ONE BLADE POOL PER SCOPE: the ladder opens one counter per scope; a
 *     plan() call under the open pool shares it (no per-template reset); a
 *     plan() call outside the pool owns a fresh counter.
 *  3. EXHAUSTION JUMPS TO THE BOX: once the pool is spent the ladder skips
 *     every remaining rung except the box.
 *
 * Pure and mocked: no live database is touched (the test connection is
 * sqlite :memory:; PostGIS statements are intercepted at the DB facade).
 */
class LeafLadderOrderTest extends TestCase
{
    private const CTX = ['floor' => 5, 'ceiling' => 9, 'budget' => 18, 'quota' => 1.0];

    public function test_a_two_part_scope_leads_with_the_box(): void
    {
        $order = LeafGiantResolver::orderTemplates(SubdivisionAutoseedService::TEMPLATE_SHORTEST, 2);

        $this->assertSame(SubdivisionAutoseedService::TEMPLATE_BOX, $order[0]);
        $this->assertSame(SubdivisionAutoseedService::TEMPLATE_SHORTEST, $order[1]);
        $this->assertCount(count(SubdivisionAutoseedService::TEMPLATES), $order, 'every registry template rides once');
        $this->assertCount(1, array_keys($order, SubdivisionAutoseedService::TEMPLATE_BOX, true), 'the box appears once');
    }

    public function test_the_box_leads_for_any_part_count_above_one(): void
    {
        foreach ([2, 3, 50, 4404] as $parts) {
            $order = LeafGiantResolver::orderTemplates(SubdivisionAutoseedService::TEMPLATE_VERTICAL_STRIPS, $parts);
            $this->assertSame(SubdivisionAutoseedService::TEMPLATE_BOX, $order[0], "parts={$parts}");
            $this->assertSame(SubdivisionAutoseedService::TEMPLATE_VERTICAL_STRIPS, $order[1], "parts={$parts}: the requested template follows");
        }
    }

    public function test_a_one_part_scope_keeps_the_cutting_ladder_with_the_box_last(): void
    {
        $order = LeafGiantResolver::orderTemplates(SubdivisionAutoseedService::TEMPLATE_SHORTEST, 1);
        $this->assertSame(SubdivisionAutoseedService::TEMPLATES, $order);
        $this->assertSame(SubdivisionAutoseedService::TEMPLATE_BOX, end($order));

        $order = LeafGiantResolver::orderTemplates(SubdivisionAutoseedService::TEMPLATE_VERTICAL_STRIPS, 1);
        $this->assertSame(SubdivisionAutoseedService::TEMPLATE_VERTICAL_STRIPS, $order[0]);
        $this->assertSame(SubdivisionAutoseedService::TEMPLATE_BOX, end($order));
    }

    public function test_the_blade_pool_is_one_counter_per_scope(): void
    {
        $svc = $this->autoseed(Mockery::mock(PopulationRaster::class));

        $this->assertFalse($svc->bladePoolOpenFor('s1'));
        $svc->openBladePool('s1');
        $this->assertTrue($svc->bladePoolOpenFor('s1'));
        $this->assertSame(240, $svc->bladeBudgetRemaining());

        (new \ReflectionProperty($svc, 'bladeBudget'))->setValue($svc, 7);
        $this->assertSame(7, $svc->bladeBudgetRemaining());

        $svc->closeBladePool();
        $this->assertFalse($svc->bladePoolOpenFor('s1'));
    }

    public function test_plan_under_an_open_pool_shares_the_counter_and_plan_outside_it_resets(): void
    {
        // plan() reaches the DB (the region-cache DDL) before it asks for the
        // grid; an empty grid refuses right after the counter rule runs.
        DB::shouldReceive('statement')->andReturnTrue();
        $raster = Mockery::mock(PopulationRaster::class);
        $raster->shouldReceive('gridWithFallback')->andReturn([]);
        $svc = $this->autoseed($raster);

        $svc->openBladePool('s1');
        (new \ReflectionProperty($svc, 'bladeBudget'))->setValue($svc, 7);
        try {
            $svc->plan('s1', self::CTX, 2023, SubdivisionAutoseedService::TEMPLATE_SHORTEST);
            $this->fail('an empty grid must refuse');
        } catch (\RuntimeException) {
        }
        $this->assertSame(7, $svc->bladeBudgetRemaining(), 'a rung under the open pool shares the counter');

        try {
            $svc->plan('s1', self::CTX, 2023, SubdivisionAutoseedService::TEMPLATE_HORIZONTAL_STRIPS);
            $this->fail('an empty grid must refuse');
        } catch (\RuntimeException) {
        }
        $this->assertSame(7, $svc->bladeBudgetRemaining(), 'a second template under the same pool does not reset it');

        try {
            $svc->plan('other-scope', self::CTX, 2023, SubdivisionAutoseedService::TEMPLATE_SHORTEST);
            $this->fail('an empty grid must refuse');
        } catch (\RuntimeException) {
        }
        $this->assertSame(240, $svc->bladeBudgetRemaining(), 'a plan outside the pool owns a fresh counter');
    }

    public function test_an_exhausted_pool_skips_every_rung_but_the_box(): void
    {
        Log::spy();
        // A single-part scope: the cutting ladder runs with the box last.
        DB::shouldReceive('selectOne')->andReturn((object) ['parts' => 1]);
        $boxPlan = ['plan_hash' => 'h', 'template' => 'box', 'districts' => []];

        $autoseed = Mockery::mock(SubdivisionAutoseedService::class);
        $autoseed->shouldReceive('openBladePool')->once()->with('s1');
        $autoseed->shouldReceive('closeBladePool')->once();
        // 240 when the shortest rung is admitted; 0 from then on (shortest spent the pool).
        $autoseed->shouldReceive('bladeBudgetRemaining')->andReturn(240, 0);
        $autoseed->shouldReceive('plan')->once()->with('s1', self::CTX, 2023, SubdivisionAutoseedService::TEMPLATE_SHORTEST)
            ->andThrow(new PlanRefused('The blade search budget was exhausted for this scope'));
        $autoseed->shouldReceive('plan')->once()->with('s1', self::CTX, 2023, SubdivisionAutoseedService::TEMPLATE_BOX)
            ->andReturn($boxPlan);
        foreach ([
            SubdivisionAutoseedService::TEMPLATE_VERTICAL_STRIPS,
            SubdivisionAutoseedService::TEMPLATE_HORIZONTAL_STRIPS,
            SubdivisionAutoseedService::TEMPLATE_COMMUNITY_CELLS,
            SubdivisionAutoseedService::TEMPLATE_COMPONENTS,
        ] as $skipped) {
            $autoseed->shouldReceive('plan')->with('s1', self::CTX, 2023, $skipped)->never();
        }

        $resolver = new LeafGiantResolver($autoseed, Mockery::mock(ConstitutionalEngine::class));
        $result = $resolver->planWithFallback('s1', self::CTX, 2023, SubdivisionAutoseedService::TEMPLATE_SHORTEST, true);

        $this->assertSame(SubdivisionAutoseedService::TEMPLATE_BOX, $result['template']);
        $this->assertTrue($result['fallback']);
    }

    public function test_a_multi_part_scope_plans_the_box_first_through_the_ladder(): void
    {
        Log::spy();
        DB::shouldReceive('selectOne')->andReturn((object) ['parts' => 12]);
        $boxPlan = ['plan_hash' => 'h', 'template' => 'box', 'districts' => []];

        $autoseed = Mockery::mock(SubdivisionAutoseedService::class);
        $autoseed->shouldReceive('openBladePool')->once()->with('s2');
        $autoseed->shouldReceive('closeBladePool')->once();
        $autoseed->shouldReceive('bladeBudgetRemaining')->andReturn(240);
        $autoseed->shouldReceive('plan')->once()->with('s2', self::CTX, 2023, SubdivisionAutoseedService::TEMPLATE_BOX)->andReturn($boxPlan);
        $autoseed->shouldReceive('plan')->with('s2', self::CTX, 2023, SubdivisionAutoseedService::TEMPLATE_SHORTEST)->never();

        $resolver = new LeafGiantResolver($autoseed, Mockery::mock(ConstitutionalEngine::class));
        $result = $resolver->planWithFallback('s2', self::CTX, 2023, SubdivisionAutoseedService::TEMPLATE_SHORTEST, true);

        $this->assertSame(SubdivisionAutoseedService::TEMPLATE_BOX, $result['template']);
        $this->assertFalse($result['fallback'], 'the leading box is the primary rung of a multi-part scope, not a fallback');
    }

    private function autoseed(PopulationRaster $raster): SubdivisionAutoseedService
    {
        return new SubdivisionAutoseedService(
            $raster,
            new SubdivisionCellSeedService($raster),
            new SubdivisionBoxSeedService($raster),
        );
    }
}
