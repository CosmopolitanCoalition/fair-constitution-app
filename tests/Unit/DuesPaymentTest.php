<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Handlers\FundsTransfer;
use App\Models\Economy\Currency;
use App\Models\Economy\EconomicAccount;
use App\Models\User;
use App\Services\Economy\AccountService;
use App\Services\Economy\JointLedgerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W-0299 part 2 — a dues payment records on the ledger as kind='dues'.
 *
 * A NAMED sqlite fixture. The ledger post uses a Postgres advisory lock, so
 * AccountService is a SPY here: it records the kind the handler asks it to
 * move the money under. AccountService::transfer forwards $kind verbatim to
 * LedgerService::post and the market_transactions row, so proving the handler
 * calls transfer with kind='dues' proves the ledger label. The refusals (no
 * dues policy, not a member) are pinned on the same fixture.
 */
final class DuesPaymentTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.dues_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::setDefaultConnection('dues_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());

        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('organizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name')->nullable();
            $t->string('type')->nullable();
            $t->text('settings')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        $schema->create('org_memberships', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('organization_id');
            $t->uuid('user_id');
            $t->string('kind')->default('member');
            $t->string('status')->default('applied');
            $t->timestamps();
            $t->softDeletes();
        });
        $schema->create('currencies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('jurisdiction_id')->nullable();
            $t->string('name')->nullable();
            $t->string('code')->nullable();
            $t->string('symbol')->nullable();
            $t->integer('precision')->default(6);
            $t->timestamps();
            $t->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function currency(): Currency
    {
        $id = (string) Str::uuid();
        DB::table('currencies')->insert([
            'id' => $id, 'jurisdiction_id' => (string) Str::uuid(), 'name' => 'Root', 'code' => 'RTC',
            'symbol' => 'R', 'precision' => 6, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Currency::query()->findOrFail($id);
    }

    private function organization(?string $duesAmount): string
    {
        $id = (string) Str::uuid();
        DB::table('organizations')->insert([
            'id' => $id, 'name' => 'Guild', 'type' => 'nonprofit',
            'settings' => $duesAmount === null ? null : json_encode(['dues_amount' => $duesAmount]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function member(string $orgId, string $userId, string $status = 'active'): void
    {
        DB::table('org_memberships')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $orgId, 'user_id' => $userId,
            'kind' => 'member', 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A spy account service: no ledger, only a record of the transfer it was asked to make. */
    private function spyAccounts(): AccountService
    {
        return new class extends AccountService
        {
            /** @var array<int, array<string, mixed>> */
            public array $transfers = [];

            public function __construct() {}

            public function accountIdFor(string $ownerType, string $ownerId, string $currencyId): ?string
            {
                return $ownerType === 'users' ? 'user-acct-'.$ownerId : null;
            }

            public function open(string $ownerType, string $ownerId, string $currencyId, string $kind = 'user'): EconomicAccount
            {
                return (new EconomicAccount)->forceFill(['id' => 'org-acct-'.$ownerId]);
            }

            public function transfer(string $fromAccountId, string $toAccountId, string $currencyId, string $amount, string $kind = 'transfer', ?string $memo = null): string
            {
                $this->transfers[] = compact('fromAccountId', 'toAccountId', 'currencyId', 'amount', 'kind', 'memo');

                return 'entry-group';
            }
        };
    }

    private function handler(AccountService $accounts): FundsTransfer
    {
        return new FundsTransfer($accounts, $this->createMock(JointLedgerService::class));
    }

    public function test_a_dues_payment_records_as_kind_dues(): void
    {
        $currency = $this->currency();
        $actor    = (new User)->forceFill(['id' => (string) Str::uuid()]);
        $orgId    = $this->organization('25.000000');
        $this->member($orgId, (string) $actor->id);

        $accounts = $this->spyAccounts();
        $result = $this->handler($accounts)->handle($actor, [
            'action' => 'dues', 'organization_id' => $orgId, 'currency_id' => (string) $currency->id,
        ]);

        self::assertSame('dues_paid', $result['action']);
        self::assertSame('dues', $result['kind']);
        self::assertSame('25.000000', $result['amount']);
        self::assertCount(1, $accounts->transfers);
        self::assertSame('dues', $accounts->transfers[0]['kind'], 'the transfer is posted under kind=dues');
        self::assertSame('25.000000', $accounts->transfers[0]['amount']);
        self::assertSame('user-acct-'.$actor->id, $accounts->transfers[0]['fromAccountId']);
        self::assertSame('org-acct-'.$orgId, $accounts->transfers[0]['toAccountId'], 'dues pay into the organization account');
    }

    public function test_dues_refuse_when_the_organization_charges_none(): void
    {
        $currency = $this->currency();
        $actor    = (new User)->forceFill(['id' => (string) Str::uuid()]);
        $orgId    = $this->organization(null); // no dues policy
        $this->member($orgId, (string) $actor->id);

        $this->expectException(ConstitutionalViolation::class);
        $this->expectExceptionMessage('This organization charges no dues.');
        $this->handler($this->spyAccounts())->handle($actor, [
            'action' => 'dues', 'organization_id' => $orgId, 'currency_id' => (string) $currency->id,
        ]);
    }

    public function test_dues_refuse_a_non_member(): void
    {
        $currency = $this->currency();
        $actor    = (new User)->forceFill(['id' => (string) Str::uuid()]);
        $orgId    = $this->organization('25.000000'); // policy set, but the actor is not a member

        $this->expectException(ConstitutionalViolation::class);
        $this->expectExceptionMessage('Only an active member pays dues');
        $this->handler($this->spyAccounts())->handle($actor, [
            'action' => 'dues', 'organization_id' => $orgId, 'currency_id' => (string) $currency->id,
        ]);
    }
}
