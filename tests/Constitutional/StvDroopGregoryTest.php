<?php

namespace Tests\Constitutional;

use App\Domain\Counting\BallotSet;
use App\Domain\Counting\CountInput;
use App\Domain\Counting\CountResult;
use App\Domain\Counting\Micro;
use App\Domain\Engine\ConstitutionalViolation;
use App\Services\VoteCountingService;
use PHPUnit\Framework\TestCase;
use Tests\Support\SyntheticBallotGenerator;

/**
 * CONSTITUTIONAL PIN — Art. II §2: PR-STV, Droop quota, Weighted
 * Inclusive Gregory fractional surplus transfers.
 *
 * DB-free (established posture). Pins VoteCountingService (PROTECTED)
 * three ways:
 *
 *  1. A fully hand-computed 3-seat worked example — every round's
 *     arithmetic derived by hand under WIGM + 6-dp truncation and
 *     asserted to the microvote.
 *  2. The Queens fixture (tests/fixtures/queens_stv.json — verbatim
 *     window.STV_DATA from mockups/electoral/results.html, 412,383
 *     ballots, quota 41,239, 27 rounds): the raw ballots were never
 *     committed, so the demonstrated SEMANTICS are pinned instead —
 *     quota formula, WIGM transfer values (surplus ÷ FULL total, which
 *     rules out last-parcel Gregory), surplus conservation, monotone
 *     eliminations, surplus-round-elects convention, final-surplus
 *     distribution, write-in indistinguishability — plus a full
 *     running-tally reconstruction from the transfer ledger.
 *  3. Seeded property tests over ~200 random elections: exact
 *     conservation, quota-rest, monotone elimination, determinism
 *     under ballot shuffling.
 *
 * If an edit to the engine breaks these tests, that edit is a
 * constitutional violation — fix the edit, never the test.
 */
class StvDroopGregoryTest extends TestCase
{
    private VoteCountingService $svc;

    protected function setUp(): void
    {
        $this->svc = new VoteCountingService;
    }

    // ==================================================================
    // 1. Hand-computed worked example (3 seats, 5 candidates, 20 ballots)
    // ==================================================================

