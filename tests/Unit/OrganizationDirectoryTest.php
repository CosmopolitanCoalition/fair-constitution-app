<?php

namespace Tests\Unit;

use App\Support\OrganizationDirectory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class OrganizationDirectoryTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.organization_directory_fixture' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::setDefaultConnection('organization_directory_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('organizations', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name'); $t->string('type')->default('business');
            $t->string('structure')->default('stock'); $t->uuid('jurisdiction_id');
            $t->integer('worker_count')->default(0); $t->uuid('board_id')->nullable();
            $t->boolean('is_cgc')->default(false); $t->string('status')->default('active');
            $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('jurisdictions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('name'); $t->integer('adm_level'); $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('boards', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->integer('worker_seats'); $t->integer('owner_seats');
            $t->boolean('composition_valid'); $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('endorsements', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('endorser_type'); $t->uuid('endorser_id'); $t->boolean('is_active');
        });
        $schema->create('org_conversions', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('organization_id'); $t->string('via');
            $t->string('status'); $t->timestamp('deleted_at')->nullable();
        });
        DB::table('jurisdictions')->insert([
            ['id' => $this->id(901), 'name' => 'Here', 'adm_level' => 1],
            ['id' => $this->id(902), 'name' => 'Elsewhere', 'adm_level' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('organization_directory_fixture');
        DB::purge('organization_directory_fixture');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_cursor_pages_cover_duplicate_names_without_duplicates_and_can_go_back(): void
    {
        for ($i = 1; $i <= 63; $i++) $this->org($i, 'Organization '.str_pad((string) intdiv($i, 4), 2, '0', STR_PAD_LEFT));
        $this->org(80, 'A deleted entry', ['deleted_at' => '2026-01-01']);
        $this->org(81, 'A dissolved entry', ['status' => 'dissolved']);
        $first = $this->page();
        self::assertCount(25, $first['organizations']);
        self::assertNull($first['previous']);
        $second = $this->page($first['next']);
        self::assertCount(25, $second['organizations']);
        $third = $this->page($second['next']);
        self::assertCount(13, $third['organizations']);
        self::assertNull($third['next']);
        self::assertSame($first['organizations'], $this->page($second['previous'])['organizations']);
        self::assertSame($second['organizations'], $this->page($third['previous'])['organizations']);
        $ids = array_column(array_merge($first['organizations'], $second['organizations'], $third['organizations']), 'id');
        self::assertCount(63, array_unique($ids));
        self::assertSame(array_map($this->id(...), range(1, 63)), $ids);
    }

    public function test_enrichment_is_scoped_to_the_page_and_read_count_does_not_grow_per_row(): void
    {
        for ($i = 1; $i <= 70; $i++) {
            $this->org($i, 'Org '.str_pad((string) $i, 3, '0', STR_PAD_LEFT), ['board_id' => $this->id(1000 + $i)]);
            DB::table('boards')->insert(['id' => $this->id(1000 + $i), 'worker_seats' => 2, 'owner_seats' => 3, 'composition_valid' => true]);
            DB::table('endorsements')->insert(['id' => $this->id(2000 + $i), 'endorser_type' => 'organization', 'endorser_id' => $this->id($i), 'is_active' => true]);
        }
        DB::table('org_conversions')->insert(['id' => $this->id(5000), 'organization_id' => $this->id(1), 'via' => 'monopoly_acquisition', 'status' => 'pending']);
        DB::connection()->enableQueryLog(); DB::connection()->flushQueryLog();
        $page = $this->page();
        $queries = DB::getQueryLog();
        self::assertCount(5, $queries);
        self::assertStringContainsString('limit 26', $queries[0]['query']);
        self::assertStringNotContainsString('offset', $queries[0]['query']);
        self::assertStringNotContainsString('count(', $queries[0]['query']);
        foreach (array_slice($queries, 1) as $query) {
            self::assertStringContainsString(' in (', $query['query']);
            self::assertNotContains($this->id(70), $query['bindings']);
        }
        self::assertTrue($page['organizations'][0]['monopoly_pending']);
        self::assertSame(1, $page['organizations'][0]['endorsement_count']);
        self::assertSame(2, $page['organizations'][0]['board']['worker_seats']);
        DB::connection()->flushQueryLog();
        $this->page($page['next']);
        self::assertStringContainsString('(lower(name), id) > (?, ?)', DB::getQueryLog()[0]['query']);
        self::assertCount(5, DB::getQueryLog());
    }

    public function test_search_is_case_insensitive_prefix_with_literal_wildcards(): void
    {
        $this->org(1, '100% Community'); $this->org(2, '1000 Community');
        $this->org(3, 'Anne Arundel Club'); $this->org(4, 'Friends of Anne Arundel');
        self::assertSame([$this->id(1)], array_column($this->page('/organizations?q=100%25')['organizations'], 'id'));
        self::assertSame([$this->id(3)], array_column($this->page('/organizations?q=ANNE')['organizations'], 'id'));
    }

    public function test_place_and_type_filters_apply_before_pagination_and_survive_links(): void
    {
        for ($i = 1; $i <= 31; $i++) $this->org($i, 'Club '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), ['type' => 'informal']);
        $this->org(50, 'Club abroad', ['type' => 'informal', 'jurisdiction_id' => $this->id(902)]);
        $this->org(51, 'Club business');
        $first = $this->page('/organizations?q=Club&type=informal&structure=stock&jurisdiction='.$this->id(901));
        self::assertCount(25, $first['organizations']);
        parse_str(parse_url($first['next'], PHP_URL_QUERY), $query);
        self::assertSame('Club', $query['q']); self::assertSame('informal', $query['type']);
        self::assertSame('stock', $query['structure']); self::assertSame($this->id(901), $query['jurisdiction']);
        self::assertCount(6, $this->page($first['next'])['organizations']);
    }

    public function test_empty_search_does_not_read_related_tables(): void
    {
        $this->org(1, 'One');
        DB::connection()->enableQueryLog(); DB::connection()->flushQueryLog();
        $page = $this->page('/organizations?q=Missing');
        self::assertSame([], $page['organizations']); self::assertNull($page['next']);
        self::assertCount(1, DB::getQueryLog());
    }

    public function test_malformed_cursor_is_rejected_before_querying(): void
    {
        DB::connection()->enableQueryLog(); DB::connection()->flushQueryLog();
        try { $this->page('/organizations?cursor=garbage'); self::fail('Expected invalid cursor'); }
        catch (ValidationException $e) { self::assertArrayHasKey('cursor', $e->errors()); }
        self::assertSame([], DB::getQueryLog());
    }

    private function id(int $id): string { return sprintf('10000000-0000-4000-8000-%012d', $id); }
    private function org(int $id, string $name, array $extra = []): void
    {
        DB::table('organizations')->insert(array_merge(['id' => $this->id($id), 'name' => $name, 'jurisdiction_id' => $this->id(901)], $extra));
    }
    private function page(string $url = '/organizations'): array
    {
        $request = Request::create($url);
        $filters = OrganizationDirectory::filters($request);
        return app(OrganizationDirectory::class)->page($request, $filters, $filters['jurisdiction'] ?: null);
    }
}
