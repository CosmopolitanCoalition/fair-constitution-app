<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Legislature\SessionController;
use App\Http\Presenters\ChamberVotePresenter;
use App\Models\Legislature;
use App\Services\Legislature\SessionArchive;
use App\Services\RoleService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Memory-only session archive fixtures. No engine filings or live database writes. */
final class SessionArchiveTest extends TestCase
{
    private string $original;
    private SessionArchive $archive;
    private $votes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.session_archive_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('session_archive_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('legislature_sessions', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('legislature_id'); $t->integer('session_no'); $t->string('status');
            foreach (['scheduled_for', 'opened_at', 'adjourned_at', 'minutes_record_id', 'agenda', 'serving_by_kind', 'quorum_required_by_kind'] as $field) $t->string($field)->nullable();
            $t->integer('serving_at_open')->nullable(); $t->integer('quorum_required')->nullable(); $t->boolean('quorum_met')->nullable(); $t->softDeletes();
        });
        $schema->create('session_attendance', function (Blueprint $t) {
            foreach (['id', 'session_id', 'member_id', 'status'] as $field) $t->string($field);
        });
        $schema->create('motions', function (Blueprint $t) {
            foreach (['id', 'session_id', 'moved_by_member_id', 'kind', 'text', 'status'] as $field) $t->string($field);
            $t->string('vote_id')->nullable(); $t->string('bill_id')->nullable(); $t->softDeletes();
        });
        $schema->create('public_records', function (Blueprint $t) {
            $t->bigInteger('seq')->primary(); $t->bigInteger('audit_seq')->nullable();
            foreach (['id', 'legislature_id', 'subject_type', 'subject_id', 'kind', 'title', 'body'] as $field) $t->string($field);
            $t->timestamp('published_at')->nullable();
        });
        $schema->create('legislature_members', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('user_id'); $t->string('seat_type')->default('a'); $t->integer('seat_no')->default(1); $t->softDeletes();
        });
        $schema->create('users', function (Blueprint $t) { $t->string('id')->primary(); $t->string('display_name'); $t->softDeletes(); });
        $schema->create('chamber_votes', function (Blueprint $t) { $t->string('id')->primary(); $t->softDeletes(); });
        $schema->create('chamber_vote_tallies', function (Blueprint $t) { $t->string('id')->primary(); $t->string('vote_id'); });
        $schema->create('vote_casts', function (Blueprint $t) {
            foreach (['id', 'vote_id', 'member_id', 'value'] as $field) $t->string($field);
            $t->string('explanation')->nullable(); $t->boolean('is_tiebreak')->default(false);
        });
        DB::table('users')->insert(['id' => $this->id(1), 'display_name' => 'Public chosen name']);
        DB::table('legislature_members')->insert(['id' => $this->id(2), 'user_id' => $this->id(1)]);
        for ($i = 1; $i <= 42; $i++) {
            DB::table('legislature_sessions')->insert([
                'id' => $this->id(100 + $i), 'legislature_id' => $this->id(10), 'session_no' => $i,
                'status' => $i === 42 ? 'open' : 'adjourned', 'opened_at' => '2026-09-01 12:00:00',
                'agenda' => json_encode([['title' => 'Session '.$i.' agenda', 'kind' => 'general']]),
            ]);
        }
        DB::table('legislature_sessions')->insert(['id' => $this->id(999), 'legislature_id' => $this->id(11), 'session_no' => 99, 'status' => 'open']);
        $this->votes = $this->createMock(ChamberVotePresenter::class);
        $this->votes->method('tallyProps')->willReturn(['outcome' => 'passed', 'serving' => 9, 'requiredYes' => 5]);
        $this->archive = new SessionArchive($this->votes);
        app()->instance(SessionArchive::class, $this->archive);
    }

    protected function tearDown(): void
    {
        DB::purge('session_archive_fixture'); DB::setDefaultConnection($this->original); parent::tearDown();
    }

    private function id(int $n): string { return sprintf('10000000-0000-4000-8000-%012d', $n); }
    private function request(array $query = []): Request { return Request::create('/legislatures/'.$this->id(10).'/session?'.http_build_query($query)); }
    private function record(array $query = []): array { return $this->archive->record($this->request($query + ['session' => $this->id(101)]), $this->id(10)); }
    private function follow(string $url): array { parse_str(parse_url($url, PHP_URL_QUERY), $query); return $query; }

    public function test_archive_walks_all_scoped_sessions_without_offset_or_total_count(): void
    {
        DB::connection()->enableQueryLog();
        $request = Request::create('/legislatures/'.$this->id(10).'/sessions');
        $seen = [];
        do {
            $page = $this->archive->listing($request, $this->id(10));
            self::assertLessThanOrEqual(20, count($page['data']));
            array_push($seen, ...array_column($page['data'], 'number'));
            if ($page['next']) $request = Request::create($page['next']);
        } while ($page['next']);
        self::assertSame(range(42, 1), $seen);
        foreach (DB::getQueryLog() as $query) {
            self::assertStringNotContainsString('offset', strtolower($query['query']));
            self::assertStringNotContainsString('count(', strtolower($query['query']));
            self::assertStringContainsString('legislature_id', $query['query']);
        }
    }

    public function test_exact_session_selection_keeps_agenda_and_rejects_another_legislatures_session(): void
    {
        self::assertSame('Session 1 agenda', $this->record()['session']['agenda'][0]['title']);
        $this->expectException(ModelNotFoundException::class);
        $this->record(['session' => $this->id(999)]);
    }

    public function test_attendance_motions_and_records_each_offer_all_pages_and_preserve_exact_selection(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            DB::table('session_attendance')->insert(['id' => $this->id(200 + $i), 'session_id' => $this->id(101), 'member_id' => $this->id(2), 'status' => 'present']);
            DB::table('motions')->insert(['id' => $this->id(300 + $i), 'session_id' => $this->id(101), 'moved_by_member_id' => $this->id(2), 'kind' => 'procedural', 'text' => 'Historical motion '.$i, 'status' => 'adopted']);
            DB::table('public_records')->insert(['seq' => $i, 'id' => $this->id(400 + $i), 'legislature_id' => $this->id(10), 'subject_type' => 'legislature_session', 'subject_id' => $this->id(101), 'kind' => 'statement', 'title' => 'Statement '.$i, 'body' => 'Historical words']);
        }
        DB::table('public_records')->insert(['seq' => 99, 'id' => $this->id(499), 'legislature_id' => $this->id(10), 'subject_type' => 'legislature_session', 'subject_id' => $this->id(142), 'kind' => 'statement', 'title' => 'Unrelated current statement', 'body' => 'Must not appear']);
        $first = $this->record();
        foreach (['attendance', 'motions', 'records'] as $section) {
            self::assertCount(20, $first[$section]['data']);
            $query = $this->follow($first[$section]['next']);
            self::assertSame($this->id(101), $query['session']);
            $second = $this->record($query);
            self::assertCount(5, $second[$section]['data']);
            self::assertNull($second[$section]['next']);
            $back = $this->record($this->follow($second[$section]['previous']));
            self::assertSame($first[$section]['data'], $back[$section]['data']);
        }
        self::assertSame('Public chosen name', $first['attendance']['data'][0]['name']);
    }

    public function test_selected_motion_casts_are_bounded_and_scoped_without_loading_all_other_casts(): void
    {
        DB::table('chamber_votes')->insert(['id' => $this->id(500)]);
        DB::table('motions')->insert(['id' => $this->id(501), 'session_id' => $this->id(101), 'moved_by_member_id' => $this->id(2), 'kind' => 'procedural', 'text' => 'Historical vote', 'status' => 'adopted', 'vote_id' => $this->id(500)]);
        DB::table('motions')->insert(['id' => $this->id(502), 'session_id' => $this->id(142), 'moved_by_member_id' => $this->id(2), 'kind' => 'procedural', 'text' => 'Wrong session', 'status' => 'adopted']);
        for ($i = 1; $i <= 23; $i++) DB::table('vote_casts')->insert(['id' => $this->id(600 + $i), 'vote_id' => $this->id(500), 'member_id' => $this->id(2), 'value' => 'yes']);
        $this->votes->expects($this->never())->method('casts');
        self::assertNull($this->record()['casts']);
        $first = $this->record(['motion' => $this->id(501)]);
        self::assertCount(20, $first['casts']['data']);
        self::assertCount(3, $this->record($this->follow($first['casts']['next']))['casts']['data']);
        $this->expectException(ModelNotFoundException::class);
        $this->record(['motion' => $this->id(502)]);
    }

    public function test_record_controller_bypasses_current_ballots_roles_and_all_mutable_console_props(): void
    {
        $engine = $this->createMock(ConstitutionalEngine::class); $engine->expects($this->never())->method('file');
        $roles = $this->createMock(RoleService::class); $roles->expects($this->never())->method('rolesFor');
        $controller = new SessionController($engine, $this->votes, $roles);
        $legislature = (new Legislature())->forceFill(['id' => $this->id(10)]);
        $legislature->setRelation('jurisdiction', null);
        $response = $controller->show($this->request(['session' => $this->id(101)]), $legislature);
        $reflection = new \ReflectionClass($response);
        $component = $reflection->getProperty('component');
        self::assertSame('Legislature/SessionRecord', $component->getValue($response));
        $props = $reflection->getProperty('props')->getValue($response);
        foreach (['can', 'speakerBallot', 'dueBanner', 'myAttendanceMarked'] as $name) self::assertArrayNotHasKey($name, $props);
        self::assertSame($this->id(101), $props['session']['id']);
    }

    public function test_invalid_cursors_fail_before_domain_queries(): void
    {
        DB::connection()->enableQueryLog();
        foreach (['attendance_cursor', 'motions_cursor', 'records_cursor', 'casts_cursor'] as $name) {
            DB::connection()->flushQueryLog();
            try { $this->record([$name => 'broken']); self::fail('Invalid cursor should fail.'); }
            catch (ValidationException $error) { self::assertArrayHasKey($name, $error->errors()); }
            self::assertSame([], DB::getQueryLog());
        }
    }
}
