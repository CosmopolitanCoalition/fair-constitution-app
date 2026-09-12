<?php

namespace Tests\Unit;

use App\Http\Controllers\Economy\EconomyController;
use App\Jobs\RefreshCurrencyReportJob;
use App\Services\Economy\AccountService;
use App\Services\Economy\CurrencyReportService;
use App\Services\Economy\CurrencyTelemetryService;
use App\Services\Economy\IssuanceService;
use App\Services\Economy\LedgerService;
use App\Services\SettingsResolver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Small, explicit SQLite memory fixtures. No live connection, host sizing or queue work. */
final class CurrencyReportTest extends TestCase
{
    private const CONNECTION = 'currency_report_fixture';
    private const START = '2026-09-12 12:00:00';

    private string $originalConnection;
    private CurrencyReportService $reports;
    private string $currency;
    private int $expectedReportDispatches = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.'.self::CONNECTION => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection(self::CONNECTION);
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        Carbon::setTestNow(Carbon::parse(self::START, 'UTC'));
        Bus::fake();

        $this->reports = $this->getMockBuilder(CurrencyReportService::class)
            ->onlyMethods(['batchSize'])->getMock();
        $this->reports->expects($this->never())->method('batchSize');
        $this->createSourceTables();
        // This migration creates only these test report tables on SQLite;
        // its production index statements are PostgreSQL-only.
        $migration = require database_path('migrations/2026_09_12_180000_currency_reports.php');
        $migration->up();
        $this->currency = $this->id(1000);
        DB::table('currencies')->insert(['id' => $this->currency]);
    }

    protected function tearDown(): void
    {
        if ($this->expectedReportDispatches === 0) Bus::assertNothingDispatched();
        else Bus::assertDispatchedTimes(RefreshCurrencyReportJob::class, $this->expectedReportDispatches);
        Carbon::setTestNow();
        DB::disconnect(self::CONNECTION);
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_start_read_failure_and_resume_preserve_the_committed_checkpoint(): void
    {
        self::assertSame('not_started', $this->reports->read($this->currency)['status']);
        self::assertNull($this->reports->read($this->currency)['data']);
        $this->wallet(1, '10');
        $this->wallet(2, '20');
        $this->wallet(3, '30');
        $run = $this->reports->start($this->currency);
        self::assertSame($run, $this->reports->start($this->currency));
        self::assertSame('running', $this->reports->read($this->currency)['status']);
        self::assertNull($this->reports->read($this->currency)['data']);

        $this->reachPhase($run, 'wallets');
        self::assertTrue($this->reports->advance($this->currency, $run, 1));
        $checkpoint = $this->stored()->checkpoint;
        $totals = $this->stored()->totals;
        self::assertSame(1, json_decode($totals, true)['wallets']);
        $this->reports->fail($this->currency, $run, $this->revision());
        self::assertSame('failed', $this->reports->read($this->currency)['status']);
        self::assertFalse($this->reports->advance($this->currency, $run, 1));
        self::assertSame($checkpoint, $this->stored()->checkpoint);
        self::assertSame($totals, $this->stored()->totals);

        self::assertSame($run, $this->reports->start($this->currency));
        $resumedPoint = json_decode($this->stored()->checkpoint, true);
        $oldPoint = json_decode($checkpoint, true);
        self::assertSame($oldPoint['revision'] + 1, $resumedPoint['revision']);
        self::assertSame(array_diff_key($oldPoint, ['revision' => true]), array_diff_key($resumedPoint, ['revision' => true]));
        $this->finish($run, 1);
        $published = $this->reports->read($this->currency);
        self::assertSame('complete', $published['status']);
        self::assertSame(3, $published['data']['wallets']);
        self::assertSame('60.000000', $published['data']['in_circulation']);
        self::assertSame('50.00', $published['data']['top_decile_share_pct']);
        self::assertFalse($this->reports->advance($this->currency, $run, 1));
        self::assertSame(0, DB::table('currency_report_balances')->count());
    }

    public function test_histogram_uses_collected_balances_and_takes_only_needed_wallets_from_tied_boundary(): void
    {
        // Eleven funded wallets require two top wallets: 100 + one of the
        // three tied 50s, not the entire tied bucket. The zero still counts.
        $this->wallet(1, '100', ['kind' => 'organization', 'status' => 'frozen']);
        foreach ([2, 3, 4] as $id) $this->wallet($id, '50', ['kind' => 'joint_ledger']);
        foreach (range(5, 11) as $id) $this->wallet($id, '10');
        $this->wallet(12, '0');
        $this->wallet(13, '999999', ['deleted_at' => self::START]);
        $other = $this->id(2000);
        DB::table('currencies')->insert(['id' => $other]);
        $this->wallet(14, '999999', ['currency_id' => $other]);
        $this->treasury(1, '11.125000');
        $this->treasury(2, '0.000001');
        $this->treasury(3, '99999', ['deleted_at' => self::START]);
        $this->treasury(4, '99999', ['currency_id' => $other]);

        $run = $this->reports->start($this->currency);
        $this->reachPhase($run, 'treasuries', 2);
        self::assertSame(11, (int) DB::table('currency_report_balances')->where('run_id', $run)->sum('wallets'));
        // A later source balance must not silently change the already
        // collected histogram or denominator during concentration.
        DB::table('economic_accounts')->where('id', $this->id(1))->update(['balance' => '1000']);
        $this->finish($run, 2);
        $data = $this->reports->read($this->currency)['data'];
        self::assertSame(12, $data['wallets']);
        self::assertSame(11, $data['funded_wallets']);
        self::assertSame('320.000000', $data['in_circulation']);
        self::assertSame('11.125001', $data['treasury_held']);
        self::assertSame('46.87', $data['top_decile_share_pct']);
        self::assertNull($data['per_jurisdiction']);
        self::assertNull($data['time_series']);
    }

    public function test_decimal_supply_and_transaction_window_stay_fixed_while_collection_runs(): void
    {
        $this->issuance(1, 'mint', '100.000003', '2026-08-01 12:00:00');
        $this->issuance(2, 'burn', '0.000001', '2026-09-12 11:59:59');
        $this->issuance(3, 'mint', '9999', '2026-09-12 12:00:01');
        $this->transaction(1, '25.000000', '2026-08-13 12:00:00');
        $this->transaction(2, '0.000001', '2026-09-12 11:59:59');
        $this->transaction(3, '9999', '2026-08-13 11:59:59');
        $this->transaction(4, '9999', '2026-09-12 12:00:01');
        $this->issuance(5, 'mint', '9999', '2026-08-01 12:00:00', $this->id(2000));
        $this->transaction(5, '9999', '2026-09-12 11:59:59', $this->id(2000));
        $run = $this->reports->start($this->currency);
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', 'UTC'));
        $this->finish($run, 1);
        $published = $this->reports->read($this->currency);
        self::assertSame('100.000002', $published['data']['supply']);
        self::assertSame('0.2500', $published['data']['velocity_30d']);
        self::assertSame('25.000001', json_decode($this->stored()->totals, true)['volume']);
        self::assertSame(self::START, $published['started_at']);
        self::assertSame('2026-10-22 12:00:00', $published['completed_at']);
    }

    public function test_previous_publication_survives_refresh_and_failure_and_old_jobs_cannot_change_new_run(): void
    {
        $this->wallet(1, '20');
        $firstRun = $this->reports->start($this->currency);
        $this->finish($firstRun, 1);
        $first = $this->reports->read($this->currency);
        Carbon::setTestNow(Carbon::parse('2026-09-13 12:00:00', 'UTC'));
        $this->wallet(2, '80');
        $secondRun = $this->reports->start($this->currency);
        self::assertNotSame($firstRun, $secondRun);
        self::assertSame($first['data'], $this->reports->read($this->currency)['data']);
        self::assertSame($first['completed_at'], $this->reports->read($this->currency)['completed_at']);

        $beforeStale = (array) $this->stored();
        self::assertFalse($this->reports->advance($this->currency, $firstRun, 1));
        $this->reports->fail($this->currency, $firstRun, $this->revision());
        self::assertSame($beforeStale, (array) $this->stored());
        $this->reachPhase($secondRun, 'wallets');
        $this->reports->advance($this->currency, $secondRun, 1);
        $this->reports->fail($this->currency, $secondRun, $this->revision());
        $failed = $this->reports->read($this->currency);
        self::assertSame('failed', $failed['status']);
        self::assertSame($first['data'], $failed['data']);
        self::assertSame($first['started_at'], $failed['started_at']);
        self::assertSame($secondRun, $this->reports->start($this->currency));
        $this->finish($secondRun, 1);
        $second = $this->reports->read($this->currency);
        self::assertSame('100.000000', $second['data']['in_circulation']);
        self::assertSame('80.00', $second['data']['top_decile_share_pct']);
        self::assertSame('2026-09-13 12:00:00', $second['started_at']);
    }

    public function test_interruption_after_checkpoint_update_rolls_back_histogram_totals_and_cursor_together(): void
    {
        $this->wallet(1, '42');
        $this->wallet(2, '42');
        $run = $this->reports->start($this->currency);
        $this->reachPhase($run, 'wallets');
        $before = (array) $this->stored();
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && $query->connectionName === self::CONNECTION
                && str_starts_with(strtolower($query->sql), 'update "currency_reports"')
                && str_contains($query->sql, '"checkpoint"')) {
                throw new \RuntimeException('Injected checkpoint interruption');
            }
        });
        try {
            $this->reports->advance($this->currency, $run, 1);
            self::fail('Expected the injected interruption.');
        } catch (\RuntimeException $error) {
            self::assertSame('Injected checkpoint interruption', $error->getMessage());
        } finally {
            $armed = false;
        }
        self::assertSame(0, DB::connection()->transactionLevel());
        self::assertSame($before, (array) $this->stored());
        self::assertSame(0, DB::table('currency_report_balances')->count());
        $this->finish($run, 1);
        self::assertSame(2, $this->reports->read($this->currency)['data']['wallets']);
        self::assertSame('84.000000', $this->reports->read($this->currency)['data']['in_circulation']);
    }

    public function test_source_queries_are_bounded_and_scoped_and_public_reads_reveal_no_checkpoints_or_buckets(): void
    {
        foreach (range(1, 7) as $id) $this->wallet($id, (string) ($id * 10));
        $this->issuance(1, 'mint', '100', '2026-09-01 12:00:00');
        $this->treasury(1, '20');
        $this->transaction(1, '5', '2026-09-01 12:00:00');
        DB::enableQueryLog();
        $run = $this->reports->start($this->currency);
        $this->finish($run, 2);
        $sources = ['issuance_events', 'economic_accounts', 'treasury_accounts', 'market_transactions'];
        $sourceQueries = [];
        foreach (DB::getQueryLog() as $query) {
            self::assertStringNotContainsString('economic_account_bindings', $query['query']);
            self::assertStringNotContainsString('"users"', $query['query']);
            foreach ($sources as $source) {
                if (! str_contains($query['query'], 'from "'.$source.'"')) continue;
                $sourceQueries[] = $query;
                self::assertContains($this->currency, $query['bindings']);
                self::assertMatchesRegularExpression('/\blimit\s+[12]\b/i', $query['query']);
                self::assertStringNotContainsString(' offset ', strtolower($query['query']));
                self::assertStringNotContainsString('sum(', strtolower($query['query']));
                self::assertStringNotContainsString('count(', strtolower($query['query']));
            }
        }
        self::assertNotEmpty($sourceQueries);
        $walletPages = array_filter($sourceQueries, fn ($q) => str_contains($q['query'], 'from "economic_accounts"') && str_contains($q['query'], ' > ?'));
        self::assertNotEmpty($walletPages, 'Later wallet chunks must seek past a checkpoint.');

        DB::flushQueryLog();
        $read = $this->reports->read($this->currency);
        $reads = DB::getQueryLog();
        self::assertCount(1, $reads);
        self::assertStringContainsString('from "currency_reports"', $reads[0]['query']);
        $json = json_encode($read, JSON_THROW_ON_ERROR);
        foreach (['checkpoint', 'ceilings', 'cursor', 'revision', 'run_id', 'top_sum', 'top_taken', 'currency_report_balances', $run, $this->id(1)] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
        self::assertSame('complete', $read['status']);
    }

    public function test_empty_and_unfunded_currencies_publish_null_ratios(): void
    {
        $run = $this->reports->start($this->currency);
        $this->finish($run, 1);
        $empty = $this->reports->read($this->currency)['data'];
        self::assertSame(0, $empty['wallets']);
        self::assertSame('0.000000', $empty['supply']);
        self::assertNull($empty['top_decile_share_pct']);
        self::assertNull($empty['velocity_30d']);

        $this->wallet(1, '0');
        $run = $this->reports->start($this->currency);
        $this->finish($run, 1);
        $unfunded = $this->reports->read($this->currency)['data'];
        self::assertSame(1, $unfunded['wallets']);
        self::assertSame(0, $unfunded['funded_wallets']);
        self::assertNull($unfunded['top_decile_share_pct']);
        self::assertNull($unfunded['velocity_30d']);
    }

    public function test_unknown_currency_and_invalid_chunk_size_do_not_create_progress(): void
    {
        try {
            $this->reports->start($this->id(9999));
            self::fail('Expected unknown currency rejection.');
        } catch (HttpException $error) {
            self::assertSame(404, $error->getStatusCode());
        }
        self::assertSame(0, DB::table('currency_reports')->count());
        $run = $this->reports->start($this->currency);
        $before = (array) $this->stored();
        try {
            $this->reports->advance($this->currency, $run, 0);
            self::fail('Expected invalid chunk-size rejection.');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, (array) $this->stored());
        }
    }

    public function test_queued_failures_only_apply_to_the_revision_the_job_was_dispatched_for(): void
    {
        $this->wallet(1, '40');
        $this->wallet(2, '60');
        $this->app->instance(CurrencyReportService::class, $this->reports);
        $run = $this->reports->start($this->currency);
        $initialRevision = $this->revision();
        $this->expectedReportDispatches++;
        self::assertTrue($this->reports->dispatch($this->currency, $run));
        $oldJob = Bus::dispatched(RefreshCurrencyReportJob::class)->last();
        self::assertSame($initialRevision, $oldJob->revision);
        self::assertTrue($oldJob->afterCommit);

        // Another delivery advanced this run before the old callback arrived.
        self::assertTrue($this->reports->advance($this->currency, $run, 1, $initialRevision));
        self::assertSame($initialRevision + 1, $this->revision());
        $afterAdvance = (array) $this->stored();
        $oldJob->failed(new \RuntimeException('A delayed failure callback'));
        self::assertSame($afterAdvance, (array) $this->stored());
        self::assertFalse($this->reports->advance($this->currency, $run, 1, $initialRevision));
        self::assertSame($afterAdvance, (array) $this->stored());

        $this->expectedReportDispatches++;
        self::assertTrue($this->reports->dispatch($this->currency, $run));
        $currentJob = Bus::dispatched(RefreshCurrencyReportJob::class)->last();
        self::assertSame($this->revision(), $currentJob->revision);
        $currentJob->failed(new \RuntimeException('The current checkpoint failed'));
        self::assertSame('failed', $this->reports->read($this->currency)['status']);
        self::assertFalse($this->reports->dispatch($this->currency, $run));
        $failedPoint = json_decode($this->stored()->checkpoint, true);

        self::assertSame($run, $this->reports->start($this->currency));
        self::assertSame($failedPoint['revision'] + 1, $this->revision());
        $afterResume = (array) $this->stored();
        $currentJob->failed(new \RuntimeException('A repeated pre-resume callback'));
        self::assertSame($afterResume, (array) $this->stored());
        $this->expectedReportDispatches++;
        self::assertTrue($this->reports->dispatch($this->currency, $run));
        $resumedJob = Bus::dispatched(RefreshCurrencyReportJob::class)->last();
        self::assertSame($this->revision(), $resumedJob->revision);
        $resumedJob->failed(new \RuntimeException('The resumed checkpoint failed'));
        self::assertSame('failed', $this->reports->read($this->currency)['status']);

        self::assertSame($run, $this->reports->start($this->currency));
        self::assertTrue($this->reports->advance($this->currency, $run, 1, $this->revision()));
        self::assertSame(1, json_decode($this->stored()->totals, true)['wallets']);
        self::assertFalse($this->reports->dispatch($this->currency, $this->id(9999)));
        // Older serialized jobs have no revision and cannot claim a failure.
        $legacy = new RefreshCurrencyReportJob($this->currency, $run);
        $beforeLegacy = (array) $this->stored();
        $legacy->failed(new \RuntimeException('Old job format'));
        self::assertSame($beforeLegacy, (array) $this->stored());
        $this->expectedReportDispatches++;
        $legacy->handle($this->reports);
        self::assertSame($this->revision(), Bus::dispatched(RefreshCurrencyReportJob::class)->last()->revision);
    }

    public function test_job_passes_its_revision_and_dispatches_continuation_through_the_service(): void
    {
        $run = $this->id(4000);
        $reports = $this->createMock(CurrencyReportService::class);
        $reports->expects($this->once())->method('advance')->with($this->currency, $run, null, 9)->willReturn(true);
        $reports->expects($this->once())->method('dispatch')->with($this->currency, $run)->willReturn(true);
        (new RefreshCurrencyReportJob($this->currency, $run, 9))->handle($reports);
    }

    public function test_units_get_only_reads_published_reports_and_does_not_start_or_aggregate_work(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('jurisdictions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->uuid('parent_id')->nullable();
            $t->softDeletes();
        });
        $schema->table('currencies', function (Blueprint $t): void {
            foreach (['jurisdiction_id', 'name', 'code', 'symbol', 'unit_kind', 'worth_basis', 'subdivisions'] as $column) $t->string($column)->nullable();
            $t->integer('precision')->nullable();
            $t->softDeletes();
        });
        $schema->create('setting_changes', function (Blueprint $t): void {
            $t->uuid('jurisdiction_id');
            $t->string('setting_key');
            $t->uuid('law_id')->nullable();
            $t->timestamp('applied_at')->nullable();
        });
        $schema->create('ubi_disbursements', function (Blueprint $t): void {
            $t->uuid('currency_id');
            $t->timestamp('ran_at')->nullable();
        });
        $root = $this->id(3000);
        DB::table('jurisdictions')->insert(['id' => $root, 'name' => 'Test world']);
        DB::table('currencies')->where('id', $this->currency)->update([
            'jurisdiction_id' => $root, 'name' => 'Report unit', 'code' => 'RPT',
            'symbol' => 'R', 'precision' => 6, 'unit_kind' => 'abstract',
        ]);
        $settings = $this->createMock(SettingsResolver::class);
        $settings->method('resolve')->willReturnCallback(function (string $jurisdiction, string $key) use ($root): mixed {
            self::assertSame($root, $jurisdiction);
            return ['stipend_period_days' => 17, 'stipend_interval' => 'daily'][$key] ?? null;
        });
        $ledger = $this->createMock(LedgerService::class);
        $ledger->expects($this->never())->method('verifyChain');
        $ledger->expects($this->never())->method('imbalanceByCurrency');
        $issuance = $this->createMock(IssuanceService::class);
        $issuance->expects($this->never())->method('supply');
        $telemetry = $this->createMock(CurrencyTelemetryService::class);
        $telemetry->expects($this->never())->method('snapshot');
        $accounts = $this->createMock(AccountService::class);
        $accounts->expects($this->never())->method('accountIdFor');
        $this->app->instance(CurrencyReportService::class, $this->reports);
        $controller = new EconomyController($ledger, $issuance, $accounts, $settings, $telemetry);
        $readPage = function () use ($controller): array {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $controller->units();
            $props = (new \ReflectionProperty($response, 'props'))->getValue($response);
            $queries = DB::getQueryLog();
            self::assertNotEmpty($queries);
            foreach ($queries as $query) {
                self::assertStringStartsWith('select ', strtolower($query['query']));
                self::assertStringContainsString('limit 1', strtolower($query['query']));
                foreach (['economic_accounts', 'economic_account_bindings', 'issuance_events', 'treasury_accounts', 'market_transactions', 'currency_report_balances', 'sum(', 'count('] as $forbidden) {
                    self::assertStringNotContainsString($forbidden, strtolower($query['query']));
                }
            }
            return $props;
        };

        $unstarted = $readPage();
        self::assertNull($unstarted['supply']);
        self::assertNull($unstarted['telemetry']);
        self::assertSame('not_started', $unstarted['report']['status']);
        self::assertSame(0, DB::table('currency_reports')->count());
        self::assertSame(17, $unstarted['clock']['period_days']);
        $this->wallet(1, '20');
        $this->issuance(1, 'mint', '100.000001', '2026-09-01 12:00:00');
        $run = $this->reports->start($this->currency);
        $this->finish($run, 1);
        $complete = $readPage();
        self::assertSame('100.000001', $complete['supply']);
        self::assertSame('20.000000', $complete['telemetry']['in_circulation']);

        $this->wallet(2, '80');
        $run = $this->reports->start($this->currency);
        $refreshing = $readPage();
        self::assertSame('running', $refreshing['report']['status']);
        self::assertSame($complete['telemetry'], $refreshing['telemetry']);
        self::assertSame($complete['supply'], $refreshing['supply']);
        $this->reports->fail($this->currency, $run, $this->revision());
        $failed = $readPage();
        self::assertSame('failed', $failed['report']['status']);
        self::assertSame($complete['telemetry'], $failed['telemetry']);
    }

    private function createSourceTables(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('currencies', fn (Blueprint $t) => $t->uuid('id')->primary());
        foreach (['issuance_events', 'economic_accounts', 'treasury_accounts', 'market_transactions'] as $table) {
            $schema->create($table, function (Blueprint $t) use ($table): void {
                $t->uuid('id')->primary();
                $t->uuid('currency_id');
                $t->timestamp('created_at')->nullable();
                $t->index(['currency_id', 'id']);
                if (in_array($table, ['economic_accounts', 'treasury_accounts'], true)) {
                    // Store fixture source amounts as strings so SQLite does
                    // not replace the service's exact decimal input with float.
                    $t->string('balance');
                    $t->string('kind')->nullable();
                    $t->string('status')->nullable();
                    $t->timestamp('updated_at')->nullable();
                    $t->softDeletes();
                } else {
                    $t->string('amount');
                    if ($table === 'issuance_events') $t->string('direction');
                }
            });
        }
    }

    private function wallet(int $id, string $balance, array $extra = []): void
    {
        DB::table('economic_accounts')->insert(array_merge([
            'id' => $this->id($id), 'currency_id' => $this->currency,
            'balance' => $balance, 'kind' => 'user', 'status' => 'open', 'created_at' => self::START,
        ], $extra));
    }

    private function treasury(int $id, string $balance, array $extra = []): void
    {
        DB::table('treasury_accounts')->insert(array_merge([
            'id' => $this->id($id), 'currency_id' => $this->currency,
            'balance' => $balance, 'created_at' => self::START,
        ], $extra));
    }

    private function issuance(int $id, string $direction, string $amount, string $at, ?string $currency = null): void
    {
        DB::table('issuance_events')->insert([
            'id' => $this->id($id), 'currency_id' => $currency ?? $this->currency,
            'direction' => $direction, 'amount' => $amount, 'created_at' => $at,
        ]);
    }

    private function transaction(int $id, string $amount, string $at, ?string $currency = null): void
    {
        DB::table('market_transactions')->insert([
            'id' => $this->id($id), 'currency_id' => $currency ?? $this->currency,
            'amount' => $amount, 'created_at' => $at,
        ]);
    }

    private function stored(): object
    {
        return DB::table('currency_reports')->where('currency_id', $this->currency)->first();
    }

    private function revision(): int
    {
        return (int) (json_decode($this->stored()->checkpoint, true)['revision'] ?? 0);
    }

    private function reachPhase(string $run, string $phase, int $limit = 1): void
    {
        for ($step = 0; $step < 100; $step++) {
            if ($this->stored()->phase === $phase) return;
            self::assertTrue($this->reports->advance($this->currency, $run, $limit));
        }
        self::fail('The fixture did not reach phase '.$phase.' within its bounded step budget.');
    }

    private function finish(string $run, int $limit): void
    {
        for ($step = 0; $step < 200; $step++) {
            if (! $this->reports->advance($this->currency, $run, $limit)) return;
        }
        self::fail('The fixture did not complete within its bounded step budget.');
    }

    private function id(int $id): string
    {
        return sprintf('70000000-0000-4000-8000-%012d', $id);
    }
}
