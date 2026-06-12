<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\EndorsementRequest as EndorsementRequestModel;
use App\Models\Organization;
use App\Models\User;

/**
 * F-CAN-002 — Endorsement Request (R-06).
 *
 * The first half of the F-CAN-002 → F-ORG-002 handshake (design §A B-5):
 * a standing candidate asks an organization for its endorsement; the
 * org's agent decides via F-ORG-002. One request per (candidacy, org) —
 * the DB unique is absolute, so a declined request cannot be re-filed in
 * Phase B (public record).
 */
class EndorsementRequest implements FormHandler
{
    public function module(): string
    {
        return 'elections';
    }

    public function event(): string
    {
        return 'endorsement.requested';
    }

    public function requiredRoles(): array
    {
        return ['R-06'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $candidacy = CampaignProfileSetup::ownStandingCandidacy($actor, $payload['candidacy_id'] ?? null, 'F-CAN-002');

        $organization = Organization::query()
            ->active()
            ->find($payload['organization_id'] ?? null);

        if ($organization === null) {
            throw new ConstitutionalViolation(
                'F-CAN-002 targets an unknown or inactive organization.',
                'CGA Forms Catalog (F-CAN-002)'
            );
        }

        $duplicate = EndorsementRequestModel::query()
            ->where('candidacy_id', (string) $candidacy->id)
            ->where('organization_id', (string) $organization->id)
            ->exists();

        if ($duplicate) {
            throw new ConstitutionalViolation(
                'An endorsement request to this organization already exists for this candidacy.',
                'CGA Forms Catalog (F-CAN-002)'
            );
        }

        $request = EndorsementRequestModel::query()->create([
            'candidacy_id'    => (string) $candidacy->id,
            'organization_id' => (string) $organization->id,
            'message'         => isset($payload['message']) ? (string) $payload['message'] : null,
            'status'          => EndorsementRequestModel::STATUS_PENDING,
            'requested_at'    => now(),
        ]);

        return [
            'request_id'      => (string) $request->id,
            'candidacy_id'    => (string) $candidacy->id,
            'organization_id' => (string) $organization->id,
        ];
    }
}
