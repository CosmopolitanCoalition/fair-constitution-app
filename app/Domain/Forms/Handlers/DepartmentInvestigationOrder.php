<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ExecutiveActor;
use App\Models\AdminOffice;
use App\Models\Department;
use App\Models\ExecutiveInvestigation;
use App\Models\User;
use App\Services\Legislature\OversightService;
use App\Services\PublicRecordService;

/**
 * F-EXE-004 — Department Investigation Order (WF-EXE-08).
 *
 * Actions:
 *  - `open` (default): the row + DECLARED records_access (declarative
 *    jsonb in Phase D — no record-ACL substrate until E/F; flagged
 *    deferral). Findings publication is the operative duty.
 *  - `publish_findings`: findings → public record; the outcome branch
 *    names what the branch produced (policy_proposal / removal_request /
 *    legislative_referral — which routes to I-ADM intake — or
 *    closed_no_finding).
 */
class DepartmentInvestigationOrder implements FormHandler
{
    public function __construct(
        private readonly PublicRecordService $records,
    ) {
    }

    public function module(): string
    {
        return 'executive';
    }

    public function event(): string
    {
        return 'department.investigation';
    }

    public function requiredRoles(): array
    {
        return ['R-14', 'R-15', 'R-16'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $action = (string) ($payload['action'] ?? 'open');

        return match ($action) {
            'open'             => $this->open($actor, $payload),
            'publish_findings' => $this->publishFindings($actor, $payload),
            default => throw new ConstitutionalViolation(
                "Unknown F-EXE-004 action [{$action}].",
                'CGA Forms Catalog (F-EXE-004)'
            ),
        };
    }

    private function open(?User $actor, array $payload): array
    {
        $executive = ExecutiveActor::executive($payload, 'F-EXE-004');
        $member    = ExecutiveActor::member($actor, (string) $executive->id, 'F-EXE-004');

        $department = null;

        if (! empty($payload['department_id'])) {
            $department = Department::query()->find((string) $payload['department_id']);

            if ($department === null || (string) $department->executive_id !== (string) $executive->id) {
                throw new ConstitutionalViolation(
                    'Investigations run against departments THIS executive oversees.',
                    'Art. III §4'
                );
            }
        }

        $scope = trim((string) ($payload['scope'] ?? ''));

        if ($scope === '') {
            throw new ConstitutionalViolation('An investigation order states its scope.', 'Art. III §4');
        }

        $investigation = ExecutiveInvestigation::create([
            'executive_id'         => (string) $executive->id,
            'department_id'        => $department?->id,
            'ordered_by_member_id' => (string) $member->id,
            'scope'                => $scope,
            'records_access'       => array_values((array) ($payload['records_access'] ?? [])),
            'outcome'              => ExecutiveInvestigation::OUTCOME_OPEN,
        ]);

        return [
            'action'           => 'open',
            'investigation_id' => (string) $investigation->id,
            'executive_id'     => (string) $executive->id,
            'department_id'    => $department?->id !== null ? (string) $department->id : null,
            'ordered_by'       => (string) $member->id,
            'records_access'   => $investigation->records_access,
        ];
    }

    private function publishFindings(?User $actor, array $payload): array
    {
        $investigation = ExecutiveInvestigation::query()->find((string) ($payload['investigation_id'] ?? ''));

        if ($investigation === null || $investigation->outcome !== ExecutiveInvestigation::OUTCOME_OPEN) {
            throw new ConstitutionalViolation('F-EXE-004 findings publish on an OPEN investigation.', 'Art. III §4');
        }

        $member = ExecutiveActor::member($actor, (string) $investigation->executive_id, 'F-EXE-004');

        $findings = trim((string) ($payload['findings'] ?? ''));

        if ($findings === '') {
            throw new ConstitutionalViolation(
                'Findings publication is the operative constitutional duty — findings text is required.',
                'Art. III §4'
            );
        }

        $outcome = (string) ($payload['outcome'] ?? ExecutiveInvestigation::OUTCOME_CLOSED_NO_FINDING);

        if (! in_array($outcome, [
            ExecutiveInvestigation::OUTCOME_POLICY_PROPOSAL,
            ExecutiveInvestigation::OUTCOME_REMOVAL_REQUEST,
            ExecutiveInvestigation::OUTCOME_LEGISLATIVE_REFERRAL,
            ExecutiveInvestigation::OUTCOME_CLOSED_NO_FINDING,
        ], true)) {
            throw new ConstitutionalViolation("Unknown investigation outcome [{$outcome}].", 'Art. III §4');
        }

        $executive = $investigation->executive()->firstOrFail();

        $record = $this->records->publish(
            kind: 'other',
            title: 'Department investigation findings published',
            body: $findings,
            attrs: [
                'actor_user_id'   => $member->user_id !== null ? (string) $member->user_id : null,
                'jurisdiction_id' => (string) $executive->jurisdiction_id,
                'via_form'        => 'F-EXE-004',
                'subject_type'    => 'executive_investigations',
                'subject_id'      => (string) $investigation->id,
            ],
        );

        $outcomeRef = null;

        // legislative_referral routes to the I-ADM docket (OversightService
        // intake — subject list extended with executive_members/board_seats).
        if ($outcome === ExecutiveInvestigation::OUTCOME_LEGISLATIVE_REFERRAL) {
            $office = AdminOffice::query()
                ->whereHas('legislature', fn ($q) => $q->where('jurisdiction_id', $executive->jurisdiction_id))
                ->where('status', '!=', AdminOffice::STATUS_DISSOLVED)
                ->first();

            if ($office !== null) {
                $referral = app(OversightService::class)->intake(
                    $office,
                    (string) ($payload['subject_type'] ?? 'board_seats'),
                    (string) ($payload['subject_id'] ?? ''),
                    "Referred by executive investigation: {$findings}",
                    $actor,
                    'F-EXE-004',
                );

                $outcomeRef = ['misconduct_investigations', (string) $referral->id];
            }
        }

        // policy_proposal / removal_request branches: the sibling form
        // (F-EXE-002 / F-EXE-003) is filed separately; the reference is
        // recorded when provided.
        if ($outcomeRef === null && ! empty($payload['outcome_ref_type']) && ! empty($payload['outcome_ref_id'])) {
            $outcomeRef = [(string) $payload['outcome_ref_type'], (string) $payload['outcome_ref_id']];
        }

        $investigation->forceFill([
            'findings_record_id' => (string) $record->id,
            'outcome'            => $outcome,
            'outcome_ref_type'   => $outcomeRef[0] ?? null,
            'outcome_ref_id'     => $outcomeRef[1] ?? null,
        ])->save();

        return [
            'action'           => 'publish_findings',
            'investigation_id' => (string) $investigation->id,
            'outcome'          => $outcome,
            'record_id'        => (string) $record->id,
            'outcome_ref'      => $outcomeRef,
        ];
    }
}
