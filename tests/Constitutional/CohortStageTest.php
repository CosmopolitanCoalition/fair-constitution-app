<?php

namespace Tests\Constitutional;

use App\Services\Demo\Stages\CohortStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the COHORTS stage.
 *
 * One claimed item = one jurisdiction = one `jurisdiction_cohorts` row holding
 * the PARAMETERS of a population, never expanded people. A jurisdiction of
 * eight million stores one row.
 *
 * THE INVARIANTS:
 *   · deterministic — same jurisdiction + same version = the same people,
 *     forever, on any box. This is what makes `sim:revert` a bounded DELETE
 *     plus a re-derivation instead of a restore-from-backup.
 *   · a VERSION bump regenerates the world, so a generator fix never means
 *     migrating 900k rows.
 *   · idempotent — re-running a claimed item overwrites in place rather than
 *     duplicating, because the pump WILL reclaim and re-hand a unit whose
 *     worker died mid-write.
 *   · a ZERO-POPULATION jurisdiction is a real, rendered case — 34,763 of them
 *     exist because their borders sit off the population raster — and must
 *     produce an honest empty cohort, never an error and never a silent skip.
 *   · WorldPop population is PROVENANCE. It is stored beside the electorate,
 *     never confused with the civic population (App\Support\CivicPopulation).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class CohortStageTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_cohort';

    /** Same seed, same world — the property the whole revert law rests on. */
    public function test_cohort_generation_is_deterministic(): void
    {
        $this->onLivePg(function () {
            $jid = $this->jurisdiction(population: 1_250_000, admLevel: 4);

            $first = CohortStage::run($jid, null, 1, 62);
            $rowA = $this->cohort($jid, 1);

            // Wipe and regenerate — the same inputs must rebuild the same world.
            DB::table('jurisdiction_cohorts')->where('jurisdiction_id', $jid)->delete();

            CohortStage::run($jid, null, 1, 62);
            $rowB = $this->cohort($jid, 1);

            $this->assertSame($rowA->seed, $rowB->seed);
            $this->assertSame($rowA->electorate, $rowB->electorate);
            $this->assertSame(
                $rowA->archetypes,
                $rowB->archetypes,
                'the same seed must rebuild a byte-identical cohort'
            );
            $this->assertGreaterThan(0, $first['electorate']);
        });
    }

    /** A version bump is how the world is regenerated after a generator fix. */
    public function test_a_version_bump_produces_a_different_world(): void
    {
        $this->onLivePg(function () {
            $jid = $this->jurisdiction(population: 900_000, admLevel: 4);

            CohortStage::run($jid, null, 1, 62);
            CohortStage::run($jid, null, 2, 62);

            $v1 = $this->cohort($jid, 1);
            $v2 = $this->cohort($jid, 2);

            $this->assertNotSame($v1->seed, $v2->seed, 'the version is part of the determinism key');
            $this->assertNotSame(
                $v1->archetypes,
                $v2->archetypes,
                'bumping the version must regenerate, not reuse'
            );
            $this->assertSame(
                2,
                DB::table('jurisdiction_cohorts')->where('jurisdiction_id', $jid)->count(),
                'versions coexist — the old world is not destroyed by generating a new one'
            );
        });
    }

    /**
     * The pump reclaims a dead worker's unit and hands it to someone else, so a
     * partially-written item WILL be re-run. That must not duplicate.
     */
    public function test_re_running_a_reclaimed_item_is_idempotent(): void
    {
        $this->onLivePg(function () {
            $jid = $this->jurisdiction(population: 250_000, admLevel: 5);

            CohortStage::run($jid, null, 1, 62);
            CohortStage::run($jid, null, 1, 62);
            CohortStage::run($jid, null, 1, 62);

            $this->assertSame(
                1,
                DB::table('jurisdiction_cohorts')->where('jurisdiction_id', $jid)->where('version', 1)->count(),
                'a re-handed unit must overwrite in place, never duplicate'
            );
        });
    }

    /**
     * 34,763 real jurisdictions store population 0 because their borders sit
     * off the raster. They are an HONEST case, not an error.
     */
    public function test_a_zero_population_jurisdiction_produces_an_honest_empty_cohort(): void
    {
        $this->onLivePg(function () {
            $jid = $this->jurisdiction(population: 0, admLevel: 6);

            $result = CohortStage::run($jid, null, 1, 62);
            $row = $this->cohort($jid, 1);

            $this->assertSame(0, $result['electorate'], 'no people means no electorate');
            $this->assertSame(0, (int) $row->population);

            $archetypes = json_decode($row->archetypes, true);
            $this->assertSame(
                'unpopulated',
                $archetypes['urbanicity'],
                'and it must SAY it is unpopulated rather than be silently skipped'
            );
        });
    }

    /** Turnout shapes the electorate; population stays untouched provenance. */
    public function test_population_is_provenance_and_turnout_sets_the_electorate(): void
    {
        $this->onLivePg(function () {
            $jid = $this->jurisdiction(population: 1_000_000, admLevel: 3);

            CohortStage::run($jid, null, 1, 50);
            $row = $this->cohort($jid, 1);

            $this->assertSame(1_000_000, (int) $row->population, 'WorldPop population is stored unmodified');
            $this->assertSame(500_000, (int) $row->electorate, '50% turnout of a million');
            $this->assertSame(50, (int) $row->turnout_pct);
        });
    }

    /** Locality shapes the persona: language and urbanicity come from the row. */
    public function test_the_cohort_reflects_the_jurisdictions_real_signals(): void
    {
        $this->onLivePg(function () {
            $metro = $this->jurisdiction(population: 5_000_000, admLevel: 2, languages: ['es', 'qu']);
            $hamlet = $this->jurisdiction(population: 400, admLevel: 6, languages: ['ar']);

            CohortStage::run($metro, null, 1, 62);
            CohortStage::run($hamlet, null, 1, 62);

            $m = json_decode($this->cohort($metro, 1)->archetypes, true);
            $h = json_decode($this->cohort($hamlet, 1)->archetypes, true);

            $this->assertSame('metropolis', $m['urbanicity']);
            $this->assertSame('rural', $h['urbanicity']);
            $this->assertSame(['es', 'qu'], $m['languages'], 'languages come from the jurisdiction row');
            $this->assertSame(['ar'], $h['languages']);

            $this->assertGreaterThan(
                count($h['clusters']),
                count($m['clusters']),
                'a plural metropolis carries more preference clusters than a hamlet'
            );

            $this->assertGreaterThan(
                $m['civic']['candidacy_per_thousand'],
                $h['civic']['candidacy_per_thousand'],
                'standing for a village council is commonplace; standing for a city is not'
            );
        });
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    private function jurisdiction(int $population, int $admLevel, array $languages = ['en']): string
    {
        $id = (string) Str::uuid();

        DB::table('jurisdictions')->insert([
            'id' => $id,
            'name' => 'Cohort Pin',
            'slug' => 'cohort-pin-'.Str::lower(Str::random(10)),
            'adm_level' => $admLevel,
            'population' => $population,
            'source' => 'user_defined',
            'official_languages' => json_encode($languages),
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function cohort(string $jid, int $version): object
    {
        return DB::table('jurisdiction_cohorts')
            ->where('jurisdiction_id', $jid)
            ->where('version', $version)
            ->firstOrFail();
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
