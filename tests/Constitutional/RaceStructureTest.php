<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Services\ConstitutionalValidator;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — elections.race_structure (WI-B4, design §B.4):
 *
 *  - type_a DISTRICT races carry 5–9 seats — the hardened district band
 *    (Art. II §2);
 *  - type_b AT-LARGE races carry 1 to type_b_seats_per_child seats. The
 *    equal-representation ladder sets a uniform rep_floor (5 → 4 → 3 → 2),
 *    but a child of population 5 or below contributes min(population,
 *    rep_floor), so a single-resident child's own per-child race carries 1
 *    seat. The 5–9 district band does NOT bind a Type B race (Bicameral
 *    Support, operator ruling 2026-07-29). The generator
 *    (ElectionLifecycleService::createRaces) writes 1, 2, 3, 4 and 5 seat
 *    Type B races per child or per clump, and the validator accepts the same
 *    shape (Art. V §3). This corrects a prior asymmetry where the validator
 *    rejected the generator's own lawful output (first the 5–9 band, then a
 *    floor of 2 that still refused the 1-seat single-resident race);
 *  - `single` races carry exactly 1 seat (individual-executive exception);
 *  - an at-large type_a race may never exceed the legislature max: above the
 *    max, subdivision into separate voter pools is MANDATORY (Art. II §8) — a
 *    10+-seat at-large race is unconstitutional on its face.
 *
 * DB-free (established posture) — checkRaceStructure is pure validation.
 * If an edit to ConstitutionalValidator breaks these tests, that edit is a
 * constitutional violation — fix the edit, never the test.
 */
