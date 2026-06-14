<?php

namespace Tests\Feature;

use App\Models\FederationPeer;
use App\Services\Federation\FederationClient;
use App\Services\Federation\InstanceIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F1 peer mesh — the server-to-server handshake + signature middleware.
 *
 * A peer learns our identity (GET /identity), introduces itself with a signed
 * handshake (TOFU pins its key, both reach trust_established), and thereafter
 * authenticates every request by Ed25519 signature. Federation OFF 404s the
 * endpoints; a forged signature 401s; an unknown peer on a pinned route 401s.
 *
 * Live-pg posture (PhaseDPageSmokeTest): guarded connection set as default so
 * the HTTP requests share it, one transaction always rolled back.
 */
class FederationHandshakeTest extends TestCase
{
    private const LIVE_CONNECTION = 'pgsql_federation_handshake';

    public function test_identity_handshake_and_signature_enforcement(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $identity->setEnabled(true);

            $ourServerId = $identity->serverId();

            // ── GET /identity (public) returns our advertised identity ───────
            $identityResponse = $this->getJson('/api/federation/identity');
            $identityResponse->assertStatus(200)
                ->assertJsonPath('server_id', $ourServerId)
                ->assertJsonPath('public_key', $identity->publicKey());

            // ── A fake peer introduces itself with a signed handshake ────────
            $peerKeypair = sodium_crypto_sign_keypair();
            $peerSecret = sodium_crypto_sign_secretkey($peerKeypair);
            $peerPublicB64 = sodium_bin2base64(sodium_crypto_sign_publickey($peerKeypair), SODIUM_BASE64_VARIANT_ORIGINAL);
            $peerServerId = (string) Str::uuid();

            $body = json_encode([
                'server_id' => $peerServerId,
                'public_key' => $peerPublicB64,
                'name' => 'Peer B (test)',
                'url' => 'http://host.docker.internal:9999',
                'schema_version' => '1',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $handshake = $this->call(
                'POST',
                '/api/federation/handshake',
                server: $this->signedHeaders('POST', '/api/federation/handshake', $body, $peerServerId, $peerSecret),
                content: $body,
            );

            $handshake->assertStatus(200);
            $this->assertSame($ourServerId, $handshake->json('server_id'), 'handshake returns OUR identity');

            $peer = FederationPeer::query()->where('server_id', $peerServerId)->first();
            $this->assertNotNull($peer, 'the peer row is created');
            $this->assertSame(FederationPeer::STATUS_TRUST_ESTABLISHED, $peer->status, 'TOFU promotes to trust_established');
            $this->assertSame($peerPublicB64, $peer->public_key, 'the peer key is pinned');
            $this->assertNotNull($peer->trust_established_at);

            // The handshake is on the audit chain.
            $this->assertTrue(
                $conn->table('audit_log')->where('event', 'peer.trust_established')
                    ->where('payload->peer_server_id', $peerServerId)->exists(),
                'trust_established is chained'
            );

            // ── A forged handshake signature is rejected (401) ───────────────
            $forged = $this->call(
                'POST',
                '/api/federation/handshake',
                server: array_merge(
                    $this->signedHeaders('POST', '/api/federation/handshake', $body, $peerServerId, $peerSecret),
                    ['HTTP_X_FEDERATION_SIGNATURE' => sodium_bin2base64(random_bytes(SODIUM_CRYPTO_SIGN_BYTES), SODIUM_BASE64_VARIANT_ORIGINAL)],
                ),
                content: $body,
            );
            $forged->assertStatus(401);

            // ── A pinned heartbeat from the established peer succeeds ─────────
            $hbBody = '';
            $heartbeat = $this->call(
                'POST',
                '/api/federation/heartbeat',
                server: $this->signedHeaders('POST', '/api/federation/heartbeat', $hbBody, $peerServerId, $peerSecret),
                content: $hbBody,
            );
            $heartbeat->assertStatus(200)->assertJsonPath('ok', true);
            $this->assertNotNull($peer->refresh()->last_heartbeat_at, 'the heartbeat is recorded');

            // ── A pinned heartbeat from an UNKNOWN peer is rejected (401) ─────
            $strangerKeypair = sodium_crypto_sign_keypair();
            $strangerSecret = sodium_crypto_sign_secretkey($strangerKeypair);
            $stranger = $this->call(
                'POST',
                '/api/federation/heartbeat',
                server: $this->signedHeaders('POST', '/api/federation/heartbeat', $hbBody, (string) Str::uuid(), $strangerSecret),
                content: $hbBody,
            );
            $stranger->assertStatus(401);

            // ── Federation OFF ⇒ the endpoints 404 (off ≡ absent) ────────────
            $identity->setEnabled(false);
            $this->getJson('/api/federation/identity')->assertStatus(404);
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
        }
    }

    /**
     * Build the X-Federation-* header set (in $server form) for a signed peer
     * request, using the same canonical string the middleware reconstructs.
     *
     * @return array<string,string>
     */
    private function signedHeaders(string $method, string $target, string $body, string $serverId, string $secretKey): array
    {
        $timestamp = now()->timestamp;
        $signingString = FederationClient::signingString($method, $target, $timestamp, $body);
        $signature = sodium_bin2base64(sodium_crypto_sign_detached($signingString, $secretKey), SODIUM_BASE64_VARIANT_ORIGINAL);

        return [
            'HTTP_X_FEDERATION_SERVER_ID' => $serverId,
            'HTTP_X_FEDERATION_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_FEDERATION_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];
    }

    private function livePg(): \Illuminate\Database\Connection
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql not loaded — live pins run inside the app container.');
        }

        config([
            'database.connections.'.self::LIVE_CONNECTION => array_merge(
                config('database.connections.pgsql'),
                ['database' => env('LIVE_PG_DATABASE', 'fair_constitution')]
            ),
        ]);

        try {
            $connection = DB::connection(self::LIVE_CONNECTION);
            $connection->getPdo();

            return $connection;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Live PostgreSQL unreachable — run inside the app container. ('.$e->getMessage().')');
        }
    }
}
