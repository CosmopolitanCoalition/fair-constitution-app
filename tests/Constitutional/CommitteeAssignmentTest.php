<?php

namespace Tests\Constitutional;

use App\Services\Legislature\CommitteeAssignmentService;
use App\Services\Legislature\CommitteeService;
use PHPUnit\Framework\TestCase;

/**
 * Pins THE committee assignment algorithm (PHASE_C_DESIGN_chamber_ops
 * §C.2–C.4, F-SPK-005) DB-free over the pure static core:
 *
 *  - the WF-LEG-03 share formula: placements distribute EVENLY (counts
 *    differ by at most 1); P = M ⇒ exactly one placement each (mockup);
 *  - contested seats resolve by vote_share_norm DESC — the q-ledger #q2
 *    tie-break currency (the mockup's Chen 1.08 > Okonkwo 0.99 case),
 *    losers' next preference honored within the same pass;
 *  - extras (P mod M) go to the highest shares;
 *  - bicameral kind-ratio mirroring (Art. V §3): per-kind partitions +
 *    the largest-remainder kind split (San Marino 32a:9b, 5 seats →
 *    4a+1b), each kind ≥ 1 at seats ≥ 2;
 *  - exhaustion guard: an exhausted list places on the most-open
 *    committee with rank NULL;
 *  - determinism: identical input → identical output, byte for byte.
 *
 * If an edit to the algorithm breaks these pins, the edit is the
 * violation — fix the edit, never the test.
 */
class CommitteeAssignmentTest extends TestCase
{
    /** 1e4-scaled share helper. */
    private static function share(float $value): int
    {
        return (int) round($value * CommitteeAssignmentService::SHARE_SCALE);
    }

    /** The mockup case: 9 members, 3 committees × 3 seats → 1 each. */
    public function test_share_formula_p_equals_m_gives_exactly_one_placement_each(): void
    {
        $committees = [
            'c-env' => ['all' => 3],
            'c-bud' => ['all' => 3],
            'c-eth' => ['all' => 3],
        ];

        $members = [];
        foreach (range(1, 9) as $i) {
            $members["m-{$i}"] = ['kind' => 'all', 'share' => self::share(1.0), 'seat_no' => $i];
        }

        $result = CommitteeAssignmentService::assign($committees, $members, []);

        $this->assertCount(9, $result['placements']);

        $counts = [];
        foreach ($result['placements'] as $p) {
            $counts[$p['member_id']] = ($counts[$p['member_id']] ?? 0) + 1;
        }

        $this->assertSame(array_fill_keys(array_keys($members), 1), $counts, 'P = M ⇒ exactly one placement each');
        $this->assertSame(['p' => 9, 'm' => 9, 'base' => 1, 'extras' => []], $result['partitions']['all']);
    }

    public function test_placement_counts_never_differ_by_more_than_one(): void
    {
        // P = 7, M = 3 → budgets 3/2/2 (extra to the highest share).
        $committees = [
            'c-a' => ['all' => 3],
            'c-b' => ['all' => 2],
            'c-c' => ['all' => 2],
        ];

        $members = [
            'm-hi'  => ['kind' => 'all', 'share' => self::share(1.31), 'seat_no' => 1],
            'm-mid' => ['kind' => 'all', 'share' => self::share(1.05), 'seat_no' => 2],
            'm-lo'  => ['kind' => 'all', 'share' => self::share(0.84), 'seat_no' => 3],
        ];

        $result = CommitteeAssignmentService::assign($committees, $members, []);

        $counts = [];
        foreach ($result['placements'] as $p) {
            $counts[$p['member_id']] = ($counts[$p['member_id']] ?? 0) + 1;
        }

        $this->assertSame(3, $counts['m-hi'], 'the P mod M extra goes to the highest vote_share_norm (#q2)');
        $this->assertSame(2, $counts['m-mid']);
        $this->assertSame(2, $counts['m-lo']);
        $this->assertSame(['m-hi'], $result['partitions']['all']['extras']);
        $this->assertLessThanOrEqual(1, max($counts) - min($counts), 'evenness: counts differ by at most 1');
    }

