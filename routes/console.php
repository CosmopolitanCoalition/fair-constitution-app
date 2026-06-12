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

// Keep Horizon's dashboard metrics fresh.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
