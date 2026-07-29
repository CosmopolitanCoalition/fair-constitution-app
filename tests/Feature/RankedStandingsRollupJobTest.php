<?php

namespace Tests\Feature;

use App\Domain\Ballots\BallotBox;
use App\Jobs\Elections\RankedStandingsRollupJob;
use App\Models\Election;
use App\Models\ElectionRace;
use App\Services\AuditService;
use App\Services\Elections\RankedProjectionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * INTEGRATION PIN — the ranked liveAggregate seam (W4 ⑥): the daily
 * RankedStandingsRollupJob decrypts a ranked_open race OUT OF BAND, caches a
 * first-preference projection the ballot page reads, and — the secrecy-adjacent
 * invariant — writes NO `tabulations` row (so it never trips TabulateRaceJob's
 * idempotency gate and never flips the race to TABULATING). BallotBox is mocked
 * so no ballot crypto is exercised here; the pure tally is pinned separately in
 * RankedLiveAggregateTest.
 *
 * Live-pg + rolled-back tx (elections/election_races need the pg schema).
 */
class RankedStandingsRollupJobTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_ranked_rollup';

    public function test_the_rollup_caches_a_projection_and_finalizes_nothing(): void
    {
        $this->onLivePg(function () {
            $jurId = DB::table('jurisdictions')->whereNull('deleted_at')->value('id');
            if ($jurId === null) {
                $this->markTestSkipped('Live DB has no jurisdiction.');
            }

            $election = Election::create([
                'id'              => (string) Str::uuid(),
                'jurisdiction_id' => (string) $jurId,
                'status'          => Election::STATUS_RANKED_OPEN,
            ]);

            $race = ElectionRace::create([
                'id'              => (string) Str::uuid(),
                'election_id'     => (string) $election->id,
                'jurisdiction_id' => (string) $jurId,
                'seat_kind'       => 'type_a',
                'seats'           => 2,
                'finalist_count'  => 0,
            ]);

            // Mock the OUT-OF-BAND decrypt — the job's only path to rankings.
            // Three valid ballots; first preferences a,a,b → {a:2, b:1}.
            $this->mock(BallotBox::class, function ($m) {
                // decryptForCount is typed Generator — return one (a closure with
                // yield IS a generator function).
                $m->shouldReceive('decryptForCount')->andReturnUsing(function () {
                    yield ['a', 'b'];
                    yield ['a'];
                    yield ['b', 'a'];
                });
            });

            (new RankedStandingsRollupJob((string) $election->id))
                ->handle(app(RankedProjectionService::class), app(AuditService::class));

            // The projection is cached for the race, first preferences + Droop.
            $agg = Cache::get(RankedStandingsRollupJob::CACHE_PREFIX.$race->id);
            $this->assertIsArray($agg, 'the rollup caches an aggregate for the race');
            $this->assertSame(3, $agg['valid']);
            $this->assertSame(2, $agg['quota']);                  // floor(3/(2+1)) + 1
            $this->assertSame(['a' => 2, 'b' => 1], $agg['first_prefs']);

            // THE INVARIANT: the projection finalizes NOTHING — no tabulations
            // row, so TabulateRaceJob's terminal-initial gate is never tripped.
            $this->assertSame(0, DB::table('tabulations')->where('race_id', (string) $race->id)->count(),
                'a projection must never write a tabulations row');

            // Audit parity — one counts-only chain entry for the rollup.
            $this->assertSame(1, DB::table('audit_log')
                ->where('module', 'elections')->where('event', 'ranked_standings.rolled')->count(),
                'the rollup appends exactly one counts-only chain entry');
        });
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
