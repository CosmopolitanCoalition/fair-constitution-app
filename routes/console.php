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

// ── Autoscale pump (pull engine, 2026-07-19) ─────────────────────────────
// THE run's only liveness root. The old self-rescheduling orchestrator tick
// chain died ≥5 times across four runs (horizon crash kills the worker AND
// its successor payload; tries=1 turns any exception into a dead chain) —
// and its watchdog lived in this same file but couldn't be trusted to
// revive a chain whose whole design was the problem. The pump replaces
// both: every minute it advances phases, reclaims stale item/scope claims,
// tops the fixed worker pool back up, and refreshes counters — each duty
// idempotent and seconds-long. If everything else crashes, the next minute
// heals it. runInBackground so a slow pump never delays EvaluateClocksJob;
// withoutOverlapping(10) so a killed pump can't wedge the lock for 24 h.
Schedule::command('autoscale:pump')
    ->everyMinute()->withoutOverlapping(10)->runInBackground()->onOneServer();

// ── Simulated-world pump (Phase O populate engine, 2026-07-25) ───────────
// The same pattern, third instance (autoscale → geodata plan → simworld).
// A populate run's ONLY liveness root: phase advance, stale-claim reclaim,
// pg-crash breaker, lease cull, counters. Every duty idempotent and
// seconds-long, so a missed tick costs a minute and a double tick costs
// nothing. Phase advance lives HERE and nowhere else — a worker that can
// advance a phase can advance it twice. No-ops in ~1 query when no run is
// live, so it is free to leave scheduled on every instance.
Schedule::command('sim:pump')
    ->everyMinute()->withoutOverlapping(10)->runInBackground()->onOneServer();

// ── THE MULTITHREADED CHAIN (operator ruling 2026-08-29) ─────────────────
// A completed official-source download hands off to the MULTITHREADED pull
// engine via control/chain_pull.json — the legacy single-threaded seeder is
// unreachable from the download flow. This tick consumes the marker and
// starts the pull run; guarded, idempotent, ~1 stat call when idle.
Schedule::command('geodata:chain-download')
    ->everyMinute()->withoutOverlapping(10)->runInBackground()->onOneServer();

// ── Geodata pull-engine pump (GEODATA_PULL_ENGINE_PLAN.md, 2026-07-20) ────
// The same pattern, applied to the ETL: a geodata run's DB-side liveness
// root — stale-claim reclaim, pg-crash breaker, phase advance (never in
// workers), lease cull, counters, acceptance-scan dispatch, completion. The
// Python supervisor owns the worker pool; this owns the run state. No-ops in
// ~1 query when no run is live, so it is free to leave scheduled everywhere.
Schedule::command('geodata:pump')
    ->everyMinute()->withoutOverlapping(10)->runInBackground()->onOneServer();

// ── Step 3 dashboard snapshot (2026-09-03, the post-reboot disk storm) ────
// Keeps the heavy district-progress aggregates warm off the poll path so a
// page poll never runs the 12-to-18 s ledger scan itself and never trips the
// page's 15 s abort. Viewer-gated: SetupController::refreshProgressSnapshot
// no-ops in one cache read when nobody has the dashboard open, so a closed
// page adds nothing to the run. runInBackground so the scan never delays the
// clock job; withoutOverlapping so a slow scan can't stack.
Schedule::command('autoscale:progress-snapshot')
    ->everyMinute()->withoutOverlapping(5)->runInBackground()->onOneServer();

// ── WI-B3: daily approval standings rollup (ESM-04) ─────────────────────
// Public approval standings aggregate ONCE A DAY per race (Earth-scale
// rule — never per request, never per approval; identities never leave
// the approvals table). One chain entry per race per rollup.
Schedule::job(new ApprovalStandingsRollupJob)->dailyAt('00:10')->withoutOverlapping()->onOneServer();
// W4 ⑥ — daily ranked-standings rollup (out-of-band decrypt; the ballot page reads its cache).
Schedule::job(new \App\Jobs\Elections\RankedStandingsRollupJob)->dailyAt('00:15')->withoutOverlapping()->onOneServer();

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

// ── Phase I: the nightly REACH snapshot ─────────────────────────────────
// reach = verified residents ÷ population estimate, per place per day. An
// ordinary scheduled job, deliberately OUTSIDE the CLK registry — a gauge, not
// a constitutional clock; a missed night just leaves it a day stale.
//
// onOneServer() elects one tick-leader per cluster and the job's own
// LeaderProbe refuses to write from a demoted replica. Those are the HA axis.
// CI-6 ("only the authoritative instance writes a snapshot") is a SEPARATE,
// per-jurisdiction filter inside the service — a mirror runs its own scheduler
// and wins its own probe, so the scheduler gate alone would let it write for
// places it does not own. Authority is not leadership.
Schedule::job(new \App\Jobs\SnapshotLegitimacyJob)->dailyAt('00:40')->withoutOverlapping()->onOneServer();

// ── The nightly world rollup that feeds the Atlas (lane 4, W4①) ─────────
// 00:50 keeps the nightly stagger clear (00:10 approval standings, 00:20
// department reports, 00:25 co-determination, 00:30 social structure, 00:40
// reach) and lands AFTER the reach snapshot on purpose: the Atlas's planet
// reach total is a sum over that night's snapshot rows, so running first would
// publish yesterday's figure. Both gates apply here for the same reason spelled
// out above — onOneServer() + LeaderProbe for HA, and the per-jurisdiction
// authoritative_server_id filter inside WorldStatsService for CI-6.
Schedule::job(new \App\Jobs\SnapshotWorldStatsJob)->dailyAt('00:50')->withoutOverlapping()->onOneServer();

// Keep Horizon's dashboard metrics fresh.
Schedule::command('horizon:snapshot')->everyFiveMinutes()->onOneServer();
