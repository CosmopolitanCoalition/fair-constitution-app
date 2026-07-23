<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Services\Districting\LeafGiantResolver;
use App\Services\Districting\PlanRefused;
use App\Services\Districting\SubdivisionAutoseedService;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the line-split FALLBACK LADDER (operator sanction
 * 2026-07-18: "ladder first, manual for the residue"). When the autoscale
 * sweep's requested template refuses a giant's geometry ("no contiguous
 * in-band straight cut"), the resolver walks the remaining templates in
 * registry order before surrendering the scope to the review list — and it
 * NEVER ladders on a previewed commit, where silently swapping the template
 * a human saw would betray the plan-hash contract.
 */
class LineSplitLadderTest extends TestCase
{
    private function resolverWith(callable $planFn): LeafGiantResolver
    {
        $autoseed = $this->createMock(SubdivisionAutoseedService::class);
        $autoseed->method('plan')->willReturnCallback($planFn);

        // The exactness pre-gate's oracle: mocked to echo the plan's own
        // pops (measured == planned → drift 0), so the ladder pins keep
        // exercising template order, not measurement.
        $raster = $this->createMock(\App\Services\Districting\PopulationRaster::class);
        $raster->method('measureWithFallback')->willReturn(['pop' => 0, 'source' => 'worldpop_raster']);

        return new LeafGiantResolver($autoseed, $this->createMock(ConstitutionalEngine::class), $raster);
    }

    public function test_refused_template_falls_through_the_registry_order(): void
    {
        $attempts = [];
        $resolver = $this->resolverWith(function ($scope, $ctx, $year, $template) use (&$attempts) {
            $attempts[] = $template;
            if ($template === 'shortest' || $template === 'vertical_strips') {
                throw new PlanRefused('No contiguous in-band straight cut found — cut it by hand.');
            }

            return ['plan_hash' => 'h', 'districts' => [], 'template' => $template];
        });

        $out = $resolver->planWithFallback('scope', [], 2023, 'shortest', true);

        $this->assertSame(['shortest', 'vertical_strips', 'horizontal_strips'], $attempts,
            'the ladder walks the registry order from the requested template');
        $this->assertSame('horizontal_strips', $out['template']);
        $this->assertTrue($out['fallback'], 'a laddered plan is marked as a fallback');
    }

    public function test_previewed_commits_never_ladder(): void
    {
        $attempts = [];
        $resolver = $this->resolverWith(function ($scope, $ctx, $year, $template) use (&$attempts) {
            $attempts[] = $template;
            throw new PlanRefused('No contiguous in-band straight cut found — cut it by hand.');
        });

        try {
            $resolver->planWithFallback('scope', [], 2023, 'shortest', false);
            $this->fail('a refused previewed template must throw, never ladder');
        } catch (PlanRefused) {
            // expected
        }

        $this->assertSame(['shortest'], $attempts,
            'allowFallback=false tries EXACTLY the requested template — the hash contract holds');
    }

    public function test_all_templates_refused_bubbles_the_last_refusal(): void
    {
        $resolver = $this->resolverWith(function ($scope, $ctx, $year, $template) {
            throw new PlanRefused("refused: {$template}");
        });

        try {
            $resolver->planWithFallback('scope', [], 2023, 'shortest', true);
            $this->fail('an exhausted ladder must throw for the review list');
        } catch (PlanRefused $e) {
            $this->assertSame('refused: community_cells', $e->getMessage(),
                'the LAST refusal (registry tail) is the surviving review reason');
        }
    }

    public function test_components_rescues_a_scope_every_cutting_template_refused(): void
    {
        $attempts = [];
        $resolver = $this->resolverWith(function ($scope, $ctx, $year, $template) use (&$attempts) {
            $attempts[] = $template;
            if ($template !== 'components') {
                throw new PlanRefused("refused: {$template}");
            }

            return ['plan_hash' => 'h', 'districts' => [], 'template' => $template];
        });

        $out = $resolver->planWithFallback('scope', [], 2023, 'shortest', true);

        $this->assertSame(
            ['shortest', 'vertical_strips', 'horizontal_strips', 'community_cells', 'components'],
            $attempts,
            'components rides LAST — the ladder reaches it only after every cutting template refused'
        );
        $this->assertSame('components', $out['template']);
        $this->assertTrue($out['fallback']);
    }

    public function test_requested_template_leads_even_from_mid_registry(): void
    {
        $attempts = [];
        $resolver = $this->resolverWith(function ($scope, $ctx, $year, $template) use (&$attempts) {
            $attempts[] = $template;

            return ['plan_hash' => 'h', 'districts' => [], 'template' => $template];
        });

        $out = $resolver->planWithFallback('scope', [], 2023, 'community_cells', true);

        $this->assertSame(['community_cells'], $attempts, 'the requested template is always tried first');
        $this->assertFalse($out['fallback']);
    }
}
