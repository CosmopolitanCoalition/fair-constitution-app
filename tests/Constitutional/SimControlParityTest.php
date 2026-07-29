<?php

namespace Tests\Constitutional;

use App\Jobs\RunSimStartJob;
use App\Models\SimRun;
use App\Services\Demo\SimRunControl;
use App\Support\GameMode;
use App\Support\InstanceClass;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the /simworld DRIVE controls, and their CLI twins.
 *
 * The console can now START, HALT and RESUME a populate run; before Wave 2 the
 * page could only WATCH. The standing order (ruling 10) is that a capability
 * offered on one surface is offered on both, and — the part that is easy to get
 * wrong — the GUARDS TRAVEL WITH THE PAIR. So halt and resume exist on the
 * console AND as `sim:halt` / `sim:resume`, and every door routes through the one
 * `SimRunControl` where the guard, the single-run law and the audit mark live.
 *
 * THE INVARIANTS:
 *   · a non-demo world REFUSES every verb, on every door — the synthetic-data
 *     guard is not a UI nicety, it is the same rail the seeder commands carry
 *   · a second concurrent run is refused (one run holds the engine)
 *   · halt sets the flag the pump honours; resume clears it; the round-trip is exact
 *   · every drive act leaves an audit mark (dev_control = true)
 *   · the CLI verbs and the console call the SAME service — they cannot drift
 *   · the drive routes are operator-gated; watching is public, driving is not
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class SimControlParityTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_simcontrol';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // start() dispatches RunSimStartJob — capture, never run it
    }

    protected function tearDown(): void
    {
        InstanceClass::override(null);
        InstanceClass::flush();
        GameMode::override(null);
        GameMode::flush();
        parent::tearDown();
    }

    /** THE GUARD. A world that has not declared itself synthetic-safe refuses every verb. */
    public function test_a_non_demo_world_refuses_every_drive_verb(): void
    {
        $this->onLivePg(function () {
            $this->beProduction();
            $control = app(SimRunControl::class);

            foreach (['start', 'halt', 'resume'] as $verb) {
                $result = $verb === 'start'
                    ? $control->start([], 'op')
                    : $control->{$verb}('op');

                $this->assertFalse($result['ok'], "{$verb} must refuse on a non-demo world");
                $this->assertNotNull($result['reason'], "{$verb} must say why");
                $this->assertStringContainsString('synthetic-safe', $result['reason']);
            }

            // The refusal is total: no run was touched and no work was queued.
            Queue::assertNotPushed(RunSimStartJob::class);
        });
    }

    /** A demo world may drive, and the halt flag round-trips exactly. */
    public function test_a_demo_world_halts_and_resumes_with_an_audit_mark(): void
    {
        $this->onLivePg(function () {
            $this->beScaleDemo();
            $run = $this->makeRun();
            $control = app(SimRunControl::class);

            $halt = $control->halt('op');
            $this->assertTrue($halt['ok']);
            $this->assertNotNull(
                $run->fresh()->halt_requested_at,
                'halt must set the flag the pump honours'
            );
            $this->assertAudited('sim.halted', (string) $run->id);

            $resume = $control->resume('op');
            $this->assertTrue($resume['ok']);
            $this->assertNull(
                $run->fresh()->halt_requested_at,
                'resume must clear the flag'
            );
            $this->assertAudited('sim.resumed', (string) $run->id);
        });
    }

    /** One run holds the engine — a second start is refused, not silently queued. */
    public function test_start_refuses_a_second_concurrent_run(): void
    {
        $this->onLivePg(function () {
            $this->beScaleDemo();
            $this->makeRun(['status' => 'running']);
            $control = app(SimRunControl::class);

            $result = $control->start([], 'op');

            $this->assertFalse($result['ok']);
            $this->assertStringContainsString('One run at a time', $result['reason']);
            Queue::assertNotPushed(RunSimStartJob::class);
        });
    }

    /** A first start queues the REAL command and marks the operator's act. */
    public function test_start_queues_the_real_command_and_audits_the_act(): void
    {
        $this->onLivePg(function () {
            $this->beScaleDemo();
            DB::table('sim_runs')->delete(); // no active run
            $control = app(SimRunControl::class);

            $result = $control->start(['limit' => 25], 'op');

            $this->assertTrue($result['ok']);
            $this->assertTrue($result['queued']);
            Queue::assertPushed(RunSimStartJob::class, function (RunSimStartJob $job) {
                return ($job->options['--limit'] ?? null) === 25 && $job->actor === 'op';
            });
            $this->assertAuditedEvent('sim.start_requested');
        });
    }

    /** The CLI verbs call the SAME guarded service — refuse on prod, act on demo. */
    public function test_the_cli_verbs_share_the_guard_and_the_write(): void
    {
        $this->onLivePg(function () {
            // Non-demo: sim:halt / sim:resume refuse (exit FAILURE).
            $this->beProduction();
            $this->assertSame(1, Artisan::call('sim:halt'), 'sim:halt must refuse on a non-demo world');
            $this->assertSame(1, Artisan::call('sim:resume'), 'sim:resume must refuse on a non-demo world');

            // Demo: sim:halt sets the flag the same service the button uses would.
            $this->beScaleDemo();
            $run = $this->makeRun(['status' => 'running']);

            $this->assertSame(0, Artisan::call('sim:halt'), 'sim:halt must succeed on a demo world');
            $this->assertNotNull($run->fresh()->halt_requested_at, 'the CLI halt writes the same flag');

            $this->assertSame(0, Artisan::call('sim:resume'), 'sim:resume must succeed');
            $this->assertNull($run->fresh()->halt_requested_at, 'the CLI resume clears the same flag');
        });
    }

    /** Watching is public (Art. II §2); DRIVING is operator-only. */
    public function test_the_drive_routes_are_operator_gated(): void
    {
        foreach (['api.simworld.start', 'api.simworld.halt', 'api.simworld.resume'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "{$name} must exist");
            $this->assertContains(
                'auth:operator',
                $route->gatherMiddleware(),
                "{$name} must be operator-gated — driving a run is not a public act"
            );
        }

        // And the read stays public-to-authenticated, never operator-walled.
        $progress = Route::getRoutes()->getByName('api.simworld.progress');
        $this->assertNotContains(
            'auth:operator',
            $progress->gatherMiddleware(),
            'watching the machinery is a citizen right — the poll must not be operator-gated'
        );
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function beScaleDemo(): void
    {
        InstanceClass::override(InstanceClass::SCALE_DEMO);
        GameMode::override(GameMode::PRODUCTION); // class carries it; mode need not
    }

    private function beProduction(): void
    {
        InstanceClass::override(InstanceClass::PRODUCTION);
        GameMode::override(GameMode::PRODUCTION);
    }

    private function assertAudited(string $event, string $runId): void
    {
        // payload is jsonb — extract with ->> rather than matching formatted text.
        $this->assertTrue(
            DB::table('audit_log')
                ->where('event', $event)
                ->whereRaw("payload->>'run_id' = ?", [$runId])
                ->whereRaw("payload->>'dev_control' = 'true'")
                ->exists(),
            "a {$event} audit entry (dev_control) must be written for run {$runId}"
        );
    }

    private function assertAuditedEvent(string $event): void
    {
        $this->assertTrue(
            DB::table('audit_log')
                ->where('event', $event)
                ->whereRaw("payload->>'dev_control' = 'true'")
                ->exists(),
            "a {$event} audit entry (dev_control) must be written"
        );
    }

    private function makeRun(array $attrs = []): SimRun
    {
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
}