    /**
     * Ballots (20 valid):
     *   8 × [A,B,C]   1 × [A,C,D]   4 × [B]   3 × [C,D]   3 × [D,E]   1 × [E,D]
     *
     * Droop quota = floor(20 / (3+1)) + 1 = 6 (6,000,000 µv).
     *
     * R1 start: A 9.0  B 4.0  C 3.0  D 3.0  E 1.0
     *   A ≥ quota → surplus S = 3,000,000 µv on total T = 9,000,000 µv.
     *   value_micro = floor(10⁶·S/T) = floor(10⁶/3) = 333,333.
     *   Each of A's 9 full-weight ballots re-weights to
     *   floor(1,000,000 × 3,000,000 / 9,000,000) = 333,333 µv:
     *     8×[A,B,C] → B: 8 × 333,333 = 2,666,664
     *     1×[A,C,D] → C:               333,333
     *   moved = 2,999,997 → truncation residue = 3,000,000 − 2,999,997 = 3.
     *
     * R2 start: A 6.0(rest)  B 6,666,664  C 3,333,333  D 3.0  E 1.0
     *   B ≥ quota → S = 666,664, T = 6,666,664.
     *   value_micro = floor(666,664·10⁶ / 6,666,664) = 99,999.
     *     4×[B]      @1,000,000 → no next pref → exhausted 4 × 99,999 = 399,996
     *     8×[A,B,C]  @333,333  → floor(333,333·666,664/6,666,664) = 33,333
     *                          → C: 8 × 33,333 = 266,664
     *   residue = 666,664 − 666,660 = 4.
     *
     * R3 start: A 6.0  B 6.0  C 3,599,997  D 3.0  E 1.0  (exhausted 399,996)
     *   No one at quota → eliminate E (lowest): 1×[E,D] @1,000,000 → D +1,000,000.
     *
     * R4 start: …  C 3,599,997  D 4.0
     *   Eliminate C: 3×[C,D] @1,000,000 → D +3,000,000;
     *   1×[A,C,D] @333,333 → D +333,333 (elimination moves at CURRENT weight);
     *   8×[A,B,C] @33,333 → no next pref → exhausted 266,664.
     *
     * R5 start: A 6.0  B 6.0  D 7,333,333  (exhausted 666,660)
     *   D ≥ quota → S = 1,333,333, T = 7,333,333.
     *   value_micro = floor(1,333,333·10⁶ / 7,333,333) = 181,818.
     *   Every ballot's next preference is gone (E and C eliminated):
     *     7 ballots @1,000,000 → 7 × 181,818 = 1,272,726 exhausted
     *     1 ballot  @333,333  → floor(333,333·1,333,333/7,333,333) = 60,605 exhausted
     *   exhausted = 1,333,331, residue = 2.
     *
     * Final: elected A(r1,s1) B(r2,s2) D(r5,s3); cumulative exhausted
     * 1,999,991; cumulative residue 3+4+2 = 9. Conservation:
     * 18,000,000 + 1,999,991 + 9 = 20,000,000 = 20 × 10⁶ exactly.
     */
    public function test_hand_worked_three_seat_count(): void
    {
        $in = new CountInput(
            candidacyIds: ['A', 'B', 'C', 'D', 'E'],
            seats: 3,
            ballots: BallotSet::fromGrouped([
                [['A', 'B', 'C'], 8],
                [['A', 'C', 'D'], 1],
                [['B'], 4],
                [['C', 'D'], 3],
                [['D', 'E'], 3],
                [['E', 'D'], 1],
            ]),
            tieSeedBase: 'worked-example',
        );

        $r = $this->svc->countStv($in);

        $this->assertSame(20, $r->totalValid);
        $this->assertSame(6, $r->quota);
        $this->assertCount(5, $r->rounds);
        $this->assertSame(0, $r->seatsUnfilled);

        $this->assertSame(
            [['A', 1, 1], ['B', 2, 2], ['D', 5, 3]],
            array_map(fn ($e) => [$e['candidacy_id'], $e['round'], $e['seat_no']], $r->elected),
        );

        // -- R1: A's surplus
        [$r1, $r2, $r3, $r4, $r5] = $r->rounds;

        $this->assertSame('elect', $r1->action);
        $this->assertSame('A', $r1->candidacyId);
        $this->assertSame('surplus', $r1->transfer['kind']);
        $this->assertSame(333_333, $r1->transfer['value_micro']);
        $this->assertSame([['B', 2_666_664], ['C', 333_333]], $r1->transfer['to']);
        $this->assertSame(0, $r1->transfer['exhausted_micro']);
        $this->assertSame(3, $r1->transfer['truncation_residue_micro']);
        $this->assertSame(
            ['A' => 9_000_000, 'B' => 4_000_000, 'C' => 3_000_000, 'D' => 3_000_000, 'E' => 1_000_000],
            $r1->tallies['candidates'],
        );
        $this->assertSame(['A'], $r1->tallies['elected_so_far']);

        // -- R2: B's surplus (per-ballot truncation on two distinct weights)
        $this->assertSame('elect', $r2->action);
        $this->assertSame('B', $r2->candidacyId);
        $this->assertSame(99_999, $r2->transfer['value_micro']);
        $this->assertSame([['C', 266_664]], $r2->transfer['to']);
        $this->assertSame(399_996, $r2->transfer['exhausted_micro']);
        $this->assertSame(4, $r2->transfer['truncation_residue_micro']);
        $this->assertSame(6_666_664, $r2->tallies['candidates']['B']);
        $this->assertSame(6_000_000, $r2->tallies['candidates']['A']); // A rests at exactly quota

        // -- R3: eliminate E at full weight
        $this->assertSame('eliminate', $r3->action);
        $this->assertSame('E', $r3->candidacyId);
        $this->assertSame('elimination', $r3->transfer['kind']);
        $this->assertNull($r3->transfer['value_micro']);
        $this->assertSame([['D', 1_000_000]], $r3->transfer['to']);
        $this->assertSame(0, $r3->transfer['exhausted_micro']);
        $this->assertSame(399_996, $r3->tallies['exhausted_micro']);

        // -- R4: eliminate C — fractional-weight ballots move at current value
        $this->assertSame('eliminate', $r4->action);
        $this->assertSame('C', $r4->candidacyId);
        $this->assertSame([['D', 3_333_333]], $r4->transfer['to']);
        $this->assertSame(266_664, $r4->transfer['exhausted_micro']);
        $this->assertSame(3_599_997, $r4->tallies['candidates']['C']);

        // -- R5: D's surplus, fully exhausted
        $this->assertSame('elect', $r5->action);
        $this->assertSame('D', $r5->candidacyId);
        $this->assertSame(181_818, $r5->transfer['value_micro']);
        $this->assertSame([], $r5->transfer['to']);
        $this->assertSame(1_333_331, $r5->transfer['exhausted_micro']);
        $this->assertSame(2, $r5->transfer['truncation_residue_micro']);
        $this->assertSame(7_333_333, $r5->tallies['candidates']['D']);
        $this->assertSame(666_660, $r5->tallies['exhausted_micro']);
        $this->assertSame(['A', 'B', 'D'], $r5->tallies['elected_so_far']);

        // -- Exact conservation, to the microvote
        $this->assertSame(1_999_991, $r->exhaustedMicro);
        $this->assertSame(9, $r->truncationResidueMicro);
        $this->assertSame(
            20 * Micro::SCALE,
            array_sum($r->finalTallies) + $r->exhaustedMicro + $r->truncationResidueMicro,
        );

        $this->assertCountInvariants($r, 5);

        // Determinism: identical input twice → identical record_hash.
        $this->assertSame($r->recordHash(), $this->svc->countStv($in)->recordHash());
    }

