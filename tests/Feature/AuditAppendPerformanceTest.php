<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditAppendPerformanceTest extends TestCase
{
    private ?string $fixture = null;
    private string $original;
    private array $children = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_SIM_INDEX_PG_TESTS') !== '1') {
            $this->markTestSkipped('Opt in to the disposable audit database.');
        }
        $this->original = DB::getDefaultConnection();
        config(['database.connections.audit_admin' => array_replace(config('database.connections.pgsql'), ['database' => 'postgres', 'name' => 'audit_admin', 'url' => null])]);
        $admin = DB::connection('audit_admin');
        $this->assertSame('postgres', $admin->selectOne('SELECT current_database() AS name')->name);
        $this->fixture = 'audit_test_'.bin2hex(random_bytes(8));
        $admin->statement('CREATE DATABASE '.$this->fixture.' TEMPLATE template0');
        config(['database.connections.audit_test' => array_replace($admin->getConfig(), ['database' => $this->fixture, 'name' => 'audit_test'])]);
        DB::setDefaultConnection('audit_test');
        $this->assertSame($this->fixture, DB::selectOne('SELECT current_database() AS name')->name);
        $this->assertSame('audit_test', (new AuditEntry)->getConnection()->getName());
        $this->assertSame($this->fixture, (new AuditEntry)->getConnection()->selectOne('SELECT current_database() AS name')->name);
        DB::statement("SET lock_timeout = '10s'");
        DB::statement("SET statement_timeout = '15s'");
        // Use the repository's real audit table and append-only triggers.
        $schema = file_get_contents(base_path('database/schema/pgsql-schema.sql'));
        preg_match('/CREATE TABLE public.audit_log \([\s\S]*?\n\);/', $schema, $table);
        $this->assertNotEmpty($table);
        DB::unprepared($table[0]);
        DB::statement('CREATE SEQUENCE fixture_audit_seq_seq');
        DB::statement("ALTER TABLE audit_log ALTER COLUMN seq SET DEFAULT nextval('fixture_audit_seq_seq')");
        DB::statement('CREATE UNIQUE INDEX fixture_audit_seq ON audit_log(seq)');
        preg_match('/CREATE FUNCTION public.audit_log_block_mutation\(\)[\s\S]*?\$\$;/', $schema, $function);
        $this->assertNotEmpty($function);
        DB::unprepared($function[0]);
        DB::statement('CREATE TRIGGER fixture_audit_immutable BEFORE UPDATE OR DELETE ON audit_log FOR EACH ROW EXECUTE FUNCTION public.audit_log_block_mutation()');
        DB::statement('CREATE TRIGGER fixture_audit_no_truncate BEFORE TRUNCATE ON audit_log FOR EACH STATEMENT EXECUTE FUNCTION public.audit_log_block_mutation()');
    }

    protected function tearDown(): void
    {
        foreach ($this->children as [$process, $pipes]) {
            if (is_resource($process)) { proc_terminate($process); }
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            if (is_resource($process)) { proc_close($process); }
        }
        if ($this->fixture !== null) {
            while (DB::transactionLevel() > 0) { DB::rollBack(); }
            DB::setDefaultConnection($this->original);
            DB::purge('audit_test');
            if (! preg_match('/^audit_test_[a-f0-9]{16}$/D', $this->fixture)) { throw new \LogicException('Unexpected fixture.'); }
            DB::connection('audit_admin')->statement('DROP DATABASE '.$this->fixture.' WITH (FORCE)');
            DB::purge('audit_admin');
        }
        parent::tearDown();
    }

    private function genesis(): void
    {
        $payload = AuditService::canonicalJson(['genesis' => true]);
        DB::table('audit_log')->insert(['module' => 'fixture', 'event' => 'genesis', 'payload' => $payload, 'prev_hash' => AuditService::GENESIS_PREV_HASH, 'hash' => AuditService::chainHash(AuditService::GENESIS_PREV_HASH, $payload), 'occurred_at' => now(), 'created_at' => now()]);
    }

    private function assertChain(): void
    {
        $previous = AuditService::GENESIS_PREV_HASH;
        foreach (DB::table('audit_log')->orderBy('seq')->get() as $row) {
            $this->assertSame($previous, $row->prev_hash);
            $this->assertSame(AuditService::chainHash($previous, AuditService::canonicalJson(json_decode($row->payload, true))), $row->hash);
            $previous = $row->hash;
        }
    }

    public function test_single_append_keeps_canonical_hashes_and_metadata_with_one_less_query(): void
    {
        $this->genesis();
        $actor = (string) Str::uuid(); $jurisdiction = (string) Str::uuid();
        $payloads = [[], ['unicode' => 'Łódź 日本語 🌍', 'url' => 'https://example.test/a/b'], ['nested' => ['z' => null, 'a' => [true, false, 12.5, 1.0]], 'list' => [3, 1, 2]], ['line' => "one\ntwo\t\\\"", 'large' => str_repeat('a', 20000)]];
        foreach ($payloads as $payload) {
            DB::enableQueryLog(); DB::flushQueryLog();
            $entry = app(AuditService::class)->append('fixture', 'tested', $payload, 'TEST', $actor, $jurisdiction, true, 'fixture refusal');
            $queries = DB::getQueryLog(); DB::disableQueryLog();
            $this->assertCount(2, $queries, 'Exactly one lock statement, then one head-read/insert statement.');
            $this->assertStringContainsString('pg_advisory_xact_lock', $queries[0]['query']);
            $this->assertStringContainsString('INSERT INTO audit_log', $queries[1]['query']);
            $this->assertSame('TEST', $entry->ref); $this->assertSame($actor, $entry->actor_user_id);
            $this->assertSame($jurisdiction, $entry->jurisdiction_id); $this->assertTrue($entry->rejected);
            $this->assertSame('fixture refusal', $entry->blocked_reason);
            $this->assertSame(AuditService::canonicalJson($payload), AuditService::canonicalJson($entry->payload));
        }
        $this->assertChain();
    }

    public function test_outer_rollback_still_rolls_back_the_audit_and_its_mutation(): void
    {
        $this->genesis(); DB::statement('CREATE TABLE fixture_changes (id integer)');
        DB::beginTransaction();
        DB::table('fixture_changes')->insert(['id' => 1]);
        app(AuditService::class)->append('fixture', 'rolled_back', ['change' => 1]);
        $this->assertSame(1, DB::transactionLevel());
        DB::rollBack();
        $this->assertSame(0, DB::table('fixture_changes')->count());
        $this->assertSame(1, DB::table('audit_log')->count());
        app(AuditService::class)->append('fixture', 'after_rollback', []);
        $this->assertChain();
    }

    public function test_missing_genesis_is_still_an_error_without_an_insert(): void
    {
        try {
            app(AuditService::class)->append('fixture', 'missing', []);
            $this->fail('Missing genesis must fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('genesis row missing', $e->getMessage());
        }
        $this->assertSame(0, DB::table('audit_log')->count());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_database_immutability_guards_still_reject_mutations(): void
    {
        $this->genesis(); app(AuditService::class)->append('fixture', 'protected', []);
        foreach (["UPDATE audit_log SET event='tampered'", 'DELETE FROM audit_log', 'TRUNCATE audit_log'] as $sql) {
            try { DB::statement($sql); $this->fail('Mutation must fail.'); }
            catch (\Illuminate\Database\QueryException $e) { $this->assertStringContainsString('append-only', $e->getMessage()); }
        }
        $this->assertChain();
    }

    public function test_waiting_writers_read_the_committed_head_and_never_fork(): void
    {
        $this->genesis(); DB::beginTransaction();
        DB::statement('SELECT pg_advisory_xact_lock(?)', [AuditService::APPEND_LOCK_KEY]);
        $pids = [];
        for ($i = 0; $i < 2; $i++) {
            $process = proc_open([PHP_BINARY, base_path('tests/Support/audit_append_worker.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process); $this->children[] = [$process, $pipes];
            fwrite($pipes[0], json_encode(['connection' => DB::connection()->getConfig(), 'worker' => $i], JSON_THROW_ON_ERROR)."\n");
            stream_set_timeout($pipes[1], 10);
            $ready = json_decode(fgets($pipes[1]) ?: '{}', true);
            $this->assertTrue($ready['ready'] ?? false); $pids[] = $ready['pid'];
            fwrite($pipes[0], "GO\n");
        }
        $deadline = microtime(true) + 5;
        do {
            DB::selectOne('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::table('pg_stat_activity')->whereIn('pid', $pids)->where('wait_event', 'advisory')->count();
            if ($waiting === 2) { break; }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->assertSame(2, $waiting, 'Both appends must actually wait before the parent changes the head.');
        app(AuditService::class)->append('fixture', 'parent_before_release', []);
        DB::commit();
        foreach ($this->children as $index => [$process, $pipes]) {
            $this->assertSame("DONE\n", fgets($pipes[1]));
            fclose($pipes[0]); fclose($pipes[1]);
            $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors);
            unset($this->children[$index]);
        }
        $this->assertSame(42, DB::table('audit_log')->count());
        $this->assertSame(40, DB::table('audit_log')->where('event', 'concurrent')->count());
        $this->assertChain();
    }
}
