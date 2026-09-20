<?php

namespace Tests\Feature;

use App\Models\{JudicialSeat, Judiciary, Legislature, LegislatureMember, User};
use App\Services\Demo\Stages\JudiciaryStage;
use App\Services\Judiciary\JudicialSeatService;
use App\Support\SimTimer;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Bounded Phase 8 reads and selection behavior, in a guarded nonce database. */
class Phase8PerformanceTest extends TestCase
{
    private ?string $fixture = null;
    private string $original;
    private string|false $oldChunk;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_SIM_INDEX_PG_TESTS') !== '1') {
            $this->markTestSkipped('Opt in to the disposable Phase 8 database.');
        }
        $this->original = DB::getDefaultConnection();
        $this->oldChunk = getenv('CGA_SWEEP_CHUNK');
        config(['database.connections.phase8_admin' => array_replace(config('database.connections.pgsql'),
            ['database' => 'postgres', 'name' => 'phase8_admin', 'url' => null])]);
        $admin = DB::connection('phase8_admin');
        $this->assertSame('postgres', $admin->selectOne('SELECT current_database() AS name')->name);
        $this->fixture = 'phase8_test_'.bin2hex(random_bytes(8));
        $admin->statement('CREATE DATABASE '.$this->fixture.' TEMPLATE template0');
        config(['database.connections.phase8_test' => array_replace($admin->getConfig(),
            ['database' => $this->fixture, 'name' => 'phase8_test', 'url' => null])]);
        DB::setDefaultConnection('phase8_test');
        $this->assertSame($this->fixture, DB::selectOne('SELECT current_database() AS name')->name);
        foreach ([new JudicialSeat, new Judiciary, new Legislature, new LegislatureMember, new User] as $model) {
            $this->assertSame('phase8_test', $model->getConnection()->getName());
            $this->assertSame($this->fixture, $model->getConnection()->selectOne('SELECT current_database() AS name')->name);
        }
        DB::statement("SET lock_timeout = '3s'");
        DB::statement("SET statement_timeout = '20s'");
        DB::statement('CREATE TABLE judicial_seats (id uuid PRIMARY KEY, judiciary_id uuid, seat_number integer, seat_class text, nominating_jurisdiction_id uuid, user_id uuid, status text, deleted_at timestamptz)');
        DB::statement('CREATE INDEX judicial_seats_judiciary_id_index ON judicial_seats(judiciary_id)');
        DB::statement('CREATE INDEX judicial_seats_user_id_index ON judicial_seats(user_id)');
        DB::statement('CREATE TABLE residency_confirmations (user_id uuid, jurisdiction_id uuid, is_active boolean)');
        DB::statement('CREATE INDEX residency_active_jurisdiction_user_idx ON residency_confirmations(jurisdiction_id, user_id) WHERE is_active');
        DB::statement('CREATE TABLE judiciaries (id uuid PRIMARY KEY, jurisdiction_id uuid, status text, deleted_at timestamptz)');
        DB::statement('CREATE TABLE legislatures (id uuid PRIMARY KEY, jurisdiction_id uuid, type_b_seats integer, deleted_at timestamptz)');
        DB::statement('CREATE TABLE legislature_members (id uuid PRIMARY KEY, legislature_id uuid, user_id uuid, status text, seat_type text, vacated_at timestamptz, deleted_at timestamptz)');
        DB::statement('CREATE TABLE users (id uuid PRIMARY KEY, name text, deleted_at timestamptz)');
        $this->resetTimers();
        putenv('CGA_SWEEP_CHUNK=1000');
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            putenv($this->oldChunk === false ? 'CGA_SWEEP_CHUNK' : 'CGA_SWEEP_CHUNK='.$this->oldChunk);
            $this->resetTimers();
            while (DB::transactionLevel() > 0) { DB::rollBack(); }
            DB::setDefaultConnection($this->original);
            DB::purge('phase8_test');
            if (! preg_match('/^phase8_test_[a-f0-9]{16}$/D', $this->fixture)) { throw new \LogicException('Unexpected fixture database.'); }
            DB::connection('phase8_admin')->statement('DROP DATABASE '.$this->fixture.' WITH (FORCE)');
            DB::purge('phase8_admin');
        }
        parent::tearDown();
    }

    private function resetTimers(): void
    {
        foreach (['us', 'n', 'max', 'open'] as $property) {
            (new \ReflectionProperty(SimTimer::class, $property))->setValue(null, []);
        }
    }

    private function id(int $number): string { return sprintf('88000000-0000-4000-8000-%012d', $number); }

    private function pools(array $needs, ?\Closure $beat = null): array
    {
        return (new \ReflectionMethod(JudiciaryStage::class, 'residentPools'))->invoke(null, $needs, $this->id(1), $beat);
    }

    private function resident(int $pool, int $user, bool $active = true): void
    {
        DB::table('residency_confirmations')->insert(['jurisdiction_id' => $this->id($pool), 'user_id' => $this->id($user), 'is_active' => $active]);
    }

    private function seat(int $number, int $pool, ?int $user = null, string $status = 'vacant', ?string $deleted = null, int $court = 1): void
    {
        DB::table('judicial_seats')->insert(['id' => $this->id(1000 + $number), 'judiciary_id' => $this->id($court),
            'seat_number' => $number, 'seat_class' => JudicialSeat::CLASS_CONSTITUENT_NOMINATED,
            'nominating_jurisdiction_id' => $this->id($pool), 'user_id' => $user === null ? null : $this->id($user),
            'status' => $status, 'deleted_at' => $deleted]);
    }

    public function test_pool_batches_match_previous_distinct_order_limits_and_bench_exclusion(): void
    {
        foreach ([[10, 100], [10, 100], [10, 101], [10, 102], [10, 103], [11, 100], [11, 102], [11, 104]] as [$pool, $user]) {
            $this->resident($pool, $user);
        }
        $this->resident(10, 99, false);
        $this->seat(1, 10, 101, 'seated');
        $this->seat(2, 10, 102, 'seated', '2026-09-20'); // Deleted seats do not reserve a resident.
        $this->seat(3, 11, 104, 'seated', null, 2); // Another court does not reserve one either.
        $needs = [$this->id(11) => 2, $this->id(10) => 2, $this->id(12) => 3];
        $taken = JudicialSeat::query()->where('judiciary_id', $this->id(1))->whereNotNull('user_id')->pluck('user_id')->all();
        $previous = [];
        foreach ($needs as $pool => $need) {
            $previous[$pool] = DB::table('residency_confirmations')->where('jurisdiction_id', $pool)->where('is_active', true)
                ->whereNotIn('user_id', $taken)->distinct()->orderBy('user_id')->limit($need)->pluck('user_id')->all();
        }
        putenv('CGA_SWEEP_CHUNK=2');
        $beats = 0;
        DB::enableQueryLog(); DB::flushQueryLog();
        $actual = $this->pools($needs, function () use (&$beats) { $beats++; });
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertSame($previous, $actual);
        $this->assertSame([$this->id(100), $this->id(102)], $actual[$this->id(10)]);
        $this->assertSame([], $actual[$this->id(12)]);
        $this->assertCount(2, $queries);
        $this->assertSame(2, $beats);
        foreach ($queries as $query) {
            $this->assertLessThanOrEqual(2, count(json_decode($query['bindings'][0], true)));
            $this->assertCount(2, $query['bindings']);
        }
    }

    public function test_empty_pools_need_no_query_and_single_pool_keeps_the_same_limit(): void
    {
        $this->resident(10, 100); $this->resident(10, 101);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertSame([], $this->pools([]));
        $this->assertSame([], DB::getQueryLog());
        $this->assertSame([$this->id(10) => [$this->id(100)]], $this->pools([$this->id(10) => 1]));
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_stage_keeps_cross_pool_duplicate_and_failed_nomination_deferrals(): void
    {
        DB::table('legislatures')->insert(['id' => $this->id(2), 'jurisdiction_id' => $this->id(3), 'type_b_seats' => 0]);
        DB::table('legislature_members')->insert(['id' => $this->id(4), 'legislature_id' => $this->id(2), 'user_id' => $this->id(5), 'status' => 'elected', 'seat_type' => 'A']);
        DB::table('users')->insert(['id' => $this->id(5), 'name' => 'Fixture proposer']);
        DB::table('judiciaries')->insert(['id' => $this->id(1), 'jurisdiction_id' => $this->id(3), 'status' => 'creating']);
        foreach ([10, 11, 10, 12, 11] as $index => $pool) { $this->seat($index + 1, $pool); }
        foreach ([[10, 100], [10, 101], [11, 100], [11, 102]] as [$pool, $user]) { $this->resident($pool, $user); }
        $attempts = [];
        $service = $this->createMock(JudicialSeatService::class);
        $service->expects($this->exactly(3))->method('stageSlateNomination')
            ->willReturnCallback(function ($seat, $user, $mode, $pool) use (&$attempts) {
                $attempts[] = [(int) $seat->seat_number, $user, $mode, $pool];
                if ($user === $this->id(102)) { throw new \RuntimeException('Fixture refusal'); }
                return [];
            });
        $service->expects($this->never())->method('openSlateConsent');
        $this->app->instance(JudicialSeatService::class, $service);
        $result = JudiciaryStage::run($this->id(3), null, 1);
        $this->assertSame([
            [1, $this->id(100), 'constituent', $this->id(10)],
            [2, $this->id(102), 'constituent', $this->id(11)],
            [3, $this->id(101), 'constituent', $this->id(10)],
        ], $attempts);
        $this->assertSame('3 seat(s) deferred', $result['skipped']);
        $this->assertSame(5, $result['seats_total']);
        $this->assertSame(0, $result['seats_seated']);
        $timers = (new \ReflectionProperty(SimTimer::class, 'n'))->getValue();
        $this->assertSame(1, $timers['judiciary.resident_pools']);
        $this->assertSame(1, $timers['judiciary.stage_nominations']);
    }

    public function test_summary_counts_one_court_and_excludes_deleted_seats_in_one_query(): void
    {
        $this->seat(1, 10, 100, 'seated'); $this->seat(2, 10, 101, 'nominated');
        $this->seat(3, 10, 102, 'seated', '2026-09-20'); $this->seat(4, 10, 103, 'seated', null, 2);
        $court = (new Judiciary)->forceFill(['id' => $this->id(1), 'status' => 'creating']);
        DB::enableQueryLog(); DB::flushQueryLog();
        $result = (new \ReflectionMethod(JudiciaryStage::class, 'done'))->invoke(null, $court, false, null);
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertSame(2, $result['seats_total']); $this->assertSame(1, $result['seats_seated']);
        $this->assertCount(1, $queries);
        $court->id = $this->id(999);
        $empty = (new \ReflectionMethod(JudiciaryStage::class, 'done'))->invoke(null, $court, false, null);
        $this->assertSame(0, $empty['seats_total']); $this->assertSame(0, $empty['seats_seated']);
    }

    public function test_large_bench_uses_bounded_resident_probes_and_hashes_exclusion_once(): void
    {
        // 120 independent pools, each with 400 residents; only two nominees per pool.
        DB::statement("INSERT INTO residency_confirmations SELECT ('88000000-0000-4000-8000-' || lpad((p * 1000 + r)::text, 12, '0'))::uuid,
            ('88000000-0000-4000-8000-' || lpad(p::text, 12, '0'))::uuid, true FROM generate_series(10,129) p CROSS JOIN generate_series(1,400) r");
        $needs = [];
        for ($pool = 10; $pool < 130; $pool++) {
            $needs[$this->id($pool)] = 2;
            $this->seat($pool, $pool, $pool * 1000 + 1, 'seated');
        }
        DB::statement('VACUUM ANALYZE residency_confirmations');
        DB::statement('ANALYZE judicial_seats');
        DB::enableQueryLog(); DB::flushQueryLog();
        $actual = $this->pools($needs);
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertCount(1, $queries); // Previously 120 roster queries plus the taken-seat read.
        foreach (range(10, 129) as $pool) {
            $this->assertSame([$this->id($pool * 1000 + 2), $this->id($pool * 1000 + 3)], $actual[$this->id($pool)]);
        }
        $result = DB::selectOne('EXPLAIN (ANALYZE, FORMAT JSON) '.$queries[0]['query'], $queries[0]['bindings']);
        $plan = json_decode($result->{'QUERY PLAN'}, true)[0]['Plan'];
        $nodes = [];
        $walk = function (array $node) use (&$walk, &$nodes) {
            $nodes[] = $node;
            foreach ($node['Plans'] ?? [] as $child) { $walk($child); }
        };
        $walk($plan);
        $resident = array_values(array_filter($nodes, fn ($node) => ($node['Relation Name'] ?? '') === 'residency_confirmations'));
        $taken = array_values(array_filter($nodes, fn ($node) => ($node['Relation Name'] ?? '') === 'judicial_seats'));
        $this->assertCount(1, $resident); $this->assertCount(1, $taken);
        $this->assertSame('residency_active_jurisdiction_user_idx', $resident[0]['Index Name']);
        $this->assertSame(120, $resident[0]['Actual Loops']);
        $this->assertLessThanOrEqual(3, $resident[0]['Actual Rows'] + $resident[0]['Rows Removed by Filter']);
        $this->assertSame(1, $taken[0]['Actual Loops'], 'The bench is read once, not per candidate/pool.');
    }
}
