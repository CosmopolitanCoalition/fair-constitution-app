<?php

namespace Tests\Constitutional;

use App\Services\Federation\InstanceIdentityService;
use App\Support\InstanceClass;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — CLASS-SCOPED FEDERATION (operator ruling, 2026-07-25).
 *
 * This REVERSES the charter's original Phase O position. The charter said a
 * scale_demo instance forces federation OFF ("a demo has received no consent,
 * so it has nothing to federate"). The operator ruled otherwise: the demo DOES
 * federate — with other demo instances only. *"It's gonna be its own
 * federation. A demo instance is only going to federate with other demo
 * instances."*
 *
 * That is a better rail than the original, because it is SYMMETRIC. "Federation
 * off" protected real instances only if every demo remembered to switch itself
 * off. Class matching protects both sides: a production instance refuses a demo
 * peer for exactly the same reason a demo refuses a production one, and neither
 * side is the lenient one.
 *
 * THE INVARIANTS:
 *   · the class rides the SIGNED handshake payload — a remote instance cannot
 *     read our local column, so it must be advertised or it cannot be checked
 *   · matching classes may peer; mismatched classes may NOT, in both directions
 *   · an unrecognised or absent class reads as `production` — every pre-Phase-O
 *     instance advertises nothing, and a demo must refuse anything it cannot
 *     positively identify as a demo
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class ClassScopedFederationTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_classfed';

    protected function tearDown(): void
    {
        InstanceClass::flush();
        parent::tearDown();
    }

    /** The class must be advertised, or a peer has nothing to check. */
    public function test_the_handshake_payload_advertises_our_instance_class(): void
    {
        $this->onLivePg(function () {
            InstanceClass::override(InstanceClass::SCALE_DEMO);

            $payload = app(InstanceIdentityService::class)->handshakePayload();

            $this->assertArrayHasKey(
                'instance_class',
                $payload,
                'the class must ride the signed handshake — a peer cannot read our local column'
            );
            $this->assertSame(InstanceClass::SCALE_DEMO, $payload['instance_class']);

            InstanceClass::override(InstanceClass::PRODUCTION);
            $this->assertSame(
                InstanceClass::PRODUCTION,
                app(InstanceIdentityService::class)->handshakePayload()['instance_class']
            );
        });
    }

    /** Like peers with like — in both directions. */
    public function test_matching_classes_may_federate(): void
    {
        InstanceClass::override(InstanceClass::SCALE_DEMO);
        $this->assertTrue(
            InstanceClass::federatesWith(InstanceClass::SCALE_DEMO),
            'a demo federates with a demo — that is the whole ruling'
        );

        InstanceClass::override(InstanceClass::PRODUCTION);
        $this->assertTrue(
            InstanceClass::federatesWith(InstanceClass::PRODUCTION),
            'real instances federate with real instances, unchanged'
        );
    }

    /** The rule is symmetric: neither side is the lenient one. */
    public function test_mismatched_classes_may_never_federate_in_either_direction(): void
    {
        InstanceClass::override(InstanceClass::PRODUCTION);
        $this->assertFalse(
            InstanceClass::federatesWith(InstanceClass::SCALE_DEMO),
            'a real instance must refuse a demo peer — synthetic records must never '
            .'cross into a consent-bearing mesh under Full Faith & Credit'
        );

        InstanceClass::override(InstanceClass::SCALE_DEMO);
        $this->assertFalse(
            InstanceClass::federatesWith(InstanceClass::PRODUCTION),
            'and a demo must refuse a real peer, for the same reason mirrored'
        );
    }

    /**
     * FAIL CLOSED on anything unrecognised. Every instance built before Phase O
     * advertises no class at all, and a typo or a future value must never be
     * read as "demo" by a demo that is looking for company.
     */
    public function test_an_unknown_or_absent_class_reads_as_production(): void
    {
        foreach ([null, '', 'sandbox', 'demo', 'SCALE_DEMO', 'banana', 0, false, []] as $value) {
            $this->assertSame(
                InstanceClass::PRODUCTION,
                InstanceClass::normalize($value),
                sprintf('[%s] must normalize to production', var_export($value, true))
            );
        }

        // Concretely: a demo refuses a peer that advertises nothing.
        InstanceClass::override(InstanceClass::SCALE_DEMO);
        $this->assertFalse(
            InstanceClass::federatesWith(null),
            'a demo must refuse a peer it cannot positively identify as a demo'
        );

        // And a production instance is unaffected by silent peers — which is
        // every peer that predates this ruling.
        InstanceClass::override(InstanceClass::PRODUCTION);
        $this->assertTrue(
            InstanceClass::federatesWith(null),
            'pre-Phase-O peers advertise nothing and must keep working'
        );
    }

    /** Both handshake ends enforce it — outbound discovery and inbound receive. */
    public function test_both_ends_of_the_handshake_enforce_the_rule(): void
    {
        $source = file_get_contents(base_path('app/Services/Federation/PeerService.php'));

        $this->assertSame(
            2,
            substr_count($source, '$this->assertSameClass('),
            'the class check must run on BOTH the outbound discover() and the inbound '
            .'receiveHandshake() paths — one-sided enforcement lets the other side in'
        );

        $this->assertStringContainsString(
            'private function assertSameClass(',
            $source,
            'both ends must share ONE implementation so their semantics cannot drift'
        );
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = \Illuminate\Support\Facades\DB::getDefaultConnection();
        \Illuminate\Support\Facades\DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            \Illuminate\Support\Facades\DB::setDefaultConnection($original);
        }
    }
}
