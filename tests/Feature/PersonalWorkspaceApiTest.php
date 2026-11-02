<?php

namespace Tests\Feature;

use App\Http\Middleware\RedirectIfSetupIncomplete;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use App\Support\ApiKeys;
use App\Support\SecretVault;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The personal workspace API: scoped per-user keys, owner-only data, the secret vault, and the
 * mail-send gate. Runs on the sqlite fixture connection with a minimal users table.
 */
class PersonalWorkspaceApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('users');
        Schema::create('users', function ($t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('status')->default('registered');
            $t->string('timezone')->default('UTC');
            $t->rememberToken();
            $t->timestampsTz();
            $t->softDeletesTz();
        });
        (require base_path('database/migrations/2026_11_02_120000_create_personal_workspace_tables.php'))->up();
        $this->withoutMiddleware([RedirectIfSetupIncomplete::class, ValidateCsrfToken::class]);
    }

    private function user(string $email = 'a@example.test'): User
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert(['id' => $id, 'name' => 'A', 'email' => $email, 'password' => Hash::make('pw-correct'), 'timezone' => 'UTC',
            'created_at' => now(), 'updated_at' => now()]);

        return User::query()->findOrFail($id);
    }

    private function headers(User $u, array $scopes): array
    {
        return ['Authorization' => 'Bearer '.ApiKeys::issue($u->id, 't', $scopes)['key'], 'Accept' => 'application/json'];
    }

    public function test_the_vault_round_trips_and_binds_a_secret_to_its_owner(): void
    {
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();
        $sealed = SecretVault::seal($a, 'hunter2-hunter2');

        $this->assertStringNotContainsString('hunter2', $sealed);
        $this->assertSame('hunter2-hunter2', SecretVault::open($a, $sealed));
        $this->assertNull(SecretVault::open($b, $sealed), 'another user must not open it');
        $this->assertNull(SecretVault::open($a, substr($sealed, 0, -3).'AAA'), 'tampering must fail');
    }

    public function test_a_key_needs_to_exist_carry_the_scope_and_stay_unrevoked(): void
    {
        $u = $this->user();
        $this->getJson('/api/v1/events')->assertStatus(401);
        $this->getJson('/api/v1/events', ['Authorization' => 'Bearer cga_'.str_repeat('x', 40)])->assertStatus(401);
        $this->getJson('/api/v1/events', $this->headers($u, ['tasks:read']))->assertStatus(403);

        $issued = ApiKeys::issue($u->id, 'n8n', ['calendar:read']);
        $h = ['Authorization' => 'Bearer '.$issued['key']];
        $this->getJson('/api/v1/events', $h)->assertOk();
        $this->assertSame(1, DB::table('api_keys')->where('key_hash', hash('sha256', $issued['key']))->count());
        $this->assertSame(0, DB::table('api_keys')->where('key_hash', $issued['key'])->count(), 'the plain key is never stored');
        DB::table('api_keys')->where('id', $issued['id'])->update(['revoked_at' => now()]);
        $this->getJson('/api/v1/events', $h)->assertStatus(401);
    }

    public function test_booking_refuses_overlap_and_slots_skip_busy_time(): void
    {
        $u = $this->user();
        $h = $this->headers($u, ['calendar:read', 'calendar:write']);
        $day = now()->addDays(2)->startOfDay();
        $this->putJson('/api/v1/availability', ['rules' => [['weekday' => $day->dayOfWeek, 'starts_at' => '09:00', 'ends_at' => '12:00']]], $h)->assertOk();

        $this->postJson('/api/v1/events', ['title' => 'Call', 'starts_at' => $day->copy()->setTime(9, 0)->toIso8601String(),
            'ends_at' => $day->copy()->setTime(10, 0)->toIso8601String()], $h)->assertCreated();
        $this->postJson('/api/v1/events', ['title' => 'Clash', 'starts_at' => $day->copy()->setTime(9, 30)->toIso8601String(),
            'ends_at' => $day->copy()->setTime(10, 30)->toIso8601String()], $h)->assertStatus(409);

        $slots = $this->getJson('/api/v1/slots?from='.$day->toDateString().'&to='.$day->copy()->addDay()->toDateString().'&minutes=60', $h)
            ->assertOk()->json('data');
        $this->assertSame([10, 11], array_map(fn ($s) => (int) substr($s['starts_at'], 11, 2), $slots));
    }

    public function test_one_user_never_sees_another_users_rows(): void
    {
        $a = $this->user('a@example.test');
        $b = $this->user('b@example.test');
        $ha = $this->headers($a, ['tasks:read', 'tasks:write', 'crm:read', 'crm:write']);
        $hb = $this->headers($b, ['tasks:read', 'tasks:write', 'crm:read', 'crm:write']);
        $taskId = $this->postJson('/api/v1/tasks', ['title' => 'Mine'], $ha)->assertCreated()->json('data.id');
        $contactId = $this->postJson('/api/v1/contacts', ['name' => 'Pat', 'phone' => '(555) 010-1234'], $ha)->assertCreated()->json('data.id');

        $this->getJson('/api/v1/tasks', $hb)->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/v1/tasks/$taskId", ['status' => 'done'], $hb)->assertStatus(404);
        $this->getJson("/api/v1/contacts/$contactId", $hb)->assertStatus(404);
        $this->getJson('/api/v1/contacts/identify?phone=5550101234', $hb)->assertOk()->assertJsonPath('known', false);
        $this->getJson('/api/v1/contacts/identify?phone=+1 555 010 1234', $ha)->assertOk()->assertJsonPath('known', true);
    }

    public function test_mail_is_never_sent_without_the_owner_approving_in_a_session(): void
    {
        $u = $this->user();
        $h = $this->headers($u, ['mail:read', 'mail:write', 'mail:send']);
        $id = $this->postJson('/api/v1/mail/drafts', ['to_addresses' => ['x@example.test'], 'subject' => 'Hi', 'body' => 'Hello'], $h)
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/mail/drafts/$id/request-send", [], $h)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->getJson('/api/v1/mail/approved', $h)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/mail/drafts/$id/sent", [], $h)->assertStatus(409);
        $this->postJson("/connections/drafts/$id/approve", [], $h)->assertStatus(401); // a key cannot approve

        $this->actingAs($u)->postJson("/connections/drafts/$id/approve")->assertNoContent();
        $this->getJson('/api/v1/mail/approved', $h)->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/mail/drafts/$id/sent", [], $h)->assertOk()->assertJsonPath('data.status', 'sent');
    }

    public function test_a_secret_shows_only_to_its_owner_after_the_password_is_retyped(): void
    {
        $u = $this->user();
        $other = $this->user('b@example.test');
        $this->actingAs($u)->postJson('/connections/secrets', ['name' => 'graph', 'provider' => 'microsoft', 'value' => 'client-secret-123456'])->assertCreated();
        $id = DB::table('user_secrets')->where('user_id', $u->id)->value('id');
        $this->assertStringNotContainsString('client-secret', DB::table('user_secrets')->where('id', $id)->value('ciphertext'));

        $this->postJson("/connections/secrets/$id/reveal", ['password' => 'wrong'])->assertStatus(422);
        $this->postJson("/connections/secrets/$id/reveal", ['password' => 'pw-correct'])->assertOk()->assertJsonPath('value', 'client-secret-123456');

        $this->actingAs($other)->postJson("/connections/secrets/$id/reveal", ['password' => 'pw-correct'])->assertStatus(404);
        $this->actingAs($other)->deleteJson("/connections/secrets/$id")->assertNoContent();
        $this->assertSame(1, DB::table('user_secrets')->where('id', $id)->count(), "another user's delete must not remove it");
    }
}
