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

            $committees = GovernanceStage::run($jurId, null, 1)['committees'];

            $this->assertNull($committees['skipped'], 'a seated unicameral chamber can pass a committee act');
            $this->assertSame($target, $committees['created'], 'the dial grows to exactly the formula target');

            $rows = DB::table('committees')
                ->where('legislature_id', $legId)
                ->whereNull('deleted_at')
                ->get();

            $this->assertCount($target, $rows);

            // (1) THE INVARIANT THAT MATTERS: each row was created by an ADOPTED
            // VOTE. The sim never wrote a committee; the vote engine did.
            foreach ($rows as $c) {
                $this->assertNotNull(
                    $c->created_by_vote_id,
                    'a committee the sim produced MUST carry the vote that adopted it — '
                    .'a row without one would mean the sim minted an act of self-government'
                );
            }
        });
    }

    public function test_the_dial_delegates_the_executive_and_charters_departments_through_real_votes(): void
    {
        $this->onLivePg(function () {
            // 12 members clears the 5+ committee-executive floor; a forming
            // executive is provisioned so the dial must DELEGATE it first.
            [$jurId, $legId] = $this->seatedChamber(totalSeats: 12, members: 12, typeBSeats: 0, population: 1_500, forming_exec: true);

            $target = InstitutionScaleService::departmentTarget(1_500);
            $this->assertGreaterThanOrEqual(3, $target, 'D floors at 3');

            $dept = GovernanceStage::run($jurId, null, 1)['departments'];

            $this->assertTrue($dept['delegated'], 'the dial delegated the forming executive before chartering departments');
            $this->assertNull($dept['skipped'], 'a delegated executive can charter departments');
            $this->assertSame($target, $dept['created'], 'the dial grows departments to exactly D(P)');

            // The executive is now delegated — the department half proved it end to end.
            $this->assertContains(
                DB::table('executives')->where('jurisdiction_id', $jurId)->value('status'),
                ['delegated', 'elected'],
                'the executive must be delegated for a department to exist'
            );

            $rows = DB::table('departments')->where('jurisdiction_id', $jurId)->whereNull('deleted_at')->get();
            $this->assertCount($target, $rows);

            // THE INVARIANT: each department carries the charter LAW its adopting
            // vote enacted — proof the sim minted no act. A row without one would
            // mean a provisioning-style direct write.
            foreach ($rows as $d) {
                $this->assertNotNull(
                    $d->charter_law_id,
                    'a department the sim produced MUST carry the charter law its vote enacted'
                );
            }

            // Mandatory kinds (Art. II §9) are filled first.
            $kinds = $rows->pluck('kind')->all();
            $this->assertContains('treasury', $kinds, 'the mandatory kinds are chartered first');
        });
    }

    public function test_a_chamber_too_small_to_seat_an_executive_committee_defers_departments(): void
    {
        $this->onLivePg(function () {
            // Five members: at/under the 5+ committee floor, so no delegation is
            // possible — the department half must defer, not force it.
            [$jurId] = $this->seatedChamber(totalSeats: 5, members: 5, typeBSeats: 0, population: 800, forming_exec: true);

            $dept = GovernanceStage::run($jurId, null, 1)['departments'];

            $this->assertFalse($dept['delegated']);
            $this->assertStringContainsString('too few seated members', (string) $dept['skipped']);
            $this->assertSame(0, $dept['created']);
        });
    }

    public function test_the_department_half_defers_when_there_is_no_executive(): void
    {
        $this->onLivePg(function () {
            [$jurId] = $this->seatedChamber(totalSeats: 12, members: 12, typeBSeats: 0, population: 1_500, forming_exec: false);

            $dept = GovernanceStage::run($jurId, null, 1)['departments'];

            $this->assertStringContainsString('no executive', (string) $dept['skipped']);
            $this->assertSame(0, $dept['created']);
        });
    }

    public function test_it_is_idempotent_and_never_overrides_a_governed_choice(): void
    {
        $this->onLivePg(function () {
            [$jurId, $legId] = $this->seatedChamber(totalSeats: 10, members: 10, typeBSeats: 0);

            GovernanceStage::run($jurId, null, 1);
            $after = DB::table('committees')->where('legislature_id', $legId)->whereNull('deleted_at')->count();

            // Re-running must add nothing.
            $again = GovernanceStage::run($jurId, null, 1)['committees'];

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

            $committees = GovernanceStage::run($jurId, null, 1)['committees'];

            $this->assertStringContainsString(
                'unseated Type B half',
                (string) $committees['skipped'],
                'a bicameral chamber that cannot satisfy the Art. V §3 kind split must be deferred, not forced'
            );
            $this->assertSame(0, $committees['created']);
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
            $this->assertSame(0, $result['committees']['created']);
            $this->assertSame(0, $result['departments']['created']);
        });
    }

    /**
     * A jurisdiction + legislature + N seated members with real users. Seating is
     * another stage's job, so it is set up directly here rather than driven —
     * this pin is about what happens AFTER a chamber seats.
     *
     * @return array{0:string,1:string} [jurisdictionId, legislatureId]
     */
    private function seatedChamber(
        int $totalSeats,
        int $members,
        int $typeBSeats,
        int $population = 50_000,
        bool $forming_exec = false,
    ): array {
        $tag = 'gov-'.Str::lower(Str::random(6));

        $jurId = (string) Str::uuid();
        DB::table('jurisdictions')->insert([
            'id' => $jurId, 'name' => $tag, 'slug' => $tag,
            'adm_level' => 2, 'population' => $population,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A forming executive — the shell InstitutionProvisionService mints — so
        // the department half must DELEGATE it before it can hold a department.
        if ($forming_exec) {
            DB::table('executives')->insert([
                'id' => (string) Str::uuid(),
                'jurisdiction_id' => $jurId,
                'type' => 'committee',
                'term_number' => 1,
                'status' => 'forming',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

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
