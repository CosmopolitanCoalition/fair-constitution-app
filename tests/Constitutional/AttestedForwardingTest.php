<?php

namespace Tests\Constitutional;

use App\Domain\Engine\AttestedForwardedActor;
use App\Models\FederationPeer;
use App\Models\ForwardedWrite;
use App\Models\StandingAttestation;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Federation\ForwardedWriteRefused;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Federation\WriteRouterService;
use App\Services\Identity\ActorIdentityService;
use App\Services\Identity\AttestationService;
use App\Services\Identity\AttestationGate;
use App\Services\Identity\AttestedActorContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Phase G (G-ID). A FORWARDED citizen write files as that
 * person only when THREE independent checks pass: a home-signed attestation of
 * their standing, the subject's DEVICE signature over THIS exact write, and a
 * local subject. The leader then authorizes against the ATTESTED role snapshot
 * (it holds no residency facts). The pins:
 *  1. a valid attested forward resolves the subject and the engine sees the
 *     ATTESTED roles (not a live re-derivation);
 *  2. it fails closed on expiry, revocation, attestation tampering, or a bad/forged
 *     device action signature;
 *  3. a bare system forward still resolves to null (strict superset);
 *  4. the attested context authorizes exactly one filing and is cleared after.
 *
 * If an edit breaks these, the edit is the violation — fix the edit, not the test.
 */
class AttestedForwardingTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'pgsql_attested_forward';

    private const VARIANT = SODIUM_BASE64_VARIANT_ORIGINAL;

    public function test_a_valid_attested_forward_resolves_the_subject_with_attested_roles(): void
    {
        $this->onLivePg(function () {
            [$subject, $devPub, $devSec] = $this->subjectWithDevice();

            // A sentinel role the live derivation NEVER produces — proves the engine
            // reads the attested snapshot, not a re-derivation.
            $att = $this->signedAttestation($subject, $devPub, ['R-99-ATTESTED'], CarbonImmutable::now()->addHour());
            $envelope = $this->forwardEnvelope($att, $devSec, 'F-LEG-003', ['note' => 'forwarded by a citizen']);

            $resolved = app(AttestedForwardedActor::class)->resolve($envelope);

            $this->assertNotNull($resolved);
            $this->assertSame((string) $subject->getKey(), (string) $resolved->getKey());
            $this->assertSame(['R-99-ATTESTED'], app(AttestationGate::class)->rolesFor($subject),
                'the engine authorizes the forwarded write against the ATTESTED roles');

            // A different (local) user is unaffected — still live-derived.
            [$other] = $this->subjectWithDevice();
            $this->assertNotSame(['R-99-ATTESTED'], app(AttestationGate::class)->rolesFor($other));
        });
    }

    public function test_an_expired_attestation_is_refused(): void
    {
        $this->onLivePg(function () {
            [$subject, $devPub, $devSec] = $this->subjectWithDevice();
            $att = $this->signedAttestation($subject, $devPub, ['R-04'], CarbonImmutable::now()->subMinute());
            $envelope = $this->forwardEnvelope($att, $devSec, 'F-LEG-003', ['note' => 'stale']);

            $this->assertRefused($envelope, 403);
        });
    }

    public function test_a_revoked_attestation_is_refused(): void
    {
        $this->onLivePg(function () {
            [$subject, $devPub, $devSec] = $this->subjectWithDevice();

            // A genuine, PERSISTED attestation — then revoked (the leader holds the CRL).
            $att = app(AttestationService::class)->issue($subject, $devPub, 3600);
            app(AttestationService::class)->revoke($att, 'device lost');

            $envelope = $this->forwardEnvelope($att, $devSec, 'F-LEG-003', ['note' => 'revoked']);
            $this->assertRefused($envelope, 403);
        });
    }

    public function test_a_tampered_attestation_is_refused(): void
    {
        $this->onLivePg(function () {
            [$subject, $devPub, $devSec] = $this->subjectWithDevice();
            $att = $this->signedAttestation($subject, $devPub, ['R-04'], CarbonImmutable::now()->addHour());
            $envelope = $this->forwardEnvelope($att, $devSec, 'F-LEG-003', ['note' => 'tampered']);

            // Escalate the roles AFTER signing — the canonical no longer matches.
            $envelope['actor']['attestation']['roles'] = ['R-04', 'R-99-FORGED'];

            $this->assertRefused($envelope, 403);
        });
    }

    public function test_a_forged_action_signature_is_refused(): void
    {
        $this->onLivePg(function () {
            [$subject, $devPub, $devSec] = $this->subjectWithDevice();
            $att = $this->signedAttestation($subject, $devPub, ['R-04'], CarbonImmutable::now()->addHour());
            $envelope = $this->forwardEnvelope($att, $devSec, 'F-LEG-003', ['note' => 'real']);

            // A signature over a DIFFERENT body — the device never authorized THIS write.
            $wrong = ActorIdentityService::actionSigningString('POST', '/actor/write', (int) $envelope['actor']['timestamp'], 'a different action');
            $envelope['actor']['action_signature'] = sodium_bin2base64(sodium_crypto_sign_detached($wrong, $devSec), self::VARIANT);

            $this->assertRefused($envelope, 403);
        });
    }

    public function test_a_bare_system_forward_resolves_to_null(): void
    {
        $this->onLivePg(function () {
            $resolved = app(AttestedForwardedActor::class)->resolve(['form_id' => 'F-LEG-003', 'payload' => ['note' => 'system']]);
            $this->assertNull($resolved, 'a forward with no actor block files as the system');
        });
    }

    public function test_end_to_end_a_citizen_forward_reaches_the_engine_and_clears_the_context(): void
    {
        $this->onLivePg(function () {
            [$subject, $devPub, $devSec] = $this->subjectWithDevice();
            $att = $this->signedAttestation($subject, $devPub, ['R-04'], CarbonImmutable::now()->addHour());

            // System-scoped (no jurisdiction → we are authoritative locally).
            $envelope = $this->forwardEnvelope($att, $devSec, 'F-LEG-003', ['note' => 'a forwarded citizen filing']);
            $envelope['origin_server_id'] = (string) Str::uuid();
            $envelope['idempotency_key'] = 'k-'.Str::uuid();

            $peer = FederationPeer::create([
                'server_id' => $envelope['origin_server_id'],
                'name' => 'forwarder',
                'url' => 'https://forwarder.test',
                'public_key' => base64_encode(str_repeat('k', 32)),
                'status' => FederationPeer::STATUS_TRUST_ESTABLISHED,
                'relation' => FederationPeer::RELATION_SOVEREIGN,
            ]);

            // It REACHES the engine (no 403 refusal) and settles — vs the pre-G-ID
            // SystemOnlyForwardedActor, which would have refused a citizen claim.
            $outcome = app(WriteRouterService::class)->executeForwarded($envelope, $peer);

            $this->assertContains($outcome['status'], [ForwardedWrite::STATUS_EXECUTED, ForwardedWrite::STATUS_REJECTED]);
            $this->assertSame(1, ForwardedWrite::query()->where('idempotency_key', $envelope['idempotency_key'])->count());

            // The attested context did not leak past the one filing.
            $this->assertNull(app(AttestedActorContext::class)->attestedRolesFor($subject),
                'the attested context is cleared after the forwarded write');
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function assertRefused(array $envelope, int $status): void
    {
        try {
            app(AttestedForwardedActor::class)->resolve($envelope);
            $this->fail('the forward should have been refused');
        } catch (ForwardedWriteRefused $e) {
            $this->assertSame($status, $e->status);
        }
    }

    /** @return array{0: User, 1: string, 2: string} [subject, devicePublicKeyB64, deviceSecret] */
    private function subjectWithDevice(): array
    {
        app(InstanceIdentityService::class)->ensureIdentity();
        app(AttestedActorContext::class)->clear();

        $keypair = sodium_crypto_sign_keypair();
        $devPub = sodium_bin2base64(sodium_crypto_sign_publickey($keypair), self::VARIANT);
        $devSec = sodium_crypto_sign_secretkey($keypair);

        $subject = User::create([
            'name' => 'Citizen '.Str::uuid(),
            'email' => 'citizen-'.Str::uuid().'@test.invalid',
            'password' => Str::random(32),
            'terms_accepted_at' => now(),
        ]);

        return [$subject, $devPub, $devSec];
    }

    /** A validly-signed (possibly custom-role / past-expiry) attestation — not persisted. */
    private function signedAttestation(User $subject, string $devPub, array $roles, CarbonImmutable $expiresAt): StandingAttestation
    {
        $identity = app(InstanceIdentityService::class);

        $att = new StandingAttestation([
            'id' => (string) Str::uuid(),
            'subject_user_id' => (string) $subject->getKey(),
            'device_public_key' => $devPub,
            'issuer_server_id' => $identity->serverId(),
            'roles' => array_values($roles),
            'issued_at' => CarbonImmutable::now()->subMinutes(1),
            'expires_at' => $expiresAt,
        ]);
        $att->signature = $identity->sign(app(AttestationService::class)->attestationCanonical($att));

        return $att;
    }

    /** Build a forwarded-write envelope with a device-signed actor block. */
    private function forwardEnvelope(StandingAttestation $att, string $devSec, string $formId, array $payload): array
    {
        $timestamp = now()->timestamp;
        $body = AuditService::canonicalJson([
            'form_id' => $formId,
            'payload' => $payload,
            'subject_user_id' => (string) $att->subject_user_id,
        ]);
        $signingString = ActorIdentityService::actionSigningString('POST', '/actor/write', $timestamp, $body);
        $actionSignature = sodium_bin2base64(sodium_crypto_sign_detached($signingString, $devSec), self::VARIANT);

        return [
            'form_id' => $formId,
            'payload' => $payload,
            'actor' => [
                'attestation' => [
                    'id' => (string) $att->id,
                    'subject_user_id' => (string) $att->subject_user_id,
                    'device_public_key' => (string) $att->device_public_key,
                    'issuer_server_id' => (string) $att->issuer_server_id,
                    'roles' => array_values((array) $att->roles),
                    'issued_at' => $att->issued_at->getTimestamp(),
                    'expires_at' => $att->expires_at->getTimestamp(),
                    'signature' => (string) $att->signature,
                ],
                'timestamp' => $timestamp,
                'action_signature' => $actionSignature,
            ],
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
            app(AttestedActorContext::class)->clear();
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            DB::setDefaultConnection($original);
        }
    }
}