    /** The mockup's contested-seat case: Chen 1.08 beats Okonkwo 0.99. */
    public function test_contested_seat_resolves_by_vote_share_norm_and_loser_next_preference_honored(): void
    {
        $committees = [
            'c-env' => ['all' => 1], // one Environment seat, two contenders
            'c-bud' => ['all' => 1],
        ];

        $members = [
            'm-chen'    => ['kind' => 'all', 'share' => self::share(1.08), 'seat_no' => 1],
            'm-okonkwo' => ['kind' => 'all', 'share' => self::share(0.99), 'seat_no' => 2],
        ];

        $preferences = [
            'm-chen'    => ['c-env', 'c-bud'],
            'm-okonkwo' => ['c-env', 'c-bud'],
        ];

        $result = CommitteeAssignmentService::assign($committees, $members, $preferences);

        $byMember = [];
        foreach ($result['placements'] as $p) {
            $byMember[$p['member_id']] = $p;
        }

        $this->assertSame('c-env', $byMember['m-chen']['committee_id'], 'higher share takes the contested seat');
        $this->assertSame('tie_break', $byMember['m-chen']['assigned_via']);
        $this->assertSame(1, $byMember['m-chen']['preference_rank_honored']);

        $this->assertSame('c-bud', $byMember['m-okonkwo']['committee_id'], "loser's NEXT preference honored in the same pass");
        $this->assertSame(2, $byMember['m-okonkwo']['preference_rank_honored']);

        $this->assertCount(1, $result['contests']);
        $this->assertSame('c-env', $result['contests'][0]['committee_id']);
        $this->assertSame(['m-chen'], $result['contests'][0]['winners']);
        $this->assertSame(['m-okonkwo'], $result['contests'][0]['losers']);
    }

    public function test_bicameral_partitions_assign_per_kind(): void
    {
        // A 5-seat committee split 4a + 1b (the San Marino shape, scaled
        // down): kinds never contend with each other.
        $committees = [
            'c-1' => ['type_a' => 4, 'type_b' => 1],
        ];

        $members = [
            'a-1' => ['kind' => 'type_a', 'share' => self::share(1.2), 'seat_no' => 1],
            'a-2' => ['kind' => 'type_a', 'share' => self::share(1.1), 'seat_no' => 2],
            'a-3' => ['kind' => 'type_a', 'share' => self::share(1.0), 'seat_no' => 3],
            'a-4' => ['kind' => 'type_a', 'share' => self::share(0.9), 'seat_no' => 4],
            'b-1' => ['kind' => 'type_b', 'share' => self::share(1.0), 'seat_no' => 5],
        ];

        $result = CommitteeAssignmentService::assign($committees, $members, []);

        $this->assertCount(5, $result['placements']);

        $kinds = [];
        foreach ($result['placements'] as $p) {
            $kinds[$p['seat_kind']] = ($kinds[$p['seat_kind']] ?? 0) + 1;
        }

        $this->assertSame(4, $kinds['type_a'], 'type_a seats filled by type_a members only');
        $this->assertSame(1, $kinds['type_b'], 'type_b seat filled by the type_b member');
    }

    public function test_exhaustion_guard_places_on_most_open_committee_with_null_rank(): void
    {
        // m-x ranks ONLY c-tiny (1 seat) and loses it; the guard places
        // them on the most-open committee with rank NULL.
        $committees = [
            'c-tiny' => ['all' => 1],
            'c-big'  => ['all' => 2],
        ];

        $members = [
            'm-win' => ['kind' => 'all', 'share' => self::share(1.5), 'seat_no' => 1],
            'm-x'   => ['kind' => 'all', 'share' => self::share(0.7), 'seat_no' => 2],
            'm-y'   => ['kind' => 'all', 'share' => self::share(1.0), 'seat_no' => 3],
        ];

        $preferences = [
            'm-win' => ['c-tiny'],   // exhausts after winning round 1? No — wins c-tiny at rank 1
            'm-x'   => ['c-tiny'],   // loses the contest, list exhausts
            'm-y'   => ['c-big'],
        ];

        $result = CommitteeAssignmentService::assign($committees, $members, $preferences);

        $byMember = [];
        foreach ($result['placements'] as $p) {
            $byMember[$p['member_id']] = $p;
        }

        $this->assertSame('c-tiny', $byMember['m-win']['committee_id']);
        $this->assertSame('c-big', $byMember['m-x']['committee_id'], 'exhausted list → most-open committee');
        $this->assertNull($byMember['m-x']['preference_rank_honored'], 'exhaustion placements carry NULL rank');
        $this->assertSame([['member_id' => 'm-x', 'committee_id' => 'c-big']], $result['exhaustion']);
    }

