<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Services\ConstitutionalValidator;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — elections.race_structure (WI-B4, design §B.4):
 *
 *  - chamber races (type_a/type_b) carry 5–9 seats — the hardened band
 *    (Art. II §2);
 *  - `single` races carry exactly 1 seat (individual-executive exception);
 *  - an AT-LARGE race may never exceed the legislature max: above the max,
 *    subdivision into separate voter pools is MANDATORY (Art. II §8) — a
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

    public function test_chamber_races_accept_the_constitutional_band(): void
    {
        foreach ([5, 6, 7, 8, 9] as $seats) {
            $this->validator->checkRaceStructure('type_a', $seats, 'some-district-uuid');
            $this->validator->checkRaceStructure('type_b', $seats, null); // at-large within max
        }

        $this->expectNotToPerformAssertions();
    }

    public function test_chamber_races_reject_outside_the_band(): void
    {
        foreach ([['type_a', 4], ['type_a', 10], ['type_b', 0], ['type_b', 1999]] as [$kind, $seats]) {
            try {
                $this->validator->checkRaceStructure($kind, $seats, 'some-district-uuid');
                $this->fail("{$kind} race with {$seats} seats must be rejected (Art. II §2)");
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §2', $e->citation);
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
        // In-range race list passes pre-commit…
        $this->validator->check('F-ELB-001', [
            'races' => [
                ['seat_kind' => 'type_a', 'seats' => 7, 'district_id' => 'd-1'],
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
