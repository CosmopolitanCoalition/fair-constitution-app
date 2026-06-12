<?php

namespace Tests\Constitutional;

use App\Domain\Counting\BallotSet;
use App\Domain\Counting\CountInput;
use App\Services\VoteCountingService;
use PHPUnit\Framework\TestCase;
use Tests\Support\SyntheticBallotGenerator;

/**
 * CONSTITUTIONAL PIN — Art. II §5: universal countback (q-ledger #q6).
 *
 * A vacancy is filled by re-running the ORIGINAL ballots at the
 * ORIGINAL seat count with the vacating candidacy struck — "as if the
 * vacating member never ran". Universality is structural: the
 * countback API cannot receive any filter input (no group, label, or
 * affiliation parameter exists on the signature), pinned here by
 * reflection AND by a source scan of the PROTECTED file. Failure
 * (no new winner derivable) routes to a special election in 90–180
 * days — the engine only reports it.
 *
 * DB-free (established posture).
 */
class CountbackUniversalTest extends TestCase
{
    private VoteCountingService $svc;

    protected function setUp(): void
    {
        $this->svc = new VoteCountingService;
    }

    /**
     * The defining identity: countback(struck: [W]) produces byte-for-byte
     * the same tabulation as re-running the count with W's candidacy
     * excluded — same ballots, same seats, same (re-derived) quota.
     */
    public function test_countback_is_exactly_a_rerun_with_the_candidacy_excluded(): void
    {
        $ids = SyntheticBallotGenerator::candidateIds(8);
        $ballots = BallotSet::fromGrouped(SyntheticBallotGenerator::grouped(424_242, 400, $ids));

        $in = new CountInput($ids, 3, $ballots, [], 'countback-seed');

        $base = $this->svc->countStv($in);
        $this->assertCount(3, $base->elected);

        $vacating = $base->elected[0]['candidacy_id'];
        $sitting = array_values(array_diff(array_column($base->elected, 'candidacy_id'), [$vacating]));

        $manualRerun = $this->svc->countStv(new CountInput($ids, 3, $ballots, [$vacating], 'countback-seed'));
        $cb = $this->svc->countback($in, [$vacating], $sitting);

        $this->assertSame($manualRerun->recordHash(), $cb->tabulation->recordHash());

        // Replacements = re-run winners minus sitting members, in re-run
        // election order; sitting members' seats are never disturbed.
        $expected = array_values(array_diff(array_column($manualRerun->elected, 'candidacy_id'), $sitting));
        $this->assertSame($expected, $cb->replacements);
        $this->assertNotContains($vacating, $cb->replacements);
        foreach ($sitting as $s) {
            $this->assertNotContains($s, $cb->replacements);
        }

        $this->assertFalse($cb->failed);
        $this->assertGreaterThanOrEqual(1, count($cb->replacements));

        // Deterministic: running the countback twice is byte-identical.
        $this->assertSame(
            $cb->tabulation->recordHash(),
            $this->svc->countback($in, [$vacating], $sitting)->tabulation->recordHash(),
        );
    }

    /**
     * Failure branch: when the re-run cannot produce a winner who is not
     * already sitting, the countback fails (→ vacancies.status=
     * 'countback_failed', CLK-04 special-election window — handled by the
     * caller; the engine only reports).
     */
    public function test_countback_failure_when_no_new_winner_derivable(): void
    {
        // 3 candidates, 3 seats: everyone is elected in the base count.
        $in = new CountInput(
            candidacyIds: ['A', 'B', 'C'],
            seats: 3,
            ballots: BallotSet::fromGrouped([
                [['A'], 4],
                [['B'], 3],
                [['C'], 2],
            ]),
            tieSeedBase: 'fail-seed',
        );

        $base = $this->svc->countStv($in);
        $this->assertSame(['A', 'B', 'C'], array_column($base->elected, 'candidacy_id'));

        // A vacates: the re-run (A struck) can only elect B and C — both
        // already sitting. No replacement exists.
        $cb = $this->svc->countback($in, ['A'], ['B', 'C']);

        $this->assertSame([], $cb->replacements);
        $this->assertTrue($cb->failed);
    }

    public function test_multi_vacancy_strike_fills_in_rerun_election_order(): void
    {
        $ids = SyntheticBallotGenerator::candidateIds(6);
        $ballots = BallotSet::fromGrouped(SyntheticBallotGenerator::grouped(777_777, 300, $ids));

        $in = new CountInput($ids, 3, $ballots, [], 'multi-seed');

        $base = $this->svc->countStv($in);
        $winners = array_column($base->elected, 'candidacy_id');

        // Two simultaneous vacancies in one race.
        $struck = [$winners[0], $winners[2]];
        $sitting = [$winners[1]];

        $cb = $this->svc->countback($in, $struck, $sitting);

        $this->assertFalse($cb->failed);
        $this->assertGreaterThanOrEqual(2, count($cb->replacements));

        // Replacements arrive in the re-run's election order.
        $rerunOrder = array_column($cb->tabulation->elected, 'candidacy_id');
        $this->assertSame(
            array_values(array_diff($rerunOrder, $sitting)),
            $cb->replacements,
        );

        // Neither struck candidacy can win its own countback.
        foreach ($struck as $s) {
            $this->assertNotContains($s, $rerunOrder);
        }
    }

    /**
     * UNIVERSALITY IS STRUCTURAL (q-ledger #q6): the countback signature
     * admits exactly (CountInput, struck, sitting) — no filter of any
     * kind can even be passed. Pinned by reflection so adding such a
     * parameter is a test failure, not a review comment.
     */
    public function test_signature_cannot_receive_filter_shaped_input(): void
    {
        $method = new \ReflectionMethod(VoteCountingService::class, 'countback');

        $params = array_map(fn (\ReflectionParameter $p) => $p->getName(), $method->getParameters());
        $this->assertSame(['in', 'struck', 'sitting'], $params);

        $types = array_map(fn (\ReflectionParameter $p) => (string) $p->getType(), $method->getParameters());
        $this->assertSame([CountInput::class, 'array', 'array'], $types);

        // And CountInput itself carries no affiliation/grouping field.
        $props = array_map(
            fn (\ReflectionProperty $p) => $p->getName(),
            (new \ReflectionClass(CountInput::class))->getProperties(),
        );
        $this->assertSame(['candidacyIds', 'seats', 'ballots', 'excluded', 'tieSeedBase'], $props);
    }

    /**
     * Grep-style source assertion (design §C.5 — "cheap, brutal,
     * effective"): the PROTECTED counting engine never mentions group
     * affiliation concepts at all.
     */
    public function test_source_contains_no_affiliation_concepts(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../app/Services/VoteCountingService.php',
        );

        $this->assertDoesNotMatchRegularExpression('/faction/i', $source);
        $this->assertDoesNotMatchRegularExpression('/endors/i', $source);
        $this->assertDoesNotMatchRegularExpression('/\bparty\b/i', $source);
        $this->assertDoesNotMatchRegularExpression('/electorate/i', $source);
    }
}