    public function test_determinism_identical_input_identical_output(): void
    {
        $committees = [
            'c-1' => ['all' => 2],
            'c-2' => ['all' => 2],
        ];

        $members = [
            'm-1' => ['kind' => 'all', 'share' => self::share(1.0), 'seat_no' => 1],
            'm-2' => ['kind' => 'all', 'share' => self::share(1.0), 'seat_no' => 2],
            'm-3' => ['kind' => 'all', 'share' => self::share(1.0), 'seat_no' => 3],
            'm-4' => ['kind' => 'all', 'share' => self::share(1.0), 'seat_no' => 4],
        ];

        $preferences = ['m-3' => ['c-2', 'c-1']];

        $first  = CommitteeAssignmentService::assign($committees, $members, $preferences);
        $second = CommitteeAssignmentService::assign($committees, $members, $preferences);

        $this->assertSame(
            json_encode($first),
            json_encode($second),
            'same snapshot → byte-identical assignment'
        );
    }

    public function test_equal_shares_fall_back_to_seat_no_then_member_id(): void
    {
        $a = ['share' => self::share(1.0), 'seat_no' => 2];
        $b = ['share' => self::share(1.0), 'seat_no' => 1];

        $this->assertGreaterThan(0, CommitteeAssignmentService::compareMembers($a, 'm-a', $b, 'm-b'), 'lower seat_no first');

        $c = ['share' => self::share(1.0), 'seat_no' => null];

        $this->assertLessThan(0, CommitteeAssignmentService::compareMembers($b, 'm-b', $c, 'm-c'), 'null seat_no last');

        $this->assertLessThan(
            0,
            CommitteeAssignmentService::compareMembers(
                ['share' => self::share(1.0), 'seat_no' => null],
                'm-a',
                ['share' => self::share(1.0), 'seat_no' => null],
                'm-b'
            ),
            'final fallback: member uuid ascending'
        );
    }

    // ─── Kind split (Art. V §3 mirror) ───────────────────────────────────

    public function test_san_marino_kind_split_five_seats_is_4a_1b(): void
    {
        $this->assertSame(['type_a' => 4, 'type_b' => 1], CommitteeService::kindSplit(5, 32, 9));
    }

    public function test_kind_split_floors_each_kind_at_one_when_seats_at_least_two(): void
    {
        // 2 seats over 40:1 — largest remainder would give 2a + 0b; the
        // Art. V §3 floor forces 1a + 1b (per-kind dual agreement must
        // never be vacuous at committee stage, q7).
        $this->assertSame(['type_a' => 1, 'type_b' => 1], CommitteeService::kindSplit(2, 40, 1));
    }

    public function test_kind_split_totals_always_match_seats(): void
    {
        foreach ([[3, 32, 9], [5, 32, 9], [7, 32, 9], [9, 32, 9], [4, 17, 5], [6, 2, 2]] as [$seats, $a, $b]) {
            $split = CommitteeService::kindSplit($seats, $a, $b);

            $this->assertSame($seats, $split['type_a'] + $split['type_b'], "split of {$seats} over {$a}:{$b}");

            if ($seats >= 2) {
                $this->assertGreaterThanOrEqual(1, $split['type_a']);
                $this->assertGreaterThanOrEqual(1, $split['type_b']);
            }
        }
    }
}
