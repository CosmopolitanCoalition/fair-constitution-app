<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\Organization;
use App\Models\OrgContract;
use App\Models\OrgDocumentPackage;
use App\Models\OrgDocumentPackageVersion;
use App\Models\OrgMembership;
use App\Models\User;
use App\Services\Organizations\CgcIpRegisterService;
use App\Services\Organizations\OrgMembershipService;
use App\Services\RoleService;

/**
 * F-ORG-001 — Organization Profile Management (R-23).
 *
 * Action-dispatching handler (the ChamberActService registry-gap
 * precedent — FLAGGED: the catalog carries no dedicated acceptance/
 * contract/document forms; F-ORG-001 "Modifies: Organization record" is
 * the canonical R-23 self-management surface). Every action chains under
 * F-ORG-001 with payload.action disambiguation:
 *
 *   update_profile · reassign_agent · accept_member · decline_member ·
 *   countersign_contract · void_contract · manage_document_package ·
 *   dedicate_ip (CGC only → CgcIpRegisterService::dedicate).
 */
class OrganizationProfileManagement implements FormHandler
{
    public function __construct(
        private readonly OrgMembershipService $memberships,
        private readonly RoleService $roles,
    ) {
    }

    public function module(): string
    {
        return 'organizations';
    }

    public function event(): string
    {
        return 'organization.managed';
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
            throw new ConstitutionalViolation('F-ORG-001 targets an unknown organization.', 'CGA Forms Catalog (F-ORG-001)');
        }

        // The role gate proves agency over SOME org; the action must come
        // from THIS org's agent (system filings pass — engine rule).
        if ($actor !== null && (string) $org->agent_user_id !== (string) $actor->getKey()) {
            throw new ConstitutionalViolation(
                'Only this organization\'s agent may manage it (R-23).',
                'CGA Forms Catalog (R-23)'
            );
        }

        $action = (string) ($payload['action'] ?? '');

        $result = match ($action) {
            'update_profile'          => $this->updateProfile($org, $payload),
            'reassign_agent'          => $this->reassignAgent($org, $payload),
            'accept_member'           => $this->decideMember($org, $payload, accept: true, actor: $actor),
            'decline_member'          => $this->decideMember($org, $payload, accept: false, actor: $actor),
            'countersign_contract'    => $this->countersign($org, $payload, $actor),
            'void_contract'           => $this->voidContract($org, $payload),
            'manage_document_package' => $this->manageDocumentPackage($org, $payload, $actor),
            'dedicate_ip'             => $this->dedicateIp($org, $payload, $actor),
            default                   => throw new ConstitutionalViolation(
                "Unknown F-ORG-001 action [{$action}].",
                'CGA Forms Catalog (F-ORG-001)'
            ),
        };

