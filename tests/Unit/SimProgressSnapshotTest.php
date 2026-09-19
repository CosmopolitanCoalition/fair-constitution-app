<?php

namespace Tests\Unit;

use App\Models\SimRun;
use App\Services\Demo\SimSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SimProgressSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Cache::flush();
        $this->freezeTime();
        Schema::create('sim_items', function ($t) {
            $t->string('run_id');
            $t->string('kind');
            $t->integer('adm_level')->nullable();
            $t->string('status');
            $t->timestamp('finished_at')->nullable();
        });
        foreach ([
            ['r', 'cohort_scope', 0, 'done', now()->subHour()],
            ['r', 'election_scope', 4, 'done', now()->subMinute()],
            ['r', 'election_scope', 4, 'running', null],
            ['r', 'election_scope', 4, 'pending', null],
            ['r', 'election_scope', 6, 'review', null],
            ['r', 'election_scope', 6, 'failed', null],
            ['r', 'election_scope', null, 'done', now()->subSeconds(600)],
            ['r', 'future_kind', 1, 'pending', null],
            ['other', 'election_scope', 4, 'done', now()],
        ] as [$run, $kind, $level, $status, $finished]) {
            DB::table('sim_items')->insert([
                'run_id' => $run, 'kind' => $kind, 'adm_level' => $level,
                'status' => $status, 'finished_at' => $finished,
            ]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
    }

    private function runModel(string $phase = 'elections', string $status = 'running', string $id = 'r'): SimRun
    {
        return (new SimRun)->forceFill(['id' => $id, 'phase' => $phase, 'status' => $status]);
    }

    public function test_one_aggregate_supplies_consistent_totals_stages_and_current_layers(): void
    {
        $out = (new SimSnapshot)->progress($this->runModel());
        $this->assertSame(['total' => 8, 'done' => 3, 'running' => 1, 'pending' => 2, 'review' => 2], $out['ledger']);
        $this->assertSame(['cohort_scope', 'election_scope'], array_column($out['stages'], 'kind'));
        $this->assertSame(6, $out['stages'][1]['total']);
        $this->assertTrue($out['stages'][1]['is_current']);
        $this->assertSame([4, 6, 99], array_column($out['layers'], 'adm_level'));
        $this->assertSame([3, 2, 1], array_column($out['layers'], 'total'));
        $this->assertSame([0, 2, 0], array_column($out['layers'], 'review'));
        $this->assertSame(6, $out['rate']['rate_per_h'], 'only the completion inside the window counts');
        $this->assertSame(now()->toIso8601String(), $out['snapshot_at']);
        $this->assertCount(2, DB::getQueryLog(), 'one grouped aggregate plus one indexed rate query');
    }

    public function test_separate_readers_and_all_public_accessors_share_the_sample(): void
    {
        $run = $this->runModel();
        $out = (new SimSnapshot)->progress($run);
        DB::flushQueryLog();
        $other = new SimSnapshot;
        $this->assertSame($out['stages'], $other->stages($run));
        $this->assertSame($out['layers'], $other->layers($run));
        $this->assertSame($out['ledger'], $other->ledger($run));
        $this->assertSame($out['rate'], $other->windowedRate($run));
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_expiry_refreshes_counts_and_timestamp(): void
    {
        $run = $this->runModel();
        $first = (new SimSnapshot)->progress($run);
        DB::table('sim_items')->where('run_id', 'r')->where('status', 'running')
            ->update(['status' => 'done', 'finished_at' => now()]);
        $this->assertSame($first, (new SimSnapshot)->progress($run));
        $this->travel(SimSnapshot::PROGRESS_TTL)->seconds();
        DB::flushQueryLog();
        $next = (new SimSnapshot)->progress($run);
        $this->assertSame(4, $next['ledger']['done']);
        $this->assertNotSame($first['snapshot_at'], $next['snapshot_at']);
        $this->assertCount(2, DB::getQueryLog());
    }

    public function test_run_phase_and_status_changes_do_not_reuse_old_counts(): void
    {
        $snap = new SimSnapshot;
        $snap->progress($this->runModel());
        $cohorts = $snap->progress($this->runModel('cohorts'));
        $this->assertSame([0], array_column($cohorts['layers'], 'adm_level'));
        $halted = $snap->progress($this->runModel('elections', 'halted'));
        $this->assertNull($halted['rate']['rate_per_h']);
        $other = $snap->progress($this->runModel(id: 'other'));
        $this->assertSame(1, $other['ledger']['total']);
    }

    public function test_a_concurrent_cold_reader_returns_computing_without_a_scan(): void
    {
        $lock = Cache::lock('sim:progress:v1:r:elections:running:refresh', 600);
        $lock->get();
        try {
            $out = (new SimSnapshot)->progress($this->runModel());
            $this->assertSame('computing', $out['snapshot_state']);
            $this->assertNull($out['snapshot_at']);
            $this->assertSame([], DB::getQueryLog());
        } finally {
            $lock->release();
        }
    }

    public function test_a_concurrent_refresh_serves_last_known_counts_with_the_original_stamp(): void
    {
        $first = (new SimSnapshot)->progress($this->runModel());
        $this->travel(SimSnapshot::PROGRESS_TTL)->seconds();
        $lock = Cache::lock('sim:progress:v1:r:elections:running:refresh', 600);
        $lock->get();
        DB::flushQueryLog();
        try {
            $out = (new SimSnapshot)->progress($this->runModel());
            $this->assertSame($first['ledger'], $out['ledger']);
            $this->assertSame($first['snapshot_at'], $out['snapshot_at']);
            $this->assertTrue($out['snapshot_stale']);
            $this->assertSame([], DB::getQueryLog());
        } finally {
            $lock->release();
        }
    }

    public function test_an_empty_run_is_a_measured_zero_and_has_no_rate(): void
    {
        $out = (new SimSnapshot)->progress($this->runModel(id: 'empty'));
        $this->assertSame('ready', $out['snapshot_state']);
        $this->assertSame(0, $out['ledger']['total']);
        $this->assertSame([], $out['stages']);
        $this->assertNull($out['rate']['rate_per_h']);
    }

    public function test_a_failed_refresh_releases_its_lock(): void
    {
        Schema::drop('sim_items'); // isolated SQLite fixture only
        try {
            (new SimSnapshot)->progress($this->runModel());
            $this->fail('The absent fixture must cause a query failure.');
        } catch (\Illuminate\Database\QueryException) {
            $lock = Cache::lock('sim:progress:v1:r:elections:running:refresh', 600);
            $this->assertTrue($lock->get());
            $lock->release();
        }
    }
}
