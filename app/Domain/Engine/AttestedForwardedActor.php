<?php

namespace App\Domain\Engine;

use App\Domain\Engine\Contracts\ResolvesForwardedActor;
use App\Models\FederationPeer;
use App\Models\StandingAttestation;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Federation\ForwardedWriteRefused;
use App\Services\Federation\InstanceIdentityService;
use App\Services\Identity\ActorIdentityService;
use App\Services\Identity\AttestationService;
use App\Services\Identity\AttestedActorContext;
use Carbon\CarbonImmutable;

/**
 * The G-ID forwarded-actor resolver (Phase G) — turns CITIZEN write-forwarding on.
 *
 * A forwarded write whose envelope carries an `actor` block must satisfy THREE
 * independent checks before it files as that person:
 *   1. ATTESTATION — the subject's home authority signed a short-lived snapshot of
 *      their standing (role codes) bound to a device key; verified against the
 *      issuer's pinned key, fails closed on expiry/revocation/mutation;
 *   2. ACTION SIGNATURE — the subject's DEVICE signed THIS exact write (form + payload
 *      + subject), so a forwarding peer cannot fabricate a write on their behalf;
 *   3. SUBJECT — the attested user resolves locally.
 * On success the attested role snapshot is placed in the request context so the
 * engine authorizes against it (the leader holds no residency facts to re-derive).
 *
 * A bare system forward (no `actor` block) resolves to null exactly as
 * SystemOnlyForwardedActor did — this resolver is a strict superset.
 */
class AttestedForwardedActor implements ResolvesForwardedActor
{
    public function __construct(
        private readonly AttestationService $attestations,
        private readonly InstanceIdentityService $identity,
        private readonly AttestedActorContext $context,
    ) {}

    public function resolve(array $envelope): ?User
    {
        $actor = $envelope['actor'] ?? null;

        if ($actor === null) {
            return null; // a system-scoped forward — files as the system
        }

        if (! is_array($actor) || ! is_array($actor['attestation'] ?? null)) {
            throw new ForwardedWriteRefused('malformed_actor_envelope', 422);
        }

        $attestation = $this->reconstruct($actor['attestation']);

        // 1. The issuer's pinned key (ours, or a peer's).
        $issuerKey = $this->issuerPublicKey((string) $attestation->issuer_server_id);
        if ($issuerKey === null) {
            throw new ForwardedWriteRefused('unknown_attestation_issuer', 403);
        }
        if (! $this->attestations->verifyAttestation($attestation, $issuerKey)) {
            throw new ForwardedWriteRefused('attestation_invalid_expired_or_revoked', 403);
        }

        // 2. The device signed THIS write (non-repudiation).
        $signingString = ActorIdentityService::actionSigningString(
            'POST',
            '/actor/write',
            (int) ($actor['timestamp'] ?? 0),
            $this->actionBody($envelope, (string) $attestation->subject_user_id),
        );
        if (! InstanceIdentityService::verify(
            (string) $attestation->device_public_key,
            $signingString,
            (string) ($actor['action_signature'] ?? ''),
        )) {
            throw new ForwardedWriteRefused('action_signature_invalid', 403);
        }

        // 3. The subject resolves locally.
        $user = User::query()->find((string) $attestation->subject_user_id);
        if ($user === null) {
            throw new ForwardedWriteRefused('unknown_subject', 403);
        }

        // Authorize this one forwarded write against the attested snapshot.
        $this->context->set((string) $user->getKey(), (array) $attestation->roles);

        return $user;
    }

    /**
     * The exact bytes the device signed: the write's identity (form + payload +
     * subject), canonicalized so issuer-side and leader-side agree byte-for-byte.
     */
    private function actionBody(array $envelope, string $subjectUserId): string
    {
        return AuditService::canonicalJson([
            'form_id' => (string) ($envelope['form_id'] ?? ''),
            'payload' => (array) ($envelope['payload'] ?? []),
            'subject_user_id' => $subjectUserId,
        ]);
    }

    private function issuerPublicKey(string $issuerServerId): ?string
    {
        if ($issuerServerId === $this->identity->serverId()) {
            return $this->identity->publicKey();
        }

        $peer = FederationPeer::query()->where('server_id', $issuerServerId)->first();

        return $peer?->public_key !== null ? (string) $peer->public_key : null;
    }

    /**
     * Rebuild an unsaved StandingAttestation from the wire fields so its canonical
     * (and therefore its signature check) reproduces exactly. issued_at/expires_at
     * arrive as epoch ints — the same form the issuer hashed.
     *
     * @param  array<string,mixed>  $att
     */
    private function reconstruct(array $att): StandingAttestation
    {
        $attestation = new StandingAttestation([
            'id' => (string) ($att['id'] ?? ''),
            'subject_user_id' => (string) ($att['subject_user_id'] ?? ''),
            'device_public_key' => (string) ($att['device_public_key'] ?? ''),
            'issuer_server_id' => (string) ($att['issuer_server_id'] ?? ''),
            'roles' => array_values((array) ($att['roles'] ?? [])),
            'issued_at' => CarbonImmutable::createFromTimestamp((int) ($att['issued_at'] ?? 0)),
            'expires_at' => CarbonImmutable::createFromTimestamp((int) ($att['expires_at'] ?? 0)),
        ]);

        $attestation->signature = (string) ($att['signature'] ?? '');

        return $attestation;
    }
}
