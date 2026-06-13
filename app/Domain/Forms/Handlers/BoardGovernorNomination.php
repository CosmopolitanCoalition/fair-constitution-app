<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ExecutiveActor;
use App\Models\Department;
use App\Models\User;
use App\Services\Executive\BoardGovernorService;

/**
 * F-EXE-001 — Board of Governors Nomination (WF-EXE-05).
 *
 * A seated member of the OVERSEEING executive nominates a governor onto
 * a vacant seat of the department's board: dossier published, F-LEG-020
 * consent vote opens in the legislature (vote_type bog_consent —
 * ordinary majority of ALL serving; consent casts ride F-LEG-004).
 * Nominee eligibility = active jurisdiction association ONLY (Art. I —
 * neutrality is a duty of office, not an eligibility test).
 */
class BoardGovernorNomination implements FormHandler
{
    public function __construct(
        private readonly BoardGovernorService $governors,
    ) {
    }

    public function module(): string
    {
        return 'executive';
    }

    public function event(): string
    {
        return 'governor.nominated';
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
            throw new ConstitutionalViolation('F-EXE-001 names a chartered department.', 'Art. III §4');
        }

        $member = ExecutiveActor::member($actor, (string) $department->executive_id, 'F-EXE-001');

        $nominee = (string) ($payload['nominee_user_id'] ?? '');

        if ($nominee === '') {
            throw new ConstitutionalViolation('F-EXE-001 names the nominee.', 'Art. III §4');
        }

        $result = $this->governors->nominate(
            $department,
            $member,
            $nominee,
            isset($payload['dossier']) ? (string) $payload['dossier'] : null,
        );

        return [
            'department_id'   => (string) $department->id,
            'nominated_by'    => (string) $member->id,
            'nominee_user_id' => $nominee,
        ] + $result;
    }
}
