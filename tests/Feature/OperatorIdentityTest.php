<?php

namespace Tests\Feature;

use App\Models\OperatorAccount;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\OperatorIdentityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Phase G (G-OP) — the LOCAL operator plane end-to-end: register, authenticate,
 * enrol a device, and revoke. Pins the security invariants: the password is
 * hashed at rest, never serializes, and auth fails closed; device auth is by
 * KEY POSSESSION (signature), never the password, and a revoked device stops
 * verifying.
 *
 * Live-pg posture (ClusterAuthoritySeparationTest): guarded connection set as
 * default, one transaction always rolled back.
 */
class OperatorIdentityTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_operator_id';

    public function test_register_authenticate_enroll_and_revoke_with_key_possession(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();
            $svc = app(OperatorIdentityService::class);

            $account = $svc->register('opnode1', 'correct horse battery');
            $this->assertSame(OperatorAccount::STATUS_ACTIVE, $account->status);

            // Password is hashed at rest and NEVER serializes (it must never federate).
            $this->assertNotSame('correct horse battery', (string) $account->password);
            $this->assertTrue(Hash::check('correct horse battery', (string) $account->password));
            $this->assertArrayNotHasKey('password', $account->toArray(), 'operator password must never serialize');

            // Local auth: right password works (stamps last_login_at); wrong / unknown fail closed.
            $this->assertNotNull($svc->authenticate('opnode1', 'correct horse battery'));
            $this->assertNull($svc->authenticate('opnode1', 'wrong'));
            $this->assertNull($svc->authenticate('ghost', 'whatever'));

            // Device enrol is idempotent on the public key.
            $kp = sodium_crypto_sign_keypair();
            $pub = sodium_bin2base64(sodium_crypto_sign_publickey($kp), SODIUM_BASE64_VARIANT_ORIGINAL);
            $sec = sodium_crypto_sign_secretkey($kp);

            $d1 = $svc->enrollDevice($account, $pub, 'pi');
            $d2 = $svc->enrollDevice($account, $pub, 'pi');
            $this->assertSame($d1->id, $d2->id, 'enrol is idempotent on the public key');

            // Auth is by KEY POSSESSION (the signature), never the password.
            $ts = 1_700_000_000;
            $signing = OperatorIdentityService::actionSigningString('POST', '/operator/consent', $ts, 'body-bytes');
            $sig = sodium_bin2base64(sodium_crypto_sign_detached($signing, $sec), SODIUM_BASE64_VARIANT_ORIGINAL);

            $this->assertTrue($svc->verifyActionSignature($d1->fresh(), $signing, $sig), 'a valid device signature verifies');
            $this->assertFalse($svc->verifyActionSignature($d1->fresh(), $signing, 'AA'), 'a bad signature is refused');

            // Revocation fails closed.
            $svc->revokeDevice($d1);
            $this->assertFalse($svc->verifyActionSignature($d1->fresh(), $signing, $sig), 'a revoked device fails closed');

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
