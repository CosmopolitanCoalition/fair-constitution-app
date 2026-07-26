<?php

namespace Tests\Constitutional;

use App\Http\Middleware\DevTimeControlsEnabled;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * THE PLAYTEST-CONTROL GATE, TESTED SO EACH REASON DISCRIMINATES.
 *
 * Lane 9 captured /system/clocks and confirmed the "advance the world" block
 * was absent; I called the gate holding. It was — but the block would have
 * been invisible either way at that moment, because nothing rendered it. The
 * absence passed for a weaker reason than I claimed.
 *
 * The first version of this file then made the SAME mistake one layer down.
 * `phpunit.xml` sets `APP_ENV=testing`, so `environment('local')` is false in
 * every test and `refusalReason()` returned the environment message every
 * time. All three "refuses because X" cases were really testing the
 * environment gate, and passed for a reason unrelated to their own names.
 * A check that passes for the wrong reason is worse than one that cannot
 * fail, because it looks like coverage.
 *
 * So every case below forces the environment to `local` and removes exactly
 * ONE condition, then asserts the message names THAT condition. If a gate
 * stops holding, the failure says which one.
 */
class DevTimeGateTest extends TestCase
{
    use LivePgConnection;

    protected function setUp(): void
    {
        parent::setUp();

        // Without this, every case below is really the environment check.
        $this->app['env'] = 'local';

        config([
            'cga.impersonation' => true,
            'cga.dev_time'      => true,
        ]);
    }

    /** The environment gate itself — the one the others were accidentally testing. */
    public function test_the_gate_refuses_outside_the_local_environment(): void
    {
        $this->app['env'] = 'production';

        $reason = DevTimeControlsEnabled::refusalReason();

        $this->assertNotNull($reason);
        $this->assertStringContainsString('local environment', $reason);
    }

    /** The controls are OFF by default — nobody has to remember to disable them. */
    public function test_the_gate_refuses_when_dev_time_is_not_switched_on(): void
    {
        config(['cga.dev_time' => false]);

        $reason = DevTimeControlsEnabled::refusalReason();

        $this->assertNotNull($reason);
        $this->assertStringContainsString('Playtest time controls are off', $reason);
    }

    /** The controls must not outlive the wider dev toolbox that gates them. */
    public function test_the_gate_refuses_when_the_dev_toolbox_is_off(): void
    {
        config(['cga.impersonation' => false]);

        $reason = DevTimeControlsEnabled::refusalReason();

        $this->assertNotNull($reason);
        $this->assertStringContainsString('dev toolbox', $reason);
    }

    /**
     * FAIL-CLOSED. The sandbox and peer probes both read the database; the
     * default test connection has no schema, so they throw and the middleware
     * treats the instance as connected.
     *
     * "I could not tell" must mean no: the cost of being wrong in the
     * permissive direction is a fabricated record inside somebody else's chain
     * of trust.
     */
    public function test_the_gate_refuses_when_it_cannot_inspect_the_instance(): void
    {
        $this->assertNotNull(
            DevTimeControlsEnabled::refusalReason(),
            'an instance we cannot inspect must be refused, not allowed',
        );
    }

    /**
     * ⚑ THE POSITIVE CONTROL — the case that makes every refusal above mean
     * something.
     *
     * Without it, all four assertions are equally satisfied by a gate that
     * refuses unconditionally, which is indistinguishable from a control that
     * was never wired. Run against the live sandbox database so the game-mode
     * and peer probes can actually answer.
     */
    public function test_the_gate_allows_when_every_condition_is_met(): void
    {
        $pg = $this->livePg('pgsql_dev_time_gate');

        // Point the default connection at the real sandbox world so
        // GameMode::isSandbox() and the peer probe resolve against real rows.
        config(['database.default' => 'pgsql_dev_time_gate']);
        $pg->getPdo();

        // GameMode caches its answer in a static for the life of a REQUEST,
        // which is right in production and wrong across tests: the refusal
        // cases above resolve it to null against the schema-less default
        // connection, and without this flush the positive control would read
        // that stale null and "fail" for a reason that has nothing to do with
        // the gate.
        \App\Support\GameMode::flush();

        $reason = DevTimeControlsEnabled::refusalReason();

        $this->assertNull(
            $reason,
            'with local env, toolbox on, dev_time on, sandbox mode and no peers, the gate MUST allow — '
            .'otherwise the refusals above prove nothing. Refusal given: '.((string) $reason),
        );
    }
}
