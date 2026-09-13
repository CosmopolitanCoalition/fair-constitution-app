<?php

namespace Tests\Unit;

use App\Http\Controllers\System\AuditChainController;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Only synthetic SQLite tables; never the LivePgConnection helper. */
final class AuditChainDirectoryTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.audit_reader_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('audit_reader_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::connection()->getSchemaBuilder()->create('audit_log', function (Blueprint $t) {
            $t->bigInteger('seq')->primary();
            $t->timestamp('occurred_at')->nullable();
            foreach (['module', 'event', 'ref', 'hash', 'prev_hash', 'blocked_reason', 'payload', 'actor_user_id'] as $column) $t->text($column)->nullable();
            $t->boolean('rejected')->default(false);
        });
        foreach (range(1, 63) as $n) $this->insert($n * 2);
        DB::enableQueryLog(); DB::flushQueryLog();
    }

    protected function tearDown(): void
    {
        DB::purge('audit_reader_fixture'); DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function insert(int $seq): void
    {
        DB::table('audit_log')->insert([
            'seq' => $seq, 'occurred_at' => '2026-09-13 12:00:00', 'module' => 'legislature', 'event' => 'committee.report_filed',
            'ref' => 'F-CHR-004', 'hash' => hash('sha256', (string) $seq), 'prev_hash' => hash('sha256', (string) ($seq - 2)),
            'rejected' => $seq === 4, 'blocked_reason' => $seq === 4 ? 'Synthetic refusal' : null,
            'payload' => '{"private_fixture":"must never be selected"}', 'actor_user_id' => 'synthetic-private-actor',
        ]);
    }

    private function props(string $url = '/system/audit-chain', ?string $only = 'entries,chain', bool $operator = false): array
    {
        $request = Request::create($url); $request->headers->set('X-Inertia', 'true');
        if ($only !== null) {
            $request->headers->set('X-Inertia-Partial-Component', 'System/AuditChain');
            $request->headers->set('X-Inertia-Partial-Data', $only);
        }
        $request->setUserResolver(fn () => (new User)->forceFill(['is_operator' => $operator]));
        $audit = $this->createMock(AuditService::class);
        foreach (['latestSeq', 'count', 'verifyChain'] as $method) $audit->expects(self::never())->method($method);
        return (new AuditChainController($audit))->show($request)->toResponse($request)->getData(true)['props'];
    }

    private function assertBoundedMetadataQueries(int $expected): void
    {
        $queries = DB::getQueryLog(); self::assertCount($expected, $queries);
        foreach ($queries as $query) {
            self::assertMatchesRegularExpression('/^select .* from "audit_log" .*limit (1|26)$/', $query['query']);
            foreach (['count(', 'max(', 'offset', 'payload', 'actor_user_id', 'select *'] as $forbidden) self::assertStringNotContainsString($forbidden, $query['query']);
        }
    }

    public function test_all_entries_are_reachable_in_both_directions_without_counts_or_offsets(): void
    {
        $url = '/system/audit-chain?jurisdiction=fixture-place'; $pages = [];
        do {
            DB::flushQueryLog(); $page = $this->props($url)['entries']; $this->assertBoundedMetadataQueries(2);
            $pages[] = $page; $url = $page['pages']['next'];
        } while ($url !== null);
        self::assertSame([25, 25, 13], array_map(fn ($p) => count($p['data']), $pages));
        self::assertSame(array_map('strval', range(126, 2, -2)), array_column(array_merge(...array_column($pages, 'data')), 'seq'));
        self::assertSame($pages[1]['data'], $this->props($pages[2]['pages']['previous'])['entries']['data']);
        self::assertSame($pages[0]['data'], $this->props($pages[1]['pages']['previous'])['entries']['data']);
        self::assertStringContainsString('jurisdiction=fixture-place', $pages[1]['pages']['previous']);
        self::assertSame('/system/audit-chain?jurisdiction=fixture-place', $pages[2]['latest_url']);
    }

    public function test_append_during_browsing_does_not_shift_older_pages_and_head_is_not_a_count(): void
    {
        $first = $this->props(); self::assertSame('126', $first['chain']['head_seq']); self::assertArrayNotHasKey('count', $first['chain']);
        $this->insert(128);
        $second = $this->props($first['entries']['pages']['next']);
        self::assertSame('128', $second['chain']['head_seq']);
        self::assertSame(array_map('strval', range(76, 28, -2)), array_column($second['entries']['data'], 'seq'));
        self::assertSame('128', $this->props($second['entries']['latest_url'])['entries']['data'][0]['seq']);
    }

    public function test_exact_receipt_outside_first_page_has_only_public_metadata_and_one_query(): void
    {
        $props = $this->props('/system/audit-chain?seq=4&entries_cursor=ignored&jurisdiction=fixture-place', 'entries');
        self::assertSame(['entries'], array_keys($props)); $receipt = $props['entries'];
        self::assertSame(['status' => 'found', 'seq' => '4'], $receipt['selection']); self::assertCount(1, $receipt['data']);
        self::assertSame(['seq', 'occurred_at', 'module', 'event', 'ref', 'hash', 'prev_hash', 'rejected', 'blocked_reason'], array_keys($receipt['data'][0]));
        self::assertTrue($receipt['data'][0]['rejected']); self::assertSame('Synthetic refusal', $receipt['data'][0]['blocked_reason']);
        self::assertSame(['previous' => null, 'next' => null], $receipt['pages']);
        self::assertStringNotContainsString('private_fixture', json_encode($props));
        self::assertStringNotContainsString('synthetic-private-actor', json_encode($props));
        $this->assertBoundedMetadataQueries(1);
        self::assertSame(['4'], DB::getQueryLog()[0]['bindings']);
    }

    public function test_bigint_receipts_remain_exact_strings_in_the_response(): void
    {
        $this->insert(9007199254740993); DB::flushQueryLog();
        $props = $this->props('/system/audit-chain?seq=9007199254740993');
        self::assertSame('9007199254740993', $props['entries']['data'][0]['seq']);
        self::assertSame('9007199254740993', $props['chain']['head_seq']); $this->assertBoundedMetadataQueries(2);
    }

    public function test_missing_and_invalid_receipts_are_distinct_and_never_silently_show_latest_entries(): void
    {
        foreach (['3', '9999'] as $seq) {
            DB::flushQueryLog(); $receipt = $this->props('/system/audit-chain?seq='.$seq, 'entries')['entries'];
            self::assertSame(['status' => 'missing', 'seq' => $seq], $receipt['selection']); self::assertSame([], $receipt['data']);
            $this->assertBoundedMetadataQueries(1);
        }
        foreach (['', '0', '-1', '1.5', '1e3', '01', '9223372036854775808', 'abc', '4%20', '%27%20OR%201=1', '1&seq[]=2'] as $seq) {
            DB::flushQueryLog(); $receipt = $this->props('/system/audit-chain?seq='.$seq, 'entries')['entries'];
            self::assertSame('invalid', $receipt['selection']['status']); self::assertSame([], $receipt['data']);
            self::assertSame([], DB::getQueryLog());
        }
    }

    public function test_invalid_cursor_provides_a_recovery_url_without_reading_history(): void
    {
        foreach (['broken', base64_encode('{"seq":4,"_pointsToNextItems":true,"unexpected":1}'), base64_encode('{"seq":0,"_pointsToNextItems":true}'), 'x&entries_cursor[]=y'] as $cursor) {
            DB::flushQueryLog(); $receipt = $this->props('/system/audit-chain?entries_cursor='.$cursor, 'entries')['entries'];
            self::assertSame('invalid_cursor', $receipt['selection']['status']); self::assertSame('/system/audit-chain', $receipt['latest_url']);
            self::assertSame([], $receipt['data']); self::assertSame([], DB::getQueryLog());
        }
    }

    public function test_empty_fixture_and_partial_props_do_not_invent_totals_or_run_expensive_verification(): void
    {
        DB::table('audit_log')->delete(); DB::flushQueryLog();
        $props = $this->props(); self::assertNull($props['chain']['head_seq']); self::assertSame([], $props['entries']['data']);
        $this->assertBoundedMetadataQueries(2);
        DB::flushQueryLog(); self::assertSame(['canVerify' => false], $this->props(only: 'canVerify')); self::assertSame([], DB::getQueryLog());
        self::assertSame(['canVerify' => true], $this->props(only: 'canVerify', operator: true)); self::assertSame([], DB::getQueryLog());
    }
}
