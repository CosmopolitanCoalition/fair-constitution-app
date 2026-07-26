<?php

namespace Tests\Constitutional;

use App\Models\Candidacy;
use App\Services\Demo\Stages\CohortStage;
use App\Services\Demo\Stages\ElectionStage;
use App\Services\Demo\Stages\IdentityStage;
use App\Services\TabulationRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the ELECTIONS stage.
 *
 * THE ENGINE IS DRIVEN, NEVER COPIED. The election, its races and the seat
 * arithmetic come from `ElectionLifecycleService::scheduleGeneral()` — the same
 * method CLK-01 calls on a live instance. A demo whose elections were shaped by
 * a parallel implementation would be a drawing of a government; one that calls
 * the real lifecycle is the same machine with synthetic input.
 *
 * THE INVARIANTS:
 *   · races come from the ACTIVE district map, via the real race plan
 *   · exactly Σ(seats + 1) candidates — a race must be a contest, and one more
 *     than the seats is the minimum that makes it one
 *   · candidates are RESIDENTS — `RaceFootprint` gates candidacy on an active
 *     residency association, so the local roster is the only lawful source
 *   · one candidacy per person per election (a DB unique constraint), which is
 *     WHY the identity roster is sized Σ(seats + 1) in the first place
 *   · candidacies are in a COUNTABLE status, or the count would see an empty field
 *   · a fully-blocked chamber elects NOBODY and settles honestly — the world is
 *     allowed to contain places whose government is still forming
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class ElectionStageTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_electionstage';

    /** The headline: real races off the real map, contested by exactly enough people. */
    public function test_it_calls_a_real_election_with_exactly_enough_candidates(): void
    {
        $this->onLivePg(function () {
            $jid = $this->world(typeA: 14, typeB: 5, districtSeats: [7, 7]);

            $result = ElectionStage::run($jid, null, 1);

            $this->assertNotNull($result['election_id']);

            // 2 district races + 1 lawful at-large type_b race.
            $this->assertSame(3, $result['races']);

            // Σ(seats + 1): (7+1) + (7+1) + (5+1) = 22.
            $this->assertSame(22, $result['candidacies']);

            $rows = DB::table('election_races')->where('election_id', $result['election_id'])->get();
            $this->assertSame(
                22,
                $rows->sum(fn ($r) => (int) $r->seats + 1),
                'the candidate count must equal the races the engine actually generated'
            );
        });
    }

    /** Every candidate is a resident — the only lawful source. */
    public function test_candidates_are_residents_of_the_jurisdiction(): void
    {
        $this->onLivePg(function () {
            $jid = $this->world(typeA: 14, typeB: 0, districtSeats: [7, 7]);
            $result = ElectionStage::run($jid, null, 1);

            $candidateIds = DB::table('candidacies')
                ->where('election_id', $result['election_id'])
                ->pluck('user_id')
                ->all();

            $residentIds = DB::table('residency_confirmations')
                ->where('jurisdiction_id', $jid)
                ->where('is_active', true)
                ->pluck('user_id')
                ->all();

            $this->assertNotEmpty($candidateIds);
            $this->assertEmpty(
                array_diff($candidateIds, $residentIds),
                'a candidate who is not a resident would be refused by RaceFootprint'
            );
        });
    }

    /** One candidacy per person per election — a DB constraint, and the reason for the roster size. */
    public function test_no_person_contests_an_election_twice(): void
    {
        $this->onLivePg(function () {
            $jid = $this->world(typeA: 14, typeB: 5, districtSeats: [7, 7]);
            $result = ElectionStage::run($jid, null, 1);

            $ids = DB::table('candidacies')
                ->where('election_id', $result['election_id'])
                ->pluck('user_id')
                ->all();

            $this->assertSame(
                count($ids),
                count(array_unique($ids)),
                'the roster must be CONSUMED across races, never reused'
            );
        });
    }

    /** The count must be able to see them, or the election is a formality. */
    public function test_candidacies_are_countable(): void
    {
        $this->onLivePg(function () {
            $jid = $this->world(typeA: 14, typeB: 0, districtSeats: [7, 7]);
            $result = ElectionStage::run($jid, null, 1);

            $statuses = DB::table('candidacies')
                ->where('election_id', $result['election_id'])
                ->pluck('status')
                ->unique()
                ->all();

            foreach ($statuses as $status) {
                $this->assertContains(
                    $status,
                    TabulationRecorder::COUNTABLE_CANDIDACY_STATUSES,
                    'a candidacy the counting engine cannot see is not a candidacy'
                );
            }

            $this->assertSame([Candidacy::STATUS_VALIDATED], $statuses);
        });
    }

    /**
     * A chamber with no lawful race elects NOBODY — and that is a DONE outcome,
     * not a failure. The world may contain places whose government is forming.
     */
    public function test_a_fully_blocked_chamber_elects_nobody_and_does_not_fail(): void
    {
        $this->onLivePg(function () {
            // Over-ceiling type_a with NO district map, and an unlawful type_b.
            $jid = $this->world(typeA: 152, typeB: 400, districtSeats: []);

            $result = ElectionStage::run($jid, null, 1);

            $this->assertNull($result['election_id'], 'nothing lawful can be scheduled here');
            $this->assertSame(0, $result['races']);
            $this->assertSame(0, $result['candidacies']);
            $this->assertNotEmpty($result['blocked_kinds'], 'and the reason must be reported, not swallowed');
        });
    }

    /** Per-kind blocking carries through: the lawful half still elects. */
    public function test_an_unlawful_type_b_does_not_stop_the_district_races(): void
    {
        $this->onLivePg(function () {
            $jid = $this->world(typeA: 14, typeB: 1141, districtSeats: [7, 7]);

            $result = ElectionStage::run($jid, null, 1);

            $this->assertNotNull($result['election_id']);
            $this->assertSame(2, $result['races'], 'the two lawful district races proceed');
            $this->assertSame(16, $result['candidacies'], '(7+1) + (7+1)');
            $this->assertContains('type_b', $result['blocked_kinds'], 'and the blocked half is still flagged');
        });
    }

    /** Most jurisdictions have no chamber. That is ordinary, not an error. */
    public function test_a_jurisdiction_without_a_legislature_is_a_no_op(): void
    {
        $this->onLivePg(function () {
            $jid = $this->jurisdiction(50_000);
            CohortStage::run($jid, null, 1, 62);

            $result = ElectionStage::run($jid, null, 1);

            $this->assertNull($result['election_id']);
            $this->assertSame(0, $result['races']);
            $this->assertSame(0, $result['candidacies']);
        });
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    /** A jurisdiction with a cohort, a roster and a chamber — the stage's precondition. */
    private function world(int $typeA, int $typeB, array $districtSeats): string
    {
        $jid = $this->jurisdiction(2_000_000);
        $this->legislature($jid, $typeA, $typeB, $districtSeats);
        CohortStage::run($jid, null, 1, 62);
        IdentityStage::run($jid, null, 1);

        return $jid;
    }

    private function jurisdiction(int $population): string
    {
        $id = (string) Str::uuid();

        DB::table('jurisdictions')->insert([
            'id' => $id,
            'name' => 'Election Pin',
            'slug' => 'election-pin-'.Str::lower(Str::random(10)),
            'adm_level' => 3,
            'population' => $population,
            'source' => 'user_defined',
            'official_languages' => '["en"]',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function legislature(string $jid, int $typeA, int $typeB, array $districtSeats): void
    {
        $id = (string) Str::uuid();

        DB::table('legislatures')->insert([
            'id' => $id,
            'jurisdiction_id' => $jid,
            'term_number' => 1,
            'status' => 'forming',
            'total_seats' => $typeA + $typeB,
            'type_a_seats' => $typeA,
            'type_b_seats' => $typeB,
            'quorum_required' => max(3, (int) ceil(($typeA + $typeB) / 2)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($districtSeats === []) {
            return;
        }

        $mapId = (string) Str::uuid();
        DB::table('legislature_district_maps')->insert([
            'id' => $mapId,
            'legislature_id' => $id,
            'name' => 'Pin Map',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($districtSeats as $i => $seats) {
            DB::table('legislature_districts')->insert([
                'id' => (string) Str::uuid(),
                'legislature_id' => $id,
                'map_id' => $mapId,
                'jurisdiction_id' => $jid,
                'district_number' => $i + 1,
                'seats' => $seats,
                'target_population' => 1_000_000,
                'actual_population' => 1_000_000,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

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
