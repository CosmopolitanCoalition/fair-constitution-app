<?php

namespace Tests\Unit;

use App\Support\PendingOrderDirectory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Explicit SQLite memory fixtures, never live orders. */
final class PendingOrderDirectoryTest extends TestCase
{
    private string $original;
    private PendingOrderDirectory $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.pending_order_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('pending_order_fixture');
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        self::assertSame('sqlite', DB::connection()->getDriverName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('marketplace_listings', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('seller_account_id'); $t->softDeletes();
        });
        $schema->create('marketplace_orders', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('listing_id'); $t->string('buyer_account_id');
            $t->string('quantity'); $t->string('status'); $t->timestamp('created_at')->nullable();
        });
        DB::table('marketplace_listings')->insert([
            ['id' => $this->id(1), 'seller_account_id' => $this->id(2)],
            ['id' => $this->id(3), 'seller_account_id' => $this->id(4)],
        ]);
        for ($i = 1; $i <= 45; $i++) $this->order(100 + $i);
        $this->order(900, 3); $this->order(901, 1, 'settled'); $this->order(902, 1, 'cancelled');
        $this->directory = new PendingOrderDirectory();
    }

    protected function tearDown(): void { DB::purge('pending_order_fixture'); DB::setDefaultConnection($this->original); parent::tearDown(); }
    private function id(int $n): string { return sprintf('30000000-0000-4000-8000-%012d', $n); }
    private function order(int $id, int $listing = 1, string $status = 'placed'): void
    {
        DB::table('marketplace_orders')->insert(['id' => $this->id($id), 'listing_id' => $this->id($listing), 'buyer_account_id' => $this->id(50), 'quantity' => '1.250000', 'status' => $status, 'created_at' => '2026-09-12 12:00:00']);
    }
    private function page(?string $url = null, ?int $viewer = 2, int $listing = 1): array
    {
        return $this->directory->page(Request::create($url ?? '/economy/market/'.$this->id($listing)), $this->id($listing), $viewer === null ? null : $this->id($viewer));
    }

    public function test_all_45_pending_orders_are_reachable_both_directions_without_unrelated_orders(): void
    {
        DB::connection()->enableQueryLog();
        $first = $this->page(); $second = $this->page($first['pagination']['next']); $third = $this->page($second['pagination']['next']);
        self::assertCount(20, $first['orders']); self::assertCount(20, $second['orders']); self::assertCount(5, $third['orders']);
        self::assertNull($first['pagination']['previous']); self::assertNull($third['pagination']['next']);
        self::assertSame(array_map(fn ($n) => $this->id($n), range(101, 145)), array_column(array_merge($first['orders'], $second['orders'], $third['orders']), 'id'));
        self::assertSame($second['orders'], $this->page($third['pagination']['previous'])['orders']);
        self::assertSame($first['orders'], $this->page($second['pagination']['previous'])['orders']);
        self::assertSame('1.250000', $first['orders'][0]['quantity']);
        self::assertSame('2026-09-12T12:00:00+00:00', $first['orders'][0]['at']);
        foreach (DB::getQueryLog() as $query) {
            self::assertStringNotContainsString('offset', strtolower($query['query']));
            if (str_contains($query['query'], 'marketplace_orders')) {
                self::assertStringContainsString('limit 21', strtolower($query['query']));
                self::assertContains($this->id(1), $query['bindings']);
                self::assertContains('placed', $query['bindings']);
            }
        }
    }

    public function test_guest_wrong_seller_and_deleted_listing_do_not_read_order_rows(): void
    {
        DB::connection()->enableQueryLog();
        foreach ([null, 4] as $viewer) {
            $result = $this->page(viewer: $viewer);
            self::assertSame([], $result['orders']); self::assertNull($result['pagination']['next']);
        }
        DB::table('marketplace_listings')->where('id', $this->id(1))->update(['deleted_at' => now()]);
        self::assertSame([], $this->page()['orders']);
        foreach (DB::getQueryLog() as $query) self::assertStringNotContainsString('marketplace_orders', $query['query']);
    }

    public function test_cursor_cannot_escape_the_selected_listing_or_seller(): void
    {
        $first = $this->page();
        self::assertSame([], $this->page($first['pagination']['next'], viewer: 2, listing: 3)['orders']);
        $other = $this->page($first['pagination']['next'], viewer: 4, listing: 3);
        self::assertSame([$this->id(900)], array_column($other['orders'], 'id'));
        self::assertStringContainsString('/economy/market/'.$this->id(1).'?orders_cursor=', $first['pagination']['next']);
    }

    public function test_bad_cursors_fail_before_any_domain_read(): void
    {
        DB::connection()->enableQueryLog();
        foreach (['garbage', str_repeat('x', 1025), (new Cursor(['id' => 'not-uuid']))->encode(), (new Cursor(['id' => $this->id(101), 'extra' => true]))->encode()] as $cursor) {
            DB::connection()->flushQueryLog();
            try { $this->page('/economy/market/'.$this->id(1).'?'.http_build_query(['orders_cursor' => $cursor])); self::fail('Malformed cursor must fail.'); }
            catch (ValidationException $error) { self::assertArrayHasKey('orders_cursor', $error->errors()); }
            self::assertSame([], DB::getQueryLog());
        }
    }
}
