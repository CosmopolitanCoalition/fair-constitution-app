<?php

namespace Tests\Constitutional;

use App\Models\Election;
use App\Services\Demo\Stages\CohortStage;
use App\Services\Demo\Stages\CountingStage;
use App\Services\Demo\Stages\ElectionStage;
use App\Services\Demo\Stages\IdentityStage;
use App\Services\Demo\Stages\SeatingStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the SEATING stage, where a synthetic world finally
 * acquires a government.
 *
 * THIS IS WHERE BATCHING STOPS, AND THAT IS THE POINT. Everything upstream is
 * mechanically derived — cohorts, rosters, candidacies, tabulations — and is
 * written in bulk under one summary audit entry. Certifying an election and
 * seating a member are not that: they are the acts by which a government comes
 * to exist. The pinned engine keeps them per-act everywhere else, and the
 * operator's D3 ruling drew the line in the same place. So this stage files ONE
 * F-ELB-004 per election through the real ConstitutionalEngine and pays the
 * serial audit cost.
 *
 * THE INVARIANTS:
 *   · seating runs through the REAL certification path — no re-implementation
 *   · an election with an uncounted race REFUSES, with a readable reason
 *     (`certifiedTabulation()` would otherwise throw)
 *   · members are actually seated, one per seat, each attached to a real person
 *   · the chamber leaves `forming` — a legislature that never activates cannot
 *     legislate, appoint a court, or form an executive
 *   · re-running does NOT re-certify
 *   · certification appends its OWN chain entry — it is not swept into a batch
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class SeatingStageTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_seating';

    /** The headline: a synthetic world gets a real, seated chamber. */
    public function test_it_certifies_and_seats_a_real_chamber(): void
    {
        $this->onLivePg(function () {
            [$jid, $electionId] = $this->countedElection(typeA: 14, typeB: 10, districtSeats: [7, 7]);

            $result = SeatingStage::run($electionId, null, 1);

            $this->assertTrue($result['certified'], $result['skipped'] ?? 'certification refused');
            $this->assertSame(24, $result['seated'], '7 + 7 district seats + 5 children × 2 per-child');

            $this->assertSame(
                Election::STATUS_CERTIFIED,
                DB::table('elections')->where('id', $electionId)->value('status')
            );

            $members = DB::table('legislature_members')
                ->where('election_id', $electionId)
                ->whereNull('deleted_at')
                ->get();

            $this->assertCount(24, $members);

            foreach ($members as $m) {
                $this->assertNotNull($m->user_id, 'every seat is held by a real person row');
                $this->assertNotNull($m->term_id, 'and every seat opens a term');
            }

            // Both chambers represented — the bicameral half is not lost.
            $kinds = $members->pluck('seat_type')->unique()->sort()->values()->all();
            $this->assertSame(['a', 'b'], $kinds);
        });
    }

    /** A chamber that never activates cannot legislate, appoint or form an executive. */
    public function test_the_chamber_leaves_forming(): void
    {
        $this->onLivePg(function () {
            [$jid, $electionId] = $this->countedElection(typeA: 9, typeB: 0, districtSeats: [9]);

            SeatingStage::run($electionId, null, 1);

            $status = DB::table('legislatures')->where('jurisdiction_id', $jid)->value('status');

            $this->assertNotSame(
                'forming',
                $status,
                'a legislature stuck in forming has no powers — the world would look governed and not be'
            );
        });
    }

    /** An uncounted race must refuse READABLY, not throw from deep inside certification. */
    public function test_an_uncounted_race_refuses_with_a_reason(): void
    {
        $this->onLivePg(function () {
            [, $electionId] = $this->contestedElection(typeA: 14, typeB: 0, districtSeats: [7, 7]);

            // Deliberately NOT counted.
            $result = SeatingStage::run($electionId, null, 1);

            $this->assertFalse($result['certified']);
            $this->assertSame(0, $result['seated']);
            $this->assertStringContainsString('not yet counted', (string) $result['skipped']);
        });
    }

    /** The pump re-hands items; a second pass must not re-certify. */
    public function test_re_running_does_not_certify_twice(): void
    {
        $this->onLivePg(function () {
            [, $electionId] = $this->countedElection(typeA: 9, typeB: 0, districtSeats: [9]);

            $first = SeatingStage::run($electionId, null, 1);
            $second = SeatingStage::run($electionId, null, 1);

            $this->assertTrue($first['certified']);
            $this->assertFalse($second['certified']);
            $this->assertSame('already certified', $second['skipped']);

            $this->assertSame(
                9,
                DB::table('legislature_members')
                    ->where('election_id', $electionId)
                    ->whereNull('deleted_at')
                    ->count(),
                'and the chamber is not seated twice'
            );
        });
    }

    /**
     * Certification is governance-constitutive and keeps its OWN chain entry.
     * It is deliberately NOT swept into the tabulation batch.
     */
    public function test_certification_appends_its_own_chain_entry(): void
    {
        $this->onLivePg(function () {
            [, $electionId] = $this->countedElection(typeA: 9, typeB: 0, districtSeats: [9]);

            $before = DB::table('audit_log')->where('event', 'election.certified')->count();

            SeatingStage::run($electionId, null, 1);

            $this->assertSame(
                $before + 1,
                DB::table('audit_log')->where('event', 'election.certified')->count(),
                'one certification, one chain entry — never batched away'
            );
        });
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string} */
    private function countedElection(int $typeA, int $typeB, array $districtSeats): array
    {
        [$jid, $electionId] = $this->contestedElection($typeA, $typeB, $districtSeats);
        CountingStage::run($electionId, null, 1);

        return [$jid, $electionId];
    }

    /** @return array{0: string, 1: string} */
    private function contestedElection(int $typeA, int $typeB, array $districtSeats): array
    {
        $jid = (string) Str::uuid();

        DB::table('jurisdictions')->insert([
            'id' => $jid,
            'name' => 'Seating Pin',
            'slug' => 'seating-pin-'.Str::lower(Str::random(10)),
            'adm_level' => 3,
            'population' => 900_000,
            'source' => 'user_defined',
            'official_languages' => '["en"]',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legId = (string) Str::uuid();
        DB::table('legislatures')->insert([
            'id' => $legId,
            'jurisdiction_id' => $jid,
            'term_number' => 1,
            'status' => 'forming',
            'total_seats' => $typeA + $typeB,
            'type_a_seats' => $typeA,
            'type_b_seats' => $typeB,
            'quorum_required' => max(3, (int) ceil(($typeA + $typeB) / 2)),
            'type_b_rep_floor' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Per-child Type B (racePlan mode='children', c96e757): seed
        // childCount = type_b_seats / rep_floor direct children (pop > TINY_POP so
        // each seats a full rep_floor of 2), or the type_b half hits racePlan's
        // drift guard and blocks. Pass an EVEN typeB.
        $childIds = [];
        for ($c = 0; $c < intdiv($typeB, 2); $c++) {
            $childIds[$c] = (string) Str::uuid();
            DB::table('jurisdictions')->insert([
                'id' => $childIds[$c], 'parent_id' => $jid, 'name' => 'Child '.$c,
                'slug' => 'child-'.Str::lower(Str::random(10)), 'adm_level' => 4,
                'population' => 100, 'source' => 'user_defined',
                'official_languages' => '["en"]', 'timezone' => 'UTC',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // The bootstrap board — its synthetic member row (user_id NULL) is what
        // a system filing acts through. ActivationService seats one of these
        // when a jurisdiction activates for real.
        $boardId = (string) Str::uuid();
        DB::table('election_boards')->insert([
            'id' => $boardId,
            'jurisdiction_id' => $jid,
            'is_bootstrap' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('election_board_members')->insert([
            'id' => (string) Str::uuid(),
            'election_board_id' => $boardId,
            'user_id' => null,
            'status' => 'seated',
            'term_starts_on' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($districtSeats !== []) {
            $mapId = (string) Str::uuid();
            DB::table('legislature_district_maps')->insert([
                'id' => $mapId,
                'legislature_id' => $legId,
                'name' => 'Pin Map',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($districtSeats as $i => $seats) {
                DB::table('legislature_districts')->insert([
                    'id' => (string) Str::uuid(),
                    'legislature_id' => $legId,
                    'map_id' => $mapId,
                    'jurisdiction_id' => $jid,
                    'district_number' => $i + 1,
                    'seats' => $seats,
                    'target_population' => 400_000,
                    'actual_population' => 400_000,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        CohortStage::run($jid, null, 1, 62);
        IdentityStage::run($jid, null, 1);
        foreach ($childIds as $cid) {
            // Each child: its own cohort (per-child electorate) + identities
            // (lane 4's fieldCandidates draws a per-child race from that child's
            // depth-0 roster).
            CohortStage::run($cid, null, 1, 62);
            IdentityStage::run($cid, null, 1);
        }
        $election = ElectionStage::run($jid, null, 1);

        return [$jid, (string) $election['election_id']];
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
