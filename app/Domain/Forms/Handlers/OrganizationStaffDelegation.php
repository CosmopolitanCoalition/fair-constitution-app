<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\OrgDelegationService;

/**
 * F-ORG-011 — Staff Delegation (R-23, IO-5; operator ruling 2026-09-13 ·
 * org-staff-delegation-model = A).
 *
 * The organization's AGENT grants or revokes a coarse task bucket for a named
 * person. Grant and revoke are audited acts (the audit-chain form count rises
 * for this form deliberately). They are NEVER themselves delegable: the
 * agent-equality gate below mirrors OrganizationProfileManagement — only this
 * organization's own agent may file, so a delegate can never widen or pass on
 * delegation, and can never seize agency.
 *
 * The write goes through OrgDelegationService (the one authority rail). The
 * grantee derives R-31, which confers no constitutional office.
 *
 *   grant_task · revoke_task   (payload: organization_id, grantee_user_id,
 *                               bucket, reason?)
 */
class OrganizationStaffDelegation implements FormHandler
{
    public function module(): string
    {
        return 'organizations';
    }

    public function event(): string
    {
        return 'organization.delegated';
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
            throw new ConstitutionalViolation(__('F-ORG-011 targets an unknown organization.'), 'CGA Forms Catalog (F-ORG-011)');
        }

        // Grant/revoke are the agent's alone and are never delegable (a system
        // filing has no agent to record, so a person is required).
        if ($actor === null) {
            throw new ConstitutionalViolation(__('Staff delegation is granted by a person — system filing is not defined.'),
                'CGA Forms Catalog (F-ORG-011)'
            );
        }

        if ((string) $org->agent_user_id !== (string) $actor->getKey()) {
            throw new ConstitutionalViolation(__('Only this organization\'s agent may delegate staff, and delegation itself is never delegable (R-23).'),
                'CGA Forms Catalog (R-23)'
            );
        }

        $action    = (string) ($payload['action'] ?? '');
        $granteeId = (string) ($payload['grantee_user_id'] ?? '');
        $bucket    = (string) ($payload['bucket'] ?? '');
        $reason    = isset($payload['reason']) ? (string) $payload['reason'] : null;

        $delegation = app(OrgDelegationService::class);

        $result = match ($action) {
            'grant_task'  => $this->grant($delegation, $org, $actor, $granteeId, $bucket),
            'revoke_task' => $this->revoke($delegation, $org, $granteeId, $bucket, $actor, $reason),
            default       => throw new ConstitutionalViolation(__('Unknown F-ORG-011 action [:action].', ['action' => $action]),
                'CGA Forms Catalog (F-ORG-011)'
            ),
        };

        return ['action' => $action, 'organization_id' => (string) $org->id] + $result;
    }

    /** @return array<string, mixed> */
    private function grant(OrgDelegationService $delegation, Organization $org, User $actor, string $granteeId, string $bucket): array
    {
        $grantee = User::query()->find($granteeId);

        if ($grantee === null) {
            throw new ConstitutionalViolation(__('Staff delegation names an unknown person.'), 'CGA Forms Catalog (F-ORG-011)');
        }

        $grant = $delegation->grant($org, $actor, (string) $grantee->getKey(), $bucket);

        return [
            'grant_id'        => (string) $grant->id,
            'grantee_user_id' => (string) $grant->grantee_user_id,
            'bucket'          => (string) $grant->task,
            'status'          => (string) $grant->status,
        ];
    }

    /** @return array<string, mixed> */
    private function revoke(OrgDelegationService $delegation, Organization $org, string $granteeId, string $bucket, User $actor, ?string $reason): array
    {
        $grant = $delegation->revoke($org, $granteeId, $bucket, $actor, $reason);

        return [
            'grantee_user_id' => $granteeId,
            'bucket'          => $bucket,
            'status'          => $grant !== null ? (string) $grant->status : 'not_found',
            'revoked'         => $grant !== null,
        ];
    }
}
