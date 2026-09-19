<?php

namespace Tests\Unit;

use App\Support\HostCapacity;
use Tests\TestCase;

/**
 * The lanes fit the whole Horizon tree (operator order 2026-09-19, the
 * size-family sweep). Pure functions, no host, no database. The shell
 * allocator (get-started.sh need_horizon) mirrors horizonNeedMb() at two
 * lanes; tests/deploy/test_sizing_family.sh pins that side.
 */
class HostCapacityLanesTest extends TestCase
{
    public function test_the_supervisor_count_matches_the_horizon_config(): void
    {
        $this->assertSame(HostCapacity::SUPERVISORS, count(config('horizon.defaults')));
    }

    public function test_the_fleet_mirrors_the_pool_widths_at_the_floor(): void
    {
        // default 2, long-running 2, autoscale 2, provision 2, sim 2, prewarm 1.
        $this->assertSame(11, HostCapacity::fleetFor(2));
        // A provision dial narrower or wider than the lane width is counted as set.
        $this->assertSame(11 + 6, HostCapacity::fleetFor(2, 8));
    }

    public function test_the_need_at_two_lanes_is_the_shell_floor(): void
    {
        // master + (6 supervisors + 11 lanes) x 64 + 2 x (256 - 64)
        $this->assertSame(256 + 17 * 64 + 384, HostCapacity::horizonNeedMb(2, 256));
        $this->assertSame(124 + 17 * 64 + 384, HostCapacity::horizonNeedMb(2, 124));
    }

    public function test_a_cap_at_its_floor_funds_exactly_two_lanes(): void
    {
        $floor = HostCapacity::horizonNeedMb(2, 256);

        $this->assertSame(2, HostCapacity::lanesThatFit($floor, 256));
        $this->assertSame(2, HostCapacity::lanesThatFit($floor - 500, 256), 'below the floor the lane floor still holds');
    }

    public function test_the_old_bound_over_granted_and_the_new_one_fits(): void
    {
        // 1776 MB: three quarters at 400 MB a lane granted 3 lanes; their tree needs more than the cap.
        $this->assertGreaterThan(1776, HostCapacity::horizonNeedMb(3, 256));
        $this->assertSame(2, HostCapacity::lanesThatFit(1776, 256));
    }

    public function test_every_granted_width_fits_its_cap_at_the_working_lane_size(): void
    {
        $lane = HostCapacity::laneWorkMb();
        $this->assertSame(400, $lane, '5/6 of the 480 MB recycle bound');

        foreach ([1536, 2048, 3000, 5052, 5499, 11038, 22119, 44272, 65536] as $cap) {
            $aw = HostCapacity::lanesThatFit($cap, 256);
            if ($aw > 2) {
                $this->assertLessThanOrEqual($cap, HostCapacity::horizonNeedMb($aw, 256, $lane), "cap {$cap}: {$aw} lanes fit");
            }
            $this->assertGreaterThan($cap, HostCapacity::horizonNeedMb($aw + 1, 256, $lane), "cap {$cap}: one more lane does not fit");
        }
    }

    public function test_a_wide_pool_keeps_a_recycle_bound_near_the_working_size(): void
    {
        // The 128 GB mapping share: the cores bound the lanes at 73 and the cap funds them all.
        $this->assertGreaterThanOrEqual(73, HostCapacity::lanesThatFit(44272, 256));
        // The 16 GB mapping share funds 7 working lanes, not the 8 the cores would run.
        $this->assertSame(7, HostCapacity::lanesThatFit(5499, 249));
    }

    public function test_the_lane_count_never_falls_as_the_cap_grows(): void
    {
        $prev = 0;
        for ($cap = 1024; $cap <= 65536; $cap += 256) {
            $aw = HostCapacity::lanesThatFit($cap, 256);
            $this->assertGreaterThanOrEqual($prev, $aw);
            $prev = $aw;
        }
    }
}
