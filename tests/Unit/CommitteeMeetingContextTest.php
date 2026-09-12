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

/** Selected-hearing reads only, guarded SQLite memory; all governance writers mocked. */
final class CommitteeMeetingContextTest extends TestCase
{
    private const SELECTED = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const FUTURE = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const FOREIGN = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    private string $original;
    private CommitteeController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.committee_context_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('committee_context_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $tables = [
            'jurisdictions' => ['name'], 'legislatures' => ['jurisdiction_id', 'status', 'speaker_id'],
            'committees' => ['legislature_id', 'name', 'status', 'chair_member_id', 'alternate_member_id', 'seats'],
            'committee_meetings' => ['committee_id', 'status', 'scheduled_for', 'agenda'],
            'committee_seats' => ['committee_id', 'member_id', 'seat_kind', 'vacated_at'],
            'legislature_members' => ['legislature_id', 'user_id', 'status'], 'users' => ['name', 'display_name'],
            'bills' => ['committee_id', 'created_at'], 'committee_reports' => ['committee_id', 'bill_id', 'report_record_id'],
            'public_records' => ['seq', 'kind', 'subject_type', 'subject_id', 'body', 'actor_display', 'published_at', 'audit_seq'],
        ];
        foreach ($tables as $table => $columns) DB::connection()->getSchemaBuilder()->create($table, function (Blueprint $t) use ($columns): void {
            $t->string('id')->primary();
            foreach ($columns as $column) $column === 'seq' ? $t->integer($column) : $t->string($column)->nullable();
            $t->softDeletes();
        });
        $this->row('jurisdictions', 'place', ['name' => 'Poland']);
        $this->row('legislatures', 'leg', ['jurisdiction_id' => 'place', 'status' => 'active']);
        $this->row('committees', 'committee', ['legislature_id' => 'leg', 'name' => 'Public works', 'status' => 'seated', 'chair_member_id' => 'member', 'seats' => 1]);
        $this->row('users', 'viewer', ['name' => 'Legal name', 'display_name' => 'Public chair']);
        $this->row('legislature_members', 'member', ['legislature_id' => 'leg', 'user_id' => 'viewer', 'status' => 'seated']);
        $this->row('committee_seats', 'seat', ['committee_id' => 'committee', 'member_id' => 'member', 'seat_kind' => 'type_a']);
        foreach ([self::SELECTED => ['committee', 'open', '2026-09-12'], self::FUTURE => ['committee', 'scheduled', '2026-10-12'], self::FOREIGN => ['other', 'open', '2026-09-12']] as $id => [$committee, $status, $date]) {
            $this->row('committee_meetings', $id, ['committee_id' => $committee, 'status' => $status, 'scheduled_for' => $date, 'agenda' => json_encode(['Agenda '.$id])]);
        }
        for ($i = 1; $i <= 60; $i++) $this->row('public_records', 'record'.$i, ['seq' => $i, 'kind' => 'testimony', 'subject_type' => 'committee_meetings', 'subject_id' => self::SELECTED, 'body' => 'Selected '.$i, 'audit_seq' => $i]);
        $this->row('public_records', 'other', ['seq' => 61, 'kind' => 'testimony', 'subject_type' => 'committee_meetings', 'subject_id' => self::FUTURE, 'body' => 'Future testimony', 'audit_seq' => 61]);
        $engine = $this->createMock(ConstitutionalEngine::class);
        $engine->expects($this->never())->method('file');
        $this->controller = new CommitteeController($engine, $this->createMock(PublicRecordService::class), $this->createMock(ChamberVotePresenter::class));
    }

    protected function tearDown(): void
    {
        DB::purge('committee_context_fixture'); DB::setDefaultConnection($this->original); parent::tearDown();
    }

    public function test_selected_open_hearing_wins_over_future_meeting_and_keeps_exact_testimony(): void
    {
        DB::enableQueryLog(); DB::flushQueryLog();
        $props = $this->page(self::SELECTED);
        self::assertSame(self::SELECTED, $props['meeting']['id']);
        self::assertSame('/rooms/committee/'.self::SELECTED, $props['urls']['room']);
        self::assertSame(['explicit' => true, 'readOnly' => false], $props['meetingContext']);
        self::assertTrue($props['can']['testify']);
        self::assertTrue($props['can']['setAgenda']);
        self::assertCount(50, $props['testimony']);
        self::assertSame(array_map(fn ($n) => 'Selected '.$n, range(60, 11)), array_column($props['testimony'], 'text'));
        $query = collect(DB::getQueryLog())->first(fn ($q) => in_array('testimony', $q['bindings'], true));
        self::assertSame(['testimony', 'committee_meetings', self::SELECTED], $query['bindings']);
        self::assertStringContainsString('limit 50', $query['query']);
        self::assertStringNotContainsString('select *', $query['query']);
    }

    public function test_closed_selected_hearing_remains_readable_but_all_filing_controls_are_off_even_for_chair(): void
    {
        DB::table('committee_meetings')->where('id', self::SELECTED)->update(['status' => 'adjourned']);
        $props = $this->page(self::SELECTED);
        self::assertSame(self::SELECTED, $props['meeting']['id']);
        self::assertSame('adjourned', $props['meeting']['status']);
        self::assertTrue($props['meetingContext']['readOnly']);
        self::assertSame([], array_filter($props['can']));
        self::assertCount(50, $props['testimony']);
        self::assertSame('/committees/committee', $props['urls']['current']);
    }

    public function test_foreign_meeting_is_rejected_before_other_reads_and_invalid_uuid_before_any_query(): void
    {
        DB::enableQueryLog(); DB::flushQueryLog();
        try { $this->page('not-a-uuid'); self::fail('Invalid meeting accepted.'); }
        catch (ValidationException) { self::assertSame([], DB::getQueryLog()); }
        try { $this->page(self::FOREIGN); self::fail('Another committee meeting accepted.'); }
        catch (ModelNotFoundException) {
            self::assertCount(1, DB::getQueryLog());
            self::assertSame(['committee', self::FOREIGN], DB::getQueryLog()[0]['bindings']);
        }
    }

    public function test_no_meeting_query_keeps_the_current_committee_view_and_combined_testimony(): void
    {
        $props = $this->page();
        self::assertSame(self::FUTURE, $props['meeting']['id']);
        self::assertFalse($props['meetingContext']['explicit']);
        self::assertSame('Future testimony', $props['testimony'][0]['text']);
        self::assertTrue($props['can']['fileReport']);
    }

    private function page(?string $meeting = null): array
    {
        $request = Request::create('/committees/committee'.($meeting === null ? '' : '?meeting='.$meeting));
        $request->setUserResolver(fn () => (new User())->forceFill(['id' => 'viewer']));
        $committee = (new Committee())->forceFill(['id' => 'committee', 'legislature_id' => 'leg', 'status' => 'seated', 'chair_member_id' => 'member']);
        $response = $this->controller->show($request, $committee);
        return (new ReflectionProperty($response, 'props'))->getValue($response);
    }

    private function row(string $table, string $id, array $data): void { DB::table($table)->insert(['id' => $id, ...$data]); }
}
