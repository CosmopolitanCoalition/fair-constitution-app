<?php

namespace Tests\Unit;

use App\Models\SimRun;
use App\Services\Demo\SimSnapshot;
use App\Services\Demo\SimTimingSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SimWorkerReportingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Cache::flush();
        $this->freezeTime();
        Schema::create('sim_timings', function ($t) {
            $t->string('run_id'); $t->string('part');
            $t->bigInteger('count'); $t->bigInteger('total_us'); $t->bigInteger('max_us');
        });
        Schema::create('sim_worker_leases', function ($t) {
            $t->string('id'); $t->string('run_id'); $t->string('lane')->nullable();
            $t->string('claim_type')->nullable(); $t->string('claim_label')->nullable();
            $t->timestamp('claim_started_at')->nullable();
            $t->timestamp('started_at'); $t->timestamp('last_seen_at');
        });
    }

    private function runModel(string $phase = 'elections', string $status = 'running', string $id = 'r'): SimRun
    {
        return (new SimRun)->forceFill(['id' => $id, 'phase' => $phase, 'status' => $status]);
    }

    private function counter(int $count, int $us, string $part = 'lane.claim_next', string $run = 'r'): void
    {
        DB::table('sim_timings')->updateOrInsert(['run_id' => $run, 'part' => $part],
            ['count' => $count, 'total_us' => $us, 'max_us' => 2_000_000]);
    }

    public function test_recent_average_uses_deltas_and_preserves_cumulative_values(): void
    {
        $this->counter(1000, 2_000_000_000);
        $first = (new SimSnapshot)->timings($this->runModel());
        $this->assertNull($first[0]['recent_avg_ms']);
        $this->assertNull($first[0]['window_seconds']);
        $this->counter(1100, 2_002_000_000); // 100 newer operations at 20 ms each
        $this->assertSame($first, (new SimSnapshot)->timings($this->runModel()));
        $this->travel(10)->seconds();
        $row = (new SimSnapshot)->timings($this->runModel())[0];
        $this->assertSame(20.0, $row['recent_avg_ms']);
        $this->assertSame(100, $row['recent_count']);
        $this->assertSame(10, $row['window_seconds']);
        $this->assertGreaterThan(1800, $row['avg_ms']);
        $this->assertSame(now()->toIso8601String(), $row['sampled_at']);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertSame([$row], (new SimTimingSnapshot)->rows($this->runModel()));
        $this->assertSame([], DB::getQueryLog(), 'another viewer shares the sample');
    }

    public function test_the_rolling_window_drops_old_samples_and_resets_after_an_observation_gap(): void
    {
        $this->counter(0, 0);
        $reader = new SimTimingSnapshot;
        $reader->rows($this->runModel());
        for ($i = 1; $i <= 8; $i++) {
            $this->travel(10)->seconds();
            $this->counter($i * 10, $i * 100_000);
            $row = $reader->rows($this->runModel())[0];
        }
        $this->assertSame(60, $row['window_seconds']);
        $this->assertSame(60, $row['recent_count']);
        $this->assertSame(10.0, $row['recent_avg_ms']);
        $this->travel(61)->seconds();
        $this->assertNull($reader->rows($this->runModel())[0]['recent_avg_ms']);
    }

    public function test_no_new_work_and_reset_counters_do_not_invent_a_recent_average(): void
    {
        $this->counter(100, 100_000);
        $reader = new SimTimingSnapshot;
        $reader->rows($this->runModel());
        $this->travel(10)->seconds();
        $row = $reader->rows($this->runModel())[0];
        $this->assertSame(0, $row['recent_count']);
        $this->assertNull($row['recent_avg_ms']);
        $this->counter(1, 1000);
        $this->travel(10)->seconds();
        $this->assertNull($reader->rows($this->runModel())[0]['recent_count']);
    }

    public function test_new_parts_are_measured_from_zero_but_new_phases_statuses_and_runs_start_a_baseline(): void
    {
        $reader = new SimTimingSnapshot;
        $this->counter(10, 10_000);
        $reader->rows($this->runModel());
        $this->travel(10)->seconds();
        $this->counter(2, 40_000, 'stage.election_scope');
        $rows = collect($reader->rows($this->runModel()))->keyBy('part');
        $this->assertSame(20.0, $rows['stage.election_scope']['recent_avg_ms']);
        foreach ([$this->runModel('counting'), $this->runModel(status: 'halted')] as $run) {
            $this->assertNull($reader->rows($run)[0]['recent_count']);
        }
        $this->assertSame([], $reader->rows($this->runModel(id: 'other')));
    }

    public function test_a_concurrent_timing_reader_reuses_the_last_sample_without_querying(): void
    {
        $this->counter(100, 100_000);
        $reader = new SimTimingSnapshot;
        $first = $reader->rows($this->runModel());
        $this->travel(10)->seconds();
        $lock = Cache::lock('sim:timings:v1:r:elections:running:refresh', 60);
        $lock->get();
        DB::enableQueryLog(); DB::flushQueryLog();
        try {
            $this->assertSame($first, $reader->rows($this->runModel()));
            $this->assertSame([], DB::getQueryLog());
        } finally {
            $lock->release();
        }
    }

    private function lease(string $id, array $extra = []): void
    {
        DB::table('sim_worker_leases')->insert($extra + [
            'id' => $id, 'run_id' => 'r', 'started_at' => now()->subMinute(),
            'last_seen_at' => now(),
        ]);
    }

    public function test_older_workers_and_an_unmigrated_table_are_not_mislabelled_idle(): void
    {
        $this->lease('legacy-empty');
        $this->lease('legacy-busy', ['claim_type' => 'election_scope', 'claim_started_at' => now()->subSeconds(4)]);
        $rows = collect((new SimSnapshot)->lanes($this->runModel()))->keyBy('activity');
        $this->assertNull($rows['unknown']['activity_secs']);
        $this->assertSame(4, $rows['executing']['activity_secs']);
    }

    public function test_migrated_worker_states_and_elapsed_times_survive_the_reader(): void
    {
        $migration = require base_path('database/migrations/2026_09_19_201000_sim_worker_activity.php');
        $migration->up(); $migration->up();
        foreach (['acquiring', 'executing', 'waiting'] as $activity) {
            $this->lease($activity, ['activity' => $activity, 'activity_started_at' => now()->subSeconds(3)]);
        }
        $this->lease('stale', ['last_seen_at' => now()->subMinutes(3)]);
        $rows = (new SimSnapshot)->lanes($this->runModel());
        $this->assertCount(3, $rows);
        $this->assertEqualsCanonicalizing(['acquiring', 'executing', 'waiting'], array_column($rows, 'activity'));
        $this->assertSame([3, 3, 3], array_column($rows, 'activity_secs'));
        $migration->down();
        $this->assertFalse(Schema::hasColumn('sim_worker_leases', 'activity'));
    }
}
