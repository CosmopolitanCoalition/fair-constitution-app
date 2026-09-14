<?php

namespace Tests\Feature;

use App\Console\Commands\SimPumpCommand;
use App\Services\Demo\DemoSessionService;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

/**
 * PIN (W-0183) — a demo void pauses the sim pump.
 *
 * DemoSessionService::void reverses a session's writes. The sim pump advances
 * the simulated world and would write into rows the void is reversing. Both
 * take the SAME lock (SimPumpCommand::EXEC_LOCK), and a pump tick that cannot
 * get the lock just returns, so the void holds it for the whole reversal and
 * releases it after.
 *
 * DB-free: the reversal body is not driven here; the pause wrapper is asserted
 * directly over the cache lock (the array store in the test environment).
 */
class DemoVoidPumpPauseTest extends TestCase
{
    public function test_the_void_holds_and_releases_the_sim_pump_lock(): void
    {
        $service = app(DemoSessionService::class);
        $withPumpsPaused = new ReflectionMethod($service, 'withPumpsPaused');
        $withPumpsPaused->setAccessible(true);

        // Precondition: a pump could take the lock right now.
        $before = Cache::lock(SimPumpCommand::EXEC_LOCK);
        $this->assertTrue($before->get(), 'the pump lock was already held before the void');
        $before->release();

        $lockHeldDuringVoid = null;
        $returned = $withPumpsPaused->invoke($service, function () use (&$lockHeldDuringVoid) {
            // A concurrent pump tick asks for the same lock; it must be refused.
            $lockHeldDuringVoid = ! Cache::lock(SimPumpCommand::EXEC_LOCK)->get();

            return 'reversed';
        });

        $this->assertSame('reversed', $returned);
        $this->assertTrue($lockHeldDuringVoid, 'the sim pump lock was NOT held while the void ran');

        // The lock is free again after the void.
        $after = Cache::lock(SimPumpCommand::EXEC_LOCK);
        $this->assertTrue($after->get(), 'the sim pump lock was not released after the void');
        $after->release();
    }
}
