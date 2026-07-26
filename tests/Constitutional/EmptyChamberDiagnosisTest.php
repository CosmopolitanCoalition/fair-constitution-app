<?php

namespace Tests\Constitutional;

use App\Models\ChamberVoteTally;
use App\Services\ChamberVoteService;
use PHPUnit\Framework\TestCase;

/**
 * AN EMPTY CHAMBER IS NOT A REJECTION.
 *
 * Every bicameral failure used to surface the same way — "the vote did not
 * carry" — which is true of all three causes and useful for none of them.
 * These pin that the three stay distinguishable:
 *
 *   no members  → nobody is seated. Nothing was refused. Hold an election.
 *   no quorum   → members exist, too few took part.
 *   voted down  → the chamber met, considered it, and said no.
 *
 * This matters at exactly the moment it is least obvious. On a freshly
 * launched node, activation DECLARES a second chamber's seats but only an
 * election FILLS them, so every bicameral act correctly refuses until a
 * community holds one. Without the distinction that reads as a broken launch —
 * and the ambiguity has already cost real diagnosis time once, when an
 * act-numbering collision surfaced as "the bicameral vote is refusing to pass"
 * and was investigated as a constitutional failure.
 *
 * DB-free by design: the reasoning is a pure function so the same words appear
 * everywhere a failure is reported.
 */
class EmptyChamberDiagnosisTest extends TestCase
{
    /** THE LAUNCH CASE. Nobody seated: this must never read as a refusal. */
    public function test_an_empty_chamber_says_hold_an_election(): void
    {
        $reason = ChamberVoteService::laneFailureReason(
            ['serving' => 0, 'quorate' => false, 'passed' => false, 'present' => 0, 'yes' => 0, 'required_yes' => 2],
            ChamberVoteTally::LANE_TYPE_B,
        );

        $this->assertSame('no_members', $reason['code']);
        $this->assertStringContainsString('no members yet', $reason['message']);
        $this->assertStringContainsString('hold an election', $reason['message']);

        // It must not describe a decision that never happened.
        $this->assertStringNotContainsString('did not agree', $reason['message']);
        $this->assertStringNotContainsString('quorum', $reason['message']);
    }

    /** Members exist, too few showed up — a different problem with a different answer. */
    public function test_a_short_turnout_says_quorum_not_rejection(): void
    {
        $reason = ChamberVoteService::laneFailureReason(
            ['serving' => 27, 'quorate' => false, 'passed' => false, 'present' => 4, 'yes' => 4, 'required_yes' => 18],
            ChamberVoteTally::LANE_TYPE_B,
        );

        $this->assertSame('no_quorum', $reason['code']);
        $this->assertStringContainsString('quorum', $reason['message']);
        $this->assertStringContainsString('4 of 27', $reason['message']);
        $this->assertStringNotContainsString('hold an election', $reason['message']);
    }

    /** The chamber met and said no. The only one of the three that is a decision. */
    public function test_a_genuine_rejection_says_the_chamber_did_not_agree(): void
    {
        $reason = ChamberVoteService::laneFailureReason(
            ['serving' => 27, 'quorate' => true, 'passed' => false, 'present' => 27, 'yes' => 10, 'required_yes' => 18],
            ChamberVoteTally::LANE_TYPE_B,
        );

        $this->assertSame('voted_down', $reason['code']);
        $this->assertStringContainsString('did not agree', $reason['message']);
        $this->assertStringContainsString('10 yes of 18', $reason['message']);
        $this->assertStringNotContainsString('hold an election', $reason['message']);
    }

    /** Each chamber is named, so "which one is empty?" never needs asking. */
    public function test_each_chamber_is_named(): void
    {
        $empty = ['serving' => 0, 'quorate' => false, 'passed' => false];

        $this->assertStringContainsString(
            'first chamber',
            ChamberVoteService::laneFailureReason($empty, ChamberVoteTally::LANE_TYPE_A)['message'],
        );
        $this->assertStringContainsString(
            'second chamber',
            ChamberVoteService::laneFailureReason($empty, ChamberVoteTally::LANE_TYPE_B)['message'],
        );
        $this->assertStringContainsString(
            'chamber',
            ChamberVoteService::laneFailureReason($empty, ChamberVoteTally::LANE_ALL)['message'],
        );
    }

    /**
     * San Marino's real shape before lane 13 ran an election: 31 seated in the
     * first chamber, 0 in the second. The first must read as a genuine
     * decision and the second as an empty room — one vote, two different
     * explanations.
     */
    public function test_the_real_san_marino_case_reads_correctly_per_chamber(): void
    {
        $typeA = ChamberVoteService::laneFailureReason(
            ['serving' => 31, 'quorate' => true, 'passed' => false, 'present' => 31, 'yes' => 15, 'required_yes' => 21],
            ChamberVoteTally::LANE_TYPE_A,
        );
        $typeB = ChamberVoteService::laneFailureReason(
            ['serving' => 0, 'quorate' => false, 'passed' => false, 'present' => 0, 'yes' => 0, 'required_yes' => 2],
            ChamberVoteTally::LANE_TYPE_B,
        );

        $this->assertSame('voted_down', $typeA['code']);
        $this->assertSame('no_members', $typeB['code']);
        $this->assertNotSame($typeA['message'], $typeB['message']);
    }
}
