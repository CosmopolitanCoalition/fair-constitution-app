<?php

namespace Tests\Feature;

use App\Domain\Engine\TrainingRequired;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Education\TrainingGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * The act-gate's mechanics (K2_ENGINE_PLAN §5.2, ruling A5): armed only by
 * published content, opened permanently by a filed completion, structured
 * refusal in between. The full engine-path walk (an untrained SEATED member's
 * F-LEG filing → redirect → train → act proceeds) is the wave's end-to-end
 * proof and lives with it.
 */
class TrainingGateTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_training_gate';

    public function test_the_gate_is_inert_until_content_is_published(): void
    {
        $this->onLivePg(function () {
            $gate = new TrainingGateService;
            $actor = $this->aUser();

            // Nothing published for 'legislature' inside this transaction's
            // world → the redirect has no destination → the gate stays open
            // (A5: a redirect, not a wall).
            if ($gate->hasLiveTraining('legislature')) {
                $this->markTestSkipped('This box already carries live legislature training content — the virgin-world half runs on virgin worlds.');
            }

            $gate->assertMayAct($actor, 'F-LEG-004');
            $this->addToAssertionCount(1);
        });
    }

    public function test_published_content_arms_the_gate_and_a_filed_completion_opens_it(): void
    {
        $this->onLivePg(function () {
            $gate = new TrainingGateService;
            $actor = $this->aUser();
            $track = 'gate-test-'.substr((string) Str::uuid(), 0, 8);

            config(['cga.education.gated_forms' => array_merge(
                config('cga.education.gated_forms'),
                ['F-LEG-004' => $track],
            ), 'cga.education.lesson_href_by_track' => array_merge(
                config('cga.education.lesson_href_by_track'),
                [$track => '/learn/'.$track],
            )]);

            // Publish a live module → the gate arms.
            $trackId = (string) Str::uuid();
            DB::table('education_tracks')->insert([
                'id' => $trackId, 'key' => $track, 'title' => 'Gate Test Track',
                'status' => 'live', 'ordering' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('education_modules')->insert([
                'id' => (string) Str::uuid(), 'track_id' => $trackId, 'key' => 'gate-module',
                'title' => 'Gate Module', 'status' => 'live', 'ordering' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            try {
                $gate->assertMayAct($actor, 'F-LEG-004');
                $this->fail('An untrained holder must be redirected once content is live.');
            } catch (TrainingRequired $e) {
                // The structured half carries everything the client needs.
                $this->assertSame([
                    'track'       => $track,
                    'surface_id'  => null,
                    'lesson_href' => '/learn/'.$track,
                ], $e->trainingRequired());
            }

            // A REJECTED F-EDU-001 opens nothing (the gate reads accepted
            // completions only)…
            app(AuditService::class)->append(
                module: 'education',
                event: 'education.training_completed.rejected',
                payload: ['track_key' => $track, 'module_key' => 'gate-module'],
                ref: 'F-EDU-001',
                actorId: (string) $actor->id,
            );
            $this->assertFalse($gate->hasCompleted($actor, $track));

            // …an accepted one opens it permanently.
            app(AuditService::class)->append(
                module: 'education',
                event: 'education.training_completed',
                payload: ['track_key' => $track, 'module_key' => 'gate-module', 'score_pct' => 100, 'passed' => true],
                ref: 'F-EDU-001',
                actorId: (string) $actor->id,
            );

            $gate->assertMayAct($actor, 'F-LEG-004');
            $this->addToAssertionCount(1);

            // Another user's completion opens nothing for this one.
            $stranger = $this->aUser();
            $this->assertFalse($gate->hasCompleted($stranger, $track));
        });
    }

    public function test_system_filings_never_meet_the_gate(): void
    {
        // Null actor = jobs and clock handlers; they hold no role to train
        // for, and the availability query must not even run (no DB here).
        (new TrainingGateService)->assertMayAct(null, 'F-LEG-004');
        $this->addToAssertionCount(1);
    }

    // ----------------------------------------------------------------------

    private function aUser(): User
    {
        return User::create([
            'name' => 'Training Gate Subject',
            'email' => 'training-gate-'.Str::uuid().'@test.invalid',
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
