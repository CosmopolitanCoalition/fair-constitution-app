<?php

namespace Tests\Constitutional;

use App\Http\Controllers\Identity\ActorIdentityController;
use App\Jobs\Identity\ExpireStandingAttestationsJob;
use App\Models\ActorDevice;
use App\Models\StandingAttestation;
use App\Models\User;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\AttestationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G-ID HTTP surface). The authenticated person manages
 * their OWN device keys and mints the attestation a client attaches to a forwarded
 * write. The pins:
 *  1. the routes are registered;
 *  2. enrolment stores only the device PUBLIC key, for the current user;
 *  3. an issued attestation is returned in the wire form and verifies against the
 *     issuer's key — only the home authority issues it (a peer-homed subject → 403);
 *  4. the expiry sweep prunes lapsed attestations and keeps live ones.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class ActorIdentitySurfaceTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_actor_surface';

    public function test_the_actor_routes_are_registered(): void
    {
        $this->assertTrue(Route::has('civic.actor.devices.enroll'));
        $this->assertTrue(Route::has('civic.actor.attestations.issue'));
    }

    public function test_enrolment_stores_only_the_public_key_for_the_current_user(): void
    {
        $this->onLivePg(function () {
            $user = $this->user();
            $devPub = $this->devicePublicKey();

            $response = app(ActorIdentityController::class)->enrollDevice(
                $this->request(['device_public_key' => $devPub, 'label' => 'phone'], $user)
            );

            $data = $response->getData(true);
            $this->assertArrayHasKey('device_id', $data);

            $device = ActorDevice::query()->find($data['device_id']);
            $this->assertNotNull($device);
            $this->assertSame((string) $user->getKey(), (string) $device->user_id);
            $this->assertSame($devPub, (string) $device->device_public_key);
        });
    }

    public function test_an_issued_attestation_is_returned_in_wire_form_and_verifies(): void
    {
        $this->onLivePg(function () {
            $identity = app(InstanceIdentityService::class);
            $identity->ensureIdentity();
            $user = $this->user(); // home_server_id null → we are home
            $devPub = $this->devicePublicKey();

            $response = app(ActorIdentityController::class)->issueAttestation(
                $this->request(['device_public_key' => $devPub, 'ttl_seconds' => 3600], $user)
            );

            $this->assertSame(200, $response->getStatusCode());
            $wire = $response->getData(true)['attestation'];

            $this->assertSame((string) $user->getKey(), $wire['subject_user_id']);
            $this->assertSame($devPub, $wire['device_public_key']);
            $this->assertSame($identity->serverId(), $wire['issuer_server_id']);
            $this->assertIsInt($wire['issued_at']);
            $this->assertIsInt($wire['expires_at']);

            // The wire attestation verifies against the issuer's key (reconstructed).
            $attestation = new StandingAttestation([
                'id' => $wire['id'],
                'subject_user_id' => $wire['subject_user_id'],
                'device_public_key' => $wire['device_public_key'],
                'issuer_server_id' => $wire['issuer_server_id'],
                'roles' => $wire['roles'],
                'issued_at' => CarbonImmutable::createFromTimestamp($wire['issued_at']),
                'expires_at' => CarbonImmutable::createFromTimestamp($wire['expires_at']),
            ]);
            $attestation->signature = $wire['signature'];

            $this->assertTrue(app(AttestationService::class)->verifyAttestation($attestation, $identity->publicKey()));
        });
    }

    public function test_only_the_home_authority_issues_an_attestation(): void
    {
        $this->onLivePg(function () {
            app(InstanceIdentityService::class)->ensureIdentity();

            // A subject whose home authority is a PEER — we must refuse.
            $foreign = $this->user();
            $foreign->forceFill(['home_server_id' => (string) Str::uuid()])->save();

            $response = app(ActorIdentityController::class)->issueAttestation(
                $this->request(['device_public_key' => $this->devicePublicKey()], $foreign)
            );

            $this->assertSame(403, $response->getStatusCode());
            $this->assertSame('not_home_authority', $response->getData(true)['error']);
        });
    }

    public function test_the_expiry_sweep_prunes_lapsed_attestations(): void
    {
        $this->onLivePg(function () {
            $user = $this->user();

            $lapsed = StandingAttestation::create([
                'subject_user_id' => (string) $user->getKey(),
                'device_public_key' => $this->devicePublicKey(),
                'issuer_server_id' => (string) Str::uuid(),
                'roles' => [],
                'issued_at' => now()->subHours(2),
                'expires_at' => now()->subHour(),
                'signature' => 'x',
            ]);
            $live = StandingAttestation::create([
                'subject_user_id' => (string) $user->getKey(),
                'device_public_key' => $this->devicePublicKey(),
                'issuer_server_id' => (string) Str::uuid(),
                'roles' => [],
                'issued_at' => now(),
                'expires_at' => now()->addHour(),
                'signature' => 'y',
            ]);

            $pruned = (new ExpireStandingAttestationsJob)->handle();

            $this->assertGreaterThanOrEqual(1, $pruned);
            $this->assertNull(StandingAttestation::query()->find($lapsed->id), 'a lapsed attestation is pruned');
            $this->assertNotNull(StandingAttestation::query()->find($live->id), 'a live attestation is kept');
        });
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Actor '.Str::uuid(),
            'email' => 'actor-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);
    }

    private function devicePublicKey(): string
    {
        return sodium_bin2base64(
            sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()),
            SODIUM_BASE64_VARIANT_ORIGINAL
        );
    }

    private function request(array $body, User $user): Request
    {
        $request = Request::create('/civic/actor', 'POST', $body);
        $request->setUserResolver(fn () => $user);

        return $request;
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
