<?php

namespace Tests\Feature;

use App\Models\Economy\LedgerEntry;
use App\Support\PublicFinanceDirectory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** DDL and EXPLAIN ANALYZE only in this test's guarded, bounded nonce database. */
class LedgerAccountIndexRetirementTest extends TestCase
{
    use \Tests\Support\UsesProductionLedgerIndexes;

    private const OLD = 'ledger_entries_account_type_account_id_index';
    private const WIDE = 'ledger_public_account_seek_idx';
    private ?string $fixture = null;
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_SIM_INDEX_PG_TESTS') !== '1') {
            $this->markTestSkipped('Opt in to the disposable ledger index database.');
        }
        $this->original = DB::getDefaultConnection();
        config(['database.connections.ledger_index_admin' => array_replace(config('database.connections.pgsql'),
            ['database' => 'postgres', 'name' => 'ledger_index_admin', 'url' => null])]);
        $admin = DB::connection('ledger_index_admin');
        $this->assertSame('postgres', $admin->selectOne('SELECT current_database() AS name')->name);
        $this->fixture = 'ledger_index_test_'.bin2hex(random_bytes(8));
        $admin->statement('CREATE DATABASE '.$this->fixture.' TEMPLATE template0');
        foreach (['ledger_index_test', 'ledger_index_peer'] as $name) {
            config(['database.connections.'.$name => array_replace($admin->getConfig(),
                ['database' => $this->fixture, 'name' => $name, 'url' => null])]);
            $this->assertSame($this->fixture, DB::connection($name)->selectOne('SELECT current_database() AS name')->name);
        }
        DB::setDefaultConnection('ledger_index_test');
        $this->assertSame($this->fixture, (new LedgerEntry)->getConnection()->selectOne('SELECT current_database() AS name')->name);
        DB::statement("SET lock_timeout = '1s'");
        DB::statement("SET statement_timeout = '10s'");
        (require base_path('database/migrations/2026_07_25_000002_create_ledger_plane.php'))->up();
        $this->installLedgerHistoryIndexes(false);
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            foreach (['ledger_index_test', 'ledger_index_peer'] as $name) {
                while (DB::connection($name)->transactionLevel() > 0) { DB::connection($name)->rollBack(); }
                DB::purge($name);
            }
            DB::setDefaultConnection($this->original);
            if (! preg_match('/^ledger_index_test_[a-f0-9]{16}$/D', $this->fixture)) {
                throw new \LogicException('Unexpected fixture database.');
            }
            DB::connection('ledger_index_admin')->statement('DROP DATABASE '.$this->fixture.' WITH (FORCE)');
            DB::purge('ledger_index_admin');
        }
        parent::tearDown();
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_09_20_090000_retire_duplicate_ledger_account_index.php');
    }

    private function id(int $n): string { return sprintf('d0090000-0000-4000-8000-%012d', $n); }

    private function indexes(): array
    {
        return DB::table('pg_indexes')->where('schemaname', 'public')->where('tablename', 'ledger_entries')->orderBy('indexname')->pluck('indexdef', 'indexname')->all();
    }

    private function seedHistory(): void
    {
        // Fixed 60k-row fixture: 20k hot-treasury rows in two currencies,
        // 39,980 other wallet rows, and 20 sparse wallet rows in two currencies.
        // Raw rows model read distribution only; real chain/rollback checks run
        // in TrainingLedgerPerformanceTest with the identical six-index set.
        DB::statement("INSERT INTO ledger_entries (id, entry_group, account_type, account_id, currency_id,
            direction, amount, kind, prev_hash, hash, created_at)
            SELECT gen_random_uuid(), gen_random_uuid(),
                CASE WHEN g <= 20000 THEN 'treasury_accounts' ELSE 'economic_accounts' END,
                CASE WHEN g <= 20000 THEN ?::uuid WHEN g > 59980 THEN ?::uuid ELSE md5((g / 10)::text)::uuid END,
                CASE WHEN g % 2 = 0 THEN ?::uuid ELSE ?::uuid END,
                CASE WHEN g % 2 = 0 THEN 'credit' ELSE 'debit' END, 1.234567, 'stipend', repeat('0',64), repeat('1',64), now()
            FROM generate_series(1,60000) g", [$this->id(20), $this->id(21), $this->id(10), $this->id(11)]);
        DB::statement('VACUUM (ANALYZE) ledger_entries'); // this private fixture only
        DB::statement('CREATE TABLE jurisdictions (id uuid PRIMARY KEY, parent_id uuid, name text, slug text, adm_level int, deleted_at timestamptz)');
        DB::statement('CREATE TABLE departments (id uuid PRIMARY KEY, jurisdiction_id uuid, deleted_at timestamptz)');
        DB::table('jurisdictions')->insert(['id' => $this->id(1), 'name' => 'Fixture', 'slug' => 'fixture', 'adm_level' => 0]);
        DB::table('currencies')->insert(['id' => $this->id(10), 'jurisdiction_id' => $this->id(1), 'name' => 'Fixture', 'code' => 'FIX', 'symbol' => 'F']);
        DB::table('treasury_accounts')->insert(['id' => $this->id(20), 'owner_type' => 'jurisdictions', 'owner_id' => $this->id(1), 'currency_id' => $this->id(10), 'public' => true]);
    }

    private function plans(array $queries): array
    {
        $plans = [];
        foreach ($queries as $name => [$sql, $bindings]) {
            $plan = json_decode(DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql, $bindings)->{'QUERY PLAN'}, true)[0];
            $plans[$name] = $plan;
        }
        return $plans;
    }

    public function test_results_and_scoped_plans_survive_retirement_for_hot_and_sparse_accounts(): void
    {
        $this->seedHistory();
        $queries = [];
        foreach (['treasury_accounts', 'economic_accounts'] as $type) {
            $bindings = [$type, $this->id($type === 'treasury_accounts' ? 20 : 21)];
            $base = ' FROM ledger_entries WHERE account_type=? AND account_id=?';
            $queries[$type.'.exists'] = ['SELECT EXISTS(SELECT 1'.$base.') AS found', $bindings];
            $queries[$type.'.account_page'] = ['SELECT seq, amount, currency_id'.$base.' ORDER BY currency_id, seq LIMIT 50', $bindings];
            $queries[$type.'.account_totals'] = ["SELECT currency_id, sum(CASE WHEN direction='credit' THEN amount ELSE -amount END) AS total".$base.' GROUP BY currency_id ORDER BY currency_id', $bindings];
            $queries[$type.'.history'] = ['SELECT seq, amount'.$base.' AND currency_id=? AND seq<? ORDER BY seq DESC LIMIT 50', [...$bindings, $this->id(10), 60010]];
            $queries[$type.'.cross_currency_history'] = ['SELECT seq, amount'.$base.' AND seq<? ORDER BY seq DESC LIMIT 50', [...$bindings, 60010]];
        }
        $before = [];
        foreach ($queries as $name => [$sql, $bindings]) { $before[$name] = DB::select($sql, $bindings); }
        $plansBefore = $this->plans($queries);
        $directory = fn () => new PublicFinanceDirectory(Request::create('/economy/treasury'), $this->id(10), $this->id(1));
        $publicBefore = $directory()->ledger();
        $oldIndexes = $this->indexes();
        $this->assertCount(7, $oldIndexes);
        $this->migration()->up();
        $expected = $oldIndexes; unset($expected[self::OLD]);
        $this->assertSame($expected, $this->indexes());
        foreach ($queries as $name => [$sql, $bindings]) { $this->assertEquals($before[$name], DB::select($sql, $bindings), $name); }
        $this->assertSame($publicBefore, $directory()->ledger());
        $plansAfter = $this->plans($queries);
        // Emit reproducible read-cost evidence without a noisy elapsed-time gate.
        $summary = [];
        foreach ($plansAfter as $name => $plan) {
            $summary[$name] = array_map(fn ($p) => ['ms' => $p['Execution Time'],
                'buffers' => ($p['Plan']['Shared Hit Blocks'] ?? 0) + ($p['Plan']['Shared Read Blocks'] ?? 0)], [$plansBefore[$name], $plan]);
        }
        fwrite(STDOUT, "\nD009 private fixture read costs (before/after): ".json_encode($summary)."\n");
        foreach ($plansAfter as $name => $plan) {
            $encoded = json_encode($plan);
            if (! str_contains(json_encode($plansBefore[$name]), 'Seq Scan')) {
                $this->assertStringNotContainsString('Seq Scan', $encoded, $name);
            }
            $this->assertStringNotContainsString(self::OLD, $encoded);
            if (! str_ends_with($name, 'cross_currency_history') && ! str_contains($encoded, 'Seq Scan')) {
                $this->assertStringContainsString(self::WIDE, $encoded, $name);
            }
            // Even a treasury aggregation can read its own 20k rows, never all
            // other accounts. Ordered pages in the actual reader stay <=50.
            if (str_ends_with($name, '.history')) {
                $this->assertStringNotContainsString('Sort', $encoded, $name);
                $this->assertLessThanOrEqual(50, $plan['Plan']['Actual Rows']);
            }
        }
    }

    public function test_retry_and_concurrent_recreation_preserve_every_other_index(): void
    {
        $before = $this->indexes();
        $migration = $this->migration();
        $this->assertFalse($migration->withinTransaction);
        $migration->up(); $migration->up();
        $this->assertCount(6, $this->indexes());
        $migration->down(); $migration->down();
        $this->assertSame($before, $this->indexes());
        $this->assertTrue(DB::selectOne('SELECT indisvalid AND indisready AND indislive AS ready FROM pg_index WHERE indexrelid=to_regclass(?)', [self::OLD])->ready);
        $this->assertSame('1s', DB::selectOne('SHOW lock_timeout')->lock_timeout);
        $this->assertSame('10s', DB::selectOne('SHOW statement_timeout')->statement_timeout);
    }

    public function test_missing_or_unexpected_replacement_and_old_definitions_are_refused(): void
    {
        $original = $this->indexes();
        DB::statement('DROP INDEX '.self::WIDE);
        $this->refused('replacement');
        $this->assertArrayHasKey(self::OLD, $this->indexes());
        foreach ([
            'CREATE INDEX '.self::WIDE.' ON ledger_entries(account_id, account_type, currency_id, seq)',
            'CREATE INDEX '.self::WIDE." ON ledger_entries(account_type, account_id, currency_id, seq) WHERE account_type='treasury_accounts'",
            'CREATE INDEX '.self::WIDE.' ON ledger_entries(account_type DESC, account_id, currency_id, seq)',
            'CREATE INDEX '.self::WIDE.' ON ledger_entries(account_type, account_id, currency_id, seq) INCLUDE(amount)',
            'CREATE INDEX '.self::WIDE.' ON ledger_entries(account_type varchar_pattern_ops, account_id, currency_id, seq)',
            'CREATE INDEX '.self::WIDE.' ON ledger_entries(account_type COLLATE "C", account_id, currency_id, seq)',
        ] as $sql) {
            DB::statement($sql); $this->refused('Unexpected definition');
            $this->assertArrayHasKey(self::OLD, $this->indexes());
            DB::statement('DROP INDEX '.self::WIDE);
        }
        DB::statement($original[self::WIDE]);
        foreach ([
            'CREATE UNIQUE INDEX '.self::OLD.' ON ledger_entries(account_type, account_id)',
            'CREATE INDEX '.self::OLD.' ON ledger_entries(account_type, account_id DESC)',
        ] as $sql) {
            DB::statement('DROP INDEX '.self::OLD); DB::statement($sql);
            $this->refused('Unexpected definition');
            $this->assertArrayHasKey(self::OLD, $this->indexes());
        }
    }

    public function test_invalid_replacement_and_dependency_are_refused(): void
    {
        // A real interrupted concurrent drop produces the exact index definition
        // in an invalid state, without editing catalogs.
        DB::statement('DROP INDEX '.self::WIDE);
        DB::statement('CREATE INDEX '.self::WIDE.' ON ledger_entries(account_type, account_id, currency_id, seq)');
        $peer = DB::connection('ledger_index_peer');
        $peer->beginTransaction();
        $peer->select('SELECT id FROM ledger_entries LIMIT 1');
        DB::statement("SET lock_timeout='50ms'");
        try { DB::statement('DROP INDEX CONCURRENTLY '.self::WIDE); $this->fail('Held snapshot must block final drop.'); }
        catch (\Illuminate\Database\QueryException) {}
        $peer->rollBack();
        $this->assertFalse(DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid=to_regclass(?)', [self::WIDE])->indisvalid);
        $this->refused('replacement');
        $this->assertArrayHasKey(self::OLD, $this->indexes());
        DB::statement('DROP INDEX CONCURRENTLY '.self::WIDE);
        DB::statement('CREATE INDEX '.self::WIDE.' ON ledger_entries(account_type, account_id, currency_id, seq)');
        // Parsed SQL-body dependencies are recorded, unlike a quoted function body.
        DB::statement("CREATE FUNCTION fixture_index_dependency() RETURNS regclass LANGUAGE SQL RETURN 'public.".self::OLD."'::regclass");
        $this->refused('dependencies');
        DB::statement('DROP FUNCTION fixture_index_dependency()');
        $this->migration()->up();
    }

    public function test_interrupted_drop_is_retryable_and_interrupted_restore_is_rebuildable(): void
    {
        $peer = DB::connection('ledger_index_peer');
        $peer->beginTransaction();
        $peer->select('SELECT id FROM ledger_entries LIMIT 1');
        DB::statement("SET lock_timeout='50ms'");
        try { $this->migration()->up(); $this->fail('Reader must make the concurrent drop hit its lock budget.'); }
        catch (\PDOException $e) { $this->assertSame('55P03', $e->getCode()); }
        $this->assertFalse(DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid=to_regclass(?)', [self::OLD])->indisvalid);
        $this->assertSame('50ms', DB::selectOne('SHOW lock_timeout')->lock_timeout);
        $peer->rollBack();
        $this->migration()->up();
        $this->assertArrayNotHasKey(self::OLD, $this->indexes());
        // A writer held before CREATE INDEX forces its initial snapshot wait.
        $peer->beginTransaction(); $peer->statement('LOCK TABLE ledger_entries IN ROW EXCLUSIVE MODE');
        try { $this->migration()->down(); $this->fail('Writer must make the concurrent build hit its budget.'); }
        catch (\PDOException $e) { $this->assertSame('55P03', $e->getCode()); }
        $peer->rollBack();
        $this->migration()->down();
        $this->assertCount(7, $this->indexes());
        $this->assertTrue(DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid=to_regclass(?)', [self::OLD])->indisvalid);
    }

    public function test_duplicate_migration_owner_and_outer_transactions_fail_before_ddl(): void
    {
        $migration = $this->migration();
        $lock = (new \ReflectionClass($migration))->getConstant('LOCK');
        $peer = DB::connection('ledger_index_peer');
        $peer->selectOne('SELECT pg_advisory_lock(?)', [$lock]);
        try { $this->refused('Another ledger index migration'); }
        finally { $peer->selectOne('SELECT pg_advisory_unlock(?)', [$lock]); }
        $this->assertCount(7, $this->indexes());
        DB::beginTransaction();
        try { $this->refused('no open transaction'); }
        finally { DB::rollBack(); }
        $this->migration()->up();
        $this->assertCount(6, $this->indexes());
    }

    public function test_statement_budget_is_preserved_and_restore_can_recover_without_replacement(): void
    {
        $peer = DB::connection('ledger_index_peer');
        $peer->beginTransaction(); $peer->select('SELECT id FROM ledger_entries LIMIT 1');
        DB::statement("SET lock_timeout='10s'");
        DB::statement("SET statement_timeout='50ms'");
        try { $this->migration()->up(); $this->fail('Held reader must exceed the statement budget.'); }
        catch (\PDOException $e) { $this->assertSame('57014', $e->getCode()); }
        $peer->rollBack();
        $this->assertSame('10s', DB::selectOne('SHOW lock_timeout')->lock_timeout);
        $this->assertSame('50ms', DB::selectOne('SHOW statement_timeout')->statement_timeout);
        DB::statement("SET statement_timeout='10s'");
        $this->migration()->up();
        DB::statement('DROP INDEX '.self::WIDE);
        // Recovery may restore the old index even when the wider index is lost.
        $this->migration()->down();
        $this->assertArrayHasKey(self::OLD, $this->indexes());
        $this->refused('replacement');
        $this->assertArrayHasKey(self::OLD, $this->indexes());
    }

    private function refused(string $reason): void
    {
        try { $this->migration()->up(); $this->fail('Expected refusal: '.$reason); }
        catch (\RuntimeException $e) { $this->assertStringContainsString($reason, $e->getMessage()); }
    }
}
