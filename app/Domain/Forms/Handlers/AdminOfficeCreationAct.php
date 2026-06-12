<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\User;
use App\Services\Legislature\ChamberActService;

/**
 * F-LEG-013 — Administrative Office Creation Act (chamber ops §D.1).
 *
 * ORDINARY MAJORITY (peg — the unstated-threshold owner ruling: majority
 * of all serving). One live office per legislature. Staffing rides the
 * Phase B appointments pipeline: optional nominees each get a consent
 * vote; on consent → seated + civil-appointment term
 * (civil_appointment_years, CLK-09) — the first seat flips the office
 * `staffed` and R-29 derives.
 */
class AdminOfficeCreationAct implements FormHandler
{
    public function __construct(
        private readonly ChamberActService $acts,
    ) {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'admin_office.creation_proposed';
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
        $legislature = ChamberActor::legislature($payload, 'F-LEG-013');
        $member      = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-013');

        $result = $this->acts->proposeAdminOffice(
            $legislature,
            $member,
            (array) ($payload['nominees'] ?? []),
        );

        return [
            'legislature_id' => (string) $legislature->id,
            'proposed_by'    => (string) $member->id,
        ] + $result;
    }
}
