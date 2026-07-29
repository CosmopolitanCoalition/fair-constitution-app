<?php

namespace Tests\Feature;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\FormRegistry;
use App\Domain\Forms\Handlers\TrainingCompletion;
use App\Domain\Forms\Handlers\TrainingMaterialPublication;
use App\Models\User;
use Tests\TestCase;

/**
 * Registration-day coverage for the F-EDU pair (K2_ENGINE_PLAN §5.0/§5.1).
 *
 * The handlers are deliberately storage-free at registration: an accepted
 * F-EDU-001 chain entry IS the whole gate-relevant record (§5.2 READING
 * RULE); the decoration legs (achievement, stipend, progress latch) join
 * with the later Wave 3 build steps and get their own tests there.
 */
class EducationFormsTest extends TestCase
{
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

        (new TrainingCompletion)->handle(null, [
            'track_key' => 'legislature', 'module_key' => 'floor-vote', 'passed' => true, 'score_pct' => 100,
        ]);
    }

    public function test_completion_requires_track_module_pass_and_integer_score(): void
    {
        $handler = new TrainingCompletion;
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

    public function test_accepted_completion_records_the_pass_and_nothing_else(): void
    {
        $record = (new TrainingCompletion)->handle(new User, [
            'track_key' => ' legislature ',
            'module_key' => 'floor-vote',
            'passed' => true,
            'score_pct' => 80,
            'surface_id' => 'legislature/floor',
            'free_text_the_client_sent' => 'never recorded',
        ]);

        $this->assertSame([
            'track_key' => 'legislature',
            'module_key' => 'floor-vote',
            'score_pct' => 80,
            'passed' => true,
            'surface_id' => 'legislature/floor',
        ], $record, 'The audit payload is fixed-shape — unexpected client keys never reach the chain.');
    }

    public function test_both_handlers_refuse_answer_content_at_any_depth(): void
    {
        $completion = new TrainingCompletion;
        $publication = new TrainingMaterialPublication;
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
        $handler = new TrainingMaterialPublication;
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
}
