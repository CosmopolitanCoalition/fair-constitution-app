<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Support\OrgShareDirectory;
use App\Support\OrgShareRecipientDirectory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Private name/lot fixtures only. Never load application migrations or live data. */
final class OrgShareDirectoriesTest extends TestCase
{
    private string $originalConnection;
    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.org_share_directory_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('org_share_directory_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
        foreach (['users', 'organizations', 'jurisdictions'] as $name) {
            $schema->create($name, function (Blueprint $table) use ($name) {
                $table->uuid('id')->primary();
                $table->string('name');
                if ($name === 'users') {
                    $table->string('display_name')->nullable();
                    $table->string('email')->default('private@example.invalid');
                }
                $table->timestamp('deleted_at')->nullable();
            });
        }
        $schema->create('org_ownership_stakes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('holder_type');
            $table->uuid('holder_id');
            $table->string('units');
            $table->string('pct')->nullable();
            $table->string('acquired_via');
            $table->timestamp('ended_at')->nullable();
        });
        $schema->create('social_profiles', function (Blueprint $table) {
            $table->uuid('user_id'); $table->string('handle')->nullable(); $table->string('visibility'); $table->softDeletes();
        });
        $this->organization = new Organization(['id' => $this->id(900), 'structure' => Organization::STRUCTURE_STOCK]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('org_share_directory_fixture');
        DB::purge('org_share_directory_fixture');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_share_pages_cover_only_current_organization_lots_and_return_without_offsets(): void
    {
        $this->person(501, 'Fallback', 'Public Holder');
        for ($i = 1; $i <= 47; $i++) {
            $this->stake($i);
        }
        $this->stake(48, ['ended_at' => '2026-09-01']);
        $this->stake(49, ['organization_id' => $this->id(901)]);
        $this->logging();
        $first = $this->shares();
        self::assertCount(20, $first['holders']);
        self::assertTrue($first['issued']);
        self::assertTrue($first['issuable']);
        self::assertNull($first['previous']);
        self::assertSame('Public Holder', $first['holders'][0]['holder']);
        self::assertArrayNotHasKey('total_units', $first);
        self::assertSame(['id', 'holder', 'units', 'pct', 'via'], array_keys($first['holders'][0]));
        $queries = DB::getQueryLog();
        self::assertCount(2, $queries);
        self::assertStringContainsString('limit 21', $queries[0]['query']);
        self::assertStringContainsString('"organization_id" = ?', $queries[0]['query']);
        self::assertContains($this->organization->id, $queries[0]['bindings']);
        self::assertStringNotContainsString('count(', $queries[0]['query']);
        $this->logging();
        $second = $this->shares($first['next']);
        self::assertStringContainsString('"id" < ?', DB::getQueryLog()[0]['query']);
        self::assertStringNotContainsString('offset', DB::getQueryLog()[0]['query']);
        $third = $this->shares($second['next']);
        self::assertCount(20, $second['holders']);
        self::assertCount(7, $third['holders']);
        self::assertNull($third['next']);
        self::assertSame($first['holders'], $this->shares($second['previous'])['holders']);
        self::assertSame($second['holders'], $this->shares($third['previous'])['holders']);
        self::assertSame(array_map($this->id(...), range(47, 1)), array_column(array_merge($first['holders'], $second['holders'], $third['holders']), 'id'));
    }

    public function test_share_names_are_batched_by_current_page_with_plain_fallbacks(): void
    {
        $this->person(501, 'Person Fallback', '   ');
        $this->person(502, 'Deleted Private Name', 'Deleted Public Name', ['deleted_at' => '2026-09-01']);
        DB::table('organizations')->insert(['id' => $this->id(601), 'name' => 'Organization Holder']);
        DB::table('jurisdictions')->insert(['id' => $this->id(701), 'name' => 'Jurisdiction Holder']);
        $this->stake(1);
        $this->stake(2, ['holder_id' => $this->id(502)]);
        $this->stake(3, ['holder_type' => 'organizations', 'holder_id' => $this->id(601)]);
        $this->stake(4, ['holder_type' => 'jurisdictions', 'holder_id' => $this->id(701)]);
        $this->logging();
        $page = $this->shares();
        self::assertSame(['Jurisdiction Holder', 'Organization Holder', 'A holder', 'Person Fallback'], array_column($page['holders'], 'holder'));
        self::assertCount(4, DB::getQueryLog());
        foreach (array_slice(DB::getQueryLog(), 1) as $query) {
            self::assertStringContainsString('"id" in (', $query['query']);
            self::assertLessThanOrEqual(20, count($query['bindings']));
            self::assertStringNotContainsString('email', $query['query']);
        }
    }

    public function test_share_idle_and_membership_states_do_not_invent_totals(): void
    {
        $this->organization->structure = Organization::STRUCTURE_MEMBER_OWNED;
        $this->logging();
        $page = $this->shares();
        self::assertFalse($page['issued']);
        self::assertFalse($page['issuable']);
        self::assertSame([], $page['holders']);
        self::assertNull($page['next']);
        self::assertCount(1, DB::getQueryLog());
    }

    public function test_share_cursor_is_bound_to_organization_and_validated_before_queries(): void
    {
        $valid = ['id' => $this->id(1), 'organization' => (string) $this->organization->id, '_pointsToNextItems' => true];
        foreach ([
            'garbage', $this->encode($valid + ['extra' => true]),
            $this->encode(array_replace($valid, ['organization' => $this->id(901)])),
            $this->encode(array_replace($valid, ['id' => 'broken'])),
            $this->encode(array_replace($valid, ['_pointsToNextItems' => 'true'])),
            ['bad'],
        ] as $cursor) {
            $this->logging();
            try {
                $this->shares($this->base().'?'.http_build_query(['share_cursor' => $cursor]));
                self::fail('Invalid cursor was accepted.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('share_cursor', $error->errors());
            }
            self::assertSame([], DB::getQueryLog());
        }
    }

    public function test_empty_late_share_page_preserves_issued_state_with_scoped_existence_query(): void
    {
        $this->stake(10);
        $cursor = $this->encode(['id' => $this->id(1), 'organization' => (string) $this->organization->id, '_pointsToNextItems' => true]);
        $this->logging();
        $page = $this->shares($this->base().'?share_cursor='.$cursor);
        self::assertSame([], $page['holders']);
        self::assertTrue($page['issued']);
        self::assertCount(2, DB::getQueryLog());
        $query = DB::getQueryLog()[1];
        self::assertStringContainsString('exists(', $query['query']);
        self::assertSame([(string) $this->organization->id], $query['bindings']);
    }

    public function test_no_recipient_query_runs_until_a_name_is_entered(): void
    {
        $this->logging();
        self::assertSame(OrgShareRecipientDirectory::empty(), $this->recipients());
        self::assertSame(OrgShareRecipientDirectory::empty('organizations'), $this->search('  ', 'organizations'));
        self::assertSame([], DB::getQueryLog());
    }

    public function test_recipient_search_prefers_public_name_falls_back_and_includes_self(): void
    {
        $this->person(1, 'Hidden Legal Prefix', 'Anna Public');
        $this->person(2, 'Ann Fallback', null);
        $this->person(3, 'Ann Spaces', '  ');
        $this->person(4, 'Ann Hidden', 'Different Public');
        $this->person(5, 'Ann Deleted', null, ['deleted_at' => '2026-09-01']);
        $this->person(900, 'Self', 'Ann Self');
        $this->logging();
        $page = $this->search('  aNn  ');
        self::assertSame('aNn', $page['query']);
        self::assertTrue($page['searched']);
        self::assertSame([$this->id(2), $this->id(900), $this->id(3), $this->id(1)], array_column($page['candidates'], 'id'));
        self::assertSame(['Ann Fallback', 'Ann Self', 'Ann Spaces', 'Anna Public'], array_column($page['candidates'], 'name'));
        foreach ($page['candidates'] as $candidate) {
            self::assertSame(['id', 'name', 'type', 'profile_href', 'public_handle'], array_keys($candidate));
            self::assertSame('users', $candidate['type']);
        }
        self::assertCount(2, DB::getQueryLog());
        $query = DB::getQueryLog()[0];
        self::assertStringContainsString('limit 21', $query['query']);
        self::assertStringNotContainsString('email', $query['query']);
        self::assertStringNotContainsString('join', $query['query']);
        self::assertStringNotContainsString('count(', $query['query']);
        self::assertContains('ann%', $query['bindings']);
        self::assertSame([], $this->search('Hidden Legal')['candidates']);
    }

    public function test_organization_recipient_search_is_separate_and_includes_issuing_organization(): void
    {
        DB::table('organizations')->insert([
            ['id' => $this->id(900), 'name' => 'Alpine Issuer', 'deleted_at' => null],
            ['id' => $this->id(901), 'name' => 'ALPINE Recipient', 'deleted_at' => null],
            ['id' => $this->id(902), 'name' => 'Alpine Deleted', 'deleted_at' => '2026-09-01'],
        ]);
        $this->person(1, 'Alpine User');
        $this->logging();
        $page = $this->search('alpine', 'organizations');
        self::assertSame([$this->id(900), $this->id(901)], array_column($page['candidates'], 'id'));
        self::assertSame(['organizations', 'organizations'], array_column($page['candidates'], 'type'));
        self::assertCount(1, DB::getQueryLog());
        self::assertStringContainsString('from "organizations"', DB::getQueryLog()[0]['query']);
        self::assertSame('/organizations/'.$this->id(900), $page['candidates'][0]['profile_href']);
        self::assertNull($page['candidates'][0]['public_handle']);
    }

    public function test_named_recipient_context_only_enriches_the_current_page_with_public_handles(): void
    {
        for ($i = 1; $i <= 24; $i++) $this->person($i, 'Existing consent name', 'Same Public Name');
        DB::table('social_profiles')->insert([
            ['user_id' => $this->id(1), 'handle' => 'chosen-public', 'visibility' => 'public', 'deleted_at' => null],
            ['user_id' => $this->id(2), 'handle' => 'private-handle', 'visibility' => 'private', 'deleted_at' => null],
            ['user_id' => $this->id(24), 'handle' => 'later-page', 'visibility' => 'public', 'deleted_at' => null],
        ]);
        $this->logging();
        $first = $this->search('Same');
        self::assertSame('@chosen-public', $first['candidates'][0]['public_handle']);
        self::assertNull($first['candidates'][1]['public_handle']);
        self::assertCount(21, DB::getQueryLog()[1]['bindings']);
        self::assertNotContains($this->id(24), DB::getQueryLog()[1]['bindings']);
        $second = $this->recipients($first['next']);
        self::assertSame('@later-page', $second['candidates'][3]['public_handle']);
        $all = array_merge($first['candidates'], $second['candidates']);
        self::assertCount(24, array_unique(array_column($all, 'profile_href')));
        self::assertStringNotContainsString('private-handle', json_encode($all));
        self::assertSame($first['candidates'], $this->recipients($second['previous'])['candidates']);
    }

    public function test_recipient_name_pages_seek_stably_across_duplicates_and_preserve_composer_state(): void
    {
        for ($i = 1; $i <= 47; $i++) {
            $this->person($i, 'Fallback', 'Person '.str_pad((string) intdiv($i, 4), 2, '0', STR_PAD_LEFT));
        }
        $first = $this->search('Person');
        self::assertCount(20, $first['candidates']);
        self::assertNull($first['previous']);
        self::assertSame($this->base(), parse_url($first['next'], PHP_URL_PATH));
        parse_str(parse_url($first['next'], PHP_URL_QUERY), $parameters);
        self::assertSame('1', $parameters['issue']);
        self::assertSame('Person', $parameters['recipient_q']);
        self::assertSame('users', $parameters['recipient_type']);
        $this->logging();
        $second = $this->recipients($first['next']);
        self::assertStringContainsString('('.OrgShareRecipientDirectory::USER_NAME.', id) > (?, ?)', DB::getQueryLog()[0]['query']);
        self::assertStringNotContainsString('offset', DB::getQueryLog()[0]['query']);
        self::assertCount(2, DB::getQueryLog());
        $third = $this->recipients($second['next']);
        self::assertCount(20, $second['candidates']);
        self::assertCount(7, $third['candidates']);
        self::assertNull($third['next']);
        self::assertSame($first['candidates'], $this->recipients($second['previous'])['candidates']);
        self::assertSame($second['candidates'], $this->recipients($third['previous'])['candidates']);
        self::assertSame(array_map($this->id(...), range(1, 47)), array_column(array_merge($first['candidates'], $second['candidates'], $third['candidates']), 'id'));
    }

    public function test_recipient_search_treats_wildcards_literally_and_accepts_short_nonlatin_names(): void
    {
        foreach (['A% Person', 'A_ Person', 'A! Person', 'Anna Person', '山田'] as $i => $name) {
            $this->person($i + 1, $name);
        }
        self::assertSame([$this->id(1)], array_column($this->search('A%')['candidates'], 'id'));
        self::assertSame([$this->id(2)], array_column($this->search('A_')['candidates'], 'id'));
        self::assertSame([$this->id(3)], array_column($this->search('A!')['candidates'], 'id'));
        self::assertSame([$this->id(5)], array_column($this->search('山')['candidates'], 'id'));
        self::assertTrue($this->search('Missing')['searched']);
        self::assertSame([], $this->search('Missing')['candidates']);
    }

    public function test_recipient_cursor_rejects_other_scope_and_malformed_data_before_queries(): void
    {
        $valid = ['directory_name' => 'person', 'id' => $this->id(1),
            'scope' => hash('sha256', $this->organization->id."\nusers\nperson"), '_pointsToNextItems' => true];
        foreach ([
            'garbage', $this->encode($valid + ['extra' => true]),
            $this->encode(array_replace($valid, ['scope' => hash('sha256', $this->id(901)."\nusers\nperson")])),
            $this->encode(array_replace($valid, ['scope' => hash('sha256', $this->organization->id."\norganizations\nperson")])),
            $this->encode(array_replace($valid, ['scope' => hash('sha256', $this->organization->id."\nusers\nper")])),
            $this->encode(array_replace($valid, ['id' => 'broken'])),
            $this->encode(array_replace($valid, ['directory_name' => 'unrelated'])),
            $this->encode(array_replace($valid, ['directory_name' => str_repeat('p', 256)])),
            $this->encode(array_replace($valid, ['_pointsToNextItems' => 'true'])),
            ['bad'],
        ] as $cursor) {
            $this->logging();
            try {
                $this->search('Person', 'users', $cursor);
                self::fail('Invalid cursor was accepted.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('recipient_cursor', $error->errors());
            }
            self::assertSame([], DB::getQueryLog());
        }
    }

    public function test_recipient_search_rejects_unbounded_or_unknown_inputs_before_queries(): void
    {
        foreach ([['recipient_q' => str_repeat('A', 121)], ['recipient_q' => ['array']],
            ['recipient_type' => 'accounts'], ['recipient_cursor' => str_repeat('x', 2049)]] as $parameters) {
            $this->logging();
            try {
                $this->recipients($this->base().'?'.http_build_query($parameters));
                self::fail('Invalid search was accepted.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey(array_key_first($parameters), $error->errors());
            }
            self::assertSame([], DB::getQueryLog());
        }
    }

    private function shares(?string $url = null): array
    {
        return app(OrgShareDirectory::class)->page(Request::create($url ?? $this->base()), $this->organization);
    }

    private function recipients(?string $url = null): array
    {
        return app(OrgShareRecipientDirectory::class)->page(Request::create($url ?? $this->base()), $this->organization);
    }

    private function search(string $query, string $type = 'users', mixed $cursor = null): array
    {
        return $this->recipients($this->base().'?'.http_build_query(['issue' => 1, 'recipient_q' => $query, 'recipient_type' => $type, 'recipient_cursor' => $cursor]));
    }

    private function base(): string
    {
        return '/organizations/'.$this->organization->id.'/economy';
    }

    private function person(int $id, string $name, ?string $displayName = null, array $extra = []): void
    {
        DB::table('users')->insert(array_merge(['id' => $this->id($id), 'name' => $name, 'display_name' => $displayName], $extra));
    }

    private function stake(int $id, array $extra = []): void
    {
        DB::table('org_ownership_stakes')->insert(array_merge([
            'id' => $this->id($id), 'organization_id' => $this->organization->id, 'holder_type' => 'users',
            'holder_id' => $this->id(501), 'units' => '10.000000', 'pct' => null, 'acquired_via' => 'issue',
        ], $extra));
    }

    private function id(int $id): string
    {
        return sprintf('30000000-0000-4000-8000-%012d', $id);
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
