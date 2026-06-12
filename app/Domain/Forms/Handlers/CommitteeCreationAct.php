<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\User;
use App\Services\Legislature\CommitteeService;

/**
 * F-LEG-009 — Committee Creation Act (chamber ops §C.2).
 *
 * Opens a SUPERMAJORITY chamber vote (vote_type committee_create) on a
 * proposal `{name, purpose, seats}`. Bicameral chambers mirror the
 * chamber-kind ratio (Art. V §3 — largest remainder over serving a:b,
 * each kind ≥ 1 at seats ≥ 2; PROTECTED kind-split rule). The committee
 * row is created only on adoption (vote-engine dispatch).
 */
class CommitteeCreationAct implements FormHandler
{
    public function __construct(
        private readonly CommitteeService $committees,
    ) {
    }

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'committee.creation_proposed';
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
        $legislature = ChamberActor::legislature($payload, 'F-LEG-009');
        $member      = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-009');

        $result = $this->committees->proposeCreation(
            $legislature,
            $member,
            (string) ($payload['name'] ?? ''),
            isset($payload['purpose']) ? (string) $payload['purpose'] : null,
            (int) ($payload['seats'] ?? 0),
        );

        return [
            'legislature_id' => (string) $legislature->id,
            'proposed_by'    => (string) $member->id,
            'name'           => (string) $payload['name'],
            'seats'          => (int) $payload['seats'],
        ] + $result;
    }
}
