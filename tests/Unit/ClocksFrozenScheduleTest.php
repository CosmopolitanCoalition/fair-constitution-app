<?php

namespace Tests\Unit;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * CGA_CLOCKS_FROZEN (operator order 2026-11-01). A frozen box does not schedule
 * the every-minute clock sweep. DB-free: the schedule event's skip filter is the seam.
 */
class ClocksFrozenScheduleTest extends TestCase
{
    private function sweepEvent()
    {
        foreach (app(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->description, 'EvaluateClocksJob')
                || str_contains($event->command ?? '', 'EvaluateClocksJob')) {
                return $event;
            }
        }
        $this->fail('The EvaluateClocksJob schedule event was not found.');
    }

    public function test_the_sweep_runs_by_default(): void
    {
        config(['cga.clocks_frozen' => false]);

        $this->assertTrue($this->sweepEvent()->filtersPass($this->app));
    }

    public function test_the_sweep_is_skipped_when_clocks_are_frozen(): void
    {
        config(['cga.clocks_frozen' => true]);

        $this->assertFalse($this->sweepEvent()->filtersPass($this->app));
    }
}
