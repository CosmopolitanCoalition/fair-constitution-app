<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\User;
use App\Services\Organizations\OrgRegistryService;

/**
 * F-IND-012 — Organization Registration (R-03; WF-ORG-01).
 *
 * Art. I Economic Freedom: ASSOCIATION IS THE ONLY REQUIREMENT — the
 * R-03 gate is the whole gate. Registration IS activation. The
 * common_good_corp type is validator-rejected pre-commit with the
 * Art. III §5 citation (legislature-only via F-LEG-019).
 */
class OrganizationRegistration implements FormHandler
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
        return 'organization.registered';
    }

    public function requiredRoles(): array
    {
        return ['R-03'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        if ($actor === null) {
            throw new ConstitutionalViolation(
                'An organization is registered by a person — the system registers none.',
                'Art. I'
            );
        }

        return $this->registry->register($actor, $payload);
    }
}