    // ==================================================================
    // 2. Queens fixture pins (round summaries — ballots not derivable)
    // ==================================================================

    private static function queens(): array
    {
        static $data = null;

        return $data ??= json_decode(
            file_get_contents(__DIR__ . '/../fixtures/queens_stv.json'),
            true,
        );
    }

    public function test_queens_fixture_semantics_pin(): void
    {
        $d = self::queens();

        // Droop quota formula, stated verbatim in the mockup.
        $this->assertSame(412_383, $d['total']);
        $this->assertSame(9, $d['seats']);
        $this->assertSame(intdiv($d['total'], $d['seats'] + 1) + 1, $d['quota']);
        $this->assertSame(41_239, $d['quota']);

        $this->assertSame(27, $d['rounds']);
        $this->assertCount(27, $d['display']);
        $this->assertCount(9, $d['elected']);

        $surplusRounds = [];
        $eliminations = [];

        foreach ($d['display'] as $i => $round) {
            $this->assertSame($i + 1, $round['n']);
            // One transfer event per round.
            $this->assertArrayHasKey('transfer', $round);
            $t = $round['transfer'];

            if ($t['kind'] === 'surplus') {
                preg_match('/surplus ([\d,]+) transfers at value ([\d.]+)/', $round['action'], $m);
                $surplus = (int) str_replace(',', '', $m[1]);
                $value = (float) $m[2];
                $total = $d['quota'] + $surplus;

                // WIGM PROOF: transfer value = surplus ÷ candidate's FULL
                // total — to within 3-dp display rounding, every round.
                $this->assertEqualsWithDelta($surplus / $total, $value, 0.0005 + 1e-9, "WIGM value, round {$round['n']}");

                // Surplus conservation: to-sum + exhausted == surplus
                // (±1 display unit per recipient).
                $toSum = array_sum(array_map(fn ($p) => $p[1], $t['to']));
                $this->assertEqualsWithDelta($surplus, $toSum + $t['exhausted'], count($t['to']) + 1, "surplus conservation, round {$round['n']}");

                $surplusRounds[$round['n']] = $t['from'];
            } else {
                $this->assertSame('elimination', $t['kind']);
                preg_match('/eliminated — ([\d,]+) votes/', $round['action'], $m);
                $moved = (int) str_replace(',', '', $m[1]);

                $toSum = array_sum(array_map(fn ($p) => $p[1], $t['to']));
                $this->assertEqualsWithDelta($moved, $toSum + $t['exhausted'], count($t['to']) + 1, "elimination conservation, round {$round['n']}");

                $eliminations[$round['n']] = $moved;
            }
        }

        // 18 eliminations + 9 surplus distributions.
        $this->assertCount(18, $eliminations);
        $this->assertCount(9, $surplusRounds);

        // Elimination totals strictly increase — lowest-continuing rule.
        $this->assertSame(array_keys($eliminations), array_keys($eliminations)); // round order
        $prev = 0;
        foreach ($eliminations as $n => $total) {
            $this->assertGreaterThan($prev, $total, "elimination total not increasing at round {$n}");
            $prev = $total;
        }

        // round_elected == the round the surplus distributes, in order;
        // the elected name is the surplus round's mover.
        $this->assertSame([16, 19, 20, 21, 22, 24, 25, 26, 27], array_keys($surplusRounds));
        foreach ($d['elected'] as $e) {
            $this->assertSame($e['name'], $surplusRounds[$e['round']], "elected round {$e['round']}");
        }

        // The final (9th) winner's surplus is still distributed — record
        // completeness: round 27 is Aisha's own surplus.
        $this->assertSame('Aisha Diop', $surplusRounds[27]);

        // Last-parcel Gregory is RULED OUT by round 16: Rita's value is
        // 458/41,697 (full total), not 458/2,749 (last parcel).
        $this->assertEqualsWithDelta(458 / 41_697, 0.011, 0.0005);
        $this->assertGreaterThan(0.1, abs(458 / 2_749 - 0.011));

        // Write-in tabulated identically: full participant — receives
        // transfers (r1, r6), then eliminated r13 like anyone else.
        $this->assertStringContainsString('Quinn Avery (write-in) eliminated', $d['display'][12]['action']);
        $r1to = array_column($d['display'][0]['transfer']['to'], 1, 0);
        $this->assertSame(275, $r1to['Quinn Avery (write-in)']);

        // Final round: Aisha rests at exactly quota + her 704 surplus;
        // electedSoFar lists all nine in election order (incl. round 27's
        // own winner — end-of-round convention).
        $final = $d['display'][26];
        $tallies = array_column($final['tallies'], 1, 0);
        $this->assertSame($d['quota'] + 704, $tallies['Aisha Diop']);
        $this->assertSame(36_812, $tallies['Sade Williams']);
        $this->assertSame(array_column($d['elected'], 'name'), $final['electedSoFar']);
    }

