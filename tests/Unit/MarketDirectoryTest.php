<?php

namespace Tests\Unit;

use App\Support\MarketDirectory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MarketDirectoryTest extends TestCase
{
    private const CONNECTION = 'market_directory_fixture';

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.'.self::CONNECTION => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection(self::CONNECTION);
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());

        $schema = DB::connection()->getSchemaBuilder();
        foreach (['marketplace_listings', 'work_postings', 'assistance_requests'] as $table) {
            $schema->create($table, function (Blueprint $t) use ($table) {
                $t->uuid('id')->primary();
                $t->string('status')->default('open');
                $t->timestamp('created_at')->nullable();
                $t->timestamp('deleted_at')->nullable();
                if ($table === 'work_postings') {
                    $t->string('title');
                    $t->text('terms');
                    $t->decimal('rate')->nullable();
                    $t->uuid('organization_id');
                } elseif ($table === 'assistance_requests') {
                    $t->string('title');
                    $t->text('need');
                    $t->string('privacy')->default('public');
                }
            });
        }
        $schema->create('work_applications', function (Blueprint $t) {
            $t->increments('id');
            $t->uuid('posting_id');
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect(self::CONNECTION);
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public static function tabs(): array
    {
        return [['offers'], ['work'], ['assistance']];
    }

    #[DataProvider('tabs')]
    public function test_cursor_traverses_duplicate_and_null_dates_in_both_directions(string $tab): void
    {
        // Each tied group crosses more than two page boundaries, including the
        // null-date group whose cursor must use the same fallback as its order.
        for ($i = 1; $i <= 120; $i++) {
            $this->entry($tab, $i, ['created_at' => $i <= 63 ? '2026-09-12 10:00:00+00' : null]);
        }
        $this->entry($tab, 121, ['status' => 'closed']);
        $this->entry($tab, 122, ['deleted_at' => '2026-09-12 11:00:00+00']);

        $pages = [];
        $url = '/economy/market?tab='.$tab;
        do {
            self::assertLessThan(6, count($pages), 'Pagination must terminate.');
            $page = $this->page($url);
            self::assertSame($tab, $page['tab']);
            self::assertSame(25, $page['pagination']['pageSize']);
            foreach (['previous', 'next'] as $direction) {
                if ($page['pagination'][$direction] !== null) {
                    parse_str(parse_url($page['pagination'][$direction], PHP_URL_QUERY), $query);
                    self::assertSame($tab, $query['tab']);
                    self::assertNotEmpty($query['cursor']);
                }
            }
            $pages[] = $page;
            $url = $page['pagination']['next'];
        } while ($url !== null);

        self::assertSame([25, 25, 25, 25, 20], array_map(fn ($p) => count($this->ids($p)), $pages));
        self::assertNull($pages[0]['pagination']['previous']);
        $ids = array_merge(...array_map($this->ids(...), $pages));
        self::assertCount(120, array_unique($ids));
        self::assertSame(array_map($this->id(...), [...range(63, 1), ...range(120, 64)]), $ids);

        $back = $pages[array_key_last($pages)];
        for ($i = count($pages) - 2; $i >= 0; $i--) {
            $back = $this->page($back['pagination']['previous']);
            self::assertSame($pages[$i], $back, 'Backward navigation must restore the complete page.');
        }
        self::assertNull($back['pagination']['previous']);
    }

    public function test_only_the_selected_tab_is_read_and_returned(): void
    {
        foreach (['offers', 'work', 'assistance'] as $tab) {
            $this->entry($tab, 1);
        }

        foreach (['offers' => 'marketplace_listings', 'work' => 'work_postings', 'assistance' => 'assistance_requests'] as $tab => $table) {
            $this->startQueryLog();
            // The default entry must have the same bounded behavior as an
            // explicit offers tab, without fetching the other two sections.
            $page = $this->page($tab === 'offers' ? '/economy/market' : '/economy/market?tab='.$tab);
            $queries = DB::getQueryLog();
            self::assertSame($tab, $page['tab']);
            self::assertSame([$this->id(1)], $this->ids($page));
            self::assertCount($tab === 'work' ? 2 : 1, $queries);
            self::assertStringContainsString('from "'.$table.'"', $queries[0]['query']);
            foreach (['offers' => 'offer_ids', 'work' => 'work', 'assistance' => 'assistance'] as $other => $key) {
                if ($other !== $tab) {
                    self::assertSame([], $page[$key]);
                }
            }
            foreach ($queries as $query) {
                foreach (array_diff(['marketplace_listings', 'work_postings', 'assistance_requests'], [$table]) as $otherTable) {
                    self::assertStringNotContainsString($otherTable, $query['query']);
                }
            }
        }
    }

    public function test_private_deleted_and_closed_help_are_excluded_before_pagination(): void
    {
        for ($i = 1; $i <= 31; $i++) {
            $this->entry('assistance', $i, ['created_at' => '2026-09-01 10:00:00+00']);
        }
        // All hidden rows sort ahead of eligible help, so filtering a selected
        // page afterward would incorrectly leave the first page empty.
        for ($i = 1; $i <= 35; $i++) {
            $this->entry('assistance', 100 + $i, ['privacy' => 'private']);
            $this->entry('assistance', 200 + $i, ['deleted_at' => '2026-09-12 11:00:00+00']);
            $this->entry('assistance', 300 + $i, ['status' => 'closed']);
        }

        $first = $this->page('/economy/market?tab=assistance');
        $second = $this->page($first['pagination']['next']);
        self::assertCount(25, $first['assistance']);
        self::assertCount(6, $second['assistance']);
        self::assertNull($second['pagination']['next']);
        self::assertSame(array_map($this->id(...), range(31, 1)), [...$this->ids($first), ...$this->ids($second)]);
        self::assertSame($first, $this->page($second['pagination']['previous']));
    }

    public function test_work_application_counts_are_batched_for_only_the_visible_page(): void
    {
        for ($i = 1; $i <= 57; $i++) {
            $this->entry('work', $i, ['rate' => $i % 2 ? null : 12.5]);
            for ($j = 0; $j < $i % 4; $j++) {
                DB::table('work_applications')->insert(['posting_id' => $this->id($i)]);
            }
        }
        DB::table('work_applications')->insert(['posting_id' => $this->id(999)]);
        $url = '/economy/market?tab=work';
        foreach ([25, 25, 7] as $size) {
            $this->startQueryLog();
            $page = $this->page($url);
            $queries = DB::getQueryLog();
            self::assertCount($size, $page['work']);
            self::assertCount(2, $queries, 'One page query and one aggregate query regardless of page size.');
            self::assertStringContainsString('limit 26', $queries[0]['query']);
            self::assertStringNotContainsString('offset', $queries[0]['query']);
            self::assertStringNotContainsString('count(', $queries[0]['query']);
            self::assertStringContainsString('from "work_applications"', $queries[1]['query']);
            self::assertStringContainsString('group by "posting_id"', $queries[1]['query']);
            self::assertSame($this->ids($page), $queries[1]['bindings'], 'Do not enrich the lookahead row or other postings.');
            self::assertLessThanOrEqual(25, count($queries[1]['bindings']));
            foreach ($page['work'] as $row) {
                $number = (int) substr($row['id'], -12);
                self::assertSame($number % 4, $row['applications']);
                self::assertSame($number % 2 ? null : '12.5', $row['rate']);
            }
            $url = $page['pagination']['next'];
        }
        self::assertNull($url);
    }

    public function test_empty_work_page_does_not_read_applications(): void
    {
        $this->entry('work', 1, ['status' => 'closed']);
        DB::table('work_applications')->insert(['posting_id' => $this->id(1)]);
        $this->startQueryLog();
        $page = $this->page('/economy/market?tab=work');
        self::assertSame([], $page['work']);
        self::assertNull($page['pagination']['next']);
        self::assertNull($page['pagination']['previous']);
        self::assertCount(1, DB::getQueryLog());
        self::assertStringNotContainsString('work_applications', DB::getQueryLog()[0]['query']);
    }

    public function test_invalid_cursors_are_rejected_before_any_database_query(): void
    {
        $valid = ['directory_created_at' => '2026-09-12 10:00:00+00', 'id' => $this->id(1)];
        $invalid = [
            'not encoded' => 'garbage',
            'missing parameters' => (new Cursor([]))->encode(),
            'missing date' => (new Cursor(['id' => $this->id(1)]))->encode(),
            'missing id' => (new Cursor(['directory_created_at' => $valid['directory_created_at']]))->encode(),
            'bad id' => (new Cursor(array_replace($valid, ['id' => 'not-a-uuid'])))->encode(),
            'non-string id' => (new Cursor(array_replace($valid, ['id' => 1])))->encode(),
            'missing direction' => base64_encode(json_encode($valid)),
            'non-boolean direction' => (new Cursor($valid, 'false'))->encode(),
            'unexpected parameter' => (new Cursor($valid + ['other' => 'value']))->encode(),
            'non-string date' => (new Cursor(array_replace($valid, ['directory_created_at' => 1])))->encode(),
            'impossible date' => (new Cursor(array_replace($valid, ['directory_created_at' => '2026-02-30 10:00:00+00'])))->encode(),
            'PostgreSQL has no year zero' => (new Cursor(array_replace($valid, ['directory_created_at' => '0000-01-01 10:00:00+00'])))->encode(),
            'non-date string' => (new Cursor(array_replace($valid, ['directory_created_at' => 'tomorrow'])))->encode(),
            'oversized' => str_repeat('a', 2049),
        ];
        foreach ($invalid as $label => $cursor) {
            $this->startQueryLog();
            try {
                $this->page('/economy/market?'.http_build_query(['tab' => 'work', 'cursor' => $cursor]));
                self::fail('Expected invalid cursor: '.$label);
            } catch (ValidationException $e) {
                self::assertArrayHasKey('cursor', $e->errors(), $label);
            }
            self::assertSame([], DB::getQueryLog(), $label);
        }
    }

    private function id(int $id): string
    {
        return sprintf('10000000-0000-4000-8000-%012d', $id);
    }

    private function entry(string $tab, int $id, array $extra = []): void
    {
        $row = ['id' => $this->id($id), 'created_at' => '2026-09-12 10:00:00+00'];
        $table = match ($tab) {
            'work' => 'work_postings', 'assistance' => 'assistance_requests', default => 'marketplace_listings',
        };
        if ($tab === 'work') {
            $row += ['title' => 'Work '.$id, 'terms' => 'Terms '.$id, 'organization_id' => $this->id(900)];
        } elseif ($tab === 'assistance') {
            $row += ['title' => 'Help '.$id, 'need' => 'Need '.$id];
        }
        DB::table($table)->insert(array_replace($row, $extra));
    }

    private function page(string $url): array
    {
        return app(MarketDirectory::class)->page(Request::create($url));
    }

    private function ids(array $page): array
    {
        return match ($page['tab']) {
            'work' => array_column($page['work'], 'id'),
            'assistance' => array_column($page['assistance'], 'id'),
            default => $page['offer_ids'],
        };
    }

    private function startQueryLog(): void
    {
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
    }
}
