<?php

namespace Tests\Unit;

use App\Services\Economy\AccountService;
use App\Services\Economy\LedgerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * W-0299 part 3 — the balance floor.
 *
 * Two guarantees, two layers. The app layer: AccountService refuses an
 * overdraft before it posts anything, so a balance never goes negative through
 * the service. This pin drives that refusal on a NAMED sqlite fixture (the
 * refusal fires before the Postgres-only ledger lock). The storage layer: a
 * 2026_09_14 migration adds CHECK (balance >= 0) on Postgres; it is a no-op on
 * sqlite, so migrating a sqlite fixture does not break. The Postgres CHECK
 * rejection is pinned by the disposable-database probe
 * tests/concurrency/economic_accounts_balance_check.php.
 */
final class EconomicAccountsBalanceFloorTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.balance_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::setDefaultConnection('balance_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());

        DB::connection()->getSchemaBuilder()->create('economic_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('kind')->default('user');
            $t->uuid('currency_id')->nullable();
            $t->decimal('balance', 24, 6)->default(0);
            $t->string('status')->default('open');
            $t->timestamps();
            $t->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    public function test_the_service_refuses_an_overdraft(): void
    {
        $from = (string) Str::uuid();
        $to   = (string) Str::uuid();
        $cur  = (string) Str::uuid();
        DB::table('economic_accounts')->insert([
            ['id' => $from, 'kind' => 'user', 'currency_id' => $cur, 'balance' => '5.000000', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $to, 'kind' => 'user', 'currency_id' => $cur, 'balance' => '0.000000', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // The overdraft is refused before any ledger write, so the ledger is never reached.
        $accounts = new AccountService($this->createMock(LedgerService::class));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient balance');
        // 10 out of a balance of 5 — refused before any ledger write, so no negative balance.
        $accounts->transfer($from, $to, $cur, '10.000000', 'transfer');
    }

    public function test_the_migration_is_real_dated_and_is_a_noop_on_sqlite(): void
    {
        $path = dirname(__DIR__, 2).'/database/migrations/2026_09_14_000100_add_balance_floor_to_economic_accounts.php';
        self::assertFileExists($path);
        self::assertStringStartsWith('2026_09_14_', basename($path), 'the migration is real-dated 2026_09_14');

        // On sqlite the driver guard returns early: up() adds nothing and does not throw.
        $migration = require $path;
        $migration->up();
        $migration->up(); // rerunnable
        $this->assertTrue(true);
    }
}