    /**
     * Full running-tally reconstruction: with round summaries only, every
     * candidate's standing at every round is still derivable from the
     * transfer ledger — first preferences are read from round 1 where
     * displayed and solved from removal totals where not. Pins that the
     * fixture is one internally consistent WIGM count.
     */
    public function test_queens_fixture_reconstruction_pin(): void
    {
        $d = self::queens();
        $quota = $d['quota'];

        // Receipts ledger + removal events.
        $receipts = [];   // name => list of [round, amount]
        $removals = [];   // name => [round, statedTotalAtRemoval]

        foreach ($d['display'] as $round) {
            $n = $round['n'];
            $t = $round['transfer'];

            foreach ($t['to'] as [$name, $amount]) {
                $receipts[$name][] = [$n, $amount];
            }

            if ($t['kind'] === 'surplus') {
                preg_match('/surplus ([\d,]+) transfers/', $round['action'], $m);
                $removals[$t['from']] = [$n, $quota + (int) str_replace(',', '', $m[1])];
            } else {
                preg_match('/eliminated — ([\d,]+) votes/', $round['action'], $m);
                $removals[$t['from']] = [$n, (int) str_replace(',', '', $m[1])];
            }
        }

        // First preferences: displayed round-1 tallies, else solved from
        // the removal total minus receipts before removal.
        $first = array_column($d['display'][0]['tallies'], 1, 0);

        foreach ($removals as $name => [$rn, $stated]) {
            if (isset($first[$name])) {
                continue;
            }
            $before = 0;
            foreach ($receipts[$name] ?? [] as [$rr, $amt]) {
                if ($rr < $rn) {
                    $before += $amt;
                }
            }
            $first[$name] = $stated - $before;
        }

        // 28 candidates total; every first preference positive.
        $this->assertCount(28, $first);
        foreach ($first as $name => $v) {
            $this->assertGreaterThan(0, $v, "non-positive first preference for {$name}");
        }

        // Global conservation: Σ first preferences == total ballots
        // (within accumulated ±0.5 display rounding per ledger entry).
        $this->assertEqualsWithDelta($d['total'], array_sum($first), 150);

        // Every removal total reconciles: first + receipts-before-removal
        // == stated total (± one display unit per receipt event).
        foreach ($removals as $name => [$rn, $stated]) {
            $events = 0;
            $sum = $first[$name];
            foreach ($receipts[$name] ?? [] as [$rr, $amt]) {
                if ($rr < $rn) {
                    $sum += $amt;
                    $events++;
                }
            }
            $this->assertEqualsWithDelta($stated, $sum, $events + 2, "removal reconciliation for {$name}");
        }

        // Explicit round 2 / round 3 tallies match the running ledger.
        foreach ([1, 2] as $i) {
            $expect = array_column($d['display'][$i]['tallies'], 1, 0);
            foreach ($expect as $name => $v) {
                $sum = $first[$name];
                foreach ($receipts[$name] ?? [] as [$rr, $amt]) {
                    if ($rr <= $i) {
                        $sum += $amt;
                    }
                }
                $this->assertEqualsWithDelta($v, $sum, 2, "round " . ($i + 1) . " tally for {$name}");
            }
        }

        // Sade (the runner-up, never removed): ledger reaches her final
        // displayed standing.
        $sade = $first['Sade Williams'];
        foreach ($receipts['Sade Williams'] as [$rr, $amt]) {
            if ($rr < 27) {
                $sade += $amt;
            }
        }
        $this->assertEqualsWithDelta(36_812, $sade, 14);

        // The pending-surplus forensics this engine's queue semantics rest
        // on (see the service docblock): Nora, Carl and Leo were already
        // over quota after round 18 yet still RECEIVED surplus transfers in
        // rounds 19–21, and the queue order 19 Sam → 20 Nora → 21 Carl →
        // 22 Leo equals descending CURRENT surplus recomputed each round.
        $standingAfter = function (string $name, int $round) use ($first, $receipts): int {
            $sum = $first[$name];
            foreach ($receipts[$name] ?? [] as [$rr, $amt]) {
                if ($rr <= $round) {
                    $sum += $amt;
                }
            }

            return $sum;
        };

        foreach (['Sam Porter', 'Nora Whitfield', 'Carl Jensen', 'Leo Tanaka'] as $name) {
            $this->assertGreaterThan($quota, $standingAfter($name, 18), "{$name} over quota after round 18");
        }
        $r19to = array_column($d['display'][18]['transfer']['to'], 1, 0);
        $this->assertSame(1_854, $r19to['Nora Whitfield']); // pending candidate receives
        $this->assertSame(1_646, $r19to['Carl Jensen']);

        // Largest CURRENT surplus each round:
        $this->assertGreaterThan($standingAfter('Nora Whitfield', 18), $standingAfter('Sam Porter', 18));
        $this->assertGreaterThan($standingAfter('Carl Jensen', 19), $standingAfter('Nora Whitfield', 19));
        $this->assertGreaterThan($standingAfter('Leo Tanaka', 20) - $quota, $standingAfter('Carl Jensen', 20) - $quota);
    }

