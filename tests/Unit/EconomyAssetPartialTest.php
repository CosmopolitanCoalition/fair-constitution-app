<?php

namespace Tests\Unit;

use App\Http\Controllers\Economy\EconomyController;
use App\Models\User;
use App\Services\Economy\AccountService;
use App\Services\Economy\CurrencyTelemetryService;
use App\Services\Economy\IssuanceService;
use App\Services\Economy\LedgerService;
use App\Services\SettingsResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Actual Inertia partial responses against private in-memory fixtures only. */
final class EconomyAssetPartialTest extends TestCase
{
    private string $originalConnection;

    private EconomyController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config([
            'database.connections.economy_asset_partial_fixture' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            ],
            'session.driver' => 'array',
        ]);
        DB::setDefaultConnection('economy_asset_partial_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('jurisdictions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('parent_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('currencies', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('jurisdiction_id');
            $table->string('name');
            $table->string('code');
            $table->string('symbol');
            $table->integer('precision');
            $table->string('unit_kind');
            $table->text('worth_basis')->nullable();
            $table->text('subdivisions')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('economic_accounts', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('currency_id');
            $table->string('balance');
            $table->string('status');
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('economic_account_bindings', function (Blueprint $table) {
            $table->string('account_id');
            $table->string('owner_type');
            $table->string('owner_id');
        });
        $schema->create('assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_account_id');
            $table->string('name');
            $table->string('kind')->default('physical');
            $table->string('quantity')->default('1.000000');
            $table->string('origin')->default('crafted');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        // Deliberately omit listing metadata and all history/work/help/user
        // tables: resolving those unrelated page props must fail this fixture.
        $schema->create('marketplace_listings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('asset_id')->nullable();
            $table->string('status');
        });
        DB::table('jurisdictions')->insert(['id' => 'root']);
        DB::table('currencies')->insert([
            'id' => 'currency', 'jurisdiction_id' => 'root', 'name' => 'Test unit',
            'code' => 'TEST', 'symbol' => 'T', 'precision' => 2, 'unit_kind' => 'abstract',
        ]);
        foreach (['viewer' => 900, 'other-person' => 901] as $owner => $account) {
            DB::table('economic_accounts')->insert([
                'id' => $this->id($account), 'currency_id' => 'currency', 'balance' => '1.000000', 'status' => 'active',
            ]);
            DB::table('economic_account_bindings')->insert([
                'account_id' => $this->id($account), 'owner_type' => 'users', 'owner_id' => $owner,
            ]);
        }
        foreach (range(1, 25) as $id) {
            DB::table('assets')->insert([
                'id' => $this->id($id), 'owner_account_id' => $this->id(900),
                'name' => 'Item '.str_pad((string) $id, 2, '0', STR_PAD_LEFT),
            ]);
        }
        DB::table('assets')->insert([
            'id' => $this->id(26), 'owner_account_id' => $this->id(901), 'name' => 'Item 00 private',
        ]);

        $ledger = $this->createMock(LedgerService::class);
        $ledger->expects($this->never())->method('verifyChain');
        $ledger->expects($this->never())->method('imbalanceByCurrency');
        $issuance = $this->createMock(IssuanceService::class);
        $issuance->expects($this->never())->method('supply');
        $telemetry = $this->createMock(CurrencyTelemetryService::class);
        $telemetry->expects($this->never())->method('snapshot');
        $this->controller = new EconomyController(
            $ledger, $issuance, new AccountService($ledger),
            $this->createMock(SettingsResolver::class), $telemetry,
        );
    }

    protected function tearDown(): void
    {
        DB::disconnect('economy_asset_partial_fixture');
        DB::purge('economy_asset_partial_fixture');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_wallet_asset_partial_pages_once_without_loading_payment_history(): void
    {
        $first = $this->partial('wallet', '/economy/wallet?asset_q=Item&account_id='.$this->id(901));
        self::assertSame(['assets', 'asset_directory'], array_keys($first));
        self::assertSame($first['assets'], $first['asset_directory']['assets']);
        self::assertSame(array_map($this->id(...), range(1, 20)), array_column($first['assets'], 'id'));
        $this->assertAssetReads(5);

        $second = $this->partial('wallet', $first['asset_directory']['next']);
        self::assertSame(array_map($this->id(...), range(21, 25)), array_column($second['assets'], 'id'));
        self::assertNull($second['asset_directory']['next']);
        self::assertNotNull($second['asset_directory']['previous']);
        $this->assertAssetReads(5);
    }

    public function test_market_picker_partial_skips_public_feed_and_keeps_only_eligible_owned_items(): void
    {
        DB::table('marketplace_listings')->insert([
            ['id' => $this->id(101), 'asset_id' => $this->id(1), 'status' => 'open'],
            ['id' => $this->id(102), 'asset_id' => $this->id(2), 'status' => 'closed'],
        ]);
        $props = $this->partial('market', '/economy/market?tab=offers&asset_picker=1&asset_q=Item&account_id='.$this->id(901));
        self::assertSame(['my_assets', 'asset_directory'], array_keys($props));
        self::assertSame($props['my_assets'], $props['asset_directory']['assets']);
        self::assertSame(array_map($this->id(...), range(2, 21)), array_column($props['my_assets'], 'id'));
        self::assertNotNull($props['asset_directory']['next']);
        $this->assertAssetReads(4);
    }

    public function test_guest_partial_does_not_resolve_an_account_or_load_private_items(): void
    {
        $props = $this->partial('wallet', '/economy/wallet?account_id='.$this->id(900), null);
        self::assertSame([], $props['assets']);
        self::assertFalse($props['asset_directory']['available']);
        self::assertCount(2, DB::getQueryLog());
        foreach (DB::getQueryLog() as $query) {
            self::assertStringNotContainsString('economic_account', $query['query']);
            self::assertStringNotContainsString('assets', $query['query']);
        }
    }

    private function partial(string $method, string $url, ?string $userId = 'viewer'): array
    {
        $request = Request::create($url);
        $request->setUserResolver(fn () => $userId === null ? null : (new User)->forceFill(['id' => $userId]));
        $request->headers->set('X-Inertia', 'true');
        $request->headers->set('X-Inertia-Partial-Component', 'Economy/'.ucfirst($method));
        $request->headers->set('X-Inertia-Partial-Data', ($method === 'wallet' ? 'assets' : 'my_assets').',asset_directory');
        DB::enableQueryLog();
        DB::flushQueryLog();

        $response = $this->controller->{$method}($request)->toResponse($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('true', $response->headers->get('X-Inertia'));

        return $response->getData(true)['props'];
    }

    private function assertAssetReads(int $total): void
    {
        $queries = DB::getQueryLog();
        self::assertCount($total, $queries);
        $assetReads = array_filter($queries, fn ($query) => str_contains($query['query'], 'from "assets"'));
        self::assertCount(1, $assetReads, 'Both asset props must share one directory read.');
        foreach ($queries as $query) {
            self::assertStringStartsWith('select ', $query['query']);
            self::assertStringContainsString('limit ', $query['query']);
            self::assertDoesNotMatchRegularExpression('/\b(count|sum|avg)\s*\(/i', $query['query']);
            self::assertDoesNotMatchRegularExpression('/"(market_transactions|ubi_receipts|work_postings|assistance_requests|users)"/', $query['query']);
        }
        $assetRead = array_values($assetReads)[0];
        self::assertContains($this->id(900), $assetRead['bindings']);
        self::assertNotContains($this->id(901), $assetRead['bindings']);
        self::assertStringContainsString('limit 21', $assetRead['query']);
    }

    private function id(int $id): string
    {
        return sprintf('40000000-0000-4000-8000-%012d', $id);
    }
}
