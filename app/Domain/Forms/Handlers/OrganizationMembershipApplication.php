<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\OrgMembershipService;

/**
 * F-IND-013 — Organization Membership Application (R-01; WF-ORG-03).
 *
 * The individual applies for the org's ownership class; the org accepts
 * per its bylaws (F-ORG-001 'accept_member'). R-24 derives on
 * ACCEPTANCE, never on application.
 */
class OrganizationMembershipApplication implements FormHandler
{
    public function __construct(
        private readonly OrgMembershipService $memberships,
    ) {
    }

    public function module(): string
    {
        return 'organizations';
    }

    public function event(): string
    {
        return 'org_membership.applied';
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
        if ($actor === null) {
            throw new ConstitutionalViolation('Membership belongs to a person.', 'CGA Forms Catalog (F-IND-013)');
        }

        $org = Organization::query()->find($payload['organization_id'] ?? null);

        if ($org === null) {
            throw new ConstitutionalViolation('F-IND-013 targets an unknown organization.', 'CGA Forms Catalog (F-IND-013)');
        }

        $membership = $this->memberships->apply(
            $actor,
            $org,
            isset($payload['kind']) ? (string) $payload['kind'] : null,
        );

        return [
            'membership_id'   => (string) $membership->id,
            'organization_id' => (string) $org->id,
            'kind'            => (string) $membership->kind,
            'status'          => (string) $membership->status,
        ];
    }
}
