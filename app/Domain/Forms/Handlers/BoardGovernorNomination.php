<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ExecutiveActor;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Services\Executive\BoardGovernorService;
use Illuminate\Support\Str;

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
    ) {}

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
        $departmentId = $payload['department_id'] ?? null;
        $organizationId = $payload['organization_id'] ?? null;
        if (($departmentId === null) === ($organizationId === null)) {
            throw new ConstitutionalViolation('Select one department or Common Good Corporation for this nomination.', 'Art. III §4/§5');
        }
        $id = $organizationId ?? $departmentId;
        $owner = is_string($id) && Str::isUuid($id)
            ? ($organizationId !== null ? Organization::query() : Department::query())->whereKey($id)->lockForUpdate()->first()
            : null;
        if ($owner === null) {
            throw new ConstitutionalViolation('Select an existing department or Common Good Corporation.', 'Art. III §4/§5');
        }
        if (isset($payload['jurisdiction_id']) && (! is_string($payload['jurisdiction_id']) || $payload['jurisdiction_id'] !== (string) $owner->jurisdiction_id)) {
            throw new ConstitutionalViolation('The nomination must use this institution\'s jurisdiction.', 'Art. III §4/§5');
        }
        $executiveId = $owner instanceof Organization ? $owner->overseen_by_executive_id : $owner->executive_id;
        if (! is_string($executiveId) || ! Str::isUuid($executiveId)) {
            throw new ConstitutionalViolation('This institution needs an overseeing executive before governors can be nominated.', 'Art. III §4/§5');
        }
        $member = ExecutiveActor::member($actor, (string) $executiveId, 'F-EXE-001');
        $nominee = $payload['nominee_user_id'] ?? null;
        if (! is_string($nominee) || ! Str::isUuid($nominee)) {
            throw new ConstitutionalViolation('F-EXE-001 names the nominee.', 'Art. III §4');
        }
        $dossier = $payload['dossier'] ?? null;
        if ($dossier !== null && (! is_string($dossier) || mb_strlen($dossier) > 20000)) {
            throw new ConstitutionalViolation('Keep the nomination dossier within 20,000 characters.', 'CGA Forms Catalog (F-EXE-001)');
        }
        $result = $owner instanceof Organization
            ? $this->governors->nominateCgc($owner, $member, $nominee, $dossier)
            : $this->governors->nominate($owner, $member, $nominee, $dossier);

        if ($actor !== null) {
            app(\App\Services\AchievementService::class)->awardSelf($actor, 'ACH-EXE-006');
        }

        return [
            $owner instanceof Organization ? 'organization_id' : 'department_id' => (string) $owner->id,
            'jurisdiction_id' => (string) $owner->jurisdiction_id,
            'nominated_by' => (string) $member->id,
            'nominee_user_id' => $nominee,
        ] + $result;
    }
}
