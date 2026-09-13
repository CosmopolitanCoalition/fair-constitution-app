<?php

namespace Tests\Unit;

use App\Support\StipendReceiptDirectory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class StipendReceiptDirectoryTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp(); $this->original = DB::getDefaultConnection();
        config(['database.connections.receipt_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('receipt_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName()); self::assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::connection()->getSchemaBuilder()->create('ubi_receipts', function (Blueprint $t) {
            $t->string('id')->primary(); foreach (['account_id', 'base', 'bump', 'amount'] as $field) $t->string($field);
            $t->timestamp('created_at')->nullable();
        });
        for ($i = 1; $i <= 46; $i++) DB::table('ubi_receipts')->insert([
            'id' => $this->id(100 + $i), 'account_id' => $this->id($i === 46 ? 2 : 1),
            'base' => '100.000000', 'bump' => '2.500000', 'amount' => '102.500000', 'created_at' => '2026-09-12 12:00:00',
        ]);
    }

    protected function tearDown(): void { DB::purge('receipt_fixture'); DB::setDefaultConnection($this->original); parent::tearDown(); }
    private function id(int $n): string { return sprintf('40000000-0000-4000-8000-%012d', $n); }
    private function page(string $url = '/economy/wallet', ?int $account = 1): array
    {
        return (new StipendReceiptDirectory())->page(Request::create($url), $account === null ? null : $this->id($account));
    }

    public function test_all_45_receipts_are_accessible_both_directions_with_exact_amount_strings(): void
    {
        $a = $this->page(); $b = $this->page($a['pagination']['next']); $c = $this->page($b['pagination']['next']);
        self::assertCount(20, $a['receipts']); self::assertCount(20, $b['receipts']); self::assertCount(5, $c['receipts']);
        self::assertNull($a['pagination']['previous']); self::assertNull($c['pagination']['next']);
        self::assertSame(array_map(fn ($n) => $this->id($n), range(101, 145)), array_column(array_merge($a['receipts'], $b['receipts'], $c['receipts']), 'id'));
        self::assertSame($b['receipts'], $this->page($c['pagination']['previous'])['receipts']);
        self::assertSame($a['receipts'], $this->page($b['pagination']['previous'])['receipts']);
        self::assertSame('102.500000', $a['receipts'][0]['amount']);
    }

    public function test_query_account_ids_and_foreign_cursors_never_change_server_account_scope(): void
    {
        $url = '/economy/wallet?'.http_build_query(['account_id' => $this->id(2), 'account' => $this->id(2)]);
        $page = $this->page($url);
        self::assertSame($this->id(101), $page['receipts'][0]['id']);
        $other = $this->page($page['pagination']['next'], 2);
        self::assertSame([$this->id(146)], array_column($other['receipts'], 'id'));
        self::assertStringNotContainsString('account_id', json_encode($page));
        self::assertStringContainsString('/economy/wallet?receipts_cursor=', $page['pagination']['next']);
    }

    public function test_no_account_returns_empty_without_any_domain_query(): void
    {
        DB::connection()->enableQueryLog();
        self::assertSame(['receipts' => [], 'pagination' => ['previous' => null, 'next' => null]], $this->page(account: null));
        self::assertSame([], DB::getQueryLog());
    }

    public function test_invalid_cursors_fail_before_querying_receipts(): void
    {
        DB::connection()->enableQueryLog();
        foreach (['bad', str_repeat('x', 1025), (new Cursor(['id' => 'bad']))->encode(), (new Cursor(['id' => $this->id(101), 'extra' => 1]))->encode()] as $cursor) {
            DB::connection()->flushQueryLog();
            try { $this->page('/economy/wallet?'.http_build_query(['receipts_cursor' => $cursor])); self::fail('Invalid cursor should fail.'); }
            catch (ValidationException $error) { self::assertArrayHasKey('receipts_cursor', $error->errors()); }
            self::assertSame([], DB::getQueryLog());
        }
    }
}
