<?php

namespace Tests\Feature;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\FormRegistry;
use App\Domain\Forms\Handlers\TrainingCompletion;
use App\Domain\Forms\Handlers\TrainingMaterialPublication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Registration-day coverage for the F-EDU pair (K2_ENGINE_PLAN §5.0/§5.1).
 *
 * Every refusal path sits ABOVE the handler's first DB touch, so the
 * refusal tests run storage-free; the acceptance path (module lookup →
 * progress latch → ACH-EDU-001 mint → stipend-once) runs on the live pg
 * posture, rolled back. The full engine-path walk (untrained member's act
 * → redirect → train → act proceeds) lives in the gate's own tests.
 */
class EducationFormsTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_education_forms';

    public function test_the_pair_is_registered_with_the_ruled_shape(): void
    {
        $completion = FormRegistry::meta('F-EDU-001');
        $this->assertSame('Training Completion', $completion['name']);
        // §5.0.2: every training is open to every user — the universal R-01,
        // never a seat-holder role.
        $this->assertSame(['R-01'], $completion['roles']);
        $this->assertSame(TrainingCompletion::class, $completion['handler']);

        $publication = FormRegistry::meta('F-EDU-002');
        $this->assertSame('Training Material Publication', $publication['name']);
        $this->assertSame(['R-23'], $publication['roles']);
        $this->assertSame(TrainingMaterialPublication::class, $publication['handler']);
    }

    public function test_completion_is_a_persons_own_act(): void
    {
        $this->expectException(ConstitutionalViolation::class);

        app(TrainingCompletion::class)->handle(null, [
            'track_key' => 'legislature', 'module_key' => 'floor-vote', 'passed' => true, 'score_pct' => 100,
        ]);
    }

    public function test_completion_requires_track_module_pass_and_integer_score(): void
    {
        $handler = app(TrainingCompletion::class);
        $actor = new User;

        $good = ['track_key' => 'legislature', 'module_key' => 'floor-vote', 'passed' => true, 'score_pct' => 80];

        foreach ([
            'missing track' => array_diff_key($good, ['track_key' => 1]),
            'missing module' => array_diff_key($good, ['module_key' => 1]),
            'failed quiz never files' => array_replace($good, ['passed' => false]),
            'truthy-but-not-true pass' => array_replace($good, ['passed' => 1]),
            'missing score' => array_diff_key($good, ['score_pct' => 1]),
            'non-integer score' => array_replace($good, ['score_pct' => '80']),
            'out-of-range score' => array_replace($good, ['score_pct' => 101]),
        ] as $label => $payload) {
            try {
                $handler->handle($actor, $payload);
                $this->fail("Payload [{$label}] must be refused.");
            } catch (ConstitutionalViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_both_handlers_refuse_answer_content_at_any_depth(): void
    {
        $completion = app(TrainingCompletion::class);
        $publication = app(TrainingMaterialPublication::class);
        $actor = new User;

        $completionGood = ['track_key' => 't', 'module_key' => 'm', 'passed' => true, 'score_pct' => 100];
        $publicationGood = ['module_key' => 'm', 'title' => 'T', 'action' => 'publish'];

        foreach ([
            ['correct_keys' => ['q1' => 'b']],
            ['Answer_Key' => 'b'],
            ['meta' => ['nested' => ['ANSWERS' => ['q1' => 'b']]]],
        ] as $poison) {
            try {
                $completion->handle($actor, array_merge($completionGood, $poison));
                $this->fail('F-EDU-001 must refuse answer content: '.json_encode($poison));
            } catch (ConstitutionalViolation $e) {
                $this->assertStringContainsString('K2_ENGINE_PLAN §2', $e->citation);
            }

            try {
                $publication->handle($actor, array_merge($publicationGood, $poison));
                $this->fail('F-EDU-002 must refuse answer content: '.json_encode($poison));
            } catch (ConstitutionalViolation $e) {
                $this->assertStringContainsString('K2_ENGINE_PLAN §2', $e->citation);
            }
        }
    }

    public function test_publication_requires_module_title_and_a_known_action(): void
    {
        $handler = app(TrainingMaterialPublication::class);
        $actor = new User;

        $good = ['module_key' => 'floor-vote', 'title' => 'The Floor Vote', 'action' => 'publish'];

        $this->assertSame($good, $handler->handle($actor, $good));

        $withRef = $handler->handle($actor, $good + ['ip_register_entry_id' => 'abc-123']);
        $this->assertSame('abc-123', $withRef['ip_register_entry_id']);

        foreach ([
            'missing module' => array_diff_key($good, ['module_key' => 1]),
            'missing title' => array_diff_key($good, ['title' => 1]),
            'unknown action' => array_replace($good, ['action' => 'retract']),
            'system filing' => null,
        ] as $label => $payload) {
            try {
                $payload === null ? $handler->handle(null, $good) : $handler->handle($actor, $payload);
                $this->fail("Publication [{$label}] must be refused.");
            } catch (ConstitutionalViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ======================================================================
    // Acceptance — live pg, rolled back
    // ======================================================================

    public function test_acceptance_records_latches_and_decorates_exactly_once(): void
    {
        $this->onLivePg(function () {
            [$trackKey, $moduleKey] = $this->seedModule();
            $learner = $this->aUser();
            $handler = app(TrainingCompletion::class);

            $record = $handler->handle($learner, [
                'track_key' => " {$trackKey} ",
                'module_key' => $moduleKey,
                'passed' => true,
                'score_pct' => 80,
                'surface_id' => 'legislature/floor',
                'free_text_the_client_sent' => 'never recorded',
            ]);

            // The audit payload is fixed-shape — unexpected client keys
            // never reach the chain.
            $this->assertSame([
                'track_key' => $trackKey,
                'module_key' => $moduleKey,
                'score_pct' => 80,
                'passed' => true,
                'surface_id' => 'legislature/floor',
            ], $record);

            // The node-local latch is set…
            $progress = DB::table('education_progress')
                ->where('user_id', (string) $learner->id)->first();
            $this->assertSame('completed', $progress->state);
            $this->assertNotNull($progress->completed_at);

            // …and the decoration minted once.
            $this->assertSame(1, DB::table('achievements')
                ->where('user_id', (string) $learner->id)
                ->where('award_key', 'ACH-EDU-001')->count());

            // A retake refreshes the score, never the ledger (§3.5: second
            // achievement on retake is impossible by construction — and the
            // stipend keys off the mint's freshness, so it cannot pay twice).
            $handler->handle($learner, [
                'track_key' => $trackKey, 'module_key' => $moduleKey, 'passed' => true, 'score_pct' => 100,
            ]);

            $this->assertSame(1, DB::table('achievements')
                ->where('user_id', (string) $learner->id)
                ->where('award_key', 'ACH-EDU-001')->count());
            $this->assertSame(100, (int) DB::table('education_progress')
                ->where('user_id', (string) $learner->id)->value('score_pct'));
        });
    }

    public function test_a_completion_of_an_unpublished_module_is_refused(): void
    {
        $this->onLivePg(function () {
            $this->expectException(ConstitutionalViolation::class);

            app(TrainingCompletion::class)->handle($this->aUser(), [
                'track_key' => 'no-such-track', 'module_key' => 'no-such-module', 'passed' => true, 'score_pct' => 100,
            ]);
        });
    }

    // ----------------------------------------------------------------------

    /** @return array{0: string, 1: string} track key + module key */
    private function seedModule(): array
    {
        $trackKey = 'edu-test-'.substr((string) Str::uuid(), 0, 8);
        $moduleKey = 'module-'.substr((string) Str::uuid(), 0, 8);

        $trackId = (string) Str::uuid();
        DB::table('education_tracks')->insert([
            'id' => $trackId, 'key' => $trackKey, 'title' => 'Test Track',
            'status' => 'live', 'ordering' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('education_modules')->insert([
            'id' => (string) Str::uuid(), 'track_id' => $trackId, 'key' => $moduleKey,
            'title' => 'Test Module', 'status' => 'live', 'ordering' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$trackKey, $moduleKey];
    }

    private function aUser(): User
    {
        return User::create([
            'name' => 'Education Forms Learner',
            'email' => 'education-forms-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }

    private function onLivePg(callable $body): void
    {
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
