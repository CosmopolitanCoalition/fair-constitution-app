<?php

namespace Tests\Constitutional;

use App\Domain\Counting\BallotSet;
use App\Domain\Counting\CountInput;
use App\Domain\Counting\Micro;
use App\Services\VoteCountingService;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Gregory surplus transfer truncates the PER-BALLOT WEIGHT
 * and only THEN multiplies by the group's multiplicity.
 *
 * VoteCountingService.php (surplus block):
 *     $w2  = Micro::mulDiv($gW[$gi], $S, $T);   // per-ballot truncation
 *     $amt = $gMult[$gi] * $w2;                 // …THEN multiply
 *
 * WHY THIS ORDER IS CONSTITUTIONAL, NOT INCIDENTAL. Identical ballots are
 * stored once with a multiplicity — `BallotSet` groups them and the counting
 * core never sees individual ballots at all. That representation is only
 * FAITHFUL to one-person-one-vote if a group of N behaves exactly as N separate
 * voters would. Flooring per ballot and then multiplying guarantees it:
 * N × floor(w·S/T) is identically the sum of N separate floor(w·S/T)
 * applications.
 *
 * The plausible "simplification" — aggregate first, floor once:
 *     $amt = Micro::mulDiv($gMult[$gi] * $gW[$gi], $S, $T);
 * silently gives a DIFFERENT answer, because floor is not distributive over
 * multiplication. It would hand a group of N voters slightly more transfer
 * value than N individuals casting the same ballot, and the discrepancy grows
 * with multiplicity — precisely the regime the simulated world runs in, where
 * a single group can carry millions of voters.
 *
 * Nothing else guards the ORDER: every ballot-level identity test passes under
 * either arithmetic, because both constructors already collapse to the same
 * groups before the engine is entered. This test is the only thing standing
 * between that refactor and a silently wrong planet.
 *
 * THE FIXTURE (hand-computed, deterministic, no RNG):
 *   3 ballots [A,B] · 2 ballots [C] · seats = 2
 *   total 5 → quota = floor(5/3)+1 = 2 → quotaMicro = 2,000,000
 *   A elected round 1 with T = 3,000,000; surplus S = 1,000,000
 *   per-ballot: w2 = floor(1,000,000 × 1,000,000 / 3,000,000) = 333,333
 *               amt = 3 × 333,333                             = 999,999  ← residue 1
 *   aggregate : amt = floor(3,000,000 × 1,000,000 / 3,000,000) = 1,000,000 ← residue 0
 *
 * The two arithmetics differ by exactly one micro-vote, and this test asserts
 * the engine produces the per-ballot answer.
 *
 * If an edit breaks this, the edit is the violation — fix the edit, not the test.
 */
class GregoryTruncationOrderTest extends TestCase
{
    public function test_surplus_truncates_per_ballot_then_multiplies(): void
    {
        $a = '11111111-1111-4111-8111-111111111111';
        $b = '22222222-2222-4222-8222-222222222222';
        $c = '33333333-3333-4333-8333-333333333333';

        $result = (new VoteCountingService)->countStv(new CountInput(
            candidacyIds: [$a, $b, $c],
            seats: 2,
            ballots: BallotSet::fromGrouped([
                [[$a, $b], 3],
                [[$c], 2],
            ]),
            excluded: [],
            tieSeedBase: 'gregory-truncation-order-pin',
        ));

        // The quota the fixture is built around.
        $this->assertSame(2, $result->quota, 'Droop quota = floor(5/3)+1');
        $this->assertSame(5, $result->totalValid);

        $surplus = 1 * Micro::SCALE;                       // 1,000,000 µv
        $perBallot = Micro::mulDiv(Micro::SCALE, $surplus, 3 * Micro::SCALE);
        $this->assertSame(333333, $perBallot, 'floor(1e6 × 1e6 / 3e6)');

        $perBallotTotal = 3 * $perBallot;                  //   999,999
        $aggregateTotal = Micro::mulDiv(3 * Micro::SCALE, $surplus, 3 * Micro::SCALE); // 1,000,000

        $this->assertNotSame(
            $perBallotTotal,
            $aggregateTotal,
            'the fixture is only meaningful if the two arithmetics actually differ'
        );

        // THE PIN. Residue is S − moved, so it is the difference the two
        // arithmetics disagree about, surfaced directly on the result.
        $this->assertSame(
            $surplus - $perBallotTotal,
            $result->truncationResidueMicro,
            'truncation residue must equal S − N×floor(w·S/T) = 1 µv. Reading 0 here means the '
            .'surplus block was changed to aggregate-then-floor '
            .'(Micro::mulDiv($gMult * $gW, $S, $T)), which breaks one-person-one-vote for every '
            .'grouped ballot and silently inflates transfers at simulated-world multiplicities.'
        );

        $this->assertSame(1, $result->truncationResidueMicro, 'the hand-computed value');
    }

    /**
     * The same property at simulated-world scale: a single group carrying
     * millions of voters must still transfer N×floor(w·S/T). Here the gap
     * between the two arithmetics is large rather than a single µv, so a
     * regression is unmissable.
     */
    public function test_the_property_holds_at_demo_multiplicities(): void
    {
        $a = '44444444-4444-4444-8444-444444444444';
        $b = '55555555-5555-4555-8555-555555555555';
        $c = '66666666-6666-4666-8666-666666666666';

        $n = 3_000_001;   // deliberately not divisible by 3
        $m = 2_000_000;

        $result = (new VoteCountingService)->countStv(new CountInput(
            candidacyIds: [$a, $b, $c],
            seats: 2,
            ballots: BallotSet::fromGrouped([
                [[$a, $b], $n],
                [[$c], $m],
            ]),
            excluded: [],
            tieSeedBase: 'gregory-truncation-order-pin-at-scale',
        ));

        $total = $n + $m;
        $quota = intdiv($total, 3) + 1;
        $this->assertSame($quota, $result->quota);

        $t = $n * Micro::SCALE;
        $s = $t - $quota * Micro::SCALE;

        $perBallot = Micro::mulDiv(Micro::SCALE, $s, $t);
        $expectedResidue = $s - $n * $perBallot;

        $this->assertSame(
            $expectedResidue,
            $result->truncationResidueMicro,
            'per-ballot truncation must hold at multiplicities the simulated world actually uses'
        );

        // And the residue is strictly less than one whole vote — truncation
        // loses fractions, never votes.
        $this->assertLessThan(
            Micro::SCALE,
            $result->truncationResidueMicro,
            'truncation residue must never reach a whole vote'
        );
    }
}
