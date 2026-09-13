<?php

namespace Tests\Unit;

use App\Http\Controllers\Organizations\OrgEconomyController;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\OrgSettingsService;
use App\Support\OrganizationFinancialHistory;
use App\Support\TransactionHistory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Private fixtures only; exercise real reader queries and Inertia prop selection. */
final class FinancialHistoryTest extends TestCase
{
    private string $original;
    protected function setUp(): void
    {
        parent::setUp(); $this->original = DB::getDefaultConnection();
        config(['database.connections.finance_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('finance_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName()); self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('market_transactions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('from_account_id')->nullable(); $t->uuid('to_account_id')->nullable();
            $t->string('amount'); $t->string('kind'); $t->string('memo')->nullable(); $t->timestamp('created_at', 6);
        });
        $schema->create('economic_account_bindings', function (Blueprint $t) { foreach (['account_id', 'owner_type', 'owner_id'] as $c) $t->string($c); });
        $schema->create('economic_accounts', function (Blueprint $t) { $t->uuid('id')->primary(); $t->string('balance'); $t->softDeletes(); });
        $schema->create('board_seats', function (Blueprint $t) { foreach (['board_id', 'holder_user_id', 'status'] as $c) $t->string($c); $t->softDeletes(); });
        $schema->create('tax_filings', function (Blueprint $t) {
            $t->uuid('id')->primary(); foreach (['account_id', 'levy_id', 'period', 'declared', 'assessed', 'status'] as $c) $t->string($c)->nullable();
        });
        $schema->create('levies', function (Blueprint $t) { $t->uuid('id')->primary(); foreach (['revenue_stream_id', 'base', 'rate'] as $c) $t->string($c); $t->boolean('civic_exempt'); });
        $schema->create('revenue_streams', function (Blueprint $t) { $t->uuid('id')->primary(); $t->string('name'); });
        $schema->create('org_conversions', function (Blueprint $t) {
            $t->uuid('id')->primary(); foreach (['organization_id', 'direction', 'via', 'status', 'fair_market_floor', 'fair_market_basis'] as $c) $t->string($c)->nullable();
            $t->timestamp('completed_at')->nullable(); $t->softDeletes();
        });
        DB::table('economic_account_bindings')->insert(['account_id' => $this->id(1), 'owner_type' => 'organizations', 'owner_id' => $this->id(10)]);
        DB::table('economic_accounts')->insert(['id' => $this->id(1), 'balance' => '50.000000']);
        DB::table('board_seats')->insert(['board_id' => $this->id(30), 'holder_user_id' => $this->id(32), 'status' => 'seated']);
        DB::table('revenue_streams')->insert(['id' => $this->id(60), 'name' => 'Public revenue']);
        DB::table('levies')->insert(['id' => $this->id(61), 'revenue_stream_id' => $this->id(60), 'base' => 'land', 'rate' => '0.025000', 'civic_exempt' => true]);
        foreach (range(1, 46) as $n) {
            // Timestamp ties cross a page boundary, plus microseconds and different days.
            DB::table('market_transactions')->insert([
                'id' => $this->id(100 + $n), 'from_account_id' => $this->id($n % 2 ? 1 : 2), 'to_account_id' => $this->id($n === 46 ? 2 : ($n % 2 ? 2 : 1)),
                'amount' => '999999999999999999.123456', 'kind' => 'transfer', 'created_at' => $n < 25 ? '2026-09-12 12:00:00.123456' : '2026-09-13 12:00:00.123457',
            ]);
            DB::table('tax_filings')->insert(['id' => $this->id(200 + $n), 'account_id' => $this->id($n === 46 ? 2 : 1), 'levy_id' => $this->id(61), 'period' => 'September', 'declared' => '100.123456', 'assessed' => null, 'status' => 'filed']);
            DB::table('org_conversions')->insert(['id' => $this->id(300 + $n), 'organization_id' => $this->id($n === 46 ? 11 : 10), 'direction' => 'public_to_private', 'via' => 'act', 'status' => 'proposed', 'fair_market_floor' => '987654321.123456']);
        }
        // Transfer to self must appear once. Internal organization transfers too.
        DB::table('market_transactions')->where('id', $this->id(145))->update(['to_account_id' => $this->id(1)]);
    }
    protected function tearDown(): void { DB::purge('finance_fixture'); DB::setDefaultConnection($this->original); parent::tearDown(); }
    private function id(int $n): string { return sprintf('40000000-0000-4000-8000-%012d', $n); }
    private function path(): string { return '/organizations/'.$this->id(10).'/economy'; }
    private function transactions(string $url = '/economy/wallet', array $accounts = [1]): array
    {
        return (new TransactionHistory)->page(Request::create($url), array_map($this->id(...), $accounts), '/economy/wallet');
    }

