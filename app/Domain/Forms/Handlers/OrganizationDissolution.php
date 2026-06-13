<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\OrgRegistryService;

/**
 * F-ORG-007 — Organization Dissolution (R-23; WF-ORG-10, voluntary path).
 *
 * Obligations settled (open contracts engine-checked), memberships and
 * workers ended (headcount recompute queued), stakes closed, packages
 * archived; records + audit preserved. CGCs are rejected — F-LEG-027
 * only (Art. III §5). The judicial path is Phase E (deferral §E.4.5).
 */
class OrganizationDissolution implements FormHandler
{
    public function __construct(
        private readonly OrgRegistryService $registry,
    ) {
    }

    public function module(): string
    {
        return 'organizations';
    }

    public function event(): string
    {
        return 'organization.dissolved';
    }

    public function requiredRoles(): array
    {
        return ['R-23'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $org = Organization::query()->find($payload['organization_id'] ?? null);

        if ($org === null) {
            throw new ConstitutionalViolation('F-ORG-007 targets an unknown organization.', 'CGA Forms Catalog (F-ORG-007)');
        }

        if ($actor !== null && (string) $org->agent_user_id !== (string) $actor->getKey()) {
            throw new ConstitutionalViolation(
                'Only this organization\'s agent may dissolve it voluntarily (R-23).',
                'CGA Forms Catalog (R-23)'
            );
        }

        return $this->registry->dissolve(
            $org,
            $actor,
            isset($payload['reason']) ? (string) $payload['reason'] : null,
        );
    }
}
