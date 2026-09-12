<?php

namespace Tests\Unit;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\User;
use App\Services\Matrix\MatrixClientService;
use App\Services\Matrix\MatrixPostingGateService;
use App\Services\RoleService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/** HTTP is faked. No database, real registration, membership, or message writes. */
final class MatrixClientSendTest extends TestCase
{
    private const USER = '@u-public-handle:matrix.test';
    private const ROOM = '!private/id:matrix.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['matrix.server_name' => 'matrix.test', 'matrix.synapse_url' => 'http://matrix.test', 'matrix.appservice.as_token' => 'test-only-appservice-token']);
        Http::preventStrayRequests();
    }

    public function test_fresh_virtual_user_registers_and_joins_before_the_message_is_sent(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['user_id' => self::USER])
            ->push(['room_id' => self::ROOM])
            ->push(['event_id' => '$sent'])]);
        $content = ['msgtype' => 'm.text', 'body' => 'A public contribution', 'cga.acting_seat' => 'legislature_member'];
        self::assertSame(['event_id' => '$sent'], app(MatrixClientService::class)->sendMessage(self::ROOM, $content, self::USER));
        $sent = $this->requests();
        self::assertCount(3, $sent);
        $this->assertRequest($sent[0], 'POST', '/_matrix/client/v3/register');
        self::assertSame(['type' => 'm.login.application_service', 'username' => 'u-public-handle', 'inhibit_login' => true], $sent[0]->data());
        self::assertArrayNotHasKey('password', $sent[0]->data());
        $this->assertRequest($sent[1], 'POST', $this->roomPath().'/join', self::USER);
        self::assertSame('{}', $sent[1]->body(), 'The Matrix join body must be a JSON object.');
        self::assertSame($content, $sent[2]->data());
        self::assertSame('PUT', $sent[2]->method());
        self::assertStringContainsString($this->roomPath().'/send/m.room.message/', $sent[2]->url());
        self::assertSame(['user_id' => self::USER], $this->query($sent[2]));
    }

    public function test_existing_user_and_existing_membership_are_idempotent_on_repeated_sends(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['errcode' => 'M_USER_IN_USE'], 400)->push(['room_id' => self::ROOM])->push(['event_id' => '$one'])
            ->push(['errcode' => 'M_USER_IN_USE'], 400)->push(['room_id' => self::ROOM])->push(['event_id' => '$two'])]);
        $client = app(MatrixClientService::class);
        self::assertSame('$one', $client->sendMessage(self::ROOM, ['body' => 'One'], self::USER)['event_id']);
        self::assertSame('$two', $client->sendMessage(self::ROOM, ['body' => 'Two'], self::USER)['event_id']);
        $sent = $this->requests();
        self::assertCount(6, $sent);
        self::assertSame($sent[0]->data(), $sent[3]->data());
        self::assertSame($sent[1]->url(), $sent[4]->url());
        self::assertNotSame($sent[2]->url(), $sent[5]->url(), 'Separate messages have separate transaction IDs.');
        foreach ($sent as $request) self::assertStringNotContainsString('/invite', $request->url());
    }

    public function test_invite_only_room_is_invited_by_the_appservice_then_joined_as_the_authorized_user(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['errcode' => 'M_USER_IN_USE'], 400)
            ->push(['errcode' => 'M_FORBIDDEN'], 403)
            ->push(['join_rule' => 'invite'])
            ->push([])
            ->push(['room_id' => self::ROOM])
            ->push(['event_id' => '$private'])]);
        self::assertSame('$private', app(MatrixClientService::class)->sendMessage(self::ROOM, ['body' => 'Private'], self::USER)['event_id']);
        $sent = $this->requests();
        self::assertCount(6, $sent);
        $this->assertRequest($sent[2], 'GET', $this->roomPath().'/state/m.room.join_rules');
        $this->assertRequest($sent[3], 'POST', $this->roomPath().'/invite');
        self::assertSame(['user_id' => self::USER], $sent[3]->data());
        $this->assertRequest($sent[4], 'POST', $this->roomPath().'/join', self::USER);
        self::assertSame(['user_id' => self::USER], $this->query($sent[5]));
        foreach ($sent as $request) {
            self::assertStringNotContainsString('power_levels', $request->url());
            self::assertStringNotContainsString('/unban', $request->url());
            self::assertFalse($request->method() === 'PUT' && str_contains($request->url(), '/state/'));
        }
    }

    public function test_concurrent_join_can_complete_when_its_second_invitation_is_already_forbidden(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['errcode' => 'M_USER_IN_USE'], 400)
            ->push(['errcode' => 'M_FORBIDDEN'], 403)
            ->push(['join_rule' => 'invite'])
            ->push(['errcode' => 'M_FORBIDDEN'], 403)
            ->push(['room_id' => self::ROOM])
            ->push(['event_id' => '$concurrent'])]);
        self::assertSame('$concurrent', app(MatrixClientService::class)->sendMessage(self::ROOM, ['body' => 'One'], self::USER)['event_id']);
        self::assertCount(6, $this->requests());
    }

    public function test_registration_errors_do_not_attempt_membership_or_send(): void
    {
        Http::fake(['*' => Http::response(['errcode' => 'M_UNKNOWN_TOKEN'], 401)]);
        $this->assertSendFails(401);
        self::assertCount(1, $this->requests());
        $this->assertRequest($this->requests()[0], 'POST', '/_matrix/client/v3/register');
    }

    public function test_join_server_errors_are_not_retried_or_treated_as_missing_invitations(): void
    {
        Http::fake(['*' => Http::sequence()->push(['user_id' => self::USER])->push(['errcode' => 'M_UNKNOWN'], 503)]);
        $this->assertSendFails(503);
        self::assertCount(2, $this->requests());
    }

    public function test_public_room_denials_never_trigger_an_invitation_or_privilege_change(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['user_id' => self::USER])->push(['errcode' => 'M_FORBIDDEN'], 403)->push(['join_rule' => 'public'])]);
        $this->assertSendFails(403);
        self::assertCount(3, $this->requests());
        foreach ($this->requests() as $request) self::assertNotSame('PUT', $request->method());
    }

    public function test_private_room_denial_after_invitation_stops_before_sending(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(['user_id' => self::USER])->push(['errcode' => 'M_FORBIDDEN'], 403)->push(['join_rule' => 'invite'])
            ->push(['errcode' => 'M_FORBIDDEN'], 403)->push(['errcode' => 'M_FORBIDDEN'], 403)]);
        $this->assertSendFails(403);
        self::assertCount(5, $this->requests());
        foreach ($this->requests() as $request) self::assertNotSame('PUT', $request->method());
    }

    public function test_nonlocal_and_out_of_namespace_senders_are_rejected_without_http(): void
    {
        Http::fake();
        foreach (['@u-person:another.test', '@administrator:matrix.test', 'u-person:matrix.test', '@u-person:matrix.test.evil'] as $user) {
            try {
                app(MatrixClientService::class)->sendMessage(self::ROOM, ['body' => 'Denied'], $user);
                self::fail('Expected a nonlocal or unowned Matrix identity to be refused.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('local application-service user', $e->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_appservice_sender_and_timeline_reads_do_not_provision_users(): void
    {
        Http::fake(['*' => Http::sequence()->push(['event_id' => '$system'])->push(['chunk' => []])]);
        $client = app(MatrixClientService::class);
        self::assertSame('$system', $client->sendMessage(self::ROOM, ['body' => 'System'])['event_id']);
        self::assertSame(['chunk' => []], $client->getMessages(self::ROOM));
        $sent = $this->requests();
        self::assertCount(2, $sent);
        self::assertSame('PUT', $sent[0]->method());
        self::assertSame([], $this->query($sent[0]));
        self::assertSame('GET', $sent[1]->method());
    }

    public function test_existing_application_gates_deny_before_virtual_user_setup(): void
    {
        Http::fake();
        foreach (['assertMayAccessCommons', 'assertMayAccessPrivateRoom'] as $method) {
            $gate = Mockery::mock(MatrixPostingGateService::class, [Mockery::mock(RoleService::class), app(MatrixClientService::class)])->makePartial();
            $gate->shouldReceive($method)->once()->andThrow(new ConstitutionalViolation('Not authorized for this room.', 'Art. I'));
            try {
                if ($method === 'assertMayAccessCommons') $gate->post(new User, 'selected-place', self::ROOM, 'Denied');
                else $gate->postToPrivateRoom(new User, self::ROOM, 'Denied');
                self::fail('Expected the application gate to deny access.');
            } catch (ConstitutionalViolation $e) {
                self::assertSame('Not authorized for this room.', $e->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    private function assertSendFails(int $status): void
    {
        try {
            app(MatrixClientService::class)->sendMessage(self::ROOM, ['body' => 'Must not be sent'], self::USER);
            self::fail('Expected Matrix to refuse the operation.');
        } catch (RequestException $e) {
            self::assertSame($status, $e->response->status());
        }
    }

    private function requests(): array { return Http::recorded()->map(fn ($pair) => $pair[0])->all(); }
    private function roomPath(): string { return '/_matrix/client/v3/rooms/'.rawurlencode(self::ROOM); }
    private function query($request): array { parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query); return $query; }
    private function assertRequest($request, string $method, string $path, ?string $user = null): void
    {
        self::assertSame($method, $request->method());
        self::assertSame($path, parse_url($request->url(), PHP_URL_PATH));
        self::assertSame('matrix.test', parse_url($request->url(), PHP_URL_HOST));
        self::assertSame($user === null ? [] : ['user_id' => $user], $this->query($request));
        self::assertTrue($request->hasHeader('Authorization', 'Bearer test-only-appservice-token'));
    }
}
