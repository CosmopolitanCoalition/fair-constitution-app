<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Real concurrent DDL in a dedicated disposable database, never the world DB. */
class SeatingMemberIndexTest extends TestCase
{
    private ?string $fixture = null;
    private string $original;
    private const INDEX = 'legislature_members_active_election_idx';
    private const ELECTION = '00000000-0000-0000-0000-000000000001';
    private const QUERY = "SELECT count(*) AS n FROM legislature_members WHERE election_id = ? AND status IN (?, ?) AND deleted_at IS NULL";

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_SIM_INDEX_PG_TESTS') !== '1') {
            $this->markTestSkipped('Opt in to the disposable seating-index database.');
        }
        $this->original = DB::getDefaultConnection();
        config(['database.connections.seat_admin' => array_replace(config('database.connections.pgsql'), ['database' => 'postgres', 'name' => 'seat_admin', 'url' => null])]);
        $admin = DB::connection('seat_admin');
        $this->assertSame('postgres', $admin->selectOne('SELECT current_database() AS name')->name);
        $this->fixture = 'seat_index_test_'.bin2hex(random_bytes(8));
        $admin->statement('CREATE DATABASE '.$this->fixture.' TEMPLATE template0');
        config(['database.connections.seat_test' => array_replace($admin->getConfig(), ['database' => $this->fixture, 'name' => 'seat_test'])]);
        DB::setDefaultConnection('seat_test');
        $this->assertSame($this->fixture, DB::selectOne('SELECT current_database() AS name')->name);
        $this->assertSame('seat_test', DB::connection()->getName());
        DB::statement("SET lock_timeout = '2s'");
        DB::statement("SET statement_timeout = '20s'");
        DB::statement('CREATE TABLE legislature_members (id uuid PRIMARY KEY DEFAULT gen_random_uuid(), election_id uuid, status varchar(20), deleted_at timestamptz)');
        DB::statement("INSERT INTO legislature_members (election_id,status) SELECT md5((g / 10)::text)::uuid, CASE WHEN g % 3 = 0 THEN 'vacated' ELSE 'elected' END FROM generate_series(1,20000) g");
        foreach (['elected', 'elected', 'elected', 'seated', 'seated', 'vacated', 'removed', 'term_ended'] as $status) {
            DB::table('legislature_members')->insert(['election_id' => self::ELECTION, 'status' => $status]);
        }
        foreach (['elected', 'seated'] as $status) {
            DB::table('legislature_members')->insert(['election_id' => self::ELECTION, 'status' => $status, 'deleted_at' => now()]);
        }
        DB::statement('ANALYZE legislature_members'); // only the 20,010-row private fixture
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            DB::setDefaultConnection($this->original); DB::purge('seat_test');
            if (! preg_match('/^seat_index_test_[a-f0-9]{16}$/D', $this->fixture)) { throw new \LogicException('Unexpected fixture.'); }
            DB::connection('seat_admin')->statement('DROP DATABASE '.$this->fixture.' WITH (FORCE)');
            DB::purge('seat_admin');
        }
        parent::tearDown();
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_09_19_213000_index_active_members_by_election.php');
    }

    private function countMembers(): int
    {
        return (int) DB::selectOne(self::QUERY, [self::ELECTION, 'elected', 'seated'])->n;
    }

    private function plan(): string
    {
        return json_encode(DB::select('EXPLAIN (FORMAT JSON) '.self::QUERY, [self::ELECTION, 'elected', 'seated']));
    }

    public function test_the_count_uses_the_new_index_with_identical_results_and_safe_retries(): void
    {
        $this->assertSame(5, $this->countMembers());
        $this->assertStringContainsString('Seq Scan', $this->plan());
        $migration = $this->migration();
        $this->assertFalse($migration->withinTransaction);
        $migration->up();
        $before = DB::selectOne('SELECT indexrelid, indisvalid, indisready FROM pg_index WHERE indexrelid=to_regclass(?)', [self::INDEX]);
        $this->assertTrue($before->indisvalid); $this->assertTrue($before->indisready);
        $migration->up();
        $this->assertSame($before->indexrelid, DB::selectOne('SELECT indexrelid FROM pg_index WHERE indexrelid=to_regclass(?)', [self::INDEX])->indexrelid);
        $this->assertStringContainsString(self::INDEX, $this->plan());
        $this->assertStringNotContainsString('Seq Scan', $this->plan());
        $this->assertSame(5, $this->countMembers());
        $migration->down();
        $this->assertNull(DB::selectOne('SELECT to_regclass(?) AS id', [self::INDEX])->id);
        $this->assertNotNull(DB::selectOne("SELECT to_regclass('legislature_members_pkey') AS id")->id);
        $this->assertSame(5, $this->countMembers());
    }

    public function test_failed_concurrent_build_is_recovered(): void
    {
        try {
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY '.self::INDEX.' ON legislature_members(election_id)');
            $this->fail('Repeated election IDs must leave a failed unique build.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertFalse(DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid=to_regclass(?)', [self::INDEX])->indisvalid);
        }
        $this->migration()->up();
        $this->assertTrue(DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid=to_regclass(?)', [self::INDEX])->indisvalid);
        $this->assertStringContainsString(self::INDEX, $this->plan());
        $this->assertSame(5, $this->countMembers());
    }

    public function test_status_and_soft_delete_changes_keep_the_index_count_correct(): void
    {
        $this->migration()->up();
        DB::table('legislature_members')->where('election_id', self::ELECTION)->where('status', 'elected')->whereNull('deleted_at')->update(['status' => 'term_ended']);
        $this->assertSame(2, $this->countMembers());
        DB::table('legislature_members')->where('election_id', self::ELECTION)->where('status', 'seated')->whereNull('deleted_at')->update(['deleted_at' => now()]);
        $this->assertSame(0, $this->countMembers());
        DB::table('legislature_members')->where('election_id', self::ELECTION)->where('status', 'removed')->update(['status' => 'seated']);
        $this->assertSame(1, $this->countMembers());
        $this->assertStringContainsString(self::INDEX, $this->plan());
    }
}
