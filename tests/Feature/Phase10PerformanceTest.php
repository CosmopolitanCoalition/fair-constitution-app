<?php

namespace Tests\Feature;

use App\Models\Economy\Currency;
use App\Models\Economy\EconomicAccount;
use App\Models\Jurisdiction;
use App\Models\User;
use App\Services\Demo\SimEconomyService;
use App\Services\Demo\Stages\StipendStage;
use App\Services\Economy\AccountService;
use App\Services\Economy\IssuanceService;
use App\Services\Economy\LedgerService;
use App\Services\Economy\StipendService;
use App\Services\SettingsResolver;
use App\Support\SimTimer;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Real stipend/ledger services in a guarded disposable PostgreSQL database. */
class Phase10PerformanceTest extends TestCase
{
    use \Tests\Support\UsesProductionLedgerIndexes;

    private ?string $fixture = null;
    private string $original;
    protected Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_SIM_INDEX_PG_TESTS') !== '1') {
            $this->markTestSkipped('Opt in to the disposable Phase 10 database.');
        }
        $this->original = DB::getDefaultConnection();
        config(['database.connections.phase10_admin' => array_replace(config('database.connections.pgsql'),
            ['database' => 'postgres', 'name' => 'phase10_admin', 'url' => null])]);
        $admin = DB::connection('phase10_admin');
        $this->assertSame('postgres', $admin->selectOne('SELECT current_database() AS name')->name);
        $this->fixture = 'phase10_test_'.bin2hex(random_bytes(8));
        $admin->statement('CREATE DATABASE '.$this->fixture.' TEMPLATE template0');
        config(['database.connections.phase10_test' => array_replace($admin->getConfig(),
            ['database' => $this->fixture, 'name' => 'phase10_test', 'url' => null])]);
        DB::setDefaultConnection('phase10_test');
        $this->assertSame($this->fixture, DB::selectOne('SELECT current_database() AS name')->name);
        foreach ([new Currency, new EconomicAccount, new Jurisdiction, new User] as $model) {
            $this->assertSame('phase10_test', $model->getConnection()->getName());
            $this->assertSame($this->fixture, $model->getConnection()->selectOne('SELECT current_database() AS name')->name);
        }
        DB::statement("SET lock_timeout = '2s'");
        DB::statement("SET statement_timeout = '15s'");
        (require base_path('database/migrations/2026_07_25_000002_create_ledger_plane.php'))->up();
        $this->installLedgerHistoryIndexes();
        foreach ([
            'CREATE TABLE jurisdictions (id uuid PRIMARY KEY, parent_id uuid, deleted_at timestamptz)',
            'CREATE TABLE users (id uuid PRIMARY KEY, email text)',
            'CREATE TABLE residency_confirmations (user_id uuid, jurisdiction_id uuid, is_active boolean)',
            'CREATE TABLE economic_accounts (id uuid PRIMARY KEY, currency_id uuid, balance numeric(24,6) NOT NULL DEFAULT 0, updated_at timestamptz, deleted_at timestamptz)',
            'CREATE TABLE economic_account_bindings (account_id uuid, owner_type text, owner_id uuid)',
            'CREATE TABLE legislatures (id uuid PRIMARY KEY, jurisdiction_id uuid, deleted_at timestamptz)',
            'CREATE TABLE legislature_members (legislature_id uuid, user_id uuid, status text, deleted_at timestamptz)',
            'CREATE TABLE constitutional_settings (jurisdiction_id uuid PRIMARY KEY, stipend_enabled boolean, civic_stipend_floor integer, stipend_bump_cap integer, pay_node_operator integer, pay_social_moderator integer, pay_office_holder integer, stipend_funding_source text)',
            'CREATE TABLE issuance_events (id uuid PRIMARY KEY, currency_id uuid, direction text, amount numeric(24,6), reason text, act_id uuid, entry_group uuid, created_at timestamptz)',
            'CREATE TABLE ubi_disbursements (id uuid PRIMARY KEY, jurisdiction_id uuid, currency_id uuid, ran_at timestamptz, recipients integer, total numeric(24,6), funding_source text, short_paid boolean, short_pay_ratio numeric(9,6), created_at timestamptz)',
            'CREATE TABLE ubi_receipts (id uuid PRIMARY KEY, disbursement_id uuid REFERENCES ubi_disbursements(id), account_id uuid, base numeric(24,6), bump numeric(24,6), amount numeric(24,6), created_at timestamptz, UNIQUE(disbursement_id, account_id))',
        ] as $statement) {
            DB::statement($statement);
        }
        DB::table('jurisdictions')->insert([
            ['id' => $this->id(1), 'parent_id' => null],
            ['id' => $this->id(2), 'parent_id' => $this->id(1)],
            ['id' => $this->id(3), 'parent_id' => $this->id(2)],
        ]);
        DB::table('currencies')->insert(['id' => $this->id(10), 'jurisdiction_id' => $this->id(1),
            'name' => 'Fixture', 'code' => 'TEST', 'symbol' => 'T']);
        DB::table('treasury_accounts')->insert(['id' => $this->id(20), 'owner_type' => 'jurisdictions',
            'owner_id' => $this->id(1), 'currency_id' => $this->id(10), 'balance' => '10000']);
        DB::table('legislatures')->insert([
            ['id' => $this->id(30), 'jurisdiction_id' => $this->id(3), 'deleted_at' => null],
            ['id' => $this->id(31), 'jurisdiction_id' => $this->id(3), 'deleted_at' => now()],
            ['id' => $this->id(32), 'jurisdiction_id' => $this->id(2), 'deleted_at' => null],
        ]);
        $this->currency = Currency::findOrFail($this->id(10));
        SimEconomyService::resetCache();
        $this->resetTimers();
    }

    protected function tearDown(): void
    {
        SimEconomyService::resetCache();
        $this->resetTimers();
        if ($this->fixture !== null) {
            while (DB::transactionLevel() > 0) { DB::rollBack(); }
            DB::disableQueryLog();
            DB::setDefaultConnection($this->original);
            DB::purge('phase10_test');
            if (! preg_match('/^phase10_test_[a-f0-9]{16}$/D', $this->fixture)) {
                throw new \LogicException('Unexpected fixture database.');
            }
            DB::connection('phase10_admin')->statement('DROP DATABASE '.$this->fixture.' WITH (FORCE)');
            DB::purge('phase10_admin');
        }
        parent::tearDown();
    }

    protected function id(int $value): string
    {
        return sprintf('10000000-0000-4000-8000-%012d', $value);
    }

    private function resetTimers(): void
    {
        foreach (['us', 'n', 'max', 'open'] as $property) {
            (new \ReflectionProperty(SimTimer::class, $property))->setValue(null, []);
        }
    }

    protected function service(): SimEconomyService
    {
        // Currency provisioning is unchanged and outside these per-scope tests.
        $sim = $this->getMockBuilder(SimEconomyService::class)
            ->setConstructorArgs([app(AccountService::class), app(StipendService::class)])
            ->onlyMethods(['ensureCurrency'])->getMock();
        $sim->method('ensureCurrency')->willReturn(['currency' => $this->currency, 'treasury_id' => $this->id(20)]);
        $this->app->instance(SimEconomyService::class, $sim);

        return $sim;
    }

    protected function resident(int $number, array $residency = [], array $account = [], string $owner = 'users', ?string $email = null): string
    {
        $user = $this->id(1000 + $number);
        $wallet = $this->id(2000 + $number);
        DB::table('users')->insert(['id' => $user, 'email' => $email ?? 'sim-'.$number.'@demo.invalid']);
        DB::table('residency_confirmations')->insert(array_replace([
            'user_id' => $user, 'jurisdiction_id' => $this->id(3), 'is_active' => true,
        ], $residency));
        DB::table('economic_accounts')->insert(array_replace(['id' => $wallet, 'currency_id' => $this->currency->id], $account));
        DB::table('economic_account_bindings')->insert(['account_id' => $wallet, 'owner_id' => $user, 'owner_type' => $owner]);

        return $wallet;
    }

    protected function member(int $number, string $status = 'seated', int $legislature = 30, bool $deleted = false): void
    {
        DB::table('legislature_members')->insert(['legislature_id' => $this->id($legislature),
            'user_id' => $this->id(1000 + $number), 'status' => $status, 'deleted_at' => $deleted ? now() : null]);
    }

    protected function settings(int $jurisdiction, array $settings): void
    {
        DB::table('constitutional_settings')->insert(['jurisdiction_id' => $this->id($jurisdiction)] + $settings);
    }

    private function recursiveQueries(array $queries): array
    {
        return array_values(array_filter($queries, fn ($query) => str_contains($query['query'], 'WITH RECURSIVE chain')));
    }

    private function receipts(string $disbursement): array
    {
        return DB::table('ubi_receipts')->where('disbursement_id', $disbursement)
            ->orderBy('account_id')->get(['account_id', 'base', 'bump', 'amount'])->map(fn ($row) => (array) $row)->all();
    }

    public function test_sample_and_role_bumps_are_unchanged_with_only_sampled_holder_ids(): void
    {
        for ($number = 35; $number >= 1; $number--) { $this->resident($number); }
        // Exclusions sort BEFORE the eligible sample, so each gate is tested.
        $this->resident(-6, ['is_active' => false]);
        $this->resident(-5, [], [], 'users', 'human@example.test');
        $this->resident(-4, [], ['currency_id' => $this->id(99)]);
        $this->resident(-3, [], ['deleted_at' => now()]);
        $this->resident(-2, [], [], 'organizations');
        $this->resident(-1, ['jurisdiction_id' => $this->id(2)]);
        $this->member(1, 'elected');
        $this->member(2);
        $this->member(2); // Duplicate office membership still grants only one bump.
        $this->member(3, 'resigned');
        $this->member(4, deleted: true);
        $this->member(5, legislature: 31);
        $this->member(6, legislature: 32);
        $this->member(30); // Serving but outside the 25-wallet sample.
        DB::table('legislature_members')->insert(['legislature_id' => $this->id(30), 'status' => 'seated']);
        $sim = $this->service();
        $commitChecks = [];
        foreach ([TransactionCommitting::class, TransactionCommitted::class] as $event) {
            $this->app['events']->listen($event, function ($event) use (&$commitChecks) {
                $ownedCommit = $event instanceof TransactionCommitting
                    ? $event->connection->transactionLevel() === 1
                    : $event->connection->transactionLevel() === 0;
                if ($ownedCommit) {
                    $commitChecks[] = [get_class($event), SimTimer::isOpen('stipend.payment'), SimTimer::isOpen('stipend.commit')];
                }
            });
        }
        $beats = 0;
        SimTimer::open('stage.stipend_scope');
        DB::enableQueryLog(); DB::flushQueryLog();
        $result = $sim->runStipendFor($this->id(3), function () use (&$beats) { $beats++; });
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        SimTimer::close('stage.stipend_scope');

        $this->assertSame(1, $beats);
        $this->assertSame(25, $result['recipients']);
        $this->assertSame('1274.000000', $result['total']);
        $this->assertFalse($result['short_paid']);
        $this->assertStringContainsString('residency_confirmations', $queries[0]['query']);
        $this->assertStringContainsString('order by "a"."id" asc limit 25', $queries[0]['query']);
        $this->assertStringContainsString('legislature_members', $queries[1]['query']);
        $this->assertStringContainsString('"m"."user_id" in (', $queries[1]['query']);
        $sampledUsers = array_map(fn ($number) => $this->id(1000 + $number), range(1, 25));
        $this->assertSame([$this->id(3), 'elected', 'seated', ...$sampledUsers], $queries[1]['bindings']);
        $this->assertCount(1, $this->recursiveQueries($queries));
        $wallets = array_map(fn ($number) => $this->id(2000 + $number), range(1, 25));
        $this->assertSame($wallets, DB::table('ledger_entries')->where('account_type', 'economic_accounts')->orderBy('seq')->pluck('account_id')->all());
        foreach ($this->receipts($result['disbursement_id']) as $index => $receipt) {
            $this->assertSame($wallets[$index], $receipt['account_id']);
            $this->assertSame('50.000000', $receipt['base']);
            $this->assertSame($index < 2 ? '12.000000' : '0.000000', $receipt['bump']);
            $this->assertSame($index < 2 ? '62.000000' : '50.000000', $receipt['amount']);
            $this->assertSame($receipt['amount'], DB::table('economic_accounts')->where('id', $receipt['account_id'])->value('balance'));
        }
        $this->assertSame(51, DB::table('ledger_entries')->count());
        $this->assertSame(1, DB::table('issuance_events')->count());
        $this->assertSame('1274.000000', app(IssuanceService::class)->supply((string) $this->currency->id));
        $this->assertSame('10000.000000', DB::table('treasury_accounts')->value('balance'));
        $this->assertSame('0.000000', DB::table('economic_accounts')->where('id', $this->id(2030))->value('balance'));
        $this->assertTrue(app(LedgerService::class)->verifyChain());
        $this->assertSame([[TransactionCommitting::class, true, true], [TransactionCommitted::class, true, true]], $commitChecks);
        $timings = (new \ReflectionProperty(SimTimer::class, 'n'))->getValue();
        foreach (['currency', 'recipients', 'office_holders', 'settings', 'payment', 'wallet_balances', 'receipts', 'commit'] as $part) {
            $this->assertSame(1, $timings['stipend.'.$part]);
        }
        $this->assertSame(2, $timings['stipend.ledger_lock_wait']);
        $this->assertSame(2, $timings['stipend.ledger_locked_post']);
        $this->assertArrayNotHasKey('training.ledger_lock_wait', $timings);
        $this->assertSame([], (new \ReflectionProperty(SimTimer::class, 'open'))->getValue());
    }

    public function test_empty_sample_skips_holders_settings_and_payment(): void
    {
        $this->member(1);
        $this->resident(1, ['is_active' => false]);
        $this->service();
        SimTimer::open('stage.stipend_scope');
        DB::enableQueryLog(); DB::flushQueryLog();
        $result = StipendStage::run($this->id(3), null, 1);
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        SimTimer::close('stage.stipend_scope');
        $this->assertSame(['ran' => false, 'recipients' => 0, 'total' => '0', 'short_paid' => false,
            'skipped' => 'no residents with wallets'], $result);
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('residency_confirmations', $queries[0]['query']);
        $this->assertSame(0, DB::table('ubi_disbursements')->count());
        $timings = (new \ReflectionProperty(SimTimer::class, 'n'))->getValue();
        $this->assertArrayNotHasKey('stipend.office_holders', $timings);
        $this->assertArrayNotHasKey('stipend.settings', $timings);
        $this->assertArrayNotHasKey('stipend.payment', $timings);
    }

    public function test_prefetch_matches_nearest_per_column_settings_and_reduces_seven_walks_to_one(): void
    {
        $wallets = [$this->resident(1), $this->resident(2), $this->resident(3)];
        $this->settings(1, ['stipend_enabled' => true, 'civic_stipend_floor' => 70, 'stipend_bump_cap' => 20,
            'pay_node_operator' => 8, 'pay_social_moderator' => 5, 'pay_office_holder' => 12, 'stipend_funding_source' => 'treasury_draw']);
        $this->settings(2, ['stipend_bump_cap' => 9, 'pay_social_moderator' => 0]);
        $this->settings(3, ['civic_stipend_floor' => 0, 'pay_office_holder' => 4]);
        $recipients = [
            ['account_id' => $wallets[0], 'roles' => ['node_operator', 'social_moderator', 'office_holder', 'office_holder']],
            ['account_id' => $wallets[1], 'roles' => ['office_holder']],
            ['account_id' => $wallets[2], 'roles' => []],
        ];
        $cold = app(StipendService::class);
        DB::enableQueryLog(); DB::flushQueryLog();
        $before = $cold->run($this->id(3), $this->currency, $recipients, $this->id(20));
        $coldQueries = DB::getQueryLog(); DB::disableQueryLog();
        // A separate service proves warming its actual injected resolver works.
        $warm = app(StipendService::class);
        DB::enableQueryLog(); DB::flushQueryLog();
        $warm->warmSettingsForRun($this->id(3));
        $after = $warm->run($this->id(3), $this->currency, $recipients, $this->id(20));
        $warmQueries = DB::getQueryLog();
        DB::flushQueryLog();
        $warm->warmSettingsForRun($this->id(3));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(7, $this->recursiveQueries($coldQueries));
        $this->assertCount(1, $this->recursiveQueries($warmQueries));
        $this->assertCount(count($coldQueries) - 6, $warmQueries);
        $this->assertSame($this->receipts($before['disbursement_id']), $this->receipts($after['disbursement_id']));
        unset($before['disbursement_id'], $after['disbursement_id']);
        $this->assertSame($before, $after);
        $this->assertSame(['recipients' => 3, 'total' => '13.000000', 'short_paid' => false], $after);
        $this->assertSame(0, DB::table('issuance_events')->count());
        $this->assertSame([], (new \ReflectionProperty(SimTimer::class, 'n'))->getValue());
    }

    public function test_null_settings_keep_defaults_on_the_actual_resolver(): void
    {
        $wallet = $this->resident(1);
        $this->settings(1, []);
        $this->settings(2, []);
        $this->settings(3, []);
        $resolver = new SettingsResolver;
        $stipend = new StipendService(app(LedgerService::class), app(IssuanceService::class), app(AccountService::class), $resolver);
        DB::enableQueryLog(); DB::flushQueryLog();
        $stipend->warmSettingsForRun($this->id(3));
        $this->assertNull($resolver->resolve($this->id(3), 'civic_stipend_floor'));
        $result = $stipend->run($this->id(3), $this->currency,
            [['account_id' => $wallet, 'roles' => ['node_operator', 'social_moderator', 'office_holder']]], $this->id(20));
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertCount(1, $this->recursiveQueries($queries));
        $this->assertSame('70.000000', $result['total']);
        $this->assertSame('minted', DB::table('ubi_disbursements')->value('funding_source'));
    }

    public function test_disabled_inherited_setting_still_rejects_before_any_payment_and_closes_timers(): void
    {
        $this->resident(1);
        $this->settings(1, ['stipend_enabled' => false]);
        $this->settings(3, ['civic_stipend_floor' => 123]);
        $this->service();
        SimTimer::open('stage.stipend_scope');
        DB::enableQueryLog(); DB::flushQueryLog();
        try {
            StipendStage::run($this->id(3), null, 1);
            $this->fail('Disabled stipend must still reject.');
        } catch (\RuntimeException $error) {
            $this->assertSame('The civic stipend is disabled for this jurisdiction.', $error->getMessage());
        } finally {
            SimTimer::close('stage.stipend_scope');
        }
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertCount(1, $this->recursiveQueries($queries));
        $this->assertSame(0, DB::table('ubi_disbursements')->count());
        $this->assertSame(0, DB::table('ledger_entries')->count());
        $this->assertSame(0, DB::table('issuance_events')->count());
        $this->assertSame([], (new \ReflectionProperty(SimTimer::class, 'open'))->getValue());
        $this->assertSame(1, (new \ReflectionProperty(SimTimer::class, 'n'))->getValue()['stipend.disburse']);
    }

    public function test_treasury_draw_short_pay_ratio_and_wallet_receipts_are_unchanged(): void
    {
        $first = $this->resident(1);
        $second = $this->resident(2);
        $this->member(2);
        $this->settings(1, ['stipend_funding_source' => 'treasury_draw']);
        DB::table('treasury_accounts')->update(['balance' => 28]);
        $result = $this->service()->runStipendFor($this->id(3));
        $this->assertSame(2, $result['recipients']);
        $this->assertTrue($result['short_paid']);
        $this->assertSame('28.000000', $result['total']);
        $this->assertSame('0.250000', DB::table('ubi_disbursements')->value('short_pay_ratio'));
        $this->assertSame('12.500000', DB::table('economic_accounts')->where('id', $first)->value('balance'));
        $this->assertSame('15.500000', DB::table('economic_accounts')->where('id', $second)->value('balance'));
        $this->assertSame('0.000000', DB::table('treasury_accounts')->value('balance'));
        $this->assertSame(0, DB::table('issuance_events')->count());
        $this->assertTrue(app(LedgerService::class)->verifyChain());
        $this->assertSame([], (new \ReflectionProperty(SimTimer::class, 'n'))->getValue());
    }

    public function test_wallet_failure_rolls_back_mint_disbursement_and_receipts_in_existing_transaction(): void
    {
        $wallet = $this->resident(1);
        DB::statement('ALTER TABLE economic_accounts ADD CONSTRAINT fixture_wallet CHECK (balance = 0)');
        $this->service();
        SimTimer::open('stage.stipend_scope');
        try {
            StipendStage::run($this->id(3), null, 1);
            $this->fail('Wallet failure must propagate.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('fixture_wallet', $error->getMessage());
        } finally {
            SimTimer::close('stage.stipend_scope');
        }
        foreach (['ledger_entries', 'issuance_events', 'ubi_disbursements', 'ubi_receipts'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertSame('0.000000', DB::table('economic_accounts')->where('id', $wallet)->value('balance'));
        $this->assertSame('10000.000000', DB::table('treasury_accounts')->value('balance'));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame([], (new \ReflectionProperty(SimTimer::class, 'open'))->getValue());
        $this->assertSame(1, (new \ReflectionProperty(SimTimer::class, 'n'))->getValue()['stipend.payment']);
    }

    public function test_outer_rollback_and_repeated_scope_disbursement_contract_are_preserved(): void
    {
        $wallet = $this->resident(1);
        $sim = $this->service();
        DB::beginTransaction();
        SimTimer::open('stage.stipend_scope');
        $sim->runStipendFor($this->id(3));
        SimTimer::close('stage.stipend_scope');
        $this->assertArrayNotHasKey('stipend.commit', (new \ReflectionProperty(SimTimer::class, 'n'))->getValue());
        $this->assertSame(1, DB::transactionLevel());
        DB::rollBack();
        foreach (['ledger_entries', 'issuance_events', 'ubi_disbursements', 'ubi_receipts'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertSame('0.000000', DB::table('economic_accounts')->where('id', $wallet)->value('balance'));
        // Step 5 has always paid a re-handed scope again; no new once-only gate.
        $first = $sim->runStipendFor($this->id(3));
        $second = $sim->runStipendFor($this->id(3));
        $this->assertNotSame($first['disbursement_id'], $second['disbursement_id']);
        $this->assertSame(2, DB::table('ubi_disbursements')->count());
        $this->assertSame('100.000000', DB::table('economic_accounts')->where('id', $wallet)->value('balance'));
        $this->assertTrue(app(LedgerService::class)->verifyChain());
    }

    public function test_failure_after_mint_before_wallet_credit_rolls_back_the_owned_payment(): void
    {
        $wallet = $this->resident(1);
        $accounts = $this->createMock(AccountService::class);
        $accounts->method('creditManyFromTreasury')->willReturnCallback(function () {
            $this->assertSame(1, DB::table('ledger_entries')->count());
            $this->assertSame(1, DB::table('issuance_events')->count());
            throw new \RuntimeException('Fixture failure after mint');
        });
        $this->app->instance(AccountService::class, $accounts);
        $sim = $this->service();
        SimTimer::open('stage.stipend_scope');
        try { $sim->runStipendFor($this->id(3)); $this->fail('Expected injected failure'); }
        catch (\RuntimeException $error) { $this->assertSame('Fixture failure after mint', $error->getMessage()); }
        finally { SimTimer::close('stage.stipend_scope'); }
        $this->assertPaymentRolledBack($wallet);
    }

    public function test_receipt_failure_rolls_back_mint_wallet_and_disbursement_and_closes_timers(): void
    {
        $wallet = $this->resident(1);
        DB::statement('ALTER TABLE ubi_receipts ADD CONSTRAINT fixture_receipt CHECK (amount = 0)');
        $sim = $this->service();
        SimTimer::open('stage.stipend_scope');
        try { $sim->runStipendFor($this->id(3)); $this->fail('Expected receipt rejection'); }
        catch (\Illuminate\Database\QueryException $error) { $this->assertStringContainsString('fixture_receipt', $error->getMessage()); }
        finally { SimTimer::close('stage.stipend_scope'); }
        $this->assertPaymentRolledBack($wallet);
        $this->assertSame(1, (new \ReflectionProperty(SimTimer::class, 'n'))->getValue()['stipend.receipts']);
    }

    private function assertPaymentRolledBack(string $wallet): void
    {
        foreach (['ledger_entries', 'issuance_events', 'ubi_disbursements', 'ubi_receipts'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertSame('0.000000', DB::table('economic_accounts')->where('id', $wallet)->value('balance'));
        $this->assertSame('10000.000000', DB::table('treasury_accounts')->value('balance'));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame([], (new \ReflectionProperty(SimTimer::class, 'open'))->getValue());
        $this->assertArrayNotHasKey('stipend.commit', (new \ReflectionProperty(SimTimer::class, 'n'))->getValue());
    }
}
