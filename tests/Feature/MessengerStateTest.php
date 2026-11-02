<?php

namespace Tests\Feature;

use App\Http\Middleware\RedirectIfSetupIncomplete;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The floating messenger's saved state: what is open, where the person stopped reading, the call
 * descriptor. Sqlite fixture with a minimal users table; message reads need Matrix and are covered
 * by the live smoke on the box.
 */
class MessengerStateTest extends TestCase
{
    private const A = 'private:aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    private const B = 'commons:bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

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
        (require base_path('database/migrations/2026_11_02_130000_create_dock_states_table.php'))->up();
        $this->withoutMiddleware([RedirectIfSetupIncomplete::class, ValidateCsrfToken::class]);
    }

    private function user(string $email): User
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert(['id' => $id, 'name' => 'A', 'email' => $email, 'password' => Hash::make('x'), 'created_at' => now(), 'updated_at' => now()]);

        return User::query()->findOrFail($id);
    }

    public function test_the_state_needs_a_signed_in_person(): void
    {
        $this->getJson('/messenger/state')->assertStatus(401);
        $this->putJson('/messenger/state', ['open' => []])->assertStatus(401);
    }

    public function test_a_new_person_starts_empty_with_a_baseline_that_does_not_move(): void
    {
        $u = $this->user('a@example.test');
        $first = $this->actingAs($u)->getJson('/messenger/state')->assertOk()->json();
        $this->assertSame([], $first['state']['open']);
        $this->assertNull($first['state']['active']);
        $this->assertGreaterThan(0, $first['baseline_ms']);

        $this->actingAs($u)->putJson('/messenger/state', ['open' => [self::A], 'active' => self::A])->assertOk();
        $second = $this->actingAs($u)->getJson('/messenger/state')->assertOk()->json();
        $this->assertSame($first['baseline_ms'], $second['baseline_ms'], 'saving must not reset the unread baseline');
        $this->assertSame([self::A], $second['state']['open']);
        $this->assertSame(self::A, $second['state']['active']);
    }

    public function test_a_read_mark_only_moves_forward(): void
    {
        $u = $this->user('a@example.test');
        $this->actingAs($u)->putJson('/messenger/state', ['reads' => [self::A => 2000]])->assertOk();
        $this->actingAs($u)->putJson('/messenger/state', ['reads' => [self::A => 1000, self::B => 500]])->assertOk();
        $reads = $this->actingAs($u)->getJson('/messenger/state')->json('state.reads');
        $this->assertSame(2000, $reads[self::A], 'a stale tab cannot un-read a message');
        $this->assertSame(500, $reads[self::B]);
    }

    public function test_bad_keys_and_oversized_documents_are_refused(): void
    {
        $u = $this->user('a@example.test');
        $this->actingAs($u)->putJson('/messenger/state', ['open' => ['private:not-a-uuid']])->assertStatus(422);
        $this->actingAs($u)->putJson('/messenger/state', ['open' => ['dm:aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa']])->assertStatus(422);
        $many = array_map(fn () => 'private:'.Str::uuid(), range(1, 9));
        $this->actingAs($u)->putJson('/messenger/state', ['open' => $many])->assertStatus(422);
        $this->actingAs($u)->getJson('/messenger/conversations/not-a-key/messages')->assertStatus(404);
    }

    public function test_the_call_descriptor_is_kept_and_cleared_per_person(): void
    {
        $a = $this->user('a@example.test');
        $b = $this->user('b@example.test');
        $call = ['descriptor' => ['mode' => 'private', 'room' => '!r:x', 'title' => 'Friends', 'href' => '/civic/rooms/1'], 'beat' => 1234];
        $this->actingAs($a)->putJson('/messenger/state', ['call' => $call])->assertOk();
        $this->assertSame('!r:x', $this->actingAs($a)->getJson('/messenger/state')->json('state.call.descriptor.room'));
        $this->assertNull($this->actingAs($b)->getJson('/messenger/state')->json('state.call'), "another person's state is separate");
        $this->actingAs($a)->putJson('/messenger/state', ['call' => null])->assertOk();
        $this->assertNull($this->actingAs($a)->getJson('/messenger/state')->json('state.call'));
    }
}
