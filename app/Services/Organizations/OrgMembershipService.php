<?php

namespace App\Services\Organizations;

use App\Domain\Engine\ConstitutionalViolation;
use App\Jobs\Organizations\RecomputeWorkerHeadcountJob;
use App\Models\Organization;
use App\Models\OrgContract;
use App\Models\OrgMembership;
use App\Models\OrgWorker;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-O3 (PHASE_D_DESIGN_organizations §D) — memberships (R-24, WF-ORG-03)
 * and workers (R-25, F-IND-014 — THE headcount feed).
 *
 * Worker activation = org countersign of the backing labor_recurring
 * contract; every org_workers status flip dispatches
 * RecomputeWorkerHeadcountJob AFTER COMMIT (queued, never synchronous —
 * a 2,000-signup import must not run 2,000 board reconciliations in the
 * request path; the job is debounced via ShouldBeUnique).
 */
class OrgMembershipService
{
    public function __construct(
        private readonly RoleService $roles,
        private readonly CoDeterminationService $coDetermination,
    ) {
    }

    // =========================================================================
    // Memberships (F-IND-013 → F-ORG-001 accept/decline)
    // =========================================================================

    /** F-IND-013 — apply for the org's ownership class. */
    public function apply(User $actor, Organization $org, ?string $kind): OrgMembership
    {
        if ($org->status !== Organization::STATUS_ACTIVE) {
            throw new ConstitutionalViolation(
                "Organization [{$org->id}] is not active (status: {$org->status}).",
                'CGA Forms Catalog (F-IND-013)'
            );
        }

        $expected = $org->membershipKind();

        if ($expected === null) {
            throw new ConstitutionalViolation(
                'This organization carries no ownership structure — it accepts no membership class.',
                'CGA Forms Catalog (F-IND-013)'
            );
        }

        $kind ??= $expected;

        if ($kind !== $expected) {
            throw new ConstitutionalViolation(
                "Membership class [{$kind}] does not match the organization's structure "
                . "({$org->structure} accepts [{$expected}]).",
                'CGA Forms Catalog (F-IND-013)'
            );
        }

        $open = OrgMembership::query()
            ->where('organization_id', $org->id)
            ->where('user_id', (string) $actor->getKey())
            ->where('kind', $kind)
            ->whereIn('status', [OrgMembership::STATUS_APPLIED, OrgMembership::STATUS_ACTIVE])
            ->exists();

        if ($open) {
            throw new ConstitutionalViolation(
                'An open membership (applied or active) already exists for this class.',
                'CGA Forms Catalog (F-IND-013)'
            );
        }

        return OrgMembership::create([
            'organization_id' => (string) $org->id,
            'user_id'         => (string) $actor->getKey(),
            'kind'            => $kind,
            'status'          => OrgMembership::STATUS_APPLIED,
            'applied_at'      => now(),
        ]);
    }

    /** F-ORG-001 'accept_member' — grants R-24 on ACCEPTANCE. */
    public function accept(OrgMembership $membership, User $agent): OrgMembership
    {
        if ($membership->status !== OrgMembership::STATUS_APPLIED) {
            throw new ConstitutionalViolation(
                "Membership [{$membership->id}] is not pending (status: {$membership->status}).",
                'CGA Forms Catalog (F-ORG-001)'
            );
        }

        $membership->forceFill([
            'status'              => OrgMembership::STATUS_ACTIVE,
            'accepted_at'         => now(),
            'accepted_by_user_id' => (string) $agent->getKey(),
        ])->save();

        $this->roles->flushUser((string) $membership->user_id); // R-24

        return $membership;
    }

    /** F-ORG-001 'decline_member'. */
    public function decline(OrgMembership $membership): OrgMembership
    {
        if ($membership->status !== OrgMembership::STATUS_APPLIED) {
            throw new ConstitutionalViolation(
                "Membership [{$membership->id}] is not pending (status: {$membership->status}).",
                'CGA Forms Catalog (F-ORG-001)'
            );
        }

        $membership->forceFill(['status' => OrgMembership::STATUS_DECLINED])->save();

        return $membership;
    }

