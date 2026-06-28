<?php

namespace App\Services\Identity;

use App\Models\AttestationRevocation;
use App\Models\StandingAttestation;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Federation\InstanceIdentityService;
use App\Services\RoleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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

    /**
     * A revocation reason is a short, fixed CATEGORY — NEVER operator/user free-text. This field federates
     * verbatim to every peer in the audit tail, so a narrative ("banned: harassment of <name>") would leak
     * PII across the plane wall (and a >48-char string would overflow the column + abort the sync). The set
     * is closed and code-versioned; anything outside it normalizes to null. A social-feature ban is 'abuse'.
     */
    public const REVOCATION_REASONS = ['device_lost', 'relocation', 'lost_standing', 'abuse', 'duplicate', 'reissue'];

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
        // The revocation must come from the attestation's OWN issuer (isRevoked is issuer-scoped) — a CRL row
        // planted by some other server can never suppress an attestation it did not issue (anti-poisoning).
        if ($a->isExpired() || $this->isRevoked((string) $a->id, (string) $a->issuer_server_id)) {
            return false;
        }

        return InstanceIdentityService::verify(
            $issuerPublicKey, $this->attestationCanonical($a), (string) $a->signature
        );
    }

    /** Revoke an attestation (signed CRL entry). Idempotent — scoped to OUR own revocation of it. */
    public function revoke(StandingAttestation $a, ?string $reason = null): AttestationRevocation
    {
        // Idempotency is scoped to (attestation, OUR issuer): a foreign-sourced row for the same attestation
        // (a different issuer's claim) must never be mistaken for our own revocation.
        $existing = AttestationRevocation::query()
            ->where('attestation_id', $a->id)
            ->where('issuer_server_id', $this->identity->serverId())
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $revocation = new AttestationRevocation([
            'id' => (string) Str::uuid(),
            'attestation_id' => (string) $a->id,
            'issuer_server_id' => $this->identity->serverId(),
            'reason' => $this->normalizeReason($reason),
            'revoked_at' => now(),
        ]);
        $revocation->signature = $this->identity->sign($this->revocationCanonical($revocation));
        $revocation->save();

        // The audit event carries the FULL signed revocation so a peer can materialize a verifiable,
        // ISSUER-BOUND CRL entry when this rides the FF&C sync tail (Flag 2 — closes the cross-node
        // ban-evasion window for voice + write-forwarding). issuer + revoked_at + signature let the
        // verifying peer re-prove the genuine issuer independently of the chain.
        $this->audit->append('actor_identity', 'attestation.revoked', [
            'attestation_id' => (string) $a->id,
            'issuer_server_id' => (string) $revocation->issuer_server_id,
            'revoked_at' => (int) $revocation->revoked_at->getTimestamp(),
            'reason' => $revocation->reason,   // a normalized CATEGORY (never free-text/PII) — it federates
            'signature' => (string) $revocation->signature,
        ], 'WF-JUR-06');

        return $revocation;
    }

    /**
     * Materialize a FOREIGN attestation revocation (arriving in a peer's signed, chain-verified audit
     * tail) into the local CRL, so a verifying peer honors a revocation the HOME authority issued — closing
     * the cross-node ban-evasion window for the foci voice path and write-forwarding (Flag 2). Fail-closed
     * and idempotent; returns true iff a new local CRL row was written. Called from
     * FederationSyncService::ingestTail AFTER the tail signature + chain recompute already passed.
     *
     * Two bindings stop a trusted peer from revoking ANOTHER node's attestations (a denial-of-service
     * vector — a revocation row makes verifyAttestation fail closed for that id):
     *  (a) ISSUER-BINDING — the revocation's issuer must BE the propagating peer (a peer carries only the
     *      revocations it issued); and
     *  (b) GENUINE-ISSUER PROOF — the issuer's detached signature must verify against the peer's PINNED key
     *      over the revocation canonical (cryptographic, independent of the chain integrity checked upstream).
     *
     * @param  array<string,mixed>  $payload  the `attestation.revoked` audit payload
     */
    public function ingestForeignRevocation(array $payload, string $peerServerId, string $peerPublicKey): bool
    {
        $attestationId = (string) ($payload['attestation_id'] ?? '');
        $issuer = (string) ($payload['issuer_server_id'] ?? '');
        $revokedAt = $payload['revoked_at'] ?? null;
        $signature = (string) ($payload['signature'] ?? '');

        // Pre-enrichment / malformed events (no verifiable issuer + signature) are NOT materialized — they
        // cannot be issuer-proved, and the attestation TTL still bounds them. Only signed revocations land.
        if ($attestationId === '' || $issuer === '' || $revokedAt === null || $signature === '' || $peerPublicKey === '') {
            return false;
        }

        // (a) A peer may only propagate revocations it ISSUED.
        if ($issuer !== $peerServerId) {
            return false;
        }

        // (b) The issuer's signature must verify against the peer's pinned key over the revocation canonical.
        $revokedAtTs = CarbonImmutable::createFromTimestamp((int) $revokedAt);
        $rebuilt = new AttestationRevocation([
            'attestation_id' => $attestationId,
            'issuer_server_id' => $issuer,
            'revoked_at' => $revokedAtTs,
        ]);
        if (! InstanceIdentityService::verify($peerPublicKey, $this->revocationCanonical($rebuilt), $signature)) {
            return false;
        }

        // Atomic + idempotent materialize, keyed on (attestation_id, issuer_server_id): insertOrIgnore
        // (ON CONFLICT DO NOTHING) so a concurrent/duplicate tail can never raise a unique violation that
        // would abort the surrounding ingestTail transaction (the K3-C SAVEPOINT lesson). reason is
        // NORMALIZED to a closed category first — so a peer-controlled free-text/over-length reason can
        // neither leak PII nor overflow varchar(48) and poison the sync. Stamped foreign-sourced.
        $now = now();
        $inserted = DB::table('attestation_revocations')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'attestation_id' => $attestationId,
            'issuer_server_id' => $issuer,
            'reason' => $this->normalizeReason(isset($payload['reason']) ? (string) $payload['reason'] : null),
            'revoked_at' => $revokedAtTs,
            'signature' => $signature,
            'source_server_id' => $peerServerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $inserted > 0;
    }

    /** A revocation reason is a closed CATEGORY; anything outside the set (incl. free-text/PII) → null. */
    private function normalizeReason(?string $reason): ?string
    {
        return in_array($reason, self::REVOCATION_REASONS, true) ? $reason : null;
    }

    public function revocationCanonical(AttestationRevocation $r): string
    {
        return AuditService::canonicalJson([
            'attestation_id' => (string) $r->attestation_id,
            'issuer_server_id' => (string) $r->issuer_server_id,
            'revoked_at' => (int) $r->revoked_at->getTimestamp(),
        ]);
    }

    /**
     * Is this attestation revoked BY ITS OWN ISSUER? Scoping by issuer is load-bearing: a CRL row a hostile
     * peer planted (claiming itself as issuer) can never suppress an attestation a DIFFERENT server issued.
     */
    public function isRevoked(string $attestationId, string $issuerServerId): bool
    {
        return AttestationRevocation::query()
            ->where('attestation_id', $attestationId)
            ->where('issuer_server_id', $issuerServerId)
            ->exists();
    }
}
