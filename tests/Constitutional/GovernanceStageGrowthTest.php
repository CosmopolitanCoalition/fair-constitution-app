<?php

namespace Tests\Constitutional;

use App\Services\Demo\Stages\GovernanceStage;
use App\Services\InstitutionScaleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the growth dial drives committees through the REAL FORM,
 * and defers where the act cannot lawfully pass.
 *
 * SERVICE_SCALE_FORMULA.md §5 stages 4–5 (Maturing → Peak) grow a seated chamber
 * toward `K(S)` committees. §4.4 and Q4 (operator, 2026-07-29) are emphatic that
 * committees are ACTS OF SELF-GOVERNMENT: a provisioning engine may NEVER mint
 * them, they arrive through F-LEG-009 once a chamber is seated and VOTES.
 *
 * THE INVARIANTS:
 *
 *  1. THE COMMITTEE IS CREATED BY AN ADOPTED VOTE, NOT BY THE SIM. Every
 *     committee the stage produces carries a `created_by_vote_id`. That column
 *     is written by `CommitteeService`'s adoption dispatch, so its presence is
 *     proof the row came through a real supermajority chamber vote and not a
 *     direct insert. This is the invariant that makes the whole stage lawful.
 *  2. IT STOPS AT THE FORMULA'S TARGET. `K(S)` is a pre-governance ceiling.
 *  3. IT IS IDEMPOTENT, and it NEVER OVERRIDES A GOVERNED CHOICE (§6): a chamber
 *     already at or past its target is left exactly as it voted itself.
 *  4. ⚑ THE BICAMERAL DEFERRAL. A committee act must satisfy the Art. V §3 kind
 *     split, so a chamber whose Type B half is unseated CANNOT pass one. The
 *     stage skips and says why — it never forces the act, fakes a member, or
 *     lowers a threshold. Same doctrine as R_A_OBSERVANCE.md: the sim defers to
 *     the guard, it never fights it.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class GovernanceStageGrowthTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_governance_stage';

    public function test_the_dial_grows_committees_through_real_adopted_votes(): void
    {
        $this->onLivePg(function () {
            // A 10-seat unicameral chamber: K(10) = 2 (the round(S/5) staffing
            // clamp binds), so the dial should produce exactly two committees.
            [$jurId, $legId] = $this->seatedChamber(totalSeats: 10, members: 10, typeBSeats: 0);

            $target = InstitutionScaleService::committeeTarget(10);
            $this->assertSame(2, $target, 'K(10) must be 2 — if the curve moved, this fixture needs revisiting');

            $result = GovernanceStage::run($jurId, null, 1);

            $this->assertNull($result['skipped'], 'a seated unicameral chamber can pass a committee act');
            $this->assertSame($target, $result['created'], 'the dial grows to exactly the formula target');

            $committees = DB::table('committees')
                ->where('legislature_id', $legId)
                ->whereNull('deleted_at')
                ->get();

            $this->assertCount($target, $committees);

            // (1) THE INVARIANT THAT MATTERS: each row was created by an ADOPTED
            // VOTE. The sim never wrote a committee; the vote engine did.
            foreach ($committees as $c) {
                $this->assertNotNull(
                    $c->created_by_vote_id,
                    'a committee the sim produced MUST carry the vote that adopted it — '
                    .'a row without one would mean the sim minted an act of self-government'
                );
            }
        });
    }

    public function test_it_is_idempotent_and_never_overrides_a_governed_choice(): void
    {
        $this->onLivePg(function () {
            [$jurId, $legId] = $this->seatedChamber(totalSeats: 10, members: 10, typeBSeats: 0);

            GovernanceStage::run($jurId, null, 1);
            $after = DB::table('committees')->where('legislature_id', $legId)->whereNull('deleted_at')->count();

            // Re-running must add nothing.
            $again = GovernanceStage::run($jurId, null, 1);

            $this->assertSame(0, $again['created'], 're-running the dial must create nothing');
            $this->assertSame('at target', $again['skipped']);
            $this->assertSame(
                $after,
                DB::table('committees')->where('legislature_id', $legId)->whereNull('deleted_at')->count(),
                'the committee count must not move on a re-run'
            );
        });
    }

    /**
     * ⚑ The deferral. `type_b_seats > 0` with nobody seated in the Type B half is
     * exactly the state lane 1's Type B race fix exists to clear. The act cannot
     * pass, so the stage must decline it — with a reason, not a crash and not a
     * forced act.
     */
    public function test_a_bicameral_chamber_with_an_unseated_type_b_half_is_deferred(): void
    {
        $this->onLivePg(function () {
            [$jurId, $legId] = $this->seatedChamber(totalSeats: 21, members: 5, typeBSeats: 16);

            $result = GovernanceStage::run($jurId, null, 1);

            $this->assertStringContainsString(
                'unseated Type B half',
                (string) $result['skipped'],
                'a bicameral chamber that cannot satisfy the Art. V §3 kind split must be deferred, not forced'
            );
            $this->assertSame(0, $result['created']);
            $this->assertSame(
                0,
                DB::table('committees')->where('legislature_id', $legId)->whereNull('deleted_at')->count(),
                'nothing may be created for a chamber that cannot lawfully pass the act'
            );
        });
    }

    public function test_an_unseated_chamber_has_nothing_to_grow_into(): void
    {
        $this->onLivePg(function () {
            [$jurId] = $this->seatedChamber(totalSeats: 10, members: 0, typeBSeats: 0);

            $result = GovernanceStage::run($jurId, null, 1);

            $this->assertSame('chamber not seated', $result['skipped']);
            $this->assertSame(0, $result['created']);
        });
    }

    /**
     * A jurisdiction + legislature + N seated members with real users. Seating is
     * another stage's job, so it is set up directly here rather than driven —
     * this pin is about what happens AFTER a chamber seats.
     *
     * @return array{0:string,1:string} [jurisdictionId, legislatureId]
     */
    private function seatedChamber(int $totalSeats, int $members, int $typeBSeats): array
    {
        $tag = 'gov-'.Str::lower(Str::random(6));

        $jurId = (string) Str::uuid();
        DB::table('jurisdictions')->insert([
            'id' => $jurId, 'name' => $tag, 'slug' => $tag,
            'adm_level' => 2, 'population' => 50_000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $legId = (string) Str::uuid();
        DB::table('legislatures')->insert([
            'id' => $legId, 'jurisdiction_id' => $jurId, 'term_number' => 1,
            'status' => 'active', 'total_seats' => $totalSeats,
            'type_a_seats' => $totalSeats - $typeBSeats, 'type_b_seats' => $typeBSeats,
            'quorum_required' => (int) ceil($totalSeats / 2),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        for ($i = 0; $i < $members; $i++) {
            $userId = (string) Str::uuid();
            DB::table('users')->insert([
                'id' => $userId,
                'name' => "{$tag}-m{$i}",
                'email' => "{$tag}-m{$i}@example.test",
                'password' => bcrypt('password'),
                // NOT NULL on users — registration always records the acceptance.
                'terms_accepted_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('legislature_members')->insert([
                'id' => (string) Str::uuid(),
                'legislature_id' => $legId,
                'user_id' => $userId,
                'seat_type' => 'A',
                'seat_no' => $i + 1,
                'status' => 'elected',
                'seated_on' => now()->toDateString(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$jurId, $legId];
    }

    /** The AchievementsPageTest posture: live pg, set as default, always rolled back. */
    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
