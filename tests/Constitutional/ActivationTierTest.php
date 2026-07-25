<?php

namespace Tests\Constitutional;

use App\Services\ActivationService;
use App\Services\ActivationTierService;
use PHPUnit\Framework\TestCase;

/**
 * Phase I — pins on the activation TIER CURVE.
 *
 * Deliberately DB-free, matching ActivationMathTest's established posture:
 * the curve lives in pure statics precisely so it can be pinned without a
 * schema. The DB-touching resolution (ActivationService::thresholdFor) is
 * exercised on the live stack.
 *
 * If an edit breaks these, the edit is the constitutional violation —
 * fix the edit, not the test.
 */
class ActivationTierTest extends TestCase
{
    private const ON = ['enabled' => true];

    /** The curve gates a BOOT and is bounded at both ends (Art. II §1 / Art. I). */
    public function test_curve_clamps_between_floor_and_cap(): void
    {
        // 27^(1/3) = 3 → below the floor of 5, so the floor wins.
        $this->assertSame(5, ActivationTierService::tierThreshold(27, self::ON));

        // 216^(1/3) = 6, 343^(1/3) = 7, 512^(1/3) = 8, 729^(1/3) = 9 — inside the band.
        $this->assertSame(6, ActivationTierService::tierThreshold(216, self::ON));
        $this->assertSame(7, ActivationTierService::tierThreshold(343, self::ON));
        $this->assertSame(8, ActivationTierService::tierThreshold(512, self::ON));
        $this->assertSame(9, ActivationTierService::tierThreshold(729, self::ON));

        // Above 729 the cap binds — deliberately flat. A metropolis needs nine
        // residents, not nine thousand: the threshold stops a LONE ACTOR
        // booting a government, it does not demand proportional enrolment.
        $this->assertSame(9, ActivationTierService::tierThreshold(1_000_000, self::ON));
        $this->assertSame(9, ActivationTierService::tierThreshold(7_991_888_892, self::ON));
    }

    /** Unmeasured population must never make a place EASIER to boot. */
    public function test_null_and_zero_population_resolve_to_the_floor(): void
    {
        $this->assertSame(5, ActivationTierService::tierThreshold(null, self::ON));
        $this->assertSame(5, ActivationTierService::tierThreshold(0, self::ON));
        $this->assertSame(5, ActivationTierService::tierThreshold(-100, self::ON));
    }

    /** Flag off = today's dev posture, exactly: one verified resident activates. */
    public function test_disabled_curve_preserves_the_dev_default_of_one(): void
    {
        $this->assertSame(1, ActivationTierService::tierThreshold(7_991_888_892, []));
        $this->assertSame(1, ActivationTierService::tierThreshold(27, ['enabled' => false]));
        $this->assertSame(1, ActivationTierService::tierThreshold(null, ['enabled' => false]));
    }

    /**
     * THE ART. I RAIL. SettingsResolver takes the nearest non-null ancestor,
     * so an unbounded cap would let any ancestor — up to Earth — render every
     * descendant permanently unbootable. That is a franchise harm by another
     * route, and the clamp is what forbids it.
     */
    public function test_cap_cannot_exceed_the_hard_cap(): void
    {
        $threshold = ActivationTierService::tierThreshold(1_000_000, [
            'enabled' => true,
            'cap'     => 5_000_000,
        ]);

        $this->assertLessThanOrEqual(ActivationTierService::HARD_CAP, $threshold);
    }

    /** A floor above the cap must not invert the clamp; the floor yields. */
    public function test_floor_above_cap_yields_to_the_cap(): void
    {
        $params = ActivationTierService::clampParams([
            'enabled' => true,
            'floor'   => 50,
            'cap'     => 9,
        ]);

        $this->assertSame(9, $params['cap']);
        $this->assertSame(9, $params['floor']);
        $this->assertSame(9, ActivationTierService::tierThreshold(1_000_000, [
            'enabled' => true, 'floor' => 50, 'cap' => 9,
        ]));
    }

    /** Degenerate parameters must not divide by zero or invert the curve. */
    public function test_degenerate_parameters_are_normalised(): void
    {
        $p = ActivationTierService::clampParams(['exponent' => 0, 'k' => 0, 'floor' => -3]);

        $this->assertGreaterThanOrEqual(1, $p['exponent']);
        $this->assertGreaterThan(0, $p['k']);
        $this->assertGreaterThanOrEqual(ActivationTierService::HARD_FLOOR, $p['floor']);
    }

    /** The curve is monotonic in population — a bigger place is never easier. */
    public function test_curve_is_monotonic_in_population(): void
    {
        $previous = 0;

        foreach ([0, 1, 27, 125, 343, 729, 5_000, 1_000_000, 7_991_888_892] as $pop) {
            $t = ActivationTierService::tierThreshold($pop, self::ON);
            $this->assertGreaterThanOrEqual($previous, $t, "regression at population {$pop}");
            $previous = $t;
        }
    }

    /**
     * THE TWO CUBE ROOTS ARE NOT THE SAME FUNCTION.
     *
     * cubeRootSeats SIZES A CHAMBER; tierThreshold THRESHOLDS A BOOT. Their
     * defaults coincide numerically (5 and 9) purely by accident — seats there,
     * residents here. Earth is the proof they diverge, and this pin fails
     * loudly if anyone ever "unifies" them.
     */
    public function test_tier_threshold_is_not_the_legislature_sizing_law(): void
    {
        $earth = 7_991_888_892;

        $this->assertSame(1999, ActivationService::cubeRootSeats($earth));
        $this->assertSame(9, ActivationTierService::tierThreshold($earth, self::ON));

        $this->assertNotSame(
            ActivationService::cubeRootSeats($earth),
            ActivationTierService::tierThreshold($earth, self::ON),
            'the sizing law and the tier curve must never collapse into one function',
        );
    }
}
