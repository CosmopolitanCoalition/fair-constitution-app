<?php

namespace App\Services\Executive;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Appropriation;
use App\Models\Executive;
use App\Models\ExecutiveMember;
use App\Models\GrantApplication;
use App\Models\GrantDisbursement;
use App\Models\Law;
use App\Models\Organization;
use App\Services\AuditService;
use App\Services\PublicRecordService;
use Illuminate\Support\Facades\DB;

/**
 * D-6 — appropriations + grants, minimal viable (PHASE_D_DESIGN_executive
 * §A D-6): legislatures appropriate BY ACT (rows attach to an enacted
 * law); executives administer. Invariants under FOR UPDATE on the
 * appropriation: award ≤ remaining; Σ disbursements ≤ awarded. Every
 * award/disbursement is audit-chained (WF-SYS-04) + published.
 * Disbursements are append-only. Budget UX is post-F backlog.
 */
class GrantService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly PublicRecordService $records,
    ) {
    }

    /** Attach an appropriation line to an already-enacted act. */
    public function createAppropriation(Law $law, Executive $executive, string $line, float $amount): Appropriation
    {
        if (! in_array($law->status, [Law::STATUS_IN_FORCE, Law::STATUS_AMENDED], true)) {
            throw new ConstitutionalViolation(
                'Appropriations attach to an act in force — legislatures appropriate by act.',
                'Art. II §9 · as implemented'
            );
        }

        if ($amount <= 0) {
            throw new ConstitutionalViolation('An appropriation line carries a positive amount.', 'Art. II §9 · as implemented');
        }

        $appropriation = Appropriation::create([
            'law_id'          => (string) $law->id,
            'jurisdiction_id' => (string) $executive->jurisdiction_id,
            'executive_id'    => (string) $executive->id,
            'line'            => $line,
            'amount'          => $amount,
            'remaining'       => $amount,
            'status'          => Appropriation::STATUS_ACTIVE,
        ]);

        $this->audit->append(
            module: 'executive',
            event: 'appropriation.created',
            payload: [
                'appropriation_id' => (string) $appropriation->id,
                'law_id'           => (string) $law->id,
                'line'             => $line,
                'amount'           => $amount,
            ],
            ref: 'WF-SYS-04',
            jurisdictionId: (string) $executive->jurisdiction_id,
        );

        return $appropriation;
    }

    public function apply(Appropriation $appropriation, Organization $org, float $amount, string $purpose): GrantApplication
    {
        if ($appropriation->status !== Appropriation::STATUS_ACTIVE) {
            throw new ConstitutionalViolation('The appropriation is not active.', 'Art. II §9 · as implemented');
        }

        if ($amount <= 0) {
            throw new ConstitutionalViolation('A grant application carries a positive amount.', 'Art. II §9 · as implemented');
        }

        return GrantApplication::create([
            'appropriation_id' => (string) $appropriation->id,
            'applicant_org_id' => (string) $org->id,
            'amount'           => $amount,
            'purpose'          => $purpose,
            'status'           => GrantApplication::STATUS_SUBMITTED,
        ]);
    }

    /** Award ≤ remaining (FOR UPDATE on the appropriation). */
    public function award(GrantApplication $application, ExecutiveMember $decider): GrantApplication
    {
        $run = function () use ($application, $decider): GrantApplication {
            $appropriation = Appropriation::query()
                ->whereKey($application->appropriation_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDecider($decider, $appropriation);

            if ($application->status !== GrantApplication::STATUS_SUBMITTED) {
                throw new ConstitutionalViolation(
                    "Only a submitted application can be awarded (status: {$application->status}).",
                    'Art. II §9 · as implemented'
                );
            }

            if ((float) $application->amount > (float) $appropriation->remaining) {
                throw new ConstitutionalViolation(
                    sprintf(
                        'Award %.2f exceeds the appropriation\'s remaining %.2f — awards never exceed the act.',
                        (float) $application->amount,
                        (float) $appropriation->remaining
                    ),
                    'Art. II §9 · as implemented'
                );
            }

            $newRemaining = (float) $appropriation->remaining - (float) $application->amount;

            $appropriation->forceFill([
                'remaining' => $newRemaining,
                'status'    => $newRemaining <= 0 ? Appropriation::STATUS_EXHAUSTED : Appropriation::STATUS_ACTIVE,
            ])->save();

            $application->forceFill([
                'status'               => GrantApplication::STATUS_AWARDED,
                'decided_by_member_id' => (string) $decider->id,
                'decided_at'           => now(),
            ])->save();

            $this->audit->append(
                module: 'executive',
                event: 'grant.awarded',
                payload: [
                    'application_id'   => (string) $application->id,
                    'appropriation_id' => (string) $appropriation->id,
                    'amount'           => (float) $application->amount,
                    'remaining'        => $newRemaining,
                ],
                ref: 'WF-SYS-04',
                actorId: $decider->user_id !== null ? (string) $decider->user_id : null,
                jurisdictionId: (string) $appropriation->jurisdiction_id,
            );

            $this->records->publish(
                kind: 'other',
                title: sprintf('Grant awarded — %s (%.2f)', $appropriation->line, (float) $application->amount),
                body: $application->purpose,
                attrs: [
                    'actor_user_id'   => $decider->user_id !== null ? (string) $decider->user_id : null,
                    'jurisdiction_id' => (string) $appropriation->jurisdiction_id,
                    'via_workflow'    => 'WF-SYS-04',
                    'subject_type'    => 'grant_applications',
                    'subject_id'      => (string) $application->id,
                ],
            );

            return $application;
        };

        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }

    public function decline(GrantApplication $application, ExecutiveMember $decider): GrantApplication
    {
        $appropriation = Appropriation::query()->whereKey($application->appropriation_id)->firstOrFail();
        $this->assertDecider($decider, $appropriation);

        if ($application->status !== GrantApplication::STATUS_SUBMITTED) {
            throw new ConstitutionalViolation('Only a submitted application can be declined.', 'Art. II §9 · as implemented');
        }

        $application->forceFill([
            'status'               => GrantApplication::STATUS_DECLINED,
            'decided_by_member_id' => (string) $decider->id,
            'decided_at'           => now(),
        ])->save();

        return $application;
    }

    /** Σ disbursements ≤ the awarded amount; append-only rows. */
    public function disburse(GrantApplication $application, ExecutiveMember $member, float $amount): GrantDisbursement
    {
        $run = function () use ($application, $member, $amount): GrantDisbursement {
            $appropriation = Appropriation::query()
                ->whereKey($application->appropriation_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDecider($member, $appropriation);

            if ($application->status !== GrantApplication::STATUS_AWARDED) {
                throw new ConstitutionalViolation('Disbursements run against AWARDED applications.', 'Art. II §9 · as implemented');
            }

            $disbursed = (float) GrantDisbursement::query()
                ->where('application_id', $application->id)
                ->sum('amount');

            if ($amount <= 0 || $disbursed + $amount > (float) $application->amount) {
                throw new ConstitutionalViolation(
                    sprintf(
                        'Disbursement %.2f would exceed the award (%.2f of %.2f already disbursed).',
                        $amount,
                        $disbursed,
                        (float) $application->amount
                    ),
                    'Art. II §9 · as implemented'
                );
            }

            $disbursement = GrantDisbursement::create([
                'application_id'         => (string) $application->id,
                'amount'                 => $amount,
                'disbursed_by_member_id' => (string) $member->id,
                'disbursed_at'           => now(),
            ]);

            $this->audit->append(
                module: 'executive',
                event: 'grant.disbursed',
                payload: [
                    'disbursement_id' => (string) $disbursement->id,
                    'application_id'  => (string) $application->id,
                    'amount'          => $amount,
                    'total_disbursed' => $disbursed + $amount,
                ],
                ref: 'WF-SYS-04',
                actorId: $member->user_id !== null ? (string) $member->user_id : null,
                jurisdictionId: (string) $appropriation->jurisdiction_id,
            );

            $this->records->publish(
                kind: 'other',
                title: sprintf('Grant disbursement — %.2f against %s', $amount, $appropriation->line),
                body: null,
                attrs: [
                    'actor_user_id'   => $member->user_id !== null ? (string) $member->user_id : null,
                    'jurisdiction_id' => (string) $appropriation->jurisdiction_id,
                    'via_workflow'    => 'WF-SYS-04',
                    'subject_type'    => 'grant_disbursements',
                    'subject_id'      => (string) $disbursement->id,
                ],
            );

            return $disbursement;
        };

        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }

    private function assertDecider(ExecutiveMember $member, Appropriation $appropriation): void
    {
        if ((string) $member->executive_id !== (string) $appropriation->executive_id
            || $member->status !== ExecutiveMember::STATUS_SEATED) {
            throw new ConstitutionalViolation(
                'Grant decisions are acts of the ADMINISTERING executive\'s seated members.',
                'Art. II §9 · as implemented'
            );
        }
    }
}
