<?php

namespace Tests\Unit;

use App\Support\AgreementPartyDirectory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Consent-name search fixtures only; no migrations or live database traits. */
final class AgreementPartyDirectoryTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.agreement_party_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('agreement_party_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::connection()->getSchemaBuilder()->create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->default('private@example.invalid');
            $table->string('display_name')->default('Unrelated public display name');
            $table->timestamp('deleted_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('agreement_party_fixture');
        DB::purge('agreement_party_fixture');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_composer_waits_for_a_search_without_querying_people(): void
    {
        $this->person(1, 'Someone');
        $this->logging();
        self::assertSame(AgreementPartyDirectory::empty(), $this->page());
        self::assertSame(AgreementPartyDirectory::empty(), $this->search('  '));
        self::assertSame([], DB::getQueryLog());
    }

    public function test_prefix_search_returns_only_consent_name_and_id_excluding_self_and_deleted_people(): void
    {
        $this->person(1, 'Anne Example');
        $this->person(2, 'ANNA Example');
        $this->person(3, 'Someone Anne');
        $this->person(4, 'Anne Deleted', ['deleted_at' => '2026-01-01']);
        $this->person(999, 'Anne Viewer');
        $this->logging();
        $page = $this->search('  aNn  ');
        self::assertTrue($page['searched']);
        self::assertSame('aNn', $page['query']);
        self::assertSame([$this->id(2), $this->id(1)], array_column($page['candidates'], 'id'));
        foreach ($page['candidates'] as $candidate) {
            self::assertSame(['id', 'name'], array_keys($candidate));
        }
        $queries = DB::getQueryLog();
        self::assertCount(1, $queries);
        self::assertStringContainsString('limit 21', $queries[0]['query']);
        self::assertStringContainsString('from "users"', $queries[0]['query']);
        self::assertStringNotContainsString('email', $queries[0]['query']);
        self::assertStringNotContainsString('display_name', $queries[0]['query']);
        self::assertStringNotContainsString('join', $queries[0]['query']);
        self::assertStringNotContainsString('count(', $queries[0]['query']);
        self::assertContains('ann%', $queries[0]['bindings']);
    }

    public function test_pages_cover_duplicate_names_and_return_to_previous_page_without_offsets(): void
    {
        for ($i = 1; $i <= 47; $i++) {
            $this->person($i, 'Person '.str_pad((string) intdiv($i, 4), 2, '0', STR_PAD_LEFT));
        }
        $first = $this->search('Person');
        self::assertCount(20, $first['candidates']);
        self::assertNull($first['previous']);
        parse_str(parse_url($first['next'], PHP_URL_QUERY), $parameters);
        self::assertSame('1', $parameters['new']);
        self::assertSame('Person', $parameters['party_q']);
        $this->logging();
        $second = $this->page($first['next']);
        self::assertStringContainsString('(lower(name), id) > (?, ?)', DB::getQueryLog()[0]['query']);
        self::assertStringNotContainsString('offset', DB::getQueryLog()[0]['query']);
        self::assertCount(1, DB::getQueryLog());
        $third = $this->page($second['next']);
        self::assertCount(20, $second['candidates']);
        self::assertCount(7, $third['candidates']);
        self::assertNull($third['next']);
        self::assertSame($first['candidates'], $this->page($second['previous'])['candidates']);
        self::assertSame($second['candidates'], $this->page($third['previous'])['candidates']);
        $all = array_merge($first['candidates'], $second['candidates'], $third['candidates']);
        self::assertCount(47, array_unique(array_column($all, 'id')));
        self::assertSame(array_map($this->id(...), range(1, 47)), array_column($all, 'id'));
    }

    public function test_search_treats_wildcards_literally_and_supports_short_nonlatin_names(): void
    {
        $this->person(1, 'A% Person');
        $this->person(2, 'A_ Person');
        $this->person(3, 'A! Person');
        $this->person(4, 'Anna Person');
        $this->person(5, '山田');
        self::assertSame([$this->id(1)], array_column($this->search('A%')['candidates'], 'id'));
        self::assertSame([$this->id(2)], array_column($this->search('A_')['candidates'], 'id'));
        self::assertSame([$this->id(3)], array_column($this->search('A!')['candidates'], 'id'));
        self::assertSame([$this->id(5)], array_column($this->search('山')['candidates'], 'id'));
    }

    public function test_valid_search_without_matches_is_distinct_from_idle(): void
    {
        $this->person(1, 'Someone');
        $this->logging();
        $page = $this->search('Missing');
        self::assertTrue($page['searched']);
        self::assertSame([], $page['candidates']);
        self::assertNull($page['next']);
        self::assertNull($page['previous']);
        self::assertCount(1, DB::getQueryLog());
    }

    public function test_malformed_and_mismatched_cursors_are_rejected_before_queries(): void
    {
        $valid = ['directory_name' => 'person', 'id' => $this->id(1), '_pointsToNextItems' => true];
        $bad = [
            'garbage',
            $this->encode(['id' => $this->id(1)]),
            $this->encode(array_replace($valid, ['id' => 'invalid'])),
            $this->encode(array_replace($valid, ['directory_name' => []])),
            $this->encode(array_replace($valid, ['directory_name' => str_repeat('p', 256)])),
            $this->encode(array_replace($valid, ['_pointsToNextItems' => 'true'])),
            $this->encode(array_replace($valid, ['directory_name' => 'different search'])),
            $this->encode($valid + ['extra' => 'unexpected']),
            ['not' => 'a string'],
        ];
        foreach ($bad as $cursor) {
            $this->logging();
            try {
                $this->search('Person', $cursor);
                self::fail('Malformed cursor accepted.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('party_cursor', $error->errors());
            }
            self::assertSame([], DB::getQueryLog());
        }
    }

    private function page(string $url = '/economy/resident-agreements?new=1'): array
    {
        return app(AgreementPartyDirectory::class)->page(Request::create($url), $this->id(999));
    }

    private function search(string $query, mixed $cursor = null): array
    {
        return $this->page('/economy/resident-agreements?'.http_build_query(['new' => 1, 'party_q' => $query, 'party_cursor' => $cursor]));
    }

    private function person(int $id, string $name, array $extra = []): void
    {
        DB::table('users')->insert(array_merge(['id' => $this->id($id), 'name' => $name], $extra));
    }

    private function id(int $id): string
    {
        return sprintf('20000000-0000-4000-8000-%012d', $id);
    }

    private function encode(array $cursor): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($cursor)));
    }

    private function logging(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
    }
}
