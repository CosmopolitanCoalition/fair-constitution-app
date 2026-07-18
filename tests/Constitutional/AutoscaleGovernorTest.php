<?php

namespace Tests\Constitutional;

use App\Support\AutoscaleGovernor;
use Tests\TestCase;

/**
 * PIN — the width governor's decision law (operator ruling 2026-07-18:
 * "set to unlimited and let the system itself decide what it can handle").
 * AIMD toward the knee: probe up on headroom, step down on saturation or
 * failures, halve on a postgres crash — bounded only by the physics
 * ceiling (cores) and the floor of 2.
 */
class AutoscaleGovernorTest extends TestCase
{
    public function test_probes_up_on_headroom_with_work_waiting(): void
    {
        $this->assertSame(6, AutoscaleGovernor::decide(5, 12, 0.55, true, 0, false));
    }

    public function test_never_probes_past_the_physics_ceiling(): void
    {
        $this->assertSame(12, AutoscaleGovernor::decide(12, 12, 0.30, true, 0, false));
    }

    public function test_holds_when_no_work_is_waiting(): void
    {
        $this->assertSame(5, AutoscaleGovernor::decide(5, 12, 0.30, false, 0, false),
            'an idle queue is not a reason to widen');
    }

    public function test_holds_in_the_comfort_band(): void
    {
        $this->assertSame(8, AutoscaleGovernor::decide(8, 12, 0.85, true, 0, false),
            'between 0.70 and 0.92 CPU-busy the width is at the knee — hold');
    }

    public function test_eases_off_when_the_cores_saturate(): void
    {
        $this->assertSame(7, AutoscaleGovernor::decide(8, 12, 0.97, true, 0, false));
    }

    public function test_steps_down_firmly_on_recent_failures(): void
    {
        $this->assertSame(6, AutoscaleGovernor::decide(8, 12, 0.50, true, 3, false),
            'failures outrank headroom — width − 2');
    }

    public function test_halves_on_a_postgres_restart(): void
    {
        $this->assertSame(5, AutoscaleGovernor::decide(10, 12, 0.50, true, 0, true),
            'the crash signal is the strongest word the host can say');
    }

    public function test_never_drops_below_two(): void
    {
        $this->assertSame(2, AutoscaleGovernor::decide(2, 12, 0.99, true, 5, true));
        $this->assertSame(2, AutoscaleGovernor::decide(3, 12, 0.50, true, 9, false));
    }

    public function test_unknown_cpu_signal_probes_gently_instead_of_freezing(): void
    {
        $this->assertSame(6, AutoscaleGovernor::decide(5, 12, null, true, 0, false),
            'a host without /proc/stat still walks up — failures and crashes remain the brakes');
    }
}
