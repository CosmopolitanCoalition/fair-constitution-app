<?php

namespace Tests\Constitutional;

use App\Models\DirectoryEntry;
use App\Models\FederationPeer;
use App\Models\FederationTransport;
use App\Models\FederationTransportHealth;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\MultiplexClient;
use App\Services\Federation\NoSurvivingTransport;
use App\Services\Federation\TransportEndpoints;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G8b) multiplex survival mesh: "if one survives, all
 * survive." A peer is ONE identity reachable over a SET of transports; the multiplex
 * hands FederationClient (the protected signing seam) the SAME signed bytes over each
 * base URL in turn until one delivers. The pins:
 *   1. BACK-COMPAT — a peer with only a legacy url is one rung; reach() dials exactly
 *      that, unchanged from a direct FederationClient call;
 *   2. FAILOVER — a connection failure on the preferred transport silently falls over
 *      to the next surviving transport; an HTTP refusal (4xx) is a real answer and is
 *      returned INTACT, never failed over;
 *   3. CIRCUIT-BREAKING — N consecutive failures trip a transport's circuit OPEN; a
 *      subsequent call skips it fast (no wasted timeout) until cooldown;
 *   4. only a transport-level failure counts against health — a delivered response
 *      (any status) closes the circuit and refreshes latency.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class MultiplexClientTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_multiplex';

    public function test_a_peer_with_only_a_legacy_url_is_one_rung_and_dials_it_unchanged(): void
    {
        $this->onLivePg(function () {
            $peerId = $this->trustedPeer('https://legacy-only.test');
            Http::fake(['*legacy-only.test*' => Http::response(['ok' => true], 200)]);

            $resp = app(MultiplexClient::class)->reach($peerId, 'POST', '/api/federation/write', ['a' => 1]);

            $this->assertSame(200, $resp->status());
            // Byte-identical to a direct FederationClient call: ONE request, same
            // method, same body, same target — no phantom rung, no inner retry.
            Http::assertSentCount(1);
            Http::assertSent(fn ($r) => $r->method() === 'POST'
                && str_contains($r->url(), 'legacy-only.test/api/federation/write')
                && $r['a'] === 1);
        });
    }

    public function test_a_connection_failure_falls_over_to_the_next_surviving_transport(): void
    {
        $this->onLivePg(function () {
            $peerId = $this->trustedPeer('https://down.test');
            $this->transport($peerId, 'https', 'https://down.test', 200);
            $this->transport($peerId, 'tailnet', 'http://100.64.0.5:8081', 100);

            Http::fake([
                '*down.test*' => fn () => throw new ConnectionException('connection refused'),
                '*100.64.0.5*' => Http::response(['ok' => true], 200),
            ]);

            $resp = app(MultiplexClient::class)->reach($peerId, 'POST', '/api/federation/write', []);

            $this->assertSame(200, $resp->status(), 'the surviving transport answered');
            $this->assertSame(1, (int) $this->health($peerId, 'https')->consecutive_failures, 'the dead transport took a failure');
            $survivor = $this->health($peerId, 'tailnet');
            $this->assertSame(FederationTransportHealth::CIRCUIT_CLOSED, $survivor->circuit_state, 'the survivor is healthy');
            $this->assertNotNull($survivor->latency_ema_ms, 'a delivery records latency');
            $this->assertGreaterThanOrEqual(0, (int) $survivor->latency_ema_ms);
        });
    }

    public function test_an_http_refusal_is_returned_intact_and_never_failed_over(): void
    {
        $this->onLivePg(function () {
            $peerId = $this->trustedPeer('https://leader.test');
            $this->transport($peerId, 'https', 'https://leader.test', 200);
            $this->transport($peerId, 'tailnet', 'http://100.64.0.9:8081', 100);

            // The preferred transport DELIVERS a 421 (an authoritative "not me").
            Http::fake([
                '*leader.test*' => Http::response(['error' => 'misdirected'], 421),
                '*100.64.0.9*' => Http::response(['ok' => true], 200),
            ]);

            $resp = app(MultiplexClient::class)->reach($peerId, 'POST', '/api/federation/write', []);

            $this->assertSame(421, $resp->status(), 'a 4xx is a real answer, returned intact');
            Http::assertNotSent(fn ($r) => str_contains($r->url(), '100.64.0.9'));
            $health = $this->health($peerId, 'https');
            $this->assertSame(0, (int) $health->consecutive_failures, 'a delivered response is not a transport failure');
            $this->assertSame(FederationTransportHealth::CIRCUIT_CLOSED, $health->circuit_state);
        });
    }

    public function test_repeated_failures_trip_the_circuit_open_and_a_later_call_skips_it_fast(): void
    {
        $this->onLivePg(function () {
            config(['cga.federation_transport_failure_threshold' => 3]);
            $peerId = $this->trustedPeer('https://flaky.test');
            $this->transport($peerId, 'https', 'https://flaky.test', 200);
            $this->transport($peerId, 'tailnet', 'http://100.64.0.7:8081', 100);

            $httpsCalls = 0;
            Http::fake([
                '*flaky.test*' => function () use (&$httpsCalls) {
                    $httpsCalls++;
                    throw new ConnectionException('down');
                },
                '*100.64.0.7*' => Http::response(['ok' => true], 200),
            ]);

            $mux = app(MultiplexClient::class);
            // Three calls drive https to the failure threshold; tailnet carries each.
            for ($i = 0; $i < 3; $i++) {
                $this->assertSame(200, $mux->reach($peerId, 'POST', '/api/federation/write', [])->status());
            }
            $this->assertSame(FederationTransportHealth::CIRCUIT_OPEN, $this->health($peerId, 'https')->circuit_state);
            $this->assertSame(3, $httpsCalls);

            // Fourth call: the open circuit is skipped fast — no new https dial.
            $this->assertSame(200, $mux->reach($peerId, 'POST', '/api/federation/write', [])->status());
            $this->assertSame(3, $httpsCalls, 'an open circuit within cooldown is not re-dialed');
        });
    }

    public function test_no_surviving_transport_throws_when_all_channels_fail(): void
    {
        $this->onLivePg(function () {
            $peerId = $this->trustedPeer('https://gone.test');
            $this->transport($peerId, 'https', 'https://gone.test', 200);

            Http::fake(['*gone.test*' => fn () => throw new ConnectionException('vanished')]);

            $this->expectException(NoSurvivingTransport::class);
            app(MultiplexClient::class)->reach($peerId, 'POST', '/api/federation/write', []);
        });
    }

    public function test_onion_is_undialable_without_a_socks_proxy_so_an_onion_only_peer_is_unreachable(): void
    {
        $this->onLivePg(function () {
            config(['cga.federation_socks_proxy' => null]);
            $peerId = (string) Str::uuid();
            FederationPeer::create([
                'server_id' => $peerId,
                'name' => 'Onion-only',
                'url' => '', // no legacy clearnet url
                'public_key' => app(InstanceIdentityService::class)->publicKey(),
                'status' => FederationPeer::STATUS_TRUST_ESTABLISHED,
                'trust_established_at' => now(),
            ]);
            $this->transport($peerId, 'onion', 'http://abc123.onion', 200);

            // No fake needed — nothing is dialable, so no HTTP is attempted.
            $this->expectException(NoSurvivingTransport::class);
            app(MultiplexClient::class)->reach($peerId, 'GET', '/api/federation/identity', []);
        });
    }

    public function test_censorship_floor_first_sorts_resistant_transports_above_clearnet(): void
    {
        $this->onLivePg(function () {
            $peerId = $this->trustedPeer('https://anchor.test');
            $this->transport($peerId, 'https', 'https://anchor.test', 200);   // higher priority
            $this->transport($peerId, 'onion', 'http://anchor.onion', 50);    // lower priority

            config(['cga.federation_censorship_floor_first' => false]);
            $open = app(TransportEndpoints::class)->forPeer($peerId);
            $this->assertSame('https', $open[0]['transport'], 'open posture: priority wins');

            config(['cga.federation_censorship_floor_first' => true]);
            $censored = app(TransportEndpoints::class)->forPeer($peerId);
            $this->assertSame('onion', $censored[0]['transport'], 'censored posture: a resistant transport is the first hop');
        });
    }

    public function test_two_urls_on_the_same_transport_own_independent_circuits(): void
    {
        $this->onLivePg(function () {
            config(['cga.federation_transport_failure_threshold' => 3]);
            // urlB is the legacy peer url; urlA is a learned https transport (preferred).
            $peerId = $this->trustedPeer('https://b.test');
            $this->transport($peerId, 'https', 'https://a.test', 200);

            Http::fake([
                '*a.test*' => fn () => throw new ConnectionException('A down'),
                '*b.test*' => Http::response(['ok' => true], 200),
            ]);

            $mux = app(MultiplexClient::class);
            for ($i = 0; $i < 3; $i++) {
                $this->assertSame(200, $mux->reach($peerId, 'POST', '/api/federation/write', [])->status());
            }

            // urlA accrues its OWN failures and trips OPEN; urlB's successes never reset
            // them. Pre-fix (one row per transport) the counter oscillated 1→0 and urlA
            // would never have opened — that shadowing is the bug this pins.
            $a = $this->health($peerId, 'https', 'https://a.test');
            $b = $this->health($peerId, 'https', 'https://b.test');
            $this->assertSame(3, (int) $a->consecutive_failures);
            $this->assertSame(FederationTransportHealth::CIRCUIT_OPEN, $a->circuit_state);
            $this->assertSame(0, (int) $b->consecutive_failures, 'the healthy sibling url keeps its own circuit');
            $this->assertSame(FederationTransportHealth::CIRCUIT_CLOSED, $b->circuit_state);
        });
    }

    public function test_an_open_circuit_within_cooldown_is_recovered_by_the_pass_2_last_resort(): void
    {
        $this->onLivePg(function () {
            config(['cga.federation_transport_circuit_cooldown_seconds' => 60]);
            $peerId = $this->trustedPeer('https://sole.test');
            // Pre-trip the SOLE transport's circuit, within cooldown (not cooled → it is
            // skipped on pass 1 and only the pass-2 last resort can save the peer).
            FederationTransportHealth::create([
                'server_id' => $peerId, 'transport' => 'https', 'url' => 'https://sole.test',
                'circuit_state' => FederationTransportHealth::CIRCUIT_OPEN,
                'consecutive_failures' => 3, 'last_fail_at' => now(),
            ]);

            $calls = 0;
            Http::fake(['*sole.test*' => function () use (&$calls) {
                $calls++;

                return Http::response(['ok' => true], 200);
            }]);

            // The channel has actually recovered — reach() must NOT throw NoSurvivingTransport.
            $resp = app(MultiplexClient::class)->reach($peerId, 'POST', '/api/federation/write', []);

            $this->assertSame(200, $resp->status(), 'pass 2 retries the open rung rather than bricking the peer');
            $this->assertSame(1, $calls, 'dialed exactly once (only on the pass-2 last resort)');
            $this->assertSame(
                FederationTransportHealth::CIRCUIT_CLOSED,
                $this->health($peerId, 'https', 'https://sole.test')->circuit_state,
                'a delivery re-closes the circuit',
            );
        });
    }

    public function test_a_cooled_open_circuit_becomes_a_half_open_probe_dialed_on_pass_1(): void
    {
        $this->onLivePg(function () {
            config(['cga.federation_transport_circuit_cooldown_seconds' => 60]);
            $peerId = $this->trustedPeer('https://cooled.test');
            FederationTransportHealth::create([
                'server_id' => $peerId, 'transport' => 'https', 'url' => 'https://cooled.test',
                'circuit_state' => FederationTransportHealth::CIRCUIT_OPEN,
                'consecutive_failures' => 3, 'last_fail_at' => now()->subSeconds(61), // cooled
            ]);

            // The ladder surfaces a cooled-open circuit as a HALF-OPEN probe rung —
            // attemptable on pass 1, scored between healthy and dead.
            $rung = app(TransportEndpoints::class)->forPeer($peerId)[0];
            $this->assertSame(FederationTransportHealth::CIRCUIT_HALF_OPEN, $rung['circuit']);
            $this->assertTrue($rung['attemptable']);

            $calls = 0;
            Http::fake(['*cooled.test*' => function () use (&$calls) {
                $calls++;

                return Http::response(['ok' => true], 200);
            }]);

            $resp = app(MultiplexClient::class)->reach($peerId, 'POST', '/api/federation/write', []);
            $this->assertSame(200, $resp->status());
            $this->assertSame(1, $calls, 'the cooled circuit is probed on pass 1');
            $this->assertSame(
                FederationTransportHealth::CIRCUIT_CLOSED,
                $this->health($peerId, 'https', 'https://cooled.test')->circuit_state,
            );
        });
    }

    public function test_the_ladder_breaks_ties_by_lower_latency_then_unknown_last(): void
    {
        $this->onLivePg(function () {
            $peerId = (string) Str::uuid();
            FederationPeer::create([
                'server_id' => $peerId, 'name' => 'Tie peer', 'url' => '', // no legacy rung
                'public_key' => app(InstanceIdentityService::class)->publicKey(),
                'status' => FederationPeer::STATUS_TRUST_ESTABLISHED, 'trust_established_at' => now(),
            ]);
            // Three equal-priority, healthy transports differing only by known latency.
            $this->transport($peerId, 'https', 'https://h.test', 100);
            $this->transport($peerId, 'tailnet', 'http://100.64.0.30:8081', 100);
            $this->transport($peerId, 'yggdrasil', 'http://[200:dd::1]:8081', 100);
            $this->seedHealth($peerId, 'https', 'https://h.test', latency: 50);
            $this->seedHealth($peerId, 'tailnet', 'http://100.64.0.30:8081', latency: 10);
            // yggdrasil has NO health row → null latency → sorts last.

            $ladder = app(TransportEndpoints::class)->forPeer($peerId);
            $this->assertSame('tailnet', $ladder[0]['transport'], 'lowest latency first');
            $this->assertSame('https', $ladder[1]['transport']);
            $this->assertSame('yggdrasil', $ladder[2]['transport'], 'unknown latency sorts last');
        });
    }

    public function test_a_directory_endpoint_surfaces_as_a_rung_and_dedupes_with_the_registry(): void
    {
        $this->onLivePg(function () {
            $peerId = $this->trustedPeer('https://legacy.test');
            // The registry advertises tailnet at a LOW priority ...
            $this->transport($peerId, 'tailnet', 'http://100.64.0.40:8081', 50);
            // ... and a directory entry names the SAME (transport,url) at a higher one,
            // plus an onion endpoint the registry never had.
            DirectoryEntry::create([
                'jurisdiction_id' => (string) Str::uuid(),
                'server_id' => $peerId,
                'endpoints' => [
                    ['transport' => 'tailnet', 'url' => 'http://100.64.0.40:8081'], // dup of the registry rung
                    ['transport' => 'onion', 'url' => 'http://peer.onion'],         // directory-only rung
                ],
                'priority' => 200,
                'signature' => 'x',
                'published_at' => now(),
            ]);

            $ladder = collect(app(TransportEndpoints::class)->forPeer($peerId));

            $this->assertTrue($ladder->contains('url', 'http://peer.onion'), 'a directory endpoint is a ladder rung');
            $tailnet = $ladder->where('url', 'http://100.64.0.40:8081');
            $this->assertCount(1, $tailnet, 'a (transport,url) seen twice dedupes to one rung');
            $this->assertSame(200, $tailnet->first()['priority'], 'dedupe keeps the highest priority');
        });
    }

    public function test_censorship_floor_outranks_health(): void
    {
        $this->onLivePg(function () {
            config(['cga.federation_censorship_floor_first' => true]);
            $peerId = $this->trustedPeer('https://anchor.test');
            $this->transport($peerId, 'https', 'https://anchor.test', 200);
            $this->transport($peerId, 'onion', 'http://anchor.onion', 50);
            // The resistant transport is UNHEALTHY (tripped, within cooldown); clearnet is
            // healthy. Censored posture STILL ranks onion first so a blocked clearnet
            // endpoint is never the visible first hop.
            FederationTransportHealth::create([
                'server_id' => $peerId, 'transport' => 'onion', 'url' => 'http://anchor.onion',
                'circuit_state' => FederationTransportHealth::CIRCUIT_OPEN,
                'consecutive_failures' => 3, 'last_fail_at' => now(),
            ]);

            $ladder = app(TransportEndpoints::class)->forPeer($peerId);
            $this->assertSame('onion', $ladder[0]['transport'], 'the floor outranks health');
        });
    }

    public function test_a_health_write_failure_never_breaks_the_delivered_call(): void
    {
        $this->onLivePg(function () {
            $peerId = $this->trustedPeer('https://resilient.test');
            Http::fake(['*resilient.test*' => Http::response(['ok' => true], 200)]);

            // Force EVERY health write to throw — bookkeeping must never abort the dial.
            FederationTransportHealth::saving(function () {
                throw new QueryException('pgsql', 'insert into federation_transport_health', [], new \RuntimeException('dup'));
            });

            try {
                $resp = app(MultiplexClient::class)->reach($peerId, 'POST', '/api/federation/write', []);
                $this->assertSame(200, $resp->status(), 'a swallowed health-write error never breaks the delivered call');
            } finally {
                FederationTransportHealth::getEventDispatcher()->forget('eloquent.saving: '.FederationTransportHealth::class);
            }
        });
    }

    public function test_clk20_probe_recovers_a_degraded_transport(): void
    {
        $this->onLivePg(function () {
            $peerId = $this->trustedPeer('https://probe.test'); // legacy https rung
            FederationTransportHealth::create([
                'server_id' => $peerId, 'transport' => 'https', 'url' => 'https://probe.test',
                'circuit_state' => FederationTransportHealth::CIRCUIT_OPEN,
                'consecutive_failures' => 3, 'last_fail_at' => now(),
            ]);
            Http::fake(['*probe.test*' => Http::response(['server_id' => $peerId], 200)]);

            $probed = app(MultiplexClient::class)->probeUnhealthy($peerId);

            $this->assertSame(1, $probed, 'the one degraded rung was probed');
            $this->assertSame(
                FederationTransportHealth::CIRCUIT_CLOSED,
                $this->health($peerId, 'https', 'https://probe.test')->circuit_state,
                'a recovered transport is re-learned even with no traffic flowing to it',
            );
        });
    }

    public function test_clk20_probe_leaves_a_dead_transport_open_and_skips_healthy_ones(): void
    {
        $this->onLivePg(function () {
            config(['cga.federation_transport_failure_threshold' => 3]);
            $peerId = $this->trustedPeer('https://dead.test'); // legacy https rung (still dead)
            $this->transport($peerId, 'tailnet', 'http://100.64.0.60:8081', 100); // healthy, never probed
            FederationTransportHealth::create([
                'server_id' => $peerId, 'transport' => 'https', 'url' => 'https://dead.test',
                'circuit_state' => FederationTransportHealth::CIRCUIT_OPEN,
                'consecutive_failures' => 3, 'last_fail_at' => now(),
            ]);
            Http::fake([
                '*dead.test*' => fn () => throw new ConnectionException('still down'),
                '*100.64.0.60*' => Http::response([], 200),
            ]);

            $probed = app(MultiplexClient::class)->probeUnhealthy($peerId);

            $this->assertSame(1, $probed, 'only the unhealthy rung is probed; the healthy tailnet is left alone');
            $this->assertSame(FederationTransportHealth::CIRCUIT_OPEN, $this->health($peerId, 'https', 'https://dead.test')->circuit_state);
            $this->assertSame(
                0,
                FederationTransportHealth::query()->where('server_id', $peerId)->where('transport', 'tailnet')->count(),
                'a healthy transport is not probed (no wasted dial)',
            );
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function trustedPeer(string $url): string
    {
        $peerId = (string) Str::uuid();
        FederationPeer::create([
            'server_id' => $peerId,
            'name' => 'Peer '.substr($peerId, 0, 8),
            'url' => $url,
            'public_key' => app(InstanceIdentityService::class)->publicKey(),
            'status' => FederationPeer::STATUS_TRUST_ESTABLISHED,
            'trust_established_at' => now(),
        ]);

        return $peerId;
    }

    private function transport(string $serverId, string $transport, string $address, int $priority): void
    {
        FederationTransport::create([
            'server_id' => $serverId,
            'transport' => $transport,
            'address' => $address,
            'is_self' => false,
            'priority' => $priority,
            'enabled' => true,
        ]);
    }

    private function health(string $serverId, string $transport, ?string $url = null): FederationTransportHealth
    {
        $q = FederationTransportHealth::query()
            ->where('server_id', $serverId)
            ->where('transport', $transport);

        if ($url !== null) {
            $q->where('url', $url);
        }

        return $q->firstOrFail();
    }

    private function seedHealth(string $serverId, string $transport, string $url, int $latency): void
    {
        FederationTransportHealth::create([
            'server_id' => $serverId,
            'transport' => $transport,
            'url' => $url,
            'latency_ema_ms' => $latency,
            'circuit_state' => FederationTransportHealth::CIRCUIT_CLOSED,
        ]);
    }

    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            app(InstanceIdentityService::class)->ensureIdentity();
            $body();
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