    public function test_transaction_pages_are_complete_stable_and_bidirectional_with_no_self_transfer_duplicates(): void
    {
        $a = $this->transactions(); $b = $this->transactions($a['pagination']['next']); $c = $this->transactions($b['pagination']['next']);
        self::assertCount(20, $a['transactions']); self::assertCount(20, $b['transactions']); self::assertCount(5, $c['transactions']);
        self::assertSame(array_map($this->id(...), range(145, 101)), array_column(array_merge($a['transactions'], $b['transactions'], $c['transactions']), 'id'));
        self::assertSame($b['transactions'], $this->transactions($c['pagination']['previous'])['transactions']);
        self::assertSame($a['transactions'], $this->transactions($b['pagination']['previous'])['transactions']);
        self::assertNull($a['pagination']['previous']); self::assertNull($c['pagination']['next']);
        self::assertSame('999999999999999999.123456', $a['transactions'][0]['amount']);
        self::assertSame('out', $a['transactions'][0]['direction']);
        self::assertSame('in', $a['transactions'][1]['direction']);
    }

    public function test_multiple_owned_accounts_merge_without_duplicate_internal_transfers(): void
    {
        $a = $this->transactions(accounts: [1, 2]); $b = $this->transactions($a['pagination']['next'], [1, 2]); $c = $this->transactions($b['pagination']['next'], [1, 2]);
        self::assertSame(array_map($this->id(...), range(146, 101)), array_column(array_merge($a['transactions'], $b['transactions'], $c['transactions']), 'id'));
    }

    public function test_account_in_query_or_foreign_cursor_cannot_change_scope_and_reads_are_limited(): void
    {
        DB::enableQueryLog(); DB::flushQueryLog();
        $a = $this->transactions('/economy/wallet?account_id='.$this->id(2));
        self::assertNotContains($this->id(146), array_column($a['transactions'], 'id'));
        self::assertCount(2, DB::getQueryLog());
        foreach (DB::getQueryLog() as $query) { self::assertStringContainsString('limit 21', $query['query']); self::assertContains($this->id(1), $query['bindings']); }
        self::assertSame([], $this->transactions($a['pagination']['next'], [999])['transactions']);
        DB::flushQueryLog(); self::assertSame([], $this->transactions(accounts: [])['transactions']); self::assertSame([], DB::getQueryLog());
    }

    public function test_malformed_and_impossible_timestamp_cursors_are_rejected_before_query(): void
    {
        DB::enableQueryLog();
        foreach (['bad', (new Cursor(['id' => 'not-uuid', 'created_at' => '2026-09-13 00:00:00']))->encode(),
            (new Cursor(['id' => $this->id(145), 'created_at' => '2026-02-30 12:00:00']))->encode(),
            (new Cursor(['id' => $this->id(145), 'created_at' => 'tomorrow']))->encode(),
            (new Cursor(['id' => $this->id(145), 'created_at' => '2026-09-13 12:00:00', 'account' => 'other']))->encode()] as $cursor) {
            DB::flushQueryLog();
            try { $this->transactions('/economy/wallet?'.http_build_query(['transactions_cursor' => $cursor])); self::fail('Expected cursor rejection'); }
            catch (ValidationException $e) { self::assertArrayHasKey('transactions_cursor', $e->errors()); }
            self::assertSame([], DB::getQueryLog());
        }
    }

