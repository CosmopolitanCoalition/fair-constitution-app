<?php

namespace Tests\Unit;

use App\Support\OwnedAssetDirectory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Private item fixtures in memory; no world database or migration traits. */
final class OwnedAssetDirectoryTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.owned_asset_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('owned_asset_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
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
        $schema->create('marketplace_listings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('asset_id')->nullable();
            $table->string('status')->default('open');
            $table->timestamp('deleted_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('owned_asset_fixture');
        DB::purge('owned_asset_fixture');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_no_authorized_account_returns_empty_without_asset_queries(): void
    {
        $this->asset(1, 'Mine');
        $this->logging();
        self::assertSame(OwnedAssetDirectory::empty(), app(OwnedAssetDirectory::class)->page(Request::create('/economy/wallet?account_id='.$this->id(900)), null));
        self::assertSame([], DB::getQueryLog());
    }

    public function test_roster_is_owner_scoped_and_preserves_quantities_without_identity_enrichment(): void
    {
        $this->asset(1, 'Owned', ['quantity' => '123456789012345678.123456', 'kind' => 'virtual']);
        $this->asset(2, 'Other person item', ['owner_account_id' => $this->id(901)]);
        $this->asset(3, 'Deleted', ['deleted_at' => '2026-01-01']);
        $this->logging();
        $page = $this->page('/economy/wallet?account_id='.$this->id(901));
        self::assertTrue($page['available']);
        self::assertSame([$this->id(1)], array_column($page['assets'], 'id'));
        self::assertSame('123456789012345678.123456', $page['assets'][0]['quantity']);
        self::assertNull($page['assets'][0]['at']);
        self::assertSame(['id', 'name', 'kind', 'quantity', 'origin', 'at'], array_keys($page['assets'][0]));
        $queries = DB::getQueryLog();
        self::assertCount(1, $queries);
        self::assertStringContainsString('"owner_account_id" = ?', $queries[0]['query']);
        self::assertContains($this->id(900), $queries[0]['bindings']);
        self::assertNotContains($this->id(901), $queries[0]['bindings']);
        self::assertStringContainsString('limit 21', $queries[0]['query']);
        self::assertStringNotContainsString('join', $queries[0]['query']);
        self::assertStringNotContainsString('count(', $queries[0]['query']);
    }

    public function test_eligible_picker_probes_open_listings_for_each_owned_asset(): void
    {
        foreach (range(1, 5) as $id) {
            $this->asset($id, 'Item '.$id);
        }
        DB::table('marketplace_listings')->insert([
            ['id' => $this->id(101), 'asset_id' => $this->id(1), 'status' => 'open', 'deleted_at' => null],
            ['id' => $this->id(102), 'asset_id' => $this->id(2), 'status' => 'closed', 'deleted_at' => null],
            ['id' => $this->id(103), 'asset_id' => null, 'status' => 'open', 'deleted_at' => null],
            ['id' => $this->id(104), 'asset_id' => $this->id(999), 'status' => 'open', 'deleted_at' => null],
            // Keep the previous picker's exclusion for an open soft-deleted row.
            ['id' => $this->id(105), 'asset_id' => $this->id(5), 'status' => 'open', 'deleted_at' => '2026-01-01'],
        ]);
        $this->logging();
        $eligible = $this->page('/economy/market', true);
        self::assertSame(array_map($this->id(...), [2, 3, 4]), array_column($eligible['assets'], 'id'));
        $queries = DB::getQueryLog();
        self::assertCount(1, $queries);
        self::assertStringContainsString('not exists', $queries[0]['query']);
        self::assertStringContainsString('"marketplace_listings"."asset_id" = "assets"."id"', $queries[0]['query']);
        self::assertStringContainsString('limit 1 offset 0', $queries[0]['query']);
        self::assertStringNotContainsString('not in', $queries[0]['query']);
        self::assertContains($this->id(900), $queries[0]['bindings']);
        self::assertCount(5, $this->page()['assets']);
    }

    public function test_name_cursor_pages_cover_all_items_including_duplicate_names_and_null_dates(): void
    {
        for ($i = 1; $i <= 57; $i++) {
            $this->asset($i, 'Item '.str_pad((string) intdiv($i, 3), 2, '0', STR_PAD_LEFT));
        }
        $first = $this->page('/economy/market?asset_q=Item&cursor=separate-market-page', true);
        self::assertCount(20, $first['assets']);
        self::assertNull($first['previous']);
        parse_str(parse_url($first['next'], PHP_URL_QUERY), $parameters);
        self::assertSame('offers', $parameters['tab']);
        self::assertSame('1', $parameters['asset_picker']);
        self::assertSame('Item', $parameters['asset_q']);
        self::assertSame('separate-market-page', $parameters['cursor']);
        self::assertArrayNotHasKey('owner_account_id', $parameters);
        $this->logging();
        $second = $this->page($first['next'], true);
        self::assertStringContainsString('(lower(name), id) > (?, ?)', DB::getQueryLog()[0]['query']);
        self::assertDoesNotMatchRegularExpression('/\boffset\s+[1-9]\d*/i', DB::getQueryLog()[0]['query']);
        $third = $this->page($second['next'], true);
        self::assertCount(20, $second['assets']);
        self::assertCount(17, $third['assets']);
        self::assertNull($third['next']);
        self::assertSame($first['assets'], $this->page($second['previous'], true)['assets']);
        self::assertSame($second['assets'], $this->page($third['previous'], true)['assets']);
        $ids = array_column(array_merge($first['assets'], $second['assets'], $third['assets']), 'id');
        self::assertSame(array_map($this->id(...), range(1, 57)), $ids);
        self::assertCount(57, array_unique($ids));
    }

    public function test_search_matches_literal_prefixes_and_empty_results_are_truthful(): void
    {
        $this->asset(1, '100% Cotton');
        $this->asset(2, '1000 Cotton');
        $this->asset(3, 'A_thing');
        $this->asset(4, 'A!thing');
        $this->asset(5, 'Artist tool');
        self::assertSame([$this->id(1)], array_column($this->search('100%')['assets'], 'id'));
        self::assertSame([$this->id(3)], array_column($this->search('a_')['assets'], 'id'));
        self::assertSame([$this->id(4)], array_column($this->search('a!')['assets'], 'id'));
        self::assertSame([$this->id(5)], array_column($this->search(' ART ')['assets'], 'id'));
        $empty = $this->search('Missing');
        self::assertTrue($empty['available']);
        self::assertSame([], $empty['assets']);
        self::assertSame('Missing', $empty['query']);
        self::assertNull($empty['next']);
    }

    public function test_bad_cursors_are_rejected_before_queries(): void
    {
        $valid = ['directory_name' => 'item', 'id' => $this->id(1), '_pointsToNextItems' => true];
        $cases = [
            'garbage',
            $this->encode(array_replace($valid, ['id' => 'invalid'])),
            $this->encode(array_replace($valid, ['directory_name' => []])),
            $this->encode(array_replace($valid, ['_pointsToNextItems' => 'true'])),
            $this->encode($valid + ['unexpected' => 'value']),
            $this->encode(array_replace($valid, ['directory_name' => 'different prefix'])),
            ['not' => 'a string'],
        ];
        foreach ($cases as $cursor) {
            $this->logging();
            try {
                $this->search('Item', $cursor);
                self::fail('Malformed cursor accepted.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('asset_cursor', $error->errors());
            }
            self::assertSame([], DB::getQueryLog());
        }
    }

    private function asset(int $id, string $name, array $extra = []): void
    {
        DB::table('assets')->insert(array_merge(['id' => $this->id($id), 'owner_account_id' => $this->id(900), 'name' => $name], $extra));
    }

    private function page(string $url = '/economy/wallet', bool $eligible = false): array
    {
        return app(OwnedAssetDirectory::class)->page(Request::create($url), $this->id(900), $eligible);
    }

    private function search(string $query, mixed $cursor = null): array
    {
        return $this->page('/economy/wallet?'.http_build_query(['asset_q' => $query, 'asset_cursor' => $cursor]));
    }

    private function id(int $id): string
    {
        return sprintf('30000000-0000-4000-8000-%012d', $id);
    }

    private function encode(array $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($data)));
    }

    private function logging(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
    }
}
