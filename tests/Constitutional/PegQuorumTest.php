<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\ChamberVote;
use App\Services\ChamberVoteService;
use App\Services\ConstitutionalValidator;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. II §2 peg quorum in the CHAMBER VOTE ENGINE
 * (replaces the FuturePhasePlaceholdersTest skip
 * test_peg_quorum_uses_all_serving_members).
 *
 * Quorum and pass thresholds are computed over ALL serving members —
 * never members present, never total seats. A vacant seat is not serving
 * (it leaves the denominator); an absent or abstaining member IS serving
 * (it stays in) and is arithmetically identical to a no. The Speaker is
 * a serving member: they stay in every denominator and may not cast
 * outside F-SPK-004.
 *
 * Pinned THROUGH the service's pure math (laneThresholds / laneResult /
 * voteOutcome / assertMemberMayCast), which DELEGATES to the two
 * PROTECTED functions — asserted identical below, so the engine can
 * never grow its own arithmetic. DB-free (established posture); the
 * DB-backed paths are exercised by the live tinker verification.
 *
 * If an edit to ChamberVoteService or ConstitutionalValidator breaks
 * these tests, the edit is the violation — fix the edit, never the test.
 */
class PegQuorumTest extends TestCase
{
    public function test_lane_thresholds_delegate_to_the_protected_functions(): void
    {
        // Realistic chamber sizes 5–9 plus the live San Marino chamber (41).
        foreach ([5, 6, 7, 8, 9, 41] as $serving) {
            $majority = ChamberVoteService::laneThresholds($serving, ChamberVote::BASIS_MAJORITY);

            $this->assertSame(ConstitutionalValidator::quorum($serving), $majority['quorum_required']);
            $this->assertSame(ConstitutionalValidator::quorum($serving), $majority['required_yes']);
            $this->assertSame(intdiv($serving, 2) + 1, $majority['required_yes'], "majority({$serving})");

            $super = ChamberVoteService::laneThresholds($serving, ChamberVote::BASIS_SUPERMAJORITY);

            $this->assertSame(ConstitutionalValidator::supermajority($serving), $super['required_yes']);
            $this->assertSame(
                max((int) ceil($serving * 2 / 3), intdiv($serving, 2) + 2),
                $super['required_yes'],
                "supermajority({$serving})"
            );

            // The denominator snapshot IS serving — recorded, never recomputed.
            $this->assertSame($serving, $majority['serving']);
            $this->assertSame($serving, $super['serving']);
        }

        // Earth scale: quorum(1999) = 1000, supermajority = ceil(2·1999/3) = 1333.
        $this->assertSame(1000, ChamberVoteService::laneThresholds(1999, 'majority')['required_yes']);
        $this->assertSame(1333, ChamberVoteService::laneThresholds(1999, 'supermajority')['required_yes']);
    }

    public function test_vacancy_leaves_the_denominator_montegiardino_fixture(): void
    {
        // Montegiardino live shape: 9 seats, 1 vacancy → 8 SERVING.
        // The vacancy lowered the thresholds (5/6 of 8) — the vacant seat
        // is simply not serving.
        $majority = ChamberVoteService::laneThresholds(8, ChamberVote::BASIS_MAJORITY);
        $super    = ChamberVoteService::laneThresholds(8, ChamberVote::BASIS_SUPERMAJORITY);

        $this->assertSame(5, $majority['quorum_required']);
        $this->assertSame(5, $majority['required_yes']);
        $this->assertSame(6, $super['required_yes']);
    }

    public function test_absent_member_is_arithmetically_a_no(): void
    {
        // 8 serving, majority 5. Seven members show up; 4 yes / 3 no with
        // 1 ABSENT: the absent member stayed in the denominator, so 4 < 5
        // — the vote FAILS. Absence never shrinks required_yes.
        $result = ChamberVoteService::laneResult(
            serving: 8, quorumRequired: 5, requiredYes: 5,
            present: 7, yes: 4, no: 3,
        );

        $this->assertTrue($result['quorate']);
        $this->assertFalse($result['passed']);

        // The same chamber with the fifth supporter present: 5 yes adopts.
        $this->assertTrue(ChamberVoteService::laneResult(8, 5, 5, 8, 5, 3)['passed']);
    }

