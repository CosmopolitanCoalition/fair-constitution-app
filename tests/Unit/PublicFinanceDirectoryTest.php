<?php

namespace Tests\Unit;

use App\Http\Controllers\Economy\EconomyController;
use App\Services\Economy\AccountService;
use App\Services\Economy\CurrencyReportService;
use App\Services\Economy\CurrencyTelemetryService;
use App\Services\Economy\IssuanceService;
use App\Services\Economy\LedgerService;
use App\Services\SettingsResolver;
use App\Support\PublicFinanceDirectory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Explicit private SQLite only: real readers and controller, no live helper or civic writes. */
final class PublicFinanceDirectoryTest extends TestCase
{
    private string $original;
    protected function setUp(): void
    {
        parent::setUp(); $this->original = DB::getDefaultConnection();
        config(['database.connections.public_finance_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('public_finance_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName()); self::assertSame(':memory:', DB::connection()->getDatabaseName());
        Bus::fake(); $schema = DB::connection()->getSchemaBuilder();
        $schema->create('jurisdictions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('parent_id')->nullable(); $t->string('name'); $t->string('slug'); $t->integer('adm_level'); $t->softDeletes();
        });
        $schema->create('currencies', function (Blueprint $t) {
            $t->uuid('id')->primary(); foreach (['jurisdiction_id', 'name', 'code', 'symbol', 'unit_kind', 'worth_basis', 'subdivisions'] as $c) $t->string($c)->nullable();
            $t->integer('precision')->default(6); $t->softDeletes();
        });
        $schema->create('departments', function (Blueprint $t) { $t->uuid('id')->primary(); $t->uuid('jurisdiction_id'); $t->softDeletes(); });
        $schema->create('treasury_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary(); foreach (['owner_type', 'owner_id', 'currency_id', 'label', 'balance'] as $c) $t->string($c); $t->boolean('public'); $t->softDeletes();
        });
        $schema->create('ledger_entries', function (Blueprint $t) {
            $t->bigInteger('seq')->primary(); foreach (['currency_id', 'account_type', 'account_id', 'amount', 'kind', 'direction', 'hash'] as $c) $t->string($c); $t->timestamp('created_at');
        });
        $schema->create('issuance_events', function (Blueprint $t) {
            $t->uuid('id')->primary(); foreach (['currency_id', 'direction', 'amount', 'reason'] as $c) $t->string($c); $t->timestamp('created_at');
        });
        $schema->create('budgets', function (Blueprint $t) {
            $t->uuid('id')->primary(); foreach (['jurisdiction_id', 'currency_id', 'fiscal_label', 'total', 'status'] as $c) $t->string($c);
            $t->uuid('enacting_act_id')->nullable(); $t->timestamp('enacted_at')->nullable(); $t->softDeletes();
        });
        $schema->create('budget_lines', function (Blueprint $t) { $t->uuid('id')->primary(); foreach (['budget_id', 'line', 'amount'] as $c) $t->string($c); });
        $schema->create('revenue_streams', function (Blueprint $t) {
            $t->uuid('id')->primary(); foreach (['jurisdiction_id', 'currency_id', 'name', 'kind', 'status'] as $c) $t->string($c);
            $t->uuid('enacting_act_id')->nullable(); $t->softDeletes();
        });
        $schema->create('levies', function (Blueprint $t) { $t->uuid('id')->primary(); foreach (['revenue_stream_id', 'base', 'rate'] as $c) $t->string($c); $t->boolean('civic_exempt'); });
        $schema->create('borrowings', function (Blueprint $t) {
            $t->uuid('id')->primary(); foreach (['jurisdiction_id', 'currency_id', 'principal', 'terms', 'status'] as $c) $t->string($c);
            $t->uuid('lender_account_id')->nullable(); $t->timestamp('created_at');
        });
        $schema->create('laws', function (Blueprint $t) { $t->uuid('id')->primary(); $t->string('act_number'); $t->string('title'); });
        $schema->create('ubi_disbursements', function (Blueprint $t) { $t->uuid('currency_id'); $t->timestamp('ran_at'); });
        // The current full treasury response also reads its next stipend clock.
        $schema->create('clock_timers', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('state'); $t->string('clock_id');
            $t->string('subject_type'); $t->uuid('subject_id');
            $t->timestamp('armed_at'); $t->timestamp('fires_at')->nullable(); $t->softDeletes();
        });
        $migration = require database_path('migrations/2026_09_12_180000_currency_reports.php'); $migration->up();
        DB::table('jurisdictions')->insert([
            ['id' => $this->id(10), 'parent_id' => null, 'name' => 'Fixture world', 'slug' => 'fixture-world', 'adm_level' => 0],
            ['id' => $this->id(11), 'parent_id' => $this->id(10), 'name' => 'Other place', 'slug' => 'other-place', 'adm_level' => 1],
        ]);
        DB::table('currencies')->insert(['id' => $this->id(1), 'jurisdiction_id' => $this->id(10), 'name' => 'Fixture money', 'code' => 'FXT', 'symbol' => 'F', 'unit_kind' => 'abstract']);
        DB::table('laws')->insert(['id' => $this->id(50), 'act_number' => '45', 'title' => 'Fixture public finance act']);
        foreach ([1000 => 10, 1001 => 11] as $account => $place) $this->account($account, 'jurisdictions', $place);
        foreach (range(1, 46) as $n) {
            $place = $this->id($n === 46 ? 11 : 10);
            DB::table('jurisdictions')->insert(['id' => $this->id(100 + $n), 'parent_id' => $place, 'name' => 'Place '.$n, 'slug' => 'place-'.$n, 'adm_level' => 1]);
            DB::table('departments')->insert(['id' => $this->id(2000 + $n), 'jurisdiction_id' => $place]);
            $this->account(3000 + $n, 'departments', 2000 + $n);
            DB::table('budgets')->insert(['id' => $this->id(4000 + $n), 'jurisdiction_id' => $place, 'currency_id' => $this->id(1), 'fiscal_label' => 'Budget '.$n,
                'total' => '999999999999999999.123456', 'status' => 'enacted', 'enacted_at' => '2026-09-13 00:00:00', 'enacting_act_id' => $this->id(50)]);
            DB::table('revenue_streams')->insert(['id' => $this->id(5000 + $n), 'jurisdiction_id' => $place, 'currency_id' => $this->id(1), 'name' => 'Revenue '.$n,
                'kind' => 'levy', 'status' => 'active', 'enacting_act_id' => $this->id(50)]);
            DB::table('borrowings')->insert(['id' => $this->id(6000 + $n), 'jurisdiction_id' => $place, 'currency_id' => $this->id(1), 'principal' => '10.123456',
                'status' => 'drawn', 'terms' => 'Fixture terms', 'lender_account_id' => $this->id(7777), 'created_at' => '2026-09-13 00:00:00']);
            DB::table('issuance_events')->insert(['id' => $this->id(7000 + $n), 'currency_id' => $this->id($n === 46 ? 2 : 1), 'direction' => 'mint',
                'amount' => '1.123456', 'reason' => 'Fixture issuance', 'created_at' => '2026-09-13 00:00:00']);
            DB::table('budget_lines')->insert(['id' => $this->id(8000 + $n), 'budget_id' => $this->id($n === 46 ? 4046 : 4045), 'line' => 'Line '.$n, 'amount' => '99.123456']);
            DB::table('levies')->insert(['id' => $this->id(9000 + $n), 'revenue_stream_id' => $this->id($n === 46 ? 5046 : 5045), 'base' => 'transaction', 'rate' => '0.123456', 'civic_exempt' => true]);
        }
        foreach (range(1, 114) as $seq) DB::table('ledger_entries')->insert([
            'seq' => $seq, 'currency_id' => $this->id($seq === 114 ? 2 : 1), 'account_type' => $seq === 112 ? 'economic_accounts' : 'treasury_accounts',
            'account_id' => $this->id($seq === 112 ? 7777 : ($seq === 113 ? 1001 : 1000)), 'direction' => 'credit', 'amount' => '7.123456',
            'kind' => 'fixture', 'hash' => str_repeat('a', 64), 'created_at' => '2026-09-13 00:00:00',
        ]);
    }
    protected function tearDown(): void { Bus::assertNothingDispatched(); DB::purge('public_finance_fixture'); DB::setDefaultConnection($this->original); parent::tearDown(); }
    private function id(int $n): string { return sprintf('50000000-0000-4000-8000-%012d', $n); }
    private function account(int $id, string $type, int $owner): void
    {
        DB::table('treasury_accounts')->insert(['id' => $this->id($id), 'owner_type' => $type, 'owner_id' => $this->id($owner), 'currency_id' => $this->id(1),
            'label' => 'Treasury '.$id, 'balance' => '999999999999999999.123456', 'public' => true]);
    }
    private function reader(string $url = '/economy/treasury'): PublicFinanceDirectory { return new PublicFinanceDirectory(Request::create($url), $this->id(1), $this->id(10)); }

    public function test_place_histories_and_nested_lines_are_complete_bidirectional_and_keep_context(): void
    {
        foreach (['budgets' => [4000, 'budgets_cursor'], 'borrowings' => [6000, 'borrowings_cursor'], 'revenue' => [5000, 'revenue_cursor'],
            'issuance' => [7000, 'issuance_cursor'], 'lines' => [8000, 'lines_cursor'], 'levies' => [9000, 'levies_cursor']] as $method => [$base, $cursor]) {
            $url = '/economy/treasury?jurisdiction=fixture-world&budget='.$this->id(4045).'&revenue_source='.$this->id(5045).'&account='.$this->id(1000);
            $a = $this->reader($url)->$method(); $b = $this->reader($a['pagination']['next'])->$method(); $c = $this->reader($b['pagination']['next'])->$method();
            self::assertCount(20, $a['records']); self::assertCount(20, $b['records']); self::assertCount(5, $c['records']);
            self::assertSame(array_map($this->id(...), range($base + 45, $base + 1)), array_column(array_merge($a['records'], $b['records'], $c['records']), 'id'));
            self::assertSame($b['records'], $this->reader($c['pagination']['previous'])->$method()['records']);
            self::assertSame($a['records'], $this->reader($b['pagination']['previous'])->$method()['records']);
            self::assertNull($a['pagination']['previous']); self::assertNull($c['pagination']['next']);
            self::assertStringContainsString('budget='.$this->id(4045), $a['pagination']['next']);
            self::assertStringContainsString('revenue_source='.$this->id(5045), $a['pagination']['next']);
            self::assertStringNotContainsString($cursor.'=', $c['first']);
        }
        $budget = $this->reader()->budgets()['records'][0];
        self::assertSame('999999999999999999.123456', $budget['total']); self::assertNull($budget['lines']); self::assertSame([], $budget['line_items']);
        self::assertSame(['act_number' => '45', 'title' => 'Fixture public finance act'], $budget['enacting_act']);
    }

    public function test_accounts_and_children_are_paged_in_the_selected_place_without_world_enumeration(): void
    {
        foreach (['accounts', 'children'] as $method) {
            $a = $this->reader()->$method(); $b = $this->reader($a['pagination']['next'])->$method(); $c = $this->reader($b['pagination']['next'])->$method();
            self::assertCount(20, $a['records']); self::assertCount(20, $b['records']); self::assertCount(6, $c['records']);
            self::assertSame($b['records'], $this->reader($c['pagination']['previous'])->$method()['records']);
            self::assertNotContains($this->id($method === 'accounts' ? 3046 : 146), array_column(array_merge($a['records'], $b['records'], $c['records']), 'id'));
        }
        self::assertSame($this->id(1000), $this->reader()->selectedAccount()['id']);
        self::assertSame($this->id(11), $this->reader('/economy/treasury?jurisdiction=other-place')->place()['id']);
        self::assertSame($this->id(1001), $this->reader('/economy/treasury?jurisdiction=other-place')->selectedAccount()['id']);
        $context = $this->reader('/economy/treasury?jurisdiction=other-place')->context();
        self::assertSame([$this->id(10), $this->id(11)], array_column($context['chain'], 'id'));
    }

    public function test_ledger_reaches_beyond_fifty_and_public_currency_view_keeps_pseudonymous_legs(): void
    {
        $a = $this->reader()->ledger(); $b = $this->reader($a['pagination']['next'])->ledger(); $c = $this->reader($b['pagination']['next'])->ledger();
        self::assertSame(range(111, 1), array_column(array_merge($a['records'], $b['records'], $c['records']), 'seq'));
        self::assertCount(50, $a['records']); self::assertCount(11, $c['records']);
        self::assertSame($a['records'], $this->reader($b['pagination']['previous'])->ledger()['records']);
        $all = $this->reader('/economy/treasury?ledger_scope=currency')->ledger();
        self::assertSame(113, $all['records'][0]['seq']); self::assertSame('economic_accounts', $all['records'][1]['account_type']);
        self::assertSame($this->id(7777), $all['records'][1]['account_id']);
        $personal = $all['records'][1]; self::assertArrayNotHasKey('user_id', $personal); self::assertArrayNotHasKey('owner_id', $personal);
        self::assertSame([], $this->reader('/economy/treasury?account='.$this->id(3045))->ledger()['records']);
        // A valid seek position cannot carry a different account or place into the query.
        $other = $this->reader('/economy/treasury?jurisdiction=other-place&ledger_cursor='.(new Cursor(['seq' => 999]))->encode())->ledger();
        self::assertSame([113], array_column($other['records'], 'seq'));
    }

    public function test_exact_selection_rejects_foreign_deleted_nonpublic_and_wrong_currency_records(): void
    {
        DB::table('treasury_accounts')->where('id', $this->id(3001))->update(['deleted_at' => '2026-09-13']);
        DB::table('treasury_accounts')->where('id', $this->id(3002))->update(['public' => false]);
        DB::table('treasury_accounts')->where('id', $this->id(3003))->update(['currency_id' => $this->id(2)]);
        DB::table('departments')->where('id', $this->id(2004))->update(['deleted_at' => '2026-09-13']);
        foreach (['account' => [1001, 3001, 3002, 3003, 3004, 3046], 'budget' => [4046], 'revenue_source' => [5046]] as $key => $ids) {
            $method = ['account' => 'selectedAccount', 'budget' => 'selectedBudget', 'revenue_source' => 'selectedRevenue'][$key];
            foreach ($ids as $id) {
                try { $this->reader('/economy/treasury?'.$key.'='.$this->id($id))->$method(); self::fail('Expected exact-scope 404'); }
                catch (HttpException $e) { self::assertSame(404, $e->getStatusCode()); }
            }
        }
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->reader('/economy/treasury?jurisdiction=nonexistent-place');
    }

    public function test_invalid_cursors_are_rejected_before_history_reads_and_cannot_carry_new_scope(): void
    {
        DB::enableQueryLog();
        foreach (['accounts' => 'accounts_cursor', 'ledger' => 'ledger_cursor', 'issuance' => 'issuance_cursor', 'budgets' => 'budgets_cursor',
            'borrowings' => 'borrowings_cursor', 'revenue' => 'revenue_cursor', 'lines' => 'lines_cursor', 'levies' => 'levies_cursor', 'children' => 'places_cursor'] as $method => $key) {
            foreach (['bad', (new Cursor(['id' => $this->id(9999), 'account' => $this->id(1001)]))->encode()] as $cursor) {
                $reader = $this->reader('/economy/treasury?'.http_build_query([$key => $cursor])); DB::flushQueryLog();
                try { $reader->$method(); self::fail('Expected malformed cursor rejection'); }
                catch (ValidationException $e) { self::assertArrayHasKey($key, $e->errors()); }
                self::assertSame([], DB::getQueryLog());
            }
        }
    }

    public function test_partial_reads_execute_only_requested_bounded_histories_and_never_resolve_identity(): void
    {
        DB::enableQueryLog();
        foreach (['ledger' => 'ledger_entries', 'budgets' => 'budgets', 'revenue' => 'revenue_streams', 'issuance' => 'issuance_events',
            'borrowings' => 'borrowings', 'budget_lines' => 'budget_lines', 'levies' => 'levies', 'accounts' => 'treasury_accounts'] as $prop => $table) {
            DB::flushQueryLog();
            $rows = $this->partial('/economy/treasury?budget='.$this->id(4045).'&revenue_source='.$this->id(5045), $prop.','.$prop.'_pages');
            self::assertCount($prop === 'ledger' ? 50 : 20, $rows[$prop]);
            $reads = array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'from "'.$table.'"'));
            self::assertCount(1, $reads, $prop.' page and links share one query');
            foreach ($reads as $query) self::assertStringContainsString($prop === 'ledger' ? 'limit 51' : 'limit 21', $query['query']);
            foreach (DB::getQueryLog() as $query) {
                self::assertStringStartsWith('select ', strtolower($query['query']));
                self::assertDoesNotMatchRegularExpression('/sum\(|count\(|offset |economic_account_bindings|users|currency_reports|ubi_disbursements/i', $query['query']);
            }
        }
        DB::flushQueryLog(); $this->partial('/economy/treasury?ledger_cursor=invalid', 'budgets,budgets_pages');
        foreach (DB::getQueryLog() as $q) self::assertStringNotContainsString('ledger_entries', $q['query']);
    }

    public function test_totals_read_only_the_last_saved_currency_report_and_remain_null_without_it(): void
    {
        DB::enableQueryLog(); DB::flushQueryLog();
        $empty = $this->partial('/economy/treasury', 'totals,report');
        self::assertSame(['supply' => null, 'treasury_balance' => null], $empty['totals']); self::assertSame('not_started', $empty['report']['status']);
        foreach (DB::getQueryLog() as $q) self::assertDoesNotMatchRegularExpression('/issuance_events|ledger_entries|treasury_accounts|economic_accounts|sum\(|count\(/', $q['query']);
        DB::table('currency_reports')->insert(['currency_id' => $this->id(1), 'run_id' => $this->id(42), 'status' => 'running', 'phase' => 'wallets',
            'checkpoint' => '{}', 'totals' => '{"rows":20}', 'result' => '{"supply":"999999999999999999.123456","treasury_held":"8.000001"}',
            'started_at' => '2026-09-13', 'updated_at' => '2026-09-13', 'result_completed_at' => '2026-09-12', 'result_started_at' => '2026-09-11']);
        DB::flushQueryLog(); $saved = $this->partial('/economy/treasury', 'totals,report');
        self::assertSame(['supply' => '999999999999999999.123456', 'treasury_balance' => '8.000001'], $saved['totals']);
        self::assertSame('2026-09-12', $saved['report']['completed_at']); self::assertSame('running', $saved['report']['status']);
        self::assertCount(1, array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'currency_reports')));
    }

    public function test_empty_install_full_contract_has_arrays_and_no_fabricated_totals(): void
    {
        DB::table('currencies')->delete();
        $props = $this->partial('/economy/treasury', '');
        foreach (['accounts', 'ledger', 'issuance', 'budgets', 'revenue', 'borrowings', 'budget_lines', 'levies'] as $key) self::assertSame([], $props[$key]);
        self::assertNull($props['currency']); self::assertNull($props['totals']['supply']); self::assertSame($this->id(10), $props['finance_scope']['place']['id']);
    }

    public function test_populated_full_contract_skips_nested_reads_until_selected_and_keeps_every_array(): void
    {
        DB::enableQueryLog(); DB::flushQueryLog();
        $props = $this->partial('/economy/treasury', '');
        foreach (['accounts', 'ledger', 'issuance', 'budgets', 'revenue', 'borrowings', 'budget_lines', 'levies', 'places'] as $key) {
            self::assertIsArray($props[$key]); self::assertArrayHasKey('previous', $props[$key.'_pages']);
            self::assertArrayHasKey('next', $props[$key.'_pages']); self::assertIsString($props[$key.'_pages']['first']);
        }
        self::assertSame([], $props['budget_lines']); self::assertSame([], $props['levies']); self::assertCount(50, $props['ledger']);
        foreach (DB::getQueryLog() as $q) self::assertDoesNotMatchRegularExpression('/budget_lines|from "levies"|sum\(|count\(|economic_account_bindings|offset /', $q['query']);
        self::assertSame(['supply' => null, 'treasury_balance' => null], $props['totals']);
    }

    private function partial(string $url, string $only): array
    {
        $request = Request::create($url); $request->headers->set('X-Inertia', 'true');
        if ($only !== '') { $request->headers->set('X-Inertia-Partial-Component', 'Economy/Treasury'); $request->headers->set('X-Inertia-Partial-Data', $only); }
        $ledger = $this->createMock(LedgerService::class); $ledger->expects($this->never())->method('verifyChain');
        $issuance = $this->createMock(IssuanceService::class); $issuance->expects($this->never())->method('supply');
        $accounts = $this->createMock(AccountService::class); $accounts->expects($this->never())->method('accountIdFor');
        $settings = $this->createMock(SettingsResolver::class); $settings->method('resolve')->willReturn(null);
        $telemetry = $this->createMock(CurrencyTelemetryService::class); $telemetry->expects($this->never())->method('snapshot');
        return (new EconomyController($ledger, $issuance, $accounts, $settings, $telemetry))->treasury($request)->toResponse($request)->getData(true)['props'];
    }
}
