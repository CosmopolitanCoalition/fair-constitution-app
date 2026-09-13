<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Judiciary\AdvocateController;
use App\Http\Controllers\Legislature\SettingsController;
use App\Models\Jurisdiction;
use App\Models\Legislature;
use App\Models\User;
use App\Support\CivicHistoryDirectory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CivicHistoryDirectoryTest extends TestCase
{
    private string $original;
    protected function setUp(): void
    {
        parent::setUp(); $this->original = DB::getDefaultConnection();
        config(['database.connections.civic_history_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('civic_history_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName()); self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $s = DB::connection()->getSchemaBuilder();
        $s->create('setting_changes', function (Blueprint $t) {
            $t->uuid('id')->primary(); foreach (['jurisdiction_id', 'setting_key', 'law_id', 'old_value', 'new_value'] as $c) $t->string($c);
            $t->timestamp('applied_at')->nullable();
        });
        $s->create('laws', function (Blueprint $t) { $t->uuid('id')->primary(); $t->string('act_number'); $t->uuid('enacting_bill_id'); $t->softDeletes(); });
        $s->create('cases', function (Blueprint $t) { $t->uuid('id')->primary(); $t->string('title'); $t->string('docket_no'); $t->softDeletes(); });
        $s->create('case_filings', function (Blueprint $t) {
            $t->bigInteger('seq')->primary(); foreach (['advocate_id', 'case_id', 'filing_form', 'filing_kind', 'title', 'body'] as $c) $t->string($c)->nullable(); $t->timestamp('created_at')->nullable();
        });
        $s->create('advocates', function (Blueprint $t) { $t->uuid('id')->primary(); foreach (['user_id', 'judiciary_id', 'status'] as $c) $t->string($c); $t->timestamp('registered_at')->nullable(); $t->softDeletes(); });
        $s->create('judiciaries', function (Blueprint $t) { $t->uuid('id')->primary(); $t->uuid('jurisdiction_id'); $t->string('court_name'); $t->softDeletes(); });
        DB::table('laws')->insert(['id' => $this->id(40), 'act_number' => 'Act 7', 'enacting_bill_id' => $this->id(41)]);
        DB::table('cases')->insert(['id' => $this->id(50), 'title' => 'Fixture case', 'docket_no' => 'Fixture 1']);
        DB::table('judiciaries')->insert(['id' => $this->id(60), 'jurisdiction_id' => $this->id(1), 'court_name' => 'Fixture court']);
        DB::table('advocates')->insert(['id' => $this->id(1), 'user_id' => $this->id(70), 'judiciary_id' => $this->id(60), 'status' => 'registered']);
        foreach (range(1, 126) as $n) {
            DB::table('setting_changes')->insert(['id' => $this->id(1000 + $n), 'jurisdiction_id' => $this->id($n === 126 ? 2 : 1), 'setting_key' => 'civil_appointment_years', 'law_id' => $this->id(40), 'old_value' => '10', 'new_value' => '12', 'applied_at' => $n % 2 ? null : '2026-09-13 12:00:00']);
            DB::table('case_filings')->insert(['seq' => $n, 'advocate_id' => $this->id($n === 126 ? 2 : 1), 'case_id' => $this->id(50), 'filing_form' => 'F-ADV-003', 'filing_kind' => 'evidence', 'title' => 'Evidence '.$n, 'created_at' => '2026-09-13 12:00:00']);
        }
    }
    protected function tearDown(): void { DB::purge('civic_history_fixture'); DB::setDefaultConnection($this->original); parent::tearDown(); }
    private function id(int $n): string { return sprintf('40000000-0000-4000-8000-%012d', $n); }
    private function page(string $kind, string $url = '/fixture?jurisdiction=place', ?int $scope = 1): array
    { return (new CivicHistoryDirectory)->page(Request::create($url), $kind, $scope === null ? null : $this->id($scope), '/fixture'); }

    public function test_both_histories_reach_125_records_bidirectionally_with_exact_law_and_case_links(): void
    {
        foreach (['changes', 'filings'] as $kind) {
            $a = $this->page($kind); $b = $this->page($kind, $a['pagination']['next']); $c = $this->page($kind, $b['pagination']['next']);
            self::assertCount(50, $a['records']); self::assertCount(50, $b['records']); self::assertCount(25, $c['records']);
            $all = array_merge($a['records'], $b['records'], $c['records']);
            self::assertSame($kind === 'changes' ? array_map($this->id(...), range(1125, 1001)) : range(125, 1), array_column($all, $kind === 'changes' ? 'id' : 'seq'));
            self::assertSame($b['records'], $this->page($kind, $c['pagination']['previous'])['records']);
            self::assertSame($a['records'], $this->page($kind, $b['pagination']['previous'])['records']);
            self::assertNull($c['pagination']['next']); self::assertStringContainsString('jurisdiction=place', $b['pagination']['previous']);
            if ($kind === 'changes') { self::assertSame('/bills/'.$this->id(41), $a['records'][0]['bill_href']); self::assertNull($a['records'][0]['applied_at']); }
            else self::assertSame('/cases/'.$this->id(50), $a['records'][0]['case']['href']);
        }
    }

    public function test_malformed_and_other_scope_cursors_refuse_before_history_queries(): void
    {
        foreach (['changes', 'filings'] as $kind) {
            $link = $this->page($kind)['pagination']['next'];
            foreach ([['/fixture?'.$kind.'_cursor=bad', 1], [$link, 2]] as [$url, $scope]) {
                DB::enableQueryLog(); DB::flushQueryLog();
                try { $this->page($kind, $url, $scope); self::fail('Expected invalid cursor'); }
                catch (ValidationException $e) { self::assertArrayHasKey($kind.'_cursor', $e->errors()); }
                self::assertSame([], DB::getQueryLog());
            }
        }
        DB::flushQueryLog(); self::assertSame([], $this->page('filings', scope: null)['records']); self::assertSame([], DB::getQueryLog());
    }

    public function test_real_settings_partial_omits_register_member_and_clock_reads(): void
    {
        $request = $this->request('/legislatures/'.$this->id(20).'/settings', 'Legislature/Settings', 'changes,change_pages');
        $legislature = (new Legislature)->forceFill(['id' => $this->id(20), 'jurisdiction_id' => $this->id(1)]);
        $legislature->setRelation('jurisdiction', (new Jurisdiction)->forceFill(['id' => $this->id(1)]));
        DB::enableQueryLog(); DB::flushQueryLog();
        $props = (new SettingsController($this->createMock(ConstitutionalEngine::class)))->show($request, $legislature)->toResponse($request)->getData(true)['props'];
        self::assertSame(['changes', 'change_pages'], array_keys($props)); self::assertCount(50, $props['changes']);
        self::assertCount(2, DB::getQueryLog(), 'Only selected changes and their laws.');
    }

    public function test_real_advocate_partial_uses_authenticated_advocate_and_omits_composer_cases(): void
    {
        $request = $this->request('/judiciary/advocate?advocate_id='.$this->id(2), 'Judiciary/AdvocateConsole', 'filings,filing_pages');
        $request->setUserResolver(fn () => (new User)->forceFill(['id' => $this->id(70)]));
        DB::enableQueryLog(); DB::flushQueryLog();
        $props = (new AdvocateController($this->createMock(ConstitutionalEngine::class)))->show($request)->toResponse($request)->getData(true)['props'];
        self::assertSame(['filings', 'filing_pages'], array_keys($props)); self::assertCount(50, $props['filings']);
        self::assertSame(125, $props['filings'][0]['seq']);
        foreach (DB::getQueryLog() as $q) { self::assertStringStartsWith('select', $q['query']); self::assertStringNotContainsString('filed_via_form', $q['query']); }
        $guest = $this->request('/judiciary/advocate', 'Judiciary/AdvocateConsole', 'filings,filing_pages');
        DB::flushQueryLog();
        $props = (new AdvocateController($this->createMock(ConstitutionalEngine::class)))->show($guest)->toResponse($guest)->getData(true)['props'];
        self::assertSame([], $props['filings']); self::assertSame([], DB::getQueryLog());
    }
    private function request(string $url, string $component, string $only): Request
    {
        $r = Request::create($url); $r->setUserResolver(fn () => null);
        $r->headers->set('X-Inertia', 'true'); $r->headers->set('X-Inertia-Partial-Component', $component); $r->headers->set('X-Inertia-Partial-Data', $only); return $r;
    }
}
