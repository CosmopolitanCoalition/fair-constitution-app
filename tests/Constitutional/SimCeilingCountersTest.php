<?php

namespace Tests\Constitutional;

use App\Jobs\SimWorkerJob;
use App\Models\SimRun;
use App\Services\Demo\SimSnapshot;
use App\Services\Demo\Stages\IdentityStage;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * THE LAWFUL-INACTIVE COUNTERS (operator ruling 2026-09-19, rubric
 * sim-roster-vs-real-population = C, "ceiling at real population").
 *
 * A place with zero population, and a place with fewer real residents than its
 * election needs, close DONE with no election. They are COUNTED on the run (the
 * O(1) world-counter idiom) and SERVED to the Step 5 page, so the operator sees
 * how many places the ceiling closed. A counted result, never an error.
 *
 * Live PostgreSQL, always rolled back.
 */
class SimCeilingCountersTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_simceiling';

    public function test_the_worker_counts_both_kinds_of_inactive_place(): void
    {
        $this->onLivePg(function () {
            $run = SimRun::create(['status' => 'running', 'phase' => 'identities', 'options' => [], 'phase_timings' => []]);

            $this->settle($run, 'identity_batch', ['users' => 0, 'confirmations' => 0, 'inactive' => IdentityStage::INACTIVE_ZERO_POPULATION]);
            $this->settle($run, 'identity_batch', ['users' => 0, 'confirmations' => 0, 'inactive' => IdentityStage::INACTIVE_ZERO_POPULATION]);
            $this->settle($run, 'identity_batch', ['users' => 4, 'confirmations' => 20, 'population' => 4, 'wanted' => 12, 'ceiling_held' => 8]);
            $this->settle($run, 'election_scope', ['election_id' => null, 'inactive' => IdentityStage::INACTIVE_TOO_FEW_RESIDENTS]);
            $this->settle($run, 'election_scope', ['election_id' => 'e-1', 'candidacies' => 6]);

            $row = DB::table('sim_runs')->where('id', $run->id)->first();
            $this->assertSame(2, (int) $row->places_zero_population);
            $this->assertSame(1, (int) $row->places_too_few_residents);
            $this->assertSame(4, (int) $row->people_founded, 'the capped place still counts the four it minted');
        });
    }

    public function test_the_snapshot_serves_both_counts_to_the_step_5_page(): void
    {
        $this->onLivePg(function () {
            DB::table('sim_runs')->whereIn('status', ['queued', 'running', 'halted'])->update(['status' => 'done']);
            $run = SimRun::create(['status' => 'running', 'phase' => 'elections', 'options' => [], 'phase_timings' => []]);
            DB::table('sim_runs')->where('id', $run->id)->update([
                'places_zero_population' => 17, 'places_too_few_residents' => 8,
            ]);

            $world = app(SimSnapshot::class)->world(fresh: true);

            $this->assertSame(17, $world['places_zero_population']);
            $this->assertSame(8, $world['places_too_few_residents']);
        });
    }

    /** Drive the worker's own counter hook, exactly as a DONE settle does. */
    private function settle(SimRun $run, string $kind, array $metrics): void
    {
        $job = new SimWorkerJob((string) $run->id);
        $hook = new \ReflectionMethod($job, 'maintainWorldCounters');
        $hook->setAccessible(true);
        $hook->invoke($job, $run, $kind, $metrics);
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
            \Illuminate\Support\Facades\Cache::forget(SimSnapshot::WORLD_KEY);
        }
    }
}
