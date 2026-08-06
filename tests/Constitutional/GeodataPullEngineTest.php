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

            // Once the boundary pool is empty a boundaries-phase lane FALLS
            // THROUGH to raster_iso (PHASE_FALLTHROUGH, the 2026-08-02 ingest
            // overlap) so rasters load concurrently — the raster is claimable
            // now, not held to the rasters phase. (This pin predated the
            // overlap and asserted null; corrected to the intended behavior.)
            $next = GeodataClaims::next($run->fresh(), (string) Str::uuid());
            $this->assertSame('raster_iso', $next['kind'] ?? null);
            $this->assertSame('RAS', $next['iso_code'] ?? null);
        });
    }

    public function test_ingest_review_waits_for_both_then_isolates_and_advances(): void
    {
        // THE GROUP GATE LAW (operator, definitive 2026-08-05):
        //   BOUNDARIES + RASTERS → REVIEW → RESOLVE + ATTRIBUTION → …
        // The ingest review fires only after BOTH boundaries and rasters drain,
        // runs isolated (resolve is gated), and only then does resolve begin.
        // No half-laning while rasters still run; resolve never starts early.
        $this->onLivePg(function () {
            Queue::fake();
            $run = $this->makeRun('boundaries');
            $bnd = $this->addItem($run, 'boundary_iso', ['iso_code' => 'AAA']);
            $can = $this->addItem($run, 'boundary_iso', ['iso_code' => 'CAN']);
            $ras = $this->addItem($run, 'raster_iso',   ['iso_code' => 'RAS']);
            $this->addItem($run, 'resolve_global'); // the derive-group singleton

            // Boundaries done, CAN in review, raster STILL running.
            $bnd->update(['status' => 'done']);
            $can->update(['status' => 'review', 'reason' => 'refused']);
            Artisan::call('geodata:pump');
            $run->refresh();
            // Advanced boundaries → rasters, but NOT past the ingest group: the
            // raster is still open, so the review has not fired and — the
            // operator's rule — the field is NOT half-laned while rasters run.
            $this->assertSame('rasters', $run->phase, 'ingest continues at rasters');
            $this->assertNull($run->review_pass, 'no half-lane while rasters still run');
            $this->assertSame('review', $can->fresh()->status, 'CAN not retried until the group drains');

            // Raster finishes → the WHOLE ingest group has drained → the review
            // fires, isolated (resolve gated), requeuing CAN for its retry. It is
            // ONE item, which is <= half the pool, so lanes are NOT halved
            // (operator: halving under half the pool is pointless). The retry is
            // still tracked by the _review_pass stamp.
            $ras->update(['status' => 'done']);
            Artisan::call('geodata:pump');
            $run->refresh();
            $this->assertSame('rasters', $run->phase, 'held at the ingest boundary for the retry');
            $this->assertNull($run->review_pass, 'a 1-item review does NOT half-lane');
            $this->assertArrayHasKey('ingest', $run->phase_timestamps['_review_pass'] ?? [],
                'the retry is tracked by the group stamp');
            $this->assertSame('pending', $can->fresh()->status, 'CAN requeued, isolated');

            // While CAN is still RETRYING (running), the gate HOLDS — resolve
            // must not start mid-review. This is the bug that stranded lanes.
            $can->fresh()->update(['status' => 'running']);
            Artisan::call('geodata:pump');
            $this->assertSame('rasters', $run->fresh()->phase, 'holds while the retry is in flight');

            // CAN clears → cross into resolve at FULL lanes.
            $can->fresh()->update(['status' => 'done']);
            Artisan::call('geodata:pump');
            $this->assertSame('resolving', $run->fresh()->phase);
            $this->assertNull($run->fresh()->review_pass, 'full lanes into resolve');
        });
    }

    public function test_review_halves_lanes_only_when_residue_exceeds_half_the_pool(): void
    {
        // Operator, 2026-08-05: halve ONLY when the review count is MORE than
        // half the pool; at half or fewer it is pointless (the items cannot even
        // fill half the lanes) so full lanes stand. With no worker leases the
        // pump reads the nominal pool of 10, so the threshold is > 5.
        $this->onLivePg(function () {
            Queue::fake();
            $run = $this->makeRun('rasters');
            $this->addItem($run, 'raster_iso', ['iso_code' => 'RAS', 'status' => 'done']);
            // Six boundaries in review — 6 > 5 → HALF LANES.
            foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $iso) {
                $this->addItem($run, 'boundary_iso', ['iso_code' => $iso, 'status' => 'review']);
            }
            $this->addItem($run, 'resolve_global');

            Artisan::call('geodata:pump');
            $this->assertSame('ingest', $run->fresh()->review_pass, '6 > half of 10 → half lanes');
        });
    }

    public function test_ingest_review_failure_holds_for_operator_then_continue_advances(): void
    {
        // When the isolated retry cannot clear the residue, the run does NOT
        // silently cross into resolve — it HOLDS for the operator (Retry /
        // Continue). Continue accepts the residue and advances.
        $this->onLivePg(function () {
            Queue::fake();
            $run = $this->makeRun('rasters');   // sitting at the ingest boundary
            $can = $this->addItem($run, 'boundary_iso', ['iso_code' => 'CAN', 'status' => 'review']);
            $this->addItem($run, 'boundary_iso', ['iso_code' => 'AAA', 'status' => 'done']);
            $this->addItem($run, 'raster_iso',   ['iso_code' => 'RAS', 'status' => 'done']);
            $this->addItem($run, 'resolve_global');

            // Group drained + residue → fire the isolated retry (requeue). One
            // item → full lanes; the retry is tracked by the group stamp.
            Artisan::call('geodata:pump');
            $this->assertSame('pending', $can->fresh()->status);
            $this->assertNull($run->fresh()->review_pass, '1-item review runs full lanes');
            $this->assertArrayHasKey('ingest', $run->fresh()->phase_timestamps['_review_pass'] ?? []);

            // Retry fails again → the SERIAL pass fires first (operator ruling
            // 2026-08-06, run 019fd562: residue that only dies in company gets
            // one round ALONE — "one at a time back to back" — before anyone
            // is woken). One item per tick; no hold while rungs remain.
            $can->fresh()->update(['status' => 'review', 'reason' => 'again']);
            Artisan::call('geodata:pump');
            $run->refresh();
            $this->assertSame('rasters', $run->phase, 'still held at the ingest boundary');
            $this->assertSame('pending', $can->fresh()->status, 'serial pass requeues it alone');
            $this->assertSame(1,
                (int) ($run->phase_timestamps['_review_serial']['ingest']['fired'] ?? 0),
                'the serial ladder rung is recorded');
            $this->assertArrayNotHasKey('ingest', $run->phase_timestamps['_review_hold'] ?? [],
                'no operator hold while the serial ladder still has rungs');

            // Fails EVEN ALONE → now hold for the operator, no silent advance.
            $can->fresh()->update(['status' => 'review', 'reason' => 'again2']);
            Artisan::call('geodata:pump');
            $run->refresh();
            $this->assertSame('rasters', $run->phase, 'still held at the ingest boundary');
            $this->assertArrayHasKey('ingest', $run->phase_timestamps['_review_hold'] ?? [],
                'the operator hold is recorded per GROUP');

            // Continue → accept the residue (what pull-control stamps) → advance.
            $stamps = $run->fresh()->phase_timestamps;
            $stamps['_accepted']['ingest'] = now()->toIso8601String();
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
            // Pre-stamp the INGEST group auto-retry as already spent, so the
            // group review gate holds for the operator rather than requeuing the
            // review/failed items (which would zero the very counters this pins).
            // This is the realistic "awaiting operator" state — residue present,
            // counters reflect it.
            $run = $this->makeRun('boundaries', [
                'phase_timestamps' => [
                    '_review_pass'   => ['ingest' => now()->toIso8601String()],
                    // The serial ladder too (2026-08-06): marked SPENT, so the
                    // gate holds for the operator instead of requeueing one
                    // residue item per tick — which would zero the very
                    // counters this test pins.
                    '_review_serial' => ['ingest' => ['total' => 2, 'fired' => 2]],
                ],
            ]);
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
