<?php

namespace Tests\Unit;

use App\Http\Controllers\Economy\AssistanceController;
use App\Models\User;
use App\Services\Economy\AccountService;
use App\Services\Economy\AssistanceService;
use App\Services\Economy\LedgerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Independent simulated participants; all state is in explicit SQLite memory fixtures. */
final class AssistanceWorkflowTest extends TestCase
{
    private string $original;
    private AssistanceService $help;
    private AssistanceController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = DB::getDefaultConnection();
        config(['database.connections.assistance_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('assistance_fixture');
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        self::assertSame('sqlite', DB::connection()->getDriverName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('jurisdictions', function (Blueprint $t) { $t->uuid('id')->primary(); $t->uuid('parent_id')->nullable(); $t->softDeletes(); });
        $schema->create('currencies', function (Blueprint $t) { $t->uuid('id')->primary(); $t->uuid('jurisdiction_id'); $t->softDeletes(); });
        $schema->create('economic_accounts', function (Blueprint $t) { $t->uuid('id')->primary(); $t->uuid('currency_id'); $t->string('status'); $t->softDeletes(); });
        $schema->create('economic_account_bindings', function (Blueprint $t) { $t->uuid('account_id'); $t->string('owner_type'); $t->uuid('owner_id'); });
        $schema->create('assistance_requests', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->uuid('requester_account_id'); $t->uuid('responder_account_id')->nullable(); $t->uuid('jurisdiction_id')->nullable();
            $t->string('title'); $t->text('need'); $t->string('privacy'); $t->string('status'); $t->timestamps(); $t->softDeletes();
        });
        $migration = require base_path('database/migrations/2026_09_13_000010_add_assistance_responses.php');
        $migration->up();
        // Rerunning the additive migration does not duplicate objects or alter rows.
        $migration->up();
        DB::table('jurisdictions')->insert(['id' => $this->id(1)]);
        DB::table('currencies')->insert(['id' => $this->id(2), 'jurisdiction_id' => $this->id(1)]);
        foreach ([11, 12, 13, 14] as $user) {
            DB::table('economic_accounts')->insert(['id' => $this->id($user + 100), 'currency_id' => $this->id(2), 'status' => 'open']);
            DB::table('economic_account_bindings')->insert(['account_id' => $this->id($user + 100), 'owner_type' => 'users', 'owner_id' => $this->id($user)]);
        }
        $ledger = $this->createMock(LedgerService::class);
        $ledger->expects($this->never())->method('post');
        $this->help = new AssistanceService(new AccountService($ledger));
        $this->controller = new AssistanceController($this->help);
    }

    protected function tearDown(): void
    {
        DB::purge('assistance_fixture');
        DB::setDefaultConnection($this->original);
        parent::tearDown();
    }

    private function id(int $id): string { return sprintf('30000000-0000-4000-8000-%012d', $id); }
    private function user(int $id): User { return (new User())->forceFill(['id' => $this->id($id)]); }
    private function make(int $owner = 11, string $privacy = 'public'): string { return $this->help->create($this->user($owner), 'Help with a community garden', 'Two hours of voluntary help; no fee.', $privacy); }
    private function row(string $id): object { return DB::table('assistance_requests')->where('id', $id)->first(); }
    private function response(string $id): object { return DB::table('assistance_responses')->where('id', $id)->first(); }
    private function request(int $user, string $path, array $query = []): Request
    {
        $request = Request::create($path.'?'.http_build_query($query));
        $request->setUserResolver(fn () => $this->user($user));
        return $request;
    }
    private function props($response): array { return (new \ReflectionClass($response))->getProperty('props')->getValue($response); }
    private function page(int $user = 11, array $query = []): array { return $this->props($this->controller->index($this->request($user, '/economy/help', $query))); }
    private function detail(string $id, int $user = 11, array $query = []): array { return $this->props($this->controller->show($this->request($user, '/economy/help/'.$id, $query), $id)); }
    private function follow(string $url): array { parse_str(parse_url($url, PHP_URL_QUERY), $query); return $query; }
    private function denied(callable $call, int $status = 403): void
    {
        try { $call(); self::fail('Expected an access refusal.'); }
        catch (HttpException $error) { self::assertSame($status, $error->getStatusCode()); }
    }
    private function refused(callable $call): void
    {
        try { $call(); self::fail('Expected a workflow refusal.'); }
        catch (InvalidArgumentException $error) { self::assertNotEmpty($error->getMessage()); }
    }

    public function test_simulated_requester_and_two_helpers_complete_voluntary_help_without_money(): void
    {
        $id = $this->make(11, 'private');
        self::assertSame('private', $this->row($id)->privacy);
        self::assertSame($this->id(111), $this->row($id)->requester_account_id);
        self::assertTrue($this->detail($id)['canPublish']);
        $this->denied(fn () => $this->detail($id, 12), 404);
        $this->help->publish($this->user(11), $id);
        $first = $this->help->respond($this->user(12), $id, 'I can help on Saturday.');
        $second = $this->help->respond($this->user(13), $id, 'I can help on Sunday.');
        $this->help->match($this->user(11), $id, $first);
        self::assertSame('matched', $this->row($id)->status);
        self::assertSame($this->id(112), $this->row($id)->responder_account_id);
        self::assertSame('accepted', $this->response($first)->status);
        $this->refused(fn () => $this->help->match($this->user(11), $id, $second));
        $this->help->resolve($this->user(11), $id);
        self::assertSame('resolved', $this->row($id)->status);
        self::assertFalse($this->detail($id)['canResolve']);
        $this->refused(fn () => $this->help->withdrawResponse($this->user(12), $id, $first));
        $this->refused(fn () => $this->help->respond($this->user(14), $id, 'Late offer'));
    }

    public function test_selected_helper_withdrawal_reopens_without_changing_privacy(): void
    {
        $id = $this->make();
        $first = $this->help->respond($this->user(12), $id, 'First offer');
        $second = $this->help->respond($this->user(13), $id, 'Second offer');
        $this->help->match($this->user(11), $id, $first);
        // A private legacy match still belongs only to its recorded parties.
        DB::table('assistance_requests')->where('id', $id)->update(['privacy' => 'private']);
        $this->help->withdrawResponse($this->user(12), $id, $first);
        self::assertSame('open', $this->row($id)->status);
        self::assertNull($this->row($id)->responder_account_id);
        self::assertSame('private', $this->row($id)->privacy);
        self::assertSame('withdrawn', $this->response($first)->status);
        self::assertSame('offered', $this->response($second)->status);
        $this->denied(fn () => $this->detail($id, 12), 404);
        $this->help->publish($this->user(11), $id);
        $this->help->match($this->user(11), $id, $second);
        self::assertSame($this->id(113), $this->row($id)->responder_account_id);
    }

    public function test_authentication_wallet_ownership_and_request_binding_are_enforced(): void
    {
        $id = $this->make();
        $other = $this->make(12);
        $offer = $this->help->respond($this->user(13), $id, 'Private offer');
        $this->denied(fn () => $this->help->withdraw($this->user(12), $id));
        $this->denied(fn () => $this->help->resolve($this->user(12), $id));
        $this->denied(fn () => $this->help->match($this->user(12), $id, $offer));
        $this->denied(fn () => $this->help->withdrawResponse($this->user(12), $id, $offer));
        $this->denied(fn () => $this->help->match($this->user(12), $other, $offer), 404);
        $this->denied(fn () => $this->controller->index(Request::create('/economy/help')));
        $this->refused(fn () => $this->help->respond($this->user(11), $id, 'Self offer'));
        $this->refused(fn () => $this->make(99));
        self::assertFalse($this->page(99)['canParticipate']);
        DB::table('economic_accounts')->where('id', $this->id(114))->update(['status' => 'closed']);
        $this->refused(fn () => $this->help->respond($this->user(14), $id, 'Closed wallet'));
        self::assertSame('open', $this->row($id)->status);
    }

    public function test_responses_and_account_bindings_are_never_public_or_shared_between_helpers(): void
    {
        $id = $this->make();
        $first = $this->help->respond($this->user(12), $id, 'First confidential note');
        $second = $this->help->respond($this->user(13), $id, 'Second confidential note');
        self::assertCount(2, $this->detail($id)['responses']['data']);
        self::assertSame([$first], array_column($this->detail($id, 12)['responses']['data'], 'id'));
        self::assertSame([$second], array_column($this->detail($id, 13)['responses']['data'], 'id'));
        self::assertSame([], $this->detail($id, 14)['responses']['data']);
        foreach ([$this->detail($id), $this->detail($id, 12), $this->page(11, ['tab' => 'mine'])] as $props) {
            $json = json_encode($props);
            foreach (['account_id', 'owner_id', 'user_id', $this->id(111), $this->id(112), $this->id(113)] as $private) self::assertStringNotContainsString($private, $json);
        }
        self::assertStringNotContainsString('confidential', json_encode($this->page()));
    }

    public function test_private_and_jurisdiction_rows_are_only_visible_to_recorded_parties(): void
    {
        $private = $this->make(11, 'private');
        $legacy = $this->make();
        DB::table('assistance_requests')->where('id', $legacy)->update(['privacy' => 'jurisdiction', 'responder_account_id' => $this->id(112), 'status' => 'matched']);
        self::assertSame([], $this->page(13)['requests']['data']);
        $this->denied(fn () => $this->detail($legacy, 13), 404);
        self::assertSame($legacy, $this->detail($legacy, 12)['assistance']['id']);
        self::assertSame([$legacy], array_column($this->page(12, ['tab' => 'responding'])['requests']['data'], 'id'));
        self::assertCount(2, $this->page(11, ['tab' => 'mine'])['requests']['data']);
        $this->denied(fn () => $this->help->publish($this->user(12), $private), 404);
        $this->refused(fn () => $this->help->publish($this->user(11), $legacy));
    }

    public function test_withdrawn_requests_and_duplicate_offers_preserve_history(): void
    {
        $id = $this->make();
        $offer = $this->help->respond($this->user(12), $id, 'Recorded offer');
        $this->help->withdrawResponse($this->user(12), $id, $offer);
        $this->refused(fn () => $this->help->respond($this->user(12), $id, 'Duplicate offer'));
        self::assertSame(1, DB::table('assistance_responses')->count());
        $this->help->withdraw($this->user(11), $id);
        self::assertSame('withdrawn', $this->row($id)->status);
        self::assertSame('Recorded offer', $this->response($offer)->message);
        self::assertSame([], $this->page()['requests']['data']);
        self::assertSame([$id], array_column($this->page(11, ['tab' => 'mine'])['requests']['data'], 'id'));
        $this->refused(fn () => $this->help->resolve($this->user(11), $id));
        $this->refused(fn () => $this->help->respond($this->user(13), $id, 'Late'));
    }

    public function test_request_pages_are_bounded_and_return_each_row_once_with_reverse_navigation(): void
    {
        for ($i = 0; $i < 25; $i++) $this->make();
        $this->make(12, 'private');
        DB::connection()->enableQueryLog();
        $first = $this->page();
        $second = $this->page(11, $this->follow($first['requests']['next']));
        self::assertCount(20, $first['requests']['data']);
        self::assertCount(5, $second['requests']['data']);
        self::assertCount(25, array_unique(array_merge(array_column($first['requests']['data'], 'id'), array_column($second['requests']['data'], 'id'))));
        self::assertSame($first['requests']['data'], $this->page(11, $this->follow($second['requests']['previous']))['requests']['data']);
        foreach (DB::connection()->getQueryLog() as $query) {
            if (str_contains($query['query'], 'from "assistance_requests"')) self::assertStringContainsString('limit 21', $query['query']);
        }
        DB::connection()->disableQueryLog();
    }

    public function test_response_pages_are_bounded_and_request_scoped(): void
    {
        $id = $this->make();
        $other = $this->make(12);
        for ($i = 0; $i < 25; $i++) {
            DB::table('assistance_responses')->insert(['id' => $this->id(500 + $i), 'request_id' => $id, 'responder_account_id' => $this->id(800 + $i), 'message' => 'Offer '.$i, 'status' => 'offered', 'created_at' => now()]);
        }
        $this->help->respond($this->user(13), $other, 'Other request secret');
        $first = $this->detail($id);
        $second = $this->detail($id, 11, $this->follow($first['responses']['next']));
        self::assertCount(20, $first['responses']['data']);
        self::assertCount(5, $second['responses']['data']);
        self::assertSame($first['responses']['data'], $this->detail($id, 11, $this->follow($second['responses']['previous']))['responses']['data']);
        self::assertStringNotContainsString('Other request secret', json_encode([$first, $second]));
    }

    public function test_create_and_reply_forms_bind_the_authenticated_actor_and_redirect_to_the_record(): void
    {
        $request = Request::create('/economy/help', 'POST', [
            'title' => 'Testing help', 'need' => 'A voluntary task', 'privacy' => 'public',
            'requester_account_id' => $this->id(112),
        ]);
        $request->setUserResolver(fn () => $this->user(11));
        $created = $this->controller->store($request);
        $id = basename(parse_url($created->getTargetUrl(), PHP_URL_PATH));
        self::assertSame($this->id(111), $this->row($id)->requester_account_id);
        $reply = Request::create('/economy/help/'.$id.'/responses', 'POST', ['message' => 'I can help', 'responder_account_id' => $this->id(113)]);
        $reply->setUserResolver(fn () => $this->user(12));
        $sent = $this->controller->respond($reply, $id);
        self::assertStringEndsWith('/economy/help/'.$id, $sent->getTargetUrl());
        self::assertSame($this->id(112), DB::table('assistance_responses')->where('request_id', $id)->value('responder_account_id'));
        $reply->merge(['message' => str_repeat('x', 5001)]);
        try { $this->controller->respond($reply, $id); self::fail('An overlong reply must be rejected.'); }
        catch (ValidationException $error) { self::assertArrayHasKey('message', $error->errors()); }
    }

    public function test_my_offers_directory_keeps_candidates_and_privacy_scoped_through_pagination(): void
    {
        $ids = [];
        for ($i = 0; $i < 25; $i++) {
            $id = $this->make(); $ids[] = $id;
            $this->help->respond($this->user(12), $id, 'My private offer');
        }
        $hidden = $this->make();
        $this->help->respond($this->user(12), $hidden, 'Former public offer');
        DB::table('assistance_requests')->where('id', $hidden)->update(['privacy' => 'private']);
        $this->make(13);
        $first = $this->page(12, ['tab' => 'responding']);
        $second = $this->page(12, $this->follow($first['requests']['next']));
        self::assertCount(20, $first['requests']['data']);
        self::assertCount(5, $second['requests']['data']);
        $actual = array_merge(array_column($first['requests']['data'], 'id'), array_column($second['requests']['data'], 'id'));
        self::assertEqualsCanonicalizing($ids, $actual);
        self::assertSame($first['requests']['data'], $this->page(12, $this->follow($second['requests']['previous']))['requests']['data']);
    }

    public function test_no_wallet_does_not_read_personal_request_directories(): void
    {
        DB::enableQueryLog(); DB::flushQueryLog();
        foreach (['mine', 'responding'] as $tab) self::assertSame([], $this->page(99, ['tab' => $tab])['requests']['data']);
        foreach (DB::getQueryLog() as $query) self::assertDoesNotMatchRegularExpression('/assistance_(requests|responses)/', $query['query']);
    }

    public function test_withdrawing_a_private_match_redirects_to_an_accessible_directory(): void
    {
        $id = $this->make();
        $response = $this->help->respond($this->user(12), $id, 'I can help');
        $this->help->match($this->user(11), $id, $response);
        DB::table('assistance_requests')->where('id', $id)->update(['privacy' => 'private']);
        $result = $this->controller->withdrawResponse($this->request(12, '/economy/help/'.$id), $id, $response);
        self::assertStringEndsWith('/economy/help?tab=responding', $result->getTargetUrl());
        self::assertSame([], $this->page(12, ['tab' => 'responding'])['requests']['data']);
        $this->denied(fn () => $this->detail($id, 12), 404);
    }

    public function test_invalid_cursor_and_input_shapes_are_rejected_before_directory_reads(): void
    {
        foreach ([['cursor' => 'not-a-page'], ['cursor' => ['bad']], ['tab' => 'private'], ['tab' => ['mine']]] as $query) {
            DB::connection()->flushQueryLog(); DB::connection()->enableQueryLog();
            try { $this->page(11, $query); self::fail('Expected invalid input refusal.'); }
            catch (ValidationException $error) { self::assertNotEmpty($error->errors()); }
            foreach (DB::connection()->getQueryLog() as $entry) self::assertStringNotContainsString('assistance_requests', $entry['query']);
            DB::connection()->disableQueryLog();
        }
    }
}
