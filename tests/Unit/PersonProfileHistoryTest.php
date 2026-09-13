<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Elections\CandidacyController;
use App\Http\Controllers\Social\PersonProfileController;
use App\Http\Presenters\CandidacyPanel;
use App\Services\JourneyService;
use App\Services\RoleService;
use App\Services\Social\PrivateRoomService;
use App\Support\PersonProfileHistory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Explicit isolated reads; no live-PG helpers, civic writes, queues or network calls. */
class PersonProfileHistoryTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.profile_history_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('profile_history_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $s = DB::connection()->getSchemaBuilder();
        $s->create('users', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('display_name');
            $t->string('name');
            $t->string('email');
            $t->softDeletes();
        });
        $s->create('social_profiles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->string('visibility');
            $t->string('display_name');
            $t->string('handle');
            $t->text('bio');
            $t->softDeletes();
        });
        $s->create('jurisdictions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('slug');
            $t->integer('adm_level');
            $t->softDeletes();
        });
        $s->create('audit_log', function (Blueprint $t) {
            $t->bigInteger('seq')->primary();
            $t->uuid('actor_user_id');
            $t->boolean('rejected')->default(false);
            $t->string('module');
            $t->string('event');
            $t->string('ref')->nullable();
            $t->timestamp('occurred_at');
            $t->text('payload')->nullable();
        });
        $s->create('public_records', function (Blueprint $t) {
            $t->bigInteger('seq')->primary();
            $t->uuid('id');
            $t->uuid('actor_user_id');
            $t->string('title');
            $t->text('body');
            $t->string('kind');
            $t->timestamp('published_at');
            $t->bigInteger('audit_seq')->nullable();
            $t->uuid('supersedes_record_id')->nullable();
        });
        $s->create('terms', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('holder_user_id');
            $t->uuid('jurisdiction_id');
            $t->string('office_kind');
            $t->string('office_type');
            $t->uuid('office_id');
            $t->string('status');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->softDeletes();
        });
        foreach (['legislatures', 'executives', 'judiciaries'] as $table) {
            $s->create($table, function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('jurisdiction_id');
                $t->string('court_name')->nullable();
                $t->string('type')->nullable();
                $t->softDeletes();
            });
        }
        foreach (['legislature_members' => 'legislature_id', 'executive_members' => 'executive_id', 'judicial_seats' => 'judiciary_id'] as $table => $foreign) {
            $s->create($table, function (Blueprint $t) use ($foreign) {
                $t->uuid('id')->primary();
                $t->uuid($foreign);
                $t->uuid('user_id');
                $t->string('status');
                $t->string('role')->default('principal');
                $t->string('seat_type')->default('a');
                $t->boolean('is_speaker')->default(false);
                foreach (['seated_on', 'term_ends_on', 'vacated_at', 'joined_at', 'left_at', 'term_starts_on'] as $date) {
                    $t->date($date)->nullable();
                } $t->softDeletes();
            });
        }
        $s->create('board_seats', function (Blueprint $t) {
            $t->uuid('id');
            $t->uuid('board_id');
        });
        $s->create('candidacies', function (Blueprint $t) {
            $t->uuid('id');
            $t->uuid('user_id');
            $t->timestamps();
            $t->softDeletes();
        });
        $s->create('endorsements', function (Blueprint $t) { $t->uuid('id')->primary(); $t->uuid('election_id');
            $t->uuid('candidate_id');
            $t->uuid('endorser_id');
            $t->string('endorser_type');
            $t->boolean('is_active');
            $t->boolean('is_public');
            $t->timestamp('withdrawn_at')->nullable();
            $t->timestamp('endorsed_at');
            $t->softDeletes();
        });
        DB::table('users')->insert(['id' => $this->id(1), 'display_name' => 'River', 'name' => 'Private legal name', 'email' => 'private@example.invalid']);
        DB::table('social_profiles')->insert(['id' => $this->id(2), 'user_id' => $this->id(1), 'visibility' => 'private', 'display_name' => 'Private social name', 'handle' => 'private-handle', 'bio' => 'Private biography']);
        DB::table('jurisdictions')->insert(['id' => $this->id(3), 'name' => 'Public place', 'slug' => 'public-place', 'adm_level' => 2]);
        foreach (['legislatures', 'executives', 'judiciaries'] as $table) {
            DB::table($table)->insert(['id' => $this->id(4), 'jurisdiction_id' => $this->id(3), 'court_name' => 'Public court', 'type' => 'appointed']);
        }
    }

    protected function tearDown(): void
    {
        DB::purge('profile_history_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function id(int $n): string
    {
        return sprintf('90000000-0000-4000-8000-%012d', $n);
    }

    private function reader(): PersonProfileHistory
    {
        return new PersonProfileHistory;
    }

    private function request(string $url = '/people'): Request
    {
        $r = Request::create($url);
        $r->setUserResolver(fn () => null);

        return $r;
    }

    private function action(int $seq, array $extra = []): void
    {
        DB::table('audit_log')->insert($extra + ['seq' => $seq, 'actor_user_id' => $this->id(1), 'module' => 'legislature', 'event' => 'public vote', 'occurred_at' => '2026-09-13 12:00:00', 'payload' => 'SECRET RAW PAYLOAD']);
    }

    private function term(int $n, array $extra = []): void
    {
        DB::table('terms')->insert($extra + ['id' => $this->id($n), 'holder_user_id' => $this->id(1), 'jurisdiction_id' => $this->id(3),
            'office_kind' => 'judicial_seat', 'office_type' => 'judicial_seats', 'office_id' => $this->id(5), 'status' => 'completed', 'starts_on' => '2020-01-01', 'ends_on' => '2025-01-01']);
    }

    public function test_all_public_actions_are_reachable_both_ways_without_private_payloads_or_world_reads(): void
    {
        for ($i = 1; $i <= 45; $i++) {
            $this->action($i);
        }
        foreach (['location_ping', 'travel_declared', 'relocation'] as $i => $event) {
            $this->action(50 + $i, ['module' => 'residency', 'event' => $event]);
        }
        $this->action(60, ['rejected' => true]);
        $this->action(61, ['module' => 'organizations']);
        $this->action(62, ['actor_user_id' => $this->id(99)]);
        DB::enableQueryLog();
        $first = $this->reader()->actions($this->request(), $this->id(1));
        $second = $this->reader()->actions($this->request($first['pages']['next']), $this->id(1));
        $third = $this->reader()->actions($this->request($second['pages']['next']), $this->id(1));
        $back = $this->reader()->actions($this->request($second['pages']['previous']), $this->id(1));
        self::assertSame(range(45, 1), array_map('intval', array_column([...$first['rows'], ...$second['rows'], ...$third['rows']], 'seq')));
        self::assertSame($first['rows'], $back['rows']);
        self::assertNull($third['pages']['next']);
        self::assertStringNotContainsString('SECRET', json_encode($first));
        self::assertSame('/system/audit-chain?seq=45', $first['rows'][0]['href']);
        foreach (DB::getQueryLog() as $query) {
            self::assertStringContainsString('limit 21', $query['query']);
            self::assertStringContainsString('actor_user_id', $query['query']);
            self::assertStringNotContainsString('count(', $query['query']);
            self::assertStringNotContainsString('offset', $query['query']);
        }
    }

    public function test_publications_include_full_public_documents_and_corrections_beyond_the_first_page(): void
    {
        for ($i = 1; $i <= 43; $i++) {
            DB::table('public_records')->insert(['seq' => $i, 'id' => $this->id(100 + $i), 'actor_user_id' => $this->id(1), 'title' => 'Public document '.$i, 'body' => 'Published full text '.$i, 'kind' => 'other', 'published_at' => '2026-09-13', 'supersedes_record_id' => $i === 43 ? $this->id(101) : null]);
        }
        $rows = [];
        $url = '/people';
        do {
            $page = $this->reader()->publications($this->request($url), $this->id(1));
            array_push($rows, ...$page['rows']);
            $url = $page['pages']['next'];
        } while ($url);
        self::assertCount(43, $rows);
        self::assertSame($this->id(101), $rows[0]['corrects']);
        self::assertSame('Published full text 1', $rows[42]['body']);
    }

    public function test_office_history_keeps_former_holders_and_includes_legacy_seats_without_duplicate_terms(): void
    {
        // A reusable judicial seat now belongs to a different person; the old holder's terms must still be shown.
        DB::table('judicial_seats')->insert(['id' => $this->id(5), 'judiciary_id' => $this->id(4), 'user_id' => $this->id(99), 'status' => 'seated']);
        for ($i = 100; $i < 145; $i++) {
            $this->term($i);
        }
        $this->term(150, ['holder_user_id' => $this->id(99)]);
        $this->term(151, ['deleted_at' => '2026-09-13']);
        DB::table('legislature_members')->insert(['id' => $this->id(10), 'legislature_id' => $this->id(4), 'user_id' => $this->id(1), 'status' => 'removed', 'seated_on' => '2015-01-01', 'term_ends_on' => '2020-01-01', 'vacated_at' => '2018-01-01']);
        DB::table('executive_members')->insert(['id' => $this->id(11), 'executive_id' => $this->id(4), 'user_id' => $this->id(1), 'status' => 'left', 'joined_at' => '2012-01-01', 'left_at' => '2014-01-01']);
        DB::table('legislature_members')->insert(['id' => $this->id(12), 'legislature_id' => $this->id(4), 'user_id' => $this->id(1), 'status' => 'term_ended', 'seated_on' => '2010-01-01']);
        $this->term(160, ['office_kind' => 'legislature_seat', 'office_type' => 'legislature_members', 'office_id' => $this->id(12), 'starts_on' => '2010-01-01']);
        self::assertTrue($this->reader()->hasOffices($this->id(1)));
        $rows = [];
        $url = '/people';
        $pages = [];
        do {
            $page = $this->reader()->offices($this->request($url), $this->id(1));
            $pages[] = $page;
            array_push($rows, ...$page['rows']);
            $url = $page['pages']['next'];
        } while ($url);
        self::assertCount(48, $rows);
        self::assertCount(48, array_unique(array_column($rows, 'record_key')));
        self::assertSame('/judiciaries/'.$this->id(4), $rows[0]['href']);
        self::assertSame('Judge', $rows[0]['title']);
        self::assertSame('left', $rows[46]['status']);
        self::assertSame('2014-01-01', $rows[46]['left_on']);
        self::assertSame('2010-01-01', $rows[47]['starts']);
        self::assertSame($pages[0]['rows'], $this->reader()->offices($this->request($pages[1]['pages']['previous']), $this->id(1))['rows']);
    }

    public function test_page_tokens_cannot_switch_people_or_readers_and_invalid_bookmarks_recover(): void
    {
        for ($i = 1; $i <= 22; $i++) {
            $this->action($i);
        }
        $next = $this->reader()->actions($this->request(), $this->id(1))['pages']['next'];
        $other = $this->reader()->actions($this->request($next), $this->id(99));
        self::assertSame([], $other['rows']);
        self::assertNotNull($other['notice']);
        parse_str(parse_url($next, PHP_URL_QUERY), $params);
        $switched = $this->reader()->publications($this->request('/people?'.http_build_query(['profile_publications_cursor' => $params['profile_actions_cursor']])), $this->id(1));
        self::assertNotNull($switched['notice']);
        foreach (['garbage', ['array'], str_repeat('x', 2100)] as $value) {
            $page = $this->reader()->actions($this->request('/people?'.http_build_query(['profile_actions_cursor' => $value])), $this->id(1));
            self::assertSame('22', $page['rows'][0]['seq']);
            self::assertNotNull($page['notice']);
        }
    }

    private function controller(): PersonProfileController
    {
        return new PersonProfileController($this->createMock(CandidacyPanel::class), $this->createMock(JourneyService::class),
            $this->createMock(ConstitutionalEngine::class), $this->createMock(PrivateRoomService::class));
    }

    public function test_large_audit_sequences_remain_exact_and_out_of_range_cursors_recover(): void
    {
        for ($i = 0; $i < 23; $i++) {
            $this->action(9007199254740993 + $i);
        }
        $first = $this->reader()->actions($this->request(), $this->id(1));
        $last = $this->reader()->actions($this->request($first['pages']['next']), $this->id(1));
        self::assertSame('9007199254740993', $last['rows'][2]['seq']);
        self::assertSame('/system/audit-chain?seq=9007199254740993', $last['rows'][2]['href']);
        parse_str(parse_url($first['pages']['next'], PHP_URL_QUERY), $params);
        $token = json_decode(base64_decode(strtr($params['profile_actions_cursor'], '-_', '+/')), true);
        $token['seq'] = '9223372036854775808';
        $bad = rtrim(strtr(base64_encode(json_encode($token)), '+/', '-_'), '=');
        $recovered = $this->reader()->actions($this->request('/people?profile_actions_cursor='.$bad), $this->id(1));
        self::assertNotNull($recovered['notice']);
        self::assertSame($first['rows'], $recovered['rows']);
    }

    public function test_private_social_preferences_do_not_hide_past_office_or_public_records(): void
    {
        $this->term(100);
        $this->action(1);
        $r = $this->request('/people?who='.$this->id(1).'&tab=office');
        $r->headers->set('X-Inertia', 'true');
        $response = $this->controller()->show($r)->toResponse($r)->getData(true);
        self::assertSame('Social/PersonProfile', $response['component']);
        self::assertSame('office', $response['props']['tab']);
        self::assertSame([], $response['props']['offices']);
        self::assertContains('office', $response['props']['tabs']);
        self::assertCount(1, $response['props']['officeHistory']['rows']);
        self::assertCount(1, $response['props']['actionHistory']['rows']);
        self::assertNull($response['props']['person']['bio']);
        self::assertNull($response['props']['achievements']);
        self::assertStringNotContainsString('Private legal name', json_encode($response));
        self::assertStringNotContainsString('Private biography', json_encode($response));
    }

    public function test_actual_inertia_history_partial_does_not_recompute_other_profile_sections(): void
    {
        $this->action(1);
        DB::enableQueryLog();
        $r = $this->request('/people?who='.$this->id(1).'&tab=candidacy');
        foreach (['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Social/PersonProfile', 'X-Inertia-Partial-Data' => 'actionHistory'] as $key => $value) {
            $r->headers->set($key, $value);
        }
        $data = $this->controller()->show($r)->toResponse($r)->getData(true)['props'];
        self::assertArrayHasKey('actionHistory', $data);
        self::assertArrayNotHasKey('candidacyPanel', $data);
        self::assertArrayNotHasKey('offices', $data);
        $queries = implode('\n', array_column(DB::getQueryLog(), 'query'));
        foreach (['candidacies', 'terms', 'endorsements', 'approval_standings', 'public_records'] as $table) {
            self::assertStringNotContainsString('"'.$table.'"', $queries);
        }
    }

    public function test_legacy_candidate_links_still_resolve_to_one_person_profile(): void
    {
        DB::table('candidacies')->insert(['id' => $this->id(10), 'user_id' => $this->id(1)]);
        $candidates = new CandidacyController($this->createMock(ConstitutionalEngine::class), $this->createMock(RoleService::class));
        $redirect = $candidates->show($this->request(), $this->id(10))->getTargetUrl();
        self::assertSame($redirect, $this->controller()->show($this->request('/people?candidate='.$this->id(10)))->getTargetUrl());
        self::assertStringContainsString('/people?who='.$this->id(1), $redirect);
        self::assertStringContainsString('tab=candidacy', $redirect);
    }
}
