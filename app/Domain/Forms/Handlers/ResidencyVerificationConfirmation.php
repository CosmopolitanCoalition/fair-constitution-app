<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Contracts\ResidencyHandlerDelegate;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * F-IND-006 — Residency Verification Confirmation (SYSTEM-FILED).
 *
 * Auto-generated when a claim's qualifying days reach the resolved
 * residency_confirmation_days threshold (the resident's confirm action in
 * Phase A; the CLK-05 evaluator path arrives with WI-6). Confirmation
 * creates verified residency plus the full ancestor-sweep of
 * jurisdictional associations, granting R-03 (and with it R-04 — voting
 * and candidacy unlock atomically, Art. I).
 *
 * The sweep itself (claim verified→active, residency_confirmations bulk
 * insert across all ancestor chains incl. dual-footprint twins, raw ping
 * purge) runs through the bound ResidencyHandlerDelegate
 * (ResidencyService since WI-5) INSIDE the engine transaction; the
 * delegate's return value carries the association jurisdiction-id list
 * into this single audit entry.
 */
class ResidencyVerificationConfirmation implements FormHandler
{
    public function __construct(
        private readonly ResidencyHandlerDelegate $delegate,
    ) {
    }

    public function module(): string
    {
        return 'residency';
    }

    public function event(): string
    {
        return 'residency.verified';
    }

    public function requiredRoles(): array
    {
        return []; // system-filed — see systemOnly()
    }

    public function systemOnly(): bool
    {
        return true;
    }

    public function handle(?User $actor, array $payload): array
    {
        $userId         = $payload['user_id'] ?? null;
        $jurisdictionId = $payload['jurisdiction_id'] ?? null;

        if (! is_string($userId) || $userId === '') {
            throw new ConstitutionalViolation(
                'F-IND-006 requires the user_id whose residency is being confirmed.',
                'Art. I'
            );
        }

        if (! is_string($jurisdictionId) || ! Str::isUuid($jurisdictionId)) {
            throw new ConstitutionalViolation(
                'F-IND-006 requires the confirmed jurisdiction_id (UUID).',
                'Art. I'
            );
        }

        return [
            'user_id'         => $userId,
            'jurisdiction_id' => $jurisdictionId,
            'qualifying_days' => (int) ($payload['qualifying_days'] ?? 0),
            'claim_id'        => $payload['claim_id'] ?? null,
        ] + $this->delegate->confirmVerification($actor, $payload);
    }
}
