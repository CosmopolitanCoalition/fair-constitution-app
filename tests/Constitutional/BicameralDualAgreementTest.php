<?php

namespace Tests\Constitutional;

use App\Models\ChamberVote;
use App\Services\ChamberVoteService;
use App\Services\ConstitutionalValidator;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. V §3 bicameral dual agreement (q-ledger #q7)
 * (replaces the FuturePhasePlaceholdersTest skip
 * test_bicameral_dual_agreement_per_kind).
 *
 * Type A (constituent) and type B (at-large) seat kinds must EACH
 * independently satisfy their OWN peg quorum and majority/supermajority
 * — at committee AND floor. The engine realizes this structurally: lanes
 * are rows running identical math; a vote adopts only when every lane
 * passes. San Marino-shaped fixture: 32 type_a + 9 type_b serving.
 *
 * DB-free (established posture); the row-level lane shape is exercised
 * by the live tinker verification against the real San Marino chamber.
 */
class BicameralDualAgreementTest extends TestCase
{
    /** San Marino live shape. */
    private const TYPE_A = 32;

    private const TYPE_B = 9;

    public function test_bicameral_chamber_votes_in_exactly_two_kind_lanes(): void
    {
        $lanes = ChamberVoteService::lanePlan(true, ['type_a' => self::TYPE_A, 'type_b' => self::TYPE_B]);

        $this->assertSame(['type_a' => 32, 'type_b' => 9], $lanes);

        // Unicameral bodies produce exactly one 'all' lane.
        $this->assertSame(['all' => 8], ChamberVoteService::lanePlan(false, ['all' => 8]));
        $this->assertSame(['all' => 41], ChamberVoteService::lanePlan(false, ['type_a' => 32, 'type_b' => 9]));
    }

    public function test_each_lane_carries_its_own_independent_thresholds(): void
    {
        $a = ChamberVoteService::laneThresholds(self::TYPE_A, ChamberVote::BASIS_MAJORITY);
        $b = ChamberVoteService::laneThresholds(self::TYPE_B, ChamberVote::BASIS_MAJORITY);

        // type_a: 17 of 32 — type_b: 5 of 9. Never pooled, never shared.
        $this->assertSame(17, $a['quorum_required']);
        $this->assertSame(17, $a['required_yes']);
        $this->assertSame(5, $b['quorum_required']);
        $this->assertSame(5, $b['required_yes']);

        // Per-kind SUPERMAJORITY uses each lane's own serving (q7).
        $this->assertSame(
            ConstitutionalValidator::supermajority(self::TYPE_A),
            ChamberVoteService::laneThresholds(self::TYPE_A, 'supermajority')['required_yes']
        );
        $this->assertSame(22, ChamberVoteService::laneThresholds(self::TYPE_A, 'supermajority')['required_yes']);
        $this->assertSame(6, ChamberVoteService::laneThresholds(self::TYPE_B, 'supermajority')['required_yes']);
    }

    public function test_failing_one_kind_fails_the_act_with_the_failing_kind_named(): void
    {
        // type_a passes overwhelmingly (30 of 32); type_b fails its own
        // threshold (4 yes / 5 no of 9) → the act FAILS (q7).
        $results = [
            'type_a' => ChamberVoteService::laneResult(32, 17, 17, 32, 30, 2),
            'type_b' => ChamberVoteService::laneResult(9, 5, 5, 9, 4, 5),
        ];

        $this->assertTrue($results['type_a']['passed']);
        $this->assertFalse($results['type_b']['passed']);
        $this->assertSame(ChamberVote::OUTCOME_FAILED, ChamberVoteService::voteOutcome($results));

        // The failing kind is identifiable from the lane rows themselves.
        $failing = array_keys(array_filter($results, fn (array $r) => ! $r['passed']));
        $this->assertSame(['type_b'], $failing);
    }

    public function test_failing_one_kinds_quorum_fails_the_act(): void
    {
        // type_b present 3 < its own quorum 5: even a unanimous type_b
        // yes among those present cannot pass — no bicameral act can
        // validly pass where one kind is below ITS quorum.
        $results = [
            'type_a' => ChamberVoteService::laneResult(32, 17, 17, 32, 30, 2),
            'type_b' => ChamberVoteService::laneResult(9, 5, 5, 3, 3, 0),
        ];

        $this->assertFalse($results['type_b']['quorate']);
        $this->assertFalse($results['type_b']['passed']);
        $this->assertSame(ChamberVote::OUTCOME_FAILED, ChamberVoteService::voteOutcome($results));
    }

    public function test_both_kinds_passing_adopts(): void
    {
        $results = [
            'type_a' => ChamberVoteService::laneResult(32, 17, 17, 32, 17, 15),
            'type_b' => ChamberVoteService::laneResult(9, 5, 5, 9, 5, 4),
        ];

        $this->assertSame(ChamberVote::OUTCOME_ADOPTED, ChamberVoteService::voteOutcome($results));
    }

    public function test_same_math_at_committee_and_floor(): void
    {
        // q7 applies at BOTH stages: the threshold functions carry no
        // stage parameter — committee lanes (e.g. a 5-seat committee
        // mirrored 4a + 1b) run the very same arithmetic as the floor.
        $params = array_map(
            fn (\ReflectionParameter $p) => $p->getName(),
            (new \ReflectionMethod(ChamberVoteService::class, 'laneThresholds'))->getParameters()
        );
        $this->assertNotContains('stage', $params);

        $committeeA = ChamberVoteService::laneThresholds(4, ChamberVote::BASIS_MAJORITY);
        $committeeB = ChamberVoteService::laneThresholds(1, ChamberVote::BASIS_MAJORITY);

        $this->assertSame(3, $committeeA['required_yes']); // quorum(4) = 3
        $this->assertSame(1, $committeeB['required_yes']); // quorum(1) = 1

        // A committee vote failing its type_b lane fails — exactly the
        // floor rule.
        $this->assertSame(
            ChamberVote::OUTCOME_FAILED,
            ChamberVoteService::voteOutcome([
                'type_a' => ChamberVoteService::laneResult(4, 3, 3, 4, 4, 0),
                'type_b' => ChamberVoteService::laneResult(1, 1, 1, 1, 0, 1),
            ])
        );
    }

    public function test_registry_realizes_dual_agreement_on_the_chamber_keys(): void
    {
        // The structural registry row…
        $this->assertSame('per_kind', config('constitution.vote_types.bicameral_dual_agreement.bicameral'));

        // …is realized as bicameral: per_kind on the deliberative chamber
        // keys — bills at both stages and the unstated-threshold default.
        foreach (['bill_pass', 'committee_bill', 'procedural_motion'] as $key) {
            $this->assertSame(
                'per_kind',
                config("constitution.vote_types.{$key}.bicameral"),
                "{$key} must run per-kind lanes in bicameral chambers"
            );
        }
    }
}
