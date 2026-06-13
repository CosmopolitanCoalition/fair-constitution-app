<?php

use App\Jobs\ApprovalStandingsRollupJob;
use App\Jobs\EvaluateClocksJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── WI-6: constitutional clock scheduler ─────────────────────────────────
// Run by the `scheduler` container (`php artisan schedule:work`). The
// every-minute sweep fires due clock_timers and evaluates the threshold
// clocks (CLK-05 residency, CLK-06 critical population); the job lands on
// the default Horizon queue. withoutOverlapping guards against a slow
// sweep stacking onto the next minute.
Schedule::job(new EvaluateClocksJob)->everyMinute()->withoutOverlapping();

// ── WI-B3: daily approval standings rollup (ESM-04) ─────────────────────
// Public approval standings aggregate ONCE A DAY per race (Earth-scale
// rule — never per request, never per approval; identities never leave
// the approvals table). One chain entry per race per rollup.
Schedule::job(new ApprovalStandingsRollupJob)->dailyAt('00:10')->withoutOverlapping();

// ── Phase D (D-5): nightly department-report cadence sweep ──────────────
// Reporting cadence is CHARTER data, not a constitutional clock — plain
// due_on + sweep (due → overdue); justified deferral from clock_timers.
Schedule::job(new \App\Jobs\Executive\DepartmentReportSweepJob)->dailyAt('00:20')->withoutOverlapping();

// ── Phase D (D-O4): nightly co-determination sweep (Art. III §6) ────────
// The CLK-05/06 pattern: the event-driven RecomputeWorkerHeadcountJob is
// the cheap path; this sweep re-evaluates every employer with an armed
// CLK-13/14 watcher (covers threshold LOWERING by act) and runs the 48h
// org-board election auto-certify backstop.
Schedule::job(new \App\Jobs\Organizations\EvaluateCoDeterminationJob)->dailyAt('00:25')->withoutOverlapping();

// Keep Horizon's dashboard metrics fresh.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
