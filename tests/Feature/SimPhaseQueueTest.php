<?php

namespace Tests\Feature;

use App\Console\Commands\SimPumpCommand;
use App\Models\SimRun;
use App\Services\AuditService;
use App\Support\SimTimer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Tests\TestCase;

/** Disposable database: advisory lock tests must not gate the live world's pump. */
class SimPhaseQueueTest extends TestCase
{
    private ?string $fixture = null;
    private string $original;
    private bool $watch = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_SIM_INDEX_PG_TESTS') !== '1') {
            $this->markTestSkipped('Opt in to the disposable PostgreSQL queue fixture.');
        }
        $this->original = DB::getDefaultConnection();
        config(['database.connections.phase_admin' => array_replace(config('database.connections.pgsql'), ['database' => 'postgres', 'name' => 'phase_admin'])]);
        $admin = DB::connection('phase_admin');
        $this->assertSame('postgres', $admin->selectOne('SELECT current_database() AS name')->name);
        $this->fixture = 'phase_test_'.bin2hex(random_bytes(8));
        $admin->statement('CREATE DATABASE '.$this->fixture.' TEMPLATE template0');
        foreach (['phase_a', 'phase_b'] as $name) {
            config(['database.connections.'.$name => array_replace($admin->getConfig(), ['database' => $this->fixture, 'name' => $name])]);
            DB::connection($name)->statement("SET lock_timeout = '2s'");
            DB::connection($name)->statement("SET statement_timeout = '20s'");
        }
        DB::setDefaultConnection('phase_a');
        $this->assertSame($this->fixture, DB::selectOne('SELECT current_database() AS name')->name);
        $this->assertSame('phase_a', (new SimRun)->getConnection()->getName());
        $this->assertSame($this->fixture, (new SimRun)->getConnection()->selectOne('SELECT current_database() AS name')->name);
        DB::statement('CREATE TABLE sim_runs (id uuid PRIMARY KEY, phase text, status text, phase_timings jsonb, options jsonb, halt_requested_at timestamptz, paused_until timestamptz, created_at timestamptz, updated_at timestamptz)');
        DB::statement("CREATE TABLE sim_items (id uuid PRIMARY KEY, run_id uuid, kind text, status text, jurisdiction_id uuid, race_id uuid, adm_level smallint, unit_key varchar(128), position integer, est_cost bigint, metrics jsonb, created_at timestamptz, updated_at timestamptz, UNIQUE(run_id,kind,unit_key))");
        DB::statement('CREATE TABLE elections (id uuid PRIMARY KEY, jurisdiction_id uuid, status text)');
        DB::statement('CREATE TABLE election_races (id uuid PRIMARY KEY, election_id uuid)');
        DB::statement('CREATE INDEX races_election_idx ON election_races(election_id)');
        Cache::flush();
        putenv('CGA_ENUM_CHUNK=2');
    }

    protected function tearDown(): void
    {
        $this->watch = false;
        $this->travelBack();
        putenv('CGA_ENUM_CHUNK');
        if ($this->fixture !== null) {
            DB::setDefaultConnection($this->original);
            DB::purge('phase_a'); DB::purge('phase_b');
            if (! preg_match('/^phase_test_[a-f0-9]{16}$/D', $this->fixture)) {
                throw new \LogicException('Unexpected fixture database.');
            }
            DB::connection('phase_admin')->statement('DROP DATABASE '.$this->fixture.' WITH (FORCE)');
            DB::purge('phase_admin');
        }
        parent::tearDown();
    }

    private function command(): SimPumpCommand
    {
        $command = app(SimPumpCommand::class);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        return $command;
    }

    private function invoke(string $method, SimRun $run): mixed
    {
        return (new \ReflectionMethod(SimPumpCommand::class, $method))->invoke($this->command(), $run);
    }

    private function runModel(): SimRun
    {
        return SimRun::create(['id' => (string) Str::uuid(), 'phase' => 'counting', 'status' => 'running', 'phase_timings' => []]);
    }

    private function source(SimRun $run, int $order, string $eligibility = 'eligible'): string
    {
        $election = (string) Str::uuid(); $jurisdiction = (string) Str::uuid();
        DB::table('elections')->insert(['id' => $election, 'jurisdiction_id' => $jurisdiction, 'status' => $eligibility === 'cancelled' ? 'cancelled' : 'scheduled']);
        if ($eligibility !== 'no_races') {
            DB::table('election_races')->insert(['id' => (string) Str::uuid(), 'election_id' => $election]);
        }
        DB::table('sim_items')->insert(['id' => (string) Str::uuid(), 'run_id' => $run->id, 'kind' => 'election_scope', 'status' => 'done', 'jurisdiction_id' => $jurisdiction, 'race_id' => $eligibility === 'no_election' ? null : $election, 'adm_level' => 2, 'unit_key' => sprintf('%04d', $order), 'position' => 97]);
        return $election;
    }

    public function test_an_empty_insert_batch_does_not_finish_generation_and_resume_keeps_every_target(): void
    {
        $run = $this->runModel();
        $this->source($run, 1, 'cancelled'); $this->source($run, 2, 'no_election');
        $existing = $this->source($run, 3); $this->source($run, 4, 'no_races');
        $a = $this->source($run, 5); $b = $this->source($run, 6);
        DB::table('sim_items')->insert(['id' => (string) Str::uuid(), 'run_id' => $run->id, 'kind' => 'count_election', 'status' => 'done', 'race_id' => $existing, 'unit_key' => $existing]);
        $this->watch = true;
        DB::listen(function ($query) use ($run) {
            if ($this->watch && str_starts_with($query->sql, 'update "sim_runs"')) {
                $this->watch = false;
                DB::table('sim_runs')->where('id', $run->id)->update(['halt_requested_at' => now()]);
            }
        });
        $this->assertFalse($this->invoke('ensureWorklist', $run));
        $run->refresh();
        $this->assertSame('0002', $run->phase_timings['counting']['worklist']['cursor']);
        $this->assertFalse($run->phase_timings['counting']['worklist']['complete']);
        $this->invoke('advancePhase', $run);
        $this->assertSame('counting', $run->fresh()->phase, 'Drained partial queue cannot advance.');
        $run->forceFill(['halt_requested_at' => null])->save();
        $this->invoke('advancePhase', $run);
        $this->assertTrue($run->fresh()->phase_timings['counting']['worklist']['complete']);
        $this->assertSame('counting', $run->fresh()->phase, 'New pending work must drain first.');
        $this->assertEqualsCanonicalizing([$existing, $a, $b], DB::table('sim_items')->where('kind', 'count_election')->pluck('race_id')->all());
        $this->assertSame(6, $run->phase_timings['counting']['worklist']['scanned']);
        $this->assertSame(2, $run->phase_timings['counting']['worklist']['minted']);
        DB::table('sim_items')->where('kind', 'count_election')->update(['status' => 'done']);
        $this->invoke('advancePhase', $run);
        $this->assertSame('seating', $run->fresh()->phase);
        $this->assertSame(3, DB::table('sim_items')->where('kind', 'seat_scope')->count());
    }

    public function test_a_crash_rolls_back_the_insert_and_cursor_as_one_chunk(): void
    {
        $run = $this->runModel(); $this->source($run, 1); $this->source($run, 2);
        $this->watch = true;
        DB::listen(function ($query) {
            if ($this->watch && str_starts_with($query->sql, 'update "sim_runs"')) {
                $this->watch = false;
                throw new \RuntimeException('fixture crash at cursor persistence');
            }
        });
        try {
            $this->invoke('ensureWorklist', $run);
            $this->fail('Fixture crash must propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('fixture crash at cursor persistence', $e->getMessage());
        }
        $this->assertSame(0, DB::table('sim_items')->where('kind', 'count_election')->count());
        $this->assertSame([], $run->fresh()->phase_timings);
        $this->assertTrue($this->invoke('ensureWorklist', $run->fresh()));
        $this->assertSame(2, DB::table('sim_items')->where('kind', 'count_election')->count());
    }

    public function test_a_second_pump_cannot_enter_after_the_cache_lock_expires(): void
    {
        $observed = false;
        $this->watch = true;
        DB::listen(function ($query) use (&$observed) {
            if (! $this->watch || ! str_contains($query->sql, 'from "sim_runs"')) {
                return;
            }
            $this->watch = false;
            $this->travel(121)->seconds();
            $expired = Cache::lock(SimPumpCommand::EXEC_LOCK, 120);
            $this->assertTrue($expired->get(), 'The cache TTL really expired.');
            $expired->release();
            DB::setDefaultConnection('phase_b');
            try {
                $this->assertSame(0, $this->command()->handle());
                $observed = true;
                $this->assertFalse(DB::selectOne('SELECT pg_try_advisory_lock(?) AS held', [SimPumpCommand::ADVISORY_LOCK_KEY])->held);
            } finally {
                DB::setDefaultConnection('phase_a');
            }
        });
        $this->assertSame(0, $this->command()->handle());
        $this->assertTrue($observed);
        $other = DB::connection('phase_b');
        $this->assertTrue($other->selectOne('SELECT pg_try_advisory_lock(?) AS held', [SimPumpCommand::ADVISORY_LOCK_KEY])->held);
        $other->selectOne('SELECT pg_advisory_unlock(?)', [SimPumpCommand::ADVISORY_LOCK_KEY]);
    }

    public function test_an_empty_phase_gets_a_completion_marker_and_can_advance(): void
    {
        $run = $this->runModel();
        $this->invoke('advancePhase', $run);
        $this->assertTrue($run->fresh()->phase_timings['counting']['worklist']['complete']);
        $this->assertSame('seating', $run->fresh()->phase);
    }

    public function test_audit_timing_preserves_batch_contents_and_chain_links(): void
    {
        DB::statement('CREATE TABLE audit_log (seq bigserial PRIMARY KEY, occurred_at timestamptz, actor_user_id uuid, module text, event text, ref text, jurisdiction_id uuid, payload jsonb, prev_hash text, hash text, rejected boolean, blocked_reason text, created_at timestamptz)');
        DB::statement('CREATE TABLE sim_timings (run_id uuid, part text, count bigint, total_us bigint, max_us bigint, updated_at timestamptz, PRIMARY KEY(run_id,part))');
        $genesis = str_repeat('a', 64);
        DB::table('audit_log')->insert(['hash' => $genesis, 'prev_hash' => str_repeat('0', 64), 'payload' => '{}']);
        $audit = app(AuditService::class);
        foreach ([false, true] as $individual) {
            $audit->beginBatch();
            $audit->append('fixture', 'first', ['value' => 1]);
            $audit->append('fixture', 'second', ['value' => 2]);
            SimTimer::open('audit.commit');
            try {
                $individual ? $audit->commitBatchIndividual() : $audit->commitBatch('simworld', 'sim.fixture');
            } finally {
                SimTimer::close('audit.commit');
            }
        }
        $runId = (string) Str::uuid(); SimTimer::flush($runId);
        foreach (['audit.commit', 'audit.lock_wait'] as $part) {
            $this->assertSame(2, (int) DB::table('sim_timings')->where('run_id', $runId)->where('part', $part)->value('count'));
        }
        $rows = DB::table('audit_log')->orderBy('seq')->get();
        $this->assertCount(4, $rows);
        foreach ($rows->slice(1) as $i => $row) {
            $this->assertSame($rows[$i-1]->hash, $row->prev_hash);
            $this->assertSame(AuditService::chainHash($row->prev_hash, AuditService::canonicalJson(json_decode($row->payload, true))), $row->hash);
        }
        $this->assertSame(2, json_decode($rows[1]->payload, true)['act_count']);
        $audit->append('fixture', 'outside_simulation', []);
        $otherRun = (string) Str::uuid(); SimTimer::flush($otherRun);
        $this->assertSame(0, DB::table('sim_timings')->where('run_id', $otherRun)->count(), 'Non-simulation audit writes must not leak timers into another run.');
    }
}
