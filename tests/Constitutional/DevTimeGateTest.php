<?php

namespace Tests\Constitutional;

use App\Http\Middleware\DevTimeControlsEnabled;
use Tests\TestCase;

/**
 * THE PLAYTEST-CONTROL GATE, TESTED IN BOTH DIRECTIONS.
 *
 * Lane 9 captured /system/clocks and confirmed the "advance the world" block
 * was absent. I reported the gate as holding. It is holding — but the block
 * would have been invisible either way at that moment, because nothing in the
 * view rendered it yet. **The absence passed for a weaker reason than I
 * claimed.**
 *
 * That is the trap this file exists to close, and it is the rule the fleet
 * arrived at today: *a check that cannot fail is not a check.* An absence
 * proves nothing until you have shown the same instrument reporting a
 * presence. So every case below is paired — the gate must REFUSE for each
 * reason on its own, and must ALLOW when every reason is removed.
 *
 * Why this is a test and not a screenshot: a screenshot can only ever report
 * what is there. It structurally cannot distinguish "correctly hidden" from
 * "never built", which is exactly the confusion that produced this file.
 */
class DevTimeGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The permissive baseline. Each test then removes exactly one reason
        // to allow, so a failure names which gate stopped holding.
        config([
            'cga.impersonation' => true,
            'cga.dev_time'      => true,
        ]);
    }

    /** The controls are OFF by default. Nobody has to remember to disable them. */
    public function test_the_gate_refuses_when_dev_time_is_not_switched_on(): void
    {
        config(['cga.dev_time' => false]);

        $reason = DevTimeControlsEnabled::refusalReason();

        $this->assertNotNull($reason, 'dev_time=false must refuse');
        $this->assertStringContainsString('off', $reason);
    }

    /** The wider dev toolbox switch turns these off with everything else. */
    public function test_the_gate_refuses_when_the_dev_toolbox_is_off(): void
    {
        config(['cga.impersonation' => false]);

        $this->assertNotNull(
            DevTimeControlsEnabled::refusalReason(),
            'the controls must not outlive the toolbox that gates them',
        );
    }

    /**
     * FAIL-CLOSED. The peer check reads the database; when it cannot, the
     * instance is treated as connected and the controls are refused.
     *
     * The cost of being wrong in the permissive direction is a fabricated
     * record inside somebody else's chain of trust, so "I could not tell"
     * must mean no.
     */
    public function test_the_gate_refuses_when_it_cannot_determine_the_peer_state(): void
    {
        // The default test connection is sqlite:memory with no schema, so the
        // peer probe throws and the middleware's catch treats that as connected.
        $reason = DevTimeControlsEnabled::refusalReason();

        $this->assertNotNull($reason, 'an instance we cannot inspect must be refused, not allowed');
    }

    /**
     * ⚑ THE POSITIVE CONTROL — the one that makes every refusal above mean
     * something.
     *
     * Without this, all the assertions in this file are satisfied by a gate
     * that refuses unconditionally, which is indistinguishable from a feature
     * that was never wired. This case must be seen PASSING, or the absence
     * results are not yet evidence.
     *
     * It needs a live database (the sandbox and peer probes both read one), so
     * it skips rather than lies when the box is unavailable — and a skip is
     * reported as a skip, never counted as a pass.
     */
    public function test_the_gate_allows_when_every_condition_is_met(): void
    {
        $this->markTestSkipped(
            'POSITIVE CONTROL NOT YET RUN. Needs a live sandbox database with no peers: '
            .'GameMode::isSandbox() and the peer probe both read one, and the default test '
            .'connection has no schema. Until this case is seen PASSING, the refusals above '
            .'do not prove the gate discriminates — they are equally satisfied by a control '
            .'that was never built. Run it against fcd with CGA_DEV_TIME=true.'
        );
    }
}
