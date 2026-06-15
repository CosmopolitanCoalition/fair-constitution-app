<?php

namespace Tests\Constitutional;

use App\Models\AttestationRevocation;
use App\Models\StandingAttestation;
use App\Models\User;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\AttestationGate;
use App\Services\Identity\AttestationRefused;
use App\Services\Identity\AttestationService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G-ID) attestation integrity. The CA issues a
 * short-lived, instance-signed snapshot of DERIVED standing. The pins:
 *  1. a genuine attestation verifies; ANY field mutation breaks the signature;
 *  2. a foreign issuer key fails; expiry + revocation fail CLOSED;
 *  3. only the HOME authority attests (a peer-homed subject is refused);
 *  4. Art. I — the gate NEVER short-circuits local derivation (gate roles ==
 *     RoleService roles); the attestation merely SNAPSHOTS them;
 *  5. PRIVACY — the attestation tables carry no credential/location/ballot column.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class AttestationIntegrityTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_attestation';

    public function test_a_genuine_attestation_verifies_and_any_mutation_or_foreign_issuer_fails(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $svc = app(AttestationService::class);

            $att = $svc->issue($this->localUser(), 'device-pub-key-b64', 3600);

            $this->assertTrue($svc->verifyAttestation($att, $identity->publicKey()), 'a genuine attestation verifies');

            $foreignPub = sodium_bin2base64(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()), SODIUM_BASE64_VARIANT_ORIGINAL);
            $this->assertFalse($svc->verifyAttestation($att, $foreignPub), 'a foreign issuer key is refused');

            $att->roles = ['R-99'];
            $this->assertFalse($svc->verifyAttestation($att, $identity->publicKey()), 'a mutated roles snapshot fails');
        });
    }

    public function test_expiry_and_revocation_fail_closed(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $svc = app(AttestationService::class);
            $user = $this->localUser();

            // A genuinely-signed but PAST attestation must still fail closed.
            $expired = new StandingAttestation([
                'id' => (string) Str::uuid(),
                'subject_user_id' => (string) $user->getKey(),
                'device_public_key' => 'dpk',
                'issuer_server_id' => $identity->serverId(),
                'roles' => ['R-01'],
                'issued_at' => now()->subHours(2),
                'expires_at' => now()->subHour(),
            ]);
            $expired->signature = $identity->sign($svc->attestationCanonical($expired));
            $expired->save();
            $this->assertFalse($svc->verifyAttestation($expired, $identity->publicKey()), 'an expired attestation fails closed');

            $live = $svc->issue($user, 'dpk2', 3600);
            $this->assertTrue($svc->verifyAttestation($live, $identity->publicKey()));
            $svc->revoke($live, 'relocation');
            $this->assertFalse($svc->verifyAttestation($live, $identity->publicKey()), 'a revoked attestation fails closed');

            // Revoke is idempotent (one CRL entry).
            $svc->revoke($live, 'again');
            $this->assertSame(1, AttestationRevocation::query()->where('attestation_id', $live->id)->count());
        });
    }

    public function test_only_the_home_authority_attests(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();

            $peerHomed = $this->localUser();
            $peerHomed->home_server_id = (string) Str::uuid(); // a home that is NOT us
            $peerHomed->save();

            $this->expectException(AttestationRefused::class);
            app(AttestationService::class)->issue($peerHomed, 'dpk');
        });
    }

    public function test_the_gate_never_short_circuits_local_derivation(): void
    {
        $this->onLivePg(function () {
            $user = $this->localUser();

            $this->assertSame(
                app(RoleService::class)->rolesFor($user),
                app(AttestationGate::class)->rolesFor($user),
                'Art. I — the gate derives local roles live, never a stored snapshot'
            );
        });
    }

    public function test_the_attestation_tables_carry_no_private_columns(): void
    {
        $this->onLivePg(function () {
            $forbidden = '/password|credential|secret|ballot|rankings|choice|location|ping|latitude|longitude|coord/i';

            foreach (['standing_attestations', 'attestation_revocations'] as $table) {
                foreach (DB::getSchemaBuilder()->getColumnListing($table) as $col) {
                    $this->assertDoesNotMatchRegularExpression($forbidden, $col,
                        "the {$table} table must carry no private column ({$col})");
                }
            }
        });
    }

    private function localUser(): User
    {
        return User::factory()->create(['home_server_id' => null]);
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
