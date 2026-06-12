<?php

namespace Tests\Constitutional;

use App\Services\ConstitutionalValidator;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. VII supermajority and Art. II §2 quorum.
 *
 *   supermajority = max( ceil(serving × 2/3), floor(serving/2) + 2 )
 *   quorum        = floor(serving/2) + 1
 *
 * The denominator is ALL serving members — never those present, never
 * total seats. Vacant seats leave the denominator (they are not serving);
 * absent members do NOT. The clamp guarantees the threshold can never
 * fall below majority + 1, whatever fraction an amendment supplies.
 *
 * DB-free (established posture). If an edit to ConstitutionalValidator
 * breaks these tests, that edit is a constitutional violation — fix the
 * edit, never the test.
 */
class SupermajorityTest extends TestCase
{
    public function test_two_thirds_supermajority_on_known_chambers(): void
    {
        // The canonical examples from the validator's contract.
        $this->assertSame(6, ConstitutionalValidator::supermajority(8));
        $this->assertSame(6, ConstitutionalValidator::supermajority(9));
        $this->assertSame(5, ConstitutionalValidator::supermajority(6));
        $this->assertSame(4, ConstitutionalValidator::supermajority(5));
        $this->assertSame(5, ConstitutionalValidator::supermajority(7));
    }

    public function test_denominator_is_serving_members_not_seats(): void
    {
        // A 9-seat chamber with 1 vacancy has 8 SERVING members: the
        // threshold drops with the vacancy (6 of 8), not 6 of 9 presents.
        $seats     = 9;
        $vacancies = 1;
        $serving   = $seats - $vacancies;

        $this->assertSame(6, ConstitutionalValidator::supermajority($serving));

        // Absent members are still serving — absence never shrinks the
        // denominator. (The API takes SERVING; there is no 'present' input.)
        $this->assertSame(
            ConstitutionalValidator::supermajority(8),
            ConstitutionalValidator::supermajority(8 /* of whom only 5 showed up */)
        );
    }

    public function test_threshold_never_falls_below_majority_plus_one(): void
    {
        // A degenerate amendment (51/100 ≈ bare majority) must clamp up:
        // ceil(10 × 51/100) = 6, but majority+1 = 7 → 7 wins.
        $this->assertSame(7, ConstitutionalValidator::supermajority(10, 51, 100));

        // Property over realistic chamber sizes and the constitutional 2/3:
        foreach (range(5, 41) as $serving) {
            $threshold = ConstitutionalValidator::supermajority($serving);

            $this->assertGreaterThanOrEqual(
                intdiv($serving, 2) + 2,
                $threshold,
                "supermajority({$serving}) fell below majority + 1"
            );
            $this->assertSame(
                max((int) ceil($serving * 2 / 3), intdiv($serving, 2) + 2),
                $threshold,
                "supermajority({$serving}) deviates from ceil(serving × 2/3) with the majority+1 clamp"
            );
        }
    }

    public function test_quorum_is_majority_of_all_serving_members(): void
    {
        // Art. II §2: floor(serving/2) + 1. Vacancies stay out of the
        // denominator (not serving); absences stay in.
        $this->assertSame(3, ConstitutionalValidator::quorum(5));
        $this->assertSame(4, ConstitutionalValidator::quorum(6));
        $this->assertSame(4, ConstitutionalValidator::quorum(7));
        $this->assertSame(5, ConstitutionalValidator::quorum(8));
        $this->assertSame(5, ConstitutionalValidator::quorum(9));
        $this->assertSame(1000, ConstitutionalValidator::quorum(1999)); // Earth-scale
    }

    public function test_supermajority_always_exceeds_quorum(): void
    {
        foreach (range(5, 41) as $serving) {
            $this->assertGreaterThan(
                ConstitutionalValidator::quorum($serving),
                ConstitutionalValidator::supermajority($serving),
                "supermajority({$serving}) must exceed quorum({$serving})"
            );
        }
    }
}
