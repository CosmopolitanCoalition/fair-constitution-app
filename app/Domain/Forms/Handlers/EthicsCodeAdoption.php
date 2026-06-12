<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\ChamberVoteProposal;
use App\Models\User;
use App\Services\Legislature\ChamberActService;

/**
 * F-LEG-033 — Ethics Code Adoption (chamber ops §D.2). Identical
 * direct-adoption mechanics to F-LEG-032 with laws.kind 'ethics_code'.
 */
class EthicsCodeAdoption implements FormHandler
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
        return 'law.ethics_code_proposed';
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
        $legislature = ChamberActor::legislature($payload, 'F-LEG-033');
        $member      = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-033');

        $result = $this->acts->proposeDirectLaw(
            $legislature,
            $member,
            ChamberVoteProposal::KIND_ETHICS_CODE,
            (string) ($payload['title'] ?? ''),
            (string) ($payload['text'] ?? ''),
        );

        return [
            'legislature_id' => (string) $legislature->id,
            'proposed_by'    => (string) $member->id,
            'title'          => (string) ($payload['title'] ?? ''),
        ] + $result;
    }
}
