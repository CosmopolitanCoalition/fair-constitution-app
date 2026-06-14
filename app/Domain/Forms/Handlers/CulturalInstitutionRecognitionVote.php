<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\User;
use App\Services\Legislature\ChamberActService;

/**
 * F-LEG-028 — Cultural Institution Recognition Vote (R-09; Art. V §2). Proposal
 * kind cultural_institution → supermajority chamber vote; the powerless cultural
 * institution row is created only on adoption.
 */
class CulturalInstitutionRecognitionVote implements FormHandler
{
    public function __construct(private readonly ChamberActService $acts) {}

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'cultural_institution.recognition_proposed';
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
        $legislature = ChamberActor::legislature($payload, 'F-LEG-028');
        $member = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-028');

        $result = $this->acts->proposeCulturalInstitution(
            $legislature,
            $member,
            (string) ($payload['name'] ?? ''),
            $payload['description'] ?? null,
        );

        return [
            'legislature_id' => (string) $legislature->id,
            'proposed_by' => (string) $member->id,
            'name' => (string) ($payload['name'] ?? ''),
        ] + $result;
    }
}
