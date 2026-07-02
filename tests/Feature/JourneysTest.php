<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RoleService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * mockups-v3-wiring Phase 3c — the journeys engine: durable per-user step
 * completion (journey_progress), the append-only achievements ledger, and
 * the /civic/record achievements prop.
 *
 * The MyProfileTabsTest posture: DB-backed paths run on the guarded live-pg
 * connection (never RefreshDatabase — the live dev DB is not disposable);
 * everything inside one rolled-back transaction; SKIPS when pg is
 * unreachable — run inside the app container.
 */
class JourneysTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_journeys';

    /** A live 5-step journey (config/cga/journeys.php). */
    private const LIVE_JOURNEY = 'form-a-group';

    /** A planned journey — step marking must reject. */
    private const PLANNED_JOURNEY = 'budget';

    public function test_the_index_renders_all_thirteen_journeys(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('Journey Browser');

            $this->actingAs($user)
                ->get('/journeys')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Civic/Journeys')
                    ->has('journeys', 13)
                    ->where('journeys.0.steps_done', 0)
                    ->where('journeys.0.completed', false));
        });
    }

    public function test_completing_every_step_earns_exactly_one_achievement_idempotently(): void
    {
        $this->onLivePg(function () {
            $user  = $this->aUser('Journey Finisher');
            $steps = count(config('cga.journeys.' . self::LIVE_JOURNEY . '.steps'));

            foreach (range(0, $steps - 1) as $step) {
                $this->actingAs($user)
                    ->post('/journeys/' . self::LIVE_JOURNEY . '/steps', ['step' => $step])
                    ->assertRedirect();
            }

            $progress = DB::table('journey_progress')
                ->where('user_id', (string) $user->id)
                ->where('journey_id', self::LIVE_JOURNEY)
                ->first();

            $this->assertNotNull($progress, 'a progress row exists');
            $this->assertNotNull($progress->completed_at, 'the journey completed');
            $this->assertCount($steps, json_decode($progress->steps_done, true));

            $medals = fn () => DB::table('achievements')
                ->where('user_id', (string) $user->id)
                ->where('journey_id', self::LIVE_JOURNEY)
                ->count();

            $this->assertSame(1, $medals(), 'exactly ONE achievement row');

            // Idempotent: re-posting the final step never mints a second medal.
            $this->actingAs($user)
                ->post('/journeys/' . self::LIVE_JOURNEY . '/steps', ['step' => $steps - 1])
                ->assertRedirect();

            $this->assertSame(1, $medals(), 'still exactly one after the repeat POST');

            // The earn is sealed to the audit chain + denormalizes the title.
            $medal = DB::table('achievements')
                ->where('user_id', (string) $user->id)
                ->where('journey_id', self::LIVE_JOURNEY)
                ->first();
            $this->assertSame(config('cga.journeys.' . self::LIVE_JOURNEY . '.title'), $medal->title);
            $this->assertNotNull($medal->audit_seq, 'sealed to the audit chain');
        });
    }

    public function test_unmarking_after_completion_is_rejected_and_steps_stay_frozen(): void
    {
        $this->onLivePg(function () {
            $user  = $this->aUser('Frozen Finisher');
            $steps = count(config('cga.journeys.' . self::LIVE_JOURNEY . '.steps'));

            foreach (range(0, $steps - 1) as $step) {
                $this->actingAs($user)
                    ->post('/journeys/' . self::LIVE_JOURNEY . '/steps', ['step' => $step]);
            }

            // Completion is a ledger event — undo is rejected (422).
            $this->actingAs($user)
                ->deleteJson('/journeys/' . self::LIVE_JOURNEY . '/steps', ['step' => 0])
                ->assertStatus(422);

            $progress = DB::table('journey_progress')
                ->where('user_id', (string) $user->id)
                ->where('journey_id', self::LIVE_JOURNEY)
                ->first();

            $this->assertNotNull($progress->completed_at, 'still completed');
            $this->assertCount($steps, json_decode($progress->steps_done, true), 'steps frozen');
        });
    }

    public function test_unmarking_before_completion_works(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('Undoing Learner');

            $this->actingAs($user)
                ->post('/journeys/' . self::LIVE_JOURNEY . '/steps', ['step' => 0])
                ->assertRedirect();
            $this->actingAs($user)
                ->deleteJson('/journeys/' . self::LIVE_JOURNEY . '/steps', ['step' => 0])
                ->assertRedirect();

            $progress = DB::table('journey_progress')
                ->where('user_id', (string) $user->id)
                ->where('journey_id', self::LIVE_JOURNEY)
                ->first();

            $this->assertSame([], json_decode($progress->steps_done, true));
            $this->assertNull($progress->completed_at);
        });
    }

    public function test_a_planned_journey_rejects_step_marking(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('Too Early');

            $this->actingAs($user)
                ->postJson('/journeys/' . self::PLANNED_JOURNEY . '/steps', ['step' => 0])
                ->assertStatus(422);

            // An unknown journey 404s on show and 422s on marking.
            $this->actingAs($user)->get('/journeys/not-a-journey')->assertNotFound();
            $this->actingAs($user)
                ->postJson('/journeys/not-a-journey/steps', ['step' => 0])
                ->assertStatus(422);

            // A step off the arc rejects too.
            $this->actingAs($user)
                ->postJson('/journeys/' . self::LIVE_JOURNEY . '/steps', ['step' => 99])
                ->assertStatus(422);
        });
    }

    public function test_achievements_appear_on_the_profile(): void
    {
        $this->onLivePg(function () {
            $user  = $this->aUser('Decorated Player');
            $steps = count(config('cga.journeys.' . self::LIVE_JOURNEY . '.steps'));

            foreach (range(0, $steps - 1) as $step) {
                $this->actingAs($user)
                    ->post('/journeys/' . self::LIVE_JOURNEY . '/steps', ['step' => $step]);
            }

            $this->actingAs($user)
                ->get('/civic/record?tab=achievements')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Civic/MyRecord')
                    ->where('tab', 'achievements')
                    ->has('achievements', 1)
                    ->where('achievements.0.journey_id', self::LIVE_JOURNEY)
                    ->where(
                        'achievements.0.title',
                        config('cga.journeys.' . self::LIVE_JOURNEY . '.title'),
                    ));
        });
    }

    public function test_the_append_only_trigger_blocks_update_on_achievements(): void
    {
        $this->onLivePg(function () {
            $user = $this->aUser('Ledger Tamperer');

            DB::table('achievements')->insert([
                'user_id'    => (string) $user->id,
                'journey_id' => 'election',
                'title'      => 'An election, end to end',
                'earned_at'  => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // The BEFORE UPDATE trigger raises — the ledger cannot be edited.
            // (This aborts the surrounding transaction; keep it the LAST
            // statement — onLivePg's finally rolls the whole thing back.)
            $caught = null;
            try {
                DB::update(
                    'UPDATE achievements SET title = ? WHERE user_id = ?',
                    ['A forged medal', (string) $user->id],
                );
            } catch (QueryException $e) {
                $caught = $e;
            }

            $this->assertInstanceOf(QueryException::class, $caught, 'UPDATE must be blocked');
            $this->assertStringContainsString('append-only', $caught->getMessage());
        });
    }

    // ── helpers (the MyProfileTabsTest live-pg posture) ──────────────────────

    private function aUser(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => 'journeys-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }

    private function onLivePg(callable $body): void
    {
        // This suite exercises the route handlers, not the CSRF layer
        // (the SupportReportTest posture for JSON/without-token POSTs).
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        app(RoleService::class)->flush();
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
            app(RoleService::class)->flush();
        }
    }
}