        return ['action' => $action, 'organization_id' => (string) $org->id] + $result;
    }

    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function updateProfile(Organization $org, array $payload): array
    {
        $updatable = ['name', 'abbreviation', 'color', 'description', 'website_url', 'purpose'];
        $changed   = [];

        foreach ($updatable as $field) {
            if (array_key_exists($field, $payload)) {
                $org->{$field} = $payload[$field] !== null ? (string) $payload[$field] : null;
                $changed[]     = $field;
            }
        }

        $org->save();

        return ['changed' => $changed];
    }

    /** @return array<string, mixed> */
    private function reassignAgent(Organization $org, array $payload): array
    {
        $newAgent = User::query()->find($payload['agent_user_id'] ?? null);

        if ($newAgent === null) {
            throw new ConstitutionalViolation('reassign_agent names an unknown user.', 'CGA Forms Catalog (F-ORG-001)');
        }

        $previous = $org->agent_user_id !== null ? (string) $org->agent_user_id : null;

        $org->forceFill(['agent_user_id' => (string) $newAgent->getKey()])->save();

        if ($previous !== null) {
            $this->roles->flushUser($previous);
        }
        $this->roles->flushUser((string) $newAgent->getKey());

        return ['previous_agent' => $previous, 'agent_user_id' => (string) $newAgent->getKey()];
    }

    /** @return array<string, mixed> */
    private function decideMember(Organization $org, array $payload, bool $accept, ?User $actor): array
    {
        $membership = OrgMembership::query()
            ->where('organization_id', $org->id)
            ->find($payload['membership_id'] ?? null);

        if ($membership === null) {
            throw new ConstitutionalViolation('Unknown membership application for this organization.', 'CGA Forms Catalog (F-ORG-001)');
        }

        $agent = $actor ?? User::query()->find($org->agent_user_id);

        $membership = $accept
            ? $this->memberships->accept($membership, $agent)
            : $this->memberships->decline($membership);

        return [
            'membership_id' => (string) $membership->id,
            'user_id'       => (string) $membership->user_id,
            'status'        => (string) $membership->status,
        ];
    }

    /** @return array<string, mixed> */
    private function countersign(Organization $org, array $payload, ?User $actor): array
    {
        $contract = OrgContract::query()
            ->where('organization_id', $org->id)
            ->find($payload['contract_id'] ?? null);

        if ($contract === null) {
            throw new ConstitutionalViolation('Unknown contract for this organization.', 'CGA Forms Catalog (F-ORG-001)');
        }

        $agent  = $actor ?? User::query()->find($org->agent_user_id);
        $result = $this->memberships->countersignContract($contract, $agent);

        return [
            'contract_id'       => (string) $contract->id,
            'contract_status'   => (string) $result['contract']->status,
            'workers_activated' => (int) $result['activated'],
        ];
    }

    /** @return array<string, mixed> */
    private function voidContract(Organization $org, array $payload): array
    {
        $contract = OrgContract::query()
            ->where('organization_id', $org->id)
            ->find($payload['contract_id'] ?? null);

        if ($contract === null) {
            throw new ConstitutionalViolation('Unknown contract for this organization.', 'CGA Forms Catalog (F-ORG-001)');
        }

        $result = $this->memberships->voidContract($contract);

        return [
            'contract_id'   => (string) $contract->id,
            'workers_ended' => (int) $result['workers_ended'],
        ];
    }

    /** @return array<string, mixed> */
    private function manageDocumentPackage(Organization $org, array $payload, ?User $actor): array
    {
        $key = trim((string) ($payload['key'] ?? ''));

        if ($key === '' || trim((string) ($payload['content'] ?? '')) === '') {
            throw new ConstitutionalViolation(
                'manage_document_package requires a key and the version content.',
                'CGA Forms Catalog (F-ORG-001)'
            );
        }

        // The FormRegistry-collision rule runs in the validator pre-commit
        // (constitutional floor); this is the engine backstop.
        if (\App\Domain\Forms\FormRegistry::exists($key)) {
            throw new ConstitutionalViolation(
                "Document package key [{$key}] collides with a constitutional form ID.",
                'CGA Forms Catalog · as implemented'
            );
        }

        $package = OrgDocumentPackage::query()
            ->where('organization_id', $org->id)
            ->where('key', $key)
            ->first();

        if ($package === null) {
            $package = OrgDocumentPackage::create([
                'organization_id' => (string) $org->id,
                'key'             => $key,
                'name'            => (string) ($payload['name'] ?? $key),
                'kind'            => in_array($payload['kind'] ?? null, OrgDocumentPackage::KINDS, true)
                    ? (string) $payload['kind']
                    : 'other',
                'status'          => OrgDocumentPackage::STATUS_ACTIVE,
            ]);
        }

        $versionNo = (int) ($package->versions()->max('version_no') ?? 0) + 1;

        $version = OrgDocumentPackageVersion::create([
            'package_id'         => (string) $package->id,
            'version_no'         => $versionNo,
            'content'            => (string) $payload['content'],
            'created_by_user_id' => $actor?->getKey() !== null ? (string) $actor->getKey() : null,
        ]);

        return [
            'package_id' => (string) $package->id,
            'key'        => $key,
            'version_no' => $versionNo,
            'version_id' => (string) $version->id,
        ];
    }

    /** @return array<string, mixed> */
    private function dedicateIp(Organization $org, array $payload, ?User $actor): array
    {
        $entry = app(CgcIpRegisterService::class)->dedicate(
            $org,
            (string) ($payload['asset'] ?? ''),
            (string) ($payload['kind'] ?? 'other'),
            isset($payload['description']) ? (string) $payload['description'] : null,
            'F-ORG-001',
            $actor?->getKey() !== null ? (string) $actor->getKey() : null,
        );

        return [
            'register_seq' => (int) $entry->seq,
            'register_id'  => (string) $entry->id,
            'asset'        => (string) $entry->asset,
            'status'       => (string) $entry->status,
        ];
    }
}
