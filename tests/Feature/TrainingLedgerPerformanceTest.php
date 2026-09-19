<?php

namespace Tests\Feature;

use App\Domain\Forms\Handlers\TrainingCompletion;
use App\Models\Economy\Currency;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Economy\AccountService;
use App\Services\Economy\IssuanceService;
use App\Services\Economy\LedgerService;
use App\Services\Education\TrainingStipendService;
use App\Services\SettingsResolver;
use App\Support\SimTimer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Real ledger DDL and services; all writes are in a guarded nonce database. */
class TrainingLedgerPerformanceTest extends TestCase
{
    private ?string $fixture = null;
    private string $original;
    private array $children = [];
    private string $root;
    private string $currency;
    private string $treasury;
    private string $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_SIM_INDEX_PG_TESTS') !== '1') {
            $this->markTestSkipped('Opt in to the disposable ledger database.');
        }
        $this->original = DB::getDefaultConnection();
        config(['database.connections.ledger_admin' => array_replace(config('database.connections.pgsql'),
            ['database' => 'postgres', 'name' => 'ledger_admin', 'url' => null])]);
        $admin = DB::connection('ledger_admin');
        $this->assertSame('postgres', $admin->selectOne('SELECT current_database() AS name')->name);
        $this->fixture = 'ledger_test_'.bin2hex(random_bytes(8));
        $admin->statement('CREATE DATABASE '.$this->fixture.' TEMPLATE template0');
        config(['database.connections.ledger_test' => array_replace($admin->getConfig(),
            ['database' => $this->fixture, 'name' => 'ledger_test', 'url' => null])]);
        DB::setDefaultConnection('ledger_test');
        $this->assertSame($this->fixture, DB::selectOne('SELECT current_database() AS name')->name);
        foreach ([new Currency, new User] as $model) {
            $this->assertSame('ledger_test', $model->getConnection()->getName());
            $this->assertSame($this->fixture, $model->getConnection()->selectOne('SELECT current_database() AS name')->name);
        }
        DB::statement("SET lock_timeout = '10s'");
        DB::statement("SET statement_timeout = '15s'");
        (require base_path('database/migrations/2026_07_25_000002_create_ledger_plane.php'))->up();
        DB::statement('CREATE TABLE jurisdictions (id uuid PRIMARY KEY, parent_id uuid, deleted_at timestamptz)');
        DB::statement('CREATE TABLE economic_accounts (id uuid PRIMARY KEY, currency_id uuid, balance numeric(24,6) NOT NULL DEFAULT 0, updated_at timestamptz, deleted_at timestamptz)');
        DB::statement('CREATE TABLE issuance_events (id uuid PRIMARY KEY, currency_id uuid, direction text, amount numeric(24,6), reason text, act_id uuid, entry_group uuid, created_at timestamptz)');
        $this->root = (string) Str::uuid();
        $this->currency = (string) Str::uuid();
        $this->treasury = (string) Str::uuid();
        $this->wallet = (string) Str::uuid();
        DB::table('jurisdictions')->insert(['id' => $this->root]);
        DB::table('currencies')->insert(['id' => $this->currency, 'jurisdiction_id' => $this->root, 'name' => 'Fixture', 'code' => 'TEST', 'symbol' => 'T']);
        DB::table('treasury_accounts')->insert(['id' => $this->treasury, 'owner_type' => 'jurisdictions', 'owner_id' => $this->root, 'currency_id' => $this->currency, 'balance' => '10000']);
        DB::table('economic_accounts')->insert(['id' => $this->wallet, 'currency_id' => $this->currency]);
        $this->resetTimers();
    }

    protected function tearDown(): void
    {
        foreach ($this->children as [$process, $pipes]) {
            if (is_resource($process)) { proc_terminate($process); }
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            if (is_resource($process)) { proc_close($process); }
        }
        TrainingStipendService::resetBatch();
        $this->resetTimers();
        if ($this->fixture !== null) {
            while (DB::transactionLevel() > 0) { DB::rollBack(); }
            DB::setDefaultConnection($this->original);
            DB::purge('ledger_test');
            if (! preg_match('/^ledger_test_[a-f0-9]{16}$/D', $this->fixture)) { throw new \LogicException('Unexpected fixture.'); }
            DB::connection('ledger_admin')->statement('DROP DATABASE '.$this->fixture.' WITH (FORCE)');
            DB::purge('ledger_admin');
        }
        parent::tearDown();
    }

    private function resetTimers(): void
    {
        foreach (['us', 'n', 'max', 'open'] as $property) {
            (new \ReflectionProperty(SimTimer::class, $property))->setValue(null, []);
        }
    }

    private function credit(array $amounts): ?string
    {
        return app(AccountService::class)->creditManyFromTreasury($this->treasury,
            array_map(fn ($amount) => ['account_id' => $this->wallet, 'amount' => $amount], $amounts),
            $this->currency, 'stipend');
    }

    private function assertBalances(string $treasury, string $wallet): void
    {
        $this->assertSame(0, bccomp($treasury, DB::table('treasury_accounts')->where('id', $this->treasury)->value('balance'), 6));
        $this->assertSame(0, bccomp($wallet, DB::table('economic_accounts')->where('id', $this->wallet)->value('balance'), 6));
    }

    private function assertChain(): void
    {
        $previous = AuditService::GENESIS_PREV_HASH;
        foreach (DB::table('ledger_entries')->orderBy('seq')->get() as $row) {
            $this->assertSame($previous, $row->prev_hash);
            $payload = (array) $row;
            unset($payload['id'], $payload['seq'], $payload['prev_hash'], $payload['hash'], $payload['created_at']);
            $payload['amount'] = rtrim(rtrim($payload['amount'], '0'), '.');
            $this->assertSame(hash('sha256', $previous.AuditService::canonicalJson($payload)), $row->hash);
            $previous = $row->hash;
        }
        $this->assertTrue(app(LedgerService::class)->verifyChain());
    }

    public function test_training_credit_preserves_amounts_order_and_balances_with_one_less_query(): void
    {
        DB::enableQueryLog(); DB::flushQueryLog();
        $group = $this->credit(['10.000000', 2.5, '0.000001', '0', '-1']);
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        // Previously five: lock, head, insert, treasury, wallets. Now four.
        $this->assertCount(4, $queries);
        $this->assertStringContainsString('pg_advisory_xact_lock', $queries[0]['query']);
        $this->assertStringContainsString('ORDER BY seq DESC', $queries[1]['query']);
        $this->assertStringContainsString('WITH appended AS', $queries[2]['query']);
        $this->assertStringContainsString('UPDATE economic_accounts', $queries[3]['query']);
        $rows = DB::table('ledger_entries')->orderBy('seq')->get();
        $this->assertCount(6, $rows);
        $this->assertSame(['debit', 'credit', 'debit', 'credit', 'debit', 'credit'], $rows->pluck('direction')->all());
        $this->assertSame([$group], $rows->pluck('entry_group')->unique()->values()->all());
        $this->assertSame('0.000000', app(LedgerService::class)->imbalanceByCurrency()[$this->currency]);
        $this->assertBalances('9987.499999', '12.500001');
        $this->assertChain();
        $this->assertSame([], (new \ReflectionProperty(SimTimer::class, 'n'))->getValue());
    }

    public function test_append_across_multiple_chunks_updates_the_treasury_once(): void
    {
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->credit(array_fill(0, 251, '1.25'));
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertCount(5, $queries);
        $this->assertSame(502, DB::table('ledger_entries')->count());
        $this->assertBalances('9686.25', '313.75');
        $this->assertChain();
    }

    public function test_multi_treasury_and_wallet_only_postings_keep_the_existing_behavior(): void
    {
        $other = (string) Str::uuid();
        DB::table('treasury_accounts')->insert(['id' => $other, 'owner_type' => 'jurisdictions', 'owner_id' => (string) Str::uuid(), 'currency_id' => $this->currency, 'balance' => '0']);
        $leg = ['account_type' => 'treasury_accounts', 'currency_id' => $this->currency, 'amount' => '2.500000'];
        app(LedgerService::class)->post('transfer', [
            $leg + ['account_id' => $this->treasury, 'direction' => 'debit'],
            $leg + ['account_id' => $other, 'direction' => 'credit'],
        ], 'Łódź / 日本語', (string) Str::uuid());
        $this->assertSame('2.500000', DB::table('treasury_accounts')->where('id', $other)->value('balance'));
        $leg['account_type'] = 'economic_accounts';
        app(LedgerService::class)->post('transfer', [
            $leg + ['account_id' => $this->wallet, 'direction' => 'debit'],
            $leg + ['account_id' => (string) Str::uuid(), 'direction' => 'credit'],
        ]);
        $this->assertBalances('9997.5', '0'); // Ledger never applies wallet balances itself.
        $this->assertChain();
    }

    public function test_outer_rollback_removes_posting_and_both_balances(): void
    {
        DB::beginTransaction();
        $this->credit(['3']);
        $this->assertSame(1, DB::transactionLevel());
        $this->assertBalances('9997', '3');
        DB::rollBack();
        $this->assertSame(0, DB::table('ledger_entries')->count());
        $this->assertBalances('10000', '0');
        $this->credit(['4']);
        $this->assertChain();
    }

    public function test_mint_burn_and_issuance_records_share_the_existing_transaction(): void
    {
        $currency = Currency::findOrFail($this->currency);
        $issuance = app(IssuanceService::class);
        $issuance->mint($currency, $this->treasury, '10.000000', 'fixture mint');
        $issuance->burn($currency, $this->treasury, '2.500000', 'fixture burn');
        $this->assertBalances('10007.5', '0');
        $this->assertSame('7.500000', $issuance->supply($this->currency));
        $this->assertSame('-7.500000', app(LedgerService::class)->imbalanceByCurrency()[$this->currency]);
        DB::beginTransaction();
        $issuance->mint($currency, $this->treasury, '3', 'rolled back mint');
        $this->credit(['3']);
        DB::rollBack();
        $this->assertSame(2, DB::table('ledger_entries')->count());
        $this->assertSame(2, DB::table('issuance_events')->count());
        $this->assertBalances('10007.5', '0');
        $this->assertChain();
    }

    public function test_treasury_failure_rolls_back_even_earlier_append_chunks(): void
    {
        DB::statement('ALTER TABLE treasury_accounts ADD CONSTRAINT fixture_balance CHECK (balance >= 10000)');
        try { $this->credit(array_fill(0, 251, '1')); $this->fail('Treasury failure must propagate.'); }
        catch (\Illuminate\Database\QueryException $e) { $this->assertStringContainsString('fixture_balance', $e->getMessage()); }
        $this->assertSame(0, DB::table('ledger_entries')->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertBalances('10000', '0');
    }

    public function test_wallet_failure_rolls_back_append_and_treasury_and_closes_timers(): void
    {
        DB::statement('ALTER TABLE economic_accounts ADD CONSTRAINT fixture_wallet CHECK (balance = 0)');
        SimTimer::open('stage.training_scope');
        try { $this->credit(['1']); $this->fail('Wallet failure must propagate.'); }
        catch (\Illuminate\Database\QueryException $e) { $this->assertStringContainsString('fixture_wallet', $e->getMessage()); }
        SimTimer::close('stage.training_scope');
        $this->assertSame(0, DB::table('ledger_entries')->count());
        $this->assertBalances('10000', '0');
        $this->assertSame([], (new \ReflectionProperty(SimTimer::class, 'open'))->getValue());
        $samples = (new \ReflectionProperty(SimTimer::class, 'n'))->getValue();
        foreach (['training.ledger_lock_wait', 'training.ledger_locked_post', 'training.wallet_balances'] as $part) {
            $this->assertSame(1, $samples[$part]);
        }
    }

    public function test_validation_and_database_immutability_remain_enforced(): void
    {
        try {
            app(LedgerService::class)->post('stipend', [['account_type' => 'treasury_accounts', 'account_id' => $this->treasury, 'currency_id' => $this->currency, 'direction' => 'debit', 'amount' => '1']]);
            $this->fail('An unbalanced posting must fail.');
        } catch (\InvalidArgumentException $e) { $this->assertStringContainsString('Unbalanced', $e->getMessage()); }
        $this->assertSame(0, DB::table('ledger_entries')->count());
        $this->credit(['1']);
        foreach (["UPDATE ledger_entries SET amount=2", 'DELETE FROM ledger_entries', 'TRUNCATE ledger_entries'] as $sql) {
            try { DB::statement($sql); $this->fail('Mutation must fail.'); }
            catch (\Illuminate\Database\QueryException $e) { $this->assertStringContainsString('append-only', $e->getMessage()); }
        }
        $this->assertChain();
    }

    public function test_waiting_writers_use_the_committed_head_and_preserve_shared_balances(): void
    {
        DB::beginTransaction();
        DB::statement('SELECT pg_advisory_xact_lock(?)', [LedgerService::APPEND_LOCK_KEY]);
        $pids = [];
        for ($i = 0; $i < 2; $i++) {
            $process = proc_open([PHP_BINARY, base_path('tests/Support/training_ledger_worker.php')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process); $this->children[] = [$process, $pipes];
            fwrite($pipes[0], json_encode(['connection' => DB::connection()->getConfig(), 'treasury' => $this->treasury, 'wallet' => $this->wallet, 'currency' => $this->currency], JSON_THROW_ON_ERROR)."\n");
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
        $this->assertSame(2, $waiting, 'Both writers must wait before the parent changes the head.');
        $this->credit(['3']);
        DB::commit();
        foreach ($this->children as $index => [$process, $pipes]) {
            $this->assertSame("DONE\n", fgets($pipes[1]));
            fclose($pipes[0]); fclose($pipes[1]);
            $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors);
            unset($this->children[$index]);
        }
        $this->assertSame(50, DB::table('ledger_entries')->count());
        $this->assertBalances('9973', '27');
        $this->assertChain();
    }

    public function test_real_training_handler_and_batch_pay_once_across_retakes(): void
    {
        $schema = file_get_contents(base_path('database/schema/pgsql-schema.sql'));
        preg_match('/CREATE TABLE public.audit_log \([\s\S]*?\n\);/', $schema, $table);
        $this->assertNotEmpty($table);
        DB::unprepared($table[0]);
        DB::statement('CREATE SEQUENCE fixture_audit_seq');
        DB::statement("ALTER TABLE audit_log ALTER COLUMN seq SET DEFAULT nextval('fixture_audit_seq')");
        $json = AuditService::canonicalJson(['genesis' => true]);
        DB::table('audit_log')->insert(['module' => 'fixture', 'event' => 'genesis', 'payload' => $json, 'prev_hash' => AuditService::GENESIS_PREV_HASH, 'hash' => AuditService::chainHash(AuditService::GENESIS_PREV_HASH, $json), 'occurred_at' => now(), 'created_at' => now()]);
        DB::statement('CREATE TABLE achievements (id uuid DEFAULT gen_random_uuid(), user_id uuid, award_key text, title text, audit_seq bigint, earned_at timestamptz, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz, UNIQUE(user_id, award_key))');
        DB::statement('CREATE TABLE education_tracks (id uuid PRIMARY KEY, key text, status text, deleted_at timestamptz)');
        DB::statement('CREATE TABLE education_modules (id uuid PRIMARY KEY, track_id uuid, key text, status text, deleted_at timestamptz)');
        DB::statement('CREATE TABLE education_progress (user_id uuid, module_id uuid, state text, score_pct int, completed_at timestamptz, created_at timestamptz, updated_at timestamptz, UNIQUE(user_id, module_id))');
        DB::statement('CREATE TABLE residency_confirmations (user_id uuid, jurisdiction_id uuid, is_active boolean, confirmed_at timestamptz)');
        DB::statement('CREATE TABLE economic_account_bindings (account_id uuid, owner_id uuid, owner_type text)');
        $track = (string) Str::uuid();
        DB::table('education_tracks')->insert(['id' => $track, 'key' => 'fixture', 'status' => 'live']);
        DB::table('education_modules')->insert(['id' => (string) Str::uuid(), 'track_id' => $track, 'key' => 'first', 'status' => 'live']);
        $learner = new User;
        $learner->id = (string) Str::uuid();
        DB::table('residency_confirmations')->insert(['user_id' => $learner->id, 'jurisdiction_id' => $this->root, 'is_active' => true, 'confirmed_at' => now()]);
        DB::table('economic_account_bindings')->insert(['account_id' => $this->wallet, 'owner_id' => $learner->id, 'owner_type' => 'users']);
        $this->mock(SettingsResolver::class, function ($mock) {
            $mock->shouldReceive('resolveInt')->once()->andReturn(10);
            $mock->shouldReceive('resolve')->once()->andReturn('minted');
        });
        $stipend = app(TrainingStipendService::class);
        $handler = app(TrainingCompletion::class);
        $payload = ['track_key' => 'fixture', 'module_key' => 'first', 'passed' => true, 'score_pct' => 100];
        SimTimer::open('stage.training_scope');
        for ($pass = 0; $pass < 2; $pass++) {
            $stipend->beginBatch();
            DB::transaction(fn () => $handler->handle($learner, $payload));
            DB::transaction(fn () => $handler->handle($learner, $payload));
            $stipend->commitBatch();
            $stipend->commitBatch(); // An already-emptied buffer cannot pay again.
        }
        SimTimer::close('stage.training_scope');
        $this->assertBalances('10000', '10');
        $this->assertSame(1, DB::table('achievements')->count());
        $this->assertSame(1, DB::table('issuance_events')->count());
        $this->assertSame('10.000000', DB::table('issuance_events')->value('amount'));
        $this->assertSame(3, DB::table('ledger_entries')->count());
        $this->assertChain();
        $samples = (new \ReflectionProperty(SimTimer::class, 'n'))->getValue();
        $this->assertSame(2, $samples['training.ledger_lock_wait']);
        $this->assertSame(2, $samples['training.ledger_locked_post']);
        foreach (['training.stipend_mint', 'training.stipend_credit', 'training.wallet_balances'] as $part) {
            $this->assertSame(1, $samples[$part]);
        }
        $this->assertSame([], (new \ReflectionProperty(SimTimer::class, 'open'))->getValue());
    }
}
