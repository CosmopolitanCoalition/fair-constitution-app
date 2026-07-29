<?php

namespace Tests\Feature;

use App\Models\FederationPeer;
use App\Services\Federation\FederationClient;
use App\Services\Federation\InstanceIdentityService;
use App\Support\GameMode;
use App\Support\InstanceClass;
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

            // This box is founded scale_demo; the faked peer declares no class
            // (reads as production), so class-scoped federation would refuse the
            // handshake. Present the box as production to match — the same seam
            // e3df1ba used for the sibling fixtures. This test pins the handshake
            // + signature mechanism, not class-scoping (ClassScopedFederationTest
            // owns that).
            InstanceClass::override(InstanceClass::PRODUCTION);

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
            InstanceClass::flush();
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
        }
    }

    /**
     * WAVE 4 ② — a cross-class handshake is a lawful POLICY refusal (a demo introduced itself to a
     * real node), not our fault. It must answer 409 Conflict, never the RuntimeException→500 it did
     * before, so the initiator learns "declined, wrong class" instead of reading us as broken.
     */
    public function test_a_cross_class_handshake_is_refused_with_409_not_500(): void
    {
        $this->onLivePgHandshake(
            InstanceClass::SCALE_DEMO,
            ['instance_class' => InstanceClass::PRODUCTION],
            function ($resp, string $peerId) {
                $resp->assertStatus(409);
                $this->assertSame('cross_class_peering_refused', $resp->json('error'));
                $this->assertSame(InstanceClass::SCALE_DEMO, $resp->json('our_class'));
                $this->assertSame(InstanceClass::PRODUCTION, $resp->json('peer_class'));
                $this->assertNull(
                    FederationPeer::query()->where('server_id', $peerId)->first(),
                    'a refused cross-class peer is never pinned'
                );
            }
        );
    }

    /**
     * WAVE 4 ③ (game_mode field) — the peer's declared class + mode ride the SIGNED body, and the
     * handshake validator must WHITELIST them or validate() strips them before receiveHandshake()
     * ever sees them. The discriminating proof: a demo box accepts a peer that DECLARES scale_demo.
     * If instance_class were stripped it would read null→production and this same box would 409 it.
     */
    public function test_a_matching_demo_peer_succeeds_proving_class_and_mode_survive_validation(): void
    {
        $this->onLivePgHandshake(
            InstanceClass::SCALE_DEMO,
            ['instance_class' => InstanceClass::SCALE_DEMO, 'game_mode' => GameMode::SANDBOX],
            function ($resp, string $peerId) {
                $resp->assertStatus(200);
                $peer = FederationPeer::query()->where('server_id', $peerId)->first();
                $this->assertNotNull($peer, 'a matching-class peer is pinned');
                $this->assertSame(
                    InstanceClass::SCALE_DEMO,
                    $peer->metadata['instance_class'] ?? null,
                    'instance_class survived validate() into receiveHandshake — else a demo box would have refused'
                );
                $this->assertSame(
                    GameMode::SANDBOX,
                    $peer->metadata['game_mode'] ?? null,
                    'game_mode survived validate() too — it feeds the demo-mesh time rail (demoPeerCount)'
                );
            }
        );
    }

    /**
     * Run a signed handshake with THIS box presenting $ourClass and the peer body carrying
     * $extraBody, inside a rolled-back live-pg transaction. The assertions run inside the txn (the
     * peer row lives only there). Mirrors the primary test's live-pg posture + signed-header path.
     *
     * @param  array<string,mixed>  $extraBody
     * @param  callable(\Illuminate\Testing\TestResponse, string): void  $assert
     */
    private function onLivePgHandshake(string $ourClass, array $extraBody, callable $assert): void
    {
        $conn = $this->livePg();
        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $identity->setEnabled(true);
            InstanceClass::override($ourClass);

            $peerKeypair = sodium_crypto_sign_keypair();
            $peerSecret = sodium_crypto_sign_secretkey($peerKeypair);
            $peerPublicB64 = sodium_bin2base64(sodium_crypto_sign_publickey($peerKeypair), SODIUM_BASE64_VARIANT_ORIGINAL);
            $peerServerId = (string) Str::uuid();

            $body = json_encode(array_merge([
                'server_id' => $peerServerId,
                'public_key' => $peerPublicB64,
                'name' => 'Peer (class test)',
                'url' => 'http://host.docker.internal:9998',
                'schema_version' => '1',
            ], $extraBody), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $resp = $this->call(
                'POST',
                '/api/federation/handshake',
                server: $this->signedHeaders('POST', '/api/federation/handshake', $body, $peerServerId, $peerSecret),
                content: $body,
            );

            $assert($resp, $peerServerId);
        } finally {
            InstanceClass::flush();
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
