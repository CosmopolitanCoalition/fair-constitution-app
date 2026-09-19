<?php

namespace Tests\Unit;

use App\Jobs\SimWorkerJob;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SimWorkerHeartbeatTest extends TestCase
{
    private SimWorkerJob $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->travelTo(now()->setMicrosecond(100_000));
        Schema::create('sim_worker_leases', function ($table) {
            $table->string('id')->primary();
            $table->timestamp('last_seen_at');
            $table->string('activity')->nullable();
        });
        DB::table('sim_worker_leases')->insert([
            'id' => 'lane', 'last_seen_at' => now()->subMinutes(3),
        ]);
        $this->worker = new SimWorkerJob('run');
        DB::enableQueryLog();
        DB::flushQueryLog();
    }

    private function beat(string $token = 'lane'): void
    {
        (new \ReflectionMethod(SimWorkerJob::class, 'touch'))->invoke($this->worker, $token);
    }

    private function activity(string $activity): void
    {
        (new \ReflectionMethod(SimWorkerJob::class, 'updateLease'))
            ->invoke($this->worker, 'lane', ['activity' => $activity]);
    }

    private function writes(): int
    {
        return count(array_filter(DB::getQueryLog(), fn ($query) =>
            str_starts_with($query['query'], 'update "sim_worker_leases"')));
    }

    private function assertFresh(string $token = 'lane'): void
    {
        $this->assertSame(DB::connection()->prepareBindings([now()])[0],
            DB::table('sim_worker_leases')->where('id', $token)->value('last_seen_at'));
    }

    public function test_burst_writes_once_per_actual_bound_timestamp(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->beat();
            $this->travel(100)->milliseconds();
        }
        $this->assertSame(1, $this->writes());
        $this->assertFresh();
        $this->travel(100)->milliseconds(); // next stored second
        $this->beat();
        $this->assertSame(2, $this->writes());
        $this->assertFresh();
    }

    public function test_activity_changes_write_immediately_and_already_supply_the_heartbeat(): void
    {
        $this->activity('acquiring');
        $this->beat();
        $this->activity('executing');
        $this->beat();
        $this->activity('waiting');
        $this->assertSame(3, $this->writes());
        $this->assertSame('waiting', DB::table('sim_worker_leases')->value('activity'));
        $this->assertFresh();
    }

    public function test_long_items_and_wall_clock_changes_keep_the_existing_heartbeat_values(): void
    {
        $this->activity('executing');
        foreach ([1, 30, 60, 90, 119, -1] as $seconds) {
            $this->travel($seconds)->seconds();
            $this->beat();
            $this->assertFresh();
        }
        $this->assertSame(7, $this->writes());
    }

    public function test_rolled_back_heartbeat_does_not_suppress_the_next_write(): void
    {
        $this->beat();
        $original = DB::table('sim_worker_leases')->value('last_seen_at');
        $this->travel(1)->seconds();
        DB::beginTransaction();
        $this->beat();
        DB::rollBack();
        $this->assertSame($original, DB::table('sim_worker_leases')->value('last_seen_at'));
        $this->beat();
        $this->assertSame(3, $this->writes());
        $this->assertFresh();
    }

    public function test_a_different_or_missing_lease_does_not_reuse_the_first_lease_timestamp(): void
    {
        $this->beat();
        $this->beat('other'); // absent: zero affected rows must not prime the cache
        DB::table('sim_worker_leases')->insert([
            'id' => 'other', 'last_seen_at' => now()->subMinutes(3),
        ]);
        $this->beat('other');
        $this->assertSame(3, $this->writes());
        $this->assertFresh('other');
    }

    public function test_failed_write_is_retried_at_the_same_timestamp(): void
    {
        DB::statement("CREATE TRIGGER refuse_heartbeat BEFORE UPDATE ON sim_worker_leases
            BEGIN SELECT RAISE(ABORT, 'fixture write failure'); END");
        try {
            $this->beat();
            $this->fail('Fixture must reject the first write.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('fixture write failure', $e->getMessage());
        }
        DB::statement('DROP TRIGGER refuse_heartbeat');
        $this->beat();
        $this->assertFresh();
    }
}
