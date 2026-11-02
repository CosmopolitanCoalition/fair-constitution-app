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

    private function events(array $needles): array
    {
        $found = [];
        foreach (app(Schedule::class)->events() as $event) {
            $name = (string) $event->description.' '.($event->command ?? '');
            foreach ($needles as $needle) {
                if (str_contains($name, $needle)) {
                    $found[$needle] = $event;
                }
            }
        }
        $this->assertSame(count($needles), count($found), 'Missing schedule events: '.implode(', ', array_diff($needles, array_keys($found))));

        return $found;
    }

    public function test_daily_game_sweeps_are_skipped_only_when_the_world_is_frozen(): void
    {
        $daily = $this->events([
            'ApprovalStandingsRollupJob', 'RankedStandingsRollupJob', 'DepartmentReportSweepJob',
            'EvaluateCoDeterminationJob', 'EvaluateSocialStructureJob', 'SnapshotLegitimacyJob',
            'SnapshotWorldStatsJob', 'achievements:sweep',
        ]);

        config(['cga.clocks_frozen' => false]);
        foreach ($daily as $name => $event) {
            $this->assertTrue($event->filtersPass($this->app), $name.' should run on a live world');
        }

        config(['cga.clocks_frozen' => true]);
        foreach ($daily as $name => $event) {
            $this->assertFalse($event->filtersPass($this->app), $name.' should skip on a frozen world');
        }
    }

    public function test_engine_pumps_are_skipped_only_when_switched_off(): void
    {
        $pumps = $this->events([
            'autoscale:pump', 'provision:pump', 'sim:pump', 'geodata:chain-download',
            'geodata:pump', 'autoscale:progress-snapshot', 'MaintainSimulationStatisticsJob',
        ]);

        config(['cga.engine_pumps' => true]);
        foreach ($pumps as $name => $event) {
            $this->assertTrue($event->filtersPass($this->app), $name.' should run by default');
        }

        config(['cga.engine_pumps' => false]);
        foreach ($pumps as $name => $event) {
            $this->assertFalse($event->filtersPass($this->app), $name.' should skip when pumps are off');
        }
    }

    public function test_the_warm_up_is_scheduled_and_runs_only_after_a_postgres_restart(): void
    {
        $this->events(['db:warm-hot --if-restarted']);

        $src = file_get_contents(app_path('Console/Commands/DbWarmHotCommand.php'));
        $this->assertStringContainsString('pg_postmaster_start_time()', $src);
        $this->assertStringContainsString('lastWarmedStart() === $postmasterStart', $src);
        $this->assertStringContainsString("'users_operator_idx'", $src);
    }
}
