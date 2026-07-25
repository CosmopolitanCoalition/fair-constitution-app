<?php

namespace Tests\Constitutional;

use App\Domain\Counting\BallotSet;
use App\Domain\Counting\CountInput;
use App\Services\VoteCountingService;
use Tests\Support\SyntheticBallotGenerator;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the counting engine's performance budget.
 *
 * `docs/plans/institutions/PHASE_B_DESIGN_counting_engine.md` §C.8 budgeted
 * **500,000 ballots / 24 candidates / 9 seats in under 60 s and under 256 MB**
 * and noted "Earth scale is N *parallel* races of district-sized electorates,
 * so this single-race budget dominates everything real."
 *
 * That budget was never implemented. This is it. Measured on the dev box the
 * real figure is ~0.9 s at ~14 MB — the budget is met roughly 67× over — so the
 * assertions below are deliberately generous: they exist to catch an
 * ALGORITHMIC REGRESSION (an accidental O(ballots) path where the engine is
 * O(distinct rankings)), not to police normal machine variance.
 *
 * WHY IT MATTERS BEYOND ITS OWN LANE. The whole simulated world rests on the
 * `BallotSet` property that cost tracks DISTINCT RANKINGS, not ballot count —
 * that is what lets a planet-scale electorate be counted exactly instead of
 * approximately. If that property ever breaks, it breaks silently: results stay
 * correct and the engine just gets slower, until a planetary run that should
 * take an hour takes a month. The collapse assertion below is the tripwire.
 *
 * Uses `tests/Support/SyntheticBallotGenerator` verbatim — no DB, no clock, no
 * fixtures; the counting core is pure.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class CountingEnginePerformanceTest extends TestCase
{
    /** The design spec's own numbers. */
    private const SPEC_BALLOTS = 500_000;

    private const SPEC_CANDIDATES = 24;

    private const SPEC_SEATS = 9;

    private const SPEC_BUDGET_SECONDS = 60.0;

    private const SPEC_BUDGET_BYTES = 256 * 1024 * 1024;

    public function test_the_phase_b_performance_budget_is_met(): void
    {
        $ids = SyntheticBallotGenerator::candidateIds(self::SPEC_CANDIDATES);
        $groups = SyntheticBallotGenerator::grouped(
            seed: 20260725,
            ballots: self::SPEC_BALLOTS,
            candidateIds: $ids,
        );

        $ballots = BallotSet::fromGrouped($groups);
        $this->assertSame(self::SPEC_BALLOTS, $ballots->count(), 'the generator must emit the full electorate');

        $input = new CountInput(
            candidacyIds: $ids,
            seats: self::SPEC_SEATS,
            ballots: $ballots,
            excluded: [],
            tieSeedBase: 'phase-b-performance-pin',
        );

        $before = memory_get_usage(true);
        $started = microtime(true);

        $result = (new VoteCountingService)->countStv($input);

        $elapsed = microtime(true) - $started;
        $used = memory_get_usage(true) - $before;

        // Correctness first — a fast wrong answer is not a pass.
        $this->assertCount(self::SPEC_SEATS, $result->elected, 'every seat must be filled');
        $this->assertSame(self::SPEC_BALLOTS, $result->totalValid);
        $this->assertSame(
            intdiv(self::SPEC_BALLOTS, self::SPEC_SEATS + 1) + 1,
            $result->quota,
            'Droop quota'
        );

        $this->assertLessThan(
            self::SPEC_BUDGET_SECONDS,
            $elapsed,
            sprintf(
                'PHASE_B_DESIGN §C.8 budgets %.0fs for %d ballots / %d candidates / %d seats; took %.3fs. '
                .'The measured baseline is ~0.9s, so a failure here is an algorithmic regression, not slow hardware.',
                self::SPEC_BUDGET_SECONDS,
                self::SPEC_BALLOTS,
                self::SPEC_CANDIDATES,
                self::SPEC_SEATS,
                $elapsed
            )
        );

        $this->assertLessThan(
            self::SPEC_BUDGET_BYTES,
            $used,
            sprintf('PHASE_B_DESIGN §C.8 budgets 256 MB; used %.1f MB.', $used / 1048576)
        );
    }

    /**
     * THE TRIPWIRE. Cost and memory must track DISTINCT RANKINGS, not ballots.
     * Two electorates three orders of magnitude apart in size, with the same
     * ranking diversity, must cost about the same.
     */
    public function test_cost_tracks_distinct_rankings_not_ballot_count(): void
    {
        $ids = SyntheticBallotGenerator::candidateIds(12);

        // Same seed and cluster structure → comparable ranking diversity.
        // The LARGE set is built by scaling the small set's multiplicities rather
        // than generating 2M ballots one at a time: the generator is O(ballots)
        // and would dominate the measurement, which is about the ENGINE.
        $small = SyntheticBallotGenerator::grouped(seed: 99, ballots: 20_000, candidateIds: $ids);
        $large = array_map(fn (array $g) => [$g[0], $g[1] * 100], $small);

        $smallSet = BallotSet::fromGrouped($small);
        $largeSet = BallotSet::fromGrouped($large);

        $this->assertSame(20_000, $smallSet->count());
        $this->assertSame(2_000_000, $largeSet->count());

        // 100× the voters, IDENTICAL group count — that is the property.
        $this->assertSame(
            count($smallSet->groups()),
            count($largeSet->groups()),
            'group count must be driven by ranking diversity, not electorate size'
        );

        $time = function (BallotSet $set) use ($ids): float {
            $started = microtime(true);
            (new VoteCountingService)->countStv(new CountInput(
                candidacyIds: $ids,
                seats: 7,
                ballots: $set,
                excluded: [],
                tieSeedBase: 'collapse-tripwire',
            ));

            return microtime(true) - $started;
        };

        $smallTime = $time($smallSet);
        $largeTime = $time($largeSet);

        // Generous ceiling: a genuine O(ballots) regression would be ~1000×.
        $this->assertLessThan(
            max($smallTime * 10, 5.0),
            $largeTime,
            sprintf(
                'counting 2,000,000 ballots took %.3fs vs %.3fs for 20,000 — cost is tracking BALLOTS, '
                .'not distinct rankings. The simulated world depends on this property holding.',
                $largeTime,
                $smallTime
            )
        );
    }
}
