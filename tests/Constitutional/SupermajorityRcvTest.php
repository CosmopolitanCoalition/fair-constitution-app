<?php

namespace Tests\Constitutional;

use App\Services\ConstitutionalValidator;
use App\Services\Legislature\SpeakerService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the F-LEG-008 speaker-election win condition (PHASE_C_DESIGN_
 * chamber_ops §B.1) DB-free: countRcv provides the ROUNDS, but the WIN
 * CONDITION is the peg supermajority — the final winner's tally must
 * reach ConstitutionalValidator::supermajority(serving), with
 * NON-CASTERS AND EXHAUSTED BALLOTS STAYING IN THE DENOMINATOR (the
 * threshold is fixed over serving, never over ballots). No supermajority
 * → the balloting terminates `failed`/no_supermajority (WF-LEG-02
 * re-ballot posture: a NEW ballot, never an auto-loop, never a lowered
 * bar).
 *
 * SpeakerService::supermajorityRcvOutcome is the pure mirror of
 * ChamberVoteService::closeRcv's rcv_supermajority branch — drift between
 * the two is a test failure on either side.
 */
class SupermajorityRcvTest extends TestCase
{
    /** Montegiardino shape: 8 serving → supermajority 6. */
    public function test_unanimous_first_preferences_meet_the_supermajority(): void
    {
        $candidates = ['m-1', 'm-2', 'm-3', 'm-4', 'm-5', 'm-6', 'm-7', 'm-8'];
        $required   = ConstitutionalValidator::supermajority(8);

        $this->assertSame(6, $required);

        $rankings = array_fill(0, 8, ['m-1', 'm-2']);

        $outcome = SpeakerService::supermajorityRcvOutcome($candidates, $rankings, $required);

        $this->assertSame('m-1', $outcome['winner']);
        $this->assertNull($outcome['reason']);
    }

    public function test_majority_without_supermajority_fails_the_balloting(): void
    {
        // 8 serving, required 6: final round 5 vs 3 — an IRV majority of
        // continuing, but NOT a supermajority of serving. The balloting
        // fails; the bar never drops.
        $candidates = ['m-1', 'm-2', 'm-3', 'm-4', 'm-5', 'm-6', 'm-7', 'm-8'];
        $required   = ConstitutionalValidator::supermajority(8);

        $rankings = array_merge(
            array_fill(0, 5, ['m-1']),
            array_fill(0, 3, ['m-2']),
        );

        $outcome = SpeakerService::supermajorityRcvOutcome($candidates, $rankings, $required);

        $this->assertNull($outcome['winner']);
        $this->assertSame('no_supermajority', $outcome['reason']);
    }

    public function test_non_casters_stay_in_the_denominator(): void
    {
        // 8 serving, required 6 — only 6 cast, all for m-1: 6 ≥ 6 wins.
        // But 5 casts for m-1 with 3 abstentions-by-absence: 5 < 6 fails,
        // because the denominator is SERVING, not ballots.
        $candidates = ['m-1', 'm-2', 'm-3', 'm-4', 'm-5', 'm-6', 'm-7', 'm-8'];
        $required   = ConstitutionalValidator::supermajority(8);

        $six = SpeakerService::supermajorityRcvOutcome($candidates, array_fill(0, 6, ['m-1']), $required);
        $this->assertSame('m-1', $six['winner']);

        $five = SpeakerService::supermajorityRcvOutcome($candidates, array_fill(0, 5, ['m-1']), $required);
        $this->assertNull($five['winner']);
        $this->assertSame('no_supermajority', $five['reason']);
    }

    public function test_exhausted_ballots_stay_in_the_denominator(): void
    {
        // 8 cast; 3 ballots rank ONLY m-3 (eliminated early) and exhaust.
        // Final round: m-1 has 5 of 8 serving — required 6 → failed. The
        // exhausted ballots never shrink the bar.
        $candidates = ['m-1', 'm-2', 'm-3', 'm-4', 'm-5', 'm-6', 'm-7', 'm-8'];
        $required   = ConstitutionalValidator::supermajority(8);

        $rankings = array_merge(
            array_fill(0, 4, ['m-1']),
            array_fill(0, 1, ['m-2', 'm-1']),
            array_fill(0, 3, ['m-3']), // exhaust at m-3's elimination
        );

        $outcome = SpeakerService::supermajorityRcvOutcome($candidates, $rankings, $required);

        $this->assertNull($outcome['winner']);
        $this->assertSame('no_supermajority', $outcome['reason']);
    }

    public function test_transfers_can_build_the_supermajority_across_rounds(): void
    {
        // 8 serving, required 6: first preferences 4/2/2 — no winner in
        // round 1; transfers consolidate to 6 for m-1.
        $candidates = ['m-1', 'm-2', 'm-3', 'm-4', 'm-5', 'm-6', 'm-7', 'm-8'];
        $required   = ConstitutionalValidator::supermajority(8);

        $rankings = array_merge(
            array_fill(0, 4, ['m-1']),
            array_fill(0, 2, ['m-2', 'm-1']),
            array_fill(0, 2, ['m-3', 'm-2']),
        );

        $outcome = SpeakerService::supermajorityRcvOutcome($candidates, $rankings, $required);

        $this->assertSame('m-1', $outcome['winner']);
    }

    /** San Marino shape: 41 serving → supermajority 28 (whole chamber, one lane). */
    public function test_san_marino_threshold_is_28_of_41(): void
    {
        $this->assertSame(28, ConstitutionalValidator::supermajority(41));

        $candidates = array_map(fn ($i) => "m-{$i}", range(1, 41));

        $rankings = array_merge(
            array_fill(0, 28, ['m-1']),
            array_fill(0, 13, ['m-2']),
        );

        $outcome = SpeakerService::supermajorityRcvOutcome($candidates, $rankings, 28);

        $this->assertSame('m-1', $outcome['winner']);

        $rankings = array_merge(
            array_fill(0, 27, ['m-1']),
            array_fill(0, 14, ['m-2']),
        );

        $outcome = SpeakerService::supermajorityRcvOutcome($candidates, $rankings, 28);

        $this->assertNull($outcome['winner'], '27 of 41 is not a supermajority — re-ballot, never a lowered bar');
    }

    public function test_no_ballots_is_a_failed_balloting(): void
    {
        $outcome = SpeakerService::supermajorityRcvOutcome(['m-1', 'm-2'], [], 2);

        $this->assertNull($outcome['winner']);
        $this->assertSame('no_ballots', $outcome['reason']);
    }
}
