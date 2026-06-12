<?php

namespace Tests\Constitutional;

use App\Domain\Counting\BallotSet;
use App\Domain\Counting\CountInput;
use App\Domain\Counting\CountResult;
use App\Domain\Counting\Micro;
use App\Services\VoteCountingService;
use PHPUnit\Framework\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. III §3: single-winner RCV (instant-runoff)
 * for the individual executive, and the top-4 advisor derivation by
 * sequential exclusion (WF-ELE-08).
 *
 * The win test is a majority of CONTINUING ballots — exhaustion can
 * never deadlock the count. The stored quota field is the display
 * majority floor(total/2)+1 (mockup convention) and is NOT the win
 * test. Advisors come from full exclusion re-runs, never from reading
 * the base count's standings.
 *
 * DB-free (established posture).
 */
class RcvTest extends TestCase
{
    private VoteCountingService $svc;

    protected function setUp(): void
    {
        $this->svc = new VoteCountingService;
    }

    public function test_win_is_majority_of_continuing_not_of_total(): void
    {
        // A 4, B 3, C 2 (total 9; display majority 5). C's ballots have
        // no later preferences: once C is out, only 7 ballots continue
        // and A's 4 is a majority OF CONTINUING — below the display
        // majority of the original total.
        $in = new CountInput(
            candidacyIds: ['A', 'B', 'C'],
            seats: 1,
            ballots: BallotSet::fromGrouped([
                [['A'], 4],
                [['B'], 3],
                [['C'], 2],
            ]),
            tieSeedBase: 'rcv-majority',
        );

        $r = $this->svc->countRcv($in);

        $this->assertSame('rcv', $r->kind);
        $this->assertSame(5, $r->quota); // display majority only
        $this->assertCount(2, $r->rounds);

        [$r1, $r2] = $r->rounds;

        $this->assertSame('eliminate', $r1->action);
        $this->assertSame('C', $r1->candidacyId);
        $this->assertSame('elimination', $r1->transfer['kind']);
        $this->assertNull($r1->transfer['value_micro']);
        $this->assertSame(2_000_000, $r1->transfer['exhausted_micro']);

        $this->assertSame('elect', $r2->action);
        $this->assertSame('A', $r2->candidacyId);
        $this->assertNull($r2->transfer);
        $this->assertSame(4_000_000, $r2->tallies['candidates']['A']); // 4 < 5: continuing majority

        $this->assertSame([['candidacy_id' => 'A', 'round' => 2, 'seat_no' => 1]], $r->elected);
        $this->assertRcvConservation($r);
    }

    public function test_exhaustion_cannot_deadlock_and_ties_resolve_by_lot(): void
    {
        // All bullet ballots: every elimination exhausts. A and B are
        // tied in every round → seeded lot decides; the survivor then
        // holds ALL continuing ballots and wins.
        $in = new CountInput(
            candidacyIds: ['A', 'B', 'C'],
            seats: 1,
            ballots: BallotSet::fromGrouped([
                [['A'], 3],
                [['B'], 3],
                [['C'], 2],
            ]),
            tieSeedBase: 'rcv-deadlock',
        );

        $r = $this->svc->countRcv($in);

        $this->assertCount(3, $r->rounds);
        $this->assertSame('C', $r->rounds[0]->candidacyId);

        $tie = $r->rounds[1]->transfer['tie_break'];
        $this->assertSame('lot', $tie['stage']);
        $this->assertSame(hash('sha256', 'rcv-deadlock:2'), $tie['seed']);

        $loser = $r->rounds[1]->candidacyId;
        $winner = $loser === 'A' ? 'B' : 'A';
        $this->assertSame($winner, $r->elected[0]['candidacy_id']);
        $this->assertSame(5_000_000, $r->exhaustedMicro); // C's 2 + the lot loser's 3

        // Deterministic across runs.
        $this->assertSame($r->recordHash(), $this->svc->countRcv($in)->recordHash());
        $this->assertRcvConservation($r);
    }

    public function test_final_two_tie_resolves_by_lot_then_survivor_wins(): void
    {
        $in = new CountInput(
            candidacyIds: ['A', 'B'],
            seats: 1,
            ballots: BallotSet::fromGrouped([
                [['A'], 1],
                [['B'], 1],
            ]),
            tieSeedBase: 'rcv-final-two',
        );

        $r = $this->svc->countRcv($in);

        $this->assertCount(2, $r->rounds);
        $this->assertSame('eliminate', $r->rounds[0]->action);
        $this->assertSame('lot', $r->rounds[0]->transfer['tie_break']['stage']);
        $this->assertSame('elect', $r->rounds[1]->action);
        $this->assertCount(1, $r->elected);
        $this->assertRcvConservation($r);
    }

