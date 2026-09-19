<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Opt-in PostgreSQL integration test. Real CONCURRENTLY DDL runs ONLY in a
 * nonce schema with no public schema in its search path. No world tables,
 * migrations history, queues or workers are touched. No wrapping transaction.
 * RUN_SIM_INDEX_PG_TESTS=1 vendor/bin/phpunit this-file.php
 */
class SimulationPerformanceIndexesTest extends TestCase
{
    use LivePgConnection;

    private ?string $schema = null;
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_SIM_INDEX_PG_TESTS') !== '1') {
            $this->markTestSkipped('Opt in to the isolated PostgreSQL schema test.');
        }
        $this->original = DB::getDefaultConnection();
        $connection = $this->livePg('pgsql_sim_index_fixture');
        $this->schema = 'sim_index_test_'.bin2hex(random_bytes(8));
        $connection->statement('CREATE SCHEMA '.$this->schema);
        $connection->statement('SET search_path TO '.$this->schema.', pg_catalog');
        DB::setDefaultConnection('pgsql_sim_index_fixture');
        $this->assertSame($this->schema, DB::selectOne('SELECT current_schema() AS name')->name);
        DB::statement('CREATE TABLE residency_confirmations (jurisdiction_id uuid NOT NULL, user_id uuid NOT NULL, is_active boolean NOT NULL)');
        DB::statement("INSERT INTO residency_confirmations SELECT md5((g % 4)::text)::uuid, md5(g::text)::uuid, g % 3 <> 0 FROM generate_series(1, 4000) g");
        DB::statement('CREATE TABLE sim_items (run_id uuid NOT NULL, status text NOT NULL, finished_at timestamptz)');
        DB::statement("INSERT INTO sim_items SELECT md5((g % 4)::text)::uuid, CASE WHEN g % 3 = 0 THEN 'pending' ELSE 'done' END, now() - g * interval '1 second' FROM generate_series(1, 4000) g");
    }

    protected function tearDown(): void
    {
        if ($this->schema !== null) {
            // The only destructive DDL is this exact random fixture schema.
            if (! preg_match('/^sim_index_test_[a-f0-9]{16}$/D', $this->schema)) {
                throw new \LogicException('Unexpected fixture schema.');
            }
            DB::connection('pgsql_sim_index_fixture')->statement('DROP SCHEMA '.$this->schema.' CASCADE');
            DB::setDefaultConnection($this->original);
            DB::purge('pgsql_sim_index_fixture');
        }
        parent::tearDown();
    }

    private function migrations(): array
    {
        return [
            'residency_active_jurisdiction_user_idx' => require base_path('database/migrations/2026_09_19_190000_index_active_residents_by_jurisdiction_user.php'),
            'sim_items_run_finished_done_idx' => require base_path('database/migrations/2026_09_19_191000_index_sim_recent_completions.php'),
        ];
    }

    public function test_concurrent_builds_are_valid_rerunnable_and_used_without_sorting(): void
    {
        $sql = "SELECT user_id FROM residency_confirmations WHERE jurisdiction_id = md5('1')::uuid AND is_active = true ORDER BY user_id LIMIT 40";
        $before = DB::select($sql);
        foreach ($this->migrations() as $name => $migration) {
            $this->assertFalse($migration->withinTransaction);
            $migration->up();
            $first = DB::selectOne('SELECT indexrelid, indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
            $this->assertTrue($first->indisvalid);
            $migration->up();
            $this->assertSame($first->indexrelid, DB::selectOne('SELECT indexrelid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name])->indexrelid);
        }
        $this->assertEquals($before, DB::select($sql), 'same residents in the same order');
        DB::statement('ANALYZE residency_confirmations'); // tiny nonce table only
        DB::statement('ANALYZE sim_items');
        foreach ([9, 40] as $limit) {
            $plan = json_encode(DB::select('EXPLAIN (FORMAT JSON) '.str_replace('LIMIT 40', 'LIMIT '.$limit, $sql)));
            $this->assertStringContainsString('residency_active_jurisdiction_user_idx', $plan);
            $this->assertStringNotContainsString('Sort', $plan);
        }
        $plan = json_encode(DB::select("EXPLAIN (FORMAT JSON) SELECT count(*) FROM sim_items WHERE run_id = md5('1')::uuid AND status = 'done' AND finished_at > now() - interval '10 minutes'"));
        $this->assertStringContainsString('sim_items_run_finished_done_idx', $plan);
        foreach ($this->migrations() as $name => $migration) {
            $migration->down();
            $this->assertNull(DB::selectOne('SELECT to_regclass(?) AS id', [$name])->id);
        }
    }

    public function test_retry_replaces_a_failed_concurrent_build_instead_of_accepting_an_invalid_index(): void
    {
        $migrations = $this->migrations();
        foreach ([
            'residency_active_jurisdiction_user_idx' => 'residency_confirmations (jurisdiction_id)',
            'sim_items_run_finished_done_idx' => 'sim_items (run_id)',
        ] as $name => $definition) {
            try {
                // Duplicate keys force PostgreSQL to leave an INVALID index.
                DB::statement('CREATE UNIQUE INDEX CONCURRENTLY '.$name.' ON '.$definition);
                $this->fail('Duplicate fixture keys should reject the unique build.');
            } catch (\Illuminate\Database\QueryException) {
                $this->assertFalse(DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name])->indisvalid);
            }
            $migrations[$name]->up();
            $this->assertTrue(DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name])->indisvalid);
        }
    }
}
