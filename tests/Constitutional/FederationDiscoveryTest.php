<?php

namespace Tests\Constitutional;

use App\Models\InstanceSettings;
use App\Services\Federation\FederationDiscoveryService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Zero-foreknowledge cold-start discovery (roles campaign). A fresh node finds an existing federation
 * with no address up front — via the public front door and an opt-in LAN sweep — and the public
 * descriptor it discovers carries ONLY public facts. The SSRF guard confines the LAN sweep to the
 * operator's own private network.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class FederationDiscoveryTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_discovery';

    private function svc(): FederationDiscoveryService
    {
        return app(FederationDiscoveryService::class);
    }

    public function test_the_lan_sweep_refuses_a_public_reserved_or_oversized_range(): void
    {
        $svc = $this->svc();

        foreach (['8.8.8.0/24', '1.1.1.1/32', '169.254.1.0/24', '100.64.1.0/24', '192.168.0.0/16', '10.0.0.0/8'] as $bad) {
            try {
                $svc->enumeratePrivateHosts($bad);
                $this->fail("the sweep accepted an out-of-bounds range: {$bad}");
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        // A small private range is allowed and enumerates to its own hosts only.
        $hosts = $svc->enumeratePrivateHosts('192.168.1.0/29');
        $this->assertContains('192.168.1.1', $hosts);
        $this->assertLessThanOrEqual(8, count($hosts));
        foreach ($hosts as $ip) {
            $this->assertStringStartsWith('192.168.1.', $ip);
        }
    }

    public function test_probe_accepts_a_valid_descriptor_and_rejects_noise(): void
    {
        Http::fake([
            'http://good.test/.well-known/cga-federation' => Http::response([
                'protocol' => 'cga-federation', 'version' => 1, 'server_id' => '11111111-1111-1111-1111-111111111111',
                'name' => 'Earth', 'federation_self_url' => 'http://good.test:8080', 'accepting_joins' => true,
            ]),
            'http://marketing.test/.well-known/cga-federation' => Http::response('<html>not a node</html>', 200),
            'http://wrongproto.test/.well-known/cga-federation' => Http::response(['protocol' => 'something-else']),
            'http://down.test/.well-known/cga-federation' => Http::response('', 503),
            '*' => Http::response('', 404),
        ]);

        $svc = $this->svc();
        $this->assertNotNull($svc->probe('http://good.test'), 'a valid CGA descriptor is accepted');
        $this->assertNull($svc->probe('http://marketing.test'), 'a non-JSON marketing page is rejected');
        $this->assertNull($svc->probe('http://wrongproto.test'), 'a wrong-protocol body is rejected');
        $this->assertNull($svc->probe('http://down.test'), 'an unreachable host is rejected');
        $this->assertNull($svc->probe('ftp://nope.test'), 'a non-http scheme is rejected');
    }

    public function test_bootstrap_discovery_probes_the_front_door_and_follows_known_federations(): void
    {
        config(['cga.federation_bootstrap_urls' => ['https://front.test']]);
        Http::fake([
            'https://front.test/.well-known/cga-federation' => Http::response([
                'protocol' => 'cga-federation', 'server_id' => 'S-front', 'name' => 'Front Door',
                'federation_self_url' => 'https://front.test', 'accepting_joins' => true,
                'known_federations' => [['url' => 'https://child.test']],
            ]),
            'https://child.test/.well-known/cga-federation' => Http::response([
                'protocol' => 'cga-federation', 'server_id' => 'S-child', 'name' => 'Child Federation',
                'federation_self_url' => 'https://child.test', 'accepting_joins' => true,
            ]),
            '*' => Http::response('', 404),
        ]);

        $found = $this->svc()->discover(false, null)['federations'];
        $ids = array_column($found, 'server_id');
        $this->assertContains('S-front', $ids);
        $this->assertContains('S-child', $ids, 'a front door can vouch for other entry points');
    }

    public function test_a_hostile_front_door_cannot_make_us_probe_an_internal_target(): void
    {
        config(['cga.federation_bootstrap_urls' => ['https://front.test']]);
        Http::fake([
            'https://front.test/.well-known/cga-federation' => Http::response([
                'protocol' => 'cga-federation', 'server_id' => 'S-front', 'name' => 'Front',
                'federation_self_url' => 'https://front.test', 'accepting_joins' => true,
                'known_federations' => [
                    ['url' => 'http://169.254.169.254'],  // cloud-metadata endpoint
                    ['url' => 'http://127.0.0.1:6379'],   // loopback service
                    ['url' => 'http://10.1.2.3:8080'],    // internal RFC1918 host
                ],
            ]),
            '*' => Http::response('', 404),
        ]);

        $found = $this->svc()->discover(false, null)['federations'];
        $ids = array_column($found, 'server_id');

        $this->assertContains('S-front', $ids, 'the front door itself is reached');
        $this->assertCount(1, $found, 'no internal target is probed or surfaced from known_federations');
        // None of the internal IPs were ever requested.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254')
            || str_contains($request->url(), '127.0.0.1')
            || str_contains($request->url(), '10.1.2.3'));
    }

    public function test_the_same_federation_reached_two_ways_dedupes_to_one(): void
    {
        config([
            'cga.federation_bootstrap_urls' => ['https://front.test'],
            'cga.federation_lan_discovery' => true,
            'cga.federation_lan_discovery_ports' => ['8080'],
        ]);
        Http::fake([
            'https://front.test/.well-known/cga-federation' => Http::response([
                'protocol' => 'cga-federation', 'server_id' => 'S1', 'name' => 'Earth',
                'federation_self_url' => 'https://front.test', 'accepting_joins' => true,
            ]),
            'http://192.168.5.10:8080/.well-known/cga-federation' => Http::response([
                'protocol' => 'cga-federation', 'server_id' => 'S1', 'name' => 'Earth',
                'federation_self_url' => 'http://192.168.5.10:8080', 'accepting_joins' => true,
            ]),
            '*' => Http::response('', 404),
        ]);

        $found = $this->svc()->discover(true, '192.168.5.8/29')['federations'];
        $matches = array_values(array_filter($found, fn ($f) => $f['server_id'] === 'S1'));
        $this->assertCount(1, $matches, 'one server_id reachable via front door AND LAN is a single federation');
    }

    public function test_a_node_never_lists_itself_as_a_joinable_federation(): void
    {
        $this->onLivePg(function () {
            // A LAN sweep reaches this box's OWN descriptor (e.g. via host.docker.internal) — it must
            // never appear as a join target (the operator saw "Unnamed Instance … not open" = itself).
            $selfId = (string) app(InstanceIdentityService::class)->serverId();

            config([
                'cga.federation_bootstrap_urls' => ['https://front.test'],
                'cga.federation_lan_discovery' => true,
                'cga.federation_lan_discovery_ports' => ['8080'],
            ]);
            Http::fake([
                // A real peer (someone else) — should appear.
                'https://front.test/.well-known/cga-federation' => Http::response([
                    'protocol' => 'cga-federation', 'server_id' => 'PEER-A', 'name' => 'United Earth',
                    'federation_self_url' => 'https://front.test', 'accepting_joins' => true,
                ]),
                // OUR OWN descriptor, reached on the LAN sweep — must be filtered out by server_id.
                'http://192.168.5.10:8080/.well-known/cga-federation' => Http::response([
                    'protocol' => 'cga-federation', 'server_id' => $selfId, 'name' => 'Unnamed Instance',
                    'federation_self_url' => 'http://host.docker.internal:8080', 'accepting_joins' => false,
                ]),
                '*' => Http::response('', 404),
            ]);

            $ids = array_column($this->svc()->discover(true, '192.168.5.8/29')['federations'], 'server_id');
            $this->assertContains('PEER-A', $ids, 'a real peer still appears');
            $this->assertNotContains($selfId, $ids, 'the node never lists itself');
        });
    }

    public function test_a_bad_lan_range_reports_an_error_without_discarding_front_door_results(): void
    {
        config(['cga.federation_bootstrap_urls' => ['https://front.test'], 'cga.federation_lan_discovery' => true]);
        Http::fake([
            'https://front.test/.well-known/cga-federation' => Http::response([
                'protocol' => 'cga-federation', 'server_id' => 'S-front', 'name' => 'Front',
                'federation_self_url' => 'https://front.test', 'accepting_joins' => true,
            ]),
            '*' => Http::response('', 404),
        ]);

        // A public CIDR is rejected by the SSRF guard — but the front-door hit must survive.
        $result = $this->svc()->discover(true, '8.8.8.0/24');
        $this->assertNotNull($result['lan_error'], 'the out-of-bounds range surfaces an error');
        $this->assertContains('S-front', array_column($result['federations'], 'server_id'),
            'front-door results are not discarded when the LAN range is bad');
    }

    public function test_the_descriptor_exposes_public_identity_and_never_a_secret(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            config(['cga.federation_self_url' => 'http://host.docker.internal:8081']);

            $descriptor = $this->svc()->describeSelf();

            $this->assertSame('cga-federation', $descriptor['protocol']);
            $this->assertNotEmpty($descriptor['server_id']);
            $this->assertNotEmpty($descriptor['public_key']);
            $this->assertSame('http://host.docker.internal:8081', $descriptor['federation_self_url']);

            // The public_key here is the SAME advertised at /api/federation/identity (already public).
            $this->assertSame(
                app(InstanceIdentityService::class)->publicKey(),
                $descriptor['public_key']
            );

            // NO secret may appear anywhere in the descriptor.
            $blob = strtolower((string) json_encode($descriptor));
            foreach (['cloudflare', 'token', 'secret', 'private', 'join_key', 'password', 'credential'] as $needle) {
                $this->assertStringNotContainsString($needle, $blob, "the discovery descriptor leaks: {$needle}");
            }
        });
    }

    public function test_a_fresh_unconfigured_node_does_not_advertise_itself_as_joinable(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            // No federation_self_url + setup not complete ⇒ a cold node is not an entry point.
            config(['cga.federation_self_url' => '']);
            InstanceSettings::current()->forceFill(['setup_completed_at' => null])->save();

            $descriptor = $this->svc()->describeSelf();
            $this->assertFalse($descriptor['accepting_joins']);
        });
    }

    public function test_the_well_known_descriptor_route_is_public_and_unauthenticated(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === '.well-known/cga-federation');

        $this->assertNotNull($route, 'GET /.well-known/cga-federation is registered');
        $this->assertContains('GET', $route->methods());
        $this->assertNotContains('auth', $route->gatherMiddleware(), 'the descriptor must be reachable without a session');
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        app(RoleService::class)->flush();
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
            app(RoleService::class)->flush();
        }
    }
}