class RaceStructureTest extends TestCase
{
    private ConstitutionalValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ConstitutionalValidator();
    }

    public function test_type_a_district_races_accept_the_constitutional_band(): void
    {
        foreach ([5, 6, 7, 8, 9] as $seats) {
            $this->validator->checkRaceStructure('type_a', $seats, 'some-district-uuid');
        }

        $this->expectNotToPerformAssertions();
    }

    public function test_type_b_races_accept_the_equal_representation_ladder(): void
    {
        // A single-resident child's own per-child race carries 1 seat:
        // min(population, rep_floor) = min(1, rep_floor) = 1. The generator
        // (ElectionLifecycleService::createRaces) emits it, so the validator
        // accepts it. The 5–9 district band does not bind Type B.
        $this->validator->checkRaceStructure('type_b', 1, null);

        // The ladder the generator produces for larger children: 2, 3, 4 and
        // 5 seat at-large races (per child or per clump). All are lawful.
        foreach ([2, 3, 4, 5] as $seats) {
            $this->validator->checkRaceStructure('type_b', $seats, null);
        }

        // A raised type_b_seats_per_child ceiling admits up to that value.
        foreach ([6, 7, 8, 9] as $seats) {
            $this->validator->checkRaceStructure('type_b', $seats, null, null, 9);
        }

        $this->expectNotToPerformAssertions();
    }

    public function test_type_a_races_reject_outside_the_band(): void
    {
        foreach ([['type_a', 4], ['type_a', 10]] as [$kind, $seats]) {
            try {
                $this->validator->checkRaceStructure($kind, $seats, 'some-district-uuid');
                $this->fail("{$kind} race with {$seats} seats must be rejected (Art. II §2)");
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §2', $e->citation);
            }
        }
    }

    public function test_type_b_races_reject_below_the_floor_and_above_the_ceiling(): void
    {
        // At or below zero seats, and above the resolved ceiling: both refuse
        // under Art. V §3 (the ladder rule), never the 5–9 band. A 1-seat
        // race is NOT rejected here: it is the lawful per-child race of a
        // single-resident child (min(population, rep_floor) = 1) and is pinned
        // as accepted above.
        foreach ([['type_b', 0, null], ['type_b', -1, null], ['type_b', 10, null], ['type_b', 4, 3]] as [$kind, $seats, $tbMax]) {
            try {
                $this->validator->checkRaceStructure($kind, $seats, null, null, $tbMax);
                $this->fail("{$kind} race with {$seats} seats must be rejected (Art. V §3)");
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. V §3', $e->citation);
            }
        }
    }

    public function test_at_large_race_may_never_exceed_the_resolved_max(): void
    {
        // An amended max BELOW the ceiling binds at-large races…
        try {
            $this->validator->checkRaceStructure('type_a', 9, null, 7);
            $this->fail('9-seat at-large race with max 7 must be rejected (Art. II §8)');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. II §8', $e->citation);
        }

        // …while the same seat count in a DISTRICT race is fine (its map
        // already subdivides the pool).
        $this->validator->checkRaceStructure('type_a', 9, 'some-district-uuid', 7);
    }

    public function test_resolved_max_can_never_exceed_the_hardened_ceiling(): void
    {
        // Even a (hypothetically corrupted) resolved max of 50 cannot
        // unlock a >9-seat at-large race: the seats band rejects first.
        $this->expectException(ConstitutionalViolation::class);
        $this->validator->checkRaceStructure('type_a', 12, null, 50);
    }

    public function test_single_races_carry_exactly_one_seat(): void
    {
        $this->validator->checkRaceStructure('single', 1, null);

        foreach ([0, 2, 5] as $seats) {
            try {
                $this->validator->checkRaceStructure('single', $seats, null);
                $this->fail("'single' race with {$seats} seats must be rejected");
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. III §2', $e->citation);
            }
        }
    }

    public function test_unknown_seat_kind_is_rejected(): void
    {
        $this->expectException(ConstitutionalViolation::class);
        $this->validator->checkRaceStructure('faction_block', 5, null);
    }

    public function test_f_elb_001_payload_pre_check_walks_explicit_races(): void
    {
        // In-range race list passes pre-commit — including a 1-seat Type B
        // race (the single-resident child's own per-child race) and a 2-seat
        // Type B race, exactly as the generator writes them, alongside a
        // 7-seat Type A district and a 9-seat Type B. The 1-seat case closes
        // the generator-vs-ballot asymmetry: the explicit ballot path now
        // accepts the same shape createRaces emits.
        $this->validator->check('F-ELB-001', [
            'races' => [
                ['seat_kind' => 'type_a', 'seats' => 7, 'district_id' => 'd-1'],
                ['seat_kind' => 'type_b', 'seats' => 1],
                ['seat_kind' => 'type_b', 'seats' => 2],
                ['seat_kind' => 'type_b', 'seats' => 9],
            ],
        ]);

        // …an unconstitutional member is rejected with citation.
        try {
            $this->validator->check('F-ELB-001', [
                'races' => [['seat_kind' => 'type_a', 'seats' => 10, 'district_id' => null]],
            ]);
            $this->fail('10-seat race must be rejected pre-commit');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. II §2', $e->citation);
        }
    }

    public function test_f_elb_002_rejection_knows_a_single_ground(): void
    {
        // The lawful ground passes the pre-commit check.
        $this->validator->check('F-ELB-002', [
            'decision'         => 'reject',
            'rejection_reason' => 'no_residency_association',
        ]);

        // Any other ground is unconstitutional (Art. I).
        foreach (['criminal_history', 'incomplete_paperwork', 'party_affiliation', ''] as $ground) {
            try {
                $this->validator->check('F-ELB-002', [
                    'decision'         => 'reject',
                    'rejection_reason' => $ground,
                ]);
                $this->fail("Rejection ground '{$ground}' must be rejected (Art. I)");
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. I', $e->citation);
            }
        }
    }

    public function test_candidacy_forms_reject_eligibility_riders(): void
    {
        // F-IND-011 / F-ELB-002 are rights-automatic: payload keys that
        // smuggle eligibility conditions are rejected (Art. I).
        foreach (['F-IND-011', 'F-ELB-002'] as $form) {
            try {
                $this->validator->check($form, ['qualifications' => ['property_owner']]);
                $this->fail("{$form} with an eligibility rider must be rejected (Art. I)");
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. I', $e->citation);
            }
        }
    }
}
