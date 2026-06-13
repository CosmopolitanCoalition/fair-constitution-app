<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\User;
use App\Services\Organizations\CgcService;

/**
 * F-LEG-019 — Common Good Corporation Creation Act (R-09; Art. III §5).
 *
 * Proposal kind cgc_creation → procedural_motion chamber vote (registry
 * gap — unstated threshold = ordinary majority of all serving, owner
 * ruling). The CGC (law + org + jurisdiction stake + governor board +
 * genesis IP dedication) is created only on adoption.
 */
class CgcCreationAct implements FormHandler
{
    public function __construct(
        private readonly CgcService $cgcs,
    ) {
    }

    public function module(): string
    {
        return 'organizations';
    }

    public function event(): string
    {
        return 'cgc.creation_proposed';
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
        $legislature = ChamberActor::legislature($payload, 'F-LEG-019');
        $member      = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-019');

        $result = $this->cgcs->proposeCreation($legislature, $member, $payload);

        return [
            'legislature_id' => (string) $legislature->id,
            'proposed_by'    => (string) $member->id,
            'name'           => (string) ($payload['name'] ?? ''),
        ] + $result;
    }
}
