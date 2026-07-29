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

    // ── The service-scale formula (SERVICE_SCALE_FORMULA.md §4, all §9 calls
    //    RULED option (a); pins the numeric contract lane 3's R-B build wires) ──

    /**
     * §4.4 — K(S) = clamp( round(3.5 + 2.7·ln S), 1, round(S/5) ). Hits the
     * settled app anchors (Niue 12→2, San Marino 32→6, Earth 1999→24) AND the
     * real-world calibration anchors (US House 435→20, Senate 100→16). Both
     * clamps bind: the staff cap for a small chamber, the log curve for a large.
     */
    public function test_committee_target_hits_its_anchors(): void
    {
        $this->assertSame(2,  Scale::committeeTarget(12),   'Niue S=12 → 2 (staff cap binds)');
        $this->assertSame(6,  Scale::committeeTarget(32),   'San Marino S=32 → 6');
        $this->assertSame(24, Scale::committeeTarget(1_999), 'Earth S=1999 → 24 (log curve binds)');
        $this->assertSame(20, Scale::committeeTarget(435),  'US House → 20');
        $this->assertSame(16, Scale::committeeTarget(100),  'US Senate → 16');
        $this->assertSame(1,  Scale::committeeTarget(5),    'a 5-seat chamber staffs one committee');
        $this->assertSame(0,  Scale::committeeTarget(0),    'no chamber, no committees');
    }

    /**
     * §4.4 — D(P) = clamp( round(-7.8 + 1.67·ln P), 3, 30 ). Hits the anchors
     * (Niue 1,819→5, San Marino 33,581→10, Earth→30 cap), floors at 3 for a
     * small inhabited place, and honours the zero rule for an empty one.
     */
    public function test_department_target_hits_its_anchors_and_floors_at_three(): void
    {
        $this->assertSame(5,  Scale::departmentTarget(1_819),   'Niue → 5');
        $this->assertSame(10, Scale::departmentTarget(33_581),  'San Marino → 10');
        $this->assertSame(30, Scale::departmentTarget(7_991_888_892), 'Earth → 30 (cap)');
        $this->assertSame(3,  Scale::departmentTarget(100),     'a tiny inhabited place floors at 3');
        $this->assertSame(3,  Scale::departmentTarget(1),       'floor 3 holds at P=1');
        $this->assertSame(0,  Scale::departmentTarget(0),       'the zero rule — empty place gets none');
        $this->assertSame(0,  Scale::departmentTarget(null),    'null population gets none');
    }

    /**
     * §4.3 — court LAYERS grow trial → +appellate → +supreme, monotone with
     * tier. This is distinct from bench SIZE (judgeCount), which keeps its
     * 5/5/7/9 floor untouched (Q2 ruling (a)).
     */
    public function test_court_tiers_grow_monotonically_with_tier(): void
    {
        $this->assertSame(0, Scale::courtTiers(Scale::TIER_NONE));
        $this->assertSame(1, Scale::courtTiers(Scale::TIER_MINIMAL));
        $this->assertSame(1, Scale::courtTiers(Scale::TIER_STANDARD));
        $this->assertSame(2, Scale::courtTiers(Scale::TIER_EXTENDED));
        $this->assertSame(3, Scale::courtTiers(Scale::TIER_FULL));

        // The bench floor is NOT touched by the new court-tier count.
        $this->assertSame(5, Scale::judgeCount(Scale::TIER_STANDARD));
        $this->assertSame(9, Scale::judgeCount(Scale::TIER_FULL));
    }

    /**
     * §4.5 — extra rooms are a LOCAL/leaf metric only (the tree carries the
     * aggregate), anchored to 1 per 50,000 and capped at 20. Earth's node earns
     * 0 (its rooms belong to descendants); microstates earn 0 (both < 50k).
     */
    public function test_extra_rooms_are_local_only_anchored_and_capped(): void
    {
        $this->assertSame(0,  Scale::extraRooms(7_991_888_892, false), 'Earth node — rooms belong to descendants');
        $this->assertSame(0,  Scale::extraRooms(1_000_000, false),     'a non-local place earns no extra rooms');
        $this->assertSame(0,  Scale::extraRooms(1_819, true),          'Niue (leaf, <50k) → square + halls only');
        $this->assertSame(0,  Scale::extraRooms(33_581, true),         'San Marino (<50k) → 0 extra');
        $this->assertSame(2,  Scale::extraRooms(120_000, true),        '1 per 50k');
        $this->assertSame(20, Scale::extraRooms(2_000_000, true),      'capped at 20');
    }

    /**
     * The count curves are monotone non-decreasing (matching the service's
     * monotonicity posture): more chamber / more people never yields fewer
     * institutions.
     */
    public function test_the_count_curves_are_monotone(): void
    {
        $prevK = -1;
        foreach ([5, 12, 32, 100, 435, 1_999] as $seats) {
            $k = Scale::committeeTarget($seats);
            $this->assertGreaterThanOrEqual($prevK, $k, "committee regression at S={$seats}");
            $prevK = $k;
        }

        $prevD = -1;
        foreach ([1, 100, 1_819, 33_581, 586_351, 7_991_888_892] as $pop) {
            $d = Scale::departmentTarget($pop);
            $this->assertGreaterThanOrEqual($prevD, $d, "department regression at P={$pop}");
            $prevD = $d;
        }
    }
}
