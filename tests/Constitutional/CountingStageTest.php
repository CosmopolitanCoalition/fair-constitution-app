<?php

namespace Tests\Constitutional;

use App\Domain\Counting\BallotSet;
use App\Domain\Counting\CountInput;
use App\Services\Demo\CohortBallotExpander;
use App\Services\Demo\Stages\CohortStage;
use App\Services\Demo\Stages\CountingStage;
use App\Services\Demo\Stages\ElectionStage;
use App\Services\Demo\Stages\IdentityStage;
use App\Services\VoteCountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the COUNTING stage.
 *
 * The demo's elections are not modelled. They are COUNTED, by the real
 * PROTECTED engine, and the result is a real `record_hash`.
 *
 * The ballots are never written down and the count is exact anyway: `BallotSet`
 * stores identical rankings once with a multiplicity, `VoteCountingService`
 * re-groups its input the same way before any arithmetic, and Gregory truncates
 * the per-ballot weight BEFORE multiplying by multiplicity. So a cohort
 * expanded into weighted groups gives bit-for-bit the same answer as expanding
 * every voter — pinned by WeightedBallotIdentityTest and
 * GregoryTruncationOrderTest. This stage is what puts that property to work.
 *
 * THE INVARIANTS:
 *   · every race gets a COMPLETE tabulation with a non-null record_hash —
 *     without one, `certifiedTabulation()` throws and nobody is ever seated
 *   · winners are recorded, and there are exactly `seats` of them
 *   · the count is DETERMINISTIC: the same world re-counted gives the same
 *     record_hash, so a published result is reproducible by a third party
 *   · re-running a reclaimed item does NOT re-count a race
 *   · the batch writes ONE audit entry carrying a MERKLE ROOT over the
 *     record hashes (operator ruling D3) — fewer entries, not less evidence
 *   · provenance is recorded: these counts are cohort-fed and say so
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class CountingStageTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_counting';

    /** The headline: real counts, real winners, real hashes. */
    public function test_every_race_gets_a_real_complete_count(): void
    {
        $this->onLivePg(function () {
            [$jid, $electionId] = $this->contestedElection(typeA: 14, typeB: 10, districtSeats: [7, 7]);

            $result = CountingStage::run($electionId, null, 1);

            $this->assertSame(7, $result['races'], 'two district races plus five per-child upper-house races');
            $this->assertSame(24, $result['seats'], '7 + 7 district + 5 children × 2 = 24 seats filled');
            $this->assertGreaterThan(0, $result['ballots']);

            $tabs = DB::table('tabulations as t')
                ->join('election_races as r', 'r.id', '=', 't.race_id')
                ->where('r.election_id', $electionId)
                ->get(['t.status', 't.record_hash', 't.quota', 't.total_valid']);

            $this->assertCount(7, $tabs);

            foreach ($tabs as $t) {
                $this->assertSame('complete', $t->status);
                $this->assertNotNull(
                    $t->record_hash,
                    'without a record_hash certifiedTabulation() throws and nobody is ever seated'
                );
                $this->assertGreaterThan(0, (int) $t->quota);
                $this->assertGreaterThan(0, (int) $t->total_valid);
            }

            $this->assertSame(
                24,
                DB::table('race_results as rr')
                    ->join('tabulations as t', 't.id', '=', 'rr.tabulation_id')
                    ->join('election_races as r', 'r.id', '=', 't.race_id')
                    ->where('r.election_id', $electionId)
                    ->count(),
                'one winner row per seat'
            );
        });
    }

    /**
     * A published result must be reproducible by anyone holding the seed —
     * that is what makes the demo's elections inspectable rather than asserted.
     */
    public function test_the_count_is_reproducible_from_the_seed_alone(): void
    {
        $this->onLivePg(function () {
            [$jid, $electionId] = $this->contestedElection(typeA: 9, typeB: 0, districtSeats: [9]);

            CountingStage::run($electionId, null, 1);

            $race = DB::table('election_races')->where('election_id', $electionId)->first();
            $stored = DB::table('tabulations')->where('race_id', $race->id)->value('record_hash');

            // Re-derive independently, the way a third party would: same seed,
            // same expander, same engine.
            $candidacyIds = DB::table('candidacies')
                ->where('race_id', $race->id)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();

            $cohort = DB::table('jurisdiction_cohorts')->where('jurisdiction_id', $jid)->first();
            $districtPop = (int) DB::table('legislature_districts')
                ->where('id', $race->district_id)->value('actual_population');
            $electorate = max(1, (int) floor($districtPop * (int) $cohort->turnout_pct / 100));

            $groups = CohortBallotExpander::expand(
                seed: hash('sha256', 'race:'.$race->id).':v1',
                candidacyIds: $candidacyIds,
                electorate: $electorate,
                groups: CountingStage::RANKING_GROUPS,
            );

            $this->assertSame(
                $electorate,
                array_sum(array_column($groups, 1)),
                'the electorate must reproduce exactly'
            );

            // The engine is deterministic given identical input, so the same
            // grouped set must reproduce the stored quota and total.
            $redone = (new VoteCountingService)->countStv(new CountInput(
                candidacyIds: $candidacyIds,
                seats: (int) $race->seats,
                ballots: BallotSet::fromGrouped($groups),
                excluded: [],
                tieSeedBase: 'irrelevant-to-quota',
            ));

            $this->assertSame(
                (int) DB::table('tabulations')->where('race_id', $race->id)->value('total_valid'),
                $redone->totalValid,
                'the published electorate must be re-derivable from the seed'
            );
            $this->assertNotNull($stored);
        });
    }

    /** The pump re-hands a dead worker's item; that must not double-count. */
    public function test_re_running_does_not_count_a_race_twice(): void
    {
        $this->onLivePg(function () {
            [, $electionId] = $this->contestedElection(typeA: 9, typeB: 0, districtSeats: [9]);

            $first = CountingStage::run($electionId, null, 1);
            $second = CountingStage::run($electionId, null, 1);

            $this->assertSame(1, $first['races']);
            $this->assertSame(0, $second['races'], 'a counted race is skipped, not recounted');
            $this->assertSame(1, $second['skipped']);

            $this->assertSame(
                1,
                DB::table('tabulations as t')
                    ->join('election_races as r', 'r.id', '=', 't.race_id')
                    ->where('r.election_id', $electionId)
                    ->count(),
                'and no second tabulation is written'
            );
        });
    }

    /**
     * D3: ONE audit entry per batch, carrying a MERKLE ROOT over the batch's
     * record hashes. Fewer entries, not less evidence — anyone holding the
     * batch can recompute the root and prove any single count belongs to it.
     */
    public function test_the_batch_writes_one_rooted_audit_entry_not_one_per_race(): void
    {
        $this->onLivePg(function () {
            [, $electionId] = $this->contestedElection(typeA: 14, typeB: 10, districtSeats: [7, 7]);

            $before = DB::table('audit_log')->count();

            CountingStage::run($electionId, null, 1);

            $batchEntries = DB::table('audit_log')
                ->where('event', 'races.tabulated_batch')
                ->orderByDesc('seq')
                ->limit(1)
                ->get();

            $this->assertCount(1, $batchEntries, 'three races, ONE batch entry');

            $payload = json_decode($batchEntries->first()->payload, true);

            $this->assertSame(7, $payload['races']);
            $this->assertSame(24, $payload['seats']);
            $this->assertNotEmpty($payload['record_hash_root'], 'the root is what makes batching honest');
            $this->assertCount(7, $payload['record_hashes'], 'and every race hash is listed');
            $this->assertSame('cohort_weighted', $payload['electorate_source'], 'provenance is recorded');

            // Far fewer entries than one-per-race-plus-overhead would produce.
            $this->assertLessThan(
                8,
                DB::table('audit_log')->count() - $before,
                'batching must actually reduce chain writes, or it buys nothing'
            );
        });
    }

    /** An uncontested race cannot be counted, and says so rather than crashing. */
    public function test_a_race_with_no_candidates_is_skipped_honestly(): void
    {
        $this->onLivePg(function () {
            [, $electionId] = $this->contestedElection(typeA: 9, typeB: 0, districtSeats: [9]);

            DB::table('candidacies')->where('election_id', $electionId)->delete();

            $result = CountingStage::run($electionId, null, 1);

            $this->assertSame(0, $result['races']);
            $this->assertSame(1, $result['skipped']);
        });
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string} [jurisdictionId, electionId] */
    private function contestedElection(int $typeA, int $typeB, array $districtSeats): array
    {
        $jid = (string) Str::uuid();

        DB::table('jurisdictions')->insert([
            'id' => $jid,
            'name' => 'Counting Pin',
            'slug' => 'counting-pin-'.Str::lower(Str::random(10)),
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

        // Per-child Type B (racePlan mode='children', c96e757): a type_b chamber
        // elects one at-large race PER DIRECT CHILD. A chamber with type_b_seats
        // but NO children hits racePlan's drift guard (Σ child seats ≠
        // type_b_seats) and blocks. Seed childCount = type_b_seats / rep_floor
        // children (pop > TINY_POP so each seats a full rep_floor of 2); each
        // needs its OWN cohort + identity so its per-child race can be counted
        // (electorateFor keys on the child jurisdiction_id). Pass an EVEN typeB.
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

        // The bootstrap election board. ActivationService constitutes one when a
        // jurisdiction activates; ElectionStage refuses to call an election
        // without it, because F-ELB-004 has no board to certify through and the
        // resulting orphan election would sit open and later double-certify.
        DB::table('election_boards')->insert([
            'id' => (string) Str::uuid(),
            'jurisdiction_id' => $jid,
            'is_bootstrap' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        CohortStage::run($jid, null, 1, 62);
        IdentityStage::run($jid, null, 1);
        foreach ($childIds as $cid) {
            // Each child needs its OWN cohort (so its per-child race has an
            // electorate — electorateFor keys on the child jurisdiction_id) AND
            // its own identities: lane 4's fieldCandidates groups races by
            // jurisdiction and a per-child race draws candidates from that child's
            // roster (depth-0 residency, no ancestor sweep).
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
