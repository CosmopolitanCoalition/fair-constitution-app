<?php

namespace Tests\Constitutional;

use App\Models\SimItem;
use App\Models\SimRun;
use App\Support\SimClaims;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the simulated-world pull engine's mechanics.
 *
 * Mirrors `AutoscalePinTest`'s mechanics coverage, because this engine
 * deliberately mirrors that engine. The invariants below are the distilled
 * lesson of four failed planet-scale runs, and every one of them was learned
 * the expensive way:
 *
 *   · a HALTED run hands out ZERO claims (a halt that leaks work is not a halt)
 *   · a PAUSED run hands out ZERO claims (the pg breaker pauses, never governs)
 *   · a dead worker's claim RETURNS to pending, and the redo is clean
 *   · SKIP LOCKED means two concurrent claimants never take the same unit
 *   · a phase barrier opens only when its pool is empty, and REVIEW/FAILED
 *     count as settled — failures never sink a run
 *   · phase advance happens in the PUMP, never in a worker
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class SimPullEnginePinTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_simpin';

    /**
     * The pump SEEDS WORKERS as one of its duties, and the test queue runs
     * synchronously — so without this a dispatched worker would immediately
     * claim back the very item whose reclaim we are asserting, and the pin
     * would fail for a reason that has nothing to do with reclaiming. These
     * tests measure the pump's bookkeeping; worker execution has its own pins.
     */
    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Queue::fake();
    }

    public function test_halted_and_paused_runs_hand_out_no_work(): void
    {
        $this->onLivePg(function () {
            $run = $this->makeRun(['phase' => 'cohorts']);
            $this->items($run, 'cohort_scope', 3);

            $this->assertNotNull(
                SimClaims::next($run, (string) Str::uuid()),
                'a live run must hand out work'
            );

            // HALT.
            $run->forceFill(['halt_requested_at' => now()])->save();
            $this->assertNull(
                SimClaims::next($run->fresh(), (string) Str::uuid()),
                'a halted run must hand out ZERO claims'
            );

            // Resume, then PAUSE via the breaker.
            $run->forceFill(['halt_requested_at' => null, 'paused_until' => now()->addMinutes(10)])->save();
            $this->assertNull(
                SimClaims::next($run->fresh(), (string) Str::uuid()),
                'a paused run must hand out ZERO claims'
            );

            // A pause in the PAST is not a pause.
            $run->forceFill(['paused_until' => now()->subMinute()])->save();
            $this->assertNotNull(
                SimClaims::next($run->fresh(), (string) Str::uuid()),
                'an expired pause must not keep the engine parked'
            );
        });
    }

    /** Two claimants, one unit each — never the same one. */
    public function test_skip_locked_never_hands_the_same_unit_to_two_workers(): void
    {
        $this->onLivePg(function () {
            $run = $this->makeRun(['phase' => 'cohorts']);
            $this->items($run, 'cohort_scope', 2);

            $a = SimClaims::next($run, (string) Str::uuid());
            $b = SimClaims::next($run, (string) Str::uuid());

            $this->assertNotNull($a);
            $this->assertNotNull($b);
            $this->assertNotSame($a->id, $b->id, 'two workers must never hold the same unit');

            $this->assertNull(
                SimClaims::next($run, (string) Str::uuid()),
                'a drained pool must hand out nothing'
            );
        });
    }

    /** A dead worker's claim comes back, and the redo is clean. */
    public function test_stale_claim_is_reclaimed_by_the_pump(): void
    {
        $this->onLivePg(function () {
            $run = $this->makeRun(['phase' => 'cohorts', 'status' => 'running']);
            $this->items($run, 'cohort_scope', 1);

            $token = (string) Str::uuid();
            $claim = SimClaims::next($run, $token);
            $this->assertNotNull($claim);

            // The worker dies: no heartbeat for 45 minutes.
            DB::table('sim_items')->where('id', $claim->id)
                ->update(['updated_at' => now()->subMinutes(45)]);

            Artisan::call('sim:pump');

            $row = DB::table('sim_items')->where('id', $claim->id)->first();
            $this->assertSame(SimItem::STATUS_PENDING, $row->status, 'a dead claim must return to pending');
            $this->assertNull($row->claim_token, 'and its token must be cleared');

            $this->assertNotNull(
                SimClaims::next($run->fresh(), (string) Str::uuid()),
                'the reclaimed unit must be claimable again'
            );
        });
    }

    /**
     * The network lane gets a longer grace period. Reclaiming a rate-limited
     * research call at 30 minutes would duplicate a call that costs money.
     */
    public function test_the_network_lane_is_not_reclaimed_on_the_ordinary_clock(): void
    {
        $this->onLivePg(function () {
            $run = $this->makeRun(['phase' => 'profiling', 'status' => 'running']);
            $this->items($run, 'profile_research', 1);

            $claim = SimClaims::next($run, (string) Str::uuid());
            $this->assertNotNull($claim);

            // 45 minutes — past the ordinary threshold, inside the network one.
            DB::table('sim_items')->where('id', $claim->id)
                ->update(['updated_at' => now()->subMinutes(45)]);

            Artisan::call('sim:pump');

            $this->assertSame(
                SimItem::STATUS_RUNNING,
                DB::table('sim_items')->where('id', $claim->id)->value('status'),
                'a research claim must survive the ordinary stale clock'
            );

            // 5 hours — past the network threshold too.
            DB::table('sim_items')->where('id', $claim->id)
                ->update(['updated_at' => now()->subHours(5)]);

            Artisan::call('sim:pump');

            $this->assertSame(
                SimItem::STATUS_PENDING,
                DB::table('sim_items')->where('id', $claim->id)->value('status'),
                'but it must still be reclaimed eventually'
            );
        });
    }

    /**
     * The barrier opens on SETTLED, not on SUCCESS. A review or a failure is an
     * honest outcome that must not wedge the run forever.
     */
    public function test_phase_advances_only_when_its_pool_drains_and_failures_do_not_sink_the_run(): void
    {
        $this->onLivePg(function () {
            $run = $this->makeRun(['phase' => 'cohorts', 'status' => 'running']);
            $items = $this->items($run, 'cohort_scope', 3);

            Artisan::call('sim:pump');
            $this->assertSame('cohorts', $run->fresh()->phase, 'an open pool must hold the barrier shut');

            // Settle them as done / review / failed — a mixed, realistic outcome.
            DB::table('sim_items')->where('id', $items[0])->update(['status' => SimItem::STATUS_DONE]);
            DB::table('sim_items')->where('id', $items[1])->update(['status' => SimItem::STATUS_REVIEW]);
            DB::table('sim_items')->where('id', $items[2])->update(['status' => SimItem::STATUS_FAILED]);

            Artisan::call('sim:pump');

            $this->assertSame(
                'identities',
                $run->fresh()->phase,
                'review and failure are SETTLED — the barrier must open'
            );
        });
    }

    /** The claim ladder only ever sees the CURRENT phase's kinds. */
    public function test_a_worker_cannot_claim_work_from_a_future_phase(): void
    {
        $this->onLivePg(function () {
            $run = $this->makeRun(['phase' => 'cohorts']);
            $this->items($run, 'count_election', 3); // a LATER phase's kind

            $this->assertNull(
                SimClaims::next($run, (string) Str::uuid()),
                'work belonging to a future phase must be invisible'
            );
        });
    }

    /** Largest-first: the costliest unit starts before the cheap tail. */
    public function test_claim_order_is_largest_first(): void
    {
        $this->onLivePg(function () {
            $run = $this->makeRun(['phase' => 'cohorts']);

            // position is filled from est_cost DESC by the enumerator; assert
            // the claim honours position order.
            foreach ([[0, 9_000_000], [1, 500], [2, 10]] as [$pos, $cost]) {
                DB::table('sim_items')->insert([
                    'id' => (string) Str::uuid(),
                    'run_id' => $run->id,
                    'kind' => 'cohort_scope',
                    'status' => SimItem::STATUS_PENDING,
                    'unit_key' => 'cost:'.$pos,
                    'position' => $pos,
                    'est_cost' => $cost,
                    'metrics' => '{}',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $first = SimClaims::next($run, (string) Str::uuid());
            $this->assertNotNull($first);

            $this->assertSame(
                9_000_000,
                (int) DB::table('sim_items')->where('id', $first->id)->value('est_cost'),
                'the costliest unit must be claimed first, or it defines the tail alone'
            );
        });
    }

    /** The idempotency key lets a crashed enumeration re-mint without duplicating. */
    public function test_reminting_the_worklist_is_idempotent(): void
    {
        $this->onLivePg(function () {
            $run = $this->makeRun(['phase' => 'cohorts']);
            $jid = (string) Str::uuid();

            $insert = fn () => DB::statement(
                "INSERT INTO sim_items (id, run_id, kind, status, jurisdiction_id, unit_key, position, est_cost, metrics, created_at, updated_at)
                 VALUES (gen_random_uuid(), ?, 'cohort_scope', 'pending', ?, ?, 0, 0, '{}', now(), now())
                 ON CONFLICT ON CONSTRAINT sim_items_unit_uq DO NOTHING",
                [$run->id, $jid, $jid]
            );

            $insert();
            $insert();
            $insert();

            $this->assertSame(
                1,
                DB::table('sim_items')->where('run_id', $run->id)->where('jurisdiction_id', $jid)->count(),
                're-minting a worklist must never duplicate a unit'
            );
        });
    }

    // ── fixture helpers ───────────────────────────────────────────────────

    /**
     * Run the body against a LIVE postgres connection inside a transaction that
     * is always rolled back — the same harness the other live-pg constitutional
     * pins use. The engine's claim SQL is `FOR UPDATE SKIP LOCKED`, which
     * SQLite cannot express, so these invariants can only be pinned on real pg.
     */
    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
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

    private function makeRun(array $attrs = []): SimRun
    {
        // The pump's contract is ONE live run at a time — it supersedes every
        // run but the oldest. So a test of the pump must establish that state,
        // or a real run sitting on the box (a populate in progress, a finished
        // demo) silently becomes the run under test and these assertions
        // measure the wrong thing. Safe: the whole test rolls back.
        DB::table('sim_items')->delete();
        DB::table('sim_worker_leases')->delete();
        DB::table('sim_runs')->delete();

        return SimRun::create(array_merge([
            'status' => 'running',
            'phase' => 'cohorts',
            'options' => [],
            'phase_timings' => [],
        ], $attrs));
    }

    /** @return list<string> */
    private function items(SimRun $run, string $kind, int $n): array
    {
        $ids = [];

        for ($i = 0; $i < $n; $i++) {
            $id = (string) Str::uuid();
            DB::table('sim_items')->insert([
                'id' => $id,
                'run_id' => $run->id,
                'kind' => $kind,
                'status' => SimItem::STATUS_PENDING,
                'jurisdiction_id' => (string) Str::uuid(),
                'unit_key' => $kind.':'.$i,
                'position' => $i,
                'est_cost' => 1000 - $i,
                'metrics' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $ids[] = $id;
        }

        return $ids;
    }
}
