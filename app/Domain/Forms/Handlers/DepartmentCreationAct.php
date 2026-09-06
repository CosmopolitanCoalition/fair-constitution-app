<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\User;
use App\Services\Executive\DepartmentService;
use App\Services\Executive\ExecutiveActService;

/**
 * F-LEG-016 — Department Creation Act (WF-EXE-04).
 *
 * Opens an ORDINARY-MAJORITY chamber vote (procedural_motion — the
 * unstated-threshold owner ruling; F-LEG-013 precedent) on a proposal
 * `{name, kind, executive_id, charter{function_text, powers_text,
 * reporting_interval_months}, owner_seats, nominees[]}`. Adoption (one
 * transaction): charter law + department + unified board + vacant
 * governor seats + optional F-EXE-001 nominations + the first periodic
 * report obligation. The mandatory five (Art. II §9) are a surface
 * checklist — NEVER auto-seeded, never an engine block.
 */
class DepartmentCreationAct implements FormHandler
{
    public function __construct(
        private readonly ExecutiveActService $acts,
        private readonly DepartmentService $departments,
    ) {
    }

    public function module(): string
    {
        return 'executive';
    }

    public function event(): string
    {
        return 'department.creation_proposed';
    }

    public function requiredRoles(): array
    {
        return ['R-09'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $legislature = ChamberActor::legislature($payload, 'F-LEG-016');

        // THE SYSTEM ACT (Wave 6, ruling sub-institutions-path B): a null
        // actor with system_act set is the Step 4 engine chartering an
        // unseated chamber's departments. Recorded by the engine, no vote.
        if ($actor === null && ! empty($payload['system_act'])) {
            $department = $this->departments->charterAsSystemAct($legislature, $payload);

            return [
                'legislature_id' => (string) $legislature->id,
                'department_id'  => (string) $department->id,
                'charter_law_id' => (string) $department->charter_law_id,
                'name'           => (string) $department->name,
                'kind'           => (string) $department->kind,
                'system_act'     => true,
            ];
        }

        $member = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-016');

        $result = $this->acts->proposeDepartmentCreation($legislature, $member, $payload);

        return [
            'legislature_id' => (string) $legislature->id,
            'proposed_by'    => (string) $member->id,
            'name'           => (string) ($payload['name'] ?? ''),
            'kind'           => (string) ($payload['kind'] ?? ''),
        ] + $result;
    }
}
