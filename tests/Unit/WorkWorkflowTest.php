<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\EngineResult;
use App\Http\Controllers\Economy\WorkController;
use App\Models\AuditEntry;
use App\Models\User;
use App\Services\Economy\LaborBoardService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Explicit memory fixtures: employer never impersonates an applicant or writes a contract. */
final class WorkWorkflowTest extends TestCase
{
    private string $original;
    private LaborBoardService $work;
    private $engine;
    private WorkController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.work_workflow_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('work_workflow_fixture');
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        self::assertSame('sqlite', DB::connection()->getDriverName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('organizations', function (Blueprint $t) {
            $t->string('id')->primary(); $t->string('agent_user_id'); $t->string('name'); $t->string('status'); $t->softDeletes();
        });
        $schema->create('work_postings', function (Blueprint $t) {
            $t->string('id')->primary(); foreach (['organization_id', 'title', 'terms', 'status'] as $field) $t->string($field);
            $t->string('rate')->nullable(); $t->string('currency_id')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        $schema->create('work_applications', function (Blueprint $t) {
            $t->string('id')->primary(); foreach (['posting_id', 'applicant_account_id', 'status'] as $field) $t->string($field);
            $t->string('note')->nullable(); $t->string('org_contract_id')->nullable(); $t->string('offer_terms')->nullable();
            $t->timestamp('offered_at')->nullable(); $t->timestamps(); $t->unique(['posting_id', 'applicant_account_id']);
        });
        $schema->create('economic_account_bindings', function (Blueprint $t) { foreach (['account_id', 'owner_type', 'owner_id'] as $field) $t->string($field); });
        DB::table('organizations')->insert([
            ['id' => $this->id(1), 'agent_user_id' => $this->id(11), 'name' => 'First organization', 'status' => 'active'],
            ['id' => $this->id(2), 'agent_user_id' => $this->id(12), 'name' => 'Other organization', 'status' => 'active'],
        ]);
        foreach ([21, 22] as $user) DB::table('economic_account_bindings')->insert(['account_id' => $this->id($user + 100), 'owner_type' => 'users', 'owner_id' => $this->id($user)]);
        $this->posting(31);
        $this->application(41, 31, 121);
        $this->engine = $this->createMock(ConstitutionalEngine::class);
        $this->work = new LaborBoardService($this->engine);
        $this->controller = new WorkController($this->work);
    }

    protected function tearDown(): void { DB::purge('work_workflow_fixture'); DB::setDefaultConnection($this->original); parent::tearDown(); }
    private function id(int $n): string { return sprintf('20000000-0000-4000-8000-%012d', $n); }
    private function user(int $n): User { return (new User())->forceFill(['id' => $this->id($n)]); }
    private function posting(int $id, int $org = 1): void
    {
        DB::table('work_postings')->insert(['id' => $this->id($id), 'organization_id' => $this->id($org), 'title' => 'Posting '.$id, 'terms' => 'Public posting terms', 'status' => 'open', 'created_at' => now()]);
    }
    private function application(int $id, int $posting = 31, int $account = 121): void
    {
        DB::table('work_applications')->insert(['id' => $this->id($id), 'posting_id' => $this->id($posting), 'applicant_account_id' => $this->id($account), 'status' => 'applied', 'note' => 'My application note', 'created_at' => now()]);
    }
    private function applicationRow(int $id = 41): object { return DB::table('work_applications')->where('id', $this->id($id))->first(); }
    private function forbidden(callable $fn): void { try { $fn(); self::fail('Expected forbidden operation.'); } catch (HttpException $e) { self::assertSame(403, $e->getStatusCode()); } }
    private function refused(callable $fn): void { try { $fn(); self::fail('Expected a readable refusal.'); } catch (RuntimeException $e) { self::assertNotEmpty($e->getMessage()); } }
    private function page(int $user, array $query = []): array
    {
        $request = Request::create('/economy/work?'.http_build_query($query)); $request->setUserResolver(fn () => $this->user($user));
        $response = $this->controller->index($request);
        return (new \ReflectionClass($response))->getProperty('props')->getValue($response);
    }
    private function follow(string $url): array { parse_str(parse_url($url, PHP_URL_QUERY), $query); return $query; }

    public function test_current_exact_agent_can_post_offer_and_close_without_worker_consent(): void
    {
        $this->engine->expects($this->never())->method('file');
        $id = $this->work->postFor($this->user(11), $this->id(1), 'Gardener', 'Complete work terms');
        self::assertSame('open', DB::table('work_postings')->where('id', $id)->value('status'));
        $this->forbidden(fn () => $this->work->postFor($this->user(12), $this->id(1), 'Invalid', 'No authority'));
        $this->forbidden(fn () => $this->work->offer($this->id(41), $this->user(12), 'Wrong organization'));
        $this->work->offer($this->id(41), $this->user(11), 'Agreed pay and schedule');
        self::assertSame('applied', $this->applicationRow()->status);
        self::assertSame('Agreed pay and schedule', $this->applicationRow()->offer_terms);
        self::assertNull($this->applicationRow()->org_contract_id);
        $this->work->closePosting($this->id(31), $this->user(11));
        self::assertSame('closed', DB::table('work_postings')->where('id', $this->id(31))->value('status'));
    }

    public function test_offer_terms_are_immutable_while_worker_decides_and_stale_agents_lose_access(): void
    {
        $this->engine->expects($this->never())->method('file');
        $this->work->offer($this->id(41), $this->user(11), 'First terms');
        $this->refused(fn () => $this->work->offer($this->id(41), $this->user(11), 'Changed terms'));
        self::assertSame('First terms', $this->applicationRow()->offer_terms);
        DB::table('organizations')->where('id', $this->id(1))->update(['agent_user_id' => $this->id(12)]);
        $this->forbidden(fn () => $this->work->decline($this->id(41), $this->user(11)));
        DB::table('organizations')->where('id', $this->id(1))->update(['status' => 'dissolved']);
        $this->forbidden(fn () => $this->work->closePosting($this->id(31), $this->user(12)));
    }

    public function test_applicant_alone_accepts_exact_offer_via_engine_and_stores_existing_contract_reference(): void
    {
        $this->work->offer($this->id(41), $this->user(11), 'Offered terms');
        $this->engine->expects($this->once())->method('file')->with('F-IND-014', $this->callback(fn ($actor) => $actor->id === $this->id(21)), [
            'employer_type' => 'organizations', 'employer_id' => $this->id(1), 'contract_terms' => 'Offered terms',
        ])->willReturn(new EngineResult('F-IND-014', new AuditEntry(), ['contract_id' => $this->id(91)]));
        $this->forbidden(fn () => $this->work->accept($this->id(41), $this->user(11)));
        $this->forbidden(fn () => $this->work->accept($this->id(41), $this->user(22)));
        $this->work->accept($this->id(41), $this->user(21));
        self::assertSame('accepted', $this->applicationRow()->status);
        self::assertSame($this->id(91), $this->applicationRow()->org_contract_id);
        self::assertSame('filled', DB::table('work_postings')->where('id', $this->id(31))->value('status'));
        $this->refused(fn () => $this->work->accept($this->id(41), $this->user(21)));
    }

    public function test_second_applicant_cannot_fill_an_already_filled_posting(): void
    {
        $this->application(42, 31, 122);
        foreach ([41, 42] as $id) $this->work->offer($this->id($id), $this->user(11), 'One position');
        $this->engine->expects($this->once())->method('file')->willReturn(new EngineResult('F-IND-014', new AuditEntry(), ['contract_id' => $this->id(91)]));
        $this->work->accept($this->id(41), $this->user(21));
        $this->refused(fn () => $this->work->accept($this->id(42), $this->user(22)));
        self::assertSame('applied', $this->applicationRow(42)->status);
        self::assertNull($this->applicationRow(42)->org_contract_id);
    }

    public function test_no_offer_closed_posting_or_engine_failure_never_creates_an_acceptance(): void
    {
        $this->refused(fn () => $this->work->accept($this->id(41), $this->user(21)));
        $this->work->offer($this->id(41), $this->user(11), 'Offered terms');
        $this->engine->expects($this->once())->method('file')->willThrowException(new RuntimeException('Engine refused registration'));
        $this->refused(fn () => $this->work->accept($this->id(41), $this->user(21)));
        self::assertSame('applied', $this->applicationRow()->status);
        self::assertNull($this->applicationRow()->org_contract_id);
        $this->work->closePosting($this->id(31), $this->user(11));
        $this->refused(fn () => $this->work->accept($this->id(41), $this->user(21)));
    }

    public function test_withdrawal_and_decline_preserve_history_and_duplicate_apply_is_readable(): void
    {
        $this->engine->expects($this->never())->method('file');
        $this->forbidden(fn () => $this->work->withdraw($this->id(41), $this->user(22)));
        $this->work->withdraw($this->id(41), $this->user(21));
        self::assertSame('withdrawn', $this->applicationRow()->status);
        $this->refused(fn () => $this->work->apply($this->id(31), $this->id(121)));
        self::assertSame(1, DB::table('work_applications')->count());
        $this->application(42, 31, 122);
        $this->work->decline($this->id(42), $this->user(11));
        self::assertSame('declined', $this->applicationRow(42)->status);
    }

    public function test_worker_applications_paginate_and_never_expose_another_applicants_binding(): void
    {
        for ($i = 1; $i <= 24; $i++) { $this->posting(100 + $i); $this->application(200 + $i, 100 + $i, 121); }
        $this->application(42, 31, 122);
        $this->work->offer($this->id(41), $this->user(11), 'Offered terms');
        $first = $this->page(21);
        self::assertCount(20, $first['applications']['data']);
        $next = $this->page(21, $this->follow($first['applications']['next']));
        self::assertCount(5, $next['applications']['data']);
        $json = json_encode([$first, $next]);
        foreach (['applicant_account_id', 'owner_id', $this->id(121), $this->id(122), $this->id(42)] as $private) self::assertStringNotContainsString($private, $json);
        $own = collect($next['applications']['data'])->firstWhere('id', $this->id(41));
        self::assertTrue($own['canAccept']);
        self::assertFalse($own['canOffer']);
        DB::table('organizations')->where('id', $this->id(1))->update(['status' => 'dissolved']);
        self::assertFalse(collect($this->page(21, $this->follow($first['applications']['next']))['applications']['data'])->firstWhere('id', $this->id(41))['canAccept']);
    }

    public function test_hiring_review_is_exact_agent_posting_scoped_and_cursor_bounded_without_names(): void
    {
        for ($i = 1; $i <= 24; $i++) $this->application(300 + $i, 31, 400 + $i);
        $query = ['tab' => 'hiring', 'organization' => $this->id(1), 'posting' => $this->id(31)];
        $this->forbidden(fn () => $this->page(12, $query));
        $first = $this->page(11, $query);
        self::assertCount(20, $first['applications']['data']);
        self::assertCount(5, $this->page(11, $this->follow($first['applications']['next']))['applications']['data']);
        $json = json_encode($first['applications']);
        foreach (['applicant_account_id', 'owner_id', 'user_id', $this->id(121)] as $private) self::assertStringNotContainsString($private, $json);
        self::assertTrue($first['applications']['data'][0]['canOffer']);
        self::assertFalse($first['applications']['data'][0]['canAccept']);
        $this->posting(99, 2);
        try { $this->page(11, array_merge($query, ['posting' => $this->id(99)])); self::fail('Another organization’s posting must be rejected.'); }
        catch (HttpException $error) { self::assertSame(404, $error->getStatusCode()); }
    }

    public function test_additive_offer_migration_can_resume_and_preserves_old_applications(): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->table('work_applications', fn (Blueprint $t) => $t->dropColumn(['offered_at', 'offer_terms']));
        $migration = require database_path('migrations/2026_09_12_230000_work_offer_metadata.php');
        $migration->up(); $migration->up();
        self::assertTrue($schema->hasColumn('work_applications', 'org_contract_id'));
        self::assertSame('applied', $this->applicationRow()->status);
        self::assertNull($this->applicationRow()->offered_at);
    }

    public function test_bad_work_cursor_is_rejected_before_any_domain_query(): void
    {
        DB::connection()->enableQueryLog();
        try { $this->page(21, ['applications_cursor' => 'broken']); self::fail('Invalid cursor should fail.'); }
        catch (ValidationException $error) { self::assertArrayHasKey('applications_cursor', $error->errors()); }
        self::assertSame([], DB::getQueryLog());
    }

    public function test_person_without_a_wallet_never_reads_the_world_application_table(): void
    {
        DB::connection()->enableQueryLog();
        self::assertSame([], $this->page(99)['applications']['data']);
        foreach (DB::getQueryLog() as $query) self::assertStringNotContainsString('work_applications', $query['query']);
    }

    public function test_post_permission_failure_remains_http_403(): void
    {
        $request = Request::create('/economy/work/applications/'.$this->id(41).'/offer', 'POST', ['offer_terms' => 'Forged offer']);
        $request->setUserResolver(fn () => $this->user(12));
        $this->forbidden(fn () => $this->controller->offer($request, $this->id(41)));
        self::assertNull($this->applicationRow()->offered_at);
    }

    public function test_publishing_opens_the_new_posting_even_when_its_uuid_sorts_on_another_page(): void
    {
        $request = Request::create('/economy/work/organizations/'.$this->id(1).'/postings', 'POST', ['title' => 'New opportunity', 'terms' => 'Complete pay and schedule']);
        $request->setUserResolver(fn () => $this->user(11));
        $response = $this->controller->postJob($request, $this->id(1));
        $query = $this->follow($response->getTargetUrl());
        self::assertSame('hiring', $query['tab']);
        self::assertSame($this->id(1), $query['organization']);
        self::assertSame('New opportunity', $this->page(11, $query)['posting']['title']);
    }
}
