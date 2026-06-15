<?php

namespace Tests\Constitutional;

use App\Models\User;
use App\Services\Identity\ActorIdentityService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G-ID) device enrolment + action signing. A person
 * enrols a device key; the device signs actions; a leader verifies them against
 * the enrolled key. The pins:
 *  - enrolment is idempotent and stores ONLY the public key (no escrow);
 *  - a genuine device action signature verifies; any body tamper fails;
 *  - a revoked device's signatures fail closed.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class ActorEnrollmentTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_actor_enroll';

    public function test_enrolment_is_idempotent_and_stores_only_the_public_key(): void
    {
        $this->onLivePg(function () {
            $svc = app(ActorIdentityService::class);
            $user = User::factory()->create();
            [$pub] = $this->keypair();

            $d1 = $svc->enrollDevice($user, $pub, 'phone');
            $d2 = $svc->enrollDevice($user, $pub, 'phone again');

            $this->assertSame($d1->id, $d2->id, 'enrolment is idempotent on the device key');
            $this->assertSame($pub, $d1->device_public_key, 'only the PUBLIC key is stored (no escrow)');
        });
    }

    public function test_a_device_action_signature_verifies_and_tamper_or_revocation_fails(): void
    {
        $this->onLivePg(function () {
            $svc = app(ActorIdentityService::class);
            $user = User::factory()->create();
            [$pub, $secret] = $this->keypair();
            $device = $svc->enrollDevice($user, $pub);

            $signing = ActorIdentityService::actionSigningString('POST', '/api/federation/write', 1700000000, '{"x":1}');
            $sig = sodium_bin2base64(sodium_crypto_sign_detached($signing, $secret), SODIUM_BASE64_VARIANT_ORIGINAL);

            $this->assertTrue($svc->verifyActionSignature($device, $signing, $sig), 'a genuine device signature verifies');

            $tampered = ActorIdentityService::actionSigningString('POST', '/api/federation/write', 1700000000, '{"x":2}');
            $this->assertFalse($svc->verifyActionSignature($device, $tampered, $sig), 'a tampered body fails');

            $svc->revokeDevice($device);
            $this->assertFalse($svc->verifyActionSignature($device, $signing, $sig), 'a revoked device fails closed');
        });
    }

    /** @return array{0:string,1:string} [publicKeyB64, rawSecretBin] */
    private function keypair(): array
    {
        $kp = sodium_crypto_sign_keypair();

        return [
            sodium_bin2base64(sodium_crypto_sign_publickey($kp), SODIUM_BASE64_VARIANT_ORIGINAL),
            sodium_crypto_sign_secretkey($kp),
        ];
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
