<?php

namespace Tests\Unit;

use App\Services\Maps\GeojsonPrewarmPlanner;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The bounded GeoJSON prewarm (WoS beta, 2026-09-17): the ledger
 * classification, DB-free. plan() and build() read Postgres and are exercised
 * on the live box (`geojson:prewarm --status` after a boot). No geometry is
 * read, counted or changed by the planner.
 */
class GeojsonPrewarmPlannerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_the_ledger_classifies_done_failed_running_and_lost_units(): void
    {
        $p = new GeojsonPrewarmPlanner;
        $units = [];
        foreach (['done', 'failed', 'running', 'lost', 'pending'] as $name) {
            $units[] = ['leg' => 'L', 'scope' => $name, 'zoom' => 3];
        }
        $p->recordPlan($units);
        $k = static fn (string $scope) => GeojsonPrewarmPlanner::unitKey('L', $scope, 3);

        $p->mark($k('done'), 'started');
        $p->mark($k('done'), 'done', ['built' => 4]);
        $p->mark($k('failed'), 'started');
        $p->mark($k('failed'), 'failed');
        $p->mark($k('running'), 'started');
        // A unit that started long ago and never reported: the worker was killed.
        Cache::put(GeojsonPrewarmPlanner::UNIT_PREFIX.$k('lost'), ['started' => now()->subHour()->toIso8601String()], 3600);

        $st = $p->status();
        $this->assertSame(['planned' => 5, 'pending' => 1, 'running' => 1, 'done' => 1, 'failed' => 1, 'lost' => 1], $st['counts']);
        $this->assertSame([$k('lost')], $st['lost']);
        $this->assertSame([$k('failed')], $st['failed']);
    }

    public function test_a_new_plan_replaces_the_old_ledger(): void
    {
        $p = new GeojsonPrewarmPlanner;
        $p->recordPlan([['leg' => 'L', 'scope' => 'x', 'zoom' => 3]]);
        $p->mark(GeojsonPrewarmPlanner::unitKey('L', 'x', 3), 'done');
        $p->recordPlan([['leg' => 'L', 'scope' => 'x', 'zoom' => 3]]);
        $this->assertSame(1, $p->status()['counts']['pending'], 'the unit is pending again after a re-plan');
    }
}