    /** End an active membership (resignation / removal per bylaws). */
    public function end(OrgMembership $membership, string $reason): OrgMembership
    {
        if ($membership->status !== OrgMembership::STATUS_ACTIVE) {
            throw new ConstitutionalViolation(
                "Membership [{$membership->id}] is not active.",
                'CGA Forms Catalog (F-ORG-001)'
            );
        }

        $membership->forceFill([
            'status'     => OrgMembership::STATUS_ENDED,
            'ended_at'   => now(),
            'end_reason' => $reason,
        ])->save();

        $this->roles->flushUser((string) $membership->user_id);

        return $membership;
    }

    // =========================================================================
    // Workers (F-IND-014 → F-ORG-001 countersign_contract)
    // =========================================================================

    /**
     * F-IND-014 — worker registration: a draft labor_recurring contract
     * (counterparty-signed by the worker at filing) + an org_workers row
     * 'applied'. Activation on org countersign.
     *
     * @return array{worker: OrgWorker, contract: OrgContract}
     */
    public function registerWorker(User $actor, string $employerType, string $employerId, ?string $contractTerms): array
    {
        $organizationId = $this->resolveEmployerOrg($employerType, $employerId);

        $open = OrgWorker::query()
            ->forEmployer($employerType, $employerId)
            ->where('user_id', (string) $actor->getKey())
            ->whereIn('status', [OrgWorker::STATUS_APPLIED, OrgWorker::STATUS_ACTIVE])
            ->exists();

        if ($open) {
            throw new ConstitutionalViolation(
                'An open worker registration (applied or active) already exists with this employer.',
                'CGA Forms Catalog (F-IND-014)'
            );
        }

        $contract = OrgContract::create([
            'organization_id'           => $organizationId,
            'counterparty_type'         => OrgContract::COUNTERPARTY_USERS,
            'counterparty_id'           => (string) $actor->getKey(),
            'kind'                      => OrgContract::KIND_LABOR_RECURRING,
            'terms'                     => $contractTerms ?? 'Recurring labor (F-IND-014 standard signup).',
            'signed_by_counterparty_at' => now(), // the worker signs at filing
            'status'                    => OrgContract::STATUS_OFFERED,
        ]);

        $worker = OrgWorker::create([
            'employer_type' => $employerType,
            'employer_id'   => $employerId,
            'user_id'       => (string) $actor->getKey(),
            'contract_id'   => (string) $contract->id,
            'status'        => OrgWorker::STATUS_APPLIED,
        ]);

        // CLK-13/14 watchers arm lazily on the first worker write.
        $this->coDetermination->armWatchers(
            $employerType,
            $employerId,
            $this->employerJurisdiction($employerType, $employerId)
        );

        return ['worker' => $worker, 'contract' => $contract];
    }

    /**
     * F-ORG-001 'countersign_contract' — the org's signature completes
     * the co-sign gate; linked worker rows activate (R-25 + headcount).
     *
     * @return array{contract: OrgContract, activated: int}
     */
    public function countersignContract(OrgContract $contract, User $agent): array
    {
        if (! in_array($contract->status, [OrgContract::STATUS_DRAFT, OrgContract::STATUS_OFFERED], true)) {
            throw new ConstitutionalViolation(
                "Contract [{$contract->id}] is not open for countersigning (status: {$contract->status}).",
                'CGA Forms Catalog (F-ORG-001)'
            );
        }

        $contract->forceFill([
            'signed_by_org_user_id' => (string) $agent->getKey(),
            'signed_by_org_at'      => now(),
        ]);

        // CO-SIGN GATE (engine rule; the DB CHECK is the belt): activation
        // requires BOTH signatures.
        if ($contract->signed_by_counterparty_at === null) {
            $contract->save();

            return ['contract' => $contract, 'activated' => 0];
        }

        $contract->forceFill([
            'status'       => OrgContract::STATUS_ACTIVE,
            'effective_at' => now(),
        ])->save();

        $activated = 0;

        foreach (OrgWorker::query()->where('contract_id', $contract->id)->where('status', OrgWorker::STATUS_APPLIED)->get() as $worker) {
            $worker->forceFill([
                'status'     => OrgWorker::STATUS_ACTIVE,
                'started_at' => now(),
            ])->save();

            $this->roles->flushUser((string) $worker->user_id); // R-25
            $this->dispatchHeadcount($worker);
            $activated++;
        }

        return ['contract' => $contract, 'activated' => $activated];
    }

