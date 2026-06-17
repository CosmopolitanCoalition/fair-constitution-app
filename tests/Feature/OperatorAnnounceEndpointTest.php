<?php

namespace Tests\Feature;

use App\Models\MeshOperatorKey;
use App\Services\Federation\FederationClient;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\MeshOperatorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Phase G (G-OP-2) — the operator-identity gossip endpoint over HTTP. A pinned
 * peer POSTs a signed announce to /api/federation/operator/announce; the peer
 * signature passes VerifyPeerSignature, and the binding (signed by the peer) is
 * verified against the peer's pinned key and ingested. The full S2S path.
 *
 * Cross-instance in production (rig-gated like G-V2); here a simulated trusted
 * peer stands in for the second machine. Live-pg posture, one rolled-back txn.
 */
class OperatorAnnounceEndpointTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_op_announce';

    private static function b64(string $bin): string
    {
        return sodium_bin2base64($bin, SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    public function test_a_pinned_peer_announces_an_operator_identity_over_http(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $identity->setEnabled(true);

            $peer = $this->makeTrustedPeer();
            $mesh = app(MeshOperatorService::class);

            // A mesh identity the PEER minted, with a binding the peer signed.
            $meshId = (string) Str::uuid();
            $devKp = sodium_crypto_sign_keypair();
            $devPub = self::b64(sodium_crypto_sign_publickey($devKp));
            $boundAt = time();
            $bindingSig = self::b64(sodium_crypto_sign_detached(
                $mesh->canonicalBinding($meshId, $devPub, (string) $peer->server_id, $boundAt),
                $this->peerSecret
            ));

            $wire = [
                'mesh_operator_id'  => $meshId,
                'display_handle'    => 'remote-op',
                'genesis_server_id' => (string) $peer->server_id,
                'keys'              => [[
                    'device_public_key'  => $devPub,
                    'bound_by_server_id' => (string) $peer->server_id,
                    'bound_at'           => $boundAt,
                    'binding_signature'  => $bindingSig,
                ]],
            ];
            $body = json_encode($wire, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $target = '/api/federation/operator/announce';

            $response = $this->call(
                'POST',
                $target,
                server: $this->signedRequest('POST', $target, $body, (string) $peer->server_id),
                content: $body,
            );

            $response->assertStatus(200)->assertJsonPath('ingested', true)->assertJsonPath('mesh_operator_id', $meshId);

            $this->assertTrue(
                MeshOperatorKey::query()
                    ->where('mesh_operator_id', $meshId)
                    ->where('device_public_key', $devPub)
                    ->where('status', 'active')->exists(),
                'the peer-signed binding is ingested over the S2S endpoint'
            );
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($originalDefault);
        }
    }

    /** @return array<string,string> */
    private function signedRequest(string $method, string $target, string $body, string $serverId): array
    {
        $timestamp = now()->timestamp;
        $signingString = FederationClient::signingString($method, $target, $timestamp, $body);
        $signature = self::b64(sodium_crypto_sign_detached($signingString, $this->peerSecret));

        return [
            'HTTP_X_FEDERATION_SERVER_ID' => $serverId,
            'HTTP_X_FEDERATION_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_FEDERATION_SIGNATURE' => $signature,
            'CONTENT_TYPE'                => 'application/json',
            'HTTP_ACCEPT'                 => 'application/json',
        ];
    }
}
