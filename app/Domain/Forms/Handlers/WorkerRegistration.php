<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\OrgWorker;
use App\Models\User;
use App\Services\Organizations\OrgMembershipService;

/**
 * F-IND-014 — Worker Registration (R-01) — THE headcount feed
 * (Art. III §6; owner ruling #12: a "worker" is an F-IND-014 signup).
 *
 * Creates the draft labor_recurring contract (worker-signed at filing) +
 * the org_workers row 'applied'. Activation happens on the org
 * countersign (F-ORG-001 'countersign_contract') → R-25 + the QUEUED
 * headcount recompute (CLK-13/14 path — the Phase D exit criterion).
 * Employer is polymorphic (organizations | departments — the binding
 * cross-designer contract).
 */
class WorkerRegistration implements FormHandler
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
        return 'worker.registered';
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
            throw new ConstitutionalViolation('A worker registration belongs to a person.', 'CGA Forms Catalog (F-IND-014)');
        }

        $employerType = (string) ($payload['employer_type'] ?? OrgWorker::EMPLOYER_ORGANIZATIONS);
        $employerId   = (string) ($payload['employer_id'] ?? ($payload['organization_id'] ?? ''));

        if ($employerId === '') {
            throw new ConstitutionalViolation('F-IND-014 names the employer.', 'CGA Forms Catalog (F-IND-014)');
        }

        $result = $this->memberships->registerWorker(
            $actor,
            $employerType,
            $employerId,
            isset($payload['contract_terms']) ? (string) $payload['contract_terms'] : null,
        );

        return [
            'worker_id'     => (string) $result['worker']->id,
            'contract_id'   => (string) $result['contract']->id,
            'employer_type' => $employerType,
            'employer_id'   => $employerId,
            'status'        => (string) $result['worker']->status,
        ];
    }
}