    public function test_abstain_never_counts_toward_yes(): void
    {
        // 4 yes / 0 no / 4 abstain on serving 8: abstentions are recorded
        // for the public record but count as nothing toward the threshold
        // — arithmetically identical to a no. 4 < 5 fails.
        $result = ChamberVoteService::laneResult(
            serving: 8, quorumRequired: 5, requiredYes: 5,
            present: 8, yes: 4, no: 0,
        );

        $this->assertFalse($result['passed']);
        $this->assertFalse($result['tie_state'], 'an abstention block is not a tie');
    }

    public function test_outcome_can_never_be_computed_from_present(): void
    {
        // `present` gates ONLY quorum. Once quorate, the pass line is
        // invariant to how many showed up:
        $this->assertTrue(ChamberVoteService::laneResult(8, 5, 5, 5, 5, 0)['passed']);
        $this->assertTrue(ChamberVoteService::laneResult(8, 5, 5, 8, 5, 3)['passed']);

        // And no yes count can pass without quorum:
        $this->assertFalse(ChamberVoteService::laneResult(8, 5, 5, 4, 5, 0)['passed']);
        $this->assertFalse(ChamberVoteService::laneResult(8, 5, 5, 4, 5, 0)['quorate']);

        // Structural: the outcome function consumes lane results only —
        // no present-derived majority exists anywhere in its signature.
        $signature = (new \ReflectionMethod(ChamberVoteService::class, 'voteOutcome'))->getParameters();
        $this->assertCount(1, $signature);
        $this->assertSame('laneResults', $signature[0]->getName());
    }

    public function test_speaker_stays_in_denominator_and_casts_only_via_f_spk_004(): void
    {
        // The chamber fixture: 8 serving INCLUDING the Speaker → quorum 5,
        // supermajority 6. The Speaker is never subtracted from serving.
        $this->assertSame(5, ChamberVoteService::laneThresholds(8, 'majority')['quorum_required']);
        $this->assertSame(6, ChamberVoteService::laneThresholds(8, 'supermajority')['required_yes']);

        // Speaker casting on yes/no business outside F-SPK-004: rejected
        // with the Art. II §3 citation.
        try {
            ChamberVoteService::assertMemberMayCast(isSpeaker: true, voteMethod: 'yes_no', viaForm: 'F-LEG-004');
            $this->fail('Speaker yes/no cast outside F-SPK-004 must be rejected');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. II §3', $e->citation);
        }

        // The tie-break form is the one door…
        ChamberVoteService::assertMemberMayCast(isSpeaker: true, voteMethod: 'yes_no', viaForm: 'F-SPK-004');

        // …and constitutive RCV elections are cast by ALL serving members,
        // Speaker included (their exclusion would warp the supermajority-
        // of-serving denominator).
        ChamberVoteService::assertMemberMayCast(isSpeaker: true, voteMethod: 'rcv', viaForm: 'F-LEG-008');

        // Non-speakers cast freely.
        ChamberVoteService::assertMemberMayCast(isSpeaker: false, voteMethod: 'yes_no', viaForm: 'F-LEG-004');
    }

    public function test_majority_tie_is_tied_supermajority_tie_is_failed(): void
    {
        // 8 serving, majority 5: a 4–4 closes TIED — one speaker yes would
        // reach 5 (the F-SPK-004 window).
        $tie = ChamberVoteService::laneResult(8, 5, 5, 8, 4, 4);
        $this->assertTrue($tie['tie_state']);
        $this->assertSame(ChamberVote::OUTCOME_TIED, ChamberVoteService::voteOutcome(['all' => $tie]));

        // 8 serving, supermajority 6: 4–4 is NOT a resolvable tie — one
        // vote cannot manufacture a supermajority. Plain failure.
        $superTie = ChamberVoteService::laneResult(8, 5, 6, 8, 4, 4);
        $this->assertFalse($superTie['tie_state']);
        $this->assertSame(ChamberVote::OUTCOME_FAILED, ChamberVoteService::voteOutcome(['all' => $superTie]));
    }

    public function test_supermajority_is_never_below_majority_plus_one(): void
    {
        // The clamp travels through the engine untouched (degenerate
        // 51/100 fraction at serving 10 clamps to 7).
        $this->assertSame(
            7,
            ChamberVoteService::laneThresholds(10, ChamberVote::BASIS_SUPERMAJORITY, 51, 100)['required_yes']
        );
    }
}