    // ==================================================================
    // 3. Targeted mechanics
    // ==================================================================

    /**
     * Simultaneous quota attainment: surpluses distribute largest-first,
     * each as its own round, and a pending (over-quota, undistributed)
     * candidate still receives transfers — the fixture-derived semantics.
     */
    public function test_simultaneous_quota_largest_surplus_first_and_pending_receive(): void
    {
        // First prefs: A 12, B 10, C 4, D 3, E 1 (total 30, quota 8).
        $in = new CountInput(
            candidacyIds: ['A', 'B', 'C', 'D', 'E'],
            seats: 3,
            ballots: BallotSet::fromGrouped([
                [['A', 'B', 'C'], 12],
                [['B', 'C'], 10],
                [['C'], 4],
                [['D', 'C'], 3],
                [['E', 'C'], 1],
            ]),
            tieSeedBase: 'double-quota',
        );

        $r = $this->svc->countStv($in);

        $this->assertSame(8, $r->quota);

        // Round 1 elects A (surplus 4 > B's 2); A's surplus flows to B
        // even though B is already over quota (pending receives).
        $this->assertSame('elect', $r->rounds[0]->action);
        $this->assertSame('A', $r->rounds[0]->candidacyId);
        $this->assertSame('B', $r->rounds[0]->transfer['to'][0][0]);

        // Round 2: B distributes a surplus GROWN by A's transfer:
        // 10,000,000 + 12×333,333 = 13,999,996 µv at round start.
        $this->assertSame('elect', $r->rounds[1]->action);
        $this->assertSame('B', $r->rounds[1]->candidacyId);
        $this->assertSame(13_999_996, $r->rounds[1]->tallies['candidates']['B']);

        // Round 3: C crosses on B's surplus and takes the third seat.
        $this->assertSame('elect', $r->rounds[2]->action);
        $this->assertSame('C', $r->rounds[2]->candidacyId);
        $this->assertCount(3, $r->rounds);

        $this->assertCountInvariants($r, 5);
    }

