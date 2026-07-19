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
//
// HA (Phase G, Patroni): onOneServer() is the "exactly-one-scheduler-leader"
// guard — a Redis cache lock (CACHE_STORE=redis) so that when the scheduler
// runs on EVERY HA app node, only ONE dispatches each tick. No PHP consensus;
// the lock simply elects a tick-leader. Belt-and-suspenders with the job's
// own LeaderProbe::isPrimary() write-leader gate. On a single node it is a
// no-op (one node always wins the lock).
Schedule::job(new EvaluateClocksJob)->everyMinute()->withoutOverlapping()->onOneServer();

// ── Autoscale tick watchdog (2026-07-19, the overnight-stall lesson) ─────
// The orchestrator's self-rescheduling tick chain can die without ANY
// survivor to revive it: a horizon-container crash kills the tick worker
// AND can lose the pre-scheduled successor payload, and the job-level
// failed() hook only fires for payloads that still exist. Overnight this
// stalled a healthy run for six hours with 47k sweeps pending. The
// scheduler container is a separate process tree that survived — so the
// liveness guarantee lives HERE: any active run whose heartbeat is stale
// gets a fresh tick chain. Idempotent by design (concurrent ticks no-op on
// the per-run advisory lock), so a spurious revive costs two queries.
Schedule::call(function () {
    $stale = \App\Models\AutoscaleRun::query()
        ->whereIn('status', ['queued', 'sizing', 'mapping'])
        ->where('updated_at', '<', now()->subMinutes(10))
        ->get();
    foreach ($stale as $run) {
        \Illuminate\Support\Facades\Log::warning('Autoscale watchdog: reviving stale run', [
            'run_id' => (string) $run->id, 'last_heartbeat' => (string) $run->updated_at,
        ]);
        \App\Jobs\AutoscaleOrchestratorJob::dispatch((string) $run->id);
    }
})->name('autoscale-tick-watchdog')->everyFiveMinutes()->onOneServer();

// ── WI-B3: daily approval standings rollup (ESM-04) ─────────────────────
// Public approval standings aggregate ONCE A DAY per race (Earth-scale
// rule — never per request, never per approval; identities never leave
// the approvals table). One chain entry per race per rollup.
Schedule::job(new ApprovalStandingsRollupJob)->dailyAt('00:10')->withoutOverlapping()->onOneServer();

// ── Phase D (D-5): nightly department-report cadence sweep ──────────────
// Reporting cadence is CHARTER data, not a constitutional clock — plain
// due_on + sweep (due → overdue); justified deferral from clock_timers.
Schedule::job(new \App\Jobs\Executive\DepartmentReportSweepJob)->dailyAt('00:20')->withoutOverlapping()->onOneServer();

// ── Phase D (D-O4): nightly co-determination sweep (Art. III §6) ────────
// The CLK-05/06 pattern: the event-driven RecomputeWorkerHeadcountJob is
// the cheap path; this sweep re-evaluates every employer with an armed
// CLK-13/14 watcher (covers threshold LOWERING by act) and runs the 48h
// org-board election auto-certify backstop.
Schedule::job(new \App\Jobs\Organizations\EvaluateCoDeterminationJob)->dailyAt('00:25')->withoutOverlapping()->onOneServer();

// ── Phase G (G-ID): prune lapsed standing attestations ──────────────────
// They already fail closed on expiry; this keeps the table bounded (minted per
// device, per hour). onOneServer + the write-leader posture as elsewhere.
Schedule::job(new \App\Jobs\Identity\ExpireStandingAttestationsJob)->hourly()->withoutOverlapping()->onOneServer();

// ── Phase K-1 (closeout): daily civic-structure sweep ───────────────────
// Provisions each civically-active jurisdiction's public_square + halls and
// (best-effort) the Matrix topology, and reconciles object subforums to the
// currently-live governance objects. CHARTER cadence, not a constitutional
// clock (the DepartmentReportSweepJob pattern). The on-seating dispatch in
// CertificationService is the event-driven fast path; this is the backstop
// sweep (null ctor arg = ALL STATUS_ACTIVE jurisdictions). A down homeserver
// never fails the sweep — the job is best-effort per jurisdiction.
Schedule::job(new \App\Jobs\EvaluateSocialStructureJob)->dailyAt('00:30')->withoutOverlapping()->onOneServer();

// Keep Horizon's dashboard metrics fresh.
Schedule::command('horizon:snapshot')->everyFiveMinutes()->onOneServer();
