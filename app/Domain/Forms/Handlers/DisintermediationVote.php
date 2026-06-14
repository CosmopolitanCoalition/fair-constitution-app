<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\User;
use App\Services\Legislature\ChamberActService;

/**
 * F-LEG-030 — Disintermediation Vote (R-09; Art. V §8). The initiating chamber
 * supermajority OPENS, on adoption, the UNANIMITY constituent MultiJurisdiction-
 * Vote; the intermediary dissolves only on unanimity + the encompassing
 * jurisdiction's consent, its Acts merging into the encompassing jurisdiction.
 */
class DisintermediationVote implements FormHandler
{
    public function __construct(private readonly ChamberActService $acts) {}

    public function module(): string
    {
        return 'jurisdictions';
    }

    public function event(): string
    {
        return 'disintermediation.proposed';
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
        $legislature = ChamberActor::legislature($payload, 'F-LEG-030');
        $member = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-030');

        $result = $this->acts->proposeDisintermediation(
            $legislature,
            $member,
            (string) ($payload['intermediary_jurisdiction_id'] ?? ''),
            (string) ($payload['encompassing_jurisdiction_id'] ?? ''),
            (array) ($payload['constituent_jurisdiction_ids'] ?? []),
        );

        return [
            'legislature_id' => (string) $legislature->id,
            'proposed_by' => (string) $member->id,
        ] + $result;
    }
}