    /**
     * Zero-surplus exact-quota election (still a round: value 0, empty
     * to, no movement) and the backwards (prior-rounds) tie-break.
     */
    public function test_zero_surplus_election_and_prior_round_tie_break(): void
    {
        // First prefs: A 10, B 8, C 4, D 3 (total 25, quota 9).
        // R1: A elected, surplus 1.0 → all 10 ballots → D at 100,000 µv
        //     each → D reaches 4,000,000 µv, TYING C.
        // R2: eliminate lowest — C and D tied NOW; round 1 standings
        //     differed (C 4.0 > D 3.0) → D had the lower prior tally →
        //     D eliminated, decided_at_round 1.
        // R3: D's pile → B +1.0 → B at exactly 9.0 = quota → elected
        //     with surplus 0.
        $in = new CountInput(
            candidacyIds: ['A', 'B', 'C', 'D'],
            seats: 2,
            ballots: BallotSet::fromGrouped([
                [['A', 'D', 'B'], 10],
                [['B'], 8],
                [['C', 'B'], 4],
                [['D', 'C'], 3],
            ]),
            tieSeedBase: 'tie-prior-rounds',
        );

        $r = $this->svc->countStv($in);

        $this->assertSame(9, $r->quota);

        [$r1, $r2, $r3] = $r->rounds;

        $this->assertSame('A', $r1->candidacyId);
        $this->assertSame(100_000, $r1->transfer['value_micro']);
        $this->assertSame([['D', 1_000_000]], $r1->transfer['to']);

        $this->assertSame('eliminate', $r2->action);
        $this->assertSame('D', $r2->candidacyId);
        $this->assertSame(4_000_000, $r2->tallies['candidates']['C']);
        $this->assertSame(4_000_000, $r2->tallies['candidates']['D']);
        $this->assertSame(
            ['stage' => 'prior_rounds', 'decided_at_round' => 1],
            $r2->transfer['tie_break'],
        );

        // D's elimination moves 3 full-weight ballots ([D,C] → C) and 10
        // ballots at current weight 100,000 ([A,D,B] → B).
        $to = array_column($r2->transfer['to'], 1, 0);
        $this->assertSame(3_000_000, $to['C']);
        $this->assertSame(1_000_000, $to['B']);

        $this->assertSame('elect', $r3->action);
        $this->assertSame('B', $r3->candidacyId);
        $this->assertSame(9_000_000, $r3->tallies['candidates']['B']);
        $this->assertSame('surplus', $r3->transfer['kind']);
        $this->assertSame(0, $r3->transfer['value_micro']);
        $this->assertSame([], $r3->transfer['to']);
        $this->assertSame(0, $r3->transfer['truncation_residue_micro']);

        $this->assertSame(['A', 'B'], array_column($r->elected, 'candidacy_id'));
        $this->assertCountInvariants($r, 4);
    }

    /**
     * All-rounds-tied → audit-chained seeded lot: deterministic order by
     * sha256(tie_seed ∥ candidacy_id), seed published in the round record.
     */
    public function test_all_rounds_tied_seeded_lot(): void
    {
        $in = new CountInput(
            candidacyIds: ['cand-x', 'cand-y'],
            seats: 1,
            ballots: BallotSet::fromGrouped([
                [['cand-x'], 1],
                [['cand-y'], 1],
            ]),
            tieSeedBase: 'lot-seed-base',
        );

        $r = $this->svc->countStv($in);

        // Quota floor(2/2)+1 = 2: nobody reaches it; round 1 eliminates
        // one of the pair by lot; round 2 shortcut-fills the survivor.
        $expectedSeed = hash('sha256', 'lot-seed-base:1');
        $order = ['cand-x', 'cand-y'];
        usort($order, fn ($a, $b) => strcmp(
            hash('sha256', $expectedSeed . $a),
            hash('sha256', $expectedSeed . $b),
        ));

        [$r1, $r2] = $r->rounds;

        $this->assertSame('eliminate', $r1->action);
        $this->assertSame($order[0], $r1->candidacyId); // first in lot order acts
        $this->assertSame(
            ['stage' => 'lot', 'seed' => $expectedSeed, 'order' => $order],
            $r1->transfer['tie_break'],
        );

        $this->assertSame('elect', $r2->action);
        $this->assertSame($order[1], $r2->candidacyId);
        $this->assertTrue($r2->tallies['elected_without_quota']);
        $this->assertNull($r2->transfer);

        // Deterministic: same input twice → identical outcome and hash.
        $this->assertSame($r->recordHash(), $this->svc->countStv($in)->recordHash());

        // Different seed base → (possibly) different lot — but always
        // deterministic per seed; assert the seed propagates.
        $in2 = new CountInput($in->candidacyIds, 1, $in->ballots, [], 'other-seed');
        $r2b = $this->svc->countStv($in2);
        $this->assertSame(hash('sha256', 'other-seed:1'), $r2b->rounds[0]->transfer['tie_break']['seed']);
    }

