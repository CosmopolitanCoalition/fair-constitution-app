<?php

namespace Tests\Constitutional;

use App\Services\InstitutionScaleService as Scale;
use PHPUnit\Framework\TestCase;

/**
 * Pins on institution scaling (operator ruling 2026-07-25): institutions
 * follow the real population of a place — people, parts, and depth.
 *
 * DB-free by design, matching ActivationMathTest's posture.
 *
 * If an edit breaks these, the edit is the constitutional violation —
 * fix the edit, not the test.
 */
class InstitutionScaleTest extends TestCase
{
    /** THE ZERO RULE: no people and no parts means no institutions at all. */
    public function test_uninhabited_jurisdictions_get_nothing(): void
    {
        $this->assertSame(Scale::TIER_NONE, Scale::tierFor(0));
        $this->assertSame(Scale::TIER_NONE, Scale::tierFor(null));
        $this->assertFalse(Scale::provisions(0));
        $this->assertFalse(Scale::provisions(null));
        $this->assertSame([], Scale::spaceTypes(Scale::TIER_NONE));
        $this->assertSame(0, Scale::judgeCount(Scale::TIER_NONE));
    }

    /**
     * A population of 0 with real constituents is a RASTER ARTEFACT, not an
     * empty place — 34,738 villages store 0 population planet-wide because
     * their borders sit off the population raster. Its constituents still
     * need somewhere to meet.
     */
    public function test_zero_population_with_constituents_is_still_a_polity(): void
    {
        $this->assertSame(Scale::TIER_MINIMAL, Scale::tierFor(0, constituents: 3));
        $this->assertTrue(Scale::provisions(0, constituents: 1));
        $this->assertTrue(Scale::provisions(null, constituents: 1));
    }

    /** Scale follows headcount across the real planet's layer medians. */
    public function test_tiers_track_the_real_planet(): void
    {
        $this->assertSame(Scale::TIER_MINIMAL,  Scale::tierFor(765));        // L6 median
        $this->assertSame(Scale::TIER_STANDARD, Scale::tierFor(1_514));      // L5 median
        $this->assertSame(Scale::TIER_STANDARD, Scale::tierFor(25_844));     // L3 median
        $this->assertSame(Scale::TIER_EXTENDED, Scale::tierFor(586_351));    // L2 median
        $this->assertSame(Scale::TIER_FULL,     Scale::tierFor(5_515_074, constituents: 30));
        $this->assertSame(Scale::TIER_FULL,     Scale::tierFor(7_991_888_892)); // Earth
    }

    /** Parts are complexity in their own right, not just headcount. */
    public function test_many_constituents_promote_a_tier(): void
    {
        $plain    = Scale::tierFor(2_000);
        $withMany = Scale::tierFor(2_000, constituents: 30);

        $this->assertSame(Scale::TIER_STANDARD, $plain);
        $this->assertSame(Scale::TIER_EXTENDED, $withMany);
    }

    /**
     * FREE binding exists so people can play without real-world constraints —
     * a tabletop group, or one organisation adopting a single component.
     * Population must impose nothing at all there, INCLUDING the zero rule.
     */
    public function test_free_binding_entitles_everything_regardless_of_population(): void
    {
        $this->assertSame(Scale::TIER_STANDARD, Scale::tierFor(0, 0, Scale::BINDING_FREE));
        $this->assertSame(Scale::TIER_STANDARD, Scale::tierFor(null, 0, Scale::BINDING_FREE));
        $this->assertTrue(Scale::provisions(0, 0, Scale::BINDING_FREE));
        $this->assertTrue(Scale::provisions(null, 0, Scale::BINDING_FREE));
    }

    /**
     * ART. IV §1 — every bench floors at five judges and has no ceiling.
     * Scaling may add judges; it may never take the floor away.
     */
    public function test_every_provisioned_bench_floors_at_five(): void
    {
        foreach ([Scale::TIER_MINIMAL, Scale::TIER_STANDARD, Scale::TIER_EXTENDED, Scale::TIER_FULL] as $tier) {
            $this->assertGreaterThanOrEqual(5, Scale::judgeCount($tier), "tier {$tier} fell below the Art. IV §1 floor");
        }

        $this->assertGreaterThan(Scale::judgeCount(Scale::TIER_MINIMAL), Scale::judgeCount(Scale::TIER_FULL));
    }

    /**
     * ART. I — the public square is where people speak. Any inhabited place
     * gets one; it is never scaled away for being small. Gating speech on a
     * headcount is the error this pin exists to prevent.
     */
    public function test_every_inhabited_place_gets_its_square(): void
    {
        foreach ([Scale::TIER_MINIMAL, Scale::TIER_STANDARD, Scale::TIER_EXTENDED, Scale::TIER_FULL] as $tier) {
            $this->assertContains('public_square', Scale::spaceTypes($tier), "tier {$tier} lost its square");
        }
    }

    /** Scaling is monotonic: more people never yields a smaller entitlement. */
    public function test_scale_is_monotonic_in_population(): void
    {
        $order = [
            Scale::TIER_NONE     => 0,
            Scale::TIER_MINIMAL  => 1,
            Scale::TIER_STANDARD => 2,
            Scale::TIER_EXTENDED => 3,
            Scale::TIER_FULL     => 4,
        ];

        $previous = -1;

        foreach ([0, 1, 765, 1_514, 25_844, 586_351, 10_000_000, 7_991_888_892] as $pop) {
            $rank = $order[Scale::tierFor($pop)];
            $this->assertGreaterThanOrEqual($previous, $rank, "regression at population {$pop}");
            $previous = $rank;
        }
    }
}
