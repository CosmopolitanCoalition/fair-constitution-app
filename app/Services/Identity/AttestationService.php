<?php

namespace App\Services\Identity;

use App\Models\AttestationRevocation;
use App\Models\StandingAttestation;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\RoleService;
use Illuminate\Support\Str;

/**
 * The attestation certificate authority (Phase G, G-ID).
 *
 * Issues a short-lived, revocable, instance-signed SNAPSHOT of a person's DERIVED
 * standing (their role codes) bound to a device key — so a node that does NOT hold
 * the person's residency facts can authorize a write they signed, WITHOUT copying
 * credentials/ballots/locations across the privacy boundary.
 *
 * Cardinal properties:
 *  - signs with the existing INSTANCE Ed25519 key (no second PKI); a verifier uses
 *    the issuer's pinned `federation_peers.public_key`;
 *  - the canonical form is byte-stable (epoch ints, key-sorted JSON) so issuer and
 *    any verifier hash identically;
 *  - fails closed — expiry, revocation, or any field mutation → verify() false;
 *  - only the HOME authority attests (refuses if the subject's home is a peer);
 *  - a hard 24h TTL ceiling + a signed CRL bound stale standing.
 *
 * It SNAPSHOTS RoleService::rolesFor — it never replaces local live derivation
 * (Art. I: roles are a pure function of facts, never stored; the attestation is a
 * portable copy for FORWARDED writes only).
 */
class AttestationService
{
    /** Art. I stale-standing ceiling: an attestation may live at most 24h. */
    public const MAX_TTL_SECONDS = 86400;

    public const DEFAULT_TTL_SECONDS = 3600;

    public function __construct(
        private readonly InstanceIdentityService $identity,
        private readonly RoleService $roles,
        private readonly AuditService $audit,
    ) {}

    /**
     * Issue an attestation for $subject's standing, bound to $devicePublicKey.
     *
     * @throws AttestationRefused when we are not the subject's home authority.
     */
    public function issue(User $subject, string $devicePublicKey, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): StandingAttestation
    {
        // Only the HOME authority attests a person's standing — never forge a
        // person whose authority lives on a peer.
        $home = $subject->home_server_id;
        if ($home !== null && (string) $home !== $this->identity->serverId()) {
            throw new AttestationRefused('not_home_authority');
        }
        if (trim($devicePublicKey) === '') {
            throw new AttestationRefused('missing_device_key');
        }

        $ttl = min(max(60, $ttlSeconds), self::MAX_TTL_SECONDS);
        $issuedAt = now();

        $attestation = new StandingAttestation([
            'id' => (string) Str::uuid(),
            'subject_user_id' => (string) $subject->getKey(),
            'device_public_key' => $devicePublicKey,
            'issuer_server_id' => $this->identity->serverId(),
            'roles' => array_values($this->roles->rolesFor($subject)), // a SNAPSHOT of the live derivation
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt->copy()->addSeconds($ttl),
        ]);

        $attestation->signature = $this->identity->sign($this->attestationCanonical($attestation));
        $attestation->save();

        $this->audit->append('actor_identity', 'attestation.issued', [
            'attestation_id' => $attestation->id,
            'subject_user_id' => $attestation->subject_user_id,
            'expires_at' => $attestation->expires_at->toIso8601String(),
        ], 'WF-JUR-06');

        return $attestation;
    }

    /** The byte-stable canonical both issuer and any verifier hash identically. */
    public function attestationCanonical(StandingAttestation $a): string
    {
        return AuditService::canonicalJson([
            'id' => (string) $a->id,
            'subject_user_id' => (string) $a->subject_user_id,
            'device_public_key' => (string) $a->device_public_key,
            'issuer_server_id' => (string) $a->issuer_server_id,
            'roles' => array_values((array) $a->roles),
            'issued_at' => (int) $a->issued_at->getTimestamp(),
            'expires_at' => (int) $a->expires_at->getTimestamp(),
        ]);
    }

    /**
     * Verify an attestation against the issuer's PINNED public key. Fails closed
     * on expiry, revocation, or any signature/field mutation.
     */
    public function verifyAttestation(StandingAttestation $a, string $issuerPublicKey): bool
    {
        if ($a->isExpired() || $this->isRevoked((string) $a->id)) {
            return false;
        }

        return InstanceIdentityService::verify(
            $issuerPublicKey, $this->attestationCanonical($a), (string) $a->signature
        );
    }

    /** Revoke an attestation (signed CRL entry). Idempotent. */
    public function revoke(StandingAttestation $a, ?string $reason = null): AttestationRevocation
    {
        $existing = AttestationRevocation::query()->where('attestation_id', $a->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $revocation = new AttestationRevocation([
            'id' => (string) Str::uuid(),
            'attestation_id' => (string) $a->id,
            'issuer_server_id' => $this->identity->serverId(),
            'reason' => $reason,
            'revoked_at' => now(),
        ]);
        $revocation->signature = $this->identity->sign($this->revocationCanonical($revocation));
        $revocation->save();

        $this->audit->append('actor_identity', 'attestation.revoked',
            ['attestation_id' => (string) $a->id, 'reason' => $reason], 'WF-JUR-06');

        return $revocation;
    }

    public function revocationCanonical(AttestationRevocation $r): string
    {
        return AuditService::canonicalJson([
            'attestation_id' => (string) $r->attestation_id,
            'issuer_server_id' => (string) $r->issuer_server_id,
            'revoked_at' => (int) $r->revoked_at->getTimestamp(),
        ]);
    }

    public function isRevoked(string $attestationId): bool
    {
        return AttestationRevocation::query()->where('attestation_id', $attestationId)->exists();
    }
}