    /**
     * Excluded candidacies are passed over (never exhaust a ballot),
     * duplicate preferences collapse to the first, and ballots with no
     * remaining rankings are invalid (excluded from total_valid). A
     * count with X struck is byte-identical to a count where X never ran.
     */
    public function test_excluded_pass_over_dedupe_and_invalid_ballots(): void
    {
        $withX = new CountInput(
            candidacyIds: ['A', 'B', 'X'],
            seats: 1,
            ballots: BallotSet::fromGrouped([
                [['X', 'A'], 3],          // X passed over → counts as [A]
                [['A', 'A', 'B'], 2],     // duplicate collapses → [A,B]
                [['X'], 1],               // nothing left → invalid
            ]),
            excluded: ['X'],
            tieSeedBase: 'seed',
        );

        $without = new CountInput(
            candidacyIds: ['A', 'B'],
            seats: 1,
            ballots: BallotSet::fromGrouped([
                [['A'], 3],
                [['A', 'B'], 2],
            ]),
            tieSeedBase: 'seed',
        );

        $a = $this->svc->countStv($withX);
        $b = $this->svc->countStv($without);

        $this->assertSame(5, $a->totalValid); // the [X] ballot is invalid
        $this->assertSame($b->recordHash(), $a->recordHash());
        $this->assertSame(['A'], array_column($a->elected, 'candidacy_id'));
    }

    public function test_fewer_candidates_than_seats_reports_unfilled_seats(): void
    {
        $in = new CountInput(
            candidacyIds: ['A', 'B'],
            seats: 3,
            ballots: BallotSet::fromGrouped([
                [['A'], 3],
                [['B'], 2],
            ]),
            tieSeedBase: 'seed',
        );

        $r = $this->svc->countStv($in);

        // Quota floor(5/4)+1 = 2: both elected by quota; the engine only
        // REPORTS the unfilled seat — vacancies routing is the
        // certification handler's job.
        $this->assertSame(['A', 'B'], array_column($r->elected, 'candidacy_id'));
        $this->assertSame(1, $r->seatsUnfilled);
        $this->assertCountInvariants($r, 2);
    }

    public function test_seats_bounds_are_hardened(): void
    {
        $ballots = BallotSet::fromGrouped([[['A'], 1]]);

        foreach ([0, 10, -1] as $seats) {
            try {
                $this->svc->countStv(new CountInput(['A'], $seats, $ballots));
                $this->fail("seats={$seats} accepted");
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §2/§8', $e->citation);
            }
        }
    }

    /**
     * Write-in indistinguishability is STRUCTURAL: CountInput carries
     * exactly five fields and no candidacy metadata of any kind, so the
     * counting core cannot know finalist from write-in. (Behavioral
     * equivalence is then the trivial determinism property — identical
     * inputs are identical.)
     */
    public function test_count_input_admits_no_candidacy_metadata(): void
    {
        $props = array_map(
            fn (\ReflectionProperty $p) => $p->getName(),
            (new \ReflectionClass(CountInput::class))->getProperties(),
        );

        $this->assertSame(
            ['candidacyIds', 'seats', 'ballots', 'excluded', 'tieSeedBase'],
            $props,
        );
    }

    // ==================================================================
    // 4. Property tests — seeded random elections
    // ==================================================================

