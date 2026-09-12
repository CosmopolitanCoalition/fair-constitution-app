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

/** In-memory fixture only. No migration or live database trait is used. */
final class EconomyHomeTest extends TestCase
{
    private EconomyController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.economy_home_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('economy_home_fixture');
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('jurisdictions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('parent_id')->nullable();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('currencies', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('jurisdiction_id')->index();
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
            $table->index(['owner_type', 'owner_id']);
        });
        DB::table('jurisdictions')->insert(['id' => 'root']);
        DB::table('currencies')->insert([
            'id' => 'currency', 'jurisdiction_id' => 'root', 'name' => 'Test unit', 'code' => 'TEST',
            'symbol' => 'T', 'precision' => 2, 'unit_kind' => 'abstract',
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

    public function test_home_reads_only_the_existing_viewers_wallet_in_four_point_queries(): void
    {
        $this->wallet('mine', 'viewer', '123456789012345678.123456');
        $this->wallet('theirs', 'other-person', '999999.000000');
        $props = $this->home('viewer');
        self::assertSame(['id' => 'mine', 'balance' => '123456789012345678.123456', 'status' => 'active'], $props['account']);
        self::assertSame('TEST', $props['currency']['code']);
        self::assertCount(4, DB::getQueryLog());
        $this->assertOnlyPointReads();
        $this->assertUnmeasured($props);
    }

    public function test_home_without_wallet_does_not_create_one_or_invent_a_zero_balance(): void
    {
        $props = $this->home('viewer');
        self::assertNull($props['account']);
        self::assertCount(3, DB::getQueryLog());
        $this->assertOnlyPointReads();
        self::assertSame(0, DB::table('economic_accounts')->count());
        $this->assertUnmeasured($props);
    }

    public function test_guest_gets_no_private_wallet_lookup(): void
    {
        $this->wallet('theirs', 'other-person', '12.000000');
        $props = $this->home(null);
        self::assertNull($props['account']);
        self::assertCount(2, DB::getQueryLog());
        $this->assertOnlyPointReads();
    }

    public function test_world_without_currency_has_no_wallet_or_false_healthy_ledger(): void
    {
        DB::table('currencies')->delete();
        $props = $this->home('viewer');
        self::assertNull($props['currency']);
        self::assertNull($props['account']);
        self::assertCount(2, DB::getQueryLog());
        $this->assertUnmeasured($props);
    }

    private function home(?string $userId): array
    {
        $request = Request::create('/economy');
        $request->setUserResolver(fn () => $userId === null ? null : (new User)->forceFill(['id' => $userId]));
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->controller->home($request);

        return (new \ReflectionProperty($response, 'props'))->getValue($response);
    }

    private function wallet(string $id, string $ownerId, string $balance): void
    {
        DB::table('economic_accounts')->insert(['id' => $id, 'currency_id' => 'currency', 'balance' => $balance, 'status' => 'active']);
        DB::table('economic_account_bindings')->insert(['account_id' => $id, 'owner_type' => 'users', 'owner_id' => $ownerId]);
    }

    private function assertOnlyPointReads(): void
    {
        foreach (DB::getQueryLog() as $query) {
            self::assertStringStartsWith('select ', strtolower($query['query']));
            self::assertStringContainsString('limit 1', strtolower($query['query']));
            self::assertDoesNotMatchRegularExpression('/\b(count|sum|avg)\s*\(/i', $query['query']);
        }
    }

    private function assertUnmeasured(array $props): void
    {
        self::assertNull($props['supply']);
        self::assertSame(['entries' => null, 'verified' => null, 'residual' => null, 'status' => 'not_checked'], $props['ledger']);
        self::assertSame([null, null, null, null, null], array_values($props['counts']));
        self::assertSame('not_loaded', $props['stipend']['status']);
        self::assertNull($props['clock']['next_run']);
    }
}
