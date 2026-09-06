<?php

namespace Tests\Constitutional;

use App\Models\InstanceSettings;
use App\Support\SetupLadder;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — THE WIZARD LADDER (operator ruling 2026-09-05,
 * wizard-ladder A): Steps 0 to 6; the scale choice and the simulate choice
 * made at map acceptance decide whether Steps 4 and 5 open; a step that does
 * not apply is skipped, never shown as pending. The counter convention holds:
 * setup_step_completed = n means steps 0..n-1 are done and the next is n.
 * DB-free: the ladder is pure functions over the settings row.
 */
class SetupLadderTest extends TestCase
{
    private function settings(array $attrs): InstanceSettings
    {
        $s = new InstanceSettings();
        $s->forceFill(array_merge([
            'setup_step_completed'   => 0,
            'institution_scale_mode' => 'eager',
            'simulate_at_scale'      => false,
            'game_mode'              => 'production',
        ], $attrs));

        return $s;
    }

    public function test_eager_sandbox_with_simulation_walks_every_step(): void
    {
        $s = $this->settings(['institution_scale_mode' => 'eager', 'simulate_at_scale' => true, 'game_mode' => 'sandbox']);

        for ($n = 0; $n <= 6; $n++) {
            $this->assertTrue(SetupLadder::applies($n, $s), "step {$n} applies");
        }
        $this->assertSame(0, SetupLadder::next($s));

        $s->setup_step_completed = 4;
        $this->assertSame(4, SetupLadder::next($s));
        $this->assertSame(5, SetupLadder::completed(4, $s));
        $s->setup_step_completed = 5;
        $this->assertSame(5, SetupLadder::next($s));
        $this->assertSame(7, SetupLadder::completed(6, $s), 'closing Step 6 moves the counter past the last step');
    }

    public function test_population_and_manual_modes_skip_steps_4_and_5(): void
    {
        foreach (['population', 'manual'] as $mode) {
            $s = $this->settings(['institution_scale_mode' => $mode, 'simulate_at_scale' => true, 'game_mode' => 'sandbox']);

            $this->assertFalse(SetupLadder::applies(4, $s), "{$mode}: Step 4 does not apply");
            $this->assertFalse(SetupLadder::applies(5, $s), "{$mode}: Step 5 needs Step 4");
            $this->assertTrue(SetupLadder::applies(6, $s));

            // Step 3 done → the ladder lands on 6, not 4.
            $s->setup_step_completed = 4;
            $this->assertSame(6, SetupLadder::next($s));
            $this->assertSame(6, SetupLadder::completed(3, $s), 'completing 3 folds the skipped 4 and 5 into the counter');
            $this->assertFalse(SetupLadder::reachable(4, $s));
            $this->assertTrue(SetupLadder::reachable(6, $s));
        }
    }

    public function test_simulation_needs_eager_and_a_sandbox(): void
    {
        $eagerProduction = $this->settings(['simulate_at_scale' => true, 'game_mode' => 'production']);
        $this->assertTrue(SetupLadder::applies(4, $eagerProduction));
        $this->assertFalse(SetupLadder::applies(5, $eagerProduction), 'a production world never simulates');

        $eagerSandboxNoSim = $this->settings(['simulate_at_scale' => false, 'game_mode' => 'sandbox']);
        $this->assertFalse(SetupLadder::applies(5, $eagerSandboxNoSim));

        $eagerSandboxNoSim->setup_step_completed = 5;   // Step 4 locked
        $this->assertSame(6, SetupLadder::next($eagerSandboxNoSim));
    }

    public function test_reachability_gates_forward_progression_only(): void
    {
        $s = $this->settings(['setup_step_completed' => 2]);

        $this->assertTrue(SetupLadder::reachable(0, $s));
        $this->assertTrue(SetupLadder::reachable(2, $s), 'the next step is reachable');
        $this->assertFalse(SetupLadder::reachable(3, $s), 'a step past the next is locked');
        $this->assertFalse(SetupLadder::reachable(7, $s));
        $this->assertFalse(SetupLadder::reachable(-1, $s));
    }

    public function test_describe_marks_skipped_steps_and_never_a_skipped_step_as_pending(): void
    {
        $s = $this->settings(['institution_scale_mode' => 'manual', 'setup_step_completed' => 4]);
        $rows = SetupLadder::describe($s, 6);
        $byN = array_column($rows, null, 'n');

        $this->assertCount(7, $rows);
        $this->assertSame('done', $byN[3]['status']);
        $this->assertSame('skipped', $byN[4]['status']);
        $this->assertSame('skipped', $byN[5]['status']);
        $this->assertSame('current', $byN[6]['status']);
        foreach ($rows as $r) {
            $this->assertSame(SetupLadder::LABELS[$r['n']], $r['label']);
        }
    }
}