    public function test_properties_over_random_elections(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $seed = 9_000 + $i;
            mt_srand($seed * 31);
            $nCands = mt_rand(3, 26);
            $seats = mt_rand(1, 9);
            $nBallots = mt_rand(50, 1_200);

            $ids = SyntheticBallotGenerator::candidateIds($nCands);
            $grouped = SyntheticBallotGenerator::grouped($seed, $nBallots, $ids);

            $in = new CountInput(
                candidacyIds: $ids,
                seats: $seats,
                ballots: BallotSet::fromGrouped($grouped),
                tieSeedBase: "prop-{$seed}",
            );

            $r = $this->svc->countStv($in);

            $this->assertSame($nBallots, $r->totalValid, "election {$i}: valid total");
            $this->assertCountInvariants($r, $nCands, "election {$i}");

            // Determinism under ballot-order shuffling (every 10th, for
            // runtime): expand groups to individual ballots, permute,
            // regroup — identical record_hash.
            if ($i % 10 === 0) {
                $flat = [];
                foreach ($grouped as [$ranking, $count]) {
                    for ($b = 0; $b < $count; $b++) {
                        $flat[] = $ranking;
                    }
                }
                mt_srand($seed + 1);
                shuffle($flat);

                $shuffled = new CountInput($ids, $seats, BallotSet::fromRankings($flat), [], "prop-{$seed}");
                $this->assertSame($r->recordHash(), $this->svc->countStv($shuffled)->recordHash(), "election {$i}: shuffle determinism");
            }
        }
    }

    // ==================================================================
    // Shared invariant checker
    // ==================================================================

    /**
     * The hardened invariants every STV count must satisfy, checked per
     * round (design §C.4):
     *  - exact conservation: Σ tallies + exhausted + Σ residues
     *    == total_valid × SCALE, with ==;
     *  - seats filled at most once, never more than `seats`, exactly
     *    min(seats, candidates) elected;
     *  - quota never overfilled twice: after a surplus distributes the
     *    winner rests at exactly quota and never receives again;
     *  - monotone elimination: the eliminated candidate held the minimum
     *    tally among below-quota continuing candidates at round start;
     *  - surplus transfer value ≤ 1.0 (SCALE).
     */
    private function assertCountInvariants(CountResult $r, int $candidateCount, string $ctx = ''): void
    {
        $totalMicro = $r->totalValid * Micro::SCALE;
        $quotaMicro = $r->quota * Micro::SCALE;

        $this->assertSame(min($r->seats, $candidateCount), count($r->elected), "{$ctx}: elected count");
        $electedIds = array_column($r->elected, 'candidacy_id');
        $this->assertSame($electedIds, array_unique($electedIds), "{$ctx}: duplicate seat");
        $this->assertSame($r->seats - count($r->elected), $r->seatsUnfilled, "{$ctx}: seats_unfilled");

        $residueCum = 0;
        $restedAtQuota = [];

        foreach ($r->rounds as $round) {
            $n = $round->roundNo;

            $this->assertSame(
                $totalMicro,
                array_sum($round->tallies['candidates']) + $round->tallies['exhausted_micro'] + $residueCum,
                "{$ctx}: conservation broken at round {$n}",
            );

            foreach ($restedAtQuota as $id => $_) {
                $this->assertSame($quotaMicro, $round->tallies['candidates'][$id], "{$ctx}: r{$n}: {$id} not resting at quota");
                if ($round->transfer !== null) {
                    foreach ($round->transfer['to'] as [$tid, $amt]) {
                        $this->assertNotSame($id, $tid, "{$ctx}: r{$n}: transfer to rested winner {$id}");
                    }
                }
            }

            if ($round->transfer !== null) {
                $residueCum += $round->transfer['truncation_residue_micro'];

                if ($round->transfer['kind'] === 'surplus') {
                    $this->assertLessThanOrEqual(Micro::SCALE, $round->transfer['value_micro'], "{$ctx}: r{$n}: value > 1.0");
                    $restedAtQuota[$round->candidacyId] = true;
                }

                if ($round->action === 'eliminate') {
                    $own = $round->tallies['candidates'][$round->candidacyId];
                    $electedSoFar = array_fill_keys($round->tallies['elected_so_far'], true);
                    foreach ($round->tallies['candidates'] as $id => $t) {
                        if ($id === $round->candidacyId || isset($electedSoFar[$id]) || $t >= $quotaMicro) {
                            continue; // elected and pending candidates are not elimination candidates
                        }
                        $this->assertGreaterThanOrEqual($own, $t, "{$ctx}: r{$n}: eliminated {$round->candidacyId} was not lowest");
                    }
                }
            }
        }

        $this->assertSame(
            $totalMicro,
            array_sum($r->finalTallies) + $r->exhaustedMicro + $r->truncationResidueMicro,
            "{$ctx}: final conservation",
        );
        $this->assertSame($residueCum, $r->truncationResidueMicro, "{$ctx}: residue bookkeeping");
    }
}
