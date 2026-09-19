<?php

namespace Tests\Unit;

use App\Console\Commands\SimStartCommand;
use App\Models\InstanceSettings;
use App\Services\Demo\SimRunControl;
use App\Support\SetupLadder;
use App\Support\SimDial;
use Tests\TestCase;

/**
 * THE SIMULATION DIAL (operator order 2026-09-19). The simulate choice moved
 * from map acceptance to the Step 4 lock, with the population dial (default
 * 0.1 %) and the roster floor beside it. DB-free: every rule is a pure seam.
 *
 *   · the default is 0.1 on every door (settings, page start, terminal start)
 *   · the dial clamps to 0..100; a value that is not a number gives the default
 *   · the lock writes only the keys the request carries; only a sandbox simulates
 *   · the stored choices reach `sim:start` as --sample-pct and --no-floor
 *   · the lock routes the ladder by the choice just made
 */
class SimDialTest extends TestCase
{
    private function settings(array $attrs = []): InstanceSettings
    {
        return (new InstanceSettings)->forceFill($attrs + [
            'institution_scale_mode' => 'eager',
            'game_mode'              => 'sandbox',
            'simulate_at_scale'      => false,
            'setup_step_completed'   => 4,
        ]);
    }

    public function test_the_default_dial_is_one_in_a_thousand_on_every_door(): void
    {
        $this->assertSame(0.1, SimDial::DEFAULT_PCT);

        // A settings row that predates the columns reads as the defaults.
        $this->assertSame(['sample_pct' => 0.1, 'roster_floor' => true], SimDial::fromSettings($this->settings()));

        // The terminal door states the same default in its signature.
        $signature = (new \ReflectionClass(SimStartCommand::class))->getDefaultProperties()['signature'];
        $this->assertMatchesRegularExpression('/--sample-pct=0\.1\b/', $signature);
    }

    public function test_the_dial_clamps_and_a_non_number_gives_the_default(): void
    {
        $this->assertSame(0.0, SimDial::clamp(0));
        $this->assertSame(0.0, SimDial::clamp(-5));
        $this->assertSame(100.0, SimDial::clamp(250));
        $this->assertSame(0.25, SimDial::clamp('0.25'));
        $this->assertSame(0.1235, SimDial::clamp(0.123456), 'stored precision is four decimals');
        $this->assertSame(SimDial::DEFAULT_PCT, SimDial::clamp(null));
        $this->assertSame(SimDial::DEFAULT_PCT, SimDial::clamp(''));
        $this->assertSame(SimDial::DEFAULT_PCT, SimDial::clamp('abc'));
    }

    public function test_the_lock_writes_only_the_keys_the_request_carries(): void
    {
        $this->assertSame([], SimDial::lockWrites([], 'sandbox'), 'an older page that sends nothing changes nothing');

        $this->assertSame(
            ['simulate_at_scale' => true, 'sim_sample_pct' => 0.5, 'sim_roster_floor' => false],
            SimDial::lockWrites(['simulate_at_scale' => true, 'sim_sample_pct' => '0.5', 'sim_roster_floor' => false], 'sandbox'),
        );

        $this->assertSame(['sim_sample_pct' => 100.0], SimDial::lockWrites(['sim_sample_pct' => 9000], 'sandbox'));
    }

    public function test_only_a_sandbox_world_simulates(): void
    {
        foreach (['production', null, ''] as $mode) {
            $writes = SimDial::lockWrites(['simulate_at_scale' => true], $mode);
            $this->assertFalse($writes['simulate_at_scale'], 'game_mode '.var_export($mode, true).' never simulates');
        }
    }

    public function test_the_stored_choices_reach_the_start_command(): void
    {
        $stored = $this->settings(['sim_sample_pct' => 0.1, 'sim_roster_floor' => true]);
        $cli = SimRunControl::cliOptions(SimDial::startOptions($stored));
        $this->assertSame(0.1, $cli['--sample-pct']);
        $this->assertArrayNotHasKey('--no-floor', $cli, 'floor on = the command default, no flag');

        $noFloor = $this->settings(['sim_sample_pct' => 2, 'sim_roster_floor' => false]);
        $cli = SimRunControl::cliOptions(SimDial::startOptions($noFloor));
        $this->assertSame(2.0, $cli['--sample-pct']);
        $this->assertTrue($cli['--no-floor']);
    }

    public function test_cli_options_pass_only_declared_options(): void
    {
        $cli = SimRunControl::cliOptions([
            'world-version' => 3, 'turnout' => 140, 'adm-max' => 4, 'limit' => '25',
            'aspects' => ['elections'], 'resume' => true, 'sample-pct' => '0.2', 'no-floor' => false,
            'smuggled' => 'x',
        ]);

        $this->assertSame([
            '--sample-pct'    => 0.2,
            '--world-version' => 3,
            '--turnout'       => 100,
            '--adm-max'       => 4,
            '--limit'         => 25,
            '--aspects'       => 'elections',
            '--resume'        => true,
        ], $cli);

        // No dial in the options = no flag: the command default (SimDial) applies.
        $this->assertArrayNotHasKey('--sample-pct', SimRunControl::cliOptions(['turnout' => 62]));
    }

    public function test_the_lock_routes_the_ladder_by_the_choice_just_made(): void
    {
        // Checked at the lock: Step 5 applies, so the lock opens Step 5.
        $on = $this->settings();
        $on->forceFill(SimDial::lockWrites(['simulate_at_scale' => true], $on->game_mode));
        $this->assertTrue(SetupLadder::applies(5, $on));
        $this->assertSame(5, SetupLadder::completed(4, $on));

        // Not checked: Step 5 is skipped, the lock opens Step 6.
        $off = $this->settings(['simulate_at_scale' => true]);
        $off->forceFill(SimDial::lockWrites(['simulate_at_scale' => false], $off->game_mode));
        $this->assertFalse(SetupLadder::applies(5, $off));
        $this->assertSame(6, SetupLadder::completed(4, $off));
    }
}
