<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\OrgConversionService;

/**
 * F-ORG-006 — Public-Private Conversion Request (R-23 / R-09).
 *
 * Creates an org_conversions row 'proposed' and routes it to the
 * legislature: a REQUEST awaiting F-LEG-026/F-LEG-027 action — request ≠
 * act; both conversion directions are legislature-only (CGCs are never
 * self-converted, Art. III §5).
 */
class PublicPrivateConversionRequest implements FormHandler
{
    public function __construct(
        private readonly OrgConversionService $conversions,
    ) {
    }

    public function module(): string
    {
        return 'organizations';
    }

    public function event(): string
    {
        return 'conversion.requested';
    }

    public function requiredRoles(): array
    {
        return ['R-23', 'R-09'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation('A conversion request names its requester.', 'CGA Forms Catalog (F-ORG-006)');
        }

        $org = Organization::query()->find($payload['organization_id'] ?? null);

        if ($org === null) {
            throw new ConstitutionalViolation('F-ORG-006 targets an unknown organization.', 'CGA Forms Catalog (F-ORG-006)');
        }

        $conversion = $this->conversions->request(
            $org,
            $actor,
            (string) ($payload['direction'] ?? ''),
            isset($payload['rationale']) ? (string) $payload['rationale'] : null,
        );

        return [
            'conversion_id'   => (string) $conversion->id,
            'organization_id' => (string) $org->id,
            'direction'       => (string) $conversion->direction,
            'status'          => (string) $conversion->status,
        ];
    }
}
