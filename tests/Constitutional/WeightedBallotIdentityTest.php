<?php

namespace Tests\Constitutional;

use App\Domain\Counting\BallotSet;
use App\Domain\Counting\CountInput;
use App\Services\Demo\CohortBallotExpander;
use App\Services\VoteCountingService;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — THE KEYSTONE of the simulated world.
 *
 * A cohort counted as WEIGHTED GROUPS produces a byte-identical result to the
 * same electorate counted as INDIVIDUAL BALLOTS. Not close. Identical —
 * including the Droop quota, every transfer round, the elected order, the
 * exhausted and truncation-residue totals, and `CountResult::recordHash()`.
 *
 * WHY EVERYTHING DEPENDS ON IT. The Attained seats ~11.6M members across ~1.7M
 * races. `legislature_members.user_id` is NOT NULL and
 * `CertificationService::certifiedTabulation()` throws unless a tabulation is
 * complete with a real `record_hash`, so a REAL count is constitutionally
 * mandatory for every seat. Any design that reaches those seats without ~8.35
 * billion ballot rows has already bet on this identity — the only question is
 * whether it bet with a proof or with a shrug. This is the proof.
 *
 * WHY IT HOLDS. `BallotSet::fromRankings()` is not an "expanded" path at all:
 * it calls `add($ranking, 1)` per ballot while `fromGrouped()` calls
 * `add($ranking, $count)` once, and BOTH land in the same private `add()` that
 * merges on the ranking key, and both finish with the same
 * `ksort(SORT_STRING)`. The two constructors therefore produce a structurally
 * identical `BallotSet` before the engine is ever entered. The equivalence is
 * structural, not statistical.
 *
 * The one thing that could still break it is arithmetic ORDER inside the
 * Gregory transfer — see GregoryTruncationOrderTest, which pins that
 * separately. These two tests together are what let the demo claim "demo math
 * == engine math" as a fact rather than a hope.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class WeightedBallotIdentityTest extends TestCase
{
    /**
     * The headline identity, over many independently-seeded cohorts and a
     * spread of race shapes. Electorates stay modest ONLY because the
     * individual-ballot side has to be physically materialized to compare.
     */
    public function test_grouped_and_expanded_counts_are_byte_identical(): void
    {
        $shapes = [
            ['candidates' => 5,  'seats' => 1, 'electorate' => 97],
            ['candidates' => 6,  'seats' => 5, 'electorate' => 743],
            ['candidates' => 8,  'seats' => 7, 'electorate' => 2_501],
            ['candidates' => 10, 'seats' => 9, 'electorate' => 12_007],
            ['candidates' => 12, 'seats' => 3, 'electorate' => 31_013],
            ['candidates' => 24, 'seats' => 9, 'electorate' => 50_021],
        ];

        foreach ($shapes as $s => $shape) {
            $ids = $this->candidacyIds($shape['candidates']);
            $seed = "identity-cohort-{$s}";

            $groups = CohortBallotExpander::expand(
                seed: $seed,
                candidacyIds: $ids,
                electorate: $shape['electorate'],
                groups: 48,
            );

            $this->assertSame(
                $shape['electorate'],
                array_sum(array_column($groups, 1)),
                'the expander must apportion the electorate exactly'
            );

            $grouped = (new VoteCountingService)->countStv(new CountInput(
                candidacyIds: $ids,
                seats: $shape['seats'],
                ballots: BallotSet::fromGrouped($groups),
                excluded: [],
                tieSeedBase: $seed,
            ));

            $expanded = (new VoteCountingService)->countStv(new CountInput(
                candidacyIds: $ids,
                seats: $shape['seats'],
                ballots: BallotSet::fromRankings($this->explode($groups)),
                excluded: [],
                tieSeedBase: $seed,
            ));

            $label = sprintf(
                'shape %d (%d candidates, %d seats, %s voters)',
                $s,
                $shape['candidates'],
                $shape['seats'],
                number_format($shape['electorate'])
            );

            $this->assertSame($grouped->recordHash(), $expanded->recordHash(), "recordHash must match — {$label}");
            $this->assertSame($grouped->quota, $expanded->quota, "quota — {$label}");
            $this->assertSame($grouped->totalValid, $expanded->totalValid, "totalValid — {$label}");
            $this->assertSame($grouped->exhaustedMicro, $expanded->exhaustedMicro, "exhausted — {$label}");
            $this->assertSame(
                $grouped->truncationResidueMicro,
                $expanded->truncationResidueMicro,
                "truncation residue — {$label}"
            );
            $this->assertSame($grouped->elected, $expanded->elected, "elected order — {$label}");
            $this->assertEquals($grouped->toArray(), $expanded->toArray(), "full result — {$label}");
        }
    }

    /**
     * Ballot ORDER cannot matter. Shuffling the individual ballots before
     * counting must not move a single micro-vote — this is what makes a
     * published count reproducible by anyone holding the seed.
     */
    public function test_ballot_order_cannot_change_the_result(): void
    {
        $ids = $this->candidacyIds(9);
        $groups = CohortBallotExpander::expand(
            seed: 'order-independence',
            candidacyIds: $ids,
            electorate: 5_003,
            groups: 40,
        );

        $flat = $this->explode($groups);
        $shuffled = $flat;
        // Deterministic reversal + rotation: no RNG, still a different order.
        $shuffled = array_reverse($shuffled);
        $shuffled = array_merge(array_slice($shuffled, 977), array_slice($shuffled, 0, 977));

        $count = fn (array $rankings) => (new VoteCountingService)->countStv(new CountInput(
            candidacyIds: $ids,
            seats: 7,
            ballots: BallotSet::fromRankings($rankings),
            excluded: [],
            tieSeedBase: 'order-independence',
        ));

        $this->assertSame(
            $count($flat)->recordHash(),
            $count($shuffled)->recordHash(),
            'ballot insertion order must never reach the count'
        );
    }

    /**
     * The regime the demo actually runs in: multiplicities in the billions,
     * which no test can expand. Here we assert the engine ACCEPTS planet-scale
     * weights and reports the true electorate — the bcmath Gregory path and the
     * raw-multiply tally headroom both get exercised.
     */
    public function test_the_engine_counts_a_planet_scale_electorate(): void
    {
        $ids = $this->candidacyIds(10);
        $electorate = 8_347_150_193; // Σ leaf population of the real planet

        $groups = CohortBallotExpander::expand(
            seed: 'earth-scale',
            candidacyIds: $ids,
            electorate: $electorate,
            groups: 64,
        );

        $this->assertSame(
            $electorate,
            array_sum(array_column($groups, 1)),
            'planet-scale apportionment must still be exact'
        );

        $result = (new VoteCountingService)->countStv(new CountInput(
            candidacyIds: $ids,
            seats: 9,
            ballots: BallotSet::fromGrouped($groups),
            excluded: [],
            tieSeedBase: 'earth-scale',
        ));

        $this->assertSame($electorate, $result->totalValid, 'total_valid is the REAL number, not a display fiction');
        $this->assertSame(intdiv($electorate, 10) + 1, $result->quota, 'Droop quota at planet scale');
        $this->assertCount(9, $result->elected, 'every seat filled');
    }

    /** Same seed → same electorate, forever. This is what makes revert cheap. */
    public function test_expansion_is_deterministic(): void
    {
        $ids = $this->candidacyIds(8);

        $a = CohortBallotExpander::expand(seed: 'determinism', candidacyIds: $ids, electorate: 10_000);
        $b = CohortBallotExpander::expand(seed: 'determinism', candidacyIds: $ids, electorate: 10_000);
        $c = CohortBallotExpander::expand(seed: 'determinism-2', candidacyIds: $ids, electorate: 10_000);

        $this->assertSame($a, $b, 'the same seed must reproduce the electorate exactly');
        $this->assertNotSame($a, $c, 'a different seed must produce a different electorate');
    }

    /**
     * The overflow guard. Round-0 tallying does a RAW multiply by Micro::SCALE,
     * so an electorate above PHP_INT_MAX/SCALE would overflow into a wrong
     * count SILENTLY. Refusing loudly is the only safe behaviour.
     */
    public function test_an_impossible_electorate_is_refused_not_silently_wrapped(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/raw-multiply ceiling/');

        CohortBallotExpander::expand(
            seed: 'overflow',
            candidacyIds: $this->candidacyIds(5),
            electorate: (int) CohortBallotExpander::MAX_ELECTORATE + 1,
        );
    }

    /** Expand grouped rankings back into one entry per ballot. */
    private function explode(array $groups): array
    {
        $out = [];
        foreach ($groups as [$ranking, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $out[] = $ranking;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function candidacyIds(int $n): array
    {
        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            $ids[] = sprintf('%08d-0000-4000-8000-000000000000', $i);
        }

        return $ids;
    }
}
