<?php

namespace Tests\Constitutional;

use App\Console\Concerns\GuardsSyntheticData;
use App\Support\GameMode;
use App\Support\InstanceClass;
use Illuminate\Console\Command;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — the synthetic-data guard (Phase O, the two-instance doctrine).
 *
 * A demo has received no consent, so it is an illustration and never a
 * government (Preamble). The corollary is the rail pinned here: rows that
 * represent people, governments or civic acts nobody consented to may be
 * written ONLY on a world that has declared itself not-real.
 *
 * Two declarations qualify, both WORLD PROPERTIES set at founding — never code
 * flags, never env vars:
 *   instance_class = scale_demo   the Attained, a full-scale illustration
 *   game_mode      = sandbox      a declared playground (the dev toolbox's home)
 *
 * THE INVARIANTS: refusal is the DEFAULT; uncertainty resolves to refusal; the
 * two axes are independent and either one alone suffices to permit; and a
 * refusal is a FAILURE exit, not a warning.
 *
 * Before this rail existed, none of the repo's 54 artisan commands carried any
 * such check — `elections:demo` would mint 40 permanent users against whatever
 * database `.env` happened to point at.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class SyntheticDataGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        InstanceClass::flush();
        GameMode::flush();
        parent::tearDown();
    }

    /** A production world with no sandbox declaration REFUSES. This is the default. */
    public function test_production_world_refuses(): void
    {
        InstanceClass::override(InstanceClass::PRODUCTION);
        GameMode::override(GameMode::PRODUCTION);

        $this->assertFalse($this->guard(), 'a real world must refuse synthetic data');
    }

    /** An un-founded world (no mode chosen yet) REFUSES — uncertainty is not permission. */
    public function test_unfounded_world_refuses(): void
    {
        InstanceClass::override(InstanceClass::PRODUCTION);
        GameMode::override(null);

        $this->assertFalse($this->guard(), 'a world that has declared nothing must refuse');
    }

    /** The Attained permits — this is the whole point of the class. */
    public function test_scale_demo_permits(): void
    {
        InstanceClass::override(InstanceClass::SCALE_DEMO);
        GameMode::override(GameMode::PRODUCTION);

        $this->assertTrue($this->guard(), 'a scale_demo instance is where synthetic data belongs');
    }

    /** A declared sandbox permits, independently of instance_class. */
    public function test_sandbox_permits_independently(): void
    {
        InstanceClass::override(InstanceClass::PRODUCTION);
        GameMode::override(GameMode::SANDBOX);

        $this->assertTrue($this->guard(), 'a declared playground is a lawful home for the dev toolbox');
    }

    /**
     * THE AXES ARE INDEPENDENT. game_mode asks "is the dev toolbox legitimate
     * here?"; instance_class asks "is this world a government or an
     * illustration?". Neither implies the other, and collapsing them would
     * either brick the sandbox worlds or open every production instance.
     */
    public function test_the_two_axes_are_not_the_same_axis(): void
    {
        InstanceClass::override(InstanceClass::SCALE_DEMO);
        GameMode::override(GameMode::PRODUCTION);
        $this->assertTrue($this->guard(), 'scale_demo does not require sandbox');

        InstanceClass::override(InstanceClass::PRODUCTION);
        GameMode::override(GameMode::SANDBOX);
        $this->assertTrue($this->guard(), 'sandbox does not require scale_demo');

        $this->assertNotSame(
            InstanceClass::SCALE_DEMO,
            GameMode::SANDBOX,
            'the two axes must never be represented by the same token'
        );
    }

    /**
     * InstanceClass FAILS CLOSED. The dangerous direction is a generator
     * believing it is on a demo when it is not, so anything unrecognised —
     * including null and a stray value — must resolve to production.
     */
    public function test_instance_class_fails_closed_on_anything_unrecognised(): void
    {
        foreach ([null, '', 'sandbox', 'demo', 'SCALE_DEMO', 'banana'] as $value) {
            InstanceClass::override($value);
            GameMode::override(GameMode::PRODUCTION);

            $this->assertFalse(
                InstanceClass::isScaleDemo(),
                sprintf('[%s] must not resolve to scale_demo', var_export($value, true))
            );
            $this->assertFalse($this->guard(), 'an unrecognised class must refuse');
        }
    }

    /** Every generator command carries the guard — the rail is only as good as its coverage. */
    public function test_every_demo_command_carries_the_guard(): void
    {
        $commands = [
            \App\Console\Commands\ElectionsDemoCommand::class,
            \App\Console\Commands\PhaseDDemoCommand::class,
            \App\Console\Commands\PhaseEDemoCommand::class,
            \App\Console\Commands\SocialDemoCommand::class,
            \App\Console\Commands\MatrixDemoCommand::class,
            \App\Console\Commands\FederationDemoCommand::class,
        ];

        foreach ($commands as $class) {
            $this->assertContains(
                GuardsSyntheticData::class,
                class_uses_recursive($class),
                "{$class} mints synthetic data and MUST use the guard trait"
            );

            $source = file_get_contents((new \ReflectionClass($class))->getFileName());
            $this->assertStringContainsString(
                'guardSyntheticData()',
                $source,
                "{$class} uses the trait but never CALLS it — the guard must run in handle()"
            );
        }
    }

    /** A refusal must be a FAILURE exit so scripts and CI cannot sail past it. */
    public function test_refusal_is_a_failure_exit_not_a_warning(): void
    {
        InstanceClass::override(InstanceClass::PRODUCTION);
        GameMode::override(GameMode::PRODUCTION);

        $command = new class extends Command
        {
            use GuardsSyntheticData;

            protected $signature = 'test:synthetic-guard-probe';

            public function handle(): int
            {
                if (! $this->guardSyntheticData()) {
                    return self::FAILURE;
                }

                return self::SUCCESS;
            }
        };

        $command->setLaravel($this->app);
        $exit = $command->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\BufferedOutput()
        );

        $this->assertSame(Command::FAILURE, $exit, 'a refusal must exit non-zero');
    }

    /** Drive the real trait method through an anonymous command. */
    private function guard(): bool
    {
        $probe = new class extends Command
        {
            use GuardsSyntheticData;

            protected $signature = 'test:synthetic-guard-inner';

            public function callGuard(): bool
            {
                return $this->guardSyntheticData();
            }
        };

        $probe->setLaravel($this->app);
        $probe->setOutput(new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\BufferedOutput()
        ));

        return $probe->callGuard();
    }
}
