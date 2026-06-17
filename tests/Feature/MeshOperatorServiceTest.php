<?php

namespace Tests\Feature;

use App\Models\MeshOperatorKey;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\MeshOperatorService;
use App\Services\Identity\OperatorIdentityService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Phase G (G-OP-2) — the MESH-SYNC half: mint a mesh identity, announce/ingest
 * key bindings (a peer-signed binding verifies against the PEER's pinned key; a
 * tampered one is dropped), link a traveling operator by POSSESSION PROOF (a
 * signature from an already-bound device, never a password), and revoke.
 *
 * Cross-instance in production (rig-gated like G-V2); here a simulated trusted
 * peer (FederationSyncSupport::makeTrustedPeer, whose secret the test holds)
 * stands in for the second machine. Live-pg posture, one rolled-back transaction.
 */
class MeshOperatorServiceTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_mesh_op';

    private static function b64(string $bin): string
    {
        return sodium_bin2base64($bin, SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    public function test_mint_announce_ingest_link_and_revoke(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $ops = app(OperatorIdentityService::class);
            $mesh = app(MeshOperatorService::class);

            // A local operator with one enrolled device.
            $account = $ops->register('opA', 'password1234');
            $kp = sodium_crypto_sign_keypair();
            $pub = self::b64(sodium_crypto_sign_publickey($kp));
            $sec = sodium_crypto_sign_secretkey($kp);
            $ops->enrollDevice($account, $pub, 'pi');

            // Flow A (genesis): mint the mesh identity — binds the device + links the account.
            $identity = $mesh->mintIdentity($account, 'operator-A');
            $this->assertSame($identity->id, $account->fresh()->mesh_operator_id);
            $this->assertSame(1, MeshOperatorKey::query()
                ->where('mesh_operator_id', $identity->id)->where('status', 'active')->count());

            // The announce wire carries the identity + its bindings.
            $wire = $mesh->announceWire($identity->id);
            $this->assertSame($identity->id, $wire['mesh_operator_id']);
            $this->assertCount(1, $wire['keys']);

            // A PEER-signed binding ingests + verifies against the peer's pinned key.
            $peer = $this->makeTrustedPeer(); // FederationPeer + $this->peerSecret = peer's instance key
            $peerDeviceKp = sodium_crypto_sign_keypair();
            $peerDevicePub = self::b64(sodium_crypto_sign_publickey($peerDeviceKp));
            $boundAt = time();
            $peerSig = self::b64(sodium_crypto_sign_detached(
                $mesh->canonicalBinding($identity->id, $peerDevicePub, (string) $peer->server_id, $boundAt),
                $this->peerSecret
            ));
            $peerWire = [
                'mesh_operator_id'  => $identity->id,
                'display_handle'    => 'operator-A',
                'genesis_server_id' => app(InstanceIdentityService::class)->serverId(),
                'keys'              => [[
                    'device_public_key'  => $peerDevicePub,
                    'bound_by_server_id' => (string) $peer->server_id,
                    'bound_at'           => $boundAt,
                    'binding_signature'  => $peerSig,
                ]],
            ];
            $mesh->ingestAnnounce($peerWire, $peer);
            $this->assertTrue(MeshOperatorKey::query()
                ->where('mesh_operator_id', $identity->id)->where('device_public_key', $peerDevicePub)
                ->where('status', 'active')->exists(), 'a peer-signed binding ingests + verifies');

            // A TAMPERED binding (signature no longer matches the canonical) is dropped.
            $otherPub = self::b64(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
            $tampered = $peerWire;
            $tampered['keys'][0]['device_public_key'] = $otherPub; // sig was over $peerDevicePub
            $mesh->ingestAnnounce($tampered, $peer);
            $this->assertFalse(MeshOperatorKey::query()->where('device_public_key', $otherPub)->exists(),
                'a tampered binding is rejected on ingest');

            // Flow B: a traveler's local account links by POSSESSION PROOF (the
            // original bound device signs the challenge for a new device key).
            $traveler = $ops->register('opA-traveler', 'password1234');
            $newKp = sodium_crypto_sign_keypair();
            $newPub = self::b64(sodium_crypto_sign_publickey($newKp));
            $ts = time();
            $proofSig = self::b64(sodium_crypto_sign_detached(
                $mesh->linkProofString($identity->id, $newPub, $ts), $sec // signed by the ORIGINAL bound device
            ));
            $mesh->linkByProof($traveler, $identity->id, $newPub, $ts, $proofSig);
            $this->assertSame($identity->id, $traveler->fresh()->mesh_operator_id, 'a valid possession proof links');
            $this->assertTrue(MeshOperatorKey::query()->where('device_public_key', $newPub)->where('status', 'active')->exists());

            // A bad proof is refused (fails closed).
            $threw = false;
            try {
                $mesh->linkByProof($traveler, $identity->id, self::b64(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())), time(), 'AA');
            } catch (\RuntimeException) {
                $threw = true;
            }
            $this->assertTrue($threw, 'an invalid possession proof is refused');

            // Revocation flips the binding to revoked.
            $binding = MeshOperatorKey::query()->where('device_public_key', $newPub)->first();
            $mesh->revokeKey($binding);
            $this->assertSame('revoked', $binding->fresh()->status);

            sodium_memzero($sec);
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