    /** F-ORG-001 'void_contract' — voiding ends backed worker rows. */
    public function voidContract(OrgContract $contract): array
    {
        if (in_array($contract->status, [OrgContract::STATUS_ENDED, OrgContract::STATUS_VOIDED], true)) {
            throw new ConstitutionalViolation(
                "Contract [{$contract->id}] is already closed.",
                'CGA Forms Catalog (F-ORG-001)'
            );
        }

        $contract->forceFill(['status' => OrgContract::STATUS_VOIDED, 'ended_at' => now()])->save();

        $ended = 0;

        foreach (OrgWorker::query()->where('contract_id', $contract->id)->whereIn('status', [OrgWorker::STATUS_APPLIED, OrgWorker::STATUS_ACTIVE])->get() as $worker) {
            $worker->forceFill(['status' => OrgWorker::STATUS_ENDED, 'ended_at' => now()])->save();
            $this->roles->flushUser((string) $worker->user_id);
            $this->dispatchHeadcount($worker);
            $ended++;
        }

        return ['contract' => $contract, 'workers_ended' => $ended];
    }

    /** End a single worker row (resignation). */
    public function endWorker(OrgWorker $worker): OrgWorker
    {
        if ($worker->status === OrgWorker::STATUS_ENDED) {
            throw new ConstitutionalViolation('Worker registration is already ended.', 'CGA Forms Catalog (F-IND-014)');
        }

        $worker->forceFill(['status' => OrgWorker::STATUS_ENDED, 'ended_at' => now()])->save();

        $this->roles->flushUser((string) $worker->user_id);
        $this->dispatchHeadcount($worker);

        return $worker;
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** Queued, never synchronous — after-commit, debounced (ShouldBeUnique). */
    private function dispatchHeadcount(OrgWorker $worker): void
    {
        RecomputeWorkerHeadcountJob::dispatch((string) $worker->employer_type, (string) $worker->employer_id)
            ->afterCommit();
    }

    /**
     * Resolve the contract-bearing organization for a polymorphic
     * employer: orgs are themselves; departments contract through their
     * overseeing structure (Phase D exec scope) — until departments
     * exist, department employers are rejected.
     */
    private function resolveEmployerOrg(string $employerType, string $employerId): string
    {
        if ($employerType === OrgWorker::EMPLOYER_ORGANIZATIONS) {
            $org = Organization::query()->find($employerId);

            if ($org === null || $org->status !== Organization::STATUS_ACTIVE) {
                throw new ConstitutionalViolation(
                    'F-IND-014 targets an unknown or inactive organization.',
                    'CGA Forms Catalog (F-IND-014)'
                );
            }

            return (string) $org->id;
        }

        if ($employerType === OrgWorker::EMPLOYER_DEPARTMENTS) {
            if (! Schema::hasTable('departments') || DB::table('departments')->where('id', $employerId)->whereNull('deleted_at')->doesntExist()) {
                throw new ConstitutionalViolation(
                    'F-IND-014 targets an unknown department.',
                    'CGA Forms Catalog (F-IND-014)'
                );
            }

            // Departments hire through the same registry (binding
            // contract); their labor contract rides without an org row.
            throw new ConstitutionalViolation(
                'Department worker contracts land with the executive scope — file against the department once its '
                . 'contracting surface ships.',
                'CGA Forms Catalog (F-IND-014) · as implemented'
            );
        }

        throw new ConstitutionalViolation(
            "Unknown employer type [{$employerType}].",
            'CGA Forms Catalog (F-IND-014)'
        );
    }

    private function employerJurisdiction(string $employerType, string $employerId): ?string
    {
        if (! Schema::hasTable($employerType)) {
            return null;
        }

        $row = DB::table($employerType)->where('id', $employerId)->first(['jurisdiction_id']);

        return $row?->jurisdiction_id !== null ? (string) $row->jurisdiction_id : null;
    }
}
