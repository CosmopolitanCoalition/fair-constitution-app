<?php

namespace Tests\Constitutional;

use App\Jobs\GeodataAcceptanceScanJob;
use App\Models\GeodataItem;
use App\Models\GeodataRun;
use App\Support\GeodataClaims;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * PIN — GEODATA PULL ENGINE (GEODATA_PULL_ENGINE_PLAN.md §8). Exercises the
 * claim ladder + pump mechanics on SYNTHETIC geodata_items — NO real ETL
 * execution — so the pins hold on any dev/CI box. Mirrors AutoscalePinTest's
 * live-pg-in-a-rolled-back-transaction harness.
 *
 * Pins: (1) claim order is largest-first (position ASC); (2) the phase barrier
 * gates on pending+running only — review/failed never block the advance;
 * (3) stale claims reclaim; (4) halt/resume round-trips; (5) the pg-crash
 * breaker pauses claims; (6) the requeue recipe resets a settled item to the
 * head; (7) counters refresh; (8) a full ladder reaches done, dispatches the
 * acceptance scan, and appends the completion audit.
 */
class GeodataPullEngineTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_geodata_pin';

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        // The pump serves the OLDEST unfinished run (the supersede dedupe),
        // so a box with a REAL ingestion in flight makes every synthetic-run
        // pin read the live run's phase instead of its own — the autoscale
        // pins' "needs a quiet box" posture applies here too.
        $liveRun = DB::table('geodata_runs')
            ->whereIn('status', ['running', 'halted'])
            ->exists();
        if ($liveRun) {
            DB::setDefaultConnection($original);
            $this->markTestSkipped('geodata pins need a box with no live geodata run (the pump serves the oldest unfinished run).');
        }

        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }

    private function makeRun(string $phase = 'enumerating', array $attrs = []): GeodataRun
    {
        return GeodataRun::create(array_merge([
            'status' => 'running',
            'phase'  => $phase,
        ], $attrs));
    }

    private function addItem(GeodataRun $run, string $kind, array $attrs = []): GeodataItem
    {
        return GeodataItem::create(array_merge([
            'run_id'   => $run->id,
            'kind'     => $kind,
            'status'   => 'pending',
            'position' => 0,
        ], $attrs));
    }

    public function test_claims_are_largest_first_by_position(): void
    {
        $this->onLivePg(function () {
            $run = $this->makeRun('boundaries');
            $this->addItem($run, 'boundary_iso', ['iso_code' => 'AAA', 'position' => 5]);
            $this->addItem($run, 'boundary_iso', ['iso_code' => 'BBB', 'position' => 1]);
            $this->addItem($run, 'boundary_iso', ['iso_code' => 'CCC', 'position' => 3]);

            $order = [];
            while ($claim = GeodataClaims::next($run->fresh(), (string) Str::uuid())) {
                $order[] = $claim['iso_code'];
            }

            // position 1 (BBB) < 3 (CCC) < 5 (AAA) — heaviest (assigned position
            // by est_cost DESC at enumeration → lowest position) first.
            $this->assertSame(['BBB', 'CCC', 'AAA'], $order);
        });
    }

    public function test_claim_only_returns_the_current_phase_kind(): void
    {
        $this->onLivePg(function () {
            $run = $this->makeRun('boundaries');
            $this->addItem($run, 'raster_iso', ['iso_code' => 'RAS']); // wrong phase
            $this->addItem($run, 'boundary_iso', ['iso_code' => 'BND']);

            $claim = GeodataClaims::next($run, (string) Str::uuid());
            $this->assertSame('boundary_iso', $claim['kind']);
            $this->assertSame('BND', $claim['iso_code']);

            // The raster item stays pending until the rasters phase.
            $this->assertNull(GeodataClaims::next($run->fresh(), (string) Str::uuid()));
        });
    }

    public function test_review_gate_holds_the_phase_then_retries_and_advances(): void
    {
        // THE GATE LAW (operator, 2026-08-05, replacing the old "review does not
        // block"): a review-bearing phase must NOT advance over review/failed
        // residue. Its one automatic half-lane retry runs IN-PHASE (where the
        // items are still claimable), and only a clean pool — or an operator
        // Continue — lets the next phase begin. The old behavior advanced to
        // `resolving` with Canada still in review, then requeued it into a phase
        // no worker could claim it in: the live deadlock this law prevents.
        $this->onLivePg(function () {
            Queue::fake();
            $run = $this->makeRun('boundaries');
            $a = $this->addItem($run, 'boundary_iso', ['iso_code' => 'AAA']);
            $b = $this->addItem($run, 'boundary_iso', ['iso_code' => 'BBB']);
            $this->addItem($run, 'resolve_global'); // the next phase's singleton

            // Still pending → barrier stays shut.
            Artisan::call('geodata:pump');
            $this->assertSame('boundaries', $run->fresh()->phase);

            // One done, one REVIEW (a refused ISO). Pool has no pending/running,
            // but the gate HOLDS at boundaries and fires the auto-retry: the
            // review item is requeued to pending at half lanes, IN-PHASE.
            $a->update(['status' => 'done']);
            $b->update(['status' => 'review', 'reason' => 'refused']);
            Artisan::call('geodata:pump');
            $this->assertSame('boundaries', $run->fresh()->phase, 'must not advance over a review item');
            $this->assertSame('boundaries', $run->fresh()->review_pass, 'auto-retry runs at half lanes, in-phase');
            $this->assertSame('pending', $b->fresh()->status, 'the review item is requeued, not stranded');

            // The retry succeeds (CAN clears when it runs alone) → pool clean →
            // NOW the phase advances to resolving.
            $b->fresh()->update(['status' => 'done']);
            Artisan::call('geodata:pump');
            $this->assertSame('resolving', $run->fresh()->phase);
            $this->assertNull($run->fresh()->review_pass, 'full lanes restored once clear');
        });
    }

    public function test_review_retry_failure_holds_for_operator_then_continue_advances(): void
    {
        // When the one auto-retry cannot clear the residue, the run does NOT
        // silently advance (the old bug) — it HOLDS for the operator, surfacing
        // Retry / Continue. Continue accepts the residue and advances.
        $this->onLivePg(function () {
            Queue::fake();
            $run = $this->makeRun('boundaries');
            $a = $this->addItem($run, 'boundary_iso', ['iso_code' => 'AAA']);
            $b = $this->addItem($run, 'boundary_iso', ['iso_code' => 'BBB']);
            $this->addItem($run, 'resolve_global');

            $a->update(['status' => 'done']);
            $b->update(['status' => 'review', 'reason' => 'refused']);

            // Tick 1: auto-retry requeues b → pending, holds.
            Artisan::call('geodata:pump');
            $this->assertSame('pending', $b->fresh()->status);

            // The retry fails again (b back to review) → tick holds for operator.
            $b->fresh()->update(['status' => 'review', 'reason' => 'refused again']);
            Artisan::call('geodata:pump');
            $run->refresh();
            $this->assertSame('boundaries', $run->phase, 'still held — no silent advance');
            $this->assertArrayHasKey('boundaries', $run->phase_timestamps['_review_hold'] ?? [],
                'the operator hold is recorded');

            // Operator presses Continue → accepts the residue. The pull-control
            // endpoint stamps _accepted[phase]; assert the GATE honors it and
            // advances (the endpoint wiring itself is a thin marker write).
            $stamps = $run->fresh()->phase_timestamps;
            $stamps['_accepted']['boundaries'] = now()->toIso8601String();
            $run->forceFill(['phase_timestamps' => $stamps, 'updated_at' => now()])->save();
            Artisan::call('geodata:pump');
            $this->assertSame('resolving', $run->fresh()->phase);
        });
    }

    public function test_stale_running_claim_is_reclaimed(): void
    {
        $this->onLivePg(function () {
            $run = $this->makeRun('boundaries');
            $item = $this->addItem($run, 'boundary_iso', ['iso_code' => 'AAA', 'status' => 'running']);
            // Backdate the claim past the 30-min stale bound.
            DB::table('geodata_items')->where('id', $item->id)
                ->update(['updated_at' => now()->subHour(), 'claim_token' => (string) Str::uuid()]);

            Artisan::call('geodata:pump');

            $reclaimed = GeodataItem::find($item->id);
            $this->assertSame('pending', $reclaimed->status);
            $this->assertNull($reclaimed->claim_token);
        });
    }

    public function test_halt_and_resume_round_trip(): void
    {
        $this->onLivePg(function () {
            $run = $this->makeRun('boundaries');
            $this->addItem($run, 'boundary_iso', ['iso_code' => 'AAA']);

            // Halt requested → pump parks the run; claims stop.
            $run->update(['halt_requested_at' => now()]);
            Artisan::call('geodata:pump');
            $this->assertSame('halted', $run->fresh()->status);
            $this->assertNull(GeodataClaims::next($run->fresh(), (string) Str::uuid()));

            // Resume (flag cleared) → pump re-enters running; claims resume.
            $run->fresh()->update(['halt_requested_at' => null]);
            Artisan::call('geodata:pump');
            $this->assertSame('running', $run->fresh()->status);
            $this->assertNotNull(GeodataClaims::next($run->fresh(), (string) Str::uuid()));
        });
    }

    public function test_breaker_pauses_claims_on_pg_fingerprint_change(): void
    {
        $this->onLivePg(function () {
            $run = $this->makeRun('boundaries', ['pg_fingerprint' => 'stale-fingerprint|old']);
            $this->addItem($run, 'boundary_iso', ['iso_code' => 'AAA']);

            Artisan::call('geodata:pump'); // detects the fingerprint mismatch
            $fresh = $run->fresh();
            $this->assertTrue($fresh->isPaused());
            $this->assertNull(GeodataClaims::next($fresh, (string) Str::uuid()));
        });
    }

    public function test_requeue_recipe_resets_a_settled_item_to_the_head(): void
    {
        $this->onLivePg(function () {
            $run = $this->makeRun('boundaries');
            $item = $this->addItem($run, 'boundary_iso', [
                'iso_code' => 'AAA', 'status' => 'done', 'position' => 9,
                'finished_at' => now(),
            ]);

            // The plan's requeue recipe (§5): reset settled items to pending, head.
            DB::table('geodata_items')
                ->where('run_id', $run->id)
                ->whereIn('status', ['review', 'failed', 'done'])
                ->update([
                    'status' => 'pending', 'claim_token' => null, 'reason' => null,
                    'started_at' => null, 'finished_at' => null, 'position' => 0,
                    'updated_at' => now(),
                ]);

            $requeued = GeodataItem::find($item->id);
            $this->assertSame('pending', $requeued->status);
            $this->assertSame(0, $requeued->position);
            $this->assertNotNull(GeodataClaims::next($run, (string) Str::uuid()));
        });
    }

    public function test_counters_refresh_from_items(): void
    {
        $this->onLivePg(function () {
            $run = $this->makeRun('boundaries');
            $this->addItem($run, 'boundary_iso', ['status' => 'done']);
            $this->addItem($run, 'boundary_iso', ['status' => 'done']);
            $this->addItem($run, 'boundary_iso', ['status' => 'review']);
            $this->addItem($run, 'boundary_iso', ['status' => 'failed']);

            Artisan::call('geodata:pump');

            $fresh = $run->fresh();
            $this->assertSame(4, $fresh->items_total);
            $this->assertSame(2, $fresh->items_done);
            $this->assertSame(1, $fresh->items_review);
            $this->assertSame(1, $fresh->items_failed);
        });
    }

    public function test_full_ladder_reaches_done_and_dispatches_the_scan(): void
    {
        $this->onLivePg(function () {
            Queue::fake();
            $run = $this->makeRun('enumerating');
            // The manifest item, plus the items it would enumerate.
            $manifest = $this->addItem($run, 'manifest');

            // Manifest runs (done) → pump walks through the (empty) middle
            // phases in one tick and stops at scanning once the scan item is
            // the only thing left.
            $manifest->update(['status' => 'done']);
            $this->addItem($run, 'acceptance_scan');

            Artisan::call('geodata:pump');
            $this->assertSame('scanning', $run->fresh()->phase);
            Queue::assertPushed(GeodataAcceptanceScanJob::class);

            // Scanning liveness: a LOST dispatch (horizon crash) must not wedge
            // the run — every pump tick re-dispatches while the item is pending.
            Artisan::call('geodata:pump');
            Queue::assertPushed(GeodataAcceptanceScanJob::class, 2);

            // The scan job closes its item; the next pump reaches done + audits.
            $auditBefore = DB::table('audit_log')->count();
            DB::table('geodata_items')->where('run_id', $run->id)
                ->where('kind', 'acceptance_scan')->update(['status' => 'done']);
            Artisan::call('geodata:pump');

            $done = $run->fresh();
            $this->assertSame('done', $done->phase);
            $this->assertSame('done', $done->status);
            $this->assertNotNull($done->finished_at);
            $this->assertSame(
                $auditBefore + 1,
                DB::table('audit_log')->count(),
                'exactly one geodata.completed audit entry'
            );
        });
    }
}
