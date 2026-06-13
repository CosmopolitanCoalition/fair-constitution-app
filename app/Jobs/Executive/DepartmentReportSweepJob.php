<?php

namespace App\Jobs\Executive;

use App\Services\Executive\DepartmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Nightly department-report cadence sweep (PHASE_D_DESIGN_executive
 * §A D-5): due_on passed without filing → `overdue`. Deliberately NOT a
 * constitutional clock — the 21-clock registry is a constitutional
 * artifact and reporting cadence is CHARTER data; plain due_on + sweep
 * suffices (justified deferral from clock_timers).
 */
class DepartmentReportSweepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(DepartmentService $departments): void
    {
        $departments->sweepOverdueReports();
    }
}
