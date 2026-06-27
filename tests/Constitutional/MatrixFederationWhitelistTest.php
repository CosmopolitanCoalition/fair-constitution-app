<?php

namespace Tests\Constitutional;

use App\Models\FederationPeer;
use App\Services\Matrix\MatrixFederationGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Matrix federation whitelist (Phase 5 / K3-E, S2S peer population). The
 * federation_domain_whitelist the homeserver runs is the LOCAL server PLUS every TRUSTED mesh peer's Matrix
 * server_name — the SAME peers that mirror our records may federate our rooms (design §6.4). A peer's
 * Matrix domain comes from its signed handshake claim (metadata), else the host of its federation url. THE
 * INVARIANTS: the local server is ALWAYS present; non-trusted peers are EXCLUDED; a scale_demo instance
 * federates with no one (empty); duplicates collapse.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class MatrixFederationWhitelistTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_matrixfed';

    public function test_whitelist_is_local_plus_trusted_peer_matrix_domains(): void
    {
        $this->onLivePg(function () {
            config(['matrix.server_name' => 'boxa.example']);
            $gate = app(MatrixFederationGateService::class);

            // A trusted peer that ADVERTISED its Matrix domain at handshake (metadata is authoritative).
            $this->peer(FederationPeer::STATUS_TRUST_ESTABLISHED, 'https://b-app.example:8448', 'boxb.example');
            // A trusted peer with NO advertised domain → derives from the url host.
            $this->peer(FederationPeer::STATUS_TRUST_ESTABLISHED, 'https://boxc.example', null);
            // A NON-trusted (merely discovered) peer → excluded, even though it names a domain.
            $this->peer(FederationPeer::STATUS_DISCOVERED, 'https://evil.example', 'evil.example');

            // Contains/not-contains, not exact-equality: this live pg may hold OTHER real trusted peers, so
            // the invariants are membership, not the whole set.
            $wl = $gate->desiredFederationWhitelist();

            $this->assertContains('boxa.example', $wl, 'the local server is always present');
            $this->assertContains('boxb.example', $wl, 'a trusted peer\'s ADVERTISED Matrix domain is included');
            $this->assertContains('boxc.example', $wl, 'a trusted peer\'s domain derives from its url host when unadvertised');
            $this->assertNotContains('evil.example', $wl, 'a non-trusted peer never enters the whitelist');
            $this->assertSame(count($wl), count(array_unique($wl)), 'no duplicate domains');

            // A scale_demo instance has no consent to federate — whitelist is empty (CI-2).
            $this->assertSame([], $gate->desiredFederationWhitelist(scaleDemo: true));
        });
    }

    public function test_a_duplicate_or_self_named_peer_domain_collapses(): void
    {
        $this->onLivePg(function () {
            config(['matrix.server_name' => 'boxa.example']);
            // Two trusted peers naming the SAME Matrix domain, plus one naming the local server.
            $this->peer(FederationPeer::STATUS_TRUST_ESTABLISHED, 'https://x1.example', 'shared.example');
            $this->peer(FederationPeer::STATUS_TRUST_ESTABLISHED, 'https://x2.example', 'shared.example');
            $this->peer(FederationPeer::STATUS_TRUST_ESTABLISHED, 'https://x3.example', 'boxa.example');

            $wl = app(MatrixFederationGateService::class)->desiredFederationWhitelist();

            $this->assertSame(count($wl), count(array_unique($wl)), 'no duplicate domains');
            $this->assertContains('shared.example', $wl);
            $this->assertSame(1, array_sum(array_map(fn ($d) => $d === 'boxa.example' ? 1 : 0, $wl)), 'local appears once');
        });
    }

    private function peer(string $status, string $url, ?string $matrixDomain): FederationPeer
    {
        return FederationPeer::create([
            'server_id' => (string) Str::uuid(),
            'name' => 'Peer '.Str::random(4),
            'url' => $url,
            'public_key' => 'k-'.Str::random(8),
            'status' => $status,
            'trust_established_at' => $status === FederationPeer::STATUS_TRUST_ESTABLISHED ? now() : null,
            'metadata' => $matrixDomain !== null
                ? ['schema_version' => '1', 'matrix_server_name' => $matrixDomain]
                : ['schema_version' => '1'],
        ]);
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
