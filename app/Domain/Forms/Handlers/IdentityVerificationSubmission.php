<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Models\User;

/**
 * F-IND-004 — Identity Verification Submission (R-01).
 *
 * Phase A implements ONLY the manual attestation-request path: the filing
 * records that the individual asked for an attestation appointment with
 * their jurisdiction's administrative office. No external ID bridge exists
 * yet (Phase F), no document data is ever accepted or stored, and nothing
 * about the user row changes — an officer recording the verified flag is
 * later-phase machinery.
 *
 * CONSTITUTIONAL NOTE (Art. I; Art. II §2): identity verification
 * strengthens election integrity but is NEVER a rights requirement.
 * Nothing in this handler may ever feed role derivation — RoleService
 * derives R-02..R-04 from residency facts alone, and the constitutional
 * suite pins that.
 */
class IdentityVerificationSubmission implements FormHandler
{
    public function module(): string
    {
        return 'identity';
    }

    public function event(): string
    {
        return 'individual.identity_attestation_requested';
    }

    public function requiredRoles(): array
    {
        return ['R-01'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        // Defense in depth: even if a caller ever passes document fields,
        // they never reach the chain — only the request itself is recorded.
        return [
            'path'            => 'manual_attestation',
            'jurisdiction_id' => is_string($payload['jurisdiction_id'] ?? null) ? $payload['jurisdiction_id'] : null,
            'requested_at'    => now()->toIso8601String(),
        ];
    }
}