    public function test_tax_and_conversion_histories_reach_all_records_with_route_scope_and_exact_amounts(): void
    {
        foreach (['taxes' => ['taxes', 'tax_pages', 'taxes_cursor', 245, 201], 'conversions' => ['conversions', 'conversion_pages', 'conversions_cursor', 345, 301]] as [$key, $pages, $cursor, $high, $low]) {
            $a = $this->partial($this->path().'?organization_id='.$this->id(11).'&account_id='.$this->id(2), "$key,$pages");
            $b = $this->partial($a[$pages]['next'], "$key,$pages"); $c = $this->partial($b[$pages]['next'], "$key,$pages");
            self::assertCount(20, $a[$key]); self::assertCount(20, $b[$key]); self::assertCount(5, $c[$key]);
            self::assertSame(array_map($this->id(...), range($high, $low)), array_column(array_merge($a[$key], $b[$key], $c[$key]), 'id'));
            self::assertSame($b[$key], $this->partial($c[$pages]['previous'], "$key,$pages")[$key]);
            self::assertSame($a[$key], $this->partial($b[$pages]['previous'], "$key,$pages")[$key]);
            self::assertNull($c[$pages]['next']);
            self::assertStringContainsString($cursor.'=', $a[$pages]['next']);
        }
        $taxes = $this->partial($this->path(), 'taxes')['taxes'];
        self::assertSame('100.123456', $taxes[0]['declared']); self::assertNull($taxes[0]['assessed']);
        self::assertSame('Public revenue', $taxes[0]['stream']); self::assertSame('0.025000', $taxes[0]['rate']);
    }

    public function test_partial_reads_keep_private_access_and_skip_unrelated_sections(): void
    {
        DB::enableQueryLog(); DB::flushQueryLog();
        $blocked = $this->partial($this->path().'?transactions_cursor=bad&taxes_cursor=bad', 'ledger,taxes,tax_pages', 33);
        self::assertTrue($blocked['ledger']['restricted']); self::assertSame([], $blocked['taxes']);
        foreach (DB::getQueryLog() as $q) self::assertDoesNotMatchRegularExpression('/market_transactions|tax_filings|economic_account/', $q['query']);
        foreach ([31, 32] as $viewer) {
            DB::flushQueryLog(); $ledger = $this->partial($this->path(), 'ledger', $viewer);
            self::assertCount(20, $ledger['ledger']['movements']); self::assertNotNull($ledger['ledger']['pagination']['next']);
            foreach (DB::getQueryLog() as $q) self::assertDoesNotMatchRegularExpression('/tax_filings|org_conversions|org_ownership/', $q['query']);
        }
        DB::flushQueryLog(); $this->partial($this->path(), 'taxes,tax_pages');
        self::assertCount(1, array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'from "tax_filings"')));
        foreach (DB::getQueryLog() as $q) self::assertDoesNotMatchRegularExpression('/market_transactions|org_conversions/', $q['query']);
    }

    private function partial(string $url, string $only, int $viewer = 31): array
    {
        $request = Request::create($url); $request->setUserResolver(fn () => (new User)->forceFill(['id' => $this->id($viewer)]));
        $request->headers->set('X-Inertia', 'true'); $request->headers->set('X-Inertia-Partial-Component', 'Economy/OrgSettings');
        $request->headers->set('X-Inertia-Partial-Data', $only);
        $org = (new Organization)->forceFill(['id' => $this->id(10), 'agent_user_id' => $this->id(31), 'board_id' => $this->id(30), 'structure' => 'stock']);
        return (new OrgEconomyController($this->createMock(OrgSettingsService::class)))->show($request, $org)->toResponse($request)->getData(true)['props'];
    }
}
