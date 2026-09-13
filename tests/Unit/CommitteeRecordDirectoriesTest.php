<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Legislature\CommitteeController;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\Committee;
use App\Models\User;
use App\Services\PublicRecordService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;
use Tests\TestCase;

/** Actual committee-controller reads, private SQLite only; governance writers are never invoked. */
final class CommitteeRecordDirectoriesTest extends TestCase
{
    private string $original;

    private CommitteeController $controller;

    private const MEETING = '10000000-0000-4000-8000-000000000001';

    private const OTHER_MEETING = '10000000-0000-4000-8000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.committee_records_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('committee_records_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $tables = [
            'jurisdictions' => ['name'], 'legislatures' => ['jurisdiction_id', 'status', 'speaker_id'],
            'committees' => ['legislature_id', 'name', 'status', 'chair_member_id', 'alternate_member_id', 'seats'],
            'committee_meetings' => ['committee_id', 'status', 'scheduled_for', 'agenda'],
            'committee_seats' => ['committee_id', 'member_id', 'seat_kind', 'vacated_at'],
            'legislature_members' => ['legislature_id', 'user_id', 'status'], 'users' => ['name', 'display_name'],
            'bills' => ['committee_id', 'legislature_id', 'title', 'status', 'created_at'],
            'committee_reports' => ['committee_id', 'bill_id', 'filed_by_member_id', 'report_record_id', 'created_at'],
            'public_records' => ['kind', 'title', 'subject_type', 'subject_id', 'via_form', 'body', 'actor_display', 'actor_user_id', 'published_at', 'audit_seq'],
            'chamber_votes' => ['votable_type', 'votable_id', 'stage', 'status', 'outcome', 'opened_at'],
            'chamber_vote_tallies' => ['vote_id'], 'vote_casts' => ['vote_id', 'member_id'],
        ];
        foreach ($tables as $table => $columns) {
            DB::connection()->getSchemaBuilder()->create($table, function (Blueprint $t) use ($columns, $table) {
                $t->string('id')->primary();
                foreach ($columns as $column) {
                    $t->string($column)->nullable();
                }
                if ($table === 'public_records') {
                    $t->integer('seq');
                }
                $t->softDeletes();
            });
        }
        $this->row('jurisdictions', 'place', ['name' => 'Poland']);
        $this->row('legislatures', 'leg', ['jurisdiction_id' => 'place', 'status' => 'active']);
        $this->row('committees', 'committee', ['legislature_id' => 'leg', 'name' => 'Public works', 'status' => 'seated', 'chair_member_id' => 'member', 'seats' => 1]);
        $this->row('users', 'viewer', ['name' => 'Legal chair identity', 'display_name' => 'Public chair']);
        $this->row('legislature_members', 'member', ['legislature_id' => 'leg', 'user_id' => 'viewer', 'status' => 'seated']);
        $this->row('committee_seats', 'seat', ['committee_id' => 'committee', 'member_id' => 'member', 'seat_kind' => 'type_a']);
        $this->row('committee_meetings', self::MEETING, ['committee_id' => 'committee', 'status' => 'open', 'scheduled_for' => '2026-09-12', 'agenda' => '["Selected hearing"]']);
        $this->row('committee_meetings', self::OTHER_MEETING, ['committee_id' => 'committee', 'status' => 'scheduled', 'scheduled_for' => '2026-10-12', 'agenda' => '[]']);
        for ($i = 1; $i <= 55; $i++) {
            $this->row('public_records', 'testimony-'.$i, ['seq' => $i, 'kind' => 'testimony', 'subject_type' => 'committee_meetings',
                'subject_id' => self::MEETING, 'body' => 'Public testimony '.$i, 'audit_seq' => 1000 + $i]);
        }
        $engine = $this->createMock(ConstitutionalEngine::class);
        $engine->expects(self::never())->method('file');
        $records = $this->createMock(PublicRecordService::class);
        $records->expects(self::never())->method('publish');
        $votes = $this->createMock(ChamberVotePresenter::class);
        $votes->method('tallyProps')->willReturnCallback(fn ($vote) => ['vote_id' => $vote->id, 'requiredYes' => 3]);
        $votes->method('casts')->willReturn([['member_name' => 'Public vote author', 'value' => 'yes']]);
        $this->controller = new CommitteeController($engine, $records, $votes);
    }

    protected function tearDown(): void
    {
        DB::purge('committee_records_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    public function test_all_bills_and_reports_are_reachable_in_fixed_pages_including_null_dates_and_multiple_reports_per_bill(): void
    {
        for ($i = 1; $i <= 47; $i++) {
            $this->bill($i, $i <= 45 ? '2026-09-13 00:00:00' : null);
        }
        for ($i = 1; $i <= 53; $i++) {
            $this->report($i, $i % 2 === 0 ? 1 : null, $i <= 51 ? '2026-09-13 00:00:00' : null);
        }
        $first = $this->page();
        self::assertCount(20, $first['bills']);
        self::assertCount(20, $first['reports']);
        $billIds = $this->traverse($first, 'bills', 'billPages');
        $reportIds = $this->traverse($first, 'reports', 'reportPages');
        self::assertCount(47, array_unique($billIds));
        self::assertCount(53, array_unique($reportIds));
        self::assertSame(array_map(fn ($i) => $this->id(1000 + $i), [...range(45, 1), 47, 46]), $billIds);
        self::assertSame(array_map(fn ($i) => $this->id(2000 + $i), [...range(51, 1), 53, 52]), $reportIds);
        $second = $this->page($this->query($first['billPages']['next']));
        self::assertSame($first['bills'], $this->page($this->query($second['billPages']['previous']))['bills']);
    }

    public function test_alternating_pagers_preserves_both_positions_selected_hearing_place_and_testimony_links(): void
    {
        for ($i = 1; $i <= 45; $i++) {
            $this->bill($i);
            $this->report($i, null);
        }
        $context = ['meeting' => self::MEETING, 'jurisdiction' => 'place', 'return_to' => '/rooms/committee/'.self::MEETING];
        $first = $this->page($context);
        $billSecond = $this->page($this->query($first['billPages']['next']));
        $bothSecondQuery = $this->query($billSecond['reportPages']['next']);
        self::assertArrayHasKey('bills_cursor', $bothSecondQuery);
        self::assertArrayHasKey('reports_cursor', $bothSecondQuery);
        $bothSecond = $this->page($bothSecondQuery);
        self::assertSame(array_column($billSecond['bills'], 'id'), array_column($bothSecond['bills'], 'id'));
        self::assertSame($context, array_intersect_key($bothSecondQuery, $context));
        $backBillsQuery = $this->query($bothSecond['billPages']['previous']);
        $backBills = $this->page($backBillsQuery);
        self::assertSame(array_column($first['bills'], 'id'), array_column($backBills['bills'], 'id'));
        self::assertSame(array_column($bothSecond['reports'], 'id'), array_column($backBills['reports'], 'id'));
        $testimonyQuery = $this->query($backBills['testimonyPages']['next']);
        self::assertSame($backBillsQuery['reports_cursor'], $testimonyQuery['reports_cursor']);
        $olderTestimony = $this->page($testimonyQuery);
        self::assertSame(array_column($backBills['bills'], 'id'), array_column($olderTestimony['bills'], 'id'));
        self::assertSame(array_column($backBills['reports'], 'id'), array_column($olderTestimony['reports'], 'id'));
        self::assertSame('/rooms/committee/'.self::MEETING, $olderTestimony['urls']['room']);
        self::assertSame(range(5, 1), array_column($olderTestimony['testimony'], 'seq'));
    }

    public function test_exact_report_reader_keeps_all_body_text_and_links_to_the_correct_bill_with_context(): void
    {
        $this->bill(1);
        $this->report(1, 1);
        $this->report(2, 1);
        $this->report(3, null);
        $body = str_repeat('Full public report text. ', 100);
        DB::table('public_records')->where('id', $this->id(3001))->update(['body' => $body]);
        $context = ['meeting' => self::MEETING, 'jurisdiction' => 'place', 'return_to' => '/rooms/committee/'.self::MEETING];
        $page = $this->page($context);
        self::assertSame($this->id(2002), $page['bills'][0]['report']['id'], 'Latest report must be deterministic and not erase earlier reports from history.');
        $report = collect($page['reports'])->firstWhere('id', $this->id(2001));
        self::assertNull($report['body']);
        self::assertLessThan(400, strlen($report['excerpt']));
        // A valid historical report remains readable after a later referral.
        DB::table('bills')->where('id', $this->id(1001))->update(['committee_id' => 'later-committee']);
        $selected = $this->page($this->query($report['href']));
        self::assertSame($this->id(2001), $selected['selectedReport']['id']);
        self::assertSame($body, $selected['selectedReport']['body']);
        self::assertSame('/bills/'.$this->id(1001), parse_url($selected['selectedReport']['bill']['href'], PHP_URL_PATH));
        self::assertEquals($context, $this->query($selected['selectedReport']['bill']['href']));
        self::assertSame(201, $selected['selectedReport']['audit_seq']);
        self::assertNull($this->page($this->query($selected['selectedReport']['close_href']))['selectedReport']);
    }

    public function test_scope_and_privacy_exclude_other_committees_or_legs_deleted_bills_and_mismatched_publications(): void
    {
        $this->bill(1);
        $this->bill(2);
        $this->bill(3);
        $this->bill(4);
        DB::table('bills')->where('id', $this->id(1002))->update(['committee_id' => 'foreign']);
        DB::table('bills')->where('id', $this->id(1003))->update(['legislature_id' => 'foreign']);
        DB::table('bills')->where('id', $this->id(1004))->update(['deleted_at' => '2026-09-13']);
        $this->report(1, 1);
        $this->report(2, null);
        $this->report(3, 3);
        DB::table('committee_reports')->where('id', $this->id(2002))->update(['committee_id' => 'foreign']);
        DB::table('public_records')->where('id', $this->id(3003))->update(['subject_id' => 'foreign', 'body' => 'Foreign publication body', 'title' => 'Foreign publication title']);
        $page = $this->page();
        self::assertSame([$this->id(1001)], array_column($page['bills'], 'id'));
        self::assertCount(2, $page['reports']);
        self::assertStringNotContainsString('Legal chair identity', json_encode($page['reports']));
        self::assertStringNotContainsString('private-record-actor', json_encode($page['reports']));
        self::assertStringNotContainsString('Foreign publication', json_encode($page['reports']));
        $selected = $this->page(['report' => $this->id(2003)])['selectedReport'];
        self::assertFalse($selected['publication_available']);
        self::assertNull($selected['body']);
        self::assertNull($selected['bill']);
        $this->expectException(ModelNotFoundException::class);
        $this->page(['report' => $this->id(2002)]);
    }

    public function test_cursor_scope_validation_happens_before_queries_and_views_cannot_exchange_tokens(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->bill($i);
            $this->report($i, null);
        }
        $first = $this->page(['meeting' => self::MEETING]);
        $billQuery = $this->query($first['billPages']['next']);
        $foreignToken = json_decode(base64_decode(strtr($billQuery['bills_cursor'], '-_', '+/')), true);
        $foreignToken['committee'] = 'foreign';
        foreach ([['bills_cursor' => 'malformed'], ['reports_cursor' => $billQuery['bills_cursor']],
            [...$billQuery, 'meeting' => self::OTHER_MEETING], ['report' => 'not-a-uuid'],
            [...$billQuery, 'bills_cursor' => base64_encode(json_encode($foreignToken))]] as $query) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $this->page($query);
                self::fail('Invalid scope or token accepted.');
            } catch (ValidationException) {
                self::assertSame([], DB::getQueryLog());
            }
        }
    }

    public function test_new_records_do_not_shift_an_existing_older_page_and_historical_hearing_remains_read_only(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->bill($i);
            $this->report($i, null);
        }
        $first = $this->page(['meeting' => self::MEETING]);
        $billQuery = $this->query($first['billPages']['next']);
        $reportQuery = $this->query($first['reportPages']['next']);
        $billsBefore = array_column($this->page($billQuery)['bills'], 'id');
        $reportsBefore = array_column($this->page($reportQuery)['reports'], 'id');
        $this->bill(31, '2026-09-14 00:00:00');
        $this->report(31, null, '2026-09-14 00:00:00');
        self::assertSame($billsBefore, array_column($this->page($billQuery)['bills'], 'id'));
        self::assertSame($reportsBefore, array_column($this->page($reportQuery)['reports'], 'id'));
        DB::table('committee_meetings')->where('id', self::MEETING)->update(['status' => 'adjourned']);
        $closed = $this->page($reportQuery);
        self::assertSame([], array_filter($closed['can']));
        self::assertTrue($closed['meetingContext']['readOnly']);
        self::assertSame(self::MEETING, $closed['meeting']['id']);
    }

    public function test_page_queries_are_limited_before_report_enrichment_and_vote_links_stay_exact(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->bill($i);
            $this->report($i, $i);
        }
        $vote = $this->id(5000);
        $this->row('chamber_votes', $vote, ['votable_type' => 'bill', 'votable_id' => $this->id(1025), 'stage' => 'committee', 'status' => 'open', 'outcome' => null, 'opened_at' => '2026-09-13']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $page = $this->page();
        $queries = DB::getQueryLog();
        self::assertSame('/votes/'.$vote.'/cast', $page['bills'][0]['vote']['cast_url']);
        self::assertSame(3, $page['bills'][0]['vote']['tally']['requiredYes']);
        self::assertSame('/bills/'.$this->id(1025).'/refer-to-floor', $page['bills'][0]['refer_url']);
        self::assertSame([['member_name' => 'Public vote author', 'value' => 'yes']], $page['bills'][0]['vote']['casts']);
        foreach ($queries as $query) {
            $sql = $query['query'];
            if (str_contains($sql, 'from "bills"') && ! str_contains($sql, ' in (')) {
                self::assertStringContainsString('limit 21', $sql);
            }
            if (str_contains($sql, 'from "committee_reports"')) {
                self::assertContains('committee', $query['bindings']);
                self::assertMatchesRegularExpression('/limit (?:21|1)$/', $sql);
            }
            if (str_contains($sql, 'from "public_records"') && in_array('F-CHR-004', $query['bindings'], true)) {
                self::assertStringContainsString('"id" in (', $sql);
                self::assertLessThanOrEqual(23, count($query['bindings']));
                self::assertStringNotContainsString('actor_user_id', $sql);
                self::assertStringContainsString('substr(body, 1, 351) as body', $sql);
            }
        }
    }

    private function traverse(array $first, string $rows, string $pages): array
    {
        $ids = [];
        $current = $first;
        for ($count = 0; ; $count++) {
            self::assertLessThan(8, $count);
            self::assertLessThanOrEqual(20, count($current[$rows]));
            array_push($ids, ...array_column($current[$rows], 'id'));
            if ($current[$pages]['next'] === null) {
                break;
            }
            $current = $this->page($this->query($current[$pages]['next']));
        }

        return $ids;
    }

    private function page(array $query = []): array
    {
        $request = Request::create('/committees/committee', 'GET', $query);
        $request->setUserResolver(fn () => (new User)->forceFill(['id' => 'viewer']));
        $committee = Committee::findOrFail('committee');
        // Scope-token assertions inspect reads after the route-model binding.
        if (DB::connection()->logging()) {
            DB::flushQueryLog();
        }
        $response = $this->controller->show($request, $committee);

        return (new ReflectionProperty($response, 'props'))->getValue($response);
    }

    private function id(int $n): string
    {
        return sprintf('20000000-0000-4000-8000-%012d', $n);
    }

    private function query(string $url): array
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

        return $query;
    }

    private function row(string $table, string $id, array $data): void
    {
        DB::table($table)->insert(['id' => $id, ...$data]);
    }

    private function bill(int $number, ?string $date = '2026-09-13 00:00:00'): void
    {
        $this->row('bills', $this->id(1000 + $number), ['committee_id' => 'committee', 'legislature_id' => 'leg', 'title' => 'Bill '.$number, 'status' => 'in_committee', 'created_at' => $date]);
    }

    private function report(int $number, ?int $bill, ?string $date = '2026-09-13 00:00:00'): void
    {
        $this->row('committee_reports', $this->id(2000 + $number), ['committee_id' => 'committee', 'bill_id' => $bill === null ? null : $this->id(1000 + $bill), 'filed_by_member_id' => 'private-member', 'report_record_id' => $this->id(3000 + $number), 'created_at' => $date]);
        $this->row('public_records', $this->id(3000 + $number), ['seq' => 100 + $number, 'kind' => 'other', 'title' => 'Report '.$number,
            'subject_type' => 'committees', 'subject_id' => 'committee', 'via_form' => 'F-CHR-004', 'body' => 'Report body '.$number,
            'actor_user_id' => 'private-record-actor', 'actor_display' => 'Published chair', 'audit_seq' => 200 + $number]);
    }
}
