<?php

namespace Tests\Constitutional;

use App\Domain\Counting\BallotSet;
use App\Domain\Counting\CountInput;
use App\Domain\Engine\ConstitutionalViolation;
use App\Services\VoteCountingService;
use PHPUnit\Framework\TestCase;

/**
 * THE 5–9 BAND IS A DISTRICT RULE (operator ruling, SETTLED 2026-07-26 —
 * CLAUDE.md "Bicameral Support (Article V §3)").
 *
 * These pin the counting core's seat bound after the band was lifted out of it.
 *
 * Why the band could never live here: CountInput carries candidacyIds, seats,
 * ballots, excluded and tieSeedBase — and NO seat_kind. The core is pure by
 * design, so it cannot tell a district race from an at-large one, and a blanket
 * `seats > 9` was enforcing a districting rule blind.
 *
 * What still enforces the band, unchanged: ElectionLifecycleService::racePlan()
 * (type_a above the ceiling with no map is refused) and the
 * election_races_seats_check constraint (type_a BETWEEN 1 AND 9). Nothing was
 * widened for districted seats.
 *
 * If an edit breaks these, the edit is the constitutional violation —
 * fix the edit, not the test.
 */
class AtLargeRaceMagnitudeTest extends TestCase
{
    private function input(int $seats): CountInput
    {
        // Enough candidates that the race is decidable; ballots are irrelevant
        // to the seat-bound guard, which runs before any counting.
        $candidates = [];
        for ($i = 1; $i <= $seats + 2; $i++) {
            $candidates[] = sprintf('00000000-0000-4000-8000-%012d', $i);
        }

        // One ballot ranking every candidate — enough for the count to run to
        // completion, which is what proves the magnitude is countable rather
        // than merely accepted.
        return new CountInput(
            candidacyIds: $candidates,
            seats: $seats,
            ballots: BallotSet::fromRankings([$candidates]),
            excluded: [],
            tieSeedBase: 'pin',
        );
    }

    private function runCount(int $seats): void
    {
        (new VoteCountingService)->countStv($this->input($seats));
    }

    /**
     * SAN MARINO'S CHAMBER. Type B is at-large — the jurisdiction IS the
     * district — so 27 seats are elected together in ONE STV race. Splitting it
     * into smaller races is explicitly ruled out: STV elects N seats in one
     * race, and cutting magnitude is what destroys proportionality.
     */
    public function test_an_at_large_race_above_nine_seats_is_countable(): void
    {
        $this->runCount(27);
        $this->addToAssertionCount(1);
    }

    /**
     * ART. IV §1 GIVES A JUDICIAL RACE NO CEILING, and the schema agrees
     * (`judicial_group` is `>= 5` with no maximum). Under the old blanket guard
     * a 10-judge elected court was lawful to schedule under both the
     * constitution and the schema, and IMPOSSIBLE TO COUNT. That contradiction
     * predates the Type B question entirely.
     */
    public function test_a_judicial_group_above_nine_is_countable(): void
    {
        $this->runCount(10);
        $this->runCount(45);   // San Marino's bench: 9 constituents × 5
        $this->addToAssertionCount(2);
    }

    /** Same for an executive committee — schema says `>= 5`, no maximum. */
    public function test_an_executive_committee_above_nine_is_countable(): void
    {
        $this->runCount(11);
        $this->addToAssertionCount(1);
    }

    /**
     * THE ONE BOUND THAT IS UNIVERSAL TO COUNTING AND STAYS HARDENED.
     * Nothing can elect zero seats. This is not a districting rule, so it
     * belongs in the core and remains here.
     */
    public function test_a_race_of_zero_seats_is_still_a_violation(): void
    {
        $this->expectException(ConstitutionalViolation::class);
        $this->runCount(0);
    }

    /** Negative magnitude is likewise not a race. */
    public function test_a_negative_seat_count_is_still_a_violation(): void
    {
        $this->expectException(ConstitutionalViolation::class);
        $this->runCount(-3);
    }

    /**
     * The refusal must not justify itself with a constitutional claim the
     * constitution does not make. The old message read "outside the
     * constitutional 1–9 range" citing Art. II §2/§8 — a FALSE claim for an
     * at-large race, and §8 is the subdivision clause this guard no longer
     * enforces. A wrong citation is worse than none: it launders an
     * engineering bound as constitutional law.
     */
    public function test_the_refusal_does_not_cite_a_rule_it_no_longer_enforces(): void
    {
        try {
            $this->runCount(0);
            $this->fail('a zero-seat race must be refused');
        } catch (ConstitutionalViolation $e) {
            $this->assertStringNotContainsString('1–9', $e->getMessage());
            $this->assertStringNotContainsString('§8', (string) $e->citation);
        }
    }
}
