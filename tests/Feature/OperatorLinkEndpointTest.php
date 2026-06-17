<?php

namespace Tests\Feature;

use App\Models\MeshOperatorIdentity;
use App\Models\MeshOperatorKey;
use App\Models\MeshOperatorLocalLink;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\MeshOperatorService;
use App\Services\Identity\OperatorIdentityService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\FederationSyncSupport;
use Tests\TestCase;

/**
 * Phase G (G-OP / G3c) — Flow B: a traveling operator links a local account on the
 * ARRIVING instance to an EXISTING mesh identity by device-possession proof. Pins:
 * a valid proof (signed by an already-bound device of the mesh identity) links the
 * local account + binds the new device; a forged proof is refused (fails closed);
 * the password is never involved. Web action under auth:operator (the proof string
 * targets POST /operator/link).
 *
 * Live-pg posture; the "home" device that proves possession is simulated by a
 * keypair the test holds, bound to the mesh identity as if learned via gossip.
 */
class OperatorLinkEndpointTest extends TestCase
{
    use FederationSyncSupport;

    private const LIVE_CONNECTION = 'pgsql_operator_link';

    public function test_a_valid_possession_proof_links_the_account_and_a_forged_one_is_refused(): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        $this->withoutMiddleware(ValidateCsrfToken::class);

        try {
            app(InstanceIdentityService::class)->ensureIdentity();
            $mesh = app(MeshOperatorService::class);
            $operators = app(OperatorIdentityService::class);

            // An existing mesh identity M with an active "home" device binding —
            // simulating what this instance learned via the Flow-A announce gossip.
            $homeKeypair = sodium_crypto_sign_keypair();
            $homeSecret = sodium_crypto_sign_secretkey($homeKeypair);
            $homePublic = sodium_bin2base64(sodium_crypto_sign_publickey($homeKeypair), SODIUM_BASE64_VARIANT_ORIGINAL);

            $identity = MeshOperatorIdentity::create([
                'display_handle'    => 'Traveler '.Str::random(4),
                'genesis_server_id' => (string) Str::uuid(),
            ]);
            $meshId = (string) $identity->id;
            $mesh->bindKey($meshId, $homePublic); // active binding (the proving device)

            // A fresh LOCAL operator account on THIS (arriving) instance + its new device.
            $account = $operators->register('traveler_'.Str::lower(Str::random(6)), 'correct horse battery');
            $newPublic = sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_ORIGINAL);
            $operators->enrollDevice($account, $newPublic, 'arriving-instance device');

            $this->assertNull($account->fresh()->mesh_operator_id, 'account starts unlinked');

            // The possession proof: the HOME device signs the link challenge (bound
            // to THIS instance + the new device key). Byte-built from linkProofString.
            $ts = now()->timestamp;
            $canonical = $mesh->linkProofString($meshId, $newPublic, $ts);
            $proof = sodium_bin2base64(sodium_crypto_sign_detached($canonical, $homeSecret), SODIUM_BASE64_VARIANT_ORIGINAL);

            // A FORGED proof (a different key) is refused — nothing links.
            $forgedKeypair = sodium_crypto_sign_keypair();
            $forged = sodium_bin2base64(
                sodium_crypto_sign_detached($canonical, sodium_crypto_sign_secretkey($forgedKeypair)),
                SODIUM_BASE64_VARIANT_ORIGINAL,
            );

            $bad = $this->actingAs($account, 'operator')->post('/operator/link', [
                'mesh_operator_id'      => $meshId,
                'new_device_public_key' => $newPublic,
                'timestamp'             => $ts,
                'proof_signature_b64'   => $forged,
            ]);
            $bad->assertSessionHasErrors('link');
            $this->assertNull($account->fresh()->mesh_operator_id, 'a forged proof links nothing');

            // The VALID proof links the account + binds the new device to M.
            $ok = $this->actingAs($account, 'operator')->post('/operator/link', [
                'mesh_operator_id'      => $meshId,
                'new_device_public_key' => $newPublic,
                'timestamp'             => $ts,
                'proof_signature_b64'   => $proof,
            ]);
            $ok->assertSessionHasNoErrors();
            $ok->assertSessionHas('status');

            $this->assertSame($meshId, $account->fresh()->mesh_operator_id, 'the account is now linked to M');
            $this->assertTrue(
                MeshOperatorLocalLink::query()
                    ->where('operator_account_id', $account->id)
                    ->where('mesh_operator_id', $meshId)->exists(),
                'a local↔mesh link row is written',
            );
            $this->assertTrue(
                MeshOperatorKey::query()
                    ->where('mesh_operator_id', $meshId)
                    ->where('device_public_key', $newPublic)
                    ->where('status', MeshOperatorKey::STATUS_ACTIVE)->exists(),
                'the new device is bound to the mesh identity',
            );
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
