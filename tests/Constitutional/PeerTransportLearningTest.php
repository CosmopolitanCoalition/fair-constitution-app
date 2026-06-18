<?php

namespace Tests\Constitutional;

use App\Http\Controllers\Federation\PeerController;
use App\Models\FederationTransport;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\PeerService;
use App\Services\Federation\TransportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G8b) transport learning. For the multiplex ladder to
 * have more than the legacy single url to fail over to, a peer's FULL transport set
 * must travel at discovery/handshake and be persisted. The pins:
 *   1. GET /identity advertises OUR enabled transports (not just one url);
 *   2. a peer's advertised transports are LEARNED (persisted as that server's rows,
 *      is_self = false) at discovery and at both sides of the handshake;
 *   3. an unknown transport label in an advert is ignored (defense in depth);
 *   4. BACK-COMPAT — a pre-G8b peer that advertises no transports is learned with
 *      none (the ladder falls back to its legacy url, unchanged).
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class PeerTransportLearningTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_peer_transport';

    public function test_identity_advertises_our_enabled_transports(): void
    {
        $this->onLivePg(function () {
            app(TransportService::class)->registerSelf('https', 'https://us.test', 200);
            app(TransportService::class)->registerSelf('onion', 'http://us.onion', 100);

            $data = app(PeerController::class)->identity()->getData(true);

            $this->assertArrayHasKey('transports', $data);
            $urls = array_column($data['transports'], 'url');
            $this->assertContains('https://us.test', $urls);
            $this->assertContains('http://us.onion', $urls);
            $this->assertSame('https://us.test', $data['transports'][0]['url'], 'best (highest priority) first');
        });
    }

    public function test_receive_handshake_learns_the_peer_transports_and_advertises_ours(): void
    {
        $this->onLivePg(function () {
            app(TransportService::class)->registerSelf('https', 'https://us.test', 200);
            $peerId = (string) Str::uuid();

            $response = app(PeerService::class)->receiveHandshake([
                'server_id' => $peerId,
                'public_key' => 'cGVlci1rZXk=', // any string — TOFU stores it verbatim
                'name' => 'Inbound peer',
                'url' => 'https://peer.test',
                'transports' => [
                    ['transport' => 'https', 'url' => 'https://peer.test', 'priority' => 200],
                    ['transport' => 'yggdrasil', 'url' => 'http://[200:abc::1]:8081', 'priority' => 150],
                    ['transport' => 'carrier_pigeon', 'url' => 'coop://nope'], // unknown → ignored
                ],
            ]);

            // (1) we advertised our own transports back.
            $this->assertContains('https://us.test', array_column($response['transports'], 'url'));

            // (2) the peer's transports are now learned as that server's rows.
            $learned = FederationTransport::query()->where('server_id', $peerId)->get();
            $this->assertEqualsCanonicalizing(['https', 'yggdrasil'], $learned->pluck('transport')->all(), 'unknown transport ignored');
            $this->assertFalse((bool) $learned->firstWhere('transport', 'https')->is_self);
            $this->assertSame('http://[200:abc::1]:8081', $learned->firstWhere('transport', 'yggdrasil')->address);
        });
    }

    public function test_discover_learns_a_peer_advertised_transports(): void
    {
        $this->onLivePg(function () {
            $peerId = (string) Str::uuid();
            Http::fake([
                '*peer-x.test*' => Http::response([
                    'server_id' => $peerId,
                    'public_key' => 'cGVlci1rZXk=',
                    'name' => 'Peer X',
                    'transports' => [
                        ['transport' => 'https', 'url' => 'https://peer-x.test', 'priority' => 200],
                        ['transport' => 'tailnet', 'url' => 'http://100.64.0.3:8081', 'priority' => 100],
                    ],
                ], 200),
            ]);

            app(PeerService::class)->discover('https://peer-x.test');

            $learned = FederationTransport::query()->where('server_id', $peerId)->pluck('transport')->all();
            $this->assertEqualsCanonicalizing(['https', 'tailnet'], $learned);
        });
    }

    public function test_a_pre_g8b_peer_advertising_no_transports_learns_none(): void
    {
        $this->onLivePg(function () {
            $peerId = (string) Str::uuid();
            Http::fake([
                '*legacy-peer.test*' => Http::response([
                    'server_id' => $peerId,
                    'public_key' => 'cGVlci1rZXk=',
                    'name' => 'Legacy peer',
                    // no 'transports' key at all
                ], 200),
            ]);

            app(PeerService::class)->discover('https://legacy-peer.test');

            $this->assertSame(0, FederationTransport::query()->where('server_id', $peerId)->count());
        });
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
