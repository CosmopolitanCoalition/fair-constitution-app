<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Judiciary\AdvocateController;
use App\Models\User;
use App\Support\AdvocateCaseDirectory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Actual controller/Inertia reads on an explicit private memory database. */
final class AdvocateCaseDirectoryTest extends TestCase
{
    private string $original;
    private AdvocateController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.advocate_cases_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('advocate_cases_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $s = DB::connection()->getSchemaBuilder();
        $s->create('users', function (Blueprint $t) { $t->uuid('id')->primary(); $t->string('name'); $t->string('display_name'); $t->softDeletes(); });
        $s->create('advocates', function (Blueprint $t) { $t->uuid('id')->primary(); $t->uuid('user_id'); $t->uuid('judiciary_id'); $t->string('status'); $t->timestamp('registered_at')->nullable(); $t->softDeletes(); });
        $s->create('judiciaries', function (Blueprint $t) { $t->uuid('id')->primary(); $t->uuid('jurisdiction_id'); $t->string('court_name'); $t->softDeletes(); });
        $s->create('cases', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('advocate_id'); $t->uuid('judiciary_id'); $t->string('filed_via_form');
            $t->string('title')->nullable(); $t->string('docket_no'); $t->string('kind'); $t->string('status');
            $t->boolean('jury_entitled'); $t->text('statement_of_claim'); $t->timestamps(); $t->softDeletes();
        });
        $s->create('panels', function (Blueprint $t) { $t->uuid('id')->primary(); $t->uuid('case_id'); $t->integer('size'); $t->boolean('is_en_banc'); $t->string('status'); $t->softDeletes(); });
        $s->create('case_filings', function (Blueprint $t) {
            $t->bigInteger('seq')->primary(); foreach (['advocate_id', 'case_id', 'filing_form', 'filing_kind', 'title', 'body'] as $c) $t->string($c)->nullable(); $t->timestamp('created_at')->nullable();
        });
        DB::table('judiciaries')->insert(['id' => $this->id(900), 'jurisdiction_id' => $this->id(901), 'court_name' => 'Fixture court']);
        foreach ([1, 2] as $n) {
            DB::table('users')->insert(['id' => $this->id(800 + $n), 'name' => 'Registered advocate', 'display_name' => 'Advocate '.$n]);
            DB::table('advocates')->insert(['id' => $this->id(700 + $n), 'user_id' => $this->id(800 + $n), 'judiciary_id' => $this->id(900), 'status' => 'registered']);
        }
        for ($n = 1; $n <= 47; $n++) $this->case($n, 'Case '.str_pad((string) intdiv($n - 1, 7), 2, '0', STR_PAD_LEFT));
        $this->case(101, 'Case another advocate', ['advocate_id' => $this->id(702)]);
        $this->case(102, 'Case another form', ['filed_via_form' => 'F-IND-016']);
        $this->case(103, 'Case deleted', ['deleted_at' => '2026-09-01']);
        DB::table('panels')->insert(['id' => $this->id(600), 'case_id' => $this->id(1), 'size' => 7, 'is_en_banc' => true, 'status' => 'seated']);
        $engine = $this->createMock(ConstitutionalEngine::class);
        $engine->expects($this->never())->method('file');
        $this->controller = new AdvocateController($engine);
    }

    protected function tearDown(): void { DB::purge('advocate_cases_fixture'); DB::setDefaultConnection($this->original); parent::tearDown(); }
    private function id(int $n): string { return sprintf('60000000-0000-4000-8000-%012d', $n); }
    private function case(int $n, string $title, array $extra = []): void
    {
        DB::table('cases')->insert(array_replace(['id' => $this->id($n), 'title' => $title, 'docket_no' => 'Docket-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            'advocate_id' => $this->id(701), 'judiciary_id' => $this->id(900), 'filed_via_form' => 'F-ADV-001', 'kind' => 'civil',
            'status' => $n === 2 ? 'closed' : ($n === 3 ? 'deliberation' : 'paneled'), 'jury_entitled' => false, 'statement_of_claim' => 'Do not preload long case bodies'], $extra));
    }
    private function request(string $url, ?string $only, ?int $actor = 1): Request
    {
        $r = Request::create($url); $r->setUserResolver(fn () => $actor === null ? null : (new User)->forceFill(['id' => $this->id(800 + $actor)]));
        $r->headers->set('X-Inertia', 'true');
        if ($only !== null) { $r->headers->set('X-Inertia-Partial-Component', 'Judiciary/AdvocateConsole'); $r->headers->set('X-Inertia-Partial-Data', $only); }
        return $r;
    }
    private function page(string $url = '/judiciary/advocate', ?string $only = 'myCases,case_pages', ?int $actor = 1): array
    {
        $r = $this->request($url, $only, $actor);
        return $this->controller->show($r)->toResponse($r)->getData(true)['props'];
    }
    private function log(): void { DB::enableQueryLog(); DB::flushQueryLog(); }
    private function caseQueries(): array { return array_values(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'from "cases"'))); }

    public function test_full_entry_loads_one_bounded_roster_and_no_composer_cases(): void
    {
        $this->log(); $p = $this->page(only: null);
        self::assertCount(20, $p['myCases']);
        self::assertArrayNotHasKey('composer_cases', $p); self::assertArrayNotHasKey('composer_case_pages', $p);
        self::assertSame(['types'], array_keys($p['composer']));
        self::assertSame($this->id(701), $p['advocate']['id']);
        self::assertSame('Full court — all 7 judges', $p['myCases'][0]['panel']);
        self::assertSame('Closed', $p['myCases'][1]['state']);
        self::assertSame('Deliberation', $p['myCases'][2]['state']);
        self::assertCount(1, $this->caseQueries());
        self::assertStringContainsString('limit 21', $this->caseQueries()[0]['query']);
        self::assertStringNotContainsString('statement_of_claim', $this->caseQueries()[0]['query']);
    }

    public function test_roster_and_composer_independently_reach_every_case_across_duplicate_titles(): void
    {
        foreach ([['myCases,case_pages', 'myCases', 'case_pages'], ['composer_cases,composer_case_pages', 'composer_cases', 'composer_case_pages']] as [$only, $rows, $pages]) {
            $first = $this->page(only: $only); $second = $this->page($first[$pages]['next'], $only); $third = $this->page($second[$pages]['next'], $only);
            self::assertCount(20, $first[$rows]); self::assertCount(20, $second[$rows]); self::assertCount(7, $third[$rows]);
            self::assertSame([$rows, $pages], array_keys($first));
            $all = array_merge($first[$rows], $second[$rows], $third[$rows]);
            self::assertSame(array_map($this->id(...), range(1, 47)), array_column($all, 'id'));
            foreach ($all as $row) self::assertSame('/cases/'.$row['id'], $row['href']);
            self::assertSame($first[$rows], $this->page($second[$pages]['previous'], $only)[$rows]);
            self::assertSame($second[$rows], $this->page($third[$pages]['previous'], $only)[$rows]);
            self::assertNull($third[$pages]['next']);
        }
    }

    public function test_real_partial_requests_skip_other_directories_and_seek_before_enrichment(): void
    {
        $first = $this->page(); $this->log();
        $this->page($first['case_pages']['next']);
        self::assertCount(1, $this->caseQueries());
        self::assertStringContainsString("(lower(COALESCE(title, '')), id) > (?, ?)", $this->caseQueries()[0]['query']);
        self::assertStringNotContainsString('offset', $this->caseQueries()[0]['query']);
        foreach (DB::getQueryLog() as $q) self::assertStringNotContainsString('case_filings', $q['query']);
        $this->log(); $this->page(only: 'composer_cases,composer_case_pages');
        self::assertCount(3, DB::getQueryLog(), 'Authenticated advocate, its court, and a single case page.');
        foreach (DB::getQueryLog() as $q) {
            self::assertStringStartsWith('select', $q['query']);
            self::assertStringNotContainsString('panels', $q['query']);
            self::assertStringNotContainsString('case_filings', $q['query']);
        }
        $this->log(); $this->page(only: 'filings,filing_pages');
        self::assertSame([], $this->caseQueries());
    }

    public function test_searches_use_literal_title_or_docket_prefix_and_keep_actor_scope(): void
    {
        $this->case(201, 'A% statement'); $this->case(202, 'A_ statement'); $this->case(203, '山田');
        self::assertSame([$this->id(201)], array_column($this->page('/judiciary/advocate?case_q=A%25')['myCases'], 'id'));
        self::assertSame([$this->id(202)], array_column($this->page('/judiciary/advocate?case_q=A_')['myCases'], 'id'));
        self::assertSame([$this->id(203)], array_column($this->page('/judiciary/advocate?case_q='.urlencode('山'))['myCases'], 'id'));
        $p = $this->page('/judiciary/advocate?compose_case_by=docket&compose_case_q=Docket-047&advocate_id='.$this->id(702), 'composer_cases,composer_case_pages');
        self::assertSame([$this->id(47)], array_column($p['composer_cases'], 'id'));
        self::assertSame('docket', $p['composer_case_pages']['by']);
        self::assertSame([], $this->page('/judiciary/advocate?case_q=Missing')['myCases']);
        self::assertSame([$this->id(101)], array_column($this->page(actor: 2)['myCases'], 'id'));
    }

    public function test_malformed_other_actor_other_directory_and_changed_search_cursors_refuse_before_case_reads(): void
    {
        $link = $this->page()['case_pages']['next'];
        parse_str(parse_url($link, PHP_URL_QUERY), $query);
        foreach ([
            ['/judiciary/advocate?case_cursor=bad', 'myCases,case_pages', 1],
            [$link, 'myCases,case_pages', 2],
            [$link.'&case_q=Different', 'myCases,case_pages', 1],
            ['/judiciary/advocate?compose_case_cursor='.urlencode($query['case_cursor']), 'composer_cases,composer_case_pages', 1],
            ['/judiciary/advocate?case_by=statement_of_claim', 'myCases,case_pages', 1],
        ] as [$url, $only, $actor]) {
            $this->log();
            try { $this->page($url, $only, $actor); self::fail('Expected invalid directory request.'); }
            catch (ValidationException $e) { self::assertNotEmpty($e->errors()); }
            self::assertSame([], $this->caseQueries());
        }
    }

    public function test_guest_directories_are_empty_without_a_world_query(): void
    {
        $this->log();
        $p = $this->page(only: 'myCases,case_pages,composer_cases,composer_case_pages', actor: null);
        self::assertSame([], $p['myCases']); self::assertSame([], $p['composer_cases']);
        self::assertSame([], DB::getQueryLog());
        self::assertSame([], (new AdvocateCaseDirectory)->page(Request::create('/judiciary/advocate'), null, 'roster')['cases']->all());
    }
}
