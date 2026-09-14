<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Handlers\OrganizationMarketParticipation;
use App\Models\Economy\EconomicAccount;
use App\Models\Organization;
use App\Models\OrgMembership;
use App\Models\OrgOwnershipStake;
use App\Models\PublicRecord;
use App\Models\User;
use App\Services\Economy\AccountService;
use App\Services\Economy\ShareTradeService;
use App\Services\Organizations\OrgOwnershipService;
use App\Services\Organizations\OrgRegistryService;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Private in-memory fixtures only. No migration, live DB, audit, queue, or world actions. */
final class ShareIssuanceWorkflowTest extends TestCase
{
    private string $originalConnection;
    private OrgOwnershipService $ownership;
    private OrganizationMarketParticipation $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.share_issuance_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('share_issuance_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('organizations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('agent_user_id');
            $t->string('structure');
            $t->string('status')->default('active');
            $t->softDeletes();
        });
        $schema->create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->softDeletes();
        });
        $schema->create('org_ownership_stakes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            foreach (['organization_id', 'holder_type', 'holder_id', 'acquired_via'] as $name) $t->string($name);
            // TEXT deliberately preserves the exact decimal; SQLite NUMERIC
            // would coerce to binary float and cannot prove PostgreSQL precision.
            $t->string('units');
            $t->string('pct')->nullable();
            $t->uuid('source_transfer_id')->nullable();
            $t->timestamp('as_of');
            $t->timestamp('ended_at')->nullable();
            $t->timestamps();
        });
        $schema->create('org_memberships', function (Blueprint $t) {
            $t->uuid('id')->primary();
            foreach (['organization_id', 'user_id', 'kind', 'status'] as $name) $t->string($name);
            foreach (['applied_at', 'accepted_at', 'ended_at'] as $name) $t->timestamp($name)->nullable();
            $t->string('end_reason')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        // IO-5 — OrgDelegationService::mayPerform reads this on the non-agent
        // 'shares' path. Empty here, so another org's agent is still refused.
        $schema->create('org_staff_grants', function (Blueprint $t) {
            $t->uuid('id')->primary(); foreach (['organization_id', 'grantee_user_id', 'task', 'status'] as $name) $t->string($name);
            $t->softDeletes();
        });
        foreach ([1, 2] as $n) DB::table('organizations')->insert([
            'id' => $this->id($n), 'agent_user_id' => $this->id(100 + $n), 'structure' => 'stock',
        ]);
        foreach ([101, 102, 201, 202] as $n) DB::table('users')->insert(['id' => $this->id($n)]);
        // Force multiple pages with tiny fixtures. Production derives this from
        // PHP/container memory; no fixed row size is imposed on the host.
        $this->ownership = new class extends OrgOwnershipService {
            protected function stakeBatchSize(): int { return 2; }
        };
        $this->handler = new OrganizationMarketParticipation($this->ownership);
    }

    protected function tearDown(): void
    {
        DB::purge('share_issuance_fixture');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_agent_issues_exact_units_and_creates_only_matching_shareholder_membership(): void
    {
        $result = $this->handler->handle($this->actor(101), $this->payload(['units' => '99999999999999.999999']));
        self::assertSame('99999999999999.999999', $result['units']);
        self::assertSame('100.0000', $result['pct']);
        $stake = DB::table('org_ownership_stakes')->sole();
        self::assertSame($this->id(1), $stake->organization_id);
        self::assertSame($this->id(201), $stake->holder_id);
        self::assertSame('issue', $stake->acquired_via);
        self::assertNull($stake->ended_at);
        $membership = DB::table('org_memberships')->sole();
        self::assertSame($this->id(201), $membership->user_id);
        self::assertSame('shareholder', $membership->kind);
        self::assertSame('active', $membership->status);
    }

    public function test_system_issuance_to_an_existing_organization_has_no_person_membership(): void
    {
        $result = $this->handler->handle(null, $this->payload([
            'holder_type' => 'organizations', 'holder_id' => $this->id(2), 'units' => '0.000001',
        ]));
        self::assertSame('0.000001', $result['units']);
        self::assertSame($this->id(2), $result['holder_id']);
        self::assertSame(0, DB::table('org_memberships')->count());
    }

    public function test_repeat_issuance_preserves_history_and_reuses_open_membership(): void
    {
        $first = $this->handler->handle($this->actor(101), $this->payload(['units' => '1']));
        $second = $this->handler->handle($this->actor(101), $this->payload(['units' => '2']));
        self::assertNotSame($first['stake_id'], $second['stake_id']);
        self::assertSame('33.3333', DB::table('org_ownership_stakes')->where('id', $first['stake_id'])->value('pct'));
        self::assertSame('66.6667', $second['pct']);
        self::assertSame(1, DB::table('org_memberships')->count());
    }

    public function test_another_organizations_agent_cannot_issue_shares(): void
    {
        $this->assertRefused($this->actor(102), $this->payload());
    }

    public function test_dissolved_organization_cannot_reopen_ownership_through_issuance(): void
    {
        DB::table('organizations')->where('id', $this->id(1))->update(['status' => 'dissolved']);
        $this->assertRefused($this->actor(101), $this->payload());
        $this->assertRefused(null, $this->payload());
    }

    public function test_registered_stock_organization_can_still_issue_shares(): void
    {
        DB::table('organizations')->where('id', $this->id(1))->update(['status' => 'registered']);
        self::assertSame('100.000000', $this->handler->handle($this->actor(101), $this->payload())['units']);
    }

    public static function nonStockStructures(): array
    {
        return array_map(fn ($structure) => [$structure], array_values(array_diff(Organization::STRUCTURES, ['stock'])));
    }

    #[DataProvider('nonStockStructures')]
    public function test_non_stock_organization_cannot_issue_shares(string $structure): void
    {
        DB::table('organizations')->where('id', $this->id(1))->update(['structure' => $structure]);
        $this->assertRefused($this->actor(101), $this->payload());
    }

    public static function badRecipients(): array
    {
        return [
            ['jurisdictions', 201, false], ['account', 201, false], ['users', 999, false],
            ['organizations', 999, false], ['users', 201, true], ['organizations', 2, true],
            ['users', 'bad-id', false], ['users', '', false], ['users', [], false], [[], 201, false],
        ];
    }

    #[DataProvider('badRecipients')]
    public function test_invalid_missing_or_deleted_recipient_is_refused(mixed $type, mixed $id, bool $deleted): void
    {
        $holderId = is_int($id) ? $this->id($id) : $id;
        if ($deleted) DB::table($type)->where('id', $holderId)->update(['deleted_at' => now()]);
        $this->assertRefused($this->actor(101), $this->payload(['holder_type' => $type, 'holder_id' => $holderId]));
    }

    public static function invalidUnits(): array
    {
        return array_map(fn ($value) => [$value], [
            null, [], true, false, '', '0', '0.000000', '-1', '+1', '.1', '1.', '1e2',
            ' 1', '1 ', "1\n", 'NaN', 'INF', '1.0000001', '0.0000001', '100000000000000',
            '999999999999999.999999', INF, NAN,
        ]);
    }

    #[DataProvider('invalidUnits')]
    public function test_invalid_quantity_never_writes_ownership(mixed $units): void
    {
        $this->assertRefused($this->actor(101), $this->payload(['units' => $units]));
    }

    public function test_legacy_numeric_service_callers_and_jurisdiction_stakes_still_work(): void
    {
        self::assertSame('100.000000', OrgOwnershipService::normalizeUnits(100.0));
        self::assertSame('0.000001', OrgOwnershipService::normalizeUnits(0.000001));
        self::assertSame('17.000000', OrgOwnershipService::normalizeUnits(17));
        self::assertSame('0.200000', OrgOwnershipService::normalizeUnits(0.3 - 0.1));
        self::assertSame('0.123457', OrgOwnershipService::normalizeUnits(0.1234567));
        $stake = $this->ownership->openStake(Organization::findOrFail($this->id(1)), 'jurisdictions', $this->id(999), 100.0, 'founding');
        self::assertSame('100.000000', $stake->units);
        self::assertSame('100.0000', $stake->pct);
        self::assertSame(0, DB::table('org_memberships')->count());
    }

    public function test_recompute_and_close_walk_all_pages_and_only_the_selected_organizations_open_rows(): void
    {
        foreach (range(1, 5) as $n) DB::table('org_ownership_stakes')->insert($this->stake(300 + $n, 1, '1'));
        DB::table('org_ownership_stakes')->insert($this->stake(401, 2, '77'));
        DB::table('org_ownership_stakes')->insert($this->stake(402, 1, '77') + ['ended_at' => now()]);
        DB::enableQueryLog();
        $this->ownership->recomputePct($this->id(1));
        $queries = DB::getQueryLog();
        foreach ($queries as $query) {
            if (str_starts_with($query['query'], 'select') && str_contains($query['query'], 'org_ownership_stakes')) {
                self::assertStringContainsString('limit 2', $query['query']);
                self::assertContains($this->id(1), $query['bindings']);
            }
        }
        self::assertSame(['20.0000'], DB::table('org_ownership_stakes')->where('organization_id', $this->id(1))
            ->whereNull('ended_at')->distinct()->pluck('pct')->all());
        self::assertSame('19.0000', DB::table('org_ownership_stakes')->where('id', $this->id(401))->value('pct'));
        self::assertSame('19.0000', DB::table('org_ownership_stakes')->where('id', $this->id(402))->value('pct'));
        self::assertSame(5, $this->ownership->closeAllStakes(Organization::findOrFail($this->id(1))));
        self::assertSame(0, DB::table('org_ownership_stakes')->where('organization_id', $this->id(1))->whereNull('ended_at')->count());
        self::assertNull(DB::table('org_ownership_stakes')->where('id', $this->id(401))->value('ended_at'));
        self::assertSame(7, DB::table('org_ownership_stakes')->count());
    }

    public function test_membership_failure_rolls_back_the_new_stake(): void
    {
        OrgMembership::creating(fn () => throw new RuntimeException('Fixture membership failure'));
        try {
            $this->handler->handle($this->actor(101), $this->payload());
            self::fail('Expected fixture failure');
        } catch (RuntimeException $error) {
            self::assertSame('Fixture membership failure', $error->getMessage());
        } finally {
            OrgMembership::flushEventListeners();
        }
        self::assertSame(0, DB::table('org_ownership_stakes')->count());
        self::assertSame(0, DB::table('org_memberships')->count());
    }

    public static function tradeQuantities(): array
    {
        return [
            ['10', '4', '6.000000', '4.000000', ['40.0000', '60.0000']],
            ['0.3', '0.1', '0.200000', '0.100000', ['33.3333', '66.6667']],
        ];
    }

    #[DataProvider('tradeQuantities')]
    public function test_secondary_trade_locks_organization_before_stakes_and_preserves_settlement(
        string $held, string $offered, string $remainder, string $bought, array $percentages,
    ): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('economic_accounts', fn (Blueprint $t) => $t->uuid('id')->primary());
        $schema->create('share_offers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            foreach (['organization_id', 'seller_holder_id', 'currency_id', 'status', 'units', 'price_per_unit'] as $name) $t->string($name);
            foreach (['buyer_holder_type', 'buyer_holder_id', 'money_transfer_id'] as $name) $t->string($name)->nullable();
            $t->timestamp('settled_at')->nullable();
            $t->timestamps();
        });
        $this->handler->handle($this->actor(101), $this->payload(['units' => $held]));
        foreach ([601, 602] as $n) DB::table('economic_accounts')->insert(['id' => $this->id($n)]);
        DB::table('share_offers')->insert([
            'id' => $this->id(501), 'organization_id' => $this->id(1), 'seller_holder_id' => $this->id(201),
            'currency_id' => $this->id(700), 'status' => 'open', 'units' => $offered, 'price_per_unit' => '0',
        ]);
        $accounts = $this->createMock(AccountService::class);
        $accounts->method('open')->willReturnCallback(fn ($type, $id) => new EconomicAccount([
            'id' => $id === $this->id(202) ? $this->id(602) : $this->id(601),
        ]));
        $accounts->expects($this->never())->method('transfer');
        DB::enableQueryLog();
        $result = (new ShareTradeService($accounts, $this->ownership))->buy($this->actor(202), $this->id(501));
        $queries = array_column(DB::getQueryLog(), 'query');
        $orgRead = array_find_key($queries, fn ($sql) => str_starts_with($sql, 'select') && str_contains($sql, '"organizations"'));
        $stakeRead = array_find_key($queries, fn ($sql) => str_starts_with($sql, 'select') && str_contains($sql, '"org_ownership_stakes"'));
        self::assertLessThan($stakeRead, $orgRead, 'The organization read precedes stake locking. SQLite does not simulate PostgreSQL contention.');
        self::assertSame($offered, $result['units']);
        self::assertSame('filled', DB::table('share_offers')->value('status'));
        self::assertSame($remainder, OrgOwnershipStake::query()->open()->where('holder_id', $this->id(201))->sole()->units);
        self::assertSame($bought, OrgOwnershipStake::query()->open()->where('holder_id', $this->id(202))->sole()->units);
        self::assertSame($percentages, OrgOwnershipStake::query()->open()->orderBy('pct')->pluck('pct')->all());
        self::assertSame(2, OrgMembership::query()->active()->count());
    }

    public function test_dissolution_takes_organization_before_memberships_and_stakes_and_keeps_history(): void
    {
        $this->dissolutionTables();
        $this->handler->handle($this->actor(101), $this->payload());
        $records = $this->createMock(PublicRecordService::class);
        $records->expects($this->once())->method('publish')->willReturn((new PublicRecord)->forceFill(['id' => $this->id(800)]));
        $roles = $this->createMock(RoleService::class);
        $roles->expects($this->once())->method('flush');
        $org = Organization::findOrFail($this->id(1));
        DB::enableQueryLog();
        $result = (new OrgRegistryService($records, $roles, $this->ownership))->dissolve($org, $this->actor(101), 'Fixture closure');
        $queries = array_column(DB::getQueryLog(), 'query');
        self::assertStringContainsString('"organizations"', $queries[0]);
        $firstUpdate = array_find_key($queries, fn ($sql) => str_starts_with($sql, 'update'));
        self::assertGreaterThan(0, $firstUpdate);
        self::assertSame(1, $result['memberships_ended']);
        self::assertSame(1, $result['stakes_closed']);
        self::assertSame($this->id(800), $result['record_id']);
        self::assertSame('dissolved', $org->refresh()->status);
        self::assertSame('ended', OrgMembership::query()->sole()->status);
        self::assertSame('dissolved', OrgMembership::query()->sole()->end_reason);
        self::assertNotNull(OrgOwnershipStake::query()->sole()->ended_at);
        self::assertSame('100.000000', OrgOwnershipStake::query()->sole()->units);
        try {
            $this->handler->handle($this->actor(101), $this->payload());
            self::fail('Dissolution must not be followed by new share issuance');
        } catch (ConstitutionalViolation $error) {
            self::assertStringContainsString('dissolved', $error->getMessage());
        }
        self::assertSame(0, OrgOwnershipStake::query()->open()->count());
    }

    public function test_failed_dissolution_rolls_back_membership_and_stake_changes_together(): void
    {
        $this->dissolutionTables();
        $this->handler->handle($this->actor(101), $this->payload());
        $records = $this->createMock(PublicRecordService::class);
        $records->method('publish')->willThrowException(new RuntimeException('Fixture record failure'));
        $roles = $this->createMock(RoleService::class);
        $roles->expects($this->never())->method('flush');
        try {
            (new OrgRegistryService($records, $roles, $this->ownership))->dissolve(
                Organization::findOrFail($this->id(1)), $this->actor(101), 'Fixture closure',
            );
            self::fail('Expected fixture record failure');
        } catch (RuntimeException $error) {
            self::assertSame('Fixture record failure', $error->getMessage());
        }
        self::assertSame('active', Organization::findOrFail($this->id(1))->status);
        self::assertSame('active', OrgMembership::query()->sole()->status);
        self::assertNull(OrgOwnershipStake::query()->sole()->ended_at);
    }

    private function dissolutionTables(): void
    {
        Queue::fake();
        $schema = DB::connection()->getSchemaBuilder();
        $schema->table('organizations', function (Blueprint $t) {
            foreach (['is_cgc', 'is_active', 'is_registered'] as $name) $t->boolean($name)->default(false);
            foreach (['name', 'dissolution_reason', 'board_id', 'jurisdiction_id'] as $name) $t->string($name)->nullable();
            $t->timestamp('dissolved_at')->nullable();
            $t->timestamps();
        });
        $schema->create('org_contracts', function (Blueprint $t) {
            $t->string('organization_id');
            $t->string('status');
            $t->softDeletes();
        });
        $schema->create('org_workers', function (Blueprint $t) {
            foreach (['employer_id', 'employer_type', 'status'] as $name) $t->string($name);
            $t->timestamp('ended_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        $schema->create('org_document_packages', function (Blueprint $t) {
            $t->string('organization_id');
            $t->string('status');
            $t->timestamps();
            $t->softDeletes();
        });
    }

    private function assertRefused(?User $actor, array $payload): void
    {
        try {
            $this->handler->handle($actor, $payload);
            self::fail('Expected a constitutional refusal');
        } catch (ConstitutionalViolation $error) {
            self::assertNotSame('', $error->getMessage());
        }
        self::assertSame(0, DB::table('org_ownership_stakes')->count());
        self::assertSame(0, DB::table('org_memberships')->count());
    }

    private function stake(int $id, int $org, string $units): array
    {
        return ['id' => $this->id($id), 'organization_id' => $this->id($org), 'holder_type' => 'users',
            'holder_id' => $this->id(201), 'units' => $units, 'pct' => '19.0000', 'acquired_via' => 'issue', 'as_of' => now()];
    }

    private function actor(int $id): User { return (new User)->forceFill(['id' => $this->id($id)]); }
    private function id(int $id): string { return sprintf('90000000-0000-4000-8000-%012d', $id); }
    private function payload(array $overrides = []): array
    {
        return array_replace(['action' => 'issue_shares', 'organization_id' => $this->id(1),
            'holder_type' => 'users', 'holder_id' => $this->id(201), 'units' => '100'], $overrides);
    }
}
