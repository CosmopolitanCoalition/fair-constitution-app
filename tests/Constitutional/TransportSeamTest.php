<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\FederationTransport;
use App\Services\Federation\FederationClient;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\TransportService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G8 transport). The SAME signed bytes travel over
 * any channel; only the DIALLING differs. The pins:
 *  1. a `.onion` endpoint dials through the configured SOCKS proxy; https / tailnet
 *     dial directly (null proxy) — and with no proxy configured, nothing changes;
 *  2. the transport registry round-trips our reachable endpoints (the shape G9
 *     publishes) and rejects an unknown transport.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class TransportSeamTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_transport';

    public function test_onion_dials_through_socks_and_others_dial_direct(): void
    {
        $client = app(FederationClient::class);

        // Default: no proxy configured → every channel dials direct (unchanged).
        config(['cga.federation_socks_proxy' => null, 'cga.federation_proxy' => null]);
        $this->assertNull($client->proxyFor('https://peer.test/api'));
        $this->assertNull($client->proxyFor('http://abc123.onion/api'));

        // With a Tor SOCKS proxy configured, ONLY .onion routes through it.
        config(['cga.federation_socks_proxy' => 'socks5h://127.0.0.1:9050']);
        $this->assertSame('socks5h://127.0.0.1:9050', $client->proxyFor('http://abc123.onion/api'));
        $this->assertNull($client->proxyFor('https://peer.test/api'), 'https dials direct');
        $this->assertNull($client->proxyFor('http://node.tailnet/api'), 'a tailnet address dials direct');
        $this->assertNull($client->proxyFor('http://[200:abcd::1]:8081/api'), 'a yggdrasil overlay address dials direct (G8b)');

        config(['cga.federation_socks_proxy' => null]);
    }

    public function test_the_transport_registry_round_trips_and_rejects_unknown(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $svc = app(TransportService::class);

            $svc->registerSelf('https', 'https://us.test', 200);
            $svc->registerSelf('onion', 'http://usxyz.onion', 100);
            // G8b — the yggdrasil overlay is a first-class fifth transport.
            $svc->registerSelf('yggdrasil', 'http://[200:abcd::1]:8081', 150);

            $endpoints = $svc->selfEndpoints();
            $this->assertSame('https://us.test', $endpoints[0]['url'], 'higher priority first');
            $this->assertSame('yggdrasil', $endpoints[1]['transport'], 'yggdrasil sits by priority (150)');
            $this->assertContains('onion', array_column($endpoints, 'transport'));

            // Idempotent update (no duplicate row per (server, transport)).
            $svc->registerSelf('https', 'https://us-new.test', 200);
            $this->assertSame(3, FederationTransport::query()
                ->where('server_id', app(InstanceIdentityService::class)->serverId())->count());

            $threw = false;
            try {
                $svc->registerSelf('carrier_pigeon', 'coop://1');
            } catch (ConstitutionalViolation) {
                $threw = true;
            }
            $this->assertTrue($threw, 'an unknown transport is rejected');
        });
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
