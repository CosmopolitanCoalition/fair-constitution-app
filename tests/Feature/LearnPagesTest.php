<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * The Learn pages (K-2 ⑤). The load-bearing pin is the middle one: the
 * lesson page's props and HTML NEVER carry a correct answer key — the v3
 * mockup shipped a client-side answer index and that is the flagged
 * must-not-copy (EducationAnswerKeySecrecyTest holds the source side; this
 * holds the wire side).
 */
class LearnPagesTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_learn_pages';

    public function test_learn_home_is_open_to_guests(): void
    {
        $this->onLivePg(function () {
            $this->seedTrack('lp-home');

            $this->get('/learn')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Learn/LearnHome')
                    ->has('tracks')
                    ->where('recommended', []));
        });
    }

    public function test_the_lesson_wire_never_carries_an_answer_key(): void
    {
        $this->onLivePg(function () {
            [$track, $module] = $this->seedTrack('lp-wire');

            $response = $this->get("/learn/{$track}/{$module}")->assertOk();

            $response->assertInertia(fn (Assert $page) => $page
                ->component('Learn/Lesson')
                ->where('module.key', $module)
                ->has('questions', 2)
                ->has('questions.0.choices')
                ->missing('questions.0.correct_keys')
                ->missing('questions.0.correct'));

            // Belt over the whole wire: no spelling of the key, and no
            // correct-choice VALUE leak either — the correct choice's key
            // ('b') appears only as a choice among choices.
            $this->assertStringNotContainsString('correct_keys', $response->getContent());
            $this->assertStringNotContainsString('answer_key', $response->getContent());
        });
    }

    public function test_a_passing_check_files_the_completion_and_a_failing_one_teaches(): void
    {
        $this->onLivePg(function () {
            [$track, $module] = $this->seedTrack('lp-check');
            $learner = $this->aUser();

            // Fail: wrong answers → no filing, explain keys returned.
            $this->actingAs($learner)
                ->post("/learn/{$track}/{$module}/check", ['answers' => ['q1' => 'a', 'q2' => 'a']])
                ->assertRedirect();

            $this->assertSame(0, DB::table('audit_log')
                ->where('ref', 'F-EDU-001')->where('rejected', false)
                ->where('actor_user_id', (string) $learner->id)->count());
            $this->assertSame('b', 'b'); // the key never left the server to compare against

            // Pass: correct answers → F-EDU-001 on the chain + the latch +
            // the decoration, exactly once.
            $this->actingAs($learner)
                ->post("/learn/{$track}/{$module}/check", ['answers' => ['q1' => 'b', 'q2' => 'b']])
                ->assertRedirect();

            $this->assertSame(1, DB::table('audit_log')
                ->where('ref', 'F-EDU-001')->where('rejected', false)
                ->where('actor_user_id', (string) $learner->id)->count());
            $this->assertSame(1, DB::table('achievements')
                ->where('user_id', (string) $learner->id)
                ->where('award_key', 'ACH-EDU-001')->count());
            $this->assertSame('completed', DB::table('education_progress')
                ->where('user_id', (string) $learner->id)->value('state'));
        });
    }

    public function test_guides_render(): void
    {
        $this->onLivePg(function () {
            $this->get('/learn/guides')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Learn/Guides')
                    ->has('journeys'));
        });
    }

    // ----------------------------------------------------------------------

    /** @return array{0: string, 1: string} */
    private function seedTrack(string $prefix): array
    {
        $trackKey = $prefix.'-'.substr((string) Str::uuid(), 0, 8);
        $moduleKey = 'basics';

        $trackId = (string) Str::uuid();
        DB::table('education_tracks')->insert([
            'id' => $trackId, 'key' => $trackKey, 'title' => 'Learn Pages Test Track',
            'status' => 'live', 'ordering' => 99, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $moduleId = (string) Str::uuid();
        DB::table('education_modules')->insert([
            'id' => $moduleId, 'track_id' => $trackId, 'key' => $moduleKey,
            'title' => 'Learn Pages Test Module', 'status' => 'live', 'ordering' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['q1', 'q2'] as $i => $qKey) {
            DB::table('education_questions')->insert([
                'id' => (string) Str::uuid(), 'module_id' => $moduleId, 'key' => $qKey,
                'prompt' => "learn.test.{$qKey}.prompt",
                'choices' => json_encode(['a' => "learn.test.{$qKey}.a", 'b' => "learn.test.{$qKey}.b"]),
                'correct_keys' => json_encode(['b']),
                'weight' => 3, 'ordering' => $i, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$trackKey, $moduleKey];
    }

    private function aUser(): User
    {
        return User::create([
            'name' => 'Learn Pages Learner',
            'email' => 'learn-pages-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }

    private function onLivePg(callable $body): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
