<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalEngine;
use App\Services\Districting\LeafGiantResolver;
use App\Services\Districting\PlanRefused;
use App\Services\Districting\SubdivisionAutoseedService;
use App\Services\DistrictingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * PIN — ONE DistrictingService PER LEAF SCOPE (fix set 2026-09-02).
 *
 * LeafGiantResolver::commit runs inside withScopeService: for the length of
 * one leaf scope every app(DistrictingService::class) resolution (each
 * piece's context() through ManualDistrictDraw, the filed-scope beat) is the
 * same instance, so the parent giant cascade runs once per scope instead of
 * once per piece. The instance is forgotten when the scope finishes or
 * fails. DistrictingService is NOT bound shared in the container: a
 * process-lifetime instance carried one scope's step timings onto the next
 * scope's ledger row (the controller beats scope_start before commit) and
 * pinned population memos across population writes (the Bayern 70/71 class).
 *
 * A pending PlanRefused is never replaced by the scope-parts cleanup in
 * commit()'s finally: under an aborted outer transaction the cleanup's own
 * statement fails (25P02) and the primary template's diagnosis must stand.
 *
 * Pure and mocked: the test connection is sqlite :memory:; every statement
 * is intercepted at the DB facade.
 */
class LeafLadderScopeServiceTest extends TestCase
{
    private const CTX = ['floor' => 5, 'ceiling' => 9, 'budget' => 18, 'quota' => 1.0];

    public function test_districting_service_is_not_shared_outside_a_leaf_scope(): void
    {
        $this->assertFalse($this->app->isShared(DistrictingService::class), 'no process-lifetime instance');
        $this->assertNotSame(app(DistrictingService::class), app(DistrictingService::class));
    }

    public function test_every_resolution_inside_a_scope_is_the_same_instance(): void
    {
        $outside = app(DistrictingService::class);

        $seen = LeafGiantResolver::withScopeService(function () {
            $a = app(DistrictingService::class);
            $b = app(DistrictingService::class);
            $this->assertSame($a, $b, 'one instance for the length of the scope');
            $this->assertTrue($this->app->isShared(DistrictingService::class));

            return $a;
        });

        $this->assertInstanceOf(DistrictingService::class, $seen);
        $this->assertNotSame($outside, $seen, 'the scope opens with a fresh instance');
        $this->assertFalse($this->app->isShared(DistrictingService::class), 'the scope service is forgotten when the scope finishes');
        $this->assertNotSame($seen, app(DistrictingService::class));
    }

    public function test_the_scope_service_is_forgotten_when_the_scope_fails(): void
    {
        try {
            LeafGiantResolver::withScopeService(function () {
                $this->assertTrue($this->app->isShared(DistrictingService::class));
                throw new PlanRefused('refused');
            });
            $this->fail('the refusal must bubble');
        } catch (PlanRefused $e) {
            $this->assertSame('refused', $e->getMessage());
        }

        $this->assertFalse($this->app->isShared(DistrictingService::class));
    }

    public function test_an_existing_shared_owner_keeps_its_lifetime(): void
    {
        $mine = new DistrictingService();
        $this->app->instance(DistrictingService::class, $mine);

        $seen = LeafGiantResolver::withScopeService(fn () => app(DistrictingService::class));

        $this->assertSame($mine, $seen, 'a registered owner is reused, not replaced');
        $this->assertSame($mine, app(DistrictingService::class), 'and the scope does not forget it');
    }

    public function test_commit_opens_one_scope_service_and_closes_it(): void
    {
        Log::spy();
        DB::shouldReceive('table')->with('legislatures')->andReturn($this->legislatureLookup('jur-1'));
        DB::shouldReceive('selectOne')->andReturn((object) ['parts' => 3]);   // a multi-part scope: the box leads
        DB::shouldReceive('statement')->andReturnTrue();                       // the parts cleanup DDL
        DB::shouldReceive('delete')->once()->andReturn(0);                     // the parts cleanup

        $inside = null;
        $autoseed = Mockery::mock(SubdivisionAutoseedService::class);
        $autoseed->shouldReceive('openBladePool')->once()->with('s1');
        $autoseed->shouldReceive('closeBladePool')->once();
        $autoseed->shouldReceive('bladeBudgetRemaining')->andReturn(240);
        $autoseed->shouldReceive('plan')->once()->with('s1', self::CTX, 2023, SubdivisionAutoseedService::TEMPLATE_BOX)
            ->andReturnUsing(function () use (&$inside) {
                $inside = app(DistrictingService::class);
                $this->assertSame($inside, app(DistrictingService::class), 'every resolution inside the scope is the same instance');
                $this->assertTrue($this->app->isShared(DistrictingService::class));

                return ['plan_hash' => 'h', 'template' => 'box', 'districts' => [], 'total_pop' => 0];
            });

        $resolver = new LeafGiantResolver($autoseed, Mockery::mock(ConstitutionalEngine::class));
        $result = $resolver->commit('leg-1', 's1', 'map-1', null, self::CTX, 2023, SubdivisionAutoseedService::TEMPLATE_SHORTEST, false, null);

        $this->assertSame(0, $result['districts_created']);
        $this->assertSame(SubdivisionAutoseedService::TEMPLATE_BOX, $result['template']);
        $this->assertInstanceOf(DistrictingService::class, $inside);
        $this->assertFalse($this->app->isShared(DistrictingService::class), 'commit() forgets the scope service when the scope finishes');
        $this->assertNotSame($inside, app(DistrictingService::class));
    }

    public function test_a_failed_parts_cleanup_never_replaces_the_scope_diagnosis(): void
    {
        Log::spy();
        DB::shouldReceive('table')->with('legislatures')->andReturn($this->legislatureLookup('jur-1'));
        DB::shouldReceive('selectOne')->andReturn((object) ['parts' => 1]);   // the cutting ladder, box last
        // The outer per-scope transaction is aborted after the failed plan
        // statement: the cleanup's own DDL answers 25P02.
        DB::shouldReceive('statement')->andThrow(new QueryException(
            'pgsql',
            'CREATE TEMP TABLE IF NOT EXISTS cga_scope_parts',
            [],
            new \RuntimeException('SQLSTATE[25P02]: In failed sql transaction: current transaction is aborted'),
        ));
        DB::shouldReceive('delete')->never();

        $autoseed = Mockery::mock(SubdivisionAutoseedService::class);
        $autoseed->shouldReceive('openBladePool')->once()->with('s1');
        $autoseed->shouldReceive('closeBladePool')->once();
        $autoseed->shouldReceive('bladeBudgetRemaining')->andReturn(240);
        $autoseed->shouldReceive('plan')->with('s1', self::CTX, 2023, SubdivisionAutoseedService::TEMPLATE_SHORTEST)
            ->andThrow(new PlanRefused('NoContiguousCut: the primary diagnosis'));
        $autoseed->shouldReceive('plan')->with('s1', self::CTX, 2023, Mockery::any())
            ->andThrow(new PlanRefused('a later rung'));

        $resolver = new LeafGiantResolver($autoseed, Mockery::mock(ConstitutionalEngine::class));
        try {
            $resolver->commit('leg-1', 's1', 'map-1', null, self::CTX, 2023, SubdivisionAutoseedService::TEMPLATE_SHORTEST, false, null);
            $this->fail('every rung refused: the scope must land on the review list');
        } catch (PlanRefused $e) {
            $this->assertSame('NoContiguousCut: the primary diagnosis', $e->getMessage(), 'first failure wins; the cleanup failure never replaces it');
        }

        Log::shouldHaveReceived('debug')->once();
        $this->assertFalse($this->app->isShared(DistrictingService::class), 'the scope service is forgotten on failure too');
    }

    public function test_the_leaf_timers_are_safe_whatever_the_collector_exposes(): void
    {
        // Outside a scope there is no shared collector: the timers are no-ops
        // and build no instance.
        LeafGiantResolver::stepBegin('leaf.test');
        LeafGiantResolver::stepEnd('leaf.test');
        $this->assertFalse($this->app->isShared(DistrictingService::class));

        $record = LeafGiantResolver::withScopeService(function () {
            LeafGiantResolver::stepBegin('leaf.test');
            LeafGiantResolver::stepEnd('leaf.test');

            return (new \ReflectionProperty(DistrictingService::class, 'stepMs'))->getValue(app(DistrictingService::class));
        });

        $exposed = (new \ReflectionMethod(DistrictingService::class, 'stepBegin'))->isPublic()
            && (new \ReflectionMethod(DistrictingService::class, 'stepEnd'))->isPublic();
        if ($exposed) {
            $this->assertArrayHasKey('leaf.test', $record, 'a public collector records the leaf label on the scope service');
        } else {
            $this->assertSame([], $record, 'a private collector is never touched by the leaf timers');
        }
    }

    private function legislatureLookup(string $jurisdictionId): object
    {
        $builder = Mockery::mock();
        $builder->shouldReceive('where')->andReturnSelf();
        $builder->shouldReceive('value')->with('jurisdiction_id')->andReturn($jurisdictionId);

        return $builder;
    }
}