    public function test_rcv_requires_exactly_one_seat(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->svc->countRcv(new CountInput(
            ['A', 'B'],
            2,
            BallotSet::fromGrouped([[['A'], 1]]),
        ));
    }

    /**
     * Advisors are derived by SEQUENTIAL EXCLUSION re-runs (re-run the
     * count without the winner, then without the top two, …), NOT by
     * reading the base count's final standings. Center-squeeze fixture:
     * the base runner-up by raw final-round count is C, but the first
     * exclusion re-run elects B — proving the re-run.
     */
    public function test_advisors_by_sequential_exclusion_not_standings(): void
    {
        // 8×[A,B]  7×[C,B]  6×[B,A]  (total 21)
        // Base: B eliminated first (6 < 7 < 8) → A wins 14 v 7 (runner-up C).
        // Re-run without A: B holds 14 (8×[B] + 6×[B]) v C 7 → advisor 1 = B.
        // Re-run without A,B: only 7×[C] remain valid → advisor 2 = C.
        // No candidates left → advisor ranks 3 and 4 stay vacant.
        $in = new CountInput(
            candidacyIds: ['A', 'B', 'C'],
            seats: 1,
            ballots: BallotSet::fromGrouped([
                [['A', 'B'], 8],
                [['C', 'B'], 7],
                [['B', 'A'], 6],
            ]),
            tieSeedBase: 'advisors',
        );

        $results = $this->svc->deriveAdvisors($in);

        $this->assertCount(5, $results);

        [$base, $a1, $a2, $a3, $a4] = $results;

        $this->assertSame('A', $base->elected[0]['candidacy_id']);

        // Base final-round runner-up is C…
        $finalRound = $base->rounds[count($base->rounds) - 1];
        $standings = $finalRound->tallies['candidates'];
        unset($standings['A']);
        arsort($standings);
        $this->assertSame('C', array_key_first($standings));

        // …but advisor 1 is B — exclusion re-run, not standings-read.
        $this->assertSame('B', $a1->elected[0]['candidacy_id']);
        $this->assertSame('C', $a2->elected[0]['candidacy_id']);

        // Fewer than 5 candidates → remaining advisor ranks stay vacant.
        $this->assertNull($a3);
        $this->assertNull($a4);

        // Each derivation is its own full count over re-canonicalized
        // ballots (stored as its own tabulations row by the caller).
        $this->assertInstanceOf(CountResult::class, $a1);
        $this->assertSame(21, $a1->totalValid);  // every ballot still ranks someone
        $this->assertSame(7, $a2->totalValid);   // only [C,...] ballots survive
        $this->assertNotSame($base->recordHash(), $a1->recordHash());

        // Determinism of the whole derivation.
        $again = $this->svc->deriveAdvisors($in);
        $this->assertSame($a1->recordHash(), $again[1]->recordHash());
        $this->assertSame($a2->recordHash(), $again[2]->recordHash());
    }

    public function test_round_record_shape_parity_with_stv(): void
    {
        $in = new CountInput(
            candidacyIds: ['A', 'B', 'C'],
            seats: 1,
            ballots: BallotSet::fromGrouped([
                [['A'], 4],
                [['B', 'A'], 3],
                [['C', 'B'], 2],
            ]),
            tieSeedBase: 'shape',
        );

        $r = $this->svc->countRcv($in);

        foreach ($r->rounds as $round) {
            $arr = $round->toArray();
            $this->assertSame(
                ['round_no', 'action', 'candidacy_id', 'transfer', 'tallies'],
                array_keys($arr),
            );
            $this->assertContains($arr['action'], ['elect', 'eliminate']);

            if ($arr['transfer'] !== null) {
                $this->assertSame(
                    ['kind', 'value_micro', 'to', 'exhausted_micro', 'truncation_residue_micro', 'tie_break'],
                    array_keys($arr['transfer']),
                );
                $this->assertSame('elimination', $arr['transfer']['kind']);
            }

            $this->assertArrayHasKey('candidates', $arr['tallies']);
            $this->assertArrayHasKey('exhausted_micro', $arr['tallies']);
            $this->assertArrayHasKey('elected_so_far', $arr['tallies']);
        }

        $this->assertRcvConservation($r);
    }

    /** IRV moves whole ballots: conservation holds with zero residue. */
    private function assertRcvConservation(CountResult $r): void
    {
        $totalMicro = $r->totalValid * Micro::SCALE;

        $this->assertSame(0, $r->truncationResidueMicro);

        foreach ($r->rounds as $round) {
            $this->assertSame(
                $totalMicro,
                array_sum($round->tallies['candidates']) + $round->tallies['exhausted_micro'],
                "conservation broken at round {$round->roundNo}",
            );
        }

        $this->assertSame(
            $totalMicro,
            array_sum($r->finalTallies) + $r->exhaustedMicro,
            'final conservation',
        );
    }
}
