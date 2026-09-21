<?php

namespace Tests\Feature;

use App\Models\ClockTimer;
use App\Models\MatrixIdentity;
use App\Models\MatrixRoom;
use App\Models\User;
use App\Services\Notifications\AppNotificationService;
use App\Services\Notifications\WebPushService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;
use Tests\Concerns\DisposableRepairWorld;
use Tests\TestCase;

class AppNotificationsTest extends TestCase
{
    use DisposableRepairWorld;

    private User $user;

    private string $scope;

    private array $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openRepairWorld();
        $this->withoutVite();
        config(['cache.default' => 'array', 'queue.default' => 'sync', 'session.driver' => 'array', 'app.url' => 'https://world.test']);
        $this->user = User::factory()->create();
        $this->scope = (string) Str::uuid();
        DB::table('jurisdictions')->insert(['id' => $this->scope, 'name' => 'Test place', 'slug' => 'push-test-place', 'adm_level' => 0, 'population' => 100]);
        DB::table('residency_confirmations')->insert(['user_id' => $this->user->id, 'jurisdiction_id' => $this->scope, 'is_active' => true, 'days_confirmed' => 1, 'confirmed_at' => now()]);
        $this->subscription = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/'.Str::uuid(), 'keys' => [
            'p256dh' => VAPID::createVapidKeys()['publicKey'], 'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
        ]];
    }

    protected function tearDown(): void
    {
        $this->closeRepairWorld();
        parent::tearDown();
    }

    private function subscribe(): object
    {
        $id = $this->actingAs($this->user)->postJson('/app-notifications/subscriptions', $this->subscription)->assertOk()->json('id');
        // Ensure the Matrix event occurs AFTER opt-in even on timestamp(0) fixture columns.
        DB::table('web_push_subscriptions')->where('id', $id)->update(['created_at' => now()->subMinute()]);

        return DB::table('web_push_subscriptions')->find($id);
    }

    private function service(): AppNotificationService
    {
        return app(AppNotificationService::class);
    }

    private function room(): MatrixRoom
    {
        $space = (string) Str::uuid();
        DB::table('social_spaces')->insert(['id' => $space, 'jurisdiction_id' => $this->scope, 'space_type' => 'group', 'title' => 'Private test', 'is_private' => true, 'owner_user_id' => $this->user->id]);
        DB::table('social_memberships')->insert(['space_id' => $space, 'user_id' => $this->user->id]);

        return MatrixRoom::create(['matrix_room_id' => '!test:world.test', 'room_type' => 'user_private', 'entity_type' => 'social_space', 'entity_id' => $space, 'is_public' => false]);
    }

    private function event(MatrixRoom $room, string $type = 'm.room.message'): array
    {
        return ['type' => $type, 'event_id' => '$test', 'room_id' => $room->matrix_room_id, 'sender' => '@someone:world.test',
            'origin_server_ts' => now()->getTimestampMs(), 'content' => ['msgtype' => 'm.text', 'body' => 'Never put this secret in push previews']];
    }

    public function test_subscription_requires_auth_and_rejects_ssrf_endpoints(): void
    {
        $this->postJson('/app-notifications/subscriptions', $this->subscription)->assertUnauthorized();
        foreach (['http://fcm.googleapis.com/x', 'https://127.0.0.1/x', 'https://fcm.googleapis.com.evil.test/x', 'https://user@fcm.googleapis.com/x', 'https://fcm.googleapis.com:8443/x'] as $endpoint) {
            $this->actingAs($this->user)->postJson('/app-notifications/subscriptions', array_replace($this->subscription, ['endpoint' => $endpoint]))->assertUnprocessable();
        }
        $this->assertDatabaseCount('web_push_subscriptions', 0);
    }

    public function test_repeated_subscription_preserves_preferences_and_encrypts_capability(): void
    {
        $a = $this->subscribe();
        DB::table('web_push_subscriptions')->where('id', $a->id)->update(['messages' => false]);
        $b = $this->subscribe();
        self::assertSame($a->id, $b->id);
        self::assertFalse($b->messages);
        self::assertStringNotContainsString('https://', $b->subscription);
        self::assertSame($this->subscription, json_decode(Crypt::decryptString($b->subscription), true));
        $this->assertDatabaseCount('web_push_subscriptions', 1);
        $keys = app(WebPushService::class)->keys();
        self::assertSame($keys, app(WebPushService::class)->keys());
        self::assertNotSame($keys['privateKey'], DB::table('web_push_keys')->value('private_key'));
    }

    public function test_other_user_cannot_claim_change_remove_or_test_a_device(): void
    {
        $d = $this->subscribe();
        $this->actingAs(User::factory()->create());
        $this->postJson('/app-notifications/subscriptions', $this->subscription)->assertConflict();
        $this->putJson('/app-notifications/subscriptions/'.$d->id, ['messages' => true, 'invitations' => true, 'clocks' => false, 'remind_minutes' => 60])->assertNotFound();
        $this->postJson('/app-notifications/subscriptions/'.$d->id.'/test')->assertNotFound();
        $this->deleteJson('/app-notifications/subscriptions/'.$d->id)->assertNoContent();
        $this->assertDatabaseCount('web_push_subscriptions', 1);
    }

    public function test_clock_preferences_require_active_residency(): void
    {
        $d = $this->subscribe();
        $path = '/app-notifications/subscriptions/'.$d->id;
        $data = ['messages' => true, 'invitations' => true, 'clocks' => true, 'clock_jurisdiction_id' => (string) Str::uuid(), 'remind_minutes' => 60];
        $this->putJson($path, $data)->assertUnprocessable();
        $data['clock_jurisdiction_id'] = $this->scope;
        $this->putJson($path, $data)->assertOk();
        DB::table('residency_confirmations')->where('user_id', $this->user->id)->update(['is_active' => false]);
        $this->putJson($path, $data)->assertUnprocessable();
    }

    public function test_matrix_message_retries_deduplicate_and_delivery_rechecks_membership(): void
    {
        $d = $this->subscribe();
        $room = $this->room();
        $e = $this->event($room);
        $this->service()->matrixEvent($e);
        $this->service()->matrixEvent($e);
        $this->assertDatabaseCount('web_push_deliveries', 1);
        $delivery = DB::table('web_push_deliveries')->first();
        $payload = $this->service()->payload($delivery, $d);
        self::assertStringNotContainsString('secret', json_encode($payload));
        self::assertSame('/civic/rooms/'.$room->entity_id, $payload['url']);
        DB::table('social_memberships')->where('user_id', $this->user->id)->update(['deleted_at' => now()]);
        self::assertNull($this->service()->payload($delivery, $d));
    }

    public function test_private_messages_ignore_self_edits_old_events_public_rooms_and_opt_out(): void
    {
        $d = $this->subscribe();
        $room = $this->room();
        $e = $this->event($room);
        MatrixIdentity::create(['user_id' => $this->user->id, 'matrix_localpart' => 'someone', 'matrix_user_id' => '@someone:world.test']);
        $this->service()->matrixEvent($e);
        $e['sender'] = '@other:world.test';
        $e['origin_server_ts'] = now()->subDays(2)->getTimestampMs();
        $this->service()->matrixEvent($e);
        $e['origin_server_ts'] = now()->getTimestampMs();
        $e['content']['m.relates_to'] = ['rel_type' => 'm.replace'];
        $this->service()->matrixEvent($e);
        unset($e['content']['m.relates_to']);
        DB::table('web_push_subscriptions')->where('id', $d->id)->update(['messages' => false]);
        $this->service()->matrixEvent($e);
        DB::table('web_push_subscriptions')->where('id', $d->id)->update(['messages' => true]);
        $room->update(['room_type' => 'commons']);
        $this->service()->matrixEvent($e);
        $this->assertDatabaseCount('web_push_deliveries', 0);
    }

    public function test_matrix_invitation_targets_only_invited_account_and_link_acceptance_is_once(): void
    {
        $this->subscribe();
        $room = $this->room();
        MatrixIdentity::create(['user_id' => $this->user->id, 'matrix_localpart' => 'recipient', 'matrix_user_id' => '@recipient:world.test']);
        $e = $this->event($room, 'm.room.member');
        $e['state_key'] = '@other:world.test';
        $e['content'] = ['membership' => 'invite'];
        $this->service()->matrixEvent($e);
        $this->assertDatabaseCount('web_push_deliveries', 0);
        $e['state_key'] = '@recipient:world.test';
        $this->service()->matrixEvent($e);
        $this->service()->matrixEvent($e);
        $this->service()->invitationAccepted($this->user->id, 'invite1', 'another');
        $this->service()->invitationAccepted($this->user->id, 'invite1', 'another');
        $this->assertDatabaseCount('web_push_deliveries', 2);
    }

    public function test_due_reminder_is_deduplicated_and_cancelled_or_changed_timers_are_not_sent(): void
    {
        $d = $this->subscribe();
        DB::table('web_push_subscriptions')->where('id', $d->id)->update(['clocks' => true, 'clock_jurisdiction_id' => $this->scope]);
        $d = DB::table('web_push_subscriptions')->find($d->id);
        $timer = ClockTimer::create(['clock_id' => 'CLK-01', 'jurisdiction_id' => $this->scope, 'subject_type' => 'jurisdictions', 'subject_id' => $this->scope, 'armed_at' => now(), 'fires_at' => now()->addMinutes(30), 'state' => 'armed']);
        $this->service()->prepareClockReminders();
        $this->service()->prepareClockReminders();
        $this->assertDatabaseCount('web_push_deliveries', 1);
        $delivery = DB::table('web_push_deliveries')->first();
        self::assertNotNull($this->service()->payload($delivery, $d));
        $timer->update(['fires_at' => now()->addDays(2)]);
        self::assertNull($this->service()->payload($delivery, $d));
        $timer->update(['state' => 'cancelled']);
        $this->service()->prepareClockReminders();
        $this->assertDatabaseCount('web_push_deliveries', 1);
    }

    public function test_provider_failures_retry_then_expired_subscription_is_removed(): void
    {
        $d = $this->subscribe();
        $this->service()->enqueue($d, 'test', 'test1', []);
        $transport = $this->mock(WebPushService::class);
        $transport->shouldReceive('send')->once()->andReturn('retry');
        self::assertSame(0, $this->service()->deliverBatch($transport));
        $row = DB::table('web_push_deliveries')->first();
        self::assertNull($row->finished_at);
        self::assertSame(1, $row->attempts);
        $this->travel(2)->minutes();
        $transport->shouldReceive('send')->once()->andReturn('expired');
        $this->service()->deliverBatch($transport);
        $this->assertDatabaseCount('web_push_subscriptions', 0);
        $this->assertDatabaseCount('web_push_deliveries', 0);
    }

    public function test_accepted_send_is_not_repeated_and_opt_out_discards_queued_message(): void
    {
        $d = $this->subscribe();
        $this->service()->enqueue($d, 'test', 'test1', []);
        $transport = $this->mock(WebPushService::class);
        $transport->shouldReceive('send')->once()->andReturn('accepted');
        self::assertSame(1, $this->service()->deliverBatch($transport));
        self::assertSame(0, $this->service()->deliverBatch($transport));
        $room = $this->room();
        $this->service()->matrixEvent($this->event($room));
        DB::table('web_push_subscriptions')->where('id', $d->id)->update(['messages' => false]);
        self::assertSame(0, $this->service()->deliverBatch($transport));
        self::assertSame('skipped', DB::table('web_push_deliveries')->orderByDesc('id')->value('outcome'));
    }

    public function test_notification_outbox_rolls_back_with_an_invite_acceptance_transaction(): void
    {
        $this->subscribe();
        DB::beginTransaction();
        $this->service()->invitationAccepted($this->user->id, 'invite1', 'another');
        $this->assertDatabaseCount('web_push_deliveries', 1);
        DB::rollBack();
        $this->assertDatabaseCount('web_push_deliveries', 0);
    }

    public function test_settings_expose_public_key_and_choices_but_never_private_keys_or_endpoints(): void
    {
        $this->subscribe();
        $response = $this->get('/system/app')->assertOk();
        $response->assertDontSee($this->subscription['endpoint'])->assertDontSee(app(WebPushService::class)->keys()['privateKey']);
    }

    public function test_matrix_receiver_requires_homeserver_token_and_deduplicates_transactions(): void
    {
        $this->subscribe();
        $room = $this->room();
        config(['matrix.appservice.hs_token' => 'fixture-homeserver-token']);
        $this->putJson('/_matrix/app/v1/transactions/fixture-one', ['events' => [$this->event($room)]])->assertForbidden();
        $this->assertDatabaseCount('web_push_deliveries', 0);
        for ($i = 0; $i < 2; $i++) {
            $this->withHeader('Authorization', 'Bearer fixture-homeserver-token')
                ->putJson('/_matrix/app/v1/transactions/fixture-one', ['events' => [$this->event($room)]])->assertOk();
        }
        $this->assertDatabaseCount('web_push_deliveries', 1);
    }

    public function test_logout_disables_this_session_devices_and_keeps_other_sessions(): void
    {
        $d = $this->subscribe();
        $other = (array) $d;
        $other['id'] = (string) Str::uuid();
        $other['endpoint_hash'] = hash('sha256', 'other-device');
        $other['session_hash'] = hash('sha256', 'other-session');
        DB::table('web_push_subscriptions')->insert($other);
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());
        $this->post('/logout')->assertRedirect('/');
        self::assertFalse(DB::table('web_push_subscriptions')->where('id', $d->id)->exists());
        self::assertTrue(DB::table('web_push_subscriptions')->where('id', $other['id'])->exists());
    }

    public function test_real_push_transport_encrypts_and_signs_payload_and_does_not_follow_redirects(): void
    {
        $history = [];
        $mock = new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Psr7\Response(201), new \GuzzleHttp\Psr7\Response(302, ['Location' => 'http://127.0.0.1/private']),
            new \GuzzleHttp\Psr7\Response(410),
        ]);
        $stack = \GuzzleHttp\HandlerStack::create($mock);
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $transport = new class($stack) extends WebPushService
        {
            public function __construct(private $handler) {}

            protected function httpOptions(): array
            {
                return ['handler' => $this->handler];
            }
        };
        self::assertSame('accepted', $transport->send($this->subscription, ['body' => 'This payload must be encrypted']));
        self::assertSame('retry', $transport->send($this->subscription, ['body' => 'No redirect']));
        self::assertSame('expired', $transport->send($this->subscription, ['body' => 'Gone']));
        self::assertCount(3, $history);
        self::assertStringNotContainsString('This payload', (string) $history[0]['request']->getBody());
        self::assertSame('aes128gcm', $history[0]['request']->getHeaderLine('Content-Encoding'));
        self::assertStringStartsWith('vapid ', $history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame(5, $history[0]['options']['timeout']);
        self::assertFalse($history[1]['options']['allow_redirects']);
    }
}
