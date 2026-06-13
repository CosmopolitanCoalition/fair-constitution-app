<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ExecutiveActor;
use App\Models\Department;
use App\Models\User;
use App\Services\Executive\DepartmentService;

/**
 * F-EXE-002 — Department Policy Proposal (WF-EXE-07).
 *
 * The executive proposes; the department BOARD decides — a
 * body_type='board' yes/no vote (ordinary majority of all seated seats;
 * governor + worker-elected cast equally, Art. III §6). Proposals never
 * bypass the board. `amended` is recorded when the board files
 * amended_text before voting.
 */
class DepartmentPolicyProposal implements FormHandler
{
    public function __construct(
        private readonly DepartmentService $departments,
    ) {
    }

    public function module(): string
    {
        return 'executive';
    }

    public function event(): string
    {
        return 'department.policy_proposed';
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
        $department = Department::query()->find((string) ($payload['department_id'] ?? ''));

        if ($department === null) {
            throw new ConstitutionalViolation('F-EXE-002 names a chartered department.', 'Art. III §4');
        }

        $member = ExecutiveActor::member($actor, (string) $department->executive_id, 'F-EXE-002');

        $title = trim((string) ($payload['title'] ?? ''));
        $text  = trim((string) ($payload['text'] ?? ''));

        if ($title === '' || $text === '') {
            throw new ConstitutionalViolation(
                'A policy proposal carries a title and text for the board to decide.',
                'Art. III §4'
            );
        }

        $result = $this->departments->proposePolicy($department, $member, $title, $text);

        return [
            'department_id' => (string) $department->id,
            'proposed_by'   => (string) $member->id,
            'title'         => $title,
        ] + $result;
    }
}
